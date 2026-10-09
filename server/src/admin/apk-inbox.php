<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/apk-inbox.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/user-error.php';

/**
 * アプリの受け箱(2026-10-09。lib/apk-inbox.php)。メールの URL(?t=印)から開き、「公開する」を押す。
 *
 * **開いただけでは公開しない**(Gmail などがリンクを先に読みに行くので。利用者の決定)。
 * 公開・取り消し・前の版に戻すは、管理者のサインインと CSRF つきの POST だけ。
 * 印は URL に載るので、nginx はこのページのクエリをログに残さない(km_no_query)。
 */

$KM_PAGE = [
    'nav' => 'downloads',
    'titleKey' => 'page.apkInbox.title',
    'title' => 'アプリの受け箱 | KosenMap 管理',
    'h1Key' => 'page.apkInbox.h1',
    'h1' => 'アプリの受け箱',
    'crumbs' => [
        ['key' => 'page.downloads.h1', 'text' => 'ダウンロード'],
        ['key' => 'page.apkInbox.h1', 'text' => 'アプリの受け箱'],
    ],
];

$errors = [];
$notice = null;
$selected = null;
$recent = [];
$pdo = null;
$by = $KM_USER['name'] ?? null;

try {
    $pdo = km_db();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $id = (int) ($_POST['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        if (!km_csrf_verify()) {
            $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
        } else {
            try {
                if ($action === 'publish') {
                    km_apk_inbox_publish($pdo, $id, $by);
                    km_admin_log_record('content', 'apk.publish', '#' . $id);
                } elseif ($action === 'cancel') {
                    km_apk_inbox_cancel($pdo, $id, $by);
                    km_admin_log_record('content', 'apk.cancel', '#' . $id);
                } elseif ($action === 'rollback') {
                    km_apk_inbox_rollback($pdo, $id, $by);
                    km_admin_log_record('content', 'apk.rollback', '#' . $id);
                } else {
                    throw new InvalidArgumentException('知らない操作です。');
                }
                header('Location: ./apk-inbox.php?id=' . $id . '&done=' . rawurlencode($action), true, 302);
                exit;
            } catch (Throwable $exception) {
                error_log('apk-inbox.php ' . $action . ' failed: ' . $exception->getMessage());
                $errors[] = km_admin_error_message($exception, '操作できませんでした。');
            }
        }
        $selected = km_apk_inbox_find($pdo, $id);
    } elseif (isset($_GET['t'])) {
        $selected = km_apk_inbox_find_by_token($pdo, (string) $_GET['t']);
        if ($selected === null) {
            $errors[] = 'この URL の届けが見つかりません(古い URL か、打ち間違い)。下の一覧から選んでください。';
        }
    } elseif (isset($_GET['id'])) {
        $selected = km_apk_inbox_find($pdo, (int) $_GET['id']);
    }
    $recent = km_apk_inbox_recent($pdo, 10);
    if ($selected === null && $recent !== []) {
        $selected = $recent[0];
    }
} catch (Throwable $exception) {
    error_log('apk-inbox.php failed: ' . $exception->getMessage());
    $errors[] = 'データベースに接続できないため、受け箱を読み込めません。';
}

$done = (string) ($_GET['done'] ?? '');
$notice = ['publish' => '公開しました。アプリの自動更新が次に確かめたときから届きます。', 'cancel' => '取り消しました(受け箱から消しました)。', 'rollback' => '前の版に戻しました。'][$done] ?? null;

