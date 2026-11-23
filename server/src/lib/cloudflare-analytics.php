<?php

declare(strict_types=1);

/**
 * Cloudflare の分析を DB に取り込む(2026-11-08、利用者「GraphQL Analytics API は Free プラン OK。作ってください(管理画面の訪問者に紐づける)」)。
 *
 * 取り込むもの(利用者が選んだ 2 つ):
 *   - WAF で止めた記録(firewallEventsAdaptive)… **サーバーまで届かない**ので、訪問者の記録(nginx)には残らない分
 *   - 時間ごとの通信量(httpRequests1hGroups)  … 要求・キャッシュで返した数・転送量・脅威・国ごとの数
 *
 * ## 流れ(**トークンは web に渡さない**。lib/apk-inbox.php の受け箱と同じ考え)
 *
 *   ホストの root の cron(1 時間ごと)が scripts/host-cloudflare-import.sh を呼ぶ
 *   → `php scripts/cloudflare-import.php plan --zone <ゾーン>` が、DB の続きから「何を問い合わせるか」を 1 行ずつ出す
 *   → ホストが curl で api.cloudflare.com/client/v4/graphql へ送る(トークンは root だけが読む cloudflare.local.conf)
 *   → 答えを `php scripts/cloudflare-import.php ingest <名前>` の標準入力へ渡し、ここで読み解いて DB に入れる
 *
 * ## 残す期間
 *
 * **消さない**(利用者「いろいろ調査したいので、位置の保存は永久に」。WAF の記録も位置を含むので同じにする)。
 * Cloudflare 側は細かいデータを少なくとも 31 日しか持たない(公式の Limits)ので、毎時取って貯める。
 * 初回は Cloudflare が持っている範囲(settings の notOlderThan)を、最大 KM_CF_BACKFILL 秒さかのぼる。
 *
 * 表は新しく作るだけ(CREATE TABLE IF NOT EXISTS)。WAF の記録の国・ASN は、まだ位置の無い IP の位置にも使う(lib/visitor-geo.php)。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/visitor-geo.php';

/** 初回にさかのぼる長さ(30 日)。Cloudflare が持っている範囲より長ければ、そちらに合わせる。 */
const KM_CF_BACKFILL = 30 * 86400;

/** 1 回の取り込みで問い合わせる窓の数の上限(初回の 30 日を 1 時間ではなく数回に分けて追いつく)。 */
const KM_CF_MAX_WINDOWS = 40;

/** settings を読み直す間隔。 */
const KM_CF_SETTINGS_TTL = 86400;

/** settings が読めないときの控えめな値(1 日の窓・1 万件・7 日前まで)。 */
const KM_CF_DEFAULT_LIMITS = ['maxDuration' => 86400, 'maxPageSize' => 10000, 'notOlderThan' => 7 * 86400];

/** 時間ごとの通信量は、終わったばかりの時間も数が増えるので、最後の 3 時間を毎回取り直す。 */
const KM_CF_TRAFFIC_REFETCH = 3 * 3600;

/** 画面へ渡す WAF の記録の上限(新しい順)。 */
const KM_CF_WAF_LIST_MAX = 5000;

const KM_CF_QUERY_SETTINGS = <<<'GQL'
    query KmSettings($zoneTag: string) {
      viewer {
        zones(filter: { zoneTag: $zoneTag }) {
          settings {
            firewallEventsAdaptive { maxDuration maxPageSize notOlderThan }
            httpRequests1hGroups { maxDuration maxPageSize notOlderThan }
          }
        }
      }
    }
    GQL;

const KM_CF_QUERY_WAF = <<<'GQL'
    query KmWaf($zoneTag: string, $since: Time, $until: Time) {
      viewer {
        zones(filter: { zoneTag: $zoneTag }) {
          firewallEventsAdaptive(filter: { datetime_geq: $since, datetime_lt: $until }, limit: __LIMIT__, orderBy: [datetime_ASC]) {
            rayName datetime action source ruleId description
            clientIP clientCountryName clientAsn clientASNDescription
            clientRequestHTTPHost clientRequestHTTPMethodName clientRequestPath userAgent
          }
        }
      }
    }
    GQL;

const KM_CF_QUERY_TRAFFIC = <<<'GQL'
    query KmTraffic($zoneTag: string, $since: Time, $until: Time) {
      viewer {
        zones(filter: { zoneTag: $zoneTag }) {
          httpRequests1hGroups(filter: { datetime_geq: $since, datetime_lt: $until }, limit: __LIMIT__, orderBy: [datetime_ASC]) {
            dimensions { datetime }
            sum {
              requests cachedRequests bytes cachedBytes threats pageViews
              countryMap { clientCountryName requests threats bytes }
            }
            uniq { uniques }
          }
        }
      }
    }
    GQL;

