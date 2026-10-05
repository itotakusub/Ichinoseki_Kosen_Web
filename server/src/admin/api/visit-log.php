<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/visit-log.php';

/**
 * 訪問者の記録(2026-10-06。lib/visit-log.php)。
 *
 * **生の行をそのまま返すだけ**(1 行 1 つの JSON。application/x-ndjson)。数える・まとめる・位置に直すのは
 * 管理画面のブラウザ(assets/js/visitors.js)。PHP は行を読み解かない(メモリを使わない)。
 *
 *   ?days=1〜30(既定 7)
 *
 * 上限(lib/visit-log.php の KM_VISIT_LOG_MAX_BYTES)を超えたら、新しい方を残して古い方を切り、
 * X-KM-Truncated: 1 を付ける。記録の置き場が無い・読めないときは X-KM-Visit-Log: missing を付けて空で返す。
 */

$days = (int) ($_GET['days'] ?? 7);
$days = max(1, min(KM_VISIT_LOG_MAX_DAYS, $days));

$present = is_dir(KM_VISIT_LOG_DIR) && is_readable(KM_VISIT_LOG_DIR);
$result = km_visit_log_plan($present ? km_visit_log_files($days) : []);

header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-KM-Visit-Log: ' . ($present ? 'ok' : 'missing'));
header('X-KM-Truncated: ' . ($result['truncated'] ? '1' : '0'));
km_visit_log_send($result['plan']);
