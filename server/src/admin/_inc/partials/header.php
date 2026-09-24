<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/** @var array|null $KM_USER guard.php が入れる。未認証ページでは null */
$user = $KM_USER ?? null;
$displayName = $user['name'] ?? '';
$roleLabel = $user['roles'] ? implode(', ', $user['roles']) : '';

/*
 * 通知ドロップダウン。
 *
 * 以前はここに「死活監視は未接続です」「MariaDB 連携は未接続です」という固定の2件と、
 * バッジの固定値 2 が書いてあった。どちらも実装済みになった今は**画面が嘘をついている**
 * 状態だったので、実データに差し替える。
 *
 * 出すのは軽い COUNT(*) 2本だけ(どちらもインデックス付き)。ヘッダーは全ページで読まれるので、
 * ここに重い処理は置かない。**DB が落ちていてもヘッダーは必ず出す**必要があるため、
 * 失敗したら件数を null にして「取得できません」と表示するだけにする(fail soft)。
 */
$unreadForms = null;
$recentActions = null;
$unreadChat = null;
if ($user !== null) {
    try {
        require_once dirname(__DIR__, 3) . '/lib/db.php';
        require_once dirname(__DIR__, 3) . '/lib/forms.php';
        require_once dirname(__DIR__, 3) . '/lib/admin-log.php';

        $notifyPdo = km_db();
        $unreadForms = km_form_unread_count($notifyPdo);
        $recentActions = km_admin_log_count_since($notifyPdo, 1);
        require_once dirname(__DIR__, 3) . '/lib/chat.php';
        $unreadChat = km_chat_unread_badge((string) ($user['sub'] ?? ''));
    } catch (Throwable $exception) {
        error_log('header.php notifications failed (fail soft): ' . $exception->getMessage());
    }
}

/*
 * プロフィール画像。設定されていれば自前の配信経路、無ければ Logto の画像、
 * それも無ければ AdminLTE 同梱の既定画像。km_profile_avatar_src() 自体が
 * 失敗しても既定へ落ちるので、ここでの try/catch は要らない。
 */
