<?php

declare(strict_types=1);

/**
 * ポスター・広告の数を記録する(2026-10-21、利用者の指示「広告の表示回数とリンクを踏んだ人数を保存」。lib/posters.php)。
 *
 *   POST {"poster": <番号>, "kind": "impression" | "click"}(X-KM-CSRF)→ 204
 *
 * **数だけを足す。** 誰のものかは残さない(IP も利用者も書かない)。
 * 外から数を膨らませにくくする:
 *   - 公開ページのセッションの CSRF トークンが要る(ほかのサイトから送らせない)
 *   - 同じセッションでは、リンクは 1 回だけ・広告の表示は 60 秒に 1 回だけ数える(km_poster_stat_allowed)
 *   - 入口で回数を絞る(nginx の km_api)
 * 数えなかったときも 204 を返す(数えたかどうかを外に教えない)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/session.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/posters.php';

require_method('POST');
km_session_start();
if (!km_csrf_verify()) {
    respond(['success' => false, 'message' => 'ページを開き直してください。'], 403);
}

$input = km_api_json_body(1024);
$posterId = is_int($input['poster'] ?? null) ? (int) $input['poster'] : 0;
$kind = is_string($input['kind'] ?? null) ? (string) $input['kind'] : '';
if ($posterId <= 0 || !in_array($kind, KM_POSTER_STAT_KINDS, true)) {
    respond(['success' => false, 'message' => '形が違います。'], 400);
}

$memory = is_array($_SESSION['km_poster_stat'] ?? null) ? $_SESSION['km_poster_stat'] : [];
$allowed = km_poster_stat_allowed($memory, $posterId, $kind, time());
$_SESSION['km_poster_stat'] = $memory;
// セッションから読むのはここまで。DB を待つあいだ、同じ人のほかの要求を止めない
session_write_close();

if ($allowed) {
    try {
        km_poster_stat_record(km_db(), $posterId, $kind);
    } catch (Throwable $exception) {
        // 数え損ねても利用者には関係ない。記録だけ
        error_log('api/poster-event.php failed: ' . $exception->getMessage());
    }
}

http_response_code(204);
exit;
