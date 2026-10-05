<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';

/**
 * タスクマネージャー(2026-10-05、利用者の指示)。
 *
 * 数値は admin/api/taskmgr-data.php が**生のまま**渡し、assets/js/taskmgr.js が**ブラウザで**
 * 割合・単位・CPU の使用率(前回との差)・グラフに直す。サーバーは変換しない(メモリと CPU を使わせない)。
 *
 * 見せるもの: ホスト(Glances。ホスト全体だけ)、Apache のワーカー(/server-status?auto)、
 * コンテナ別(scripts/host-stats.sh が cgroup から集める。メモリ・スワップ・CPU・メモリ不足での停止・ログの大きさ)。
 *
 * **このページとデータの口は nginx で IP を絞ってある**(nginx/km/taskmgr-allow*.local.conf。無ければ誰も入れない)。
 */

$KM_PAGE = [
    'nav' => 'taskmgr',
    'titleKey' => 'page.taskmgr.title',
    'title' => 'タスクマネージャー | KosenMap 管理',
    'h1Key' => 'page.taskmgr.h1',
    'h1' => 'タスクマネージャー',
    'crumbs' => [
        ['key' => 'page.taskmgr.h1', 'text' => 'タスクマネージャー'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid" id="km-taskmgr" data-src="./api/taskmgr-data.php">
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.taskmgr.notice">
                5 秒ごとに読み直します(このタブを開いている間だけ)。数値の計算と表示はこのブラウザで行い、サーバーは生の値を渡すだけです。
              </div>
            </div>
            <p class="text-body-secondary fs-7" data-km-tm="status">読み込み中…</p>

            <!--begin::Row(タイル)-->
            <div class="row">
              <?php
              $tile = static function (string $key, string $icon, string $color, string $labelKey, string $label): void {
                  ?>
                  <div class="col-12 col-sm-6 col-xl-3">
                    <div class="info-box">
                      <span class="info-box-icon text-bg-<?= km_e($color) ?> shadow-sm"><i class="bi <?= km_e($icon) ?>"></i></span>
                      <div class="info-box-content">
                        <span class="info-box-text" data-i18n="<?= km_e($labelKey) ?>"><?= km_e($label) ?></span>
                        <span class="info-box-number" data-km-tm="<?= km_e($key) ?>">—</span>
                        <span class="progress-description fs-7 text-body-secondary" data-km-tm="<?= km_e($key) ?>Sub"></span>
                      </div>
                    </div>
                  </div>
                  <?php
              };
              $tile('cpu', 'bi-cpu', 'primary', 'page.taskmgr.cpu', 'CPU');
              $tile('mem', 'bi-memory', 'success', 'page.taskmgr.mem', 'メモリ');
              $tile('swap', 'bi-hdd-stack', 'warning', 'page.taskmgr.swap', 'スワップ');
              $tile('disk', 'bi-device-hdd', 'info', 'page.taskmgr.disk', 'ディスク');
              ?>
            </div>

            <!--begin::Row(グラフ)-->
            <div class="row">
              <div class="col-12 col-xl-8">
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.taskmgr.historyTitle">直近の推移(CPU・メモリ・スワップ)</h3>
                  </div>
                  <div class="card-body">
                    <svg viewBox="0 0 600 160" class="w-100" role="img" aria-label="直近の推移" data-km-tm="chart"></svg>
                    <p class="fs-7 text-body-secondary mb-0">
                      <span class="badge text-bg-primary">&nbsp;</span> CPU
                      <span class="badge text-bg-success ms-2">&nbsp;</span> <span data-i18n="page.taskmgr.mem">メモリ</span>
                      <span class="badge text-bg-warning ms-2">&nbsp;</span> <span data-i18n="page.taskmgr.swap">スワップ</span>
                    </p>
                  </div>
                </div>
              </div>
              <div class="col-12 col-xl-4">
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.taskmgr.hostTitle">ホスト</h3>
                  </div>
                  <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                      <tbody data-km-tm="hostTable"></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!--begin::Row(コンテナ・プロセス)-->
            <div class="row">
              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.taskmgr.containersTitle">コンテナ</h3>
                  </div>
                  <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-hover mb-0">
                      <thead>
                        <tr>
                          <th data-i18n="page.taskmgr.colName">名前</th>
                          <th class="text-end">CPU</th>
                          <th class="text-end" data-i18n="page.taskmgr.mem">メモリ</th>
                          <th class="text-end" data-i18n="page.taskmgr.swap">スワップ</th>
                          <th class="text-end" data-i18n="page.taskmgr.colOom">メモリ不足</th>
                          <th class="text-end" data-i18n="page.taskmgr.colLog">ログ</th>
                        </tr>
                      </thead>
                      <tbody data-km-tm="containers"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.taskmgr.processTitle">プロセス(CPU の上位)</h3>
                  </div>
                  <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-hover mb-0">
                      <thead>
                        <tr>
                          <th>PID</th>
                          <th data-i18n="page.taskmgr.colName">名前</th>
                          <th class="text-end">CPU</th>
                          <th class="text-end" data-i18n="page.taskmgr.mem">メモリ</th>
                        </tr>
                      </thead>
                      <tbody data-km-tm="processes"></tbody>
                    </table>
                  </div>
                </div>
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.taskmgr.apacheTitle">Apache(web コンテナ)</h3>
                  </div>
                  <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                      <tbody data-km-tm="apache"></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
        <script src="<?= km_e(km_asset('./assets/js/taskmgr.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
