<?php

declare(strict_types=1);

/**
 * お問い合わせ(誰でも使える公開ページ)。
 *
 * 送られた内容は km_form_submissions に入り、管理画面の受信箱(admin/mailbox.php)で読む。
 * 併せて通知メールを送るが、**メールの失敗で投稿を失わせない**(保存が先、送信が後)。
 *
 * スパム対策は3層。reCAPTCHA だけに頼らない:
 *   1. reCAPTCHA v2。**検証できなければ通さない**(到達不可も失敗と同じ扱い)
 *   2. 同じ IP からの30秒以内の連投を弾く(km_form_too_soon)
 *   3. 入力の検証(必須・メール形式・本文4000文字上限)
 *
 * **CSRF トークンは付けていない。** ここはセッションを持たない利用者が使う公開フォームで、
 * 守るべきセッション権限が無い(api/map-unlock.php でも同じ判断をしている)。
 * 「他人に成りすまして送らせる」ことを防ぐ役目は reCAPTCHA が負う。
 *
 * 日本語のみ — このホームページ側には元々言語切り替えが無い(フェーズ4の判断)。
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/forms.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/recaptcha.php';
require_once __DIR__ . '/lib/admin-log.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/user-error.php';

/*
 * このページだけ reCAPTCHA のぶんを開ける(google.com / gstatic.com と、
 * チェックボックス本体の iframe)。他のページは frame-src 'none' のまま。
 */
km_csp_send('contact');

function km_contact_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 件名の表示名。値の側は KM_FORM_SUBJECTS(lib/forms.php)と対で維持する。 */
const KM_CONTACT_SUBJECT_LABELS = [
    'bug' => '不具合の報告',
    'feature' => 'ご要望',
    'other' => 'その他',
];

$errors = [];
$sent = isset($_GET['sent']);

/** 入力し直しのときに値を戻すため、送信された内容を持っておく。 */
$input = [
    'name' => (string) ($_POST['name'] ?? ''),
    'email' => (string) ($_POST['email'] ?? ''),
    'subject' => (string) ($_POST['subject'] ?? 'other'),
    'body' => (string) ($_POST['body'] ?? ''),
];

