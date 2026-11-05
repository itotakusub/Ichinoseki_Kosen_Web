<?php

declare(strict_types=1);

/**
 * コンテナのイメージの検査結果を読む(2026-11-06。タスクマネージャーの「イメージの検査」)。
 *
 * 書くのはホストの scripts/host-image-scan.sh(root の cron が毎週日曜。Trivy を docker.sock なしで回す)。
 * web へは run/hoststats を読み取り専用で渡してある(compose の web の volumes。stats.json と同じ場所)。
 *
 * **ファイルの中身は信じすぎない。** 形の合わない項目は捨て、数は 0 以上の整数に、文字列は長さを切る
 * (画面に出すときは km_e で逃がす)。
 */

const KM_IMAGE_SCAN_FILE = '/var/www/hoststats/image-scan.json';
const KM_IMAGE_SCAN_MAX_BYTES = 512 * 1024;
/** 結果がこれより古ければ「古い」と出す(週 1 回 + 余裕) */
const KM_IMAGE_SCAN_STALE = 9 * 86400;

/**
 * @return array{generatedAt:int, trivy:string, dbUpdatedAt:string, failed:int,
 *               images:list<array{image:string, status:string, created:string, os:string, critical:int, high:int,
 *                                 criticalFixable:int, highFixable:int, osVulns:int,
 *                                 top:list<array{id:string, severity:string, pkg:string, installed:string, fixed:string}>}>}|null
 */
function km_image_scan_parse(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data) || !is_int($data['generatedAt'] ?? null) || !is_array($data['images'] ?? null)) {
        return null;
    }
    $str = static fn ($v, int $max = 200): string => is_string($v) ? mb_substr($v, 0, $max) : '';
    $num = static fn ($v): int => is_int($v) && $v > 0 ? $v : 0;

    $images = [];
    foreach ($data['images'] as $image) {
        if (!is_array($image) || !is_string($image['image'] ?? null) || $image['image'] === '') {
            continue;
        }
        $status = in_array($image['status'] ?? null, ['ok', 'error', 'missing'], true) ? $image['status'] : 'error';
        $top = [];
        foreach (is_array($image['top'] ?? null) ? $image['top'] : [] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) {
                continue;
            }
            $top[] = [
                'id' => $str($row['id'], 80),
                'severity' => in_array($row['severity'] ?? null, ['CRITICAL', 'HIGH'], true) ? $row['severity'] : 'HIGH',
                'pkg' => $str($row['pkg'] ?? null, 120),
                'installed' => $str($row['installed'] ?? null, 80),
                'fixed' => $str($row['fixed'] ?? null, 120),
            ];
        }
        $images[] = [
            'image' => $str($image['image']),
            'status' => $status,
            'created' => $str($image['created'] ?? null, 40),
            'os' => $str($image['os'] ?? null, 120),
            'critical' => $num($image['critical'] ?? null),
            'high' => $num($image['high'] ?? null),
            'criticalFixable' => $num($image['criticalFixable'] ?? null),
            'highFixable' => $num($image['highFixable'] ?? null),
            'osVulns' => $num($image['osVulns'] ?? null),
            'top' => $top,
        ];
    }

    // 直す必要の大きい順(直る版のある CRITICAL → 直る版のある HIGH → 全体)
    usort($images, static fn (array $a, array $b): int =>
        [$b['criticalFixable'], $b['highFixable'], $b['critical'] + $b['high'], $a['image']]
        <=> [$a['criticalFixable'], $a['highFixable'], $a['critical'] + $a['high'], $b['image']]);

    return [
        'generatedAt' => $data['generatedAt'],
        'trivy' => $str($data['trivy'] ?? null, 20),
        'dbUpdatedAt' => $str($data['dbUpdatedAt'] ?? null, 40),
        'failed' => $num($data['failed'] ?? null),
        'images' => $images,
    ];
}

/** 置いてあるファイルを読む。無い・大きすぎる・形が違えば null */
function km_image_scan_read(string $path = KM_IMAGE_SCAN_FILE): ?array
{
    if (!is_file($path) || filesize($path) > KM_IMAGE_SCAN_MAX_BYTES) {
        return null;
    }
    $text = @file_get_contents($path);

    return is_string($text) ? km_image_scan_parse($text) : null;
}
