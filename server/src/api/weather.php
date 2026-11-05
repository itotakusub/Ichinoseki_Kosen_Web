<?php

declare(strict_types=1);

/**
 * 一関の天気(2026-11-05。中身は lib/weather.php)。
 *
 *   GET /api/weather.php
 *   → {"success":true,"rain":bool,"reason":"observed|forecast|none|unknown","snow":bool,"pop":N|null,
 *      "observed":{"at","temp","precip10m","precip1h"}|null,
 *      "days":[{"date","code","kind","telop","telopEn","pop","min","max"}],"place":"一関","source":"気象庁","reportedAt"}
 *
 * - Web の地図の「雨の日」(自動)・予定の見出し・Android のアプリが読む。**アクセスコードは要らない**(気象庁が公開している天気だけ)
 * - 受け取るものは無い(問い合わせの中身を何も見ない)。気象庁へ行くのはこのサーバーで、利用者の情報は送らない
 * - 5 分は使い回してよい(中身は誰が見ても同じ)。入口の回数は nginx の km_api で絞る
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/weather.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    respond(['success' => false, 'message' => 'GET required.'], 405);
}

$now = time();
$weather = km_weather_public(km_weather_state($now), $now);
header('Cache-Control: public, max-age=300');
respond(['success' => true] + $weather);
