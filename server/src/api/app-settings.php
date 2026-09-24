<?php

declare(strict_types=1);

/**
 * Android アプリの設定をアカウントへ預ける／取り戻す。
 *
 *   GET                          自分の設定を返す。未保存なら204
 *   POST  {"payload": "<JSON>"}  保存する
 *   POST  {"action":"clear"}     消す
 *
 * **必ずトークンの sub に対してのみ読み書きする。** userId は受け取らない。
 * 他人の設定を覗いたり上書きしたりする余地を作らないため。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/app-settings.php';

$principal = logto_require_principal();
$subject = trim((string) ($principal['subject'] ?? ''));
if ($subject === '') {
    respond(['success' => false, 'message' => 'Logtoユーザーを特定できません。'], 401);
}

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/app-settings.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $row = km_app_settings_find($pdo, $subject);
    if ($row === null) {
        http_response_code(204);
        exit;
    }
    // payload は保存したままの文字列を返す。ここで詰め直すと、
    // アプリ側が作ったキーの順序や表記が変わる。
    http_response_code(200);
    echo '{"success":true,"updatedAt":' . $row['updatedAtEpoch'] . ',"payload":'
        . json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '}';
    exit;
}

require_method('POST');

/*
 * 本文の上限。**payload は JSON の文字列として入れ子になる**ので、中の `"` や `\` が
 * エスケープされて膨らむ。payload 自体の上限(KM_APP_SETTINGS_MAX_BYTES = 64KB)を
 * 満たす設定を本文の上限で先に断らないよう、ここだけ 2 倍まで受ける。
 * それを超えたものは 413、payload の 64KB 超えは従来どおり lib が 400 で断る。
 */
$input = km_api_json_body(KM_APP_SETTINGS_MAX_BYTES * 2);

if (($input['action'] ?? '') === 'clear') {
    km_app_settings_clear($pdo, $subject);
    respond(['success' => true, 'cleared' => true]);
}

$payload = $input['payload'] ?? null;
if (!is_string($payload) || $payload === '') {
    respond(['success' => false, 'message' => 'payload が必要です。'], 400);
}

try {
    $updatedAt = km_app_settings_store($pdo, $subject, $payload);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/app-settings.php store failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '設定を保存できませんでした。'], 500);
}

respond(['success' => true, 'updatedAt' => $updatedAt]);
