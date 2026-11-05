<?php

declare(strict_types=1);

/**
 * サーバーのベンチマーク(2026-11-06、利用者の指示「サーバーでベンチマークを測れるようにしたい」)。
 *
 * 押す口は管理画面のタスクマネージャーの「ベンチマーク」(admin/api/bench.php。段ごとに 1 回の POST)、
 * CLI は scripts/bench.php、この PC から外の応答を測るのは scripts/bench-web.ps1(結果は scripts/bench-record.php)。
 *
 * ## 比べられるようにする工夫(利用者の注文「簡単な処理だと比較が難しいので考慮」)
 *
 *   - **毎回同じ仕事。** 中身は種を決めた疑似乱数(km_bench_rng)と、作り方の決まった文から作る。
 *     1 回の仕事は 0.1〜0.5 秒くらいにする(短すぎると時計とほかの仕事の揺れに埋もれる)。
 *   - **繰り返して真ん中を取る。** 1 回空回し(キャッシュ・JIT を温める)のあと 5 回測り、中央値を使う。
 *     段の予算(KM_BENCH_STEP_BUDGET)を超えそうなら 3 回まで減らす。ばらつき(CV)が 10% を超えたら印を付ける。
 *   - **スコア = 1000 × 基準の時間 ÷ 中央値。** 基準(KM_BENCH_REFERENCE)は本番で測った値を固定したもの
 *     (2026-11-06)。1000 なら基準の日と同じ速さ、2000 なら倍の速さ。
 *   - **合計は相乗平均。** 1 つの段だけ極端でも合計が振り回されない。合計に入れるのは**固定の仕事**だけ ——
 *     本番のデータで組む「アプリ」と、配っているファイルで変わる「Web」は、データが増えると下がるので分けて出す。
 *   - **中身を変えたら KM_BENCH_VERSION を上げる。** 比べるのは同じ版どうしだけ。
 *   - **そのときの混み具合も残す**(load average・Apache の忙しいワーカー)。混んでいた回は印を付ける。
 *
 * ## 本番を壊さない
 *
 *   - 書くのは新しい表 km_bench_runs だけ。DB の段は **一時表(CREATE TEMPORARY TABLE)** で、実の表は触らない。
 *   - ディスクの段は cache/bench/ に書いて、段の終わりに消す。
 *   - **同時に 1 つだけ**(段は GET_LOCK、回は「2 分以内に動いた running の行」で断る)。1 段は 15 秒まで。
 */

require_once __DIR__ . '/db.php';

const KM_BENCH_VERSION = 1;
const KM_BENCH_REPS = 5;
const KM_BENCH_MIN_REPS = 3;
/** 1 段の時間の予算(秒)。空回し + 繰り返しがこれに収まるよう回数を減らす */
const KM_BENCH_STEP_BUDGET = 12.0;
const KM_BENCH_CV_WARN = 0.10;
/** running のまま、これより長く動きの無い回は止まったとみなす(画面を閉じた等) */
const KM_BENCH_RUNNING_STALE = 120;
/** 基準を測ったときのコアの数(並列の段のスコアに使う) */
const KM_BENCH_REFERENCE_CORES = 2;
const KM_BENCH_PARALLEL_MAX = 4;
/** 1 コアあたりの load1 がこれを超えていたら「混んでいた」 */
const KM_BENCH_BUSY_LOAD = 0.7;
const KM_BENCH_WEB_REQUESTS = 200;
const KM_BENCH_WEB_CONCURRENCY = 4;
/** 中から測る URL(Apache へ直に。nginx の回数制限は通らない)。セッションを作らず、外へ取りに行かないものだけ(PHP は release.php) */
const KM_BENCH_WEB_URLS = ['/Main/styles.css', '/Main/app.js', '/api/release.php'];

/**
 * 段 = [分野, 日本語, 英語, 合計に入れるか]。**並びが実行の順。**
 * 分野: cpu / mem / disk / db / img / app / web
 */
const KM_BENCH_STEPS = [
    'cpu.hash' => ['cpu', 'CPU: ハッシュ(sha256 128MB)', 'CPU: hashing (sha256, 128MB)', true],
    'cpu.compress' => ['cpu', 'CPU: 圧縮と展開(deflate 8MB)', 'CPU: compress and inflate (deflate, 8MB)', true],
    'cpu.sort' => ['cpu', 'CPU: 並べ替え(50 万の整数 × 2)', 'CPU: sorting (500k integers × 2)', true],
    'cpu.json' => ['cpu', 'CPU: JSON の作りと読み(2 万件 × 3)', 'CPU: JSON encode and decode (20k records × 3)', true],
    'cpu.route' => ['cpu', 'CPU: 最短経路(16 万点の格子)', 'CPU: shortest path (160,000-node grid)', true],
    'cpu.parallel' => ['cpu', 'CPU: 並列(コアの数だけ同時に最短経路)', 'CPU: parallel (shortest path on every core)', true],
    'mem' => ['mem', 'メモリ: 16MB の複写 × 16 と飛び飛びの読み 100 万', 'Memory: 16MB copy × 16 and 1M random reads', true],
    'disk.seq' => ['disk', 'ディスク: 64MB を書いて fsync・読み戻し', 'Disk: write 64MB with fsync, read back', true],
    'disk.small' => ['disk', 'ディスク: 小さいファイル 200 個(作る・fsync・消す)', 'Disk: 200 small files (create, fsync, delete)', true],
    'db' => ['db', 'DB: 一時表(挿入 2 万・索引の読み 2 千・集計・更新 1 千)', 'DB: temporary table (20k inserts, 2k index reads, aggregate, 1k updates)', true],
    'img' => ['img', '画像: 1600×1200 を描いて縮小し WebP に', 'Image: draw 1600×1200, resize, encode WebP', true],
    'app' => ['app', 'アプリ: 地図のデータを組む × 20(本番のデータ)', 'App: build the map data × 20 (live data)', false],
    'web' => ['web', 'Web: Apache へ 200 回(同時 4)', 'Web: 200 requests to Apache (4 at a time)', false],
];

