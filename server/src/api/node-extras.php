<?php

declare(strict_types=1);

/**
 * アプリの部屋の詳細に出す写真(ポスター)と「いいね」(2026-10-22。中身は lib/node-extras.php)。
 *
 *   POST {"code":"…","node":"<地点の uuid>","device":"<32 桁の 16 進>"}               写真といいねの数を読む
 *   POST {"code":"…","node":"…","device":"…","like":true|false}                      いいねを付ける・外す(そのあとの数を返す)
 *   → {"success":true,"posters":[…],"likes":{"count":N,"liked":bool},"likeable":bool,"schedule":[…],"stalls":[…]}
 *   schedule・stalls は 2026-10-30(振り返りのフェーズ 5)。この地点の一般の予定と、屋台のメニュー・混み具合
 *
 * - **地図のアクセスコードが要る**(地図を持つ人だけ。照合は学習データと同じ km_learning_code_allowed)。コードは URL に載せない
 * - 端末の印は鍵つきの印にしてだけ持つ。IP は持たない(回数を絞る印だけ)
 * - いいねできるのは Web の地図にある地点だけ(イベントの臨時の地点は数えない)。教員室・研究室は写真もいいねも出さない
 * - 入口の回数は nginx の km_api で絞る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/learning-data.php';
require_once __DIR__ . '/../lib/node-extras.php';

require_method('POST');

$input = km_api_json_body(4 * 1024);

$nodeUuid = is_string($input['node'] ?? null) ? (string) $input['node'] : '';
if (!km_node_uuid_valid($nodeUuid)) {
    respond(['success' => false, 'message' => '地点の形が違います。'], 400);
}
$device = is_string($input['device'] ?? null) ? strtolower((string) $input['device']) : '';
if ($device !== '' && !km_node_like_device_valid($device)) {
    respond(['success' => false, 'message' => '端末の印の形が違います。'], 400);
}
$like = $input['like'] ?? null;
if ($like !== null && !is_bool($like)) {
    respond(['success' => false, 'message' => 'like は true か false です。'], 400);
}
if ($like !== null && $device === '') {
    respond(['success' => false, 'message' => '端末の印が必要です。'], 400);
}

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/node-extras.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$allowed = km_learning_code_allowed($pdo, (string) ($input['code'] ?? ''));
if (!$allowed['ok']) {
    respond(['success' => false, 'message' => $allowed['message']], $allowed['status']);
}

try {
    $node = km_node_extras_find_node($pdo, $nodeUuid);
    $posters = km_node_extras_posters($pdo, $node, km_poster_event_active($pdo));

    km_node_likes_ensure_table($pdo);
    $voter = $device !== '' ? km_node_like_voter($pdo, $device) : null;
    // 教員室・研究室は写真もいいねも出さない(2026-10-22、Android 側の厳格化。アクセスコードだけでは組織メンバーか分からない)
    $restricted = $node !== null && km_map_node_is_restricted($node);
    $likeable = $node !== null && !$restricted;
    if ($like !== null && $voter !== null) {
        if (!$likeable) {
            respond(['success' => false, 'message' => 'この地点にはいいねできません。'], 400);
        }
        if (km_node_like_set($pdo, $nodeUuid, $voter, km_node_like_source($pdo), $like) === 'limited') {
            respond(['success' => false, 'message' => 'いいねが多すぎます。しばらく待ってからやり直してください。'], 429);
        }
    }
    $likes = $restricted ? ['count' => 0, 'liked' => false] : km_node_like_state($pdo, $nodeUuid, $voter);
    $schedule = km_node_extras_schedule($pdo, $node);
    $stalls = km_node_extras_stalls($pdo, $node);
} catch (Throwable $exception) {
    error_log('api/node-extras.php failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '読めませんでした。'], 500);
}

respond(['success' => true, 'posters' => $posters, 'likes' => $likes, 'likeable' => $likeable, 'schedule' => $schedule, 'stalls' => $stalls]);
