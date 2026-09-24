<?php

declare(strict_types=1);

/**
 * ドメインを変えたあと、Logto に登録してある**戻り先の URL** を新しい名前へ書き換える。
 *
 *   # 何が変わるかを見るだけ(既定。何も変えない)
 *   docker compose exec -T -u www-data web php scripts/logto-domain.php --from=ito8795.com
 *
 *   # 書き換える
 *   docker compose exec -T -u www-data web php scripts/logto-domain.php --from=ito8795.com --apply
 *
 *   # 新しい名前を明示する(省けば APP_URL のホスト名)
 *   docker compose exec -T -u www-data web php scripts/logto-domain.php --from=ito8795.com --to=kosenmap.example.jp
 *
 * 呼ぶ順番は scripts/host-domain.sh apply の案内のとおり(.env を書き換えて up したあと)。
 *
 * ## なぜ要るのか
 *
 * .env を変えても、**Logto の DB に登録した URL は変わらない。**
 *
 *   - アプリのリダイレクト URI / サインアウト後の URI … 旧ドメインのままだと
 *     **サインインが redirect_uri の不一致で必ず落ちる**(管理画面に入れない)
 *   - CORS の許可 Origin
 *   - webhook の送り先 … 旧ドメインのままだと **アカウント削除の後片付けが黙って届かなくなる**
 *   - メールのコネクタの差出人とテンプレートの URL … 差出人が旧ドメインのままだと mailserver が拒み、
 *     **確認コードが届かない**(2026-09-17 に足した)
 *   - サインイン画面の利用規約・プライバシーポリシー・ロゴの URL
 *
 * ## 何を書き換えるか
 *
 * **ホスト名が旧ドメインと完全に一致する http(s) の URL だけ。** スキーム・ポート・パスは保つ
 * (`:3001` や `/callback.php`)。サブドメイン(www.旧)や、端末アプリのカスタムスキーム
 * (`com.ito.kosenmap.auth://…`)には触れない —— 旧ドメインを含むのに一致しないものは「手で確かめる」に出す。
 *
 * ## 変えないもの
 *
 * - API リソース(アクセストークンの audience)。**ドメインを変えても据え置く**(配布済みのアプリが通らなくなる)
 * - Console(admin テナント)の戻り先。Logto が ADMIN_ENDPOINT から作るので、ここでは扱わない
 *
 * ## root で走らせない
 *
 * Management API のトークンは src/cache/ にキャッシュされる(lib/logto-management.php)。
 * root のまま走らせると、そのファイルの持ち主が root になり、**管理画面(www-data)が信用しない**
 * (置き場ごと無ければ root の 0700 で作られ、以後 www-data はキャッシュを書けない)。
 * だから `-u www-data` を付ける。付け忘れたら止める。
 *
 * 資格情報は lib/logto-management.php の m2m(Management API のロールが要る)。
 * lib には読むと消すしか無いので、書き換え(PATCH)はここに同じ流儀で置く。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/site.php';
require_once __DIR__ . '/../lib/logto-management.php';

/** ドメイン名かホスト名付きの URL を受け、小文字のホスト名にする。形が違えば null。 */
function km_logto_domain_host(string $value): ?string
{
    $value = trim($value);
    if (str_contains($value, '://')) {
        $host = parse_url($value, PHP_URL_HOST);
        $value = is_string($host) ? $host : '';
    }
    $value = strtolower(rtrim($value, '.'));

    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value) === 1 ? $value : null;
}

/**
 * ホスト名が $from の http(s) URL なら、ホスト名だけを $to にした URL を返す。そうでなければ null。
 *
 * **組み立て直さない。** parse_url の部品から作り直すと、末尾の `/` や空のクエリが落ちて
 * 「登録済みの値と1文字違う」URL になる。ホスト名の位置だけを差し替える。
 */
