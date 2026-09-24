<?php

declare(strict_types=1);

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

const LOGTO_ADMIN_PERMISSIONS = [
    'admin:users:read',
    'admin:users:write',
    'admin:api-keys:read',
    'admin:api-keys:write',
];

/**
 * 運営スタッフ区分。管理者権限は持たないが、イベントの通行止めを通過でき、
 * スタッフ限定の地点を閲覧できる。permissions にそのまま載せてAndroidへ返す。
 * api/app-map.php が、スタッフ限定の一時地点を含めるかの判定に使う。
 */
const LOGTO_STAFF_PERMISSION = 'staff:event:access';

function logto_authorization_header(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                return trim((string) $value);
            }
        }
    }
    return $header;
}

/** Bearerトークンを取り出す。無い・形式が違うなら null（応答しない）。 */
function logto_bearer_token_or_null(): ?string
{
    $header = logto_authorization_header();
    if (!preg_match('/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)$/', $header, $matches)) {
        return null;
    }
    return $matches[1];
}

function logto_bearer_token(): string
{
    $token = logto_bearer_token_or_null();
    if ($token === null) {
        respond(['success' => false, 'message' => 'Logtoでログインしてください。'], 401);
    }
    return $token;
}

function logto_config(string $key): string
{
    global $kosenmapLogtoConfig;
    $value = trim((string) ($kosenmapLogtoConfig[$key] ?? ''));
    if ($value === '') {
        error_log("KosenMap Logto configuration is incomplete: {$key}");
        respond(['success' => false, 'message' => 'Logtoサーバー設定を確認できません。'], 500);
    }
    return $value;
}

/**
 * JWKSキャッシュの保存先を返す。作成できない場合は null(キャッシュなしで動作する)。
 *
 * **sys_get_temp_dir() は使わない。** JWKSのURLは公開情報なのでファイル名を誰でも
 * 計算でき、同じホストの他アカウントが自分の公開鍵を書いた鍵束を先置きできてしまう。
 * それを読むと偽造トークンが検証を通り、認証バイパスと管理者昇格が成立する。
 *
 * 既定は src/cache/。nginx の deny 一覧にも入れてあるので直接は配信されない
 * (JWKS自体は公開情報なので、読まれること自体は問題ではない。守りたいのは書き込み)。
 */
function logto_jwks_cache_path(string $jwksUrl): ?string
{
    global $kosenmapLogtoConfig;
    $configured = trim((string) ($kosenmapLogtoConfig['logtoJwksCacheDir'] ?? ''));
    $directory = $configured !== '' ? rtrim($configured, '/\\') : __DIR__ . '/cache';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('KosenMap Logto JWKS cache directory is not writable: ' . $directory);
        return null;
    }
    return $directory . DIRECTORY_SEPARATOR . 'logto-jwks-' . hash('sha256', $jwksUrl) . '.json';
}

/** 自分が所有していて、他ユーザーが書き込めないキャッシュだけを信用する。 */
function logto_jwks_cache_is_trusted(string $path): bool
{
    $stat = @stat($path);
    if ($stat === false) {
        return false;
    }
    if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
        return false;
    }
    // group / other に書き込み権があるものは差し替えられている可能性がある。
    return ($stat['mode'] & 0022) === 0;
}

