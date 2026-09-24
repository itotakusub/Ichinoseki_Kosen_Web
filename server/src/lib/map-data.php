<?php

declare(strict_types=1);

/**
 * 地図データ(階・ノード・経路)の取得。
 *
 * api/map-data.php と index.php の**両方**が使う。
 *
 * なぜ2箇所から使うのか:
 *   公開ページは、以前は HTML を読み終えてから app.js が /api/map-data.php を
 *   fetch していた。**HTML → CSS/JS → API → 画像** と4波に直列化しており、
 *   モバイル回線では地図が出るまでの待ちがそのぶん伸びていた。
 *   index.php は APK の有無を見るために**どのみち DB へ繋いでいる**ので、
 *   そのついでに地図データも読んで HTML へ同梱すれば、API の往復が丸ごと消える。
 *
 *   エンドポイントは残す。admin/map-editor.php が同じ Main/app.js を読んでおり、
 *   そちらは同梱しないため、fetch の経路が必要になる。
 */

require_once __DIR__ . '/map-access.php';
require_once __DIR__ . '/map-events.php';   // イベントモードの重ね合わせ
require_once __DIR__ . '/assets.php';   // km_public_asset(): 更新時刻を付けてキャッシュを破棄する
require_once __DIR__ . '/building-floors.php';

/**
 * 見取り図を **PHP 経由**で取るための URL。
 *
 * ## なぜ直リンクをやめたのか
 *
 * `Main/Picture/*.svg` は静的ファイルなので、nginx / Apache がそのまま配る。
 * つまり**パスさえ分かれば、パスワードを入れていなくても見取り図が取れた**。
 * ノードを隠しても図面が取れるなら、隠したことにならない。
 *
 * `api/floor-image.php` を通すと、そこで [km_map_view_unlocked] を見られる。
 * 直リンクの方は nginx で塞いである(`nginx/default.conf.template` の
 * `location ~ ^/Main/Picture/`)。
 *
 * **階の ID しか渡さない。** ファイル名を受け取ると、そこから抜け出す経路
 * (`../`)を自前で塞ぐことになる。ID なら DB に在るものしか通らない。
 */
function km_map_floor_image_endpoint(string $floorId, string $svgPath): string
{
    // ?v= は更新時刻。nginx が静的資材へ長い Cache-Control を付けるため、
    // 版が無いと見取り図を差し替えても反映されない(直リンク時代と同じ理由)。
    $version = km_public_asset('Main/' . ltrim($svgPath, '/'));
    $stamp = str_contains($version, '?v=') ? substr($version, strpos($version, '?v=') + 3) : '';

    return '/api/floor-image.php?floor=' . rawurlencode($floorId)
        . ($stamp !== '' ? '&v=' . rawurlencode($stamp) : '');
}

/**
 * 配ってよい見取り図の種別。**許可した拡張子だけ。**
 *
 * 拡張子から MIME を機械的に組み立てず一覧にするのは、`km_map_floors.svg_path` に
 * 想定外の値が入ったときに、こちらが知らない種別をそのまま名乗って配らないため。
 */
const KM_FLOOR_IMAGE_TYPES = [
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];

/**
 * 見取り図の Content-Type。配れない種別なら null。
 *
 * ## 列名を信じてはいけない
 *
 * 列は `svg_path` だが、**中身は PNG**(圧縮 PNG へ切り替えたとき、参照箇所を
 * 増やさないよう列名だけ据え置いた)。api/floor-image.php はここを
 * `image/svg+xml` 決め打ちにしていたため、`X-Content-Type-Options: nosniff` と
 * 噛み合って**ブラウザが画像を捨て、全階が「読み込めませんでした」になっていた**。
 * 名前ではなく実物の拡張子で決める。
 */
function km_map_floor_image_content_type(string $path): ?string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return KM_FLOOR_IMAGE_TYPES[$extension] ?? null;
}

/**
 * 地図データ一式を組み立てる。
 *
 * フィールド名は移行前の graph.js(node.floor / edge.source / edge.target)と
 * 揃えてある。これにより app.js 側は「どこから受け取るか」だけの違いで済む。
 *
 * ## イベントモード(1.0.4)
 *
 * 恒久データを読んだあとに、有効なイベントの重ね合わせを被せる。
 * **エッジは配列から消さず `closed` を立てるだけ**にしてある —— 消すと利用者側で
 * 「なぜ遠回りするのか」が分からなくなるため、地図には破線で残して理由を出す。
 *
 * @param int|null $previewEventId 管理画面のプレビュー専用。**公開ページからは渡さない**
 * @param bool $forAdmin 管理画面の地図編集から呼ぶときだけ true。地図の錠を素通りする。
 *        **公開ページからは絶対に渡さない** —— 渡すと錠が意味を失う。
 *        呼ぶ側は admin/_inc/guard.php の内側であること(= 管理者だと確かめ済み)。
 * @return array{floors:array,nodes:array,edges:array,namesUnlocked:bool,mode:string,
 *               event:?array,eventPois:array}
 */
