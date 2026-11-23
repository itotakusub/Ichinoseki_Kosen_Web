<?php

declare(strict_types=1);

/**
 * Cloudflare の分析の取り込み(2026-11-08。lib/cloudflare-analytics.php)。呼ぶのは scripts/host-cloudflare-import.sh(本番ホストの root の cron)。
 *
 *     php scripts/cloudflare-import.php plan --zone <32 桁>     何を問い合わせるか(1 行 1 つ: 名前 TAB 本文の JSON)。無ければ何も出さない
 *     php scripts/cloudflare-import.php ingest <名前>          標準入力の答えを DB に入れる。「もう一度 plan を」のときは replan と出す
 *     php scripts/cloudflare-import.php done <メッセージ>      取り込みが済んだ(画面の「最後に取り込んだ時刻」)
 *     php scripts/cloudflare-import.php fail <メッセージ>      取り込みに失敗した(画面に理由を出す)
 *
 * **トークンはここに来ない**(ホストの root だけが持つ)。答えは 32MB まで。
 * 終わりのコード: 0 = 済んだ・1 = 失敗(ホストの記録とメールに残る)・2 = 使い方の誤り。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/cloudflare-analytics.php';

const KM_CF_INGEST_MAX_BYTES = 32 * 1024 * 1024;

$command = $argv[1] ?? '';

try {
    $pdo = km_db();
    if ($command === 'plan') {
        $zone = ($argv[2] ?? '') === '--zone' ? (string) ($argv[3] ?? '') : '';
        foreach (km_cf_plan($pdo, $zone) as [$name, $body]) {
            echo $name, "\t", $body, "\n";
        }
        exit(0);
    }
    if ($command === 'ingest') {
        $name = (string) ($argv[2] ?? '');
        $text = (string) stream_get_contents(STDIN, KM_CF_INGEST_MAX_BYTES + 1);
        if (strlen($text) > KM_CF_INGEST_MAX_BYTES) {
            throw new RuntimeException('答えが大きすぎます(32MB まで)。');
        }
        $result = km_cf_ingest($pdo, $name, $text);
        printf("%s: %d 件(新しく %d 件)%s\n", $result['name'], $result['rows'], $result['inserted'], $result['replan'] ? "\nreplan" : '');
        exit(0);
    }
    if ($command === 'done' || $command === 'fail') {
        $message = (string) ($argv[2] ?? '');
        $summary = [];
        if ($command === 'done') {
            $count = $pdo->query('SELECT COUNT(*) FROM km_cf_waf_events')->fetchColumn();
            $hours = $pdo->query('SELECT COUNT(*) FROM km_cf_traffic_hourly')->fetchColumn();
            $summary = ['wafRows' => (int) $count, 'trafficHours' => (int) $hours];
        }
        km_cf_record_run($pdo, $command === 'done', $message, $summary);
        exit(0);
    }
    fwrite(STDERR, "使い方: php scripts/cloudflare-import.php plan --zone <ID> | ingest <名前> | done <文> | fail <文>\n");
    exit(2);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    try {
        km_cf_record_run(km_db(), false, $command . ': ' . $exception->getMessage());
    } catch (Throwable) {
        // DB に書けなければ、ホストの記録だけに残る
    }
    exit(1);
}
