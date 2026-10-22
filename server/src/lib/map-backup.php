<?php

declare(strict_types=1);

/**
 * 地図のノードのクラウドバックアップ(2026-10-06。2026-10-05 の計画 D1、利用者の指示「バックアップ先は自鯖」)。
 *
 * 管理アプリの「ノードのエクスポート(Wi-Fi 学習を含まない)」と**同じ JSON**(format=kosenmap-map)を、
 * そのまま gzip にして uploads/map-backups/ へ置く。戻すときはアプリが同じ JSON を受け取り、
 * 「ノードのインポート」と同じ道(学習データは残す)で取り込む。
 *
 * - **管理者だけ**(api/map-backup.php が Logto の管理者の権限を確かめる)。中身に教職員の氏名が入りうるため
 *   2026-10-23 から学習の協力者(LOGTO_LEARNING_PERMISSION)も置ける。**協力者が見る・戻す・消すのは自分が置いた分だけ**
 * - 2026-10-23 から Wi-Fi 学習を含む書き出し(format は同じ kosenmap-map)と、パスワード付きの包み(kosenmap-encrypted。
 *   アプリの BackupCrypto.kt)も預かる。包みはサーバーでは開けない(パスワードは端末の外へ出ない)。
 *   印(学習を含む・パスワード付き)は別の表 km_map_backup_meta に持つ(km_map_backups に ALTER は要らない)
 * - **サーバーは中身を読み解かない**(json_validate で形だけ確かめる。地点の数はアプリが数えて送る)
 * - 残すのは**置いた人ごとに**新しい方から KM_MAP_BACKUP_KEEP 件(2026-10-23 から。協力者が管理者の分を押し出さないように)。
 *   古いものはファイルごと消す(控えの世代と同じ扱い)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';

const KM_MAP_BACKUP_KEEP = 20;
/** 1 件の上限。ノードだけの書き出しは数百 KB 〜数 MB(学習データを含まない)。nginx の client_max_body_size と揃える */
const KM_MAP_BACKUP_MAX_BYTES = 16 * 1024 * 1024;
const KM_MAP_BACKUP_NOTE_MAX = 200;

function km_map_backup_dir(): string
{
    return km_upload_dir() . '/map-backups';
}

