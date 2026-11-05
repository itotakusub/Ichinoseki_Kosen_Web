<?php

declare(strict_types=1);

/**
 * 一関の天気(2026-11-05、利用者の指示「一関の天気を取得して、天候に関係する項目に自動でチェックを付ける」)。
 *
 * - **気象庁の JSON を、このサーバーが取りに行く**(利用者の答え。第三者の API に頼らない)。
 *   ブラウザやアプリは api/weather.php を読む(公開の地図の CSP は connect-src 'self' のまま)。
 *   **利用者の情報は何も送らない**(気象庁へ行くのはこのサーバーで、中身は決まった URL だけ)
 * - 一関は気象庁の区分で「岩手県 内陸」(030010)。頂いた URL の city=030030 は大船渡(沿岸南部)で違った。
 *   予報の気温は「一関」の地点(33911)、今の雨はアメダス 一関(33911)
 * - 取った物は cache/weather.json に**読み解いた形で**置く(予報 30 分・アメダス 10 分で取り直す)。
 *   取り直しは要求の中で 1 人だけ(flock を待たない)。失敗しても 6 時間までは古いものを使う
 * - 「雨の日」を自動で付けるかは km_weather_rain_now(観測の雨 → 今の 6 時間の降水確率)。雪も雨と同じに扱う
 * - 出典は「気象庁」と表示する(画面と口の source)
 */

require_once __DIR__ . '/weather-telops.php';

const KM_WEATHER_BASE = 'https://www.jma.go.jp/bosai/';
/** 岩手県(予報を出す気象台の区分) */
const KM_WEATHER_OFFICE = '030000';
/** 岩手県 内陸(一関を含む一次細分区域) */
const KM_WEATHER_AREA = '030010';
/** 予報の気温の地点「一関」 */
const KM_WEATHER_TEMP_POINT = '33911';
/** アメダス 一関 */
const KM_WEATHER_AMEDAS = '33911';
const KM_WEATHER_PLACE = '一関';

const KM_WEATHER_FORECAST_TTL = 1800;
const KM_WEATHER_AMEDAS_TTL = 600;
/** 取り直せないとき、古いものを使ってよい長さ */
const KM_WEATHER_STALE_MAX = 21600;
/** 観測がこれより古ければ「今の雨」には使わない */
const KM_WEATHER_OBSERVED_MAX_AGE = 1800;
/** 今の 6 時間の降水確率がこれ以上なら「雨」 */
const KM_WEATHER_RAIN_POP = 50;
/** 1 時間の雨量がこれ以上なら「降っている」(10 分の雨量が 0 より大きいときも) */
const KM_WEATHER_RAIN_1H = 0.5;
/** 気温がこれ以下で降っていれば「雪」と言う(どちらも「雨の日」を付けるのは同じ) */
const KM_WEATHER_SNOW_TEMP = 1.0;
/** 予定に出す日数(今日から) */
const KM_WEATHER_DAYS = 7;
const KM_WEATHER_MAX_BYTES = 524288;

/**
 * 天気の番号 → [分類, 日本語, 英語]。表に無い番号は百の位で分類だけ決める。
 *
 * @return array{kind:string, ja:string, en:string}
 */
function km_weather_telop(string $code): array
{
    $kinds = ['100' => 'sunny', '200' => 'cloudy', '300' => 'rain', '400' => 'snow'];
    $row = KM_WEATHER_TELOPS[$code] ?? null;
    if ($row !== null) {
        return ['kind' => $kinds[$row[0]] ?? 'cloudy', 'ja' => $row[1], 'en' => $row[2]];
    }
    $base = preg_match('/\A[1-4]\d\d\z/', $code) === 1 ? $code[0] . '00' : '200';
    $names = ['100' => ['晴', 'CLEAR'], '200' => ['曇', 'CLOUDY'], '300' => ['雨', 'RAIN'], '400' => ['雪', 'SNOW']];

    return ['kind' => $kinds[$base], 'ja' => $names[$base][0], 'en' => $names[$base][1]];
}

