<?php

declare(strict_types=1);

/**
 * HTML 展開サイトの**共有リンク**(https://share.<本体>/<32 字>/)の入口(2026-11-05、利用者の指示)。
 * html-view.php がホストを見て、share のときだけここの km_html_share_view_main() を呼ぶ(nginx の html の server が両方の名前を受ける)。
 *
 *   GET  /<番号>/…   開けた印(__Host-KMSH)が無ければ **注意の画面**(スクリプトなし)。あれば、そのサイトの**いつも最新の版**を返す
 *   POST /<番号>/    注意の画面の「開く」。share のオリジンから来たときだけ。パスワード付きなら照合(外れは IP ごとに 15 分 8 回)
 *
 * 本体のサインインには触れない(セッションも logto-client.php も読まない)。持ち主が止められているかだけ、開くときに Logto に聞く
 * (聞けなければ通して記録する。止めたいときは管理画面の「共有を止める」)。
 * 中身を返す作り(型・CSP・ETag)は html-view.php の km_html_view_send_blob を使い回す
 */

require_once __DIR__ . '/html-host.php';
require_once __DIR__ . '/map-rate-limit.php';

function km_html_share_view_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** スクリプトの無い 1 枚のページ(注意の画面・見つからない) */
function km_html_share_view_page(int $status, string $title, string $body): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    // no-referrer にすると「開く」の POST が Origin: null になる(11-05 に写しで見つけた)。よそへは何も渡さない same-origin
    header('Referrer-Policy: same-origin');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . km_html_share_view_e($title) . '</title><style>'
        . ':root{color-scheme:light dark;--bg:#f6f4ef;--fg:#1f2937;--muted:#4b5563;--card:#fff;--line:#d6d3cc;--accent:#2563eb;--warn-bg:#fff7e6;--warn-line:#d97706;--err:#b91c1c}'
        . '@media (prefers-color-scheme: dark){:root{--bg:#16181d;--fg:#e5e7eb;--muted:#a3a8b3;--card:#1f2228;--line:#3a3f48;--accent:#7aa7ff;--warn-bg:#2b2412;--warn-line:#f0a53a;--err:#ff8a8a}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.75}'
        . 'main{max-width:36rem;margin:0 auto;padding:2rem 1rem 3rem}h1{font-size:1.35rem;margin:0 0 1rem}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:1.1rem 1.2rem;margin:0 0 1rem}'
        . '.warn{background:var(--warn-bg);border-color:var(--warn-line)}.warn p{margin:0 0 .5rem}.warn ul{margin:.25rem 0 0;padding-left:1.2rem}'
        . 'dl{display:grid;grid-template-columns:auto 1fr;gap:.25rem 1rem;margin:0}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}'
        . 'label{display:block;font-weight:700;margin:0 0 .3rem}input[type=password]{box-sizing:border-box;width:100%;font:inherit;padding:.55rem .7rem;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--fg)}'
        . 'button{font:inherit;font-weight:700;color:#fff;background:var(--accent);border:0;border-radius:999px;padding:.6rem 1.6rem;margin-top:.9rem;cursor:pointer}'
        . '.error{color:var(--err);font-weight:700;margin:.6rem 0 0}.muted{color:var(--muted);font-size:.9rem}a{color:var(--accent)}'
        . '</style></head><body><main>' . $body . '</main></body></html>';
    exit;
}

function km_html_share_view_gone(string $message = 'このリンクは止められたか、期限が切れています。'): never
{
    km_html_share_view_page(404, '見つかりません', '<h1>見つかりません</h1><div class="card"><p>' . km_html_share_view_e($message) . '</p></div>');
}

/** 期限の表示(止めるまでなら null) */
function km_html_share_view_expires(?string $expiresAt): string
{
    if ($expiresAt === null) {
        return '止めるまで';
    }
    $time = strtotime($expiresAt);

    return $time === false ? $expiresAt : date('Y年n月j日 H:i', $time) . ' まで';
}

/**
 * **注意の画面**(利用者の答え「中身の前に注意の画面を挟む」)。ito4.jp の名前で偽のサインイン画面などを配られないように、
 * 運営者が作ったものではないこと・入力しないことを先に読ませる。持ち主の名前は出さない
 */
function km_html_share_view_gate(array $share, array $site, string $error = '', int $status = 200): never
{
    $hasPassword = $share['passwordHash'] !== null;
    $contact = km_site_origin() . '/contact.php';
    $body = '<h1>共有されたページ</h1>'
        . '<div class="card warn"><p><strong>このページは、高専マップ(' . km_html_share_view_e((string) parse_url(km_site_origin(), PHP_URL_HOST)) . ')の利用者が上げたものです。</strong>'
        . '高専マップの運営者が作ったものではありません。</p><ul>'
        . '<li><strong>パスワード・クレジットカード番号・個人情報は入力しないでください。</strong>高専マップがこのページで入力をお願いすることはありません。</li>'
        . '<li>ページの中のスクリプトも動きます。心当たりのないリンクなら、開かずに閉じてください。</li></ul></div>'
        . '<div class="card"><dl><dt>名前</dt><dd>' . km_html_share_view_e($site['name']) . '(上げた人が付けた名前)</dd>'
        . '<dt>期限</dt><dd>' . km_html_share_view_e(km_html_share_view_expires($share['expiresAt'])) . '</dd></dl></div>'
        . '<form class="card" method="post" action="/' . km_html_share_view_e($share['id']) . '/">';
    if ($hasPassword) {
        $body .= '<label for="password">パスワード</label>'
            . '<input type="password" id="password" name="password" autocomplete="current-password" required maxlength="' . KM_HTML_SHARE_PASSWORD_MAX . '">'
            . '<p class="muted">このリンクを作った人から聞いたパスワードを入れてください。</p>';
    }
    if ($error !== '') {
        $body .= '<p class="error" role="alert">' . km_html_share_view_e($error) . '</p>';
    }
    $body .= '<button type="submit">注意を読んだので開く</button></form>'
        . '<p class="muted">問題のあるページを見つけたら、<a href="' . km_html_share_view_e($contact) . '" rel="noopener">お問い合わせ</a>から、このページのアドレスを添えて知らせてください。</p>';
    km_html_share_view_page($status, '共有されたページ', $body);
}

