<?php

declare(strict_types=1);

/**
 * サーバーだけが知る鍵(HMAC 用)。
 *
 * **利用者の識別子や IP を、そのまま外へも DB へも出さない**ために使う:
 *
 *   ranking   … ランキングの利用者キー(Logto の sub の代わりに返す)と、検索語の出どころの印
 *   presence  … 在席の出どころの印(1つの IP から数える端末の上限)
 *
 * 置き場は DB(km_app_secrets)。初めて使うときに random_bytes(32) で作る。
 * **.env の秘密を流用しない** —— 用途の違う鍵を1本に束ねると、片方を入れ替えたときにもう片方が壊れる。
 *
 * 消すと、利用者キーと出どころの印がすべて別物になる(数え直しになるだけで、壊れはしない)。
 */

function km_app_secret(PDO $pdo, string $name): string
{
    static $memory = [];
    if (isset($memory[$name])) {
        return $memory[$name];
    }
    if (preg_match('/^[a-z0-9_]{1,32}$/', $name) !== 1) {
        throw new InvalidArgumentException('鍵の名前が不正です: ' . $name);
    }

    $read = static function () use ($pdo, $name) {
        $statement = $pdo->prepare('SELECT secret FROM km_app_secrets WHERE name = ?');
        $statement->execute([$name]);
        return $statement->fetchColumn();
    };

    try {
        $secret = $read();
    } catch (PDOException) {
        // 表がまだ無い
        $secret = false;
    }

    if (!is_string($secret)) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS km_app_secrets (
                name VARCHAR(32) NOT NULL PRIMARY KEY,
                secret VARBINARY(64) NOT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
        // 同時に2つのリクエストが作りに来ても、先に入った方が勝つ
        $pdo->prepare('INSERT IGNORE INTO km_app_secrets (name, secret, created_at) VALUES (?, ?, NOW())')
            ->execute([$name, random_bytes(32)]);
        $secret = $read();
    }

    if (!is_string($secret) || strlen($secret) < 32) {
        throw new RuntimeException('鍵を読めませんでした: ' . $name);
    }

    return $memory[$name] = $secret;
}

/**
 * 鍵つきの短い印(16進 32 文字)。同じ入力には同じ値、鍵を知らなければ元へ戻せない。
 * `$purpose` を混ぜるので、用途が違えば同じ値からでも別の印になる。
 */
function km_app_keyed_hash(string $secret, string $purpose, string $value): string
{
    return substr(hash_hmac('sha256', $purpose . "\n" . $value, $secret), 0, 32);
}
