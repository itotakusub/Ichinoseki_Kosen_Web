<?php

declare(strict_types=1);

/**
 * 管理アプリの書き出しを Website へ取り込む。admin/map-sync.php が使う。
 *
 * ## 上げられるようにした(2026-09-03)
 *
 * それまでは**配信中のファイルしか読まなかった。** アプリが正本で、
 * 配信は手元の `new-map-release.ps1` が SSH で本番へ直接書いていたので、
 * 「いま配っているもの」以外を読む意味が無かった。
 *
 * 向きが変わり、**作成も配信も Website 一括**になった(利用者の指示)。
 * アプリで直したものを Website へ戻す口がここになる。
 *
 * ## 上げたものはすぐには入れない
 *
 * 一度 `uploads/app-map-import.json` へ置き、**差分を見せてから**適用する。
 * 地図の入れ替えは取り返しがつかない。
 * 置き場を uploads にしているのは、**nginx が直接配らない**場所だから
 * (配信中の地図と同じ守り方)。
 *
 * ## 書き込みは1回の取引で
 *
 * 途中で失敗すると、半分だけ入れ替わった地図が残る。
 * トランザクションで包み、失敗したら丸ごと戻す。
 */

require_once __DIR__ . '/app-map-convert.php';
require_once __DIR__ . '/db.php';

/** 取り込み待ちの置き場。**配信中のファイルとは別名にする**(取り違えない) */
const KM_APP_MAP_IMPORT_FILE = 'app-map-import.json';

/**
 * 上げてよい大きさ。実データは 0.36MB。**桁が違うものは中身を見る前に断る。**
 *
 * 上限が無いと、巨大なファイルで PHP のメモリを使い切らせられる。
 */
const KM_APP_MAP_IMPORT_MAX_BYTES = 20 * 1024 * 1024;

/** 取り込み待ちファイルの置き場所。設定が壊れていれば null。 */
function km_app_map_import_path(): ?string
{
    $config = km_app_map_config();
    $dir = km_app_map_storage_dir($config);

    return $dir === '' ? null : rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . KM_APP_MAP_IMPORT_FILE;
}

/**
 * 上げられた JSON を置き場へ移す。**中身を確かめてから。**
 *
 * @return ?string 断った理由。受け付けたら null
 */
function km_app_map_import_store(string $tmpPath, int $size): ?string
{
    if ($size <= 0) {
        return 'ファイルが空です。';
    }
    if ($size > KM_APP_MAP_IMPORT_MAX_BYTES) {
        return 'ファイルが大きすぎます(上限 '
            . (string) (KM_APP_MAP_IMPORT_MAX_BYTES / 1024 / 1024) . 'MB)。';
    }

    $raw = @file_get_contents($tmpPath);
    if (!is_string($raw)) {
        return 'ファイルを読めませんでした。';
    }

    /*
     * **置く前に読む。** 壊れたファイルを置いてから気づくと、
     * 次に画面を開いた人が「取り込み待ちがある」と誤解する。
     */
    $map = km_app_map_decode_snapshot($raw);
    if ($map === null) {
        return '管理アプリの書き出し(kosenmap-map)として読めませんでした。';
    }
    if (!is_array($map->nodes ?? null) || $map->nodes === []) {
        return '地点が1件もありません。別のファイルではありませんか。';
    }

    $path = km_app_map_import_path();
    if ($path === null) {
        return '置き場所の設定を読めませんでした。';
    }
    if (@file_put_contents($path, $raw) === false) {
        return '置き場所へ書き込めませんでした(' . $path . ')。';
    }

    return null;
}

/**
 * 取り込み待ちの地図。無ければ null。
 *
 * @return array{map:?stdClass, error:?string}
 */
function km_app_map_import_load(): array
{
    $path = km_app_map_import_path();
    if ($path === null || !is_readable($path)) {
        return ['map' => null, 'error' => null];
    }

    $map = km_app_map_decode_snapshot((string) file_get_contents($path));
    if ($map === null) {
        return ['map' => null, 'error' => '取り込み待ちのファイルを読み取れません。'];
    }

    return ['map' => $map, 'error' => null];
}

