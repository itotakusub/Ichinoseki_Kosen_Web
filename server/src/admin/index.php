<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/logto-management.php';
require_once dirname(__DIR__) . '/lib/staff-org.php';
require_once dirname(__DIR__) . '/lib/app-map.php';

/*
 * タイルの数値。取得できないものは「—」のままにして画面自体は必ず出す
 * (admin/map-settings.php の $dbError と同じ扱い)。
 * 監視対象サービス数は services.js が data-km-services-count へ書き込むのでここでは扱わない。
 */
// 「テーブル数」のタイルは 2026-11-02 に「教職員の申請(未処理)」へ替えた(運用で見て手が動く数にする)
$staffWaiting = null;
$eventCount = null;
try {
    $pdo = km_db();
    $staffWaiting = km_staff_requests_pending_count($pdo);
    $eventCount = km_admin_log_count_since($pdo, 1);
} catch (Throwable $exception) {
    error_log('admin/index.php tiles failed: ' . $exception->getMessage());
}

// Logto は DB とは別系統なので、片方が落ちてももう片方のタイルは出るように try を分ける。
$userCount = null;
try {
    $userCount = count(km_logto_users(100));
} catch (Throwable $exception) {
    error_log('admin/index.php user tile failed: ' . $exception->getMessage());
}

/*
 * アプリへ配っている地図の期限(2026-11-02)。以前ここにあった「次フェーズの作業」(km_tasks)は
 * プロジェクト状況・かんばんと同じものだったので外し、会期の前に見落とすと困る「配信の期限」を出す。
 * 期限が切れると来場者アプリは地図を消す(lib/app-map.php の km_app_map_release_status の注記)。
 */
