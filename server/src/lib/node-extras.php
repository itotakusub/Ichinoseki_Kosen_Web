<?php

declare(strict_types=1);

/**
 * アプリの部屋の詳細に出す写真(ポスター)と「いいね」(2026-10-22、利用者の指示「部屋をタップすると写真やレビュー」→ 決定「写真といいね」)。
 * 口は api/node-extras.php。
 *
 * ## 写真
 * 管理画面の「ポスター・広告」で地点に置いたポスター。**Web の公開ページと同じ決まり**で出す(km_posters_public):
 * 出し方(表示しない / イベント中のみ / 常に表示)に従い、教員室・研究室(lib/node-visibility.php)のポスターは出さない。
 *
 * ## いいね
 * - **だれのものかは持たない。** アプリが作ったでたらめな端末の印を、鍵つきの印(km_app_keyed_hash)にしてだけ持つ。IP も持たない
 * - 1 端末 1 地点 1 つ。付け外しできる
 * - 同じ送り元(IP を丸めた鍵つきの印)から 1 時間に付けられる数を絞る(KM_NODE_LIKE_SOURCE_LIMIT)
 * - 管理画面で地点ごとの数を見て、0 に戻せる
 */

require_once __DIR__ . '/app-secret.php';
require_once __DIR__ . '/map-rate-limit.php';
require_once __DIR__ . '/posters.php';
require_once __DIR__ . '/node-visibility.php';

const KM_NODE_LIKE_KEY_NAME = 'node_like';
/** 同じ送り元から 1 時間に付けられるいいねの数 */
const KM_NODE_LIKE_SOURCE_LIMIT = 60;
/** 端末の印の形(アプリが作る 32 桁の 16 進) */
const KM_NODE_LIKE_DEVICE_PATTERN = '/^[0-9a-f]{32}$/';
/** 地点の uuid の形(古い Web の地点は `n_new_10` のような番号がそのまま uuid になっている) */
const KM_NODE_UUID_PATTERN = '/^[0-9A-Za-z_-]{1,64}$/';

