<?php

declare(strict_types=1);

/**
 * Android アプリ(来場者版)への地図配信。**来場者アプリが地図を手に入れる唯一の経路。**
 *
 * 認可はアクセスコードのみで、Logto のログインは**要求しない**(来場者は未ログインで使う)。
 * ただしトークンが付いていて、それが運営スタッフの権限を持つなら、スタッフ限定の
 * 一時地点を含む版を返す。
 *
 * api/map-data.php とは別物。あちらは Web 版地図(km_map_nodes)を返す。
 * ここが返すのは管理アプリが書き出した MapDataSnapshot をそのまま包んだもの。
 *
 * セッションは使わない。端末は Cookie を持たないし、PHP のセッションロックで
 * 大きな JSON の配信が他のリクエストを待たせるのも避けたい。
 *
 * ## 配信 ID は2つ(2026-09-14)
 *
 * kosen-main(Website の正本)と kosen-event(イベント用の正本)。**どちらを返すかはコードで決まる。**
 * 版の比較は配信 ID ごと(km_app_map_is_up_to_date())。
 */

// respond() / require_method() と、JSON 用のセキュリティヘッダー。
// logto_guard.php が respond() を呼ぶので、これより先に読み込む必要がある。
require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-rate-limit.php';
require_once __DIR__ . '/../lib/app-secret.php';
require_once __DIR__ . '/../lib/app-map.php';
require_once __DIR__ . '/../lib/route-weights.php';

require_method('POST');

/*
 * 本文は小さい(コード・版・配信 ID だけ)。**64KB を超えるものは読まずに断る** ——
 * 未認証の口なので、巨大な本文で json_decode にメモリを使わせない。
 */
const KM_APP_MAP_REQUEST_MAX_BYTES = 64 * 1024;
$rawBody = (string) file_get_contents('php://input', false, null, 0, KM_APP_MAP_REQUEST_MAX_BYTES + 1);
if (strlen($rawBody) > KM_APP_MAP_REQUEST_MAX_BYTES) {
    respond(['success' => false, 'message' => 'リクエストが大きすぎます。'], 413);
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    respond(['success' => false, 'message' => 'リクエストを読み取れません。'], 400);
}

$code = km_app_map_normalize_code((string) ($input['code'] ?? ''));
if ($code === '') {
    respond(['success' => false, 'message' => 'アクセスコードを入力してください。'], 400);
}
$haveRevision = isset($input['haveRevision']) && is_int($input['haveRevision'])
    ? $input['haveRevision']
    : null;
// 端末が持っている地図の配信 ID。**無い(古いアプリ)と空は同じ扱い。** 形の違うものは無視する
$haveMapId = is_string($input['haveMapId'] ?? null) && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $input['haveMapId']) === 1
    ? $input['haveMapId']
    : null;

$serverTime = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/app-map.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$config = km_app_map_config();

/*
 * ## 照合は2段(2026-09-14)
 *
 * 1. **鍵つきの印(lookup)で引く。** HMAC 1回なので安い。当たればロックに関係なく通す ——
 *    学校や会場の共有回線で誰かが打ち間違え続けても、**正しいコードを持つ来場者は止めない**
 *    (android-data#0)。当たりは回数にも数えない。
 * 2. 外れたときだけ、**先に1回数えてから**(km_map_unlock_attempt)、lookup の無い古いコードを
 *    件数の上限付きで bcrypt にかける。通ったら lookup を書き足す(次からは 1 で引ける)。
 *
 * 鍵が読めない(DB の不調)ときは 1 を飛ばして 2 だけにする。**通すべきコードを落とさない**ため、
 * そのときは lookup を持つコードも bcrypt の対象に戻る(km_app_map_code_has_lookup が keyId=null を拒む)。
 *
 * レート制限は lib/map-rate-limit.php をそのまま使う。**独自に書かないこと。**
 * あちらは nginx リバースプロキシ越しの X-Real-IP を見る(REMOTE_ADDR は常に
 * プロキシの内部 IP になるため、素直に書くと全訪問者が同じバケツに入る)うえ、
 * ロック判定を DB 側の NOW() で完結させている(PHP と DB のタイムゾーンがずれると
 * ロックが無言で効かなくなる)。どちらも実際に踏んだ不具合の対策。
 *
 * **錠は 'app'。Web 版地図のパスワード('web')とは別に数える。**
 * 以前は同じ表を共有していたが、アクセスコードは配布物で誰でも持っているので、
 * ここで1回成功するたびに Web 地図の失敗回数まで消え、あちらのパスワードを
 * 無限に試せた(security-review-2026-09-10 の 3)。
 *
 * **成功しても 'app' の回数は消さない。** 以前は km_map_clear_unlock_failures($pdo, 'app') で
 * 消していたので、配布済みのコードを混ぜれば外れを無制限に試せた(android-data#1)。
 */
