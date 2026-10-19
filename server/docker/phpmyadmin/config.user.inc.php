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

/*
 * ## 環境保管領域(phpMyAdmin configuration storage。2026-10-19、利用者の指示)
 *
 * ブックマーク・SQL の履歴・表の表示の設定・関連(リレーション)・デザイナーなどを保存する DB(phpmyadmin)と、
 * そこへ入る制御用の利用者(controluser)。無いと画面の下に「環境保管領域が完全に設定されていません」と出続け、
 * それらの機能が使えない。
 *
 * **.env に KM_PMA_CONTROLPASS があるときだけ有効にする。** 無いまま controluser だけ書くと、
 * 空のパスワードで繋ぎに行き、ログインのたびに接続の失敗を出す。作り方は docs/05-containers の §1-2
 * (DB と表は phpMyAdmin の像の sql/create_tables.sql、利用者は phpmyadmin.* だけ・TLS 必須)。
 *
 * 制御用の接続は、同じホストなら本体の接続の TLS の設定(PMA_SSL・PMA_SSL_CA)をそのまま使う(phpMyAdmin の仕様)。
 */
$kmPmaControlPass = (string) getenv('KM_PMA_CONTROLPASS');
if ($kmPmaControlPass !== '' && isset($cfg['Servers']) && is_array($cfg['Servers'])) {
    $kmPmaTables = [
        'bookmarktable' => 'pma__bookmark',
        'relation' => 'pma__relation',
        'table_info' => 'pma__table_info',
        'table_coords' => 'pma__table_coords',
        'pdf_pages' => 'pma__pdf_pages',
        'column_info' => 'pma__column_info',
        'history' => 'pma__history',
        'table_uiprefs' => 'pma__table_uiprefs',
        'tracking' => 'pma__tracking',
        'userconfig' => 'pma__userconfig',
        'recent' => 'pma__recent',
        'favorite' => 'pma__favorite',
        'users' => 'pma__users',
        'usergroups' => 'pma__usergroups',
        'navigationhiding' => 'pma__navigationhiding',
        'savedsearches' => 'pma__savedsearches',
        'central_columns' => 'pma__central_columns',
        'designer_settings' => 'pma__designer_settings',
        'export_templates' => 'pma__export_templates',
    ];
    foreach (array_keys($cfg['Servers']) as $kmPmaServer) {
        $cfg['Servers'][$kmPmaServer]['controluser'] = (string) (getenv('KM_PMA_CONTROLUSER') ?: 'pma');
        $cfg['Servers'][$kmPmaServer]['controlpass'] = $kmPmaControlPass;
        $cfg['Servers'][$kmPmaServer]['pmadb'] = 'phpmyadmin';
        foreach ($kmPmaTables as $kmPmaKey => $kmPmaTable) {
            $cfg['Servers'][$kmPmaServer][$kmPmaKey] = $kmPmaTable;
        }
    }
    unset($kmPmaServer, $kmPmaTables, $kmPmaKey, $kmPmaTable);
}
unset($kmPmaControlPass);
