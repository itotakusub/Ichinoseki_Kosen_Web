<?php

declare(strict_types=1);

/**
 * HTML 展開サイト(2026-11-05、利用者の指示)。
 *   - 「ZIP または単体の HTML をドラッグアンドドロップして、実際の URL で表示できるようにする」(同日「CSS, JS にも対応」)
 *   - 「保存場所はローカル。できなかったらサーバーの一時フォルダ」
 *   - 「ログインしていたらサーバーに保存。版 10 まで、11 以降は上書き」
 * 決めたこと(同日の質問): 見せる側は別のサブドメイン・サーバーのものは本人だけ・版は古いものから消して 10 を残す・
 * 一時フォルダは 24 時間で、上げたブラウザだけ。
 *
 * ## 2 つのオリジン
 *
 *   道具のページ  https://<本体>/html.php            落とす・一覧・版・サインイン(今の Logto をそのまま使う)
 *   見せる側      https://html.<本体>/ (html-view.php) 上げたものだけを返す。**本体のサインインの Cookie を持たない**
 *
 * 上げた HTML のスクリプトは自由に動く。本体の上で動かすと、来場者として地図やアカウントの画面を操作できてしまう。
 * 見せる側が持つのは「見るための印」(__Host-KMHV)だけで、それで見られるのは**その人自身が上げたもの**だけ。
 *
 * ## 置き場所は 3 つ
 *
 *   1. ローカル … 見せる側の bridge.html(本体のページの見えない iframe)が IndexedDB にしまい、
 *                 Service Worker(/sw.js、範囲 /l/)が /l/<番号>/… で返す。サーバーには何も来ない(src/html-host/)
 *   2. 一時     … ローカルが使えないとき。uploads/html-temp/<番号>/。24 時間で消す。開けるのは上げたブラウザだけ
 *                 (上げたときに渡す鍵を、道具のページが localStorage に覚える。DB には鍵のハッシュだけ)
 *   3. サーバー … サインインした人。uploads/html-sites/<番号>/。版を 10 まで残し、11 版目で一番古い版を消す
 *
 * ## ファイルの置き方
 *
 * **上げた名前はパスに使わない**(lib/uploads.php と同じ考え。.php などを実行させる余地を作らない)。
 * 中身は sha256 の名前で `blobs/` に置き、版ごとの目録(v<版>.json / 一時は manifest.json)が「パス → ハッシュ」を持つ。
 * 版どうしで同じ中身は 1 つだけ置く(CSS を 1 つ直しただけの版は、その CSS の分しか増えない)。
 *
 * ## 見るための印
 *
 * 1. 本体が使い捨ての札を作る(ランダム 32 バイト・60 秒・1 回限り。DB にはハッシュだけ)。行き先は本体が決める
 * 2. ブラウザが https://html.<本体>/auth?t=<札> を開く(URL に載るのは札だけ。個人の情報は載せない)。
 *    **本体から開いたときだけ受ける**(Sec-Fetch-Site / Referer)。ほかのサイトが自分の札で人を誘い込み、
 *    学校のドメインで偽のページを見せる使い方を塞ぐ
 * 3. 見せる側が札を使い切り、__Host-KMHV(12 時間)を置いて行き先へ 303
 * 4. 見せる側は、印が持ち主と合うときだけ返す。合わなければ 404(有無を教えない)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/zip-read.php';

/** 1 回に上げる zip の大きさ(道具のページがまとめた zip。nginx の client_max_body_size はこれ + 1MB) */
const KM_HTML_MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
/** 展開した中身の合計 */
const KM_HTML_MAX_TOTAL_BYTES = 40 * 1024 * 1024;
const KM_HTML_MAX_FILES = 1000;
/** サーバーのサイトが残す版の数。11 版目を保存すると一番古い版を消す(利用者の指示) */
const KM_HTML_MAX_VERSIONS = 10;
const KM_HTML_MAX_SITES_PER_OWNER = 10;
const KM_HTML_MAX_OWNER_BYTES = 300 * 1024 * 1024;
/** 一時フォルダに置く時間(利用者の指示「24 時間・上げたブラウザだけ」) */
const KM_HTML_TEMP_TTL = 86400;
/** 一時フォルダ全体の上限。超えたら新しい一時を断る */
const KM_HTML_TEMP_POOL_BYTES = 1024 * 1024 * 1024;
const KM_HTML_TICKET_TTL = 60;
const KM_HTML_VIEWER_TTL = 43200;
const KM_HTML_VIEWER_COOKIE = '__Host-KMHV';
const KM_HTML_NAME_MAX = 100;
const KM_HTML_PATH_MAX = 255;
const KM_HTML_DEPTH_MAX = 16;
/** 一時の印をいくつまで 1 つのブラウザに覚えるか */
const KM_HTML_VIEWER_TEMPS_MAX = 30;

/*
 * 使い始める前の関門(2026-11-05、利用者の指示「展開する前に、利用規約と I'm not a robot を表示」)。
 * 道具のページで規約(第8条の2)に同意し、reCAPTCHA を通すと、本体のセッションに「いつまで・どの版の規約に」を置く。
 * サーバーに置く口(temp・temp_patch・save)はこれが無ければ断る。このブラウザの中に置くときは、道具のページの JS が同じ印を見る。
 * 規約を改めたら(KM_LEGAL_UPDATED が変わったら)同意し直してもらう
 */
const KM_HTML_GATE_TTL = 7200;
const KM_HTML_GATE_SESSION_KEY = 'km_html_gate';

/**
 * 返すときの型(拡張子 → Content-Type)。**ここに無いものは application/octet-stream で落とさせる。**
 * 道具のページ(ローカルの Service Worker)にも同じ表を渡す(html.php の data-km-types)。
 * HTML・CSS・JS に charset は付けない —— Shift_JIS の HTML の <meta charset> を、ヘッダーで上書きしてしまうため
 */
