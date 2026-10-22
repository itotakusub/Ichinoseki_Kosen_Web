<?php

declare(strict_types=1);

/**
 * ポスター(広告)と、地点の URL(2026-10-21、利用者の指示)。
 *
 * - **出し方は 3 段階**: 表示しない / イベント中のみ / 常に表示(管理画面「ポスター・広告」)
 * - ポスター 1 枚 = 画像・見出し・URL・地点。地点の詳細に出し、一覧のページ(/posters.php)から地図のその地点へ飛ぶ
 * - 地点の URL: 地点の詳細にリンクを 1 本出す(ポスターとは別。出し方の 3 段階にも関わらない)
 *
 * ## 画像は必ずこちらに置く
 *
 * 「URL から」を選んでも、**取り込んだときにサーバーが取りに行って保存する**。公開ページで外部の画像を直に出さない ——
 *   - CSP の img-src は 'self' と data: だけ(lib/csp.php)。外部を足すと、どこの画像でも出せるようになる
 *   - 来場者の端末から外部のサーバーへ繋がない(閲覧の記録を外へ渡さない。学校のプロキシでも止まらない)
 *   - 元のページが消えても出し続けられる
 * 取りに行く先は**公開されているアドレスだけ**(km_poster_ip_allowed)。管理者しか使えない機能でも、
 * サーバーの内側(Docker の網・127.0.0.1・メタデータ)を覗く道にはしない。
 *
 * ## 表と列
 *
 * 地点の正本(km_map_nodes)には**列を足さない** —— アプリの DB 利用者には ALTER が無く
 * (lib/map-data.php の km_map_floor_bounds と同じ判断)、地図の取り込みで地点が作り直されても URL が残るよう別表にする。
 * 表は管理画面を開いたときに作る。公開ページは表が無ければ「何も無い」として素通りする(CREATE を公開の経路で流さない)。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';   // km_upload_dir() / km_upload_format_size()
require_once __DIR__ . '/raster-image.php';   // 画像の中身の確かめ(階の画像と共通)

/** 出し方の設定(km_settings)の名前と、選べる値 → 画面の言葉。 */
const KM_POSTER_MODE_SETTING = 'poster_display_mode';
const KM_POSTER_MODES = [
    'off' => '表示しない',
    'event' => 'イベント中のみ',
    'always' => '常に表示',
];
/** まだ選んでいないとき。**出さない側に倒す**(作りかけのポスターが勝手に出ない) */
const KM_POSTER_MODE_DEFAULT = 'off';

/**
 * 広告 1 件あたりの重み(%)(2026-10-21、利用者の指示「広告の確率は全て 10% に統一」)。
 *
 * **広告 = 地点に置いていないポスター。** 操作の案内(チュートリアル)が終わったときに、**広告があれば必ず 1 件を出す**。
 * どれを出すかは重みで選ぶ。**全部同じ重み**なので、広告が 1 件なら 100%、2 件なら 50%、10 件なら 10%
 * (同日の利用者の指示「表示しないというわけでは無い。1 枚なら 100%、2 枚なら 50%」。km_poster_ad_share)。
 * 地点に置いたポスターは地点の詳細と一覧に出るもので、重みは掛からない。
 * **利用規約の第 7 条はこの値から書く**(lib/legal.php)。変えるときはここだけを変える。
 */
const KM_POSTER_AD_WEIGHT = 10;

/** 広告が $count 件あるとき、1 件が選ばれる割合(0〜1)。全部同じ重みなので 1 / 件数 */
function km_poster_ad_share(int $count): float
{
    return $count <= 0 ? 0.0 : KM_POSTER_AD_WEIGHT / (KM_POSTER_AD_WEIGHT * $count);
}

/**
 * チュートリアルの終わりに出す知らせ(2026-10-21)。広告を出したときは、広告を閉じたあとに出す。
 * 本文とアンケートの URL は管理画面「ポスター・広告」で変える。アンケートの URL が空なら、
 * テスト用アカウントの画面で決めたアンケートの URL を使う(どちらも空ならアンケートの行を出さない)。
 */
const KM_TUTORIAL_END_TEXT_SETTING = 'tutorial_end_text';
const KM_TUTORIAL_END_SURVEY_SETTING = 'tutorial_end_survey_url';
const KM_TUTORIAL_END_PHONE_SETTING = 'tutorial_end_phone_qr';
const KM_TUTORIAL_END_TEXT_DEFAULT = '行きたい場所を検索して、経路を調べてみてください。';
const KM_TUTORIAL_END_TEXT_MAX = 200;

/** 画像の種類は lib/raster-image.php の KM_RASTER_IMAGE_TYPES(SVG は入れない) */
const KM_POSTER_IMAGE_TYPES = KM_RASTER_IMAGE_TYPES;
/** 1 枚の上限。nginx の /admin/ は 12m(multipart の分の余裕を残す) */
const KM_POSTER_MAX_BYTES = 8 * 1024 * 1024;
/**
 * 縦横の上限(px)。**バイト数だけでは足りない** —— 小さな PNG でも 20000px 四方は作れ、
 * スマホが展開した瞬間にメモリが尽きる。A4 を 300dpi で取り込むと 2480×3508 なので、それは通す。
 */
