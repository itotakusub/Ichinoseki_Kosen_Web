<?php

declare(strict_types=1);

/**
 * 地図の履歴とブックマーク(2026-10-23、利用者の指示「両方(Android と Web)に履歴とブックマークを追加」
 * 「端末の中だけで、ログインしているなら同期。規約・プライバシーポリシーの追記」)。
 *
 * - ふだんは**端末の中だけ**(アプリはファイル、Web は localStorage)。サインインしているときだけ、ここへ置いて端末どうしで揃える
 * - 置くのは: ブックマーク・開いた場所・検索した言葉・経路(出発 → 目的)・表示調整(アプリ・Web それぞれ)
 * - **混ぜ方は項目ごとに新しい方**。消した項目は消した時刻、「全部消す」は一覧ごとの時刻で持ち、古い方が生き返らない
 * - 誰のものかはサインインした sub だけで決める(他人の分は読めない)。退会で消える(lib/account-delete.php)
 *
 * 形(アプリの UserMapData.kt・Web の Main/user-history.js と同じ):
 *   {"version":1,
 *    "bookmarks":[{"uuid":"…","at":1760000000000}], "places":[{"uuid":"…","at":…}],
 *    "searches":[{"q":"…","at":…}], "routes":[{"from":"…","to":"…","at":…}],
 *    "removed":{"bookmarks":{"<key>":at}, …}, "cleared":{"places":at, …},
 *    "settings":{"android":{"values":{…},"at":…}, "web":{"values":{…},"at":…}}}
 */

require_once __DIR__ . '/db.php';

const KM_USER_MAP_LISTS = ['bookmarks' => 200, 'places' => 50, 'searches' => 30, 'routes' => 20];
/** 消した印を持つ数(一覧ごと) */
const KM_USER_MAP_REMOVED_MAX = 300;
/** 本文の上限(JSON) */
const KM_USER_MAP_MAX_BYTES = 64 * 1024;
const KM_USER_MAP_UUID_PATTERN = '/\A[0-9A-Za-z_-]{1,64}\z/';

