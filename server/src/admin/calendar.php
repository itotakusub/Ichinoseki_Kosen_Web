<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/events.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'calendar',
    'titleKey' => 'page.calendar.title',
    'title' => 'カレンダー | KosenMap 管理',
    'h1Key' => 'page.calendar.h1',
    'h1' => 'カレンダー',
    'crumbs' => [
        ['key' => 'side.extra', 'text' => 'その他ページ'],
        ['key' => 'page.calendar.h1', 'text' => 'カレンダー'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 編集フォームの「削除」ボタンは同じフォームから送られてくる。action を二重に
    // 送って最後勝ちに頼るのは分かりにくいので、専用のキーで意図を表す。
    $action = isset($_POST['do_delete']) ? 'delete' : (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $date = (string) ($_POST['event_date'] ?? '');
    $label = (string) ($_POST['label'] ?? '');
    $badge = (string) ($_POST['badge_class'] ?? '');
    $backTo = substr((string) ($_POST['ym'] ?? ''), 0, 7);

    try {
        $pdo = km_db();

        if ($action === 'create') {
            km_events_create($pdo, $date, $label, $badge);
            km_admin_log_record('content', 'event.create', $date . ' ' . $label);
            header('Location: ./calendar.php?ym=' . rawurlencode($backTo) . '&created=1', true, 302);
            exit;
        }
        if ($action === 'update') {
            km_events_update($pdo, $id, $date, $label, $badge);
            km_admin_log_record('content', 'event.update', $date . ' ' . $label);
            header('Location: ./calendar.php?ym=' . rawurlencode($backTo) . '&updated=1', true, 302);
            exit;
        }
        if ($action === 'delete') {
            km_events_delete($pdo, $id);
            km_admin_log_record('content', 'event.delete', "#{$id}");
            header('Location: ./calendar.php?ym=' . rawurlencode($backTo) . '&deleted=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('calendar.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文面をそのまま出さない(lib/user-error.php)。入力の検証だけはそのまま出る
        $errors[] = km_admin_error_message($exception, '保存できませんでした。');
    }
}

foreach (['created' => 'createdNotice', 'updated' => 'updatedNotice', 'deleted' => 'deletedNotice'] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

/*
 * 表示する月。?ym=YYYY-MM で移動する(以前は今月しか出せなかった)。
 * 不正な値は今月に落とす。
 */
$requestedYm = (string) ($_GET['ym'] ?? '');
$base = preg_match('/^\d{4}-\d{2}$/', $requestedYm) === 1
    ? DateTimeImmutable::createFromFormat('Y-m-d', $requestedYm . '-01')
    : false;
if (!$base instanceof DateTimeImmutable) {
    $base = new DateTimeImmutable('first day of this month');
}

$firstOfMonth = $base->setDate((int) $base->format('Y'), (int) $base->format('n'), 1);
$yearMonth = $firstOfMonth->format('Y-m');
$daysInMonth = (int) $firstOfMonth->format('t');
$startWeekday = (int) $firstOfMonth->format('w');
$prevYm = $firstOfMonth->modify('-1 month')->format('Y-m');
$nextYm = $firstOfMonth->modify('+1 month')->format('Y-m');

// 「今日」の強調は、表示中の月が実際に今月のときだけ
$today = new DateTimeImmutable('today');
$todayNum = $today->format('Y-m') === $yearMonth ? (int) $today->format('j') : 0;

$events = [];
$dbError = null;
try {
    $events = km_events_for_month(km_db(), $yearMonth);
} catch (Throwable $exception) {
    error_log('calendar.php list failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

$weekdayLabels = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

$badgeLabels = [
    'text-bg-info' => ['key' => 'page.calendar.badgeInfo', 'text' => '青(情報)'],
    'text-bg-warning' => ['key' => 'page.calendar.badgeWarning', 'text' => '黄(注意)'],
    'text-bg-danger' => ['key' => 'page.calendar.badgeDanger', 'text' => '赤(重要)'],
    'text-bg-success' => ['key' => 'page.calendar.badgeSuccess', 'text' => '緑(完了)'],
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
              <div class="alert alert-success" role="alert" data-i18n="page.calendar.<?= km_e($notice) ?>"></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.calendar.dbError">
                データベースに接続できないため、予定を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.calendar.notice">
                外部のカレンダーライブラリを使わず PHP だけで描いています。予定はクリックで編集でき、
                同じ日に複数入れられます。
              </div>
            </div>

            <!--begin::Card-->
            <div class="card">
              <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <a href="?ym=<?= km_e($prevYm) ?>" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </a>
                <h3 class="card-title mb-0"><?= km_e($firstOfMonth->format('Y') . ' / ' . $firstOfMonth->format('n')) ?></h3>
                <a href="?ym=<?= km_e($nextYm) ?>" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
                <a href="./calendar.php" class="btn btn-sm btn-outline-secondary" data-i18n="page.calendar.thisMonth">今月</a>
                <button type="button" class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#km-event-create">
                  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                  <span data-i18n="page.calendar.add">予定を追加</span>
                </button>
              </div>
              <!-- /.card-header -->
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-bordered mb-0 text-center">
                    <thead>
                      <tr>
                        <?php foreach ($weekdayLabels as $i => $wd): ?>
                          <th
                            scope="col"
                            class="<?= $i === 0 ? 'text-danger' : ($i === 6 ? 'text-primary' : '') ?>"
                            data-i18n="common.weekday.<?= $wd ?>"
                          ><?= km_e(['日', '月', '火', '水', '木', '金', '土'][$i]) ?></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                      $day = 1 - $startWeekday;
                      while ($day <= $daysInMonth):
                      ?>
                        <tr>
                          <?php for ($w = 0; $w < 7; $w++, $day++): ?>
                            <td class="km-cal-cell <?= $day === $todayNum ? 'bg-body-secondary' : '' ?>">
                              <?php if ($day >= 1 && $day <= $daysInMonth): ?>
                                <div class="fw-<?= $day === $todayNum ? 'bold' : 'normal' ?>"><?= $day ?></div>
                                <?php foreach ($events[$day] ?? [] as $event): ?>
                                  <?php // 予定の文言は利用者が入れた自由文なので data-i18n は付けない ?>
                                  <button
                                    type="button"
                                    class="badge <?= km_e((string) $event['badgeClass']) ?> d-block mt-1 text-wrap w-100 border-0 km-event-edit"
                                    data-bs-toggle="modal"
                                    data-bs-target="#km-event-edit"
                                    data-km-event="<?= km_e((string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"
                                  ><?= km_e((string) $event['label']) ?></button>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </td>
                          <?php endfor; ?>
                        </tr>
                      <?php endwhile; ?>
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
          // 追加と編集でほぼ同じフォームなので、1つの無名関数から2回出す(projects.php と同じ作り)。
          $eventForm = static function (string $id, string $action, string $titleKey, string $titleText)
              use ($badgeLabels, $yearMonth, $firstOfMonth) {
              ?>
              <div class="modal fade" id="<?= km_e($id) ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="action" value="<?= km_e($action) ?>" />
                      <input type="hidden" name="ym" value="<?= km_e($yearMonth) ?>" />
                      <?php if ($action === 'update'): ?>
                        <input type="hidden" name="id" id="km-event-edit-id" value="" />
                      <?php endif; ?>
                      <div class="modal-header">
                        <h5 class="modal-title" data-i18n="<?= km_e($titleKey) ?>"><?= km_e($titleText) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる" data-i18n-attr="aria-label:common.close"></button>
                      </div>
                      <div class="modal-body">
                        <div class="mb-3">
                          <label class="form-label" for="<?= km_e($id) ?>-date" data-i18n="page.calendar.fieldDate">日付</label>
                          <input type="date" class="form-control" id="<?= km_e($id) ?>-date" name="event_date"
                                 value="<?= km_e($firstOfMonth->format('Y-m-d')) ?>" required />
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="<?= km_e($id) ?>-label" data-i18n="page.calendar.fieldLabel">予定</label>
                          <input type="text" class="form-control" id="<?= km_e($id) ?>-label" name="label" maxlength="255" required />
                        </div>
                        <div class="mb-0">
                          <label class="form-label" for="<?= km_e($id) ?>-badge" data-i18n="page.calendar.fieldBadge">色</label>
                          <select class="form-select" id="<?= km_e($id) ?>-badge" name="badge_class">
                            <?php foreach ($badgeLabels as $value => $label): ?>
                              <option value="<?= km_e($value) ?>" data-i18n="<?= km_e($label['key']) ?>"><?= km_e($label['text']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <?php if ($action === 'update'): ?>
<?php // formnovalidate が無いと、必須項目の HTML5 検証に引っかかって削除できないことがある ?>
                          <button type="submit" name="do_delete" value="1" formnovalidate class="btn btn-outline-danger me-auto km-event-delete" data-i18n="common.delete">削除</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="common.cancel">キャンセル</button>
                        <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <?php
          };

          $eventForm('km-event-create', 'create', 'page.calendar.add', '予定を追加');
          $eventForm('km-event-edit', 'update', 'page.calendar.edit', '予定を編集');
        ?>

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            const editModal = document.getElementById('km-event-edit');
            editModal?.addEventListener('show.bs.modal', (event) => {
              let ev = {};
              try {
                ev = JSON.parse(event.relatedTarget?.getAttribute('data-km-event') ?? '{}');
              } catch {
                ev = {};
              }
              document.getElementById('km-event-edit-id').value = ev.id ?? '';
              document.getElementById('km-event-edit-date').value = ev.date ?? '';
              document.getElementById('km-event-edit-label').value = ev.label ?? '';
              document.getElementById('km-event-edit-badge').value = ev.badgeClass ?? 'text-bg-info';
            });

            // 削除ボタンは同じフォームの submit なので、確認を挟む
            document.querySelector('.km-event-delete')?.addEventListener('click', (event) => {
              if (!window.confirm(window.KmI18n ? window.KmI18n.t('page.calendar.confirmDelete') : 'delete?')) {
                event.preventDefault();
              }
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
