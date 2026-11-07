<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/search-aliases.php';
require_once dirname(__DIR__) . '/lib/english-names.php';
// CSV でまとめて書き出す・取り込む(2026-11-07、plan.md 段 2-1)
require_once dirname(__DIR__) . '/lib/search-alias-csv.php';
// 地点の URL(km_node_link_*)。2026-11-02 にポスターの画面から移した
require_once dirname(__DIR__) . '/lib/posters.php';

/**
 * 読み・別名(2026-10-30、高専祭の振り返りのフェーズ 4 の 1。lib/search-aliases.php)。
 *
 * 地点・一時地点・ポスターごとに「れっつぷれいでんさん、レッツプレイ電算、電算部」のような読み・別名を入れる。
 * 公開の地図の検索・最初の画面・案内チャット・アプリの検索で、名前と同じように探せる(名前より少し下に並ぶ)。
 * 英語の名前(2026-10-30、振り返りのフェーズ 6 の 1。lib/english-names.php)も同じ行で入れる。公開の地図を英語で見るときの名前(無ければ日本語のまま)。
 * 一度に出すのは絞り込んだ 150 件まで(入力欄の数が PHP の上限 max_input_vars を超えないように)。変えた行だけ保存する。
 *
 * **地点の URL もここ**(2026-11-02、利用者の指示「無理やり配置した項目を的確な場所へ」)。地点の詳細に出すリンクで、
 * 以前はポスターの画面にあった(ポスターの出し方とは関係なく出る)。ページの名前は「地点の読み・別名・URL」。
 *
 * **CSV でまとめて**(2026-11-07、plan.md 段 2-1): 書き出し(?export=csv)と取り込み(上げる → 変わる行だけ見せる → 取り込む)。
 * 決まりは lib/search-alias-csv.php(空のセルは変えない・消すのは「-」・見せたあとに変わった行は上書きしない)。
 */

$KM_PAGE = [
    'nav' => 'searchAliases',
    'titleKey' => 'page.searchAliases.title',
    'title' => '地点の読み・別名・URL | KosenMap 管理',
    'h1Key' => 'page.searchAliases.h1',
    'h1' => '地点の読み・別名・URL',
    'crumbs' => [
        ['key' => 'side.groupMap', 'text' => '地図'],
        ['key' => 'page.searchAliases.h1', 'text' => '地点の読み・別名・URL'],
    ],
];

const KM_SEARCH_ALIAS_PAGE_ROWS = 150;
/** 取り込みの計画(見せてから「取り込む」まで)をセッションに置く鍵 */
const KM_ALIAS_IMPORT_SESSION_KEY = 'km_alias_import';

$kindLabels = ['node' => '地点', 'poi' => '一時地点', 'poster' => 'ポスター'];
$kind = (string) ($_GET['kind'] ?? $_POST['kind'] ?? 'node');
if (!isset($kindLabels[$kind])) {
    $kind = 'node';
}
$query = trim((string) ($_GET['q'] ?? ''));
$onlyFilled = ($_GET['filled'] ?? '') === '1';

$errors = [];
$rows = [];
$aliases = ['node' => [], 'poi' => [], 'poster' => []];
$englishNames = ['node' => [], 'poi' => [], 'poster' => []];
$floorLabels = [];
$links = [];
$nodeOptions = [];
$importAction = null;
$importPlan = null;
$linkNotice = ['link' => '地点の URL を保存しました。', 'link_removed' => '地点の URL を外しました。'][(string) ($_GET['saved'] ?? '')] ?? null;