function km_logto_domain_rewrite_url(string $url, string $from, string $to): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user'])) {
        return null;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true) || strtolower($parts['host']) !== $from) {
        return null;
    }
    $hostStart = strlen($parts['scheme']) + 3;
    if (strcasecmp(substr($url, $hostStart, strlen($parts['host'])), $parts['host']) !== 0) {
        return null;
    }

    return substr($url, 0, $hostStart) . $to . substr($url, $hostStart + strlen($parts['host']));
}

/**
 * メールアドレスのドメインが $from なら $to にしたものを返す。`名前 <a@b>` の形も受ける。違えば null。
 */
function km_logto_domain_rewrite_address(string $address, string $from, string $to): ?string
{
    if (preg_match('/^(.*@)([A-Za-z0-9.-]+)(>?\s*)$/s', $address, $m) !== 1 || strtolower($m[2]) !== $from) {
        return null;
    }

    return $m[1] . $to . $m[3];
}

/**
 * 本文の中の `http(s)://<$from>` を `http(s)://<$to>` にする。**ホスト名がちょうど一致するものだけ**
 * (`https://www.<$from>` や `https://<$from>.example` は変えない)。置き換えた数を $count に入れる。
 */
function km_logto_domain_rewrite_text(string $text, string $from, string $to, int &$count): string
{
    $pattern = '~(https?://)' . preg_quote($from, '~') . '(?![A-Za-z0-9.-])~i';
    $result = preg_replace($pattern, '${1}' . $to, $text, -1, $count);

    return is_string($result) ? $result : $text;
}

/** 画面に出す形。**クエリは伏せる**(webhook の送り先に鍵が付いていることがある)。 */
function km_logto_domain_display(string $url): string
{
    $cut = strcspn($url, '?#');

    return $cut < strlen($url) ? substr($url, 0, $cut) . '?…' : $url;
}

/**
 * 1つの値を書き換える。変わったら $changes に、旧ドメインを含むのに一致しなければ $notes に積む。
 *
 * @param list<array{0:string,1:string,2:string}> $changes
 * @param list<string> $notes
 */
function km_logto_domain_rewrite_one(string $value, string $from, string $to, string $label, string $field, array &$changes, array &$notes): string
{
    $rewritten = km_logto_domain_rewrite_url($value, $from, $to);
    if ($rewritten !== null) {
        $changes[] = [$field, $value, $rewritten];

        return $rewritten;
    }
    if (stripos($value, $from) !== false) {
        $notes[] = $label . ' の' . $field . ': ' . km_logto_domain_display($value) . '(ホスト名が一致しないので変えません)';
    }

    return $value;
}

/**
 * @param array<int, mixed> $values
 * @param list<array{0:string,1:string,2:string}> $changes
 * @param list<string> $notes
 * @return list<mixed>
 */
function km_logto_domain_rewrite_list(array $values, string $from, string $to, string $label, string $field, array &$changes, array &$notes): array
{
    $out = [];
    $before = count($changes);
    foreach ($values as $value) {
        $out[] = is_string($value) ? km_logto_domain_rewrite_one($value, $from, $to, $label, $field, $changes, $notes) : $value;
    }

    // **書き換えが無ければ、並びにも触れない。** 元から重複していただけの一覧を「変わった」と数えると、
    // ドメインと関係のない PATCH を送り、--apply 後の読み直しでも残りに数えてしまう
    if (count($changes) === $before) {
        return $out;
    }

    // 新旧の両方が登録されていると、書き換えで同じ値が2つになる。1つにまとめる
    return array_values(array_unique($out, SORT_REGULAR));
}

/** アプリの一覧。**ページを辿る**(1ページ目だけ見て「無い」と言わない)。 */
function km_logto_domain_applications(): array
{
    $all = [];
    $seen = [];
    for ($page = 1; $page <= 20; $page++) {
        $rows = km_logto_management_get('applications', ['page' => $page, 'page_size' => 100]);
        $added = 0;
        foreach ($rows as $row) {
            $id = is_array($row) ? trim((string) ($row['id'] ?? '')) : '';
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $all[] = $row;
            $added++;
        }
        if (count($rows) < 100 || $added === 0) {
            break;
        }
    }

    return $all;
}

