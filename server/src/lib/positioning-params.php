<?php

declare(strict_types=1);

/**
 * アプリの測位のパラメータ(2026-10-06、利用者の指示「各パラメータは編集(確認必要)+表示」・測位の再構築)。
 *
 * 管理アプリで編集 → その端末で試す → **全員の端末に配る**(経路の重み・北と距離の補正と同じ形)。
 * 管理アプリ → api/positioning-params.php(管理者のトークン)→ アプリは地図を取るときに GET で読む。
 *
 * **キー・範囲・既定はアプリの `positioning/PositioningParams.kt` の POSITIONING_PARAM_SPECS と同じ**
 * (check.php の positioning-params が突き合わせる。片方だけ足すと、配っても効かない・配れない)。
 * 置き場は専用の表(km_settings.value は 255 字で、28 項目の JSON が入らない)。
 */

require_once __DIR__ . '/db.php';

/** キー => [最小, 最大, 既定, 整数か]。 */
const KM_POSITIONING_PARAM_SPECS = [
    'rssi_ema_alpha' => [0.05, 1.0, 0.5, false],
    'rssi_window_seconds' => [2.0, 120.0, 20.0, true],
    'path_loss_tx_dbm' => [-70.0, -10.0, -40.0, false],
    'path_loss_exponent' => [1.5, 5.0, 2.8, false],
    'wall_attenuation_db' => [0.0, 15.0, 4.0, false],
    'trilateration_min_anchors' => [3.0, 10.0, 3.0, true],
    'ls_iterations' => [1.0, 50.0, 12.0, true],
    'knn_k' => [1.0, 10.0, 4.0, true],
    'fingerprint_missing_dbm' => [-110.0, -80.0, -95.0, false],
    'fingerprint_compare_min_dbm' => [-95.0, -60.0, -85.0, false],
    'confidence_high_db' => [2.0, 20.0, 8.0, false],
    'coverage_db' => [5.0, 30.0, 14.0, false],
    'centroid_exponent' => [0.1, 3.0, 1.0, false],
    'rtt_enabled' => [0.0, 1.0, 1.0, true],
    'rtt_min_responders' => [2.0, 10.0, 3.0, true],
    'pdr_step_noise_ratio' => [0.0, 1.0, 0.3, false],
    'pdr_heading_noise_deg' => [0.0, 45.0, 12.0, false],
    'ar_translation_noise_ratio' => [0.0, 0.5, 0.05, false],
    'ar_depth_weight' => [0.0, 1.0, 0.5, false],
    'outlier_gate_sigma' => [1.0, 10.0, 3.0, false],
    'outlier_min_m' => [1.0, 30.0, 6.0, false],
    'walkable_max_m' => [0.5, 20.0, 4.0, false],
    // 部屋・出入口の点のまわりを歩ける所にする半径(2026-10-08。部屋の中の候補が外れ値にされていた)
    'room_radius_m' => [1.0, 20.0, 5.0, false],
    'particle_count' => [100.0, 3000.0, 500.0, true],
    'particle_drift_mps' => [0.0, 3.0, 0.5, false],
    'particle_resample_ess' => [0.1, 1.0, 0.5, false],
    'particle_off_walk_penalty' => [0.0, 1.0, 0.3, false],
    'particle_wall_penalty' => [0.0, 1.0, 0.02, false],
    'ekf_process_noise_mps' => [0.0, 3.0, 0.5, false],
];

function km_positioning_params_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_positioning_params (
            id TINYINT NOT NULL PRIMARY KEY,
            body TEXT NOT NULL,
            updated_by VARCHAR(191) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ensured = true;
}

/**
 * 検めて揃える。**純粋関数。** 全キーを既定で埋めた形を返す。
 * 知らないキー・数でない値・範囲の外は理由を付けて断る(InvalidArgumentException)。
 * アプリは送る前に範囲へ丸めるので、ここで断られるのは版の食い違いか改ざん。
 *
 * @param array<string,mixed> $input
 * @return array<string,float|int>
 */
function km_positioning_params_normalize(array $input): array
{
    $result = [];
    foreach ($input as $key => $_) {
        if (!is_string($key) || !array_key_exists($key, KM_POSITIONING_PARAM_SPECS)) {
            throw new InvalidArgumentException('知らない項目です: ' . mb_substr((string) $key, 0, 40) . '(アプリとサーバーの版が違う見込み)');
        }
    }
    foreach (KM_POSITIONING_PARAM_SPECS as $key => [$min, $max, $default, $integer]) {
        if (!array_key_exists($key, $input)) {
            $result[$key] = $integer ? (int) $default : (float) $default;
            continue;
        }
        $value = $input[$key];
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value)) {
            throw new InvalidArgumentException($key . ' は数で入れてください。');
        }
        $value = (float) $value;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException($key . ' は ' . $min . '〜' . $max . ' の範囲で入れてください。');
        }
        $result[$key] = $integer ? (int) round($value) : $value;
    }
    return $result;
}

/** いま配っている値。配っていない・読めなければ null(端末は自分の既定で動く)。 */
function km_positioning_params_stored(PDO $pdo): ?array
{
    try {
        km_positioning_params_ensure_table($pdo);
        $body = $pdo->query('SELECT body FROM km_positioning_params WHERE id = 1')->fetchColumn();
    } catch (Throwable $exception) {
        error_log('km_positioning_params_stored: ' . $exception->getMessage());
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
        return km_positioning_params_normalize($decoded);
    } catch (InvalidArgumentException $exception) {
        error_log('km_positioning_params_stored: ' . $exception->getMessage() . '(配らずに動きます)');
        return null;
    }
}

/** 配る。検めてから保存し、保存した形を返す。 */
function km_positioning_params_publish(PDO $pdo, array $input, ?string $updatedBy): array
{
    $params = km_positioning_params_normalize($input);
    $json = json_encode($params, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    km_positioning_params_ensure_table($pdo);
    $pdo->prepare(
        'INSERT INTO km_positioning_params (id, body, updated_by, updated_at) VALUES (1, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by), updated_at = NOW()'
    )->execute([$json, $updatedBy]);

    return $params;
}

/** 配るのをやめる(= 各端末の既定へ戻す)。行は消さず空にする(誰がやめたかを残す)。 */
function km_positioning_params_reset(PDO $pdo, ?string $updatedBy): void
{
    km_positioning_params_ensure_table($pdo);
    $pdo->prepare(
        "INSERT INTO km_positioning_params (id, body, updated_by, updated_at) VALUES (1, '', ?, NOW())
         ON DUPLICATE KEY UPDATE body = '', updated_by = VALUES(updated_by), updated_at = NOW()"
    )->execute([$updatedBy]);
}

/** 既定と違う項目の数(操作ログの詳細に使う。値そのものは長いので書かない)。 */
function km_positioning_params_changed_count(array $params): int
{
    $count = 0;
    foreach (KM_POSITIONING_PARAM_SPECS as $key => [, , $default]) {
        if (isset($params[$key]) && (float) $params[$key] !== (float) $default) {
            $count++;
        }
    }
    return $count;
}