/** "2026-11-05T17:00:00+09:00" → UNIX 時刻。読めなければ null */
function km_weather_time(mixed $value): ?int
{
    if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
        return null;
    }
    $time = strtotime($value);

    return $time === false ? null : $time;
}

/** 日本時間の日付・時(サーバーの時間帯の設定に頼らない) */
function km_weather_jst(int $time, string $format): string
{
    return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format($format);
}

/** 数字の文字(気象庁は "" で「無し」を表す)→ int|null */
function km_weather_int(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/\A-?\d{1,3}\z/', $value) === 1) {
        return (int) $value;
    }

    return null;
}

/**
 * 予報の JSON(forecast/data/forecast/030000.json)を読み解く。形が違えば null。
 *
 * @return array{reportedAt:int, days:array<string, array{code:string, text:string, pop:?int, min:?int, max:?int}>, blocks:list<array{from:int, to:int, pop:int}>}|null
 */
function km_weather_parse_forecast(mixed $json, string $area = KM_WEATHER_AREA, string $tempPoint = KM_WEATHER_TEMP_POINT): ?array
{
    if (!is_array($json) || !isset($json[0]['timeSeries']) || !is_array($json[0]['timeSeries'])) {
        return null;
    }
    $reportedAt = km_weather_time($json[0]['reportDatetime'] ?? null);
    if ($reportedAt === null) {
        return null;
    }
    $days = [];
    $blocks = [];
    $areaOf = static function (array $series, string $code): ?array {
        foreach (($series['areas'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['area']['code'] ?? '') === $code) {
                return $item;
            }
        }

        return null;
    };
    $dateOf = static fn (int $time): string => km_weather_jst($time, 'Y-m-d');

    foreach ($json[0]['timeSeries'] as $series) {
        if (!is_array($series) || !is_array($series['timeDefines'] ?? null)) {
            continue;
        }
        $times = array_map('km_weather_time', $series['timeDefines']);
        if (($item = $areaOf($series, $area)) !== null && is_array($item['weatherCodes'] ?? null)) {
            // 3 日分の天気(今日・明日・あさって)
            foreach ($times as $index => $time) {
                $code = (string) ($item['weatherCodes'][$index] ?? '');
                if ($time === null || preg_match('/\A\d{3}\z/', $code) !== 1) {
                    continue;
                }
                $text = is_string($item['weathers'][$index] ?? null) ? $item['weathers'][$index] : '';
                // 気象庁の文は全角の空白で区切ってある(「雨　夜遅く　くもり」)
                $text = trim((string) preg_replace('/[\s　]+/u', ' ', $text));
                $days[$dateOf($time)] = ['code' => $code, 'text' => mb_substr($text, 0, 80), 'pop' => null, 'min' => null, 'max' => null];
            }
        } elseif ($item !== null && is_array($item['pops'] ?? null)) {
            // 6 時間ごとの降水確率。時刻は区切りの始まり
            foreach ($times as $index => $time) {
                $pop = km_weather_int($item['pops'][$index] ?? null);
                if ($time !== null && $pop !== null && $pop >= 0 && $pop <= 100) {
                    $blocks[] = ['from' => $time, 'to' => $time + 21600, 'pop' => $pop];
                }
            }
        } elseif (($point = $areaOf($series, $tempPoint)) !== null && is_array($point['temps'] ?? null)) {
            // 気温。0 時の値はその日の最低、9 時の値はその日の最高
            foreach ($times as $index => $time) {
                $temp = km_weather_int($point['temps'][$index] ?? null);
                if ($time === null || $temp === null) {
                    continue;
                }
                $key = (int) km_weather_jst($time, 'G') === 0 ? 'min' : 'max';
                $days[$dateOf($time)] ??= ['code' => '', 'text' => '', 'pop' => null, 'min' => null, 'max' => null];
                $days[$dateOf($time)][$key] = $temp;
            }
        }
    }

    // 週間(7 日)。3 日分に無い日の天気と降水確率を足す(週間の気温は盛岡の地点しか無いので使わない)
    foreach (($json[1]['timeSeries'] ?? []) as $series) {
        if (!is_array($series) || !is_array($series['timeDefines'] ?? null) || ($item = $areaOf($series, $area)) === null || !is_array($item['weatherCodes'] ?? null)) {
            continue;
        }
        foreach ($series['timeDefines'] as $index => $define) {
            $time = km_weather_time($define);
            $code = (string) ($item['weatherCodes'][$index] ?? '');
            if ($time === null || preg_match('/\A\d{3}\z/', $code) !== 1) {
                continue;
            }
            $date = $dateOf($time);
            $days[$date] ??= ['code' => '', 'text' => '', 'pop' => null, 'min' => null, 'max' => null];
            if ($days[$date]['code'] === '') {
                $days[$date]['code'] = $code;
            }
            $days[$date]['pop'] ??= km_weather_int($item['pops'][$index] ?? null);
        }
    }

    // その日の降水確率は、6 時間ごとの値があれば、その日の中の一番高いもの
    foreach ($blocks as $block) {
        $date = $dateOf($block['from']);
        if (isset($days[$date]) && ($days[$date]['popFromBlocks'] ?? false) !== true) {
            $days[$date]['pop'] = $block['pop'];
            $days[$date]['popFromBlocks'] = true;
        } elseif (isset($days[$date])) {
            $days[$date]['pop'] = max((int) $days[$date]['pop'], $block['pop']);
        }
    }
    foreach ($days as $date => $day) {
        unset($days[$date]['popFromBlocks']);
        if ($day['code'] === '') {
            unset($days[$date]);   // 気温だけの日(天気の無い日)は出さない
        }
    }
    ksort($days);

    return ['reportedAt' => $reportedAt, 'days' => $days, 'blocks' => $blocks];
}

