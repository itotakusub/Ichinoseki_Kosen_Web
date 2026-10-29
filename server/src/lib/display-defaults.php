<?php

declare(strict_types=1);

/**
 * アプリの表示調整の既定を配る(2026-10-23、利用者の指示「Android で設定した表示調整の既定も配れるように」)。
 *
 * 管理アプリの表示調整で「この表示を既定として配る」→ api/display-defaults.php(管理者のトークン)→
 * 一般のアプリは地図を取るときに GET で読み、**利用者が変えていない項目だけ**に当てはめる(アプリの DisplayDefaults.kt)。
 * 測位のパラメータ(lib/positioning-params.php)と同じ形。
 *
 * **どの項目を配るかはアプリが決める**(表示調整の画面の項目。アプリの DISPLAY_DEFAULT_KEYS)。サーバーは形だけ確かめる:
 * 名前は英小文字・数字・_、値は真偽・整数・短い文字列・短い文字列の並び。置き場は専用の表。
 */

require_once __DIR__ . '/db.php';

const KM_DISPLAY_DEFAULTS_MAX_KEYS = 200;
const KM_DISPLAY_DEFAULTS_KEY_PATTERN = '/\A[a-z0-9_]{1,64}\z/';

function km_display_defaults_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_display_defaults (
            id TINYINT NOT NULL PRIMARY KEY,
            body MEDIUMTEXT NOT NULL,
            updated_by VARCHAR(191) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ensured = true;
}

/**
 * 検める。**純粋関数。** 形の違うものは理由を付けて断る(InvalidArgumentException)。
 *
 * @param array<mixed> $input
 * @return array<string, bool|int|string|list<string>>
 */
