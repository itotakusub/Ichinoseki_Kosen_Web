<?php

declare(strict_types=1);

/**
 * 模擬店・会場内の案内チャット(2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」)。
 *
 * ## 何をどこでするか(企画書 §4.3「経路計算や店舗情報の取得はプログラム側で処理し、LLM には取得済みのデータに基づく説明を担当させる」)
 *
 *   1. LLM に質問を**分けさせる**(JSON だけ): 店を探す / 経路 / 場所 / その他、探す言葉、種類、出発地・目的地の言葉
 *   2. **プログラムが探す**: 店と場所は公開の地図データ(km_map_data。教員室・氏名は入らない)から、この中の一致の点数で。
 *      経路の端は言葉から地点を決めるだけで、**経路そのものはブラウザの dijkstra.js が引く**(地図の案内と同じもの)
 *   3. LLM に、**探した記録だけ**を渡して答えの文を作らせる
 *   4. 画面に出す店と場所のカードは**サーバーが記録から作る**(LLM の文からは作らない)。存在しない店や通路は出ない
 *
 * ## 順位(企画書 §4.1「評価基準が不足している場合は、根拠のない順位付けを行わない」)
 * 登録されている数だけで並べる: 一致の点数 → ♡ の数(ポスター)→ 名前。**価格・待ち時間の欄は無い**ので使わない。
 *
 * ## 使える人(企画書 §8「テストユーザー1のみ使用を許可」)
 * 管理画面「案内チャット」で ON にし、テスト用アカウント(km_test_accounts)を 1 つ選ぶ。公開ページがそのアカウントで
 * 開かれたとき(セッションのテストモードの印。lib/test-mode.php)だけ使える。ほかの人には画面にも出さない。
 *
 * ## LLM(企画書: 自宅 PC の LLM + ngrok。2026-10-25 に Unsloth から Bionic へ替えた。利用者「Unsloth にバグが報告されていて使えない」)
 * 自宅 PC で利用者が動かしている Bionic の API(/v1/chat/completions の形)の前に、門番 server/guide-llm/server.py
 * (鍵を確かめて渡すだけ。2026-10-25 に WSL をやめてこの形にした)を置き、ngrok で出す。ここから Bearer の鍵を付けて呼ぶ。URL と鍵は環境変数 KM_GUIDE_LLM_URL / KM_GUIDE_LLM_KEY(.env → compose.yaml)。
 * **鍵はブラウザに出さない**(ブラウザは api/guide-chat.php しか呼ばない)。止まっていても、言葉で探した結果は出す(企画書 §8)。
 *
 * ## 残すもの・残さないもの(企画書 §8「ログに不要な個人情報や秘密情報を記録しない」)
 * 2026-10-25 から、利用者の指示「機能改善のために、質問した内容とその解答を保存」で、**質問と答えを km_guide_chat_log に残す**
 * (消さずに残す。管理画面「案内チャット」で見る・まとめて消す)。**誰が聞いたかは残さない**(アカウント・セッション・IP を持たない)。
 * メールアドレスと電話番号らしい文字は伏せてから残す。画面には「質問と答えを保存します」と書く。
 * サーバーのログ(error_log)には今までどおり本文を書かない。日ごとの回数(聞いた・失敗・LLM なしで答えた)も数える。
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/test-mode.php';

const KM_GUIDE_ENABLED_SETTING = 'guide_chat_enabled';
const KM_GUIDE_SUB_SETTING = 'guide_chat_sub';
const KM_GUIDE_DAILY_SETTING = 'guide_chat_daily_limit';
/**
 * 考える(推論)の ON/OFF(2026-10-25、利用者「Website 側で、考えるの ON-OFF はできませんか」)。
 * auto は自宅 PC の門番に任せる(模型の性質から決める)。ほかは頼みのたびに reasoning_effort で伝える
 * (off は none。考えを止められない模型では門番が low に置き換える)
 */
const KM_GUIDE_REASONING_SETTING = 'guide_chat_reasoning';
const KM_GUIDE_REASONING_CHOICES = [
    'auto' => '模型に任せる(自宅 PC の設定)',
    'off' => 'OFF(考えない。速い)',
    'low' => 'ON・少し',
    'medium' => 'ON・ふつう',
    'high' => 'ON・しっかり(遅い)',
];
const KM_GUIDE_DAILY_DEFAULT = 100;
const KM_GUIDE_DAILY_MAX = 1000;
/** 1 回の質問の長さ(字)。本文(JSON)の上限は api/guide-chat.php が 2KB で切る */
const KM_GUIDE_QUESTION_MAX = 200;
/** セッションで 1 分に聞ける回数(nginx でも IP ごとに絞る) */
const KM_GUIDE_PER_MINUTE = 6;
/** 答えに付けるカードの数 */
const KM_GUIDE_CARD_MAX = 5;
/** 言葉で当たらなかったとき LLM に見せる名前の数の上限(地図の場所は 2026-10 で約 180・一時地点は約 35。門番の本文 64KB に収まる) */
const KM_GUIDE_MATCH_MAX = 300;
/**
 * 言葉で当たらなかったときの言い換え・一覧から選ぶ頼みの字数(トークン)。api/guide-chat.php はこの字数の頼みを
 * 「後回しにしてよい頼み」と見て、残り時間が少なければ断る(答えの文の時間を残す)
 */
const KM_GUIDE_RESOLVE_TOKENS = 80;
/** LLM の返事の大きさの上限(バイト) */
const KM_GUIDE_LLM_MAX_BYTES = 65536;
/** 一時地点の種類(lib/map-events.php の KM_MAP_EVENT_POI_CATEGORIES と同じ) */
const KM_GUIDE_CATEGORIES = ['food', 'exhibit', 'reception', 'firstaid', 'toilet', 'stage', 'other'];
const KM_GUIDE_CATEGORY_LABELS = [
    'food' => '模擬店', 'exhibit' => '展示', 'reception' => '受付', 'firstaid' => '救護',
    'toilet' => 'トイレ', 'stage' => 'ステージ', 'other' => 'その他',
];
const KM_GUIDE_INTENTS = ['shop', 'route', 'place', 'other'];

// ---------------------------------------------------------------- 設定と使える人

/**
 * @return array{enabled:bool, sub:string, dailyLimit:int, reasoning:string}
 */
