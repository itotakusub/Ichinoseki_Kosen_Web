<?php

declare(strict_types=1);

/**
 * 階と平面図の管理(2026-10-21、利用者の指示「地図画像の差し替え、階や内部の地図の差し替え・追加・削除」)。
 * 画面は admin/map-floors.php。
 *
 * ## 階は 2 種類
 *
 * - **校舎の階**(`campus`): 外・1F〜5F と、Website で足した階。公開ページの右の階の並びに出る
 * - **建物の中**(`building`): 平面図 1 枚が 1 つの階(lib/building-floors.php)。屋外の建物を押すと「中へ」で開く。
 *   Website で足した建物の中は、名前の手がかり(keywords)で屋外の建物と結ぶ
 *
 * 組み込みの階(外・1F〜5F・KM_BUILDING_FLOORS)はアプリにも同じ絵がある。Website で足した階はアプリには無い
 * (アプリへの配信では、その階の地点は「知らない階」として数えて落とす。lib/app-map-publish.php)。
 *
 * ## 差し替えた画像は uploads/ に置く
 *
 * 本番の src は**読み取り専用**で web に渡している(compose.yaml)うえ、配備のたびに上書きされる。
 * 書けて配備に消されないのは `uploads/` だけ(nginx が直の配信を塞いである)。
 * `km_map_floors.svg_path` に `upload:floor_<32 桁>.<拡張子>` と入れ、api/floor-image.php がそこから配る。
 * 組み込みの画像(`Picture/…`)は消さずに残るので、「元の画像に戻す」ができる。
 *
 * ## 座標の範囲は変えない(差し替え)
 *
 * 地点の座標は `km_map_floor_bounds` の範囲の中の値。**画像を差し替えても範囲はそのまま**にし、画像を範囲に合わせて伸ばす。
 * 縦横の比が違うと絵が歪むので、画面で知らせる(km_map_floor_aspect_differs)。新しく足す階は画像の大きさを範囲にする。
 *
 * ## 表
 *
 * `km_map_floors` には列を足さない(アプリの DB 利用者に ALTER が無い)。Website で足した階の種類と手がかりは別表
 * `km_map_floor_custom`。管理画面を開いたときに作る。公開ページは表が無ければ「足した階は無い」として素通りする。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/raster-image.php';
require_once __DIR__ . '/building-floors.php';

/** 差し替え・追加の画像の上限。nginx の /admin/ は 12m */
const KM_MAP_FLOOR_MAX_BYTES = 8 * 1024 * 1024;
const KM_MAP_FLOOR_MAX_DIMENSION = 4096;
/** zip でまとめて差し替えるとき */
const KM_MAP_FLOOR_ZIP_MAX_ENTRIES = 40;
const KM_MAP_FLOOR_ZIP_MAX_TOTAL = 64 * 1024 * 1024;
/** 差し替えた画像の svg_path の印 */
const KM_MAP_FLOOR_UPLOAD_PREFIX = 'upload:';
/** 名前の長さ(画面と DB の列に収める) */
const KM_MAP_FLOOR_LABEL_MAX = 32;
/** 公開ページの最初に開く階(Main/app.js の window.currentFloor)。**消させない** */
const KM_MAP_FLOOR_INITIAL = '1';

/**
 * 組み込みの校舎の階。**元の画像に戻す・消した階を戻す**ときの正本(`rail` は公開ページの階の並びの文字)。
 * 座標の範囲はアプリの画像と同じ(lib/app-map-convert.php の KM_APP_MAP_IMAGE_WIDTH / HEIGHT)。
 */
const KM_MAP_CAMPUS_FLOORS = [
    ['id' => 'outside', 'label' => '外', 'title' => '外全体図', 'image' => 'Picture/外全体図.png', 'sort' => 0],
    ['id' => '1', 'label' => '1F', 'title' => '1階', 'image' => 'Picture/１階.png', 'sort' => 1],
    ['id' => '2', 'label' => '2F', 'title' => '2階', 'image' => 'Picture/２階.png', 'sort' => 2],
    ['id' => '3', 'label' => '3F', 'title' => '3階', 'image' => 'Picture/３階.png', 'sort' => 3],
    ['id' => '4', 'label' => '4F', 'title' => '4階', 'image' => 'Picture/４階.png', 'sort' => 4],
    ['id' => '5', 'label' => '5F', 'title' => '5階', 'image' => 'Picture/５階.png', 'sort' => 5],
];

