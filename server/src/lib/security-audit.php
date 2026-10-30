<?php

declare(strict_types=1);

/**
 * 脆弱性が出たら知らせる(2026-10-30、高専祭の振り返りのフェーズ 6 の 2)。
 *
 * scripts/check-updates.sh(毎日)が、像の更新に加えて次を見る。読むのは scripts/security-audit.php(web の中で動く)。
 *
 *   php      … 動いている PHP と、php.net の同じ系列(8.4 など)の最新。最新が上で、セキュリティの直しを含む版なら知らせる
 *   composer … `composer audit --locked`(composer.lock の部品。勧告は Packagist と GitHub のセキュリティ勧告)
 *   npm      … Soketi の /app の `npm audit`(勧告は GitHub のセキュリティ勧告)。重さが moderate 以上だけ
 *   usn      … Ubuntu のセキュリティのお知らせ(USN)のうち、**ホストに入っている部品**に当たるもの。
 *              2026-10-30 の CVE-2026-98432(カーネルの TCP の穴)で、コンテナの外も見る要があると分かった。
 *              直したカーネルが出たときに USN が出るので、「直した版が出た」の知らせにもなる
 *
 * - 1 件ごとに鍵(`php:8.4.15`・`composer:PKSA-…`・`npm:<名前>@<範囲>`・`usn:USN-7000-1`)を作る。
 *   check-updates.sh は**知らせた鍵を覚え、同じ鍵は二度と送らない**(像の更新のような 7 日ごとの念押しはしない)
 * - 聞けなかったとき(ネットが無い・道具が無い)は「保留」の行を出す。**「無い」とは言わない**
 * - ここは**純粋関数だけ**(scripts/check.php が試す)
 */

/** npm の勧告で知らせる重さ(low は数が多く、Soketi の中で届かない所が多い) */
const KM_SECURITY_NPM_SEVERITIES = ['moderate', 'high', 'critical'];
/** 1 回に知らせる上限(多すぎるとメールが読めない。残りは次の日) */
const KM_SECURITY_MAX_ITEMS = 30;

/**
 * php.net の `releases/index.php?json&version=8.4` の答えと、動いている版を比べる。新しい版が無ければ null。
 *
 * @return array{key:string, name:string, current:string, available:string}|null
 */
function km_security_php_item(string $current, string $releaseJson): ?array
{
    $release = json_decode($releaseJson, true);
    $latest = is_array($release) ? (string) ($release['version'] ?? '') : '';
    if (preg_match('/\A\d+\.\d+\.\d+\z/', $latest) !== 1 || preg_match('/\A\d+\.\d+\.\d+/', $current) !== 1) {
        throw new InvalidArgumentException('php.net の答えを読めません');
    }
    if (version_compare($latest, $current, '<=')) {
        return null;
    }
    $tags = is_array($release['tags'] ?? null) ? array_map('strval', $release['tags']) : [];
    $security = in_array('security', $tags, true);
    return [
        'key' => 'php:' . $latest,
        'name' => 'PHP ' . $latest . ($security ? '(セキュリティの直しを含む版)' : '(直しの版)'),
        'current' => $current,
        'available' => $latest . ' — https://www.php.net/ChangeLog-' . strtok($latest, '.') . '.php#' . $latest,
    ];
}

/**
 * `composer audit --locked --format=json` の答えを並べる。
 *
 * @return list<array{key:string, name:string, current:string, available:string}>
 */
