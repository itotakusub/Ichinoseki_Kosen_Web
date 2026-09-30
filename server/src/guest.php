<?php

declare(strict_types=1);

/**
 * お試しの閲覧リンクの受け口(lib/map-guest.php。2026-09-30)。
 *
 *   GET  /guest.php?t=<トークン>  確認の画面を出すだけ。**リンクを使わない**
 *   POST (ボタン)                  1 台分を使い、このブラウザに期限付きの印を立てて地図へ戻す
 *
 * GET で使わないのは、メッセージアプリのリンクの下見(プレビュー)が自動で取りに来るため。
 * それで台数が減ったり、送った相手より先に下見のサーバーが使ってしまったりする。
 *
 * URL にトークンが載るので、nginx はこのページのクエリをログに残さず(km_no_query)、
 * 参照元も渡さない(Referrer-Policy: no-referrer。PHP と nginx で同じ値)。
 */

require_once __DIR__ . '/lib/session.php';
km_session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/map-guest.php';
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = (string) ($method === 'POST' ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$message = null;
$link = null;

try {
    $pdo = km_db();

    if ($method === 'POST') {
        if (!km_csrf_verify()) {
            $message = 'ページの有効期限が切れました。受け取ったリンクをもう一度開いてください。';
        } else {
            $used = km_map_guest_redeem($pdo, $token);
            if ($used === null) {
                $message = 'このリンクは使えません(期限切れ・取り消し済み・使える台数を超えた、のいずれかです)。';
            } else {
                // 権限が上がる瞬間にセッション ID を作り直す(セッション固定への対策。api/map-unlock.php と同じ)
                session_regenerate_id(true);
                km_map_guest_grant($used['id'], $used['expiresAt']);
                km_admin_log_record('content', 'guest.link_used', '#' . $used['id']);
                header('Location: /', true, 303);
                exit;
            }
        }
    } else {
        $link = km_map_guest_peek($pdo, $token);
        if ($link === null) {
            $message = 'このリンクは使えません(期限切れ・取り消し済み・使える台数を超えた、のいずれかです)。';
        }
    }
} catch (Throwable $exception) {
    error_log('guest.php failed: ' . $exception->getMessage());
    $message = '現在利用できません。時間をおいてもう一度お試しください。';
    $link = null;
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
        <?php if ($link !== null): ?>
            <p>
                このリンクを使うと、<strong>このブラウザだけ</strong>、
                <strong><?= km_guest_e(date('Y/m/d H:i', $link['expiresAt'])) ?></strong> まで
                教員の地点の名前をパスワード無しで見られます。
            </p>
            <p>使える台数はあと <?= (int) $link['usesLeft'] ?> 台です。ボタンを押すと 1 台分を使います。</p>
            <form method="post" action="/guest.php">
                <?= km_csrf_field() ?>
                <input type="hidden" name="t" value="<?= km_guest_e($token) ?>">
                <button type="submit" class="km-doc-button">このブラウザで地図を開く</button>
            </form>
            <p class="km-doc-updated">
                教員の氏名は個人情報です。画面の写真を撮って広げたり、ほかの人にこのリンクを渡したりしないでください。
            </p>
        <?php else: ?>
            <div class="km-doc-warning" role="alert"><?= km_guest_e((string) $message) ?></div>
            <p><a href="/" class="km-doc-link">地図へ戻る</a></p>
        <?php endif; ?>
    </main>
</body>

</html>