function km_map_floors_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_floor_custom (
            floor_id VARCHAR(16) NOT NULL PRIMARY KEY,
            kind VARCHAR(16) NOT NULL,
            keywords VARCHAR(255) NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_floor_bounds (
            floor_id VARCHAR(16) NOT NULL,
            coord_width INT NOT NULL,
            coord_height INT NOT NULL,
            PRIMARY KEY (floor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

/**
 * 組み込みの階か。そうなら種類・元の画像・既定の名前と並び・座標の範囲を返す。
 *
 * @return array{kind:string, image:string, label:string, sort:int, width:int, height:int}|null
 */
function km_map_floor_builtin(string $floorId): ?array
{
    foreach (KM_MAP_CAMPUS_FLOORS as $floor) {
        if ($floor['id'] === $floorId) {
            return ['kind' => 'campus', 'image' => $floor['image'], 'label' => $floor['label'], 'sort' => $floor['sort'], 'width' => 1600, 'height' => 1200];
        }
    }
    foreach (KM_BUILDING_FLOORS as $index => $floor) {
        if ($floor['id'] === $floorId) {
            return [
                'kind' => 'building', 'image' => $floor['image'], 'label' => $floor['label'],
                'sort' => KM_BUILDING_FLOOR_SORT_BASE + $index, 'width' => KM_BUILDING_FLOOR_SIZE, 'height' => KM_BUILDING_FLOOR_SIZE,
            ];
        }
    }

    return null;
}

/**
 * Website で足した階(種類と手がかり)。**表を作らずに読む**(公開ページの経路)。
 *
 * @return array<string, array{kind:string, keywords:array<int,string>}>
 */
function km_map_floor_custom_rows(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT floor_id AS floorId, kind, keywords FROM km_map_floor_custom')->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
    $custom = [];
    foreach ($rows as $row) {
        $custom[(string) $row['floorId']] = [
            'kind' => (string) $row['kind'] === 'building' ? 'building' : 'campus',
            'keywords' => km_map_floor_keywords((string) ($row['keywords'] ?? '')),
        ];
    }

    return $custom;
}

/** 階の種類。組み込みの建物の階か、足した建物の中なら building。それ以外は campus */
function km_map_floor_kind(string $floorId, array $custom): string
{
    if (km_building_floor_is($floorId)) {
        return 'building';
    }

    return ($custom[$floorId]['kind'] ?? 'campus') === 'building' ? 'building' : 'campus';
}

/** 手がかりの文字(「図書館、メディアセンター」)を語の並びへ */
function km_map_floor_keywords(string $text): array
{
    $words = preg_split('/[\s,、,]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique(array_map(static fn (string $w): string => mb_substr($w, 0, 40), $words)));
}

/** 階の id の形。**小文字・数字・_ だけ、16 字まで**(列の幅。URL と HTML の属性にそのまま入る) */
function km_map_floor_id_valid(string $floorId): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_]{0,15}$/', $floorId) === 1;
}

/** svg_path が差し替えた画像か */
function km_map_floor_is_upload(string $svgPath): bool
{
    return str_starts_with($svgPath, KM_MAP_FLOOR_UPLOAD_PREFIX);
}

/** 差し替えた画像の保存名の形。**自分で採番した形しか通さない**(DB が汚れていてもパスをはみ出させない) */
function km_map_floor_upload_name_valid(string $storedName): bool
{
    return preg_match('/^floor_[0-9a-f]{32}\.(png|jpg|gif|webp)$/', $storedName) === 1;
}

/**
 * svg_path から実際のファイル。組み込みは `Main/` の下、差し替えは `uploads/` の下。**外へ出るものは null。**
 */
function km_map_floor_image_file(string $svgPath): ?string
{
    if (km_map_floor_is_upload($svgPath)) {
        $name = substr($svgPath, strlen(KM_MAP_FLOOR_UPLOAD_PREFIX));
        if (!km_map_floor_upload_name_valid($name)) {
            return null;
        }
        $path = km_upload_dir() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? $path : null;
    }

    $relative = ltrim(str_replace('\\', '/', $svgPath), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }
    $base = realpath(__DIR__ . '/../Main');
    $path = realpath(__DIR__ . '/../Main/' . $relative);
    if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

/** 縦横の比が 2% を超えて違うか(画像を範囲に合わせて伸ばすと歪む) */
function km_map_floor_aspect_differs(int $width, int $height, int $coordWidth, int $coordHeight): bool
{
    if ($width <= 0 || $height <= 0 || $coordWidth <= 0 || $coordHeight <= 0) {
        return false;
    }

    return abs(($width / $height) / ($coordWidth / $coordHeight) - 1) > 0.02;
}

/**
 * 管理画面の一覧。並び順どおり。
 *
 * @return array<int, array<string, mixed>>
 */
function km_map_floors_admin(PDO $pdo): array
{
    km_map_floors_ensure_tables($pdo);
    $custom = km_map_floor_custom_rows($pdo);

    $bounds = [];
    foreach ($pdo->query('SELECT floor_id, coord_width, coord_height FROM km_map_floor_bounds')->fetchAll() as $row) {
        $bounds[(string) $row['floor_id']] = [(int) $row['coord_width'], (int) $row['coord_height']];
    }
    $counts = [];
    foreach ($pdo->query('SELECT floor_id, COUNT(*) AS n FROM km_map_nodes GROUP BY floor_id')->fetchAll() as $row) {
        $counts[(string) $row['floor_id']] = (int) $row['n'];
    }

    $floors = [];
    foreach ($pdo->query('SELECT id, label, svg_path, sort_order FROM km_map_floors ORDER BY sort_order, id')->fetchAll() as $row) {
        $id = (string) $row['id'];
        $svgPath = (string) $row['svg_path'];
        $builtin = km_map_floor_builtin($id);
        $file = km_map_floor_image_file($svgPath);
        $size = $file !== null ? @getimagesize($file) : false;
        $floors[] = [
            'id' => $id,
            'label' => (string) $row['label'],
            'svgPath' => $svgPath,
            'sortOrder' => (int) $row['sort_order'],
            'kind' => km_map_floor_kind($id, $custom),
            'builtin' => $builtin !== null,
            'uploaded' => km_map_floor_is_upload($svgPath),
            'imageMissing' => $file === null,
            'imageWidth' => is_array($size) ? (int) $size[0] : 0,
            'imageHeight' => is_array($size) ? (int) $size[1] : 0,
            'imageVersion' => $file !== null ? (int) @filemtime($file) : 0,
            'coordWidth' => $bounds[$id][0] ?? 0,
            'coordHeight' => $bounds[$id][1] ?? 0,
            'nodeCount' => $counts[$id] ?? 0,
            'keywords' => $custom[$id]['keywords'] ?? ($builtin !== null && $builtin['kind'] === 'building' ? (km_building_floor($id)['keywords'] ?? []) : []),
            'custom' => isset($custom[$id]),
        ];
    }

    return $floors;
}

/** 1 件(一覧から引く) */
function km_map_floor_find(PDO $pdo, string $floorId): ?array
{
    foreach (km_map_floors_admin($pdo) as $floor) {
        if ($floor['id'] === $floorId) {
            return $floor;
        }
    }

    return null;
}

/** 画像を確かめて uploads/ に置く。@return array{storedName:string, width:int, height:int} */
function km_map_floor_store_image(string $bytes): array
{
    $checked = km_raster_image_check($bytes, KM_MAP_FLOOR_MAX_BYTES, KM_MAP_FLOOR_MAX_DIMENSION);
    $dir = km_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('保存先ディレクトリを作成できませんでした。');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('保存先ディレクトリに書き込めません。権限を確認してください。');
    }
    $storedName = 'floor_' . bin2hex(random_bytes(16)) . '.' . $checked['ext'];
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . $storedName, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($dir . DIRECTORY_SEPARATOR . $storedName);
        throw new RuntimeException('画像を保存できませんでした。');
    }

    return ['storedName' => $storedName, 'width' => $checked['width'], 'height' => $checked['height']];
}

/** 差し替えた画像のファイルを片付ける(組み込みの画像は消さない) */
function km_map_floor_remove_upload(string $svgPath): void
{
    if (!km_map_floor_is_upload($svgPath)) {
        return;
    }
    $file = km_map_floor_image_file($svgPath);
    if ($file !== null && !@unlink($file)) {
        error_log('km_map_floor_remove_upload: file remains on disk: ' . $svgPath);
    }
}

/** 選ばれたファイル($_FILES の 1 件)の中身。**本当にアップロードされたものか**を確かめてから読む */
function km_map_floor_read_upload(array $file, string $what = '画像'): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException($what . 'のファイルが選ばれていません。');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException($what . 'が大きすぎます。');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('アップロードに失敗しました(コード ' . $error . ')。');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('アップロードされたファイルを確認できませんでした。');
    }
    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        throw new RuntimeException('アップロードされたファイルを読めませんでした。');
    }

    return $bytes;
}

