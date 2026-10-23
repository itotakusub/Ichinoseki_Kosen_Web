<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/map-floors.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * 階と平面図(2026-10-21、利用者の指示「地図画像の差し替え、階や内部の地図の差し替え・追加・削除」。lib/map-floors.php)。
 *
 * - 階ごとに: 画像の差し替え(プレビューで縦横の比を確かめる)・元の画像に戻す・名前と並び順・消す
 * - 階を足す: 校舎の階(右の階の並びに出る)か、建物の中(屋外の建物の「中へ」から開く)
 * - zip でまとめて差し替え: 画像の名前から階を当て、確かめてから差し替える
 *
 * 地点の置き方・線は今までどおり「地図編集」。ここは階と画像だけ。
 */

const KM_FLOOR_ZIP_SESSION_KEY = 'km_floor_zip';

$errors = [];
$notice = null;
$dbError = null;
$saved = '';
$editId = isset($_GET['edit']) && is_string($_GET['edit']) && km_map_floor_id_valid($_GET['edit']) ? $_GET['edit'] : null;
$addForm = null;
$staged = is_array($_SESSION[KM_FLOOR_ZIP_SESSION_KEY] ?? null) ? $_SESSION[KM_FLOOR_ZIP_SESSION_KEY] : null;

try {
    $pdo = km_db();
    km_map_floors_ensure_tables($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $floorId = (string) ($_POST['floor_id'] ?? '');

        try {
            if ($formAction === 'replace') {
                $result = km_map_floor_replace_image($pdo, $floorId, km_map_floor_read_upload($_FILES['image'] ?? []));
                km_admin_log_record('content', 'map.floor_image', $floorId . '(' . $result['width'] . '×' . $result['height'] . ')');
                header('Location: ./map-floors.php?saved=' . ($result['aspectDiffers'] ? 'replaced_aspect' : 'replaced') . '&edit=' . rawurlencode($floorId), true, 303);
                exit;
            }
            if ($formAction === 'restore_image') {
                km_map_floor_restore_image($pdo, $floorId);
                km_admin_log_record('content', 'map.floor_image', $floorId . '(元の画像に戻した)');
                header('Location: ./map-floors.php?saved=restored&edit=' . rawurlencode($floorId), true, 303);
                exit;
            }
            if ($formAction === 'update') {
                $fields = km_map_floor_validate((string) ($_POST['label'] ?? ''), (string) ($_POST['sort_order'] ?? ''), (string) ($_POST['keywords'] ?? ''));
                km_map_floor_update($pdo, $floorId, $fields);
                km_admin_log_record('content', 'map.floor_update', $floorId . ' → ' . $fields['label']);
                header('Location: ./map-floors.php?saved=updated&edit=' . rawurlencode($floorId), true, 303);
                exit;
            }
            if ($formAction === 'add') {
                $kind = (string) ($_POST['kind'] ?? '');
                $addForm = [
                    'id' => trim((string) ($_POST['new_id'] ?? '')),
                    'label' => (string) ($_POST['label'] ?? ''),
                    'kind' => $kind,
                    'sortOrder' => (string) ($_POST['sort_order'] ?? ''),
                    'keywords' => (string) ($_POST['keywords'] ?? ''),
                ];
                $fields = km_map_floor_validate($addForm['label'], $addForm['sortOrder'], $addForm['keywords']);
                km_map_floor_add($pdo, $addForm['id'], $kind, $fields, km_map_floor_read_upload($_FILES['image'] ?? []));
                km_admin_log_record('content', 'map.floor_add', $addForm['id'] . ' ' . $fields['label']);
                header('Location: ./map-floors.php?saved=added&edit=' . rawurlencode($addForm['id']), true, 303);
                exit;
            }
            if ($formAction === 'delete') {
                $result = km_map_floor_delete($pdo, $floorId, isset($_POST['with_nodes']));
                km_admin_log_record('content', 'map.floor_delete', $floorId . '(地点 ' . $result['nodes'] . '・線 ' . $result['edges'] . ')');
                header('Location: ./map-floors.php?saved=deleted&nodes=' . $result['nodes'], true, 303);
                exit;
            }
            if ($formAction === 'restore_builtins') {
                $restored = km_map_floor_restore_builtins($pdo);
                km_admin_log_record('content', 'map.floor_add', '組み込みの階を戻した: ' . implode(', ', $restored));
                header('Location: ./map-floors.php?saved=builtins&count=' . count($restored), true, 303);
                exit;
            }
            if ($formAction === 'zip_upload') {
                $file = $_FILES['zip'] ?? [];
                km_map_floor_read_upload($file, 'zip ');   // 本当にアップロードされたものかの確かめ
                if ($staged !== null) {
                    km_map_floor_staging_remove((string) ($staged['token'] ?? ''));
                }
                $staged = km_map_floor_zip_stage((string) $file['tmp_name'], km_map_floors_admin($pdo));
                $_SESSION[KM_FLOOR_ZIP_SESSION_KEY] = $staged;
                km_admin_log_record('content', 'map.floor_zip', count($staged['items']) . ' 個を読み込んだ');
                header('Location: ./map-floors.php?saved=zip_staged#floor-zip', true, 303);
                exit;
            }
            if ($formAction === 'zip_apply') {
                if ($staged === null) {
                    throw new InvalidArgumentException('取り込む画像が見つかりません。zip を上げ直してください。');
                }
                $assign = [];
                foreach ((array) ($_POST['assign'] ?? []) as $index => $value) {
                    $value = (string) $value;
                    $assign[(int) $index] = km_map_floor_id_valid($value) ? $value : '';
                }
                $done = km_map_floor_zip_apply($pdo, $staged, $assign);
                unset($_SESSION[KM_FLOOR_ZIP_SESSION_KEY]);
                km_admin_log_record('content', 'map.floor_zip', '差し替え: ' . implode(', ', array_column($done, 'floor')));
                $warn = array_column(array_filter($done, static fn (array $d): bool => $d['aspectDiffers']), 'floor');
                header('Location: ./map-floors.php?saved=zip_applied&count=' . count($done) . ($warn === [] ? '' : '&aspect=' . rawurlencode(implode(',', $warn))), true, 303);
                exit;
            }
            if ($formAction === 'zip_cancel') {
                if ($staged !== null) {
                    km_map_floor_staging_remove((string) ($staged['token'] ?? ''));
                }
                unset($_SESSION[KM_FLOOR_ZIP_SESSION_KEY]);
                header('Location: ./map-floors.php#floor-zip', true, 303);
                exit;
            }
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
            if ($formAction !== 'add' && km_map_floor_id_valid($floorId)) {
                $editId = $floorId;
            }
        }
    }

    $saved = (string) ($_GET['saved'] ?? '');
    $count = (int) ($_GET['count'] ?? 0);
    $notice = [
        'replaced' => '画像を差し替えました。公開ページを開き直すと反映されます。',
        'replaced_aspect' => '画像を差し替えました。**縦横の比が今の座標の範囲と違うので、画像が伸びて表示されます。**地点の位置を地図で確かめてください。',
        'restored' => '元の画像に戻しました。',
        'updated' => '名前と並び順を保存しました。',
        'added' => '階を足しました。地点は「地図編集」で置いてください。',
        'deleted' => '階を消しました' . ((int) ($_GET['nodes'] ?? 0) > 0 ? '(中の地点 ' . (int) $_GET['nodes'] . ' 件と、その線も消しました)' : '') . '。',
        'builtins' => $count > 0 ? '組み込みの階を ' . $count . ' つ戻しました。' : '戻す組み込みの階はありませんでした(元の画像が見つからない階は戻せません)。',
        'zip_staged' => 'zip を読み込みました。下の表で当てる階を確かめてから「差し替える」を押してください。',
        'zip_applied' => $count . ' つの階の画像を差し替えました。' . (isset($_GET['aspect']) ? '縦横の比が座標の範囲と違う階があります(' . (string) $_GET['aspect'] . ')。画像が伸びて表示されます。' : ''),
    ][$saved] ?? null;

    $floors = km_map_floors_admin($pdo);
    $missingBuiltins = km_map_floor_missing_builtins($floors);
} catch (Throwable $exception) {
    error_log('map-floors.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
    $floors = [];
    $missingBuiltins = [];
}

$editing = null;
foreach ($floors as $floor) {
    if ($floor['id'] === $editId) {
        $editing = $floor;
    }
}
$floorLabels = array_column($floors, 'label', 'id');
$imageUrl = static fn (array $floor): string => '/api/floor-image.php?floor=' . rawurlencode($floor['id']) . '&v=' . $floor['imageVersion'];
$kindLabel = static fn (string $kind): string => $kind === 'building' ? '建物の中' : '校舎の階';
$nextSort = static function (string $kind) use ($floors): int {
    $orders = array_column(array_filter($floors, static fn (array $f): bool => $f['kind'] === $kind), 'sortOrder');
    return $orders === [] ? ($kind === 'building' ? KM_BUILDING_FLOOR_SORT_BASE : 6) : max($orders) + 1;
};

$KM_PAGE = [
    'nav' => 'mapFloors',
    'titleKey' => 'page.mapFloors.title',
    'title' => '階と平面図 | KosenMap 管理',
    'h1Key' => 'page.mapFloors.h1',
    'h1' => '階と平面図',
    'crumbs' => [
        ['key' => 'page.mapFloors.h1', 'text' => '階と平面図'],
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
              <?php // 文言の ** は太字の印(ここで書いた固定の文だけ。利用者の値は入らない) ?>
              <div class="alert <?= $saved === 'replaced_aspect' || isset($_GET['aspect']) ? 'alert-warning' : 'alert-success' ?>" role="alert"><?= preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', km_e($notice)) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert"><?= km_e($dbError) ?></div>
            <?php endif; ?>

            <div class="alert alert-info fs-7" role="note">
              <strong>校舎の階</strong>は公開ページの右の階の並びに出ます。<strong>建物の中</strong>は、屋外でその建物を押すと「中へ」に出ます。
              画像を差し替えても<strong>地点の座標はそのまま</strong>です(画像を今の座標の範囲に合わせて表示します。縦横の比が違うと伸びるので、プレビューで知らせます)。
              地点と線の置き方は <a href="./map-editor.php">地図編集</a> で。
              <strong>アプリには反映されません</strong>(アプリの画像はアプリの中に入っています。ここで足した階の地点は、アプリへの配信では「知らない階」として外れます)。
            </div>

            <div class="row">
              <div class="col-12 col-xxl-7">
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title">階(<?= count($floors) ?>)</h3></div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr><th scope="col">画像</th><th scope="col">名前・id</th><th scope="col">種類</th><th scope="col">地点</th><th scope="col">画像の大きさ / 座標の範囲</th><th scope="col"></th></tr>
                        </thead>
                        <tbody>
                          <?php foreach ($floors as $floor): ?>
                            <tr class="<?= $editId === $floor['id'] ? 'table-active' : '' ?>">
                              <td class="km-floor-thumb-cell">
                                <?php if ($floor['imageMissing']): ?>
                                  <span class="badge text-bg-danger">画像なし</span>
                                <?php else: ?>
                                  <img class="km-floor-thumb" src="<?= km_e($imageUrl($floor)) ?>" alt="" loading="lazy">
                                <?php endif; ?>
                              </td>
                              <td>
                                <div class="fw-bold"><?= km_e($floor['label']) ?></div>
                                <div class="fs-7 text-body-secondary"><code><?= km_e($floor['id']) ?></code>・並び <?= (int) $floor['sortOrder'] ?></div>
                              </td>
                              <td class="fs-7">
                                <?= km_e($kindLabel($floor['kind'])) ?>
                                <div>
                                  <?php if ($floor['builtin']): ?><span class="badge text-bg-secondary">組み込み</span><?php else: ?><span class="badge text-bg-info">Website で追加</span><?php endif; ?>
                                  <?php if ($floor['builtin'] && $floor['uploaded']): ?><span class="badge text-bg-primary">差し替え済み</span><?php endif; ?>
                                </div>
                              </td>
                              <td><?= (int) $floor['nodeCount'] ?></td>
                              <td class="fs-7">
                                <?= (int) $floor['imageWidth'] ?>×<?= (int) $floor['imageHeight'] ?> /
                                <?= (int) $floor['coordWidth'] ?>×<?= (int) $floor['coordHeight'] ?>
                                <?php if (km_map_floor_aspect_differs((int) $floor['imageWidth'], (int) $floor['imageHeight'], (int) $floor['coordWidth'], (int) $floor['coordHeight'])): ?>
                                  <div><span class="badge text-bg-warning">縦横の比が違う(伸びて表示)</span></div>
                                <?php endif; ?>
                              </td>
                              <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="./map-floors.php?edit=<?= km_e(rawurlencode($floor['id'])) ?>#floor-edit">差し替え・編集</a>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <?php if ($missingBuiltins !== []): ?>
                    <div class="card-footer fs-7 d-flex flex-wrap align-items-center gap-2">
                      <span>消した組み込みの階: <?= km_e(implode(', ', $missingBuiltins)) ?></span>
                      <form method="post" class="m-0">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="form_action" value="restore_builtins">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">組み込みの階を戻す(元の画像で)</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="col-12 col-xxl-5">
                <?php if ($editing !== null): ?>
                  <div class="card mb-4 card-primary card-outline" id="floor-edit">
                    <div class="card-header">
                      <h3 class="card-title">「<?= km_e($editing['label']) ?>」(<code><?= km_e($editing['id']) ?></code>)</h3>
                      <div class="card-tools"><a class="btn btn-sm btn-outline-secondary" href="./map-floors.php">閉じる</a></div>
                    </div>
                    <div class="card-body">
                      <h4 class="fs-6 fw-bold">画像を差し替える</h4>
                      <form method="post" enctype="multipart/form-data" class="mb-3" data-km-floor-image-form
                            data-coord-width="<?= (int) $editing['coordWidth'] ?>" data-coord-height="<?= (int) $editing['coordHeight'] ?>">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="form_action" value="replace">
                        <input type="hidden" name="floor_id" value="<?= km_e($editing['id']) ?>">
                        <input class="form-control mb-2" type="file" name="image" accept="image/png,image/jpeg,image/gif,image/webp" required data-km-floor-file>
                        <div class="km-floor-compare mb-2">
                          <figure class="m-0">
                            <figcaption class="fs-7 text-body-secondary">いま</figcaption>
                            <?php if (!$editing['imageMissing']): ?>
                              <img class="km-floor-preview" src="<?= km_e($imageUrl($editing)) ?>" alt="いまの画像">
                            <?php endif; ?>
                          </figure>
                          <figure class="m-0">
                            <figcaption class="fs-7 text-body-secondary">新しい画像(プレビュー)</figcaption>
                            <img class="km-floor-preview" alt="新しい画像" hidden data-km-floor-preview>
                            <span class="km-floor-preview-empty fs-7" data-km-floor-preview-empty>ファイルを選ぶとここに出ます</span>
                          </figure>
                        </div>
                        <div class="fs-7 mb-2" data-km-floor-meta></div>
                        <div class="fs-7 text-danger mb-2" data-km-floor-error hidden></div>
                        <div class="form-text mb-2">
                          PNG / JPEG / GIF / WebP、<?= km_e(km_upload_format_size(KM_MAP_FLOOR_MAX_BYTES)) ?>・縦横 <?= KM_MAP_FLOOR_MAX_DIMENSION ?>px まで。
                          座標の範囲は <?= (int) $editing['coordWidth'] ?>×<?= (int) $editing['coordHeight'] ?> —— <strong>同じ縦横の比</strong>の画像にすると、地点の位置がずれません。
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">差し替える</button>
                      </form>
                      <?php if ($editing['builtin'] && $editing['uploaded']): ?>
                        <form method="post" class="mb-3" data-km-confirm="元の画像(組み込み)に戻します。差し替えた画像は消えます。よろしいですか?">
                          <?= km_csrf_field() ?>
                          <input type="hidden" name="form_action" value="restore_image">
                          <input type="hidden" name="floor_id" value="<?= km_e($editing['id']) ?>">
                          <button type="submit" class="btn btn-outline-secondary btn-sm">元の画像に戻す</button>
                        </form>
                      <?php endif; ?>

                      <hr>
                      <h4 class="fs-6 fw-bold">名前と並び順</h4>
                      <form method="post" class="mb-3">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="form_action" value="update">
                        <input type="hidden" name="floor_id" value="<?= km_e($editing['id']) ?>">
                        <div class="row g-2">
                          <div class="col-7">
                            <label class="form-label" for="km-floor-label">名前</label>
                            <input class="form-control" id="km-floor-label" name="label" maxlength="<?= KM_MAP_FLOOR_LABEL_MAX ?>" required value="<?= km_e($editing['label']) ?>">
                          </div>
                          <div class="col-5">
                            <label class="form-label" for="km-floor-sort">並び順</label>
                            <input class="form-control" id="km-floor-sort" name="sort_order" type="number" step="1" value="<?= (int) $editing['sortOrder'] ?>">
                          </div>
                        </div>
                        <?php if ($editing['custom'] && $editing['kind'] === 'building'): ?>
                          <label class="form-label mt-2" for="km-floor-keywords">屋外の建物の名前の手がかり</label>
                          <input class="form-control" id="km-floor-keywords" name="keywords" value="<?= km_e(implode('、', $editing['keywords'])) ?>">
                        <?php else: ?>
                          <input type="hidden" name="keywords" value="">
                        <?php endif; ?>
                        <?php if ($editing['builtin'] && $editing['kind'] === 'campus'): ?>
                          <div class="form-text">組み込みの校舎の階は、右の階の並びの文字(外・1F…)は変わりません。名前は地図編集と案内の帯で使います。</div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm mt-2">保存</button>
                      </form>

                      <hr>
                      <h4 class="fs-6 fw-bold">この階を消す</h4>
                      <?php if ($editing['id'] === KM_MAP_FLOOR_INITIAL): ?>
                        <p class="fs-7 text-body-secondary mb-0">1F は公開ページを開いたときに最初に出す階なので消せません。</p>
                      <?php else: ?>
                        <form method="post" data-km-confirm="「<?= km_e($editing['label']) ?>」を消します。<?= $editing['nodeCount'] > 0 ? '印を付けていれば中の地点 ' . (int) $editing['nodeCount'] . ' 件と線も消えます。' : '' ?>よろしいですか?">
                          <?= km_csrf_field() ?>
                          <input type="hidden" name="form_action" value="delete">
                          <input type="hidden" name="floor_id" value="<?= km_e($editing['id']) ?>">
                          <?php if ($editing['nodeCount'] > 0): ?>
                            <div class="form-check mb-2">
                              <input class="form-check-input" type="checkbox" id="km-floor-with-nodes" name="with_nodes" value="1">
                              <label class="form-check-label" for="km-floor-with-nodes">中の地点 <?= (int) $editing['nodeCount'] ?> 件と、その線も消す(戻せません)</label>
                            </div>
                          <?php endif; ?>
                          <?php if ($editing['builtin']): ?>
                            <div class="form-text mb-2">組み込みの階は、あとで「組み込みの階を戻す」で戻せます(地点は戻りません)。アプリの地図を取り込むと、この階の地点はまた入りますが、階が無い間は公開ページに出ません。</div>
                          <?php endif; ?>
                          <button type="submit" class="btn btn-outline-danger btn-sm">この階を消す</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <div class="card mb-4" id="floor-add">
                  <div class="card-header"><h3 class="card-title">階を足す</h3></div>
                  <form method="post" enctype="multipart/form-data" class="card-body" data-km-floor-image-form data-coord-width="0" data-coord-height="0">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="add">
                    <fieldset class="mb-2">
                      <legend class="form-label fs-6 mb-1">種類</legend>
                      <?php $addKind = (string) ($addForm['kind'] ?? 'campus'); ?>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="kind" id="km-kind-campus" value="campus" <?= $addKind !== 'building' ? 'checked' : '' ?> data-next-sort="<?= $nextSort('campus') ?>">
                        <label class="form-check-label" for="km-kind-campus">校舎の階(右の並びに出る)</label>
                      </div>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="kind" id="km-kind-building" value="building" <?= $addKind === 'building' ? 'checked' : '' ?> data-next-sort="<?= $nextSort('building') ?>">
                        <label class="form-check-label" for="km-kind-building">建物の中(「中へ」から開く)</label>
                      </div>
                    </fieldset>
                    <div class="row g-2 mb-2">
                      <div class="col-5">
                        <label class="form-label" for="km-new-id">id</label>
                        <input class="form-control" id="km-new-id" name="new_id" maxlength="16" pattern="[a-z0-9][a-z0-9_]{0,15}" required placeholder="例: 6 / bldg_new_1f" value="<?= km_e((string) ($addForm['id'] ?? '')) ?>">
                      </div>
                      <div class="col-7">
                        <label class="form-label" for="km-new-label">名前</label>
                        <input class="form-control" id="km-new-label" name="label" maxlength="<?= KM_MAP_FLOOR_LABEL_MAX ?>" required placeholder="例: 6F / 新棟 1F" value="<?= km_e((string) ($addForm['label'] ?? '')) ?>">
                      </div>
                    </div>
                    <div class="form-text mb-2">id は後から変えられません(URL と地点の記録に入ります)。半角の小文字・数字・_ で 16 字まで。校舎の階の名前は右の丸に入るので、短く(4 字くらいまで)。</div>
                    <div class="row g-2 mb-2">
                      <div class="col-4">
                        <label class="form-label" for="km-new-sort">並び順</label>
                        <input class="form-control" id="km-new-sort" name="sort_order" type="number" step="1" value="<?= km_e((string) ($addForm['sortOrder'] ?? $nextSort($addKind === 'building' ? 'building' : 'campus'))) ?>" data-km-new-sort>
                      </div>
                      <div class="col-8" data-km-keywords-row>
                        <label class="form-label" for="km-new-keywords">屋外の建物の名前の手がかり</label>
                        <input class="form-control" id="km-new-keywords" name="keywords" placeholder="例: 図書館、メディアセンター" value="<?= km_e((string) ($addForm['keywords'] ?? '')) ?>">
                      </div>
                    </div>
                    <label class="form-label" for="km-new-image">画像</label>
                    <input class="form-control mb-2" id="km-new-image" type="file" name="image" accept="image/png,image/jpeg,image/gif,image/webp" required data-km-floor-file>
                    <img class="km-floor-preview mb-1" alt="新しい画像" hidden data-km-floor-preview>
                    <span class="km-floor-preview-empty fs-7" data-km-floor-preview-empty hidden></span>
                    <div class="fs-7 mb-2" data-km-floor-meta></div>
                    <div class="fs-7 text-danger mb-2" data-km-floor-error hidden></div>
                    <div class="form-text mb-2">座標の範囲は画像の大きさになります。足したあと、地点は「地図編集」でこの階を選んで置きます。</div>
                    <button type="submit" class="btn btn-primary">足す</button>
                  </form>
                </div>
              </div>
            </div>

            <div class="card mb-4" id="floor-zip">
              <div class="card-header"><h3 class="card-title">zip でまとめて差し替える</h3></div>
              <div class="card-body">
                <?php if ($staged === null): ?>
                  <p class="fs-7">
                    画像をまとめた zip を上げると、<strong>ファイルの名前から階を当てます</strong>(例: <code>1.png</code>・<code>1F.png</code>・<code>１階.png</code>・<code>外全体図.png</code>・<code>bldg_library_1f.png</code>・<code>図書館 1F.png</code>)。
                    当てた結果を表で確かめてから差し替えます(当たらなかったものは表で選べます)。
                    zip は <?= KM_MAP_FLOOR_ZIP_MAX_ENTRIES ?> 個・1 つ <?= km_e(km_upload_format_size(KM_MAP_FLOOR_MAX_BYTES)) ?> まで(zip そのものは 11MB まで)。Windows なら、画像を選んで右クリック →「送る」→「圧縮(zip 形式)フォルダー」。
                  </p>
                  <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="zip_upload">
                    <input class="form-control km-w-24rem" type="file" name="zip" accept=".zip,application/zip" required>
                    <button type="submit" class="btn btn-primary">読み込む</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="zip_apply">
                    <div class="table-responsive">
                      <table class="table table-sm align-middle">
                        <thead><tr><th scope="col">画像</th><th scope="col">ファイル</th><th scope="col">大きさ</th><th scope="col">当てる階</th></tr></thead>
                        <tbody>
                          <?php foreach ($staged['items'] as $index => $item): ?>
                            <tr>
                              <td class="km-floor-thumb-cell">
                                <?php if ($item['error'] === null): ?>
                                  <img class="km-floor-thumb" src="./api/floor-staging-image.php?i=<?= (int) $index ?>&amp;t=<?= km_e(substr((string) $staged['token'], 0, 8)) ?>" alt="" loading="lazy">
                                <?php endif; ?>
                              </td>
                              <td class="text-break"><?= km_e($item['name']) ?></td>
                              <td class="fs-7"><?= $item['error'] === null ? (int) $item['width'] . '×' . (int) $item['height'] : '' ?></td>
                              <td>
                                <?php if ($item['error'] !== null): ?>
                                  <span class="text-danger fs-7"><?= km_e($item['error']) ?></span>
                                <?php else: ?>
                                  <select class="form-select form-select-sm" name="assign[<?= (int) $index ?>]" data-km-zip-assign
                                          data-width="<?= (int) $item['width'] ?>" data-height="<?= (int) $item['height'] ?>">
                                    <option value="">(使わない)</option>
                                    <?php foreach ($floors as $floor): ?>
                                      <option value="<?= km_e($floor['id']) ?>" data-coord-width="<?= (int) $floor['coordWidth'] ?>" data-coord-height="<?= (int) $floor['coordHeight'] ?>" <?= $item['match'] === $floor['id'] ? 'selected' : '' ?>><?= km_e($floor['label']) ?>(<?= km_e($floor['id']) ?>)</option>
                                    <?php endforeach; ?>
                                  </select>
                                  <div class="fs-7 text-warning-emphasis" data-km-zip-warn></div>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <button type="submit" class="btn btn-primary">差し替える</button>
                  </form>
                  <form method="post" class="mt-2">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="zip_cancel">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">やめる(読み込んだ画像を捨てる)</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
        <script src="<?= km_e(km_asset('./assets/js/map-floors.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
