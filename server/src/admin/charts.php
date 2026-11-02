<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/node-extras.php';

$KM_PAGE = [
    'nav' => 'charts',
    'titleKey' => 'page.charts.title',
    'title' => 'チャート | KosenMap 管理',
    'h1Key' => 'page.charts.h1',
    'h1' => 'チャート',
    'crumbs' => [
        ['key' => 'side.groupRecords', 'text' => '記録と分析'],
        ['key' => 'page.charts.h1', 'text' => 'チャート'],
    ],
];

/*
 * 外部チャートライブラリは使わず、素の SVG / CSS で描く方針は据え置き。
 * 数値だけをサンプルから実データに差し替える。
 *
 * **2026-11-02 の並べ直し**(利用者の指示「無理やり配置した項目を的確な場所へ」):
 * - 部屋のいいね(Android アプリ)をポスターの画面から移した(数を見る場所はここ)
 * - 教職員氏名の登録状況は教職員の地点(staff-nodes.php)へ移した
 * - 今年よく検索された語はランキングの語(ranking.php)と同じものだったので、そこへのリンクにした
 *
 * 月の区切りは必ず SQL 側(DATE_FORMAT)で出す。フェーズ13で DB と PHP のタイムゾーンを
 * Asia/Tokyo に揃えたので今はどちらで丸めても同じになるが、**値が住んでいる場所で区切る**
 * 方が設定の食い違いに強い。以前は DB が UTC・PHP が Asia/Tokyo で、PHP 側で丸めると
 * 月境界が9時間ずれた(フェーズ5・9の教訓)。
 */
$months = [];
$values = [];
$dbError = null;
$rankingPlaces = [];
$likeTotals = [];
$errors = [];

