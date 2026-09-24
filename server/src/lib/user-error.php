<?php

declare(strict_types=1);

/**
 * 利用者に見せてよい例外と、見せてはいけない例外を分ける。
 *
 * ## なぜ要るのか
 *
 * これまで contact.php や管理画面の多くが `$errors[] = $exception->getMessage()` で
 * **例外の文面をそのまま画面に出していた**(2026-09-14 の診断 server-ops#6)。
 * PDOException なら SQLSTATE と表の名前、lib/db.php の RuntimeException なら
 * CA のパスと compose の構成が、公開の問い合わせフォームにまで出る。
 *
 * ## 決まり
 *
 * - **利用者向けに書いた文だけ KmUserError で投げる。** その文は画面に出してよい
 * - それ以外は「処理できませんでした」+ **照合用の短い ID** だけを出し、詳細は error_log へ。
 *   利用者から ID を聞けば、サーバーのログで同じ行を引ける(callback.php の参照番号と同じ考え)
 *
 * admin/_inc/bootstrap.php の km_fail_closed() が「原因はログにだけ」としているのと同じ姿勢を、
 * 画面の中のエラー表示にも広げたもの。
 */

/** 利用者に見せてよい文を持つ例外。**文面は画面に出る前提で書くこと。** */
final class KmUserError extends RuntimeException
{
}

/**
 * 画面に出す文を決める。
 *
 * KmUserError ならその文。それ以外は $fallback に照合用 ID を付けて返し、
 * 例外の種類・文面・発生箇所は error_log にだけ残す。
 */
function km_user_error_message(Throwable $e, string $fallback): string
{
    if ($e instanceof KmUserError) {
        return $e->getMessage();
    }

    // 推測されても困らない値でよい(秘密ではなく、ログの行を探す手がかり)
    $id = bin2hex(random_bytes(4));
    error_log(sprintf(
        'KosenMap error [%s]: %s: %s at %s:%d',
        $id,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    return rtrim($fallback) . '(照合用 ID: ' . $id . ')';
}

/**
 * 管理画面用。**入力の検証で断った文(InvalidArgumentException)も見せる。**
 *
 * lib の検証(テーブル名の形・日付の形・画像の種類など)は InvalidArgumentException で
 * 断る作りで、check.php もその型で受けている。文面はどれも利用者向けに書いてあり、
 * これを隠すと「なぜ保存できないのか」が管理者にも分からなくなる。
 * DB や設定の失敗(PDOException・RuntimeException)は、管理画面でも ID だけにする。
 */
function km_admin_error_message(Throwable $e, string $fallback): string
{
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }

    return km_user_error_message($e, $fallback);
}
