<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/chat.php';
require_once dirname(__DIR__, 2) . '/lib/soketi.php';

/**
 * 「ここまで読んだ」を記録する。
 *
 * 記録したあと、他の管理者の画面の「既読」表示がその場で追いつくよう Soketi にも流す。
 * ここでも **DB への保存が先、配信は後**(chat-send.php と同じ考え方)。
 * 配信に失敗しても既読自体は残り、相手が次に開き直したときには正しく見える。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST で送信してください。']);
    exit;
}

// 本文を読む前に弾く(副作用の手前で止める)
if (!km_csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$lastReadId = is_array($input) ? ($input['lastReadId'] ?? null) : null;

if (!is_numeric($lastReadId) || (int) $lastReadId < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'lastReadId は0以上の数値で指定してください。']);
    exit;
}

try {
    $pdo = km_db();
    km_chat_mark_read($pdo, (string) $KM_USER['sub'], (string) $KM_USER['name'], (int) $lastReadId);

    // 未読バッジを画面側でその場で消せるよう、更新後の件数を返す
    $unread = km_chat_unread_count($pdo, (string) $KM_USER['sub']);
} catch (Throwable $exception) {
    error_log('chat-read.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => '既読を記録できませんでした。']);
    exit;
}

/*
 * 配信は「おまけ」なので、失敗しても 200 を返す。
 * ここで 503 にすると、既読は記録できているのに画面側がやり直してしまう。
 */
try {
    km_soketi_client()->trigger('presence-admin-chat', 'read', [
        'userId' => $KM_USER['sub'],
        'userName' => $KM_USER['name'],
        'lastReadId' => (int) $lastReadId,
    ]);
} catch (Throwable $exception) {
    error_log('chat-read.php broadcast failed (read itself was saved): ' . $exception->getMessage());
}

echo json_encode(['success' => true, 'unread' => $unread]);
