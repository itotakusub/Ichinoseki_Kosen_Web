<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/app-map-sync.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * 管理アプリの書き出しを、この Website の地点と経路へ取り込む。
 *
 * ## 向きが変わった(2026-09-03)
 *
 * 以前は「アプリが正本」で、この画面は**配信中のファイルを読んで作り直す**だけだった。
 * 作り直しとは文字どおり `DELETE` してから入れ直すことで、
 * **Website で足したものは毎回消えていた。**
 *
 * いまは Website が地図の保管庫で、作成・編集・配信もここで行う。
 * アプリでも編集できる(利用者の判断)ので、**この画面はアプリ側の変更を戻す口**になる。
 *
 * ## 作り直さない
 *
 * 上げた地図に無いものは**既定で残す。** 消すのは明示的に選んだときだけ。
 * 両方で編集する以上、片方が黙って全部を作り直すと、
 * もう片方で直したものが消える —— それが「地図が2つある」状態の正体だった。
 *
 * ## 適用の前に必ず数を見せる
 *
 * 何件増えて何件置き換わり、消すと**どの氏名が失われる**のかを出してから押させる。
 */

$slug = 'kosen-main';
$errors = [];
$notice = null;

$existing = [];
$converted = null;
$diff = null;
$dbError = null;

try {
    $pdo = km_db();
    $existing = km_app_map_sync_existing($pdo);
} catch (Throwable $exception) {
    error_log('map-sync.php db failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['do_sync'] ?? '');

    if ($action === 'upload') {
        $file = $_FILES['map_json'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'ファイルを受け取れませんでした。';
        } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
            // **上げられたファイル以外を読ませない**
            $errors[] = 'ファイルを受け取れませんでした。';
        } else {
            $reason = km_app_map_import_store(
                (string) $file['tmp_name'],
                (int) ($file['size'] ?? 0)
            );
            if ($reason !== null) {
                $errors[] = $reason;
            } else {
                km_admin_log_record('content', 'map.import_upload', (string) ($file['name'] ?? ''));
                header('Location: ./map-sync.php?uploaded=1', true, 302);
                exit;
            }
        }
    } elseif ($action === 'discard') {
        km_app_map_import_discard();
        header('Location: ./map-sync.php?discarded=1', true, 302);
        exit;
    } elseif ($action === 'apply') {
        $staged = km_app_map_import_load();
        if ($staged['map'] === null) {
            $errors[] = '取り込み待ちの地図がありません。先にファイルを上げてください。';
        } else {
            /*
             * **取り込みだけは時間の上限を広げる。** 地点が数百あると1件ずつの UPDATE が並び、
             * 既定の max_execution_time(30秒、docker/php/99-limits.ini)を超えうる。
             * 途中で切られると半分だけ入った地図になるので、この処理に限って明示する。
             * SQL の1文ごとの上限(lib/db.php の 15 秒)はそのまま効く。
             */
            set_time_limit(120);
            try {
                $removeMissing = ($_POST['remove_missing'] ?? '') === '1';
                /*
                 * **既定で守る側に倒す。** チェックを外したときだけ上書きさせる ——
                 * 氏名が消えるのは、画面のどこにも出ない失敗になる。
                 */
                $keepOccupantNames = ($_POST['overwrite_occupant'] ?? '') !== '1';
                $result = km_app_map_sync_apply(
                    km_db(),
                    km_app_map_to_web($staged['map']),
                    $removeMissing,
                    $keepOccupantNames
                );
                km_admin_log_record(
                    'content',
                    'map.sync',
                    "追加{$result['added']} / 更新{$result['updated']} / 削除{$result['removed']}"
                    . " / 経路+{$result['edgesAdded']}-{$result['edgesRemoved']}"
                );
                /*
                 * **取り込んだら置き場を空にする。**
                 * 残すと、次に開いた人が「まだ取り込んでいない」と読む。
                 */
                km_app_map_import_discard();
                header('Location: ./map-sync.php?synced=1', true, 302);
                exit;
            } catch (Throwable $exception) {
                error_log('map-sync.php apply failed: ' . $exception->getMessage());
                // DB の文面は出さない(lib/user-error.php)。入力の検証で断った文だけそのまま出る
                $errors[] = km_admin_error_message($exception, '取り込めませんでした。');
            }
        }
    }
}

$staged = km_app_map_import_load();
if ($staged['error'] !== null) {
    $errors[] = $staged['error'];
}
$map = $staged['map'];

if ($map !== null) {
    $converted = km_app_map_to_web($map);
    $diff = km_app_map_diff($converted, $existing);
}

/** DB にある氏名の件数。取り込みで消える恐れがあるので出す。 */
$occupantsInDb = count(array_filter($existing, static fn ($r) => $r['occupant_name'] !== null));
$occupantsInApp = $diff['occupantKept'] ?? 0;

if (isset($_GET['uploaded'])) {
    $notice = 'ファイルを受け取りました。下の差分を確かめてから取り込んでください。';
} elseif (isset($_GET['discarded'])) {
    $notice = '取り込み待ちのファイルを捨てました。';
} elseif (isset($_GET['synced'])) {
    $notice = 'アプリの地図を取り込みました。';
}