$ready = km_recaptcha_configured();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!$ready) {
            // キーが無い = 検証できない。素通しにはしない
            throw new KmUserError('ただいま問い合わせを受け付けていません。');
        }

        /*
         * 1. まず reCAPTCHA。ここを通らなければ DB にも触れない。
         * 第3引数は下の `data-action` と対。**Enterprise ではトークンの action まで照合する**
         * (別のページや別のサイトで解かれたトークンを受けない。診断 critic#2)
         */
        km_recaptcha_verify(
            (string) ($_POST['g-recaptcha-response'] ?? ''),
            km_map_rate_limit_ip(),
            KM_RECAPTCHA_ACTION_CONTACT
        );

        $pdo = km_db();

        // 2. 連投の抑制
        if (km_form_too_soon($pdo)) {
            throw new KmUserError(
                '短い間隔での連続送信はできません。'
                . KM_FORM_MIN_INTERVAL_SECONDS . '秒ほど待ってからお試しください。'
            );
        }

        // 3. 入力の検証は km_form_save() の中で行われる(km_form_validate)
        $id = km_form_save($pdo, $input['name'], $input['email'], $input['subject'], $input['body']);
        $subjectLabel = KM_CONTACT_SUBJECT_LABELS[$input['subject']] ?? $input['subject'];
        km_admin_log_record('content', 'form.submit', $subjectLabel);

        /*
         * 通知メールはここから。**失敗しても投稿は成功として扱う**。
         * 受信箱には既に入っているので、届かないのはメールだけ。
         */
        try {
            km_mail_send(
                km_mail_admin_to(),
                '[KosenMap] お問い合わせ: ' . $subjectLabel,
                "公開ページのお問い合わせフォームから届きました。\n\n"
                . "お名前: {$input['name']}\n"
                . "メール: {$input['email']}\n"
                . "件名: {$subjectLabel}\n"
                . "受付番号: #{$id}\n\n"
                . $input['body']
            );
        } catch (Throwable $exception) {
            error_log('contact.php: 通知メールの送信に失敗しました(投稿は保存済み): ' . $exception->getMessage());
        }

        // PRG。再読み込みで二重投稿させない
        header('Location: /contact.php?sent=1', true, 302);
        exit;
    } catch (InvalidArgumentException $exception) {
        // 入力の検証(km_form_validate)。文面は利用者向けに書いてある
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        /*
         * **例外の文面をそのまま出さない。** 以前は getMessage() を表示しており、
         * DB が落ちると SQLSTATE や CA のパスが未認証の相手に見えていた(診断 server-ops#6)。
         * 利用者向けの文は KmUserError で投げてあり、それ以外は照合用 ID だけを出す。
         */
        $errors[] = km_user_error_message(
            $exception,
            '送信できませんでした。時間をおいてもう一度お試しください。'
        );
    }
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お問い合わせ | 高専マップ案内</title>

    <!-- 絶対パス。/favicon.svg は src/ 直下に置いてある。 -->
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_contact_e(km_public_asset('Main/styles.css')) ?>">
    <?php
    /*
     * 文書ページの外枠(body の打ち消し・見出し・戻る導線)は faq.php / terms.php /
     * privacy.php と共通なので Main/document.css に出してある。
     * ここに残すのは、このページにしか無いフォームの見た目だけ。
     */
    ?>
    <link rel="stylesheet" href="<?= km_contact_e(km_public_asset('Main/document.css')) ?>">
    <?php if ($ready): ?>
        <?php // 唯一の外部読み込み。reCAPTCHA は自前ホストできない ?>
        <?php // クラシックと Enterprise で読む JS が違う。判断は lib/recaptcha.php に集約してある ?>
        <script src="<?= km_contact_e(km_recaptcha_script_url()) ?>" async defer></script>
    <?php endif; ?>
    <style<?= km_csp_nonce_attr() ?>>
        /* 入力欄が並ぶページなので、読む文書より少し狭くする(km-doc-main は 46rem) */
        .c-main { max-width: 34rem; margin: 0 auto; padding: 1.5rem 1.25rem; }
        .c-card {
            background: #fff; border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm); padding: 1.5rem;
        }
        .c-field { margin-bottom: 1.1rem; }
        .c-field label { display: block; font-weight: 600; font-size: 0.9rem; margin-bottom: 0.35rem; }
        .c-field input, .c-field select, .c-field textarea {
            width: 100%; font: inherit; padding: 0.6rem 0.7rem;
            border: 1px solid #cbd5e1; border-radius: var(--radius-md); background: #fff; color: inherit;
        }
        .c-field textarea { resize: vertical; min-height: 9rem; }
        .c-field input:focus, .c-field select:focus, .c-field textarea:focus {
            outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary);
        }
        .c-hint { font-size: 0.8rem; color: var(--secondary); margin-top: 0.3rem; }
        .c-submit {
            width: 100%; font: inherit; font-weight: 700; cursor: pointer;
            padding: 0.75rem; border: 0; border-radius: var(--radius-md);
            background: var(--primary); color: #fff;
        }
        .c-submit:hover { background: var(--primary-dark); }
        .c-alert {
            border-radius: var(--radius-md); padding: 0.85rem 1rem;
            margin-bottom: 1.1rem; font-size: 0.9rem; line-height: 1.6;
        }
        .c-alert.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .c-alert.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .c-alert.info { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
        .g-recaptcha { margin-bottom: 1.1rem; }
    </style>
</head>

<body>
    <header class="km-doc-header">
        <h1>お問い合わせ</h1>
        <nav class="km-doc-nav">
            <a href="/faq.php" class="km-doc-link">よくある質問</a>
            <a href="/terms.php" class="km-doc-link">利用規約</a>
            <a href="/privacy.php" class="km-doc-link">プライバシーポリシー</a>
            <a href="/" class="km-doc-link">地図へ戻る</a>
        </nav>
    </header>

    <main class="c-main">
        <?php if ($sent): ?>
            <div class="c-alert ok">
                送信しました。ありがとうございます。<br>
                内容によっては返信までお時間をいただくことがあります。
            </div>
        <?php endif; ?>

        <?php foreach ($errors as $message): ?>
            <div class="c-alert err"><?= km_contact_e($message) ?></div>
        <?php endforeach; ?>

        <?php if (!$ready): ?>
            <div class="c-alert info">
                ただいまお問い合わせフォームを準備中です。恐れ入りますが、しばらくしてからお試しください。
            </div>
        <?php else: ?>
            <form method="post" class="c-card">
                <div class="c-field">
                    <label for="c-name">お名前</label>
                    <input type="text" id="c-name" name="name" maxlength="255" required
                           value="<?= km_contact_e($input['name']) ?>">
                </div>
                <div class="c-field">
                    <label for="c-email">メールアドレス</label>
                    <input type="email" id="c-email" name="email" maxlength="255" required
                           value="<?= km_contact_e($input['email']) ?>">
                    <p class="c-hint">返信先としてのみ使います。</p>
                </div>
                <div class="c-field">
                    <label for="c-subject">件名</label>
                    <select id="c-subject" name="subject">
                        <?php foreach (KM_CONTACT_SUBJECT_LABELS as $value => $label): ?>
                            <option value="<?= km_contact_e($value) ?>"<?= $input['subject'] === $value ? ' selected' : '' ?>>
                                <?= km_contact_e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="c-field">
                    <label for="c-body">お問い合わせ内容</label>
                    <textarea id="c-body" name="body" maxlength="<?= (int) KM_FORM_BODY_MAX ?>"
                              required><?= km_contact_e($input['body']) ?></textarea>
                    <p class="c-hint"><?= (int) KM_FORM_BODY_MAX ?>文字まで。</p>
                </div>

                <?php // data-action はサーバーの照合(KM_RECAPTCHA_ACTION_CONTACT)と対。Enterprise だけが見る ?>
                <div class="g-recaptcha" data-sitekey="<?= km_contact_e(km_recaptcha_site_key()) ?>"
                     data-action="<?= km_contact_e(KM_RECAPTCHA_ACTION_CONTACT) ?>"></div>

                <?php
                /*
                 * **送信ボタンの手前に置く。** 何が送られ、どう扱われるかを、
                 * 送る前に読める位置に出す。reCAPTCHA を通す時点で Google へ
                 * 接続元の情報が渡るので、それも含めてポリシー側に書いてある。
                 */
                ?>
                <p class="c-hint">
                    送信すると、<a href="/terms.php">利用規約</a>と<a href="/privacy.php">プライバシーポリシー</a>に同意したものとみなします。
                </p>

                <button type="submit" class="c-submit">送信する</button>
            </form>
        <?php endif; ?>
    </main>
</body>

</html>
