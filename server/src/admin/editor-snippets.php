<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/editor-snippets.php';

/**
 * エディタの雛形の取り込み(段 14-4。2026-11-13。中身は lib/editor-snippets.php)。
 *
 * 利用者「予測変換は JSON で管理できませんか」「管理画面の取り込みだけ作る」。
 * - Web Code Editor(/editor/ と /code/)で `<a` と打つと出る「a — リンク」や、JS の for・fetch などの雛形を、JSON のファイルで差し替える
 * - **ここでは編集しない**(取り込むだけ)。同梱の雛形か、いまの雛形を書き出して、手元で直してから取り込む
 * - 取り込む前に形を確かめる(形が違えば何も変えない)。取り込みと「同梱に戻す」は記録に残す
 * - 書き出しは GET(?download=default / current)。何も書き込まない
 */

$KM_PAGE = [
    'nav' => 'editorSnippets',
    'titleKey' => 'page.editorSnippets.title',
    'title' => 'エディタの雛形 | KosenMap 管理',
    'h1Key' => 'page.editorSnippets.h1',
    'h1' => 'エディタの雛形',
    'crumbs' => [
        ['key' => 'side.groupPublic', 'text' => '公開ページ'],
        ['key' => 'page.editorSnippets.h1', 'text' => 'エディタの雛形'],
    ],
];

$errors = [];
$dbError = null;
$notice = ['imported' => '取り込みました。エディタを開き直すと、新しい雛形が出ます(1 分ほど前のものが残ることがあります)。', 'reset' => '同梱の雛形に戻しました。'][(string) ($_GET['saved'] ?? '')] ?? null;
$current = ['custom' => false, 'data' => null, 'updatedAt' => null, 'updatedBy' => '', 'counts' => ['htmlJa' => 0, 'htmlEn' => 0, 'css' => 0, 'js' => 0]];
// 取り込んだときの知らせ(先頭の < を外した・知らない項目を捨てた。段 14-5)。1 回出したら消す
$importNotes = is_array($_SESSION['km_editor_snippets_notes'] ?? null) ? array_values(array_filter($_SESSION['km_editor_snippets_notes'], 'is_string')) : [];
unset($_SESSION['km_editor_snippets_notes']);
$defaultJson = km_editor_snippets_default_json();
$defaultData = $defaultJson === null ? null : json_decode($defaultJson, true);

