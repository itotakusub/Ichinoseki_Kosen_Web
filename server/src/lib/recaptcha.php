<?php

declare(strict_types=1);

/**
 * reCAPTCHA v2(「私はロボットではありません」)の検証。公開の問い合わせフォームで使う。
 *
 * **このプロジェクトで唯一の、意図的な外部 CDN 依存。** AdminLTE も Leaflet も pusher-js も
 * 手元へ落として配置しているが、reCAPTCHA のスクリプトは google.com から読むしかなく、
 * 自前でホストできない。承知の上で入れている。
 *
 * 帰結として、**インターネットが切れている間は公開フォームが使えなくなる**。
 * 「検証できないものは通さない」(fail closed)という判断を採ったため。
 * 校内 LAN のフォームなので、送れない時間があることより、素通しでスパムを受けることの方を避ける。
 *
 * ---
 *
 * **クラシック版と Enterprise 版で、サーバー側の検証方法がまったく違う。**
 * Google のコンソールが Google Cloud 側へ統合され、いま新規に作るキーは Enterprise になる。
 *
 * | | クラシック | Enterprise |
 * |---|---|---|
 * | 必要な資格情報 | siteKey + **secretKey** | siteKey + **プロジェクト ID + API キー** |
 * | 検証先 | `siteverify`(フォーム形式) | `assessments`(JSON) |
 * | 合否 | `success` | `tokenProperties.valid` |
 * | 読み込む JS | `api.js` | `enterprise.js` |
 *
 * **置かれている資格情報から、どちらの方式かを自動で決める**(Enterprise を優先)。
 * どちらも揃っていなければ「未設定」として、フォーム自体を出さない。
 *
 * curl の使い方は logto_guard.php の logto_jwks() / lib/logto-management.php と揃えてある
 * (タイムアウトを必ず入れ、TLS 検証は無効化しない)。
 * 設定は lib/db.php と同じ「環境変数 → config/recaptcha.local.php」の二段構え。
 */

require_once __DIR__ . '/site.php';        // km_site_host(): トークンが出たホスト名の照合に使う
require_once __DIR__ . '/user-error.php';  // 利用者に見せる文は KmUserError で投げる

const KM_RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
const KM_RECAPTCHA_ASSESS_URL = 'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments';

/** 問い合わせフォームの action。contact.php の `data-action` と対。 */
const KM_RECAPTCHA_ACTION_CONTACT = 'contact';

/** @return array{siteKey:?string, secretKey:?string, projectId:?string, apiKey:?string} */
function km_recaptcha_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/../config/recaptcha.local.php';
    $local = is_file($path) ? require $path : [];
    if (!is_array($local)) {
        $local = [];
    }

    $read = static function (string $envKey, string $localKey) use ($local): ?string {
        $env = getenv($envKey);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        $value = $local[$localKey] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    };

    $config = [
        'siteKey' => $read('RECAPTCHA_SITE_KEY', 'siteKey'),
        'secretKey' => $read('RECAPTCHA_SECRET_KEY', 'secretKey'),
        'projectId' => $read('RECAPTCHA_PROJECT_ID', 'projectId'),
        'apiKey' => $read('RECAPTCHA_API_KEY', 'apiKey'),
    ];

    return $config;
}

/**
 * 検証に使える方式。`enterprise` / `classic` / 揃っていなければ null。
 *
 * Enterprise を先に見るのは、両方置かれているなら新しい方が現役だと考えられるため。
 */
function km_recaptcha_mode(): ?string
{
    $config = km_recaptcha_config();
    if ($config['siteKey'] === null) {
        return null;
    }
    if ($config['projectId'] !== null && $config['apiKey'] !== null) {
        return 'enterprise';
    }
    if ($config['secretKey'] !== null) {
        return 'classic';
    }

    return null;
}

/** 揃っているときだけフォームを出す。片方だけでは検証できない。 */
function km_recaptcha_configured(): bool
{
    return km_recaptcha_mode() !== null;
}

/**
 * HTML に埋めるサイトキー。**これは秘密ではない**(ブラウザに出る前提のもの)。
 * secretKey / apiKey の方は km_recaptcha_verify() の中だけで使い、外へは一切出さない。
 */
