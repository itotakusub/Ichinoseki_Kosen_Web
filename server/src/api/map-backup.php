<?php

declare(strict_types=1);

/**
 * 地図のノードのクラウドバックアップ(2026-10-06。中身は lib/map-backup.php)。**管理者だけ。**
 *
 *   GET                       一覧(新しい順)
 *   GET    ?id=<数>           1 件の中身(アプリの「ノードのインポート」と同じ JSON)
 *   POST   本文 = JSON        置く(?nodes=<地点の数>&note=<メモ> は任意。地点の数はアプリが数える)
 *   DELETE ?id=<数>           消す
 *
 * Logto のアクセストークンに管理者の権限(LOGTO_ADMIN_PERMISSIONS)が全部あるときだけ。
 * **組織トークン(教職員)からは管理者の権限は生まれない**(logto_guard.php の守り)。
 * 置いた・戻した・消したはタイムラインに残す。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-backup.php';
require_once __DIR__ . '/../lib/admin-log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    respond(['success' => false, 'message' => 'GET / POST / DELETE のどれかで呼んでください。'], 405);
}

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);

/*
 * タイムラインの実行者と、一覧の「作った人」。アクセストークンには利用者名が入らない
 * (username は sub と同じ値になる。2026-10-06 に検証機で内部 ID が出た)ので、Logto から表示名を引く。
 * 引けなければ今までどおりトークンの値。
 */
$actorName = (string) ($principal['username'] ?? '');
try {
    require_once __DIR__ . '/../lib/logto-management.php';
    $actorName = km_logto_user_display_name((string) ($principal['subject'] ?? '')) ?? $actorName;
} catch (Throwable $exception) {
    error_log('api/map-backup.php display name: ' . $exception->getMessage());
}
$GLOBALS['KM_USER'] = [
    'sub' => (string) ($principal['subject'] ?? ''),
    'name' => $actorName,
];

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/map-backup.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($method === 'GET' && $id <= 0) {
    respond(['success' => true, 'keep' => KM_MAP_BACKUP_KEEP, 'backups' => km_map_backup_list($pdo)]);
}

if ($method === 'GET') {
    $row = km_map_backup_find($pdo, $id);
    if ($row === null) {
        respond(['success' => false, 'message' => 'そのバックアップはありません(古いものは自動で消えます)。'], 404);
    }
    try {
        $path = km_map_backup_path($row);
    } catch (RuntimeException) {
        respond(['success' => false, 'message' => 'バックアップを読めません。'], 500);
    }
    if (!is_file($path)) {
        respond(['success' => false, 'message' => 'バックアップのファイルが見つかりません。'], 410);
    }
    km_admin_log_record('content', 'map.backup_restore', '#' . $id);
    // 解凍しながらそのまま流す(メモリに全部を載せない)。端末は SHA-256 で確かめる
    header('X-KM-Sha256: ' . $row['sha256']);
    header('Content-Length: ' . (int) $row['bytes']);
    readgzfile($path);
    exit;
}

if ($method === 'DELETE') {
    if ($id <= 0 || !km_map_backup_delete($pdo, $id)) {
        respond(['success' => false, 'message' => 'そのバックアップはありません。'], 404);
    }
    km_admin_log_record('content', 'map.backup_delete', '#' . $id);
    respond(['success' => true, 'message' => 'バックアップを消しました。']);
}

// POST: 本文をそのまま受ける(km_api_json_body は decode するので使わない)
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > KM_MAP_BACKUP_MAX_BYTES) {
    respond(['success' => false, 'message' => '大きすぎます(16MB まで)。'], 413);
}
$stream = fopen('php://input', 'rb');
$json = $stream === false ? '' : (string) stream_get_contents($stream, KM_MAP_BACKUP_MAX_BYTES + 1);
if ($stream !== false) {
    fclose($stream);
}
$nodeCount = isset($_GET['nodes']) && ctype_digit((string) $_GET['nodes']) ? (int) $_GET['nodes'] : null;
$note = (string) ($_GET['note'] ?? '');

try {
    $created = km_map_backup_create($pdo, $json, (string) ($principal['subject'] ?? ''), $actorName, $nodeCount, $note);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/map-backup.php create failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '保存できませんでした。'], 500);
}

km_admin_log_record('content', 'map.backup_create', '#' . $created['id'] . ($nodeCount !== null ? " / {$nodeCount} 地点" : ''));
respond([
    'success' => true,
    'backup' => $created,
    'message' => 'サーバーにバックアップしました(新しい方から ' . KM_MAP_BACKUP_KEEP . ' 件を残します)。',
]);