try {
    $pdo = km_db();
    $current = km_editor_snippets_current($pdo);

    // ---- 書き出し(読むだけ) ----
    $download = (string) ($_GET['download'] ?? '');
    if ($download === 'default' || $download === 'current') {
        $text = $download === 'current' && $current['custom']
            ? json_encode($current['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            : $defaultJson;
        if (!is_string($text)) {
            http_response_code(404);
            exit('not found');
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="editor-snippets' . ($download === 'current' && $current['custom'] ? '' : '-default') . '.json"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $text;
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        // 副作用を起こす前に弾く
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $by = (string) ($KM_USER['name'] ?? '');
        if ($formAction === 'import') {
            $file = $_FILES['json'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                $errors[] = 'ファイルを受け取れませんでした(' . intdiv(KM_EDITOR_SNIPPET_MAX_BYTES, 1024) . 'KB までの .json を選んでください)。';
            } elseif ((int) $file['size'] > KM_EDITOR_SNIPPET_MAX_BYTES) {
                $errors[] = 'ファイルが大きすぎます(' . intdiv(KM_EDITOR_SNIPPET_MAX_BYTES, 1024) . 'KB まで)。';
            } else {
                $parsed = km_editor_snippets_parse((string) file_get_contents((string) $file['tmp_name']));
                if (!$parsed['ok']) {
                    $errors[] = '取り込めませんでした(何も変えていません): ' . $parsed['error'];
                } else {
                    km_editor_snippets_store($pdo, $parsed['value'], $by);
                    km_admin_log_record('content', 'editor-snippets.import', sprintf(
                        'HTML 日本語 %d・英語 %d・CSS %d・JS %d',
                        count($parsed['value']['html']['ja']),
                        count($parsed['value']['html']['en']),
                        count($parsed['value']['css']),
                        count($parsed['value']['js'])
                    ));
                    $_SESSION['km_editor_snippets_notes'] = $parsed['notes'];
                    header('Location: ./editor-snippets.php?saved=imported', true, 303);
                    exit;
                }
            }
        } elseif ($formAction === 'reset') {
            if ($current['custom']) {
                km_editor_snippets_store($pdo, null, $by);
                km_admin_log_record('content', 'editor-snippets.reset');
            }
            header('Location: ./editor-snippets.php?saved=reset', true, 303);
            exit;
        }
    }
} catch (Throwable $exception) {
    error_log('admin/editor-snippets.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$defaultCounts = is_array($defaultData)
    ? ['htmlJa' => count($defaultData['html']['ja'] ?? []), 'htmlEn' => count($defaultData['html']['en'] ?? []), 'css' => count($defaultData['css'] ?? []), 'js' => count($defaultData['js'] ?? [])]
    : null;
$shown = $current['custom'] ? $current['counts'] : $defaultCounts;

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
            <?php foreach ($importNotes as $message): ?>
              <div class="alert alert-warning" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-braces me-2 mt-1" aria-hidden="true"></i>
              <div>
                Web Code Editor(<a href="/editor/" target="_blank" rel="noopener noreferrer">/editor/</a> と <a href="/code/" target="_blank" rel="noopener noreferrer">/code/</a>)で
                <code>&lt;a</code> と打つと出る「a — リンク」や、CSS の <code>display flex</code>・<code>@media</code>、JavaScript の <code>for</code>・<code>fetch</code> などの<strong>雛形</strong>を、JSON のファイルで差し替えます。<br>
                ここでは編集しません。<strong>書き出す → 手元で直す → 取り込む</strong>の順です。形が違うファイルは取り込まず、何も変えません。<br>
                HTML の <code>body</code> は先頭の <code>&lt;</code> を書かなくてよい(書いてあれば外して取り込みます)。CSS の雛形は <code>"css": [ … ]</code>(書き方は JavaScript と同じ <code>label</code>・<code>body</code>・<code>ja</code>・<code>en</code>)。<code>${1:文字}</code> は Tab で順に回る入力欄、<code>$0</code> は最後のカーソルの位置です。
              </div>
            </div>

            <div class="card mb-4">
              <div class="card-header"><h3 class="card-title mb-0">いまの雛形</h3></div>
              <div class="card-body">
                <?php if ($current['custom']): ?>
                  <p class="mb-2"><span class="badge text-bg-primary">取り込んだもの</span>
                    <?= km_e((string) $current['updatedAt']) ?><?= $current['updatedBy'] !== '' ? '・' . km_e($current['updatedBy']) : '' ?></p>
                <?php else: ?>
                  <p class="mb-2"><span class="badge text-bg-secondary">同梱のもの</span> 取り込んだ雛形はありません。</p>
                <?php endif; ?>
                <?php if ($shown !== null): ?>
                  <p class="mb-3">HTML(日本語)<?= (int) $shown['htmlJa'] ?> 個・HTML(英語)<?= (int) $shown['htmlEn'] ?> 個・CSS <?= (int) ($shown['css'] ?? 0) ?> 個・JavaScript <?= (int) $shown['js'] ?> 個</p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                  <?php if ($current['custom']): ?>
                    <a class="btn btn-outline-primary" href="./editor-snippets.php?download=current"><i class="bi bi-download" aria-hidden="true"></i> いまの雛形を書き出す</a>
                  <?php endif; ?>
                  <?php if ($defaultJson !== null): ?>
                    <a class="btn btn-outline-secondary" href="./editor-snippets.php?download=default"><i class="bi bi-download" aria-hidden="true"></i> 同梱の雛形を書き出す</a>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="card mb-4">
              <div class="card-header"><h3 class="card-title mb-0">取り込む</h3></div>
              <div class="card-body">
                <form method="post" action="./editor-snippets.php" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center gap-2 m-0">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="form_action" value="import">
                  <input class="form-control w-auto" type="file" name="json" accept=".json,application/json" required aria-label="取り込む JSON">
                  <button type="submit" class="btn btn-primary"<?= $KM_ADMIN_CAN_WRITE ? '' : ' disabled' ?>><i class="bi bi-upload" aria-hidden="true"></i> 取り込む</button>
                </form>
                <p class="text-secondary small mt-2 mb-0">
                  <?= intdiv(KM_EDITOR_SNIPPET_MAX_BYTES, 1024) ?>KB まで・HTML と JavaScript はそれぞれ <?= KM_EDITOR_SNIPPET_MAX_ITEMS ?> 個・CSS は <?= KM_EDITOR_SNIPPET_MAX_CSS_ITEMS ?> 個まで。取り込むと、いまの雛形と入れ替わります(前のものは記録として残ります)。
                </p>
              </div>
            </div>

            <?php if ($current['custom']): ?>
              <div class="card mb-4">
                <div class="card-header"><h3 class="card-title mb-0">同梱の雛形に戻す</h3></div>
                <div class="card-body">
                  <form method="post" action="./editor-snippets.php" class="m-0">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="reset">
                    <button type="submit" class="btn btn-outline-danger"<?= $KM_ADMIN_CAN_WRITE ? '' : ' disabled' ?>>同梱の雛形に戻す</button>
                    <span class="text-secondary small ms-2">取り込んだものを使うのをやめます。戻す前に「いまの雛形を書き出す」で手元に残せます。</span>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
