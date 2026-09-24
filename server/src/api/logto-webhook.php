<?php

declare(strict_types=1);

/**
 * Logto の webhook の受け口。**いまは `User.Deleted` だけ。**
 *
 * Logto Console → Webhooks で、この URL を登録する:
 *
 *     https://ito4.jp/api/logto-webhook.php
 *
 * 「Signing key」を `.env` の `LOGTO_WEBHOOK_SIGNING_KEY` に入れること。
 * **入れないと何も受け付けない**(鍵が無いから素通し、にはしない)。
 *
 * ## この口はインターネットに開いている
 *
 * 確かめずに受けると「この利用者が消えました」と誰でも名乗れる ——
 * **他人のランキングと設定を消せる口**になる。だから:
 *
 *   1. POST 以外は受けない
 *   2. **署名を確かめる**(`lib/logto-webhook.php`)
 *   3. 知らないイベントは何もせず 200 で返す(再送を招かないため)
 *
 * ## 失敗の伝え方
 *
 * 相手は Logto なので、**中身のある本文は返さない。** 状態だけ返し、
 * 理由は `error_log` に残す。webhook の応答から内部の事情を教えない。
 */

require_once __DIR__ . '/../lib/logto-webhook.php';
require_once __DIR__ . '/../lib/account-delete.php';
require_once __DIR__ . '/../lib/admin-log.php';

header('Content-Type: application/json; charset=UTF-8');

function km_webhook_finish(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status < 400, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 署名の失敗を監査ログへ残す間隔(秒)。この間の2件目以降は error_log だけ。 */
const KM_WEBHOOK_FAILURE_LOG_INTERVAL = 600;

/**
 * 署名の検証に失敗したことを、管理画面のタイムラインにも残す(診断 server-ops#8)。
 *
 * **鍵の設定漏れは、ここにしか現れない。** 本番では鍵が web コンテナに渡っておらず
 * (診断 server-ops#1)、削除の後片付けが黙って止まっていた —— error_log だけでは誰も気付けない。
 *
 * ただしこの口はインターネットに開いているので、**1件ごとに DB へ書くと、
 * 送りつけるだけで監査ログを埋められる。** 10分に1件へ間引く(印は一時ディレクトリのファイル)。
 */
function km_webhook_record_signature_failure(bool $keyMissing): void
{
    $stamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'km-webhook-signature-failure.stamp';
    $last = @filemtime($stamp);
    if ($last !== false && time() - $last < KM_WEBHOOK_FAILURE_LOG_INTERVAL) {
        return;
    }
    @touch($stamp);
    km_admin_log_record(
        'system',
        'webhook.signature_failed',
        $keyMissing ? '鍵が未設定(LOGTO_WEBHOOK_SIGNING_KEY)' : '署名が一致しない'
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    km_webhook_finish(405, 'POST only');
}

/*
 * **本文の大きさに上限を置く。** 署名の検証は本文全体の HMAC を取るので、
 * 巨大な本文を送りつけられると、署名が偽物でもそのぶん計算させられる。
 * User.Deleted の本文は数 KB なので、1MB あれば足りる。
 */
const KM_WEBHOOK_MAX_BYTES = 1024 * 1024;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > KM_WEBHOOK_MAX_BYTES) {
    km_webhook_finish(413, 'too large');
}

/*
 * **本文はそのまま読む。** json_decode してから作り直すと、
 * キーの順序や空白が変わって署名が合わなくなる。
 * 読むのは上限+1 バイトまで —— Content-Length を名乗らない(chunked の)本文は上の判定を素通りするため。
 */
$rawBody = (string) file_get_contents('php://input', false, null, 0, KM_WEBHOOK_MAX_BYTES + 1);
if (strlen($rawBody) > KM_WEBHOOK_MAX_BYTES) {
    km_webhook_finish(413, 'too large');
}
$signature = (string) ($_SERVER[KM_LOGTO_WEBHOOK_SIGNATURE_HEADER] ?? '');

if (!km_logto_webhook_signature_valid($rawBody, $signature, km_logto_webhook_signing_key())) {
    error_log('api/logto-webhook.php: 署名が合いません(または鍵が未設定)');
    km_webhook_record_signature_failure(km_logto_webhook_signing_key() === '');
    km_webhook_finish(401, 'invalid signature');
}

/*
 * **署名が正しくても、古いものは受けない。** 盗み見た1通をあとから送り直されても
 * 動かないようにする。200 で返す(エラーにすると Logto が再送を繰り返す)。
 */
if (km_logto_webhook_is_stale(km_logto_webhook_created_at($rawBody), time())) {
    error_log('api/logto-webhook.php: 古い(または未来の)イベントを捨てました');
    km_webhook_finish(200, 'stale');
}

$parsed = km_logto_webhook_parse($rawBody);

if ($parsed['event'] !== KM_LOGTO_WEBHOOK_USER_DELETED) {
    /*
     * **知らないイベントは 200 で返す。** エラーにすると Logto が再送を繰り返し、
     * ログが埋まって本当の失敗が見えなくなる。
     */
    km_webhook_finish(200, 'ignored');
}

if ($parsed['userId'] === null) {
    // 形が違う。**推測で消さない**
    error_log('api/logto-webhook.php: User.Deleted に利用者 id がありません');
    km_webhook_finish(400, 'missing user id');
}

try {
    $result = km_account_delete_data(km_db(), $parsed['userId']);
} catch (Throwable $exception) {
    /*
     * **500 を返す。** Logto は再送してくれるので、DB が一時的に落ちていただけなら
     * あとで片付く。200 を返すと、消えないまま二度と機会が来ない。
     */
    error_log('api/logto-webhook.php: 後片付けに失敗: ' . $exception->getMessage());
    km_webhook_finish(500, 'cleanup failed');
}

/*
 * 何を消したかを記録する。**利用者の id は残さない** ——
 * 消した記録に id を書いたら、消した意味が薄れる。件数だけ残す。
 *
 * ここは `$KM_USER` が居ない(Logto からの呼び出し)ので既定のままで実行者は空になる。
 * `account.php` 側は本人がサインイン中なので、あちらだけ第4引数で匿名を明示する。
 * **残る IP は Logto サーバーのもの**で、消えた人のものではない ——
 * webhook が本当に届いたかを後から追えるので、これは残す。
 */
try {
    km_admin_log_record('system', 'account.deleted', km_account_delete_summary($result));
} catch (Throwable $exception) {
    error_log('api/logto-webhook.php: 記録に失敗: ' . $exception->getMessage());
}

km_webhook_finish(200, 'deleted');
