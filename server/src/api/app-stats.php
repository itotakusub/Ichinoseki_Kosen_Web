<?php

declare(strict_types=1);

/**
 * Android アプリのヘッダーに出す人数。
 *
 *   GET                        いまの人数を返す(記録はしない)
 *   POST {"clientId":"…"}      在席を記録してから人数を返す
 *
 * **ログインは必須にしない。** 未ログインの来場者も「いま使っている人数」に
 * 入れたいので logto_optional_principal() を使う。
 *
 * clientId は端末が作るランダムな文字列。Logto の sub とは別物で、
 * 同時に使っている端末を重複なく数えるためだけに使う。
 *
 * ## 未ログインの経路は Logto に触らない(2026-08-29)
 *
 * 総人数のキャッシュを更新できるのは**ログイン済みのリクエストだけ**。
 * 未ログインは MariaDB のキャッシュを読むだけで、切れていても `stale` を立てて返す。
 *
 * 読み取り専用の m2m を作る案は Logto の仕様上できない —— Management API の
 * スコープは `all` の1つだけ。権限ではなく**経路**で分ける
 * (lib/user-stats.php の km_user_stats_directory() の説明を参照)。
 *
 * **ログイン済みでも1日誰も来なければ数字は古くなる。** そのぶん `refreshedAt` を
 * 返して、端末が「いつの値か」を出せるようにしてある。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/user-stats.php';
// 出どころ(IP)の読み方は総当たり対策と同じ1本(X-Real-IP)
require_once __DIR__ . '/../lib/map-rate-limit.php';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/app-stats.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// ログイン済みかどうか。**総人数のキャッシュを更新してよいのはこのときだけ。**
$signedIn = false;

if ($method === 'POST') {
    // 64KB を超える本文は 413(api_bootstrap.php)。送るのは clientId だけ
    $input = km_api_json_body();

    // 未ログインでも通す。トークンが付いていて壊れている場合だけ null になる。
    $principal = logto_optional_principal();
    $subject = is_array($principal) ? trim((string) ($principal['subject'] ?? '')) : '';
    $signedIn = $subject !== '';

    km_user_stats_touch(
        $pdo,
        (string) ($input['clientId'] ?? ''),
        $signedIn ? $subject : null,
        // 1つの IP から数える端末に上限を置く(lib/user-stats.php)。IP は保存しない
        km_map_rate_limit_ip()
    );
}

if ($method !== 'GET' && $method !== 'POST') {
    respond(['success' => false, 'message' => 'GET か POST を使ってください。'], 405);
}

$online = km_user_stats_online($pdo);
/*
 * GET は常に、POST も未ログインなら**読むだけ**。
 *
 * これで未ログインの経路から Management API への線が消える。
 * ログイン済みの POST だけがキャッシュを更新する —— アプリは30秒ごとに
 * POST するので、ログインしている人が1人でも居れば5分の TTL は十分に保たれる。
 */
$directory = km_user_stats_directory($pdo, false, $signedIn);

respond([
    'success' => true,
    'online' => $online['online'],
    'onlineSignedIn' => $online['signedIn'],
    'onlineAnonymous' => $online['anonymous'],
    'total' => $directory['total'],
    'inside' => $directory['inside'],
    'outside' => $directory['outside'],
    'organizations' => $directory['organizations'],
    // Logto から取れなかった / 更新できない経路だったときは古い値を返している。
    'stale' => $directory['stale'],
    // いつの値か。**stale だけでは古さの程度が分からない**ので epoch 秒で返す。
    // 0 は「一度も取れていない」。
    'refreshedAt' => $directory['refreshedAt'],
]);
