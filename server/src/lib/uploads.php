<?php

declare(strict_types=1);

/**
 * ファイルのアップロード(admin/file-manager.php)。
 *
 * **置き場所が一番の論点。** src/ は 9443 のドキュメントルート(./src:/var/www/html)なので、
 * 素直に置くとアップロードされた .php が Apache に実行されてしまう。対策は二重にしてある:
 *
 *   1. nginx: default.conf の `location ~ ^/(lib|config|scripts|uploads)/ { return 404; }` で
 *      実ファイルを直接配信させない。ダウンロードは admin/api/file-download.php 経由だけ
 *   2. 保存名: 元のファイル名は**パスに一切使わない**。ランダムな名前 + 許可された拡張子に
 *      作り直す(パストラバーサルも二重拡張子も、そもそも成立しない)
 *
 * 元のファイル名は DB の列にだけ持ち、表示とダウンロード時のファイル名に使う。
 */

require_once __DIR__ . '/db.php';

/** 許可する拡張子。ここに無いものは拒否する(実行可能なものは入れない)。 */
const KM_UPLOAD_ALLOWED_EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'csv', 'zip'];

/** 1ファイルの上限(バイト)。 */
const KM_UPLOAD_MAX_BYTES = 10 * 1024 * 1024;

function km_upload_dir(): string
{
    return __DIR__ . '/../uploads';
}

function km_uploads_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(64) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            extension VARCHAR(16) NOT NULL,
            uploaded_by VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY (stored_name),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** バイト数を人が読める形へ。表示のためだけに使う(DB には整数で持つ)。 */
function km_upload_format_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }

    return $bytes . ' B';
}

/**
 * $_FILES の1件を受け取って保存する。
 *
 * @param array $file $_FILES['...'] の形
 */
function km_upload_store(PDO $pdo, array $file, ?string $uploadedBy): void
{
    km_uploads_ensure_table($pdo);

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('ファイルが選択されていません。');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('ファイルが大きすぎます。');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('アップロードに失敗しました(コード ' . $error . ')。');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    // ブラウザ以外から直接叩かれた場合に備え、本当にアップロードされたファイルかを確認する
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('アップロードされたファイルを確認できませんでした。');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new InvalidArgumentException('空のファイルは保存できません。');
    }
    if ($size > KM_UPLOAD_MAX_BYTES) {
        throw new InvalidArgumentException('ファイルは ' . km_upload_format_size(KM_UPLOAD_MAX_BYTES) . ' 以内にしてください。');
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, KM_UPLOAD_ALLOWED_EXTENSIONS, true)) {
        throw new InvalidArgumentException(
            'この拡張子は許可されていません(許可: ' . implode(', ', KM_UPLOAD_ALLOWED_EXTENSIONS) . ')。'
        );
    }

    $dir = km_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('保存先ディレクトリを作成できませんでした。');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('保存先ディレクトリに書き込めません。権限を確認してください。');
    }

    // 元の名前はパスに使わない。ランダム名 + 許可拡張子だけで組み立てる。
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('ファイルを保存できませんでした。');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO km_files (original_name, stored_name, size_bytes, extension, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([mb_substr($originalName, 0, 255), $storedName, $size, $extension, $uploadedBy]);
    } catch (Throwable $exception) {
        // DB に登録できなかったら実体も残さない(参照できない孤児を作らない)
        @unlink($dir . DIRECTORY_SEPARATOR . $storedName);
        throw $exception;
    }
}

/**
 * 時刻は UNIX_TIMESTAMP() の epoch で返す(フェーズ5・9の教訓)。
 *
 * @return array<int, array{id:int, originalName:string, storedName:string, sizeBytes:int,
 *                          extension:string, uploadedBy:?string, createdAtEpoch:int}>
 */
function km_uploads_all(PDO $pdo): array
{
    km_uploads_ensure_table($pdo);

    return $pdo->query(
        'SELECT id, original_name AS originalName, stored_name AS storedName, size_bytes AS sizeBytes,
                extension, uploaded_by AS uploadedBy, UNIX_TIMESTAMP(created_at) AS createdAtEpoch
         FROM km_files ORDER BY id DESC'
    )->fetchAll();
}

/** @return array{id:int, originalName:string, storedName:string, extension:string}|null */
function km_upload_find(PDO $pdo, int $id): ?array
{
    km_uploads_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, original_name AS originalName, stored_name AS storedName, extension
         FROM km_files WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function km_upload_delete(PDO $pdo, int $id): void
{
    $file = km_upload_find($pdo, $id);
    if ($file === null) {
        throw new InvalidArgumentException('ファイルが見つかりません。');
    }

    // stored_name は自分で作ったランダム名なので、ここで結合しても外から細工されようがない
    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $file['storedName'];

    /*
     * 実体の削除に失敗しても DB の行は消す(利用者は「消した」つもりなので、
     * 一覧に残り続ける方が困る)。ただし**黙って捨てない** — 消せなかった実体は
     * どこからも参照されない孤児として残るので、必ずログに出して気付けるようにする。
     * (Windows では他プロセスが開いている間 unlink が失敗することがある。
     *  本番の Linux では通常成功する。)
     */
    if (is_file($path) && !@unlink($path)) {
        error_log('km_upload_delete: DB row removed but file remains on disk: ' . $file['storedName']);
    }

    $pdo->prepare('DELETE FROM km_files WHERE id = ?')->execute([$id]);
}
