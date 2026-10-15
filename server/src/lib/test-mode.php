<?php

declare(strict_types=1);

/**
 * テスト用アカウント(展示の PC。2026-10-15、利用者の指示)。
 *
 * ## 何のためか
 *
 * Web 版の展示が決まった。PC に地図を出し、来場者に代わる代わる触ってもらう。
 * そのときの使われ方(何を探し、どこへの経路を引き、どの部屋を開いたか)を集めて、次の改善に使う。
 *
 * - 管理画面でアカウントに「テスト用」の印を付ける(`km_test_accounts`。Logto の sub で持つ)
 * - 印の付いたアカウントで公開ページを開くと:
 *   - 「テスト中」の札とアンケートの QR・URL を出す(最小化できる)
 *   - 操作方法を常に出す(最小化できる)。次の人へ代わるときの「始めから」
 *   - 検索の語・選んだ場所・経路の出発地と目的地・開いた部屋の詳細・階の切り替えを記録する(`km_test_events`)
 * - **印の無いアカウント・ログインしていない人の操作は記録しない。** 一般の閲覧者を黙って記録しないため
 *
 * ## 表を新しく作る理由
 *
 * アプリの DB 利用者には ALTER が無い。既存の表に列を足さず、ここで作る表だけを使う。
 * 操作方法の文は 255 字に収まらないので `km_settings` ではなく自前の表に置く。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/** 公開ページが立てる印(セッション)。API はこれを見る(Logto へ毎回問い合わせない) */
const KM_TEST_MODE_SESSION_KEY = 'km_test_mode';

/** 印の有効時間。公開ページを開き直すたび(「始めから」も)に立て直す。外した印は次に開いたときに消える */
const KM_TEST_MODE_SESSION_TTL = 8 * 3600;

const KM_TEST_SURVEY_SETTING = 'test_mode_survey_url';

/** 1 回の送信で受ける件数と、1 件の文字の長さ(検索の語) */
const KM_TEST_EVENTS_PER_REQUEST = 50;
const KM_TEST_EVENT_TEXT_MAX = 100;

/**
 * 記録の種類。
 *
 * - open     … ページを開いた(新しい来場者の始まり)
 * - restart  … 「始めから」を押した
 * - search   … 出発地・目的地の欄に打った語(field = start / goal)。地点に当たれば node
 * - category … カテゴリー検索で選んだ分類(text)
 * - pick     … カテゴリー検索の結果から選んだ地点(node)
 * - route    … 「案内開始」(node = 出発地、node2 = 目的地、text = 条件)
 * - detail   … 部屋の詳細を開いた(node)
 * - floor    … 階を切り替えた(floor)
 */
const KM_TEST_EVENT_KINDS = ['open', 'restart', 'search', 'category', 'pick', 'route', 'detail', 'floor'];

const KM_TEST_EVENT_LABELS = [
    'open' => '開いた',
    'restart' => '始めから',
    'search' => '検索の語',
    'category' => 'カテゴリー',
    'pick' => '検索から選んだ場所',
    'route' => '経路',
    'detail' => '部屋の詳細',
    'floor' => '階の切り替え',
];

/** 操作方法の既定の文(管理画面で書き換えられる)。1 行が 1 項目 */
const KM_TEST_GUIDE_DEFAULT = <<<'TEXT'
左下の「検索」から、出発地と目的地を選んで「案内開始」
部屋の点を押すと詳細が出ます。「ここを目的地にする」で経路も引けます
「カテゴリー検索」で、トイレや自販機などをまとめて探せます
右の「外・1F〜5F」で階を切り替えます
地図はドラッグで動かし、ホイールか ＋/− で拡大・縮小します
次の方に代わるときは「始めから」を押してください
TEXT;

