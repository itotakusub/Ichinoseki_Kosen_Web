<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/assets.php';

/**
 * 地図の色分け(2026-10-30、高専祭の振り返りのフェーズ 1 の 3。lib/area-paint.php)。
 *
 * 床の線画を、閉じた枠ごとの区画に分け、中の地点の種類で塗る(部屋・廊下・階段・建物・道)。決まりはアプリの AreaSegmentation.kt と同じで、
 * 計算は**このブラウザ**で行う(Main/area-segmentation.js)。塗った画像をサーバーに置き、公開の地図はそれを出すだけ。
 *
 * 作り直すとき: 地点・壁の線・手で決めた区画の色(管理アプリ)を変えたあと、床の画像を差し替えたあと(差し替えると古い塗りは出なくなる)。
 */

$KM_PAGE = [
    'nav' => 'mapAreas',
    'titleKey' => 'page.mapAreas.title',
    'title' => '地図の色分け | KosenMap 管理',
    'h1Key' => 'page.mapAreas.h1',
    'h1' => '地図の色分け',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.mapAreas.h1', 'text' => '地図の色分け'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid" id="km-map-areas" data-src="./api/map-areas.php">
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                床の線で閉じた区画を、中の地点の種類で塗ります(部屋・廊下・階段・建物・道。色はアプリと同じで、色覚の違いでも見分けられる色)。
                計算はこのブラウザで行い、塗った画像を公開の地図に出します。<br>
                <strong>作り直すとき:</strong> 地点や壁の線、管理アプリで決めた区画の色を変えたあと。床の画像を差し替えると、その階の古い塗りは出なくなります。<br>
                塗りが廊下などへ漏れているときは、管理アプリで壁の線を足してから作り直してください(「漏れ」の数が 0 になれば直っています)。
              </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
              <button type="button" class="btn btn-primary" id="km-map-areas-all" disabled>
                <i class="bi bi-palette me-1" aria-hidden="true"></i>すべての階を作り直す
              </button>
              <span class="text-body-secondary fs-7" id="km-map-areas-status" role="status">読み込み中…</span>
            </div>
            <div class="card mb-4">
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                  <thead>
                    <tr>
                      <th>階</th>
                      <th>いまの塗り</th>
                      <th>区画(部屋・廊下・階段・建物・道)</th>
                      <th>確かめ(漏れ・名前なし・あいまい)</th>
                      <th>見本</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="km-map-areas-rows"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
        <script src="<?= km_e(km_public_asset('Main/area-segmentation.js')) ?>"></script>
        <script src="<?= km_e(km_asset('./assets/js/map-areas.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
