<?php

declare(strict_types=1);

/**
 * 地図データ(km_map_nodes / km_map_edges)の編集。admin/map-editor.php から使う。
 *
 * **操作ごとの関数にしてある。旧実装のような「グラフ丸ごと保存」はしない。**
 * 旧 Main/builder.js + save_graph.php は操作のたびにグラフ全体を POST し、サーバーはそれを
 * graph.js へ書き戻していた。しかし今のスキーマには occupant_name(教職員氏名)があり、
 * ブラウザ側のノードはその列を持っていない。丸ごと保存すると**氏名が全件消える**。
 * 操作ごとに「触る列だけ」を更新することで、この事故を構造的に起こせなくしている
 * (例: km_map_node_update() は name/type だけを受け取り、
 *  km_map_node_move() は x/y しか書かない)。
 *
 * **氏名は 2026-09-03 にここへ戻ってきた。** 一度は管理アプリを正本にしたが、
 * 利用者の指示で向きを逆にした(作成も配信も Website 一括)。
 * ただし**「未指定」と「空にする」は分ける** —— 上の事故は、画面が持っていない列を
 * `null` として送り返したことで起きた。氏名は**専用の操作**でだけ書く。
 *
 * 検証もここに集約する。API 側(admin/api/map-edit.php)は受け取った値を渡すだけにして、
 * 「どこかの経路だけ検証が抜けている」状態を作らない。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app-map-convert.php';

/**
 * 置いてよいノードの種類。**アプリと同じ語彙を使う**(2026-09-03)。
 *
 * 以前は Web だけの語彙(`point` / `All`)を持っていた。
 * `point` はアプリの `road` と `entrance` の**両方**から来ており、
 * **逆写しでは戻せない** —— Website が正本になった以上、潰せない。
 *
 * `All`(どのズームでも出す)は表示の都合の分類だった。
 * 種別ごとのズーム閾値を持つようになったので役目ごと無くなった。
 *
 * 旧実装の 'ad'(広告)も無い。xrea.com の広告を消した名残で、既に描画対象外だった。
 */
const KM_MAP_NODE_TYPES = KM_APP_MAP_NODE_TYPES;

/** 座標の許容範囲。フロア画像は数千 px 程度なので、桁違いの値は弾く。 */
const KM_MAP_COORD_MIN = -100000;
const KM_MAP_COORD_MAX = 100000;

