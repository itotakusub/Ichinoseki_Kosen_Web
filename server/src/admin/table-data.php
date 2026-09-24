<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/table-manage.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

const KM_TABLE_DATA_PAGE_SIZE = 50;

/** datetime-local の "2026-08-08T14:30" 形式を MySQL の "2026-08-08 14:30:00" へ。 */
function km_table_data_normalize_datetime(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
        $value .= ':00';
    }
    return $value;
}

/** POST された values[] を、DATETIME カラムだけ正規化してから渡す。 */
function km_table_data_normalize_values(array $columns, array $rawValues): array
{
    $values = [];
    foreach ($columns as $column) {
        $colName = (string) $column['name'];
        if (!array_key_exists($colName, $rawValues)) {
            continue;
        }
        $value = (string) $rawValues[$colName];
        if ($column['data_type'] === 'datetime') {
            $value = km_table_data_normalize_datetime($value);
        }
        $values[$colName] = $value;
    }
    return $values;
}

$tableName = trim((string) ($_GET['table'] ?? $_POST['table'] ?? ''));
$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo = km_db();
        $columns = km_table_columns($pdo, $tableName);

        if ($action === 'insert') {
            $values = km_table_data_normalize_values($columns, is_array($_POST['values'] ?? null) ? $_POST['values'] : []);
            km_table_row_insert($pdo, $tableName, $values);
            km_admin_log_record('table', 'row.insert', $tableName);
            header('Location: ./table-data.php?table=' . rawurlencode($tableName) . '&inserted=1', true, 302);
            exit;
        }

        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $values = km_table_data_normalize_values($columns, is_array($_POST['values'] ?? null) ? $_POST['values'] : []);
            km_table_row_update($pdo, $tableName, $id, $values);
            km_admin_log_record('table', 'row.update', "{$tableName} #{$id}");
            header('Location: ./table-data.php?table=' . rawurlencode($tableName) . '&updated=1', true, 302);
            exit;
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            km_table_row_delete($pdo, $tableName, $id);
            km_admin_log_record('table', 'row.delete', "{$tableName} #{$id}");
            header('Location: ./table-data.php?table=' . rawurlencode($tableName) . '&deleted=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('table-data.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文面をそのまま出さない(lib/user-error.php)。入力の検証だけはそのまま出る
        $errors[] = km_admin_error_message($exception, '保存できませんでした。');
    }
}

