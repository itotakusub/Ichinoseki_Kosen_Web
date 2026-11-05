<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/map-events.php';
require_once dirname(__DIR__) . '/lib/event-schedule.php';
require_once dirname(__DIR__) . '/lib/stalls.php';
require_once dirname(__DIR__) . '/lib/posters.php';
require_once dirname(__DIR__) . '/lib/poster-archive.php';
require_once dirname(__DIR__) . '/lib/notices.php';
require_once dirname(__DIR__) . '/lib/map-switches.php';
require_once dirname(__DIR__) . '/lib/test-mode.php';
require_once dirname(__DIR__) . '/lib/map-access.php';
require_once dirname(__DIR__) . '/lib/app-map.php';
require_once dirname(__DIR__) . '/lib/app-map-publish.php';
require_once __DIR__ . '/_inc/event-setup-status.php';

/**
 * イベントのセットアップ(2026-11-03、利用者の指示「イベントを設定するときのセットアップ画面
 * (例: 1. 会期の日付と開始時間、終了時間を設定してください。2. …)」)。
 *
 * イベントの準備で行き来していた 8 つほどのページを、番号順の 10 の手順にまとめる。
 * **保存は今のページと同じ関数を呼ぶ**(ここで別の作りをしない)。手順の印は _inc/event-setup-status.php。
 *
 * 一番下の「会期を終える」: イベントを無効にする・アプリのイベント用の配信を止める・
 * **ポスター・広告を全部、控えへ移して消す**(利用者の指示。押したときだけ。lib/poster-archive.php)。
 *
 * 決まり: 新しく作るイベントは無効のまま保存し、有効にするのは 8。アプリへ配る地図に入るのは
 * 有効なイベントだけなので、配信(9)は有効にしたあと。
 */

$errors = [];
$notice = null;
$dbError = null;
$canWrite = (bool) ($KM_ADMIN_CAN_WRITE ?? false);

$eventParam = (string) ($_GET['event'] ?? '');
$eventId = ctype_digit($eventParam) ? (int) $eventParam : null;
$stepParam = (string) ($_GET['step'] ?? '');
$step = ctype_digit($stepParam) && isset(KM_EVENT_SETUP_STEPS[(int) $stepParam]) ? (int) $stepParam : null;

/** 保存のあとは同じ(か次の)段へ戻る(303。再読み込みで二重に送らない) */
$back = static function (?int $id, int $toStep, string $saved, array $extra = []): never {
    $query = http_build_query(['event' => $id ?? 'new', 'step' => $toStep, 'saved' => $saved] + $extra);
    header('Location: ./event-setup.php?' . $query . '#km-setup-step', true, 303);
    exit;
};