try {
    $pdo = km_db();
    km_search_aliases_ensure_table($pdo);
    km_english_names_ensure_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        if (!km_csrf_verify()) {
            $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
        } elseif ($formAction === 'link_save') {
            // 地点の URL(2026-10-21。2026-11-02 にポスターの画面から移した。記録の名前は前のまま)
            km_posters_ensure_tables($pdo);
            $nodeId = (string) ($_POST['node_id'] ?? '');
            try {
                km_node_link_set($pdo, $nodeId, (string) ($_POST['url'] ?? ''), (string) ($_POST['label'] ?? ''));
                km_admin_log_record('content', 'map.node_link', $nodeId);
                header('Location: ./search-aliases.php?saved=link#node-links', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        } elseif ($formAction === 'link_delete') {
            km_posters_ensure_tables($pdo);
            $nodeId = (string) ($_POST['node_id'] ?? '');
            km_node_link_delete($pdo, $nodeId);
            km_admin_log_record('content', 'map.node_link', '外した ' . $nodeId);
            header('Location: ./search-aliases.php?saved=link_removed#node-links', true, 303);
            exit;
        } elseif (in_array($formAction, ['import_preview', 'import_apply', 'import_cancel'], true)) {
            // CSV の取り込みは、並べる行(取り込める id)を読んだあとで扱う(下)
            $importAction = $formAction;
        } else {
            $saved = 0;
            $new = is_array($_POST['a'] ?? null) ? $_POST['a'] : [];
            $old = is_array($_POST['o'] ?? null) ? $_POST['o'] : [];
            foreach ($new as $id => $text) {
                $id = (string) $id;
                $text = is_string($text) ? $text : '';
                // 変えた行だけ(開いている間にほかの人が直した行を、古い値で上書きしない)
                if (!km_search_alias_target_valid($kind, $id) || $text === (string) ($old[$id] ?? '')) {
                    continue;
                }
                km_search_alias_save($pdo, $kind, $id, $text);
                $saved++;
            }
            // 英語の名前(変えた行だけ)
            $newEn = is_array($_POST['e'] ?? null) ? $_POST['e'] : [];
            $oldEn = is_array($_POST['eo'] ?? null) ? $_POST['eo'] : [];
            foreach ($newEn as $id => $text) {
                $id = (string) $id;
                $text = is_string($text) ? $text : '';
                if (!km_search_alias_target_valid($kind, $id) || $text === (string) ($oldEn[$id] ?? '')) {
                    continue;
                }
                km_english_name_save($pdo, $kind, $id, $text);
                $saved++;
            }
            if ($saved > 0) {
                km_admin_log_record('content', 'search-aliases.save', $kind . ' ' . $saved);
            }
            header('Location: ./search-aliases.php?' . http_build_query(['kind' => $kind, 'q' => (string) ($_POST['q'] ?? ''), 'filled' => $onlyFilled ? '1' : null, 'done' => $saved]), true, 302);
            exit;
        }
    }

    $aliases = km_search_aliases_all($pdo);
    km_posters_ensure_tables($pdo);
    $links = km_node_links_all($pdo);
    $nodeOptions = km_poster_node_options($pdo);
    $englishNames = km_english_names_all($pdo);
    foreach ($pdo->query('SELECT id, label FROM km_map_floors ORDER BY sort_order, id')->fetchAll() as $row) {
        $floorLabels[(string) $row['id']] = (string) $row['label'];
    }
    $floorText = static fn (string $floor): string => $floorLabels[$floor] ?? $floor;
    if ($kind === 'node') {
        $typeLabels = ['room' => '部屋', 'facility' => '建物', 'entrance' => '出入口', 'stairs' => '階段'];
        foreach ($pdo->query("SELECT id, floor_id AS floor, name, type FROM km_map_nodes WHERE type IN ('room', 'facility', 'entrance', 'stairs') AND name <> ''")->fetchAll() as $row) {
            $rows[] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'where' => $floorText((string) $row['floor']) . ' · ' . ($typeLabels[(string) $row['type']] ?? ''), 'sort' => (string) $row['floor']];
        }
    } elseif ($kind === 'poi') {
        try {
            foreach ($pdo->query('SELECT p.id, p.name, p.floor_id AS floor, e.name AS event FROM km_map_event_pois p JOIN km_map_events e ON e.id = p.event_id ORDER BY p.id')->fetchAll() as $row) {
                $rows[] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'where' => $floorText((string) $row['floor']) . ' · ' . (string) $row['event'], 'sort' => (string) $row['floor']];
            }
        } catch (Throwable $exception) {
            // イベントの表がまだ無い
        }
    } else {
        try {
            foreach ($pdo->query('SELECT id, title FROM km_posters ORDER BY sort_order, id')->fetchAll() as $row) {
                $rows[] = ['id' => (string) $row['id'], 'name' => (string) $row['title'], 'where' => 'ポスター #' . (int) $row['id'], 'sort' => ''];
            }
        } catch (Throwable $exception) {
            // ポスターの表がまだ無い
        }
    }

    // ---- CSV の取り込み(2026-11-07)。取り込めるのは、いまの種類で画面に並ぶもの全部(絞り込みに関係なく)
    $targets = [];
    foreach ($rows as $row) {
        $targets[$row['id']] = $row['name'];
    }
    $backTo = './search-aliases.php?' . http_build_query(['kind' => $kind]);
    if ($importAction === 'import_cancel') {
        unset($_SESSION[KM_ALIAS_IMPORT_SESSION_KEY]);
        header('Location: ' . $backTo, true, 303);
        exit;
    }
    if ($importAction === 'import_preview') {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $errors[] = 'CSV のファイルを選んでください。';
        } elseif ((int) $file['size'] > KM_ALIAS_CSV_MAX_BYTES) {
            $errors[] = 'CSV が大きすぎます(1MB まで)。';
        } else {
            $plan = km_alias_csv_plan((string) file_get_contents((string) $file['tmp_name']), $kind, $targets, $aliases[$kind], $englishNames[$kind]);
            $_SESSION[KM_ALIAS_IMPORT_SESSION_KEY] = ['kind' => $kind, 'token' => bin2hex(random_bytes(16)), 'at' => time(), 'plan' => $plan];
            header('Location: ' . $backTo . '#km-alias-import', true, 303);
            exit;
        }
    }
    if ($importAction === 'import_apply') {
        $stash = $_SESSION[KM_ALIAS_IMPORT_SESSION_KEY] ?? null;
        $token = (string) ($_POST['token'] ?? '');
        if (!is_array($stash) || ($stash['kind'] ?? '') !== $kind || !hash_equals((string) ($stash['token'] ?? ''), $token)
            || time() - (int) ($stash['at'] ?? 0) > KM_ALIAS_CSV_PLAN_TTL) {
            $errors[] = '取り込みの確かめが古くなりました。CSV をもう一度上げてください。';
        } else {
            $applied = 0;
            $conflicts = 0;
            $pdo->beginTransaction();
            try {
                foreach ((array) ($stash['plan']['changes'] ?? []) as $change) {
                    $id = (string) $change['id'];
                    // 見せたときの値から変わっていたら書かない(開いている間にほかの人が直した)
                    if (km_search_alias_text($aliases[$kind][$id] ?? []) !== (string) $change['oldReadings']
                        || (string) ($englishNames[$kind][$id] ?? '') !== (string) $change['oldEnglish']) {
                        $conflicts++;
                        continue;
                    }
                    if ($change['newReadings'] !== null) {
                        km_search_alias_save($pdo, $kind, $id, (string) $change['newReadings']);
                    }
                    if ($change['newEnglish'] !== null) {
                        km_english_name_save($pdo, $kind, $id, (string) $change['newEnglish']);
                    }
                    $applied++;
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }
            unset($_SESSION[KM_ALIAS_IMPORT_SESSION_KEY]);
            km_admin_log_record('content', 'search-aliases.import', "{$kind} {$applied} 件" . ($conflicts > 0 ? "・食い違い {$conflicts} 件" : ''));
            header('Location: ' . $backTo . '&' . http_build_query(['imported' => $applied, 'conflicts' => $conflicts]), true, 303);
            exit;
        }
    }
    $stash = $_SESSION[KM_ALIAS_IMPORT_SESSION_KEY] ?? null;
    if (is_array($stash) && ($stash['kind'] ?? '') === $kind && time() - (int) ($stash['at'] ?? 0) <= KM_ALIAS_CSV_PLAN_TTL) {
        $importPlan = $stash;
    }
} catch (Throwable $exception) {
    error_log('admin/search-aliases.php: ' . $exception->getMessage());
    $errors[] = km_admin_error_message($exception, 'データベースに接続できません。');
}

