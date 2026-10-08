<?php

declare(strict_types=1);

/**
 * お試しの閲覧リンク(lib/map-guest.php。2026-09-30、利用者の指示)。
 *
 * 期限と使える台数を決めてリンクを発行する。開いた人は仮アカウントを作り、そのブラウザだけ期限まで
 * 地図の錠を通れる。**教職員氏名は、ここで「教員名を見せる」にした仮アカウントだけ**に出る。途中で取り消せる。
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
        if ($action === 'switch') {
            // 機能の有効・無効と、表の読み取り専用(2026-10-05、利用者の指示)。切り替えは記録に残す
            $name = (string) ($_POST['name'] ?? '');
            $on = ($_POST['on'] ?? '') === '1';
            km_map_guest_set_switch($pdo, $name, $on);
            if ($name === KM_MAP_GUEST_SETTING_ENABLED) {
                km_admin_log_record('content', $on ? 'guest.feature_enable' : 'guest.feature_disable');
            } else {
                km_admin_log_record('content', $on ? 'guest.tables_lock' : 'guest.tables_unlock');
            }
            header('Location: ./guest-links.php?switched=1', true, 302);
            exit;
        }
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
        if ($action === 'set_names') {
            // 教員名は人ごとに許す(利用者の指示。2026-09-30)。開いているブラウザにも次に地図を開いたときに効く
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $allow = ($_POST['allow'] ?? '') === '1';
            if (km_map_guest_set_names($pdo, $accountId, $allow)) {
                km_admin_log_record('content', $allow ? 'guest.account_names_allow' : 'guest.account_names_deny', '#' . $accountId);
            }
            header('Location: ./guest-links.php?names=1', true, 302);
            exit;
        }
        if ($action === 'revoke_account') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            if (km_map_guest_revoke_account($pdo, $accountId)) {
                km_admin_log_record('content', 'guest.account_revoke', '#' . $accountId);
            }
            header('Location: ./guest-links.php?revoked=1', true, 302);
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
if (isset($_GET['switched'])) {
    $notice = ['key' => 'switchedNotice', 'text' => '切り替えました。'];
} elseif (isset($_GET['revoked'])) {
    $notice = ['key' => 'revokedNotice', 'text' => '取り消しました。'];
} elseif (isset($_GET['names'])) {
    $notice = ['key' => 'namesNotice', 'text' => '教員名の表示を変えました。相手が地図を開き直すと反映されます。'];
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
$accountsByLink = [];
$dbError = null;
$guestEnabled = true;
$guestReadonly = false;
try {
    $guestEnabled = km_map_guest_enabled(km_db());
    $guestReadonly = km_map_guest_readonly(km_db());
    $links = km_map_guest_list(km_db());
    $accountsByLink = km_map_guest_accounts_by_link(km_db());
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
              <div class="alert alert-success" role="alert" data-i18n="page.guestLinks.<?= km_e($notice['key']) ?>"><?= km_e($notice['text']) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.guestLinks.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <?php
            /*
             * 機能のスイッチ(2026-10-05)。無効 = 入口を止めるだけで何も消さない。
             * 読み取り専用 = 仮アカウントの表への書き込みを全部断る(入ることはできる)。
             */
            $switchForm = static function (string $name, bool $on, string $onKey, string $onText, string $offKey, string $offText, string $confirm): void {
                ?>
                <form method="post" class="d-inline" data-km-confirm="<?= km_e($confirm) ?>">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="action" value="switch">
                  <input type="hidden" name="name" value="<?= km_e($name) ?>">
                  <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                  <?php if ($on): ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger" data-i18n="<?= km_e($offKey) ?>"><?= km_e($offText) ?></button>
                  <?php else: ?>
                    <button type="submit" class="btn btn-sm btn-outline-success" data-i18n="<?= km_e($onKey) ?>"><?= km_e($onText) ?></button>
                  <?php endif; ?>
                </form>
                <?php
            };
            ?>
            <div class="card mb-3">
              <div class="card-header">
                <h3 class="card-title" data-i18n="page.guestLinks.switchTitle">機能のスイッチ</h3>
              </div>
              <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                  <span data-i18n="page.guestLinks.switchEnabled">お試しの閲覧リンク</span>
                  <?php if ($guestEnabled): ?>
                    <span class="badge text-bg-success" data-i18n="page.guestLinks.stateEnabled">有効</span>
                  <?php else: ?>
                    <span class="badge text-bg-danger" data-i18n="page.guestLinks.stateDisabled">無効(停止中)</span>
                  <?php endif; ?>
                  <?php $switchForm(KM_MAP_GUEST_SETTING_ENABLED, $guestEnabled, 'page.guestLinks.enable', '有効にする', 'page.guestLinks.disable', '無効にする',
                      $guestEnabled ? 'お試しの閲覧を止めます。リンクを開いても入れず、仮アカウントでも地図の錠を通れなくなります(何も消しません)。よろしいですか?' : 'お試しの閲覧を再開します。よろしいですか?'); ?>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <span data-i18n="page.guestLinks.switchReadonly">仮アカウントの表</span>
                  <?php if ($guestReadonly): ?>
                    <span class="badge text-bg-warning" data-i18n="page.guestLinks.stateLocked">読み取り専用(ロック中)</span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary" data-i18n="page.guestLinks.stateUnlocked">書ける</span>
                  <?php endif; ?>
                  <?php $switchForm(KM_MAP_GUEST_SETTING_READONLY, $guestReadonly, 'page.guestLinks.lock', 'ロックする', 'page.guestLinks.unlock', 'ロックを外す',
                      $guestReadonly ? '仮アカウントの表のロックを外します。よろしいですか?' : '仮アカウントの表を読み取り専用にします。発行・作成・取り消し・教員名の切り替えができなくなります(入ることはできます)。よろしいですか?'); ?>
                </div>
                <p class="text-body-secondary fs-7 mt-2 mb-0" data-i18n="page.guestLinks.switchHint">
                  無効にしても、リンク・仮アカウント・ブラウザの印は消えません。有効に戻すと元どおり入れます。
                  ロック中は、表への書き込み(発行・仮アカウントの作成・取り消し・教員名の切り替え・最後に見た時刻)をすべて断ります。
                </p>
              </div>
            </div>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.guestLinks.notice">
                リンクを開いた人は、名前(と所属)を入れて仮アカウントを作ると、期限まで地図のパスワード無しで地図を見られます。
                教員の地点の名前は、下の一覧で「教員名を見せる」にした仮アカウントだけに出ます(作っただけでは出ません)。
                仮アカウントはこのサーバーの DB にだけ作り、Logto のアカウントは要りません。誰がいつ作り、最後にいつ見たかは下の一覧で分かり、人ごとに止められます。
                リンクを知っている人は誰でも作れるので、短い期限と少ない人数にしてください。
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
                      <label class="form-label" for="km-guest-uses" data-i18n="page.guestLinks.maxUses">作れる仮アカウントの数(人数)</label>
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
                              <th class="text-end" data-i18n="page.guestLinks.colUses">作った数</th>
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
                              <?php // このリンクで作られた仮アカウント。**誰が見ているか**をここで確かめる ?>
                              <?php foreach ($accountsByLink[$row['id']] ?? [] as $account): ?>
                                <tr class="small">
                                  <td></td>
                                  <td colspan="2">
                                    <i class="bi bi-person me-1" aria-hidden="true"></i><?= km_e($account['name']) ?>
                                    <?php if ($account['affiliation'] !== null): ?>
                                      <span class="text-body-secondary">(<?= km_e($account['affiliation']) ?>)</span>
                                    <?php endif; ?>
                                  </td>
                                  <td class="text-end text-body-secondary">
                                    <span data-i18n="page.guestLinks.accountCreated">作成</span> <?= km_e(date('n/j H:i', $account['createdAt'])) ?><br>
                                    <span data-i18n="page.guestLinks.accountSeen">最後に見た</span>
                                    <?= $account['lastSeenAt'] !== null ? km_e(date('n/j H:i', $account['lastSeenAt'])) : '—' ?>
                                  </td>
                                  <td>
                                    <?php if ($account['revoked']): ?>
                                      <span class="badge text-bg-danger" data-i18n="page.guestLinks.accountStopped">止めた</span>
                                    <?php elseif ($account['namesAllowed']): ?>
                                      <span class="badge text-bg-warning" data-i18n="page.guestLinks.namesOn">教員名が見える</span>
                                    <?php else: ?>
                                      <span class="badge text-bg-light" data-i18n="page.guestLinks.namesOff">地図だけ</span>
                                    <?php endif; ?>
                                  </td>
                                  <td class="text-end text-nowrap">
                                    <?php if (!$account['revoked'] && $row['status'] !== 'expired' && !$row['revoked']): ?>
                                      <form method="post" class="d-inline<?= $account['namesAllowed'] ? '' : ' km-guest-names-form' ?>">
                                        <?= km_csrf_field() ?>
                                        <input type="hidden" name="action" value="set_names" />
                                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>" />
                                        <input type="hidden" name="allow" value="<?= $account['namesAllowed'] ? '0' : '1' ?>" />
                                        <?php if ($account['namesAllowed']): ?>
                                          <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <span data-i18n="page.guestLinks.namesDeny">教員名を隠す</span>
                                          </button>
                                        <?php else: ?>
                                          <button type="submit" class="btn btn-sm btn-outline-warning">
                                            <span data-i18n="page.guestLinks.namesAllow">教員名を見せる</span>
                                          </button>
                                        <?php endif; ?>
                                      </form>
                                      <form method="post" class="d-inline km-guest-revoke-form">
                                        <?= km_csrf_field() ?>
                                        <input type="hidden" name="action" value="revoke_account" />
                                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>" />
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                          <span data-i18n="page.guestLinks.stopAccount">この人を止める</span>
                                        </button>
                                      </form>
                                    <?php endif; ?>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
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
            // 教員名を見せるのは個人情報を渡すこと。押し間違いで許さないように確かめる
            document.querySelectorAll('.km-guest-names-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                if (!window.confirm(window.KmI18n ? window.KmI18n.t('page.guestLinks.confirmNames') : 'allow names?')) {
                  event.preventDefault();
                }
              });
            });
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
