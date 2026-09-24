<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/table-manage.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/** 許可されている型の候補(UI からはこの固定リストだけを選ばせる。将来もっと細かい指定が
 *  要るようになったら、ここへ選択肢を足すだけで lib/table-manage.php 側は変更不要)。 */
const KM_TABLES_TYPE_OPTIONS = ['INT', 'BIGINT', 'VARCHAR(255)', 'TEXT', 'DATE', 'DATETIME', 'BOOLEAN', 'DECIMAL(10,2)'];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $columnsInput = is_array($_POST['columns'] ?? null) ? $_POST['columns'] : [];
        $columns = [];
        foreach ($columnsInput as $column) {
            $colName = trim((string) ($column['name'] ?? ''));
            if ($colName === '') {
                continue;
            }
            $columns[] = [
                'name' => $colName,
                'type' => (string) ($column['type'] ?? ''),
                'nullable' => !empty($column['nullable']),
            ];
        }

        try {
            $pdo = km_db();
            km_table_create($pdo, $name, $columns);
            km_admin_log_record('table', 'table.create', $name);
            header('Location: ./tables.php?created=' . rawurlencode($name), true, 302);
            exit;
        } catch (Throwable $exception) {
            error_log('tables.php create failed: ' . $exception->getMessage());
            // 例外の文面をそのまま出さない(lib/user-error.php)。入力の検証だけはそのまま出る
            $errors[] = km_admin_error_message($exception, 'テーブルを作成できませんでした。');
        }
    } elseif ($action === 'delete') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $confirmName = trim((string) ($_POST['confirm_name'] ?? ''));

        if ($confirmName !== $name) {
            $errors[] = 'テーブル名の再入力が一致しません。削除を取り消しました。';
        } else {
            try {
                $pdo = km_db();
                km_table_soft_delete($pdo, $name);
                km_admin_log_record('table', 'table.delete', $name);
                header('Location: ./tables.php?deleted=' . rawurlencode($name), true, 302);
                exit;
            } catch (Throwable $exception) {
                error_log('tables.php delete failed: ' . $exception->getMessage());
                $errors[] = km_admin_error_message($exception, 'テーブルを削除できませんでした。');
            }
        }
    }
}

if (isset($_GET['created'])) {
    $notice = ['type' => 'created', 'name' => (string) $_GET['created']];
} elseif (isset($_GET['deleted'])) {
    $notice = ['type' => 'deleted', 'name' => (string) $_GET['deleted']];
}

