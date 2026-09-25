<?php

declare(strict_types=1);

require_once __DIR__ . '/app-secret.php';

/**
 * ランキングの集計。
 *
 *   よく行かれた場所   km_map_ranking_places   ノードUUID と回数だけ
 *   調べられた語       km_map_ranking_queries  正規化した語と回数だけ
 *   利用者ランキング   km_map_ranking_users    Logto の sub と回数
 *
 * **上の2つは個人を紐付けない。** 誰が何を調べたかは残さず、回数だけ積む。
 *
 * **利用者ランキングだけは個人が紐付く。** そのため:
 *   - 参加は任意。端末が participate=true を送ってきたときだけ記録する
 *   - 自分の記録を消せる(km_ranking_forget_user)
 *   - 年より前の行は捨てる(km_ranking_purge_old)
 *   - **外へは sub を出さない**(km_ranking_public_user_key)
 * この4つが揃っていない状態で使い始めないこと。
 *
 * ## sub を外へ出さない(2026-09-14)
 *
 * 以前は応答の `userId` に Logto の sub をそのまま入れていた。未認証の
 * `GET /api/app-ranking.php` で参加者全員の sub が取れ、それを
 * `GET /api/app-avatar.php?userId=<sub>` に渡すと**顔写真を一括で集められた**
 * (security-review-2026-09-10 の 2)。いまは年ごとの鍵つきの印を返す。
 * **キーの名前 `userId` は変えない** —— 配布済みのアプリはこのキーを必須として読んでおり、
 * 消すとランキング全体が読めなくなる。値の中身はアプリでは使っていない。
 *
 * ## 調べられた語は、3つ以上の出どころから来たものだけ出す(2026-09-14)
 *
 * 記録は未ログインでも受ける(来場者の多くは未ログイン)。そのため
 * **1台から任意の語を連投すれば、来場者に見える一覧へ好きな文字を出せた**(同 9)。
 * いまは出どころ(IP)ごとの鍵つきの印を別に数え、KM_RANKING_QUERY_MIN_SOURCES 以上の
 * 出どころから来た語だけを一覧に出す。IP そのものは残さない。
 *
 * 地図そのものは DB に入れない(JSON のまま)。ここに置くのは派生した集計値だけ。
 *
 * ## 1 回の送信で数える量を絞る(2026-09-25、診断 W-44・W-45)
 *
 * 以前は形と件数(50)しか見ておらず、**同じ地点 ID を 50 個並べれば 1 回で +50**、
 * **実在しない ID と新しい語は送るたびに表の行が増えた**(1 つの IP から毎分最大 6,000 行)。
 * いまは:
 *   - 1 回の送信の中の重複を落とす(アプリはもともと重複を送らない)
 *   - 地点は地図(km_map_nodes)に在る ID だけ数える。場所の表は地点の数で頭打ちになる
 *   - 利用者の件数は、前の記録から KM_RANKING_USER_MIN_INTERVAL 秒たっていない送信では足さない
 *   - 語の表は年ごとに KM_RANKING_MAX_QUERY_ROWS 行まで。語ごとの出どころの印も打ち止めにする
 *   - 出どころは IP ではなく km_map_rate_limit_key()(IPv6 は /64)で数える
 *   - 管理画面(admin/ranking.php)で、語を一覧から外せる(km_map_ranking_hidden_queries)
 */

require_once __DIR__ . '/map-rate-limit.php';

/** 記録を残す年数。これより古い年は捨てる。 */
const KM_RANKING_RETENTION_YEARS = 2;

/** 1回の送信で受け付ける件数の上限。まとめ送りが暴れないようにする。 */
const KM_RANKING_MAX_BATCH = 50;

/** 調べられた語として保存する長さの上限。 */
const KM_RANKING_MAX_QUERY_LENGTH = 64;

/** 調べられた語を一覧に出すのに要る、異なる出どころの数。 */
const KM_RANKING_QUERY_MIN_SOURCES = 3;

/** 語の表に入れる、年ごとの行数の上限。超えたら新しい語は入れない(既にある語は数える)。 */
const KM_RANKING_MAX_QUERY_ROWS = 20000;

/** 語ごとに覚える出どころの数の上限。公開の判定に要る数より多くは要らない。 */
const KM_RANKING_MAX_QUERY_SOURCES = KM_RANKING_QUERY_MIN_SOURCES * 4;

/** 利用者の件数を足す間隔(秒)。これより短い間の送信は、場所の集計には入れるが利用者には足さない。 */
const KM_RANKING_USER_MIN_INTERVAL = 30;

