<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/map-access.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$errors = [];
$saved = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $mode = (string) ($_POST['mode'] ?? '');
    $mapMode = (string) ($_POST['map_mode'] ?? '');
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    // hidden でも教職員には見せるか(docs/15 段 D)。チェックが無ければ見せない
    $teacherSeesHidden = ($_POST['teacher_sees_hidden'] ?? '') === '1';
    $hasPassword = km_map_access_config()['passwordHash'] !== null || $newPassword !== '';

    if (!in_array($mode, KM_MAP_ACCESS_MODES, true)) {
        $errors[] = '不正な公開モードです。';
    }
    if (!in_array($mapMode, KM_MAP_VIEW_MODES, true)) {
        $errors[] = '不正な地図の公開範囲です。';
    }
    // **どちらの錠にもパスワードが要る。** 鍵の無い錠を掛けさせない。
    if (($mode === 'password' || $mapMode === 'password') && !$hasPassword) {
        $errors[] = 'パスワードが未設定です。新しいパスワードを入力してください。';
    }
    if ($newPassword !== '' && mb_strlen($newPassword) < 8) {
        $errors[] = 'パスワードは 8 文字以上にしてください。';
    }

    if ($errors === []) {
        try {
            km_map_access_save($mode, $newPassword !== '' ? $newPassword : null, $mapMode, $teacherSeesHidden);
            $saved = true;
            km_admin_log_record(
                'settings',
                'map.settings',
                "氏名={$mode} / 地図={$mapMode} / hidden でも教職員に=" . ($teacherSeesHidden ? '見せる' : '見せない')
                    . ($newPassword !== '' ? ' / パスワード変更' : '')
            );
        } catch (Throwable $exception) {
            /*
             * **理由をそのまま出す。**
             *
             * 以前は「サーバーのログを確認してください」とだけ出していたが、ログを読むには
             * ホストへ入って `docker compose logs web` を叩く必要がある。管理者にそれを
             * 強いると、原因(多くは設定ファイルの権限)に辿り着けないまま詰む。
             *
             * ここは Logto の認証とスコープ確認を通った後なので、パスや権限の話が出ても
             * 見えるのは管理者だけ。ログにも従来どおり残す。
             *
             * 2026-09-14 に改めた: 管理画面でも例外の文面は出さず、照合用 ID にする
             * (lib/user-error.php。診断 server-ops#6)。いちばん多い原因だった書き込み権限は、
             * 下の $configWritable で**押す前に**画面へ出すようになっているので、詰みはしない。
             */
            error_log('map-settings.php save failed: ' . $exception->getMessage());
            $errors[] = km_admin_error_message($exception, '保存できませんでした。');
        }
    }
}

$config = km_map_access_config();

/*
 * **押す前に、保存できるかを見ておく。**
 *
 * この設定だけは DB ではなくファイル(`config/map-access.local.php`)に入っている ——
 * 解除パスワードのハッシュが入っており、配備物にも バックアップにも混ぜたくないため。
 * そのぶん、PHP に書き込み権限が無いと保存だけが失敗する。
 *
 * 失敗してから知らせるのでは、選び直した内容が消えたのか残ったのか分からない。
 * ここで先に出しておけば、触る前に権限を直せる。
 */
$configPath = km_map_access_config_path();
$configWritable = is_file($configPath)
    ? is_writable($configPath)
    : is_writable(dirname($configPath));