function km_cf_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (!km_db_table_exists($pdo, 'km_cf_waf_events')) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS km_cf_waf_events (
                ray_name VARCHAR(32) NOT NULL PRIMARY KEY,
                occurred_at BIGINT NOT NULL,
                action VARCHAR(32) NOT NULL DEFAULT '',
                source VARCHAR(48) NOT NULL DEFAULT '',
                rule_id VARCHAR(64) NOT NULL DEFAULT '',
                description VARCHAR(255) NOT NULL DEFAULT '',
                client_ip VARCHAR(45) NOT NULL DEFAULT '',
                country CHAR(2) NOT NULL DEFAULT '',
                asn INT UNSIGNED NULL,
                asn_name VARCHAR(200) NOT NULL DEFAULT '',
                host VARCHAR(255) NOT NULL DEFAULT '',
                method VARCHAR(16) NOT NULL DEFAULT '',
                path VARCHAR(1024) NOT NULL DEFAULT '',
                user_agent VARCHAR(512) NOT NULL DEFAULT '',
                imported_at BIGINT NOT NULL,
                INDEX (occurred_at),
                INDEX (client_ip),
                INDEX (country)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }
    if (!km_db_table_exists($pdo, 'km_cf_traffic_hourly')) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS km_cf_traffic_hourly (
                hour_at BIGINT NOT NULL PRIMARY KEY,
                requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
                cached_requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                cached_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                threats BIGINT UNSIGNED NOT NULL DEFAULT 0,
                page_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uniques BIGINT UNSIGNED NOT NULL DEFAULT 0,
                countries TEXT NULL,
                updated_at BIGINT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }
    if (!km_db_table_exists($pdo, 'km_cf_import_state')) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS km_cf_import_state (
                name VARCHAR(32) NOT NULL PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at BIGINT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }
    $ready = true;
}

/** @return array<string, mixed>|null */
function km_cf_state_get(PDO $pdo, string $name): ?array
{
    km_cf_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT value, updated_at FROM km_cf_import_state WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $value = json_decode((string) $row['value'], true);

    return is_array($value) ? $value + ['_at' => (int) $row['updated_at']] : null;
}

/** @param array<string, mixed> $value */
function km_cf_state_set(PDO $pdo, string $name, array $value, ?int $now = null): void
{
    km_cf_ensure_tables($pdo);
    $pdo->prepare(
        'INSERT INTO km_cf_import_state (name, value, updated_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
    )->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $now ?? time()]);
}

/** ゾーンの ID(32 桁の 16 進)。 */
function km_cf_valid_zone(string $zone): bool
{
    return preg_match('/^[0-9a-f]{32}$/', $zone) === 1;
}

/** GraphQL の Time(UTC・秒まで)。 */
function km_cf_time(int $epoch): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $epoch);
}

/**
 * 1 つの dataset の窓を切る。**純粋関数**。
 *
 * @param int $cursor 続き(ここから)
 * @param int $end    ここまで(含まない)
 * @return list<array{0:int,1:int}>
 */
function km_cf_windows(int $cursor, int $end, int $maxDuration, int $maxWindows = KM_CF_MAX_WINDOWS): array
{
    $out = [];
    $step = max(3600, $maxDuration);
    for ($start = $cursor; $start < $end && count($out) < $maxWindows; $start += $step) {
        $out[] = [$start, min($start + $step, $end)];
    }

    return $out;
}

/**
 * settings の値(1 dataset 分)。足りない・おかしい値は控えめな値にする。**純粋関数**。
 *
 * @return array{maxDuration:int, maxPageSize:int, notOlderThan:int}
 */
function km_cf_limits(mixed $raw): array
{
    $out = KM_CF_DEFAULT_LIMITS;
    if (is_array($raw)) {
        foreach (array_keys($out) as $key) {
            if (is_int($raw[$key] ?? null) && $raw[$key] > 0) {
                $out[$key] = $raw[$key];
            }
        }
    }
    $out['maxDuration'] = min($out['maxDuration'], 7 * 86400);
    $out['maxPageSize'] = min($out['maxPageSize'], 10000);

    return $out;
}

