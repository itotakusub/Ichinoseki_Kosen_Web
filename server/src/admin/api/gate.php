<?php

declare(strict_types=1);

/**
 * 管理系ポート(8281 phpMyAdmin / 3002 Logto 管理 / 8025 Mailpit)の入場判定。
 *
 * nginx の `auth_request` がリクエストごとにここを呼ぶ。**返すのは status だけ**で、
 * 本文は返さない(auth_request は本文を捨てる)。
 *
 *   204 … 管理者として通してよい
 *   401 … 通さない。nginx が 9443 のログイン画面へ 302 で送る
 *
 * ## 1.0.2: 毎回やり直すのをやめた
 *
 * 1.0.1 の実装は**リクエストのたびに Logto SDK を丸ごと起動していた**。実測(HAR)では
 * 1リクエストあたりのサーバー処理が **2.36秒**、Logto コンソールを開くと
 * 全体の中央値が **10.5秒**(最大 28.9秒)になっていた。
 *
 * 原因は2つ:
 *   1. 静的アセット1本ごとに認証の重い処理をやり直していた
 *   2. Logto SDK のストレージは PHP セッションで、`session_start()` は
 *      **セッションファイルを排他ロックする**。ブラウザが張る6本の接続が
 *      互いにロック待ちで**直列化**していた(ブラウザ側の待ち行列が中央値 8.1秒)
 *
 * そこで:
 *   - **セッション Cookie が無ければ、Logto に触れる前に 401**(未ログインの相手に
 *     SDK を起動させない。総当たりでこちらが重くならないようにする)
 *   - **通った結果だけ 60秒キャッシュ**する。拒否は覚えない —
 *     覚えるとログイン直後に最大60秒締め出されるため
 *   - **`session_write_close()` でロックを早く手放す**。ここが直列化の解消点
 *
 * **判定の中身は 1.0.1 から変えていない**(ログイン済み + KM_ADMIN_SCOPE、
 * 判定できないときは通さない)。遅さの原因は判定の内容ではなく、その回数だった。
 *
 * ## なぜ guard.php をそのまま使わないのか
 *
 * guard.php は「人が見るページ」向けで、未認証なら `login.php` へ **302** し、
 * 設定不備なら **503 の HTML** を出す。auth_request から見ると 302 も 503 も
 * 「200 でも 401 でもない」= エラー扱いになり、**利用者にはただのエラーに見える**。
 */

/** 通過をどれだけ覚えておくか。長くするほど速いが、ログアウトの反映が遅れる。 */
const KM_GATE_CACHE_SECONDS = 60;

require_once dirname(__DIR__, 2) . '/lib/session.php';

/**
 * 本文を出さずに終わる。auth_request の応答は status しか見られない。
 *
 * `Cache-Control` を自分で出しているのは、**nginx 側でこの応答をキャッシュできるように
 * するため**。PHP のセッションは既定で `no-store` を付けるので、そのままだと
 * nginx は絶対にキャッシュしない(km_session_start(true) でその既定を抑止している)。
 */
function km_gate_finish(int $status, int $maxAge): never
{
    header('Cache-Control: private, max-age=' . $maxAge);
    http_response_code($status);
    exit;
}

/**
 * 拒否を監査ログへ残す。**ブラウザセッションにつき1回だけ**(診断 server-ops#8)。
 *
 * ゲートは静的資材1本ごとに呼ばれるので、素直に書くと Logto Console を1回開くだけで
 * 数十行になる。印をセッションに置いて絞る(guard.php の admin.denied と同じ)。
 * 未ログイン(Cookie 無し)の 401 は記録しない —— 誰でも起こせて、行が意味を持たない。
 *
 * 記録の失敗で判定を変えない(km_admin_log_record は fail soft)。
 *
 * **記録そのものは呼ぶ側で書く**(`km_admin_log_record('auth', 'gate.denied')` のように)。
 * 操作名を変数で渡すと、check.php の「すべての操作に文言がある」の突き合わせから漏れる。
 *
 * @return bool 記録してよいとき true(実行者 $KM_USER もここで置く)
 */
