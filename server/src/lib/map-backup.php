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
 * - **サーバーは中身を読み解かない**(json_validate で形だけ確かめる。地点の数はアプリが数えて送る)
 * - 残すのは新しい方から KM_MAP_BACKUP_KEEP 件。古いものはファイルごと消す(控えの世代と同じ扱い)
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
    $ready = true;
}

/**
 * 中身の形を確かめる。**読み解かずに**、JSON として正しいことと、ノードの書き出しの頭であることだけを見る。
 * (アプリの encodeEnvelope は format → formatVersion の順に書く。TransferFormat.kt)
 */
function km_map_backup_validate(string $json): void
{
    if ($json === '' || strlen($json) > KM_MAP_BACKUP_MAX_BYTES) {
        throw new InvalidArgumentException('大きさが範囲外です(16MB まで)。');
    }
    if (preg_match('/\A\s*\{\s*"format"\s*:\s*"kosenmap-map"\s*,\s*"formatVersion"\s*:\s*[12]\b/', substr($json, 0, 200)) !== 1) {
        throw new InvalidArgumentException('ノードの書き出し(kosenmap-map)ではありません。');
    }
    if (!json_validate($json)) {
        throw new InvalidArgumentException('JSON として読めません。');
    }
}

/**
 * 1 件置く。古いものを片付けたうえで、置いたものの情報を返す。
 *
 * @return array{id:int, createdAt:int, bytes:int, sha256:string, nodeCount:?int, note:string}
 */
function km_map_backup_create(PDO $pdo, string $json, string $userSub, string $userName, ?int $nodeCount, string $note): array
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

    km_map_backup_prune($pdo);

    return ['id' => $id, 'createdAt' => time(), 'bytes' => $bytes, 'sha256' => $sha, 'nodeCount' => $nodeCount, 'note' => $note];
}

/** 新しい方から KM_MAP_BACKUP_KEEP 件を残し、残りはファイルごと消す。 */
function km_map_backup_prune(PDO $pdo, int $keep = KM_MAP_BACKUP_KEEP): int
{
    km_map_backup_ensure_table($pdo);
    $rows = $pdo->query('SELECT id, stored_name FROM km_map_backups ORDER BY created_at DESC, id DESC')->fetchAll();
    $removed = 0;
    foreach (array_slice($rows, $keep) as $row) {
        km_map_backup_remove_row($pdo, $row);
        $removed++;
    }
    return $removed;
}

/**
 * @return list<array{id:int, createdAt:int, bytes:int, sha256:string, nodeCount:?int, note:string, by:string}>
 */
function km_map_backup_list(PDO $pdo): array
{
    km_map_backup_ensure_table($pdo);
    $rows = $pdo->query(
        'SELECT id, UNIX_TIMESTAMP(created_at) AS createdAt, bytes, sha256, node_count AS nodeCount, note, user_name AS userName
         FROM km_map_backups ORDER BY created_at DESC, id DESC'
    )->fetchAll();

    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'createdAt' => (int) $r['createdAt'],
        'bytes' => (int) $r['bytes'],
        'sha256' => (string) $r['sha256'],
        'nodeCount' => $r['nodeCount'] === null ? null : (int) $r['nodeCount'],
        'note' => (string) $r['note'],
        'by' => (string) $r['userName'],
    ], $rows);
}

/** @return array{id:int, stored_name:string, sha256:string, bytes:int}|null */
function km_map_backup_find(PDO $pdo, int $id): ?array
{
    km_map_backup_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT id, stored_name, sha256, bytes FROM km_map_backups WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
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
}