/**
 * 基準の時間(秒。中央値)。本番(2 コア・2GB)で測った値を固定する(2026-11-06)。
 * **変えるときは KM_BENCH_VERSION も上げる**(同じ版の中でスコアの意味が変わらないように)。
 * 並列の段は cpu.route の時間を基準に使う(km_bench_score)。web.pc は scripts/bench-web.ps1 の 100 回の時間。
 */
const KM_BENCH_REFERENCE = [
    // 2026-11-06 本番(Xeon Gold 6212U・2 コア・load 0.7〜1.0)で 2 回測った中央値の平均。2 回の差は 3% 以内
    'cpu.hash' => 0.536,
    'cpu.compress' => 0.523,
    'cpu.sort' => 0.251,
    'cpu.json' => 0.184,
    'cpu.route' => 0.123,
    'mem' => 0.236,
    'disk.seq' => 0.412,
    'disk.small' => 0.329,
    'db' => 0.416,
    'img' => 0.167,
    // 904 地点(2026-11-06)。地点が増えれば下がる(合計に入れない)
    'app' => 0.202,
    // 中から 200 回(p50 14.7 ms・p95 33 ms・271 回/秒)
    'web' => 0.775,
    // 管理の PC から 100 回・同時 5 本(p50 28 ms)。測る場所(回線)で変わる
    'web.pc' => 0.85,
];

/** 分野の名前 = [日本語, 英語] */
const KM_BENCH_GROUPS = [
    'cpu' => ['CPU', 'CPU'],
    'mem' => ['メモリ', 'Memory'],
    'disk' => ['ディスク', 'Disk'],
    'db' => ['DB', 'DB'],
    'img' => ['画像', 'Image'],
    'app' => ['アプリ', 'App'],
    'web' => ['Web(中から)', 'Web (inside)'],
    'pc' => ['Web(外から)', 'Web (outside)'],
];

// ---------------------------------------------------------------------------
// 計算(純粋関数。check.php の bench で確かめる)
// ---------------------------------------------------------------------------

/**
 * 時間の並びから中央値・最小・最大・ばらつき(CV = 標準偏差 ÷ 平均)を出す。
 *
 * @param list<float> $times
 * @return array{median:float, min:float, max:float, mean:float, cv:float, reps:int, unstable:bool}
 */
function km_bench_stats(array $times): array
{
    $times = array_values(array_map('floatval', $times));
    if ($times === []) {
        throw new InvalidArgumentException('時間がありません');
    }
    sort($times);
    $n = count($times);
    $median = $n % 2 === 1 ? $times[intdiv($n, 2)] : ($times[$n / 2 - 1] + $times[$n / 2]) / 2;
    $mean = array_sum($times) / $n;
    $var = 0.0;
    foreach ($times as $t) {
        $var += ($t - $mean) ** 2;
    }
    $cv = $n > 1 && $mean > 0 ? sqrt($var / ($n - 1)) / $mean : 0.0;

    return [
        'median' => $median,
        'min' => $times[0],
        'max' => $times[$n - 1],
        'mean' => $mean,
        'cv' => $cv,
        'reps' => $n,
        'unstable' => $cv > KM_BENCH_CV_WARN,
    ];
}

/** 空回しの時間から、予算に収まる回数(3〜5)を決める */
function km_bench_reps_for(float $warmup, float $budget = KM_BENCH_STEP_BUDGET): int
{
    if ($warmup <= 0) {
        return KM_BENCH_REPS;
    }
    $fit = (int) floor(($budget - $warmup) / $warmup);

    return max(KM_BENCH_MIN_REPS, min(KM_BENCH_REPS, $fit));
}

/**
 * 段のスコア。基準の時間 ÷ 中央値 × 1000。基準が無い段は null。
 *
 * 並列の段は「1 コアの基準 × 同時に走らせた数 ÷ (かかった時間 × 基準の日のコア数)」——
 * 基準の日と同じ速さのコアが同じ数あり、きれいに並列に動けば 1000。コアが倍になれば 2000 近くになる。
 *
 * @param array<string, mixed> $extra
 */
function km_bench_score(string $step, float $median, array $extra = [], array $reference = KM_BENCH_REFERENCE): ?float
{
    if ($median <= 0) {
        return null;
    }
    if ($step === 'cpu.parallel') {
        $unit = $reference['cpu.route'] ?? null;
        $workers = (int) ($extra['workers'] ?? 0);
        if (!is_float($unit) && !is_int($unit) || $workers < 1) {
            return null;
        }

        return round(1000 * $unit * $workers / ($median * KM_BENCH_REFERENCE_CORES), 1);
    }
    $ref = $reference[$step] ?? null;
    if (!is_float($ref) && !is_int($ref)) {
        return null;
    }

    return round(1000 * $ref / $median, 1);
}

