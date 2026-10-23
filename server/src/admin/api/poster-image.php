<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/posters.php';

/**
 * ポスターの画像(管理画面の一覧とプレビュー。2026-10-21)。
 *
 * 公開の口(api/poster-image.php)は「いま出しているもの」しか返さないので、止めているポスターや
 * 「表示しない」の間は画像が出ない。管理画面ではいつでも見られるよう、こちらは guard.php の内側で全部返す。
 */

$idParam = $_GET['id'] ?? '';
if (!is_string($idParam) || preg_match('/^[1-9]\d{0,9}$/', $idParam) !== 1) {
    http_response_code(404);
    exit('not found');
}

try {
    $poster = km_poster_find(km_db(), (int) $idParam);
} catch (Throwable $exception) {
    error_log('admin/api/poster-image.php failed: ' . $exception->getMessage());
    $poster = null;
}

if ($poster === null || !km_poster_send_image($poster, false)) {
    http_response_code(404);
    exit('not found');
}
