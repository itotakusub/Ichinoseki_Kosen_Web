<?php

declare(strict_types=1);

/**
 * 別のサイトに埋め込む地図(段 15。2026-11-14。仕組みは lib/map-embed.php)。
 *
 *   https://<ドメイン>/embed/?site=<札>&floor=<階>&focus=<地点>&lang=ja|en
 *
 * 埋め込む側は <iframe src="…"> を貼るか、/embed/v1.js の KosenMap.embed() を使う。
 *
 * - **セッションを開かない**(Cookie を作らない・読まない)。出すのはサインインしていない来場者と同じ地図(km_embed_load_public)
 * - 枠に入れてよい親は、札に登録したオリジンだけ(frame-ancestors)。知らない札・止めた札は 'none' で、何も出さない
 * - 地図を「パスワードが必要」にしているときは「地図は非公開です」とだけ出す
 * - 描くのは Main/embed.js(Leaflet・Main/map-style.js を使う)。公開ページの Main/app.js は読まない
 * - 表示の回数を札ごと・日ごとに数える(管理画面「地図の埋め込み」)
 */

require_once dirname(__DIR__) . '/lib/site.php';
require_once dirname(__DIR__) . '/lib/assets.php';
require_once dirname(__DIR__) . '/lib/csp.php';
require_once dirname(__DIR__) . '/lib/map-embed.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$query = km_embed_parse_query($_GET);
$site = null;
$data = null;
$displayDefaults = null;
$problem = null;
try {
    $pdo = km_db();
    $site = km_embed_site_by_key($pdo, $query['site']);
    if ($site === null || !$site['enabled']) {
        $problem = 'site';
    } else {
        $data = km_embed_load_public($pdo);
        if ($data['locked']) {
            $problem = 'locked';
        } else {
            // 管理アプリで配った表示調整の既定(公開ページと同じ。Main/map-style.js が読む)
            try {
                require_once dirname(__DIR__) . '/lib/display-defaults.php';
                $stored = km_display_defaults_stored($pdo);
                $web = $stored === null ? [] : km_display_defaults_for_web($stored);
                $displayDefaults = $web === [] ? null : $web;
            } catch (Throwable $exception) {
                error_log('embed/index.php: display defaults: ' . $exception->getMessage());
            }
            if ($method === 'GET') {
                km_embed_count($pdo, $site['id']);
            }
        }
    }
} catch (Throwable $exception) {
    error_log('embed/index.php: ' . $exception::class . ': ' . $exception->getMessage());
    $problem = 'error';
}

$frameAncestors = km_embed_frame_ancestors($problem === 'site' ? null : $site);
$csp = preg_replace("/frame-ancestors 'self'/", 'frame-ancestors ' . $frameAncestors, km_csp_policy('public'), 1);
header((km_csp_report_only() ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') . ': ' . $csp);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex');
http_response_code(match ($problem) {
    'site' => 404,
    'error' => 503,
    default => 200,
});

$ja = $query['lang'] === 'ja';
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$asset = static fn (string $path): string => $e('/' . km_public_asset($path));
$json = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$home = km_site_origin() . '/';
$messages = [
    'site' => $ja ? 'この埋め込みは使えません(登録されていないか、止めてあります)。' : 'This embed is not available.',
    'locked' => $ja ? '地図はいま非公開です。' : 'The map is not public right now.',
    'error' => $ja ? '地図を読み込めませんでした。しばらくしてから開き直してください。' : 'The map could not be loaded. Please try again later.',
];
?>
<!DOCTYPE html>
<html lang="<?= $ja ? 'ja' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $ja ? '高専マップ' : 'KosenMap' ?></title>
<?php if ($problem === null): ?>
<link rel="stylesheet" href="<?= $asset('vendor-web/leaflet/leaflet.css') ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= $asset('Main/embed.css') ?>">
</head>
<body class="km-embed">
<?php if ($problem !== null): ?>
<main class="km-embed-message"><p><?= $e($messages[$problem]) ?></p><p><a href="<?= $e($home) ?>" target="_blank" rel="noopener"><?= $ja ? '高専マップを開く' : 'Open KosenMap' ?></a></p></main>
<?php else: ?>
<div id="km-embed-map" class="km-embed-map" role="region" aria-label="<?= $ja ? '校内の地図' : 'Campus map' ?>"></div>
<nav id="km-embed-floors" class="km-embed-floors" aria-label="<?= $ja ? '階' : 'Floors' ?>"></nav>
<a class="km-embed-home" href="<?= $e($home) ?>" target="_blank" rel="noopener"><?= $ja ? '高専マップで開く' : 'Open in KosenMap' ?></a>
<?php if ($displayDefaults !== null): ?>
<script type="application/json" id="km-display-defaults"<?= km_csp_nonce_attr() ?>><?= $json($displayDefaults) ?></script>
<?php endif; ?>
<script type="application/json" id="km-embed-data"<?= km_csp_nonce_attr() ?>><?= $json([
    'lang' => $query['lang'],
    'floor' => $query['floor'],
    'focus' => $query['focus'],
    // 親へ知らせを送ってよい相手(登録したオリジンだけ。postMessage の相手を決め打ちにする)
    'parents' => $site['origins'],
    'home' => $home,
    'floors' => $data['floors'],
    'places' => $data['places'],
]) ?></script>
<script defer src="<?= $asset('vendor-web/leaflet/leaflet.js') ?>"></script>
<script defer src="<?= $asset('Main/map-style.js') ?>"></script>
<script defer src="<?= $asset('Main/embed.js') ?>"></script>
<?php endif; ?>
</body>
</html>
