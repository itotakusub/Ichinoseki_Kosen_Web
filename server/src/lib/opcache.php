<?php

declare(strict_types=1);

/**
 * PHP が書き換えた設定ファイル(config/*.local.php)を、OPcache にすぐ読み直させる(2026-10-06、診断の「情報」)。
 *
 * 設定は `require` で読むので OPcache に載る。php:8.4-apache の既定では、ファイルが変わったかを
 * **最大 2 秒(revalidate_freq)見に行かない**。そのため地図の錠やパスワードを管理画面で変えても、
 * 2 秒ほどは古い設定で答えていた(実害は小さいが、「変えたのに効かない」と見える)。
 * 書いた直後に呼べば、次の要求から新しい中身になる(OPcache の共有メモリなので、ほかのプロセスにも効く)。
 */
function km_opcache_forget(string $path): void
{
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
}
