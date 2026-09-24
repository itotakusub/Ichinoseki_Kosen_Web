<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/map-events.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * イベントモードの管理。
 *
 * 地図に一時的な変更(通行止め・臨時の地点・臨時名称)を重ねるための入れ物を作る。
 * 中身の編集は admin/map-editor.php の「イベント編集」モードで行う。
 *
 * **ここで一番大事なのは「戻し忘れ」を見つけられること。**
 * 終了予定を過ぎているのに有効なままのイベントは、一覧の先頭で警告する。
 */

$errors = [];
$notice = null;
$dbError = null;
$events = [];
$isolation = null;
$cleanupMode = 'manual';
$expiredForCleanup = [];

try {
    $pdo = km_db();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        // 副作用を起こす前に弾く
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;

        try {
            if ($formAction === 'cleanup_mode') {
                km_map_event_cleanup_mode_save($pdo, (string) ($_POST['cleanup_mode'] ?? ''));
                km_admin_log_record(
                    'settings',
                    'map.event_cleanup_mode',
                    (string) ($_POST['cleanup_mode'] ?? '')
                );
                $notice = ($_POST['cleanup_mode'] ?? '') === 'auto'
                    ? '期限切れのイベントを自動で削除します。'
                        . '終了から' . KM_EVENT_CLEANUP_GRACE_DAYS . '日たったものが対象です。'
                    : '期限切れのイベントは自動で削除しません。このページから手動で消してください。';
            } elseif ($formAction === 'cleanup_now') {
                // **手動でもここを通す。** 自動と同じ関数を使うので、
                // 「手動だと消え方が違う」という食い違いが起きない。
                $removed = km_map_events_delete_expired($pdo);
                km_admin_log_record(
                    'content',
                    'map.event_cleanup',
                    $removed === [] ? '対象なし' : implode(' / ', $removed)
                );
                $notice = $removed === []
                    ? '削除の対象はありませんでした。'
                    : count($removed) . '件のイベントを削除しました: ' . implode('、', $removed);
            } elseif ($formAction === 'delete' && $id !== null) {
                km_map_event_delete($pdo, $id);
                km_admin_log_record('content', 'map.event_delete', "#{$id}");
                $notice = 'イベントを削除しました。重ね合わせも一緒に消えています。';
            } elseif ($formAction === 'toggle' && $id !== null) {
                $current = km_map_event_find($pdo, $id);
                if ($current === null) {
                    $errors[] = 'そのイベントは見つかりませんでした。';
                } else {
                    $current['isEnabled'] = !$current['isEnabled'];
                    km_map_event_save($pdo, $id, $current);
                    km_admin_log_record(
                        'content',
                        'map.event_toggle',
                        ($current['isEnabled'] ? '有効化 ' : '無効化 ') . $current['name']
                    );
                    $notice = $current['isEnabled']
                        ? 'イベントを有効にしました。期間内であれば、いま地図に反映されています。'
                        : 'イベントを無効にしました。地図は元に戻っています。';
                }
            } else {
                $savedId = km_map_event_save($pdo, $id, [
                    'name' => (string) ($_POST['name'] ?? ''),
                    'startsAt' => (string) ($_POST['starts_at'] ?? ''),
                    'endsAt' => (string) ($_POST['ends_at'] ?? ''),
                    'isEnabled' => isset($_POST['is_enabled']),
                    'hideOccupantNames' => isset($_POST['hide_occupant_names']),
                    'bannerText' => (string) ($_POST['banner_text'] ?? ''),
                    'bannerUrl' => (string) ($_POST['banner_url'] ?? ''),
                ]);
                km_admin_log_record(
                    'content',
                    $id === null ? 'map.event_create' : 'map.event_update',
                    (string) ($_POST['name'] ?? '')
                );
                $notice = $id === null
                    ? "イベントを作成しました。通行止めや臨時の地点は「地図編集」から設定してください。"
                    : 'イベントを更新しました。';
            }
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            // 理由をそのまま出す(admin/map-settings.php と同じ判断)。
            // ここは guard.php を通った後なので、見えるのは管理者だけ。
            // → 2026-09-14 に改めた: 管理画面でも DB の文面は出さず照合用 ID にする
            //   (lib/user-error.php。入力の検証は上の catch がそのまま出す)
            error_log('map-events.php save failed: ' . $exception->getMessage());
            $errors[] = km_admin_error_message($exception, '保存できませんでした。');
        }
    }

    $cleanupMode = km_map_event_cleanup_mode($pdo);

    /*
     * 自動掃除はここで走る。
     *
     * **この構成に cron は無い。** web コンテナは HTTP を受けたときだけ動くので、
     * 「時間が来たら消える」ようには作れない。**管理画面のこのページを開いたときに掃除する。**
     *
     * 公開ページからは走らせない —— 来場者のリクエストで管理データを消す経路を作らない。
     * そのぶん「誰も管理画面を開かなければ残り続ける」が、
     * 残っていても期限切れのイベントは地図に反映されない(KM_MAP_EVENT_ACTIVE_SQL)ので実害が無い。
     */
    if ($cleanupMode === 'auto' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $swept = km_map_events_delete_expired($pdo);
        if ($swept !== []) {
            km_admin_log_record('content', 'map.event_cleanup_auto', implode(' / ', $swept));
            $notice = '期限切れの' . count($swept) . '件を自動で削除しました: ' . implode('、', $swept);
        }
    }

    $expiredForCleanup = km_map_events_expired($pdo);
    $events = km_map_events_all($pdo);

    // いま重ねている内容で、到達できなくなる場所がどれだけあるか
    $overlay = km_map_event_overlay($pdo);
    if ($overlay['closedEdges'] !== [] || $overlay['closedNodes'] !== []) {
        $isolation = km_map_event_isolation_count($pdo, $overlay);
    }
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$editing = null;
if (isset($_GET['edit']) && $dbError === null) {
    $editing = km_map_event_find($pdo, (int) $_GET['edit']);
}

