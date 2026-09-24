<?php

declare(strict_types=1);

define('KM_ADMIN', true);
/*
 * **購読の認可は状態を変えない。** POST で来るが(pusher-js の作法)、書き込みの権限が無い
 * 管理者でも死活監視やチャットを「見る」ことはできるべきなので、guard.php の
 * 「POST には admin:users:write が要る」を外す。送信(chat-send.php)は外さない。
 */
define('KM_ADMIN_POST_WITHOUT_WRITE', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/soketi.php';

/**
 * pusher-js がチャンネルを購読する際に自動 POST してくるチャンネル認可。
 * ログイン中の管理者だけがここへ到達できる(guard.php でガード済み)。
 * SOKETI_APP_SECRET はここ(サーバー側)でしか使わない。
 *
 * チャットだけでなく死活監視(フェーズ16)もここを使う。認可の判断は1箇所に集めたいので
 * ファイルは分けず、**購読してよいチャンネルを列挙する**形にした。
 * ここに無い名前は購読できない。
 */

const KM_REALTIME_CHANNELS = [
    'presence-admin-chat',      // 管理者どうしのチャット
    'presence-admin-monitor',   // 死活監視の結果を配る
];

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST で送信してください。']);
    exit;
}

/*
 * CSRF(1.0.0 のセキュリティ確認で追加)。
 *
 * ここが返すのはチャンネル購読の署名で、他所のページから利用者のブラウザに POST させて
 * 手に入れられると、その利用者になりすまして presence チャンネルへ入れてしまう。
 * 応答自体は CORS が無いので他オリジンからは読めないが、それに頼らず塞いでおく。
 *
 * pusher-js はこの POST を自分で投げるため、購読側(chat.php / monitor.php)で
 * auth.headers に X-KM-CSRF を載せている。
 */
if (!km_csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。']);
    exit;
}

$channelName = (string) ($_POST['channel_name'] ?? '');
$socketId = (string) ($_POST['socket_id'] ?? '');

if (!in_array($channelName, KM_REALTIME_CHANNELS, true) || $socketId === '') {
    http_response_code(400);
    echo json_encode(['error' => '不正なリクエストです。']);
    exit;
}

try {
    $pusher = km_soketi_client();
    $auth = $pusher->authorizePresenceChannel(
        $channelName,
        $socketId,
        (string) $KM_USER['sub'],
        ['name' => $KM_USER['name']]
    );
    echo $auth;
} catch (Throwable $exception) {
    error_log('chat-auth.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'リアルタイム接続を認可できません。']);
}