/**
 * 書き換えの計画。**読むだけ**で、Logto には何も送らない。
 *
 * @return array{patches: list<array{label:string, path:string, body:array<string,mixed>, changes:list<array{0:string,1:string,2:string}>}>, notes: list<string>}
 */
function km_logto_domain_plan(string $from, string $to): array
{
    $patches = [];
    $notes = [];

    foreach (km_logto_domain_applications() as $app) {
        $id = trim((string) ($app['id'] ?? ''));
        $label = 'アプリ ' . (string) ($app['name'] ?? $id) . '(' . (string) ($app['type'] ?? '?') . ')';
        $changes = [];
        $body = [];

        /*
         * **変えた欄だけでなく、元の中身を丸ごと送る。** Logto の版によって PATCH が
         * 部分の併合か置き換えかが違う。丸ごと送れば、どちらでも他の欄は消えない。
         */
        $oidc = is_array($app['oidcClientMetadata'] ?? null) ? $app['oidcClientMetadata'] : [];
        $newOidc = $oidc;
        foreach (['redirectUris' => 'リダイレクト URI', 'postLogoutRedirectUris' => 'サインアウト後の URI'] as $field => $fieldLabel) {
            if (is_array($oidc[$field] ?? null)) {
                $newOidc[$field] = km_logto_domain_rewrite_list($oidc[$field], $from, $to, $label, $fieldLabel, $changes, $notes);
            }
        }
        foreach (['backchannelLogoutUri' => 'バックチャネル・ログアウトの URI', 'logoUri' => 'ロゴの URI'] as $field => $fieldLabel) {
            if (is_string($oidc[$field] ?? null)) {
                $newOidc[$field] = km_logto_domain_rewrite_one($oidc[$field], $from, $to, $label, $fieldLabel, $changes, $notes);
            }
        }
        if ($newOidc !== $oidc) {
            $body['oidcClientMetadata'] = $newOidc;
        }

        $custom = is_array($app['customClientMetadata'] ?? null) ? $app['customClientMetadata'] : [];
        if (is_array($custom['corsAllowedOrigins'] ?? null)) {
            $newCustom = $custom;
            $newCustom['corsAllowedOrigins'] = km_logto_domain_rewrite_list($custom['corsAllowedOrigins'], $from, $to, $label, 'CORS の許可 Origin', $changes, $notes);
            if ($newCustom !== $custom) {
                $body['customClientMetadata'] = $newCustom;
            }
        }

        if ($id !== '' && $body !== []) {
            $patches[] = ['label' => $label, 'path' => 'applications/' . rawurlencode($id), 'body' => $body, 'changes' => $changes];
        }
    }

    foreach (km_logto_management_get('hooks') as $hook) {
        if (!is_array($hook)) {
            continue;
        }
        $id = trim((string) ($hook['id'] ?? ''));
        $config = is_array($hook['config'] ?? null) ? $hook['config'] : [];
        if ($id === '' || !is_string($config['url'] ?? null)) {
            continue;
        }
        $label = 'webhook ' . (string) ($hook['name'] ?? $id);
        $changes = [];
        $newUrl = km_logto_domain_rewrite_one($config['url'], $from, $to, $label, '送り先', $changes, $notes);
        if ($newUrl === $config['url']) {
            continue;
        }
        // **headers は送り返す**(署名以外の独自ヘッダーを消さない)。空の {} は json_encode が [] にするので直す
        $newConfig = $config;
        $newConfig['url'] = $newUrl;
        if (array_key_exists('headers', $newConfig) && $newConfig['headers'] === []) {
            $newConfig['headers'] = new stdClass();
        }
        $patches[] = ['label' => $label, 'path' => 'hooks/' . rawurlencode($id), 'body' => ['config' => $newConfig], 'changes' => $changes];
    }

    /*
     * メールのコネクタ(2026-09-17)。**差出人のドメインが旧のままだと、mailserver が差出人を拒み
     * (ALLOWED_SENDER_DOMAINS は KM_DOMAIN だけ)、確認コードが届かず誰もサインインできなくなる。**
     * テンプレートの本文に書いた URL(ロゴ・サイトへのリンク)も同じホスト名の置き換えだけ行う。
     *
     * **config は丸ごと送り返す**(SMTP のパスワードも含む)。画面には差出人と件数しか出さない。
     */
    try {
        foreach (km_logto_management_get('connectors') as $connector) {
            if (!is_array($connector)) {
                continue;
            }
            $id = trim((string) ($connector['id'] ?? ''));
            $config = is_array($connector['config'] ?? null) ? $connector['config'] : [];
            if ($id === '' || $config === []) {
                continue;
            }
            $label = 'メールのコネクタ ' . (string) ($connector['connectorId'] ?? $id);
            $changes = [];
            $newConfig = $config;

            if (is_string($config['fromEmail'] ?? null)) {
                $newFrom = km_logto_domain_rewrite_address($config['fromEmail'], $from, $to);
                if ($newFrom !== null) {
                    $newConfig['fromEmail'] = $newFrom;
                    $changes[] = ['差出人', $config['fromEmail'], $newFrom];
                } elseif (stripos($config['fromEmail'], $from) !== false) {
                    $notes[] = $label . ' の差出人: ' . $config['fromEmail'] . '(形が読めないので変えません。Console で直す)';
                }
            }

            if (is_array($config['templates'] ?? null)) {
                foreach ($config['templates'] as $index => $template) {
                    if (!is_array($template)) {
                        continue;
                    }
                    foreach (['subject', 'content'] as $field) {
                        if (!is_string($template[$field] ?? null)) {
                            continue;
                        }
                        $count = 0;
                        $rewritten = km_logto_domain_rewrite_text($template[$field], $from, $to, $count);
                        if ($count > 0) {
                            $newConfig['templates'][$index][$field] = $rewritten;
                            $usage = (string) ($template['usageType'] ?? $index);
                            $changes[] = ["テンプレート {$usage} の {$field}", "{$count} か所の https://{$from}", "https://{$to}"];
                        }
                    }
                }
            }

            if ($changes !== []) {
                /*
                 * **送り返す config はオブジェクトのまま組む。** 配列で読むと空の `{}` が json_encode で `[]` に化け、
                 * Logto の検査で丸ごと拒まれうる(SMTP の auth・tls など、空で持つ欄がある)。
                 * 読んだ JSON をオブジェクトで読み直し、変えた欄だけ差し替える。
                 */
                $raw = km_logto_domain_get_object('connectors/' . rawurlencode($id));
                if (!($raw instanceof stdClass) || !($raw->config ?? null) instanceof stdClass) {
                    throw new RuntimeException("コネクタ {$id} の設定をオブジェクトとして読めませんでした。");
                }
                $objConfig = $raw->config;
                if (isset($newConfig['fromEmail']) && is_string($newConfig['fromEmail'])) {
                    $objConfig->fromEmail = $newConfig['fromEmail'];
                }
                if (is_array($newConfig['templates'] ?? null) && is_array($objConfig->templates ?? null)) {
                    foreach ($newConfig['templates'] as $index => $template) {
                        if (!is_array($template) || !(($objConfig->templates[$index] ?? null) instanceof stdClass)) {
                            continue;
                        }
                        foreach (['subject', 'content'] as $field) {
                            if (is_string($template[$field] ?? null)) {
                                $objConfig->templates[$index]->{$field} = $template[$field];
                            }
                        }
                    }
                }
                $patches[] = ['label' => $label, 'path' => 'connectors/' . rawurlencode($id), 'body' => ['config' => $objConfig], 'changes' => $changes];
            }
        }
    } catch (Throwable $exception) {
        $notes[] = 'コネクタを読めませんでした(' . $exception->getMessage() . ')。Console → コネクタ → メールの差出人を確かめてください。';
    }

    // サインイン画面。**読めなくても他は進める**(戻り先ほど急がない)
    try {
        $experience = km_logto_management_get('sign-in-exp');
        $label = 'サインイン画面';
        $changes = [];
        $body = [];
        foreach ([
            'termsOfUseUrl' => '利用規約の URL',
            'privacyPolicyUrl' => 'プライバシーポリシーの URL',
            'supportWebsiteUrl' => 'サポートの URL',
            'unknownSessionRedirectUrl' => 'セッションが無いときの戻り先',
        ] as $field => $fieldLabel) {
            if (is_string($experience[$field] ?? null)) {
                $rewritten = km_logto_domain_rewrite_one($experience[$field], $from, $to, $label, $fieldLabel, $changes, $notes);
                if ($rewritten !== $experience[$field]) {
                    $body[$field] = $rewritten;
                }
            }
        }
        $branding = is_array($experience['branding'] ?? null) ? $experience['branding'] : [];
        $newBranding = $branding;
        foreach (['logoUrl' => 'ロゴ', 'darkLogoUrl' => 'ロゴ(暗い背景)', 'favicon' => 'ファビコン', 'darkFavicon' => 'ファビコン(暗い背景)'] as $field => $fieldLabel) {
            if (is_string($branding[$field] ?? null)) {
                $newBranding[$field] = km_logto_domain_rewrite_one($branding[$field], $from, $to, $label, $fieldLabel, $changes, $notes);
            }
        }
        if ($newBranding !== $branding) {
            $body['branding'] = $newBranding;
        }
        if ($body !== []) {
            $patches[] = ['label' => $label, 'path' => 'sign-in-exp', 'body' => $body, 'changes' => $changes];
        }
    } catch (Throwable $exception) {
        $notes[] = 'サインイン画面の設定を読めませんでした(' . $exception->getMessage() . ')。Console で利用規約などの URL を確かめてください。';
    }

    return ['patches' => $patches, 'notes' => $notes];
}

