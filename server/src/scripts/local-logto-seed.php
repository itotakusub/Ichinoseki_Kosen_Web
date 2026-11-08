<?php

declare(strict_types=1);

/**
 * 検証機の空の Logto に、本番と同じ形(アプリ・API リソース・ロール・組織・webhook・メール)とダミーの利用者を作る。
 * **検証機(KM_ENV=local)でだけ動く。** 2026-11-07 段 5-4(plan.md)。
 *
 *   docker compose exec -T -u www-data web php scripts/local-logto-seed.php -           作るものを並べるだけ(既定)
 *   docker compose exec -T -u www-data web php scripts/local-logto-seed.php - --apply   作る(何度流してもよい。在るものは使い回す)
 *
 * 標準入力はメールのテンプレートの正本(docs/logto-email-templates.json)。`-` の代わりに `--no-templates` なら SMTP のコネクタを作らない。
 * ふつうは scripts/local-logto-seed.sh(ホストで動く包み)から呼ぶ。包みが下の出力を .env と run/dummy-accounts.txt に振り分ける。
 *
 * ## 出力(標準出力。人向けの説明は標準エラー)
 *
 *   ENV<TAB>キー<TAB>値          … .env に書くもの(アプリの ID と秘密・組織の ID・webhook の署名鍵)
 *   ACCOUNT<TAB>利用者名<TAB>メール<TAB>パスワード<TAB>役割 … 作ったダミーの利用者(パスワードは乱数。**画面に出さない**)
 *
 * ## 前提(利用者が Console で 1 回だけ)
 *
 * 管理者を作り、マシン間(m2m)のアプリを 1 つ作って **Logto Management API の権限(all)を持つロール**を付け、
 * その ID と秘密を検証機の .env の LOGTO_M2M_APP_ID・LOGTO_M2M_APP_SECRET に書いて web を起こし直す。
 * このアプリがそのままサイトの m2m になる(本番と同じ。読むだけの m2m は本番でも空)。
 *
 * ## 本番に寄せたもの
 *
 * - API リソース `https://<名前>/api` とスコープ 5 つ・ロール kosenmap-admin(管理の 4 つ)と kosenmap-staff(staff:event:access)
 *   (docs/14 §5 の本番の表と同じ名前)
 * - 組織のスコープ staff:normal:access・組織ロール Kosen_Member(KM_LOGTO_STAFF_ORG_ROLE)・組織 1 つ(lib/staff-org.php)
 * - サイトのアプリ(**自社用**の Traditional。戻り先は callback.php)・Android のネイティブアプリ(戻り先 2 つ)
 * - webhook(User.Deleted → api/logto-webhook.php)・SMTP のコネクタ(mailpit:1025・テンプレートは正本)
 * - ダミーの利用者: 管理者 1・教職員 2(組織に入れる)・一般 3・試験モード 2(km_test_accounts にも入れる)
 */

require_once dirname(__DIR__) . '/lib/logto-management.php';
require_once dirname(__DIR__) . '/lib/db.php';

const KM_SEED_PROD_DOMAINS = ['ito4.jp', 'ito8795.com'];
const KM_SEED_SCOPES = ['admin:users:read', 'admin:users:write', 'admin:api-keys:read', 'admin:api-keys:write', 'staff:event:access'];
const KM_SEED_ROLES = [
    'kosenmap-admin' => ['admin:users:read', 'admin:users:write', 'admin:api-keys:read', 'admin:api-keys:write'],
    'kosenmap-staff' => ['staff:event:access'],
];
const KM_SEED_NATIVE_REDIRECTS = [
    'com.ito.kosenmap.auth://com.ito.kosenmap/callback',
    'com.ito.kosenmap.admin.auth://com.ito.kosenmap.admin/callback',
];
/** [利用者名, 表示名, 役割(admin|staff|user|test)] */
const KM_SEED_ACCOUNTS = [
    ['dummy_admin', 'ダミー管理者', 'admin'],
    ['dummy_staff1', 'ダミー教職員 1', 'staff'],
    ['dummy_staff2', 'ダミー教職員 2', 'staff'],
    ['dummy_user1', 'ダミー利用者 1', 'user'],
    ['dummy_user2', 'ダミー利用者 2', 'user'],
    ['dummy_user3', 'ダミー利用者 3', 'user'],
    ['dummy_test1', 'ダミー試験 1', 'test'],
    ['dummy_test2', 'ダミー試験 2', 'test'],
];

function km_seed_say(string $message): void
{
    fwrite(STDERR, $message . "\n");
}

function km_seed_host(string $url): string
{
    return strtolower((string) parse_url($url, PHP_URL_HOST));
}

