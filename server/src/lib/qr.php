<?php

declare(strict_types=1);

/**
 * QR の符号化(バイトモード、型1〜10)。
 *
 * ## なぜ自前なのか
 *
 * **外部の QR 生成サービスは使わない。** アクセスコードを他所のサーバーへ送ることになり、
 * そこに残ったコードで誰でも地図を落とせるようになる。
 * 依存も足さない(`composer.json` に1つ足すたびに、更新の面倒と供給経路の心配が増える)。
 *
 * ## `scripts/new-map-qr.ps1` からの移植
 *
 * 配信を Website 一括にした(2026-09-03)ので、QR も管理画面で出せる必要がある。
 * **仕様の表も手順も PowerShell 版と1対1**にしてあり、
 * `scripts/check-qr-port.ps1` が同じ入力で両者のモジュール並びを突き合わせる ——
 * QR は「読めるかどうか」でしか正しさを確かめられないので、
 * **既に動いている実装と一致することを機械で確かめる。**
 *
 * 出力は SVG。PNG にしないのは、画像ライブラリ(GD/Imagick)の有無に依存させないため。
 */

/**
 * 型ごとの [誤り訂正符号語数/ブロック, グループ1のブロック数, グループ1のデータ符号語数,
 * グループ2のブロック数, グループ2のデータ符号語数]。
 */
const KM_QR_BLOCKS = [
    1  => ['L' => [7, 1, 19, 0, 0],   'M' => [10, 1, 16, 0, 0],  'Q' => [13, 1, 13, 0, 0],  'H' => [17, 1, 9, 0, 0]],
    2  => ['L' => [10, 1, 34, 0, 0],  'M' => [16, 1, 28, 0, 0],  'Q' => [22, 1, 22, 0, 0],  'H' => [28, 1, 16, 0, 0]],
    3  => ['L' => [15, 1, 55, 0, 0],  'M' => [26, 1, 44, 0, 0],  'Q' => [18, 2, 17, 0, 0],  'H' => [22, 2, 13, 0, 0]],
    4  => ['L' => [20, 1, 80, 0, 0],  'M' => [18, 2, 32, 0, 0],  'Q' => [26, 2, 24, 0, 0],  'H' => [16, 4, 9, 0, 0]],
    5  => ['L' => [26, 1, 108, 0, 0], 'M' => [24, 2, 43, 0, 0],  'Q' => [18, 2, 15, 2, 16], 'H' => [22, 2, 11, 2, 12]],
    6  => ['L' => [18, 2, 68, 0, 0],  'M' => [16, 4, 27, 0, 0],  'Q' => [24, 4, 19, 0, 0],  'H' => [28, 4, 15, 0, 0]],
    7  => ['L' => [20, 2, 78, 0, 0],  'M' => [18, 4, 31, 0, 0],  'Q' => [18, 2, 14, 4, 15], 'H' => [26, 4, 13, 1, 14]],
    8  => ['L' => [24, 2, 97, 0, 0],  'M' => [22, 2, 38, 2, 39], 'Q' => [22, 4, 18, 2, 19], 'H' => [26, 4, 14, 2, 15]],
    9  => ['L' => [30, 2, 116, 0, 0], 'M' => [22, 3, 36, 2, 37], 'Q' => [20, 4, 16, 4, 17], 'H' => [24, 4, 12, 4, 13]],
    10 => ['L' => [18, 2, 68, 2, 69], 'M' => [26, 4, 43, 1, 44], 'Q' => [24, 6, 19, 2, 20], 'H' => [28, 6, 15, 2, 16]],
];

/** 型ごとの位置合わせパターンの中心座標。型1には無い。 */
const KM_QR_ALIGNMENT = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
];

/** 符号語の後ろに付く余りビット。型1は0、型2〜6は7、型7〜10は0。 */
const KM_QR_REMAINDER_BITS = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];

/** 形式情報に入る誤り訂正レベルの2ビット表現。**強さの順と数値の順が一致しない**ので表で持つ。 */
const KM_QR_EC_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

