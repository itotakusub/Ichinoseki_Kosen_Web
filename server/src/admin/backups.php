<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/map-backup.php';
require_once dirname(__DIR__) . '/lib/learning-backup.php';
require_once dirname(__DIR__) . '/lib/poster-archive.php';
require_once dirname(__DIR__) . '/lib/html-host.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * バックアップ(2026-10-23、利用者の指示「サーバーでもバックアップデータを見れるように」)。
 *
 * - 地図のバックアップ: 管理アプリの「サーバーにバックアップ」(api/map-backup.php・lib/map-backup.php)。新しい方から 20 件
 * - Wi-Fi 学習の控え: サーバーの学習データを消すときに、消す前の中身を自動で取ったもの(lib/learning-backup.php)。新しい方から 30 件。
 *   **戻す**と同じ記録をサーバーへ入れ直し、各端末は次の同期で受け取る
 *
 * - ポスターの控え: 会期の終わりに「ポスター・広告を全部、控えへ移して消す」で作った zip(2026-11-03、lib/poster-archive.php)。
 *   利用者の決定で**ダウンロードだけ**(戻すボタンは無い)。自動では消さない
 * - HTML 展開サイト: 利用者がサーバーに保存したもの(2026-11-05、lib/html-host.php)。持ち主ごとの数と大きさ・消す。
 *   **中身は管理画面からも開かない**(本人だけが見られる約束。規約 第8条の2)。規約に反するものを消すためにある
 *
 * 中身には教職員の氏名が入りうる(地図のバックアップ)。見る・落とすは管理者だけ(この画面は guard.php の内側)。
 * 消す・戻すは書き込みの権限が要る(guard.php が POST を見る)。
 */

$errors = [];
$notice = null;
$dbError = null;
$mapBackups = [];
$learningBackups = [];
$posterArchives = [];
$htmlSummary = null;
$mapView = null;
$learningView = null;

$idParam = static fn (string $key): int => isset($_GET[$key]) && is_string($_GET[$key]) && ctype_digit($_GET[$key]) ? (int) $_GET[$key] : 0;

