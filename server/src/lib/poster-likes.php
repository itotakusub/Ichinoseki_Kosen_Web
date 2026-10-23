<?php

declare(strict_types=1);

/**
 * ポスターの ♡(いいね。2026-10-23、利用者の指示「ポスターに ♡ を付けられるように」)。口は api/poster-like.php。
 *
 * 作りは地点のいいね(lib/node-extras.php)と同じ:
 * - **だれのものかは持たない。** 端末が作ったでたらめな印(localStorage の 32 桁の 16 進)を、鍵つきの印にしてだけ持つ。IP も持たない
 * - 1 端末 1 ポスター 1 つ。付け外しできる
 * - 同じ送り元(IP を丸めた鍵つきの印)から 1 時間に付けられる数を絞る(KM_POSTER_LIKE_SOURCE_LIMIT)
 * - 管理画面の「ポスター・広告」でポスターごとの数を見て、0 に戻せる
 *
 * **別の表**(km_poster_likes)。CREATE だけで足りる(ALTER は要らない)。人に結びつかないので、退会で消すものは無い。
 */

require_once __DIR__ . '/app-secret.php';
require_once __DIR__ . '/map-rate-limit.php';
require_once __DIR__ . '/node-extras.php';

/** 鍵の名前は地点のいいねと分ける(片方の印から、もう片方を引けないように) */
const KM_POSTER_LIKE_KEY_NAME = 'poster_like';
/** 同じ送り元から 1 時間に付けられる ♡ の数 */
const KM_POSTER_LIKE_SOURCE_LIMIT = 60;
/** 一度に数を尋ねられるポスターの数(地点の詳細に並ぶ数より十分多い) */
const KM_POSTER_LIKE_QUERY_MAX = 50;

function km_poster_likes_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_poster_likes (
            poster_id INT NOT NULL,
            voter_hash CHAR(32) NOT NULL,
            source_hash CHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (poster_id, voter_hash),
            INDEX idx_km_poster_likes_source (source_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 端末の印 → 持つ印 */
function km_poster_like_voter(PDO $pdo, string $device): string
{
    return km_app_keyed_hash(km_app_secret($pdo, KM_POSTER_LIKE_KEY_NAME), 'poster-like-voter', $device);
}

/** 送り元(IP を丸めたもの)→ 持つ印。回数を絞るためだけに使う */
function km_poster_like_source(PDO $pdo, ?string $ip = null): string
{
    return km_app_keyed_hash(km_app_secret($pdo, KM_POSTER_LIKE_KEY_NAME), 'poster-like-source', km_map_rate_limit_key($ip));
}

/**
 * 尋ねられたポスターの番号(「1,2,3」)をそろえる。重複は 1 つ・上限まで。**純粋関数**
 *
 * @return list<int>
 */
function km_poster_like_ids(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $part) {
        if (preg_match('/^[1-9]\d{0,9}$/', trim($part)) !== 1) {
            continue;
        }
        $id = (int) trim($part);
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        if (count($ids) >= KM_POSTER_LIKE_QUERY_MAX) {
            break;
        }
    }
    return $ids;
}

/**
 * 付ける・外す。付けるときだけ送り元の回数を見る。返すのは 'ok' か 'limited'(回数を超えた)。
 * 時刻は PHP で作って比べる(km_node_like_set と同じ。[$now] は試しのため)
 */
function km_poster_like_set(PDO $pdo, int $posterId, string $voter, string $source, bool $like, ?int $now = null): string
{
    if (!$like) {
        $pdo->prepare('DELETE FROM km_poster_likes WHERE poster_id = ? AND voter_hash = ?')->execute([$posterId, $voter]);
        return 'ok';
    }
    $mine = $pdo->prepare('SELECT 1 FROM km_poster_likes WHERE poster_id = ? AND voter_hash = ?');
    $mine->execute([$posterId, $voter]);
    if ($mine->fetchColumn() !== false) {
        return 'ok';
    }
    $now ??= time();
    $recent = $pdo->prepare('SELECT COUNT(*) FROM km_poster_likes WHERE source_hash = ? AND created_at >= ?');
    $recent->execute([$source, date('Y-m-d H:i:s', $now - 3600)]);
    if ((int) $recent->fetchColumn() >= KM_POSTER_LIKE_SOURCE_LIMIT) {
        return 'limited';
    }
    try {
        $pdo->prepare('INSERT INTO km_poster_likes (poster_id, voter_hash, source_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$posterId, $voter, $source, date('Y-m-d H:i:s', $now)]);
    } catch (PDOException $exception) {
        // 同じ端末から同時に 2 つ来た(主キーの重複)。もう付いているので ok
        if ((string) $exception->getCode() !== '23000') {
            throw $exception;
        }
    }
    return 'ok';
}

/**
 * ポスターごとの数と、この端末が付けているか。表が無ければ 0。
 *
 * @param list<int> $ids
 * @return list<array{id:int, count:int, liked:bool}>
 */
function km_poster_like_state(PDO $pdo, array $ids, ?string $voter): array
{
    if ($ids === []) {
        return [];
    }
    $counts = [];
    $mine = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare("SELECT poster_id, COUNT(*) AS likes FROM km_poster_likes WHERE poster_id IN ($in) GROUP BY poster_id");
        $statement->execute($ids);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['poster_id']] = (int) $row['likes'];
        }
        if ($voter !== null) {
            $statement = $pdo->prepare("SELECT poster_id FROM km_poster_likes WHERE voter_hash = ? AND poster_id IN ($in)");
            $statement->execute(array_merge([$voter], $ids));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $mine[(int) $id] = true;
            }
        }
    } catch (PDOException $exception) {
        // 表がまだ無い(まだ誰も押していない)
    }
    return array_map(static fn (int $id): array => ['id' => $id, 'count' => $counts[$id] ?? 0, 'liked' => isset($mine[$id])], $ids);
}

/**
 * 地図のデータに載せる数(ポスターの番号 => 数)。表が無ければ空。
 *
 * @return array<int, int>
 */
function km_poster_like_counts(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT poster_id, COUNT(*) AS likes FROM km_poster_likes GROUP BY poster_id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        return [];
    }
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int) $row['poster_id']] = (int) $row['likes'];
    }
    return $counts;
}

/** 管理画面: そのポスターの ♡ を 0 に戻す。消した数を返す */
function km_poster_likes_reset(PDO $pdo, int $posterId): int
{
    $statement = $pdo->prepare('DELETE FROM km_poster_likes WHERE poster_id = ?');
    $statement->execute([$posterId]);
    return $statement->rowCount();
}
