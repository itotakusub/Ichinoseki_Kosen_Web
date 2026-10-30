<?php

declare(strict_types=1);

/**
 * 屋台がつなぐ API(メニュー・混み具合。2026-10-30、高専祭の振り返りのフェーズ 5 の 2)。
 *
 * - 屋台ごとの鍵を管理画面「屋台」(admin/stalls.php)で出す。**鍵は出したときに 1 度だけ見せ、表には SHA-256 だけを置く**
 * - 屋台は api/stall.php へ `Authorization: Bearer kms_…` でメニューと「◯人待ち」を送る(鍵は URL に載せない)
 * - 場所は企画の番号(予定・ポスターと同じ。一時地点を取り込み直しても外れない)か地点。両方あれば地点を先に
 * - 公開の地図は地図のデータ(stalls)で初めの値を持ち、api/stalls.php を 30 秒ごとに読み直す(Main/stalls.js)。
 *   Soketi(/ws/app/)は管理画面の Origin だけに開けているので、来場者の画面へは押し出さない(読み直しで足りる大きさ)
 * - **古い値は出さない**: 待ちの人数は 30 分、メニューは 12 時間で消えた扱い(表の行は残し、出すときに外す)
 * - 送る回数は 1 屋台 5 秒に 1 回まで(入口の回数は nginx の km_api)
 *
 * 表は 2 つ(ALTER の権限が無いので、足すときは別の表):
 *   km_stalls        屋台(名前・企画の番号・地点・鍵の SHA-256・鍵の末尾 4 字・止めた日時)
 *   km_stall_status  屋台の今の値(メニューの JSON・待ちの人数・ひとこと・送った日時)
 */

require_once __DIR__ . '/map-node-projects.php';

const KM_STALL_NAME_MAX = 40;
const KM_STALL_MENU_MAX_ITEMS = 30;
const KM_STALL_MENU_NAME_MAX = 30;
const KM_STALL_PRICE_MAX = 100000;
const KM_STALL_WAITING_MAX = 999;
const KM_STALL_NOTE_MAX = 60;
/** 待ちの人数を出す長さ(秒) */
const KM_STALL_WAITING_TTL = 30 * 60;
/** メニューとひとことを出す長さ(秒) */
const KM_STALL_MENU_TTL = 12 * 60 * 60;
/** 1 屋台が続けて送れる間(秒) */
const KM_STALL_POST_INTERVAL = 5;
/** 鍵の頭(形で見分ける) */
const KM_STALL_KEY_PREFIX = 'kms_';

