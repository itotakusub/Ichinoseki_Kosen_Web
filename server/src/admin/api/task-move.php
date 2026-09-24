<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/tasks.php';
require_once dirname(__DIR__, 2) . '/lib/admin-log.php';

/** kanban.php でカードをドラッグしたときに、レーン(status)と並び順を保存する。 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST で送信してください。']);
    exit;
}

// 本文を読む前に弾く
if (!km_csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$status = is_array($input) ? (string) ($input['status'] ?? '') : '';
$ids = is_array($input) && is_array($input['ids'] ?? null) ? $input['ids'] : [];

try {
    km_tasks_move(km_db(), $status, array_map('intval', $ids));
    km_admin_log_record('content', 'task.move', $status);
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('task-move.php failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => '保存に失敗しました。']);
}
