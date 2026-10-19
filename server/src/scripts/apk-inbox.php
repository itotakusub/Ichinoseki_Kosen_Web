<?php

declare(strict_types=1);

/**
 * アプリの受け箱に、ホストが GitHub から取ってきた届けを入れる(2026-10-09。lib/apk-inbox.php)。
 *
 *     docker compose exec -T web php scripts/apk-inbox.php stage /var/www/apkinbox/<届けのフォルダ>
 *
 * 呼ぶのは scripts/host-apk-inbox.sh(本番ホストの root の cron)。フォルダには latest.json と APK 2 つがある
 * (ホストの run/apk-inbox/ を、web には読み取り専用で渡してある)。
 * 検めて、通れば受け箱(uploads/apk-inbox/)へ写し、管理者へメールする。同じ届けを 2 度入れても 1 回にしかならない。
 *
 * 終わりのコード: 0 = 入れた(または前に入れた)・1 = 失敗(ホストの記録とメールに残る)。
 * 検めに落ちた届けも 0(「公開できない届け」として記録し、理由はメールで知らせる)。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/apk-inbox.php';

$command = $argv[1] ?? '';
$dir = $argv[2] ?? '';
if ($command !== 'stage' || $dir === '') {
    fwrite(STDERR, "使い方: php scripts/apk-inbox.php stage <届けのフォルダ>\n");
    exit(2);
}
// 置き場の外は読まない(ホストが渡す /var/www/apkinbox/<名前> だけ)。断る理由は分けて出す(2026-10-09、本番で初回に区別できなかった)
$allowAny = getenv('KM_APK_INBOX_ALLOW_ANY_DIR') === '1';
if (!$allowAny && !is_dir('/var/www/apkinbox')) {
    fwrite(STDERR, "web の中に受け箱の置き場 /var/www/apkinbox がありません。compose の作り直し(deploy-to-host.ps1 -Action up)がまだです\n");
    exit(2);
}
$real = realpath($dir);
if ($real === false || !is_dir($real)) {
    fwrite(STDERR, "届けのフォルダが web の中に見えません: {$dir}(置き場はあるので、ホストの run/apk-inbox と web の /var/www/apkinbox が別の場所を指しています)\n");
    exit(2);
}
if (!$allowAny && !str_starts_with($real, '/var/www/apkinbox/')) {
    fwrite(STDERR, "届けのフォルダは /var/www/apkinbox/ の下だけです: {$dir}\n");
    exit(2);
}

try {
    $result = km_apk_inbox_stage(km_db(), $real);
    printf(
        "届け #%d: %s%s\n",
        $result['id'],
        $result['created'] ? ['waiting' => '公開を待っています(メールを送りました)', 'rejected' => '検めに通りませんでした(メールで知らせました)'][$result['status']] ?? $result['status'] : '前に入れた届けです(何もしません)',
        $result['problems'] !== [] ? ' —— ' . implode(' / ', $result['problems']) : ''
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '受け箱に入れられませんでした: ' . $exception->getMessage() . "\n");
    exit(1);
}
