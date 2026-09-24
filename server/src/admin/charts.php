<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';

$KM_PAGE = [
    'nav' => 'charts',
    'titleKey' => 'page.charts.title',
    'title' => 'チャート | KosenMap 管理',
    'h1Key' => 'page.charts.h1',
    'h1' => 'チャート',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.charts.h1', 'text' => 'チャート'],
    ],
];

/*
 * 外部チャートライブラリは使わず、素の SVG / CSS で描く方針は据え置き。
 * 数値だけをサンプルから実データに差し替える。
 *
 * 月の区切りは必ず SQL 側(DATE_FORMAT)で出す。フェーズ13で DB と PHP のタイムゾーンを
 * Asia/Tokyo に揃えたので今はどちらで丸めても同じになるが、**値が住んでいる場所で区切る**
 * 方が設定の食い違いに強い。以前は DB が UTC・PHP が Asia/Tokyo で、PHP 側で丸めると
 * 月境界が9時間ずれた(フェーズ5・9の教訓)。
 */
$months = [];
$values = [];
$occupantWith = 0;
$occupantWithout = 0;
$dbError = null;

try {
    $pdo = km_db();

    // 直近6か月ぶんの枠を先に作る(データが無い月も 0 として並べたいので)
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-{$i} month"));
        $months[$key] = (int) date('n', strtotime("-{$i} month")) . '月';
        $values[$key] = 0;
    }

    $rows = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM km_admin_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP BY ym"
    )->fetchAll();
    foreach ($rows as $row) {
        $ym = (string) $row['ym'];
        if (array_key_exists($ym, $values)) {
            $values[$ym] = (int) $row['c'];
        }
    }

    // ドーナツは map-settings.php が既に使っている2つの COUNT をそのまま流用する
    $totalNodes = (int) $pdo->query('SELECT COUNT(*) FROM km_map_nodes')->fetchColumn();
    $occupantWith = (int) $pdo->query('SELECT COUNT(*) FROM km_map_nodes WHERE occupant_name IS NOT NULL')->fetchColumn();
    $occupantWithout = max(0, $totalNodes - $occupantWith);
} catch (Throwable $exception) {
    error_log('charts.php failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
    $months = [];
    $values = [];
}

$months = array_values($months);
$values = array_values($values);
// 全部 0 でも高さ計算で 0 除算しないように(元のサンプル実装と同じ配慮)
$max = ($values !== [] ? max($values) : 0) ?: 1;

$occupantTotal = $occupantWith + $occupantWithout;
$occupantWithPercent = $occupantTotal > 0 ? round($occupantWith / $occupantTotal * 100, 1) : 0.0;
$occupantWithoutPercent = $occupantTotal > 0 ? round(100 - $occupantWithPercent, 1) : 0.0;

$barWidth = 44;
$gap = 24;
$chartHeight = 160;
$chartWidth = max(1, count($values)) * ($barWidth + $gap) + $gap;

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.charts.notice">
                外部のチャートライブラリを使わず、素の SVG と CSS だけで描いています。数値は実データです。
              </div>
            </div>

            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.charts.dbError">
                データベースに接続できないため、グラフを表示できません。
              </div>
            <?php endif; ?>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-7">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.charts.barTitle">月別の操作件数</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <svg
                      viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight + 30 ?>"
                      role="img"
                      aria-label="月別ログイン回数の棒グラフ"
                      data-i18n-attr="aria-label:page.charts.barTitle"
                      class="w-100 km-chart-bar"
                    >
                      <?php foreach ($values as $i => $value): ?>
                        <?php
                        $barHeight = (int) round(($value / $max) * $chartHeight);
                        $x = $gap + $i * ($barWidth + $gap);
                        $y = $chartHeight - $barHeight;
                        ?>
                        <rect
                          x="<?= $x ?>"
                          y="<?= $y ?>"
                          width="<?= $barWidth ?>"
                          height="<?= $barHeight ?>"
                          rx="4"
                          fill="var(--bs-primary)"
                        >
                          <title><?= km_e($months[$i] . ': ' . $value) ?></title>
                        </rect>
                        <text
                          x="<?= $x + $barWidth / 2 ?>"
                          y="<?= $y - 6 ?>"
                          text-anchor="middle"
                          font-size="12"
                          fill="currentColor"
                        ><?= $value ?></text>
                        <text
                          x="<?= $x + $barWidth / 2 ?>"
                          y="<?= $chartHeight + 20 ?>"
                          text-anchor="middle"
                          font-size="12"
                          fill="currentColor"
                        ><?= km_e($months[$i]) ?></text>
                      <?php endforeach; ?>
                    </svg>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->
              </div>

              <div class="col-12 col-xl-5">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.charts.donutTitle">教職員氏名の登録状況</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body d-flex align-items-center gap-4 flex-wrap">
                    <?php if ($occupantTotal === 0): ?>
                      <p class="text-body-secondary fs-7 mb-0" data-i18n="page.charts.donutEmpty">
                        地図データがまだ登録されていません。
                      </p>
                    <?php else: ?>
                      <?php
                        /*
                         * 割合だけが動くので、値は data 属性で渡し、CSS 変数として
                         * 下のスクリプトが設定する(style="…" は CSP で使えない)。
                         * 見た目の定義は assets/css/km-admin.css の .km-donut。
                         */
                      ?>
                      <div
                        class="km-donut"
                        data-km-donut="<?= (int) round($occupantWithPercent) ?>"
                        role="img"
                        aria-label="教職員氏名の登録状況"
                        data-i18n-attr="aria-label:page.charts.donutTitle"
                      ></div>
                      <ul class="list-unstyled mb-0 fs-7">
                        <li class="mb-2">
                          <span class="badge text-bg-danger">&nbsp;</span>
                          <span data-i18n="page.charts.donutWith">氏名あり</span>
                          <strong><?= $occupantWith ?> (<?= $occupantWithPercent ?>%)</strong>
                        </li>
                        <li>
                          <span class="badge text-bg-primary">&nbsp;</span>
                          <span data-i18n="page.charts.donutWithout">氏名なし</span>
                          <strong><?= $occupantWithout ?> (<?= $occupantWithoutPercent ?>%)</strong>
                        </li>
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

        <?php // 値の反映は JS 経由。要素の style を JS から触るのは CSP の対象外 ?>
        <script<?= km_csp_nonce_attr() ?>>
          document.querySelectorAll('[data-km-donut]').forEach((el) => {
            el.style.setProperty('--km-donut', el.dataset.kmDonut + '%');
          });
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
