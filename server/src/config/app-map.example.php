<?php

/**
 * Android アプリ(来場者版)へ配信する地図データの設定。
 * 実際の値は git 管理外の app-map.local.php に置く(map-access.local.php と同じパターン)。
 *
 * **config/map-access.local.php とは別物。** あちらは Web 版地図の教職員氏名を
 * ブラウザセッションで解除するための設定で、こちらは Android アプリへ地図 JSON を
 * 配信するための設定。名前が似ているので取り違えないこと。
 */

declare(strict_types=1);

return [
    /**
     * 地図 JSON の置き場。空なら src/uploads/。
     * uploads/ は nginx の `location ~ ^/(lib|config|scripts|uploads)/ { return 404; }` で
     * 直接配信を塞いであるので、アクセスコードを知らない相手は URL を推測しても取れない。
     */
    'storageDir' => '',

    /**
     * 配信 ID(アプリ側の mapId)ごとの設定。
     * 管理アプリからエクスポートした JSON を storageDir へ置き、file にその名前を書く。
     */
    'maps' => [
        'kosen-main' => [
            /** storageDir 直下のファイル名。ディレクトリ区切りは使えない。 */
            'file' => 'app-map-kosen-main.json',

            /** 単調増加の整数。上げ忘れると端末が更新を取りに来ない。 */
            'revision' => 1,

            /**
             * **必須。** ISO-8601(オフセット付き)。
             * これを過ぎると来場者アプリは地図とアクセスコードを削除し、
             * 「マップの有効期限が切れました」の画面に戻る。
             */
            'expiresAt' => '2026-11-03T18:00:00+09:00',

            /** 来場者に適用するイベントの UUID。適用しないなら null。 */
            'activeEventUuid' => null,

            /**
             * 破損検出用の SHA-256 を付けるか。既定は true(この行は省略できる)。
             * Gson の再直列化と PHP の json_encode が一致することは、Android 側の
             * MapPackageTest が実際の出力を固定して検証している。
             * 万一ずれて更新が止まったときの逃げ道として false にできる。
             */
            'checksum' => true,
        ],
    ],

    /**
     * アクセスコードと配信 ID の対応。1つの配信 ID に複数のコードを割り当ててよい。
     * **コードは平文で置かない。** password_hash() の出力を貼る:
     *   php -r "echo password_hash('KOSEN2026', PASSWORD_DEFAULT), PHP_EOL;"
     *
     * 照合は総当たりで password_verify を回すので、数十件を超えないこと。
     */
    'codes' => [
        [
            'slug' => 'kosen-main',
            'hash' => null,
        ],
    ],

    /**
     * 「組織内」と数えるメールドメイン(api/app-stats.php が使う)。
     *
     * 判定は **Logto の Organization が先**。組織に属していない利用者だけ、
     * ここのドメインで判定する。どちらにも当てはまらなければ組織外。
     *
     * サブドメインも組織内とみなす。'example.ac.jp' を書くと
     * 'sub.example.ac.jp' も通る('notexample.ac.jp' は通らない)。
     *
     * 空のままなら、Organization に属している人だけが組織内になる。
     */
    'organizationEmailDomains' => [
        // 'example.ac.jp',
    ],
];