/**
 * 相乗平均。0 以下と null は数えない。1 つも無ければ null。
 *
 * @param iterable<float|null> $scores
 */
function km_bench_geomean(iterable $scores): ?float
{
    $sum = 0.0;
    $n = 0;
    foreach ($scores as $score) {
        if (is_numeric($score) && (float) $score > 0) {
            $sum += log((float) $score);
            $n++;
        }
    }

    return $n === 0 ? null : round(exp($sum / $n), 1);
}

/**
 * 段の結果から、分野ごとのスコアと合計を出す。合計は KM_BENCH_STEPS で「合計に入れる」段だけ。
 *
 * @param array<string, array<string, mixed>> $steps
 * @return array{groups: array<string, float|null>, total: float|null}
 */
function km_bench_summarize(array $steps): array
{
    $byGroup = [];
    $forTotal = [];
    foreach ($steps as $id => $result) {
        $score = $result['score'] ?? null;
        $group = $id === 'web.pc' ? 'pc' : (KM_BENCH_STEPS[$id][0] ?? null);
        if ($group === null) {
            continue;
        }
        $byGroup[$group][] = $score;
        if ((KM_BENCH_STEPS[$id][3] ?? false) === true) {
            $forTotal[] = $score;
        }
    }
    $groups = [];
    foreach (array_keys(KM_BENCH_GROUPS) as $group) {
        if (isset($byGroup[$group])) {
            $groups[$group] = km_bench_geomean($byGroup[$group]);
        }
    }

    return ['groups' => $groups, 'total' => km_bench_geomean($forTotal)];
}

/**
 * 2 つの回の差(%)。**版が違えば比べない**(null)。速くなれば +。
 *
 * @return array{total: float|null, steps: array<string, float>}|null
 */
function km_bench_compare(array $run, array $base): ?array
{
    if ((int) ($run['suiteVersion'] ?? 0) !== (int) ($base['suiteVersion'] ?? -1)) {
        return null;
    }
    $pct = static fn ($a, $b): ?float => is_numeric($a) && is_numeric($b) && (float) $b > 0 ? round(((float) $a / (float) $b - 1) * 100, 1) : null;
    $steps = [];
    foreach ($run['results']['steps'] ?? [] as $id => $result) {
        $d = $pct($result['score'] ?? null, $base['results']['steps'][$id]['score'] ?? null);
        if ($d !== null) {
            $steps[$id] = $d;
        }
    }

    return ['total' => $pct($run['totalScore'] ?? null, $base['totalScore'] ?? null), 'steps' => $steps];
}

/** 種を決めた疑似乱数(線形合同法)。mt_rand の全体の状態を汚さない */
function km_bench_rng(int &$state): int
{
    $state = ($state * 1103515245 + 12345) & 0x7fffffff;

    return $state;
}

/** 作り方の決まった文(単語を疑似乱数で並べる)。毎回同じ中身 */
function km_bench_text(int $bytes): string
{
    $words = ['kosen', 'map', 'route', 'floor', 'node', 'edge', 'event', 'poster', '一関', '天気', '教室', '階段', 'rain', 'graph', 'path', 'stair'];
    $state = 12345;
    // 配列に貯めて implode すると、8MB で 100 万を超える小さな文字列を持つことになる(メモリが 10 倍)。足していく
    $text = '';
    while (strlen($text) < $bytes) {
        $r = km_bench_rng($state);
        // 数を混ぜる(単語だけだと圧縮が簡単すぎて、圧縮の段が軽くなりすぎる)
        $text .= $words[$r % 16] . (($r >> 4) % 1000) . ((($r >> 8) % 11) === 0 ? ".\n" : ' ');
    }

    return substr($text, 0, $bytes);
}

// ---------------------------------------------------------------------------
// 仕事(段ごと)。prepare で材料を作り(時間に数えない)、work を繰り返す
// ---------------------------------------------------------------------------

/** 最短経路(格子・4 方向・重みは座標から決まる)。返すのは右下までの距離(毎回同じになる) */
function km_bench_route(int $w = 400, int $h = 400): int
{
    $n = $w * $h;
    $dist = array_fill(0, $n, PHP_INT_MAX);
    $dist[0] = 0;
    $queue = new SplPriorityQueue();
    $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    $queue->insert(0, 0);
    while (!$queue->isEmpty()) {
        $top = $queue->extract();
        $v = $top['data'];
        $d = -$top['priority'];
        if ($d > $dist[$v]) {
            continue;
        }
        $x = $v % $w;
        $y = intdiv($v, $w);
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                continue;
            }
            $u = $ny * $w + $nx;
            $nd = $d + (($nx * 31 + $ny * 17) % 9) + 1;
            if ($nd < $dist[$u]) {
                $dist[$u] = $nd;
                $queue->insert($u, -$nd);
            }
        }
    }

    return $dist[$n - 1];
}

/** ディスクの段の置き場所(cache/bench)。段の終わりに km_bench_disk_cleanup で消す */
function km_bench_disk_dir(): string
{
    $dir = dirname(__DIR__) . '/cache/bench';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cache/bench を作れません');
    }

    return $dir;
}

