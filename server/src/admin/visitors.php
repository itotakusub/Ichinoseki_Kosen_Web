<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';

/**
 * 訪問者と大まかな位置(2026-10-06。2026-10-05 の計画 B、利用者の指示「外国からのアクセスを確かめる」)。
 *
 * - 記録は nginx が書く(公開のページだけ・クエリなし・30 日で消える。lib/visit-log.php)
 * - admin/api/visit-log.php は**生の行をそのまま**渡す
 * - **数える・まとめる・IP を位置に直すのは、このページを開いたブラウザ**(assets/js/visitors.js)
 *   位置は ipwho.is(国・地域・都市)、引けなければ api.country.is(国だけ)。結果はこのブラウザに 30 日覚える
 * - **外部で引くのはスイッチを入れたときだけ**(訪問者の IP が外部へ渡るため。既定は切)
 *
 * CSP はこのページだけ 2 つの宛先を connect-src に足す(lib/csp.php の 'admin-geo')。
 * bootstrap.php が送った 'admin' のヘッダを、同じ名前で上書きする。
 */
km_csp_send('admin-geo');

$KM_PAGE = [
    'nav' => 'visitors',
    'titleKey' => 'page.visitors.title',
    'title' => '訪問者 | KosenMap 管理',
    'h1Key' => 'page.visitors.h1',
    'h1' => '訪問者',
    'crumbs' => [
        ['key' => 'page.visitors.h1', 'text' => '訪問者'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid" id="km-visitors" data-src="./api/visit-log.php">
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.visitors.notice">
                公開のページ(地図・お試しのリンク・共有リンク・よくある質問・お問い合わせ・アプリの地図)を開いた記録です。
                30 日で消えます。数える・まとめる・位置に直すのはこのブラウザで、サーバーは記録をそのまま渡すだけです。
                「位置を調べる」を入れると、このブラウザが訪問者の IP を外部(ipwho.is、だめなら api.country.is)へ送って国・都市を引きます。
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
              <div class="d-flex align-items-center gap-1">
                <label class="form-label fs-7 mb-0" for="km-visitors-days" data-i18n="page.visitors.days">期間</label>
                <select class="form-select form-select-sm w-auto" id="km-visitors-days">
                  <option value="1" data-i18n="page.visitors.days1">今日</option>
                  <option value="7" selected data-i18n="page.visitors.days7">7 日</option>
                  <option value="14" data-i18n="page.visitors.days14">14 日</option>
                  <option value="30" data-i18n="page.visitors.days30">30 日</option>
                </select>
              </div>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="km-visitors-geo" />
                <label class="form-check-label fs-7" for="km-visitors-geo" data-i18n="page.visitors.geo">位置を調べる(外部へ IP を送る)</label>
              </div>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="km-visitors-foreign" />
                <label class="form-check-label fs-7" for="km-visitors-foreign" data-i18n="page.visitors.foreignOnly">外国からだけ</label>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" id="km-visitors-reload">
                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
                <span data-i18n="page.visitors.reload">読み直す</span>
              </button>
              <span class="text-body-secondary fs-7" data-km-vs="status">読み込み中…</span>
            </div>

            <div class="row">
              <?php
              $tile = static function (string $key, string $icon, string $color, string $labelKey, string $label): void {
                  ?>
                  <div class="col-12 col-sm-6 col-xl-3">
                    <div class="info-box">
                      <span class="info-box-icon text-bg-<?= km_e($color) ?> shadow-sm"><i class="bi <?= km_e($icon) ?>"></i></span>
                      <div class="info-box-content">
                        <span class="info-box-text" data-i18n="<?= km_e($labelKey) ?>"><?= km_e($label) ?></span>
                        <span class="info-box-number" data-km-vs="<?= km_e($key) ?>">—</span>
                        <span class="progress-description fs-7 text-body-secondary" data-km-vs="<?= km_e($key) ?>Sub"></span>
                      </div>
                    </div>
                  </div>
                  <?php
              };
              $tile('visits', 'bi-eye', 'primary', 'page.visitors.visits', '訪問');
              $tile('ips', 'bi-pc-display', 'success', 'page.visitors.ips', '送り元(IP)');
              $tile('foreign', 'bi-globe2', 'danger', 'page.visitors.foreign', '外国から');
              $tile('signed', 'bi-person-badge', 'info', 'page.visitors.signed', 'お試し・サインイン・アプリ');
              ?>
            </div>

            <div class="row">
              <?php
              $table = static function (string $key, string $titleKey, string $title, array $columns, string $col = 'col-12 col-xl-4', bool $numeric = true): void {
                  ?>
                  <div class="<?= km_e($col) ?>">
                    <div class="card mb-4">
                      <div class="card-header">
                        <h3 class="card-title" data-i18n="<?= km_e($titleKey) ?>"><?= km_e($title) ?></h3>
                      </div>
                      <div class="card-body p-0 table-responsive">
                        <table class="table table-sm table-hover mb-0">
                          <thead>
                            <tr>
                              <?php foreach ($columns as $i => [$colKey, $colText]): ?>
                                <th<?= $numeric && $i > 0 ? ' class="text-end"' : '' ?> data-i18n="<?= km_e($colKey) ?>"><?= km_e($colText) ?></th>
                              <?php endforeach; ?>
                            </tr>
                          </thead>
                          <tbody data-km-vs="<?= km_e($key) ?>"></tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                  <?php
              };
              $table('byDay', 'page.visitors.byDay', '日別', [['page.visitors.colDay', '日付'], ['page.visitors.visits', '訪問'], ['page.visitors.ips', '送り元(IP)'], ['page.visitors.foreign', '外国から']]);
              $table('byCountry', 'page.visitors.byCountry', '国・地域別', [['page.visitors.colCountry', '国・地域'], ['page.visitors.visits', '訪問'], ['page.visitors.ips', '送り元(IP)']]);
              $table('byPage', 'page.visitors.byPage', 'ページ別', [['page.visitors.colPage', 'ページ'], ['page.visitors.visits', '訪問'], ['page.visitors.ips', '送り元(IP)']]);
              ?>
            </div>

            <div class="row">
              <?php
              $table('recent', 'page.visitors.recent', '最近の訪問(新しい順・200 件まで)', [
                  ['page.visitors.colTime', '時刻'],
                  ['page.visitors.colIp', 'IP'],
                  ['page.visitors.colPlace', '場所'],
                  ['page.visitors.colPage', 'ページ'],
                  ['page.visitors.colStatus', '状態'],
                  ['page.visitors.colViewer', '種別'],
                  ['page.visitors.colUa', '端末'],
              ], 'col-12', false);
              ?>
            </div>
          </div>
        </div>
        <!--end::App Content-->
        <script src="<?= km_e(km_asset('./assets/js/visitors.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