$KM_PAGE = [
    'nav' => 'mapSync',
    'titleKey' => 'page.mapSync.title',
    'title' => 'アプリの地図を取り込む | KosenMap 管理',
    'h1Key' => 'page.mapSync.h1',
    'h1' => 'アプリの地図を取り込む',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.mapSync.h1', 'text' => 'アプリの地図を取り込む'],
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
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.mapSync.dbError">
                データベースに接続できません。
              </div>
            <?php endif; ?>

            <div class="card mb-4">
              <div class="card-body">
                <p class="mb-0" data-i18n="page.mapSync.lead">
                  管理アプリで書き出した地図を読み込み、この Website の地点と経路へ反映します。
                  <strong>上げた地図に無いものは、既定では消しません。</strong>
                  Website 側で足した地点が、取り込みのたびに消えないようにするためです。
                </p>
              </div>
            </div>

            <div class="card mb-4">
              <div class="card-header">
                <h3 class="card-title" data-i18n="page.mapSync.uploadTitle">書き出したファイルを上げる</h3>
              </div>
              <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="do_sync" value="upload">
                  <div class="col-12 col-md-8">
                    <label class="form-label" for="map_json" data-i18n="page.mapSync.uploadLabel">
                      管理アプリの「エクスポート」で作った JSON
                    </label>
                    <input class="form-control" type="file" id="map_json" name="map_json"
                           accept="application/json,.json" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                      <i class="bi bi-upload me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapSync.uploadButton">読み込む</span>
                    </button>
                  </div>
                </form>
                <p class="form-text mb-0 mt-2" data-i18n="page.mapSync.uploadHint">
                  読み込んだだけでは何も変わりません。差分を見てから取り込みます。
                </p>
              </div>
            </div>

            <?php if ($converted === null): ?>
              <div class="alert alert-secondary" role="alert" data-i18n="page.mapSync.noStaged">
                取り込み待ちのファイルはありません。
              </div>
            <?php else: ?>
              <!--begin::Row-->
              <div class="row">
                <div class="col-12 col-xl-6">
                  <div class="card mb-4">
                    <div class="card-header">
                      <h3 class="card-title" data-i18n="page.mapSync.previewTitle">取り込むと何が起きるか</h3>
                    </div>
                    <div class="card-body">
                      <dl class="row mb-0">
                        <dt class="col-7" data-i18n="page.mapSync.added">増える地点</dt>
                        <dd class="col-5 text-end"><?= (int) $diff['added'] ?></dd>
                        <dt class="col-7" data-i18n="page.mapSync.updated">中身が変わる地点</dt>
                        <dd class="col-5 text-end"><?= (int) $diff['updated'] ?></dd>
                        <?php
                        /*
                         * **「変わらない」を出す。**
                         * これが無いと、何も直していない書き出しでも
                         * 「置き換わる地点 685」と出て、数字が意味を失う。
                         */
                        ?>
                        <dt class="col-7" data-i18n="page.mapSync.unchanged">変わらない地点</dt>
                        <dd class="col-5 text-end text-body-secondary"><?= (int) $diff['unchanged'] ?></dd>
                        <dt class="col-7" data-i18n="page.mapSync.removed">上げた地図に無い地点</dt>
                        <dd class="col-5 text-end"><?= (int) $diff['removed'] ?></dd>
                        <dt class="col-7" data-i18n="page.mapSync.edges">経路</dt>
                        <dd class="col-5 text-end"><?= count($converted['edges']) ?></dd>
                      </dl>
                    </div>
                  </div>
                </div>

                <div class="col-12 col-xl-6">
                  <div class="card mb-4">
                    <div class="card-header">
                      <h3 class="card-title" data-i18n="page.mapSync.skippedTitle">取り込めないもの</h3>
                    </div>
                    <div class="card-body">
                      <?php
                      /*
                       * **黙って落とさない。**
                       * 種類で落とすことはもう無い(壁も Wi-Fi ルーターも持つ)ので、
                       * ここに出るのは**知らない種類**と**知らない階**だけ。
                       * 出たら、それはアプリ側に新しい概念が増えたということ。
                       */
                      ?>
                      <dl class="row mb-0">
                        <?php foreach (($converted['skipped']['unknownType'] ?? []) as $type => $count): ?>
                          <dt class="col-7 text-danger">
                            <code><?= km_e((string) $type) ?></code>
                            <span data-i18n="page.mapSync.unknownType">(知らない種類)</span>
                          </dt>
                          <dd class="col-5 text-end text-danger"><?= (int) $count ?> 件</dd>
                        <?php endforeach; ?>
                        <?php if (($converted['skipped']['unknownFloor'] ?? []) !== []): ?>
                          <dt class="col-7 text-danger" data-i18n="page.mapSync.unknownFloor">知らない階</dt>
                          <dd class="col-5 text-end text-danger">
                            <?= km_e(implode(' / ', $converted['skipped']['unknownFloor'])) ?>
                          </dd>
                        <?php endif; ?>
                        <dt class="col-7" data-i18n="page.mapSync.dangling">端点を失った経路</dt>
                        <dd class="col-5 text-end"><?= (int) ($converted['skipped']['danglingEdge'] ?? 0) ?></dd>
                      </dl>
                    </div>
                  </div>
                </div>
              </div>
              <!--end::Row-->

              <div class="card mb-4">
                <div class="card-header">
                  <h3 class="card-title" data-i18n="page.mapSync.occupantTitle">教職員氏名</h3>
                </div>
                <div class="card-body">
                  <dl class="row mb-0">
                    <dt class="col-8" data-i18n="page.mapSync.occupantDb">この Website が持っている件数</dt>
                    <dd class="col-4 text-end"><?= (int) $occupantsInDb ?></dd>
                    <dt class="col-8" data-i18n="page.mapSync.occupantApp">上げた地図が持っている件数</dt>
                    <dd class="col-4 text-end"><?= (int) $occupantsInApp ?></dd>
                  </dl>

                  <?php if (($diff['occupantCleared'] ?? []) !== []): ?>
                    <?php
                    /*
                     * **これが一番危ない。** 上げた地図にその地点は在るのに
                     * 氏名だけ入っていない —— 来場者向けに氏名を抜いた書き出しや、
                     * 氏名を移す前の古い書き出しを取り込むとこうなる。
                     * 既定では守るので、ここは「守った件数」として出る。
                     */
                    ?>
                    <div class="alert alert-warning mt-3 mb-0" role="alert">
                      <strong data-i18n="page.mapSync.clearedTitle">
                        上げた地図に氏名が入っていない地点があります。
                      </strong>
                      <span><?= count($diff['occupantCleared']) ?> 件</span>
                      <div class="fs-7 mt-1" data-i18n="page.mapSync.clearedHint">
                        既定ではいまの氏名を残します。下で「氏名も上書きする」を選ぶと消えます。
                      </div>
                      <details class="mt-2">
                        <summary class="fs-7" data-i18n="page.mapSync.clearedList">対象の地点</summary>
                        <ul class="mt-2 fs-7">
                          <?php foreach ($diff['occupantCleared'] as $name): ?>
                            <li><?= km_e((string) $name) ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </details>
                    </div>
                  <?php endif; ?>

                  <?php if (($diff['occupantLost'] ?? []) !== []): ?>
                    <?php
                    /*
                     * **消すときだけ失われる。** 残す設定(既定)なら、
                     * 上げた地図に無い地点はそのまま残るので氏名も消えない。
                     */
                    ?>
                    <details class="mt-3">
                      <summary class="text-danger" data-i18n="page.mapSync.lostList">
                        「無いものを消す」を選ぶと失われる氏名の地点
                      </summary>
                      <ul class="mt-2 fs-7">
                        <?php foreach ($diff['occupantLost'] as $name): ?>
                          <li><?= km_e((string) $name) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </details>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card mb-4">
                <div class="card-body">
                  <?php // 確認は partials/scripts.php が data-km-confirm を見て出す(onsubmit= は CSP で動かない) ?>
                  <form method="post"
                        data-km-confirm="上げた地図をこの Website へ取り込みます。よろしいですか?">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="do_sync" value="apply">

                    <div class="form-check mb-3">
                      <input class="form-check-input" type="checkbox" value="1"
                             id="remove_missing" name="remove_missing">
                      <label class="form-check-label" for="remove_missing"
                             data-i18n="page.mapSync.removeMissing">
                        上げた地図に無い地点と経路を消す
                      </label>
                      <div class="form-text" data-i18n="page.mapSync.removeMissingHint">
                        既定では消しません。Website 側だけで作った地点があると、
                        これを選んだ時点で一緒に消えます。
                      </div>
                    </div>

                    <div class="form-check mb-3">
                      <input class="form-check-input" type="checkbox" value="1"
                             id="overwrite_occupant" name="overwrite_occupant">
                      <label class="form-check-label" for="overwrite_occupant"
                             data-i18n="page.mapSync.overwriteOccupant">
                        教職員氏名も上書きする
                      </label>
                      <div class="form-text" data-i18n="page.mapSync.overwriteOccupantHint">
                        既定では、上げた地図に氏名が無い地点はいまの氏名を残します。
                        アプリ側で氏名を消したことを反映したいときだけ選んでください。
                      </div>
                    </div>

                    <button type="submit" class="btn btn-danger">
                      <span data-i18n="page.mapSync.applyButton">取り込む</span>
                    </button>
                    <span class="form-text ms-2" data-i18n="page.mapSync.applyHint">
                      元には戻せません。先に差分の数を確かめてください。
                    </span>
                  </form>
                </div>
              </div>

              <form method="post" class="mb-4">
                <?= km_csrf_field() ?>
                <input type="hidden" name="do_sync" value="discard">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                  <span data-i18n="page.mapSync.discardButton">取り込まずに捨てる</span>
                </button>
              </form>
            <?php endif; ?>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
