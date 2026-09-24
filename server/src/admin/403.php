<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/bootstrap.php';
/*
 * ログアウトのフォームに CSRF トークンを載せるため、セッションを開く
 * (このページは guard.php を通らないので、ほかに開く場所が無い)。
 */
require_once dirname(__DIR__) . '/lib/session.php';
km_session_start();

http_response_code(403);

$KM_PAGE = [
    'layout' => 'plain',
    'bodyClass' => 'bg-body-tertiary',
    'titleKey' => 'page.e403.title',
    'title' => '403 権限がありません | KosenMap 管理',
];

require __DIR__ . '/_inc/partials/head.php';
?>
    <main class="d-flex align-items-center min-vh-100 py-5" id="main">
      <div class="container">
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6 text-center">
            <div class="display-1 fw-bold text-warning lh-1 mb-3">403</div>
            <h1 class="h3 mb-3" data-i18n="page.e403.heading">この画面を開く権限がありません</h1>
            <p class="text-secondary mb-4" data-i18n="page.e403.text">
              ログインはできていますが、管理者の権限が割り当てられていません。Logto Console で
              kosenmap-admin ロールを付与してから、ログインし直してください。
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

            <?php // POST + CSRF でだけ出られる(sign-out.php の説明) ?>
            <form method="post" action="/sign-out.php" class="d-inline">
              <?= km_csrf_field() ?>
              <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>
                <span data-i18n="user.signOut">ログアウト</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </main>
<?php require __DIR__ . '/_inc/partials/scripts.php'; ?>
