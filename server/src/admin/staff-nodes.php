<?php

declare(strict_types=1);

/**
 * 教職員の地点(docs/15 段 G。2026-09-18)。
 *
 * - 割り当て … 地点の ID(地図編集の「地点の ID」)を、承認済みの教職員に割り当てる
 * - 提案     … 教職員が account.php から送った変更。**承認したときだけ**地図に入る(lib/staff-nodes.php)
 *
 * 承認で書くのは**変えた項目だけ**。アプリへは、そのあと「地図の公開」で配る。
 * 変更は POST だけ。書き込みの権限が無い人の POST は guard.php が断る。
 */

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/staff-nodes.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'staffNodes',
    'titleKey' => 'page.staffNodes.title',
    'title' => '教職員の地点 | KosenMap 管理',
    'h1Key' => 'page.staffNodes.h1',
    'h1' => '教職員の地点',
    'crumbs' => [
        ['key' => 'page.staffNodes.h1', 'text' => '教職員の地点'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $by = (string) ($KM_USER['sub'] ?? '');
    try {
        $pdo = km_db();
        // 記録の名前は文字列のまま書く(check.php の admin-log が「記録していない文言」を探すため)
        switch ($action) {
            case 'assign':
                $userId = (string) ($_POST['user_id'] ?? '');
                $nodeId = km_staff_node_assign($pdo, (string) ($_POST['node'] ?? ''), $userId, $by);
                km_admin_log_record('content', 'staffnode.assigned', "{$nodeId} {$userId}");
                break;
            case 'unassign':
                $nodeId = (string) ($_POST['node_id'] ?? '');
                $userId = (string) ($_POST['user_id'] ?? '');
                km_staff_node_unassign($pdo, $nodeId, $userId);
                km_admin_log_record('content', 'staffnode.unassigned', "{$nodeId} {$userId}");
                break;
            case 'approve':
            case 'reject':
                $id = (int) ($_POST['id'] ?? 0);
                $result = km_staff_node_edit_decide($pdo, $id, $action, $by);
                $detail = "#{$id} {$result['nodeId']}";
                match ($action) {
                    'approve' => km_admin_log_record('content', 'staffnode.approved', $detail),
                    'reject' => km_admin_log_record('content', 'staffnode.rejected', $detail),
                };
                break;
            default:
                throw new InvalidArgumentException('操作の指定が不正です。');
        }
        header('Location: ./staff-nodes.php?done=' . rawurlencode($action), true, 302);
        exit;
    } catch (Throwable $exception) {
        error_log('staff-nodes.php ' . $action . ' failed: ' . $exception->getMessage());
        $errors[] = km_admin_error_message($exception, '処理できませんでした。');
    }
}

$doneKeys = ['assign' => 'assigned', 'unassign' => 'unassigned', 'approve' => 'approved', 'reject' => 'rejected'];
if (isset($_GET['done'], $doneKeys[$_GET['done']])) {
    $notice = $doneKeys[$_GET['done']];
}

$teachers = [];
$assignments = [];
$pending = [];
$recent = [];
$nodes = [];
$currentFields = [];
$dbError = null;
try {
    $pdo = km_db();
    // 割り当ての相手は「承認済みの教職員」だけ。同じ人の申請が複数あっても 1 人として並べる
    foreach (km_staff_requests_list($pdo) as $request) {
        if (!isset($teachers[$request['userId']]) && (km_staff_request_latest($pdo, $request['userId'])['status'] ?? null) === 'approved') {
            $teachers[$request['userId']] = $request;
        }
    }
    $assignments = km_staff_node_assignments($pdo);
    $edits = km_staff_node_edits($pdo);
    $pending = array_values(array_filter($edits, static fn (array $e): bool => $e['status'] === 'pending'));
    $recent = array_slice(array_values(array_filter($edits, static fn (array $e): bool => $e['status'] !== 'pending')), 0, 20);
    foreach (array_merge(array_column($assignments, 'nodeId'), array_column($edits, 'nodeId')) as $nodeId) {
        if (!array_key_exists($nodeId, $nodes)) {
            $node = km_map_node_find($pdo, $nodeId);
            $nodes[$nodeId] = $node;
            $currentFields[$nodeId] = $node !== null ? km_staff_node_current_fields($pdo, $node) : [];
        }
    }
} catch (Throwable $exception) {
    error_log('staff-nodes.php list failed: ' . $exception->getMessage());
    $dbError = true;
}

// 申請の表から、人の見出し(名前・メール)を引く
$whoLabel = static function (string $userId) use ($teachers): string {
    $t = $teachers[$userId] ?? null;
    if ($t === null) {
        return $userId;
    }
    $name = trim((string) ($t['name'] ?? ''));
    $email = trim((string) ($t['email'] ?? ''));

    return $name !== '' ? $name . ($email !== '' ? " <{$email}>" : '') : ($email !== '' ? $email : $userId);
};
$nodeLabel = static fn (string $nodeId): string => (string) ($nodes[$nodeId]['name'] ?? '');
$fieldLabel = ['title' => '名前', 'subtitle' => '部屋番号など', 'occupantName' => '教職員氏名', 'note' => 'メモ'];
$statusLabel = ['approved' => '承認', 'rejected' => '却下', 'withdrawn' => '取り下げ'];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <div class="app-content">
          <div class="container-fluid">
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.staffNodes.done.<?= km_e($notice) ?>"></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.staffNodes.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.staffNodes.notice">
                教職員は、割り当てた地点の名前・部屋番号など・教職員氏名・メモを直して送れます。位置・種類・経路は変えられません。
                送られた変更は、ここで承認したときだけ地図に入ります。アプリへは、そのあと「地図の公開」で配信されます。
              </div>
            </div>

            <div class="card mb-4">
              <div class="card-header">
                <h3 class="card-title mb-0" data-i18n="page.staffNodes.pendingTitle">確認待ちの変更</h3>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col" data-i18n="page.staffNodes.colNode">地点</th>
                        <th scope="col" data-i18n="page.staffNodes.colWho">送った人</th>
                        <th scope="col" data-i18n="page.staffNodes.colChanges">変更</th>
                        <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($pending === [] && $dbError === null): ?>
                        <tr>
                          <td colspan="4" class="text-center text-body-secondary py-4" data-i18n="page.staffNodes.pendingEmpty">確認待ちの変更はありません。</td>
                        </tr>
                      <?php endif; ?>
                      <?php foreach ($pending as $edit): ?>
                        <tr>
                          <td>
                            <div class="fw-semibold"><?= km_e($nodeLabel($edit['nodeId'])) ?></div>
                            <div class="small text-body-secondary"><code><?= km_e($edit['nodeId']) ?></code></div>
                          </td>
                          <td class="small"><?= km_e($whoLabel($edit['userId'])) ?><br><?= km_e(date('Y-m-d H:i', $edit['createdAtEpoch'])) ?></td>
                          <td>
                            <?php // 利用者が入れた自由文なので data-i18n は付けずにそのまま出す ?>
                            <?php foreach ($edit['changes'] as $field => $change): ?>
                              <?php
                              $now = $currentFields[$edit['nodeId']][$field] ?? null;
                              $moved = $now !== null && $now !== (string) ($change['from'] ?? '');
                              ?>
                              <div class="mb-2">
                                <div class="small fw-semibold"><?= km_e($fieldLabel[$field] ?? $field) ?></div>
                                <div class="small"><del class="text-body-secondary"><?= km_e((string) ($change['from'] ?? '')) ?: '(空)' ?></del></div>
                                <div class="small"><ins><?= km_e((string) ($change['to'] ?? '')) ?: '(空)' ?></ins></div>
                                <?php if ($moved): ?>
                                  <div class="small text-warning-emphasis">
                                    <span data-i18n="page.staffNodes.changedSince">送られたあとに地図の値が変わっています。いまの値:</span>
                                    <?= km_e((string) $now) ?: '(空)' ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                            <?php endforeach; ?>
                          </td>
                          <td class="text-end text-nowrap">
                            <form method="post" class="d-inline km-staff-form" data-km-confirm="page.staffNodes.confirmApprove">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="action" value="approve">
                              <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                              <button type="submit" class="btn btn-sm btn-success" data-i18n="page.staffNodes.approve">承認して反映</button>
                            </form>
                            <form method="post" class="d-inline km-staff-form" data-km-confirm="page.staffNodes.confirmReject">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="action" value="reject">
                              <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                              <button type="submit" class="btn btn-sm btn-outline-secondary" data-i18n="page.staffNodes.reject">却下</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card mb-4">
              <div class="card-header">
                <h3 class="card-title mb-0" data-i18n="page.staffNodes.assignTitle">地点の割り当て</h3>
              </div>
              <div class="card-body">
                <?php if ($teachers === []): ?>
                  <p class="text-body-secondary mb-3" data-i18n="page.staffNodes.noTeachers">承認済みの教職員がいません(「教職員の申請」で承認すると選べます)。</p>
                <?php else: ?>
                  <form method="post" class="row g-2 align-items-end mb-3">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="action" value="assign">
                    <div class="col-md-5">
                      <label class="form-label" for="km-assign-node" data-i18n="page.staffNodes.fieldNode">地点の ID</label>
                      <input type="text" class="form-control font-monospace" id="km-assign-node" name="node" required maxlength="40"
                             placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                      <div class="form-text" data-i18n="page.staffNodes.fieldNodeHint">地図編集で地点を開くと出る「地点の ID」を貼ってください。</div>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label" for="km-assign-user" data-i18n="page.staffNodes.fieldTeacher">教職員</label>
                      <select class="form-select" id="km-assign-user" name="user_id" required>
                        <?php foreach ($teachers as $userId => $teacher): ?>
                          <option value="<?= km_e($userId) ?>"><?= km_e($whoLabel($userId)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <button type="submit" class="btn btn-primary w-100" data-i18n="page.staffNodes.assign">割り当てる</button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 border-top">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col" data-i18n="page.staffNodes.colNode">地点</th>
                        <th scope="col" data-i18n="page.staffNodes.colTeacher">教職員</th>
                        <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($assignments === [] && $dbError === null): ?>
                        <tr>
                          <td colspan="3" class="text-center text-body-secondary py-4" data-i18n="page.staffNodes.assignEmpty">割り当てはまだありません。</td>
                        </tr>
                      <?php endif; ?>
                      <?php foreach ($assignments as $assignment): ?>
                        <tr>
                          <td>
                            <?php if ($nodes[$assignment['nodeId']] ?? null): ?>
                              <div class="fw-semibold"><?= km_e($nodeLabel($assignment['nodeId'])) ?></div>
                            <?php else: ?>
                              <div class="text-warning-emphasis" data-i18n="page.staffNodes.nodeMissing">地図にない地点(削除された可能性があります)</div>
                            <?php endif; ?>
                            <div class="small text-body-secondary"><code><?= km_e($assignment['nodeId']) ?></code></div>
                          </td>
                          <td class="small">
                            <?= km_e($whoLabel($assignment['userId'])) ?>
                            <?php if (!isset($teachers[$assignment['userId']])): ?>
                              <span class="badge text-bg-dark" data-i18n="page.staffNodes.notTeacher">権限なし</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-end">
                            <form method="post" class="d-inline km-staff-form" data-km-confirm="page.staffNodes.confirmUnassign">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="action" value="unassign">
                              <input type="hidden" name="node_id" value="<?= km_e($assignment['nodeId']) ?>">
                              <input type="hidden" name="user_id" value="<?= km_e($assignment['userId']) ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger" data-i18n="page.staffNodes.unassign">外す</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <?php if ($recent !== []): ?>
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title mb-0" data-i18n="page.staffNodes.recentTitle">最近処理した変更</h3>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <tbody>
                        <?php foreach ($recent as $edit): ?>
                          <tr>
                            <td class="small text-nowrap"><?= km_e(date('Y-m-d H:i', $edit['decidedAtEpoch'] ?? $edit['createdAtEpoch'])) ?></td>
                            <td class="small"><?= km_e($nodeLabel($edit['nodeId']) ?: $edit['nodeId']) ?></td>
                            <td class="small"><?= km_e($whoLabel($edit['userId'])) ?></td>
                            <td class="small"><?= km_e(implode(' / ', array_map(static fn ($f) => $fieldLabel[$f] ?? $f, array_keys($edit['changes'])))) ?></td>
                            <td class="small">
                              <span class="badge text-bg-secondary" data-i18n="page.staffNodes.status.<?= km_e($edit['status']) ?>"><?= km_e($statusLabel[$edit['status']] ?? $edit['status']) ?></span>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            document.querySelectorAll('.km-staff-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                const key = form.dataset.kmConfirm || '';
                const text = window.KmI18n ? window.KmI18n.t(key) : '実行しますか?';
                if (!window.confirm(text)) {
                  event.preventDefault();
                }
              });
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
