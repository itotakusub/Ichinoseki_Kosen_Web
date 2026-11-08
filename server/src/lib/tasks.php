<?php

declare(strict_types=1);

/**
 * projects.php(進捗表)と kanban.php(ボード)が共有するタスク。
 *
 * 元は両ページとも別々のハードコードだったが、実体は同じ「タスクの一覧」なので1つの表で賄う。
 * projects は status を4値(完了/進行中/要判断/未着手)として、kanban はそのうち3つを
 * レーンとして見る、という関係になっている。
 *
 * lib/admin-log.php と同じ「初回アクセス時に CREATE TABLE IF NOT EXISTS」。
 */

require_once __DIR__ . '/db.php';

/** projects.php の状態バッジと kanban.php のレーンの両方がこの4値を使う。 */
const KM_TASK_STATUSES = ['done', 'progress', 'decision', 'todo'];

/** kanban は「要判断」レーンを持たないので、ボード上はこの3つだけを並べる。 */
const KM_TASK_LANES = ['todo', 'progress', 'done'];

/**
 * タスクの分類(2026-10-06、利用者の指示「サービス監視の今後の拡張をかんばん等で」)。
 *
 * 空文字は「なし」。分類を持つタスクは、その画面(サービス監視の「今後の拡張」など)にも並ぶ。
 * 日本語を原文として置き、辞書は page.projects.topic.<値> で対訳を持つ。
 */
const KM_TASK_TOPICS = [
    '' => 'なし',
    // 2026-11-07(利用者の指示「段 7 から、プロジェクト状況に追加(分類も)」)。plan.md の段の中身をこの分け方で並べる
    'server' => 'サーバー・運用',
    'security' => 'セキュリティ',
    'web' => 'Website',
    'app' => 'アプリ',
    'mapdata' => '地図のデータ',
    'devenv' => '検証環境',
    'monitor' => 'サービス監視',
];

/**
 * タスクの補足(2026-11-07)。plan.md の段の番号(「段7-1」など)と、どうするかのメモ。
 * **km_tasks には列を足さない**(既存の表は変えない決まり)。別の表に task_id で 1 行ずつ置く。
 */
const KM_TASK_PLAN_REF_MAX = 32;
const KM_TASK_NOTE_MAX = 2000;

/**
 * サービス監視の「今後の拡張」に、以前 monitor.php へ直接書いていた 3 行。
 * 分類の列を足したとき(と表を新しく作ったとき)に 1 回だけ入れる。消したら戻さない。
 */
const KM_TASK_MONITOR_SEED = [
    '9443 の PHP に死活監視用エンドポイントを追加し、各サービスへサーバー間で接続する',
    'MariaDB は HTTP ではないため、TCP 接続の可否で判定する',
    '誰かが確認した結果を Soketi で全員へ配る(定期実行の仕組みは作らず、自動更新を入れた人が配信役になる)',
];

