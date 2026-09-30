<?php

declare(strict_types=1);

/**
 * お試しの閲覧リンク(lib/map-guest.php。2026-09-30、利用者の指示)。
 *
 * 期限と使える台数を決めてリンクを発行する。開いた人は、そのブラウザだけ期限まで
 * **教職員と同じように**地図を見られる(地図の錠を通る・教職員氏名が見える)。途中で取り消せる。
 *
 * **リンクはここで 1 回しか見せない**(表には要約だけを置く)。見せる前に URL へ載せて
 * 戻し先を作ると、アクセスログやブラウザの履歴にトークンが残るので、セッションで 1 回だけ渡す。
 */

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/map-guest.php';
require_once dirname(__DIR__) . '/lib/map-access.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'guestLinks',
    'titleKey' => 'page.guestLinks.title',
    'title' => 'お試しの閲覧リンク | KosenMap 管理',
    'h1Key' => 'page.guestLinks.h1',
    'h1' => 'お試しの閲覧リンク',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.guestLinks.h1', 'text' => 'お試しの閲覧リンク'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        $pdo = km_db();
        if ($action === 'create') {
            $created = km_map_guest_create(
                $pdo,
                (string) ($_POST['label'] ?? ''),
                (int) ($_POST['days'] ?? 0),
                (int) ($_POST['max_uses'] ?? 0),
                is_array($KM_USER ?? null) ? (string) ($KM_USER['sub'] ?? '') : null
            );
            // 呼び名は記録に写さない(人の名前が入りうる)。番号だけ
            km_admin_log_record('content', 'guest.link_create', '#' . $created['id']);
            // **URL に載せずに**、次の画面へ 1 回だけ渡す
            $_SESSION['km_guest_link_new'] = [
                'id' => $created['id'],
                'url' => km_map_guest_url($created['token']),
                'expiresAt' => $created['expiresAt'],
            ];
            header('Location: ./guest-links.php?created=1', true, 302);
            exit;
        }
        if ($action === 'revoke') {
            $id = (int) ($_POST['id'] ?? 0);
            if (km_map_guest_revoke($pdo, $id)) {
                km_admin_log_record('content', 'guest.link_revoke', '#' . $id);
            }
            header('Location: ./guest-links.php?revoked=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('guest-links.php ' . $action . ' failed: ' . $exception->getMessage());
        $errors[] = km_admin_error_message($exception, '処理できませんでした。');
    }
}

// 発行したばかりのリンク。**1 回見せたら消す**
$newLink = null;
if (isset($_GET['created'], $_SESSION['km_guest_link_new']) && is_array($_SESSION['km_guest_link_new'])) {
    $newLink = $_SESSION['km_guest_link_new'];
}
unset($_SESSION['km_guest_link_new']);
if (isset($_GET['revoked'])) {
    $notice = 'revokedNotice';
}

/*
 * いまの地図の公開設定で、お試しの人に氏名が見えるか。**見えない設定なら先に言う** ——
 * リンクを渡したのに何も変わらない、にしない。
 */
$config = km_map_access_config();
$configNote = match (true) {
    $config['mode'] === 'public' => 'public',
    $config['mode'] === 'hidden' && !$config['teacherSeesHidden'] => 'hidden',
    default => null,
};

