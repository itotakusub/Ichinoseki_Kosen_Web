<?php

declare(strict_types=1);

/**
 * Logto Management API クライアント(ユーザー一覧の取得に使う)。
 *
 * 管理画面のログインに使っている logto/sdk は OIDC のサインインしか持たず、Management API の
 * クライアントは含まれていない。そのため logto_guard.php の logto_jwks() と同じ流儀
 * (素の curl + タイムアウト + TLS 検証は必ず有効 + src/cache/ へキャッシュ)で
 * 最小限だけ自前で書く。
 *
 * 資格情報は lib/db.php と同じ「環境変数 → config/logto-m2m.local.php → 既定値」の二段構え。
 * フェーズ4で compose の環境変数が効かない事例があったため、ファイル側の逃げ道を必ず残す。
 *
 * appSecret は**サーバー側でしか読まない**(lib/soketi.php の SOKETI_APP_SECRET と同じ原則)。
 * 画面や JS へ渡すことは一切しない。
 */

require_once __DIR__ . '/site.php';

/**
 * Logto まわりのキャッシュの置き場。**sys_get_temp_dir() は使わない**(2026-09-14)。
 *
 * 以前は共有の tmp に置き、読むときに持ち主も権限も見ていなかった
 * (security-review-2026-09-10 の 11)。同じ場所に先にファイルを置ければ、
 * **停止したアカウントを「停止されていない」と読ませられる**。
 * logto_guard.php の JWKS と同じく src/cache/(0700)に置き、
 * **自分の持ち物で、他人に読み書きの権限が無いファイルだけを信用する。**
 * 置き場を作れなければ null(キャッシュなしで動く)。
 */
function km_logto_private_cache_path(string $name): ?string
{
    $directory = __DIR__ . '/../cache';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('KosenMap Logto: キャッシュの置き場を作れません: ' . $directory);
        return null;
    }

    return $directory . DIRECTORY_SEPARATOR . $name;
}

/** 信用できるときだけ中身を返す。書くときは 0600 なので、group / other の権限があれば信用しない。 */
function km_logto_private_cache_read(?string $path): ?string
{
    if ($path === null || !is_file($path)) {
        return null;
    }
    $stat = @stat($path);
    if ($stat === false) {
        return null;
    }
    if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
        return null;
    }
    // Windows(手元の検査)は権限の値が当てにならないので、Linux のときだけ見る
    if (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0) {
        return null;
    }
    $content = @file_get_contents($path);

    return is_string($content) && $content !== '' ? $content : null;
}

/** ランダム名へ 0600 で書いてから rename する(宛先のシンボリックリンクを辿らない)。 */
function km_logto_private_cache_write(?string $path, string $payload): void
{
    if ($path === null) {
        return;
    }
    $temporaryPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($temporaryPath, $payload, LOCK_EX) !== false) {
        @chmod($temporaryPath, 0600);
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
        }
    }
}

/**
 * 資格情報の種類。
 *
 * 'default'  管理画面のユーザー管理などが使う。Users の書き込み権限を持つ
 * 'readonly' 参照しかしない口が使う
 *
 * 狙いは「**未ログインでも叩ける口('readonly')から、書き込みできる資格情報を
 * 使わない**」こと。実際に注入経路がなくても、あとで手を入れたときの事故の範囲が変わる。
 *
 * ## ⚠ ただし、いまの Logto では **この分離を実現できない**
 *
 * Management API リソース(`https://default.logto.app/api`)が持つスコープは
 * **`all` の1つだけ**で、読み取りだけを切り出す手段が無い。独自リソースに
 * read:user 等を作っても、**それは別のリソース**なので Management API では 403 になる。
 *
 * 2026-08-28、本番でこれを踏んだ —— 読み取り専用のつもりの資格情報が 403 を返し、
 * km_logto_user_is_suspended() が例外を投げ、guard がフェイルクローズして
 * **管理画面が全面停止した**(Logto Console も同じゲートの内側なので直しに行けない)。
 *
 * **そのため readonly は空にして運用している**(空なら default に落ちる)。
 * 本当に分けたいなら、ロールではなく **公開の口が Management API を使わない形**
 * にする必要がある。定数と分岐は、その日のために残してある。
 */
