<?php

declare(strict_types=1);

/**
 * config/app-map.local.php を書き換える。scripts/new-map-release.ps1 から呼ばれる。
 *
 * **引数ではなく標準入力でJSONを受け取る。** アクセスコードを argv に載せると、
 * 同じホストの他プロセスから `ps` で読めてしまう。ハッシュ化もここで行い、
 * 平文のコードはこのプロセスの外へ出さない。
 *
 * 書き込みは lib/map-access.php の km_map_access_save() と同じ流儀
 * (var_export + 一時ファイル + rename)。
 *
 * 入力JSON:
 *   {
 *     "slug": "kosen-main",
 *     "file": "app-map-kosen-main.json",
 *     "revision": 3,
 *     "expiresAt": "2026-11-03T18:00:00+09:00",
 *     "activeEventUuid": null,
 *     "checksum": true,
 *     "code": "KOSEN2026"        // 省略すると既存のハッシュを保つ
 *   }
 */

require_once __DIR__ . '/../src/lib/db.php';
require_once __DIR__ . '/../src/lib/app-map.php';
require_once __DIR__ . '/../src/lib/app-secret.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$raw = stream_get_contents(STDIN);
$input = json_decode((string) $raw, true);
if (!is_array($input)) {
    fail('標準入力をJSONとして読み取れません。');
}

$slug = trim((string) ($input['slug'] ?? ''));
if ($slug === '' || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $slug) !== 1) {
    fail('slug が不正です(英数字と . _ - のみ、32文字まで)。');
}

$file = trim((string) ($input['file'] ?? ''));
if ($file === '' || strpbrk($file, "/\\") !== false || str_contains($file, '..')) {
    fail('file が不正です(ディレクトリ区切りは使えません)。');
}

$revision = $input['revision'] ?? null;
if (!is_int($revision) || $revision < 0) {
    fail('revision は0以上の整数で指定してください。');
}

$expiresAt = trim((string) ($input['expiresAt'] ?? ''));
if (km_app_map_parse_iso($expiresAt) === null) {
    fail('expiresAt を日時として読み取れません(例: 2026-11-03T18:00:00+09:00)。');
}

$activeEventUuid = $input['activeEventUuid'] ?? null;
if ($activeEventUuid !== null && !is_string($activeEventUuid)) {
    fail('activeEventUuid は文字列か null で指定してください。');
}
if (is_string($activeEventUuid) && trim($activeEventUuid) === '') {
    $activeEventUuid = null;
}

$configPath = __DIR__ . '/../src/config/app-map.local.php';
$current = is_file($configPath) ? require $configPath : [];
if (!is_array($current)) {
    $current = [];
}
$storageDir = is_string($current['storageDir'] ?? null) ? $current['storageDir'] : '';

// 置いた地図が本当に読めるかを、設定を書く前に確かめる。
// 壊れたエクスポートをそのまま配信設定に載せない。
$path = km_app_map_storage_path(['storageDir' => $storageDir], $file);
if ($path === null) {
    fail("地図ファイルが見つかりません: {$file}(置き場: " . km_app_map_storage_dir(['storageDir' => $storageDir]) . ')');
}
$snapshot = km_app_map_decode_snapshot((string) file_get_contents($path));
if ($snapshot === null) {
    fail("地図ファイルを MapDataSnapshot として読み取れません: {$file}");
}

$maps = is_array($current['maps'] ?? null) ? $current['maps'] : [];
$maps[$slug] = [
    'file' => $file,
    'revision' => $revision,
    'expiresAt' => $expiresAt,
    'activeEventUuid' => $activeEventUuid,
    'checksum' => ($input['checksum'] ?? true) !== false,
];

