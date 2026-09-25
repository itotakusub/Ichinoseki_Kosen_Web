<?php

declare(strict_types=1);

/**
 * Android アプリ(来場者版)向けの地図配信。api/app-map.php が使う。
 *
 * **lib/map-data.php とは別物。** あちらは Web 版地図(km_map_nodes などの
 * 正規化テーブル)を返す。こちらは管理アプリが書き出した MapDataSnapshot の
 * JSON を、そのままの形で端末へ渡す。両者はモデルが違う(アプリ側は
 * Wi-Fi フィンガープリント・建物平面図の配置・校正値を持ち、Web 側には列が無い)。
 *
 * ロジックをここに置いてあるのは、DB も Web サーバーも無しで組み立てだけを
 * 検証できるようにするため(scripts/check.php app-map)。
 */

require_once __DIR__ . '/app-secret.php';
require_once __DIR__ . '/user-error.php';

const KM_APP_MAP_FORMAT = 'kosenmap-map-package';
const KM_APP_MAP_FORMAT_VERSION = 1;

/*
 * ## 配信 ID は2つ(2026-09-14)
 *
 * - `kosen-main`  … **Website の正本。** DB の地図から作る(admin/map-publish.php の上の区画)
 * - `kosen-event` … **イベント用の正本。** 管理アプリで作った JSON を管理画面で添付して配る
 *
 * どちらを受け取るかは**アクセスコードで決まる**(codes[].slug)。設定の形
 * (maps[slug] と codes[])は変えていないので、kosen-event の無い古い設定もそのまま読める。
 * 版(revision)・期限・停止・チェックサムは maps[slug] ごとに持つ。
 */
const KM_APP_MAP_MAIN_SLUG = 'kosen-main';
const KM_APP_MAP_EVENT_SLUG = 'kosen-event';
const KM_APP_MAP_SLUGS = [KM_APP_MAP_MAIN_SLUG, KM_APP_MAP_EVENT_SLUG];

/**
 * アクセスコードの鍵つきの印(lookup)に使う鍵の名前と用途。
 * 鍵の名前は km_app_secret() の規則(英小文字・数字・_)に合わせる。
 */
const KM_APP_MAP_CODE_SECRET = 'app_map_code';
const KM_APP_MAP_CODE_PURPOSE = 'app-map-code';

/**
 * lookup を持たない古いコードを bcrypt で照合する件数の上限。
 * **1回の要求で回す bcrypt の数をコードの件数に比例させない**(android-data#1)。
 * 古いコードは一度通れば lookup が書き足されるので、ここに残るのは使われていないものだけ。
 */
const KM_APP_MAP_LEGACY_VERIFY_LIMIT = 10;

function km_app_map_config_path(): string
{
    return __DIR__ . '/../config/app-map.local.php';
}

/** @return array{storageDir:string,maps:array,codes:array} */
function km_app_map_config(): array
{
    $path = km_app_map_config_path();
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) {
        $config = [];
    }
    return [
        'storageDir' => is_string($config['storageDir'] ?? null) ? trim($config['storageDir']) : '',
        'maps' => is_array($config['maps'] ?? null) ? $config['maps'] : [],
        'codes' => is_array($config['codes'] ?? null) ? $config['codes'] : [],
    ];
}

/**
 * `config/app-map.local.php` を書き戻す。
 *
 * ## 所有者の罠を避ける
 *
 * 本番の `src/config/` は**配備利用者のもの**で、PHP(www-data)は
 * **その中に新しいファイルを作れない**。一時ファイルを作って rename する作りだと、
 * 書き込みだけが静かに失敗する —— 「地図データ公開設定が保存できない」で
 * 実際に起きた形なので、`lib/map-access.php` と同じ二段構えにする。
 *
 * **失敗したら理由を添えて投げる。** 「保存に失敗しました」だけでは打つ手が分からない。
 */
