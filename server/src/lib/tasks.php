<?php

declare(strict_types=1);

/**
 * projects.php(進捗表)と kanban.php(ボード)が共有するタスク。
 *
 * 元は両ページとも別々のハードコードだったが、実体は同じ「タスクの一覧」なので1つの表で賄う。
 * projects は status を4値(完了/進行中/要判断/未着手)として、kanban はそのうち3つを
 * レーンとして見る、という関係になっている。
 *
 * lib/chat.php / lib/admin-log.php と同じ「初回アクセス時に CREATE TABLE IF NOT EXISTS」。
 */

require_once __DIR__ . '/db.php';

/** projects.php の状態バッジと kanban.php のレーンの両方がこの4値を使う。 */
const KM_TASK_STATUSES = ['done', 'progress', 'decision', 'todo'];

/** kanban は「要判断」レーンを持たないので、ボード上はこの3つだけを並べる。 */
const KM_TASK_LANES = ['todo', 'progress', 'done'];

function km_tasks_ensure_table(PDO $pdo): void
{
    // 表が既に在るなら何もしない(初期データも入れない)。1リクエストで何度も呼ばれるので覚えておく
    static $ready = false;
    if ($ready) {
        return;
    }
    if (km_db_table_exists($pdo, 'km_tasks')) {
        $ready = true;
        return;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(16) NOT NULL,
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (status),
            INDEX (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    km_tasks_seed_once($pdo);
    $ready = true;
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
 * @return array<int, array{id:int, title:string, status:string, progress:int,
 *                          sortOrder:int, updatedAtEpoch:int}>
 */
function km_tasks_all(PDO $pdo): array
{
    km_tasks_ensure_table($pdo);

    return $pdo->query(
        'SELECT id, title, status, progress, sort_order AS sortOrder,
                UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
         FROM km_tasks ORDER BY sort_order, id'
    )->fetchAll();
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

function km_tasks_create(PDO $pdo, string $title, string $status, int $progress): void
{
    km_tasks_ensure_table($pdo);
    km_tasks_validate($title, $status, $progress);

    // 末尾に置く
    $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM km_tasks')->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO km_tasks (title, status, progress, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([trim($title), $status, $progress, $next]);
}

function km_tasks_update(PDO $pdo, int $id, string $title, string $status, int $progress): void
{
    km_tasks_ensure_table($pdo);
    km_tasks_validate($title, $status, $progress);

    $stmt = $pdo->prepare(
        'UPDATE km_tasks SET title = ?, status = ?, progress = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([trim($title), $status, $progress, $id]);
}

function km_tasks_delete(PDO $pdo, int $id): void
{
    km_tasks_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_tasks WHERE id = ?')->execute([$id]);
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
