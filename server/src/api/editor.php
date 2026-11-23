<?php

declare(strict_types=1);

/**
 * Web Code Editor のプロジェクトをサーバーに預かる口(段 10-B。2026-11-08。仕組みは lib/editor-projects.php、画面は editor-src/src/project/sync.js)。
 *
 * どれも CSRF(X-KM-CSRF。editor/index.php の meta km-csrf)。誰のものかは**サインインのセッションの sub だけ**で決める
 * (利用者の ID は受け取らない)。他人のもの・無いものは同じ「見つかりません」。
 *
 *   POST action=state   サインインしているか・保存できるか・サーバーのプロジェクトの一覧・使っている量・上限
 *   POST action=get     project → 目録(entry・folders・files {パス: {h, s}})と版
 *   POST action=save    project(空なら新しく)・baseRevision・name・entry・folders(JSON)・manifest(JSON)・zip(足りない中身だけ。名前はハッシュ)
 *                       → 200 {project, changed} / 409 {conflict: true, revision} / 409 {missing: [ハッシュ]}
 *   POST action=rename  project・name
 *   POST action=delete  project
 *   GET  action=blob    project・h → 中身(application/octet-stream・attachment)。数が多いので GET にして nginx の回数(POST だけ)に数えない
 *
 * 止められたアカウント・テスト用アカウント(展示の PC)は使えない(api/html-host.php と同じ守り)。
 * 本文は nginx の km_editor で 21MB まで・書き込みの回数を絞る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/editor-projects.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'POST' && $method !== 'GET') {
    header('Allow: GET, POST');
    respond(['success' => false, 'message' => 'POST required.'], 405);
}

$owner = null;
try {
    require dirname(__DIR__) . '/logto-client.php';
    if ($client->isAuthenticated()) {
        $claims = $client->getIdTokenClaims();
        $owner = trim((string) ($claims->sub ?? '')) ?: null;
    }
} catch (Throwable $exception) {
    error_log('api/editor.php session: ' . $exception->getMessage());
    require_once dirname(__DIR__) . '/lib/session.php';
    km_session_start();
}
if (!km_csrf_verify()) {
    respond(['success' => false, 'message' => 'ページを読み込み直してからやり直してください。'], 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$input = $method === 'GET' ? $_GET : $_POST;
$action = is_string($input['action'] ?? null) ? $input['action'] : '';
if (($method === 'GET') !== ($action === 'blob')) {
    respond(['success' => false, 'message' => 'action が違います。'], 400);
}

function km_editor_api_str(array $input, string $key, int $max = 300): string
{
    $value = $input[$key] ?? '';

    return is_string($value) ? mb_substr($value, 0, $max) : '';
}

/** JSON の欄(大きすぎるもの・壊れたものは 400) */
function km_editor_api_json(array $input, string $key, int $maxBytes): mixed
{
    $raw = $input[$key] ?? '';
    if (!is_string($raw) || strlen($raw) > $maxBytes) {
        throw new InvalidArgumentException('送ったものが大きすぎるか、形が違います。');
    }
    $value = json_decode($raw === '' ? 'null' : $raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('送ったものの形が違います。');
    }

    return $value;
}

if ($owner === null) {
    if ($action === 'state') {
        respond(['success' => true, 'signedIn' => false, 'canSave' => false]);
    }
    respond(['success' => false, 'signedIn' => false, 'message' => 'サインインすると、サーバーにも保存できます。'], 401);
}