function km_app_map_write_config(array $config, ?string $path = null): void
{
    $path ??= km_app_map_config_path();
    $php = "<?php\n\ndeclare(strict_types=1);\n\n"
        . "// scripts/publish-events.php と scripts/new-map-release.ps1 が書き換える。\n"
        . "// 'hash' は password_hash() の出力であること(平文を置かない)。\n"
        . "// このファイルは配備対象外(deploy-to-host.ps1 の除外一覧)。ホスト側が正本。\n\n"
        . 'return ' . var_export($config, true) . ";\n";

    $failure = null;
    set_error_handler(static function (int $severity, string $message) use (&$failure): bool {
        $failure = $message;

        return true;
    });

    try {
        if (is_writable(dirname($path))) {
            $tmp = $path . '.tmp';
            if (file_put_contents($tmp, $php, LOCK_EX) !== false && rename($tmp, $path)) {
                // rename で作り直すと権限が umask 任せになる。
                // アクセスコードのハッシュを同じホストの他の利用者に見せない
                chmod($path, 0640);

                return;
            }
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        if (file_put_contents($path, $php, LOCK_EX) !== false) {
            return;
        }
    } finally {
        restore_error_handler();
    }

    /*
     * 画面に出すのは**打つ手だけ**(KmUserError)。置き場の絶対パスと PHP の警告文は
     * サーバーのログへ回す —— 例外の文をそのまま画面に出さない(2026-09-14 の決まり)。
     */
    error_log(sprintf('km_app_map_write_config: %s へ書き込めませんでした(%s)', $path, $failure ?? '原因不明'));
    throw new KmUserError(sprintf(
        '配信設定を書き込めませんでした。ホストで次を実行してください: '
            . 'docker compose exec -u root web chown www-data config/%s'
            . ' && docker compose exec -u root web chmod 640 config/%s',
        basename($path),
        basename($path)
    ));
}

/**
 * 地図 JSON の置き場。既定は src/uploads/。
 * uploads/ は nginx で直接配信を塞いであるので、そのまま置いてよい。
 */
function km_app_map_storage_dir(array $config): string
{
    $configured = $config['storageDir'] !== '' ? $config['storageDir'] : __DIR__ . '/../uploads';
    return rtrim($configured, '/\\');
}

/**
 * 設定のファイル名を実体のパスへ解決する。
 *
 * ディレクトリを抜け出す名前(`../` や絶対パス)は拒否する。設定ファイル由来とはいえ、
 * ここを信頼して置き場の外を読ませない。
 */
function km_app_map_storage_path(array $config, string $fileName): ?string
{
    $name = trim($fileName);
    if ($name === '' || strpbrk($name, "/\\") !== false || str_contains($name, '..')) {
        return null;
    }
    $path = km_app_map_storage_dir($config) . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? $path : null;
}

/**
 * アプリ側の normalizeAccessCode と同じ規則に揃える。
 * 揃っていないと、利用者が同じ文字列を入れても片方だけ通る。
 */
function km_app_map_normalize_code(string $raw): string
{
    return (string) preg_replace('/[\s\-_]+/u', '', strtoupper(trim($raw)));
}

/**
 * アクセスコードから配信 ID を引く。**古い形(bcrypt だけ)の入口。**
 *
 * bcrypt のハッシュは引けないので総当たりで照合する。**一致しても即座に返さず
 * 全件を回す**ことで、当たりの位置で処理時間が変わらないようにする。
 *
 * api/app-map.php は先に km_app_map_find_code_by_lookup() で引き、外れたときだけ
 * 件数に上限を付けて km_app_map_find_code_by_hash() を呼ぶ。ここは既存の呼び出しのために残す。
 */
function km_app_map_resolve_slug(string $code, array $config): ?string
{
    return km_app_map_find_code_by_hash($code, $config)['slug'] ?? null;
}

/**
 * アクセスコードの鍵つきの印。**正規化したコード**を渡すこと。
 *
 * `keyId` は鍵そのものの短い印。km_app_secrets の行を消して鍵が作り直されると、
 * 保存済みの lookup は二度と一致しない —— そのとき**黙って誰も入れなくなる**のを避けるため、
 * 鍵の違う lookup は「無いもの」として bcrypt の照合へ回す(通れば新しい鍵で書き直す)。
 *
 * @return array{lookup:string, keyId:string}
 */
function km_app_map_code_lookup(string $secret, string $normalizedCode): array
{
    return [
        'lookup' => km_app_keyed_hash($secret, KM_APP_MAP_CODE_PURPOSE, $normalizedCode),
        'keyId' => substr(km_app_keyed_hash($secret, KM_APP_MAP_CODE_PURPOSE . '-key-id', 'key-id'), 0, 8),
    ];
}

/** いまの鍵で作った lookup を持っているか。鍵が分からなければ false(= 古い形として扱う)。 */
function km_app_map_code_has_lookup(array $entry, ?string $keyId): bool
{
    return $keyId !== null
        && is_string($entry['lookup'] ?? null)
        && $entry['lookup'] !== ''
        && ($entry['lookupKey'] ?? null) === $keyId;
}

/**
 * lookup で引く。**bcrypt を回さない**ので、何度呼ばれても安い。
 *
 * 比べるのは hash_equals。一致しても全件を回す(当たりの位置で時間を変えない)。
 *
 * @param array{lookup:string, keyId:string} $lookup
 * @return array{index:int, slug:string}|null
 */
function km_app_map_find_code_by_lookup(array $config, array $lookup): ?array
{
    $matched = null;
    foreach (($config['codes'] ?? []) as $index => $entry) {
        if (!is_array($entry) || !km_app_map_code_has_lookup($entry, $lookup['keyId'])) {
            continue;
        }
        $slug = (string) ($entry['slug'] ?? '');
        if ($slug !== '' && hash_equals((string) $entry['lookup'], $lookup['lookup']) && $matched === null) {
            $matched = ['index' => (int) $index, 'slug' => $slug];
        }
    }

    return $matched;
}

/**
 * bcrypt で引く。**いまの鍵の lookup を持つコードは飛ばす**(そちらは lookup で引ける)。
 *
 * `$keyId` が null なら全件を見る(古い入口 km_app_map_resolve_slug() と同じ)。
 * `$limit` を超えた分は照合しない —— 1回の要求で回す bcrypt の数に上限を付ける。
 *
 * @return array{index:int, slug:string, hash:string}|null
 */
function km_app_map_find_code_by_hash(
    string $code,
    array $config,
    ?string $keyId = null,
    int $limit = PHP_INT_MAX
): ?array {
    $matched = null;
    $checked = 0;
    foreach (($config['codes'] ?? []) as $index => $entry) {
        if (!is_array($entry) || km_app_map_code_has_lookup($entry, $keyId)) {
            continue;
        }
        $hash = $entry['hash'] ?? null;
        $slug = (string) ($entry['slug'] ?? '');
        if (!is_string($hash) || $hash === '' || $slug === '') {
            continue;
        }
        if ($checked >= $limit) {
            // **黙って通さない。** 上限の外に正しいコードがあると、入れた人には「違います」としか出ない
            error_log('km_app_map_find_code_by_hash: lookup の無いコードが上限(' . $limit . ')を超えています。管理画面で作り直してください');
            break;
        }
        $checked++;
        if (password_verify($code, $hash) && $matched === null) {
            $matched = ['index' => (int) $index, 'slug' => $slug, 'hash' => $hash];
        }
    }

    return $matched;
}

/**
 * lookup を書き足す。**純粋関数**。bcrypt で通った古いコードを、次から安く引けるようにする。
 *
 * 並び順(index)ではなく **hash で探す** —— 照合してから書くまでの間に
 * 管理画面でコードが足し引きされても、別のコードに書かない。
 *
 * @param array{lookup:string, keyId:string} $lookup
 */
function km_app_map_remember_lookup(array $config, string $hash, array $lookup): array
{
    foreach (($config['codes'] ?? []) as $index => $entry) {
        if (is_array($entry) && is_string($entry['hash'] ?? null) && hash_equals($entry['hash'], $hash)) {
            $config['codes'][$index]['lookup'] = $lookup['lookup'];
            $config['codes'][$index]['lookupKey'] = $lookup['keyId'];
        }
    }

    return $config;
}

/**
 * 「最新です」と返してよいか。**版の比較は配信 ID ごと。**
 *
 * 版は maps[slug] ごとに数えるので、kosen-main の版 5 と kosen-event の版 5 は別物。
 * 端末が別の配信 ID の版を持っているときに比べると、**コードを付け替えたのに地図が来ない。**
 *
 * - 端末が `haveMapId` を送ってきた … それが配信先と同じときだけ版を比べる
 * - 送ってこない(古いアプリ)… 今まで配っていたのは kosen-main だけなので、そのときだけ比べる
 */
function km_app_map_is_up_to_date(string $slug, ?string $haveMapId, ?int $haveRevision, int $revision): bool
{
    if ($haveRevision === null) {
        return false;
    }
    if ($haveMapId !== null) {
        return $haveMapId === $slug && $haveRevision >= $revision;
    }

    return $slug === KM_APP_MAP_MAIN_SLUG && $haveRevision >= $revision;
}

/** 管理画面での呼び名。main / event / other。 */
function km_app_map_role(string $slug): string
{
    return match ($slug) {
        KM_APP_MAP_MAIN_SLUG => 'main',
        KM_APP_MAP_EVENT_SLUG => 'event',
        default => 'other',
    };
}

/**
 * コードを画面の操作で指すための短い印。**並び順だけで指さない** ——
 * 画面を開いてから押すまでの間に並びが変わると、別のコードを止めてしまう。
 * hash から作るので、付け替え(slug が変わる)でも変わらない。
 */
function km_app_map_code_ref(array $entry): string
{
    return substr(hash('sha256', (string) ($entry['hash'] ?? '')), 0, 16);
}

/** 画面が出した番号と印が、いまの設定でも同じコードを指しているか。 */
function km_app_map_code_at(array $config, int $index, string $ref): ?array
{
    $entry = $config['codes'][$index] ?? null;
    if (!is_array($entry) || !hash_equals(km_app_map_code_ref($entry), $ref)) {
        return null;
    }

    return $entry;
}

/**
 * **どこにも配信先の無いコード。** 入れた端末は地図を受け取れない。
 *
 * 本番には slug='TEST1' のコードが1件だけあり(maps に TEST1 は無い)、
 * アプリは地図を取れずに 500 を受けていた。消す前に**付け替えられる**ように一覧で出す。
 *
 * @return array<int, array{index:int, slug:string, ref:string, plain:?string, hasLookup:bool}>
 */
function km_app_map_orphan_codes(array $config): array
{
    $maps = is_array($config['maps'] ?? null) ? $config['maps'] : [];
    $rows = [];
    foreach (($config['codes'] ?? []) as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $slug = (string) ($entry['slug'] ?? '');
        if ($slug !== '' && is_array($maps[$slug] ?? null)) {
            continue;
        }
        $plain = trim((string) ($entry['code'] ?? ''));
        $rows[] = [
            'index' => (int) $index,
            'slug' => $slug,
            'ref' => km_app_map_code_ref($entry),
            'plain' => $plain !== '' ? $plain : null,
            'hasLookup' => is_string($entry['lookup'] ?? null) && $entry['lookup'] !== '',
        ];
    }

    return $rows;
}

/**
 * コードの配信先を付け替える。**純粋関数**。付け替え先は kosen-main / kosen-event だけ。
 *
 * hash と lookup は**そのまま** —— 配ったコード(印刷した QR)を変えずに行き先だけ直す。
 */
function km_app_map_reassign_code(array $config, int $index, string $slug): array
{
    if (!in_array($slug, KM_APP_MAP_SLUGS, true)) {
        throw new InvalidArgumentException('付け替え先の配信 ID が不正です: ' . $slug);
    }
    if (!is_array($config['codes'][$index] ?? null)) {
        throw new InvalidArgumentException('そのアクセスコードはありません: ' . $index);
    }
    $config['codes'][$index]['slug'] = $slug;

    return $config;
}

function km_app_map_parse_iso(string $value): ?DateTimeImmutable
{
    if (trim($value) === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return null;
    }
}

/** これを切ったら管理画面で警告する日数。会期の直前に気づけるだけの余裕を置く。 */
const KM_APP_MAP_EXPIRY_WARN_DAYS = 14;

/**
 * 配信中の地図の期限。**管理画面に出すためのもの。**
 *
 * ## なぜ要るのか
 *
 * 期限が切れると来場者アプリは地図とアクセスコードを消す。**そういう作りにしてある**
 * ので、切れること自体は異常ではない。問題は、切れたことが**どこにも出ていなかった**
 * こと —— 2026-08-27 に失効したのに気づかれず、記録文書にだけ残り続けた。
 *
 * 期限を短く保つのは正しい(会期が終わった地図を配り続けない)。だからこそ
 * **切れる前に気づける場所**が要る。
 *
 * ## 時刻は渡せるようにする
 *
 * `$now` を引数にしてあるのは検査のため。内部で now() を呼ぶと、
 * 「あと何日」の境目を確かめられない。
 *
 * @return array<int, array{slug:string, revision:int, expiresAt:?string,
 *                          expired:bool, daysLeft:?int, warn:bool, unknown:bool}>
 */
function km_app_map_release_status(array $config, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    $rows = [];

    foreach (($config['maps'] ?? []) as $slug => $map) {
        if (!is_array($map)) {
            continue;
        }

        $raw = is_string($map['expiresAt'] ?? null) ? $map['expiresAt'] : '';
        $expiresAt = $raw !== '' ? km_app_map_parse_iso($raw) : null;

        /*
         * **期限が読めないものを「大丈夫」と扱わない。**
         * 書き損じや空欄を素通りさせると、配信が止まっているのに画面は無言になる。
         */
        $unknown = $expiresAt === null;
        $expired = !$unknown && $expiresAt <= $now;

        // 切り捨てではなく「日付の差」で数える。1時間後でも「あと0日」と出したい
        $daysLeft = $unknown ? null : (int) $now->diff($expiresAt)->format('%r%a');

        $rows[] = [
            'slug' => (string) $slug,
            'revision' => (int) ($map['revision'] ?? 0),
            'expiresAt' => $unknown ? null : $expiresAt->format(DateTimeInterface::ATOM),
            'expired' => $expired,
            'daysLeft' => $daysLeft,
            'warn' => $unknown || $expired || ($daysLeft !== null && $daysLeft <= KM_APP_MAP_EXPIRY_WARN_DAYS),
            'unknown' => $unknown,
        ];
    }

    return $rows;
}

/**
 * 配信を止める / 再開する。**純粋関数**(新しい設定を返すだけ)。
 *
 * ## 止めると何が起きるか
 *
 * `api/app-map.php` が 410 を返すようになる。つまり:
 *
 *   - **これから取りに来る端末は受け取れない**(アクセスコードは正しくても)
 *   - **既に受け取った端末の地図は消えない。** あちらは自分が持っている期限で
 *     判断しており、410 は「取得に失敗した」としか扱わない
 *
 * 配り終えたものまで取り上げたいなら、止めるのではなく**期限を縮める**しかない。
 * 画面にもそう書くこと —— 「止めたのに使われている」と見えると、
 * **もう一度止めようとして設定を壊す。**
 *
 * ## 期限は触らない
 *
 * 「止める = 期限を今にする」でも同じ動きになるが、**元の期限が失われる。**
 * 再開したいときに、いつまでのつもりだったのかが分からなくなる。
 */
function km_app_map_set_paused(array $config, string $slug, bool $paused): array
{
    if (!isset($config['maps'][$slug]) || !is_array($config['maps'][$slug])) {
        throw new InvalidArgumentException('その配信 ID はありません: ' . $slug);
    }
    $config['maps'][$slug]['paused'] = $paused;

    return $config;
}

/** 止まっているか。**設定が無ければ止まっていない**(既存の設定をそのまま読める)。 */
function km_app_map_is_paused(array $map): bool
{
    return ($map['paused'] ?? false) === true;
}

/**
 * 配信をまるごと消す。**純粋関数** —— ファイルはここでは消さない。
 *
 * ## アクセスコードも一緒に消す
 *
 * 地図だけ消してコードを残すと、**行き先の無いコード**になる。
 * 入れた人には「地図が見つかりません」としか出ず、原因に辿り着けない ——
 * `TEST1` で実際に起きた形。
 *
 * ## 消すファイル名を返す
 *
 * 消したあとの設定からはファイル名が引けない。**呼ぶ側が消せるように返す。**
 * ファイルを消すのは DB でも設定でもなく取り返しがつかないので、
 * **設定を書き終えてから**触ること。
 *
 * @return array{config:array, fileName:?string, removedCodes:int}
 */
function km_app_map_remove(array $config, string $slug): array
{
    if (!isset($config['maps'][$slug]) || !is_array($config['maps'][$slug])) {
        throw new InvalidArgumentException('その配信 ID はありません: ' . $slug);
    }

    $fileName = trim((string) ($config['maps'][$slug]['file'] ?? ''));
    unset($config['maps'][$slug]);

    $before = count($config['codes'] ?? []);
    $config['codes'] = array_values(array_filter(
        $config['codes'] ?? [],
        static fn ($entry) => !(is_array($entry) && ($entry['slug'] ?? null) === $slug)
    ));

    return [
        'config' => $config,
        'fileName' => $fileName !== '' ? $fileName : null,
        'removedCodes' => $before - count($config['codes']),
    ];
}

/**
 * 配信ファイルの中身を要約する。**純粋関数** —— 渡された snapshot だけを見る。
 *
 * ## なぜ数えるのか
 *
 * 「期限が切れているか」だけでは**上げ間違いに気づけない。**
 * 設定ファイルの `revision` は書き換わったのに**実体が古いまま**、という形が
 * 実際に疑われている(`-UploadToHost` が長らく失敗していた)。
 * 件数は、それに気づける唯一の手がかり。
 *
 * `$now` を引数にしてあるのは検査のため。中で now() を呼ぶと会期の境目を確かめられない。
 *
 * @return array{readable:bool, mapVersion:?int, nodeCount:int, lineCount:int,
 *               occupantCount:int, fingerprintCount:int, overlayCount:int,
 *               eventCount:int, activeEvent:?array}
 */
function km_app_map_snapshot_summary(
    ?stdClass $snapshot,
    ?string $activeEventUuid = null,
    ?DateTimeImmutable $now = null
): array {
    $empty = [
        'readable' => false,
        'mapVersion' => null,
        'nodeCount' => 0,
        'lineCount' => 0,
        'occupantCount' => 0,
        'fingerprintCount' => 0,
        'overlayCount' => 0,
        'eventCount' => 0,
        'activeEvent' => null,
    ];
    if ($snapshot === null) {
        return $empty;
    }

    $nodes = is_array($snapshot->nodes ?? null) ? $snapshot->nodes : [];
    $occupants = 0;
    foreach ($nodes as $node) {
        if ($node instanceof stdClass && trim((string) ($node->occupantName ?? '')) !== '') {
            $occupants++;
        }
    }

    $events = is_array($snapshot->events ?? null) ? $snapshot->events : [];

    return [
        'readable' => true,
        'mapVersion' => isset($snapshot->version) ? (int) $snapshot->version : null,
        'nodeCount' => count($nodes),
        'lineCount' => is_array($snapshot->lines ?? null) ? count($snapshot->lines) : 0,
        'occupantCount' => $occupants,
        'fingerprintCount' => is_array($snapshot->fingerprints ?? null) ? count($snapshot->fingerprints) : 0,
        'overlayCount' => is_array($snapshot->overlays ?? null) ? count($snapshot->overlays) : 0,
        'eventCount' => count($events),
        'activeEvent' => km_app_map_active_event_status($events, $activeEventUuid, $now),
    ];
}

/**
 * 配信に載せているイベントの状態。載せていなければ null。
 *
 * ## 「終わったイベントが載ったまま」を見つけるためのもの
 *
 * アプリ側の `activeMapEvent()` は**期間外を黙って null にする** ——
 * 通行止めを指定したのに何も起きない、という形でしか現れない。
 * 設定した側からは「効いていない」のか「そもそも終わっている」のか分からないので、
 * **こちらで言い切る。**
 *
 * @param array<int, mixed> $events 配信ファイルの events
 * @return array{uuid:string, missing:bool, name:?string, startAt:?string, endAt:?string,
 *               notStarted:bool, ended:bool, active:bool}|null
 */
function km_app_map_active_event_status(
    array $events,
    ?string $activeEventUuid,
    ?DateTimeImmutable $now = null
): ?array {
    $uuid = trim((string) ($activeEventUuid ?? ''));
    if ($uuid === '') {
        return null;
    }
    $now ??= new DateTimeImmutable('now');
    $nowMillis = $now->getTimestamp() * 1000;

    $found = null;
    foreach ($events as $event) {
        if ($event instanceof stdClass && (string) ($event->uuid ?? '') === $uuid) {
            $found = $event;
            break;
        }
    }

    /*
     * **見つからないことを「期間外」と同じに扱わない。**
     * 地図を差し替えたときにイベントごと入れ替わり、設定の activeEventUuid だけが
     * 古い値のまま残る —— そのとき利用者には「イベントが効かない」としか見えない。
     */
    if ($found === null) {
        return [
            'uuid' => $uuid,
            'missing' => true,
            'name' => null,
            'startAt' => null,
            'endAt' => null,
            'notStarted' => false,
            'ended' => false,
            'active' => false,
        ];
    }

    $toIso = static function ($millis): ?string {
        if (!is_int($millis) && !is_float($millis)) {
            return null;
        }
        return (new DateTimeImmutable('@' . (int) ($millis / 1000)))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(DateTimeInterface::ATOM);
    };

    $startMillis = $found->startAtMillis ?? null;
    $endMillis = $found->endAtMillis ?? null;
    $notStarted = is_numeric($startMillis) && $nowMillis < (int) $startMillis;
    $ended = is_numeric($endMillis) && $nowMillis > (int) $endMillis;

    return [
        'uuid' => $uuid,
        'missing' => false,
        'name' => (string) ($found->name ?? ''),
        'startAt' => $toIso($startMillis),
        'endAt' => $toIso($endMillis),
        'notStarted' => $notStarted,
        'ended' => $ended,
        'active' => !$notStarted && !$ended,
    ];
}

/**
 * 配信ファイルの実体を調べる。**設定ではなく、置いてあるものを見る。**
 *
 * 設定の `revision` だけが新しくて中身が古い、という形が実際に疑われている。
 * **並べて出せるように、こちらは実体だけを返す。**
 *
 * @return array{fileName:string, exists:bool, path:?string, sizeBytes:?int,
 *               modifiedAt:?string, sha256:?string, error:?string}
 */
function km_app_map_release_file_info(array $config, array $map): array
{
    $fileName = (string) ($map['file'] ?? '');
    $info = [
        'fileName' => $fileName,
        'exists' => false,
        'path' => null,
        'sizeBytes' => null,
        'modifiedAt' => null,
        'sha256' => null,
        'error' => null,
    ];

    if ($fileName === '') {
        $info['error'] = 'ファイル名が設定されていません';
        return $info;
    }

    $path = km_app_map_storage_path($config, $fileName);
    if ($path === null) {
        // **「無い」と「読めない」を分ける。**置き場ごと間違えているのか、
        // 権限で読めないのかで、打つ手がまるで違う
        $info['error'] = '置き場にありません: ' . km_app_map_storage_dir($config);
        return $info;
    }

    $info['exists'] = true;
    $info['path'] = $path;
    $size = @filesize($path);
    $info['sizeBytes'] = is_int($size) ? $size : null;
    $modified = @filemtime($path);
    if (is_int($modified)) {
        $info['modifiedAt'] = (new DateTimeImmutable('@' . $modified))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(DateTimeInterface::ATOM);
    }
    $hash = @hash_file('sha256', $path);
    if (is_string($hash)) {
        $info['sha256'] = $hash;
    } else {
        $info['error'] = 'PHP から読めません(所有者と権限を確認してください)';
    }

    return $info;
}

/**
 * 管理画面へ出す配信の全体像。設定・実体・中身を1行にまとめる。
 *
 * **DB は使わない。** 配信の設定と実体だけで完結するので、
 * DB が落ちていても配信の状態は見られる。
 *
 * @return array<int, array>
 */
function km_app_map_release_report(array $config, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    $statusBySlug = [];
    foreach (km_app_map_release_status($config, $now) as $status) {
        $statusBySlug[$status['slug']] = $status;
    }

    /*
     * アクセスコードは配信ごとに数える。**孤児のコードを二度と作らない**ため。
     *
     * 平文(`code`)は**管理画面に出すためだけ**に持ち回る。
     * 照合に使うのは今までどおり `hash` だけで、
     * ここで平文を読めなくても配信そのものには何の影響も無い
     * (古い設定には `code` が入っていない —— そのときは「保存していません」と出す)。
     */
    $codeCounts = [];
    $codePlain = [];
    $legacyCounts = [];
    foreach (($config['codes'] ?? []) as $entry) {
        if (is_array($entry) && ($entry['slug'] ?? '') !== '') {
            $slug = (string) $entry['slug'];
            $codeCounts[$slug] = ($codeCounts[$slug] ?? 0) + 1;
            // lookup の無いコード(一度も使われていない古い形)。照合が bcrypt に回る
            if (!is_string($entry['lookup'] ?? null) || $entry['lookup'] === '') {
                $legacyCounts[$slug] = ($legacyCounts[$slug] ?? 0) + 1;
            }
            $plain = trim((string) ($entry['code'] ?? ''));
            if ($plain !== '' && !isset($codePlain[$slug])) {
                $codePlain[$slug] = $plain;
            }
        }
    }

    $rows = [];
    foreach (($config['maps'] ?? []) as $slug => $map) {
        if (!is_array($map)) {
            continue;
        }
        $slug = (string) $slug;
        $file = km_app_map_release_file_info($config, $map);

        $snapshot = null;
        if ($file['path'] !== null) {
            $json = @file_get_contents($file['path']);
            if (is_string($json)) {
                $snapshot = km_app_map_decode_snapshot($json);
            }
        }
        $activeEventUuid = is_string($map['activeEventUuid'] ?? null) ? $map['activeEventUuid'] : null;
        $summary = km_app_map_snapshot_summary($snapshot, $activeEventUuid, $now);

        if ($file['exists'] && !$summary['readable'] && $file['error'] === null) {
            $file['error'] = 'ファイルはありますが、地図として読めません';
        }

        $rows[] = [
            'slug' => $slug,
            // main(Website の正本)/ event(イベント用の正本)/ other(古い設定の名前)
            'role' => km_app_map_role($slug),
            'status' => $statusBySlug[$slug] ?? null,
            'checksumEnabled' => ($map['checksum'] ?? false) === true,
            'activeEventUuid' => $activeEventUuid,
            'paused' => km_app_map_is_paused($map),
            'codeCount' => $codeCounts[$slug] ?? 0,
            'legacyCodeCount' => $legacyCounts[$slug] ?? 0,
            // 平文。保存されていなければ null(古い設定 = 次のリリースから入る)
            'code' => $codePlain[$slug] ?? null,
            'file' => $file,
            'summary' => $summary,
        ];
    }

    return $rows;
}

/**
 * 配布ファイルの中身を MapDataSnapshot 相当まで開く。読めなければ null。
 *
 * **連想配列にしない。** json_decode(..., true) は空の JSON オブジェクト {} を
 * 空配列にしてしまい、再エンコードで [] に化ける。rssiByBssid のようなマップ型の
 * フィールドが壊れ、端末側の MapDataRepository.decode を通らなくなる。
 *
 * 管理アプリのエクスポートは「マップ単体」でも「全体バックアップ」でも、
 * どちらの形でもそのまま置けるようにする。
 */
function km_app_map_decode_snapshot(string $json): ?stdClass
{
    $decoded = json_decode($json);
    if (!($decoded instanceof stdClass)) {
        return null;
    }
    if (isset($decoded->map) && $decoded->map instanceof stdClass) {
        $decoded = $decoded->map;
    }
    if (!isset($decoded->nodes) || !isset($decoded->lines)) {
        return null;
    }
    return $decoded;
}

/**
 * スタッフ限定の一時地点(本部・控室・搬入口など)を落とす。
 *
 * アプリ側の eventPlacesForRole() は**クライアント側の**フィルタなので、
 * 配ってしまうと改造アプリからは見えてしまう。出さないことでしか守れない。
 *
 * 通行止め(closedLineKeys / closedNodeUuids / closedFloors)は**落とさない**。
 * 規制されている事実は全員に見せる仕様で、通れるかどうかはアプリ側が
 * Logto の権限で決めるため、隠しても意味がない。
 */
function km_app_map_strip_staff_only(stdClass $map): stdClass
{
    km_app_map_strip_occupant_names($map);

    if (!isset($map->events) || !is_array($map->events)) {
        return $map;
    }
    foreach ($map->events as $event) {
        if (!($event instanceof stdClass) || !isset($event->places) || !is_array($event->places)) {
            continue;
        }
        $event->places = array_values(array_filter(
            $event->places,
            static fn ($place) => !($place instanceof stdClass && ($place->staffOnly ?? false) === true)
        ));
    }
    return $map;
}

/**
 * ノードから教職員氏名を落とす。**引数を書き換える**(呼ぶ側で受け直さない)。
 *
 * ## なぜ配信から外すのか
 *
 * 氏名はアプリの地図が正本になった(MAP_DATA_VERSION 9)。管理者は手元で編集するが、
 * **配るパッケージに載せてよいかは別の話**。アプリ側で隠しても、配ってしまえば
 * 改造アプリからは見える —— 出さないことでしか守れない(一時地点と同じ理屈)。
 *
 * ## 錠は1つ
 *
 * 出してよいかは Website の「地図データ公開設定」が決める
 * ([km_map_access_config] の `mode`)。**`hidden` なら staff にも出さない。**
 * アプリと Web で別々の判断を持つと、片方だけ開いたことに誰も気づけない。
 */
function km_app_map_strip_occupant_names(stdClass $map): void
{
    if (!isset($map->nodes) || !is_array($map->nodes)) {
        return;
    }
    foreach ($map->nodes as $node) {
        if ($node instanceof stdClass) {
            // **キーごと消さない。** 端末側は全ノードが同じ形であることを前提にしており
            // (serializeNulls)、抜くと形が揃わなくなる。null にする
            $node->occupantName = null;
        }
    }
}

/**
 * 氏名を配ってよいか。Website の「地図データ公開設定」に従う。
 *
 * | mode | staff | それ以外 |
 * |---|---|---|
 * | `hidden` | **配らない** | 配らない |
 * | `password` / `public` | 配る | 配らない |
 *
 * `password` を「アプリでもパスワードを聞く」とは解釈しない —— アプリに解除の口が無く、
 * 聞けない錠を掛けると誰も開けられない。**staff かどうかで分ける。**
 */
function km_app_map_may_send_occupant_names(bool $isStaff, bool $isTeacher = false): bool
{
    require_once __DIR__ . '/map-access.php';

    if (!$isStaff) {
        return false;
    }
    $config = km_map_access_config();
    if ($config['mode'] !== 'hidden') {
        return true;
    }

    /*
     * hidden のときは誰にも配らない。**ただし管理画面で「常に隠すでも教職員には見せる」にしたときだけ、
     * 教職員には配る**(docs/15 段 D。Web の km_map_names_unlocked と同じ判断)。
     * イベント運営の staff はこの切り替えの対象ではない。
     */
    return $isTeacher && $config['teacherSeesHidden'];
}

/**
 * 配信パッケージの JSON を組み立てる。
 *
 * map は**チェックサムを取った文字列そのもの**を埋める。組み立て後に再エンコードすると、
 * 端末が受け取った文字列とハッシュが食い違いうる。
 *
 * @return string|null エンコードに失敗したら null。
 */
function km_app_map_build_package(
    stdClass $map,
    string $slug,
    int $revision,
    string $serverTime,
    string $expiresAt,
    ?string $activeEventUuid,
    bool $withChecksum,
    /** 経路の重み(lib/route-weights.php)。配っていなければ null(アプリは自分の既定で動く) */
    ?array $routeWeights = null
): ?string {
    $mapJson = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($mapJson)) {
        return null;
    }

    $placeholder = '__KM_APP_MAP_BODY__';
    $package = [
        'format' => KM_APP_MAP_FORMAT,
        'formatVersion' => KM_APP_MAP_FORMAT_VERSION,
        'mapId' => $slug,
        'revision' => $revision,
        'issuedAt' => $serverTime,
        'expiresAt' => $expiresAt,
        'activeEventUuid' => ($activeEventUuid ?? '') !== '' ? $activeEventUuid : null,
        'serverTime' => $serverTime,
        'checksum' => $withChecksum ? 'sha256:' . hash('sha256', $mapJson) : null,
        // 地図の外に置く(チェックサムは地図の文字列だけに取っている。重みを変えても版を上げずに済む)
        'routeWeights' => $routeWeights,
        'map' => $placeholder,
    ];
    $body = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return null;
    }
    return str_replace('"' . $placeholder . '"', $mapJson, $body);
}
