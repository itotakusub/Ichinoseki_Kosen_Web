<?php

declare(strict_types=1);

/**
 * 管理画面から変えられる小さな設定の置き場。
 *
 * ## なぜ DB なのか
 *
 * 秘密を含む設定は `config/*.local.php`(配備対象外・ホスト側が正本)に置いている。
 * だがこちらは**秘密ではなく、管理画面から変えるもの**。ファイルに書くと
 * 配備のたびに触るファイルが増え、書き込み権限も要る。値は DB に置く。
 *
 * ## 1件のために表を作らない
 *
 * 「期限切れイベントの掃除」の設定を入れるために作ったが、**この先も同じ形の設定は増える**
 * ので、最初から名前と値の対にしてある。列を足す作りにすると、設定を足すたびに
 * マイグレーションが要る。
 *
 * 値は文字列で持つ。真偽や数値を入れたいときは、**読む側が意味を決める** ——
 * ここで型を持たせると、取り違えたときにどこで壊れたか分からなくなる。
 */

require_once __DIR__ . '/db.php';

function km_settings_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_settings (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    /*
     * **成功した後に立てる。**
     *
     * 先に立てると「試したこと」の印になってしまう。km_setting_get() は失敗を握り潰して
     * 既定値を返すので、1回目の CREATE がこけても画面は普通に出る。その同じリクエストで
     * km_setting_set() が呼ばれると、この関数は何もせずに戻り、**表が無いまま INSERT** して
     * 「保存できませんでした」になる —— しかも読む方は既定値を返し続けるので、
     * 表が無いことに気づけない。
     */
    $done = true;
}

/**
 * 設定を読む。**未設定なら既定値**。
 *
 * DB が読めないときも既定値を返す(例外を投げない)。設定1つのために
 * 画面が開けなくなるのは割に合わない。落ちたことはログに残す。
 */
function km_setting_get(PDO $pdo, string $name, string $default = ''): string
{
    try {
        km_settings_ensure_table($pdo);
        $statement = $pdo->prepare('SELECT value FROM km_settings WHERE name = ?');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : (string) $value;
    } catch (Throwable $exception) {
        error_log("km_setting_get({$name}) failed: " . $exception->getMessage());

        return $default;
    }
}

/** 設定を書く。**呼ぶ側が値を検証してから渡すこと。**ここでは中身を見ない。 */
function km_setting_set(PDO $pdo, string $name, string $value): void
{
    km_settings_ensure_table($pdo);
    $pdo->prepare(
        'INSERT INTO km_settings (name, value, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
    )->execute([$name, $value]);
}