function km_test_mode_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_test_accounts (
            sub VARCHAR(64) NOT NULL PRIMARY KEY,
            label VARCHAR(100) NOT NULL DEFAULT '',
            marked_by VARCHAR(255) NOT NULL DEFAULT '',
            marked_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_test_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sub VARCHAR(64) NOT NULL,
            visit CHAR(16) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            field VARCHAR(16) NULL,
            text VARCHAR(100) NULL,
            node_id VARCHAR(64) NULL,
            node_id2 VARCHAR(64) NULL,
            floor_id VARCHAR(32) NULL,
            happened_at DATETIME(3) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_km_test_events_time (happened_at),
            INDEX idx_km_test_events_kind (kind),
            INDEX idx_km_test_events_visit (visit)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_test_mode_texts (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            body TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    // 成功した後に立てる(lib/settings.php と同じ理由)
    $done = true;
}

/** 印が付いているか。**確かめられなければ付いていないとみなす**(一般の人を記録しない側へ倒す) */
function km_test_account_is_marked(PDO $pdo, string $sub): bool
{
    if ($sub === '') {
        return false;
    }
    try {
        km_test_mode_ensure_tables($pdo);
        $statement = $pdo->prepare('SELECT 1 FROM km_test_accounts WHERE sub = ?');
        $statement->execute([$sub]);

        return $statement->fetchColumn() !== false;
    } catch (Throwable $exception) {
        error_log('km_test_account_is_marked failed (treated as not marked): ' . $exception->getMessage());

        return false;
    }
}

/** @return list<array{sub:string,label:string,markedBy:string,markedAt:string}> */
function km_test_accounts_all(PDO $pdo): array
{
    km_test_mode_ensure_tables($pdo);
    $rows = [];
    foreach ($pdo->query('SELECT sub, label, marked_by, marked_at FROM km_test_accounts ORDER BY marked_at') as $row) {
        $rows[] = [
            'sub' => (string) $row['sub'],
            'label' => (string) $row['label'],
            'markedBy' => (string) $row['marked_by'],
            'markedAt' => (string) $row['marked_at'],
        ];
    }

    return $rows;
}

/** Logto の sub の形(英数字。Logto は 12 字前後)。それ以外は印を付けない */
function km_test_valid_sub(string $sub): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sub) === 1;
}

function km_test_account_mark(PDO $pdo, string $sub, string $label, string $markedBy): void
{
    if (!km_test_valid_sub($sub)) {
        throw new InvalidArgumentException('アカウントの ID の形が正しくありません。');
    }
    km_test_mode_ensure_tables($pdo);
    $pdo->prepare(
        'INSERT INTO km_test_accounts (sub, label, marked_by, marked_at) VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE label = VALUES(label)'
    )->execute([$sub, mb_substr($label, 0, 100), mb_substr($markedBy, 0, 255)]);
}

function km_test_account_unmark(PDO $pdo, string $sub): bool
{
    km_test_mode_ensure_tables($pdo);
    $statement = $pdo->prepare('DELETE FROM km_test_accounts WHERE sub = ?');
    $statement->execute([$sub]);

    return $statement->rowCount() > 0;
}

// ---- 文(アンケートの URL・操作方法) ------------------------------------------

function km_test_survey_url(PDO $pdo): string
{
    return km_setting_get($pdo, KM_TEST_SURVEY_SETTING, '');
}

/**
 * アンケートの URL を確かめる。**https だけ**(QR にして来場者のスマホで開かせるので、
 * 中身の見えない形や javascript: を通さない)。空は「出さない」。
 */
function km_test_survey_url_valid(string $url): bool
{
    if ($url === '') {
        return true;
    }
    if (strlen($url) > 255 || preg_match('/[\s<>"\']/', $url) === 1) {
        return false;
    }
    $parts = parse_url($url);

    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== '';
}

function km_test_guide_text(PDO $pdo): string
{
    try {
        km_test_mode_ensure_tables($pdo);
        $statement = $pdo->prepare('SELECT body FROM km_test_mode_texts WHERE name = ?');
        $statement->execute(['guide']);
        $value = $statement->fetchColumn();

        return $value === false ? KM_TEST_GUIDE_DEFAULT : (string) $value;
    } catch (Throwable $exception) {
        error_log('km_test_guide_text failed (default used): ' . $exception->getMessage());

        return KM_TEST_GUIDE_DEFAULT;
    }
}

function km_test_guide_set(PDO $pdo, string $body): void
{
    km_test_mode_ensure_tables($pdo);
    $pdo->prepare(
        'INSERT INTO km_test_mode_texts (name, body, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE body = VALUES(body), updated_at = NOW()'
    )->execute(['guide', $body]);
}

