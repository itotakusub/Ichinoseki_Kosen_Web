<?php

declare(strict_types=1);

/**
 * お試しの閲覧リンク(2026-09-30、利用者の指示)。
 *
 * 管理画面(admin/guest-links.php)で期限と使える台数を決めてリンクを発行し、
 * 開いたブラウザだけが、期限まで**教職員と同じように**地図を見られる
 * (地図の錠を通る・教職員氏名が見える)。Logto のアカウントは要らない。
 *
 * ## 守り
 *
 * - **リンクの中身(トークン)は保存しない。** 表には sha256 だけを置き、発行した画面で 1 回だけ見せる
 * - **開いただけでは使わない。** メッセージアプリのリンクの下見(プレビュー)が GET で取りに来るので、
 *   GET では確認の画面を出すだけにし、ボタン(POST + CSRF)を押したときに 1 台分を使う
 * - 使える台数は 1 本の UPDATE で数える(同時に押されても上限を超えない)
 * - 取り消し・期限切れは、公開ページを開くたびに表を見直して印を外す(km_map_guest_verify)
 * - **見せるのは教職員と同じ範囲まで。** `hidden`(誰にも出さない)のときは、
 *   管理画面の「教職員には見せる」が ON のときだけ出る(教職員と同じ)
 * - 期限は最長 KM_MAP_GUEST_MAX_DAYS 日。期限の切れたリンクは 30 日で行ごと消す(lib/privacy-retention.php)
 */

require_once __DIR__ . '/db.php';

/** 期限の選択肢(日)。管理画面の選択肢と対。 */
const KM_MAP_GUEST_DAY_CHOICES = [1, 3, 7, 14];

/** 期限の上限(日)。 */
const KM_MAP_GUEST_MAX_DAYS = 14;

/** 1 本のリンクで使える台数(ブラウザの数)の上限。 */
const KM_MAP_GUEST_MAX_USES = 30;

/** セッションの印の名前。 */
const KM_MAP_GUEST_SESSION_KEY = 'km_map_guest';

function km_map_guest_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_guest_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL,
            label VARCHAR(64) NOT NULL,
            created_by VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            max_uses INT NOT NULL,
            uses INT NOT NULL DEFAULT 0,
            revoked_at DATETIME NULL,
            UNIQUE KEY uq_km_map_guest_token (token_hash),
            INDEX idx_km_map_guest_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** トークンの形。発行するのは 48 桁の 16 進数だけ。 */
function km_map_guest_token_valid(string $token): bool
{
    return preg_match('/^[0-9a-f]{48}$/', $token) === 1;
}

function km_map_guest_token_hash(string $token): string
{
    return hash('sha256', 'km-map-guest|' . $token);
}

/** 呼び名を絞る(管理画面で見分けるためだけのもの)。 */
function km_map_guest_clean_label(string $raw): string
{
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $raw));
    if ($value === '') {
        throw new InvalidArgumentException('呼び名を入れてください(例: 情報科の〇〇さんに試してもらう)。');
    }

    return mb_substr($value, 0, 64, 'UTF-8');
}

/**
 * リンクを発行する。**戻り値の token はここでしか手に入らない**(表には要約だけ)。
 *
 * @return array{id:int, token:string, expiresAt:int}
 */
function km_map_guest_create(PDO $pdo, string $label, int $days, int $maxUses, ?string $createdBy): array
{
    if (!in_array($days, KM_MAP_GUEST_DAY_CHOICES, true) || $days > KM_MAP_GUEST_MAX_DAYS) {
        throw new InvalidArgumentException('期限の日数が正しくありません。');
    }
    if ($maxUses < 1 || $maxUses > KM_MAP_GUEST_MAX_USES) {
        throw new InvalidArgumentException('使える台数は 1〜' . KM_MAP_GUEST_MAX_USES . ' にしてください。');
    }
    $label = km_map_guest_clean_label($label);

    km_map_guest_ensure_table($pdo);
    $token = bin2hex(random_bytes(24));
    $pdo->prepare(
        'INSERT INTO km_map_guest_links (token_hash, label, created_by, created_at, expires_at, max_uses)
         VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?)'
    )->execute([km_map_guest_token_hash($token), $label, $createdBy, $days, $maxUses]);
    $id = (int) $pdo->lastInsertId();

    $expires = $pdo->prepare('SELECT UNIX_TIMESTAMP(expires_at) FROM km_map_guest_links WHERE id = ?');
    $expires->execute([$id]);

    return ['id' => $id, 'token' => $token, 'expiresAt' => (int) $expires->fetchColumn()];
}

/**
 * 管理画面の一覧(新しい順)。
 *
 * @return array<int, array{id:int, label:string, createdAt:int, expiresAt:int, maxUses:int, uses:int, revoked:bool, status:string}>
 */
function km_map_guest_list(PDO $pdo, int $limit = 100): array
{
    km_map_guest_ensure_table($pdo);
    $statement = $pdo->prepare(
        'SELECT id, label, UNIX_TIMESTAMP(created_at) AS createdAt, UNIX_TIMESTAMP(expires_at) AS expiresAt,
                max_uses AS maxUses, uses, revoked_at IS NOT NULL AS revoked
         FROM km_map_guest_links ORDER BY id DESC LIMIT ?'
    );
    $statement->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $item = [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'createdAt' => (int) $row['createdAt'],
            'expiresAt' => (int) $row['expiresAt'],
            'maxUses' => (int) $row['maxUses'],
            'uses' => (int) $row['uses'],
            'revoked' => (bool) $row['revoked'],
        ];
        $item['status'] = km_map_guest_status($item['revoked'], $item['expiresAt'], $item['uses'], $item['maxUses'], time());
        $rows[] = $item;
    }

    return $rows;
}

