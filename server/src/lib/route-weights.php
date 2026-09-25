<?php

declare(strict_types=1);

/**
 * 経路の重みを**一般の既定として配る**(2026-09-25、利用者の指示)。
 *
 *   管理アプリ ──(POST api/route-weights.php・管理者だけ)──▶ km_settings 'route_weights'
 *        ├─▶ Website: lib/map-data.php が `routeWeights`(画素に直したもの)を載せる → Main/dijkstra.js
 *        └─▶ アプリ: api/app-map.php の応答(地図が最新のときも)に `routeWeights` を載せる → RouteWeights
 *
 * ## 単位はアプリに合わせて1つだけ持つ
 *
 * メートルと倍率(アプリの `RouteSearch.kt` の `RouteWeights` と同じ名前・同じ意味)。
 * Website は画素で持っているので、渡すときだけ [km_route_weights_for_web] で直す(10px/m)。
 * **2つの形で保存しない** —— 片方だけ書き換わると、アプリと Website で違う道を案内する。
 *
 * ## 配っていないときは何も載せない
 *
 * 未設定なら Website もアプリも**自分の既定**(KM_ROUTE_WEIGHTS / RouteWeights())で動く。
 * 既定の値はこの 3 か所で同じ(check.php の route-weights が突き合わせる)。
 */

require_once __DIR__ . '/settings.php';

const KM_ROUTE_WEIGHTS_SETTING = 'route_weights';

/** 既定。アプリの RouteWeights() と Website の KM_ROUTE_WEIGHTS(画素に直す前)と同じ値。 */
const KM_ROUTE_WEIGHTS_DEFAULTS = [
    'floorTransferMeters' => 20.0,
    'entranceTransferMeters' => 15.0,
    'outsideMultiplier' => 1.3,
    'noRoomPassThrough' => true,
    'verticalPenalty' => 4.0,
    'fewerFloorsMultiplier' => 2.0,
    'rainOutsideMultiplier' => 3.0,
];

/**
 * 受け付ける範囲。**倍率は 1 以上** —— 1 未満だと「屋内優先」「減らす」が逆に効く。
 * 上は、打ち間違い(桁の多い値)で経路が全部おかしくなるのを止める程度に広く取る。
 */
const KM_ROUTE_WEIGHTS_RANGES = [
    'floorTransferMeters' => [0.1, 500.0],
    'entranceTransferMeters' => [0.0, 500.0],
    'outsideMultiplier' => [1.0, 20.0],
    'verticalPenalty' => [1.0, 20.0],
    'fewerFloorsMultiplier' => [1.0, 20.0],
    'rainOutsideMultiplier' => [1.0, 20.0],
];

/** 画面に出す名前(エラーの文と管理画面で使う)。 */
const KM_ROUTE_WEIGHTS_LABELS = [
    'floorTransferMeters' => '階段・エレベーター 1 層ぶん(m)',
    'entranceTransferMeters' => '屋内外の出入り 1 回ぶん(m)',
    'outsideMultiplier' => '屋外の道の倍率',
    'noRoomPassThrough' => '部屋を通り抜けない',
    'verticalPenalty' => '好みでない昇降の倍率',
    'fewerFloorsMultiplier' => '「階の移動を減らす」の倍率',
    'rainOutsideMultiplier' => '「雨の日」の屋外倍率',
];

/**
 * 受け取った重みを検める。**無い項目は既定で埋め、知らない項目は捨てる。**
 *
 * @param array<string,mixed> $input
 * @return array<string,float|bool>
 * @throws InvalidArgumentException 範囲外・型違い(どの項目かを文に入れる)
 */
function km_route_weights_normalize(array $input): array
{
    $out = KM_ROUTE_WEIGHTS_DEFAULTS;
    $bad = [];
    foreach (KM_ROUTE_WEIGHTS_RANGES as $key => [$min, $max]) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];
        // 真偽値や "12abc" を数として受けない(is_numeric は "1e3" などは通すが、範囲で止まる)
        if (is_bool($value) || !is_numeric($value)) {
            $bad[] = KM_ROUTE_WEIGHTS_LABELS[$key];
            continue;
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            $bad[] = KM_ROUTE_WEIGHTS_LABELS[$key] . "({$min}〜{$max})";
            continue;
        }
        $out[$key] = round($number, 3);
    }
    if (array_key_exists('noRoomPassThrough', $input)) {
        if (!is_bool($input['noRoomPassThrough'])) {
            $bad[] = KM_ROUTE_WEIGHTS_LABELS['noRoomPassThrough'];
        } else {
            $out['noRoomPassThrough'] = $input['noRoomPassThrough'];
        }
    }
    if ($bad !== []) {
        throw new InvalidArgumentException('受け付けられない値があります: ' . implode('、', $bad));
    }

    return $out;
}

/**
 * 配っている重み。**未設定・壊れているときは null**(= 各自の既定で動く)。
 *
 * @return array<string,float|bool>|null
 */
function km_route_weights_stored(PDO $pdo): ?array
{
    $raw = km_setting_get($pdo, KM_ROUTE_WEIGHTS_SETTING, '');
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('km_route_weights_stored: 保存された重みを読めません(既定で動きます)');
        return null;
    }
    try {
        return km_route_weights_normalize($decoded);
    } catch (InvalidArgumentException $exception) {
        error_log('km_route_weights_stored: ' . $exception->getMessage() . '(既定で動きます)');
        return null;
    }
}

/**
 * 配る。検めてから保存し、保存した形を返す。
 *
 * @param array<string,mixed> $input
 * @return array<string,float|bool>
 */
function km_route_weights_publish(PDO $pdo, array $input): array
{
    $weights = km_route_weights_normalize($input);
    $json = json_encode($weights, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    // km_settings.value は VARCHAR(255)。7 項目の数と真偽値なので収まるが、超えたら切らずに断る
    if (strlen($json) > 255) {
        throw new InvalidArgumentException('重みが長すぎて保存できません。');
    }
    km_setting_set($pdo, KM_ROUTE_WEIGHTS_SETTING, $json);

    return $weights;
}

/** 配るのをやめる(= 各自の既定へ戻す)。 */
function km_route_weights_reset(PDO $pdo): void
{
    km_setting_set($pdo, KM_ROUTE_WEIGHTS_SETTING, '');
}

/**
 * Website の形(Main/dijkstra.js の KM_ROUTE_WEIGHTS)へ直す。距離は 10px/m で画素に。
 *
 * @param array<string,float|bool> $weights
 * @return array<string,float|bool>
 */
function km_route_weights_for_web(array $weights): array
{
    $pxPerMeter = 10.0;
    return [
        'floorTransferPx' => round((float) $weights['floorTransferMeters'] * $pxPerMeter, 3),
        'entranceTransferPx' => round((float) $weights['entranceTransferMeters'] * $pxPerMeter, 3),
        'outsideMultiplier' => (float) $weights['outsideMultiplier'],
        'noRoomPassThrough' => (bool) $weights['noRoomPassThrough'],
        'verticalPenalty' => (float) $weights['verticalPenalty'],
        'fewerFloorsMultiplier' => (float) $weights['fewerFloorsMultiplier'],
        'rainOutsideMultiplier' => (float) $weights['rainOutsideMultiplier'],
    ];
}