function km_bench_disk_cleanup(): void
{
    $dir = dirname(__DIR__) . '/cache/bench';
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

/**
 * 段の仕事を返す: ['prepare' => fn(): mixed, 'work' => fn(mixed $prepared): ?array(付け足しの情報)]
 *
 * @return array{prepare: callable, work: callable}
 */
function km_bench_job(string $step, ?PDO $pdo = null): array
{
    $none = static fn (): mixed => null;

    return match ($step) {
        'cpu.hash' => [
            'prepare' => static fn (): string => km_bench_text(1 << 20),
            'work' => static function (string $block): ?array {
                $ctx = hash_init('sha256');
                // 本番の CPU は sha256 の命令が無く、手元の 8 倍ほどかかる(2026-11-06 実測)。128MB で 0.5 秒ほど
                for ($i = 0; $i < 128; $i++) {
                    hash_update($ctx, $block);
                }
                hash_final($ctx);

                return null;
            },
        ],
        'cpu.compress' => [
            'prepare' => static fn (): string => km_bench_text(8 << 20),
            'work' => static function (string $text): ?array {
                $packed = gzdeflate($text, 6);
                if (!is_string($packed) || strlen((string) gzinflate($packed)) !== strlen($text)) {
                    throw new RuntimeException('圧縮して戻したものが合いません');
                }

                return ['ratio' => round(strlen($packed) / strlen($text), 3)];
            },
        ],
        'cpu.sort' => [
            'prepare' => static function (): array {
                $state = 42;
                $values = [];
                for ($i = 0; $i < 500_000; $i++) {
                    $values[] = km_bench_rng($state);
                }

                return $values;
            },
            'work' => static function (array $values): ?array {
                // 2 回に分ける(一度に 100 万だと web の memory_limit 128M に近づく)
                for ($i = 0; $i < 2; $i++) {
                    $copy = $values;
                    sort($copy);
                    unset($copy);
                }

                return null;
            },
        ],
        'cpu.json' => [
            'prepare' => static function (): array {
                $state = 7;
                $rows = [];
                for ($i = 0; $i < 20_000; $i++) {
                    $r = km_bench_rng($state);
                    $rows[] = ['id' => $i, 'name' => 'node-' . $r, 'x' => $r % 4000 / 3.0, 'y' => ($r >> 4) % 3000 / 7.0,
                        'floor' => '' . ($r % 6), 'tags' => ['room', 'stair', 'toilet'][$r % 3], 'open' => $r % 2 === 0];
                }

                return $rows;
            },
            'work' => static function (array $rows): ?array {
                for ($i = 0; $i < 3; $i++) {
                    $back = json_decode((string) json_encode($rows, JSON_UNESCAPED_UNICODE), true);
                    if (!is_array($back) || count($back) !== count($rows)) {
                        throw new RuntimeException('JSON を戻したものが合いません');
                    }
                }

                return null;
            },
        ],
        'cpu.route' => [
            'prepare' => $none,
            'work' => static function (): ?array {
                return ['distance' => km_bench_route()];
            },
        ],
        'cpu.parallel' => [
            'prepare' => static fn (): int => km_bench_cores(),
            'work' => static fn (int $cores): ?array => km_bench_parallel(max(1, min(KM_BENCH_PARALLEL_MAX, $cores))),
        ],
        'mem' => [
            'prepare' => static fn (): string => str_repeat(km_bench_text(1 << 20), 16),
            'work' => static function (string $big): ?array {
                for ($i = 0; $i < 16; $i++) {
                    $copy = $big;
                    $copy[0] = 'x'; // ここで 16MB を写す(書くまでは同じものを指している)
                    unset($copy);
                }
                $state = 99;
                $sum = 0;
                $len = strlen($big);
                for ($i = 0; $i < 1_000_000; $i++) {
                    $sum += ord($big[km_bench_rng($state) % $len]);
                }

                return null;
            },
        ],
        'disk.seq' => [
            'prepare' => static fn (): string => km_bench_text(1 << 20),
            'work' => static function (string $block): ?array {
                $path = km_bench_disk_dir() . '/seq.bin';
                $fp = fopen($path, 'wb');
                if ($fp === false) {
                    throw new RuntimeException('書けません');
                }
                for ($i = 0; $i < 64; $i++) {
                    fwrite($fp, $block);
                }
                fflush($fp);
                fsync($fp);
                fclose($fp);
                $fp = fopen($path, 'rb');
                $read = 0;
                while ($fp !== false && !feof($fp)) {
                    $read += strlen((string) fread($fp, 1 << 20));
                }
                if ($fp !== false) {
                    fclose($fp);
                }
                @unlink($path);
                if ($read !== 64 << 20) {
                    throw new RuntimeException('読み戻した大きさが合いません');
                }

                return null;
            },
        ],
        'disk.small' => [
            'prepare' => static fn (): string => km_bench_text(4096),
            'work' => static function (string $block): ?array {
                $dir = km_bench_disk_dir();
                for ($i = 0; $i < 200; $i++) {
                    $fp = fopen($dir . '/s' . $i . '.txt', 'wb');
                    if ($fp === false) {
                        throw new RuntimeException('書けません');
                    }
                    fwrite($fp, $block);
                    fflush($fp);
                    fsync($fp);
                    fclose($fp);
                }
                for ($i = 0; $i < 200; $i++) {
                    @unlink($dir . '/s' . $i . '.txt');
                }

                return null;
            },
        ],
        'db' => [
            'prepare' => static fn (): PDO => $pdo ?? km_db(),
            'work' => static fn (PDO $db): ?array => km_bench_db_work($db),
        ],
        'img' => [
            'prepare' => $none,
            'work' => static function (): ?array {
                $src = imagecreatetruecolor(1600, 1200);
                $state = 3;
                for ($i = 0; $i < 3000; $i++) {
                    $r = km_bench_rng($state);
                    $color = imagecolorallocate($src, $r % 256, ($r >> 8) % 256, ($r >> 16) % 256);
                    $x = $r % 1600;
                    $y = ($r >> 5) % 1200;
                    match ($i % 3) {
                        0 => imageline($src, $x, $y, ($x * 7) % 1600, ($y * 3) % 1200, $color),
                        1 => imagefilledrectangle($src, $x, $y, $x + 40, $y + 30, $color),
                        default => imagefilledellipse($src, $x, $y, 50, 35, $color),
                    };
                }
                $dst = imagecreatetruecolor(800, 600);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, 800, 600, 1600, 1200);
                ob_start();
                imagewebp($dst, null, 80);
                $bytes = strlen((string) ob_get_clean());
                imagedestroy($src);
                imagedestroy($dst);

                return ['bytes' => $bytes];
            },
        ],
        'app' => [
            'prepare' => static function () use ($pdo): PDO {
                require_once __DIR__ . '/map-data.php';

                return $pdo ?? km_db();
            },
            'work' => static function (PDO $db): ?array {
                // 管理画面の地図編集と同じ全体を組む(公開の地図は錠が掛かっていると空になり、測る意味が無い)。
                // 中身は外へ出さない(数と大きさだけ返す)
                // 1 回は 10ms ほど(904 地点。2026-11-06)。短すぎて揺れに埋もれるので 20 回組む
                for ($i = 0; $i < 20; $i++) {
                    $data = km_map_data($db, null, true);
                    $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
                }

                return ['nodes' => count($data['nodes'] ?? []), 'bytes' => strlen($json)];
            },
        ],
        'web' => [
            'prepare' => $none,
            'work' => static fn (): ?array => km_bench_web_internal(),
        ],
        default => throw new InvalidArgumentException('知らない段です: ' . $step),
    };
}