const KM_LOGTO_M2M_PROFILES = ['default', 'readonly'];

/**
 * readonly から default へ落とした事実を、1リクエストのあいだ持ち回る。
 *
 * 引数を渡すと記録、引数なしで読み出し。管理画面の見出し
 * (admin/_inc/partials/page-header.php)が帯として出す。
 *
 * **error_log だけでは気付けない。** 落とし込みは「動いてしまう」ので、
 * 誰かがログを読むまで readonly が壊れたままになる。画面に出して気付けるようにする。
 *
 * 停止判定は60秒キャッシュするので、キャッシュに当たった間は帯が出ない。
 * 消えたことを「直った」と読まないこと —— 判断は error_log を見る。
 */
function km_logto_m2m_fallback_notice(?string $reason = null): ?string
{
    static $held = null;
    if ($reason !== null) {
        $held = $reason;
    }
    return $held;
}

/** @return array{appId:string, appSecret:string, endpoint:string, resource:string} */
function km_logto_m2m_config(string $profile = 'default'): array
{
    static $cache = [];
    if (isset($cache[$profile])) {
        return $cache[$profile];
    }

    $path = __DIR__ . '/../config/logto-m2m.local.php';
    $local = is_file($path) ? require $path : [];
    if (!is_array($local)) {
        $local = [];
    }

    $read = static function (string $envKey, string $localKey, ?string $default) use ($local): ?string {
        $env = getenv($envKey);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        $value = $local[$localKey] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        return $default;
    };

    if ($profile === 'readonly') {
        $readonlyAppId = $read('LOGTO_M2M_READONLY_APP_ID', 'readonlyAppId', null);
        $readonlySecret = $read('LOGTO_M2M_READONLY_APP_SECRET', 'readonlyAppSecret', null);
        // 片方だけ書かれている状態は設定ミス。黙って default へ落ちると
        // 「読み取り専用にしたつもりが書けるまま」になるので、はっきり止める。
        if (($readonlyAppId === null) !== ($readonlySecret === null)) {
            throw new RuntimeException(
                '読み取り専用 m2m の設定が片方だけです。'
                . 'readonlyAppId と readonlyAppSecret は両方書くか、両方消してください。'
            );
        }
        if ($readonlyAppId === null) {
            // 未設定なら従来どおり。**先に動くことを優先し、権限の分離は任意にする。**
            $cache[$profile] = km_logto_m2m_config('default');
            return $cache[$profile];
        }
        $cache[$profile] = [
            'appId' => $readonlyAppId,
            'appSecret' => $readonlySecret,
            'endpoint' => rtrim((string) $read('LOGTO_M2M_ENDPOINT', 'endpoint', km_site_url('logto-core')), '/'),
            'resource' => (string) $read('LOGTO_M2M_RESOURCE', 'resource', 'https://default.logto.app/api'),
        ];
        return $cache[$profile];
    }

    $appId = $read('LOGTO_M2M_APP_ID', 'appId', null);
    $appSecret = $read('LOGTO_M2M_APP_SECRET', 'appSecret', null);

    if ($appId === null || $appSecret === null) {
        throw new RuntimeException(
            'Logto m2m の資格情報が設定されていません。'
            . 'compose.yaml の LOGTO_M2M_APP_ID / LOGTO_M2M_APP_SECRET か、'
            . 'config/logto-m2m.local.php を確認してください。'
        );
    }

    $cache[$profile] = [
        'appId' => $appId,
        'appSecret' => $appSecret,
        'endpoint' => rtrim((string) $read('LOGTO_M2M_ENDPOINT', 'endpoint', km_site_url('logto-core')), '/'),
        'resource' => (string) $read('LOGTO_M2M_RESOURCE', 'resource', 'https://default.logto.app/api'),
    ];

    return $cache[$profile];
}

/**
 * client_credentials でアクセストークンを取り、期限までファイルへキャッシュする。
 *
 * JWKS のキャッシュ(固定300秒)と違い、TTL はレスポンスの expires_in から余裕を引いて決める。
 * また **JWKS と違いこれは bearer 秘密**なので、キャッシュファイルは他ユーザーから読めないよう
 * 0600 で作る(共有 tmp に平文で置くため、ここは手を抜けない)。
 */