$codes = is_array($current['codes'] ?? null) ? $current['codes'] : [];
$code = $input['code'] ?? null;
if (is_string($code) && $code !== '') {
    /*
     * **正規化してから hash と lookup を作る**(admin/map-publish.php の km_app_map_add_code と同じ)。
     * api/app-map.php は受け取ったコードを km_app_map_normalize_code() してから照合する。
     * 以前は入力のまま hash にしていたので、小文字やハイフン入りのコードは通らなかった。
     */
    $code = km_app_map_normalize_code($code);
    if ($code === '') {
        fail('code が空です(空白・ハイフン・下線だけのコードは使えません)。');
    }
    /*
     * **鍵つきの印(lookup)を必ず付ける。** 付けないと、そのコードの照合は
     * bcrypt と外れの回数制限に回る。鍵(DB の km_app_secrets)を読めないなら書かない ——
     * 設定を書く前に止めるので、途中まで書き換わった設定は残らない。
     */
    try {
        $lookup = km_app_map_code_lookup(km_app_secret(km_db(), KM_APP_MAP_CODE_SECRET), $code);
    } catch (Throwable $exception) {
        fail('アクセスコードの鍵を DB から読めませんでした: ' . $exception->getMessage());
    }
    // この slug のコードは1つに保つ。付け替えたのに古いコードが通り続ける事故を防ぐ。
    $codes = array_values(array_filter(
        $codes,
        static fn ($entry) => !(is_array($entry) && ($entry['slug'] ?? null) === $slug)
    ));
    /*
     * `code`(平文)も併せて置く。**照合に使うのは今までどおり `hash` だけ。**
     *
     * ## なぜ平文を置くようになったのか
     *
     * 以前は hash だけだったので、**コードを知っているのはリリースを実行した人だけ**
     * だった。会期の当日に「来場者へ伝えるコードが分からない」となり、
     * 配り直す(= 全員のアプリで入れ直す)しか手が無くなる。
     *
     * ## それでよいと判断した理由
     *
     * このコードは**来場者全員に配る前提の共有情報**で、QR にも印刷物にも載る。
     * 秘密ではない —— 地図そのものにも秘匿情報は入れない方針で、
     * 教職員氏名は配信の時点で取り除いている。
     *
     * ## それでも守ること
     *
     * - **照合は `hash` で行う。** `code` を見て通す経路は作らない
     * - `code` は**管理画面にしか出さない**(api/app-map.php は触れない)
     * - 画面では既定で伏せる(「表示」を押したときだけ出る)
     */
    $codes[] = [
        'slug' => $slug,
        'hash' => password_hash($code, PASSWORD_DEFAULT),
        'code' => $code,
        'lookup' => $lookup['lookup'],
        'lookupKey' => $lookup['keyId'],
    ];
} else {
    $has = false;
    foreach ($codes as $entry) {
        if (is_array($entry) && ($entry['slug'] ?? null) === $slug && is_string($entry['hash'] ?? null)) {
            $has = true;
        }
    }
    if (!$has) {
        fail("slug '{$slug}' のアクセスコードがまだありません。code を指定してください。");
    }
}

$config = [
    'storageDir' => $storageDir,
    'maps' => $maps,
    'codes' => $codes,
];

$php = "<?php\n\ndeclare(strict_types=1);\n\n"
    . "// scripts/new-map-release.ps1 が書き換える。手動編集も可能だが、\n"
    . "// 'hash' は password_hash() の出力であること(平文を置かない)。\n"
    . "// このファイルは配備対象外(deploy-to-host.ps1 の除外一覧)。ホスト側が正本。\n\n"
    . 'return ' . var_export($config, true) . ";\n";

$temporary = $configPath . '.tmp';
if (@file_put_contents($temporary, $php, LOCK_EX) === false) {
    fail('設定ファイルへ書き込めませんでした: ' . $configPath);
}
@chmod($temporary, 0600);
if (!@rename($temporary, $configPath)) {
    @unlink($temporary);
    fail('設定ファイルを置き換えられませんでした: ' . $configPath);
}

// 秘密は返さない。
echo json_encode([
    'slug' => $slug,
    'file' => $file,
    'revision' => $revision,
    'expiresAt' => $expiresAt,
    'activeEventUuid' => $activeEventUuid,
    'nodes' => is_array($snapshot->nodes ?? null) ? count($snapshot->nodes) : 0,
    'lines' => is_array($snapshot->lines ?? null) ? count($snapshot->lines) : 0,
    'events' => is_array($snapshot->events ?? null) ? count($snapshot->events) : 0,
    'configPath' => $configPath,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