function km_security_composer_items(string $auditJson): array
{
    $audit = json_decode($auditJson, true);
    if (!is_array($audit) || !array_key_exists('advisories', $audit)) {
        throw new InvalidArgumentException('composer audit の答えを読めません');
    }
    $items = [];
    foreach ((array) $audit['advisories'] as $package => $advisories) {
        foreach ((array) $advisories as $advisory) {
            if (!is_array($advisory)) {
                continue;
            }
            $id = (string) ($advisory['advisoryId'] ?? ($advisory['cve'] ?? ''));
            if ($id === '') {
                continue;
            }
            $cve = (string) ($advisory['cve'] ?? '');
            $items[] = [
                'key' => 'composer:' . $id,
                'name' => (string) $package . ': ' . trim((string) ($advisory['title'] ?? '')) . ($cve !== '' ? '(' . $cve . ')' : ''),
                'current' => '当たる版: ' . (string) ($advisory['affectedVersions'] ?? '不明'),
                'available' => (string) ($advisory['link'] ?? ''),
            ];
        }
    }
    return $items;
}

/**
 * `npm audit --json` の答え(npm 7 以降の形)を並べる。重さが moderate 以上だけ。
 *
 * @return list<array{key:string, name:string, current:string, available:string}>
 */
function km_security_npm_items(string $auditJson): array
{
    $audit = json_decode($auditJson, true);
    if (!is_array($audit) || !is_array($audit['vulnerabilities'] ?? null)) {
        throw new InvalidArgumentException('npm audit の答えを読めません');
    }
    $items = [];
    foreach ($audit['vulnerabilities'] as $name => $vulnerability) {
        if (!is_array($vulnerability) || !in_array((string) ($vulnerability['severity'] ?? ''), KM_SECURITY_NPM_SEVERITIES, true)) {
            continue;
        }
        // via のうち、勧告そのもの(配列)だけを拾う。字の via は「この部品を通して当たる」という印
        $titles = [];
        $urls = [];
        foreach ((array) ($vulnerability['via'] ?? []) as $via) {
            if (is_array($via)) {
                $titles[] = trim((string) ($via['title'] ?? ''));
                $urls[] = (string) ($via['url'] ?? '');
            }
        }
        $range = (string) ($vulnerability['range'] ?? '');
        $items[] = [
            'key' => 'npm:' . $name . '@' . $range,
            'name' => 'Soketi の部品 ' . $name . '(' . $vulnerability['severity'] . ')' . ($titles !== [] ? ': ' . implode(' / ', array_filter($titles)) : ''),
            'current' => '当たる版: ' . ($range !== '' ? $range : '不明'),
            'available' => implode(' ', array_filter($urls)) ?: '(上の部品から当たります)',
        ];
    }
    return $items;
}

/**
 * Debian の版を比べる(dpkg --compare-versions と同じ決まり。エポック・上流・改版。~ は何よりも前)。**純粋関数**
 * a < b なら負、同じなら 0、a > b なら正
 */
function km_security_deb_compare(string $a, string $b): int
{
    $split = static function (string $v): array {
        $epoch = 0;
        if (preg_match('/\A(\d+):(.*)\z/s', $v, $m) === 1) {
            $epoch = (int) $m[1];
            $v = $m[2];
        }
        $dash = strrpos($v, '-');
        return $dash === false ? [$epoch, $v, '0'] : [$epoch, substr($v, 0, $dash), substr($v, $dash + 1)];
    };
    $order = static function (string $ch): int {
        if ($ch === '~') {
            return -1;
        }
        if ($ch === '') {
            return 0;
        }
        return ctype_alpha($ch) ? ord($ch) : ord($ch) + 256;
    };
    $part = static function (string $x, string $y) use ($order): int {
        while ($x !== '' || $y !== '') {
            // 数字でない所を 1 字ずつ
            while (($x !== '' && !ctype_digit($x[0])) || ($y !== '' && !ctype_digit($y[0]))) {
                $cx = ($x !== '' && !ctype_digit($x[0])) ? $x[0] : '';
                $cy = ($y !== '' && !ctype_digit($y[0])) ? $y[0] : '';
                $d = $order($cx) <=> $order($cy);
                if ($d !== 0) {
                    return $d;
                }
                $x = $cx === '' ? $x : substr($x, 1);
                $y = $cy === '' ? $y : substr($y, 1);
            }
            // 数字の所は数として
            preg_match('/\A\d*/', $x, $mx);
            preg_match('/\A\d*/', $y, $my);
            $d = ltrim($mx[0], '0') === ltrim($my[0], '0') ? 0
                : (strlen(ltrim($mx[0], '0')) <=> strlen(ltrim($my[0], '0')) ?: strcmp(ltrim($mx[0], '0'), ltrim($my[0], '0')));
            if ($d !== 0) {
                return $d;
            }
            $x = substr($x, strlen($mx[0]));
            $y = substr($y, strlen($my[0]));
        }
        return 0;
    };
    [$ea, $ua, $ra] = $split(trim($a));
    [$eb, $ub, $rb] = $split(trim($b));
    return ($ea <=> $eb) ?: ($part($ua, $ub) ?: $part($ra, $rb));
}

