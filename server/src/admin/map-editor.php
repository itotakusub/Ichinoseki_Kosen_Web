<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/map-edit.php';

/*
 * 地図編集ツール。
 *
 * 旧実装は公開ページ(index.php)の中に編集パネルを置き、$_SESSION['is_admin'] で
 * 出し分けていた。今回は**管理画面側に置く**:
 *   - index.php の Logto チェックは認証しか見ておらず(isAuthenticated() だけ)、
 *     admin かどうかの判定をしていない。しかも意図的に fail open にしてある
 *   - admin/ なら guard.php(fail closed)・CSRF・監査ログが既に揃っている
 *   - 公開ページを閲覧専用のまま保てる
 *
 * 地図の描画は公開ページと同じ Main/app.js をそのまま使う(二重に持たない)。
 * app.js の画像パスはルート相対に直してあるので、/admin/ から読んでも解決できる。
 */

require_once dirname(__DIR__) . '/lib/map-events.php';
require_once dirname(__DIR__) . '/lib/map-data.php';
// 氏名を伏せている間は編集させない、の判定に使う
require_once dirname(__DIR__) . '/lib/map-access.php';

$floors = [];
$nodeCount = 0;
$dbError = null;

/*
 * ---- イベント編集モード ----
 *
 * `?event=<id>` が付いていると、そのイベントの重ね合わせ(通行止め・臨時の地点・
 * 臨時名称)を編集する。**恒久データの編集とは別の操作**で、同じキャンバスを使い回す。
 *
 * 地図データはここで HTML に同梱する。公開ページと違って app.js は
 * /api/map-data.php を取りに行く作りだが、**プレビューは有効化前のイベントも
 * 見えないと意味が無い**。API 側にプレビュー用の入口を作ると公開面が広がるので、
 * 管理画面から埋め込む形にした。
 */
$kmEditEvent = null;
$inlineMapData = null;

