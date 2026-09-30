<?php

declare(strict_types=1);

/**
 * お試しの閲覧リンクの受け口(lib/map-guest.php。2026-09-30)。
 *
 *   GET  /guest.php?t=<トークン>      仮アカウントを作る欄と、再入場の欄を出すだけ。**リンクを使わない**
 *   POST action=create(名前・所属)  1 台分を使って仮アカウントを作り、再入場コードを 1 回だけ見せる
 *   POST action=reenter(コード)     作った仮アカウントで入り直す(台数は使わない)
 *
 * GET で使わないのは、メッセージアプリのリンクの下見(プレビュー)が自動で取りに来るため。
 * 仮アカウントは MariaDB にだけ作る(Logto には作らない。利用者の指示)。
 *
 * URL にトークンが載るので、nginx はこのページのクエリをログに残さず(km_no_query)、
 * 参照元も渡さない(Referrer-Policy: no-referrer。PHP と nginx で同じ値)。
 */

require_once __DIR__ . '/lib/session.php';
km_session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/map-guest.php';
require_once __DIR__ . '/lib/map-rate-limit.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/admin-log.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
km_csp_send('public');

function km_guest_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const KM_GUEST_UNUSABLE = 'このリンクは使えません(期限切れか、取り消し済みです)。';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $method === 'POST' ? (string) ($_POST['action'] ?? '') : '';
$token = (string) ($method === 'POST' ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$message = null;      // 使えないときの理由(ページ全体)
$formError = null;    // 入力の誤り(欄の上)
$link = null;
$created = null;      // 作ったばかりの仮アカウント(再入場コードを 1 回だけ見せる)

try {
    $pdo = km_db();

    if ($method === 'POST' && !km_csrf_verify()) {
        $message = 'ページの有効期限が切れました。受け取ったリンクをもう一度開いてください。';
    } elseif ($action === 'create') {
        try {
            $account = km_map_guest_create_account(
                $pdo,
                $token,
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['affiliation'] ?? '')
            );
        } catch (InvalidArgumentException $exception) {
            $account = false;
            $formError = $exception->getMessage();
        }
        if ($account === null) {
            $formError = 'このリンクでは、もう仮アカウントを作れません(使える台数を超えたか、期限切れ・取り消し済みです)。';
        } elseif (is_array($account)) {
            // 権限が上がる瞬間にセッション ID を作り直す(セッション固定への対策。api/map-unlock.php と同じ)
            session_regenerate_id(true);
            km_map_guest_grant($account['linkId'], $account['accountId'], $account['expiresAt'], $account['name'], $account['names']);
            // 名前は記録に写さない(管理画面の一覧で見られる)。番号だけ
            km_admin_log_record('content', 'guest.account_create', '#' . $account['linkId'] . ' / ' . $account['accountId']);
            $created = $account;
        }
    } elseif ($action === 'reenter') {
        // コードの総当たりを絞る(地図のパスワードと同じ数え方。錠 'guest')
        if (!km_map_unlock_attempt($pdo, 'guest')) {
            $formError = '試した回数が多すぎます。しばらく待ってからやり直してください。';
        } else {
            $account = km_map_guest_reenter($pdo, $token, (string) ($_POST['code'] ?? ''));
            if ($account === null) {
                $formError = '再入場コードが違うか、この仮アカウントは止められています。';
            } else {
                km_map_clear_unlock_failures($pdo, 'guest');
                session_regenerate_id(true);
                km_map_guest_grant($account['linkId'], $account['accountId'], $account['expiresAt'], $account['name'], $account['names']);
                km_admin_log_record('content', 'guest.account_reenter', '#' . $account['linkId'] . ' / ' . $account['accountId']);
                header('Location: /', true, 303);
                exit;
            }
        }
    }

    if ($created === null && $message === null) {
        $link = km_map_guest_peek($pdo, $token);
        if ($link === null) {
            $message = KM_GUEST_UNUSABLE;
        }
    }
} catch (Throwable $exception) {
    error_log('guest.php failed: ' . $exception->getMessage());
    $message = '現在利用できません。時間をおいてもう一度お試しください。';
    $link = null;
    $created = null;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>お試しの閲覧 | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_guest_e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= km_guest_e(km_public_asset('Main/document.css')) ?>">
</head>

<body>
    <header class="km-doc-header">
        <h1>お試しの閲覧</h1>
        <nav class="km-doc-nav">
            <a href="/" class="km-doc-link">地図へ</a>
        </nav>
    </header>

    <main class="km-doc-main">
        <?php if ($created !== null): ?>
            <h2><?= km_guest_e($created['name']) ?> さんの仮アカウントを作りました</h2>
            <p>
                <strong><?= km_guest_e(date('Y/m/d H:i', $created['expiresAt'])) ?></strong> まで、このブラウザで
                地図をパスワード無しで見られます。
            </p>
            <p>教員の地点の名前は、運営者があなたの仮アカウントに許可すると見えるようになります(地図を開き直すと反映されます)。</p>
            <p>ブラウザを閉じたり別の端末で見たりするときは、同じリンクを開いて次の<strong>再入場コード</strong>を入れてください。</p>
            <p class="km-doc-code"><?= km_guest_e(km_map_guest_format_code($created['code'])) ?></p>
            <p class="km-doc-updated">このコードは<strong>この画面でしか表示しません。</strong>メモするか、画面を保存してください。</p>
            <p><a href="/" class="km-doc-button">地図を開く</a></p>
        <?php elseif ($link !== null): ?>
            <p>
                このリンクでは、仮アカウントを作ると <strong><?= km_guest_e(date('Y/m/d H:i', $link['expiresAt'])) ?></strong> まで、
                このブラウザで地図をパスワード無しで見られます。教員の地点の名前は、運営者が許可した仮アカウントだけに表示されます。
                入れた名前は、誰が見ているかを運営者が確かめるためだけに使い、期限の 30 日後に消えます。
            </p>
            <?php if ($formError !== null): ?>
                <div class="km-doc-warning" role="alert"><?= km_guest_e($formError) ?></div>
            <?php endif; ?>

            <?php if ($link['usesLeft'] > 0): ?>
                <h2>仮アカウントを作る</h2>
                <form method="post" action="/guest.php" class="km-doc-form">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="t" value="<?= km_guest_e($token) ?>">
                    <input type="hidden" name="action" value="create">
                    <label>名前(必須)
                        <input type="text" name="name" maxlength="<?= (int) KM_MAP_GUEST_NAME_MAX ?>" required autocomplete="name">
                    </label>
                    <label>所属(学科・学年など。任意)
                        <input type="text" name="affiliation" maxlength="<?= (int) KM_MAP_GUEST_AFFILIATION_MAX ?>">
                    </label>
                    <button type="submit" class="km-doc-button">作って地図を開く</button>
                </form>
                <p class="km-doc-updated">あと <?= (int) $link['usesLeft'] ?> 人分作れます。</p>
            <?php else: ?>
                <p>このリンクで作れる仮アカウントは、もう残っていません。作ったことがある人は、下から入り直せます。</p>
            <?php endif; ?>

            <h2>作った仮アカウントで入り直す</h2>
            <form method="post" action="/guest.php" class="km-doc-form">
                <?= km_csrf_field() ?>
                <input type="hidden" name="t" value="<?= km_guest_e($token) ?>">
                <input type="hidden" name="action" value="reenter">
                <label>再入場コード
                    <input type="text" name="code" maxlength="12" required autocomplete="off" autocapitalize="characters" placeholder="XXXX-XXXX">
                </label>
                <button type="submit" class="km-doc-button">入り直す</button>
            </form>

            <p class="km-doc-updated">
                教員の氏名は個人情報です。画面の写真を撮って広げたり、ほかの人にこのリンクやコードを渡したりしないでください。
            </p>
        <?php else: ?>
            <div class="km-doc-warning" role="alert"><?= km_guest_e((string) $message) ?></div>
            <p><a href="/" class="km-doc-link">地図へ戻る</a></p>
        <?php endif; ?>
    </main>
</body>

</html>
