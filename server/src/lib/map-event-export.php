<?php

declare(strict_types=1);

/**
 * Website のイベントを、管理アプリのイベント(MapEvent)の形で渡す(2026-10-23、利用者の指示「アプリのイベントを Web でも直す」)。
 *
 * 管理アプリから取り込んだイベントも Website の地図編集で直せるようにした。直した内容は、
 * 管理アプリの設定のイベントの「Website から取り込む」(api/map-events.php)でアプリへ戻す。
 *
 * - **1 つのイベントを 1 つの MapEvent に**する(配信の折り込み km_app_map_events_from_web は、有効なものを 1 つにまとめる)。
 *   中身の変換は同じ関数を使う(通行止め・臨時の地点・臨時の名前・出さない地点・つなぎ先)
 * - uuid はアプリから取り込んだものならアプリの uuid(km_map_event_sources)、Website で作ったものは `web-event-<番号>`
 * - 会期は Website の値(アプリの startAtMillis / endAtMillis)。有効かどうか(is_enabled)はアプリでは別に選ぶので渡さない
 * - アプリの「スタッフ限定の一時地点」と「立入禁止の階」は Website に無い(取り込みで落とす・地点に広げる)。
 *   アプリは受け取るときにスタッフ限定の一時地点を残す(MapEventSync.kt)
 */

require_once __DIR__ . '/map-events.php';
require_once __DIR__ . '/app-map-convert.php';

/**
 * 地点の uuid と階(アプリの階)を引くための、地点だけの地図。km_app_map_events_from_web に渡す
 */
function km_map_event_export_node_map(PDO $pdo): stdClass
{
    $map = new stdClass();
    $map->nodes = [];
    foreach ($pdo->query('SELECT id, uuid, floor_id FROM km_map_nodes')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uuid = trim((string) ($row['uuid'] ?? ''));
        $floor = km_app_map_app_floor((string) $row['floor_id']);
        if ($uuid === '' || $floor === null) {
            continue;
        }
        $node = new stdClass();
        $node->uuid = $uuid;
        $node->floor = $floor;
        $map->nodes[] = $node;
    }
    return $map;
}

/** 'Y-m-d H:i:s'(DB の時刻) → ミリ秒。空・読めなければ null。**純粋関数** */
function km_map_event_export_millis(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $parsed = strtotime($value);
    return $parsed === false ? null : $parsed * 1000;
}

/**
 * 全部のイベントを MapEvent の形で。
 *
 * @return array{events: list<array<string, mixed>>, skipped: array<string, mixed>}
 */
function km_map_events_for_app(PDO $pdo): array
{
    km_map_events_ensure_tables($pdo);
    $nodeMap = km_map_event_export_node_map($pdo);
    $sources = [];
    foreach ($pdo->query('SELECT event_id, app_uuid FROM km_map_event_sources')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sources[(int) $row['event_id']] = (string) $row['app_uuid'];
    }
    $events = [];
    $skipped = ['unknownNode' => 0, 'edgeAcrossFloors' => 0, 'unknownFloor' => []];
    foreach (km_map_events_all($pdo) as $row) {
        $id = (int) $row['id'];
        $converted = km_app_map_events_from_web(km_map_event_overlay($pdo, $id, true), $nodeMap);
        $event = $converted['events'][0] ?? null;
        if ($event === null) {
            continue;
        }
        $event['uuid'] = $sources[$id] ?? ('web-event-' . $id);
        $event['name'] = (string) $row['name'];
        $event['startAtMillis'] = km_map_event_export_millis($row['startsAt'] ?? null);
        $event['endAtMillis'] = km_map_event_export_millis($row['endsAt'] ?? null);
        $event['onlyEventNodes'] = (bool) ($row['onlyEventNodes'] ?? true);
        $events[] = $event;
        $skipped['unknownNode'] += $converted['skipped']['unknownNode'];
        $skipped['edgeAcrossFloors'] += $converted['skipped']['edgeAcrossFloors'];
        $skipped['unknownFloor'] = array_values(array_unique(array_merge($skipped['unknownFloor'], $converted['skipped']['unknownFloor'])));
    }
    return ['events' => $events, 'skipped' => $skipped];
}