function km_gate_should_record(object $client, string $kind): bool
{
    $logged = $_SESSION['km_gate_denied_logged'] ?? [];
    if (!is_array($logged)) {
        $logged = [];
    }
    if (isset($logged[$kind])) {
        return false;
    }
    try {
        require_once dirname(__DIR__, 2) . '/lib/admin-log.php';
        $claims = $client->getIdTokenClaims();
        $GLOBALS['KM_USER'] = [
            'sub' => (string) ($claims->sub ?? ''),
            'name' => (string) ($claims->name ?? $claims->username ?? $claims->sub ?? ''),
        ];
    } catch (Throwable $exception) {
        error_log('admin/api/gate.php: 拒否を記録できませんでした: ' . $exception->getMessage());

        return false;
    }
    $logged[$kind] = true;
    $_SESSION['km_gate_denied_logged'] = $logged;

    return true;
}

/*
 * ここではまだセッションを開始しない。**開始すると新しい空のセッションが作られ、
 * Set-Cookie が付く**(nginx はそれをキャッシュしないし、無意味な Cookie も配りたくない)。
 */
if (!km_session_cookie_present()) {
    km_gate_finish(401, 5);
}

km_session_start(true);

// 通過を覚えている間は、Logto に一切触らずに返す
if ((int) ($_SESSION['km_gate_ok'] ?? 0) > time()) {
    session_write_close();
    km_gate_finish(204, KM_GATE_CACHE_SECONDS);
}

define('KM_ADMIN', true);
require_once dirname(__DIR__) . '/_inc/bootstrap.php';

try {
    require_once dirname(__DIR__, 2) . '/logto-client.php';

    if (!isset($client) || !$client->isAuthenticated()) {
        session_write_close();
        km_gate_finish(401, 5);
    }

    $accessTokenClaims = $client->getAccessTokenClaims(KM_ADMIN_RESOURCE);
    $scopes = preg_split('/\s+/', (string) $accessTokenClaims->scope, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    /*
     * **書き込みの scope も要る**(2026-09-14、診断 server-authz#2)。
     * この先は phpMyAdmin・Logto Console・Mailpit —— どれも DB や利用者を直接書き換えられる。
     * 管理画面の POST と同じ KM_ADMIN_WRITE_SCOPE を求める(bootstrap.php の説明)。
     */
    if (!in_array(KM_ADMIN_SCOPE, $scopes, true) || !in_array(KM_ADMIN_WRITE_SCOPE, $scopes, true)) {
        if (km_gate_should_record($client, 'scope')) {
            km_admin_log_record('auth', 'gate.denied');
        }
        session_write_close();
        km_gate_finish(401, 5);
    }

    /*
     * **停止されたアカウントを通さない。**
     *
     * ここは 8281 / 3002 / 8025(phpMyAdmin・Logto Console・Mailpit)の入口。
     * 管理画面(guard.php)だけ塞いでも、こちらが開いていれば
     * **停止された人が DB を直接触れてしまう。**
     *
     * 例外は下の catch が拾い、判定できないものは通さない。
     */
    require_once dirname(__DIR__, 2) . '/lib/logto-management.php';
    $subject = (string) ($client->getIdTokenClaims()->sub ?? '');
    if (km_logto_user_is_suspended($subject)) {
        error_log('admin/api/gate.php: suspended account was blocked: ' . $subject);
        if (km_gate_should_record($client, 'suspended')) {
            km_admin_log_record('auth', 'account.suspended_blocked', 'gate');
        }
        // **通過の記憶も捨てる。** 残すと最大60秒のあいだ素通しになる
        unset($_SESSION['km_gate_ok'], $_SESSION['km_admin_logged']);
        session_write_close();
        km_gate_finish(401, 5);
    }
} catch (Throwable $exception) {
    /*
     * 設定不備・Logto へ届かない・トークンが壊れている——いずれも「判定できない」。
     * **通さない**(guard.php の fail closed と同じ姿勢)。
     * 理由はログにだけ残す。auth_request の呼び出し元には status しか伝わらない。
     */
    error_log('admin/api/gate.php: 判定できないため拒否します: ' . $exception::class . ': ' . $exception->getMessage());
    session_write_close();
    km_gate_finish(401, 5);
}

$_SESSION['km_gate_ok'] = time() + KM_GATE_CACHE_SECONDS;
session_write_close();

km_gate_finish(204, KM_GATE_CACHE_SECONDS);
