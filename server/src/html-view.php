<?php

declare(strict_types=1);

/**
 * HTML 展開サイトの**見せる側**(https://html.<本体>/)の入口 1 本(2026-11-05。仕組みは lib/html-host.php)。
 *
 * nginx の html の server が、どの要求もこのファイルへ書き換えて渡す(元の URI は X-KM-Html-Uri)。
 * **本体のホストで呼ばれたら 404**(nginx でも本体の server で塞いでいる)。本体のサインインには触れない
 * (logto-client.php もセッションも読まない。持つのは見るための印 __Host-KMHV だけ)。
 *
 *   /auth?t=<札>        本体が作った札を使い切って印を置き、行き先へ 303
 *   /s/<番号>/[v<版>/]…  サーバーに保存したもの(持ち主だけ)
 *   /t/<番号>/…          一時フォルダのもの(上げたブラウザだけ)
 *   /l/<番号>/…          ローカル(Service Worker が答える。ここへ来たら「このブラウザにありません」)
 *   /bridge.html・/bridge.js・/sw.js  ローカルの仕組み(src/html-host/)
 *   /robots.txt          検索に載せない
 *   /                    道具のページへ
 *
 * share.<本体>(共有リンク。2026-11-05)は lib/html-share-view.php が答える(注意の画面・パスワード・いつも最新の版)
 */

require_once __DIR__ . '/lib/site.php';
require_once __DIR__ . '/lib/html-host.php';

