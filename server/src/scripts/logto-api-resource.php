<?php

declare(strict_types=1);

/**
 * Logto の API リソース(= アクセストークンの audience)を**別の識別子へ移す。**
 *
 *   # 何をするかを見るだけ(既定。何も変えない)
 *   docker compose exec -T -u www-data web php scripts/logto-api-resource.php --from=https://ito8795.com/api --to=https://ito4.jp/api
 *
 *   # 新しいリソースを作り、スコープとロールの割り当てを写す(旧いリソースは残す)
 *   docker compose exec -T -u www-data web php scripts/logto-api-resource.php --from=https://ito8795.com/api --to=https://ito4.jp/api --apply
 *
 *   # 切り替えが済んでから、旧いリソースを消す(写しが揃っていなければ止まる)
 *   docker compose exec -T -u www-data web php scripts/logto-api-resource.php --from=https://ito8795.com/api --to=https://ito4.jp/api --delete-old
 *
 * ## なぜ要るのか(2026-09-17)
 *
 * ドメインを ito8795.com → ito4.jp へ移すとき、audience も `https://ito4.jp/api` に揃えることにした。
 * **Logto の API リソースの識別子(indicator)は作ったあとに変えられない。** 新しいリソースを作り、
 * スコープ(admin:users:read など)を同じ名前で作り直し、それを持っていたロール(kosenmap-admin・
 * kosenmap-staff など)に新しいスコープも割り当てる必要がある。手で Console を辿ると 1 つ抜けただけで
 * 管理画面の一部の操作やスタッフの証明だけが 403 になり、気付きにくい。
 *
 * ## 順番
 *
 *   1. --apply(**切り替えの前に。** 新しいリソースは誰も使っていないので、作っても何も変わらない)
 *   2. .env の KM_API_RESOURCE を新しい値にして up、Android を新しい apiResource で配る
 *   3. 落ち着いたら --delete-old(旧いリソースのスコープとロールの割り当てが一緒に消える)
 *
 * **旧いリソースを消すまでは、どちらの audience のトークンも発行できる**(ロールが両方のスコープを持つ)。
 * だから 1 と 2 の間に誰も締め出されない。
 *
 * ## 写さないもの
 *
 * 組織ロールのリソーススコープ・サードパーティアプリの同意スコープ。**見つかったら止めて知らせる**
 * (この環境では 0 件。手で写す)。
 *
 * ## root で走らせない
 *
 * logto-domain.php と同じ。Management API のトークンのキャッシュ(src/cache/)の持ち主が root になり、
 * 管理画面(www-data)が使えなくなる。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/logto-management.php';

/**
 * Management API へ POST / DELETE する。GET は lib の km_logto_management_get()。
 *
 * @return array<mixed> 応答の JSON(DELETE は空)
 * @throws RuntimeException 受け付けられなかったとき(**変えたと誤解させない**)
 */
function km_logto_resource_send(string $method, string $path, ?array $body = null): array
{
    $config = km_logto_m2m_config();
    $curl = curl_init($config['endpoint'] . '/api/' . ltrim($path, '/'));
    if ($curl === false) {
        throw new RuntimeException('Logto へのリクエストを初期化できませんでした。');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . km_logto_m2m_token(),
        ],
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (in_array($status, [200, 201, 204], true)) {
        $json = is_string($response) && $response !== '' ? json_decode($response, true) : [];

        return is_array($json) ? $json : [];
    }

    throw new RuntimeException(
        "{$method} {$path} を受け付けられませんでした(status={$status}" . ($curlError !== '' ? " curl={$curlError}" : '') . ')'
        . (is_string($response) && $response !== '' ? ': ' . substr($response, 0, 300) : '')
    );
}

