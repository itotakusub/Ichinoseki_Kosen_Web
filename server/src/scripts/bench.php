<?php

declare(strict_types=1);

/**
 * ベンチマークを CLI で回す(2026-11-06。中身は lib/bench.php、画面はタスクマネージャーの「ベンチマーク」)。
 *
 *     docker compose exec -T -u www-data web php scripts/bench.php [--label 名前]
 *     docker compose exec -T -u www-data web php scripts/bench.php --no-save --json      基準を測り直すとき
 *     docker compose exec -T -u www-data web php scripts/bench.php --steps cpu.hash,db  段を選ぶ
 *
 * 既定では管理画面と同じく km_bench_runs に保存する(測った所は「ホスト(CLI)」)。同時に 1 つだけ。
 *
 * **root で動かさない(-u www-data を付ける)。** ディスクの段は cache/bench/ を作って消す。
 * root で作ったまま残ると、web(www-data)が書けなくなる。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bench.php';

$label = '';
$save = true;
$json = false;
$only = null;
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--label':
            $label = (string) ($argv[++$i] ?? '');
            break;
        case '--no-save':
            $save = false;
            break;
        case '--json':
            $json = true;
            break;
        case '--steps':
            $only = array_values(array_filter(explode(',', (string) ($argv[++$i] ?? ''))));
            break;
        case '-h':
        case '--help':
            fwrite(STDOUT, "php scripts/bench.php [--label 名前] [--steps a,b] [--no-save] [--json]\n段: " . implode(' ', array_keys(KM_BENCH_STEPS)) . "\n");
            exit(0);
        default:
            fwrite(STDERR, "知らない引数: {$argv[$i]}\n");
            exit(2);
    }
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "root では動かしません。docker compose exec -u www-data … で呼んでください(cache/bench の持ち主が変わるため)。\n");
    exit(2);
}

$steps = $only ?? array_keys(KM_BENCH_STEPS);
foreach ($steps as $step) {
    if (!isset(KM_BENCH_STEPS[$step])) {
        fwrite(STDERR, "知らない段: {$step}\n");
        exit(2);
    }
}

$say = static function (string $line) use ($json): void {
    if (!$json) {
        fwrite(STDOUT, $line . "\n");
    }
};

$pdo = null;
$runId = null;
if ($save) {
    $pdo = km_db();
    $runId = km_bench_start($pdo, 'cli', $label, 'cli');
    $say("回 #{$runId} を始めました(版 " . KM_BENCH_VERSION . ')');
}

$results = [];
$started = microtime(true);
foreach ($steps as $step) {
    $t0 = microtime(true);
    if ($save) {
        $result = km_bench_run_step($pdo, (int) $runId, $step);
    } else {
        try {
            $result = km_bench_measure_step($step);
        } catch (Throwable $exception) {
            $result = ['error' => $exception->getMessage(), 'score' => null];
        }
    }
    $results[$step] = $result;
    if (isset($result['error'])) {
        $say(sprintf('  %-13s 失敗: %s', $step, $result['error']));
        continue;
    }
    $say(sprintf('  %-13s 中央値 %8.4f s  %d 回  CV %5.1f%%%s  スコア %6s  (%.1f s)', $step, $result['median'], $result['reps'], $result['cv'] * 100,
        $result['unstable'] ? '!' : ' ', $result['score'] === null ? '—' : (string) round($result['score']), microtime(true) - $t0));
}

$summary = km_bench_summarize($results);
if ($save) {
    $run = km_bench_finish($pdo, (int) $runId);
    $summary = ['groups' => $run['results']['groups'] ?? [], 'total' => $run['totalScore'] ?? null];
}
$say(sprintf('合計 %s(%.0f 秒)', $summary['total'] === null ? '—' : (string) round($summary['total']), microtime(true) - $started));
foreach ($summary['groups'] as $group => $score) {
    $say(sprintf('  %-6s %s', $group, $score === null ? '—' : (string) round($score)));
}

if ($json) {
    fwrite(STDOUT, json_encode([
        'suiteVersion' => KM_BENCH_VERSION,
        'environment' => km_bench_environment(),
        'steps' => $results,
        'summary' => $summary,
        'seconds' => round(microtime(true) - $started, 1),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}
