<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';

/**
 * BAN の様子(2026-10-08、利用者の指示「BAN されているところやアクセスしてきた回数も表示」)。
 *
 * scripts/host-stats.sh が root の cron で 1 分ごとに書く run/hoststats/bans.json を、**組み直さずに**渡す
 * (taskmgr-data.php と同じ。web には run/hoststats を読み取り専用で渡してある)。
 *
 *   geoblock … 国の拒否(拒否する国・当てているか・落とした数)
 *   fail2ban … 牢ごとの、いま BAN している IP・BAN した数・失敗の数
 *   iptables … 22 番の DROP(kosenmap-ssh-kick)と落とした数
 *
 * 国の色付け・訪問の記録との突き合わせは管理画面のブラウザ(assets/js/visitors-world.js)。
 * まだ書かれていない(配備の直後・cron の前)ときは "bans": null。
 */

const KM_VISITOR_BANS_FILE = '/var/www/hoststats/bans.json';
const KM_VISITOR_BANS_MAX_BYTES = 256 * 1024;

$text = null;
if (is_file(KM_VISITOR_BANS_FILE) && filesize(KM_VISITOR_BANS_FILE) <= KM_VISITOR_BANS_MAX_BYTES) {
    $read = @file_get_contents(KM_VISITOR_BANS_FILE);
    $text = is_string($read) && json_validate($read) ? $read : null;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo '{"fetchedAt":' . time() . ',"bans":' . ($text ?? 'null') . '}';
