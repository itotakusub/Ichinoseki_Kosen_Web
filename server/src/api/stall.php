<?php

declare(strict_types=1);

/**
 * 屋台がメニューと混み具合を送る口(2026-10-30、振り返りのフェーズ 5 の 2。中身は lib/stalls.php)。
 *
 *   POST /api/stall.php
 *   Authorization: Bearer kms_<32 字の 16 進>            管理画面「屋台」で出した鍵。**URL に載せない**
 *   {"menu":[{"name":"たこ焼き","price":300,"soldOut":false}], "waiting":12, "note":"焼きたて"}
 *   → {"success":true,"waitingExpiresIn":1800,"menuExpiresIn":43200}
 *
 * - 送らなかった項目は前の値のまま。waiting・note は "" か null で消す
 * - 1 屋台 5 秒に 1 回まで(429)。入口の回数は nginx の km_api。本文は 8KB まで
 * - 鍵が違う・止めた鍵は 401(どちらかは言わない)
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stalls.php';

require_method('POST');

$key = km_stall_key_from_header((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if (!km_stall_key_valid($key)) {
    header('WWW-Authenticate: Bearer');
    respond(['success' => false, 'message' => '鍵が要ります(Authorization: Bearer kms_…)。'], 401);
}
$input = km_api_json_body(8 * 1024);
try {
    $post = km_stall_validate_post($input);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
}

try {
    $pdo = km_db();
    $stall = km_stall_by_key($pdo, $key);
    if ($stall === null) {
        header('WWW-Authenticate: Bearer error="invalid_token"');
        respond(['success' => false, 'message' => '鍵が違うか、止められています。'], 401);
    }
    if (km_stall_post($pdo, (int) $stall['id'], $post) === 'limited') {
        header('Retry-After: ' . KM_STALL_POST_INTERVAL);
        respond(['success' => false, 'message' => '送るのが早すぎます。' . KM_STALL_POST_INTERVAL . ' 秒あけてください。'], 429);
    }
} catch (Throwable $exception) {
    error_log('api/stall.php failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

respond(['success' => true, 'waitingExpiresIn' => KM_STALL_WAITING_TTL, 'menuExpiresIn' => KM_STALL_MENU_TTL]);