function km_guide_settings(PDO $pdo): array
{
    $limit = (int) km_setting_get($pdo, KM_GUIDE_DAILY_SETTING, (string) KM_GUIDE_DAILY_DEFAULT);
    $reasoning = km_setting_get($pdo, KM_GUIDE_REASONING_SETTING, 'auto');
    return [
        'reasoning' => array_key_exists($reasoning, KM_GUIDE_REASONING_CHOICES) ? $reasoning : 'auto',
        // **既定は OFF**(管理画面で ON にして、使う人を選んでから)
        'enabled' => km_setting_get($pdo, KM_GUIDE_ENABLED_SETTING, '0') === '1',
        'sub' => km_setting_get($pdo, KM_GUIDE_SUB_SETTING, ''),
        'dailyLimit' => $limit >= 1 && $limit <= KM_GUIDE_DAILY_MAX ? $limit : KM_GUIDE_DAILY_DEFAULT,
    ];
}

/**
 * このセッションの人が使ってよいか。**純粋関数**。
 * ON で、選んだテスト用アカウントの印(期限内)がセッションにあるときだけ。印は公開ページを開くたびに index.php が立て直す
 */
function km_guide_allowed(array $session, int $now, array $settings): bool
{
    if (!($settings['enabled'] ?? false) || ($settings['sub'] ?? '') === '') {
        return false;
    }
    $test = $session[KM_TEST_MODE_SESSION_KEY] ?? null;
    return is_array($test)
        && (int) ($test['until'] ?? 0) >= $now
        && hash_equals((string) $settings['sub'], (string) ($test['sub'] ?? ''));
}

/**
 * 1 分に聞ける回数を見る。**純粋関数**。$times はこのセッションで聞いた時刻(秒)。
 *
 * @return array{allowed:bool, times:list<int>} times は 1 分より前を捨て、通すなら今を足したもの
 */
function km_guide_rate_check(array $times, int $now, int $perMinute = KM_GUIDE_PER_MINUTE): array
{
    $recent = array_values(array_filter(array_map('intval', $times), static fn (int $t): bool => $t > $now - 60 && $t <= $now));
    if (count($recent) >= $perMinute) {
        return ['allowed' => false, 'times' => $recent];
    }
    $recent[] = $now;
    return ['allowed' => true, 'times' => $recent];
}

// ---------------------------------------------------------------- 回数(本文は残さない)

