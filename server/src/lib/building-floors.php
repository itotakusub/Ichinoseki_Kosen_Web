<?php

declare(strict_types=1);

/**
 * 建物の階(2026-09-24、利用者の指示)。**平面図 1 枚を 1 つの階として扱う。**
 *
 * ## 何が変わったか
 *
 * これまで建物の平面図は、**外全体図の上に位置・大きさ・角度を指定して重ねる**ものだった
 * (`km_map_overlays` と、アプリの `AdMapOverlay`)。重ねる方式には次の弱点がある:
 *
 *   - 建物の中に地点を置けない。全体図の座標に縛られ、部屋を打てる広さが無い
 *   - 位置合わせが難しい。ずれると中の部屋と建物の輪郭が合わない
 *   - 拡大しないと出ない。屋外の倍率に縛られる
 *
 * **1 枚を 1 つの階にすると、ふつうの階と同じ扱いになる** —— 地点を置け、経路が通り、
 * 教職員の担当地点にもでき、アプリと Website で同じものが出る。
 *
 * ## 対応づけは 1 箇所
 *
 * ここと、アプリの `BuildingFloorCatalog.kt` が対。**片方だけ足すと、
 * 一方で開けない階ができる**ので、必ず両方を直すこと(`check.php` の `building-floors` が数を突き合わせる)。
 *
 * - `id`        … `km_map_floors.id`(**16 文字まで**。列の幅)
 * - `appFloor`  … アプリ側の階の値(`AdMapNode.floor`)。id を大文字にしたもの
 * - `image`     … `Main/` から見た画像の場所。**アプリの drawable と同じ絵**
 * - `keywords`  … 屋外の施設(建物名)と結び付ける手がかり。「中へ」を出すのに使う
 */

/** 画像の大きさ。全部 600x600(実測 2026-09-24)。座標系もこれに合わせる。 */
const KM_BUILDING_FLOOR_SIZE = 600;

/** 階の並び。屋外(0)と 1〜5F(1〜5)の後ろへ置く。 */
const KM_BUILDING_FLOOR_SORT_BASE = 10;

const KM_BUILDING_FLOORS = [
    [
        'id' => 'bldg_library_1f',
        'label' => '図書館 1F',
        'image' => 'Picture/bldg/bldg_library_1f.png',
        'appFloor' => 'BLDG_LIBRARY_1F',
        'keywords' => ['図書館', 'メディアセンター'],
    ],
    [
        'id' => 'bldg_library_2f',
        'label' => '図書館 2F',
        'image' => 'Picture/bldg/bldg_library_2f.png',
        'appFloor' => 'BLDG_LIBRARY_2F',
        'keywords' => ['図書館', 'メディアセンター'],
    ],
    [
        'id' => 'bldg_hagitomo_1f',
        'label' => '萩友会館 1F',
        'image' => 'Picture/bldg/bldg_hagitomo_1f.png',
        'appFloor' => 'BLDG_HAGITOMO_1F',
        'keywords' => ['萩友会館', '食堂'],
    ],
    [
        'id' => 'bldg_hagitomo_2f',
        'label' => '萩友会館 2F',
        'image' => 'Picture/bldg/bldg_hagitomo_2f.png',
        'appFloor' => 'BLDG_HAGITOMO_2F',
        'keywords' => ['萩友会館', '食堂'],
    ],
    [
        'id' => 'bldg_techno_1f',
        'label' => '地域共同テクノセンター 1F',
        'image' => 'Picture/bldg/bldg_techno_center_1f.png',
        'appFloor' => 'BLDG_TECHNO_1F',
        'keywords' => ['テクノセンター'],
    ],
    [
        'id' => 'bldg_techno_2f',
        'label' => '地域共同テクノセンター 2F',
        'image' => 'Picture/bldg/bldg_techno_center_2f.png',
        'appFloor' => 'BLDG_TECHNO_2F',
        'keywords' => ['テクノセンター'],
    ],
    [
        'id' => 'bldg_gym1',
        'label' => '第一体育館',
        'image' => 'Picture/bldg/bldg_gym1.png',
        'appFloor' => 'BLDG_GYM1',
        'keywords' => ['第一体育館'],
    ],
    [
        'id' => 'bldg_gym2',
        'label' => '第二体育館',
        'image' => 'Picture/bldg/bldg_gym2.png',
        'appFloor' => 'BLDG_GYM2',
        'keywords' => ['第二体育館'],
    ],
    [
        'id' => 'bldg_budokan',
        'label' => '武道館',
        'image' => 'Picture/bldg/bldg_budokan.png',
        'appFloor' => 'BLDG_BUDOKAN',
        'keywords' => ['武道館'],
    ],
    [
        'id' => 'bldg_machine',
        'label' => '機械実習工場',
        'image' => 'Picture/bldg/bldg_machine_shop.png',
        'appFloor' => 'BLDG_MACHINE',
        'keywords' => ['機械実習工場'],
    ],
    [
        'id' => 'bldg_chem_shop',
        'label' => '化学工学実習工場',
        'image' => 'Picture/bldg/bldg_chem_shop.png',
        'appFloor' => 'BLDG_CHEM_SHOP',
        'keywords' => ['化学工学実習工場', 'ミライチ'],
    ],
];

