<?php

/**
 * MariaDB 接続情報の見本。実際の値は git 管理外の db.local.php に置く
 * (logto_config.local.php と同じパターン)。
 *
 * 優先順位は km_db() 側で: 環境変数(compose.yaml の MARIADB_*) > このファイル形式の
 * db.local.php > host/port だけの既定値。database/user/password には既定値を
 * 持たせていないので、どちらからも取れなければ接続時に例外になる。
 */

declare(strict_types=1);

return [
    'host' => null,
    'port' => null,
    'database' => null,
    'user' => null,
    'password' => null,
];
