<?php

declare(strict_types=1);

/**
 * 場所ごとの色分けした床の画像(2026-10-30、高専祭の振り返りのフェーズ 1 の 3)。
 *
 * アプリは床の線画を区画に分けて、中の地点の種類で塗る(AreaSegmentation.kt)。Website にも同じ色分けを出す。
 *
 * - **塗るのは管理画面**(admin/map-areas.php が Main/area-segmentation.js で各階を塗り、admin/api/map-areas.php へ送る)。
 *   来場者の端末では計算しない(1600×1200 で数百 ms。遅い端末のズームを邪魔しない)
 * - 置き場は uploads/(配備に消されない。nginx が直の配信を塞いである)。配るのは api/floor-image.php?floor=…&area=1(地図の錠の内側)
 * - 記録は km_settings に階ごと(`map_area:<階>` = {"file","source","at"})。**source は塗ったときの床の画像の URL**(版の印 ?v= 付き)。
 *   床の画像を差し替えたら URL が変わるので、古い塗りは使わない(公開の地図は塗っていない床に戻る)
 * - 材料(地点・壁の線・手で決めた種類と色)はアプリと同じもの(km_map_data の管理者向け・km_map_node_area)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/raster-image.php';
require_once __DIR__ . '/settings.php';

const KM_AREA_PAINT_SETTING_PREFIX = 'map_area:';
const KM_AREA_PAINT_MAX_BYTES = 8 * 1024 * 1024;
const KM_AREA_PAINT_MAX_DIMENSION = 4096;
/** 区画を決めるのに使う地点の種類(Main/area-segmentation.js の AREA_SEED_TYPES。区画の印 area は表から足す) */
const KM_AREA_PAINT_SEED_TYPES = ['room', 'facility', 'road', 'stairs', 'entrance'];

/** 塗った画像のファイル名か。**純粋関数** */
function km_area_paint_name_valid(string $name): bool
{
    return preg_match('/\Aarea_[0-9a-f]{12}_[0-9a-f]{16}\.png\z/', $name) === 1;
}

/** 階ごとの記録。読めなければ空(公開の地図を止めない) @return array<string, array{file:string, source:string, at:string}> */
function km_area_paint_records(PDO $pdo): array
{
    try {
        km_settings_ensure_table($pdo);
        $statement = $pdo->prepare('SELECT name, value FROM km_settings WHERE name LIKE ?');
        $statement->execute([KM_AREA_PAINT_SETTING_PREFIX . '%']);
        $records = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = json_decode((string) $row['value'], true);
            if (!is_array($value) || !is_string($value['file'] ?? null) || !is_string($value['source'] ?? null) || !km_area_paint_name_valid($value['file'])) {
                continue;
            }
            $records[substr((string) $row['name'], strlen(KM_AREA_PAINT_SETTING_PREFIX))] = [
                'file' => $value['file'], 'source' => $value['source'], 'at' => (string) ($value['at'] ?? ''),
            ];
        }
        return $records;
    } catch (Throwable $exception) {
        error_log('km_area_paint_records: ' . $exception->getMessage());
        return [];
    }
}

/**
 * 公開の地図に出す塗った画像の URL。**塗ったときの床の画像と今の床の画像が同じときだけ**(床を差し替えたら null)。**純粋関数**
 *
 * @param array{file:string, source:string, at:string}|null $record
 */
function km_area_paint_url(string $floorId, string $floorImageUrl, ?array $record): ?string
{
    if ($record === null || $record['source'] !== $floorImageUrl || !km_area_paint_name_valid($record['file'])) {
        return null;
    }
    // 版はファイル名の後ろ(塗りの中身の SHA-256 の頭)。作り直せば URL が変わり、ブラウザの写しを使わない
    return '/api/floor-image.php?floor=' . rawurlencode($floorId) . '&area=1&v=' . substr($record['file'], -20, 16);
}

/** 塗った画像のファイル。名前が合わない・無ければ null */
function km_area_paint_file(string $name): ?string
{
    if (!km_area_paint_name_valid($name)) {
        return null;
    }
    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? $path : null;
}

/**
 * 塗った画像を置く。床の画像がその間に差し替わっていたら断る(古い床で塗ったものを出さない)。
 * 置いたら前のファイルは消す。返すのは記録
 *
 * @return array{file:string, source:string, at:string}
 */
function km_area_paint_save(PDO $pdo, string $floorId, string $source, string $png, ?int $now = null): array
{
    require_once __DIR__ . '/map-data.php';
    $statement = $pdo->prepare('SELECT svg_path FROM km_map_floors WHERE id = ?');
    $statement->execute([$floorId]);
    $svgPath = $statement->fetchColumn();
    if (!is_string($svgPath) || $svgPath === '') {
        throw new InvalidArgumentException('その階はありません。');
    }
    if ($source !== km_map_floor_image_endpoint($floorId, $svgPath)) {
        throw new InvalidArgumentException('塗っている間に床の画像が変わりました。ページを読み込み直して、もう一度作ってください。');
    }
    $checked = km_raster_image_check($png, KM_AREA_PAINT_MAX_BYTES, KM_AREA_PAINT_MAX_DIMENSION);
    if ($checked['ext'] !== 'png') {
        throw new InvalidArgumentException('PNG ではありません。');
    }
    $dir = km_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('保存先ディレクトリを作成できませんでした。');
    }
    $name = 'area_' . substr(hash('sha256', $floorId), 0, 12) . '_' . substr(hash('sha256', $png), 0, 16) . '.png';
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $png, LOCK_EX) !== strlen($png)) {
        throw new RuntimeException('保存できませんでした。');
    }
    $previous = km_area_paint_records($pdo)[$floorId] ?? null;
    $record = ['file' => $name, 'source' => $source, 'at' => date('Y-m-d H:i:s', $now ?? time())];
    km_setting_set($pdo, KM_AREA_PAINT_SETTING_PREFIX . $floorId, (string) json_encode($record, JSON_UNESCAPED_SLASHES));
    if ($previous !== null && $previous['file'] !== $name && ($old = km_area_paint_file($previous['file'])) !== null) {
        @unlink($old);
    }
    return $record;
}

