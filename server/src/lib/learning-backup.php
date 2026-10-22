<?php

declare(strict_types=1);

/**
 * Wi-Fi の学習データを**消す前の控え**(2026-10-23、利用者の指示「Wi-Fi データは初期化する。サーバーでもバックアップデータを見れるように」)。
 *
 * - 管理アプリの「学習データを初期化」などでサーバーの記録を消すとき(lib/learning-data.php の km_learning_delete)、
 *   **消す行の中身を先にここへ書く。書けなければ消さない**
 * - 形は配信(km_learning_changes)と同じ項目の JSON を gzip にして uploads/learning-backups/ に置く。表は km_learning_backups
 * - 管理画面「バックアップ」(admin/backups.php)で、一覧・中身の内訳・ダウンロード・**戻す**ができる
 *   (戻すは同じ uuid で入れ直す。km_learning_store は消した記録を生き返らせる)
 * - 残すのは新しい方から KM_LEARNING_BACKUP_KEEP 件
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';

const KM_LEARNING_BACKUP_KEEP = 30;
const KM_LEARNING_BACKUP_FORMAT = 'kosenmap-learning-backup';

function km_learning_backup_dir(): string
{
    return km_upload_dir() . '/learning-backups';
}

function km_learning_backup_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_learning_backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            kind VARCHAR(16) NOT NULL,
            scope VARCHAR(16) NOT NULL,
            floor_id VARCHAR(40) NULL,
            item_count INT UNSIGNED NOT NULL,
            bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            stored_name VARCHAR(64) NOT NULL,
            deleted_by VARCHAR(191) NULL,
            restored_at DATETIME NULL,
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

/**
 * 消す行を控えに書く。行が無ければ何もしない(null)。**書けなければ例外**(呼ぶ側は消さない)。
 *
 * @param 'samples'|'evaluations' $kind
 * @param list<array<string,mixed>> $items km_learning_changes の items と同じ形
 */
function km_learning_backup_save(PDO $pdo, string $kind, string $scope, ?string $floor, array $items, ?string $deletedBy): ?int
{
    if ($items === []) {
        return null;
    }
    km_learning_backup_ensure_table($pdo);
    $json = json_encode([
        'format' => KM_LEARNING_BACKUP_FORMAT,
        'formatVersion' => 1,
        'kind' => $kind,
        'scope' => $scope,
        'floor' => $floor,
        'createdAt' => date(DATE_ATOM),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $dir = km_learning_backup_dir();
    // 0755(2026-10-23)。0750 だとグループが www-data になり、控え(host-backup.sh。kmops)が開けずに落ちた。uploads/ 自体は 750 なので外からは入れない
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('控えの置き場を作れませんでした: ' . $dir);
    }
    $stored = bin2hex(random_bytes(16)) . '.json.gz';
    $gz = gzencode($json, 6);
    if ($gz === false || @file_put_contents($dir . '/' . $stored, $gz, LOCK_EX) !== strlen($gz)) {
        @unlink($dir . '/' . $stored);
        throw new RuntimeException('控えを書き込めませんでした。');
    }
    $pdo->prepare(
        'INSERT INTO km_learning_backups (created_at, kind, scope, floor_id, item_count, bytes, sha256, stored_name, deleted_by)
         VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$kind, $scope, $floor, count($items), strlen($json), hash('sha256', $json), $stored, $deletedBy === null ? null : mb_substr($deletedBy, 0, 191)]);
    $id = (int) $pdo->lastInsertId();
    km_learning_backup_prune($pdo);

    return $id;
}

/** 新しい方から [$keep] 件を残し、残りはファイルごと消す。 */
function km_learning_backup_prune(PDO $pdo, int $keep = KM_LEARNING_BACKUP_KEEP): int
{
    km_learning_backup_ensure_table($pdo);
    $rows = $pdo->query('SELECT id, stored_name FROM km_learning_backups ORDER BY created_at DESC, id DESC')->fetchAll();
    $removed = 0;
    foreach (array_slice($rows, $keep) as $row) {
        try {
            $path = km_learning_backup_path($row);
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (RuntimeException $exception) {
            error_log('km_learning_backup_prune: ' . $exception->getMessage());
        }
        $pdo->prepare('DELETE FROM km_learning_backups WHERE id = ?')->execute([(int) $row['id']]);
        $removed++;
    }
    return $removed;
}

/** @return list<array{id:int, createdAt:string, kind:string, scope:string, floor:?string, count:int, bytes:int, sha256:string, by:string, restoredAt:?string}> */
function km_learning_backup_list(PDO $pdo): array
{
    km_learning_backup_ensure_table($pdo);
    $rows = $pdo->query(
        'SELECT id, created_at, kind, scope, floor_id, item_count, bytes, sha256, deleted_by, restored_at
         FROM km_learning_backups ORDER BY created_at DESC, id DESC'
    )->fetchAll();

    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'createdAt' => (string) $r['created_at'],
        'kind' => (string) $r['kind'],
        'scope' => (string) $r['scope'],
        'floor' => $r['floor_id'] === null ? null : (string) $r['floor_id'],
        'count' => (int) $r['item_count'],
        'bytes' => (int) $r['bytes'],
        'sha256' => (string) $r['sha256'],
        'by' => (string) ($r['deleted_by'] ?? ''),
        'restoredAt' => $r['restored_at'] === null ? null : (string) $r['restored_at'],
    ], $rows);
}

