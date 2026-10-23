<?php

declare(strict_types=1);

/**
 * 区画の色分けを手で決める(2026-10-23、利用者の指示「部屋の色分けは手動でもできるように」)。
 *
 * アプリの地図は下絵の線で閉じた区画を、中の地点の種類で自動で塗る(アプリの AreaSegmentation.kt)。
 * 管理アプリで、地点ごとに「区画の種類」(部屋・廊下・階段・建物・道・塗らない)と「色」を決められる。
 * 地点の無い区画には「区画の印」(種類 area の地点)を置いて決める。
 *
 * - **別の表に持つ**(km_map_node_stairs と同じ理由。ALTER は要らない)。表 km_map_node_area
 * - 区画の印は **km_map_nodes に入れない**(Web の地図・一覧・検索に名前の無い地点が出ないように)。
 *   この表に階と位置ごと持ち、配信でアプリへ戻す
 * - 取り込みでは**この表を丸ごと入れ替える**(区画の色はアプリでしか決めないので、アプリの地図が正本)
 * - 色は `#RRGGBB`。アプリへは不透明の ARGB の数(0xFFRRGGBB)で返す
 */

const KM_MAP_AREA_KINDS = ['room', 'corridor', 'stairs', 'building', 'road', 'none'];
const KM_MAP_AREA_MARKER_TYPE = 'area';

/** 表を作る。**取引の外で呼ぶ**(CREATE は取引を確定させる) */
function km_map_node_area_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_node_area (
            node_uuid VARCHAR(64) NOT NULL PRIMARY KEY,
            area_kind VARCHAR(16) NULL,
            area_color CHAR(7) NULL,
            marker_floor VARCHAR(40) NULL,
            marker_x DOUBLE NULL,
            marker_y DOUBLE NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 区画の種類。知らない値は null(自動)。**純粋関数** */
function km_map_area_kind(mixed $value): ?string
{
    return is_string($value) && in_array($value, KM_MAP_AREA_KINDS, true) ? $value : null;
}

/** 色を `#RRGGBB` にする。アプリの ARGB の数か `#RRGGBB` を受ける。読めなければ null。**純粋関数** */
function km_map_area_color(mixed $value): ?string
{
    if (is_string($value) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1) {
        return strtoupper($value);
    }
    if (is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && ctype_digit($value))) {
        $number = (int) $value;
        if ($number < 0 || $number > 0xFFFFFFFF) {
            return null;
        }
        return sprintf('#%06X', $number & 0xFFFFFF);
    }
    return null;
}

/** `#RRGGBB` → アプリの ARGB(不透明)。**純粋関数** */
function km_map_area_color_argb(?string $hex): ?int
{
    return $hex !== null && preg_match('/\A#[0-9A-F]{6}\z/', $hex) === 1 ? (0xFF000000 | hexdec(substr($hex, 1))) : null;
}

/**
 * アプリの地図から、区画の指定を取り出す。**純粋関数**。
 *
 * @return array{overrides: array<string, array{kind:?string, color:?string}>,
 *               markers: list<array{uuid:string, floor:string, x:float, y:float, kind:?string, color:?string}>}
 */
function km_map_area_from_app(stdClass $map): array
{
    $overrides = [];
    $markers = [];
    foreach ((isset($map->nodes) && is_array($map->nodes)) ? $map->nodes : [] as $node) {
        if (!($node instanceof stdClass)) {
            continue;
        }
        $uuid = trim((string) ($node->uuid ?? ''));
        if ($uuid === '' || strlen($uuid) > 64) {
            continue;
        }
        $kind = km_map_area_kind($node->areaKind ?? null);
        $color = km_map_area_color($node->areaColor ?? null);
        if (($node->type1 ?? '') === KM_MAP_AREA_MARKER_TYPE) {
            $floor = trim((string) ($node->floor ?? ''));
            if ($floor === '' || !is_numeric($node->x ?? null) || !is_numeric($node->y ?? null)) {
                continue;
            }
            // 指定の無い印も残す(置いただけで、あとで色を決める)
            $markers[] = ['uuid' => $uuid, 'floor' => $floor, 'x' => round((float) $node->x, 2), 'y' => round((float) $node->y, 2), 'kind' => $kind, 'color' => $color];
            continue;
        }
        if ($kind !== null || $color !== null) {
            $overrides[$uuid] = ['kind' => $kind, 'color' => $color];
        }
    }

    return ['overrides' => $overrides, 'markers' => $markers];
}