function km_logto_m2m_token(string $profile = 'default'): string
{
    $config = km_logto_m2m_config($profile);
    // 鍵に appId を含めているので、default と readonly でファイルが分かれる。
    $cachePath = km_logto_private_cache_path(
        'kosenmap-logto-m2m-' . hash('sha256', $config['endpoint'] . '|' . $config['resource'] . '|' . $config['appId']) . '.json'
    );

    $cached = km_logto_private_cache_read($cachePath);
    if ($cached !== null) {
        $json = json_decode($cached, true);
        if (is_array($json) && isset($json['token'], $json['expiresAt']) && (int) $json['expiresAt'] > time()) {
            return (string) $json['token'];
        }
    }

    $tokenUrl = $config['endpoint'] . '/oidc/token';
    $curl = curl_init($tokenUrl);
    if ($curl === false) {
        throw new RuntimeException('Logto へのリクエストを初期化できませんでした。');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'resource' => $config['resource'],
            'scope' => 'all',
        ]),
        CURLOPT_USERPWD => $config['appId'] . ':' . $config['appSecret'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        // 自己署名 CA でも検証は必ず有効のまま。web コンテナは /tmp/combined-ca.pem を
        // CURL_CA_BUNDLE で見ているので、追加の指定は要らない。
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($body) || $status !== 200) {
        error_log("km_logto_m2m_token failed: status={$status} curl={$curlError} body=" . substr((string) $body, 0, 200));
        throw new RuntimeException('Logto のアクセストークンを取得できませんでした。m2m アプリの ID とシークレットを確認してください。');
    }

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['access_token'])) {
        throw new RuntimeException('Logto のトークン応答を解釈できませんでした。');
    }

    $expiresIn = isset($json['expires_in']) ? (int) $json['expires_in'] : 3600;
    // 期限ぎりぎりで使って 401 になるのを避けるため、60秒の余裕を引く
    $expiresAt = time() + max(60, $expiresIn - 60);

    /*
     * ランダム名の新しいファイルへ 0600 で書いてから rename する。
     * logto_guard.php の JWKS キャッシュと同じ手口。
     *
     * 以前は touch → chmod → file_put_contents だったが、共有の tmp では
     * **攻撃者が先に $cachePath をシンボリックリンクとして置ける**。その場合
     * touch も書き込みもリンク先へ抜けるので、bearer 秘密が相手の読める場所に落ちる。
     * rename は宛先のリンクを辿らず置き換えるので、この経路が塞がる。
     */
    km_logto_private_cache_write(
        $cachePath,
        (string) json_encode(['token' => $json['access_token'], 'expiresAt' => $expiresAt])
    );

    return (string) $json['access_token'];
}

/**
 * Management API を GET して配列で返す。
 *
 * $profile は使う資格情報。参照しかしない口は 'readonly' を渡すこと
 * (KM_LOGTO_M2M_PROFILES の説明を参照)。
 */
function km_logto_management_get(string $path, array $query = [], string $profile = 'default'): array
{
    $config = km_logto_m2m_config($profile);
    $url = $config['endpoint'] . '/api/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Logto へのリクエストを初期化できませんでした。');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . km_logto_m2m_token($profile),
        ],
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($status === 401 || $status === 403) {
        error_log("km_logto_management_get denied: {$path} status={$status}");
        throw new RuntimeException(
            'Logto Management API へのアクセスが拒否されました(' . $status . ')。'
            . 'Logto Console で m2m アプリに Management API のロールが割り当てられているか確認してください。'
        );
    }
    if (!is_string($body) || $status !== 200) {
        error_log("km_logto_management_get failed: {$path} status={$status} curl={$curlError}");
        throw new RuntimeException('Logto から応答がありませんでした。');
    }

    $json = json_decode($body, true);

    return is_array($json) ? $json : [];
}

