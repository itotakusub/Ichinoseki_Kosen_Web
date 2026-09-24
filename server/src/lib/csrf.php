<?php

declare(strict_types=1);

/**
 * CSRF 対策のトークン。
 *
 * ## なぜ lib に在るのか
 *
 * もとは admin/_inc/bootstrap.php にあった。あちらは先頭で `KM_ADMIN` を確かめて
 * 定義されていなければ 404 で止まるので、**管理画面の外からは読めない**。
 *
 * account.php(サインインした利用者が自分のパスワードを変える公開ページ)が
 * 同じ守りを要るようになったため、判定の実体をここへ出した。
 * bootstrap.php はこのファイルを読むだけになっている —— **同じ検証を2つ書かない。**
 *
 * ## どこに要るか
 *
 * セッションで「誰か」が決まっていて、POST が取り返しのつかない操作をする画面。
 * Cookie は SameSite=Lax だが、Lax は**他サイトからのトップレベル POST**を
 * 完全には防がない。
 *
 * 逆に api/map-unlock.php や contact.php は対象外。未ログインの利用者が使うもので、
 * 守るべきセッション権限が無く(むしろトークンを要求すると解除やフォームが壊れる)、
 * 総当たりには IP のレート制限で対処している。
 *
 * ## 使う前に
 *
 * セッションが開始済みであること。lib/session.php の km_session_start() か、
 * それを通る logto-client.php を先に読むこと。
 */

/** このセッションのトークン。無ければ作る。 */
function km_csrf_token(): string
{
    if (!isset($_SESSION['km_csrf']) || !is_string($_SESSION['km_csrf']) || $_SESSION['km_csrf'] === '') {
        $_SESSION['km_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['km_csrf'];
}

/**
 * フォームに差し込む hidden 要素。
 *
 * エスケープは htmlspecialchars を直に呼ぶ。km_e() は管理画面側の関数で、
 * ここが**それに依存すると公開ページから使えなくなる**(元の場所へ戻ってしまう)。
 */
function km_csrf_field(): string
{
    $token = htmlspecialchars(km_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<input type="hidden" name="km_csrf" value="' . $token . '" />';
}

/**
 * POST の正当性を確認する。フォームの hidden と、fetch 用の X-KM-CSRF ヘッダーの両方を見る。
 *
 * hash_equals() を使うのは、== の早期リターンで一致文字数が漏れるのを避けるため。
 */
function km_csrf_verify(): bool
{
    $sent = (string) ($_POST['km_csrf'] ?? $_SERVER['HTTP_X_KM_CSRF'] ?? '');
    if ($sent === '') {
        return false;
    }

    $expected = $_SESSION['km_csrf'] ?? null;

    return is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
}
