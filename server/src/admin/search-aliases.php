<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/search-aliases.php';

/**
 * 読み・別名(2026-10-30、高専祭の振り返りのフェーズ 4 の 1。lib/search-aliases.php)。
 *
 * 地点・一時地点・ポスターごとに「れっつぷれいでんさん、レッツプレイ電算、電算部」のような読み・別名を入れる。
 * 公開の地図の検索・最初の画面・案内チャット・アプリの検索で、名前と同じように探せる(名前より少し下に並ぶ)。
 * 一度に出すのは絞り込んだ 150 件まで(入力欄の数が PHP の上限 max_input_vars を超えないように)。変えた行だけ保存する。
 */

$KM_PAGE = [
    'nav' => 'searchAliases',
    'titleKey' => 'page.searchAliases.title',
    'title' => '読み・別名 | KosenMap 管理',
    'h1Key' => 'page.searchAliases.h1',
    'h1' => '読み・別名',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.searchAliases.h1', 'text' => '読み・別名'],
    ],
];

const KM_SEARCH_ALIAS_PAGE_ROWS = 150;

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
$floorLabels = [];

try {
    $pdo = km_db();
    km_search_aliases_ensure_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!km_csrf_verify()) {
            $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
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
            if ($saved > 0) {
                km_admin_log_record('content', 'search-aliases.save', $kind . ' ' . $saved);
            }
            header('Location: ./search-aliases.php?' . http_build_query(['kind' => $kind, 'q' => (string) ($_POST['q'] ?? ''), 'filled' => $onlyFilled ? '1' : null, 'done' => $saved]), true, 302);
            exit;
        }
    }

    $aliases = km_search_aliases_all($pdo);
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
} catch (Throwable $exception) {
    error_log('admin/search-aliases.php: ' . $exception->getMessage());
    $errors[] = km_admin_error_message($exception, 'データベースに接続できません。');
}

// 絞り込み(名前・読みのどちらかに含む)と、読みのある行だけ
$needle = mb_strtolower($query);
$rows = array_values(array_filter($rows, static function (array $row) use ($needle, $onlyFilled, $aliases, $kind): bool {
    $current = implode('、', $aliases[$kind][$row['id']] ?? []);
    if ($onlyFilled && $current === '') {
        return false;
    }
    return $needle === '' || str_contains(mb_strtolower($row['name'] . ' ' . $current), $needle);
}));
usort($rows, static fn (array $a, array $b): int => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
$total = count($rows);
$rows = array_slice($rows, 0, KM_SEARCH_ALIAS_PAGE_ROWS);
$done = isset($_GET['done']) ? (int) $_GET['done'] : null;

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
            <?php if ($done !== null): ?>
              <div class="alert alert-success" role="alert"><?= $done > 0 ? km_e($done . ' 件を保存しました。') : '変えた行はありませんでした。' ?></div>
            <?php endif; ?>
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-alphabet me-2 mt-1" aria-hidden="true"></i>
              <div>
                漢字の読み・英語の名前のカタカナ・部活の呼び名などを入れると、地図の検索・最初の画面・案内チャット・アプリの検索で探せます
                (例: Let's play Densan! に「れっつぷれいでんさん、レッツプレイ電算、電算部」)。<br>
                区切りは読点「、」かカンマ・改行。1 件に <?= KM_SEARCH_ALIAS_MAX_COUNT ?> 個・1 つ <?= KM_SEARCH_ALIAS_MAX_LENGTH ?> 字まで。
                名前で当たったものより少し下に並びます。打ち間違い(体育舘 → 体育館)は「もしかして」が拾うので、入れなくて構いません。
              </div>
            </div>
            <form method="get" action="./search-aliases.php" class="d-flex flex-wrap align-items-center gap-2 mb-3">
              <div class="btn-group" role="group" aria-label="対象">
                <?php foreach ($kindLabels as $value => $label): ?>
                  <a class="btn btn-outline-primary<?= $value === $kind ? ' active' : '' ?>" href="./search-aliases.php?kind=<?= km_e($value) ?>"><?= km_e($label) ?></a>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="kind" value="<?= km_e($kind) ?>">
              <input type="search" class="form-control w-auto" name="q" value="<?= km_e($query) ?>" placeholder="名前・読みで絞る" aria-label="名前・読みで絞る">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="filled" value="1" id="km-alias-filled"<?= $onlyFilled ? ' checked' : '' ?>>
                <label class="form-check-label" for="km-alias-filled">読みのあるものだけ</label>
              </div>
              <button type="submit" class="btn btn-secondary">絞る</button>
              <span class="text-body-secondary fs-7"><?= km_e($total . ' 件' . ($total > KM_SEARCH_ALIAS_PAGE_ROWS ? '(先頭の ' . KM_SEARCH_ALIAS_PAGE_ROWS . ' 件を出しています。絞ってください)' : '')) ?></span>
            </form>
            <form method="post" action="./search-aliases.php?<?= km_e(http_build_query(['kind' => $kind, 'filled' => $onlyFilled ? '1' : null])) ?>">
              <?= km_csrf_field() ?>
              <input type="hidden" name="kind" value="<?= km_e($kind) ?>">
              <input type="hidden" name="q" value="<?= km_e($query) ?>">
              <div class="card mb-3">
                <div class="card-body p-0">
                  <table class="table table-sm align-middle mb-0">
                    <thead><tr><th scope="col">名前</th><th scope="col">場所</th><th scope="col">読み・別名</th></tr></thead>
                    <tbody>
                      <?php if ($rows === []): ?>
                        <tr><td colspan="3" class="text-body-secondary">ありません。</td></tr>
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
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <button type="submit" class="btn btn-primary"<?= $rows === [] ? ' disabled' : '' ?>><i class="bi bi-save me-1" aria-hidden="true"></i>変えた行を保存</button>
            </form>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
