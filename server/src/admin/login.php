<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/bootstrap.php';

$KM_PAGE = [
    'layout' => 'plain',
    'bodyClass' => 'login-page bg-body-secondary',
    'titleKey' => 'page.login.title',
    'title' => 'ログイン | KosenMap 管理',
];

// 管理画面からは新規登録ではなくサインイン画面へ入る。
// 一般向けサイトの導線 (mode 指定なし) は現状のままにしてある。
$signInUrl = '/sign-in.php?mode=signIn&return=' . rawurlencode('/admin/');

require __DIR__ . '/_inc/partials/head.php';
?>
    <main class="login-box" id="main">
      <h1 class="login-logo">
        <a href="./index.php" data-i18n-html="page.login.logo"><b>KosenMap</b> 管理</a>
      </h1>
      <!-- /.login-logo -->
      <div class="card">
        <div class="card-body login-card-body">
          <p class="login-box-msg" data-i18n="page.login.msg">Logto アカウントでログインします</p>

          <div class="d-grid gap-2 mb-3">
            <a href="<?= km_e($signInUrl) ?>" class="btn btn-primary btn-lg">
              <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>
              <span data-i18n="page.login.button">Logto でログイン</span>
            </a>
          </div>

          <!-- パスワード入力欄は置かない。認証情報は Logto の画面だけで扱う。 -->
          <p class="text-body-secondary fs-7" data-i18n="page.login.credentialsNote">
            この画面ではパスワードを入力しません。認証情報の入力は Logto のログイン画面で行います。
          </p>

          <div class="alert alert-secondary fs-7 mb-3" role="alert" data-i18n="page.login.roleNote">
            管理画面に入るには Logto で kosenmap-admin ロールが割り当てられている必要があります。
          </div>

          <hr />

          <!--begin::Language Toggle-->
          <div class="text-center mb-3">
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
        </div>
        <!-- /.login-card-body -->
      </div>
    </main>
    <!-- /.login-box -->
<?php require __DIR__ . '/_inc/partials/scripts.php'; ?>
