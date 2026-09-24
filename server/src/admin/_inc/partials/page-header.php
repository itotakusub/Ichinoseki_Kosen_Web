<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/** @var array $KM_PAGE */
$crumbs = $KM_PAGE['crumbs'] ?? [];
$last = count($crumbs) - 1;
?>
        <!--begin::App Content Header-->
        <div class="app-content-header">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
            <div class="row">
              <div class="col-sm-6">
                <h1 class="mb-0 fs-3" data-i18n="<?= km_e($KM_PAGE['h1Key'] ?? '') ?>">
                  <?= km_e($KM_PAGE['h1'] ?? '') ?>
                </h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="パンくずリスト" data-i18n-attr="aria-label:a11y.breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item">
                      <a href="./index.php" data-i18n="common.home">ホーム</a>
                    </li>
                    <?php foreach ($crumbs as $i => $crumb): ?>
                      <?php if ($i === $last): ?>
                        <li class="breadcrumb-item active" aria-current="page" data-i18n="<?= km_e($crumb['key']) ?>">
                          <?= km_e($crumb['text']) ?>
                        </li>
                      <?php else: ?>
                        <li class="breadcrumb-item" data-i18n="<?= km_e($crumb['key']) ?>">
                          <?= km_e($crumb['text']) ?>
                        </li>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </ol>
                </nav>
              </div>
            </div>
            <!--end::Row-->
            <?php
            /*
             * 補助的な資格情報が壊れて代替へ落ちたときの帯。
             *
             * 落とし込みは「動いてしまう」ので、出さないと誰もログを読むまで気付かない。
             * 2026-08-28 はここが無く、readonly の 403 が管理画面の全面停止として現れた。
             */
            $km_fallback = function_exists('km_logto_m2m_fallback_notice')
                ? km_logto_m2m_fallback_notice()
                : null;
            ?>
            <?php if ($km_fallback !== null): ?>
              <div class="row">
                <div class="col-12">
                  <div class="alert alert-warning mb-0 mt-2" role="alert">
                    <strong>設定の警告:</strong> <?= km_e($km_fallback) ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content Header-->
