<?php

declare(strict_types=1);

/**
 * プロジェクト状況(管理画面 admin/projects.php・kanban.php と同じ km_tasks)を読み書きする口(2026-11-07、利用者の指示)。
 *
 * **鍵 KM_PROJ_PASSKEY が要る**(lib/proj-passkey.php。ヘッダーで送る。URL に載せない)。手元からは scripts/proj-tasks.ps1 で叩く。
 *
 *   GET    /api/project-tasks.php                      一覧(?topic=web ?status=todo ?plan=段7 で絞る)と、分類・状態の一覧
 *   GET    /api/project-tasks.php?id=12                1 件
 *   POST   /api/project-tasks.php                      足す   {"title":"…","status":"todo","progress":0,"topic":"web","planRef":"段7-1","note":"…"}
 *                                                      まとめて足す {"tasks":[{…},{…}]}(50 件まで・全部か無しか)
 *   PATCH  /api/project-tasks.php?id=12                直す(送った項目だけ)。PUT も同じ。{"id":5} で番号(#)を 5 に付け替える(使い済みは 400)
 *   DELETE /api/project-tasks.php?id=12                消す
 *
 * - 状態は done / progress / decision / todo。省いたら todo(done なら進捗の既定は 100)
 * - 分類は lib/tasks.php の KM_TASK_TOPICS の値だけ(知らない値は 400)
 * - 本文は 64KB まで。書いたら管理の記録(タイムライン)に「プロジェクトの鍵(API) がタスクを…」と残る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/tasks.php';
require_once __DIR__ . '/../lib/admin-log.php';
require_once __DIR__ . '/../lib/proj-passkey.php';

const KM_PROJ_TASKS_BATCH_MAX = 50;

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, PUT, DELETE');
    respond(['success' => false, 'message' => '使えるのは GET・POST・PATCH・PUT・DELETE です。'], 405);
}

km_proj_passkey_guard();

/** @param array<string, mixed> $row */
function km_proj_task_out(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'status' => (string) $row['status'],
        'progress' => (int) $row['progress'],
        'topic' => (string) $row['topic'],
        'planRef' => (string) $row['planRef'],
        'note' => (string) $row['note'],
        'sortOrder' => (int) $row['sortOrder'],
        'updatedAt' => date(DATE_ATOM, (int) $row['updatedAtEpoch']),
    ];
}

/**
 * 本文の 1 件を、いまの値(直すとき)か既定(足すとき)に重ねる。知らない項目は断る(打ち間違いを黙って捨てない)。
 *
 * @param array<string, mixed> $input
 * @param array{title:string, status:string, progress:int, topic:string, planRef:string, note:string} $base
 * @return array{title:string, status:string, progress:int, topic:string, planRef:string, note:string}
 */
function km_proj_task_merge(array $input, array $base, bool $creating): array
{
    $known = ['title', 'status', 'progress', 'topic', 'planRef', 'note'];
    $unknown = array_diff(array_keys($input), $known);
    if ($unknown !== []) {
        throw new InvalidArgumentException('知らない項目です: ' . implode(', ', array_map('strval', $unknown)) . '(使えるのは ' . implode(', ', $known) . ')');
    }
    foreach (['title', 'status', 'topic', 'planRef', 'note'] as $name) {
        if (array_key_exists($name, $input) && !is_string($input[$name])) {
            throw new InvalidArgumentException("{$name} は文字列で送ってください。");
        }
    }
    if (array_key_exists('progress', $input) && !is_int($input['progress'])) {
        throw new InvalidArgumentException('progress は 0〜100 の整数で送ってください。');
    }
    if ($creating && !isset($input['title'])) {
        throw new InvalidArgumentException('title が要ります。');
    }

    $out = $base;
    foreach ($known as $name) {
        if (array_key_exists($name, $input)) {
            $out[$name] = $input[$name];
        }
    }
    // 完了にしたのに進捗を送らなかったら 100 にする(画面の「完了 0%」を作らない)
    if (($input['status'] ?? null) === 'done' && !array_key_exists('progress', $input)) {
        $out['progress'] = 100;
    }

    return $out;
}

function km_proj_task_id_param(): int
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        respond(['success' => false, 'message' => '?id=<数> が要ります。'], 400);
    }

    return (int) $id;
}