/**
 * 画像を差し替える。**座標の範囲はそのまま**(地点の位置を変えない)。
 *
 * @return array{width:int, height:int, aspectDiffers:bool}
 */
function km_map_floor_replace_image(PDO $pdo, string $floorId, string $bytes): array
{
    $floor = km_map_floor_find($pdo, $floorId);
    if ($floor === null) {
        throw new InvalidArgumentException('階が見つかりません(' . $floorId . ')。');
    }
    $stored = km_map_floor_store_image($bytes);
    try {
        $pdo->prepare('UPDATE km_map_floors SET svg_path = ? WHERE id = ?')
            ->execute([KM_MAP_FLOOR_UPLOAD_PREFIX . $stored['storedName'], $floorId]);
        // 範囲がまだ無い階(古い DB)は、画像の大きさで入れる
        if ($floor['coordWidth'] <= 0 || $floor['coordHeight'] <= 0) {
            km_map_floor_set_bounds($pdo, $floorId, $stored['width'], $stored['height']);
        }
    } catch (Throwable $exception) {
        km_map_floor_remove_upload(KM_MAP_FLOOR_UPLOAD_PREFIX . $stored['storedName']);
        throw $exception;
    }
    km_map_floor_remove_upload((string) $floor['svgPath']);

    return [
        'width' => $stored['width'],
        'height' => $stored['height'],
        'aspectDiffers' => km_map_floor_aspect_differs($stored['width'], $stored['height'], (int) $floor['coordWidth'], (int) $floor['coordHeight']),
    ];
}

