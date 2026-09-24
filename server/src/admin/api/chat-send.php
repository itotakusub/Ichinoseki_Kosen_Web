<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/chat.php';
require_once dirname(__DIR__, 2) . '/lib/soketi.php';

/**
 * メッセージの送信はクライアントから Soketi へ直接送らせず(client events は使わない)、
 * 必ずここを経由させる。理由:
 *   - 送信者が本当にログイン中の管理者かどうかを guard.php で確認できる
 *   - 空文字・長すぎるメッセージをサーバー側で拒否できる
 *   - SOKETI_APP_SECRET をクライアントに一切渡さずに済む
 */

const KM_CHAT_MAX_LENGTH = 1000;

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
$message = is_array($input) ? trim((string) ($input['message'] ?? '')) : '';

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'メッセージを入力してください。']);
    exit;
}
if (mb_strlen($message) > KM_CHAT_MAX_LENGTH) {
    http_response_code(400);
    echo json_encode(['error' => 'メッセージは' . KM_CHAT_MAX_LENGTH . ' 文字以内にしてください。']);
    exit;
}

try {
    // DB への保存を先に行う。Soketi への配信(trigger)がその後に失敗しても、
    // メッセージ自体は履歴に残り次回読み込み時に見える方を優先する。
    $messageId = km_chat_save_message(km_db(), (string) $KM_USER['sub'], (string) $KM_USER['name'], $message);

    $pusher = km_soketi_client();
    $pusher->trigger('presence-admin-chat', 'message', [
        // id も載せる。受け取った側はこれを「ここまで読んだ」の基準に使う
        'id' => $messageId,
        'senderId' => $KM_USER['sub'],
        'senderName' => $KM_USER['name'],
        'message' => $message,
        'sentAt' => date(DATE_ATOM),
    ]);
    echo json_encode(['success' => true]);
} catch (Throwable $exception) {
    error_log('chat-send.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => '送信に失敗しました。']);
}
