<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

$nav = $KM_PAGE['nav'] ?? '';

/*
 * チャットの未読件数。km_chat_unread_badge() は1リクエスト内で1回しか数えず、
 * DB が落ちていれば null を返す(そのときはバッジを出さない = 画面は壊れない)。
 */
require_once dirname(__DIR__, 3) . '/lib/chat.php';
$chatUnread = km_chat_unread_badge((string) ($KM_USER['sub'] ?? ''));

// どの親メニューを開くか。フェーズ1では組み立てスクリプトでページごとに
// active / menu-open を書き込んでいたが、ここで一元化した。
$openMenus = [
    'tables' => 'database',
    'mapSettings' => 'database',
    'mapSync' => 'database',
    'mapPublish' => 'database',
    'mapEditor' => 'database',
    'mapEvents' => 'database',
    'login' => 'auth',
    'projects' => 'content',
    'timeline' => 'content',
    'charts' => 'content',
    'mailbox' => 'content',
    'profile' => 'extra',
    // FAQ は見本ページではなく、編集して公開する実コンテンツになった(フェーズ14)
    'faq' => 'content',
    // 規約とポリシーへ足す章。本文はコードのまま(admin/legal.php の説明を参照)
    'legal' => 'content',
    'calendar' => 'extra',
    'kanban' => 'extra',
    'chat' => 'extra',
    'filemanager' => 'extra',
];
$openMenu = $openMenus[$nav] ?? '';

