<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/tasks.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'projects',
    'titleKey' => 'page.projects.title',
    'title' => 'プロジェクト状況 | KosenMap 管理',
    'h1Key' => 'page.projects.h1',
    'h1' => 'プロジェクト状況',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.projects.h1', 'text' => 'プロジェクト状況'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $title = (string) ($_POST['title'] ?? '');
    $status = (string) ($_POST['status'] ?? '');
    $progress = (int) ($_POST['progress'] ?? 0);
    $id = (int) ($_POST['id'] ?? 0);

    try {
        $pdo = km_db();

        if ($action === 'create') {
            km_tasks_create($pdo, $title, $status, $progress);
            km_admin_log_record('content', 'task.create', $title);
            header('Location: ./projects.php?created=1', true, 302);
            exit;
        }
        if ($action === 'update') {
            km_tasks_update($pdo, $id, $title, $status, $progress);
            km_admin_log_record('content', 'task.update', $title);
            header('Location: ./projects.php?updated=1', true, 302);
            exit;
        }
        if ($action === 'delete') {
            km_tasks_delete($pdo, $id);
            km_admin_log_record('content', 'task.delete', "#{$id}");
            header('Location: ./projects.php?deleted=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('projects.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文面をそのまま出さない(lib/user-error.php)。入力の検証だけはそのまま出る
        $errors[] = km_admin_error_message($exception, '保存できませんでした。');
    }
}

foreach (['created' => 'createdNotice', 'updated' => 'updatedNotice', 'deleted' => 'deletedNotice'] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

$rows = [];
$dbError = null;
try {
    $rows = km_tasks_all(km_db());
} catch (Throwable $exception) {
    error_log('projects.php list failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

// 状態の表示。日本語を原文として PHP 側に置き、辞書は対訳を持つだけ(他ページと同じ原則)。
$statusLabel = [
    'done' => '完了',
    'progress' => '進行中',
    'decision' => '要判断',
    'todo' => '未着手',
];

$statusBadge = [
    'done' => 'text-bg-success',
    'progress' => 'text-bg-info',
    'decision' => 'text-bg-warning',
    'todo' => 'text-bg-secondary',
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.projects.<?= km_e($notice) ?>"></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.projects.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.projects.notice">
                この管理画面プロジェクト自体の進捗です。カンバン画面と同じデータを見ています。
              </div>
            </div>

            <!--begin::Card-->
            <div class="card">
              <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h3 class="card-title mb-0" data-i18n="page.projects.cardTitle">タスク一覧</h3>
                <div class="card-tools ms-auto">
                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#km-task-create">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                    <span data-i18n="page.projects.add">タスクを追加</span>
                  </button>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col" data-i18n="page.projects.colTask">タスク</th>
                        <th scope="col" data-i18n="page.projects.colStatus">状態</th>
                        <th scope="col" class="km-col-progress" data-i18n="page.projects.colProgress">進捗</th>
                        <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($rows === [] && $dbError === null): ?>
                        <tr>
                          <td colspan="4" class="text-center text-body-secondary py-5" data-i18n="page.projects.empty">
                            タスクがありません。「タスクを追加」から登録してください。
                          </td>
                        </tr>
                      <?php endif; ?>
                      <?php foreach ($rows as $row): ?>
                        <tr>
                          <?php // 利用者が入れた自由文なので data-i18n は付けず、そのまま出す
                                // (翻訳するのは状態ラベルだけ。timeline.php と同じ方針) ?>
                          <td><?= km_e((string) $row['title']) ?></td>
                          <td>
                            <span
                              class="badge <?= km_e($statusBadge[$row['status']] ?? 'text-bg-secondary') ?>"
                              data-i18n="page.projects.status.<?= km_e((string) $row['status']) ?>"
                              ><?= km_e($statusLabel[$row['status']] ?? (string) $row['status']) ?></span
                            >
                          </td>
                          <td>
                            <div class="progress km-progress-sm" role="progressbar" aria-valuenow="<?= (int) $row['progress'] ?>" aria-valuemin="0" aria-valuemax="100">
                              <?php // 幅は動く値なので data 属性で渡し、下のスクリプトが style へ入れる ?>
                              <div class="progress-bar" data-km-progress="<?= (int) $row['progress'] ?>"></div>
                            </div>
                          </td>
                          <td class="text-end text-nowrap">
                            <button
                              type="button"
                              class="btn btn-sm btn-outline-secondary km-task-edit"
                              data-bs-toggle="modal"
                              data-bs-target="#km-task-edit"
                              data-km-task="<?= km_e((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"
                            >
                              <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <form method="post" class="d-inline km-task-delete-form">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="action" value="delete" />
                              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <!-- /.card-body -->
            </div>
            <!--end::Card-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <?php
          // 追加と編集でほぼ同じフォームなので、1つの無名関数から2回出す。
          $taskForm = static function (string $id, string $action, string $titleKey, string $titleText) use ($statusLabel) {
              ?>
              <div class="modal fade" id="<?= km_e($id) ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="action" value="<?= km_e($action) ?>" />
                      <?php if ($action === 'update'): ?>
                        <input type="hidden" name="id" id="km-task-edit-id" value="" />
                      <?php endif; ?>
                      <div class="modal-header">
                        <h5 class="modal-title" data-i18n="<?= km_e($titleKey) ?>"><?= km_e($titleText) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる" data-i18n-attr="aria-label:common.close"></button>
                      </div>
                      <div class="modal-body">
                        <div class="mb-3">
                          <label class="form-label" for="<?= km_e($id) ?>-title" data-i18n="page.projects.fieldTitle">タスク名</label>
                          <input type="text" class="form-control" id="<?= km_e($id) ?>-title" name="title" maxlength="255" required />
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="<?= km_e($id) ?>-status" data-i18n="page.projects.fieldStatus">状態</label>
                          <select class="form-select" id="<?= km_e($id) ?>-status" name="status">
                            <?php foreach ($statusLabel as $value => $label): ?>
                              <option value="<?= km_e($value) ?>" data-i18n="page.projects.status.<?= km_e($value) ?>"><?= km_e($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="mb-0">
                          <label class="form-label" for="<?= km_e($id) ?>-progress" data-i18n="page.projects.fieldProgress">進捗 (0〜100)</label>
                          <input type="number" class="form-control" id="<?= km_e($id) ?>-progress" name="progress" min="0" max="100" value="0" />
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="common.cancel">キャンセル</button>
                        <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <?php
          };

          $taskForm('km-task-create', 'create', 'page.projects.add', 'タスクを追加');
          $taskForm('km-task-edit', 'update', 'page.projects.edit', 'タスクを編集');
        ?>

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            // 進捗バーの幅。style="…" は CSP で使えないので、JS から要素の style へ入れる
            document.querySelectorAll('[data-km-progress]').forEach((bar) => {
              bar.style.width = bar.dataset.kmProgress + '%';
            });

            document.querySelectorAll('.km-task-delete-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                if (!window.confirm(window.KmI18n ? window.KmI18n.t('page.projects.confirmDelete') : 'delete?')) {
                  event.preventDefault();
                }
              });
            });

            const editModal = document.getElementById('km-task-edit');
            editModal?.addEventListener('show.bs.modal', (event) => {
              let task = {};
              try {
                task = JSON.parse(event.relatedTarget?.getAttribute('data-km-task') ?? '{}');
              } catch {
                task = {};
              }
              document.getElementById('km-task-edit-id').value = task.id ?? '';
              document.getElementById('km-task-edit-title').value = task.title ?? '';
              document.getElementById('km-task-edit-status').value = task.status ?? 'todo';
              document.getElementById('km-task-edit-progress').value = task.progress ?? 0;
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