/** 何も教えない 404(HTML 1 行) */
function km_html_view_not_found(string $message = 'ページが見つかりません。'): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    header('X-Robots-Tag: noindex, nofollow');
    $tool = htmlspecialchars(km_site_url(null, '/html.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>見つかりません</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;line-height:1.8;color:#1f2937}a{color:#2563eb}</style></head><body>'
        . '<h1>見つかりません</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '<p><a href="' . $tool . '">HTML 展開のページへ</a></p></body></html>';
    exit;
}

/** このホストが見せる側か(Host は利用者が書けるので、設定した名前と同じときだけ) */
function km_html_view_host_ok(): bool
{
    return km_html_view_host_matches(km_site_html_origin());
}

/** Host がそのオリジンの名前(とポート)と同じか */
function km_html_view_host_matches(string $origin): bool
{
    $parts = parse_url($origin);
    if (!is_array($parts) || !isset($parts['host'])) {
        return false;
    }
    $expected = strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    $requested = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $defaultPort = ($parts['scheme'] ?? 'https') === 'https' ? ':443' : ':80';

    return $requested === $expected || (!isset($parts['port']) && $requested === $expected . $defaultPort);
}

/** 上げたファイルを返す(ETag は中身のハッシュ) */
function km_html_view_send_blob(string $blobFile, string $path, string $hash): never
{
    if (!is_file($blobFile)) {
        km_html_view_not_found();
    }
    $etag = '"' . $hash . '"';
    header('Content-Type: ' . km_html_type($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: ' . KM_HTML_CONTENT_CSP);
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    // 本人だけが見るもの。共有のキャッシュに置かせない。中身が同じなら 304
    header('Cache-Control: private, no-cache');
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    // 型の表に無いもの(実行ファイルなど)は表示させずに落とさせる
    if (km_html_type($path) === 'application/octet-stream') {
        header('Content-Disposition: attachment');
    }
    header('Content-Length: ' . (string) filesize($blobFile));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($blobFile);
    }
    exit;
}

/** 静的なファイル(src/html-host/) */
function km_html_view_send_static(string $name, string $type, string $csp): never
{
    $file = __DIR__ . '/html-host/' . $name;
    if (!is_file($file)) {
        km_html_view_not_found();
    }
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: ' . $csp);
    header('Cache-Control: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($file);
    exit;
}

// 共有リンク(share.<本体>。2026-11-05)は別の入口の関数が答える。html のホストでは共有の道に答えない
if (km_html_view_host_matches(km_site_share_origin())) {
    require __DIR__ . '/lib/html-share-view.php';
    km_html_share_view_main();
}
if (!km_html_view_host_ok()) {
    km_html_view_not_found();
}
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$uri = (string) ($_SERVER['HTTP_X_KM_HTML_URI'] ?? $_SERVER['REQUEST_URI'] ?? '/');
$uriPath = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
$appOrigin = km_site_origin();

if ($uriPath === '/' || $uriPath === '') {
    header('Location: ' . $appOrigin . '/html.php', true, 302);
    exit;
}
if ($uriPath === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}
// ローカルの仕組み。bridge.html だけ本体のページの枠に入れてよい(本体のオリジンだけ)
if ($uriPath === '/bridge.html') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; frame-ancestors " . $appOrigin);
    header('Cache-Control: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    // 受け取ってよい相手(本体のオリジン)を bridge.js に教える
    echo str_replace('{{KM_PARENT}}', htmlspecialchars($appOrigin, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        (string) file_get_contents(__DIR__ . '/html-host/bridge.html'));
    exit;
}
if ($uriPath === '/bridge.js') {
    km_html_view_send_static('bridge.js', 'text/javascript; charset=utf-8', "default-src 'none'");
}
if ($uriPath === '/sw.js') {
    km_html_view_send_static('sw.js', 'text/javascript; charset=utf-8', "default-src 'none'");
}

if ($uriPath === '/auth') {
    /*
     * 札 → 印。**本体のページから開いたときだけ**(lib/html-host.php の km_html_auth_request_allowed)。
     * 札の行き先は本体が組んだもの(/s/ か /t/)なので、ほかのサイトへは飛ばない
     */
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    if (!km_html_auth_request_allowed($_SERVER, $appOrigin)) {
        km_html_view_not_found('このリンクは、HTML 展開のページの「開く」から開いてください。');
    }
    try {
        $pdo = km_db();
        $ticket = km_html_ticket_consume($pdo, is_string($query['t'] ?? null) ? $query['t'] : '');
        if ($ticket === null) {
            km_html_view_not_found('リンクの期限が切れています。HTML 展開のページの「開く」からもう一度開いてください。');
        }
        $token = km_html_viewer_grant($pdo, (string) ($_COOKIE[KM_HTML_VIEWER_COOKIE] ?? ''), $ticket['kind'], $ticket['subject']);
    } catch (Throwable $exception) {
        error_log('html-view.php /auth: ' . $exception->getMessage());
        http_response_code(503);
        exit;
    }
    km_html_viewer_cookie_set($token);
    header('Location: ' . $ticket['to'], true, 303);
    exit;
}

$route = km_html_parse_view_path($uriPath);
if ($route === null) {
    km_html_view_not_found();
}
if ($route['kind'] === 'local') {
    // ここへ来たのは、このブラウザに Service Worker が無い(別のブラウザ・消した・プライベートの窓)とき
    km_html_view_not_found('このページは、置いたブラウザの中にだけあります。置いたブラウザで、HTML 展開のページから開いてください。');
}
if (!$route['slash']) {
    // 相対パスの CSS・JS が効くように、フォルダの形(終わりのスラッシュ)へ移る
    header('Location: ' . $uriPath . '/', true, 308);
    exit;
}

try {
    $pdo = km_db();
    $viewer = km_html_viewer_find($pdo, (string) ($_COOKIE[KM_HTML_VIEWER_COOKIE] ?? ''));
    if ($viewer === null) {
        km_html_view_not_found('見る権限がありません。HTML 展開のページの「開く」から開いてください(印は 12 時間で切れます)。');
    }
    if ($route['kind'] === 'site') {
        $site = km_html_site_find($pdo, $route['id']);
        // 持ち主でなければ、在っても無いことにする
        if ($site === null || $viewer['owner'] === null || !hash_equals($site['ownerSub'], $viewer['owner'])) {
            km_html_view_not_found();
        }
        $manifest = km_html_site_manifest($pdo, $site, $route['version']);
        $dir = km_html_site_dir($site['id']);
        $base = '/s/' . $site['id'] . '/' . ($route['version'] !== null ? 'v' . $route['version'] . '/' : '');
    } else {
        $row = km_html_temp_find($pdo, $route['id']);
        if ($row === null || !in_array($route['id'], $viewer['temps'], true)) {
            km_html_view_not_found('見つかりません(一時フォルダのものは 24 時間で消えます)。');
        }
        $dir = km_html_temp_dir($route['id']);
        $manifest = km_html_read_manifest($dir . '/manifest.json');
        $base = '/t/' . $route['id'] . '/';
    }
} catch (Throwable $exception) {
    error_log('html-view.php: ' . $exception->getMessage());
    http_response_code(503);
    exit;
}
if ($manifest === null) {
    km_html_view_not_found();
}
$resolved = km_html_resolve($manifest['files'], $manifest['entry'], $route['path']);
if ($resolved['redirect'] !== null) {
    header('Location: ' . $base . km_html_encode_path($resolved['redirect']), true, 302);
    exit;
}
if ($resolved['path'] === null) {
    km_html_view_not_found();
}
$item = $manifest['files'][$resolved['path']];
$hash = (string) ($item['h'] ?? '');
if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
    km_html_view_not_found();
}
km_html_view_send_blob($dir . '/blobs/' . $hash, $resolved['path'], $hash);
