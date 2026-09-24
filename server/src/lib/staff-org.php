<?php

declare(strict_types=1);

/**
 * 教職員の権限(Logto の組織と組織ロール)。docs/15-staff-org.ipynb。
 *
 * ## 形(2026-09-18 に決めた)
 *
 * | 何 | どこ |
 * |---|---|
 * | 誰が教職員か | **管理者が承認した申請**(`km_staff_requests`)。本人の自己申告だけでは付かない |
 * | 権限そのもの | Logto の**組織**(`KM_LOGTO_ORG_ID`)の**組織ロール**(`KM_LOGTO_STAFF_ORG_ROLE`) |
 * | 判定 | トークンの中の権限 `KM_LOGTO_STAFF_SCOPE`(既定 `staff:normal:access`)。**Management API は判定経路に入れない** |
 *
 * **イベント運営(`staff:event:access`、logto_guard.php)とは別の権限。** 両方持つ人は両方効く。
 *
 * ## 学生と教職員でメールのドメインが同じ
 *
 * だから Logto の JIT(ドメインでの自動付与)は使わない。付けるのは承認のときだけ、外すのは取り消しのときだけ。
 *
 * ## この段(A)で作るもの
 *
 * 組織への出し入れと、申請を貯める表。画面(申請・承認)は次の段。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logto-management.php';

/** 申請の状態。`revoked` は「承認したあとで取り消した」。 */
const KM_STAFF_REQUEST_STATUSES = ['pending', 'approved', 'rejected', 'revoked'];

/** 申請に添える一言の上限(氏名・所属を書ける長さ)。 */
const KM_STAFF_REQUEST_NOTE_MAX = 200;

/**
 * 設定。**組織 ID が空なら、この機能は無いものとして振る舞う**(付けない・判定しない)。
 *
 * @return array{orgId:string, role:string, scope:string}
 */
function km_staff_org_config(): array
{
    $orgId = trim((string) getenv('KM_LOGTO_ORG_ID'));
    $role = trim((string) getenv('KM_LOGTO_STAFF_ORG_ROLE'));
    $scope = trim((string) getenv('KM_LOGTO_STAFF_SCOPE'));

    return [
        // Logto の ID は英小文字と数字。**形が違えば空として扱う**(パスに埋めるので、変な値を通さない)
        'orgId' => preg_match('/^[a-z0-9]{6,64}$/', $orgId) === 1 ? $orgId : '',
        'role' => $role !== '' ? $role : 'Kosen_Member',
        'scope' => $scope !== '' ? $scope : 'staff:normal:access',
    ];
}

function km_staff_org_enabled(): bool
{
    return km_staff_org_config()['orgId'] !== '';
}

/** Logto の利用者 ID の形。パスに埋めるので、ここで絞る。 */
function km_staff_org_valid_user_id(string $userId): bool
{
    return preg_match('/^[A-Za-z0-9_-]{6,64}$/', $userId) === 1;
}

/**
 * 組織に入れて、組織ロールを付ける。**何度呼んでもよい**(既に居る・既に持っているは Logto が無視する)。
 *
 * @throws RuntimeException 付けられなかったとき(**付いたと誤解させない**)
 */
function km_staff_org_grant(string $userId): void
{
    $config = km_staff_org_config();
    if ($config['orgId'] === '') {
        throw new RuntimeException('教職員の組織が設定されていません(KM_LOGTO_ORG_ID)。');
    }
    if (!km_staff_org_valid_user_id($userId)) {
        throw new InvalidArgumentException('利用者 ID の形が不正です。');
    }

    $org = rawurlencode($config['orgId']);
    km_logto_management_post("organizations/{$org}/users", ['userIds' => [$userId]]);
    km_logto_management_post(
        "organizations/{$org}/users/" . rawurlencode($userId) . '/roles',
        ['organizationRoleNames' => [$config['role']]]
    );
}

