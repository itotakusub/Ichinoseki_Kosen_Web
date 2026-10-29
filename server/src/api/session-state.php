<?php

declare(strict_types=1);

/**
 * いまサインインしているか(2026-10-25、利用者の報告「posters.php からブラウザの戻るボタンで地図へ戻ると、
 * ログインが切れている(ログインボタンを押すとすぐ入れる)」)。
 *
 *   GET → {"success":true,"loggedIn":bool}
 *
 * 戻るボタンでは、ブラウザが前の画面をメモリから戻すことがある(bfcache。Safari や最近の Chrome は no-store の画面でも戻す)。
 * その画面がサインインする前に描いたものだと、セッションは生きているのに「未ログイン」と出る。
 * Main/back-refresh.js が、メモリから戻ったときだけここに聞き、画面の印(<meta name="km-logged-in">)と違えば読み直す。
 *
 * **Logto へは問い合わせない**(LogtoClient を作ると、1 時間に 1 度 Logto の設定を取りに行く)。
 * 地図(index.php)と同じく、SDK のセッションに ID トークンがあるか(LogtoClient::isAuthenticated と同じ)だけを見る。
 * 返すのは真偽だけ(名前もトークンも返さない)。Cookie が無い人にはセッションを作らない。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/session.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Logto\Sdk\Storage\StorageKey;

require_method('GET');

if (!km_session_cookie_present()) {
    respond(['success' => true, 'loggedIn' => false]);
}
km_session_start();
$idToken = $_SESSION[StorageKey::idToken->value] ?? null;
session_write_close();

respond(['success' => true, 'loggedIn' => is_string($idToken) && $idToken !== '']);
