<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/table-manage.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/logto-management.php';

/*
 * タイルの数値。取得できないものは「—」のままにして画面自体は必ず出す
 * (admin/map-settings.php の $dbError と同じ扱い)。
 * 監視対象サービス数は services.js が data-km-services-count へ書き込むのでここでは扱わない。
 */
$tableCount = null;
$eventCount = null;
try {
    $pdo = km_db();
    $tableCount = count(km_table_list($pdo));
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
                    href="./monitor.php"
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
                    <h3><?= $tableCount === null ? '&mdash;' : (int) $tableCount ?></h3>
                    <p data-i18n="page.index.tileTables">テーブル数</p>
                  </div>
                  <i class="bi bi-table small-box-icon" aria-hidden="true"></i>
                  <a
                    href="./tables.php"
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
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.index.nextTitle">次フェーズの作業</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item d-flex align-items-start">
                        <i class="bi bi-1-circle-fill me-2 mt-1 text-primary" aria-hidden="true"></i>
                        <span data-i18n="page.index.next1"
                          >VPS とドメインを用意し、Let's Encrypt へ移行する (準備は 1.0.1 で完了済み)</span
                        >
                      </li>
                      <li class="list-group-item d-flex align-items-start">
                        <i class="bi bi-2-circle-fill me-2 mt-1 text-primary" aria-hidden="true"></i>
                        <span data-i18n="page.index.next2"
                          >sign-in.php の新規登録画面 (InteractionMode::signUp) を一般サイトからも外すか判断する</span
                        >
                      </li>
                      <li class="list-group-item d-flex align-items-start">
                        <i class="bi bi-3-circle-fill me-2 mt-1 text-primary" aria-hidden="true"></i>
                        <span data-i18n="page.index.next3"
                          >Logto の MFA (2段階認証) を有効にする (Logto Console 側の設定)</span
                        >
                      </li>
                      <li class="list-group-item d-flex align-items-start">
                        <i class="bi bi-4-circle-fill me-2 mt-1 text-primary" aria-hidden="true"></i>
                        <span data-i18n="page.index.next4"
                          >監査ログ用に DB ユーザーを分ける (利用者側で実施予定。アプリは接続をもう1本持つ改修が要る)</span
                        >
                      </li>
                    </ul>
                  </div>
                  <!-- /.card-body -->
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