const KM_POSTER_MAX_DIMENSION = 4096;
/** 外部から取りに行くときの待ち(秒)と、リダイレクトを辿る回数 */
const KM_POSTER_FETCH_TIMEOUT = 10;
const KM_POSTER_FETCH_REDIRECTS = 3;

/** 文字数の上限(表の列と揃える) */
const KM_POSTER_TITLE_MAX = 100;
const KM_POSTER_DESCRIPTION_MAX = 500;
const KM_POSTER_URL_MAX = 500;
const KM_NODE_LINK_LABEL_MAX = 100;

function km_posters_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_posters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            description VARCHAR(500) NULL,
            link_url VARCHAR(500) NULL,
            node_id VARCHAR(32) NULL,
            image_stored_name VARCHAR(64) NOT NULL,
            image_source_url VARCHAR(500) NULL,
            image_width INT NOT NULL,
            image_height INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (is_enabled, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_map_node_links (
            node_id VARCHAR(32) NOT NULL PRIMARY KEY,
            url VARCHAR(500) NOT NULL,
            label VARCHAR(100) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    /*
     * ポスターの企画の番号(2026-10-23、利用者の指示「番号でポスターなどを決める」。lib/map-node-projects.php)。
     * **km_posters に列を足さず、別表にする**(ALTER が無い)。番号があれば、その番号を振った地点に出す(地点の指定より先)
     */
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_poster_projects (
            poster_id INT NOT NULL PRIMARY KEY,
            project_no VARCHAR(16) NOT NULL,
            INDEX (project_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    // **成功した後に立てる**(lib/settings.php の km_settings_ensure_table と同じ理由)
    $done = true;
}

/** ポスターの企画の番号(poster_id => 番号)。表が無ければ空(公開の経路は表を作らない) */
function km_poster_projects_map(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT poster_id, project_no FROM km_poster_projects')->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['poster_id']] = (string) $row['project_no'];
    }
    return $map;
}

// ---------------------------------------------------------------- 出し方(3 段階)

/**
 * いまの出し方。**表を作らずに読む**(公開ページの経路)。読めなければ既定(出さない)。
 */
function km_poster_mode(PDO $pdo): string
{
    try {
        $statement = $pdo->prepare('SELECT value FROM km_settings WHERE name = ?');
        $statement->execute([KM_POSTER_MODE_SETTING]);
        $value = $statement->fetchColumn();
    } catch (Throwable $exception) {
        // km_settings がまだ無い(どの設定もまだ保存していない)
        return KM_POSTER_MODE_DEFAULT;
    }

    return is_string($value) && isset(KM_POSTER_MODES[$value]) ? $value : KM_POSTER_MODE_DEFAULT;
}

function km_poster_mode_save(PDO $pdo, string $mode): void
{
    if (!isset(KM_POSTER_MODES[$mode])) {
        throw new InvalidArgumentException('出し方は「表示しない」「イベント中のみ」「常に表示」から選んでください。');
    }
    require_once __DIR__ . '/settings.php';
    km_setting_set($pdo, KM_POSTER_MODE_SETTING, $mode);
}

/** 出し方と「いまイベント中か」から、ポスターを出すか。**判定はここ 1 箇所**(地図・一覧・画像の口が同じ答えを出す) */
function km_poster_mode_shows(string $mode, bool $eventActive): bool
{
    return $mode === 'always' || ($mode === 'event' && $eventActive);
}

/** いまイベント中か。判定は lib/map-events.php の式のまま(書き写さない) */
function km_poster_event_active(PDO $pdo): bool
{
    require_once __DIR__ . '/map-events.php';
    try {
        return km_map_event_active($pdo) !== [];
    } catch (Throwable $exception) {
        // イベントの表がまだ無い = イベント中ではない
        return false;
    }
}

/** いまポスターを出しているか(画像の口と一覧のページが使う。地図は km_map_data が重ね合わせから決める) */
function km_posters_showing_now(PDO $pdo): bool
{
    $mode = km_poster_mode($pdo);
    if ($mode === 'off') {
        return false;
    }

    return km_poster_mode_shows($mode, $mode === 'event' && km_poster_event_active($pdo));
}

// ---------------------------------------------------------------- 入力の確かめ

/**
 * リンクの URL として受けてよいか。**http / https だけ**(javascript: や data: を通さない)。
 * 空は「付けない」なので呼ぶ側で先に分ける。
 */
function km_poster_url_valid(string $url): bool
{
    if ($url === '' || strlen($url) > KM_POSTER_URL_MAX) {
        return false;
    }
    // 空白・制御文字を含むものは、表示やヘッダーで別の意味に化けうる
    if (preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
        return false;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    // https://user:pass@… は見た目と行き先がずれる(フィッシングの定番)
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    return (string) ($parts['host'] ?? '') !== '';
}

/**
 * 外部から取りに行ってよいアドレスか。**公開されているアドレスだけ。**
 * 私的な網(10/8・172.16/12・192.168/16 = Docker の網もここ)・127/8・169.254/16(雲のメタデータ)・
 * 100.64/10 などを弾く(FILTER_FLAG_GLOBAL_RANGE。PHP 8.2 から)。
 */
function km_poster_ip_allowed(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false;
}

/**
 * 画像の中身を確かめる。**拡張子や Content-Type は自己申告**なので、getimagesize の答えだけを信じる。
 *
 * @return array{ext:string, mime:string, width:int, height:int}
 */
function km_poster_image_check(string $bytes): array
{
    return km_raster_image_check($bytes, KM_POSTER_MAX_BYTES, KM_POSTER_MAX_DIMENSION);
}

/**
 * 選ばれたファイル($_FILES の 1 件)の中身。**本当にアップロードされたものか**を確かめてから読む。
 */
function km_poster_read_upload(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('画像のファイルが選ばれていません。');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('画像が大きすぎます。');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('アップロードに失敗しました(コード ' . $error . ')。');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('アップロードされたファイルを確認できませんでした。');
    }
    if ((int) ($file['size'] ?? 0) > KM_POSTER_MAX_BYTES) {
        throw new InvalidArgumentException('画像は ' . km_upload_format_size(KM_POSTER_MAX_BYTES) . ' 以内にしてください。');
    }
    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        throw new RuntimeException('アップロードされたファイルを読めませんでした。');
    }

    return $bytes;
}

/**
 * URL の画像を取りに行く。**取りに行く先は毎回確かめる**(リダイレクトの先も)。
 *
 * - 名前を引いた結果を CURLOPT_RESOLVE で固定する —— 確かめたあとで名前が別のアドレスを指す(DNS の付け替え)道を塞ぐ
 * - リダイレクトは curl に任せず自分で辿る(辿った先も km_poster_ip_allowed を通す)
 * - 受け取りながら数え、上限を超えたら打ち切る(巨大なファイルを全部読まない)
 * - $accept はページ(HTML)を読むときだけ変える(まとめての取り込み。lib/poster-import.php)
 */
function km_poster_fetch_remote(string $url, string $accept = 'image/png, image/jpeg, image/gif, image/webp'): string
{
    for ($hop = 0; $hop <= KM_POSTER_FETCH_REDIRECTS; $hop++) {
        if (!km_poster_url_valid($url)) {
            throw new InvalidArgumentException('画像の URL は http:// か https:// で始まる URL にしてください。');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port !== 80 && $port !== 443) {
            throw new InvalidArgumentException('画像の URL のポートは 80 か 443 にしてください。');
        }

        // IPv4 だけ引く(IPv6 の範囲の確かめまで持たない)。名前が IP そのものなら、それを確かめる
        $bareHost = trim($host, '[]');
        $addresses = filter_var($bareHost, FILTER_VALIDATE_IP) !== false ? [$bareHost] : (gethostbynamel($bareHost) ?: []);
        if ($addresses === []) {
            throw new InvalidArgumentException('画像の URL の名前を引けませんでした(' . $host . ')。');
        }
        foreach ($addresses as $address) {
            if (!km_poster_ip_allowed($address)) {
                throw new InvalidArgumentException('その URL は取り込めません(公開されていないアドレスを指しています)。');
            }
        }

        $body = '';
        $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RESOLVE => [$bareHost . ':' . $port . ':' . $addresses[0]],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => KM_POSTER_FETCH_TIMEOUT,
            CURLOPT_USERAGENT => 'KosenMap-PosterFetch/1.0',
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > KM_POSTER_MAX_BYTES) {
                    $tooLarge = true;
                    return 0;   // 0 を返すと curl が打ち切る
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $location = (string) (curl_getinfo($curl, CURLINFO_REDIRECT_URL) ?: '');
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($tooLarge) {
            throw new InvalidArgumentException('画像は ' . km_upload_format_size(KM_POSTER_MAX_BYTES) . ' 以内にしてください。');
        }
        if ($ok === false) {
            error_log('km_poster_fetch_remote: ' . $curlError);
            throw new InvalidArgumentException('画像を取りに行けませんでした(相手が応答しない・時間切れ)。');
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            $url = $location;
            continue;
        }
        if ($status !== 200) {
            // 応答の番号を code に載せる(まとめての取り込みが 404 =「その番号の店は無い」を見分ける。lib/poster-import.php)
            throw new InvalidArgumentException('画像を取りに行けませんでした(応答 ' . $status . ')。', $status);
        }

        return $body;
    }

    throw new InvalidArgumentException('リダイレクトが多すぎます。画像そのものの URL を入れてください。');
}

/** 画像を保存して保存名を返す。元の名前はパスに使わない(lib/uploads.php と同じ方針) */
function km_poster_store_image(string $bytes, string $ext): string
{
    $dir = km_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('保存先ディレクトリを作成できませんでした。');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('保存先ディレクトリに書き込めません。権限を確認してください。');
    }
    $storedName = 'poster_' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . $storedName, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($dir . DIRECTORY_SEPARATOR . $storedName);
        throw new RuntimeException('画像を保存できませんでした。');
    }

    return $storedName;
}

