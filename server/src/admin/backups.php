<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/map-backup.php';
require_once dirname(__DIR__) . '/lib/learning-backup.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * バックアップ(2026-10-23、利用者の指示「サーバーでもバックアップデータを見れるように」)。
 *
 * - 地図のバックアップ: 管理アプリの「サーバーにバックアップ」(api/map-backup.php・lib/map-backup.php)。新しい方から 20 件
 * - Wi-Fi 学習の控え: サーバーの学習データを消すときに、消す前の中身を自動で取ったもの(lib/learning-backup.php)。新しい方から 30 件。
 *   **戻す**と同じ記録をサーバーへ入れ直し、各端末は次の同期で受け取る
 *
 * 中身には教職員の氏名が入りうる(地図のバックアップ)。見る・落とすは管理者だけ(この画面は guard.php の内側)。
 * 消す・戻すは書き込みの権限が要る(guard.php が POST を見る)。
 */

$errors = [];
$notice = null;
$dbError = null;
$mapBackups = [];
$learningBackups = [];
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
    }

    $mapBackups = km_map_backup_list($pdo);
    $learningBackups = km_learning_backup_list($pdo);

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

          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
