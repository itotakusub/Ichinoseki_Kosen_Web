<?php

declare(strict_types=1);

/**
 * AR 実測の記録と画像(2026-10-06。中身は lib/ar-capture.php)。**読み書きはすべて管理者だけ**(画像に人が写りうる)。
 *
 *   GET    ?action=list                         撮影の一覧
 *   GET    ?action=record&session=<uuid>        記録(JSON)。アプリが地図の誤差を直す候補を計算し直すため
 *   POST   multipart: action=put_session, record(gzip の JSON)
 *   POST   multipart: action=put_frame, session, index, meta(JSON), image(JPEG), depth?(16bit PNG)
 *   DELETE ?session=<uuid>                      撮影をまるごと消す(画像のファイルも)
 *
 * PC への zip は管理画面(admin/ar-captures.php)から落とす。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ar-capture.php';
require_once __DIR__ . '/../lib/admin-log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    respond(['success' => false, 'message' => 'GET / POST / DELETE のどれかで呼んでください。'], 405);
}

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);
$actor = (string) ($principal['subject'] ?? '') ?: null;
$GLOBALS['KM_USER'] = ['sub' => (string) ($principal['subject'] ?? ''), 'name' => (string) ($principal['username'] ?? '')];

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/ar-capture.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

/** multipart の 1 つのファイルを読む。無ければ null。 */
$readUpload = static function (string $field, int $max): ?string {
    $upload = $_FILES[$field] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($upload['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
        respond(['success' => false, 'message' => $field . ' を受け取れませんでした。'], 400);
    }
    if ((int) $upload['size'] > $max) {
        respond(['success' => false, 'message' => $field . ' が大きすぎます。'], 413);
    }
    return (string) file_get_contents((string) $upload['tmp_name']);
};

try {
    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? 'list');
        if ($action === 'list') {
            respond(['success' => true, 'sessions' => km_ar_list_sessions($pdo), 'totalBytes' => km_ar_total_bytes($pdo), 'maxBytes' => KM_AR_TOTAL_MAX_BYTES]);
        }
        if ($action === 'record') {
            $json = km_ar_session_record($pdo, (string) ($_GET['session'] ?? ''));
            if ($json === null) {
                respond(['success' => false, 'message' => 'その撮影はありません。'], 404);
            }
            header('Content-Type: application/json; charset=utf-8');
            echo '{"success":true,"record":' . $json . '}';
            exit;
        }
        respond(['success' => false, 'message' => 'action が違います。'], 400);
    }

    if ($method === 'DELETE') {
        $session = (string) ($_GET['session'] ?? '');
        $count = km_ar_delete_session($pdo, $session);
        if ($count === null) {
            respond(['success' => false, 'message' => 'その撮影はありません。'], 404);
        }
        km_admin_log_record('content', 'ar.capture_delete', strtolower($session) . ' / ' . $count);
        respond(['success' => true, 'deleted' => $count, 'message' => "撮影を消しました(画像 {$count} 枚)。"]);
    }

    // POST(multipart)
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'put_session') {
        $gz = $readUpload('record', KM_AR_RECORD_MAX_GZ_BYTES);
        if ($gz === null) {
            respond(['success' => false, 'message' => 'record が必要です。'], 400);
        }
        $stored = km_ar_put_session($pdo, $gz, $actor);
        km_admin_log_record('content', 'ar.capture_upload', $stored['uuid'] . ' / ' . $stored['floor']);
        respond(['success' => true, 'session' => $stored]);
    }
    if ($action === 'put_frame') {
        $image = $readUpload('image', KM_AR_IMAGE_MAX_BYTES);
        if ($image === null) {
            respond(['success' => false, 'message' => 'image が必要です。'], 400);
        }
        $depth = $readUpload('depth', KM_AR_DEPTH_MAX_BYTES);
        $meta = json_decode((string) ($_POST['meta'] ?? ''), true);
        $index = filter_var($_POST['index'] ?? null, FILTER_VALIDATE_INT);
        if (!is_array($meta) || $index === false) {
            respond(['success' => false, 'message' => 'meta と index が必要です。'], 400);
        }
        // 1 枚ずつは記録しない(多い)。撮影の記録を送ったときに 1 行残る
        respond(['success' => true, 'frame' => km_ar_put_frame($pdo, (string) ($_POST['session'] ?? ''), $index, $meta, $image, $depth)]);
    }
    respond(['success' => false, 'message' => 'action が違います。'], 400);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/ar-capture.php failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '処理できませんでした。'], 500);
}
