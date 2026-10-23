<?php

declare(strict_types=1);

/**
 * イベントモード —— 地図への一時的な重ね合わせ。
 *
 * 高専祭・オープンキャンパス・式典・工事のあいだだけ、通行止めや臨時の地点を足したい。
 *
 * ## 恒久データには一切触れない
 *
 * 地図の正本は km_map_nodes(604件)/ km_map_edges(598件)で、**教職員氏名もここにある**。
 * イベントのたびにこれを書き換えると、戻すときに壊す危険がある
 * (チェックリストの「編集後に教職員氏名の件数が減っていないこと」はそのための項目)。
 *
 * そこで別テーブルに「重ね合わせ」として持ち、読み出すときに被せる。
 * **イベントを止める = 被せるのをやめるだけ**なので、復旧の手間が無い。
 *
 * ## 同時に複数のイベントを有効にできる
 *
 * 工事の通行止め(数か月)の最中に高専祭(2日)が来る。片方しか持てないと、
 * どちらかを手で写すことになる。有効なものを**すべて重ねる**。
 *
 * ## 期間の判定は DB の NOW() で行う
 *
 * PHP 側で time() と比較しない。**DB と PHP のタイムゾーンがずれていると
 * 判定が静かに壊れる**(lib/map-rate-limit.php に、それで総当たり対策が無効化されていた
 * 前例が記録してある)。値が住んでいる場所で比べる。
 *
 * ## 通行止めはノードの組で持つ
 *
 * エッジの id ではなく from/to のノード id で持つ。admin/api/map-edit.php の
 * `edge.delete` も from/to で指定しており、**エッジの id は編集で振り直されうる**のに対し
 * ノード id は VARCHAR の主キーで安定しているため。
 */

require_once __DIR__ . '/db.php';

/** 臨時の地点の種別。任意の文字列を入れさせない(アイコンの対応表でもある)。 */
const KM_MAP_EVENT_POI_CATEGORIES = ['food', 'exhibit', 'reception', 'firstaid', 'toilet', 'stage', 'other'];

/**
 * テーブルを用意する。**管理側の入口からだけ呼ぶこと。**
 *
 * 読み出し(km_map_event_overlay)からは呼ばない。あれは公開トップの表示ごとに
 * 走る経路で、そこに CREATE TABLE を4本挟むと**毎回4往復増える**うえ、
 * 公開ページの1リクエストごとに CREATE 権限を使うことになる。
 * テーブルが無い場合は overlay 側が「イベント無し」として素通りするので支障はない。
 *
 * 1リクエスト内で何度呼ばれても実際に流すのは1回だけにする。
 */
