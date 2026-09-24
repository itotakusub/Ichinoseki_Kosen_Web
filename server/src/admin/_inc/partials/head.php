<?php
declare(strict_types=1);

// 直接叩かれた場合は存在しないことにする。nginx でも /admin/_ を 404 にしているが、
// nginx を経由しない経路(Apache 直、将来の構成変更)でも守れるようにここでも塞ぐ。
if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/** @var array $KM_PAGE ページごとの見出し・パンくず・サイドバーの選択状態 */
$KM_PAGE = ($KM_PAGE ?? []) + [
    'nav' => '',
    'titleKey' => '',
    'title' => 'KosenMap 管理',
    'bodyClass' => 'layout-fixed sidebar-expand-lg bg-body-tertiary',
];
?>
<!doctype html>
<html lang="ja" data-km-lang="ja"<?= $KM_PAGE['titleKey'] !== '' ? ' data-i18n-title="' . km_e($KM_PAGE['titleKey']) . '"' : '' ?>>
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title><?= km_e($KM_PAGE['title']) ?></title>

    <!--begin::Theme Init (AdminLTE 本体と同じ no-flash スニペット。初回描画前に走らせる)-->
    <script<?= km_csp_nonce_attr() ?>>
      (() => {
        'use strict';
        const root = document.documentElement;

        if (root.getAttribute('data-lte-color-mode') === 'off') {
          return;
        }

        const STORAGE_KEY = 'lte-theme';
        let stored = null;
        try {
          stored = localStorage.getItem(STORAGE_KEY);
        } catch {
          // localStorage may be unavailable (private mode, sandboxed iframe).
        }
        const authored = root.getAttribute('data-bs-theme');
        let resolved = 'light';
        if (stored === 'dark' || stored === 'light') {
          resolved = stored;
        } else if (authored === 'dark' || authored === 'light') {
          resolved = authored;
        } else if (globalThis.matchMedia('(prefers-color-scheme: dark)').matches) {
          resolved = 'dark';
        }
        root.setAttribute('data-bs-theme', resolved);
        root.style.colorScheme = resolved;
        if (resolved !== authored) {
          root.setAttribute('data-lte-theme-resolved', '');
        }
      })();
    </script>
    <!--end::Theme Init-->

    <!--begin::Language Init (テーマと同じ形で、初回描画前に <html lang> を確定させる)-->
    <script<?= km_csp_nonce_attr() ?>>
      (() => {
        'use strict';
        const root = document.documentElement;
        let stored = null;
        try {
          stored = localStorage.getItem('kmadmin-lang');
        } catch {
          // localStorage が使えない環境では宣言値のまま進める
        }
        const lang =
          stored === 'ja' || stored === 'en'
            ? stored
            : (navigator.language || '').toLowerCase().startsWith('ja')
              ? 'ja'
              : 'en';
        root.setAttribute('lang', lang);
        root.setAttribute('data-km-lang', lang);
      })();
    </script>
    <!--end::Language Init-->

    <!--begin::Site Host(公開ホスト名を JS へ渡す)-->
    <?php /* 出所は lib/site.php(APP_URL)。services.js とチャットの接続先がこれを見る。
             JS 側に直書きしていると、ドメインを変えたときにここだけ古い宛先を指し続ける。 */ ?>
    <script<?= km_csp_nonce_attr() ?>>
      <?php /* <script> の中へ書くので HEX 系のフラグを付ける。値は設定由来でも、</script> を作らせない(chat.php と揃える) */ ?>
      window.KM_SITE_HOST = <?= json_encode(km_site_host(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      /* 各サービスの URL とポートも PHP 側で決める。**JS でポート番号を組み立てない** —
         8080 / 9443 は校内 LAN 専用で、VPS では 80 / 443 になるため。 */
      window.KM_SITE_SERVICES = <?= json_encode(km_site_service_map(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <!--end::Site Host-->

    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <meta name="robots" content="noindex, nofollow" />
    <!--end::Accessibility Meta Tags-->

    <?php if (session_status() === PHP_SESSION_ACTIVE): ?>
      <!-- fetch で POST する API 用。JS はこれを読んで X-KM-CSRF ヘッダーに載せる
           (通常のフォームは km_csrf_field() の hidden を使う)。
           login.php / 403.php などガード外のページはセッションを開始しないので、
           意味のないトークンを出さないようここで出し分ける。 -->
      <meta name="km-csrf" content="<?= km_e(km_csrf_token()) ?>" />
    <?php endif; ?>

    <?php
    /*
     * ファビコン。**絶対パスで書く。** 管理画面は /admin/ の下にあるので、
     * 相対で書くと /admin/favicon.svg を探しに行って 404 になる。
     *
     * 置いていなかった間、ブラウザは /favicon.ico を取りに行って 404 を貰い続けていた
     * (本番のアクセスログに残っている)。SVG 1枚で全サイズに効く。
     */
    ?>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml" />
    <link rel="preload" href="./vendor/adminlte/css/adminlte.css" as="style" />

    <!--begin::Fonts-->
    <link rel="stylesheet" href="./vendor/source-sans-3/index.css" />
    <!--end::Fonts-->

    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <link rel="stylesheet" href="./vendor/overlayscrollbars/overlayscrollbars.min.css" />
    <!--end::Third Party Plugin(OverlayScrollbars)-->

    <!--begin::Third Party Plugin(Bootstrap Icons)-->
    <link rel="stylesheet" href="./vendor/bootstrap-icons/bootstrap-icons.min.css" />
    <!--end::Third Party Plugin(Bootstrap Icons)-->

    <!--begin::Required Plugin(AdminLTE)-->
    <link rel="stylesheet" href="./vendor/adminlte/css/adminlte.css" />
    <!--end::Required Plugin(AdminLTE)-->

    <!--begin::KosenMap Admin(自前の追加スタイル。AdminLTE の後に読む)-->
    <?php // CSP を厳格にして style="…" が使えなくなったぶんの行き先。km_asset() でキャッシュを破棄する ?>
    <link rel="stylesheet" href="<?= km_e(km_asset('./assets/css/km-admin.css')) ?>" />
    <!--end::KosenMap Admin-->
  </head>
  <!--end::Head-->
  <!--begin::Body-->
  <body class="<?= km_e($KM_PAGE['bodyClass']) ?>">
    <!--begin::Skip Links-->
    <!-- adminlte.js の addSkipLinks() は .skip-links が既にあれば何もしない
         (vendor/adminlte/js/adminlte.js:1028)。先に日本語で書いておくことで、
         AdminLTE 本体を編集せずに英語の自動挿入を防いでいる。 -->
    <div class="skip-links">
      <a href="#main" class="skip-link" data-i18n="a11y.skipMain">メインコンテンツへスキップ</a>
      <?php if (($KM_PAGE['layout'] ?? 'app') === 'app'): ?>
        <a href="#navigation" class="skip-link" data-i18n="a11y.skipNav">ナビゲーションへスキップ</a>
      <?php endif; ?>
    </div>
    <!--end::Skip Links-->
