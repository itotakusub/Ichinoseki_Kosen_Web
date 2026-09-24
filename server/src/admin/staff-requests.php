<?php

declare(strict_types=1);

/**
 * 教職員の権限の申請(docs/15-staff-org.ipynb。2026-09-18)。
 *
 * 本人が account.php から申請し、**ここで承認したときだけ** Logto の組織に入る(lib/staff-org.php)。
 * 承認すると、その人は地図の**教職員氏名**と**閲覧不可の地点**を見られるようになる —— 本人か確かめてから押す。
 *
 * - 承認 / 却下 … 未処理の申請だけ
 * - 取り消し   … 承認済みのものだけ。組織から外す(発行済みのトークンは最長 1 時間効く)
 *
 * 変更は POST だけ。書き込みの権限が無い人の POST は guard.php が断る。
 */

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/staff-org.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'staffRequests',
    'titleKey' => 'page.staffRequests.title',
    'title' => '教職員の申請 | KosenMap 管理',
    'h1Key' => 'page.staffRequests.h1',
    'h1' => '教職員の申請',
    'crumbs' => [
        ['key' => 'page.staffRequests.h1', 'text' => '教職員の申請'],
    ],
];

$errors = [];
$notice = null;
$enabled = km_staff_org_enabled();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if (!in_array($action, ['approve', 'reject', 'revoke'], true)) {
            throw new InvalidArgumentException('操作の指定が不正です。');
        }
        $result = km_staff_request_decide(km_db(), $id, $action, (string) ($KM_USER['sub'] ?? ''));
        $detail = "#{$id} {$result['userId']}";
        // 記録の名前は文字列のまま書く(check.php の admin-log が「記録していない文言」を探すため)
        match ($action) {
            'approve' => km_admin_log_record('auth', 'staff.approved', $detail),
            'reject' => km_admin_log_record('auth', 'staff.rejected', $detail),
            'revoke' => km_admin_log_record('auth', 'staff.revoked', $detail),
        };
        header('Location: ./staff-requests.php?done=' . rawurlencode($action), true, 302);
        exit;
    } catch (Throwable $exception) {
        error_log('staff-requests.php ' . $action . ' failed: ' . $exception->getMessage());
        $errors[] = km_admin_error_message($exception, '処理できませんでした。');
    }
}

$doneKeys = ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'];
if (isset($_GET['done'], $doneKeys[$_GET['done']])) {
    $notice = $doneKeys[$_GET['done']];
}

$rows = [];
$dbError = null;
try {
    $rows = km_staff_requests_list(km_db());
} catch (Throwable $exception) {
    error_log('staff-requests.php list failed: ' . $exception->getMessage());
    $dbError = true;
}
// 未処理を先に並べる(同じ状態の中は新しい順のまま)
usort($rows, static fn (array $a, array $b): int => ($a['status'] === 'pending' ? 0 : 1) <=> ($b['status'] === 'pending' ? 0 : 1));

$statusLabel = ['pending' => '未処理', 'approved' => '承認済み', 'rejected' => '却下', 'revoked' => '取り消し'];
$statusBadge = ['pending' => 'text-bg-warning', 'approved' => 'text-bg-success', 'rejected' => 'text-bg-secondary', 'revoked' => 'text-bg-dark'];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <div class="app-content">
          <div class="container-fluid">
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.staffRequests.done.<?= km_e($notice) ?>"></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if (!$enabled): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.staffRequests.disabled">
                教職員の組織が設定されていません(.env の KM_LOGTO_ORG_ID)。申請は受け付けていません。
              </div>
            <?php endif; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.staffRequests.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.staffRequests.notice">
                承認すると、その人は地図の教職員氏名と閲覧不可の地点を見られるようになります。本人か確かめてから承認してください。
                取り消しても、発行済みの権限は最長 1 時間残ります。
              </div>
            </div>

            <div class="card">
              <div class="card-header">
                <h3 class="card-title mb-0" data-i18n="page.staffRequests.cardTitle">申請の一覧</h3>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col" data-i18n="page.staffRequests.colWho">申請した人</th>
                        <th scope="col" data-i18n="page.staffRequests.colNote">ひとこと</th>
                        <th scope="col" data-i18n="page.staffRequests.colStatus">状態</th>
                        <th scope="col" data-i18n="page.staffRequests.colDate">申請日時</th>
                        <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($rows === [] && $dbError === null): ?>
                        <tr>
                          <td colspan="5" class="text-center text-body-secondary py-5" data-i18n="page.staffRequests.empty">申請はまだありません。</td>
                        </tr>
                      <?php endif; ?>
                      <?php foreach ($rows as $row): ?>
                        <tr>
                          <?php // 利用者が入れた自由文なので data-i18n は付けずにそのまま出す(projects.php と同じ方針) ?>
                          <td>
                            <div class="fw-semibold"><?= km_e((string) ($row['name'] ?? '')) ?></div>
                            <div class="small text-body-secondary"><?= km_e((string) ($row['email'] ?? '')) ?></div>
                            <div class="small text-body-secondary"><code><?= km_e($row['userId']) ?></code></div>
                          </td>
                          <td><?= km_e($row['note']) ?></td>
                          <td>
                            <span class="badge <?= km_e($statusBadge[$row['status']] ?? 'text-bg-secondary') ?>"
                                  data-i18n="page.staffRequests.status.<?= km_e($row['status']) ?>"><?= km_e($statusLabel[$row['status']] ?? $row['status']) ?></span>
                          </td>
                          <td class="text-nowrap small"><?= km_e(date('Y-m-d H:i', $row['createdAtEpoch'])) ?></td>
                          <td class="text-end text-nowrap">
                            <?php if ($row['status'] === 'pending' && $enabled): ?>
                              <form method="post" class="d-inline km-staff-form" data-km-confirm="page.staffRequests.confirmApprove">
                                <?= km_csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success" data-i18n="page.staffRequests.approve">承認</button>
                              </form>
                              <form method="post" class="d-inline km-staff-form" data-km-confirm="page.staffRequests.confirmReject">
                                <?= km_csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-i18n="page.staffRequests.reject">却下</button>
                              </form>
                            <?php elseif ($row['status'] === 'approved' && $enabled): ?>
                              <form method="post" class="d-inline km-staff-form" data-km-confirm="page.staffRequests.confirmRevoke">
                                <?= km_csrf_field() ?>
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" data-i18n="page.staffRequests.revoke">取り消し</button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
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
