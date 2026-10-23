<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/qr-links.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * QR のリンク集の編集(2026-10-24、利用者の指示「qr.php に URL を並べて表示。管理画面で設定」。中身は lib/qr-links.php)。
 *
 * - ここで足したリンクが、公開ページ /qr.php に上から順に並ぶ(先頭はいつも地図)
 * - 「出す」を外すと公開ページから消える(行は残る)
 * - 並びは上下のボタンで入れ替える(数が少ないので、FAQ と同じ作り)
 */

$KM_PAGE = [
    'nav' => 'qrLinks',
    'titleKey' => 'page.qrLinks.title',
    'title' => 'QR のリンク集 | KosenMap 管理',
    'h1Key' => 'page.qrLinks.h1',
    'h1' => 'QR のリンク集',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.qrLinks.h1', 'text' => 'QR のリンク集'],
    ],
];

$errors = [];
$dbError = null;
$rows = [];
$editing = null;
$form = null;
$editId = isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int) $_GET['edit'] : null;

try {
    $pdo = km_db();
    km_qr_links_ensure_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        // 副作用を起こす前に弾く
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $targetId = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        if ($formAction === 'delete' && $targetId !== null) {
            $title = km_qr_link_delete($pdo, $targetId);
            if ($title !== null) {
                km_admin_log_record('content', 'qr_link.delete', $title);
            }
            header('Location: ./qr-links.php?saved=deleted', true, 303);
            exit;
        }
        if ($formAction === 'move' && $targetId !== null) {
            $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
            km_qr_link_move($pdo, $targetId, $direction);
            km_admin_log_record('content', 'qr_link.move', '#' . $targetId . ' ' . $direction);
            header('Location: ./qr-links.php?saved=moved', true, 303);
            exit;
        }
        if ($formAction === 'save') {
            $form = [
                'title' => (string) ($_POST['title'] ?? ''),
                'url' => (string) ($_POST['url'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'isEnabled' => isset($_POST['is_enabled']),
            ];
            try {
                $fields = km_qr_link_validate($form);
                $savedId = km_qr_link_save($pdo, $targetId, $fields);
                km_admin_log_record('content', $targetId === null ? 'qr_link.create' : 'qr_link.update', $fields['title'] . '(' . $fields['url'] . ')');
                header('Location: ./qr-links.php?saved=' . ($targetId === null ? 'created' : 'updated') . '#qr-link-' . $savedId, true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
                $editId = $targetId;
            }
        }
    }

    $rows = km_qr_links_all($pdo);
    if ($editId !== null) {
        $editing = km_qr_link_find($pdo, $editId);
    }
} catch (Throwable $exception) {
    error_log('admin/qr-links.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$notice = [
    'created' => 'リンクを足しました。',
    'updated' => 'リンクを保存しました。',
    'deleted' => 'リンクを消しました。',
    'moved' => '並びを変えました。',
][(string) ($_GET['saved'] ?? '')] ?? null;

// フォームの中身: 打ち直し(入力の誤り)> 編集中の行 > 空
$value = static function (string $key) use ($form, $editing) {
    if ($form !== null) {
        return $form[$key];
    }
    if ($editing !== null) {
        return $editing[$key];
    }
    return $key === 'isEnabled' ? true : '';
};

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid">
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert">データベースに接続できません。<?= km_e($dbError) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-qr-code me-2 mt-1" aria-hidden="true"></i>
              <div>
                配っている QR(<code>/qr.php</code>)を読むと開くページに、ここで足したリンクを上から順に並べます。先頭はいつも「高専マップ案内」(地図)です。
                URL は <code>https://</code> で始まる形か、このサイトの中の道(<code>/posters.php</code> のように <code>/</code> で始まる形)にしてください。
                <a href="/qr.php" target="_blank" rel="noopener noreferrer" class="ms-1">公開ページを開く <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
              </div>
            </div>

            <div class="row">
              <div class="col-12 col-xl-8">
                <div class="card mb-4">
                  <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0">並べるリンク</h3>
                    <span class="ms-auto text-body-secondary fs-7">出している <strong><?= count(array_filter($rows, static fn (array $r): bool => $r['isEnabled'])) ?></strong> / <?= count($rows) ?></span>
                  </div>
                  <div class="card-body p-0">
                    <?php if ($rows === []): ?>
                      <p class="text-body-secondary text-center my-4">まだリンクがありません。右のフォームから足してください。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                          <tbody>
                            <?php foreach ($rows as $i => $row): ?>
                              <tr id="qr-link-<?= (int) $row['id'] ?>">
                                <td>
                                  <?php if ($row['isEnabled']): ?>
                                    <span class="badge text-bg-success">出す</span>
                                  <?php else: ?>
                                    <span class="badge text-bg-secondary">止めている</span>
                                  <?php endif; ?>
                                  <?php // 名前・説明は利用者が入れた自由文なので data-i18n は付けない ?>
                                  <span class="fw-semibold ms-1"><?= km_e($row['title']) ?></span>
                                  <div class="fs-7 text-truncate"><a href="<?= km_e($row['url']) ?>" target="_blank" rel="noopener noreferrer"><?= km_e($row['url']) ?></a></div>
                                  <?php if ($row['description'] !== ''): ?>
                                    <div class="fs-7 text-body-secondary km-pre-wrap"><?= km_e($row['description']) ?></div>
                                  <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                  <form method="post" class="d-inline">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="move">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm" aria-label="上へ" <?= $i === 0 ? 'disabled' : '' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                                  </form>
                                  <form method="post" class="d-inline">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="move">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm" aria-label="下へ" <?= $i === count($rows) - 1 ? 'disabled' : '' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
                                  </form>
                                  <a class="btn btn-sm btn-outline-primary" href="./qr-links.php?edit=<?= (int) $row['id'] ?>#qr-link-form">編集</a>
                                  <form method="post" class="d-inline" data-km-confirm="「<?= km_e($row['title']) ?>」を消します。よろしいですか?">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">消す</button>
                                  </form>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="col-12 col-xl-4">
                <div class="card" id="qr-link-form">
                  <div class="card-header">
                    <h3 class="card-title"><?= $editing !== null ? 'リンクを編集' : 'リンクを足す' ?></h3>
                  </div>
                  <form method="post">
                    <div class="card-body">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="save">
                      <?php if ($editing !== null): ?>
                        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                      <?php endif; ?>
                      <div class="mb-3">
                        <label class="form-label" for="km-qr-title">名前</label>
                        <input type="text" class="form-control" id="km-qr-title" name="title" maxlength="<?= KM_QR_LINK_TITLE_MAX ?>" required value="<?= km_e((string) $value('title')) ?>">
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-qr-url">URL</label>
                        <input type="text" class="form-control" id="km-qr-url" name="url" maxlength="<?= KM_QR_LINK_URL_MAX ?>" required placeholder="https://… または /posters.php" value="<?= km_e((string) $value('url')) ?>">
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-qr-description">説明(任意)</label>
                        <textarea class="form-control" id="km-qr-description" name="description" rows="3" maxlength="<?= KM_QR_LINK_DESCRIPTION_MAX ?>"><?= km_e((string) $value('description')) ?></textarea>
                      </div>
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="km-qr-enabled" name="is_enabled" value="1" <?= $value('isEnabled') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-qr-enabled">公開ページに出す</label>
                      </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                      <button type="submit" class="btn btn-primary">保存</button>
                      <?php if ($editing !== null): ?>
                        <a class="btn btn-outline-secondary" href="./qr-links.php">やめる</a>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