function km_map_backup_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_sub VARCHAR(64) NOT NULL,
            user_name VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            stored_name VARCHAR(64) NOT NULL,
            node_count INT UNSIGNED NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    // 学習を含む・パスワード付きの印(2026-10-23)。行が無いバックアップはノードだけ・パスワードなし
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_backup_meta (
            backup_id INT NOT NULL PRIMARY KEY,
            with_learning TINYINT(1) NOT NULL DEFAULT 0,
            locked TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

/** パスワード付きの包み(アプリの BackupCrypto.kt)か。頭だけ見る。**純粋関数** */
function km_map_backup_is_locked(string $json): bool
{
    return preg_match('/\A\s*\{\s*"format"\s*:\s*"kosenmap-encrypted"\s*,\s*"formatVersion"\s*:\s*1\b/', substr($json, 0, 200)) === 1;
}

/**
 * 中身の形を確かめる。**読み解かずに**、JSON として正しいことと、ノードの書き出しの頭であることだけを見る。
 * (アプリの encodeEnvelope は format → formatVersion の順に書く。TransferFormat.kt)。パスワード付きの包みも受ける(2026-10-23)
 */
function km_map_backup_validate(string $json): void
{
    if ($json === '' || strlen($json) > KM_MAP_BACKUP_MAX_BYTES) {
        throw new InvalidArgumentException('大きさが範囲外です(16MB まで)。');
    }
    if (!km_map_backup_is_locked($json)
        && preg_match('/\A\s*\{\s*"format"\s*:\s*"kosenmap-map"\s*,\s*"formatVersion"\s*:\s*[12]\b/', substr($json, 0, 200)) !== 1) {
        throw new InvalidArgumentException('ノードの書き出し(kosenmap-map)ではありません。');
    }
    if (!json_validate($json)) {
        throw new InvalidArgumentException('JSON として読めません。');
    }
}

/**
 * 1 件置く。古いものを片付けたうえで、置いたものの情報を返す。
 *
 * @param bool $withLearning Wi-Fi 学習を含む(アプリが送る印。一覧に出すだけで、サーバーは中身を読まない)
 * @return array{id:int, createdAt:int, bytes:int, sha256:string, nodeCount:?int, note:string, withLearning:bool, locked:bool}
 */
function km_map_backup_create(PDO $pdo, string $json, string $userSub, string $userName, ?int $nodeCount, string $note, bool $withLearning = false): array
{
    km_map_backup_ensure_table($pdo);
    km_map_backup_validate($json);
    $note = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $note) ?? '');
    if (mb_strlen($note) > KM_MAP_BACKUP_NOTE_MAX) {
        $note = mb_substr($note, 0, KM_MAP_BACKUP_NOTE_MAX);
    }
    if ($nodeCount !== null && ($nodeCount < 0 || $nodeCount > 1000000)) {
        $nodeCount = null;
    }

    $dir = km_map_backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('置き場を作れませんでした: ' . $dir);
    }
    $stored = bin2hex(random_bytes(16)) . '.json.gz';
    $gz = gzencode($json, 6);
    if ($gz === false || @file_put_contents($dir . '/' . $stored, $gz, LOCK_EX) !== strlen($gz)) {
        @unlink($dir . '/' . $stored);
        throw new RuntimeException('書き込めませんでした。');
    }
    $sha = hash('sha256', $json);
    $bytes = strlen($json);

    $pdo->prepare(
        'INSERT INTO km_map_backups (user_sub, user_name, created_at, bytes, sha256, stored_name, node_count, note)
         VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)'
    )->execute([mb_substr($userSub, 0, 64), mb_substr($userName, 0, 255), $bytes, $sha, $stored, $nodeCount, $note]);
    $id = (int) $pdo->lastInsertId();
    $locked = km_map_backup_is_locked($json);
    if ($withLearning || $locked) {
        $pdo->prepare('INSERT INTO km_map_backup_meta (backup_id, with_learning, locked) VALUES (?, ?, ?)')
            ->execute([$id, $withLearning ? 1 : 0, $locked ? 1 : 0]);
    }

    km_map_backup_prune($pdo);

    return ['id' => $id, 'createdAt' => time(), 'bytes' => $bytes, 'sha256' => $sha, 'nodeCount' => $nodeCount, 'note' => $note, 'withLearning' => $withLearning, 'locked' => $locked];
}

/** 置いた人ごとに、新しい方から KM_MAP_BACKUP_KEEP 件を残し、残りはファイルごと消す。 */
function km_map_backup_prune(PDO $pdo, int $keep = KM_MAP_BACKUP_KEEP): int
{
    km_map_backup_ensure_table($pdo);
    $rows = $pdo->query('SELECT id, stored_name, user_sub FROM km_map_backups ORDER BY created_at DESC, id DESC')->fetchAll();
    $removed = 0;
    $seen = [];
    foreach ($rows as $row) {
        $owner = (string) $row['user_sub'];
        $seen[$owner] = ($seen[$owner] ?? 0) + 1;
        if ($seen[$owner] > $keep) {
            km_map_backup_remove_row($pdo, $row);
            $removed++;
        }
    }
    return $removed;
}

/**
 * @param ?string $onlyUserSub 学習の協力者が見るとき、その人の sub(その人が置いた分だけ)。null は全員の分(管理者)
 * @return list<array{id:int, createdAt:int, bytes:int, sha256:string, nodeCount:?int, note:string, by:string, withLearning:bool, locked:bool}>
 */