require_once dirname(__DIR__, 3) . '/lib/profile.php';
$avatarSrc = $user !== null ? km_profile_avatar_src($user) : KM_AVATAR_FALLBACK;
// バッジは「手当てが要るもの」だけ = 未読の問い合わせとチャット。
// 操作件数は情報であって用件ではないので数えない。
$notifyBadge = ($unreadForms ?? 0) + ($unreadChat ?? 0);
?>
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-body">
        <!--begin::Container-->
        <div class="container-fluid">
          <!--begin::Start Navbar Links-->
          <ul class="navbar-nav">
            <li class="nav-item">
              <a
                class="nav-link"
                data-lte-toggle="sidebar"
                href="#"
                role="button"
                aria-label="サイドバーの開閉"
                data-i18n-attr="aria-label:a11y.toggleSidebar"
              >
                <i class="bi bi-list"></i>
              </a>
            </li>

            <li class="nav-item d-none d-md-block">
              <a href="./index.php" class="nav-link">
                <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>
                <span data-i18n="nav.dashboard">ダッシュボード</span>
              </a>
            </li>
            <li class="nav-item d-none d-md-block">
              <a href="./monitor.php" class="nav-link">
                <i class="bi bi-activity me-1" aria-hidden="true"></i>
                <span data-i18n="nav.monitor">サービス監視</span>
              </a>
            </li>
          </ul>
          <!--end::Start Navbar Links-->

          <!--begin::End Navbar Links-->
          <ul class="navbar-nav ms-auto">
            <!--begin::Notifications Dropdown Menu-->
            <li class="nav-item dropdown">
              <a
                class="nav-link"
                data-bs-toggle="dropdown"
                href="#"
                aria-label="システム通知"
                data-i18n-attr="aria-label:nav.notifications.title"
              >
                <i class="bi bi-bell-fill"></i>
                <?php if ($notifyBadge > 0): ?>
                  <span class="navbar-badge badge text-bg-warning"><?= (int) $notifyBadge ?></span>
                <?php endif; ?>
              </a>
              <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <span class="dropdown-item dropdown-header" data-i18n="nav.notifications.title"
                  >システム通知</span
                >
                <div class="dropdown-divider"></div>
                <a href="./mailbox.php" class="dropdown-item">
                  <i class="bi bi-envelope me-2"></i>
                  <span data-i18n="nav.notifications.unreadForms">未読の問い合わせ</span>
                  <span class="float-end text-secondary fs-7">
                    <?= $unreadForms === null ? '&mdash;' : (int) $unreadForms ?>
                  </span>
                </a>
                <div class="dropdown-divider"></div>
                <a href="./chat.php" class="dropdown-item">
                  <i class="bi bi-chat-dots me-2"></i>
                  <span data-i18n="nav.notifications.unreadChat">未読のチャット</span>
                  <span class="float-end text-secondary fs-7">
                    <?= $unreadChat === null ? '&mdash;' : (int) $unreadChat ?>
                  </span>
                </a>
                <div class="dropdown-divider"></div>
                <a href="./timeline.php" class="dropdown-item">
                  <i class="bi bi-clock-history me-2"></i>
                  <span data-i18n="nav.notifications.recentActions">直近24時間の操作</span>
                  <span class="float-end text-secondary fs-7">
                    <?= $recentActions === null ? '&mdash;' : (int) $recentActions ?>
                  </span>
                </a>
                <?php if ($unreadForms === null): ?>
                  <div class="dropdown-divider"></div>
                  <span class="dropdown-item text-body-secondary fs-7" data-i18n="nav.notifications.dbError">
                    データベースに接続できないため件数を取得できません。
                  </span>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="./timeline.php" class="dropdown-item dropdown-footer" data-i18n="nav.notifications.all">
                  すべての操作履歴を見る
                </a>
              </div>
            </li>
            <!--end::Notifications Dropdown Menu-->

            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
              <a
                class="nav-link"
                href="#"
                data-lte-toggle="fullscreen"
                aria-label="全画面表示の切り替え"
                data-i18n-attr="aria-label:a11y.toggleFullscreen"
              >
                <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                <i data-lte-icon="minimize" class="bi bi-fullscreen-exit d-none"></i>
              </a>
            </li>
            <!--end::Fullscreen Toggle-->

            <!--begin::Color Mode Toggle (#6010)-->
            <li class="nav-item dropdown">
              <a
                class="nav-link"
                href="#"
                id="bd-theme"
                aria-label="配色の切り替え"
                data-i18n-attr="aria-label:a11y.toggleTheme"
                data-bs-toggle="dropdown"
                aria-expanded="false"
              >
                <i class="bi bi-sun-fill" data-lte-theme-icon="light"></i>
                <i class="bi bi-moon-fill d-none" data-lte-theme-icon="dark"></i>
                <i class="bi bi-circle-half d-none" data-lte-theme-icon="auto"></i>
              </a>
              <ul
                class="dropdown-menu dropdown-menu-end km-dropdown-8"
                aria-labelledby="bd-theme"
              >
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center"
                    data-bs-theme-value="light"
                    aria-pressed="false"
                  >
                    <i class="bi bi-sun-fill me-2"></i>
                    <span data-i18n="theme.light">ライト</span>
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center"
                    data-bs-theme-value="dark"
                    aria-pressed="false"
                  >
                    <i class="bi bi-moon-fill me-2"></i>
                    <span data-i18n="theme.dark">ダーク</span>
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center active"
                    data-bs-theme-value="auto"
                    aria-pressed="true"
                  >
                    <i class="bi bi-circle-half me-2"></i>
                    <span data-i18n="theme.auto">自動</span>
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
              </ul>
            </li>
            <!--end::Color Mode Toggle-->

            <!--begin::Language Toggle (ColorMode と同じ構造・別の属性名で衝突を避ける)-->
            <li class="nav-item dropdown">
              <a
                class="nav-link"
                href="#"
                id="km-lang"
                aria-label="言語の切り替え"
                data-i18n-attr="aria-label:a11y.toggleLang"
                data-bs-toggle="dropdown"
                aria-expanded="false"
              >
                <i class="bi bi-translate"></i>
                <span class="ms-1 d-none d-sm-inline" data-km-lang-label>日本語</span>
              </a>
              <ul
                class="dropdown-menu dropdown-menu-end km-dropdown-9"
                aria-labelledby="km-lang"
              >
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center"
                    data-km-lang-value="ja"
                    aria-pressed="true"
                  >
                    日本語
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center"
                    data-km-lang-value="en"
                    aria-pressed="false"
                  >
                    English
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
              </ul>
            </li>
            <!--end::Language Toggle-->

            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
              <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <img
                  src="<?= km_e($avatarSrc) ?>"
                  class="user-image rounded-circle shadow"
                  alt=""
                />
                <span class="d-none d-md-inline"><?= km_e($displayName) ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <!--begin::User Image-->
                <li class="user-header text-bg-primary">
                  <img
                    src="<?= km_e($avatarSrc) ?>"
                    class="rounded-circle shadow"
                    alt=""
                  />
                  <p>
                    <?= km_e($displayName) ?>
                    <small><?= km_e($user['email'] ?? '') ?></small>
                  </p>
                </li>
                <!--end::User Image-->
                <!--begin::Menu Body-->
                <li class="user-body">
                  <!--begin::Row-->
                  <div class="row">
                    <div class="col-6 text-center">
                      <span class="text-body-secondary fs-7" data-i18n="user.role">ロール</span>
                      <br />
                      <strong><?= $roleLabel !== '' ? km_e($roleLabel) : '&mdash;' ?></strong>
                    </div>
                    <div class="col-6 text-center">
                      <span class="text-body-secondary fs-7" data-i18n="user.userId">ユーザーID</span>
                      <br />
                      <code class="fs-7"><?= km_e($user['sub'] ?? '—') ?></code>
                    </div>
                  </div>
                  <!--end::Row-->
                </li>
                <!--end::Menu Body-->
                <!--begin::Menu Footer-->
                <li class="user-footer">
                  <a href="./settings.php" class="btn btn-outline-secondary" data-i18n="user.settings">設定</a>
                  <?php // POST + CSRF でだけ出られる(GET だと他サイトのリンクで追い出せた。sign-out.php の説明) ?>
                  <form method="post" action="/sign-out.php" class="d-inline float-end m-0">
                    <?= km_csrf_field() ?>
                    <button type="submit" class="btn btn-outline-danger" data-i18n="user.signOut">
                      ログアウト
                    </button>
                  </form>
                </li>
                <!--end::Menu Footer-->
              </ul>
            </li>
            <!--end::User Menu Dropdown-->
          </ul>
          <!--end::End Navbar Links-->
        </div>
        <!--end::Container-->
      </nav>
      <!--end::Header-->
