<?php

/**
 * Logto Management API を叩くための m2m(machine-to-machine)アプリの資格情報の見本。
 * 実際の値は git 管理外の logto-m2m.local.php に置く(db.local.php と同じパターン)。
 *
 * 優先順位は lib/logto-management.php 側で:
 *   環境変数(compose.yaml の LOGTO_M2M_*) > このファイル形式の logto-m2m.local.php > 既定値
 * appId / appSecret には既定値を持たせていないので、どちらからも取れなければ例外になる
 * (秘密が空のまま静かに動くより、はっきり止まる方がよい)。
 *
 * Logto Console 側の前提:
 *   1. アプリケーション種別「Machine-to-Machine」で作成する
 *   2. **そのアプリに Logto Management API のロールを割り当てる**
 *      (割り当てが無いとトークンは取れても API 呼び出しが 403 になる)
 */

declare(strict_types=1);

return [
    // 管理画面のユーザー管理が使う。Users の書き込み権限が要る。
    'appId' => null,
    'appSecret' => null,

    /*
     * 参照しかしない口(api/app-stats.php の人数集計)が使う資格情報。
     *
     * **未ログインでも叩ける口なので、書き込みできる上の資格情報とは分ける。**
     * 実際に注入経路があるわけではないが、あとで手を入れたときの事故の範囲が変わる。
     *
     * Logto Console 側:
     *   1. もう1つ Machine-to-Machine アプリを作る(名前は kosenmap-readonly など)
     *   2. Management API のロールで **read: 系だけ**を与える
     *      人数集計に要るのは read:user と read:organization の2つ
     *
     * 両方 null なら上の資格情報へ落ちるので、設定しなくても動く。
     * **片方だけ書くと例外**になる(読み取り専用にしたつもりで書けるまま、を防ぐため)。
     */
    'readonlyAppId' => null,
    'readonlyAppSecret' => null,

    // Logto 本体のエンドポイント。既定は他の設定と同じ https://192.168.3.29:3001
    'endpoint' => null,

    // Management API のリソース指定子。Logto の既定値は下記のままでよい。
    // (自己ホストでもこの識別子は 'default' のまま使う)
    'resource' => 'https://default.logto.app/api',
];
