<?php

declare(strict_types=1);

/**
 * アプリの表示調整の既定を配る口(2026-10-23、利用者の指示。中身は lib/display-defaults.php)。
 *
 *   GET                                  いま配っている値(配っていなければ published=false)
 *   POST {"values": {...}}               全員の端末に配る          … **管理者だけ**
 *   POST {"action": "reset"}             配るのをやめる            … **管理者だけ**
 *
 * 読むのは誰でもよい(見た目の既定は秘密ではない。アプリは地図を取るときに読む)。api/positioning-params.php と同じ作り。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/display-defaults.php';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/display-defaults.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $stored = km_display_defaults_stored($pdo);
    respond([
        'success' => true,
        'published' => $stored !== null,
        'values' => $stored === null ? null : (object) $stored,
    ]);
}

require_method('POST');

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);

// 項目は 200 まで・値は短い。大きいものは読まずに断る
$input = km_api_json_body(32 * 1024);

require_once __DIR__ . '/../lib/admin-log.php';
$GLOBALS['KM_USER'] = [
    'sub' => (string) ($principal['subject'] ?? ''),
    'name' => (string) ($principal['username'] ?? ''),
];
$updatedBy = (string) ($principal['subject'] ?? '') ?: null;

if (($input['action'] ?? '') === 'reset') {
    km_display_defaults_reset($pdo, $updatedBy);
    km_admin_log_record('settings', 'display.defaults_unpublish', 'アプリから');
    respond([
        'success' => true,
        'published' => false,
        'values' => null,
        'message' => '表示調整の既定を配るのをやめました。各端末は、変えていない項目がアプリの既定に戻ります。',
    ]);
}

$values = $input['values'] ?? null;
if (!is_array($values)) {
    respond(['success' => false, 'message' => 'values が必要です。'], 400);
}

try {
    $saved = km_display_defaults_publish($pdo, $values, $updatedBy);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/display-defaults.php publish failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '保存できませんでした。'], 500);
}

km_admin_log_record('settings', 'display.defaults_publish', count($saved) . ' 項目');
respond([
    'success' => true,
    'published' => true,
    'values' => (object) $saved,
    'message' => '表示調整の既定を全員の端末に配りました。各端末は次に地図を取得・更新したときから、変えていない項目に効きます。',
]);
