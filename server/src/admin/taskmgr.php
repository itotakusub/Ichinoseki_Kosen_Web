<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/image-scan.php';

/**
 * タスクマネージャー(2026-10-05、利用者の指示)。
 *
 * 数値は admin/api/taskmgr-data.php が**生のまま**渡し、assets/js/taskmgr.js が**ブラウザで**
 * 割合・単位・CPU の使用率(前回との差)・グラフに直す。サーバーは変換しない(メモリと CPU を使わせない)。
 *
 * 見せるもの: ホスト(Glances。ホスト全体だけ)、Apache のワーカー(/server-status?auto)、
 * コンテナ別(scripts/host-stats.sh が cgroup から集める。メモリ・スワップ・CPU・メモリ不足での停止・ログの大きさ)。
 *
 * **Windows のタスクマネージャーの形**(2026-11-02、利用者の指示)。左の縦のタブで
 * プロセス / パフォーマンス / コンテナ / サービス / 詳細 を切り替える(`?tab=` で開ける)。
 * サービスはサービス監視(monitor.php)を移したもの —— 生死は api/health-check.php、表は services.js が描く。
 *
 * **このページとデータの口は nginx で IP を絞ってある**(nginx/km/taskmgr-allow*.local.conf。無ければ誰も入れない)。
 */

/** タブ = [id, アイコン, i18n の鍵, 日本語の既定] */
$tabs = [
    ['processes', 'bi-list-columns-reverse', 'page.taskmgr.tabProcesses', 'プロセス'],
    ['performance', 'bi-activity', 'page.taskmgr.tabPerformance', 'パフォーマンス'],
    ['containers', 'bi-box-seam', 'page.taskmgr.tabContainers', 'コンテナ'],
    ['services', 'bi-hdd-network', 'page.taskmgr.tabServices', 'サービス'],
    ['details', 'bi-card-list', 'page.taskmgr.tabDetails', '詳細'],
    // 週 1 回の Trivy の結果(2026-11-06。scripts/host-image-scan.sh)。数秒ごとに読み直さないので、このページが描く
    ['images', 'bi-shield-check', 'page.taskmgr.tabImages', 'イメージの検査'],
    // ベンチマーク(2026-11-06。admin/api/bench.php・assets/js/bench.js・lib/bench.php)
    ['bench', 'bi-speedometer2', 'page.taskmgr.tabBench', 'ベンチマーク'],
];
$imageScan = km_image_scan_read();
$tabIds = array_column($tabs, 0);
$tab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], $tabIds, true) ? $_GET['tab'] : 'processes';

/** パフォーマンスの左の一覧 = [id, i18n の鍵, 日本語の既定] */
$metrics = [
    ['cpu', 'page.taskmgr.cpu', 'CPU'],
    ['mem', 'page.taskmgr.mem', 'メモリ'],
    ['swap', 'page.taskmgr.swap', 'スワップ'],
    ['disk', 'page.taskmgr.disk', 'ディスク'],
    ['net', 'page.taskmgr.net', 'ネットワーク'],
];

