<?php
// 検証用: 偽 Logto の鍵で署名したアクセストークンを出す。php mktoken.php <sub> "<scope>"
require __DIR__ . '/websrc/vendor/autoload.php';
$now = time();
echo Firebase\JWT\JWT::encode(['iss' => 'http://127.0.0.1:3901/oidc', 'aud' => 'https://kosenmap.test/api', 'client_id' => 'androidapp',
    'sub' => $argv[1], 'scope' => $argv[2] ?? '', 'iat' => $now, 'exp' => $now + 3600], file_get_contents(__DIR__ . '/keys/rsa.pem'), 'RS256', 'verify1');
