<?php

declare(strict_types=1);

/**
 * 案内チャットの「近い順」(2026-10-30、高専祭の振り返りのフェーズ 4 の 4)。
 *
 * 振り返り: 「トイレはどこ？」の答えが距離の順になっていない。サーバーは地点の座標と線を持っているのに、
 * 一覧(km_guide_catalog)が座標を捨てていた。**今いる場所(現在地の QR か展示の場所)から、線の上の最短の長さ**で並べる。
 *
 * つなぎ方は公開の地図の Main/dijkstra.js と同じ(アプリの RouteSearch.kt とも同じ)。
 * - 壁の線・通行止めの線と地点は通らない。屋外の線は 1.3 倍(屋内を先に)
 * - 階段は接続 ID(無ければ名前)が同じものを、違う階どうし(屋外を除く)で 1 層 200px として繋ぐ
 * - 出入口は接続 ID が同じものを、1F と屋外・建物の階と繋ぐ(150px)
 * - 部屋は**行き止まり**として扱う(着けるが通り抜けない。dijkstra.js の「B 部屋を通り抜けない」)。
 *   そうすると着けない部屋があれば、通り抜けを許してもう 1 度測る
 *
 * 1 つの出発地から全部の地点への長さを 1 度に測る(経路を 1 本ずつ引かない)。**純粋関数**。
 * 長さは地図の px(10px = 1m。dijkstra.js の KM_ROUTE_WEIGHTS の注記と同じ)。
 */

const KM_GUIDE_DISTANCE_FLOOR_PX = 200;
const KM_GUIDE_DISTANCE_ENTRANCE_PX = 150;
const KM_GUIDE_DISTANCE_OUTSIDE = 1.3;
const KM_GUIDE_PX_PER_METER = 10;

/** 階段をまとめる鍵(dijkstra.js の stairKey と同じ) */
function km_guide_distance_stair_key(array $node): string
{
    $id = mb_strtolower(trim((string) ($node['transferGroupId'] ?? '')));
    if ($id !== '') {
        return $id;
    }
    $title = trim((string) (($node['title'] ?? '') !== '' ? $node['title'] : ($node['name'] ?? '')));
    return mb_strtolower($title !== '' ? $title : trim((string) ($node['subtitle'] ?? '')));
}

function km_guide_distance_floor_number(string $floor): ?int
{
    $text = preg_replace('/F$/i', '', $floor) ?? $floor;
    return preg_match('/\A-?\d+\z/', $text) === 1 ? (int) $text : null;
}

/**
 * 隣どうしの一覧(id => [[隣の id, 重さ], …])。
 *
 * @param array<string, array<string, mixed>> $nodes km_map_data の nodes
 * @param list<array<string, mixed>> $edges km_map_data の edges
 * @return array<string, list<array{0:string, 1:float}>>
 */