$mapReleases = null;
try {
    $mapConfig = km_app_map_config();
    $mapReleases = km_app_map_release_status($mapConfig);
    foreach ($mapReleases as $i => $row) {
        $mapReleases[$i]['paused'] = km_app_map_is_paused((array) ($mapConfig['maps'][$row['slug']] ?? []));
    }
} catch (Throwable $exception) {
    error_log('admin/index.php map releases failed: ' . $exception->getMessage());
}
$KM_PAGE = [
    'nav' => 'index',
    'titleKey' => 'page.index.title',
    'title' => 'ダッシュボード | KosenMap 管理',
    'h1Key' => 'page.index.h1',
    'h1' => 'ダッシュボード',
    'crumbs' => [
        ['key' => 'page.index.h1', 'text' => 'ダッシュボード'],
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
            <!--begin::Placeholder Notice-->
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                <strong data-i18n="common.noticeTitle">お知らせ</strong>
                <div data-i18n="common.placeholderNotice">
                  タイルの数値はすべて実データです(サービス数は死活監視、ユーザー数は Logto、
                  他は MariaDB から取得しています)。
                </div>
              </div>
            </div>
            <!--end::Placeholder Notice-->

            <!--begin::Row-->
            <div class="row">
              <!--begin::Col-->
              <div class="col-lg-3 col-6">
                <!--begin::Small Box Widget 1-->
                <div class="small-box text-bg-primary">
                  <div class="inner">
                    <!-- services.js が読み込み時に実際の件数で置き換える。ここは JS 前の初期値 -->
                    <h3 data-km-services-count>8</h3>
                    <p data-i18n="page.index.tileServices">監視対象サービス</p>
                  </div>
                  <i class="bi bi-hdd-network small-box-icon" aria-hidden="true"></i>
                  <a
                    href="./taskmgr.php?tab=services"
                    class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover"
                  >
                    <span data-i18n="common.details">詳細</span>
                    <i class="bi bi-link-45deg"></i>
                  </a>
                </div>
                <!--end::Small Box Widget 1-->
              </div>
              <!--end::Col-->
              <!--begin::Col-->
              <div class="col-lg-3 col-6">
                <!--begin::Small Box Widget 2-->
                <div class="small-box text-bg-success">
                  <div class="inner">
                    <h3><?= $userCount === null ? '&mdash;' : (int) $userCount ?></h3>
                    <p data-i18n="page.index.tileUsers">登録ユーザー</p>
                  </div>
                  <i class="bi bi-people small-box-icon" aria-hidden="true"></i>
                  <a
                    href="./users.php"
                    class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover"
                  >
                    <span data-i18n="common.details">詳細</span>
                    <i class="bi bi-link-45deg"></i>
                  </a>
                </div>
                <!--end::Small Box Widget 2-->
              </div>
              <!--end::Col-->
              <!--begin::Col-->
              <div class="col-lg-3 col-6">
                <!--begin::Small Box Widget 3-->
                <div class="small-box text-bg-warning">
                  <div class="inner">
                    <h3><?= $staffWaiting === null ? '&mdash;' : (int) $staffWaiting ?></h3>
                    <p data-i18n="page.index.tileStaff">教職員の申請(未処理)</p>
                  </div>
                  <i class="bi bi-person-badge small-box-icon" aria-hidden="true"></i>
                  <a
                    href="./staff-requests.php"
                    class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover"
                  >
                    <span data-i18n="common.details">詳細</span>
                    <i class="bi bi-link-45deg"></i>
                  </a>
                </div>
                <!--end::Small Box Widget 3-->
              </div>
              <!--end::Col-->
              <!--begin::Col-->
              <div class="col-lg-3 col-6">
                <!--begin::Small Box Widget 4-->
                <div class="small-box text-bg-danger">
                  <div class="inner">
                    <h3><?= $eventCount === null ? '&mdash;' : (int) $eventCount ?></h3>
                    <p data-i18n="page.index.tileEvents">24時間の操作</p>
                  </div>
                  <i class="bi bi-clock-history small-box-icon" aria-hidden="true"></i>
                  <a
                    href="./timeline.php"
                    class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover"
                  >
                    <span data-i18n="common.details">詳細</span>
                    <i class="bi bi-link-45deg"></i>
                  </a>
                </div>
                <!--end::Small Box Widget 4-->
              </div>
              <!--end::Col-->
            </div>
            <!--end::Row-->

            <!--begin::Row-->
            <div class="row">
              <div class="col-12">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.index.servicesTitle">サービス一覧</h3>
                    <div class="card-tools">
                      <button
                        type="button"
                        class="btn btn-tool"
                        data-lte-toggle="card-collapse"
                        aria-label="カードを折りたたむ"
                        data-i18n-attr="aria-label:a11y.collapseCard"
                      >
                        <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                        <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                      </button>
                    </div>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <!-- services.js が生成する。言語切り替え時は作り直される -->
                    <div class="row" data-km-services="compact"></div>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->
              </div>
            </div>
            <!--end::Row-->

            <!--begin::Row-->
            <div class="row">
              <div class="col-12">
                <!--begin::Card-->
                <div class="card">
                  <div class="card-header d-flex align-items-center">
                    <h3 class="card-title" data-i18n="page.index.mapTitle">アプリへ配っている地図</h3>
                    <div class="ms-auto">
                      <a href="./map-publish.php" class="btn btn-sm btn-outline-secondary" data-i18n="side.mapPublish">地図の配信</a>
                    </div>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body p-0">
                    <?php if ($mapReleases === null): ?>
                      <p class="text-body-secondary m-3" data-i18n="page.index.mapError">配信の設定を読み込めませんでした。</p>
                    <?php elseif ($mapReleases === []): ?>
                      <p class="text-body-secondary m-3" data-i18n="page.index.mapEmpty">いま配っている地図はありません。</p>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                      <?php foreach ($mapReleases as $row): ?>
                      <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                        <code><?= km_e($row['slug']) ?></code>
                        <span class="text-body-secondary fs-7">版 <?= (int) $row['revision'] ?></span>
                        <span class="flex-grow-1"></span>
                        <?php if ($row['paused']): ?>
                          <span class="badge text-bg-secondary" data-i18n="page.index.mapPaused">停止中</span>
                        <?php elseif ($row['unknown']): ?>
                          <span class="badge text-bg-danger" data-i18n="page.index.mapUnknown">期限が読めません</span>
                        <?php elseif ($row['expired']): ?>
                          <span class="badge text-bg-danger" data-i18n="page.index.mapExpired">期限切れ</span>
                        <?php else: ?>
                          <span class="badge <?= $row['warn'] ? 'text-bg-warning' : 'text-bg-success' ?>">
                            <?= km_e(substr((string) $row['expiresAt'], 0, 16)) ?>
                            (<span data-i18n="page.index.mapLeft">あと</span> <?= (int) $row['daysLeft'] ?> <span data-i18n="page.index.mapDays">日</span>)
                          </span>
                        <?php endif; ?>
                      </li>
                      <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                  </div>                  <!-- /.card-body -->
                </div>
                <!--end::Card-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

<?php require __DIR__ . '/_inc/partials/footer.php'; ?>