/** 保存名の形。**DB が汚れていてもパスをはみ出させない**(自分で採番した形しか通さない) */
function km_poster_stored_name_valid(string $storedName): bool
{
    return preg_match('/^poster_[0-9a-f]{32}\.(png|jpg|gif|webp)$/', $storedName) === 1;
}

function km_poster_remove_image(string $storedName): void
{
    if (!km_poster_stored_name_valid($storedName)) {
        return;
    }
    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (is_file($path) && !@unlink($path)) {
        error_log('km_poster_remove_image: file remains on disk: ' . $storedName);
    }
}

// ---------------------------------------------------------------- ポスター(管理画面)

/** @return array<int, array<string, mixed>> 一覧(有効・無効を問わず。並び順どおり) */
function km_posters_all(PDO $pdo): array
{
    km_posters_ensure_tables($pdo);

    $rows = $pdo->query(
        'SELECT id, title, description, link_url AS linkUrl, node_id AS nodeId,
                image_stored_name AS storedName, image_source_url AS sourceUrl,
                image_width AS width, image_height AS height,
                sort_order AS sortOrder, is_enabled AS isEnabled,
                UNIX_TIMESTAMP(updated_at) AS updatedEpoch
         FROM km_posters ORDER BY sort_order, id'
    )->fetchAll();
    $projects = km_poster_projects_map($pdo);

    return array_map(static fn (array $row): array => km_poster_normalize_row($row + ['projectNo' => $projects[(int) $row['id']] ?? '']), $rows);
}

