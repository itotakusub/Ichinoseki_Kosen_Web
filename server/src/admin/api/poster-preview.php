<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/posters.php';

/**
 * 「URL から」の画像のプレビュー(admin/posters.php。2026-10-21)。
 *
 *   POST {"url": "https://…"}(X-KM-CSRF)→ {"dataUrl": "data:image/…;base64,…", "width", "height", "bytes"}
 *
 * **サーバーが取りに行き、確かめた画像を data: で返す。** 管理画面の CSP は img-src が 'self' と data: だけなので、
 * 外部の URL をそのまま <img> に入れても出ない(出せるようにすると、どこの画像でも読めるようになる)。
 * 保存はしない —— 保存のときにもう一度取りに行く(プレビューの中身を送り返させると、確かめていないものを受けることになる)。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function km_poster_preview_respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    km_poster_preview_respond(['error' => 'POST で送信してください。'], 405);
}
if (!km_csrf_verify()) {
    km_poster_preview_respond(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。'], 403);
}

$input = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
$url = is_array($input) ? trim((string) ($input['url'] ?? '')) : '';

try {
    $bytes = km_poster_fetch_remote($url);
    $checked = km_poster_image_check($bytes);
} catch (InvalidArgumentException $exception) {
    km_poster_preview_respond(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('admin/api/poster-preview.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    km_poster_preview_respond(['error' => '画像を取りに行けませんでした。'], 502);
}

km_poster_preview_respond([
    'dataUrl' => 'data:' . $checked['mime'] . ';base64,' . base64_encode($bytes),
    'width' => $checked['width'],
    'height' => $checked['height'],
    'bytes' => strlen($bytes),
]);