function km_tasks_ensure_table(PDO $pdo): void
{
    // 1リクエストで何度も呼ばれるので覚えておく
    static $ready = false;
    if ($ready) {
        return;
    }
    if (km_db_table_exists($pdo, 'km_tasks')) {
        km_tasks_ensure_topic_column($pdo);
        km_task_details_ensure_table($pdo);
        $ready = true;
        return;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(16) NOT NULL,
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            topic VARCHAR(32) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (status),
            INDEX (topic),
            INDEX (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    km_tasks_seed_once($pdo);
    km_tasks_seed_monitor($pdo);
    km_task_details_ensure_table($pdo);
    $ready = true;
}

/**
 * 補足の表(2026-11-07)。**新しい表だけ**(CREATE TABLE IF NOT EXISTS)。km_tasks には触れない。
 * 外部キーは張らない(km_tasks の作り方に依らずに作れるように)。タスクを消すときに一緒に消す(km_tasks_delete)。
 */
function km_task_details_ensure_table(PDO $pdo): void
{
    if (km_db_table_exists($pdo, 'km_task_details')) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_task_details (
            task_id INT NOT NULL PRIMARY KEY,
            plan_ref VARCHAR(32) NOT NULL DEFAULT '',
            note TEXT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (plan_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 段の番号とメモを確かめる。段の番号は 1 行(改行・制御文字なし)、メモは改行まで。 */
function km_task_details_validate(string $planRef, string $note): void
{
    if (mb_strlen($planRef) > KM_TASK_PLAN_REF_MAX || preg_match('/[\x00-\x1F\x7F]/u', $planRef) === 1) {
        throw new InvalidArgumentException('段の番号は改行なしの ' . KM_TASK_PLAN_REF_MAX . ' 文字以内にしてください。');
    }
    if (mb_strlen($note) > KM_TASK_NOTE_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $note) === 1) {
        throw new InvalidArgumentException('メモは ' . KM_TASK_NOTE_MAX . ' 文字以内にしてください(制御文字は使えません)。');
    }
    if (preg_match('//u', $planRef . $note) !== 1) {
        throw new InvalidArgumentException('段の番号かメモの文字コードが UTF-8 ではありません。');
    }
}

/** 補足を置く。**両方とも空なら行を消す**(空の行を残さない)。 */
function km_task_details_set(PDO $pdo, int $taskId, string $planRef, string $note): void
{
    km_tasks_ensure_table($pdo);
    $planRef = trim($planRef);
    $note = trim(str_replace("\r\n", "\n", $note));
    km_task_details_validate($planRef, $note);

    if ($planRef === '' && $note === '') {
        $pdo->prepare('DELETE FROM km_task_details WHERE task_id = ?')->execute([$taskId]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO km_task_details (task_id, plan_ref, note, updated_at) VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE plan_ref = VALUES(plan_ref), note = VALUES(note), updated_at = NOW()'
    )->execute([$taskId, $planRef, $note === '' ? null : $note]);
}

/**
 * 分類の列(2026-10-06)が無い古い表に足し、そのときだけサービス監視の 3 行を入れる。
 * 表が在るときは毎リクエスト通るので、information_schema を 1 回見るだけにする。
 */
function km_tasks_ensure_topic_column(PDO $pdo): void
{
    $column = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'km_tasks' AND COLUMN_NAME = 'topic'"
    );
    if ($column === false || (int) $column->fetchColumn() > 0) {
        return;
    }
    $pdo->exec("ALTER TABLE km_tasks ADD COLUMN IF NOT EXISTS topic VARCHAR(32) NOT NULL DEFAULT '' AFTER progress");
    $pdo->exec('ALTER TABLE km_tasks ADD INDEX IF NOT EXISTS topic (topic)');
    km_tasks_seed_monitor($pdo);
}

/**
 * 完了として末尾に足す。同時に 2 つのリクエストが列を足しても二重にならないよう、同じ題があれば入れない。
 */
function km_tasks_seed_monitor(PDO $pdo): void
{
    $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM km_tasks')->fetchColumn();
    $stmt = $pdo->prepare(
        "INSERT INTO km_tasks (title, status, progress, topic, sort_order, created_at, updated_at)
         SELECT ?, 'done', 100, 'monitor', ?, NOW(), NOW() FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM km_tasks WHERE topic = 'monitor' AND title = ?)"
    );
    foreach (KM_TASK_MONITOR_SEED as $i => $title) {
        $stmt->execute([$title, $next + $i * 10, $title]);
    }
}

/**
 * 初回だけ、これまで projects.php にハードコードされていた内容を投入する。
 *
 * **呼ぶのは km_tasks_ensure_table が表を新しく作ったときだけ。**
 * 以前は「表が空なら入れる」だったため、タスクを全部消すと次の表示で
 * 15件がまるごと復活した(2026-09-18 に「消しても生成される」と報告)。
 * 空であることは「利用者が全部消した」とも読めるので、空を初回の目印にしない。
 */
function km_tasks_seed_once(PDO $pdo): void
{
    $seed = [
        ['管理画面シェル + AdminLTE 無編集適用', 'done', 100],
        ['日本語/英語の切り替え', 'done', 100],
        ['CDN 依存のローカル化', 'done', 100],
        ['Logto ログインと管理者権限判定', 'done', 100],
        ['firebase/php-jwt の依存を明示化', 'done', 100],
        ['テーブルの作成・データ編集・削除', 'done', 100],
        ['KosenMap をホームページにする件', 'done', 100],
        ['サービス死活監視の実疎通', 'done', 100],
        ['Soketi チャット(履歴の保存まで)', 'done', 100],
        ['操作ログ(監査ログ)とタイムライン', 'done', 100],
        ['CSRF 対策', 'done', 100],
        ['Logto Management API でのユーザー一覧', 'progress', 80],
        ['メール送信・お問い合わせフォーム', 'progress', 50],
        ['地図編集ツールの権限連携', 'decision', 0],
        ['リリース前のセキュリティチェック', 'todo', 0],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO km_tasks (title, status, progress, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())'
    );
    foreach ($seed as $i => [$title, $status, $progress]) {
        $stmt->execute([$title, $status, $progress, $i * 10]);
    }
}

/**
 * 時刻は UNIX_TIMESTAMP() の epoch で返す(フェーズ5・9・13の教訓。DB と PHP は
 * Asia/Tokyo に揃えたが、epoch なら設定が食い違っても表示がずれない)。
 *
 * 段の番号(planRef)とメモ(note)は補足の表から。無ければ空文字。
 *
 * @return array<int, array{id:int, title:string, status:string, progress:int, topic:string,
 *                          sortOrder:int, updatedAtEpoch:int, planRef:string, note:string}>
 */
function km_tasks_all(PDO $pdo): array
{
    km_tasks_ensure_table($pdo);

    return $pdo->query(
        'SELECT t.id, t.title, t.status, t.progress, t.topic, t.sort_order AS sortOrder,
                UNIX_TIMESTAMP(GREATEST(t.updated_at, COALESCE(d.updated_at, t.updated_at))) AS updatedAtEpoch,
                COALESCE(d.plan_ref, \'\') AS planRef, COALESCE(d.note, \'\') AS note
         FROM km_tasks t LEFT JOIN km_task_details d ON d.task_id = t.id
         ORDER BY t.sort_order, t.id'
    )->fetchAll();
}

/**
 * 一覧の並べ方(2026-11-07、利用者「プロジェクト状況を番号順にソートできるように」)。画面の「並び」の選択肢
 *   ''     … 並べた順(sort_order。カンバンで動かした順)
 *   'id'   … 番号順(# の小さい順。proj-tasks.ps1 の Id と同じ)
 *   'idDesc' … 番号の大きい順(新しく足したものが上)
 *   'plan' … 段の番号順(段1 → 段1-2 → 段2 … → 段10。数は数として比べる。段の無いもの・「保留」は後ろ)
 */
const KM_TASK_SORTS = ['' => '並べた順', 'id' => '番号順', 'idDesc' => '番号の大きい順', 'plan' => '段の番号順'];

/**
 * 並べ替える。**純粋関数**。知らない並べ方は並べた順のまま。同じ値どうしは番号の小さい順
 *
 * @param array<int, array<string, mixed>> $rows km_tasks_all の行
 * @return array<int, array<string, mixed>>
 */
function km_tasks_sort(array $rows, string $sort): array
{
    $byId = static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id'];
    if ($sort === 'id') {
        usort($rows, $byId);
    } elseif ($sort === 'idDesc') {
        usort($rows, static fn (array $a, array $b): int => $byId($b, $a));
    } elseif ($sort === 'plan') {
        // 「段7-1」→ [7, 1]。数字の無いもの(保留など)は後ろ
        $key = static function (array $row): array {
            preg_match_all('/\d+/', (string) ($row['planRef'] ?? ''), $m);
            return $m[0] === [] ? [PHP_INT_MAX] : array_map('intval', $m[0]);
        };
        // 前から 1 つずつ比べる(PHP の配列の <=> は長さを先に比べるので、段10 が段7-1 より前になってしまう)
        usort($rows, static function (array $a, array $b) use ($key, $byId): int {
            [$ka, $kb] = [$key($a), $key($b)];
            for ($i = 0, $n = min(count($ka), count($kb)); $i < $n; $i++) {
                if ($ka[$i] !== $kb[$i]) {
                    return $ka[$i] <=> $kb[$i];
                }
            }
            $order = count($ka) <=> count($kb);
            return $order !== 0 ? $order : $byId($a, $b);
        });
    }

    return array_values($rows);
}

/**
 * 1 件(API の読み直し)。無ければ null。
 *
 * @return array{id:int, title:string, status:string, progress:int, topic:string,
 *               sortOrder:int, updatedAtEpoch:int, planRef:string, note:string}|null
 */
function km_tasks_find(PDO $pdo, int $id): ?array
{
    km_tasks_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT t.id, t.title, t.status, t.progress, t.topic, t.sort_order AS sortOrder,
                UNIX_TIMESTAMP(GREATEST(t.updated_at, COALESCE(d.updated_at, t.updated_at))) AS updatedAtEpoch,
                COALESCE(d.plan_ref, \'\') AS planRef, COALESCE(d.note, \'\') AS note
         FROM km_tasks t LEFT JOIN km_task_details d ON d.task_id = t.id
         WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/**
 * 分類ごとの一覧(サービス監視の「今後の拡張」など)。並びはかんばんと同じ。
 *
 * @return array<int, array{id:int, title:string, status:string, progress:int}>
 */
function km_tasks_by_topic(PDO $pdo, string $topic): array
{
    km_tasks_ensure_table($pdo);

    $stmt = $pdo->prepare('SELECT id, title, status, progress FROM km_tasks WHERE topic = ? ORDER BY sort_order, id');
    $stmt->execute([$topic]);

    return $stmt->fetchAll();
}

/** 分類の値を確かめる。知らない値は断る(画面の選択肢以外を入れさせない)。 */
function km_tasks_validate_topic(string $topic): void
{
    if (!array_key_exists($topic, KM_TASK_TOPICS)) {
        throw new InvalidArgumentException('分類の指定が不正です。');
    }
}

function km_tasks_validate(string $title, string $status, int $progress): void
{
    if (trim($title) === '') {
        throw new InvalidArgumentException('タイトルを入力してください。');
    }
    if (mb_strlen($title) > 255) {
        throw new InvalidArgumentException('タイトルは255文字以内にしてください。');
    }
    if (!in_array($status, KM_TASK_STATUSES, true)) {
        throw new InvalidArgumentException('状態の指定が不正です。');
    }
    if ($progress < 0 || $progress > 100) {
        throw new InvalidArgumentException('進捗は 0〜100 で指定してください。');
    }
}

/** 足したタスクの id を返す(API が読み直して返すため。2026-11-07)。 */
function km_tasks_create(PDO $pdo, string $title, string $status, int $progress, string $topic = ''): int
{
    km_tasks_ensure_table($pdo);
    km_tasks_validate($title, $status, $progress);
    km_tasks_validate_topic($topic);

    // 末尾に置く
    $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM km_tasks')->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO km_tasks (title, status, progress, topic, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    );
    try {
        $stmt->execute([trim($title), $status, $progress, $topic, $next]);
    } catch (PDOException $exception) {
        // 番号を付け替えた(km_tasks_renumber)あとは、自動の番号が使い済みの番号に当たることがある
        // (UPDATE では AUTO_INCREMENT が進まない)。そのときだけ「いちばん大きい番号 + 1」で入れ直す
        if ((string) $exception->getCode() !== '23000') {
            throw $exception;
        }
        $retry = $pdo->prepare(
            'INSERT INTO km_tasks (id, title, status, progress, topic, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $id = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM km_tasks')->fetchColumn();
        $retry->execute([$id, trim($title), $status, $progress, $topic, $next]);

        return $id;
    }

    return (int) $pdo->lastInsertId();
}

/**
 * 番号(#・id)の付け替え(2026-11-08、利用者「プロジェクト状況の # は編集できるようにしてください」)。
 *
 * # は km_tasks.id そのもの(proj-tasks.ps1 の -Id・status.md の「#112」と同じ)。**表の作りは変えない**(行の id を UPDATE するだけ)。
 * 1〜KM_TASK_ID_MAX の整数で、ほかのタスクが使っていない番号だけ。補足の表(km_task_details.task_id)も一緒に付け替える。
 * 消したタスクの補足が残っていれば(外部キーが無いので起こりうる)、新しい番号の古い補足は先に消す。
 */
const KM_TASK_ID_MAX = 999999;

function km_tasks_validate_new_id(int $newId): void
{
    if ($newId < 1 || $newId > KM_TASK_ID_MAX) {
        throw new InvalidArgumentException('番号は 1〜' . KM_TASK_ID_MAX . ' の整数にしてください。');
    }
}

function km_tasks_renumber(PDO $pdo, int $id, int $newId): void
{
    km_tasks_ensure_table($pdo);
    km_tasks_validate_new_id($newId);
    if ($newId === $id) {
        return;
    }
    if (km_tasks_find($pdo, $id) === null) {
        throw new InvalidArgumentException('そのタスクはありません。');
    }
    if (km_tasks_find($pdo, $newId) !== null) {
        throw new InvalidArgumentException("番号 {$newId} はほかのタスクが使っています。");
    }

    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('DELETE FROM km_task_details WHERE task_id = ?')->execute([$newId]);
        $pdo->prepare('UPDATE km_tasks SET id = ?, updated_at = NOW() WHERE id = ?')->execute([$newId, $id]);
        $pdo->prepare('UPDATE km_task_details SET task_id = ? WHERE task_id = ?')->execute([$newId, $id]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($own) {
            $pdo->rollBack();
        }
        // 同時にほかの人が同じ番号を取ったときは、重複の失敗になる
        if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
            throw new InvalidArgumentException("番号 {$newId} はほかのタスクが使っています。");
        }
        throw $exception;
    }
}

function km_tasks_update(PDO $pdo, int $id, string $title, string $status, int $progress, string $topic = ''): void
{
    km_tasks_ensure_table($pdo);
    km_tasks_validate($title, $status, $progress);
    km_tasks_validate_topic($topic);

    $stmt = $pdo->prepare(
        'UPDATE km_tasks SET title = ?, status = ?, progress = ?, topic = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([trim($title), $status, $progress, $topic, $id]);
}

function km_tasks_delete(PDO $pdo, int $id): void
{
    km_tasks_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_tasks WHERE id = ?')->execute([$id]);
    // 補足も消す(外部キーを張っていないので、ここで消さないと残る)
    $pdo->prepare('DELETE FROM km_task_details WHERE task_id = ?')->execute([$id]);
}

/**
 * カンバンでドラッグしたときの移動。レーン(status)と並び順をまとめて保存する。
 *
 * @param array<int, int> $orderedIds そのレーンに入っている ID を、上から順に並べたもの
 */
function km_tasks_move(PDO $pdo, string $status, array $orderedIds): void
{
    km_tasks_ensure_table($pdo);

    if (!in_array($status, KM_TASK_STATUSES, true)) {
        throw new InvalidArgumentException('レーンの指定が不正です。');
    }

    $stmt = $pdo->prepare('UPDATE km_tasks SET status = ?, sort_order = ?, updated_at = NOW() WHERE id = ?');
    foreach (array_values($orderedIds) as $index => $id) {
        $stmt->execute([$status, $index * 10, (int) $id]);
    }
}
