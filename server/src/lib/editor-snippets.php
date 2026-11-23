<?php

declare(strict_types=1);

/**
 * エディタの雛形(/editor/ と /code/ の候補に出る「a — リンク」「for」など)を JSON で取り込む(段 14-4。2026-11-13)。
 *
 * 利用者「予測変換は JSON で管理できませんか」「管理画面の取り込みだけ作る」。
 *
 * - 同梱の雛形は editor-src/src/data/html.js・js.js(正本)。その JSON の形が lib/editor-snippets.default.json
 *   (node の試験 editor.test.js が、同梱のデータと同じ中身かを見る)
 * - 管理画面「エディタの雛形」(admin/editor-snippets.php)で JSON のファイルを取り込むと、表に 1 行足す。
 *   公開の口 api/editor-snippets.php がいちばん新しい行を返し、エディタが起動のときに読んで差し替える
 * - 「同梱に戻す」も 1 行(json が NULL)。**前の中身は消さない**(表に残る。新しいほうから 20 行だけ持つ)
 * - 形の確かめはここ(km_editor_snippets_validate)。エディタの側(editor-src/src/data/snippets.js の normalizeSnippetJson)も同じ決まり
 *
 * JSON の形(version 1):
 *   { "version": 1,
 *     "html": { "ja": [ { "tag": "a", "label": "リンク", "body": "a href=\"${1:URLを入力}\">${2:リンクの文字}</a>" } ], "en": [ … ] },
 *     "css":  [ { "label": "display flex", "body": "display: flex;\ngap: ${1:1rem};", "ja": "…", "en": "…" } ],   (11-14 から。無くてもよい)
 *     "js":   [ { "label": "if", "body": "if (${1:condition}) {\n\t$0\n}", "ja": "もし…なら。", "en": "…" } ] }
 * 中身は誰が見ても同じ公開のもの(秘密を入れない)。エディタは雛形を**文字として差し込むだけ**で、実行しない
 *
 * 11-14(段 14-5。利用者の v2.json で分かったこと):
 * - HTML の body の先頭の `<` は**外して受ける**(書きたくなるので断らない。そのまま入れると `<<a` になる)。外した数は notes で知らせる
 * - `css` を受ける(それまでは黙って捨てていた)。知らない項目は捨てて notes で知らせる
 * - 上限: ファイル 1MB・CSS は 1,000 個(ほかは 300 個のまま)
 */

require_once __DIR__ . '/db.php';

const KM_EDITOR_SNIPPET_VERSION = 1;
/** editor-src/src/data/snippets.js の SNIPPET_LIMITS と同じ値にすること(check.php が見る) */
const KM_EDITOR_SNIPPET_MAX_ITEMS = 300;
const KM_EDITOR_SNIPPET_MAX_CSS_ITEMS = 1000;
const KM_EDITOR_SNIPPET_MAX_TAG = 40;
const KM_EDITOR_SNIPPET_MAX_LABEL = 80;
const KM_EDITOR_SNIPPET_MAX_BODY = 2000;
const KM_EDITOR_SNIPPET_MAX_DOC = 400;
/** 取り込むファイルの大きさの上限(同梱のものは約 14KB・利用者の v2 は約 256KB) */
const KM_EDITOR_SNIPPET_MAX_BYTES = 1048576;
/** 一番外で受ける項目(ほかは捨てて notes で知らせる) */
const KM_EDITOR_SNIPPET_KEYS = ['version', 'html', 'css', 'js'];
const KM_EDITOR_SNIPPET_KEEP_ROWS = 20;

function km_editor_snippets_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_editor_snippets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            json MEDIUMTEXT NULL,
            html_ja INT UNSIGNED NOT NULL DEFAULT 0,
            html_en INT UNSIGNED NOT NULL DEFAULT 0,
            js INT UNSIGNED NOT NULL DEFAULT 0,
            updated_by VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

/** 文字の数(見た目ではなく符号位置)で長さを見る。NUL は断る */
function km_editor_snippet_text(mixed $value, int $max, int $min = 1): bool
{
    if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
        return false;
    }
    $length = mb_strlen($value, 'UTF-8');

    return $length >= $min && $length <= $max;
}

/**
 * JSON の形を確かめる。**純粋関数**(check.php が試す)。
 *
 * @return array{ok: bool, value: ?array, error: string, notes: list<string>} error・notes は利用者に見せる日本語の文
 */
