<?php

declare(strict_types=1);

/**
 * ストリートビューの写真(2026-10-06。中身は lib/panorama.php)。
 *
 *   GET    ?h=<sha256>                 写真そのもの(**ハッシュを知っている人だけ**。地図の配信で一覧を受け取った人)
 *   GET    ?list=1                     全地点の一覧(管理者だけ)
 *   POST   multipart: node, heading?, photo   1 枚足す(管理者だけ。JPEG / WebP・10MB まで)
 *   DELETE ?id=<数>                     1 枚外す(管理者だけ)
 *
 * 写真は中身のハッシュで呼ぶので、中身は変わらない。**長くキャッシュしてよい**(private・1 年・immutable)。
 * 端末は ETag(= ハッシュ)で取り直しを省き、受け取ったものをハッシュで確かめる。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/panorama.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/panorama.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

// ---- 写真そのもの(認証なし。ハッシュが鍵) ----
if ($method === 'GET' && isset($_GET['h'])) {
    $hash = strtolower((string) $_GET['h']);
    $file = km_panorama_find_file($pdo, $hash);
    if ($file === null) {
        respond(['success' => false, 'message' => '写真が見つかりません。'], 404);
    }
    $etag = '"' . $hash . '"';
    header_remove('Cache-Control');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . ($file['ext'] === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('Content-Length: ' . $file['bytes']);
    readfile($file['path']);
    exit;
}

// ---- ここから先は管理者だけ ----
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/admin-log.php';

if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    respond(['success' => false, 'message' => 'GET / POST / DELETE のどれかで呼んでください。'], 405);
}

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);

// タイムラインの実行者(api/map-backup.php と同じく、Logto から表示名を引く)
$actorName = (string) ($principal['username'] ?? '');
try {
    require_once __DIR__ . '/../lib/logto-management.php';
    $actorName = km_logto_user_display_name((string) ($principal['subject'] ?? '')) ?? $actorName;
} catch (Throwable $exception) {
    error_log('api/panorama.php display name: ' . $exception->getMessage());
}
$GLOBALS['KM_USER'] = ['sub' => (string) ($principal['subject'] ?? ''), 'name' => $actorName];

if ($method === 'GET') {
    respond(['success' => true, 'panoramas' => (object) km_panorama_manifest($pdo, null)]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $removed = $id > 0 ? km_panorama_delete($pdo, $id) : null;
    if ($removed === null) {
        respond(['success' => false, 'message' => 'その写真はありません。'], 404);
    }
    km_admin_log_record('content', 'map.panorama_delete', '#' . $id . ' / ' . $removed['nodeUuid']);
    respond(['success' => true, 'message' => '写真を外しました。アプリには次に地図を取得・更新したときに届きます。']);
}

// POST(multipart)
$upload = $_FILES['photo'] ?? null;
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
    $code = is_array($upload) ? (int) ($upload['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
    respond([
        'success' => false,
        'message' => in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '写真が大きすぎます(10MB まで)。' : '写真を受け取れませんでした。',
    ], 400);
}
if ((int) $upload['size'] > KM_PANORAMA_MAX_BYTES) {
    respond(['success' => false, 'message' => '写真が大きすぎます(10MB まで)。'], 413);
}
$bytes = (string) file_get_contents((string) $upload['tmp_name']);
$nodeUuid = (string) ($_POST['node'] ?? '');
$headingRaw = trim((string) ($_POST['heading'] ?? ''));
$heading = $headingRaw === '' ? null : (is_numeric($headingRaw) ? (float) $headingRaw : NAN);

try {
    $added = km_panorama_add($pdo, $nodeUuid, $bytes, $heading, $actorName);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/panorama.php add failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '保存できませんでした。'], 500);
}

km_admin_log_record('content', 'map.panorama_add', '#' . $added['id'] . ' / ' . $nodeUuid);
respond([
    'success' => true,
    'nodeUuid' => $nodeUuid,
    'panorama' => $added,
    'message' => '写真を足しました。アプリには次に地図を取得・更新したときに届きます。',
]);
