<?php

declare(strict_types=1);

/**
 * 検証機の uploads/ に、DB が指しているのに無いファイルの**ダミー**を作る。**検証機(KM_ENV=local)でだけ動く。**
 * 2026-11-07 段 5-2(plan.md)。uploads/ は本番から写さない(ポスターの画像・360° 写真・配る APK など)ので、
 * そのままでは画面が壊れた画像だらけになる。見ればダミーと分かる絵(灰色の地に「DUMMY」)で埋める。
 *
 *   docker compose exec -T -u www-data web php scripts/local-dummy-files.php          無いものを数えるだけ
 *   docker compose exec -T -u www-data web php scripts/local-dummy-files.php --apply  無いものを作る(在るものには触らない)
 *
 * 作るもの:
 *   ポスター    … km_posters.image_stored_name(大きさは image_width・image_height。長い辺 1600 まで)
 *   360° 写真   … km_node_panoramas の uploads/panoramas/<sha256>.<ext>(2:1 の格子。名前は台帳の値のまま)
 * 作らないもの(数えて出すだけ):
 *   色分けの絵(km_settings の map_area:*)… 本番の公開の画像。local-refresh.ps1 -CopyPublicImages で本番から写すか、アプリから送り直す
 */

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/uploads.php';

if (getenv('KM_ENV') !== 'local') {
    fwrite(STDERR, "★ KM_ENV=local ではありません。検証機でだけ流します。\n");
    exit(1);
}
if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "★ GD がありません。\n");
    exit(1);
}

$apply = in_array('--apply', array_slice($argv, 1), true);
$pdo = km_db();
$dir = realpath(km_upload_dir());
if ($dir === false) {
    fwrite(STDERR, "★ uploads/ がありません。\n");
    exit(1);
}

/** 保存名として安全か(パスの部品を含まない) */
function km_dummy_safe_name(string $name): bool
{
    return preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $name) === 1 && !str_starts_with($name, '.');
}

function km_dummy_image(int $w, int $h, string $label, bool $grid): GdImage
{
    $img = imagecreatetruecolor(max(16, $w), max(16, $h));
    $bg = imagecolorallocate($img, 120, 124, 130);
    $fg = imagecolorallocate($img, 245, 245, 245);
    $line = imagecolorallocate($img, 150, 154, 160);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    if ($grid) {
        for ($x = 0; $x < $w; $x += (int) max(16, $w / 24)) {
            imageline($img, $x, 0, $x, $h, $line);
        }
        for ($y = 0; $y < $h; $y += (int) max(16, $h / 12)) {
            imageline($img, 0, $y, $w, $y, $line);
        }
    } else {
        imageline($img, 0, 0, $w, $h, $line);
        imageline($img, 0, $h, $w, 0, $line);
    }
    // 組み込みの字(英数字だけ)。大きい絵でも読めるよう、小さく描いて引き伸ばす
    $text = 'DUMMY ' . $label;
    $small = imagecreatetruecolor(imagefontwidth(5) * strlen($text) + 8, imagefontheight(5) + 8);
    $sbg = imagecolorallocate($small, 120, 124, 130);
    imagefilledrectangle($small, 0, 0, imagesx($small), imagesy($small), $sbg);
    imagestring($small, 5, 4, 4, $text, imagecolorallocate($small, 245, 245, 245));
    $scale = max(1, (int) floor(min($w * 0.6 / imagesx($small), $h * 0.3 / imagesy($small))));
    $tw = imagesx($small) * $scale;
    $th = imagesy($small) * $scale;
    imagecopyresized($img, $small, (int) (($w - $tw) / 2), (int) (($h - $th) / 2), 0, 0, $tw, $th, imagesx($small), imagesy($small));
    imagedestroy($small);
    unset($fg);
    return $img;
}

function km_dummy_save(GdImage $img, string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $ok = match ($ext) {
        'png' => imagepng($img, $path),
        'webp' => imagewebp($img, $path, 80),
        'gif' => imagegif($img, $path),
        default => imagejpeg($img, $path, 80),
    };
    imagedestroy($img);
    if ($ok) {
        @chmod($path, 0644);
    }
    return $ok;
}

$made = 0;
$missing = 0;
$skipped = 0;

// ---------------------------------------------------------------- ポスター
$hasTable = static function (string $table) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
};

if ($hasTable('km_posters')) {
    foreach ($pdo->query("SELECT id, image_stored_name, image_width, image_height FROM km_posters WHERE COALESCE(image_stored_name, '') <> ''") as $row) {
        $name = (string) $row['image_stored_name'];
        if (!km_dummy_safe_name($name)) {
            $skipped++;
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            continue;
        }
        $missing++;
        if (!$apply) {
            continue;
        }
        $w = (int) $row['image_width'] ?: 1200;
        $h = (int) $row['image_height'] ?: 1700;
        $k = min(1.0, 1600 / max($w, $h));
        if (km_dummy_save(km_dummy_image((int) ($w * $k), (int) ($h * $k), sprintf('P%03d', (int) $row['id']), false), $path)) {
            $made++;
        }
    }
}

// ---------------------------------------------------------------- 360° 写真
if ($hasTable('km_node_panoramas')) {
    $pano = $dir . '/panoramas';
    foreach ($pdo->query('SELECT id, sha256, ext FROM km_node_panoramas') as $row) {
        $name = strtolower((string) $row['sha256']) . '.' . strtolower((string) $row['ext']);
        if (preg_match('/\A[0-9a-f]{64}\.(jpg|webp)\z/', $name) !== 1) {
            $skipped++;
            continue;
        }
        if (is_file($pano . '/' . $name)) {
            continue;
        }
        $missing++;
        if (!$apply) {
            continue;
        }
        if (!is_dir($pano)) {
            mkdir($pano, 0755, true);
        }
        if (km_dummy_save(km_dummy_image(2048, 1024, '360 #' . (int) $row['id'], true), $pano . '/' . $name)) {
            $made++;
        }
    }
}

// ---------------------------------------------------------------- 色分けの絵(作らない。数えるだけ)
$areaMissing = 0;
if ($hasTable('km_settings')) {
    foreach ($pdo->query("SELECT value FROM km_settings WHERE name LIKE 'map\\_area:%'") as $row) {
        foreach (preg_split('/[^A-Za-z0-9._-]+/', (string) $row['value']) ?: [] as $part) {
            if (preg_match('/\Aarea_[0-9a-f]+_[0-9a-f]+\.png\z/', $part) === 1 && !is_file($dir . '/' . $part)) {
                $areaMissing++;
            }
        }
    }
}

echo ($apply ? "作りました: {$made}" : "無いもの: {$missing}(--apply で作る)")
    . ($skipped > 0 ? " / 名前の形が変で飛ばした: {$skipped}" : '')
    . "\n";
if ($areaMissing > 0) {
    echo "色分けの絵が {$areaMissing} 枚ありません(作りません)。local-refresh.ps1 -CopyPublicImages で本番の公開の画像を写すか、アプリから送り直す\n";
}
