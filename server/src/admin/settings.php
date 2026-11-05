<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';

$KM_PAGE = [
    'nav' => 'settings',
    'titleKey' => 'page.settings.title',
    'title' => '設定 | KosenMap 管理',
    'h1Key' => 'page.settings.h1',
    'h1' => '設定',
    'crumbs' => [
        ['key' => 'page.settings.h1', 'text' => '設定'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-6">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.settings.displayTitle">表示</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <!--begin::Language-->
                    <div class="mb-4">
                      <div class="form-label" data-i18n="page.settings.lang">表示言語</div>
                      <!-- ヘッダーのトグルと同じ属性なので、i18n.js のイベント委譲がそのまま効く -->
                      <div class="btn-group" role="group" aria-label="言語の切り替え" data-i18n-attr="aria-label:a11y.toggleLang">
                        <button type="button" class="btn btn-outline-primary" data-km-lang-value="ja" aria-pressed="true">
                          <i class="bi bi-check-lg me-1 d-none"></i>日本語
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-km-lang-value="en" aria-pressed="false">
                          <i class="bi bi-check-lg me-1 d-none"></i>English
                        </button>
                      </div>
                      <div class="form-text" data-i18n="page.settings.langHint">
                        選択した言語はこのブラウザに保存されます (localStorage の kmadmin-lang)。
                      </div>
                    </div>
                    <!--end::Language-->
                    <!--begin::Theme-->
                    <div class="mb-0">
                      <div class="form-label" data-i18n="page.settings.theme">配色</div>
                      <div class="btn-group" role="group" aria-label="配色の切り替え" data-i18n-attr="aria-label:a11y.toggleTheme">
                        <button type="button" class="btn btn-outline-secondary" data-bs-theme-value="light">
                          <i class="bi bi-sun-fill me-1"></i><span data-i18n="theme.light">ライト</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-theme-value="dark">
                          <i class="bi bi-moon-fill me-1"></i><span data-i18n="theme.dark">ダーク</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-theme-value="auto">
                          <i class="bi bi-circle-half me-1"></i><span data-i18n="theme.auto">自動</span>
                        </button>
                      </div>
                      <div class="form-text" data-i18n="page.settings.themeHint">
                        AdminLTE 本体の機能です (localStorage の lte-theme)。言語設定とは独立しています。
                      </div>
                    </div>
                    <!--end::Theme-->
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->
              </div>

              <div class="col-12 col-xl-6">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.settings.endpointsTitle">接続先</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <div class="mb-3">
                      <label for="km-host" class="form-label" data-i18n="page.settings.host">ホスト</label>
                      <!-- 値は services.js の HOST で上書きされる。ここは JS 前の初期値 -->
                      <input
                        type="text"
                        class="form-control"
                        id="km-host"
                        value="<?= km_e(km_site_host()) ?>"
                        data-km-services-host
                        readonly
                      />
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col" data-i18n="svc.port">ポート</th>
                            <th scope="col" data-i18n="svc.role">役割</th>
                          </tr>
                        </thead>
                        <!-- services.js が SERVICES から行を生成する(言語切り替え時も作り直される) -->
                        <tbody data-km-endpoints></tbody>
                      </table>
                    </div>
                  </div>
                  <!-- /.card-body -->
                  <!-- 「保存」ボタンは置かない。この表は services.js の定義をそのまま映しているだけで、
                       画面から変更できる設定ではない(効かないボタンを置くと在るように見えてしまう) -->
                  <div class="card-footer">
                    <span class="text-body-secondary fs-7" data-i18n="page.settings.endpointsHint"
                      >この一覧は admin/assets/js/services.js の定義から生成しています。変更するにはそのファイルを編集してください。</span
                    >
                  </div>
                </div>
                <!--end::Card-->

                <?php
                /*
                 * Logto Console と phpMyAdmin の開き方(2026-11-02 に Console を SSH の先へ。11-03 に phpMyAdmin も戻した)。
                 * どちらもインターネットに出さず、ホストの 127.0.0.1(3002・8281)だけで待つ(compose.yaml の logto・phpmyadmin)。
                 * **SOCKS のトンネル(ssh -D)を km の鍵で張る** —— kmops の鍵は転送が禁止(no-port-forwarding)で通らない。
                 * 学校の回線は 22 番を通さないので、自宅などから開く。
                 */
                ?>
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.settings.consoleTitle">Logto Console と phpMyAdmin(SSH のトンネルの先)</h3>
                  </div>
                  <div class="card-body">
                    <p class="fs-7" data-i18n="page.settings.consoleHint">
                      どちらもインターネットに出していません。手元の PC で 1 のトンネルを張って開いたままにし、2 の Chrome(トンネルを通す専用のもの)で開きます。デスクトップの「トンネル開通SSH.cmd」は 1 と 2 をまとめて行います。
                    </p>
                    <p class="fs-7 mb-1">1.</p>
                    <pre class="km-api-example mb-2"><code class="user-select-all"><?= km_e(km_site_logto_console_tunnel()) ?></code></pre>
                    <p class="fs-7 mb-1">2.</p>
                    <pre class="km-api-example mb-3"><code class="user-select-all"><?= km_e(km_site_tunnel_chrome()) ?></code></pre>
                    <ul class="fs-7 mb-0">
                      <li>Logto Console: <code><?= km_e(KM_SITE_LOGTO_CONSOLE_URL) ?></code></li>
                      <li>phpMyAdmin: <code><?= km_e(KM_SITE_PHPMYADMIN_URL) ?></code></li>
                    </ul>
                    <p class="fs-7 text-body-secondary mt-3 mb-0" data-i18n="page.settings.consoleNote">
                      ふだんのブラウザでは開けません(トンネルを通していないため)。学校の回線は 443 番しか通さないので、トンネルは自宅などから張ってください。鍵(km_vps)が無い PC からは開けません。
                    </p>
                  </div>
                </div>
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>