$KM_PAGE = [
    'nav' => 'mapEvents',
    'titleKey' => 'page.mapEvents.title',
    'title' => 'イベントモード | KosenMap 管理',
    'h1Key' => 'page.mapEvents.h1',
    'h1' => 'イベントモード',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.mapEvents.h1', 'text' => 'イベントモード'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';

$fmt = static fn (?string $value): string => $value === null || $value === ''
    ? '—'
    : str_replace(' ', ' ', substr($value, 0, 16));
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.mapEvents.dbError">
                データベースに接続できません。イベント用のテーブルは初回アクセス時に自動で作られるので、接続そのものを確認してください。
              </div>
            <?php endif; ?>

            <?php
              // **戻し忘れの警告。** 一覧より前に出す
              $expired = array_filter($events, static fn (array $e): bool => $e['isExpired']);
            ?>
            <?php if ($expired !== []): ?>
              <div class="alert alert-warning" role="alert">
                <strong data-i18n="page.mapEvents.expiredTitle">終了予定を過ぎたイベントが有効なままです。</strong>
                <div class="fs-7" data-i18n="page.mapEvents.expiredHint">
                  期間を過ぎているので地図には反映されていませんが、無効にしておくと状態が分かりやすくなります。
                </div>
              </div>
            <?php endif; ?>

            <?php if ($isolation !== null && $isolation['isolated'] > 0): ?>
              <div class="alert alert-danger" role="alert">
                <strong>
                  いまの通行止めで、<?= (int) $isolation['isolated'] ?> 件の場所へ到達できません
                  (全 <?= (int) $isolation['total'] ?> 件)。
                </strong>
                <div class="fs-7" data-i18n="page.mapEvents.isolatedHint">
                  工事などで実際に閉鎖しているなら問題ありません。意図しない場合は通行止めを見直してください。
                </div>
              </div>
            <?php endif; ?>

            <?php if ($dbError === null): ?>
            <div class="card mb-4">
              <div class="card-header">
                <h3 class="card-title" data-i18n="page.mapEvents.cleanupTitle">期限切れの掃除</h3>
              </div>
              <div class="card-body">
                <p class="text-body-secondary fs-7" data-i18n="page.mapEvents.cleanupHint">
                  終了時刻を過ぎたイベントの扱いを選びます。削除すると、そのイベントの通行止め・臨時の地点・臨時名称も一緒に消えます。戻せません。
                </p>
                <form method="post" class="row g-3 align-items-end">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="form_action" value="cleanup_mode">
                  <div class="col-12 col-md-auto">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="cleanup_mode" id="cleanup-manual"
                             value="manual" <?= $cleanupMode === 'manual' ? 'checked' : '' ?>>
                      <label class="form-check-label" for="cleanup-manual"
                             data-i18n="page.mapEvents.cleanupManual">
                        手動で削除する
                      </label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="cleanup_mode" id="cleanup-auto"
                             value="auto" <?= $cleanupMode === 'auto' ? 'checked' : '' ?>>
                      <label class="form-check-label" for="cleanup-auto">
                        <span data-i18n="page.mapEvents.cleanupAuto">自動で削除する</span>
                        <span class="text-body-secondary">
                          （終了から <?= (int) KM_EVENT_CLEANUP_GRACE_DAYS ?> 日後）
                        </span>
                      </label>
                    </div>
                  </div>
                  <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                  </div>
                </form>

                <?php
                /*
                 * 猶予の説明と、いま対象になっているものを出す。
                 * **消える前に名前が見えること**が、打ち間違いに気づける唯一の機会。
                 */
                ?>
                <hr>
                <?php if ($expiredForCleanup === []): ?>
                  <p class="mb-0 text-body-secondary fs-7" data-i18n="page.mapEvents.cleanupNone">
                    いま削除の対象になっているイベントはありません。
                  </p>
                <?php else: ?>
                  <p class="mb-2 fs-7">
                    <strong><?= count($expiredForCleanup) ?> 件</strong>が対象です（終了から
                    <?= (int) KM_EVENT_CLEANUP_GRACE_DAYS ?> 日以上）:
                  </p>
                  <ul class="fs-7">
                    <?php foreach ($expiredForCleanup as $target): ?>
                      <li><?= km_e($target['name']) ?>
                        <span class="text-body-secondary">（<?= km_e($fmt($target['endsAt'])) ?> 終了）</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                  <?php // 確認は partials/scripts.php が data-km-confirm を見て出す(onsubmit= は CSP で動かない) ?>
                  <form method="post" data-km-confirm="対象のイベントを削除します。重ね合わせも消えます。よろしいですか?">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="cleanup_now">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            data-i18n="page.mapEvents.cleanupNow">
                      いま削除する
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <div class="row">
              <!-- ------------------------------------------------ 一覧 -->
              <div class="col-12 col-xl-7">
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.mapEvents.listTitle">イベント一覧</h3>
                  </div>
                  <div class="card-body p-0">
                    <?php if ($events === []): ?>
                      <p class="p-3 mb-0 text-body-secondary" data-i18n="page.mapEvents.empty">
                        まだイベントがありません。右のフォームから作成してください。
                      </p>
                    <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th data-i18n="page.mapEvents.colName">イベント</th>
                            <th data-i18n="page.mapEvents.colPeriod">期間</th>
                            <th class="text-end" data-i18n="page.mapEvents.colContent">内容</th>
                            <th class="text-end" data-i18n="page.mapEvents.colState">状態</th>
                            <th class="text-end"></th>
                          </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                          <tr<?= $event['isExpired'] ? ' class="table-warning"' : '' ?>>
                            <td>
                              <div class="fw-semibold"><?= km_e($event['name']) ?></div>
                              <?php if ($event['hideOccupantNames']): ?>
                                <span class="badge text-bg-secondary fs-8" data-i18n="page.mapEvents.namesHidden">氏名を隠す</span>
                              <?php endif; ?>
                            </td>
                            <td class="fs-7">
                              <?= km_e($fmt($event['startsAt'])) ?><br>
                              〜 <?= km_e($fmt($event['endsAt'])) ?>
                            </td>
                            <td class="text-end fs-7">
                              通行止め <?= $event['closureCount'] ?><br>
                              地点 <?= $event['poiCount'] ?> / 別名 <?= $event['aliasCount'] ?>
                            </td>
                            <td class="text-end">
                              <?php if ($event['isActive']): ?>
                                <span class="badge text-bg-success" data-i18n="page.mapEvents.stateActive">反映中</span>
                              <?php elseif ($event['isExpired']): ?>
                                <span class="badge text-bg-warning" data-i18n="page.mapEvents.stateExpired">期間切れ</span>
                              <?php elseif ($event['isEnabled']): ?>
                                <span class="badge text-bg-info" data-i18n="page.mapEvents.stateWaiting">開始待ち</span>
                              <?php else: ?>
                                <span class="badge text-bg-secondary" data-i18n="page.mapEvents.stateOff">無効</span>
                              <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                              <a class="btn btn-sm btn-outline-secondary"
                                 href="./map-events.php?edit=<?= $event['id'] ?>"
                                 data-i18n="page.mapEvents.edit">編集</a>
                              <a class="btn btn-sm btn-outline-primary"
                                 href="./map-editor.php?event=<?= $event['id'] ?>"
                                 data-i18n="page.mapEvents.editMap">地図</a>
                              <form method="post" class="d-inline">
                                <?= km_csrf_field() ?>
                                <input type="hidden" name="form_action" value="toggle">
                                <input type="hidden" name="id" value="<?= $event['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-<?= $event['isEnabled'] ? 'warning' : 'success' ?>">
                                  <?= $event['isEnabled'] ? '無効化' : '有効化' ?>
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

              <!-- ------------------------------------------------ 作成・編集 -->
              <div class="col-12 col-xl-5">
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title">
                      <?= $editing === null ? '新しいイベント' : 'イベントを編集' ?>
                    </h3>
                  </div>
                  <div class="card-body">
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <?php if ($editing !== null): ?>
                        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                      <?php endif; ?>

                      <div class="mb-3">
                        <label class="form-label" for="km-event-name" data-i18n="page.mapEvents.fieldName">イベント名</label>
                        <input class="form-control" id="km-event-name" name="name" maxlength="100" required
                               value="<?= km_e($editing['name'] ?? '') ?>"
                               placeholder="高専祭 2026">
                      </div>

                      <div class="row">
                        <div class="col-6 mb-3">
                          <label class="form-label" for="km-event-start" data-i18n="page.mapEvents.fieldStart">開始日時</label>
                          <input type="datetime-local" class="form-control" id="km-event-start" name="starts_at"
                                 value="<?= km_e(str_replace(' ', 'T', substr((string) ($editing['startsAt'] ?? ''), 0, 16))) ?>">
                        </div>
                        <div class="col-6 mb-3">
                          <label class="form-label" for="km-event-end" data-i18n="page.mapEvents.fieldEnd">終了日時</label>
                          <input type="datetime-local" class="form-control" id="km-event-end" name="ends_at"
                                 value="<?= km_e(str_replace(' ', 'T', substr((string) ($editing['endsAt'] ?? ''), 0, 16))) ?>">
                        </div>
                      </div>
                      <div class="text-body-secondary fs-7 mb-3" data-i18n="page.mapEvents.periodHint">
                        空欄にすると期限なし。**終了日時を入れておくと、過ぎた時点で自動的に元へ戻ります。**
                      </div>

                      <div class="mb-3">
                        <label class="form-label" for="km-event-banner" data-i18n="page.mapEvents.fieldBanner">案内の文言</label>
                        <input class="form-control" id="km-event-banner" name="banner_text" maxlength="255"
                               value="<?= km_e($editing['bannerText'] ?? '') ?>"
                               placeholder="高専祭 開催中！順路にご協力ください">
                        <div class="text-body-secondary fs-7" data-i18n="page.mapEvents.bannerHint">
                          公開ページの上部に出ます。空欄ならイベント名を出します。
                        </div>
                      </div>

                      <div class="mb-3">
                        <label class="form-label" for="km-event-url" data-i18n="page.mapEvents.fieldUrl">案内のリンク</label>
                        <input class="form-control" id="km-event-url" name="banner_url" maxlength="255"
                               value="<?= km_e($editing['bannerUrl'] ?? '') ?>"
                               placeholder="/faq.php">
                        <div class="text-body-secondary fs-7" data-i18n="page.mapEvents.urlHint">
                          このサイト内のパスだけ(/ で始まるもの)。外部サイトは指定できません。
                        </div>
                      </div>

                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="km-event-hide" name="hide_occupant_names"
                               <?= ($editing === null || $editing['hideOccupantNames']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-event-hide">
                          <strong data-i18n="page.mapEvents.fieldHideNames">期間中は教職員氏名を隠す</strong>
                          <div class="text-body-secondary fs-7" data-i18n="page.mapEvents.hideNamesHint">
                            外部の来場者が地図を見るため、解除パスワードを知っている人にも表示しません。
                          </div>
                        </label>
                      </div>

                      <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="km-event-enabled" name="is_enabled"
                               <?= ($editing !== null && $editing['isEnabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-event-enabled">
                          <strong data-i18n="page.mapEvents.fieldEnabled">有効にする</strong>
                          <div class="text-body-secondary fs-7" data-i18n="page.mapEvents.enabledHint">
                            期間内であれば、すぐに公開ページへ反映されます。
                          </div>
                        </label>
                      </div>

                      <button type="submit" class="btn btn-primary" data-i18n="page.mapEvents.save">保存</button>
                      <?php if ($editing !== null): ?>
                        <a class="btn btn-outline-secondary" href="./map-events.php" data-i18n="page.mapEvents.cancel">やめる</a>
                      <?php endif; ?>
                    </form>

                    <?php if ($editing !== null): ?>
                      <hr>
                      <form method="post">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="form_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                id="km-event-delete" data-i18n="page.mapEvents.delete">
                          このイベントを削除
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <?php /* インラインの onclick は CSP に弾かれる。nonce 付きの script から登録する */ ?>
        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            'use strict';
            const t = (key, fallback) => (window.KmI18n ? window.KmI18n.t(key) : fallback) || fallback;
            const del = document.getElementById('km-event-delete');
            if (!del) return;
            del.addEventListener('click', (event) => {
              const message = t(
                'page.mapEvents.confirmDelete',
                'このイベントと、その通行止め・臨時の地点・臨時名称をすべて削除します。よろしいですか?'
              );
              if (!window.confirm(message)) event.preventDefault();
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
