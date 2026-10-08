<?php

declare(strict_types=1);

/**
 * ランキングの「調べられた語」を選んで公開する・一覧から外す(2026-09-25、診断 W-45。2026-10-06 に承認制へ)。
 *
 * 語の記録はログイン不要で受ける。公開の条件(3 つ以上の出どころ)は、回線を 3 つ用意すれば 1 人でも越えられるので、
 * **特定の人を名指しする文言や不適切な文言が、誰でも見られる一覧に載りうる。**
 * 9/25 は外せるようにしただけで、外すまでは載った。**いまは管理者が選んだ語だけを、月ごとに写し取って公開する**
 * (利用者の決定「承認制・1 か月ごとに更新」。lib/app-ranking.php の km_ranking_publish_queries)。
 *
 * 外した語はその年のうちは、また送られてきても数えない(km_ranking_hide_query)。公開中の写しからもすぐ消える。
 * **公開の条件に満たない語も出す** —— 載る前に外せるように(選べるのは条件を満たした語だけ)。
 */

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/app-ranking.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'ranking',
    'titleKey' => 'page.ranking.title',
    'title' => 'ランキングの語 | KosenMap 管理',
    'h1Key' => 'page.ranking.h1',
    'h1' => 'ランキングの語',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.ranking.h1', 'text' => 'ランキングの語'],
    ],
];

$year = (int) date('Y');
$period = km_ranking_current_period();
$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'hide') {
            $query = (string) ($_POST['query'] ?? '');
            km_ranking_hide_query(km_db(), $query, $year);
            // 語そのものは記録に残さない(外した理由がその文言にあるので、記録に写すと残り続ける)
            km_admin_log_record('content', 'ranking.query_hidden', (string) $year);
            header('Location: ./ranking.php?hidden=1', true, 302);
            exit;
        }
        if ($action === 'publish') {
            $selected = $_POST['publish'] ?? [];
            $count = km_ranking_publish_queries(km_db(), is_array($selected) ? $selected : [], $period);
            // 記録には月と語の数だけ(語そのものは書かない)
            km_admin_log_record('content', 'ranking.queries_published', $period . ' / ' . $count);
            header('Location: ./ranking.php?published=' . $count, true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('ranking.php ' . $action . ' failed: ' . $exception->getMessage());
        $errors[] = km_admin_error_message($exception, '処理できませんでした。');
    }
}

if (isset($_GET['hidden'])) {
    $notice = 'hiddenNotice';
} elseif (isset($_GET['published'])) {
    $notice = 'publishedNotice';
}

