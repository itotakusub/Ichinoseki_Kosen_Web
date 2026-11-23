<?php

declare(strict_types=1);

/**
 * Web Code Editor のプロジェクトをサーバーに預かる(段 10-B。2026-11-08、利用者の指示「サインインしてサーバーにも」)。
 * 口は api/editor.php。画面は editor-src/src/project/sync.js。規約は第8条の3(lib/legal.php)。
 *
 * **作業の正本はブラウザ(IndexedDB)。**サーバーはサインインした人のプロジェクトを預かるだけで、中身は開かない・動かさない。
 *
 *   表   km_editor_projects(新しい表。CREATE TABLE IF NOT EXISTS)
 *   置き場 uploads/editor/<public_id>/blobs/<sha256> と manifest.json({entry, folders, files: {パス: {h, s}}})
 *
 * 送り方(sync.js):
 *   - 目録(パス → ハッシュと大きさ)をまるごと送り、**中身はサーバーに無いものだけ**を zip に入れて送る。
 *     zip の中の名前は**ハッシュそのもの**(パスは目録だけが持つ。上げた名前をパスに使わない = HTML 展開と同じ考え)
 *   - 受けた中身は sha256 を計り直し、名前と合わないもの・目録に無いものは捨てる
 *   - 目録のハッシュが揃わなければ 409 と足りない一覧(missing)。版(revision)が違えば 409(conflict)
 *   - 中身が前と同じ(目録・名前・入口・フォルダ)なら版を上げない
 * 上限: 1 人 30 プロジェクト・合計 200MB。1 プロジェクト 20MB・500 ファイル(画面の model.js と同じ)。1 回の本文 20MB
 */

require_once __DIR__ . '/html-host.php';   // KM_HTML_TYPES・km_html_clean_path・km_html_ensure_dir・km_html_remove_dir・km_html_clean_name
require_once __DIR__ . '/zip-read.php';

const KM_EDITOR_MAX_PROJECTS = 30;
const KM_EDITOR_MAX_OWNER_BYTES = 200 * 1024 * 1024;
const KM_EDITOR_MAX_PROJECT_BYTES = 20 * 1024 * 1024;
const KM_EDITOR_MAX_FILES = 500;
const KM_EDITOR_MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
const KM_EDITOR_MAX_FOLDERS = 200;
const KM_EDITOR_NAME_MAX = 100;

/**
 * エディタだけで使える拡張子(段 11。2026-11-08。VS Code 型のエディタ /code/ で書く言語)。
 * **保存して返すだけで、どこでも実行しない**(中身は blobs/<sha256> に拡張子なしで置き、返すときは
 * octet-stream・attachment・CSP sandbox)。HTML 展開の型の表(KM_HTML_TYPES)には足さない
 * (向こうは上げたものを見せるので、php・py・sh などを決して入れない。check.php が見張る)。
 * 画面の表は editor-src/src/project/model.js の TYPES(同じ並び)
 */
const KM_EDITOR_EXTRA_TYPES = [
    'ts', 'tsx', 'jsx', 'cjs', 'mts', 'cts',
    'py', 'ipynb', 'java', 'php', 'sql',
    'ps1', 'psm1', 'psd1', 'bat', 'cmd', 'sh', 'bash',
    'yaml', 'yml', 'toml', 'ini', 'scss', 'less', 'log',
];

/** エディタのプロジェクトに置ける拡張子か(HTML 展開の表 + エディタだけの表) */
function km_editor_type_allowed(string $extension): bool
{
    return isset(KM_HTML_TYPES[$extension]) || in_array($extension, KM_EDITOR_EXTRA_TYPES, true);
}