/** 組み込みの階を元の画像に戻す */
function km_map_floor_restore_image(PDO $pdo, string $floorId): void
{
    $builtin = km_map_floor_builtin($floorId);
    $floor = km_map_floor_find($pdo, $floorId);
    if ($builtin === null || $floor === null) {
        throw new InvalidArgumentException('元の画像があるのは組み込みの階だけです。');
    }
    if (km_map_floor_image_file($builtin['image']) === null) {
        throw new InvalidArgumentException('元の画像が見つかりません(' . $builtin['image'] . ')。');
    }
    $pdo->prepare('UPDATE km_map_floors SET svg_path = ? WHERE id = ?')->execute([$builtin['image'], $floorId]);
    km_map_floor_remove_upload((string) $floor['svgPath']);
}

function km_map_floor_set_bounds(PDO $pdo, string $floorId, int $width, int $height): void
{
    $pdo->prepare(
        'INSERT INTO km_map_floor_bounds (floor_id, coord_width, coord_height) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE coord_width = VALUES(coord_width), coord_height = VALUES(coord_height)'
    )->execute([$floorId, $width, $height]);
}

/**
 * 名前・並び順・手がかりを確かめる。**DB を使わない**(scripts/check.php が試す)。
 *
 * @return array{label:string, sortOrder:int, keywords:array<int,string>}
 */
function km_map_floor_validate(string $label, string $sortOrder, string $keywords): array
{
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('階の名前を入れてください(例: 6F・図書館 3F)。');
    }
    if (mb_strlen($label) > KM_MAP_FLOOR_LABEL_MAX) {
        throw new InvalidArgumentException('階の名前は ' . KM_MAP_FLOOR_LABEL_MAX . ' 字までにしてください。');
    }
    if (preg_match('/^-?\d{1,4}$/', trim($sortOrder)) !== 1) {
        throw new InvalidArgumentException('並び順は整数にしてください(小さいほど前)。');
    }
    $words = km_map_floor_keywords($keywords);
    if (mb_strlen(implode('、', $words)) > 255) {
        throw new InvalidArgumentException('建物の手がかりが長すぎます。');
    }

    return ['label' => $label, 'sortOrder' => (int) trim($sortOrder), 'keywords' => $words];
}

