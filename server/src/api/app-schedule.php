<?php

declare(strict_types=1);

/**
 * アプリの「予定」(2026-10-30、振り返りのフェーズ 5 の 1。Web の公開の地図の「スケジュール」と同じ中身)。
 *
 *   POST {"code":"…"}
 *   → {"success":true,"schedule":[{"title","organizer","description","startsAt","endsAt","projectNo","places":[{"uuid","name"}]}]}
 *
 * - **地図のアクセスコードが要る**(照合は学習データと同じ km_learning_code_allowed)。コードは URL に載せない
 * - 一般の行だけ(アプリはアクセスコードだけで、教職員か分からない)。今日より前に終わった行は出さない
 * - 場所は会場に選んだ地点と企画の番号の地点。アプリの地点の uuid で返す。教員室・研究室は場所に入れない
 * - 入口の回数は nginx の km_api で絞る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/learning-data.php';
require_once __DIR__ . '/../lib/node-visibility.php';
require_once __DIR__ . '/../lib/map-node-projects.php';
require_once __DIR__ . '/../lib/map-events.php';
require_once __DIR__ . '/../lib/event-schedule.php';

require_method('POST');

$input = km_api_json_body(4 * 1024);

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/app-schedule.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$allowed = km_learning_code_allowed($pdo, (string) ($input['code'] ?? ''));
if (!$allowed['ok']) {
    respond(['success' => false, 'message' => $allowed['message']], $allowed['status']);
}

try {
    // 場所に入れてよい地点(教員室・研究室を除く)。Web の id → アプリの uuid と名前
    $places = [];
    foreach ($pdo->query('SELECT id, uuid, name, type2 FROM km_map_nodes')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $node = ['id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? ''), 'type2' => (string) ($row['type2'] ?? '')];
        if (!km_map_node_is_restricted($node) && (string) ($row['uuid'] ?? '') !== '') {
            $places[$node['id']] = ['uuid' => (string) $row['uuid'], 'name' => $node['name']];
        }
    }
    $projectNodes = km_map_project_nodes(km_map_projects_with_aliases(km_map_projects_by_node_id($pdo), km_map_event_overlay($pdo)['aliases']), $places);
    $schedule = array_map(static fn (array $row): array => [
        'title' => $row['title'],
        'organizer' => $row['organizer'],
        'description' => $row['description'],
        'startsAt' => $row['startsAt'],
        'endsAt' => $row['endsAt'],
        'projectNo' => $row['projectNo'],
        'places' => array_values(array_map(static fn (string $id): array => $places[$id], array_filter($row['nodeIds'], static fn (string $id): bool => isset($places[$id])))),
    ], km_event_schedule_public($pdo, $projectNodes, [], false, $places));
} catch (Throwable $exception) {
    error_log('api/app-schedule.php failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '読めませんでした。'], 500);
}

respond(['success' => true, 'schedule' => $schedule]);
