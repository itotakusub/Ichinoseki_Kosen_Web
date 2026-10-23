<?php

declare(strict_types=1);

/**
 * お知らせのくわしいページ(誰でも見られる公開ページ。2026-10-24)。中身は管理画面「お知らせ」(admin/notices.php・lib/notices.php)。
 *
 *   /notice.php?id=<番号>   … そのお知らせ(公開しているものだけ。下書きは出さない)
 *   /notice.php             … お知らせの一覧
 *
 * 公開ページなので fail open(faq.php と同じ): DB に繋がらなくてもページは出し、「いま読み込めない」と伝える
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/notices.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';

km_csp_send('public');

$id = is_string($_GET['id'] ?? null) ? (string) $_GET['id'] : '';
$all = [];
$unavailable = false;
try {
    $all = km_notices_public(km_db(), km_notice_today());
} catch (Throwable $exception) {
    error_log('notice.php (public) failed (fail open): ' . $exception->getMessage());
    $unavailable = true;
}
$notice = null;
if (km_notice_id_valid($id)) {
    foreach ($all as $item) {
        if ($item['id'] === (int) $id) {
            $notice = $item;
        }
    }
}
if ($id !== '' && $notice === null && !$unavailable) {
    http_response_code(404);
}

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
        <?= KM_NOTICE_DETAIL_CSS ?>
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
        <?php if ($unavailable): ?>
            <p>ただいまお知らせを読み込めません。しばらくしてからもう一度お試しください。</p>
        <?php elseif ($notice !== null): ?>
            <?php /* 中身は lib/notices.php の km_notice_detail_html(管理画面のプレビューと同じ。文字は全部エスケープ済み) */ ?>
            <?= km_notice_detail_html($notice) ?>
            <p><a class="km-doc-button" href="/contact.php">お問い合わせ</a></p>
        <?php else: ?>
            <?php if ($id !== ''): ?>
                <p>そのお知らせは見つかりませんでした。</p>
            <?php endif; ?>
            <?php if ($all === []): ?>
                <p>いまお知らせはありません。</p>
            <?php endif; ?>
            <ul class="km-notice-list">
                <?php foreach ($all as $item): ?>
                    <li>
                        <a href="/notice.php?id=<?= (int) $item['id'] ?>"><?= km_notice_e($item['title']) ?></a>
                        <span class="km-notice-date">(<?= km_notice_e(km_notice_date_label($item['date'])) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</body>

</html>