function km_map_events_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            hide_occupant_names TINYINT(1) NOT NULL DEFAULT 1,
            banner_text VARCHAR(255) NULL,
            banner_url VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (is_enabled, starts_at, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_event_closures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            target_type VARCHAR(8) NOT NULL,
            from_node_id VARCHAR(32) NULL,
            to_node_id VARCHAR(32) NULL,
            node_id VARCHAR(32) NULL,
            reason VARCHAR(100) NULL,
            INDEX (event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_event_pois (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            floor_id VARCHAR(16) NOT NULL,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'other',
            x INT NOT NULL,
            y INT NOT NULL,
            anchor_node_id VARCHAR(32) NULL,
            note VARCHAR(255) NULL,
            INDEX (event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_event_aliases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            node_id VARCHAR(32) NOT NULL,
            alias VARCHAR(255) NOT NULL,
            UNIQUE KEY (event_id, node_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    /*
     * イベント中に出さない地点(2026-10-14。管理アプリで作ったイベントの取り込み)。
     * 通行止めとは別 —— **出さないだけで、経路は通ってよい**(通さないのは通行止め)。
     */
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_event_hidden (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            node_id VARCHAR(32) NOT NULL,
            UNIQUE KEY (event_id, node_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    /*
     * 管理アプリから取り込んだイベントの印(2026-10-14。lib/map-event-import.php)。
     * **km_map_events に列を足さず、別表にする** —— アプリの DB 利用者には ALTER が無い
     * (lib/map-data.php の km_map_floor_bounds と同じ判断)。
     * 行があるイベントは中身(通行止め・臨時の地点・名前・非表示)をアプリが持つので、
     * Website の地図編集では変えさせない(次の取り込みで消えるため)。
     */
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_event_sources (
            event_id INT NOT NULL PRIMARY KEY,
            app_uuid VARCHAR(64) NOT NULL,
            imported_at DATETIME NOT NULL,
            UNIQUE KEY (app_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    /*
     * イベントの設定(2026-10-23)。**km_map_events に列を足さず、別表にする**(ALTER が無い)。
     * only_event_nodes: イベント中は「イベントの地点」だけを出す(利用者の指示「イベント中ではイベントノードが設定されている場所のみ表示」)。
     * **行が無ければ ON**(アプリの MapEvent.onlyEventNodes の null と同じ)。出す地点は lib/map-data.php の km_map_event_node_visible
     */
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_event_options (
            event_id INT NOT NULL PRIMARY KEY,
            only_event_nodes TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    /*
     * Website で中身を直した時刻(2026-10-23、利用者の指示「アプリのイベントを Web でも直す」)。
     * 管理アプリから取り込んだイベントも Web で直せるようにした。次にアプリから取り込むと Web で直した分は置き換わるので、
     * 取り込みの前に「Web で直した内容があります」と知らせるために持つ。取り込むと消す
     */
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_event_web_edits (
            event_id INT NOT NULL PRIMARY KEY,
            edited_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 重ね合わせの子の表。イベントを消すとき・取り込み直すときに、ここに並べたものを消す。 */
const KM_MAP_EVENT_CHILD_TABLES = [
    'km_map_event_closures',
    'km_map_event_pois',
    'km_map_event_aliases',
    'km_map_event_hidden',
    'km_map_event_options',
    // Web で直した時刻(2026-10-23)。アプリから取り込み直したら消える(アプリの中身に戻るため)
    'km_map_event_web_edits',
];

/**
 * Website の地図編集でイベントの中身を直したことを記録する(2026-10-23)。
 *
 * 以前は管理アプリから取り込んだイベントを Web では直させなかった(次の取り込みで黙って消えるため)。
 * 利用者の指示で**どちらでも直せる**ようにした。代わりに、直した時刻を覚えておき、
 * アプリから取り込み直すときに「Web で直した内容は置き換わります」と知らせる(admin/map-events.php)。
 * アプリへは、管理アプリの「Website から取り込む」(api/map-events.php)で戻せる。
 */
function km_map_event_touch_web_edit(PDO $pdo, int $eventId): void
{
    km_map_events_ensure_tables($pdo);
    if (km_map_event_find($pdo, $eventId) === null) {
        throw new InvalidArgumentException('そのイベントは見つかりません。');
    }
    $pdo->prepare('DELETE FROM km_map_event_web_edits WHERE event_id = ?')->execute([$eventId]);
    $pdo->prepare('INSERT INTO km_map_event_web_edits (event_id, edited_at) VALUES (?, NOW())')->execute([$eventId]);
}

/** Website で直した時刻(event_id => 'Y-m-d H:i:s')。表が無ければ空 */
function km_map_event_web_edits(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT event_id, edited_at FROM km_map_event_web_edits')->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
    $edits = [];
    foreach ($rows as $row) {
        $edits[(int) $row['event_id']] = (string) $row['edited_at'];
    }
    return $edits;
}

/**
 * イベント中に出さない地点を入り切りする(2026-10-23。Website の地図編集の「表示しない」)。出さないにしたら true
 */
function km_map_event_hidden_toggle(PDO $pdo, int $eventId, string $nodeId): bool
{
    km_map_events_ensure_tables($pdo);
    if (preg_match('/^[0-9A-Za-z_-]{1,32}$/', $nodeId) !== 1) {
        throw new InvalidArgumentException('地点の指定が正しくありません。');
    }
    $stmt = $pdo->prepare('SELECT 1 FROM km_map_event_hidden WHERE event_id = ? AND node_id = ?');
    $stmt->execute([$eventId, $nodeId]);
    if ($stmt->fetchColumn() !== false) {
        $pdo->prepare('DELETE FROM km_map_event_hidden WHERE event_id = ? AND node_id = ?')->execute([$eventId, $nodeId]);
        return false;
    }
    $pdo->prepare('INSERT INTO km_map_event_hidden (event_id, node_id) VALUES (?, ?)')->execute([$eventId, $nodeId]);
    return true;
}

/**
 * 「いま有効」の条件。**この式が正本**なので、他の場所で書き写さないこと。
 *
 * is_enabled が入口。期間は未設定(NULL)なら制限なしとみなす:
 *   starts_at NULL … すぐ始まる
 *   ends_at   NULL … 手動で止めるまで(工事の長期通行止め向け)
 */
const KM_MAP_EVENT_ACTIVE_SQL =
    'is_enabled = 1
     AND (starts_at IS NULL OR starts_at <= NOW())
     AND (ends_at IS NULL OR ends_at > NOW())';

/**
 * いま有効なイベント。
 *
 * @return array<int, array{id:int,name:string,hideOccupantNames:bool,bannerText:?string,bannerUrl:?string,endsAt:?string}>
 */
function km_map_event_active(PDO $pdo): array
{
    // **ここでは ensure_tables を呼ばない。** 公開トップの表示ごとに走る経路のため。
    // テーブルが未作成なら例外になり、呼び出し元(overlay)が「イベント無し」に倒す
    $rows = $pdo->query(
        'SELECT id, name, hide_occupant_names AS hideOccupantNames,
                banner_text AS bannerText, banner_url AS bannerUrl,
                ends_at AS endsAt
         FROM km_map_events
         WHERE ' . KM_MAP_EVENT_ACTIVE_SQL . '
         ORDER BY id'
    )->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['hideOccupantNames'] = (bool) $row['hideOccupantNames'];
    }
    unset($row);

    return $rows;
}

/**
 * 通行止め・臨時地点・臨時名称をまとめて取る。
 *
 * $previewEventId を渡すと、**まだ有効になっていないイベントも含めて**組み立てる。
 * 管理画面のプレビュー専用(公開ページからは渡さない)。
 *
 * @return array{
 *   events: array<int, array>,
 *   hideOccupantNames: bool,
 *   closedEdges: array<string, string>,   // "from\tto" => 理由
 *   closedNodes: array<string, string>,   // nodeId => 理由
 *   pois: array<int, array>,
 *   aliases: array<string, string>,       // nodeId => 別名
 *   hiddenNodes: array<string, bool>,     // nodeId => true(イベント中は出さない)
 *   onlyEventNodes: bool                  // イベントの地点だけを出す(有効なイベントのどれかが ON。2026-10-23)
 * }
 */
function km_map_event_overlay(PDO $pdo, ?int $previewEventId = null, bool $onlyPreview = false): array
{
    $empty = [
        'events' => [],
        'hideOccupantNames' => false,
        'closedEdges' => [],
        'closedNodes' => [],
        'pois' => [],
        'aliases' => [],
        'hiddenNodes' => [],
        'onlyEventNodes' => false,
    ];

    try {
        // $onlyPreview: そのイベント 1 つだけ(管理アプリへ渡すとき。api/map-events.php)。有効かどうかは問わない
        $events = $onlyPreview ? [] : km_map_event_active($pdo);

        if ($previewEventId !== null && !in_array($previewEventId, array_column($events, 'id'), true)) {
            $stmt = $pdo->prepare(
                'SELECT id, name, hide_occupant_names AS hideOccupantNames,
                        banner_text AS bannerText, banner_url AS bannerUrl, ends_at AS endsAt
                 FROM km_map_events WHERE id = ?'
            );
            $stmt->execute([$previewEventId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $row['id'] = (int) $row['id'];
                $row['hideOccupantNames'] = (bool) $row['hideOccupantNames'];
                $events[] = $row;
            }
        }

        if ($events === []) {
            return $empty;
        }

        $ids = array_column($events, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));

        $overlay = $empty;
        $overlay['events'] = $events;
        foreach ($events as $event) {
            if ($event['hideOccupantNames']) {
                $overlay['hideOccupantNames'] = true;
            }
        }

        // --- 通行止め ---
        $stmt = $pdo->prepare(
            "SELECT target_type AS targetType, from_node_id AS fromNode, to_node_id AS toNode,
                    node_id AS nodeId, reason
             FROM km_map_event_closures WHERE event_id IN ($in)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $reason = (string) ($row['reason'] ?? '');
            if ($row['targetType'] === 'node' && $row['nodeId'] !== null) {
                $overlay['closedNodes'][(string) $row['nodeId']] = $reason;
                continue;
            }
            if ($row['fromNode'] === null || $row['toNode'] === null) {
                continue;
            }
            // **両方向を入れる。** エッジは無向で、配信時の source/target がどちら向きかは
            // 保証されない。片方だけだと「閉じたはずの道が通れる」ことになる
            $overlay['closedEdges'][km_map_event_edge_key((string) $row['fromNode'], (string) $row['toNode'])] = $reason;
            $overlay['closedEdges'][km_map_event_edge_key((string) $row['toNode'], (string) $row['fromNode'])] = $reason;
        }

        // --- 臨時の地点 ---
        $stmt = $pdo->prepare(
            "SELECT id, event_id AS eventId, floor_id AS floor, name, category, x, y,
                    anchor_node_id AS anchorNodeId, note
             FROM km_map_event_pois WHERE event_id IN ($in) ORDER BY id"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $row['eventId'] = (int) $row['eventId'];
            $row['x'] = (int) $row['x'];
            $row['y'] = (int) $row['y'];
            $overlay['pois'][] = $row;
        }

        // --- 臨時名称 ---
        $stmt = $pdo->prepare(
            "SELECT node_id AS nodeId, alias FROM km_map_event_aliases WHERE event_id IN ($in)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $overlay['aliases'][(string) $row['nodeId']] = (string) $row['alias'];
        }

        /*
         * --- 出さない地点(2026-10-14) ---
         *
         * **ここだけは失敗しても続ける。** 表は管理画面を開いたときに作られるので、
         * 配備してから管理画面を開くまでの間は無い。そのせいで通行止めまで消えては困る。
         */
        try {
            $stmt = $pdo->prepare("SELECT node_id AS nodeId FROM km_map_event_hidden WHERE event_id IN ($in)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $overlay['hiddenNodes'][(string) $row['nodeId']] = true;
            }
        } catch (Throwable $exception) {
            error_log('km_map_event_overlay: km_map_event_hidden is not readable yet: ' . $exception->getMessage());
        }

        // --- イベントの地点だけを出すか(2026-10-23)。行が無い・表がまだ無いイベントは ON ---
        $options = km_map_event_options_read($pdo, $ids);
        foreach ($ids as $eventId) {
            if ($options[(int) $eventId] ?? true) {
                $overlay['onlyEventNodes'] = true;
            }
        }

        return $overlay;
    } catch (Throwable $exception) {
        /*
         * **イベントの不調で地図そのものを落とさない。**
         * 重ね合わせが取れなければ「イベント無し」= 通常の地図として続ける。
         * 通行止めが効かない方が、地図が出ないより害が小さい。
         */
        error_log(
            'km_map_event_overlay failed (falling back to no event; '
            . 'the tables are created on demand by km_map_events_ensure_tables): '
            . $exception->getMessage()
        );
        return $empty;
    }
}

/**
 * イベントの「イベントの地点だけを出す」(event_id => bool)。**行が無いイベントは入れない**(呼ぶ側で ON とみなす)。
 * 表がまだ無いときも空(管理画面を開くまで作られない。公開の地図を止めない)。
 *
 * @param list<int> $eventIds
 * @return array<int, bool>
 */
function km_map_event_options_read(PDO $pdo, array $eventIds): array
{
    if ($eventIds === []) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT event_id, only_event_nodes FROM km_map_event_options WHERE event_id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ')');
        $stmt->execute(array_values($eventIds));
        $options = [];
        foreach ($stmt->fetchAll() as $row) {
            $options[(int) $row['event_id']] = (int) $row['only_event_nodes'] === 1;
        }
        return $options;
    } catch (Throwable $exception) {
        return [];
    }
}

/** イベントの「イベントの地点だけを出す」を決める(行を入れ替える。ON でも行を書く) */
function km_map_event_options_save(PDO $pdo, int $eventId, bool $onlyEventNodes): void
{
    km_map_events_ensure_tables($pdo);
    $pdo->prepare('DELETE FROM km_map_event_options WHERE event_id = ?')->execute([$eventId]);
    $pdo->prepare('INSERT INTO km_map_event_options (event_id, only_event_nodes) VALUES (?, ?)')->execute([$eventId, $onlyEventNodes ? 1 : 0]);
}

/** 通行止めの照合に使うキー。source/target の向きを含めて1本ぶん。 */
function km_map_event_edge_key(string $from, string $to): string
{
    return $from . "\t" . $to;
}

// ---------------------------------------------------------------- 管理画面向け

/** @return array<int, array> 一覧(有効・無効を問わず全部) */
function km_map_events_all(PDO $pdo): array
{
    km_map_events_ensure_tables($pdo);

    $rows = $pdo->query(
        'SELECT e.id, e.name, e.starts_at AS startsAt, e.ends_at AS endsAt,
                e.is_enabled AS isEnabled, e.hide_occupant_names AS hideOccupantNames,
                e.banner_text AS bannerText, e.banner_url AS bannerUrl,
                (' . KM_MAP_EVENT_ACTIVE_SQL . ') AS isActive,
                (e.is_enabled = 1 AND e.ends_at IS NOT NULL AND e.ends_at <= NOW()) AS isExpired,
                (SELECT COUNT(*) FROM km_map_event_closures c WHERE c.event_id = e.id) AS closureCount,
                (SELECT COUNT(*) FROM km_map_event_pois p WHERE p.event_id = e.id) AS poiCount,
                (SELECT COUNT(*) FROM km_map_event_aliases a WHERE a.event_id = e.id) AS aliasCount,
                (SELECT COUNT(*) FROM km_map_event_hidden h WHERE h.event_id = e.id) AS hiddenCount,
                s.app_uuid AS appUuid, s.imported_at AS importedAt
         FROM km_map_events e
         LEFT JOIN km_map_event_sources s ON s.event_id = e.id
         ORDER BY e.is_enabled DESC, e.id DESC'
    )->fetchAll();

    $options = km_map_event_options_read($pdo, array_map('intval', array_column($rows, 'id')));
    $webEdits = km_map_event_web_edits($pdo);
    foreach ($rows as &$row) {
        $row['onlyEventNodes'] = $options[(int) $row['id']] ?? true;
        $row['webEditedAt'] = $webEdits[(int) $row['id']] ?? null;
        foreach (['id', 'closureCount', 'poiCount', 'aliasCount', 'hiddenCount'] as $intKey) {
            $row[$intKey] = (int) $row[$intKey];
        }
        foreach (['isEnabled', 'hideOccupantNames', 'isActive', 'isExpired'] as $boolKey) {
            $row[$boolKey] = (bool) $row[$boolKey];
        }
    }
    unset($row);

    return $rows;
}

/** @return array|null */
function km_map_event_find(PDO $pdo, int $id): ?array
{
    km_map_events_ensure_tables($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, name, starts_at AS startsAt, ends_at AS endsAt,
                is_enabled AS isEnabled, hide_occupant_names AS hideOccupantNames,
                banner_text AS bannerText, banner_url AS bannerUrl
         FROM km_map_events WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['isEnabled'] = (bool) $row['isEnabled'];
    $row['hideOccupantNames'] = (bool) $row['hideOccupantNames'];
    $row['onlyEventNodes'] = km_map_event_options_read($pdo, [$id])[$id] ?? true;

    return $row;
}

/**
 * 日時入力(datetime-local)の検証。空文字は NULL(= 制限なし)。
 */
function km_map_event_normalize_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // "2026-08-20T09:00" / "2026-08-20 09:00" のどちらも受ける
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
        throw new InvalidArgumentException('日時の形式が正しくありません。');
    }

    return strlen($value) === 16 ? $value . ':00' : $value;
}

function km_map_event_validate(string $name, ?string $startsAt, ?string $endsAt, string $bannerUrl): void
{
    if (trim($name) === '') {
        throw new InvalidArgumentException('イベント名を入力してください。');
    }
    if (mb_strlen($name) > 100) {
        throw new InvalidArgumentException('イベント名は100文字以内にしてください。');
    }
    if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
        throw new InvalidArgumentException('終了日時は開始日時より後にしてください。');
    }
    // バナーのリンクは**このサイト内の絶対パスだけ**。外部への誘導に使わせない
    if ($bannerUrl !== '' && preg_match('#^/(?!/)[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*$#', $bannerUrl) !== 1) {
        throw new InvalidArgumentException('案内リンクはサイト内のパス(/ で始まる)にしてください。');
    }
}

function km_map_event_save(PDO $pdo, ?int $id, array $fields): int
{
    km_map_events_ensure_tables($pdo);

    $name = (string) ($fields['name'] ?? '');
    $startsAt = km_map_event_normalize_datetime((string) ($fields['startsAt'] ?? ''));
    $endsAt = km_map_event_normalize_datetime((string) ($fields['endsAt'] ?? ''));
    $bannerText = trim((string) ($fields['bannerText'] ?? ''));
    $bannerUrl = trim((string) ($fields['bannerUrl'] ?? ''));

    km_map_event_validate($name, $startsAt, $endsAt, $bannerUrl);

    $params = [
        $name,
        $startsAt,
        $endsAt,
        !empty($fields['isEnabled']) ? 1 : 0,
        !empty($fields['hideOccupantNames']) ? 1 : 0,
        $bannerText !== '' ? mb_substr($bannerText, 0, 255) : null,
        $bannerUrl !== '' ? $bannerUrl : null,
    ];

    if ($id === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO km_map_events
                (name, starts_at, ends_at, is_enabled, hide_occupant_names, banner_text, banner_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute($params);
        $newId = (int) $pdo->lastInsertId();
        if (array_key_exists('onlyEventNodes', $fields)) {
            km_map_event_options_save($pdo, $newId, (bool) $fields['onlyEventNodes']);
        }

        return $newId;
    }

    $params[] = $id;
    $stmt = $pdo->prepare(
        'UPDATE km_map_events
         SET name = ?, starts_at = ?, ends_at = ?, is_enabled = ?, hide_occupant_names = ?,
             banner_text = ?, banner_url = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute($params);
    if (array_key_exists('onlyEventNodes', $fields)) {
        km_map_event_options_save($pdo, $id, (bool) $fields['onlyEventNodes']);
    }

    return $id;
}

/**
 * イベントを削除する。**重ね合わせも一緒に消す。**
 *
 * 外部キーで CASCADE させていないのは、既存の km_map_* が
 * 「FK は恒久データの中だけ」で組まれているため。ここは明示的に消す。
 */
function km_map_event_delete(PDO $pdo, int $id): void
{
    km_map_events_ensure_tables($pdo);

    $pdo->beginTransaction();
    try {
        foreach ([...KM_MAP_EVENT_CHILD_TABLES, 'km_map_event_sources'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE event_id = ?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM km_map_events WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

// ---------------------------------------------------------------- 期限切れの掃除

/** 掃除のしかた。管理画面の「イベント」で選ぶ。 */
const KM_EVENT_CLEANUP_MODES = ['manual', 'auto'];

/** 設定の名前(km_settings の主キー)。 */
const KM_EVENT_CLEANUP_SETTING = 'map_event_cleanup';

/**
 * 終了してから、実際に消すまでの猶予。
 *
 * **0 にしないこと。** `ends_at` を打ち間違えた瞬間にイベントが消えると、
 * 通行止め・一時地点・臨時名称まで巻き添えで失われ、戻す手段が無い
 * (`km_map_event_delete` は子テーブルごと消す)。
 *
 * 7日あれば、翌週に気づいて止められる。会期が終わってから1週間、
 * 使われないイベントが表に残るだけの代償で釣り合う。
 */
const KM_EVENT_CLEANUP_GRACE_DAYS = 7;

/**
 * いまの掃除のしかた。未設定・不正な値は 'manual'。
 *
 * **既定を manual にしてある。** 消す方を既定にすると、設定の存在を知らないまま
 * イベントが消えることになる。
 */
function km_map_event_cleanup_mode(PDO $pdo): string
{
    require_once __DIR__ . '/settings.php';
    $mode = km_setting_get($pdo, KM_EVENT_CLEANUP_SETTING, 'manual');

    return in_array($mode, KM_EVENT_CLEANUP_MODES, true) ? $mode : 'manual';
}

/** 掃除のしかたを保存する。知らない値は受け付けない。 */
function km_map_event_cleanup_mode_save(PDO $pdo, string $mode): void
{
    if (!in_array($mode, KM_EVENT_CLEANUP_MODES, true)) {
        throw new InvalidArgumentException('不正な掃除モードです。');
    }
    require_once __DIR__ . '/settings.php';
    km_setting_set($pdo, KM_EVENT_CLEANUP_SETTING, $mode);
}

/**
 * 掃除の対象になるイベント。**消す前に見せるためにも使う。**
 *
 * 条件は「終了時刻があり、そこから猶予日数を過ぎている」。
 * `is_enabled` は見ない —— 無効にしたまま置き忘れたものも、期限を過ぎていれば同じ扱い。
 *
 * @return array<int, array{id:int, name:string, endsAt:string}>
 */
function km_map_events_expired(PDO $pdo, int $graceDays = KM_EVENT_CLEANUP_GRACE_DAYS): array
{
    km_map_events_ensure_tables($pdo);

    $statement = $pdo->prepare(
        'SELECT id, name, ends_at
         FROM km_map_events
         WHERE ends_at IS NOT NULL AND ends_at <= (NOW() - INTERVAL ? DAY)
         ORDER BY ends_at'
    );
    $statement->execute([max(0, $graceDays)]);

    return array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'endsAt' => (string) $row['ends_at'],
        ],
        $statement->fetchAll()
    );
}

/**
 * 期限切れのイベントを消す。**自動・手動のどちらからも呼ぶ。**
 *
 * 1件ずつ [km_map_event_delete] に渡すので、子テーブル(通行止め・一時地点・臨時名称)も
 * 一緒に消える。1件失敗しても他は続ける —— 途中で止まると、
 * 何が消えて何が残ったのか分からない状態になる。
 *
 * @return array<int, string> 消したイベントの名前
 */
function km_map_events_delete_expired(
    PDO $pdo,
    int $graceDays = KM_EVENT_CLEANUP_GRACE_DAYS
): array {
    $deleted = [];
    foreach (km_map_events_expired($pdo, $graceDays) as $event) {
        try {
            km_map_event_delete($pdo, $event['id']);
            $deleted[] = $event['name'];
        } catch (Throwable $exception) {
            error_log(
                "km_map_events_delete_expired: id={$event['id']} を消せませんでした: "
                . $exception->getMessage()
            );
        }
    }

    return $deleted;
}

// ---------------------------------------------------------------- 重ね合わせの編集

/** エッジの通行止めを切り替える。既にあれば消し、無ければ足す。@return bool 追加したなら true */
function km_map_event_closure_toggle_edge(PDO $pdo, int $eventId, string $from, string $to, string $reason): bool
{
    km_map_events_ensure_tables($pdo);

    // 向きを問わず同じ1本として扱う
    $stmt = $pdo->prepare(
        "SELECT id FROM km_map_event_closures
         WHERE event_id = ? AND target_type = 'edge'
           AND ((from_node_id = ? AND to_node_id = ?) OR (from_node_id = ? AND to_node_id = ?))"
    );
    $stmt->execute([$eventId, $from, $to, $to, $from]);
    $existing = $stmt->fetchColumn();

    if ($existing !== false) {
        $pdo->prepare('DELETE FROM km_map_event_closures WHERE id = ?')->execute([$existing]);
        return false;
    }

    $pdo->prepare(
        "INSERT INTO km_map_event_closures (event_id, target_type, from_node_id, to_node_id, reason)
         VALUES (?, 'edge', ?, ?, ?)"
    )->execute([$eventId, $from, $to, $reason !== '' ? mb_substr($reason, 0, 100) : null]);

    return true;
}

/** ノードの通行止めを切り替える。@return bool 追加したなら true */
function km_map_event_closure_toggle_node(PDO $pdo, int $eventId, string $nodeId, string $reason): bool
{
    km_map_events_ensure_tables($pdo);

    $stmt = $pdo->prepare(
        "SELECT id FROM km_map_event_closures WHERE event_id = ? AND target_type = 'node' AND node_id = ?"
    );
    $stmt->execute([$eventId, $nodeId]);
    $existing = $stmt->fetchColumn();

    if ($existing !== false) {
        $pdo->prepare('DELETE FROM km_map_event_closures WHERE id = ?')->execute([$existing]);
        return false;
    }

    $pdo->prepare(
        "INSERT INTO km_map_event_closures (event_id, target_type, node_id, reason)
         VALUES (?, 'node', ?, ?)"
    )->execute([$eventId, $nodeId, $reason !== '' ? mb_substr($reason, 0, 100) : null]);

    return true;
}

function km_map_event_poi_save(PDO $pdo, int $eventId, ?int $poiId, array $fields): int
{
    km_map_events_ensure_tables($pdo);

    $name = trim((string) ($fields['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('地点の名前を入力してください。');
    }
    $category = (string) ($fields['category'] ?? 'other');
    if (!in_array($category, KM_MAP_EVENT_POI_CATEGORIES, true)) {
        throw new InvalidArgumentException('種別の指定が不正です。');
    }
    $floor = trim((string) ($fields['floor'] ?? ''));
    if ($floor === '') {
        throw new InvalidArgumentException('階が指定されていません。');
    }
    $anchor = trim((string) ($fields['anchorNodeId'] ?? ''));
    $note = trim((string) ($fields['note'] ?? ''));

    $params = [
        $floor,
        mb_substr($name, 0, 255),
        $category,
        (int) ($fields['x'] ?? 0),
        (int) ($fields['y'] ?? 0),
        $anchor !== '' ? $anchor : null,
        $note !== '' ? mb_substr($note, 0, 255) : null,
    ];

    if ($poiId === null) {
        array_unshift($params, $eventId);
        $pdo->prepare(
            'INSERT INTO km_map_event_pois (event_id, floor_id, name, category, x, y, anchor_node_id, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute($params);

        return (int) $pdo->lastInsertId();
    }

    $params[] = $poiId;
    $params[] = $eventId;
    $pdo->prepare(
        'UPDATE km_map_event_pois
         SET floor_id = ?, name = ?, category = ?, x = ?, y = ?, anchor_node_id = ?, note = ?
         WHERE id = ? AND event_id = ?'
    )->execute($params);

    return $poiId;
}

function km_map_event_poi_delete(PDO $pdo, int $eventId, int $poiId): void
{
    km_map_events_ensure_tables($pdo);
    $pdo->prepare('DELETE FROM km_map_event_pois WHERE id = ? AND event_id = ?')->execute([$poiId, $eventId]);
}

/** 臨時名称を設定する。$alias が空なら解除。 */
function km_map_event_alias_set(PDO $pdo, int $eventId, string $nodeId, string $alias): void
{
    km_map_events_ensure_tables($pdo);

    $alias = trim($alias);
    if ($alias === '') {
        $pdo->prepare('DELETE FROM km_map_event_aliases WHERE event_id = ? AND node_id = ?')
            ->execute([$eventId, $nodeId]);
        return;
    }

    $pdo->prepare(
        'INSERT INTO km_map_event_aliases (event_id, node_id, alias) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE alias = VALUES(alias)'
    )->execute([$eventId, $nodeId, mb_substr($alias, 0, 255)]);
}

/**
 * その重ね合わせで**到達できなくなるノード**を数える。
 *
 * 保存を止めはしない(工事で本当に孤立する区画はありうる)。
 * ただし**気付かずに全館を切り離す**のは避けたいので、管理画面で件数を見せる。
 *
 * @return array{isolated:int, total:int}
 */
function km_map_event_isolation_count(PDO $pdo, array $overlay): array
{
    $nodes = $pdo->query('SELECT id FROM km_map_nodes')->fetchAll(PDO::FETCH_COLUMN);
    $edges = $pdo->query('SELECT from_node_id AS f, to_node_id AS t FROM km_map_edges')->fetchAll();

    $total = count($nodes);
    if ($total === 0) {
        return ['isolated' => 0, 'total' => 0];
    }

    $adjacency = [];
    foreach ($edges as $edge) {
        $from = (string) $edge['f'];
        $to = (string) $edge['t'];
        if (isset($overlay['closedEdges'][km_map_event_edge_key($from, $to)])) {
            continue;
        }
        if (isset($overlay['closedNodes'][$from]) || isset($overlay['closedNodes'][$to])) {
            continue;
        }
        $adjacency[$from][] = $to;
        $adjacency[$to][] = $from;
    }

    // 通行止めでないノードから1つ選んで幅優先。届かなかったものを数える
    $start = null;
    foreach ($nodes as $node) {
        if (!isset($overlay['closedNodes'][(string) $node])) {
            $start = (string) $node;
            break;
        }
    }
    if ($start === null) {
        return ['isolated' => $total, 'total' => $total];
    }

    $seen = [$start => true];
    $queue = [$start];
    while ($queue !== []) {
        $current = array_shift($queue);
        foreach ($adjacency[$current] ?? [] as $next) {
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                $queue[] = $next;
            }
        }
    }

    return ['isolated' => $total - count($seen), 'total' => $total];
}