function km_map_backup_list(PDO $pdo, ?string $onlyUserSub = null): array
{
    km_map_backup_ensure_table($pdo);
    $sql = 'SELECT b.id, UNIX_TIMESTAMP(b.created_at) AS createdAt, b.bytes, b.sha256, b.node_count AS nodeCount, b.note, b.user_name AS userName,
                   COALESCE(m.with_learning, 0) AS withLearning, COALESCE(m.locked, 0) AS locked
            FROM km_map_backups b LEFT JOIN km_map_backup_meta m ON m.backup_id = b.id'
        . ($onlyUserSub !== null ? ' WHERE b.user_sub = ?' : '')
        . ' ORDER BY b.created_at DESC, b.id DESC';
    $statement = $pdo->prepare($sql);
    $statement->execute($onlyUserSub !== null ? [$onlyUserSub] : []);
    $rows = $statement->fetchAll();

    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'createdAt' => (int) $r['createdAt'],
        'bytes' => (int) $r['bytes'],
        'sha256' => (string) $r['sha256'],
        'nodeCount' => $r['nodeCount'] === null ? null : (int) $r['nodeCount'],
        'note' => (string) $r['note'],
        'by' => (string) $r['userName'],
        'withLearning' => (int) $r['withLearning'] === 1,
        'locked' => (int) $r['locked'] === 1,
    ], $rows);
}

/** @return array{id:int, stored_name:string, sha256:string, bytes:int, user_sub:string}|null */
function km_map_backup_find(PDO $pdo, int $id): ?array
{
    km_map_backup_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT id, stored_name, sha256, bytes, user_sub FROM km_map_backups WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 中身(解凍した JSON)を読む。SHA-256 を確かめる(2026-10-23。管理画面「バックアップ」で見る・落とす)。
 */
function km_map_backup_read(array $row): string
{
    $path = km_map_backup_path($row);
    $gz = is_file($path) ? (string) file_get_contents($path) : '';
    $json = $gz === '' ? false : gzdecode($gz);
    if (!is_string($json) || !hash_equals((string) $row['sha256'], hash('sha256', $json))) {
        throw new RuntimeException('バックアップのファイルが見つからないか、壊れています。');
    }
    return $json;
}

/**
 * 内訳(階ごとの地点の数・線・イベント・氏名の入った地点の数)。**純粋関数**(scripts/check.php が試す)。
 * 読めなければ null。
 *
 * @return array{floors:array<string,int>, nodes:int, lines:int, events:int, occupants:int, fingerprints:int}|null
 */
function km_map_backup_summary(string $json): ?array
{
    require_once __DIR__ . '/app-map.php';
    $map = km_app_map_decode_snapshot($json);
    if ($map === null) {
        return null;
    }
    $floors = [];
    $occupants = 0;
    foreach ((array) $map->nodes as $node) {
        if (!($node instanceof stdClass)) {
            continue;
        }
        $floor = (string) ($node->floor ?? '?');
        $floors[$floor] = ($floors[$floor] ?? 0) + 1;
        if (trim((string) ($node->occupantName ?? '')) !== '') {
            $occupants++;
        }
    }
    ksort($floors, SORT_NATURAL);

    return [
        'floors' => $floors,
        'nodes' => array_sum($floors),
        'lines' => count((array) $map->lines),
        'events' => isset($map->events) && is_array($map->events) ? count($map->events) : 0,
        'occupants' => $occupants,
        // Wi-Fi 学習の地点(2026-10-23。学習を含むバックアップ)
        'fingerprints' => isset($map->fingerprints) && is_array($map->fingerprints) ? count($map->fingerprints) : 0,
    ];
}

/** 中身のファイルのパス。**保存名は表からだけ取る**(利用者の入力をパスに使わない)。 */
function km_map_backup_path(array $row): string
{
    $name = (string) $row['stored_name'];
    if (preg_match('/\A[0-9a-f]{32}\.json\.gz\z/', $name) !== 1) {
        throw new RuntimeException('保存名が不正です。');
    }
    return km_map_backup_dir() . '/' . $name;
}

function km_map_backup_delete(PDO $pdo, int $id): bool
{
    $row = km_map_backup_find($pdo, $id);
    if ($row === null) {
        return false;
    }
    km_map_backup_remove_row($pdo, $row);
    return true;
}

function km_map_backup_remove_row(PDO $pdo, array $row): void
{
    try {
        $path = km_map_backup_path($row);
        if (is_file($path)) {
            @unlink($path);
        }
    } catch (RuntimeException $exception) {
        error_log('km_map_backup_remove_row: ' . $exception->getMessage());
    }
    $pdo->prepare('DELETE FROM km_map_backups WHERE id = ?')->execute([(int) $row['id']]);
    $pdo->prepare('DELETE FROM km_map_backup_meta WHERE backup_id = ?')->execute([(int) $row['id']]);
}