function km_node_likes_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_node_likes (
            node_uuid VARCHAR(64) NOT NULL,
            voter_hash CHAR(32) NOT NULL,
            source_hash CHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (node_uuid, voter_hash),
            INDEX idx_km_node_likes_source (source_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

function km_node_like_device_valid(string $device): bool
{
    return preg_match(KM_NODE_LIKE_DEVICE_PATTERN, $device) === 1;
}

function km_node_uuid_valid(string $uuid): bool
{
    return preg_match(KM_NODE_UUID_PATTERN, $uuid) === 1;
}

/** 端末の印 → 持つ印 */
function km_node_like_voter(PDO $pdo, string $device): string
{
    return km_app_keyed_hash(km_app_secret($pdo, KM_NODE_LIKE_KEY_NAME), 'node-like-voter', $device);
}

/** 送り元(IP を丸めたもの)→ 持つ印。回数を絞るためだけに使う */
function km_node_like_source(PDO $pdo, ?string $ip = null): string
{
    return km_app_keyed_hash(km_app_secret($pdo, KM_NODE_LIKE_KEY_NAME), 'node-like-source', km_map_rate_limit_key($ip));
}

/**
 * Web の地図の地点(uuid で引く)。無ければ null。
 *
 * @return array{id:string, uuid:string, name:string, type2:string}|null
 */
function km_node_extras_find_node(PDO $pdo, string $uuid): ?array
{
    $statement = $pdo->prepare('SELECT id, name, type2 FROM km_map_nodes WHERE uuid = ? LIMIT 1');
    $statement->execute([$uuid]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return ['id' => (string) $row['id'], 'uuid' => $uuid, 'name' => (string) ($row['name'] ?? ''), 'type2' => (string) ($row['type2'] ?? '')];
}

/**
 * その地点のポスター(写真)。Web の公開ページと同じ決まりで、出さないときは空。
 *
 * @param array{id:string, name:string, type2:string}|null $node
 * @return array<int, array{id:int, title:string, description:string, linkUrl:string, imageUrl:string, width:int, height:int}>
 */
function km_node_extras_posters(PDO $pdo, ?array $node, bool $eventActive): array
{
    if ($node === null || km_map_node_is_restricted($node)) {
        return [];
    }
    /*
     * 企画の番号で置いたポスターも出す(2026-10-23。それまで地点に直接置いたものだけで、番号で置いたものがアプリに出なかった)。
     * 番号は Web の地図と同じ —— 管理アプリで振った番号と、イベント中の名前が番号のもの
     */
    $projectNodes = [];
    foreach (km_node_extras_project_numbers($pdo, $node) as $number) {
        $projectNodes[$number] = [$node['id']];
    }
    $posters = [];
    foreach (km_posters_public($pdo, $eventActive, [$node['id'] => ['name' => $node['name']]], $projectNodes) as $poster) {
        if (!in_array($node['id'], $poster['nodeIds'], true)) {
            continue;
        }
        unset($poster['nodeId'], $poster['nodeIds'], $poster['poiIds'], $poster['isAd']);
        $posters[] = $poster;
    }

    return $posters;
}

/**
 * その地点の予定(2026-10-30、振り返りのフェーズ 5 の 1)。**一般の行だけ**(アプリはアクセスコードだけで、教職員か分からない)。
 * 場所は Web の地図と同じ(会場に選んだ地点・企画の番号の地点)。教員室・研究室は出さない
 *
 * @return list<array{title:string, organizer:string, description:string, startsAt:string, endsAt:?string, projectNo:?string}>
 */
function km_node_extras_schedule(PDO $pdo, ?array $node): array
{
    if ($node === null || km_map_node_is_restricted($node)) {
        return [];
    }
    require_once __DIR__ . '/event-schedule.php';
    $projectNodes = [];
    foreach (km_node_extras_project_numbers($pdo, $node) as $number) {
        $projectNodes[$number] = [$node['id']];
    }
    $rows = [];
    foreach (km_event_schedule_public($pdo, $projectNodes, [], false, [$node['id'] => true]) as $row) {
        if (in_array($node['id'], $row['nodeIds'], true)) {
            $rows[] = ['title' => $row['title'], 'organizer' => $row['organizer'], 'description' => $row['description'],
                'startsAt' => $row['startsAt'], 'endsAt' => $row['endsAt'], 'projectNo' => $row['projectNo']];
        }
    }
    return $rows;
}

/**
 * その地点の屋台のメニューと混み具合(2026-10-30、振り返りのフェーズ 5 の 2。lib/stalls.php)。古い値は外れている
 *
 * @return list<array{name:string, menu:list<array>, waiting:?int, note:string, menuAt:?string, waitingAt:?string}>
 */
function km_node_extras_stalls(PDO $pdo, ?array $node): array
{
    if ($node === null || km_map_node_is_restricted($node)) {
        return [];
    }
    require_once __DIR__ . '/stalls.php';
    $projectNodes = [];
    foreach (km_node_extras_project_numbers($pdo, $node) as $number) {
        $projectNodes[$number] = [$node['id']];
    }
    $stalls = [];
    foreach (km_stalls_public($pdo, $projectNodes, [], [$node['id'] => true]) as $stall) {
        if (in_array($node['id'], $stall['nodeIds'], true)) {
            $stalls[] = ['name' => $stall['name'], 'menu' => $stall['menu'], 'waiting' => $stall['waiting'], 'note' => $stall['note'],
                'menuAt' => $stall['menuAt'], 'waitingAt' => $stall['waitingAt']];
        }
    }
    return $stalls;
}

/**
 * その地点の企画の番号(管理アプリで振った番号 + イベント中の名前が番号のもの)。表が無ければ空。
 *
 * @param array{id:string, uuid?:string} $node
 * @return list<string>
 */
function km_node_extras_project_numbers(PDO $pdo, array $node): array
{
    require_once __DIR__ . '/map-node-projects.php';
    require_once __DIR__ . '/map-events.php';
    $numbers = km_map_node_projects_all($pdo)[(string) ($node['uuid'] ?? '')] ?? [];
    $aliases = km_map_event_overlay($pdo)['aliases'];
    $alias = isset($aliases[$node['id']]) ? [$node['id'] => $aliases[$node['id']]] : [];

    return km_map_projects_with_aliases([$node['id'] => $numbers], $alias)[$node['id']];
}

/**
 * いいねの数と、この端末が付けているか。
 *
 * @return array{count:int, liked:bool}
 */
function km_node_like_state(PDO $pdo, string $nodeUuid, ?string $voter): array
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM km_node_likes WHERE node_uuid = ?');
    $count->execute([$nodeUuid]);
    $liked = false;
    if ($voter !== null) {
        $mine = $pdo->prepare('SELECT 1 FROM km_node_likes WHERE node_uuid = ? AND voter_hash = ?');
        $mine->execute([$nodeUuid, $voter]);
        $liked = $mine->fetchColumn() !== false;
    }

    return ['count' => (int) $count->fetchColumn(), 'liked' => $liked];
}

/**
 * 付ける・外す。付けるときだけ送り元の回数を見る。返すのは 'ok' か 'limited'(回数を超えた)。
 * **同じ端末が同じ地点に 2 つ付けることはない**(主キー。同時に来た 2 つ目は重複で弾かれ、付いている扱い)。
 *
 * 時刻は PHP で作って書き、PHP で作った時刻と比べる(DB の NOW() と PHP の時計をまたがない。scripts/check.php の SQLite でも同じに動く)。
 * [$now] は試しのために渡せる。
 */
function km_node_like_set(PDO $pdo, string $nodeUuid, string $voter, string $source, bool $like, ?int $now = null): string
{
    if (!$like) {
        $pdo->prepare('DELETE FROM km_node_likes WHERE node_uuid = ? AND voter_hash = ?')->execute([$nodeUuid, $voter]);
        return 'ok';
    }
    $mine = $pdo->prepare('SELECT 1 FROM km_node_likes WHERE node_uuid = ? AND voter_hash = ?');
    $mine->execute([$nodeUuid, $voter]);
    if ($mine->fetchColumn() !== false) {
        return 'ok';
    }
    $now ??= time();
    $recent = $pdo->prepare('SELECT COUNT(*) FROM km_node_likes WHERE source_hash = ? AND created_at >= ?');
    $recent->execute([$source, date('Y-m-d H:i:s', $now - 3600)]);
    if ((int) $recent->fetchColumn() >= KM_NODE_LIKE_SOURCE_LIMIT) {
        return 'limited';
    }
    try {
        $pdo->prepare('INSERT INTO km_node_likes (node_uuid, voter_hash, source_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$nodeUuid, $voter, $source, date('Y-m-d H:i:s', $now)]);
    } catch (PDOException $exception) {
        // 同じ端末から同時に 2 つ来た(主キーの重複)。もう付いているので ok
        if ((string) $exception->getCode() !== '23000') {
            throw $exception;
        }
    }

    return 'ok';
}

/**
 * 管理画面: 地点ごとのいいねの数(多い順)。地点の名前は Web の地図から引く(無ければ空)。
 *
 * @return array<int, array{uuid:string, name:string, count:int}>
 */
function km_node_likes_totals(PDO $pdo, int $limit = 200): array
{
    $rows = $pdo->query(
        'SELECT l.node_uuid AS uuid, MAX(n.name) AS name, COUNT(*) AS likes
           FROM km_node_likes l
           LEFT JOIN km_map_nodes n ON n.uuid = l.node_uuid
          GROUP BY l.node_uuid
          ORDER BY likes DESC, l.node_uuid
          LIMIT ' . max(1, $limit)
    )->fetchAll();

    return array_map(static fn (array $row): array => [
        'uuid' => (string) $row['uuid'],
        'name' => (string) ($row['name'] ?? ''),
        'count' => (int) $row['likes'],
    ], $rows);
}

/** 管理画面: その地点のいいねを 0 に戻す。消した数を返す */
function km_node_likes_reset(PDO $pdo, string $nodeUuid): int
{
    $statement = $pdo->prepare('DELETE FROM km_node_likes WHERE node_uuid = ?');
    $statement->execute([$nodeUuid]);

    return $statement->rowCount();
}
