<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/site.php';
require_once dirname(__DIR__) . '/lib/qr.php';
require_once dirname(__DIR__) . '/lib/csp.php';
require_once dirname(__DIR__) . '/lib/here-qr.php';

/**
 * 現在地の QR(2026-10-30、高専祭の振り返りのフェーズ 3 の 6。lib/here-qr.php)。
 *
 * 校内の壁や柱に貼る QR を印刷する。読むと、その場所を「今いる場所」にした地図が開き、そこからの道順を出せる
 * (Website は現在地を測れないので、来場者に QR を読んでもらう)。
 * 地点を選んで「印刷用に並べる」→ 1 枚に 12 個(名前・階・説明つき)を並べたページ → ブラウザで印刷。
 * 何も書き込まない(読むだけ)ので CSRF は要らない。
 */

$KM_PAGE = [
    'nav' => 'mapHereQr',
    'titleKey' => 'page.mapHereQr.title',
    'title' => '現在地の QR | KosenMap 管理',
    'h1Key' => 'page.mapHereQr.h1',
    'h1' => '現在地の QR',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.mapHereQr.h1', 'text' => '現在地の QR'],
    ],
];

$dbError = null;
$floorLabels = [];
$candidates = [];
try {
    $pdo = km_db();
    foreach ($pdo->query('SELECT id, label FROM km_map_floors ORDER BY sort_order, id')->fetchAll() as $row) {
        $floorLabels[(string) $row['id']] = (string) $row['label'];
    }
    $nodes = $pdo->query('SELECT id, floor_id AS floor, name, type FROM km_map_nodes')->fetchAll();
    $candidates = km_here_qr_candidates($nodes, $floorLabels);
} catch (Throwable $exception) {
    error_log('admin/map-here-qr.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$selected = km_here_qr_selected($_GET['ids'] ?? null, $candidates);
$origin = km_site_url(null, '');

/*
 * ---- 印刷のページ(?print=1)----
 * 管理画面の枠(横の一覧・上の帯)を付けない 1 枚のページ。A4 に 3 列 × 4 段
 */
if (($_GET['print'] ?? '') === '1' && $selected !== []) {
    $byId = [];
    foreach ($candidates as $floor => $list) {
        foreach ($list as $node) {
            $byId[$node['id']] = $node + ['floor' => $floor];
        }
    }
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>現在地の QR(印刷)</title>
    <style<?= km_csp_nonce_attr() ?>>
        body { margin: 0; font-family: "Noto Sans JP", sans-serif; color: #0f172a; background: #fff; }
        .bar { padding: 12px 16px; display: flex; gap: 12px; align-items: center; border-bottom: 1px solid #cbd5e1; }
        .sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; padding: 8mm; }
        .card { border: 1px dashed #94a3b8; padding: 5mm 4mm; text-align: center; break-inside: avoid; page-break-inside: avoid; }
        .card svg { width: 40mm; height: 40mm; }
        .title { font-size: 11pt; font-weight: 700; margin: 0 0 2mm; }
        .name { font-size: 13pt; font-weight: 700; margin: 2mm 0 1mm; overflow-wrap: anywhere; }
        .floor { font-size: 10pt; margin: 0; }
        .hint { font-size: 8pt; color: #475569; margin: 2mm 0 0; }
        .url { font: 8pt ui-monospace, monospace; color: #475569; margin: 2mm 0 0; overflow-wrap: anywhere; user-select: all; }
        @media print { .bar, .url { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" id="km-print">印刷する</button>
        <a href="./map-here-qr.php">選び直す</a>
        <span><?= count($selected) ?> 個</span>
    </div>
    <div class="sheet">
        <?php foreach ($selected as $id): ?>
            <?php $node = $byId[$id]; $url = km_here_qr_url($origin, $id); if ($url === null) { continue; } ?>
            <div class="card">
                <p class="title">📍 現在地の QR</p>
                <?= str_replace('アクセスコードの QR', km_e('現在地 ' . $node['name']), km_qr_svg(km_qr_matrix($url, 'M'), 4, 2)) ?>
                <p class="name"><?= km_e($node['name']) ?></p>
                <p class="floor"><?= km_e($floorLabels[$node['floor']] ?? $node['floor']) ?></p>
                <p class="hint">スマホのカメラで読むと、ここから行き先までの道順を出せます</p>
                <?php /* NFC のシールに書く URL(画面だけ。印刷には出さない。docs/plan.md「位置を自動で取る方法」) */ ?>
                <p class="url"><?= km_e($url) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <script<?= km_csp_nonce_attr() ?>>
        document.getElementById('km-print').addEventListener('click', function () { window.print(); });
    </script>
</body>
</html>
    <?php
    exit;
}

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
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-qr-code me-2 mt-1" aria-hidden="true"></i>
              <div>
                校内の壁や柱に貼る QR を印刷します。来場者がスマホのカメラで読むと、その場所を「今いる場所」にした地図が開き、
                そこから行き先までの道順を出せます(Website は現在地を測れないため)。案内チャットの「現在地から」にも使います。<br>
                貼る場所の地点を選んで「印刷用に並べる」を押してください(1 回に <?= KM_HERE_QR_MAX ?> 個まで。A4 に 12 個)。
                QR の中身は <code><?= km_e($origin) ?>/?here=…</code> です。同じ URL を NFC のシールに書けば、かざすだけでも開きます
                (印刷のページの画面に URL を出します。印刷には出ません)。
              </div>
            </div>
            <form method="get" action="./map-here-qr.php">
              <input type="hidden" name="print" value="1">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-printer me-1" aria-hidden="true"></i>印刷用に並べる</button>
                <span class="text-body-secondary fs-7">出入口・階段・部屋・建物のうち、名前のある地点だけ並べています。</span>
              </div>
              <div class="row">
                <?php foreach ($candidates as $floor => $list): ?>
                  <div class="col-12 col-md-6 col-xl-4">
                    <div class="card mb-3">
                      <div class="card-header"><h3 class="card-title mb-0"><?= km_e($floorLabels[$floor] ?? $floor) ?> <span class="text-body-secondary fs-7">(<?= count($list) ?>)</span></h3></div>
                      <div class="card-body km-here-qr-list">
                        <?php foreach ($list as $node): ?>
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="ids[]" value="<?= km_e($node['id']) ?>" id="here-<?= km_e($node['id']) ?>">
                            <label class="form-check-label" for="here-<?= km_e($node['id']) ?>"><?= km_e($node['name']) ?></label>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </form>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
