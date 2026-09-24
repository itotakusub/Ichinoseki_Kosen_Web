<?php

declare(strict_types=1);

/**
 * 更新の通知メールを1通送る。**標準入力から JSON を受け取る。**
 *
 *     docker compose exec -T web php scripts/notify-update.php < report.json
 *
 * 呼ぶのは scripts/check-updates.sh(ホスト側)。
 *
 * ## なぜ PHP から送るのか
 *
 * 送信の設定(宛先・SMTP・認証・TLS)は既に `lib/mailer.php` が env から読んでいる。
 * シェル側で mail コマンドを叩くと**同じ設定が2箇所に散る** ——
 * 片方だけ直されて、通知だけ届かなくなる形になる。
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

require_once __DIR__ . '/../lib/update-notice.php';
require_once __DIR__ . '/../lib/mailer.php';

$json = (string) stream_get_contents(STDIN);
if (trim($json) === '') {
    fwrite(STDERR, "標準入力が空です。JSON を渡してください。\n");
    exit(1);
}

try {
    $report = km_update_notice_parse($json);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, '通知の内容を読めません: ' . $exception->getMessage() . "\n");
    exit(1);
}

/*
 * **変化が無ければ送らない。** 毎日届くメールは読まれなくなり、
 * 本当に上がったときにも気づかれない。
 * ただし heartbeat のときは送る —— 沈黙を「正常」と解釈させないため。
 */
if ($report['items'] === [] && !$report['heartbeat']) {
    echo "更新はありません。メールは送りません。\n";
    exit(0);
}

$to = km_mail_admin_to();
$subject = km_update_notice_subject($report['items'], $report['heartbeat']);
$body = km_update_notice_body($report['items'], $report['host'], $report['heartbeat']);

try {
    km_mail_send($to, $subject, $body);
} catch (Throwable $exception) {
    // 宛先は出す(設定の取り違えが一番多い)。中身は出さない
    fwrite(STDERR, '送信に失敗しました (宛先 ' . $to . '): ' . $exception->getMessage() . "\n");
    exit(1);
}

echo '送りました: ' . $to . ' / ' . $subject . "\n";
