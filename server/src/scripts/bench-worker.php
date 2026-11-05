<?php

declare(strict_types=1);

/**
 * ベンチマークの並列の段の 1 本(2026-11-06。lib/bench.php の km_bench_parallel が proc_open で同時に起こす)。
 *
 *     php scripts/bench-worker.php cpu.route
 *
 * 決まった仕事を 1 回だけして "ok <秒>" を出す。時間は呼ぶ側が全体で測る。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bench.php';

$step = (string) ($argv[1] ?? '');
if ($step !== 'cpu.route') {
    fwrite(STDERR, "使える段は cpu.route だけです\n");
    exit(2);
}

$t0 = hrtime(true);
km_bench_route();
printf("ok %.5f\n", (hrtime(true) - $t0) / 1e9);
