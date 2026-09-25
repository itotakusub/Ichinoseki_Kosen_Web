<?php

declare(strict_types=1);

/**
 * 経路の重みを配る口(2026-09-25、利用者の指示)。中身は lib/route-weights.php。
 *
 *   GET                                  いま配っている重み(配っていなければ published=false と既定)
 *   POST {"weights": {...}}              一般の既定として配る      … **管理者だけ**
 *   POST {"action": "reset"}             配るのをやめる(各自の既定へ) … **管理者だけ**
 *
 * ## 読むのは誰でもよい
 *
 * 重みは秘密ではない(どちらの道を案内するかの好みで、地図と一緒に誰にでも配っている)。
 * 管理アプリが「いま配っている値を読み込む」ためにも使うので、読みには権限を求めない。
 *
 * ## 書けるのは管理者だけ
 *
 * Logto のアクセストークンに管理者の権限(LOGTO_ADMIN_PERMISSIONS)が全部あるときだけ。
 * **組織トークン(教職員)からは管理者の権限は生まれない**(logto_guard.php の守り)。
 * 誰が配ったかはタイムラインに残す。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/route-weights.php';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/route-weights.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $stored = km_route_weights_stored($pdo);
    respond([
        'success' => true,
        'published' => $stored !== null,
        'weights' => $stored ?? KM_ROUTE_WEIGHTS_DEFAULTS,
        'defaults' => KM_ROUTE_WEIGHTS_DEFAULTS,
    ]);
}

require_method('POST');

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);

// 本文は小さい(7 項目)。大きいものは読まずに断る
$input = km_api_json_body(4 * 1024);

/*
 * タイムラインの実行者。管理画面(guard.php)は $GLOBALS['KM_USER'] を立てるが、
 * API はトークンから来るので、ここで同じ形に入れてから記録する。
 */
require_once __DIR__ . '/../lib/admin-log.php';
$GLOBALS['KM_USER'] = [
    'sub' => (string) ($principal['subject'] ?? ''),
    'name' => (string) ($principal['username'] ?? ''),
];

if (($input['action'] ?? '') === 'reset') {
    km_route_weights_reset($pdo);
    km_admin_log_record('settings', 'route.weights_reset', 'アプリから');
    respond([
        'success' => true,
        'published' => false,
        'weights' => KM_ROUTE_WEIGHTS_DEFAULTS,
        'message' => '経路の重みを配るのをやめました。各端末は既定の重みに戻ります。',
    ]);
}

$weights = $input['weights'] ?? null;
if (!is_array($weights)) {
    respond(['success' => false, 'message' => 'weights が必要です。'], 400);
}

try {
    $saved = km_route_weights_publish($pdo, $weights);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/route-weights.php publish failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '保存できませんでした。'], 500);
}

km_admin_log_record('settings', 'route.weights_publish', json_encode($saved, JSON_UNESCAPED_UNICODE) ?: null);
respond([
    'success' => true,
    'published' => true,
    'weights' => $saved,
    'message' => '経路の重みを一般の既定として配りました。Website はすぐ、アプリは次に地図を取得・更新したときから効きます。',
]);
