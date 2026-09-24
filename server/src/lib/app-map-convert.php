<?php

declare(strict_types=1);

/**
 * 管理アプリの地図(MapDataSnapshot)と、Website の `km_map_nodes` / `km_map_edges` を
 * **両方向に**変換する。
 *
 * ## 向きが変わった(2026-09-03)
 *
 * それまでは**アプリが正本**で、ここは片道(アプリ→Web)だけだった。
 * 理由は「指紋・平面図の配置・Wi-Fi の校正値は Web に置き場が無く、逆向きでは必ず落ちる」。
 *
 * **配信中の JSON を実際に開いて数えたら、それが1件も無かった** ——
 * `fingerprints` 0、`overlays` キーごと無し、`wall`/`wifi_router` 0、`occupantName` 0
 * (`version 5` / 582 ノード)。**落ちて困るものが、まだ作られていない。**
 *
 * そこで利用者の指示で**向きを逆にした**。Website が地図の保管庫になり、
 * 作成・編集・配信を1箇所で行う。器は `scripts/migrate-map-app-schema.sql` で広げてある。
 *
 * ## 両方で編集できる
 *
 * アプリ側の編集も残す(利用者の判断)。**そのため、どちらの向きも
 * 差分を見せてから適用する。** 片方が黙って全部を作り直すと、
 * もう片方で直したものが消える —— それが「地図が2つある」状態の正体だった。
 *
 * ## ここは DB を触らない…が、逆向きだけは読む
 *
 * アプリ→Web は純粋関数。**DB 無しで検査できる**ようにするため
 * (`scripts/check.php` の `app-map-convert`)。
 * Web→アプリ([km_app_map_from_web])は DB から読むので、
 * **組み立てだけを [km_app_map_snapshot_from_rows] に分けて**そちらを検査する。
 */

require_once __DIR__ . '/app-map.php';

/**
 * アプリの基準マップ画像の実寸(`res/drawable/f1_r.png` ほか、全階 1600×1200)。
 *
 * ## 座標を変換しないために、画像の方を揃える
 *
 * Web は同じ図面を 801×801 で持ち、`km_map_floor_bounds` は 1122×1122 だった。
 * **同じ建物の同じ図面だが、切り取り方と解像度が違う。** 実測すると
 * アプリ→Web の当てはめは x 0.697 倍 / y 0.94 倍と**縦横で比が合わず**、
 * 手で置いた位置の差が最大 83px 残った —— 比を推定して当てると、その誤差ごと焼き付く。
 *
 * **Web の見取り図をアプリの画像に差し替え、`km_map_floor_bounds` をこの値にする。**
 * そうすれば倍率は 1 で、変換は Y の反転だけになる(下記)。図面そのものは同じなので、
 * 見た目は余白が広がるだけで内容は変わらない。
 */
const KM_APP_MAP_IMAGE_WIDTH = 1600;
const KM_APP_MAP_IMAGE_HEIGHT = 1200;

/**
 * アプリの Y 座標を Web の Y 座標へ。**反転する。**
 *
 * アプリは画面座標で、Y は**下**へ増える。Web は Leaflet の `L.CRS.Simple` で
 * `bounds = [[0,0],[h,w]]` を使っており、緯度が**上**へ増える
 * (`Main/app.js` の `boundsForFloor` と `L.circleMarker([node.y, node.x])`)。
 *
 * **ここを忘れると地図が上下逆さまになる。** しかも建物の形は対称に近いので、
 * 一見それらしく見えてしまう。
 */
function km_app_map_flip_y(float $appY, int $imageHeight = KM_APP_MAP_IMAGE_HEIGHT): float
{
    return $imageHeight - $appY;
}

/**
 * その階の画像の高さ。**反転はこの値で行う。**
 *
 * 建物の階(lib/building-floors.php。2026-09-24)は平面図 1 枚が 1 つの階で、
 * 画像は 600×600 —— 1200 で反転すると**建物の中の地点が図の外へ飛ぶ**。
 * Web の階 id でもアプリの階の値でも引けるようにしてある(呼ぶ側で持っている方が違う)。
 */
function km_app_map_floor_height(string $floor): int
{
    require_once __DIR__ . '/building-floors.php';
    $webFloor = KM_APP_MAP_FLOOR_MAP[strtoupper(trim($floor))] ?? trim($floor);

    return km_building_floor_is($webFloor) ? KM_BUILDING_FLOOR_SIZE : KM_APP_MAP_IMAGE_HEIGHT;
}

/**
 * 階の表記。アプリは `1F` / `OUTSIDE`、Web は `1` / `outside`。
 *
 * **対応表を持つ。** 「先頭の数字を取る」のような規則で書くと `OUTSIDE` が落ちるし、
 * 将来 `B1F` が来たときに黙って壊れる。**知らない階は変換せず、数えて報告する。**
 */