/** 塗りを外す(公開の地図はその階を塗らない床に戻す) */
function km_area_paint_clear(PDO $pdo, string $floorId): void
{
    $previous = km_area_paint_records($pdo)[$floorId] ?? null;
    km_settings_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_settings WHERE name = ?')->execute([KM_AREA_PAINT_SETTING_PREFIX . $floorId]);
    if ($previous !== null && ($old = km_area_paint_file($previous['file'])) !== null) {
        @unlink($old);
    }
}

/** uuid をそろえる(Web の地点の id はハイフン無しの小文字、アプリはハイフン付き)。**純粋関数** */
function km_area_paint_uuid(string $uuid): string
{
    return strtolower(str_replace('-', '', trim($uuid)));
}

/**
 * 塗る材料を階ごとに組み立てる。**純粋関数**(km_map_data の結果と km_map_node_area_all の結果を受ける)。
 * 座標は Web の地図と同じ(y は上向き。画素へは画面の JS が、範囲 coordWidth・coordHeight か画像の大きさで直す)。
 *
 * @param array{floors:array, nodes:array, edges:array} $data km_map_data($pdo, null, true)
 * @param array{overrides: array<string, array{kind:?string, color:?string}>, markers: list<array<string,mixed>>} $area
 * @param array<string, array{file:string, source:string, at:string}> $records
 * @return list<array<string, mixed>>
 */
function km_area_paint_inputs(array $data, array $area, array $records): array
{
    require_once __DIR__ . '/app-map-convert.php';
    require_once __DIR__ . '/map-node-area.php';
    $overrides = [];
    foreach ($area['overrides'] as $uuid => $override) {
        $overrides[km_area_paint_uuid((string) $uuid)] = $override;
    }
    $color = static fn (?string $hex): ?int => $hex !== null && preg_match('/\A#[0-9A-Fa-f]{6}\z/', $hex) === 1 ? (int) hexdec(substr($hex, 1)) : null;

    $byFloor = [];
    foreach ($data['floors'] as $floor) {
        $id = (string) $floor['id'];
        $byFloor[$id] = [
            'floor' => $id,
            'label' => (string) ($floor['label'] ?? $id),
            'imageUrl' => (string) $floor['svgPath'],
            'coordWidth' => $floor['coordWidth'] ?? null,
            'coordHeight' => $floor['coordHeight'] ?? null,
            'outdoor' => $id === 'outside',
            'seeds' => [],
            'walls' => [],
            'painted' => isset($records[$id]) ? ['at' => $records[$id]['at'], 'current' => $records[$id]['source'] === (string) $floor['svgPath']] : null,
        ];
    }
    foreach ($data['nodes'] as $id => $node) {
        $floorId = (string) ($node['floor'] ?? '');
        $type = (string) ($node['type'] ?? '');
        if (!isset($byFloor[$floorId]) || !in_array($type, KM_AREA_PAINT_SEED_TYPES, true)) {
            continue;
        }
        $override = $overrides[km_area_paint_uuid((string) $id)] ?? null;
        $byFloor[$floorId]['seeds'][] = [
            'uuid' => (string) $id, 'type' => $type, 'x' => (float) $node['x'], 'y' => (float) $node['y'],
            // イベントで隠した地点は、色は決めても押して開かない(アプリと同じ)
            'tappable' => empty($node['hidden']),
            'kind' => $override['kind'] ?? null, 'color' => $color($override['color'] ?? null),
        ];
    }
    // 区画の印はアプリの座標(y は下向き・階は 1F など)で持っている。Web の階と座標へ直す
    foreach ($area['markers'] as $marker) {
        $floorId = KM_APP_MAP_FLOOR_MAP[strtoupper((string) $marker['floor'])] ?? null;
        if ($floorId === null || !isset($byFloor[$floorId])) {
            continue;
        }
        $byFloor[$floorId]['seeds'][] = [
            'uuid' => (string) $marker['uuid'], 'type' => KM_MAP_AREA_MARKER_TYPE,
            'x' => (float) $marker['x'], 'y' => km_app_map_flip_y((float) $marker['y'], km_app_map_floor_height((string) $marker['floor'])),
            'tappable' => false, 'kind' => $marker['kind'], 'color' => $color($marker['color']),
        ];
    }
    foreach ($data['edges'] as $edge) {
        if (empty($edge['wall'])) {
            continue;
        }
        $a = $data['nodes'][(string) $edge['source']] ?? null;
        $b = $data['nodes'][(string) $edge['target']] ?? null;
        if ($a === null || $b === null || (string) $a['floor'] !== (string) $b['floor'] || !isset($byFloor[(string) $a['floor']])) {
            continue;
        }
        $byFloor[(string) $a['floor']]['walls'][] = [(float) $a['x'], (float) $a['y'], (float) $b['x'], (float) $b['y']];
    }
    foreach ($byFloor as &$floor) {
        // 並びを決めておく(同じ材料なら同じ塗り。アプリも uuid の順)
        usort($floor['seeds'], static fn (array $p, array $q): int => strcmp($p['uuid'], $q['uuid']));
    }
    unset($floor);

    return array_values($byFloor);
}
