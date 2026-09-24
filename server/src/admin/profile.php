<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/profile.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/logto-account.php';

$KM_PAGE = [
    'nav' => 'profile',
    'titleKey' => 'page.profile.title',
    'title' => 'プロフィール | KosenMap 管理',
    'h1Key' => 'page.profile.h1',
    'h1' => 'プロフィール',
    'crumbs' => [
        ['key' => 'side.extra', 'text' => 'その他ページ'],
        ['key' => 'page.profile.h1', 'text' => 'プロフィール'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['do_account'])) {
    /*
     * Logto のアカウント自己管理。**アバターの POST とは分けて受ける。**
     * 同じ分岐に混ぜると、画像の検証とパスワードの検証が絡み合って読めなくなる。
     */
    try {
        $token = $client->getAccessToken();
        if (!is_string($token) || $token === '') {
            throw new KmLogtoAccountException(
                'サインインの有効期限が切れています。入り直してからお試しください。'
            );
        }

        if (($_POST['do_account'] ?? '') === 'password') {
            km_logto_account_change_password(
                $token,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? '')
            );
            // **何を変えたかだけ残す。** パスワードそのものは記録しない
            km_admin_log_record('auth', 'account.password');
            header('Location: ./profile.php?password=1', true, 302);
            exit;
        }

        km_logto_account_update_profile($token, [
            'username' => (string) ($_POST['account_username'] ?? ''),
            'name' => (string) ($_POST['account_name'] ?? ''),
        ]);
        km_admin_log_record('auth', 'account.profile');
        header('Location: ./profile.php?account=1', true, 302);
        exit;
    } catch (KmLogtoAccountException $exception) {
        // 文言は利用者向けに作ってあるのでそのまま出す
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('profile.php account update failed: ' . $exception->getMessage());
        $errors[] = 'アカウントを更新できませんでした。';
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = isset($_POST['do_clear']) ? 'clear' : 'upload';

    try {
        $pdo = km_db();

        if ($action === 'clear') {
            km_profile_clear_avatar($pdo, (string) $KM_USER['sub']);
            km_admin_log_record('content', 'profile.avatar_clear');
            header('Location: ./profile.php?cleared=1', true, 302);
            exit;
        }

        km_profile_store_avatar($pdo, (string) $KM_USER['sub'], $_FILES['avatar'] ?? []);
        km_admin_log_record('content', 'profile.avatar_update');
        header('Location: ./profile.php?uploaded=1', true, 302);
        exit;
    } catch (Throwable $exception) {
        error_log('profile.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文面をそのまま出さない(lib/user-error.php)。画像の検証で断った文だけそのまま出る
        $errors[] = km_admin_error_message($exception, '画像を保存できませんでした。');
    }
}

foreach ([
    'uploaded' => 'uploadedNotice',
    'cleared' => 'clearedNotice',
    'account' => 'accountNotice',
    'password' => 'passwordNotice',
] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

/*
 * いまの値。**入力欄の初期値に使う。**
 *
 * 読めなくてもページは出す —— Account API が無効でも、プロフィール画像や
 * 操作記録は見られるべきなので、ここで止めない。読めなかったことは画面に出す。
 */
$account = null;
$accountUnavailable = false;
try {
    $token = $client->getAccessToken();
    $account = is_string($token) && $token !== '' ? km_logto_account_profile($token) : null;
    $accountUnavailable = $account === null;
} catch (Throwable $exception) {
    error_log('profile.php account fetch failed: ' . $exception->getMessage());
    $accountUnavailable = true;
}

$avatarSrc = km_profile_avatar_src($KM_USER);

/*
 * このアカウントの操作記録。
 *
 * **ここが読めなくてもプロフィールは出す。** 記録の不調でページごと開けなくなるのは
 * 割に合わない(km_admin_log_record() の fail soft と同じ考え方)。
 */
$activity = [];
$activityError = false;
try {
    $activity = km_admin_log_for_actor(km_db(), (string) $KM_USER['sub'], 20);
} catch (Throwable $exception) {
    error_log('profile.php activity failed: ' . $exception->getMessage());
    $activityError = true;
}

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
            <div class="row">
              <div class="col-md-4">
                <!--begin::Card-->
                <?php foreach ($errors as $message): ?>
                  <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
                <?php endforeach; ?>
                <?php if ($notice !== null): ?>
                  <div class="alert alert-success" role="alert" data-i18n="page.profile.<?= km_e($notice) ?>">
                    保存しました。
                  </div>
                <?php endif; ?>

                <div class="card">
                  <div class="card-body text-center">
                    <img
                      src="<?= km_e($avatarSrc) ?>"
                      class="rounded-circle shadow mb-3 km-avatar-img"
                      width="120"
                      height="120"
                      alt=""
                    />
                    <h3 class="mb-0"><?= km_e($KM_USER['name']) ?></h3>
                    <p class="text-body-secondary">
                      <?= km_e($KM_USER['roles'] !== [] ? implode(', ', $KM_USER['roles']) : '—') ?>
                    </p>
                    <a
                      href="<?= km_e(km_site_url('logto-admin', '/')) ?>"
                      class="btn btn-outline-primary btn-sm"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                      <span data-i18n="page.profile.editInLogto">Logto Console で編集</span>
                    </a>
                  </div>
                  <!-- /.card-body -->
                  <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                      <span data-i18n="page.users.colEmail">メールアドレス</span>
                      <strong><?= km_e($KM_USER['email'] !== '' ? $KM_USER['email'] : '—') ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                      <span data-i18n="user.userId">ユーザーID</span>
                      <code class="fs-7"><?= km_e($KM_USER['sub']) ?></code>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                      <span data-i18n="page.profile.scopes">保有スコープ</span>
                      <span class="text-end">
                        <?php if ($KM_USER['scopes'] === []): ?>
                          <span class="text-body-secondary">—</span>
                        <?php else: ?>
                          <?php foreach ($KM_USER['scopes'] as $scope): ?>
                            <span class="badge text-bg-secondary"><?= km_e($scope) ?></span>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </span>
                    </li>
                  </ul>
                </div>
                <!--end::Card-->

                <!--begin::Avatar-->
                <div class="card mt-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.profile.avatarTitle">プロフィール画像</h3>
                  </div>
                  <form method="post" enctype="multipart/form-data">
                    <div class="card-body">
                      <?= km_csrf_field() ?>
                      <div class="mb-3">
                        <label class="form-label" for="km-avatar" data-i18n="page.profile.avatarField">画像を選ぶ</label>
                        <input
                          type="file"
                          class="form-control"
                          id="km-avatar"
                          name="avatar"
                          accept="image/png,image/jpeg,image/gif,image/webp"
                          required
                        />
                        <div class="form-text" data-i18n="page.profile.avatarHint">
                          PNG / JPEG / GIF / WebP、2 MB まで。SVG は使えません。
                          設定した画像はログインした管理者だけが見られます。
                        </div>
                      </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                      <button type="submit" class="btn btn-primary" data-i18n="page.profile.avatarSave">
                        この画像にする
                      </button>
                      <?php // 既定へ戻すだけなので、必須の file 入力に引っかからないよう検証を外す ?>
                      <button
                        type="submit"
                        name="do_clear"
                        value="1"
                        class="btn btn-outline-secondary ms-auto"
                        formnovalidate
                        data-i18n="page.profile.avatarClear"
                      >既定に戻す</button>
                    </div>
                  </form>
                </div>
                <!--end::Avatar-->
              </div>

              <div class="col-md-8">
                <!--begin::Card-->
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.profile.aboutTitle">このアカウントについて</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <?php if ($accountUnavailable): ?>
                      <?php
                      /*
                       * **Account API を有効にしただけでは足りない。**
                       * 項目ごとに Off / ReadOnly / Edit があり、既定は Off。
                       * ここを書いておかないと、有効にしたつもりで断られ続ける。
                       */
                      ?>
                      <div class="alert alert-warning" role="alert" data-i18n="page.profile.accountUnavailable">
                        Logto のアカウント情報を読み込めませんでした。サインインし直しが必要か、
                        Logto Console の「Sign-in &amp; account」&gt;「Account center」で項目が
                        有効になっていない可能性があります(既定は Off です)。
                      </div>
                    <?php endif; ?>

                    <p data-i18n="page.profile.aboutText">
                      表示名とパスワードはここで変更できます。メールアドレス・電話番号・
                      多要素認証・パスキーは確認コードのやり取りが要るため、Logto の画面で行ってください。
                    </p>
                    <?php // 行き先を示す。「Logto の画面から」とだけ書くのは案内になっていない ?>
                    <p>
                      <a href="<?= km_e(km_logto_account_center_url()) ?>"
                         class="btn btn-outline-secondary btn-sm"
                         target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                        <span data-i18n="page.profile.openAccountCenter">Logto のアカウント画面を開く</span>
                      </a>
                    </p>

                    <form method="post" class="mb-4">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="do_account" value="profile">
                      <?php
                      /*
                       * **編集できる項目だけを入力欄にする。**
                       * 何が編集できるかは lib/logto-account.php の
                       * KM_LOGTO_ACCOUNT_EDITABLE(= Logto Console の設定と対)。
                       * 出しておいて保存時に断られるのが、いちばん質の悪い作りになる。
                       */
                      ?>
                      <div class="mb-3">
                        <label class="form-label" for="km-account-username" data-i18n="page.profile.accountUsername">
                          利用者名
                        </label>
                        <?php if (km_logto_account_field_editable('username')): ?>
                          <input
                            type="text"
                            class="form-control"
                            id="km-account-username"
                            name="account_username"
                            value="<?= km_e((string) ($account['username'] ?? '')) ?>"
                            autocomplete="username"
                          />
                        <?php else: ?>
                          <input
                            type="text"
                            class="form-control"
                            id="km-account-username"
                            value="<?= km_e((string) ($account['username'] ?? '')) ?>"
                            readonly
                          />
                          <div class="form-text" data-i18n="page.profile.accountUsernameLocked">
                            サインインに使う識別子のため変更できません。
                          </div>
                        <?php endif; ?>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-account-name" data-i18n="page.profile.accountName">
                          表示名
                        </label>
                        <input
                          type="text"
                          class="form-control"
                          id="km-account-name"
                          name="account_name"
                          value="<?= km_e((string) ($account['name'] ?? '')) ?>"
                        />
                        <div class="form-text" data-i18n="page.profile.accountNameHint">
                          アプリのランキングに出るのはこの名前です。メールアドレスは出ません。
                        </div>
                      </div>
                      <?php // 空欄の項目は変更しない。消す手段はここでは用意しない ?>
                      <button type="submit" class="btn btn-primary" data-i18n="page.profile.accountSave">
                        この内容にする
                      </button>
                      <span class="form-text ms-2" data-i18n="page.profile.accountBlankHint">
                        空欄にした項目は変更しません。
                      </span>
                    </form>

                    <hr>

                    <h4 class="fs-6 mb-2" data-i18n="page.profile.passwordTitle">パスワードの変更</h4>
                    <form method="post" class="mb-3">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="do_account" value="password">
                      <div class="mb-3">
                        <label class="form-label" for="km-current-password" data-i18n="page.profile.passwordCurrent">
                          現在のパスワード
                        </label>
                        <input
                          type="password"
                          class="form-control"
                          id="km-current-password"
                          name="current_password"
                          autocomplete="current-password"
                          required
                        />
                        <?php
                        /*
                         * **現在のパスワードを必ず聞く。** アクセストークンだけで変えられると、
                         * 端末を借りられた場面やトークンが漏れた場面でそのまま乗っ取られる。
                         */
                        ?>
                        <div class="form-text" data-i18n="page.profile.passwordCurrentHint">
                          本人であることの確認に使います。
                        </div>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-account-password" data-i18n="page.profile.passwordNew">
                          新しいパスワード
                        </label>
                        <input
                          type="password"
                          class="form-control"
                          id="km-account-password"
                          name="new_password"
                          autocomplete="new-password"
                          required
                        />
                        <div class="form-text" data-i18n="page.profile.passwordNewHint">
                          長さと文字種の条件は Logto の設定によります。
                        </div>
                      </div>
                      <button type="submit" class="btn btn-outline-primary" data-i18n="page.profile.passwordSave">
                        パスワードを変更する
                      </button>
                    </form>

                    <div class="alert alert-secondary fs-7 mb-0" role="alert" data-i18n="page.profile.roleHint">
                      admin:users:read を含む権限があるため、この管理画面に入れています。権限は
                      Logto の role: kosenmap-admin から発行されます。
                    </div>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->

                <!--begin::Activity-->
                <div class="card mt-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.profile.activityTitle">このアカウントの操作</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <p class="text-body-secondary fs-7" data-i18n="page.profile.activityHint">
                      直近 20 件です。身に覚えのない操作や、見慣れない接続元があれば、
                      Logto でパスワードを変えてください。
                    </p>

                    <?php if ($activityError): ?>
                      <div class="alert alert-warning mb-0" role="alert" data-i18n="page.profile.activityError">
                        操作記録を読み込めませんでした。
                      </div>
                    <?php elseif ($activity === []): ?>
                      <div class="text-center text-body-secondary py-4">
                        <i class="bi bi-clock-history fs-2 d-block mb-2" aria-hidden="true"></i>
                        <span data-i18n="page.profile.activityEmpty">まだ記録された操作はありません。</span>
                      </div>
                    <?php else: ?>
                      <?php // 幅が足りない画面では表を横に流す(ページ全体を横スクロールさせない) ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th scope="col" data-i18n="page.profile.activityWhen">日時</th>
                              <th scope="col" data-i18n="page.profile.activityWhat">操作</th>
                              <th scope="col" data-i18n="page.profile.activityFrom">接続元</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($activity as $entry): ?>
                              <?php
                                $category = (string) $entry['category'];
                                $look = KM_ADMIN_LOG_ICONS[$category] ?? KM_ADMIN_LOG_ICONS['system'];
                                $action = (string) $entry['action'];
                                /*
                                 * 文言は timeline.php と**同じ辞書**を引く(lib/admin-log.php)。
                                 * あちらは「<名前> が…しました」の形で、動詞側が「が」から始まる。
                                 *
                                 * ここは自分の記録しかないので、主語を「あなた」に差し替えて
                                 * そのまま繋ぐ。動詞側の辞書を2つ持つと、操作が増えるたびに
                                 * **両方直すことになり、必ず片方が古くなる。**
                                 */
                                $label = KM_ADMIN_LOG_ACTION_LABELS[$action] ?? $action;
                                $actionKey = 'log.action.' . str_replace('.', '_', $action);
                              ?>
                              <tr>
                                <td class="text-nowrap fs-7">
                                  <?= km_e(date('Y-m-d H:i', (int) $entry['createdAtEpoch'])) ?>
                                </td>
                                <td class="fs-7">
                                  <i class="bi <?= km_e($look['icon']) ?> me-1" aria-hidden="true"></i>
                                  <?php // 2つの span の間に改行を残す。英語で "You" と動詞が繋がってしまうため ?>
                                  <span data-i18n="log.actor.you">あなた</span>
                                  <span data-i18n="<?= km_e($actionKey) ?>"><?= km_e($label) ?></span>
                                  <?php if (($entry['detail'] ?? null) !== null): ?>
                                    <code class="d-block text-body-secondary"><?= km_e((string) $entry['detail']) ?></code>
                                  <?php endif; ?>
                                </td>
                                <td class="fs-7">
                                  <?php if (($entry['ip'] ?? null) !== null): ?>
                                    <code class="text-nowrap"><?= km_e((string) $entry['ip']) ?></code>
                                  <?php else: ?>
                                    <span class="text-body-secondary">—</span>
                                  <?php endif; ?>
                                  <?php
                                  /*
                                   * 端末の名乗り(User-Agent)。**そのまま出さず、短くする。**
                                   *
                                   * 生の User-Agent は 150 字を超えることがあり、
                                   * 出すと表が読めなくなって**IP の方まで読まれなくなる。**
                                   * ここで見たいのは「いつもと違う端末か」だけなので、
                                   * 見分けが付く程度に畳んで、全文は title に置く。
                                   *
                                   * 列がまだ無い環境では null が来る(移行 SQL は
                                   * 配備利用者が別に流す)。**そのときは何も出さない** ——
                                   * 「—」を出すと「送ってこなかった」と読めてしまう。
                                   */
                                  $userAgent = $entry['userAgent'] ?? null;
                                  if ($userAgent !== null):
                                  ?>
                                    <span
                                      class="d-block text-body-secondary"
                                      title="<?= km_e((string) $userAgent) ?>"
                                    ><?= km_e(km_admin_log_device_label((string) $userAgent)) ?></span>
                                  <?php endif; ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>

                    <?php
                    /*
                     * 端末の名乗り(User-Agent)は 2026-09-03 から記録している。
                     *
                     * **列は配備利用者が足す**(`scripts/migrate-admin-log-user-agent.sql`)。
                     * まだ流していない環境では、その欄が空のまま並ぶ ——
                     * 「記録していない」のか「この操作のときだけ無かった」のかが
                     * 分からないので、**流していないことを画面で言う。**
                     */
                    $userAgentReady = false;
                    try {
                        $userAgentReady = km_admin_log_has_user_agent(km_db());
                    } catch (Throwable $exception) {
                        // DB が読めないときは黙る。上に既にエラーが出ている
                    }
                    ?>
                    <div class="alert alert-secondary fs-7 mt-3 mb-0" role="alert">
                      <span data-i18n="page.profile.activityScope">
                        記録されるのはこの管理画面での操作だけです。
                        ログインの履歴そのものは Logto 側にあります。
                      </span>
                      <?php if (!$userAgentReady): ?>
                        <span class="d-block mt-1 text-warning-emphasis" data-i18n="page.profile.activityNoDevice">
                          端末の情報はまだ記録していません(移行 SQL が未実行です)。
                        </span>
                      <?php endif; ?>
                      <a href="./timeline.php" data-i18n="page.profile.activityAll">全員の操作を見る</a>
                    </div>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Activity-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
