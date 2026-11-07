<?php

declare(strict_types=1);

/**
 * 読み・別名と英語の名前を CSV でまとめて書き出す・取り込む(2026-11-07、plan.md 段 2-1「まとめて取り込む口が無ければ足す」)。
 *
 * 画面は admin/search-aliases.php。**取り込みは 2 段**: CSV を上げる → 変わる行だけを並べて見せる(まだ書かない)→「取り込む」で書く。
 *
 * - 列は見出しで探す: `id` は必須、`readings`(読み・別名)・`english`(英語の名前)のどちらかが要る。
 *   `kind`(種類)があれば、いま開いている種類と同じ行だけ受ける。`name`・`where` など他の列は読まない(下書きの確かめ用に置いてよい)
 * - **空のセルは「変えない」**。消すときは `-` だけを書く(下書きの空欄で、入っている値を消さないため)
 * - 文字コードは UTF-8(先頭の BOM は外す)。UTF-8 でなければ Excel の Shift_JIS として読み直す
 * - 地図に無い id・形の違う id は数えて知らせ、取り込まない
 * - 書く直前に、見せたときの値から変わっていないかを確かめる(開いている間にほかの人が直した行を上書きしない)
 */

require_once __DIR__ . '/search-aliases.php';
require_once __DIR__ . '/english-names.php';

const KM_ALIAS_CSV_MAX_BYTES = 1024 * 1024;
const KM_ALIAS_CSV_MAX_ROWS = 3000;
const KM_ALIAS_CSV_CLEAR = '-';
/** 見せてから取り込むまでの時間の上限(秒) */
const KM_ALIAS_CSV_PLAN_TTL = 1800;

/** 見出しの名前 → 列の役。日本語の見出しも受ける */
const KM_ALIAS_CSV_HEADERS = [
    'kind' => 'kind', '種類' => 'kind',
    'id' => 'id',
    'readings' => 'readings', '読み・別名' => 'readings', '読み' => 'readings',
    'english' => 'english', '英語の名前' => 'english', '英語' => 'english',
];

/** 文字コードをそろえる(BOM を外す・UTF-8 でなければ Shift_JIS として読む)。**純粋関数** */
function km_alias_csv_decode(string $raw): string
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
        $raw = is_string($converted) ? $converted : '';
    }

    return $raw;
}

/**
 * CSV の文字列を行の配列にする。**純粋関数**
 *
 * @return list<list<string>>
 */
function km_alias_csv_rows(string $text): array
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        return [];
    }
    fwrite($stream, $text);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = array_map(static fn ($cell): string => (string) $cell, $row);
        if (count($rows) > KM_ALIAS_CSV_MAX_ROWS + 1) {
            break;
        }
    }
    fclose($stream);

    return $rows;
}

/**
 * 取り込みの計画(まだ書かない)。**純粋関数**
 *
 * @param array<string, string> $targets 取り込めるもの id => 名前(いま開いている種類の、画面に並ぶもの全部)
 * @param array<string, list<string>> $currentAliases id => いまの読み・別名
 * @param array<string, string> $currentEnglish id => いまの英語の名前
 * @return array{changes: list<array{id:string, name:string, oldReadings:string, newReadings:?string, oldEnglish:string, newEnglish:?string}>,
 *               errors: list<string>, unchanged:int, rows:int}
 */
