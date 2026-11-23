<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/cloudflare-analytics.php';

/**
 * Cloudflare の分析(2026-11-08。lib/cloudflare-analytics.php)を訪問者の画面へ渡す。読むだけ。
 *
 *   ?days=1〜366(既定 7)… WAF で止めた記録(新しい順・5000 件まで。全部の数は wafTotal)と、時間ごとの通信量
 *
 * 取り込みはホストの cron(scripts/host-cloudflare-import.sh)。まだ一度も動いていなければ configured: false。
 * 突き合わせ(同じ IP の訪問・危険の点)と表示はブラウザ(assets/js/visitors-cloudflare.js)。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$days = (int) ($_GET['days'] ?? 7);

try {
    echo json_encode(km_cf_data(km_db(), $days), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('cloudflare-data.php failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Cloudflare の表を読めませんでした。']);
}