/**
 * ガロア体 GF(256) の指数表と対数表。**1度だけ作る。**
 *
 * @return array{0: array<int,int>, 1: array<int,int>}
 */
function km_qr_gf_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }

    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $value = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $value;
        $log[$value] = $i;
        $value <<= 1;
        if ($value & 0x100) {
            $value ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }

    $tables = [$exp, $log];

    return $tables;
}

function km_qr_gf_product(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = km_qr_gf_tables();

    return $exp[$log[$a] + $log[$b]];
}

/** 誤り訂正符号語の生成多項式。次数ぶんだけ (x - a^i) を掛け合わせる。 */
function km_qr_generator_polynomial(int $degree): array
{
    [$exp] = km_qr_gf_tables();
    $poly = [1];
    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        for ($j = 0, $n = count($poly); $j < $n; $j++) {
            $next[$j] ^= $poly[$j];
            $next[$j + 1] ^= km_qr_gf_product($poly[$j], $exp[$i]);
        }
        $poly = $next;
    }

    return $poly;
}

/** Reed-Solomon の剰余。これが誤り訂正符号語になる。 */
function km_qr_error_correction(array $data, int $count): array
{
    $generator = km_qr_generator_polynomial($count);
    $length = count($data);
    $remainder = array_merge($data, array_fill(0, $count, 0));

    for ($i = 0; $i < $length; $i++) {
        $factor = $remainder[$i];
        if ($factor === 0) {
            continue;
        }
        for ($j = 0, $n = count($generator); $j < $n; $j++) {
            $remainder[$i + $j] ^= km_qr_gf_product($generator[$j], $factor);
        }
    }

    return array_slice($remainder, $length);
}

/**
 * 収まる一番小さい型を選ぶ。
 *
 * @throws RuntimeException 型10に収まらないとき
 */
function km_qr_version(int $byteCount, string $ec): int
{
    for ($version = 1; $version <= 10; $version++) {
        $spec = KM_QR_BLOCKS[$version][$ec];
        $capacity = $spec[1] * $spec[2] + $spec[3] * $spec[4];
        // モード指示子4ビット + 文字数指示子(型1〜9は8ビット、型10以上は16ビット)
        $headerBits = 4 + ($version < 10 ? 8 : 16);
        if ($headerBits + $byteCount * 8 <= $capacity * 8) {
            return $version;
        }
    }

    throw new RuntimeException("QR にする文字列が長すぎます({$byteCount} バイト)。");
}

/** バイトモードで符号化し、パディングまで済ませた符号語列を返す。 */
function km_qr_data_codewords(array $bytes, int $version, string $ec): array
{
    $spec = KM_QR_BLOCKS[$version][$ec];
    $dataCapacity = $spec[1] * $spec[2] + $spec[3] * $spec[4];

    $bits = [];
    $add = static function (int $value, int $length) use (&$bits): void {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    };

    $add(0x4, 4);                                        // バイトモード
    $add(count($bytes), $version < 10 ? 8 : 16);         // 文字数
    foreach ($bytes as $byte) {
        $add($byte, 8);
    }

    // 終端子は最大4ビット。容量が足りなければその分だけ短くする
    $capacityBits = $dataCapacity * 8;
    $add(0, min(4, $capacityBits - count($bits)));
    // バイト境界まで0で埋める
    if (count($bits) % 8 !== 0) {
        $add(0, 8 - (count($bits) % 8));
    }

    $codewords = array_fill(0, $dataCapacity, 0);
    $filled = intdiv(count($bits), 8);
    for ($i = 0; $i < $filled; $i++) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | $bits[$i * 8 + $j];
        }
        $codewords[$i] = $byte;
    }
    // 残りは 0xEC / 0x11 の繰り返しで埋める(仕様で定められた埋め草)
    $pad = [0xEC, 0x11];
    for ($i = $filled; $i < $dataCapacity; $i++) {
        $codewords[$i] = $pad[($i - $filled) % 2];
    }

    return $codewords;
}