const KM_HTML_TYPES = [
    'html' => 'text/html',
    'htm' => 'text/html',
    'css' => 'text/css',
    'js' => 'text/javascript',
    'mjs' => 'text/javascript',
    'json' => 'application/json',
    'map' => 'application/json',
    'webmanifest' => 'application/manifest+json',
    'xml' => 'application/xml',
    'txt' => 'text/plain',
    'md' => 'text/plain',
    'csv' => 'text/csv',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'bmp' => 'image/bmp',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
    'woff' => 'font/woff',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'm4a' => 'audio/mp4',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'wasm' => 'application/wasm',
    'pdf' => 'application/pdf',
    'glb' => 'model/gltf-binary',
    'gltf' => 'model/gltf+json',
];

/**
 * 上げたものを見せるときの CSP。上げた HTML が動く広さにする(インラインのスクリプト・外の CDN・eval)。
 * 守りは「別のオリジン」と「本人しか見られない」で取る。**枠に入れさせない**(クリックジャッキング)
 */
const KM_HTML_CONTENT_CSP = "default-src * data: blob: 'unsafe-inline' 'unsafe-eval'; frame-ancestors 'none'";

// ---------------------------------------------------------------------------
// 純粋関数(scripts/check.php の html-host が試す)
// ---------------------------------------------------------------------------

/** 拡張子から型。無ければ application/octet-stream */
function km_html_type(string $path): string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return KM_HTML_TYPES[$extension] ?? 'application/octet-stream';
}

function km_html_is_html(string $path): bool
{
    return km_html_type($path) === 'text/html';
}

/**
 * 相対パスを確かめて整える(`\` は `/` に)。外へ出るもの・使えない文字・長すぎるものは例外
 */
function km_html_clean_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $shown = mb_substr($path, 0, 80);
    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
        throw new InvalidArgumentException('使えないファイルの場所です(' . $shown . ')。');
    }
    if (!mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        throw new InvalidArgumentException('ファイル名に使えない文字があります(' . $shown . ')。');
    }
    if (mb_strlen($path) > KM_HTML_PATH_MAX) {
        throw new InvalidArgumentException('ファイルの場所が長すぎます(' . KM_HTML_PATH_MAX . ' 字まで。' . $shown . '…)。');
    }
    $segments = explode('/', $path);
    if (count($segments) > KM_HTML_DEPTH_MAX) {
        throw new InvalidArgumentException('フォルダが深すぎます(' . KM_HTML_DEPTH_MAX . ' 段まで。' . $shown . ')。');
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('使えないファイルの場所です(' . $shown . ')。');
        }
    }

    return $path;
}

/** 飛ばすもの(隠しファイル・macOS の付録・Windows の縮小画像の控え) */
function km_html_ignored_path(string $path): bool
{
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '__MACOSX' || str_starts_with($segment, '.')) {
            return true;
        }
    }
    $base = strtolower(basename($path));

    return $base === 'thumbs.db' || $base === 'desktop.ini';
}

/**
 * 上げたファイルの組を整える。
 *   - パスを確かめる・飛ばすものを除く・大文字小文字だけ違う同じ名前は断る
 *   - 型の表(KM_HTML_TYPES)に無いものは除く(2026-11-05)
 *   - 全部が 1 つのフォルダの中なら、そのフォルダを外す(フォルダごと zip にしたもの)。何段でも
 *   - 数と合計の上限
 *
 * @param list<array{name:string, bytes:string}> $entries
 * @return array<string, string> パス → 中身(パスの順)
 */
function km_html_normalize_files(array $entries): array
{
    $files = [];
    $lower = [];
    $total = 0;
    foreach ($entries as $entry) {
        $name = (string) $entry['name'];
        if (km_html_ignored_path($name)) {
            continue;
        }
        $path = km_html_clean_path($name);
        // 型の表に無いもの(exe・docx・中の zip・7z など)は使わない(2026-11-05。道具のページの JS も同じく除く)
        if (km_html_type($path) === 'application/octet-stream') {
            continue;
        }
        $key = mb_strtolower($path);
        if (isset($lower[$key])) {
            throw new InvalidArgumentException('大文字と小文字だけが違う同じ名前があります(' . mb_substr($path, 0, 80) . ')。');
        }
        $lower[$key] = true;
        $total += strlen((string) $entry['bytes']);
        if ($total > KM_HTML_MAX_TOTAL_BYTES) {
            throw new InvalidArgumentException('中身の合計が大きすぎます(' . intdiv(KM_HTML_MAX_TOTAL_BYTES, 1024 * 1024) . 'MB まで)。');
        }
        $files[$path] = (string) $entry['bytes'];
        if (count($files) > KM_HTML_MAX_FILES) {
            throw new InvalidArgumentException('ファイルは ' . KM_HTML_MAX_FILES . ' 個までにしてください。');
        }
    }
    if ($files === []) {
        throw new InvalidArgumentException('使えるファイルがありませんでした。');
    }

    // 全部が同じ 1 つのフォルダの中なら外す(「site/index.html」だけの zip → 「index.html」)
    while (true) {
        $first = null;
        foreach (array_keys($files) as $path) {
            $slash = strpos($path, '/');
            if ($slash === false) {
                $first = null;
                break;
            }
            $top = substr($path, 0, $slash);
            if ($first === null) {
                $first = $top;
            } elseif ($first !== $top) {
                $first = null;
                break;
            }
        }
        if ($first === null) {
            break;
        }
        $stripped = [];
        foreach ($files as $path => $bytes) {
            $stripped[substr($path, strlen($first) + 1)] = $bytes;
        }
        $files = $stripped;
    }
    ksort($files, SORT_STRING);

    return $files;
}

/**
 * 入口(最初に開く HTML)を決める。決められなければ null(HTML が 2 つ以上で index が無い → 道具のページで選ぶ)。
 *   1. 求めたもの(HTML で、中にあるもの)
 *   2. いちばん上の index.html / index.htm(大文字小文字は問わない)
 *   3. HTML が 1 つだけなら、それ
 *
 * @param list<string> $paths
 */