/** @return array<int, string> 実在する階 id */
function km_map_floor_ids(PDO $pdo): array
{
    return $pdo->query('SELECT id FROM km_map_floors ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
}

function km_map_assert_type(string $type): void
{
    if (!in_array($type, KM_MAP_NODE_TYPES, true)) {
        throw new InvalidArgumentException('種類の指定が不正です(' . implode(' / ', KM_MAP_NODE_TYPES) . ')。');
    }
}

function km_map_assert_floor(PDO $pdo, string $floorId): void
{
    // FK 任せにすると分かりにくいエラーになるので、先に見て日本語で返す
    if (!in_array($floorId, km_map_floor_ids($pdo), true)) {
        throw new InvalidArgumentException("階の指定が不正です: {$floorId}");
    }
}

function km_map_assert_coord(float $value, string $label): void
{
    if ($value < KM_MAP_COORD_MIN || $value > KM_MAP_COORD_MAX) {
        throw new InvalidArgumentException("{$label} の値が範囲外です。");
    }
}

function km_map_assert_name(string $name): void
{
    if (trim($name) === '') {
        throw new InvalidArgumentException('名前を入力してください。');
    }
    if (mb_strlen($name) > 255) {
        throw new InvalidArgumentException('名前は255文字以内にしてください。');
    }
}

/** @return array{id:string, floor:string, name:string, occupantName:?string, type:string, x:int, y:int}|null */
function km_map_node_find(PDO $pdo, string $id): ?array
{
    /*
     * 列は**在るものだけ読む。** 移行 SQL(`scripts/migrate-map-app-schema.sql`)は
     * 配備利用者が別に流すもので、コードの配備と順番が決まっていない。
     */
    require_once __DIR__ . '/app-map-sync.php';
    $available = km_map_nodes_columns($pdo);
    $select = 'id, floor_id AS floor, name, occupant_name AS occupantName, type, x, y';
    foreach (['uuid', 'title', 'subtitle', 'note', 'type2'] as $column) {
        if (in_array($column, $available, true)) {
            $select .= ', ' . $column;
        }
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM km_map_nodes WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function km_map_node_require(PDO $pdo, string $id): array
{
    $node = km_map_node_find($pdo, $id);
    if ($node === null) {
        throw new InvalidArgumentException("ノードが見つかりません: {$id}");
    }

    return $node;
}

/**
 * 新しいノードの uuid を作る。**アプリと同じ形(UUID)で。**
 *
 * 以前は `n_` + 24 桁の16進(26 文字)を採番していた。写しだった頃はそれでよかったが、
 * **Website が正本になると、この id がそのままアプリへ配られる。**
 * アプリの `AdMapNode.uuid` は UUID を前提にしており、
 * 独自の形を混ぜると端末側で線の突き合わせや重複判定が読みにくくなる。
 *
 * **クライアントから来た id は使わない**(重複も細工も防げない)。
 */
function km_map_new_node_uuid(): string
{
    $bytes = random_bytes(16);
    // 版4・変種の印。これを立てないと、ただの16進文字列と区別が付かない
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * 新しいノード id を作る。uuid からハイフンを抜いた 32 文字(`varchar(32)` にちょうど収まる)。
 */
function km_map_new_node_id(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $uuid = km_map_new_node_uuid();
        if (km_map_node_find($pdo, km_app_map_node_id($uuid)) === null) {
            return $uuid;
        }
    }

    throw new RuntimeException('ノード ID を採番できませんでした。');
}

/**
 * 地点を作る。
 *
 * **`title` と `subtitle` を別に受け取る。** 繋いだ `name`(`トレーナー室 管-104`)から
 * 分かれ目は戻せない —— 部屋名に空白が入っていると、どこで切れるか決められない。
 * `name` は繋いだものを一緒に入れる(公開ページの検索がそこを見ている)。
 */
function km_map_node_create(
    PDO $pdo,
    string $floorId,
    string $title,
    string $subtitle,
    string $type,
    float $x,
    float $y
): string {
    km_map_assert_floor($pdo, $floorId);
    km_map_assert_name($title);
    km_map_assert_type($type);
    km_map_assert_coord($x, 'X');
    km_map_assert_coord($y, 'Y');

    $uuid = km_map_new_node_id($pdo);
    $id = km_app_map_node_id($uuid);
    $name = km_app_map_node_name($title, $subtitle);

    require_once __DIR__ . '/app-map-sync.php';
    $available = km_map_nodes_columns($pdo);
    $columns = ['id', 'floor_id', 'name', 'occupant_name', 'type', 'x', 'y'];
    $values = [$id, $floorId, $name, null, $type, $x, $y];
    foreach (['uuid' => $uuid, 'title' => trim($title), 'subtitle' => trim($subtitle)] as $column => $value) {
        if (in_array($column, $available, true)) {
            $columns[] = $column;
            $values[] = $value;
        }
    }

    $pdo->prepare(
        'INSERT INTO km_map_nodes (' . implode(', ', $columns) . ')'
        . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
    )->execute($values);

    return $id;
}

/**
 * 地点の名前と種類を更新する。**教職員氏名には触らない。**
 *
 * ## なぜここでは氏名を書かないのか
 *
 * 以前ここが受け取った値をそのまま入れており、**画面が読み込んだ null を
 * そのまま送り返して氏名を空で上書きしていた**(氏名を伏せている状態で
 * 地点名を直しただけで失われた)。
 *
 * 氏名は 2026-09-03 に Website が正本へ戻ったが、**この事故の形は戻さない。**
 * 氏名を書くのは専用の [km_map_node_set_occupant] だけ ——
 * 「うっかり一緒に送ってしまう」ことが構造的に起きない。
 */
function km_map_node_update(
    PDO $pdo,
    string $id,
    string $title,
    string $subtitle,
    string $type
): void {
    km_map_node_require($pdo, $id);
    km_map_assert_name($title);
    km_map_assert_type($type);

    require_once __DIR__ . '/app-map-sync.php';
    $name = km_app_map_node_name($title, $subtitle);

    if (km_map_nodes_has_split_name($pdo)) {
        $pdo->prepare('UPDATE km_map_nodes SET name = ?, title = ?, subtitle = ?, type = ? WHERE id = ?')
            ->execute([$name, trim($title), trim($subtitle), $type, $id]);

        return;
    }

    $pdo->prepare('UPDATE km_map_nodes SET name = ?, type = ? WHERE id = ?')
        ->execute([$name, $type, $id]);
}

/**
 * 教職員氏名だけを更新する。**この1つの操作でしか書けない。**
 *
 * 空文字は `null`(氏名なし)にする —— 入力欄を空にしたときに `''` が入ると、
 * 「氏名がある地点」として数えられてしまう。
 */
function km_map_node_set_occupant(PDO $pdo, string $id, ?string $occupantName): void
{
    km_map_node_require($pdo, $id);

    $value = $occupantName === null ? null : trim($occupantName);
    if ($value === '') {
        $value = null;
    }
    if ($value !== null && mb_strlen($value) > 255) {
        throw new InvalidArgumentException('氏名が長すぎます(255 文字まで)。');
    }

    $pdo->prepare('UPDATE km_map_nodes SET occupant_name = ? WHERE id = ?')->execute([$value, $id]);
}

/**
 * メモ(`note`)だけを更新する。列が無い環境では何もしない(移行 SQL は配備と別に流す)。
 * 教職員の提案(lib/staff-nodes.php)から使う。空文字は `null`。
 */
function km_map_node_set_note(PDO $pdo, string $id, ?string $note): void
{
    km_map_node_require($pdo, $id);
    require_once __DIR__ . '/app-map-sync.php';
    if (!in_array('note', km_map_nodes_columns($pdo), true)) {
        return;
    }

    $value = $note === null ? null : trim($note);
    if ($value === '') {
        $value = null;
    }
    if ($value !== null && mb_strlen($value) > 500) {
        throw new InvalidArgumentException('メモが長すぎます(500 文字まで)。');
    }

    $pdo->prepare('UPDATE km_map_nodes SET note = ? WHERE id = ?')->execute([$value, $id]);
}

/** 座標だけを更新する(ドラッグ移動用)。名前や氏名には触らない。 */
function km_map_node_move(PDO $pdo, string $id, float $x, float $y, ?string $floorId = null): void
{
    km_map_node_require($pdo, $id);
    km_map_assert_coord($x, 'X');
    km_map_assert_coord($y, 'Y');

    if ($floorId !== null) {
        km_map_assert_floor($pdo, $floorId);
        $pdo->prepare('UPDATE km_map_nodes SET x = ?, y = ?, floor_id = ? WHERE id = ?')
            ->execute([$x, $y, $floorId, $id]);
        return;
    }

    $pdo->prepare('UPDATE km_map_nodes SET x = ?, y = ? WHERE id = ?')->execute([$x, $y, $id]);
}

/**
 * ノードを削除する。
 *
 * km_map_edges の FK は CASCADE していないので、**先に依存するエッジを消す**。
 * 順番を間違えると外部キー違反で失敗する。まとめて成否を揃えたいのでトランザクションで囲う。
 *
 * @return int 巻き添えで消したエッジの本数
 */
function km_map_node_delete(PDO $pdo, string $id): int
{
    km_map_node_require($pdo, $id);

    $pdo->beginTransaction();
    try {
        $edges = $pdo->prepare('DELETE FROM km_map_edges WHERE from_node_id = ? OR to_node_id = ?');
        $edges->execute([$id, $id]);
        $removed = $edges->rowCount();

        $pdo->prepare('DELETE FROM km_map_nodes WHERE id = ?')->execute([$id]);
        $pdo->commit();

        return $removed;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * 2つのノードを繋ぐ。
 *
 * **距離はここで計算する。** クライアントから送られた数値は使わない(経路探索の重みなので、
 * 偽の値を入れられると案内結果を操作できてしまう)。
 */
function km_map_edge_create(PDO $pdo, string $fromId, string $toId): int
{
    if ($fromId === $toId) {
        throw new InvalidArgumentException('同じノード同士は繋げません。');
    }

    $from = km_map_node_require($pdo, $fromId);
    $to = km_map_node_require($pdo, $toId);

    // 向き違いも重複とみなす(経路探索は双方向に扱うため)
    $dup = $pdo->prepare(
        'SELECT COUNT(*) FROM km_map_edges
         WHERE (from_node_id = ? AND to_node_id = ?) OR (from_node_id = ? AND to_node_id = ?)'
    );
    $dup->execute([$fromId, $toId, $toId, $fromId]);
    if ((int) $dup->fetchColumn() > 0) {
        throw new InvalidArgumentException('この2点は既に繋がっています。');
    }

    $distance = (int) round(sqrt(
        (((int) $from['x']) - ((int) $to['x'])) ** 2 + (((int) $from['y']) - ((int) $to['y'])) ** 2
    ));

    $pdo->prepare('INSERT INTO km_map_edges (from_node_id, to_node_id, distance) VALUES (?, ?, ?)')
        ->execute([$fromId, $toId, $distance]);

    return $distance;
}

/**
 * エッジを削除する。
 *
 * /api/map-data.php はエッジに id を含めないので、両端の組で消す。向きは問わない。
 */
function km_map_edge_delete(PDO $pdo, string $fromId, string $toId): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM km_map_edges
         WHERE (from_node_id = ? AND to_node_id = ?) OR (from_node_id = ? AND to_node_id = ?)'
    );
    $stmt->execute([$fromId, $toId, $toId, $fromId]);

    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('その経路は見つかりません。');
    }
}

/**
 * 複数ノードの x または y を揃える(旧実装の「整列」)。
 *
 * @param array<int, string> $ids
 */
function km_map_nodes_align(PDO $pdo, array $ids, string $axis, float $value): int
{
    if (!in_array($axis, ['x', 'y'], true)) {
        throw new InvalidArgumentException('軸の指定が不正です。');
    }
    km_map_assert_coord($value, strtoupper($axis));

    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn (string $v): bool => $v !== '')));
    if (count($ids) < 2) {
        throw new InvalidArgumentException('整列するノードを2つ以上選んでください。');
    }

    $pdo->beginTransaction();
    try {
        // 列名は $axis に限定済みなので埋め込んでよい(値はプレースホルダ)
        $stmt = $pdo->prepare("UPDATE km_map_nodes SET {$axis} = ? WHERE id = ?");
        foreach ($ids as $id) {
            km_map_node_require($pdo, $id);
            $stmt->execute([$value, $id]);
        }
        $pdo->commit();

        return count($ids);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
