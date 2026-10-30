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
 *
 * ## 会場と見せる相手(2026-10-30、高専祭の振り返りのフェーズ 5 の 1)
 * - **会場**: 地点を 1 つ選べる(venueNodeId)。企画の番号の地点より先に出す。番号の無い催し(ステージの演目など)にも場所が付く
 * - **見せる相手**: 一般(public)・スタッフだけ(staff。教職員の組織のアカウント = km_map_teacher_session)・出さない(hidden)
 * - どちらも**別の表** km_event_schedule_extra に持つ(ALTER は要らない)。is_public は今までどおり「一般に出すか」と同じ値にそろえる
 *   (古い版の配備や、表がまだ無いときも一般の行は出る)
 * - CSV の書き出し・取り込み(Excel で開ける UTF-8 BOM 付き。取り込みは「確かめてから入れる」の 2 段。admin/schedule.php)
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
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_event_schedule_extra (
            schedule_id INT NOT NULL PRIMARY KEY,
            venue_node_id VARCHAR(32) NULL,
            audience VARCHAR(8) NOT NULL DEFAULT 'public'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 見せる相手 → 画面の言葉(CSV もこの言葉) */
const KM_SCHEDULE_AUDIENCES = ['public' => '一般', 'staff' => 'スタッフだけ', 'hidden' => '出さない'];

/** 見せる相手の値をそろえる(言葉・英語のどちらでも)。知らなければ null。**純粋関数** */
function km_event_schedule_audience(mixed $value): ?string
{
    $text = trim((string) $value);
    foreach (KM_SCHEDULE_AUDIENCES as $key => $label) {
        if ($text === $key || $text === $label) {
            return $key;
        }
    }
    return match ($text) {
        'スタッフ', '係', '教職員' => 'staff',
        '非公開', '出さない', 'いいえ', '0' => 'hidden',
        'はい', '1', '公開' => 'public',
        default => null,
    };
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
 * @param array<string, mixed> $input projectNo / title / organizer / description / startsAt / endsAt / audience(無ければ isPublic) / venueNodeId
 * @return array{projectNo:?string, title:string, organizer:?string, description:?string, startsAt:string, endsAt:?string, isPublic:bool, audience:string, venueNodeId:?string}
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

    $audience = array_key_exists('audience', $input)
        ? km_event_schedule_audience($input['audience'])
        : ((bool) ($input['isPublic'] ?? false) ? 'public' : 'hidden');
    if ($audience === null) {
        throw new InvalidArgumentException('見せる相手は「一般」「スタッフだけ」「出さない」のどれかにしてください。');
    }
    $venue = strtolower(trim((string) ($input['venueNodeId'] ?? '')));
    if ($venue !== '' && preg_match('/\A[0-9a-f]{32}\z/', $venue) !== 1) {
        throw new InvalidArgumentException('会場の地点が正しくありません。');
    }

    return [
        'projectNo' => $project,
        'title' => $title,
        'organizer' => $organizer === '' ? null : $organizer,
        'description' => $description === '' ? null : $description,
        'startsAt' => $startsAt,
        'endsAt' => $endsAt,
        'isPublic' => $audience === 'public',
        'audience' => $audience,
        'venueNodeId' => $venue === '' ? null : $venue,
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
        // 別の表の値(無ければ今までの「一般に出すか」から)
        'audience' => km_event_schedule_audience($row['audience'] ?? '') ?? ((int) $row['is_public'] === 1 ? 'public' : 'hidden'),
        'venueNodeId' => isset($row['venue_node_id']) && $row['venue_node_id'] !== '' ? (string) $row['venue_node_id'] : null,
    ];
}

/** 本体と別の表をつないで読む SQL(WHERE などは後ろに足す) */
const KM_SCHEDULE_SELECT = 'SELECT s.*, x.venue_node_id, x.audience FROM km_event_schedule s LEFT JOIN km_event_schedule_extra x ON x.schedule_id = s.id';

/** @return list<array<string, mixed>> 全部(管理画面)。始まりの順 */
function km_event_schedule_all(PDO $pdo): array
{
    km_event_schedule_ensure_table($pdo);
    $rows = $pdo->query(KM_SCHEDULE_SELECT . ' ORDER BY s.starts_at, s.id')->fetchAll(PDO::FETCH_ASSOC);
    return array_map('km_event_schedule_row', $rows);
}

function km_event_schedule_find(PDO $pdo, int $id): ?array
{
    km_event_schedule_ensure_table($pdo);
    $statement = $pdo->prepare(KM_SCHEDULE_SELECT . ' WHERE s.id = ?');
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
        $newId = (int) $pdo->lastInsertId();
        km_event_schedule_save_extra($pdo, $newId, $fields['audience'] ?? ($fields['isPublic'] ? 'public' : 'hidden'), $fields['venueNodeId'] ?? null);
        return $newId;
    }
    if (km_event_schedule_find($pdo, $id) === null) {
        throw new InvalidArgumentException('その行は見つかりません(消されたかもしれません)。');
    }
    $values[] = $id;
    $pdo->prepare(
        'UPDATE km_event_schedule SET project_no = ?, title = ?, organizer = ?, description = ?, starts_at = ?, ends_at = ?, is_public = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute($values);
    km_event_schedule_save_extra($pdo, $id, $fields['audience'] ?? ($fields['isPublic'] ? 'public' : 'hidden'), $fields['venueNodeId'] ?? null);
    return $id;
}

/** 会場と見せる相手を別の表へ */
function km_event_schedule_save_extra(PDO $pdo, int $id, string $audience, ?string $venueNodeId): void
{
    $pdo->prepare(
        'INSERT INTO km_event_schedule_extra (schedule_id, venue_node_id, audience) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE venue_node_id = VALUES(venue_node_id), audience = VALUES(audience)'
    )->execute([$id, $venueNodeId, $audience]);
}

function km_event_schedule_delete(PDO $pdo, int $id): ?string
{
    $row = km_event_schedule_find($pdo, $id);
    if ($row === null) {
        return null;
    }
    $pdo->prepare('DELETE FROM km_event_schedule WHERE id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM km_event_schedule_extra WHERE schedule_id = ?')->execute([$id]);
    return $row['title'];
}

function km_event_schedule_set_public(PDO $pdo, int $id, bool $public): void
{
    km_event_schedule_set_audience($pdo, $id, $public ? 'public' : 'hidden');
}

/** 見せる相手を替える(一般に出すかの印もそろえる) */
function km_event_schedule_set_audience(PDO $pdo, int $id, string $audience): void
{
    km_event_schedule_ensure_table($pdo);
    $row = km_event_schedule_find($pdo, $id);
    if ($row === null || !isset(KM_SCHEDULE_AUDIENCES[$audience])) {
        return;
    }
    $pdo->prepare('UPDATE km_event_schedule SET is_public = ?, updated_at = NOW() WHERE id = ?')->execute([$audience === 'public' ? 1 : 0, $id]);
    km_event_schedule_save_extra($pdo, $id, $audience, $row['venueNodeId']);
}

/**
 * 公開の地図に出す行。**一般の行(スタッフのアカウントならスタッフだけの行も)・今日より前に終わったものは出さない。**表が無ければ空(作らない)。
 * 地点(nodeIds)は会場と番号から引き、**出している地点だけ**を入れる(錠・イベントで隠した地点の場所を漏らさない)。
 *
 * @param array<string, list<string>> $projectNodes 番号 => 地点の id(km_map_project_nodes。出している地点だけ)
 * @param bool $staff 教職員の組織のアカウント(km_map_teacher_session)。スタッフだけの行も出す
 * @param array<string, mixed>|null $shownNodes 出している地点(id => 何か)。会場がここに無ければ付けない。null なら絞らない
 * @return list<array{id:int, projectNo:?string, title:string, organizer:string, description:string, startsAt:string, endsAt:?string, nodeIds:list<string>}>
 */
function km_event_schedule_public(PDO $pdo, array $projectNodes, array $projectPois = [], bool $staff = false, ?array $shownNodes = null): array
{
    try {
        $rows = $pdo->query(KM_SCHEDULE_SELECT . ' WHERE COALESCE(s.ends_at, s.starts_at) >= CURDATE() ORDER BY s.starts_at, s.id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        // 表がまだ無い(管理画面の「スケジュール」をまだ開いていない)。古い版の表だけなら本体だけで読む(一般の行は出す)
        try {
            $rows = $pdo->query('SELECT * FROM km_event_schedule WHERE is_public = 1 AND COALESCE(ends_at, starts_at) >= CURDATE() ORDER BY starts_at, id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $inner) {
            return [];
        }
    }
    return km_event_schedule_public_rows(array_map('km_event_schedule_row', $rows), $projectNodes, $projectPois, $staff, $shownNodes);
}

/**
 * 公開の形にする(isPublic を外し、場所を付ける)。**純粋関数**(scripts/check.php が試す)
 *
 * @param list<array<string, mixed>> $rows km_event_schedule_row の形
 * @param array<string, list<string>> $projectNodes
 * @param array<string, list<int>> $projectPois 番号 => 臨時の地点の id(名前が番号の一時地点。2026-10-23)
 * @return list<array<string, mixed>>
 */
function km_event_schedule_public_rows(array $rows, array $projectNodes, array $projectPois = [], bool $staff = false, ?array $shownNodes = null): array
{
    $public = [];
    foreach ($rows as $row) {
        $audience = $row['audience'] ?? ($row['isPublic'] ? 'public' : 'hidden');
        if ($audience !== 'public' && !($staff && $audience === 'staff')) {
            continue;
        }
        $venue = $row['venueNodeId'] ?? null;
        unset($row['isPublic'], $row['audience'], $row['venueNodeId']);
        $nodeIds = $row['projectNo'] !== null ? ($projectNodes[$row['projectNo']] ?? []) : [];
        // 会場は先頭に(出している地点だけ)。表の列(会場 × 時間)は先頭の地点で決める
        if ($venue !== null && ($shownNodes === null || isset($shownNodes[$venue]))) {
            $nodeIds = array_values(array_unique(array_merge([$venue], $nodeIds)));
            $row['venueNodeId'] = $venue;
        }
        $row['nodeIds'] = $nodeIds;
        $row['poiIds'] = $row['projectNo'] !== null ? ($projectPois[$row['projectNo']] ?? []) : [];
        if ($audience === 'staff') {
            $row['staffOnly'] = true;
        }
        $public[] = $row;
    }
    return $public;
}

// ---------------------------------------------------------------- CSV(2026-10-30、振り返りのフェーズ 5 の 1)

/** CSV の見出し(この順に書き出し、取り込みは見出しの名前で読む) */
const KM_SCHEDULE_CSV_HEADERS = ['id', '企画の番号', '催しの名前', '団体', '説明', '始まり', '終わり', '会場の地点id', '会場', '見せる相手'];
/** 取り込める行の数 */
const KM_SCHEDULE_CSV_MAX_ROWS = 500;

/**
 * Excel が式として読む字(= + - @ と制御文字)で始まる値は、頭に ' を付けて書き出す(CSV の式の差し込み対策)。
 * 取り込みでは、その ' を外す(書き出して直して戻しても同じ値になる)。**純粋関数**
 */
function km_event_schedule_csv_cell(string $value): string
{
    return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
}

function km_event_schedule_csv_uncell(string $value): string
{
    return preg_match('/\A\'[=+\-@\t\r]/', $value) === 1 ? substr($value, 1) : $value;
}

/**
 * 書き出す CSV(Excel で開ける UTF-8 BOM 付き・CRLF)。**純粋関数**
 *
 * @param list<array<string, mixed>> $rows km_event_schedule_row の形
 * @param array<string, string> $venueNames 地点の id => 名前
 */
function km_event_schedule_csv_export(array $rows, array $venueNames): string
{
    $out = fopen('php://memory', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, KM_SCHEDULE_CSV_HEADERS, ',', '"', '', "\r\n");
    foreach ($rows as $row) {
        $venue = $row['venueNodeId'] ?? null;
        fputcsv($out, array_map('km_event_schedule_csv_cell', [
            (string) $row['id'], (string) ($row['projectNo'] ?? ''), (string) $row['title'], (string) ($row['organizer'] ?? ''),
            (string) ($row['description'] ?? ''), substr((string) $row['startsAt'], 0, 16), $row['endsAt'] === null ? '' : substr((string) $row['endsAt'], 0, 16),
            (string) ($venue ?? ''), $venue !== null ? ($venueNames[$venue] ?? '') : '', KM_SCHEDULE_AUDIENCES[$row['audience'] ?? 'public'] ?? '一般',
        ]), ',', '"', '', "\r\n");
    }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    return $csv;
}

/**
 * Excel が直した日時の形(「2026/11/3 9:00」)を「2026-11-03 09:00」にそろえる。そろえられなければそのまま(後で誤りにする)。**純粋関数**
 */
function km_event_schedule_csv_datetime(string $value): string
{
    if (preg_match('#\A(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?\z#', trim($value), $m) !== 1) {
        return $value;
    }
    return sprintf('%s-%02d-%02d %02d:%s', $m[1], (int) $m[2], (int) $m[3], (int) $m[4], $m[5]);
}

/**
 * 取り込む CSV を読んで確かめる。**純粋関数**(書き込まない。画面で確かめてから km_event_schedule_csv_commit)。
 *
 * - BOM・CRLF・Shift_JIS(Excel の「CSV」)を受ける。1 行目は見出し(名前で読むので、列の順は問わない。知らない列は無視)
 * - id のある行は直す、無い行は足す(id が今の表に無ければ誤り)
 * - 会場は「会場の地点id」が先。無ければ「会場」の名前がそっくり同じ地点(1 つだけのとき)
 * - 誤りが 1 つでもあれば取り込まない(画面に行番号と理由を出す)
 *
 * @param array<string, string> $venueNames 地点の id => 名前(選べる会場)
 * @param array<int, true> $existingIds 今の表の id
 * @return array{rows: list<array{line:int, id:?int, fields:array}>, errors: list<string>}
 */
function km_event_schedule_csv_parse(string $csv, array $venueNames, array $existingIds): array
{
    if (!mb_check_encoding($csv, 'UTF-8')) {
        $csv = (string) mb_convert_encoding($csv, 'UTF-8', 'SJIS-win');
    }
    if (str_starts_with($csv, "\xEF\xBB\xBF")) {
        $csv = substr($csv, 3);
    }
    $in = fopen('php://memory', 'w+');
    fwrite($in, $csv);
    rewind($in);
    $header = fgetcsv($in, null, ',', '"', '');
    if (!is_array($header)) {
        fclose($in);
        return ['rows' => [], 'errors' => ['CSV が空です。']];
    }
    $columns = [];
    foreach ($header as $index => $name) {
        $columns[trim((string) $name)] = $index;
    }
    foreach (['催しの名前', '始まり'] as $required) {
        if (!isset($columns[$required])) {
            fclose($in);
            return ['rows' => [], 'errors' => ["1 行目に「{$required}」の列がありません(書き出した CSV の見出しを使ってください)。"]];
        }
    }
    // 名前 → 地点(同じ名前が 2 つ以上なら名前では決めない)
    $byName = [];
    foreach ($venueNames as $id => $name) {
        $byName[$name][] = (string) $id;
    }
    $rows = [];
    $errors = [];
    $line = 1;
    while (($record = fgetcsv($in, null, ',', '"', '')) !== false) {
        $line++;
        if ($record === [null] || implode('', array_map('strval', $record)) === '') {
            continue;
        }
        if (count($rows) >= KM_SCHEDULE_CSV_MAX_ROWS) {
            $errors[] = '行が多すぎます(' . KM_SCHEDULE_CSV_MAX_ROWS . ' 行まで)。';
            break;
        }
        $get = static fn (string $name): string => isset($columns[$name]) ? km_event_schedule_csv_uncell(trim((string) ($record[$columns[$name]] ?? ''))) : '';
        try {
            $idText = $get('id');
            $id = null;
            if ($idText !== '') {
                if (!ctype_digit($idText) || !isset($existingIds[(int) $idText])) {
                    throw new InvalidArgumentException('id「' . $idText . '」の行は今の表にありません(足す行は id を空にしてください)。');
                }
                $id = (int) $idText;
            }
            $venueId = strtolower($get('会場の地点id'));
            $venueName = $get('会場');
            if ($venueId !== '' && !isset($venueNames[$venueId])) {
                throw new InvalidArgumentException('会場の地点 id「' . $venueId . '」は地図にありません。');
            }
            if ($venueId === '' && $venueName !== '') {
                $matches = $byName[$venueName] ?? [];
                if (count($matches) !== 1) {
                    throw new InvalidArgumentException('会場「' . $venueName . '」' . ($matches === [] ? 'は地図にありません' : 'は同じ名前が ' . count($matches) . ' つあります(会場の地点id を入れてください)') . '。');
                }
                $venueId = $matches[0];
            }
            $audienceText = $get('見せる相手');
            $fields = km_event_schedule_validate([
                'projectNo' => $get('企画の番号'),
                'title' => $get('催しの名前'),
                'organizer' => $get('団体'),
                'description' => $get('説明'),
                'startsAt' => km_event_schedule_csv_datetime($get('始まり')),
                'endsAt' => km_event_schedule_csv_datetime($get('終わり')),
                'audience' => $audienceText === '' ? 'public' : $audienceText,
                'venueNodeId' => $venueId,
            ]);
            $rows[] = ['line' => $line, 'id' => $id, 'fields' => $fields];
        } catch (InvalidArgumentException $exception) {
            $errors[] = $line . ' 行目: ' . $exception->getMessage();
        }
    }
    fclose($in);
    return ['rows' => $rows, 'errors' => $errors];
}

/** 確かめた行を入れる(id のある行は直す・無い行は足す)。返すのは [足した数, 直した数] */
function km_event_schedule_csv_commit(PDO $pdo, array $rows): array
{
    km_event_schedule_ensure_table($pdo);
    $added = 0;
    $updated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            km_event_schedule_save($pdo, $row['id'], $row['fields']);
            $row['id'] === null ? $added++ : $updated++;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return [$added, $updated];
}