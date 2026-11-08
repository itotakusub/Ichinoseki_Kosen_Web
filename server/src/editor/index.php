<?php

declare(strict_types=1);

/*
 * Web Code Editor の殻(https://<ドメイン>/editor/。2026-11-08。段 10 で複数ファイル・プロジェクト・読み込みを足した)。
 *
 * **元は server/editor-src/public/index.php。** `npm run build` が src/editor/ へ写す(src/editor/ は毎回作り直すので、
 * こちらを直接直さない)。中身(Monaco・プレビュー・保存(IndexedDB)・補完・ZIP)はすべてブラウザで動く(利用者の指示
 * 「処理はできるだけクライアントで」)。サーバーはこの殻と静的ファイルを配る。
 *
 * 段 10-B(2026-11-08): サインインしていれば、プロジェクトをサーバーにも預かる(api/editor.php・規約第8条の3)。
 * そのため殻はサインインのセッションを見て、CSRF の札(meta km-csrf)とサインインしているか(meta km-signed-in)を渡す。
 * **公開ページなので fail open**(サインインの仕組みに繋がらなくても殻は出し、この端末への保存は使える。html.php と同じ)。DB は読まない
 *
 * - CSP は 'editor'(lib/csp.php。style だけ 'unsafe-inline'・worker は同じオリジン・枠は見せる側だけ)
 * - 入口の JS と CSS の名前は Vite の .vite/manifest.json から読む(ハッシュ付き)
 * - プレビューは見せる側(html.<ドメイン>/editor-run.html)。<iframe sandbox="allow-scripts">(allow-same-origin は付けない)
 */

require_once dirname(__DIR__) . '/lib/site.php';
require_once dirname(__DIR__) . '/lib/assets.php';
require_once dirname(__DIR__) . '/lib/release.php';
require_once dirname(__DIR__) . '/lib/csp.php';
require_once dirname(__DIR__) . '/lib/csrf.php';

km_csp_send('editor');

$signedIn = false;
try {
    require dirname(__DIR__) . '/logto-client.php';
    $signedIn = $client->isAuthenticated() && trim((string) ($client->getIdTokenClaims()->sub ?? '')) !== '';
} catch (Throwable $exception) {
    error_log('editor/index.php: Logto session check failed (fail open): ' . $exception->getMessage());
    require_once dirname(__DIR__) . '/lib/session.php';
    km_session_start();
}
$csrf = km_csrf_token();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function km_editor_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Vite の manifest から入口の JS と CSS を読む。無ければ null(作っていない・壊れている) */
function km_editor_entry(string $dir): ?array
{
    $raw = @file_get_contents($dir . '/.vite/manifest.json');
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    $entry = is_array($manifest) ? ($manifest['src/main.js'] ?? null) : null;
    if (!is_array($entry) || !is_string($entry['file'] ?? null) || !preg_match('#^assets/[A-Za-z0-9._-]+\.js$#', $entry['file'])) {
        return null;
    }
    $css = [];
    foreach ((array) ($entry['css'] ?? []) as $file) {
        if (is_string($file) && preg_match('#^assets/[A-Za-z0-9._-]+\.css$#', $file)) {
            $css[] = $file;
        }
    }
    return ['js' => $entry['file'], 'css' => $css];
}

$entry = km_editor_entry(__DIR__);
$runUrl = km_site_html_origin() . '/editor-run.html';
if ($entry === null) {
    http_response_code(503);
}
?>
<!DOCTYPE html>
<html lang="ja" data-theme="auto">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Code Editor | 高専マップ案内</title>
    <meta name="description" content="HTML・CSS・JavaScript をブラウザで書いて、その場で動きを見られるエディタ。複数のファイル・プロジェクト・ZIP の読み書きに対応。書いたものはこの端末のブラウザにだけ残ります。">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <meta name="km-editor-run" content="<?= km_editor_e($runUrl) ?>">
    <meta name="km-csrf" content="<?= km_editor_e($csrf) ?>">
    <meta name="km-signed-in" content="<?= $signedIn ? '1' : '0' ?>">
    <?= km_release_tags() ?>
