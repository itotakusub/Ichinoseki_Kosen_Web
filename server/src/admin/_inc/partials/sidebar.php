<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

$nav = $KM_PAGE['nav'] ?? '';

/*
 * アプリの受け箱で公開を待っている届けの数(2026-10-09。lib/apk-inbox.php)。DB が落ちていれば出さない。
 * **行き先は変えない**(2026-11-02)。以前は待ちがあると「ダウンロード」のリンク先が受け箱に変わり、
 * 開くたびに行き先が違った。数はバッジで知らせ、受け箱へはダウンロードの画面とヘッダーの通知から行く。
 */
$apkWaiting = (static function (): int {
    try {
        require_once dirname(__DIR__, 3) . '/lib/apk-inbox.php';
        return km_apk_inbox_waiting_count(km_db());
    } catch (Throwable $exception) {
        return 0;
    }
})();

/*
 * **サイドバーは目的ごとの組で並べる**(2026-11-02、利用者の指示「無理やり配置した項目を分かりやすく」)。
 *
 * 以前は「データベース」の下に地図・イベント・屋台・バックアップなど 13 項目が入り、
 * 「コンテンツ」に集計やアクセス制御が混ざり、「設定」が「連携サービス(Logto)」の見出しの下にあった。
 * 組は「何をしに来たか」で分ける。ページの URL と各ページの nav の名前は変えていない(ブックマークが生きる)。
 *
 * 1 項目 = [nav の名前, 行き先, アイコン, i18n の鍵, 日本語の既定]。組は開閉でき、いまのページの組だけ開いておく。
 * チャット・サービス監視・phpMyAdmin・Logto Console へのリンクは 2026-11-02 に外した
 * (チャットと Soketi は撤去、監視はタスクマネージャーへ、Console は SSH のトンネルで開く。設定の画面に開き方がある)。
 */
$groups = [
    'map' => ['bi-map', 'side.groupMap', '地図', [
        ['mapEditor', './map-editor.php', 'bi-pin-map', 'side.mapEditor', '地図編集'],
        ['mapFloors', './map-floors.php', 'bi-layers', 'side.mapFloors', '階と平面図'],
        ['mapAreas', './map-areas.php', 'bi-palette', 'side.mapAreas', '地図の色分け'],
        ['searchAliases', './search-aliases.php', 'bi-alphabet', 'side.searchAliases', '地点の読み・別名・URL'],
        ['mapHereQr', './map-here-qr.php', 'bi-qr-code', 'side.mapHereQr', '現在地の QR'],
        ['mapSettings', './map-settings.php', 'bi-shield-lock', 'side.mapSettings', '地図の公開設定'],
    ]],
    'event' => ['bi-calendar-event', 'side.groupEvent', '会期', [
        ['mapEvents', './map-events.php', 'bi-cone-striped', 'side.mapEvents', 'イベントモード'],
        ['schedule', './schedule.php', 'bi-calendar-event', 'side.schedule', 'スケジュール'],
        ['stalls', './stalls.php', 'bi-shop', 'side.stalls', '屋台'],
        ['posters', './posters.php', 'bi-image', 'side.posters', 'ポスター・広告'],
        ['notices', './notices.php', 'bi-megaphone', 'side.notices', 'お知らせとチュートリアル'],
        ['guideChat', './guide-chat.php', 'bi-chat-dots', 'side.guideChat', '案内チャット(AI)'],
    ]],
    'app' => ['bi-phone', 'side.groupApp', 'アプリ', [
        ['mapPublish', './map-publish.php', 'bi-broadcast', 'side.mapPublish', '地図の配信'],
        ['mapSync', './map-sync.php', 'bi-arrow-repeat', 'side.mapSync', 'アプリの地図を取り込む'],
        ['downloads', './downloads.php', 'bi-download', 'side.downloads', 'アプリの配布'],
    ]],
    'public' => ['bi-globe', 'side.groupPublic', '公開ページ', [
        ['guestLinks', './guest-links.php', 'bi-link-45deg', 'side.guestLinks', 'お試しの閲覧リンク'],
        ['testMode', './test-mode.php', 'bi-clipboard2-data', 'side.testMode', 'テスト用アカウント'],
        ['qrLinks', './qr-links.php', 'bi-qr-code-scan', 'side.qrLinks', 'QR のリンク集'],
        ['faq', './faq.php', 'bi-question-circle', 'side.faq', 'FAQ'],
        ['legal', './legal.php', 'bi-file-earmark-text', 'side.legal', '規約とポリシー'],
    ]],
    'people' => ['bi-people-fill', 'side.groupPeople', '人', [
        ['users', './users.php', 'bi-people', 'side.users', 'ユーザー管理'],
        ['staffRequests', './staff-requests.php', 'bi-person-badge', 'side.staffRequests', '教職員の申請'],
        ['staffNodes', './staff-nodes.php', 'bi-geo-alt', 'side.staffNodes', '教職員の地点'],
        ['mailbox', './mailbox.php', 'bi-envelope-fill', 'side.mailbox', '受信箱'],
    ]],
    'records' => ['bi-bar-chart-fill', 'side.groupRecords', '記録と分析', [
        ['visitors', './visitors.php', 'bi-globe2', 'side.visitors', '訪問者'],
        ['charts', './charts.php', 'bi-bar-chart', 'side.charts', 'チャート'],
        ['ranking', './ranking.php', 'bi-trophy', 'side.ranking', 'ランキングの語'],
        ['arCaptures', './ar-captures.php', 'bi-camera', 'side.arCaptures', 'AR の撮影'],
        ['timeline', './timeline.php', 'bi-clock-history', 'side.timeline', 'タイムライン'],
    ]],
    'ops' => ['bi-gear-wide-connected', 'side.groupOps', '運用', [
        ['taskmgr', './taskmgr.php', 'bi-cpu', 'side.taskmgr', 'タスクマネージャー'],
        ['backups', './backups.php', 'bi-archive', 'side.backups', 'バックアップ'],
        ['tables', './tables.php', 'bi-table', 'side.tables', 'テーブル管理'],
        ['settings', './settings.php', 'bi-gear-fill', 'side.settings', '設定'],
    ]],
    'team' => ['bi-kanban', 'side.groupTeam', 'チーム', [
        ['projects', './projects.php', 'bi-list-check', 'side.projects', 'プロジェクト状況'],
        ['kanban', './kanban.php', 'bi-kanban-fill', 'side.kanban', 'かんばん'],
        ['calendar', './calendar.php', 'bi-calendar3', 'side.calendar', 'カレンダー'],
        ['filemanager', './file-manager.php', 'bi-folder-fill', 'side.fileManager', 'ファイル管理'],
    ]],
];

