<?php

declare(strict_types=1);

/**
 * Website のイベントを管理アプリへ渡す口(2026-10-23、利用者の指示「アプリのイベントを Web でも直す」。中身は lib/map-event-export.php)。
 *
 *   GET   全部のイベントを、アプリのイベント(MapEvent)の形で … **管理者だけ**
 *
 * 管理アプリの設定のイベントの「Website から取り込む」が読む。アプリから取り込んだイベントは同じ uuid で戻るので、
 * アプリは同じイベントを入れ替える(Website の地図編集で直した内容がアプリへ戻る)。
 * 中身に地点の uuid が入るが氏名は入らない。それでも地図を直す人にだけ渡す(学習の協力者にも渡さない —— イベントは配信に効く)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-event-export.php';

require_method('GET');

$principal = logto_require_principal();
logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);

try {
    $pdo = km_db();
    $exported = km_map_events_for_app($pdo);
} catch (Throwable $exception) {
    error_log('api/map-events.php failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'イベントを読めませんでした。'], 500);
}

respond(['success' => true, 'events' => $exported['events'], 'skipped' => $exported['skipped']]);
