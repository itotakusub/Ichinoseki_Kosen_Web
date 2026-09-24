<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/chat.php';

/**
 * 直近のチャット履歴を返す。ページ読み込み時に Pusher 購読を始める前に呼ぶ。
 *
 * 「誰がどこまで読んだか」も一緒に返す。画面の「既読 N」はこれを使って組み立てる
 * (メッセージごとに問い合わせない)。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    $pdo = km_db();
    echo json_encode([
        'messages' => km_chat_recent_messages($pdo, 100),
        'reads' => km_chat_read_receipts($pdo),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('chat-history.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => '履歴を取得できません。']);
}