$lookup = null;
try {
    $lookup = km_app_map_code_lookup(km_app_secret($pdo, KM_APP_MAP_CODE_SECRET), $code);
} catch (Throwable $exception) {
    error_log('api/app-map.php: コードの鍵を読めません(bcrypt だけで照合します): ' . $exception->getMessage());
}

$found = $lookup !== null ? km_app_map_find_code_by_lookup($config, $lookup) : null;

if ($found === null) {
    if (!km_map_unlock_attempt($pdo, 'app')) {
        respond([
            'success' => false,
            'message' => '試行回数が多すぎます。しばらく待ってからやり直してください。',
        ], 429);
    }

    $legacy = km_app_map_find_code_by_hash($code, $config, $lookup['keyId'] ?? null, KM_APP_MAP_LEGACY_VERIFY_LIMIT);
    if ($legacy === null) {
        respond(['success' => false, 'message' => 'アクセスコードが違います。'], 401);
    }
    $found = $legacy;

    if ($lookup !== null) {
        /*
         * lookup を書き足す。**書く直前に読み直す**(照合の間に管理画面で変えられた分を消さない)。
         * 書けなくても地図は返す —— 次も bcrypt に回るだけで、壊れはしない。
         *
         * **変わらないなら書かない。** 本番の config/ は www-data が新しいファイルを作れず、
         * 書き込みは既存ファイルへの上書きになる(rename で差し替えられない)。同じコードの端末が
         * 同時に来るたびに書き直すと、別の要求が書きかけの設定を require して落ちうる。
         */
        try {
            $fresh = km_app_map_config();
            $remembered = km_app_map_remember_lookup($fresh, $legacy['hash'], $lookup);
            if ($remembered !== $fresh) {
                km_app_map_write_config($remembered);
            }
        } catch (Throwable $exception) {
            error_log('api/app-map.php: lookup を書き足せませんでした: ' . $exception->getMessage());
        }
    }
}
$slug = $found['slug'];

$meta = $config['maps'][$slug] ?? null;
if (!is_array($meta)) {
    /*
     * **行き先の無いコード**(本番の TEST1 がこの形だった)。コードは正しいので 401 ではない。
     * 管理画面の「配信先の無いアクセスコード」で付け替えるか止める。
     */
    error_log("api/app-map.php: 配信設定がありません: {$slug}(管理画面で付け替えてください)");
    respond(['success' => false, 'message' => 'このマップは配信設定が未完了です。'], 500);
}

/*
 * 管理画面から止められているか。**期限より先に見る。**
 *
 * 止めても**既に受け取った端末の地図は消えない** —— あちらは自分が持っている
 * 期限で判断しており、410 は「取得に失敗した」としか扱わない。
 * ここで止まるのは「これから取りに来る端末」だけ。
 */
if (km_app_map_is_paused($meta)) {
    respond(['success' => false, 'message' => 'このマップの配信は停止しています。'], 410);
}

$expiresAt = km_app_map_parse_iso((string) ($meta['expiresAt'] ?? ''));
if ($expiresAt === null) {
    error_log("api/app-map.php: 有効期限が不正です: {$slug}");
    respond(['success' => false, 'message' => 'このマップは配信設定が未完了です。'], 500);
}
// 期限切れのものは配らない。端末側でも判定するが、入口でも止める。
if ($expiresAt->getTimestamp() < time()) {
    respond(['success' => false, 'message' => 'このマップの配信は終了しています。'], 410);
}

