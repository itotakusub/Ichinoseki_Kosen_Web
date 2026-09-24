<?php

declare(strict_types=1);

/**
 * 教職員による地点の編集(docs/15 段 G。2026-09-18)。
 *
 * ## 形(利用者が決めたこと)
 *
 * | 何 | どうする |
 * |---|---|
 * | 誰がどの地点を | **管理者が地点の ID(UUID)を教職員に割り当てる**(`km_staff_node_assignments`)。割り当てた地点だけ |
 * | 何を | 地点の**文字の項目すべて**(名前・部屋番号など・教職員氏名・メモ) |
 * | 在室・不在 | **作らない**(Teams で見られる) |
 * | 反映 | **管理者の確認を挟む。** 教職員の送ったものは提案(`km_staff_node_edits`)として貯め、承認したときだけ地図に入る |
 *
 * ## 位置・種類・経路は触らせない
 *
 * 座標・階・種類・線は**経路探索と表示の骨組み**で、間違えると他の人の案内まで狂う。
 * 文字の項目だけなら、間違えても「その地点の表示」が変わるだけで済む。
 *
 * ## 提案は「変えた項目だけ」を持つ
 *
 * 送られた時点の値(`from`)と新しい値(`to`)の組で持ち、承認のときは `to` だけを書く。
 * **送っていない項目は触らない** —— 管理者が同じ地点を直していても、上書きしない
 * (lib/map-edit.php の「読み込んだ値を送り返して消す」事故と同じ形を作らない)。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/map-edit.php';
require_once __DIR__ . '/app-map-sync.php';
require_once __DIR__ . '/staff-org.php';

/** 提案の状態。`withdrawn` は新しい提案で置き換わった・割り当てが外れた。 */
const KM_STAFF_NODE_EDIT_STATUSES = ['pending', 'approved', 'rejected', 'withdrawn'];

/**
 * 教職員が変えられる項目と上限。**ここに無い項目は受け取らない。**
 * `note` は列が在る環境だけ(scripts/migrate-map-app-schema.sql)。
 */
const KM_STAFF_NODE_FIELDS = [
    'title' => 255,
    'subtitle' => 64,
    'occupantName' => 255,
    'note' => 500,
];

function km_staff_nodes_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_staff_node_assignments (
            node_id VARCHAR(32) NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            created_by VARCHAR(64) NULL,
            PRIMARY KEY (node_id, user_id),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_staff_node_edits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_id VARCHAR(32) NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            changes_json TEXT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decided_by VARCHAR(64) NULL,
            INDEX (status),
            INDEX (user_id),
            INDEX (node_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

/** 地点の ID を受け取った形(UUID・ハイフン無し)から、表の `id`(32 字)にする。形が違えば null。 */
function km_staff_node_normalize_id(string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $input) === 1) {
        return strtolower(str_replace('-', '', $input));
    }
    // UUID 以前の id(旧 Web の採番)も在るので、表に入りうる形なら通す。在るかどうかは呼び出し側で確かめる
    if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $input) === 1) {
        return $input;
    }

    return null;
}

/** 教職員が変えられる項目の、いまの値(列が無い項目は含めない)。 */
function km_staff_node_current_fields(PDO $pdo, array $node): array
{
    $fields = [
        'title' => (string) ($node['title'] ?? $node['name'] ?? ''),
        'subtitle' => (string) ($node['subtitle'] ?? ''),
        'occupantName' => (string) ($node['occupantName'] ?? ''),
    ];
    if (in_array('note', km_map_nodes_columns($pdo), true)) {
        $fields['note'] = (string) ($node['note'] ?? '');
    }

    return $fields;
}

// ---------------------------------------------------------------- 割り当て

/**
 * 地点を教職員に割り当てる。**承認済みの教職員にだけ**(申請の表で確かめる)。
 *
 * @return string 表の地点 id
 */
