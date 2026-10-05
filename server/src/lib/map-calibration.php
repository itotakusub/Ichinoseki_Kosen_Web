<?php

declare(strict_types=1);

/**
 * 地図の北と距離の補正(2026-10-05、利用者の指示)。管理アプリで決めて、**全員の端末に配る**。
 *
 * - 北: 画像ごとの「地図の上が向いている方角(度)」。手描きの地図は上が北とは限らない。
 *   一般の端末の「進行方向に合わせて回転」と、歩数で現在地を進める向き(PDR)に使う
 * - 距離: 実測区間(2 つの地点の UUID と、実際の距離 m)。端末が階ごとに「距離の測り方」を当てはめる
 *   (手描きの画像は縦と横で縮尺が違ったり、斜めに歪んでいたりする)
 * - 区間の無い階の既定の縮尺(px/m)。以前は管理者の端末にしか無く、一般の端末は常に 10 だった
 *
 * 置き場は km_settings ではなく専用の表(km_settings.value は 255 字で、区間の一覧が入らない)。
 * 配り方は経路の重み(lib/route-weights.php)と同じ: 管理アプリ → api/map-calibration.php → 地図の配信(app-map.php)。
 * Website の経路の距離(10 px/m 固定)は今回は変えない。
 */

require_once __DIR__ . '/db.php';

/** 区間の上限。 */
const KM_MAP_CALIBRATION_MAX_SEGMENTS = 500;

/** 階の鍵の形(アプリの階: 1F〜5F・OUTSIDE・建物の階 BLDG_*)。 */
const KM_MAP_CALIBRATION_FLOOR_PATTERN = '/^[A-Za-z0-9_]{1,40}$/';

function km_map_calibration_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_calibration (
            id TINYINT NOT NULL PRIMARY KEY,
            body MEDIUMTEXT NOT NULL,
            updated_by VARCHAR(191) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ensured = true;
}

/**
 * 検めて揃える。**純粋関数。** おかしな値は理由を付けて断る(InvalidArgumentException)。
 *
 * @param array<string,mixed> $input
 * @return array{mapUpBearingDegrees: float, mapUpBearingByFloor: array<string,float>, defaultPixelsPerMeter: ?float, segments: list<array{fromUuid:string,toUuid:string,meters:float}>}
 */
function km_map_calibration_normalize(array $input): array
{
    $bearing = $input['mapUpBearingDegrees'] ?? 0;
    if (!is_int($bearing) && !is_float($bearing)) {
        throw new InvalidArgumentException('mapUpBearingDegrees は数で入れてください。');
    }
    $bearing = (float) $bearing;
    if (!is_finite($bearing) || $bearing < 0 || $bearing >= 360) {
        throw new InvalidArgumentException('北の向きは 0〜360 度で入れてください。');
    }

    $byFloor = [];
    $rawByFloor = $input['mapUpBearingByFloor'] ?? [];
    if (!is_array($rawByFloor)) {
        throw new InvalidArgumentException('mapUpBearingByFloor の形が違います。');
    }
    foreach ($rawByFloor as $floor => $value) {
        if (!is_string($floor) || preg_match(KM_MAP_CALIBRATION_FLOOR_PATTERN, $floor) !== 1) {
            throw new InvalidArgumentException('階の名前の形が違います。');
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value >= 360) {
            throw new InvalidArgumentException('北の向きは 0〜360 度で入れてください(' . $floor . ')。');
        }
        $byFloor[$floor] = (float) $value;
    }
    if (count($byFloor) > 64) {
        throw new InvalidArgumentException('階が多すぎます。');
    }

    $ppm = $input['defaultPixelsPerMeter'] ?? null;
    if ($ppm !== null) {
        if ((!is_int($ppm) && !is_float($ppm)) || !is_finite((float) $ppm) || $ppm < 0.5 || $ppm > 200) {
            throw new InvalidArgumentException('既定の縮尺は 0.5〜200 px/m で入れてください。');
        }
        $ppm = (float) $ppm;
    }

    $segments = [];
    $rawSegments = $input['segments'] ?? [];
    if (!is_array($rawSegments) || !array_is_list($rawSegments)) {
        throw new InvalidArgumentException('segments の形が違います。');
    }
    if (count($rawSegments) > KM_MAP_CALIBRATION_MAX_SEGMENTS) {
        throw new InvalidArgumentException('実測区間は ' . KM_MAP_CALIBRATION_MAX_SEGMENTS . ' 本までです。');
    }
    foreach ($rawSegments as $segment) {
        if (!is_array($segment)) {
            throw new InvalidArgumentException('実測区間の形が違います。');
        }
        $from = $segment['fromUuid'] ?? null;
        $to = $segment['toUuid'] ?? null;
        $meters = $segment['meters'] ?? null;
        foreach ([$from, $to] as $uuid) {
            if (!is_string($uuid) || preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $uuid) !== 1) {
                throw new InvalidArgumentException('実測区間の地点の形が違います。');
            }
        }
        if ($from === $to) {
            throw new InvalidArgumentException('実測区間の 2 点が同じです。');
        }
        if ((!is_int($meters) && !is_float($meters)) || !is_finite((float) $meters) || $meters < 0.1 || $meters > 1000) {
            throw new InvalidArgumentException('実測の距離は 0.1〜1000 m で入れてください。');
        }
        $segments[] = ['fromUuid' => $from, 'toUuid' => $to, 'meters' => (float) $meters];
    }

    return [
        'mapUpBearingDegrees' => $bearing,
        'mapUpBearingByFloor' => $byFloor,
        'defaultPixelsPerMeter' => $ppm,
        'segments' => $segments,
    ];
}

/** いま配っている補正。配っていない・読めなければ null(端末は自分の既定で動く)。 */
function km_map_calibration_stored(PDO $pdo): ?array
{
    try {
        km_map_calibration_ensure_table($pdo);
        $body = $pdo->query('SELECT body FROM km_map_calibration WHERE id = 1')->fetchColumn();
    } catch (Throwable $exception) {
        error_log('km_map_calibration_stored: ' . $exception->getMessage());
        return null;
    }
    if (!is_string($body) || $body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    try {
        return km_map_calibration_normalize($decoded);
    } catch (InvalidArgumentException $exception) {
        error_log('km_map_calibration_stored: ' . $exception->getMessage() . '(配らずに動きます)');
        return null;
    }
}

/** 配る。検めてから保存し、保存した形を返す。 */
function km_map_calibration_publish(PDO $pdo, array $input, ?string $updatedBy): array
{
    $calibration = km_map_calibration_normalize($input);
    $json = json_encode($calibration, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    km_map_calibration_ensure_table($pdo);
    $pdo->prepare(
        'INSERT INTO km_map_calibration (id, body, updated_by, updated_at) VALUES (1, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by), updated_at = NOW()'
    )->execute([$json, $updatedBy]);

    return $calibration;
}

/** 配るのをやめる(= 各端末の既定へ戻す)。行は消さず空にする(誰がやめたかを残す)。 */
function km_map_calibration_reset(PDO $pdo, ?string $updatedBy): void
{
    km_map_calibration_ensure_table($pdo);
    $pdo->prepare(
        "INSERT INTO km_map_calibration (id, body, updated_by, updated_at) VALUES (1, '', ?, NOW())
         ON DUPLICATE KEY UPDATE body = '', updated_by = VALUES(updated_by), updated_at = NOW()"
    )->execute([$updatedBy]);
}
