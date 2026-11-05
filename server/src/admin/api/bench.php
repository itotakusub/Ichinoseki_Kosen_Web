<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/bench.php';
require_once dirname(__DIR__, 2) . '/lib/admin-log.php';

/**
 * ベンチマークの口(2026-11-06。タスクマネージャーの「ベンチマーク」。中身は lib/bench.php)。
 *
 *   GET                          段の一覧・基準・履歴(新しい順 50 件)・開催中のイベント
 *   POST {action:'start', label} 回を始める(同時に 1 つだけ)→ {id, steps}
 *   POST {action:'step', id, step}  1 つの段を測って保存する → 段の結果
 *   POST {action:'finish', id}   分野と合計を出して閉じる → 回
 *   POST {action:'delete', id}   履歴を消す
 *
 * **数字は画面から受け取らない。** サーバーが自分で測って書く。POST は guard.php が書き込みの権限を求める。
 * nginx でタスクマネージャーと同じく IP を絞ってある(taskmgr-allow*.local.conf)。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('bench.php: ' . $exception->getMessage());
    $respond(503, ['error' => 'DB に繋がりません。']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $event = null;
    try {
        require_once dirname(__DIR__, 2) . '/lib/map-events.php';
        $active = km_map_event_active($pdo);
        $event = $active !== [] ? (string) ($active[0]['name'] ?? '') : null;
    } catch (Throwable $exception) {
        // イベントの表が無い・読めないときは注意を出さないだけ
    }
    $steps = [];
    foreach (KM_BENCH_STEPS as $id => [$group, $ja, $en, $inTotal]) {
        $steps[] = ['id' => $id, 'group' => $group, 'label' => $ja, 'labelEn' => $en, 'inTotal' => $inTotal];
    }
    $groups = [];
    foreach (KM_BENCH_GROUPS as $id => [$ja, $en]) {
        $groups[] = ['id' => $id, 'label' => $ja, 'labelEn' => $en];
    }
    $respond(200, [
        'suiteVersion' => KM_BENCH_VERSION,
        'steps' => $steps,
        'groups' => $groups,
        'reference' => KM_BENCH_REFERENCE,
        'cvWarn' => KM_BENCH_CV_WARN,
        'event' => $event,
        'runs' => km_bench_list($pdo, 50),
    ]);
}

if ($method !== 'POST') {
    $respond(405, ['error' => 'GET か POST で送ってください。']);
}
if (!km_csrf_verify()) {
    $respond(403, ['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。']);
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = (string) ($input['action'] ?? '');
$id = (int) ($input['id'] ?? 0);

// 1 段は 15 秒ほどまで。DB の段と並列の段は待ちが入るので、組み込みの 30 秒より少し広げる
set_time_limit(50);
ignore_user_abort(true);

try {
    switch ($action) {
        case 'start':
            $label = is_string($input['label'] ?? null) ? $input['label'] : '';
            $user = $GLOBALS['KM_USER'] ?? null;
            $runId = km_bench_start($pdo, 'admin', $label, is_array($user) ? (string) ($user['name'] ?? '') : '');
            km_admin_log_record('system', 'bench.run', km_bench_label($label) !== '' ? km_bench_label($label) : '#' . $runId);
            $respond(200, ['success' => true, 'id' => $runId, 'steps' => array_keys(KM_BENCH_STEPS)]);
            // no break(respond は戻らない)
        case 'step':
            $step = is_string($input['step'] ?? null) ? $input['step'] : '';
            $respond(200, ['success' => true, 'step' => $step, 'result' => km_bench_run_step($pdo, $id, $step)]);
            // no break
        case 'finish':
            $respond(200, ['success' => true, 'run' => km_bench_finish($pdo, $id)]);
            // no break
        case 'delete':
            if (!km_bench_delete($pdo, $id)) {
                $respond(404, ['error' => '消せる回がありません(動いている回は消せません)。']);
            }
            km_admin_log_record('system', 'bench.delete', '#' . $id);
            $respond(200, ['success' => true]);
            // no break
        default:
            $respond(400, ['error' => '知らない操作です。']);
    }
} catch (InvalidArgumentException $exception) {
    $respond(400, ['error' => $exception->getMessage()]);
} catch (PDOException $exception) {
    // PDOException は RuntimeException の子。DB の文言は画面に出さない
    error_log('bench.php DB: ' . $exception->getMessage());
    $respond(503, ['error' => 'DB に書けませんでした。']);
} catch (RuntimeException $exception) {
    $respond(409, ['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('bench.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    $respond(503, ['error' => '測れませんでした。']);
}
