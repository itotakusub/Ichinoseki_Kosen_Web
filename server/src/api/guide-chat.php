<?php

declare(strict_types=1);

/**
 * 模擬店・会場内の案内チャット(2026-10-24、利用者の企画書。中身は lib/guide-chat.php)。
 *
 *   POST {"question":"食べ物の店を教えて"}(X-KM-CSRF)
 *   → {"success":true,"answer":"…","cards":[{kind,name,place,floor,category,detail,likes,times,nodeId,poiId}],"route":{from,to}|null,"fallback":bool,"rateToken":"…"}
 *   POST {"rate":{…}} は評価(下)
 *
 * 通す順(企画書 §8「認証、レート制限、入力検証」):
 *   1. 公開ページのセッションの CSRF トークン(ほかのサイトから送らせない)
 *   2. **テストユーザー 1 だけ**: 管理画面「案内チャット」で ON にし、選んだテスト用アカウントの印がセッションにあること。違えば 403
 *   3. 本文 2KB・質問 200 字まで
 *   4. 回数: セッションで 1 分 6 回・1 日の上限(管理画面で決める)。nginx でも IP ごとに絞る(location = /api/guide-chat.php)
 *   5. 自宅 PC の LLM(Bionic + ngrok)を呼ぶ。止まっていても、言葉で探した結果は返す
 *
 * 2026-10-25 から、質問と答えを km_guide_chat_log に残す(利用者「機能改善のために保存」。誰が聞いたかは残さない。
 * メールアドレス・電話番号らしい文字は伏せる)。サーバーのログには本文を書かない。日ごとの回数も数える。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/session.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/guide-chat.php';
require_once __DIR__ . '/../lib/map-data.php';

require_method('POST');

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/guide-chat.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

km_session_start();
if (!km_csrf_verify()) {
    respond(['success' => false, 'message' => 'ページを開き直してください。'], 403);
}
$settings = km_guide_settings($pdo);
if (!km_guide_allowed($_SESSION, time(), $settings)) {
    // 使える人かどうか以上のことは言わない
    respond(['success' => false, 'message' => 'この案内は使えません。'], 403);
}

$input = km_api_json_body(2048);

/*
 * 評価(2026-10-25、利用者「評価も付けられるようにし、BAD だったら理由を聞く」)
 *   POST {"rate":{"token":"…","rating":"good"|"bad","reason":"place|route|missing|wrong|unclear|slow|other","note":"…"}}
 * token は答えと一緒に返した合言葉(残しているのはハッシュだけ)。質問の回数には数えない(nginx の回数には入る)
 */
