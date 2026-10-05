<?php

declare(strict_types=1);

/**
 * ファイル管理の共有リンク(2026-10-05、利用者の指示)。
 *
 * 管理画面で上げたファイル(km_files)を、ログインしていない人へ**期限と回数を決めて**渡す。
 * 作り方は lib/map-guest.php(お試しの閲覧リンク)と同じ:
 *
 *   - トークンは random_bytes(24)。**表には sha256 だけ**を置き、URL は作った直後に 1 回だけ見せる
 *   - 回数は 1 本の UPDATE で数える(同時に押されても上限を超えない)
 *   - 受け口 /share.php は **GET では数えない**(メッセージアプリの下見が勝手に取りに来るため)。
 *     確認の画面を出し、ボタン(POST)で数えて渡す
 *   - nginx はこのページのクエリをログに残さない(km_no_query)
 *
 * ファイルを消したら、そのファイルのリンクも止める(km_file_share_revoke_for_file)。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';

/** 期限の選択肢(日)。管理画面の選択肢と対。 */
const KM_FILE_SHARE_DAY_CHOICES = [1, 7, 30];

/** 1 本のリンクで取れる回数の上限。 */
const KM_FILE_SHARE_MAX_USES = 100;

function km_file_share_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_file_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_by VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            max_uses INT NOT NULL,
            uses INT NOT NULL DEFAULT 0,
            revoked_at DATETIME NULL,
            UNIQUE KEY uniq_token (token_hash),
            INDEX (file_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

function km_file_share_token_valid(string $token): bool
{
    return preg_match('/^[0-9a-f]{48}$/', $token) === 1;
}

function km_file_share_token_hash(string $token): string
{
    // 用途の印を混ぜる(お試しリンクと同じトークンの形でも、表をまたいで当たらない)
    return hash('sha256', 'km-file-share|' . $token);
}

/**
 * リンクを作る。**戻り値の token はここでしか手に入らない。**
 *
 * @return array{id:int, token:string, expiresAt:int}
 */
function km_file_share_create(PDO $pdo, int $fileId, int $days, int $maxUses, ?string $createdBy): array
{
    if (!in_array($days, KM_FILE_SHARE_DAY_CHOICES, true)) {
        throw new InvalidArgumentException('期限の日数が正しくありません。');
    }
    if ($maxUses < 1 || $maxUses > KM_FILE_SHARE_MAX_USES) {
        throw new InvalidArgumentException('取れる回数は 1〜' . KM_FILE_SHARE_MAX_USES . ' にしてください。');
    }
    if (km_upload_find($pdo, $fileId) === null) {
        throw new InvalidArgumentException('ファイルが見つかりません。');
    }

    km_file_share_ensure_table($pdo);
    $token = bin2hex(random_bytes(24));
    $pdo->prepare(
        'INSERT INTO km_file_shares (file_id, token_hash, created_by, created_at, expires_at, max_uses)
         VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?)'
    )->execute([$fileId, km_file_share_token_hash($token), $createdBy, $days, $maxUses]);
    $id = (int) $pdo->lastInsertId();

    $expires = $pdo->prepare('SELECT UNIX_TIMESTAMP(expires_at) FROM km_file_shares WHERE id = ?');
    $expires->execute([$id]);

    return ['id' => $id, 'token' => $token, 'expiresAt' => (int) $expires->fetchColumn()];
}

/**
 * 管理画面の一覧: ファイルごとの、使えるリンク(期限内・取り消していない)。
 *
 * @return array<int, array<int, array{id:int, expiresAt:int, maxUses:int, uses:int}>>
 */
function km_file_share_active_by_file(PDO $pdo): array
{
    km_file_share_ensure_table($pdo);
    $rows = $pdo->query(
        'SELECT id, file_id, UNIX_TIMESTAMP(expires_at) AS expiresAt, max_uses AS maxUses, uses
           FROM km_file_shares
          WHERE revoked_at IS NULL AND expires_at > NOW()
          ORDER BY id DESC'
    );
    $byFile = [];
    foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
        $byFile[(int) $row['file_id']][] = [
            'id' => (int) $row['id'],
            'expiresAt' => (int) $row['expiresAt'],
            'maxUses' => (int) $row['maxUses'],
            'uses' => (int) $row['uses'],
        ];
    }

    return $byFile;
}

function km_file_share_revoke(PDO $pdo, int $id): bool
{
    km_file_share_ensure_table($pdo);
    $statement = $pdo->prepare('UPDATE km_file_shares SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
    $statement->execute([$id]);

    return $statement->rowCount() > 0;
}

/** ファイルを消したとき、そのファイルのリンクを全部止める。 */
function km_file_share_revoke_for_file(PDO $pdo, int $fileId): void
{
    km_file_share_ensure_table($pdo);
    $pdo->prepare('UPDATE km_file_shares SET revoked_at = NOW() WHERE file_id = ? AND revoked_at IS NULL')
        ->execute([$fileId]);
}

/**
 * リンクを確かめる(**数えない**)。確認の画面にファイル名と残りの回数を出すため。
 *
 * @return array{id:int, fileId:int, expiresAt:int, usesLeft:int, file:array}|null 使えなければ null
 */
function km_file_share_peek(PDO $pdo, string $token): ?array
{
    if (!km_file_share_token_valid($token)) {
        return null;
    }
    km_file_share_ensure_table($pdo);
    $statement = $pdo->prepare(
        'SELECT id, file_id, UNIX_TIMESTAMP(expires_at) AS expiresAt, GREATEST(max_uses - uses, 0) AS usesLeft
           FROM km_file_shares
          WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() AND uses < max_uses'
    );
    $statement->execute([km_file_share_token_hash($token)]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }
    $file = km_upload_find($pdo, (int) $row['file_id']);
    if ($file === null) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'fileId' => (int) $row['file_id'],
        'expiresAt' => (int) $row['expiresAt'],
        'usesLeft' => (int) $row['usesLeft'],
        'file' => $file,
    ];
}

/**
 * 1 回分を使う。**1 本の UPDATE で数える**(上限を超えない)。使えればファイルの行を返す。
 *
 * @return array{id:int, originalName:string, storedName:string, extension:string}|null
 */
function km_file_share_use(PDO $pdo, string $token): ?array
{
    $link = km_file_share_peek($pdo, $token);
    if ($link === null) {
        return null;
    }
    $use = $pdo->prepare(
        'UPDATE km_file_shares SET uses = uses + 1
          WHERE id = ? AND revoked_at IS NULL AND expires_at > NOW() AND uses < max_uses'
    );
    $use->execute([$link['id']]);

    return $use->rowCount() === 1 ? $link['file'] : null;
}

/** 渡す URL。**公開側のホスト**に向ける(管理画面を別オリジンにしていても)。 */
function km_file_share_url(string $token): string
{
    require_once __DIR__ . '/site.php';

    return km_site_url(null, '/share.php?t=' . rawurlencode($token));
}
