<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/distributables.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/user-error.php';

$KM_PAGE = [
    'nav' => 'downloads',
    'titleKey' => 'page.downloads.title',
    'title' => 'ダウンロード | KosenMap 管理',
    'h1Key' => 'page.downloads.h1',
    'h1' => 'ダウンロード',
    'crumbs' => [
        ['key' => 'page.downloads.h1', 'text' => 'ダウンロード'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $slug = (string) ($_POST['slug'] ?? '');
    $action = isset($_POST['do_delete']) ? 'delete' : 'upload';

    try {
        $pdo = km_db();

        if ($action === 'delete') {
            km_dist_delete($pdo, $slug);
            km_admin_log_record('content', 'dist.delete', $slug);
            header('Location: ./downloads.php?deleted=1', true, 302);
            exit;
        }

        km_dist_store(
            $pdo,
            $slug,
            $_FILES['file'] ?? [],
            (string) ($_POST['version_label'] ?? ''),
            $KM_USER['name'] ?? null,
            // 版番号(アプリの versionCode。2026-10-05)。アプリの自動更新が比べる
            ctype_digit(trim((string) ($_POST['version_code'] ?? ''))) ? (int) trim((string) $_POST['version_code']) : null
        );
        km_admin_log_record('content', 'dist.update', $slug);
        header('Location: ./downloads.php?uploaded=1', true, 302);
        exit;
    } catch (Throwable $exception) {
        error_log('downloads.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文をそのまま出さない。利用者向けの文は KmUserError / InvalidArgumentException で届く
        $errors[] = km_admin_error_message($exception, '配布ファイルを保存できませんでした。');
    }
}

foreach ([
    'uploaded' => 'uploadedNotice',
    'deleted' => 'deletedNotice',
] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

// アプリの受け箱(2026-10-09。lib/apk-inbox.php)。読めなくても画面は出す
$inboxRecent = [];
try {
    require_once dirname(__DIR__) . '/lib/apk-inbox.php';
    $inboxRecent = km_apk_inbox_recent(km_db(), 3);
} catch (Throwable $exception) {
    error_log('downloads.php inbox failed: ' . $exception->getMessage());
}

$dist = [];
try {
    $dist = km_dist_all(km_db());
} catch (Throwable $exception) {
    error_log('downloads.php failed: ' . $exception->getMessage());
    $errors[] = 'データベースに接続できないため、配布物の状態を読み込めません。';
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
            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.downloads.<?= km_e($notice) ?>">
                保存しました。
              </div>
            <?php endif; ?>

            <?php
              /**
               * 「差し替え」フォームを1枚描く。実体の有無で「差し替え」か「新規に置く」かの
               * 文言だけが変わる。
               *
               * いま使うのは APK の1枚だけ(証明書のカードは 1.0.5 で外した)。
               * それでも関数のまま残す —— 配布物が増えたときに、同じ形をもう一度書かせない。
               */
              $renderUploadForm = static function (string $slug, ?array $row): void {
                  $spec = KM_DISTRIBUTABLES[$slug];
                  ?>
                  <form method="post" enctype="multipart/form-data" class="border-top pt-3 mt-3">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="slug" value="<?= km_e($slug) ?>" />
                    <div class="mb-2">
                      <label class="form-label fs-7" for="km-dist-<?= km_e($slug) ?>">
                        <span data-i18n="page.downloads.replaceField">差し替えるファイル</span>
                        <span class="text-body-secondary">
                          (<?= km_e(implode(' / ', $spec['extensions'])) ?>,
                          <?= km_e(km_upload_format_size($spec['maxBytes'])) ?>
                          <span data-i18n="page.downloads.upTo">まで</span>)
                        </span>
                      </label>
                      <input
                        type="file"
                        class="form-control form-control-sm"
                        id="km-dist-<?= km_e($slug) ?>"
                        name="file"
                        required
                      />
                    </div>
                    <div class="mb-2">
                      <label class="form-label fs-7" for="km-ver-<?= km_e($slug) ?>" data-i18n="page.downloads.versionField">
                        バージョン(任意)
                      </label>
                      <input
                        type="text"
                        class="form-control form-control-sm"
                        id="km-ver-<?= km_e($slug) ?>"
                        name="version_label"
                        maxlength="64"
                        value="<?= km_e((string) ($row['versionLabel'] ?? '')) ?>"
                      />
                    </div>
                    <?php if (($spec['needsVersionCode'] ?? false) === true): ?>
                      <div class="mb-2">
                        <label class="form-label fs-7" for="km-code-<?= km_e($slug) ?>" data-i18n="page.downloads.versionCodeField">
                          版番号(空なら APK の中から読みます。入れたときは APK の中の値と照らします)
                        </label>
                        <input
                          type="number"
                          class="form-control form-control-sm"
                          id="km-code-<?= km_e($slug) ?>"
                          name="version_code"
                          min="1"
                          max="<?= (int) KM_DIST_MAX_VERSION_CODE ?>"
                        />
                        <div class="form-text" data-i18n="page.downloads.versionCodeHint">
                          アプリは、この数が自分より大きいときだけ「新しい版があります」と知らせます。SHA-256 はサーバーで計算します。
                        </div>
                      </div>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                      <button type="submit" class="btn btn-outline-primary btn-sm" data-i18n="page.downloads.replaceButton">
                        差し替える
                      </button>
                      <?php if ($row !== null): ?>
                        <?php // 既定へ戻すだけなので、必須の file 入力に引っかからないよう検証を外す ?>
                        <button
                          type="submit"
                          name="do_delete"
                          value="1"
                          class="btn btn-outline-secondary btn-sm ms-auto"
                          formnovalidate
                          data-i18n="page.downloads.revertButton"
                        >登録を取り消す</button>
                      <?php endif; ?>
                    </div>
                  </form>
                  <?php
              };

              /** 現在登録されているものの説明行(いつ・誰が・どのファイル)。 */
              $renderCurrent = static function (?array $row): void {
                  if ($row === null) {
                      return;
                  }
                  ?>
                  <p class="text-body-secondary fs-7 mb-0">
                    <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>
                    <?php // ファイル名とバージョンは利用者が入れた値なので data-i18n は付けない ?>
                    <?= km_e((string) $row['originalName']) ?>
                    <?php if (($row['versionLabel'] ?? null) !== null): ?>
                      (<?= km_e((string) $row['versionLabel']) ?>)
                    <?php endif; ?>
                    <?php if (($row['versionCode'] ?? null) !== null): ?>
                      / <span data-i18n="page.downloads.versionCodeShort">版番号</span> <?= (int) $row['versionCode'] ?>
                    <?php endif; ?>
                    / <?= km_e(km_upload_format_size((int) $row['sizeBytes'])) ?>
                    <?php if (($row['sha256'] ?? null) !== null): ?>
                      / SHA-256 <code><?= km_e(substr((string) $row['sha256'], 0, 12)) ?>…</code>
                    <?php endif; ?>
                    / <?= km_e(date('Y-m-d H:i', (int) $row['updatedAtEpoch'])) ?>
                    <?php if (($row['updatedBy'] ?? null) !== null): ?>
                      / <?= km_e((string) $row['updatedBy']) ?>
                    <?php endif; ?>
                  </p>
                  <?php
              };
            ?>

            <?php
            /*
             * ここにあった「ローカル CA 証明書」のカードは 1.0.5 で外した。
             * 理由は index.php の同じ箇所を参照(本番は Let's Encrypt なので配る意味が無く、
             * 自前の CA を入れさせる導線を残す方が危ない)。
             *
             * **`certs/rootCA.pem` は消していない。** MariaDB の内部 TLS が同じ CA を使う。
             */
            ?>
            <?php
            /*
             * 「アプリへ配信中の地図」(期限・中身・停止・再開・削除)は 2026-11-02 に「地図の配信」(map-publish.php)へ移した
             * (利用者の指示「無理やり配置した項目を的確な場所へ」)。ここはアプリの配布(APK)だけ。
             */
            ?>
            <p class="fs-7 text-body-secondary">
              <i class="bi bi-broadcast me-1" aria-hidden="true"></i>アプリへ配っている地図の期限・停止・削除は<a href="./map-publish.php#map-releases">地図の配信</a>の画面にあります。
            </p>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-8">
                <!--begin::Card アプリの受け箱(2026-10-09)-->
                <div class="card mb-4 border-primary-subtle">
                  <div class="card-header">
                    <h3 class="card-title">アプリの受け箱(GitHub から)</h3>
                  </div>
                  <div class="card-body fs-7">
                    <?php $waiting = array_values(array_filter($inboxRecent, static fn (array $r): bool => $r['status'] === 'waiting')); ?>
                    <?php if ($waiting !== []): ?>
                      <?php foreach ($waiting as $w): ?>
                        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mb-2" role="alert">
                          <span>公開を待っている届け #<?= (int) $w['id'] ?>(版 <?= km_e((string) ($w['version_label'] ?? '?')) ?>)</span>
                          <a class="btn btn-sm btn-primary ms-auto" href="./apk-inbox.php?id=<?= (int) $w['id'] ?>">開いて公開する</a>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <p class="mb-2 text-body-secondary">
                        公開を待っている届けはありません。PC で <code>scripts\apk-push.ps1</code> を流すと、5 分ほどで届いてメールが来ます。
                      </p>
                    <?php endif; ?>
                    <a href="./apk-inbox.php">受け箱の一覧</a>
                    ・下のフォームから手で置き換えることもできます(今までどおり)。
                  </div>
                </div>
                <!--end::Card-->

                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.downloads.apkTitle">Android アプリ (APK)</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <?php if (isset($dist['apk'])): ?>
                      <p data-i18n="page.downloads.apkReady">
                        端末にインストールできる APK が登録されています。Android の設定で
                        「提供元不明のアプリ」を許可してからインストールしてください。
                      </p>
                      <div class="d-grid gap-2 d-sm-flex mb-2">
                        <a href="/api/download.php?slug=apk" class="btn btn-primary">
                          <i class="bi bi-download me-1" aria-hidden="true"></i>
                          <span data-i18n="page.downloads.apkButton">APK をダウンロード</span>
                        </a>
                      </div>
                    <?php else: ?>
                      <div class="text-center py-4">
                        <i class="bi bi-phone text-body-secondary km-icon-3rem"></i>
                        <p class="mt-3 mb-1 fw-semibold" data-i18n="page.downloads.apkNotReady">
                          まだ配布用の APK はありません
                        </p>
                        <p class="text-body-secondary fs-7 mb-0" data-i18n="page.downloads.apkHint">
                          ビルドができたら、下のフォームから置いてください。置いた時点で
                          ダウンロードできるようになります。
                        </p>
                      </div>
                    <?php endif; ?>
                    <?php $renderCurrent($dist['apk'] ?? null); ?>
                    <?php $renderUploadForm('apk', $dist['apk'] ?? null); ?>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->

                <!--begin::Card 管理用 APK(2026-10-05)-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.downloads.apkAdminTitle">管理用 Android アプリ (APK)</h3>
                  </div>
                  <div class="card-body">
                    <p class="fs-7 text-body-secondary" data-i18n="page.downloads.apkAdminHint">
                      管理アプリの自動アップデートが取りに来ます。ダウンロードには管理者のログインが要り、公開ページには出しません。
                    </p>
                    <?php $renderCurrent($dist['apk_admin'] ?? null); ?>
                    <?php $renderUploadForm('apk_admin', $dist['apk_admin'] ?? null); ?>
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