function km_staff_node_assign(PDO $pdo, string $nodeInput, string $userId, string $by): string
{
    km_staff_nodes_ensure_tables($pdo);
    $nodeId = km_staff_node_normalize_id($nodeInput);
    if ($nodeId === null) {
        throw new InvalidArgumentException('地点の ID の形が不正です(地図編集の「地点の ID」をそのまま貼ってください)。');
    }
    km_map_node_require($pdo, $nodeId);
    if (!km_staff_org_valid_user_id($userId)) {
        throw new InvalidArgumentException('教職員の指定が不正です。');
    }
    if ((km_staff_request_latest($pdo, $userId)['status'] ?? null) !== 'approved') {
        throw new InvalidArgumentException('承認済みの教職員にだけ割り当てられます。');
    }
    $pdo->prepare('INSERT IGNORE INTO km_staff_node_assignments (node_id, user_id, created_at, created_by) VALUES (?, ?, NOW(), ?)')
        ->execute([$nodeId, $userId, mb_substr($by, 0, 64)]);

    return $nodeId;
}

/** 割り当てを外す。**未処理の提案も取り下げる**(もう編集できない人の提案を承認させない)。 */
function km_staff_node_unassign(PDO $pdo, string $nodeId, string $userId): void
{
    km_staff_nodes_ensure_tables($pdo);
    $pdo->prepare('DELETE FROM km_staff_node_assignments WHERE node_id = ? AND user_id = ?')->execute([$nodeId, $userId]);
    $pdo->prepare("UPDATE km_staff_node_edits SET status = 'withdrawn', decided_at = NOW() WHERE node_id = ? AND user_id = ? AND status = 'pending'")
        ->execute([$nodeId, $userId]);
}

function km_staff_node_is_assigned(PDO $pdo, string $nodeId, string $userId): bool
{
    km_staff_nodes_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM km_staff_node_assignments WHERE node_id = ? AND user_id = ?');
    $stmt->execute([$nodeId, $userId]);

    return $stmt->fetchColumn() !== false;
}

