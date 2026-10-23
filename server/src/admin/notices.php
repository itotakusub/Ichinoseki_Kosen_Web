<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/notices.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * お知らせの編集(2026-10-24、利用者の指示「お知らせは今の管理画面に編集画面(詳細ページも)を足す。プレビュー機能があると尚良し」)。
 * 中身は lib/notices.php。
 *
 * - 「公開する」を付けたものだけが公開ページ(/notice.php)に出る。付けていなければ下書き
 * - 「最初の画面に出す」を付けたものは、公開の地図の「はじめに」の先頭に短い文を出す(出す最後の日まで)。
 *   読んだ人には出し直さない(閲覧者の手元に覚える。Main/welcome.js)
 * - 「プレビュー」は保存せずに、最初の画面のお知らせと、くわしいページを**公開ページと同じ組み立て**(km_notice_detail_html)で下に出す
 */

$KM_PAGE = [
    'nav' => 'notices',
    'titleKey' => 'page.notices.title',
    'title' => 'お知らせ | KosenMap 管理',
    'h1Key' => 'page.notices.h1',
    'h1' => 'お知らせ',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.notices.h1', 'text' => 'お知らせ'],
    ],
];

$errors = [];
$dbError = null;
$rows = [];
$editing = null;
$form = null;
$preview = null;
$editId = isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int) $_GET['edit'] : null;
$today = km_notice_today();