/**
 * Management API を GET し、**JSON のオブジェクトをオブジェクトのまま**返す(空の `{}` を保つため)。
 * lib の km_logto_management_get() は配列で返すので、送り返す設定を組むときだけこちらを使う。
 */
function km_logto_domain_get_object(string $path): mixed
{
    $config = km_logto_m2m_config();
    $curl = curl_init($config['endpoint'] . '/api/' . ltrim($path, '/'));
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
            'Authorization: Bearer ' . km_logto_m2m_token(),
        ],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($body) || $status !== 200) {
        throw new RuntimeException("{$path} を読めませんでした(status={$status})。");
    }

    return json_decode($body, false, 512, JSON_THROW_ON_ERROR);
}

/**
 * Management API へ PATCH する。lib の km_logto_management_get() と同じ流儀
 * (素の curl・タイムアウト・TLS 検証は必ず有効・トークンは km_logto_m2m_token())。
 *
 * @throws RuntimeException 受け付けられなかったとき(**書き換えたと誤解させない**)
 */
function km_logto_domain_patch(string $path, array $body): void
{
    $config = km_logto_m2m_config();
    $curl = curl_init($config['endpoint'] . '/api/' . ltrim($path, '/'));
    if ($curl === false) {
        throw new RuntimeException('Logto へのリクエストを初期化できませんでした。');
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . km_logto_m2m_token(),
        ],
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($status === 200) {
        return;
    }

    throw new RuntimeException(
        '受け付けられませんでした(status=' . $status . ($curlError !== '' ? ' curl=' . $curlError : '') . ')'
        . (is_string($response) && $response !== '' ? ': ' . substr($response, 0, 300) : '')
    );
}

