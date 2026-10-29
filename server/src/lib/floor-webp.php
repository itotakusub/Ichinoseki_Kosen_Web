<?php

declare(strict_types=1);

/**
 * 床の画像を WebP にして配る(2026-10-30、振り返りのフェーズ 2 の 5「軽くする」)。
 *
 * 床の画像は線画の PNG(1600×1200)。**可逆の WebP にすると 3〜8 分の 1**になる
 * (手元の実測: 1 階 49KB → 11KB、外 119KB → 40KB)。可逆なので見た目は同じ。
 *
 * - 元の PNG は置き換えない(管理画面・アプリはそのまま PNG を使う)
 * - **組み込みの床(Main/Picture)は、横に置いた写し `<名前>.webp` を使う。** 本番の PHP には GD が無い
 *   (docker/php/Dockerfile は使う拡張だけ入れる)ので、写しは手元で `php src/scripts/make-floor-webp.php` で作って一緒に配る。
 *   PNG より古い写しは使わない(床を描き直したのに写しを作り忘れたとき)
 * - 管理画面で差し替えた床は、GD があればここで**一時置き場に写し**を作る。名前は元のパスと更新時刻から作るので、
 *   床を差し替えれば別の名前になり、古い写しは使われない
 * - 作れないとき(GD に WebP が無い・大きすぎる・壊れた PNG)は null。呼ぶ側は PNG をそのまま配る
 * - 受け取る側が WebP を読めると言ったとき(Accept: image/webp)だけ使う。言わない端末には PNG
 */

/** これより大きい画像は WebP にしない(GD は 1 画素 4 バイトで持つ。16M 画素で 64MB) */
const KM_FLOOR_WEBP_MAX_PIXELS = 16_000_000;
const KM_FLOOR_WEBP_MAX_BYTES = 8 * 1024 * 1024;

/** 受け取る側が WebP を読めるか(Accept に image/webp。q=0 は断り) */
function km_floor_webp_accepted(string $accept): bool
{
    foreach (explode(',', strtolower($accept)) as $part) {
        $fields = array_map('trim', explode(';', $part));
        if ($fields[0] !== 'image/webp') {
            continue;
        }
        foreach (array_slice($fields, 1) as $param) {
            if (preg_match('/^q=0(?:\.0*)?$/', $param) === 1) {
                return false;
            }
        }
        return true;
    }
    return false;
}

/** 横に置いた写し(`１階.png` → `１階.webp`)。在って PNG より新しければそのパス */
function km_floor_webp_sibling(string $pngPath): ?string
{
    if (strtolower(pathinfo($pngPath, PATHINFO_EXTENSION)) !== 'png') {
        return null;
    }
    $sibling = substr($pngPath, 0, -4) . '.webp';
    if (!is_file($sibling) || filesize($sibling) === 0 || !is_file($pngPath)) {
        return null;
    }
    return filemtime($sibling) >= filemtime($pngPath) ? $sibling : null;
}

/** 配る WebP を選ぶ。横の写し → (GD があれば)一時置き場の写し → 無ければ null(PNG のまま) */
function km_floor_webp_for(string $pngPath): ?string
{
    return km_floor_webp_sibling($pngPath) ?? km_floor_webp_file($pngPath);
}

/** 写しの置き場(一時置き場。消えても作り直すだけ) */
function km_floor_webp_cache_dir(): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'km-floor-webp';
}

/**
 * PNG の可逆の WebP の写しのパス。無ければ作る。作れなければ null。
 * 同時に同じ階を頼まれても壊れないよう、別名に書いてから rename する。
 */
function km_floor_webp_file(string $pngPath, ?string $cacheDir = null): ?string
{
    if (!function_exists('imagewebp') || !function_exists('imagecreatefrompng')) {
        return null;
    }
    if (strtolower(pathinfo($pngPath, PATHINFO_EXTENSION)) !== 'png' || !is_file($pngPath)) {
        return null;
    }
    $bytes = filesize($pngPath);
    if ($bytes === false || $bytes > KM_FLOOR_WEBP_MAX_BYTES) {
        return null;
    }
    $dir = $cacheDir ?? km_floor_webp_cache_dir();
    $target = $dir . DIRECTORY_SEPARATOR . hash('sha256', $pngPath . '|' . (string) filemtime($pngPath) . '|' . (string) $bytes) . '.webp';
    if (is_file($target) && filesize($target) > 0) {
        return $target;
    }
    // 前に試して PNG の方が小さかった(毎回作り直さない)
    if (is_file($target . '.png-wins')) {
        return null;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    $size = @getimagesize($pngPath);
    if ($size === false || $size[0] * $size[1] > KM_FLOOR_WEBP_MAX_PIXELS) {
        return null;
    }
    $image = @imagecreatefrompng($pngPath);
    if ($image === false) {
        return null;
    }
    // パレットの PNG は GD の WebP に渡せない。透明は残す(色分けの塗りなど)
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $temp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $ok = @imagewebp($image, $temp, IMG_WEBP_LOSSLESS);
    imagedestroy($image);
    if (!$ok || !is_file($temp) || filesize($temp) === 0) {
        @unlink($temp);
        return null;
    }
    // 元より大きくなるなら使わない(線画ではまず無いが、写真のような床を入れたとき)
    if (filesize($temp) >= $bytes) {
        @unlink($temp);
        @touch($target . '.png-wins');
        return null;
    }
    if (!@rename($temp, $target)) {
        @unlink($temp);
        return is_file($target) ? $target : null;
    }
    return $target;
}