function km_map_data(PDO $pdo, ?int $previewEventId = null, bool $forAdmin = false): array
{
    $config = km_map_access_config();
    $overlay = km_map_event_overlay($pdo, $previewEventId);

    /*
     * **イベント中は氏名を出さない。**
     *
     * 高専祭やオープンキャンパスでは外部の来場者が地図を見る。解除パスワードを
     * 知っている人のセッションであっても、期間中は伏せる。
     * 判定がここ1箇所しか無いので、ここで潰せば漏れない。
     *
     * ## 編集画面には必ず出す($forAdmin)
     *
     * **伏せると氏名が消える。** admin/assets/js/map-editor.js は読み込んだ
     * `occupantName` をそのまま入力欄へ入れ、保存時にその値を送り返す。
     * null で受け取ると入力欄は空になり、ノード名を直しただけのつもりでも
     * **氏名を空で上書きしてしまう**(本番は mode = 'hidden' なので、
     * 編集するたびに1件ずつ失われていた)。
     *
     * 「見せない」と「編集させる」は両立しない。編集画面では出す。
     */
    $unlocked = $forAdmin || (km_map_names_unlocked($config) && !$overlay['hideOccupantNames']);

    /*
     * ノード座標系は km_map_floors の列ではなく**別表**に持つ。
     *
     * アプリの DB 利用者(Main)には SELECT/INSERT/UPDATE/DELETE/CREATE しか与えられて
     * おらず、**ALTER が無い**。列を足すには root が要り、そのためだけに権限を広げると
     * 実行時の利用者がスキーマを変えられることになる。CREATE だけで完結する別表なら、
     * マイグレーションがアプリの資格情報のまま動き、本番へ移すときも同じ手順で通る。
     *
     * 表がまだ無い時間帯(ファイルを先に配備し、あとからマイグレーションを流す)が
     * 必ずできるので、**失敗しても地図は出す**。その場合は座標系が空になり、
     * app.js は従来どおり画像の固有サイズへ落ちる。
     */
    $coordSpaces = [];
    try {
        $boundRows = $pdo->query('SELECT floor_id, coord_width, coord_height FROM km_map_floor_bounds')->fetchAll();
        foreach ($boundRows as $row) {
            $coordSpaces[(string) $row['floor_id']] = [
                (float) $row['coord_width'],
                (float) $row['coord_height'],
            ];
        }
    } catch (Throwable $exception) {
        // km_map_floor_bounds がまだ無い = 移行前。座標系は画像から決める
    }

    $floors = $pdo
        ->query('SELECT id, label, svg_path AS svgPath, sort_order AS sortOrder FROM km_map_floors ORDER BY sort_order')
        ->fetchAll();
    foreach ($floors as &$floor) {
        // **常に PHP 経由にする。** 公開のときだけ直リンクにすると経路が2本になり、
        // 設定を切り替えた瞬間に古い HTML が直リンクを掴んだままになる。
        $floor['svgPath'] = km_map_floor_image_endpoint(
            (string) $floor['id'],
            (string) $floor['svgPath']
        );
        [$coordWidth, $coordHeight] = $coordSpaces[(string) $floor['id']] ?? [null, null];
        $floor['coordWidth'] = $coordWidth;
        $floor['coordHeight'] = $coordHeight;
    }
    unset($floor); // 参照渡しの後始末。外さないと次の foreach が最後の要素を壊す

    /*
     * `title` / `subtitle` は**在るときだけ読む。**
     * 列を足す SQL は配備利用者が別に流すので、コードの配備と順番が決まっていない
     * (`km_map_nodes_has_split_name` の説明を参照)。
     *
     * **`name` は今までどおり必ず返す。** 公開ページの検索とカテゴリ絞り込みは
     * `node.name` を見ており、そこを壊さない。
     */
    require_once __DIR__ . '/app-map-sync.php';
    $available = km_map_nodes_columns($pdo);
    $nodeColumns = 'id, floor_id AS floor, name, occupant_name AS occupantName, type, x, y';
    /*
     * 2026-09-03 に増えた列。**在るものだけ読む。**
     * `type2` と `note` は地点の詳細に出す(アプリのボトムシートに合わせる)。
     *
     * `transfer_group_id` は**階段と出入口の対応付け**(2026-09-05 に追加)。
     * 出していなかったので、Website の経路探索は**屋外と 1F を繋ぐ手がかりを
     * 持っていなかった** —— 外から中への案内が必ず失敗していた
     * (アプリは `RouteSearch.kt` でこの ID を使って繋いでいる)。
     * ここに列が在っても値が空なら繋がらないので、**地図編集で ID を入れること。**
     */
    $optionalColumns = [
        'title' => null,
        'subtitle' => null,
        'type2' => null,
        'note' => null,
        'transfer_group_id' => 'transferGroupId',
    ];
    foreach ($optionalColumns as $column => $alias) {
        if (!in_array($column, $available, true)) {
            continue;
        }
        $nodeColumns .= ', ' . $column . ($alias === null ? '' : ' AS ' . $alias);
    }
    $nodeStmt = $pdo->query("SELECT {$nodeColumns} FROM km_map_nodes");
    $nodesById = [];
    $nodeTypes = [];
    foreach ($nodeStmt->fetchAll() as $node) {
        if (!$unlocked) {
            $node['occupantName'] = null;
        }
        /*
         * 座標は**数として返す。** decimal 列は PDO が文字列で返すので、
         * そのままだと JSON に `"514.27"` と入り、描画側で比較や計算が文字列になる。
         */
        $node['x'] = (float) $node['x'];
        $node['y'] = (float) $node['y'];
        $id = (string) $node['id'];
        $nodeTypes[$id] = (string) $node['type'];
        if (isset($overlay['closedNodes'][$id])) {
            $node['closed'] = true;
            $node['closureReason'] = $overlay['closedNodes'][$id];
        }
        if (isset($overlay['aliases'][$id])) {
            $node['alias'] = $overlay['aliases'][$id];
        }
        $nodesById[$id] = $node;
    }

    $edges = $pdo
        ->query('SELECT from_node_id AS source, to_node_id AS target, distance FROM km_map_edges')
        ->fetchAll();
    foreach ($edges as &$edge) {
        $key = km_map_event_edge_key((string) $edge['source'], (string) $edge['target']);
        if (isset($overlay['closedEdges'][$key])) {
            $edge['closed'] = true;
            $edge['closureReason'] = $overlay['closedEdges'][$key];
        }
        /*
         * **壁は通れない。**
         *
         * 壁のノードを持つようになった(2026-09-03)ので、壁を繋ぐ線も
         * `km_map_edges` に入る。印を付けずに出すと、**経路探索が壁を通り抜ける。**
         *
         * 判定はアプリと同じ —— **両端が `wall` の線だけ**が壁
         * (`LineRenderer.kt` の `isWallLine`)。片方だけなら通路と壁を繋いだ線で、
         * それは通れる。2箇所で違う判定をすると、案内が食い違う。
         */
        $edge['wall'] = ($nodeTypes[(string) $edge['source']] ?? '') === 'wall'
            && ($nodeTypes[(string) $edge['target']] ?? '') === 'wall';
    }
    unset($edge);

    /*
     * 画面に出すのは**先頭のイベント1つぶん**の案内(バナー)。
     * 工事と祭が重なっているときに2枚出しても読まれないので、名前は連結して見せる。
     */
    $event = null;
    if ($overlay['events'] !== []) {
        $banner = null;
        foreach ($overlay['events'] as $candidate) {
            if (($candidate['bannerText'] ?? null) !== null && $candidate['bannerText'] !== '') {
                $banner = $candidate;
                break;
            }
        }
        $event = [
            'names' => array_column($overlay['events'], 'name'),
            'bannerText' => $banner['bannerText'] ?? null,
            'bannerUrl' => $banner['bannerUrl'] ?? null,
            'hideOccupantNames' => $overlay['hideOccupantNames'],
        ];
    }

    /*
     * **地図そのものに錠が掛かっているなら、中身を1件も返さない。**
     *
     * 一覧を組み立ててから捨てているのは、上の処理(イベントの重ね合わせ・座標系)を
     * 二重に書かないため。**返す直前の1箇所で落とす**ので、
     * 経路が増えてもここを通る限り漏れない。
     *
     * 階の一覧も返さない —— 見取り図の URL が入っており、
     * それが分かれば api/floor-image.php を総当たりできてしまう。
     *
     * `mapLocked` は画面が「パスワードを聞く」ために使う。**何が足りないかを伝える**
     * ためのもので、これを出さないと利用者には故障に見える。
     *
     * 管理画面の編集は $forAdmin で素通りする。**セッションの印ではなく引数で受ける**
     * ことに意味がある —— 印にすると「管理画面を一度開いた人」が公開ページでも
     * 素通りしてしまい、設定した本人が錠を確かめられなくなる(実際そうなっていた)。
     */
    $viewUnlocked = $forAdmin || km_map_view_unlocked($config);
    if (!$viewUnlocked) {
        return [
            'floors' => [],
            'nodes' => [],
            'edges' => [],
            'namesUnlocked' => false,
            'mode' => $config['mode'],
            'mapLocked' => true,
            'passwordRequested' => km_map_password_requested($config),
            'event' => null,
            'eventPois' => [],
        ];
    }

    return [
        'floors' => $floors,
        'nodes' => $nodesById,
        'edges' => $edges,
        'namesUnlocked' => $unlocked,
        'mode' => $config['mode'],
        'mapLocked' => false,
        'passwordRequested' => km_map_password_requested($config),
        'event' => $event,
        'eventPois' => $overlay['pois'],
        'buildings' => km_map_building_overlays($pdo),
        /*
         * 建物の階(2026-09-24。lib/building-floors.php)。**平面図 1 枚が 1 つの階。**
         * 屋外の建物を押したときに「中へ 図書館 1F …」を出すために、
         * 名前の手がかり(keywords)ごと渡す —— **中にまだ地点が無くても案内したい**ので、
         * 地点から導くのでは足りない。
         */
        'buildingFloors' => array_map(
            static fn (array $floor): array => [
                'id' => $floor['id'],
                'label' => $floor['label'],
                'keywords' => $floor['keywords'],
            ],
            KM_BUILDING_FLOORS
        ),
    ];
}

