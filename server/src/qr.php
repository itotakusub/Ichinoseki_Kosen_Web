<?php

declare(strict_types=1);

/**
 * QR のリンク集(誰でも見られる公開ページ。2026-10-24、利用者の指示)。
 * 利用者が https://ito4.jp/qr.php の QR を作って配っている。並べるリンクは管理画面の「QR のリンク集」(admin/qr-links.php)で決める。
 *
 * 公開ページなので fail open(faq.php と同じ): DB に繋がらなくてもページは出し、地図へのリンクだけは必ず出す。
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/qr-links.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';

km_csp_send('public');

$links = [];
$unavailable = false;
try {
    $links = km_qr_links_public(km_db());
} catch (Throwable $exception) {
    error_log('qr.php (public) failed (fail open): ' . $exception->getMessage());
    $unavailable = true;
}

function km_qr_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>リンク集 | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_qr_e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= km_qr_e(km_public_asset('Main/document.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        /* スマホで QR から開く前提。指で押しやすい大きさのカードを縦に並べる */
        .km-qr-links { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.75rem; }
        .km-qr-link {
            display: block;
            padding: 1rem 1.1rem;
            border-radius: var(--radius-lg);
            background: #fff;
            box-shadow: var(--shadow-sm);
            color: var(--text);
            text-decoration: none;
            border: 1px solid rgba(148, 163, 184, 0.3);
        }
        .km-qr-link:hover, .km-qr-link:focus-visible { border-color: var(--primary); }
        .km-qr-link-title { display: block; font-size: 1.1rem; font-weight: 800; color: var(--primary-dark); }
        .km-qr-link-desc { display: block; margin-top: 0.3rem; color: var(--secondary); line-height: 1.6; white-space: pre-wrap; }
        .km-qr-link-url { display: block; margin-top: 0.3rem; font-size: 0.8rem; color: var(--secondary); overflow-wrap: anywhere; }
    </style>
</head>

<body>
    <header class="km-doc-header">
        <h1>リンク集</h1>
        <nav class="km-doc-nav">
            <a href="/" class="km-doc-link">地図を開く</a>
        </nav>
    </header>

    <main class="km-doc-main">
        <ul class="km-qr-links">
            <?php /* 地図はいつも先頭(管理画面で何も登録していなくても、DB が止まっていても行き先がある) */ ?>
            <li>
                <a class="km-qr-link" href="/">
                    <span class="km-qr-link-title">📍 高専マップ案内</span>
                    <span class="km-qr-link-desc">校内の地図。行きたい場所を探して、道順を案内します。</span>
                </a>
            </li>
            <?php foreach ($links as $link): ?>
                <?php $external = !str_starts_with($link['url'], '/'); ?>
                <li>
                    <?php /* URL は lib/qr-links.php が http / https かサイトの中の道だけに確かめてある */ ?>
                    <a class="km-qr-link" href="<?= km_qr_e($link['url']) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <span class="km-qr-link-title"><?= km_qr_e($link['title']) ?><?= $external ? ' ↗' : '' ?></span>
                        <?php if ($link['description'] !== ''): ?>
                            <span class="km-qr-link-desc"><?= km_qr_e($link['description']) ?></span>
                        <?php endif; ?>
                        <?php if ($external): ?>
                            <?php /* よそのサイトへ行くときは、行き先を見せる(押す前に分かるように) */ ?>
                            <span class="km-qr-link-url"><?= km_qr_e(preg_replace('#^https?://#', '', $link['url'])) ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($unavailable): ?>
            <p class="km-doc-note">ほかのリンクを、ただいま読み込めません。しばらくしてからもう一度お試しください。</p>
        <?php endif; ?>
    </main>
</body>

</html>