/** DB の段: 一時表だけを使う(実の表は読みも書きもしない) */
function km_bench_db_work(PDO $db): array
{
    $db->exec('DROP TEMPORARY TABLE IF EXISTS km_bench_tmp');
    $db->exec('CREATE TEMPORARY TABLE km_bench_tmp (id INT NOT NULL PRIMARY KEY, k INT NOT NULL, v VARCHAR(64) NOT NULL, INDEX (k)) ENGINE=InnoDB');
    try {
        $state = 11;
        $db->beginTransaction();
        for ($batch = 0; $batch < 40; $batch++) {
            $place = [];
            $values = [];
            for ($i = 0; $i < 500; $i++) {
                $id = $batch * 500 + $i;
                $r = km_bench_rng($state);
                $place[] = '(?,?,?)';
                array_push($values, $id, $r % 5000, 'value-' . $r);
            }
            $db->prepare('INSERT INTO km_bench_tmp (id, k, v) VALUES ' . implode(',', $place))->execute($values);
        }
        $db->commit();

        $select = $db->prepare('SELECT COUNT(*) FROM km_bench_tmp WHERE k = ?');
        $found = 0;
        for ($i = 0; $i < 2000; $i++) {
            $select->execute([($i * 37) % 5000]);
            $found += (int) $select->fetchColumn();
        }
        $groups = $db->query('SELECT k % 100 AS g, COUNT(*) AS c, AVG(id) AS a FROM km_bench_tmp GROUP BY g ORDER BY g')->fetchAll();

        $db->beginTransaction();
        $update = $db->prepare('UPDATE km_bench_tmp SET v = ? WHERE id = ?');
        for ($i = 0; $i < 1000; $i++) {
            $update->execute(['updated-' . $i, ($i * 19) % 20000]);
        }
        $db->commit();

        return ['found' => $found, 'groups' => count($groups)];
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->exec('DROP TEMPORARY TABLE IF EXISTS km_bench_tmp');
    }
}

/** 使えるコアの数(cgroup の上限があればそちら) */
function km_bench_cores(): int
{
    $cores = 0;
    $info = @file_get_contents('/proc/cpuinfo');
    if (is_string($info)) {
        $cores = preg_match_all('/^processor\s*:/m', $info);
    }
    $max = @file_get_contents('/sys/fs/cgroup/cpu.max');
    if (is_string($max) && preg_match('/^(\d+)\s+(\d+)/', trim($max), $m) === 1 && (int) $m[2] > 0) {
        $cores = min($cores > 0 ? $cores : PHP_INT_MAX, (int) ceil((int) $m[1] / (int) $m[2]));
    }

    return max(1, $cores);
}

/**
 * 並列の段: scripts/bench-worker.php を $workers 本同時に起こし、全部終わるまでの時間を測る(呼ぶ側が測る)。
 * 引数は固定の配列で渡す(シェルを通さない)。
 */
