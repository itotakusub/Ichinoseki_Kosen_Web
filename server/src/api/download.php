<?php

declare(strict_types=1);

/**
 * 配布物(Android アプリ)のダウンロード。**誰でも使える**。
 *
 * APK は来場者が取りに来るものなので、ログインの内側に置くと取りに来られない。
 * よってここは公開のまま置く。代わりに次の3点で縛ってある:
 *
 *   1. 出せるのは lib/distributables.php に定義した slug だけ。
 *      利用者が指定できるのは種類であってパスではない
 *   2. 保存名が「こちらが採番した形」であることを、パスを組み立てる直前に確認する
 *   3. 必ず添付として返す(octet-stream + attachment + nosniff)。
 *      ブラウザ内で開かせない
 *
 * 実体は src/uploads/ にあり、そこは nginx の
 * `location ~ ^/(lib|config|scripts|uploads)/ { return 404; }` で直接配信を塞いである。
 *
 * 公開ページなので **fail open ではなく素直に 404**。地図と違って「出せないなら無い」で
 * 困らないため。
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/distributables.php';

/*
 * ?slug[]=x のように配列で渡されると (string) キャストが警告になる。
 * 警告が出力へ混ざるとヘッダーを送れなくなり、この下の 404 が効かなくなる
 * (1.0.0 の確認で実際に 200 が返っていた)。型を先に確かめる。
 */
$slugParam = $_GET['slug'] ?? '';
$slug = is_string($slugParam) ? $slugParam : '';
if (!isset(KM_DISTRIBUTABLES[$slug])) {
    http_response_code(404);
    exit('not found');
}
$spec = KM_DISTRIBUTABLES[$slug];

$row = null;
try {
    $row = km_dist_find(km_db(), $slug);
} catch (Throwable $exception) {
    // DB が落ちていても、静的な既定ファイルがあるならそれで用は足りる
    error_log('api/download.php lookup failed: ' . $exception->getMessage());
}

/*
 * まだ一度も差し替えていない(または DB が読めない)場合は、従来の静的ファイルへ振る。
 * これで「差し替え機能を入れる前と同じものが取れる」状態が保たれる。
 */
if ($row === null) {
    if ($spec['fallback'] === null) {
        http_response_code(404);
        exit('not found');
    }
    header('Location: ' . $spec['fallback'], true, 302);
    exit;
}

$storedName = (string) $row['storedName'];
$allowed = implode('|', $spec['extensions']);
if (preg_match('/^dist_[0-9a-f]{32}\.(' . $allowed . ')$/', $storedName) !== 1) {
    error_log('api/download.php: 保存名の形式が不正です: ' . $storedName);
    http_response_code(404);
    exit('not found');
}

$path = __DIR__ . '/../uploads/' . $storedName;
if (!is_file($path)) {
    error_log('api/download.php: 実体が見つかりません: ' . $path);
    http_response_code(404);
    exit('not found');
}

/*
 * ダウンロード時のファイル名は**種類ごとに決め打ち**にする。利用者がアップロードしたときの
 * 名前をそのまま使うと、そこに入っていた文字がヘッダーへ出ていくことになる。
 * 元の名前は管理画面の表示にだけ使う。
 */
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $spec['downloadName'] . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

readfile($path);