/** いまのページが入っている組(開いておく)。プロフィールはヘッダーの名前のメニューから開くので、どの組にも無い */
$openGroup = '';
foreach ($groups as $groupId => $group) {
    foreach ($group[3] as $item) {
        if ($item[0] === $nav) {
            $openGroup = $groupId;
        }
    }
}

/** サイドバーの子項目に付けるクラス */
$link = static fn(string $id): string => 'nav-link' . ($nav === $id ? ' active' : '');
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
              <li class="nav-item">
                <a href="./index.php" class="<?= $link('index') ?>">
                  <i class="nav-icon bi bi-speedometer2"></i>
                  <p data-i18n="side.dashboard">ダッシュボード</p>
                </a>
              </li>
              <?php foreach ($groups as $groupId => [$groupIcon, $groupKey, $groupLabel, $items]): ?>
                <li class="nav-item<?= $openGroup === $groupId ? ' menu-open' : '' ?>">
                  <a href="#" class="nav-link<?= $openGroup === $groupId ? ' active' : '' ?>">
                    <i class="nav-icon bi <?= km_e($groupIcon) ?>"></i>
                    <p>
                      <span data-i18n="<?= km_e($groupKey) ?>"><?= km_e($groupLabel) ?></span>
                      <i class="nav-arrow bi bi-chevron-right"></i>
                    </p>
                  </a>
                  <ul class="nav nav-treeview">
                    <?php foreach ($items as [$id, $href, $icon, $key, $label]): ?>
                      <li class="nav-item">
                        <a href="<?= km_e($href) ?>" class="<?= $link($id) ?>">
                          <i class="nav-icon bi <?= km_e($icon) ?>"></i>
                          <p>
                            <span data-i18n="<?= km_e($key) ?>"><?= km_e($label) ?></span>
                            <?php if ($id === 'downloads' && $apkWaiting > 0): ?>
                              <span class="nav-badge badge text-bg-warning me-3" title="公開を待っているアプリ"><?= (int) $apkWaiting ?></span>
                            <?php endif; ?>
                          </p>
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </li>
              <?php endforeach; ?>
            </ul>
            <!--end::Sidebar Menu-->
          </nav>
        </div>
        <!--end::Sidebar Wrapper-->
      </aside>
      <!--end::Sidebar-->
      <!--begin::App Main-->
      <main class="app-main">