function km_bench_parallel(int $workers): array
{
    $worker = dirname(__DIR__) . '/scripts/bench-worker.php';
    $php = PHP_BINDIR . '/php';
    if (!is_file($worker) || !is_executable($php)) {
        throw new RuntimeException('並列の段の道具がありません(scripts/bench-worker.php・' . $php . ')');
    }
    $procs = [];
    for ($i = 0; $i < $workers; $i++) {
        $pipes = [];
        $proc = proc_open([$php, $worker, 'cpu.route'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('並列の段を起こせません');
        }
        $procs[] = [$proc, $pipes];
    }
    $failed = 0;
    foreach ($procs as [$proc, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0 || !str_starts_with(trim($out), 'ok')) {
            $failed++;
        }
    }
    if ($failed > 0) {
        throw new RuntimeException("並列の段で {$failed} 本が失敗しました");
    }

    return ['workers' => $workers];
}

/**
 * Web(中から): Apache(127.0.0.1)へ決まった URL を合わせて 200 回、同時 4 本で送る。
 * 返す: 1 秒あたりの数・p50・p95(ミリ秒)・失敗の数
 */
function km_bench_web_internal(string $base = 'http://127.0.0.1'): array
{
    $multi = curl_multi_init();
    $queue = [];
    for ($i = 0; $i < KM_BENCH_WEB_REQUESTS; $i++) {
        $queue[] = KM_BENCH_WEB_URLS[$i % count(KM_BENCH_WEB_URLS)];
    }
    $times = [];
    $errors = 0;
    $active = 0;
    $started = hrtime(true);
    $add = static function () use (&$queue, $multi, $base): void {
        $path = array_shift($queue);
        $curl = curl_init($base . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'KosenMap-bench/1',
        ]);
        curl_multi_add_handle($multi, $curl);
    };
    for ($i = 0; $i < KM_BENCH_WEB_CONCURRENCY && $queue !== []; $i++) {
        $add();
    }
    do {
        curl_multi_exec($multi, $active);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $curl = $info['handle'];
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $times[] = (float) curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000;
            if ($info['result'] !== CURLE_OK || $status !== 200) {
                $errors++;
            }
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
            if ($queue !== []) {
                $add();
                $active = 1;
            }
        }
        if ($active > 0) {
            curl_multi_select($multi, 0.5);
        }
    } while ($active > 0 || $queue !== []);
    curl_multi_close($multi);
    $wall = (hrtime(true) - $started) / 1e9;
    sort($times);
    $at = static fn (float $p): float => $times === [] ? 0.0 : round($times[min(count($times) - 1, (int) floor($p * count($times)))], 1);

    return [
        'requests' => count($times),
        'errors' => $errors,
        'rps' => $wall > 0 ? round(count($times) / $wall, 1) : 0.0,
        'p50' => $at(0.5),
        'p95' => $at(0.95),
    ];
}

/**
 * 1 つの段を測る: 材料を作り、1 回空回し、予算に合わせて 3〜5 回測る。
 *
 * @return array<string, mixed>
 */
function km_bench_measure_step(string $step, ?PDO $pdo = null): array
{
    $job = km_bench_job($step, $pdo);
    $prepared = ($job['prepare'])();
    try {
        $t0 = hrtime(true);
        $extra = ($job['work'])($prepared);
        $warmup = (hrtime(true) - $t0) / 1e9;
        $reps = km_bench_reps_for($warmup);
        $times = [];
        for ($i = 0; $i < $reps; $i++) {
            // 前の回のごみを先に片付ける(測っている最中に GC が走ると揺れる)
            gc_collect_cycles();
            $t0 = hrtime(true);
            $result = ($job['work'])($prepared);
            $times[] = (hrtime(true) - $t0) / 1e9;
            if ($result !== null) {
                $extra = $result;
            }
        }
    } finally {
        unset($prepared);
        if (str_starts_with($step, 'disk.')) {
            km_bench_disk_cleanup();
        }
    }
    $stats = km_bench_stats($times);
    $extra = is_array($extra) ? $extra : [];

    return [
        'median' => round($stats['median'], 5),
        'min' => round($stats['min'], 5),
        'max' => round($stats['max'], 5),
        'cv' => round($stats['cv'], 4),
        'reps' => $stats['reps'],
        'warmup' => round($warmup, 5),
        'unstable' => $stats['unstable'],
        'score' => km_bench_score($step, $stats['median'], $extra),
        'extra' => $extra,
    ];
}

