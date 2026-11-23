<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/site.php';
require_once dirname(__DIR__) . '/lib/map-embed.php';

/**
 * 地図の埋め込み(段 15。2026-11-14。中身は lib/map-embed.php)。
 *
 * 利用者「マップの API を作成してください。別のサイトで表示されるような埋め込み方を想定してください」「登録したサイトだけ」。
 * - 埋め込むサイトを登録する(名前・オリジン)→ 札ができる。貼るコード(JS・iframe)と JSON の口の URL をここで写す
 * - 埋め込めるのは、札に登録したオリジンのページだけ(/embed/ の CSP frame-ancestors)。止めればすぐ出なくなる
 * - **消さずに止める**(札をほかのサイトへ使い回さない。止めたものはいつでも戻せる)
 * - 30 日の表示の回数(枠の地図・JSON の口)を出す
 */

$KM_PAGE = [
    'nav' => 'embedSites',
    'titleKey' => 'page.embedSites.title',
    'title' => '地図の埋め込み | KosenMap 管理',
    'h1Key' => 'page.embedSites.h1',
    'h1' => '地図の埋め込み',
    'crumbs' => [
        ['key' => 'side.groupPublic', 'text' => '公開ページ'],
        ['key' => 'page.embedSites.h1', 'text' => '地図の埋め込み'],
    ],
];

$errors = [];
$dbError = null;
$notice = [
    'created' => '登録しました。下の「貼るコード」を埋め込むサイトに貼ってください。',
    'updated' => '直しました。',
    'enabled' => '使えるようにしました。',
    'disabled' => '止めました。このサイトの埋め込みは、開き直すと出なくなります。',
][(string) ($_GET['saved'] ?? '')] ?? null;
$sites = [];
$counts = [];
$form = ['label' => '', 'origins' => ''];

