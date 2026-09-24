<?php

declare(strict_types=1);

/**
 * 確認コードの橋渡しページ(誰でも開ける公開ページ)。
 *
 * Logto が送るメールに `…/verify.php?code={{code}}` を載せておくと、
 * 開いたときに6桁を大きく表示し、ワンタップでコピーできる。手で書き写す手間と
 * 打ち間違いを無くすためのページ。
 *
 * ## このページで**できないこと**(意図的)
 *
 * **押すだけではサインインは完了しない。** Logto のコード検証はサインイン中の
 * セッション(interaction)に紐づいていて、別のタブで開いたこのページからは完了させられない。
 * 実装を確認した事実:
 *
 *   - `{{code}}` は本文のどこでも置換される(connector-smtp の replaceSendMessageHandlebars)
 *   - **`{{link}}` は置換されない** — 型にはあるが core が payload に入れていないため、
 *     テンプレートに書くと `{{link}}` の文字がそのまま届く。だから使っていない
 *   - サインイン画面はクエリからコードを受け取らない
 *
 * そのため「**元のタブに戻って貼り付けてください**」と案内する。
 * **サインイン画面を開くボタンは置かない** — 押すと新しい interaction が始まり、
 * いま待っている画面のコードが無効になりうる。
 *
 * ## 扱いの原則
 *
 * **コードは保存も検証もしない。** 検証するのは Logto で、ここは表示するだけ。
 * 監査ログにも残さない(短命であるべき秘密を、こちらで長生きさせない)。
 * 同じ理由で、nginx 側でもこのページのクエリ文字列をアクセスログに書かないようにしてある
 * (nginx/default.conf.template の km_no_query ログ形式)。
 */

require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';

km_csp_send('public');

/*
 * このページの URL には確認コードが入る。他所へ渡さないよう、参照元の送出を止める。
 * 9443 全体には strict-origin-when-cross-origin が付いているが、ここはさらに強くする。
 */
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function km_verify_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
 * 受け付けるのは6桁の数字だけ。配列で渡されても壊れないよう型から確かめる
 * (1.0.0 の確認で `?slug[]=x` が警告を出した件と同じ用心)。
 */
$codeParam = $_GET['code'] ?? '';
$code = is_string($codeParam) && preg_match('/^\d{6}$/', $codeParam) === 1 ? $codeParam : null;
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>確認コード | 高専マップ案内</title>

    <!-- 配色や角丸などの変数を地図側と共有する -->
    <!-- 絶対パス。/favicon.svg は src/ 直下に置いてある。 -->
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_verify_e(km_public_asset('Main/styles.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        /* Main/styles.css は全画面の地図前提で body を固定しているので、その2点だけ打ち消す */
        body { overflow: auto; height: auto; min-height: 100vh; padding: 0 0 4rem; }

        .v-header {
            background: var(--glass-heavy);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1rem 1.25rem;
            display: flex; align-items: center; gap: 0.75rem;
            position: sticky; top: 0; z-index: 10;
        }
        .v-header h1 { font-size: 1.15rem; font-weight: 700; margin: 0; }

        .v-main { max-width: 30rem; margin: 0 auto; padding: 2rem 1.25rem; }
        .v-card {
            background: #fff; border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm); padding: 2rem 1.5rem; text-align: center;
        }

        /* コードは離れていても読めるだけの大きさにする。数字は等幅で桁を揃える */
        .v-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: clamp(2.5rem, 12vw, 3.5rem);
            font-weight: 800;
            letter-spacing: 0.15em;
            color: var(--primary-dark);
            margin: 0.5rem 0 1.25rem;
            user-select: all;   /* 長押し・ダブルクリックで一気に選べる */
        }

        .v-copy {
            width: 100%; font: inherit; font-weight: 700; cursor: pointer;
            padding: 0.85rem; border: 0; border-radius: var(--radius-md);
            background: var(--primary); color: #fff;
        }
        .v-copy:hover { background: var(--primary-dark); }
        .v-copied { color: var(--success); font-weight: 700; font-size: 0.9rem; min-height: 1.4rem; margin-top: 0.6rem; }

        .v-note {
            font-size: 0.9rem; color: var(--secondary); line-height: 1.7;
            margin: 1.5rem 0 0; text-align: left;
        }
        .v-note strong { color: #1e293b; }
        /* 見出し代わりの1行。style 属性は CSP で使えないのでクラスにしている */
        .v-note.is-lead { text-align: center; margin: 0; }

        .v-error {
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
            border-radius: var(--radius-md); padding: 1rem; line-height: 1.7;
        }

        .v-back {
            display: inline-block; margin-top: 1.5rem;
            color: var(--primary-dark); text-decoration: none;
            font-size: 0.9rem; font-weight: 600;
            padding: 0.45rem 0.9rem;
            border-radius: var(--radius-md); border: 1px solid var(--primary);
        }
        .v-back:hover { background: var(--primary); color: #fff; }
    </style>
</head>

<body>
    <header class="v-header">
        <h1>確認コード</h1>
    </header>

    <main class="v-main">
        <?php if ($code === null): ?>
            <div class="v-error">
                コードを読み取れませんでした。<br>
                メールに書かれている<strong>6桁の数字</strong>を、サインイン画面に直接入力してください。
            </div>
        <?php else: ?>
            <div class="v-card">
                <p class="v-note is-lead">この番号を入力してください</p>
                <p class="v-code" id="km-code"><?= km_verify_e($code) ?></p>

                <button type="button" class="v-copy" id="km-copy">コードをコピー</button>
                <p class="v-copied" id="km-copied" role="status" aria-live="polite"></p>

                <p class="v-note">
                    <strong>このページを開いただけでは、サインインは完了しません。</strong>
                    コードをコピーしたら、<strong>サインインを始めた元の画面(タブ)に戻って</strong>
                    貼り付けてください。<br>
                    このページから開き直すと、待っている画面のコードが使えなくなることがあります。
                </p>
            </div>
        <?php endif; ?>

        <a href="/" class="v-back">地図へ戻る</a>
    </main>

    <script<?= km_csp_nonce_attr() ?>>
        (() => {
            const code = document.getElementById('km-code');
            const button = document.getElementById('km-copy');
            const message = document.getElementById('km-copied');
            if (!code || !button) return;

            button.addEventListener('click', async () => {
                const text = code.textContent.trim();
                try {
                    // HTTPS なので使える。使えない環境(古い端末など)では下の選択に落とす
                    await navigator.clipboard.writeText(text);
                    message.textContent = 'コピーしました';
                } catch {
                    /*
                     * 失敗したときに黙って何も起きないと「壊れている」と見える。
                     * せめて選択状態にして、利用者が自分でコピーできるようにする。
                     */
                    const range = document.createRange();
                    range.selectNodeContents(code);
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                    message.textContent = 'コピーできませんでした。選択したのでコピーしてください';
                }
            });
        })();
    </script>
</body>

</html>
