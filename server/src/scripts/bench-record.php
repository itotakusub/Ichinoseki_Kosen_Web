<?php

declare(strict_types=1);

/**
 * 外(この PC)から測った応答をベンチマークの履歴に足す(2026-11-06。scripts/bench-web.ps1 が ssh 越しに呼ぶ)。
 *
 *     docker compose exec -T web php scripts/bench-record.php < result.json
 *
 * 受け取る JSON: {label, requests, concurrency, errors, seconds, rps, p50, p95}。数は範囲を確かめてから書く
 * (lib/bench.php の km_bench_record_external)。書くのは km_bench_runs の 1 行だけ。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bench.php';

$raw = (string) stream_get_contents(STDIN, 65536);
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "JSON を読めません\n");
    exit(1);
}

try {
    $id = km_bench_record_external(km_db(), $data, is_string($data['label'] ?? null) ? $data['label'] : '');
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
echo "保存しました(#{$id})\n";