/** 建物の階の id か。ふつうの階(`1`〜`5`・`outside`)と見分ける。 */
function km_building_floor_is(string $floorId): bool
{
    foreach (KM_BUILDING_FLOORS as $floor) {
        if ($floor['id'] === $floorId) {
            return true;
        }
    }

    return false;
}

/** @return array{id:string, label:string, image:string, appFloor:string, keywords:array<int,string>}|null */
function km_building_floor(string $floorId): ?array
{
    foreach (KM_BUILDING_FLOORS as $floor) {
        if ($floor['id'] === $floorId) {
            return $floor;
        }
    }

    return null;
}

/**
 * 施設(建物名)に結び付く建物の階。**地点が 1 つも無くても出せる** ——
 * 中をこれから作る建物でも「中へ」を出したいので、名前の手がかりで結ぶ。
 *
 * @return array<int, array{id:string, label:string}>
 */
function km_building_floors_for_facility(string $facilityName): array
{
    $name = km_building_floor_normalize($facilityName);
    if ($name === '') {
        return [];
    }

    $found = [];
    foreach (KM_BUILDING_FLOORS as $floor) {
        foreach ($floor['keywords'] as $keyword) {
            if (str_contains($name, km_building_floor_normalize($keyword))) {
                $found[] = ['id' => $floor['id'], 'label' => $floor['label']];
                break;
            }
        }
    }

    return $found;
}

/** 空白を落として小文字に(「第一体育館」と「第一 体育館」を同じものとして照らす)。 */
function km_building_floor_normalize(string $value): string
{
    return strtolower((string) preg_replace('/[\s\x{3000}]+/u', '', trim($value)));
}

/**
 * `km_map_floors` に建物の階を用意する(何度流してもよい)。
 *
 * **`CREATE` と `INSERT` だけで済ませる。** アプリの DB 利用者には `ALTER` を与えていない
 * (lib/map-data.php の座標系の表と同じ理由)。座標系は画像と同じ 600x600 で入れる。
 *
 * @return array{added:int, bounds:int}
 */
function km_building_floors_ensure(PDO $pdo): array
{
    require_once __DIR__ . '/db.php';

    $added = 0;
    $bounds = 0;
    $insertFloor = $pdo->prepare(
        'INSERT IGNORE INTO km_map_floors (id, label, svg_path, sort_order) VALUES (?, ?, ?, ?)'
    );

    // 座標系の表は lib/map-data.php と同じもの。無ければ作る(CREATE だけで足りる)
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_floor_bounds (
            floor_id VARCHAR(16) NOT NULL,
            coord_width INT NOT NULL,
            coord_height INT NOT NULL,
            PRIMARY KEY (floor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $insertBounds = $pdo->prepare(
        'INSERT IGNORE INTO km_map_floor_bounds (floor_id, coord_width, coord_height) VALUES (?, ?, ?)'
    );

    foreach (KM_BUILDING_FLOORS as $index => $floor) {
        $insertFloor->execute([
            $floor['id'],
            $floor['label'],
            $floor['image'],
            KM_BUILDING_FLOOR_SORT_BASE + $index,
        ]);
        $added += $insertFloor->rowCount();
        $insertBounds->execute([$floor['id'], KM_BUILDING_FLOOR_SIZE, KM_BUILDING_FLOOR_SIZE]);
        $bounds += $insertBounds->rowCount();
    }

    return ['added' => $added, 'bounds' => $bounds];
}