/**
 * アメダスの 1 地点の 3 時間分(amedas/data/point/<地点>/<日付>_<時>.json)から、一番新しい観測を取る。
 * 値は [値, 品質]。品質 0(正常)・1(準正常)だけ使う。
 *
 * @return array{at:int, temp:?float, precip10m:?float, precip1h:?float}|null
 */
function km_weather_parse_amedas(mixed $json): ?array
{
    if (!is_array($json) || $json === []) {
        return null;
    }
    $keys = array_filter(array_keys($json), static fn ($key): bool => preg_match('/\A\d{14}\z/', (string) $key) === 1);
    if ($keys === []) {
        return null;
    }
    $latest = (string) max(array_map('strval', $keys));
    $row = $json[$latest];
    if (!is_array($row)) {
        return null;
    }
    $value = static function (mixed $pair): ?float {
        if (!is_array($pair) || !isset($pair[0]) || !(is_int($pair[0]) || is_float($pair[0]))) {
            return null;
        }
        $quality = $pair[1] ?? null;

        return ($quality === 0 || $quality === 1) ? (float) $pair[0] : null;
    };
    $at = DateTimeImmutable::createFromFormat('YmdHis', $latest, new DateTimeZone('Asia/Tokyo'));
    if ($at === false) {
        return null;
    }

    return [
        'at' => $at->getTimestamp(),
        'temp' => $value($row['temp'] ?? null),
        'precip10m' => $value($row['precipitation10m'] ?? null),
        'precip1h' => $value($row['precipitation1h'] ?? null),
    ];
}

/**
 * アメダスの latest_time.txt から、その時刻の 3 時間分のファイルの名前(<日付>_<時>)。日本時間で数える。
 */
function km_weather_amedas_block(string $latestTime): ?string
{
    $time = km_weather_time(trim($latestTime));
    if ($time === null) {
        return null;
    }
    $hour = intdiv((int) km_weather_jst($time, 'G'), 3) * 3;

    return km_weather_jst($time, 'Ymd') . '_' . sprintf('%02d', $hour);
}

/**
 * 今「雨の日」にするか。**観測の雨 → 今の 6 時間の降水確率**の順に見る。
 *
 * @param array{forecast?:?array, forecastAt?:int, amedas?:?array} $state cache/weather.json の中身
 * @return array{rain:bool, reason:string, snow:bool, pop:?int, observed:?array}
 *         reason は observed(降っている)・forecast(予報)・none(雨でない)・unknown(分からない)
 */