/**
 * 表の中身(km_map_area_from_app と同じ形)。表が無ければ空(配信を止めない)。
 *
 * @return array{overrides: array<string, array{kind:?string, color:?string}>, markers: list<array<string,mixed>>}
 */
function km_map_node_area_all(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT node_uuid, area_kind, area_color, marker_floor, marker_x, marker_y FROM km_map_node_area ORDER BY node_uuid')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        return ['overrides' => [], 'markers' => []];
    }
    $overrides = [];
    $markers = [];
    foreach ($rows as $row) {
        $kind = km_map_area_kind($row['area_kind']);
        $color = km_map_area_color($row['area_color']);
        if ($row['marker_floor'] !== null) {
            $markers[] = ['uuid' => (string) $row['node_uuid'], 'floor' => (string) $row['marker_floor'], 'x' => (float) $row['marker_x'], 'y' => (float) $row['marker_y'], 'kind' => $kind, 'color' => $color];
        } elseif ($kind !== null || $color !== null) {
            $overrides[(string) $row['node_uuid']] = ['kind' => $kind, 'color' => $color];
        }
    }

    return ['overrides' => $overrides, 'markers' => $markers];
}

/**
 * 取り込んだ地図の指定で、表を**丸ごと入れ替える**。表は先に作っておくこと。返すのは入れた数
 *
 * @param array{overrides: array<string, array{kind:?string, color:?string}>, markers: list<array<string,mixed>>} $area
 */
function km_map_node_area_sync(PDO $pdo, array $area, ?int $now = null): int
{
    $pdo->exec('DELETE FROM km_map_node_area');
    $insert = $pdo->prepare('INSERT INTO km_map_node_area (node_uuid, area_kind, area_color, marker_floor, marker_x, marker_y, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $at = date('Y-m-d H:i:s', $now ?? time());
    $saved = 0;
    foreach ($area['overrides'] as $uuid => $override) {
        $insert->execute([(string) $uuid, $override['kind'], $override['color'], null, null, null, $at]);
        $saved++;
    }
    foreach ($area['markers'] as $marker) {
        $insert->execute([$marker['uuid'], $marker['kind'], $marker['color'], $marker['floor'], $marker['x'], $marker['y'], $at]);
        $saved++;
    }

    return $saved;
}

/**
 * 配信する地図に区画の指定を足す(地点に areaKind / areaColor、区画の印を地点として)。**引数を書き換える**。
 * 指定の無い地点には項目を足さない(形を変えない)。
 */
function km_map_area_apply(stdClass $map, array $area): void
{
    if ($area['overrides'] === [] && $area['markers'] === []) {
        return;
    }
    $map->nodes = isset($map->nodes) && is_array($map->nodes) ? $map->nodes : [];
    foreach ($map->nodes as $node) {
        $override = $node instanceof stdClass ? ($area['overrides'][(string) ($node->uuid ?? '')] ?? null) : null;
        if ($override === null) {
            continue;
        }
        if ($override['kind'] !== null) {
            $node->areaKind = $override['kind'];
        }
        if ($override['color'] !== null) {
            $node->areaColor = km_map_area_color_argb($override['color']);
        }
    }
    foreach ($area['markers'] as $marker) {
        $node = new stdClass();
        $node->uuid = $marker['uuid'];
        $node->x = $marker['x'];
        $node->y = $marker['y'];
        $node->useWifi = false;
        $node->title = '';
        $node->subtitle = '';
        $node->type1 = KM_MAP_AREA_MARKER_TYPE;
        $node->type2 = '';
        $node->note = '';
        $node->floor = $marker['floor'];
        if ($marker['kind'] !== null) {
            $node->areaKind = $marker['kind'];
        }
        if ($marker['color'] !== null) {
            $node->areaColor = km_map_area_color_argb($marker['color']);
        }
        $map->nodes[] = $node;
    }
}