/** 名前・並び順・(足した建物の中なら)手がかりを変える */
function km_map_floor_update(PDO $pdo, string $floorId, array $fields): void
{
    $floor = km_map_floor_find($pdo, $floorId);
    if ($floor === null) {
        throw new InvalidArgumentException('階が見つかりません(' . $floorId . ')。');
    }
    $pdo->prepare('UPDATE km_map_floors SET label = ?, sort_order = ? WHERE id = ?')
        ->execute([$fields['label'], $fields['sortOrder'], $floorId]);
    if ($floor['custom']) {
        $pdo->prepare('UPDATE km_map_floor_custom SET keywords = ? WHERE floor_id = ?')
            ->execute([$fields['keywords'] === [] ? null : implode('、', $fields['keywords']), $floorId]);
    }
}

/**
 * 階を足す。座標の範囲は**画像の大きさ**にする(地点は Website の地図編集で置く)。
 */
function km_map_floor_add(PDO $pdo, string $floorId, string $kind, array $fields, string $bytes): void
{
    km_map_floors_ensure_tables($pdo);
    if (!km_map_floor_id_valid($floorId)) {
        throw new InvalidArgumentException('階の id は半角の小文字・数字・_ で 16 字までにしてください(例: 6・b1・bldg_new_1f)。');
    }
    if ($kind !== 'campus' && $kind !== 'building') {
        throw new InvalidArgumentException('種類を選んでください。');
    }
    $exists = $pdo->prepare('SELECT 1 FROM km_map_floors WHERE id = ?');
    $exists->execute([$floorId]);
    if ($exists->fetchColumn() !== false || km_map_floor_builtin($floorId) !== null) {
        throw new InvalidArgumentException('その id の階はもうあります(組み込みの階を消したなら「組み込みの階を戻す」で戻せます)。');
    }
    if ($kind === 'building' && $fields['keywords'] === []) {
        throw new InvalidArgumentException('建物の中は、屋外の建物の名前の手がかりを入れてください(例: 図書館)。屋外でその建物を押すと「中へ」に出ます。');
    }

    $stored = km_map_floor_store_image($bytes);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO km_map_floors (id, label, svg_path, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$floorId, $fields['label'], KM_MAP_FLOOR_UPLOAD_PREFIX . $stored['storedName'], $fields['sortOrder']]);
        km_map_floor_set_bounds($pdo, $floorId, $stored['width'], $stored['height']);
        $pdo->prepare('INSERT INTO km_map_floor_custom (floor_id, kind, keywords, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$floorId, $kind, $fields['keywords'] === [] ? null : implode('、', $fields['keywords'])]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        km_map_floor_remove_upload(KM_MAP_FLOOR_UPLOAD_PREFIX . $stored['storedName']);
        throw $exception;
    }
}

/**
 * 階を消す。地点があるときは `$withNodes` を明示しない限り断る(地点と、その地点に繋がる線も消える)。
 *
 * @return array{nodes:int, edges:int}
 */
