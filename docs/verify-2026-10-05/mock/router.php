<?php
// 検証用の偽 Logto。受けた要求を1行ずつ記録し、決まった応答を返す。
//
// 2026-10-05 版で足したもの: トークンの更新(grant_type=refresh_token)に、**本当に署名した** ID トークンを返す。
// 9/25 版は更新を必ず 400 で断っていたので、「取り直す」ように直したコードを動かしても
// 「取り直せなかった」としか出ず、直ったかどうかを判定できなかった。
//
// 振る舞いの切り替えは mock/state.json:
//   {"removedFromOrg": ["teacher-0002"]}  … この利用者の更新では organization_roles を空にする(Console で組織から外した想定)
//   {"refreshFails": true}                … 更新を 400 で断る(Logto に届かない・失効した想定)
$log = __DIR__ . '/../logs/mock-logto.log';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
file_put_contents($log, date('H:i:s') . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $uri . ' ' . $body . "\n", FILE_APPEND);
header('Content-Type: application/json');
$base = 'http://127.0.0.1:3901';
$state = is_file(__DIR__ . '/state.json') ? (json_decode((string) file_get_contents(__DIR__ . '/state.json'), true) ?: []) : [];

if ($uri === '/oidc/.well-known/openid-configuration') {
    echo json_encode(['issuer' => "$base/oidc", 'authorization_endpoint' => "$base/oidc/auth", 'token_endpoint' => "$base/oidc/token",
        'userinfo_endpoint' => "$base/oidc/me", 'jwks_uri' => "$base/oidc/jwks", 'end_session_endpoint' => "$base/oidc/session/end",
        'revocation_endpoint' => "$base/oidc/token/revocation", 'response_types_supported' => ['code'], 'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'], 'scopes_supported' => ['openid'], 'claims_supported' => ['sub'],
        'code_challenge_methods_supported' => ['S256']]);
    exit;
}
if ($uri === '/oidc/jwks') {
    echo is_file(__DIR__ . '/jwks.json') ? file_get_contents(__DIR__ . '/jwks.json') : json_encode(['keys' => []]);
    exit;
}
if ($uri === '/oidc/token') {
    parse_str($body, $p);
    $grant = $p['grant_type'] ?? '';
    if ($grant === 'client_credentials') {
        echo json_encode(['access_token' => 'm2m-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
        exit;
    }
    if ($grant === 'refresh_token' && empty($state['refreshFails']) && str_starts_with((string) ($p['refresh_token'] ?? ''), 'rt-')) {
        require __DIR__ . '/../websrc/vendor/autoload.php';
        $sub = substr((string) $p['refresh_token'], 3);
        $now = time();
        $removed = in_array($sub, (array) ($state['removedFromOrg'] ?? []), true);
        $key = (string) file_get_contents(__DIR__ . '/../keys/rsa.pem');
        $idToken = Firebase\JWT\JWT::encode([
            'iss' => "$base/oidc", 'sub' => $sub, 'aud' => getenv('LOGTO_APP_ID') ?: 'webapp', 'exp' => $now + 3600, 'iat' => $now,
            'name' => '教職員' . $sub, 'organization_roles' => $removed ? [] : ['org123456:Kosen_Member'],
        ], $key, 'RS256', 'verify1');
        $accessToken = Firebase\JWT\JWT::encode([
            'iss' => "$base/oidc", 'sub' => $sub, 'aud' => getenv('LOGTO_API_RESOURCE') ?: 'https://kosenmap.test/api', 'exp' => $now + 3600, 'iat' => $now,
            'scope' => '', 'client_id' => getenv('LOGTO_APP_ID') ?: 'webapp',
        ], $key, 'RS256', 'verify1');
        // SDK の TokenResponse は、この 5 つ以外のキーがあると例外になる
        echo json_encode(['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => 3600,
            'refresh_token' => $p['refresh_token'], 'id_token' => $idToken]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant (mock)']);
    exit;
}
if (preg_match('#^/api/users/[^/]+/organizations$#', $uri)) { echo '[]'; exit; }
if (preg_match('#^/api/users/([^/]+)$#', $uri, $m)) {
    // state.json の suspended に入れた利用者は停止中として返す(検証 N-SUSPEND)
    echo json_encode(['id' => $m[1], 'isSuspended' => in_array($m[1], (array) ($state['suspended'] ?? []), true), 'name' => 'Test Teacher', 'username' => 'teacher']);
    exit;
}
if (str_starts_with($uri, '/api/organizations/')) {
    http_response_code($_SERVER['REQUEST_METHOD'] === 'DELETE' ? 204 : 201);
    echo '{}';
    exit;
}
http_response_code(404);
echo '{}';
