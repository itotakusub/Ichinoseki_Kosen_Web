<?php

declare(strict_types=1);

/**
 * Android アプリの設定をアカウントへ紐付けて預かる。
 *
 * 端末を替えても、アプリを入れ直しても設定が戻るようにするためのもの。
 * **中身は解釈しない。** アプリが `kosenmap-settings` 形式で作った JSON を
 * そのまま預かり、そのまま返す。サーバーが設定の意味を知ってしまうと、
 * アプリ側で設定を1つ増やすたびにサーバーも直すことになる。
 *
 * テーブルの作成は km_profile_ensure_table() などと同じ遅延作成。
 */

const KM_APP_SETTINGS_MAX_BYTES = 64 * 1024;

function km_app_settings_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_app_settings (
            user_id VARCHAR(191) NOT NULL PRIMARY KEY,
            payload MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** @return array{payload:string, updatedAtEpoch:int}|null */
function km_app_settings_find(PDO $pdo, string $userId): ?array
{
    km_app_settings_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT payload, UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
         FROM km_app_settings WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'payload' => (string) $row['payload'],
        'updatedAtEpoch' => (int) $row['updatedAtEpoch'],
    ];
}

/**
 * 設定を保存する。
 *
 * @throws InvalidArgumentException 形式が違う・大きすぎる場合
 */
function km_app_settings_store(PDO $pdo, string $userId, string $payload): int
{
    if (strlen($payload) > KM_APP_SETTINGS_MAX_BYTES) {
        throw new InvalidArgumentException('設定が大きすぎます。');
    }
    // 中身は解釈しないが、**JSON であることだけは確かめる**。
    // 壊れたものを預かると、次に取り出したときアプリ側で落ちる。
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('設定をJSONとして読み取れません。');
    }
    if (($decoded['format'] ?? '') !== 'kosenmap-settings') {
        throw new InvalidArgumentException('設定の形式が違います。');
    }

    km_app_settings_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO km_app_settings (user_id, payload, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW()'
    );
    $stmt->execute([$userId, $payload]);
    return time();
}

function km_app_settings_clear(PDO $pdo, string $userId): void
{
    km_app_settings_ensure_table($pdo);
    $stmt = $pdo->prepare('DELETE FROM km_app_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
}