function km_guide_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_guide_chat_usage (
            day DATE NOT NULL PRIMARY KEY,
            asked INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            fallback INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

/** 今日の数を 1 つ足す。$column は asked / failed / fallback だけ */
function km_guide_count(PDO $pdo, string $day, string $column): void
{
    if (!in_array($column, ['asked', 'failed', 'fallback'], true)) {
        throw new InvalidArgumentException('数える欄が違います');
    }
    km_guide_ensure_tables($pdo);
    $pdo->prepare("INSERT INTO km_guide_chat_usage (day, {$column}) VALUES (?, 1) ON DUPLICATE KEY UPDATE {$column} = {$column} + 1")
        ->execute([$day]);
}

function km_guide_asked_today(PDO $pdo, string $day): int
{
    km_guide_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT asked FROM km_guide_chat_usage WHERE day = ?');
    $statement->execute([$day]);
    return (int) ($statement->fetchColumn() ?: 0);
}

/** @return list<array{day:string, asked:int, failed:int, fallback:int}> 新しい日から */
function km_guide_usage_recent(PDO $pdo, int $days = 7): array
{
    km_guide_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT day, asked, failed, fallback FROM km_guide_chat_usage ORDER BY day DESC LIMIT ' . max(1, min(60, $days)));
    $statement->execute();
    return array_map(static fn (array $row): array => [
        'day' => (string) $row['day'], 'asked' => (int) $row['asked'], 'failed' => (int) $row['failed'], 'fallback' => (int) $row['fallback'],
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

/** 日本時間の今日(お知らせと同じく、PHP の時刻設定によらない) */
function km_guide_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

// ---------------------------------------------------------------- 質問と答えを残す(2026-10-25、利用者「機能改善のために保存」)

/**
 * 残す前に伏せる。**純粋関数**。メールアドレスと、0 で始まる 10〜11 桁の番号(電話番号らしいもの)だけ。
 * 部屋番号(1-209・管-105)や年(2026)は 0 で始まらないので残る
 */
function km_guide_mask_personal(string $text): string
{
    $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[メール]', $text);
    return (string) preg_replace_callback('/(?<![0-9０-９])[0０][0-9０-９\-‐−ー（）() ]{8,16}[0-9０-９](?![0-9０-９])/u', static function (array $m): string {
        $digits = preg_match_all('/[0-9０-９]/u', $m[0]);
        return $digits >= 10 && $digits <= 11 ? '[電話番号]' : $m[0];
    }, $text);
}

/**
 * 残す 1 行を作る。**純粋関数**。**誰が聞いたかは入れない**(アカウント・セッション・IP は持たない)。
 * カードは名前だけ、経路は「出発地 → 目的地」の名前だけ
 *
 * @param array{answer:string, cards:list<array>, route:?array, fallback:bool, intent?:string} $result km_guide_answer の戻り値
 */
function km_guide_log_row(string $question, array $result, int $elapsedMs, string $askedAt, string $rateToken = ''): array
{
    $route = $result['route'] ?? null;
    return [
        // 評価に使う合言葉は、ハッシュだけを残す(画面が持つのは元の値。ほかの答えには評価を付けられない)
        'rate_hash' => $rateToken === '' ? '' : hash('sha256', $rateToken),
        'asked_at' => $askedAt,
        'question' => km_guide_mask_personal(mb_substr($question, 0, KM_GUIDE_QUESTION_MAX)),
        'answer' => km_guide_mask_personal(mb_substr((string) ($result['answer'] ?? ''), 0, 600)),
        'intent' => in_array($result['intent'] ?? '', KM_GUIDE_INTENTS, true) ? (string) $result['intent'] : '',
        'cards' => (string) json_encode(array_values(array_map(static fn (array $card): string => (string) ($card['name'] ?? ''), (array) ($result['cards'] ?? []))), JSON_UNESCAPED_UNICODE),
        'route' => is_array($route) ? mb_substr((string) ($route['from']['name'] ?? '(出発地は地図で選ぶ)') . ' → ' . (string) ($route['to']['name'] ?? ''), 0, 400) : '',
        'fallback' => !empty($result['fallback']) ? 1 : 0,
        'elapsed_ms' => max(0, $elapsedMs),
    ];
}

function km_guide_log_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_guide_chat_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            asked_at DATETIME NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            intent VARCHAR(16) NOT NULL DEFAULT '',
            cards TEXT NOT NULL,
            route VARCHAR(400) NOT NULL DEFAULT '',
            fallback TINYINT(1) NOT NULL DEFAULT 0,
            elapsed_ms INT NOT NULL DEFAULT 0,
            rate_hash CHAR(64) NOT NULL DEFAULT '',
            rating TINYINT NULL,
            bad_reason VARCHAR(16) NOT NULL DEFAULT '',
            bad_note VARCHAR(600) NOT NULL DEFAULT '',
            rated_at DATETIME NULL,
            KEY asked_at (asked_at),
            KEY rate_hash (rate_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

function km_guide_log_save(PDO $pdo, array $row): void
{
    km_guide_log_ensure_table($pdo);
    $pdo->prepare('INSERT INTO km_guide_chat_log (asked_at, question, answer, intent, cards, route, fallback, elapsed_ms, rate_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$row['asked_at'], $row['question'], $row['answer'], $row['intent'], $row['cards'], $row['route'], $row['fallback'], $row['elapsed_ms'], $row['rate_hash']]);
}

/** @return list<array> 新しいものから */
function km_guide_log_recent(PDO $pdo, int $limit = 100, int $offset = 0, bool $badOnly = false): array
{
    km_guide_log_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT id, asked_at, question, answer, intent, cards, route, fallback, elapsed_ms, rating, bad_reason, bad_note FROM km_guide_chat_log'
        . ($badOnly ? ' WHERE rating < 0' : '') . ' ORDER BY id DESC LIMIT '
        . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $statement->execute();
    return array_map(static function (array $row): array {
        $cards = json_decode((string) $row['cards'], true);
        return [
            'id' => (int) $row['id'], 'askedAt' => (string) $row['asked_at'], 'question' => (string) $row['question'],
            'answer' => (string) $row['answer'], 'intent' => (string) $row['intent'], 'cards' => is_array($cards) ? $cards : [],
            'route' => (string) $row['route'], 'fallback' => (int) $row['fallback'] === 1, 'elapsedMs' => (int) $row['elapsed_ms'],
            'rating' => $row['rating'] === null ? null : ((int) $row['rating'] > 0 ? 'good' : 'bad'),
            'badReason' => (string) $row['bad_reason'], 'badNote' => (string) $row['bad_note'],
        ];
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
}

/** @return array{total:int, good:int, bad:int} */
function km_guide_log_count(PDO $pdo): array
{
    km_guide_log_ensure_table($pdo);
    $row = $pdo->query('SELECT COUNT(*) AS total, SUM(rating > 0) AS good, SUM(rating < 0) AS bad FROM km_guide_chat_log')->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['total' => (int) ($row['total'] ?? 0), 'good' => (int) ($row['good'] ?? 0), 'bad' => (int) ($row['bad'] ?? 0)];
}

// ---------------------------------------------------------------- 評価(2026-10-25、利用者「評価も付けられるようにし、BAD だったら理由を聞く」)

/** BAD の理由(画面の選びどころと同じ並び) */
const KM_GUIDE_BAD_REASONS = [
    'place' => '場所や店が違う',
    'route' => '経路が違う',
    'missing' => '探しているものが出てこない',
    'wrong' => '答えの内容がまちがっている',
    'unclear' => '答えが分かりにくい',
    'slow' => '答えが遅い',
    'other' => 'その他',
];
/** BAD のひとことの長さ(字) */
const KM_GUIDE_BAD_NOTE_MAX = 200;

/**
 * 評価の入力を確かめる。**純粋関数**。合言葉は 32 字の 16 進。GOOD なら理由とひとことは捨てる。
 * BAD は理由が要る(決まったものだけ)。ひとことは 200 字まで・伏せてから
 *
 * @return array{hash:string, rating:int, reason:string, note:string}|null 形が違えば null
 */
function km_guide_rating_input(array $input): ?array
{
    $token = is_string($input['token'] ?? null) ? (string) $input['token'] : '';
    $rating = $input['rating'] ?? null;
    if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1 || !in_array($rating, ['good', 'bad'], true)) {
        return null;
    }
    if ($rating === 'good') {
        return ['hash' => hash('sha256', $token), 'rating' => 1, 'reason' => '', 'note' => ''];
    }
    $reason = is_string($input['reason'] ?? null) ? (string) $input['reason'] : '';
    $note = is_string($input['note'] ?? null) ? trim((string) $input['note']) : '';
    if (!array_key_exists($reason, KM_GUIDE_BAD_REASONS) || mb_strlen($note) > KM_GUIDE_BAD_NOTE_MAX
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
        return null;
    }
    return ['hash' => hash('sha256', $token), 'rating' => -1, 'reason' => $reason, 'note' => km_guide_mask_personal($note)];
}

/** 評価を付ける(付け直しは上書き)。合言葉に当たる答えが無ければ false */
function km_guide_log_rate(PDO $pdo, array $rating, string $ratedAt): bool
{
    km_guide_log_ensure_table($pdo);
    $statement = $pdo->prepare("UPDATE km_guide_chat_log SET rating = ?, bad_reason = ?, bad_note = ?, rated_at = ? WHERE rate_hash = ? AND rate_hash <> ''");
    $statement->execute([$rating['rating'], $rating['reason'], $rating['note'], $ratedAt, $rating['hash']]);
    if ($statement->rowCount() > 0) {
        return true;
    }
    // 同じ値で付け直したときは rowCount が 0 になる(MySQL)。答えがあるかで見分ける
    $exists = $pdo->prepare('SELECT 1 FROM km_guide_chat_log WHERE rate_hash = ? LIMIT 1');
    $exists->execute([$rating['hash']]);
    return $exists->fetchColumn() !== false;
}

/** 全部消す(管理画面から。消した数を返す) */
function km_guide_log_clear(PDO $pdo): int
{
    km_guide_log_ensure_table($pdo);
    return (int) $pdo->exec('DELETE FROM km_guide_chat_log');
}

// ---------------------------------------------------------------- 探す(Main/search-rank.js と同じ考え方)

/** 比べるための形: 全角半角・大文字小文字・カタカナ/ひらがな・空白と区切りの記号をそろえる */
function km_guide_normalize(string $text): string
{
    $s = mb_convert_kana($text, 'KVas', 'UTF-8');   // 半角カナ→全角、英数字・空白を半角に
    $s = mb_convert_kana($s, 'c', 'UTF-8');         // カタカナ→ひらがな
    $s = mb_strtolower($s, 'UTF-8');
    return (string) preg_replace('/[\s　・·()（）「」『』【】\[\]{}<>〈〉《》\/／\\\\\-‐‑‒–—―−_＿,，、。.．:：;；!！?？\'"’”~〜]+/u', '', $s);
}

/** 1 つの欄の点数。完全一致 1000 > 前方一致 800 > 途中の一致 400 > なし 0 */
function km_guide_field_score(string $field, string $word): int
{
    $f = km_guide_normalize($field);
    if ($f === '' || $word === '') {
        return 0;
    }
    if ($f === $word) {
        return 1000;
    }
    if (str_starts_with($f, $word)) {
        return 800;
    }
    return str_contains($f, $word) ? 400 : 0;
}

/**
 * 記録の点数。言葉は**全部**どこかに当たること(AND)。名前に当たれば満点、ほかの欄(説明・元の名前など)は 150 低い。
 * 言葉が無ければ 1(種類だけで探すとき)。当たらなければ 0
 */
function km_guide_item_score(array $item, array $words): int
{
    $words = array_values(array_filter(array_map('km_guide_normalize', $words), static fn (string $w): bool => $w !== ''));
    if ($words === []) {
        return 1;
    }
    $lowest = PHP_INT_MAX;
    foreach ($words as $word) {
        $best = km_guide_field_score((string) ($item['name'] ?? ''), $word);
        foreach ((array) ($item['extras'] ?? []) as $extra) {
            $best = max($best, km_guide_field_score((string) $extra, $word) - 150);
        }
        if ($best <= 0) {
            return 0;
        }
        $lowest = min($lowest, $best);
    }
    return $lowest;
}

/**
 * 記録を探して並べる。**純粋関数**。並びは 点数 → ♡ の数(登録されている数)→ 名前の短さ → 名前。
 * 場所を探すとき($byLikes = false。経路の端・「どこ」)は ♡ を見ない(ポスターのある部屋が建物そのものより先に来ないように)。
 *
 * 名前の短さ: 「体育館」は「第一体育館」にも「トレーニングルーム 第一体育館」「男WC 第一体育館」にも途中で当たって同じ点数になる。
 * 短い方が言葉をよく言い当てているので先にする(2026-10-25、利用者「第二実習室から体育館への行き方」でトレーニングルームへ行った。
 * それまでは名前の字の順で、カタカナの「ト」が漢字の「第」より先だった)
 *
 * @param list<array> $items km_guide_catalog の shops か places
 * @return list<array>
 */
function km_guide_search(array $items, array $words, ?string $category = null, int $limit = KM_GUIDE_CARD_MAX, bool $byLikes = true): array
{
    $scored = [];
    foreach ($items as $index => $item) {
        if ($category !== null && ($item['category'] ?? '') !== $category) {
            continue;
        }
        $score = km_guide_item_score($item, $words);
        if ($score > 0) {
            $name = (string) ($item['name'] ?? '');
            $likes = $byLikes ? (int) ($item['likes'] ?? 0) : 0;
            $scored[] = [$score, $likes, mb_strlen(km_guide_normalize($name)), $name, $index, $item];
        }
    }
    usort($scored, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2], $a[3], $a[4]] <=> [$a[0], $a[1], $b[2], $b[3], $b[4]]);
    return array_map(static fn (array $row): array => $row[5], array_slice($scored, 0, max(0, $limit)));
}

/**
 * 公開の地図データ(km_map_data の戻り値)から、案内に使う記録を作る。**純粋関数**。
 *
 *   shops  … 店・企画: 一時地点(模擬店など)と、イベント中の名前か企画の番号がある地点。ポスターの見出し・説明・♡・予定を付ける
 *   places … 経路の端に使える地点: 出している名前のある地点(部屋・建物・出入口・階段)と一時地点
 *
 * 教員の名前は入れない(occupantName を読まない)。出さない地点(hidden)・広告のポスターは入れない
 *
 * @return array{shops:list<array>, places:list<array>}
 */
function km_guide_catalog(array $mapData): array
{
    $nodes = is_array($mapData['nodes'] ?? null) ? $mapData['nodes'] : [];
    $pois = is_array($mapData['eventPois'] ?? null) ? $mapData['eventPois'] : [];
    $posters = is_array($mapData['posters'] ?? null) ? $mapData['posters'] : [];
    $schedule = is_array($mapData['schedule'] ?? null) ? $mapData['schedule'] : [];
    $eventOn = ($mapData['event'] ?? null) !== null;

    // ポスターと予定を、地点・一時地点ごとに束ねる
    $postersFor = [];
    foreach ($posters as $poster) {
        if (!is_array($poster) || ($poster['isAd'] ?? false) === true) {
            continue;
        }
        foreach ((array) ($poster['nodeIds'] ?? []) as $nodeId) {
            $postersFor['n:' . $nodeId][] = $poster;
        }
        foreach ((array) ($poster['poiIds'] ?? []) as $poiId) {
            $postersFor['p:' . (int) $poiId][] = $poster;
        }
    }
    $scheduleFor = [];
    foreach ($schedule as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ((array) ($row['nodeIds'] ?? []) as $nodeId) {
            $scheduleFor['n:' . $nodeId][] = $row;
        }
        foreach ((array) ($row['poiIds'] ?? []) as $poiId) {
            $scheduleFor['p:' . (int) $poiId][] = $row;
        }
    }
    $decorate = static function (array $item, string $key) use ($postersFor, $scheduleFor): array {
        $likes = 0;
        foreach ($postersFor[$key] ?? [] as $poster) {
            $item['extras'][] = (string) ($poster['title'] ?? '');
            $item['extras'][] = (string) ($poster['description'] ?? '');
            if (($item['detail'] ?? '') === '' && trim((string) ($poster['description'] ?? '')) !== '') {
                $item['detail'] = mb_substr(trim((string) $poster['description']), 0, 120);
            }
            if (($item['title'] ?? '') === '' && trim((string) ($poster['title'] ?? '')) !== '') {
                $item['title'] = (string) $poster['title'];
            }
            $likes = max($likes, (int) ($poster['likes'] ?? 0));
        }
        foreach ($scheduleFor[$key] ?? [] as $row) {
            $item['extras'][] = (string) ($row['title'] ?? '');
            $item['times'][] = trim((string) ($row['startsAt'] ?? '') . '〜' . (string) ($row['endsAt'] ?? ''));
        }
        $item['likes'] = $likes;
        $item['extras'] = array_values(array_filter($item['extras'], static fn (string $s): bool => trim($s) !== ''));
        return $item;
    };

    $floorLabel = static fn (string $floor): string => $floor === 'outside' ? '外' : (ctype_digit($floor) ? $floor . 'F' : $floor);

    $shops = [];
    $places = [];
    foreach ($pois as $poi) {
        if (!is_array($poi) || (int) ($poi['id'] ?? 0) <= 0) {
            continue;
        }
        $category = in_array($poi['category'] ?? '', KM_GUIDE_CATEGORIES, true) ? (string) $poi['category'] : 'other';
        $item = $decorate([
            'kind' => 'poi',
            'poiId' => (int) $poi['id'],
            'nodeId' => (string) ($poi['anchorNodeId'] ?? '') !== '' ? (string) $poi['anchorNodeId'] : null,
            'name' => (string) ($poi['name'] ?? ''),
            'title' => '',
            'category' => $category,
            'floor' => $floorLabel((string) ($poi['floor'] ?? '')),
            'detail' => mb_substr(trim((string) ($poi['note'] ?? '')), 0, 120),
            'extras' => [(string) ($poi['note'] ?? ''), KM_GUIDE_CATEGORY_LABELS[$category]],
            'times' => [],
        ], 'p:' . (int) $poi['id']);
        $shops[] = $item;
        $places[] = $item;
    }

    foreach ($nodes as $id => $node) {
        if (!is_array($node) || !empty($node['hidden'])) {
            continue;
        }
        $type = (string) ($node['type'] ?? '');
        if (!in_array($type, ['room', 'facility', 'entrance', 'stairs'], true)) {
            continue;
        }
        $name = trim((string) ($node['name'] ?? ''));
        $alias = trim((string) ($node['alias'] ?? ''));
        if ($name === '' && $alias === '') {
            continue;
        }
        $projects = array_values(array_map('strval', (array) ($node['projects'] ?? [])));
        $item = $decorate([
            'kind' => 'node',
            'poiId' => null,
            'nodeId' => (string) $id,
            'name' => $alias !== '' ? $alias : $name,
            'title' => '',
            'category' => '',
            'floor' => $floorLabel((string) ($node['floor'] ?? '')),
            'detail' => '',
            // 元の名前・部屋番号・企画の番号でも探せる
            'extras' => array_merge([$name, (string) ($node['subtitle'] ?? '')], array_map(static fn (string $p): string => '企画' . $p, $projects), $projects),
            'times' => [],
        ], 'n:' . $id);
        $places[] = $item;
        if ($eventOn && ($alias !== '' || $projects !== [])) {
            $shops[] = $item;
        }
    }

    return ['shops' => $shops, 'places' => $places];
}

/** 画面に出すカード(記録から作る。LLM の文からは作らない) */
function km_guide_card(array $item): array
{
    return [
        'kind' => (string) $item['kind'],
        'name' => (string) ($item['title'] !== '' ? $item['title'] : $item['name']),
        'place' => (string) $item['name'],
        'floor' => (string) $item['floor'],
        'category' => $item['category'] !== '' ? (KM_GUIDE_CATEGORY_LABELS[$item['category']] ?? '') : '',
        'detail' => (string) $item['detail'],
        'likes' => (int) ($item['likes'] ?? 0),
        'times' => array_slice(array_values((array) ($item['times'] ?? [])), 0, 3),
        'nodeId' => $item['nodeId'],
        'poiId' => $item['poiId'],
    ];
}

/** LLM に渡す形(短く。id は渡さない —— LLM に id を作らせない) */
function km_guide_record_for_llm(array $item): array
{
    return array_filter([
        '名前' => $item['title'] !== '' ? $item['title'] : $item['name'],
        '場所' => $item['name'] . '(' . $item['floor'] . ')',
        '種類' => $item['category'] !== '' ? (KM_GUIDE_CATEGORY_LABELS[$item['category']] ?? '') : '',
        '説明' => $item['detail'],
        '♡の数' => (int) ($item['likes'] ?? 0) > 0 ? (int) $item['likes'] : null,
        '時間' => ($item['times'] ?? []) !== [] ? implode('、', array_slice($item['times'], 0, 3)) : null,
    ], static fn ($v): bool => $v !== null && $v !== '');
}

// ---------------------------------------------------------------- LLM への頼み方

/** 1 回目: 質問を JSON に分けさせる */
function km_guide_classify_messages(string $question): array
{
    $categories = implode(' / ', array_map(static fn (string $k): string => $k . '(' . KM_GUIDE_CATEGORY_LABELS[$k] . ')', KM_GUIDE_CATEGORIES));
    return [
        ['role' => 'system', 'content' => "あなたは学校祭の会場案内の受付です。利用者の質問を分類し、次の形の JSON だけを返してください。説明や前置きは書かないでください。\n"
            . "{\"intent\":\"shop|route|place|other\",\"keywords\":[\"探す言葉\"],\"category\":\"種類か null\",\"from\":\"出発地の言葉か null\",\"to\":\"目的地の言葉か null\"}\n"
            . "- shop: 店・企画・展示・食べ物を探している\n- route: ある場所からある場所への行き方・道順を聞いている\n- place: ある場所がどこにあるかを聞いている\n- other: それ以外\n"
            // 2026-10-25: 名前を区切らせない。「レッツプレイ電算はどこ？」で keywords が ["電算"] だけになり(3 回とも)、見つからなかった
            . "- keywords は探す名前や言葉。店・企画・場所の名前は**区切らず、書かれたとおりに 1 つ**にしてください(カタカナの英語・打ち間違いもそのまま直さない)。「の店」「どこ」などは入れない。最大 3 つ\n"
            . "- category は次のどれかか null: {$categories}"],
        ['role' => 'user', 'content' => '食べ物の模擬店を教えて'],
        ['role' => 'assistant', 'content' => '{"intent":"shop","keywords":[],"category":"food","from":null,"to":null}'],
        ['role' => 'user', 'content' => '第二実習室から図書館への行き方'],
        ['role' => 'assistant', 'content' => '{"intent":"route","keywords":[],"category":null,"from":"第二実習室","to":"図書館"}'],
        ['role' => 'user', 'content' => 'ハッピーカフェ3Aはどこ?'],
        ['role' => 'assistant', 'content' => '{"intent":"place","keywords":["ハッピーカフェ3A"],"category":null,"from":null,"to":null}'],
        ['role' => 'user', 'content' => $question],
    ];
}

/** 2 回目: 探した記録だけを渡して、答えの文を作らせる */
function km_guide_answer_messages(string $question, array $records, string $situation): array
{
    $data = json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [
        ['role' => 'system', 'content' => "あなたは学校祭の会場案内です。下の「データ」に書かれている店・場所だけを使って、やさしい日本語で 3 文以内で答えてください。\n"
            . "- データに無い店・場所・値段・メニュー・待ち時間・順位は言わないでください(作らないでください)\n"
            . "- 道順の細かい説明はしないでください(地図に経路を出します)\n"
            . "- データが空なら「登録されている中には見つかりませんでした」と答えてください"],
        ['role' => 'user', 'content' => "状況: {$situation}\nデータ: {$data}\n質問: {$question}"],
    ];
}

/**
 * 1 回目の返事を読む。**純粋関数**。JSON の前後に文が付いていても、最初の { から最後の } までを読む。
 * 形の合わない値は捨てる(種類は決まったものだけ・言葉は 3 つまで 30 字まで)。読めなければ null
 *
 * @return array{intent:string, keywords:list<string>, category:?string, from:?string, to:?string}|null
 */
function km_guide_parse_classification(string $text): ?array
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($decoded)) {
        return null;
    }
    $intent = in_array($decoded['intent'] ?? '', KM_GUIDE_INTENTS, true) ? (string) $decoded['intent'] : 'other';
    $short = static function ($value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        // 模型は空を文字の "null" で返すことがある(2026-10-25、Bionic の Qwen3.5-9B で測った)
        if (in_array(strtolower($value), ['null', 'none', 'undefined'], true) || $value === 'なし') {
            return null;
        }
        return $value === '' || mb_strlen($value) > 30 ? null : $value;
    };
    $keywords = [];
    foreach (is_array($decoded['keywords'] ?? null) ? $decoded['keywords'] : [] as $word) {
        $word = $short($word);
        if ($word !== null && count($keywords) < 3) {
            $keywords[] = $word;
        }
    }
    return [
        'intent' => $intent,
        'keywords' => $keywords,
        'category' => in_array($decoded['category'] ?? null, KM_GUIDE_CATEGORIES, true) ? (string) $decoded['category'] : null,
        'from' => $short($decoded['from'] ?? null),
        'to' => $short($decoded['to'] ?? null),
    ];
}

/**
 * 言葉で当たらなかったときに LLM に見せる名前の一覧(記録と同じ並び)。ポスターの見出しが名前と違えば並べて書く。
 * id は入れない(LLM には一覧の番号で答えさせ、こちらで記録に戻す)
 *
 * @return list<string>
 */
function km_guide_match_names(array $items): array
{
    return array_map(static function (array $item): string {
        $name = (string) ($item['name'] ?? '');
        $title = (string) ($item['title'] ?? '');
        return $title !== '' && $title !== $name ? $name . ' / ' . $title : $name;
    }, array_slice(array_values($items), 0, KM_GUIDE_MATCH_MAX));
}

/**
 * 3 回目(言葉で当たらなかったときだけ): 利用者の言葉が一覧のどれのことかを、番号で選ばせる。
 * 2026-10-25、利用者「レッツプレイ電算 → Let's play densan! このようなタイポにも対応してほしい」。
 * カタカナと英語・漢字とローマ字の違いは、文字をそろえるだけでは当たらない(「電算」を densan と読むには辞書が要る)
 */
function km_guide_match_messages(string $word, array $names): array
{
    $lines = [];
    foreach (array_values($names) as $i => $name) {
        $lines[] = ($i + 1) . '. ' . $name;
    }
    return [
        ['role' => 'system', 'content' => "利用者が探している言葉が、下の一覧のどれを指しているかを選んでください。\n"
            . "- 読み方・書き方の違い(カタカナと英語、漢字とローマ字、ひらがな)や打ち間違いも同じものと考えてください\n"
            . "- 同じものだと言えるときだけ選んでください。似ているだけ・種類が同じだけなら null にしてください\n"
            . '- 次の形の JSON だけを返してください: {"number": 一覧の番号か null}'],
        ['role' => 'user', 'content' => "探している言葉: {$word}\n一覧:\n" . implode("\n", $lines)],
    ];
}

/**
 * 言葉で当たらなかったときの 1 段目: 別の書き方(英語のつづり・漢字・数字の書き方)を出させ、いつもの検索で探す。
 * 選ぶのは検索なので、LLM が一覧に無いものを作っても当たらない(2026-10-25 に本物の Bionic で、たいいくかん → 体育館、
 * としょかん → 図書館、第2実習室 → 第二実習室 が当たった。一覧から選ばせる形はこれらを「なし」にした)
 */
function km_guide_variant_messages(string $word): array
{
    return [
        ['role' => 'system', 'content' => "学校祭の会場案内で、利用者が打った言葉を、地図に登録されていそうな別の書き方に直してください。\n"
            . "- 英語の読みのカタカナは英語のつづりに(例: レッツプレイ → Let's play)、ローマ字・ひらがなは漢字に(例: でんさん → 電算、たいいくかん → 体育館)\n"
            . "- 数字の書き方(2 と 二)、打ち間違いも直してください\n"
            . "- 意味の違う言葉に置き換えないでください(メロンパン をクレープにしない)\n"
            . '- 次の形の JSON だけを返してください: {"variants":["書き方1","書き方2","書き方3"]}(最大 4 つ)'],
        ['role' => 'user', 'content' => $word],
    ];
}

/** 1 段目の返事を読む。**純粋関数**。4 つまで・30 字まで・元の言葉と同じものは捨てる */
function km_guide_parse_variants(string $text, string $word): array
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return [];
    }
    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    $variants = [];
    foreach (is_array($decoded['variants'] ?? null) ? $decoded['variants'] : [] as $variant) {
        if (!is_string($variant) || ($variant = trim($variant)) === '' || mb_strlen($variant) > 30
            || km_guide_normalize($variant) === km_guide_normalize($word) || in_array($variant, $variants, true)) {
            continue;
        }
        $variants[] = $variant;
    }
    return array_slice($variants, 0, 4);
}

/** 3 回目の返事を読む。**純粋関数**。一覧の範囲の番号なら 0 から数えた位置、それ以外は null(LLM の番号を信じきらない) */
function km_guide_parse_match(string $text, int $count): ?int
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    $number = null;
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        $number = is_array($decoded) ? ($decoded['number'] ?? null) : null;
    } elseif (preg_match('/^\s*(\d+)\s*$/', $text, $m) === 1) {
        $number = (int) $m[1];
    }
    if (is_string($number) && ctype_digit($number)) {
        $number = (int) $number;
    }
    return is_int($number) && $number >= 1 && $number <= $count ? $number - 1 : null;
}

