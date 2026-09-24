<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

// 見た目と文言は lib/admin-log.php に置いてある(admin/profile.php も同じものを使う)。

$entries = [];
$dbError = null;
try {
    $entries = km_admin_log_recent(km_db(), 100);
} catch (Throwable $exception) {
    error_log('timeline.php failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

$KM_PAGE = [
    'nav' => 'timeline',
    'titleKey' => 'page.timeline.title',
    'title' => 'タイムライン | KosenMap 管理',
    'h1Key' => 'page.timeline.h1',
    'h1' => 'タイムライン',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.timeline.h1', 'text' => 'タイムライン'],
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
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning d-flex align-items-start" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2 mt-1" aria-hidden="true"></i>
                <div data-i18n="page.timeline.dbError">操作ログを読み込めませんでした。</div>
              </div>
            <?php endif; ?>

            <!--begin::Filter-->
            <div class="btn-group mb-3" role="group" aria-label="ログの種類で絞り込む" data-i18n-attr="aria-label:page.timeline.filterLabel" id="km-timeline-filter">
              <button type="button" class="btn btn-outline-secondary active" data-km-filter="all" data-i18n="page.timeline.filterAll">
                すべて
              </button>
              <button type="button" class="btn btn-outline-secondary" data-km-filter="auth" data-i18n="page.timeline.filterAuth">
                認証
              </button>
              <button type="button" class="btn btn-outline-secondary" data-km-filter="table" data-i18n="page.timeline.filterTable">
                テーブル
              </button>
              <button type="button" class="btn btn-outline-secondary" data-km-filter="settings" data-i18n="page.timeline.filterSettings">
                設定
              </button>
              <button type="button" class="btn btn-outline-secondary" data-km-filter="content" data-i18n="page.timeline.filterContent">
                コンテンツ
              </button>
              <button type="button" class="btn btn-outline-secondary" data-km-filter="system" data-i18n="page.timeline.filterSystem">
                システム
              </button>
            </div>
            <!--end::Filter-->

            <?php if ($entries === []): ?>
              <div class="card">
                <div class="card-body text-center text-body-secondary py-5">
                  <i class="bi bi-clock-history fs-2 d-block mb-2" aria-hidden="true"></i>
                  <span data-i18n="page.timeline.empty">まだ記録された操作はありません。</span>
                </div>
              </div>
            <?php else: ?>
              <!-- The time line -->
              <div class="timeline" id="km-timeline">
                <?php
                  // 日付が変わるたびに time-label を挟む。日付は createdAtEpoch から出す
                  // (DB の datetime 文字列をそのまま使うとタイムゾーン差でずれる)。
                  $today = date('Y-m-d');
                  $yesterday = date('Y-m-d', strtotime('-1 day'));
                  $currentDay = null;

                  foreach ($entries as $entry):
                      $epoch = (int) $entry['createdAtEpoch'];
                      $day = date('Y-m-d', $epoch);
                      $category = (string) $entry['category'];
                      $look = KM_ADMIN_LOG_ICONS[$category] ?? KM_ADMIN_LOG_ICONS['system'];

                      if ($day !== $currentDay):
                          $currentDay = $day;
                          if ($day === $today) {
                              $labelKey = 'page.timeline.today';
                              $labelText = '本日';
                              $labelAccent = 'text-bg-danger';
                          } elseif ($day === $yesterday) {
                              $labelKey = 'page.timeline.yesterday';
                              $labelText = '昨日';
                              $labelAccent = 'text-bg-secondary';
                          } else {
                              $labelKey = null;
                              $labelText = $day;
                              $labelAccent = 'text-bg-secondary';
                          }
                  ?>
                    <div class="time-label">
                      <span class="<?= $labelAccent ?>"<?= $labelKey !== null ? ' data-i18n="' . $labelKey . '"' : '' ?>><?= km_e($labelText) ?></span>
                    </div>
                  <?php endif; ?>

                  <div data-km-category="<?= km_e($category) ?>">
                    <i class="timeline-icon bi <?= km_e($look['icon']) ?> <?= km_e($look['accent']) ?>"></i>
                    <div class="timeline-item">
                      <span class="time"><i class="bi bi-clock-fill"></i> <?= km_e(date('H:i', $epoch)) ?></span>
                      <h3 class="timeline-header<?= $entry['detail'] === null ? ' no-border' : '' ?>">
                        <?php if ($entry['actorName'] !== null): ?>
                          <strong><?= km_e((string) $entry['actorName']) ?></strong>
                        <?php else: ?>
                          <?php
                            /*
                             * 公開ページからの操作(問い合わせの送信・氏名解除の失敗)は
                             * 実行者が居ない。**主語を省くと「が問い合わせを送信しました」と
                             * 主語の欠けた文になる**ので、代わりの呼び名を置く。
                             * 誰かを特定しているわけではないことが分かる言い方にする。
                             */
                          ?>
                          <strong class="text-body-secondary" data-i18n="log.actor.anonymous">公開ページの利用者</strong>
                        <?php endif; ?>
                        <?php
                          // 動詞部分だけを辞書に載せる。固有名詞(ユーザー名・テーブル名)は
                          // 翻訳できないので、エスケープした生テキストのまま隣に置く。
                          // admin/map-settings.php のモード別バッジと同じ「動的キー」の作り方。
                          $action = (string) $entry['action'];
                          $actionKey = 'log.action.' . str_replace('.', '_', $action);
                          // 知らない action でも内部名を晒さず、そのまま出すしかないときだけ出す
                          $actionText = KM_ADMIN_LOG_ACTION_LABELS[$action] ?? $action;
                        ?>
                        <span data-i18n="<?= km_e($actionKey) ?>"><?= km_e($actionText) ?></span>
                      </h3>
                      <?php if ($entry['detail'] !== null): ?>
                        <div class="timeline-body"><code><?= km_e((string) $entry['detail']) ?></code></div>
                      <?php endif; ?>
                      <?php
                      /*
                       * **接続元を出す。** 記録はしていたのに画面に出しておらず、
                       * 「いつもと違う場所からの操作」に気づく手立てが無かった。
                       * DB を直接見ないと分からない情報を持っている意味は薄い。
                       */
                      ?>
                      <?php if (($entry['ip'] ?? null) !== null): ?>
                        <div class="timeline-footer">
                          <span class="text-body-secondary fs-7">
                            <i class="bi bi-hdd-network me-1" aria-hidden="true"></i>
                            <code><?= km_e((string) $entry['ip']) ?></code>
                          </span>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>

                <div>
                  <i class="timeline-icon bi bi-three-dots text-bg-secondary"></i>
                </div>
              </div>
              <!-- END timeline -->
            <?php endif; ?>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <!--begin::Timeline Filter Script-->
        <script<?= km_csp_nonce_attr() ?>>
          // フィルターは表示/非表示の切り替えだけ。サーバーへは何も送らない。
          // 日付ラベル(time-label)は data-km-category を持たないので絞り込みの影響を受けず、
          // その日の項目が全て隠れてもラベルだけ残る。件数が少ないうちは実害が無いので
          // 単純さを優先し、絞り込み後の空ラベル整理まではしない。
          document.getElementById('km-timeline-filter')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-km-filter]');
            if (!button) return;
            const category = button.dataset.kmFilter;

            document.querySelectorAll('#km-timeline-filter [data-km-filter]').forEach((b) => {
              b.classList.toggle('active', b === button);
            });
            document.querySelectorAll('#km-timeline [data-km-category]').forEach((item) => {
              item.style.display = category === 'all' || item.dataset.kmCategory === category ? '' : 'none';
            });
          });
        </script>
        <!--end::Timeline Filter Script-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