function km_poster_find(PDO $pdo, int $id): ?array
{
    km_posters_ensure_tables($pdo);

    $statement = $pdo->prepare(
        'SELECT id, title, description, link_url AS linkUrl, node_id AS nodeId,
                image_stored_name AS storedName, image_source_url AS sourceUrl,
                image_width AS width, image_height AS height,
                sort_order AS sortOrder, is_enabled AS isEnabled,
                UNIX_TIMESTAMP(updated_at) AS updatedEpoch
         FROM km_posters WHERE id = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    return km_poster_normalize_row($row + ['projectNo' => km_poster_projects_map($pdo)[$id] ?? '']);
}

function km_poster_normalize_row(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['width'] = (int) $row['width'];
    $row['height'] = (int) $row['height'];
    $row['sortOrder'] = (int) $row['sortOrder'];
    $row['isEnabled'] = (bool) $row['isEnabled'];
    $row['updatedEpoch'] = (int) $row['updatedEpoch'];
    $row['description'] = (string) ($row['description'] ?? '');
    $row['linkUrl'] = (string) ($row['linkUrl'] ?? '');
    $row['nodeId'] = (string) ($row['nodeId'] ?? '');
    $row['sourceUrl'] = (string) ($row['sourceUrl'] ?? '');
    $row['projectNo'] = (string) ($row['projectNo'] ?? '');

    return $row;
}

/**
 * 画面の入力を確かめて整える。**DB を使わない**(scripts/check.php が試す)。
 *
 * @param array{title?:string, description?:string, linkUrl?:string, nodeId?:string, projectNo?:string, sortOrder?:string|int, isEnabled?:bool} $input
 * @return array{title:string, description:?string, linkUrl:?string, nodeId:?string, projectNo:?string, sortOrder:int, isEnabled:bool}
 */
function km_poster_validate(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $description = str_replace("\r\n", "\n", trim((string) ($input['description'] ?? '')));
    $linkUrl = trim((string) ($input['linkUrl'] ?? ''));
    $nodeId = trim((string) ($input['nodeId'] ?? ''));
    $sortOrder = trim((string) ($input['sortOrder'] ?? '0'));

    if ($title === '') {
        throw new InvalidArgumentException('見出しを入れてください(画像を読めない人にはこれが読まれます)。');
    }
    if (mb_strlen($title) > KM_POSTER_TITLE_MAX) {
        throw new InvalidArgumentException('見出しは ' . KM_POSTER_TITLE_MAX . ' 字までにしてください。');
    }
    if (mb_strlen($description) > KM_POSTER_DESCRIPTION_MAX) {
        throw new InvalidArgumentException('説明は ' . KM_POSTER_DESCRIPTION_MAX . ' 字までにしてください。');
    }
    if ($linkUrl !== '' && !km_poster_url_valid($linkUrl)) {
        throw new InvalidArgumentException('URL は http:// か https:// で始まる ' . KM_POSTER_URL_MAX . ' 字までの URL にしてください。');
    }
    // 地点の id は 32 字の 16 進(lib/app-map-convert.php の km_app_map_node_id)。在るかは保存の前に DB で見る
    if ($nodeId !== '' && preg_match('/^[0-9A-Za-z_-]{1,32}$/', $nodeId) !== 1) {
        throw new InvalidArgumentException('地点の指定が正しくありません。');
    }
    // 企画の番号(2026-10-23)。あれば、その番号を振った地点に出す(地点の指定より先)
    require_once __DIR__ . '/map-node-projects.php';
    $rawProject = trim((string) ($input['projectNo'] ?? ''));
    $projectNo = $rawProject === '' ? null : km_map_project_number($rawProject);
    if ($rawProject !== '' && $projectNo === null) {
        throw new InvalidArgumentException('企画の番号は英数字と - _ の ' . KM_MAP_PROJECT_NUMBER_MAX_LENGTH . ' 字までにしてください(例: A-1)。');
    }
    if (preg_match('/^-?\d{1,6}$/', $sortOrder) !== 1) {
        throw new InvalidArgumentException('並び順は整数にしてください(小さいほど前)。');
    }

    return [
        'title' => $title,
        'description' => $description === '' ? null : $description,
        'linkUrl' => $linkUrl === '' ? null : $linkUrl,
        'nodeId' => $nodeId === '' ? null : $nodeId,
        'projectNo' => $projectNo,
        'sortOrder' => (int) $sortOrder,
        'isEnabled' => (bool) ($input['isEnabled'] ?? false),
    ];
}

/** 地点が地図に在るか(消えた地点を指したまま保存しない) */
function km_poster_node_exists(PDO $pdo, string $nodeId): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM km_map_nodes WHERE id = ?');
    $statement->execute([$nodeId]);

    return $statement->fetchColumn() !== false;
}

