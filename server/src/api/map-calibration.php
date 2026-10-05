<?php

declare(strict_types=1);

/**
 * 地図の北と距離の補正を配る口(2026-10-05、利用者の指示)。中身は lib/map-calibration.php。
 *
 *   GET                                     いま配っている補正(配っていなければ published=false)
 *   POST {"calibration": {...}}             全員の端末に配る          … **管理者だけ**
 *   POST {"action": "reset"}                配るのをやめる            … **管理者だけ**
 *
 * 読むのは誰でもよい(北の向きと実測の距離は秘密ではなく、地図と一緒に誰にでも配る)。
 * 書けるのは Logto のアクセストークンに管理者の権限が全部あるときだけ(api/route-weights.php と同じ)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-calibration.php';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/map-calibration.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $stored = km_map_calibration_stored($pdo);
    respond([
        'success' => true,
        'published' => $stored !== null,
        'calibration' => $stored,
    ]);
}

require_method('POST');

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);

// 区間 500 本でも 64KB に収まる。大きいものは読まずに断る
$input = km_api_json_body(64 * 1024);

require_once __DIR__ . '/../lib/admin-log.php';
$GLOBALS['KM_USER'] = [
    'sub' => (string) ($principal['subject'] ?? ''),
    'name' => (string) ($principal['username'] ?? ''),
];
$updatedBy = (string) ($principal['subject'] ?? '') ?: null;

if (($input['action'] ?? '') === 'reset') {
    km_map_calibration_reset($pdo, $updatedBy);
    km_admin_log_record('settings', 'map.calibration_unpublish', 'アプリから');
    respond([
        'success' => true,
        'published' => false,
        'calibration' => null,
        'message' => '北と距離の補正を配るのをやめました。各端末は自分の既定に戻ります。',
    ]);
}

$calibration = $input['calibration'] ?? null;
if (!is_array($calibration)) {
    respond(['success' => false, 'message' => 'calibration が必要です。'], 400);
}

try {
    $saved = km_map_calibration_publish($pdo, $calibration, $updatedBy);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/map-calibration.php publish failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '保存できませんでした。'], 500);
}

// 記録は件数だけ(区間の一覧は長い)
km_admin_log_record(
    'settings',
    'map.calibration_publish',
    '北 ' . $saved['mapUpBearingDegrees'] . '° / 実測区間 ' . count($saved['segments']) . ' 本'
);
respond([
    'success' => true,
    'published' => true,
    'calibration' => $saved,
    'message' => '北と距離の補正を全員の端末に配りました。アプリは次に地図を取得・更新したときから効きます。',
]);