/**
 * 何を問い合わせるか(1 行 1 つ: [名前, 本文の JSON])。DB の続きから決める。
 *
 * - settings が無いか古い → settings だけを出す(読んだら ingest が「もう一度 plan を」と言う)
 * - WAF … 最後に入れた記録の時刻から今(1 分前)まで
 * - 通信量 … 最後に入れた時間の 3 時間前から、今の時間の終わりまで(終わったばかりの時間の数も直す)
 *
 * @return list<array{0:string, 1:string}>
 */
function km_cf_plan(PDO $pdo, string $zone, ?int $now = null): array
{
    if (!km_cf_valid_zone($zone)) {
        throw new InvalidArgumentException('ゾーンの ID は 32 桁の 16 進です。');
    }
    km_cf_ensure_tables($pdo);
    $now ??= time();
    $body = static fn (string $query, array $vars): string => json_encode(
        ['query' => $query, 'variables' => ['zoneTag' => $zone] + $vars],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $settings = km_cf_state_get($pdo, 'settings');
    if ($settings === null || $now - (int) $settings['_at'] > KM_CF_SETTINGS_TTL) {
        return [['settings', $body(KM_CF_QUERY_SETTINGS, [])]];
    }

    $plan = [];
    // WAF
    $waf = km_cf_limits($settings['waf'] ?? null);
    $oldest = $now - min(KM_CF_BACKFILL, $waf['notOlderThan'] - 600);
    // 続きは「読み終えた窓の終わり」(km_cf_ingest が覚える)。止めた記録が何時間も無くても、同じ所から読み直さない
    $cursor = max($oldest, (int) ((km_cf_state_get($pdo, 'waf_cursor') ?? [])['at'] ?? $oldest));
    $wafQuery = str_replace('__LIMIT__', (string) $waf['maxPageSize'], KM_CF_QUERY_WAF);
    foreach (km_cf_windows($cursor, $now - 60, $waf['maxDuration']) as [$since, $until]) {
        $plan[] = ["waf:{$since}:{$until}", $body($wafQuery, ['since' => km_cf_time($since), 'until' => km_cf_time($until)])];
    }
    // 通信量(時間の区切りにそろえる)
    $traffic = km_cf_limits($settings['traffic'] ?? null);
    $oldestHour = intdiv($now - min(KM_CF_BACKFILL, $traffic['notOlderThan'] - 3600), 3600) * 3600 + 3600;
    $endHour = intdiv($now, 3600) * 3600 + 3600;
    $done = (km_cf_state_get($pdo, 'traffic_cursor') ?? [])['at'] ?? null;
    $cursor = $done === null ? $oldestHour : max($oldestHour, min((int) $done, $endHour - 3600) - KM_CF_TRAFFIC_REFETCH);
    $trafficQuery = str_replace('__LIMIT__', '1000', KM_CF_QUERY_TRAFFIC);
    foreach (km_cf_windows($cursor, $endHour, intdiv($traffic['maxDuration'], 3600) * 3600) as [$since, $until]) {
        $plan[] = ["traffic:{$since}:{$until}", $body($trafficQuery, ['since' => km_cf_time($since), 'until' => km_cf_time($until)])];
    }

    return $plan;
}

/**
 * 答えの JSON を開き、最初のゾーンを返す。errors があれば断る(文はそのまま記録に残す。トークンは入らない)。
 *
 * @return array<string, mixed>
 */
function km_cf_zone_of(string $text): array
{
    $doc = json_decode($text, true);
    if (!is_array($doc)) {
        throw new RuntimeException('Cloudflare の答えが JSON ではありません(' . strlen($text) . ' バイト)。');
    }
    if (is_array($doc['errors'] ?? null) && $doc['errors'] !== []) {
        $messages = array_map(static fn ($e): string => is_array($e) ? (string) ($e['message'] ?? '?') : (string) $e, $doc['errors']);
        throw new RuntimeException('Cloudflare がエラーを返しました: ' . mb_substr(implode(' / ', $messages), 0, 500));
    }
    $zone = $doc['data']['viewer']['zones'][0] ?? null;
    if (!is_array($zone)) {
        throw new RuntimeException('Cloudflare の答えにゾーンがありません(ゾーンの ID か、トークンの対象のゾーンが違います)。');
    }

    return $zone;
}

/**
 * WAF の記録 1 件を確かめて形をそろえる。**純粋関数**。だめなら null。
 *
 * @return array<string, mixed>|null
 */
function km_cf_waf_normalize(mixed $e, int $now): ?array
{
    if (!is_array($e)) {
        return null;
    }
    $ray = is_string($e['rayName'] ?? null) ? $e['rayName'] : '';
    $at = is_string($e['datetime'] ?? null) ? strtotime($e['datetime']) : false;
    if (preg_match('/^[0-9a-f]{8,32}$/', $ray) !== 1 || $at === false) {
        return null;
    }
    $ip = is_string($e['clientIP'] ?? null) && filter_var($e['clientIP'], FILTER_VALIDATE_IP) !== false ? $e['clientIP'] : '';
    $country = is_string($e['clientCountryName'] ?? null) ? strtoupper($e['clientCountryName']) : '';
    $asn = $e['clientAsn'] ?? null;
    $asn = is_string($asn) && ctype_digit($asn) ? (int) $asn : (is_int($asn) ? $asn : null);
    $method = is_string($e['clientRequestHTTPMethodName'] ?? null) && preg_match('/^[A-Z]{1,16}$/', $e['clientRequestHTTPMethodName']) === 1 ? $e['clientRequestHTTPMethodName'] : '';
    $rule = is_string($e['ruleId'] ?? null) && preg_match('/^[0-9A-Za-z_-]{0,64}$/', $e['ruleId']) === 1 ? $e['ruleId'] : '';

    return [
        'ray' => $ray,
        'at' => $at,
        'action' => km_visitor_geo_text($e['action'] ?? '', 32),
        'source' => km_visitor_geo_text($e['source'] ?? '', 48),
        'rule' => $rule,
        'description' => km_visitor_geo_text($e['description'] ?? '', 255),
        'ip' => $ip,
        'country' => preg_match('/^[A-Z0-9]{2}$/', $country) === 1 ? $country : '',
        'asn' => $asn !== null && $asn > 0 && $asn <= 4294967295 ? $asn : null,
        'asnName' => km_visitor_geo_text($e['clientASNDescription'] ?? '', 200),
        'host' => km_visitor_geo_text($e['clientRequestHTTPHost'] ?? '', 255),
        'method' => $method,
        'path' => km_visitor_geo_text($e['clientRequestPath'] ?? '', 1024),
        'ua' => km_visitor_geo_text($e['userAgent'] ?? '', 512),
        'imported' => $now,
    ];
}

/**
 * 時間ごとの通信量 1 件。**純粋関数**。だめなら null。国は {"JP":[要求, 脅威, バイト], …}。
 *
 * @return array<string, mixed>|null
 */
function km_cf_traffic_normalize(mixed $g): ?array
{
    if (!is_array($g)) {
        return null;
    }
    $at = is_string($g['dimensions']['datetime'] ?? null) ? strtotime($g['dimensions']['datetime']) : false;
    if ($at === false || $at % 3600 !== 0) {
        return null;
    }
    $sum = is_array($g['sum'] ?? null) ? $g['sum'] : [];
    $n = static fn (mixed $v): int => is_int($v) && $v >= 0 ? $v : 0;
    $countries = [];
    foreach (is_array($sum['countryMap'] ?? null) ? $sum['countryMap'] : [] as $c) {
        $code = is_array($c) && is_string($c['clientCountryName'] ?? null) ? strtoupper($c['clientCountryName']) : '';
        if (preg_match('/^[A-Z0-9]{2}$/', $code) === 1) {
            $countries[$code] = [$n($c['requests'] ?? 0), $n($c['threats'] ?? 0), $n($c['bytes'] ?? 0)];
        }
    }

    return [
        'hour' => $at,
        'requests' => $n($sum['requests'] ?? 0),
        'cachedRequests' => $n($sum['cachedRequests'] ?? 0),
        'bytes' => $n($sum['bytes'] ?? 0),
        'cachedBytes' => $n($sum['cachedBytes'] ?? 0),
        'threats' => $n($sum['threats'] ?? 0),
        'pageViews' => $n($sum['pageViews'] ?? 0),
        'uniques' => $n($g['uniq']['uniques'] ?? 0),
        'countries' => $countries,
    ];
}

/** 1 ページで足りなかったときの続き: 読めた記録のうちいちばん新しい時刻(読めなければ窓の始め)。**純粋関数**。 */
function km_cf_waf_last_at(array $list, int $fallback): int
{
    $max = $fallback;
    foreach ($list as $e) {
        $at = is_array($e) && is_string($e['datetime'] ?? null) ? strtotime($e['datetime']) : false;
        if ($at !== false && $at > $max) {
            $max = $at;
        }
    }

    return $max;
}

/**
 * 1 つの答えを DB に入れる。
 *
 * @return array{name:string, rows:int, inserted:int, replan:bool}
 *   replan … もう一度 plan からやり直してほしい(settings を読んだ・WAF の窓が 1 ページで足りなかった)
 */
function km_cf_ingest(PDO $pdo, string $name, string $text, ?int $now = null): array
{
    km_cf_ensure_tables($pdo);
    $now ??= time();

    if ($name === 'settings') {
        try {
            $zone = km_cf_zone_of($text);
            $s = is_array($zone['settings'] ?? null) ? $zone['settings'] : [];
            km_cf_state_set($pdo, 'settings', [
                'waf' => km_cf_limits($s['firewallEventsAdaptive'] ?? null),
                'traffic' => km_cf_limits($s['httpRequests1hGroups'] ?? null),
                'note' => '',
            ], $now);
        } catch (RuntimeException $exception) {
            // 読めなくても止めない(控えめな値で進める)。画面に理由を出す
            km_cf_state_set($pdo, 'settings', [
                'waf' => KM_CF_DEFAULT_LIMITS, 'traffic' => KM_CF_DEFAULT_LIMITS,
                'note' => '範囲を読めなかったので控えめな値で取り込みます: ' . $exception->getMessage(),
            ], $now);
        }

        return ['name' => $name, 'rows' => 0, 'inserted' => 0, 'replan' => true];
    }

    if (preg_match('/^(waf|traffic):(\d{9,11}):(\d{9,11})$/', $name, $m) !== 1) {
        throw new InvalidArgumentException('知らない名前です: ' . $name);
    }
    $zone = km_cf_zone_of($text);

    if ($m[1] === 'waf') {
        $list = is_array($zone['firewallEventsAdaptive'] ?? null) ? $zone['firewallEventsAdaptive'] : [];
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO km_cf_waf_events (ray_name, occurred_at, action, source, rule_id, description, client_ip, country, asn, asn_name, host, method, path, user_agent, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $inserted = 0;
        $geo = [];
        foreach ($list as $raw) {
            $e = km_cf_waf_normalize($raw, $now);
            if ($e === null) {
                continue;
            }
            $insert->execute([$e['ray'], $e['at'], $e['action'], $e['source'], $e['rule'], $e['description'], $e['ip'], $e['country'],
                $e['asn'], $e['asnName'], $e['host'], $e['method'], $e['path'], $e['ua'], $e['imported']]);
            $inserted += $insert->rowCount();
            if ($e['ip'] !== '' && $e['country'] !== '') {
                $geo[$e['ip']] = ['ip' => $e['ip'], 'country' => $e['country'], 'region' => '', 'city' => '', 'asn' => $e['asn'],
                    'org' => $e['asnName'], 'isp' => '', 'src' => 'cloudflare', 'at' => $e['at']];
            }
        }
        if ($geo !== []) {
            km_visitor_geo_save($pdo, array_values($geo), true);
        }
        $limits = km_cf_limits((km_cf_state_get($pdo, 'settings') ?? [])['waf'] ?? null);
        $truncated = count($list) >= $limits['maxPageSize'];
        // 読み終えた所を覚える。1 ページで足りなかったときは、最後に読んだ記録の時刻から(同じ記録は ray で重ならない)
        $reached = $truncated ? km_cf_waf_last_at($list, (int) $m[2]) : (int) $m[3];
        $prev = (int) ((km_cf_state_get($pdo, 'waf_cursor') ?? [])['at'] ?? 0);
        if ($reached > $prev) {
            km_cf_state_set($pdo, 'waf_cursor', ['at' => $reached], $now);
        }

        return ['name' => $name, 'rows' => count($list), 'inserted' => $inserted, 'replan' => $truncated];
    }

    $list = is_array($zone['httpRequests1hGroups'] ?? null) ? $zone['httpRequests1hGroups'] : [];
    $upsert = $pdo->prepare(
        'INSERT INTO km_cf_traffic_hourly (hour_at, requests, cached_requests, bytes, cached_bytes, threats, page_views, uniques, countries, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE requests = VALUES(requests), cached_requests = VALUES(cached_requests), bytes = VALUES(bytes),
           cached_bytes = VALUES(cached_bytes), threats = VALUES(threats), page_views = VALUES(page_views), uniques = VALUES(uniques),
           countries = VALUES(countries), updated_at = VALUES(updated_at)'
    );
    $count = 0;
    foreach ($list as $raw) {
        $t = km_cf_traffic_normalize($raw);
        if ($t === null) {
            continue;
        }
        $upsert->execute([$t['hour'], $t['requests'], $t['cachedRequests'], $t['bytes'], $t['cachedBytes'], $t['threats'], $t['pageViews'], $t['uniques'],
            json_encode($t['countries'], JSON_THROW_ON_ERROR), $now]);
        $count++;
    }
    // 読み終えた所(今の時間より先は覚えない。次の回に今の時間を取り直す)
    $reached = min((int) $m[3], intdiv($now, 3600) * 3600);
    $prev = (int) ((km_cf_state_get($pdo, 'traffic_cursor') ?? [])['at'] ?? 0);
    if ($reached > $prev) {
        km_cf_state_set($pdo, 'traffic_cursor', ['at' => $reached], $now);
    }

    return ['name' => $name, 'rows' => count($list), 'inserted' => $count, 'replan' => false];
}

/**
 * 取り込みの結果を残す(画面の「最後に取り込んだ時刻・失敗」)。
 *
 * @param array<string, mixed> $summary
 */
function km_cf_record_run(PDO $pdo, bool $ok, string $message, array $summary = [], ?int $now = null): void
{
    $now ??= time();
    if ($ok) {
        km_cf_state_set($pdo, 'last_success', ['message' => mb_substr($message, 0, 500)] + $summary, $now);
    } else {
        km_cf_state_set($pdo, 'last_error', ['message' => mb_substr($message, 0, 500)], $now);
    }
}

/**
 * 管理画面へ渡す中身(admin/api/cloudflare-data.php)。
 *
 * @return array<string, mixed>
 */
function km_cf_data(PDO $pdo, int $days, ?int $now = null): array
{
    km_cf_ensure_tables($pdo);
    $now ??= time();
    $days = max(1, min(366, $days));
    $since = $now - $days * 86400;

    $stmt = $pdo->prepare(
        'SELECT ray_name, occurred_at, action, source, rule_id, description, client_ip, country, asn, asn_name, host, method, path, user_agent
         FROM km_cf_waf_events WHERE occurred_at >= ? ORDER BY occurred_at DESC LIMIT ' . KM_CF_WAF_LIST_MAX
    );
    $stmt->execute([$since]);
    $waf = array_map(static fn (array $r): array => [
        'ray' => (string) $r['ray_name'], 'at' => (int) $r['occurred_at'], 'action' => (string) $r['action'], 'source' => (string) $r['source'],
        'rule' => (string) $r['rule_id'], 'description' => (string) $r['description'], 'ip' => (string) $r['client_ip'], 'country' => (string) $r['country'],
        'asn' => $r['asn'] === null ? null : (int) $r['asn'], 'asnName' => (string) $r['asn_name'], 'host' => (string) $r['host'],
        'method' => (string) $r['method'], 'path' => (string) $r['path'], 'ua' => (string) $r['user_agent'],
    ], $stmt->fetchAll());
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM km_cf_waf_events WHERE occurred_at >= ?');
    $countStmt->execute([$since]);

    $stmt = $pdo->prepare('SELECT hour_at, requests, cached_requests, bytes, cached_bytes, threats, page_views, uniques, countries FROM km_cf_traffic_hourly WHERE hour_at >= ? ORDER BY hour_at');
    $stmt->execute([intdiv($since, 3600) * 3600]);
    $traffic = array_map(static function (array $r): array {
        $countries = json_decode((string) ($r['countries'] ?? ''), true);

        return [
            'hour' => (int) $r['hour_at'], 'requests' => (int) $r['requests'], 'cachedRequests' => (int) $r['cached_requests'], 'bytes' => (int) $r['bytes'],
            'cachedBytes' => (int) $r['cached_bytes'], 'threats' => (int) $r['threats'], 'pageViews' => (int) $r['page_views'], 'uniques' => (int) $r['uniques'],
            'countries' => is_array($countries) ? $countries : [],
        ];
    }, $stmt->fetchAll());

    $state = [];
    foreach (['settings', 'last_success', 'last_error'] as $name) {
        $value = km_cf_state_get($pdo, $name);
        if ($value !== null) {
            $state[$name] = $value;
        }
    }

    return [
        'success' => true,
        'days' => $days,
        'configured' => isset($state['last_success']) || isset($state['last_error']) || isset($state['settings']),
        'state' => $state,
        'wafTotal' => (int) $countStmt->fetchColumn(),
        'waf' => $waf,
        'traffic' => $traffic,
    ];
}