/**
 * Logto の利用者を**消す**(`DELETE /api/users/{id}`)。
 *
 * ## なぜ Management API なのか
 *
 * **Account API には削除が無い**(2026-09-03 に実測)。本人のトークンで
 * 自分を消すことはできず、Management API を使うしかない。
 * つまり **m2m の資格情報でこちらが代理で消す**形になる。
 *
 * ## 呼ぶ前に必ず本人だと確かめること
 *
 * この関数は**渡された id をそのまま消す。** 誰の id かは見ない ——
 * 見ようがないので、**呼ぶ側が本人確認をする責任を持つ。**
 * `account.php` はサインイン中の `sub` しか渡さない(利用者が id を選べない)。
 *
 * ## GET と分けてある
 *
 * `km_logto_management_get()` に手を入れて動詞を引数にすると、
 * **読むだけのつもりの呼び出しが1文字の間違いで消す呼び出しになる。**
 * 消す操作は名前で分かる別の関数にしておく。
 *
 * @throws RuntimeException 消せなかったとき(**消えたと誤解させない**)
 */
function km_logto_management_delete_user(string $userId, string $profile = 'default'): void
{
    $userId = trim($userId);
    if ($userId === '') {
        throw new InvalidArgumentException('利用者 ID が空です。');
    }

    $config = km_logto_m2m_config($profile);
    $url = $config['endpoint'] . '/api/users/' . rawurlencode($userId);

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Logto へのリクエストを初期化できませんでした。');
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . km_logto_m2m_token($profile),
        ],
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    /*
     * **204 が成功。** 404 は「もう居ない」——
     * こちらの目的(消えていること)は達しているので成功として扱う。
     * 再送や二重押しで失敗にすると、直しようのないエラーを見せることになる。
     */
    if ($status === 204 || $status === 404) {
        return;
    }

    if ($status === 401 || $status === 403) {
        error_log("km_logto_management_delete_user denied: status={$status}");
        throw new RuntimeException(
            'Logto Management API へのアクセスが拒否されました(' . $status . ')。'
            . 'm2m アプリに Management API のロールが割り当てられているか確認してください。'
        );
    }

    error_log(
        'km_logto_management_delete_user failed: status=' . $status
        . ' curl=' . $curlError
        . ' body=' . (is_string($body) ? mb_substr($body, 0, 200) : '(なし)')
    );

    throw new RuntimeException('Logto のアカウントを削除できませんでした(' . $status . ')。');
}

/**
 * Logto の設定を**部分的に書き換える**(`PATCH`)。
 *
 * ## 分けてある理由は delete と同じ
 *
 * `km_logto_management_get()` に動詞を足すと、**読むだけのつもりの呼び出しが書き換えになる。**
 * 書き換える操作は名前で分かる別の関数にしておく。
 *
 * ## 既定の資格情報を使う
 *
 * `readonly` の m2m には書き込みのロールが無い。**呼ぶ側が profile を選べるようにはしない** ——
 * 選べると「readonly を渡したのに書けてしまった」を疑う余地が残る。
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed> 応答の JSON(空なら [])
 * @throws RuntimeException 書き換えられなかったとき(**書けたと誤解させない**)
 */
function km_logto_management_patch(string $path, array $payload): array
{
    $path = ltrim(trim($path), '/');
    if ($path === '') {
        throw new InvalidArgumentException('書き換え先が空です。');
    }

    $config = km_logto_m2m_config('default');
    $url = $config['endpoint'] . '/api/' . $path;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new InvalidArgumentException('送る内容を JSON にできませんでした。');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Logto へのリクエストを初期化できませんでした。');
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . km_logto_m2m_token('default'),
        ],
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($status >= 200 && $status < 300) {
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    if ($status === 401 || $status === 403) {
        error_log("km_logto_management_patch denied: path={$path} status={$status}");
        throw new RuntimeException(
            'Logto Management API へのアクセスが拒否されました(' . $status . ')。'
            . 'm2m アプリに書き込みのロールが割り当てられているか確認してください。'
        );
    }

    error_log(
        'km_logto_management_patch failed: path=' . $path
        . ' status=' . $status
        . ' curl=' . $curlError
        . ' body=' . (is_string($body) ? mb_substr($body, 0, 200) : '(なし)')
    );

    throw new RuntimeException('Logto の設定を書き換えられませんでした(' . $status . ')。');
}

/**
 * Logto に**足す**(`POST`)。組織への追加・組織ロールの付与に使う(lib/staff-org.php)。
 *
 * patch と同じく**名前で動詞が分かる別の関数**にしてあり、資格情報は既定(書ける方)だけを使う。
 *
 * @param array<string, mixed> $payload
 * @return array<mixed> 応答の JSON(空なら [])
 * @throws RuntimeException 足せなかったとき
 */