function logto_jwks(): array
{
    $endpoint = rtrim(logto_config('logtoEndpoint'), '/');
    $jwksUrl = $endpoint . '/oidc/jwks';
    $cachePath = logto_jwks_cache_path($jwksUrl);
    $cached = false;
    if ($cachePath !== null
        && is_file($cachePath)
        && logto_jwks_cache_is_trusted($cachePath)
        && filemtime($cachePath) >= time() - 300
    ) {
        $cached = file_get_contents($cachePath);
    }
    if (is_string($cached) && $cached !== '') {
        $json = json_decode($cached, true);
        if (is_array($json) && isset($json['keys'])) {
            return $json;
        }
    }

    $curl = curl_init($jwksUrl);
    if ($curl === false) {
        respond(['success' => false, 'message' => 'Logto公開鍵を取得できません。'], 503);
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    /*
     * CA の指定はしない(1.0.5 で外した)。
     *
     * 以前は KOSENMAP_LOGTO_CA_BUNDLE を CURLOPT_CAINFO に流していたが、これは
     * **同じことを2通りで指定していた**だけだった。compose.yaml が
     * `CURL_CA_BUNDLE=/tmp/combined-ca.pem` を渡しており、libcurl はこれを自分で読む。
     * その中身は start-with-local-ca.sh が組み立てる(mkcert の CA があれば足し、
     * 無ければシステムの CA だけ)。
     *
     * **2通りあるのが危なかった。** VPS では mkcert の CA を落としたので
     * `/etc/ssl/certs/rootCA.pem` は存在しない。`.env` にその行が残ったままだと
     * CURLOPT_CAINFO が実在しないファイルを指し、**JWKS が取れず管理画面へ入れなくなる**。
     * 環境変数1本にすれば、置き忘れた行で認証が落ちることが無い。
     *
     * `.env` の KOSENMAP_LOGTO_CA_BUNDLE は消してよい(残っていても無視される)。
     */
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($body) || $status !== 200) {
        error_log('KosenMap Logto JWKS request failed. status=' . $status . ' error=' . $error);
        respond(['success' => false, 'message' => 'Logto公開鍵を取得できません。'], 503);
    }
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['keys']) || !is_array($json['keys'])) {
        error_log('KosenMap Logto JWKS response is invalid.');
        respond(['success' => false, 'message' => 'Logto公開鍵の形式を確認できません。'], 503);
    }
    if ($cachePath !== null) {
        // 0600 を付けてから rename する。書き込み途中のファイルが見えないようにし、
        // 他ユーザーが読み書きできる状態の瞬間も作らない。
        $temporaryPath = $cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporaryPath, $body, LOCK_EX) !== false) {
            @chmod($temporaryPath, 0600);
            if (!@rename($temporaryPath, $cachePath)) {
                @unlink($temporaryPath);
            }
        }
    }
    return $json;
}

function logto_audiences(mixed $audience): array
{
    if (is_string($audience)) {
        return [$audience];
    }
    return is_array($audience)
        ? array_values(array_filter($audience, 'is_string'))
        : [];
}

function logto_scopes(mixed $scope): array
{
    if (is_string($scope)) {
        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
    }
    return is_array($scope)
        ? array_values(array_filter($scope, 'is_string'))
        : [];
}

/**
 * 認証が任意のエンドポイント用。
 *
 * トークンが無い・検証を通らないときは **401ではなく null** を返す。呼び出し側は
 * 未ログイン扱いで処理を続ける。api/app-map.php で、ログインが切れただけで
 * 地図そのものを取得できなくなるのを避けるために使う。
 *
 * 既知の制限: Logtoの公開鍵を取得できないとき（logto_jwks() が503で応答する）は、
 * トークンが付いているリクエストがそこで終わる。未ログインのリクエストには影響しない。
 */
function logto_optional_principal(): ?array
{
    if (logto_bearer_token_or_null() === null) {
        return null;
    }
    return logto_principal(false);
}

function logto_require_principal(): array
{
    $principal = logto_principal(true);
    // $required=true の経路は必ず配列を返すか respond() で終了する。
    return $principal ?? [];
}

