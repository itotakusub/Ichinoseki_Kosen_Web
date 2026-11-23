<?php

declare(strict_types=1);

/**
 * 訪問者の位置(国・地域・都市・ASN・ISP)を DB に残す(2026-11-08)。
 *
 * 利用者「位置を調べ終わったらデータベースに保存する形に」「いろいろ調査したいので、位置の保存は永久にしてください」。
 *
 * - 引くのは今までどおり管理画面のブラウザ(assets/js/visitors.js。ipwho.is → api.country.is)。引いた結果をここへ送って残す
 * - Cloudflare の WAF で止めた記録(lib/cloudflare-analytics.php)にも国と ASN が付くので、まだ無い IP はそれでも埋める(src = cloudflare)
 * - **消さない**(期限なし)。km_visitor_geo は IP ごとの最新、km_visitor_geo_history は**中身が変わったときだけ**前の結果を足していく
 * - 表は新しく作るだけ(CREATE TABLE IF NOT EXISTS)。既存の表には触れない
 */

require_once __DIR__ . '/db.php';

/** 1 回に受け取る件数の上限(ブラウザは数件ずつまとめて送る)。 */
const KM_VISITOR_GEO_SAVE_MAX = 200;

/** 画面へ渡す件数の上限(新しく引いた順)。 */
const KM_VISITOR_GEO_LIST_MAX = 50000;

/** 引いた先の名前。知らない名前は断る。 */
const KM_VISITOR_GEO_SOURCES = ['ipwho.is', 'country.is', 'cloudflare'];

function km_visitor_geo_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (!km_db_table_exists($pdo, 'km_visitor_geo')) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS km_visitor_geo (
                ip VARCHAR(45) NOT NULL PRIMARY KEY,
                country CHAR(2) NOT NULL DEFAULT '',
                region VARCHAR(120) NOT NULL DEFAULT '',
                city VARCHAR(120) NOT NULL DEFAULT '',
                asn INT UNSIGNED NULL,
                org VARCHAR(200) NOT NULL DEFAULT '',
                isp VARCHAR(200) NOT NULL DEFAULT '',
                src VARCHAR(16) NOT NULL DEFAULT '',
                first_at BIGINT NOT NULL,
                looked_up_at BIGINT NOT NULL,
                lookups INT UNSIGNED NOT NULL DEFAULT 1,
                INDEX (looked_up_at),
                INDEX (country),
                INDEX (asn)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }
    if (!km_db_table_exists($pdo, 'km_visitor_geo_history')) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS km_visitor_geo_history (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                country CHAR(2) NOT NULL DEFAULT '',
                region VARCHAR(120) NOT NULL DEFAULT '',
                city VARCHAR(120) NOT NULL DEFAULT '',
                asn INT UNSIGNED NULL,
                org VARCHAR(200) NOT NULL DEFAULT '',
                isp VARCHAR(200) NOT NULL DEFAULT '',
                src VARCHAR(16) NOT NULL DEFAULT '',
                looked_up_at BIGINT NOT NULL,
                INDEX (ip),
                INDEX (looked_up_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }
    $ready = true;
}

/** 文字を 1 行にして長さを切る(制御文字を消す)。 */
function km_visitor_geo_text(mixed $value, int $max): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    if (preg_match('//u', $value) !== 1) {
        return '';
    }

    return mb_substr($value, 0, $max);
}

/**
 * 1 件を確かめて形をそろえる。**純粋関数**。だめなら null(理由は問わない。ブラウザの送った物なので黙って捨てる)。
 *
 * @param mixed $entry {ip, code, region, city, asn, org, isp, src, at}
 * @return array{ip:string, country:string, region:string, city:string, asn:?int, org:string, isp:string, src:string, at:int}|null
 */
function km_visitor_geo_normalize(mixed $entry, ?int $now = null): ?array
{
    if (!is_array($entry)) {
        return null;
    }
    $ip = is_string($entry['ip'] ?? null) ? trim($entry['ip']) : '';
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $country = is_string($entry['code'] ?? null) ? strtoupper(trim($entry['code'])) : '';
    if ($country !== '' && preg_match('/^[A-Z0-9]{2}$/', $country) !== 1) {
        return null;
    }
    $src = is_string($entry['src'] ?? null) ? $entry['src'] : '';
    if (!in_array($src, KM_VISITOR_GEO_SOURCES, true)) {
        return null;
    }
    $asn = $entry['asn'] ?? null;
    if (is_string($asn) && preg_match('/^(?:AS)?(\d{1,10})$/i', trim($asn), $m) === 1) {
        $asn = (int) $m[1];
    }
    $asn = is_int($asn) && $asn > 0 && $asn <= 4294967295 ? $asn : null;
    $now ??= time();
    // 時刻はブラウザのミリ秒か秒。先の時刻・古すぎる時刻(2026 年より前)は今にする
    $at = $entry['at'] ?? null;
    $at = is_int($at) || is_float($at) ? (int) ($at > 1e11 ? $at / 1000 : $at) : $now;
    if ($at > $now + 300 || $at < 1767225600) {
        $at = $now;
    }

    return [
        'ip' => $ip,
        'country' => $country,
        'region' => km_visitor_geo_text($entry['region'] ?? '', 120),
        'city' => km_visitor_geo_text($entry['city'] ?? '', 120),
        'asn' => $asn,
        'org' => km_visitor_geo_text($entry['org'] ?? '', 200),
        'isp' => km_visitor_geo_text($entry['isp'] ?? '', 200),
        'src' => $src,
        'at' => $at,
    ];
}

