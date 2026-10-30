<?php

declare(strict_types=1);

/**
 * 英語の名前(2026-10-30、高専祭の振り返りのフェーズ 6 の 1)。
 *
 * 公開の地図を英語で見るとき(Main/i18n.js)、地点・一時地点・ポスターの名前を英語にする。**任意**: 無ければ日本語のまま。
 *
 * - 別の表に持つ(km_search_aliases と同じ理由。ALTER は要らない。地図の取り込みで消えない)
 * - 管理画面「データベース → 読み・別名」(admin/search-aliases.php)の「英語の名前」の欄で入れる
 * - 公開の地図データ(lib/map-data.php)が地点・一時地点に `nameEn`、ポスターに `titleEn` を付ける。
 *   英語のときは Main/i18n.js が名前と入れ替え、日本語の名前は読み(readings)に回す(日本語でも探せる)
 * - アプリへの配信(lib/app-map-publish.php)も地点に `titleEn` を付ける(アプリの英語の画面で使う)
 */

const KM_ENGLISH_NAME_MAX = 80;

function km_english_names_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_english_names (
            target_kind VARCHAR(8) NOT NULL,
            target_id VARCHAR(64) NOT NULL,
            name VARCHAR(80) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (target_kind, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 入れた名前を整える(制御文字を落とし、前後の空白を落とし、長さを切る)。**純粋関数** */
function km_english_name_clean(string $text): string
{
    $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    $text = (string) preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $text);
    return mb_substr($text, 0, KM_ENGLISH_NAME_MAX);
}

/**
 * 表の中身。表が無ければ空(地図を止めない)。
 *
 * @return array{node: array<string, string>, poi: array<string, string>, poster: array<string, string>}
 */
function km_english_names_all(PDO $pdo): array
{
    $all = ['node' => [], 'poi' => [], 'poster' => []];
    try {
        $rows = $pdo->query('SELECT target_kind, target_id, name FROM km_english_names')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return $all;
    }
    foreach ($rows as $row) {
        $kind = (string) $row['target_kind'];
        $name = km_english_name_clean((string) $row['name']);
        if (isset($all[$kind]) && $name !== '') {
            $all[$kind][(string) $row['target_id']] = $name;
        }
    }
    return $all;
}

/** 1 件を保存する(空なら行を消す)。対象の形は読み・別名と同じ決まり(km_search_alias_target_valid) */
function km_english_name_save(PDO $pdo, string $kind, string $id, string $text): string
{
    require_once __DIR__ . '/search-aliases.php';
    if (!in_array($kind, KM_SEARCH_ALIAS_KINDS, true) || !km_search_alias_target_valid($kind, $id)) {
        throw new InvalidArgumentException('英語の名前の対象が正しくありません。');
    }
    $name = km_english_name_clean($text);
    if ($name === '') {
        $pdo->prepare('DELETE FROM km_english_names WHERE target_kind = ? AND target_id = ?')->execute([$kind, $id]);
        return '';
    }
    $pdo->prepare(
        'INSERT INTO km_english_names (target_kind, target_id, name) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    )->execute([$kind, $id, $name]);
    return $name;
}

/**
 * 公開の地図データに英語の名前を付ける。**純粋関数**(写しを返す)。無いものには項目を足さない。
 * 出していない地点は地図データに無いので、名前も漏れない
 */
function km_english_names_apply(array $mapData, array $names): array
{
    foreach (($names['node'] ?? []) as $id => $name) {
        if (isset($mapData['nodes'][$id]) && is_array($mapData['nodes'][$id])) {
            $mapData['nodes'][$id]['nameEn'] = $name;
        }
    }
    foreach (['eventPois' => ['poi', 'nameEn'], 'posters' => ['poster', 'titleEn']] as $key => [$kind, $field]) {
        if (!is_array($mapData[$key] ?? null) || ($names[$kind] ?? []) === []) {
            continue;
        }
        foreach ($mapData[$key] as $index => $row) {
            $name = is_array($row) ? ($names[$kind][(string) ($row['id'] ?? '')] ?? null) : null;
            if ($name !== null) {
                $mapData[$key][$index][$field] = $name;
            }
        }
    }
    return $mapData;
}

/** アプリへ配る地図の地点に `titleEn` を付ける(uuid → 32 字の id で引く)。**引数を書き換える** */
function km_english_names_apply_app(stdClass $map, array $names): void
{
    $nodeNames = $names['node'] ?? [];
    if ($nodeNames === []) {
        return;
    }
    foreach ((isset($map->nodes) && is_array($map->nodes)) ? $map->nodes : [] as $node) {
        if (!($node instanceof stdClass)) {
            continue;
        }
        $id = strtolower(str_replace('-', '', (string) ($node->uuid ?? '')));
        if (isset($nodeNames[$id])) {
            $node->titleEn = $nodeNames[$id];
        }
    }
}
