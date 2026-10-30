<?php

declare(strict_types=1);

/**
 * 読み・別名(2026-10-30、高専祭の振り返りのフェーズ 4 の 1)。
 *
 * 振り返り: 「れっつぷれいでんさん」「レッツプレイ電算」「電算部」で Let's play Densan! が出ない。
 * 漢字の読み・英語のカタカナ・部活の呼び名は、文字の近さ(もしかして)では拾えない。**人が書いておく**。
 *
 * - 地点(node。id は 32 字)・一時地点(poi。番号)・ポスター(poster。番号)ごとに、読み・別名を何個か持つ
 * - **別の表に持つ**(km_map_node_projects と同じ理由。ALTER は要らない。地図の取り込みで消えない)
 * - 管理画面「データベース → 読み・別名」(admin/search-aliases.php)で入れる
 * - 公開の地図データ(lib/map-data.php)が地点・一時地点・ポスターに `readings` として付ける。
 *   検索(Main/app.js → Main/search-rank.js)の別名と、案内チャットの一覧(lib/guide-chat.php)に入る
 * - アプリへの配信(lib/app-map-convert.php)も地点と一時地点に `searchAliases` として付ける
 */

const KM_SEARCH_ALIAS_KINDS = ['node', 'poi', 'poster'];
/** 1 件に持てる数・1 つの長さ(字)・まとめた長さ(字) */
const KM_SEARCH_ALIAS_MAX_COUNT = 10;
const KM_SEARCH_ALIAS_MAX_LENGTH = 40;
const KM_SEARCH_ALIAS_TEXT_MAX = 500;

/** 表を作る。**取引の外で呼ぶ**(CREATE は取引を確定させる) */
function km_search_aliases_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_search_aliases (
            target_kind VARCHAR(8) NOT NULL,
            target_id VARCHAR(64) NOT NULL,
            aliases VARCHAR(500) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (target_kind, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/**
 * 入力欄の文字を、読み・別名の一覧にする。**純粋関数**。
 * 区切りは読点・カンマ・改行・スラッシュ(空白は区切らない —— 「Let's play」を 2 つに割らない)。
 * 前後の空白を落とし、同じもの(大文字小文字・全角半角の違いだけのものも)は 1 つ。長すぎるものは切る。上限の数まで
 *
 * @return list<string>
 */
function km_search_alias_list(string $text): array
{
    $list = [];
    $seen = [];
    foreach (preg_split('/[、,，;；\/／\r\n]+/u', $text) ?: [] as $word) {
        $word = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $word));
        // 全角の空白も前後から落とす
        $word = (string) preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $word);
        if ($word === '') {
            continue;
        }
        $word = mb_substr($word, 0, KM_SEARCH_ALIAS_MAX_LENGTH);
        $key = mb_strtolower(class_exists('Normalizer') ? (string) Normalizer::normalize($word, Normalizer::FORM_KC) : $word);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $list[] = $word;
        if (count($list) >= KM_SEARCH_ALIAS_MAX_COUNT) {
            break;
        }
    }
    return $list;
}

/** 一覧を保存する形(読点で区切る)。上限の長さに収まるまで後ろから落とす。**純粋関数** */
function km_search_alias_text(array $list): string
{
    while ($list !== [] && mb_strlen(implode('、', $list)) > KM_SEARCH_ALIAS_TEXT_MAX) {
        array_pop($list);
    }
    return implode('、', $list);
}

/** 対象の id の形が合うか。node は 32 字の 16 進、poi・poster は正の整数。**純粋関数** */
function km_search_alias_target_valid(string $kind, string $id): bool
{
    return match ($kind) {
        'node' => preg_match('/\A[0-9a-f]{32}\z/', $id) === 1,
        'poi', 'poster' => preg_match('/\A[1-9][0-9]{0,9}\z/', $id) === 1,
        default => false,
    };
}

/**
 * 表の中身。表が無ければ空(地図を止めない)。
 *
 * @return array{node: array<string, list<string>>, poi: array<string, list<string>>, poster: array<string, list<string>>}
 */