/** 取り込み待ちを捨てる。無ければ何もしない。 */
function km_app_map_import_discard(): void
{
    $path = km_app_map_import_path();
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

/**
 * 配信中の地図を読む。
 *
 * @return array{map:?stdClass, file:?string, error:?string}
 */
function km_app_map_sync_load(string $slug): array
{
    $config = km_app_map_config();
    $entry = $config['maps'][$slug] ?? null;
    if (!is_array($entry)) {
        return ['map' => null, 'file' => null, 'error' => "配信設定に {$slug} がありません。"];
    }

    $fileName = (string) ($entry['file'] ?? '');
    $path = $fileName !== '' ? km_app_map_storage_path($config, $fileName) : null;
    if ($path === null || !is_readable($path)) {
        return [
            'map' => null,
            'file' => $fileName,
            'error' => "配信中の地図ファイルを読めません({$fileName})。"
                . 'new-map-release.ps1 で配信し直してください。',
        ];
    }

    $map = km_app_map_decode_snapshot((string) file_get_contents($path));
    if ($map === null) {
        return ['map' => null, 'file' => $fileName, 'error' => '地図ファイルの中身を読み取れません。'];
    }

    return ['map' => $map, 'file' => $fileName, 'error' => null];
}

/**
 * いまの Website のノード。差分を出すために使う。
 *
 * **比べる列を全部読む。** 以前は `name` と `occupant_name` だけだったので、
 * 「両方に在る = 置き換わる」としか言えず、**何も直していない書き出しでも
 * 「置き換わる地点 685」**と出ていた。その数字を見ても何が起きるか分からない。
 *
 * 在る列だけ読む —— 移行 SQL は配備利用者が別に流すもので、
 * コードの配備と順番が決まっていない。
 *
 * @return array<string, array<string, mixed>>
 */
function km_app_map_sync_existing(PDO $pdo): array
{
    $available = km_map_nodes_columns($pdo);
    $columns = array_values(array_intersect(
        array_merge(['id'], KM_APP_MAP_COMPARED_COLUMNS),
        $available
    ));

    $rows = [];
    foreach ($pdo->query('SELECT ' . implode(', ', $columns) . ' FROM km_map_nodes') as $row) {
        $id = (string) $row['id'];
        $rows[$id] = [];
        foreach ($columns as $column) {
            $rows[$id][$column] = $row[$column];
        }
        // 氏名は空文字を null に揃える(数え方を1つにする)
        $occupant = $rows[$id]['occupant_name'] ?? null;
        $rows[$id]['occupant_name'] = ($occupant === null || trim((string) $occupant) === '')
            ? null
            : (string) $occupant;
    }

    return $rows;
}

/**
 * 名前 => 氏名。**同じ名前が2つ以上あるものは空文字**にして「決められない」と印を付ける。
 *
 * 当てずっぽうで入れると、間違った先生の名前が地図に載る。
 *
 * @param array<string, array{name:string, occupant_name:?string}> $existing
 * @return array<string, string>
 */
function km_app_map_sync_occupant_index(array $existing): array
{
    $byName = [];
    foreach ($existing as $row) {
        if ($row['occupant_name'] === null || $row['occupant_name'] === '') {
            continue;
        }
        if (array_key_exists($row['name'], $byName)) {
            $byName[$row['name']] = '';   // 重複。決められない
            continue;
        }
        $byName[$row['name']] = $row['occupant_name'];
    }

    return $byName;
}

/**
 * `km_map_nodes` に `title` / `subtitle` の列があるか。
 *
 * ## なぜ調べる必要があるのか
 *
 * 列を足すのは `scripts/migrate-node-title-subtitle.sql` で、**`ALTER` はアプリの
 * DB 利用者に与えていない**(権限を広げると実行時の利用者がスキーマを変えられる)。
 * つまり**配備利用者が別に流す**もので、コードの配備との順番が決まっていない。
 *
 * 決め打ちで書くと、SQL を流す前に同期した人のところで「不明な列」で落ちる。
 * **地図の入れ替えが途中で止まるのが一番困る。**
 *
 * 1リクエストの間は覚えておく(同期の中で何度も聞かない)。
 */
function km_map_nodes_has_split_name(PDO $pdo): bool
{
    $columns = km_map_nodes_columns($pdo);

    return in_array('title', $columns, true) && in_array('subtitle', $columns, true);
}

/**
 * `km_map_nodes` に実際に在る列。
 *
 * **列ごとに関数を増やさない。** `title`/`subtitle` のときは真偽値1つで足りたが、
 * 2026-09-03 に9列増えた(`scripts/migrate-map-app-schema.sql`)。
 * そのたびに判定を足すと、**足し忘れた列だけが黙って書かれない。**
 *
 * 1リクエストの間は覚えておく(同期の中で何度も聞かない)。
 *
 * @return array<int, string>
 */
function km_map_nodes_columns(PDO $pdo): array
{
    static $known = null;
    if ($known !== null) {
        return $known;
    }

    try {
        $stmt = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'km_map_nodes'"
        );
        $known = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $exception) {
        /*
         * 聞けなければ**移行前の最小の形**に倒す。
         * **無い前提の SQL は、在っても通る。** 逆は通らない。
         */
        error_log('km_map_nodes_columns failed: ' . $exception->getMessage());
        $known = ['id', 'floor_id', 'name', 'occupant_name', 'type', 'x', 'y'];
    }

    return $known;
}

