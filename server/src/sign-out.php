<?php

declare(strict_types=1);

require __DIR__ . '/logto-client.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/admin-log.php';

/*
 * **POST と CSRF トークンが揃ったときだけサインアウトする**(2026-09-14、診断 server-authz#3)。
 *
 * 以前は GET で即座にサインアウトしていた。セッション Cookie は SameSite=Lax なので、
 * 他サイトからのトップレベルの遷移でも Cookie が付く —— **罠のリンクを踏ませるだけで
 * 管理者を追い出し、地図の解除状態も消せた。**
 *
 * リンクは小さなフォームに替えた(index.php・account.php・admin の header.php と 403.php)。
 * 古いブックマークや手打ちの GET には、押せば出られるボタンだけの画面を返す
 * (勝手にはサインアウトしない。「押したのに何も起きない」にもしない)。
 */
/*
 * **テスト用アカウント(展示の PC)は、係の人の合言葉が要る**(2026-10-19、利用者の指示。lib/test-mode.php の「ログアウトの合言葉」)。
 * ボタンを隠すだけにしない —— ここで止める。確かめられないとき(DB の不調など)は印が無いとみなし、普段どおり出す
 * (km_test_account_is_marked は確かめられなければ false を返す。ログアウトは閉じ込めるより出られる方へ倒す)。
 */
require_once __DIR__ . '/lib/test-mode.php';
$kmTestSignout = false;
$kmTestPdo = null;
try {
    if ($client->isAuthenticated()) {
        $kmTestPdo = km_db();
        $kmTestSignout = km_test_account_is_marked($kmTestPdo, (string) ($client->getIdTokenClaims()->sub ?? ''));
    }
} catch (Throwable $exception) {
    error_log('sign-out.php: テスト用アカウントか確かめられません(普段どおり出します): ' . $exception->getMessage());
    $kmTestSignout = false;
}