foreach (['inserted' => 'insertedNotice', 'updated' => 'updatedNotice', 'deleted' => 'deletedNotice'] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

$offset = max(0, (int) ($_GET['offset'] ?? 0));

$columns = [];
$rows = [];
$totalRows = 0;
$dbError = null;
try {
    $pdo = km_db();
    $columns = km_table_columns($pdo, $tableName);
    $data = km_table_rows($pdo, $tableName, KM_TABLE_DATA_PAGE_SIZE, $offset);
    $rows = $data['rows'];
    $totalRows = km_table_row_count($pdo, $tableName);
} catch (Throwable $exception) {
    // 下でそのまま表示するので、DB の文面は入れない(知らないテーブル名などの検証の文は出る)
    $dbError = km_admin_error_message($exception, 'テーブルを読み込めませんでした。');
}

/** 表示・入力欄の組み立てに使う、カラムごとの HTML input type。 */
function km_table_data_input_type(string $dataType): string
{
    return match ($dataType) {
        'int', 'bigint' => 'number',
        'decimal' => 'number',
        'date' => 'date',
        'datetime' => 'datetime-local',
        'tinyint' => 'select', // BOOLEAN は tinyint(1) として保存される(このツール経由の列だけの前提)
        default => 'text',
    };
}

$editableColumns = array_values(array_filter($columns, static fn (array $c): bool => strtolower($c['name']) !== 'id'));

$KM_PAGE = [
    'nav' => 'tables',
    'titleKey' => 'page.tableData.title',
    'title' => 'テーブルデータ | KosenMap 管理',
    'h1Key' => 'page.tableData.h1',
    'h1' => 'テーブルデータ',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.tables.h1', 'text' => 'テーブル管理'],
        ['key' => 'page.tableData.h1', 'text' => 'テーブルデータ'],
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
            <p class="mb-3">
              <a href="./tables.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i><span data-i18n="page.tableData.back">テーブル管理へ戻る</span></a>
            </p>

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.tableData.<?= km_e($notice) ?>"></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert"><?= km_e($dbError) ?></div>
            <?php endif; ?>

            <?php if ($dbError === null): ?>
              <!--begin::Row-->
              <div class="row">
                <div class="col-12">
                  <!--begin::Card-->
                  <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                      <h3 class="card-title mb-0"><code><?= km_e($tableName) ?></code></h3>
                      <span class="text-body-secondary fs-7">
                        <span data-i18n="page.tableData.totalRows">総行数:</span> <?= (int) $totalRows ?>
                      </span>
                      <div class="card-tools ms-auto">
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#km-row-create">
                          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                          <span data-i18n="page.tableData.addRow">行を追加</span>
                        </button>
                      </div>
                    </div>
                    <!-- /.card-header -->
                    <div class="card-body p-0">
                      <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                          <thead>
                            <tr>
                              <th scope="col">id</th>
                              <?php foreach ($editableColumns as $column): ?>
                                <th scope="col"><?= km_e((string) $column['name']) ?></th>
                              <?php endforeach; ?>
                              <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if ($rows === []): ?>
                              <tr>
                                <td colspan="<?= count($editableColumns) + 2 ?>" class="text-center text-body-secondary py-5">
                                  <span data-i18n="page.tableData.empty">まだ行がありません。</span>
                                </td>
                              </tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                              <tr>
                                <td><?= km_e((string) $row['id']) ?></td>
                                <?php foreach ($editableColumns as $column): ?>
                                  <td><?= km_e((string) ($row[$column['name']] ?? '')) ?></td>
                                <?php endforeach; ?>
                                <td class="text-end text-nowrap">
                                  <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary km-row-edit-trigger"
                                    data-bs-toggle="modal"
                                    data-bs-target="#km-row-edit"
                                    data-km-row="<?= km_e((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"
                                  >
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                  </button>
                                  <form method="post" class="d-inline km-row-delete-form">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete" />
                                    <input type="hidden" name="table" value="<?= km_e($tableName) ?>" />
                                    <input type="hidden" name="id" value="<?= km_e((string) $row['id']) ?>" />
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
                    <?php if ($totalRows > KM_TABLE_DATA_PAGE_SIZE): ?>
                      <div class="card-footer d-flex justify-content-between">
                        <a
                          class="btn btn-sm btn-outline-secondary <?= $offset <= 0 ? 'disabled' : '' ?>"
                          href="?table=<?= rawurlencode($tableName) ?>&offset=<?= max(0, $offset - KM_TABLE_DATA_PAGE_SIZE) ?>"
                          data-i18n="page.tableData.prevPage"
                        >前へ</a>
                        <a
                          class="btn btn-sm btn-outline-secondary <?= $offset + KM_TABLE_DATA_PAGE_SIZE >= $totalRows ? 'disabled' : '' ?>"
                          href="?table=<?= rawurlencode($tableName) ?>&offset=<?= $offset + KM_TABLE_DATA_PAGE_SIZE ?>"
                          data-i18n="page.tableData.nextPage"
                        >次へ</a>
                      </div>
                    <?php endif; ?>
                  </div>
                  <!--end::Card-->
                </div>
              </div>
              <!--end::Row-->

              <?php
                $renderField = static function (array $column, string $idPrefix) {
                    $name = (string) $column['name'];
                    $type = km_table_data_input_type((string) $column['data_type']);
                    $inputId = $idPrefix . '-' . $name;
                    echo '<div class="mb-3">';
                    echo '<label class="form-label" for="' . km_e($inputId) . '">' . km_e($name) . '</label>';
                    if ($type === 'select') {
                        echo '<select class="form-select" id="' . km_e($inputId) . '" name="values[' . km_e($name) . ']">';
                        if ($column['is_nullable'] === 'YES') {
                            echo '<option value=""></option>';
                        }
                        echo '<option value="1">true</option><option value="0">false</option>';
                        echo '</select>';
                    } else {
                        $step = $type === 'number' ? ' step="any"' : '';
                        echo '<input class="form-control" type="' . km_e($type) . '"' . $step
                            . ' id="' . km_e($inputId) . '" name="values[' . km_e($name) . ']" />';
                    }
                    echo '</div>';
                };
              ?>

              <!--begin::Create Row Modal-->
              <div class="modal fade" id="km-row-create" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="action" value="insert" />
                      <input type="hidden" name="table" value="<?= km_e($tableName) ?>" />
                      <div class="modal-header">
                        <h5 class="modal-title" data-i18n="page.tableData.addRow">行を追加</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる" data-i18n-attr="aria-label:common.close"></button>
                      </div>
                      <div class="modal-body">
                        <?php foreach ($editableColumns as $column) {
                            $renderField($column, 'km-create');
                        } ?>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="common.cancel">キャンセル</button>
                        <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <!--end::Create Row Modal-->

              <!--begin::Edit Row Modal-->
              <div class="modal fade" id="km-row-edit" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="action" value="update" />
                      <input type="hidden" name="table" value="<?= km_e($tableName) ?>" />
                      <input type="hidden" name="id" id="km-edit-id" value="" />
                      <div class="modal-header">
                        <h5 class="modal-title" data-i18n="page.tableData.editRow">行を編集</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる" data-i18n-attr="aria-label:common.close"></button>
                      </div>
                      <div class="modal-body">
                        <?php foreach ($editableColumns as $column) {
                            $renderField($column, 'km-edit');
                        } ?>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="common.cancel">キャンセル</button>
                        <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <!--end::Edit Row Modal-->

              <script<?= km_csp_nonce_attr() ?>>
                (() => {
                  document.querySelectorAll('.km-row-delete-form').forEach((form) => {
                    form.addEventListener('submit', (event) => {
                      if (!window.confirm(window.KmI18n ? window.KmI18n.t('page.tableData.confirmDelete') : 'delete this row?')) {
                        event.preventDefault();
                      }
                    });
                  });

                  const editModal = document.getElementById('km-row-edit');
                  editModal?.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    let row = {};
                    try {
                      row = JSON.parse(button?.getAttribute('data-km-row') ?? '{}');
                    } catch {
                      row = {};
                    }
                    document.getElementById('km-edit-id').value = row.id ?? '';
                    editModal.querySelectorAll('[name^="values["]').forEach((field) => {
                      const match = /^values\[(.+)\]$/.exec(field.name);
                      if (!match) return;
                      const value = row[match[1]];
                      if (field.type === 'datetime-local' && typeof value === 'string') {
                        field.value = value.replace(' ', 'T').slice(0, 16);
                      } else {
                        field.value = value ?? '';
                      }
                    });
                  });
                })();
              </script>
            <?php endif; ?>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