function km_html_pick_entry(array $paths, string $requested = ''): ?string
{
    if ($requested !== '' && in_array($requested, $paths, true) && km_html_is_html($requested)) {
        return $requested;
    }
    foreach (['index.html', 'index.htm'] as $index) {
        foreach ($paths as $path) {
            if (strtolower($path) === $index) {
                return $path;
            }
        }
    }
    $htmls = array_values(array_filter($paths, 'km_html_is_html'));

    return count($htmls) === 1 ? $htmls[0] : null;
}

/** サイトの名前を整える(空なら代わりの名前) */
function km_html_clean_name(string $name, string $fallback = 'HTML'): string
{
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
    if ($name === '' || !mb_check_encoding($name, 'UTF-8')) {
        $name = $fallback;
    }

    return mb_substr($name, 0, KM_HTML_NAME_MAX);
}

function km_html_id_valid(string $id): bool
{
    return preg_match('/^[0-9a-f]{24}$/', $id) === 1;
}

function km_html_new_id(): string
{
    return bin2hex(random_bytes(12));
}

/**
 * 残す版と消す版を分ける(新しい順に KM_HTML_MAX_VERSIONS を残す)。**純粋関数**
 *
 * @param list<int> $versions
 * @return array{keep: list<int>, remove: list<int>}
 */
function km_html_versions_split(array $versions): array
{
    $sorted = array_values(array_unique(array_map('intval', $versions)));
    rsort($sorted);

    return [
        'keep' => array_slice($sorted, 0, KM_HTML_MAX_VERSIONS),
        'remove' => array_slice($sorted, KM_HTML_MAX_VERSIONS),
    ];
}

/**
 * 見せる側のパスを分ける。**純粋関数**
 *   /s/<番号>/…           サーバーのもの(いちばん新しい版)
 *   /s/<番号>/v<版>/…     その版
 *   /t/<番号>/…           一時のもの
 *   /l/<番号>/…           ローカル(Service Worker が答える。サーバーに来たら「このブラウザにありません」)
 * 中のパスは URL のデコード後。`<番号>` の後ろにスラッシュが無ければ slash=false(付けて移る)
 *
 * @return array{kind:string, id:string, version:?int, path:string, slash:bool}|null
 */
function km_html_parse_view_path(string $uriPath): ?array
{
    if (preg_match('#^/([stl])/([0-9a-f]{24})(/.*)?$#', $uriPath, $m) !== 1) {
        return null;
    }
    $kind = ['s' => 'site', 't' => 'temp', 'l' => 'local'][$m[1]];
    $rest = $m[3] ?? '';
    if ($rest === '') {
        return ['kind' => $kind, 'id' => $m[2], 'version' => null, 'path' => '', 'slash' => false];
    }
    $rest = substr($rest, 1);
    $version = null;
    if ($kind === 'site' && preg_match('#^v([1-9][0-9]{0,6})(/.*)?$#', $rest, $v) === 1) {
        $version = (int) $v[1];
        if (!isset($v[2]) || $v[2] === '') {
            return ['kind' => $kind, 'id' => $m[2], 'version' => $version, 'path' => '', 'slash' => false];
        }
        $rest = substr($v[2], 1);
    }
    $segments = $rest === '' ? [] : explode('/', $rest);
    $decoded = [];
    foreach ($segments as $index => $segment) {
        $segment = rawurldecode($segment);
        if ($segment === '' && $index === count($segments) - 1) {
            $decoded[] = '';   // 終わりのスラッシュ(フォルダの index.html)
            continue;
        }
        if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '/') || str_contains($segment, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
            return null;
        }
        $decoded[] = $segment;
    }

    return ['kind' => $kind, 'id' => $m[2], 'version' => $version, 'path' => implode('/', $decoded), 'slash' => true];
}

/**
 * 目録から返すファイルを選ぶ。空・フォルダなら index.html。**純粋関数**
 *
 * @param array<string, array{h:string, s:int}> $files
 * @return array{redirect:?string, path:?string}
 */
function km_html_resolve(array $files, string $entry, string $path): array
{
    if ($path === '') {
        if (isset($files[$entry]) && $entry !== 'index.html') {
            return ['redirect' => $entry, 'path' => null];
        }
        $path = 'index.html';
    } elseif (str_ends_with($path, '/')) {
        $path .= 'index.html';
    }

    return ['redirect' => null, 'path' => isset($files[$path]) ? $path : null];
}

/** パスを URL に(段ごとにエンコード) */
function km_html_encode_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * /auth を受けてよいか(本体のページから開いたときだけ)。**純粋関数**
 * Sec-Fetch-Site が来ていれば same-site / same-origin だけ(貼り付けた URL の none・ほかのサイトの cross-site は断る)。
 * Referer が来ていれば本体のオリジンだけ。どちらも無ければ断る
 *
 * @param array<string, mixed> $server
 */