try {
    $pdo = km_db();

    // 落とす(GET)。地図はアプリの「ノードのインポート」でそのまま読める JSON、学習は控えの JSON
    if (($downloadId = $idParam('map_download')) > 0) {
        $row = km_map_backup_find($pdo, $downloadId);
        if ($row !== null) {
            $json = km_map_backup_read($row);
            km_admin_log_record('content', 'map.backup_download', '#' . $downloadId);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="kosenmap-map-backup-' . $downloadId . '.json"');
            header('Cache-Control: no-store');
            echo $json;
            exit;
        }
        $errors[] = 'そのバックアップはありません(古いものは自動で消えます)。';
    }
    if (($downloadId = $idParam('learning_download')) > 0) {
        $row = km_learning_backup_find($pdo, $downloadId);
        if ($row !== null) {
            $path = km_learning_backup_path($row);
            km_learning_backup_read($row); // 壊れていないか確かめてから流す
            km_admin_log_record('content', 'learning.backup_download', '#' . $downloadId);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="kosenmap-learning-backup-' . $downloadId . '.json"');
            header('Cache-Control: no-store');
            readgzfile($path);
            exit;
        }
        $errors[] = 'その控えはありません(古いものは自動で消えます)。';
    }
    if (($downloadId = $idParam('poster_archive_download')) > 0) {
        $row = km_poster_archive_find($pdo, $downloadId);
        $path = $row === null ? null : km_poster_archive_path($row);
        if ($path !== null && is_file($path)) {
            km_admin_log_record('content', 'poster.archive_download', $row['storedName']);
            header('Content-Type: application/zip');
            header('Content-Length: ' . (string) filesize($path));
            header('Content-Disposition: attachment; filename="' . $row['storedName'] . '"');
            header('Cache-Control: no-store');
            readfile($path);
            exit;
        }
        $errors[] = 'そのポスターの控えはありません(消されたか、ファイルが見つかりません)。';
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $targetId = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : 0;
        if ($formAction === 'map_delete') {
            if ($targetId > 0 && km_map_backup_delete($pdo, $targetId)) {
                km_admin_log_record('content', 'map.backup_delete', '#' . $targetId);
            }
            header('Location: ./backups.php?saved=map_deleted', true, 303);
            exit;
        }
        if ($formAction === 'learning_restore') {
            try {
                $restored = km_learning_backup_restore($pdo, $targetId, (string) ($KM_USER['sub'] ?? '') ?: null);
                km_admin_log_record('settings', 'learning.backup_restore', '#' . $targetId . '(' . $restored . ' 件)');
                header('Location: ./backups.php?saved=learning_restored&n=' . $restored . '#learning-backups', true, 303);
                exit;
            } catch (InvalidArgumentException | RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if ($formAction === 'poster_archive_delete') {
            try {
                $removed = km_poster_archive_delete($pdo, $targetId);
                km_admin_log_record('content', 'poster.archive_delete', $removed);
                header('Location: ./backups.php?saved=poster_archive_deleted#poster-archives', true, 303);
                exit;
            } catch (InvalidArgumentException | RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if ($formAction === 'html_site_delete') {
            $site = km_html_site_find($pdo, (string) ($_POST['site'] ?? ''));
            if ($site !== null) {
                km_html_site_delete($pdo, $site);
                // 持ち主の名前は記録に書かない(利用者 ID も)。サイトの名前と番号だけ
                km_admin_log_record('content', 'html.delete', $site['name'] . '(' . $site['id'] . ')');
            }
            header('Location: ./backups.php?saved=html_deleted#html-sites', true, 303);
            exit;
        }
        // 共有リンクを全部止める(2026-11-05)。サイトそのものは残す。止めたリンクは二度と使えない
        if ($formAction === 'html_share_stop') {
            $site = km_html_site_find($pdo, (string) ($_POST['site'] ?? ''));
            if ($site !== null) {
                $stopped = km_html_shares_delete_site($pdo, $site['dbId']);
                km_admin_log_record('content', 'html.share_stop', $site['name'] . '(' . $site['id'] . ')・' . $stopped . ' 本');
            }
            header('Location: ./backups.php?saved=html_share_stopped#html-sites', true, 303);
            exit;
        }
    }

    $mapBackups = km_map_backup_list($pdo);
    $htmlSummary = km_html_admin_summary($pdo);
    $learningBackups = km_learning_backup_list($pdo);
    $posterArchives = km_poster_archives_list($pdo);

    // 中身を見る(内訳)
    if (($viewId = $idParam('map_view')) > 0) {
        $row = km_map_backup_find($pdo, $viewId);
        if ($row !== null) {
            $json = km_map_backup_read($row);
            // パスワード付き(2026-10-23)はサーバーで開けない
            $locked = km_map_backup_is_locked($json);
            $mapView = ['id' => $viewId, 'locked' => $locked, 'summary' => $locked ? null : km_map_backup_summary($json)];
        }
    }
    if (($viewId = $idParam('learning_view')) > 0) {
        $row = km_learning_backup_find($pdo, $viewId);
        $learningView = $row === null ? null : ['id' => $viewId, 'summary' => km_learning_backup_summary(km_learning_backup_read($row)['items'])];
    }
} catch (Throwable $exception) {
    error_log('admin/backups.php: ' . $exception->getMessage());
    $dbError = $exception instanceof RuntimeException ? $exception->getMessage() : 'データベースに接続できませんでした。';
}

$saved = (string) ($_GET['saved'] ?? '');
$notice = match ($saved) {
    'map_deleted' => '地図のバックアップを消しました。',
    'learning_restored' => '学習データを ' . (int) ($_GET['n'] ?? 0) . ' 件戻しました(各端末は次の同期で受け取ります)。',
    'poster_archive_deleted' => 'ポスターの控えを消しました。',
    'html_deleted' => 'HTML 展開サイトのサイトを消しました。',
    'html_share_stopped' => 'HTML 展開サイトの共有リンクを止めました。',
    default => null,
};

$floorName = static fn (string $floor): string => match (true) {
    $floor === 'OUTSIDE' || $floor === 'outside' => '外',
    preg_match('/^\d+F?$/', $floor) === 1 => rtrim($floor, 'F') . 'F',
    default => $floor,
};
$size = static fn (int $bytes): string => $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%d KB', (int) ceil($bytes / 1024));
$scopeLabel = static fn (array $b): string => match ($b['scope']) {
    'all' => '全部',
    'floor' => ($b['floor'] !== null ? $floorName($b['floor']) : '') . 'の階',
    'nodes' => '地点を選んで',
    default => $b['scope'],
};

$KM_PAGE = [
    'nav' => 'backups',
    'titleKey' => 'page.backups.title',
    'title' => 'バックアップ | KosenMap 管理',
    'h1Key' => 'page.backups.h1',
    'h1' => 'バックアップ',
    'crumbs' => [
        ['key' => 'page.backups.h1', 'text' => 'バックアップ'],
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
              <div class="alert alert-warning" role="alert"><?= km_e($dbError) ?></div>
            <?php endif; ?>

            <div class="card mb-4" id="map-backups">
              <div class="card-header">
                <h3 class="card-title">地図のバックアップ(置いた人ごとに新しい方から <?= (int) KM_MAP_BACKUP_KEEP ?> 件)</h3>
              </div>
              <div class="card-body">
                <p class="text-body-secondary fs-7 mb-2">
                  管理アプリの「サーバーにバックアップ」で置いたもの。地点・線・イベントと、「学習あり」のものは Wi-Fi の学習も入っています。
                  落とした JSON は管理アプリの「ノードのインポート」(学習ありは「地図のインポート」)で読めます。中身に教職員の氏名が入ることがあります。
                  🔒 はパスワード付きで、サーバーでは中身を開けません(管理アプリでパスワードを入れて戻します)。学習の協力者が置いたものも並びます。
                </p>
                <?php if ($mapView !== null): ?>
                  <div class="alert alert-info">
                    <strong>#<?= (int) $mapView['id'] ?> の中身</strong>
                    <?php if ($mapView['locked']): ?>
                      : パスワード付きのため、サーバーでは中身を開けません。
                    <?php elseif ($mapView['summary'] === null): ?>
                      : 地図として読めませんでした。
                    <?php else: ?>
                      : 地点 <?= (int) $mapView['summary']['nodes'] ?>(<?php
                        echo km_e(implode('・', array_map(static fn ($f, $n) => $floorName((string) $f) . ' ' . $n, array_keys($mapView['summary']['floors']), $mapView['summary']['floors'])));
                      ?>)・線 <?= (int) $mapView['summary']['lines'] ?>・イベント <?= (int) $mapView['summary']['events'] ?>・氏名の入った地点 <?= (int) $mapView['summary']['occupants'] ?>・学習地点 <?= (int) $mapView['summary']['fingerprints'] ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($mapBackups === []): ?>
                  <p class="mb-0">まだありません。</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>#</th><th>日時</th><th>置いた人</th><th>地点</th><th>大きさ</th><th>メモ</th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($mapBackups as $backup): ?>
                          <tr>
                            <td><?= (int) $backup['id'] ?></td>
                            <td><?= km_e(date('Y-m-d H:i', $backup['createdAt'])) ?></td>
                            <td><?= km_e($backup['by']) ?></td>
                            <td><?= $backup['nodeCount'] === null ? '—' : (int) $backup['nodeCount'] ?></td>
                            <td><?= km_e($size($backup['bytes'])) ?></td>
                            <td><?= km_e($backup['note']) ?><?php if ($backup['withLearning']): ?> <span class="badge text-bg-info">学習あり</span><?php endif; ?><?php if ($backup['locked']): ?> <span class="badge text-bg-secondary" title="パスワード付き">🔒</span><?php endif; ?></td>
                            <td class="text-nowrap">
                              <a class="btn btn-outline-secondary btn-sm" href="./backups.php?map_view=<?= (int) $backup['id'] ?>#map-backups">中身</a>
                              <a class="btn btn-outline-primary btn-sm" href="./backups.php?map_download=<?= (int) $backup['id'] ?>">ダウンロード</a>
                              <?php if ($KM_ADMIN_CAN_WRITE): ?>
                                <form method="post" class="d-inline" data-km-confirm="このバックアップ(#<?= (int) $backup['id'] ?>)を消しますか？元に戻せません。">
                                  <?= km_csrf_field() ?>
                                  <input type="hidden" name="form_action" value="map_delete">
                                  <input type="hidden" name="id" value="<?= (int) $backup['id'] ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm">消す</button>
                                </form>
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

            <div class="card mb-4" id="learning-backups">
              <div class="card-header">
                <h3 class="card-title">Wi-Fi 学習の控え(新しい方から <?= (int) KM_LEARNING_BACKUP_KEEP ?> 件)</h3>
              </div>
              <div class="card-body">
                <p class="text-body-secondary fs-7 mb-2">
                  サーバーの学習データ(と精度の評価)を消したときに、消す前の中身を自動で取ったもの。控えを取れなかったときは消しません。
                  「戻す」と同じ記録をサーバーへ入れ直し、各端末は次の同期で受け取ります。
                </p>
                <?php if ($learningView !== null): ?>
                  <div class="alert alert-info">
                    <strong>#<?= (int) $learningView['id'] ?> の中身</strong>:
                    地点 <?= (int) $learningView['summary']['nodes'] ?>・記録 <?= (int) array_sum($learningView['summary']['floors']) ?>(<?php
                      echo km_e(implode('・', array_map(static fn ($f, $n) => $floorName((string) $f) . ' ' . $n, array_keys($learningView['summary']['floors']), $learningView['summary']['floors'])));
                    ?>)<?php if ($learningView['summary']['from'] !== null): ?>・測った日 <?= km_e(date('Y-m-d', intdiv($learningView['summary']['from'], 1000))) ?> 〜 <?= km_e(date('Y-m-d', intdiv((int) $learningView['summary']['to'], 1000))) ?><?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($learningBackups === []): ?>
                  <p class="mb-0">まだありません(学習データを消すと、ここに控えが残ります)。</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>#</th><th>消した日時</th><th>消した人</th><th>種類</th><th>範囲</th><th>件数</th><th>大きさ</th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($learningBackups as $backup): ?>
                          <tr>
                            <td><?= (int) $backup['id'] ?></td>
                            <td><?= km_e($backup['createdAt']) ?></td>
                            <td><?= km_e($backup['by']) ?></td>
                            <td><?= $backup['kind'] === 'samples' ? '学習データ' : '精度の評価' ?></td>
                            <td><?= km_e($scopeLabel($backup)) ?></td>
                            <td><?= (int) $backup['count'] ?></td>
                            <td><?= km_e($size($backup['bytes'])) ?></td>
                            <td class="text-nowrap">
                              <a class="btn btn-outline-secondary btn-sm" href="./backups.php?learning_view=<?= (int) $backup['id'] ?>#learning-backups">中身</a>
                              <a class="btn btn-outline-primary btn-sm" href="./backups.php?learning_download=<?= (int) $backup['id'] ?>">ダウンロード</a>
                              <?php if ($KM_ADMIN_CAN_WRITE): ?>
                                <form method="post" class="d-inline" data-km-confirm="この控え(#<?= (int) $backup['id'] ?>・<?= (int) $backup['count'] ?> 件)をサーバーの学習データへ戻しますか？">
                                  <?= km_csrf_field() ?>
                                  <input type="hidden" name="form_action" value="learning_restore">
                                  <input type="hidden" name="id" value="<?= (int) $backup['id'] ?>">
                                  <button type="submit" class="btn btn-outline-warning btn-sm">戻す</button>
                                </form>
                              <?php endif; ?>
                              <?php if ($backup['restoredAt'] !== null): ?>
                                <span class="badge text-bg-secondary">戻した <?= km_e($backup['restoredAt']) ?></span>
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

            <div class="card mb-4" id="poster-archives">
              <div class="card-header">
                <h3 class="card-title">ポスターの控え(会期の終わり)</h3>
              </div>
              <div class="card-body">
                <p class="text-body-secondary fs-7 mb-2">
                  会期を終えるときに「ポスター・広告を全部、控えへ移して消す」で作ったもの(<a href="./event-setup.php">イベントのセットアップ</a>の「会期を終える」)。
                  zip の中は、全部の項目(posters.json)と元の画像です。画面から戻す機能はありません(戻すときは「ポスター・広告」で入れ直します)。
                  自動では消えません<?= $posterArchives !== [] ? '(いま合計 ' . km_e($size(array_sum(array_column($posterArchives, 'bytes')))) . ')' : '' ?>。
                </p>
                <?php if ($posterArchives === []): ?>
                  <p class="mb-0">まだありません。</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>#</th><th>日時</th><th>会期</th><th>件数</th><th>画像</th><th>大きさ</th><th>作った人</th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($posterArchives as $archive): ?>
                          <tr>
                            <td><?= (int) $archive['id'] ?></td>
                            <td><?= km_e($archive['createdAt']) ?></td>
                            <td><?= km_e($archive['label']) ?></td>
                            <td><?= (int) $archive['posterCount'] ?></td>
                            <td><?= (int) $archive['imageCount'] ?></td>
                            <td><?= km_e($size($archive['bytes'])) ?></td>
                            <td><?= km_e($archive['createdBy']) ?></td>
                            <td class="text-nowrap">
                              <a class="btn btn-outline-primary btn-sm" href="./backups.php?poster_archive_download=<?= (int) $archive['id'] ?>">ダウンロード</a>
                              <?php if ($KM_ADMIN_CAN_WRITE): ?>
                                <form method="post" class="d-inline" data-km-confirm="このポスターの控え(#<?= (int) $archive['id'] ?>・<?= (int) $archive['posterCount'] ?> 件)を消しますか？元に戻せません。">
                                  <?= km_csrf_field() ?>
                                  <input type="hidden" name="form_action" value="poster_archive_delete">
                                  <input type="hidden" name="id" value="<?= (int) $archive['id'] ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm">消す</button>
                                </form>
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

            <?php /* HTML 展開サイト(2026-11-05、lib/html-host.php)。中身は開かない(本人だけが見られる約束) */ ?>
            <div class="card mb-4" id="html-sites">
              <div class="card-header">
                <h3 class="card-title">HTML 展開サイト(サーバーに保存したもの)</h3>
              </div>
              <div class="card-body">
                <p class="text-body-secondary fs-7 mb-2">
                  利用者が <a href="/html.php" target="_blank" rel="noopener">HTML 展開</a>でサーバーに保存したもの(1 人 <?= KM_HTML_MAX_SITES_PER_OWNER ?> サイト・<?= km_e($size(KM_HTML_MAX_OWNER_BYTES)) ?> まで。各サイトは新しい版を <?= KM_HTML_MAX_VERSIONS ?> つ残します)。
                  <strong>中身はここからは開けません</strong>(本人と、本人が作った共有リンクを知る人だけが見られる約束)。規約に反するものと分かったときに、共有を止める・消すための一覧です。
                  <?php if ($htmlSummary !== null): ?>
                    一時フォルダ(24 時間で消えるもの)はいま <?= (int) $htmlSummary['tempCount'] ?> 件・<?= km_e($size($htmlSummary['tempBytes'])) ?>。
                  <?php endif; ?>
                </p>
                <?php if ($htmlSummary === null || $htmlSummary['sites'] === []): ?>
                  <p class="mb-0">まだありません。</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>持ち主</th><th>サイト</th><th>版</th><th>大きさ</th><th>共有リンク</th><th>最後に保存</th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($htmlSummary['sites'] as $site): ?>
                          <tr>
                            <?php // 持ち主の名前もサイトの名前も利用者が入れた自由文。data-i18n は付けない ?>
                            <td><?= km_e($site['ownerName'] !== '' ? $site['ownerName'] : '(名前なし)') ?></td>
                            <td><?= km_e($site['name']) ?></td>
                            <td><?= max(0, (int) $site['nextVersion'] - 1) ?></td>
                            <td><?= km_e($size($site['bytes'])) ?></td>
                            <td><?= (int) ($site['shares'] ?? 0) ?> 本</td>
                            <td><?= km_e($site['updatedAt']) ?></td>
                            <td class="text-nowrap">
                              <?php if ($KM_ADMIN_CAN_WRITE): ?>
                                <?php if (($site['shares'] ?? 0) > 0): ?>
                                  <form method="post" class="d-inline" data-km-confirm="「<?= km_e($site['name']) ?>」の共有リンクを全部止めますか？止めたリンクは二度と使えません。">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="html_share_stop">
                                    <input type="hidden" name="site" value="<?= km_e($site['id']) ?>">
                                    <button type="submit" class="btn btn-outline-warning btn-sm">共有を止める</button>
                                  </form>
                                <?php endif; ?>
                                <form method="post" class="d-inline" data-km-confirm="「<?= km_e($site['name']) ?>」を版ごと消しますか？元に戻せません。">
                                  <?= km_csrf_field() ?>
                                  <input type="hidden" name="form_action" value="html_site_delete">
                                  <input type="hidden" name="site" value="<?= km_e($site['id']) ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm">消す</button>
                                </form>
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
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