$statusLabel = static fn (string $s): array => [
    'waiting' => ['公開を待っています', 'text-bg-warning'],
    'published' => ['公開しました', 'text-bg-success'],
    'cancelled' => ['取り消しました', 'text-bg-secondary'],
    'expired' => ['期限切れ', 'text-bg-secondary'],
    'rolledback' => ['前の版に戻しました', 'text-bg-info'],
    'rejected' => ['検めに通りません(公開できません)', 'text-bg-danger'],
][$s] ?? [$s, 'text-bg-secondary'];
$slugLabel = static fn (string $slug): string => $slug === 'apk' ? '一般用' : '管理用';

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid">
            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start fs-7" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                PC が GitHub の非公開リポジトリに置いた APK を、サーバーが 5 分ごとに取りに行き、ここへ入れます(サーバーに外から APK を受け取る口はありません)。
                <strong>開いただけでは公開しません。</strong>版・署名の検めを見て「公開する」を押してください。覚えのない届けは「取り消す」。
              </div>
            </div>

            <?php if ($selected !== null): ?>
              <?php [$label, $badge] = $statusLabel((string) $selected['status']); ?>
              <div class="card mb-4">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                  <h3 class="card-title mb-0">届け #<?= (int) $selected['id'] ?>(版 <?= km_e((string) ($selected['version_label'] ?? '?')) ?>)</h3>
                  <span class="badge <?= km_e($badge) ?>"><?= km_e($label) ?></span>
                  <span class="text-body-secondary fs-7 ms-auto">
                    届いた <?= km_e(date('Y-m-d H:i', (int) strtotime((string) $selected['created_at']))) ?>
                    <?php if ($selected['status'] === 'waiting'): ?>
                      ・<?= km_e(date('Y-m-d H:i', (int) strtotime((string) $selected['expires_at']))) ?> まで
                    <?php endif; ?>
                    <?php if ($selected['decided_by'] !== null): ?>
                      ・<?= km_e((string) $selected['decided_by']) ?>(<?= km_e(date('Y-m-d H:i', (int) strtotime((string) $selected['decided_at']))) ?>)
                    <?php endif; ?>
                  </span>
                </div>
                <div class="card-body p-0 table-responsive">
                  <table class="table table-sm mb-0 text-nowrap">
                    <thead>
                      <tr><th>枠</th><th>版番号(今 → 届いた)</th><th>大きさ</th><th>SHA-256</th><th>パッケージ</th><th>署名</th><th>検め</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($selected['files'] as $f): ?>
                        <tr>
                          <td><?= km_e($slugLabel((string) $f['slug'])) ?></td>
                          <td><?= km_e((string) ($f['currentVersionCode'] ?? 'なし')) ?> → <strong><?= km_e((string) ($f['versionCode'] ?? '?')) ?></strong></td>
                          <td><?= km_e(km_upload_format_size((int) $f['size'])) ?></td>
                          <td><code><?= km_e(substr((string) $f['sha256'], 0, 16)) ?>…</code></td>
                          <td><code><?= km_e((string) ($f['package'] ?? '読めない')) ?></code></td>
                          <td>
                            <?php if ($f['signer'] === null): ?>
                              <span class="text-danger">読めない</span>
                            <?php elseif ($f['currentSigner'] === null): ?>
                              <code><?= km_e(substr((string) $f['signer'], 0, 12)) ?>…</code>(比べる相手なし)
                            <?php elseif ($f['signer'] === $f['currentSigner']): ?>
                              <span class="text-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> 今と同じ鍵</span>
                            <?php else: ?>
                              <span class="text-danger"><i class="bi bi-x-octagon-fill" aria-hidden="true"></i> 今と違う鍵</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-wrap">
                            <?php if ($f['problems'] === []): ?>
                              <span class="text-success">通過</span>
                            <?php else: ?>
                              <?php foreach ($f['problems'] as $p): ?>
                                <div class="text-danger fs-7">✕ <?= km_e((string) $p) ?></div>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer">
                  <?php foreach ($selected['problems'] as $p): ?>
                    <div class="text-danger fs-7 mb-2">✕ <?= km_e((string) $p) ?></div>
                  <?php endforeach; ?>
                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($selected['status'] === 'waiting'): ?>
                      <form method="post" class="d-inline">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>" />
                        <button type="submit" name="action" value="publish" class="btn btn-primary">
                          <i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>公開する
                        </button>
                      </form>
                      <form method="post" class="d-inline ms-auto">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>" />
                        <button type="submit" name="action" value="cancel" class="btn btn-outline-secondary">取り消す</button>
                      </form>
                    <?php elseif ($pdo !== null && km_apk_inbox_can_rollback($pdo, $selected)): ?>
                      <form method="post" class="d-inline">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>" />
                        <button type="submit" name="action" value="rollback" class="btn btn-outline-warning">
                          <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>前の版に戻す
                        </button>
                      </form>
                      <span class="text-body-secondary fs-7 align-self-center">
                        公開から <?= (int) KM_APK_INBOX_KEEP_DAYS ?> 日まで。新しい版を入れた端末は下がりません(Android は版を下げて上書きしない)。これから入れる人に前の版を配ります。
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="card mb-4">
              <div class="card-header"><h3 class="card-title">最近の届け</h3></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-hover mb-0 text-nowrap">
                  <thead><tr><th>#</th><th>版</th><th>状態</th><th>届いた</th><th>決めた人</th></tr></thead>
                  <tbody>
                    <?php if ($recent === []): ?>
                      <tr><td colspan="5" class="text-center text-body-secondary">まだ届けはありません</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recent as $r): ?>
                      <?php [$rl, $rb] = $statusLabel((string) $r['status']); ?>
                      <tr>
                        <td><a href="./apk-inbox.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
                        <td><?= km_e((string) ($r['version_label'] ?? '?')) ?>(<?= km_e((string) ($r['version_code'] ?? '?')) ?>)</td>
                        <td><span class="badge <?= km_e($rb) ?>"><?= km_e($rl) ?></span></td>
                        <td><?= km_e(date('Y-m-d H:i', (int) strtotime((string) $r['created_at']))) ?></td>
                        <td><?= km_e((string) ($r['decided_by'] ?? '')) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