// 絞り込み(名前・読みのどちらかに含む)と、読みのある行だけ
$needle = mb_strtolower($query);
$rows = array_values(array_filter($rows, static function (array $row) use ($needle, $onlyFilled, $aliases, $englishNames, $kind): bool {
    $current = trim(implode('、', $aliases[$kind][$row['id']] ?? []) . ' ' . ($englishNames[$kind][$row['id']] ?? ''));
    if ($onlyFilled && $current === '') {
        return false;
    }
    return $needle === '' || str_contains(mb_strtolower($row['name'] . ' ' . $current), $needle);
}));
usort($rows, static fn (array $a, array $b): int => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
// CSV で書き出す(絞り込みはそのまま効く・150 件で切らない)。取り込みと同じ見出し
if (($_GET['export'] ?? '') === 'csv' && $errors === []) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="km-aliases-' . $kind . '-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    echo km_alias_csv_export($kind, $rows, $aliases[$kind], $englishNames[$kind]);
    exit;
}
$total = count($rows);
$rows = array_slice($rows, 0, KM_SEARCH_ALIAS_PAGE_ROWS);
$done = isset($_GET['done']) ? (int) $_GET['done'] : null;
$imported = isset($_GET['imported']) ? (int) $_GET['imported'] : null;
$importConflicts = (int) ($_GET['conflicts'] ?? 0);

