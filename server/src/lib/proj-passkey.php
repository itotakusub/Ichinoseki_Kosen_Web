<?php

declare(strict_types=1);

/**
 * 手元の道具の鍵 `KM_PROJ_PASSKEY`(2026-11-07、利用者の指示)。
 *
 * 利用者の指示: 「プロジェクト状況を編集できる API を作成して。この API は docs の .env の KM_PROJ_PASSKEY= の
 * パスワードを使用しないとアクセスできないように。**今後もこの ENV を使用して便利化していきたい**」。
 * そのため鍵の扱いはこのファイルにまとめ、API ごとに書かない(api/project-tasks.php が最初の 1 本)。
 *
 * ## 鍵の置き場
 *
 * | 場所 | 中身 |
 * |---|---|
 * | 手元 `server/docs/.env` | 正本。**配備にも GitHub の控えにも載らない**(deploy-to-host.ps1 は docs を送らない・控えは .env を除く) |
 * | 本番 `/opt/kosenmap/.env` | `scripts/proj-passkey-sync.ps1` が写す(値を画面に出さない)。compose が web へ渡す |
 *
 * ## 守ること
 *
 * - **鍵が無い・短いときは誰も通さない**(503。空の鍵と空の入力が一致して通る、を起こさない)
 * - 鍵は**ヘッダーだけ**で受ける(`X-KM-Proj-Passkey: …` か `Authorization: Bearer …`)。
 *   URL に載せたら断る(nginx のログ・ブラウザの履歴に残る)
 * - 比べるのは sha256 どうしを hash_equals(長さも時間も漏らさない)
 * - **外れだけ**数える(lib/map-rate-limit.php の 'proj-api'。15 分 8 回で 15 分止める)。
 *   当たりはロック中でも通す(鍵は 32 字以上の乱数で当てられない。持ち主が巻き添えで止まらないように)
 * - 通ったら、管理の記録の実行者を「プロジェクトの鍵(API)」にする(誰が直したかを人と分ける)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/map-rate-limit.php';

const KM_PROJ_PASSKEY_ENV = 'KM_PROJ_PASSKEY';
const KM_PROJ_PASSKEY_MIN_LENGTH = 32;
const KM_PROJ_PASSKEY_HEADER = 'HTTP_X_KM_PROJ_PASSKEY';
/** URL に載せてはいけない名前。見つけたら照合せずに断る。 */
const KM_PROJ_PASSKEY_QUERY_NAMES = ['passkey', 'key', 'token', 'km_proj_passkey', 'access_token'];
const KM_PROJ_PASSKEY_ACTOR = ['sub' => 'km-proj-passkey', 'name' => 'プロジェクトの鍵(API)'];

/** 置かれた鍵。**無い・短い・空白を含むときは null**(通さない側に倒す)。 */
function km_proj_passkey_configured(?string $value = null): ?string
{
    $value ??= getenv(KM_PROJ_PASSKEY_ENV);
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (strlen($value) < KM_PROJ_PASSKEY_MIN_LENGTH || preg_match('/\s/', $value) === 1) {
        return null;
    }

    return $value;
}

/**
 * 要求から鍵を取り出す。`X-KM-Proj-Passkey` を先に、無ければ `Authorization: Bearer`。
 *
 * @param array<string, mixed> $server $_SERVER
 */
function km_proj_passkey_from_request(array $server): string
{
    $direct = trim((string) ($server[KM_PROJ_PASSKEY_HEADER] ?? ''));
    if ($direct !== '') {
        return $direct;
    }
    $auth = trim((string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1) {
        return $m[1];
    }

    return '';
}

/** URL に鍵らしいものが載っているか(名前で見る)。 @param array<string, mixed> $query */
function km_proj_passkey_in_query(array $query): bool
{
    foreach (array_keys($query) as $name) {
        if (in_array(strtolower((string) $name), KM_PROJ_PASSKEY_QUERY_NAMES, true)) {
            return true;
        }
    }

    return false;
}

/** 照合。空は必ず外れ。sha256 どうしを比べる(長さを漏らさない)。 */
function km_proj_passkey_matches(string $given, ?string $expected): bool
{
    if ($expected === null || $given === '') {
        return false;
    }

    return hash_equals(hash('sha256', 'km-proj|' . $expected), hash('sha256', 'km-proj|' . $given));
}

/**
 * API の入口で呼ぶ。通らなければ JSON で応えて終わる(api_bootstrap.php の respond())。
 * 通れば管理の記録の実行者を「プロジェクトの鍵(API)」にする。
 */
function km_proj_passkey_guard(): void
{
    $expected = km_proj_passkey_configured();
    if ($expected === null) {
        error_log('proj-passkey: KM_PROJ_PASSKEY が無いか短いので、すべて断ります');
        respond(['success' => false, 'message' => 'この口はいま使えません(サーバーに鍵が設定されていません)。'], 503);
    }
    if (km_proj_passkey_in_query($_GET)) {
        respond(['success' => false, 'message' => '鍵を URL に載せないでください(ヘッダー X-KM-Proj-Passkey で送る)。載せた鍵は替えてください。'], 400);
    }

    $given = km_proj_passkey_from_request($_SERVER);
    if (km_proj_passkey_matches($given, $expected)) {
        $GLOBALS['KM_USER'] = KM_PROJ_PASSKEY_ACTOR;
        return;
    }

    // 外れ。数えてから断る(上限を超えていれば 429)
    $allowed = true;
    try {
        $allowed = km_map_unlock_attempt(km_db(), 'proj-api');
    } catch (Throwable $exception) {
        error_log('proj-passkey: 回数を DB で数えられないのでファイルで数えます: ' . $exception->getMessage());
        $allowed = km_map_rate_limit_fallback_consume('proj-api') <= km_map_rate_limit_limit('proj-api');
    }
    // 総当たりを遅らせる(当たりは待たせない)
    usleep(random_int(300_000, 600_000));
    if (!$allowed) {
        header('Retry-After: ' . KM_MAP_UNLOCK_LOCK_SECONDS);
        respond(['success' => false, 'message' => '鍵の外れが多すぎます。15 分あけてください。'], 429);
    }
    header('WWW-Authenticate: Bearer realm="km-proj"');
    respond(['success' => false, 'message' => '鍵が要ります(ヘッダー X-KM-Proj-Passkey)。'], 401);
}
