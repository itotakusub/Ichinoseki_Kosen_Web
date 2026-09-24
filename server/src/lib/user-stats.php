<?php

declare(strict_types=1);

/**
 * Android アプリのヘッダーに出す利用者の人数。
 *
 *   いま使っている人数   端末からの定期通知(ハートビート)を数える
 *   総人数・組織内・組織外  Logto の利用者を Organization とメールドメインで分類する
 *
 * **Logto Management API は毎回叩かない。** 利用者数ぶんのページングが要るので、
 * 集計結果を km_user_stats_cache に置いて既定5分だけ使い回す。
 * 端末は30秒ごとに聞きに来るため、素で通すと Logto へ毎分120回の問い合わせになる。
 *
 * 在席の記録は**集計にしか使わない**。誰がどこにいたかの履歴にしないため、
 * 保持は1行1端末の「最後に見た時刻」だけで、古い行は消す。
 */

require_once __DIR__ . '/logto-management.php';
require_once __DIR__ . '/app-secret.php';

/**
 * 1つの出どころ(IP)から「いま使っている」と数える端末の上限。
 *
 * 端末 id は端末が作るランダムな文字列なので、未ログインのまま id を変えて連投すれば
 * 人数をいくらでも水増しできた(security-review-2026-09-10 の 10)。
 * **学校の回線は1つの IP を大勢で共有する**ので、上限は大きめにしてある。
 */
const KM_USER_STATS_MAX_CLIENTS_PER_SOURCE = 300;

/** この秒数以内に通知があった端末を「いま使っている」と数える。 */
const KM_USER_STATS_ONLINE_WINDOW = 120;

/** 在席記録をこの秒数だけ残す。過ぎた行は次の通知のときに消す。 */
const KM_USER_STATS_PRESENCE_RETENTION = 3600;

/** Logto への問い合わせ結果を使い回す秒数。 */
const KM_USER_STATS_CACHE_TTL = 300;

/** Management API のページングの上限。無限ループにしないための歯止め。 */
const KM_USER_STATS_MAX_PAGES = 50;

/**
 * 使う m2m 資格情報。**既定のものを使う(読み取り専用は採らない)。**
 *
 * 当初は 'readonly' にしていたが、**Logto の Management API リソースは `all` の
 * 1スコープしか持たず、読み取りだけを切り出せない。** 読み取り専用のつもりの
 * 資格情報は 403 を返し、guard がフェイルクローズして管理画面が全面停止した
 * (2026-08-28)。詳しくは lib/logto-management.php の KM_LOGTO_M2M_PROFILES を参照。
 *
 * **そのため、公開の口を守る手段はここではない。** api/app-stats.php が
 * Management API へ渡す値は固定のパスとページ番号だけで、リクエスト由来の文字列は
 * 一切通していない。この口に手を入れるときは、その前提を崩さないこと。
 */
const KM_USER_STATS_M2M_PROFILE = 'default';

function km_user_stats_ensure_tables(PDO $pdo): void
{
    // 端末ごとの最終通知時刻。client_id は端末が作るランダムな文字列で、
    // Logto の sub とは別。**未ログインの人も数に入れたい**ので分けている。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_app_presence (
            client_id VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id VARCHAR(191) NULL,
            source_hash CHAR(32) NULL,
            last_seen_at DATETIME NOT NULL,
            INDEX idx_km_app_presence_last_seen (last_seen_at),
            INDEX idx_km_app_presence_source (source_hash, last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    // 2026-09-14 に足した列。**既にある表には後から足す**(無いときだけ ALTER する)
    $column = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'km_app_presence' AND COLUMN_NAME = 'source_hash'"
    );
    if ($column !== false && (int) $column->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE km_app_presence
               ADD COLUMN IF NOT EXISTS source_hash CHAR(32) NULL AFTER user_id,
               ADD INDEX IF NOT EXISTS idx_km_app_presence_source (source_hash, last_seen_at)'
        );
    }

    // Logto への問い合わせ結果。1行しか使わない(id = 1)。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_user_stats_cache (
            id TINYINT NOT NULL PRIMARY KEY,
            payload TEXT NOT NULL,
            refreshed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

// ---------------------------------------------------------------- 在席

/**
 * 端末からの通知を記録する。
 *
 * @param string      $clientId 端末が作る識別子。空なら記録しない(数だけ返す)。
 * @param string|null $userId   ログインしていれば Logto の sub。
 * @param string|null $source   出どころ(IP)。**そのままは保存しない**(鍵つきの印にする)。
 */
