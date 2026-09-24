<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/bootstrap.php';

http_response_code(404);

$KM_PAGE = [
    'layout' => 'plain',
    'bodyClass' => 'bg-body-tertiary',
    'titleKey' => 'page.e404.title',
    'title' => '404 ページが見つかりません | KosenMap 管理',
];

require __DIR__ . '/_inc/partials/head.php';
?>
    <main class="d-flex align-items-center min-vh-100 py-5" id="main">
      <div class="container">
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6 text-center">
            <div class="display-1 fw-bold text-primary lh-1 mb-3">404</div>
            <h1 class="h3 mb-3" data-i18n="page.e404.heading">ページが見つかりません</h1>
            <p class="text-secondary mb-4" data-i18n="page.e404.text">
              お探しのページは存在しないか、移動した可能性があります。ダッシュボードへ戻ってください。
            </p>

            <!--begin::Language Toggle-->
            <div class="mb-4">
              <div
                class="btn-group btn-group-sm"
                role="group"
                aria-label="言語の切り替え"
                data-i18n-attr="aria-label:a11y.toggleLang"
              >
                <button type="button" class="btn btn-outline-secondary" data-km-lang-value="ja" aria-pressed="true">
                  <i class="bi bi-check-lg me-1 d-none"></i>日本語
                </button>
                <button type="button" class="btn btn-outline-secondary" data-km-lang-value="en" aria-pressed="false">
                  <i class="bi bi-check-lg me-1 d-none"></i>English
                </button>
              </div>
            </div>
            <!--end::Language Toggle-->

            <a href="./index.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
              <span data-i18n="common.backToDashboard">ダッシュボードへ戻る</span>
            </a>
          </div>
        </div>
      </div>
    </main>
<?php require __DIR__ . '/_inc/partials/scripts.php'; ?>