function km_seed_is_prod(string $host): bool
{
    foreach (KM_SEED_PROD_DOMAINS as $prod) {
        if ($host === $prod || str_ends_with($host, '.' . $prod)) {
            return true;
        }
    }
    return false;
}

/** 乱数のパスワード(英数字 20 文字・記号 2 つ)。Logto の既定の方針(8 文字以上・3 種類)を満たす。 */
function km_seed_password(): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 20; $i++) {
        $out .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    return $out . '-' . random_int(10, 99) . 'x';
}

/** 一覧を全部(在るものを名前で探すため。空の Logto なので数は少ない)。 */
function km_seed_all(string $path): array
{
    $rows = km_logto_management_get($path, ['page' => 1, 'page_size' => 100]);
    return array_values(array_filter($rows, 'is_array'));
}

function km_seed_find(array $rows, string $key, string $value): ?array
{
    foreach ($rows as $row) {
        if ((string) ($row[$key] ?? '') === $value) {
            return $row;
        }
    }
    return null;
}

// ---------------------------------------------------------------- 引数と止まる条件
$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$noTemplates = in_array('--no-templates', $args, true);
if (!$noTemplates && !in_array('-', $args, true)) {
    km_seed_say('使い方: php scripts/local-logto-seed.php - [--apply](標準入力に docs/logto-email-templates.json)/ --no-templates');
    exit(2);
}

if (getenv('KM_ENV') !== 'local') {
    km_seed_say('★ KM_ENV=local ではありません。検証機でだけ流します。');
    exit(1);
}
$appUrl = rtrim((string) getenv('APP_URL'), '/');
$domain = km_seed_host($appUrl);
$adminUrl = rtrim((string) (getenv('KM_ADMIN_URL') ?: $appUrl), '/');
$endpointHost = km_seed_host((string) getenv('LOGTO_ENDPOINT'));
foreach ([$domain, km_seed_host($adminUrl), $endpointHost] as $host) {
    if ($host === '' || km_seed_is_prod($host)) {
        km_seed_say('★ 名前が空か本番のものです(' . ($host === '' ? '空' : $host) . ')。止めます。');
        exit(1);
    }
}
$indicator = rtrim((string) (getenv('LOGTO_API_RESOURCE') ?: $appUrl . '/api'), '/');
if (km_seed_is_prod(km_seed_host($indicator))) {
    km_seed_say('★ API リソースの名前が本番のものです(' . $indicator . ')。.env の KM_API_RESOURCE を https://' . $domain . '/api に。');
    exit(1);
}

$templates = null;
if (!$noTemplates) {
    $raw = stream_get_contents(STDIN);
    $templates = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($templates) || $templates === []) {
        km_seed_say('★ 標準入力がメールのテンプレートの JSON ではありません(docs/logto-email-templates.json)。');
        exit(2);
    }
}

km_seed_say(($apply ? '== 作ります' : '== 作るものを並べるだけ(--apply で作る)') . ': ' . $domain . '(Logto ' . $endpointHost . ')');
$env = [];
$accounts = [];
$todo = 0;

// 先に Management API に届くかを見る(届かなければ何もしない)
try {
    km_logto_management_get('resources', ['page' => 1, 'page_size' => 1]);
} catch (Throwable $e) {
    km_seed_say('★ Management API に届きません: ' . $e->getMessage());
    km_seed_say('  Console で m2m のアプリに Management API の権限を付け、.env の LOGTO_M2M_APP_ID・SECRET を書いて web を起こし直したか。');
    exit(1);
}

// ---------------------------------------------------------------- 1. API リソースとスコープ
$resources = km_seed_all('resources');
$resource = km_seed_find($resources, 'indicator', $indicator);
$scopeIds = [];
if ($resource === null) {
    $todo++;
    km_seed_say("  作る: API リソース {$indicator}(スコープ " . count(KM_SEED_SCOPES) . ' つ)');
    if ($apply) {
        $resource = km_logto_management_post('resources', ['name' => 'KosenMap API', 'indicator' => $indicator, 'accessTokenTtl' => 3600]);
    }
} else {
    km_seed_say("  在る: API リソース {$indicator}");
}
if ($resource !== null) {
    $have = km_logto_management_get('resources/' . $resource['id'] . '/scopes');
    foreach (KM_SEED_SCOPES as $name) {
        $found = km_seed_find($have, 'name', $name);
        if ($found === null) {
            $todo++;
            km_seed_say("  作る: スコープ {$name}");
            if ($apply) {
                $found = km_logto_management_post('resources/' . $resource['id'] . '/scopes', ['name' => $name, 'description' => $name]);
            }
        }
        if ($found !== null) {
            $scopeIds[$name] = (string) $found['id'];
        }
    }
}