/** LLM が使えないときの言葉: 質問を区切りで分けた言葉(短すぎるものは捨てる) */
function km_guide_fallback_words(string $question): array
{
    $parts = preg_split('/[\s　、。,.!?！？「」]+|(?:の|を|は|が|に|で|へ|と|から|まで|どこ|教えて|ある|ありますか|ですか)/u', $question) ?: [];
    $words = array_values(array_filter(array_map('trim', $parts), static fn (string $w): bool => mb_strlen($w) >= 2));
    return array_slice($words, 0, 3);
}

/**
 * 分けた結果から、探してカードと経路を決める。**純粋関数**(LLM は呼ばない)。
 *
 * $resolve は、言葉で当たらなかったときだけ呼ぶ(km_guide_answer が LLM で一覧から選ばせるものを渡す)。
 * 戻すのは渡した $items の中の 1 件か null。null なら今までどおり「見つかりませんでした」
 *
 * $startNodeId は、出発地を言わなかったときに使う地点(テスト用アカウントの展示の場所。2026-10-25、利用者
 * 「テストユーザーのみ、出発点を Let's Play Densan に」。lib/test-mode.php の km_test_start_node)。
 *
 * @param null|callable(list<array> $items, string $word): ?array $resolve
 * @return array{cards:list<array>, records:list<array>, route:?array, situation:string}
 */
