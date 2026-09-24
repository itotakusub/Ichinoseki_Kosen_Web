<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/profile.php';

/**
 * プロフィール画像の配信。
 *
 * 実体は src/uploads/ にあるが、そこは nginx の
 * `location ~ ^/(lib|config|scripts|uploads)/ { return 404; }` で直接配信を塞いである。
 * 画像を出せる経路はここだけで、guard.php の内側 = ログイン済みの管理者にしか見えない。
 *
 * **Content-Type は保存時に中身から判定した値を使う**(クライアントの自己申告ではない)。
 * 併せて nosniff を付け、ブラウザが中身を推測して別物として扱うのを防ぐ。
 * ファイル管理のダウンロード(api/file-download.php)が octet-stream + attachment で
 * 「開かせない」のに対し、こちらは <img> に出すので inline で返す — その差があるので、
 * 保存側(lib/profile.php)で SVG を弾いてある。
 */

/*
 * 画像を持っていないユーザーは既定の画像へ振る。
 *
 * ここを 404 にすると、呼ぶ側が「この人は画像を持っているか」を先に知っていないと
 * <img> が壊れる。チャットは相手の senderId しか持っていないので、それは無理がある。
 * 常に何かを返す方が呼ぶ側が単純になり、壊れた画像も出ない。
 * 静的ファイルなのでリダイレクト先はブラウザにキャッシュされる。
 */
function km_avatar_fallback(): never
{
    header('Location: ../vendor/adminlte/assets/img/user2-160x160.jpg', true, 302);
    exit;
}

$userId = (string) ($_GET['user'] ?? ($KM_USER['sub'] ?? ''));
if ($userId === '') {
    km_avatar_fallback();
}

try {
    $row = km_profile_find(km_db(), $userId);
} catch (Throwable $exception) {
    // DB が落ちていても画面が壊れないように、既定の画像を出す
    error_log('avatar.php failed (fall back to default): ' . $exception->getMessage());
    km_avatar_fallback();
}

$storedName = $row['avatarStoredName'] ?? null;
if ($storedName === null) {
    km_avatar_fallback();
}

/*
 * 保存名は自分で採番した「avatar_<32桁の16進>.<拡張子>」しか入らないはずだが、
 * ここはファイルパスを組み立てる場所なので、その形であることを必ず確かめてから使う
 * (DB が何らかの理由で汚れていても、パスをはみ出させない)。
 */
if (preg_match('/^avatar_[0-9a-f]{32}\.(png|jpg|gif|webp)$/', $storedName) !== 1) {
    error_log('avatar.php: 保存名の形式が不正です: ' . $storedName);
    km_avatar_fallback();
}

$path = km_upload_dir() . DIRECTORY_SEPARATOR . $storedName;
if (!is_file($path)) {
    // DB には印があるのに実体が無い(消し損ねた等)。気づけるように残す
    error_log('avatar.php: 実体が見つかりません: ' . $path);
    km_avatar_fallback();
}

// **DB の MIME をそのまま流さない。** 上で形を確かめた保存名の拡張子から決める
header('Content-Type: ' . km_profile_avatar_mime($storedName));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
// 自分と他の管理者の顔写真なので共有キャッシュには載せない。差し替えは ?v= で無効化される。
header('Cache-Control: private, max-age=300');

readfile($path);