/** ページを辿って全部読む(1 ページ目だけ見て「無い」と言わない)。 */
function km_logto_resource_all(string $path): array
{
    $all = [];
    $seen = [];
    for ($page = 1; $page <= 50; $page++) {
        $rows = km_logto_management_get($path, ['page' => $page, 'page_size' => 100]);
        $added = 0;
        foreach ($rows as $row) {
            $id = is_array($row) ? (string) ($row['id'] ?? '') : '';
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

/** 識別子が一致するリソース。無ければ null。 */
function km_logto_resource_find(array $resources, string $indicator): ?array
{
    foreach ($resources as $resource) {
        if (is_array($resource) && rtrim((string) ($resource['indicator'] ?? ''), '/') === rtrim($indicator, '/')) {
            return $resource;
        }
    }

    return null;
}

/** スコープを名前で引ける形にする。 */
function km_logto_resource_scopes_by_name(string $resourceId): array
{
    $byName = [];
    foreach (km_logto_resource_all('resources/' . rawurlencode($resourceId) . '/scopes') as $scope) {
        $byName[(string) $scope['name']] = $scope;
    }

    return $byName;
}

/**
 * 今の状態を読んで、足りないものを並べる。**読むだけ。**
 *
 * @return array{old: array, new: ?array, oldScopes: array, newScopes: array, missingScopes: list<array>, roles: list<array{role: array, missing: list<string>}>, blockers: list<string>}
 */
function km_logto_resource_plan(string $from, string $to): array
{
    $resources = km_logto_resource_all('resources');
    $old = km_logto_resource_find($resources, $from);
    if ($old === null) {
        throw new RuntimeException("移す元のリソース {$from} が Logto にありません(もう消した? 識別子の綴りを確かめる)。");
    }
    $new = km_logto_resource_find($resources, $to);

    $oldScopes = km_logto_resource_scopes_by_name((string) $old['id']);
    $newScopes = $new !== null ? km_logto_resource_scopes_by_name((string) $new['id']) : [];
    $missingScopes = [];
    foreach ($oldScopes as $name => $scope) {
        if (!isset($newScopes[$name])) {
            $missingScopes[] = $scope;
        }
    }

    $oldScopeIds = [];
    foreach ($oldScopes as $name => $scope) {
        $oldScopeIds[(string) $scope['id']] = $name;
    }

    $roles = [];
    foreach (km_logto_resource_all('roles') as $role) {
        $has = [];
        $hasNew = [];
        foreach (km_logto_resource_all('roles/' . rawurlencode((string) $role['id']) . '/scopes') as $scope) {
            $scopeId = (string) ($scope['id'] ?? '');
            if (isset($oldScopeIds[$scopeId])) {
                $has[] = $oldScopeIds[$scopeId];
            }
            if ($new !== null && (string) ($scope['resourceId'] ?? '') === (string) $new['id']) {
                $hasNew[(string) $scope['name']] = true;
            }
        }
        if ($has === []) {
            continue;
        }
        $missing = array_values(array_filter($has, static fn (string $name): bool => !isset($hasNew[$name])));
        $roles[] = ['role' => $role, 'has' => $has, 'missing' => $missing];
    }

    $blockers = [];
    // 組織ロールに旧いスコープが付いていたら、写し方をここでは持たないので止める
    try {
        foreach (km_logto_resource_all('organization-roles') as $orgRole) {
            foreach (km_logto_resource_all('organization-roles/' . rawurlencode((string) $orgRole['id']) . '/resource-scopes') as $scope) {
                if (isset($oldScopeIds[(string) ($scope['id'] ?? '')])) {
                    $blockers[] = '組織ロール「' . (string) $orgRole['name'] . '」が旧いスコープ ' . (string) $scope['name'] . ' を持っています(手で写す)';
                }
            }
        }
    } catch (Throwable $exception) {
        $blockers[] = '組織ロールを読めませんでした(' . $exception->getMessage() . ')';
    }

    return [
        'old' => $old,
        'new' => $new,
        'oldScopes' => $oldScopes,
        'newScopes' => $newScopes,
        'missingScopes' => $missingScopes,
        'roles' => $roles,
        'blockers' => $blockers,
    ];
}

function km_logto_resource_print(array $plan, string $from, string $to): void
{
    $old = $plan['old'];
    echo "移す元: {$from}(" . (string) $old['name'] . '、トークンの有効期間 ' . (int) ($old['accessTokenTtl'] ?? 0) . " 秒)\n";
    echo '  スコープ: ' . ($plan['oldScopes'] === [] ? '(なし)' : implode(', ', array_keys($plan['oldScopes']))) . "\n";
    echo "移す先: {$to} " . ($plan['new'] === null ? '(まだ無い → 作る)' : '(あります)') . "\n";
    if ($plan['missingScopes'] !== []) {
        echo '  作るスコープ: ' . implode(', ', array_map(static fn (array $s): string => (string) $s['name'], $plan['missingScopes'])) . "\n";
    } elseif ($plan['new'] !== null) {
        echo "  スコープは揃っています\n";
    }
    echo "ロール:\n";
    if ($plan['roles'] === []) {
        echo "  (旧いスコープを持つロールはありません)\n";
    }
    foreach ($plan['roles'] as $row) {
        $name = (string) $row['role']['name'] . '(' . (string) ($row['role']['type'] ?? '?') . ')';
        echo '  ' . $name . ': ' . implode(', ', $row['has'])
            . ($row['missing'] === [] ? ' → 新しいリソースの分も揃っています' : ' → 足す: ' . implode(', ', $row['missing'])) . "\n";
    }
    foreach ($plan['blockers'] as $blocker) {
        echo "★ {$blocker}\n";
    }
}

// ---------------------------------------------------------------------------

$options = getopt('', ['from:', 'to:', 'apply', 'delete-old', 'help']);
$options = is_array($options) ? $options : [];

if (isset($options['help']) || !isset($options['from'], $options['to'])) {
    $usage = "使い方(web コンテナの中で、www-data として):\n"
        . "  php scripts/logto-api-resource.php --from=<旧い識別子> --to=<新しい識別子>              何をするか見るだけ\n"
        . "  php scripts/logto-api-resource.php --from=<旧い識別子> --to=<新しい識別子> --apply      新しいリソースを作り、スコープとロールを写す\n"
        . "  php scripts/logto-api-resource.php --from=<旧い識別子> --to=<新しい識別子> --delete-old 写しが揃っていれば旧いリソースを消す\n";
    fwrite(isset($options['help']) ? STDOUT : STDERR, $usage);
    exit(isset($options['help']) ? 0 : 2);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "root で走っています。-u www-data を付けてください(Management API のトークンのキャッシュの持ち主が root になる)。\n");
    exit(2);
}

$from = (string) $options['from'];
$to = (string) $options['to'];
foreach ([$from, $to] as $indicator) {
    if (preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?$#', $indicator) !== 1) {
        fwrite(STDERR, "識別子は https:// で始まる URL の形にしてください: {$indicator}\n");
        exit(2);
    }
}
if (rtrim($from, '/') === rtrim($to, '/')) {
    fwrite(STDERR, "旧と新が同じです({$from})。\n");
    exit(2);
}

$apply = isset($options['apply']);
$deleteOld = isset($options['delete-old']);
if ($apply && $deleteOld) {
    fwrite(STDERR, "--apply と --delete-old は一緒に使えません(切り替えを確かめてから消す)。\n");
    exit(2);
}

echo "== Logto の API リソース: {$from} → {$to}" . ($apply ? '(写す)' : ($deleteOld ? '(旧いものを消す)' : '(見るだけ。何も変えない)')) . " ==\n";

try {
    echo 'Logto: ' . km_logto_m2m_config()['endpoint'] . "\n\n";
    $plan = km_logto_resource_plan($from, $to);
} catch (Throwable $exception) {
    fwrite(STDERR, '★ ' . $exception->getMessage() . "\n");
    exit(1);
}
km_logto_resource_print($plan, $from, $to);

$complete = $plan['new'] !== null && $plan['missingScopes'] === []
    && array_filter($plan['roles'], static fn (array $row): bool => $row['missing'] !== []) === [];

if (!$apply && !$deleteOld) {
    echo "\n" . ($complete ? "写しは揃っています。旧いリソースを消すなら --delete-old(切り替えを確かめてから)。\n" : "写すには --apply を付けてください。\n");
    exit($plan['blockers'] === [] ? 0 : 1);
}

if ($plan['blockers'] !== []) {
    fwrite(STDERR, "\n★ 上の理由で止めます。何も変えていません。\n");
    exit(1);
}

if ($deleteOld) {
    if (!$complete) {
        fwrite(STDERR, "\n★ 新しいリソースへの写しが揃っていません。先に --apply を流してください。何も消していません。\n");
        exit(1);
    }
    try {
        km_logto_resource_send('DELETE', 'resources/' . rawurlencode((string) $plan['old']['id']));
        echo "\n消しました: {$from}(スコープとロールへの割り当ても一緒に消えます)\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, '★ 消せませんでした: ' . $exception->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

// --apply
$failed = 0;
echo "\n";
try {
    $new = $plan['new'];
    if ($new === null) {
        $new = km_logto_resource_send('POST', 'resources', [
            'name' => (string) $plan['old']['name'],
            'indicator' => $to,
            'accessTokenTtl' => (int) ($plan['old']['accessTokenTtl'] ?? 3600),
        ]);
        echo "作りました: リソース {$to}\n";
    }
    $newId = (string) ($new['id'] ?? '');
    if ($newId === '') {
        throw new RuntimeException('作ったリソースの id を読めませんでした。');
    }

    $newScopes = km_logto_resource_scopes_by_name($newId);
    foreach ($plan['oldScopes'] as $name => $scope) {
        if (isset($newScopes[$name])) {
            continue;
        }
        $newScopes[$name] = km_logto_resource_send('POST', 'resources/' . rawurlencode($newId) . '/scopes', [
            'name' => $name,
            'description' => (string) ($scope['description'] ?? ''),
        ]);
        echo "作りました: スコープ {$name}\n";
    }

    foreach ($plan['roles'] as $row) {
        $ids = [];
        foreach ($row['has'] as $name) {
            $scopeId = (string) ($newScopes[$name]['id'] ?? '');
            if ($scopeId === '') {
                throw new RuntimeException("新しいスコープ {$name} の id を読めませんでした。");
            }
            if (in_array($name, $row['missing'], true)) {
                $ids[] = $scopeId;
            }
        }
        if ($ids === []) {
            continue;
        }
        km_logto_resource_send('POST', 'roles/' . rawurlencode((string) $row['role']['id']) . '/scopes', ['scopeIds' => $ids]);
        echo '割り当てました: ロール ' . (string) $row['role']['name'] . ' に ' . implode(', ', $row['missing']) . "\n";
    }
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '★ 途中で止まりました: ' . $exception->getMessage() . "\n  もう一度 --apply を流すと、足りない分だけ続きから作ります。\n");
}

// **写したと誤解させない。** 読み直して揃ったかを見る
try {
    $after = km_logto_resource_plan($from, $to);
    $ok = $after['new'] !== null && $after['missingScopes'] === []
        && array_filter($after['roles'], static fn (array $row): bool => $row['missing'] !== []) === [];
    echo "\n読み直しました: " . ($ok ? "新しいリソースにスコープとロールの割り当てが揃っています。\n" : "★ まだ揃っていません。\n");
    if (!$ok) {
        $failed++;
    }
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '読み直せませんでした: ' . $exception->getMessage() . "\n");
}

echo "\n次に: .env の KM_API_RESOURCE を {$to} にして立て直し、Android を同じ apiResource で配る。落ち着いたら --delete-old。\n";
exit($failed > 0 ? 1 : 0);