function km_guide_plan(array $catalog, array $classification, ?callable $resolve = null, string $startNodeId = ''): array
{
    $intent = $classification['intent'];
    $route = null;
    $found = [];
    $records = null;
    // 言葉と、LLM がそれだと選んだ名前(答えの文で「◯◯のことですね」と言えるように)
    $guessed = [];
    $guess = static function (array $items, string $word) use ($resolve, &$guessed): ?array {
        $item = $resolve === null || trim($word) === '' ? null : $resolve($items, $word);
        if ($item !== null) {
            $guessed[] = "利用者の言う「{$word}」は、登録されている「" . ((string) ($item['title'] ?? '') !== '' ? $item['title'] : $item['name'])
                . '」と同じものです(書き方の違い)。「登録されていない」「見つからない」とは言わないでください';
        }
        return $item;
    };
    // 分けた言葉をつなげ直す(「レッツプレイ電算」が「レッツプレイ」「電算」に分かれると、どちらの書き方にも当たらない)。英字どうしは空白でつなぐ
    $joined = static function (array $words): string {
        $out = '';
        foreach ($words as $word) {
            $out .= ($out !== '' && preg_match('/[A-Za-z0-9]$/', $out) === 1 && preg_match('/^[A-Za-z0-9]/', $word) === 1 ? ' ' : '') . $word;
        }
        return $out;
    };
    $place = static fn (?string $word): ?array => $word === null
        ? null
        : (km_guide_search($catalog['places'], [$word], null, 1, false)[0] ?? $guess($catalog['places'], $word));
    if ($intent === 'route') {
        $to = $place($classification['to']);
        $from = $place($classification['from']);
        $here = false;
        // 出発地を言わなかったら、展示の場所から(テスト用アカウントだけ。目的地が見つかったときだけ)
        if ($from === null && $classification['from'] === null && $startNodeId !== '' && $to !== null) {
            foreach ($catalog['places'] as $item) {
                if ($item['kind'] === 'node' && $item['nodeId'] === $startNodeId && $item['nodeId'] !== $to['nodeId']) {
                    $from = $item;
                    $here = true;
                    break;
                }
            }
        }
        $end = static fn (?array $item): ?array => $item === null ? null : ['nodeId' => $item['nodeId'], 'poiId' => $item['poiId'], 'name' => $item['name']];
        if ($to !== null) {
            $route = ['from' => $end($from), 'to' => $end($to)];
        }
        // 経路のカードは場所の名前で出す(ポスターの見出しだと、どこへ行くのか分からない)。LLM には役目を付けて渡す
        $found = array_map(static fn (array $item): array => ['title' => ''] + $item, array_values(array_filter([$from, $to])));
        $records = [];
        foreach (['出発地' => $from, '目的地' => $to] as $role => $item) {
            if ($item !== null) {
                $records[] = ['役目' => $role] + km_guide_record_for_llm(['title' => ''] + $item);
            }
        }
        $situation = $to === null
            ? '目的地が登録されている場所の中に見つかりませんでした'
            : ($from === null
                ? "目的地は「{$to['name']}」です。出発地は利用者に地図で選んでもらいます"
                : ($here
                    ? '出発地は、いま利用者がいる「' . ((string) ($from['title'] ?? '') !== '' ? "{$from['title']}({$from['name']})" : $from['name'])
                        . "」です。そこから目的地「{$to['name']}」への経路を地図に出します"
                    : "出発地「{$from['name']}」から目的地「{$to['name']}」への経路を地図に出します"));
    } elseif ($intent === 'shop') {
        $found = km_guide_search($catalog['shops'], $classification['keywords'], $classification['category']);
        // 言葉で当たらなければ、LLM に一覧から選ばせる(読み方・書き方の違い)。それでも無ければ種類だけで探し直す
        $situation = '登録されている店・企画から探しました(並びは一致の度合いと♡の数)';
        if ($found === [] && $classification['keywords'] !== []) {
            $found = array_values(array_filter([$guess($catalog['shops'], $joined($classification['keywords']))]));
        }
        if ($found === [] && $classification['category'] !== null && $classification['keywords'] !== []) {
            $found = km_guide_search($catalog['shops'], [], $classification['category']);
            $situation = '「' . implode('・', $classification['keywords']) . '」に当たる店・企画は登録されていません。代わりに同じ種類の店・企画を出しています(代わりだと分かるように言ってください)';
        }
        if ($found === []) {
            $situation = '登録されている店・企画の中に当たるものがありませんでした';
        }
    } elseif ($intent === 'place') {
        $found = km_guide_search($catalog['places'], $classification['keywords'], null, 3, false);
        if ($found === [] && $classification['keywords'] !== []) {
            $found = array_values(array_filter([$guess($catalog['places'], $joined($classification['keywords']))]));
        }
        $situation = $found === [] ? '登録されている場所の中に見つかりませんでした' : '登録されている場所から探しました。カードの「地図で見る」で場所を、「経路」で行き方を出せます(まだ経路は出していません)';
    } else {
        $situation = '店・場所・経路の質問ではありませんでした。地図の案内でできることを短く伝えてください';
    }
    if ($guessed !== []) {
        $situation .= '。' . implode('。', $guessed) . '(違っていたら言い直してもらうよう、ひとこと添えてください)';
    }
    return [
        'cards' => array_map('km_guide_card', $found),
        'records' => $records ?? array_map('km_guide_record_for_llm', $found),
        'route' => $route,
        'situation' => $situation,
    ];
}

