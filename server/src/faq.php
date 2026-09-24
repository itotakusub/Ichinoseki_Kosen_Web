<?php

declare(strict_types=1);

/**
 * よくある質問(誰でも見られる公開ページ)。
 *
 * 管理画面(admin/faq.php)で「公開」を付けた質問だけを出す。
 *
 * 公開ページなので index.php と同じ **fail open** の姿勢にする: DB に繋がらなくても
 * ページ自体は出し、「いま読み込めない」と伝えるだけにする(管理画面の fail closed とは逆)。
 * 日本語のみ — このホームページ側には元々言語切り替えが無い(フェーズ4の判断)。
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/faq.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';

// 出力より前に送る。公開ページは AdminLTE も外部サービスも使わないので一番狭い方針でよい
km_csp_send('public');

$items = [];
$unavailable = false;
try {
    $items = km_faq_all(km_db(), true);
} catch (Throwable $exception) {
    error_log('faq.php (public) failed (fail open): ' . $exception->getMessage());
    $unavailable = true;
}

function km_faq_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>よくある質問 | 高専マップ案内</title>

    <!-- 配色や角丸などの変数を地図側と共有する -->
    <!-- 絶対パス。/favicon.svg は src/ 直下に置いてある。 -->
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_faq_e(km_public_asset('Main/styles.css')) ?>">
    <?php
    /*
     * 文書ページの外枠(body の打ち消し・見出し・戻る導線)は terms.php /
     * privacy.php と共通なので Main/document.css に出してある。
     * ここに残すのは、このページにしか無い「質問の開閉」の見た目だけ。
     */
    ?>
    <link rel="stylesheet" href="<?= km_faq_e(km_public_asset('Main/document.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        .faq-item {
            background: #fff;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 0.85rem;
            overflow: hidden;
        }

        .faq-q {
            width: 100%;
            text-align: left;
            background: none;
            border: 0;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            padding: 1rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: inherit;
        }

        .faq-q::before { content: "Q"; color: var(--primary); font-weight: 800; }
        .faq-q[aria-expanded="true"] { color: var(--primary-dark); }

        .faq-a {
            padding: 0 1.1rem 1.1rem 2.5rem;
            color: #334155;
            line-height: 1.7;
            white-space: pre-wrap;
        }

        .faq-a[hidden] { display: none; }

        .faq-empty {
            text-align: center;
            color: var(--secondary);
            padding: 3rem 1rem;
        }
    </style>
</head>

<body>
    <header class="km-doc-header">
        <h1>よくある質問</h1>
        <nav class="km-doc-nav">
            <a href="/contact.php" class="km-doc-link">お問い合わせ</a>
            <a href="/terms.php" class="km-doc-link">利用規約</a>
            <a href="/privacy.php" class="km-doc-link">プライバシーポリシー</a>
            <a href="/" class="km-doc-link">地図へ戻る</a>
        </nav>
    </header>

    <main class="km-doc-main">
        <?php if ($unavailable): ?>
            <p class="faq-empty">ただいま読み込めません。しばらくしてからもう一度お試しください。</p>
        <?php elseif ($items === []): ?>
            <p class="faq-empty">公開されている質問はまだありません。</p>
        <?php else: ?>
            <?php foreach ($items as $i => $item): ?>
                <?php $answerId = 'faq-a-' . (int) $item['id']; ?>
                <div class="faq-item">
                    <button
                        type="button"
                        class="faq-q"
                        aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>"
                        aria-controls="<?= km_faq_e($answerId) ?>"
                    ><?= km_faq_e((string) $item['question']) ?></button>
                    <?php
                    /*
                     * **タグと出力の間に空白を入れない。**
                     *
                     * `.faq-a` は `white-space: pre-wrap`(答えの改行を残すため)。
                     * 改行して字下げすると、**その空白がそのまま画面に出る** ——
                     * 実際に、答えの前に空行と空白20個ぶんの字下げが出ていた
                     * (利用者が「仕様ですか」と指摘)。
                     */
                    ?>
                    <div class="faq-a" id="<?= km_faq_e($answerId) ?>" <?= $i === 0 ? '' : 'hidden' ?>><?= km_faq_e((string) $item['answer']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <script<?= km_csp_nonce_attr() ?>>
        // 開閉だけ。ライブラリは使わない(このページのために bootstrap を持ち込む理由が無い)
        document.querySelectorAll('.faq-q').forEach((button) => {
            button.addEventListener('click', () => {
                const open = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', String(!open));
                document.getElementById(button.getAttribute('aria-controls')).hidden = open;
            });
        });
    </script>
</body>

</html>
