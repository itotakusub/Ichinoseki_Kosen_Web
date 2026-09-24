<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/uploads.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'filemanager',
    'titleKey' => 'page.fileManager.title',
    'title' => 'ファイル管理 | KosenMap 管理',
    'h1Key' => 'page.fileManager.h1',
    'h1' => 'ファイル管理',
    'crumbs' => [
        ['key' => 'side.extra', 'text' => 'その他ページ'],
        ['key' => 'page.fileManager.h1', 'text' => 'ファイル管理'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo = km_db();

        if ($action === 'upload') {
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                throw new InvalidArgumentException('ファイルが選択されていません。');
            }
            km_upload_store($pdo, $file, (string) ($KM_USER['name'] ?? ''));
            km_admin_log_record('content', 'file.upload', (string) ($file['name'] ?? ''));
            header('Location: ./file-manager.php?uploaded=1', true, 302);
            exit;
        }
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            km_upload_delete($pdo, $id);
            km_admin_log_record('content', 'file.delete', "#{$id}");
            header('Location: ./file-manager.php?deleted=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('file-manager.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文面をそのまま出さない(lib/user-error.php)。入力の検証だけはそのまま出る
        $errors[] = km_admin_error_message($exception, 'ファイルを処理できませんでした。');
    }
}

foreach (['uploaded' => 'uploadedNotice', 'deleted' => 'deletedNotice'] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

$files = [];
$dbError = null;
try {
    $files = km_uploads_all(km_db());
} catch (Throwable $exception) {
    error_log('file-manager.php list failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

/** 拡張子からアイコンを選ぶ。表示だけの話なので当たらなければ汎用のものにする。 */
function km_file_icon(string $extension): string
{
    return match ($extension) {
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp' => 'bi-file-earmark-image',
        'pdf' => 'bi-file-earmark-pdf',
        'csv' => 'bi-file-earmark-spreadsheet',
        'zip' => 'bi-file-earmark-zip',
        'txt' => 'bi-file-earmark-text',
        default => 'bi-file-earmark',
    };
}

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
              <div class="alert alert-success" role="alert" data-i18n="page.fileManager.<?= km_e($notice) ?>"></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.fileManager.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.fileManager.notice">
                アップロードしたファイルは直接の URL では公開されず、この画面からのダウンロードでのみ
                取り出せます。保存名はランダムに付け直され、元の名前は表示にだけ使われます。
              </div>
            </div>

            <!--begin::Card-->
            <div class="card">
              <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h3 class="card-title mb-0" data-i18n="page.fileManager.cardTitle">ファイル一覧</h3>
                <form method="post" enctype="multipart/form-data" class="card-tools ms-auto d-flex flex-wrap gap-2 align-items-center">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="action" value="upload" />
                  <input type="file" name="file" class="form-control form-control-sm km-w-16" required />
                  <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>
                    <span data-i18n="page.fileManager.upload">アップロード</span>
                  </button>
                </form>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col" data-i18n="page.fileManager.colName">ファイル名</th>
                        <th scope="col" data-i18n="page.fileManager.colSize">サイズ</th>
                        <th scope="col" data-i18n="page.fileManager.colUploaded">アップロード</th>
                        <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($files === [] && $dbError === null): ?>
                        <tr>
                          <td colspan="4" class="text-center text-body-secondary py-5">
                            <i class="bi bi-folder2-open fs-2 d-block mb-2" aria-hidden="true"></i>
                            <span data-i18n="page.fileManager.empty">まだファイルがありません。</span>
                          </td>
                        </tr>
                      <?php endif; ?>
                      <?php foreach ($files as $file): ?>
                        <tr>
                          <td>
                            <i class="bi <?= km_e(km_file_icon((string) $file['extension'])) ?> me-2" aria-hidden="true"></i>
                            <?php // 元のファイル名は利用者由来なのでエスケープして表示するだけ ?>
                            <?= km_e((string) $file['originalName']) ?>
                          </td>
                          <td><?= km_e(km_upload_format_size((int) $file['sizeBytes'])) ?></td>
                          <td class="fs-7 text-body-secondary">
                            <?= km_e(date('Y-m-d H:i', (int) $file['createdAtEpoch'])) ?>
                            <?php if (($file['uploadedBy'] ?? '') !== ''): ?>
                              / <?= km_e((string) $file['uploadedBy']) ?>
                            <?php endif; ?>
                          </td>
                          <td class="text-end text-nowrap">
                            <a href="./api/file-download.php?id=<?= (int) $file['id'] ?>" class="btn btn-sm btn-outline-secondary">
                              <i class="bi bi-download me-1" aria-hidden="true"></i>
                              <span data-i18n="common.download">ダウンロード</span>
                            </a>
                            <form method="post" class="d-inline km-file-delete-form">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="action" value="delete" />
                              <input type="hidden" name="id" value="<?= (int) $file['id'] ?>" />
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
              <div class="card-footer">
                <span class="text-body-secondary fs-7" data-i18n="page.fileManager.limits">
                  許可: svg / png / jpg / jpeg / gif / webp / pdf / txt / csv / zip、1ファイル 10 MB まで。
                </span>
              </div>
            </div>
            <!--end::Card-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            document.querySelectorAll('.km-file-delete-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                if (!window.confirm(window.KmI18n ? window.KmI18n.t('page.fileManager.confirmDelete') : 'delete?')) {
                  event.preventDefault();
                }
              });
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
