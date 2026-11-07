<?php

declare(strict_types=1);

/**
 * 管理アプリが塗った「場所の色分け」の絵を受け取る口(2026-11-07、利用者の指示「場所の色分けの絵は Android アプリのものを持ってくる」)。
 * 中身は lib/area-paint.php の km_area_paint_save_from_app()。送るのはアプリの AreaPaintUpload.kt(設定 → 地図の色分けを Website へ送る)。
 *
 *   POST {"floor": "1F", "png": "<base64>"}     → {"success": true, "floor": "1", "at": "…"}
 *
 * - **管理者のトークンだけ**(Logto の管理者の権限。api/map-backup.php と同じ確かめ方)
 * - 1 回に 1 つの階(1 枚は数百 KB。上限 8MB の PNG)。床の画像を Website で差し替えた階・大きさの違う絵は 400
 * - 置いたらタイムラインに「…が管理アプリの色分けの絵を Website に置きました」と残す
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/area-paint.php';
require_once __DIR__ . '/../lib/admin-log.php';

require_method('POST');

$principal = logto_require_principal();
if (($principal['is_admin'] ?? 0) !== 1) {
    respond(['success' => false, 'message' => 'この操作に必要なLogto権限がありません(管理者だけ)。'], 403);
}
$actorName = (string) ($principal['username'] ?? '');
try {
    require_once __DIR__ . '/../lib/logto-management.php';
    $actorName = km_logto_user_display_name((string) ($principal['subject'] ?? '')) ?? $actorName;
} catch (Throwable $exception) {
    error_log('api/area-paint.php display name: ' . $exception->getMessage());
}
$GLOBALS['KM_USER'] = ['sub' => (string) ($principal['subject'] ?? ''), 'name' => $actorName];

// PNG(8MB まで)を base64 にした分と少しの余裕
$input = km_api_json_body(12 * 1024 * 1024);
$floor = is_string($input['floor'] ?? null) ? $input['floor'] : '';
$png = base64_decode(is_string($input['png'] ?? null) ? $input['png'] : '', true);
if ($floor === '' || strlen($floor) > 40) {
    respond(['success' => false, 'message' => '階がありません。'], 400);
}
if ($png === false || $png === '') {
    respond(['success' => false, 'message' => '絵がありません。'], 400);
}

try {
    $record = km_area_paint_save_from_app(km_db(), $floor, $png);
    km_admin_log_record('content', 'map.area_paint_app', $record['floor']);
    respond(['success' => true, 'floor' => $record['floor'], 'at' => $record['at'], 'message' => $record['floor'] . ' の色分けの絵を置きました。']);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/area-paint.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    respond(['success' => false, 'message' => '保存できませんでした(サーバーのログを見てください)。'], 500);
}
