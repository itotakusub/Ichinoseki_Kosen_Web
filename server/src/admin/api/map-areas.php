<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/map-data.php';
require_once dirname(__DIR__, 2) . '/lib/map-node-area.php';
require_once dirname(__DIR__, 2) . '/lib/area-paint.php';
require_once dirname(__DIR__, 2) . '/lib/admin-log.php';

/**
 * 地図の色分け(admin/map-areas.php。2026-10-30、lib/area-paint.php)。
 *
 *   GET                                           → {"floors": [塗る材料(階ごと)]}
 *   POST {"floor", "source", "png": base64}(X-KM-CSRF) → 塗った画像を置く {"ok": true, "at"}
 *   POST {"floor", "clear": true}(X-KM-CSRF)          → 塗りを外す
 *
 * 塗るのはブラウザ(Main/area-segmentation.js)。**1 回に 1 つの階だけ**送る(1 枚は 1600×1200 の PNG で数百 KB)。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function km_map_areas_respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'GET') {
    session_write_close();
    try {
        $pdo = km_db();
        km_map_areas_respond(['floors' => km_area_paint_inputs(km_map_data($pdo, null, true), km_map_node_area_all($pdo), km_area_paint_records($pdo))]);
    } catch (Throwable $exception) {
        error_log('admin/api/map-areas.php GET failed: ' . $exception::class . ': ' . $exception->getMessage());
        km_map_areas_respond(['error' => '地図を読めませんでした(サーバーのログを見てください)。'], 500);
    }
}
if ($method !== 'POST') {
    km_map_areas_respond(['error' => 'GET か POST で送ってください。'], 405);
}
if (!km_csrf_verify()) {
    km_map_areas_respond(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。'], 403);
}

// PNG を base64 にした分(8MB の 4/3 と少し)まで読む
$input = json_decode((string) file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024), true);
$floor = is_array($input) && is_string($input['floor'] ?? null) ? $input['floor'] : '';
if ($floor === '' || strlen($floor) > 40) {
    km_map_areas_respond(['error' => '階を選んでください。'], 400);
}
session_write_close();

try {
    $pdo = km_db();
    if (($input['clear'] ?? false) === true) {
        km_area_paint_clear($pdo, $floor);
        km_admin_log_record('content', 'map.area_clear', $floor);
        km_map_areas_respond(['ok' => true]);
    }
    $png = base64_decode(is_string($input['png'] ?? null) ? $input['png'] : '', true);
    if ($png === false || $png === '') {
        km_map_areas_respond(['error' => '画像がありません。'], 400);
    }
    $record = km_area_paint_save($pdo, $floor, is_string($input['source'] ?? null) ? $input['source'] : '', $png);
    km_admin_log_record('content', 'map.area_paint', $floor);
    km_map_areas_respond(['ok' => true, 'at' => $record['at']]);
} catch (InvalidArgumentException $exception) {
    km_map_areas_respond(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('admin/api/map-areas.php POST failed: ' . $exception::class . ': ' . $exception->getMessage());
    km_map_areas_respond(['error' => '保存できませんでした(サーバーのログを見てください)。'], 500);
}
