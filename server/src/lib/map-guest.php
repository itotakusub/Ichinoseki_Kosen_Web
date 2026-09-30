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
 *
 * ## 仮アカウント(2026-09-30 の 2 回目、利用者の指示)
 *
 * リンクに入ったら**名前(と所属)を入れて仮アカウントを作る**。**誰が見ているのかが分かる**ようにするため。
 * 仮アカウントは **MariaDB の km_map_guest_accounts にだけ**置き、Logto には作らない(利用者の指示)。
 *
 * - 「使える台数」は、作れる仮アカウントの数になる(1 アカウント = 1 台分)
 * - 作った画面で**再入場コード**を 1 回だけ見せる。ブラウザを閉じても、同じリンクとコードで入り直せる(台数は減らない)。
 *   表にはコードの要約だけを置く。コードの総当たりは、地図のパスワードと同じ数え方で絞る(錠 'guest')
 * - 管理画面で、誰が・いつ作り・最後にいつ見たかが分かり、アカウントごとに止められる
 * - 入れた名前と所属は、リンクと一緒に期限の 30 日後に消える
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

    // 仮アカウント。**Logto ではなくここにだけ作る**(利用者の指示)。コードは要約だけ
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_guest_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            link_id INT NOT NULL,
            display_name VARCHAR(32) NOT NULL,
            affiliation VARCHAR(64) NULL,
            code_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY uq_km_map_guest_account_code (link_id, code_hash),
            INDEX idx_km_map_guest_account_link (link_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 名前の長さの上限(文字)。 */
const KM_MAP_GUEST_NAME_MAX = 32;

/** 所属の長さの上限(文字)。 */
const KM_MAP_GUEST_AFFILIATION_MAX = 64;

/** 再入場コードの文字。**見間違えやすい 0/O・1/I/L は入れない**。 */
const KM_MAP_GUEST_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

/** 再入場コードの長さ(表示は 4 文字ずつ区切る)。 */
const KM_MAP_GUEST_CODE_LENGTH = 8;

/** 最後に見た時刻を書き直す間隔(秒)。開くたびに書かない。 */
const KM_MAP_GUEST_SEEN_INTERVAL = 300;

/** 名前・所属を絞る。制御文字を落とし、前後の空白を取る。 */
function km_map_guest_clean_text(string $raw, int $max): string
{
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $raw));

    return mb_substr($value, 0, $max, 'UTF-8');
}