$queries = [];
$hiddenCount = 0;
$publication = null;
$dbError = null;
try {
    $pdo = km_db();
    $queries = km_ranking_queries_for_admin($pdo, $year, 200);
    $hiddenCount = count(km_ranking_hidden_queries($pdo, $year));
    $publication = km_ranking_latest_publication($pdo, $year);
} catch (Throwable $exception) {
    error_log('ranking.php list failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}
$publishedWords = array_fill_keys($publication['queries'] ?? [], true);
$publishedThisMonth = ($publication['period'] ?? null) === $period;

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.ranking.<?= km_e($notice) ?>">保存しました。</div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.ranking.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.ranking.notice">
                アプリのランキングに出る「調べられた語」です。誰でも見られる一覧に載るのは、ここで選んで「今月の一覧を公開」した語だけです(回数は公開したときのもの)。
                毎月、選び直して公開してください。選べるのは 3 つ以上の出どころから来た語だけです。
                人を名指しする文言や不適切な文言は「一覧から外す」で外してください。外した語は、今年のうちは数えず、公開中の一覧からもすぐ消えます。
              </div>
            </div>

            <?php if ($dbError === null && !$publishedThisMonth): ?>
              <div class="alert alert-warning d-flex align-items-start" role="alert">
                <i class="bi bi-calendar-check me-2 mt-1" aria-hidden="true"></i>
                <div>
                  <span data-i18n="page.ranking.notPublishedThisMonth">今月の一覧はまだ公開していません。</span>
                  <?php if ($publication !== null): ?>
                    <span data-i18n="page.ranking.showingPeriod">公開中:</span> <?= km_e($publication['period']) ?>
                  <?php else: ?>
                    <span data-i18n="page.ranking.nothingPublished">公開中の語はありません(アプリには何も出ません)。</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>

            <form method="post" id="km-ranking-publish-form">
              <?= km_csrf_field() ?>
              <input type="hidden" name="action" value="publish" />
            </form>

            <div class="card">
              <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h3 class="card-title mb-0"><?= (int) $year ?></h3>
                <span class="text-body-secondary fs-7">
                  <span data-i18n="page.ranking.lastPublished">公開中の月</span>:
                  <?= $publication !== null ? km_e($publication['period']) . ' (' . count($publication['queries']) . ')' : '—' ?>
                </span>
                <span class="ms-auto text-body-secondary fs-7">
                  <span data-i18n="page.ranking.hiddenCount">外した語</span>: <?= (int) $hiddenCount ?>
                </span>
                <button type="submit" form="km-ranking-publish-form" class="btn btn-sm btn-primary" id="km-ranking-publish">
                  <i class="bi bi-megaphone me-1" aria-hidden="true"></i>
                  <span data-i18n="page.ranking.publish">選んだ語で今月の一覧を公開</span>
                </button>
              </div>
              <div class="card-body p-0">
                <?php if ($queries === [] && $dbError === null): ?>
                  <p class="text-center text-body-secondary py-5 mb-0" data-i18n="page.ranking.empty">
                    まだ語はありません。
                  </p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                      <thead>
                        <tr>
                          <th data-i18n="page.ranking.colSelect">公開する</th>
                          <th data-i18n="page.ranking.colQuery">語</th>
                          <th class="text-end" data-i18n="page.ranking.colSearches">回数</th>
                          <th class="text-end" data-i18n="page.ranking.colSources">出どころ</th>
                          <th data-i18n="page.ranking.colPublic">公開</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($queries as $row): ?>
                          <?php $isPublished = isset($publishedWords[$row['query']]); ?>
                          <tr>
                            <td>
                              <?php if ($row['public']): ?>
                                <!-- 前に公開した語は最初から選んでおく(毎月の手間を減らす) -->
                                <input type="checkbox" class="form-check-input" form="km-ranking-publish-form" name="publish[]"
                                       value="<?= km_e($row['query']) ?>"<?= $isPublished ? ' checked' : '' ?> />
                              <?php endif; ?>
                            </td>
                            <td><?= km_e($row['query']) ?></td>
                            <td class="text-end"><?= (int) $row['searches'] ?></td>
                            <td class="text-end"><?= (int) $row['sources'] ?></td>
                            <td>
                              <?php if ($isPublished): ?>
                                <span class="badge text-bg-primary" data-i18n="page.ranking.public">載っている</span>
                              <?php elseif ($row['public']): ?>
                                <span class="badge text-bg-info" data-i18n="page.ranking.eligible">選べる</span>
                              <?php else: ?>
                                <span class="badge text-bg-secondary" data-i18n="page.ranking.notPublic">まだ選べない</span>
                              <?php endif; ?>
                            </td>
                            <td class="text-end">
                              <form method="post" class="d-inline km-ranking-hide-form">
                                <?= km_csrf_field() ?>
                                <input type="hidden" name="action" value="hide" />
                                <input type="hidden" name="query" value="<?= km_e($row['query']) ?>" />
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                  <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>
                                  <span data-i18n="page.ranking.hide">一覧から外す</span>
                                </button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            const t = (key, fallback) => (window.KmI18n ? window.KmI18n.t(key) : fallback);
            document.querySelectorAll('.km-ranking-hide-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                if (!window.confirm(t('page.ranking.confirmHide', 'hide?'))) {
                  event.preventDefault();
                }
              });
            });
            const publish = document.getElementById('km-ranking-publish-form');
            if (publish) {
              publish.addEventListener('submit', (event) => {
                if (!window.confirm(t('page.ranking.confirmPublish', 'publish?'))) {
                  event.preventDefault();
                }
              });
            }
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