$nodeCount = 0;
$occupantCount = 0;
$dbError = null;
try {
    $pdo = km_db();
    $nodeCount = (int) $pdo->query('SELECT COUNT(*) FROM km_map_nodes')->fetchColumn();
    $occupantCount = (int) $pdo->query('SELECT COUNT(*) FROM km_map_nodes WHERE occupant_name IS NOT NULL')->fetchColumn();
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$KM_PAGE = [
    'nav' => 'mapSettings',
    'titleKey' => 'page.mapSettings.title',
    'title' => '地図データ公開設定 | KosenMap 管理',
    'h1Key' => 'page.mapSettings.h1',
    'h1' => '地図データ公開設定',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.mapSettings.h1', 'text' => '地図データ公開設定'],
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
            <?php if ($saved): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.mapSettings.saved">
                設定を保存しました。
              </div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.mapSettings.dbError">
                データベースに接続できません。マイグレーションが未実行の可能性があります。
              </div>
            <?php endif; ?>
            <?php if (!$configWritable): ?>
              <div class="alert alert-warning" role="alert">
                <strong data-i18n="page.mapSettings.notWritable">設定ファイルに書き込めません。</strong>
                <span data-i18n="page.mapSettings.notWritableHint">
                  このままでは保存できません。ホストで次を実行してから、もう一度お試しください。
                </span>
                <pre class="mb-0 mt-2"><code>docker compose exec -u root web chown www-data config/<?= km_e(basename($configPath)) ?>
docker compose exec -u root web chmod 640 config/<?= km_e(basename($configPath)) ?></code></pre>
              </div>
            <?php endif; ?>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-6">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.mapSettings.statusTitle">現在の状態</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <dl class="row mb-0">
                      <dt class="col-6" data-i18n="page.mapSettings.nodeCount">地図上の場所の総数</dt>
                      <dd class="col-6 text-end"><?= $nodeCount ?></dd>
                      <dt class="col-6" data-i18n="page.mapSettings.occupantCount">教職員氏名を含む件数</dt>
                      <dd class="col-6 text-end"><?= $occupantCount ?></dd>
                      <?php
                      /*
                       * **2つの軸を両方出す。** 以前は氏名の mode しか出しておらず、
                       * 「パスワードが必要」を保存しても、それが効いているのかを
                       * この画面で確かめる手段が無かった。
                       */
                      ?>
                      <dt class="col-6" data-i18n="page.mapSettings.currentMapMode">現在の地図の公開範囲</dt>
                      <dd class="col-6 text-end">
                        <span class="badge <?= $config['mapMode'] === 'password' ? 'text-bg-warning' : 'text-bg-success' ?>"
                              data-i18n="page.mapSettings.mapMode.<?= km_e($config['mapMode']) ?>">
                          <?= km_e($config['mapMode']) ?>
                        </span>
                      </dd>
                      <dt class="col-6" data-i18n="page.mapSettings.currentMode">現在の氏名のモード</dt>
                      <dd class="col-6 text-end">
                        <span class="badge text-bg-secondary" data-i18n="page.mapSettings.mode.<?= km_e($config['mode']) ?>">
                          <?= km_e($config['mode']) ?>
                        </span>
                      </dd>
                      <dt class="col-6" data-i18n="page.mapSettings.passwordSet">パスワード</dt>
                      <dd class="col-6 text-end">
                        <?php if ($config['passwordHash'] !== null): ?>
                          <span class="badge text-bg-success" data-i18n="page.mapSettings.passwordYes">設定済み</span>
                        <?php else: ?>
                          <span class="badge text-bg-danger" data-i18n="page.mapSettings.passwordNo">未設定</span>
                        <?php endif; ?>
                      </dd>
                    </dl>

                    <?php if ($config['mapMode'] === 'password'): ?>
                      <?php
                      /*
                       * **確かめ方を書いておく。** 管理者のブラウザは管理画面を通った印を
                       * 持っているが、その印は公開ページには効かない(lib/map-access.php)。
                       * それでも「自分の画面で見えている = 効いていない」と読まれやすいので、
                       * どう確かめるかをここに置く。
                       */
                      ?>
                      <div class="alert alert-info fs-7 mt-3 mb-0" role="alert">
                        <span data-i18n="page.mapSettings.verifyHint">
                          効いているかは、シークレットウィンドウでトップページを開いて確かめてください。
                          パスワードの入力欄が出れば掛かっています。
                        </span>
                      </div>
                    <?php endif; ?>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->
              </div>

              <div class="col-12 col-xl-6">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.mapSettings.formTitle">公開設定を変更</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <form method="post">
                      <?= km_csrf_field() ?>

                      <?php
                      /*
                       * **地図そのものの錠。** 下の「教職員氏名」とは別の軸。
                       * パスワードは共通で、一度入れれば両方が開く。
                       */
                      ?>
                      <h4 class="fs-6 mb-2" data-i18n="page.mapSettings.mapTitle">地図の公開範囲</h4>
                      <div class="mb-4">
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="radio"
                            name="map_mode"
                            value="public"
                            id="km-map-mode-public"
                            <?= $config['mapMode'] === 'public' ? 'checked' : '' ?>
                          />
                          <label class="form-check-label" for="km-map-mode-public">
                            <strong data-i18n="page.mapSettings.mapMode.public">誰でも見られる</strong>
                            <div class="text-body-secondary fs-7" data-i18n="page.mapSettings.mapMode.publicHint">
                              地図・経路・見取り図をパスワード無しで表示します。
                            </div>
                          </label>
                        </div>
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="radio"
                            name="map_mode"
                            value="password"
                            id="km-map-mode-password"
                            <?= $config['mapMode'] === 'password' ? 'checked' : '' ?>
                          />
                          <label class="form-check-label" for="km-map-mode-password">
                            <strong data-i18n="page.mapSettings.mapMode.password">パスワードが必要</strong>
                            <div class="text-body-secondary fs-7" data-i18n="page.mapSettings.mapMode.passwordHint">
                              入力するまで地点も経路も見取り図も出ません。トップページ・お問い合わせ・よくある質問は今までどおり見られます。
                            </div>
                          </label>
                        </div>
                      </div>

                      <h4 class="fs-6 mb-2" data-i18n="page.mapSettings.nameTitle">教職員氏名</h4>
                      <div class="mb-3">
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="radio"
                            name="mode"
                            value="hidden"
                            id="km-mode-hidden"
                            <?= $config['mode'] === 'hidden' ? 'checked' : '' ?>
                          />
                          <label class="form-check-label" for="km-mode-hidden">
                            <strong data-i18n="page.mapSettings.mode.hidden">常に隠す</strong>
                            <div class="text-body-secondary fs-7" data-i18n="page.mapSettings.mode.hiddenHint">
                              パスワードを知っていても教職員氏名は一切表示しません。
                            </div>
                          </label>
                        </div>
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="radio"
                            name="mode"
                            value="password"
                            id="km-mode-password"
                            <?= $config['mode'] === 'password' ? 'checked' : '' ?>
                          />
                          <label class="form-check-label" for="km-mode-password">
                            <strong data-i18n="page.mapSettings.mode.password">パスワードで解除</strong>
                            <div class="text-body-secondary fs-7" data-i18n="page.mapSettings.mode.passwordHint">
                              正しいパスワードを入力した人だけ、ブラウザセッション中は氏名を見られます。
                            </div>
                          </label>
                        </div>
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="radio"
                            name="mode"
                            value="public"
                            id="km-mode-public"
                            <?= $config['mode'] === 'public' ? 'checked' : '' ?>
                          />
                          <label class="form-check-label" for="km-mode-public">
                            <strong data-i18n="page.mapSettings.mode.public">常に公開</strong>
                            <div class="text-body-secondary fs-7" data-i18n="page.mapSettings.mode.publicHint">
                              パスワード不要で誰でも教職員氏名を見られます。
                            </div>
                          </label>
                        </div>
                      </div>
                      <?php
                      /*
                       * 教職員(docs/15)。教職員は password のときパスワード無しで通る。
                       * hidden のときにも見せるかは、ここで決める(既定は見せない)。
                       */
                      ?>
                      <div class="mb-3">
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="checkbox"
                            name="teacher_sees_hidden"
                            value="1"
                            id="km-teacher-sees-hidden"
                            <?= $config['teacherSeesHidden'] ? 'checked' : '' ?>
                          />
                          <label class="form-check-label" for="km-teacher-sees-hidden">
                            <strong data-i18n="page.mapSettings.teacherSeesHidden">「常に隠す」でも、承認した教職員には見せる</strong>
                            <div class="text-body-secondary fs-7" data-i18n="page.mapSettings.teacherSeesHiddenHint">
                              承認した教職員は、パスワード無しで地図と氏名を見られます(「パスワードで解除」のとき)。これを付けると「常に隠す」のときも教職員にだけは氏名を出します。
                            </div>
                          </label>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="km-new-password" class="form-label" data-i18n="page.mapSettings.newPassword">
                          新しいパスワード
                        </label>
                        <input
                          type="password"
                          class="form-control"
                          id="km-new-password"
                          name="new_password"
                          autocomplete="new-password"
                          minlength="8"
                        />
                        <div class="form-text" data-i18n="page.mapSettings.newPasswordHint">
                          空欄のままにすると、現在のパスワードを変更しません。8 文字以上。
                        </div>
                      </div>
                      <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                    </form>
                  </div>
                  <!-- /.card-body -->
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
