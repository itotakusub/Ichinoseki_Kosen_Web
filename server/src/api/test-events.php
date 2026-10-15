<?php

declare(strict_types=1);

/**
 * テスト用アカウントの操作の記録を受ける(2026-10-15。lib/test-mode.php)。
 *
 * **受けるのは、公開ページがテスト用と確かめたセッションからだけ**(`$_SESSION[KM_TEST_MODE_SESSION_KEY]`。
 * index.php が開くたびに立て直す)。印の無い人・ログインしていない人の送信は 403 で、何も書かない。
 * セッションの Cookie に乗って他所のサイトから送らせないよう、CSRF のトークン(X-KM-CSRF)も見る。
 *
 * 本文: `{"visit":"16 字の 16 進","events":[{"kind":"search","t":1760000000000,"field":"goal","text":"図書館","node":"…"}]}`
 */

require_once __DIR__ . '/../lib/session.php';
km_session_start();

require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/test-mode.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function km_test_events_respond(array $body, int $status): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    km_test_events_respond(['ok' => false], 405);
}

$mark = $_SESSION[KM_TEST_MODE_SESSION_KEY] ?? null;
$sub = is_array($mark) ? (string) ($mark['sub'] ?? '') : '';
if ($sub === '' || (int) ($mark['until'] ?? 0) < time()) {
    km_test_events_respond(['ok' => false], 403);
}
if (!km_csrf_verify()) {
    km_test_events_respond(['ok' => false], 403);
}

$raw = (string) file_get_contents('php://input', false, null, 0, 65536);
$parsed = km_test_events_parse(json_decode($raw, true), (int) floor(microtime(true) * 1000));
if ($parsed === null) {
    km_test_events_respond(['ok' => false], 400);
}

try {
    $count = km_test_events_insert(km_db(), $sub, $parsed);
} catch (Throwable $exception) {
    error_log('test-events.php insert failed: ' . $exception->getMessage());
    km_test_events_respond(['ok' => false], 503);
}

km_test_events_respond(['ok' => true, 'stored' => $count], 200);