function km_user_stats_touch(PDO $pdo, string $clientId, ?string $userId, ?string $source = null): void
{
    $clientId = km_user_stats_normalize_client_id($clientId);
    if ($clientId === '') {
        return;
    }

    km_user_stats_ensure_tables($pdo);

    $sourceHash = null;
    if ($source !== null && $source !== '') {
        $sourceHash = km_app_keyed_hash(km_app_secret($pdo, 'presence'), 'presence-source', $source);
        /*
         * **新しい端末だけ**上限で断る。既に居る端末の通知は通す
         * (上限に達した回線で、使っている人の数字が消えないように)。
         */
        $known = $pdo->prepare('SELECT 1 FROM km_app_presence WHERE client_id = ?');
        $known->execute([$clientId]);
        if ($known->fetchColumn() === false) {
            $count = $pdo->prepare(
                'SELECT COUNT(*) FROM km_app_presence
                 WHERE source_hash = ? AND last_seen_at >= (NOW() - INTERVAL ? SECOND)'
            );
            $count->execute([$sourceHash, KM_USER_STATS_ONLINE_WINDOW]);
            if ((int) $count->fetchColumn() >= KM_USER_STATS_MAX_CLIENTS_PER_SOURCE) {
                return;
            }
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO km_app_presence (client_id, user_id, source_hash, last_seen_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), source_hash = VALUES(source_hash), last_seen_at = NOW()'
    );
    $stmt->execute([$clientId, $userId !== null && $userId !== '' ? $userId : null, $sourceHash]);

    // 古い行はここで捨てる。cron を用意しなくても表が太らないようにするため。
    $pdo->prepare(
        'DELETE FROM km_app_presence WHERE last_seen_at < (NOW() - INTERVAL ? SECOND)'
    )->execute([KM_USER_STATS_PRESENCE_RETENTION]);
}

/**
 * いま使っている人数。
 *
 * @return array{online:int, signedIn:int, anonymous:int}
 */
function km_user_stats_online(PDO $pdo, int $windowSeconds = KM_USER_STATS_ONLINE_WINDOW): array
{
    km_user_stats_ensure_tables($pdo);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                COUNT(user_id) AS signedIn
         FROM km_app_presence
         WHERE last_seen_at >= (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([max(30, $windowSeconds)]);
    $row = $stmt->fetch();

    $total = is_array($row) ? (int) $row['total'] : 0;
    $signedIn = is_array($row) ? (int) $row['signedIn'] : 0;

    return [
        'online' => $total,
        'signedIn' => $signedIn,
        'anonymous' => max(0, $total - $signedIn),
    ];
}

/** 端末が送ってくる識別子を安全な形に丸める。 */
function km_user_stats_normalize_client_id(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '' || strlen($trimmed) > 64) {
        return '';
    }
    // 英数字とハイフンだけ。SQL には必ずプレースホルダで渡すが、
    // 表に妙な値を溜めないため入口で絞る。
    return preg_match('/^[A-Za-z0-9-]{8,64}$/', $trimmed) === 1 ? $trimmed : '';
}

// ---------------------------------------------------------------- 組織の判定

/**
 * 組織内とみなすメールドメインの一覧。config/app-map.local.php の
 * `organizationEmailDomains` に書く。
 *
 * @return array<int, string> 小文字・前後の空白なし
 */
function km_user_stats_allowed_domains(): array
{
    static $domains = null;
    if ($domains !== null) {
        return $domains;
    }

    $path = __DIR__ . '/../config/app-map.local.php';
    $config = is_file($path) ? require $path : [];
    $raw = is_array($config) ? ($config['organizationEmailDomains'] ?? []) : [];

    $domains = [];
    if (is_array($raw)) {
        foreach ($raw as $entry) {
            $normalized = strtolower(trim((string) $entry));
            if ($normalized !== '') {
                $domains[] = ltrim($normalized, '@.');
            }
        }
    }

    return $domains;
}

/**
 * メールアドレスからドメインを取り出す。
 *
 * `+` 付きアドレスや大文字が混ざっても同じ結果になるようにする。
 */
function km_user_stats_email_domain(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return '';
    }
    return strtolower(trim(substr($email, $at + 1)));
}

/**
 * ドメインが許可一覧に当てはまるか。**サブドメインも組織内とみなす。**
 * `example.com` を許可すると `sub.example.com` も通る。
 */
