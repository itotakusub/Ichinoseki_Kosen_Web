<?php

declare(strict_types=1);

/**
 * アプリの表示調整の既定を配る(2026-10-23、利用者の指示「Android で設定した表示調整の既定も配れるように」)。
 *
 * 管理アプリの表示調整で「この表示を既定として配る」→ api/display-defaults.php(管理者のトークン)→
 * 一般のアプリは地図を取るときに GET で読み、**利用者が変えていない項目だけ**に当てはめる(アプリの DisplayDefaults.kt)。
 * 測位のパラメータ(lib/positioning-params.php)と同じ形。
 *
 * **どの項目を配るかはアプリが決める**(表示調整の画面の項目。アプリの DISPLAY_DEFAULT_KEYS)。サーバーは形だけ確かめる:
 * 名前は英小文字・数字・_、値は真偽・整数・短い文字列・短い文字列の並び。置き場は専用の表。
 */

require_once __DIR__ . '/db.php';

const KM_DISPLAY_DEFAULTS_MAX_KEYS = 200;
const KM_DISPLAY_DEFAULTS_KEY_PATTERN = '/\A[a-z0-9_]{1,64}\z/';

function km_display_defaults_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_display_defaults (
            id TINYINT NOT NULL PRIMARY KEY,
            body MEDIUMTEXT NOT NULL,
            updated_by VARCHAR(191) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ensured = true;
}

/**
 * 検める。**純粋関数。** 形の違うものは理由を付けて断る(InvalidArgumentException)。
 *
 * @param array<mixed> $input
 * @return array<string, bool|int|string|list<string>>
 */
function km_display_defaults_normalize(array $input): array
{
    if (count($input) > KM_DISPLAY_DEFAULTS_MAX_KEYS) {
        throw new InvalidArgumentException('項目が多すぎます(' . KM_DISPLAY_DEFAULTS_MAX_KEYS . ' まで)。');
    }
    $clean = static function (string $text): string {
        if (mb_strlen($text) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1 || !mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('文字列の値が長すぎるか、使えない文字があります。');
        }
        return $text;
    };
    $result = [];
    foreach ($input as $key => $value) {
        if (!is_string($key) || preg_match(KM_DISPLAY_DEFAULTS_KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('項目の名前の形が違います: ' . mb_substr((string) $key, 0, 40));
        }
        if (is_bool($value)) {
            $result[$key] = $value;
        } elseif (is_int($value) && abs($value) <= 1_000_000) {
            $result[$key] = $value;
        } elseif (is_string($value)) {
            $result[$key] = $clean($value);
        } elseif (is_array($value) && array_is_list($value) && count($value) <= 32) {
            $result[$key] = array_map(static function ($item) use ($clean): string {
                if (!is_string($item)) {
                    throw new InvalidArgumentException('並びの値は文字列だけです。');
                }
                return $clean($item);
            }, $value);
        } else {
            throw new InvalidArgumentException('値の形が違います: ' . $key);
        }
    }
    ksort($result);

    return $result;
}

/** いま配っている値。配っていない・読めなければ null。 */
function km_display_defaults_stored(PDO $pdo): ?array
{
    try {
        km_display_defaults_ensure_table($pdo);
        $body = $pdo->query('SELECT body FROM km_display_defaults WHERE id = 1')->fetchColumn();
    } catch (Throwable $exception) {
        error_log('km_display_defaults_stored: ' . $exception->getMessage());
        return null;
    }
    if (!is_string($body) || $body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    try {
        return km_display_defaults_normalize($decoded);
    } catch (InvalidArgumentException $exception) {
        error_log('km_display_defaults_stored: ' . $exception->getMessage() . '(配らずに動きます)');
        return null;
    }
}

/** 配る。検めてから保存し、保存した形を返す。 */
function km_display_defaults_publish(PDO $pdo, array $input, ?string $updatedBy): array
{
    $values = km_display_defaults_normalize($input);
    $json = json_encode((object) $values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    km_display_defaults_ensure_table($pdo);
    $pdo->prepare(
        'INSERT INTO km_display_defaults (id, body, updated_by, updated_at) VALUES (1, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by), updated_at = NOW()'
    )->execute([$json, $updatedBy]);

    return $values;
}

/** 配るのをやめる。行は消さず空にする(誰がやめたかを残す)。 */
function km_display_defaults_reset(PDO $pdo, ?string $updatedBy): void
{
    km_display_defaults_ensure_table($pdo);
    $pdo->prepare(
        "INSERT INTO km_display_defaults (id, body, updated_by, updated_at) VALUES (1, '', ?, NOW())
         ON DUPLICATE KEY UPDATE body = '', updated_by = VALUES(updated_by), updated_at = NOW()"
    )->execute([$updatedBy]);
}