function km_alias_csv_plan(string $raw, string $kind, array $targets, array $currentAliases, array $currentEnglish): array
{
    $result = ['changes' => [], 'errors' => [], 'unchanged' => 0, 'rows' => 0];
    if (strlen($raw) > KM_ALIAS_CSV_MAX_BYTES) {
        $result['errors'][] = 'CSV が大きすぎます(' . (KM_ALIAS_CSV_MAX_BYTES / 1024 / 1024) . 'MB まで)。';
        return $result;
    }
    $rows = km_alias_csv_rows(km_alias_csv_decode($raw));
    if ($rows === []) {
        $result['errors'][] = 'CSV が空か、読めませんでした。';
        return $result;
    }
    if (count($rows) > KM_ALIAS_CSV_MAX_ROWS + 1) {
        $result['errors'][] = '行が多すぎます(' . KM_ALIAS_CSV_MAX_ROWS . ' 行まで)。';
        return $result;
    }

    // 見出し
    $columns = [];
    foreach (array_shift($rows) as $index => $label) {
        $role = KM_ALIAS_CSV_HEADERS[mb_strtolower(trim($label))] ?? KM_ALIAS_CSV_HEADERS[trim($label)] ?? null;
        if ($role !== null && !isset($columns[$role])) {
            $columns[$role] = $index;
        }
    }
    if (!isset($columns['id']) || (!isset($columns['readings']) && !isset($columns['english']))) {
        $result['errors'][] = '1 行目の見出しに id と、readings(読み・別名)か english(英語の名前)が要ります。';
        return $result;
    }

    $seen = [];
    foreach ($rows as $i => $row) {
        $line = $i + 2;
        $cell = static fn (string $role): ?string => isset($columns[$role]) ? trim((string) ($row[$columns[$role]] ?? '')) : null;
        $id = strtolower((string) $cell('id'));
        if ($id === '' && implode('', $row) === '') {
            continue;
        }
        $result['rows']++;
        $rowKind = $cell('kind');
        if ($rowKind !== null && $rowKind !== '' && $rowKind !== $kind) {
            continue; // ほかの種類の行(1 つの CSV に混ぜてよい)
        }
        if (!km_search_alias_target_valid($kind, $id)) {
            $result['errors'][] = "{$line} 行目: id の形が違います。";
            continue;
        }
        if (!isset($targets[$id])) {
            $result['errors'][] = "{$line} 行目: 地図に無い id です({$id})。";
            continue;
        }
        if (isset($seen[$id])) {
            $result['errors'][] = "{$line} 行目: 同じ id が {$seen[$id]} 行目にもあります(あとの行は読みません)。";
            continue;
        }
        $seen[$id] = $line;

        $oldReadings = km_search_alias_text($currentAliases[$id] ?? []);
        $oldEnglish = (string) ($currentEnglish[$id] ?? '');
        $newReadings = null;
        $newEnglish = null;
        $readings = $cell('readings');
        if ($readings !== null && $readings !== '') {
            $text = $readings === KM_ALIAS_CSV_CLEAR ? '' : km_search_alias_text(km_search_alias_list($readings));
            if ($text !== $oldReadings) {
                $newReadings = $text;
            }
        }
        $english = $cell('english');
        if ($english !== null && $english !== '') {
            $text = $english === KM_ALIAS_CSV_CLEAR ? '' : km_english_name_clean($english);
            if ($text !== $oldEnglish) {
                $newEnglish = $text;
            }
        }
        if ($newReadings === null && $newEnglish === null) {
            $result['unchanged']++;
            continue;
        }
        $result['changes'][] = [
            'id' => $id, 'name' => $targets[$id],
            'oldReadings' => $oldReadings, 'newReadings' => $newReadings,
            'oldEnglish' => $oldEnglish, 'newEnglish' => $newEnglish,
        ];
    }

    return $result;
}

/**
 * 書き出し(Excel で開けるよう BOM 付きの UTF-8)。取り込みと同じ見出し。**純粋関数**
 *
 * @param list<array{id:string, name:string, where:string}> $rows
 * @param array<string, list<string>> $aliases
 * @param array<string, string> $english
 */
function km_alias_csv_export(string $kind, array $rows, array $aliases, array $english): string
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['kind', 'id', 'name', 'where', 'readings', 'english'], ',', '"', '');
    foreach ($rows as $row) {
        // 表計算ソフトが式として読まないよう、= + - @ で始まる名前は ' を前に付ける(読み・英語の列は取り込みで使うので変えない)
        $name = preg_match('/\A[=+\-@]/', $row['name']) === 1 ? "'" . $row['name'] : $row['name'];
        fputcsv($stream, [$kind, $row['id'], $name, $row['where'], km_search_alias_text($aliases[$row['id']] ?? []), (string) ($english[$row['id']] ?? '')], ',', '"', '');
    }
    rewind($stream);
    $out = (string) stream_get_contents($stream);
    fclose($stream);

    return $out;
}
