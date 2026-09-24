<?php

declare(strict_types=1);

/**
 * バックアップの結果メールを1通送る。**標準入力から JSON を受け取る。**
 *
 *     docker compose exec -T web php scripts/notify-backup.php < report.json
 *     … --attach /var/www/html/../backups/xxx.enc
 *
 * 呼ぶのは scripts/host-backup.sh(ホスト側)。
 *
 * ## 添付はコンテナから見えるパスで渡すこと
 *
 * この PHP は web コンテナの中で動く。ホストの `/opt/kosenmap/backups/…` は
 * **そのままでは見えない**ので、呼び出し側が `src/` の下へ置いてから渡す。
 * ここでは「読めなければ例外」にする —— **送れたのに中身が無いのが一番危ない。**
 *
 * ## 失敗しても 0 で終わらない
 *
 * 呼び出し側(cron)が失敗に気づけるよう、送れなければ 1 を返す。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/backup-notice.php';
require_once __DIR__ . '/../lib/mailer.php';

$attachments = [];
$arguments = array_slice($argv, 1);
while ($arguments !== []) {
    $argument = array_shift($arguments);
    if ($argument === '--attach') {
        $path = (string) array_shift($arguments);
        if ($path === '') {
            fwrite(STDERR, "--attach にファイルが指定されていません。\n");
            exit(1);
        }
        $attachments[] = $path;
        continue;
    }
    fwrite(STDERR, '知らない引数: ' . $argument . "\n");
    exit(1);
}

$json = (string) stream_get_contents(STDIN);
if (trim($json) === '') {
    fwrite(STDERR, "標準入力が空です。JSON を渡してください。\n");
    exit(1);
}

try {
    $report = km_backup_notice_parse($json);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, '通知の内容を読めません: ' . $exception->getMessage() . "\n");
    exit(1);
}

/*
 * **大きすぎる添付は付けない。** Gmail は 25MB を超えると受け取らず、
 * しかも**送信側からは成功に見えることがある** —— 「送ったつもり」を作らない。
 * 上限の判断はここ1箇所に置く(呼び出し側にも書くと、片方だけ直されて食い違う)。
 */
$total = 0;
$dropped = [];
$usable = [];
foreach ($attachments as $path) {
    if (!is_file($path) || !is_readable($path)) {
        $dropped[] = basename($path) . '(読めません)';
        continue;
    }
    $size = (int) filesize($path);
    if ($total + $size > KM_BACKUP_NOTICE_ATTACH_LIMIT) {
        $dropped[] = basename($path) . '(大きすぎます: ' . km_backup_notice_size($size) . ')';
        continue;
    }
    $total += $size;
    $usable[] = $path;
}

$report['attached'] = array_map('basename', $usable);
if ($dropped !== []) {
    $note = '付けられなかったもの: ' . implode(' / ', $dropped)
        . '。置き場から直接お取りください。';
    $report['attachNote'] = $report['attachNote'] === '' ? $note : $report['attachNote'] . ' ' . $note;
    $report['problems'][] = $note;
}

/*
 * **成功を毎日送らない。** 成功の便りが毎日届くと読まれなくなり、
 * 失敗した日の1通も同じ扱いで流される。
 * 送るのは「失敗したとき」「添付したとき」「週に一度の便り(日曜 2:40)」だけ。
 */
$shouldSend = !$report['ok'] || $report['problems'] !== [] || $usable !== [] || $report['heartbeat'];
if (!$shouldSend) {
    echo "取れました。添付も無いのでメールは送りません。\n";
    exit(0);
}

$to = km_mail_admin_to();
$subject = km_backup_notice_subject($report);
$body = km_backup_notice_body($report);

try {
    km_mail_send_with_files($to, $subject, $body, $usable);
} catch (Throwable $exception) {
    // 宛先は出す(設定の取り違えが一番多い)。中身は出さない
    fwrite(STDERR, '送信に失敗しました (宛先 ' . $to . '): ' . $exception->getMessage() . "\n");
    exit(1);
}

echo '送りました: ' . $to . ' / ' . $subject . "\n";
