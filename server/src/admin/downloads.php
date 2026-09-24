<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/distributables.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/app-map.php';   // 配信中の地図の期限
require_once dirname(__DIR__) . '/lib/user-error.php';

$KM_PAGE = [
    'nav' => 'downloads',
    'titleKey' => 'page.downloads.title',
    'title' => 'ダウンロード | KosenMap 管理',
    'h1Key' => 'page.downloads.h1',
    'h1' => 'ダウンロード',
    'crumbs' => [
        ['key' => 'page.downloads.h1', 'text' => 'ダウンロード'],
    ],
];

$errors = [];
$notice = null;

/*
 * 配信中の地図の期限。
 *
 * **切れたことが、これまでどこにも出ていなかった。** 2026-08-27 に失効したのに
 * 気づかれず、記録文書にだけ残り続けた。期限を短く保つのは正しい(会期が終わった
 * 地図を配り続けない)ので、**切れる前に気づける場所**をここに置く。
 *
 * 設定ファイルを読むだけなので DB は要らない。失敗しても画面は出す。
 */
$mapReleases = [];
try {
    $mapReleases = km_app_map_release_report(km_app_map_config());
} catch (Throwable $exception) {
    error_log('downloads.php map release status failed: ' . $exception->getMessage());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    // 副作用を起こす前に弾く
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['map_action'])) {
    /*
     * 配信の停止 / 再開 / 削除。
     *
     * **DB は使わない。** 設定ファイルと地図の実体だけで完結するので、
     * DB が落ちていても止められる —— 止めたいのは大抵、何かがおかしいとき。
     */
    $mapAction = (string) $_POST['map_action'];
    $mapSlug = (string) ($_POST['map_slug'] ?? '');

    try {
        $config = km_app_map_config();

        if ($mapAction === 'pause' || $mapAction === 'resume') {
            $paused = $mapAction === 'pause';
            km_app_map_write_config(km_app_map_set_paused($config, $mapSlug, $paused));
            km_admin_log_record('content', $paused ? 'map.pause' : 'map.resume', $mapSlug);
            header('Location: ./downloads.php?' . ($paused ? 'paused' : 'resumed') . '=1', true, 302);
            exit;
        }

        if ($mapAction === 'delete') {
            /*
             * **取り返しがつかないので、配信 ID を打たせる。**
             * チェックボックスだと、押し慣れた場所を反射で押して消える。
             * 打つ手間があると、少なくとも「どれを消すのか」は読むことになる。
             */
            if (trim((string) ($_POST['confirm_slug'] ?? '')) !== $mapSlug) {
                $errors[] = '確認のため、配信 ID をそのまま入力してください。';
            } else {
                $removed = km_app_map_remove($config, $mapSlug);
                // **設定を先に書く。** ファイルを先に消すと、書き込みに失敗したとき
                // 「設定は残っているのに実体が無い」状態になる
                km_app_map_write_config($removed['config']);

                $deletedFile = false;
                if ($removed['fileName'] !== null) {
                    $path = km_app_map_storage_path($config, $removed['fileName']);
                    if ($path !== null) {
                        $deletedFile = @unlink($path);
                    }
                }
                km_admin_log_record(
                    'content',
                    'map.delete',
                    $mapSlug . ' コード=' . $removed['removedCodes'] . ' 実体=' . ($deletedFile ? '削除' : '無し')
                );
                header('Location: ./downloads.php?mapdeleted=1', true, 302);
                exit;
            }
        }
    } catch (Throwable $exception) {
        error_log('downloads.php map ' . $mapAction . ' failed: ' . $exception->getMessage());
        /*
         * **理由をそのまま出す。**「失敗しました」だけでは打つ手が分からない。
         * ただし出すのは利用者向けに書いた文だけ(KmUserError と入力の検証)。
         * 書き込みの失敗は lib/app-map.php が打つ手を KmUserError で添える(2026-09-14)。
         */
        $errors[] = km_admin_error_message($exception, '配信の設定を変えられませんでした。');
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $slug = (string) ($_POST['slug'] ?? '');
    $action = isset($_POST['do_delete']) ? 'delete' : 'upload';

    try {
        $pdo = km_db();

        if ($action === 'delete') {
            km_dist_delete($pdo, $slug);
            km_admin_log_record('content', 'dist.delete', $slug);
            header('Location: ./downloads.php?deleted=1', true, 302);
            exit;
        }

        km_dist_store(
            $pdo,
            $slug,
            $_FILES['file'] ?? [],
            (string) ($_POST['version_label'] ?? ''),
            $KM_USER['name'] ?? null
        );
        km_admin_log_record('content', 'dist.update', $slug);
        header('Location: ./downloads.php?uploaded=1', true, 302);
        exit;
    } catch (Throwable $exception) {
        error_log('downloads.php ' . $action . ' failed: ' . $exception->getMessage());
        // 例外の文をそのまま出さない。利用者向けの文は KmUserError / InvalidArgumentException で届く
        $errors[] = km_admin_error_message($exception, '配布ファイルを保存できませんでした。');
    }
}

foreach ([
    'uploaded' => 'uploadedNotice',
    'deleted' => 'deletedNotice',
    'paused' => 'mapPausedNotice',
    'resumed' => 'mapResumedNotice',
    'mapdeleted' => 'mapDeletedNotice',
] as $param => $key) {
    if (isset($_GET[$param])) {
        $notice = $key;
    }
}

$dist = [];
try {
    $dist = km_dist_all(km_db());
} catch (Throwable $exception) {
    error_log('downloads.php failed: ' . $exception->getMessage());
    $errors[] = 'データベースに接続できないため、配布物の状態を読み込めません。';
}

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert" data-i18n="page.downloads.<?= km_e($notice) ?>">
                保存しました。
              </div>
            <?php endif; ?>

            <?php
              /**
               * 「差し替え」フォームを1枚描く。実体の有無で「差し替え」か「新規に置く」かの
               * 文言だけが変わる。
               *
               * いま使うのは APK の1枚だけ(証明書のカードは 1.0.5 で外した)。
               * それでも関数のまま残す —— 配布物が増えたときに、同じ形をもう一度書かせない。
               */
              $renderUploadForm = static function (string $slug, ?array $row): void {
                  $spec = KM_DISTRIBUTABLES[$slug];
                  ?>
                  <form method="post" enctype="multipart/form-data" class="border-top pt-3 mt-3">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="slug" value="<?= km_e($slug) ?>" />
                    <div class="mb-2">
                      <label class="form-label fs-7" for="km-dist-<?= km_e($slug) ?>">
                        <span data-i18n="page.downloads.replaceField">差し替えるファイル</span>
                        <span class="text-body-secondary">
                          (<?= km_e(implode(' / ', $spec['extensions'])) ?>,
                          <?= km_e(km_upload_format_size($spec['maxBytes'])) ?>
                          <span data-i18n="page.downloads.upTo">まで</span>)
                        </span>
                      </label>
                      <input
                        type="file"
                        class="form-control form-control-sm"
                        id="km-dist-<?= km_e($slug) ?>"
                        name="file"
                        required
                      />
                    </div>
                    <div class="mb-2">
                      <label class="form-label fs-7" for="km-ver-<?= km_e($slug) ?>" data-i18n="page.downloads.versionField">
                        バージョン(任意)
                      </label>
                      <input
                        type="text"
                        class="form-control form-control-sm"
                        id="km-ver-<?= km_e($slug) ?>"
                        name="version_label"
                        maxlength="64"
                        value="<?= km_e((string) ($row['versionLabel'] ?? '')) ?>"
                      />
                    </div>
                    <div class="d-flex gap-2">
                      <button type="submit" class="btn btn-outline-primary btn-sm" data-i18n="page.downloads.replaceButton">
                        差し替える
                      </button>
                      <?php if ($row !== null): ?>
                        <?php // 既定へ戻すだけなので、必須の file 入力に引っかからないよう検証を外す ?>
                        <button
                          type="submit"
                          name="do_delete"
                          value="1"
                          class="btn btn-outline-secondary btn-sm ms-auto"
                          formnovalidate
                          data-i18n="page.downloads.revertButton"
                        >登録を取り消す</button>
                      <?php endif; ?>
                    </div>
                  </form>
                  <?php
              };

              /** 現在登録されているものの説明行(いつ・誰が・どのファイル)。 */
              $renderCurrent = static function (?array $row): void {
                  if ($row === null) {
                      return;
                  }
                  ?>
                  <p class="text-body-secondary fs-7 mb-0">
                    <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>
                    <?php // ファイル名とバージョンは利用者が入れた値なので data-i18n は付けない ?>
                    <?= km_e((string) $row['originalName']) ?>
                    <?php if (($row['versionLabel'] ?? null) !== null): ?>
                      (<?= km_e((string) $row['versionLabel']) ?>)
                    <?php endif; ?>
                    / <?= km_e(km_upload_format_size((int) $row['sizeBytes'])) ?>
                    / <?= km_e(date('Y-m-d H:i', (int) $row['updatedAtEpoch'])) ?>
                    <?php if (($row['updatedBy'] ?? null) !== null): ?>
                      / <?= km_e((string) $row['updatedBy']) ?>
                    <?php endif; ?>
                  </p>
                  <?php
              };
            ?>

            <?php
            /*
             * ここにあった「ローカル CA 証明書」のカードは 1.0.5 で外した。
             * 理由は index.php の同じ箇所を参照(本番は Let's Encrypt なので配る意味が無く、
             * 自前の CA を入れさせる導線を残す方が危ない)。
             *
             * **`certs/rootCA.pem` は消していない。** MariaDB の内部 TLS が同じ CA を使う。
             */
            ?>
            <?php if ($mapReleases !== []): ?>
              <!--begin::MapRelease-->
              <div class="card mb-4">
                <div class="card-header">
                  <h3 class="card-title" data-i18n="page.downloads.mapReleaseTitle">アプリへ配信中の地図</h3>
                </div>
                <!-- /.card-header -->
                <div class="card-body">
                  <p class="text-body-secondary fs-7" data-i18n="page.downloads.mapReleaseHint">
                    期限を過ぎると、来場者アプリは地図とアクセスコードを削除します。
                    会期に合わせて短く保つのが正しい運用なので、切れる前に更新してください。
                  </p>
                  <?php foreach ($mapReleases as $release): ?>
                    <?php
                      $status = $release['status'] ?? null;
                      $file = $release['file'];
                      $summary = $release['summary'];
                      $event = $summary['activeEvent'];
                      $fmt = static fn (?string $iso): string =>
                          $iso === null ? '—' : date('Y-m-d H:i', (int) strtotime($iso));
                    ?>
                    <div class="border rounded p-3 mb-3">
                      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <code class="fs-6"><?= km_e($release['slug']) ?></code>
                        <?php
                        /*
                         * **どちらの正本かを並べて出す**(2026-09-14 に2つに分けた)。
                         * 止める・消すのは配信 ID ごとなので、取り違えるとイベント中に Website の地図を止める。
                         */
                        ?>
                        <?php if (($release['role'] ?? '') === 'main'): ?>
                          <span class="badge text-bg-primary" data-i18n="page.downloads.mapReleaseRoleMain">Website の正本</span>
                        <?php elseif (($release['role'] ?? '') === 'event'): ?>
                          <span class="badge text-bg-info" data-i18n="page.downloads.mapReleaseRoleEvent">イベント用の正本</span>
                        <?php endif; ?>
                        <span class="badge text-bg-secondary">
                          <span data-i18n="page.downloads.mapReleaseRevision">版</span>
                          <?= (int) ($status['revision'] ?? 0) ?>
                        </span>
                        <?php if ($release['paused']): ?>
                          <?php
                          /*
                           * **止まっていることを、期限より先に言う。**
                           * 期限が残っていても配られないので、「配信中(あと26日)」と
                           * 出ていたら**止めたつもりの人が騙される。**
                           */
                          ?>
                          <span class="badge text-bg-dark" data-i18n="page.downloads.mapReleasePaused">
                            配信を停止中
                          </span>
                        <?php elseif ($status === null || $status['unknown']): ?>
                          <?php // 読めない値を「大丈夫」と扱わない。配信が止まっていても画面が無言になる ?>
                          <span class="badge text-bg-danger" data-i18n="page.downloads.mapReleaseUnknown">
                            期限を読めません
                          </span>
                        <?php elseif ($status['expired']): ?>
                          <span class="badge text-bg-danger" data-i18n="page.downloads.mapReleaseExpired">
                            期限切れ
                          </span>
                        <?php elseif ($status['warn']): ?>
                          <span class="badge text-bg-warning">
                            <span data-i18n="page.downloads.mapReleaseSoon">まもなく期限</span>
                            (<?= (int) $status['daysLeft'] ?>)
                          </span>
                        <?php else: ?>
                          <span class="badge text-bg-success">
                            <span data-i18n="page.downloads.mapReleaseOk">配信中</span>
                            (<?= (int) $status['daysLeft'] ?>)
                          </span>
                        <?php endif; ?>
                      </div>

                      <div class="row g-3">
                        <?php
                        /*
                         * **設定と実体を並べて出す。** 片方だけ見せると、いま疑っている
                         * 「設定は新しいのに中身は古い」を見逃す。
                         */
                        ?>
                        <div class="col-12 col-lg-4">
                          <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseConfig">配信の設定</p>
                          <dl class="row fs-7 mb-0">
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseExpires">期限</dt>
                            <dd class="col-7 mb-1"><?= km_e($fmt($status['expiresAt'] ?? null)) ?></dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseChecksum">チェックサム</dt>
                            <dd class="col-7 mb-1">
                              <?php if ($release['checksumEnabled']): ?>
                                <span data-i18n="page.downloads.mapReleaseOn">あり</span>
                              <?php else: ?>
                                <span class="text-warning-emphasis" data-i18n="page.downloads.mapReleaseOff">なし</span>
                              <?php endif; ?>
                            </dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseCodes">アクセスコード</dt>
                            <dd class="col-7 mb-0">
                              <?php if ($release['codeCount'] === 0): ?>
                                <?php // コードが無いと、地図はあっても誰も入れられない ?>
                                <span class="text-danger-emphasis">
                                  0 (<span data-i18n="page.downloads.mapReleaseNoCode">誰も入れません</span>)
                                </span>
                              <?php elseif ($release['code'] === null): ?>
                                <?php
                                /*
                                 * 古い設定には平文が入っていない。**「無い」と言い切る。**
                                 * ここを空欄にすると「読めていないのか、設定が古いのか」が
                                 * 分からず、コードを探しに行く先も分からない。
                                 */
                                ?>
                                <?= (int) $release['codeCount'] ?>
                                <span class="text-body-secondary fs-7 d-block" data-i18n="page.downloads.mapReleaseCodeUnsaved">
                                  コードは保存されていません(次回のリリースから表示できます)
                                </span>
                              <?php else: ?>
                                <?php
                                /*
                                 * **既定では伏せる。** この画面は会期中に人に見せながら
                                 * 開くことがある(配信の状態を確かめる場面)。
                                 * コードは来場者へ配る前提の共有情報だが、
                                 * 出しっぱなしにしてよい理由にはならない。
                                 *
                                 * 伏せ字と実物の**両方を最初から置いて、表示を切り替える**。
                                 * 押したときに取りに行く作りにすると、通信が要るうえ
                                 * 「押しても出ない」場面が増える。
                                 */
                                ?>
                                <span class="km-code" data-km-code-mask>
                                  <?= km_e(str_repeat('•', mb_strlen($release['code']))) ?>
                                </span>
                                <code class="km-code" data-km-code-value hidden><?= km_e($release['code']) ?></code>
                                <?php
                                /*
                                 * ボタンの文字は**2つの span を入れ替える**。
                                 * JS で textContent を書き換えると、翻訳が当たったあとに
                                 * 日本語で上書きしてしまう(英語表示で押すと日本語に化ける)。
                                 * span ごとに data-i18n を持たせれば、どちらの言語でも成立する。
                                 */
                                ?>
                                <button
                                  type="button"
                                  class="btn btn-link btn-sm p-0 ms-1 align-baseline"
                                  data-km-code-toggle
                                >
                                  <span data-km-code-label-show data-i18n="page.downloads.mapReleaseCodeShow">表示</span>
                                  <span data-km-code-label-hide data-i18n="page.downloads.mapReleaseCodeHide" hidden>隠す</span>
                                </button>
                                <?php if ($release['codeCount'] > 1): ?>
                                  <span class="text-body-secondary fs-7 d-block">
                                    <span data-i18n="page.downloads.mapReleaseCodeMany">この配信には複数のコードがあります</span>
                                    (<?= (int) $release['codeCount'] ?>)
                                  </span>
                                <?php endif; ?>
                              <?php endif; ?>
                            </dd>
                          </dl>
                        </div>

                        <div class="col-12 col-lg-4">
                          <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseFile">置いてあるファイル</p>
                          <dl class="row fs-7 mb-0">
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileName">ファイル</dt>
                            <dd class="col-7 mb-1 text-break"><?= km_e($file['fileName']) ?></dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileSize">大きさ</dt>
                            <dd class="col-7 mb-1">
                              <?= $file['sizeBytes'] === null ? '—' : km_e(km_upload_format_size((int) $file['sizeBytes'])) ?>
                            </dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileTime">更新</dt>
                            <dd class="col-7 mb-1"><?= km_e($fmt($file['modifiedAt'])) ?></dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileHash">SHA-256</dt>
                            <dd class="col-7 mb-0">
                              <?php if ($file['sha256'] === null): ?>
                                —
                              <?php else: ?>
                                <code class="fs-7"><?= km_e(substr($file['sha256'], 0, 16)) ?></code>
                              <?php endif; ?>
                            </dd>
                          </dl>
                          <?php if ($file['error'] !== null): ?>
                            <p class="text-danger-emphasis fs-7 mb-0 mt-1"><?= km_e($file['error']) ?></p>
                          <?php endif; ?>
                        </div>

                        <div class="col-12 col-lg-4">
                          <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseContent">中身</p>
                          <?php if (!$summary['readable']): ?>
                            <p class="text-danger-emphasis fs-7 mb-0" data-i18n="page.downloads.mapReleaseUnreadable">
                              読めないため、中身を確かめられません。
                            </p>
                          <?php else: ?>
                            <dl class="row fs-7 mb-0">
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseNodes">地点</dt>
                              <dd class="col-5 mb-1"><?= (int) $summary['nodeCount'] ?></dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseLines">経路</dt>
                              <dd class="col-5 mb-1"><?= (int) $summary['lineCount'] ?></dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseOccupants">教職員氏名</dt>
                              <dd class="col-5 mb-1">
                                <?php // 0 は「氏名が失われた」合図。配信では取り除かれるが、原本には入っている ?>
                                <span class="<?= $summary['occupantCount'] === 0 ? 'text-warning-emphasis' : '' ?>">
                                  <?= (int) $summary['occupantCount'] ?>
                                </span>
                              </dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFingerprints">Wi-Fi 指紋</dt>
                              <dd class="col-5 mb-1"><?= (int) $summary['fingerprintCount'] ?></dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseMapVersion">地図の版</dt>
                              <dd class="col-5 mb-0"><?= $summary['mapVersion'] === null ? '—' : (int) $summary['mapVersion'] ?></dd>
                            </dl>
                          <?php endif; ?>
                        </div>
                      </div>

                      <?php
                      /*
                       * 載せているイベント。**「終わったイベントが載ったまま」を見つけるためのもの。**
                       * アプリ側は期間外を黙って無効にするので、設定した側からは
                       * 「効いていない」のか「終わっている」のか分からない。
                       */
                      ?>
                      <div class="border-top mt-3 pt-3">
                        <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseEvent">配信に載せているイベント</p>
                        <?php if ($event === null): ?>
                          <p class="text-body-secondary fs-7 mb-0">
                            <span data-i18n="page.downloads.mapReleaseNoEvent">指定していません</span>
                            (<span data-i18n="page.downloads.mapReleaseEventCount">地図が持つイベント</span>:
                            <?= (int) $summary['eventCount'] ?>)
                          </p>
                        <?php elseif ($event['missing']): ?>
                          <p class="text-danger-emphasis fs-7 mb-0">
                            <span data-i18n="page.downloads.mapReleaseEventMissing">
                              指定された UUID のイベントが、配信中の地図にありません。
                            </span>
                            <code><?= km_e($event['uuid']) ?></code>
                          </p>
                        <?php else: ?>
                          <p class="fs-7 mb-0">
                            <?= km_e((string) $event['name']) ?>
                            <span class="text-body-secondary">
                              / <?= km_e($fmt($event['startAt'])) ?> 〜 <?= km_e($fmt($event['endAt'])) ?>
                            </span>
                            <?php if ($event['ended']): ?>
                              <span class="badge text-bg-danger ms-1" data-i18n="page.downloads.mapReleaseEventEnded">
                                会期は終了しています
                              </span>
                            <?php elseif ($event['notStarted']): ?>
                              <span class="badge text-bg-secondary ms-1" data-i18n="page.downloads.mapReleaseEventPending">
                                会期前
                              </span>
                            <?php else: ?>
                              <span class="badge text-bg-success ms-1" data-i18n="page.downloads.mapReleaseEventActive">
                                会期中
                              </span>
                            <?php endif; ?>
                          </p>
                        <?php endif; ?>
                      </div>

                      <?php
                      /*
                       * 停止 / 再開 / 削除。
                       *
                       * **止めても、既に受け取った端末の地図は消えない。**
                       * あちらは自分が持っている期限で判断しており、
                       * サーバーの 410 は「取得に失敗した」としか扱わない。
                       * ここを書いておかないと「止めたのに使われている」と見えて、
                       * **もう一度止めようとして設定を壊す。**
                       */
                      ?>
                      <div class="border-top mt-3 pt-3 d-flex flex-wrap gap-2 align-items-start">
                        <form method="post" class="d-inline">
                          <?= km_csrf_field() ?>
                          <input type="hidden" name="map_slug" value="<?= km_e($release['slug']) ?>" />
                          <?php if ($release['paused']): ?>
                            <button type="submit" name="map_action" value="resume" class="btn btn-outline-success btn-sm">
                              <i class="bi bi-play-fill me-1" aria-hidden="true"></i>
                              <span data-i18n="page.downloads.mapReleaseResume">配信を再開する</span>
                            </button>
                          <?php else: ?>
                            <button type="submit" name="map_action" value="pause" class="btn btn-outline-secondary btn-sm">
                              <i class="bi bi-pause-fill me-1" aria-hidden="true"></i>
                              <span data-i18n="page.downloads.mapReleasePause">配信を停止する</span>
                            </button>
                          <?php endif; ?>
                        </form>

                        <details class="ms-auto">
                          <summary class="btn btn-outline-danger btn-sm">
                            <span data-i18n="page.downloads.mapReleaseDelete">配信を削除する</span>
                          </summary>
                          <div class="mt-2 p-3 border border-danger-subtle rounded">
                            <p class="fs-7 mb-2" data-i18n="page.downloads.mapReleaseDeleteWarn">
                              設定・地図の実体・この配信のアクセスコードを消します。元に戻せません。
                              既に受け取った端末の地図は、それぞれの期限が切れるまで残ります。
                            </p>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="map_slug" value="<?= km_e($release['slug']) ?>" />
                              <?php
                              /*
                               * **配信 ID を打たせる。** チェックボックスだと、
                               * 押し慣れた場所を反射で押して消える。打つ手間があれば、
                               * 少なくとも「どれを消すのか」は読むことになる。
                               */
                              ?>
                              <label class="fs-7 mb-0" for="km-del-<?= km_e($release['slug']) ?>">
                                <span data-i18n="page.downloads.mapReleaseDeleteConfirm">確認のため</span>
                                <code><?= km_e($release['slug']) ?></code>
                                <span data-i18n="page.downloads.mapReleaseDeleteType">と入力</span>
                              </label>
                              <input
                                type="text"
                                class="form-control form-control-sm km-w-12rem"
                                id="km-del-<?= km_e($release['slug']) ?>"
                                name="confirm_slug"
                                autocomplete="off"
                                required
                              />
                              <button type="submit" name="map_action" value="delete" class="btn btn-danger btn-sm">
                                <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                <span data-i18n="page.downloads.mapReleaseDeleteButton">削除する</span>
                              </button>
                            </form>
                          </div>
                        </details>
                      </div>

                      <?php if ($release['paused']): ?>
                        <div class="alert alert-dark fs-7 mt-3 mb-0" role="alert" data-i18n="page.downloads.mapReleasePausedHint">
                          停止中は、新しくコードを入れた端末が地図を受け取れません。
                          既に受け取った端末からは消えません(それぞれの期限まで使えます)。
                          取り上げるには、期限を縮めて配信し直してください。
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>

                  <?php
                    // 期限だけでなく、**実体が読めない場合も更新の手順を出す**
                    $needsRenew = false;
                    foreach ($mapReleases as $release) {
                        if (($release['status']['warn'] ?? true) === true
                            || $release['file']['error'] !== null
                            || ($release['summary']['activeEvent']['ended'] ?? false) === true) {
                            $needsRenew = true;
                        }
                    }
                  ?>
                  <?php if ($needsRenew): ?>
                    <?php
                    /*
                     * **更新の手順をここに置く。** この画面から地図は差し替えられない
                     * (地図の実体と config/app-map.local.php は配備対象外で、
                     * ホスト側が正本)。行き先を示さないと、気づいても何もできない。
                     *
                     * 以前は手元の `new-map-release.ps1 -UploadToHost` を案内していた。
                     * 配信は 2026-09-03 から管理画面で行う(2つの正本とも)ので、そちらへ案内する。
                     */
                    ?>
                    <div class="alert alert-warning fs-7 mt-3 mb-0" role="alert">
                      <strong data-i18n="page.downloads.mapReleaseRenew">更新するには</strong>
                      <p class="mb-1 mt-2" data-i18n="page.downloads.mapReleaseRenewHint">
                        「アプリへ地図を配信する」の画面で、期限を選び直して配信し直してください(アクセスコードは据え置き)。
                      </p>
                      <a class="btn btn-sm btn-outline-dark" href="./map-publish.php" data-i18n="page.downloads.mapReleaseRenewLink">
                        アプリへ地図を配信する
                      </a>
                    </div>
                  <?php endif; ?>
                </div>
                <!-- /.card-body -->
              </div>
              <!--end::MapRelease-->
            <?php endif; ?>

            <!--begin::Row-->
            <div class="row">
              <div class="col-12 col-xl-8">
                <!--begin::Card-->
                <div class="card mb-4">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.downloads.apkTitle">Android アプリ (APK)</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <?php if (isset($dist['apk'])): ?>
                      <p data-i18n="page.downloads.apkReady">
                        端末にインストールできる APK が登録されています。Android の設定で
                        「提供元不明のアプリ」を許可してからインストールしてください。
                      </p>
                      <div class="d-grid gap-2 d-sm-flex mb-2">
                        <a href="/api/download.php?slug=apk" class="btn btn-primary">
                          <i class="bi bi-download me-1" aria-hidden="true"></i>
                          <span data-i18n="page.downloads.apkButton">APK をダウンロード</span>
                        </a>
                      </div>
                    <?php else: ?>
                      <div class="text-center py-4">
                        <i class="bi bi-phone text-body-secondary km-icon-3rem"></i>
                        <p class="mt-3 mb-1 fw-semibold" data-i18n="page.downloads.apkNotReady">
                          まだ配布用の APK はありません
                        </p>
                        <p class="text-body-secondary fs-7 mb-0" data-i18n="page.downloads.apkHint">
                          ビルドができたら、下のフォームから置いてください。置いた時点で
                          ダウンロードできるようになります。
                        </p>
                      </div>
                    <?php endif; ?>
                    <?php $renderCurrent($dist['apk'] ?? null); ?>
                    <?php $renderUploadForm('apk', $dist['apk'] ?? null); ?>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
        <?php if ($mapReleases !== []): ?>
          <script<?= km_csp_nonce_attr() ?>>
            (() => {
              /*
               * アクセスコードの表示切り替え。**通信はしない。**
               * 伏せ字と実物は最初から HTML に在り、hidden を入れ替えるだけ。
               * 取りに行く作りにすると、通信が要るうえ「押しても出ない」場面が増える。
               *
               * style.display ではなく hidden 属性を使う —— CSP で style 属性が
               * 使えないので、JS から style を触る形はこの現場では素直でない。
               */
              document.querySelectorAll('[data-km-code-toggle]').forEach((button) => {
                const box = button.closest('dd');
                if (!box) return;
                const mask = box.querySelector('[data-km-code-mask]');
                const value = box.querySelector('[data-km-code-value]');
                const labelShow = button.querySelector('[data-km-code-label-show]');
                const labelHide = button.querySelector('[data-km-code-label-hide]');
                if (!mask || !value || !labelShow || !labelHide) return;

                const hide = () => {
                  value.hidden = true;
                  mask.hidden = false;
                  labelShow.hidden = false;
                  labelHide.hidden = true;
                };

                button.addEventListener('click', () => {
                  if (!value.hidden) {
                    hide();
                    return;
                  }
                  value.hidden = false;
                  mask.hidden = true;
                  labelShow.hidden = true;
                  labelHide.hidden = false;
                  // 出しっぱなしにしない。**押した本人が忘れても戻る**
                  window.setTimeout(hide, 30000);
                });
              });
            })();
          </script>
        <?php endif; ?>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
