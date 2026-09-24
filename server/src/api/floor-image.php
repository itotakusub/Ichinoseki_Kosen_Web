<?php

declare(strict_types=1);

/**
 * 見取り図(階の平面図)を配る。
 *
 * ## なぜ PHP を通すのか
 *
 * `Main/Picture/*` は静的ファイルで、nginx がそのまま配っていた。
 * **パスさえ分かればパスワード無しで図面が取れた**ので、ノードを隠しても
 * 隠したことにならなかった。ここを通せば `km_map_view_unlocked()` を見られる。
 *
 * 直リンクの方は nginx が 404 にする
 * (`nginx/default.conf.template` の `location ~ ^/Main/Picture/`)。
 * **片方だけ塞いでも意味が無い**ので、両方を必ず一緒に配ること。
 *
 * ## 受け取るのは階の ID だけ
 *
 * ファイル名を受け取ると、`../` で抜け出す経路を自前で塞ぐことになる。
 * **ID を DB に問い合わせ、そこに登録されているパスだけを開く。**
 * 総当たりされても、`km_map_floors` に無い ID は 404 で返るだけ。
 *
 * ## 種別は拡張子から決める。列名を信じない
 *
 * 列は `svg_path` という名前だが、**入っているのは PNG**
 * (`Old/migrate-map-floor-bounds.php` で圧縮 PNG へ切り替えたとき、
 * lib/map-data.php との食い違いを避けるため列名だけ据え置いた)。
 *
 * ここを `image/svg+xml` 決め打ちにしていたせいで、下の `nosniff` と噛み合って
 * **ブラウザが画像を捨て、全階が「読み込めませんでした」になっていた**。
 * 判定は lib/map-data.php の km_map_floor_image_content_type() に置いてある
 * (検査から呼べるようにするため。このファイルは読むと即座に応答してしまう)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-access.php';
require_once __DIR__ . '/../lib/map-data.php';   // km_map_floor_image_content_type()

// **Cookie 属性は lib/session.php に一本化する。** 素の session_start() で開くと、
// この口が最初に開いた利用者にだけ secure / httponly / SameSite の付かない
// Cookie を出し直すことになる。
require_once __DIR__ . '/../lib/session.php';
km_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit;
}

/*
 * **管理者の抜け道はここだけ。**
 *
 * admin/map-editor.php は同じ見取り図をこの口から読む。管理者に見せない理由が無いので
 * 錠を素通りさせるが、印は利用者の解除とは別のもの([km_map_admin_session])。
 *
 * 公開ページの方は素通りしない —— 錠が掛かっていれば km_map_data() が階を1件も返さず、
 * そもそも画像を取りに来ない。管理者が URL を手で組めば取れるが、
 * **それは管理者が自分の設定を迂回できるというだけ**で、守るべき相手はそこではない。
 */
$config = km_map_access_config();
$viewUnlocked = km_map_view_unlocked($config) || km_map_admin_session();

/*
 * 錠を見たら**すぐ閉じる**。
 *
 * PHP はスクリプトが終わるまでセッションファイルをロックし続ける。ここは
 * **1ページで6階ぶんが並行に飛んでくる**口なので、開いたままにすると
 * 2枚目以降が1枚目の readfile() の後ろで順番待ちになり、見取り図が出そろうまでが
 * 階数ぶん延びる。セッションから読むのは上の1行だけなので、ここで手放してよい。
 */
session_write_close();

if (!$viewUnlocked) {
    /*
     * **403 で返す。** 404 にすると「地図が壊れている」と読まれる。
     * 本文は付けない —— 画面側は km_map_data() の `mapLocked` を見て
     * パスワードを聞くので、ここでの文言は誰も読まない。
     *
     */
    http_response_code(403);
    exit;
}

/*
 * 建物平面図(屋外図の上に重ねる小さな見取り図)もここから配る。
 *
 * **`Main/Picture/*` は nginx が塞いである**(この口を通させるため)ので、
 * 別の場所に置くと錠の外から取れてしまう。同じ錠の内側に置く。
 *
 * DB は引かない —— 画像はアプリの APK 由来で、こちらは**同じ絵の写し**
 * (`lib/map-data.php` の `km_map_building_overlays` を参照)。
 * 名前は `[a-z0-9_]` だけに絞り、置き場も1つに固定する。
 */
$buildingKey = (string) ($_GET['building'] ?? '');
if ($buildingKey !== '') {
    if (!preg_match('/^[a-z0-9_]+$/', $buildingKey)) {
        http_response_code(400);
        exit;
    }
    $svgPath = 'Picture/bldg/' . $buildingKey . '.png';
} else {
    $floorId = (string) ($_GET['floor'] ?? '');
    if ($floorId === '') {
        http_response_code(400);
        exit;
    }

    try {
        $statement = km_db()->prepare('SELECT svg_path FROM km_map_floors WHERE id = ?');
        $statement->execute([$floorId]);
        $svgPath = $statement->fetchColumn();
    } catch (Throwable $exception) {
        error_log('api/floor-image.php db failed: ' . $exception->getMessage());
        http_response_code(503);
        exit;
    }

    if ($svgPath === false || !is_string($svgPath) || $svgPath === '') {
        http_response_code(404);
        exit;
    }
}

/*
 * DB の値でも念のため確かめる。**管理画面から入る値なので、
 * ここが唯一の防波堤ではない**が、`..` を含むパスが混ざったときに
 * ファイルシステムを歩き回らせない。
 */
$relative = ltrim(str_replace('\\', '/', $svgPath), '/');
if ($relative === '' || str_contains($relative, '..')) {
    error_log("api/floor-image.php: 不正なパスが登録されています: {$svgPath}");
    http_response_code(500);
    exit;
}

$base = realpath(__DIR__ . '/../Main');
$path = realpath(__DIR__ . '/../Main/' . $relative);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$contentType = km_map_floor_image_content_type($path);
if ($contentType === null) {
    // 登録されているのは管理側の値なので、これは利用者の誤りではなく設定の誤り。
    // 黙って 404 にすると「見取り図が無い」と読まれて原因から遠ざかる。
    error_log("api/floor-image.php: 配れない種別です: {$svgPath}");
    http_response_code(500);
    exit;
}

$modified = filemtime($path);
$etag = '"' . substr(hash('sha256', $path . '|' . (string) $modified), 0, 32) . '"';

/*
 * **private を付ける。** 錠が掛かっている構成では、共有プロキシに
 * 図面を溜めさせない。ブラウザ自身のキャッシュは効かせたいので no-store にはしない。
 */
header('Content-Type: ' . $contentType);
/*
 * **画像として配るだけにする。** SVG は中に <script> を書けるので、この URL を直接開くと
 * このサイトのオリジンでスクリプトが動きうる。これまで止めていたのは nginx の予備の CSP
 * 1行だけだった(security-review-2026-09-10 の 17)。ここで sandbox 付きの CSP を自分で送る
 * (<img> や地図の重ね合わせで使う分には影響しない)。
 */
header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=2592000');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . (string) filesize($path));
readfile($path);
