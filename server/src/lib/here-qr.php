<?php

declare(strict_types=1);

/**
 * 校内に貼る「現在地の QR」(2026-10-30、高専祭の振り返りのフェーズ 3 の 6)。
 *
 * QR の中身は `<サイト>/?here=<地点の id>`。読むと、その地点を「今いる場所」にした地図が開く
 * (index.php の $kmHere → Main/app.js の hereFromUrl)。経路の出発地・案内チャットの「現在地」に使う。
 * 印刷は管理画面「現在地の QR」(admin/map-here-qr.php)。QR は lib/qr.php で作る(外のサービスへは送らない)。
 */

/** 貼る場所に選べる地点の種類(人が立つ所。道・壁・Wi-Fi ルーターは選ばない) */
const KM_HERE_QR_TYPES = ['room', 'facility', 'entrance', 'stairs'];
/** 1 回に並べる数の上限(1 枚の紙に 12 個 × 数枚。多すぎると QR を作るのに時間がかかる) */
const KM_HERE_QR_MAX = 60;

/** QR に入れる URL。**純粋関数**。地点の id は 32 字の 16 進だけ通す(それ以外は null) */
function km_here_qr_url(string $siteOrigin, string $nodeId): ?string
{
    $id = strtolower(str_replace('-', '', $nodeId));
    if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
        return null;
    }

    return rtrim($siteOrigin, '/') . '/?here=' . $id;
}

/**
 * 選べる地点(名前のある部屋・建物・出入口・階段)を、階ごとに名前の順で。**純粋関数**。
 *
 * @param list<array{id:string, floor:string, name:string, type:string}> $nodes
 * @param array<string, string> $floorLabels 階の id => 呼び名(並びもこの順)
 * @return array<string, list<array{id:string, name:string, type:string}>> 階の id => 地点
 */
function km_here_qr_candidates(array $nodes, array $floorLabels): array
{
    $byFloor = array_fill_keys(array_keys($floorLabels), []);
    foreach ($nodes as $node) {
        $floor = (string) ($node['floor'] ?? '');
        $name = trim((string) ($node['name'] ?? ''));
        if ($name === '' || !isset($byFloor[$floor]) || !in_array((string) ($node['type'] ?? ''), KM_HERE_QR_TYPES, true)
            || preg_match('/^[0-9a-f]{32}$/', (string) ($node['id'] ?? '')) !== 1) {
            continue;
        }
        $byFloor[$floor][] = ['id' => (string) $node['id'], 'name' => $name, 'type' => (string) $node['type']];
    }
    foreach ($byFloor as $floor => $list) {
        usort($list, static fn (array $a, array $b): int => strnatcmp($a['name'], $b['name']));
        $byFloor[$floor] = $list;
    }

    return array_filter($byFloor, static fn (array $list): bool => $list !== []);
}

/**
 * 印刷に選ばれた id。決まった形(32 字の 16 進)で、候補に在るものだけ・重ねず・上限まで。**純粋関数**
 *
 * @param mixed $raw $_GET['ids']
 * @param array<string, list<array{id:string}>> $candidates km_here_qr_candidates の戻り
 * @return list<string>
 */
function km_here_qr_selected(mixed $raw, array $candidates): array
{
    if (!is_array($raw)) {
        return [];
    }
    $known = [];
    foreach ($candidates as $list) {
        foreach ($list as $node) {
            $known[$node['id']] = true;
        }
    }
    $out = [];
    foreach ($raw as $value) {
        if (is_string($value) && isset($known[$value]) && !in_array($value, $out, true)) {
            $out[] = $value;
        }
        if (count($out) >= KM_HERE_QR_MAX) {
            break;
        }
    }

    return $out;
}