function km_weather_rain_now(array $state, int $now): array
{
    $observed = is_array($state['amedas'] ?? null) ? $state['amedas'] : null;
    if ($observed !== null && ($now - (int) $observed['at']) > KM_WEATHER_OBSERVED_MAX_AGE) {
        $observed = null;   // 古い観測は「今」に使わない
    }
    $forecast = is_array($state['forecast'] ?? null) ? $state['forecast'] : null;
    if ($forecast !== null && ($now - (int) ($state['forecastAt'] ?? 0)) > KM_WEATHER_STALE_MAX) {
        $forecast = null;
    }
    $pop = null;
    foreach (($forecast['blocks'] ?? []) as $block) {
        if ($block['from'] <= $now && $now < $block['to']) {
            $pop = (int) $block['pop'];
            break;
        }
    }
    $raining = $observed !== null
        && ((($observed['precip10m'] ?? 0.0) > 0.0) || (($observed['precip1h'] ?? 0.0) >= KM_WEATHER_RAIN_1H));
    $snow = $raining && $observed['temp'] !== null && $observed['temp'] <= KM_WEATHER_SNOW_TEMP;
    if ($raining) {
        $reason = 'observed';
    } elseif ($pop !== null && $pop >= KM_WEATHER_RAIN_POP) {
        $reason = 'forecast';
    } elseif ($observed === null && $pop === null) {
        $reason = 'unknown';
    } else {
        $reason = 'none';
    }

    return [
        'rain' => $reason === 'observed' || $reason === 'forecast',
        'reason' => $reason,
        'snow' => $snow,
        'pop' => $pop,
        'observed' => $observed,
    ];
}

/**
 * 口(api/weather.php)と予定に返す形。$state は cache/weather.json の中身。
 *
 * @return array<string, mixed>
 */
function km_weather_public(array $state, int $now): array
{
    $rain = km_weather_rain_now($state, $now);
    $forecast = is_array($state['forecast'] ?? null) && ($now - (int) ($state['forecastAt'] ?? 0)) <= KM_WEATHER_STALE_MAX ? $state['forecast'] : null;
    $days = [];
    $today = km_weather_jst($now, 'Y-m-d');
    foreach (($forecast['days'] ?? []) as $date => $day) {
        if ($date < $today || count($days) >= KM_WEATHER_DAYS) {
            continue;
        }
        $telop = km_weather_telop($day['code']);
        $days[] = [
            'date' => $date,
            'code' => $day['code'],
            'kind' => $telop['kind'],
            'telop' => $telop['ja'],
            'telopEn' => $telop['en'],
            'pop' => $day['pop'],
            'min' => $day['min'],
            'max' => $day['max'],
        ];
    }
    $observed = $rain['observed'];

    return [
        'rain' => $rain['rain'],
        'reason' => $rain['reason'],
        'snow' => $rain['snow'],
        'pop' => $rain['pop'],
        'observed' => $observed === null ? null : [
            'at' => km_weather_jst((int) $observed['at'], 'c'),
            'temp' => $observed['temp'],
            'precip10m' => $observed['precip10m'],
            'precip1h' => $observed['precip1h'],
        ],
        'days' => $days,
        'place' => KM_WEATHER_PLACE,
        'source' => '気象庁',
        'reportedAt' => $forecast === null ? null : km_weather_jst((int) $forecast['reportedAt'], 'c'),
    ];
}

// ---------------------------------------------------------------------------
// 取りに行く・置く
// ---------------------------------------------------------------------------

function km_weather_cache_path(): string
{
    return __DIR__ . '/../cache/weather.json';
}

/** 自分が持ち主で、ほかの人が書けないものだけ信じる(logto_guard.php と同じ考え) */
function km_weather_cache_trusted(string $path): bool
{
    $stat = @stat($path);
    if ($stat === false) {
        return false;
    }
    if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
        return false;
    }

    return ($stat['mode'] & 0022) === 0;
}

/** @return array<string, mixed> */
function km_weather_cache_read(string $path): array
{
    if (!is_file($path) || !km_weather_cache_trusted($path)) {
        return [];
    }
    $json = json_decode((string) @file_get_contents($path), true);

    return is_array($json) ? $json : [];
}