/**
 * 保存する。画像は**新しく選んだときだけ**渡す($image)。編集で画像を変えなければ今の画像のまま。
 *
 * @param array{bytes:string, sourceUrl:?string}|null $image
 * @return int 保存したポスターの番号
 */
function km_poster_save(PDO $pdo, ?int $id, array $fields, ?array $image): int
{
    km_posters_ensure_tables($pdo);

    if ($fields['nodeId'] !== null && !km_poster_node_exists($pdo, $fields['nodeId'])) {
        throw new InvalidArgumentException('選んだ地点が地図に見つかりません。地図を取り込み直したなら、選び直してください。');
    }

    $current = $id === null ? null : km_poster_find($pdo, $id);
    if ($id !== null && $current === null) {
        throw new InvalidArgumentException('ポスターが見つかりません(消されたかもしれません)。');
    }
    if ($current === null && $image === null) {
        throw new InvalidArgumentException('画像を選んでください(ファイルか URL)。');
    }

    $newStored = null;
    $checked = null;
    if ($image !== null) {
        $checked = km_poster_image_check($image['bytes']);
        $newStored = km_poster_store_image($image['bytes'], $checked['ext']);
    }

    try {
        if ($current === null) {
            $pdo->prepare(
                'INSERT INTO km_posters (title, description, link_url, node_id, image_stored_name, image_source_url,
                                         image_width, image_height, sort_order, is_enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                $fields['title'], $fields['description'], $fields['linkUrl'], $fields['nodeId'],
                $newStored, $image['sourceUrl'] ?? null, $checked['width'], $checked['height'],
                $fields['sortOrder'], $fields['isEnabled'] ? 1 : 0,
            ]);

            $newId = (int) $pdo->lastInsertId();
            km_poster_project_save($pdo, $newId, $fields['projectNo'] ?? null);

            return $newId;
        }

        $pdo->prepare(
            'UPDATE km_posters SET title = ?, description = ?, link_url = ?, node_id = ?, sort_order = ?, is_enabled = ?,
                                   image_stored_name = ?, image_source_url = ?, image_width = ?, image_height = ?,
                                   updated_at = NOW()
             WHERE id = ?'
        )->execute([
            $fields['title'], $fields['description'], $fields['linkUrl'], $fields['nodeId'],
            $fields['sortOrder'], $fields['isEnabled'] ? 1 : 0,
            $newStored ?? $current['storedName'],
            $newStored !== null ? ($image['sourceUrl'] ?? null) : ($current['sourceUrl'] === '' ? null : $current['sourceUrl']),
            $checked['width'] ?? $current['width'],
            $checked['height'] ?? $current['height'],
            $id,
        ]);
        km_poster_project_save($pdo, $id, $fields['projectNo'] ?? null);
    } catch (Throwable $exception) {
        // DB に書けなかったら、置いたばかりの画像も残さない(どこからも指されない孤児を作らない)
        if ($newStored !== null) {
            km_poster_remove_image($newStored);
        }
        throw $exception;
    }

    // 差し替えた古い画像を片付ける(書き終えてから。先に消すと、失敗したときに画像の無いポスターが残る)
    if ($newStored !== null) {
        km_poster_remove_image((string) $current['storedName']);
    }

    return $id;
}

/** ポスターの企画の番号を決める(null は外す) */
function km_poster_project_save(PDO $pdo, int $posterId, ?string $projectNo): void
{
    $pdo->prepare('DELETE FROM km_poster_projects WHERE poster_id = ?')->execute([$posterId]);
    if ($projectNo !== null) {
        $pdo->prepare('INSERT INTO km_poster_projects (poster_id, project_no) VALUES (?, ?)')->execute([$posterId, $projectNo]);
    }
}

function km_poster_delete(PDO $pdo, int $id): string
{
    $poster = km_poster_find($pdo, $id);
    if ($poster === null) {
        throw new InvalidArgumentException('ポスターが見つかりません(もう消されたかもしれません)。');
    }
    $pdo->prepare('DELETE FROM km_posters WHERE id = ?')->execute([$id]);
    km_poster_project_save($pdo, $id, null);
    km_poster_stats_delete($pdo, $id);
    km_poster_remove_image((string) $poster['storedName']);

    return (string) $poster['title'];
}

function km_poster_set_enabled(PDO $pdo, int $id, bool $enabled): void
{
    km_posters_ensure_tables($pdo);
    $pdo->prepare('UPDATE km_posters SET is_enabled = ?, updated_at = NOW() WHERE id = ?')->execute([$enabled ? 1 : 0, $id]);
}