function km_display_defaults_normalize(array $input): array
{
    if (count($input) > KM_DISPLAY_DEFAULTS_MAX_KEYS) {
        throw new InvalidArgumentException('項目が多すぎます(' . KM_DISPLAY_DEFAULTS_MAX_KEYS . ' まで)。');
    }
    $clean = static function (string $text): string {
        if (mb_strlen($text) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1 || !mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('文字列の値が長すぎるか、使えない文字があります。');
        }
        return $text;
    };
    $result = [];
    foreach ($input as $key => $value) {
        if (!is_string($key) || preg_match(KM_DISPLAY_DEFAULTS_KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('項目の名前の形が違います: ' . mb_substr((string) $key, 0, 40));
        }
        if (is_bool($value)) {
            $result[$key] = $value;
        } elseif (is_int($value) && abs($value) <= 1_000_000) {
            $result[$key] = $value;
        } elseif (is_string($value)) {
            $result[$key] = $clean($value);
        } elseif (is_array($value) && array_is_list($value) && count($value) <= 32) {
            $result[$key] = array_map(static function ($item) use ($clean): string {
                if (!is_string($item)) {
                    throw new InvalidArgumentException('並びの値は文字列だけです。');
                }
                return $clean($item);
            }, $value);
        } else {
            throw new InvalidArgumentException('値の形が違います: ' . $key);
        }
    }
    ksort($result);

    return $result;
}

/** いま配っている値。配っていない・読めなければ null。 */
function km_display_defaults_stored(PDO $pdo): ?array
{
    try {
        km_display_defaults_ensure_table($pdo);
        $body = $pdo->query('SELECT body FROM km_display_defaults WHERE id = 1')->fetchColumn();
    } catch (Throwable $exception) {
        error_log('km_display_defaults_stored: ' . $exception->getMessage());
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
        return km_display_defaults_normalize($decoded);
    } catch (InvalidArgumentException $exception) {
        error_log('km_display_defaults_stored: ' . $exception->getMessage() . '(配らずに動きます)');
        return null;
    }
}

/** 配る。検めてから保存し、保存した形を返す。 */
function km_display_defaults_publish(PDO $pdo, array $input, ?string $updatedBy): array
{
    $values = km_display_defaults_normalize($input);
    $json = json_encode((object) $values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    km_display_defaults_ensure_table($pdo);
    $pdo->prepare(
        'INSERT INTO km_display_defaults (id, body, updated_by, updated_at) VALUES (1, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by), updated_at = NOW()'
    )->execute([$json, $updatedBy]);

    return $values;
}

/** 配るのをやめる。行は消さず空にする(誰がやめたかを残す)。 */
function km_display_defaults_reset(PDO $pdo, ?string $updatedBy): void
{
    km_display_defaults_ensure_table($pdo);
    $pdo->prepare(
        "INSERT INTO km_display_defaults (id, body, updated_by, updated_at) VALUES (1, '', ?, NOW())
         ON DUPLICATE KEY UPDATE body = '', updated_by = VALUES(updated_by), updated_at = NOW()"
    )->execute([$updatedBy]);
}

/**
 * 配った表示調整の既定を、Website の地図の表示調整(Main/map-style.js の DEFAULTS と種類ごとの設定)の形にする(2026-10-23、
 * 利用者の指示「表示調整が Website に配られているか、それが既定になっているか」)。**純粋関数**。
 *
 * それまで配った既定はアプリだけが読み、Web の地図は自分の既定のままだった。Web と対応の付く項目だけを移す:
 *   文字と地点の大きさ・名前の出し方・部屋名と Wi-Fi の層・道の網・距離・種類ごとの形/大きさ/文字/出始める倍率/出す種類。
 *   色・色分け・初めの倍率・詳細の出し方は Web に同じ設定が無いので移さない。
 * Web は閲覧者が自分で変えた値を優先し、変えていない項目にこの既定が効く(「既定に戻す」もこの既定に戻す)。
 *
 * @param array<string, mixed> $values km_display_defaults_stored の値(アプリの設定のキー)
 * @return array<string, mixed> map-style.js の DEFAULTS に重ねる値(types は種類ごと)
 */
function km_display_defaults_for_web(array $values): array
{
    $web = [];
    $percent = static fn (mixed $v): ?int => is_int($v) && $v >= 50 && $v <= 300 ? $v : null;
    if (($v = $percent($values['map_node_size_percent'] ?? null)) !== null) {
        $web['nodeSize'] = $v;
    }
    if (($v = $percent($values['map_label_size_percent'] ?? null)) !== null) {
        // Web の「文字」のつまみの上限(Main/map-tuning.js・map-style.js の labelScale。2026-10-30 に 200 → 260)
        $web['labelSize'] = min(260, $v);
    }
    $labelModes = ['icon' => 'icon_only', 'icon_text' => 'icon_and_text', 'text' => 'text_only'];
    if (isset($labelModes[$values['map_node_label_mode'] ?? ''])) {
        $web['labelMode'] = $labelModes[$values['map_node_label_mode']];
    }
    if (is_array($values['map_display_layers'] ?? null)) {
        $web['roomNames'] = in_array('layer_room_names', $values['map_display_layers'], true);
        $web['accessPoints'] = in_array('layer_ap_icons', $values['map_display_layers'], true);
    }
    if (is_bool($values['map_show_road_graph'] ?? null)) {
        $web['roadGraph'] = $values['map_show_road_graph'];
    }
    if (is_bool($values['map_show_distance_labels'] ?? null)) {
        $web['distances'] = $values['map_show_distance_labels'];
    }
    $types = [];
    $shapes = ['circle', 'square', 'triangle', 'diamond', 'hexagon', 'star', 'pin'];
    foreach (['room', 'facility', 'stairs', 'road', 'entrance', 'wall', 'wifi_router'] as $type) {
        $entry = [];
        if (is_string($values["map_node_shape_{$type}"] ?? null) && in_array($values["map_node_shape_{$type}"], $shapes, true)) {
            $entry['shape'] = $values["map_node_shape_{$type}"];
        }
        if (($v = $percent($values["map_node_size_{$type}"] ?? null)) !== null) {
            $entry['size'] = $v;
        }
        if (is_bool($values["map_node_label_{$type}"] ?? null)) {
            $entry['label'] = $values["map_node_label_{$type}"];
        }
        $zoom = $values["map_node_zoom_{$type}"] ?? null;
        if (is_string($zoom) && is_numeric($zoom) && (float) $zoom >= 0 && (float) $zoom <= 64) {
            $entry['showScale'] = (float) $zoom;
        }
        if (is_array($values['map_visible_node_types'] ?? null)) {
            $entry['visible'] = in_array($type, $values['map_visible_node_types'], true);
        }
        if ($entry !== []) {
            $types[$type] = $entry;
        }
    }
    if ($types !== []) {
        $web['types'] = $types;
    }
    return $web;
}