// ---------------------------------------------------------------------------

$options = getopt('', ['from:', 'to:', 'apply', 'help']);
$options = is_array($options) ? $options : [];

if (isset($options['help']) || !isset($options['from'])) {
    $usage = "使い方(web コンテナの中で、www-data として):\n"
        . "  docker compose exec -T -u www-data web php scripts/logto-domain.php --from=旧ドメイン            一覧だけ(何も変えない)\n"
        . "  docker compose exec -T -u www-data web php scripts/logto-domain.php --from=旧ドメイン --apply    書き換える\n"
        . "  --to=新ドメイン を省くと APP_URL のホスト名を使う\n";
    fwrite(isset($options['help']) ? STDOUT : STDERR, $usage);
    exit(isset($options['help']) ? 0 : 2);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "root で走っています。-u www-data を付けてください。\n"
        . "  root のままだと Management API のトークンのキャッシュ(src/cache/)の持ち主が root になり、\n"
        . "  管理画面(www-data)がそれを使えなくなります。\n");
    exit(2);
}

$from = km_logto_domain_host((string) $options['from']);
$to = km_logto_domain_host(isset($options['to']) ? (string) $options['to'] : km_site_host());
if ($from === null || $to === null) {
    fwrite(STDERR, "ドメインの形ではありません(--from / --to)。例: --from=ito8795.com\n");
    exit(2);
}
if ($from === $to) {
    fwrite(STDERR, "旧と新が同じです({$from})。"
        . (isset($options['to']) ? '' : 'APP_URL がまだ旧ドメインのままです。docker compose up -d をしてから実行してください(--to で明示もできます)。')
        . "\n");
    exit(2);
}
if (isset($options['to']) && $to !== strtolower(km_site_host())) {
    echo "注意: APP_URL のホスト名(" . km_site_host() . ")と --to({$to})が違います。\n\n";
}

