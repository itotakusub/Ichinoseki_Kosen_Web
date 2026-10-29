<?php

declare(strict_types=1);

/**
 * 画像の縮小版(2026-10-30、振り返りのフェーズ 2 の 5「ポスターは縮小画像を使う」)。
 *
 * ポスターの一覧・検索の候補の小さなアイコンにも、元の画像(数 MB のこともある)をそのまま配っていた。
 * 幅を決めた WebP の縮小版を**一時置き場**に作って配る。
 *
 * - 幅は決まった値だけ(KM_IMAGE_THUMB_WIDTHS)。好きな幅を受けると、幅を変えながら叩くだけで縮小を何度も走らせられる
 * - 作れないとき(GD に WebP が無い・大きすぎる・元が十分小さい)は null。呼ぶ側は元の画像を配る。
 *   **本番の PHP には今 GD が無い**(docker/php/Dockerfile)ので、入れるまでは元の画像のまま
 * - 名前は元のパス・更新時刻・幅から作る。差し替えれば別の名前になる
 */

require_once __DIR__ . '/floor-webp.php';   // km_floor_webp_accepted

const KM_IMAGE_THUMB_WIDTHS = [640];
const KM_IMAGE_THUMB_MAX_PIXELS = 40_000_000;
const KM_IMAGE_THUMB_QUALITY = 80;

/** ?w= の値。決まった幅でなければ 0(縮小しない) */
function km_image_thumb_width(mixed $value): int
{
    if (!is_string($value) || preg_match('/^[1-9]\d{1,4}$/', $value) !== 1) {
        return 0;
    }
    $width = (int) $value;
    return in_array($width, KM_IMAGE_THUMB_WIDTHS, true) ? $width : 0;
}

function km_image_thumb_cache_dir(): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'km-image-thumb';
}

/** 縮小版(WebP)のパス。無ければ作る。作れない・作る意味が無ければ null */
function km_image_thumb_file(string $path, int $width, ?string $cacheDir = null): ?string
{
    if ($width <= 0 || !function_exists('imagewebp') || !is_file($path)) {
        return null;
    }
    $size = @getimagesize($path);
    if ($size === false || $size[0] <= 0 || $size[1] <= 0 || $size[0] * $size[1] > KM_IMAGE_THUMB_MAX_PIXELS) {
        return null;
    }
    // 元が十分小さければ縮めない(元をそのまま配る)
    if ($size[0] <= $width) {
        return null;
    }
    $dir = $cacheDir ?? km_image_thumb_cache_dir();
    $target = $dir . DIRECTORY_SEPARATOR . hash('sha256', $path . '|' . (string) filemtime($path) . '|' . (string) filesize($path) . '|' . $width) . '.webp';
    if (is_file($target) && filesize($target) > 0) {
        return $target;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    $source = match ($size[2]) {
        IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
        IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
        IMAGETYPE_GIF => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
    if ($source === false) {
        return null;
    }
    $height = max(1, (int) round($size[1] * $width / $size[0]));
    $thumb = imagecreatetruecolor($width, $height);
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);
    imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
    imagedestroy($source);
    $temp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $ok = @imagewebp($thumb, $temp, KM_IMAGE_THUMB_QUALITY);
    imagedestroy($thumb);
    if (!$ok || !is_file($temp) || filesize($temp) === 0) {
        @unlink($temp);
        return null;
    }
    if (!@rename($temp, $target)) {
        @unlink($temp);
        return is_file($target) ? $target : null;
    }
    return $target;
}
