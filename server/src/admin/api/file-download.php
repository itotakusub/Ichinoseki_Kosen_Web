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

    /*
     * **保存名の形を確かめてからパスにする。** 自分で採番した「32桁の16進 + 拡張子」
     * (lib/uploads.php)しか入らないはずだが、DB が汚れていたときにパスをはみ出させない
     * (security-review-2026-09-10 の 19。avatar.php は前から見ていた)。
     */
    if (preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,16}$/', (string) $file['storedName']) !== 1) {
        error_log('file-download.php: 保存名の形式が不正です: ' . $file['storedName']);
        http_response_code(404);
        exit;
    }

    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $file['storedName'];
    if (!is_file($path)) {
        error_log('file-download.php: row exists but file is missing: ' . $file['storedName']);
        http_response_code(404);
        exit;
    }

    /*
     * 中身が何であれ「添付として保存させる」以上のことはしない。
     *
     * 特に SVG は中に <script> を書けるため、ブラウザで直接開かせると保存された XSS に
     * なりうる。Content-Type を推測せず application/octet-stream 固定にし、
     * X-Content-Type-Options: nosniff でブラウザ側の推測も止める。
     */
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, no-store');

    // ファイル名はヘッダーへ入れるので、改行や " を持ち込ませない。
    // **\ も落とす** —— 引用符の中では \" が引用符の逃がしとして読まれ、名前の途中で区切りを作れる(同 20)
    $safeName = str_replace(["\r", "\n", '"', '\\'], '', $file['originalName']);
    header(
        'Content-Disposition: attachment; filename="' . $safeName . '"; '
        . "filename*=UTF-8''" . rawurlencode($file['originalName'])
    );

    readfile($path);
} catch (Throwable $exception) {
    error_log('file-download.php failed: ' . $exception->getMessage());
    http_response_code(503);
}