if (array_key_exists('rate', $input)) {
    session_write_close();
    $rating = is_array($input['rate']) ? km_guide_rating_input($input['rate']) : null;
    if ($rating === null) {
        respond(['success' => false, 'message' => '理由を選んでください(ひとことは ' . KM_GUIDE_BAD_NOTE_MAX . ' 字まで)。'], 400);
    }
    try {
        $saved = km_guide_log_rate($pdo, $rating, (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'));
    } catch (Throwable $exception) {
        error_log('api/guide-chat.php rate failed: ' . $exception->getMessage());
        respond(['success' => false, 'message' => '評価を残せませんでした。'], 500);
    }
    if (!$saved) {
        respond(['success' => false, 'message' => 'この答えには評価を付けられません。'], 404);
    }
    respond(['success' => true]);
}

$question = is_string($input['question'] ?? null) ? trim((string) $input['question']) : '';
if ($question === '' || mb_strlen($question) > KM_GUIDE_QUESTION_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $question) === 1) {
    respond(['success' => false, 'message' => '質問は ' . KM_GUIDE_QUESTION_MAX . ' 字までで書いてください。'], 400);
}

// 1 分の回数(セッションに時刻だけを置く)。ここでセッションを閉じる —— LLM を待つあいだ、同じ人のほかの要求を止めない
$rate = km_guide_rate_check(is_array($_SESSION['km_guide_recent'] ?? null) ? $_SESSION['km_guide_recent'] : [], time());
$_SESSION['km_guide_recent'] = $rate['times'];
session_write_close();
if (!$rate['allowed']) {
    respond(['success' => false, 'message' => '続けて聞きすぎです。1 分ほど待ってからやり直してください。'], 429);
}

$today = km_guide_today();
try {
    if (km_guide_asked_today($pdo, $today) >= $settings['dailyLimit']) {
        respond(['success' => false, 'message' => '今日の案内の回数を使い切りました。地図の「場所を検索」を使ってください。'], 429);
    }
    km_guide_count($pdo, $today, 'asked');
    // 公開の地図と同じデータ(錠・教員室・氏名の決まりもそのまま)。イベントの一時地点・ポスター・予定も入る
    $catalog = km_guide_catalog(km_map_data($pdo));
} catch (Throwable $exception) {
    error_log('api/guide-chat.php prepare failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '案内を準備できませんでした。'], 500);
}

$llmConfig = km_guide_llm_config();
/*
 * 待つのは 1 回あたり 20 秒(答えの文は 30 秒)まで、**全部で 55 秒まで**。Apache の Timeout 60 秒
 * (docker/php/apache-km.conf)より短くする。nginx はこの口だけ 90 秒まで待つ(default.conf.template)。
 * 言葉で当たらなかったときの言い換え・一覧から選ぶ頼み(2026-10-25)は、始めてから 25 秒を過ぎたら出さない(答えの文の時間を残す)
 */
$started = microtime(true);
// 考える(管理画面の設定)。ON の間は 1 回の返事が遅いので、1 回あたり 40 秒まで待ち、言い換えは 15 秒を過ぎたら出さない
if ($llmConfig !== null) {
    $llmConfig['reasoning'] = $settings['reasoning'];
}
$thinking = $llmConfig !== null && km_guide_reasoning_on($llmConfig);
$llm = $llmConfig === null ? null : static function (array $messages, int $maxTokens) use ($llmConfig, $started, $thinking): string {
    $elapsed = microtime(true) - $started;
    if ($maxTokens === KM_GUIDE_RESOLVE_TOKENS && $elapsed > ($thinking ? 15 : 25)) {
        throw new RuntimeException('言い換えの時間切れ');
    }
    $left = 55 - $elapsed;
    if ($left < 3) {
        throw new RuntimeException('時間切れ');
    }
    $perCall = $thinking ? 40 : ($maxTokens <= 160 ? 20 : 30);
    return km_guide_llm_chat($llmConfig, $messages, $maxTokens, (int) min($perCall, floor($left)));
};
// 出発地を言わなかった経路は、展示の場所から(テスト用アカウントの設定。2026-10-25、利用者「テストユーザーのみ、出発点を Let's Play Densan に」)
try {
    $startNodeId = km_test_start_node($pdo);
} catch (Throwable $exception) {
    error_log('api/guide-chat.php start node failed: ' . $exception->getMessage());
    $startNodeId = '';
}
$result = km_guide_answer($catalog, $question, $llm, $startNodeId);

// 質問と答えを残す(2026-10-25、利用者「機能改善のために保存」)。誰が聞いたかは入れない。残せなくても答えは返す。
// 評価の合言葉は画面に返し、ここにはハッシュだけを残す(残せなかったときは評価のボタンを出さない)
$rateToken = bin2hex(random_bytes(16));
try {
    km_guide_log_save($pdo, km_guide_log_row(
        $question,
        $result,
        (int) round((microtime(true) - $started) * 1000),
        (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'),
        $rateToken
    ));
    $result['rateToken'] = $rateToken;
} catch (Throwable $exception) {
    error_log('api/guide-chat.php log save failed: ' . $exception->getMessage());
}
unset($result['intent']);

try {
    if ($result['fallback']) {
        km_guide_count($pdo, $today, 'fallback');
        // LLM を設定しているのに言葉の検索に倒れた = 自宅 PC に届かなかった・返事が読めなかった
        if ($llm !== null) {
            km_guide_count($pdo, $today, 'failed');
        }
    }
} catch (Throwable $exception) {
    error_log('api/guide-chat.php count failed: ' . $exception->getMessage());
}

respond(['success' => true] + $result);