function km_ranking_ensure_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_ranking_places (
            node_uuid VARCHAR(64) NOT NULL,
            year SMALLINT NOT NULL,
            visits INT NOT NULL DEFAULT 0,
            PRIMARY KEY (node_uuid, year),
            INDEX idx_km_ranking_places_year (year, visits)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    // 語は正規化済みのものだけを入れる。表記ゆれで別行にしないため。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_ranking_queries (
            normalized_query VARCHAR(64) NOT NULL,
            year SMALLINT NOT NULL,
            searches INT NOT NULL DEFAULT 0,
            PRIMARY KEY (normalized_query, year),
            INDEX idx_km_ranking_queries_year (year, searches)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    // 語ごとの出どころの印。**IP ではなく鍵つきの印**(lib/app-secret.php)。数えるためだけに置く
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_ranking_query_sources (
            normalized_query VARCHAR(64) NOT NULL,
            year SMALLINT NOT NULL,
            source_hash CHAR(32) NOT NULL,
            PRIMARY KEY (normalized_query, year, source_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    // **ここだけ個人が紐付く。** 参加した人の行しか作らない。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_ranking_users (
            user_id VARCHAR(191) NOT NULL,
            year SMALLINT NOT NULL,
            visits INT NOT NULL DEFAULT 0,
            display_name VARCHAR(64) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, year),
            INDEX idx_km_ranking_users_year (year, visits)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    // 管理画面で一覧から外した語。**外したあとに同じ語が来ても数えない**(年ごと)
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_ranking_hidden_queries (
            normalized_query VARCHAR(64) NOT NULL,
            year SMALLINT NOT NULL,
            hidden_at DATETIME NOT NULL,
            PRIMARY KEY (normalized_query, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

// ---------------------------------------------------------------- 記録

/**
 * 訪問と検索をまとめて積む。
 *
 * @param array<int, string> $nodeUuids  訪れた地点
 * @param array<int, string> $queries    調べた語(端末側で正規化済み)
 * @param string|null $userId 参加している利用者の sub。参加していなければ null
 * @param string|null $source 出どころ(IP)。語を一覧に出すかの判定に使う。**そのままは保存しない**
 */
function km_ranking_record(
    PDO $pdo,
    array $nodeUuids,
    array $queries,
    ?string $userId,
    ?string $displayName = null,
    ?string $source = null
): void {
    km_ranking_ensure_tables($pdo);

    $year = (int) date('Y');

    // 地図に在る地点だけ。**実在しない ID で表を膨らませない**(W-44)
    $places = km_ranking_existing_uuids($pdo, km_ranking_clean_uuids($nodeUuids));
    if ($places !== []) {
        $statement = $pdo->prepare(
            'INSERT INTO km_map_ranking_places (node_uuid, year, visits)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE visits = visits + 1'
        );
        foreach ($places as $uuid) {
            $statement->execute([$uuid, $year]);
        }
    }

    $words = km_ranking_clean_queries($queries);
    if ($words !== []) {
        $words = km_ranking_without_hidden($pdo, $words, $year);
    }
    if ($words !== []) {
        /*
         * **既にある語は数える。新しい語は、年の上限まで。** 上限を超えたあとの新しい語は捨てる
         * (1 つの IP から毎分数千行を足せた。W-44)。数え方は 1 文にまとめず、先に行数を見る ——
         * 上限はおおよそでよい(同時に来た分だけ少し超えうる)。
         */
        $rows = $pdo->prepare('SELECT COUNT(*) FROM km_map_ranking_queries WHERE year = ?');
        $rows->execute([$year]);
        $full = (int) $rows->fetchColumn() >= KM_RANKING_MAX_QUERY_ROWS;
        $statement = $full
            ? $pdo->prepare('UPDATE km_map_ranking_queries SET searches = searches + 1 WHERE normalized_query = ? AND year = ?')
            : $pdo->prepare(
                'INSERT INTO km_map_ranking_queries (normalized_query, year, searches)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE searches = searches + 1'
            );
        $counted = [];
        foreach ($words as $word) {
            $statement->execute([$word, $year]);
            if (!$full || $statement->rowCount() > 0) {
                $counted[] = $word;
            }
        }

        if ($source !== null && $source !== '' && $counted !== []) {
            // IP ではなく数える単位(IPv6 は /64)。アドレスを替えるだけで別の出どころにならないように
            $sourceHash = km_app_keyed_hash(
                km_app_secret($pdo, 'ranking'),
                "query-source|{$year}",
                km_map_rate_limit_key($source)
            );
            // 語ごとの出どころは打ち止めにする。公開の判定に要る数より多くは覚えない
            $mark = $pdo->prepare(
                'INSERT IGNORE INTO km_map_ranking_query_sources (normalized_query, year, source_hash)
                 SELECT ?, ?, ? FROM DUAL
                 WHERE (SELECT COUNT(*) FROM km_map_ranking_query_sources
                         WHERE normalized_query = ? AND year = ?) < ?'
            );
            foreach ($counted as $word) {
                $mark->execute([$word, $year, $sourceHash, $word, $year, KM_RANKING_MAX_QUERY_SOURCES]);
            }
        }
    }

    // **参加していない利用者の行は作らない。** 既定は参加しないこと。
    if ($userId !== null && $userId !== '' && $places !== []) {
        /*
         * **短い間隔で送り直しても件数は増えない**(W-44)。前の記録から
         * KM_RANKING_USER_MIN_INTERVAL 秒たっていなければ、表示名だけ直して件数は足さない。
         * アプリは 20 件たまるか画面を離れたときに送るので、ふつうの使い方ではまず当たらない。
         */
        $pdo->prepare(
            'INSERT INTO km_map_ranking_users (user_id, year, visits, display_name, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 visits = IF(updated_at <= DATE_SUB(NOW(), INTERVAL ? SECOND), visits + VALUES(visits), visits),
                 display_name = VALUES(display_name),
                 updated_at = IF(updated_at <= DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), updated_at)'
        )->execute([
            $userId,
            $year,
            count($places),
            km_ranking_clean_name($displayName),
            KM_RANKING_USER_MIN_INTERVAL,
            KM_RANKING_USER_MIN_INTERVAL,
        ]);
    }

    km_ranking_purge_old($pdo, $year);
}

/**
 * ノードUUIDを絞る。
 *
 * @param array<int, mixed> $raw
 * @return array<int, string>
 */
function km_ranking_clean_uuids(array $raw): array
{
    $cleaned = [];
    foreach ($raw as $entry) {
        if (count($cleaned) >= KM_RANKING_MAX_BATCH) {
            break;
        }
        $value = trim((string) $entry);
        // アプリの UUID は英数字とハイフン。表に妙な値を溜めないため入口で絞る。
        // **同じ ID は 1 回だけ**(W-44。並べて送っても 1 回の送信で 1 と数える)
        if ($value !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1 && !in_array($value, $cleaned, true)) {
            $cleaned[] = $value;
        }
    }
    return $cleaned;
}

/**
 * 地図(km_map_nodes.uuid)に在る ID だけを、渡された順で返す。
 *
 * **確かめられなければ 1 件も数えない**(表が無い・DB の不調)。ランキングが一時的に数えないのは害が小さく、
 * 数え方の歯止めが外れる方が困る。
 *
 * @param array<int, string> $uuids km_ranking_clean_uuids() を通したもの
 * @return array<int, string>
 */
function km_ranking_existing_uuids(PDO $pdo, array $uuids): array
{
    if ($uuids === []) {
        return [];
    }
    try {
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $statement = $pdo->prepare("SELECT uuid FROM km_map_nodes WHERE uuid IN ({$placeholders})");
        $statement->execute(array_values($uuids));
        $known = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Throwable $exception) {
        error_log('km_ranking_existing_uuids: 地図の地点を確かめられないので数えません: ' . $exception->getMessage());
        return [];
    }

    return array_values(array_filter($uuids, static fn (string $uuid): bool => isset($known[$uuid])));
}

/**
 * 管理画面で外した語を除く。
 *
 * @param array<int, string> $words
 * @return array<int, string>
 */
function km_ranking_without_hidden(PDO $pdo, array $words, int $year): array
{
    if ($words === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($words), '?'));
    $statement = $pdo->prepare(
        "SELECT normalized_query FROM km_map_ranking_hidden_queries
         WHERE year = ? AND normalized_query IN ({$placeholders})"
    );
    $statement->execute([$year, ...array_values($words)]);
    $hidden = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);

    return array_values(array_filter($words, static fn (string $word): bool => !isset($hidden[$word])));
}

/**
 * 語を一覧から外す(管理画面から)。**回数と出どころの印も消す。** 同じ年のうちは、また来ても数えない。
 *
 * @return bool 外した語が一覧に在ったか
 */
function km_ranking_hide_query(PDO $pdo, string $query, int $year): bool
{
    km_ranking_ensure_tables($pdo);
    $query = trim($query);
    if ($query === '' || mb_strlen($query, 'UTF-8') > KM_RANKING_MAX_QUERY_LENGTH) {
        throw new InvalidArgumentException('外す語が正しくありません。');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO km_map_ranking_hidden_queries (normalized_query, year, hidden_at) VALUES (?, ?, NOW())'
        )->execute([$query, $year]);
        $removed = $pdo->prepare('DELETE FROM km_map_ranking_queries WHERE normalized_query = ? AND year = ?');
        $removed->execute([$query, $year]);
        $pdo->prepare('DELETE FROM km_map_ranking_query_sources WHERE normalized_query = ? AND year = ?')
            ->execute([$query, $year]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return $removed->rowCount() > 0;
}

/**
 * 管理画面の一覧。**公開の条件に満たない語も出す**(公開される前に外せるように)。
 *
 * @return array<int, array{query:string, searches:int, sources:int, public:bool}>
 */
function km_ranking_queries_for_admin(PDO $pdo, int $year, int $limit = 100): array
{
    km_ranking_ensure_tables($pdo);
    $statement = $pdo->prepare(
        'SELECT q.normalized_query, q.searches,
                (SELECT COUNT(*) FROM km_map_ranking_query_sources s
                  WHERE s.normalized_query = q.normalized_query AND s.year = q.year) AS sources
         FROM km_map_ranking_queries q
         WHERE q.year = ?
         ORDER BY q.searches DESC, q.normalized_query ASC LIMIT ?'
    );
    $statement->bindValue(1, $year, PDO::PARAM_INT);
    $statement->bindValue(2, max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $sources = (int) $row['sources'];
        $rows[] = [
            'query' => (string) $row['normalized_query'],
            'searches' => (int) $row['searches'],
            'sources' => $sources,
            'public' => $sources >= KM_RANKING_QUERY_MIN_SOURCES,
        ];
    }
    return $rows;
}

/** 外した語の一覧(新しい順)。 */
function km_ranking_hidden_queries(PDO $pdo, int $year): array
{
    km_ranking_ensure_tables($pdo);
    $statement = $pdo->prepare(
        'SELECT normalized_query FROM km_map_ranking_hidden_queries WHERE year = ? ORDER BY hidden_at DESC LIMIT 200'
    );
    $statement->execute([$year]);

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 調べられた語を絞る。
 *
 * **端末が正規化済みの語を送ってくる前提**だが、ここでも長さと空白だけは見る。
 * サーバーは日本語の畳み込みをしない(アプリの normalizeSearchText と食い違うと
 * 同じ語が2行に割れる)。
 *
 * @param array<int, mixed> $raw
 * @return array<int, string>
 */
function km_ranking_clean_queries(array $raw): array
{
    $cleaned = [];
    foreach ($raw as $entry) {
        if (count($cleaned) >= KM_RANKING_MAX_BATCH) {
            break;
        }
        $value = trim((string) $entry);
        if ($value === '') {
            continue;
        }
        // 制御文字が混ざった語は捨てる。画面へそのまま出すため。
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            continue;
        }
        if (mb_strlen($value, 'UTF-8') > KM_RANKING_MAX_QUERY_LENGTH) {
            continue;
        }
        // 同じ語は 1 回の送信で 1 回だけ数える
        if (in_array($value, $cleaned, true)) {
            continue;
        }
        $cleaned[] = $value;
    }
    return $cleaned;
}

/** 表示名を絞る。null なら null のまま。 */
function km_ranking_clean_name(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $value = trim($raw);
    if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return null;
    }
    return mb_substr($value, 0, 64, 'UTF-8');
}

/**
 * 外へ出す利用者キー。**sub の代わり。**
 *
 * 年を混ぜるので、年をまたいで同じ人を結び付けられない。先頭の `u` は、
 * sub と取り違えて使われたときに形で気づけるようにするための印。
 */
function km_ranking_public_user_key(string $secret, string $userId, int $year): string
{
    return 'u' . substr(km_app_keyed_hash($secret, "ranking-user|{$year}", $userId), 0, 31);
}

/** 保持年数より古い行を捨てる。 */
function km_ranking_purge_old(PDO $pdo, int $currentYear): void
{
    $oldest = $currentYear - KM_RANKING_RETENTION_YEARS;
    foreach (['km_map_ranking_places', 'km_map_ranking_queries', 'km_map_ranking_query_sources', 'km_map_ranking_users', 'km_map_ranking_hidden_queries'] as $table) {
        $pdo->prepare("DELETE FROM {$table} WHERE year < ?")->execute([$oldest]);
    }
}

/**
 * 利用者ランキングから自分の記録を消す。
 *
 * **参加をやめたときに必ず呼べるようにしておくこと。** 消せない記録は残さない。
 */
function km_ranking_forget_user(PDO $pdo, string $userId): void
{
    km_ranking_ensure_tables($pdo);
    $pdo->prepare('DELETE FROM km_map_ranking_users WHERE user_id = ?')->execute([$userId]);
}

// ---------------------------------------------------------------- 取得

/**
 * よく行かれた場所。
 *
 * ノード名はサーバーが持っていないので UUID と回数だけ返す。
 * **名前はアプリが手元の地図から引く。** サーバーに地図を持たせない方針のため。
 *
 * @return array<int, array{nodeUuid:string, visits:int}>
 */
function km_ranking_places(PDO $pdo, int $year, int $limit = 20): array
{
    km_ranking_ensure_tables($pdo);
    $statement = $pdo->prepare(
        'SELECT node_uuid, visits FROM km_map_ranking_places
         WHERE year = ? ORDER BY visits DESC, node_uuid ASC LIMIT ?'
    );
    $statement->bindValue(1, $year, PDO::PARAM_INT);
    $statement->bindValue(2, km_ranking_clamp_limit($limit), PDO::PARAM_INT);
    $statement->execute();

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = ['nodeUuid' => (string) $row['node_uuid'], 'visits' => (int) $row['visits']];
    }
    return $rows;
}

