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
 * - 色分けは ① ISP/ASN 〜 ⑦ 過去の挙動 の点数(assets/js/visitors-threats.js。2026-10-08)
 * - CSV の書き出し・表示する件数(10/50/100/200)・国の行の ▷ で地域・都市を開く も、すべてブラウザ(2026-10-08)
 * - 世界地図のカード(2026-10-08、利用者の指示): 本書(Website/index.html)の
 *   `card text-white bg-primary bg-gradient border-primary mb-4`(Sales Value)を写し、国ごとの訪問と BAN を載せる。
 *   地図は本書と同じ jsVectorMap 1.5.3(vendor/jsvectormap。CSP で外の CDN を読めないので置いた)。
 *   小さなグラフは本書の ApexCharts の代わりに SVG で描く(assets/js/visitors-world.js)。
 *   BAN は admin/api/visitor-bans.php(scripts/host-stats.sh が root の cron で書く bans.json)
 * - 日別・国・地域別・ページ別のカードは折りたためる(AdminLTE の card-collapse)
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
    'css' => ['./vendor/jsvectormap/css/jsvectormap.min.css'],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid" id="km-visitors" data-src="./api/visit-log.php" data-bans-src="./api/visitor-bans.php">
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
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="km-visitors-show-foreign" checked />
                <label class="form-check-label fs-7" for="km-visitors-show-foreign" data-i18n="page.visitors.showForeign">外国からの接続を表示</label>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" id="km-visitors-reload">
                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
                <span data-i18n="page.visitors.reload">読み直す</span>
              </button>
              <div class="btn-group btn-group-sm" role="group" aria-label="CSV">
                <button type="button" class="btn btn-outline-success" id="km-visitors-export-visits" title="いま表示している訪問を CSV(Excel で開ける)に書き出す">
                  <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>
                  <span data-i18n="page.visitors.exportVisits">訪問を CSV に</span>
                </button>
                <button type="button" class="btn btn-outline-success" id="km-visitors-export-ips" title="送り元(IP)ごとのまとめを CSV に書き出す">
                  <span data-i18n="page.visitors.exportIps">送り元を CSV に</span>
                </button>
              </div>
              <span class="text-body-secondary fs-7" data-km-vs="status">読み込み中…</span>
            </div>
            <p class="fs-7 mb-3">
              <span class="badge text-bg-danger me-1" data-i18n="page.visitors.levelDanger">危険</span>
              <span class="badge text-bg-warning me-1" data-i18n="page.visitors.levelSuspect">怪しい</span>
              <span class="badge text-bg-info me-1" data-i18n="page.visitors.levelTrusted">信頼</span>
              <span class="badge text-bg-secondary me-1" data-i18n="page.visitors.levelForeign">外国</span>
              <span class="text-body-secondary" data-i18n="page.visitors.legend">
                ① ISP/ASN ② User-Agent ③ IP 種別 ④ アクセス頻度 ⑤ HTTP ステータス ⑥ URL・メソッド ⑦ 過去の挙動 の点を足して、60 点以上は赤(危険)・25 点以上は黄(怪しい)。
                決まりはページの下の「危険リスト」。自分の回線などは「信頼する」で色を外せます(このブラウザだけ)。名乗りは偽れるので、色が付いていないことは安全の印ではありません。
              </span>
            </p>

            <div class="row">
              <?php
              $tile = static function (string $key, string $icon, string $color, string $labelKey, string $label): void {
                  ?>
                  <div class="col-12 col-sm-6 col-xl-2">
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
              $tile('danger', 'bi-exclamation-octagon', 'danger', 'page.visitors.danger', '危険な送り元');
              $tile('suspect', 'bi-exclamation-triangle', 'warning', 'page.visitors.suspect', '怪しい送り元');
              ?>
            </div>

            <?php
            // 折りたたむボタン(AdminLTE の card-collapse。ダッシュボードのサービス一覧と同じ形)
            $collapseButton = static function (string $class = 'btn btn-tool'): void {
                ?>
                <button
                  type="button"
                  class="<?= km_e($class) ?>"
                  data-lte-toggle="card-collapse"
                  aria-label="カードを折りたたむ"
                  data-i18n-attr="aria-label:a11y.collapseCard"
                >
                  <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                  <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                </button>
                <?php
            };
            $table = static function (string $key, string $titleKey, string $title, array $columns, string $col = 'col-12 col-xl-4', bool $numeric = true, ?callable $tools = null, bool $collapsible = false) use ($collapseButton): void {
                ?>
                  <div class="<?= km_e($col) ?>">
                    <div class="card mb-4">
                      <div class="card-header">
                        <h3 class="card-title" data-i18n="<?= km_e($titleKey) ?>"><?= km_e($title) ?></h3>
                        <?php if ($tools !== null || $collapsible): ?>
                          <div class="card-tools d-flex align-items-center gap-2">
                            <?php if ($tools !== null) { $tools(); } ?>
                            <?php if ($collapsible) { $collapseButton(); } ?>
                          </div>
                        <?php endif; ?>
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
            ?>

            <div class="row">
              <!--begin::World(本書 index.html の Sales Value のカードを写したもの。中身は visitors-world.js)-->
              <div class="col-12 col-xl-5">
                <div class="card text-white bg-primary bg-gradient border-primary mb-4" id="km-visitors-world">
                  <div class="card-header border-0">
                    <h3 class="card-title" data-i18n="page.visitors.world">世界地図(訪問と BAN)</h3>
                    <div class="card-tools">
                      <?php $collapseButton('btn btn-primary btn-sm'); ?>
                    </div>
                  </div>
                  <div class="card-body">
                    <div id="km-visitors-world-map" class="km-world-map"></div>
                    <div class="fs-7 mt-2" data-km-vs="worldLegend"></div>
                  </div>
                  <div class="card-footer border-0">
                    <!--begin::Row-->
                    <div class="row">
                      <div class="col-4 text-center">
                        <div data-km-vs="spark1" class="text-dark"></div>
                        <div class="text-white"><span data-i18n="page.visitors.visits">訪問</span> <span data-km-vs="spark1Total"></span></div>
                      </div>
                      <div class="col-4 text-center">
                        <div data-km-vs="spark2" class="text-dark"></div>
                        <div class="text-white"><span data-i18n="page.visitors.ips">送り元(IP)</span> <span data-km-vs="spark2Total"></span></div>
                      </div>
                      <div class="col-4 text-center">
                        <div data-km-vs="spark3" class="text-dark"></div>
                        <div class="text-white"><span data-i18n="page.visitors.colDanger">危険</span> <span data-km-vs="spark3Total"></span></div>
                      </div>
                    </div>
                    <!--end::Row-->
                    <div class="km-world-bans fs-7 mt-3" data-km-vs="bans"></div>
                  </div>
                </div>
              </div>
              <!--end::World-->
              <?php
              $table('byCountry', 'page.visitors.byCountry', '国・地域別', [['page.visitors.colCountry', '国・地域'], ['page.visitors.visits', '訪問'], ['page.visitors.ips', '送り元(IP)']], 'col-12 col-xl-7', collapsible: true);
              ?>
            </div>

            <div class="row">
              <?php
              $table('byDay', 'page.visitors.byDay', '日別', [['page.visitors.colDay', '日付'], ['page.visitors.visits', '訪問'], ['page.visitors.ips', '送り元(IP)'], ['page.visitors.foreign', '外国から'], ['page.visitors.colDanger', '危険']], 'col-12 col-xl-6', collapsible: true);
              $table('byPage', 'page.visitors.byPage', 'ページ別', [['page.visitors.colPage', 'ページ'], ['page.visitors.visits', '訪問'], ['page.visitors.ips', '送り元(IP)']], 'col-12 col-xl-6', collapsible: true);
              ?>
            </div>

            <div class="row">
              <?php
              $table('threats', 'page.visitors.threats', '危険・怪しい送り元(点の高い順・100 件まで)', [
                  ['page.visitors.colScore', '判定・点'],
                  ['page.visitors.colIp', 'IP'],
                  ['page.visitors.colPlace', '場所'],
                  ['page.visitors.colIsp', 'ISP・ASN'],
                  ['page.visitors.colKind', 'IP 種別'],
                  ['page.visitors.visits', '訪問'],
                  ['page.visitors.colPeak', '1 分の最多'],
                  ['page.visitors.colReason', '理由'],
                  ['page.visitors.colLast', '最後'],
                  ['page.visitors.colTrust', '信頼'],
              ], 'col-12', false);
              ?>
            </div>

            <div class="row">
              <?php
              $table('recent', 'page.visitors.recent', '最近の訪問(新しい順)', [
                  ['page.visitors.colTime', '時刻'],
                  ['page.visitors.colScore', '判定・点'],
                  ['page.visitors.colIp', 'IP'],
                  ['page.visitors.colPlace', '場所'],
                  ['page.visitors.colIsp', 'ISP・ASN'],
                  ['page.visitors.colPage', 'ページ'],
                  ['page.visitors.colStatus', '状態'],
                  ['page.visitors.colViewer', '種別'],
                  ['page.visitors.colUa', '端末'],
              ], 'col-12', false, static function (): void {
                  ?>
                  <span class="text-body-secondary fs-7" data-km-vs="recentSub"></span>
                  <label class="form-label fs-7 mb-0" for="km-visitors-rows" data-i18n="page.visitors.rows">表示する件数</label>
                  <select class="form-select form-select-sm w-auto" id="km-visitors-rows">
                    <option value="10">10</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                  </select>
                  <?php
              });
              ?>
            </div>

            <div class="row">
              <?php
              // 危険リスト(判定の決まり。assets/js/visitors-threats.js から作る)
              $table('rules', 'page.visitors.rules', '危険リスト(判定の決まり)', [
                  ['page.visitors.colFactor', '見方'],
                  ['page.visitors.colPoints', '点'],
                  ['page.visitors.colLevel', '判定'],
                  ['page.visitors.colRule', '名前'],
                  ['page.visitors.colWhat', '見ているもの'],
              ], 'col-12', false);
              ?>
            </div>
          </div>
        </div>
        <!--end::App Content-->
        <script src="./vendor/jsvectormap/js/jsvectormap.min.js"></script>
        <script src="./vendor/jsvectormap/maps/world.js"></script>
        <script src="<?= km_e(km_asset('./assets/js/visitors-world.js')) ?>"></script>
        <script src="<?= km_e(km_asset('./assets/js/visitors-threats.js')) ?>"></script>
        <script src="<?= km_e(km_asset('./assets/js/visitors.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