try {
    $pdo = km_db();
    $floors = $pdo->query('SELECT id, label FROM km_map_floors ORDER BY sort_order')->fetchAll();
    $nodeCount = (int) $pdo->query('SELECT COUNT(*) FROM km_map_nodes')->fetchColumn();

    if (isset($_GET['event']) && $_GET['event'] !== '') {
        $kmEditEvent = km_map_event_find($pdo, (int) $_GET['event']);
    }

    $inlineMapData = json_encode(
        km_map_data(
            $pdo,
            $kmEditEvent !== null ? (int) $kmEditEvent['id'] : null,
            // **地図の錠を素通りする。** ここは guard.php の内側 = 管理者だと確かめ済み。
            // 「パスワードが必要」に設定していても、編集できなくなっては困る。
            forAdmin: true
        ),
        // JSON_HEX_TAG を外さないこと(index.php と同じ理由。</script> を作らせない)
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    error_log('map-editor.php failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

$KM_PAGE = [
    'nav' => 'mapEditor',
    'titleKey' => 'page.mapEditor.title',
    'title' => '地図編集 | KosenMap 管理',
    'h1Key' => 'page.mapEditor.h1',
    'h1' => '地図編集',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.mapEditor.h1', 'text' => '地図編集'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
?>
    <!-- 地図の見た目は公開ページと共通のものを使う(ルート相対で読む) -->
    <link rel="stylesheet" href="/vendor-web/leaflet/leaflet.css" />
    <link rel="stylesheet" href="/Main/styles.css" />
    <style<?= km_csp_nonce_attr() ?>>
      /* 管理画面のレイアウトの中に地図を収める。Main/styles.css は全画面前提なので、
         ここでカードの中に閉じ込める分だけ上書きする(Main/styles.css 自体は触らない)。 */
      #km-map-editor-wrap { position: relative; height: 60vh; min-height: 420px; }
      #km-map-editor-wrap #map { position: absolute; inset: 0; width: 100%; height: 100%; }
      #km-map-editor-wrap.km-editing #map { cursor: crosshair; }
    </style>
<?php
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <?php
            /*
             * **ここが正本になった(2026-09-03)。**
             *
             * それまでは管理アプリが正本で、この画面での編集は次の同期で
             * 上書きされていた。利用者の指示で向きを逆にし、
             * 取り込みも**上げた地図に無いものは消さない**形に変えたので、
             * ここで足したものが黙って消えることは無くなった。
             *
             * **配信しないと端末には届かない。** そこだけは言っておく ——
             * 「直したのにアプリで変わらない」が次に起きる勘違いなので。
             */
            ?>
            <div class="alert alert-info" role="alert">
              <strong data-i18n="page.mapEditor.syncWarning">地点と経路の正本はこの Website です。</strong>
              <span data-i18n="page.mapEditor.syncWarningText">
                ここでの編集は、「アプリへ地図を配信する」で配信したときにアプリへ届きます。
                アプリ側で直したものを取り込むときは「アプリの地図を取り込む」から。
                取り込みでは、上げた地図に無い地点を既定では消しません。
              </span>
            </div>
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.mapEditor.dbError">
                データベースに接続できないため、地図を編集できません。
              </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.mapEditor.notice">
                変更は操作するたびにすぐ保存されます(元に戻す機能はありません)。
                削除は確認を挟みます。すべての操作は「タイムライン」に記録されます。
              </div>
            </div>

            <?php if ($kmEditEvent !== null): ?>
              <!--
                いまどのイベントを編集しているのかを、常に見えるところに出す。
                恒久データの編集と同じ画面なので、**取り違えると地図の正本を壊す**。
              -->
              <div class="alert alert-warning d-flex align-items-center" role="alert">
                <i class="bi bi-cone-striped me-2" aria-hidden="true"></i>
                <div class="flex-grow-1">
                  <strong>イベント「<?= km_e($kmEditEvent['name']) ?>」の重ね合わせを編集しています。</strong>
                  <div class="fs-7" data-i18n="page.mapEditor.eventNotice">
                    ここでの通行止め・臨時の地点・臨時の名前は、このイベント専用です。
                    地図そのもの(場所や経路)は変わりません。
                  </div>
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="./map-events.php"
                   data-i18n="page.mapEditor.eventBack">イベント一覧へ</a>
                <a class="btn btn-sm btn-outline-secondary ms-2" href="./map-editor.php"
                   data-i18n="page.mapEditor.eventExit">通常の編集に戻る</a>
              </div>
            <?php endif; ?>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-3">
                <!--begin::Tools-->
                <div class="card mb-3">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.mapEditor.toolsTitle">モード</h3>
                  </div>
                  <div class="card-body d-grid gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm km-mode active" data-km-mode="none">
                      <i class="bi bi-hand-index me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modeNone">選択(編集しない)</span>
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm km-mode" data-km-mode="node">
                      <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modeNode">場所を追加</span>
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm km-mode" data-km-mode="point">
                      <i class="bi bi-circle me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modePoint">通過点を追加</span>
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm km-mode" data-km-mode="move">
                      <i class="bi bi-arrows-move me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modeMove">移動</span>
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm km-mode" data-km-mode="edit">
                      <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modeEdit">編集</span>
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm km-mode" data-km-mode="edge">
                      <i class="bi bi-share me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modeEdge">経路をつなぐ</span>
                    </button>
                    <button type="button" class="btn btn-outline-warning btn-sm km-mode" data-km-mode="align">
                      <i class="bi bi-rulers me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modeAlign">整列</span>
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm km-mode" data-km-mode="delete">
                      <i class="bi bi-trash me-1" aria-hidden="true"></i>
                      <span data-i18n="page.mapEditor.modeDelete">削除</span>
                    </button>

                    <?php if ($kmEditEvent !== null): ?>
                      <hr class="my-1">
                      <div class="fs-7 text-body-secondary" data-i18n="page.mapEditor.eventGroup">
                        イベントの重ね合わせ
                      </div>
                      <button type="button" class="btn btn-outline-danger btn-sm km-mode" data-km-mode="event-closure">
                        <i class="bi bi-cone-striped me-1" aria-hidden="true"></i>
                        <span data-i18n="page.mapEditor.modeEventClosure">通行止め</span>
                      </button>
                      <button type="button" class="btn btn-outline-warning btn-sm km-mode" data-km-mode="event-poi">
                        <i class="bi bi-shop me-1" aria-hidden="true"></i>
                        <span data-i18n="page.mapEditor.modeEventPoi">臨時の地点</span>
                      </button>
                      <button type="button" class="btn btn-outline-warning btn-sm km-mode" data-km-mode="event-alias">
                        <i class="bi bi-tag me-1" aria-hidden="true"></i>
                        <span data-i18n="page.mapEditor.modeEventAlias">臨時の名前</span>
                      </button>
                    <?php endif; ?>
                  </div>
                  <div class="card-footer">
                    <p id="km-editor-hint" class="fs-7 mb-2 text-body-secondary" data-i18n="page.mapEditor.hintNone">
                      モードを選んでください。
                    </p>
                    <div id="km-align-controls" class="d-none">
                      <button type="button" id="km-align-exec" class="btn btn-warning btn-sm w-100" data-i18n="page.mapEditor.alignExec">
                        選んだ点を整列する
                      </button>
                    </div>
                  </div>
                </div>
                <!--end::Tools-->

                <div class="card">
                  <div class="card-body fs-7">
                    <div>
                      <span data-i18n="page.mapEditor.nodeCount">登録済みの地点</span>:
                      <strong id="km-node-count"><?= (int) $nodeCount ?></strong>
                    </div>
                    <div id="km-editor-status" class="mt-2 text-body-secondary"></div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-xl-9">
                <!--begin::Map-->
                <div class="card">
                  <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h3 class="card-title mb-0" data-i18n="page.mapEditor.mapTitle">地図</h3>
                    <div class="card-tools ms-auto btn-group btn-group-sm" id="km-floor-buttons">
                      <?php foreach ($floors as $i => $floor): ?>
                        <button
                          type="button"
                          class="btn btn-outline-secondary floor-btn<?= (string) $floor['id'] === '1' ? ' active' : '' ?>"
                          data-floor="<?= km_e((string) $floor['id']) ?>"
                        ><?= km_e((string) $floor['label']) ?></button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div class="card-body p-0">
                    <div id="km-map-editor-wrap">
                      <div id="map"></div>
                    </div>
                  </div>
                  <div class="card-footer fs-7 text-body-secondary" data-i18n="page.mapEditor.mapFooter">
                    公開ページと同じ描画を使っています。ここでの変更はそのまま公開側に反映されます。
                  </div>
                </div>
                <!--end::Map-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <!--begin::Node Edit Modal-->
        <div class="modal fade" id="km-node-modal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" data-i18n="page.mapEditor.editTitle">地点を編集</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる" data-i18n-attr="aria-label:common.close"></button>
              </div>
              <div class="modal-body">
                <input type="hidden" id="km-node-id" value="" />
                <?php // 教職員の地点(docs/15 段 G)に割り当てるとき、ここから写す ?>
                <div class="mb-3">
                  <label class="form-label" for="km-node-uid" data-i18n="page.mapEditor.fieldNodeId">地点の ID</label>
                  <input type="text" class="form-control form-control-sm font-monospace" id="km-node-uid" readonly />
                  <div class="form-text" data-i18n="page.mapEditor.nodeIdHint">「教職員の地点」で割り当てるときに貼ります。</div>
                </div>
                <?php
                /*
                 * **名前を2つに分ける。** アプリと同じ持ち方(`title` / `subtitle`)。
                 * 繋いだ1本の文字列からは分かれ目を戻せない ——
                 * 部屋名に空白が入っていると、どこで切れるか決められない。
                 */
                ?>
                <div class="mb-3">
                  <label class="form-label" for="km-node-title" data-i18n="page.mapEditor.fieldName">名前</label>
                  <input type="text" class="form-control" id="km-node-title" maxlength="255" />
                </div>
                <div class="mb-3">
                  <label class="form-label" for="km-node-subtitle" data-i18n="page.mapEditor.fieldSubtitle">部屋番号など</label>
                  <input type="text" class="form-control" id="km-node-subtitle" maxlength="64" />
                  <div class="form-text" data-i18n="page.mapEditor.subtitleHint">
                    「管-104」のような番号。地図には名前と続けて出ます。
                  </div>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="km-node-type" data-i18n="page.mapEditor.fieldType">種類</label>
                  <select class="form-select" id="km-node-type">
                    <?php foreach (KM_MAP_NODE_TYPES as $type): ?>
                      <option value="<?= km_e($type) ?>"><?= km_e($type) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-0">
                  <label class="form-label" for="km-node-occupant" data-i18n="page.mapEditor.fieldOccupant">教職員氏名</label>
                  <?php
                  /*
                   * 2026-09-03 に**編集できるように戻した。** 正本が Website になったため。
                   *
                   * ただし**伏せているときは触らせない。** 以前は「読み込んだ値を
                   * そのまま送り返す」作りで、氏名を伏せた状態(mode = hidden)で
                   * 地点名を直しただけで**氏名が空で上書きされていた。**
                   * 読めない値を書き戻させない、という形でその経路を塞ぐ。
                   *
                   * 送るのも別の操作(`node.occupant`)にしてある ——
                   * 名前や種類と一緒に送れる形にしない。
                   */
                  $occupantEditable = km_map_access_config()['mode'] !== 'hidden';
                  ?>
                  <input type="text" class="form-control" id="km-node-occupant" maxlength="255"
                         <?= $occupantEditable ? '' : 'readonly' ?> />
                  <?php if ($occupantEditable): ?>
                    <div class="form-text" data-i18n="page.mapEditor.occupantHint">
                      公開するかどうかは「地図データ公開設定」で決まります。
                    </div>
                  <?php else: ?>
                    <div class="form-text" data-i18n="page.mapEditor.occupantLocked">
                      「地図データ公開設定」で氏名を伏せている間は編集できません。
                      読めない値を送り返して消してしまわないためです。
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="common.cancel">キャンセル</button>
                <button type="button" class="btn btn-primary" id="km-node-save" data-i18n="common.save">保存</button>
              </div>
            </div>
          </div>
        </div>
        <!--end::Node Edit Modal-->

        <!-- 地図の描画は公開ページと共通。編集の上乗せは map-editor.js が行う -->
        <script src="/vendor-web/leaflet/leaflet.js"></script>
        <?php if ($inlineMapData !== null): ?>
          <?php /* app.js は #km-map-data があればそれを使う。プレビュー(未有効のイベント)も
                   ここに含まれるので、API 側にプレビュー用の入口を作らずに済む */ ?>
          <script type="application/json" id="km-map-data"<?= km_csp_nonce_attr() ?>><?= $inlineMapData ?></script>
        <?php endif; ?>
        <script<?= km_csp_nonce_attr() ?>>
          // app.js は window.isAdmin を「通過点も描く / 座標つきツールチップを出す」の
          // 判定に使う。ここでは編集したいので true にするが、**保存の可否はサーバーが
          // 決める**(admin/api/map-edit.php は guard.php の内側にある)。
          window.isAdmin = true;
          // イベント編集モードのときだけ入る。map-editor.js がこれを見て操作を切り替える
          window.KM_EDIT_EVENT = <?= $kmEditEvent === null
              ? 'null'
              : json_encode(['id' => (int) $kmEditEvent['id'], 'name' => $kmEditEvent['name']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        </script>
        <script src="/Main/dijkstra.js"></script>
        <?php /* app.js が起動時に読む。**app.js より先に置く** */ ?>
        <script src="/Main/map-style.js"></script>
        <script src="/Main/app.js"></script>
        <script src="<?= km_e(km_asset('./assets/js/map-editor.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
