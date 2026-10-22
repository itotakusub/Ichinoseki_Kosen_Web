<?php

declare(strict_types=1);

/**
 * 企画のスケジュール(2026-10-23、利用者の指示「Website 側でスケジュール表を作成。それを元に、どのノードで何が開催されているか表記。
 * 一般向けに表示するかはチェックマークで」)。
 *
 * - 1 行 = 1 つの催し(企画の番号・名前・団体・説明・始まり・終わり・一般に出すか)
 * - **場所は企画の番号で決める。** 番号は管理アプリで地点に振る(lib/map-node-projects.php)。同じ番号の地点がいくつあれば、全部に出す
 * - 一般の地図に出すのは「一般に出す」に印を付けた行だけ(管理画面の「スケジュール」では全部見える)
 * - 公開の地図は、今日より前に終わった行を出さない(去年の催しが残らないように)
 * - **別の表**(km_event_schedule)。CREATE だけで足りる(ALTER は要らない)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/map-node-projects.php';

const KM_SCHEDULE_TITLE_MAX = 100;
const KM_SCHEDULE_ORGANIZER_MAX = 100;
const KM_SCHEDULE_DESCRIPTION_MAX = 500;

/** 表を作る。**管理側の入口からだけ呼ぶ**(公開の地図の読み出しは、表が無ければ空として素通りする) */
function km_event_schedule_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_event_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_no VARCHAR(16) NULL,
            title VARCHAR(100) NOT NULL,
            organizer VARCHAR(100) NULL,
            description VARCHAR(500) NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (starts_at),
            INDEX (project_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 日時の入力(datetime-local)を `Y-m-d H:i:s` に。空は null。おかしければ例外。**純粋関数** */
function km_event_schedule_datetime(string $value): ?string
{
    $value = str_replace('T', ' ', trim($value));
    if ($value === '') {
        return null;
    }
    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?\z/', $value, $m) !== 1
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
        throw new InvalidArgumentException('日時の形が正しくありません(例: 2026-11-03 10:00)。');
    }
    return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
}

/**
 * 画面の入力を確かめて整える。**純粋関数**(scripts/check.php が試す)
 *
 * @param array<string, mixed> $input projectNo / title / organizer / description / startsAt / endsAt / isPublic
 * @return array{projectNo:?string, title:string, organizer:?string, description:?string, startsAt:string, endsAt:?string, isPublic:bool}
 */
function km_event_schedule_validate(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $organizer = trim((string) ($input['organizer'] ?? ''));
    $description = str_replace("\r\n", "\n", trim((string) ($input['description'] ?? '')));
    $rawProject = trim((string) ($input['projectNo'] ?? ''));
    $project = $rawProject === '' ? null : km_map_project_number($rawProject);

    if ($title === '') {
        throw new InvalidArgumentException('催しの名前を入れてください。');
    }
    if (mb_strlen($title) > KM_SCHEDULE_TITLE_MAX) {
        throw new InvalidArgumentException('名前は ' . KM_SCHEDULE_TITLE_MAX . ' 字までにしてください。');
    }
    if (mb_strlen($organizer) > KM_SCHEDULE_ORGANIZER_MAX) {
        throw new InvalidArgumentException('団体は ' . KM_SCHEDULE_ORGANIZER_MAX . ' 字までにしてください。');
    }
    if (mb_strlen($description) > KM_SCHEDULE_DESCRIPTION_MAX) {
        throw new InvalidArgumentException('説明は ' . KM_SCHEDULE_DESCRIPTION_MAX . ' 字までにしてください。');
    }
    if ($rawProject !== '' && $project === null) {
        throw new InvalidArgumentException('企画の番号は英数字と - _ の ' . KM_MAP_PROJECT_NUMBER_MAX_LENGTH . ' 字までにしてください(例: A-1)。');
    }
    $startsAt = km_event_schedule_datetime((string) ($input['startsAt'] ?? ''));
    if ($startsAt === null) {
        throw new InvalidArgumentException('始まりの日時を入れてください。');
    }
    $endsAt = km_event_schedule_datetime((string) ($input['endsAt'] ?? ''));
    if ($endsAt !== null && $endsAt <= $startsAt) {
        throw new InvalidArgumentException('終わりは始まりより後にしてください。');
    }

    return [
        'projectNo' => $project,
        'title' => $title,
        'organizer' => $organizer === '' ? null : $organizer,
        'description' => $description === '' ? null : $description,
        'startsAt' => $startsAt,
        'endsAt' => $endsAt,
        'isPublic' => (bool) ($input['isPublic'] ?? false),
    ];
}