// ---------------------------------------------------------------- 公開ページ

/** 公開ページでの画像の URL。**番号と版だけ**(?v= は差し替えたときにキャッシュを捨てさせる) */
function km_poster_public_image_url(array $poster): string
{
    return '/api/poster-image.php?id=' . (int) $poster['id'] . '&v=' . (int) $poster['updatedEpoch'];
}

/**
 * 公開ページに出すポスター。**出し方の 3 段階を見て、出さないときは空。**表が無ければ空(作らない)。
 *
 * @param array<string, array> $nodes 地図の地点(km_map_data の nodes)。ここに無い・出さない(hidden)地点は外して
 *        nodeId を空にする —— 錠・イベントで見せていない地点の名前と場所を、ポスターから漏らさない
 * @param array<string, list<string>> $projectNodes 企画の番号 => 出している地点の id(2026-10-23。km_map_project_nodes)。
 *        番号を付けたポスターは、その番号を振った地点**全部**に出す(nodeIds)。番号の地点が無ければどこにも置かない(広告にもしない)
 * @return array<int, array{id:int, title:string, description:string, linkUrl:string, nodeId:string, nodeIds:list<string>, projectNo:string, imageUrl:string, width:int, height:int}>
 */
function km_posters_public(PDO $pdo, bool $eventActive, array $nodes, array $projectNodes = []): array
{
    if (!km_poster_mode_shows(km_poster_mode($pdo), $eventActive)) {
        return [];
    }
    try {
        $rows = $pdo->query(
            'SELECT id, title, description, link_url AS linkUrl, node_id AS nodeId,
                    image_width AS width, image_height AS height, UNIX_TIMESTAMP(updated_at) AS updatedEpoch
             FROM km_posters WHERE is_enabled = 1 ORDER BY sort_order, id'
        )->fetchAll();
    } catch (Throwable $exception) {
        // 表がまだ無い(管理画面の「ポスター・広告」をまだ開いていない)
        return [];
    }

    $projects = km_poster_projects_map($pdo);
    $posters = [];
    foreach ($rows as $row) {
        $projectNo = $projects[(int) $row['id']] ?? '';
        $nodeId = (string) ($row['nodeId'] ?? '');
        if ($nodeId !== '' && (!isset($nodes[$nodeId]) || !empty($nodes[$nodeId]['hidden']))) {
            $nodeId = '';
        }
        // 企画の番号があれば、番号の地点が置き場(地点の指定より先)。$projectNodes は出している地点だけ
        $nodeIds = $projectNo !== '' ? ($projectNodes[$projectNo] ?? []) : ($nodeId !== '' ? [$nodeId] : []);
        if ($projectNo !== '') {
            $nodeId = $nodeIds[0] ?? '';
        }
        $poster = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'linkUrl' => (string) ($row['linkUrl'] ?? ''),
            'nodeId' => $nodeId,
            'nodeIds' => $nodeIds,
            'projectNo' => $projectNo,
            /*
             * 広告か(**管理画面で地点に置いていない**もの)。上で外した地点(イベントで出さない・地図に無い)の
             * ポスターは広告にしない —— 地点を指したつもりのものが、確率で幕に出てくるのはおかしい。
             */
            'isAd' => (string) ($row['nodeId'] ?? '') === '' && $projectNo === '',
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'updatedEpoch' => (int) $row['updatedEpoch'],
        ];
        $poster['imageUrl'] = km_poster_public_image_url($poster);
        unset($poster['updatedEpoch']);
        $posters[] = $poster;
    }

    return $posters;
}

/**
 * 画像を返す(公開の口と管理画面の口が使う)。返せなければ false(呼ぶ側が 404 にする)。
 * Content-Type は**保存名の拡張子から決める**(保存のときに中身を見て決めてある)。
 */
function km_poster_send_image(array $poster, bool $publicCache): bool
{
    $storedName = (string) ($poster['storedName'] ?? '');
    if (!km_poster_stored_name_valid($storedName)) {
        error_log('km_poster_send_image: 保存名の形式が不正です: ' . $storedName);
        return false;
    }
    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path)) {
        error_log('km_poster_send_image: row exists but file is missing: ' . $storedName);
        return false;
    }
    $mime = 'application/octet-stream';
    $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    foreach (KM_POSTER_IMAGE_TYPES as [$allowed, $type]) {
        if ($ext === $allowed) {
            $mime = $type;
        }
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    // 公開の画像は ?v= が版なので長く持たせてよい。止めたときに残りすぎないよう 1 日にとどめる
    header('Cache-Control: ' . ($publicCache ? 'public, max-age=86400' : 'private, no-store'));
    readfile($path);

    return true;
}

// ---------------------------------------------------------------- 地点の URL

/**
 * 地点ごとの URL(地図の組み立てで使う)。**表を作らずに読む。**無ければ空。
 *
 * @return array<string, array{url:string, label:string}>
 */
function km_node_links_for_map(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT node_id AS nodeId, url, label FROM km_map_node_links')->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
    $links = [];
    foreach ($rows as $row) {
        // 表に入ったあとで条件を変えても、通らない値は出さない
        if (!km_poster_url_valid((string) $row['url'])) {
            continue;
        }
        $links[(string) $row['nodeId']] = ['url' => (string) $row['url'], 'label' => (string) ($row['label'] ?? '')];
    }

    return $links;
}