function km_stalls_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_stalls (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            project_no VARCHAR(32) NULL,
            node_id CHAR(32) NULL,
            key_hash CHAR(64) NOT NULL,
            key_hint CHAR(4) NOT NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY km_stalls_key (key_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_stall_status (
            stall_id INT UNSIGNED NOT NULL PRIMARY KEY,
            menu TEXT NULL,
            waiting SMALLINT NULL,
            note VARCHAR(120) NULL,
            menu_at DATETIME NULL,
            waiting_at DATETIME NULL,
            posted_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
}

/** 新しい鍵(kms_ + 32 字の 16 進)。返すのは [鍵, SHA-256, 末尾 4 字] */
function km_stall_new_key(): array
{
    $key = KM_STALL_KEY_PREFIX . bin2hex(random_bytes(16));
    return [$key, hash('sha256', $key), substr($key, -4)];
}

/** 鍵の形(送られてきた値を表に当てる前に見る)。**純粋関数** */
function km_stall_key_valid(string $key): bool
{
    return preg_match('/\Akms_[0-9a-f]{32}\z/', $key) === 1;
}

/** `Authorization: Bearer …` から鍵を取り出す。無ければ ''。**純粋関数** */
function km_stall_key_from_header(string $header): string
{
    return preg_match('/\A\s*Bearer\s+(\S+)\s*\z/i', $header, $m) === 1 ? $m[1] : '';
}

/**
 * 屋台が送る値を確かめて整える。**純粋関数**(scripts/check.php が試す)。
 * 送らなかった項目は null(前の値を残す)。waiting・note に "" か null を送ると消す。
 *
 * @param array<string, mixed> $input {"menu":[{"name":"たこ焼き","price":300,"soldOut":false}], "waiting": 12, "note": "…"}
 * @return array{menu:?list<array{name:string, price:?int, soldOut:bool}>, waiting:int|false|null, note:string|false|null}
 *         waiting / note が false なら「消す」
 */
function km_stall_validate_post(array $input): array
{
    $menu = null;
    if (array_key_exists('menu', $input)) {
        if (!is_array($input['menu']) || !array_is_list($input['menu'])) {
            throw new InvalidArgumentException('menu は品物の並び([{"name":…}])にしてください。');
        }
        if (count($input['menu']) > KM_STALL_MENU_MAX_ITEMS) {
            throw new InvalidArgumentException('menu は ' . KM_STALL_MENU_MAX_ITEMS . ' 品までにしてください。');
        }
        $menu = [];
        foreach ($input['menu'] as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('menu の ' . ($index + 1) . ' 品目の形が違います。');
            }
            $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($item['name'] ?? '')) ?? '');
            if ($name === '' || mb_strlen($name) > KM_STALL_MENU_NAME_MAX) {
                throw new InvalidArgumentException('menu の ' . ($index + 1) . ' 品目の名前は 1〜' . KM_STALL_MENU_NAME_MAX . ' 字にしてください。');
            }
            $price = $item['price'] ?? null;
            if ($price !== null && (!is_int($price) || $price < 0 || $price > KM_STALL_PRICE_MAX)) {
                throw new InvalidArgumentException('menu の ' . ($index + 1) . ' 品目の price は 0〜' . KM_STALL_PRICE_MAX . ' の整数にしてください。');
            }
            $menu[] = ['name' => $name, 'price' => $price, 'soldOut' => ($item['soldOut'] ?? false) === true];
        }
    }

    $waiting = null;
    if (array_key_exists('waiting', $input)) {
        $raw = $input['waiting'];
        if ($raw === '' || $raw === null) {
            $waiting = false;
        } elseif (is_int($raw) && $raw >= 0 && $raw <= KM_STALL_WAITING_MAX) {
            $waiting = $raw;
        } else {
            throw new InvalidArgumentException('waiting は 0〜' . KM_STALL_WAITING_MAX . ' の整数にしてください(消すときは "")。');
        }
    }

    $note = null;
    if (array_key_exists('note', $input)) {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $input['note']) ?? '');
        if (mb_strlen($text) > KM_STALL_NOTE_MAX) {
            throw new InvalidArgumentException('note は ' . KM_STALL_NOTE_MAX . ' 字までにしてください。');
        }
        $note = $text === '' ? false : $text;
    }

    if ($menu === null && $waiting === null && $note === null) {
        throw new InvalidArgumentException('menu・waiting・note のどれかを送ってください。');
    }
    return ['menu' => $menu, 'waiting' => $waiting, 'note' => $note];
}

/**
 * 管理画面で打つメニュー(1 行に 1 品「名前,値段」。売り切れは行の頭に ×)→ km_stall_validate_post に渡す形。**純粋関数**
 * 値段は半角・全角の数字だけを読む(「300円」も 300)。値段の無い行は null
 */
function km_stall_menu_from_text(string $text): array
{
    $menu = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $soldOut = (bool) preg_match('/\A[×✕]\s*/u', $line);
        $line = (string) preg_replace('/\A[×✕]\s*/u', '', $line);
        $parts = preg_split('/\s*[,，、]\s*/u', $line, 2) ?: [$line];
        $digits = preg_replace('/[^0-9]/', '', mb_convert_kana((string) ($parts[1] ?? ''), 'n'));
        $menu[] = ['name' => trim((string) $parts[0]), 'price' => $digits === '' || $digits === null ? null : (int) $digits, 'soldOut' => $soldOut];
    }
    return $menu;
}