/** 1 行を整える(DB の行 → 画面と JSON の形) */
function km_event_schedule_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'projectNo' => $row['project_no'] === null ? null : (string) $row['project_no'],
        'title' => (string) $row['title'],
        'organizer' => (string) ($row['organizer'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'startsAt' => (string) $row['starts_at'],
        'endsAt' => $row['ends_at'] === null ? null : (string) $row['ends_at'],
        'isPublic' => (int) $row['is_public'] === 1,
    ];
}

/** @return list<array<string, mixed>> 全部(管理画面)。始まりの順 */
function km_event_schedule_all(PDO $pdo): array
{
    km_event_schedule_ensure_table($pdo);
    $rows = $pdo->query('SELECT * FROM km_event_schedule ORDER BY starts_at, id')->fetchAll(PDO::FETCH_ASSOC);
    return array_map('km_event_schedule_row', $rows);
}

function km_event_schedule_find(PDO $pdo, int $id): ?array
{
    km_event_schedule_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_event_schedule WHERE id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? km_event_schedule_row($row) : null;
}

/** 保存する(id が null なら足す)。保存した行の番号を返す */
function km_event_schedule_save(PDO $pdo, ?int $id, array $fields): int
{
    km_event_schedule_ensure_table($pdo);
    $values = [
        $fields['projectNo'], $fields['title'], $fields['organizer'], $fields['description'],
        $fields['startsAt'], $fields['endsAt'], $fields['isPublic'] ? 1 : 0,
    ];
    if ($id === null) {
        $pdo->prepare(
            'INSERT INTO km_event_schedule (project_no, title, organizer, description, starts_at, ends_at, is_public, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute($values);
        return (int) $pdo->lastInsertId();
    }
    if (km_event_schedule_find($pdo, $id) === null) {
        throw new InvalidArgumentException('その行は見つかりません(消されたかもしれません)。');
    }
    $values[] = $id;
    $pdo->prepare(
        'UPDATE km_event_schedule SET project_no = ?, title = ?, organizer = ?, description = ?, starts_at = ?, ends_at = ?, is_public = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute($values);
    return $id;
}

function km_event_schedule_delete(PDO $pdo, int $id): ?string
{
    $row = km_event_schedule_find($pdo, $id);
    if ($row === null) {
        return null;
    }
    $pdo->prepare('DELETE FROM km_event_schedule WHERE id = ?')->execute([$id]);
    return $row['title'];
}

function km_event_schedule_set_public(PDO $pdo, int $id, bool $public): void
{
    km_event_schedule_ensure_table($pdo);
    $pdo->prepare('UPDATE km_event_schedule SET is_public = ?, updated_at = NOW() WHERE id = ?')->execute([$public ? 1 : 0, $id]);
}

/**
 * 公開の地図に出す行。**一般に出す印の行だけ・今日より前に終わったものは出さない。**表が無ければ空(作らない)。
 * 地点(nodeIds)は番号から引き、**出している地点だけ**を入れる(錠・イベントで隠した地点の場所を漏らさない)。
 *
 * @param array<string, list<string>> $projectNodes 番号 => 地点の id(km_map_project_nodes。出している地点だけ)
 * @return list<array{id:int, projectNo:?string, title:string, organizer:string, description:string, startsAt:string, endsAt:?string, nodeIds:list<string>}>
 */
function km_event_schedule_public(PDO $pdo, array $projectNodes): array
{
    try {
        $rows = $pdo->query(
            'SELECT * FROM km_event_schedule
             WHERE is_public = 1 AND COALESCE(ends_at, starts_at) >= CURDATE()
             ORDER BY starts_at, id'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        // 表がまだ無い(管理画面の「スケジュール」をまだ開いていない)
        return [];
    }
    return km_event_schedule_public_rows(array_map('km_event_schedule_row', $rows), $projectNodes);
}

/**
 * 公開の形にする(isPublic を外し、場所を付ける)。**純粋関数**(scripts/check.php が試す)
 *
 * @param list<array<string, mixed>> $rows km_event_schedule_row の形
 * @param array<string, list<string>> $projectNodes
 * @return list<array<string, mixed>>
 */
function km_event_schedule_public_rows(array $rows, array $projectNodes): array
{
    $public = [];
    foreach ($rows as $row) {
        if (!$row['isPublic']) {
            continue;
        }
        unset($row['isPublic']);
        $row['nodeIds'] = $row['projectNo'] !== null ? ($projectNodes[$row['projectNo']] ?? []) : [];
        $public[] = $row;
    }
    return $public;
}
