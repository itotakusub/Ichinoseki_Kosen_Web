<?php

declare(strict_types=1);

/**
 * 地図を別のサイトに埋め込む(段 15。2026-11-14)。
 *
 * 利用者「マップの API を作成してください。別のサイトで表示されるような埋め込み方を想定してください」。決めたこと(11-14):
 *   - 埋め込めるのは**管理画面で登録したサイトだけ**(admin/embed-sites.php)
 *   - v1 は「見る・階の切り替え・地点を指す・押すと名前」。検索と道案内は次の版
 *   - 地図のデータを JSON で配る口も作る(api/embed-places.php。登録したサイトのオリジンにだけ CORS)
 *
 * 入口:
 *   /embed/?site=<札>&floor=<階>&focus=<地点>&lang=ja|en   枠に入れる地図(embed/index.php)
 *   /embed/v1.js                                          埋め込む側のページが読む小さな JS(KosenMap.embed)
 *   /api/embed-places.php?site=<札>                         地点・階の JSON
 *
 * ## 守り
 *
 * - **出すのはサインインしていない来場者と同じもの。** セッションを開かずに km_map_data を呼ぶ
 *   (先生の印・お試しの閲覧・地図のパスワードはどれもセッションにしか無い)。そのうえで要る項目だけに絞る
 *   (km_embed_public_data。教職員の氏名・メモ・リンク・経路の重み・イベントで隠した地点は出さない)
 * - 枠に入れてよい親は、札に登録したオリジンだけ(CSP の frame-ancestors。ブラウザが止める)。
 *   **札は秘密ではない**(埋め込む側のページに載る)。止めるのは frame-ancestors と、札を「止める」こと
 * - 地図を「パスワードが必要」にしているときは、埋め込みにも JSON にも何も出さない(錠を迂回しない)
 * - 表は新しく作るだけ(ALTER しない)
 */

require_once __DIR__ . '/db.php';

/** 1 つのサイトに登録できるオリジンの数 */
const KM_EMBED_MAX_ORIGINS = 5;
const KM_EMBED_MAX_LABEL = 100;
/** 埋め込みに出す地点の種類(名前のある所。道の点・壁・ルーターは出さない) */
const KM_EMBED_NODE_TYPES = ['room', 'facility', 'stairs', 'entrance'];

function km_embed_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_embed_sites (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_key CHAR(16) NOT NULL,
            label VARCHAR(100) NOT NULL DEFAULT '',
            origins TEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY site_key (site_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_embed_views_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            places INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

// ---------------------------------------------------------------------------
// 純粋関数(scripts/check.php が試す)
// ---------------------------------------------------------------------------

/**
 * オリジンを 1 つ確かめて正しい形にする。**https だけ・パスなし・ワイルドカードなし。** 違えば null。
 *
 *   "https://Example.ac.jp/"     → "https://example.ac.jp"
 *   "https://example.ac.jp:8443" → "https://example.ac.jp:8443"
 *   "https://example.ac.jp:443"  → "https://example.ac.jp"
 *   "http://…"・"https://*.x"・"https://x/page"・"https://user@x" → null
 */
function km_embed_normalize_origin(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 255 || preg_match('/[\s*\\\\]/', $value) === 1) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        return null;
    }
    $host = strtolower($parts['host']);
    // 名前は英数字・ハイフン・点だけ(日本語のドメインは xn-- の形で書く)。点で始まる・終わる・続くものは断る
    if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
        return null;
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }

    return 'https://' . $host . ($port !== null && $port !== 443 ? ':' . $port : '');
}

/**
 * 入力(改行・カンマ区切り)からオリジンの並びを作る。**純粋関数**。
 *
 * @return array{ok: bool, origins: list<string>, error: string}
 */
function km_embed_parse_origins(string $text): array
{
    $out = [];
    foreach (preg_split('/[\s,]+/', $text) ?: [] as $item) {
        if ($item === '') {
            continue;
        }
        $origin = km_embed_normalize_origin($item);
        if ($origin === null) {
            return ['ok' => false, 'origins' => [], 'error' => '「' . mb_substr($item, 0, 80) . '」はオリジンの形ではありません(https://名前 だけ。パス・* は書けません)。'];
        }
        $out[$origin] = true;
    }
    $origins = array_keys($out);
    if ($origins === []) {
        return ['ok' => false, 'origins' => [], 'error' => '埋め込むサイトのオリジン(例: https://www.example.ac.jp)を 1 つ以上書いてください。'];
    }
    if (count($origins) > KM_EMBED_MAX_ORIGINS) {
        return ['ok' => false, 'origins' => [], 'error' => 'オリジンは 1 つのサイトに ' . KM_EMBED_MAX_ORIGINS . ' 個までです。'];
    }

    return ['ok' => true, 'origins' => $origins, 'error' => ''];
}

/** 札の形(16 桁の 16 進) */
function km_embed_valid_key(string $key): bool
{
    return preg_match('/^[0-9a-f]{16}$/', $key) === 1;
}

