<?php

declare(strict_types=1);

/**
 * Soketi(Pusher protocol互換のWebSocketサーバー)へアクセスするための
 * pusher-php-server クライアントをここに集約する。km_db() と同じパターン。
 *
 * SOKETI_APP_SECRET はこのファイル(サーバー側)でしか読まない。
 * admin/chat.php 等のクライアント側には SOKETI_APP_KEY/APP_ID しか渡さない
 * (Pusher プロトコルの仕様上、キー自体は公開情報。秘密鍵だけが機密)。
 *
 * web コンテナと soketi コンテナは同じ docker ネットワーク上にいるため、
 * 外部公開用の nginx (wss://192.168.3.29:6001) ではなく、内部のサービス名
 * soketi:6001 へ平文 HTTP で直接アクセスする(サーバー間通信なので TLS 不要)。
 */

function km_soketi_client(): \Pusher\Pusher
{
    static $pusher = null;
    if ($pusher !== null) {
        return $pusher;
    }

    $appId = getenv('SOKETI_APP_ID');
    $appKey = getenv('SOKETI_APP_KEY');
    $appSecret = getenv('SOKETI_APP_SECRET');

    if ($appId === false || $appKey === false || $appSecret === false) {
        throw new RuntimeException('SOKETI_APP_ID / SOKETI_APP_KEY / SOKETI_APP_SECRET が設定されていません。');
    }

    $pusher = new \Pusher\Pusher($appKey, $appSecret, $appId, [
        'host' => getenv('SOKETI_HOST') ?: 'soketi',
        'port' => (int) (getenv('SOKETI_PORT') ?: 6001),
        'scheme' => 'http',
        'useTLS' => false,
    ]);

    return $pusher;
}