const KM_APP_MAP_FLOOR_MAP = [
    '1F' => '1',
    '2F' => '2',
    '3F' => '3',
    '4F' => '4',
    '5F' => '5',
    'OUTSIDE' => 'outside',
    /*
     * 建物の階(2026-09-24。lib/building-floors.php とアプリの BuildingFloorCatalog.kt)。
     * **平面図 1 枚が 1 つの階。** 値は Web 側の id を大文字にしたもので、
     * 規則ではなくここに並べてある(知らない階は変換せず数えて報告する、という上の方針のまま)。
     */
    'BLDG_LIBRARY_1F' => 'bldg_library_1f',
    'BLDG_LIBRARY_2F' => 'bldg_library_2f',
    'BLDG_HAGITOMO_1F' => 'bldg_hagitomo_1f',
    'BLDG_HAGITOMO_2F' => 'bldg_hagitomo_2f',
    'BLDG_TECHNO_1F' => 'bldg_techno_1f',
    'BLDG_TECHNO_2F' => 'bldg_techno_2f',
    'BLDG_GYM1' => 'bldg_gym1',
    'BLDG_GYM2' => 'bldg_gym2',
    'BLDG_BUDOKAN' => 'bldg_budokan',
    'BLDG_MACHINE' => 'bldg_machine',
    'BLDG_CHEM_SHOP' => 'bldg_chem_shop',
];

/**
 * ノードの種類。**アプリと Web で同じ語彙を使う。**
 *
 * 以前は対応表で潰していた —— `road` と `entrance` を両方 `point` にし、
 * `wall` と `wifi_router` は捨てていた。写しだったので、それで足りていた。
 *
 * **Web が正本になると、潰した瞬間に情報が消える。**
 * しかも `point` は2つの種類から来るので、**逆写しでは復元できない**
 * (どちらだったか、もうどこにも書いていない)。
 *
 * だから語彙をアプリに合わせ、変換をやめた。
 * 移行 SQL が既存の `point` を `road` へ寄せている(繋ぎ。正しい値は取り込みで入る)。
 *
 * | 種類 | 何か |
 * |---|---|
 * | `room` | 部屋 |
 * | `facility` | 施設 |
 * | `stairs` | 階段(階をまたぐ乗り換え点) |
 * | `road` | 経路の通過点。**これを繋いだものが経路探索のグラフ** |
 * | `entrance` | 出入口 |
 * | `wall` | 壁。**経路探索では通れない**(両端が `wall` の線が壁) |
 * | `wifi_router` | Wi-Fi ルーターの設置位置。測位用 |
 */
const KM_APP_MAP_NODE_TYPES = [
    'room',
    'facility',
    'stairs',
    'road',
    'entrance',
    'wall',
    'wifi_router',
];

/**
 * アプリの UUID を `km_map_nodes.id`(`varchar(32)`)へ収める。
 *
 * **UUID は 36 文字で、そのままでは入らない。** ハイフンを抜くとちょうど 32 文字になる。
 *
 * 列を広げないのは、`ALTER` が**アプリの DB 利用者に与えられていない**ため
 * (権限を広げると、実行時の利用者がスキーマを変えられる)。桁を削らずに収まる形が
 * ちょうどあるので、それを使う。
 *
 * UUID 以外の ID(旧 Web 側の `n_new_10` など)が来たら、32 文字へ切り詰める。
 * **切り詰めが起きたことは呼ぶ側が数えられるよう、長さで判定できるようにしてある。**
 */
function km_app_map_node_id(string $uuid): string
{
    $compact = str_replace('-', '', trim($uuid));

    return mb_strlen($compact) <= 32 ? $compact : mb_substr($compact, 0, 32);
}

/** 階の対応。知らない階なら null。 */
function km_app_map_floor_id(string $appFloor): ?string
{
    return KM_APP_MAP_FLOOR_MAP[strtoupper(trim($appFloor))] ?? null;
}

/**
 * 階の対応の**逆向き**(Web → アプリ)。知らない階なら null。
 *
 * イベントだけは Web が正本になるので、この向きが要る
 * ([km_app_map_events_from_web])。**同じ対応表から引く** ——
 * 2つ持つと、片方に階を足したときにもう片方が黙って落とす。
 */
function km_app_map_app_floor(string $webFloor): ?string
{
    $flipped = array_flip(KM_APP_MAP_FLOOR_MAP);

    return $flipped[strtolower(trim($webFloor))] ?? null;
}

/**
 * Web に出す名前。アプリの `title` と `subtitle` を繋いだもの。
 *
 * 既存の Web データがその形になっている(`トレーナー室 管-104` = `トレーナー室` + `管-104`)。
 * **氏名の引き継ぎもこの形で突き合わせる**ので、作り方を1箇所に持つ。
 */
function km_app_map_node_name(string $title, string $subtitle): string
{
    $title = trim($title);
    $subtitle = trim($subtitle);

    return $subtitle === '' ? $title : $title . ' ' . $subtitle;
}

/**
 * 2点の距離。`km_map_edges.distance` に入れる。
 *
 * Web の経路探索(`Main/dijkstra.js`)が重みに使う。アプリは線に距離を持たず、
 * 端点の座標から計算している —— **同じ計算をここでもする**(持ち回らない)。
 */
function km_app_map_edge_distance(float $x1, float $y1, float $x2, float $y2): int
{
    return (int) round(sqrt(($x2 - $x1) ** 2 + ($y2 - $y1) ** 2));
}