/**
 * Ubuntu の USN(`https://ubuntu.com/security/notices.json?release=<名前>`)のうち、
 * **入っている部品が、直した版より古いもの**(まだ当たっていないもの)。直した版がもう入っていれば知らせない
 * (unattended-upgrades が当てた後の USN で騒がない)。カーネルは版ごとに部品の名前が変わるので、
 * 一覧にある linux-image-generic などのまとめ役の部品で当たる。
 *
 * @param string $installed `dpkg-query -W -f='${Package} ${Version}\n'` の答え(1 行 1 部品)
 * @return list<array{key:string, name:string, current:string, available:string}>
 */
function km_security_usn_items(string $noticesJson, string $installed, string $release): array
{
    $decoded = json_decode($noticesJson, true);
    if (!is_array($decoded) || !is_array($decoded['notices'] ?? null)) {
        throw new InvalidArgumentException('USN の答えを読めません');
    }
    $have = [];
    foreach (preg_split('/\R/u', $installed) ?: [] as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (($fields[0] ?? '') !== '' && isset($fields[1])) {
            $have[$fields[0]] = $fields[1];
        }
    }
    $items = [];
    foreach ($decoded['notices'] as $notice) {
        if (!is_array($notice) || !is_string($notice['id'] ?? null)) {
            continue;
        }
        $packages = (array) (($notice['release_packages'] ?? [])[$release] ?? []);
        $hits = [];
        foreach ($packages as $package) {
            $name = is_array($package) ? (string) ($package['name'] ?? '') : '';
            $fixed = is_array($package) ? (string) ($package['version'] ?? '') : '';
            if ($name !== '' && $fixed !== '' && isset($have[$name]) && km_security_deb_compare($have[$name], $fixed) < 0) {
                $hits[$name] = [$have[$name], $fixed];
            }
        }
        if ($hits === []) {
            continue;
        }
        $cves = array_slice(array_map('strval', (array) ($notice['cves_ids'] ?? [])), 0, 5);
        $describe = static fn (int $index): string => implode(' / ', array_slice(array_map(static fn (string $n, array $v): string => $n . ' ' . $v[$index], array_keys($hits), $hits), 0, 3));
        $items[] = [
            'key' => 'usn:' . $notice['id'],
            'name' => 'Ubuntu ' . $notice['id'] . ': ' . trim((string) ($notice['title'] ?? '')) . ($cves !== [] ? '(' . implode(', ', $cves) . ')' : ''),
            'current' => '入っている版: ' . $describe(0) . (count($hits) > 3 ? ' ほか' : ''),
            'available' => '直した版: ' . $describe(1) . ' — https://ubuntu.com/security/notices/' . $notice['id'],
        ];
    }
    return $items;
}
/** check-updates.sh へ渡す 1 行(タブ区切り。値のタブと改行は空白に)。**純粋関数** */
function km_security_line(array $item): string
{
    $clean = static fn (string $s): string => trim((string) preg_replace('/[\t\r\n]+/', ' ', $s));
    return implode("\t", [$clean($item['key']), $clean($item['name']), $clean($item['current']), $clean($item['available'])]);
}