function km_user_map_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_user_map_data (
            user_id VARCHAR(191) NOT NULL PRIMARY KEY,
            payload MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

/** 一覧の項目の鍵(同じものを 1 つにまとめる)。形が違えば null。**純粋関数** */
function km_user_map_item_key(string $list, mixed $item): ?string
{
    if (!is_array($item)) {
        return null;
    }
    return match ($list) {
        'bookmarks', 'places' => is_string($item['uuid'] ?? null) && preg_match(KM_USER_MAP_UUID_PATTERN, $item['uuid']) === 1 ? $item['uuid'] : null,
        'searches' => is_string($item['q'] ?? null) && ($q = trim($item['q'])) !== '' && mb_strlen($q) <= 64 && preg_match('/[\x00-\x1F\x7F]/u', $q) !== 1 ? $q : null,
        'routes' => is_string($item['from'] ?? null) && is_string($item['to'] ?? null)
            && preg_match(KM_USER_MAP_UUID_PATTERN, $item['from']) === 1 && preg_match(KM_USER_MAP_UUID_PATTERN, $item['to']) === 1
            && $item['from'] !== $item['to'] ? $item['from'] . '>' . $item['to'] : null,
        default => null,
    };
}

/** 時刻(ミリ秒)。形が違えば null。**純粋関数** */
function km_user_map_time(mixed $value): ?int
{
    return is_int($value) && $value >= 1_000_000_000_000 && $value <= 4_000_000_000_000 ? $value : null;
}

/**
 * 形を揃える(知らない項目・形の違う値は捨てる。上限で切る)。**純粋関数**
 *
 * @return array<string, mixed>
 */
function km_user_map_normalize(mixed $input): array
{
    $input = is_array($input) ? $input : [];
    $doc = ['version' => 1, 'removed' => [], 'cleared' => [], 'settings' => []];
    foreach (KM_USER_MAP_LISTS as $list => $max) {
        $items = [];
        foreach (is_array($input[$list] ?? null) ? $input[$list] : [] as $item) {
            $key = km_user_map_item_key($list, $item);
            $at = is_array($item) ? km_user_map_time($item['at'] ?? null) : null;
            if ($key === null || $at === null) {
                continue;
            }
            $clean = match ($list) {
                'searches' => ['q' => $key, 'at' => $at],
                'routes' => ['from' => $item['from'], 'to' => $item['to'], 'at' => $at],
                default => ['uuid' => $key, 'at' => $at],
            };
            if (!isset($items[$key]) || $items[$key]['at'] < $at) {
                $items[$key] = $clean;
            }
        }
        usort($items, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $doc[$list] = array_slice(array_values($items), 0, $max);

        $removed = [];
        foreach (is_array($input['removed'][$list] ?? null) ? $input['removed'][$list] : [] as $key => $at) {
            $time = km_user_map_time($at);
            if (is_string($key) && $key !== '' && mb_strlen($key) <= 130 && $time !== null) {
                $removed[$key] = $time;
            }
        }
        arsort($removed);
        $doc['removed'][$list] = array_slice($removed, 0, KM_USER_MAP_REMOVED_MAX, true);
        $cleared = km_user_map_time($input['cleared'][$list] ?? null);
        if ($cleared !== null) {
            $doc['cleared'][$list] = $cleared;
        }
    }
    foreach (['android', 'web'] as $platform) {
        $setting = $input['settings'][$platform] ?? null;
        $at = is_array($setting) ? km_user_map_time($setting['at'] ?? null) : null;
        /*
         * 表示の設定はその端末の形のまま預かる(アプリは項目と値、Web は表示調整の入れ子)。**サーバーは読み解かない**。
         * 名前の形と大きさ(16KB)だけ見る。使うのは同じ人の端末だけ
         */
        if ($at !== null && is_array($setting['values'] ?? null) && !array_is_list($setting['values'] ?: ['x' => 1])) {
            $values = array_filter($setting['values'], static fn ($key): bool => is_string($key) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $key) === 1, ARRAY_FILTER_USE_KEY);
            $encoded = json_encode($values, JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && strlen($encoded) <= 16 * 1024 && count($values) <= 200) {
                $doc['settings'][$platform] = ['values' => (object) $values, 'at' => $at];
            }
        }
    }

    return km_user_map_prune($doc);
}

/** 消した印・全部消した時刻より古い項目を落とす。**純粋関数** */
function km_user_map_prune(array $doc): array
{
    foreach (array_keys(KM_USER_MAP_LISTS) as $list) {
        $cleared = $doc['cleared'][$list] ?? 0;
        $removed = $doc['removed'][$list] ?? [];
        $doc[$list] = array_values(array_filter($doc[$list] ?? [], static function (array $item) use ($list, $cleared, $removed): bool {
            $key = km_user_map_item_key($list, $item);
            return $item['at'] > $cleared && ($key === null || !isset($removed[$key]) || $item['at'] > $removed[$key]);
        }));
    }

    return $doc;
}

/**
 * 2 つを混ぜる(項目ごとに新しい方・消した印と全部消した時刻は新しい方)。**純粋関数**。順番を入れ替えても同じ結果
 *
 * @return array<string, mixed>
 */
function km_user_map_merge(array $a, array $b): array
{
    $merged = ['version' => 1, 'removed' => [], 'cleared' => [], 'settings' => []];
    foreach (array_keys(KM_USER_MAP_LISTS) as $list) {
        $merged[$list] = array_merge($a[$list] ?? [], $b[$list] ?? []);
        $removed = $a['removed'][$list] ?? [];
        foreach ($b['removed'][$list] ?? [] as $key => $at) {
            $removed[$key] = max($removed[$key] ?? 0, $at);
        }
        $merged['removed'][$list] = $removed;
        $cleared = max($a['cleared'][$list] ?? 0, $b['cleared'][$list] ?? 0);
        if ($cleared > 0) {
            $merged['cleared'][$list] = $cleared;
        }
    }
    foreach (['android', 'web'] as $platform) {
        $sa = $a['settings'][$platform] ?? null;
        $sb = $b['settings'][$platform] ?? null;
        $pick = $sa === null ? $sb : ($sb === null ? $sa : ($sb['at'] > $sa['at'] ? $sb : $sa));
        if ($pick !== null) {
            $merged['settings'][$platform] = $pick;
        }
    }

    // normalize が鍵ごとに新しい方を残し、並べ、上限で切り、消した印より古いものを落とす
    return km_user_map_normalize(json_decode((string) json_encode($merged), true));
}

/** @return array<string, mixed>|null */
function km_user_map_find(PDO $pdo, string $userId): ?array
{
    km_user_map_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT payload FROM km_user_map_data WHERE user_id = ?');
    $statement->execute([$userId]);
    $payload = $statement->fetchColumn();
    if (!is_string($payload)) {
        return null;
    }
    return km_user_map_normalize(json_decode($payload, true));
}

/** 受け取ったものを、いま置いてあるものと混ぜて置き、混ぜた結果を返す */
function km_user_map_sync(PDO $pdo, string $userId, array $incoming): array
{
    km_user_map_ensure_table($pdo);
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT payload FROM km_user_map_data WHERE user_id = ? FOR UPDATE');
        $statement->execute([$userId]);
        $stored = $statement->fetchColumn();
        $current = is_string($stored) ? km_user_map_normalize(json_decode($stored, true)) : km_user_map_normalize([]);
        $merged = km_user_map_merge($current, km_user_map_normalize($incoming));
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > KM_USER_MAP_MAX_BYTES * 2) {
            throw new InvalidArgumentException('履歴が大きすぎます。');
        }
        $pdo->prepare(
            'INSERT INTO km_user_map_data (user_id, payload, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW()'
        )->execute([$userId, $json]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return $merged;
}

/** アカウントから全部消す(サーバーの分だけ。端末の中は端末で消す) */
function km_user_map_delete(PDO $pdo, string $userId): void
{
    km_user_map_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_user_map_data WHERE user_id = ?')->execute([$userId]);
}