/** ブロックごとに誤り訂正を付け、仕様どおりの順に織り交ぜる。 */
function km_qr_interleave(array $data, int $version, string $ec): array
{
    $spec = KM_QR_BLOCKS[$version][$ec];
    $ecPerBlock = $spec[0];
    $groups = [[$spec[1], $spec[2]], [$spec[3], $spec[4]]];

    $blocks = [];
    $offset = 0;
    foreach ($groups as [$blockCount, $wordsPerBlock]) {
        for ($b = 0; $b < $blockCount; $b++) {
            $chunk = array_slice($data, $offset, $wordsPerBlock);
            $offset += $wordsPerBlock;
            $blocks[] = ['data' => $chunk, 'ec' => km_qr_error_correction($chunk, $ecPerBlock)];
        }
    }

    $result = [];
    $maxData = 0;
    foreach ($blocks as $block) {
        $maxData = max($maxData, count($block['data']));
    }
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $block) {
            if ($i < count($block['data'])) {
                $result[] = $block['data'][$i];
            }
        }
    }
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($blocks as $block) {
            $result[] = $block['ec'][$i];
        }
    }

    return $result;
}

/**
 * 機能パターンを置いた盤面を作る。
 *
 * @return array{modules: array<int, array<int, bool>>, fixed: array<int, array<int, bool>>, size: int}
 */
function km_qr_canvas(int $version): array
{
    $size = $version * 4 + 17;
    $canvas = [
        'size' => $size,
        'modules' => array_fill(0, $size, array_fill(0, $size, false)),
        'fixed' => array_fill(0, $size, array_fill(0, $size, false)),
    ];

    $set = static function (int $x, int $y, bool $dark) use (&$canvas): void {
        if ($x < 0 || $y < 0 || $x >= $canvas['size'] || $y >= $canvas['size']) {
            return;
        }
        $canvas['modules'][$y][$x] = $dark;
        $canvas['fixed'][$y][$x] = true;
    };

    /*
     * **タイミングパターンを先に引く。** 行6・列6を端から端まで塗ってから、
     * 位置検出パターンで上書きさせる。逆にすると位置検出パターンの下辺・右辺が
     * 市松模様に潰れ、読み取り機が位置を掴めなくなる。
     */
    for ($i = 0; $i < $size; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }

    // 位置検出パターン3つ(周囲の分離帯を含めて 9x9 で塗る)
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $set($cx + $dx, $cy + $dy, $distance !== 2 && $distance !== 4);
            }
        }
    }

    // 位置合わせパターン(位置検出パターンと重なる3隅は置かない)
    $positions = KM_QR_ALIGNMENT[$version];
    $last = count($positions) - 1;
    for ($a = 0; $a <= $last; $a++) {
        for ($b = 0; $b <= $last; $b++) {
            if (($a === 0 && $b === 0) || ($a === 0 && $b === $last) || ($a === $last && $b === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $distance = max(abs($dx), abs($dy));
                    $set($positions[$a] + $dx, $positions[$b] + $dy, $distance !== 1);
                }
            }
        }
    }

    /*
     * 形式情報の領域を仮の値で押さえ、データを流し込む対象から外す。
     * **行8・列8を端から端まで塗ってはいけない。** その中には (6,8) と (8,6) の
     * タイミングモジュールが含まれており、潰すと位置合わせが崩れる。
     * 実際に置かれる座標だけを触るよう、本番と同じ関数を使う。
     */
    km_qr_set_format_info($canvas, 'L', 0);

    // 型情報(型7以上のみ)。BCH(18,6) で誤り訂正ビットを作る
    if ($version >= 7) {
        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            // **切り捨てであること。**四捨五入すると型情報が別の行へ書かれる
            $b = intdiv($i, 3);
            $set($a, $b, $bit);
            $set($b, $a, $bit);
        }
    }

    return $canvas;
}