function km_html_auth_request_allowed(array $server, string $appOrigin): bool
{
    $site = strtolower((string) ($server['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '' && $site !== 'same-site' && $site !== 'same-origin') {
        return false;
    }
    $referer = (string) ($server['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $parts = parse_url($referer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        if ($origin !== strtolower($appOrigin)) {
            return false;
        }
    }

    return $site !== '' || $referer !== '';
}

/** バイト数の表示 */
/**
 * セッションに置いた関門の印が、今も効いているか(純粋関数)。
 *
 * @param array<mixed> $session $_SESSION
 */
function km_html_gate_passed(array $session, string $termsVersion, ?int $now = null): bool
{
    $record = $session[KM_HTML_GATE_SESSION_KEY] ?? null;

    return is_array($record)
        && ($record['terms'] ?? null) === $termsVersion
        && is_int($record['until'] ?? null)
        && $record['until'] > ($now ?? time());
}

/** 関門の印(同意した規約の版と期限) @return array{terms: string, until: int} */
function km_html_gate_record(string $termsVersion, ?int $now = null): array
{
    return ['terms' => $termsVersion, 'until' => ($now ?? time()) + KM_HTML_GATE_TTL];
}

/** 印の期限(無い・切れていれば 0) @param array<mixed> $session */
function km_html_gate_until(array $session, string $termsVersion, ?int $now = null): int
{
    return km_html_gate_passed($session, $termsVersion, $now) ? (int) $session[KM_HTML_GATE_SESSION_KEY]['until'] : 0;
}
function km_html_size_label(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return (string) round($bytes / 1024) . ' KB';
    }

    return $bytes . ' B';
}

// ---------------------------------------------------------------------------
// ファイル
// ---------------------------------------------------------------------------

/** サーバーのサイトの置き場(uploads/html-sites)。夜の控えに入る */
function km_html_sites_root(): string
{
    return __DIR__ . '/../uploads/html-sites';
}

/** 一時の置き場(uploads/html-temp)。**夜の控えには入れない**(scripts/host-backup.sh の除外) */
function km_html_temp_root(): string
{
    return __DIR__ . '/../uploads/html-temp';
}

function km_html_ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('置き場を作れませんでした。');
    }
}

/**
 * 中身を blobs/<sha256> に書き、目録の「パス → {h, s}」を返す。同じ中身がもう在れば書かない
 *
 * @param array<string, string> $files
 * @return array<string, array{h:string, s:int}>
 */
function km_html_write_blobs(string $dir, array $files): array
{
    $blobs = $dir . '/blobs';
    km_html_ensure_dir($blobs);
    $manifest = [];
    foreach ($files as $path => $bytes) {
        $hash = hash('sha256', $bytes);
        $target = $blobs . '/' . $hash;
        if (!is_file($target)) {
            $temp = $target . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($temp, $target)) {
                @unlink($temp);
                throw new RuntimeException('ファイルを書けませんでした(空きが足りないかもしれません)。');
            }
        }
        $manifest[$path] = ['h' => $hash, 's' => strlen($bytes)];
    }

    return $manifest;
}

/** blobs にまだ無い中身の大きさ(上限の見積もりに使う) */
function km_html_new_bytes(string $dir, array $files): int
{
    $bytes = 0;
    $seen = [];
    foreach ($files as $content) {
        $hash = hash('sha256', $content);
        if (isset($seen[$hash]) || is_file($dir . '/blobs/' . $hash)) {
            continue;
        }
        $seen[$hash] = true;
        $bytes += strlen($content);
    }

    return $bytes;
}

/** 目録を書く(一時の名前に書いてから入れ替える) */
function km_html_write_manifest(string $file, string $entry, array $manifest): void
{
    $json = json_encode(['entry' => $entry, 'files' => $manifest], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $temp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (!is_string($json) || @file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !@rename($temp, $file)) {
        @unlink($temp);
        throw new RuntimeException('目録を書けませんでした。');
    }
}

/** @return array{entry:string, files:array<string, array{h:string, s:int}>}|null */
function km_html_read_manifest(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !is_string($data['entry'] ?? null) || !is_array($data['files'] ?? null)) {
        return null;
    }

    return ['entry' => $data['entry'], 'files' => $data['files']];
}

/** どの目録からも指されていない中身を消す。残った中身の合計を返す */
function km_html_prune_blobs(string $dir): int
{
    $used = [];
    foreach (glob($dir . '/{v*.json,manifest.json}', GLOB_BRACE) ?: [] as $file) {
        foreach ((km_html_read_manifest($file)['files'] ?? []) as $item) {
            $used[(string) ($item['h'] ?? '')] = true;
        }
    }
    $total = 0;
    foreach (glob($dir . '/blobs/*') ?: [] as $blob) {
        $name = basename($blob);
        if (!isset($used[$name])) {
            @unlink($blob);
            continue;
        }
        $total += (int) filesize($blob);
    }

    return $total;
}

/** フォルダごと消す(置き場の中だけ) */
function km_html_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/**
 * 上げた zip(道具のページがまとめたもの、または落とした zip そのもの)を読む。
 *
 * @return array<string, string> パス → 中身
 */
function km_html_read_upload(string $zipPath): array
{
    $size = is_file($zipPath) ? (int) filesize($zipPath) : 0;
    if ($size <= 0) {
        throw new InvalidArgumentException('ファイルを受け取れませんでした。');
    }
    if ($size > KM_HTML_MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('大きすぎます(' . intdiv(KM_HTML_MAX_UPLOAD_BYTES, 1024 * 1024) . 'MB まで)。');
    }

    return km_html_normalize_files(km_zip_read_all($zipPath, KM_HTML_MAX_FILES, KM_HTML_MAX_TOTAL_BYTES, KM_HTML_MAX_TOTAL_BYTES, true));
}

// ---------------------------------------------------------------------------
// 表
// ---------------------------------------------------------------------------

