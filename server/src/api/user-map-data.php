<?php

declare(strict_types=1);

/**
 * 地図の履歴とブックマークの同期(2026-10-23。中身は lib/user-map-data.php)。**サインインした人だけ。**
 *
 *   GET                          自分の分(まだ無ければ空の形)
 *   POST {"data": {...}}         端末の分を送る → サーバーの分と混ぜて置き、混ぜた結果を返す
 *   DELETE                       アカウントから全部消す(端末の中は端末で消す)
 *
 * 誰のものかは、アプリは Logto のアクセストークン(Authorization: Bearer)の sub、
 * Web はサインインのセッション(logto-client.php)の sub だけで決める。**利用者の ID は受け取らない**(他人の分を読めない)。
 * Web からの POST・DELETE は CSRF のトークン(X-KM-CSRF。公開ページの meta km-csrf)を確かめる。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/user-map-data.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    respond(['success' => false, 'message' => 'GET / POST / DELETE のどれかで呼んでください。'], 405);
}

$subject = '';
if (trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')) !== '') {
    // アプリ。トークンが正しくなければ logto_require_principal が 401 で止める
    $principal = logto_require_principal();
    $subject = trim((string) ($principal['subject'] ?? ''));
} else {
    // Web。サインインしていなければ 401(端末の中だけで使う)
    try {
        require dirname(__DIR__) . '/logto-client.php';
        if ($client->isAuthenticated()) {
            $subject = trim((string) ($client->getIdTokenClaims()->sub ?? ''));
        }
    } catch (Throwable $exception) {
        error_log('api/user-map-data.php session: ' . $exception->getMessage());
    }
    if ($subject !== '' && $method !== 'GET') {
        require_once dirname(__DIR__) . '/lib/csrf.php';
        if (!km_csrf_verify()) {
            respond(['success' => false, 'message' => 'ページを読み込み直してからやり直してください。'], 403);
        }
    }
    // 大きな本文を読む間、ほかの要求(同じセッション)を待たせない
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
if ($subject === '') {
    respond(['success' => false, 'message' => 'サインインしていません(履歴とブックマークはこの端末の中だけに残ります)。'], 401);
}

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/user-map-data.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

try {
    if ($method === 'GET') {
        respond(['success' => true, 'data' => km_user_map_find($pdo, $subject) ?? km_user_map_normalize([])]);
    }
    if ($method === 'DELETE') {
        km_user_map_delete($pdo, $subject);
        respond(['success' => true, 'message' => 'アカウントに置いた履歴とブックマークを消しました。']);
    }
    $input = km_api_json_body(KM_USER_MAP_MAX_BYTES);
    if (!is_array($input['data'] ?? null)) {
        respond(['success' => false, 'message' => 'data が必要です。'], 400);
    }
    respond(['success' => true, 'data' => km_user_map_sync($pdo, $subject, $input['data'])]);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/user-map-data.php failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '同期できませんでした。'], 500);
}
