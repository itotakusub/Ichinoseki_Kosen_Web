<?php

declare(strict_types=1);

/**
 * アプリの測位のパラメータを配る口(2026-10-06、利用者の指示)。中身は lib/positioning-params.php。
 *
 *   GET                                  いま配っている値(配っていなければ published=false)
 *   POST {"params": {...}}               全員の端末に配る          … **管理者だけ**
 *   POST {"action": "reset"}             配るのをやめる            … **管理者だけ**
 *
 * 読むのは誰でもよい(測位の係数は秘密ではない。アプリは地図を取るときに読む)。
 * 書けるのは Logto のアクセストークンに管理者の権限が全部あるときだけ(api/map-calibration.php と同じ)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/positioning-params.php';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/positioning-params.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $stored = km_positioning_params_stored($pdo);
    respond([
        'success' => true,
        'published' => $stored !== null,
        'params' => $stored,
    ]);
}

require_method('POST');

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);

// 28 項目の数だけ。大きいものは読まずに断る
$input = km_api_json_body(8 * 1024);

require_once __DIR__ . '/../lib/admin-log.php';
$GLOBALS['KM_USER'] = [
    'sub' => (string) ($principal['subject'] ?? ''),
    'name' => (string) ($principal['username'] ?? ''),
];
$updatedBy = (string) ($principal['subject'] ?? '') ?: null;

if (($input['action'] ?? '') === 'reset') {
    km_positioning_params_reset($pdo, $updatedBy);
    km_admin_log_record('settings', 'positioning.params_unpublish', 'アプリから');
    respond([
        'success' => true,
        'published' => false,
        'params' => null,
        'message' => '測位のパラメータを配るのをやめました。各端末は既定の値に戻ります。',
    ]);
}

$params = $input['params'] ?? null;
if (!is_array($params)) {
    respond(['success' => false, 'message' => 'params が必要です。'], 400);
}

try {
    $saved = km_positioning_params_publish($pdo, $params, $updatedBy);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/positioning-params.php publish failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '保存できませんでした。'], 500);
}

// 記録は「既定と違う項目の数」だけ(全部の値は長い)
km_admin_log_record('settings', 'positioning.params_publish', '既定と違う項目 ' . km_positioning_params_changed_count($saved) . ' 個');
respond([
    'success' => true,
    'published' => true,
    'params' => $saved,
    'message' => '測位のパラメータを全員の端末に配りました。アプリは次に地図を取得・更新したときから効きます。',
]);
