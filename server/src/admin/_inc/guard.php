<?php

/**
 * 管理画面のアクセス制御。
 *
 * 認証が要るページの先頭で require してください:
 *
 *     define('KM_ADMIN', true);
 *     require __DIR__ . '/_inc/guard.php';
 *
 * 判定は3通りで、通過した場合だけ後続が実行されます。
 *   - 未ログイン        → login.php
 *   - 管理権限なし      → 403.php
 *   - 設定不備・判定不能 → 503 で停止 (fail closed)
 */

declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

// logto-client.php が $client と $appUrl を定義する。
// vendor 欠落や環境変数未設定はここで例外になるので、通さずに止める。
try {
    require_once dirname(__DIR__, 2) . '/logto-client.php';
} catch (Throwable $exception) {
    km_fail_closed($exception);
}

if (!isset($client)) {
    km_fail_closed(new RuntimeException('logto-client.php が $client を定義しませんでした。'));
}

try {
    $isAuthenticated = $client->isAuthenticated();
} catch (Throwable $exception) {
    km_fail_closed($exception);
}

if (!$isAuthenticated) {
    km_redirect('./login.php');
}

try {
    $idTokenClaims = $client->getIdTokenClaims();
} catch (Throwable $exception) {
    // ID トークンが壊れている。判定できないので通さない。
    km_fail_closed($exception);
}

// API リソース向けアクセストークンの scope が権限の正本。
// role が無いユーザーはトークン自体を取得できないことがあるため、
// 失敗は「権限なし」として扱う(ここで止めると 403 を出せない)。
$grantedScopes = [];
try {
    $accessTokenClaims = $client->getAccessTokenClaims(KM_ADMIN_RESOURCE);
    $grantedScopes = preg_split('/\s+/', $accessTokenClaims->scope, -1, PREG_SPLIT_NO_EMPTY) ?: [];
} catch (Throwable $exception) {
    error_log(
        'KosenMap admin could not get an access token for ' . KM_ADMIN_RESOURCE
        . ' (treating as no permission): ' . $exception::class . ': ' . $exception->getMessage()
    );
}

/*
 * **停止されたアカウントを追い出す。**
 *
 * Logto でアカウントを停止しても、発行済みのトークンは期限まで有効で、
 * こちらのセッションも生き続ける。実際、停止済みの管理者アカウントで
 * ダッシュボードを操作できる状態になっていた(2026-08-28)。
 * ロール(scope)の判定より**前**に置く —— 停止された人には
 * 「権限が無い」ではなく「停止されている」を伝えるべきだから。
 *
 * 判定できないときは通さない(km_logto_user_is_suspended は例外を投げる)。
 */
require_once dirname(__DIR__, 2) . '/lib/logto-management.php';
/*
 * 監査ログ。下の拒否(停止・権限なし・書き込み権限なし)も記録するので、ここで読む
 * (以前は error_log だけで、docker logs を読みに行かない限り気付けなかった。診断 server-ops#8)。
 */
require_once dirname(__DIR__, 2) . '/lib/admin-log.php';

try {
    $suspended = km_logto_user_is_suspended((string) $idTokenClaims->sub);
} catch (Throwable $exception) {
    km_fail_closed($exception);
}

if ($suspended) {
    /*
     * **セッションを捨ててから返す。** 残したままだと、この判定を毎回踏みながら
     * 「ログイン済み」の状態が続き、ゲート(gate.php)のキャッシュも生き残る。
     */
    error_log('KosenMap admin: suspended account was blocked: ' . $idTokenClaims->sub);
    // 誰が弾かれたかを残す。$KM_USER はまだ組み立てていないので、記録に要る分だけ置く
    $KM_USER = [
        'sub' => (string) $idTokenClaims->sub,
        'name' => (string) ($idTokenClaims->name ?? $idTokenClaims->username ?? $idTokenClaims->sub),
    ];
    km_admin_log_record('auth', 'account.suspended_blocked', 'admin');
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    km_redirect('./suspended.php');
}

/** @var array $KM_USER ヘッダーの表示に使う。パスワードやトークンは入れない。 */
$KM_USER = [
    'sub' => $idTokenClaims->sub,
    'name' => $idTokenClaims->name ?? $idTokenClaims->username ?? $idTokenClaims->email ?? $idTokenClaims->sub,
    'email' => $idTokenClaims->email ?? '',
    'picture' => $idTokenClaims->picture ?? null,
    'roles' => $idTokenClaims->roles ?? [],
    'scopes' => $grantedScopes,
];

if (!in_array(KM_ADMIN_SCOPE, $grantedScopes, true)) {
    /*
     * **ブラウザセッションにつき1回だけ記録する。** 403 のページから戻る・再読み込みする
     * たびに行が増えると、本当に見たい記録が埋もれる(下のログイン記録と同じ絞り方)。
     */
    if (($_SESSION['km_admin_denied_logged'] ?? false) !== true) {
        $_SESSION['km_admin_denied_logged'] = true;
        km_admin_log_record('auth', 'admin.denied');
    }
    km_redirect('./403.php');
}

/*
 * **状態を変えるリクエストには書き込みの scope も要る**(KM_ADMIN_WRITE_SCOPE の説明)。
 *
 * 各ページの POST に1つずつ書くと、足し忘れたページだけ素通しになる。
 * 全ページが通るここで、GET / HEAD 以外をまとめて見る。
 * 状態を変えない POST(Soketi の購読の認可)だけ、ページ側が
 * KM_ADMIN_POST_WITHOUT_WRITE を定義して外す。
 */
$KM_ADMIN_CAN_WRITE = in_array(KM_ADMIN_WRITE_SCOPE, $grantedScopes, true);
$kmRequestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (
    !$KM_ADMIN_CAN_WRITE
    && !in_array($kmRequestMethod, ['GET', 'HEAD'], true)
    && !defined('KM_ADMIN_POST_WITHOUT_WRITE')
) {
    km_admin_log_record('auth', 'admin.write_denied', basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    km_admin_forbid_write();
}

/*
 * **管理画面を通ったことを記録する。**
 *
 * ここまで来た = Logto で認証され、停止されておらず、管理スコープを持っている。
 * admin/map-editor.php と、そこが読む api/floor-image.php はこの印を見て、
 * 地図の錠(map-access.local.php の mapMode)を素通りする。
 *
 * **利用者の解除印(`km_map_unlocked`)とは別にする。**
 * 以前は同じ印を立てていたため、管理者は**公開ページでも錠の内側**にいた ——
 * 「パスワードが必要」に設定した本人が公開ページで地図を見られてしまい、
 * 設定が効いていないようにしか見えなかった。印を分ければ、公開ページは
 * 管理者に対しても設定どおりに閉じる(理由は lib/map-access.php に書いてある)。
 */
$_SESSION['km_map_admin'] = true;

/*
 * 監査ログへログインを1件残す。
 *
 * このファイルは管理画面の全ページで走るため、素直に書くとページを開くたびに記録されて
 * ログが埋まる。セッションに印を付けて「ブラウザセッションにつき1回」に絞る
 * (= 実質ログインしたタイミング)。
 *
 * km_admin_log_record() 自体が fail soft(失敗してもサーバーログへ落として戻るだけ)なので、
 * 監査ログの不調で管理画面に入れなくなることはない。
 * (lib/admin-log.php は上の停止判定の手前で読んである)
 */
if (($_SESSION['km_admin_logged'] ?? false) !== true) {
    $_SESSION['km_admin_logged'] = true;
    km_admin_log_record('auth', 'login');
}
