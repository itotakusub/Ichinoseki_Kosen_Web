<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/poster-import.php';
require_once dirname(__DIR__, 2) . '/lib/admin-log.php';

/**
 * ポスター(広告)をまとめて取り込む口(admin/posters.php の「まとめて取り込む」。2026-10-21、lib/poster-import.php)。
 *
 *   POST {"template": "https://…/shops/%N/detail", "number": 1, "enabled": true}(X-KM-CSRF)
 *     → {"status": "created|updated|unchanged|missing", "id", "title", "message"}
 *
 * **1 回に 1 つの番号だけ。** 画面の JS が番号を 1 つずつ送る(58 件を 1 回でやると PHP の 30 秒と nginx の 60 秒を超える)。
 * 1 つの番号でページと画像の 2 回だけ取りに行く(どちらも 10 秒で切る)。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function km_poster_import_respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    km_poster_import_respond(['error' => 'POST で送信してください。'], 405);
}
if (!km_csrf_verify()) {
    km_poster_import_respond(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。'], 403);
}

$input = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
$template = is_array($input) ? (string) ($input['template'] ?? '') : '';
$number = is_array($input) && is_int($input['number'] ?? null) ? $input['number'] : -1;
$enabled = is_array($input) && ($input['enabled'] ?? false) === true;

// セッションの書き込みを先に閉じる(取りに行く間、同じ人のほかの画面を待たせない)
session_write_close();
set_time_limit(45);

try {
    $result = km_poster_import_one(km_db(), $template, $number, $enabled);
} catch (InvalidArgumentException $exception) {
    km_poster_import_respond(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('admin/api/poster-import.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    km_poster_import_respond(['error' => '取り込めませんでした(サーバーのログを見てください)。'], 500);
}

if ($result['status'] === 'created' || $result['status'] === 'updated') {
    km_admin_log_record('content', 'poster.import', 'No.' . $number . ' ' . $result['title'] . ($result['status'] === 'created' ? '(作った)' : '(直した)'));
}

km_poster_import_respond($result);