/**
 * 建物平面図の配置(`km_map_overlays`)。屋外図の上に重ねる小さな見取り図。
 *
 * ## 画像はこちらにも要る
 *
 * アプリは APK の中の drawable を使う(`MapOverlayCatalog.kt`)。
 * **サーバーから配れない**ので、Website 側にも同じ絵を置いてある
 * (`Main/Picture/bldg/`)。
 *
 * **同じ絵を2箇所に置いている。** アプリに建物を足したらこちらにも要る ——
 * 足し忘れると、その建物だけ Web に出ない。だから
 * **知らないキーは黙って飛ばさず、`missing` として返す**(画面で数えられる)。
 *
 * 表がまだ無い環境(移行 SQL を流す前)では空を返す。
 *
 * @return array{items:array<int,array<string,mixed>>, missing:array<int,string>}
 */
function km_map_building_overlays(PDO $pdo): array
{
    require_once __DIR__ . '/app-map-convert.php';

    $rows = km_app_map_select_optional(
        $pdo,
        'SELECT uuid, image_key, floor_id, x, y, scale, rotation_degrees, opacity, visible
           FROM km_map_overlays WHERE visible = 1'
    );

    $items = [];
    $missing = [];
    foreach ($rows as $row) {
        $key = (string) $row['image_key'];
        // `../` などを弾く。設定由来とはいえ、置き場の外を読ませない
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            $missing[] = $key;
            continue;
        }
        $file = __DIR__ . '/../Main/Picture/bldg/' . $key . '.png';
        if (!is_file($file)) {
            $missing[] = $key;
            continue;
        }

        $items[] = [
            'uuid' => (string) $row['uuid'],
            'imageKey' => $key,
            'floor' => (string) $row['floor_id'],
            'x' => (float) $row['x'],
            'y' => (float) $row['y'],
            'scale' => (float) $row['scale'],
            'rotationDegrees' => (float) $row['rotation_degrees'],
            'opacity' => (float) $row['opacity'],
            /*
             * 建物の何階の図か。`bldg_library_1f` の接尾辞から取る
             * (アプリの `overlayBuildingFloor` と同じ規則)。
             * 屋外図の上で「建物 1F / 2F」を切り替えるのに使う。
             */
            'buildingFloor' => str_ends_with($key, '_1f') ? 1 : (str_ends_with($key, '_2f') ? 2 : null),
        ];
    }

    return ['items' => $items, 'missing' => array_values(array_unique($missing))];
}