function km_recaptcha_site_key(): ?string
{
    return km_recaptcha_config()['siteKey'];
}

/**
 * 読み込むスクリプトの URL。方式によって別物なので、ここで一元的に決める
 * (画面側が取り違えると、トークンは出るのに検証が通らない、という分かりにくい壊れ方をする)。
 */
function km_recaptcha_script_url(): string
{
    return km_recaptcha_mode() === 'enterprise'
        ? 'https://www.google.com/recaptcha/enterprise.js'
        : 'https://www.google.com/recaptcha/api.js';
}

/**
 * 検証する。通らなければ例外。
 *
 * **利用者に見せる文言と、ログに残す内容を分けている。** Google が返す理由
 * (`invalid-input-secret` / `SITE_MISMATCH` など)は設定ミスの手がかりになるので必ず
 * ログへ出すが、画面には出さない(設定の内情を外へ知らせない)。
 *
 * ## 「通った」だけでは受けない(2026-09-14、診断 critic#2)
 *
 * Google の合否(success / valid)に加えて、**トークンが出たホスト名**がこのサイトかを確かめる。
 * サイトキーは HTML に出る公開値なので、コンソールのドメイン検証が切れていたり、
 * 同じキーを別のサイトでも使っていたりすると、**よそのページで解かせたトークン**が通ってしまう。
 * Enterprise では $expectedAction を渡すと action も照合する(クラシックの v2 は action を持たない)。
 *
 * @param string|null $expectedAction ページで `data-action` に書いた値。null なら照合しない
 * @throws KmUserError 検証を通せなかったとき(失敗も到達不可も同じ扱い。文面は画面に出してよい)
 */
function km_recaptcha_verify(string $token, string $remoteIp, ?string $expectedAction = null): void
{
    $mode = km_recaptcha_mode();
    if ($mode === null) {
        throw new KmUserError('送信の受け付け設定が未完了です。管理者へお知らせください。');
    }
    if (trim($token) === '') {
        // チェックボックスに触れずに送信した場合ここに来る
        throw new KmUserError('「私はロボットではありません」にチェックを入れてください。');
    }

    if ($mode === 'enterprise') {
        km_recaptcha_verify_enterprise($token, $remoteIp, $expectedAction);

        return;
    }

    km_recaptcha_verify_classic($token, $remoteIp);
}

/**
 * トークンが出てよいホスト名。**APP_URL のホスト**が正本。
 *
 * 別名でも開かせる環境(校内 LAN の IP と名前の両方など)だけ、
 * `RECAPTCHA_ALLOWED_HOSTNAMES`(カンマ区切り)で足す。
 *
 * @return list<string> 小文字
 */