try {
    $pdo = km_db();

    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $row = km_tasks_find($pdo, km_proj_task_id_param());
            if ($row === null) {
                respond(['success' => false, 'message' => 'そのタスクはありません。'], 404);
            }
            respond(['success' => true, 'task' => km_proj_task_out($row)]);
        }
        $topic = isset($_GET['topic']) ? (string) $_GET['topic'] : null;
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
        $plan = isset($_GET['plan']) ? (string) $_GET['plan'] : null;
        $rows = array_values(array_filter(km_tasks_all($pdo), static fn (array $row): bool =>
            ($topic === null || (string) $row['topic'] === $topic)
            && ($status === null || (string) $row['status'] === $status)
            && ($plan === null || str_starts_with((string) $row['planRef'], $plan))));
        respond([
            'success' => true,
            'tasks' => array_map('km_proj_task_out', $rows),
            'topics' => KM_TASK_TOPICS,
            'statuses' => KM_TASK_STATUSES,
        ]);
    }

    if ($method === 'DELETE') {
        $id = km_proj_task_id_param();
        $row = km_tasks_find($pdo, $id);
        if ($row === null) {
            respond(['success' => false, 'message' => 'そのタスクはありません。'], 404);
        }
        km_tasks_delete($pdo, $id);
        km_admin_log_record('content', 'task.delete', "#{$id} " . (string) $row['title']);
        respond(['success' => true, 'deleted' => $id]);
    }

    $input = km_api_json_body();

    if ($method === 'POST') {
        $defaults = ['title' => '', 'status' => 'todo', 'progress' => 0, 'topic' => '', 'planRef' => '', 'note' => ''];
        $batch = array_key_exists('tasks', $input);
        if ($batch && (count($input) !== 1 || !is_array($input['tasks']) || !array_is_list($input['tasks']))) {
            throw new InvalidArgumentException('まとめて足すときは {"tasks":[…]} だけを送ってください。');
        }
        $items = $batch ? $input['tasks'] : [$input];
        if ($items === [] || count($items) > KM_PROJ_TASKS_BATCH_MAX) {
            throw new InvalidArgumentException('まとめて足せるのは 1〜' . KM_PROJ_TASKS_BATCH_MAX . ' 件です。');
        }
        // 先に全部を確かめる(途中で断って半分だけ入る、を起こさない)
        $merged = [];
        foreach ($items as $i => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException("{$i} 番目がオブジェクトではありません。");
            }
            $one = km_proj_task_merge($item, $defaults, true);
            km_tasks_validate($one['title'], $one['status'], $one['progress']);
            km_tasks_validate_topic($one['topic']);
            km_task_details_validate(trim($one['planRef']), trim($one['note']));
            $merged[] = $one;
        }
        // 表を作る文(DDL)はトランザクションを暗黙に確定させるので、先に済ませておく
        km_tasks_ensure_table($pdo);
        $pdo->beginTransaction();
        $ids = [];
        try {
            foreach ($merged as $one) {
                $id = km_tasks_create($pdo, $one['title'], $one['status'], $one['progress'], $one['topic']);
                km_task_details_set($pdo, $id, $one['planRef'], $one['note']);
                $ids[] = $id;
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        foreach ($merged as $i => $one) {
            km_admin_log_record('content', 'task.create', trim($one['planRef'] . ' ' . $one['title']));
        }
        $tasks = array_map(static fn (int $id): array => km_proj_task_out((array) km_tasks_find($pdo, $id)), $ids);
        respond($batch ? ['success' => true, 'tasks' => $tasks] : ['success' => true, 'task' => $tasks[0]], 201);
    }

    // PATCH / PUT —— 送った項目だけ直す
    $id = km_proj_task_id_param();
    $row = km_tasks_find($pdo, $id);
    if ($row === null) {
        respond(['success' => false, 'message' => 'そのタスクはありません。'], 404);
    }
    if ($input === []) {
        throw new InvalidArgumentException('直す項目を送ってください。');
    }
    // 番号(#)の付け替え(2026-11-08)。本文の "id" が新しい番号。ほかの項目を書く前に確かめる
    $newId = $id;
    if (array_key_exists('id', $input)) {
        if (!is_int($input['id'])) {
            throw new InvalidArgumentException('id(新しい番号)は整数で送ってください。');
        }
        $newId = $input['id'];
        km_tasks_validate_new_id($newId);
        if ($newId !== $id && km_tasks_find($pdo, $newId) !== null) {
            throw new InvalidArgumentException("番号 {$newId} はほかのタスクが使っています。");
        }
        unset($input['id']);
    }
    $current = [
        'title' => (string) $row['title'], 'status' => (string) $row['status'], 'progress' => (int) $row['progress'],
        'topic' => (string) $row['topic'], 'planRef' => (string) $row['planRef'], 'note' => (string) $row['note'],
    ];
    $next = km_proj_task_merge($input, $current, false);
    km_task_details_validate(trim($next['planRef']), trim($next['note']));
    km_tasks_update($pdo, $id, $next['title'], $next['status'], $next['progress'], $next['topic']);
    km_task_details_set($pdo, $id, $next['planRef'], $next['note']);
    km_tasks_renumber($pdo, $id, $newId);
    km_admin_log_record('content', 'task.update', trim(($newId !== $id ? "#{$id} → #{$newId} " : '') . $next['planRef'] . ' ' . $next['title']));
    respond(['success' => true, 'task' => km_proj_task_out((array) km_tasks_find($pdo, $newId))]);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/project-tasks.php ' . $method . ' failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}