/** 形式情報(誤り訂正レベル+マスク番号)を BCH(15,5) で符号化して2か所に置く。 */
function km_qr_set_format_info(array &$canvas, string $ec, int $pattern): void
{
    $data = (KM_QR_EC_BITS[$ec] << 3) | $pattern;
    $remainder = $data;
    for ($i = 0; $i < 10; $i++) {
        $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
    }
    $bits = (($data << 10) | $remainder) ^ 0x5412;

    $size = $canvas['size'];
    $set = static function (int $x, int $y, bool $dark) use (&$canvas): void {
        if ($x < 0 || $y < 0 || $x >= $canvas['size'] || $y >= $canvas['size']) {
            return;
        }
        $canvas['modules'][$y][$x] = $dark;
        $canvas['fixed'][$y][$x] = true;
    };

    for ($i = 0; $i <= 5; $i++) {
        $set(8, $i, (($bits >> $i) & 1) === 1);
    }
    $set(8, 7, (($bits >> 6) & 1) === 1);
    $set(8, 8, (($bits >> 7) & 1) === 1);
    $set(7, 8, (($bits >> 8) & 1) === 1);
    for ($i = 9; $i < 15; $i++) {
        $set(14 - $i, 8, (($bits >> $i) & 1) === 1);
    }

    for ($i = 0; $i < 8; $i++) {
        $set($size - 1 - $i, 8, (($bits >> $i) & 1) === 1);
    }
    for ($i = 8; $i < 15; $i++) {
        $set(8, $size - 15 + $i, (($bits >> $i) & 1) === 1);
    }
    $set(8, $size - 8, true);
}

/** 符号語をジグザグに流し込む。列6(タイミングパターン)は飛ばす。 */
function km_qr_set_data(array &$canvas, array $codewords): void
{
    $size = $canvas['size'];
    $bitIndex = 0;
    $totalBits = count($codewords) * 8;

    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vertical = 0; $vertical < $size; $vertical++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) === 0;
                $y = $upward ? $size - 1 - $vertical : $vertical;
                if (!$canvas['fixed'][$y][$x] && $bitIndex < $totalBits) {
                    $byte = $codewords[$bitIndex >> 3];
                    $canvas['modules'][$y][$x] = (($byte >> (7 - ($bitIndex & 7))) & 1) === 1;
                    $bitIndex++;
                }
            }
        }
    }
}

function km_qr_mask_condition(int $pattern, int $x, int $y): bool
{
    return match ($pattern) {
        0 => ($x + $y) % 2 === 0,
        1 => $y % 2 === 0,
        2 => $x % 3 === 0,
        3 => ($x + $y) % 3 === 0,
        4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
        5 => (($x * $y) % 2 + ($x * $y) % 3) === 0,
        6 => ((($x * $y) % 2 + ($x * $y) % 3) % 2) === 0,
        7 => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0,
        default => throw new RuntimeException("不正なマスク番号: {$pattern}"),
    };
}

function km_qr_apply_mask(array &$canvas, int $pattern): void
{
    for ($y = 0; $y < $canvas['size']; $y++) {
        for ($x = 0; $x < $canvas['size']; $x++) {
            if (!$canvas['fixed'][$y][$x] && km_qr_mask_condition($pattern, $x, $y)) {
                $canvas['modules'][$y][$x] = !$canvas['modules'][$y][$x];
            }
        }
    }
}