// ---------------------------------------------------------------- 2. ロール
$roles = km_seed_all('roles');
$roleIds = [];
foreach (KM_SEED_ROLES as $roleName => $roleScopes) {
    $role = km_seed_find($roles, 'name', $roleName);
    if ($role === null) {
        $todo++;
        km_seed_say("  作る: ロール {$roleName}(" . implode(', ', $roleScopes) . ')');
        if ($apply) {
            $role = km_logto_management_post('roles', ['name' => $roleName, 'description' => $roleName, 'type' => 'User']);
        }
    }
    if ($role === null) {
        continue;
    }
    $roleIds[$roleName] = (string) $role['id'];
    $haveScopes = array_map(fn ($s) => (string) ($s['id'] ?? ''), km_logto_management_get('roles/' . $role['id'] . '/scopes'));
    $missing = array_values(array_diff(array_values(array_intersect_key($scopeIds, array_flip($roleScopes))), $haveScopes));
    if ($missing !== []) {
        $todo++;
        km_seed_say("  割り当てる: {$roleName} に " . count($missing) . ' つ');
        if ($apply) {
            km_logto_management_post('roles/' . $role['id'] . '/scopes', ['scopeIds' => $missing]);
        }
    }
}

// ---------------------------------------------------------------- 3. 組織
$orgScopeName = trim((string) getenv('KM_LOGTO_STAFF_SCOPE')) ?: 'staff:normal:access';
$orgRoleName = trim((string) getenv('KM_LOGTO_STAFF_ORG_ROLE')) ?: 'Kosen_Member';
$orgScope = km_seed_find(km_seed_all('organization-scopes'), 'name', $orgScopeName);
if ($orgScope === null) {
    $todo++;
    km_seed_say("  作る: 組織のスコープ {$orgScopeName}");
    if ($apply) {
        $orgScope = km_logto_management_post('organization-scopes', ['name' => $orgScopeName, 'description' => '教職員(承認済み)']);
    }
}
$orgRole = km_seed_find(km_seed_all('organization-roles'), 'name', $orgRoleName);
if ($orgRole === null) {
    $todo++;
    km_seed_say("  作る: 組織ロール {$orgRoleName}");
    if ($apply && $orgScope !== null) {
        $orgRole = km_logto_management_post('organization-roles', [
            'name' => $orgRoleName,
            'description' => '教職員',
            'organizationScopeIds' => [(string) $orgScope['id']],
        ]);
    }
}
$org = km_seed_find(km_seed_all('organizations'), 'name', 'KosenMap 教職員(検証機)');
if ($org === null) {
    $todo++;
    km_seed_say('  作る: 組織「KosenMap 教職員(検証機)」');
    if ($apply) {
        $org = km_logto_management_post('organizations', ['name' => 'KosenMap 教職員(検証機)', 'description' => 'ダミー']);
    }
}
if ($org !== null) {
    $env['KM_LOGTO_ORG_ID'] = (string) $org['id'];
}

// ---------------------------------------------------------------- 4. アプリ
$apps = km_seed_all('applications');
$callbacks = array_values(array_unique([$appUrl . '/callback.php', $adminUrl . '/callback.php']));
$afterSignOut = array_values(array_unique([$appUrl . '/', $adminUrl . '/']));
$web = km_seed_find($apps, 'name', 'KosenMap サイト(検証機)');
if ($web === null) {
    $todo++;
    km_seed_say('  作る: サイトのアプリ(自社用・Traditional。戻り先 ' . implode(' ', $callbacks) . ')');
    if ($apply) {
        $web = km_logto_management_post('applications', [
            'name' => 'KosenMap サイト(検証機)',
            'type' => 'Traditional',
            'isThirdParty' => false,
            'oidcClientMetadata' => ['redirectUris' => $callbacks, 'postLogoutRedirectUris' => $afterSignOut],
        ]);
        $env['LOGTO_APP_SECRET'] = (string) ($web['secret'] ?? '');
    }
} else {
    km_seed_say('  在る: サイトのアプリ(秘密は .env のものを使う。無くしたら Console で消して流し直す)');
}
if ($web !== null) {
    $env['LOGTO_APP_ID'] = (string) $web['id'];
}
$native = km_seed_find($apps, 'name', 'KosenMap Android(検証機)');
if ($native === null) {
    $todo++;
    km_seed_say('  作る: Android のネイティブアプリ(戻り先 2 つ)');
    if ($apply) {
        $native = km_logto_management_post('applications', [
            'name' => 'KosenMap Android(検証機)',
            'type' => 'Native',
            'isThirdParty' => false,
            'oidcClientMetadata' => ['redirectUris' => KM_SEED_NATIVE_REDIRECTS, 'postLogoutRedirectUris' => KM_SEED_NATIVE_REDIRECTS],
        ]);
    }
}
if ($native !== null) {
    $env['KM_ANDROID_APP_ID'] = (string) $native['id'];
}