/** 操作方法の文を行に分ける(空の行は飛ばす)。**純粋関数** */
function km_test_guide_lines(string $body): array
{
    $lines = [];
    foreach (preg_split('/\R/u', $body) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}

// ---- 記録 --------------------------------------------------------------------

/**
 * 送られてきた記録を確かめて、表に入れる形にする。**純粋関数。**
 * 形の合わないものは黙って捨てる(1 件の誤りで残りを失わない)。
 *
 * @param mixed $input `{"visit":"16 字の 16 進","events":[{kind, t, field?, text?, node?, node2?, floor?}]}`
 * @return array{visit:string, rows:list<array{kind:string,field:?string,text:?string,node:?string,node2:?string,floor:?string,at:string}>}|null
 */
function km_test_events_parse(mixed $input, int $nowMillis): ?array
{
    if (!is_array($input)) {
        return null;
    }
    $visit = $input['visit'] ?? null;
    if (!is_string($visit) || preg_match('/^[0-9a-f]{16}$/', $visit) !== 1) {
        return null;
    }
    $events = $input['events'] ?? null;
    if (!is_array($events)) {
        return null;
    }

    $id = static fn (mixed $v): ?string => is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v) === 1 ? $v : null;
    $rows = [];
    foreach (array_slice(array_values($events), 0, KM_TEST_EVENTS_PER_REQUEST) as $event) {
        if (!is_array($event) || !in_array($event['kind'] ?? null, KM_TEST_EVENT_KINDS, true)) {
            continue;
        }
        $text = $event['text'] ?? null;
        $text = is_string($text) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '') : '';
        $field = $event['field'] ?? null;
        $floor = $event['floor'] ?? null;
        /*
         * 時刻は端末の時計を**信じすぎない**。送るまでの遅れ(最大で数十秒)だけ許し、
         * 未来や大昔の値はサーバーの今にする。
         */
        $t = $event['t'] ?? null;
        $millis = is_int($t) || is_float($t) ? (int) $t : $nowMillis;
        if ($millis > $nowMillis + 60_000 || $millis < $nowMillis - 3_600_000) {
            $millis = $nowMillis;
        }
        $rows[] = [
            'kind' => (string) $event['kind'],
            'field' => is_string($field) && in_array($field, ['start', 'goal'], true) ? $field : null,
            'text' => $text === '' ? null : mb_substr($text, 0, KM_TEST_EVENT_TEXT_MAX),
            'node' => $id($event['node'] ?? null),
            'node2' => $id($event['node2'] ?? null),
            'floor' => is_string($floor) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $floor) === 1 ? $floor : null,
            'at' => gmdate('Y-m-d H:i:s', intdiv($millis, 1000)) . sprintf('.%03d', $millis % 1000),
        ];
    }

    return ['visit' => $visit, 'rows' => $rows];
}

/** 時刻は UTC で入れる(読み出しで日本時間に直す)。入れた件数を返す */
function km_test_events_insert(PDO $pdo, string $sub, array $parsed): int
{
    if ($parsed['rows'] === []) {
        return 0;
    }
    km_test_mode_ensure_tables($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO km_test_events (sub, visit, kind, field, text, node_id, node_id2, floor_id, happened_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    foreach ($parsed['rows'] as $row) {
        $statement->execute([
            $sub, $parsed['visit'], $row['kind'], $row['field'], $row['text'],
            $row['node'], $row['node2'], $row['floor'], $row['at'],
        ]);
    }

    return count($parsed['rows']);
}

/** UTC で入れた時刻を日本時間の表示へ */
function km_test_local_time(string $utc): string
{
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Tokyo'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $utc;
    }
}

/** 地点の id → 表示名(地図の表から。無ければ id のまま) */
function km_test_node_names(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids, static fn ($v) => is_string($v) && $v !== '')));
    if ($ids === []) {
        return [];
    }
    $names = [];
    try {
        foreach (array_chunk($ids, 500) as $chunk) {
            $statement = $pdo->prepare(
                // title は後から増えた列で、無い DB もある(lib/map-data.php)。name だけを見る
                'SELECT id, COALESCE(NULLIF(name, \'\'), id) AS label FROM km_map_nodes WHERE id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $statement->execute($chunk);
            foreach ($statement as $row) {
                $names[(string) $row['id']] = (string) $row['label'];
            }
        }
    } catch (Throwable $exception) {
        error_log('km_test_node_names failed: ' . $exception->getMessage());
    }

    return $names;
}

/**
 * 管理画面のまとめ。
 *
 * @return array{total:int, visits:int, byKind:array<string,int>, searches:list<array{text:string,count:int}>,
 *               places:list<array{node:string,count:int}>, routes:list<array{from:?string,to:?string,count:int}>,
 *               floors:list<array{floor:string,count:int}>, first:?string, last:?string}
 */