function km_map_floor_delete(PDO $pdo, string $floorId, bool $withNodes): array
{
    $floor = km_map_floor_find($pdo, $floorId);
    if ($floor === null) {
        throw new InvalidArgumentException('階が見つかりません(もう消されたかもしれません)。');
    }
    if ($floorId === KM_MAP_FLOOR_INITIAL) {
        throw new InvalidArgumentException('1F は公開ページを開いたときに最初に出す階なので消せません(画像の差し替えはできます)。');
    }
    if ($floor['nodeCount'] > 0 && !$withNodes) {
        throw new InvalidArgumentException(
            'この階には地点が ' . $floor['nodeCount'] . ' 件あります。消すなら「中の地点と線も消す」に印を付けてください。'
        );
    }

    $edges = 0;
    $nodes = 0;
    $pdo->beginTransaction();
    try {
        if ($floor['nodeCount'] > 0) {
            // 線は先に消す(外部キーに CASCADE は付いていない。lib/app-map-sync.php と同じ)
            $statement = $pdo->prepare(
                'DELETE FROM km_map_edges WHERE from_node_id IN (SELECT id FROM km_map_nodes WHERE floor_id = ?)
                    OR to_node_id IN (SELECT id FROM km_map_nodes WHERE floor_id = ?)'
            );
            $statement->execute([$floorId, $floorId]);
            $edges = $statement->rowCount();
            $statement = $pdo->prepare('DELETE FROM km_map_nodes WHERE floor_id = ?');
            $statement->execute([$floorId]);
            $nodes = $statement->rowCount();
        }
        $pdo->prepare('DELETE FROM km_map_floor_bounds WHERE floor_id = ?')->execute([$floorId]);
        $pdo->prepare('DELETE FROM km_map_floor_custom WHERE floor_id = ?')->execute([$floorId]);
        $pdo->prepare('DELETE FROM km_map_floors WHERE id = ?')->execute([$floorId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    km_map_floor_remove_upload((string) $floor['svgPath']);

    return ['nodes' => $nodes, 'edges' => $edges];
}

/**
 * 消した組み込みの階を戻す(画像は組み込みのもの・範囲は既定)。在るものには触らない。
 *
 * @return array<int, string> 戻した階の id
 */
function km_map_floor_restore_builtins(PDO $pdo): array
{
    km_map_floors_ensure_tables($pdo);
    $existing = array_map('strval', $pdo->query('SELECT id FROM km_map_floors')->fetchAll(PDO::FETCH_COLUMN));
    $restored = [];
    $ids = array_merge(array_column(KM_MAP_CAMPUS_FLOORS, 'id'), array_column(KM_BUILDING_FLOORS, 'id'));
    foreach ($ids as $id) {
        if (in_array($id, $existing, true)) {
            continue;
        }
        $builtin = km_map_floor_builtin($id);
        if ($builtin === null || km_map_floor_image_file($builtin['image']) === null) {
            continue;
        }
        $pdo->prepare('INSERT INTO km_map_floors (id, label, svg_path, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$id, $builtin['label'], $builtin['image'], $builtin['sort']]);
        km_map_floor_set_bounds($pdo, $id, $builtin['width'], $builtin['height']);
        $restored[] = $id;
    }

    return $restored;
}

/** 組み込みの階のうち、いま無いもの */
function km_map_floor_missing_builtins(array $floors): array
{
    $have = array_column($floors, 'id');
    $ids = array_merge(array_column(KM_MAP_CAMPUS_FLOORS, 'id'), array_column(KM_BUILDING_FLOORS, 'id'));

    return array_values(array_diff($ids, $have));
}

// ---------------------------------------------------------------- zip でまとめて差し替え

/** 名前を照らすための形(全角の英数字を半角に・空白と記号を落とす・小文字) */
function km_map_floor_name_key(string $name): string
{
    $name = mb_convert_kana($name, 'as', 'UTF-8');
    $name = strtolower($name);

    return (string) preg_replace('/[\s\x{3000}_\-・.()()]+/u', '', $name);
}

/**
 * zip の中のファイル名から、どの階の画像かを当てる。当たらなければ null。
 * 照らす相手: 階の id(`1`・`outside`・`bldg_library_1f`)・名前(`1F`・`図書館 1F`)・組み込みの画像の名前(`１階`・`外全体図`)。
 * 「1階」は「1F」とも照らす(元のファイル名がそうなっている)。
 *
 * @param array<int, array{id:string, label:string}> $floors
 */
function km_map_floor_match(string $fileName, array $floors): ?string
{
    $stem = km_map_floor_name_key((string) pathinfo($fileName, PATHINFO_FILENAME));
    if ($stem === '') {
        return null;
    }
    $alias = static fn (string $key): string => (string) preg_replace('/^(\d+)(階|f)$/u', '$1f', $key);
    foreach ($floors as $floor) {
        $candidates = [$floor['id'], $floor['label']];
        $builtin = km_map_floor_builtin((string) $floor['id']);
        if ($builtin !== null) {
            $candidates[] = (string) pathinfo($builtin['image'], PATHINFO_FILENAME);
        }
        foreach ($candidates as $candidate) {
            $key = km_map_floor_name_key((string) $candidate);
            if ($key !== '' && ($key === $stem || $alias($key) === $alias($stem))) {
                return (string) $floor['id'];
            }
        }
    }

    return null;
}

/** 置き場(uploads の下)。取り込む前の画像を一時的に置く。**1 日より古いものは開くたびに片付ける** */
function km_map_floor_staging_dir(): string
{
    return km_upload_dir() . DIRECTORY_SEPARATOR . 'floor-staging';
}

function km_map_floor_staging_cleanup(int $olderThanSeconds = 86400): void
{
    $base = km_map_floor_staging_dir();
    if (!is_dir($base)) {
        return;
    }
    foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (preg_match('/[\/\\\\][0-9a-f]{32}$/', $dir) === 1 && (int) @filemtime($dir) < time() - $olderThanSeconds) {
            km_map_floor_staging_remove(basename($dir));
        }
    }
}

function km_map_floor_staging_remove(string $token): void
{
    if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
        return;
    }
    $dir = km_map_floor_staging_dir() . DIRECTORY_SEPARATOR . $token;
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

/**
 * zip を開いて、画像を置き場へ並べる。**まだ階には当てない**(画面で確かめてから km_map_floor_zip_apply)。
 *
 * @param array<int, array{id:string, label:string}> $floors
 * @return array{token:string, items:array<int, array{name:string, file:string, width:int, height:int, match:?string, error:?string}>}
 */
function km_map_floor_zip_stage(string $zipPath, array $floors): array
{
    require_once __DIR__ . '/zip-read.php';
    $entries = km_zip_read_all($zipPath, KM_MAP_FLOOR_ZIP_MAX_ENTRIES, KM_MAP_FLOOR_MAX_BYTES, KM_MAP_FLOOR_ZIP_MAX_TOTAL);
    if ($entries === []) {
        throw new InvalidArgumentException('zip の中にファイルがありません。');
    }
    km_map_floor_staging_cleanup();

    $token = bin2hex(random_bytes(16));
    $dir = km_map_floor_staging_dir() . DIRECTORY_SEPARATOR . $token;
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('一時の置き場を作れませんでした。');
    }

    $items = [];
    $taken = [];
    foreach ($entries as $index => $entry) {
        $item = ['name' => $entry['name'], 'file' => '', 'width' => 0, 'height' => 0, 'match' => null, 'error' => null];
        try {
            $checked = km_raster_image_check($entry['bytes'], KM_MAP_FLOOR_MAX_BYTES, KM_MAP_FLOOR_MAX_DIMENSION);
            $file = $index . '.' . $checked['ext'];
            file_put_contents($dir . DIRECTORY_SEPARATOR . $file, $entry['bytes'], LOCK_EX);
            $item['file'] = $file;
            $item['width'] = $checked['width'];
            $item['height'] = $checked['height'];
            $match = km_map_floor_match($entry['name'], $floors);
            // 同じ階に 2 枚当たったら、2 枚目は当てない(どちらにするかは画面で選ぶ)
            if ($match !== null && !isset($taken[$match])) {
                $item['match'] = $match;
                $taken[$match] = true;
            }
        } catch (InvalidArgumentException $exception) {
            $item['error'] = $exception->getMessage();
        }
        $items[] = $item;
    }

    return ['token' => $token, 'items' => $items];
}

/**
 * 置き場の画像を階に当てる。
 *
 * @param array{token:string, items:array} $staged
 * @param array<int, string> $assign 品目の番号 → 階の id(空は「使わない」)
 * @return array<int, array{floor:string, aspectDiffers:bool}>
 */
function km_map_floor_zip_apply(PDO $pdo, array $staged, array $assign): array
{
    $token = (string) ($staged['token'] ?? '');
    if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
        throw new InvalidArgumentException('取り込む画像が見つかりません。zip を上げ直してください。');
    }
    $used = [];
    foreach ($assign as $index => $floorId) {
        if ($floorId === '') {
            continue;
        }
        if (isset($used[$floorId])) {
            throw new InvalidArgumentException('同じ階(' . $floorId . ')に 2 枚当てています。どちらか一方にしてください。');
        }
        $used[$floorId] = true;
    }
    if ($used === []) {
        throw new InvalidArgumentException('当てる階が 1 つも選ばれていません。');
    }

    $dir = km_map_floor_staging_dir() . DIRECTORY_SEPARATOR . $token;
    $done = [];
    foreach ($assign as $index => $floorId) {
        $item = $staged['items'][(int) $index] ?? null;
        if ($floorId === '' || !is_array($item) || $item['file'] === '' || preg_match('/^\d+\.(png|jpg|gif|webp)$/', (string) $item['file']) !== 1) {
            continue;
        }
        $bytes = @file_get_contents($dir . DIRECTORY_SEPARATOR . $item['file']);
        if (!is_string($bytes)) {
            throw new InvalidArgumentException('取り込む画像が見つかりません。zip を上げ直してください。');
        }
        $result = km_map_floor_replace_image($pdo, $floorId, $bytes);
        $done[] = ['floor' => $floorId, 'aspectDiffers' => $result['aspectDiffers']];
    }
    km_map_floor_staging_remove($token);

    return $done;
}
