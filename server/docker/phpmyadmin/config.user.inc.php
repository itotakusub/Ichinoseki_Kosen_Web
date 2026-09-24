<?php
/*
 * phpMyAdmin の追加設定(2026-09-14 の診断で追加)。
 *
 * compose.yaml が /etc/phpmyadmin/config.user.inc.php へ読み取り専用でマウントする。
 * イメージの config.inc.php は、PMA_HOST などから $cfg を組み立てた**最後に**このファイルを読む。
 *
 * ## なぜ要るのか
 *
 * イメージの既定のままだと **root でログインでき(AllowRoot 未指定 = 許可)、
 * 空のパスワードも通る(AllowNoPassword = true)**(本番の phpMyAdmin 5.2.3 で実測)。
 * phpMyAdmin は nginx の IP 制限と Logto のゲート(MFA)の内側にあるが、
 * そこを越えた相手に「MariaDB の root のパスワードを試す場所」を残さない。
 *
 * ## root で入りたいとき
 *
 * 普段の作業は MARIADB_USER(アプリの利用者)で足りる。どうしても root が要るときだけ
 * .env に `KM_PMA_ALLOW_ROOT=1` を書いて `docker compose up -d phpmyadmin`。
 * **済んだら消して、もう一度 up すること。** '1' 以外(空・0・true など)はすべて禁止のまま。
 */

$kmPmaAllowRoot = getenv('KM_PMA_ALLOW_ROOT') === '1';

if (isset($cfg['Servers']) && is_array($cfg['Servers'])) {
    foreach (array_keys($cfg['Servers']) as $kmPmaServer) {
        $cfg['Servers'][$kmPmaServer]['AllowRoot'] = $kmPmaAllowRoot;
        $cfg['Servers'][$kmPmaServer]['AllowNoPassword'] = false;
    }
}
unset($kmPmaServer, $kmPmaAllowRoot);

/*
 * 操作が無いまま 30 分(1800 秒)でログインを切る。イメージの既定(1440 秒)に任せず、書いて固定する。
 * PHP のセッションの寿命(session.gc_maxlifetime、既定 1440)がこれより短いと、
 * phpMyAdmin が画面に警告を出し、しかも先にセッションが消えるので、同じ値に揃える。
 */
$cfg['LoginCookieValidity'] = 1800;
ini_set('session.gc_maxlifetime', '1800');