/**
 * 数として読めるものだけ通す。読めなければ null。
 *
 * **0 と「無い」を混ぜない。** `(int) null` は 0 になるので、
 * 校正値が未設定のノードに 0 が入り、**測位が真面目にその 0 を使う。**
 */
function km_app_map_nullable_int(mixed $value): ?int
{
    return is_numeric($value) ? (int) round((float) $value) : null;
}

/** @see km_app_map_nullable_int */
function km_app_map_nullable_float(mixed $value): ?float
{
    return is_numeric($value) ? round((float) $value, 4) : null;
}

/**
 * アプリの地図を Web の形へ直す。**DB は触らない。**
 *
 * @param stdClass $map `km_app_map_decode_snapshot()` が返したもの
 * @return array{
 *     nodes: array<int, array<string, mixed>>,
 *     edges: array<int, array{from:string,to:string,distance:int}>,
 *     skipped: array{unknownFloor:array<int,string>, unknownType:array<string,int>,
 *                    danglingEdge:int}
 * }
 */
function km_app_map_to_web(stdClass $map): array
{
    $nodes = [];
    $byUuid = [];
    $unknownFloor = [];
    $unknownType = [];

    foreach (($map->nodes ?? []) as $node) {
        if (!($node instanceof stdClass)) {
            continue;
        }

        $type1 = (string) ($node->type1 ?? '');
        if (!in_array($type1, KM_APP_MAP_NODE_TYPES, true)) {
            // **黙って通さない。** 知らない種類を入れると、描画もカテゴリ検索も外れる
            $unknownType[$type1] = ($unknownType[$type1] ?? 0) + 1;
            continue;
        }

        $appFloor = (string) ($node->floor ?? '');
        $floorId = km_app_map_floor_id($appFloor);
        if ($floorId === null) {
            $unknownFloor[] = $appFloor;
            continue;
        }

        $uuid = trim((string) ($node->uuid ?? ''));
        $id = km_app_map_node_id($uuid);
        if ($id === '') {
            continue;
        }

        $occupant = trim((string) ($node->occupantName ?? ''));
        $note = trim((string) ($node->note ?? ''));
        $bssid = trim((string) ($node->bssid ?? ''));
        $transfer = trim((string) ($node->transferGroupId ?? ''));

        $title = trim((string) ($node->title ?? ''));
        $subtitle = trim((string) ($node->subtitle ?? ''));

        $nodes[] = [
            'id' => $id,
            /*
             * **完全な uuid も持つ。** `id` はハイフンを抜いた 32 字で、
             * UUID 以外の文字列(旧 Web の `n_new_10` など)は元に戻せない。
             * 写しだったときは戻す必要が無かったが、いまは配信で使う。
             */
            'uuid' => $uuid,
            'floor_id' => $floorId,
            /*
             * `name` は**組み立てたもの**、`title` / `subtitle` は**元のまま**。
             *
             * 3つとも入れるのは、name だけを見ている画面(公開ページの検索・
             * カテゴリ絞り込み)を壊さずに、番号で並べ替えられるようにするため。
             */
            'name' => km_app_map_node_name($title, $subtitle),
            'title' => $title,
            'subtitle' => $subtitle,
            'occupant_name' => $occupant === '' ? null : $occupant,
            'note' => $note === '' ? null : $note,
            'type' => $type1,
            'type2' => trim((string) ($node->type2 ?? '')),
            'use_wifi' => ($node->useWifi ?? true) ? 1 : 0,
            'bssid' => $bssid === '' ? null : $bssid,
            'tx_power_at_one_meter' => km_app_map_nullable_int($node->txPowerAtOneMeter ?? null),
            'path_loss_exponent' => km_app_map_nullable_float($node->pathLossExponent ?? null),
            'transfer_group_id' => $transfer === '' ? null : $transfer,
            /*
             * **倍率は掛けない。** 見取り図をアプリの画像へ揃える前提なので、
             * x はそのまま。y だけ Leaflet の向きに合わせて反転する。
             *
             * **丸めない。** 列は decimal(10,2) にしてある ——
             * Web が正本になった以上、丸めは往復のたびに積もる位置ずれになる。
             */
            'x' => round((float) ($node->x ?? 0), 2),
            'y' => round(km_app_map_flip_y((float) ($node->y ?? 0), km_app_map_floor_height($floorId)), 2),
            'z' => km_app_map_nullable_float($node->z ?? null),
        ];
        $byUuid[$uuid] = $id;
    }

    /*
     * 線。**両端が残っているものだけ。**
     *
     * 種類で落とすことはもう無いが、階が不明で落ちたノードや、
     * 書き出しが壊れていて端点が見つからない線はある。外部キー
     * (`fk_km_map_edges_from` / `_to`)があるので、そのまま入れると挿入が失敗する。
     * ここで落とし、件数を報告する。
     */
    $edges = [];
    $dangling = 0;
    $seen = [];

    foreach (($map->lines ?? []) as $line) {
        if (!($line instanceof stdClass)) {
            continue;
        }
        $from = $byUuid[(string) ($line->startNodeUuid ?? '')] ?? null;
        $to = $byUuid[(string) ($line->endNodeUuid ?? '')] ?? null;
        if ($from === null || $to === null || $from === $to) {
            $dangling++;
            continue;
        }

        // 向きの違う同じ線を2本入れない(Web の経路探索は無向グラフとして読む)
        $key = $from < $to ? "$from|$to" : "$to|$from";
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $edges[] = [
            'from' => $from,
            'to' => $to,
            /*
             * 距離は反転の影響を受けない(差の絶対値しか使わない)ので、
             * アプリの座標のまま測ってよい。
             */
            'distance' => km_app_map_edge_distance(
                (float) ($line->startX ?? 0),
                (float) ($line->startY ?? 0),
                (float) ($line->endX ?? 0),
                (float) ($line->endY ?? 0)
            ),
        ];
    }

    return [
        'nodes' => $nodes,
        'edges' => $edges,
        'skipped' => [
            'unknownFloor' => array_values(array_unique($unknownFloor)),
            'unknownType' => $unknownType,
            'danglingEdge' => $dangling,
        ],
    ];
}

