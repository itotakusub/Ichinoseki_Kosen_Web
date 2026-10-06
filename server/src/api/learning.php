<?php

declare(strict_types=1);

/**
 * Wi-Fi の学習データと精度の評価(2026-10-06。中身は lib/learning-data.php)。**サーバーは保存するだけ。**
 *
 *   POST {"action":"pull","kind":"samples","since":N,"code":"…"}   変わった分を読む(地図のアクセスコード、または管理者のトークン)
 *   POST {"action":"pull","kind":"evaluations","since":N}          評価を読む … **管理者だけ**
 *   POST {"action":"push","samples":[…],"evaluations":[…]}         送る … **管理者だけ**
 *   POST {"action":"delete","kind":"samples","scope":"all|floor|nodes",…}  消す … **管理者だけ**
 *
 * アクセスコードは URL に載せない(ログに残る)ので、読むときも POST の本文で受ける。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/learning-data.php';

require_method('POST');

// 送るとき 200 件 × 数 KB。それより大きいものは読まずに断る
$input = km_api_json_body(2 * 1024 * 1024);

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/learning.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$action = (string) ($input['action'] ?? '');
$kind = (string) ($input['kind'] ?? 'samples');
if (!in_array($kind, ['samples', 'evaluations'], true)) {
    respond(['success' => false, 'message' => 'kind が違います。'], 400);
}

/** 管理者のトークンが付いていれば、その利用者。付いていなければ null(断らない)。 */
$admin = static function (): ?array {
    $principal = logto_optional_principal();
    if ($principal === null || ($principal['is_admin'] ?? 0) !== 1) {
        return null;
    }
    return $principal;
};

if ($action === 'pull') {
    $since = isset($input['since']) && is_int($input['since']) ? max(0, $input['since']) : 0;
    if ($kind === 'evaluations' || $admin() === null) {
        if ($kind === 'evaluations') {
            // 評価は管理者だけ(一般のアプリには配らない)
            $principal = logto_require_principal();
            logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);
        } else {
            $allowed = km_learning_code_allowed($pdo, (string) ($input['code'] ?? ''));
            if (!$allowed['ok']) {
                respond(['success' => false, 'message' => $allowed['message']], $allowed['status']);
            }
        }
    }
    try {
        $changes = km_learning_changes($pdo, $kind, $since);
    } catch (Throwable $exception) {
        error_log('api/learning.php pull failed: ' . $exception->getMessage());
        respond(['success' => false, 'message' => '読めませんでした。'], 500);
    }
    respond(['success' => true, 'kind' => $kind] + $changes);
}

// ここから先は書き込み。**管理者だけ**
$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);
$uploadedBy = (string) ($principal['subject'] ?? '') ?: null;
require_once __DIR__ . '/../lib/admin-log.php';
$GLOBALS['KM_USER'] = [
    'sub' => (string) ($principal['subject'] ?? ''),
    'name' => (string) ($principal['username'] ?? ''),
];

if ($action === 'push') {
    $samples = $input['samples'] ?? [];
    $evaluations = $input['evaluations'] ?? [];
    if (!is_array($samples) || !is_array($evaluations) || count($samples) + count($evaluations) > KM_LEARNING_PUSH_MAX) {
        respond(['success' => false, 'message' => '1 回に送れるのは ' . KM_LEARNING_PUSH_MAX . ' 件までです。'], 400);
    }
    try {
        $cleanSamples = array_map(static fn ($s) => km_learning_normalize_sample(is_array($s) ? $s : []), array_values($samples));
        $cleanEvaluations = array_map(static fn ($e) => km_learning_normalize_evaluation(is_array($e) ? $e : []), array_values($evaluations));
    } catch (InvalidArgumentException $exception) {
        respond(['success' => false, 'message' => $exception->getMessage()], 400);
    }
    try {
        $stored = km_learning_store($pdo, $cleanSamples, $cleanEvaluations, $uploadedBy);
    } catch (Throwable $exception) {
        error_log('api/learning.php push failed: ' . $exception->getMessage());
        respond(['success' => false, 'message' => '保存できませんでした。'], 500);
    }
    // 毎回は記録しない(学習のたびに送るので多い)。消したときだけ操作ログに残す
    respond(['success' => true, 'stored' => $stored, 'received' => ['samples' => count($cleanSamples), 'evaluations' => count($cleanEvaluations)]]);
}

if ($action === 'delete') {
    $scope = (string) ($input['scope'] ?? '');
    $floor = isset($input['floor']) && is_string($input['floor']) ? $input['floor'] : null;
    $nodes = is_array($input['nodeUuids'] ?? null) ? $input['nodeUuids'] : [];
    try {
        $count = km_learning_delete($pdo, $kind, $scope, $floor, $nodes);
    } catch (InvalidArgumentException $exception) {
        respond(['success' => false, 'message' => $exception->getMessage()], 400);
    } catch (Throwable $exception) {
        error_log('api/learning.php delete failed: ' . $exception->getMessage());
        respond(['success' => false, 'message' => '消せませんでした。'], 500);
    }
    km_admin_log_record('settings', 'learning.delete', ($kind === 'samples' ? '学習データ' : '評価') . ' ' . $count . ' 件(' . $scope . ($floor !== null ? ' ' . $floor : '') . ')');
    respond(['success' => true, 'deleted' => $count, 'message' => ($kind === 'samples' ? '学習データ' : '評価') . "を {$count} 件消しました(全員の端末から、次の同期で消えます)。"]);
}

respond(['success' => false, 'message' => 'action が違います。'], 400);