/** そのときの様子(負荷・コア・メモリ・PHP・Apache の忙しいワーカー) */
function km_bench_environment(): array
{
    $load = @file_get_contents('/proc/loadavg');
    $parts = is_string($load) ? explode(' ', trim($load)) : [];
    $mem = @file_get_contents('/proc/meminfo');
    $memTotal = is_string($mem) && preg_match('/^MemTotal:\s+(\d+)/m', $mem, $m) === 1 ? (int) $m[1] * 1024 : null;
    $memAvail = is_string($mem) && preg_match('/^MemAvailable:\s+(\d+)/m', $mem, $m) === 1 ? (int) $m[1] * 1024 : null;
    $cpu = @file_get_contents('/proc/cpuinfo');
    $model = is_string($cpu) && preg_match('/^model name\s*:\s*(.+)$/m', $cpu, $m) === 1 ? trim($m[1]) : '';
    $busy = null;
    $curl = curl_init('http://127.0.0.1/server-status?auto');
    if ($curl !== false) {
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_FOLLOWLOCATION => false]);
        $status = curl_exec($curl);
        curl_close($curl);
        if (is_string($status) && preg_match('/^BusyWorkers:\s*(\d+)/m', $status, $m) === 1) {
            $busy = (int) $m[1];
        }
    }
    $cores = km_bench_cores();
    $load1 = isset($parts[0]) ? (float) $parts[0] : null;

    return [
        'at' => time(),
        'load1' => $load1,
        'load5' => isset($parts[1]) ? (float) $parts[1] : null,
        'cores' => $cores,
        'cpuModel' => mb_substr($model, 0, 80),
        'memTotal' => $memTotal,
        'memAvailable' => $memAvail,
        'php' => PHP_VERSION,
        'kernel' => php_uname('r'),
        'apacheBusy' => $busy,
        'busy' => $load1 !== null && $load1 / $cores > KM_BENCH_BUSY_LOAD,
    ];
}

// ---------------------------------------------------------------------------
// 保存(新しい表 km_bench_runs だけ。既存の表は変えない)
// ---------------------------------------------------------------------------

function km_bench_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_bench_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            suite_version INT NOT NULL,
            source VARCHAR(8) NOT NULL,
            label VARCHAR(60) NOT NULL DEFAULT '',
            actor_name VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL,
            started_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            environment MEDIUMTEXT NOT NULL,
            results MEDIUMTEXT NOT NULL,
            total_score DOUBLE NULL,
            INDEX (started_at),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

function km_bench_now(): string
{
    return date('Y-m-d H:i:s');
}

/** 名前(任意)。制御文字を外して 60 文字まで */
function km_bench_label(string $label): string
{
    $label = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label));

    return mb_substr($label, 0, 60);
}

/** 行を画面・CLI 向けの形にする */
function km_bench_row(array $row): array
{
    $env = json_decode((string) $row['environment'], true);
    $results = json_decode((string) $row['results'], true);

    return [
        'id' => (int) $row['id'],
        'suiteVersion' => (int) $row['suite_version'],
        'source' => (string) $row['source'],
        'label' => (string) $row['label'],
        'actor' => (string) $row['actor_name'],
        'status' => (string) $row['status'],
        'startedAt' => (string) $row['started_at'],
        'updatedAt' => (string) $row['updated_at'],
        'finishedAt' => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
        'environment' => is_array($env) ? $env : [],
        'results' => is_array($results) ? $results : ['steps' => []],
        'totalScore' => $row['total_score'] !== null ? (float) $row['total_score'] : null,
    ];
}

function km_bench_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM km_bench_runs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return is_array($row) ? km_bench_row($row) : null;
}

/** 止まった回(running のまま KM_BENCH_RUNNING_STALE 秒動きが無い)を aborted にする */
function km_bench_abort_stale(PDO $pdo): void
{
    $pdo->prepare("UPDATE km_bench_runs SET status = 'aborted' WHERE status = 'running' AND updated_at < ?")
        ->execute([date('Y-m-d H:i:s', time() - KM_BENCH_RUNNING_STALE)]);
}

/**
 * 回を始める。**動いている回があれば断る**(同時に 1 つだけ)。
 *
 * @throws RuntimeException 動いている回があるとき
 */