/** 「押せば出られる」画面。テスト用アカウントには合言葉の欄を出す */
function km_signout_form_page(string $message, bool $askPin, int $status): never
{
    km_csp_send('public');
    header('Cache-Control: no-store');
    http_response_code($status);
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>サインアウト | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= $e(km_public_asset('Main/document.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        /* ボタンをリンクと同じ見た目にする(document.css の .km-doc-link は a 用) */
        button.km-doc-link { background: none; font: inherit; cursor: pointer; }
        .km-signout-pin { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin: 0.75rem 0; }
        .km-signout-pin input { font: inherit; padding: 0.4rem 0.6rem; border: 1px solid #94a3b8; border-radius: 6px; }
    </style>
</head>

<body>
    <header class="km-doc-header">
        <h1>サインアウト</h1>
        <nav class="km-doc-nav">
            <a href="/" class="km-doc-link">地図へ戻る</a>
        </nav>
    </header>
    <main class="km-doc-main">
        <section class="km-doc-section">
            <p><?= $e($message) ?></p>
            <form method="post" action="/sign-out.php">
                <?= km_csrf_field() ?>
                <?php if ($askPin): ?>
                    <label class="km-signout-pin">
                        <span>係の人の合言葉</span>
                        <input type="password" name="signout_pin" maxlength="32" autocomplete="off" required>
                    </label>
                <?php endif; ?>
                <button type="submit" class="km-doc-link">サインアウトする</button>
            </form>
        </section>
    </main>
</body>

</html>
<?php
    exit;
}

$kmTestPinMessage = 'これは展示用のアカウントです。サインアウトは係の人が合言葉を入れて行います。';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !km_csrf_verify()) {
    $postBack = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    // トークンが古い(別タブで入り直した等)。もう一度押せば新しいトークンで通る
    km_signout_form_page(
        $postBack ? 'ページの有効期限が切れていたため、サインアウトできませんでした。もう一度押してください。'
            : ($kmTestSignout ? $kmTestPinMessage : '下のボタンを押すとサインアウトします。'),
        $kmTestSignout,
        $postBack ? 403 : 200
    );
}

if ($kmTestSignout) {
    try {
        $kmPinHash = km_test_signout_pin_hash($kmTestPdo);
    } catch (Throwable $exception) {
        error_log('sign-out.php: 合言葉を読めません: ' . $exception->getMessage());
        $kmPinHash = '';
    }
    $kmDecision = km_test_signout_decide(
        $kmPinHash,
        (array) ($_SESSION[KM_TEST_SIGNOUT_FAIL_KEY] ?? []),
        (string) ($_POST['signout_pin'] ?? ''),
        time()
    );
    $_SESSION[KM_TEST_SIGNOUT_FAIL_KEY] = $kmDecision['fail'];
    if ($kmDecision['result'] !== 'ok') {
        $kmLeft = KM_TEST_SIGNOUT_MAX_FAILS - (int) $kmDecision['fail']['count'];
        km_signout_form_page(match ($kmDecision['result']) {
            'unset' => 'ログアウトの合言葉が決まっていないため、サインアウトできません。管理画面「テスト用アカウント」で合言葉を決めるか、このアカウントのテスト用の印を外してください。',
            'locked' => '合言葉を続けて間違えたため、しばらく受け付けません。10 分ほどたってからやり直してください。',
            default => "合言葉が違います(あと {$kmLeft} 回続けて間違えると、10 分受け付けなくなります)。",
        }, $kmDecision['result'] !== 'unset', $kmDecision['result'] === 'locked' ? 429 : 403);
    }
}
unset($_SESSION[KM_TEST_SIGNOUT_FAIL_KEY]);

/*
 * 監査ログに「誰が出たか」を残す(診断 server-ops#8)。**印を消す前に**組み立てる ——
 * signOut() のあとではトークンが無く、誰だったか分からない。
 * 読めなければ実行者なしで残す(サインアウト自体は止めない)。
 *
 * **サインインしていない人の POST は記録しない。** CSRF トークンは匿名のセッションでも
 * 上の画面から取れるので、記録すると、未認証の相手が GET と POST を繰り返すだけで
 * 監査ログを埋められる(api/logto-webhook.php が失敗の記録を間引いているのと同じ理由)。
 */
$signingOutUser = false;
try {
    if ($client->isAuthenticated()) {
        $signingOutUser = true;
        $claims = $client->getIdTokenClaims();
        $KM_USER = [
            'sub' => (string) ($claims->sub ?? ''),
            'name' => (string) ($claims->name ?? $claims->username ?? $claims->sub ?? ''),
        ];
    }
} catch (Throwable $exception) {
    error_log('sign-out.php: サインアウトする利用者を読み取れませんでした: ' . $exception->getMessage());
}
if ($signingOutUser) {
    km_admin_log_record('auth', 'logout');
}

/*
 * 管理系ポートのゲート(admin/api/gate.php)が持っている「通過してよい」の印を消す。
 *
 * SDK の signOut() は**自分が保存したトークンしか消さない**(LogtoClient.php を確認済み)。
 * この印を残すと、ログアウトしたのに phpMyAdmin / Mailpit / Logto 管理へ
 * **最大60秒のあいだ入れてしまう**。ログアウトは即座に効かせる。
 *
 * 拒否を1回だけ記録するための印(km_admin_denied_logged / km_gate_denied_logged)も消す。
 * 残すと、同じブラウザで別の人が入ったときの拒否が記録されない。
 */
unset($_SESSION['km_gate_ok'], $_SESSION['km_admin_logged']);
unset($_SESSION['km_admin_denied_logged'], $_SESSION['km_gate_denied_logged']);

/*
 * **地図の錠も閉め直す。** 教職員氏名・地図そのもののパスワード解除(km_map_unlocked)と、
 * 管理画面から来たときの印(km_map_admin)。残すと、共用の端末でサインアウトしても
 * **次の人が解除済みの地図を見られる**(security-review-2026-09-10 の 5)。
 */
// お試しの閲覧リンクの印(km_map_guest。2026-09-30)も同じ理由で外す
unset($_SESSION['km_map_unlocked'], $_SESSION['km_map_admin'], $_SESSION['km_map_guest']);
// 教職員の印(docs/15 段 D)も。共用の端末でサインアウトしたあとに教職員として見え続けないように
unset($_SESSION['km_map_teacher_until']);

$postLogoutRedirectUri = $appUrl . '/';
$location = $client->signOut($postLogoutRedirectUri);

/*
 * **Logto へは 302 で飛ばさない。ページを返し、そこから移る**(2026-09-17)。
 *
 * このページへはフォーム(POST)で来る。CSP の `form-action 'self'` は、ブラウザが**フォーム送信のあとの転送先にも**
 * 当てる(Chrome・Firefox)。Logto はポートが違う(:3001)ので別のオリジンになり、302 の転送が止められて
 * **ボタンを押しても画面がそのまま**になっていた(サーバー側ではトークンが消えているので、再読み込みすると出ている)。
 * form-action に Logto を足すより、転送をフォームの流れから外す方が狭い —— meta refresh の移動は form-action の対象外。
 * スクリプトを使わないので CSP の nonce も要らない。万一移らなくても、押せるリンクを置く。
 */
km_csp_send('public');
header('Cache-Control: no-store');
// Logto の URL にはクエリ(client_id・戻り先)が付く。Referer で次のページへ渡さない
header('Referrer-Policy: no-referrer');
$locationHtml = htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="0;url=<?= $locationHtml ?>">
    <title>サインアウト中 | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= htmlspecialchars(km_public_asset('Main/styles.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(km_public_asset('Main/document.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
</head>

<body>
    <header class="km-doc-header">
        <h1>サインアウトしています</h1>
    </header>
    <main class="km-doc-main">
        <section class="km-doc-section">
            <p>このサイトからはサインアウトしました。自動で切り替わらないときは、下のリンクを押してください。</p>
            <p><a href="<?= $locationHtml ?>" class="km-doc-link">サインアウトを完了する</a></p>
        </section>
    </main>
</body>

</html>
<?php
exit;
