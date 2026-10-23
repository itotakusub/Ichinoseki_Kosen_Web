<?php

declare(strict_types=1);

/**
 * 階段の上り口・下り口の向き(2026-10-22、利用者の指摘「階段に矢印。どっち側に行くのか、上行き下行きの文字も」)。
 *
 * - **管理アプリで入れる**(地点の編集の「上り口の向き」「下り口の向き」)。アプリの地図を取り込むとここへ入り、配信でアプリへ戻る
 * - 角度は**アプリの地図の画像の上を 0° とし、時計回り**(0〜359)。Web の地図は使わない(向きの変換もしない)
 * - **別の表に持つ**(km_map_nodes の列にしない)。列を足す ALTER はアプリの DB 利用者に与えていないので、
 *   新しい表なら配備利用者が SQL を流さなくても入る(CREATE は今の利用者でできる)
 * - 取り込みで、その地図の階段に向きが無ければ消す(アプリが正本)。階段でなくなった地点の向きも消す
 */

/** 表を作る。**取引の外で呼ぶ**(CREATE は取引を確定させる) */
function km_map_node_stairs_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_node_stairs (
            node_uuid VARCHAR(64) NOT NULL PRIMARY KEY,
            up_heading SMALLINT NULL,
            down_heading SMALLINT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 向きを 0〜359 の整数にする。数でなければ null。**純粋関数** */
function km_map_heading(mixed $value): ?int
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $degrees = (int) round((float) $value);

    return (($degrees % 360) + 360) % 360;
}

/**
 * 全部の向き(uuid => [up, down])。表が無ければ空(配信を止めない)。
 *
 * @return array<string, array{up:?int, down:?int}>
 */
function km_map_node_stairs_all(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT node_uuid, up_heading, down_heading FROM km_map_node_stairs')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        return [];
    }
    $all = [];
    foreach ($rows as $row) {
        $all[(string) $row['node_uuid']] = ['up' => km_map_heading($row['up_heading']), 'down' => km_map_heading($row['down_heading'])];
    }

    return $all;
}

/**
 * 取り込んだ地図の向きを入れる(km_app_map_to_web() の nodes)。階段で向きがあれば置き換え、無ければ消す。
 * 表は先に作っておくこと(km_map_node_stairs_ensure_table)。返すのは入れた数
 *
 * @param array<int, array<string, mixed>> $nodes
 */
function km_map_node_stairs_sync(PDO $pdo, array $nodes, ?int $now = null): int
{
    $delete = $pdo->prepare('DELETE FROM km_map_node_stairs WHERE node_uuid = ?');
    $insert = $pdo->prepare('INSERT INTO km_map_node_stairs (node_uuid, up_heading, down_heading, updated_at) VALUES (?, ?, ?, ?)');
    $at = date('Y-m-d H:i:s', $now ?? time());
    $saved = 0;
    foreach ($nodes as $node) {
        $uuid = (string) ($node['uuid'] ?? '');
        if ($uuid === '') {
            continue;
        }
        $delete->execute([$uuid]);
        $up = ($node['type'] ?? '') === 'stairs' ? km_map_heading($node['stair_up_heading'] ?? null) : null;
        $down = ($node['type'] ?? '') === 'stairs' ? km_map_heading($node['stair_down_heading'] ?? null) : null;
        if ($up === null && $down === null) {
            continue;
        }
        $insert->execute([$uuid, $up, $down, $at]);
        $saved++;
    }

    return $saved;
}