/** 再入場コードを作る(例 `K7QM-3XRA`)。 */
function km_map_guest_new_code(): string
{
    $alphabet = KM_MAP_GUEST_CODE_ALPHABET;
    $code = '';
    for ($i = 0; $i < KM_MAP_GUEST_CODE_LENGTH; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

/** 入力されたコードを揃える(小文字・空白・ハイフンを許す)。形が違えば null。 */
function km_map_guest_normalize_code(string $raw): ?string
{
    $code = strtoupper((string) preg_replace('/[\s\-]/', '', $raw));
    $pattern = '/^[' . KM_MAP_GUEST_CODE_ALPHABET . ']{' . KM_MAP_GUEST_CODE_LENGTH . '}$/';

    return preg_match($pattern, $code) === 1 ? $code : null;
}

/** 見せる形(4 文字ずつ)。 */
function km_map_guest_format_code(string $code): string
{
    return implode('-', str_split($code, 4));
}

/** コードの要約。**リンクごとに違う値になる**(別のリンクの同じコードと取り違えない)。 */
function km_map_guest_code_hash(int $linkId, string $code): string
{
    return hash('sha256', 'km-map-guest-code|' . $linkId . '|' . $code);
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
 * リンクを確かめる(**使わない**)。確認の画面で期限と残りの台数を出すため。
 * 台数を使い切っていても返す(再入場はできるので)。取り消し・期限切れなら null。
 *
 * @return array{id:int, expiresAt:int, usesLeft:int}|null
 */
function km_map_guest_peek(PDO $pdo, string $token): ?array
{
    if (!km_map_guest_token_valid($token)) {
        return null;
    }
    km_map_guest_ensure_table($pdo);
    $statement = $pdo->prepare(
        'SELECT id, UNIX_TIMESTAMP(expires_at) AS expiresAt, GREATEST(max_uses - uses, 0) AS usesLeft
         FROM km_map_guest_links
         WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'
    );
    $statement->execute([km_map_guest_token_hash($token)]);
    $row = $statement->fetch();

    return is_array($row)
        ? ['id' => (int) $row['id'], 'expiresAt' => (int) $row['expiresAt'], 'usesLeft' => (int) $row['usesLeft']]
        : null;
}

/**
 * 仮アカウントを作る。**1 台分を使い、アカウントを入れるのを 1 つの取引で**行う。
 * 台数は 1 本の UPDATE で数える(同時に押されても上限を超えない)。
 *
 * @return array{linkId:int, accountId:int, expiresAt:int, code:string, name:string}|null 使えなければ null
 */
function km_map_guest_create_account(PDO $pdo, string $token, string $name, string $affiliation): ?array
{
    if (!km_map_guest_token_valid($token)) {
        return null;
    }
    $name = km_map_guest_clean_text($name, KM_MAP_GUEST_NAME_MAX);
    if ($name === '') {
        throw new InvalidArgumentException('名前を入れてください。');
    }
    $affiliation = km_map_guest_clean_text($affiliation, KM_MAP_GUEST_AFFILIATION_MAX);

    km_map_guest_ensure_table($pdo);
    $hash = km_map_guest_token_hash($token);
    $pdo->beginTransaction();
    try {
        $use = $pdo->prepare(
            'UPDATE km_map_guest_links SET uses = uses + 1
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() AND uses < max_uses'
        );
        $use->execute([$hash]);
        if ($use->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $read = $pdo->prepare('SELECT id, UNIX_TIMESTAMP(expires_at) AS expiresAt FROM km_map_guest_links WHERE token_hash = ?');
        $read->execute([$hash]);
        $link = $read->fetch();

        // コードはリンクの中で重ならないように作り直す(UNIQUE に当たったらもう一度)
        $insert = $pdo->prepare(
            'INSERT INTO km_map_guest_accounts (link_id, display_name, affiliation, code_hash, created_at, last_seen_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $code = '';
        for ($try = 0; $try < 5; $try++) {
            $code = km_map_guest_new_code();
            try {
                $insert->execute([(int) $link['id'], $name, $affiliation !== '' ? $affiliation : null, km_map_guest_code_hash((int) $link['id'], $code)]);
                break;
            } catch (PDOException $exception) {
                if ($try === 4 || !str_contains($exception->getMessage(), 'Duplicate')) {
                    throw $exception;
                }
            }
        }
        $accountId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'linkId' => (int) $link['id'],
        'accountId' => $accountId,
        'expiresAt' => (int) $link['expiresAt'],
        'code' => $code,
        'name' => $name,
    ];
}

/**
 * 再入場。**台数は使わない。** リンクが生きていて、アカウントが止められていないときだけ。
 *
 * @return array{linkId:int, accountId:int, expiresAt:int, name:string}|null
 */
function km_map_guest_reenter(PDO $pdo, string $token, string $rawCode): ?array
{
    $link = km_map_guest_peek($pdo, $token);
    $code = km_map_guest_normalize_code($rawCode);
    if ($link === null || $code === null) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT id, display_name FROM km_map_guest_accounts
         WHERE link_id = ? AND code_hash = ? AND revoked_at IS NULL'
    );
    $statement->execute([$link['id'], km_map_guest_code_hash($link['id'], $code)]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }
    $pdo->prepare('UPDATE km_map_guest_accounts SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);

    return [
        'linkId' => $link['id'],
        'accountId' => (int) $row['id'],
        'expiresAt' => $link['expiresAt'],
        'name' => (string) $row['display_name'],
    ];
}

/** このセッションに印を立てる。**呼ぶ前に session_regenerate_id(true) すること**(権限が上がる瞬間)。 */
function km_map_guest_grant(int $linkId, int $accountId, int $expiresAt, string $name): void
{
    $_SESSION[KM_MAP_GUEST_SESSION_KEY] = ['id' => $linkId, 'account' => $accountId, 'until' => $expiresAt, 'name' => $name];
}

/**
 * このセッションがお試しの閲覧中か(**セッションだけを見る**。取り消しは km_map_guest_verify が外す)。
 * 仮アカウントの無い印(仮アカウントを入れる前の形)は効かない。
 */
function km_map_guest_session(): bool
{
    $mark = $_SESSION[KM_MAP_GUEST_SESSION_KEY] ?? null;

    return is_array($mark)
        && is_int($mark['id'] ?? null)
        && is_int($mark['account'] ?? null)
        && is_int($mark['until'] ?? null)
        && $mark['until'] > time();
}

/** 画面に出す名前(お試しの閲覧中でなければ null)。 */
function km_map_guest_name(): ?string
{
    return km_map_guest_session() ? (string) ($_SESSION[KM_MAP_GUEST_SESSION_KEY]['name'] ?? '') : null;
}

/**
 * 印を表と突き合わせ、リンクかアカウントが取り消し・期限切れなら外す。公開ページ(index.php)と地図データの API が呼ぶ。
 * ついでに「最後に見た時刻」を書く(KM_MAP_GUEST_SEEN_INTERVAL 秒に 1 回まで)。
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
        $mark = $_SESSION[KM_MAP_GUEST_SESSION_KEY];
        try {
            $pdo ??= km_db();
            km_map_guest_ensure_table($pdo);
            $statement = $pdo->prepare(
                'SELECT 1 FROM km_map_guest_accounts a
                   JOIN km_map_guest_links l ON l.id = a.link_id
                  WHERE a.id = ? AND a.link_id = ? AND a.revoked_at IS NULL
                    AND l.revoked_at IS NULL AND l.expires_at > NOW()'
            );
            $statement->execute([$mark['account'], $mark['id']]);
            $ok = $statement->fetchColumn() !== false;
            if ($ok) {
                $pdo->prepare(
                    'UPDATE km_map_guest_accounts SET last_seen_at = NOW()
                     WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL ? SECOND))'
                )->execute([$mark['account'], KM_MAP_GUEST_SEEN_INTERVAL]);
            }
        } catch (Throwable $exception) {
            error_log('km_map_guest_verify: 確かめられないので外します: ' . $exception->getMessage());
            $ok = false;
        }
    }
    if (!$ok) {
        unset($_SESSION[KM_MAP_GUEST_SESSION_KEY]);
    }

    return $ok;
}

/**
 * 管理画面の一覧: リンクごとの仮アカウント(新しい順)。
 *
 * @return array<int, array<int, array{id:int, name:string, affiliation:?string, createdAt:int, lastSeenAt:?int, revoked:bool}>>
 */
function km_map_guest_accounts_by_link(PDO $pdo): array
{
    km_map_guest_ensure_table($pdo);
    $rows = $pdo->query(
        'SELECT id, link_id, display_name, affiliation, UNIX_TIMESTAMP(created_at) AS createdAt,
                UNIX_TIMESTAMP(last_seen_at) AS lastSeenAt, revoked_at IS NOT NULL AS revoked
         FROM km_map_guest_accounts ORDER BY id DESC LIMIT 1000'
    );
    $byLink = [];
    foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
        $byLink[(int) $row['link_id']][] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['display_name'],
            'affiliation' => $row['affiliation'] !== null ? (string) $row['affiliation'] : null,
            'createdAt' => (int) $row['createdAt'],
            'lastSeenAt' => $row['lastSeenAt'] !== null ? (int) $row['lastSeenAt'] : null,
            'revoked' => (bool) $row['revoked'],
        ];
    }

    return $byLink;
}

/** 仮アカウントを 1 つ止める。**開いているブラウザも、次に地図を開いたときに外れる。** */
function km_map_guest_revoke_account(PDO $pdo, int $accountId): bool
{
    km_map_guest_ensure_table($pdo);
    $statement = $pdo->prepare('UPDATE km_map_guest_accounts SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
    $statement->execute([$accountId]);

    return $statement->rowCount() > 0;
}

/** 発行した URL。**公開側のホスト**に向ける(管理画面を別オリジンにしていても)。 */
function km_map_guest_url(string $token): string
{
    require_once __DIR__ . '/site.php';

    return km_site_url(null, '/guest.php?t=' . rawurlencode($token));
}
