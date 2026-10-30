<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/stalls.php';
require_once dirname(__DIR__) . '/lib/posters.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/site.php';

/**
 * 屋台(2026-10-30、高専祭の振り返りのフェーズ 5 の 2。中身は lib/stalls.php)。
 *
 * - 屋台を足すと鍵(kms_…)を出す。**鍵はこの 1 度だけ見せる**(表には SHA-256 だけ)。なくしたら「鍵を出し直す」
 * - 屋台は api/stall.php へメニューと「◯人待ち」を送る。端末の無い屋台は、この画面から係が入れてもよい
 * - 場所は企画の番号(一時地点を取り込み直しても外れない)か地点
 * - 「止める」と鍵が効かなくなり、地図にも出さない。鍵を出し直すと戻る
 */

const KM_STALL_FLASH = 'km_stall_flash_key';

$errors = [];
$dbError = null;
$rows = [];
$nodeNames = [];
$editing = null;
$form = null;
$newKey = null;

$editId = isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int) $_GET['edit'] : null;

// 出したばかりの鍵(1 度だけ。URL には載せない)
if (is_array($_SESSION[KM_STALL_FLASH] ?? null)) {
    $newKey = $_SESSION[KM_STALL_FLASH];
    unset($_SESSION[KM_STALL_FLASH]);
}

