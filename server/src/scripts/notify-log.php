<?php

declare(strict_types=1);

/**
 * スクリプトの実行結果・ログをメールで1通送る。**標準入力から JSON を受け取る。**
 *
 *     docker compose exec -T web php scripts/notify-log.php < report.json
 *
 * 呼ぶのは scripts/send-log.sh(ホスト側)。
 *
 * ## なぜ PHP から送るのか
 *
 * 送信の設定(宛先・SMTP・認証・TLS)は既に `lib/mailer.php` が env から読んでいる。
 * シェル側で mail コマンドを叩くと**同じ設定が2箇所に散る**
 * (notify-update.php / notify-security.php と同じ理由)。
 *
 * ## 失敗しても 0 で終わらない
 *
 * 呼び出し側(cron)が失敗に気づけるよう、送れなければ 1 を返す。
 * **「送ったつもり」が一番危ない。**
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/log-notice.php';
require_once __DIR__ . '/../lib/mailer.php';

$json = (string) stream_get_contents(STDIN);
if (trim($json) === '') {
    fwrite(STDERR, "標準入力が空です。JSON を渡してください。\n");
    exit(1);
}

try {
    $report = km_log_notice_parse($json);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, '通知の内容を読めません: ' . $exception->getMessage() . "\n");
    exit(1);
}

$to = km_mail_admin_to();
$subject = km_log_notice_subject($report);
$body = km_log_notice_body($report);

try {
    km_mail_send($to, $subject, $body);
} catch (Throwable $exception) {
    // 宛先は出す(設定の取り違えが一番多い)。中身は出さない
    fwrite(STDERR, '送信に失敗しました (宛先 ' . $to . '): ' . $exception->getMessage() . "\n");
    exit(1);
}

echo '送りました: ' . $to . ' / ' . $subject . "\n";
