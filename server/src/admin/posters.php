<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/posters.php';
require_once dirname(__DIR__) . '/lib/poster-import.php';
require_once dirname(__DIR__) . '/lib/map-events.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/node-extras.php';

/**
 * ポスター・広告と、地点の URL(2026-10-21、利用者の指示。lib/posters.php)。
 *
 * - 出し方: 表示しない / イベント中のみ / 常に表示
 * - ポスター: 画像(ファイルか URL)・見出し・説明・URL・地点。保存の前にプレビューで見た目を確かめる
 * - 地点の URL: 地点の詳細に出すリンク(ポスターとは別。出し方の 3 段階に関わらず出る)
 *
 * 公開ページでの出方: 地点の詳細(地図)・地図の右上の「🖼 ポスター」・一覧のページ(/posters.php)。
 */

$errors = [];
$notice = null;
$dbError = null;
$editId = isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int) $_GET['edit'] : null;
// 失敗したときに入れた値を残す(選び直しで全部打ち直させない)
$form = null;

try {
    $pdo = km_db();
    km_posters_ensure_tables($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');

        if ($formAction === 'mode') {
            $mode = (string) ($_POST['mode'] ?? '');
            try {
                km_poster_mode_save($pdo, $mode);
                km_admin_log_record('settings', 'poster.mode', KM_POSTER_MODES[$mode]);
                header('Location: ./posters.php?saved=mode', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($formAction === 'tutorial_end') {
            try {
                km_tutorial_end_save($pdo, (string) ($_POST['end_text'] ?? ''), (string) ($_POST['end_survey_url'] ?? ''), isset($_POST['end_phone_qr']));
                km_admin_log_record('settings', 'poster.tutorial_end');
                header('Location: ./posters.php?saved=tutorial_end#tutorial-end', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($formAction === 'save') {
            $posterId = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
            $source = (string) ($_POST['image_source'] ?? 'keep');
            $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
            $form = [
                'id' => $posterId,
                'title' => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'linkUrl' => (string) ($_POST['link_url'] ?? ''),
                'nodeId' => (string) ($_POST['node_id'] ?? ''),
                'projectNo' => (string) ($_POST['project_no'] ?? ''),
                'sortOrder' => (string) ($_POST['sort_order'] ?? '0'),
                'isEnabled' => isset($_POST['is_enabled']),
                'imageSource' => $source,
                'imageUrl' => $imageUrl,
            ];
            try {
                $fields = km_poster_validate($form);
                $image = null;
                if ($source === 'file') {
                    $image = ['bytes' => km_poster_read_upload($_FILES['image_file'] ?? []), 'sourceUrl' => null];
                } elseif ($source === 'url') {
                    $image = ['bytes' => km_poster_fetch_remote($imageUrl), 'sourceUrl' => $imageUrl];
                }
                $savedId = km_poster_save($pdo, $posterId, $fields, $image);
                km_admin_log_record('content', $posterId === null ? 'poster.create' : 'poster.update', $fields['title']);
                header('Location: ./posters.php?saved=' . ($posterId === null ? 'created' : 'updated') . '#poster-' . $savedId, true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
                $editId = $posterId;
            }
        }

        if ($formAction === 'toggle') {
            $posterId = (int) ($_POST['id'] ?? 0);
            $enabled = ($_POST['enabled'] ?? '') === '1';
            km_poster_set_enabled($pdo, $posterId, $enabled);
            km_admin_log_record('content', 'poster.update', '#' . $posterId . ($enabled ? ' を出す' : ' を止める'));
            header('Location: ./posters.php?saved=toggled#poster-' . $posterId, true, 303);
            exit;
        }

        if ($formAction === 'delete') {
            try {
                $title = km_poster_delete($pdo, (int) ($_POST['id'] ?? 0));
                km_admin_log_record('content', 'poster.delete', $title);
                header('Location: ./posters.php?saved=deleted', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($formAction === 'link_save') {
            $nodeId = (string) ($_POST['node_id'] ?? '');
            try {
                km_node_link_set($pdo, $nodeId, (string) ($_POST['url'] ?? ''), (string) ($_POST['label'] ?? ''));
                km_admin_log_record('content', 'map.node_link', $nodeId);
                header('Location: ./posters.php?saved=link#node-links', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        // 部屋のいいねを 0 に戻す(2026-10-22。lib/node-extras.php)
        if ($formAction === 'likes_reset') {
            $likeNode = (string) ($_POST['node_uuid'] ?? '');
            if (km_node_uuid_valid($likeNode)) {
                km_node_likes_ensure_table($pdo);
                $removed = km_node_likes_reset($pdo, $likeNode);
                km_admin_log_record('content', 'node.likes_reset', $likeNode . '(' . $removed . ' 件)');
            }
            header('Location: ./posters.php?saved=likes_reset#node-likes', true, 303);
            exit;
        }

        if ($formAction === 'link_delete') {
            $nodeId = (string) ($_POST['node_id'] ?? '');
            km_node_link_delete($pdo, $nodeId);
            km_admin_log_record('content', 'map.node_link', '外した ' . $nodeId);
            header('Location: ./posters.php?saved=link_removed#node-links', true, 303);
            exit;
        }
    }

    $notice = [
        'mode' => '出し方を保存しました。公開ページを開き直すと反映されます。',
        'created' => 'ポスターを足しました。',
        'updated' => 'ポスターを保存しました。',
        'toggled' => 'ポスターの出し入れを変えました。',
        'deleted' => 'ポスターを消しました(画像も消しました)。',
        'link' => '地点の URL を保存しました。',
        'link_removed' => '地点の URL を外しました。',
        'tutorial_end' => 'チュートリアルの終わりの知らせを保存しました。',
        'likes_reset' => 'いいねを 0 に戻しました。',
    ][(string) ($_GET['saved'] ?? '')] ?? null;

    $mode = km_poster_mode($pdo);
    try {
        $activeEvents = km_map_event_active($pdo);
    } catch (Throwable $exception) {
        $activeEvents = [];
    }
    $showingNow = km_poster_mode_shows($mode, $activeEvents !== []);
    $posters = km_posters_all($pdo);
    $links = km_node_links_all($pdo);
    $nodeOptions = km_poster_node_options($pdo);
    // 企画の番号 → 地点(2026-10-23。管理アプリで振った番号。lib/map-node-projects.php)
    require_once __DIR__ . '/../lib/map-node-projects.php';
    $projectNodes = km_map_project_nodes(km_map_projects_by_node_id($pdo));
    $tutorialEnd = km_tutorial_end_config($pdo);
    $stats = km_poster_stats_totals($pdo);
    km_node_likes_ensure_table($pdo);
    $likeTotals = km_node_likes_totals($pdo);
} catch (Throwable $exception) {
    error_log('posters.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
    $mode = KM_POSTER_MODE_DEFAULT;
    $activeEvents = [];
    $showingNow = false;
    $posters = [];
    $links = [];
    $nodeOptions = [];
    $projectNodes = [];
    $tutorialEnd = ['text' => KM_TUTORIAL_END_TEXT_DEFAULT, 'ownSurveyUrl' => '', 'surveyUrl' => '', 'phoneQr' => true];
    $stats = [];
    $likeTotals = [];
}
// 広告(地点なし・出しているもの)の数と、1 件が選ばれる割合(全部同じ重みなので 1 / 件数。lib/posters.php)
$adCount = count(array_filter($posters, static fn (array $p): bool => $p['nodeId'] === '' && $p['projectNo'] === '' && $p['isEnabled']));
$adShare = (int) round(km_poster_ad_share($adCount) * 100);

$nodeNames = [];
foreach ($nodeOptions as $option) {
    $nodeNames[$option['id']] = $option['name'] . '(' . km_poster_floor_label($option['floor']) . ')';
}
$nodeLabel = static fn (string $id): string => $id === '' ? '—' : ($nodeNames[$id] ?? $id . '(地図に無い)');

// 編集中のポスター。失敗したときは入れた値を優先する
$editing = null;
if ($editId !== null) {
    foreach ($posters as $poster) {
        if ($poster['id'] === $editId) {
            $editing = $poster;
        }
    }
}
$value = static function (string $key, $default = '') use ($form, $editing) {
    if ($form !== null) {
        return $form[$key] ?? $default;
    }
    if ($editing !== null) {
        return $editing[$key] ?? $default;
    }
    return $default;
};
$isEdit = $editing !== null;
$imageSource = (string) $value('imageSource', $isEdit ? 'keep' : 'file');
if (!$isEdit && $imageSource === 'keep') {
    $imageSource = 'file';
}
$enabledChecked = $form !== null ? (bool) $form['isEnabled'] : ($editing['isEnabled'] ?? true);

$KM_PAGE = [
    'nav' => 'posters',
    'titleKey' => 'page.posters.title',
    'title' => 'ポスター・広告 | KosenMap 管理',
    'h1Key' => 'page.posters.h1',
    'h1' => 'ポスター・広告',
    'crumbs' => [
        ['key' => 'page.posters.h1', 'text' => 'ポスター・広告'],
    ],
];

/** 地点を選ぶ欄(ポスターと地点の URL で同じもの)。絞り込みは assets/js/posters.js */
$nodeSelect = static function (string $name, string $id, string $selected, bool $allowEmpty) use ($nodeOptions): void {
    $byFloor = [];
    foreach ($nodeOptions as $option) {
        $byFloor[km_poster_floor_label($option['floor'])][] = $option;
    }
    ?>
    <input type="search" class="form-control form-control-sm mb-1" placeholder="地点の名前で絞り込む" data-km-node-filter="<?= km_e($id) ?>" aria-label="地点を絞り込む">
    <select class="form-select" name="<?= km_e($name) ?>" id="<?= km_e($id) ?>" size="6" <?= $allowEmpty ? '' : 'required' ?>>
      <?php if ($allowEmpty): ?>
        <option value="" <?= $selected === '' ? 'selected' : '' ?>>(地点に置かない)</option>
      <?php endif; ?>
      <?php foreach ($byFloor as $floorLabel => $options): ?>
        <optgroup label="<?= km_e($floorLabel) ?>">
          <?php foreach ($options as $option): ?>
            <option value="<?= km_e($option['id']) ?>" <?= $selected === $option['id'] ? 'selected' : '' ?>><?= km_e($option['name']) ?>(<?= km_e($floorLabel) ?>)</option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <?php
};

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

            <div class="row">
              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title">出し方</h3></div>
                  <form method="post" class="card-body">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="mode">
                    <?php foreach (KM_POSTER_MODES as $modeValue => $modeLabel): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode" id="km-poster-mode-<?= km_e($modeValue) ?>" value="<?= km_e($modeValue) ?>" <?= $mode === $modeValue ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-poster-mode-<?= km_e($modeValue) ?>"><?= km_e($modeLabel) ?></label>
                      </div>
                    <?php endforeach; ?>
                    <p class="form-text mb-3">
                      「イベント中のみ」は、<a href="./map-events.php">イベントモード</a>のどれかが有効で期間内のときだけ出します。
                      止めたポスター(一覧の「止める」)は、どの出し方でも出しません。
                    </p>
                    <button type="submit" class="btn btn-primary">保存</button>
                  </form>
                  <div class="card-footer fs-7">
                    いまは
                    <?php if ($showingNow): ?>
                      <span class="badge text-bg-success">公開ページに出しています</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">出していません</span>
                    <?php endif; ?>
                    (<?= km_e(KM_POSTER_MODES[$mode]) ?><?= $mode === 'event' ? ($activeEvents === [] ? '・いまイベントはありません' : '・イベント中: ' . km_e(implode(' / ', array_column($activeEvents, 'name')))) : '' ?>)
                  </div>
                </div>
              </div>
              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title">公開ページでの出方</h3></div>
                  <div class="card-body fs-7">
                    <ul class="mb-2">
                      <li><strong>地図の地点の詳細</strong>に、その地点のポスター(画像・見出し・説明・URL)が出ます。ポスターのある地点の名前には 🖼 が付きます。</li>
                      <li>地図の右上の <strong>🖼️</strong>(出しているポスターがあるときだけ出ます)から、ポスターを並べた<strong>一覧のページ</strong>を開けます。画像を押すと、地図のその地点へ移って詳細を開きます。</li>
                      <li><strong>地点に置かないポスターは「広告」</strong>です。チュートリアル(操作の案内)が終わったときに<strong>必ず 1 件</strong>を出します。どれを出すかは<strong>同じ重み(<?= (int) KM_POSTER_AD_WEIGHT ?>%)</strong>で選ぶので、広告が 1 件なら 100%、2 件なら 50% です(利用規約の第 7 条)。<?= $adCount > 0 ? 'いまは出している広告が ' . $adCount . ' 件で、それぞれ ' . $adShare . '%。' : 'いま出している広告はありません。' ?>一覧のページにも出ます。</li>
                      <li>広告を出した回数と、リンクを押した人数(同じ端末は 1 人)を数えます(下の一覧の「数」。数だけで、誰のものかは残しません)。</li>
                      <li>広告を閉じたら(広告が無いときはすぐ)、下の「チュートリアルの終わりの知らせ」を出します。</li>
                    </ul>
                    <a class="btn btn-sm btn-outline-primary" href="<?= km_e(km_site_url(null, '/posters.php')) ?>" target="_blank" rel="noopener">一覧のページを開く</a>
                    <span class="text-body-secondary ms-1">(「表示しない」の間は「いまはありません」と出ます)</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-12 col-xxl-7">
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title">ポスター(<?= count($posters) ?> 枚)</h3></div>
                  <div class="card-body p-0">
                    <?php if ($posters === []): ?>
                      <p class="text-body-secondary fs-7 p-3 mb-0">まだありません。右(または下)のフォームから足してください。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr><th scope="col">画像</th><th scope="col">見出し・地点・URL</th><th scope="col">数<span class="fw-normal fs-7">(合計 / 7 日)</span></th><th scope="col">並び</th><th scope="col">状態</th><th scope="col"></th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($posters as $poster): ?>
                              <tr id="poster-<?= (int) $poster['id'] ?>" class="<?= $editId === $poster['id'] ? 'table-active' : '' ?>">
                                <td class="km-poster-thumb-cell">
                                  <img class="km-poster-thumb" src="./api/poster-image.php?id=<?= (int) $poster['id'] ?>&amp;v=<?= (int) $poster['updatedEpoch'] ?>" alt="" loading="lazy" width="<?= (int) $poster['width'] ?>" height="<?= (int) $poster['height'] ?>">
                                </td>
                                <td>
                                  <div class="fw-bold"><?= km_e($poster['title']) ?></div>
                                  <?php if ($poster['projectNo'] !== ''): ?>
                                    <div class="fs-7"><span class="badge text-bg-info">企画 <?= km_e($poster['projectNo']) ?></span> <?= km_e(implode('、', array_map($nodeLabel, $projectNodes[$poster['projectNo']] ?? []))) ?: '<span class="text-danger">この番号の地点が地図にありません(管理アプリで番号を振って取り込んでください)</span>' ?></div>
                                  <?php elseif ($poster['nodeId'] === ''): ?>
                                    <div class="fs-7"><span class="badge text-bg-warning">広告</span> チュートリアルの終わりに<?= $poster['isEnabled'] && $adShare > 0 ? ' ' . $adShare . '%' : '' ?></div>
                                  <?php else: ?>
                                    <div class="fs-7"><i class="bi bi-geo-alt"></i> <?= km_e($nodeLabel($poster['nodeId'])) ?></div>
                                  <?php endif; ?>
                                  <?php if ($poster['linkUrl'] !== ''): ?>
                                    <div class="fs-7 text-truncate km-poster-url"><i class="bi bi-link-45deg"></i> <a href="<?= km_e($poster['linkUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= km_e($poster['linkUrl']) ?></a></div>
                                  <?php endif; ?>
                                  <div class="fs-7 text-body-secondary"><?= (int) $poster['width'] ?>×<?= (int) $poster['height'] ?>px<?= $poster['sourceUrl'] !== '' ? '・URL から取り込み' : '' ?></div>
                                </td>
                                <td class="fs-7 text-nowrap">
                                  <?php $stat = $stats[$poster['id']] ?? ['impressions' => 0, 'clicks' => 0, 'impressions7' => 0, 'clicks7' => 0]; ?>
                                  <?php if ($poster['nodeId'] === '' && $poster['projectNo'] === ''): ?>
                                    <div title="広告として出した回数">表示 <?= (int) $stat['impressions'] ?> 回 / <?= (int) $stat['impressions7'] ?></div>
                                  <?php endif; ?>
                                  <?php if ($poster['linkUrl'] !== ''): ?>
                                    <div title="リンクを押した人数(同じ端末は 1 人)">リンク <?= (int) $stat['clicks'] ?> 人 / <?= (int) $stat['clicks7'] ?></div>
                                  <?php endif; ?>
                                  <?php if (($poster['nodeId'] !== '' || $poster['projectNo'] !== '') && $poster['linkUrl'] === ''): ?>—<?php endif; ?>
                                </td>
                                <td><?= (int) $poster['sortOrder'] ?></td>                                <td>
                                  <form method="post" class="m-0">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $poster['id'] ?>">
                                    <input type="hidden" name="enabled" value="<?= $poster['isEnabled'] ? '0' : '1' ?>">
                                    <?php if ($poster['isEnabled']): ?>
                                      <span class="badge text-bg-success d-block mb-1">出す</span>
                                      <button type="submit" class="btn btn-sm btn-outline-secondary">止める</button>
                                    <?php else: ?>
                                      <span class="badge text-bg-secondary d-block mb-1">止めている</span>
                                      <button type="submit" class="btn btn-sm btn-outline-success">出す</button>
                                    <?php endif; ?>
                                  </form>
                                </td>
                                <td class="text-nowrap">
                                  <a class="btn btn-sm btn-outline-primary" href="./posters.php?edit=<?= (int) $poster['id'] ?>#poster-form">編集</a>
                                  <form method="post" class="d-inline" data-km-confirm="「<?= km_e($poster['title']) ?>」を消します。画像も消え、戻せません。よろしいですか?">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $poster['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">消す</button>
                                  </form>
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

              <div class="col-12 col-xxl-5">
                <div class="card mb-4" id="poster-form">
                  <div class="card-header">
                    <h3 class="card-title"><?= $isEdit ? 'ポスターを直す' : 'ポスターを足す' ?></h3>
                    <?php if ($isEdit): ?>
                      <div class="card-tools"><a class="btn btn-sm btn-outline-secondary" href="./posters.php#poster-form">新しく足す</a></div>
                    <?php endif; ?>
                  </div>
                  <form method="post" enctype="multipart/form-data" class="card-body" data-km-poster-form>
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="save">
                    <?php if ($isEdit): ?>
                      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                    <?php endif; ?>

                    <fieldset class="mb-3">
                      <legend class="form-label fs-6 mb-1">画像</legend>
                      <?php if ($isEdit): ?>
                        <div class="form-check form-check-inline">
                          <input class="form-check-input" type="radio" name="image_source" id="km-src-keep" value="keep" <?= $imageSource === 'keep' ? 'checked' : '' ?>>
                          <label class="form-check-label" for="km-src-keep">今の画像のまま</label>
                        </div>
                      <?php endif; ?>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="image_source" id="km-src-file" value="file" <?= $imageSource === 'file' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-src-file">ファイルを選ぶ</label>
                      </div>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="image_source" id="km-src-url" value="url" <?= $imageSource === 'url' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="km-src-url">URL から</label>
                      </div>
                      <div class="mt-2" data-km-src-panel="file">
                        <input class="form-control" type="file" name="image_file" accept="image/png,image/jpeg,image/gif,image/webp" data-km-image-file>
                      </div>
                      <div class="mt-2" data-km-src-panel="url">
                        <div class="input-group">
                          <input class="form-control" type="url" name="image_url" maxlength="<?= KM_POSTER_URL_MAX ?>" placeholder="https://… (画像そのものの URL)" value="<?= km_e((string) $value('imageUrl')) ?>" data-km-image-url>
                          <button class="btn btn-outline-primary" type="button" data-km-image-url-preview>プレビュー</button>
                        </div>
                        <div class="form-text">保存すると、サーバーが画像を取りに行って<strong>こちらに保存</strong>します(公開ページで外部の画像を直に出さないため)。</div>
                      </div>
                      <div class="form-text">PNG / JPEG / GIF / WebP、<?= km_e(km_upload_format_size(KM_POSTER_MAX_BYTES)) ?>・縦横 <?= KM_POSTER_MAX_DIMENSION ?>px まで。PDF のポスターは画像に書き出してから選んでください。</div>
                      <div class="form-text text-danger" data-km-image-error hidden></div>
                    </fieldset>

                    <div class="mb-3">
                      <label class="form-label" for="km-poster-title">見出し</label>
                      <input class="form-control" type="text" id="km-poster-title" name="title" maxlength="<?= KM_POSTER_TITLE_MAX ?>" required value="<?= km_e((string) $value('title')) ?>" data-km-preview-input="title">
                    </div>
                    <div class="mb-3">
                      <label class="form-label" for="km-poster-description">説明(任意)</label>
                      <textarea class="form-control" id="km-poster-description" name="description" rows="2" maxlength="<?= KM_POSTER_DESCRIPTION_MAX ?>" data-km-preview-input="description"><?= km_e((string) $value('description')) ?></textarea>
                    </div>
                    <div class="mb-3">
                      <label class="form-label" for="km-poster-link">URL(任意)</label>
                      <input class="form-control" type="url" id="km-poster-link" name="link_url" maxlength="<?= KM_POSTER_URL_MAX ?>" placeholder="https://…" value="<?= km_e((string) $value('linkUrl')) ?>" data-km-preview-input="link">
                      <div class="form-text">「くわしく見る」のリンクになります(新しいタブで開きます)。</div>
                    </div>
                    <div class="mb-3">
                      <label class="form-label" for="km-poster-node">地点(任意)</label>
                      <?php $nodeSelect('node_id', 'km-poster-node', (string) $value('nodeId'), true); ?>
                      <div class="form-text">この地点の詳細にポスターが出ます。一覧のページで画像を押すと、ここへ移ります。<strong>地点に置かないと「広告」</strong>になり、チュートリアルの終わりに出ます(広告どうしは同じ重みで選びます)。</div>
                    </div>
                    <div class="mb-3">
                      <label class="form-label" for="km-poster-project">企画の番号(任意)</label>
                      <input class="form-control" type="text" id="km-poster-project" name="project_no" maxlength="<?= KM_MAP_PROJECT_NUMBER_MAX_LENGTH ?>" placeholder="例: A-1" value="<?= km_e((string) $value('projectNo')) ?>" list="km-project-numbers">
                      <datalist id="km-project-numbers"><?php foreach (array_keys($projectNodes) as $number): ?><option value="<?= km_e((string) $number) ?>"><?php endforeach; ?></datalist>
                      <div class="form-text">管理アプリで地点に振った番号です。入れると、<strong>その番号の地点(いくつあっても全部)</strong>にポスターが出ます(上の「地点」より先)。</div>
                    </div>
                    <div class="row g-2 mb-3 align-items-end">
                      <div class="col-5">
                        <label class="form-label" for="km-poster-sort">並び順</label>
                        <input class="form-control" type="number" id="km-poster-sort" name="sort_order" step="1" value="<?= km_e((string) $value('sortOrder', '0')) ?>">
                      </div>
                      <div class="col-7">
                        <div class="form-check mb-2">
                          <input class="form-check-input" type="checkbox" id="km-poster-enabled" name="is_enabled" value="1" <?= $enabledChecked ? 'checked' : '' ?>>
                          <label class="form-check-label" for="km-poster-enabled">出す(止めると、どの出し方でも出ません)</label>
                        </div>
                      </div>
                    </div>

                    <div class="mb-3">
                      <div class="form-label mb-1">プレビュー<span class="text-body-secondary fs-7">(地点の詳細と一覧のページでの見え方)</span></div>
                      <div class="km-poster-preview" data-km-preview>
                        <div class="km-poster-preview-image">
                          <img alt="" data-km-preview-img <?= $isEdit ? 'src="./api/poster-image.php?id=' . (int) $editing['id'] . '&amp;v=' . (int) $editing['updatedEpoch'] . '"' : 'hidden' ?>>
                          <span class="km-poster-preview-empty" data-km-preview-empty <?= $isEdit ? 'hidden' : '' ?>>画像を選ぶとここに出ます</span>
                        </div>
                        <div class="km-poster-preview-body">
                          <div class="km-poster-preview-title" data-km-preview-title></div>
                          <div class="km-poster-preview-desc" data-km-preview-desc></div>
                          <div class="km-poster-preview-place" data-km-preview-place></div>
                          <span class="km-poster-preview-link" data-km-preview-link hidden>くわしく見る ↗</span>
                        </div>
                      </div>
                      <div class="form-text" data-km-preview-meta></div>
                    </div>

                    <button type="submit" class="btn btn-primary"><?= $isEdit ? '保存' : '足す' ?></button>
                    <?php if ($isEdit): ?>
                      <a class="btn btn-outline-secondary" href="./posters.php">やめる</a>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
            </div>

            <div class="card mb-4" id="poster-import">
              <div class="card-header"><h3 class="card-title">まとめて取り込む(模擬店のページから広告を作る)</h3></div>
              <form class="card-body" data-km-poster-import>
                <?= km_csrf_field() ?>
                <p class="fs-7 text-body-secondary">
                  番号の入ったページ(高専祭のサイトの模擬店など)を 1 つずつ読み、<strong>店の名前・コメント・ポスターの画像から広告(地点に置かないポスター)</strong>を作ります。リンクはそのページです。
                  同じページの広告が既にあれば作り直さず、名前・コメント(と、変わっていれば画像)だけを直します(出し入れ・並び順・地点は今のまま)。
                  無い番号(404)は飛ばします。<strong>番号 0 は使いません</strong>(1 から)。
                </p>
                <div class="row g-2 align-items-end mb-3">
                  <div class="col-12 col-lg-6">
                    <label class="form-label" for="km-import-template">ページの URL(<code>%N</code> に番号が入る)</label>
                    <input class="form-control" type="url" id="km-import-template" name="template" maxlength="<?= KM_POSTER_URL_MAX ?>" value="<?= km_e(KM_POSTER_IMPORT_TEMPLATE_DEFAULT) ?>" required>
                  </div>
                  <div class="col-6 col-lg-2">
                    <label class="form-label" for="km-import-first">最初の番号</label>
                    <input class="form-control" type="number" id="km-import-first" name="first" min="<?= KM_POSTER_IMPORT_MIN ?>" max="<?= KM_POSTER_IMPORT_MAX ?>" value="<?= KM_POSTER_IMPORT_FIRST_DEFAULT ?>" required>
                  </div>
                  <div class="col-6 col-lg-2">
                    <label class="form-label" for="km-import-last">最後の番号</label>
                    <input class="form-control" type="number" id="km-import-last" name="last" min="<?= KM_POSTER_IMPORT_MIN ?>" max="<?= KM_POSTER_IMPORT_MAX ?>" value="<?= KM_POSTER_IMPORT_LAST_DEFAULT ?>" required>
                  </div>
                  <div class="col-12 col-lg-2">
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" id="km-import-enabled" name="enabled" value="1" checked>
                      <label class="form-check-label" for="km-import-enabled">作った広告をすぐ出す</label>
                    </div>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary" data-km-import-start>取り込む</button>
                <button type="button" class="btn btn-outline-secondary" data-km-import-stop hidden>止める</button>
                <div class="mt-3" data-km-import-progress hidden>
                  <progress class="w-100" max="1" value="0" data-km-import-bar></progress>
                  <div class="fs-7" data-km-import-summary></div>
                  <ol class="fs-7 mt-2 mb-2 km-import-log" data-km-import-log></ol>
                  <a class="btn btn-sm btn-outline-primary" href="./posters.php" data-km-import-reload hidden>一覧を読み直す</a>
                </div>
              </form>
            </div>

            <div class="card mb-4" id="tutorial-end">
              <div class="card-header"><h3 class="card-title">チュートリアルの終わりの知らせ</h3></div>
              <form method="post" class="card-body">
                <?= km_csrf_field() ?>
                <input type="hidden" name="form_action" value="tutorial_end">
                <p class="fs-7 text-body-secondary">
                  チュートリアルが終わったとき(最後まで進んだ・スキップした)に出します。広告を出したときは、広告を閉じたあとに出ます。
                  見出しは「🎉 チュートリアルが終わりました」で固定です。<strong>ポスターの出し方(3 段階)とは関係なく出ます。</strong>
                </p>
                <div class="mb-3">
                  <label class="form-label" for="km-end-text">本文</label>
                  <textarea class="form-control" id="km-end-text" name="end_text" rows="2" maxlength="<?= KM_TUTORIAL_END_TEXT_MAX ?>"><?= km_e($tutorialEnd['text']) ?></textarea>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="km-end-survey">アンケートの URL(https)</label>
                  <input class="form-control" type="url" id="km-end-survey" name="end_survey_url" maxlength="255" placeholder="https://forms.gle/…" value="<?= km_e($tutorialEnd['ownSurveyUrl']) ?>">
                  <div class="form-text">
                    「このページのアンケートを募集中です。こちら」と QR を出します。空なら <a href="./test-mode.php">テスト用アカウント</a>の画面で決めたアンケートの URL を使います
                    <?php if ($tutorialEnd['ownSurveyUrl'] === '' && $tutorialEnd['surveyUrl'] !== ''): ?>(いまは <code><?= km_e($tutorialEnd['surveyUrl']) ?></code>)<?php elseif ($tutorialEnd['surveyUrl'] === ''): ?>(いまはどちらも空なので、アンケートの行は出しません)<?php endif; ?>。
                  </div>
                </div>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" id="km-end-phone" name="end_phone_qr" value="1" <?= $tutorialEnd['phoneQr'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="km-end-phone">「スマホでもマップを見ることができます」と、地図の QR(<?= km_e(km_site_url(null, '/')) ?>)を出す</label>
                </div>
                <button type="submit" class="btn btn-primary">保存</button>
              </form>
            </div>

            <div class="card mb-4" id="node-links">
              <div class="card-header"><h3 class="card-title">地点の URL(<?= count($links) ?> 件)</h3></div>
              <div class="card-body">
                <p class="fs-7 text-body-secondary">
                  地点の詳細に、リンクを 1 本出します(研究室のページ・模擬店の案内など)。<strong>ポスターの出し方とは関係なく、いつも出ます。</strong>
                  地図を取り込み直しても消えません(地点の番号が変わったときは付け直してください)。
                </p>
                <div class="row">
                  <div class="col-12 col-xl-7">
                    <?php if ($links === []): ?>
                      <p class="text-body-secondary fs-7">まだありません。</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle">
                          <thead><tr><th scope="col">地点</th><th scope="col">URL・リンクの文字</th><th scope="col"></th></tr></thead>
                          <tbody>
                            <?php foreach ($links as $link): ?>
                              <tr>
                                <td><?= km_e($nodeLabel((string) $link['nodeId'])) ?></td>
                                <td class="km-poster-url">
                                  <a class="text-break" href="<?= km_e((string) $link['url']) ?>" target="_blank" rel="noopener noreferrer"><?= km_e((string) $link['url']) ?></a>
                                  <div class="fs-7 text-body-secondary"><?= km_e((string) $link['label'] !== '' ? (string) $link['label'] : '(既定:くわしく見る)') ?></div>
                                </td>
                                <td>
                                  <form method="post" class="m-0" data-km-confirm="この地点の URL を外します。よろしいですか?">
                                    <?= km_csrf_field() ?>
                                    <input type="hidden" name="form_action" value="link_delete">
                                    <input type="hidden" name="node_id" value="<?= km_e((string) $link['nodeId']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">外す</button>
                                  </form>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="col-12 col-xl-5">
                    <form method="post">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="link_save">
                      <div class="mb-2">
                        <label class="form-label" for="km-link-node">地点</label>
                        <?php $nodeSelect('node_id', 'km-link-node', '', false); ?>
                      </div>
                      <div class="mb-2">
                        <label class="form-label" for="km-link-url">URL</label>
                        <input class="form-control" type="url" id="km-link-url" name="url" maxlength="<?= KM_POSTER_URL_MAX ?>" placeholder="https://…" required>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="km-link-label">リンクの文字(任意)</label>
                        <input class="form-control" type="text" id="km-link-label" name="label" maxlength="<?= KM_NODE_LINK_LABEL_MAX ?>" placeholder="くわしく見る">
                      </div>
                      <button type="submit" class="btn btn-primary">保存(同じ地点なら置き換え)</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>

            <div class="card mb-4" id="node-likes">
              <div class="card-header"><h3 class="card-title">部屋のいいね(<?= count($likeTotals) ?> 地点)</h3></div>
              <div class="card-body">
                <p class="fs-7 text-body-secondary">
                  Android アプリの部屋の詳細で付けられる「いいね」の数です(2026-10-22)。<strong>だれが付けたかは持っていません</strong>
                  (端末ごとの印を鍵つきの印にしてだけ持ち、1 端末 1 地点 1 つ。同じ送り元から 1 時間に <?= KM_NODE_LIKE_SOURCE_LIMIT ?> まで)。
                  部屋の詳細には、この画面で地点に置いたポスターが写真として出ます。
                </p>
                <?php if ($likeTotals === []): ?>
                  <p class="text-body-secondary fs-7">まだありません。</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead><tr><th scope="col">地点</th><th scope="col" class="text-end">いいね</th><th scope="col"></th></tr></thead>
                      <tbody>
                        <?php foreach ($likeTotals as $likeRow): ?>
                          <tr>
                            <td><?= km_e($likeRow['name'] !== '' ? $likeRow['name'] : $likeRow['uuid'] . '(地図に無い)') ?></td>
                            <td class="text-end"><?= (int) $likeRow['count'] ?></td>
                            <td>
                              <form method="post" class="m-0" data-km-confirm="この地点のいいねを 0 に戻します。よろしいですか?">
                                <?= km_csrf_field() ?>
                                <input type="hidden" name="form_action" value="likes_reset">
                                <input type="hidden" name="node_uuid" value="<?= km_e($likeRow['uuid']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">0 に戻す</button>
                              </form>
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
        <?php /* プレビューと地点の絞り込み。地点の名前は選ぶ欄の文字から取る(別に渡さない) */ ?>
        <script src="<?= km_e(km_asset('./assets/js/posters.js')) ?>"></script>
        <?php /* まとめて取り込む(番号を 1 つずつ admin/api/poster-import.php へ送る) */ ?>
        <script src="<?= km_e(km_asset('./assets/js/poster-import.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