/**
 * CSP の frame-ancestors の値。**純粋関数**。サイトが無い・止めているなら 'none'。
 * 本体('self')はいつも入れる(本体のページの中で開いたとき。上の枠のどれか 1 つでも外れると止まるため)
 *
 * @param array{enabled: bool, origins: list<string>}|null $site
 */
function km_embed_frame_ancestors(?array $site): string
{
    if ($site === null || !$site['enabled'] || $site['origins'] === []) {
        return "'none'";
    }

    return "'self' " . implode(' ', $site['origins']);
}

/**
 * Origin の頭書きが、そのサイトに登録したオリジンか(JSON の口の CORS)。**純粋関数**
 *
 * @param array{enabled: bool, origins: list<string>}|null $site
 */
function km_embed_origin_allowed(?array $site, string $origin): bool
{
    if ($site === null || !$site['enabled'] || $origin === '') {
        return false;
    }
    $normalized = km_embed_normalize_origin($origin);

    return $normalized !== null && in_array($normalized, $site['origins'], true);
}

/** 階の名前の英語(「1F」「1階」→「1F」・「外」を含む →「Outdoors」・ほかはそのまま)。**純粋関数** */
function km_embed_floor_label_en(string $label): string
{
    if (preg_match('/^\s*(\d+)\s*(F|階)\s*$/u', $label, $m) === 1) {
        return $m[1] . 'F';
    }
    if (preg_match('/^\s*B\s*(\d+)\s*(F|階)?\s*$/iu', $label, $m) === 1 || preg_match('/^\s*地下\s*(\d+)\s*階?\s*$/u', $label, $m) === 1) {
        return 'B' . $m[1] . 'F';
    }
    if (str_contains($label, '外') || str_contains($label, '屋外')) {
        return 'Outdoors';
    }

    return $label;
}

/**
 * 地図のデータ(km_map_data の答え)から、埋め込み・JSON の口に出してよいものだけを取り出す。**純粋関数**。
 *
 * 出すもの: 階(番号・名前・画像の URL・座標の大きさ・種類)と、名前のある地点(番号・階・名前・英語の名前・種類・座標)。
 * **出さないもの**: 教職員の氏名・メモ・リンク・ポスター・スケジュール・経路の線と重み・イベントで隠した地点・
 * 道の点・壁・ルーター。地図に錠が掛かっていれば locked: true で中身は空
 *
 * @param array<string, mixed> $map km_map_data の答え(セッションなしで呼んだもの)
 * @return array{locked: bool, floors: list<array<string, mixed>>, places: list<array<string, mixed>>}
 */
function km_embed_public_data(array $map): array
{
    if (($map['mapLocked'] ?? false) === true) {
        return ['locked' => true, 'floors' => [], 'places' => []];
    }
    $floors = [];
    $floorIds = [];
    foreach (($map['floors'] ?? []) as $floor) {
        if (!is_array($floor) || !isset($floor['id'])) {
            continue;
        }
        $id = (string) $floor['id'];
        $floorIds[$id] = true;
        $label = (string) ($floor['label'] ?? $id);
        $floors[] = [
            'id' => $id,
            'label' => $label,
            'labelEn' => km_embed_floor_label_en($label),
            'kind' => (string) ($floor['kind'] ?? 'campus'),
            'image' => (string) (($floor['areaPath'] ?? '') !== '' ? $floor['areaPath'] : ($floor['svgPath'] ?? '')),
            'width' => isset($floor['coordWidth']) && is_numeric($floor['coordWidth']) ? (float) $floor['coordWidth'] : null,
            'height' => isset($floor['coordHeight']) && is_numeric($floor['coordHeight']) ? (float) $floor['coordHeight'] : null,
        ];
    }
    $places = [];
    foreach (($map['nodes'] ?? []) as $id => $node) {
        if (!is_array($node)) {
            continue;
        }
        $type = (string) ($node['type'] ?? '');
        $name = trim((string) ($node['alias'] ?? '') !== '' ? (string) $node['alias'] : (string) ($node['title'] ?? $node['name'] ?? ''));
        if (!in_array($type, KM_EMBED_NODE_TYPES, true) || $name === '' || !empty($node['hidden'])
            || !isset($floorIds[(string) ($node['floor'] ?? '')])) {
            continue;
        }
        $place = [
            'id' => (string) ($node['id'] ?? $id),
            'floor' => (string) $node['floor'],
            'name' => $name,
            'type' => $type,
            'x' => (float) ($node['x'] ?? 0),
            'y' => (float) ($node['y'] ?? 0),
        ];
        $nameEn = trim((string) ($node['nameEn'] ?? ''));
        if ($nameEn !== '') {
            $place['nameEn'] = $nameEn;
        }
        $places[] = $place;
    }

    return ['locked' => false, 'floors' => $floors, 'places' => $places];
}

/**
 * 埋め込みの URL の引数を確かめる。**純粋関数**。おかしな値は捨てる(既定に戻す)
 *
 * @param array<string, mixed> $query $_GET
 * @return array{site: string, floor: ?string, focus: ?string, lang: string}
 */
