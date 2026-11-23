<?php

declare(strict_types=1);

/**
 * 地図の地点・階を JSON で配る(段 15-7。2026-11-14。仕組みは lib/map-embed.php)。
 *
 *   GET /api/embed-places.php?site=<札>&lang=ja|en
 *   → {"success":true,"version":1,"floors":[{id,label,labelEn,kind,image,width,height}],"places":[{id,floor,name,nameEn?,type,x,y}],"home":"…"}
 *
 * 利用者(11-14)「地図のデータを JSON で配る口も今回作る」。他のサイトが自分で地図や一覧を描く用。
 *
 * - **CORS は札に登録したオリジンにだけ**(Access-Control-Allow-Origin はそのオリジン 1 つ・Vary: Origin)。
 *   ほかのページの JS からは読めない。中身は公開の地図と同じものなので、サーバー同士で取るのは止めない
 * - 中身は**サインインしていない来場者と同じ地図**から、名前のある地点と階だけ(km_embed_public_data)。
 *   教職員の氏名・メモ・経路は出さない。セッションは開かない・Cookie は受けない(credentials を許さない)
 * - 知らない札・止めた札は 404。地図に錠が掛かっていれば locked: true で空
 * - 座標は平面図の画素(階の width・height が座標の大きさ。無い階は画像の大きさ)。image は絶対 URL
 * - 1 分は使い回してよい。回数は nginx の km_api で絞る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/site.php';
require_once __DIR__ . '/../lib/map-embed.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    // 頭書きを足さない単純な GET なら下見は来ない。来ても GET だけを許すと答える(CORS の頭書きは下で同じ決め方)
    header('Allow: GET, HEAD, OPTIONS');
    http_response_code(204);
}
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS') {
    header('Allow: GET, HEAD, OPTIONS');
    respond(['success' => false, 'message' => 'GET required.'], 405);
}

$query = km_embed_parse_query($_GET);
try {
    $pdo = km_db();
    $site = km_embed_site_by_key($pdo, $query['site']);
} catch (Throwable $exception) {
    error_log('api/embed-places.php: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'unavailable'], 503);
}
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
header('Vary: Origin');
if (km_embed_origin_allowed($site, $origin)) {
    header('Access-Control-Allow-Origin: ' . km_embed_normalize_origin($origin));
    header('Access-Control-Allow-Methods: GET, HEAD');
    header('Access-Control-Max-Age: 600');
}
if ($method === 'OPTIONS') {
    exit;
}
if ($site === null || !$site['enabled']) {
    respond(['success' => false, 'message' => 'unknown site'], 404);
}

try {
    $data = km_embed_load_public($pdo);
} catch (Throwable $exception) {
    error_log('api/embed-places.php: ' . $exception::class . ': ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'unavailable'], 503);
}
if ($method === 'GET') {
    km_embed_count($pdo, $site['id'], 'places');
}
$base = km_site_origin();
foreach ($data['floors'] as &$floor) {
    // 画像は本体の URL(相対のままだと、読んだ側のサイトの URL として解かれる)
    $floor['image'] = $floor['image'] !== '' && str_starts_with($floor['image'], '/') ? $base . $floor['image'] : $floor['image'];
}
unset($floor);
header_remove('Cache-Control');
header('Cache-Control: public, max-age=60');
respond([
    'success' => true,
    'version' => 1,
    'locked' => $data['locked'],
    'lang' => $query['lang'],
    'floors' => $data['floors'],
    'places' => $data['places'],
    'home' => $base . '/',
]);