/** 中身(国・地域・都市・ASN・組織・ISP)が同じか。時刻と引いた先は比べない。**純粋関数**。 */
function km_visitor_geo_same(array $a, array $b): bool
{
    foreach (['country', 'region', 'city', 'org', 'isp'] as $key) {
        if ((string) ($a[$key] ?? '') !== (string) ($b[$key] ?? '')) {
            return false;
        }
    }

    return ($a['asn'] ?? null) === ($b['asn'] ?? null);
}

/**
 * 残す。**中身が変わったら前の結果を履歴へ**。同じなら時刻と回数だけ進める。
 *
 * $weak = true(Cloudflare の WAF の記録から埋めるとき)は、**まだ無い IP だけ**入れる
 * (都市まで引いた ipwho.is の結果を、国と ASN しかない記録で上書きしない)。
 *
 * @param list<array> $entries km_visitor_geo_normalize を通したもの
 * @return array{saved:int, changed:int}
 */
function km_visitor_geo_save(PDO $pdo, array $entries, bool $weak = false): array
{
    km_visitor_geo_ensure_tables($pdo);
    $find = $pdo->prepare('SELECT ip, country, region, city, asn, org, isp, src, looked_up_at FROM km_visitor_geo WHERE ip = ?');
    $insert = $pdo->prepare(
        'INSERT INTO km_visitor_geo (ip, country, region, city, asn, org, isp, src, first_at, looked_up_at, lookups)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $touch = $pdo->prepare('UPDATE km_visitor_geo SET looked_up_at = GREATEST(looked_up_at, ?), lookups = lookups + 1 WHERE ip = ?');
    $update = $pdo->prepare(
        'UPDATE km_visitor_geo SET country = ?, region = ?, city = ?, asn = ?, org = ?, isp = ?, src = ?, looked_up_at = ?, lookups = lookups + 1
         WHERE ip = ?'
    );
    $history = $pdo->prepare(
        'INSERT INTO km_visitor_geo_history (ip, country, region, city, asn, org, isp, src, looked_up_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $saved = 0;
    $changed = 0;
    foreach ($entries as $e) {
        $find->execute([$e['ip']]);
        $old = $find->fetch();
        $find->closeCursor();
        $values = [$e['country'], $e['region'], $e['city'], $e['asn'], $e['org'], $e['isp'], $e['src']];
        try {
            if (!is_array($old)) {
                $insert->execute([$e['ip'], ...$values, $e['at'], $e['at']]);
                $saved++;
                continue;
            }
        } catch (PDOException $exception) {
            // 同時に同じ IP を入れた(重複)。下の「在るとき」として扱う
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $find->execute([$e['ip']]);
            $old = $find->fetch();
            $find->closeCursor();
            if (!is_array($old)) {
                continue;
            }
        }
        if ($weak) {
            continue;
        }
        $old['asn'] = $old['asn'] === null ? null : (int) $old['asn'];
        if (km_visitor_geo_same($old, $e)) {
            $touch->execute([$e['at'], $e['ip']]);
            $saved++;
            continue;
        }
        // 古い結果(時刻が前)では上書きしない
        if ($e['at'] < (int) $old['looked_up_at']) {
            continue;
        }
        $history->execute([$old['ip'], $old['country'], $old['region'], $old['city'], $old['asn'], $old['org'], $old['isp'], $old['src'], (int) $old['looked_up_at']]);
        $update->execute([...$values, $e['at'], $e['ip']]);
        $saved++;
        $changed++;
    }

    return ['saved' => $saved, 'changed' => $changed];
}

/**
 * 画面へ渡す一覧(新しく引いた順・上限まで)。形は短い配列(行が多いので鍵の名前を繰り返さない):
 *   [ip, 国, 地域, 都市, ASN|null, 組織, ISP, 引いた先, 引いた時刻(秒), 前の結果の数]
 *
 * @return list<array{0:string,1:string,2:string,3:string,4:?int,5:string,6:string,7:string,8:int,9:int}>
 */
function km_visitor_geo_list(PDO $pdo, int $limit = KM_VISITOR_GEO_LIST_MAX): array
{
    km_visitor_geo_ensure_tables($pdo);
    $limit = max(1, min(KM_VISITOR_GEO_LIST_MAX, $limit));
    $rows = $pdo->query(
        'SELECT g.ip, g.country, g.region, g.city, g.asn, g.org, g.isp, g.src, g.looked_up_at, COALESCE(h.changes, 0) AS changes
         FROM km_visitor_geo g
         LEFT JOIN (SELECT ip, COUNT(*) AS changes FROM km_visitor_geo_history GROUP BY ip) h ON h.ip = g.ip
         ORDER BY g.looked_up_at DESC LIMIT ' . $limit
    )->fetchAll();

    return array_map(static fn (array $r): array => [
        (string) $r['ip'], (string) $r['country'], (string) $r['region'], (string) $r['city'],
        $r['asn'] === null ? null : (int) $r['asn'], (string) $r['org'], (string) $r['isp'], (string) $r['src'],
        (int) $r['looked_up_at'], (int) $r['changes'],
    ], $rows);
}