/**
 * 配信する地図の `version`。**アプリの `MAP_DATA_VERSION` と揃える。**
 *
 * アプリは `require(stored.version in 1..MAP_DATA_VERSION)` で弾く
 * (`MapDataRepository.kt`)。**ここを大きくしすぎると、古い端末が地図を読めなくなる** ——
 * しかも「読めない」としか出ないので、原因が版だと気づけない。
 */
const KM_APP_MAP_DATA_VERSION = 10;

/**
 * Web の行から、アプリの地図(MapDataSnapshot)を組み立てる。**DB は触らない。**
 *
 * [km_app_map_to_web] の逆。**往復して元に戻ることを検査で固定する** ——
 * 戻らないと、配信のたびに少しずつ形が変わっていることに誰も気づけない。
 *
 * `events` はここでは空。Web のイベント重ね合わせは
 * [km_app_map_events_from_web] が作り、[km_app_map_replace_events] が入れる。
 *
 * `evaluations` は**持たない**(端末が出す精度の測定履歴で、地図の内容ではない)。
 * 空配列で出す —— アプリは `stored.evaluations.orEmpty()` で受けるのでキーが無くても
 * 落ちないが、**「無い」と「空」を読む側に判断させない。**
 *
 * @param array<int, array<string, mixed>> $nodeRows km_map_nodes の行
 * @param array<int, array<string, mixed>> $edgeRows km_map_edges の行
 * @param array<int, array<string, mixed>> $fingerprintRows km_map_fingerprints の行
 * @param array<int, array<string, mixed>> $overlayRows km_map_overlays の行
 * @return array{map: stdClass, skipped: array{unknownFloor:array<int,string>,
 *                unknownType:array<string,int>, danglingEdge:int, crossFloorEdge:int}}
 */