/**
 * 調べられた語。**KM_RANKING_QUERY_MIN_SOURCES 以上の出どころから来た語だけ。**
 *
 * @return array<int, array{query:string, searches:int}>
 */
function km_ranking_queries(
    PDO $pdo,
    int $year,
    int $limit = 20,
    int $minSources = KM_RANKING_QUERY_MIN_SOURCES
): array {
    km_ranking_ensure_tables($pdo);
    $statement = $pdo->prepare(
        'SELECT q.normalized_query, q.searches FROM km_map_ranking_queries q
         WHERE q.year = ?
           AND (SELECT COUNT(*) FROM km_map_ranking_query_sources s
                 WHERE s.normalized_query = q.normalized_query AND s.year = q.year) >= ?
           AND NOT EXISTS (SELECT 1 FROM km_map_ranking_hidden_queries h
                 WHERE h.normalized_query = q.normalized_query AND h.year = q.year)
         ORDER BY q.searches DESC, q.normalized_query ASC LIMIT ?'
    );
    $statement->bindValue(1, $year, PDO::PARAM_INT);
    $statement->bindValue(2, max(1, $minSources), PDO::PARAM_INT);
    $statement->bindValue(3, km_ranking_clamp_limit($limit), PDO::PARAM_INT);
    $statement->execute();

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = [
            'query' => (string) $row['normalized_query'],
            'searches' => (int) $row['searches'],
        ];
    }
    return $rows;
}

