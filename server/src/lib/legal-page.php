<?php

declare(strict_types=1);

/**
 * 利用規約・プライバシーポリシーの**ページの外枠**。
 *
 * 中身は lib/legal.php、見た目は Main/document.css。ここは「両ページで同じもの」だけ
 * —— <head>、見出し、戻る導線、未記入の警告、脚注。
 *
 * **2ページで別々に書かない。** 片方だけ favicon を足したり CSP を直したりして、
 * もう片方が古いままになる形にしない(実際、公開ページの favicon でそれをやった)。
 *
 * ## DB が落ちていても本文は出る
 *
 * 本文はコードにあるので、**何が落ちていても必ず出る。**
 * 規約とポリシーが読めない状態は、それ自体が問題になる。
 *
 * 管理画面から足した章(`km_legal_sections`)だけは DB から読む。
 * **読めなければ、その章を出さずに本文だけ出す** —— 落とすのは追記の方で、
 * 土台は落とさない。
 */

require_once __DIR__ . '/legal.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/csp.php';

/**
 * @param string $title  <title> と <h1> に出す名前
 * @param array<int, array> $sections lib/legal.php が返す節
 * @param string $document 追記の章を引く文書 ('terms' / 'privacy')
 */
function km_legal_page(string $title, array $sections, string $document = ''): void
{
    // 出力より前に送る。この2ページは自前の CSS しか読まないので一番狭い方針でよい
    km_csp_send('public');

    $placeholders = km_legal_placeholders();

    /*
     * 管理画面から足した章。**本文の後ろに並べる。**
     *
     * DB が読めなければ黙って足さない —— ここで止めると、
     * **規約そのものが読めなくなる**方が重い。
     */
    $extraSections = [];
    $extraUpdatedAt = null;
    if ($document !== '') {
        try {
            require_once __DIR__ . '/legal-db.php';
            require_once __DIR__ . '/db.php';
            $pdo = km_db();
            $extraSections = km_legal_db_to_sections(km_legal_db_all($pdo, $document, true));
            $extraUpdatedAt = km_legal_db_latest_update($pdo, $document);
        } catch (Throwable $exception) {
            error_log('legal-page.php extra sections skipped: ' . $exception->getMessage());
        }
    }

    /*
     * 改定日は**新しい方**を出す。章を足したのに日付が古いままだと、
     * 読む人は「前と同じ文書だ」と思って読み飛ばす。
     */
    $updated = KM_LEGAL_UPDATED;
    if ($extraUpdatedAt !== null) {
        $extraDate = date('Y-m-d', $extraUpdatedAt);
        if ($extraDate > $updated) {
            $updated = $extraDate;
        }
    }
    ?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= km_legal_e($title) ?> | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <?php // 配色と角丸は地図側と共有する。document.css は styles.css の**後**に読むこと ?>
    <link rel="stylesheet" href="<?= km_legal_e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= km_legal_e(km_public_asset('Main/document.css')) ?>">
</head>

<body>
    <header class="km-doc-header">
        <h1><?= km_legal_e($title) ?></h1>
        <nav class="km-doc-nav">
            <a href="/terms.php" class="km-doc-link">利用規約</a>
            <a href="/privacy.php" class="km-doc-link">プライバシーポリシー</a>
            <a href="/" class="km-doc-link">地図へ戻る</a>
        </nav>
    </header>

    <main class="km-doc-main">
        <?php if ($placeholders !== []): ?>
            <?php
            /*
             * **未記入のまま公開されていることを隠さない。**
             *
             * 運営者名も窓口も空の規約は、読む人にとっては「誰との約束なのか
             * 分からない文書」でしかない。黙って出すと、埋め忘れたことに誰も気づかない。
             * 埋まれば自動的に消える。
             */
            ?>
            <div class="km-doc-warning" role="alert">
                <strong>この文書はまだ準備中です。</strong>
                次の項目が未記入です: <?= km_legal_e(implode(' / ', $placeholders)) ?>。
                <code>src/lib/legal.php</code> の <code>KM_LEGAL_OPERATOR</code> を埋めてください。
            </div>
        <?php endif; ?>

        <p class="km-doc-updated">最終改定日: <?= km_legal_e($updated) ?></p>

        <?php // 本文。エスケープは km_legal_render_sections() の中で済ませてある ?>
        <?= km_legal_render_sections($sections) ?>
        <?php
        /*
         * 管理画面から足した章。**同じ描画を通す** ——
         * 別の描き方をすると、`**強調**` の効き方や見出しの出方が本文とずれる。
         */
        ?>
        <?= km_legal_render_sections($extraSections) ?>
    </main>

    <footer class="km-doc-footer">
        <p>
            この文書についてのお問い合わせは
            <a href="<?= km_legal_e((string) (KM_LEGAL_OPERATOR['contactUrl'] ?? '/contact.php')) ?>">お問い合わせフォーム</a>
            からお願いします。
        </p>
    </footer>
</body>

</html>
    <?php
}