function km_app_map_snapshot_from_rows(
    array $nodeRows,
    array $edgeRows,
    array $fingerprintRows = [],
    array $overlayRows = []
): array {
    $nodes = [];
    $byId = [];
    $unknownFloor = [];
    $unknownType = [];

    foreach ($nodeRows as $row) {
        $type = (string) ($row['type'] ?? '');
        if (!in_array($type, KM_APP_MAP_NODE_TYPES, true)) {
            /*
             * **推測で通さない。** 知らない種類のまま配信すると、端末では
             * 色も形も付かないノードになり、原因が種類だと分からない。
             * 移行 SQL を流す前の `point` がここに落ちる。
             */
            $unknownType[$type] = ($unknownType[$type] ?? 0) + 1;
            continue;
        }

        $appFloor = km_app_map_app_floor((string) ($row['floor_id'] ?? ''));
        if ($appFloor === null) {
            $unknownFloor[] = (string) ($row['floor_id'] ?? '');
            continue;
        }

        $uuid = trim((string) ($row['uuid'] ?? ''));
        if ($uuid === '') {
            // 移行 SQL が id を入れているはずの列。空なら**配信に出さない**
            continue;
        }

        $occupant = trim((string) ($row['occupant_name'] ?? ''));
        $note = trim((string) ($row['note'] ?? ''));
        $bssid = trim((string) ($row['bssid'] ?? ''));
        $transfer = trim((string) ($row['transfer_group_id'] ?? ''));

        $node = new stdClass();
        $node->uuid = $uuid;
        $node->x = round((float) ($row['x'] ?? 0), 2);
        // **反転は同じ式で戻る**(高さから引くだけ)。2つ持たない。高さは階ごと(建物の階は 600)
        $node->y = round(km_app_map_flip_y((float) ($row['y'] ?? 0), km_app_map_floor_height($appFloor)), 2);
        $node->z = km_app_map_nullable_float($row['z'] ?? null);
        $node->useWifi = (int) ($row['use_wifi'] ?? 1) === 1;
        $node->title = (string) ($row['title'] ?? '');
        $node->subtitle = (string) ($row['subtitle'] ?? '');
        $node->type1 = $type;
        $node->type2 = (string) ($row['type2'] ?? '');
        $node->note = $note;
        $node->floor = $appFloor;
        $node->bssid = $bssid === '' ? null : $bssid;
        $node->transferGroupId = $transfer === '' ? null : $transfer;
        $node->txPowerAtOneMeter = km_app_map_nullable_int($row['tx_power_at_one_meter'] ?? null);
        $node->pathLossExponent = km_app_map_nullable_float($row['path_loss_exponent'] ?? null);
        $node->occupantName = $occupant === '' ? null : $occupant;

        $nodes[] = $node;
        $byId[(string) ($row['id'] ?? '')] = $node;
    }

    /*
     * 線。**座標は持ち回らず、端点から作り直す。**
     *
     * アプリの `AdMapLine` は端点の座標を複製して持っているが、
     * Web は `from_node_id` / `to_node_id` しか持っていない。
     * ここで写しを作る —— **ノードを動かしたのに線が古い位置のまま、を作らない。**
     */
    $lines = [];
    $dangling = 0;
    $crossFloor = 0;

    foreach ($edgeRows as $row) {
        $from = $byId[(string) ($row['from_node_id'] ?? '')] ?? null;
        $to = $byId[(string) ($row['to_node_id'] ?? '')] ?? null;
        if ($from === null || $to === null) {
            $dangling++;
            continue;
        }
        if ($from->floor !== $to->floor) {
            /*
             * アプリの線は**1つの階に属する**(階をまたぐ移動は
             * `transferGroupId` で表す)。またぐ線を出すと、どちらの階で
             * 描くべきか決められない。
             */
            $crossFloor++;
            continue;
        }

        $line = new stdClass();
        $line->startNodeUuid = $from->uuid;
        $line->endNodeUuid = $to->uuid;
        $line->startX = $from->x;
        $line->startY = $from->y;
        $line->endX = $to->x;
        $line->endY = $to->y;
        $line->floor = $from->floor;
        $lines[] = $line;
    }

    $fingerprints = [];
    foreach ($fingerprintRows as $row) {
        $appFloor = km_app_map_app_floor((string) ($row['floor_id'] ?? ''));
        if ($appFloor === null) {
            $unknownFloor[] = (string) ($row['floor_id'] ?? '');
            continue;
        }
        $rssi = json_decode((string) ($row['rssi_json'] ?? '{}'));
        if (!($rssi instanceof stdClass)) {
            // 中身が読めない指紋は**出さない**。空の指紋は測位を狂わせる
            continue;
        }

        $fingerprint = new stdClass();
        $fingerprint->nodeUuid = (string) ($row['node_uuid'] ?? '');
        $fingerprint->x = round((float) ($row['x'] ?? 0), 2);
        $fingerprint->y = round(km_app_map_flip_y((float) ($row['y'] ?? 0), km_app_map_floor_height($appFloor)), 2);
        $fingerprint->floor = $appFloor;
        $fingerprint->rssiByBssid = $rssi;
        $fingerprint->altitudeMeters = km_app_map_nullable_float($row['altitude_meters'] ?? null);
        $fingerprint->sampleCount = (int) ($row['sample_count'] ?? 1);
        $fingerprint->updatedAtMillis = (int) ($row['updated_at_millis'] ?? 0);
        $fingerprints[] = $fingerprint;
    }

    $overlays = [];
    foreach ($overlayRows as $row) {
        $appFloor = km_app_map_app_floor((string) ($row['floor_id'] ?? ''));
        if ($appFloor === null) {
            $unknownFloor[] = (string) ($row['floor_id'] ?? '');
            continue;
        }

        $overlay = new stdClass();
        $overlay->uuid = (string) ($row['uuid'] ?? '');
        $overlay->imageKey = (string) ($row['image_key'] ?? '');
        $overlay->floor = $appFloor;
        $overlay->x = round((float) ($row['x'] ?? 0), 2);
        $overlay->y = round(km_app_map_flip_y((float) ($row['y'] ?? 0)), 2);
        $overlay->scale = round((float) ($row['scale'] ?? 1), 4);
        $overlay->rotationDegrees = round((float) ($row['rotation_degrees'] ?? 0), 4);
        $overlay->opacity = round((float) ($row['opacity'] ?? 1), 4);
        $overlay->visible = (int) ($row['visible'] ?? 1) === 1;
        $overlay->locked = (int) ($row['locked'] ?? 0) === 1;
        $overlays[] = $overlay;
    }

    $map = new stdClass();
    $map->version = KM_APP_MAP_DATA_VERSION;
    $map->nodes = $nodes;
    $map->lines = $lines;
    $map->fingerprints = $fingerprints;
    $map->evaluations = [];
    $map->overlays = $overlays;
    $map->events = [];

    return [
        'map' => $map,
        'skipped' => [
            'unknownFloor' => array_values(array_unique($unknownFloor)),
            'unknownType' => $unknownType,
            'danglingEdge' => $dangling,
            'crossFloorEdge' => $crossFloor,
        ],
    ];
}

/**
 * DB から配信用の地図を組み立てる。[km_app_map_snapshot_from_rows] に読ませるだけ。
 *
 * **読むのはここ、組み立ては向こう。** 組み立てを純粋関数に置いてあるので、
 * DB 無しで往復を検査できる。
 *
 * @return array{map: stdClass, skipped: array<string, mixed>}
 */