function km_html_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_html_sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(24) NOT NULL,
            owner_sub VARCHAR(191) NOT NULL,
            owner_name VARCHAR(255) NOT NULL DEFAULT '',
            name VARCHAR(100) NOT NULL,
            next_version INT NOT NULL DEFAULT 1,
            bytes BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY (public_id),
            INDEX (owner_sub)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_html_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_id INT NOT NULL,
            version INT NOT NULL,
            entry VARCHAR(255) NOT NULL,
            file_count INT NOT NULL,
            bytes BIGINT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY (site_id, version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_html_temp (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(24) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            name VARCHAR(100) NOT NULL,
            entry VARCHAR(255) NOT NULL,
            file_count INT NOT NULL,
            bytes BIGINT NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY (public_id),
            INDEX (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_html_tickets (
            hash CHAR(64) NOT NULL PRIMARY KEY,
            kind VARCHAR(8) NOT NULL,
            subject VARCHAR(191) NOT NULL,
            to_path VARCHAR(400) NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_html_viewers (
            hash CHAR(64) NOT NULL PRIMARY KEY,
            owner_sub VARCHAR(191) NULL,
            temps TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

function km_html_now(int $offset = 0): string
{
    return date('Y-m-d H:i:s', time() + $offset);
}

// ---------------------------------------------------------------------------
// サーバーに保存(サインインした人)
// ---------------------------------------------------------------------------

/** @return array<string, mixed> */
function km_html_site_row(array $row): array
{
    return [
        'id' => (string) $row['public_id'],
        'dbId' => (int) $row['id'],
        'ownerSub' => (string) $row['owner_sub'],
        'ownerName' => (string) $row['owner_name'],
        'name' => (string) $row['name'],
        'nextVersion' => (int) $row['next_version'],
        'bytes' => (int) $row['bytes'],
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

function km_html_site_find(PDO $pdo, string $publicId): ?array
{
    if (!km_html_id_valid($publicId)) {
        return null;
    }
    km_html_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_html_sites WHERE public_id = ?');
    $statement->execute([$publicId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? km_html_site_row($row) : null;
}

/** @return list<array<string, mixed>> 版(新しい順) */
function km_html_site_versions(PDO $pdo, int $siteDbId): array
{
    $statement = $pdo->prepare('SELECT version, entry, file_count, bytes, created_at FROM km_html_versions WHERE site_id = ? ORDER BY version DESC');
    $statement->execute([$siteDbId]);

    return array_map(static fn (array $r): array => [
        'version' => (int) $r['version'],
        'entry' => (string) $r['entry'],
        'files' => (int) $r['file_count'],
        'bytes' => (int) $r['bytes'],
        'createdAt' => (string) $r['created_at'],
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

/** @return list<array<string, mixed>> その人のサイト(版つき。新しく直した順) */
function km_html_sites_of(PDO $pdo, string $ownerSub): array
{
    km_html_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_html_sites WHERE owner_sub = ? ORDER BY updated_at DESC, id DESC');
    $statement->execute([$ownerSub]);
    $sites = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $site = km_html_site_row($row);
        $site['versions'] = km_html_site_versions($pdo, $site['dbId']);
        $sites[] = $site;
    }

    return $sites;
}

function km_html_site_dir(string $publicId): string
{
    return km_html_sites_root() . '/' . $publicId;
}

/** その版の目録(無ければ null)。版を省けば一番新しい版 */
function km_html_site_manifest(PDO $pdo, array $site, ?int $version = null): ?array
{
    if ($version === null) {
        $statement = $pdo->prepare('SELECT MAX(version) FROM km_html_versions WHERE site_id = ?');
        $statement->execute([$site['dbId']]);
        $version = (int) $statement->fetchColumn();
        if ($version <= 0) {
            return null;
        }
    }
    $manifest = km_html_read_manifest(km_html_site_dir($site['id']) . '/v' . $version . '.json');

    return $manifest === null ? null : $manifest + ['version' => $version];
}

/** その人が使っている合計 */
function km_html_owner_bytes(PDO $pdo, string $ownerSub): int
{
    km_html_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT COALESCE(SUM(bytes), 0) FROM km_html_sites WHERE owner_sub = ?');
    $statement->execute([$ownerSub]);

    return (int) $statement->fetchColumn();
}

/**
 * 新しい版を保存する。
 *   - $publicId が null なら新しいサイト(1 人 KM_HTML_MAX_SITES_PER_OWNER まで)
 *   - $patch なら、一番新しい版に $files を重ねる(CSS・JS だけを落としたとき。同じ名前は差し替える)
 *   - 書いて目録を置いてから行を足し、**そのあとで** 11 版目より古い版を消す
 *
 * @param array<string, string> $files
 * @return array{site: array<string, mixed>, version:int, entry:string, removed: list<int>}
 */
function km_html_site_save(PDO $pdo, string $ownerSub, string $ownerName, ?string $publicId, string $name, array $files, string $requestedEntry = '', bool $patch = false): array
{
    km_html_ensure_tables($pdo);
    if ($ownerSub === '') {
        throw new InvalidArgumentException('サインインしてください。');
    }
    $site = null;
    if ($publicId !== null) {
        $site = km_html_site_find($pdo, $publicId);
        if ($site === null || $site['ownerSub'] !== $ownerSub) {
            throw new InvalidArgumentException('そのサイトは見つかりません。');
        }
    } else {
        if ($patch) {
            throw new InvalidArgumentException('足す先のサイトを選んでください。');
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM km_html_sites WHERE owner_sub = ?');
        $count->execute([$ownerSub]);
        if ((int) $count->fetchColumn() >= KM_HTML_MAX_SITES_PER_OWNER) {
            throw new InvalidArgumentException('サーバーに置けるサイトは ' . KM_HTML_MAX_SITES_PER_OWNER . ' 個までです。使わないサイトを消してください。');
        }
    }

    $base = [];
    $baseEntry = '';
    if ($patch && $site !== null) {
        $latest = km_html_site_manifest($pdo, $site);
        if ($latest === null) {
            throw new InvalidArgumentException('足す先のサイトに版がありません。');
        }
        $base = $latest['files'];
        $baseEntry = $latest['entry'];
    }
    $paths = array_values(array_unique(array_merge(array_keys($base), array_keys($files))));
    if (count($paths) > KM_HTML_MAX_FILES) {
        throw new InvalidArgumentException('ファイルは ' . KM_HTML_MAX_FILES . ' 個までにしてください。');
    }
    $entry = km_html_pick_entry($paths, $requestedEntry !== '' ? $requestedEntry : $baseEntry);
    if ($entry === null) {
        throw new InvalidArgumentException(array_filter($paths, 'km_html_is_html') === []
            ? 'HTML がありません。HTML も一緒に落としてください。'
            : '最初に開く HTML を選んでください。');
    }

    $isNew = $site === null;
    $newId = $site['id'] ?? km_html_new_id();
    $dir = km_html_site_dir($newId);
    $owned = km_html_owner_bytes($pdo, $ownerSub);
    if ($owned + km_html_new_bytes($dir, $files) > KM_HTML_MAX_OWNER_BYTES) {
        throw new InvalidArgumentException('サーバーに置ける合計(' . intdiv(KM_HTML_MAX_OWNER_BYTES, 1024 * 1024) . 'MB)を超えます。古い版やサイトを消してください。');
    }

    km_html_ensure_dir($dir);
    $manifest = array_merge($base, km_html_write_blobs($dir, $files));
    ksort($manifest, SORT_STRING);
    $bytes = array_sum(array_map(static fn (array $item): int => (int) $item['s'], $manifest));
    $name = km_html_clean_name($name, $site['name'] ?? 'HTML');

    $pdo->beginTransaction();
    try {
        if ($site === null) {
            $pdo->prepare('INSERT INTO km_html_sites (public_id, owner_sub, owner_name, name, next_version, bytes, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())')
                ->execute([$newId, $ownerSub, mb_substr($ownerName, 0, 255), $name]);
            $site = km_html_site_find($pdo, $newId);
            if ($site === null) {
                throw new RuntimeException('サイトを作れませんでした。');
            }
        }
        // 版の番号は行を締めて取る(同時に 2 つ保存しても重ならない)
        $lock = $pdo->prepare('SELECT next_version FROM km_html_sites WHERE id = ? FOR UPDATE');
        $lock->execute([$site['dbId']]);
        $version = (int) $lock->fetchColumn();
        km_html_write_manifest($dir . '/v' . $version . '.json', $entry, $manifest);
        $pdo->prepare('INSERT INTO km_html_versions (site_id, version, entry, file_count, bytes, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$site['dbId'], $version, $entry, count($manifest), $bytes]);
        $pdo->prepare('UPDATE km_html_sites SET next_version = ?, name = ?, owner_name = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$version + 1, $name, mb_substr($ownerName, 0, 255), $site['dbId']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        if ($isNew) {
            km_html_remove_dir($dir);
        } else {
            if (isset($version)) {
                @unlink($dir . '/v' . $version . '.json');
            }
            km_html_prune_blobs($dir);
        }
        throw $exception;
    }

    // 11 版目からは一番古い版を消す(利用者の指示)。書けて行が入ったあとで消す
    $removed = km_html_site_trim($pdo, $site);

    return ['site' => km_html_site_find($pdo, $site['id']) ?? $site, 'version' => $version, 'entry' => $entry, 'removed' => $removed];
}

/** 古い版を消して KM_HTML_MAX_VERSIONS を残し、大きさを数え直す。消した版を返す */
function km_html_site_trim(PDO $pdo, array $site): array
{
    $statement = $pdo->prepare('SELECT version FROM km_html_versions WHERE site_id = ?');
    $statement->execute([$site['dbId']]);
    $split = km_html_versions_split(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    $dir = km_html_site_dir($site['id']);
    foreach ($split['remove'] as $version) {
        $pdo->prepare('DELETE FROM km_html_versions WHERE site_id = ? AND version = ?')->execute([$site['dbId'], $version]);
        @unlink($dir . '/v' . $version . '.json');
    }
    $pdo->prepare('UPDATE km_html_sites SET bytes = ? WHERE id = ?')->execute([km_html_prune_blobs($dir), $site['dbId']]);

    return $split['remove'];
}

/** 版を 1 つ消す。最後の 1 つならサイトごと消す */
function km_html_version_delete(PDO $pdo, array $site, int $version): void
{
    $statement = $pdo->prepare('DELETE FROM km_html_versions WHERE site_id = ? AND version = ?');
    $statement->execute([$site['dbId'], $version]);
    if ($statement->rowCount() === 0) {
        throw new InvalidArgumentException('その版は見つかりません。');
    }
    $dir = km_html_site_dir($site['id']);
    @unlink($dir . '/v' . $version . '.json');
    if (km_html_site_versions($pdo, $site['dbId']) === []) {
        km_html_site_delete($pdo, $site);
        return;
    }
    $pdo->prepare('UPDATE km_html_sites SET bytes = ?, updated_at = NOW() WHERE id = ?')->execute([km_html_prune_blobs($dir), $site['dbId']]);
}

/** サイトを消す(版と中身ごと)。行を消してからファイル */
function km_html_site_delete(PDO $pdo, array $site): void
{
    $pdo->prepare('DELETE FROM km_html_versions WHERE site_id = ?')->execute([$site['dbId']]);
    $pdo->prepare('DELETE FROM km_html_sites WHERE id = ?')->execute([$site['dbId']]);
    km_html_remove_dir(km_html_site_dir($site['id']));
}

function km_html_site_rename(PDO $pdo, array $site, string $name): string
{
    $name = km_html_clean_name($name, $site['name']);
    $pdo->prepare('UPDATE km_html_sites SET name = ? WHERE id = ?')->execute([$name, $site['dbId']]);

    return $name;
}

/** その人のサイトを全部消す(アカウントの削除。lib/account-delete.php)。表が無ければ 0 */
function km_html_sites_delete_owner(PDO $pdo, string $ownerSub): int
{
    try {
        $statement = $pdo->prepare('SELECT * FROM km_html_sites WHERE owner_sub = ?');
        $statement->execute([$ownerSub]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), '1146') || str_contains($exception->getMessage(), 'Base table or view not found')) {
            return 0;
        }
        throw $exception;
    }
    foreach ($rows as $row) {
        km_html_site_delete($pdo, km_html_site_row($row));
    }

    return count($rows);
}

/**
 * 管理画面の一覧(利用者ごとのサイトの数と合計・一時の合計)。表が無ければ空
 *
 * @return array{owners: list<array<string, mixed>>, sites: list<array<string, mixed>>, tempCount:int, tempBytes:int}
 */
function km_html_admin_summary(PDO $pdo): array
{
    km_html_ensure_tables($pdo);
    $sites = array_map('km_html_site_row', $pdo->query('SELECT * FROM km_html_sites ORDER BY owner_name, updated_at DESC')->fetchAll(PDO::FETCH_ASSOC));
    $owners = [];
    foreach ($sites as $site) {
        $key = $site['ownerSub'];
        $owners[$key] ??= ['ownerSub' => $key, 'ownerName' => $site['ownerName'], 'sites' => 0, 'bytes' => 0];
        $owners[$key]['sites']++;
        $owners[$key]['bytes'] += $site['bytes'];
    }
    $temp = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(bytes), 0) FROM km_html_temp WHERE expires_at > ?');
    $temp->execute([km_html_now()]);
    [$tempCount, $tempBytes] = $temp->fetch(PDO::FETCH_NUM) ?: [0, 0];

    return ['owners' => array_values($owners), 'sites' => $sites, 'tempCount' => (int) $tempCount, 'tempBytes' => (int) $tempBytes];
}

// ---------------------------------------------------------------------------
// 一時フォルダ(サインインしていない人で、ローカルが使えないとき)
// ---------------------------------------------------------------------------

function km_html_temp_dir(string $publicId): string
{
    return km_html_temp_root() . '/' . $publicId;
}

/** 期限の過ぎた一時を消す(上げるたびに呼ぶ。cron は増やさない)。行の無いフォルダも 1 日を過ぎたら消す */
function km_html_temp_sweep(PDO $pdo): int
{
    km_html_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT public_id FROM km_html_temp WHERE expires_at <= ?');
    $statement->execute([km_html_now()]);
    $removed = 0;
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $publicId) {
        $pdo->prepare('DELETE FROM km_html_temp WHERE public_id = ?')->execute([$publicId]);
        km_html_remove_dir(km_html_temp_dir((string) $publicId));
        $removed++;
    }
    $live = array_flip(array_map('strval', $pdo->query('SELECT public_id FROM km_html_temp')->fetchAll(PDO::FETCH_COLUMN)));
    foreach (glob(km_html_temp_root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!isset($live[basename($dir)]) && filemtime($dir) < time() - KM_HTML_TEMP_TTL) {
            km_html_remove_dir($dir);
        }
    }
    $pdo->prepare('DELETE FROM km_html_tickets WHERE expires_at <= ?')->execute([km_html_now()]);
    $pdo->prepare('DELETE FROM km_html_viewers WHERE expires_at <= ?')->execute([km_html_now()]);

    return $removed;
}

/** @return array<string, mixed>|null 期限の中のものだけ */
function km_html_temp_find(PDO $pdo, string $publicId): ?array
{
    if (!km_html_id_valid($publicId)) {
        return null;
    }
    km_html_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_html_temp WHERE public_id = ? AND expires_at > ?');
    $statement->execute([$publicId, km_html_now()]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function km_html_temp_key_valid(array $row, string $key): bool
{
    return preg_match('/^[0-9a-f]{64}$/', $key) === 1 && hash_equals((string) $row['key_hash'], hash('sha256', $key));
}

/**
 * 一時を作る(または $existing に重ねる)。鍵は作ったときだけ返す(DB にはハッシュ)
 *
 * @param array<string, string> $files
 * @return array{id:string, key:?string, entry:string, files:int, bytes:int, expiresAt:string, name:string}
 */
function km_html_temp_save(PDO $pdo, array $files, string $name, string $requestedEntry = '', ?array $existing = null): array
{
    km_html_temp_sweep($pdo);
    $base = [];
    $baseEntry = '';
    if ($existing !== null) {
        $current = km_html_read_manifest(km_html_temp_dir((string) $existing['public_id']) . '/manifest.json');
        if ($current === null) {
            throw new InvalidArgumentException('足す先が見つかりません(期限が過ぎたかもしれません)。');
        }
        $base = $current['files'];
        $baseEntry = $current['entry'];
    }
    $paths = array_values(array_unique(array_merge(array_keys($base), array_keys($files))));
    if (count($paths) > KM_HTML_MAX_FILES) {
        throw new InvalidArgumentException('ファイルは ' . KM_HTML_MAX_FILES . ' 個までにしてください。');
    }
    $entry = km_html_pick_entry($paths, $requestedEntry !== '' ? $requestedEntry : $baseEntry);
    if ($entry === null) {
        throw new InvalidArgumentException(array_filter($paths, 'km_html_is_html') === []
            ? 'HTML がありません。HTML も一緒に落としてください。'
            : '最初に開く HTML を選んでください。');
    }
    $pool = (int) $pdo->query('SELECT COALESCE(SUM(bytes), 0) FROM km_html_temp')->fetchColumn();
    $newBytes = array_sum(array_map('strlen', $files));
    if ($pool + $newBytes > KM_HTML_TEMP_POOL_BYTES) {
        throw new RuntimeException('いま一時フォルダがいっぱいです。しばらくしてからお試しください。');
    }

    $publicId = $existing !== null ? (string) $existing['public_id'] : km_html_new_id();
    $dir = km_html_temp_dir($publicId);
    km_html_ensure_dir($dir);
    $manifest = array_merge($base, km_html_write_blobs($dir, $files));
    ksort($manifest, SORT_STRING);
    km_html_write_manifest($dir . '/manifest.json', $entry, $manifest);
    $bytes = km_html_prune_blobs($dir);
    $name = km_html_clean_name($name, (string) ($existing['name'] ?? 'HTML'));

    $key = null;
    if ($existing === null) {
        $key = bin2hex(random_bytes(32));
        $expires = km_html_now(KM_HTML_TEMP_TTL);
        $pdo->prepare('INSERT INTO km_html_temp (public_id, key_hash, name, entry, file_count, bytes, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)')
            ->execute([$publicId, hash('sha256', $key), $name, $entry, count($manifest), $bytes, $expires]);
    } else {
        // 足しても期限は延ばさない(上げてから 24 時間)
        $expires = (string) $existing['expires_at'];
        $pdo->prepare('UPDATE km_html_temp SET entry = ?, file_count = ?, bytes = ? WHERE public_id = ?')
            ->execute([$entry, count($manifest), $bytes, $publicId]);
    }

    return ['id' => $publicId, 'key' => $key, 'entry' => $entry, 'files' => count($manifest), 'bytes' => $bytes, 'expiresAt' => $expires, 'name' => $name];
}

function km_html_temp_delete(PDO $pdo, array $row): void
{
    $pdo->prepare('DELETE FROM km_html_temp WHERE public_id = ?')->execute([$row['public_id']]);
    km_html_remove_dir(km_html_temp_dir((string) $row['public_id']));
}

// ---------------------------------------------------------------------------
// 見るための印(札 → __Host-KMHV)
// ---------------------------------------------------------------------------

/**
 * 札を作る。行き先は**本体が組んだもの**(/s/<番号>/… か /t/<番号>/…)。札の値はここでしか返さない
 *
 * @param 'owner'|'temp' $kind owner は Logto の sub、temp は一時の番号
 */
function km_html_ticket_issue(PDO $pdo, string $kind, string $subject, string $to): string
{
    km_html_ensure_tables($pdo);
    if (!in_array($kind, ['owner', 'temp'], true) || $subject === '' || preg_match('#^/[st]/[0-9a-f]{24}/#', $to) !== 1) {
        throw new InvalidArgumentException('札を作れません。');
    }
    $ticket = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO km_html_tickets (hash, kind, subject, to_path, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $ticket), $kind, $subject, $to, km_html_now(KM_HTML_TICKET_TTL)]);

    return $ticket;
}

/**
 * 札を使い切る(1 回限り。消せた 1 行だけが勝つ)。
 *
 * @return array{kind:string, subject:string, to:string}|null
 */
function km_html_ticket_consume(PDO $pdo, string $ticket): ?array
{
    if (preg_match('/^[0-9a-f]{64}$/', $ticket) !== 1) {
        return null;
    }
    km_html_ensure_tables($pdo);
    $hash = hash('sha256', $ticket);
    $statement = $pdo->prepare('SELECT kind, subject, to_path FROM km_html_tickets WHERE hash = ? AND expires_at > ?');
    $statement->execute([$hash, km_html_now()]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $delete = $pdo->prepare('DELETE FROM km_html_tickets WHERE hash = ?');
    $delete->execute([$hash]);
    if (!is_array($row) || $delete->rowCount() !== 1) {
        return null;
    }

    return ['kind' => (string) $row['kind'], 'subject' => (string) $row['subject'], 'to' => (string) $row['to_path']];
}

/**
 * 見るための印に、持ち主か一時の番号を足す。今の印が使えればそれに足し、無ければ作る。印の値を返す。
 * 別の人がサインインして開いたら、持ち主はその人に置き換わる(1 つのブラウザに持ち主は 1 人)
 */
function km_html_viewer_grant(PDO $pdo, string $currentToken, string $kind, string $subject): string
{
    km_html_ensure_tables($pdo);
    $row = null;
    if (preg_match('/^[0-9a-f]{64}$/', $currentToken) === 1) {
        $statement = $pdo->prepare('SELECT owner_sub, temps FROM km_html_viewers WHERE hash = ? AND expires_at > ?');
        $statement->execute([hash('sha256', $currentToken), km_html_now()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $owner = is_array($row) ? $row['owner_sub'] : null;
    $temps = is_array($row) ? (json_decode((string) $row['temps'], true) ?: []) : [];
    if ($kind === 'owner') {
        $owner = $subject;
    } else {
        $temps = array_values(array_unique(array_merge([$subject], array_filter($temps, 'is_string'))));
        $temps = array_slice($temps, 0, KM_HTML_VIEWER_TEMPS_MAX);
    }
    $token = is_array($row) ? $currentToken : bin2hex(random_bytes(32));
    $pdo->prepare('REPLACE INTO km_html_viewers (hash, owner_sub, temps, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $owner, json_encode($temps), km_html_now(KM_HTML_VIEWER_TTL)]);

    return $token;
}

/** @return array{owner:?string, temps:list<string>}|null */
function km_html_viewer_find(PDO $pdo, string $token): ?array
{
    if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        return null;
    }
    km_html_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT owner_sub, temps FROM km_html_viewers WHERE hash = ? AND expires_at > ?');
    $statement->execute([hash('sha256', $token), km_html_now()]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'owner' => $row['owner_sub'] === null ? null : (string) $row['owner_sub'],
        'temps' => array_values(array_filter(json_decode((string) $row['temps'], true) ?: [], 'is_string')),
    ];
}

/**
 * その人の印を全部取り消す(本体でサインアウトしたとき。sign-out.php)。表が無ければ何もしない。
 * 一時の番号も一緒に持っていた印は、その分も見られなくなる(本体から開き直せば、また作られる)
 */
function km_html_viewers_revoke_owner(PDO $pdo, string $ownerSub): int
{
    if ($ownerSub === '') {
        return 0;
    }
    try {
        $statement = $pdo->prepare('DELETE FROM km_html_viewers WHERE owner_sub = ?');
        $statement->execute([$ownerSub]);

        return $statement->rowCount();
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), '1146') || str_contains($exception->getMessage(), 'Base table or view not found')) {
            return 0;
        }
        throw $exception;
    }
}

/**
 * 印の Cookie を置く(見せる側だけ)。**ブラウザを閉じたら消える**(本体のサインインの Cookie と同じ)。
 * DB の側は 12 時間で切れ、本体でサインアウトすると取り消す(km_html_viewers_revoke_owner)
 */
function km_html_viewer_cookie_set(string $token): void
{
    setcookie(KM_HTML_VIEWER_COOKIE, $token, [
        'expires' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
