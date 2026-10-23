<?php

declare(strict_types=1);

/**
 * 企画の番号(2026-10-23、利用者の指示「Android 側で番号を振り分け、その番号でポスターなどを決める。
 * Android 側で番号のみ設定、Website 側で番号を元にポスターの位置を決定。1 つのノードに複数入る」)。
 *
 * - 番号は管理アプリで地点に振る(アプリの ProjectNumbers.kt。AdMapNode.projectNumbers)。**Website では振らない**
 * - **別の表に持つ**(km_map_node_area と同じ理由。ALTER は要らない)。表 km_map_node_projects(地点 × 番号)
 * - 取り込み(lib/app-map-sync.php)で**丸ごと入れ替える**。配信(lib/app-map-convert.php の km_app_map_from_web)でアプリへ戻す
 * - Website は番号から決める: ポスターの置き場(lib/posters.php)、スケジュールの場所(lib/event-schedule.php)、
 *   イベント中に出す地点(lib/map-data.php)
 * - 番号は英数字と `-` `_`(16 字まで)。全角は半角、英字は大文字にそろえる(アプリと同じ)
 */

const KM_MAP_PROJECT_NUMBER_MAX_LENGTH = 16;
const KM_MAP_PROJECT_NUMBERS_PER_NODE = 10;

/** 表を作る。**取引の外で呼ぶ**(CREATE は取引を確定させる) */
function km_map_node_projects_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_node_projects (
            node_uuid VARCHAR(64) NOT NULL,
            project_no VARCHAR(16) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (node_uuid, project_no),
            INDEX (project_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 1 つの番号をそろえる。番号として使えなければ null。**純粋関数**(アプリの normalizeProjectNumber と同じ) */
function km_map_project_number(mixed $value): ?string
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $text = (string) $value;
    $text = class_exists('Normalizer') ? (string) Normalizer::normalize($text, Normalizer::FORM_KC) : mb_convert_kana($text, 'as');
    $text = strtoupper(trim($text));
    // 長音・ダッシュの揺れをハイフンにそろえる
    $text = (string) preg_replace('/[‐‑‒–—―−ー]/u', '-', $text);

    return preg_match('/\A[0-9A-Z][0-9A-Z_-]{0,' . (KM_MAP_PROJECT_NUMBER_MAX_LENGTH - 1) . '}\z/', $text) === 1 ? $text : null;
}

/**
 * 番号の一覧をそろえる(重複は 1 つ・入れた順・上限まで)。**純粋関数**
 *
 * @return list<string>
 */
function km_map_project_numbers(mixed $values): array
{
    $numbers = [];
    foreach (is_array($values) ? $values : [] as $value) {
        $number = km_map_project_number($value);
        if ($number !== null && !in_array($number, $numbers, true)) {
            $numbers[] = $number;
        }
        if (count($numbers) >= KM_MAP_PROJECT_NUMBERS_PER_NODE) {
            break;
        }
    }
    return $numbers;
}

/**
 * アプリの地図から、地点ごとの番号を取り出す。**純粋関数**
 *
 * @return array<string, list<string>> uuid => 番号
 */
function km_map_projects_from_app(stdClass $map): array
{
    $projects = [];
    foreach ((isset($map->nodes) && is_array($map->nodes)) ? $map->nodes : [] as $node) {
        if (!($node instanceof stdClass)) {
            continue;
        }
        $uuid = trim((string) ($node->uuid ?? ''));
        if ($uuid === '' || strlen($uuid) > 64) {
            continue;
        }
        $numbers = km_map_project_numbers($node->projectNumbers ?? null);
        if ($numbers !== []) {
            $projects[$uuid] = $numbers;
        }
    }
    return $projects;
}

/**
 * 表の中身(uuid => 番号)。表が無ければ空(配信と公開の地図を止めない)。
 *
 * @return array<string, list<string>>
 */
function km_map_node_projects_all(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT node_uuid, project_no FROM km_map_node_projects ORDER BY node_uuid, sort_order, project_no')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        return [];
    }
    $projects = [];
    foreach ($rows as $row) {
        $projects[(string) $row['node_uuid']][] = (string) $row['project_no'];
    }
    return $projects;
}

/**
 * 取り込んだ地図の番号で、表を**丸ごと入れ替える**。表は先に作っておくこと。返すのは入れた行の数
 *
 * @param array<string, list<string>> $projects
 */
function km_map_node_projects_sync(PDO $pdo, array $projects): int
{
    $pdo->exec('DELETE FROM km_map_node_projects');
    $insert = $pdo->prepare('INSERT INTO km_map_node_projects (node_uuid, project_no, sort_order) VALUES (?, ?, ?)');
    $saved = 0;
    foreach ($projects as $uuid => $numbers) {
        foreach (array_values($numbers) as $order => $number) {
            $insert->execute([(string) $uuid, $number, $order]);
            $saved++;
        }
    }
    return $saved;
}

/** 配信する地図の地点に番号を足す。番号の無い地点には項目を足さない(形を変えない)。**引数を書き換える** */
function km_map_projects_apply(stdClass $map, array $projects): void
{
    if ($projects === [] || !isset($map->nodes) || !is_array($map->nodes)) {
        return;
    }
    foreach ($map->nodes as $node) {
        $numbers = $node instanceof stdClass ? ($projects[(string) ($node->uuid ?? '')] ?? null) : null;
        if ($numbers !== null) {
            $node->projectNumbers = array_values($numbers);
        }
    }
}