function km_app_map_from_web(PDO $pdo): array
{
    $nodes = $pdo->query(
        'SELECT id, uuid, floor_id, title, subtitle, occupant_name, note, use_wifi,
                type, type2, bssid, tx_power_at_one_meter, path_loss_exponent,
                transfer_group_id, x, y, z
           FROM km_map_nodes'
    )->fetchAll(PDO::FETCH_ASSOC);

    $edges = $pdo->query(
        'SELECT from_node_id, to_node_id FROM km_map_edges'
    )->fetchAll(PDO::FETCH_ASSOC);

    /*
     * 指紋と平面図の配置は**表がまだ無い環境がある**(移行 SQL を流す前)。
     * 無いことを失敗にすると、**配信そのものが止まる。**
     */
    $fingerprints = km_app_map_select_optional(
        $pdo,
        'SELECT node_uuid, floor_id, x, y, rssi_json, altitude_meters,
                sample_count, updated_at_millis
           FROM km_map_fingerprints'
    );
    $overlays = km_app_map_select_optional(
        $pdo,
        'SELECT uuid, image_key, floor_id, x, y, scale, rotation_degrees,
                opacity, visible, locked
           FROM km_map_overlays'
    );

    return km_app_map_snapshot_from_rows($nodes, $edges, $fingerprints, $overlays);
}

/**
 * 表が無ければ空。**「まだ作っていない」と「読めなかった」を分ける。**
 *
 * @return array<int, array<string, mixed>>
 */
function km_app_map_select_optional(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'Base table or view not found')
            || str_contains($exception->getMessage(), '1146')) {
            return [];
        }
        throw $exception;
    }
}

/**
 * 突き合わせに使う項目。**取り込みが書き換える列と同じ顔ぶれ。**
 *
 * ここに挙げ忘れた列は「変わっていない」と数えられる ——
 * **画面には出ないが、適用すると変わる**という一番たちの悪い形になる。
 */
const KM_APP_MAP_COMPARED_COLUMNS = [
    'floor_id', 'name', 'title', 'subtitle', 'occupant_name', 'note', 'use_wifi',
    'type', 'type2', 'bssid', 'tx_power_at_one_meter', 'path_loss_exponent',
    'transfer_group_id', 'x', 'y', 'z',
];

/**
 * 2つの値が同じか。**型の違いで「変わった」と言わない。**
 *
 * DB の `decimal` は PDO が**文字列**で返す(`"514.27"`)。取り込み側は float。
 * `!==` で比べると**全件が「置き換わる」**になり、数字が意味を失う。
 */
function km_app_map_same_value(mixed $a, mixed $b): bool
{
    if ($a === null || $b === null || $a === '' || $b === '') {
        // 「無い」と「空」は同じ扱い。DB は null、書き出しは '' で来ることがある
        return ($a === null || $a === '') && ($b === null || $b === '');
    }
    if (is_numeric($a) && is_numeric($b)) {
        // 桁は列に合わせて丸めてから比べる(decimal(10,2))
        return abs((float) $a - (float) $b) < 0.005;
    }

    return (string) $a === (string) $b;
}

/**
 * いまの Web のノードと突き合わせて、**何が起きるか**を数える。
 *
 * **適用の前に必ずこれを見せる。** 地図の入れ替えは取り返しがつかない。
 *
 * @param array $converted km_app_map_to_web() の戻り
 * @param array<string, array<string, mixed>> $existing 現在の DB の中身(id => 行)
 * @return array{added:int, updated:int, unchanged:int, removed:int, occupantKept:int,
 *               occupantLost:array<int,string>, occupantCleared:array<int,string>}
 */