// ---------------------------------------------------------------- 5. webhook(利用者の削除)
$hookUrl = $appUrl . '/api/logto-webhook.php';
$hook = km_seed_find(km_seed_all('hooks'), 'name', 'KosenMap 利用者の削除(検証機)');
if ($hook === null) {
    $todo++;
    km_seed_say("  作る: webhook User.Deleted → {$hookUrl}");
    if ($apply) {
        $hook = km_logto_management_post('hooks', [
            'name' => 'KosenMap 利用者の削除(検証機)',
            'events' => ['User.Deleted'],
            'config' => ['url' => $hookUrl],
        ]);
        $env['LOGTO_WEBHOOK_SIGNING_KEY'] = (string) ($hook['signingKey'] ?? '');
    }
}

// ---------------------------------------------------------------- 6. メール(mailpit)
if ($templates !== null) {
    $connectors = km_logto_management_get('connectors');
    $smtp = null;
    foreach ($connectors as $c) {
        if (is_array($c) && ($c['connectorId'] ?? '') === 'simple-mail-transfer-protocol') {
            $smtp = $c;
        }
    }
    if ($smtp === null) {
        $todo++;
        km_seed_say('  作る: SMTP のコネクタ(mailpit:1025・認証なし・テンプレート ' . count($templates) . ' 種)');
        if ($apply) {
            km_logto_management_post('connectors', [
                'connectorId' => 'simple-mail-transfer-protocol',
                'config' => [
                    'host' => 'mailpit',
                    'port' => 1025,
                    'fromEmail' => 'noreply@' . $domain,
                    'secure' => false,
                    'templates' => $templates,
                ],
            ]);
        }
    } else {
        km_seed_say('  在る: SMTP のコネクタ(テンプレートは logto-email-templates.php で合わせる)');
    }
}

// ---------------------------------------------------------------- 7. ダミーの利用者
$users = km_seed_all('users');
$testSubs = [];
foreach (KM_SEED_ACCOUNTS as [$username, $displayName, $kind]) {
    $user = km_seed_find($users, 'username', $username);
    $email = str_replace('_', '.', $username) . '@example.test';
    $created = false;
    if ($user === null) {
        $todo++;
        km_seed_say("  作る: 利用者 {$username}({$kind})");
        if ($apply) {
            $password = km_seed_password();
            $user = km_logto_management_post('users', [
                'username' => $username,
                'primaryEmail' => $email,
                'name' => $displayName,
                'password' => $password,
            ]);
            $accounts[] = [$username, $email, $password, $kind];
            $password = '';
            $created = true;
        }
    }
    // ロールと組織は**作ったときだけ**付ける(在る利用者は、Console で直したものを崩さない)
    if (!$created) {
        continue;
    }
    $userId = (string) $user['id'];
    if ($kind === 'admin') {
        km_logto_management_post('users/' . $userId . '/roles', ['roleIds' => array_values(array_filter([$roleIds['kosenmap-admin'] ?? null, $roleIds['kosenmap-staff'] ?? null]))]);
    }
    if ($kind === 'staff' && $org !== null && $orgRole !== null) {
        km_logto_management_post('organizations/' . $org['id'] . '/users', ['userIds' => [$userId]]);
        km_logto_management_post('organizations/' . $org['id'] . '/users/' . $userId . '/roles', ['organizationRoleIds' => [(string) $orgRole['id']]]);
    }
    if ($kind === 'test') {
        $testSubs[$userId] = $displayName;
    }
}
if ($apply && $testSubs !== []) {
    $pdo = km_db();
    $stmt = $pdo->prepare('INSERT IGNORE INTO km_test_accounts (sub, label, marked_by, marked_at) VALUES (?, ?, ?, NOW())');
    foreach ($testSubs as $sub => $label) {
        $stmt->execute([$sub, $label, 'local-logto-seed']);
    }
    km_seed_say('  試験モードの利用者 ' . count($testSubs) . ' 人を km_test_accounts に入れました');
}

// ---------------------------------------------------------------- 出力
foreach ($env as $key => $value) {
    if ($value !== '') {
        echo "ENV\t{$key}\t{$value}\n";
    }
}
foreach ($accounts as $row) {
    echo 'ACCOUNT' . "\t" . implode("\t", $row) . "\n";
}
km_seed_say($apply
    ? ($todo === 0 ? '揃っていました(何も作っていません)。' : "作りました({$todo} 件)。")
    : ($todo === 0 ? '揃っています。' : "作るものが {$todo} 件あります(--apply で作る)。"));
