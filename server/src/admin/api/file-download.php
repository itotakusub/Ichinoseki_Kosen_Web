<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/uploads.php';

/**
 * アップロードしたファイルの受け渡し。
 *
 * uploads/ は nginx で直接配信を塞いである(default.conf の
 * `location ~ ^/(lib|config|scripts|uploads)/`)ので、実体はここを通してしか出ない。
 * ここは guard.php の内側なので、ログインした管理者だけが取得できる。
 */

$id = (int) ($_GET['id'] ?? 0);

try {
    $file = km_upload_find(km_db(), $id);
    if ($file === null) {
        http_response_code(404);
        exit;
    }

    // 保存名の確かめ・添付として送るヘッダー・ファイル名の掃除は lib/uploads.php の km_upload_send(共有リンクと同じ)
    if (!km_upload_send($file)) {
        http_response_code(404);
        exit;
    }
} catch (Throwable $exception) {
    error_log('file-download.php failed: ' . $exception->getMessage());
    http_response_code(503);
}
