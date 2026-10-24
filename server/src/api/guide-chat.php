<?php

declare(strict_types=1);

/**
 * 模擬店・会場内の案内チャット(2026-10-24、利用者の企画書。中身は lib/guide-chat.php)。
 *
 *   POST {"question":"食べ物の店を教えて"}(X-KM-CSRF)
 *   → {"success":true,"answer":"…","cards":[{kind,name,place,floor,category,detail,likes,times,nodeId,poiId}],"route":{from,to}|null,"fallback":bool}
 *
 * 通す順(企画書 §8「認証、レート制限、入力検証」):
 *   1. 公開ページのセッションの CSRF トークン(ほかのサイトから送らせない)
 *   2. **テストユーザー 1 だけ**: 管理画面「案内チャット」で ON にし、選んだテスト用アカウントの印がセッションにあること。違えば 403
 *   3. 本文 2KB・質問 200 字まで
 *   4. 回数: セッションで 1 分 6 回・1 日の上限(管理画面で決める)。nginx でも IP ごとに絞る(location = /api/guide-chat.php)
 *   5. 自宅 PC の LLM(Bionic + ngrok)を呼ぶ。止まっていても、言葉で探した結果は返す
 *
 * 質問と答えの本文は**どこにも残さない**(数えるのは日ごとの回数だけ)。
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
 * 待つのは分ける 1 回目 20 秒・答える 2 回目 30 秒まで(合わせて 50 秒)。Apache の Timeout 60 秒
 * (docker/php/apache-km.conf)より短くする。nginx はこの口だけ 90 秒まで待つ(default.conf.template)
 */
$llm = $llmConfig === null ? null : static fn (array $messages, int $maxTokens): string => km_guide_llm_chat(
    $llmConfig,
    $messages,
    $maxTokens,
    $maxTokens <= 160 ? 20 : 30
);
$result = km_guide_answer($catalog, $question, $llm);

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