try {
    $pdo = km_db();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        // 副作用を起こす前に弾く
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $by = (string) ($KM_USER['name'] ?? '');
        $label = trim((string) ($_POST['label'] ?? ''));
        $id = (int) ($_POST['id'] ?? 0);
        if ($formAction === 'create' || $formAction === 'update') {
            $parsed = km_embed_parse_origins((string) ($_POST['origins'] ?? ''));
            if ($label === '' || mb_strlen($label) > KM_EMBED_MAX_LABEL) {
                $errors[] = '名前を ' . KM_EMBED_MAX_LABEL . ' 字までで書いてください(例: 学校のホームページ)。';
            } elseif (!$parsed['ok']) {
                $errors[] = $parsed['error'];
            } elseif ($formAction === 'create') {
                $created = km_embed_site_create($pdo, $label, $parsed['origins'], $by);
                km_admin_log_record('content', 'embed-site.create', $label . '(' . implode(' ', $parsed['origins']) . ')');
                header('Location: ./embed-sites.php?saved=created#site-' . $created['id'], true, 303);
                exit;
            } else {
                km_embed_site_update($pdo, $id, $label, $parsed['origins']);
                km_admin_log_record('content', 'embed-site.update', $label . '(' . implode(' ', $parsed['origins']) . ')');
                header('Location: ./embed-sites.php?saved=updated#site-' . $id, true, 303);
                exit;
            }
            if ($formAction === 'create') {
                $form = ['label' => $label, 'origins' => (string) ($_POST['origins'] ?? '')];
            }
        } elseif ($formAction === 'enable' || $formAction === 'disable') {
            km_embed_site_set_enabled($pdo, $id, $formAction === 'enable');
            km_admin_log_record('content', $formAction === 'enable' ? 'embed-site.enable' : 'embed-site.disable', '#' . $id);
            header('Location: ./embed-sites.php?saved=' . $formAction . 'd#site-' . $id, true, 303);
            exit;
        }
    }
    $sites = km_embed_sites($pdo);
    $counts = km_embed_counts_30d($pdo);
} catch (Throwable $exception) {
    error_log('admin/embed-sites.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$appOrigin = km_site_origin();
$snippet = static function (array $site) use ($appOrigin): string {
    return '<div id="campus-map"></div>' . "\n"
        . '<' . 'script src="' . $appOrigin . '/embed/v1.js"></' . 'script>' . "\n"  // 見本の文字(画面には escape して出す)
        . '<' . "script>\n"
        . "  const map = KosenMap.embed('#campus-map', { site: '" . $site['key'] . "', height: 480 });\n"
        . "  map.on('select', (place) => console.log(place.name));\n"
        . '</' . 'script>';
};
$iframeSnippet = static fn (array $site): string => '<iframe src="' . $appOrigin . '/embed/?site=' . $site['key'] . '" title="校内の地図" width="100%" height="480" loading="lazy"></iframe>';

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

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-window-stack me-2 mt-1" aria-hidden="true"></i>
              <div>
                公開の地図を、ほかのサイトのページの中に出せます(Google マップの「地図を埋め込む」と同じ形)。<br>
                <strong>埋め込めるのは、ここで登録したオリジン(https://名前)のページだけ</strong>です。ほかのサイトに貼られても地図は出ません。<br>
                出るのは<strong>サインインしていない来場者と同じ地図</strong>です(教職員の氏名・研究室は出ません)。地図を「パスワードが必要」にしているときは出ません。<br>
                外から地点を指す(<code>map.focus('地点の番号')</code>)ときの番号は、各サイトの「地点の一覧(JSON)」で見られます。
              </div>
            </div>

            <div class="card mb-4">
              <div class="card-header"><h3 class="card-title mb-0">サイトを登録する</h3></div>
              <div class="card-body">
                <form method="post" action="./embed-sites.php" class="row g-3 m-0">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="form_action" value="create">
                  <div class="col-md-4">
                    <label class="form-label" for="embed-label">名前</label>
                    <input class="form-control" id="embed-label" name="label" maxlength="<?= KM_EMBED_MAX_LABEL ?>" required value="<?= km_e($form['label']) ?>" placeholder="学校のホームページ">
                  </div>
                  <div class="col-md-8">
                    <label class="form-label" for="embed-origins">オリジン(1 行に 1 つ・<?= KM_EMBED_MAX_ORIGINS ?> つまで)</label>
                    <textarea class="form-control font-monospace" id="embed-origins" name="origins" rows="2" required placeholder="https://www.example.ac.jp"><?= km_e($form['origins']) ?></textarea>
                    <div class="form-text">https:// で始まる名前だけ(パス・* は書けません)。HTML 展開サイトに置いたページなら <code><?= km_e(km_site_html_origin()) ?></code> と <code><?= km_e(km_site_share_origin()) ?></code>。</div>
                  </div>
                  <div class="col-12">
                    <button type="submit" class="btn btn-primary"<?= $KM_ADMIN_CAN_WRITE ? '' : ' disabled' ?>><i class="bi bi-plus-lg" aria-hidden="true"></i> 登録する</button>
                  </div>
                </form>
              </div>
            </div>

            <?php if ($sites === [] && $dbError === null): ?>
              <p class="text-secondary">まだ登録したサイトはありません。</p>
            <?php endif; ?>
            <?php foreach ($sites as $site): ?>
              <?php $count = $counts[$site['id']] ?? ['views' => 0, 'places' => 0]; ?>
              <div class="card mb-4<?= $site['enabled'] ? '' : ' border-secondary' ?>" id="site-<?= (int) $site['id'] ?>">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                  <h3 class="card-title mb-0 me-auto"><?= km_e($site['label']) ?></h3>
                  <?php if ($site['enabled']): ?>
                    <span class="badge text-bg-success">使える</span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary">止めている</span>
                  <?php endif; ?>
                  <span class="text-secondary small">この 30 日: 地図 <?= (int) $count['views'] ?> 回・JSON <?= (int) $count['places'] ?> 回</span>
                </div>
                <div class="card-body">
                  <p class="mb-2">札: <code><?= km_e($site['key']) ?></code>・オリジン: <?php foreach ($site['origins'] as $origin): ?><code class="me-2"><?= km_e($origin) ?></code><?php endforeach; ?></p>
                  <p class="small text-secondary mb-3">登録 <?= km_e($site['createdAt']) ?><?= $site['createdBy'] !== '' ? '・' . km_e($site['createdBy']) : '' ?>・最後に直した日 <?= km_e($site['updatedAt']) ?></p>

                  <h4 class="h6">貼るコード(JavaScript で操るとき)</h4>
                  <pre class="bg-body-tertiary border rounded p-2 small mb-3"><code><?= km_e($snippet($site)) ?></code></pre>
                  <h4 class="h6">貼るコード(iframe だけ)</h4>
                  <pre class="bg-body-tertiary border rounded p-2 small mb-3"><code><?= km_e($iframeSnippet($site)) ?></code></pre>
                  <p class="mb-3">
                    <a href="<?= km_e($appOrigin . '/embed/?site=' . $site['key']) ?>" target="_blank" rel="noopener noreferrer">埋め込みの地図を開く</a>
                    ・<a href="<?= km_e($appOrigin . '/api/embed-places.php?site=' . $site['key']) ?>" target="_blank" rel="noopener noreferrer">地点の一覧(JSON)</a>
                  </p>

                  <details class="mb-2">
                    <summary>名前・オリジンを直す</summary>
                    <form method="post" action="./embed-sites.php" class="row g-2 mt-2 m-0">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="update">
                      <input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
                      <div class="col-md-4">
                        <label class="form-label" for="embed-label-<?= (int) $site['id'] ?>">名前</label>
                        <input class="form-control" id="embed-label-<?= (int) $site['id'] ?>" name="label" maxlength="<?= KM_EMBED_MAX_LABEL ?>" required value="<?= km_e($site['label']) ?>">
                      </div>
                      <div class="col-md-8">
                        <label class="form-label" for="embed-origins-<?= (int) $site['id'] ?>">オリジン</label>
                        <textarea class="form-control font-monospace" id="embed-origins-<?= (int) $site['id'] ?>" name="origins" rows="2" required><?= km_e(implode("\n", $site['origins'])) ?></textarea>
                      </div>
                      <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary btn-sm"<?= $KM_ADMIN_CAN_WRITE ? '' : ' disabled' ?>>直す</button>
                      </div>
                    </form>
                  </details>

                  <form method="post" action="./embed-sites.php" class="m-0">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
                    <?php if ($site['enabled']): ?>
                      <input type="hidden" name="form_action" value="disable">
                      <button type="submit" class="btn btn-outline-danger btn-sm"<?= $KM_ADMIN_CAN_WRITE ? '' : ' disabled' ?>>止める</button>
                      <span class="text-secondary small ms-2">消さずに止めます(いつでも戻せます)。</span>
                    <?php else: ?>
                      <input type="hidden" name="form_action" value="enable">
                      <button type="submit" class="btn btn-outline-success btn-sm"<?= $KM_ADMIN_CAN_WRITE ? '' : ' disabled' ?>>使えるようにする</button>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