// ---------------------------------------------------------------- LLM を呼ぶ(自宅 PC の Bionic を ngrok 越しに)

/** @return array{url:string, key:string, model:string}|null 設定が無ければ null(LLM なしで答える) */
function km_guide_llm_config(): ?array
{
    $url = trim((string) getenv('KM_GUIDE_LLM_URL'));
    $key = trim((string) getenv('KM_GUIDE_LLM_KEY'));
    // https だけ(鍵を平文で流さない)。末尾の / は落とす
    if ($url === '' || $key === '' || !str_starts_with(strtolower($url), 'https://')) {
        return null;
    }
    $model = trim((string) getenv('KM_GUIDE_LLM_MODEL'));
    return ['url' => rtrim($url, '/'), 'key' => $key, 'model' => $model !== '' ? $model : 'kosenmap-guide'];
}

/**
 * 自宅 PC へ送る本文。**純粋関数**。考える(reasoning)は管理画面の設定が auto 以外のときだけ付ける
 * (off は none。門番が模型に合わせて字数を上乗せする・止められない模型では low に置き換える)
 */
function km_guide_llm_body(array $config, array $messages, int $maxTokens): array
{
    $body = [
        'model' => $config['model'],
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.2,
    ];
    $reasoning = (string) ($config['reasoning'] ?? 'auto');
    if ($reasoning !== 'auto' && array_key_exists($reasoning, KM_GUIDE_REASONING_CHOICES)) {
        $body['reasoning_effort'] = $reasoning === 'off' ? 'none' : $reasoning;
    }
    return $body;
}