/**
 * 組織から外す(組織ロールも一緒に外れる)。**居なくても成功**。
 *
 * **外しても、既に発行済みのトークンは期限(最長 1 時間)まで効く。** 即時に止めたいなら
 * Logto で本人のセッションも切る(管理画面の「停止」と同じ考え方。lib/logto-management.php の停止判定)。
 */
function km_staff_org_revoke(string $userId): void
{
    $config = km_staff_org_config();
    if ($config['orgId'] === '') {
        throw new RuntimeException('教職員の組織が設定されていません(KM_LOGTO_ORG_ID)。');
    }
    if (!km_staff_org_valid_user_id($userId)) {
        throw new InvalidArgumentException('利用者 ID の形が不正です。');
    }

    km_logto_management_delete_path(
        'organizations/' . rawurlencode($config['orgId']) . '/users/' . rawurlencode($userId)
    );
}

/**
 * いま組織ロールを持っているか(**画面の表示用**。判定には使わない —— 判定はトークンで行う)。
 */
function km_staff_org_has_role(string $userId): bool
{
    $config = km_staff_org_config();
    if ($config['orgId'] === '' || !km_staff_org_valid_user_id($userId)) {
        return false;
    }
    /*
     * **利用者の側から所属を読む**(`/users/{id}/organizations` は各組織の organizationRoles を含む)。
     * 組織の側(`/organizations/{id}/users/{userId}/roles`)で読むと、**居ない人は 422** になり、
     * 表示のたびにエラーログが増える(2026-09-18 に検証機で確認)。
     */
    try {
        $orgs = km_logto_management_get('users/' . rawurlencode($userId) . '/organizations', [], 'readonly');
    } catch (Throwable $exception) {
        return false;
    }
    foreach ($orgs as $org) {
        if (!is_array($org) || ($org['id'] ?? null) !== $config['orgId']) {
            continue;
        }
        foreach ((array) ($org['organizationRoles'] ?? []) as $role) {
            if (is_array($role) && ($role['name'] ?? null) === $config['role']) {
                return true;
            }
        }
    }

    return false;
}

/**
 * API のトークン(logto_guard.php)に組織が付いていたときの扱い。**署名と aud の検証が済んだあと**に呼ぶ。
 *
 *   1. `organization_id` が付いていれば、**こちらの組織 ID と一致するときだけ** ok
 *      (aud だけ見ると、別の組織のトークンでも通ってしまう)
 *   2. 組織トークンから採る権限は**教職員の権限だけ**。組織ロールに何が付いていても admin:* には化けさせない
 *   3. 組織を通さないトークンに教職員の権限が載っていても**捨てる**(教職員の出どころは組織だけ)
 *
 * @param list<string> $permissions トークンの scope
 * @return array{ok:bool, permissions:list<string>, isTeacher:bool}
 */
function km_staff_org_apply_token(?string $organizationId, array $permissions): array
{
    $config = km_staff_org_config();
    if ($organizationId !== null) {
        if ($config['orgId'] === '' || !hash_equals($config['orgId'], $organizationId)) {
            return ['ok' => false, 'permissions' => [], 'isTeacher' => false];
        }
        $isTeacher = in_array($config['scope'], $permissions, true);

        return ['ok' => true, 'permissions' => $isTeacher ? [$config['scope']] : [], 'isTeacher' => $isTeacher];
    }

    return [
        'ok' => true,
        'permissions' => array_values(array_filter($permissions, static fn ($p): bool => $p !== $config['scope'])),
        'isTeacher' => false,
    ];
}

/**
 * 公開ページで、サインイン中の人が教職員か(ID トークンの `organization_roles` を見る)。
 *
 * 値は `組織ID:ロール名` の並び。**こちらの組織の、こちらのロールだけを数える**
 * (別の組織で同じ名前のロールを持っていても教職員にしない)。
 * ロールを ID で書く版の Logto に備えて、`組織ID:ロールID` は扱わない —— 扱うと
 * ロール ID を知る手段(Management API)を判定経路に入れることになる。名前が合わなければ教職員にしない。
 *
 * @param array<mixed>|null $organizationRoles ID トークンの organization_roles
 */