try {
    $pdo = km_db();
    km_notices_ensure_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        // 副作用を起こす前に弾く
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $targetId = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        if ($formAction === 'delete' && $targetId !== null) {
            $title = km_notice_delete($pdo, $targetId);
            if ($title !== null) {
                km_admin_log_record('content', 'notice.delete', $title);
            }
            header('Location: ./notices.php?saved=deleted', true, 303);
            exit;
        }
        if ($formAction === 'save' || $formAction === 'preview') {
            $form = [
                'title' => (string) ($_POST['title'] ?? ''),
                'summary' => (string) ($_POST['summary'] ?? ''),
                'body' => (string) ($_POST['body'] ?? ''),
                'publishedOn' => (string) ($_POST['published_on'] ?? ''),
                'showOnWelcome' => isset($_POST['show_on_welcome']),
                'showUntil' => (string) ($_POST['show_until'] ?? ''),
                'isPublished' => isset($_POST['is_published']),
            ];
            $editId = $targetId;
            try {
                $fields = km_notice_validate($form);
                if ($formAction === 'preview') {
                    // 保存しない。公開ページと同じ形に組み立てて、下に出す
                    $preview = [
                        'id' => $targetId ?? 0,
                        'title' => $fields['title'],
                        'summary' => $fields['summary'],
                        'sections' => km_notice_parse_body($fields['body']),
                        'date' => $fields['publishedOn'],
                        'showOnWelcome' => $fields['showOnWelcome'],
                        'until' => $fields['showUntil'],
                        'isPublished' => $fields['isPublished'],
                    ];
                } else {
                    $savedId = km_notice_save($pdo, $targetId, $fields);
                    km_admin_log_record('content', $targetId === null ? 'notice.create' : 'notice.update', $fields['title'] . ($fields['isPublished'] ? '(公開)' : '(下書き)'));
                    header('Location: ./notices.php?saved=' . ($targetId === null ? 'created' : 'updated') . '&edit=' . $savedId . '#notice-form', true, 303);
                    exit;
                }
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }

    $rows = km_notices_all($pdo);
    if ($editId !== null) {
        $editing = km_notice_find($pdo, $editId);
    }
} catch (Throwable $exception) {
    error_log('admin/notices.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$notice = [
    'created' => 'お知らせを足しました。',
    'updated' => 'お知らせを保存しました。',
    'deleted' => 'お知らせを消しました。',
][(string) ($_GET['saved'] ?? '')] ?? null;

// フォームの中身: 打ち直し・プレビュー > 編集中の行 > 新しく書く(日付は今日・最初の画面に出す)
$value = static function (string $key) use ($form, $editing, $today) {
    if ($form !== null) {
        return $form[$key];
    }
    if ($editing !== null) {
        return match ($key) {
            'publishedOn' => $editing['date'],
            'showUntil' => $editing['until'] ?? '',
            default => $editing[$key],
        };
    }
    return match ($key) {
        'publishedOn' => $today,
        'showOnWelcome' => true,
        'isPublished' => false,
        default => '',
    };
};

/** 一覧の状態の札 */
$statusOf = static function (array $row) use ($today): array {
    if (!$row['isPublished']) {
        return ['text-bg-secondary', '下書き'];
    }
    if ($row['date'] > $today) {
        return ['text-bg-info', '公開待ち(' . km_notice_date_label($row['date']) . 'から)'];
    }
    if ($row['showOnWelcome'] && ($row['until'] === null || $today <= $row['until'])) {
        return ['text-bg-success', '公開中・最初の画面に出している'];
    }
    return ['text-bg-primary', '公開中'];
};

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <style<?= km_csp_nonce_attr() ?>>
          /* プレビュー: 公開ページと同じ見た目(lib/notices.php の KM_NOTICE_DETAIL_CSS)と、最初の画面のお知らせ(Main/styles.css の .km-welcome-notice の写し) */
          .km-notice-preview { background: #f8fafc; border-radius: 12px; padding: 1.25rem; color: #0f172a; }
          .km-notice-preview h1 { font-size: 1.4rem; font-weight: 800; margin: 0 0 0.5rem; }
          <?= KM_NOTICE_DETAIL_CSS ?>
          .km-welcome-notice { max-width: 460px; padding: 0.85rem 1rem; border-left: 4px solid #2563EB; border-radius: 12px; background: rgba(37, 99, 235, 0.08); color: #0f172a; }
          .km-welcome-notice-head { margin: 0 0 0.25rem; font-size: 0.8rem; font-weight: 700; color: #1D4ED8; }
          .km-welcome-notice-title { display: block; margin-bottom: 0.35rem; font-size: 1rem; }
          .km-welcome-notice-text { margin: 0 0 0.5rem; font-size: 0.9rem; line-height: 1.7; white-space: pre-wrap; }
          .km-welcome-notice-link { font-size: 0.9rem; font-weight: 700; }
        </style>
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
              <i class="bi bi-megaphone me-2 mt-1" aria-hidden="true"></i>
              <div>
                「公開する」を付けたお知らせだけが、公開ページ(お知らせの一覧とくわしいページ)に出ます。
                「最初の画面に出す」も付けると、地図を開いたときの「はじめに」の先頭に短い文を出します(読んだ人には出し直しません)。
                保存の前に「プレビュー」で見た目を確かめられます。
                <a href="/notice.php" target="_blank" rel="noopener noreferrer" class="ms-1">公開ページを開く <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
              </div>
            </div>

            <div class="row">
              <div class="col-12 col-xl-5">
                <div class="card mb-4">
                  <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0">お知らせの一覧</h3>
                    <a class="btn btn-sm btn-primary ms-auto" href="./notices.php#notice-form"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>新しく書く</a>
                  </div>
                  <div class="card-body p-0">
                    <?php if ($rows === []): ?>
                      <p class="text-body-secondary text-center my-4">まだお知らせがありません。</p>
                    <?php else: ?>
                      <div class="list-group list-group-flush">
                        <?php foreach ($rows as $row): ?>
                          <?php [$badgeClass, $badgeText] = $statusOf($row); ?>
                          <div class="list-group-item<?= $editing !== null && $editing['id'] === $row['id'] ? ' bg-body-tertiary' : '' ?>">
                            <div class="d-flex align-items-start gap-2">
                              <div class="flex-grow-1">
                                <span class="badge <?= km_e($badgeClass) ?>"><?= km_e($badgeText) ?></span>
                                <?php // 題は利用者が入れた自由文なので data-i18n は付けない ?>
                                <div class="fw-semibold mt-1"><?= km_e($row['title']) ?></div>
                                <div class="fs-7 text-body-secondary"><?= km_e(km_notice_date_label($row['date'])) ?><?= $row['showOnWelcome'] ? '・最初の画面' . ($row['until'] !== null ? 'は ' . km_e(km_notice_date_label($row['until'])) . ' まで' : '(終わりなし)') : '' ?></div>
                              </div>
                              <div class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="./notices.php?edit=<?= (int) $row['id'] ?>#notice-form">編集</a>
                                <?php if ($row['isPublished']): ?>
                                  <a class="btn btn-sm btn-outline-secondary" href="/notice.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener noreferrer" title="公開ページで見る"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="col-12 col-xl-7">
                <div class="card mb-4" id="notice-form">
                  <div class="card-header">
                    <h3 class="card-title"><?= $editId !== null && ($editing !== null || $form !== null) ? 'お知らせを編集' : 'お知らせを書く' ?></h3>
                  </div>
                  <?php // プレビューのあとは、下のプレビューの所まで送る(保存は別の画面へ移るので関係しない) ?>
                  <form method="post" action="./notices.php<?= $editId !== null ? '?edit=' . (int) $editId : '' ?>#notice-preview">
                    <div class="card-body">
                      <?= km_csrf_field() ?>
                      <?php if ($editId !== null): ?>
                        <input type="hidden" name="id" value="<?= (int) $editId ?>">
                      <?php endif; ?>
                      <div class="mb-3">
                        <label class="form-label" for="km-notice-title">題</label>
                        <input type="text" class="form-control" id="km-notice-title" name="title" maxlength="<?= KM_NOTICE_TITLE_MAX ?>" required value="<?= km_e((string) $value('title')) ?>">
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-notice-summary">短い文(最初の画面とくわしいページの先頭に出す)</label>
                        <textarea class="form-control" id="km-notice-summary" name="summary" rows="4" maxlength="<?= KM_NOTICE_SUMMARY_MAX ?>" required><?= km_e((string) $value('summary')) ?></textarea>
                        <div class="form-text"><?= KM_NOTICE_SUMMARY_MAX ?> 字まで。地図を開いたときに読まれるので、何があったか・どうしてほしいかを短く。</div>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-notice-body">本文(くわしいページ)</label>
                        <textarea class="form-control font-monospace" id="km-notice-body" name="body" rows="14" maxlength="<?= KM_NOTICE_BODY_MAX ?>"><?= km_e((string) $value('body')) ?></textarea>
                        <div class="form-text">
                          書き方: <code>## 見出し</code> の行が見出しになります。空の行で段落を分けます。HTML やリンクは書けません(文字のまま出ます)。空でもかまいません。
                        </div>
                      </div>
                      <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                          <label class="form-label" for="km-notice-date">日付</label>
                          <input type="date" class="form-control" id="km-notice-date" name="published_on" required value="<?= km_e((string) $value('publishedOn')) ?>">
                          <div class="form-text">この日から公開ページに出ます(公開するを付けたとき)。</div>
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label" for="km-notice-until">最初の画面に出す最後の日(任意)</label>
                          <input type="date" class="form-control" id="km-notice-until" name="show_until" value="<?= km_e((string) $value('showUntil')) ?>">
                          <div class="form-text">空なら終わりを決めません。過ぎてもくわしいページは残ります。</div>
                        </div>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="km-notice-welcome" name="show_on_welcome" value="1" <?= $value('showOnWelcome') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-notice-welcome">最初の画面(「はじめに」)に出す</label>
                      </div>
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="km-notice-published" name="is_published" value="1" <?= $value('isPublished') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-notice-published">公開する(付けなければ下書き)</label>
                      </div>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2">
                      <button type="submit" name="form_action" value="preview" class="btn btn-outline-primary" formnovalidate><i class="bi bi-eye me-1" aria-hidden="true"></i>プレビュー</button>
                      <button type="submit" name="form_action" value="save" class="btn btn-primary">保存</button>
                      <?php if ($editId !== null): ?>
                        <a class="btn btn-outline-secondary" href="./notices.php">やめる</a>
                      <?php endif; ?>
                    </div>
                  </form>
                  <?php if ($editId !== null && $editing !== null): ?>
                    <div class="card-footer border-top-0 pt-0 text-end">
                      <form method="post" class="d-inline" data-km-confirm="「<?= km_e($editing['title']) ?>」を消します。公開ページからも消え、戻せません。よろしいですか?">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="form_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">このお知らせを消す</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>

                <?php if ($preview !== null): ?>
                  <?php // プレビュー(保存していない)。公開ページと同じ組み立て ?>
                  <div class="card mb-4 border-primary" id="notice-preview">
                    <div class="card-header bg-primary-subtle">
                      <h3 class="card-title mb-0"><i class="bi bi-eye me-1" aria-hidden="true"></i>プレビュー(まだ保存していません)</h3>
                    </div>
                    <div class="card-body">
                      <?php if (!$preview['isPublished']): ?>
                        <div class="alert alert-secondary py-2">「公開する」が付いていないので、保存しても下書きのままです(公開ページにも最初の画面にも出ません)。</div>
                      <?php elseif ($preview['showOnWelcome'] && ($preview['until'] === null || $preview['until'] >= $today)): ?>
                        <h4 class="h6">地図の最初の画面(「はじめに」の先頭)</h4>
                        <section class="km-welcome-notice mb-4" aria-label="お知らせ">
                          <p class="km-welcome-notice-head">📢 お知らせ(<?= km_e(km_notice_date_label($preview['date'])) ?>)</p>
                          <b class="km-welcome-notice-title"><?= km_e($preview['title']) ?></b>
                          <p class="km-welcome-notice-text"><?= km_e($preview['summary']) ?></p>
                          <span class="km-welcome-notice-link">くわしく読む ↗</span>
                        </section>
                      <?php else: ?>
                        <div class="alert alert-secondary py-2">「最初の画面に出す」が付いていないか、出す期間が終わっているので、最初の画面には出しません。</div>
                      <?php endif; ?>
                      <h4 class="h6">くわしいページ(/notice.php)</h4>
                      <div class="km-notice-preview">
                        <h1><?= km_e($preview['title']) ?></h1>
                        <?php /* 文字は km_notice_detail_html が全部エスケープする */ ?>
                        <?= km_notice_detail_html($preview) ?>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