/** @return array<int, array{nodeId:string, url:string, label:string, updatedAt:string}> */
function km_node_links_all(PDO $pdo): array
{
    km_posters_ensure_tables($pdo);

    return $pdo->query(
        'SELECT node_id AS nodeId, url, COALESCE(label, \'\') AS label, updated_at AS updatedAt
         FROM km_map_node_links ORDER BY updated_at DESC'
    )->fetchAll();
}

function km_node_link_set(PDO $pdo, string $nodeId, string $url, string $label): void
{
    km_posters_ensure_tables($pdo);

    $url = trim($url);
    $label = trim($label);
    if ($nodeId === '' || !km_poster_node_exists($pdo, $nodeId)) {
        throw new InvalidArgumentException('地点を選んでください。');
    }
    if (!km_poster_url_valid($url)) {
        throw new InvalidArgumentException('URL は http:// か https:// で始まる ' . KM_POSTER_URL_MAX . ' 字までの URL にしてください。');
    }
    if (mb_strlen($label) > KM_NODE_LINK_LABEL_MAX) {
        throw new InvalidArgumentException('リンクの文字は ' . KM_NODE_LINK_LABEL_MAX . ' 字までにしてください。');
    }
    $pdo->prepare(
        'INSERT INTO km_map_node_links (node_id, url, label, updated_at) VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE url = VALUES(url), label = VALUES(label), updated_at = NOW()'
    )->execute([$nodeId, $url, $label === '' ? null : $label]);
}

function km_node_link_delete(PDO $pdo, string $nodeId): void
{
    km_posters_ensure_tables($pdo);
    $pdo->prepare('DELETE FROM km_map_node_links WHERE node_id = ?')->execute([$nodeId]);
}

/**
 * 管理画面で選べる地点(名前のあるもの。通路・壁・Wi-Fi は人が行く場所ではないので外す)。
 *
 * @return array<int, array{id:string, name:string, floor:string, type:string}>
 */
function km_poster_node_options(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, name, floor_id AS floor, type FROM km_map_nodes
         WHERE type NOT IN ('road', 'wall', 'wifi_router') AND TRIM(COALESCE(name, '')) <> ''
         ORDER BY floor_id, name"
    )->fetchAll();

    return array_map(static fn (array $row): array => [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'floor' => (string) $row['floor'],
        'type' => (string) $row['type'],
    ], $rows);
}

/** 階の表示(地図の詳細と同じ: 外 / 1F …) */
function km_poster_floor_label(string $floor): string
{
    return $floor === 'outside' ? '外' : $floor . 'F';
}

// ---------------------------------------------------------------- チュートリアルの終わりの知らせ

/** 設定を**表を作らずに**読む(公開ページの経路)。無ければ既定 */
function km_poster_setting_read(PDO $pdo, string $name, string $default): string
{
    try {
        $statement = $pdo->prepare('SELECT value FROM km_settings WHERE name = ?');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
    } catch (Throwable $exception) {
        return $default;
    }

    return is_string($value) ? $value : $default;
}

/**
 * チュートリアルの終わりに出す知らせの中身。
 *
 * @return array{text:string, ownSurveyUrl:string, surveyUrl:string, phoneQr:bool}
 *   ownSurveyUrl は管理画面で入れた値(空なら未設定)。surveyUrl は実際に出す URL(テスト用アカウントの画面の値へ落ちる)
 */
function km_tutorial_end_config(PDO $pdo): array
{
    require_once __DIR__ . '/test-mode.php';
    $text = trim(km_poster_setting_read($pdo, KM_TUTORIAL_END_TEXT_SETTING, ''));
    $own = trim(km_poster_setting_read($pdo, KM_TUTORIAL_END_SURVEY_SETTING, ''));
    $survey = $own !== '' ? $own : trim(km_poster_setting_read($pdo, KM_TEST_SURVEY_SETTING, ''));
    if ($survey !== '' && (!km_test_survey_url_valid($survey))) {
        $survey = '';
    }

    return [
        'text' => $text === '' ? KM_TUTORIAL_END_TEXT_DEFAULT : $text,
        'ownSurveyUrl' => $own,
        'surveyUrl' => $survey,
        'phoneQr' => km_poster_setting_read($pdo, KM_TUTORIAL_END_PHONE_SETTING, '1') !== '0',
    ];
}

/** 管理画面から保存する。アンケートの URL は https だけ(QR にしてスマホで開かせる。lib/test-mode.php と同じ確かめ) */
function km_tutorial_end_save(PDO $pdo, string $text, string $surveyUrl, bool $phoneQr): void
{
    require_once __DIR__ . '/test-mode.php';
    require_once __DIR__ . '/settings.php';
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    $surveyUrl = trim($surveyUrl);
    if (mb_strlen($text) > KM_TUTORIAL_END_TEXT_MAX) {
        throw new InvalidArgumentException('知らせの本文は ' . KM_TUTORIAL_END_TEXT_MAX . ' 字までにしてください。');
    }
    if (!km_test_survey_url_valid($surveyUrl)) {
        throw new InvalidArgumentException('アンケートの URL は https:// で始まる 255 字までの URL にしてください(空にするとテスト用アカウントの画面のものを使います)。');
    }
    km_setting_set($pdo, KM_TUTORIAL_END_TEXT_SETTING, $text);
    km_setting_set($pdo, KM_TUTORIAL_END_SURVEY_SETTING, $surveyUrl);
    km_setting_set($pdo, KM_TUTORIAL_END_PHONE_SETTING, $phoneQr ? '1' : '0');
}
// ---------------------------------------------------------------- 表示回数とリンクの人数(2026-10-21)