/** @return array<string,mixed>|null */
function km_learning_backup_find(PDO $pdo, int $id): ?array
{
    km_learning_backup_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_learning_backups WHERE id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

/** 中身のファイルのパス。**保存名は表からだけ取る**(利用者の入力をパスに使わない)。 */
function km_learning_backup_path(array $row): string
{
    $name = (string) $row['stored_name'];
    if (preg_match('/\A[0-9a-f]{32}\.json\.gz\z/', $name) !== 1) {
        throw new RuntimeException('保存名が不正です。');
    }
    return km_learning_backup_dir() . '/' . $name;
}

/**
 * 中身を読む(SHA-256 を確かめる)。
 *
 * @return array{kind:string, items:list<array<string,mixed>>}
 */
function km_learning_backup_read(array $row): array
{
    $path = km_learning_backup_path($row);
    $gz = is_file($path) ? (string) file_get_contents($path) : '';
    $json = $gz === '' ? false : gzdecode($gz);
    if (!is_string($json) || !hash_equals((string) $row['sha256'], hash('sha256', $json))) {
        throw new RuntimeException('控えのファイルが見つからないか、壊れています。');
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || ($decoded['format'] ?? '') !== KM_LEARNING_BACKUP_FORMAT || !is_array($decoded['items'] ?? null)) {
        throw new RuntimeException('控えの形が違います。');
    }
    return ['kind' => (string) $decoded['kind'], 'items' => array_values(array_filter($decoded['items'], 'is_array'))];
}

/**
 * 内訳(階ごとの数・地点の数・測った期間)。管理画面の「中身」に出す。**純粋関数**(scripts/check.php が試す)。
 *
 * @param list<array<string,mixed>> $items
 * @return array{floors:array<string,int>, nodes:int, from:?int, to:?int}
 */
function km_learning_backup_summary(array $items): array
{
    $floors = [];
    $nodes = [];
    $from = null;
    $to = null;
    foreach ($items as $item) {
        $floor = (string) ($item['floor'] ?? '?');
        $floors[$floor] = ($floors[$floor] ?? 0) + 1;
        $nodes[(string) ($item['nodeUuid'] ?? '')] = true;
        $at = $item['measuredAtMillis'] ?? null;
        if (is_int($at)) {
            $from = $from === null ? $at : min($from, $at);
            $to = $to === null ? $at : max($to, $at);
        }
    }
    ksort($floors, SORT_NATURAL);

    return ['floors' => $floors, 'nodes' => count($nodes), 'from' => $from, 'to' => $to];
}

/**
 * 控えの記録をサーバーへ戻す(同じ uuid で入れ直す。消した記録は生き返る)。戻した数を返す。
 * 端末は次の同期で受け取る(通し番号が振り直されるため)。
 */
function km_learning_backup_restore(PDO $pdo, int $id, ?string $by): int
{
    require_once __DIR__ . '/learning-data.php';
    $row = km_learning_backup_find($pdo, $id);
    if ($row === null) {
        throw new InvalidArgumentException('その控えはありません(古いものは自動で消えます)。');
    }
    $backup = km_learning_backup_read($row);
    $samples = [];
    $evaluations = [];
    foreach ($backup['items'] as $item) {
        try {
            if ($backup['kind'] === 'samples') {
                $samples[] = km_learning_normalize_sample($item);
            } else {
                $evaluations[] = km_learning_normalize_evaluation($item);
            }
        } catch (InvalidArgumentException) {
            // 形の崩れた 1 件は飛ばす(ほかは戻す)
        }
    }
    $stored = km_learning_store($pdo, $samples, $evaluations, $by);
    $pdo->prepare('UPDATE km_learning_backups SET restored_at = NOW() WHERE id = ?')->execute([$id]);

    return $stored['samples'] + $stored['evaluations'];
}
