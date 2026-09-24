<?php

declare(strict_types=1);

/**
 * 停止されたアカウントに見せる画面。
 *
 * **guard.php を通さない。** 通すと、この画面を出そうとして再び停止判定に入り、
 * 無限に転送し合うことになる。ここは認証を要求しない静的な案内に徹する
 * (403.php も同じ考え方で bootstrap.php だけを読んでいる)。
 *
 * 停止の判定そのものは guard.php / gate.php / logto_guard.php が行う。
 * この画面に来られたからといって、何かが開くわけではない。
 */

define('KM_ADMIN', true);
require __DIR__ . '/_inc/bootstrap.php';

http_response_code(403);

$KM_PAGE = [
    'layout' => 'plain',
    'bodyClass' => 'bg-body-tertiary',
    'titleKey' => 'page.suspended.title',
    'title' => 'アカウントが停止されています | KosenMap 管理',
];

require __DIR__ . '/_inc/partials/head.php';
?>
    <main class="d-flex align-items-center min-vh-100 py-5" id="main">
      <div class="container">
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6 text-center">
            <div class="display-1 fw-bold text-danger lh-1 mb-3">
              <i class="bi bi-person-slash" aria-hidden="true"></i>
            </div>
            <h1 class="h3 mb-3" data-i18n="page.suspended.heading">アカウントが停止されています</h1>
            <p class="text-secondary mb-4" data-i18n="page.suspended.text">
              このアカウントは管理者によって停止されました。管理画面は利用できません。
              心当たりがない場合は、管理者にお問い合わせください。
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

            <a href="/" class="btn btn-outline-secondary">
              <i class="bi bi-map me-1" aria-hidden="true"></i>
              <span data-i18n="page.suspended.toMap">地図へ戻る</span>
            </a>
          </div>
        </div>
      </div>
    </main>
<?php require __DIR__ . '/_inc/partials/scripts.php'; ?>
