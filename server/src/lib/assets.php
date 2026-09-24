<?php

declare(strict_types=1);

/**
 * 公開ページ(index.php / faq.php)から読む自前の静的ファイルに、更新時刻を付ける。
 *
 * 管理画面側の km_asset()(admin/_inc/bootstrap.php)と同じ目的で、基準にするディレクトリが
 * 違うだけ。あちらは admin/ の下、こちらは src/ の下を見る。
 *
 * これが無いと、**配備しても利用者のブラウザは古いファイルを使い続ける**。
 * フェーズ15で実際に踏んだ(辞書へ足した文言が画面に出ず、サーバー上のファイルには
 * 入っているのにブラウザだけ古い、という状態になった)。
 *
 * **vendor-web/leaflet にも付けるようになった。** 以前は「配置したきり変わらない」ので
 * 対象外にしていたが、nginx が静的資材へ 30日 の Cache-Control を出すようにしたため、
 * 版が無いと差し替えても1か月古いままになる。付けても中身が変わらなければ
 * 値も変わらないので、キャッシュの効きは落ちない。
 */

function km_public_asset(string $relativePath): string
{
    static $versions = [];

    if (!array_key_exists($relativePath, $versions)) {
        $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
        $mtime = is_file($path) ? filemtime($path) : false;
        $versions[$relativePath] = $mtime === false ? null : (string) $mtime;
    }

    return $versions[$relativePath] === null
        ? $relativePath
        : $relativePath . '?v=' . $versions[$relativePath];
}