function km_logto_management_post(string $path, array $payload): array
{
    return km_logto_management_write('POST', $path, $payload);
}

/**
 * Logto から**外す**(`DELETE`)。組織からの除名に使う(lib/staff-org.php)。
 *
 * **404 は成功として扱う**(目的は「居ないこと」。二重押しや再送で失敗を見せない)。
 * 利用者そのものを消す `km_logto_management_delete_user()` とは別物 —— こちらは**関係を外すだけ**。
 *
 * @throws RuntimeException 外せなかったとき
 */
function km_logto_management_delete_path(string $path): void
{
    km_logto_management_write('DELETE', $path, null);
}

/**
 * post / delete_path の中身。**直接呼ばない**(動詞を引数で選べる口を外に見せない)。
 *
 * @param array<string, mixed>|null $payload
 * @return array<mixed>
 * @internal
 */
function km_logto_management_write(string $method, string $path, ?array $payload): array
{
    if (!in_array($method, ['POST', 'DELETE'], true)) {
        throw new InvalidArgumentException('使えない動詞です: ' . $method);
    }
    $path = ltrim(trim($path), '/');
    if ($path === '' || str_contains($path, '..') || preg_match('#^[A-Za-z0-9/_-]+$#', $path) !== 1) {
        throw new InvalidArgumentException('書き換え先の形が不正です。');
    }

    $config = km_logto_m2m_config('default');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . km_logto_m2m_token('default'),
    ];
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new InvalidArgumentException('送る内容を JSON にできませんでした。');
        }
        $options[CURLOPT_POSTFIELDS] = $json;
        $headers[] = 'Content-Type: application/json';
    }
    $options[CURLOPT_HTTPHEADER] = $headers;

    $curl = curl_init($config['endpoint'] . '/api/' . $path);
    if ($curl === false) {
        throw new RuntimeException('Logto へのリクエストを初期化できませんでした。');
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (($status >= 200 && $status < 300) || ($method === 'DELETE' && $status === 404)) {
        $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    if ($status === 401 || $status === 403) {
        error_log("km_logto_management_write denied: {$method} {$path} status={$status}");
        throw new RuntimeException(
            'Logto Management API へのアクセスが拒否されました(' . $status . ')。'
            . 'm2m アプリに書き込みのロールが割り当てられているか確認してください。'
        );
    }

    error_log(
        "km_logto_management_write failed: {$method} {$path} status={$status}"
        . ' curl=' . $curlError
        . ' body=' . (is_string($body) ? mb_substr($body, 0, 200) : '(なし)')
    );

    throw new RuntimeException('Logto への変更ができませんでした(' . $status . ')。');
}

/**
 * そのユーザーが Logto で**停止されているか**。
 *
 * ## なぜ要るのか
 *
 * Logto でアカウントを停止しても、**すでに発行済みのトークンは期限まで有効**で、
 * こちらのセッションもそのまま生き続ける。実際、停止済みの管理者アカウントで
 * ダッシュボードを操作できる状態になっていた(2026-08-28 に判明)。
 *
 * Logto のトークン発行は止まるので「新しくログインはできない」が、
 * **既にログインしている人を追い出す仕組みが無かった**。ここがその仕組み。
 *
 * ## 判定できないときは「停止」とみなす(fail closed)
 *
 * guard.php / gate.php の姿勢に合わせる。Logto へ問い合わせられない状況で
 * 管理画面を開けたままにするより、閉じる方を選ぶ。
 * **呼ぶ側は例外を捕まえて拒否すること。**
 *
 * ## キャッシュ
 *
 * 毎リクエスト問い合わせると重い(Management API への往復が増える)。
 * 60秒だけ覚える —— 既存のゲートのキャッシュと同じ長さにしてあるので、
 * 「停止してから効くまで」の最大待ち時間は変わらない。
 * 参照専用の口なので **readonly の資格情報**を使う。
 */
const KM_LOGTO_SUSPENDED_CACHE_SECONDS = 60;

/**
 * 公開の場に出してよい表示名を、Logto のユーザー情報から選ぶ。
 *
 * ## なぜトークンから取らないのか
 *
 * **API リソース向けのアクセストークンには profile 系のクレームが載らない。**
 * `sub` / `aud` / `scope` などしか入らないので、`logto_guard.php` の
 * `$principal['username']` は**実際には `sub`(UUID)へ落ちている**。
 * 端末のドロワーに名前が出るのは、あちらが**ID トークン**を読んでいるため
 * (`LogtoAuthManager.kt`)。サーバー側とは出所が違う。
 *
 * ## メールアドレスは絶対に使わない
 *
 * ランキングは**来場者にも見える**。`$principal['username']` の並びには
 * `email` が入っているので、そのまま表示名に使うと
 * **利用者のメールアドレスが公開の画面に出る**経路ができる。ここでは使わない。
 *
 * @param array<string, mixed> $user Management API の users/{id} の応答
 */
function km_logto_public_display_name(array $user): ?string
{
    $profile = is_array($user['profile'] ?? null) ? $user['profile'] : [];

    // ニックネーム優先。利用者が「こう呼ばれたい」と決めた名前だから。
    foreach ([$profile['nickname'] ?? null, $user['name'] ?? null, $user['username'] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return null;
}

/**
 * ユーザーの状態をひとまとめに取る。**Logto への往復は1回だけ。**
 *
 * 停止判定と表示名で別々に取りに行くと往復が倍になるので、同じ応答から両方を作る。
 * 呼び出し口は [km_logto_user_is_suspended] と [km_logto_user_display_name]。
 *
 * @return array{suspended:bool, displayName:?string}
 */
function km_logto_user_snapshot(string $userId): array
{
    static $memory = [];

    $userId = trim($userId);
    if ($userId === '') {
        // 誰か分からないものを通さない
        return ['suspended' => true, 'displayName' => null];
    }

    // 同じリクエスト内で何度も呼ばれる(guard → 各ページ)
    if (isset($memory[$userId])) {
        return $memory[$userId];
    }

    // **接頭辞は 'user'。** 以前の 'suspended' 時代のファイルには displayName が無く、
    // そのまま読むと名前だけ null になる。名前を変えて作り直させる。
    // **信用できるファイルだけを読む**(km_logto_private_cache_read)。停止判定を左右するので特に
    $cachePath = km_logto_private_cache_path('kosenmap-logto-user-' . hash('sha256', $userId) . '.json');

    $cachedRaw = km_logto_private_cache_read($cachePath);
    if ($cachedRaw !== null) {
        $cached = json_decode($cachedRaw, true);
        if (is_array($cached) && (int) ($cached['expiresAt'] ?? 0) > time()) {
            $name = $cached['displayName'] ?? null;
            return $memory[$userId] = [
                'suspended' => (bool) ($cached['suspended'] ?? true),
                'displayName' => is_string($name) && $name !== '' ? $name : null,
            ];
        }
    }

    /*
     * 参照だけなので readonly のプロファイル。未設定なら既定へ落ちる。
     *
     * **readonly で失敗したら default で1回だけ試し直す。**
     *
     * 2026-08-28、readonly の資格情報が 403 を返しただけで**管理画面が全面停止した**。
     * 呼び出し元(admin/_inc/guard.php)がフェイルクローズする作りで、しかも
     * Logto Console も同じゲートの内側にあるため、**直しに行く手段ごと失われる**。
     *
     * 補助的な資格情報の不調で管理の入口を失うのは割に合わない。default でも
     * 駄目なら従来どおり例外を投げて閉じる ——「Logto に問い合わせられないなら開けない」
     * という方針自体は変えない。落ちたことは error_log に必ず残す。
     */
    try {
        $user = km_logto_management_get('users/' . rawurlencode($userId), [], 'readonly');
    } catch (Throwable $readonlyFailure) {
        error_log(
            'KosenMap Logto: readonly の資格情報で停止判定に失敗しました。default で試し直します。'
            . ' 原因: ' . $readonlyFailure::class . ': ' . $readonlyFailure->getMessage()
            . ' —— readonly を使うなら .env の LOGTO_M2M_READONLY_* を見直してください'
            . '(Management API のスコープは all の1つだけで、読み取り専用は作れません)。'
        );
        km_logto_m2m_fallback_notice(
            '読み取り専用の Logto 資格情報(LOGTO_M2M_READONLY_*)が使えないため、'
            . '管理用の資格情報で代替しています。設定を見直してください。'
        );
        // ここで投げれば従来どおり閉じる。落とし先が無いときだけそうなる。
        $user = km_logto_management_get('users/' . rawurlencode($userId), [], 'default');
    }
    /*
     * **利用者の形をしていない応答で「停止されていない」と読まない。**
     * 以前は 200 で中身が違っても `isSuspended` が無い = false(通す)になっていた
     * (security-review-2026-09-10 の 12)。投げれば呼ぶ側が閉じる(判定できないときは閉じる方針)。
     */
    if (!is_array($user) || trim((string) ($user['id'] ?? '')) === '' || !array_key_exists('isSuspended', $user)) {
        throw new RuntimeException('Logto の応答が利用者の形ではありません(停止判定ができません)。');
    }
    $snapshot = [
        'suspended' => (bool) ($user['isSuspended'] ?? false),
        'displayName' => km_logto_public_display_name(is_array($user) ? $user : []),
    ];

    // JWKS / m2m トークンと同じ手口(ランダム名へ 0600 で書いてから rename)
    km_logto_private_cache_write(
        $cachePath,
        (string) json_encode($snapshot + ['expiresAt' => time() + KM_LOGTO_SUSPENDED_CACHE_SECONDS])
    );

    return $memory[$userId] = $snapshot;
}

/**
 * 停止されているか。**判定できないときは true**(fail closed)。
 * 呼ぶ側は例外を捕まえて拒否すること。
 */
function km_logto_user_is_suspended(string $userId): bool
{
    return km_logto_user_snapshot($userId)['suspended'];
}

/**
 * 公開の場に出してよい表示名。取れなければ null。
 *
 * **停止判定と同じ応答から作るので、Logto への往復は増えない。**
 * 名前が無い利用者もいるので、呼ぶ側は null を扱えること。
 */
function km_logto_user_display_name(string $userId): ?string
{
    try {
        return km_logto_user_snapshot($userId)['displayName'];
    } catch (Throwable $exception) {
        // **名前が取れないだけで記録を落とさない。** 停止判定はこの前に通っている。
        error_log('km_logto_user_display_name failed: ' . $exception->getMessage());
        return null;
    }
}

/**
 * ユーザー一覧。画面に必要な形へ整えて返す。
 *
 * ロールは GET /api/users/{id}/roles が**ユーザーごとの追加リクエスト**になる(N+1)。
 * 今の利用者数なら許容できるので表示するぶんだけ取り、失敗しても一覧自体は出す(fail soft)。
 * 人数が増えて重くなったらここを見直す。
 *
 * @return array<int, array{id:string, name:string, email:string, avatar:?string,
 *                          isSuspended:bool, lastSignInAtEpoch:?int, roles:array<int,string>}>
 */
function km_logto_users(int $limit = 50, string $search = ''): array
{
    $query = ['page' => 1, 'page_size' => max(1, min(100, $limit))];
    if ($search !== '') {
        $query['search'] = '%' . $search . '%';
    }

    $rows = km_logto_management_get('users', $query);

    $users = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['id'])) {
            continue;
        }
        $id = (string) $row['id'];

        $roles = [];
        try {
            foreach (km_logto_management_get("users/{$id}/roles") as $role) {
                if (is_array($role) && isset($role['name'])) {
                    $roles[] = (string) $role['name'];
                }
            }
        } catch (Throwable $exception) {
            // ロールが取れなくても一覧は出す
            error_log("km_logto_users: roles for {$id} failed: " . $exception->getMessage());
        }

        // Logto の lastSignInAt はミリ秒。秒へ直す。
        $lastSignIn = $row['lastSignInAt'] ?? null;

        $users[] = [
            'id' => $id,
            'name' => (string) ($row['name'] ?? $row['username'] ?? $row['primaryEmail'] ?? $id),
            'email' => (string) ($row['primaryEmail'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== '' ? (string) $row['avatar'] : null,
            'isSuspended' => (bool) ($row['isSuspended'] ?? false),
            'lastSignInAtEpoch' => is_numeric($lastSignIn) ? (int) ((int) $lastSignIn / 1000) : null,
            'roles' => $roles,
        ];
    }

    return $users;
}