try {
    $pdo = km_db();
    km_editor_ensure_tables($pdo);
} catch (Throwable $exception) {
    error_log('api/editor.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'いまサーバーに保存できません(この端末には保存されています)。'], 503);
}

$blocked = null;
try {
    require_once dirname(__DIR__) . '/lib/logto-management.php';
    if (km_logto_user_is_suspended($owner)) {
        $blocked = 'このアカウントは止められています。';
    }
} catch (Throwable $exception) {
    error_log('api/editor.php suspended check failed: ' . $exception->getMessage());
    $blocked = 'いまアカウントを確かめられません。しばらくしてからお試しください。';
}
if ($blocked === null) {
    try {
        require_once dirname(__DIR__) . '/lib/test-mode.php';
        if (km_test_account_is_marked($pdo, $owner)) {
            $blocked = 'テスト用アカウントでは、サーバーに保存できません。';
        }
    } catch (Throwable $exception) {
        error_log('api/editor.php test account check failed: ' . $exception->getMessage());
    }
}
if ($action === 'state') {
    respond([
        'success' => true,
        'signedIn' => true,
        'canSave' => $blocked === null,
        'blocked' => $blocked,
        // 端末に覚えたプロジェクトが、どのアカウントのものかを見分ける印(sub そのものは返さない)
        'account' => substr(hash('sha256', 'km-editor:' . $owner), 0, 16),
        'projects' => $blocked === null ? array_map('km_editor_out', km_editor_projects_of($pdo, $owner)) : [],
        'usedBytes' => $blocked === null ? km_editor_owner_bytes($pdo, $owner) : 0,
        'limits' => [
            'projects' => KM_EDITOR_MAX_PROJECTS,
            'ownerBytes' => KM_EDITOR_MAX_OWNER_BYTES,
            'projectBytes' => KM_EDITOR_MAX_PROJECT_BYTES,
            'files' => KM_EDITOR_MAX_FILES,
            'uploadBytes' => KM_EDITOR_MAX_UPLOAD_BYTES,
        ],
    ]);
}
if ($blocked !== null) {
    respond(['success' => false, 'blocked' => true, 'message' => $blocked], 403);
}

try {
    switch ($action) {
        case 'get':
            $got = km_editor_get($pdo, $owner, km_editor_api_str($input, 'project', 40));
            respond([
                'success' => true,
                'project' => km_editor_out($got['project']),
                'entry' => $got['entry'],
                'folders' => $got['folders'],
                'files' => (object) $got['files'],
            ]);

        case 'blob':
            $path = km_editor_blob_path($pdo, $owner, km_editor_api_str($input, 'project', 40), km_editor_api_str($input, 'h', 80));
            if ($path === null) {
                respond(['success' => false, 'message' => '見つかりません。'], 404);
            }
            // 利用者の書いたものを本体のオリジンで開かせない(ダウンロードの扱い・型は決めつけない・台本を止める)
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="blob"');
            header("Content-Security-Policy: sandbox; default-src 'none'");
            header('Cache-Control: private, max-age=31536000, immutable');
            header('Content-Length: ' . (string) filesize($path));
            readfile($path);
            exit;

        case 'save':
            $file = $_FILES['zip'] ?? null;
            $zipPath = null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                    throw new InvalidArgumentException('ファイルを受け取れませんでした(1 回に ' . intdiv(KM_EDITOR_MAX_UPLOAD_BYTES, 1024 * 1024) . 'MB まで)。');
                }
                $zipPath = (string) $file['tmp_name'];
            }
            $result = km_editor_save(
                $pdo,
                $owner,
                km_editor_api_str($input, 'project', 40),
                (int) km_editor_api_str($input, 'baseRevision', 12),
                km_editor_api_str($input, 'name', 200),
                km_editor_api_str($input, 'entry', 300),
                km_editor_clean_folders(km_editor_api_json($input, 'folders', 64 * 1024)),
                km_editor_clean_manifest(km_editor_api_json($input, 'manifest', 512 * 1024)),
                $zipPath,
            );
            respond(['success' => true, 'project' => km_editor_out($result['project']), 'changed' => $result['changed']]);

        case 'rename':
            $project = km_editor_rename($pdo, $owner, km_editor_api_str($input, 'project', 40), km_editor_api_str($input, 'name', 200));
            respond(['success' => true, 'project' => km_editor_out($project)]);

        case 'delete':
            km_editor_delete($pdo, km_editor_find_own($pdo, $owner, km_editor_api_str($input, 'project', 40)));
            respond(['success' => true]);

        default:
            respond(['success' => false, 'message' => 'action が違います。'], 400);
    }
} catch (KmEditorConflict $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()] + $exception->detail, 409);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/editor.php ' . $action . ' failed: ' . $exception->getMessage());
    // lib が投げる RuntimeException の文は利用者向け。DB の例外(PDOException も RuntimeException)は中身を出さない
    $userFacing = $exception instanceof RuntimeException && !$exception instanceof PDOException;
    respond(['success' => false, 'message' => $userFacing ? $exception->getMessage() : '保存できませんでした。'], 500);
}
