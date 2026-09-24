<?php

declare(strict_types=1);

/*
 * 既定値のホスト名は lib/site.php(APP_URL 由来)から組む。
 * ここに IP を直書きしていると、ドメインへ移したときに
 * **env を設定し忘れた経路だけが古い宛先を見に行く**という分かりにくい壊れ方をする。
 */
require_once __DIR__ . '/lib/site.php';

$localConfig = [];
$localConfigPath = __DIR__ . '/logto_config.local.php';
if (is_file($localConfigPath)) {
    $loadedLocalConfig = require $localConfigPath;
    if (is_array($loadedLocalConfig)) {
        $localConfig = $loadedLocalConfig;
    } else {
        $localConfig = [
            'logtoEndpoint' => $logtoEndpoint ?? $logto_endpoint ?? null,
            'logtoIssuer' => $logtoIssuer ?? $logto_issuer ?? null,
            'logtoAudience' => $logtoAudience ?? $logto_audience ?? null,
            'logtoAppId' => $logtoAppId ?? $logto_app_id ?? null,
            'logtoJwksCacheDir' => $logtoJwksCacheDir ?? $logto_jwks_cache_dir ?? null,
        ];
    }
}

function logto_config_value(
    array $localConfig,
    string $environmentKey,
    array $localKeys,
    string $default = ''
): string {
    $environmentValue = getenv($environmentKey);
    if (is_string($environmentValue) && trim($environmentValue) !== '') {
        return trim($environmentValue);
    }
    foreach ($localKeys as $key) {
        if (array_key_exists($key, $localConfig) && trim((string) $localConfig[$key]) !== '') {
            return trim((string) $localConfig[$key]);
        }
    }
    return $default;
}

$kosenmapLogtoConfig = [
    'logtoEndpoint' => logto_config_value(
        $localConfig,
        'KOSENMAP_LOGTO_ENDPOINT',
        ['KOSENMAP_LOGTO_ENDPOINT', 'logtoEndpoint', 'logto_endpoint'],
        km_site_url('logto-core')
    ),
    'logtoIssuer' => logto_config_value(
        $localConfig,
        'KOSENMAP_LOGTO_ISSUER',
        ['KOSENMAP_LOGTO_ISSUER', 'logtoIssuer', 'logto_issuer'],
        km_site_url('logto-core', '/oidc')
    ),
    'logtoAudience' => logto_config_value(
        $localConfig,
        'KOSENMAP_LOGTO_AUDIENCE',
        ['KOSENMAP_LOGTO_AUDIENCE', 'logtoAudience', 'logto_audience'],
        km_site_url(null, '/api')
    ),
    'logtoAppId' => logto_config_value(
        $localConfig,
        'KOSENMAP_LOGTO_APP_ID',
        ['KOSENMAP_LOGTO_APP_ID', 'logtoAppId', 'logto_app_id'],
        'z13cvqi4yyfn5nby0q4g6'
    ),
    /*
     * `logtoCaBundle` は 1.0.5 で外した。CA は compose の `CURL_CA_BUNDLE` 一本で決める
     * (理由は logto_guard.php の JWKS 取得部分に書いてある)。
     */
    /*
     * JWKSキャッシュの保存先。空なら logto_guard.php と同じ階層の cache/ を 0700 で作る。
     *
     * 共有ホストなど、他アカウントから書き込める場所しか使えない環境では、Web公開範囲外の
     * 専有ディレクトリを絶対パスで指定すること。予測可能かつ書き込み可能な場所に置くと、
     * 攻撃者が自分の公開鍵を書いた鍵束を先置きでき、偽造トークンが検証を通ってしまう。
     */
    'logtoJwksCacheDir' => logto_config_value(
        $localConfig,
        'KOSENMAP_LOGTO_JWKS_CACHE_DIR',
        ['KOSENMAP_LOGTO_JWKS_CACHE_DIR', 'logtoJwksCacheDir', 'logto_jwks_cache_dir']
    ),
];