function km_editor_snippets_validate(mixed $data): array
{
    $fail = static fn (string $message): array => ['ok' => false, 'value' => null, 'error' => $message, 'notes' => []];
    if (!is_array($data) || array_is_list($data)) {
        return $fail('JSON の一番外はオブジェクト({ … })にしてください。');
    }
    if (($data['version'] ?? null) !== KM_EDITOR_SNIPPET_VERSION) {
        return $fail('"version": ' . KM_EDITOR_SNIPPET_VERSION . ' がありません(同梱の雛形を書き出したものを元にしてください)。');
    }
    $html = $data['html'] ?? null;
    $css = $data['css'] ?? [];
    if (!is_array($html) || !is_array($html['ja'] ?? null) || !is_array($html['en'] ?? null) || !is_array($data['js'] ?? null)
        || !array_is_list($html['ja']) || !array_is_list($html['en']) || !array_is_list($data['js'])) {
        return $fail('"html": { "ja": [ … ], "en": [ … ] } と "js": [ … ] が要ります。');
    }
    if (!is_array($css) || !array_is_list($css)) {
        return $fail('"css" は [ … ](並び)にしてください。');
    }
    foreach ([['html.ja', $html['ja'], KM_EDITOR_SNIPPET_MAX_ITEMS], ['html.en', $html['en'], KM_EDITOR_SNIPPET_MAX_ITEMS], ['css', $css, KM_EDITOR_SNIPPET_MAX_CSS_ITEMS], ['js', $data['js'], KM_EDITOR_SNIPPET_MAX_ITEMS]] as [$name, $list, $max]) {
        if (count($list) > $max) {
            return $fail("{$name} は {$max} 個までです(" . count($list) . ' 個ありました)。');
        }
    }
    $notes = [];
    $unknown = array_values(array_diff(array_map('strval', array_keys($data)), KM_EDITOR_SNIPPET_KEYS));
    if ($unknown !== []) {
        $shown = array_map(static fn (string $key): string => preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key) === 1 ? $key : '(読めない名前)', array_slice($unknown, 0, 5));
        $notes[] = '知らない項目 ' . implode('・', $shown) . (count($unknown) > 5 ? ' ほか' : '') . ' は使わずに捨てました(使えるのは html・css・js)。';
    }
    $value = ['version' => KM_EDITOR_SNIPPET_VERSION, 'html' => ['ja' => [], 'en' => []], 'css' => [], 'js' => []];
    $stripped = 0;
    foreach (['ja', 'en'] as $lang) {
        foreach ($html[$lang] as $index => $item) {
            $where = "html.{$lang} の " . ($index + 1) . ' 番目';
            if (!is_array($item)) {
                return $fail("{$where}: オブジェクトにしてください。");
            }
            if (!km_editor_snippet_text($item['tag'] ?? null, KM_EDITOR_SNIPPET_MAX_TAG) || preg_match('/^[a-z][a-z0-9-]*$/', (string) $item['tag']) !== 1) {
                return $fail("{$where}: tag は半角の小文字の英数字とハイフンだけ(例: a・img・my-element)。");
            }
            if (!km_editor_snippet_text($item['label'] ?? null, KM_EDITOR_SNIPPET_MAX_LABEL) || preg_match('/[\r\n]/', (string) $item['label']) === 1) {
                return $fail("{$where}: label は 1 行で " . KM_EDITOR_SNIPPET_MAX_LABEL . ' 字まで。');
            }
            $body = $item['body'] ?? null;
            // 先頭の < は外す(< を打ったあとに差し込むので、残すと <<a になる)
            if (is_string($body) && str_starts_with($body, '<')) {
                $body = substr($body, 1);
                $stripped++;
            }
            if (!km_editor_snippet_text($body, KM_EDITOR_SNIPPET_MAX_BODY)) {
                return $fail("{$where}: body は " . KM_EDITOR_SNIPPET_MAX_BODY . ' 字まで(空にはできません)。');
            }
            $value['html'][$lang][] = ['tag' => $item['tag'], 'label' => $item['label'], 'body' => $body];
        }
    }
    if ($stripped > 0) {
        $notes[] = "HTML の body の先頭の < を {$stripped} 個外しました(< を打ったあとに差し込むので、書かなくてよい)。";
    }
    foreach (['css' => $css, 'js' => $data['js']] as $section => $list) {
        foreach ($list as $index => $item) {
            $where = "{$section} の " . ($index + 1) . ' 番目';
            if (!is_array($item)) {
                return $fail("{$where}: オブジェクトにしてください。");
            }
            if (!km_editor_snippet_text($item['label'] ?? null, KM_EDITOR_SNIPPET_MAX_LABEL) || preg_match('/[\r\n]/', (string) $item['label']) === 1) {
                return $fail("{$where}: label は 1 行で " . KM_EDITOR_SNIPPET_MAX_LABEL . ' 字まで。');
            }
            if (!km_editor_snippet_text($item['body'] ?? null, KM_EDITOR_SNIPPET_MAX_BODY)) {
                return $fail("{$where}: body は " . KM_EDITOR_SNIPPET_MAX_BODY . ' 字まで(空にはできません)。');
            }
            $ja = $item['ja'] ?? '';
            $en = $item['en'] ?? '';
            if (!km_editor_snippet_text($ja, KM_EDITOR_SNIPPET_MAX_DOC, 0) || !km_editor_snippet_text($en, KM_EDITOR_SNIPPET_MAX_DOC, 0)) {
                return $fail("{$where}: ja・en(説明)は " . KM_EDITOR_SNIPPET_MAX_DOC . ' 字まで。');
            }
            $value[$section][] = ['label' => $item['label'], 'body' => $item['body'], 'ja' => $ja, 'en' => $en];
        }
    }

    return ['ok' => true, 'value' => $value, 'error' => '', 'notes' => $notes];
}
/** 取り込んだファイルの中身(文字列)を読み解いて確かめる */
function km_editor_snippets_parse(string $text): array
{
    if (strlen($text) > KM_EDITOR_SNIPPET_MAX_BYTES) {
        return ['ok' => false, 'value' => null, 'error' => 'ファイルが大きすぎます(' . intdiv(KM_EDITOR_SNIPPET_MAX_BYTES, 1024) . 'KB まで)。', 'notes' => []];
    }
    // メモ帳などが付ける BOM は外す
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        $text = substr($text, 3);
    }
    try {
        $data = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return ['ok' => false, 'value' => null, 'error' => 'JSON として読めません(' . $exception->getMessage() . ')。', 'notes' => []];
    }

    return km_editor_snippets_validate($data);
}

