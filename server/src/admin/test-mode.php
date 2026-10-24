<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/test-mode.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/logto-management.php';
require_once dirname(__DIR__) . '/lib/posters.php';

/**
 * テスト用アカウント(展示の PC。2026-10-15、利用者の指示。lib/test-mode.php)。
 *
 * - アカウントに「テスト用」の印を付ける/外す(ユーザー管理の一覧からも付けられる)
 * - 公開ページに出すアンケートの URL(QR にする)と操作方法の文
 * - 集めた記録のまとめ・新しい順の一覧・CSV・消去
 *
 * **印が効くのは、そのアカウントで公開ページを開き直したとき。**外したときも同じ(開いている画面は次に開くまで記録を送り続ける。最長 8 時間)。
 */

$errors = [];
$notice = null;
$dbError = null;
$search = trim((string) ($_GET['q'] ?? ''));
$found = [];
$searchError = null;
$returnTo = static fn (): string => ($_POST['return'] ?? '') === 'users' ? './users.php' : './test-mode.php';
// 記録の絞り込み(テスト用アカウント / イベント中の来場者)
$source = in_array($_GET['source'] ?? '', ['test', 'event'], true) ? (string) $_GET['source'] : 'all';

try {
    $pdo = km_db();

    // CSV は画面より先に返す(GET でよい。中身は管理者だけが見られる記録)
    if (($_GET['download'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kosenmap-test-events-' . $source . '-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        $rows = km_test_events_csv($pdo, $out, $source);
        fclose($out);
        km_admin_log_record('content', 'test.events_csv', $rows . ' 件');
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $sub = trim((string) ($_POST['sub'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));

        if ($formAction === 'mark') {
            km_test_account_mark($pdo, $sub, $label, (string) ($KM_USER['name'] ?? ''));
            km_admin_log_record('settings', 'test.mark', $label !== '' ? $label : $sub);
            header('Location: ' . $returnTo() . '?marked=1', true, 303);
            exit;
        }
        if ($formAction === 'unmark') {
            km_test_account_unmark($pdo, $sub);
            km_admin_log_record('settings', 'test.unmark', $label !== '' ? $label : $sub);
            header('Location: ' . $returnTo() . '?unmarked=1', true, 303);
            exit;
        }
        if ($formAction === 'settings') {
            $survey = trim((string) ($_POST['survey_url'] ?? ''));
            $guide = str_replace("\r\n", "\n", trim((string) ($_POST['guide'] ?? '')));
            $idle = trim((string) ($_POST['idle_minutes'] ?? ''));
            $collect = isset($_POST['event_collect']) ? '1' : '0';
            $startNode = trim((string) ($_POST['start_node'] ?? ''));
            if ($startNode !== '' && !in_array($startNode, array_column(km_poster_node_options($pdo), 'id'), true)) {
                $errors[] = '出発地は一覧の地点から選んでください。';
            } elseif (!km_test_survey_url_valid($survey)) {
                $errors[] = 'アンケートの URL は https:// で始まる 255 字までの URL にしてください(空にすると出しません)。';
            } elseif (mb_strlen($guide) > 2000) {
                $errors[] = '操作方法は 2000 字までにしてください。';
            } elseif (!ctype_digit($idle) || (int) $idle > 60) {
                $errors[] = '自動で「始めから」に戻すまでの時間は 0〜60 分にしてください(0 は戻さない)。';
            } else {
                km_setting_set($pdo, KM_TEST_SURVEY_SETTING, $survey);
                km_setting_set($pdo, KM_TEST_IDLE_SETTING, (string) (int) $idle);
                km_setting_set($pdo, KM_EVENT_COLLECT_SETTING, $collect);
                km_setting_set($pdo, KM_TEST_START_SETTING, $startNode);
                km_test_guide_set($pdo, $guide === '' ? KM_TEST_GUIDE_DEFAULT : $guide);
                km_admin_log_record('settings', 'test.settings');
                header('Location: ./test-mode.php?saved=1', true, 303);
                exit;
            }
        }
        // ログアウトの合言葉(2026-10-19)。**ハッシュだけを置く**。空にして保存すると消える(テスト用アカウントはログアウトできなくなる)
        if ($formAction === 'signout_pin') {
            $pin = (string) ($_POST['signout_pin'] ?? '');
            $clear = isset($_POST['signout_pin_clear']);
            if (!$clear && !km_test_signout_pin_valid($pin)) {
                $errors[] = 'ログアウトの合言葉は英数字 4〜32 字にしてください。';
            } else {
                km_test_signout_pin_set($pdo, $clear ? '' : $pin);
                km_admin_log_record('settings', 'test.settings', $clear ? 'ログアウトの合言葉を消した' : 'ログアウトの合言葉を変えた');
                header('Location: ./test-mode.php?pin=' . ($clear ? 'cleared' : 'set'), true, 303);
                exit;
            }
        }
        if ($formAction === 'guide_default') {
            km_test_guide_set($pdo, KM_TEST_GUIDE_DEFAULT);
            km_admin_log_record('settings', 'test.settings', '操作方法を既定に戻した');
            header('Location: ./test-mode.php?saved=1', true, 303);
            exit;
        }
        if ($formAction === 'delete_events') {
            $deleted = km_test_events_delete_all($pdo);
            km_admin_log_record('content', 'test.events_delete', $deleted . ' 件');
            header('Location: ./test-mode.php?deleted=' . $deleted, true, 303);
            exit;
        }
    }

    if (isset($_GET['saved'])) {
        $notice = '保存しました。公開ページを開き直すと反映されます。';
    } elseif (isset($_GET['marked'])) {
        $notice = 'テスト用の印を付けました。そのアカウントで公開ページを開き直すと「テスト中」になります。';
    } elseif (isset($_GET['unmarked'])) {
        $notice = 'テスト用の印を外しました。';
    } elseif (($_GET['pin'] ?? '') === 'set') {
        $notice = 'ログアウトの合言葉を決めました。展示の PC では、係の人がこの合言葉でログアウトします。';
    } elseif (($_GET['pin'] ?? '') === 'cleared') {
        $notice = 'ログアウトの合言葉を消しました。テスト用アカウントは、印を外すまでログアウトできません。';
    } elseif (isset($_GET['deleted'])) {
        $notice = '記録を ' . (int) $_GET['deleted'] . ' 件消しました。';
    }

    $accounts = km_test_accounts_all($pdo);
    $surveyUrl = km_test_survey_url($pdo);
    $guideText = km_test_guide_text($pdo);
    $idleMinutes = km_test_idle_minutes($pdo);
    $eventCollect = km_event_collect_enabled($pdo);
    $startNodeId = km_test_start_node($pdo);
    $startOptions = km_poster_node_options($pdo);
    $signoutPinSet = km_test_signout_pin_hash($pdo) !== '';
    $summary = km_test_events_summary($pdo, $source);
    $recent = km_test_events_recent($pdo, 100, $source);
    $nodeNames = km_test_node_names($pdo, array_merge(
        array_column($summary['places'], 'node'),
        array_column($summary['routes'], 'from'),
        array_column($summary['routes'], 'to'),
        array_column($recent, 'node_id'),
        array_column($recent, 'node_id2'),
    ));
} catch (Throwable $exception) {
    error_log('test-mode.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
    $accounts = [];
    $surveyUrl = '';
    $guideText = KM_TEST_GUIDE_DEFAULT;
    $idleMinutes = KM_TEST_IDLE_DEFAULT;
    $eventCollect = true;
    $startNodeId = '';
    $startOptions = [];
    $signoutPinSet = false;
    $summary = null;
    $recent = [];
    $nodeNames = [];
}

// 印を付けるアカウントを探す(Logto が正本。ここでは一覧を引くだけ)
if ($search !== '') {
    try {
        $found = km_logto_users(20, $search);
    } catch (Throwable $exception) {
        error_log('test-mode.php search: ' . $exception->getMessage());
        $searchError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
    }
}
$markedSubs = array_column($accounts, 'sub');
$nodeName = static fn (?string $id): string => $id === null || $id === '' ? '—' : ($nodeNames[$id] ?? $id);

$KM_PAGE = [
    'nav' => 'testMode',
    'titleKey' => 'page.testMode.title',
    'title' => 'テスト用アカウント | KosenMap 管理',
    'h1Key' => 'page.testMode.h1',
    'h1' => 'テスト用アカウント',
    'crumbs' => [
        ['key' => 'page.users.h1', 'text' => 'ユーザー管理'],
        ['key' => 'page.testMode.h1', 'text' => 'テスト用アカウント'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid">

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert"><?= km_e($dbError) ?></div>
            <?php endif; ?>

            <div class="alert alert-info fs-7" role="note">
              印を付けたアカウントで公開ページを開くと、右上に「テスト中」の札(アンケートの QR)と操作方法・「始めから」が出て、
              検索の語・選んだ場所・経路の出発地と目的地・開いた部屋の詳細・階の切り替えを記録します。
              <strong>印の無いアカウントやログインしていない人の操作は記録しません。</strong>
              展示の PC はこのアカウントでログインしたままにし、来場者が代わるたびに「始めから」を押してください。
            </div>

            <div class="row">
              <div class="col-12 col-xl-6">
                <!-- 印の付いたアカウント -->
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title">テスト用のアカウント</h3></div>
                  <div class="card-body">
                    <?php if ($accounts === []): ?>
                      <p class="text-body-secondary fs-7 mb-3">まだありません。下で探して印を付けてください(ユーザー管理の一覧からも付けられます)。</p>
                    <?php else: ?>
                      <ul class="list-group mb-3">
                        <?php foreach ($accounts as $account): ?>
                          <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span>
                              <span class="badge text-bg-warning me-1">テスト用</span>
                              <?= km_e($account['label'] !== '' ? $account['label'] : $account['sub']) ?>
                              <span class="text-body-secondary fs-7">(<?= km_e(substr($account['markedAt'], 0, 16)) ?><?= $account['markedBy'] !== '' ? '・' . km_e($account['markedBy']) : '' ?>)</span>
                            </span>
                            <form method="post" class="m-0">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="form_action" value="unmark">
                              <input type="hidden" name="sub" value="<?= km_e($account['sub']) ?>">
                              <input type="hidden" name="label" value="<?= km_e($account['label']) ?>">
                              <button type="submit" class="btn btn-sm btn-outline-secondary">印を外す</button>
                            </form>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>

                    <form method="get" class="d-flex gap-2">
                      <input type="search" name="q" class="form-control" value="<?= km_e($search) ?>" placeholder="名前・メールで探す" aria-label="アカウントを探す">
                      <button type="submit" class="btn btn-outline-primary text-nowrap">探す</button>
                    </form>
                    <?php if ($searchError !== null): ?>
                      <div class="alert alert-warning mt-2 mb-0 fs-7"><?= km_e($searchError) ?></div>
                    <?php elseif ($search !== ''): ?>
                      <?php if ($found === []): ?>
                        <p class="text-body-secondary fs-7 mt-2 mb-0">見つかりませんでした。</p>
                      <?php else: ?>
                        <ul class="list-group mt-2">
                          <?php foreach ($found as $user): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                              <span><?= km_e($user['name']) ?> <span class="text-body-secondary fs-7"><?= km_e($user['email']) ?></span></span>
                              <?php if (in_array($user['id'], $markedSubs, true)): ?>
                                <span class="badge text-bg-warning">テスト用</span>
                              <?php else: ?>
                                <form method="post" class="m-0">
                                  <?= km_csrf_field() ?>
                                  <input type="hidden" name="form_action" value="mark">
                                  <input type="hidden" name="sub" value="<?= km_e($user['id']) ?>">
                                  <input type="hidden" name="label" value="<?= km_e($user['name']) ?>">
                                  <button type="submit" class="btn btn-sm btn-warning">テスト用にする</button>
                                </form>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="col-12 col-xl-6">
                <!-- 公開ページに出すもの -->
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title">画面に出すもの</h3></div>
                  <div class="card-body">
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="settings">
                      <div class="mb-3">
                        <label for="survey-url" class="form-label">アンケートの URL(QR にして「テスト中」の札に出す)</label>
                        <input type="url" id="survey-url" name="survey_url" class="form-control" maxlength="255"
                               value="<?= km_e($surveyUrl) ?>" placeholder="https://forms.gle/...">
                        <div class="form-text">https:// で始まるものだけ。空にすると QR も URL も出しません。</div>
                      </div>
                      <div class="mb-3">
                        <label for="guide-text" class="form-label">操作方法(1 行が 1 項目。番号は自動で付きます)</label>
                        <textarea id="guide-text" name="guide" class="form-control" rows="8" maxlength="2000"><?= km_e($guideText) ?></textarea>
                      </div>
                      <div class="mb-3">
                        <label for="idle-minutes" class="form-label">触られないまま、この分数が過ぎたら自動で「始めから」</label>
                        <?php // style 属性は CSP で効かないので、幅は格子で決める ?>
                        <div class="row"><div class="col-6 col-md-4">
                          <div class="input-group">
                            <input type="number" id="idle-minutes" name="idle_minutes" class="form-control" min="0" max="60" step="1"
                                   value="<?= (int) $idleMinutes ?>">
                            <span class="input-group-text">分</span>
                          </div>
                        </div></div>
                        <div class="form-text">0 にすると自動では戻しません。戻す前に 15 秒の知らせ(「このまま使う」で取り消せる)が出ます。1 度も触られていない画面は戻しません。</div>
                      </div>
                      <div class="mb-3">
                        <?php /* 2026-10-25、利用者「テストユーザーのみ、出発点を Let's Play Densan に」。既定は第二実習室 管-105(lib/test-mode.php) */ ?>
                        <label for="start-node" class="form-label">出発地(展示の PC を置く場所)</label>
                        <select id="start-node" name="start_node" class="form-select">
                          <option value="">入れない(毎回選んでもらう)</option>
                          <?php foreach ($startOptions as $option): ?>
                            <option value="<?= km_e($option['id']) ?>"<?= $option['id'] === $startNodeId ? ' selected' : '' ?>>
                              <?= km_e(km_poster_floor_label($option['floor']) . ' ' . $option['name']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <div class="form-text">テスト用アカウントで開いたときだけ、経路の出発地が空ならここを入れます(地図の経路と案内チャット)。</div>
                      </div>
                      <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="event-collect" name="event_collect" value="1" <?= $eventCollect ? 'checked' : '' ?>>
                        <label class="form-check-label" for="event-collect">
                          イベントモード中は、来場者(ログインしていない人も)の操作も記録する
                        </label>
                        <div class="form-text">最初の画面で「情報を収集するのを許可します」(チェック済み)を出し、外した人の操作は記録しません。誰の操作かは残しません。</div>
                      </div>
                      <button type="submit" class="btn btn-primary">保存</button>
                    </form>
                    <form method="post" class="mt-2" data-km-confirm="操作方法を既定の文に戻します。よろしいですか?">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="guide_default">
                      <button type="submit" class="btn btn-sm btn-outline-secondary">操作方法を既定に戻す</button>
                    </form>
                  </div>
                </div>

                <!-- ログアウトの合言葉(2026-10-19) -->
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title">ログアウトの合言葉(係の人)</h3></div>
                  <div class="card-body">
                    <p class="mb-2">
                      テスト用アカウントは、<strong>この合言葉を入れたときだけ</strong>ログアウトできます(来場者が押しても出られません)。
                      アカウントの設定の画面も開けません。いまは
                      <?= $signoutPinSet ? '<span class="badge text-bg-success">決まっています</span>' : '<span class="badge text-bg-warning">決まっていません(ログアウトできません)</span>' ?>
                    </p>
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="signout_pin">
                      <div class="mb-2">
                        <label for="signout-pin" class="form-label">新しい合言葉(英数字 4〜32 字)</label>
                        <div class="row"><div class="col-8 col-md-6">
                          <input type="password" id="signout-pin" name="signout_pin" class="form-control" maxlength="32" autocomplete="new-password">
                        </div></div>
                        <div class="form-text">保存するのはハッシュだけです(あとから読み出せません)。忘れたら決め直してください。</div>
                      </div>
                      <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="signout-pin-clear" name="signout_pin_clear" value="1">
                        <label class="form-check-label" for="signout-pin-clear">合言葉を消す(テスト用アカウントはログアウトできなくなる)</label>
                      </div>
                      <button type="submit" class="btn btn-primary">保存</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>

            <?php if ($summary !== null): ?>
            <!-- 集めた記録 -->
            <div class="card mb-4">
              <div class="card-header d-flex align-items-center">
                <h3 class="card-title me-auto">集めた記録</h3>
                <div class="btn-group btn-group-sm me-2" role="group" aria-label="記録の絞り込み">
                  <?php foreach (['all' => 'すべて', 'test' => 'テスト用アカウント', 'event' => 'イベント中の来場者'] as $key => $text): ?>
                    <a href="./test-mode.php?source=<?= km_e($key) ?>" class="btn <?= $source === $key ? 'btn-secondary' : 'btn-outline-secondary' ?>"><?= km_e($text) ?></a>
                  <?php endforeach; ?>
                </div>
                <a href="./test-mode.php?download=csv&amp;source=<?= km_e($source) ?>" class="btn btn-sm btn-outline-primary me-2">CSV を書き出す</a>
                <form method="post" class="m-0" data-km-confirm="テスト用アカウントの記録を全部消します。戻せません。先に CSV を書き出しましたか?">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="form_action" value="delete_events">
                  <button type="submit" class="btn btn-sm btn-outline-danger" <?= $summary['total'] === 0 ? 'disabled' : '' ?>>全部消す</button>
                </form>
              </div>
              <div class="card-body">
                <?php if ($summary['total'] === 0): ?>
                  <p class="text-body-secondary mb-0">まだ記録はありません。</p>
                <?php else: ?>
                  <p class="mb-3">
                    <strong><?= (int) $summary['total'] ?></strong> 件・来場者(「始めから」で区切った数)<strong><?= (int) $summary['visits'] ?></strong> 人
                    <span class="text-body-secondary fs-7">(<?= km_e((string) $summary['first']) ?> 〜 <?= km_e((string) $summary['last']) ?>)</span>
                  </p>
                  <p class="fs-7 mb-3">
                    <?php foreach (KM_TEST_EVENT_LABELS as $kind => $kindLabel): ?>
                      <span class="badge text-bg-light border me-1"><?= km_e($kindLabel) ?> <?= (int) ($summary['byKind'][$kind] ?? 0) ?></span>
                    <?php endforeach; ?>
                  </p>
                  <div class="row">
                    <div class="col-12 col-lg-4">
                      <h4 class="fs-6 fw-bold">よく打たれた語・カテゴリー</h4>
                      <table class="table table-sm fs-7">
                        <?php foreach ($summary['searches'] as $row): ?>
                          <tr><td><?= km_e($row['text']) ?></td><td class="text-end"><?= (int) $row['count'] ?></td></tr>
                        <?php endforeach; ?>
                      </table>
                    </div>
                    <div class="col-12 col-lg-4">
                      <h4 class="fs-6 fw-bold">よく探された場所</h4>
                      <p class="text-body-secondary fs-7 mb-1">検索で当たった・選んだ・詳細を開いた・経路の目的地にした数</p>
                      <table class="table table-sm fs-7">
                        <?php foreach ($summary['places'] as $row): ?>
                          <tr><td><?= km_e($nodeName($row['node'])) ?></td><td class="text-end"><?= (int) $row['count'] ?></td></tr>
                        <?php endforeach; ?>
                      </table>
                    </div>
                    <div class="col-12 col-lg-4">
                      <h4 class="fs-6 fw-bold">よく引かれた経路</h4>
                      <table class="table table-sm fs-7">
                        <?php foreach ($summary['routes'] as $row): ?>
                          <tr><td><?= km_e($nodeName($row['from'])) ?> → <?= km_e($nodeName($row['to'])) ?></td><td class="text-end"><?= (int) $row['count'] ?></td></tr>
                        <?php endforeach; ?>
                      </table>
                      <h4 class="fs-6 fw-bold mt-3">見られた階</h4>
                      <p class="fs-7">
                        <?php foreach ($summary['floors'] as $row): ?>
                          <span class="badge text-bg-light border me-1"><?= km_e($row['floor'] === 'outside' ? '外' : $row['floor'] . 'F') ?> <?= (int) $row['count'] ?></span>
                        <?php endforeach; ?>
                      </p>
                    </div>
                  </div>

                  <h4 class="fs-6 fw-bold mt-2">新しい順(100 件まで)</h4>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped fs-7 align-middle">
                      <thead>
                        <tr><th>時刻</th><th>アカウント</th><th>来場者</th><th>種類</th><th>語・条件</th><th>場所</th><th>階</th></tr>
                      </thead>
                      <tbody>
                        <?php foreach ($recent as $row): ?>
                          <tr>
                            <td class="text-nowrap"><?= km_e(km_test_local_time((string) $row['happened_at'])) ?></td>
                            <td><?= km_e(km_test_account_label((string) $row['account'])) ?></td>
                            <td><code><?= km_e(substr((string) $row['visit'], 0, 6)) ?></code></td>
                            <td class="text-nowrap"><?= km_e(KM_TEST_EVENT_LABELS[$row['kind']] ?? (string) $row['kind']) ?><?= $row['field'] === 'start' ? '(出発地)' : ($row['field'] === 'goal' ? '(目的地)' : '') ?></td>
                            <td><?= km_e((string) ($row['text'] ?? '')) ?></td>
                            <td>
                              <?= km_e($row['node_id'] !== null ? $nodeName($row['node_id']) : '') ?>
                              <?= $row['node_id2'] !== null ? ' → ' . km_e($nodeName($row['node_id2'])) : '' ?>
                            </td>
                            <td><?= km_e((string) ($row['floor_id'] ?? '')) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