try {
    $pdo = km_db();
    km_map_events_ensure_tables($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        $postId = ctype_digit((string) ($_POST['event_id'] ?? '')) ? (int) $_POST['event_id'] : null;
        $current = $postId === null ? null : km_map_event_find($pdo, $postId);
        if ($postId !== null && $current === null) {
            $errors[] = 'そのイベントは見つかりませんでした(消されたかもしれません)。';
        }
        // 今の値に変えたところだけを重ねて保存する(km_map_event_save は全部の項目を書く)
        $saveEvent = static function (array $changes) use ($pdo, $current, $postId): void {
            km_map_event_save($pdo, $postId, array_merge($current ?? [], $changes));
        };

        try {
            if ($errors !== []) {
                // 何もしない(上で断った)
            } elseif ($formAction === 'step1') {
                $fields = [
                    'name' => (string) ($_POST['name'] ?? ''),
                    'startsAt' => (string) ($_POST['starts_at'] ?? ''),
                    'endsAt' => (string) ($_POST['ends_at'] ?? ''),
                ];
                if (trim($fields['startsAt']) === '' || trim($fields['endsAt']) === '') {
                    throw new InvalidArgumentException('開始と終了の日時を両方入れてください(終了が無いと、地図もアプリの配信も自動で終わりません)。');
                }
                if ($current === null) {
                    // 新しく作るときは無効のまま(有効にするのは 8)。教職員名を隠す・イベントの地点だけは今のページの既定と同じ
                    $newId = km_map_event_save($pdo, null, $fields + ['isEnabled' => false, 'hideOccupantNames' => true, 'onlyEventNodes' => true]);
                    km_admin_log_record('content', 'map.event_create', $fields['name'] . '(セットアップ)');
                    $back($newId, 2, 'created');
                }
                $saveEvent($fields);
                km_admin_log_record('content', 'map.event_update', $fields['name'] . '(セットアップ: 日時)');
                $back($postId, 1, 'saved');
            } elseif ($current === null) {
                throw new InvalidArgumentException('先に 1 でイベントを作ってください。');
            } elseif ($formAction === 'step2') {
                $saveEvent(['onlyEventNodes' => isset($_POST['only_event_nodes']), 'hideOccupantNames' => isset($_POST['hide_occupant_names'])]);
                km_admin_log_record('content', 'map.event_update', $current['name'] . '(セットアップ: 地図に出すもの)');
                $back($postId, 2, 'saved');
            } elseif ($formAction === 'poster_mode') {
                $mode = (string) ($_POST['mode'] ?? '');
                km_poster_mode_save($pdo, $mode);
                km_admin_log_record('settings', 'poster.mode', KM_POSTER_MODES[$mode] . '(セットアップ)');
                $back($postId, 4, 'saved');
            } elseif ($formAction === 'poster_bulk') {
                $bulk = km_poster_bulk_plan((string) ($_POST['bulk'] ?? ''), $_POST['ids'] ?? null);
                $changed = km_posters_set_enabled_many($pdo, $bulk['ids'], $bulk['enabled']);
                km_admin_log_record('content', 'poster.bulk', $bulk['label'] . '(' . ($bulk['ids'] === null ? '全部' : count($bulk['ids']) . ' 件') . '・変わったもの ' . $changed . ' 件・セットアップ)');
                $back($postId, 4, 'bulk', ['n' => $changed]);
            } elseif ($formAction === 'step5') {
                $saveEvent(['bannerText' => (string) ($_POST['banner_text'] ?? ''), 'bannerUrl' => (string) ($_POST['banner_url'] ?? '')]);
                $tutorialOn = isset($_POST['tutorial']);
                $noticesOn = isset($_POST['notices']);
                km_map_switches_save($pdo, $tutorialOn, $noticesOn);
                km_admin_log_record('content', 'map.event_update', $current['name'] . '(セットアップ: 上の帯)');
                km_admin_log_record('content', 'map.switches', 'チュートリアル ' . ($tutorialOn ? 'ON' : 'OFF') . '・お知らせ ' . ($noticesOn ? 'ON' : 'OFF') . '(セットアップ)');
                $back($postId, 5, 'saved');
            } elseif ($formAction === 'step6') {
                $collect = isset($_POST['event_collect']);
                km_setting_set($pdo, KM_EVENT_COLLECT_SETTING, $collect ? '1' : '0');
                km_admin_log_record('settings', 'test.settings', 'イベント中の記録を' . ($collect ? '取る' : '取らない') . '(セットアップ)');
                $back($postId, 6, 'saved');
            } elseif ($formAction === 'step7') {
                $access = km_map_access_config();
                $opens = isset($_POST['event_opens_map']);
                km_map_access_save($access['mode'], null, $access['mapMode'], $access['teacherSeesHidden'], $opens);
                km_admin_log_record('settings', 'map.settings', 'イベント中は地図を開ける=' . ($opens ? 'はい' : 'いいえ') . '(セットアップ)');
                $back($postId, 7, 'saved');
            } elseif ($formAction === 'enable' || $formAction === 'disable') {
                $on = $formAction === 'enable';
                $saveEvent(['isEnabled' => $on]);
                km_admin_log_record('content', 'map.event_toggle', ($on ? '有効化 ' : '無効化 ') . $current['name'] . '(セットアップ)');
                $back($postId, $on ? 9 : 8, $on ? 'enabled' : 'disabled');
            } elseif ($formAction === 'republish') {
                $slug = (string) ($_POST['slug'] ?? '');
                if (!in_array($slug, KM_APP_MAP_SLUGS, true)) {
                    throw new InvalidArgumentException('配信先を選び直してください。');
                }
                $published = km_app_map_republish($pdo, km_app_map_config(), $slug);
                km_admin_log_record(
                    'content',
                    'map.publish',
                    $slug . " / 配り直し(セットアップ) / 版{$published['revision']} / 地点{$published['nodes']} / 経路{$published['lines']}"
                    . ' / イベント' . $published['events'] . ' / 期限' . $published['expiresAt']
                );
                $back($postId, 9, 'republished', ['rev' => $published['revision']]);
            } elseif ($formAction === 'finish') {
                // 会期を終える。控え(消すもの)を先に作り、失敗したら何もしない(無効化・停止は戻せるが、順番を一つに決めておく)
                $done = [];
                $extra = [];
                if (isset($_POST['archive_posters'])) {
                    $archived = km_poster_archive_and_clear($pdo, (string) $current['name'], (string) ($KM_USER['name'] ?? ''));
                    if ($archived !== null) {
                        km_admin_log_record('content', 'poster.archive', $archived['count'] . ' 件を控えへ移して消しました(' . $archived['storedName'] . ')');
                        $done[] = 'ポスター・広告 ' . $archived['count'] . ' 件を控えへ移して消した';
                        $extra = ['n' => $archived['count'], 'file' => $archived['storedName'], 'missing' => count($archived['missing'])];
                    }
                }
                if (isset($_POST['disable_event']) && $current['isEnabled']) {
                    $saveEvent(['isEnabled' => false]);
                    $done[] = 'イベントを無効にした';
                }
                if (isset($_POST['pause_event_map'])) {
                    $config = km_app_map_config();
                    if (isset($config['maps'][KM_APP_MAP_EVENT_SLUG]) && !km_app_map_is_paused((array) $config['maps'][KM_APP_MAP_EVENT_SLUG])) {
                        km_app_map_write_config(km_app_map_set_paused($config, KM_APP_MAP_EVENT_SLUG, true));
                        km_admin_log_record('content', 'map.pause', KM_APP_MAP_EVENT_SLUG . '(セットアップ: 会期を終える)');
                        $done[] = 'アプリのイベント用の配信を止めた';
                    }
                }
                km_admin_log_record('content', 'map.event_finish', $current['name'] . ': ' . ($done === [] ? '何もしなかった' : implode('・', $done)));
                $back($postId, 10, 'finished', $extra + ['done' => count($done)]);
            } else {
                throw new InvalidArgumentException('操作を選び直してください。');
            }
        } catch (InvalidArgumentException | KmUserError $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('event-setup.php: ' . $exception->getMessage());
            $errors[] = km_admin_error_message($exception, '保存できませんでした。');
        }
        if ($postId !== null) {
            $eventId = $postId;
        }
    }

    // ---- 手順の印のための事実を集める ----
    $events = km_map_events_all($pdo);
    $now = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $event = null;
    foreach ($events as $row) {
        if ($row['id'] === $eventId) {
            $event = $row;
        }
    }
    if ($eventId !== null && $event === null && $errors === []) {
        $errors[] = 'そのイベントは見つかりませんでした。下から選び直すか、新しく作ってください。';
    }
    // 選ぶ欄は、いま開催中 → これから → 終わったもの の順
    usort($events, static function (array $a, array $b) use ($now): int {
        $rank = static fn (array $e): int => $e['isActive'] ? 0 : ((($e['endsAt'] ?? null) === null || (string) $e['endsAt'] > $now) ? 1 : 2);
        return [$rank($a), (string) ($a['startsAt'] ?? '')] <=> [$rank($b), (string) ($b['startsAt'] ?? '')];
    });

    $schedule = km_event_schedule_all($pdo);
    $inWindow = $event === null || ($event['startsAt'] ?? null) === null || ($event['endsAt'] ?? null) === null
        ? []
        : array_values(array_filter($schedule, static fn (array $s): bool => $s['startsAt'] >= (string) $event['startsAt'] && $s['startsAt'] < (string) $event['endsAt']));
    $stalls = array_values(array_filter(km_stalls_all($pdo), static fn (array $s): bool => !$s['revoked']));
    $posters = km_posters_all($pdo);
    $posterMode = km_poster_mode($pdo);
    $switches = km_map_switches($pdo);
    $publishedNotices = array_values(array_filter(km_notices_all($pdo), static fn (array $n): bool => $n['isPublished']));
    $currentNotices = km_notices_current(date('Y-m-d'), $publishedNotices);
    $eventCollect = km_event_collect_enabled($pdo);
    $access = km_map_access_config();
    $accessPath = km_map_access_config_path();
    $accessWritable = is_file($accessPath) ? is_writable($accessPath) : is_writable(dirname($accessPath));
    $appConfig = km_app_map_config();
    $releases = [];
    foreach (km_app_map_release_status($appConfig) as $release) {
        $releases[$release['slug']] = $release + ['paused' => km_app_map_is_paused((array) ($appConfig['maps'][$release['slug']] ?? []))];
    }
    $appWindow = km_map_event_overlay_for_app($pdo);
    $appWindowIds = array_map(static fn (array $e): int => (int) $e['id'], $appWindow['overlay']['events'] ?? []);
    $archivePreview = km_poster_archive_preview($pdo);

    $steps = km_event_setup_steps([
        'event' => $event,
        'now' => $now,
        'scheduleInWindow' => count($inWindow),
        'schedulePublic' => count(array_filter($inWindow, static fn (array $s): bool => $s['isPublic'])),
        'stalls' => count($stalls),
        'posters' => count($posters),
        'postersOn' => count(array_filter($posters, static fn (array $p): bool => $p['isEnabled'])),
        'posterMode' => $posterMode,
        'tutorial' => $switches['tutorial'],
        'notices' => $switches['notices'],
        'noticesCurrent' => count($currentNotices),
        'eventCollect' => $eventCollect,
        'access' => ['mode' => $access['mode'], 'mapMode' => $access['mapMode'], 'hasPassword' => $access['passwordHash'] !== null, 'eventOpensMap' => $access['eventOpensMap']],
        'releases' => $releases,
        'appWindowIds' => $appWindowIds,
    ]);
} catch (Throwable $exception) {
    error_log('event-setup.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, 'データベースに接続できませんでした。');
    $events = [];
    $event = null;
    $steps = [];
}

// 開く段: 指定が無ければ最初の「!」(無ければ 10)。イベントが無ければ 1
if ($event === null) {
    $step = 1;
} elseif ($step === null) {
    $step = 10;
    foreach ($steps as $n => $s) {
        if ($n < 10 && $s['state'] === 'warn') {
            $step = $n;
            break;
        }
    }
}

$notice = match ((string) ($_GET['saved'] ?? '')) {
    'created' => 'イベントを作りました(まだ無効です。8 で有効にします)。続けて 2 の「地図に出すもの」を決めてください。',
    'saved' => '保存しました。',
    'bulk' => 'ポスターをまとめて切り替えました(変わったもの ' . (int) ($_GET['n'] ?? 0) . ' 件)。',
    'enabled' => 'イベントを有効にしました。開始の時刻から地図に出ます。続けて 9 でアプリへ配り直してください。',
    'disabled' => 'イベントを無効にしました。地図は元に戻っています。',
    'republished' => '最新の内容で版 ' . (int) ($_GET['rev'] ?? 0) . ' を配り直しました。アプリは開いている間 15 分以内に受け取ります。',
    'finished' => '会期を終えました。'
        . (isset($_GET['file']) && is_string($_GET['file']) && preg_match(KM_POSTER_ARCHIVE_NAME_PATTERN, $_GET['file']) === 1
            ? 'ポスター・広告 ' . (int) ($_GET['n'] ?? 0) . ' 件を控え(' . $_GET['file'] . ')へ移して消しました。控えは「バックアップ」の画面からダウンロードできます。'
                . ((int) ($_GET['missing'] ?? 0) > 0 ? '(画像が見つからなかったもの ' . (int) $_GET['missing'] . ' 件は、項目だけ控えに入れました)' : '')
            : ''),
    default => null,
};

$stateBadge = static fn (string $state): string => match ($state) {
    'ok' => '<span class="badge text-bg-success km-setup-mark" title="済み">✓</span>',
    'warn' => '<span class="badge text-bg-warning km-setup-mark" title="足りない">!</span>',
    default => '<span class="badge bg-body-secondary text-body-secondary border km-setup-mark" title="任意">―</span>',
};
$dt = static fn (?string $value): string => $value === null || $value === '' ? '' : str_replace(' ', 'T', substr($value, 0, 16));
$short = static fn (?string $value): string => $value === null || $value === '' ? '—' : substr($value, 0, 16);
$size = static fn (int $bytes): string => $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%d KB', (int) ceil($bytes / 1024));
$eventQuery = $event === null ? 'new' : (string) $event['id'];
$stepUrl = static fn (int $n): string => './event-setup.php?' . http_build_query(['event' => $eventQuery, 'step' => $n]) . '#km-setup-step';
$ended = $event !== null && ($event['endsAt'] ?? null) !== null && (string) $event['endsAt'] <= ($now ?? '');
// 会期を終える欄の✓は、会期が終わってから最初から付ける(準備中に押してもポスターが消えないように)
$checkIfEnded = $ended ? 'checked' : '';

$KM_PAGE = [
    'nav' => 'eventSetup',
    'titleKey' => 'page.eventSetup.title',
    'title' => 'イベントのセットアップ | KosenMap 管理',
    'h1Key' => 'page.eventSetup.h1',
    'h1' => 'イベントのセットアップ',
    'crumbs' => [
        ['key' => 'side.groupEvent', 'text' => '会期'],
        ['key' => 'page.eventSetup.h1', 'text' => 'イベントのセットアップ'],
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
            <?php if (!$canWrite): ?>
              <div class="alert alert-info" role="alert">書き込みの権限が無いので、見るだけです。</div>
            <?php endif; ?>

            <?php // ---- どのイベントか ---- ?>
            <div class="card mb-4">
              <div class="card-body d-flex flex-wrap align-items-end gap-2">
                <form method="get" class="d-flex flex-wrap align-items-end gap-2 me-auto">
                  <div>
                    <label class="form-label mb-1" for="km-setup-event">準備するイベント</label>
                    <select class="form-select" id="km-setup-event" name="event">
                      <option value="new" <?= $event === null ? 'selected' : '' ?>>(新しく作る)</option>
                      <?php foreach ($events as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= $event !== null && $row['id'] === $event['id'] ? 'selected' : '' ?>>
                          <?= km_e($row['name']) ?>(<?= km_e($short($row['startsAt'] ?? null)) ?> 〜 <?= km_e($short($row['endsAt'] ?? null)) ?>)<?= $row['isActive'] ? ' ・開催中' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="submit" class="btn btn-outline-primary">開く</button>
                </form>
                <a class="btn btn-outline-secondary btn-sm" href="./map-events.php">イベントモードの一覧</a>
              </div>
            </div>

            <div class="row g-4 mb-4" id="km-setup-step">
              <?php // ---- 左: 手順 ---- ?>
              <div class="col-12 col-lg-4 col-xxl-3">
                <nav class="list-group km-setup-steps" aria-label="セットアップの手順">
                  <?php foreach ($steps as $n => $s): ?>
                    <?php $reachable = $event !== null || $n === 1; ?>
                    <?php if ($reachable): ?>
                      <a class="list-group-item list-group-item-action d-flex gap-2<?= $n === $step ? ' active' : '' ?>" href="<?= km_e($stepUrl($n)) ?>" <?= $n === $step ? 'aria-current="step"' : '' ?>>
                    <?php else: ?>
                      <span class="list-group-item d-flex gap-2 text-body-secondary" aria-disabled="true">
                    <?php endif; ?>
                        <?= $stateBadge($s['state']) ?>
                        <span class="flex-grow-1 text-break">
                          <span class="d-block fw-semibold"><?= $n ?>. <?= km_e($s['title']) ?></span>
                          <span class="d-block fs-7 km-setup-reason"><?= km_e($s['reason']) ?></span>
                        </span>
                    <?php if ($reachable): ?>
                      </a>
                    <?php else: ?>
                      </span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </nav>
              </div>

              <?php // ---- 右: 選んだ段(狭い画面では手順の一覧より先に出す) ---- ?>
              <div class="col-12 col-lg-8 col-xxl-9 order-first order-lg-0">
                <div class="card h-100">
                  <div class="card-header">
                    <h3 class="card-title"><?= $step ?>. <?= km_e(KM_EVENT_SETUP_STEPS[$step]) ?></h3>
                    <?php if ($event !== null): ?>
                      <div class="card-tools fs-7 text-body-secondary"><?= km_e($event['name']) ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="card-body">
                    <?php $hidden = '<input type="hidden" name="event_id" value="' . ($event === null ? '' : (int) $event['id']) . '">'; ?>

                    <?php if ($step === 1): ?>
                      <p class="fs-7 text-body-secondary">会期の名前と、開始・終了の日時を入れてください。<strong>終了の日時を過ぎると、地図は自動で元に戻ります</strong>(アプリへ配る地図の期限もここに合わせます)。</p>
                      <form method="post">
                        <?= km_csrf_field() ?><?= $hidden ?>
                        <input type="hidden" name="form_action" value="step1">
                        <fieldset <?= $canWrite ? '' : 'disabled' ?>>
                          <div class="mb-3">
                            <label class="form-label" for="km-setup-name">イベント名</label>
                            <input class="form-control" id="km-setup-name" name="name" maxlength="100" required value="<?= km_e((string) ($event['name'] ?? '')) ?>" placeholder="高専祭 2026">
                          </div>
                          <div class="row g-3 mb-3">
                            <div class="col-12 col-md-6">
                              <label class="form-label" for="km-setup-start">開始日時</label>
                              <input type="datetime-local" class="form-control" id="km-setup-start" name="starts_at" required value="<?= km_e($dt($event['startsAt'] ?? null)) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                              <label class="form-label" for="km-setup-end">終了日時</label>
                              <input type="datetime-local" class="form-control" id="km-setup-end" name="ends_at" required value="<?= km_e($dt($event['endsAt'] ?? null)) ?>">
                            </div>
                          </div>
                          <button type="submit" class="btn btn-primary"><?= $event === null ? '作って次へ' : '保存' ?></button>
                          <?php if ($event === null): ?>
                            <span class="fs-7 text-body-secondary ms-2">作った時点では無効のままです(8 で有効にします)。</span>
                          <?php endif; ?>
                        </fieldset>
                      </form>

                    <?php elseif ($step === 2): ?>
                      <p class="fs-7 text-body-secondary">会期中の地図の出し方です。通行止め・臨時の地点・臨時名称は地図編集で直します。</p>
                      <ul class="fs-7">
                        <li>通行止め <?= (int) $event['closureCount'] ?> 件・臨時の地点 <?= (int) $event['poiCount'] ?> 件・臨時名称 <?= (int) $event['aliasCount'] ?> 件・表示しない地点 <?= (int) $event['hiddenCount'] ?> 件</li>
                      </ul>
                      <a class="btn btn-outline-primary btn-sm mb-3" href="./map-editor.php?event=<?= (int) $event['id'] ?>"><i class="bi bi-pin-map me-1" aria-hidden="true"></i>地図編集で通行止め・臨時の地点を直す</a>
                      <form method="post">
                        <?= km_csrf_field() ?><?= $hidden ?>
                        <input type="hidden" name="form_action" value="step2">
                        <fieldset <?= $canWrite ? '' : 'disabled' ?>>
                          <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="km-setup-only" name="only_event_nodes" <?= $event['onlyEventNodes'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="km-setup-only"><strong>期間中はイベントの地点だけを出す</strong>
                              <span class="d-block fs-7 text-body-secondary">企画の番号がある地点・臨時の地点・トイレ・階段・出入口だけを出します(経路は通れます)。</span></label>
                          </div>
                          <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="km-setup-hide" name="hide_occupant_names" <?= $event['hideOccupantNames'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="km-setup-hide"><strong>期間中は教職員氏名を隠す</strong>
                              <span class="d-block fs-7 text-body-secondary">外部の来場者が見るため、解除の合言葉を知っている人にも出しません。</span></label>
                          </div>
                          <button type="submit" class="btn btn-primary">保存</button>
                        </fieldset>
                      </form>

                    <?php elseif ($step === 3): ?>
                      <p class="fs-7 text-body-secondary">会期の中(<?= km_e($short($event['startsAt'] ?? null)) ?> 〜 <?= km_e($short($event['endsAt'] ?? null)) ?>)に始まる予定です。足す・直すはスケジュールの画面で行います。</p>
                      <?php if ($inWindow === []): ?>
                        <p class="mb-3">会期の中に始まる予定はまだありません。</p>
                      <?php else: ?>
                        <div class="table-responsive mb-3">
                          <table class="table table-sm mb-0">
                            <thead><tr><th>始まり</th><th>企画</th><th>見出し</th><th>公開</th></tr></thead>
                            <tbody>
                              <?php foreach (array_slice($inWindow, 0, 8) as $item): ?>
                                <tr><td class="text-nowrap"><?= km_e($short($item['startsAt'])) ?></td><td><?= km_e((string) ($item['projectNo'] ?? '')) ?></td><td><?= km_e($item['title']) ?></td><td><?= $item['isPublic'] ? '公開' : '—' ?></td></tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                        <?php if (count($inWindow) > 8): ?><p class="fs-7 text-body-secondary">ほか <?= count($inWindow) - 8 ?> 件</p><?php endif; ?>
                      <?php endif; ?>
                      <a class="btn btn-outline-primary btn-sm" href="./schedule.php">スケジュールを開く</a>
                      <a class="btn btn-outline-secondary btn-sm" href="./schedule.php#schedule-csv">CSV でまとめて取り込む</a>

                    <?php elseif ($step === 4): ?>
                      <div class="mb-4">
                        <h4 class="fs-6 fw-semibold">屋台</h4>
                        <p class="mb-2">使っている屋台 <?= count($stalls) ?> 件。</p>
                        <a class="btn btn-outline-primary btn-sm" href="./stalls.php">屋台を開く</a>
                      </div>
                      <h4 class="fs-6 fw-semibold">ポスター・広告</h4>
                      <form method="post" class="d-flex flex-wrap align-items-center gap-3 mb-3">
                        <?= km_csrf_field() ?><?= $hidden ?>
                        <input type="hidden" name="form_action" value="poster_mode">
                        <fieldset class="d-flex flex-wrap align-items-center gap-3" <?= $canWrite ? '' : 'disabled' ?>>
                          <span class="fs-7">出し方:</span>
                          <?php foreach (KM_POSTER_MODES as $modeValue => $modeLabel): ?>
                            <span class="form-check form-check-inline m-0">
                              <input class="form-check-input" type="radio" name="mode" id="km-setup-mode-<?= km_e($modeValue) ?>" value="<?= km_e($modeValue) ?>" <?= $posterMode === $modeValue ? 'checked' : '' ?>>
                              <label class="form-check-label" for="km-setup-mode-<?= km_e($modeValue) ?>"><?= km_e($modeLabel) ?></label>
                            </span>
                          <?php endforeach; ?>
                          <button type="submit" class="btn btn-sm btn-primary">保存</button>
                        </fieldset>
                      </form>
                      <?php $kmPosterBulkList = true; require __DIR__ . '/_inc/partials/poster-bulk.php'; ?>
                      <a class="btn btn-outline-primary btn-sm mt-3" href="./posters.php">ポスター・広告を開く(足す・直す)</a>

                    <?php elseif ($step === 5): ?>
                      <form method="post">
                        <?= km_csrf_field() ?><?= $hidden ?>
                        <input type="hidden" name="form_action" value="step5">
                        <fieldset <?= $canWrite ? '' : 'disabled' ?>>
                          <div class="mb-3">
                            <label class="form-label" for="km-setup-banner">地図の上の帯の文</label>
                            <input class="form-control" id="km-setup-banner" name="banner_text" maxlength="255" value="<?= km_e((string) ($event['bannerText'] ?? '')) ?>" placeholder="高専祭 開催中!順路にご協力ください">
                            <div class="form-text">空欄ならイベント名を出します。</div>
                          </div>
                          <div class="mb-3">
                            <label class="form-label" for="km-setup-banner-url">帯のリンク</label>
                            <input class="form-control" id="km-setup-banner-url" name="banner_url" maxlength="255" value="<?= km_e((string) ($event['bannerUrl'] ?? '')) ?>" placeholder="/schedule.php">
                            <div class="form-text">このサイトの中のパスだけ(/ で始まるもの)。</div>
                          </div>
                          <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" id="km-setup-tutorial" name="tutorial" <?= $switches['tutorial'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="km-setup-tutorial">地図を開いたときにチュートリアル(操作の案内)を出す</label>
                          </div>
                          <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="km-setup-notices" name="notices" <?= $switches['notices'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="km-setup-notices">地図を開いたときにお知らせを出す(いま出るもの <?= count($currentNotices) ?> 件)</label>
                          </div>
                          <button type="submit" class="btn btn-primary">保存</button>
                        </fieldset>
                      </form>
                      <a class="btn btn-outline-secondary btn-sm mt-3" href="./notices.php">お知らせとチュートリアルを開く(文を書く)</a>

                    <?php elseif ($step === 6): ?>
                      <form method="post">
                        <?= km_csrf_field() ?><?= $hidden ?>
                        <input type="hidden" name="form_action" value="step6">
                        <fieldset <?= $canWrite ? '' : 'disabled' ?>>
                          <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="km-setup-collect" name="event_collect" <?= $eventCollect ? 'checked' : '' ?>>
                            <label class="form-check-label" for="km-setup-collect">会期中は、来場者(ログインしていない人も)の操作も記録する</label>
                            <div class="form-text">最初の画面で「情報を収集するのを許可します」(チェック済み)を出し、外した人の操作は記録しません。誰の操作かは残しません。記録は<a href="./test-mode.php">テスト用アカウント</a>の画面で見られます。</div>
                          </div>
                          <button type="submit" class="btn btn-primary">保存</button>
                        </fieldset>
                      </form>

                    <?php elseif ($step === 7): ?>
                      <ul class="mb-3">
                        <li>地図: <?= $access['mapMode'] === 'password' ? '合言葉で開く' : '誰でも見られる' ?></li>
                        <li>教職員名: <?= km_e(match ($access['mode']) { 'public' => '出す', 'password' => '合言葉で出す', default => '出さない' }) ?></li>
                        <li>合言葉: <?= $access['passwordHash'] !== null ? '決めてある' : '<strong class="text-danger">まだ無い</strong>' ?></li>
                      </ul>
                      <?php if ($accessWritable): ?>
                        <form method="post">
                          <?= km_csrf_field() ?><?= $hidden ?>
                          <input type="hidden" name="form_action" value="step7">
                          <fieldset <?= $canWrite ? '' : 'disabled' ?>>
                            <div class="form-check mb-2">
                              <input class="form-check-input" type="checkbox" id="km-setup-opens" name="event_opens_map" <?= $access['eventOpensMap'] ? 'checked' : '' ?>>
                              <label class="form-check-label" for="km-setup-opens">会期中は、地図を合言葉なしで出す</label>
                              <div class="form-text">効くのは地図だけで、教職員名の錠は変えません(教職員名は 2 の「隠す」も効きます)。</div>
                            </div>
                            <button type="submit" class="btn btn-primary">保存</button>
                          </fieldset>
                        </form>
                      <?php else: ?>
                        <div class="alert alert-warning fs-7">設定のファイル(config/map-access.local.php)に書けないので、ここでは変えられません。</div>
                      <?php endif; ?>
                      <a class="btn btn-outline-secondary btn-sm mt-3" href="./map-settings.php">地図の公開設定を開く(合言葉を変える)</a>

                    <?php elseif ($step === 8): ?>
                      <p>いまの状態: <strong><?= km_e($steps[8]['reason'] ?? '') ?></strong></p>
                      <p class="fs-7 text-body-secondary">有効にしても、地図に出るのは開始の時刻(<?= km_e($short($event['startsAt'] ?? null)) ?>)から終了の時刻(<?= km_e($short($event['endsAt'] ?? null)) ?>)までです(時刻で自動に切り替わります)。<strong>アプリへ配る地図に入るのも有効にしたあと</strong>です(続けて 9)。</p>
                      <?php $openWarns = array_filter($steps, static fn (array $s, int $n): bool => $n < 8 && $s['state'] === 'warn', ARRAY_FILTER_USE_BOTH); ?>
                      <?php if ($openWarns !== []): ?>
                        <div class="alert alert-warning fs-7">まだ ! があります: <?= km_e(implode('・', array_map(static fn (int $n): string => $n . '. ' . KM_EVENT_SETUP_STEPS[$n], array_keys($openWarns)))) ?></div>
                      <?php endif; ?>
                      <form method="post" class="d-inline">
                        <?= km_csrf_field() ?><?= $hidden ?>
                        <fieldset class="d-inline" <?= $canWrite ? '' : 'disabled' ?>>
                          <?php if (!$event['isEnabled']): ?>
                            <button type="submit" name="form_action" value="enable" class="btn btn-success"
                                    <?= $openWarns !== [] ? 'data-km-confirm="まだ ! が残っています。それでも有効にしますか?"' : '' ?>>
                              <i class="bi bi-play-fill me-1" aria-hidden="true"></i>有効にする
                            </button>
                          <?php else: ?>
                            <button type="submit" name="form_action" value="disable" class="btn btn-outline-warning" data-km-confirm="イベントを無効にします。地図は元に戻ります。よろしいですか?">無効にする</button>
                          <?php endif; ?>
                        </fieldset>
                      </form>

                    <?php elseif ($step === 9): ?>
                      <p class="fs-7 text-body-secondary">
                        アプリの地図は、配った時点の内容を焼き込んだ写しです。会期の準備ができたら配り直してください(入力は要りません。前回の設定のまま版を上げます。
                        イベント用の期限は会期の終わりになります)。
                      </p>
                      <p class="fs-7">いま配り直すと入るイベント: <strong><?= km_e($appWindow['overlay']['events'] === [] ? 'なし' : implode('・', array_map(static fn (array $e): string => (string) $e['name'], $appWindow['overlay']['events']))) ?></strong></p>
                      <div class="table-responsive mb-2">
                        <table class="table table-sm align-middle mb-0">
                          <thead><tr><th>配信</th><th>版</th><th>期限</th><th>状態</th><th></th></tr></thead>
                          <tbody>
                            <?php foreach (['kosen-event' => 'イベント用(管理アプリの書き出し)', 'kosen-main' => 'Website の正本'] as $slug => $slugLabel): ?>
                              <?php $release = $releases[$slug] ?? null; ?>
                              <tr>
                                <td><?= km_e($slugLabel) ?><div class="fs-7 text-body-secondary"><?= km_e($slug) ?></div></td>
                                <?php if ($release === null): ?>
                                  <td colspan="3" class="text-body-secondary">まだ配信していません</td><td></td>
                                <?php else: ?>
                                  <td><?= (int) $release['revision'] ?></td>
                                  <td><?= km_e($release['expiresAt'] === null ? '不明' : (new DateTimeImmutable($release['expiresAt']))->format('Y-m-d H:i')) ?></td>
                                  <td>
                                    <?php if ($release['paused']): ?><span class="badge text-bg-secondary">止めている</span>
                                    <?php elseif ($release['expired']): ?><span class="badge text-bg-danger">期限切れ</span>
                                    <?php else: ?><span class="badge text-bg-success">配信中</span><?php endif; ?>
                                  </td>
                                  <td>
                                    <form method="post" class="m-0">
                                      <?= km_csrf_field() ?><?= $hidden ?>
                                      <input type="hidden" name="form_action" value="republish">
                                      <input type="hidden" name="slug" value="<?= km_e($slug) ?>">
                                      <fieldset <?= $canWrite ? '' : 'disabled' ?>><button type="submit" class="btn btn-sm btn-primary text-nowrap" title="最新の内容で配り直す">配り直す</button></fieldset>
                                    </form>
                                  </td>
                                <?php endif; ?>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                      <a class="btn btn-outline-secondary btn-sm" href="./map-publish.php">地図の配信を開く(最初の配信・アクセスコード)</a>

                    <?php elseif ($step === 10): ?>
                      <h4 class="fs-6 fw-semibold">控え</h4>
                      <p class="fs-7 mb-1">本番の全体の控え(DB・アップロードしたファイル・設定)は、<strong>毎晩 2:40 に自動で</strong>取っています。会期の直前に取りたいときは、PC の <code>server</code> フォルダで次を実行してください。</p>
                      <pre class="km-api-example mb-3"><code class="user-select-all">pwsh -File .\scripts\deploy-to-host.ps1 -BackupOnly</code></pre>
                      <h4 class="fs-6 fw-semibold">最終確認</h4>
                      <ul class="list-unstyled mb-0">
                        <?php foreach ($steps as $n => $s): ?>
                          <?php if ($n === 10) { continue; } ?>
                          <li class="d-flex gap-2 mb-1"><?= $stateBadge($s['state']) ?><a class="text-nowrap" href="<?= km_e($stepUrl($n)) ?>"><?= $n ?>. <?= km_e($s['title']) ?></a><span class="fs-7 text-body-secondary"><?= km_e($s['reason']) ?></span></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>

                    <?php if ($event !== null && $step < 10): ?>
                      <div class="border-top mt-4 pt-3 d-flex justify-content-between">
                        <?php if ($step > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= km_e($stepUrl($step - 1)) ?>">← <?= $step - 1 ?>. <?= km_e(KM_EVENT_SETUP_STEPS[$step - 1]) ?></a><?php else: ?><span></span><?php endif; ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= km_e($stepUrl($step + 1)) ?>"><?= $step + 1 ?>. <?= km_e(KM_EVENT_SETUP_STEPS[$step + 1]) ?> →</a>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <?php // ---- 会期を終える ---- ?>
            <?php if ($event !== null): ?>
              <div class="card mb-4 <?= $ended ? 'border-warning' : '' ?>" id="km-setup-finish">
                <div class="card-header">
                  <h3 class="card-title">会期を終える</h3>
                  <?php if ($ended): ?><div class="card-tools"><span class="badge text-bg-warning">会期は終わっています</span></div><?php endif; ?>
                </div>
                <form method="post" class="card-body">
                  <?= km_csrf_field() ?><?= $hidden ?>
                  <input type="hidden" name="form_action" value="finish">
                  <fieldset <?= $canWrite ? '' : 'disabled' ?>>
                    <p class="fs-7 text-body-secondary">✓を付けたものを、押したときにまとめて行います。<?= $ended ? '' : '会期がまだ終わっていないので、✓は外してあります(付ければ途中でも押せます)。' ?></p>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" id="km-finish-disable" name="disable_event" <?= $event['isEnabled'] ? $checkIfEnded : 'disabled' ?>>
                      <label class="form-check-label" for="km-finish-disable">イベントを無効にする<?= $event['isEnabled'] ? '' : '(もう無効です)' ?></label>
                    </div>
                    <?php $eventRelease = $releases['kosen-event'] ?? null; ?>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" id="km-finish-pause" name="pause_event_map" <?= $eventRelease !== null && !$eventRelease['paused'] ? $checkIfEnded : 'disabled' ?>>
                      <label class="form-check-label" for="km-finish-pause">アプリのイベント用の配信を止める<?= $eventRelease === null ? '(配信していません)' : ($eventRelease['paused'] ? '(もう止めています)' : '') ?>
                        <span class="d-block fs-7 text-body-secondary">これから取りに来る端末は受け取れなくなります。もう受け取った端末の地図は、期限(会期の終わり)で消えます。</span></label>
                    </div>
                    <div class="form-check mb-3">
                      <input class="form-check-input" type="checkbox" id="km-finish-archive" name="archive_posters" <?= $archivePreview['count'] > 0 ? $checkIfEnded : 'disabled' ?>>
                      <label class="form-check-label" for="km-finish-archive">
                        <strong>ポスター・広告を全部、控えへ移して消す</strong>(<?= (int) $archivePreview['count'] ?> 件・画像 <?= (int) $archivePreview['images'] ?> 枚・<?= km_e($size($archivePreview['bytes'])) ?>)
                        <span class="d-block fs-7 text-body-secondary">止めているものも広告も全部です。控え(zip)は<a href="./backups.php#poster-archives">バックアップ</a>の画面からダウンロードできます。画面から戻す機能はありません。控えが書けなかったときは何も消しません。</span>
                      </label>
                    </div>
                    <button type="submit" class="btn btn-warning"
                            data-km-confirm="会期を終えます。✓を付けたものを行います。<?= $archivePreview['count'] > 0 ? 'ポスター・広告を消すと、画面からは戻せません(控えの zip はバックアップの画面から落とせます)。' : '' ?>よろしいですか?">
                      <i class="bi bi-flag me-1" aria-hidden="true"></i>会期を終える
                    </button>
                  </fieldset>
                </form>
              </div>
            <?php endif; ?>

          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
