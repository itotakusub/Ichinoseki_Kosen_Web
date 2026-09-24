<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/legal.php';
require_once dirname(__DIR__) . '/lib/legal-db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/*
 * 利用規約とプライバシーポリシーへ**章を足す**画面。
 *
 * ## 本文はここから直せない
 *
 * 本文は `lib/legal.php` にコードとして置いてあり、
 * **`scripts/check.php` が本文の一文を見て「実装と文書が食い違っていないか」を
 * 確かめている**(例: 「自動削除を約束していない」)。
 * DB へ移すと、配備のときに DB を見ないその検査から**何も見えなくなる**。
 *
 * 文書だけが実装から離れていくのは、この2つで一番起きやすい壊れ方なので、
 * 見張っている足場は崩さない。**運用で増えるもの**だけを、ここから足す。
 *
 * ## 作りは admin/faq.php と同じ
 *
 * 一覧・上下の入れ替え・公開の切り替え・同じフォームで追加と編集。
 * **2つ目の書き方を持ち込まない。**
 */

$KM_PAGE = [
    'nav' => 'legal',
    'titleKey' => 'page.legal.title',
    'title' => '規約とポリシー | KosenMap 管理',
    'h1Key' => 'page.legal.h1',
    'h1' => '規約とポリシー',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.legal.h1', 'text' => '規約とポリシー'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 削除は同じフォームから来るので、専用キーで意図を表す(faq.php と同じ)
    $action = isset($_POST['do_delete']) ? 'delete' : (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $document = (string) ($_POST['document'] ?? '');
    $heading = (string) ($_POST['heading'] ?? '');
    $body = (string) ($_POST['body'] ?? '');
    $isPublic = isset($_POST['is_public']);

    try {
        $pdo = km_db();

        if ($action === 'create') {
            km_legal_db_create($pdo, $document, $heading, $body, $isPublic);
            km_admin_log_record('content', 'legal.create', $document . ': ' . $heading);
            header('Location: ./legal.php?created=1', true, 302);
            exit;
        }
        if ($action === 'update') {
            km_legal_db_update($pdo, $id, $heading, $body, $isPublic);
            km_admin_log_record('content', 'legal.update', $heading);
            header('Location: ./legal.php?updated=1', true, 302);
            exit;
        }
        if ($action === 'delete') {
            km_legal_db_delete($pdo, $id);
            km_admin_log_record('content', 'legal.delete', "#{$id}");
            header('Location: ./legal.php?deleted=1', true, 302);
            exit;
        }
        if ($action === 'move') {
            $direction = (string) ($_POST['direction'] ?? '');
            km_legal_db_move($pdo, $id, $direction === 'up' ? 'up' : 'down');
            km_admin_log_record('content', 'legal.move', "#{$id} {$direction}");
            header('Location: ./legal.php?moved=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('legal.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文面をそのまま出さない(lib/user-error.php)。入力の検証だけはそのまま出る
        $errors[] = km_admin_error_message($exception, '保存できませんでした。');
    }
}

foreach ([
    'created' => 'createdNotice',
    'updated' => 'updatedNotice',
    'deleted' => 'deletedNotice',
    'moved' => 'movedNotice',
] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

/** 文書ごとの章。 */
$sectionsByDocument = [];
$dbError = null;
try {
    $pdo = km_db();
    foreach (KM_LEGAL_DOCUMENTS as $document) {
        $sectionsByDocument[$document] = km_legal_db_all($pdo, $document);
    }
} catch (Throwable $exception) {
    error_log('legal.php failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
    $sectionsByDocument = array_fill_keys(KM_LEGAL_DOCUMENTS, []);
}

/** コードにある章の数。**足せる場所がどこなのか**を出すために使う。 */
$builtIn = [
    'terms' => count(km_legal_terms()),
    'privacy' => count(km_legal_privacy()),
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
              <div class="alert alert-warning" role="alert" data-i18n="page.legal.dbError">
                データベースに接続できないため、足した章を読み込めません。
              </div>
            <?php endif; ?>

            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.legal.<?= km_e($notice) ?>">保存しました。</div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                <span data-i18n="page.legal.notice">
                  ここで足せるのは<strong>章</strong>です。本文はコード
                  (<code>src/lib/legal.php</code>)にあり、検査が「実装と食い違っていないか」を
                  見ているので、この画面からは変えられません。足した章は本文の<strong>後ろ</strong>に並びます。
                </span>
                <span class="d-block mt-1">
                  <a href="/terms.php" target="_blank" rel="noopener noreferrer" class="me-2">
                    <span data-i18n="page.legal.openTerms">利用規約を開く</span>
                    <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                  </a>
                  <a href="/privacy.php" target="_blank" rel="noopener noreferrer">
                    <span data-i18n="page.legal.openPrivacy">プライバシーポリシーを開く</span>
                    <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                  </a>
                </span>
              </div>
            </div>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-8">
                <?php foreach (KM_LEGAL_DOCUMENTS as $document): ?>
                  <?php $rows = $sectionsByDocument[$document] ?? []; ?>
                  <div class="card mb-4">
                    <div class="card-header d-flex align-items-center">
                      <h3 class="card-title mb-0">
                        <?= km_e(KM_LEGAL_DOCUMENT_LABELS[$document]) ?>
                      </h3>
                      <span class="ms-auto text-body-secondary fs-7">
                        <span data-i18n="page.legal.builtInCount">本文の章</span>
                        <strong><?= (int) ($builtIn[$document] ?? 0) ?></strong>
                        <span class="mx-1">/</span>
                        <span data-i18n="page.legal.addedCount">足した章</span>
                        <strong><?= count($rows) ?></strong>
                      </span>
                    </div>
                    <div class="card-body p-0">
                      <?php if ($rows === []): ?>
                        <p class="text-body-secondary text-center my-4" data-i18n="page.legal.empty">
                          足した章はまだありません。
                        </p>
                      <?php else: ?>
                        <div class="accordion accordion-flush" id="km-legal-<?= km_e($document) ?>">
                          <?php foreach ($rows as $i => $row): ?>
                            <div class="accordion-item">
                              <h2 class="accordion-header d-flex align-items-center">
                                <button
                                  class="accordion-button collapsed"
                                  type="button"
                                  data-bs-toggle="collapse"
                                  data-bs-target="#km-legal-item-<?= (int) $row['id'] ?>"
                                >
                                  <?php if ((int) $row['isPublic'] === 1): ?>
                                    <span class="badge text-bg-success me-2" data-i18n="page.legal.public">公開</span>
                                  <?php else: ?>
                                    <span class="badge text-bg-secondary me-2" data-i18n="page.legal.private">非公開</span>
                                  <?php endif; ?>
                                  <?php // 見出しは利用者が入れた自由文なので data-i18n は付けない ?>
                                  <?= km_e((string) $row['heading']) ?>
                                </button>
                              </h2>
                              <div id="km-legal-item-<?= (int) $row['id'] ?>" class="accordion-collapse collapse"
                                   data-bs-parent="#km-legal-<?= km_e($document) ?>">
                                <div class="accordion-body">
                                  <p class="mb-3 km-pre-wrap"><?= km_e((string) $row['body']) ?></p>
                                  <div class="d-flex gap-2">
                                    <button
                                      type="button"
                                      class="btn btn-outline-primary btn-sm km-legal-edit"
                                      data-id="<?= (int) $row['id'] ?>"
                                      data-document="<?= km_e((string) $row['document']) ?>"
                                      data-heading="<?= km_e((string) $row['heading']) ?>"
                                      data-body="<?= km_e((string) $row['body']) ?>"
                                      data-public="<?= (int) $row['isPublic'] ?>"
                                    >
                                      <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                                      <span data-i18n="common.edit">編集</span>
                                    </button>
                                    <form method="post" class="d-inline">
                                      <?= km_csrf_field() ?>
                                      <input type="hidden" name="action" value="move" />
                                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>" />
                                      <input type="hidden" name="direction" value="up" />
                                      <button type="submit" class="btn btn-outline-secondary btn-sm"
                                              aria-label="上へ移動" data-i18n-attr="aria-label:page.legal.moveUp"
                                              <?= $i === 0 ? 'disabled' : '' ?>
                                      ><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                                    </form>
                                    <form method="post" class="d-inline">
                                      <?= km_csrf_field() ?>
                                      <input type="hidden" name="action" value="move" />
                                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>" />
                                      <input type="hidden" name="direction" value="down" />
                                      <button type="submit" class="btn btn-outline-secondary btn-sm"
                                              aria-label="下へ移動" data-i18n-attr="aria-label:page.legal.moveDown"
                                              <?= $i === count($rows) - 1 ? 'disabled' : '' ?>
                                      ><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
                                    </form>
                                  </div>
                                </div>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="col-12 col-xl-4">
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title" id="km-legal-form-title" data-i18n="page.legal.addTitle">章を足す</h3>
                  </div>
                  <form method="post" id="km-legal-form">
                    <div class="card-body">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="action" id="km-legal-action" value="create" />
                      <input type="hidden" name="id" id="km-legal-id" value="" />

                      <div class="mb-3">
                        <label class="form-label" for="km-legal-document" data-i18n="page.legal.fieldDocument">文書</label>
                        <select class="form-select" id="km-legal-document" name="document">
                          <?php foreach (KM_LEGAL_DOCUMENTS as $document): ?>
                            <option value="<?= km_e($document) ?>"><?= km_e(KM_LEGAL_DOCUMENT_LABELS[$document]) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <?php
                        /*
                         * 編集では変えさせない。**規約の章がポリシーへ移ると、
                         * 読む人の前提が変わる。**
                         */
                        ?>
                        <div class="form-text" data-i18n="page.legal.documentHint">
                          あとから別の文書へは移せません。
                        </div>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-legal-heading" data-i18n="page.legal.fieldHeading">見出し</label>
                        <input type="text" class="form-control" id="km-legal-heading" name="heading"
                               maxlength="255" required />
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-legal-body" data-i18n="page.legal.fieldBody">本文</label>
                        <textarea class="form-control" id="km-legal-body" name="body" rows="10"
                                  maxlength="20000" required></textarea>
                        <div class="form-text" data-i18n="page.legal.bodyHint">
                          空行で段落が分かれます。**この形**で強調できます。
                        </div>
                      </div>
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="km-legal-public" name="is_public" value="1" />
                        <label class="form-check-label" for="km-legal-public" data-i18n="page.legal.fieldPublic">
                          公開ページにも出す
                        </label>
                      </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                      <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                      <button type="button" class="btn btn-outline-secondary d-none" id="km-legal-cancel"
                              data-i18n="common.cancel">キャンセル</button>
                      <button type="submit" name="do_delete" value="1"
                              class="btn btn-outline-danger ms-auto d-none" id="km-legal-delete"
                              formnovalidate data-i18n="common.delete">削除</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script<?= km_csp_nonce_attr() ?>>
          // 追加と編集で同じフォームを使い回す(admin/faq.php と同じ作り)。
          (() => {
            const form = document.getElementById('km-legal-form');
            if (!form) return;
            const t = (key, fallback) => (window.KmI18n ? window.KmI18n.t(key) : fallback);
            const title = document.getElementById('km-legal-form-title');
            const cancel = document.getElementById('km-legal-cancel');
            const del = document.getElementById('km-legal-delete');
            const documentSelect = document.getElementById('km-legal-document');

            const toCreate = () => {
              document.getElementById('km-legal-action').value = 'create';
              document.getElementById('km-legal-id').value = '';
              document.getElementById('km-legal-heading').value = '';
              document.getElementById('km-legal-body').value = '';
              document.getElementById('km-legal-public').checked = false;
              documentSelect.disabled = false;
              title.dataset.i18n = 'page.legal.addTitle';
              title.textContent = t('page.legal.addTitle', '章を足す');
              cancel.classList.add('d-none');
              del.classList.add('d-none');
            };

            document.querySelectorAll('.km-legal-edit').forEach((button) => {
              button.addEventListener('click', () => {
                document.getElementById('km-legal-action').value = 'update';
                document.getElementById('km-legal-id').value = button.dataset.id;
                document.getElementById('km-legal-heading').value = button.dataset.heading;
                document.getElementById('km-legal-body').value = button.dataset.body;
                document.getElementById('km-legal-public').checked = button.dataset.public === '1';
                documentSelect.value = button.dataset.document;
                // 文書の付け替えはさせない(サーバー側でも受け付けない)
                documentSelect.disabled = true;
                title.dataset.i18n = 'page.legal.editTitle';
                title.textContent = t('page.legal.editTitle', '章を編集');
                cancel.classList.remove('d-none');
                del.classList.remove('d-none');
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
              });
            });

            cancel.addEventListener('click', toCreate);
            del.addEventListener('click', (event) => {
              if (!window.confirm(t('page.legal.confirmDelete', 'この章を削除しますか?'))) {
                event.preventDefault();
              }
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
