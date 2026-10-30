<?php

declare(strict_types=1);

/**
 * 屋台の今の値(メニュー・混み具合)を読む口(2026-10-30、振り返りのフェーズ 5 の 2。中身は lib/stalls.php)。
 *
 *   GET /api/stalls.php
 *   → {"success":true,"stalls":[{"id":1,"menu":[…],"waiting":12,"note":"…","menuAt":"…","waitingAt":"…"}]}
 *
 * - 公開の地図(Main/stalls.js)が 30 秒ごとに読み直す。場所は返さない(場所は地図のデータの stalls が持つ。錠と隠した地点はあちらが見る)
 * - 地図に錠が掛かっていれば空
 * - 個人の値は無いが、錠の解除はセッションごとなので、控えは端末の中だけ 15 秒(読み直しの回数を抑える)
 */

require_once __DIR__ . '/../lib/session.php';
km_session_start();
require_once __DIR__ . '/../lib/map-guest.php';
km_map_guest_verify();
session_write_close();

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-access.php';
require_once __DIR__ . '/../lib/stalls.php';

require_method('GET');

try {
    $pdo = km_db();
    if (!km_map_view_unlocked(km_map_access_config())) {
        respond(['success' => true, 'stalls' => []]);
    }
    // 場所は返さないので、番号の地点で絞らない(地図のデータに無い id は画面が捨てる)
    $stalls = km_stalls_status_rows(km_stalls_public_raw($pdo), time());
} catch (Throwable $exception) {
    error_log('api/stalls.php failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

header('Cache-Control: private, max-age=15');
respond(['success' => true, 'stalls' => $stalls]);