function km_bench_start(PDO $pdo, string $source, string $label, string $actor): int
{
    if (!in_array($source, ['admin', 'cli'], true)) {
        throw new InvalidArgumentException('知らない source です');
    }
    km_bench_ensure_table($pdo);
    $got = (int) $pdo->query("SELECT GET_LOCK('km_bench_start', 3)")->fetchColumn();
    if ($got !== 1) {
        throw new RuntimeException('ほかの人が始めようとしています。少し待ってから押してください。');
    }
    try {
        km_bench_abort_stale($pdo);
        $running = (int) $pdo->query("SELECT COUNT(*) FROM km_bench_runs WHERE status = 'running'")->fetchColumn();
        if ($running > 0) {
            throw new RuntimeException('ほかのベンチマークが動いています。終わってから押してください(同時に 1 つだけ)。');
        }
        $now = km_bench_now();
        $pdo->prepare('INSERT INTO km_bench_runs (suite_version, source, label, actor_name, status, started_at, updated_at, environment, results)
            VALUES (?, ?, ?, ?, \'running\', ?, ?, ?, ?)')
            ->execute([KM_BENCH_VERSION, $source, km_bench_label($label), mb_substr($actor, 0, 255), $now, $now,
                json_encode(['before' => km_bench_environment()], JSON_UNESCAPED_UNICODE), json_encode(['steps' => new stdClass()])]);

        return (int) $pdo->lastInsertId();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('km_bench_start')");
    }
}

/**
 * 1 つの段を測って保存する。**段は同時に 1 つだけ**(GET_LOCK)。
 *
 * @return array<string, mixed> その段の結果
 */
function km_bench_run_step(PDO $pdo, int $runId, string $step): array
{
    if (!isset(KM_BENCH_STEPS[$step])) {
        throw new InvalidArgumentException('知らない段です');
    }
    $run = km_bench_find($pdo, $runId);
    if ($run === null || $run['status'] !== 'running') {
        throw new InvalidArgumentException('この回は動いていません(止まったか、終わっています)。');
    }
    if (isset($run['results']['steps'][$step])) {
        throw new InvalidArgumentException('この段はもう測りました');
    }
    $got = (int) $pdo->query("SELECT GET_LOCK('km_bench_step', 0)")->fetchColumn();
    if ($got !== 1) {
        throw new RuntimeException('ほかの段を測っています(同時に 1 つだけ)。');
    }
    try {
        // 測っている間に「止まった」とみなされないよう、始める前にも触る
        $pdo->prepare('UPDATE km_bench_runs SET updated_at = ? WHERE id = ?')->execute([km_bench_now(), $runId]);
        try {
            $result = km_bench_measure_step($step);
        } catch (Throwable $exception) {
            $result = ['error' => mb_substr($exception->getMessage(), 0, 200), 'score' => null];
        }
        $result['at'] = time();
        // 測っている間に別の段が書いたものを消さないよう、読み直して足す
        $fresh = km_bench_find($pdo, $runId);
        $results = $fresh['results'] ?? ['steps' => []];
        $steps = is_array($results['steps'] ?? null) ? $results['steps'] : [];
        $steps[$step] = $result;
        $results['steps'] = $steps;
        $pdo->prepare('UPDATE km_bench_runs SET results = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($results, JSON_UNESCAPED_UNICODE), km_bench_now(), $runId]);

        return $result;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('km_bench_step')");
    }
}

/** 回を閉じる: 分野と合計を出し、終わりの様子を足す */
function km_bench_finish(PDO $pdo, int $runId): array
{
    $run = km_bench_find($pdo, $runId);
    if ($run === null || $run['status'] !== 'running') {
        throw new InvalidArgumentException('この回は動いていません');
    }
    $steps = $run['results']['steps'] ?? [];
    $summary = km_bench_summarize(is_array($steps) ? $steps : []);
    $results = ['steps' => $steps, 'groups' => $summary['groups']];
    $env = $run['environment'];
    $env['after'] = km_bench_environment();
    $failed = count(array_filter(is_array($steps) ? $steps : [], static fn ($s): bool => isset($s['error'])));
    $now = km_bench_now();
    $pdo->prepare('UPDATE km_bench_runs SET status = ?, results = ?, environment = ?, total_score = ?, finished_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$failed > 0 ? 'partial' : 'done', json_encode($results, JSON_UNESCAPED_UNICODE), json_encode($env, JSON_UNESCAPED_UNICODE),
            $summary['total'], $now, $now, $runId]);

    return km_bench_find($pdo, $runId) ?? [];
}

/**
 * 外(この PC)から測った応答を 1 回として保存する(scripts/bench-record.php)。
 *
 * @param array<string, mixed> $data {requests, concurrency, errors, seconds, rps, p50, p95, urls}
 */
function km_bench_record_external(PDO $pdo, array $data, string $label): int
{
    $num = static function (string $key, float $min, float $max) use ($data): float {
        $v = $data[$key] ?? null;
        if (!is_int($v) && !is_float($v) || $v < $min || $v > $max) {
            throw new InvalidArgumentException("値が範囲の外です: {$key}");
        }

        return (float) $v;
    };
    $seconds = $num('seconds', 0.001, 3600);
    $result = [
        'median' => round($seconds, 5),
        'reps' => 1,
        'score' => km_bench_score('web.pc', $seconds),
        'extra' => [
            'requests' => (int) $num('requests', 1, 1000),
            'concurrency' => (int) $num('concurrency', 1, 20),
            'errors' => (int) $num('errors', 0, 1000),
            'rps' => round($num('rps', 0, 100000), 1),
            'p50' => round($num('p50', 0, 600000), 1),
            'p95' => round($num('p95', 0, 600000), 1),
        ],
        'at' => time(),
    ];
    km_bench_ensure_table($pdo);
    $steps = ['web.pc' => $result];
    $summary = km_bench_summarize($steps);
    $now = km_bench_now();
    $pdo->prepare('INSERT INTO km_bench_runs (suite_version, source, label, actor_name, status, started_at, updated_at, finished_at, environment, results, total_score)
        VALUES (?, \'pc\', ?, \'\', \'done\', ?, ?, ?, ?, ?, NULL)')
        ->execute([KM_BENCH_VERSION, km_bench_label($label), $now, $now, $now, json_encode(['before' => ['at' => time()]]),
            json_encode(['steps' => $steps, 'groups' => $summary['groups']], JSON_UNESCAPED_UNICODE)]);

    return (int) $pdo->lastInsertId();
}

/** @return list<array<string, mixed>> 新しい順 */
function km_bench_list(PDO $pdo, int $limit = 50): array
{
    km_bench_ensure_table($pdo);
    km_bench_abort_stale($pdo);
    $stmt = $pdo->prepare('SELECT * FROM km_bench_runs ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    $stmt->execute();

    return array_map('km_bench_row', $stmt->fetchAll());
}

function km_bench_delete(PDO $pdo, int $id): bool
{
    km_bench_ensure_table($pdo);
    $stmt = $pdo->prepare("DELETE FROM km_bench_runs WHERE id = ? AND status <> 'running'");
    $stmt->execute([$id]);

    return $stmt->rowCount() > 0;
}