function km_recaptcha_allowed_hostnames(): array
{
    $hosts = [strtolower(km_site_host())];
    $extra = getenv('RECAPTCHA_ALLOWED_HOSTNAMES');
    if (is_string($extra) && trim($extra) !== '') {
        foreach (explode(',', $extra) as $host) {
            $host = strtolower(trim($host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
    }

    return array_values(array_unique($hosts));
}

/** 返ってきたホスト名がこのサイトのものか。空(= 分からない)は通さない。 */
function km_recaptcha_hostname_allowed(string $hostname): bool
{
    $hostname = strtolower(trim($hostname));

    return $hostname !== '' && in_array($hostname, km_recaptcha_allowed_hostnames(), true);
}

/** 到達できなかったときの共通の断り方。**通さない**のがこのプロジェクトの決定。 */
function km_recaptcha_unreachable(): KmUserError
{
    return new KmUserError(
        'ただいま送信を受け付けられません(認証の確認に失敗しました)。'
        . 'しばらくしてからもう一度お試しください。'
    );
}

/** 確認が取れなかったときの共通の断り方(利用者の操作でやり直せる場合)。 */
function km_recaptcha_rejected(): KmUserError
{
    return new KmUserError(
        '「私はロボットではありません」の確認が取れませんでした。もう一度お試しください。'
    );
}

/**
 * Google が返したエラー本文を、直し方が分かる日本語にする。
 *
 * **英語のまま出すと、何を直せばよいか分からないまま止まる。** 実際 1.0.0 の設定で
 * `The project number of the API key doesn't match parent project number` が返り、
 * 「API キーを reCAPTCHA キーと同じプロジェクトで作り直す」という結論に辿り着くまで
 * 手間取った。同じ所で止まらないよう、既知の応答は訳して手当てまで書いておく。
 *
 * 当てはまらないものは素通し(推測で断定的なことを書かない)。
 */
function km_recaptcha_explain(string $body): string
{
    $known = [
        "doesn't match parent project number" =>
            'API キーが、URL に指定したプロジェクトとは別のプロジェクトのものです。'
            . 'reCAPTCHA キーがあるプロジェクトで API キーを作り直してください',
        'API_KEY_SERVICE_BLOCKED' =>
            'API キーの制限で reCAPTCHA Enterprise API が許可されていません。'
            . 'キーの「API の制限」に reCAPTCHA Enterprise API を追加してください',
        'API_KEY_HTTP_REFERRER_BLOCKED' =>
            'API キーに HTTP リファラー制限が付いています。'
            . 'サーバーから呼ぶキーはリファラーを送らないので**必ず弾かれます**。'
            . '「アプリケーションの制限」を IP アドレスにして、このサーバーの送信元 IP を入れてください',
        /*
         * **本文に API_KEY_HTTP_REFERRER_BLOCKED が入らない場合がある。**
         * 実際に本番で返ってきたのはこれだけだった(2026-08-29):
         *
         *   {"error":{"code":403,"message":"Requests from this referer are blocked.",
         *             "status":"PERMISSION_DENIED"}}
         *
         * この並びは**先に一致したものを返す**ので、下の 'PERMISSION_DENIED' より
         * 前に置くこと。後ろに置くと「このプロジェクトに対する権限がありません」に
         * 化けて、原因から遠い案内になる(実際にそうなっていた)。
         */
        'Requests from this referer are blocked' =>
            'API キーの「アプリケーションの制限」が HTTP リファラーになっています。'
            . 'サーバーから呼ぶキーはリファラーを送らないので**必ず 403 になります**。'
            . 'IP アドレス制限へ変えて、このサーバーの送信元 IP を登録してください'
            . '(「API の制限」の方は reCAPTCHA Enterprise API のままでよい)',
        'API_KEY_IP_ADDRESS_BLOCKED' =>
            'API キーの IP 制限に、このサーバーの送信元 IP が入っていません',
        'API_KEY_INVALID' => 'API キーが無効です(値の取り違え・削除済みなど)',
        'has not been used in project' =>
            'そのプロジェクトで reCAPTCHA Enterprise API が有効になっていません。'
            . 'Google Cloud で API を有効化してください',
        'CONSUMER_INVALID' => 'プロジェクト ID が違います(存在しない、または権限がありません)',
        'PERMISSION_DENIED' => 'このプロジェクトに対する権限がありません',
    ];

    foreach ($known as $needle => $advice) {
        if (str_contains($body, $needle)) {
            return $advice;
        }
    }

    return '(既知のパターンには当てはまりません。上の body をそのまま読んでください)';
}

/** クラシック版(secretKey を使う siteverify)。 */
function km_recaptcha_verify_classic(string $token, string $remoteIp): void
{
    $fields = [
        'secret' => (string) km_recaptcha_config()['secretKey'],
        'response' => $token,
    ];
    // remoteip は任意。逆プロキシ越しなので X-Real-IP 由来の値を渡す
    if ($remoteIp !== '' && $remoteIp !== '0.0.0.0') {
        $fields['remoteip'] = $remoteIp;
    }

    $curl = curl_init(KM_RECAPTCHA_VERIFY_URL);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($body === false || $status !== 200) {
        // インターネットが切れている / Google 側が不調。**通さない**
        error_log("km_recaptcha_verify(classic): 検証に到達できません http={$status} curl={$error}");
        throw km_recaptcha_unreachable();
    }

    $result = json_decode((string) $body, true);
    if (!is_array($result)) {
        error_log('km_recaptcha_verify(classic): 応答を解釈できません: ' . substr((string) $body, 0, 200));
        throw km_recaptcha_unreachable();
    }

    if (($result['success'] ?? false) !== true) {
        $codes = implode(',', (array) ($result['error-codes'] ?? []));
        error_log("km_recaptcha_verify(classic): 検証が通りませんでした error-codes=[{$codes}]");
        throw km_recaptcha_rejected();
    }

    // よそのページで解かれたトークンを受けない(上の km_recaptcha_verify の説明)
    $hostname = (string) ($result['hostname'] ?? '');
    if (!km_recaptcha_hostname_allowed($hostname)) {
        error_log('km_recaptcha_verify(classic): ホスト名が違います hostname=' . substr($hostname, 0, 100));
        throw km_recaptcha_rejected();
    }
}

/**
 * Enterprise 版(プロジェクト ID + API キーを使う assessments)。
 *
 * チェックボックス型のキーなので、**合否は `tokenProperties.valid` だけで決める**。
 * スコア(`riskAnalysis.score`)も一緒に返ってくるが、しきい値の調整を持ち込まないために
 * 判定には使わず、様子を見るためログにだけ残す(v2 チェックボックスを選んだ理由がそこにある)。
 */
function km_recaptcha_verify_enterprise(string $token, string $remoteIp, ?string $expectedAction = null): void
{
    $config = km_recaptcha_config();

    $event = [
        'token' => $token,
        'siteKey' => (string) $config['siteKey'],
    ];
    if ($remoteIp !== '' && $remoteIp !== '0.0.0.0') {
        $event['userIpAddress'] = $remoteIp;
    }

    /*
     * API キーはクエリ文字列で渡す(Google の API キーの作法)。
     * URL はサーバー側でしか組み立てず、ログにも残さない。
     */
    $url = sprintf(KM_RECAPTCHA_ASSESS_URL, rawurlencode((string) $config['projectId']))
        . '?key=' . rawurlencode((string) $config['apiKey']);

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['event' => $event], JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($body === false || $status !== 200) {
        /*
         * 400/401/403 は設定の問題(API キーが無効、reCAPTCHA Enterprise API が未有効、
         * キーの制限が厳しすぎる、プロジェクト ID 違い、API キーが別プロジェクト)。
         * 切り分けの手がかりを残す。
         * **応答本文には秘密が含まれないので、そのままログに出してよい**(URL は出さない)。
         */
        error_log(
            "km_recaptcha_verify(enterprise): 検証に到達できません http={$status} curl={$error} "
            . 'body=' . substr((string) $body, 0, 300)
            . ' → ' . km_recaptcha_explain((string) $body)
        );
        throw km_recaptcha_unreachable();
    }

    $result = json_decode((string) $body, true);
    if (!is_array($result)) {
        error_log('km_recaptcha_verify(enterprise): 応答を解釈できません: ' . substr((string) $body, 0, 200));
        throw km_recaptcha_unreachable();
    }

    $properties = (array) ($result['tokenProperties'] ?? []);
    if (($properties['valid'] ?? false) !== true) {
        $reason = (string) ($properties['invalidReason'] ?? 'UNKNOWN');
        error_log("km_recaptcha_verify(enterprise): トークンが無効です invalidReason={$reason}");
        throw km_recaptcha_rejected();
    }

    // よそのページ・よそのフォームで解かれたトークンを受けない(km_recaptcha_verify の説明)
    $hostname = (string) ($properties['hostname'] ?? '');
    if (!km_recaptcha_hostname_allowed($hostname)) {
        error_log('km_recaptcha_verify(enterprise): ホスト名が違います hostname=' . substr($hostname, 0, 100));
        throw km_recaptcha_rejected();
    }
    $action = (string) ($properties['action'] ?? '');
    if ($expectedAction !== null && !hash_equals($expectedAction, $action)) {
        error_log('km_recaptcha_verify(enterprise): action が違います action=' . substr($action, 0, 100));
        throw km_recaptcha_rejected();
    }

    $score = $result['riskAnalysis']['score'] ?? null;
    if ($score !== null) {
        error_log('km_recaptcha_verify(enterprise): 通過 score=' . (string) $score);
    }
}
