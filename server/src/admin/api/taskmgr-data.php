<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';

/**
 * タスクマネージャーの数値(2026-10-05、利用者の指示)。
 *
 * **サーバーは生のデータを束ねて渡すだけ。変換(割合・単位・CPU の使用率・グラフ)はブラウザがする**
 * (利用者の指示「サーバー側はデータを送信するだけで、変換はクライアント側で」)。
 * 受け取った JSON は**組み直さない** —— json_validate で形だけ確かめ、文字列のまま差し込む
 * (デコードして組み直すと、その分のメモリと CPU をサーバーが使う)。
 *
 *   glances … Glances の API(monitor の網。ホスト全体だけ。docker.sock は渡していない)
 *   apache  … このコンテナの /server-status?auto(Require local)のテキスト
 *   host    … scripts/host-stats.sh が root の cron で書く JSON(コンテナ別・ログの大きさ・ディスク・通信)
 *
 * 取れなかったものは null。**ここは nginx で IP を絞ってある**(taskmgr-allow*.local.conf。管理者のサインインの上にもう 1 枚)。
 */

const KM_TASKMGR_GLANCES = 'http://glances:61208/api/4/';
const KM_TASKMGR_GLANCES_PLUGINS = [
    'quicklook', 'cpu', 'percpu', 'load', 'mem', 'memswap', 'processcount', 'uptime', 'system', 'diskio', 'processlist/top/15',
];
const KM_TASKMGR_HOSTSTATS = '/var/www/hoststats/stats.json';
const KM_TASKMGR_MAX_BYTES = 512 * 1024;

/** 1 つ取る。**2 秒で諦める**(画面は 5 秒ごとに読みに来るので、待たせない)。 */
function km_taskmgr_fetch(string $url): ?string
{
    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_TIMEOUT_MS => 2000,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return is_string($body) && $status === 200 && strlen($body) <= KM_TASKMGR_MAX_BYTES ? $body : null;
}

/** JSON の形だけ確かめて、**文字列のまま**返す。形が違えば null(JSON の null として差し込む)。 */
function km_taskmgr_raw_json(?string $text): string
{
    return $text !== null && json_validate($text) ? $text : 'null';
}

$parts = [];
foreach (KM_TASKMGR_GLANCES_PLUGINS as $plugin) {
    $key = strtok($plugin, '/');
    $parts[] = json_encode($key) . ':' . km_taskmgr_raw_json(km_taskmgr_fetch(KM_TASKMGR_GLANCES . $plugin));
}
$glances = '{' . implode(',', $parts) . '}';

$apache = km_taskmgr_fetch('http://127.0.0.1/server-status?auto');

$hostText = null;
if (is_file(KM_TASKMGR_HOSTSTATS) && filesize(KM_TASKMGR_HOSTSTATS) <= KM_TASKMGR_MAX_BYTES) {
    $read = @file_get_contents(KM_TASKMGR_HOSTSTATS);
    $hostText = is_string($read) ? $read : null;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo '{"fetchedAt":' . time()
    . ',"glances":' . $glances
    . ',"apache":' . json_encode($apache, JSON_UNESCAPED_UNICODE)
    . ',"host":' . km_taskmgr_raw_json($hostText)
    . '}';
