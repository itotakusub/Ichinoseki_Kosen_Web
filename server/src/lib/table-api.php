<?php

declare(strict_types=1);

/**
 * テーブル管理の外部接続(2026-10-21、利用者の指示「接続するユーザー、パスワードなどを自動で作成。権限は最小限で」)。
 *
 * ## MariaDB の利用者は作らない(利用者の判断: HTTPS の API キー)
 *
 * Web の DB 利用者 `Main` には利用者を作る権限(CREATE USER・GRANT)が無く、足すと Web から DB の権限を
 * 配れるようになる。MariaDB の 3306 番も外へ開いていない(学校からは 443 番しか出られない)。
 * そこで **443 番の `/api/table.php` を、接続ごとの「ユーザー名(接続 ID)とパスワード(キー)」で開ける**形にする。
 *
 * ## 最小限の権限
 *
 * - 1 つの接続で触れるのは **1 つの表(kmt_…)だけ**。システムの表(km_…)には届かない
 * - できることは接続ごとに選ぶ: 読む・足す・直す・消す。**既定は「読む + 足す」**(利用者の判断)
 * - 表を消した(論理削除)・接続を取り消したら、その場で使えなくなる
 * - キーは**作ったときに 1 度だけ画面に出す**。DB には SHA-256 の値だけを置く(キーは 192 ビットの乱数なので、
 *   遅いハッシュ(password_hash)にしなくても総当たりは成り立たない。毎回の照合を軽くする)
 * - 行の書き込みは操作の記録(km_admin_log)に残す。読みは残さない(最後に使った時刻だけ)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/table-manage.php';

/** 接続でできること(列名 → 画面の言葉) */
const KM_TABLE_API_PERMISSIONS = [
    'can_read' => '読む',
    'can_insert' => '足す',
    'can_update' => '直す',
    'can_delete' => '消す',
];
/** 既定(利用者の判断「読む + 足す」) */
const KM_TABLE_API_DEFAULT_PERMISSIONS = ['can_read', 'can_insert'];
/** 1 回に読む行の上限 */
const KM_TABLE_API_MAX_LIMIT = 500;
const KM_TABLE_API_LABEL_MAX = 100;

function km_table_api_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_table_api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(100) NOT NULL,
            key_id VARCHAR(32) NOT NULL,
            secret_hash CHAR(64) NOT NULL,
            table_name VARCHAR(41) NOT NULL,
            can_read TINYINT(1) NOT NULL DEFAULT 0,
            can_insert TINYINT(1) NOT NULL DEFAULT 0,
            can_update TINYINT(1) NOT NULL DEFAULT 0,
            can_delete TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY (key_id),
            INDEX (table_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

/** 接続 ID(ユーザー名)の形 */
function km_table_api_key_id_valid(string $keyId): bool
{
    return preg_match('/^kmx_[0-9a-f]{16}$/', $keyId) === 1;
}

/** キー(パスワード)の形 */
function km_table_api_secret_valid(string $secret): bool
{
    return preg_match('/^[0-9a-f]{48}$/', $secret) === 1;
}

/**
 * 接続を作る。**ユーザー名とキーはここで作る**(人に決めさせない)。キーを返すのはこの 1 回だけ。
 *
 * @param array<int, string> $permissions KM_TABLE_API_PERMISSIONS のキー
 * @return array{id:int, keyId:string, secret:string}
 */
function km_table_api_create(PDO $pdo, string $table, string $label, array $permissions, ?string $createdBy): array
{
    km_table_api_ensure_table($pdo);
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('接続の名前を入れてください(何から繋ぐのか分かる名前。例: 集計シート)。');
    }
    if (mb_strlen($label) > KM_TABLE_API_LABEL_MAX) {
        throw new InvalidArgumentException('接続の名前は ' . KM_TABLE_API_LABEL_MAX . ' 字までにしてください。');
    }
    // 管理している表で、消していないものだけ(km_table_columns が確かめる)
    km_table_columns($pdo, $table);
    $permissions = array_values(array_intersect(array_keys(KM_TABLE_API_PERMISSIONS), $permissions));
    if ($permissions === []) {
        throw new InvalidArgumentException('できることを 1 つ以上選んでください。');
    }

    $keyId = 'kmx_' . bin2hex(random_bytes(8));
    $secret = bin2hex(random_bytes(24));
    $pdo->prepare(
        'INSERT INTO km_table_api_keys (label, key_id, secret_hash, table_name, can_read, can_insert, can_update, can_delete, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([
        $label, $keyId, hash('sha256', $secret), $table,
        in_array('can_read', $permissions, true) ? 1 : 0,
        in_array('can_insert', $permissions, true) ? 1 : 0,
        in_array('can_update', $permissions, true) ? 1 : 0,
        in_array('can_delete', $permissions, true) ? 1 : 0,
        $createdBy,
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'keyId' => $keyId, 'secret' => $secret];
}