function km_search_aliases_all(PDO $pdo): array
{
    $all = ['node' => [], 'poi' => [], 'poster' => []];
    try {
        $rows = $pdo->query('SELECT target_kind, target_id, aliases FROM km_search_aliases')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return $all;
    }
    foreach ($rows as $row) {
        $kind = (string) $row['target_kind'];
        if (!isset($all[$kind])) {
            continue;
        }
        $list = km_search_alias_list((string) $row['aliases']);
        if ($list !== []) {
            $all[$kind][(string) $row['target_id']] = $list;
        }
    }
    return $all;
}

/** 1 件を保存する(空なら行を消す)。返すのは保存した一覧。表は先に作っておくこと */
function km_search_alias_save(PDO $pdo, string $kind, string $id, string $text): array
{
    if (!in_array($kind, KM_SEARCH_ALIAS_KINDS, true) || !km_search_alias_target_valid($kind, $id)) {
        throw new InvalidArgumentException('読み・別名の対象が正しくありません。');
    }
    $list = km_search_alias_list($text);
    if ($list === []) {
        $pdo->prepare('DELETE FROM km_search_aliases WHERE target_kind = ? AND target_id = ?')->execute([$kind, $id]);
        return [];
    }
    $pdo->prepare(
        'INSERT INTO km_search_aliases (target_kind, target_id, aliases) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE aliases = VALUES(aliases)'
    )->execute([$kind, $id, km_search_alias_text($list)]);
    return $list;
}

/**
 * アプリへ配る地図(km_app_map_publish の $map)の地点と一時地点に `searchAliases` を付ける。**引数を書き換える**。
 * 地点は uuid → Website の id(ハイフンを抜いた 32 字)で、一時地点は `web-poi-<番号>` で引く。読みの無いものは形を変えない。
 * アプリの AdMapNode.searchAliases・MapEventPlace.searchAliases(NodeSearch.kt の検索に入る)
 */
function km_search_aliases_apply_app(stdClass $map, array $aliases): void
{
    $nodeAliases = $aliases['node'] ?? [];
    foreach ((isset($map->nodes) && is_array($map->nodes)) ? $map->nodes : [] as $node) {
        if (!($node instanceof stdClass)) {
            continue;
        }
        $id = strtolower(str_replace('-', '', (string) ($node->uuid ?? '')));
        if (isset($nodeAliases[$id])) {
            $node->searchAliases = $nodeAliases[$id];
        }
    }
    $poiAliases = $aliases['poi'] ?? [];
    if ($poiAliases === [] || !isset($map->events) || !is_array($map->events)) {
        return;
    }
    foreach ($map->events as $eventIndex => $event) {
        $places = is_array($event) ? ($event['places'] ?? null) : ($event->places ?? null);
        if (!is_array($places)) {
            continue;
        }
        foreach ($places as $placeIndex => $place) {
            $uuid = (string) (is_array($place) ? ($place['uuid'] ?? '') : ($place->uuid ?? ''));
            if (preg_match('/\Aweb-poi-([1-9][0-9]*)\z/', $uuid, $m) !== 1 || !isset($poiAliases[$m[1]])) {
                continue;
            }
            if (is_array($place)) {
                $places[$placeIndex]['searchAliases'] = $poiAliases[$m[1]];
            } else {
                $place->searchAliases = $poiAliases[$m[1]];
            }
        }
        if (is_array($event)) {
            $map->events[$eventIndex]['places'] = $places;
        } else {
            $event->places = $places;
        }
    }
}

/**
 * 公開の地図データ(km_map_data の戻り)の地点・一時地点・ポスターに `readings` を付ける。**純粋関数**(写しを返す)。
 * 読みの無いものには項目を足さない(形を変えない)。出していない地点は地図データに無いので、読みも漏れない
 */
function km_search_aliases_apply(array $mapData, array $aliases): array
{
    foreach (($aliases['node'] ?? []) as $id => $list) {
        if (isset($mapData['nodes'][$id]) && is_array($mapData['nodes'][$id])) {
            $mapData['nodes'][$id]['readings'] = $list;
        }
    }
    foreach (['eventPois' => 'poi', 'posters' => 'poster'] as $key => $kind) {
        if (!is_array($mapData[$key] ?? null) || ($aliases[$kind] ?? []) === []) {
            continue;
        }
        foreach ($mapData[$key] as $index => $row) {
            $list = is_array($row) ? ($aliases[$kind][(string) ($row['id'] ?? '')] ?? null) : null;
            if ($list !== null) {
                $mapData[$key][$index]['readings'] = $list;
            }
        }
    }
    return $mapData;
}