/**
 * Website の地点の id(uuid からハイフンを抜いた 32 字。km_app_map_node_id)ごとの番号。
 *
 * @return array<string, list<string>> id => 番号
 */
function km_map_projects_by_node_id(PDO $pdo): array
{
    require_once __DIR__ . '/app-map-convert.php';
    $byId = [];
    foreach (km_map_node_projects_all($pdo) as $uuid => $numbers) {
        $id = km_app_map_node_id((string) $uuid);
        if ($id !== '') {
            $byId[$id] = $numbers;
        }
    }
    return $byId;
}

/**
 * 番号ごとの地点(id の一覧)。**純粋関数**。$visible に在る地点だけ(出していない地点の場所を漏らさない)
 *
 * @param array<string, list<string>> $projectsById id => 番号
 * @param array<string, mixed>|null $visible id => 何か(null なら絞らない)
 * @return array<string, list<string>> 番号 => id
 */
function km_map_project_nodes(array $projectsById, ?array $visible = null): array
{
    $nodes = [];
    foreach ($projectsById as $id => $numbers) {
        if ($visible !== null && !isset($visible[$id])) {
            continue;
        }
        foreach ($numbers as $number) {
            $nodes[$number][] = (string) $id;
        }
    }
    ksort($nodes, SORT_NATURAL);
    return $nodes;
}

/**
 * 一時地点(臨時の地点)の名前が、そのまま企画の番号か(2026-10-23、利用者の運用「一時ノードでは、名前にポスター番号を明記した」)。
 * **名前全体が番号の形のときだけ**番号とみなす(「21」「A-3」は番号、「受付」「ゴミ箱」「21 たこ焼き」は違う)。**純粋関数**
 */
function km_map_project_number_from_name(string $name): ?string
{
    $name = trim($name);
    return $name === '' ? null : km_map_project_number($name);
}

/**
 * イベント中の名前が、名前全体で企画の番号なら、その地点の番号に足す(2026-10-23。一時地点と同じ運用 ——
 * 教室にイベント中の名前「32」を付けても、番号 32 のポスターとスケジュールが付く)。**純粋関数**
 *
 * @param array<string, list<string>> $projectsById id => 番号
 * @param array<string, string> $aliases id => イベント中の名前(km_map_event_overlay の aliases)
 * @return array<string, list<string>>
 */
function km_map_projects_with_aliases(array $projectsById, array $aliases): array
{
    foreach ($aliases as $id => $alias) {
        $number = km_map_project_number_from_name((string) $alias);
        if ($number !== null && !in_array($number, $projectsById[(string) $id] ?? [], true)) {
            $projectsById[(string) $id][] = $number;
        }
    }
    return $projectsById;
}

/**
 * 管理画面(ポスター・スケジュール)で使う、地点ごとの番号(id => 番号)。表の番号に、**全部のイベント**のイベント中の名前が番号のものを足す
 * (2026-10-23、利用者の報告「企画 32 がしっかり設定されているのにエラー」—— 2E はイベント中の名前「32」だけで番号が付いており、
 * 管理画面は表の番号しか見ていなかったので「この番号の地点が地図にありません」と出た)。期間前のイベントも入れる(準備のときに見るため)。
 * 表が無ければ空として続ける
 *
 * @return array<string, list<string>>
 */
function km_map_projects_for_admin(PDO $pdo): array
{
    $aliases = [];
    try {
        foreach ($pdo->query('SELECT node_id, alias FROM km_map_event_aliases ORDER BY event_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aliases[] = [(string) $row['node_id'] => (string) $row['alias']];
        }
    } catch (Throwable $exception) {
        // イベントの表がまだ無い
    }
    $projects = km_map_projects_by_node_id($pdo);
    // 同じ地点に別のイベントで別の番号の名前が付いていても、どれも入れる
    foreach ($aliases as $alias) {
        $projects = km_map_projects_with_aliases($projects, $alias);
    }
    return $projects;
}

/**
 * 臨時の地点(km_map_event_overlay の pois)を、番号ごとにまとめる。**純粋関数**
 *
 * @param list<array<string, mixed>> $pois
 * @return array<string, list<int>> 番号 => 臨時の地点の id
 */
function km_map_project_pois(array $pois): array
{
    $byNumber = [];
    foreach ($pois as $poi) {
        $number = km_map_project_number_from_name((string) ($poi['name'] ?? ''));
        if ($number !== null) {
            $byNumber[$number][] = (int) ($poi['id'] ?? 0);
        }
    }
    ksort($byNumber, SORT_NATURAL);
    return $byNumber;
}

/**
 * 管理画面用: 全部のイベントの臨時の地点のうち、名前が番号のもの(番号 => [{id, name, floor, event}])。表が無ければ空
 *
 * @return array<string, list<array{id:int, floor:string, event:string}>>
 */
function km_map_project_pois_all(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT p.id, p.name, p.floor_id AS floor, e.name AS event
             FROM km_map_event_pois p JOIN km_map_events e ON e.id = p.event_id ORDER BY p.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return [];
    }
    $byNumber = [];
    foreach ($rows as $row) {
        $number = km_map_project_number_from_name((string) $row['name']);
        if ($number !== null) {
            $byNumber[$number][] = ['id' => (int) $row['id'], 'floor' => (string) $row['floor'], 'event' => (string) $row['event']];
        }
    }
    ksort($byNumber, SORT_NATURAL);
    return $byNumber;
}