/** 考えるを ON にしているか(ON の間は 1 回の返事が遅いので、口の待ち時間を延ばす) */
function km_guide_reasoning_on(array $config): bool
{
    return in_array($config['reasoning'] ?? 'auto', ['low', 'medium', 'high'], true);
}

/**
 * /v1/chat/completions の形で 1 回呼んで、文を返す。失敗は RuntimeException(本文はログに出さない)。
 * 手本は lib/logto-management.php の km_logto_management_get(TLS を確かめる・転送しない・時間を切る)
 */
function km_guide_llm_chat(array $config, array $messages, int $maxTokens, int $timeoutSeconds = 30): string
{
    $body = json_encode(km_guide_llm_body($config, $messages, $maxTokens), JSON_UNESCAPED_UNICODE);
    $received = '';
    $tooLarge = false;
    $curl = curl_init($config['url'] . '/v1/chat/completions');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['key'],
            // ngrok の無料の口は、ブラウザ向けの確かめ画面を挟むことがある。API には要らない
            'ngrok-skip-browser-warning: 1',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received, &$tooLarge): int {
            if (strlen($received) + strlen($chunk) > KM_GUIDE_LLM_MAX_BYTES) {
                $tooLarge = true;
                return 0;   // 止める
            }
            $received .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($tooLarge) {
        throw new RuntimeException('LLM の返事が大きすぎます');
    }
    if ($ok === false || $status !== 200) {
        throw new RuntimeException('LLM に届きません(' . ($status > 0 ? 'HTTP ' . $status : $error) . ')');
    }
    $decoded = json_decode($received, true);
    $text = $decoded['choices'][0]['message']['content'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('LLM の返事を読めません');
    }
    return trim($text);
}