try {
    $pdo = km_db();
    km_stalls_ensure_table($pdo);
    foreach (km_poster_node_options($pdo) as $option) {
        $nodeNames[$option['id']] = $option['name'] . '(' . km_poster_floor_label($option['floor']) . ')';
    }
    $findStall = static function (int $id) use ($pdo): ?array {
        foreach (km_stalls_all($pdo) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $targetId = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $target = $targetId !== null ? $findStall($targetId) : null;
        if ($targetId !== null && $target === null) {
            $errors[] = 'その屋台は見つかりません(消されたかもしれません)。';
        } elseif ($formAction === 'save') {
            $form = [
                'name' => (string) ($_POST['name'] ?? ''),
                'projectNo' => (string) ($_POST['project_no'] ?? ''),
                'nodeId' => (string) ($_POST['node_id'] ?? ''),
            ];
            try {
                if ($form['nodeId'] !== '' && !isset($nodeNames[$form['nodeId']])) {
                    throw new InvalidArgumentException('地点が地図にありません。選び直してください。');
                }
                $fields = km_stall_validate($form);
                if ($target === null) {
                    [$newId, $key] = km_stall_create($pdo, $fields);
                    $_SESSION[KM_STALL_FLASH] = ['id' => $newId, 'name' => $fields['name'], 'key' => $key];
                    km_admin_log_record('content', 'stall.create', $fields['name']);
                } else {
                    km_stall_update($pdo, $target['id'], $fields);
                    km_admin_log_record('content', 'stall.update', $fields['name']);
                }
                header('Location: ./stalls.php?saved=' . ($target === null ? 'created' : 'updated') . '#stalls-list', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
                $editId = $targetId;
            }
        } elseif ($target !== null && $formAction === 'rekey') {
            $_SESSION[KM_STALL_FLASH] = ['id' => $target['id'], 'name' => $target['name'], 'key' => km_stall_rekey($pdo, $target['id'])];
            km_admin_log_record('content', 'stall.rekey', $target['name']);
            header('Location: ./stalls.php?saved=rekeyed#stalls-list', true, 303);
            exit;
        } elseif ($target !== null && $formAction === 'revoke') {
            km_stall_revoke($pdo, $target['id']);
            km_admin_log_record('content', 'stall.revoke', $target['name']);
            header('Location: ./stalls.php?saved=revoked#stalls-list', true, 303);
            exit;
        } elseif ($target !== null && $formAction === 'delete') {
            km_stall_delete($pdo, $target['id']);
            km_admin_log_record('content', 'stall.delete', $target['name']);
            header('Location: ./stalls.php?saved=deleted#stalls-list', true, 303);
            exit;
        } elseif ($target !== null && $formAction === 'status') {
            // 端末の無い屋台の代わりに係が入れる(屋台が送るのと同じ決まり)
            try {
                $waitingText = trim(mb_convert_kana((string) ($_POST['waiting'] ?? ''), 'n'));
                if ($waitingText !== '' && !ctype_digit($waitingText)) {
                    throw new InvalidArgumentException('待ちの人数は数字で入れてください(消すときは空に)。');
                }
                $post = km_stall_validate_post([
                    'menu' => km_stall_menu_from_text((string) ($_POST['menu'] ?? '')),
                    'waiting' => $waitingText === '' ? '' : (int) $waitingText,
                    'note' => (string) ($_POST['note'] ?? ''),
                ]);
                if (km_stall_post($pdo, $target['id'], $post) === 'limited') {
                    throw new InvalidArgumentException('続けて送るのが早すぎます。' . KM_STALL_POST_INTERVAL . ' 秒あけてください。');
                }
                km_admin_log_record('content', 'stall.status', $target['name']);
                header('Location: ./stalls.php?saved=status#stall-' . $target['id'], true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
                $editId = $target['id'];
            }
        }
    }

    $rows = km_stalls_all($pdo);
    if ($editId !== null) {
        $editing = $findStall($editId);
    }
} catch (Throwable $exception) {
    error_log('admin/stalls.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$notice = match ((string) ($_GET['saved'] ?? '')) {
    'created' => '屋台を足しました。下の鍵を屋台の係に渡してください。',
    'updated' => '屋台を直しました。',
    'rekeyed' => '鍵を出し直しました。前の鍵はもう使えません。',
    'revoked' => '止めました。鍵は使えず、地図にも出ません(鍵を出し直すと戻ります)。',
    'deleted' => '消しました。',
    'status' => 'メニューと混み具合を入れました。',
    default => null,
};

$value = static function (string $key) use ($form, $editing): string {
    if ($form !== null) {
        return (string) ($form[$key] ?? '');
    }
    return (string) ($editing[$key] ?? '');
};
$stallApiUrl = km_site_origin() . '/api/stall.php';

$KM_PAGE = [
    'nav' => 'stalls',
    'titleKey' => 'page.stalls.title',
    'title' => '屋台 | KosenMap 管理',
    'h1Key' => 'page.stalls.h1',
    'h1' => '屋台',
    'crumbs' => [
        ['key' => 'page.stalls.h1', 'text' => '屋台'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid">

            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($dbError) ?></div>
            <?php endif; ?>

            <?php if ($newKey !== null): ?>
              <div class="alert alert-warning" role="alert" id="stall-new-key">
                <strong><?= km_e((string) $newKey['name']) ?> の鍵(この画面を離れると二度と出ません)</strong>
                <div class="input-group my-2">
                  <input class="form-control font-monospace" type="text" readonly value="<?= km_e((string) $newKey['key']) ?>" aria-label="屋台の鍵">
                </div>
                <div class="fs-7">屋台の端末から、次の形で送ります(鍵は URL に入れず、Authorization の見出しで送る):</div>
                <pre class="bg-body-tertiary p-2 mb-0 fs-7"><code>curl -X POST <?= km_e($stallApiUrl) ?> \
  -H "Authorization: Bearer <?= km_e((string) $newKey['key']) ?>" \
  -H "Content-Type: application/json" \
  -d '{"waiting": 12, "menu": [{"name": "たこ焼き", "price": 300}], "note": "焼きたて"}'</code></pre>
              </div>
            <?php endif; ?>

            <div class="row g-4">
              <div class="col-xl-8">
                <div class="card mb-4" id="stalls-list">
                  <div class="card-header"><h3 class="card-title">屋台(<?= count($rows) ?> 件)</h3></div>
                  <div class="card-body">
                    <p class="text-body-secondary fs-7 mb-2">
                      公開の地図では、場所(企画の番号の地点・一時地点、または選んだ地点)の詳細と案内チャットに「◯人待ち」とメニューが出ます。
                      待ちの人数は <?= (int) (KM_STALL_WAITING_TTL / 60) ?> 分、メニューは <?= (int) (KM_STALL_MENU_TTL / 3600) ?> 時間たつと出さなくなります(送り直すと戻ります)。
                    </p>
                    <?php if ($rows === []): ?>
                      <p class="mb-0">まだありません。右の欄から足してください。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead><tr><th>屋台</th><th>場所</th><th>今の値</th><th>鍵</th><th></th></tr></thead>
                          <tbody>
                            <?php foreach ($rows as $row): ?>
                              <tr id="stall-<?= (int) $row['id'] ?>" class="<?= $row['revoked'] ? 'text-body-secondary' : '' ?><?= $editing !== null && $editing['id'] === $row['id'] ? ' table-active' : '' ?>">
                                <td><strong><?= km_e($row['name']) ?></strong><?= $row['revoked'] ? ' <span class="badge text-bg-secondary">止めた</span>' : '' ?></td>
                                <td class="fs-7">
                                  <?php if ($row['projectNo'] !== null): ?><span class="badge text-bg-info">企画 <?= km_e($row['projectNo']) ?></span><?php endif; ?>
                                  <?php if ($row['nodeId'] !== null): ?><div><?= km_e($nodeNames[$row['nodeId']] ?? 'この地点は地図にありません') ?></div><?php endif; ?>
                                </td>
                                <td class="fs-7">
                                  <?php $summary = km_stall_summary($row); ?>
                                  <?= $summary !== '' ? km_e($summary) : '<span class="text-body-secondary">まだ無い(古い値は出しません)</span>' ?>
                                  <?php if ($row['postedAt'] !== null): ?><div class="text-body-secondary">最後に受けたのは <?= km_e(substr((string) $row['postedAt'], 5, 11)) ?></div><?php endif; ?>
                                </td>
                                <td class="fs-7 font-monospace">…<?= km_e($row['keyHint']) ?></td>
                                <td class="text-nowrap">
                                  <a class="btn btn-sm btn-outline-secondary" href="./stalls.php?edit=<?= (int) $row['id'] ?>#stall-form">直す・値を入れる</a>
                                  <?php if ($KM_ADMIN_CAN_WRITE): ?>
                                    <form method="post" class="d-inline" data-km-confirm="<?= km_e($row['name']) ?> の鍵を出し直します。前の鍵は使えなくなります。">
                                      <?= km_csrf_field() ?>
                                      <input type="hidden" name="form_action" value="rekey">
                                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                      <button type="submit" class="btn btn-sm btn-outline-warning">鍵を出し直す</button>
                                    </form>
                                    <?php if (!$row['revoked']): ?>
                                      <form method="post" class="d-inline" data-km-confirm="<?= km_e($row['name']) ?> を止めます。鍵が使えなくなり、地図にも出ません。">
                                        <?= km_csrf_field() ?>
                                        <input type="hidden" name="form_action" value="revoke">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">止める</button>
                                      </form>
                                    <?php else: ?>
                                      <form method="post" class="d-inline" data-km-confirm="<?= km_e($row['name']) ?> を消します。元に戻せません。">
                                        <?= km_csrf_field() ?>
                                        <input type="hidden" name="form_action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">消す</button>
                                      </form>
                                    <?php endif; ?>
                                  <?php endif; ?>
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

              <div class="col-xl-4">
                <div class="card mb-4" id="stall-form">
                  <div class="card-header"><h3 class="card-title"><?= $editing !== null ? '屋台を直す' : '屋台を足す' ?></h3></div>
                  <div class="card-body">
                    <?php if (!$KM_ADMIN_CAN_WRITE): ?>
                      <p class="mb-0 text-body-secondary">書き込みの権限がありません。</p>
                    <?php else: ?>
                      <form method="post">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="form_action" value="save">
                        <?php if ($editing !== null): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                        <div class="mb-3">
                          <label class="form-label" for="km-stall-name">屋台の名前</label>
                          <input class="form-control" type="text" id="km-stall-name" name="name" maxlength="<?= KM_STALL_NAME_MAX ?>" required value="<?= km_e($value('name')) ?>">
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="km-stall-project">企画の番号</label>
                          <input class="form-control" type="text" id="km-stall-project" name="project_no" maxlength="16" value="<?= km_e($value('projectNo')) ?>" placeholder="例: 21">
                          <div class="form-text">番号を振った地点・名前が番号の一時地点に出ます(予定・ポスターと同じ)。</div>
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="km-stall-node">地点(番号が無いとき)</label>
                          <select class="form-select" id="km-stall-node" name="node_id">
                            <option value="">— 企画の番号の場所だけ —</option>
                            <?php foreach ($nodeNames as $nodeId => $nodeLabel): ?>
                              <option value="<?= km_e((string) $nodeId) ?>"<?= (string) $nodeId === $value('nodeId') ? ' selected' : '' ?>><?= km_e($nodeLabel) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= $editing !== null ? '直す' : '足して鍵を出す' ?></button>
                        <?php if ($editing !== null): ?><a class="btn btn-outline-secondary" href="./stalls.php">やめる</a><?php endif; ?>
                      </form>

                      <?php if ($editing !== null): ?>
                        <hr>
                        <h4 class="fs-6">メニューと混み具合を入れる(係が代わりに)</h4>
                        <form method="post">
                          <?= km_csrf_field() ?>
                          <input type="hidden" name="form_action" value="status">
                          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                          <div class="mb-2">
                            <label class="form-label" for="km-stall-waiting">待ちの人数</label>
                            <input class="form-control" type="text" inputmode="numeric" id="km-stall-waiting" name="waiting" maxlength="3" value="<?= $editing['waiting'] !== null ? (int) $editing['waiting'] : '' ?>" placeholder="空なら出さない">
                          </div>
                          <div class="mb-2">
                            <label class="form-label" for="km-stall-menu">メニュー(1 行に 1 品「名前,値段」。売り切れは頭に ×)</label>
                            <textarea class="form-control" id="km-stall-menu" name="menu" rows="5" placeholder="たこ焼き,300&#10;×ラムネ,150"><?= km_e(km_stall_menu_to_text($editing['menu'])) ?></textarea>
                          </div>
                          <div class="mb-2">
                            <label class="form-label" for="km-stall-note">ひとこと</label>
                            <input class="form-control" type="text" id="km-stall-note" name="note" maxlength="<?= KM_STALL_NOTE_MAX ?>" value="<?= km_e($editing['note']) ?>">
                          </div>
                          <button type="submit" class="btn btn-outline-primary">入れる</button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
