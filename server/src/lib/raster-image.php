<?php

declare(strict_types=1);

/**
 * 画像の中身を確かめる(2026-10-21。ポスター lib/posters.php と階の画像 lib/map-floors.php が使う)。
 *
 * **拡張子や Content-Type は自己申告**なので、getimagesize の答えだけを信じる。
 * **SVG は入れない** —— <img> や地図の上に描くので、中の script が動きうる(lib/profile.php と同じ判断)。
 * 縦横にも上限を置く: 小さなファイルでも 20000px 四方は作れ、表示する端末が展開した瞬間にメモリが尽きる。
 */

require_once __DIR__ . '/uploads.php';   // km_upload_format_size()

/** 受ける種類 → [保存する拡張子, Content-Type] */
const KM_RASTER_IMAGE_TYPES = [
    IMAGETYPE_PNG => ['png', 'image/png'],
    IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
    IMAGETYPE_GIF => ['gif', 'image/gif'],
    IMAGETYPE_WEBP => ['webp', 'image/webp'],
];

/**
 * @return array{ext:string, mime:string, width:int, height:int}
 */
function km_raster_image_check(string $bytes, int $maxBytes, int $maxDimension): array
{
    $size = strlen($bytes);
    if ($size === 0) {
        throw new InvalidArgumentException('画像が空です。');
    }
    if ($size > $maxBytes) {
        throw new InvalidArgumentException('画像は ' . km_upload_format_size($maxBytes) . ' 以内にしてください。');
    }
    $info = @getimagesizefromstring($bytes);
    $type = is_array($info) ? ($info[2] ?? null) : null;
    if ($type === null || !isset(KM_RASTER_IMAGE_TYPES[$type])) {
        throw new InvalidArgumentException('PNG / JPEG / GIF / WebP の画像にしてください(PDF や SVG は使えません)。');
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
        throw new InvalidArgumentException('画像の大きさを読み取れませんでした。');
    }
    if ($width > $maxDimension || $height > $maxDimension) {
        throw new InvalidArgumentException(
            '画像は縦横それぞれ ' . $maxDimension . 'px 以内にしてください'
            . '(いまは ' . $width . '×' . $height . 'px)。縮めてから選び直してください。'
        );
    }
    [$ext, $mime] = KM_RASTER_IMAGE_TYPES[$type];

    return ['ext' => $ext, 'mime' => $mime, 'width' => $width, 'height' => $height];
}

/** 保存名の拡張子から Content-Type(保存のときに中身を見て決めた拡張子)。知らなければ null */
function km_raster_image_mime(string $storedName): ?string
{
    $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    foreach (KM_RASTER_IMAGE_TYPES as [$allowed, $mime]) {
        if ($ext === $allowed) {
            return $mime;
        }
    }

    return null;
}