$revision = isset($meta['revision']) && is_int($meta['revision']) ? $meta['revision'] : 0;

/*
 * 経路の重み(2026-09-25)。管理アプリから配ったときだけ載せる(lib/route-weights.php)。
 * **地図が最新のときの応答にも載せる** —— 重みだけ変えたときに、版を上げなくても「更新」で届くように。
 * 単位はアプリと同じ(メートルと倍率)。無ければアプリは自分の既定(RouteWeights())で動く。
 */
$routeWeights = km_route_weights_stored($pdo);

if (km_app_map_is_up_to_date($slug, $haveMapId, $haveRevision, $revision)) {
    // 本体を返さない場合でも serverTime は必ず返す。端末はこれで時計を合わせ、
    // 有効期限の判定に端末の時計を使わずに済む。
    respond([
        'upToDate' => true,
        'mapId' => $slug,
        'revision' => $revision,
        'expiresAt' => $expiresAt->format(DateTimeInterface::ATOM),
        'serverTime' => $serverTime,
        'routeWeights' => $routeWeights,
    ]);
}

$path = km_app_map_storage_path($config, (string) ($meta['file'] ?? ''));
if ($path === null) {
    error_log("api/app-map.php: 実体が見つかりません: {$slug} (" . ($meta['file'] ?? '') . ')');
    respond(['success' => false, 'message' => 'このマップはまだ配置されていません。'], 500);
}

$map = km_app_map_decode_snapshot((string) file_get_contents($path));
if ($map === null) {
    error_log("api/app-map.php: 地図 JSON として読めません: {$slug}");
    respond(['success' => false, 'message' => 'マップを準備できませんでした。'], 500);
}

/*
 * スタッフ限定の一時地点は、権限を確認できたときだけ含める。
 * 検証に失敗しても 401 にはせず来場者版を返す(ログインが切れただけで
 * 地図そのものを取得できなくなる方が困る)。
 *
 * **イベント用の正本も同じ扱い。** 添付した JSON に氏名や staffOnly の地点が入っていても、
 * ここで落とす(添付する人が消し忘れても配らない)。
 */
$principal = logto_optional_principal();
$permissions = is_array($principal) ? ($principal['permissions'] ?? []) : [];
/*
 * 教職員(docs/15 段 D。2026-09-18)。組織トークンで、こちらの組織の教職員の権限を持つ人
 * (logto_guard.php が組織 ID を照合したうえで is_teacher を立てる)。
 * **イベント運営とは別の権限**だが、閲覧不可の地点(staffOnly)は教職員にも見せる。
 */
$isTeacher = is_array($principal) && ($principal['is_teacher'] ?? false) === true;
$isStaff = is_array($principal) && (
    in_array(LOGTO_STAFF_PERMISSION, $permissions, true) || ($principal['is_admin'] ?? 0) === 1
);
if (!$isStaff && !$isTeacher) {
    $map = km_app_map_strip_staff_only($map);
} elseif (!km_app_map_may_send_occupant_names($isStaff || $isTeacher, $isTeacher)) {
    /*
     * **staff でも、氏名だけは別に判断する。**
     *
     * 「地図データ公開設定」が `hidden` のときは誰にも配らない —— Web で隠している
     * ものを、アプリ経由で取れるようにしてしまうと、錠が2つある状態になる。
     * 一時地点(staffOnly)は staff に見せてよいので、そちらは落とさない。
     */
    km_app_map_strip_occupant_names($map);
}

$body = km_app_map_build_package(
    $map,
    $slug,
    $revision,
    $serverTime,
    $expiresAt->format(DateTimeInterface::ATOM),
    is_string($meta['activeEventUuid'] ?? null) ? $meta['activeEventUuid'] : null,
    ($meta['checksum'] ?? true) !== false,
    $routeWeights
);
if ($body === null) {
    error_log("api/app-map.php: パッケージを組み立てられません: {$slug}");
    respond(['success' => false, 'message' => 'マップを準備できませんでした。'], 500);
}

http_response_code(200);
echo $body;
exit;