function km_staff_org_claims_is_teacher(?array $organizationRoles): bool
{
    $config = km_staff_org_config();
    if ($config['orgId'] === '' || $organizationRoles === null) {
        return false;
    }

    return in_array($config['orgId'] . ':' . $config['role'], $organizationRoles, true);
}

// ---------------------------------------------------------------- 申請の表

function km_staff_requests_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_staff_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(64) NOT NULL,
            email VARCHAR(255) NULL,
            name VARCHAR(255) NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decided_by VARCHAR(64) NULL,
            INDEX (status),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

/**
 * 申請を受け付ける。**同じ人の未処理の申請は 1 件だけ**(連打で一覧を埋めさせない)。
 *
 * @return int 申請の番号
 * @throws InvalidArgumentException 形が違う・既に申請中・既に承認済み
 */
function km_staff_request_create(PDO $pdo, string $userId, ?string $email, ?string $name, string $note): int
{
    km_staff_requests_ensure_table($pdo);
    if (!km_staff_org_valid_user_id($userId)) {
        throw new InvalidArgumentException('利用者を確かめられませんでした。');
    }
    $note = trim($note);
    if (mb_strlen($note) > KM_STAFF_REQUEST_NOTE_MAX) {
        throw new InvalidArgumentException('ひとことは ' . KM_STAFF_REQUEST_NOTE_MAX . ' 文字以内にしてください。');
    }

    $open = $pdo->prepare("SELECT status FROM km_staff_requests WHERE user_id = ? AND status IN ('pending', 'approved') ORDER BY id DESC LIMIT 1");
    $open->execute([$userId]);
    $existing = $open->fetchColumn();
    if ($existing === 'pending') {
        throw new InvalidArgumentException('申請は受け付け済みです。承認をお待ちください。');
    }
    if ($existing === 'approved') {
        throw new InvalidArgumentException('既に承認されています。');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO km_staff_requests (user_id, email, name, note, status, created_at) VALUES (?, ?, ?, ?, \'pending\', NOW())'
    );
    $stmt->execute([
        $userId,
        $email !== null ? mb_substr($email, 0, 255) : null,
        $name !== null ? mb_substr($name, 0, 255) : null,
        $note,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return list<array{id:int, userId:string, email:?string, name:?string, note:string, status:string, createdAtEpoch:int, decidedAtEpoch:?int, decidedBy:?string}>
 */
function km_staff_requests_list(PDO $pdo, ?string $status = null): array
{
    km_staff_requests_ensure_table($pdo);
    $sql = 'SELECT id, user_id, email, name, note, status, UNIX_TIMESTAMP(created_at) AS c, UNIX_TIMESTAMP(decided_at) AS d, decided_by FROM km_staff_requests';
    $params = [];
    if ($status !== null) {
        if (!in_array($status, KM_STAFF_REQUEST_STATUSES, true)) {
            throw new InvalidArgumentException('状態の指定が不正です。');
        }
        $sql .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'userId' => (string) $r['user_id'],
        'email' => $r['email'] !== null ? (string) $r['email'] : null,
        'name' => $r['name'] !== null ? (string) $r['name'] : null,
        'note' => (string) $r['note'],
        'status' => (string) $r['status'],
        'createdAtEpoch' => (int) $r['c'],
        'decidedAtEpoch' => $r['d'] !== null ? (int) $r['d'] : null,
        'decidedBy' => $r['decided_by'] !== null ? (string) $r['decided_by'] : null,
    ], $stmt->fetchAll());
}

/**
 * その人のいちばん新しい申請(無ければ null)。本人の画面で状態を出すのに使う。
 *
 * @return array{id:int, status:string, note:string, createdAtEpoch:int}|null
 */
function km_staff_request_latest(PDO $pdo, string $userId): ?array
{
    km_staff_requests_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT id, status, note, UNIX_TIMESTAMP(created_at) AS c FROM km_staff_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    return ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'note' => (string) $row['note'], 'createdAtEpoch' => (int) $row['c']];
}

/** 未処理の件数(管理画面のサイドバーに出す)。表が無ければ 0。 */
function km_staff_requests_pending_count(PDO $pdo): int
{
    km_staff_requests_ensure_table($pdo);

    return (int) $pdo->query("SELECT COUNT(*) FROM km_staff_requests WHERE status = 'pending'")->fetchColumn();
}

/**
 * 申請が来たことを管理者へ知らせる(メール)。**送れなくても申請は受け付けたまま** ——
 * 知らせの失敗で本人の申請を失わせない。管理画面の一覧には必ず出る。
 *
 * 本文に入れるのは、承認の判断に要るもの(名前・メール・ひとこと)と管理画面への道だけ。
 * **Teams への送信は後の段**(docs/15 段 F)。
 */
function km_staff_request_notify(int $id, ?string $name, ?string $email, string $note): void
{
    try {
        require_once __DIR__ . '/mailer.php';
        require_once __DIR__ . '/site.php';
        $link = km_site_admin_origin() . '/admin/staff-requests.php';
        $body = "教職員の権限の申請が届きました(#{$id})。\n\n"
            . '名前    : ' . ($name ?? '(不明)') . "\n"
            . 'メール  : ' . ($email ?? '(不明)') . "\n"
            . 'ひとこと: ' . ($note !== '' ? $note : '(無し)') . "\n\n"
            . "承認・却下は管理画面から:\n{$link}\n\n"
            . "承認すると、教職員氏名と閲覧不可の地点が見えるようになります。本人か確かめてから承認してください。\n";
        km_mail_send(km_mail_admin_to(), '[KosenMap] 教職員の権限の申請 #' . $id, $body);
    } catch (Throwable $exception) {
        error_log('km_staff_request_notify failed: ' . $exception->getMessage());
    }
}

/**
 * 申請を処理する。**Logto を先に変え、成功してから表を書く** —— 逆だと「承認済みなのに権限が無い」が残る。
 *
 * | 動作 | 前の状態 | 後の状態 | Logto |
 * |---|---|---|---|
 * | approve | pending | approved | 組織に入れる |
 * | reject | pending | rejected | 触れない |
 * | revoke | approved | revoked | 組織から外す |
 *
 * @return array{userId:string, status:string}
 */
function km_staff_request_decide(PDO $pdo, int $id, string $action, string $decidedBy): array
{
    km_staff_requests_ensure_table($pdo);
    $transitions = ['approve' => ['pending', 'approved'], 'reject' => ['pending', 'rejected'], 'revoke' => ['approved', 'revoked']];
    if (!isset($transitions[$action])) {
        throw new InvalidArgumentException('操作の指定が不正です。');
    }
    [$from, $to] = $transitions[$action];

    $row = $pdo->prepare('SELECT user_id, status FROM km_staff_requests WHERE id = ?');
    $row->execute([$id]);
    $found = $row->fetch();
    if (!is_array($found)) {
        throw new InvalidArgumentException('申請が見つかりません。');
    }
    if ($found['status'] !== $from) {
        throw new InvalidArgumentException('この申請は既に処理されています。');
    }
    $userId = (string) $found['user_id'];

    if ($action === 'approve') {
        km_staff_org_grant($userId);
    } elseif ($action === 'revoke') {
        km_staff_org_revoke($userId);
    }

    // 同時に 2 人が押しても 1 回しか通らないよう、前の状態を条件にする
    $update = $pdo->prepare('UPDATE km_staff_requests SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ? AND status = ?');
    $update->execute([$to, mb_substr($decidedBy, 0, 64), $id, $from]);

    return ['userId' => $userId, 'status' => $to];
}