/** サイドバーの子項目に付けるクラス */
$link = static fn(string $id): string => 'nav-link' . ($nav === $id ? ' active' : '');
/** 親項目(展開する側)に付けるクラス */
$parentItem = static fn(string $id): string => 'nav-item' . ($openMenu === $id ? ' menu-open' : '');
$parentLink = static fn(string $id): string => 'nav-link' . ($openMenu === $id ? ' active' : '');
?>
      <!--begin::Sidebar-->
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <!--begin::Sidebar Brand-->
        <div class="sidebar-brand">
          <!--begin::Brand Link-->
          <a href="./index.php" class="brand-link">
            <?php // ロゴは自前のもの。AdminLTE の見本ロゴのままだと、どのサイトか分からない ?>
            <!--begin::Brand Image-->
            <img
              src="/favicon.svg"
              alt=""
              class="brand-image opacity-75 shadow"
            />
            <!--end::Brand Image-->
            <!--begin::Brand Text-->
            <span class="brand-text fw-light" data-i18n="side.brand">KosenMap 管理</span>
            <!--end::Brand Text-->
          </a>
          <!--end::Brand Link-->
        </div>
        <!--end::Sidebar Brand-->
        <!--begin::Sidebar Wrapper-->
        <div class="sidebar-wrapper">
          <nav
            class="mt-2"
            aria-label="メインナビゲーション"
            data-i18n-attr="aria-label:a11y.mainNav"
          >
            <!--begin::Sidebar Menu-->
            <ul
              class="nav sidebar-menu flex-column"
              data-lte-toggle="treeview"
              data-accordion="false"
              id="navigation"
            >
              <li class="nav-header" data-i18n="side.headerMain">メイン</li>
              <li class="nav-item">
                <a href="./index.php" class="<?= $link('index') ?>">
                  <i class="nav-icon bi bi-speedometer2"></i>
                  <p data-i18n="side.dashboard">ダッシュボード</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="./users.php" class="<?= $link('users') ?>">
                  <i class="nav-icon bi bi-people-fill"></i>
                  <p data-i18n="side.users">ユーザー管理</p>
                </a>
              </li>
              <?php // 教職員の権限の申請(docs/15。2026-09-18)。承認すると教職員氏名と閲覧不可の地点が見えるようになる ?>
              <li class="nav-item">
                <a href="./staff-requests.php" class="<?= $link('staffRequests') ?>">
                  <i class="nav-icon bi bi-person-badge"></i>
                  <p data-i18n="side.staffRequests">教職員の申請</p>
                </a>
              </li>
              <?php // 教職員の地点(docs/15 段 G)。割り当てと、教職員が送った変更の確認 ?>
              <li class="nav-item">
                <a href="./staff-nodes.php" class="<?= $link('staffNodes') ?>">
                  <i class="nav-icon bi bi-geo-alt"></i>
                  <p data-i18n="side.staffNodes">教職員の地点</p>
                </a>
              </li>
              <li class="<?= $parentItem('database') ?>">
                <a href="#" class="<?= $parentLink('database') ?>">
                  <i class="nav-icon bi bi-database-fill"></i>
                  <p data-i18n="side.database">
                    データベース
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./tables.php" class="<?= $link('tables') ?>">
                      <i class="nav-icon bi bi-table"></i>
                      <p data-i18n="side.tables">テーブル管理</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./map-sync.php" class="<?= $link('mapSync') ?>">
                      <i class="nav-icon bi bi-arrow-repeat"></i>
                      <p data-i18n="side.mapSync">アプリの地図を取り込む</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./map-publish.php" class="<?= $link('mapPublish') ?>">
                      <i class="nav-icon bi bi-broadcast"></i>
                      <p data-i18n="side.mapPublish">アプリへ地図を配信する</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./map-editor.php" class="<?= $link('mapEditor') ?>">
                      <i class="nav-icon bi bi-pin-map"></i>
                      <p data-i18n="side.mapEditor">地図編集</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./map-events.php" class="<?= $link('mapEvents') ?>">
                      <i class="nav-icon bi bi-cone-striped"></i>
                      <p data-i18n="side.mapEvents">イベントモード</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./map-settings.php" class="<?= $link('mapSettings') ?>">
                      <i class="nav-icon bi bi-shield-lock"></i>
                      <p data-i18n="side.mapSettings">地図データ公開設定</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a
                      href="<?= km_e(km_site_url('phpmyadmin', '/')) ?>"
                      class="nav-link"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i class="nav-icon bi bi-box-arrow-up-right"></i>
                      <p data-i18n="side.phpmyadmin">phpMyAdmin</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="./monitor.php" class="<?= $link('monitor') ?>">
                  <i class="nav-icon bi bi-activity"></i>
                  <p data-i18n="side.monitor">サービス監視</p>
                </a>
              </li>

              <li class="nav-header" data-i18n="side.headerContent">コンテンツ</li>
              <li class="<?= $parentItem('content') ?>">
                <a href="#" class="<?= $parentLink('content') ?>">
                  <i class="nav-icon bi bi-grid-3x3-gap-fill"></i>
                  <p data-i18n="side.content">
                    コンテンツ
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./projects.php" class="<?= $link('projects') ?>">
                      <i class="nav-icon bi bi-kanban"></i>
                      <p data-i18n="side.projects">プロジェクト状況</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./timeline.php" class="<?= $link('timeline') ?>">
                      <i class="nav-icon bi bi-clock-history"></i>
                      <p data-i18n="side.timeline">タイムライン</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./charts.php" class="<?= $link('charts') ?>">
                      <i class="nav-icon bi bi-bar-chart-fill"></i>
                      <p data-i18n="side.charts">チャート</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./mailbox.php" class="<?= $link('mailbox') ?>">
                      <i class="nav-icon bi bi-envelope-fill"></i>
                      <p data-i18n="side.mailbox">受信箱</p>
                    </a>
                  </li>
                  <?php // 問い合わせフォームは公開ページ(/contact.php)へ一本化した(フェーズ17)。
                        // 受信箱はここに残る ?>
                  <li class="nav-item">
                    <a href="./faq.php" class="<?= $link('faq') ?>">
                      <i class="nav-icon bi bi-question-circle"></i>
                      <p data-i18n="side.faq">FAQ</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./legal.php" class="<?= $link('legal') ?>">
                      <i class="nav-icon bi bi-file-earmark-text"></i>
                      <p data-i18n="side.legal">規約とポリシー</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="./downloads.php" class="<?= $link('downloads') ?>">
                  <i class="nav-icon bi bi-download"></i>
                  <p data-i18n="side.downloads">ダウンロード</p>
                </a>
              </li>
              <li class="<?= $parentItem('extra') ?>">
                <a href="#" class="<?= $parentLink('extra') ?>">
                  <i class="nav-icon bi bi-collection-fill"></i>
                  <p data-i18n="side.extra">
                    その他ページ
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./profile.php" class="<?= $link('profile') ?>">
                      <i class="nav-icon bi bi-person-circle"></i>
                      <p data-i18n="side.profile">プロフィール</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./calendar.php" class="<?= $link('calendar') ?>">
                      <i class="nav-icon bi bi-calendar3"></i>
                      <p data-i18n="side.calendar">カレンダー</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./kanban.php" class="<?= $link('kanban') ?>">
                      <i class="nav-icon bi bi-kanban-fill"></i>
                      <p data-i18n="side.kanban">かんばん</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./chat.php" class="<?= $link('chat') ?>">
                      <i class="nav-icon bi bi-chat-dots-fill"></i>
                      <p data-i18n="side.chat">
                        チャット
                        <?php // チャット画面を開いている間は JS がこの数字を直接書き替える ?>
                        <span
                          class="nav-badge badge text-bg-danger me-3<?= ($chatUnread ?? 0) > 0 ? '' : ' d-none' ?>"
                          data-km-chat-unread
                        ><?= (int) ($chatUnread ?? 0) ?></span>
                      </p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./file-manager.php" class="<?= $link('filemanager') ?>">
                      <i class="nav-icon bi bi-folder-fill"></i>
                      <p data-i18n="side.fileManager">ファイル管理</p>
                    </a>
                  </li>
                  <?php
                  /*
                   * ここにあった「請求書(見本)」「サービス比較」「メンテナンス中」は
                   * 1.0.5 で消した。**3枚とも DB に繋がっていない AdminLTE のデモ**で、
                   * 実機能と並んでいると管理者が取り違える(「請求書?」と聞かれた)。
                   *
                   * 原本は `admin/vendor/adminlte/` に在るので、組み込み方の参照が
                   * 要るときはそちらを見る。
                   */
                  ?>
                </ul>
              </li>

              <li class="nav-header" data-i18n="side.headerAuth">連携サービス</li>
              <li class="<?= $parentItem('auth') ?>">
                <a href="#" class="<?= $parentLink('auth') ?>">
                  <i class="nav-icon bi bi-shield-lock-fill"></i>
                  <p data-i18n="side.auth">
                    認証 (Logto)
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a
                      href="<?= km_e(km_site_url('logto-admin', '/')) ?>"
                      class="nav-link"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i class="nav-icon bi bi-box-arrow-up-right"></i>
                      <p data-i18n="side.logtoAdmin">管理コンソール</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a
                      href="<?= km_e(km_site_url('logto-core', '/')) ?>"
                      class="nav-link"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i class="nav-icon bi bi-box-arrow-up-right"></i>
                      <p data-i18n="side.logtoCore">認証エンドポイント</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="./settings.php" class="<?= $link('settings') ?>">
                  <i class="nav-icon bi bi-gear-fill"></i>
                  <p data-i18n="side.settings">設定</p>
                </a>
              </li>
            </ul>
            <!--end::Sidebar Menu-->
          </nav>
        </div>
        <!--end::Sidebar Wrapper-->
      </aside>
      <!--end::Sidebar-->
      <!--begin::App Main-->
      <main class="app-main">