function km_app_map_diff(array $converted, array $existing): array
{
    $newIds = array_column($converted['nodes'], 'id');
    $newIdSet = array_flip($newIds);

    $added = 0;
    $updated = 0;
    $unchanged = 0;

    /*
     * **「変わらない」を数える。**
     *
     * 以前は「両方に在る = 置き換わる」としていたので、
     * 何も直していない書き出しを上げても「置き換わる地点 685」と出た。
     * **その数字を見ても、何が起きるのか分からない。**
     */
    foreach ($converted['nodes'] as $node) {
        $before = $existing[$node['id']] ?? null;
        if ($before === null) {
            $added++;
            continue;
        }

        $same = true;
        foreach (KM_APP_MAP_COMPARED_COLUMNS as $column) {
            // DB にまだ無い列は比べない(移行 SQL を流す前の環境)
            if (!array_key_exists($column, $before)) {
                continue;
            }
            if (!km_app_map_same_value($before[$column], $node[$column] ?? null)) {
                $same = false;
                break;
            }
        }
        if ($same) {
            $unchanged++;
        } else {
            $updated++;
        }
    }

    $removed = 0;
    foreach ($existing as $id => $_row) {
        if (!isset($newIdSet[$id])) {
            $removed++;
        }
    }

    /** 上げた地図が持っている氏名の件数。 */
    $occupantKept = 0;
    foreach ($converted['nodes'] as $node) {
        if (($node['occupant_name'] ?? null) !== null) {
            $occupantKept++;
        }
    }

    /*
     * **上書きで消える氏名を、消える前に数える。**
     *
     * 取り込みは同じ ID の行を丸ごと置き換える。**氏名も置き換わる。**
     * 上げた地図に氏名が入っていなければ(来場者向けに氏名を抜いた書き出し、
     * 氏名を移す前の古い書き出し)、**その地点の氏名は黙って消える。**
     *
     * 消えるのは「無いものを消す」を選んだときだけではない ——
     * **残す設定でも、上書きされる分は消える。** ここを数えないと、
     * 画面は「置き換わる地点 685」としか言わず、氏名が消えたことは
     * 誰も気づかないまま配信まで進む。
     */
    $byId = [];
    foreach ($converted['nodes'] as $node) {
        $byId[$node['id']] = $node;
    }

    $occupantCleared = [];
    $occupantLost = [];
    foreach ($existing as $id => $row) {
        if (($row['occupant_name'] ?? null) === null) {
            continue;
        }
        if (!isset($newIdSet[$id])) {
            // 上げた地図に無い地点。**「消す」を選んだときだけ失われる**
            $occupantLost[] = (string) $row['name'];
            continue;
        }
        if (($byId[$id]['occupant_name'] ?? null) === null) {
            // 上げた地図にはあるが、氏名が入っていない。**上書きで消える**
            $occupantCleared[] = (string) $row['name'];
        }
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'removed' => $removed,
        'occupantKept' => $occupantKept,
        'occupantLost' => $occupantLost,
        'occupantCleared' => $occupantCleared,
    ];
}

// ------------------------------------------------- Web のイベント → アプリの地図
//
// **ここだけ向きが逆になる。** 地図(ノード・線・指紋)はアプリが正本だが、
// イベント(会期・通行止め・臨時の地点)は **Website の管理画面が正本**
// (2026-09-02 に利用者が決めた分担)。
//
// 配信のたびに Web のイベントを地図へ差し込む。**配信時に1回だけ**なので、
// 配信の経路(api/app-map.php)に DB がぶら下がらない ——
// あそこが DB に依存すると、**DB が落ちた日に地図そのものが配れなくなる。**

/** 臨時の地点の種別。Web の値 → アプリで表示する言葉(ノードの種類2)。 */
const KM_APP_MAP_POI_CATEGORY_LABELS = [
    'food' => '模擬店',
    'exhibit' => '展示',
    'reception' => '受付',
    'firstaid' => '救護',
    'toilet' => 'トイレ',
    'stage' => 'ステージ',
    'other' => '',
];

/** 差し込んだイベントの UUID。**固定でよい。** 中身が変わっても指し先は同じ。 */
const KM_APP_MAP_WEB_EVENT_UUID = 'web-overlay';

/**
 * `km_map_event_overlay()` の重ね合わせを、アプリの `MapEvent` 1件へ変換する。
 *
 * ## なぜ1件にまとめるのか
 *
 * Website は**同時に複数のイベントを重ねられる**(工事の通行止めの最中に高専祭が来る)。
 * 一方アプリが適用するのは `activeEventUuid` の**1件だけ**。
 * 重ね合わせた結果がまさに「いま効いている規制」なので、それを1件として渡す。
 *
 * ## 会期の終わりは「一番早いもの」に合わせる
 *
 * まとめると、個々のイベントの会期は失われる。**遅い方に合わせない** ——
 * 終わった通行止めが残り続けると、通れる道を「通れません」と言い続けることになる。
 * 早い方で切れば、**その時点でこの重ね合わせは古い**という意味になり、
 * 管理画面の配信欄に「会期は終了しています」と出る(そこで配信し直す)。
 *
 * 終わりが決まっていないイベント(長期の工事)しか無ければ、期限なしにする。
 *
 * ## 対応づけられなかったものは数えて返す
 *
 * **黙って落とさない。** ノードを作り直すと Web 側の通行止めの指定が静かに外れる。
 *
 * @param array $overlay km_map_event_overlay() の戻り値
 * @param stdClass $map 配信する地図(アプリの MapDataSnapshot)
 * @return array{events:array<int,array>, activeEventUuid:?string, skipped:array}
 */