/**
 * いま効いているもの(いちばん新しい行)。
 *
 * @return array{custom: bool, data: ?array, updatedAt: ?string, updatedBy: string, counts: array{htmlJa:int, htmlEn:int, css:int, js:int}}
 */
function km_editor_snippets_current(PDO $pdo): array
{
    km_editor_snippets_ensure_table($pdo);
    $row = $pdo->query('SELECT json, html_ja, html_en, js, updated_by, created_at FROM km_editor_snippets ORDER BY id DESC LIMIT 1')->fetch();
    $none = ['custom' => false, 'data' => null, 'updatedAt' => $row ? (string) $row['created_at'] : null, 'updatedBy' => $row ? (string) $row['updated_by'] : '',
        'counts' => ['htmlJa' => 0, 'htmlEn' => 0, 'css' => 0, 'js' => 0]];
    if (!$row || $row['json'] === null) {
        return $none;
    }
    // 保存したときに確かめてあるが、読むときにも確かめる(表を直に触られても、形の違うものを配らない)
    $checked = km_editor_snippets_validate(json_decode((string) $row['json'], true));
    if (!$checked['ok']) {
        error_log('km_editor_snippets_current: stored json is invalid (' . $checked['error'] . ')');

        return $none;
    }

    return ['custom' => true, 'data' => $checked['value'], 'updatedAt' => (string) $row['created_at'], 'updatedBy' => (string) $row['updated_by'],
        // CSS の数は表の列に無い(表を変えない)ので中身から数える
        'counts' => ['htmlJa' => (int) $row['html_ja'], 'htmlEn' => (int) $row['html_en'], 'css' => count($checked['value']['css']), 'js' => (int) $row['js']]];
}

/** 取り込む($value は km_editor_snippets_validate を通したもの)。null なら「同梱に戻す」 */
function km_editor_snippets_store(PDO $pdo, ?array $value, string $by): void
{
    km_editor_snippets_ensure_table($pdo);
    $json = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO km_editor_snippets (json, html_ja, html_en, js, updated_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([
        $json,
        $value === null ? 0 : count($value['html']['ja']),
        $value === null ? 0 : count($value['html']['en']),
        $value === null ? 0 : count($value['js']),
        mb_substr($by, 0, 191),
    ]);
    // 古い行は新しいほうから KM_EDITOR_SNIPPET_KEEP_ROWS 行だけ残す
    $keepFrom = $pdo->query('SELECT id FROM km_editor_snippets ORDER BY id DESC LIMIT 1 OFFSET ' . (KM_EDITOR_SNIPPET_KEEP_ROWS - 1))->fetchColumn();
    if ($keepFrom !== false) {
        $pdo->prepare('DELETE FROM km_editor_snippets WHERE id < ?')->execute([(int) $keepFrom]);
    }
}

/** 同梱の雛形の JSON(文字列)。無ければ null */
function km_editor_snippets_default_json(): ?string
{
    $text = @file_get_contents(__DIR__ . '/editor-snippets.default.json');

    return is_string($text) && $text !== '' ? $text : null;
}