/**
 * 質問 1 つに答える。LLM が止まっていても、言葉で探した結果は返す(企画書 §8)。
 *
 * @param callable(array $messages, int $maxTokens): string $llm LLM を呼ぶもの(試験では差し替える)。null なら LLM なし
 * @param string $startNodeId 出発地を言わなかったときの地点(テスト用アカウントの展示の場所。km_guide_plan)
 * @return array{answer:string, cards:list<array>, route:?array, fallback:bool}
 */
function km_guide_answer(array $catalog, string $question, ?callable $llm, string $startNodeId = ''): array
{
    $fallback = static function () use ($catalog, $question): array {
        $found = km_guide_search($catalog['shops'], km_guide_fallback_words($question));
        if ($found === []) {
            $found = km_guide_search($catalog['places'], km_guide_fallback_words($question), null, 3, false);
        }
        return [
            'answer' => $found === []
                ? 'いま案内の AI が使えません。言葉で探しましたが、登録されている中には見つかりませんでした。地図の「場所を検索」も使えます。'
                : 'いま案内の AI が使えないので、言葉で探した結果だけ出します。',
            'cards' => array_map('km_guide_card', $found),
            'route' => null,
            'fallback' => true,
        ];
    };
    if ($llm === null) {
        return $fallback();
    }
    try {
        $classification = km_guide_parse_classification($llm(km_guide_classify_messages($question), 160));
        if ($classification === null) {
            return $fallback();
        }
        // 言葉で当たらなかったときだけ: 1 段目は別の書き方で探す。2 段目は一覧の番号で選ばせる(id は渡さない。番号は範囲を確かめて記録に戻す)
        // ここでつまずいても(時間切れ・返事が読めない)答えは止めない。見つからなかったことにする
        $resolve = static function (array $items, string $word) use ($llm): ?array {
            $items = array_values($items);
            try {
                foreach (km_guide_parse_variants($llm(km_guide_variant_messages($word), KM_GUIDE_RESOLVE_TOKENS), $word) as $variant) {
                    $hit = km_guide_search($items, [$variant], null, 1, false)[0] ?? null;
                    if ($hit !== null) {
                        return $hit;
                    }
                }
                $names = km_guide_match_names($items);
                if ($names === []) {
                    return null;
                }
                $index = km_guide_parse_match($llm(km_guide_match_messages($word, $names), KM_GUIDE_RESOLVE_TOKENS), count($names));
            } catch (RuntimeException $exception) {
                error_log('km_guide_answer: resolve skipped: ' . $exception->getMessage());
                return null;
            }
            return $index === null ? null : $items[$index];
        };
        $plan = km_guide_plan($catalog, $classification, $resolve, $startNodeId);
        $answer = $llm(km_guide_answer_messages($question, $plan['records'], $plan['situation']), 300);
        return [
            // 長すぎる答えは切る(画面に入る長さ)
            'answer' => mb_substr($answer, 0, 600),
            'cards' => $plan['cards'],
            'route' => $plan['route'],
            'fallback' => false,
            'intent' => $classification['intent'],
        ];
    } catch (RuntimeException $exception) {
        error_log('km_guide_answer: LLM failed (falling back to keyword search): ' . $exception->getMessage());
        return $fallback();
    }
}