function km_app_map_events_from_web(array $overlay, stdClass $map): array
{
    $skipped = [
        'unknownNode' => 0,
        'edgeAcrossFloors' => 0,
        'unknownFloor' => [],
    ];

    $webEvents = is_array($overlay['events'] ?? null) ? $overlay['events'] : [];
    if ($webEvents === []) {
        return ['events' => [], 'activeEventUuid' => null, 'skipped' => $skipped];
    }

    /*
     * 32文字の ID から UUID へ戻す対応表。**地図そのものから作る。**
     * ハイフンの位置で機械的に戻す手もあるが、それでは
     * 「その UUID が本当にこの地図に在るか」が分からない。
     */
    $byShortId = [];
    foreach (($map->nodes ?? []) as $node) {
        if (!($node instanceof stdClass)) {
            continue;
        }
        $uuid = (string) ($node->uuid ?? '');
        if ($uuid === '') {
            continue;
        }
        $byShortId[km_app_map_node_id($uuid)] = [
            'uuid' => $uuid,
            'floor' => (string) ($node->floor ?? ''),
        ];
    }

    // --- 通行止め(ノード) ---
    $closedNodeUuids = [];
    foreach (array_keys($overlay['closedNodes'] ?? []) as $shortId) {
        $found = $byShortId[(string) $shortId] ?? null;
        if ($found === null) {
            $skipped['unknownNode']++;
            continue;
        }
        $closedNodeUuids[$found['uuid']] = true;
    }

    /*
     * --- 通行止め(線) ---
     *
     * overlay は**両方向を持っている**(向きが保証されないため、あちらは
     * わざと2本入れている)。アプリのキーは無向なので畳む。
     *
     * **数える前に畳む。** 畳まずに数えると、対応づけられなかった線が
     * すべて2件に見える —— 「2本落ちた」と報告されて、実際は1本という形になる。
     */
    $edgePairs = [];
    foreach (array_keys($overlay['closedEdges'] ?? []) as $edgeKey) {
        $parts = explode("\t", (string) $edgeKey);
        if (count($parts) !== 2) {
            continue;
        }
        sort($parts);
        $edgePairs[$parts[0] . "\t" . $parts[1]] = $parts;
    }

    $closedLineKeys = [];
    foreach ($edgePairs as $parts) {
        $from = $byShortId[$parts[0]] ?? null;
        $to = $byShortId[$parts[1]] ?? null;
        if ($from === null || $to === null) {
            $skipped['unknownNode']++;
            continue;
        }
        if ($from['floor'] !== $to['floor']) {
            // 階をまたぐ線はアプリのキーに階が1つしか入らない。**作らずに数える**
            $skipped['edgeAcrossFloors']++;
            continue;
        }
        $endpoints = [$from['uuid'], $to['uuid']];
        sort($endpoints);
        $closedLineKeys[$from['floor'] . ':' . $endpoints[0] . ':' . $endpoints[1]] = true;
    }

    // --- 臨時の地点 ---
    $places = [];
    foreach (($overlay['pois'] ?? []) as $poi) {
        if (!is_array($poi)) {
            continue;
        }
        $appFloor = km_app_map_app_floor((string) ($poi['floor'] ?? ''));
        if ($appFloor === null) {
            $skipped['unknownFloor'][] = (string) ($poi['floor'] ?? '');
            continue;
        }
        $category = (string) ($poi['category'] ?? 'other');
        $places[] = [
            // ノードの UUID とぶつからない形にする。ぶつかると描画も経路も壊れる
            'uuid' => 'web-poi-' . (int) ($poi['id'] ?? 0),
            'name' => (string) ($poi['name'] ?? ''),
            'floor' => $appFloor,
            'x' => (float) ($poi['x'] ?? 0),
            // Web → アプリも同じ反転(この関数は自分自身が逆関数)
            'y' => km_app_map_flip_y((float) ($poi['y'] ?? 0), km_app_map_floor_height($appFloor)),
            'category' => KM_APP_MAP_POI_CATEGORY_LABELS[$category] ?? '',
            'note' => (string) ($poi['note'] ?? ''),
            // Website 側に「スタッフ限定」の列が無い。**勝手に隠さない**
            'staffOnly' => false,
        ];
    }

    // --- 会期 ---
    $endAtMillis = null;
    foreach ($webEvents as $event) {
        $endsAt = $event['endsAt'] ?? null;
        if (!is_string($endsAt) || trim($endsAt) === '') {
            continue;
        }
        $parsed = strtotime($endsAt);
        if ($parsed === false) {
            continue;
        }
        $millis = $parsed * 1000;
        if ($endAtMillis === null || $millis < $endAtMillis) {
            $endAtMillis = $millis;
        }
    }

    $names = [];
    foreach ($webEvents as $event) {
        $name = trim((string) ($event['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    $appEvent = [
        'uuid' => KM_APP_MAP_WEB_EVENT_UUID,
        'name' => $names === [] ? 'イベント' : implode(' / ', $names),
        'note' => '',
        // 開始は入れない。**Website 側で「いま有効」と判定されたものだけが来る**ので、
        // 開始を入れると同じ判定を2度することになり、時計のずれで食い違う
        'startAtMillis' => null,
        'endAtMillis' => $endAtMillis,
        'closedLineKeys' => array_keys($closedLineKeys),
        'closedNodeUuids' => array_keys($closedNodeUuids),
        // Website 側に「階ごとの立入禁止」が無い。線とノードで表す
        'closedFloors' => [],
        'places' => $places,
    ];

    return [
        'events' => [$appEvent],
        'activeEventUuid' => KM_APP_MAP_WEB_EVENT_UUID,
        'skipped' => $skipped,
    ];
}

/**
 * 変換したイベントを地図へ差し込む。**引数を書き換える。**
 *
 * アプリ側で作ったイベントは**残さない**。混ざると、どちらが効いているのか
 * 分からなくなる —— イベントは Website が正本だと決めたので、そこで揃える。
 * (アプリの編集画面は残してある。会場で1つ足したい場面のため)
 */
function km_app_map_replace_events(stdClass $map, array $events): void
{
    $map->events = $events;
}