function km_embed_parse_query(array $query): array
{
    $text = static fn (string $key, int $max): ?string => is_string($query[$key] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,' . $max . '}$/', $query[$key]) === 1 ? $query[$key] : null;

    return [
        'site' => is_string($query['site'] ?? null) && km_embed_valid_key($query['site']) ? $query['site'] : '',
        'floor' => $text('floor', 64),
        'focus' => $text('focus', 64),
        'lang' => ($query['lang'] ?? '') === 'en' ? 'en' : 'ja',
    ];
}

// ---------------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------------

/** @return array{id: int, key: string, label: string, origins: list<string>, enabled: bool, createdBy: string, createdAt: string, updatedAt: string} */
function km_embed_row(array $row): array
{
    $origins = json_decode((string) $row['origins'], true);

    return [
        'id' => (int) $row['id'],
        'key' => (string) $row['site_key'],
        'label' => (string) $row['label'],
        // 読むときにも確かめる(表を直に触られても、形の違うオリジンを CSP に出さない)
        'origins' => is_array($origins) ? array_values(array_filter(array_map(static fn ($o): ?string => is_string($o) ? km_embed_normalize_origin($o) : null, $origins))) : [],
        'enabled' => (int) $row['enabled'] === 1,
        'createdBy' => (string) $row['created_by'],
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

/** 札からサイトを引く(止めていても返す。使えるかは enabled で見る)。無ければ null */
function km_embed_site_by_key(PDO $pdo, string $key): ?array
{
    if (!km_embed_valid_key($key)) {
        return null;
    }
    km_embed_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM km_embed_sites WHERE site_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return is_array($row) ? km_embed_row($row) : null;
}

/** @return list<array> 新しい順 */
function km_embed_sites(PDO $pdo): array
{
    km_embed_ensure_tables($pdo);

    return array_map('km_embed_row', $pdo->query('SELECT * FROM km_embed_sites ORDER BY id DESC')->fetchAll());
}

/** @param list<string> $origins km_embed_parse_origins を通したもの */
function km_embed_site_create(PDO $pdo, string $label, array $origins, string $by): array
{
    km_embed_ensure_tables($pdo);
    $key = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO km_embed_sites (site_key, label, origins, enabled, created_by, created_at, updated_at) VALUES (?, ?, ?, 1, ?, NOW(), NOW())')
        ->execute([$key, mb_substr($label, 0, KM_EMBED_MAX_LABEL), json_encode(array_values($origins), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), mb_substr($by, 0, 191)]);

    return km_embed_site_by_key($pdo, $key) ?? throw new RuntimeException('embed site was not created');
}

/** @param list<string> $origins */
function km_embed_site_update(PDO $pdo, int $id, string $label, array $origins): void
{
    km_embed_ensure_tables($pdo);
    $pdo->prepare('UPDATE km_embed_sites SET label = ?, origins = ?, updated_at = NOW() WHERE id = ?')
        ->execute([mb_substr($label, 0, KM_EMBED_MAX_LABEL), json_encode(array_values($origins), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $id]);
}

function km_embed_site_set_enabled(PDO $pdo, int $id, bool $enabled): void
{
    km_embed_ensure_tables($pdo);
    $pdo->prepare('UPDATE km_embed_sites SET enabled = ?, updated_at = NOW() WHERE id = ?')->execute([$enabled ? 1 : 0, $id]);
}

/** 表示の回数を数える(枠の地図 = views・JSON の口 = places)。失敗しても地図は止めない */
function km_embed_count(PDO $pdo, int $siteId, string $what = 'views'): void
{
    $column = $what === 'places' ? 'places' : 'views';
    try {
        km_embed_ensure_tables($pdo);
        $pdo->prepare("INSERT INTO km_embed_views_daily (site_id, day, {$column}) VALUES (?, CURDATE(), 1) ON DUPLICATE KEY UPDATE {$column} = {$column} + 1")
            ->execute([$siteId]);
    } catch (Throwable $exception) {
        error_log('km_embed_count: ' . $exception->getMessage());
    }
}

/**
 * サイトごとの、この 30 日の回数。
 *
 * @return array<int, array{views: int, places: int}>
 */
function km_embed_counts_30d(PDO $pdo): array
{
    km_embed_ensure_tables($pdo);
    $out = [];
    foreach ($pdo->query('SELECT site_id, SUM(views) v, SUM(places) p FROM km_embed_views_daily WHERE day >= CURDATE() - INTERVAL 29 DAY GROUP BY site_id') as $row) {
        $out[(int) $row['site_id']] = ['views' => (int) $row['v'], 'places' => (int) $row['p']];
    }

    return $out;
}

/**
 * 地図のデータ(サインインしていない来場者と同じもの)を、セッションなしで組み立てて絞る。
 * **セッションを開かない**ことが守りの要(開くと、管理者や先生のブラウザで埋め込みを見たときに氏名が乗る)
 */
function km_embed_load_public(PDO $pdo): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        throw new LogicException('km_embed_load_public must be called without a session');
    }
    // 先生の印・お試しの閲覧・地図のパスワードはどれも $_SESSION にしか無い。空にしてから読む
    $_SESSION = [];
    require_once __DIR__ . '/map-data.php';

    return km_embed_public_data(km_map_data($pdo));
}