try {
    $pdo = km_db();

    // 部屋のいいねを 0 に戻す(2026-10-22。lib/node-extras.php。2026-11-02 にポスターの画面から移した)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!km_csrf_verify()) {
            $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
        } elseif ((string) ($_POST['form_action'] ?? '') === 'likes_reset') {
            $likeNode = (string) ($_POST['node_uuid'] ?? '');
            if (km_node_uuid_valid($likeNode)) {
                km_node_likes_ensure_table($pdo);
                $removed = km_node_likes_reset($pdo, $likeNode);
                km_admin_log_record('content', 'node.likes_reset', $likeNode . '(' . $removed . ' 件)');
            }
            header('Location: ./charts.php?saved=likes_reset#node-likes', true, 303);
            exit;
        }
    }

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

    /*
     * よく行く場所(2026-10-05、利用者の指示)。今年の上位 10 件。表がまだ無ければ空のまま(ランキングを一度も使っていない)。
     * 調べられた語は ranking.php(公開を選ぶ画面に同じ数がある)。2026-11-02 にここの写しをやめた
     */
    $year = (int) date('Y');
    try {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(NULLIF(n.title, \'\'), NULLIF(n.name, \'\'), p.node_uuid) AS label, SUM(p.visits) AS c
               FROM km_map_ranking_places p
               LEFT JOIN km_map_nodes n ON n.uuid = p.node_uuid
              WHERE p.year = ?
              GROUP BY p.node_uuid, label
              ORDER BY c DESC, label
              LIMIT 10'
        );
        $stmt->execute([$year]);
        $rankingPlaces = $stmt->fetchAll();
    } catch (PDOException $exception) {
        error_log('charts.php ranking skipped: ' . $exception->getMessage());
    }

    km_node_likes_ensure_table($pdo);
    $likeTotals = km_node_likes_totals($pdo);
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

$notice = ['likes_reset' => 'いいねを 0 に戻しました。'][(string) ($_GET['saved'] ?? '')] ?? null;

$barWidth = 44;
$gap = 24;
$chartHeight = 160;
/*
 * 棒の上の数字のための余白(2026-10-05)。以前はいちばん高い棒の数字が y=-6 に描かれ、
 * SVG の枠の外に出て消えていた(「200 件を超えると見えない」と報告された。200 という数そのものは関係ない)。
 */
$chartTop = 20;
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

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
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
                      viewBox="0 0 <?= $chartWidth ?> <?= $chartTop + $chartHeight + 30 ?>"
                      role="img"
                      aria-label="月別の操作件数の棒グラフ"
                      data-i18n-attr="aria-label:page.charts.barTitle"
                      class="w-100 km-chart-bar"
                    >
                      <?php foreach ($values as $i => $value): ?>
                        <?php
                        $barHeight = (int) round(($value / $max) * $chartHeight);
                        $x = $gap + $i * ($barWidth + $gap);
                        $y = $chartTop + $chartHeight - $barHeight;
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
                          y="<?= $chartTop + $chartHeight + 20 ?>"
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
                <?php
                /*
                 * 部屋のいいね(2026-10-22。Android アプリの部屋の詳細。lib/node-extras.php)。
                 * 2026-11-02 にポスターの画面から移した(数を見る場所はチャート)
                 */
                ?>
                <div class="card mb-4" id="node-likes">
                  <div class="card-header"><h3 class="card-title">部屋のいいね(<?= count($likeTotals) ?> 地点)</h3></div>
                  <div class="card-body">
                    <p class="fs-7 text-body-secondary">
                      Android アプリの部屋の詳細で付けられる「いいね」の数です。<strong>だれが付けたかは持っていません</strong>
                      (端末ごとの印を鍵つきの印にしてだけ持ち、1 端末 1 地点 1 つ。同じ送り元から 1 時間に <?= KM_NODE_LIKE_SOURCE_LIMIT ?> まで)。
                      部屋の詳細には、<a href="./posters.php">ポスター・広告</a>で地点に置いたポスターが写真として出ます。
                    </p>
                    <?php if ($likeTotals === []): ?>
                      <p class="text-body-secondary fs-7 mb-0">まだありません。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead><tr><th scope="col">地点</th><th scope="col" class="text-end">いいね</th><th scope="col"></th></tr></thead>
                          <tbody>
                            <?php foreach ($likeTotals as $likeRow): ?>
                              <tr>
                                <td><?= km_e($likeRow['name'] !== '' ? $likeRow['name'] : $likeRow['uuid'] . '(地図に無い)') ?></td>
                                <td class="text-end"><?= (int) $likeRow['count'] ?></td>
                                <td class="text-end">
                                  <form method="post" class="m-0" data-km-confirm="この地点のいいねを 0 に戻します。よろしいですか?">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="likes_reset">
                                    <input type="hidden" name="node_uuid" value="<?= km_e($likeRow['uuid']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">0 に戻す</button>
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
            </div>
            <!--end::Row-->

            <?php
            /*
             * ランキングの語・よく行く場所(今年の上位 10 件)。横棒は割合を data 属性で渡し、下のスクリプトが幅に入れる。
             * 語は利用者が打った文字列なので、必ず km_e で出す。
             */
            $rankingCard = static function (string $titleKey, string $title, string $emptyKey, array $rows): void {
                $top = 0;
                foreach ($rows as $row) {
                    $top = max($top, (int) $row['c']);
                }
                ?>
                <div class="col-12 col-xl-6">
                  <div class="card mb-4">
                    <div class="card-header">
                      <h3 class="card-title" data-i18n="<?= km_e($titleKey) ?>"><?= km_e($title) ?></h3>
                    </div>
                    <div class="card-body">
                      <?php if ($rows === []): ?>
                        <p class="text-body-secondary fs-7 mb-0" data-i18n="<?= km_e($emptyKey) ?>">まだありません。</p>
                      <?php else: ?>
                        <ol class="list-unstyled mb-0">
                          <?php foreach ($rows as $i => $row): ?>
                            <li class="mb-2">
                              <div class="d-flex justify-content-between fs-7">
                                <span class="text-truncate me-2"><?= (int) $i + 1 ?>. <?= km_e((string) $row['label']) ?></span>
                                <strong><?= (int) $row['c'] ?></strong>
                              </div>
                              <div class="progress km-progress-sm" role="progressbar" aria-valuenow="<?= (int) $row['c'] ?>" aria-valuemin="0" aria-valuemax="<?= $top ?>">
                                <div class="progress-bar" data-km-progress="<?= $top > 0 ? (int) round((int) $row['c'] / $top * 100) : 0 ?>"></div>
                              </div>
                            </li>
                          <?php endforeach; ?>
                        </ol>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php
            };
            ?>
            <!--begin::Row(ランキング)-->
            <div class="row">
              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title" data-i18n="page.charts.rankingQueriesTitle">今年よく検索された語</h3></div>
                  <div class="card-body">
                    <p class="fs-7 text-body-secondary mb-2">数と、公開する語を選ぶ画面は「ランキングの語」にあります(同じ数を 2 か所に出さない)。</p>
                    <a href="./ranking.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-trophy me-1" aria-hidden="true"></i><span data-i18n="side.ranking">ランキングの語</span></a>
                  </div>
                </div>
              </div>
              <?php $rankingCard('page.charts.rankingPlacesTitle', '今年よく行かれた場所', 'page.charts.rankingEmpty', $rankingPlaces); ?>
            </div>
            <!--end::Row(ランキング)-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <?php // 値の反映は JS 経由。要素の style を JS から触るのは CSP の対象外 ?>
        <script<?= km_csp_nonce_attr() ?>>
          document.querySelectorAll('[data-km-progress]').forEach((bar) => {
            bar.style.width = bar.dataset.kmProgress + '%';
          });
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
