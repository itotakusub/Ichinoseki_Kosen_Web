<?php

declare(strict_types=1);

/**
 * HTML 展開サイトの**道具のページ**(誰でも使える公開ページ。2026-11-05、利用者の指示。仕組みは lib/html-host.php)。
 * 利用者がリンク集(qr.php・管理画面「QR のリンク集」)に貼る。
 *
 * ZIP・HTML・CSS・JS・フォルダを落とすと、見せる側(https://html.<本体>/)の実際の URL で開ける。
 *   - サインインしていなければ、このブラウザの中(ローカル)に置く。置けなければサーバーの一時フォルダ(24 時間)
 *   - サインインしていれば、サーバーに保存もできる(版 10 まで。11 版目からは一番古い版を消す)
 *   - HTML の無い CSS・JS だけを落としたら、選んでいるサイトに足す(同じ名前は差し替え)
 * 動きは Main/html-host.js。サーバーとのやりとりは api/html-host.php。
 *
 * 公開ページなので fail open: サインインの仕組みに繋がらなくてもページは出し、ローカルと一時フォルダは使える。
 */

require_once __DIR__ . '/lib/site.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/release.php';   // km_release_tags(): 配備のあとに更新を促す(2026-11-02)
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/html-host.php';
require_once __DIR__ . '/lib/legal.php';      // 使い始める前に見せる規約(第8条の2)
require_once __DIR__ . '/lib/recaptcha.php';  // 使い始める前の「私はロボットではありません」

// 見せる側の bridge.html を見えない iframe で開く(ローカルの置き場)。枠に許すのは reCAPTCHA と見せる側のオリジンだけ
km_csp_send('html-host');

$signedIn = false;
$displayName = '';
try {
    require __DIR__ . '/logto-client.php';
    if ($client->isAuthenticated()) {
        $claims = $client->getIdTokenClaims();
        $signedIn = trim((string) ($claims->sub ?? '')) !== '';
        $displayName = (string) ($claims->name ?? $claims->username ?? $claims->email ?? '');
    }
} catch (Throwable $exception) {
    error_log('html.php: Logto session check failed (fail open): ' . $exception->getMessage());
    require_once __DIR__ . '/lib/session.php';
    km_session_start();
}
$csrf = km_csrf_token();

/*
 * 使い始める前の関門(2026-11-05、利用者の指示「展開する前に、利用規約と I'm not a robot を表示」)。
 * 通るまで落とす場所を出さない。通ったかはセッションの印(api/html-host.php の gate が置く)で決める。
 * reCAPTCHA のスクリプト(Google)は、関門を出すときだけ読む
 */
$gateUntil = km_html_gate_until(is_array($_SESSION ?? null) ? $_SESSION : [], KM_LEGAL_UPDATED);
$gatePassed = $gateUntil > 0;
$gateReady = km_recaptcha_configured();
$gateArticle = array_values(array_filter(km_legal_terms(), static fn (array $section): bool => ($section['heading'] ?? '') === '第8条の2(HTML 展開サイト)'));