/** 仕様の4つの減点規則。値が小さいマスクほど読み取りやすい。 */
function km_qr_penalty(array $canvas): int
{
    $size = $canvas['size'];
    $m = $canvas['modules'];
    $score = 0;

    // 規則1: 同色が5個以上連続
    for ($pass = 0; $pass < 2; $pass++) {
        for ($a = 0; $a < $size; $a++) {
            $runColor = null;
            $runLength = 0;
            for ($b = 0; $b < $size; $b++) {
                $color = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                if ($color === $runColor) {
                    $runLength++;
                    if ($runLength === 5) {
                        $score += 3;
                    } elseif ($runLength > 5) {
                        $score++;
                    }
                } else {
                    $runColor = $color;
                    $runLength = 1;
                }
            }
        }
    }

    // 規則2: 同色の 2x2 ブロック
    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $color = $m[$y][$x];
            if ($color === $m[$y][$x + 1] && $color === $m[$y + 1][$x] && $color === $m[$y + 1][$x + 1]) {
                $score += 3;
            }
        }
    }

    // 規則3: 位置検出パターンに似た並び(1:1:3:1:1 の前後に空き4)
    $patternA = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
    $patternB = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for ($pass = 0; $pass < 2; $pass++) {
        for ($a = 0; $a < $size; $a++) {
            for ($b = 0; $b <= $size - 11; $b++) {
                $matchA = true;
                $matchB = true;
                for ($k = 0; $k < 11; $k++) {
                    $bit = $pass === 0 ? $m[$a][$b + $k] : $m[$b + $k][$a];
                    $value = $bit ? 1 : 0;
                    if ($value !== $patternA[$k]) {
                        $matchA = false;
                    }
                    if ($value !== $patternB[$k]) {
                        $matchB = false;
                    }
                }
                if ($matchA) {
                    $score += 40;
                }
                if ($matchB) {
                    $score += 40;
                }
            }
        }
    }

    // 規則4: 暗いモジュールの割合が 50% から離れているほど減点
    $dark = 0;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($m[$y][$x]) {
                $dark++;
            }
        }
    }
    $total = $size * $size;
    // **切り捨てであること。**四捨五入すると減点が1段ずれ、選ぶマスクが変わる
    $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

    return $score + $k * 10;
}

/**
 * 文字列を QR のモジュール並びにする。
 *
 * @param int $forcedMask マスクを固定する(0〜7)。-1 なら仕様どおり減点法で選ぶ
 * @return array<int, array<int, bool>> [行][列] = 暗いか
 */
function km_qr_matrix(string $text, string $ec = 'M', int $forcedMask = -1): array
{
    if (!isset(KM_QR_EC_BITS[$ec])) {
        throw new RuntimeException("不正な誤り訂正レベル: {$ec}");
    }

    $bytes = array_values(unpack('C*', $text) ?: []);
    $version = km_qr_version(count($bytes), $ec);
    $data = km_qr_data_codewords($bytes, $version, $ec);
    $codewords = km_qr_interleave($data, $version, $ec);

    // 余りビットは0のまま。符号語列の後ろに足りない分を0バイトとして持たせる
    if (KM_QR_REMAINDER_BITS[$version] > 0) {
        $codewords[] = 0;
    }

    $best = null;
    $bestScore = PHP_INT_MAX;
    $candidates = $forcedMask >= 0 ? [$forcedMask] : range(0, 7);

    foreach ($candidates as $pattern) {
        $canvas = km_qr_canvas($version);
        km_qr_set_data($canvas, $codewords);
        km_qr_apply_mask($canvas, $pattern);
        km_qr_set_format_info($canvas, $ec, $pattern);

        $score = km_qr_penalty($canvas);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $canvas;
        }
    }

    return $best['modules'];
}

/**
 * モジュール並びを SVG にする。
 *
 * **静寂域(quiet zone)を必ず付ける。** 周りの余白が無いと、
 * 背景と地続きになって読み取り機がコードの端を見つけられない。
 *
 * 暗いモジュールを1つの `<path>` にまとめる —— 矩形を数百個並べるより小さく、
 * 印刷したときに継ぎ目の白線が出ない。
 */
function km_qr_svg(array $matrix, int $moduleSize = 8, int $quietZone = 4): string
{
    $size = count($matrix);
    $side = ($size + $quietZone * 2) * $moduleSize;

    $path = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if (!$matrix[$y][$x]) {
                continue;
            }
            $px = ($x + $quietZone) * $moduleSize;
            $py = ($y + $quietZone) * $moduleSize;
            $path .= "M{$px} {$py}h{$moduleSize}v{$moduleSize}h-{$moduleSize}z";
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $side . '" height="' . $side . '"'
        . ' viewBox="0 0 ' . $side . ' ' . $side . '" role="img" aria-label="アクセスコードの QR">'
        . '<rect width="' . $side . '" height="' . $side . '" fill="#ffffff"/>'
        . '<path d="' . $path . '" fill="#000000"/></svg>';
}