function km_weather_cache_write(string $path, array $state): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * 気象庁から 1 つ取る。**決まった道だけ**(KM_WEATHER_BASE の下)・転送しない・512KB まで。
 * 失敗すれば null(理由はログ)。
 */
function km_weather_fetch(string $path): ?string
{
    if (preg_match('#\A[a-z0-9_/.-]{1,120}\z#', $path) !== 1 || str_contains($path, '..')) {
        return null;
    }
    $curl = curl_init(KM_WEATHER_BASE . $path);
    if ($curl === false) {
        return null;
    }
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'KosenMap-weather/1 (+https://ito4.jp/)',
        CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > KM_WEATHER_MAX_BYTES) {
                return 0;   // 大きすぎれば止める
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($ok !== true || $status !== 200 || $body === '') {
        error_log('km_weather_fetch failed: ' . $path . ' status=' . $status . ' error=' . $error);
        return null;
    }

    return $body;
}

/** 予報を取り直す。取れなければ null */
function km_weather_refresh_forecast(): ?array
{
    $body = km_weather_fetch('forecast/data/forecast/' . KM_WEATHER_OFFICE . '.json');

    return $body === null ? null : km_weather_parse_forecast(json_decode($body, true));
}

/** アメダスを取り直す。新しい 3 時間のファイルがまだ空なら、1 つ前の 3 時間を見る */
function km_weather_refresh_amedas(): ?array
{
    $latest = km_weather_fetch('amedas/data/latest_time.txt');
    $block = $latest === null ? null : km_weather_amedas_block($latest);
    if ($block === null) {
        return null;
    }
    $body = km_weather_fetch('amedas/data/point/' . KM_WEATHER_AMEDAS . '/' . $block . '.json');
    $observed = $body === null ? null : km_weather_parse_amedas(json_decode($body, true));
    if ($observed === null) {
        $previous = km_weather_amedas_block(km_weather_jst((int) km_weather_time(trim((string) $latest)) - 10800, 'c'));
        $body = $previous === null ? null : km_weather_fetch('amedas/data/point/' . KM_WEATHER_AMEDAS . '/' . $previous . '.json');
        $observed = $body === null ? null : km_weather_parse_amedas(json_decode($body, true));
    }

    return $observed;
}

/**
 * 今の天気(置いてあるもの。古ければ 1 人だけ取り直す)。cache/weather.json の中身を返す。
 *
 * @return array<string, mixed>
 */
function km_weather_state(?int $now = null, ?string $path = null): array
{
    $now ??= time();
    $path ??= km_weather_cache_path();
    $state = km_weather_cache_read($path);
    $forecastStale = ($now - (int) ($state['forecastTriedAt'] ?? 0)) >= KM_WEATHER_FORECAST_TTL;
    $amedasStale = ($now - (int) ($state['amedasTriedAt'] ?? 0)) >= KM_WEATHER_AMEDAS_TTL;
    if (!$forecastStale && !$amedasStale) {
        return $state;
    }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return $state;
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return $state;   // ほかの人が取り直している。古いものを返す
        }
        // 待っている間にほかの人が書いたかもしれない
        $state = km_weather_cache_read($path);
        if (($now - (int) ($state['forecastTriedAt'] ?? 0)) >= KM_WEATHER_FORECAST_TTL) {
            // 失敗しても、試した時刻は置く(気象庁が落ちているときに毎回取りに行かない)
            $state['forecastTriedAt'] = $now;
            $forecast = km_weather_refresh_forecast();
            if ($forecast !== null) {
                $state['forecast'] = $forecast;
                $state['forecastAt'] = $now;
            }
        }
        if (($now - (int) ($state['amedasTriedAt'] ?? 0)) >= KM_WEATHER_AMEDAS_TTL) {
            $state['amedasTriedAt'] = $now;
            $amedas = km_weather_refresh_amedas();
            if ($amedas !== null) {
                $state['amedas'] = $amedas;
            }
        }
        km_weather_cache_write($path, $state);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return $state;
}