/**
 * 辺を向きの無い1本として数えるための鍵。`a|b` と `b|a` を同じものにする。
 *
 * Web の経路探索は無向グラフとして読むので、**向きの違う同じ線を2本入れない。**
 */
function km_app_map_edge_key(string $from, string $to): string
{
    return $from < $to ? $from . '|' . $to : $to . '|' . $from;
}

/**
 * 取り込んだ地図を DB へ入れる。**1回の取引で。**
 *
 * ## 作り直さない(2026-09-03 に変えた)
 *
 * 以前はこうしていた:
 *
 *     DELETE FROM km_map_edges;
 *     DELETE FROM km_map_nodes;
 *     -- 全部入れ直す
 *
 * **アプリが正本だったので、それでよかった。**
 * いまは Website でも編集する(利用者の判断)。作り直すと、
 * **Website で足したノードが、アプリの書き出しを取り込むたびに消える。**
 * それが「同じ校舎の地図が2つある」状態の正体だった。
 *
 * ## 消すのは、頼まれたときだけ
 *
 * `$removeMissing` を渡さない限り、**取り込んだ地図に無いものは残す。**
 * 消すのは取り返しがつかないので、画面で数を見てから選ばせる。
 *
 * ## 氏名は既定で守る
 *
 * 上げた地図に氏名が入っていない地点は、**いまの氏名を残す**($keepOccupantNames)。
 * 来場者向けに氏名を抜いた書き出しや、氏名を移す前の古い書き出しを取り込むと、
 * **65 件の氏名が黙って消える。** 消えたことは画面のどこにも出ない ——
 * 「置き換わる地点 685」としか書かれないからだ。
 *
 * @param array $converted km_app_map_to_web() の戻り
 * @param bool  $removeMissing 取り込んだ地図に無いノード・辺を消すか
 * @param bool  $keepOccupantNames 上げた地図に氏名が無いとき、いまの氏名を残すか
 * @return array{added:int, updated:int, removed:int,
 *               edgesAdded:int, edgesRemoved:int, floors:int}
 */
