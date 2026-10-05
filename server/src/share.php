<?php

declare(strict_types=1);

/**
 * ファイル管理の共有リンクの受け口(lib/file-share.php。2026-10-05、利用者の指示)。
 *
 *   GET  /share.php?t=<トークン>   ファイル名・大きさ・期限を見せるだけ。**数えない**
 *   POST t=<トークン>              1 回分を使って、添付として渡す
 *
 * GET で数えないのは、メッセージアプリのリンクの下見(プレビュー)が自動で取りに来るため(guest.php と同じ)。
 * URL にトークンが載るので、nginx はこのページのクエリをログに残さず(km_no_query)、参照元も渡さない。
 */

require_once __DIR__ . '/lib/session.php';
km_session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/file-share.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/admin-log.php';

function km_share_e(string $value): string
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
            $file = km_file_share_use($pdo, $token);
            if ($file === null) {
                $message = 'このリンクは使えません(期限切れか、回数を使い切ったか、取り消し済みです)。';
            } else {
                // ファイル名は記録に写さない(番号だけ)
                km_admin_log_record('content', 'file.share_download', '#' . (int) $file['id']);
                header('Referrer-Policy: no-referrer');
                if (km_upload_send($file)) {
                    exit;
                }
                $message = 'ファイルを取り出せませんでした。';
            }
        }
    }
    if ($message === null) {
        $link = km_file_share_peek($pdo, $token);
        if ($link === null) {
            $message = 'このリンクは使えません(期限切れか、回数を使い切ったか、取り消し済みです)。';
        }
    }
} catch (Throwable $exception) {
    error_log('share.php failed: ' . $exception->getMessage());
    $message = '現在利用できません。時間をおいてもう一度お試しください。';
    $link = null;
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
km_csp_send('public');
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>ファイルの受け取り | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_share_e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= km_share_e(km_public_asset('Main/document.css')) ?>">
</head>

<body>
    <header class="km-doc-header">
        <h1>ファイルの受け取り</h1>
        <nav class="km-doc-nav">
            <a href="/" class="km-doc-link">地図へ</a>
        </nav>
    </header>

    <main class="km-doc-main">
        <?php if ($link === null): ?>
            <p><?= km_share_e((string) $message) ?></p>
        <?php else: ?>
            <h2><?= km_share_e((string) $link['file']['originalName']) ?></h2>
            <p>
                <?= km_share_e(date('Y/m/d H:i', $link['expiresAt'])) ?> まで、あと <?= (int) $link['usesLeft'] ?> 回受け取れます。
            </p>
            <form method="post" action="/share.php">
                <?= km_csrf_field() ?>
                <input type="hidden" name="t" value="<?= km_share_e($token) ?>">
                <button type="submit" class="km-doc-button">ダウンロードする</button>
            </form>
        <?php endif; ?>
    </main>
</body>

</html>