function km_html_page_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$limits = [
    'uploadBytes' => KM_HTML_MAX_UPLOAD_BYTES,
    'totalBytes' => KM_HTML_MAX_TOTAL_BYTES,
    'files' => KM_HTML_MAX_FILES,
    'pathMax' => KM_HTML_PATH_MAX,
    'depthMax' => KM_HTML_DEPTH_MAX,
    'versions' => KM_HTML_MAX_VERSIONS,
];
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTML 展開 | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <meta name="km-csrf" content="<?= km_html_page_e($csrf) ?>">
    <link rel="stylesheet" href="<?= km_html_page_e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= km_html_page_e(km_public_asset('Main/document.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        .km-html-main { max-width: 60rem; }
        .km-html-lead { color: var(--secondary); line-height: 1.7; margin: 0 0 1rem; }
        .km-html-drop {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            padding: 2rem 1rem;
            border: 2px dashed var(--secondary);
            border-radius: var(--radius-lg);
            background: color-mix(in srgb, var(--primary) 4%, transparent);
            text-align: center;
            transition: background 0.15s ease, border-color 0.15s ease;
        }
        .km-html-drop.is-over { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 12%, transparent); }
        .km-html-drop-title { font-size: 1.15rem; font-weight: 800; margin: 0; }
        .km-html-drop-note { margin: 0; color: var(--secondary); font-size: 0.9rem; }
        .km-html-buttons { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center; }
        .km-html-buttons .km-doc-link, .km-html-buttons .km-doc-button { margin: 0; }
        .km-html-file-input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .km-html-place { margin: 1rem 0 0; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-lg); }
        .km-html-place legend { font-weight: 700; padding: 0 0.3rem; }
        .km-html-place label { display: flex; gap: 0.5rem; align-items: baseline; margin: 0.35rem 0; line-height: 1.5; }
        .km-html-place select, .km-html-place input[type="text"] { font: inherit; padding: 0.3rem 0.5rem; border-radius: 8px; border: 1px solid var(--border); max-width: 100%; }
        .km-html-server-options { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; margin: 0.25rem 0 0 1.6rem; }
        .km-html-server-options label { white-space: nowrap; }
        /* display を決めたものも hidden で隠れるように */
        .km-html-server-options[hidden], .km-html-chooser[hidden], .km-html-section[hidden] { display: none; }
        .km-html-status { min-height: 1.6rem; margin: 1rem 0 0; line-height: 1.6; }
        .km-html-status.is-error { color: #b91c1c; font-weight: 700; }
        .km-html-chooser { margin: 1rem 0 0; padding: 0.75rem 1rem; border: 1px solid var(--primary); border-radius: var(--radius-lg); }
        .km-html-chooser label { display: block; margin: 0.3rem 0; overflow-wrap: anywhere; }
        .km-html-section { margin-top: 2rem; }
        .km-html-section h2 { font-size: 1.1rem; margin: 0 0 0.5rem; }
        .km-html-note { color: var(--secondary); font-size: 0.9rem; margin: 0 0 0.75rem; line-height: 1.6; }
        .km-html-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.6rem; }
        .km-html-item {
            padding: 0.75rem 0.9rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--glass-heavy);
        }
        .km-html-item.is-current { border-color: var(--primary); box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary) 30%, transparent); }
        .km-html-item-head { display: flex; flex-wrap: wrap; gap: 0.4rem 0.75rem; align-items: baseline; }
        .km-html-item-name { font-weight: 700; overflow-wrap: anywhere; }
        .km-html-item-meta { color: var(--secondary); font-size: 0.85rem; }
        .km-html-item-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; }
        .km-html-item-actions .km-doc-link, .km-html-item-actions .km-doc-button { margin: 0; min-height: 2.25rem; padding: 0.25rem 0.8rem; font-size: 0.85rem; }
        .km-html-versions { margin-top: 0.5rem; }
        .km-html-versions summary { cursor: pointer; color: var(--primary-dark); font-size: 0.9rem; }
        .km-html-versions ol { list-style: none; margin: 0.4rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.35rem; }
        .km-html-versions li { display: flex; flex-wrap: wrap; gap: 0.4rem 0.75rem; align-items: center; font-size: 0.85rem; }
        .km-html-empty { color: var(--secondary); font-size: 0.9rem; margin: 0; }
        .km-html-danger { color: #b91c1c; border-color: #b91c1c; }
        .km-html-current-badge { font-size: 0.75rem; font-weight: 700; color: var(--on-primary); background: var(--primary); border-radius: 999px; padding: 0.05rem 0.5rem; }
        .km-html-gate { padding: 1rem 1.1rem; border: 2px solid var(--primary); border-radius: var(--radius-lg); background: var(--glass-heavy); }
        .km-html-gate[hidden], .km-html-work[hidden] { display: none; }
        .km-html-gate > h2 { font-size: 1.15rem; margin: 0 0 0.5rem; }
        .km-html-terms { max-height: 18rem; overflow-y: auto; padding: 0.25rem 0.9rem; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--bg, transparent); line-height: 1.7; font-size: 0.92rem; }
        .km-html-terms .km-doc-section h2 { font-size: 1rem; margin: 0.6rem 0 0.4rem; }
        .km-html-terms ul { padding-left: 1.2rem; }
        .km-html-terms details { margin: 0.5rem 0 0.75rem; }
        .km-html-terms summary { cursor: pointer; color: var(--primary-dark); font-weight: 700; }
        .km-html-agree { display: flex; gap: 0.5rem; align-items: baseline; margin: 0.9rem 0; font-weight: 700; line-height: 1.5; }
        .km-html-agree input { width: 1.15rem; height: 1.15rem; flex: none; }
        .km-html-recaptcha { margin: 0 0 0.75rem; min-height: 78px; }
        .km-html-gate .km-html-status { margin: 0.5rem 0 0; }
        .km-html-password-input { display: block; width: 100%; max-width: 22rem; margin: 0.3rem 0 0; font: inherit; padding: 0.45rem 0.6rem; border-radius: 8px; border: 1px solid var(--border); }
    </style>
    <?php if (!$gatePassed && $gateReady): ?>
        <?php // 関門を出すときだけ Google の reCAPTCHA を読む(contact.php と同じ。方式で読む JS が違うので lib/recaptcha.php が決める) ?>
        <script id="km-html-recaptcha-js" src="<?= km_html_page_e(km_recaptcha_script_url()) ?>" async defer></script>
    <?php endif; ?>
    <?php // 配備のあとに「ページを更新してください」と出す(2026-11-02。lib/release.php・Main/release-watch.js) ?>
    <?= km_release_tags() ?>