/** share.<本体> への要求に答える(返らない) */
function km_html_share_view_main(): never
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $uri = (string) ($_SERVER['HTTP_X_KM_HTML_URI'] ?? $_SERVER['REQUEST_URI'] ?? '/');
    $uriPath = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

    if ($uriPath === '/' || $uriPath === '') {
        header('Location: ' . km_site_origin() . '/html.php', true, 302);
        exit;
    }
    if ($uriPath === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: /\n";
        exit;
    }
    $route = km_html_parse_share_path($uriPath);
    if ($route === null) {
        km_html_share_view_gone('ページが見つかりません。');
    }
    if (!$route['slash']) {
        if ($method !== 'GET' && $method !== 'HEAD') {
            km_html_share_view_gone('ページが見つかりません。');
        }
        // 相対パスの CSS・JS が効くように、フォルダの形(終わりのスラッシュ)へ移る
        header('Location: ' . $uriPath . '/', true, 308);
        exit;
    }
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true) || ($method === 'POST' && $route['path'] !== '')) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }

    try {
        $pdo = km_db();
        $share = km_html_share_find($pdo, $route['id']);
        $site = $share === null ? null : km_html_share_site($pdo, $share);
    } catch (Throwable $exception) {
        error_log('html-share-view: ' . $exception->getMessage());
        http_response_code(503);
        exit;
    }
    if ($share === null || $site === null) {
        km_html_share_view_gone();
    }

    if ($method === 'POST') {
        header('Cache-Control: no-store');
        // よそのサイトから送らせない(パスワードの総当たりの踏み台・開いた印の押し付け)
        if (!km_html_share_post_allowed($_SERVER, km_site_share_origin())) {
            km_html_share_view_gate($share, $site, 'このページの「開く」から開いてください。', 403);
        }
        // 持ち主が止められていれば開かない(確かめられないときは通して記録する)
        try {
            require_once __DIR__ . '/logto-management.php';
            if (km_logto_user_is_suspended($share['ownerSub'])) {
                km_html_share_view_gone();
            }
        } catch (Throwable $exception) {
            error_log('html-share-view: suspended check failed (fail open): ' . $exception->getMessage());
        }
        try {
            if ($share['passwordHash'] !== null) {
                // **照合の前に数える**(lib/map-rate-limit.php。共有をまたいで IP ごとに 15 分 8 回)。成功しても消さない
                if (!km_map_unlock_attempt($pdo, 'html-share')) {
                    km_html_share_view_gate($share, $site, '続けて間違えたため、しばらく開けません。15 分ほど待ってからお試しください。', 429);
                }
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                if ($password === '' || strlen($password) > KM_HTML_SHARE_PASSWORD_MAX || !password_verify($password, $share['passwordHash'])) {
                    km_html_share_view_gate($share, $site, 'パスワードが違います。', 401);
                }
            }
            $token = km_html_share_grant($pdo, (string) ($_COOKIE[KM_HTML_SHARE_COOKIE] ?? ''), $share['id']);
            km_html_share_opened($pdo, $share);
        } catch (Throwable $exception) {
            error_log('html-share-view open: ' . $exception->getMessage());
            http_response_code(503);
            exit;
        }
        km_html_share_cookie_set($token);
        header('Location: /' . $share['id'] . '/', true, 303);
        exit;
    }

    try {
        $granted = km_html_share_grant_find($pdo, (string) ($_COOKIE[KM_HTML_SHARE_COOKIE] ?? '')) ?? [];
        if (!in_array($share['id'], $granted, true)) {
            km_html_share_view_gate($share, $site);
        }
        $manifest = km_html_site_manifest($pdo, $site, null);
    } catch (Throwable $exception) {
        error_log('html-share-view: ' . $exception->getMessage());
        http_response_code(503);
        exit;
    }
    if ($manifest === null) {
        km_html_share_view_gone();
    }
    $base = '/' . $share['id'] . '/';
    $resolved = km_html_resolve($manifest['files'], $manifest['entry'], $route['path']);
    if ($resolved['redirect'] !== null) {
        header('Location: ' . $base . km_html_encode_path($resolved['redirect']), true, 302);
        exit;
    }
    if ($resolved['path'] === null) {
        km_html_share_view_gone('ページが見つかりません。');
    }
    $item = $manifest['files'][$resolved['path']];
    $hash = (string) ($item['h'] ?? '');
    if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
        km_html_share_view_gone('ページが見つかりません。');
    }
    km_html_view_send_blob(km_html_site_dir($site['id']) . '/blobs/' . $hash, $resolved['path'], $hash);
}