/** 409 で返す誤り(足りない中身・版のぶつかり)。api/editor.php が形を作る */
final class KmEditorConflict extends RuntimeException
{
    /** @param array<string, mixed> $detail */
    public function __construct(string $message, public readonly array $detail)
    {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------------------
// 純粋関数(scripts/check.php の editor-sync が試す)
// ---------------------------------------------------------------------------

function km_editor_id_valid(string $id): bool
{
    return preg_match('/^[0-9a-f]{24}$/', $id) === 1;
}

function km_editor_hash_valid(string $hash): bool
{
    return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
}

/**
 * 画面から来た目録を確かめて整える。パスは HTML 展開と同じ決まり(km_html_clean_path)・型の表に無い拡張子は断る・
 * 大文字小文字だけ違う同じ名前は断る・数と合計の上限。
 *
 * @return array<string, array{h:string, s:int}> パスの順
 */
function km_editor_clean_manifest(mixed $raw): array
{
    if (!is_array($raw)) {
        throw new InvalidArgumentException('目録の形が違います。');
    }
    if (count($raw) > KM_EDITOR_MAX_FILES) {
        throw new InvalidArgumentException('ファイルは ' . KM_EDITOR_MAX_FILES . ' 個までです。');
    }
    $out = [];
    $lower = [];
    $total = 0;
    foreach ($raw as $path => $item) {
        $path = km_html_clean_path((string) $path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!km_editor_type_allowed($extension)) {
            throw new InvalidArgumentException('使えない拡張子のファイルがあります(' . mb_substr($path, 0, 80) . ')。');
        }
        $key = mb_strtolower($path);
        if (isset($lower[$key])) {
            throw new InvalidArgumentException('大文字と小文字だけが違う同じ名前があります(' . mb_substr($path, 0, 80) . ')。');
        }
        $lower[$key] = true;
        $hash = is_array($item) ? (string) ($item['h'] ?? '') : '';
        $size = is_array($item) ? ($item['s'] ?? null) : null;
        if (!km_editor_hash_valid($hash) || !is_int($size) || $size < 0) {
            throw new InvalidArgumentException('目録の形が違います(' . mb_substr($path, 0, 80) . ')。');
        }
        $total += $size;
        if ($total > KM_EDITOR_MAX_PROJECT_BYTES) {
            throw new InvalidArgumentException('1 つのプロジェクトは ' . intdiv(KM_EDITOR_MAX_PROJECT_BYTES, 1024 * 1024) . 'MB までです。');
        }
        $out[$path] = ['h' => $hash, 's' => $size];
    }
    ksort($out, SORT_STRING);

    return $out;
}

/** 空のフォルダの一覧(パスの決まりは同じ。拡張子は見ない) @return list<string> */
function km_editor_clean_folders(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach (array_slice(array_values($raw), 0, KM_EDITOR_MAX_FOLDERS) as $folder) {
        $out[] = km_html_clean_path((string) $folder);
    }
    $out = array_values(array_unique($out));
    sort($out, SORT_STRING);

    return $out;
}

/** 入口: 目録の中の HTML なら、それ。そうでなければ空 */
function km_editor_clean_entry(string $entry, array $manifest): string
{
    return isset($manifest[$entry]) && km_html_is_html($entry) ? $entry : '';
}

/** 目録が指すハッシュのうち、置き場に無いもの @param array<string, true> $have */
function km_editor_missing(array $manifest, array $have): array
{
    $missing = [];
    foreach ($manifest as $item) {
        if (!isset($have[$item['h']])) {
            $missing[$item['h']] = true;
        }
    }

    return array_keys($missing);
}

/** 中身の合計(同じ中身は 1 つと数える = 置き場で使う量) */
function km_editor_unique_bytes(array $manifest): int
{
    $seen = [];
    foreach ($manifest as $item) {
        $seen[$item['h']] = (int) $item['s'];
    }

    return array_sum($seen);
}

// ---------------------------------------------------------------------------
// 置き場
// ---------------------------------------------------------------------------

/** uploads/editor(夜の控えに入る) */
function km_editor_root(): string
{
    return __DIR__ . '/../uploads/editor';
}

function km_editor_dir(string $publicId): string
{
    if (!km_editor_id_valid($publicId)) {
        throw new InvalidArgumentException('プロジェクトが見つかりません。');
    }

    return km_editor_root() . '/' . $publicId;
}

/** @return array<string, true> 置き場にある中身のハッシュ */
function km_editor_blobs_have(string $dir): array
{
    $have = [];
    foreach (glob($dir . '/blobs/*') ?: [] as $blob) {
        $name = basename($blob);
        if (km_editor_hash_valid($name)) {
            $have[$name] = true;
        }
    }

    return $have;
}

/**
 * 上げた zip の中身を置く。名前(= ハッシュ)と中身が合い、目録が指すものだけ。置いた数を返す
 *
 * @param array<string, true> $wanted 目録のハッシュ
 */
function km_editor_store_upload(string $dir, string $zipPath, array $wanted): int
{
    $size = is_file($zipPath) ? (int) filesize($zipPath) : 0;
    if ($size <= 0) {
        return 0;
    }
    if ($size > KM_EDITOR_MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('1 回に送れるのは ' . intdiv(KM_EDITOR_MAX_UPLOAD_BYTES, 1024 * 1024) . 'MB までです。');
    }
    $entries = km_zip_read_all($zipPath, KM_EDITOR_MAX_FILES, KM_EDITOR_MAX_PROJECT_BYTES, KM_EDITOR_MAX_PROJECT_BYTES, true);
    km_html_ensure_dir($dir . '/blobs');
    $stored = 0;
    foreach ($entries as $entry) {
        $name = (string) $entry['name'];
        $bytes = (string) $entry['bytes'];
        if (!km_editor_hash_valid($name) || !isset($wanted[$name]) || !hash_equals($name, hash('sha256', $bytes))) {
            continue;
        }
        $target = $dir . '/blobs/' . $name;
        if (is_file($target)) {
            continue;
        }
        $temp = $target . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException('ファイルを書けませんでした(空きが足りないかもしれません)。');
        }
        $stored++;
    }

    return $stored;
}

/** @return array{entry:string, folders:list<string>, files:array<string, array{h:string, s:int}>}|null */
function km_editor_read_manifest(string $dir): ?array
{
    $file = $dir . '/manifest.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !is_array($data['files'] ?? null)) {
        return null;
    }

