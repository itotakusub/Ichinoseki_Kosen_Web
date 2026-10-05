<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/table-manage.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/logto-management.php';
require_once dirname(__DIR__) . '/lib/tasks.php';

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

/*
 * 「次フェーズの作業」は、かんばん・プロジェクト状況と同じ表(km_tasks)から出す(2026-10-05、利用者の指示)。
 * 以前は 4 行の決め打ちで、ボードで進めても変わらなかった。
 * 並びは「進行中 → 要判断 → 未着手」、同じ状態の中はボードの並び(sort_order)。完了は出さない。
 */
$nextTasks = null;
$taskStatusLabel = ['progress' => '進行中', 'decision' => '要判断', 'todo' => '未着手'];
$taskStatusBadge = ['progress' => 'text-bg-info', 'decision' => 'text-bg-warning', 'todo' => 'text-bg-secondary'];
try {
    $rank = ['progress' => 0, 'decision' => 1, 'todo' => 2];
    $open = array_values(array_filter(
        km_tasks_all(km_db()),
        static fn (array $task): bool => isset($rank[$task['status']])
    ));
    usort($open, static fn (array $a, array $b): int =>
        [$rank[$a['status']], (int) $a['sortOrder'], (int) $a['id']] <=> [$rank[$b['status']], (int) $b['sortOrder'], (int) $b['id']]);
    $nextTasks = array_slice($open, 0, 5);
} catch (Throwable $exception) {
    error_log('admin/index.php next tasks failed: ' . $exception->getMessage());
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
                  <div class="card-header d-flex align-items-center">
                    <h3 class="card-title" data-i18n="page.index.nextTitle">次フェーズの作業</h3>
                    <div class="ms-auto">
                      <a href="./kanban.php" class="btn btn-sm btn-outline-secondary" data-i18n="page.index.nextKanban">かんばん</a>
                      <a href="./projects.php" class="btn btn-sm btn-outline-secondary" data-i18n="page.index.nextProjects">プロジェクト状況</a>
                    </div>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body p-0">
                    <?php if ($nextTasks === null): ?>
                      <p class="text-body-secondary m-3" data-i18n="page.index.nextError">作業の一覧を読み込めませんでした。</p>
                    <?php elseif ($nextTasks === []): ?>
                      <p class="text-body-secondary m-3" data-i18n="page.index.nextEmpty">残っている作業はありません。</p>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                      <?php foreach ($nextTasks as $i => $task): ?>
                      <li class="list-group-item d-flex align-items-center gap-2">
                        <i class="bi bi-<?= (int) $i + 1 ?>-circle-fill text-primary" aria-hidden="true"></i>
                        <span class="flex-grow-1"><?= km_e((string) $task['title']) ?></span>
                        <span class="badge <?= km_e($taskStatusBadge[$task['status']]) ?>"
                          data-i18n="page.projects.status.<?= km_e((string) $task['status']) ?>"
                          ><?= km_e($taskStatusLabel[$task['status']]) ?></span>
                        <div class="progress km-progress-sm km-w-8rem flex-shrink-0" role="progressbar" aria-valuenow="<?= (int) $task['progress'] ?>" aria-valuemin="0" aria-valuemax="100">
                          <div class="progress-bar" data-km-progress="<?= (int) $task['progress'] ?>"></div>
                        </div>
                      </li>
                      <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
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
        <?php // 進み具合の幅は動く値なので data 属性で渡し、ここで style へ入れる(CSP で style 属性が使えない。projects.php と同じ) ?>
        <script<?= km_csp_nonce_attr() ?>>
          document.querySelectorAll('[data-km-progress]').forEach((bar) => {
            bar.style.width = bar.dataset.kmProgress + '%';
          });
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>