/**
 * 利用者ランキング。
 *
 * **参加した人しか行が無い。** ただし `userId` には sub ではなく
 * km_ranking_public_user_key() の値を入れる(冒頭の説明を参照)。
 * 表示名が無ければアプリ側で「名前なし」を出す。
 *
 * @return array<int, array{userId:string, displayName:?string, visits:int}>
 */
function km_ranking_users(PDO $pdo, int $year, int $limit = 20): array
{
    km_ranking_ensure_tables($pdo);
    $statement = $pdo->prepare(
        'SELECT user_id, display_name, visits FROM km_map_ranking_users
         WHERE year = ? ORDER BY visits DESC, user_id ASC LIMIT ?'
    );
    $statement->bindValue(1, $year, PDO::PARAM_INT);
    $statement->bindValue(2, km_ranking_clamp_limit($limit), PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll();
    if ($rows === []) {
        return [];
    }

    $secret = km_app_secret($pdo, 'ranking');
    $users = [];
    foreach ($rows as $row) {
        $name = $row['display_name'];
        $users[] = [
            'userId' => km_ranking_public_user_key($secret, (string) $row['user_id'], $year),
            'displayName' => is_string($name) && $name !== '' ? $name : null,
            'visits' => (int) $row['visits'],
        ];
    }
    return $users;
}

/** 取得件数の歯止め。 */
function km_ranking_clamp_limit(int $limit): int
{
    return max(1, min(100, $limit));
}

/** 集計のある年。新しい順。 */
function km_ranking_years(PDO $pdo): array
{
    km_ranking_ensure_tables($pdo);
    $statement = $pdo->query(
        'SELECT DISTINCT year FROM km_map_ranking_places
         UNION SELECT DISTINCT year FROM km_map_ranking_queries
         ORDER BY year DESC'
    );
    if ($statement === false) {
        return [];
    }
    $years = [];
    foreach ($statement->fetchAll() as $row) {
        $years[] = (int) $row['year'];
    }
    return $years;
}