/** 一覧に出す状態。**純粋関数。** active / used_up / expired / revoked */
function km_map_guest_status(bool $revoked, int $expiresAt, int $uses, int $maxUses, int $now): string
{
    return match (true) {
        $revoked => 'revoked',
        $expiresAt <= $now => 'expired',
        $uses >= $maxUses => 'used_up',
        default => 'active',
    };
}

/** 取り消す。**開いているブラウザも、次に公開ページを開いたときに外れる。** */
function km_map_guest_revoke(PDO $pdo, int $id): bool
{
    km_map_guest_ensure_table($pdo);
    $statement = $pdo->prepare('UPDATE km_map_guest_links SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
    $statement->execute([$id]);

    return $statement->rowCount() > 0;
}

/**
 * リンクを確かめる(**使わない**)。確認の画面で期限を出すため。
 *
 * @return array{id:int, expiresAt:int, usesLeft:int}|null 使えなければ null
 */
function km_map_guest_peek(PDO $pdo, string $token): ?array
{
    if (!km_map_guest_token_valid($token)) {
        return null;
    }
    km_map_guest_ensure_table($pdo);
    $statement = $pdo->prepare(
        'SELECT id, UNIX_TIMESTAMP(expires_at) AS expiresAt, max_uses - uses AS usesLeft
         FROM km_map_guest_links
         WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() AND uses < max_uses'
    );
    $statement->execute([km_map_guest_token_hash($token)]);
    $row = $statement->fetch();

    return is_array($row)
        ? ['id' => (int) $row['id'], 'expiresAt' => (int) $row['expiresAt'], 'usesLeft' => (int) $row['usesLeft']]
        : null;
}

/**
 * 1 台分を使う。**1 本の UPDATE で数える**(同時に押されても上限を超えない)。
 *
 * @return array{id:int, expiresAt:int}|null 使えなければ null
 */
function km_map_guest_redeem(PDO $pdo, string $token): ?array
{
    if (!km_map_guest_token_valid($token)) {
        return null;
    }
    km_map_guest_ensure_table($pdo);
    $hash = km_map_guest_token_hash($token);
    $use = $pdo->prepare(
        'UPDATE km_map_guest_links SET uses = uses + 1
         WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() AND uses < max_uses'
    );
    $use->execute([$hash]);
    if ($use->rowCount() !== 1) {
        return null;
    }
    $read = $pdo->prepare('SELECT id, UNIX_TIMESTAMP(expires_at) AS expiresAt FROM km_map_guest_links WHERE token_hash = ?');
    $read->execute([$hash]);
    $row = $read->fetch();

    return is_array($row) ? ['id' => (int) $row['id'], 'expiresAt' => (int) $row['expiresAt']] : null;
}

/** このセッションに印を立てる。**呼ぶ前に session_regenerate_id(true) すること**(権限が上がる瞬間)。 */
function km_map_guest_grant(int $id, int $expiresAt): void
{
    $_SESSION[KM_MAP_GUEST_SESSION_KEY] = ['id' => $id, 'until' => $expiresAt];
}

/**
 * このセッションがお試しの閲覧中か(**セッションだけを見る**。取り消しは km_map_guest_verify が外す)。
 */
function km_map_guest_session(): bool
{
    $mark = $_SESSION[KM_MAP_GUEST_SESSION_KEY] ?? null;

    return is_array($mark)
        && is_int($mark['id'] ?? null)
        && is_int($mark['until'] ?? null)
        && $mark['until'] > time();
}

/**
 * 印を表と突き合わせ、取り消し・期限切れなら外す。公開ページ(index.php)と地図データの API が呼ぶ。
 *
 * **確かめられなければ外す**(fail closed)。困るのは「お試しの人に一時的に氏名が出ない」だけ。
 * セッションが閉じたあと(書けない)に呼んだときは、この要求の間だけ効かなくする。
 */
function km_map_guest_verify(?PDO $pdo = null): bool
{
    if (!isset($_SESSION[KM_MAP_GUEST_SESSION_KEY])) {
        return false;
    }
    $ok = false;
    if (km_map_guest_session()) {
        try {
            $pdo ??= km_db();
            km_map_guest_ensure_table($pdo);
            $statement = $pdo->prepare(
                'SELECT 1 FROM km_map_guest_links WHERE id = ? AND revoked_at IS NULL AND expires_at > NOW()'
            );
            $statement->execute([$_SESSION[KM_MAP_GUEST_SESSION_KEY]['id']]);
            $ok = $statement->fetchColumn() !== false;
        } catch (Throwable $exception) {
            error_log('km_map_guest_verify: 確かめられないので外します: ' . $exception->getMessage());
        }
    }
    if (!$ok) {
        unset($_SESSION[KM_MAP_GUEST_SESSION_KEY]);
    }

    return $ok;
}

/** 発行した URL。**公開側のホスト**に向ける(管理画面を別オリジンにしていても)。 */
function km_map_guest_url(string $token): string
{
    require_once __DIR__ . '/site.php';

    return km_site_url(null, '/guest.php?t=' . rawurlencode($token));
}