    return [
        'entry' => (string) ($data['entry'] ?? ''),
        'folders' => array_values(array_filter((array) ($data['folders'] ?? []), 'is_string')),
        'files' => $data['files'],
    ];
}

function km_editor_write_manifest(string $dir, string $entry, array $folders, array $files): void
{
    $json = json_encode(['entry' => $entry, 'folders' => $folders, 'files' => (object) $files], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $file = $dir . '/manifest.json';
    $temp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (!is_string($json) || @file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !@rename($temp, $file)) {
        @unlink($temp);
        throw new RuntimeException('目録を書けませんでした。');
    }
}

/** 目録から指されていない中身を消す(保存のたび) */
function km_editor_prune(string $dir, array $files): void
{
    $used = [];
    foreach ($files as $item) {
        $used[$item['h']] = true;
    }
    foreach (glob($dir . '/blobs/*') ?: [] as $blob) {
        if (!isset($used[basename($blob)])) {
            @unlink($blob);
        }
    }
}

// ---------------------------------------------------------------------------
// 表
// ---------------------------------------------------------------------------

function km_editor_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_editor_projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(24) NOT NULL,
            owner_sub VARCHAR(191) NOT NULL,
            name VARCHAR(100) NOT NULL,
            revision INT NOT NULL DEFAULT 0,
            entry VARCHAR(255) NOT NULL DEFAULT '',
            file_count INT NOT NULL DEFAULT 0,
            bytes BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY (public_id),
            INDEX (owner_sub)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

/** @return array<string, mixed> */
function km_editor_row(array $row): array
{
    return [
        'id' => (string) $row['public_id'],
        'dbId' => (int) $row['id'],
        'ownerSub' => (string) $row['owner_sub'],
        'name' => (string) $row['name'],
        'revision' => (int) $row['revision'],
        'entry' => (string) $row['entry'],
        'files' => (int) $row['file_count'],
        'bytes' => (int) $row['bytes'],
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

/** 画面に返す形(持ち主の sub・表の番号は返さない) */
function km_editor_out(array $project): array
{
    return [
        'id' => $project['id'],
        'name' => $project['name'],
        'revision' => $project['revision'],
        'entry' => $project['entry'],
        'files' => $project['files'],
        'bytes' => $project['bytes'],
        'updatedAt' => $project['updatedAt'],
    ];
}

/** 自分のプロジェクトだけ(他人のもの・無いものは同じ「見つかりません」) */
function km_editor_find_own(PDO $pdo, string $ownerSub, string $publicId, bool $forUpdate = false): array
{
    km_editor_ensure_tables($pdo);
    if ($ownerSub === '' || !km_editor_id_valid($publicId)) {
        throw new InvalidArgumentException('プロジェクトが見つかりません。');
    }
    $statement = $pdo->prepare('SELECT * FROM km_editor_projects WHERE public_id = ? AND owner_sub = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $statement->execute([$publicId, $ownerSub]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new InvalidArgumentException('プロジェクトが見つかりません。');
    }

    return km_editor_row($row);
}

/** @return list<array<string, mixed>> 新しく直した順 */
function km_editor_projects_of(PDO $pdo, string $ownerSub): array
{
    km_editor_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_editor_projects WHERE owner_sub = ? ORDER BY updated_at DESC, id DESC');
    $statement->execute([$ownerSub]);

    return array_map('km_editor_row', $statement->fetchAll(PDO::FETCH_ASSOC));
}

function km_editor_owner_bytes(PDO $pdo, string $ownerSub, int $exceptDbId = 0): int
{
    km_editor_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT COALESCE(SUM(bytes), 0) FROM km_editor_projects WHERE owner_sub = ? AND id <> ?');
    $statement->execute([$ownerSub, $exceptDbId]);

    return (int) $statement->fetchColumn();
}

/**
 * 保存する。$publicId が空なら新しいプロジェクト(1 人 KM_EDITOR_MAX_PROJECTS まで)。
 *
 * @param array<string, array{h:string, s:int}> $files km_editor_clean_manifest の結果
 * @return array{project: array<string, mixed>, changed: bool}
 * @throws KmEditorConflict 版が違う(conflict)・中身が足りない(missing)
 */
function km_editor_save(PDO $pdo, string $ownerSub, string $publicId, int $baseRevision, string $name, string $entry, array $folders, array $files, ?string $zipPath): array
{
    km_editor_ensure_tables($pdo);
    if ($ownerSub === '') {
        throw new InvalidArgumentException('サインインしてください。');
    }
    $name = mb_substr(km_html_clean_name($name, 'Project'), 0, KM_EDITOR_NAME_MAX);
    $entry = km_editor_clean_entry($entry, $files);
    $isNew = $publicId === '';
    if ($isNew) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM km_editor_projects WHERE owner_sub = ?');
        $count->execute([$ownerSub]);
        if ((int) $count->fetchColumn() >= KM_EDITOR_MAX_PROJECTS) {
            throw new InvalidArgumentException('サーバーに置けるプロジェクトは ' . KM_EDITOR_MAX_PROJECTS . ' 個までです。使わないものを消してください。');
        }
        $publicId = bin2hex(random_bytes(12));
    }
    $dir = km_editor_dir($publicId);
    $bytes = km_editor_unique_bytes($files);

    $pdo->beginTransaction();
    try {
        if ($isNew) {
            $pdo->prepare('INSERT INTO km_editor_projects (public_id, owner_sub, name, revision, entry, file_count, bytes, created_at, updated_at) VALUES (?, ?, ?, 0, ?, 0, 0, NOW(), NOW())')
                ->execute([$publicId, $ownerSub, $name, $entry]);
        }
        // 行を締めて版を確かめる(同じプロジェクトを 2 つの端末から同時に送っても、片方は 409 になる)
        $project = km_editor_find_own($pdo, $ownerSub, $publicId, true);
        if (!$isNew && $project['revision'] !== $baseRevision) {
            throw new KmEditorConflict('ほかの端末で変わっています。', ['conflict' => true, 'revision' => $project['revision'], 'updatedAt' => $project['updatedAt']]);
        }
        if (km_editor_owner_bytes($pdo, $ownerSub, $project['dbId']) + $bytes > KM_EDITOR_MAX_OWNER_BYTES) {
            throw new InvalidArgumentException('サーバーに置ける合計(' . intdiv(KM_EDITOR_MAX_OWNER_BYTES, 1024 * 1024) . 'MB)を超えます。使わないプロジェクトを消してください。');
        }
        $wanted = [];
        foreach ($files as $item) {
            $wanted[$item['h']] = true;
        }
        if ($zipPath !== null) {
            km_editor_store_upload($dir, $zipPath, $wanted);
        }
        $missing = km_editor_missing($files, km_editor_blobs_have($dir));
        if ($missing !== []) {
            throw new KmEditorConflict('中身が足りません。', ['missing' => $missing, 'revision' => $project['revision']]);
        }
        $before = km_editor_read_manifest($dir);
        $changed = $isNew || $before === null || $before['files'] != $files || $before['entry'] !== $entry || $before['folders'] !== $folders || $project['name'] !== $name;
        if ($changed) {
            km_html_ensure_dir($dir);
            km_editor_write_manifest($dir, $entry, $folders, $files);
            $pdo->prepare('UPDATE km_editor_projects SET name = ?, revision = revision + 1, entry = ?, file_count = ?, bytes = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$name, $entry, count($files), $bytes, $project['dbId']]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        if ($isNew) {
            km_html_remove_dir($dir);
        }
        throw $exception;
    }
    if ($changed) {
        km_editor_prune($dir, $files);
    }

    return ['project' => km_editor_find_own($pdo, $ownerSub, $publicId), 'changed' => $changed];
}

/** @return array{project: array<string, mixed>, entry:string, folders:list<string>, files:array<string, array{h:string, s:int}>} */
function km_editor_get(PDO $pdo, string $ownerSub, string $publicId): array
{
    $project = km_editor_find_own($pdo, $ownerSub, $publicId);
    $manifest = km_editor_read_manifest(km_editor_dir($publicId)) ?? ['entry' => '', 'folders' => [], 'files' => []];

    return ['project' => $project] + $manifest;
}

/** 中身を 1 つ(目録が指していて置き場にあるものだけ)。無ければ null */
function km_editor_blob_path(PDO $pdo, string $ownerSub, string $publicId, string $hash): ?string
{
    if (!km_editor_hash_valid($hash)) {
        return null;
    }
    km_editor_find_own($pdo, $ownerSub, $publicId);
    $dir = km_editor_dir($publicId);
    $manifest = km_editor_read_manifest($dir);
    if ($manifest === null || !in_array($hash, array_column($manifest['files'], 'h'), true)) {
        return null;
    }
    $path = $dir . '/blobs/' . $hash;

    return is_file($path) ? $path : null;
}

function km_editor_rename(PDO $pdo, string $ownerSub, string $publicId, string $name): array
{
    $project = km_editor_find_own($pdo, $ownerSub, $publicId);
    $name = mb_substr(km_html_clean_name($name, $project['name']), 0, KM_EDITOR_NAME_MAX);
    $pdo->prepare('UPDATE km_editor_projects SET name = ?, revision = revision + 1, updated_at = NOW() WHERE id = ?')->execute([$name, $project['dbId']]);

    return km_editor_find_own($pdo, $ownerSub, $publicId);
}

/** 行を消してからファイル */
function km_editor_delete(PDO $pdo, array $project): void
{
    $pdo->prepare('DELETE FROM km_editor_projects WHERE id = ?')->execute([$project['dbId']]);
    km_html_remove_dir(km_editor_dir($project['id']));
}

/** その人のプロジェクトを全部消す(アカウントの削除。lib/account-delete.php)。表が無ければ 0 */
function km_editor_projects_delete_owner(PDO $pdo, string $ownerSub): int
{
    try {
        $statement = $pdo->prepare('SELECT * FROM km_editor_projects WHERE owner_sub = ?');
        $statement->execute([$ownerSub]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), '1146') || str_contains($exception->getMessage(), 'Base table or view not found')) {
            return 0;
        }
        throw $exception;
    }
    foreach ($rows as $row) {
        km_editor_delete($pdo, km_editor_row($row));
    }

    return count($rows);
}
