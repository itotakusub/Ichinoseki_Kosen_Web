<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/logto-management.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/profile.php';

$search = trim((string) ($_GET['q'] ?? ''));

/*
 * ユーザーの正本は Logto なので、ここは読み取り専用の一覧にする。ローカル DB に
 * ユーザー表を持つと正本が二重化して必ず食い違うため、意図的に作らない。
 * 追加・停止・ロール変更は Logto Console 側で行ってもらう。
 */
$users = [];
$logtoError = null;
try {
    $users = km_logto_users(50, $search);
} catch (Throwable $exception) {
    error_log('users.php: ' . $exception->getMessage());
    // 下でそのまま表示するので、Logto や設定の文面は入れない(lib/user-error.php)
    $logtoError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

/*
 * この管理画面で設定したプロフィール画像。Logto の avatar より優先する
 * (利用者が「この画面で」設定したものが、この画面に出ないのは分かりにくいため)。
 * 人数ぶん問い合わせを飛ばさないよう、一覧に出る id をまとめて1回で引く。
 * Logto と DB は別系統なので、DB が落ちていても一覧自体は出す(fail soft)。
 */
$avatarMap = [];
try {
    $avatarMap = km_profile_avatar_map(km_db(), array_column($users, 'id'));
} catch (Throwable $exception) {
    error_log('users.php avatar lookup failed (fall back to Logto): ' . $exception->getMessage());
}

/** 表示に使う画像を1人ぶん決める。優先順は「この画面で設定 → Logto → 既定」。 */
$avatarFor = static function (array $user) use ($avatarMap): string {
    $id = (string) ($user['id'] ?? '');
    if (isset($avatarMap[$id])) {
        return './api/avatar.php?user=' . rawurlencode($id) . '&v=' . $avatarMap[$id];
    }

    return (string) ($user['avatar'] ?? '') !== '' ? (string) $user['avatar'] : KM_AVATAR_FALLBACK;
};

$KM_PAGE = [
    'nav' => 'users',
    'titleKey' => 'page.users.title',
    'title' => 'ユーザー管理 | KosenMap 管理',
    'h1Key' => 'page.users.h1',
    'h1' => 'ユーザー管理',
    'crumbs' => [
        ['key' => 'page.users.h1', 'text' => 'ユーザー管理'],
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
            <!--begin::Notice-->
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                <strong data-i18n="page.users.sourceTitle">ユーザーの正本は Logto です</strong>
                <div data-i18n="page.users.sourceText">
                  この一覧は Logto Management API から取得した実データです。追加・停止・ロールの
                  変更は Logto Console で行ってください(この画面からは変更できません)。
                </div>
              </div>
            </div>
            <!--end::Notice-->

            <?php if ($logtoError !== null): ?>
              <div class="alert alert-warning" role="alert">
                <div data-i18n="page.users.apiError">Logto からユーザー一覧を取得できませんでした。</div>
                <hr />
                <div class="fs-7 mb-0"><?= km_e($logtoError) ?></div>
              </div>
            <?php endif; ?>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12">
                <!--begin::Card-->
                <div class="card">
                  <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h3 class="card-title mb-0" data-i18n="page.users.cardTitle">ユーザー一覧</h3>
                    <div class="card-tools ms-auto d-flex flex-wrap gap-2">
                      <form method="get" class="input-group input-group-sm km-w-14">
                        <span class="input-group-text bg-body">
                          <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input
                          type="search"
                          name="q"
                          class="form-control"
                          value="<?= km_e($search) ?>"
                          placeholder="ユーザーを検索..."
                          aria-label="ユーザーを検索"
                          data-i18n-attr="placeholder:page.users.searchPlaceholder;aria-label:page.users.searchPlaceholder"
                        />
                      </form>
                      <a
                        href="<?= km_e(km_site_url('logto-admin', '/')) ?>"
                        class="btn btn-sm btn-outline-primary"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                        <span data-i18n="page.users.openLogto">Logto Console で管理</span>
                      </a>
                    </div>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col" data-i18n="page.users.colUser">ユーザー</th>
                            <th scope="col" data-i18n="page.users.colEmail">メールアドレス</th>
                            <th scope="col" data-i18n="page.users.colRole">ロール</th>
                            <th scope="col" data-i18n="page.users.colLastSignIn">最終ログイン</th>
                            <th scope="col" data-i18n="page.users.colStatus">状態</th>
                            <th scope="col" class="text-end" data-i18n="common.actions">操作</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if ($users === []): ?>
                            <tr>
                              <td colspan="6" class="text-center text-body-secondary py-5">
                                <i class="bi bi-people fs-2 d-block mb-2" aria-hidden="true"></i>
                                <?php if ($logtoError !== null): ?>
                                  <span data-i18n="page.users.unavailable">一覧を表示できません。</span>
                                <?php elseif ($search !== ''): ?>
                                  <span data-i18n="page.users.noMatch">条件に一致するユーザーがいません。</span>
                                <?php else: ?>
                                  <span data-i18n="page.users.empty">ユーザーがまだ登録されていません。</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endif; ?>
                          <?php foreach ($users as $user): ?>
                            <?php $isAdmin = in_array('kosenmap-admin', $user['roles'], true); ?>
                            <tr>
                              <td>
                                <img
                                  src="<?= km_e($avatarFor($user)) ?>"
                                  class="rounded-circle me-2 km-avatar-img"
                                  width="32"
                                  height="32"
                                  alt=""
                                />
                                <?= km_e($user['name']) ?>
                              </td>
                              <td><?= $user['email'] !== '' ? km_e($user['email']) : '—' ?></td>
                              <td>
                                <?php if ($user['roles'] === []): ?>
                                  <span class="badge text-bg-secondary" data-i18n="common.noData">データなし</span>
                                <?php else: ?>
                                  <?php foreach ($user['roles'] as $role): ?>
                                    <span class="badge <?= $role === 'kosenmap-admin' ? 'text-bg-danger' : 'text-bg-primary' ?>">
                                      <?= km_e($role) ?>
                                    </span>
                                  <?php endforeach; ?>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?php // Logto に「オンライン」の概念は無いので、最終ログイン時刻を出す。
                                      // 時刻は epoch で受け取っているので、DB/PHP のタイムゾーン差の影響を受けない。 ?>
                                <?= $user['lastSignInAtEpoch'] !== null
                                    ? km_e(date('Y-m-d H:i', $user['lastSignInAtEpoch']))
                                    : '—' ?>
                              </td>
                              <td>
                                <?php if ($user['isSuspended']): ?>
                                  <span class="badge text-bg-warning" data-i18n="page.users.statusSuspended">停止中</span>
                                <?php elseif ($user['id'] === ($KM_USER['sub'] ?? null)): ?>
                                  <span class="badge text-bg-info" data-i18n="page.users.statusYou">本人</span>
                                <?php else: ?>
                                  <span class="badge text-bg-success" data-i18n="page.users.statusActive">有効</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-end">
                                <a
                                  href="<?= km_e(km_site_url('logto-admin', '/console/users/' . rawurlencode($user['id']))) ?>"
                                  class="btn btn-sm btn-outline-secondary"
                                  target="_blank"
                                  rel="noopener noreferrer"
                                >
                                  <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                                  <span data-i18n="common.edit">編集</span>
                                </a>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <!-- /.card-body -->
                  <div class="card-footer">
                    <span class="text-body-secondary fs-7" data-i18n="page.users.footer">
                      Logto の role: kosenmap-admin / kosenmap-user
                    </span>
                  </div>
                </div>
                <!--end::Card-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