$nodeNames = [];
foreach ($nodeOptions as $option) {
    $nodeNames[$option['id']] = $option['name'] . '(' . km_poster_floor_label($option['floor']) . ')';
}
$nodeLabel = static fn (string $id): string => $id === '' ? '—' : ($nodeNames[$id] ?? $id . '(地図に無い)');
/** 地点を選ぶ欄(階ごと・名前で絞れる。絞り込みは assets/js/posters.js) */
$nodeSelectByFloor = [];
foreach ($nodeOptions as $option) {
    $nodeSelectByFloor[km_poster_floor_label($option['floor'])][] = $option;
}

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
            <?php if ($linkNotice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($linkNotice) ?></div>
            <?php endif; ?>
            <?php if ($done !== null): ?>
              <div class="alert alert-success" role="alert"><?= $done > 0 ? km_e($done . ' 件を保存しました。') : '変えた行はありませんでした。' ?></div>
            <?php endif; ?>
            <?php if ($imported !== null): ?>
              <div class="alert <?= $importConflicts > 0 ? 'alert-warning' : 'alert-success' ?>" role="alert">
                <?= km_e('CSV から ' . $imported . ' 件を取り込みました。') ?>
                <?php if ($importConflicts > 0): ?><?= km_e('見せたあとに画面で直された ' . $importConflicts . ' 件は取り込んでいません(もう一度上げると並びます)。') ?><?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-alphabet me-2 mt-1" aria-hidden="true"></i>
              <div>
                漢字の読み・英語の名前のカタカナ・部活の呼び名などを入れると、地図の検索・最初の画面・案内チャット・アプリの検索で探せます
                (例: Let's play Densan! に「れっつぷれいでんさん、レッツプレイ電算、電算部」)。<br>
                区切りは読点「、」かカンマ・改行。1 件に <?= KM_SEARCH_ALIAS_MAX_COUNT ?> 個・1 つ <?= KM_SEARCH_ALIAS_MAX_LENGTH ?> 字まで。
                名前で当たったものより少し下に並びます。打ち間違い(体育舘 → 体育館)は「もしかして」が拾うので、入れなくて構いません。<br>
                「英語の名前」は、公開の地図を英語(右上のアカウントの欄か最初の画面の「🌐 English」)で見るときの名前です。空なら日本語のまま出します。
              </div>
            </div>
            <form method="get" action="./search-aliases.php" class="d-flex flex-wrap align-items-center gap-2 mb-3">
              <div class="btn-group" role="group" aria-label="対象">
                <?php foreach ($kindLabels as $value => $label): ?>
                  <a class="btn btn-outline-primary<?= $value === $kind ? ' active' : '' ?>" href="./search-aliases.php?kind=<?= km_e($value) ?>"><?= km_e($label) ?></a>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="kind" value="<?= km_e($kind) ?>">
              <input type="search" class="form-control w-auto" name="q" value="<?= km_e($query) ?>" placeholder="名前・読み・英語で絞る" aria-label="名前・読み・英語で絞る">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="filled" value="1" id="km-alias-filled"<?= $onlyFilled ? ' checked' : '' ?>>
                <label class="form-check-label" for="km-alias-filled">読みのあるものだけ</label>
              </div>
              <button type="submit" class="btn btn-secondary">絞る</button>
              <span class="text-body-secondary fs-7"><?= km_e($total . ' 件' . ($total > KM_SEARCH_ALIAS_PAGE_ROWS ? '(先頭の ' . KM_SEARCH_ALIAS_PAGE_ROWS . ' 件を出しています。絞ってください)' : '')) ?></span>
            </form>
            <div class="card mb-3" id="km-alias-import">
              <div class="card-header"><h3 class="card-title">CSV でまとめて(<?= km_e($kindLabels[$kind]) ?>)</h3></div>
              <div class="card-body">
                <p class="fs-7 text-body-secondary mb-2">
                  書き出した CSV の <code>readings</code>(読み・別名)・<code>english</code>(英語の名前)を表計算ソフトで埋めて上げます。
                  <strong>空のセルは変えません。</strong>消すときは <code>-</code> だけを書きます。上げるとまず変わる行だけを並べ、「取り込む」を押すまで書きません。
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <a class="btn btn-outline-secondary" href="./search-aliases.php?<?= km_e(http_build_query(['kind' => $kind, 'q' => $query !== '' ? $query : null, 'filled' => $onlyFilled ? '1' : null, 'export' => 'csv'])) ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>CSV を書き出す</a>
                  <form method="post" action="./search-aliases.php?<?= km_e(http_build_query(['kind' => $kind])) ?>" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center gap-2 m-0">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="kind" value="<?= km_e($kind) ?>">
                    <input type="hidden" name="form_action" value="import_preview">
                    <input class="form-control w-auto" type="file" name="csv" accept=".csv,text/csv" required aria-label="取り込む CSV">
                    <button type="submit" class="btn btn-outline-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>上げて確かめる</button>
                  </form>
                </div>
                <?php if ($importPlan !== null): ?>
                  <?php $plan = $importPlan['plan']; ?>
                  <hr>
                  <p class="mb-2">
                    <?= km_e('CSV の ' . (int) $plan['rows'] . ' 行のうち、変わるのは ' . count($plan['changes']) . ' 件(同じ ' . (int) $plan['unchanged'] . ' 件・読めない ' . count($plan['errors']) . ' 件)。まだ書いていません。') ?>
                  </p>
                  <?php if ($plan['errors'] !== []): ?>
                    <details class="mb-2"><summary class="text-warning-emphasis"><?= km_e('読めなかった行(' . count($plan['errors']) . ')') ?></summary>
                      <ul class="fs-7 mb-0"><?php foreach (array_slice($plan['errors'], 0, 50) as $message): ?><li><?= km_e($message) ?></li><?php endforeach; ?></ul>
                    </details>
                  <?php endif; ?>
                  <?php if ($plan['changes'] !== []): ?>
                    <div class="table-responsive mb-2">
                      <table class="table table-sm align-middle mb-0">
                        <thead><tr><th scope="col">名前</th><th scope="col">読み・別名(前 → 後)</th><th scope="col">英語の名前(前 → 後)</th></tr></thead>
                        <tbody>
                          <?php foreach ($plan['changes'] as $change): ?>
                            <tr>
                              <td><?= km_e($change['name']) ?></td>
                              <td class="fs-7"><?php if ($change['newReadings'] === null): ?><span class="text-body-secondary">変えない</span><?php else: ?><span class="text-body-secondary"><?= km_e($change['oldReadings'] !== '' ? $change['oldReadings'] : '(なし)') ?></span> → <strong><?= km_e($change['newReadings'] !== '' ? $change['newReadings'] : '(消す)') ?></strong><?php endif; ?></td>
                              <td class="fs-7" lang="en"><?php if ($change['newEnglish'] === null): ?><span class="text-body-secondary">変えない</span><?php else: ?><span class="text-body-secondary"><?= km_e($change['oldEnglish'] !== '' ? $change['oldEnglish'] : '(なし)') ?></span> → <strong><?= km_e($change['newEnglish'] !== '' ? $change['newEnglish'] : '(消す)') ?></strong><?php endif; ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                  <div class="d-flex gap-2">
                    <?php if ($plan['changes'] !== []): ?>
                      <form method="post" action="./search-aliases.php?<?= km_e(http_build_query(['kind' => $kind])) ?>" class="m-0">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="kind" value="<?= km_e($kind) ?>">
                        <input type="hidden" name="form_action" value="import_apply">
                        <input type="hidden" name="token" value="<?= km_e((string) $importPlan['token']) ?>">
                        <button type="submit" class="btn btn-primary"><?= km_e(count($plan['changes']) . ' 件を取り込む') ?></button>
                      </form>
                    <?php endif; ?>
                    <form method="post" action="./search-aliases.php?<?= km_e(http_build_query(['kind' => $kind])) ?>" class="m-0">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="kind" value="<?= km_e($kind) ?>">
                      <input type="hidden" name="form_action" value="import_cancel">
                      <button type="submit" class="btn btn-outline-secondary">やめる</button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <form method="post" action="./search-aliases.php?<?= km_e(http_build_query(['kind' => $kind, 'filled' => $onlyFilled ? '1' : null])) ?>">
              <?= km_csrf_field() ?>
              <input type="hidden" name="kind" value="<?= km_e($kind) ?>">
              <input type="hidden" name="q" value="<?= km_e($query) ?>">
              <div class="card mb-3">
                <div class="card-body p-0">
                  <table class="table table-sm align-middle mb-0">
                    <thead><tr><th scope="col">名前</th><th scope="col">場所</th><th scope="col">読み・別名</th><th scope="col">英語の名前</th></tr></thead>
                    <tbody>
                      <?php if ($rows === []): ?>
                        <tr><td colspan="4" class="text-body-secondary">ありません。</td></tr>
                      <?php endif; ?>
                      <?php foreach ($rows as $row): ?>
                        <?php $current = implode('、', $aliases[$kind][$row['id']] ?? []); ?>
                        <tr>
                          <td><label for="alias-<?= km_e($row['id']) ?>"><?= km_e($row['name']) ?></label></td>
                          <td class="text-body-secondary fs-7"><?= km_e($row['where']) ?></td>
                          <td>
                            <input type="text" class="form-control form-control-sm" id="alias-<?= km_e($row['id']) ?>" name="a[<?= km_e($row['id']) ?>]" value="<?= km_e($current) ?>" maxlength="<?= KM_SEARCH_ALIAS_TEXT_MAX ?>">
                            <input type="hidden" name="o[<?= km_e($row['id']) ?>]" value="<?= km_e($current) ?>">
                          </td>
                          <?php $currentEn = $englishNames[$kind][$row['id']] ?? ''; ?>
                          <td>
                            <input type="text" class="form-control form-control-sm" name="e[<?= km_e($row['id']) ?>]" value="<?= km_e($currentEn) ?>" maxlength="<?= KM_ENGLISH_NAME_MAX ?>" lang="en" aria-label="<?= km_e($row['name']) ?> の英語の名前" placeholder="(日本語のまま)">
                            <input type="hidden" name="eo[<?= km_e($row['id']) ?>]" value="<?= km_e($currentEn) ?>">
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <button type="submit" class="btn btn-primary"<?= $rows === [] ? ' disabled' : '' ?>><i class="bi bi-save me-1" aria-hidden="true"></i>変えた行を保存</button>
            </form>

            <div class="card mt-4 mb-4" id="node-links">
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
                                  <form method="post" action="./search-aliases.php" class="m-0" data-km-confirm="この地点の URL を外します。よろしいですか?">
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
                    <form method="post" action="./search-aliases.php">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="link_save">
                      <div class="mb-2">
                        <label class="form-label" for="km-link-node">地点</label>
                        <input type="search" class="form-control form-control-sm mb-1" placeholder="地点の名前で絞り込む" data-km-node-filter="km-link-node" aria-label="地点を絞り込む">
                        <select class="form-select" name="node_id" id="km-link-node" size="6" required>
                          <?php foreach ($nodeSelectByFloor as $floorLabel => $options): ?>
                            <optgroup label="<?= km_e($floorLabel) ?>">
                              <?php foreach ($options as $option): ?>
                                <option value="<?= km_e($option['id']) ?>"><?= km_e($option['name']) ?>(<?= km_e($floorLabel) ?>)</option>
                              <?php endforeach; ?>
                            </optgroup>
                          <?php endforeach; ?>
                        </select>
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
          </div>
        </div>
        <!--end::App Content-->
        <?php /* 地点の選ぶ欄の絞り込み(ポスターの画面と同じもの。ポスターの入力欄が無ければそこで終わる) */ ?>
        <script src="<?= km_e(km_asset('./assets/js/posters.js')) ?>"></script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
