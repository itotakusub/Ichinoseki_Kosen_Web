<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/site.php';
// km_mail_env(): 監視するメールの宛先を、送信の設定に合わせるために使う
require_once dirname(__DIR__, 2) . '/lib/mailer.php';

/**
 * 死活監視の実疎通。ブラウザから各サービスを直接 fetch すると自己署名証明書と
 * CORS で必ず失敗するため、サーバー間(web の PHP プロセス自身)から TCP 接続の可否だけを見る。
 * HTTP レスポンスの中身までは見ない「ポートが開いているか」だけの単純な死活監視。
 *
 * id は assets/js/services.js の SERVICES 配列と揃えてある。名前・アイコン等の表示情報は
 * 引き続き services.js 側の役目で、ここでは host:port だけを持つ。
 *
 * 見せる先はダッシュボードとタスクマネージャーの「サービス」(2026-11-02 にサービス監視の画面をまとめた)。
 * それまで結果を Soketi で他のタブへも配っていたが、Soketi ごと外した。各画面が自分で叩く。
 * phpMyAdmin と Soketi は監視先からも外した(撤去)。
 */

const KM_HEALTH_CHECK_TIMEOUT_SECONDS = 1.5;

/**
 * 監視先は **Docker ネットワーク内のサービス名**で持つ。
 *
 * ## なぜ公開ホストを見に行かないのか(1.0.4 で変更)
 *
 * 以前は `km_site_host()` + `KM_SITE_PORTS` で「人がブラウザで開く URL」を叩いていた。
 * 校内 LAN では動くが、**VPS では 8 サービス中 5 つが「停止中」と誤表示される**:
 *
 *   - `web-http`(8080)/ `web-https`(9443)は **LAN 専用のポート**。VPS は 80/443
 *   - `logto-admin`(3002)は **ホストの 127.0.0.1 にしか出さない**(SSH トンネル経由で見る設計)
 *   - `mariadb`(3306)も **publish しない**(外に出したら事故)
 *
 * さらに致命的なのは、**コンテナから自ホストの公開ポートへは ufw に阻まれる**こと。
 * 外からは Docker の DNAT が ufw を迂回するので繋がるが、コンテナ発の通信は INPUT を
 * 通るため落ちる。つまり公開ポートを叩く方式では、**動いていても down と出る**。
 *
 * サービス名なら同じネットワーク内で完結し、**LAN でも VPS でも同じように効く**。
 * 「生きているか」を見るという目的にも、こちらの方が素直
 * (公開できているかは web-https が応答している時点で分かる)。
 *
 * @return array<string, array{host:string, port:int}>
 */
function km_health_check_targets(): array
{
    return [
        'web-http'    => ['host' => 'reverse-proxy', 'port' => 80],
        'web-https'   => ['host' => 'reverse-proxy', 'port' => 443],
        'logto-core'  => ['host' => 'logto',         'port' => 3001],
        'logto-admin' => ['host' => 'logto',         'port' => 3002],
        'mariadb'     => ['host' => 'mariadb',       'port' => 3306],
        /*
         * メールは **SMTP の口**を見る。画面(Mailpit の 8025)が生きていても
         * SMTP が死んでいればメールは送れない。見逃したくないのはそちら。
         *
         * **宛先は設定に追従させる。** `mailpit:1025` に固定していると、
         * 送信先を実サービスへ切り替えたあとも Mailpit を見続けることになり、
         * **本当に使っている経路が落ちても「稼働中」と出る**。
         * 既定は Mailpit なので、検証環境の表示は変わらない。
         */
        'mailpit'     => [
            'host' => km_mail_env('MAIL_HOST', 'mailpit'),
            'port' => (int) km_mail_env('MAIL_PORT', '1025'),
        ],
    ];
}

function km_health_check_port(string $host, int $port): string
{
    $connection = @fsockopen($host, $port, $errno, $errstr, KM_HEALTH_CHECK_TIMEOUT_SECONDS);
    if ($connection === false) {
        return 'down';
    }
    fclose($connection);
    return 'up';
}

$result = [];
foreach (km_health_check_targets() as $id => $target) {
    $result[$id] = km_health_check_port($target['host'], $target['port']);
}
$result['checkedAt'] = date(DATE_ATOM);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode($result, JSON_UNESCAPED_SLASHES);

