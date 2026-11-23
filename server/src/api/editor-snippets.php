<?php

declare(strict_types=1);

/**
 * エディタの雛形(段 14-4。2026-11-13。中身は lib/editor-snippets.php)。
 *
 *   GET /api/editor-snippets.php
 *   → {"success":true,"custom":false,"data":null,"updatedAt":null}          管理画面で取り込んでいない(エディタは同梱の雛形を使う)
 *   → {"success":true,"custom":true,"data":{"version":1,"html":{…},"js":[…]},"updatedAt":"…"}
 *
 * - /editor/ と /code/ が起動のときに 1 回読む。**サインインも鍵も要らない**(中身は誰が見ても同じ雛形だけ)
 * - 受け取るものは無い(問い合わせの中身を何も見ない)
 * - DB が読めないときは custom: false を返す(エディタは同梱の雛形で動く。雛形のために開けなくしない)
 * - 1 分は使い回してよい。入口の回数は nginx の km_api で絞る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/editor-snippets.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    respond(['success' => false, 'message' => 'GET required.'], 405);
}

$current = ['custom' => false, 'data' => null, 'updatedAt' => null];
try {
    $current = km_editor_snippets_current(km_db());
} catch (Throwable $exception) {
    error_log('api/editor-snippets.php: ' . $exception->getMessage());
}
header('Cache-Control: public, max-age=60');
respond(['success' => true, 'custom' => $current['custom'], 'data' => $current['custom'] ? $current['data'] : null, 'updatedAt' => $current['custom'] ? $current['updatedAt'] : null]);
