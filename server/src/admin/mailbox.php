<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/forms.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

$KM_PAGE = [
    'nav' => 'mailbox',
    'titleKey' => 'page.mailbox.title',
    'title' => 'メール | KosenMap 管理',
    'h1Key' => 'page.mailbox.h1',
    'h1' => 'メール',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.mailbox.h1', 'text' => 'メール'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        $pdo = km_db();

        if ($action === 'read' || $action === 'unread') {
            km_form_mark_read($pdo, $id, $action === 'read');
            header('Location: ./mailbox.php?marked=1', true, 302);
            exit;
        }
        if ($action === 'delete') {
            km_form_delete($pdo, $id);
            km_admin_log_record('content', 'form.delete', "#{$id}");
            header('Location: ./mailbox.php?deleted=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('mailbox.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文面をそのまま出さない(lib/user-error.php)。入力の検証だけはそのまま出る
        $errors[] = km_admin_error_message($exception, '処理できませんでした。');
    }
}

foreach (['marked' => 'markedNotice', 'deleted' => 'deletedNotice'] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

$messages = [];
$unread = 0;
$dbError = null;
try {
    $pdo = km_db();
    $messages = km_form_recent($pdo, 100);
    $unread = km_form_unread_count($pdo);
} catch (Throwable $exception) {
    error_log('mailbox.php list failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

$subjectLabels = [
    'bug' => ['key' => 'page.forms.subjectBug', 'text' => '不具合報告'],
    'feature' => ['key' => 'page.forms.subjectFeature', 'text' => '機能の要望'],
    'other' => ['key' => 'page.forms.subjectOther', 'text' => 'その他'],
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
              <div class="alert alert-success" role="alert" data-i18n="page.mailbox.<?= km_e($notice) ?>"></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.mailbox.dbError">
                データベースに接続できないため、一覧を表示できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.mailbox.notice">
                公開ページのお問い合わせフォームから届いた一覧です。実際に送信された通知メールそのものは
                Mailpit の画面で確認できます。
              </div>
            </div>

            <!--begin::Row-->
            <div class="row">
              <div class="col-md-3">
                <div class="card mb-3">
                  <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column">
                      <li class="nav-item">
                        <a href="./mailbox.php" class="nav-link active">
                          <i class="bi bi-inbox me-1" aria-hidden="true"></i>
                          <span data-i18n="page.mailbox.inbox">受信箱</span>
                          <?php if ($unread > 0): ?>
                            <span class="badge text-bg-primary float-end"><?= (int) $unread ?></span>
                          <?php endif; ?>
                        </a>
                      </li>
                      <?php
                        // 「スター付き」「送信済み」「ゴミ箱」に相当する機能は無い。
                        // 在るように見せない方針なので、無効のまま残して理由を注記する。
                      ?>
                      <li class="nav-item">
                        <span class="nav-link disabled" data-i18n="page.mailbox.starred">スター付き</span>
                      </li>
                      <li class="nav-item">
                        <span class="nav-link disabled" data-i18n="page.mailbox.sent">送信済み</span>
                      </li>
                      <li class="nav-item">
                        <span class="nav-link disabled" data-i18n="page.mailbox.trash">ゴミ箱</span>
                      </li>
                    </ul>
                  </div>
                  <div class="card-footer fs-7 text-body-secondary" data-i18n="page.mailbox.foldersNote">
                    受信箱以外は、対応する機能がまだ無いため無効にしています。
                  </div>
                </div>
              </div>

              <div class="col-md-9">
                <div class="card">
                  <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h3 class="card-title mb-0" data-i18n="page.mailbox.cardTitle">受信箱</h3>
                    <?php // フォームは公開ページに一本化した(フェーズ17)。ここからは開くだけ ?>
                    <a href="/contact.php" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary ms-auto">
                      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mailbox.openForm">公開フォームを開く</span>
                    </a>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body p-0">
                    <div class="accordion accordion-flush" id="km-mailbox-list">
                      <?php if ($messages === [] && $dbError === null): ?>
                        <p class="text-center text-body-secondary py-5 mb-0" data-i18n="page.mailbox.empty">
                          まだ問い合わせはありません。
                        </p>
                      <?php endif; ?>
                      <?php foreach ($messages as $message): ?>
                        <?php $mid = (int) $message['id']; ?>
                        <div class="accordion-item">
                          <h2 class="accordion-header">
                            <button
                              class="accordion-button collapsed"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#km-mail-<?= $mid ?>"
                            >
                              <?php if ((int) $message['isRead'] === 0): ?>
                                <span class="badge text-bg-primary me-2" data-i18n="page.mailbox.unread">未読</span>
                              <?php endif; ?>
                              <span class="me-2"><strong><?= km_e((string) $message['name']) ?></strong></span>
                              <span class="me-2 text-body-secondary">
                                <?php $s = (string) $message['subject']; ?>
                                <span data-i18n="<?= km_e($subjectLabels[$s]['key'] ?? 'common.noData') ?>">
                                  <?= km_e($subjectLabels[$s]['text'] ?? $s) ?>
                                </span>
                              </span>
                              <span class="ms-auto text-body-secondary fs-7">
                                <?php // epoch なので DB/PHP のタイムゾーン差の影響を受けない ?>
                                <?= km_e(date('Y-m-d H:i', (int) $message['createdAtEpoch'])) ?>
                              </span>
                            </button>
                          </h2>
                          <div id="km-mail-<?= $mid ?>" class="accordion-collapse collapse" data-bs-parent="#km-mailbox-list">
                            <div class="accordion-body">
                              <dl class="row fs-7 mb-3">
                                <dt class="col-sm-2" data-i18n="page.forms.fieldEmail">メールアドレス</dt>
                                <dd class="col-sm-10"><?= km_e((string) $message['email']) ?></dd>
                                <dt class="col-sm-2" data-i18n="page.mailbox.fromIp">送信元 IP</dt>
                                <dd class="col-sm-10"><?= km_e((string) ($message['ip'] ?? '—')) ?></dd>
                              </dl>
                              <?php // 本文は自由文なので nl2br + エスケープ。HTML は通さない ?>
                              <div class="mb-3"><?= nl2br(km_e((string) $message['body'])) ?></div>
                              <div class="d-flex gap-2">
                                <form method="post" class="d-inline">
                                  <?= km_csrf_field() ?>
                                  <input type="hidden" name="action" value="<?= (int) $message['isRead'] === 0 ? 'read' : 'unread' ?>" />
                                  <input type="hidden" name="id" value="<?= $mid ?>" />
                                  <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <span data-i18n="page.mailbox.<?= (int) $message['isRead'] === 0 ? 'markRead' : 'markUnread' ?>">
                                      <?= (int) $message['isRead'] === 0 ? '既読にする' : '未読に戻す' ?>
                                    </span>
                                  </button>
                                </form>
                                <form method="post" class="d-inline km-mail-delete-form">
                                  <?= km_csrf_field() ?>
                                  <input type="hidden" name="action" value="delete" />
                                  <input type="hidden" name="id" value="<?= $mid ?>" />
                                  <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                    <span data-i18n="common.delete">削除</span>
                                  </button>
                                </form>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <!-- /.card-body -->
                  <?php /* Mailpit はローカル環境だけ(本番は 8025 を publish していない。2026-09-18) */ ?>
                  <?php if (km_site_is_local()): ?>
                  <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                    <span class="text-body-secondary fs-7" data-i18n="page.mailbox.footer">
                      実際に送信されたメールは Mailpit で確認できます。
                    </span>
                    <a
                      href="<?= km_e(km_site_url('mailpit')) ?>"
                      class="btn btn-sm btn-outline-secondary ms-auto"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mailbox.openMailpit">Mailpit を開く</span>
                    </a>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            document.querySelectorAll('.km-mail-delete-form').forEach((form) => {
              form.addEventListener('submit', (event) => {
                if (!window.confirm(window.KmI18n ? window.KmI18n.t('page.mailbox.confirmDelete') : 'delete?')) {
                  event.preventDefault();
                }
              });
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