function km_app_map_sync_apply(
    PDO $pdo,
    array $converted,
    bool $removeMissing = false,
    bool $keepOccupantNames = true
): array {
    $pdo->beginTransaction();
    try {
        /*
         * 列は**在るものだけ書く。**
         *
         * 列を足す SQL(`scripts/migrate-map-app-schema.sql` ほか)は
         * **配備利用者が別に流す**もので、コードの配備とは順番が決まっていない。
         * 決め打ちで INSERT すると、SQL を流す前に取り込んだ人のところで
         * 「不明な列」で落ちる —— **地図の入れ替えが途中で止まるのが一番困る。**
         */
        $available = km_map_nodes_columns($pdo);
        $wanted = [
            'id', 'uuid', 'floor_id', 'name', 'title', 'subtitle', 'occupant_name',
            'note', 'use_wifi', 'type', 'type2', 'bssid', 'tx_power_at_one_meter',
            'path_loss_exponent', 'transfer_group_id', 'x', 'y', 'z',
        ];
        $columns = array_values(array_intersect($wanted, $available));

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        /*
         * `id` は主キーなので更新しない。**それ以外は上書きする** ——
         * 取り込みは「アプリ側の状態に合わせる」操作で、
         * 部分的にしか合わないと、どちらでもない中間の地図ができる。
         */
        $updates = [];
        foreach ($columns as $column) {
            if ($column === 'id') {
                continue;
            }
            if ($column === 'occupant_name' && $keepOccupantNames) {
                /*
                 * **null では上書きしない。** 上げた地図に氏名が入っていれば
                 * そちらを採り、入っていなければいまの値を残す。
                 */
                $updates[] = 'occupant_name = COALESCE(VALUES(occupant_name), occupant_name)';
                continue;
            }
            $updates[] = "{$column} = VALUES({$column})";
        }

        $insertNode = $pdo->prepare(
            'INSERT INTO km_map_nodes (' . implode(', ', $columns) . ')'
            . ' VALUES (' . $placeholders . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );

        $existingIds = array_map(
            'strval',
            $pdo->query('SELECT id FROM km_map_nodes')->fetchAll(PDO::FETCH_COLUMN)
        );
        $existingIds = array_flip($existingIds);

        $added = 0;
        $updated = 0;
        $keptIds = [];

        foreach ($converted['nodes'] as $node) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $node[$column] ?? ($column === 'name' ? '' : null);
            }
            $insertNode->execute($values);

            $keptIds[$node['id']] = true;
            if (isset($existingIds[$node['id']])) {
                $updated++;
            } else {
                $added++;
            }
        }

        /*
         * 辺。**在るものは触らない。**
         *
         * `km_map_edges` に (from, to) の一意制約が無いので、
         * 上書きの形にできない。いまの中身と突き合わせて、足りない分だけ入れる。
         */
        $existingEdges = [];
        foreach ($pdo->query('SELECT id, from_node_id, to_node_id FROM km_map_edges') as $row) {
            $key = km_app_map_edge_key((string) $row['from_node_id'], (string) $row['to_node_id']);
            $existingEdges[$key] = (int) $row['id'];
        }

        $insertEdge = $pdo->prepare(
            'INSERT INTO km_map_edges (from_node_id, to_node_id, distance) VALUES (?, ?, ?)'
        );
        $edgesAdded = 0;
        $keptEdges = [];

        foreach ($converted['edges'] as $edge) {
            $key = km_app_map_edge_key($edge['from'], $edge['to']);
            $keptEdges[$key] = true;
            if (isset($existingEdges[$key])) {
                continue;
            }
            $insertEdge->execute([$edge['from'], $edge['to'], $edge['distance']]);
            $edgesAdded++;
        }

        $removed = 0;
        $edgesRemoved = 0;

        if ($removeMissing) {
            /*
             * **辺が先。** `km_map_edges` には `km_map_nodes` への外部キーがあるので、
             * ノードから消すと外部キー制約で落ちる。
             */
            $deleteEdge = $pdo->prepare('DELETE FROM km_map_edges WHERE id = ?');
            foreach ($existingEdges as $key => $id) {
                if (!isset($keptEdges[$key])) {
                    $deleteEdge->execute([$id]);
                    $edgesRemoved++;
                }
            }

            $deleteNode = $pdo->prepare('DELETE FROM km_map_nodes WHERE id = ?');
            $deleteNodeEdges = $pdo->prepare(
                'DELETE FROM km_map_edges WHERE from_node_id = ? OR to_node_id = ?'
            );
            foreach (array_keys($existingIds) as $id) {
                $id = (string) $id;
                if (isset($keptIds[$id])) {
                    continue;
                }
                // 残っている辺ごと消す。**外部キーに CASCADE は付いていない**
                $deleteNodeEdges->execute([$id, $id]);
                $deleteNode->execute([$id]);
                $removed++;
            }
        }

        /*
         * 座標系もアプリに合わせる。**ここを忘れると全ノードがずれる。**
         * 見取り図をアプリの画像(1600×1200)へ差し替えてあるので、その値にする。
         *
         * **建物の階(lib/building-floors.php)は除く**(2026-09-24)。あちらは平面図 1 枚が
         * 1 つの階で、画像は 600×600 —— ここで一律に 1600×1200 を入れると、
         * 取り込みのたびに建物の中の地点が隅へ寄る。
         */
        require_once __DIR__ . '/building-floors.php';
        $floors = 0;
        $bounds = $pdo->prepare(
            'INSERT INTO km_map_floor_bounds (floor_id, coord_width, coord_height)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE coord_width = VALUES(coord_width), coord_height = VALUES(coord_height)'
        );
        foreach ($pdo->query('SELECT id FROM km_map_floors') as $row) {
            $floorId = (string) $row['id'];
            if (km_building_floor_is($floorId)) {
                continue;
            }
            $bounds->execute([
                $floorId,
                KM_APP_MAP_IMAGE_WIDTH,
                KM_APP_MAP_IMAGE_HEIGHT,
            ]);
            $floors++;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'removed' => $removed,
        'edgesAdded' => $edgesAdded,
        'edgesRemoved' => $edgesRemoved,
        'floors' => $floors,
    ];
}
