<?php

declare(strict_types=1);

/**
 * ポスターの ♡(2026-10-23、利用者の指示。中身は lib/poster-likes.php)。
 *
 *   GET  ?ids=1,2,3(X-KM-Device: <32 桁の 16 進>。端末の印は URL に載せない)→ {"success":true,"likes":[{"id":1,"count":3,"liked":false},…]}
 *   POST {"poster":<番号>,"device":"<32 桁の 16 進>","like":true|false}(X-KM-CSRF)→ {"success":true,"count":N,"liked":bool}
 *
 * - 付け外しは**公開ページのセッションの CSRF トークンが要る**(ほかのサイトから押させない。api/poster-event.php と同じ)
 * - 押せるのは、いま公開の地図に出しているポスターだけ(出し方が「表示しない」・止めたポスターには付けない)
 * - 端末の印は鍵つきの印にしてだけ持つ。IP は持たない(回数を絞る印だけ)
 * - 入口の回数は nginx の km_api で絞る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/session.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/poster-likes.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    require_method('POST');
}

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/poster-like.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

if ($method === 'GET') {
    // 端末の印は見出しで受ける(URL に載せるとアクセスの記録に残る)
    $device = strtolower((string) ($_SERVER['HTTP_X_KM_DEVICE'] ?? ''));
    $ids = km_poster_like_ids(is_string($_GET['ids'] ?? null) ? (string) $_GET['ids'] : '');
    try {
        $voter = km_node_like_device_valid($device) ? km_poster_like_voter($pdo, $device) : null;
        respond(['success' => true, 'likes' => km_poster_like_state($pdo, $ids, $voter)]);
    } catch (Throwable $exception) {
        error_log('api/poster-like.php GET failed: ' . $exception->getMessage());
        respond(['success' => false, 'message' => '読めませんでした。'], 500);
    }
}

km_session_start();
if (!km_csrf_verify()) {
    respond(['success' => false, 'message' => 'ページを開き直してください。'], 403);
}
// セッションから読むのはここまで。DB を待つあいだ、同じ人のほかの要求を止めない
session_write_close();

$input = km_api_json_body(1024);
$posterId = is_int($input['poster'] ?? null) ? (int) $input['poster'] : 0;
$device = is_string($input['device'] ?? null) ? strtolower((string) $input['device']) : '';
$like = $input['like'] ?? null;
if ($posterId <= 0 || !km_node_like_device_valid($device) || !is_bool($like)) {
    respond(['success' => false, 'message' => '形が違います。'], 400);
}

try {
    // いま公開の地図に出しているポスターだけ(km_posters_public と同じ決まり)
    $shown = array_column(km_posters_public($pdo, km_poster_event_active($pdo), []), 'id');
    if (!in_array($posterId, array_map('intval', $shown), true)) {
        respond(['success' => false, 'message' => 'このポスターは今は掲示していません。'], 404);
    }
    km_poster_likes_ensure_table($pdo);
    $voter = km_poster_like_voter($pdo, $device);
    if (km_poster_like_set($pdo, $posterId, $voter, km_poster_like_source($pdo), $like) === 'limited') {
        respond(['success' => false, 'message' => '♡ が多すぎます。しばらく待ってからやり直してください。'], 429);
    }
    $state = km_poster_like_state($pdo, [$posterId], $voter)[0];
} catch (Throwable $exception) {
    error_log('api/poster-like.php POST failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '送れませんでした。'], 500);
}

respond(['success' => true, 'count' => $state['count'], 'liked' => $state['liked']]);