$apply = isset($options['apply']);
echo "== Logto の戻り先: {$from} → {$to}" . ($apply ? '(書き換える)' : '(一覧だけ。何も変えない)') . " ==\n";

try {
    echo 'Logto: ' . km_logto_m2m_config()['endpoint'] . "\n\n";
    $plan = km_logto_domain_plan($from, $to);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Logto から設定を読めませんでした: ' . $exception->getMessage() . "\n");
    exit(1);
}

foreach ($plan['patches'] as $patch) {
    echo "[{$patch['label']}]\n";
    foreach ($patch['changes'] as [$field, $before, $after]) {
        echo "  {$field}: " . km_logto_domain_display($before) . "\n";
        echo '    → ' . km_logto_domain_display($after) . "\n";
    }
}
if ($plan['patches'] === []) {
    echo "書き換えるものはありません(ホスト名が {$from} の URL は登録されていません)。\n";
}
if ($plan['notes'] !== []) {
    echo "\n手で確かめる:\n";
    foreach ($plan['notes'] as $note) {
        echo "  - {$note}\n";
    }
}

if (!$apply) {
    if ($plan['patches'] !== []) {
        echo "\n書き換えるには --apply を付けてください。\n";
    }
    exit(0);
}

$failed = 0;
echo "\n";
foreach ($plan['patches'] as $patch) {
    try {
        km_logto_domain_patch($patch['path'], $patch['body']);
        echo "書き換えました: {$patch['label']}\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "★ 書き換えられませんでした: {$patch['label']}: " . $exception->getMessage() . "\n");
    }
}

// **書き換えたと誤解させない。** 読み直して、旧ドメインの URL が残っていないかを見る
try {
    $left = count(km_logto_domain_plan($from, $to)['patches']);
    if ($left > 0) {
        $failed++;
        fwrite(STDERR, "★ 読み直すと、まだ {$from} を指している場所が {$left} 件あります。\n");
    } else {
        echo "\n読み直しました: ホスト名が {$from} の URL は残っていません。\n";
    }
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '読み直せませんでした: ' . $exception->getMessage() . "\n");
}

echo "\n次に: 新しい名前で管理画面にサインインし、戻ってこられることを確かめてください。\n";
exit($failed > 0 ? 1 : 0);
