<?php

declare(strict_types=1);

/**
 * AR の撮影(2026-10-06、lib/ar-capture.php)。管理アプリの「AR 実測」で置いた記録と画像を、撮影ごとに一覧・zip で落とす・消す。
 *
 * **画像には人が写りうる**(利用者の決定: PC でぼかす)。zip は PC で scripts/ar-blur-faces.ps1 を通してから使い、
 * 処理が済んだらここで消す。一般のアプリと地図の配信には載らない。
 */

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/ar-capture.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'arCaptures',
    'titleKey' => 'page.arCaptures.title',
    'title' => 'AR の撮影 | KosenMap 管理',
    'h1Key' => 'page.arCaptures.h1',
    'h1' => 'AR の撮影',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.arCaptures.h1', 'text' => 'AR の撮影'],
    ],
];

$errors = [];
$notice = null;

// ---- zip を落とす(GET。画面は出さない) ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['download'])) {
    $session = strtolower((string) $_GET['download']);
    try {
        $pdo = km_db();
        $entries = km_ar_session_zip_entries($pdo, $session);
        $row = null;
        foreach (km_ar_list_sessions($pdo) as $candidate) {
            if ($candidate['uuid'] === $session) {
                $row = $candidate;
                break;
            }
        }
        $name = sprintf('ar-%s-%s-%s.zip', date('Ymd-Hi', intdiv((int) ($row['startedAtMillis'] ?? 0), 1000)), $row['floor'] ?? 'x', substr($session, 0, 8));
        km_admin_log_record('content', 'ar.capture_download', $session);
        // nginx の待ち(default.conf.template の /admin/ar-captures.php。900 秒)より短く
        @set_time_limit(840);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        km_zip_stream_stored($entries, static function (string $bytes): void {
            echo $bytes;
            flush();
        });
        exit;
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('ar-captures.php download failed: ' . $exception->getMessage());
        if (headers_sent()) {
            exit; // 途中まで送った zip は壊れている。PC 側の展開で気づく
        }
        $errors[] = km_admin_error_message($exception, 'zip を作れませんでした。');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $session = strtolower((string) ($_POST['session'] ?? ''));
    try {
        $count = km_ar_delete_session(km_db(), $session);
        if ($count === null) {
            $errors[] = 'その撮影はありません。';
        } else {
            km_admin_log_record('content', 'ar.capture_delete', $session . ' / ' . $count);
            header('Location: ./ar-captures.php?deleted=' . $count, true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('ar-captures.php delete failed: ' . $exception->getMessage());
        $errors[] = km_admin_error_message($exception, '消せませんでした。');
    }
}
if (isset($_GET['deleted'])) {
    $notice = 'deletedNotice';
}

$sessions = [];
$totalBytes = 0;
$dbError = null;
try {
    $pdo = km_db();
    $sessions = km_ar_list_sessions($pdo);
    $totalBytes = km_ar_total_bytes($pdo);
} catch (Throwable $exception) {
    error_log('ar-captures.php list failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}
$mb = static fn (int $bytes): string => number_format($bytes / 1048576, 1) . ' MB';

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
              <div class="alert alert-success" role="alert" data-i18n="page.arCaptures.<?= km_e($notice) ?>">消しました。</div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.arCaptures.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-warning d-flex align-items-start" role="alert">
              <i class="bi bi-person-bounding-box me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.arCaptures.privacy">
                画像には人が写っていることがあります(サーバーには元の画像のまま置いています)。
                zip を落としたら、PC で scripts\ar-blur-faces.ps1 を通して顔をぼかしてから使ってください。
                3D・疑似ストリートビューの処理が済んだら、ここで消してください。一般のアプリと地図の配信には載りません。
              </div>
            </div>

            <div class="card">
              <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h3 class="card-title mb-0" data-i18n="page.arCaptures.listTitle">撮影の一覧</h3>
                <span class="ms-auto text-body-secondary fs-7">
                  <span data-i18n="page.arCaptures.usage">使っている容量</span>:
                  <?= km_e($mb($totalBytes)) ?> / <?= km_e($mb(KM_AR_TOTAL_MAX_BYTES)) ?>
                </span>
              </div>
              <div class="card-body p-0">
                <?php if ($sessions === [] && $dbError === null): ?>
                  <p class="text-center text-body-secondary py-5 mb-0" data-i18n="page.arCaptures.empty">
                    まだありません。管理アプリの地図で AR 測位モードを ON にし、「実測」から撮影してください。
                  </p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                      <thead>
                        <tr>
                          <th data-i18n="page.arCaptures.colStarted">撮った日時</th>
                          <th data-i18n="page.arCaptures.colFloor">階</th>
                          <th class="text-end" data-i18n="page.arCaptures.colMarks">印</th>
                          <th class="text-end" data-i18n="page.arCaptures.colFrames">画像</th>
                          <th class="text-end" data-i18n="page.arCaptures.colDepth">深度</th>
                          <th class="text-end" data-i18n="page.arCaptures.colSize">大きさ</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($sessions as $row): ?>
                          <tr>
                            <td><?= km_e(date('Y-m-d H:i', intdiv($row['startedAtMillis'], 1000))) ?></td>
                            <td><?= km_e($row['floor']) ?></td>
                            <td class="text-end"><?= (int) $row['marks'] ?></td>
                            <td class="text-end"><?= (int) $row['frames'] ?></td>
                            <td class="text-end"><?= (int) $row['depthFrames'] ?></td>
                            <td class="text-end"><?= km_e($mb($row['bytes'])) ?></td>
                            <td class="text-end text-nowrap">
                              <a class="btn btn-sm btn-outline-primary" href="./ar-captures.php?download=<?= km_e(rawurlencode($row['uuid'])) ?>">
                                <i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i>
                                <span data-i18n="page.arCaptures.download">zip を落とす</span>
                              </a>
                              <form method="post" class="d-inline km-ar-delete-form">
                                <?= km_csrf_field() ?>
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="session" value="<?= km_e($row['uuid']) ?>" />
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                  <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                  <span data-i18n="page.arCaptures.delete">消す</span>
                                </button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            const t = (key, fallback) => (window.KmI18n ? window.KmI18n.t(key) : fallback);
            document.querySelectorAll('.km-ar-delete-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                if (!window.confirm(t('page.arCaptures.confirmDelete', 'delete?'))) {
                  event.preventDefault();
                }
              });
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