$links = [];
$dbError = null;
try {
    $links = km_map_guest_list(km_db());
} catch (Throwable $exception) {
    error_log('guest-links.php list failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

$statusLabels = [
    'active' => ['text' => '使える', 'class' => 'text-bg-success', 'key' => 'page.guestLinks.statusActive'],
    'used_up' => ['text' => '台数を使い切った', 'class' => 'text-bg-secondary', 'key' => 'page.guestLinks.statusUsedUp'],
    'expired' => ['text' => '期限切れ', 'class' => 'text-bg-secondary', 'key' => 'page.guestLinks.statusExpired'],
    'revoked' => ['text' => '取り消し済み', 'class' => 'text-bg-danger', 'key' => 'page.guestLinks.statusRevoked'],
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
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.guestLinks.<?= km_e($notice) ?>">取り消しました。</div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.guestLinks.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.guestLinks.notice">
                リンクを開いてボタンを押したブラウザだけが、期限まで教職員と同じように地図を見られます(地図のパスワードが要らず、教員の地点の名前が見えます)。
                Logto のアカウントは要りません。リンクを知っている人は誰でも使えるので、短い期限と少ない台数にしてください。
              </div>
            </div>

            <?php if ($configNote === 'hidden'): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.guestLinks.hiddenWarning">
                いまの地図の公開設定では教職員氏名を「常に隠す」にしているため、リンクを使っても氏名は出ません。
                出すには「地図の公開設定」で「常に隠すでも教職員には見せる」を ON にしてください(教職員にも出るようになります)。
              </div>
            <?php elseif ($configNote === 'public'): ?>
              <div class="alert alert-secondary" role="alert" data-i18n="page.guestLinks.publicNote">
                いまは教職員氏名を誰にでも見せる設定なので、リンクが無くても見えます。
              </div>
            <?php endif; ?>

            <?php if ($newLink !== null): ?>
              <div class="card border-success mb-3">
                <div class="card-header text-bg-success">
                  <span data-i18n="page.guestLinks.newTitle">リンクを発行しました</span>(#<?= (int) $newLink['id'] ?>)
                </div>
                <div class="card-body">
                  <p class="mb-2">
                    <strong data-i18n="page.guestLinks.newOnce">このリンクはこの画面でしか表示しません。</strong>
                    <span data-i18n="page.guestLinks.newCopy">いまコピーして相手に渡してください。</span>
                    <span class="text-body-secondary">(<?= km_e(date('Y/m/d H:i', (int) $newLink['expiresAt'])) ?> まで)</span>
                  </p>
                  <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="km-guest-new-url" value="<?= km_e((string) $newLink['url']) ?>" readonly />
                    <button type="button" class="btn btn-outline-success" id="km-guest-copy">
                      <i class="bi bi-clipboard me-1" aria-hidden="true"></i><span data-i18n="page.guestLinks.copy">コピー</span>
                    </button>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="row">
              <div class="col-lg-4">
                <div class="card mb-3">
                  <div class="card-header"><h3 class="card-title mb-0" data-i18n="page.guestLinks.createTitle">発行する</h3></div>
                  <form method="post" class="card-body">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="action" value="create" />
                    <div class="mb-3">
                      <label class="form-label" for="km-guest-label" data-i18n="page.guestLinks.label">呼び名(誰に渡すか。管理画面で見分けるためだけに使います)</label>
                      <input type="text" class="form-control" id="km-guest-label" name="label" maxlength="64" required />
                    </div>
                    <div class="mb-3">
                      <label class="form-label" for="km-guest-days" data-i18n="page.guestLinks.days">期限</label>
                      <select class="form-select" id="km-guest-days" name="days">
                        <?php foreach (KM_MAP_GUEST_DAY_CHOICES as $days): ?>
                          <option value="<?= (int) $days ?>"<?= $days === 1 ? ' selected' : '' ?>><?= (int) $days ?> 日</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label" for="km-guest-uses" data-i18n="page.guestLinks.maxUses">使える台数(ブラウザの数)</label>
                      <input type="number" class="form-control" id="km-guest-uses" name="max_uses" min="1" max="<?= (int) KM_MAP_GUEST_MAX_USES ?>" value="1" required />
                    </div>
                    <button type="submit" class="btn btn-primary">
                      <i class="bi bi-link-45deg me-1" aria-hidden="true"></i><span data-i18n="page.guestLinks.create">発行する</span>
                    </button>
                  </form>
                </div>
              </div>

              <div class="col-lg-8">
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0" data-i18n="page.guestLinks.listTitle">発行したリンク</h3></div>
                  <div class="card-body p-0">
                    <?php if ($links === [] && $dbError === null): ?>
                      <p class="text-center text-body-secondary py-5 mb-0" data-i18n="page.guestLinks.empty">まだ発行していません。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                          <thead>
                            <tr>
                              <th>#</th>
                              <th data-i18n="page.guestLinks.colLabel">呼び名</th>
                              <th data-i18n="page.guestLinks.colExpires">期限</th>
                              <th class="text-end" data-i18n="page.guestLinks.colUses">使った台数</th>
                              <th data-i18n="page.guestLinks.colStatus">状態</th>
                              <th></th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($links as $row): ?>
                              <?php $status = $statusLabels[$row['status']] ?? $statusLabels['expired']; ?>
                              <tr>
                                <td><?= (int) $row['id'] ?></td>
                                <td><?= km_e($row['label']) ?></td>
                                <td><?= km_e(date('Y/m/d H:i', $row['expiresAt'])) ?></td>
                                <td class="text-end"><?= (int) $row['uses'] ?> / <?= (int) $row['maxUses'] ?></td>
                                <td><span class="badge <?= km_e($status['class']) ?>" data-i18n="<?= km_e($status['key']) ?>"><?= km_e($status['text']) ?></span></td>
                                <td class="text-end">
                                  <?php if (!$row['revoked'] && $row['status'] !== 'expired'): ?>
                                    <form method="post" class="d-inline km-guest-revoke-form">
                                      <?= km_csrf_field() ?>
                                      <input type="hidden" name="action" value="revoke" />
                                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>" />
                                      <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <span data-i18n="page.guestLinks.revoke">取り消す</span>
                                      </button>
                                    </form>
                                  <?php endif; ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="card-footer fs-7 text-body-secondary" data-i18n="page.guestLinks.footer">
                    取り消すと、そのリンクで開いているブラウザも、次に地図を開いたときに見えなくなります。期限の切れたリンクは 30 日後に一覧から消えます。
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            const copy = document.getElementById('km-guest-copy');
            const field = document.getElementById('km-guest-new-url');
            if (copy && field) {
              copy.addEventListener('click', async () => {
                field.select();
                try {
                  await navigator.clipboard.writeText(field.value);
                } catch (error) {
                  document.execCommand('copy');
                }
                copy.classList.replace('btn-outline-success', 'btn-success');
              });
            }
            document.querySelectorAll('.km-guest-revoke-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                if (!window.confirm(window.KmI18n ? window.KmI18n.t('page.guestLinks.confirmRevoke') : 'revoke?')) {
                  event.preventDefault();
                }
              });
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
