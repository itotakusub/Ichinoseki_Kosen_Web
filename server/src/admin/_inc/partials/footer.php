<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}
?>
      </main>
      <!--end::App Main-->
      <!--begin::Footer-->
      <footer class="app-footer">
        <!--begin::To the end-->
        <?php
        /*
         * **接続先をそのまま出す。** 以前はここに「ローカルテスト環境」と
         * 書き固めてあり、本番へ移ったあとも表示だけが取り残されていた
         * (2026-08-29 に利用者から指摘)。
         *
         * km_site_host() は APP_URL 由来なので、環境を移せば表示も一緒に動く。
         * 翻訳しない —— ホスト名は訳す対象ではない。
         */
        ?>
        <div class="float-end d-none d-sm-inline text-body-secondary">
          <?php /* ローカル環境(compose.local.yaml)なら目立たせる。本番と見間違えないため(lib/site.php の km_site_is_local) */ ?>
          <?php if (km_site_is_local()): ?><span class="badge text-bg-warning me-2">ローカル環境</span><?php endif; ?>
          <?= km_e(km_site_host()) ?>
        </div>
        <!--end::To the end-->
        <!--begin::Copyright-->
        <strong data-i18n="footer.product">KosenMap 管理画面</strong>
        <?php // バージョンは数字なので翻訳しない ?>
        <span class="text-body-secondary">v<?= km_e(KM_VERSION) ?></span>
        <span data-i18n="footer.basedOn">— AdminLTE 4 を無編集で利用しています。</span>
        <!--end::Copyright-->
      </footer>
      <!--end::Footer-->
    </div>
    <!--end::App Wrapper-->
<?php require __DIR__ . '/scripts.php'; ?>