/** 並べ替えのできる見出し。押すと taskmgr.js が並べ替える */
$sortHead = static function (string $table, string $key, string $i18n, string $label, bool $end = false): void {
    ?>
    <th scope="col"<?= $end ? ' class="text-end"' : '' ?>>
      <button type="button" class="km-tm-sort" data-km-tm-sort="<?= km_e($table) ?>:<?= km_e($key) ?>">
        <span data-i18n="<?= km_e($i18n) ?>"><?= km_e($label) ?></span><i class="bi km-tm-sort-icon" aria-hidden="true"></i>
      </button>
      <?php if ($table === 'processes' && in_array($key, ['cpu', 'mem'], true)): ?>
        <span class="km-tm-colsum" data-km-tm="procSum-<?= km_e($key) ?>"></span>
      <?php endif; ?>
    </th>
    <?php
};

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
          <div class="container-fluid" id="km-taskmgr" data-src="./api/taskmgr-data.php" data-tab="<?= km_e($tab) ?>">
            <div class="card mb-4 km-tm">
              <!--begin::左の縦のタブ(狭い画面では上の横並び)-->
              <div class="km-tm-rail">
                <div class="km-tm-tabs" role="tablist" aria-label="タスクマネージャーの表示" data-i18n-attr="aria-label:page.taskmgr.h1">
                  <?php foreach ($tabs as [$id, $icon, $key, $label]): ?>
                    <button
                      type="button"
                      class="km-tm-tab<?= $id === $tab ? ' active' : '' ?>"
                      role="tab"
                      id="km-tm-tab-<?= km_e($id) ?>"
                      aria-controls="km-tm-panel-<?= km_e($id) ?>"
                      aria-selected="<?= $id === $tab ? 'true' : 'false' ?>"
                      data-km-tm-tab="<?= km_e($id) ?>"
                    >
                      <i class="bi <?= km_e($icon) ?>" aria-hidden="true"></i>
                      <span data-i18n="<?= km_e($key) ?>"><?= km_e($label) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
                <div class="km-tm-rail-foot">
                  <label class="form-label fs-7 mb-1" for="km-taskmgr-interval" data-i18n="page.taskmgr.interval">更新の間隔</label>
                  <?php // 1〜10 秒(利用者の指示 2026-10-06)。選んだ値は taskmgr.js がこのブラウザに覚える ?>
                  <select class="form-select form-select-sm" id="km-taskmgr-interval">
                    <?php for ($s = 1; $s <= 10; $s++): ?>
                      <option value="<?= $s ?>"<?= $s === 5 ? ' selected' : '' ?>><?= $s ?> s</option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
              <!--end::左の縦のタブ-->

              <div class="km-tm-body">
                <div class="km-tm-bar">
                  <span class="km-tm-status" data-km-tm="status" role="status">読み込み中…</span>
                </div>

                <!--begin::プロセス-->
                <section class="km-tm-panel" id="km-tm-panel-processes" role="tabpanel" aria-labelledby="km-tm-tab-processes" data-km-tm-panel="processes"<?= $tab === 'processes' ? '' : ' hidden' ?>>
                  <p class="km-tm-hint" data-i18n="page.taskmgr.processHint">ホストの CPU の上位(Glances。画面の高さに入るだけ、最大 40 件)。見出しを押すと並べ替えます。</p>
                  <?php // km-tm-fill: 広い画面では残りの高さを埋め、入る行数を taskmgr.js が数える ?>
                  <div class="table-responsive km-tm-fill">
                    <table class="table table-sm table-hover align-middle mb-0 km-tm-table">
                      <thead>
                        <tr>
                          <?php
                          $sortHead('processes', 'name', 'page.taskmgr.colName', '名前');
                          $sortHead('processes', 'pid', 'page.taskmgr.colPid', 'PID', true);
                          $sortHead('processes', 'user', 'page.taskmgr.colUser', 'ユーザー');
                          $sortHead('processes', 'status', 'page.taskmgr.colStatus', '状態');
                          $sortHead('processes', 'cpu', 'page.taskmgr.cpu', 'CPU', true);
                          $sortHead('processes', 'mem', 'page.taskmgr.mem', 'メモリ', true);
                          $sortHead('processes', 'threads', 'page.taskmgr.colThreads', 'スレッド', true);
                          ?>
                        </tr>
                      </thead>
                      <tbody data-km-tm="processes"></tbody>
                    </table>
                  </div>
                </section>

                <!--begin::パフォーマンス-->
                <section class="km-tm-panel" id="km-tm-panel-performance" role="tabpanel" aria-labelledby="km-tm-tab-performance" data-km-tm-panel="performance"<?= $tab === 'performance' ? '' : ' hidden' ?>>
                  <div class="km-tm-perf">
                    <div class="km-tm-metrics" role="listbox" aria-label="項目" data-i18n-attr="aria-label:page.taskmgr.tabPerformance">
                      <?php foreach ($metrics as $index => [$id, $key, $label]): ?>
                        <button
                          type="button"
                          class="km-tm-metric km-tm-c-<?= km_e($id) ?><?= $index === 0 ? ' active' : '' ?>"
                          role="option"
                          aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                          data-km-tm-metric="<?= km_e($id) ?>"
                          hidden
                        >
                          <svg class="km-tm-mini" viewBox="0 0 80 40" preserveAspectRatio="none" aria-hidden="true" data-km-tm="mini-<?= km_e($id) ?>"></svg>
                          <span class="km-tm-metric-text">
                            <span class="km-tm-metric-name" data-i18n="<?= km_e($key) ?>"><?= km_e($label) ?></span>
                            <span class="km-tm-metric-line" data-km-tm="line-<?= km_e($id) ?>">—</span>
                          </span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                    <div class="km-tm-main" data-km-tm="main">
                      <div class="km-tm-head">
                        <h2 class="km-tm-name" data-km-tm="mainName">CPU</h2>
                        <span class="km-tm-model" data-km-tm="mainModel"></span>
                      </div>
                      <div class="km-tm-axis">
                        <span data-km-tm="mainUnit"></span>
                        <span data-km-tm="mainMax"></span>
                      </div>
                      <svg class="km-tm-graph" viewBox="0 0 600 200" preserveAspectRatio="none" role="img" aria-label="直近の推移" data-km-tm="graph"></svg>
                      <div class="km-tm-axis">
                        <span data-km-tm="mainSpan"></span>
                        <span>0</span>
                      </div>
                      <div class="km-tm-stats">
                        <div class="km-tm-big" data-km-tm="mainBig"></div>
                        <dl class="km-tm-kv" data-km-tm="mainKv"></dl>
                      </div>
                    </div>
                  </div>
                </section>

                <!--begin::コンテナ-->
                <section class="km-tm-panel" id="km-tm-panel-containers" role="tabpanel" aria-labelledby="km-tm-tab-containers" data-km-tm-panel="containers"<?= $tab === 'containers' ? '' : ' hidden' ?>>
                  <p class="km-tm-hint" data-i18n="page.taskmgr.containersHint">1 分ごとの集計(host-stats.sh)。CPU は前の集計との差から出します(開いてから 2 回目の集計で出ます)。</p>
                  <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 km-tm-table">
                      <thead>
                        <tr>
                          <?php
                          $sortHead('containers', 'name', 'page.taskmgr.colName', '名前');
                          $sortHead('containers', 'cpu', 'page.taskmgr.cpu', 'CPU', true);
                          $sortHead('containers', 'mem', 'page.taskmgr.mem', 'メモリ', true);
                          $sortHead('containers', 'limit', 'page.taskmgr.colLimit', '上限', true);
                          $sortHead('containers', 'swap', 'page.taskmgr.swap', 'スワップ', true);
                          $sortHead('containers', 'oom', 'page.taskmgr.colOom', 'メモリ不足', true);
                          $sortHead('containers', 'log', 'page.taskmgr.colLog', 'ログ', true);
                          ?>
                        </tr>
                      </thead>
                      <tbody data-km-tm="containers"></tbody>
                    </table>
                  </div>
                </section>

                <!--begin::サービス(以前のサービス監視。2026-11-02)-->
                <section class="km-tm-panel" id="km-tm-panel-services" role="tabpanel" aria-labelledby="km-tm-tab-services" data-km-tm-panel="services"<?= $tab === 'services' ? '' : ' hidden' ?>>
                  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <p class="km-tm-hint mb-0 me-auto" data-i18n="page.taskmgr.servicesHint">サーバーから各サービスの口へ繋いで確かめます(このタブを開いている間、30 秒ごと)。</p>
                    <span class="fs-7 text-body-secondary" data-km-tm="svcChecked"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-km-tm="svcCheck">
                      <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i><span data-i18n="page.taskmgr.checkNow">今すぐ確かめる</span>
                    </button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 km-tm-table">
                      <thead>
                        <tr>
                          <th scope="col" data-i18n="page.taskmgr.colName">名前</th>
                          <th scope="col" data-i18n="page.taskmgr.colStatus">状態</th>
                          <th scope="col" class="text-end" data-i18n="svc.port">ポート</th>
                          <th scope="col" data-i18n="svc.role">役割</th>
                          <th scope="col" data-i18n="page.taskmgr.colUrl">開き方</th>
                          <th scope="col" data-i18n="page.taskmgr.colContainer">コンテナ</th>
                        </tr>
                      </thead>
                      <?php // services.js が SERVICES から行を作る(言語の切り替えでも作り直す) ?>
                      <tbody data-km-services="table"></tbody>
                    </table>
                  </div>
                </section>

                <!--begin::詳細-->
                <section class="km-tm-panel" id="km-tm-panel-details" role="tabpanel" aria-labelledby="km-tm-tab-details" data-km-tm-panel="details"<?= $tab === 'details' ? '' : ' hidden' ?>>
                  <div class="row g-4">
                    <div class="col-12 col-xl-6">
                      <h3 class="km-tm-subtitle" data-i18n="page.taskmgr.hostTitle">ホスト</h3>
                      <table class="table table-sm mb-0 km-tm-table">
                        <tbody data-km-tm="hostTable"></tbody>
                      </table>
                    </div>
                    <div class="col-12 col-xl-6">
                      <h3 class="km-tm-subtitle" data-i18n="page.taskmgr.apacheTitle">Apache(web コンテナ)</h3>
                      <table class="table table-sm mb-0 km-tm-table">
                        <tbody data-km-tm="apache"></tbody>
                      </table>
                    </div>
                  </div>
                </section>

                <!--begin::イメージの検査(2026-11-06)-->
                <section class="km-tm-panel" id="km-tm-panel-images" role="tabpanel" aria-labelledby="km-tm-tab-images" data-km-tm-panel="images"<?= $tab === 'images' ? '' : ' hidden' ?>>
                  <p class="km-tm-hint" data-i18n="page.taskmgr.imagesHint">コンテナのイメージにある既知の脆弱性(HIGH・CRITICAL)。毎週日曜 4:47 にホストが Trivy で調べます(docker.sock は渡しません)。直る版のあるものが新しく出たときだけメールが届きます。</p>
                  <?php if ($imageScan === null): ?>
                    <p class="mb-0" data-i18n="page.taskmgr.imagesNone">まだ結果がありません。ホストで sudo sh scripts/host-image-scan.sh --path /opt/kosenmap を一度流すと出ます。</p>
                  <?php else: ?>
                    <?php $scanOld = time() - $imageScan['generatedAt'] > KM_IMAGE_SCAN_STALE; ?>
                    <p class="fs-7 mb-2<?= $scanOld ? ' text-danger' : ' text-body-secondary' ?>">
                      <span data-i18n="page.taskmgr.imagesAt">調べた日時</span>: <?= km_e(date('Y-m-d H:i', $imageScan['generatedAt'])) ?>
                      ・Trivy <?= km_e($imageScan['trivy']) ?>
                      <?php if ($imageScan['dbUpdatedAt'] !== ''): ?>・DB <?= km_e(substr($imageScan['dbUpdatedAt'], 0, 10)) ?><?php endif; ?>
                      <?php if ($scanOld): ?>・<span data-i18n="page.taskmgr.imagesStale">1 週間より古い結果です(cron が止まっていないか確かめてください)</span><?php endif; ?>
                      <?php if ($imageScan['failed'] > 0): ?>・<span class="text-danger"><span data-i18n="page.taskmgr.imagesFailed">調べられなかったイメージ</span> <?= (int) $imageScan['failed'] ?></span><?php endif; ?>
                    </p>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover align-middle mb-0 km-tm-table">
                        <thead>
                          <tr>
                            <th scope="col" data-i18n="page.taskmgr.colImage">イメージ</th>
                            <th scope="col" class="text-end text-nowrap">CRITICAL</th>
                            <th scope="col" class="text-end text-nowrap">HIGH</th>
                            <th scope="col" class="text-end text-nowrap" data-i18n="page.taskmgr.colFixable">直る版あり</th>
                            <th scope="col" class="text-end text-nowrap" data-i18n="page.taskmgr.colOsVulns">うち OS</th>
                            <th scope="col" class="text-nowrap" data-i18n="page.taskmgr.colBase">土台</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($imageScan['images'] as $img): ?>
                            <tr>
                              <td class="text-break">
                                <code><?= km_e($img['image']) ?></code>
                                <?php if ($img['status'] === 'missing'): ?>
                                  <span class="badge text-bg-secondary" data-i18n="page.taskmgr.imagesMissing">このホストに無い</span>
                                <?php elseif ($img['status'] === 'error'): ?>
                                  <span class="badge text-bg-danger" data-i18n="page.taskmgr.imagesError">調べられず</span>
                                <?php endif; ?>
                                <?php if ($img['top'] !== []): ?>
                                  <details class="mt-1">
                                    <summary class="fs-7" data-i18n="page.taskmgr.imagesTop">直る版のあるもの</summary>
                                    <ul class="fs-7 mb-0 ps-3">
                                      <?php foreach ($img['top'] as $v): ?>
                                        <li>
                                          <span class="badge <?= $v['severity'] === 'CRITICAL' ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= km_e($v['severity']) ?></span>
                                          <?= km_e($v['id']) ?> — <?= km_e($v['pkg']) ?> <?= km_e($v['installed']) ?> → <?= km_e($v['fixed']) ?>
                                        </li>
                                      <?php endforeach; ?>
                                    </ul>
                                  </details>
                                <?php endif; ?>
                              </td>
                              <td class="text-end<?= $img['critical'] > 0 ? ' text-danger fw-bold' : '' ?>"><?= $img['critical'] ?></td>
                              <td class="text-end"><?= $img['high'] ?></td>
                              <td class="text-end<?= $img['criticalFixable'] + $img['highFixable'] > 0 ? ' fw-bold' : '' ?>"><?= $img['criticalFixable'] + $img['highFixable'] ?></td>
                              <td class="text-end"><?= $img['osVulns'] ?></td>
                              <td class="fs-7 text-body-secondary"><?= km_e(preg_replace('/^.*\((.*)\)$/', '$1', $img['os']) ?? '') ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </section>

                <!--begin::ベンチマーク(2026-11-06)-->
                <section class="km-tm-panel" id="km-tm-panel-bench" role="tabpanel" aria-labelledby="km-tm-tab-bench" data-km-tm-panel="bench"<?= $tab === 'bench' ? '' : ' hidden' ?>>
                  <div id="km-bench" data-src="./api/bench.php">
                    <p class="km-tm-hint" data-i18n="page.taskmgr.benchHint">決まった仕事を 1 回空回ししてから 3〜5 回測り、中央値でスコアを出します(1000 = 基準の日と同じ速さ)。1 回で 1 分ほど、同時に 1 つだけ。測っている間はサーバーが重くなります。</p>
                    <div class="alert alert-warning py-2 fs-7" data-km-bench="event" role="alert" hidden></div>
                    <form class="d-flex flex-wrap align-items-end gap-2 mb-3" data-km-bench="form">
                      <div>
                        <label class="form-label fs-7 mb-1" for="km-bench-label" data-i18n="page.taskmgr.benchLabel">名前(任意)</label>
                        <input type="text" class="form-control form-control-sm" id="km-bench-label" maxlength="60" autocomplete="off" />
                      </div>
                      <button type="submit" class="btn btn-sm btn-primary" data-km-bench="start">
                        <i class="bi bi-play-fill me-1" aria-hidden="true"></i><span data-i18n="page.taskmgr.benchStart">測る</span>
                      </button>
                      <span class="fs-7 text-body-secondary" data-km-bench="status" role="status" aria-live="polite"></span>
                    </form>
                    <div data-km-bench="detail"></div>
                    <h3 class="km-tm-subtitle mt-3" data-i18n="page.taskmgr.benchHistory">履歴</h3>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover align-middle mb-0 km-tm-table">
                        <thead data-km-bench="historyHead"></thead>
                        <tbody data-km-bench="history"></tbody>
                      </table>
                    </div>
                    <p class="fs-7 text-body-secondary mt-2 mb-0" data-i18n="page.taskmgr.benchCli">ホストからは php scripts/bench.php、この PC から外の応答は scripts/bench-web.ps1 で測れます(どちらも履歴に残ります)。</p>
                  </div>
                </section>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
        <script src="<?= km_e(km_asset('./assets/js/taskmgr.js')) ?>"></script>
        <script src="<?= km_e(km_asset('./assets/js/bench.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
