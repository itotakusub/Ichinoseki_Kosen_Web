<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 公開の JSON API が受ける本文の上限(バイト)。
 *
 * アプリが送るのは人数の印・ランキングの差分・設定で、どれも数 KB に収まる。
 * 上限が無いと、巨大な本文を json_decode させてメモリと CPU を食わせられる。
 */
const KM_API_JSON_MAX_BYTES = 64 * 1024;

/**
 * JSON の本文を読む。**大きすぎれば 413、形が違えば 400** で終わる。
 *
 * Content-Length を先に見て、読まずに断れるものは読まない。
 * 名乗りを偽って(または chunked で)送られても、上限+1 バイトまでしか読まない。
 */
function km_api_json_body(int $maxBytes = KM_API_JSON_MAX_BYTES): array
{
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > $maxBytes) {
        respond(['success' => false, 'message' => 'リクエストが大きすぎます。'], 413);
    }

    $stream = fopen('php://input', 'rb');
    $raw = $stream === false ? '' : (string) stream_get_contents($stream, $maxBytes + 1);
    if ($stream !== false) {
        fclose($stream);
    }
    if (strlen($raw) > $maxBytes) {
        respond(['success' => false, 'message' => 'リクエストが大きすぎます。'], 413);
    }

    $input = json_decode($raw, true);
    if (!is_array($input)) {
        respond(['success' => false, 'message' => 'リクエストを読み取れません。'], 400);
    }

    return $input;
}

/**
 * 要求の Authorization ヘッダー(無ければ '')。
 *
 * **`$_SERVER['HTTP_AUTHORIZATION']` だけを見てはいけない。** この web(Apache + mod_php)では
 * アプリが送っても空のままで、getallheaders() にだけ届く(2026-11-08 に検証機と本番で確かめた)。
 * それで api/user-map-data.php はアプリを「サインインしていない Web」と取り違えて毎回 401、
 * api/stall.php は正しい形の鍵でも「鍵が要ります」の 401 を返していた。
 * logto_guard.php の logto_authorization_header() と同じ読み方(あちらは Logto の確かめに使う)。
 */
function km_request_authorization(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                return trim((string) $value);
            }
        }
    }
    return '';
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        respond(['success' => false, 'message' => $method . ' required.'], 405);
    }
}
