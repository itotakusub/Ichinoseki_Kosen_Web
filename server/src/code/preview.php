<?php

declare(strict_types=1);

/*
 * VS Code 型のエディタのプレビューの窓(https://<ドメイン>/code/preview.php?p=<プロジェクト>。段 11-3。2026-11-09)。
 *
 * **元は server/code-src/public/preview.php。** `npm run build` が src/code/ へ写す(こちらを直す)。
 * /code/ の「プレビュー」コマンドが別の窓で開く。プロジェクトは同じ置き場(IndexedDB km-editor)から読み、
 * /code/ が保存するたび(BroadcastChannel)組み直す。組み立てと見せ方は /editor/ と同じ部品
 * (editor-src の projectBuilder.js・preview.js)。動かすのは見せる側(html.<ドメイン>/editor-run.html)。
 *
 * CSP は 'editor'(枠は見せる側だけ・worker と WASM は使わない)。Monaco も VS Code の本体も読まない
 */

require_once dirname(__DIR__) . '/lib/site.php';
require_once dirname(__DIR__) . '/lib/csp.php';

km_csp_send('editor');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function km_code_preview_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Vite の manifest からプレビューの入口の JS を読む。無ければ null */
function km_code_preview_entry(string $dir): ?string
{
    $raw = @file_get_contents($dir . '/.vite/manifest.json');
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    $file = is_array($manifest) ? ($manifest['src/preview-main.js']['file'] ?? null) : null;

    return is_string($file) && preg_match('#^assets/[A-Za-z0-9._-]+\.js$#', $file) ? $file : null;
}

$entry = km_code_preview_entry(__DIR__);
$runUrl = km_site_html_origin() . '/editor-run.html';
if ($entry === null) {
    http_response_code(503);
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>プレビュー | Web Code Editor (Workbench)</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <meta name="km-editor-run" content="<?= km_code_preview_e($runUrl) ?>">
<?php if ($entry !== null): ?>
    <script type="module" src="/code/<?= km_code_preview_e($entry) ?>"<?= km_csp_nonce_attr() ?>></script>
<?php endif; ?>
    <style>
        :root { color-scheme: light dark; --bg: #ffffff; --fg: #1f1f1f; --bar: #f3f3f3; --line: #d4d4d4; --muted: #6b6b6b; --warn: #8a5a00; --error: #b3261e; --btn: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #1f1f1f; --fg: #e6e6e6; --bar: #181818; --line: #3c3c3c; --muted: #9d9d9d; --warn: #e2b93d; --error: #f48771; --btn: #2b2b2b; }
        }
        html, body { margin: 0; height: 100%; background: var(--bg); color: var(--fg); font: 13px/1.5 system-ui, sans-serif; }
        body { display: flex; flex-direction: column; overflow: hidden; }
        .kp-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 6px 10px; background: var(--bar); border-bottom: 1px solid var(--line); }
        .kp-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 40vw; }
        .kp-bar select, .kp-bar button, .kp-console-bar button { font: inherit; color: inherit; background: var(--btn); border: 1px solid var(--line); border-radius: 4px; padding: 2px 8px; }
        .kp-bar button, .kp-console-bar button { cursor: pointer; }
        .kp-spacer { flex: 1; }
        .kp-state { color: var(--muted); }
        .kp-frame { flex: 1; min-height: 0; border: 0; width: 100%; background: #fff; }
        .kp-console { display: flex; flex-direction: column; max-height: 32vh; border-top: 1px solid var(--line); background: var(--bar); }
        .kp-console[hidden] { display: none; }
        .kp-console-bar { display: flex; align-items: center; gap: 8px; padding: 2px 10px; color: var(--muted); }
        .kp-console ol { list-style: none; margin: 0; padding: 0; overflow: auto; font: 12px/1.45 ui-monospace, Consolas, monospace; }
        .kp-console li { display: flex; gap: 8px; padding: 2px 10px; border-top: 1px solid var(--line); white-space: pre-wrap; word-break: break-word; }
        .kp-console li[data-level="warn"] { color: var(--warn); }
        .kp-console li[data-level="error"] { color: var(--error); }
        .kp-console .kp-text { flex: 1; }
        .kp-console .kp-where { color: inherit; background: none; border: 0; padding: 0; font: inherit; text-decoration: underline; cursor: pointer; white-space: nowrap; }
        .kp-empty { padding: 24px 16px; }
    </style>
</head>

<body>
<?php if ($entry === null): ?>
    <main class="kp-empty">
        <h1>プレビュー</h1>
        <p>いまは使えません(組み立てたファイルがありません)。</p>
    </main>
<?php else: ?>
    <header class="kp-bar">
        <span class="kp-name" id="kp-name">…</span>
        <select id="kp-entry" aria-label="プレビューするページ"></select>
        <button type="button" id="kp-reload">再読み込み</button>
        <span class="kp-spacer"></span>
        <span class="kp-state" id="kp-state" role="status" aria-live="polite"></span>
        <button type="button" id="kp-console-toggle" aria-controls="kp-console" aria-expanded="true">コンソール</button>
    </header>
    <iframe class="kp-frame" id="kp-frame" sandbox="allow-scripts" referrerpolicy="no-referrer" title="プレビュー"></iframe>
    <section class="kp-console" id="kp-console" aria-label="コンソール">
        <div class="kp-console-bar">
            <span id="kp-console-count">0</span>
            <span class="kp-spacer"></span>
            <button type="button" id="kp-console-clear">消す</button>
        </div>
        <ol id="kp-console-list"></ol>
    </section>
<?php endif; ?>
</body>

</html>
