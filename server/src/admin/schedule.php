<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/event-schedule.php';
require_once dirname(__DIR__) . '/lib/posters.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * スケジュール(2026-10-23、利用者の指示「Website 側でスケジュール表を作成。それを元に、どのノードで何が開催されているか表記。
 * 一般向けに表示するかはチェックマークで」。中身は lib/event-schedule.php)。
 *
 * - 1 行 = 1 つの催し。場所は**企画の番号**で決める(番号は管理アプリで地点に振る。lib/map-node-projects.php)
 * - 「一般に出す」に印の行だけが公開の地図(地点の詳細と「スケジュール」の一覧)に出る
 * - 下に「企画の番号と地点」の一覧を出す(番号を振った地点と、スケジュール・ポスターの付き方)
 */

$errors = [];
$dbError = null;
$rows = [];
$projectNodes = [];
$projectPois = [];
$nodeNames = [];
$editing = null;
$form = null;

$editId = isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int) $_GET['edit'] : null;

try {
    $pdo = km_db();
    km_event_schedule_ensure_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $targetId = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        if ($formAction === 'delete' && $targetId !== null) {
            $title = km_event_schedule_delete($pdo, $targetId);
            if ($title !== null) {
                km_admin_log_record('content', 'schedule.delete', $title);
            }
            header('Location: ./schedule.php?saved=deleted', true, 303);
            exit;
        }
        if ($formAction === 'public' && $targetId !== null) {
            $public = ($_POST['public'] ?? '') === '1';
            km_event_schedule_set_public($pdo, $targetId, $public);
            km_admin_log_record('content', 'schedule.public', '#' . $targetId . ($public ? ' 一般に出す' : ' 出さない'));
            header('Location: ./schedule.php?saved=public#schedule-list', true, 303);
            exit;
        }
        if ($formAction === 'save') {
            $form = [
                'id' => $targetId,
                'projectNo' => (string) ($_POST['project_no'] ?? ''),
                'title' => (string) ($_POST['title'] ?? ''),
                'organizer' => (string) ($_POST['organizer'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'startsAt' => (string) ($_POST['starts_at'] ?? ''),
                'endsAt' => (string) ($_POST['ends_at'] ?? ''),
                'isPublic' => isset($_POST['is_public']),
            ];
            try {
                $savedId = km_event_schedule_save($pdo, $targetId, km_event_schedule_validate($form));
                km_admin_log_record('content', $targetId === null ? 'schedule.create' : 'schedule.update', trim($form['title']));
                header('Location: ./schedule.php?saved=' . ($targetId === null ? 'created' : 'updated') . '#schedule-' . $savedId, true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
                $editId = $targetId;
            }
        }
    }

    $rows = km_event_schedule_all($pdo);
    // イベント中の名前が番号の地点も入れる(2026-10-23。公開の地図と同じ)
    $projectNodes = km_map_project_nodes(km_map_projects_for_admin($pdo));
    // 名前が番号の一時地点(臨時の地点。2026-10-23)。全部のイベントの分
    $projectPois = km_map_project_pois_all($pdo);
    foreach (km_poster_node_options($pdo) as $option) {
        $nodeNames[$option['id']] = $option['name'] . '(' . km_poster_floor_label($option['floor']) . ')';
    }
    if ($editId !== null) {
        $editing = km_event_schedule_find($pdo, $editId);
    }
} catch (Throwable $exception) {
    error_log('admin/schedule.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$notice = match ((string) ($_GET['saved'] ?? '')) {
    'created' => 'スケジュールに足しました。',
    'updated' => 'スケジュールを直しました。',
    'deleted' => 'スケジュールから消しました。',
    'public' => '一般に出すかを切り替えました。',
    default => null,
};

// 入れ直すときは、入れた値を優先する
$value = static function (string $key, string $default = '') use ($form, $editing): mixed {
    if ($form !== null) {
        return $form[$key] ?? $default;
    }
    return $editing[$key] ?? $default;
};
$datetimeValue = static fn (?string $value): string => $value === null || $value === '' ? '' : substr(str_replace(' ', 'T', $value), 0, 16);
$poiFloor = static fn (string $floor): string => $floor === 'outside' ? '外' : (preg_match('/^\d+$/', $floor) === 1 ? $floor . 'F' : $floor);
$placeLabel = static function (?string $projectNo) use ($projectNodes, $projectPois, $nodeNames, $poiFloor): string {
    if ($projectNo === null || $projectNo === '') {
        return '';
    }
    $ids = $projectNodes[$projectNo] ?? [];
    $labels = array_map(static fn (string $id): string => $nodeNames[$id] ?? $id, $ids);
    foreach ($projectPois[$projectNo] ?? [] as $poi) {
        $labels[] = '一時地点(' . $poiFloor($poi['floor']) . '・' . $poi['event'] . ')';
    }
    return implode('、', $labels);
};
// 番号の一覧は、地点と一時地点の両方から
$allNumbers = array_unique(array_merge(array_keys($projectNodes), array_keys($projectPois)));
sort($allNumbers, SORT_NATURAL);
$formatTime = static fn (string $start, ?string $end): string => substr($start, 0, 16) . ($end !== null ? ' 〜 ' . (substr($end, 0, 10) === substr($start, 0, 10) ? substr($end, 11, 5) : substr($end, 0, 16)) : '');
$isPublicChecked = $form !== null ? (bool) $form['isPublic'] : ($editing === null || $editing['isPublic']);

// 番号ごとの行の数(企画の番号の一覧で使う)
$scheduleCount = [];
foreach ($rows as $row) {
    if ($row['projectNo'] !== null) {
        $scheduleCount[$row['projectNo']] = ($scheduleCount[$row['projectNo']] ?? 0) + 1;
    }
}

$KM_PAGE = [
    'nav' => 'schedule',
    'titleKey' => 'page.schedule.title',
    'title' => 'スケジュール | KosenMap 管理',
    'h1Key' => 'page.schedule.h1',
    'h1' => 'スケジュール',
    'crumbs' => [
        ['key' => 'page.schedule.h1', 'text' => 'スケジュール'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid">

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($dbError) ?></div>
            <?php endif; ?>

            <div class="row g-4">
              <div class="col-xl-8">
                <div class="card mb-4" id="schedule-list">
                  <div class="card-header">
                    <h3 class="card-title">スケジュール(<?= count($rows) ?> 件)</h3>
                  </div>
                  <div class="card-body">
                    <p class="text-body-secondary fs-7 mb-2">
                      場所は<strong>企画の番号</strong>で決まります(番号は管理アプリで地点に振ります)。
                      「一般に出す」の行だけが、公開の地図の地点の詳細と「スケジュール」の一覧に出ます。今日より前に終わった行は公開の地図には出ません。
                    </p>
                    <?php if ($rows === []): ?>
                      <p class="mb-0">まだありません。右の欄から足してください。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead><tr><th>日時</th><th>企画</th><th>催し</th><th>場所</th><th>一般</th><th></th></tr></thead>
                          <tbody>
                            <?php foreach ($rows as $row): ?>
                              <?php $place = $placeLabel($row['projectNo']); ?>
                              <tr id="schedule-<?= (int) $row['id'] ?>"<?= $editing !== null && $editing['id'] === $row['id'] ? ' class="table-active"' : '' ?>>
                                <td class="fs-7 text-nowrap"><?= km_e($formatTime($row['startsAt'], $row['endsAt'])) ?></td>
                                <td><?= $row['projectNo'] !== null ? '<span class="badge text-bg-info">' . km_e($row['projectNo']) . '</span>' : '—' ?></td>
                                <td>
                                  <div class="fw-semibold"><?= km_e($row['title']) ?></div>
                                  <?php if ($row['organizer'] !== ''): ?><div class="fs-7 text-body-secondary"><?= km_e($row['organizer']) ?></div><?php endif; ?>
                                </td>
                                <td class="fs-7">
                                  <?php if ($row['projectNo'] === null): ?>
                                    <span class="text-body-secondary">場所なし</span>
                                  <?php elseif ($place === ''): ?>
                                    <span class="text-danger">この番号の地点が地図にありません</span>
                                  <?php else: ?>
                                    <?= km_e($place) ?>
                                  <?php endif; ?>
                                </td>
                                <td>
                                  <form method="post" class="m-0">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="public">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="public" value="<?= $row['isPublic'] ? '0' : '1' ?>">
                                    <div class="form-check form-switch m-0">
                                      <input class="form-check-input" type="checkbox" role="switch" aria-label="一般に出す" data-km-autosubmit <?= $row['isPublic'] ? 'checked' : '' ?> <?= $KM_ADMIN_CAN_WRITE ? '' : 'disabled' ?>>
                                    </div>
                                  </form>
                                </td>
                                <td class="text-nowrap">
                                  <a class="btn btn-outline-secondary btn-sm" href="./schedule.php?edit=<?= (int) $row['id'] ?>#schedule-form">直す</a>
                                  <?php if ($KM_ADMIN_CAN_WRITE): ?>
                                    <form method="post" class="d-inline" data-km-confirm="「<?= km_e($row['title']) ?>」をスケジュールから消しますか？">
                                      <?= km_csrf_field() ?>
                                      <input type="hidden" name="form_action" value="delete">
                                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                      <button type="submit" class="btn btn-outline-danger btn-sm">消す</button>
                                    </form>
                                  <?php endif; ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card mb-4" id="project-numbers">
                  <div class="card-header">
                    <h3 class="card-title">企画の番号と地点(<?= count($allNumbers) ?> 件)</h3>
                  </div>
                  <div class="card-body">
                    <p class="text-body-secondary fs-7 mb-2">
                      管理アプリで地点に振った番号と、名前が番号の一時地点(イベントの臨時の地点)です(地図・イベントを取り込むと入れ替わります)。1 つの地点に番号がいくつも、1 つの番号に地点がいくつも付けられます。
                      ポスターは「ポスター・広告」で番号を選ぶと、その番号の地点に出ます。
                    </p>
                    <?php if ($allNumbers === []): ?>
                      <p class="mb-0">まだありません。管理アプリの地点の編集で「企画の番号」を入れ、地図を取り込んでください。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead><tr><th>番号</th><th>地点</th><th>スケジュール</th></tr></thead>
                          <tbody>
                            <?php foreach ($allNumbers as $number): ?>
                              <tr>
                                <td><span class="badge text-bg-info"><?= km_e((string) $number) ?></span></td>
                                <td class="fs-7"><?= km_e($placeLabel((string) $number)) ?></td>
                                <td><?= (int) ($scheduleCount[(string) $number] ?? 0) ?> 件</td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="col-xl-4">
                <div class="card mb-4" id="schedule-form">
                  <div class="card-header">
                    <h3 class="card-title"><?= $editing !== null ? '直す' : '足す' ?></h3>
                  </div>
                  <div class="card-body">
                    <?php if (!$KM_ADMIN_CAN_WRITE): ?>
                      <p class="mb-0 text-body-secondary">読み取り専用のアカウントです。</p>
                    <?php else: ?>
                      <form method="post">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="form_action" value="save">
                        <?php if ($editing !== null): ?>
                          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                          <label class="form-label" for="km-schedule-title">催しの名前</label>
                          <input class="form-control" type="text" id="km-schedule-title" name="title" maxlength="<?= KM_SCHEDULE_TITLE_MAX ?>" required value="<?= km_e((string) $value('title')) ?>">
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="km-schedule-project">企画の番号(場所)</label>
                          <input class="form-control" type="text" id="km-schedule-project" name="project_no" maxlength="<?= KM_MAP_PROJECT_NUMBER_MAX_LENGTH ?>" placeholder="例: A-1" list="km-schedule-numbers" value="<?= km_e((string) $value('projectNo')) ?>">
                          <datalist id="km-schedule-numbers"><?php foreach ($allNumbers as $number): ?><option value="<?= km_e((string) $number) ?>"><?= km_e($placeLabel((string) $number)) ?></option><?php endforeach; ?></datalist>
                          <div class="form-text">空なら場所なし(一覧にだけ出ます)。番号の地点がいくつあれば、全部に出ます。</div>
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="km-schedule-organizer">団体(任意)</label>
                          <input class="form-control" type="text" id="km-schedule-organizer" name="organizer" maxlength="<?= KM_SCHEDULE_ORGANIZER_MAX ?>" value="<?= km_e((string) $value('organizer')) ?>">
                        </div>
                        <div class="row g-2 mb-3">
                          <div class="col-12">
                            <label class="form-label" for="km-schedule-start">始まり</label>
                            <input class="form-control" type="datetime-local" id="km-schedule-start" name="starts_at" required value="<?= km_e($datetimeValue((string) $value('startsAt'))) ?>">
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="km-schedule-end">終わり(任意)</label>
                            <input class="form-control" type="datetime-local" id="km-schedule-end" name="ends_at" value="<?= km_e($datetimeValue((string) $value('endsAt'))) ?>">
                          </div>
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="km-schedule-description">説明(任意)</label>
                          <textarea class="form-control" id="km-schedule-description" name="description" rows="3" maxlength="<?= KM_SCHEDULE_DESCRIPTION_MAX ?>"><?= km_e((string) $value('description')) ?></textarea>
                        </div>
                        <div class="form-check mb-3">
                          <input class="form-check-input" type="checkbox" id="km-schedule-public" name="is_public" value="1" <?= $isPublicChecked ? 'checked' : '' ?>>
                          <label class="form-check-label" for="km-schedule-public">
                            <strong>一般に出す</strong>
                            <div class="text-body-secondary fs-7">外すと、管理画面にだけ残ります(準備・リハーサルなど)。</div>
                          </label>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= $editing !== null ? '直す' : '足す' ?></button>
                        <?php if ($editing !== null): ?>
                          <a class="btn btn-outline-secondary" href="./schedule.php">やめる</a>
                        <?php endif; ?>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