function logto_principal(bool $required): ?array
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('KosenMap Logto JWT dependency is not installed. Run composer install in php/.');
        if (!$required) {
            return null;
        }
        respond(['success' => false, 'message' => 'Logto JWT検証機能が未配置です。'], 500);
    }
    require_once $autoload;

    $token = logto_bearer_token_or_null();
    if ($token === null) {
        if (!$required) {
            return null;
        }
        respond(['success' => false, 'message' => 'Logtoでログインしてください。'], 401);
    }

    try {
        JWT::$leeway = 30;
        $claims = (array) JWT::decode($token, JWK::parseKeySet(logto_jwks()));
        $issuer = logto_config('logtoIssuer');
        $audience = logto_config('logtoAudience');
        $appId = logto_config('logtoAppId');
        if (!isset($claims['iss']) || !is_string($claims['iss']) || !hash_equals($issuer, $claims['iss'])) {
            if (!$required) {
                return null;
            }
            respond(['success' => false, 'message' => 'Logtoトークンの発行元が一致しません。'], 401);
        }
        if (!in_array($audience, logto_audiences($claims['aud'] ?? null), true)) {
            if (!$required) {
                return null;
            }
            respond(['success' => false, 'message' => 'Logtoトークンの対象APIが一致しません。'], 401);
        }
        if (!isset($claims['client_id']) || !is_string($claims['client_id'])
            || !hash_equals($appId, $claims['client_id'])) {
            if (!$required) {
                return null;
            }
            respond(['success' => false, 'message' => 'LogtoアプリIDが一致しません。'], 401);
        }
        $subject = trim((string) ($claims['sub'] ?? ''));
        if ($subject === '') {
            if (!$required) {
                return null;
            }
            respond(['success' => false, 'message' => 'Logtoユーザーを特定できません。'], 401);
        }
        /*
         * **停止されたアカウントを通さない。**
         *
         * 署名が正しいことは「発行した時点で正しかった」ことしか意味しない。
         * Logto でアカウントを停止しても**発行済みトークンは期限まで有効**なので、
         * ここで現在の状態を見る必要がある(管理画面側と同じ判定。60秒だけ覚える)。
         *
         * $required=false(api/app-map.php)では**未ログイン扱いにする**。
         * 地図そのものは誰でも見られるべきで、停止された人にだけ
         * 「ログイン済みの特典」を渡さなければよい。
         */
        require_once __DIR__ . '/lib/logto-management.php';
        if (km_logto_user_is_suspended($subject)) {
            error_log('KosenMap Logto: suspended account was blocked (bearer): ' . $subject);
            if (!$required) {
                return null;
            }
            respond(['success' => false, 'message' => 'このアカウントは停止されています。'], 403);
        }

        $permissions = logto_scopes($claims['scope'] ?? null);

        /*
         * **組織トークン**(docs/15。2026-09-18)。教職員の権限は Logto の組織ロールから来るので、
         * Android は `getAccessToken(resource, organizationId)` で取る。そのトークンには `organization_id` が付く。
         *
         * 守り:
         *   1. `organization_id` が付いていれば、**こちらの組織 ID と一致するときだけ**受け付ける。
         *      aud だけ見ると、別の組織のトークンでも通ってしまう
         *   2. 組織トークンから採る権限は**教職員の権限だけ**。組織ロールに何が付いていても、
         *      管理者の権限(admin:*)には化けさせない
         *   3. 組織を通さないトークンに教職員の権限が載っていても、**教職員とはみなさない**
         *      (教職員の出どころは組織だけ。承認の記録と食い違わせない)
         */
        require_once __DIR__ . '/lib/staff-org.php';
        $organizationId = isset($claims['organization_id']) && is_string($claims['organization_id'])
            ? $claims['organization_id']
            : null;
        // 判定の中身は lib/staff-org.php(check.php から偽のトークン内容で試せるように切り出してある)
        $org = km_staff_org_apply_token($organizationId, $permissions);
        if (!$org['ok']) {
            error_log('KosenMap Logto: token for an unknown organization was refused: ' . (string) $organizationId);
            if (!$required) {
                return null;
            }
            respond(['success' => false, 'message' => 'Logtoトークンの組織が一致しません。'], 401);
        }
        $permissions = $org['permissions'];
        $isTeacher = $org['isTeacher'];

        $username = trim((string) (
            $claims['name']
            ?? $claims['preferred_username']
            ?? $claims['username']
            ?? $claims['email']
            ?? $subject
        ));
        return [
            'id' => null,
            'subject' => $subject,
            'username' => $username,
            'email' => isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null,
            // ここまで来た = 停止されていない
            'status' => 'approved',
            'is_admin' => count(array_intersect(LOGTO_ADMIN_PERMISSIONS, $permissions)) === count(LOGTO_ADMIN_PERMISSIONS) ? 1 : 0,
            'auth_type' => 'logto',
            'permissions' => $permissions,
            // 教職員(組織トークンで、こちらの組織の教職員の権限を持つ)。イベント運営とは別
            'is_teacher' => $isTeacher,
            'organization_id' => $organizationId,
        ];
    } catch (Throwable $exception) {
        error_log('KosenMap Logto JWT validation failed: ' . get_class($exception) . ': ' . $exception->getMessage());
        if (!$required) {
            return null;
        }
        respond(['success' => false, 'message' => 'Logtoセッションを確認できません。'], 401);
    }
}

function logto_assert_permissions(array $principal, array $requiredPermissions): void
{
    $granted = $principal['permissions'] ?? [];
    foreach ($requiredPermissions as $permission) {
        if (!in_array($permission, $granted, true)) {
            respond(['success' => false, 'message' => 'この操作に必要なLogto権限がありません。'], 403);
        }
    }
}
