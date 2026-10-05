<?php
// WA-07: 署名の方式(alg)をすり替えたアクセストークンを作る。php wa07_tokens.php <種類>
// 鍵は検証用の偽 Logto のもの(keys/rsa.pem・mock/jwks.json)。値はすべて架空。
declare(strict_types=1);
require __DIR__ . '/websrc/vendor/autoload.php';
use Firebase\JWT\JWT;
$b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$now = time();
$claims = ['iss' => 'http://127.0.0.1:3901/oidc', 'aud' => 'https://kosenmap.test/api', 'client_id' => 'androidapp',
    'sub' => 'wa07-user', 'scope' => 'staff:event:access', 'iat' => $now, 'exp' => $now + 3600];
$priv = file_get_contents(__DIR__ . '/keys/rsa.pem');
$pubPem = openssl_pkey_get_details(openssl_pkey_get_private($priv))['key'];
$jwk = json_decode(file_get_contents(__DIR__ . '/mock/jwks.json'), true)['keys'][0];
$raw = static function (array $header, array $payload, string $sig) use ($b64): string {
    return $b64(json_encode($header)) . '.' . $b64(json_encode($payload)) . '.' . $b64($sig);
};
$hs = static function (array $header, array $payload, string $key) use ($b64): string {
    $in = $b64(json_encode($header)) . '.' . $b64(json_encode($payload));
    return $in . '.' . $b64(hash_hmac(['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'][$header['alg']], $in, $key, true));
};
switch ($argv[1] ?? '') {
    case 'valid':      echo JWT::encode($claims, $priv, 'RS256', 'verify1'); break;
    case 'none':       echo $raw(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'verify1'], $claims, ''); break;
    case 'none-upper': echo $raw(['alg' => 'NONE', 'typ' => 'JWT', 'kid' => 'verify1'], $claims, ''); break;
    // 古典的な取り違え: 公開鍵(PEM)を HMAC の鍵にする
    case 'hs256-pem':  echo $hs(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'verify1'], $claims, $pubPem); break;
    // 公開鍵の n(JWKS に載っている値)を HMAC の鍵にする
    case 'hs256-n':    echo $hs(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'verify1'], $claims, $jwk['n']); break;
    case 'hs256-nokid': echo $hs(['alg' => 'HS256', 'typ' => 'JWT'], $claims, $pubPem); break;
    // 同じ鍵で、ヘッダの alg だけを RS512 にする(鍵の alg は RS256)
    case 'rs512':      echo JWT::encode($claims, $priv, 'RS512', 'verify1'); break;
    case 'unknown-kid': echo JWT::encode($claims, $priv, 'RS256', 'no-such-kid'); break;
    case 'no-kid':     echo JWT::encode($claims, $priv, 'RS256'); break;
    // 署名はそのままで、中身(scope)だけ書き換える
    case 'tampered':
        [$h, , $s] = explode('.', JWT::encode($claims, $priv, 'RS256', 'verify1'));
        echo $h . '.' . $b64(json_encode(['scope' => 'staff:event:access admin'] + $claims)) . '.' . $s; break;
    // 自分で作った鍵で署名し、その公開鍵をヘッダの jwk に埋める(埋め込み鍵を信じるか)
    case 'embedded-jwk':
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($k, $kp); $d = openssl_pkey_get_details($k)['rsa'];
        echo JWT::encode($claims, $kp, 'RS256', 'verify1', ['jwk' => ['kty' => 'RSA', 'n' => $b64($d['n']), 'e' => $b64($d['e']), 'kid' => 'verify1']]); break;
    case 'expired':    echo JWT::encode(['iat' => $now - 7200, 'exp' => $now - 3600] + $claims, $priv, 'RS256', 'verify1'); break;
    default: fwrite(STDERR, "種類が不明\n"); exit(2);
}