function km_user_stats_domain_matches(string $domain, array $allowedDomains): bool
{
    if ($domain === '') {
        return false;
    }
    foreach ($allowedDomains as $allowed) {
        if ($domain === $allowed) {
            return true;
        }
        // 末尾一致だけだと `notexample.com` が `example.com` に当たってしまう。
        // 直前がドットであることまで見る。
        $suffix = '.' . $allowed;
        if (substr($domain, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

/**
 * 1人を組織内と判定するか。
 *
 * 1. Logto の Organization に属していれば組織内
 * 2. 属していなければメールドメインで判定
 * 3. どちらでもなければ組織外
 *
 * @param array<string, bool> $organizationMemberIds ユーザーID => true
 * @param array<int, string>  $allowedDomains
 */
function km_user_stats_is_inside(
    string $userId,
    string $email,
    array $organizationMemberIds,
    array $allowedDomains
): bool {
    if (isset($organizationMemberIds[$userId])) {
        return true;
    }
    return km_user_stats_domain_matches(km_user_stats_email_domain($email), $allowedDomains);
}

// ---------------------------------------------------------------- 総人数

/**
 * Logto の利用者を数えて分類する。**5分キャッシュ付き。**
 *
 * ## $mayRefresh —— 公開の口を Logto から切り離すための鍵(2026-08-29)
 *
 * false を渡すと、キャッシュが切れていても**Logto へは一切問い合わせない**。
 * 古い値に `stale` を立てて返すだけ。
 *
 * 狙いは「**未ログインで叩ける経路が Management API に繋がらない**」こと。
 * 読み取り専用の m2m を作る案は Logto の仕様上できない —— Management API の
 * スコープは `all` の1つだけで、権限を絞れない(2026-08-28 に本番で実測。
 * lib/logto-management.php の説明を参照)。権限ではなく**経路**で分けると、
 * readonly 資格情報より強い性質が得られる。
 *
 * 併せて、キャッシュ切れの瞬間に殺到した未ログインのリクエストが
 * **揃って Logto を数十往復待つ**(thundering herd)経路も塞がる。
 *
 * @return array{total:int, inside:int, outside:int, organizations:int,
 *               refreshedAt:int, stale:bool}
 */
function km_user_stats_directory(
    PDO $pdo,
    bool $forceRefresh = false,
    bool $mayRefresh = true
): array {
    km_user_stats_ensure_tables($pdo);

    $cached = km_user_stats_read_cache($pdo);
    if (!$forceRefresh && $cached !== null && $cached['refreshedAt'] > time() - KM_USER_STATS_CACHE_TTL) {
        $cached['stale'] = false;
        return $cached;
    }

    if (!$mayRefresh) {
        // 更新できない経路。古くても持っているものを返す。
        if ($cached !== null) {
            $cached['stale'] = true;
            return $cached;
        }
        return [
            'total' => 0,
            'inside' => 0,
            'outside' => 0,
            'organizations' => 0,
            'refreshedAt' => 0,
            'stale' => true,
        ];
    }

    try {
        $fresh = km_user_stats_collect_directory();
    } catch (Throwable $exception) {
        error_log('km_user_stats_directory failed: ' . $exception->getMessage());
        // **Logto が落ちていても画面は出す。** 古い値に印を付けて返す。
        if ($cached !== null) {
            $cached['stale'] = true;
            return $cached;
        }
        return [
            'total' => 0,
            'inside' => 0,
            'outside' => 0,
            'organizations' => 0,
            'refreshedAt' => 0,
            'stale' => true,
        ];
    }

    km_user_stats_write_cache($pdo, $fresh);
    $fresh['stale'] = false;

    return $fresh;
}

/** @return array{total:int, inside:int, outside:int, organizations:int, refreshedAt:int}|null */
function km_user_stats_read_cache(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        'SELECT payload, UNIX_TIMESTAMP(refreshed_at) AS refreshedAt
         FROM km_user_stats_cache WHERE id = 1'
    );
    $row = $stmt === false ? false : $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $payload = json_decode((string) $row['payload'], true);
    if (!is_array($payload)) {
        return null;
    }

    return [
        'total' => (int) ($payload['total'] ?? 0),
        'inside' => (int) ($payload['inside'] ?? 0),
        'outside' => (int) ($payload['outside'] ?? 0),
        'organizations' => (int) ($payload['organizations'] ?? 0),
        'refreshedAt' => (int) $row['refreshedAt'],
    ];
}

function km_user_stats_write_cache(PDO $pdo, array $stats): void
{
    $payload = json_encode([
        'total' => (int) $stats['total'],
        'inside' => (int) $stats['inside'],
        'outside' => (int) $stats['outside'],
        'organizations' => (int) $stats['organizations'],
    ], JSON_UNESCAPED_UNICODE);

    $pdo->prepare(
        'INSERT INTO km_user_stats_cache (id, payload, refreshed_at)
         VALUES (1, ?, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), refreshed_at = NOW()'
    )->execute([$payload]);
}

/**
 * Logto を実際に読んで数える。キャッシュを見ないので直接は呼ばない。
 *
 * @return array{total:int, inside:int, outside:int, organizations:int, refreshedAt:int}
 */
function km_user_stats_collect_directory(): array
{
    $allowedDomains = km_user_stats_allowed_domains();
    // 組織数は、メンバー一覧を取るときの応答から数える。
    // 別に数え直すと **同じ organizations 取得を2回**することになる(2026-08-29 に統合)。
    $organizationCount = 0;
    $organizationMemberIds = km_user_stats_organization_members($organizationCount);

    $total = 0;
    $inside = 0;

    for ($page = 1; $page <= KM_USER_STATS_MAX_PAGES; $page++) {
        $rows = km_logto_management_get(
            'users',
            ['page' => $page, 'page_size' => 100],
            KM_USER_STATS_M2M_PROFILE
        );
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            // 停止中の利用者は数に入れない(画面の「全 N 人」が実態とずれるため)。
            if (!empty($row['isSuspended'])) {
                continue;
            }
            $total++;
            $isInside = km_user_stats_is_inside(
                (string) $row['id'],
                (string) ($row['primaryEmail'] ?? ''),
                $organizationMemberIds,
                $allowedDomains
            );
            if ($isInside) {
                $inside++;
            }
        }
        if (count($rows) < 100) {
            break;
        }
    }

    return [
        'total' => $total,
        'inside' => $inside,
        'outside' => max(0, $total - $inside),
        'organizations' => $organizationCount,
        'refreshedAt' => time(),
    ];
}

/**
 * Organization に属している利用者の ID。
 *
 * Organization をまだ使っていなければ空になり、判定はメールドメインだけになる。
 *
 * @return array<string, bool>
 */
function km_user_stats_organization_members(?int &$organizationCount = null): array
{
    $members = [];
    $organizationCount = 0;
    try {
        $organizations = km_logto_management_get(
            'organizations',
            ['page' => 1, 'page_size' => 100],
            KM_USER_STATS_M2M_PROFILE
        );
    } catch (Throwable $exception) {
        error_log('km_user_stats_organization_members failed: ' . $exception->getMessage());
        return $members;
    }
    // 呼び出し側が数を欲しがるので、ここで数えて渡す。
    // 別関数で数え直すと同じ取得が2往復になる。
    $organizationCount = count($organizations);

    foreach ($organizations as $organization) {
        if (!is_array($organization) || !isset($organization['id'])) {
            continue;
        }
        $id = (string) $organization['id'];
        try {
            for ($page = 1; $page <= KM_USER_STATS_MAX_PAGES; $page++) {
                $users = km_logto_management_get(
                    "organizations/{$id}/users",
                    ['page' => $page, 'page_size' => 100],
                    KM_USER_STATS_M2M_PROFILE
                );
                if ($users === []) {
                    break;
                }
                foreach ($users as $user) {
                    if (is_array($user) && isset($user['id'])) {
                        $members[(string) $user['id']] = true;
                    }
                }
                if (count($users) < 100) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            // 1つの組織が読めなくても他は数える。
            error_log("km_user_stats_organization_members: {$id} failed: " . $exception->getMessage());
        }
    }

    return $members;
}

/**
 * 組織の数。
 *
 * **集計からは呼ばない。** km_user_stats_organization_members() が
 * メンバーを取るついでに数えて返すので、そちらを使うこと
 * (ここを使うと同じ organizations 取得がもう1往復増える)。
 * 単独で数だけ欲しい場面のために残してある。
 */
function km_user_stats_organization_count(): int
{
    try {
        $organizations = km_logto_management_get(
            'organizations',
            ['page' => 1, 'page_size' => 100],
            KM_USER_STATS_M2M_PROFILE
        );
    } catch (Throwable $exception) {
        error_log('km_user_stats_organization_count failed: ' . $exception->getMessage());
        return 0;
    }

    return count($organizations);
}
