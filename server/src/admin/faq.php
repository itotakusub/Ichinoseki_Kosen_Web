<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/faq.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/*
 * よくある質問の編集画面。
 *
 * 移行前はここに5件のQ&Aが直接書いてあり、i18n 辞書に ja/en があった。DB へ移したので
 * 文言は日本語の自由文1本になり、辞書のキー(page.faq.q1..a5)は不要になった
 * — フェーズ10で projects.php のハードコード14行を km_tasks へ移したときと同じ手順。
 *
 * 「公開」を付けた質問だけが公開ページ(/faq.php)に出る。管理画面の使い方の質問を
 * 一般の利用者に見せないための区別。
 */

$KM_PAGE = [
    'nav' => 'faq',
    'titleKey' => 'page.faq.title',
    'title' => 'FAQ | KosenMap 管理',
    'h1Key' => 'page.faq.h1',
    'h1' => 'FAQ',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.faq.h1', 'text' => 'FAQ'],
    ],
];

$errors = [];
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 編集フォームの「削除」は同じフォームから送られてくるので専用キーで意図を表す
    // (action を二重に送って最後勝ちに頼らない。カレンダーと同じ作り)
    $action = isset($_POST['do_delete']) ? 'delete' : (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $question = (string) ($_POST['question'] ?? '');
    $answer = (string) ($_POST['answer'] ?? '');
    $isPublic = isset($_POST['is_public']);

    try {
        $pdo = km_db();

        if ($action === 'create') {
            km_faq_create($pdo, $question, $answer, $isPublic);
            km_admin_log_record('content', 'faq.create', $question);
            header('Location: ./faq.php?created=1', true, 302);
            exit;
        }
        if ($action === 'update') {
            km_faq_update($pdo, $id, $question, $answer, $isPublic);
            km_admin_log_record('content', 'faq.update', $question);
            header('Location: ./faq.php?updated=1', true, 302);
            exit;
        }
        if ($action === 'delete') {
            km_faq_delete($pdo, $id);
            km_admin_log_record('content', 'faq.delete', "#{$id}");
            header('Location: ./faq.php?deleted=1', true, 302);
            exit;
        }
        if ($action === 'move') {
            $direction = (string) ($_POST['direction'] ?? '');
            km_faq_move($pdo, $id, $direction === 'up' ? 'up' : 'down');
            km_admin_log_record('content', 'faq.move', "#{$id} {$direction}");
            header('Location: ./faq.php?moved=1', true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('faq.php ' . $action . ' failed: ' . $exception->getMessage());
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

$rows = [];
$dbError = null;
try {
    $pdo = km_db();
    km_faq_seed_once($pdo);
    $rows = km_faq_all($pdo);
} catch (Throwable $exception) {
    error_log('faq.php failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

$publicCount = count(array_filter($rows, static fn (array $r): bool => (int) $r['isPublic'] === 1));

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
              <div class="alert alert-warning" role="alert" data-i18n="page.faq.dbError">
                データベースに接続できないため、よくある質問を読み込めません。
              </div>
            <?php endif; ?>

            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.faq.<?= km_e($notice) ?>">保存しました。</div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                <span data-i18n="page.faq.notice">
                  「公開」を付けた質問だけが、誰でも見られる公開ページに出ます。付けていないものは
                  この画面の中だけで見えます。
                </span>
                <a href="/faq.php" target="_blank" rel="noopener noreferrer" class="ms-1">
                  <span data-i18n="page.faq.openPublic">公開ページを開く</span>
                  <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                </a>
              </div>
            </div>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-8">
                <!--begin::List-->
                <div class="card mb-4">
                  <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0" data-i18n="page.faq.listTitle">登録済みの質問</h3>
                    <span class="ms-auto text-body-secondary fs-7">
                      <span data-i18n="page.faq.publicCount">公開中</span>
                      <strong><?= (int) $publicCount ?></strong> / <?= count($rows) ?>
                    </span>
                  </div>
                  <div class="card-body p-0">
                    <?php if ($rows === []): ?>
                      <p class="text-body-secondary text-center my-4" data-i18n="page.faq.empty">
                        まだ質問が登録されていません。
                      </p>
                    <?php else: ?>
                      <div class="accordion accordion-flush" id="km-faq">
                        <?php foreach ($rows as $i => $row): ?>
                          <div class="accordion-item">
                            <h2 class="accordion-header d-flex align-items-center">
                              <button
                                class="accordion-button collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#km-faq-<?= (int) $row['id'] ?>"
                              >
                                <?php if ((int) $row['isPublic'] === 1): ?>
                                  <span class="badge text-bg-success me-2" data-i18n="page.faq.public">公開</span>
                                <?php else: ?>
                                  <span class="badge text-bg-secondary me-2" data-i18n="page.faq.private">非公開</span>
                                <?php endif; ?>
                                <?php // 質問文は利用者が入れた自由文なので data-i18n は付けない ?>
                                <?= km_e((string) $row['question']) ?>
                              </button>
                            </h2>
                            <div id="km-faq-<?= (int) $row['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#km-faq">
                              <div class="accordion-body">
                                <p class="mb-3 km-pre-wrap"><?= km_e((string) $row['answer']) ?></p>
                                <div class="d-flex gap-2">
                                  <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm km-faq-edit"
                                    data-id="<?= (int) $row['id'] ?>"
                                    data-question="<?= km_e((string) $row['question']) ?>"
                                    data-answer="<?= km_e((string) $row['answer']) ?>"
                                    data-public="<?= (int) $row['isPublic'] ?>"
                                  >
                                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                                    <span data-i18n="common.edit">編集</span>
                                  </button>
                                  <?php // 並べ替えは上下ボタンで足りる(件数が少なく、D&D を持つ理由が無い) ?>
                                  <form method="post" class="d-inline">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="action" value="move" />
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>" />
                                    <input type="hidden" name="direction" value="up" />
                                    <button
                                      type="submit"
                                      class="btn btn-outline-secondary btn-sm"
                                      aria-label="上へ移動"
                                      data-i18n-attr="aria-label:page.faq.moveUp"
                                      <?= $i === 0 ? 'disabled' : '' ?>
                                    ><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                                  </form>
                                  <form method="post" class="d-inline">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="action" value="move" />
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>" />
                                    <input type="hidden" name="direction" value="down" />
                                    <button
                                      type="submit"
                                      class="btn btn-outline-secondary btn-sm"
                                      aria-label="下へ移動"
                                      data-i18n-attr="aria-label:page.faq.moveDown"
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
                <!--end::List-->
              </div>

              <div class="col-12 col-xl-4">
                <!--begin::Form-->
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title" id="km-faq-form-title" data-i18n="page.faq.addTitle">質問を追加</h3>
                  </div>
                  <form method="post" id="km-faq-form">
                    <div class="card-body">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="action" id="km-faq-action" value="create" />
                      <input type="hidden" name="id" id="km-faq-id" value="" />

                      <div class="mb-3">
                        <label class="form-label" for="km-faq-question" data-i18n="page.faq.fieldQuestion">質問</label>
                        <input type="text" class="form-control" id="km-faq-question" name="question" maxlength="255" required />
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-faq-answer" data-i18n="page.faq.fieldAnswer">回答</label>
                        <textarea class="form-control" id="km-faq-answer" name="answer" rows="6" maxlength="5000" required></textarea>
                      </div>
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="km-faq-public" name="is_public" value="1" />
                        <label class="form-check-label" for="km-faq-public" data-i18n="page.faq.fieldPublic">
                          公開ページにも出す
                        </label>
                      </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                      <button type="submit" class="btn btn-primary" data-i18n="common.save">保存</button>
                      <button type="button" class="btn btn-outline-secondary d-none" id="km-faq-cancel" data-i18n="common.cancel">
                        キャンセル
                      </button>
                      <button
                        type="submit"
                        name="do_delete"
                        value="1"
                        class="btn btn-outline-danger ms-auto d-none"
                        id="km-faq-delete"
                        formnovalidate
                        data-i18n="common.delete"
                      >削除</button>
                    </div>
                  </form>
                </div>
                <!--end::Form-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script<?= km_csp_nonce_attr() ?>>
          // 追加と編集で同じフォームを使い回す(calendar.php と同じ作り)。
          // 削除は formnovalidate 付き — 必須項目の HTML5 検証に引っかかって削除できなくなるため。
          (() => {
            const form = document.getElementById('km-faq-form');
            if (!form) return;
            const t = (key, fallback) => (window.KmI18n ? window.KmI18n.t(key) : fallback);
            const title = document.getElementById('km-faq-form-title');
            const cancel = document.getElementById('km-faq-cancel');
            const del = document.getElementById('km-faq-delete');

            const toCreate = () => {
              document.getElementById('km-faq-action').value = 'create';
              document.getElementById('km-faq-id').value = '';
              document.getElementById('km-faq-question').value = '';
              document.getElementById('km-faq-answer').value = '';
              document.getElementById('km-faq-public').checked = false;
              title.dataset.i18n = 'page.faq.addTitle';
              title.textContent = t('page.faq.addTitle', '質問を追加');
              cancel.classList.add('d-none');
              del.classList.add('d-none');
            };

            document.querySelectorAll('.km-faq-edit').forEach((button) => {
              button.addEventListener('click', () => {
                document.getElementById('km-faq-action').value = 'update';
                document.getElementById('km-faq-id').value = button.dataset.id;
                document.getElementById('km-faq-question').value = button.dataset.question;
                document.getElementById('km-faq-answer').value = button.dataset.answer;
                document.getElementById('km-faq-public').checked = button.dataset.public === '1';
                title.dataset.i18n = 'page.faq.editTitle';
                title.textContent = t('page.faq.editTitle', '質問を編集');
                cancel.classList.remove('d-none');
                del.classList.remove('d-none');
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
              });
            });

            cancel.addEventListener('click', toCreate);
            del.addEventListener('click', (event) => {
              if (!window.confirm(t('page.faq.confirmDelete', 'この質問を削除しますか?'))) {
                event.preventDefault();
              }
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