$tables = [];
$dbError = null;
try {
    $pdo = km_db();
    $tables = km_table_list($pdo);
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$KM_PAGE = [
    'nav' => 'tables',
    'titleKey' => 'page.tables.title',
    'title' => 'テーブル管理 | KosenMap 管理',
    'h1Key' => 'page.tables.h1',
    'h1' => 'テーブル管理',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.tables.h1', 'text' => 'テーブル管理'],
    ],
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
            <?php if ($notice !== null && $notice['type'] === 'created'): ?>
              <div class="alert alert-success" role="alert">
                <span data-i18n="page.tables.createdNotice">テーブルを作成しました:</span>
                <code><?= km_e($notice['name']) ?></code>
              </div>
            <?php elseif ($notice !== null && $notice['type'] === 'deleted'): ?>
              <div class="alert alert-success" role="alert">
                <span data-i18n="page.tables.deletedNotice">テーブルを削除しました(一覧から非表示):</span>
                <code><?= km_e($notice['name']) ?></code>
              </div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.tables.dbError">
                データベースに接続できません。
              </div>
            <?php endif; ?>

            <!--begin::Notice-->
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                <div data-i18n-html="page.tables.scopeText">
                  作成できるのは <code>kmt_</code> で始まる名前のテーブルだけです。既存のシステム
                  テーブル(<code>km_</code> 接頭辞)には一切触れません。削除は物理的な DROP では
                  なく一覧から隠すだけの論理削除で、実際に消したい場合は phpMyAdmin から手動で
                  行ってください。
                </div>
              </div>
            </div>
            <!--end::Notice-->

            <!--begin::Row-->
            <div class="row">
              <div class="col-12">
                <!--begin::Card-->
                <div class="card">
                  <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h3 class="card-title mb-0" data-i18n="page.tables.cardTitle">テーブル一覧</h3>
                    <div class="card-tools ms-auto d-flex flex-wrap gap-2">
                      <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#km-table-create"
                      >
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                        <span data-i18n="page.tables.create">新規作成</span>
                      </button>
                    </div>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col" data-i18n="page.tables.colName">テーブル名</th>
                            <th scope="col" data-i18n="page.tables.colRows">行数</th>
                            <th scope="col" data-i18n="page.tables.colEngine">エンジン</th>
                            <th scope="col" data-i18n="page.tables.colUpdated">更新日時</th>
                            <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if ($dbError === null && $tables === []): ?>
                            <tr>
                              <td colspan="5" class="text-center text-body-secondary py-5">
                                <i class="bi bi-database-x fs-2 d-block mb-2" aria-hidden="true"></i>
                                <span data-i18n="page.tables.empty">まだテーブルがありません。「新規作成」から作成してください。</span>
                              </td>
                            </tr>
                          <?php endif; ?>
                          <?php foreach ($tables as $table): ?>
                            <tr>
                              <td><code><?= km_e((string) $table['name']) ?></code></td>
                              <td><?= $table['row_count'] === null ? '—' : km_e((string) $table['row_count']) ?></td>
                              <td><?= km_e((string) ($table['engine'] ?? '—')) ?></td>
                              <td><?= km_e((string) ($table['updated_at'] ?? '—')) ?></td>
                              <td class="text-end">
                                <a
                                  href="./table-data.php?table=<?= rawurlencode((string) $table['name']) ?>"
                                  class="btn btn-sm btn-outline-secondary"
                                >
                                  <i class="bi bi-table me-1" aria-hidden="true"></i>
                                  <span data-i18n="page.tables.open">開く</span>
                                </a>
                                <button
                                  type="button"
                                  class="btn btn-sm btn-outline-danger"
                                  data-bs-toggle="modal"
                                  data-bs-target="#km-table-delete"
                                  data-km-table-name="<?= km_e((string) $table['name']) ?>"
                                >
                                  <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                  <span data-i18n="common.delete">削除</span>
                                </button>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <!-- /.card-body -->
                  <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                    <?php // 接続先は環境で変わるので、翻訳する語と生の値を分ける ?>
                    <span class="text-body-secondary fs-7">
                      <span data-i18n="page.tables.footer">接続先</span>:
                      <?= km_e(km_site_host_port('mariadb')) ?> (MariaDB)
                    </span>
                    <a
                      href="<?= km_e(km_site_url('phpmyadmin', '/')) ?>"
                      class="btn btn-sm btn-outline-secondary ms-auto"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                      <span data-i18n="page.tables.openPhpMyAdmin">phpMyAdmin で開く</span>
                    </a>
                  </div>
                </div>
                <!--end::Card-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <!--begin::Create Modal-->
        <div class="modal fade" id="km-table-create" tabindex="-1" aria-labelledby="km-table-create-label" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
              <form method="post">
                <?= km_csrf_field() ?>
                <input type="hidden" name="action" value="create" />
                <div class="modal-header">
                  <h5 class="modal-title" id="km-table-create-label" data-i18n="page.tables.modalCreate">
                    テーブルを新規作成
                  </h5>
                  <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="閉じる"
                    data-i18n-attr="aria-label:common.close"
                  ></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label for="km-table-name" class="form-label">
                      <span data-i18n="page.tables.fieldName">テーブル名</span>
                      <span class="required-indicator sr-only" data-i18n="a11y.required"> (必須)</span>
                    </label>
                    <input
                      type="text"
                      class="form-control"
                      id="km-table-name"
                      name="name"
                      pattern="kmt_[a-z0-9_]{1,37}"
                      maxlength="41"
                      placeholder="kmt_events"
                      required
                    />
                    <div class="form-text" data-i18n="page.tables.fieldNameHint">
                      "kmt_" で始まる半角小文字・数字・アンダースコアのみ(最大41文字)。
                    </div>
                  </div>

                  <label class="form-label" data-i18n="page.tables.columnsLabel">カラム</label>
                  <div id="km-table-columns"></div>
                  <template id="km-table-column-template">
                    <div class="row g-2 align-items-end mb-2 km-table-column-row">
                      <div class="col-5">
                        <label class="form-label fs-7" data-i18n="page.tables.fieldColumnName">カラム名</label>
                        <input
                          type="text"
                          class="form-control form-control-sm"
                          name="columns[__i__][name]"
                          pattern="[a-z][a-z0-9_]{0,40}"
                          maxlength="41"
                          required
                        />
                      </div>
                      <div class="col-4">
                        <label class="form-label fs-7" data-i18n="page.tables.fieldColumnType">型</label>
                        <select class="form-select form-select-sm" name="columns[__i__][type]">
                          <?php foreach (KM_TABLES_TYPE_OPTIONS as $type): ?>
                            <option value="<?= km_e($type) ?>"><?= km_e($type) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-2">
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="checkbox"
                            name="columns[__i__][nullable]"
                            value="1"
                            checked
                            id="km-col-null-__i__"
                          />
                          <label class="form-check-label fs-7" for="km-col-null-__i__" data-i18n="page.tables.fieldNullable">
                            NULL可
                          </label>
                        </div>
                      </div>
                      <div class="col-1">
                        <button type="button" class="btn btn-sm btn-outline-danger km-table-column-remove" data-i18n-attr="aria-label:common.delete" aria-label="削除">
                          <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                      </div>
                    </div>
                  </template>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="km-table-add-column">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                    <span data-i18n="page.tables.addColumn">列を追加</span>
                  </button>
                  <div class="form-text mt-2" data-i18n="page.tables.idHint">
                    主キーの "id" (自動採番) は常に自動で追加されます。
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="common.cancel">
                    キャンセル
                  </button>
                  <button type="submit" class="btn btn-primary" data-i18n="common.save">
                    保存
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!--end::Create Modal-->

        <!--begin::Delete Modal-->
        <div class="modal fade" id="km-table-delete" tabindex="-1" aria-labelledby="km-table-delete-label" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <form method="post">
                <?= km_csrf_field() ?>
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="name" id="km-table-delete-name" value="" />
                <div class="modal-header">
                  <h5 class="modal-title" id="km-table-delete-label" data-i18n="page.tables.modalDelete">
                    テーブルを削除
                  </h5>
                  <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="閉じる"
                    data-i18n-attr="aria-label:common.close"
                  ></button>
                </div>
                <div class="modal-body">
                  <div class="alert alert-warning" role="alert" data-i18n="page.tables.deleteWarning">
                    一覧から隠すだけで、データは残ります(物理削除は phpMyAdmin から手動で行ってください)。
                  </div>
                  <p class="mb-2">
                    <span data-i18n="page.tables.deleteConfirmLabel">確認のため、削除するテーブル名を入力してください:</span>
                    <code id="km-table-delete-target"></code>
                  </p>
                  <input
                    type="text"
                    class="form-control"
                    name="confirm_name"
                    id="km-table-delete-confirm"
                    autocomplete="off"
                    required
                  />
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="common.cancel">
                    キャンセル
                  </button>
                  <button type="submit" class="btn btn-danger" data-i18n="common.delete">
                    削除
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!--end::Delete Modal-->

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            const columnsContainer = document.getElementById('km-table-columns');
            const template = document.getElementById('km-table-column-template');
            const addButton = document.getElementById('km-table-add-column');
            let columnIndex = 0;

            const addColumnRow = () => {
              const fragment = template.content.cloneNode(true);
              fragment.querySelectorAll('[name]').forEach((el) => {
                el.name = el.name.replace('__i__', String(columnIndex));
              });
              fragment.querySelectorAll('[id]').forEach((el) => {
                el.id = el.id.replace('__i__', String(columnIndex));
              });
              fragment.querySelectorAll('[for]').forEach((el) => {
                el.htmlFor = el.htmlFor.replace('__i__', String(columnIndex));
              });
              columnIndex += 1;
              columnsContainer.appendChild(fragment);
              if (window.KmI18n) {
                // 複製直後は data-i18n が未適用なので、現在の言語を明示的に再適用する
                window.KmI18n.set(window.KmI18n.current, { persist: false });
              }
            };

            addButton?.addEventListener('click', addColumnRow);
            columnsContainer?.addEventListener('click', (event) => {
              const removeButton = event.target.closest('.km-table-column-remove');
              if (removeButton) {
                removeButton.closest('.km-table-column-row')?.remove();
              }
            });

            // モーダルを開いたときに1行だけ最初から用意しておく
            document.getElementById('km-table-create')?.addEventListener('show.bs.modal', () => {
              if (columnsContainer.children.length === 0) {
                addColumnRow();
              }
            });

            const deleteModal = document.getElementById('km-table-delete');
            deleteModal?.addEventListener('show.bs.modal', (event) => {
              const button = event.relatedTarget;
              const name = button?.getAttribute('data-km-table-name') ?? '';
              document.getElementById('km-table-delete-name').value = name;
              document.getElementById('km-table-delete-target').textContent = name;
              document.getElementById('km-table-delete-confirm').value = '';
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
