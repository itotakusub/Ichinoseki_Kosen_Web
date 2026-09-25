<?php
// 検証用の偽 Logto。受けた要求を1行ずつ記録し、決まった応答を返す。
$log = __DIR__ . '/../logs/mock-logto.log';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
file_put_contents($log, date('H:i:s') . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $uri . ' ' . $body . "\n", FILE_APPEND);
header('Content-Type: application/json');
$base = 'http://127.0.0.1:3901';
if ($uri === '/oidc/.well-known/openid-configuration') {
    echo json_encode(['issuer' => "$base/oidc", 'authorization_endpoint' => "$base/oidc/auth", 'token_endpoint' => "$base/oidc/token",
        'userinfo_endpoint' => "$base/oidc/me", 'jwks_uri' => "$base/oidc/jwks", 'end_session_endpoint' => "$base/oidc/session/end",
        'revocation_endpoint' => "$base/oidc/token/revocation", 'response_types_supported' => ['code'], 'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['ES384'], 'scopes_supported' => ['openid'], 'claims_supported' => ['sub'],
        'code_challenge_methods_supported' => ['S256']]);
    exit;
}
if ($uri === '/oidc/jwks') { echo is_file(__DIR__ . '/jwks.json') ? file_get_contents(__DIR__ . '/jwks.json') : json_encode(['keys' => []]); exit; }
if ($uri === '/oidc/token') {
    parse_str($body, $p);
    if (($p['grant_type'] ?? '') === 'client_credentials') { echo json_encode(['access_token' => 'm2m-token', 'expires_in' => 3600, 'token_type' => 'Bearer']); exit; }
    http_response_code(400); echo json_encode(['error' => 'invalid_grant (mock)']); exit;
}
if (preg_match('#^/api/users/[^/]+/organizations$#', $uri)) { echo '[]'; exit; }
if (preg_match('#^/api/users/([^/]+)$#', $uri, $m)) {
    echo json_encode(['id' => $m[1], 'isSuspended' => false, 'name' => 'Test Teacher', 'username' => 'teacher']); exit;
}
if (str_starts_with($uri, '/api/organizations/')) { http_response_code($_SERVER['REQUEST_METHOD'] === 'DELETE' ? 204 : 201); echo '{}'; exit; }
http_response_code(404); echo '{}';
