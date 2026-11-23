<?php

declare(strict_types=1);

/*
 * VS Code 型のエディタの殻(https://<ドメイン>/code/。段 11。2026-11-08)。
 *
 * **元は server/code-src/public/index.php。** `npm run build` が src/code/ へ写す(src/code/ は毎回作り直すので、
 * こちらを直接直さない)。中身(VS Code のワークベンチ・言語機能・保存(IndexedDB))はすべてブラウザで動く。
 * サーバーはこの殻と静的ファイルを配る。プロジェクトは /editor/ と同じ置き場(IndexedDB km-editor)。
 *
 * CSP は 'code'(lib/csp.php)。11-0 で 'editor' と同じ厳しさを Report-Only で送って数え、足りないものだけを足した。
 * サーバーとの同期(段 11-4。口は /editor/ と同じ api/editor.php)のため、/editor/ の殻と同じくサインインのセッションを見て、
 * CSRF の札(meta km-csrf)とサインインしているか(meta km-signed-in)を渡す。DB は読まない
 */

require_once dirname(__DIR__) . '/lib/site.php';
require_once dirname(__DIR__) . '/lib/csp.php';
require_once dirname(__DIR__) . '/lib/csrf.php';

km_csp_send('code');

$signedIn = false;
try {
    require dirname(__DIR__) . '/logto-client.php';
    $signedIn = $client->isAuthenticated() && trim((string) ($client->getIdTokenClaims()->sub ?? '')) !== '';
} catch (Throwable $exception) {
    error_log('code/index.php: Logto session check failed (fail open): ' . $exception->getMessage());
    require_once dirname(__DIR__) . '/lib/session.php';
    km_session_start();
}
$csrf = km_csrf_token();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function km_code_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Vite の manifest から入口の JS を読む。無ければ null(作っていない・壊れている) */
function km_code_entry(string $dir): ?string
{
    $raw = @file_get_contents($dir . '/.vite/manifest.json');
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    $file = is_array($manifest) ? ($manifest['src/main.js']['file'] ?? null) : null;

    return is_string($file) && preg_match('#^assets/[A-Za-z0-9._-]+\.js$#', $file) ? $file : null;
}

$entry = km_code_entry(__DIR__);
if ($entry === null) {
    http_response_code(503);
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Code Editor (Workbench) | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <!-- 拡張機能ホストと webview の枠を出す別のオリジン(段 11-3。lib/site.php の km_site_webview_origin) -->
    <meta name="km-webview-origin" content="<?= km_code_e(km_site_webview_origin()) ?>">
    <meta name="km-csrf" content="<?= km_code_e($csrf) ?>">
    <meta name="km-signed-in" content="<?= $signedIn ? '1' : '0' ?>">
<?php if ($entry !== null): ?>
    <script type="module" src="/code/<?= km_code_e($entry) ?>"<?= km_csp_nonce_attr() ?>></script>
<?php endif; ?>
    <style<?= km_csp_nonce_attr() ?>>
        html, body { margin: 0; height: 100%; overflow: hidden; background: #1f1f1f; }
        #km-code { height: 100vh; }
        .km-code-narrow { box-sizing: border-box; height: 100%; overflow: auto; padding: 24px 16px; color: #e6e6e6; font: 16px/1.7 system-ui, sans-serif; }
        .km-code-narrow h1 { font-size: 20px; margin: 0 0 12px; }
        .km-code-narrow a, .km-code-narrow button { display: block; width: 100%; box-sizing: border-box; margin: 12px 0 0; padding: 12px; border-radius: 8px; border: 1px solid #555; background: #2b2b2b; color: inherit; font: inherit; text-align: center; text-decoration: none; }
        .km-code-narrow .km-code-narrow-primary { background: #0e639c; border-color: #0e639c; color: #fff; }
    </style>
</head>

<body>
<?php if ($entry === null): ?>
    <main>
        <h1>Web Code Editor (Workbench)</h1>
        <p>いまは使えません(組み立てたファイルがありません)。<a href="/editor/">Web Code Editor</a> を使ってください。</p>
    </main>
<?php else: ?>
    <div id="km-code"></div>
<?php endif; ?>
</body>

</html>
