<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/map-floors.php';

/**
 * zip から読み込んだ、まだ階に当てていない画像(admin/map-floors.php の表の小さな画像。2026-10-21)。
 *
 *   GET ?i=<表の番号>
 *
 * **どの置き場かはセッションが決める**(URL で置き場を選ばせない)。番号はセッションの一覧の中のものだけ。
 */

$staged = $_SESSION['km_floor_zip'] ?? null;
$index = $_GET['i'] ?? '';
session_write_close();

$item = null;
if (is_array($staged) && is_string($index) && ctype_digit($index)) {
    $item = $staged['items'][(int) $index] ?? null;
}
$token = is_array($staged) ? (string) ($staged['token'] ?? '') : '';
$file = is_array($item) ? (string) ($item['file'] ?? '') : '';
if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1 || preg_match('/^\d+\.(png|jpg|gif|webp)$/', $file) !== 1) {
    http_response_code(404);
    exit;
}
$path = km_map_floor_staging_dir() . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . (km_raster_image_mime($file) ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