function km_guide_distance_graph(array $nodes, array $edges): array
{
    $adjacent = [];
    foreach ($nodes as $id => $node) {
        if (is_array($node) && empty($node['closed'])) {
            $adjacent[(string) $id] = [];
        }
    }
    $outside = static fn (array $node): bool => strtolower((string) ($node['floor'] ?? '')) === 'outside';
    foreach ($edges as $edge) {
        $a = (string) ($edge['source'] ?? '');
        $b = (string) ($edge['target'] ?? '');
        if (!empty($edge['wall']) || !empty($edge['closed']) || !isset($adjacent[$a], $adjacent[$b])) {
            continue;
        }
        $weight = (float) ($edge['distance'] ?? 0);
        if ($outside($nodes[$a]) && $outside($nodes[$b])) {
            $weight *= KM_GUIDE_DISTANCE_OUTSIDE;
        }
        $adjacent[$a][] = [$b, $weight];
        $adjacent[$b][] = [$a, $weight];
    }

    // 階段と出入口(線を引かなくても、同じ接続 ID で繋がる)
    $groups = ['stairs' => [], 'entrance' => []];
    foreach ($adjacent as $id => $unused) {
        $node = $nodes[$id];
        $type = (string) ($node['type'] ?? '');
        if ($type === 'stairs') {
            $key = km_guide_distance_stair_key($node);
        } elseif ($type === 'entrance') {
            $key = mb_strtolower(trim((string) ($node['transferGroupId'] ?? '')));
        } else {
            continue;
        }
        if ($key !== '') {
            $groups[$type][$key][] = (string) $id;
        }
    }
    foreach ($groups as $type => $byKey) {
        foreach ($byKey as $ids) {
            $count = count($ids);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $fa = (string) ($nodes[$ids[$i]]['floor'] ?? '');
                    $fb = (string) ($nodes[$ids[$j]]['floor'] ?? '');
                    if ($fa === $fb) {
                        continue;
                    }
                    if ($type === 'stairs') {
                        if (strtolower($fa) === 'outside' || strtolower($fb) === 'outside') {
                            continue;
                        }
                        $na = km_guide_distance_floor_number($fa);
                        $nb = km_guide_distance_floor_number($fb);
                        $weight = max(1, ($na === null || $nb === null) ? 1 : abs($na - $nb)) * KM_GUIDE_DISTANCE_FLOOR_PX;
                    } else {
                        $building = str_starts_with(strtolower($fa), 'bldg_') || str_starts_with(strtolower($fb), 'bldg_');
                        $outsideAndFirst = in_array('outside', [strtolower($fa), strtolower($fb)], true)
                            && in_array(1, [km_guide_distance_floor_number($fa), km_guide_distance_floor_number($fb)], true);
                        if (!$building && !$outsideAndFirst) {
                            continue;
                        }
                        $weight = KM_GUIDE_DISTANCE_ENTRANCE_PX;
                    }
                    $adjacent[$ids[$i]][] = [$ids[$j], (float) $weight];
                    $adjacent[$ids[$j]][] = [$ids[$i], (float) $weight];
                }
            }
        }
    }
    return $adjacent;
}

/**
 * 出発地から全部の地点への長さ(px)。着けない地点は入らない。**純粋関数**
 *
 * @return array<string, float>
 */
function km_guide_route_lengths(array $nodes, array $edges, string $startId): array
{
    $adjacent = km_guide_distance_graph($nodes, $edges);
    if (!isset($adjacent[$startId])) {
        return [];
    }
    $run = static function (bool $avoidRooms) use ($adjacent, $nodes, $startId): array {
        $distance = [$startId => 0.0];
        $done = [];
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $queue->insert($startId, 0.0);
        while (!$queue->isEmpty()) {
            $top = $queue->extract();
            $id = (string) $top['data'];
            if (isset($done[$id])) {
                continue;
            }
            $done[$id] = true;
            // 部屋は着くだけ(出発地は除く)
            if ($avoidRooms && $id !== $startId && (string) ($nodes[$id]['type'] ?? '') === 'room') {
                continue;
            }
            foreach ($adjacent[$id] as [$next, $weight]) {
                $alt = $distance[$id] + $weight;
                if (!isset($distance[$next]) || $alt < $distance[$next]) {
                    $distance[$next] = $alt;
                    // SplPriorityQueue は大きい方を先に出すので、符号を反転して入れる
                    $queue->insert($next, -$alt);
                }
            }
        }
        return $distance;
    };
    $strict = $run(true);
    // 通り抜けないと着けない所だけ、通り抜けを許した長さで埋める(dijkstra.js と同じく、消すより通り抜けて案内する)
    foreach ($run(false) as $id => $length) {
        $strict[$id] ??= $length;
    }
    return $strict;
}

/** 長さ(px)を「約 ◯ m」に。**純粋関数** */
function km_guide_distance_label(float $px): string
{
    $meters = $px / KM_GUIDE_PX_PER_METER;
    return '約 ' . ($meters < 100 ? (int) (round($meters / 5) * 5 ?: 5) : (int) (round($meters / 10) * 10)) . ' m';
}