function km_test_events_summary(PDO $pdo): array
{
    km_test_mode_ensure_tables($pdo);
    $one = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $byKind = [];
    foreach ($one('SELECT kind, COUNT(*) AS c FROM km_test_events GROUP BY kind') as $row) {
        $byKind[(string) $row['kind']] = (int) $row['c'];
    }
    $range = $one('SELECT COUNT(*) AS c, COUNT(DISTINCT visit) AS v, MIN(happened_at) AS f, MAX(happened_at) AS l FROM km_test_events')[0];

    return [
        'total' => (int) $range['c'],
        'visits' => (int) $range['v'],
        'byKind' => $byKind,
        'searches' => array_map(static fn ($r) => ['text' => (string) $r['text'], 'count' => (int) $r['c']], $one(
            "SELECT text, COUNT(*) AS c FROM km_test_events WHERE kind IN ('search', 'category') AND text IS NOT NULL
             GROUP BY text ORDER BY c DESC, text LIMIT 30"
        )),
        // 「探した場所」は、検索から選んだ地点・詳細を開いた地点・経路の目的地を合わせて数える
        'places' => array_map(static fn ($r) => ['node' => (string) $r['n'], 'count' => (int) $r['c']], $one(
            "SELECT n, COUNT(*) AS c FROM (
                 SELECT node_id AS n FROM km_test_events WHERE kind IN ('pick', 'detail', 'search') AND node_id IS NOT NULL
                 UNION ALL
                 SELECT node_id2 AS n FROM km_test_events WHERE kind = 'route' AND node_id2 IS NOT NULL
             ) AS t GROUP BY n ORDER BY c DESC, n LIMIT 30"
        )),
        'routes' => array_map(static fn ($r) => ['from' => $r['node_id'], 'to' => $r['node_id2'], 'count' => (int) $r['c']], $one(
            "SELECT node_id, node_id2, COUNT(*) AS c FROM km_test_events WHERE kind = 'route'
             GROUP BY node_id, node_id2 ORDER BY c DESC LIMIT 20"
        )),
        'floors' => array_map(static fn ($r) => ['floor' => (string) $r['floor_id'], 'count' => (int) $r['c']], $one(
            "SELECT floor_id, COUNT(*) AS c FROM km_test_events WHERE kind = 'floor' AND floor_id IS NOT NULL
             GROUP BY floor_id ORDER BY c DESC"
        )),
        'first' => $range['f'] !== null ? km_test_local_time((string) $range['f']) : null,
        'last' => $range['l'] !== null ? km_test_local_time((string) $range['l']) : null,
    ];
}

/** 新しい順に [limit] 件 */
function km_test_events_recent(PDO $pdo, int $limit = 100): array
{
    km_test_mode_ensure_tables($pdo);
    $statement = $pdo->prepare(
        'SELECT e.visit, e.kind, e.field, e.text, e.node_id, e.node_id2, e.floor_id, e.happened_at, COALESCE(NULLIF(a.label, \'\'), e.sub) AS account
           FROM km_test_events e LEFT JOIN km_test_accounts a ON a.sub = e.sub
          ORDER BY e.id DESC LIMIT ' . max(1, min(5000, $limit))
    );
    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** CSV(Excel で開けるよう BOM 付き UTF-8)を書き出す。全件・古い順 */
function km_test_events_csv(PDO $pdo, $out): int
{
    km_test_mode_ensure_tables($pdo);
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['時刻(日本)', 'アカウント', '来場者(visit)', '種類', '欄', '語・条件', '地点', '地点2(目的地)', '階'], ',', '"', '');
    $rows = $pdo->query(
        'SELECT e.visit, e.kind, e.field, e.text, e.node_id, e.node_id2, e.floor_id, e.happened_at, COALESCE(NULLIF(a.label, \'\'), e.sub) AS account
           FROM km_test_events e LEFT JOIN km_test_accounts a ON a.sub = e.sub ORDER BY e.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $names = km_test_node_names($pdo, array_merge(array_column($rows, 'node_id'), array_column($rows, 'node_id2')));
    $name = static fn ($id) => $id === null ? '' : ($names[$id] ?? $id);
    foreach ($rows as $row) {
        fputcsv($out, [
            km_test_local_time((string) $row['happened_at']),
            (string) $row['account'],
            (string) $row['visit'],
            KM_TEST_EVENT_LABELS[$row['kind']] ?? (string) $row['kind'],
            match ($row['field']) { 'start' => '出発地', 'goal' => '目的地', default => '' },
            // 表計算ソフトが式として読まない(=,+,-,@ で始まる語)
            preg_replace('/^([=+\-@])/', "'$1", (string) ($row['text'] ?? '')),
            $name($row['node_id']),
            $name($row['node_id2']),
            (string) ($row['floor_id'] ?? ''),
        ], ',', '"', '');
    }

    return count($rows);
}

function km_test_events_delete_all(PDO $pdo): int
{
    km_test_mode_ensure_tables($pdo);

    return (int) $pdo->exec('DELETE FROM km_test_events');
}
