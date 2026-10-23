<?php

declare(strict_types=1);

/**
 * お知らせのくわしいページ(誰でも見られる公開ページ。2026-10-24)。中身は lib/notices.php。
 *
 *   /notice.php?id=<お知らせの id>   … そのお知らせ
 *   /notice.php                       … お知らせの一覧
 *
 * DB を使わないので、地図が止まっていても出る。id は形を確かめてから探す(知らなければ一覧へ)
 */

require_once __DIR__ . '/lib/notices.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';

km_csp_send('public');

$id = is_string($_GET['id'] ?? null) ? (string) $_GET['id'] : '';
$notice = km_notice_id_valid($id) ? km_notice_find($id) : null;
if ($id !== '' && $notice === null) {
    http_response_code(404);
}
$all = km_notices();
usort($all, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

function km_notice_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $notice !== null ? km_notice_e($notice['title']) . ' | ' : 'お知らせ | ' ?>高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_notice_e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= km_notice_e(km_public_asset('Main/document.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        .km-notice-date { color: var(--secondary); margin: 0 0 1rem; }
        .km-notice-summary {
            background: #fff;
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 1rem 1.1rem;
            line-height: 1.8;
        }
        .km-notice-section h2 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; }
        .km-notice-section p { line-height: 1.8; margin: 0 0 0.6rem; }
        .km-notice-list { list-style: none; padding: 0; }
        .km-notice-list li { margin-bottom: 0.75rem; }
    </style>
</head>

<body>
    <header class="km-doc-header">
        <h1><?= $notice !== null ? km_notice_e($notice['title']) : 'お知らせ' ?></h1>
        <nav class="km-doc-nav">
            <a href="/" class="km-doc-link">地図へ戻る</a>
            <?php if ($notice !== null): ?>
                <a href="/notice.php" class="km-doc-link">お知らせの一覧</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="km-doc-main">
        <?php if ($notice !== null): ?>
            <p class="km-notice-date"><?= km_notice_e(km_notice_date_label($notice['date'])) ?></p>
            <p class="km-notice-summary"><?= km_notice_e($notice['summary']) ?></p>
            <?php foreach ($notice['sections'] as $section): ?>
                <section class="km-notice-section">
                    <h2><?= km_notice_e($section['heading']) ?></h2>
                    <?php foreach ($section['paragraphs'] as $paragraph): ?>
                        <p><?= km_notice_e($paragraph) ?></p>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            <p><a class="km-doc-button" href="/contact.php">お問い合わせ</a></p>
        <?php else: ?>
            <?php if ($id !== ''): ?>
                <p>そのお知らせは見つかりませんでした。</p>
            <?php endif; ?>
            <ul class="km-notice-list">
                <?php foreach ($all as $item): ?>
                    <li>
                        <a href="/notice.php?id=<?= km_notice_e(rawurlencode($item['id'])) ?>"><?= km_notice_e($item['title']) ?></a>
                        <span class="km-notice-date">(<?= km_notice_e(km_notice_date_label($item['date'])) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</body>

</html>
