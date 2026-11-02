<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/*
 * 管理アプリのイベントの取り込み(2026-10-14。lib/map-event-import.php)。
 * 上げる → 差分を見る → 選んで入れる。入れたイベントは無効のまま(ON は「イベントモード」の画面で)。
 *
 * **2026-11-02 にイベントモード(map-events.php)から「アプリの地図を取り込む」(map-sync.php)へ移した**
 * (利用者の指示「無理やり配置した項目を的確な場所へ」—— 管理アプリから入れる口を 1 つの画面にまとめた)。
 *
 * 読み込む側が用意するもの: $pdo(DB に繋げなければ未定義のまま)・$errors・$notice・$kmEventImportVerified(CSRF を通ったら true)。
 * 受ける POST は form_action が import_upload / import_discard / import_apply のものだけ。
 * 用意するもの: $importPending・$importPreview・$importProjects(カードは _inc/partials/event-import.php)。
 */

require_once dirname(__DIR__, 2) . '/lib/map-events.php';
require_once dirname(__DIR__, 2) . '/lib/map-event-import.php';

const KM_EVENT_IMPORT_ACTIONS = ['import_upload', 'import_discard', 'import_apply'];

$importPending = ['events' => [], 'projects' => null, 'uploadedAt' => null, 'error' => null];
$importPreview = [];
$importProjects = null;

$formAction = (string) ($_POST['form_action'] ?? '');
if (($kmEventImportVerified ?? false) === true && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($formAction, KM_EVENT_IMPORT_ACTIONS, true) && isset($pdo)) {
    try {
        if ($formAction === 'import_upload') {
            $file = $_FILES['event_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                throw new InvalidArgumentException('ファイルを受け取れませんでした。選び直してください。');
            }
            $refused = km_map_event_import_store((string) $file['tmp_name'], (int) ($file['size'] ?? 0));
            if ($refused !== null) {
                throw new InvalidArgumentException($refused);
            }
            $notice = 'ファイルを受け取りました。下の「取り込み待ち」で中身を確かめてから取り込んでください。';
        } elseif ($formAction === 'import_discard') {
            km_map_event_import_discard();
            $notice = '取り込み待ちを捨てました。';
        } elseif ($formAction === 'import_apply') {
            $selected = array_map('strval', is_array($_POST['uuids'] ?? null) ? $_POST['uuids'] : []);
            $pending = km_map_event_import_load();
            if ($pending['error'] !== null) {
                throw new InvalidArgumentException($pending['error']);
            }
            $applied = [];
            foreach ($pending['events'] as $pendingEvent) {
                if (!in_array(trim((string) $pendingEvent->uuid), $selected, true)) {
                    continue;
                }
                $result = km_map_event_import_apply($pdo, $pendingEvent);
                $applied[] = $result['converted']['name'] . ($result['created'] ? '(新規・無効)' : '(入れ替え)');
            }
            // 教室などに振った企画の番号(2026-10-23。同じファイルの地図から。それまでここでは入らなかった)
            if ($pending['projects'] !== null && ($_POST['import_projects'] ?? '') === '1') {
                km_map_event_import_apply_projects($pdo, $pending['projects']);
                $applied[] = '企画の番号 ' . count($pending['projects']) . ' 地点';
            }
            if ($applied === []) {
                throw new InvalidArgumentException('取り込むイベントを選んでください。');
            }
            km_map_event_import_discard();
            km_admin_log_record('content', 'map.event_import', implode(' / ', $applied));
            $notice = '取り込みました: ' . implode('、', $applied)
                . '。新しいイベントは無効のままです。期間と案内の文を確かめてから有効にしてください。';
        }
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('event-import ' . $formAction . ' failed: ' . $exception->getMessage());
        $errors[] = km_admin_error_message($exception, '取り込めませんでした。');
    }
}

if (isset($pdo)) {
    try {
        $importPending = km_map_event_import_load();
        if ($importPending['events'] !== []) {
            $importPreview = km_map_event_import_preview($pdo, $importPending['events']);
        }
        if ($importPending['projects'] !== null) {
            $importProjects = km_map_event_import_projects_summary(
                $importPending['projects'], km_map_event_import_known_nodes($pdo), km_map_node_projects_all($pdo)
            );
        }
    } catch (Throwable $exception) {
        // 見せるためだけのもの。読めなくても地図の取り込みは止めない
        error_log('event-import load failed: ' . $exception->getMessage());
    }
}