/**
 * 数える(利用者の指示「広告の表示回数を保存し、リンクを踏んだ人数も保存」)。
 *
 * **数だけを置く**(ポスター × 日 の行)。誰が見た・押したかは残さない(利用規約 第 7 条・プライバシーポリシー 2.)。
 * - 表示回数(impressions): 広告(地点なし)をチュートリアルの終わりに出した回数
 * - リンクの人数(clicks): ポスター・広告のリンクを押した数。**同じ端末は 1 人**と数える
 *   (端末の localStorage に「押した」を覚えて 2 度目を送らない + サーバーもセッションごとに 1 回だけ数える)
 */
const KM_POSTER_STAT_KINDS = ['impression', 'click'];
/** 同じセッションから同じ広告の表示を数える間(秒)。押し続けて数を膨らませない */
const KM_POSTER_STAT_IMPRESSION_GAP = 60;

function km_poster_stats_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_poster_stats (
            poster_id INT NOT NULL,
            day DATE NOT NULL,
            impressions INT NOT NULL DEFAULT 0,
            clicks INT NOT NULL DEFAULT 0,
            PRIMARY KEY (poster_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $done = true;
}

/**
 * セッションの覚えを見て、数えてよいか。**DB を使わない**(scripts/check.php が試す)。数えるなら覚えを書き換える。
 *
 * @param array<string, mixed> $memory セッションの km_poster_stat(参照で書き換える)
 */
function km_poster_stat_allowed(array &$memory, int $posterId, string $kind, int $now): bool
{
    if ($kind === 'click') {
        if (!empty($memory['click'][$posterId])) {
            return false;
        }
        $memory['click'][$posterId] = true;

        return true;
    }
    $last = (int) ($memory['impression'][$posterId] ?? 0);
    if ($last > 0 && $now - $last < KM_POSTER_STAT_IMPRESSION_GAP) {
        return false;
    }
    $memory['impression'][$posterId] = $now;

    return true;
}

/**
 * 1 回ぶん数える。**いま公開ページに出しているポスターだけ**(止めた・出し方が「表示しない」のものは数えない)。
 * 表示回数は広告(地点なし)だけ。数えなかったら false。
 */
function km_poster_stat_record(PDO $pdo, int $posterId, string $kind): bool
{
    if (!in_array($kind, KM_POSTER_STAT_KINDS, true) || $posterId <= 0 || !km_posters_showing_now($pdo)) {
        return false;
    }
    $statement = $pdo->prepare('SELECT node_id FROM km_posters WHERE id = ? AND is_enabled = 1');
    $statement->execute([$posterId]);
    $nodeId = $statement->fetchColumn();
    if ($nodeId === false) {
        return false;
    }
    if ($kind === 'impression' && (string) ($nodeId ?? '') !== '') {
        return false;   // 地点に置いたポスターは広告として出さない
    }

    km_poster_stats_ensure_table($pdo);
    $column = $kind === 'impression' ? 'impressions' : 'clicks';
    $pdo->prepare(
        "INSERT INTO km_poster_stats (poster_id, day, impressions, clicks) VALUES (?, CURDATE(), ?, ?)
         ON DUPLICATE KEY UPDATE {$column} = {$column} + 1"
    )->execute([$posterId, $kind === 'impression' ? 1 : 0, $kind === 'click' ? 1 : 0]);

    return true;
}

/**
 * 管理画面の数。ポスターごとの合計と、直近 7 日(今日を含む)。
 *
 * @return array<int, array{impressions:int, clicks:int, impressions7:int, clicks7:int}>
 */
function km_poster_stats_totals(PDO $pdo): array
{
    km_poster_stats_ensure_table($pdo);
    $rows = $pdo->query(
        'SELECT poster_id AS posterId, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
                SUM(CASE WHEN day >= CURDATE() - INTERVAL 6 DAY THEN impressions ELSE 0 END) AS impressions7,
                SUM(CASE WHEN day >= CURDATE() - INTERVAL 6 DAY THEN clicks ELSE 0 END) AS clicks7
         FROM km_poster_stats GROUP BY poster_id'
    )->fetchAll();
    $totals = [];
    foreach ($rows as $row) {
        $totals[(int) $row['posterId']] = [
            'impressions' => (int) $row['impressions'],
            'clicks' => (int) $row['clicks'],
            'impressions7' => (int) $row['impressions7'],
            'clicks7' => (int) $row['clicks7'],
        ];
    }

    return $totals;
}

/** ポスターを消したら、その数も消す */
function km_poster_stats_delete(PDO $pdo, int $posterId): void
{
    km_poster_stats_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_poster_stats WHERE poster_id = ?')->execute([$posterId]);
}