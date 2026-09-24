<?php

declare(strict_types=1);

/**
 * 建物の階を `km_map_floors` に用意する(2026-09-24)。**何度流してもよい。**
 *
 *   docker compose exec -T -u www-data web php /var/www/html/scripts/seed-building-floors.php
 *   docker compose exec -T -u www-data web php /var/www/html/scripts/seed-building-floors.php --list
 *
 * 平面図 1 枚を 1 つの階として扱うための下ごしらえ(lib/building-floors.php)。
 * **`ALTER` は使わない** —— アプリの DB 利用者に与えていないため、`CREATE` と `INSERT` だけで済ませる。
 *
 * 画像は `Main/Picture/bldg/` に既に置いてある(アプリの drawable と同じ絵)。
 * 地点はまだ 1 つも無い。中を作るのは管理画面の地図編集から。
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/building-floors.php';

$list = in_array('--list', $argv, true);

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    fwrite(STDERR, "データベースに繋げません: " . $exception->getMessage() . "\n");
    exit(1);
}

if (!$list) {
    $result = km_building_floors_ensure($pdo);
    printf("階を足しました: %d 件 / 座標系: %d 件(既にあるものは触りません)\n", $result['added'], $result['bounds']);
}

$stmt = $pdo->query(
    'SELECT f.id, f.label, f.svg_path, f.sort_order, b.coord_width, b.coord_height,
            (SELECT COUNT(*) FROM km_map_nodes n WHERE n.floor_id = f.id) AS nodes
     FROM km_map_floors f
     LEFT JOIN km_map_floor_bounds b ON b.floor_id = f.id
     ORDER BY f.sort_order'
);
printf("%-17s %-26s %-34s %-12s %s\n", 'id', '名前', '画像', '座標系', '地点');
foreach ($stmt as $row) {
    printf(
        "%-17s %-26s %-34s %-12s %d\n",
        (string) $row['id'],
        (string) $row['label'],
        (string) $row['svg_path'],
        ($row['coord_width'] ?? '?') . 'x' . ($row['coord_height'] ?? '?'),
        (int) $row['nodes']
    );
}

// 画像が本当に在るか。**無い階を足すと、開いたときに真っ白になる**
$missing = [];
foreach (KM_BUILDING_FLOORS as $floor) {
    if (!is_file(__DIR__ . '/../Main/' . $floor['image'])) {
        $missing[] = $floor['image'];
    }
}
if ($missing !== []) {
    echo "\n★ 画像が見つかりません(配備漏れ):\n  " . implode("\n  ", $missing) . "\n";
    exit(1);
}
echo "\n画像はすべて在ります。\n";
