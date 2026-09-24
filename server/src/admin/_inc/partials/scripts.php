<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

// サイドバーを持つページだけ OverlayScrollbars と services.js が要る
$isApp = ($KM_PAGE['layout'] ?? 'app') === 'app';
?>
    <!--begin::Script-->
    <?php if ($isApp): ?>
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <script src="./vendor/overlayscrollbars/overlayscrollbars.browser.es6.min.js"></script>
    <!--end::Third Party Plugin(OverlayScrollbars)-->
    <?php endif; ?>
    <!--begin::Required Plugin(popperjs for Bootstrap 5)-->
    <script src="./vendor/popperjs/popper.min.js"></script>
    <!--end::Required Plugin(popperjs for Bootstrap 5)-->
    <!--begin::Required Plugin(Bootstrap 5)-->
    <script src="./vendor/bootstrap/bootstrap.min.js"></script>
    <!--end::Required Plugin(Bootstrap 5)-->
    <!--begin::Required Plugin(AdminLTE)-->
    <script src="./vendor/adminlte/js/adminlte.js"></script>
    <!--end::Required Plugin(AdminLTE)-->
    <?php if ($isApp): ?>
    <!--begin::OverlayScrollbars Configure-->
    <script<?= km_csp_nonce_attr() ?>>
      const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
      const Default = {
        scrollbarTheme: 'os-theme-light',
        scrollbarAutoHide: 'leave',
        scrollbarClickScroll: true,
      };
      document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);

        // Disable OverlayScrollbars on mobile devices to prevent touch interference
        const isMobile = window.innerWidth <= 992;

        if (
          sidebarWrapper &&
          OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined &&
          !isMobile
        ) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: {
              theme: Default.scrollbarTheme,
              autoHide: Default.scrollbarAutoHide,
              clickScroll: Default.scrollbarClickScroll,
            },
          });
        }
      });
    </script>
    <!--end::OverlayScrollbars Configure-->
    <?php endif; ?>
    <!--begin::KosenMap Admin(送信前の確認)-->
    <?php
    /*
     * **取り返しのつかない送信の前に確認を出す。** フォームに `data-km-confirm="文言"` を書く。
     *
     * 以前は各ページが `onsubmit="return confirm(…)"` と書いていたが、
     * 管理画面の CSP は script-src が 'self' と nonce だけなので、**インラインの属性は動かず、
     * 確認を出さないまま送っていた**(host-facts: map-publish.php で実測)。
     * 1箇所でまとめて受ければ、ページごとに nonce 付きの script を書かずに済む。
     *
     * **`data-km-confirm-fallback` も持つフォームは受けない。** その形は map-publish.php が
     * data-km-confirm に**辞書のキー**を入れ、自分の script で訳して確認を出している。
     * ここでも受けると確認が2回出て、2回目は「page.mapPublish.…」というキーそのものが出る。
     */
    ?>
    <script<?= km_csp_nonce_attr() ?>>
      document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.kmConfirmFallback !== undefined) {
          return;
        }
        const message = form.dataset.kmConfirm;
        if (message && !window.confirm(message)) {
          event.preventDefault();
        }
      });
    </script>
    <!--end::KosenMap Admin(送信前の確認)-->
    <!--begin::KosenMap Admin(i18n)-->
    <?php // km_asset() が更新時刻を付ける。付けないと配備しても古いものがブラウザに残る ?>
    <script src="<?= km_e(km_asset('./assets/i18n/ja.js')) ?>"></script>
    <script src="<?= km_e(km_asset('./assets/i18n/en.js')) ?>"></script>
    <script src="<?= km_e(km_asset('./assets/js/i18n.js')) ?>"></script>
    <?php if ($isApp): ?>
    <script src="<?= km_e(km_asset('./assets/js/services.js')) ?>"></script>
    <?php endif; ?>
    <!--end::KosenMap Admin(i18n)-->
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