<?php if ($entry !== null): ?>
<?php foreach ($entry['css'] as $css): ?>
    <link rel="stylesheet" href="/editor/<?= km_editor_e($css) ?>">
<?php endforeach; ?>
    <script type="module" src="/editor/<?= km_editor_e($entry['js']) ?>"<?= km_csp_nonce_attr() ?>></script>
<?php endif; ?>
</head>

<body class="wce">
<?php if ($entry === null): ?>
    <main class="wce-unavailable">
        <h1>Web Code Editor</h1>
        <p>いまは使えません(組み立てたファイルがありません)。しばらくしてからもう一度開いてください。</p>
        <p><a href="/">地図へ戻る</a></p>
    </main>
<?php else: ?>
    <header class="wce-header">
        <a class="wce-home" href="/" data-i18n-title="nav.home" title="地図へ戻る" aria-label="地図へ戻る">←</a>
        <h1 class="wce-title">Web Code Editor</h1>
        <button type="button" class="wce-project-btn" id="wce-project" aria-haspopup="dialog">…</button>
        <span class="wce-save" id="wce-save" role="status" aria-live="polite" data-state="saved" data-i18n="save.saved">保存済み</span>
        <a class="wce-sync wce-sync-signin" id="wce-signin" href="/sign-in.php?mode=signIn&amp;return=/editor/" data-i18n="sync.signIn" data-i18n-title="sync.signInTitle" title="サインインすると、サーバーにも保存できます" hidden>サインイン</a>
        <button type="button" class="wce-sync" id="wce-sync" role="status" aria-live="polite" data-state="idle" hidden></button>
        <div class="wce-actions">
            <button type="button" class="wce-btn wce-btn-primary" id="wce-run" data-i18n="action.run">実行</button>
            <button type="button" class="wce-btn" id="wce-template" data-i18n="action.new">新規</button>
            <button type="button" class="wce-btn" id="wce-import" data-i18n="action.import">読み込み</button>
            <button type="button" class="wce-btn" id="wce-download" data-i18n="action.download">ダウンロード</button>
            <button type="button" class="wce-btn" id="wce-settings" data-i18n="action.settings">設定</button>
            <button type="button" class="wce-btn" id="wce-theme" data-i18n-title="action.theme" title="見た目を切り替える" aria-label="見た目を切り替える"></button>
            <button type="button" class="wce-btn" id="wce-lang" lang="en">English</button>
        </div>
    </header>

    <nav class="wce-switch" aria-label="表示の切り替え" data-i18n-aria="switch.label">
        <button type="button" class="wce-switch-btn" data-view="edit" aria-pressed="true" data-i18n="switch.edit">編集</button>
        <button type="button" class="wce-switch-btn" data-view="preview" aria-pressed="false" data-i18n="switch.preview">プレビュー</button>
    </nav>

    <main class="wce-main" id="wce-main" data-view="edit">
        <section class="wce-pane wce-pane-editor is-files-open" aria-label="編集" data-i18n-aria="pane.editor">
            <aside class="wce-files" id="wce-files" aria-label="ファイル" data-i18n-aria="files.label">
                <div class="wce-files-bar">
                    <span class="wce-files-title" data-i18n="files.label">ファイル</span>
                    <button type="button" class="wce-icon-btn" id="wce-files-new" data-i18n-title="files.newFile" title="新しいファイル" aria-label="新しいファイル">+</button>
                    <button type="button" class="wce-icon-btn" id="wce-files-folder" data-i18n-title="files.newFolder" title="新しいフォルダ" aria-label="新しいフォルダ">▤</button>
                    <button type="button" class="wce-icon-btn" id="wce-files-upload" data-i18n-title="files.upload" title="ファイルを追加" aria-label="ファイルを追加">↑</button>
                </div>
                <div class="wce-tree-box" id="wce-tree"></div>
                <p class="wce-files-hint" data-i18n="files.dropHint">ここへファイル・フォルダ・ZIP を落とすと追加できます。</p>
            </aside>
            <div class="wce-editor-col">
                <div class="wce-tabs-bar">
                    <button type="button" class="wce-icon-btn" id="wce-files-toggle" aria-controls="wce-files" aria-expanded="true" data-i18n-aria="files.label" aria-label="ファイル">☰</button>
                    <div class="wce-tabs" id="wce-tabs" role="tablist" aria-label="開いているファイル" data-i18n-aria="tabs.label"></div>
                </div>
                <div class="wce-panel" id="wce-panel" role="tabpanel">
                    <div class="wce-monaco" id="wce-monaco"></div>
                    <div class="wce-viewer" id="wce-viewer" hidden></div>
                </div>
            </div>
        </section>

        <div class="wce-resizer" id="wce-resizer" role="separator" tabindex="0" aria-orientation="vertical" aria-valuemin="20" aria-valuemax="80" aria-valuenow="50" data-i18n-aria="pane.resizer" aria-label="編集とプレビューの幅"></div>

        <section class="wce-pane wce-pane-preview" aria-label="プレビュー" data-i18n-aria="pane.preview">
            <div class="wce-preview-bar">
                <span class="wce-preview-label" data-i18n="preview.label">プレビュー</span>
                <select class="wce-entry" id="wce-entry" data-i18n-aria="preview.entry" aria-label="プレビューするページ"></select>
                <span class="wce-preview-mode" id="wce-preview-mode"></span>
                <button type="button" class="wce-btn wce-btn-small" id="wce-reload" data-i18n="preview.reload">再読み込み</button>
            </div>
            <div class="wce-frame-wrap">
                <iframe class="wce-frame" id="wce-frame" sandbox="allow-scripts" referrerpolicy="no-referrer" title="プレビュー" data-i18n-title="preview.label"></iframe>
            </div>
            <section class="wce-console" aria-label="コンソール" data-i18n-aria="console.label">
                <div class="wce-console-bar">
                    <button type="button" class="wce-console-toggle" id="wce-console-toggle" aria-expanded="true" aria-controls="wce-console-list">
                        <span data-i18n="console.label">コンソール</span>
                        <span class="wce-console-count" id="wce-console-count">0</span>
                    </button>
                    <button type="button" class="wce-btn wce-btn-small" id="wce-console-clear" data-i18n="console.clear">消す</button>
                </div>
                <ol class="wce-console-list" id="wce-console-list"></ol>
            </section>
        </section>
    </main>

    <input type="file" class="wce-hidden-input" id="wce-import-files" multiple tabindex="-1" aria-hidden="true">
    <input type="file" class="wce-hidden-input" id="wce-import-folder" webkitdirectory multiple tabindex="-1" aria-hidden="true">

    <dialog class="wce-dialog wce-dialog-wide" id="wce-settings-dialog" aria-labelledby="wce-settings-title">
        <form method="dialog" class="wce-dialog-body" id="wce-settings-form">
            <h2 id="wce-settings-title" data-i18n="settings.title">設定</h2>
            <h3 data-i18n="settings.previewHead">プレビュー</h3>
            <label class="wce-check">
                <input type="checkbox" id="wce-auto" checked>
                <span data-i18n="settings.auto">自動で更新する</span>
            </label>
            <label class="wce-field" for="wce-delay-range">
                <span data-i18n="settings.delay">更新までの待ち時間</span>
            </label>
            <div class="wce-delay">
                <span class="wce-delay-end">0ms</span>
                <input type="range" id="wce-delay-range" min="0" max="1000" step="10" value="250">
                <span class="wce-delay-end">1000ms</span>
            </div>
            <div class="wce-delay-number">
                <input type="number" id="wce-delay-number" min="0" max="1000" step="1" value="250" inputmode="numeric" data-i18n-aria="settings.delay" aria-label="更新までの待ち時間">
                <span>ms</span>
            </div>
            <p class="wce-help" id="wce-delay-help" data-i18n="settings.help">0ms は打つたびにすぐ。ほかは打つのを止めてから、その時間のあとに更新します。</p>
            <h3><label for="wce-theme-select" data-i18n="settings.theme">配色</label></h3>
            <select class="wce-select" id="wce-theme-select"></select>
            <h3 data-i18n="settings.keys">ショートカット</h3>
            <p class="wce-help" data-i18n="settings.keysHelp">ボタンを押してから、付けたいキーを押します。Esc でやめる・Backspace で外す。</p>
            <ul class="wce-keys-list" id="wce-keys-list"></ul>
            <p class="wce-keys-message" id="wce-keys-message" role="alert"></p>
            <div>
                <button type="button" class="wce-btn wce-btn-small" id="wce-keys-reset" data-i18n="settings.keysReset">既定に戻す</button>
            </div>
            <h3 data-i18n="settings.storageHead">この端末の保存</h3>
            <p class="wce-help" id="wce-storage"></p>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.cancel">やめる</button>
                <button type="submit" class="wce-btn wce-btn-primary" value="save" id="wce-settings-save" data-i18n="settings.save">設定を保存</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog wce-dialog-wide" id="wce-projects-dialog" aria-labelledby="wce-projects-title">
        <form method="dialog" class="wce-dialog-body">
            <h2 id="wce-projects-title" data-i18n="project.list">プロジェクト</h2>
            <p class="wce-help" id="wce-projects-help" data-i18n="project.help">プロジェクトはこの端末のブラウザに保存されます(サーバーには送りません)。</p>
            <p class="wce-help" id="wce-projects-usage" hidden></p>
            <div class="wce-row">
                <button type="button" class="wce-btn wce-btn-primary" id="wce-projects-new" data-i18n="project.new">新しいプロジェクト</button>
                <button type="button" class="wce-btn" id="wce-projects-import" data-i18n="project.import">ZIP・フォルダから作る</button>
            </div>
            <ul class="wce-projects-list" id="wce-projects-list"></ul>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.close">閉じる</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-template-dialog" aria-labelledby="wce-template-title">
        <form method="dialog" class="wce-dialog-body">
            <h2 id="wce-template-title" data-i18n="template.title">新しいプロジェクト</h2>
            <p class="wce-help" data-i18n="template.help">テンプレートを選ぶと、新しいプロジェクトを作ります(いまのプロジェクトはそのまま残ります)。</p>
            <ul class="wce-template-list" id="wce-template-list"></ul>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.close">閉じる</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-import-dialog" aria-labelledby="wce-import-title">
        <form method="dialog" class="wce-dialog-body">
            <h2 id="wce-import-title" data-i18n="import.title">読み込み</h2>
            <fieldset class="wce-fieldset">
                <legend data-i18n="import.target">読み込む先</legend>
                <label class="wce-check"><input type="radio" name="wce-import-target" value="new" checked> <span data-i18n="import.toNew">新しいプロジェクト</span></label>
                <label class="wce-check"><input type="radio" name="wce-import-target" value="current"> <span data-i18n="import.toCurrent">いまのプロジェクトに足す</span></label>
            </fieldset>
            <div class="wce-row">
                <button type="button" class="wce-btn" id="wce-import-pick" data-i18n="import.pick">ZIP・ファイルを選ぶ</button>
                <button type="button" class="wce-btn" id="wce-import-pick-folder" data-i18n="import.pickFolder">フォルダを選ぶ</button>
            </div>
            <div class="wce-drop" id="wce-import-drop" data-i18n="import.drop">ここへ ZIP・ファイル・フォルダを落としても読み込めます。</div>
            <p class="wce-help" data-i18n="import.help">この端末の中で読みます(サーバーには送りません)。隠しファイル・__MACOSX は除きます。1 プロジェクト 20MB・500 ファイルまで。</p>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.close">閉じる</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-download-dialog" aria-labelledby="wce-download-title">
        <form method="dialog" class="wce-dialog-body">
            <h2 id="wce-download-title" data-i18n="download.title">ダウンロード</h2>
            <p class="wce-help" data-i18n="download.help">この端末に保存します(サーバーには送りません)。</p>
            <div class="wce-download-choices">
                <button type="button" class="wce-btn" id="wce-download-zip" data-i18n="download.zip">プロジェクトを ZIP で</button>
                <button type="button" class="wce-btn" id="wce-download-single" data-i18n="download.single">1 つの HTML にまとめる</button>
            </div>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.close">閉じる</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-quick-dialog" aria-labelledby="wce-quick-title">
        <form method="dialog" class="wce-dialog-body">
            <h2 id="wce-quick-title" data-i18n="quick.title">ファイルを開く</h2>
            <input type="search" class="wce-input" id="wce-quick-input" autocomplete="off" spellcheck="false" data-i18n-aria="quick.title" aria-label="ファイルを開く">
            <ul class="wce-quick-list" id="wce-quick-list"></ul>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.close">閉じる</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-prompt-dialog" aria-labelledby="wce-prompt-title">
        <form method="dialog" class="wce-dialog-body" id="wce-prompt-form">
            <h2 id="wce-prompt-title"></h2>
            <label class="wce-field" for="wce-prompt-input" id="wce-prompt-label"></label>
            <input type="text" class="wce-input" id="wce-prompt-input" autocomplete="off" spellcheck="false" aria-describedby="wce-prompt-error">
            <p class="wce-error" id="wce-prompt-error" role="alert"></p>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.cancel">やめる</button>
                <button type="submit" class="wce-btn wce-btn-primary" value="ok" id="wce-prompt-ok" data-i18n="dialog.ok">はい</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-password-dialog" aria-labelledby="wce-password-title">
        <form method="dialog" class="wce-dialog-body">
            <h2 id="wce-password-title" data-i18n="import.password">ZIP のパスワード</h2>
            <p class="wce-help" data-i18n="import.passwordHelp">パスワードはこの端末の中で使うだけで、どこにも送りません。</p>
            <input type="password" class="wce-input" id="wce-password-input" autocomplete="off" data-i18n-aria="import.password" aria-label="ZIP のパスワード" aria-describedby="wce-password-error">
            <p class="wce-error" id="wce-password-error" role="alert"></p>
            <div class="wce-dialog-actions">
                <button type="button" class="wce-btn" data-close data-i18n="dialog.cancel">やめる</button>
                <button type="submit" class="wce-btn wce-btn-primary" value="ok" data-i18n="dialog.ok">はい</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-choose-dialog" aria-labelledby="wce-choose-text">
        <form method="dialog" class="wce-dialog-body">
            <p id="wce-choose-text" class="wce-pre"></p>
            <div class="wce-dialog-actions" id="wce-choose-actions"></div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-message-dialog" aria-labelledby="wce-message-text">
        <form method="dialog" class="wce-dialog-body">
            <p id="wce-message-text" class="wce-pre"></p>
            <div class="wce-dialog-actions">
                <button type="submit" class="wce-btn wce-btn-primary" value="ok" data-i18n="dialog.ok">はい</button>
            </div>
        </form>
    </dialog>

    <dialog class="wce-dialog" id="wce-confirm-dialog" aria-labelledby="wce-confirm-text">
        <form method="dialog" class="wce-dialog-body">
            <p id="wce-confirm-text" class="wce-pre"></p>
            <div class="wce-dialog-actions">
                <button type="submit" class="wce-btn" value="cancel" data-i18n="dialog.cancel">やめる</button>
                <button type="submit" class="wce-btn wce-btn-danger" value="ok" id="wce-confirm-ok">はい</button>
            </div>
        </form>
    </dialog>

    <noscript><p class="wce-noscript">このエディタは JavaScript を使います。</p></noscript>
<?php endif; ?>
</body>

</html>