/** @return array<int, array<string, mixed>> 新しい順。取り消したものも含む */
function km_table_api_list(PDO $pdo): array
{
    km_table_api_ensure_table($pdo);

    return $pdo->query(
        'SELECT id, label, key_id AS keyId, table_name AS tableName, can_read, can_insert, can_update, can_delete,
                created_by AS createdBy, created_at AS createdAt, last_used_at AS lastUsedAt, revoked_at AS revokedAt
         FROM km_table_api_keys ORDER BY revoked_at IS NOT NULL, id DESC'
    )->fetchAll();
}

/** 取り消す(行は消さない。誰がいつ作った接続だったかを残す) */
function km_table_api_revoke(PDO $pdo, int $id): string
{
    km_table_api_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT label FROM km_table_api_keys WHERE id = ? AND revoked_at IS NULL');
    $statement->execute([$id]);
    $label = $statement->fetchColumn();
    if ($label === false) {
        throw new InvalidArgumentException('接続が見つかりません(もう取り消したかもしれません)。');
    }
    $pdo->prepare('UPDATE km_table_api_keys SET revoked_at = NOW() WHERE id = ?')->execute([$id]);

    return (string) $label;
}

/**
 * 送られてきたユーザー名とキーを取り出す。**DB を使わない**(scripts/check.php が試す)。
 *
 * 受ける形: HTTP の Basic 認証(ユーザー名 = 接続 ID、パスワード = キー)/ `Authorization: Bearer <接続 ID>:<キー>`。
 * **URL のクエリでは受けない**(アクセスログ・閲覧履歴・参照元に残る)。
 *
 * @param array<string, mixed> $server $_SERVER
 * @return array{0:string, 1:string}|null
 */
function km_table_api_credentials(array $server): ?array
{
    $user = $server['PHP_AUTH_USER'] ?? null;
    $pass = $server['PHP_AUTH_PW'] ?? null;
    if (!is_string($user) || !is_string($pass)) {
        $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $header, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            if (!is_string($decoded) || !str_contains($decoded, ':')) {
                return null;
            }
            [$user, $pass] = explode(':', $decoded, 2);
        } elseif (preg_match('/^Bearer\s+(kmx_[0-9a-f]{16}):([0-9a-f]{48})$/i', $header, $m) === 1) {
            [$user, $pass] = [$m[1], $m[2]];
        } else {
            return null;
        }
    }
    if (!km_table_api_key_id_valid($user) || !km_table_api_secret_valid($pass)) {
        return null;
    }

    return [$user, $pass];
}

/**
 * 接続を確かめる。取り消した接続・消した表なら null。
 *
 * @return array{id:int, label:string, table:string, can_read:bool, can_insert:bool, can_update:bool, can_delete:bool}|null
 */
function km_table_api_authenticate(PDO $pdo, string $keyId, string $secret): ?array
{
    try {
        $statement = $pdo->prepare(
            'SELECT id, label, secret_hash, table_name, can_read, can_insert, can_update, can_delete
             FROM km_table_api_keys WHERE key_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$keyId]);
        $row = $statement->fetch();
    } catch (Throwable $exception) {
        return null;   // 表がまだ無い = 接続はまだ 1 つも無い
    }
    // 見つからないときも同じだけ計算する(ユーザー名の有無を時間で漏らさない)
    $expected = $row === false ? str_repeat('0', 64) : (string) $row['secret_hash'];
    $ok = hash_equals($expected, hash('sha256', $secret));
    if ($row === false || !$ok) {
        return null;
    }
    $table = (string) $row['table_name'];
    if (!km_table_is_managed_name($table) || !km_table_exists($pdo, $table) || km_table_is_deleted($pdo, $table)) {
        return null;
    }
    // 最後に使った時刻(1 分に 1 回まで書く)
    $pdo->prepare(
        'UPDATE km_table_api_keys SET last_used_at = NOW()
         WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)'
    )->execute([(int) $row['id']]);

    return [
        'id' => (int) $row['id'],
        'label' => (string) $row['label'],
        'table' => $table,
        'can_read' => (bool) $row['can_read'],
        'can_insert' => (bool) $row['can_insert'],
        'can_update' => (bool) $row['can_update'],
        'can_delete' => (bool) $row['can_delete'],
    ];
}

/**
 * 書き込む値を整える。**DB を使わない**(scripts/check.php が試す)。
 * 列名は文字列、値は文字列・数・真偽・null だけ(入れ子は断る)。どの列があるかは km_table_row_insert / update が
 * 表の形と突き合わせる(知らない列は無視される)。
 *
 * @return array<string, string>
 */
function km_table_api_values(mixed $input): array
{
    if (!is_array($input) || $input === [] || array_is_list($input)) {
        throw new InvalidArgumentException('values には {"列名": 値, …} の形で 1 つ以上入れてください。');
    }
    $values = [];
    foreach ($input as $column => $value) {
        if (!is_string($column) || !km_is_valid_identifier($column) || strtolower($column) === 'id') {
            throw new InvalidArgumentException('列名が正しくありません(id は自動で振られるので入れられません)。');
        }
        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('値に配列やオブジェクトは入れられません(' . $column . ')。');
        }
        $values[$column] = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    return $values;
}

/** 使い方の例に出す URL(公開側のオリジン) */
function km_table_api_endpoint(): string
{
    require_once __DIR__ . '/site.php';

    return km_site_url(null, '/api/table.php');
}