</head>

<body>
    <header class="km-doc-header">
        <h1>HTML 展開</h1>
        <nav class="km-doc-nav">
            <a href="/" class="km-doc-link">地図を開く</a>
            <?php if ($signedIn): ?>
                <a href="/account.php" class="km-doc-link">アカウント</a>
            <?php else: ?>
                <a href="/sign-in.php?mode=signIn&amp;return=/html.php" class="km-doc-link">サインイン</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="km-doc-main km-html-main" id="km-html"
        data-html-origin="<?= km_html_page_e(km_site_html_origin()) ?>"
        data-api="/api/html-host.php"
        data-types="<?= km_html_page_e((string) json_encode(KM_HTML_TYPES, JSON_UNESCAPED_SLASHES)) ?>"
        data-limits="<?= km_html_page_e((string) json_encode($limits)) ?>"
        data-signed-in="<?= $signedIn ? '1' : '0' ?>"
        data-gate-until="<?= (int) $gateUntil ?>"
        data-recaptcha-src="<?= $gateReady ? km_html_page_e(km_recaptcha_script_url()) : '' ?>">
        <p class="km-html-lead">
            ZIP・HTML・CSS・JS(フォルダごとでも)を落とすと、<strong><?= km_html_page_e(km_site_html_origin()) ?></strong> の実際の URL で開けます。
            CSS・JS・画像は、HTML から相対パスで読めます。
            <?php if ($signedIn): ?>
                サインインしているので、サーバーにも保存できます(版 <?= KM_HTML_MAX_VERSIONS ?> まで残し、それより古い版は消えます)。
            <?php else: ?>
                ふだんはこのブラウザの中だけに置きます。<a href="/sign-in.php?mode=signIn&amp;return=/html.php">サインイン</a>すると、サーバーにも保存できます。
            <?php endif; ?>
        </p>

        <section class="km-html-gate" id="km-html-gate" aria-labelledby="km-html-gate-title"<?= $gatePassed ? ' hidden' : '' ?>>
            <h2 id="km-html-gate-title">使う前に —— 利用規約と確認</h2>
            <p class="km-html-note">
                展開する前に、下の利用規約(とくに第8条の2)を読んで同意し、「私はロボットではありません」にチェックを入れてください。
                確認は <?= intdiv(KM_HTML_GATE_TTL, 3600) ?> 時間、このブラウザで有効です。
            </p>
            <div class="km-html-terms" tabindex="0" role="region" aria-label="利用規約">
                <?php // エスケープは km_legal_render_sections() の中で済ませてある ?>
                <?= km_legal_render_sections($gateArticle) ?>
                <details>
                    <summary>利用規約の全文を読む</summary>
                    <?= km_legal_render_sections(km_legal_terms()) ?>
                </details>
            </div>
            <p class="km-html-note">別のタブで読む: <a href="/terms.php" target="_blank" rel="noopener">利用規約</a>・<a href="/privacy.php" target="_blank" rel="noopener">プライバシーポリシー</a></p>
            <label class="km-html-agree"><input type="checkbox" id="km-html-agree"> <span>利用規約に同意します</span></label>
            <?php if ($gateReady): ?>
                <?php // data-action はサーバーの照合(KM_RECAPTCHA_ACTION_HTML_HOST)と対。Enterprise だけが見る ?>
                <div class="g-recaptcha km-html-recaptcha" data-sitekey="<?= km_html_page_e(km_recaptcha_site_key()) ?>"
                     data-action="<?= km_html_page_e(KM_RECAPTCHA_ACTION_HTML_HOST) ?>"></div>
                <p class="km-html-note">確認のため、接続元の IP アドレスやブラウザの情報が Google(reCAPTCHA)へ送られます(<a href="/privacy.php" target="_blank" rel="noopener">プライバシーポリシー</a>の 7)。</p>
            <?php else: ?>
                <p class="km-html-status is-error">いま確認の仕組みが使えないため、始められません。管理者へお知らせください。</p>
            <?php endif; ?>
            <div class="km-html-buttons">
                <button type="button" class="km-doc-button" id="km-html-gate-ok"<?= $gateReady ? '' : ' disabled' ?>>同意して始める</button>
            </div>
            <p class="km-html-status" id="km-html-gate-status" role="status" aria-live="polite"></p>
        </section>

        <div class="km-html-work" id="km-html-work"<?= $gatePassed ? '' : ' hidden' ?>>
        <div class="km-html-drop" id="km-html-drop">
            <p class="km-html-drop-title">ここに落とす</p>
            <p class="km-html-drop-note">ZIP・HTML・CSS・JS・画像・フォルダ(<?= intdiv(KM_HTML_MAX_TOTAL_BYTES, 1024 * 1024) ?>MB・<?= KM_HTML_MAX_FILES ?> 個まで。サーバーへ送るときは、まとめて <?= intdiv(KM_HTML_MAX_UPLOAD_BYTES, 1024 * 1024) ?>MB まで)</p>
            <p class="km-html-drop-note">パスワード付きの ZIP(ZipCrypto・AES)も開けます。7z・RAR・tar・gz などは使えません(ZIP にしてください)。exe など、ページに使わないファイルは除きます。</p>
            <div class="km-html-buttons">
                <label class="km-doc-button" for="km-html-pick-files">ファイルを選ぶ</label>
                <label class="km-doc-link" for="km-html-pick-folder">フォルダを選ぶ</label>
            </div>
            <input class="km-html-file-input" type="file" id="km-html-pick-files" multiple>
            <input class="km-html-file-input" type="file" id="km-html-pick-folder" webkitdirectory multiple>
        </div>

        <fieldset class="km-html-place" id="km-html-place">
            <legend>置き場所</legend>
            <label><input type="radio" name="km-html-place" value="local" checked> <span>このブラウザ(このブラウザでだけ開けます。置けなければサーバーの一時フォルダに 24 時間置きます)</span></label>
            <label><input type="radio" name="km-html-place" value="server" id="km-html-place-server"<?= $signedIn ? '' : ' disabled' ?>> <span>サーバーに保存(サインインした本人だけが開けます。版 <?= KM_HTML_MAX_VERSIONS ?> まで)<?= $signedIn ? '' : ' —— サインインすると使えます' ?></span></label>
            <div class="km-html-server-options" id="km-html-server-options" hidden>
                <label>保存先 <select id="km-html-target"><option value="">新しいサイト</option></select></label>
                <label>名前 <input type="text" id="km-html-name" maxlength="100" placeholder="(落としたものの名前)"></label>
            </div>
        </fieldset>

        <?php // パスワード付きの ZIP。パスワードはこのブラウザの中だけで使う(Main/html-zip.js。サーバーへは送らない) ?>
        <fieldset class="km-html-chooser" id="km-html-password" hidden>
            <legend>この ZIP にはパスワードが付いています</legend>
            <p class="km-html-note">パスワードはこのブラウザの中で ZIP を開くのにだけ使い、サーバーへは送りません。</p>
            <label for="km-html-password-input">パスワード</label>
            <input type="password" id="km-html-password-input" class="km-html-password-input" autocomplete="off" spellcheck="false">
            <p class="km-html-status" id="km-html-password-message" role="status" aria-live="polite"></p>
            <div class="km-html-buttons">
                <button type="button" class="km-doc-button" id="km-html-password-ok">開く</button>
                <button type="button" class="km-doc-link" id="km-html-password-cancel">やめる</button>
            </div>
        </fieldset>

        <fieldset class="km-html-chooser" id="km-html-chooser" hidden>
            <legend>最初に開く HTML を選んでください</legend>
            <div id="km-html-chooser-list"></div>
            <div class="km-html-buttons">
                <button type="button" class="km-doc-button" id="km-html-chooser-ok">これで開く</button>
                <button type="button" class="km-doc-link" id="km-html-chooser-cancel">やめる</button>
            </div>
        </fieldset>
        </div>

        <?php // 一覧の操作(開く・消す)の結果も出すので、関門の間も見える所に置く ?>
        <p class="km-html-status" id="km-html-status" role="status" aria-live="polite"></p>

        <section class="km-html-section" aria-labelledby="km-html-local-title">
            <h2 id="km-html-local-title">このブラウザに置いたもの</h2>
            <p class="km-html-note">CSS・JS だけを落とすと、<strong>選んでいるもの</strong>に足します(同じ名前は差し替えます)。</p>
            <ul class="km-html-list" id="km-html-local"></ul>
        </section>

        <section class="km-html-section" aria-labelledby="km-html-temp-title" id="km-html-temp-section" hidden>
            <h2 id="km-html-temp-title">サーバーの一時フォルダ(24 時間で消えます)</h2>
            <ul class="km-html-list" id="km-html-temp"></ul>
        </section>

        <section class="km-html-section" aria-labelledby="km-html-server-title" id="km-html-server-section"<?= $signedIn ? '' : ' hidden' ?>>
            <h2 id="km-html-server-title">サーバーに保存したもの</h2>
            <p class="km-html-note" id="km-html-server-note"><?= $signedIn ? km_html_page_e(($displayName !== '' ? $displayName . ' さんの' : '') . 'サイトです。開けるのはご本人だけです。') : '' ?></p>
            <ul class="km-html-list" id="km-html-server"></ul>
        </section>

        <p class="km-html-note km-html-section">
            上げた HTML のスクリプトは、地図とは別のアドレス(<?= km_html_page_e(km_site_html_origin()) ?>)で動きます。
            人に見せるためのものではありません(開けるのは置いた人だけです)。使い方は<a href="/terms.php">利用規約</a>の第8条の2をご覧ください。
        </p>
    </main>
    <?php // ZIP を読む・作る・パスワード付きを解く(window.KmHtmlZip)。html-host.js より先に ?>
    <script defer src="<?= km_html_page_e(km_public_asset('Main/html-zip.js')) ?>"></script>
    <script defer src="<?= km_html_page_e(km_public_asset('Main/html-host.js')) ?>"></script>
</body>

</html>
