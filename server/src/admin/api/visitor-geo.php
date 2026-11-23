<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/visitor-geo.php';

/**
 * 訪問者の位置を DB に残す・読む(2026-11-08。lib/visitor-geo.php)。
 *
 *   GET  … 残っている位置の全部(新しく引いた順・5 万件まで)。行は短い配列([ip, 国, 地域, 都市, ASN, 組織, ISP, 引いた先, 時刻, 変わった回数])
 *   POST … ブラウザが引いた結果を残す {"entries":[{ip, code, region, city, asn, org, isp, src, at}, …]}(200 件まで・CSRF)
 *
 * **IP を URL に載せない**(読むときも全部を渡し、どの IP かはブラウザが選ぶ)。
 * 書くのは書き込みの権限がある管理者だけ(guard.php が GET 以外をまとめて断る)。読むだけの管理者は今までどおりブラウザに覚える。
 * 管理の記録には残さない(画面を開くたびに数件ずつ届くので、記録が埋まる)。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $rows = km_visitor_geo_list(km_db());
        echo json_encode(['success' => true, 'rows' => $rows, 'canWrite' => !empty($KM_ADMIN_CAN_WRITE)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['error' => 'GET か POST で送ってください。']);
        exit;
    }
    // 本文を読む前に弾く
    if (!km_csrf_verify()) {
        http_response_code(403);
        echo json_encode(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。']);
        exit;
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, 256 * 1024 + 1);
    if (strlen($raw) > 256 * 1024) {
        throw new InvalidArgumentException('本文が大きすぎます。');
    }
    $input = json_decode($raw, true);
    $list = is_array($input) && is_array($input['entries'] ?? null) && array_is_list($input['entries']) ? $input['entries'] : null;
    if ($list === null || count($list) > KM_VISITOR_GEO_SAVE_MAX) {
        throw new InvalidArgumentException('{"entries":[…]} を 1〜' . KM_VISITOR_GEO_SAVE_MAX . ' 件で送ってください。');
    }
    $entries = array_values(array_filter(array_map('km_visitor_geo_normalize', $list)));
    $result = km_visitor_geo_save(km_db(), $entries);
    echo json_encode(['success' => true, 'received' => count($list), 'accepted' => count($entries)] + $result);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('visitor-geo.php failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => '位置の表を読み書きできませんでした。']);
}
