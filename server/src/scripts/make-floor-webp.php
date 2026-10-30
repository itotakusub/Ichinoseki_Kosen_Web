<?php

declare(strict_types=1);

/**
 * 組み込みの床の画像(Main/Picture の PNG)の横に、可逆の WebP の写しを作る(2026-10-30。lib/floor-webp.php)。
 *
 *   php src/scripts/make-floor-webp.php
 *
 * **手元の PC で走らせる**(本番の PHP には GD が無い)。床の PNG を描き直したら走らせ直す
 * (PNG より古い写しは配らないので、忘れても PNG に戻るだけで壊れはしない)。
 * 写しが PNG より大きくなる画像は作らない(その画像は PNG のまま配る)。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('imagewebp')) {
    fwrite(STDERR, "GD に WebP がありません(php.ini で extension=gd を有効に)\n");
    exit(1);
}

$root = realpath(__DIR__ . '/../Main/Picture');
if ($root === false) {
    fwrite(STDERR, "Main/Picture が見つかりません\n");
    exit(1);
}

$pngs = array_merge(glob($root . '/*.png') ?: [], glob($root . '/bldg/*.png') ?: []);
$made = 0;
$failed = 0;
foreach ($pngs as $png) {
    $target = substr($png, 0, -4) . '.webp';
    $image = @imagecreatefrompng($png);
    if ($image === false) {
        fwrite(STDERR, "読めません: {$png}\n");
        $failed++;
        continue;
    }
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $temp = $target . '.tmp';
    $ok = imagewebp($image, $temp, IMG_WEBP_LOSSLESS);
    imagedestroy($image);
    if (!$ok) {
        @unlink($temp);
        fwrite(STDERR, "作れません: {$png}\n");
        $failed++;
        continue;
    }
    $pngBytes = (int) filesize($png);
    $webpBytes = (int) filesize($temp);
    if ($webpBytes >= $pngBytes) {
        @unlink($temp);
        if (is_file($target)) {
            unlink($target);
        }
        printf("%s: PNG の方が小さいので作らない(%d KB)\n", basename($png), intdiv($pngBytes, 1024));
        continue;
    }
    rename($temp, $target);
    printf("%s: %d KB → %d KB\n", basename($png), intdiv($pngBytes, 1024), intdiv($webpBytes, 1024));
    $made++;
}
printf("作った写し %d 件・失敗 %d 件\n", $made, $failed);
exit($failed === 0 ? 0 : 1);