/** 管理画面に出すメニューの字(km_stall_menu_from_text の逆)。**純粋関数** */
function km_stall_menu_to_text(array $menu): string
{
    return implode("\n", array_map(static fn (array $item): string => (($item['soldOut'] ?? false) ? '×' : '') . $item['name']
        . (($item['price'] ?? null) !== null ? ',' . $item['price'] : ''), $menu));
}

/** 鍵に当たる屋台(止めた鍵は外れ)。無ければ null */
function km_stall_by_key(PDO $pdo, string $key): ?array
{
    if (!km_stall_key_valid($key)) {
        return null;
    }
    km_stalls_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_stalls WHERE key_hash = ? AND revoked_at IS NULL');
    $statement->execute([hash('sha256', $key)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * 送られた値を入れる。5 秒以内の 2 回目は 'limited'。
 *
 * @param array{menu:?array, waiting:int|false|null, note:string|false|null} $post km_stall_validate_post の結果
 * @return 'ok'|'limited'
 */
function km_stall_post(PDO $pdo, int $stallId, array $post, ?int $now = null): string
{
    $now ??= time();
    km_stalls_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_stall_status WHERE stall_id = ?');
    $statement->execute([$stallId]);
    $current = $statement->fetch(PDO::FETCH_ASSOC);
    if (is_array($current) && $now - strtotime((string) $current['posted_at']) < KM_STALL_POST_INTERVAL) {
        return 'limited';
    }
    $at = date('Y-m-d H:i:s', $now);
    $menu = $post['menu'] !== null ? json_encode($post['menu'], JSON_UNESCAPED_UNICODE) : ($current['menu'] ?? null);
    $menuAt = $post['menu'] !== null || $post['note'] !== null ? $at : ($current['menu_at'] ?? null);
    $note = match (true) {
        $post['note'] === false => null,
        is_string($post['note']) => $post['note'],
        default => $current['note'] ?? null,
    };
    $waiting = match (true) {
        $post['waiting'] === false => null,
        is_int($post['waiting']) => $post['waiting'],
        default => isset($current['waiting']) ? (int) $current['waiting'] : null,
    };
    $waitingAt = is_int($post['waiting']) ? $at : ($post['waiting'] === false ? null : ($current['waiting_at'] ?? null));
    $pdo->prepare(
        'REPLACE INTO km_stall_status (stall_id, menu, waiting, note, menu_at, waiting_at, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$stallId, $menu, $waiting, $note, $menuAt, $waitingAt, $at]);
    return 'ok';
}

/**
 * 出す値(古いものを外す)。**純粋関数**(scripts/check.php が試す)
 *
 * @param array<string, mixed>|null $status km_stall_status の行
 * @return array{menu:list<array>, waiting:?int, note:string, menuAt:?string, waitingAt:?string}
 */
function km_stall_fresh(?array $status, int $now): array
{
    $menuFresh = $status !== null && $status['menu_at'] !== null && $now - strtotime((string) $status['menu_at']) <= KM_STALL_MENU_TTL;
    $waitingFresh = $status !== null && $status['waiting'] !== null && $status['waiting_at'] !== null
        && $now - strtotime((string) $status['waiting_at']) <= KM_STALL_WAITING_TTL;
    $menu = $menuFresh ? json_decode((string) ($status['menu'] ?? '[]'), true) : [];
    return [
        'menu' => is_array($menu) ? $menu : [],
        'waiting' => $waitingFresh ? (int) $status['waiting'] : null,
        'note' => $menuFresh ? (string) ($status['note'] ?? '') : '',
        'menuAt' => $menuFresh ? date('c', (int) strtotime((string) $status['menu_at'])) : null,
        'waitingAt' => $waitingFresh ? date('c', (int) strtotime((string) $status['waiting_at'])) : null,
    ];
}

/** @return list<array<string, mixed>> 管理画面の一覧(鍵の末尾と今の値も) */
function km_stalls_all(PDO $pdo): array
{
    km_stalls_ensure_table($pdo);
    $rows = $pdo->query(
        'SELECT s.*, t.menu, t.waiting, t.note, t.menu_at, t.waiting_at, t.posted_at FROM km_stalls s
         LEFT JOIN km_stall_status t ON t.stall_id = s.id ORDER BY s.revoked_at IS NOT NULL, s.project_no IS NULL, s.project_no, s.name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $now = time();
    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'projectNo' => $row['project_no'] === null ? null : (string) $row['project_no'],
        'nodeId' => $row['node_id'] === null ? null : (string) $row['node_id'],
        'keyHint' => (string) $row['key_hint'],
        'revoked' => $row['revoked_at'] !== null,
        'postedAt' => $row['posted_at'] ?? null,
    ] + km_stall_fresh($row['posted_at'] === null ? null : $row, $now), $rows);
}

/**
 * 管理画面の入力を確かめる。**純粋関数**
 *
 * @return array{name:string, projectNo:?string, nodeId:?string}
 */
function km_stall_validate(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > KM_STALL_NAME_MAX) {
        throw new InvalidArgumentException('屋台の名前は 1〜' . KM_STALL_NAME_MAX . ' 字にしてください。');
    }
    $rawProject = trim((string) ($input['projectNo'] ?? ''));
    $project = $rawProject === '' ? null : km_map_project_number($rawProject);
    if ($rawProject !== '' && $project === null) {
        throw new InvalidArgumentException('企画の番号は英数字と - _ にしてください(例: A-1)。');
    }
    $node = strtolower(trim((string) ($input['nodeId'] ?? '')));
    if ($node !== '' && preg_match('/\A[0-9a-f]{32}\z/', $node) !== 1) {
        throw new InvalidArgumentException('地点が正しくありません。');
    }
    if ($project === null && $node === '') {
        throw new InvalidArgumentException('企画の番号か地点のどちらかを選んでください(地図のどこに出すか)。');
    }
    return ['name' => $name, 'projectNo' => $project, 'nodeId' => $node === '' ? null : $node];
}

/** 足す。返すのは [id, 鍵](鍵はこの 1 度だけ) */
function km_stall_create(PDO $pdo, array $fields): array
{
    km_stalls_ensure_table($pdo);
    [$key, $hash, $hint] = km_stall_new_key();
    $pdo->prepare('INSERT INTO km_stalls (name, project_no, node_id, key_hash, key_hint, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([$fields['name'], $fields['projectNo'], $fields['nodeId'], $hash, $hint]);
    return [(int) $pdo->lastInsertId(), $key];
}

/** 名前と場所を直す */
function km_stall_update(PDO $pdo, int $id, array $fields): void
{
    km_stalls_ensure_table($pdo);
    $pdo->prepare('UPDATE km_stalls SET name = ?, project_no = ?, node_id = ? WHERE id = ?')
        ->execute([$fields['name'], $fields['projectNo'], $fields['nodeId'], $id]);
}

/** 鍵を出し直す(前の鍵は効かなくなる。止めていたら戻す)。返すのは新しい鍵 */
function km_stall_rekey(PDO $pdo, int $id): string
{
    km_stalls_ensure_table($pdo);
    [$key, $hash, $hint] = km_stall_new_key();
    $pdo->prepare('UPDATE km_stalls SET key_hash = ?, key_hint = ?, revoked_at = NULL WHERE id = ?')->execute([$hash, $hint, $id]);
    return $key;
}

/** 止める(鍵が効かなくなり、地図にも出さない。値は残す) */
function km_stall_revoke(PDO $pdo, int $id): void
{
    km_stalls_ensure_table($pdo);
    $pdo->prepare('UPDATE km_stalls SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')->execute([$id]);
}

function km_stall_delete(PDO $pdo, int $id): void
{
    km_stalls_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_stalls WHERE id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM km_stall_status WHERE stall_id = ?')->execute([$id]);
}

/**
 * 公開の形(地図のデータと api/stalls.php)。止めた屋台は出さない。場所は出している地点だけ(錠・イベントで隠した地点を漏らさない)。
 * 表が無ければ空(作らない)。
 *
 * @param array<string, list<string>> $projectNodes 番号 => 地点の id(出している地点だけ)
 * @param array<string, list<int>> $projectPois 番号 => 一時地点の id
 * @param array<string, mixed>|null $shownNodes 出している地点(id => 何か)。null なら絞らない
 * @return list<array{id:int, name:string, projectNo:?string, nodeIds:list<string>, poiIds:list<int>, menu:list<array>, waiting:?int, note:string, menuAt:?string, waitingAt:?string}>
 */
function km_stalls_public(PDO $pdo, array $projectNodes, array $projectPois = [], ?array $shownNodes = null, ?int $now = null): array
{
    return km_stalls_public_rows(km_stalls_public_raw($pdo), $projectNodes, $projectPois, $shownNodes, $now ?? time());
}

/** 止めていない屋台の行(今の値をつないで)。表が無ければ空(作らない) */
function km_stalls_public_raw(PDO $pdo): array
{
    try {
        return $pdo->query(
            'SELECT s.id, s.name, s.project_no, s.node_id, t.menu, t.waiting, t.note, t.menu_at, t.waiting_at, t.posted_at
             FROM km_stalls s LEFT JOIN km_stall_status t ON t.stall_id = s.id WHERE s.revoked_at IS NULL ORDER BY s.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return [];
    }
}

/** 読み直し(api/stalls.php)の形: 場所を持たず、今の値だけ。**純粋関数** */
function km_stalls_status_rows(array $rows, int $now): array
{
    return array_map(static fn (array $row): array => ['id' => (int) $row['id']] + km_stall_fresh(($row['posted_at'] ?? null) === null ? null : $row, $now), $rows);
}

/** km_stalls_public の中身。**純粋関数**(scripts/check.php が試す) */
function km_stalls_public_rows(array $rows, array $projectNodes, array $projectPois, ?array $shownNodes, int $now): array
{
    $out = [];
    foreach ($rows as $row) {
        $project = $row['project_no'] === null ? null : (string) $row['project_no'];
        $nodeIds = $project !== null ? ($projectNodes[$project] ?? []) : [];
        $node = $row['node_id'] === null ? null : (string) $row['node_id'];
        if ($node !== null && ($shownNodes === null || isset($shownNodes[$node]))) {
            $nodeIds = array_values(array_unique(array_merge([$node], $nodeIds)));
        }
        $poiIds = $project !== null ? array_map('intval', $projectPois[$project] ?? []) : [];
        if ($nodeIds === [] && $poiIds === []) {
            continue;
        }
        $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'projectNo' => $project, 'nodeIds' => $nodeIds, 'poiIds' => $poiIds]
            + km_stall_fresh(($row['posted_at'] ?? null) === null ? null : $row, $now);
    }
    return $out;
}

/** 地図の札・案内チャットに出す 1 行(「12 人待ち・たこ焼き 300 円 ほか 2 品」)。値が無ければ ''。**純粋関数** */
function km_stall_summary(array $stall): string
{
    $parts = [];
    if (($stall['waiting'] ?? null) !== null) {
        $parts[] = (int) $stall['waiting'] === 0 ? '待ちなし' : (int) $stall['waiting'] . ' 人待ち';
    }
    $menu = array_values(array_filter($stall['menu'] ?? [], static fn (array $item): bool => !($item['soldOut'] ?? false)));
    if ($menu !== []) {
        $first = $menu[0]['name'] . (($menu[0]['price'] ?? null) !== null ? ' ' . $menu[0]['price'] . ' 円' : '');
        $parts[] = $first . (count($menu) > 1 ? ' ほか ' . (count($menu) - 1) . ' 品' : '');
    } elseif (($stall['menu'] ?? []) !== []) {
        $parts[] = '売り切れ';
    }
    if (($stall['note'] ?? '') !== '') {
        $parts[] = (string) $stall['note'];
    }
    return implode('・', $parts);
}
