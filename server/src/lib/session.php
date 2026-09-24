<?php

declare(strict_types=1);

/**
 * PHP セッションの開始。**Cookie の属性を1箇所に集める**ための小さな lib。
 *
 * Logto PHP SDK のストレージは PHP セッションで、`LogtoClient` を作った時点で
 * `session_start()` される。属性(secure / httponly / SameSite)は**開始より前**に
 * 決めておく必要があるため、これまで logto-client.php が直前に設定していた。
 *
 * 1.0.2 で admin/api/gate.php が「SDK を作る前にセッションを覗く」ようになり、
 * 開始する場所が2つになった。**同じ属性で開かないと、片方のリクエストが
 * 別の属性で Cookie を出し直してしまう**ので、作法をここへ寄せる。
 */

/**
 * セッション Cookie の名前。**`__Host-` を付ける**(2026-09-15。security-review-2026-09-14 の W-33・§7 の 9)。
 *
 * `__Host-` の付いた Cookie を、ブラウザは「Secure・Path=/・Domain なし」のときだけ受け取る。
 * つまり**同じ名前の Cookie を、サブドメインや平文の http から植え付けられない**(セッション固定の経路が減る)。
 * 属性は下の km_session_start() がすでにその形で出しているので、名前を変えるだけで条件を満たす。
 *
 * **変えた瞬間に全員が一度ログアウトされる**(古い PHPSESSID は読まれなくなる)。
 * docker/php/99-limits.ini の session.name も同じ値にしてある —— 開始より前に名前を読む
 * km_session_cookie_present() と、lib を通らない経路のため。
 */
const KM_SESSION_NAME = '__Host-KMSID';

/**
 * まだ開始していなければ、決めた属性でセッションを開始する。
 *
 * @param bool $suppressCacheHeaders true にすると PHP が既定で付ける
 *        `Cache-Control: no-store` 等を抑止する。**nginx 側で応答をキャッシュしたい
 *        エンドポイント(gate.php)専用**。通常のページで使ってはいけない
 *        — 管理画面の HTML がプロキシに残る可能性が出る。
 */
function km_session_start(bool $suppressCacheHeaders = false): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    /*
     * **サーバーが発行していない ID をセッションとして受けない**(use_strict_mode)。
     * 既定の 0 だと、外から植え付けた ID の上に CSRF トークンやゲートの印が積まれる
     * (診断 critic#3)。ID は Cookie でだけ受け、URL には載せない。
     *
     * docker/php/99-limits.ini にも同じ値を書いてある。**二重にしてあるのは意図的** ——
     * ini のマウントが外れた環境でも、ここで同じ守りになる。
     */
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name(KM_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($suppressCacheHeaders) {
        session_cache_limiter('');
    }

    session_start();
}

/**
 * セッション Cookie がそもそも送られてきているか。
 *
 * 未ログインの相手に対して**重い認証処理を始める前**に切り上げるために使う
 * (Cookie が無ければ、どうやっても「ログイン済み」にはなりえない)。
 */
function km_session_cookie_present(): bool
{
    // 開始前でも同じ名前を見る(session_name() は ini の値を返すので、ini が外れた環境でずれる)
    return isset($_COOKIE[KM_SESSION_NAME]) && $_COOKIE[KM_SESSION_NAME] !== '';
}