/** @return list<array{nodeId:string, userId:string, createdAtEpoch:int}> */
function km_staff_node_assignments(PDO $pdo, ?string $userId = null): array
{
    km_staff_nodes_ensure_tables($pdo);
    $sql = 'SELECT node_id, user_id, UNIX_TIMESTAMP(created_at) AS c FROM km_staff_node_assignments';
    $params = [];
    if ($userId !== null) {
        $sql .= ' WHERE user_id = ?';
        $params[] = $userId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT 1000');
    $stmt->execute($params);

    return array_map(static fn (array $r): array => [
        'nodeId' => (string) $r['node_id'],
        'userId' => (string) $r['user_id'],
        'createdAtEpoch' => (int) $r['c'],
    ], $stmt->fetchAll());
}

// ---------------------------------------------------------------- 提案

/**
 * 送られた値から「変えた項目」を作る。**知らない項目は捨てる。** 何も変えていなければ空。
 *
 * @param array<string, string> $current いまの値(km_staff_node_current_fields)
 * @param array<string, mixed> $input 送られた値
 * @return array<string, array{from:string, to:string}>
 */
function km_staff_node_diff(array $current, array $input): array
{
    $changes = [];
    foreach (KM_STAFF_NODE_FIELDS as $field => $max) {
        if (!array_key_exists($field, $current) || !array_key_exists($field, $input)) {
            continue;
        }
        $to = trim((string) $input[$field]);
        if (mb_strlen($to) > $max) {
            throw new InvalidArgumentException("{$max} 文字を超える項目があります。");
        }
        if ($field === 'title' && $to === '') {
            throw new InvalidArgumentException('名前は空にできません。');
        }
        if ($to !== $current[$field]) {
            $changes[$field] = ['from' => $current[$field], 'to' => $to];
        }
    }

    return $changes;
}

/**
 * 教職員が地点の変更を送る。**割り当てられた地点だけ。** 同じ人・同じ地点の未処理の提案は置き換える
 * (何度直しても、管理者が見るのは最新の 1 件)。
 *
 * 教職員かどうかは**呼び出し側がトークンで確かめてから**呼ぶ(account.php は ID トークンの organization_roles)。
 *
 * @return int|null 提案の番号(何も変えていなければ null)
 */
function km_staff_node_propose(PDO $pdo, string $nodeId, string $userId, array $input): ?int
{
    km_staff_nodes_ensure_tables($pdo);
    if (!km_staff_node_is_assigned($pdo, $nodeId, $userId)) {
        throw new InvalidArgumentException('この地点は編集できません(割り当てられていません)。');
    }
    $node = km_map_node_require($pdo, $nodeId);
    $changes = km_staff_node_diff(km_staff_node_current_fields($pdo, $node), $input);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE km_staff_node_edits SET status = 'withdrawn', decided_at = NOW() WHERE node_id = ? AND user_id = ? AND status = 'pending'")
            ->execute([$nodeId, $userId]);
        if ($changes === []) {
            $pdo->commit();

            return null;
        }
        $pdo->prepare("INSERT INTO km_staff_node_edits (node_id, user_id, changes_json, status, created_at) VALUES (?, ?, ?, 'pending', NOW())")
            ->execute([$nodeId, $userId, json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();

        return $id;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @return list<array{id:int, nodeId:string, userId:string, changes:array<string, array{from:string, to:string}>, status:string, createdAtEpoch:int, decidedAtEpoch:?int}>
 */
function km_staff_node_edits(PDO $pdo, ?string $status = null, ?string $userId = null): array
{
    km_staff_nodes_ensure_tables($pdo);
    $where = [];
    $params = [];
    if ($status !== null) {
        if (!in_array($status, KM_STAFF_NODE_EDIT_STATUSES, true)) {
            throw new InvalidArgumentException('状態の指定が不正です。');
        }
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($userId !== null) {
        $where[] = 'user_id = ?';
        $params[] = $userId;
    }
    $sql = 'SELECT id, node_id, user_id, changes_json, status, UNIX_TIMESTAMP(created_at) AS c, UNIX_TIMESTAMP(decided_at) AS d FROM km_staff_node_edits'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY id DESC LIMIT 300';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(static function (array $r): array {
        $changes = json_decode((string) $r['changes_json'], true);

        return [
            'id' => (int) $r['id'],
            'nodeId' => (string) $r['node_id'],
            'userId' => (string) $r['user_id'],
            'changes' => is_array($changes) ? $changes : [],
            'status' => (string) $r['status'],
            'createdAtEpoch' => (int) $r['c'],
            'decidedAtEpoch' => $r['d'] !== null ? (int) $r['d'] : null,
        ];
    }, $stmt->fetchAll());
}

function km_staff_node_edits_pending_count(PDO $pdo): int
{
    km_staff_nodes_ensure_tables($pdo);

    return (int) $pdo->query("SELECT COUNT(*) FROM km_staff_node_edits WHERE status = 'pending'")->fetchColumn();
}

/**
 * 提案を処理する。承認なら**変えた項目だけ**を地図に書く。
 *
 * 承認の前に確かめること:
 *   - まだ割り当てられている(外したあとの提案は通さない)
 *   - 送った人がまだ承認済みの教職員(取り消したあとの提案は通さない)
 *
 * @return array{nodeId:string, userId:string, status:string}
 */
function km_staff_node_edit_decide(PDO $pdo, int $id, string $action, string $decidedBy): array
{
    km_staff_nodes_ensure_tables($pdo);
    /*
     * **表の用意は取引の前に済ませる。** 下の取引の中で km_staff_request_latest() が初めて
     * CREATE TABLE IF NOT EXISTS を流すと、MariaDB はそこで**暗黙に確定**してしまい、
     * 「押さえた状態だけ書かれて地図は変わらない」が残りうる。
     */
    km_staff_requests_ensure_table($pdo);
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('操作の指定が不正です。');
    }
    $stmt = $pdo->prepare('SELECT node_id, user_id, changes_json, status FROM km_staff_node_edits WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new InvalidArgumentException('提案が見つかりません。');
    }
    if ($row['status'] !== 'pending') {
        throw new InvalidArgumentException('この提案は既に処理されています。');
    }
    $nodeId = (string) $row['node_id'];
    $userId = (string) $row['user_id'];
    $to = $action === 'approve' ? 'approved' : 'rejected';

    $pdo->beginTransaction();
    try {
        // 同時に 2 人が押しても 1 回しか通らないよう、前の状態を条件にして先に押さえる
        $claim = $pdo->prepare("UPDATE km_staff_node_edits SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ? AND status = 'pending'");
        $claim->execute([$to, mb_substr($decidedBy, 0, 64), $id]);
        if ($claim->rowCount() !== 1) {
            throw new InvalidArgumentException('この提案は既に処理されています。');
        }
        if ($action === 'approve') {
            if (!km_staff_node_is_assigned($pdo, $nodeId, $userId)) {
                throw new InvalidArgumentException('この地点の割り当てが外れているため、承認できません。');
            }
            if ((km_staff_request_latest($pdo, $userId)['status'] ?? null) !== 'approved') {
                throw new InvalidArgumentException('送った人の教職員の権限が有効でないため、承認できません。');
            }
            $changes = json_decode((string) $row['changes_json'], true);
            km_staff_node_apply($pdo, $nodeId, is_array($changes) ? $changes : []);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return ['nodeId' => $nodeId, 'userId' => $userId, 'status' => $to];
}

/**
 * 変えた項目だけを地図に書く。書く関数は管理画面の地図編集と同じ(lib/map-edit.php)。
 *
 * @param array<string, array{from?:string, to?:string}> $changes
 */
function km_staff_node_apply(PDO $pdo, string $nodeId, array $changes): void
{
    $node = km_map_node_require($pdo, $nodeId);
    $current = km_staff_node_current_fields($pdo, $node);
    $value = static fn (string $field): ?string => isset($changes[$field]['to']) && is_string($changes[$field]['to'])
        ? $changes[$field]['to'] : null;

    if ($value('title') !== null || $value('subtitle') !== null) {
        km_map_node_update(
            $pdo,
            $nodeId,
            $value('title') ?? $current['title'],
            $value('subtitle') ?? $current['subtitle'],
            (string) $node['type']
        );
    }
    if ($value('occupantName') !== null) {
        km_map_node_set_occupant($pdo, $nodeId, $value('occupantName'));
    }
    if ($value('note') !== null && array_key_exists('note', $current)) {
        km_map_node_set_note($pdo, $nodeId, $value('note'));
    }
}

/**
 * 提案が来たことを管理者へ知らせる(メール)。**送れなくても提案は受け付けたまま。**
 * 本文に**氏名そのものは入れない**(管理画面で見る)。
 */
function km_staff_node_edit_notify(int $id, string $who, string $nodeLabel): void
{
    try {
        require_once __DIR__ . '/mailer.php';
        require_once __DIR__ . '/site.php';
        $link = km_site_admin_origin() . '/admin/staff-nodes.php';
        $body = "教職員から地点の変更の提案が届きました(#{$id})。\n\n"
            . "送った人: {$who}\n"
            . "地点    : {$nodeLabel}\n\n"
            . "内容の確認と承認・却下は管理画面から:\n{$link}\n\n"
            . "承認するまで地図には反映されません。アプリへは、承認のあと「地図の公開」で配信されます。\n";
        km_mail_send(km_mail_admin_to(), '[KosenMap] 地点の変更の提案 #' . $id, $body);
    } catch (Throwable $exception) {
        error_log('km_staff_node_edit_notify failed: ' . $exception->getMessage());
    }
}
