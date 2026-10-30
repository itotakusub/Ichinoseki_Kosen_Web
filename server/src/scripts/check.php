<?php

declare(strict_types=1);

/**
 * 自己検査の入口。**DB も Logto も Web サーバーも要らない**範囲で、
 * 取り違えたときの被害が大きい判定だけを固定する。
 *
 *   php src/scripts/check.php                      # 純粋な検査すべて(既定)
 *   php src/scripts/check.php app-map              # 名前で絞る
 *   php src/scripts/check.php user-stats app-map   # 複数指定も可
 *   php src/scripts/check.php recaptcha            # 環境依存。明示したときだけ走る
 *   php src/scripts/check.php --list               # 何があるか見る
 *
 * Docker の中なら:
 *   docker compose exec -T web php scripts/check.php
 *
 * ## 2026-08-29 の統合について
 *
 * もとは check-user-stats.php / check-app-map.php / check-app-ranking.php /
 * check-recaptcha.php の4本に分かれていた。**どれを走らせればよいか毎回迷う**ので
 * ここへまとめた。中身の検査項目は変えていない。
 *
 * ## 2種類ある
 *
 * | 区分 | 中身 | 既定で走るか |
 * |---|---|---|
 * | 純粋 | 引数と戻り値だけを見る。何度走らせても同じ | **走る** |
 * | 環境依存 | 置かれている設定を読み、外へ問い合わせることもある | **走らない**(名前を書いたときだけ) |
 *
 * 環境依存を既定から外してあるのは、**手元では設定が揃っていないのが正常**だから。
 * 混ぜると「未設定」と「壊れている」が同じ失敗に見えて、区別できなくなる。
 *
 * ## mbstring について
 *
 * app-ranking は語の長さを**文字数**で数えるので mbstring が要る。
 * php:8.4-apache には同梱されているが、素の Windows ビルドでは無効なことがある:
 *   php -d extension_dir=<php>\ext -d extension=mbstring src/scripts/check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** 引数なしで走る検査。純粋(外に何も問い合わせない)。 */
const KM_CHECK_PURE = [
    'user-stats',
    'app-map',
    'app-ranking',
    'recaptcha-messages',
    'map-events',
    'map-access',
    'admin-log',
    'app-map-convert',
    'app-map-web-events',
    'admin-log-user-agent',
    'seed-once',
    'staff-org',
    'staff-org-map',
    'staff-org-app',
    'staff-nodes',
    'map-vertical',
    'building-floors',
    'ssh-notify',
    'github-mirror',
    'route-weights',
    // 診断 2026-09-25(docs/16)の所見を直したこと
    'review-0925',
    // お試しの閲覧リンク(2026-09-30)
    'guest-links',
    // 1 台のルーターの複数 BSSID(2026-10-02)
    'router-bssids',
    // 地図の北と距離の補正を配る(2026-10-05)
    'map-calibration',
    // アプリの測位のパラメータを配る(2026-10-06)
    'positioning-params',
    // Wi-Fi の学習データと評価をサーバーに置く(2026-10-06)
    'learning-data',
    // AR 実測の記録と画像(2026-10-06)
    'ar-capture',
    // アプリの自動更新(2026-10-05)
    'app-update',
    'ssh-roles',
    'account-delete',
    'legal',
    'self',
    'update-notice',
    'security-notice',
    'log-notice',
    'backup-notice',
    'powershell',
    'shell',
    'notebooks',
    'security-review',
    'hardening',
    'layout',
    'route',
    'qr',
];

/** 名前を明示したときだけ走る検査。置かれている設定を読む。 */
const KM_CHECK_ENVIRONMENT = ['recaptcha'];

$failures = 0;
$checks = 0;
$skipped = 0;

/**
 * 手元の作業ツリーの根。**ここでしか調べられないものがある。**
 *
 * 自己検査はホストでも走る —— `host-setup.sh` が web コンテナの中から呼ぶ。
 * ところがコンテナに見えているのは `src/` だけで、`scripts/` も `Old/` も無い
 * (`deploy-to-host.ps1` は `.sh` を `$include` に名指しした分だけ置き、`Old/` は送らない)。
 *
 * そこを素で見に行くと、**配備のたびに必ず FAIL が並ぶ。**
 * 毎回出る FAIL は読まれなくなり、**本物の失敗がその中に埋もれる**
 * (実際にそうなった、2026-09-05)。
 *
 * @return string|null 手元の作業ツリーなら根のパス、配備先なら null
 */
function km_check_repo_root(): ?string
{
    $root = __DIR__ . '/../..';

    return is_dir($root . '/scripts') && is_dir($root . '/Old') ? $root : null;
}

/**
 * 調べられないものを飛ばす。**黙って飛ばさない。**
 *
 * 出さずに飛ばすと「出ていない = 通った」と読まれる。
 * SKIP と書き、どこでなら調べられるかまで出す。
 */
function check_skip(string $label, string $where): void
{
    global $skipped;
    $skipped++;
    printf("  %-56s SKIP  (%s)\n", $label, $where);
}

/** 値が一致するかを見る。 */
function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    $ok = $expected === $actual;
    if (!$ok) {
        $failures++;
    }
    printf(
        "  %-56s %s%s\n",
        $label,
        $ok ? 'PASS' : 'FAIL',
        $ok ? '' : '  期待=' . var_export($expected, true) . ' 実際=' . var_export($actual, true)
    );
}

/** 真偽そのものを見る(一致では書きにくいもの)。 */
function check_bool(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
    }
    printf("  %-56s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail !== '' ? "  ({$detail})" : '');
}

function km_check_heading(string $title): void
{
    echo "\n== {$title} ==\n";
}

// ===================================================================== user-stats

/**
 * lib/user-stats.php の判定部分。
 *
 * 数える処理そのものは DB と Logto が要るのでここでは触らない。
 * **取り違えると人数が丸ごとずれる**のはドメイン判定と識別子の検証なので、そこだけを固定する。
 */
function km_check_user_stats(): void
{
    require_once __DIR__ . '/../lib/user-stats.php';

    km_check_heading('user-stats: メールのドメイン');
    check('メールからドメインを取る', 'example.ac.jp', km_user_stats_email_domain('a@example.ac.jp'));
    check('大文字は小文字に畳む', 'example.ac.jp', km_user_stats_email_domain('A@Example.AC.JP'));
    check('+付きでもドメインは同じ', 'example.ac.jp', km_user_stats_email_domain('a+tag@example.ac.jp'));
    check('@ が複数あれば最後を使う', 'example.ac.jp', km_user_stats_email_domain('"a@b"@example.ac.jp'));
    check('@ が無ければ空', '', km_user_stats_email_domain('notanemail'));
    check('メールが空なら空', '', km_user_stats_email_domain(''));

    $allowed = ['example.ac.jp', 'kosen.jp'];

    km_check_heading('user-stats: 組織内かどうか');
    check('完全一致は組織内', true, km_user_stats_domain_matches('example.ac.jp', $allowed));
    check('サブドメインも組織内', true, km_user_stats_domain_matches('sub.example.ac.jp', $allowed));
    check('深いサブドメインも組織内', true, km_user_stats_domain_matches('a.b.example.ac.jp', $allowed));
    check('別ドメインは組織外', false, km_user_stats_domain_matches('gmail.com', $allowed));
    // ここが緩いと、似た名前のドメインを取った相手が組織内に化ける。
    check('末尾が似ているだけは組織外', false, km_user_stats_domain_matches('notexample.ac.jp', $allowed));
    check('前方一致は組織外', false, km_user_stats_domain_matches('example.ac.jp.evil.com', $allowed));
    check('空のドメインは組織外', false, km_user_stats_domain_matches('', $allowed));
    check('許可一覧が空なら常に組織外', false, km_user_stats_domain_matches('example.ac.jp', []));

    $members = ['u-in-org' => true];

    check(
        'Organization に居れば、メールが外部でも組織内',
        true,
        km_user_stats_is_inside('u-in-org', 'someone@gmail.com', $members, $allowed)
    );
    check(
        'Organization に居なくても、ドメインが合えば組織内',
        true,
        km_user_stats_is_inside('u-other', 'a@example.ac.jp', $members, $allowed)
    );
    check(
        'どちらでもなければ組織外',
        false,
        km_user_stats_is_inside('u-other', 'a@gmail.com', $members, $allowed)
    );
    check(
        'メール未設定で組織にも居なければ組織外',
        false,
        km_user_stats_is_inside('u-other', '', $members, $allowed)
    );

    km_check_heading('user-stats: 端末の識別子');
    check('普通の識別子は通る', 'abcdef01-2345-6789', km_user_stats_normalize_client_id('abcdef01-2345-6789'));
    check('前後の空白は落とす', 'abcdefgh', km_user_stats_normalize_client_id('  abcdefgh  '));
    check('短すぎるものは弾く', '', km_user_stats_normalize_client_id('abc'));
    check('空は弾く', '', km_user_stats_normalize_client_id(''));
    check('記号入りは弾く', '', km_user_stats_normalize_client_id("abcdefgh'; DROP TABLE--"));
    check('長すぎるものは弾く', '', km_user_stats_normalize_client_id(str_repeat('a', 65)));
    check('64文字ちょうどは通る', str_repeat('a', 64), km_user_stats_normalize_client_id(str_repeat('a', 64)));

    km_check_heading('user-stats: m2m の選択');
    // **'readonly' には戻さないこと。** Management API のスコープは `all` だけで、
    // 読み取り専用の資格情報は 403 になる。それを guard が拾ってフェイルクローズし、
    // 管理画面が全面停止した(2026-08-28)。lib/logto-management.php の説明を参照。
    check('人数集計は既定の資格情報を使う', 'default', KM_USER_STATS_M2M_PROFILE);
    check('プロファイル名は既知のもの', true, in_array(KM_USER_STATS_M2M_PROFILE, KM_LOGTO_M2M_PROFILES, true));

    /*
     * 停止判定が readonly で失敗したら default へ落ちること。
     *
     * ここが無いと、補助的な資格情報が壊れただけで **管理画面が全面停止する**
     * (2026-08-28 に実際に起きた。Logto Console も同じゲートの内側なので直しに行けない)。
     *
     * **これは「落とし込みのコードが在るか」しか見ていない。** 実際に効くかは、
     * readonly をわざと壊した状態で管理画面が開けるかを**本番へ出す前にホストで**確かめること。
     */
    $management = (string) file_get_contents(__DIR__ . '/../lib/logto-management.php');
    check_bool(
        '停止判定は readonly で失敗したら default で試し直す',
        str_contains($management, "km_logto_management_get('users/' . rawurlencode(\$userId), [], 'default')"),
        'lib/logto-management.php'
    );
    check_bool(
        '落とし込みは画面にも出す(ログだけでは気付けない)',
        function_exists('km_logto_m2m_fallback_notice')
    );
    check('警告は既定では出ていない', null, km_logto_m2m_fallback_notice());

    km_check_heading('user-stats: 公開の口が Logto に触らないこと');
    /*
     * 未ログインの経路から Management API への線が復活していないかを固定する。
     * ここが緩むと、キャッシュ切れの瞬間に**未ログインの GET が Logto を数十往復待つ**
     * 経路が戻る(2026-08-29 に切り離した)。
     *
     * 引数の形だけを見る —— 実際に叩くには DB と Logto が要るため。
     */
    $directory = new ReflectionFunction('km_user_stats_directory');
    $parameters = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        $directory->getParameters()
    );
    check('第3引数は $mayRefresh', 'mayRefresh', $parameters[2] ?? '(無し)');
    check('既定は更新してよい(管理側の呼び出しを壊さない)', true, $directory->getParameters()[2]->getDefaultValue());

    $endpoint = (string) file_get_contents(__DIR__ . '/../api/app-stats.php');
    check_bool(
        '公開の口は $signedIn を渡している',
        str_contains($endpoint, 'km_user_stats_directory($pdo, false, $signedIn)'),
        'api/app-stats.php'
    );
    check_bool(
        '$signedIn はトークンの sub から決まる',
        str_contains($endpoint, '$signedIn = $subject !== \'\';'),
        'api/app-stats.php'
    );
    check_bool(
        'refreshedAt を返している(stale だけでは古さが分からない)',
        str_contains($endpoint, "'refreshedAt' => \$directory['refreshedAt']"),
        'api/app-stats.php'
    );

    // 組織数の二重取得が戻っていないこと。
    $members = new ReflectionFunction('km_user_stats_organization_members');
    check_bool(
        '組織数はメンバー取得のついでに数える',
        ($members->getParameters()[0] ?? null)?->isPassedByReference() === true,
        'km_user_stats_organization_members の第1引数が参照渡し'
    );
    check_bool(
        '集計は organization_count を呼ばない',
        !str_contains(
            (string) file_get_contents(__DIR__ . '/../lib/user-stats.php'),
            "'organizations' => km_user_stats_organization_count()"
        )
    );
}

// ======================================================================= app-map

/**
 * lib/app-map.php の検証。
 *
 * 最後に出力するパッケージは、Android 側の MapPackageTest が
 * 「サーバーが実際に出す形」として固定している。**ここの出力を変えたら
 * app/src/test/java/com/ito/kosenmap/MapPackageTest.kt の serverProducedPackage も
 * 更新すること。**片方だけ直すと、端末が読めない形を配ってしまう。
 *
 * @return string MapPackageTest に貼る値
 */
function km_check_app_map(): string
{
    require_once __DIR__ . '/../lib/app-map.php';

    km_check_heading('app-map: アクセスコードの正規化');
    // Android の normalizeAccessCode と同じ結果になること。
    check_bool('前後空白とハイフン', km_app_map_normalize_code('  abcd-1234 ') === 'ABCD1234');
    check_bool('アンダースコア', km_app_map_normalize_code('ABCD_1234') === 'ABCD1234');
    check_bool('空白区切り', km_app_map_normalize_code('ab cd 12 34') === 'ABCD1234');
    check_bool('区切りだけなら空', km_app_map_normalize_code(' -- __ ') === '');

    km_check_heading('app-map: コードから配信 ID を引く');
    $codeConfig = [
        'codes' => [
            ['slug' => 'kosen-main', 'hash' => password_hash('KOSEN2026', PASSWORD_DEFAULT)],
            ['slug' => 'broken', 'hash' => null],
        ],
    ];
    check_bool('正しいコード', km_app_map_resolve_slug('KOSEN2026', $codeConfig) === 'kosen-main');
    check_bool('誤ったコード', km_app_map_resolve_slug('NOPE', $codeConfig) === null);
    check_bool('hash が null の項目は無視', km_app_map_resolve_slug('', $codeConfig) === null);

    km_check_heading('app-map: 置き場から抜け出さない');
    $storage = ['storageDir' => __DIR__];
    check_bool('親ディレクトリ参照を拒否', km_app_map_storage_path($storage, '../lib/app-map.php') === null);
    check_bool('絶対パスを拒否', km_app_map_storage_path($storage, 'C:\\Windows\\win.ini') === null);
    check_bool('空を拒否', km_app_map_storage_path($storage, '') === null);
    check_bool('実在するファイルは解決できる', km_app_map_storage_path($storage, basename(__FILE__)) !== null);

    km_check_heading('app-map: 配布ファイルの読み取り');
    $rawSnapshot = '{"version":8,"nodes":[],"lines":[]}';
    check_bool('素のスナップショット', km_app_map_decode_snapshot($rawSnapshot) instanceof stdClass);
    check_bool(
        '転送エンベロープの中身を取り出す',
        (km_app_map_decode_snapshot('{"format":"kosenmap-map","formatVersion":2,"map":' . $rawSnapshot . '}')?->version ?? null) === 8
    );
    check_bool('nodes/lines が無ければ null', km_app_map_decode_snapshot('{"version":8}') === null);
    check_bool('JSON でなければ null', km_app_map_decode_snapshot('nope') === null);

    km_check_heading('app-map: staffOnly の除去');
    $withStaff = km_app_map_decode_snapshot(json_encode([
        'version' => 8,
        'nodes' => [],
        'lines' => [],
        'events' => [[
            'uuid' => 'ev-1',
            'name' => '文化祭',
            'closedLineKeys' => ['1F:a:b'],
            'closedNodeUuids' => ['n1'],
            'places' => [
                ['uuid' => 'p1', 'name' => '模擬店', 'staffOnly' => false],
                ['uuid' => 'p2', 'name' => '本部', 'staffOnly' => true],
            ],
        ]],
    ]));
    $stripped = km_app_map_strip_staff_only($withStaff);
    $places = $stripped->events[0]->places;
    check_bool('1件だけ残る', count($places) === 1, 'count=' . count($places));
    check_bool('残ったのは来場者向け', ($places[0]->uuid ?? '') === 'p1');
    check_bool('通行止めは消さない', ($stripped->events[0]->closedLineKeys[0] ?? '') === '1F:a:b');
    check_bool(
        '除去後もリストのまま(オブジェクト化しない)',
        str_contains((string) json_encode($stripped), '"places":[{')
    );

    /*
     * 教職員氏名の除去。**ここが配信に載らない唯一の歯止め。**
     *
     * 氏名はアプリの地図が正本で(MAP_DATA_VERSION 10 で備考から移した)、
     * 実際の校舎で 65 件入っている。アプリ側で隠しても、**配ってしまえば
     * 改造アプリからは見える** —— 出さないことでしか守れない。
     *
     * 通ってしまったときの壊れ方が「来場者全員に先生の名前が渡る」なので、
     * 落ちたことに気づける形で固定しておく。
     */
    km_check_heading('app-map: 教職員氏名の除去');
    $withNames = km_app_map_decode_snapshot(json_encode([
        'version' => 10,
        'nodes' => [
            ['uuid' => 'n1', 'title' => '教員室', 'subtitle' => '1-106',
                'type1' => 'room', 'floor' => '1F', 'x' => 1, 'y' => 2,
                'occupantName' => '架空 一郎 先生'],
            // 氏名のキーを持たないノード。**落ちないこと**
            ['uuid' => 'n2', 'title' => '通路', 'subtitle' => '',
                'type1' => 'road', 'floor' => '1F', 'x' => 3, 'y' => 4],
        ],
        'lines' => [],
    ]));
    km_app_map_strip_occupant_names($withNames);
    check_bool('氏名は null になる', $withNames->nodes[0]->occupantName === null);
    check_bool('氏名を持たないノードも null で揃う', $withNames->nodes[1]->occupantName === null);
    $strippedJson = (string) json_encode($withNames);
    // **キーごと消さない。** 端末側は全ノードが同じ形である前提(serializeNulls)
    check_bool('キーは残す(形を揃える)', str_contains($strippedJson, '"occupantName":null'));
    check_bool('氏名の文字列がどこにも残らない', !str_contains($strippedJson, '架空'));
    // 配信の入口が呼ぶのはこちら。**氏名の除去がここから外れていないこと**
    $viaStaffOnly = km_app_map_decode_snapshot(json_encode([
        'version' => 10,
        'nodes' => [['uuid' => 'n1', 'title' => '教員室', 'subtitle' => '1-106',
            'type1' => 'room', 'floor' => '1F', 'x' => 1, 'y' => 2,
            'occupantName' => '架空 花子 先生']],
        'lines' => [],
    ]));
    check_bool(
        'staffOnly の除去からも氏名が落ちる',
        km_app_map_strip_staff_only($viaStaffOnly)->nodes[0]->occupantName === null
    );

    /*
     * 教員室・研究室を落とす(2026-10-22、Android 側の厳格化)。Web と同じ判定(種類 2・名前・番号のどれか)。
     * 線・精度評価・イベントの名前の差し替えも一緒に落とす(アプリは線の両端が地図に在ることを確かめていて、無いと地図ごと読めない)
     */
    km_check_heading('app-map: 教員室・研究室の除去');
    $withRestricted = km_app_map_decode_snapshot(json_encode([
        'version' => 10,
        'nodes' => [
            ['uuid' => 'r1', 'title' => '教員室', 'subtitle' => '1-106', 'type1' => 'room', 'type2' => '', 'floor' => '1F', 'x' => 1, 'y' => 2, 'occupantName' => '架空 一郎 先生'],
            ['uuid' => 'r2', 'title' => '1-201', 'subtitle' => '機械研究室', 'type1' => 'room', 'type2' => '', 'floor' => '2F', 'x' => 1, 'y' => 2],
            ['uuid' => 'r3', 'title' => '1-301', 'subtitle' => '', 'type1' => 'room', 'type2' => '教員室', 'floor' => '3F', 'x' => 1, 'y' => 2],
            ['uuid' => 'c1', 'title' => '通路', 'subtitle' => '', 'type1' => 'road', 'type2' => '', 'floor' => '1F', 'x' => 3, 'y' => 4],
            ['uuid' => 'c2', 'title' => '教室', 'subtitle' => '1-107', 'type1' => 'room', 'type2' => '', 'floor' => '1F', 'x' => 5, 'y' => 6],
        ],
        'lines' => [
            ['startNodeUuid' => 'c1', 'endNodeUuid' => 'r1'],
            ['startNodeUuid' => 'c1', 'endNodeUuid' => 'c2'],
        ],
        'fingerprints' => [['nodeUuid' => 'r1', 'x' => 1, 'y' => 2, 'floor' => '1F', 'rssiByBssid' => ['aa' => -50]]],
        'evaluations' => [['nodeUuid' => 'r1'], ['nodeUuid' => 'c2']],
        'events' => [['uuid' => 'e', 'name' => '文化祭', 'nameOverrides' => ['r1' => '架空の控室', 'c2' => '模擬店'], 'hiddenNodeUuids' => ['r1', 'c2'], 'closedNodeUuids' => ['r2'], 'places' => []]],
    ]));
    $removedUuids = km_app_map_strip_restricted_nodes($withRestricted);
    sort($removedUuids);
    check('教員室・研究室(名前・番号・種類 2)を落とす', ['r1', 'r2', 'r3'], $removedUuids);
    check('ほかの地点は残す', ['c1', 'c2'], array_map(static fn ($n) => $n->uuid, $withRestricted->nodes));
    check('落とした地点の線を落とす', 1, count($withRestricted->lines));
    check('落とした地点の精度評価を落とす', ['c2'], array_map(static fn ($e) => $e->nodeUuid, $withRestricted->evaluations));
    check('Wi-Fi の学習は残す(名前を持たない)', 1, count($withRestricted->fingerprints));
    $restrictedJson = (string) json_encode($withRestricted->events, JSON_UNESCAPED_UNICODE);
    check_bool('イベントの名前の差し替え・隠す・通行止めからも消える', !str_contains((string) json_encode($withRestricted, JSON_UNESCAPED_UNICODE), '架空') && !str_contains($restrictedJson, '"r1"') && !str_contains($restrictedJson, '"r2"')
        && str_contains($restrictedJson, '"c2":"模擬店"') && str_contains($restrictedJson, '"hiddenNodeUuids":["c2"]') && str_contains($restrictedJson, '"closedNodeUuids":[]'));
    check('教員室が無ければ何もしない', [], km_app_map_strip_restricted_nodes(km_app_map_decode_snapshot('{"version":10,"nodes":[{"uuid":"a","title":"教室"}],"lines":[]}')));
    $namedMap = km_app_map_decode_snapshot('{"version":10,"nodes":[{"uuid":"a","occupantName":"架空 一"},{"uuid":"b","occupantName":"架空 二"}],"lines":[]}');
    km_app_map_keep_occupant_names_for($namedMap, ['b']);
    check_bool('氏名は許可した地点だけ残す', $namedMap->nodes[0]->occupantName === null && $namedMap->nodes[1]->occupantName === '架空 二');

    $withEmptyObject = km_app_map_decode_snapshot('{"version":8,"nodes":[],"lines":[],"extra":{}}');
    check_bool(
        '{} が [] に化けない',
        str_contains((string) json_encode($withEmptyObject), '"extra":{}')
    );

    /*
     * 配信中の地図の期限。**切れたことが、これまでどこにも出ていなかった。**
     * 2026-08-27 に失効したのに気づかれず、記録文書にだけ残り続けた。
     *
     * 時刻を引数で渡せるようにしてあるので、境目をそのまま確かめられる。
     */
    km_check_heading('app-map: 配信の期限');
    $now = new DateTimeImmutable('2026-08-30T12:00:00+09:00');
    $release = static fn (?string $expiresAt): array => km_app_map_release_status(
        ['maps' => ['kosen-main' => ['revision' => 6, 'expiresAt' => $expiresAt]]],
        $now
    )[0];

    $expired = $release('2026-08-27T20:22:04+09:00');
    check_bool('過ぎていれば期限切れ', $expired['expired']);
    check_bool('期限切れは警告する', $expired['warn']);

    $soon = $release('2026-09-05T12:00:00+09:00');
    check_bool('まだ切れていない', !$soon['expired']);
    check('残り日数を数える', 6, $soon['daysLeft']);
    check_bool('猶予を切っていれば警告する', $soon['warn']);

    $healthy = $release('2026-12-01T12:00:00+09:00');
    check_bool('余裕があれば警告しない', !$healthy['warn']);
    check_bool('余裕があっても期限切れではない', !$healthy['expired']);

    // **読めない値を「大丈夫」と扱わない。** 書き損じで配信が止まっても画面が無言になる
    $unknown = $release('');
    check_bool('期限が空なら不明として扱う', $unknown['unknown']);
    check_bool('不明は警告する', $unknown['warn']);
    check_bool('壊れた文字列も不明', $release('いつか')['unknown']);

    check('猶予は14日', 14, KM_APP_MAP_EXPIRY_WARN_DAYS);

    /*
     * 配信ファイルの要約。**「期限が切れているか」だけでは上げ間違いに気づけない。**
     * 設定の revision は書き換わったのに実体が古いまま、という形が実際に疑われている。
     */
    km_check_heading('app-map: 配信ファイルの要約');
    $snapshot = km_app_map_decode_snapshot(json_encode([
        'version' => 10,
        'nodes' => [
            ['uuid' => 'n1', 'occupantName' => '架空 一郎 先生'],
            ['uuid' => 'n2', 'occupantName' => null],
            // **空白だけは「入っていない」。** 数え方がずれると、失った氏名に気づけない
            ['uuid' => 'n3', 'occupantName' => '   '],
        ],
        'lines' => [['floor' => '1F'], ['floor' => '2F']],
        'fingerprints' => [['nodeUuid' => 'n1']],
        'overlays' => [],
        'events' => [],
    ]));
    $summary = km_app_map_snapshot_summary($snapshot);
    check_bool('読めた', $summary['readable']);
    check('地図の版', 10, $summary['mapVersion']);
    check('地点を数える', 3, $summary['nodeCount']);
    check('経路を数える', 2, $summary['lineCount']);
    check('氏名は空白を数えない', 1, $summary['occupantCount']);
    check('指紋を数える', 1, $summary['fingerprintCount']);
    // **読めないものを 0 件と混同しない。** どちらも「氏名 0」に見えるが意味が違う
    check_bool('読めなければ readable=false', !km_app_map_snapshot_summary(null)['readable']);

    /*
     * 配信に載せているイベントの会期。
     *
     * アプリ側の activeMapEvent() は**期間外を黙って null にする**ので、
     * 設定した側からは「効いていない」のか「終わっている」のか分からない。
     */
    km_check_heading('app-map: 載せているイベントの会期');
    $eventNow = new DateTimeImmutable('2026-09-02T12:00:00+09:00');
    $events = [json_decode(json_encode([
        'uuid' => 'ev-1',
        'name' => '高専祭',
        'startAtMillis' => (new DateTimeImmutable('2026-09-01T09:00:00+09:00'))->getTimestamp() * 1000,
        'endAtMillis' => (new DateTimeImmutable('2026-09-03T17:00:00+09:00'))->getTimestamp() * 1000,
    ]))];

    $running = km_app_map_active_event_status($events, 'ev-1', $eventNow);
    check_bool('会期中', $running['active']);
    check('名前を出す', '高専祭', $running['name']);

    $ended = km_app_map_active_event_status(
        $events,
        'ev-1',
        new DateTimeImmutable('2026-09-04T12:00:00+09:00')
    );
    check_bool('過ぎていれば終了', $ended['ended']);
    check_bool('終了していれば有効ではない', !$ended['active']);

    $pending = km_app_map_active_event_status(
        $events,
        'ev-1',
        new DateTimeImmutable('2026-08-30T12:00:00+09:00')
    );
    check_bool('会期前', $pending['notStarted']);

    // **見つからないことを「期間外」と同じに扱わない。** 地図を差し替えると
    // イベントごと入れ替わり、設定の activeEventUuid だけが古い値のまま残る
    $missing = km_app_map_active_event_status($events, 'ev-999', $eventNow);
    check_bool('無い UUID は missing', $missing['missing']);
    check_bool('指定なしなら null', km_app_map_active_event_status($events, null, $eventNow) === null);
    check_bool('空文字も指定なし', km_app_map_active_event_status($events, '  ', $eventNow) === null);

    /*
     * **中身の件数も出す**(2026-10-24、利用者の指摘「一般向けアプリで臨時の名前になっていない」)。
     * 「イベントは載っているのに名前が置き換わらない」とき、載っているのが**空のイベント**なのかどうかが
     * 画面から分からなかった。nameOverrides は JSON ではオブジェクト、ほかは配列なので、どちらも数える
     */
    check('件数: 空のイベントは全部 0', [0, 0, 0, 0], [
        $running['nameCount'], $running['placeCount'], $running['closureCount'], $running['hiddenCount'],
    ]);
    $filled = [json_decode(json_encode([
        'uuid' => 'ev-1',
        'name' => '高専祭',
        'nameOverrides' => (object) ['uuid-a' => '32', 'uuid-b' => '受付'],
        'places' => [['uuid' => 'web-poi-1'], ['uuid' => 'web-poi-2'], ['uuid' => 'web-poi-3']],
        'closedNodeUuids' => ['uuid-c'],
        'closedLineKeys' => ['1F:a:b', '1F:b:c'],
        'hiddenNodeUuids' => ['uuid-d'],
    ]))];
    $filledStatus = km_app_map_active_event_status($filled, 'ev-1', $eventNow);
    check('件数: 臨時の名前はオブジェクトでも数える', 2, $filledStatus['nameCount']);
    check('件数: 臨時の地点', 3, $filledStatus['placeCount']);
    check('件数: 通行止めは地点と線の合計', 3, $filledStatus['closureCount']);
    check('件数: 出さない地点', 1, $filledStatus['hiddenCount']);
    check('件数: 無い UUID のときも 0 で揃える', 0, $missing['nameCount']);
    // 臨時の名前が 0 件なら、管理画面「ダウンロード」で配信し直しを促す
    check_bool('臨時の名前 0 件を知らせる', str_contains((string) file_get_contents(__DIR__ . '/../admin/downloads.php'), 'page.downloads.mapReleaseEventEmpty'));
    foreach (['ja', 'en'] as $lang) {
        $i18n = (string) file_get_contents(__DIR__ . '/../admin/assets/i18n/' . $lang . '.js');
        foreach (['mapReleaseEventEmpty', 'mapReleaseEventNames', 'mapReleaseEventPlaces', 'mapReleaseEventClosures', 'mapReleaseEventHidden'] as $key) {
            check_bool("i18n: {$key} ({$lang})", str_contains($i18n, 'page.downloads.' . $key));
        }
    }

    /*
     * アクセスコード。**照合の正本は今までどおり `hash`。**
     *
     * 平文(`code`)は管理画面に出すためだけに持ち回る。ここが崩れると
     * 「平文さえ合っていれば通る」経路ができ、ハッシュにしてある意味が消える。
     */
    km_check_heading('app-map: アクセスコードの照合');
    $codeConfig = [
        'maps' => [],
        'codes' => [[
            'slug' => 'kosen-main',
            'hash' => password_hash('KOSEN2026', PASSWORD_DEFAULT),
            'code' => 'KOSEN2026',
        ]],
    ];
    check('正しいコードで引ける', 'kosen-main', km_app_map_resolve_slug('KOSEN2026', $codeConfig));
    check_bool('違うコードは通らない', km_app_map_resolve_slug('WRONG', $codeConfig) === null);

    // **平文が合っていても、ハッシュが違えば通さない。**
    // 照合を平文へ移してしまうと、この検査が落ちる
    $mismatched = [
        'maps' => [],
        'codes' => [[
            'slug' => 'kosen-main',
            'hash' => password_hash('OTHER', PASSWORD_DEFAULT),
            'code' => 'KOSEN2026',
        ]],
    ];
    check_bool(
        '平文ではなくハッシュで照合する',
        km_app_map_resolve_slug('KOSEN2026', $mismatched) === null
    );

    km_check_heading('app-map: アクセスコードの表示');
    $reportConfig = [
        'storageDir' => '',
        'maps' => ['kosen-main' => [
            'file' => 'このファイルはありません.json',
            'revision' => 1,
            'expiresAt' => '2099-01-01T00:00:00+09:00',
        ]],
        'codes' => [['slug' => 'kosen-main', 'hash' => 'x', 'code' => 'KOSEN2026']],
    ];
    $reportRow = km_app_map_release_report($reportConfig)[0];
    check('平文を管理画面へ渡す', 'KOSEN2026', $reportRow['code']);
    check('コードの数も数える', 1, $reportRow['codeCount']);
    // **「無い」と「読めない」を分ける。** 打つ手がまるで違う
    check_bool('実体が無いことを言う', $reportRow['file']['error'] !== null);

    // 古い設定には平文が入っていない。**空文字ではなく null**(画面で言い分けるため)
    $legacyConfig = $reportConfig;
    $legacyConfig['codes'] = [['slug' => 'kosen-main', 'hash' => 'x']];
    check_bool(
        '平文が無ければ null',
        km_app_map_release_report($legacyConfig)[0]['code'] === null
    );

    /*
     * 配信の停止と削除。**設定を書き換えるだけの純粋関数**なので、
     * ファイルも DB も無しで確かめられる。
     */
    km_check_heading('app-map: 配信の停止');
    $liveConfig = [
        'storageDir' => '',
        'maps' => [
            'kosen-main' => ['file' => 'a.json', 'revision' => 3, 'expiresAt' => '2099-01-01T00:00:00+09:00'],
            'other' => ['file' => 'b.json', 'revision' => 1, 'expiresAt' => '2099-01-01T00:00:00+09:00'],
        ],
        'codes' => [
            ['slug' => 'kosen-main', 'hash' => 'x', 'code' => 'AAA'],
            ['slug' => 'other', 'hash' => 'y'],
        ],
    ];

    // **設定が無ければ止まっていない。** 既存の設定をそのまま読めること
    check_bool('既定では止まっていない', !km_app_map_is_paused($liveConfig['maps']['kosen-main']));

    $paused = km_app_map_set_paused($liveConfig, 'kosen-main', true);
    check_bool('止められる', km_app_map_is_paused($paused['maps']['kosen-main']));
    // **他の配信を巻き込まない**
    check_bool('別の配信は動いたまま', !km_app_map_is_paused($paused['maps']['other']));
    // **期限は触らない。** 触ると、再開したいときに元の期限が分からなくなる
    check(
        '期限は変えない',
        '2099-01-01T00:00:00+09:00',
        $paused['maps']['kosen-main']['expiresAt']
    );
    check_bool(
        '再開できる',
        !km_app_map_is_paused(km_app_map_set_paused($paused, 'kosen-main', false)['maps']['kosen-main'])
    );

    $unknownSlug = false;
    try {
        km_app_map_set_paused($liveConfig, 'いない', true);
    } catch (InvalidArgumentException $exception) {
        $unknownSlug = true;
    }
    check_bool('知らない配信 ID は拒む', $unknownSlug);

    km_check_heading('app-map: 配信の削除');
    $removed = km_app_map_remove($liveConfig, 'kosen-main');
    check_bool('設定から消える', !isset($removed['config']['maps']['kosen-main']));
    check_bool('別の配信は残る', isset($removed['config']['maps']['other']));
    // **アクセスコードも一緒に消す。** 残すと行き先の無いコードになる(TEST1 の形)
    check('この配信のコードを消す', 1, $removed['removedCodes']);
    check('別の配信のコードは残す', 1, count($removed['config']['codes']));
    check('残ったのは別の配信のもの', 'other', $removed['config']['codes'][0]['slug']);
    // 消したあとの設定からはファイル名が引けない。**呼ぶ側が消せるように返す**
    check('消すファイル名を返す', 'a.json', $removed['fileName']);

    $unknownRemove = false;
    try {
        km_app_map_remove($liveConfig, 'いない');
    } catch (InvalidArgumentException $exception) {
        $unknownRemove = true;
    }
    check_bool('知らない配信 ID は拒む', $unknownRemove);

    // 止めた配信も、報告には載せる。**「消えた」と見えると探しに行けない**
    $pausedReport = km_app_map_release_report(km_app_map_set_paused($liveConfig, 'kosen-main', true));
    $pausedRow = null;
    foreach ($pausedReport as $row) {
        if ($row['slug'] === 'kosen-main') {
            $pausedRow = $row;
        }
    }
    check_bool('報告に止まっていることを出す', ($pausedRow['paused'] ?? false) === true);

    km_check_heading('app-map: パッケージの組み立て');
    // 実データの形に寄せてある(小数・指数表記・日本語のキー・空オブジェクト)。
    $map = km_app_map_decode_snapshot(
        '{"version":8,'
        . '"nodes":[{"uuid":"n1","x":1122.0,"y":794.5,"z":0.30000000000000004,'
        . '"title":"1F 廊下 <A> & \"B\"","txPowerAtOneMeter":null,"pathLossExponent":2.5}],'
        . '"lines":[{"floor":"1F","startX":0.1,"endX":-1.0e-7}],'
        . '"rssi":{"aa:bb":-40},"名前":"日本語","path":"a/b/c","extra":{}}'
    );
    $body = km_app_map_build_package(
        $map,
        'kosen-main',
        12,
        '2026-10-01T10:00:00+09:00',
        '2026-11-03T18:00:00+09:00',
        'ev-2026-fes',
        true
    );
    check_bool('文字列が返る', is_string($body));
    $parsed = json_decode((string) $body);
    check_bool('JSON として読める', $parsed instanceof stdClass);
    check_bool('format', ($parsed->format ?? '') === 'kosenmap-map-package');
    check_bool('formatVersion', ($parsed->formatVersion ?? 0) === 1);
    check_bool('mapId', ($parsed->mapId ?? '') === 'kosen-main');
    check_bool('revision', ($parsed->revision ?? 0) === 12);
    check_bool('expiresAt', ($parsed->expiresAt ?? '') === '2026-11-03T18:00:00+09:00');
    check_bool('activeEventUuid', ($parsed->activeEventUuid ?? '') === 'ev-2026-fes');
    check_bool('map が入れ子のオブジェクト', ($parsed->map ?? null) instanceof stdClass);
    check_bool('map の中身が保たれる', ($parsed->map->rssi->{'aa:bb'} ?? null) === -40);
    check_bool('日本語がそのまま', ($parsed->map->{'名前'} ?? '') === '日本語');

    // チェックサムが、埋め込まれた map の文字列と一致すること。
    $mapJson = json_encode($parsed->map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check_bool(
        'checksum が map と一致',
        ($parsed->checksum ?? '') === 'sha256:' . hash('sha256', (string) $mapJson)
    );

    $noChecksum = json_decode((string) km_app_map_build_package(
        $map, 'kosen-main', 1, '2026-10-01T10:00:00+09:00', '2026-11-03T18:00:00+09:00', null, false
    ));
    // ?? は値が null でも既定値を返すので、キーの存在と値を分けて見る。
    check_bool('checksum キーはある', property_exists($noChecksum, 'checksum'));
    check_bool('checksum 無効なら null', $noChecksum->checksum === null);
    check_bool('activeEventUuid キーはある', property_exists($noChecksum, 'activeEventUuid'));
    check_bool('activeEventUuid 空なら null', $noChecksum->activeEventUuid === null);

    return (string) $body;
}

// =================================================================== app-ranking

/**
 * lib/app-ranking.php の入力の絞り込み。
 *
 * 積む処理そのものは DB が要るのでここでは触らない。
 * **表に妙な値が入る経路**と**件数の歯止め**だけを固定する。
 */
function km_check_app_ranking(): void
{
    require_once __DIR__ . '/../lib/app-ranking.php';

    km_check_heading('app-ranking: 地点の UUID');
    check('普通のUUIDは通る', ['a1b2-c3d4'], km_ranking_clean_uuids(['a1b2-c3d4']));
    check('前後の空白は落とす', ['abc'], km_ranking_clean_uuids(['  abc  ']));
    check('空文字は落とす', [], km_ranking_clean_uuids(['', '   ']));
    check('記号入りは落とす', [], km_ranking_clean_uuids(["abc'; DROP TABLE--"]));
    check('スラッシュ入りは落とす', [], km_ranking_clean_uuids(['a/b']));
    check('長すぎるものは落とす', [], km_ranking_clean_uuids([str_repeat('a', 65)]));
    check('64文字ちょうどは通る', [str_repeat('a', 64)], km_ranking_clean_uuids([str_repeat('a', 64)]));

    // まとめ送りが暴れないこと。ここが効かないと1回の送信で表を膨らませられる。
    $many = array_map(static fn(int $i): string => 'node' . $i, range(1, 200));
    check('件数の上限で切る', KM_RANKING_MAX_BATCH, count(km_ranking_clean_uuids($many)));

    km_check_heading('app-ranking: 検索語');
    check('普通の語は通る', ['しょくどう'], km_ranking_clean_queries(['しょくどう']));
    check('前後の空白は落とす', ['しょくどう'], km_ranking_clean_queries(['  しょくどう  ']));
    check('空文字は落とす', [], km_ranking_clean_queries(['', '  ']));
    // 画面へそのまま出すので、制御文字は入れない。
    check('制御文字入りは落とす', [], km_ranking_clean_queries(["ab\x00cd"]));
    check('改行入りは落とす', [], km_ranking_clean_queries(["ab\ncd"]));
    check(
        '長すぎる語は落とす',
        [],
        km_ranking_clean_queries([str_repeat('あ', KM_RANKING_MAX_QUERY_LENGTH + 1)])
    );
    check(
        '上限ちょうどは通る',
        [str_repeat('あ', KM_RANKING_MAX_QUERY_LENGTH)],
        km_ranking_clean_queries([str_repeat('あ', KM_RANKING_MAX_QUERY_LENGTH)])
    );
    // 日本語は1文字3バイト。バイト数で数えていると、ここで取りこぼす。
    check('長さは文字数で数える', 1, count(km_ranking_clean_queries([str_repeat('あ', 30)])));
    check('件数の上限で切る(語)', KM_RANKING_MAX_BATCH, count(km_ranking_clean_queries($many)));

    km_check_heading('app-ranking: 表示名と件数');
    check('null はそのまま', null, km_ranking_clean_name(null));
    check('空文字は null', null, km_ranking_clean_name('   '));
    check('制御文字入りは null', null, km_ranking_clean_name("Ito\x01"));
    check('普通の名前は通る', 'Ito', km_ranking_clean_name('  Ito  '));
    check('長い名前は切る', 64, mb_strlen((string) km_ranking_clean_name(str_repeat('あ', 100)), 'UTF-8'));

    check('取得件数の下限', 1, km_ranking_clamp_limit(0));
    check('取得件数の下限(負)', 1, km_ranking_clamp_limit(-5));
    check('取得件数の上限', 100, km_ranking_clamp_limit(9999));
    check('範囲内はそのまま', 20, km_ranking_clamp_limit(20));

    km_check_heading('app-ranking: 公開してよい表示名');
    require_once __DIR__ . '/../lib/logto-management.php';
    /*
     * ランキングは**来場者にも見える**。表示名の選び方はプライバシーの決まりそのもの。
     *
     * `logto_guard.php` が組み立てる $principal['username'] は
     * `name → preferred_username → username → email → sub` の順で、
     * **email が入っている**。あれをそのまま表示名にすると
     * 利用者のメールアドレスが公開の画面に出る。だから別の関数で選ぶ。
     */
    check(
        'ニックネームが最優先',
        'ぬし',
        km_logto_public_display_name([
            'name' => '伊藤',
            'username' => 'ito',
            'primaryEmail' => 'ito@example.ac.jp',
            'profile' => ['nickname' => 'ぬし'],
        ])
    );
    check(
        'ニックネームが無ければ name',
        '伊藤',
        km_logto_public_display_name(['name' => '伊藤', 'username' => 'ito', 'profile' => []])
    );
    check(
        'name も無ければ username',
        'ito',
        km_logto_public_display_name(['username' => 'ito'])
    );
    // ここが緩むとメールアドレスが公開の一覧に出る。
    check(
        '**メールアドレスは使わない**',
        null,
        km_logto_public_display_name(['primaryEmail' => 'ito@example.ac.jp'])
    );
    check(
        'メールしか無ければ名前なし',
        null,
        km_logto_public_display_name([
            'name' => '',
            'username' => '   ',
            'primaryEmail' => 'ito@example.ac.jp',
            'profile' => ['nickname' => ''],
        ])
    );
    check('空の応答は null', null, km_logto_public_display_name([]));
    check(
        '前後の空白は落とす',
        'ぬし',
        km_logto_public_display_name(['profile' => ['nickname' => '  ぬし  ']])
    );
    // profile が配列でない応答でも壊れないこと(Logto の版差で null が来うる)。
    check(
        'profile が null でも壊れない',
        '伊藤',
        km_logto_public_display_name(['name' => '伊藤', 'profile' => null])
    );
}

// =================================================================== map-access

/**
 * 地図の錠。**2つの軸が混ざっていないこと**をここで固定する。
 *
 * | 設定 | 何を決めるか |
 * |---|---|
 * | `mode` | 教職員氏名を出すか(hidden / password / public) |
 * | `mapMode` | 地図そのものを出すか(public / password) |
 *
 * セッションを直接いじって判定を確かめる。DB もファイルも要らない。
 */
function km_check_map_access(): void
{
    require_once __DIR__ . '/../lib/map-access.php';

    $config = static fn (string $mode, string $mapMode): array => [
        'mode' => $mode,
        'mapMode' => $mapMode,
        'passwordHash' => 'dummy',
    ];

    km_check_heading('map-access: 地図そのものの錠');
    $_SESSION = [];
    check('public なら誰でも見られる', true, km_map_view_unlocked($config('hidden', 'public')));
    check('password で未入力なら見せない', false, km_map_view_unlocked($config('public', 'password')));

    $_SESSION['km_map_unlocked'] = km_map_password_fingerprint('dummy');
    check('password で入力済みなら見せる', true, km_map_view_unlocked($config('hidden', 'password')));

    km_check_heading('map-access: 2つの錠は独立している');
    /*
     * **氏名が hidden でも地図は開く。** ここが繋がっていると
     * 「氏名を隠したいだけなのに地図まで消える」ことになる。
     * いまの本番がまさに mode=hidden なので、取り違えると公開ページが真っ白になる。
     */
    $_SESSION = [];
    check('氏名 hidden・地図 public → 地図は見える', true, km_map_view_unlocked($config('hidden', 'public')));
    check('氏名 hidden・地図 public → 氏名は出ない', false, km_map_names_unlocked($config('hidden', 'public')));

    // 逆向き。地図を開けても、氏名の設定が hidden なら氏名は出ない。
    $_SESSION['km_map_unlocked'] = km_map_password_fingerprint('dummy');
    check('解除しても hidden の氏名は出ない', false, km_map_names_unlocked($config('hidden', 'password')));
    check('解除すれば password の氏名は出る', true, km_map_names_unlocked($config('password', 'public')));

    /*
     * **管理者の印は公開ページに効かない。**
     *
     * 以前は guard.php が利用者と同じ `km_map_unlocked` を立てていたので、管理画面を
     * 一度開いた人は公開ページでも錠の内側にいた。「パスワードが必要」に設定した本人が
     * 公開ページで普通に地図を見られてしまい、**設定が効いていないようにしか見えなかった。**
     */
    km_check_heading('map-access: 管理者の印と利用者の解除は別');
    $_SESSION = [];
    $_SESSION['km_map_admin'] = true;
    check('管理者の印だけでは公開ページの地図は開かない', false, km_map_view_unlocked($config('hidden', 'password')));
    check('管理者の印だけでは氏名も開かない', false, km_map_names_unlocked($config('password', 'password')));
    check_bool('管理者の印は読み取れる', km_map_admin_session());

    $_SESSION = [];
    $_SESSION['km_map_unlocked'] = km_map_password_fingerprint('dummy');
    check_bool('利用者の解除は管理者の印にならない', !km_map_admin_session());
    $_SESSION = [];

    /*
     * **パスワードを替えたら、前の解除は効かない**(2026-09-25、診断 W-46)。
     * 以前は印が true かだけを見ていたので、漏れたパスワードで解除した人は替えたあとも氏名を見続けられた。
     */
    km_check_heading('map-access: パスワードを替えたら解除が外れる(W-46)');
    $withHash = static fn (?string $hash): array => ['mode' => 'password', 'mapMode' => 'password', 'passwordHash' => $hash];
    $oldHash = password_hash('PassA', PASSWORD_BCRYPT, ['cost' => 4]);
    $newHash = password_hash('PassB', PASSWORD_BCRYPT, ['cost' => 4]);
    $_SESSION['km_map_unlocked'] = km_map_password_fingerprint($oldHash);
    check_bool('解除した設定のままなら氏名も地図も開く', km_map_names_unlocked($withHash($oldHash)) && km_map_view_unlocked($withHash($oldHash)));
    check_bool('パスワードを替えたら氏名は閉じる', !km_map_names_unlocked($withHash($newHash)));
    check_bool('パスワードを替えたら地図も閉じる', !km_map_view_unlocked($withHash($newHash)));
    $_SESSION['km_map_unlocked'] = true;
    check_bool('以前の形(true)の印は効かない', !km_map_password_entered($withHash($oldHash)));
    $_SESSION['km_map_unlocked'] = km_map_password_fingerprint(null);
    check_bool('パスワードが未設定なら要約は無く、誰も解除できない', km_map_password_fingerprint(null) === null && !km_map_password_entered($withHash(null)));
    $unlockApi = (string) file_get_contents(__DIR__ . '/../api/map-unlock.php');
    check_bool('解除の API は要約を入れる', str_contains($unlockApi, '$_SESSION[\'km_map_unlocked\'] = km_map_password_fingerprint($config[\'passwordHash\']);'));
    $_SESSION = [];

    km_check_heading('map-access: 入力欄を出す条件');
    // **どちらか一方でも要求していれば出す。** 片方しか見ないと、
    // 地図だけ錠を掛けたときに入力欄が出ず、利用者に開ける手段が無くなる。
    check('地図だけ password でも出す', true, km_map_password_requested($config('hidden', 'password')));
    // 2026-10-22 から Web の氏名はパスワードで開かない(組織メンバー + 先生の許可だけ。lib/node-visibility.php)。入れても何も変わらない欄は出さない
    check('氏名だけ password では出さない(Web の氏名はパスワードで開かない)', false, km_map_password_requested($config('password', 'public')));
    check('どちらも要らなければ出さない', false, km_map_password_requested($config('hidden', 'public')));
    check('public / public でも出さない', false, km_map_password_requested($config('public', 'public')));

    km_check_heading('map-access: 既定と未知の値');
    // **未知の値は緩い方へ落とす。** password へ倒すと、設定を書き損じた瞬間に
    // 公開ページが全部閉じて、しかも誰も開けられない。
    check('未知の mapMode は public 扱い', true, km_map_view_unlocked($config('hidden', 'nonsense')));
    check('mapMode の選択肢は2つ', ['public', 'password'], KM_MAP_VIEW_MODES);
    check('mapMode の既定は public', 'public', KM_MAP_VIEW_MODES[0]);

    $_SESSION = [];

    /*
     * 保存そのもの。**ここが本番で一度も成功していなかった。**
     *
     * 一時ファイルを作ってから rename する作りだったが、本番の `src/config/` は
     * 配備利用者のもので、PHP(www-data)は**中に新しいファイルを作れない**。
     * ファイル自体には書けるので、既存ファイルへ直接書く経路を足してある。
     *
     * 検査は書き込み可能なディレクトリでしか回せない(権限を落とす操作は
     * Windows では効かない)ので、**書けないときに何が起きるか**は
     * 「そもそも存在しないディレクトリ」で代用する。is_writable() が false になり、
     * 直接書く方も失敗する —— 本番で起きていた状態と同じ枝を通る。
     */
    km_check_heading('map-access: 設定ファイルの書き込み');
    $sandbox = sys_get_temp_dir() . '/km-check-' . uniqid('', true);
    mkdir($sandbox);
    $target = $sandbox . '/map-access.local.php';

    $payload = static fn (string $mapMode): string => "<?php\n\nreturn "
        . var_export(['mode' => 'hidden', 'mapMode' => $mapMode, 'passwordHash' => 'x'], true)
        . ";\n";

    km_map_access_write_config($target, $payload('public'));
    $written = require $target;
    check('書いた内容がそのまま読み戻せる', 'public', $written['mapMode']);

    km_map_access_write_config($target, $payload('password'));
    check_bool('上書きできる', str_contains(file_get_contents($target), "'mapMode' => 'password'"));

    // .tmp が残ると、次の配備で「見覚えのない設定ファイル」が転がることになる
    check_bool('一時ファイルを残さない', !is_file($target . '.tmp'));

    $failure = null;
    try {
        km_map_access_write_config($sandbox . '/no-such-dir/map-access.local.php', $payload('public'));
    } catch (Throwable $exception) {
        $failure = $exception->getMessage();
    }
    check_bool('書けなければ例外を投げる', $failure !== null);
    // **直し方を文面に入れておく。** 管理画面はこの文言をそのまま出すので、
    // ここが空だと管理者は「保存できません」だけを見て詰む。
    check_bool('例外に直し方が入っている', $failure !== null && str_contains($failure, 'chown'));

    unlink($target);
    rmdir($sandbox);

    /*
     * 見取り図の Content-Type。**ここを間違えると全階が同時に出なくなる。**
     *
     * 実際に起きた: 列名が `svg_path` なので `image/svg+xml` 決め打ちで配っていたが、
     * 中身は PNG。`X-Content-Type-Options: nosniff` を付けてあるのでブラウザは
     * 名乗りと中身の食い違いを直せず、画像を捨てて
     * 「この階の見取り図を読み込めませんでした」になった。
     */
    require_once __DIR__ . '/../lib/map-data.php';

    km_check_heading('map-access: 見取り図の Content-Type');
    check('PNG は image/png', 'image/png', km_map_floor_image_content_type('Picture/1階.png'));
    check('大文字の拡張子も同じ', 'image/png', km_map_floor_image_content_type('Picture/1階.PNG'));
    check('SVG は image/svg+xml', 'image/svg+xml', km_map_floor_image_content_type('a.svg'));
    check('jpg と jpeg は同じ', 'image/jpeg', km_map_floor_image_content_type('a.jpeg'));
    // **知らない種別は配らない。** 拡張子から機械的に組み立てると、
    // 表に妙な値が入った瞬間に、こちらが把握していない種別を名乗って配ることになる
    check_bool('知らない拡張子は null', km_map_floor_image_content_type('a.exe') === null);
    check_bool('拡張子が無ければ null', km_map_floor_image_content_type('Picture/1階') === null);

    /*
     * 実物と突き合わせる。DB は読めないので、`km_map_floors` へ入れる値を決めている
     * 移行スクリプトの一覧ではなく、**置いてあるファイル**を見る。
     * ここが落ちるなら、配れない種別の見取り図が混ざっている。
     */
    $pictures = glob(__DIR__ . '/../Main/Picture/*');
    $unservable = array_values(array_filter(
        $pictures === false ? [] : $pictures,
        static fn (string $file): bool => is_file($file)
            && km_map_floor_image_content_type($file) === null
    ));
    check_bool(
        '置いてある見取り図はすべて配れる',
        $unservable === [],
        $unservable === [] ? count($pictures ?: []) . '件' : implode(' / ', array_map('basename', $unservable))
    );

    /*
     * 管理画面が錠を素通りする経路。**DB が要るので中身は動かせない**ので、
     * 「どう書かれているか」を見る。ここが崩れると、次のどちらかが起きる:
     *   - 素通りが消える → 「パスワードが必要」にした瞬間に地図編集ができなくなる
     *   - 素通りがセッションの印に戻る → 公開ページでも管理者だけ錠が開く(元の不具合)
     */
    km_check_heading('map-access: 管理画面の素通り');
    $mapDataSource = (new ReflectionFunction('km_map_data'))->getFileName();
    $mapDataBody = file_get_contents((string) $mapDataSource);

    check_bool(
        'km_map_data は forAdmin を受け取る',
        (new ReflectionFunction('km_map_data'))->getNumberOfParameters() === 3
    );
    check_bool(
        '地図の錠を forAdmin で素通りする',
        str_contains($mapDataBody, '$viewUnlocked = $forAdmin || km_map_view_unlocked($config)')
    );
    // **氏名も素通りさせること。** 伏せたまま編集画面へ渡すと、保存時に空で
    // 上書きされて氏名が1件ずつ消える(map-editor.js は読んだ値をそのまま送り返す)
    check_bool(
        '氏名も forAdmin で素通りする',
        str_contains($mapDataBody, "\$unlocked = \$forAdmin || (\$member && !\$overlay['hideOccupantNames']);")
            && str_contains($mapDataBody, 'if (!$forAdmin) {')
    );

    $guard = file_get_contents(__DIR__ . '/../admin/_inc/guard.php');
    check_bool(
        'guard.php が立てるのは管理者の印',
        str_contains($guard, "\$_SESSION['km_map_admin'] = true;")
    );
    check_bool(
        'guard.php は利用者の解除印を立てない',
        !str_contains($guard, "\$_SESSION['km_map_unlocked']")
    );

    $editor = file_get_contents(__DIR__ . '/../admin/map-editor.php');
    check_bool('地図編集は forAdmin: true で呼ぶ', str_contains($editor, 'forAdmin: true'));
}

// ============================================================== app-map-convert

/**
 * 管理アプリの地図 → Website のノード。
 *
 * **地図の作り直しは取り返しがつかない。** ここで固定するのは、黙って壊れる種類のもの:
 * ID が列に入らない・階の表記・Y の向き・知らない種類を素通りさせない。
 */
function km_check_app_map_convert(): void
{
    require_once __DIR__ . '/../lib/app-map-convert.php';

    km_check_heading('app-map-convert: ID を列へ収める');
    /*
     * `km_map_nodes.id` は varchar(32)、アプリの UUID は36文字。
     * **ハイフンを抜くとちょうど32文字。** ALTER はアプリの DB 利用者に無いので、
     * 列を広げずに収まるこの形に頼っている。
     */
    $uuid = '2735e277-ed55-4b74-963e-82baa0b6a641';
    check('UUID は32文字に収まる', 32, mb_strlen(km_app_map_node_id($uuid)));
    check('ハイフンだけを抜く', '2735e277ed554b74963e82baa0b6a641', km_app_map_node_id($uuid));
    check_bool('長すぎるものは切り詰める', mb_strlen(km_app_map_node_id(str_repeat('x', 50))) === 32);

    km_check_heading('app-map-convert: 階の表記');
    check('1F は 1', '1', km_app_map_floor_id('1F'));
    check('OUTSIDE は outside', 'outside', km_app_map_floor_id('OUTSIDE'));
    // **知らない階は黙って落とさない。** null を返して呼ぶ側に数えさせる
    check_bool('知らない階は null', km_app_map_floor_id('B1F') === null);

    km_check_heading('app-map-convert: Y の向き');
    /*
     * アプリは画面座標(Y は下へ)、Web は Leaflet の CRS.Simple(緯度は上へ)。
     * **忘れると地図が上下逆さまになる。**建物が対称に近いので一見それらしく見える。
     */
    check('上端は下端になる', 1200.0, km_app_map_flip_y(0.0));
    check('下端は上端になる', 0.0, km_app_map_flip_y(1200.0));
    check('2回反転すると戻る', 300.0, km_app_map_flip_y(km_app_map_flip_y(300.0)));

    km_check_heading('app-map-convert: 変換');
    $map = json_decode(json_encode([
        'nodes' => [
            ['uuid' => $uuid, 'title' => '第二実習室', 'subtitle' => '管-105',
                'type1' => 'room', 'floor' => '1F', 'x' => 514.27, 'y' => 212.69,
                'occupantName' => '架空 次郎'],
            ['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'title' => '通路', 'subtitle' => '',
                'type1' => 'road', 'floor' => '1F', 'x' => 600.0, 'y' => 300.0],
            /*
             * 壁。**以前は落としていた。**
             * Web が正本になった以上、落とすと二度と戻らない(2026-09-03)。
             */
            ['uuid' => 'ffffffff-0000-1111-2222-333333333333', 'title' => '壁', 'subtitle' => '',
                'type1' => 'wall', 'floor' => '1F', 'x' => 1.0, 'y' => 2.0],
            // 知らない種類。**推測で通さず、数えて報告する**
            ['uuid' => '11111111-0000-1111-2222-333333333333', 'title' => '謎', 'subtitle' => '',
                'type1' => 'teleporter', 'floor' => '1F', 'x' => 1.0, 'y' => 2.0],
            // 知らない階
            ['uuid' => '99999999-0000-1111-2222-333333333333', 'title' => '地下', 'subtitle' => '',
                'type1' => 'room', 'floor' => 'B1F', 'x' => 1.0, 'y' => 2.0],
        ],
        'lines' => [
            ['startNodeUuid' => $uuid, 'endNodeUuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'startX' => 0.0, 'startY' => 0.0, 'endX' => 3.0, 'endY' => 4.0, 'floor' => '1F'],
            // 端点が落ちた線。外部キーがあるので入れると挿入が失敗する
            ['startNodeUuid' => $uuid, 'endNodeUuid' => '00000000-dead-beef-0000-000000000000',
                'startX' => 0.0, 'startY' => 0.0, 'endX' => 1.0, 'endY' => 1.0, 'floor' => '1F'],
        ],
    ]));

    $result = km_app_map_to_web($map);
    check('通ったノードは3件', 3, count($result['nodes']));
    check('名前は title と subtitle を繋ぐ', '第二実習室 管-105', $result['nodes'][0]['name']);
    /*
     * **元のままの title / subtitle も渡す。**
     * 組み立てた `name` からは戻せない —— 部屋名に空白が入っていると
     * どこが分かれ目か決められない。分かれ目を知っているのはアプリだけ。
     */
    check('title はそのまま', '第二実習室', $result['nodes'][0]['title']);
    check('subtitle はそのまま', '管-105', $result['nodes'][0]['subtitle']);
    check('subtitle が無ければ空', '', $result['nodes'][1]['subtitle']);
    check('氏名を持ち越す', '架空 次郎', $result['nodes'][0]['occupant_name']);
    /*
     * **種類を潰さない。** 以前は road と entrance を両方 `point` にしていたが、
     * `point` からはどちらだったか戻せない。Web が正本になると復元できない情報になる。
     */
    check('road はそのまま', 'road', $result['nodes'][1]['type']);
    check('壁も通す', 'wall', $result['nodes'][2]['type']);
    check('Y は反転している', 1200 - 212.69, $result['nodes'][0]['y']);
    /*
     * **丸めない。** 写しだったときは int で足りたが、正本になると
     * 丸めは往復のたびに積もる位置ずれになる(列は decimal(10,2))。
     */
    check('X は丸めない', 514.27, $result['nodes'][0]['x']);
    check('知らない種類を数える', 1, $result['skipped']['unknownType']['teleporter'] ?? 0);
    check('知らない階を数える', ['B1F'], $result['skipped']['unknownFloor']);
    check('端点を失った線は落とす', 1, $result['skipped']['danglingEdge']);
    check('残った線は1本', 1, count($result['edges']));
    check('距離は座標から測る', 5, $result['edges'][0]['distance']);

    km_check_heading('app-map-convert: 適用したらどうなるか');
    $existing = [
        // 変換後に残る ID。**名前が違う**ので中身が変わる
        km_app_map_node_id($uuid) => ['name' => '古い名前', 'occupant_name' => '架空 次郎'],
        // 変換後に無い ID(削除される)。**氏名を持っているので必ず報告する**
        'n_new_10' => ['name' => 'トレーナー室 管-104', 'occupant_name' => '伊藤 太郎'],
    ];
    $diff = km_app_map_diff($result, $existing);
    check('追加', 2, $diff['added']);
    check('更新', 1, $diff['updated']);
    check('削除', 1, $diff['removed']);

    km_check_heading('app-map-convert: 変わらないものは数え分ける');
    /*
     * **これが無いと数字が意味を失う。**
     * 以前は「両方に在る = 置き換わる」としていたので、何も直していない
     * 書き出しを上げても「置き換わる地点 685」と出ていた。
     */
    $identical = [];
    foreach ($result['nodes'] as $node) {
        $identical[$node['id']] = $node;
    }
    $noChange = km_app_map_diff($result, $identical);
    check('同じものを上げたら更新は 0', 0, $noChange['updated']);
    check('全部「変わらない」に入る', count($result['nodes']), $noChange['unchanged']);
    check('追加も削除も 0', [0, 0], [$noChange['added'], $noChange['removed']]);

    /*
     * **型の違いで「変わった」と言わない。**
     * DB の decimal は PDO が文字列で返す(`"514.27"`)。`!==` で比べると
     * **全件が「置き換わる」**になる。
     */
    $asStrings = [];
    foreach ($result['nodes'] as $node) {
        $row = $node;
        $row['x'] = (string) $node['x'];
        $row['y'] = (string) $node['y'];
        $row['use_wifi'] = (string) $node['use_wifi'];
        $asStrings[$node['id']] = $row;
    }
    check('文字列で返っても同じと見る', 0, km_app_map_diff($result, $asStrings)['updated']);
    // 「無い」と「空」を分けない(DB は null、書き出しは '' で来ることがある)
    check_bool('null と空文字は同じ', km_app_map_same_value(null, ''));
    check_bool('0.001 の差は同じ', km_app_map_same_value(514.27, '514.271'));
    check_bool('0.01 の差は違う', !km_app_map_same_value(514.27, 514.28));
    check('氏名を持つノード', 1, $diff['occupantKept']);
    // ここが空でないまま適用すると、その氏名は失われる
    check('消える氏名を挙げる', ['トレーナー室 管-104'], $diff['occupantLost']);

    km_check_heading('app-map-convert: Web → アプリ');
    /*
     * **この向きは 2026-09-03 に足した。** それまで片道しか無かった
     * (アプリが正本だったので、戻す必要が無かった)。
     *
     * 行は DB の列名で組む。`km_app_map_from_web()` が読んで、そのまま渡す形。
     */
    $rows = [];
    foreach ($result['nodes'] as $node) {
        $rows[] = $node + ['id' => $node['id']];
    }
    $edgeRows = [];
    foreach ($result['edges'] as $edge) {
        $edgeRows[] = ['from_node_id' => $edge['from'], 'to_node_id' => $edge['to']];
    }
    $back = km_app_map_snapshot_from_rows($rows, $edgeRows);
    $backMap = $back['map'];

    check('版はアプリと揃える', 10, $backMap->version);
    check('ノードの数', 3, count($backMap->nodes));
    check('uuid は完全な形で戻る', $uuid, $backMap->nodes[0]->uuid);
    // **反転は同じ式で戻る。**2つ持つと、片方だけ直したときに黙ってずれる
    check('Y が元に戻る', 212.69, $backMap->nodes[0]->y);
    check('X が元に戻る', 514.27, $backMap->nodes[0]->x);
    check('階の表記が戻る', '1F', $backMap->nodes[0]->floor);
    check('種類が戻る', 'wall', $backMap->nodes[2]->type1);
    check('氏名が戻る', '架空 次郎', $backMap->nodes[0]->occupantName);
    /*
     * 端末が出す精度の測定履歴は**持たない**。地図の内容ではない。
     * 空配列で出す —— アプリは `orEmpty()` で受けるが、
     * **「無い」と「空」を読む側に判断させない。**
     */
    check('evaluations は空で出す', [], $backMap->evaluations);
    check_bool('events はここでは空', $backMap->events === []);
    // 線の座標は**持ち回らず端点から作る**。ノードを動かして線だけ古い、を作らない
    check('線の座標は端点から作る', 514.27, $backMap->lines[0]->startX);
    check('線は階を持つ', '1F', $backMap->lines[0]->floor);

    km_check_heading('app-map-convert: 往復して元に戻る');
    /*
     * **ここが崩れると、配信のたびに少しずつ形が変わる。**
     * しかも1回では気づけない —— 気づくのは、ずれが目に見える大きさになってから。
     */
    $again = km_app_map_to_web($backMap);
    check('ノードの数が変わらない', count($result['nodes']), count($again['nodes']));
    check('座標が変わらない', $result['nodes'][0]['x'], $again['nodes'][0]['x']);
    check('Y も変わらない', $result['nodes'][0]['y'], $again['nodes'][0]['y']);
    check('種類が変わらない', $result['nodes'][2]['type'], $again['nodes'][2]['type']);
    check('氏名が変わらない', $result['nodes'][0]['occupant_name'], $again['nodes'][0]['occupant_name']);
    check('線の数が変わらない', count($result['edges']), count($again['edges']));

    km_check_heading('app-map-convert: 出せないものは黙って通さない');
    // 移行 SQL を流す前の `point` がここに落ちる。**推測で road にしない**
    $bad = km_app_map_snapshot_from_rows(
        [['id' => 'x', 'uuid' => 'x', 'floor_id' => '1', 'type' => 'point', 'x' => 0, 'y' => 0]],
        []
    );
    check('知らない種類は出さない', 0, count($bad['map']->nodes));
    check('知らない種類を数える', 1, $bad['skipped']['unknownType']['point'] ?? 0);
    // uuid が空のノードを配信すると、端末側で線が繋がらなくなる
    $noUuid = km_app_map_snapshot_from_rows(
        [['id' => 'x', 'uuid' => '', 'floor_id' => '1', 'type' => 'room', 'x' => 0, 'y' => 0]],
        []
    );
    check('uuid が空なら出さない', 0, count($noUuid['map']->nodes));
    /*
     * アプリの線は**1つの階に属する**(階をまたぐ移動は transferGroupId)。
     * またぐ線を出すと、どちらの階で描くか決められない。
     */
    $cross = km_app_map_snapshot_from_rows(
        [
            ['id' => 'a', 'uuid' => 'a', 'floor_id' => '1', 'type' => 'road', 'x' => 0, 'y' => 0],
            ['id' => 'b', 'uuid' => 'b', 'floor_id' => '2', 'type' => 'road', 'x' => 0, 'y' => 0],
        ],
        [['from_node_id' => 'a', 'to_node_id' => 'b']]
    );
    check('階をまたぐ線は出さない', 0, count($cross['map']->lines));
    check('階をまたぐ線を数える', 1, $cross['skipped']['crossFloorEdge']);

    km_check_heading('app-map-convert: 0 と「無い」を分ける');
    /*
     * `(int) null` は 0。校正値が未設定のノードに 0 が入ると、
     * **測位がその 0 を真面目に使う。**「測っていない」は null で出す。
     */
    check('未設定は null', null, km_app_map_nullable_int(null));
    check('空文字も null', null, km_app_map_nullable_float(''));
    check('0 は 0 のまま', 0, km_app_map_nullable_int(0));
    check('数は通す', -42, km_app_map_nullable_int('-42'));

    km_check_heading('app-map-convert: 上書きで消える氏名を数える');
    /*
     * **ここが一番危ない。**
     *
     * 取り込みは同じ ID の行を丸ごと置き換える。上げた地図にその地点は在るのに
     * 氏名だけ入っていない(来場者向けに氏名を抜いた書き出し、氏名を移す前の
     * 古い書き出し)と、**氏名だけが黙って消える。**
     *
     * 「無いものを消す」を選ばなくても消える —— 画面は「置き換わる地点 685」
     * としか言わないので、**数えなければ誰も気づかない。**
     */
    $sameId = km_app_map_node_id($uuid);
    $withNames = km_app_map_diff($result, [
        // 上げた地図にも在るが、そちらに氏名が無い → 上書きで消える
        km_app_map_node_id('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
            => ['name' => '通路', 'occupant_name' => '伊藤 太郎'],
        // 上げた地図にも在り、そちらにも氏名がある → 置き換わるだけ
        $sameId => ['name' => '第二実習室 管-105', 'occupant_name' => '古い 名前'],
        // 上げた地図に無い → 「消す」を選んだときだけ失われる
        'n_new_10' => ['name' => 'トレーナー室 管-104', 'occupant_name' => '架空 次郎'],
    ]);
    check('上書きで消える氏名を挙げる', ['通路'], $withNames['occupantCleared']);
    check('消える地点の氏名は別に挙げる', ['トレーナー室 管-104'], $withNames['occupantLost']);
    // 上げた地図にも氏名があるものは、消える方に数えない
    check_bool(
        '置き換わるだけのものは数えない',
        !in_array('第二実習室 管-105', $withNames['occupantCleared'], true)
    );

    km_check_heading('app-map-sync: 作り直さない');
    /*
     * **2026-09-03 に向きが変わった。**
     *
     * それまでは `DELETE FROM km_map_nodes` してから入れ直していた。
     * アプリが正本だったのでそれでよかったが、**Website でも編集する**いまは、
     * 取り込みのたびに Website 側で足した地点が消える。
     * それが「同じ校舎の地図が2つある」状態の正体だった。
     */
    $syncLib = (string) file_get_contents(__DIR__ . '/../lib/app-map-sync.php');
    /*
     * **実行される形だけを見る。** 単に文字列を探すと、
     * 「昔はこう書いていた」と説明している docblock を捕まえてしまう ——
     * 説明を消さないと通らない検査は、説明の方を削らせる。
     */
    check_bool('全消しをしない', !str_contains($syncLib, "exec('DELETE FROM km_map_nodes"));
    check_bool('辺も全消ししない', !str_contains($syncLib, "exec('DELETE FROM km_map_edges"));
    check_bool('上書きで入れる', str_contains($syncLib, 'ON DUPLICATE KEY UPDATE'));
    // 消すのは頼まれたときだけ。**既定は残す**
    check_bool('消すのは選んだときだけ', str_contains($syncLib, 'if ($removeMissing) {'));
    check_bool(
        '既定では消さない',
        str_contains($syncLib, 'bool $removeMissing = false')
    );
    /*
     * **null で氏名を上書きしない。**
     * 氏名を抜いた書き出しを取り込むと、65 件が黙って消える。
     */
    check_bool(
        '氏名は null で上書きしない',
        str_contains($syncLib, 'COALESCE(VALUES(occupant_name), occupant_name)')
    );
    check_bool(
        '守る側が既定',
        str_contains($syncLib, 'bool $keepOccupantNames = true')
    );
    /*
     * 列は在るものだけ書く。移行 SQL は配備利用者が別に流すもので、
     * コードの配備と順番が決まっていない。**決め打ちは取り込みごと止める。**
     */
    check_bool('在る列だけ書く', str_contains($syncLib, 'array_intersect($wanted, $available)'));
    check_bool('新しい列を挙げている', str_contains($syncLib, "'tx_power_at_one_meter'"));

    km_check_heading('app-map-publish: イベントを折り込む');
    /*
     * **配信でイベントを落としていた。**
     *
     * 配信を Website 一括にしたとき(2026-09-03)、差し込みだけが
     * `scripts/publish-events.php` に取り残された。管理画面から配信しても
     * **通行止めも臨時の地点も端末に届かない**状態が残っていた。
     *
     * しかも**配信は成功したように見える** —— 地図は配られるので、
     * 気づけるのは会場で「通れないはずの道を案内された」ときだけ。
     */
    $publishLib = (string) file_get_contents(__DIR__ . '/../lib/app-map-publish.php');
    check_bool('Web のイベントを読む', str_contains($publishLib, 'km_app_map_events_from_web('));
    check_bool('地図へ差し込む', str_contains($publishLib, 'km_app_map_replace_events('));
    /*
     * **差し込んでから書く。** 逆にすると、書いたファイルにイベントが入らない。
     */
    $foldAt = strpos($publishLib, 'km_app_map_replace_events(');
    $encodeAt = strpos($publishLib, 'json_encode($map,');
    check_bool(
        '差し込んでから JSON にする',
        $foldAt !== false && $encodeAt !== false && $foldAt < $encodeAt
    );
    /*
     * **活きているイベントを人に入力させない。**
     * 打ち間違えると端末は「そのイベントは無い」状態になり、配信は成功して見える。
     */
    check_bool(
        '会期のイベントは差し込んだ結果から決める',
        str_contains($publishLib, "'activeEventUuid' => \$events['activeEventUuid']")
    );
    $publishPage = (string) file_get_contents(__DIR__ . '/../admin/map-publish.php');
    check_bool('画面から自由入力を外した', !str_contains($publishPage, "name=\"active_event_uuid\""));
    // 対応づかなかった通行止めは数えて出す(地点を作り直すと静かに外れる)
    check_bool('外れた通行止めを出す', str_contains($publishPage, "eventSkipped['unknownNode']"));

    km_check_heading('map-data: 建物平面図の重ね');
    /*
     * 屋外図の上に置く建物の見取り図。**画像はアプリの APK 由来の写し。**
     * アプリに建物を足したらこちらにも要る —— 足し忘れると、
     * その建物だけ Web に出ない。**黙って飛ばさず数える**ようにしてある。
     */
    $mapData = (string) file_get_contents(__DIR__ . '/../lib/map-data.php');
    check_bool('配置を読んでいる', str_contains($mapData, 'km_map_overlays'));
    check_bool('見えないものは出さない', str_contains($mapData, 'WHERE visible = 1'));
    // 設定由来の値でも、置き場の外を読ませない
    check_bool('キーを絞っている', str_contains($mapData, "preg_match('/^[a-z0-9_]+$/', \$key)"));
    check_bool('無い画像は数える', str_contains($mapData, "'missing'"));

    $bldgDir = __DIR__ . '/../Main/Picture/bldg';
    $images = glob($bldgDir . '/*.png') ?: [];
    check_bool('建物の画像が置いてある', $images !== [], (string) count($images) . ' 枚');
    /*
     * **錠の内側に置く。** `Main/Picture/*` は nginx が塞いであり
     * (`api/floor-image.php` を通させるため)、別の場所に置くと
     * 錠の外から取れてしまう。
     *
     * 実体で確かめる —— 文字列の書き方(`/` か `\`)では確かめたことにならない。
     */
    $pictureBase = realpath(__DIR__ . '/../Main/Picture');
    $bldgReal = realpath($bldgDir);
    check_bool(
        '置き場は Main/Picture の内側',
        $pictureBase !== false && $bldgReal !== false
            && str_starts_with($bldgReal, $pictureBase . DIRECTORY_SEPARATOR)
    );

    $floorImage = (string) file_get_contents(__DIR__ . '/../api/floor-image.php');
    check_bool('建物も同じ口から配る', str_contains($floorImage, "\$_GET['building']"));
    // 錠を見てから配る。この判定より前に建物の分岐を置くと、素通しになる
    $lockAt = strpos($floorImage, 'if (!$viewUnlocked)');
    $buildingAt = strpos($floorImage, "\$buildingKey = ");
    check_bool(
        '錠を見たあとで配る',
        $lockAt !== false && $buildingAt !== false && $lockAt < $buildingAt
    );

    km_check_heading('app-map-sync: 上げたものはすぐには入れない');
    // 置き場は uploads(nginx が直接配らない)。配信中の地図と同じ守り方
    check_bool('置き場は別名', str_contains($syncLib, "KM_APP_MAP_IMPORT_FILE = 'app-map-import.json'"));
    // 上限が無いと、巨大なファイルでメモリを使い切らせられる
    check_bool('大きさの上限がある', str_contains($syncLib, 'KM_APP_MAP_IMPORT_MAX_BYTES'));
    // **置く前に読む。** 壊れたファイルを置くと「取り込み待ちがある」と誤解される
    $storeAt = strpos($syncLib, 'function km_app_map_import_store(');
    $decodeAt = strpos($syncLib, 'km_app_map_decode_snapshot($raw)');
    $writeAt = strpos($syncLib, 'file_put_contents($path, $raw)');
    check_bool(
        '置く前に中身を確かめる',
        $storeAt !== false && $decodeAt !== false && $writeAt !== false
            && $storeAt < $decodeAt && $decodeAt < $writeAt
    );
}

/**
 * Web のイベント → アプリの地図。**ここだけ向きが逆になる。**
 *
 * 地図はアプリが正本だが、イベントは Website の管理画面が正本
 * (2026-09-02 の分担)。配信のときに1回だけ差し込む
 * (scripts/publish-events.php)。DB は使わないので、ここで全部検査できる。
 */
function km_check_app_map_web_events(): void
{
    require_once __DIR__ . '/../lib/app-map-convert.php';

    km_check_heading('app-map-convert: 階の対応(Web → アプリ)');
    check('1 は 1F', '1F', km_app_map_app_floor('1'));
    check('outside は OUTSIDE', 'OUTSIDE', km_app_map_app_floor('outside'));
    check_bool('知らない階は null', km_app_map_app_floor('b1') === null);
    // **往復して戻ること。** 対応表を1つしか持たないことの確認でもある
    foreach (KM_APP_MAP_FLOOR_MAP as $appFloor => $webFloor) {
        check("往復で戻る({$appFloor})", $appFloor, km_app_map_app_floor($webFloor));
    }

    km_check_heading('app-map-convert: Web のイベントを差し込む');
    $uuidA = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $uuidB = '11111111-2222-3333-4444-555555555555';
    $uuidC = '99999999-8888-7777-6666-555555555555';
    $map = json_decode(json_encode([
        'nodes' => [
            ['uuid' => $uuidA, 'floor' => '1F', 'title' => '廊下', 'subtitle' => ''],
            ['uuid' => $uuidB, 'floor' => '1F', 'title' => '階段', 'subtitle' => ''],
            // 別の階。**階をまたぐ線は作らない**(アプリのキーに階が1つしか入らない)
            ['uuid' => $uuidC, 'floor' => '2F', 'title' => '階段', 'subtitle' => ''],
        ],
        'lines' => [],
        'events' => [['uuid' => 'app-old', 'name' => 'アプリで作った古いもの']],
    ]));

    $shortA = km_app_map_node_id($uuidA);
    $shortB = km_app_map_node_id($uuidB);
    $shortC = km_app_map_node_id($uuidC);

    $overlay = [
        'events' => [
            ['id' => 1, 'name' => '高専祭', 'endsAt' => '2026-09-03 17:00:00'],
            // 終わりが早い方に合わせる。**遅い方に合わせない** ——
            // 終わった通行止めが残ると、通れる道を「通れません」と言い続ける
            ['id' => 2, 'name' => '工事', 'endsAt' => '2026-09-02 09:00:00'],
        ],
        'closedNodes' => [$shortB => '工事中'],
        'closedEdges' => [
            // overlay は両方向を持つ。**畳んで1本にする**
            km_map_event_edge_key($shortA, $shortB) => '',
            km_map_event_edge_key($shortB, $shortA) => '',
            // 階をまたぐ線
            km_map_event_edge_key($shortA, $shortC) => '',
            km_map_event_edge_key($shortC, $shortA) => '',
            // 地図に無い地点
            km_map_event_edge_key($shortA, str_repeat('f', 32)) => '',
        ],
        'pois' => [
            ['id' => 7, 'floor' => '1', 'name' => 'たこ焼き', 'category' => 'food',
                'x' => 500, 'y' => 300, 'note' => ''],
            // 知らない階。**黙って落とさず数える**
            ['id' => 8, 'floor' => 'b1', 'name' => '倉庫', 'category' => 'other',
                'x' => 1, 'y' => 2, 'note' => ''],
        ],
    ];

    $result = km_app_map_events_from_web($overlay, $map);
    $event = $result['events'][0];

    check('イベントは1件にまとめる', 1, count($result['events']));
    check('指し先は固定', KM_APP_MAP_WEB_EVENT_UUID, $result['activeEventUuid']);
    check('名前を並べる', '高専祭 / 工事', $event['name']);

    // 終わりは一番早いもの
    check(
        '会期の終わりは早い方',
        strtotime('2026-09-02 09:00:00') * 1000,
        $event['endAtMillis']
    );
    // **いま有効な会期なら開始は入れない。** Website 側で判定済みのものだけが来る
    check_bool('開始は入れない', $event['startAtMillis'] === null);
    /*
     * **まだ始まっていない会期を焼いたときは開始を入れる**(2026-10-24、利用者の指摘
     * 「一般向けアプリでイベントが丸ごと効いていない」)。入れないと、配信した瞬間から
     * 通行止めと臨時の地点が効いてしまう。アプリは activeMapEvent() でこの時刻から効かせる
     */
    $pending = km_app_map_events_from_web($overlay, $map, '2026-11-01 09:00:00');
    check(
        '会期前なら開始を入れる',
        strtotime('2026-11-01 09:00:00') * 1000,
        $pending['events'][0]['startAtMillis']
    );
    check_bool('渡さなければ今までどおり null', km_app_map_events_from_web($overlay, $map, null)['events'][0]['startAtMillis'] === null);
    check_bool('読めない時刻は入れない', km_app_map_events_from_web($overlay, $map, 'いつか')['events'][0]['startAtMillis'] === null);

    check('通行止めの地点', [$uuidB], $event['closedNodeUuids']);
    // 両方向が畳まれ、階をまたぐものと知らない地点は落ちて、残るのは1本
    check('通行止めの線は1本', 1, count($event['closedLineKeys']));
    $endpoints = [$uuidA, $uuidB];
    sort($endpoints);
    check('線のキーは階と両端(並べ替え済み)', '1F:' . $endpoints[0] . ':' . $endpoints[1], $event['closedLineKeys'][0]);
    check('階をまたぐ線を数える', 1, $result['skipped']['edgeAcrossFloors']);
    check('地図に無い地点を数える', 1, $result['skipped']['unknownNode']);

    check('臨時の地点は1件', 1, count($event['places']));
    check('種別は言葉にする', '模擬店', $event['places'][0]['category']);
    check('階はアプリの表記へ', '1F', $event['places'][0]['floor']);
    // Y は反転する。**忘れると上下逆さまになる**
    check('Y を反転する', KM_APP_MAP_IMAGE_HEIGHT - 300.0, $event['places'][0]['y']);
    check('X はそのまま', 500.0, $event['places'][0]['x']);
    // ノードの UUID とぶつからない形にする
    check_bool('臨時の地点の ID は接頭辞つき', str_starts_with($event['places'][0]['uuid'], 'web-poi-'));
    // **勝手に隠さない。** Website 側にスタッフ限定の列が無い
    check_bool('staffOnly は false', $event['places'][0]['staffOnly'] === false);
    check('知らない階を数える', ['b1'], $result['skipped']['unknownFloor']);

    km_check_heading('app-map-convert: 差し込みで置き換える');
    km_app_map_replace_events($map, $result['events']);
    check('アプリ側の古いイベントは残さない', 1, count($map->events));
    check('残るのは差し込んだもの', KM_APP_MAP_WEB_EVENT_UUID, $map->events[0]['uuid']);

    // **「イベントが無い」と「差し込まない」は別。** 会期が終われば空で上書きする
    $none = km_app_map_events_from_web(['events' => []], $map);
    check('有効なイベントが無ければ空', [], $none['events']);
    check_bool('指し先も null', $none['activeEventUuid'] === null);

    /*
     * ======== 配信に焼くのは「次に効く会期」(2026-10-24) ========
     *
     * 利用者の指摘「Android 側の一般向けで、イベント時にイベントの名前で表示されていない」。
     * 一般向けビルドは**配信に載っているイベントしか効かせない**(resolveEventSelection。
     * 管理ビルドは自分で選べるので、同じ地図でも効いて見える)。配信は焼き付けた写しなので、
     * 「いま有効」なものだけを焼くと、**会期の前に配信した地図にはイベントが 1 件も入らない**。
     *
     * DB は使わずに、配信の 2 か所が「次に効く会期」を使っていることと、
     * 選び方(いま有効 → それ / 無ければ一番早い会期)を、コードの形で見る
     */
    km_check_heading('app-map: 配信に焼くのは「次に効く会期」(2026-10-24)');
    $publishLib = (string) file_get_contents(__DIR__ . '/../lib/app-map-publish.php');
    $eventsLib = (string) file_get_contents(__DIR__ . '/../lib/map-events.php');
    check_bool('Website の正本は次に効く会期を焼く', str_contains($publishLib, '$window = km_map_event_overlay_for_app($pdo);')
        && str_contains($publishLib, "km_app_map_events_from_web(\$overlay, \$map, \$window['startsAt'])"));
    check_bool('イベント用の地図の折り込みも同じ', str_contains($publishLib, "km_app_map_events_from_web(\$window['overlay'], \$map, \$window['startsAt'])"));
    check_bool('公開ページは今までどおり「いま有効」だけ', str_contains($eventsLib, '$events = $onlyPreview ? [] : km_map_event_active($pdo);')
        && !str_contains($eventsLib, 'km_map_event_overlay_for_app($pdo)'));
    // 配信の画面は**実際に焼かれるもの**を見せる(「いま有効」だけを見せると、会期前に空に見えるのに中身は入る)
    $publishPage = (string) file_get_contents(__DIR__ . '/../admin/map-publish.php');
    check_bool('配信の画面は焼かれる会期を見せる', str_contains($publishPage, '$publishWindow = km_map_event_overlay_for_app(km_db());')
        && str_contains($publishPage, 'page.mapPublish.eventPending'));
    foreach (['ja', 'en'] as $lang) {
        check_bool(
            "i18n: mapPublish.eventPending ($lang)",
            str_contains((string) file_get_contents(__DIR__ . '/../admin/assets/i18n/' . $lang . '.js'), 'page.mapPublish.eventPending')
        );
    }
    // 終わっていないもの(いま有効 + これから)を取り、有効かどうかは **DB の NOW()** で見る
    check_bool('終わっていないイベントを取る', str_contains($eventsLib, 'WHERE is_enabled = 1 AND (ends_at IS NULL OR ends_at > NOW())')
        && str_contains($eventsLib, '(starts_at IS NULL OR starts_at <= NOW()) AS isActive'));
    // **会期をまたいで混ぜない。** 工事(長期)と高専祭(翌週)を 1 つに畳むと、通行止めが今日から効く
    check_bool('いま有効なものがあればそれだけ', str_contains($eventsLib, "return ['overlay' => km_map_event_overlay_build(\$pdo, \$active), 'startsAt' => null];"));
    check_bool('無ければ一番早い会期だけ', str_contains($eventsLib, "static fn (array \$row): bool => (string) (\$row['startsAt'] ?? '') === \$startsAt"));
    // イベントの不調で配信そのものを止めない(表がまだ無いときも地図は配れる)
    check_bool('不調なら「イベント無し」に倒す', str_contains($eventsLib, "error_log('km_map_event_overlay_for_app failed (publishing without events): '"));
    // 配信し直し忘れに気づける場所(管理画面「ダウンロード」)
    $downloadsPage = (string) file_get_contents(__DIR__ . '/../admin/downloads.php');
    check_bool('配信に載っていなければ管理画面で知らせる', str_contains($downloadsPage, 'page.downloads.mapReleaseEventStale')
        && str_contains($downloadsPage, '$mapEventWindow'));
    foreach (['ja', 'en'] as $lang) {
        check_bool(
            "i18n: mapReleaseEventStale ($lang)",
            str_contains((string) file_get_contents(__DIR__ . '/../admin/assets/i18n/' . $lang . '.js'), 'page.downloads.mapReleaseEventStale')
        );
    }
}

/**
 * 案内チャット(2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」。lib/guide-chat.php)。
 * LLM は差し替えて試す(自宅 PC の Bionic は要らない)。
 */
function km_check_guide_chat(): void
{
    require_once __DIR__ . '/../lib/guide-chat.php';

    km_check_heading('guide-chat: 使える人(テストユーザー 1 だけ)');
    $now = 1_800_000_000;
    $on = ['enabled' => true, 'sub' => 'testUser1', 'dailyLimit' => 100];
    $session = [KM_TEST_MODE_SESSION_KEY => ['sub' => 'testUser1', 'until' => $now + 60]];
    check_bool('選んだテスト用アカウントの印があれば使える', km_guide_allowed($session, $now, $on));
    check_bool('ほかのテスト用アカウントは使えない', !km_guide_allowed([KM_TEST_MODE_SESSION_KEY => ['sub' => 'testUser2', 'until' => $now + 60]], $now, $on));
    check_bool('期限の切れた印は使えない', !km_guide_allowed([KM_TEST_MODE_SESSION_KEY => ['sub' => 'testUser1', 'until' => $now - 1]], $now, $on));
    check_bool('印の無い人(一般の来場者)は使えない', !km_guide_allowed([], $now, $on));
    check_bool('OFF なら誰も使えない', !km_guide_allowed($session, $now, ['enabled' => false] + $on));
    check_bool('人を選んでいなければ誰も使えない', !km_guide_allowed($session, $now, ['sub' => ''] + $on));
    // イベント中の来場者の印(event:…)では使えない(テストモードの印だけを見る)
    check_bool('イベント中の来場者の印では使えない', !km_guide_allowed([KM_EVENT_COLLECT_SESSION_KEY => ['subject' => 'event:1', 'until' => $now + 60]], $now, $on));

    km_check_heading('guide-chat: 回数(1 分 6 回)');
    $rate = ['allowed' => true, 'times' => []];
    for ($i = 0; $i < KM_GUIDE_PER_MINUTE; $i++) {
        $rate = km_guide_rate_check($rate['times'], $now + $i);
    }
    check_bool('6 回目までは通す', $rate['allowed']);
    check_bool('7 回目は止める', !km_guide_rate_check($rate['times'], $now + 10)['allowed']);
    check_bool('1 分たてばまた通す', km_guide_rate_check($rate['times'], $now + 70)['allowed']);
    check('止めたときは数を増やさない', KM_GUIDE_PER_MINUTE, count(km_guide_rate_check($rate['times'], $now + 10)['times']));

    km_check_heading('guide-chat: 探す(地図に登録されているものだけ)');
    check('文字をそろえる(半角カナ・全角英数・区切り)', ['たこ焼きa1', 'たこやき'], [km_guide_normalize('ﾀｺ焼き Ａ-1'), km_guide_normalize('タコヤキ')]);
    $mapData = [
        'event' => ['id' => 1],
        'nodes' => [
            'n1' => ['type' => 'room', 'name' => '第二実習室 管-105', 'alias' => 'お化け屋敷', 'floor' => '1', 'projects' => ['22'], 'occupantName' => '架空 太郎'],
            'n2' => ['type' => 'facility', 'name' => '図書館', 'floor' => 'outside'],
            'n3' => ['type' => 'room', 'name' => '教員室', 'floor' => '2', 'hidden' => true],
            'n4' => ['type' => 'road', 'name' => '', 'floor' => '1'],
        ],
        'eventPois' => [
            ['id' => 3, 'name' => 'たこ焼き', 'category' => 'food', 'floor' => 'outside', 'anchorNodeId' => 'n2', 'note' => 'ソース味'],
            ['id' => 4, 'name' => 'クレープ', 'category' => 'food', 'floor' => 'outside', 'anchorNodeId' => 'n2', 'note' => ''],
        ],
        'posters' => [
            ['id' => 9, 'title' => 'たこ焼き屋 A組', 'description' => '熱々', 'nodeIds' => [], 'poiIds' => [3], 'likes' => 2, 'isAd' => false],
            ['id' => 10, 'title' => 'クレープ屋', 'description' => '甘い', 'nodeIds' => [], 'poiIds' => [4], 'likes' => 7, 'isAd' => false],
            ['id' => 11, 'title' => '広告', 'description' => '', 'nodeIds' => ['n1'], 'poiIds' => [], 'likes' => 99, 'isAd' => true],
        ],
        'schedule' => [['title' => '上演', 'startsAt' => '10:00', 'endsAt' => '11:00', 'nodeIds' => ['n1'], 'poiIds' => []]],
    ];
    $catalog = km_guide_catalog($mapData);
    check('店: 一時地点 2 つとイベントの地点 1 つ', 3, count($catalog['shops']));
    check('場所: 出さない地点・道は入れない', ['poi', 'poi', 'node', 'node'], array_column($catalog['places'], 'kind'));
    check_bool('先生の名前は入れない', !str_contains(json_encode($catalog, JSON_UNESCAPED_UNICODE), '架空'));
    check_bool('広告のポスターは入れない', !str_contains(json_encode($catalog, JSON_UNESCAPED_UNICODE), '広告'));
    // 並びは 点数 → ♡ の数(登録されている数)→ 名前。価格・待ち時間は使わない
    check('種類で探すと ♡ の多い順', ['クレープ', 'たこ焼き'], array_column(km_guide_search($catalog['shops'], [], 'food'), 'name'));
    check('言葉はポスターの見出しでも当たる', ['たこ焼き'], array_column(km_guide_search($catalog['shops'], ['A組']), 'name'));
    check('企画の番号でも当たる', ['お化け屋敷'], array_column(km_guide_search($catalog['shops'], ['22']), 'name'));
    check('当たらなければ空(作らない)', [], km_guide_search($catalog['shops'], ['メロンパン']));

    km_check_heading('guide-chat: LLM の返事を読む・答えを作る');
    check('JSON の前後の文は捨てる・種類は決まったものだけ', ['intent' => 'shop', 'keywords' => ['たこ焼き'], 'category' => null, 'from' => null, 'to' => null],
        km_guide_parse_classification("はい。\n{\"intent\":\"shop\",\"keywords\":[\"たこ焼き\",\"\"],\"category\":\"burger\"}"));
    check_bool('JSON でなければ null', km_guide_parse_classification('わかりません') === null);
    check('知らない intent は other', 'other', km_guide_parse_classification('{"intent":"hack"}')['intent'] ?? null);
    // Bionic の Qwen3.5-9B は空を文字の "null" で返すことがある(2026-10-25 のベンチ)
    check('文字の "null" は空として読む(出発地・目的地・言葉)', ['keywords' => ['道'], 'from' => null, 'to' => '図書館'],
        array_intersect_key(km_guide_parse_classification('{"intent":"route","keywords":["null","道"],"from":"null","to":"図書館"}') ?? [], ['from' => 1, 'to' => 1, 'keywords' => 1]));
    // 経路: 言葉から地点を決めるだけ。経路そのものはブラウザの dijkstra.js
    $plan = km_guide_plan($catalog, ['intent' => 'route', 'keywords' => [], 'category' => null, 'from' => 'お化け屋敷', 'to' => 'たこ焼き']);
    check('経路の端は地点の id と一時地点の番号', [['nodeId' => 'n1', 'poiId' => null, 'name' => 'お化け屋敷'], ['nodeId' => 'n2', 'poiId' => 3, 'name' => 'たこ焼き']],
        [$plan['route']['from'], $plan['route']['to']]);
    /*
     * 2026-10-25、利用者「第二実習室から体育館への行き方」でトレーニングルームへ行った。「体育館」は多くの地点に途中で当たって
     * 同じ点数になり、名前の字の順でカタカナの「ト」が先だった。名前の短い方(言葉をよく言い当てている方)を先にする
     */
    $gym = km_guide_catalog(['event' => null, 'eventPois' => [], 'posters' => [
        ['id' => 1, 'title' => '筋トレ体験', 'description' => '', 'nodeIds' => ['g3'], 'poiIds' => [], 'likes' => 5, 'isAd' => false],
    ], 'nodes' => [
        'r1' => ['type' => 'room', 'name' => '第二実習室 管-105', 'floor' => '1'],
        'g3' => ['type' => 'room', 'name' => 'トレーニングルーム 第一体育館', 'floor' => 'bldg_gym1'],
        'g4' => ['type' => 'room', 'name' => '男WC 第一体育館', 'floor' => 'bldg_gym1'],
        'g5' => ['type' => 'entrance', 'name' => '第一体育館 出入り口', 'floor' => 'outside'],
        'g2' => ['type' => 'facility', 'name' => '第二体育館', 'floor' => 'outside'],
        'g1' => ['type' => 'facility', 'name' => '第一体育館', 'floor' => 'outside'],
    ]]);
    $gymPlan = km_guide_plan($gym, ['intent' => 'route', 'keywords' => [], 'category' => null, 'from' => '第二実習室', 'to' => '体育館']);
    check('経路: 「体育館」は建物そのもの(名前の短い方。♡ のある部屋より先)', 'g1', $gymPlan['route']['to']['nodeId'] ?? null);
    check('場所: 「体育館はどこ」も建物そのものが先', ['第一体育館', '第二体育館'],
        array_slice(array_column(km_guide_plan($gym, ['intent' => 'place', 'keywords' => ['体育館'], 'category' => null, 'from' => null, 'to' => null])['cards'], 'name'), 0, 2));
    check('経路: LLM には役目(出発地・目的地)を付けて渡す', [['出発地', '第二実習室 管-105'], ['目的地', '第一体育館']],
        array_map(static fn (array $r): array => [$r['役目'], $r['名前']], $gymPlan['records']));

    /*
     * 読み方・書き方の違い(2026-10-25、利用者「レッツプレイ電算 → Let's play densan! このようなタイポにも対応」)。
     * 言葉で当たらなかったときだけ、1 段目は LLM の言い換えをいつもの検索で、2 段目は一覧の番号で選ばせる
     */
    km_check_heading('guide-chat: 読み方・書き方の違い(言い換え → 一覧から選ぶ)');
    $densan = km_guide_catalog(['event' => null, 'eventPois' => [], 'posters' => [
        ['id' => 1, 'title' => "Let's play Densan!", 'description' => '電子計算機部', 'nodeIds' => ['r1'], 'poiIds' => [], 'likes' => 0, 'isAd' => false],
    ], 'nodes' => [
        'r1' => ['type' => 'room', 'name' => '第二実習室 管-105', 'floor' => '1'],
        'g1' => ['type' => 'facility', 'name' => '第一体育館', 'floor' => 'outside'],
    ]]);
    check('一覧の名前はポスターの見出しも並べる(id は入れない)', ['第二実習室 管-105 / Let\'s play Densan!', '第一体育館'], km_guide_match_names($densan['places']));
    check('言い換えは 4 つまで・元の言葉と同じものは捨てる', ["Let's play 電算", '体育館'],
        km_guide_parse_variants('{"variants":["レッツプレイ電算","Let\'s play 電算","","体育館","体育館"]}', 'レッツプレイ電算'));
    check('一覧の番号は範囲だけ通す(0 から数えた位置で返す)', [1, null, null, null, 0],
        [km_guide_parse_match('{"number": 2}', 2), km_guide_parse_match('{"number": 3}', 2), km_guide_parse_match('{"number": null}', 2), km_guide_parse_match('わかりません', 2), km_guide_parse_match('1', 2)]);
    // 言い換えで当たらなければ一覧から選ばせる。LLM は差し替える
    $asked = [];
    $fakeLlm = static function (array $messages, int $maxTokens) use (&$asked): string {
        $asked[] = $maxTokens;
        $system = $messages[0]['content'];
        if (str_contains($system, '分類し')) {
            return '{"intent":"route","keywords":[],"category":null,"from":"レッツプレイ電算","to":"たいいくかん"}';
        }
        if (str_contains($system, '別の書き方')) {
            return str_contains($messages[1]['content'], 'たいいくかん') ? '{"variants":["体育館"]}' : '{"variants":["Let\'s play 電算"]}';
        }
        if (str_contains($system, 'どれを指しているか')) {
            return '{"number": 1}';
        }
        return '経路を出します。';
    };
    $densanAnswer = km_guide_answer($densan, 'レッツプレイ電算からたいいくかんへの行き方', $fakeLlm);
    check('言い換え(たいいくかん → 体育館)と一覧(レッツプレイ電算 → 第二実習室)で経路の両端が決まる', ['r1', 'g1'],
        [$densanAnswer['route']['from']['nodeId'] ?? null, $densanAnswer['route']['to']['nodeId'] ?? null]);
    check('答えの結果に質問の種類が付く(残すため。画面へは返さない)', 'route', $densanAnswer['intent'] ?? null);
    check_bool('言い換え・一覧の頼みは決まった字数(口が時間切れで断れるように)', in_array(KM_GUIDE_RESOLVE_TOKENS, $asked, true));
    $throwing = static function (array $messages, int $maxTokens): string {
        if ($maxTokens === KM_GUIDE_RESOLVE_TOKENS) {
            throw new RuntimeException('時間切れ');
        }
        return str_contains($messages[0]['content'], '分類し') ? '{"intent":"place","keywords":["メロンパン"]}' : '見つかりませんでした。';
    };
    $melon = km_guide_answer($densan, 'メロンパンはどこ', $throwing);
    check_bool('言い換えがつまずいても答えは止めない(LLM なしに倒さない)・カードは出ない', $melon['fallback'] === false && $melon['cards'] === []);

    /*
     * 関係の率で選ぶ(2026-10-25、利用者「ユーザーの問いかけに対して関係のあるものを率として考えてみては？」。案内の 45 件の記録を見て)
     */
    km_check_heading('guide-chat: 関係の率で選ぶ');
    $rateCatalog = km_guide_catalog(['event' => ['id' => 1], 'eventPois' => [
        ['id' => 1, 'name' => 'ゴミ箱', 'category' => 'other', 'floor' => 'outside', 'anchorNodeId' => 'w1', 'note' => ''],
        ['id' => 2, 'name' => '8', 'category' => 'food', 'floor' => 'outside', 'anchorNodeId' => 'w1', 'note' => ''],
    ], 'posters' => [
        ['id' => 1, 'title' => 'VRお化け屋敷', 'description' => 'こわい体験', 'nodeIds' => ['h1'], 'poiIds' => [], 'likes' => 3, 'isAd' => false],
    ], 'nodes' => [
        'h1' => ['type' => 'room', 'name' => 'ファーストペンギンラボ 7-102', 'floor' => '1', 'projects' => ['12']],
        'w1' => ['type' => 'room', 'name' => '男WC 1号棟', 'floor' => '1'],
    ]]);
    $rateItems = km_guide_relevance_items($rateCatalog);
    check('一覧: 店と場所を合わせて同じものは 1 つ・ゴミ箱は入れない', ['8', 'ファーストペンギンラボ 7-102', '男WC 1号棟'], array_column($rateItems, 'name'));
    check_bool('一覧の 1 行: 名前 / 見出し・種類と階・説明の頭(id は入れない)', str_contains(km_guide_relevance_messages('怖い店', $rateItems)[0]['content'], '2. ファーストペンギンラボ 7-102 / VRお化け屋敷 (1F) こわい体験')
        && !str_contains(json_encode(km_guide_relevance_messages('怖い店', $rateItems), JSON_UNESCAPED_UNICODE), 'h1'));
    check('率の返事: 名前が番号と合うものだけ・ずれた番号は名前で付け替え・名前の無い行と範囲外は捨てる', [1 => 95, 2 => 70],
        km_guide_parse_relevance('{"items":[{"n":2,"name":"VRお化け屋敷","p":95},{"n":1,"name":"男WC 1号棟","p":70},{"n":1,"p":99},{"n":9,"name":"x","p":80},{"n":3,"name":"関係ない店","p":100}]}', $rateItems));
    check('率の返事: 途中で切れても書き終わった行は使う・読めなければ null', [[1 => 90], null],
        [km_guide_parse_relevance('{"items":[{"n":2,"name":"VRお化け屋敷","p":90},{"n":3,"name":"男W', $rateItems), km_guide_parse_relevance('わかりません', $rateItems)]);
    $ranked = km_guide_rank_by_relevance($rateItems, [0 => 50, 1 => 95, 2 => 70]);
    check('並べ方: 下限(60%)以上を率の高い順・カードと LLM に率を付ける', [['VRお化け屋敷', 95], ['男WC 1号棟', 70]],
        array_map(static fn (array $c): array => [$c['name'], $c['relevance']], array_map('km_guide_card', $ranked)));
    check('LLM には「質問との関係」を渡す', '95%', km_guide_record_for_llm($ranked[0])['質問との関係'] ?? null);
    $ratePlan = km_guide_plan($rateCatalog, ['intent' => 'other', 'keywords' => ['こわい'], 'category' => null, 'from' => null, 'to' => null], null, '',
        static fn (array $items): ?array => [1 => 100]);
    check('その他に分けられても率で選ぶ', ['VRお化け屋敷'], array_column($ratePlan['cards'], 'name'));
    check('率が付けられなければ(null)今までの言葉の一致', ['男WC 1号棟'],
        array_column(km_guide_plan($rateCatalog, ['intent' => 'place', 'keywords' => ['WC'], 'category' => null, 'from' => null, 'to' => null], null, '', static fn (array $items): ?array => null)['cards'], 'name'));
    $chatJs = (string) file_get_contents(__DIR__ . '/../Main/guide-chat.js');
    check_bool('画面: 例を押すと入力して送る・企画番号の例を先頭(いちばん確実)・苦手な質問を理由と並べる・率をカードに出す',
        str_contains($chatJs, "{ text: '企画番号 8 の場所を教えて', note: 'いちばん確実' },") && strpos($chatJs, '企画番号 8') < strpos($chatJs, "{ text: '食べ物の店を教えて' }")
        && str_contains($chatJs, "input.value = example.text;\n                ask(example.text);") && str_contains($chatJs, "'苦手な質問(答えられない・まちがえやすい)'")
        && str_contains($chatJs, "meta.push('関係 ' + card.relevance + '%');"));

    // テスト用アカウントの出発地(2026-10-25、利用者「テストユーザーのみ、出発点を Let's Play Densan に」)
    km_check_heading('guide-chat: テスト用アカウントの出発地');
    $toGym = ['intent' => 'route', 'keywords' => [], 'category' => null, 'from' => null, 'to' => '体育館'];
    $herePlan = km_guide_plan($densan, $toGym, null, 'r1');
    check('出発地を言わなければ展示の場所から', ['r1', 'g1'], [$herePlan['route']['from']['nodeId'] ?? null, $herePlan['route']['to']['nodeId'] ?? null]);
    check_bool('LLM には「いまいる場所」として見出しと部屋の名前で伝える', str_contains($herePlan['situation'], "いま利用者がいる「Let's play Densan!(第二実習室 管-105)」"));
    check('言った出発地のほうが先', 'g1', km_guide_plan($densan, ['from' => '体育館', 'to' => '第二実習室'] + $toGym, null, 'r1')['route']['from']['nodeId'] ?? null);
    check('設定が無ければ今までどおり(出発地は地図で選ぶ)', null, km_guide_plan($densan, $toGym)['route']['from'] ?? null);
    check('目的地が展示の場所そのものなら入れない', null, km_guide_plan($densan, ['to' => '第二実習室'] + $toGym, null, 'r1')['route']['from'] ?? null);
    // 現在地(2026-10-25、利用者「現在地はどこですか？や、現在地から～～への行き方。などは lets play densan に」)
    check('「現在地から」「ここから」は展示の場所から(地図の名前として探さない)', ['r1', 'r1', 'r1'], array_map(
        static fn (string $word): ?string => km_guide_plan($densan, ['from' => $word] + $toGym, null, 'r1')['route']['from']['nodeId'] ?? null,
        ['現在地', 'ここ', '今いる場所']
    ));
    check('現在地の言葉', [true, true, true, false, false], array_map('km_guide_is_here_word', ['現在地', 'ここ', 'いまいるところ', '第二実習室', null]));
    // 一時地点の名前が番号だけなら、経路では見出しを名前にする(「ここから献血への行き方」で目的地が「99」と出た)
    $numbered = km_guide_catalog(['event' => ['id' => 1], 'posters' => [['id' => 5, 'title' => '献血', 'description' => '', 'nodeIds' => [], 'poiIds' => [9], 'likes' => 0, 'isAd' => false]],
        'eventPois' => [['id' => 9, 'name' => '99', 'category' => 'other', 'floor' => 'outside', 'anchorNodeId' => 'r1', 'note' => '']],
        'nodes' => ['r1' => ['type' => 'room', 'name' => '第二実習室 管-105', 'floor' => '1']]]);
    $numberedPlan = km_guide_plan($numbered, ['intent' => 'route', 'keywords' => [], 'category' => null, 'from' => '第二実習室', 'to' => '献血']);
    check('経路: 名前が番号だけの一時地点は見出しで出す', ['献血', '献血'], [$numberedPlan['route']['to']['name'] ?? null, $numberedPlan['cards'][1]['name'] ?? null]);
    check('「現在地はどこ？」だけを当てる(「ここのトイレはどこ？」は当てない)', [true, true, true, true, false, false, false],
        array_map('km_guide_asks_here', ['現在地はどこですか？', 'ここはどこ', '今いる場所を教えて', '現在地', 'ここのトイレはどこ？', '現在地から体育館への行き方', 'ここ']));
    $hereCalls = 0;
    $hereAnswer = km_guide_answer($densan, '現在地はどこですか？', static function () use (&$hereCalls): string {
        $hereCalls++;
        return '';
    }, 'r1');
    check('「現在地はどこ？」は LLM に聞かずに展示の場所を答える', [0, ["Let's play Densan!"], true],
        [$hereCalls, array_column($hereAnswer['cards'], 'name'), str_contains($hereAnswer['answer'], "いまいる場所は「Let's play Densan!」(第二実習室 管-105・1F)です")]);
    check_bool('既定は第二実習室 管-105 の地点 id・形を確かめる', KM_TEST_START_DEFAULT === '2735e277ed554b74963e82baa0b6a641'
        && km_test_start_node_valid('') && km_test_start_node_valid(KM_TEST_START_DEFAULT) && !km_test_start_node_valid('a"b') && !km_test_start_node_valid(str_repeat('a', 65)));
    $startIndex = (string) file_get_contents(__DIR__ . '/../index.php');
    $startApp = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    $startAdmin = (string) file_get_contents(__DIR__ . '/../admin/test-mode.php');
    check_bool('地図: 出発地の印はテスト用アカウントのときだけ・目的地を選んで出発地が空なら入れる',
        preg_match('/<\?php if \(\$kmTestMode !== null\): \?>.*?<meta name="km-test-start" content="<\?= km_home_e\(\$kmTestMode\[\'startNode\'\]\) \?>">.*?<\?php elseif/s', $startIndex) === 1
        && str_contains($startApp, 'if (!routeStartId && !(opts && opts.noTestStart)) applyTestStart(nodeId);')
        // 案内チャットの経路では入れない(AI の出発地より先に Densan からの経路を引かない。2026-10-25、利用者の指示)
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/guide-chat.js'), "window.setAsLocation(route.to.nodeId, 'end', Object.assign(opts(route.to), { noTestStart: true }));")
        && str_contains($startApp, "if (!node || startId === endId || !isRoutableNode(node)) return;"));
    check_bool('口: 案内チャットに出発地を渡す・管理画面で選ぶ(一覧の地点だけ)', str_contains((string) file_get_contents(__DIR__ . '/../api/guide-chat.php'), '$result = km_guide_answer($catalog, $question, $llm, $startNodeId);')
        && str_contains($startAdmin, "if (\$startNode !== '' && !in_array(\$startNode, array_column(km_poster_node_options(\$pdo), 'id'), true)) {")
        && str_contains($startAdmin, 'km_setting_set($pdo, KM_TEST_START_SETTING, $startNode);'));

    km_check_heading('guide-chat: 質問と答えを残す・評価(2026-10-25)');
    check('メールアドレスと電話番号らしい文字は伏せる(部屋番号・年は残す)', '[メール] か [電話番号] / [電話番号] に 1-209 と 2026 年',
        km_guide_mask_personal('taro.k@example.com か 090-1234-5678 / ０３（１２３４）５６７８ に 1-209 と 2026 年'));
    $logRow = km_guide_log_row('090-1111-2222 です。たこ焼き', ['answer' => 'あります', 'cards' => [['name' => 'たこ焼き']], 'route' => ['from' => null, 'to' => ['name' => '図書館']], 'fallback' => false, 'intent' => 'route'], 2345, '2026-10-25 10:00:00', str_repeat('a', 32));
    check('残す欄は決まったものだけ(誰が聞いたかは入れない)', ['rate_hash', 'asked_at', 'question', 'answer', 'intent', 'cards', 'route', 'fallback', 'elapsed_ms', 'model'], array_keys($logRow));
    check('残す中身: 伏せた質問・カードの名前・経路の名前・合言葉はハッシュ', ['[電話番号] です。たこ焼き', '["たこ焼き"]', '(出発地は地図で選ぶ) → 図書館', hash('sha256', str_repeat('a', 32))],
        [$logRow['question'], $logRow['cards'], $logRow['route'], $logRow['rate_hash']]);
    $token = str_repeat('0f', 16);
    check('評価: GOOD は理由を捨てる', ['rating' => 1, 'reason' => '', 'note' => ''],
        array_intersect_key(km_guide_rating_input(['token' => $token, 'rating' => 'good', 'reason' => 'place', 'note' => 'x']) ?? [], ['rating' => 1, 'reason' => 1, 'note' => 1]));
    check('評価: BAD は理由とひとこと(伏せる)', ['rating' => -1, 'reason' => 'route', 'note' => '[メール] に連絡'],
        array_intersect_key(km_guide_rating_input(['token' => $token, 'rating' => 'bad', 'reason' => 'route', 'note' => ' a@b.jp に連絡 ']) ?? [], ['rating' => 1, 'reason' => 1, 'note' => 1]));
    check_bool('評価: BAD で理由が無い・知らない理由・長すぎるひとこと・合言葉の形が違えば断る',
        km_guide_rating_input(['token' => $token, 'rating' => 'bad']) === null
        && km_guide_rating_input(['token' => $token, 'rating' => 'bad', 'reason' => 'hack']) === null
        && km_guide_rating_input(['token' => $token, 'rating' => 'bad', 'reason' => 'other', 'note' => str_repeat('あ', KM_GUIDE_BAD_NOTE_MAX + 1)]) === null
        && km_guide_rating_input(['token' => 'ZZ', 'rating' => 'good']) === null
        && km_guide_rating_input(['token' => $token, 'rating' => 'meh']) === null);
    $calls = [];
    $fake = static function (array $messages, int $maxTokens) use (&$calls): string {
        $calls[] = $messages;
        return count($calls) === 1
            ? '{"intent":"shop","keywords":[],"category":"food","from":null,"to":null}'
            : 'クレープ屋とたこ焼き屋があります。';
    };
    $answer = km_guide_answer($catalog, '食べ物の店を教えて', $fake);
    // 2026-10-25 から 3 回(分ける・関係の率・答える)。この差し替えは率に文を返すので、率は読めず言葉の一致に戻る
    check('LLM は 3 回(分ける・関係の率・答える)', 3, count($calls));
    check('カードは登録から作る(LLM の文からは作らない)', ['クレープ屋', 'たこ焼き屋 A組'], array_column($answer['cards'], 'name'));
    $answerCall = end($calls);
    check_bool('LLM に地点の id を渡さない', !str_contains(json_encode($calls, JSON_UNESCAPED_UNICODE), '"n2"') && !str_contains(json_encode($calls, JSON_UNESCAPED_UNICODE), 'poiId'));
    check_bool('LLM には「データに無いものを作らない」と言う', str_contains($answerCall[0]['content'], 'データに無い店・場所・値段・メニュー・待ち時間・順位は言わないでください'));
    // LLM が止まっていても、言葉で探した結果は出す(企画書 §8)
    $down = km_guide_answer($catalog, 'クレープの店', static function (): string {
        throw new RuntimeException('LLM に届きません');
    });
    check_bool('LLM が止まっていても言葉で探す', $down['fallback'] === true && array_column($down['cards'], 'place') === ['クレープ']);
    check_bool('LLM を設定していなければ言葉で探す', km_guide_answer($catalog, 'たこ焼き', null)['fallback'] === true);
    check_bool('分けた返事が読めなければ言葉で探す', km_guide_answer($catalog, 'たこ焼き', static fn (): string => 'すみません')['fallback'] === true);

    km_check_heading('guide-chat: 口・設定・画面');
    $api = (string) file_get_contents(__DIR__ . '/../api/guide-chat.php');
    // 通す順: CSRF → 使える人 → 本文 → 回数 → LLM
    $order = [strpos($api, 'km_csrf_verify()'), strpos($api, 'km_guide_allowed($_SESSION'), strpos($api, 'km_api_json_body(2048)'), strpos($api, 'km_guide_rate_check('), strpos($api, 'km_guide_answer(')];
    $sorted = $order;
    sort($sorted);
    check_bool('通す順: CSRF → 使える人 → 本文 → 回数 → LLM', !in_array(false, $order, true) && $order === $sorted);
    check_bool('質問は 200 字まで・制御文字は断る', str_contains($api, 'mb_strlen($question) > KM_GUIDE_QUESTION_MAX') && str_contains($api, '[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]'));
    check_bool('1 日の上限を見る', str_contains($api, "km_guide_asked_today(\$pdo, \$today) >= \$settings['dailyLimit']"));
    check_bool('LLM を待つ前にセッションを閉じる', strpos($api, 'session_write_close();') < strpos($api, 'km_guide_answer('));
    // 本文は残さない(企画書 §8)。ログに出すのは失敗の理由だけ
    check_bool('質問と答えをログに書かない', !preg_match('/error_log\([^;]*\$question/', $api) && !preg_match('/error_log\([^;]*\$question/', (string) file_get_contents(__DIR__ . '/../lib/guide-chat.php')));
    $lib = (string) file_get_contents(__DIR__ . '/../lib/guide-chat.php');
    check_bool('LLM は https だけ・転送しない・TLS を確かめる・返事の大きさを切る', str_contains($lib, "str_starts_with(strtolower(\$url), 'https://')")
        && str_contains($lib, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($lib, 'CURLOPT_SSL_VERIFYPEER => true')
        && str_contains($lib, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS') && str_contains($lib, 'KM_GUIDE_LLM_MAX_BYTES'));
    check_bool('鍵は環境変数から(ブラウザに出さない)', str_contains($lib, "getenv('KM_GUIDE_LLM_KEY')")
        && !str_contains((string) file_get_contents(__DIR__ . '/../Main/guide-chat.js'), 'KM_GUIDE_LLM'));
    $root = km_check_repo_root();
    $guideNginx = (string) @file_get_contents($root . '/nginx/default.conf.template');
    check_bool('nginx: 専用の zone で IP ごとに絞る・本文 4k・LLM を待つ 90 秒', str_contains($guideNginx, 'limit_req_zone $binary_remote_addr zone=km_guide:10m rate=10r/m;')
        && preg_match('/location = \/api\/guide-chat\.php \{\s*client_max_body_size 4k;\s*limit_req zone=km_guide burst=5 nodelay;\s*include \/etc\/nginx\/km\/proxy-web\.conf;\s*proxy_set_header X-Forwarded-Port \$\{KM_APP_PORT\};\s*proxy_read_timeout 90s;/', $guideNginx) === 1);
    $compose = (string) @file_get_contents($root . '/compose.yaml');
    $envExample = (string) @file_get_contents($root . '/.env.example');
    foreach (['KM_GUIDE_LLM_URL', 'KM_GUIDE_LLM_KEY', 'KM_GUIDE_LLM_MODEL'] as $name) {
        check_bool("compose と .env.example に {$name}", str_contains($compose, "{$name}: \${{$name}:-}") && str_contains($envExample, "{$name}="));
    }
    $guideIndex = (string) file_get_contents(__DIR__ . '/../index.php');
    check_bool('画面は選んだテスト用アカウントだけ(ボタンと guide-chat.js)', str_contains($guideIndex, '$kmGuideChat = km_guide_allowed($_SESSION, time(), km_guide_settings(km_db()));')
        && preg_match("/<\?php if \(\\\$kmGuideChat\): \?>\s*<\?php \/\* 案内チャット[^*]*\*\/ \?>\s*<button id=\"km-guide-open\"/", $guideIndex) === 1
        && preg_match("/<\?php if \(\\\$kmGuideChat\): \?>\s*<\?php \/\*[^*]*\*\/ \?>\s*<script defer src=\"<\?= km_home_e\(km_public_asset\('Main\/guide-chat\.js'\)\) \?>\"><\/script>/", $guideIndex) === 1
        && str_contains($guideIndex, '|| $kmGuideChat): ?>'));
    $guideJs = (string) file_get_contents(__DIR__ . '/../Main/guide-chat.js');
    check_bool('画面の文字は textContent で入れる(HTML として読まない)', !str_contains($guideJs, 'innerHTML') && str_contains($guideJs, "'X-KM-CSRF': csrf"));
    $guideAdmin = (string) file_get_contents(__DIR__ . '/../admin/guide-chat.php');
    check_bool('管理画面: guard の内側・CSRF のあとで書く・選べるのは印の付いたテスト用アカウントだけ・記録に残す', str_contains($guideAdmin, "require __DIR__ . '/_inc/guard.php';")
        && strpos($guideAdmin, 'km_csrf_verify()') < strpos($guideAdmin, 'km_setting_set($pdo, KM_GUIDE_ENABLED_SETTING')
        && str_contains($guideAdmin, "\$known = array_column(km_test_accounts_all(\$pdo), 'sub');")
        && str_contains($guideAdmin, "km_admin_log_record('content', 'guide.settings',")
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/_inc/partials/sidebar.php'), 'href="./guide-chat.php"'));
    check_bool('既定は OFF(管理画面で ON にして人を選んでから)', str_contains($lib, "'enabled' => km_setting_get(\$pdo, KM_GUIDE_ENABLED_SETTING, '0') === '1',"));
    // 2026-10-25: 質問と答えを残す・評価・時間の枠・チャットを開いたまま・「AI は間違えることがあります」
    check_bool('口: 評価は質問より前に受ける(回数に数えない)・答えたら残す・質問の種類は画面へ返さない', strpos($api, "if (array_key_exists('rate', \$input)) {") < strpos($api, '$question = ')
        && str_contains($api, 'km_guide_log_save($pdo, km_guide_log_row(') && str_contains($api, "unset(\$result['intent'], \$result['model']);")
        && str_contains($api, "\$result['rateToken'] = \$rateToken;") && str_contains($api, '$rateToken = bin2hex(random_bytes(16));'));
    check_bool('口: 全部で 55 秒まで・言い換えは 25 秒を過ぎたら出さない', str_contains($api, '$left = 55 - $elapsed;')
        && str_contains($api, 'if ($maxTokens === KM_GUIDE_RESOLVE_TOKENS && $elapsed > ($thinking ? 15 : 25)) {'));
    check_bool('残す表に誰が聞いたかの欄は無い', preg_match('/CREATE TABLE IF NOT EXISTS km_guide_chat_log \((.*?)\) ENGINE/s', $lib, $logTable) === 1
        && preg_match('/\b(sub|user|session|ip|account)\w*\s+(VARCHAR|CHAR|INT|TEXT)/i', $logTable[1]) !== 1);
    preg_match('/const BAD_REASONS = \[(.*?)\];/s', $guideJs, $jsReasons);
    preg_match_all("/\['([a-z]+)', '([^']+)'\]/", $jsReasons[1] ?? '', $jsReasonPairs);
    check('画面の BAD の理由はサーバーと同じ', KM_GUIDE_BAD_REASONS, array_combine($jsReasonPairs[1], $jsReasonPairs[2]) ?: []);
    check_bool('画面: 「AIは間違えることがあります」と保存することをいつも出す・チャットは引き出しの土台(開いたまま)',
        str_contains($guideJs, "'AIは間違えることがあります。") && str_contains($guideJs, '質問と答えは、案内をよくするために保存します')
        && str_contains($guideJs, "window.KmSheet.setBase({ key: 'guide', title: '💬 案内', content: box });")
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/km-sheet.js'), 'closeBtn.hidden = entry === base;'));
    // 2026-10-29(振り返りのフェーズ 0): 土台は PC だけ。スマホは開いたままのシートが画面の半分と操作の帯を隠す
    check_bool('画面: チャットを土台にするのは PC だけ(スマホは「💬 案内」で開いて閉じられる)',
        str_contains($guideJs, "const wide = window.matchMedia('(min-width: 769px)');") && str_contains($guideJs, 'if (wide.matches && !pin())')
        && str_contains($guideJs, "window.KmSheet.open({ key: 'guide', title: '💬 案内', content: box });"));
    check_bool('管理画面: 質問と答えの一覧・👎 だけに絞る・まとめて消すのは「消す」と打ってから・記録に残す',
        str_contains($guideAdmin, '$logs = km_guide_log_recent($pdo, $logPerPage, ($logPage - 1) * $logPerPage, $logBadOnly);')
        && str_contains($guideAdmin, "if (trim((string) (\$_POST['confirm'] ?? '')) !== '消す') {")
        && str_contains($guideAdmin, "km_admin_log_record('content', 'guide.log_clear',")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/admin-log.php'), "'guide.log_clear' =>"));
    $llmDir = $root . '/guide-llm';
    $serverPy = (string) @file_get_contents($llmDir . '/server.py');
    /*
     * 2026-10-25 に作り直した(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」)。同じ日に Bionic に替えた。
     * 2026-10-29、利用者「Unsloth API が使えるようになったので変更」「AI を選択できるように」: 頼み先を 2 つ持つ**門番**(標準ライブラリだけ)
     */
    check_bool('自宅 PC: 門番は 127.0.0.1 だけ(番号を 2 つ目に取らせない)・鍵は定数時間で比べる・頼み先はこの PC の中だけ', str_contains($serverPy, 'ExclusiveServer(("127.0.0.1", PORT), Handler).serve_forever()')
        && str_contains($serverPy, 'allow_reuse_address = False')
        && str_contains($serverPy, 'secrets.compare_digest(') && str_contains($serverPy, 'parts.hostname in ("127.0.0.1", "localhost", "::1")')
        && str_contains($serverPy, 'if not is_local_url(backend.url):')
        && !preg_match('/^\s*(import|from)\s+(fastapi|uvicorn|unsloth|torch)\b/m', $serverPy));
    check_bool('自宅 PC: 頼み先は Unsloth と Bionic(Bionic は古い名前 LLM_API_URL でも読む)・一覧に無い模型は既定へ', str_contains($serverPy, 'BACKEND_NAMES = ("unsloth", "bionic")')
        && str_contains($serverPy, 'os.environ.get("BIONIC_API_URL", "").strip() or os.environ.get("LLM_API_URL", "").strip()')
        && str_contains($serverPy, "if isinstance(requested, str) and requested in catalog:\n        return requested"));
    check_bool('自宅 PC: Unsloth には道具を使わせない(enable_tools・mcp_enabled を false。頼み直しでも外さない)', str_contains($serverPy, 'body["enable_tools"] = False')
        && str_contains($serverPy, 'body["mcp_enabled"] = False') && str_contains($serverPy, 'OPTIONAL_KEYS = ("chat_template_kwargs", "reasoning_effort", "enable_thinking")'));
    check_bool('自宅 PC: 模型は 1 つだけ読む(ほかを外してから)・入れ替えの間は答えない・起動のときはアンロードしてからロード', str_contains($serverPy, 'unload_everything()')
        && str_contains($serverPy, '"/api/v1/models/load"') && str_contains($serverPy, '"/v1/load"')
        && str_contains($serverPy, 'switching = next((k for k, v in LOAD_STATE.items() if v == "loading"), None)')
        && str_contains($serverPy, 'load_model(key, reload=True)'));
    // 2 つ読むと PC が固まりかねない(利用者 2026-10-29): Unsloth へは横に読まない指定・外しきれていなければ読まない
    check_bool('自宅 PC: Unsloth は alongside なし・外しきれていなければ読まない・文字起こしの模型は一覧に出さない',
        str_contains($serverPy, '{"model_path": model_id, "alongside": False}') && str_contains($serverPy, 'raise BadRequest(503, "another model is still loaded")')
        && str_contains($serverPy, 'if item.get("task"):'));
    // Bionic は chat_template_kwargs を聞かない。reasoning_effort で考えを止めないと Qwen3.5 の本文が空になる(2026-10-25 のベンチで 20 件中 19 件)
    check_bool('自宅 PC: 考えは reasoning_effort で止める・空の答えは 1 度 low で頼み直し、それでも空なら 502', str_contains($serverPy, 'body["reasoning_effort"] = effort')
        && str_contains($serverPy, 'if not text and effort in ("", "none"):') && str_contains($serverPy, 'raise BadRequest(502, "upstream empty")'));
    // 2026-10-25、利用者「Website 側で、考えるの ON-OFF はできませんか」: 管理画面で選び、頼みのたびに reasoning_effort で伝える
    $llmBody = static fn (string $reasoning): array => km_guide_llm_body(['model' => 'm', 'reasoning' => $reasoning], [['role' => 'user', 'content' => 'x']], 160);
    check('考える: auto は付けない・off は none・ON は low/medium/high・知らない値は付けない', [null, 'none', 'low', 'high', null],
        [$llmBody('auto')['reasoning_effort'] ?? null, $llmBody('off')['reasoning_effort'] ?? null, $llmBody('low')['reasoning_effort'] ?? null,
         $llmBody('high')['reasoning_effort'] ?? null, $llmBody('max')['reasoning_effort'] ?? null]);
    check_bool('考える: 管理画面で選ぶ(一覧の値だけ)・ON の間は口が長く待つ', str_contains($guideAdmin, 'km_setting_set($pdo, KM_GUIDE_REASONING_SETTING, $reasoning);')
        && str_contains($guideAdmin, '} elseif (!array_key_exists($reasoning, KM_GUIDE_REASONING_CHOICES)) {')
        && str_contains($api, "\$llmConfig['reasoning'] = \$settings['reasoning'];") && str_contains($api, '$perCall = $thinking ? 40 : ($maxTokens <= 160 ? 20 : 30);'));
    check_bool('自宅 PC: Website の指定を受ける・止められない模型の OFF は low にする', str_contains($serverPy, 'REQUESTABLE_EFFORTS = ("none", "low", "medium", "high")')
        && str_contains($serverPy, 'if effort == "none" and off_ok == "no":') && str_contains($serverPy, 'effort = choose_effort(payload.get("reasoning_effort"), ')); 
    check_bool('自宅 PC: 模型の名前と出す字数は門番が決める・余計な項目は渡さない', str_contains($serverPy, '"model": model_id,')
        && str_contains($serverPy, '"max_tokens": min(max_tokens, MAX_NEW_TOKENS_CAP) + (REASONING_HEADROOM if thinking else 0),'));
    check_bool('自宅 PC: 本文をログに書かない', str_contains($serverPy, '# **本文は書かない**。時間と字数と模型だけ') && !preg_match('/LOG\.\w+\([^)]*(messages|text|body)\b/', $serverPy)
        && str_contains($serverPy, 'def log_message(self, format: str, *args: Any) -> None:'));
    // 2026-10-29: 模型を選ぶ(管理画面 → 門番の /v1/models・/v1/select)。答えた模型を記録に残す
    check_bool('模型を選ぶ: 管理画面は門番の一覧の模型だけ・変えたら読み込みを頼む・答えた模型を残す', str_contains($guideAdmin, '$modelList = km_guide_llm_models($llmConfig);')
        && str_contains($guideAdmin, "|| (\$modelList !== null && in_array(\$model, array_column(\$modelList['models'], 'id'), true));")
        && str_contains($guideAdmin, '$loadState = km_guide_llm_select($llmConfig, $model);')
        && str_contains($api, "\$llmConfig['model'] = \$settings['model'];") && str_contains($api, "\$result['model'] = \$usedModel;")
        && str_contains($lib, "ALTER TABLE km_guide_chat_log ADD COLUMN model"));
    check('模型の鍵の形', [true, true, true, false, false, false], array_map('km_guide_model_key_valid', ['', 'unsloth:unsloth/Qwen3.5-9B-GGUF', 'bionic:980b060c@q4_k_xl', 'openai:gpt', 'unsloth:a b', 'bionic:']));
    check('本文の model: 選んでいなければ default', ['default', 'unsloth:x'], [km_guide_llm_body(['model' => ''], [], 10)['model'], km_guide_llm_body(['model' => 'unsloth:x'], [], 10)['model']]);
    $startPs = (string) @file_get_contents($llmDir . '/start-guide-llm.ps1');
    // 説明の文の「WSL」ではなく、wsl を呼ぶ形(wsl -d … / Start-Process 'wsl' / WSLENV)を探す
    check_bool('自宅 PC: WSL を使わない・ngrok で出す・鍵は環境変数で渡す', !preg_match("/\bwsl(\.exe)?\s+-|FilePath\s+'wsl|WSLENV/i", $startPs)
        && str_contains($startPs, '& ngrok http --url="https://$domain" $port') && str_contains($startPs, "\$env:KM_GUIDE_LLM_KEY = \$conf['KM_GUIDE_LLM_KEY']"));
    // Lib の無い python(C:\Python315)は警告だけで終了コード 0 になるので、標準ライブラリのファイルまで見る(2026-10-29)
    check_bool('自宅 PC: python は標準ライブラリが在るものだけ使う', str_contains($startPs, "sysconfig.get_paths()['stdlib']")
        && str_contains($startPs, "('http', 'server.py')") && str_contains($startPs, '-c $pythonProbe'));
    // 鍵の入るファイルは git に入れない。見本(git に入る)には**値を入れない**(2026-10-25、利用者が入れた値を guide-llm.env へ移した)
    $exampleEnv = (string) @file_get_contents($llmDir . '/guide-llm.env.example');
    check_bool('自宅 PC: guide-llm.env は git に入れない・見本の鍵とドメインは空', str_contains((string) @file_get_contents($llmDir . '/.gitignore'), 'guide-llm.env')
        && preg_match('/^KM_GUIDE_LLM_KEY=\s*$/m', $exampleEnv) === 1 && preg_match('/^NGROK_DOMAIN=\s*$/m', $exampleEnv) === 1
        && preg_match('/^UNSLOTH_API_KEY=\s*$/m', $exampleEnv) === 1 && preg_match('/^BIONIC_API_KEY=\s*$/m', $exampleEnv) === 1
        && preg_match('/^GUIDE_LLM_MODEL=\s*$/m', $exampleEnv) === 1 && preg_match('/^GUIDE_LLM_PORT=8192$/m', $exampleEnv) === 1);
}

/**
 * 端末の名乗り(User-Agent)。**列は配備利用者が足す**ので、
 * ここで見るのは「切り方」と「畳み方」だけ(DB は要らない)。
 */
function km_check_admin_log_user_agent(): void
{
    require_once __DIR__ . '/../lib/admin-log.php';

    km_check_heading('admin-log: 端末の名乗りを切る');
    check_bool('空なら null', km_admin_log_user_agent('') === null);
    check_bool('空白だけでも null', km_admin_log_user_agent('   ') === null);
    check('短いものはそのまま', 'curl/8.0', km_admin_log_user_agent('curl/8.0'));

    // **上限の無い自由文字列をそのまま入れない。** 長い UA で INSERT ごと落ちる
    $long = str_repeat('あ', 400);
    $cut = (string) km_admin_log_user_agent($long);
    check('列の長さに収める', KM_ADMIN_LOG_USER_AGENT_MAX, mb_strlen($cut));
    // 切れた文字列を完全な値だと思って読むと、端末の判別を誤る
    check_bool('切ったことが分かる', str_ends_with($cut, '…'));
    check('列は 255', 255, KM_ADMIN_LOG_USER_AGENT_MAX);

    km_check_heading('admin-log: 端末の名乗りを畳む');
    /*
     * **順番に意味がある。** Edge は Chrome を名乗り、Chrome は Safari を名乗る。
     * 細かい方から見ないと、全部 Safari になる。
     */
    check(
        'Edge を Chrome と読まない',
        'Windows Edge',
        km_admin_log_device_label(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'
        )
    );
    check(
        'Chrome を Safari と読まない',
        'Windows Chrome',
        km_admin_log_device_label(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/120.0.0.0 Safari/537.36'
        )
    );
    check(
        'iPhone の Safari',
        'iPhone Safari',
        km_admin_log_device_label(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
            . '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
        )
    );
    check(
        'Android の Chrome',
        'Android Chrome',
        km_admin_log_device_label(
            'Mozilla/5.0 (Linux; Android 15; A401OP) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/120.0.0.0 Mobile Safari/537.36'
        )
    );

    // **当てられないものを名乗らせない。** 間違った端末名を出すより、分からないと言う
    check('見当が付かなければ先頭だけ', 'MyTool/1.0', km_admin_log_device_label('MyTool/1.0'));
    check('空なら不明', '不明な端末', km_admin_log_device_label('   '));
    $unknownLong = km_admin_log_device_label(str_repeat('x', 100));
    check_bool('長い未知のものは切る', mb_strlen($unknownLong) === 40 && str_ends_with($unknownLong, '…'));
}

/**
 * 初期データは「表を作ったときだけ」入れる(lib/tasks.php・lib/faq.php)。
 *
 * 2026-09-18、プロジェクト状況のタスクを消しても生成される(ゾンビ化)と報告された。
 * 「表が空なら入れる」にしていたので、全部消すと次の表示で初期の15件が戻っていた。
 * **DB は使わない。** 判定の書き方だけを見る。
 */
function km_check_seed_once(): void
{
    km_check_heading('初期データ: 全部消しても戻らない');
    require_once __DIR__ . '/../lib/tasks.php';
    require_once __DIR__ . '/../lib/faq.php';
    check_bool('km_db_table_exists が在る', function_exists('km_db_table_exists'));

    $body = static function (string $file, string $fn): string {
        $src = (string) file_get_contents(__DIR__ . '/../lib/' . $file);
        return preg_match('/function ' . $fn . '\(.*?\n\}/s', $src, $m) === 1 ? $m[0] : '';
    };
    foreach ([['tasks.php', 'km_tasks_ensure_table', 'km_tasks_seed_once', 'km_tasks'], ['faq.php', 'km_faq_seed_once', 'km_faq_seed_once', 'km_faq']] as [$file, $gate, $seed, $table]) {
        $gateBody = $body($file, $gate);
        $seedBody = $body($file, $seed);
        check_bool("{$table}: 関数を読めた", $gateBody !== '' && $seedBody !== '');
        check_bool("{$table}: 空かどうかで初期データを入れない", !preg_match('/COUNT\(\*\)/i', $seedBody));
        check_bool("{$table}: 表が在るかで判定する", str_contains($gateBody, "km_db_table_exists(\$pdo, '{$table}')"));
        // 判定より先に CREATE すると、在るかどうかの答えが常に「在る」になる
        $existsAt = strpos($gateBody, 'km_db_table_exists');
        $createAt = strpos($gateBody, $gate === $seed ? 'km_faq_ensure_table' : 'CREATE TABLE');
        check_bool("{$table}: 在るか見てから作る", $existsAt !== false && $createAt !== false && $existsAt < $createAt);
    }
}
/**
 * SSH の役の分け方(docs/12 §7-4)。控え専用の鍵の門番(A)と、km の降格(B)の段 1。
 *
 * **ホストには繋がない。** 見るのは「断るべきものを断る書き方になっているか」と、配備に載るか。
 * 実際に断られるかは検証機と本番で試した(2026-09-17・18。docs/12 §7-4)。
 */
function km_check_ssh_roles(): void
{
    km_check_heading('SSH の役: 控え専用の鍵の門番と km の降格');
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('SSH の役の見張り', '手元の作業ツリーで確認する。配備先の web コンテナに scripts/ は出ない');
        return;
    }
    $read = static fn (string $rel): string => (string) @file_get_contents($repoRoot . '/' . $rel);
    $gate = $read('scripts/ssh-backup-gate.sh');
    $ops = $read('scripts/host-ops-user.sh');
    $deploy = $read('scripts/deploy-to-host.ps1');
    $setup = $read('scripts/host-setup.sh');

    foreach (['ssh-backup-gate.sh' => $gate, 'host-ops-user.sh' => $ops] as $name => $body) {
        check_bool("{$name} がある", $body !== '');
        check_bool("{$name} は LF・BOM 無し(ホストの sh が読む)", $body !== '' && !str_contains($body, "\r") && !str_starts_with($body, "\xEF\xBB\xBF"));
        check_bool("{$name} は配備に載る(2 か所)", substr_count($deploy, "'./scripts/{$name}'") === 2);
        check_bool("{$name} は host-setup が実行できるようにする", preg_match("/^HOST_SCRIPTS='[^']*\\b" . preg_quote($name, '/') . "\\b/m", $setup) === 1);
    }

    // 門番: 来た文字列を sh に渡さない。形を 1 つずつ照らして、それ以外は断る
    check_bool('門番: SSH_ORIGINAL_COMMAND を eval・sh -c に渡さない', !preg_match('/eval|sh -c "\$CMD"|sh -c "\$SSH_ORIGINAL_COMMAND"/', $gate));
    check_bool('門番: 知らない形は deny で終わる', preg_match('/\*\)\s*\n\s*deny "\$CMD"/', $gate) === 1);
    check_bool('門番: 控えの名前に / と .. を通さない', str_contains($gate, '*[!A-Za-z0-9._-]*|*..*) return 1'));
    check_bool('門番: backup は決まった引数だけ(--path を選ばせない)', !str_contains($gate, '--path)'));
    check_bool('門番: report-failure は件名を選ばせない', str_contains($gate, '--label "$_label" --status ng'));
    $kmSsh = $read('scripts/km-ssh.ps1');
    check_bool('門番の鍵では疎通確認に ping を使う(echo は断られる)', str_contains($kmSsh, "if (\$script:KmSsh.Gated) { 'ping' }"));
    check_bool('週次の控えは -Gated で km_backup を使える', str_contains($read('scripts/register-backup-task.ps1'), '"$env:USERPROFILE\.ssh\km_backup"') && str_contains($read('scripts/backup-task-run.ps1'), '[switch]$Gated'));

    // km の降格(段 1): 閉め出さない・役を混ぜない
    check_bool('降格: AllowUsers が無いところへ足さない(足すと他の全員が閉め出される)', str_contains($ops, 'if [ -z "$_allow" ]; then') && str_contains($ops, 'AllowUsers が無いので足しません'));
    check_bool('降格: sshd -t が通らなければ控えに戻す', preg_match('/if ! sshd -t; then\s*\n\s*cp -p "\$SSHD_DROPIN\.bak-\$TS" "\$SSHD_DROPIN"/', $ops) === 1);
    check_bool('降格: 運用の利用者を sudo に入れない', !preg_match('/usermod[^\n]*\bsudo\b/', $ops) && str_contains($ops, 'if in_group "$OPS_USER" sudo; then'));
    check_bool('降格: km・root・ubuntu を運用の利用者にしない', str_contains($ops, '[ "$OPS_USER" = "root" ] || [ "$OPS_USER" = "km" ] || [ "$OPS_USER" = "ubuntu" ]'));
    check_bool('降格: 鍵の行に縛り(command= 等)を持ち込ませない', str_contains($ops, 'ssh-ed25519|sk-ssh-ed25519@openssh.com|ecdsa-sha2-nistp256') && str_contains($ops, '*[!A-Za-z0-9+/=]*) die'));
    check_bool('降格: 鍵は転送を切って置く', str_contains($ops, 'OPTS="no-agent-forwarding,no-X11-forwarding,no-port-forwarding"'));
    check_bool('降格: --from に引用符を通さない', str_contains($ops, '*[!0-9A-Fa-f.:/,]*) die'));
    check_bool('降格: 入力の検査は root の確認より前', ($a = strpos($ops, '*[!0-9A-Fa-f.:/,]*) die')) !== false && ($b = strpos($ops, 'create は sudo で実行してください')) !== false && $a < $b);

    // 段 2(handover / handback): 持ち主だけを変え、戻せる控えを残し、門番の行は写してから外す
    check_bool('降格 段2: 変える前の持ち主を root だけが読む所へ控える', str_contains($ops, 'LIST="/var/backups/kosenmap/owners-$CMD-$TS.tsv"') && str_contains($ops, 'install -d -m 700 /var/backups/kosenmap'));
    check_bool('降格 段2: 置き場の外へ出ない(-xdev)', substr_count($ops, 'find "$PATH_ROOT" -xdev') >= 4);
    check_bool('降格 段2: モードは変えない(.env の 600・backups の 2770 を保つ)', preg_match('/handover.*?chmod -R|chmod -R/s', $ops) === 0);
    check_bool('降格 段2: 門番の行は縛りの文字列ごと一致したものだけ移す', str_contains($ops, 'GATE_PREFIX="restrict,command=\"$PATH_ROOT/scripts/ssh-backup-gate.sh\" "'));
    check_bool('降格 段2: 写せたか数えてから元を外す(パイプの中の die に頼らない)', str_contains($ops, '_missing="$(grep -F "$GATE_PREFIX" "$SRC_AK" | while') && strpos($ops, '_missing=') < strpos($ops, 'grep -vF "$GATE_PREFIX" "$SRC_AK"'));
    check_bool('降格 段2: 戻す口(handback)がある', str_contains($ops, 'handover|handback'));
    // PC 側: docker を使う接続は kmops(置き場の持ち主)。km に戻っていると、handover 後は配備も控えも Permission denied になる
    foreach (['deploy-to-host.ps1' => "User = 'kmops'", 'host-setup.ps1' => "[string]\$User = 'kmops'", 'backup-data.ps1' => "[string]\$User = 'kmops'", 'backup-task-run.ps1' => "[string]\$User = 'kmops'", 'backup-keys.ps1' => "[string]\$User = 'kmops'", 'register-backup-task.ps1' => "[string]\$User = 'kmops'"] as $file => $needle) {
        check_bool("降格 段2: {$file} の既定は kmops", str_contains($read('scripts/' . $file), $needle));
    }
    check_bool('降格 段2: ノートの %%host は kmops と km_ops', str_contains($read('docs/km_nb.py'), "USER = os.environ.get('KM_USER', 'kmops')") && str_contains($read('docs/km_nb.py'), "'.ssh' / 'km_ops'"));
    check_bool('降格 段2: host-setup は uploads の中のファイルが読めるかも見る(控えが落ちる)', str_contains($read('scripts/host-setup.sh'), 'find "$SRC/uploads" -type f ! -readable'));

    // 段 3(demote): 引き継ぎを全部確かめてから外す。外したあとに配備も控えも動かない、を作らない
    $demote = substr($ops, (int) strpos($ops, 'if [ "$CMD" = "demote" ] || [ "$CMD" = "undemote" ]; then'));
    check_bool('降格 段3: 外す前に置き場の持ち主・門番の行・sudo を確かめる', str_contains($demote, 'stat -c \'%U\' "$PATH_ROOT")" = "$OPS_USER"') && str_contains($demote, 'grep -qF "$GATE_PREFIX" "$_admin_ak"') && str_contains($demote, 'in_group "$ADMIN_USER" sudo'));
    check_bool('降格 段3: 1 つでも欠けたら外さない', ($x = strpos($demote, 'if [ "$_ng" -ne 0 ]; then die')) !== false && ($y = strpos($demote, 'gpasswd -d "$ADMIN_USER" docker')) !== false && $x < $y);
    check_bool('降格 段3: 戻す口(undemote)がある', str_contains($ops, '|demote|undemote|') && str_contains($demote, 'usermod -aG docker "$ADMIN_USER"'));

    // 控えが同時に 2 つ走ったとき、取得中の空の保存先を消さない(2026-09-18 02:00 に失敗の知らせまで出た)
    check_bool('控え: backup-data.ps1 は PC 全体のロックを取る', str_contains($read('scripts/backup-data.ps1'), "[System.Threading.Mutex]::new(\$false, 'Global\\KosenMapBackupData')"));
    $lib = $read('scripts/backup-lib.ps1');
    check_bool('控え: 世代整理は作ったばかりの空の保存先を消さない', str_contains($lib, '$freshLimit = (Get-Date).AddHours(-2)') && strpos($lib, 'if ($dir.CreationTime -gt $freshLimit) { continue }') < strpos($lib, "空の世代を削除"));

    // 段 4(retire): 消さない・閉め出さない・戻せる
    $retire = substr($ops, (int) strpos($ops, 'if [ "$CMD" = "retire" ] || [ "$CMD" = "unretire" ]; then'));
    check_bool('降格 段4: root・km・kmops は退役させない', str_contains($retire, '[ "$RETIRE_USER" = "root" ] || [ "$RETIRE_USER" = "$ADMIN_USER" ] || [ "$RETIRE_USER" = "$OPS_USER" ]'));
    check_bool('降格 段4: いま sudo している本人・km の sudo と SSH を先に確かめ、欠けたら何も変えない', str_contains($retire, '"${SUDO_USER:-}" = "$RETIRE_USER"') && ($x = strpos($retire, 'if [ "$_ng" -ne 0 ]; then die')) !== false && ($y = strpos($retire, 'gpasswd -d "$RETIRE_USER"')) !== false && $x < $y);
    check_bool('降格 段4: 利用者も鍵も消さない(userdel・rm を使わず、鍵は退避)', !preg_match('/\buserdel\b|\brm -/', $retire) && str_contains($retire, 'authorized_keys.retired-$TS'));
    check_bool('降格 段4: 戻すための記録を先に書く', ($r = strpos($retire, '} > "$REC"')) !== false && $r < strpos($retire, 'gpasswd -d "$RETIRE_USER"'));
    check_bool('降格 段4: AllowUsers を書き換えたら km が残っているか見て、消えていたら戻す', str_contains($retire, 'が AllowUsers から消えたので元に戻しました'));
    check_bool('降格 段4: 検証機の見本づくりは本番で止まる', str_contains($read('docs/stage4-vm-mimic.sh'), "grep -q '^KM_ENV=local' /opt/kosenmap/.env"));

    // 取扱説明書が古い繋ぎ方へ戻らないように。**本番へ繋ぐ設定は kmops / km_ops**(km では書けない・docker も無い)
    $stale = [];
    foreach (glob($repoRoot . '/docs/*.ipynb') ?: [] as $book) {
        $json = json_decode((string) file_get_contents($book), true);
        foreach (($json['cells'] ?? []) as $index => $cell) {
            $body = implode('', (array) ($cell['source'] ?? []));
            $where = basename($book) . '#' . $index;
            if (($cell['cell_type'] ?? '') === 'code' && str_contains($body, "os.environ['KM_HOST'] = 'ito4.jp'")
                && !(str_contains($body, "'kmops'") && str_contains($body, "'km_ops'"))) {
                $stale[] = $where . '(KM_USER)';
            }
            if (preg_match('/-User\s+(km\b|\x27km\x27)/', $body) === 1) {
                $stale[] = $where . '(-User km)';
            }
        }
    }
    check_bool('取扱説明書: 本番へ繋ぐのは kmops(km を直に書かない)', $stale === [], implode(', ', $stale));
    $start = (string) @file_get_contents($repoRoot . '/docs/00-start.ipynb');
    check_bool('取扱説明書: 00-start が 2 つの利用者を説明している', str_contains($start, 'kmops') && str_contains($start, 'km_ops'));

    // Mailpit の画面(8025)は本番では publish しない(2026-09-18。本番では Mailpit 自体を起動していない)
    $vps = $read('compose.vps.yaml');
    $local = $read('compose.local.yaml');
    check_bool('本番は 8025(Mailpit)を publish しない', $vps !== '' && !preg_match('/^\s*-\s*"0\.0\.0\.0:8025:8025"/m', $vps));
    check_bool('ローカル環境は 8025 を publish する', preg_match('/^\s*-\s*"0\.0\.0\.0:8025:8025"/m', $local) === 1);
    check_bool('本番は Mailpit を profile で外したまま', preg_match('/^\s{2}mailpit:\s*\n\s+profiles:\s*\["mailpit"\]/m', $vps) === 1);
    // 口を閉じたら、画面の「開く」も消す(押しても繋がらないボタンを残さない)
    require_once __DIR__ . '/../lib/site.php';
    $map = km_site_service_map();
    check_bool('本番の画面は Mailpit の URL を持たない', ($map['mailpit']['url'] ?? null) === null);
    check_bool('Mailpit の行は URL があるときだけ開ける', str_contains($read('src/admin/assets/js/services.js'), "openable: Boolean(urlOf('mailpit', null))"));
    check_bool('メール一覧の Mailpit リンクはローカル環境だけ', str_contains($read('src/admin/mailbox.php'), 'if (km_site_is_local()): ?>'));}

/**
 * 教職員の権限(lib/staff-org.php。docs/15)。**DB も Logto も使わない** —— 設定の読み方と、
 * パスに埋める値の絞り方、書き換えの口が読み取りと混ざっていないことを見る。
 */
function km_check_staff_org(): void
{
    require_once __DIR__ . '/../lib/staff-org.php';

    km_check_heading('staff-org: 設定の読み方');
    $saved = [getenv('KM_LOGTO_ORG_ID'), getenv('KM_LOGTO_STAFF_ORG_ROLE'), getenv('KM_LOGTO_STAFF_SCOPE')];
    putenv('KM_LOGTO_ORG_ID');
    check_bool('組織 ID が空なら無効(付けない・判定しない)', !km_staff_org_enabled());
    putenv('KM_LOGTO_ORG_ID=0kpbyqtgrkcc');
    check('組織 ID を読む', '0kpbyqtgrkcc', km_staff_org_config()['orgId']);
    check('ロール名の既定', 'Kosen_Member', km_staff_org_config()['role']);
    check('権限名の既定(イベント運営とは別)', 'staff:normal:access', km_staff_org_config()['scope']);
    // パスに埋めるので、形が違えば空として扱う(`../` や `/` を通さない)
    putenv('KM_LOGTO_ORG_ID=../users');
    check('形の違う組織 ID は空として扱う', '', km_staff_org_config()['orgId']);
    foreach (['KM_LOGTO_ORG_ID', 'KM_LOGTO_STAFF_ORG_ROLE', 'KM_LOGTO_STAFF_SCOPE'] as $i => $name) {
        $saved[$i] === false ? putenv($name) : putenv($name . '=' . $saved[$i]);
    }

    km_check_heading('staff-org: 利用者 ID と書き換えの口');
    check_bool('利用者 ID: Logto の形は通す', km_staff_org_valid_user_id('bg6s0x1kq2ab'));
    check_bool('利用者 ID: / を含むものは断る', !km_staff_org_valid_user_id('abc/def123'));
    check_bool('利用者 ID: 短すぎるものは断る', !km_staff_org_valid_user_id('abc'));
    $threw = false;
    try {
        km_logto_management_write('PUT', 'organizations', []);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check_bool('書き換えの口は POST と DELETE だけ', $threw);
    $threw = false;
    try {
        km_logto_management_write('DELETE', 'users/../configs', null);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check_bool('書き換え先に .. を通さない', $threw);

    $lib = (string) file_get_contents(__DIR__ . '/../lib/staff-org.php');
    // 承認は Logto を先に変えてから表を書く(逆だと「承認済みなのに権限が無い」が残る)
    check_bool('承認は Logto を先に変える', strpos($lib, 'km_staff_org_grant($userId);') < strpos($lib, "UPDATE km_staff_requests SET status"));
    check_bool('二重に処理させない(前の状態を条件にする)', str_contains($lib, 'WHERE id = ? AND status = ?'));
    check_bool('未処理の申請は 1 人 1 件', str_contains($lib, "status IN ('pending', 'approved')"));
    check_bool('JIT(ドメインでの自動付与)を使わない', !str_contains($lib, 'jit/email-domains'));
    $mgmt = (string) file_get_contents(__DIR__ . '/../lib/logto-management.php');
    check_bool('読み取り(get)は既定で動詞を持たない', !preg_match('/function km_logto_management_get\([^)]*method/', $mgmt));

    km_check_heading('staff-org: 申請と承認の画面(段 B)');
    $account = (string) file_get_contents(__DIR__ . '/../account.php');
    $admin = (string) file_get_contents(__DIR__ . '/../admin/staff-requests.php');
    // 申請は CSRF の確認を通ったあとの POST の中で処理する(副作用を起こす前に弾く)
    check_bool('申請は CSRF を通った POST の中', strpos($account, "km_csrf_verify()") < strpos($account, "=== 'staff_request'"));
    check_bool('申請は組織が設定されていないと受け付けない', str_contains($account, 'if (!km_staff_org_enabled()) {'));
    check_bool('申請する本人はトークンの sub(フォームから選ばせない)', str_contains($account, "km_staff_request_create(km_db(), (string) \$KM_USER['sub']"));
    check_bool('申請を記録する', str_contains($account, "km_admin_log_record('auth', 'staff.requested'"));
    check_bool('承認の画面は guard を通る(書き込みの権限は POST ごとに見る)', str_contains($admin, "require __DIR__ . '/_inc/guard.php';"));
    check_bool('承認の画面は CSRF を確かめる', str_contains($admin, "km_csrf_verify()"));
    check_bool('承認・却下・取り消しを記録する', str_contains($admin, "'staff.approved'") && str_contains($admin, "'staff.rejected'") && str_contains($admin, "'staff.revoked'"));
    check_bool('押す前に確認を出す', str_contains($admin, 'window.confirm('));
    $staffLib = (string) file_get_contents(__DIR__ . '/../lib/staff-org.php');
    check_bool('通知の失敗で申請を失わせない', preg_match('/function km_staff_request_notify.*?catch \(Throwable/s', $staffLib) === 1);
    check_bool('サイドバーに申請の画面がある', str_contains((string) file_get_contents(__DIR__ . '/../admin/_inc/partials/sidebar.php'), './staff-requests.php'));

    km_check_heading('staff-org: 組織トークンと公開ページの判定(段 C)');
    putenv('KM_LOGTO_ORG_ID=0kpbyqtgrkcc');
    check_bool('公開ページ: こちらの組織のこちらのロールなら教職員', km_staff_org_claims_is_teacher(['0kpbyqtgrkcc:Kosen_Member']));
    check_bool('公開ページ: 別の組織の同じ名前のロールは数えない', !km_staff_org_claims_is_teacher(['zzzzzzzzzzzz:Kosen_Member']));
    check_bool('公開ページ: ロールが違えば数えない', !km_staff_org_claims_is_teacher(['0kpbyqtgrkcc:other']));
    check_bool('公開ページ: 役割の欄が無ければ教職員でない', !km_staff_org_claims_is_teacher(null));
    putenv('KM_LOGTO_ORG_ID');
    check_bool('公開ページ: 組織が未設定なら誰も教職員でない', !km_staff_org_claims_is_teacher(['0kpbyqtgrkcc:Kosen_Member']));
    $saved[0] === false ? putenv('KM_LOGTO_ORG_ID') : putenv('KM_LOGTO_ORG_ID=' . $saved[0]);
    // API: 偽のトークン内容で判定の中身を試す(署名と aud の検証は logto_principal が先に済ませる)
    putenv('KM_LOGTO_ORG_ID=0kpbyqtgrkcc');
    $r = km_staff_org_apply_token('0kpbyqtgrkcc', ['staff:normal:access', 'admin:users:write', 'staff:event:access']);
    check_bool('API: こちらの組織のトークンで教職員の権限があれば教職員', $r['ok'] && $r['isTeacher']);
    check('API: 組織トークンから採るのは教職員の権限だけ(admin にもイベント運営にも化けない)', ['staff:normal:access'], $r['permissions']);
    $r = km_staff_org_apply_token('zzzzzzzzzzzz', ['staff:normal:access']);
    check_bool('API: 別の組織のトークンは断る', !$r['ok'] && !$r['isTeacher']);
    $r = km_staff_org_apply_token('0kpbyqtgrkcc', ['read:profile']);
    check_bool('API: こちらの組織でも教職員の権限が無ければ教職員でない', $r['ok'] && !$r['isTeacher'] && $r['permissions'] === []);
    $r = km_staff_org_apply_token(null, ['admin:users:read', 'staff:normal:access', 'staff:event:access']);
    check('API: 組織を通さないトークンの教職員の権限は捨てる(他は残す)', ['admin:users:read', 'staff:event:access'], $r['permissions']);
    check_bool('API: 組織を通さないトークンは教職員でない', $r['ok'] && !$r['isTeacher']);
    putenv('KM_LOGTO_ORG_ID');
    check_bool('API: 組織が未設定なら組織トークンは断る', !km_staff_org_apply_token('0kpbyqtgrkcc', ['staff:normal:access'])['ok']);
    $saved[0] === false ? putenv('KM_LOGTO_ORG_ID') : putenv('KM_LOGTO_ORG_ID=' . $saved[0]);
    $guard = (string) file_get_contents(__DIR__ . '/../logto_guard.php');
    check_bool('API: 組織の判定は管理者の判定より前(絞ったあとの権限で is_admin を決める)', strpos($guard, 'km_staff_org_apply_token(') < strpos($guard, "'is_admin' =>"));
    check_bool('公開ページ: サインインで organization_roles を受け取る', str_contains((string) file_get_contents(__DIR__ . '/../logto-client.php'), 'UserScope::organizationRoles->value'));
}

/**
 * 教職員の地図の扱い(docs/15 段 D)。**DB も Logto も使わない** —— セッションの印と設定の組み合わせを見る。
 */
function km_check_staff_org_map(): void
{
    require_once __DIR__ . '/../lib/map-access.php';

    km_check_heading('staff-org: 教職員の地図の扱い(段 D)');
    $savedSession = $_SESSION ?? null;
    $_SESSION = [];
    check_bool('印が無ければ教職員でない', !km_map_teacher_session());
    $_SESSION['km_map_teacher_until'] = time() + 60;
    check_bool('期限内の印なら教職員', km_map_teacher_session());
    $_SESSION['km_map_teacher_until'] = time() - 1;
    check_bool('期限切れの印は効かない', !km_map_teacher_session());
    $_SESSION['km_map_teacher_until'] = (string) (time() + 60);
    check_bool('数字でない印は効かない', !km_map_teacher_session());

    $cfg = static fn (string $mode, string $mapMode, bool $seesHidden): array => ['mode' => $mode, 'mapMode' => $mapMode, 'passwordHash' => 'x', 'teacherSeesHidden' => $seesHidden];
    $_SESSION = ['km_map_teacher_until' => time() + 60];
    check_bool('教職員: password の氏名はパスワード無しで見える', km_map_names_unlocked($cfg('password', 'public', false)));
    check_bool('教職員: password の地図はパスワード無しで見える', km_map_view_unlocked($cfg('hidden', 'password', false)));
    check_bool('教職員: hidden は既定で見えない', !km_map_names_unlocked($cfg('hidden', 'public', false)));
    check_bool('教職員: hidden でも切り替えを付ければ見える', km_map_names_unlocked($cfg('hidden', 'public', true)));
    $_SESSION = [];
    check_bool('一般: 切り替えを付けても hidden は見えない', !km_map_names_unlocked($cfg('hidden', 'public', true)));
    check_bool('一般: password はパスワードを入れるまで見えない', !km_map_names_unlocked($cfg('password', 'public', false)) && !km_map_view_unlocked($cfg('hidden', 'password', false)));
    $_SESSION = ['km_map_unlocked' => km_map_password_fingerprint('x')];
    check_bool('一般: パスワードを入れても hidden は見えない', !km_map_names_unlocked($cfg('hidden', 'public', true)));
    $_SESSION = $savedSession ?? [];

    $src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);
    $index = $src('index.php');
    check_bool('公開ページ: 開くたびに印を外してから立て直す', strpos($index, "unset(\$_SESSION['km_map_teacher_until']);") < strpos($index, "\$_SESSION['km_map_teacher_until'] = min("));
    check_bool('公開ページ: 止められたアカウントには印を立てない', str_contains($index, 'if (!km_logto_user_is_suspended((string) $fresh->sub)) {'));
    check_bool('公開ページ: 印の期限は ID トークンの期限まで(最長 1 時間)', str_contains($index, 'min((int) $fresh->exp, time() + 3600)'));
    check_bool('サインアウトで印を外す', str_contains($src('sign-out.php'), "unset(\$_SESSION['km_map_teacher_until']);"));
    $appMap = $src('api/app-map.php');
    // 2026-10-22 から段で分ける(イベント運営は教員室・研究室を見ない。km_app_map_content_level)
    check_bool('アプリ: 教職員には閲覧不可の地点も配る', str_contains($appMap, '$isTeacher = is_array($principal) && ($principal[\'is_teacher\'] ?? false) === true;')
        && km_app_map_content_level(false, true, true) === 'member' && km_app_map_content_level(false, false, true) === 'event');
    check_bool('アプリ: hidden の切り替えは教職員だけ(イベント運営は対象外)', str_contains($src('lib/app-map.php'), "return \$isTeacher && \$config['teacherSeesHidden'];"));
    check_bool('設定: hidden の切り替えの既定は見せない(厳密に true のときだけ)', str_contains($src('lib/map-access.php'), "'teacherSeesHidden' => (\$config['teacherSeesHidden'] ?? false) === true,"));

    /*
     * ## 教員室・研究室と先生の名前・備考(2026-10-22、利用者の指示「権限チェックを厳格化。例外なし」「先生が申請し管理者が承認」)
     * lib/node-visibility.php・lib/staff-nodes.php の showNames。**Website だけ**(アプリへの配信は今のまま)。
     */
    km_check_heading('node-visibility: 教員室・研究室は組織メンバーだけ・氏名とメモは先生の許可だけ(2026-10-22)');
    require_once __DIR__ . '/../lib/node-visibility.php';
    check('見分け方: 種類 2・名前・見出しのどれかに「教員室」「研究室」', [true, true, true, false, false], [
        km_map_node_is_restricted(['type2' => '教員室', 'name' => '管-115']),
        km_map_node_is_restricted(['type2' => '実験室', 'name' => '物理化学研究室']),
        km_map_node_is_restricted(['title' => '第一研究室']),
        km_map_node_is_restricted(['type2' => '会議室', 'name' => '会議室']),
        km_map_node_is_restricted([]),
    ]);
    check('出し方の表(教員室か・組織メンバーか・許可・イベント中)', [
        ['show' => false, 'names' => false], ['show' => false, 'names' => false],
        ['show' => true, 'names' => false], ['show' => true, 'names' => true], ['show' => true, 'names' => false],
        ['show' => true, 'names' => false], ['show' => true, 'names' => true],
    ], [
        km_map_node_visibility(true, false, false, false), km_map_node_visibility(true, false, true, false),
        km_map_node_visibility(true, true, false, false), km_map_node_visibility(true, true, true, false), km_map_node_visibility(true, true, true, true),
        km_map_node_visibility(false, false, true, false), km_map_node_visibility(false, true, true, false),
    ]);
    // 許可の数え方: 割り当てた先生が全員許可した地点だけ。割り当ての無い許可・表が無いときは数えない
    $consentDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    check('許可: 表が無ければ誰の名前も出さない', [], km_staff_node_consented_ids($consentDb));
    $consentDb->exec('CREATE TABLE km_staff_node_assignments (node_id TEXT, user_id TEXT)');
    $consentDb->exec('CREATE TABLE km_staff_node_consents (node_id TEXT, user_id TEXT)');
    $consentDb->exec("INSERT INTO km_staff_node_assignments VALUES ('a', 'u1'), ('b', 'u1'), ('b', 'u2'), ('d', 'u3')");
    $consentDb->exec("INSERT INTO km_staff_node_consents VALUES ('a', 'u1'), ('b', 'u1'), ('c', 'u9')");
    check('許可: 全員が許可した地点だけ(b は 1 人足りない・c は割り当てが無い・d は許可が無い)', ['a' => true], km_staff_node_consented_ids($consentDb));

    $mapDataSrc = $src('lib/map-data.php');
    check_bool('地図データ: 組織メンバーかは教職員の印だけ(お試し・パスワード・管理者の印を混ぜない)', str_contains($mapDataSrc, '$member = km_map_teacher_session();')
        && !str_contains($mapDataSrc, 'km_map_teacher_like_session') && !str_contains($mapDataSrc, 'km_map_names_unlocked(') && !str_contains($mapDataSrc, 'km_map_admin_session'));
    check_bool('地図データ: 教員室・研究室は地点ごと外し、許可が無ければ担当と教員室のメモを消す(編集画面は全部)', str_contains($mapDataSrc, "if (!\$visibility['show']) {\n                continue;")
        && str_contains($mapDataSrc, "\$node['occupantName'] = null;") && str_contains($mapDataSrc, "if (\$restricted && array_key_exists('note', \$node)) {")
        && strpos($mapDataSrc, 'if (!$forAdmin) {') < strpos($mapDataSrc, "\$visibility = km_map_node_visibility("));
    check_bool('パスワードの欄: 地図の錠のときだけ(氏名はパスワードで開かない)', str_contains($src('lib/map-access.php'), "return \$config['mapMode'] === 'password';")
        && !str_contains($src('Main/panel.js'), "heading: '教職員氏名の表示'"));

    require_once __DIR__ . '/../lib/staff-nodes.php';
    $consentCurrent = ['title' => '教員室', 'subtitle' => '', 'occupantName' => '', KM_STAFF_NODE_SHOW_NAMES => '0'];
    check('提案: 表示の許可は 0 / 1 の差だけを持つ', [KM_STAFF_NODE_SHOW_NAMES => ['from' => '0', 'to' => '1']],
        km_staff_node_diff($consentCurrent, [KM_STAFF_NODE_SHOW_NAMES => '1']));
    $badConsent = 'ok';
    try {
        km_staff_node_diff($consentCurrent, [KM_STAFF_NODE_SHOW_NAMES => 'yes']);
    } catch (InvalidArgumentException) {
        $badConsent = 'error';
    }
    check('提案: 0 / 1 以外は断る', 'error', $badConsent);
    check('提案: いまの値に無ければ(管理画面の一覧など)許可の項目は見ない', [], km_staff_node_diff(['title' => '教員室'], [KM_STAFF_NODE_SHOW_NAMES => '1']));
    $staffNodesSrc = $src('lib/staff-nodes.php');
    require_once __DIR__ . '/../lib/account-delete.php';
    check_bool('許可: 取り下げは承認を待たずにすぐ(差を取る前に消す)・承認で送った先生の分だけ付ける', strpos($staffNodesSrc, 'km_staff_node_consent_withdraw($pdo, $nodeId, $userId);') < strpos($staffNodesSrc, '$changes = km_staff_node_diff(km_staff_node_current_fields($pdo, $node, $userId), $input);')
        && str_contains($staffNodesSrc, 'INSERT IGNORE INTO km_staff_node_consents (node_id, user_id, granted_at, granted_by) VALUES (?, ?, NOW(), ?)')
        && str_contains($staffNodesSrc, 'km_staff_node_apply($pdo, $nodeId, is_array($changes) ? $changes : [], $userId, $decidedBy);'));
    check_bool('許可: 割り当てを外す・権限を取り消す・退会で消える', preg_match('/function km_staff_node_unassign.*?km_staff_node_consent_withdraw\(\$pdo, \$nodeId, \$userId\);/s', $staffNodesSrc) === 1
        && str_contains($src('lib/staff-org.php'), "DELETE FROM km_staff_node_consents WHERE user_id = ?") && in_array('km_staff_node_consents', KM_ACCOUNT_DELETE_TABLES, true));
    $accountSrc = $src('account.php');
    check_bool('先生の画面: 外したときも 0 が届く(隠し欄)・今の状態を出す・取り下げを記録する', str_contains($accountSrc, '<input type="hidden" name="node_<?= km_legal_e(KM_STAFF_NODE_SHOW_NAMES) ?>" value="0">')
        && str_contains($accountSrc, "\$fields[KM_STAFF_NODE_SHOW_NAMES] = (\$_POST['node_' . KM_STAFF_NODE_SHOW_NAMES] ?? '0') === '1' ? '1' : '0';")
        && str_contains($accountSrc, "km_admin_log_record('content', 'staffnode.consent_withdrawn', \$nodeId);"));
    require_once __DIR__ . '/../lib/legal.php';
    $termsForNodes = (string) json_encode(km_legal_terms(), JSON_UNESCAPED_UNICODE);
    check_bool('利用規約: 第 8 条に教員室・研究室と教職員氏名(例外なし・申請と承認・すぐ取り下げ)', str_contains($termsForNodes, '第8条(教員室・研究室と教職員氏名の表示)')
        && str_contains($termsForNodes, '例外なく表示しません') && str_contains($termsForNodes, '運営者の承認を待たずにすぐ反映されます') && KM_LEGAL_UPDATED >= '2026-10-22');
    check_bool('プライバシーポリシー: 表示の承認を取得する情報に書いた', str_contains((string) json_encode(km_legal_privacy(), JSON_UNESCAPED_UNICODE), '担当の地点で教職員氏名とメモを表示してよいかの承認'));
}
/**
 * 教職員とアプリ(docs/15 段 E)。アプリは組織付きのトークンで logto_me.php に聞き直して教職員かを決める。
 */
function km_check_staff_org_app(): void
{
    km_check_heading('staff-org: アプリへの返し方(段 E)');
    $me = (string) file_get_contents(__DIR__ . '/../logto_me.php');
    check_bool('logto_me.php は教職員かどうかを返す', str_contains($me, "'isTeacher' => (\$principal['is_teacher'] ?? false) === true,"));
    $legal = (string) file_get_contents(__DIR__ . '/../lib/legal.php');
    check_bool('プライバシーポリシーに教職員の権限の節がある', str_contains($legal, "'heading' => '5. 教職員の権限について'"));
    check_bool('プライバシーポリシー: 申請の記録は退会で消えると書いてある', str_contains($legal, '教職員の権限の申請の記録・'));
}
/**
 * 建物の階(2026-09-24、利用者の指示)。**平面図 1 枚を 1 つの階として扱う。**
 *
 * ここで見るのは「**両方に同じものが在るか**」。Website とアプリで一覧が食い違うと、
 * 片方でだけ開けない階ができる(押しても真っ白になる)。
 */
function km_check_building_floors(): void
{
    require_once __DIR__ . '/../lib/building-floors.php';
    require_once __DIR__ . '/../lib/app-map-convert.php';

    km_check_heading('building-floors: 一覧の形');
    $ids = array_column(KM_BUILDING_FLOORS, 'id');
    check('id が重なっていない', count($ids), count(array_unique($ids)));
    // km_map_floors.id は varchar(16)。長いと DB に入らない
    $tooLong = array_values(array_filter($ids, static fn (string $id): bool => strlen($id) > 16));
    check('id は 16 文字まで(列の幅)', [], $tooLong);
    $missingImages = [];
    foreach (KM_BUILDING_FLOORS as $floor) {
        if (!is_file(__DIR__ . '/../Main/' . $floor['image'])) {
            $missingImages[] = $floor['image'];
        }
    }
    check('画像がすべて在る', [], $missingImages);
    check_bool('建物の階と分かる', km_building_floor_is('bldg_library_1f') && !km_building_floor_is('1') && !km_building_floor_is('outside'));

    km_check_heading('building-floors: アプリとの対応');
    $unmapped = [];
    foreach (KM_BUILDING_FLOORS as $floor) {
        if ((KM_APP_MAP_FLOOR_MAP[$floor['appFloor']] ?? null) !== $floor['id']) {
            $unmapped[] = $floor['appFloor'];
        }
    }
    check('アプリの階の値から Web の id へ引ける', [], $unmapped);
    /*
     * **反転の高さは階ごと。** 建物の階の画像は 600×600 で、1200 で反転すると
     * 中の地点が図の外へ飛ぶ。
     */
    check('建物の階の高さは 600', KM_BUILDING_FLOOR_SIZE, km_app_map_floor_height('bldg_library_1f'));
    check('建物の階はアプリ側の値でも引ける', KM_BUILDING_FLOOR_SIZE, km_app_map_floor_height('BLDG_LIBRARY_1F'));
    check('ふつうの階は 1200 のまま', KM_APP_MAP_IMAGE_HEIGHT, km_app_map_floor_height('1'));
    $sync = (string) file_get_contents(__DIR__ . '/../lib/app-map-sync.php');
    check_bool('取り込みで建物の階・管理画面で足した階の座標系を壊さない', str_contains($sync, 'if (km_building_floor_is($floorId) || isset($customFloors[$floorId])) {'));

    km_check_heading('building-floors: 施設との結び付き');
    check('メディアセンター(図書館)から 2 つ', ['bldg_library_1f', 'bldg_library_2f'],
        array_column(km_building_floors_for_facility('メディアセンター（図書館）'), 'id'));
    check('萩友会館(食堂)から 2 つ', ['bldg_hagitomo_1f', 'bldg_hagitomo_2f'],
        array_column(km_building_floors_for_facility('萩友会館（食堂・保健室）'), 'id'));
    check('関わりの無い建物は結び付かない', [], km_building_floors_for_facility('プール'));
    check('空の名前では何も返さない', [], km_building_floors_for_facility(''));

    km_check_heading('building-floors: 画面');
    $src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);
    check_bool('地図データに建物の階を載せる', str_contains($src('lib/map-data.php'), "'buildingFloors' =>"));
    check_bool('屋外には重ねない(データは残す)', str_contains($src('Main/app.js'), 'const KM_DRAW_BUILDING_OVERLAYS = false;'));
    check_bool('経路: 建物の階の出入口を繋ぐ', str_contains($src('Main/dijkstra.js'), 'static isBuildingFloor(floor)'));
    check_bool('中に地点が無くても開ける', str_contains($src('Main/app.js'), '**地点が無くても開く。**'));

    /*
     * アプリ側の一覧(別のリポジトリ)。**在るときだけ突き合わせる** ——
     * 本番のホストにアプリの原本は置いていない。
     */
    $catalog = 'C:/Users/itota/Documents/Test/app/src/main/java/com/ito/kosenmap/BuildingFloorCatalog.kt';
    if (is_file($catalog)) {
        km_check_heading('building-floors: アプリの一覧と突き合わせ');
        $kotlin = (string) file_get_contents($catalog);
        preg_match_all('/BuildingFloorImage\("([A-Z0-9_]+)"/', $kotlin, $matches);
        $appFloors = $matches[1];
        sort($appFloors);
        $expected = array_column(KM_BUILDING_FLOORS, 'appFloor');
        sort($expected);
        check('アプリと同じ階が並んでいる', $expected, $appFloors);
    }
}
/**
 * 階を移るときの好み・ラベルの重なり・施設から中の階へ(2026-09-22、利用者の要望)。
 *
 * **中身の検査は node 側**(`src/scripts/js/*.test.js`)。ここで見るのは
 * **画面とアプリで同じ決め方になっているか** —— 片方だけ直すと、同じ地図なのに
 * Website とアプリで違う道を案内することになる。
 */
function km_check_map_vertical(): void
{
    $src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);
    $dijkstra = $src('Main/dijkstra.js');
    $app = $src('Main/app.js');

    km_check_heading('map: エレベーター優先・階段優先');
    // 値は重みの表(KM_ROUTE_WEIGHTS)に 1 か所で持つ(2026-09-25)。倍率として掛けるだけで、道は外さない
    check_bool('好みでない方は重くするだけ(外さない)', str_contains($dijkstra, '    verticalPenalty: 4,') && str_contains($dijkstra, "? 1 : this.weights.verticalPenalty"));
    check_bool('エレベーターは名前で見分ける(種類は階段と同じ)', str_contains($dijkstra, 'static isElevator(node)'));
    check_bool('英語表記も拾う', str_contains($dijkstra, "indexOf('elevator')"));
    check_bool('知らない値は指定なしに倒す', str_contains($dijkstra, "KM_VERTICAL_MODES.indexOf(vertical) >= 0 ? vertical : 'any'"));
    check_bool('検索パネルに選択がある', str_contains($src('index.php'), 'id="vertical-pref"'));
    check_bool('選んだ値は端末に覚える(サーバーへ送らない)', str_contains($app, "const KM_VERTICAL_KEY = 'km-route-vertical-v1';"));
    check_bool('選び直したら経路を引き直す', str_contains($app, 'rebuildDijkstra();'));

    km_check_heading('map: ラベルの重なりをほどく');
    // 置き方の計算は Main/label-layout.js(2026-10-14。引き出し線)
    check_bool('ずらしてから隠す', str_contains($src('Main/label-layout.js'), 'const NUDGES = [0, -14, 14, -30, 30];'));
    check_bool('大事なものから順に置く', str_contains($app, "KM_LABEL_PRIORITY = { facility: 1, entrance: 1, stairs: 2"));
    check_bool('隠すのは文字だけ(点は残す)', str_contains($src('Main/styles.css'), '.km-label.km-label-hidden'));
    check_bool('編集画面では間引かない', str_contains($app, 'if (window.isAdmin) return;'));
    check_bool('測り直す前に前回の結果を戻す', ($reset = strpos($app, 'all.forEach(clearLabelLayout);')) !== false && $reset < (int) strpos($app, 'layoutApi.layout(items'));

    km_check_heading('map: 施設から中の階へ');
    check_bool('建物の階は接続IDから求める', str_contains($app, 'function facilityFloorLinks(facilityNode)'));
    check_bool('屋外は出さない', str_contains($app, "if (String(node.floor) === 'outside') continue;"));
    check_bool('中の地点が無ければボタンを出さない', str_contains($app, 'links.length === 0'));
    check_bool('入口まで寄せてから開く', str_contains($app, 'window.kmEnterBuilding = function (floor, nodeId)'));
    // 値は node 側の検査で確かめる。ここでは**検査そのものが在るか**を見る
    check_bool('node の検査がある', is_file(__DIR__ . '/js/building.test.js'));
}
/**
 * SSH ログインの知らせと、切る・BAN する道具(2026-09-25、利用者の指示)。
 *
 * どれも**ホストで root が走らせる**ものなので、壊れ方が「ログインが詰まる」「root への抜け道」になる。
 * 形だけでもここで押さえる。
 */
function km_check_ssh_notify(): void
{
    require_once __DIR__ . '/../lib/log-notice.php';

    km_check_heading('ssh-notify: メールの形');
    $report = km_log_notice_parse('{"label":"SSH ログイン","host":"h","status":"info","text":"x"}');
    check('info は通知として受ける', 'info', $report['status']);
    check('件名は「通知」', '[KosenMap] SSH ログイン: 通知 (h)', km_log_notice_subject($report));
    check_bool('本文も「通知」', str_contains(km_log_notice_body($report), '結果: 通知'));

    km_check_heading('ssh-notify: 信頼済みと「重要」(2026-09-25)');
    $trusted = km_log_notice_parse('{"label":"**信頼済み**SSH ログイン","host":"ubuntu","status":"info","important":false,"text":"x"}');
    check('信頼済みの件名', '[KosenMap] **信頼済み**SSH ログイン: 通知 (ubuntu)', km_log_notice_subject($trusted));
    check_bool('既定は重要を付けない', $report['important'] === false);
    check_bool('true で重要', km_log_notice_parse('{"label":"a","important":true}')['important'] === true);
    // 書き損じで全部に重要が付くと、本当に見るべき 1 通が埋もれる
    check_bool('文字列の "true" や 1 では付けない', km_log_notice_parse('{"label":"a","important":"true"}')['important'] === false
        && km_log_notice_parse('{"label":"a","important":1}')['important'] === false);

    require_once __DIR__ . '/../lib/mailer.php';
    $probe = new PHPMailer\PHPMailer\PHPMailer();
    km_mail_mark_important($probe, true);
    $headers = $probe->getCustomHeaders();
    check_bool('重要なら X-Priority 1 と Importance: High', $probe->Priority === 1 && in_array(['Importance', 'High'], $headers, true));
    km_mail_mark_important($probe, false);
    check_bool('重要でなければ印を外す', $probe->Priority === null && $probe->getCustomHeaders() === []);
    check_bool('notify-log.php は重要を渡す', str_contains((string) file_get_contents(__DIR__ . '/notify-log.php'), "km_mail_send(\$to, \$subject, \$body, \$report['important'])"));

    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('ホスト側のスクリプト', '手元の作業ツリーで確認する(配備先に server/scripts の原本一式は無い)');
        return;
    }
    $read = static fn (string $path): string => (string) @file_get_contents($repoRoot . '/' . $path);
    $notify = $read('scripts/ssh-login-notify.sh');
    $kick = $read('scripts/ssh-kick.sh');
    $setup = $read('scripts/ssh-login-notify-setup.sh');

    km_check_heading('ssh-notify: ログインを邪魔しない');
    check_bool('3 本ある', $notify !== '' && $kick !== '' && $setup !== '');
    foreach (['ssh-login-notify.sh' => $notify, 'ssh-kick.sh' => $kick, 'ssh-login-notify-setup.sh' => $setup] as $name => $body) {
        check_bool("{$name} は LF", !str_contains($body, "\r\n"));
    }
    check_bool('PAM の行は optional(落ちてもログインは通る)', str_contains($setup, 'PAM_LINE="session optional pam_exec.so quiet /usr/local/sbin/kosenmap-ssh-login-notify"'));
    check_bool('開くときだけ知らせる', str_contains($notify, '"${PAM_TYPE:-}" != "open_session"'));
    // 送信を待たせると、SMTP が詰まったときにログインが詰まる
    check_bool('送信は裏で行い、すぐ戻る', preg_match('/\) <\/dev\/null >\/dev\/null 2>&1 &\n/', $notify) === 1);
    check_bool('送信に時間の上限がある', str_contains($notify, 'timeout 60 docker compose exec -T web php scripts/notify-log.php'));
    check_bool('PAM から来た値は絞ってから使う', str_contains($notify, "tr -c 'A-Za-z0-9._:@()-' '_'"));
    check_bool('同じ相手は 10 分まとめる', preg_match('/^COOLDOWN=600$/m', $notify) === 1);
    check_bool('status は info', str_contains($notify, '\"status\":\"info\"'));
    // 信頼の一覧は root だけが書ける場所。配備の利用者が書けると、攻撃側が自分を「信頼済み」にできる
    check_bool('信頼の一覧は /etc/kosenmap', str_contains($notify, 'TRUSTED_FILE="/etc/kosenmap/ssh-trusted-ips"') && str_contains($kick, 'TRUSTED_FILE="/etc/kosenmap/ssh-trusted-ips"'));
    check_bool('信頼は完全一致だけ見る', str_contains($notify, 'grep -qFx -- "$RHOST_SAFE" "$TRUSTED_FILE"'));
    check_bool('信頼済みは件名で分かる', str_contains($notify, 'LABEL="**信頼済み**SSH ログイン"'));
    check_bool('重要は信頼していない接続元だけ', preg_match('/IMPORTANT=true\nif \[ "\$TRUSTED" -eq 1 \]; then\n  IMPORTANT=false/', $notify) === 1);
    check_bool('信頼していなければ「信頼する」コマンドを載せる', str_contains($notify, 'sudo kosenmap-ssh-kick --trust $RHOST_SAFE'));
    check_bool('信頼の一覧は root:root 644', str_contains($kick, 'chown root:root "$TRUSTED_FILE"') && str_contains($kick, 'chmod 644 "$TRUSTED_FILE"'));
    // 信頼はメールのリンクから付けない(メールの安全確認がリンクを先に開くと、攻撃側が信頼済みになる)
    check_bool('信頼はホストで sudo して付ける', !preg_match('#https?://[^\s"]*trust#', $notify));

    km_check_heading('ssh-notify: root への抜け道を作らない');
    // /opt/kosenmap/scripts は配備の利用者が書く。そこを root に直接走らせない
    check_bool('PAM は /usr/local/sbin の写しを指す', !str_contains($setup, 'pam_exec.so quiet /opt/'));
    check_bool('写しは root:root 755', str_contains($setup, 'install -o root -g root -m 755'));
    check_bool('写しと原本を突き合わせる', str_contains($setup, 'cmp -s "$_src" "$_dst"'));
    check_bool('PAM を書き換える前に控える', str_contains($setup, 'cp -p "$PAM_FILE" "$PAM_FILE.kosenmap-bak"'));
    check_bool('setup は既定で見るだけ', preg_match('/^FIX=0$/m', $setup) === 1);

    km_check_heading('ssh-notify: 切る・BAN する');
    check_bool('root でないと動かない', str_contains($kick, '"$(id -u)" -ne 0'));
    check_bool('IP と利用者名は形を確かめてから使う', str_contains($kick, 'valid_ip()') && str_contains($kick, 'valid_user()'));
    check_bool('自分自身は --force が無いと切らない', substr_count($kick, '"$FORCE" -eq 0') >= 3);
    check_bool('fail2ban が動いていればそちらで BAN', str_contains($kick, 'fail2ban-client set sshd banip'));
    check_bool('BAN は解ける', str_contains($kick, 'fail2ban-client set sshd unbanip') && str_contains($kick, '-D INPUT -s "$_ip" -p tcp --dport 22 -j DROP'));
    check_bool('鍵でのログインも止められる', str_contains($kick, 'usermod --expiredate 1'));
    check_bool('メールに切るコマンドが載る', str_contains($notify, 'sudo kosenmap-ssh-kick --ip $RHOST_SAFE --ban'));

    km_check_heading('ssh-notify: 配備で届く');
    $deploy = $read('scripts/deploy-to-host.ps1');
    foreach (['ssh-login-notify.sh', 'ssh-kick.sh', 'ssh-login-notify-setup.sh'] as $name) {
        check('配備の一覧と必須の両方に ' . $name, 2, substr_count($deploy, "'./scripts/{$name}'"));
    }
}

/**
 * 診断(2026-09-25、docs/16)の所見を直したこと。**DB も Logto も使わない**(SQLite と偽物で動かす)。
 */
function km_check_review_0925(): void
{
    $src = __DIR__ . '/..';
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    // 説明の文章に当たらないよう、コメントを外してから探す
    $code = static fn (string $text): string => (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $text);

    require_once $src . '/lib/app-ranking.php';

    km_check_heading('review-0925 W-44: ランキングは重複と実在しない地点を数えない');
    check('同じ地点 ID を 60 個並べても 1 つ', 1, count(km_ranking_clean_uuids(array_fill(0, 60, 'aaaa-1'))));
    check('同じ語は 1 回の送信で 1 回', ['図書館'], km_ranking_clean_queries(['図書館', ' 図書館 ', '図書館']));
    if (extension_loaded('pdo_sqlite')) {
        $lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $lite->exec('CREATE TABLE km_map_nodes (id TEXT, uuid TEXT)');
        $lite->exec("INSERT INTO km_map_nodes VALUES ('a1', 'real-1'), ('a2', 'real-2')");
        check('地図に在る地点だけ残す(順は送られた順)', ['real-2', 'real-1'], km_ranking_existing_uuids($lite, ['real-2', 'ghost-1', 'real-1']));
        $broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        check('地図を確かめられなければ 1 件も数えない', [], km_ranking_existing_uuids($broken, ['real-1']));
    } else {
        check_skip('ranking: 地図に在る地点だけ', 'pdo_sqlite が無い');
    }
    $ranking = $code($read('lib/app-ranking.php'));
    check_bool('記録は地図に在る地点だけを数える', str_contains($ranking, '$places = km_ranking_existing_uuids($pdo, km_ranking_clean_uuids($nodeUuids));'));
    check_bool('利用者の件数は短い間隔では足さない', str_contains($ranking, 'visits = IF(updated_at <= DATE_SUB(NOW(), INTERVAL ? SECOND), visits + VALUES(visits), visits)'));
    check_bool('語の表は年ごとの上限を超えたら新しい語を入れない', str_contains($ranking, '>= KM_RANKING_MAX_QUERY_ROWS') && str_contains($ranking, 'UPDATE km_map_ranking_queries SET searches = searches + 1 WHERE'));
    check_bool('語ごとの出どころは打ち止め', str_contains($ranking, '< ?') && str_contains($ranking, 'KM_RANKING_MAX_QUERY_SOURCES]'));

    km_check_heading('review-0925 W-45: 調べられた語を管理画面で外せる');
    check_bool('出どころは /64 単位(km_map_rate_limit_key)で数える', str_contains($ranking, 'km_map_rate_limit_key($source)'));
    check_bool('公開の一覧は外した語を出さない', str_contains($ranking, 'NOT EXISTS (SELECT 1 FROM km_map_ranking_hidden_queries h'));
    check_bool('外した語は年の掃除でも消える', str_contains($ranking, "'km_map_ranking_hidden_queries', 'km_map_ranking_published_queries', 'km_map_ranking_publications'] as \$table"));
    $page = $read('admin/ranking.php');
    check_bool('管理画面は guard.php を通る', str_contains($page, "require __DIR__ . '/_inc/guard.php';"));
    check_bool('外す操作は CSRF を確かめてから', strpos($page, 'km_csrf_verify()') !== false && strpos($page, 'km_csrf_verify()') < strpos($page, 'km_ranking_hide_query('));
    check_bool('外したことを記録する(語そのものは書かない)', str_contains($page, "km_admin_log_record('content', 'ranking.query_hidden', (string) \$year);"));
    require_once $src . '/lib/admin-log.php';
    check_bool('記録の文言がある', isset(KM_ADMIN_LOG_ACTION_LABELS['ranking.query_hidden']));
    check_bool('メニューから開ける', str_contains($read('admin/_inc/partials/sidebar.php'), 'href="./ranking.php"'));
    check_bool('語は画面でエスケープする', str_contains($page, "<?= km_e(\$row['query']) ?>"));

    km_check_heading('review-1005 W-45: 公開するのは管理者が選んだ語だけ(月ごとの写し)');
    check_bool('公開の一覧は月の写しだけを読む', str_contains($ranking, 'FROM km_map_ranking_published_queries p') && !str_contains($ranking, 'int $minSources'));
    check_bool('写しに入るのは出どころの条件を満たし、外していない語だけ', str_contains($ranking, "\$statement->execute([\$year, ...\$words, KM_RANKING_QUERY_MIN_SOURCES]);"));
    check_bool('同じ月に公開し直すと置き換える', str_contains($ranking, "DELETE FROM km_map_ranking_published_queries WHERE period = ?"));
    check_bool('外した語は公開中の写しからもすぐ消す', str_contains($ranking, "DELETE FROM km_map_ranking_published_queries WHERE normalized_query = ? AND year = ?"));
    check_bool('公開した月は語が 0 でも残す(前の月に戻らない)', str_contains($ranking, 'INSERT INTO km_map_ranking_publications') && str_contains($ranking, 'SELECT MAX(period) FROM km_map_ranking_publications WHERE year = ?'));
    check_bool('公開する操作も CSRF を確かめてから', strpos($page, 'km_csrf_verify()') < strpos($page, 'km_ranking_publish_queries('));
    check_bool('公開したことを記録する(月と数だけ)', str_contains($page, "km_admin_log_record('content', 'ranking.queries_published', \$period . ' / ' . \$count);"));
    check_bool('記録の文言がある(公開)', isset(KM_ADMIN_LOG_ACTION_LABELS['ranking.queries_published']));
    check_bool('今月まだ公開していなければ画面で知らせる', str_contains($page, 'page.ranking.notPublishedThisMonth'));
    check_bool('リセットの道具は写しも消す', substr_count($read('scripts/reset-app-ranking.php'), 'km_map_ranking_published_queries') === 3 && substr_count($read('scripts/reset-app-ranking.php'), 'km_map_ranking_publications') === 2);

    km_check_heading('review-0925 W-48: 削除した人の ID は他の記録とチャットからも消す');
    require_once $src . '/lib/account-delete.php';
    check('LIKE の % _ ! を文字どおりにする', 'a!%b!_c!!', km_account_delete_like_escape('a%b_c!'));
    $delete = $code($read('lib/account-delete.php'));
    check_bool('監査ログの detail から対象者の ID を置き換える', str_contains($delete, "UPDATE km_admin_log SET detail = REPLACE(detail, ?, ?) WHERE detail LIKE ? ESCAPE '!'"));
    check_bool('チャットの発言者を匿名にする', str_contains($delete, 'UPDATE km_chat_messages SET sender_id = ?, sender_name = ? WHERE sender_id = ?'));
    check_bool('置き換えは同じ取引の中(commit の前)', strpos($delete, 'UPDATE km_chat_messages') < strpos($delete, '$pdo->commit();'));
    check_bool('ポリシーに「された側」とチャットのことを書く', str_contains($read('lib/legal.php'), 'あなたの利用者 ID を削除します') && str_contains($read('lib/legal.php'), '発言者の名前と利用者 ID を削除します'));

    km_check_heading('review-0925 W-49: IP の保存期限');
    require_once $src . '/lib/privacy-retention.php';
    check('監査ログの IP は 90 日', 90, KM_PRIVACY_AUDIT_IP_DAYS);
    check('解除の試行は 1 日', 1, KM_PRIVACY_UNLOCK_ATTEMPT_DAYS);
    $retention = $code($read('lib/privacy-retention.php'));
    check_bool('監査ログは行を残して IP だけ空にする', str_contains($retention, "\$columns = 'ip_address = NULL';") && !str_contains($retention, 'DELETE FROM km_admin_log'));
    check_bool('解除の試行はロックが切れてから消す', str_contains($retention, 'AND (locked_until IS NULL OR locked_until < NOW())'));
    check_bool('監査の記録のついでに 1 日 1 回動く', str_contains($code($read('lib/admin-log.php')), 'km_privacy_purge_daily($pdo);'));
    check_bool('解除の API でも動く', str_contains($code($read('api/map-unlock.php')), 'km_privacy_purge_daily($pdo);'));
    $clear = $code($read('lib/map-rate-limit.php'));
    check_bool('解除に成功したら行ごと消す(成功した IP を残さない)', (bool) preg_match('/function km_map_clear_unlock_failures.*?DELETE FROM \{\$table\}/s', $clear));
    require_once $src . '/lib/legal.php';
    $policy = json_encode(km_legal_privacy(), JSON_UNESCAPED_UNICODE);
    check_bool('ポリシーに日数が出る(定数から)', is_string($policy) && str_contains($policy, '90 日を過ぎると削除します') && str_contains($policy, '1 日で削除します'));
    check_bool('ポリシーの改定日は 2026-09-25 以降', KM_LEGAL_UPDATED >= '2026-09-25');

    km_check_heading('review-0925 W-50・W-51: 期限の切れた ID トークンを取り直す');
    km_check_review_0925_fresh_claims($src);
    check_bool('公開ページは取り直した中身で教職員を判定する', str_contains($code($read('index.php')), '$fresh = $client->kmFreshIdTokenClaims();'));
    check_bool('取り直せない教職員には理由を出す', str_contains($read('index.php'), '教職員の表示を出すには、サインインし直してください'));
    $account = $code($read('account.php'));
    check('アカウント画面は 2 箇所とも取り直した中身で見る', 2, substr_count($account, '$freshClaims = $client->kmFreshIdTokenClaims();'));
    check_bool('古い ID トークンの organization_roles で教職員を決めない', !str_contains($account, 'isset($claims->organization_roles)'));
    km_check_heading('review-0925 W-52: 中身の段が変われば地図を送り直す');
    require_once $src . '/lib/app-map.php';
    // 段は 2026-10-22 に作り直した(Android 側の厳格化。lib/node-visibility.php と同じ決まり)
    check('ふつうの人', 'public', km_app_map_content_level(false, false, false));
    check('イベント運営(教員室・名前なし)', 'event', km_app_map_content_level(false, false, true, true));
    check('組織メンバー・許可なし', 'member', km_app_map_content_level(false, true, false, true, []));
    check('組織メンバー・錠が閉じている', 'member', km_app_map_content_level(false, true, false, false, ['a']));
    check_bool('組織メンバー・許可あり(許可の組の印が付く)', preg_match('/\Amember-[0-9a-f]{12}\z/', km_app_map_content_level(false, true, false, true, ['a'])) === 1);
    check_bool('許可の組が変われば段も変わる', km_app_map_content_level(false, true, false, true, ['a']) !== km_app_map_content_level(false, true, false, true, ['a', 'b']));
    check('管理者(錠が開いている)', 'editor-names', km_app_map_content_level(true, false, false, true));
    check('管理者(錠が閉じている)', 'editor', km_app_map_content_level(true, false, true, false));
    check('ふつうの人に氏名の段は付かない', 'public', km_app_map_content_level(false, false, false, true, ['a']));
    check('知らない段は unknown(必ず送り直す)', 'unknown', km_app_map_parse_have_level('everything'));
    check('送ってこない古いアプリは null', null, km_app_map_parse_have_level(null));
    check('以前の段の名前は古いアプリとして null(毎回まるごと送らない)', null, km_app_map_parse_have_level('visitor'));
    check('新しい段はそのまま', 'member-0123456789ab', km_app_map_parse_have_level('member-0123456789ab'));
    check('形の違う印は unknown', 'unknown', km_app_map_parse_have_level('member-xyz'));
    check_bool('同じ版・同じ段なら最新', km_app_map_is_up_to_date('kosen-main', 'kosen-main', 3, 3, 'member', 'member'));
    check_bool('権限を失った(member → public)なら送り直す', !km_app_map_is_up_to_date('kosen-main', 'kosen-main', 3, 3, 'member', 'public'));
    check_bool('教職員になった(public → member)なら送り直す', !km_app_map_is_up_to_date('kosen-main', 'kosen-main', 3, 3, 'public', 'member'));
    check_bool('先生が許可を取り下げた(member-… → member)なら送り直す', !km_app_map_is_up_to_date('kosen-main', 'kosen-main', 3, 3, 'member-0123456789ab', 'member'));
    check_bool('段の分からない端末(unknown)には送り直す', !km_app_map_is_up_to_date('kosen-main', 'kosen-main', 3, 3, 'unknown', 'public'));
    check_bool('段を送らない古いアプリは今までどおり', km_app_map_is_up_to_date('kosen-main', 'kosen-main', 3, 3, null, 'public'));
    $appMap = $code($read('api/app-map.php'));
    check_bool('段は「最新です」の判定より前に決める', strpos($appMap, '$contentLevel = km_app_map_content_level(') < strpos($appMap, 'km_app_map_is_up_to_date('));
    check_bool('「最新です」の応答にも段を載せる', str_contains($appMap, "'contentLevel' => \$contentLevel,"));
    $package = km_app_map_build_package((object) ['nodes' => []], 'kosen-main', 1, '2026-09-25T00:00:00+09:00', '2026-10-01T00:00:00+09:00', null, true, null, 'staff');
    $decoded = json_decode((string) $package, true);
    check('パッケージに段を載せる', 'staff', $decoded['contentLevel'] ?? null);
    check_bool('段を渡さなければ以前と同じ形', !str_contains((string) km_app_map_build_package((object) ['nodes' => []], 'kosen-main', 1, 'a', 'b', null, true), 'contentLevel'));
}

/**
 * アプリの自動更新(2026-10-05、api/app-update.php・lib/distributables.php)。DB は使わない(形と守りを見る)。
 */
function km_check_app_update(): void
{
    $src = __DIR__ . '/..';
    require_once $src . '/lib/distributables.php';
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    km_check_heading('app-update: 配布物');
    check_bool('一般用と管理用の 2 枠', isset(KM_DISTRIBUTABLES['apk'], KM_DISTRIBUTABLES['apk_admin']));
    check_bool('管理用はダウンロードに管理者が要る・一般用は要らない', KM_DISTRIBUTABLES['apk_admin']['requiresAdmin'] === true && KM_DISTRIBUTABLES['apk']['requiresAdmin'] === false);
    check_bool('APK は版番号が必須', KM_DISTRIBUTABLES['apk']['needsVersionCode'] === true && KM_DISTRIBUTABLES['apk_admin']['needsVersionCode'] === true);
    $lib = $read('lib/distributables.php');
    check_bool('SHA-256 はサーバーで取る(置く前の一時ファイルで)', strpos($lib, "hash_file('sha256', \$tmp)") !== false && strpos($lib, "hash_file('sha256', \$tmp)") < strpos($lib, 'move_uploaded_file('));
    check_bool('既にある表には列を後から足す', str_contains($lib, 'ADD COLUMN IF NOT EXISTS version_code INT NULL') && str_contains($lib, 'ADD COLUMN IF NOT EXISTS sha256 CHAR(64) NULL'));
    check_bool('版番号は Android の上限まで', KM_DIST_MAX_VERSION_CODE === 2100000000);
    km_check_heading('app-update: 口');
    $api = $read('api/app-update.php');
    check_bool('読むだけ(GET)', str_contains($api, "require_method('GET');") && !str_contains($api, 'km_dist_store('));
    check_bool('管理用は管理者のトークンを確かめてから', strpos($api, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);') < strpos($api, 'km_dist_find('));
    check_bool('版番号と SHA-256 の無い登録は「更新なし」', str_contains($api, "respond(['success' => true, 'available' => false]);"));
    $download = $read('api/download.php');
    check_bool('管理用の APK は管理者のトークンが無いと出さない', strpos($download, "(\$spec['requiresAdmin'] ?? false) === true") < strpos($download, 'readfile($path);')
        && str_contains($download, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);'));
    $page = $read('admin/downloads.php');
    check_bool('管理画面に版番号の欄と管理用の枠', str_contains($page, 'name="version_code"') && str_contains($page, "\$renderUploadForm('apk_admin'"));
    check_bool('公開ページに管理用 APK の入口を出さない', !str_contains($read('index.php'), 'slug=apk_admin'));
}

/**
 * 地図の北と距離の補正(2026-10-05、lib/map-calibration.php)。DB は使わない(検査と、口の守りを見る)。
 */
function km_check_map_calibration(): void
{
    $src = __DIR__ . '/..';
    require_once $src . '/lib/map-calibration.php';
    require_once $src . '/lib/app-map.php';
    km_check_heading('map-calibration: 検め方');
    $ok = km_map_calibration_normalize([
        'mapUpBearingDegrees' => 12.5,
        'mapUpBearingByFloor' => ['OUTSIDE' => 30],
        'defaultPixelsPerMeter' => 9.5,
        'segments' => [['fromUuid' => 'a-1', 'toUuid' => 'b-2', 'meters' => 12]],
    ]);
    check('北の向き', 12.5, $ok['mapUpBearingDegrees']);
    check('階ごとの北は数に揃える', ['OUTSIDE' => 30.0], $ok['mapUpBearingByFloor']);
    check('区間の距離は数に揃える', 12.0, $ok['segments'][0]['meters']);
    check('空でも通る(既定)', [], km_map_calibration_normalize([])['segments']);
    $rejects = static function (array $input): bool {
        try {
            km_map_calibration_normalize($input);
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    check_bool('北は 0〜360 度', $rejects(['mapUpBearingDegrees' => 360]) && $rejects(['mapUpBearingDegrees' => -1]) && $rejects(['mapUpBearingDegrees' => '90']));
    check_bool('階の名前の形を確かめる', $rejects(['mapUpBearingByFloor' => ['1F; DROP' => 0]]));
    check_bool('区間の 2 点は別・距離は 0.1〜1000 m', $rejects(['segments' => [['fromUuid' => 'a', 'toUuid' => 'a', 'meters' => 5]]])
        && $rejects(['segments' => [['fromUuid' => 'a', 'toUuid' => 'b', 'meters' => 0]]])
        && $rejects(['segments' => [['fromUuid' => 'a', 'toUuid' => 'b', 'meters' => 5000]]]));
    check_bool('区間は ' . KM_MAP_CALIBRATION_MAX_SEGMENTS . ' 本まで', $rejects(['segments' => array_fill(0, KM_MAP_CALIBRATION_MAX_SEGMENTS + 1, ['fromUuid' => 'a', 'toUuid' => 'b', 'meters' => 1])]));
    check_bool('既定の縮尺は 0.5〜200 px/m', $rejects(['defaultPixelsPerMeter' => 0.1]) && $rejects(['defaultPixelsPerMeter' => 500]));

    km_check_heading('map-calibration: 配り方');
    $package = json_decode((string) km_app_map_build_package((object) ['nodes' => []], 'kosen-main', 1, 'a', 'b', null, true, null, null, $ok), true);
    check('地図の配信に補正を載せる', 12.5, $package['calibration']['mapUpBearingDegrees'] ?? null);
    check_bool('補正はチェックサムの外(地図の文字列だけに取る)', ($package['checksum'] ?? '') === 'sha256:' . hash('sha256', json_encode((object) ['nodes' => []])));
    check_bool('補正が無ければ以前と同じ形', !array_key_exists('calibration', json_decode((string) km_app_map_build_package((object) ['nodes' => []], 'kosen-main', 1, 'a', 'b', null, true), true)));
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    $api = $read('api/map-calibration.php');
    check_bool('書き込みは管理者のトークンだけ', strpos($api, "require_method('POST');") < strpos($api, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);')
        && strpos($api, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);') < strpos($api, 'km_map_calibration_publish('));
    check_bool('本文は 64KB まで', str_contains($api, 'km_api_json_body(64 * 1024)'));
    $appMap = $read('api/app-map.php');
    check_bool('「最新です」の応答にも載せる', str_contains($appMap, "'calibration' => \$calibration,"));
    require_once $src . '/lib/admin-log.php';
    check_bool('記録の文言', isset(KM_ADMIN_LOG_ACTION_LABELS['map.calibration_publish'], KM_ADMIN_LOG_ACTION_LABELS['map.calibration_unpublish']));
    check_bool('専用の表(km_settings の 255 字に入らない)', str_contains($read('lib/map-calibration.php'), 'body MEDIUMTEXT NOT NULL'));
}

/**
 * アプリの測位のパラメータ(2026-10-06、lib/positioning-params.php)。DB は使わない(検め方・口の守り・アプリとの突き合わせ)。
 */
function km_check_positioning_params(): void
{
    $src = __DIR__ . '/..';
    require_once $src . '/lib/positioning-params.php';
    km_check_heading('positioning-params: 検め方');
    $all = km_positioning_params_normalize([]);
    check('空なら全部既定で埋める', count(KM_POSITIONING_PARAM_SPECS), count($all));
    check('既定の粒子の数', 500, $all['particle_count']);
    $ok = km_positioning_params_normalize(['knn_k' => 6, 'path_loss_exponent' => 3.1]);
    check('整数の項目は整数に', 6, $ok['knn_k']);
    check('小数の項目はそのまま', 3.1, $ok['path_loss_exponent']);
    $rejects = static function (array $input): bool {
        try {
            km_positioning_params_normalize($input);
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    check_bool('範囲の外は断る', $rejects(['knn_k' => 11]) && $rejects(['particle_count' => 50]));
    check_bool('数でないものは断る', $rejects(['knn_k' => '4']) && $rejects(['rtt_enabled' => true]) && $rejects(['ls_iterations' => INF]));
    check_bool('知らない項目は断る(版の食い違い)', $rejects(['unknown_param' => 1]));

    km_check_heading('positioning-params: 口');
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    $api = $read('api/positioning-params.php');
    $assertAt = strpos($api, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);');
    check_bool('書き込みは管理者のトークンだけ', $assertAt !== false && strpos($api, "require_method('POST');") < $assertAt
        && $assertAt < (int) strpos($api, 'km_positioning_params_publish(') && $assertAt < (int) strpos($api, 'km_positioning_params_reset('));
    check_bool('読みは権限なしで返す(秘密ではない)', strpos($api, "=== 'GET'") < $assertAt);
    check_bool('本文は 8KB まで', str_contains($api, 'km_api_json_body(8 * 1024)'));
    require_once $src . '/lib/admin-log.php';
    check_bool('記録の文言', isset(KM_ADMIN_LOG_ACTION_LABELS['positioning.params_publish'], KM_ADMIN_LOG_ACTION_LABELS['positioning.params_unpublish']));
    check_bool('専用の表(km_settings の 255 字に入らない)', str_contains($read('lib/positioning-params.php'), 'CREATE TABLE IF NOT EXISTS km_positioning_params'));

    $kotlin = 'C:/Users/itota/Documents/Test/app/src/main/java/com/ito/kosenmap/positioning/PositioningParams.kt';
    if (!is_file($kotlin)) {
        check_skip('positioning-params: アプリと突き合わせ', 'アプリの原本が無い(本番のホストなど)');
        return;
    }
    km_check_heading('positioning-params: アプリと突き合わせ');
    $kt = (string) file_get_contents($kotlin);
    preg_match_all(
        '/PositioningParamSpec\(\s*"([a-z_]+)",\s*"[^"]*",\s*"[^"]*",\s*"[^"]*",\s*(-?[0-9.]+),\s*(-?[0-9.]+),\s*(-?[0-9.]+)(,\s*integer = true)?/u',
        $kt,
        $matches,
        PREG_SET_ORDER
    );
    $app = [];
    foreach ($matches as $m) {
        $app[$m[1]] = [(float) $m[2], (float) $m[3], (float) $m[4], ($m[5] ?? '') !== ''];
    }
    check('項目の並びと数が同じ', array_keys(KM_POSITIONING_PARAM_SPECS), array_keys($app));
    foreach (KM_POSITIONING_PARAM_SPECS as $key => [$min, $max, $default, $integer]) {
        check("$key の範囲・既定・整数か", [(float) $min, (float) $max, (float) $default, $integer], $app[$key] ?? null);
    }
    $dir = dirname($kotlin, 2);
    $prefs = (string) @file_get_contents($dir . '/MapPreferences.kt');
    check_bool('手元の値を読むのは管理ビルドだけ', str_contains($prefs, 'return (if (adminBuild) read(POSITIONING_PARAMS_LOCAL_KEY) else null)'));
}

/**
 * Wi-Fi の学習データと評価の置き場(2026-10-06、lib/learning-data.php)。DB は使わない(検め方と口の守り)。
 */
function km_check_learning_data(): void
{
    $src = __DIR__ . '/..';
    require_once $src . '/lib/learning-data.php';
    km_check_heading('learning-data: 検め方');
    $sample = [
        'sampleUuid' => '0F8FAD5B-D9CB-469F-A165-70867728950E',
        'nodeUuid' => '5d42d06e-45f5-4109-b6f7-89e3f7518ceb',
        'floor' => '3F',
        'x' => 796.91,
        'y' => 816,
        'rssiByBssid' => ['78:7d:53:39:34:d7' => -50, '78:7D:53:39:34:D8' => -48],
        'altitudeMeters' => 98.5,
        'sampleCount' => 3,
        'measuredAtMillis' => 1791270100702,
    ];
    $ok = km_learning_normalize_sample($sample);
    check('uuid は小文字に揃える', '0f8fad5b-d9cb-469f-a165-70867728950e', $ok['sampleUuid']);
    check('BSSID は大文字に揃える', ['78:7D:53:39:34:D7' => -50, '78:7D:53:39:34:D8' => -48], $ok['rssiByBssid']);
    check('座標は数に', 816.0, $ok['y']);
    $rejects = static function (array $override) use ($sample): bool {
        try {
            km_learning_normalize_sample(array_merge($sample, $override));
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    check_bool('uuid の形を確かめる', $rejects(['sampleUuid' => 'x']));
    check_bool('地点・階の形を確かめる', $rejects(['nodeUuid' => "a'; DROP"]) && $rejects(['floor' => '3F/../']));
    check_bool('電波の値は BSSID と -127〜0 の整数', $rejects(['rssiByBssid' => ['nope' => -50]]) && $rejects(['rssiByBssid' => ['78:7D:53:39:34:D7' => 5]])
        && $rejects(['rssiByBssid' => ['78:7D:53:39:34:D7' => '-50']]) && $rejects(['rssiByBssid' => []]));
    check_bool('時刻と回数の範囲', $rejects(['measuredAtMillis' => 5]) && $rejects(['sampleCount' => 0]) && $rejects(['x' => INF]));
    $evaluation = km_learning_normalize_evaluation(['uuid' => '0F8FAD5B-D9CB-469F-A165-70867728950E', 'nodeUuid' => 'n-1', 'floor' => '3F', 'algorithm' => 'ハイブリッド法']);
    check('評価は中身をそのまま保存する', 'ハイブリッド法', json_decode($evaluation['body'], true)['algorithm'] ?? null);

    km_check_heading('learning-data: 口');
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    $api = $read('api/learning.php');
    // 2026-10-23 から学習の協力者(LOGTO_LEARNING_PERMISSION)も送れる。消せるのは自分が送った分だけ
    $assertAt = strpos($api, "\$principal = \$requireLearner();\n\$isAdminWriter = (\$principal['is_admin'] ?? 0) === 1;\n\$uploadedBy");
    check_bool('送る・消すは管理者か学習の協力者のトークンだけ', $assertAt !== false && $assertAt < (int) strpos($api, 'km_learning_store(') && $assertAt < (int) strpos($api, 'km_learning_delete(')
        && str_contains($api, "if (!km_learning_principal_allowed(\$principal)) {\n        respond(['success' => false,"));
    check('学習を扱えるのは管理者と学習の協力者だけ(組織の先生・運営・ログインだけは不可)', [true, true, false, false, false],
        [km_learning_principal_allowed(['is_admin' => 1]), km_learning_principal_allowed(['is_admin' => 0, 'is_learner' => true]),
         km_learning_principal_allowed(['is_admin' => 0, 'is_teacher' => true, 'permissions' => ['staff:event:access']]), km_learning_principal_allowed(['is_admin' => 0]), km_learning_principal_allowed(null)]);
    check_bool('協力者が消すのは自分が送った分だけ(空の sub でも全員の分に倒さない)', str_contains($api, "\$isAdminWriter ? null : (\$uploadedBy ?? '')")
        && str_contains($learningLibForDelete = (string) file_get_contents($src . '/lib/learning-data.php'), "if (\$onlyUploadedBy !== null) {")
        && str_contains($learningLibForDelete, "\$where .= ' AND uploaded_by = ?';"));
    check_bool('協力者の権限は組織トークンからは生まれない・logto_me が返す', str_contains($read('logto_guard.php'), "'is_learner' => in_array(LOGTO_LEARNING_PERMISSION, \$permissions, true),")
        && strpos($read('logto_guard.php'), "\$permissions = \$org['permissions'];") < strpos($read('logto_guard.php'), "'is_learner' =>")
        && str_contains($read('logto_me.php'), "'isLearner' => (\$principal['is_learner'] ?? false) === true,"));
    check_bool('学習データを読むのはアクセスコードか管理者', str_contains($api, "km_learning_code_allowed(\$pdo, (string) (\$input['code'] ?? ''))"));
    check_bool('評価を読むのは管理者だけ', strpos($api, "if (\$kind === 'evaluations') {") < strpos($api, 'km_learning_changes('));
    check_bool('コードは URL ではなく本文で受ける(POST だけ)', str_contains($api, "require_method('POST');") && !str_contains($api, '$_GET'));
    check_bool('消したことを記録する', str_contains($api, "km_admin_log_record('settings', 'learning.delete'"));
    require_once $src . '/lib/admin-log.php';
    check_bool('記録の文言', isset(KM_ADMIN_LOG_ACTION_LABELS['learning.delete']));
    $lib = $read('lib/learning-data.php');
    check_bool('同じ記録は 2 度入れない(送り直しても増えない)', str_contains($lib, 'UNIQUE KEY uq_km_learning_samples_uuid (sample_uuid)') && str_contains($lib, 'rssi_json = IF(deleted = 1, VALUES(rssi_json), rssi_json)'));
    check_bool('消した記録を送り直したら生き返らせる(deleted は最後に書く)', preg_match('/seq = IF\(deleted = 1, VALUES\(seq\), seq\),\s*deleted = 0/', $lib) === 1);
    check_bool('消しても行は残し、番号を振り直す(端末が消えたと知るため)', str_contains($lib, 'SET deleted = 1, seq = ?'));
    check_bool('1 回に返す量を絞る(アプリは 1MB まで読む)', KM_LEARNING_PAGE_BYTES <= 700 * 1024);
}

/**
 * AR 実測の記録と画像(2026-10-06、lib/ar-capture.php)。DB は使わない(検め方・COLMAP の変換・zip の作り・口の守り)。
 * 表の読み書きは本番ホストの使い捨ての MariaDB で確かめた(docs/20)。
 */
function km_check_ar_capture(): void
{
    $src = __DIR__ . '/..';
    require_once $src . '/lib/ar-capture.php';
    require_once $src . '/lib/apk-version.php';
    km_check_heading('ar-capture: 記録と画像の検め方');
    $record = ['version' => 1, 'uuid' => '0F8FAD5B-D9CB-469F-A165-70867728950E', 'floor' => '3F', 'startedAtMillis' => 1791270100702,
        'track' => [[1791270100800, 0.1, -0.2, null]], 'marks' => [['nodeUuid' => 'n1', 't' => 1791270100900, 'x' => 0.0, 'z' => 0.0]], 'depthHits' => []];
    $ok = km_ar_normalize_record(gzencode((string) json_encode($record)));
    check('記録: uuid は小文字・印の数を数える', ['0f8fad5b-d9cb-469f-a165-70867728950e', '3F', 1], [$ok['uuid'], $ok['floor'], $ok['marks']]);
    $rejectsRecord = static function (string $gz): bool {
        try {
            km_ar_normalize_record($gz);
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    check_bool('記録: gzip でない・JSON でない・形が違うものは断る', $rejectsRecord('plain') && $rejectsRecord(gzencode('nope'))
        && $rejectsRecord(gzencode((string) json_encode(['uuid' => 'x'] + $record)))
        && $rejectsRecord(gzencode((string) json_encode(['floor' => '../3F'] + $record)))
        && $rejectsRecord(gzencode((string) json_encode(['track' => 'x'] + $record))));
    check_bool('記録: ほどくと大きすぎるもの(gzip 爆弾)は断る', $rejectsRecord(gzencode(str_repeat(' ', KM_AR_RECORD_MAX_JSON_BYTES + 10))));

    $meta = ['timestampMillis' => 1791270100702, 'pose' => ['t' => [1, 2.5, -3], 'q' => [0, 0, 0, 1.2]],
        'intrinsics' => ['fx' => 500.5, 'fy' => 500, 'cx' => 320, 'cy' => 240, 'width' => 640, 'height' => 480]];
    $clean = km_ar_normalize_frame_meta($meta);
    check('付帯情報: 向きは長さ 1 に揃える・位置は数に', [[1.0, 2.5, -3.0], [0.0, 0.0, 0.0, 1.0], null], [$clean['pose']['t'], $clean['pose']['q'], $clean['map']]);
    $rejectsMeta = static function (array $override) use ($meta): bool {
        try {
            km_ar_normalize_frame_meta(array_replace_recursive($meta, $override));
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    check_bool('付帯情報: 回転でない q・欠けた t・負の fx・大きすぎる幅は断る', $rejectsMeta(['pose' => ['q' => [0, 0, 0, 0]]])
        && $rejectsMeta(['pose' => ['t' => [1, 2, 3, 4]]]) && $rejectsMeta(['intrinsics' => ['fx' => -1]])
        && $rejectsMeta(['intrinsics' => ['width' => 99999]]) && $rejectsMeta(['timestampMillis' => '1']));

    // 中身で形を見る(拡張子は信じない)。最小の JPEG(SOF0 だけ)と PNG(IHDR だけ)
    $jpeg = "\xFF\xD8\xFF\xC0" . pack('n', 17) . "\x08" . pack('nn', 480, 640) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01" . "\xFF\xD9";
    $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 160, 120) . "\x10\x00\x00\x00\x00" . "\x00\x00\x00\x00";
    check('画像は JPEG の中身から大きさを読む', ['width' => 640, 'height' => 480, 'ext' => 'jpg'], km_ar_inspect_image($jpeg, false));
    check('深度は 16bit PNG の中身から', ['width' => 160, 'height' => 120, 'ext' => 'png'], km_ar_inspect_image($png, true));
    $rejectsImage = static function (string $bytes, bool $depth): bool {
        try {
            km_ar_inspect_image($bytes, $depth);
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    check_bool('画像の欄に PNG・深度の欄に JPEG・中身の無いものは断る', $rejectsImage($png, false) && $rejectsImage($jpeg, true) && $rejectsImage('<?php ', false));

    km_check_heading('ar-capture: COLMAP の姿勢');
    $pose = km_ar_colmap_pose([1.0, 2.0, 3.0], [0.0, 0.0, 0.0, 1.0]);
    check('向きを変えていないカメラ: x 軸まわりに 180 度・t = -R·C', [[0.0, 1.0, 0.0, 0.0], [-1.0, 2.0, 3.0]],
        [array_map(static fn ($v) => round($v, 9) + 0.0, $pose['qvec']), array_map(static fn ($v) => round($v, 9) + 0.0, $pose['tvec'])]);
    // どんな向きでも: AR のカメラの前(-z)に d m の点は、COLMAP のカメラでは (0, 0, d)。上(+y)は COLMAP の -y
    $rotate = static function (array $q, array $v): array {
        [$x, $y, $z, $w] = $q;
        $m = [[1 - 2 * ($y * $y + $z * $z), 2 * ($x * $y - $z * $w), 2 * ($x * $z + $y * $w)],
            [2 * ($x * $y + $z * $w), 1 - 2 * ($x * $x + $z * $z), 2 * ($y * $z - $x * $w)],
            [2 * ($x * $z - $y * $w), 2 * ($y * $z + $x * $w), 1 - 2 * ($x * $x + $y * $y)]];
        return [$m[0][0] * $v[0] + $m[0][1] * $v[1] + $m[0][2] * $v[2], $m[1][0] * $v[0] + $m[1][1] * $v[1] + $m[1][2] * $v[2], $m[2][0] * $v[0] + $m[2][1] * $v[1] + $m[2][2] * $v[2]];
    };
    $worst = 0.0;
    foreach ([[0.1, 0.7, -0.2, 0.67], [0.5, -0.5, 0.5, 0.5], [0.0, 0.0, 1.0, 0.0], [0.3, 0.1, 0.0, -0.95]] as $q) {
        $n = sqrt(array_sum(array_map(static fn ($v) => $v * $v, $q)));
        $q = array_map(static fn ($v) => $v / $n, $q);
        $c = [0.4, 1.5, -2.0];
        $p = km_ar_colmap_pose($c, $q);
        [$qw, $qx, $qy, $qz] = $p['qvec'];
        foreach ([[[0, 0, -2.0], [0, 0, 2.0]], [[0, 1.0, 0], [0, -1.0, 0]], [[1.0, 0, 0], [1.0, 0, 0]]] as [$inAr, $inColmap]) {
            $world = array_map(static fn ($a, $b) => $a + $b, $c, $rotate($q, $inAr));
            $cam = array_map(static fn ($a, $b) => $a + $b, $rotate([$qx, $qy, $qz, $qw], $world), $p['tvec']);
            $worst = max($worst, abs($cam[0] - $inColmap[0]), abs($cam[1] - $inColmap[1]), abs($cam[2] - $inColmap[2]));
        }
    }
    check_bool('どんな向きでも、前は +z・上は -y・右は +x になる', $worst < 1e-9, sprintf('最大のずれ %.2e', $worst));
    $text = km_ar_colmap_text([['name' => 'frame_0000.jpg', 'meta' => $clean], ['name' => 'frame_0001.jpg', 'meta' => $clean]]);
    check_bool('内部の値が同じ画像は 1 つのカメラにまとめる', substr_count($text['cameras'], ' PINHOLE ') === 1
        && str_contains($text['cameras'], "1 PINHOLE 640 480 500.5 500 320 240\n"));
    check_bool('画像の行は「番号 qw qx qy qz tx ty tz カメラ 名前」と空行', preg_match('/^2 (\S+ ){7}1 frame_0001\.jpg\n\n\z/m', $text['images']) === 1);

    km_check_heading('ar-capture: zip(圧縮しない・流しながら書く)');
    $tmp = tempnam(sys_get_temp_dir(), 'kmzip');
    $file = tempnam(sys_get_temp_dir(), 'kmimg');
    file_put_contents($file, random_bytes(3000));
    $out = fopen($tmp, 'wb');
    $written = km_zip_stream_stored([
        ['name' => 'survey.json', 'data' => '{"a":1}'],
        ['name' => 'images/frame_0000.jpg', 'path' => $file],
        ['name' => 'sparse/0/points3D.txt', 'data' => ''],
    ], static function (string $bytes) use ($out): void {
        fwrite($out, $bytes);
    }, mktime(12, 34, 56, 10, 6, 2026));
    fclose($out);
    check('書いたバイト数 = ファイルの大きさ', filesize($tmp), $written);
    check_bool('中身を zip として読み返せる(中央ディレクトリから)', km_apk_read_entry($tmp, 'survey.json') === '{"a":1}'
        && km_apk_read_entry($tmp, 'images/frame_0000.jpg') === file_get_contents($file)
        && km_apk_read_entry($tmp, 'sparse/0/points3D.txt') === '');
    $zip = (string) file_get_contents($tmp);
    $local = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc', substr($zip, 0, 18));
    check('CRC は中身の CRC32・格納(圧縮なし)', [0x04034b50, 0, (int) hexdec(hash('crc32b', '{"a":1}'))], [$local['sig'], $local['method'], $local['crc']]);
    if (class_exists('ZipArchive')) {
        $archive = new ZipArchive();
        check_bool('ZipArchive でも開けて CRC が合う', $archive->open($tmp, ZipArchive::CHECKCONS) === true && $archive->numFiles === 3);
        $archive->close();
    } else {
        check_skip('ZipArchive でも開けて CRC が合う', 'zip 拡張のある PHP');
    }
    @unlink($tmp);
    @unlink($file);
    $rejectsName = static function (string $name): bool {
        try {
            km_zip_stream_stored([['name' => $name, 'data' => 'x']], static function (string $b): void {});
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    check_bool('zip の名前: 上へ戻る・絶対パス・空白は断る', $rejectsName('../x') && $rejectsName('/etc/x') && $rejectsName('a b') && $rejectsName('a/../b'));

    km_check_heading('ar-capture: 口の守り');
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    $api = $read('api/ar-capture.php');
    $assertAt = strpos($api, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);');
    check_bool('読み書きはすべて管理者のトークン(どの操作よりも先に確かめる)', $assertAt !== false
        && $assertAt < (int) strpos($api, 'km_db()') && $assertAt < (int) strpos($api, 'km_ar_list_sessions(')
        && $assertAt < (int) strpos($api, 'km_ar_session_record(') && $assertAt < (int) strpos($api, 'km_ar_put_frame('));
    $admin = $read('admin/ar-captures.php');
    check_bool('zip を落とす画面は管理者の画面(guard)・消すのは CSRF を確かめてから', str_contains($admin, "require __DIR__ . '/_inc/guard.php';")
        && strpos($admin, 'km_csrf_verify()') < strpos($admin, 'km_ar_delete_session('));
    check_bool('画像の置き場は uploads の下', str_contains(km_ar_capture_dir(), '/uploads/'));
    // nginx の設定は手元の作業ツリーにだけある(本番の web コンテナには src/ しか見えない。2026-10-07 に本番で FAIL)
    $nginxPath = $src . '/../nginx/default.conf.template';
    if (!is_file($nginxPath)) {
        check_skip('画像の置き場は外から読めない(uploads は nginx で塞いである)', '手元の作業ツリーで確認する。配備先のコンテナに nginx/ は無い');
        check_skip('nginx: 1 枚(画像 3MB + 深度 1MB)を受けられる', '手元の作業ツリーで確認する。配備先のコンテナに nginx/ は無い');
    } else {
        $nginx = (string) file_get_contents($nginxPath);
        check_bool('画像の置き場は外から読めない(uploads は nginx で塞いである)', str_contains($nginx, 'location ~ ^/(lib|config|scripts|uploads|cache|vendor)/ {'));
        check_bool('nginx: 1 枚(画像 3MB + 深度 1MB)を受けられる', preg_match('#location = /api/ar-capture\.php \{\s*client_max_body_size 5m;#', $nginx) === 1
            && KM_AR_IMAGE_MAX_BYTES + KM_AR_DEPTH_MAX_BYTES < 5 * 1024 * 1024);
    }
    check_bool('地図の配信と一般のアプリには載せない', !str_contains($read('api/app-map.php'), 'ar-capture') && !str_contains($read('lib/app-map.php'), 'ar-capture'));
    require_once $src . '/lib/admin-log.php';
    check_bool('記録の文言', isset(KM_ADMIN_LOG_ACTION_LABELS['ar.capture_upload'], KM_ADMIN_LOG_ACTION_LABELS['ar.capture_download'], KM_ADMIN_LOG_ACTION_LABELS['ar.capture_delete']));

    // アプリ(positioning/ArCapture.kt)の上限と揃っているか。片方だけ変えると、撮れても送れない
    $kotlin = 'C:/Users/itota/Documents/Test/app/src/main/java/com/ito/kosenmap/positioning/ArCapture.kt';
    if (!is_file($kotlin)) {
        check_skip('ar-capture: アプリと突き合わせ', 'アプリの原本が無い(本番のホストなど)');
        return;
    }
    km_check_heading('ar-capture: アプリと突き合わせ');
    $kt = (string) file_get_contents($kotlin);
    $constant = static function (string $name) use ($kt): ?int {
        if (preg_match('/const val ' . $name . ' = ([0-9 *]+?)L?\s*$/m', $kt, $m) !== 1) {
            return null;
        }
        return (int) array_product(array_map('intval', array_map('trim', explode('*', $m[1]))));
    };
    check('1 回の撮影の枚数', KM_AR_FRAMES_PER_SESSION, $constant('AR_CAPTURE_MAX_FRAMES'));
    check('画像 1 枚の上限', KM_AR_IMAGE_MAX_BYTES, $constant('AR_CAPTURE_IMAGE_MAX_BYTES'));
    check('深度 1 枚の上限', KM_AR_DEPTH_MAX_BYTES, $constant('AR_CAPTURE_DEPTH_MAX_BYTES'));
    check_bool('付帯情報の形(timestampMillis・pose の t と q・intrinsics)', str_contains($kt, 'addProperty("timestampMillis"') && str_contains($kt, 'add("t",')
        && str_contains($kt, 'add("q",') && str_contains($kt, 'addProperty("fx"') && str_contains($kt, 'addProperty("width"'));
}

/**
 * 1 台のルーターの複数 BSSID(2026-10-02)。アプリはカンマ区切りで送る。列が狭いと取り込みごと止まる。
 */
function km_check_router_bssids(): void
{
    require_once __DIR__ . '/../lib/app-map-sync.php';
    km_check_heading('router-bssids: 列の幅と切り方');
    $two = 'AA:BB:CC:DD:EE:01, AA:BB:CC:DD:EE:02';
    check('幅に入るならそのまま', $two, km_app_map_fit_bssids($two, 255));
    check('32 字なら丸ごと入る 1 件だけ残す(途中で切らない)', 'AA:BB:CC:DD:EE:01', km_app_map_fit_bssids($two, 32));
    check('幅が分からなければ切らない', $two, km_app_map_fit_bssids($two, null));
    check('空は null', null, km_app_map_fit_bssids('  ', 255));
    check('1 件も入らなければ null', null, km_app_map_fit_bssids('AA:BB:CC:DD:EE:01', 10));
    $sync = (string) file_get_contents(__DIR__ . '/../lib/app-map-sync.php');
    $applyAt = strpos($sync, 'function km_app_map_sync_apply(');
    check_bool('列を広げるのは取引の前(ALTER は取引を確定させる)', $applyAt !== false
        && strpos($sync, 'km_map_nodes_ensure_bssid_width($pdo);', $applyAt) < strpos($sync, '$pdo->beginTransaction();', $applyAt));
    check_bool('入れる前に幅に合わせる', str_contains($sync, "\$value = km_app_map_fit_bssids(\$value, \$bssidWidth);"));
    check_bool('広げる幅は 255', KM_MAP_NODES_BSSID_WIDTH === 255 && str_contains($sync, "'ALTER TABLE km_map_nodes MODIFY COLUMN bssid varchar(' . KM_MAP_NODES_BSSID_WIDTH . ') DEFAULT NULL'"));
    check_bool('移行の SQL も 255 にする', str_contains((string) file_get_contents(__DIR__ . '/migrate-map-app-schema.sql'), 'MODIFY COLUMN bssid varchar(255) DEFAULT NULL;'));
}

/**
 * お試しの閲覧リンク(2026-09-30、lib/map-guest.php)。DB は使わない(判定と、入口の順番を見る)。
 */
function km_check_guest_links(): void
{
    $src = __DIR__ . '/..';
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    $code = static fn (string $text): string => (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $text);
    require_once $src . '/lib/map-guest.php';
    require_once $src . '/lib/map-access.php';

    km_check_heading('guest-links: 形と状態');
    check_bool('発行する形(48 桁の 16 進数)だけ受ける', km_map_guest_token_valid(str_repeat('a1', 24)) && !km_map_guest_token_valid('abc') && !km_map_guest_token_valid(str_repeat('Z', 48)));
    check_bool('表に置くのは要約(トークンそのものではない)', km_map_guest_token_hash(str_repeat('a', 48)) !== str_repeat('a', 48) && strlen(km_map_guest_token_hash('x')) === 64);
    $now = 1_800_000_000;
    check('使える', 'active', km_map_guest_status(false, $now + 60, 0, 1, $now));
    check('台数を使い切った', 'used_up', km_map_guest_status(false, $now + 60, 1, 1, $now));
    check('期限切れ', 'expired', km_map_guest_status(false, $now, 0, 1, $now));
    check('取り消しがいちばん強い', 'revoked', km_map_guest_status(true, $now - 60, 5, 1, $now));
    check('呼び名の制御文字は落とす', 'ab', km_map_guest_clean_label("a\x07b"));
    check_bool('期限の上限は 14 日', max(KM_MAP_GUEST_DAY_CHOICES) <= KM_MAP_GUEST_MAX_DAYS && KM_MAP_GUEST_MAX_DAYS === 14);

    km_check_heading('guest-links: 見える範囲(地図は誰でも・教員名は許した人だけ)');
    $saved = $_SESSION ?? null;
    $cfg = static fn (string $mode, string $mapMode, bool $seesHidden): array => ['mode' => $mode, 'mapMode' => $mapMode, 'passwordHash' => 'x', 'teacherSeesHidden' => $seesHidden];
    $_SESSION = [KM_MAP_GUEST_SESSION_KEY => ['id' => 1, 'until' => time() + 600]];
    check_bool('仮アカウントの無い印(前の形)は効かない', !km_map_guest_session());
    // 教員名を許していない仮アカウント(作っただけ。利用者の指示で既定は許さない)
    $_SESSION = [KM_MAP_GUEST_SESSION_KEY => ['id' => 1, 'account' => 7, 'until' => time() + 600, 'name' => '試し 花子', 'names' => false]];
    check_bool('期限内の印ならお試しの閲覧中', km_map_guest_session());
    check('画面に出す名前', '試し 花子', km_map_guest_name());
    check_bool('許していなければ: password の地図はパスワード無しで見える', km_map_view_unlocked($cfg('hidden', 'password', false)));
    check_bool('許していなければ: password の氏名は見えない', !km_map_names_unlocked($cfg('password', 'public', false)) && !km_map_guest_names_session());
    check_bool('許していなければ: hidden で「教職員には見せる」でも見えない', !km_map_names_unlocked($cfg('hidden', 'public', true)));
    $_SESSION[KM_MAP_GUEST_SESSION_KEY]['names'] = 1;
    check_bool('names は true そのものだけ(1 や "1" では許さない)', !km_map_guest_names_session());
    $_SESSION = [KM_MAP_GUEST_SESSION_KEY => ['id' => 1, 'account' => 7, 'until' => time() + 600, 'name' => '試し 花子']];
    check_bool('names の無い印(前の形)は教員名を許さない', !km_map_guest_names_session() && km_map_guest_session());
    // 管理画面で教員名を許した仮アカウント
    $_SESSION = [KM_MAP_GUEST_SESSION_KEY => ['id' => 1, 'account' => 7, 'until' => time() + 600, 'name' => '試し 花子', 'names' => true]];
    check_bool('許した人: password の氏名がパスワード無しで見える', km_map_names_unlocked($cfg('password', 'public', false)));
    check_bool('許した人: password の地図がパスワード無しで見える', km_map_view_unlocked($cfg('hidden', 'password', false)));
    check_bool('許した人: hidden は既定で見えない(教職員と同じ)', !km_map_names_unlocked($cfg('hidden', 'public', false)));
    check_bool('許した人: hidden でも「教職員には見せる」なら見える', km_map_names_unlocked($cfg('hidden', 'public', true)));
    $_SESSION = [KM_MAP_GUEST_SESSION_KEY => ['id' => 1, 'account' => 7, 'until' => time() - 1, 'names' => true]];
    check_bool('期限切れの印は効かない', !km_map_guest_session() && !km_map_names_unlocked($cfg('password', 'public', false)));
    $_SESSION = [KM_MAP_GUEST_SESSION_KEY => ['id' => '1', 'account' => '7', 'until' => (string) (time() + 600)]];
    check_bool('数字でない印は効かない', !km_map_guest_session());
    $_SESSION = [KM_MAP_GUEST_SESSION_KEY => ['id' => 1, 'account' => 7, 'until' => time() + 600]];
    check_bool('管理者の印にはならない', !km_map_admin_session());
    $_SESSION = $saved ?? [];

    km_check_heading('guest-links: 入口');
    $guest = $code($read('guest.php'));
    check_bool('GET では使わない(確かめるだけ)', str_contains($guest, 'km_map_guest_peek($pdo, $token)') && strpos($guest, '$action === \'create\'') < strpos($guest, 'km_map_guest_create_account('));
    check_bool('作るのも入り直すのも POST で CSRF を確かめてから', strpos($guest, 'km_csrf_verify()') < strpos($guest, 'km_map_guest_create_account(') && strpos($guest, 'km_csrf_verify()') < strpos($guest, 'km_map_guest_reenter('));
    check_bool('再入場コードの総当たりを絞る(錠 guest)', strpos($guest, 'km_map_unlock_attempt($pdo, \'guest\')') < strpos($guest, 'km_map_guest_reenter('));
    check_bool('再入場コードは作った画面でだけ見せる(URL に載せない)', str_contains($guest, 'km_map_guest_format_code($created[\'code\'])') && !str_contains($guest, 'Location: /?'));
    check_bool('印を立てる前にセッション ID を作り直す', strpos($guest, 'session_regenerate_id(true);') < strpos($guest, 'km_map_guest_grant('));
    check_bool('記録に名前を写さない(番号だけ)', !preg_match('/km_admin_log_record\([^;]*name/', $guest));
    check_bool('参照元を渡さない', str_contains($guest, "header('Referrer-Policy: no-referrer');"));
    $lib = $code($read('lib/map-guest.php'));
    check_bool('台数は 1 本の UPDATE で数える', str_contains($lib, 'SET uses = uses + 1') && str_contains($lib, 'AND uses < max_uses'));
    check_bool('取り消し・期限切れは表と突き合わせて外す', str_contains($lib, 'unset($_SESSION[KM_MAP_GUEST_SESSION_KEY]);'));
    $index = $code($read('index.php'));
    check_bool('公開ページは地図を同梱する前に突き合わせる', strpos($index, 'km_map_guest_verify()') !== false && strpos($index, 'km_map_guest_verify()') < strpos($index, 'km_map_data(km_db())'));
    $mapData = $code($read('api/map-data.php'));
    check_bool('地図データの API はセッションを閉じる前に突き合わせる', strpos($mapData, 'km_map_guest_verify();') < strpos($mapData, 'session_write_close();'));
    check_bool('サインアウトで外す', str_contains($code($read('sign-out.php')), "\$_SESSION['km_map_guest']"));
    $admin = $code($read('admin/guest-links.php'));
    check_bool('管理画面は guard.php を通る', str_contains($admin, "require __DIR__ . '/_inc/guard.php';"));
    check_bool('発行・取り消しは CSRF を確かめてから', strpos($admin, 'km_csrf_verify()') < strpos($admin, 'km_map_guest_create('));
    check_bool('発行したリンクを URL に載せて戻さない', str_contains($admin, "header('Location: ./guest-links.php?created=1', true, 302);") && str_contains($admin, "\$_SESSION['km_guest_link_new']"));
    check_bool('記録に呼び名を写さない(番号だけ)', str_contains($admin, "km_admin_log_record('content', 'guest.link_create', '#' . \$created['id']);"));
    require_once $src . '/lib/admin-log.php';
    foreach (['guest.link_create', 'guest.link_revoke', 'guest.account_create', 'guest.account_reenter', 'guest.account_revoke', 'guest.account_names_allow', 'guest.account_names_deny'] as $action) {
        check_bool('記録の文言: ' . $action, isset(KM_ADMIN_LOG_ACTION_LABELS[$action]));
    }
    check_bool('メニューから開ける', str_contains($read('admin/_inc/partials/sidebar.php'), 'href="./guest-links.php"'));
    check_bool('期限の切れたリンクは 30 日で消す', str_contains($code($read('lib/privacy-retention.php')), 'DELETE FROM km_map_guest_links WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)'));
    check_bool('退会したら発行者を外す', str_contains($code($read('lib/account-delete.php')), 'UPDATE km_map_guest_links SET created_by = NULL WHERE created_by = ?'));
    km_check_heading('guest-links: 仮アカウント(MariaDB)');
    check('コードは見間違えやすい字を使わない', 0, preg_match('/[01OIL]/', KM_MAP_GUEST_CODE_ALPHABET));
    $newCode = km_map_guest_new_code();
    check_bool('作ったコードは揃えた形と同じ', km_map_guest_normalize_code($newCode) === $newCode && strlen($newCode) === KM_MAP_GUEST_CODE_LENGTH);
    check('小文字・ハイフン・空白を許して揃える', 'K7QMAB23', km_map_guest_normalize_code(' k7qm-ab23 '));
    check('使わない字(0 や O)を含むコードは受けない', null, km_map_guest_normalize_code('K0QM-AB23'));
    check('4 文字ずつ区切って見せる', 'K7QM-AB23', km_map_guest_format_code('K7QMAB23'));
    check_bool('コードの要約はリンクごとに違う', km_map_guest_code_hash(1, 'K7QMAB23') !== km_map_guest_code_hash(2, 'K7QMAB23'));
    check('名前の制御文字を落とし長さで切る', str_repeat('あ', KM_MAP_GUEST_NAME_MAX), km_map_guest_clean_text("\x07" . str_repeat('あ', 40), KM_MAP_GUEST_NAME_MAX));
    check_bool('仮アカウントは Logto ではなく DB の表に作る', str_contains($lib, 'INSERT INTO km_map_guest_accounts') && !str_contains($lib, 'logto'));
    check_bool('1 人分を使うのと作るのは 1 つの取引', (bool) preg_match('/function km_map_guest_create_account.*?beginTransaction.*?SET uses = uses \+ 1.*?INSERT INTO km_map_guest_accounts.*?commit/s', $lib));
    check_bool('入り直しは台数を使わない', !preg_match('/function km_map_guest_reenter.*?uses = uses \+ 1.*?function km_map_guest_grant/s', $lib));
    check_bool('止めたアカウントは突き合わせで外れる', str_contains($lib, 'a.revoked_at IS NULL') && str_contains($lib, 'l.revoked_at IS NULL AND l.expires_at > NOW()'));
    check_bool('期限の切れたリンクの仮アカウントも消す(リンクより先に)', strpos($code($read('lib/privacy-retention.php')), 'DELETE FROM km_map_guest_accounts') < strpos($code($read('lib/privacy-retention.php')), 'DELETE FROM km_map_guest_links'));
    check_bool('ポリシーに仮アカウントの名前と保存期間を書く', str_contains($read('lib/legal.php'), '仮アカウント') && str_contains($read('lib/legal.php'), '入力した名前・所属'));
    check_bool('再入場の錠がある', isset(KM_MAP_UNLOCK_SCOPES['guest']));
    check_bool('管理画面で人ごとに止められる', str_contains($admin, 'km_map_guest_revoke_account(') && str_contains($admin, "km_admin_log_record('content', 'guest.account_revoke'"));
    km_check_heading('guest-links: 教員名は人ごとに許す');
    check_bool('表に names_allowed を持ち、既定は許さない', str_contains($lib, 'names_allowed TINYINT(1) NOT NULL DEFAULT 0,'));
    check_bool('既にある表には列を後から足す(無いときだけ)', str_contains($lib, "COLUMN_NAME = 'names_allowed'") && str_contains($lib, 'ADD COLUMN IF NOT EXISTS names_allowed'));
    check_bool('作っただけでは許さない', (bool) preg_match("/function km_map_guest_create_account.*?'names' => false,/s", $lib));
    check_bool('突き合わせのたびに表から読み直す(許す・外すがすぐ効く)', (bool) preg_match("/function km_map_guest_verify.*?SELECT a\\.names_allowed.*?\\['names'\\] = \\(int\\) \\\$namesAllowed === 1;/s", $lib));
    check_bool('入り直しも表の値で印を立てる', (bool) preg_match('/function km_map_guest_reenter.*?SELECT id, display_name, names_allowed/s', $lib) && substr_count($guest, "\$account['names']);") === 2);
    check_bool('止めたアカウントは変えない', str_contains($lib, 'SET names_allowed = ? WHERE id = ? AND revoked_at IS NULL'));
    check_bool('氏名の判定は許した人だけ・地図の錠は仮アカウントなら通す', str_contains($code($read('lib/map-access.php')), "'password' => km_map_password_entered(\$config) || km_map_teacher_like_session(true),") && str_contains($code($read('lib/map-access.php')), "'password' => km_map_password_entered(\$config) || km_map_teacher_like_session(false) || km_map_event_opens_view(\$config),"));
    check_bool('管理画面: CSRF を確かめてから・記録は番号だけ', strpos($admin, 'km_csrf_verify()') < strpos($admin, 'km_map_guest_set_names(') && str_contains($admin, "\$allow ? 'guest.account_names_allow' : 'guest.account_names_deny', '#' . \$accountId);"));
    check_bool('管理画面: 見せる前に確かめる', str_contains($read('admin/guest-links.php'), "'page.guestLinks.confirmNames'") && str_contains($read('admin/assets/i18n/ja.js'), '"page.guestLinks.confirmNames"') && str_contains($read('admin/assets/i18n/en.js'), '"page.guestLinks.confirmNames"'));
    check_bool('公開ページに誰として見ているかを出す', str_contains($read('index.php'), 'お試し: <?= km_home_e((string) $guestName) ?> さん'));
    // nginx/ は src/ の外。配備先の web コンテナには無いので飛ばす(2026-09-30。09-17 と同じ付け忘れ)
    $guestRepoRoot = km_check_repo_root();
    $nginx = $guestRepoRoot === null ? '' : (string) @file_get_contents($guestRepoRoot . '/nginx/default.conf.template');
    if ($guestRepoRoot === null) {
        check_skip('nginx に /guest.php の location がある', '手元の作業ツリーで確認する。配備先に nginx/ は出ない');
    } elseif (preg_match('#location = /guest\.php \{(.*?)\n    \}#s', $nginx, $m)) {
        check_bool('nginx: クエリをログに残さない', str_contains($m[1], 'km_no_query'));
        check_bool('nginx: 参照元を渡さない', str_contains($m[1], 'Referrer-Policy no-referrer always'));
        check_bool('nginx: 入口で回数を絞る', str_contains($m[1], 'limit_req zone=km_public'));
    } else {
        check_bool('nginx に /guest.php の location がある', false);
    }
}

/** kmFreshIdTokenClaims を、Logto に繋がずに動かす(偽の OidcCore と保存場所)。 */
function km_check_review_0925_fresh_claims(string $src): void
{
    if (!is_file($src . '/vendor/autoload.php')) {
        check_skip('W-50: ID トークンの取り直し', 'vendor が無い');
        return;
    }
    require_once $src . '/vendor/autoload.php';
    require_once $src . '/lib/logto-sdk-client.php';

    $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $jwt = static fn (array $claims): string => $b64('{"alg":"none"}') . '.' . $b64((string) json_encode($claims)) . '.sig';
    $claims = static fn (int $exp, array $roles): array => [
        'iss' => 'https://logto.test/oidc', 'sub' => 'teacher-1', 'aud' => 'app', 'exp' => $exp, 'iat' => $exp - 3600,
        'organization_roles' => $roles,
    ];

    $make = static function (?string $idToken, ?string $refreshToken, string $mode) {
        $storage = new class implements \Logto\Sdk\Storage\Storage {
            public array $data = [];
            public function get(\Logto\Sdk\Storage\StorageKey $key): ?string { return $this->data[$key->value] ?? null; }
            public function set(\Logto\Sdk\Storage\StorageKey $key, ?string $value): void { $this->data[$key->value] = $value; }
            public function delete(\Logto\Sdk\Storage\StorageKey $key): void { unset($this->data[$key->value]); }
        };
        $storage->set(\Logto\Sdk\Storage\StorageKey::idToken, $idToken);
        $storage->set(\Logto\Sdk\Storage\StorageKey::refreshToken, $refreshToken);
        $oidc = new class extends \Logto\Sdk\Oidc\OidcCore {
            public string $mode = 'ok';
            public string $nextIdToken = '';
            public int $calls = 0;
            public function __construct() {}
            public function verifyIdToken(string $idToken, string $clientId) {}
            public function fetchTokenByRefreshToken(string $clientId, ?string $clientSecret, string $refreshToken, string $resource = ''): \Logto\Sdk\Oidc\TokenResponse
            {
                $this->calls++;
                if ($this->mode === 'fail') {
                    throw new RuntimeException('invalid_grant');
                }
                return new \Logto\Sdk\Oidc\TokenResponse(
                    access_token: 'at', token_type: 'Bearer', expires_in: 3600, refresh_token: 'rt-2', id_token: $this->nextIdToken
                );
            }
        };
        $oidc->mode = $mode;
        $client = (new ReflectionClass(KmLogtoClient::class))->newInstanceWithoutConstructor();
        $client->config = new \Logto\Sdk\LogtoConfig(endpoint: 'https://logto.test', appId: 'app', appSecret: 'fake');
        $client->storage = $storage;
        (new ReflectionProperty(\Logto\Sdk\LogtoClient::class, 'oidcCore'))->setValue($client, $oidc);

        return [$client, $oidc, $storage];
    };

    $teacherRole = ['org-1:teacher'];
    [$client, $oidc] = $make($jwt($claims(time() + 3600, $teacherRole)), 'rt-1', 'ok');
    $fresh = $client->kmFreshIdTokenClaims();
    check_bool('期限内ならそのまま使い、取り直さない', $fresh !== null && $oidc->calls === 0);

    [$client, $oidc, $storage] = $make($jwt($claims(time() - 60, $teacherRole)), 'rt-1', 'ok');
    $oidc->nextIdToken = $jwt($claims(time() + 3600, []));
    $fresh = $client->kmFreshIdTokenClaims();
    check_bool('期限切れなら取り直し、新しい中身を返す(組織から外れていれば外れる)', $fresh !== null && $oidc->calls === 1 && ($fresh->organization_roles ?? null) === []);
    check('取り直した refresh_token を保存する', 'rt-2', $storage->get(\Logto\Sdk\Storage\StorageKey::refreshToken));

    [$client, $oidc] = $make($jwt($claims(time() - 60, $teacherRole)), 'rt-1', 'fail');
    check_bool('取り直せなければ null(教職員の特典を出さない)', $client->kmFreshIdTokenClaims() === null);

    [$client, $oidc] = $make($jwt($claims(time() - 60, $teacherRole)), null, 'ok');
    check_bool('refresh_token が無ければ null', $client->kmFreshIdTokenClaims() === null && $oidc->calls === 0);

    [$client] = $make(null, null, 'ok');
    check_bool('サインインしていなければ null', $client->kmFreshIdTokenClaims() === null);
}

/**
 * 経路の重み(2026-09-25、利用者の指示)。A 屋内優先・B 部屋を通り抜けない(既定)、C・D(設定)。
 * **アプリと Website で同じ値**であること —— 片方だけ直すと、同じ場所へ違う道を案内する。
 */
function km_check_route_weights(): void
{
    $dijkstra = (string) file_get_contents(__DIR__ . '/../Main/dijkstra.js');
    $appJs = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    $index = (string) file_get_contents(__DIR__ . '/../index.php');

    km_check_heading('route-weights: Website');
    $web = [];
    if (preg_match('/const KM_ROUTE_WEIGHTS = Object\.freeze\(\{(.*?)\}\);/s', $dijkstra, $m)) {
        preg_match_all('/^\s*(\w+):\s*([0-9.]+|true|false),/m', $m[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $p) {
            $web[$p[1]] = $p[2];
        }
    }
    check_bool('重みの表を読めた', count($web) === 7);
    check('A: 出入り 1 回は 15m(150px)', '150', $web['entranceTransferPx'] ?? null);
    check('A: 屋外は 1.3 倍', '1.3', $web['outsideMultiplier'] ?? null);
    check('B: 部屋を通り抜けないが既定', 'true', $web['noRoomPassThrough'] ?? null);
    // 通り抜けを禁じて道が消えるなら、許してもう一度(経路を消さない)
    check_bool('B: 道が消えるなら通り抜けを許す', str_contains($dijkstra, 'const loose = this.searchPath(startNodeId, endNodeId, false);'));
    check_bool('C・D は検索パネルで選べる', str_contains($index, 'id="route-fewer-floors"') && str_contains($index, 'id="route-rain"'));
    check_bool('C・D を探索に渡す', str_contains($appJs, 'fewerFloors: routeOptions.fewerFloors') && str_contains($appJs, 'rain: routeOptions.rain'));
    // 将来、管理アプリで決めた重みを配信データで配る受け口
    check_bool('配信データの重みを受け取れる', str_contains($appJs, 'weights: graphData.routeWeights'));
    check_bool('壊れた重みは既定へ倒す', str_contains($dijkstra, 'function kmRouteWeights(source)'));

    km_check_heading('route-weights: 配る道(サーバー)');
    require_once __DIR__ . '/../lib/route-weights.php';
    require_once __DIR__ . '/../lib/app-map.php';
    // 既定はサーバー・Website・アプリで同じ(Website は画素。10px/m)
    $forWeb = km_route_weights_for_web(KM_ROUTE_WEIGHTS_DEFAULTS);
    $sameAsWeb = true;
    foreach ($forWeb as $key => $value) {
        $webValue = $web[$key] ?? null;
        $webValue = $webValue === 'true' ? true : ($webValue === 'false' ? false : (float) $webValue);
        if ($webValue !== (is_bool($value) ? $value : (float) $value)) {
            $sameAsWeb = false;
        }
    }
    check_bool('サーバーの既定を画素に直すと Website の既定と同じ', $sameAsWeb);
    check('無い項目は既定で埋める', KM_ROUTE_WEIGHTS_DEFAULTS, km_route_weights_normalize([]));
    check('知らない項目は捨てる', KM_ROUTE_WEIGHTS_DEFAULTS, km_route_weights_normalize(['evil' => 1]));
    check('値は受ける', 2.5, km_route_weights_normalize(['outsideMultiplier' => 2.5])['outsideMultiplier']);
    $rejects = static function (array $input): bool {
        try {
            km_route_weights_normalize($input);
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    // 1 未満の倍率は「屋内優先」「減らす」を逆に効かせる
    check_bool('倍率 1 未満は断る', $rejects(['outsideMultiplier' => 0.5]));
    check_bool('桁違いは断る', $rejects(['floorTransferMeters' => 100000]));
    check_bool('数でないものは断る', $rejects(['verticalPenalty' => 'abc']) && $rejects(['verticalPenalty' => true]));
    check_bool('真偽値でない「通り抜けない」は断る', $rejects(['noRoomPassThrough' => 'false']));
    check_bool('無限大は断る', $rejects(['rainOutsideMultiplier' => INF]));
    check_bool('保存の形が 255 文字に収まる', strlen((string) json_encode(KM_ROUTE_WEIGHTS_DEFAULTS, JSON_PRESERVE_ZERO_FRACTION)) <= 255);

    $api = (string) file_get_contents(__DIR__ . '/../api/route-weights.php');
    $assertAt = strpos($api, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);');
    check_bool('書き込みは管理者の権限を確かめてから', $assertAt !== false
        && $assertAt < (int) strpos($api, 'km_route_weights_publish($pdo')
        && $assertAt < (int) strpos($api, 'km_route_weights_reset($pdo)'));
    check_bool('読みは権限なしで返す(秘密ではない)', strpos($api, "=== 'GET'") < $assertAt);
    check_bool('配った・やめたをタイムラインに残す', str_contains($api, "'route.weights_publish'") && str_contains($api, "'route.weights_reset'"));

    $mapDataLib = (string) file_get_contents(__DIR__ . '/../lib/map-data.php');
    check_bool('Website の地図データに載せる(画素に直して)', str_contains($mapDataLib, "'routeWeights' =>") && str_contains($mapDataLib, 'km_route_weights_for_web($stored)'));
    $appMapApi = (string) file_get_contents(__DIR__ . '/../api/app-map.php');
    // 重みだけ変えたとき、版を上げずに「更新」で届くように
    check_bool('アプリへ: 地図が最新のときの応答にも載せる', preg_match("/'upToDate' => true,.*?'routeWeights' => \\\$routeWeights,/s", $appMapApi) === 1);
    check_bool('アプリへ: 本体の応答にも載せる', str_contains($appMapApi, "    \$routeWeights,\n    \$contentLevel,\n    \$calibration,\n    \$panoramasFor(\$map)\n);"));
    // 地図の外に置く。チェックサムは地図の文字列だけに取る
    $map = new stdClass();
    $map->nodes = [];
    $package = json_decode((string) km_app_map_build_package($map, 'kosen-main', 1, '2026-09-25T00:00:00+09:00', '2027-01-01T00:00:00+09:00', null, true, KM_ROUTE_WEIGHTS_DEFAULTS), true);
    check_bool('パッケージに重みが載る', is_array($package) && ($package['routeWeights']['floorTransferMeters'] ?? null) == 20);
    check('チェックサムは地図だけ', 'sha256:' . hash('sha256', '{"nodes":[]}'), $package['checksum'] ?? null);
    $plain = json_decode((string) km_app_map_build_package($map, 'kosen-main', 1, '2026-09-25T00:00:00+09:00', '2027-01-01T00:00:00+09:00', null, true), true);
    check_bool('配っていなければ null', is_array($plain) && array_key_exists('routeWeights', $plain) && $plain['routeWeights'] === null);

    $routeKt = 'C:/Users/itota/Documents/Test/app/src/main/java/com/ito/kosenmap/RouteSearch.kt';
    if (!is_file($routeKt)) {
        check_skip('route-weights: アプリと突き合わせ', 'アプリの原本が無い(本番のホストなど)');
        return;
    }
    km_check_heading('route-weights: アプリと突き合わせ');
    $kt = (string) file_get_contents($routeKt);
    $num = static function (string $pattern) use ($kt): ?float {
        return preg_match($pattern, $kt, $m) === 1 ? (float) $m[1] : null;
    };
    $pxPerMeter = 10.0;
    check('1 層ぶん(20m × 10px/m)', (float) ($web['floorTransferPx'] ?? -1), $num('/const val FLOOR_TRANSFER_METERS = ([0-9.]+)f/') * $pxPerMeter);
    check('出入り 1 回(15m × 10px/m)', (float) ($web['entranceTransferPx'] ?? -1), $num('/const val ENTRANCE_TRANSFER_METERS = ([0-9.]+)f/') * $pxPerMeter);
    check('屋外の倍率', (float) ($web['outsideMultiplier'] ?? -1), $num('/val outsideMultiplier: Float = ([0-9.]+)f/'));
    check('好みでない昇降の倍率', (float) ($web['verticalPenalty'] ?? -1), $num('/const val VERTICAL_PENALTY = ([0-9.]+)f/'));
    check('階の移動を減らす倍率', (float) ($web['fewerFloorsMultiplier'] ?? -1), $num('/val fewerFloorsMultiplier: Float = ([0-9.]+)f/'));
    check('雨の日の倍率', (float) ($web['rainOutsideMultiplier'] ?? -1), $num('/val rainOutsideMultiplier: Float = ([0-9.]+)f/'));
    check_bool('部屋を通り抜けないが既定', str_contains($kt, 'val noRoomPassThrough: Boolean = true'));
    check_bool('アプリも道が消えるなら通り抜けを許す', str_contains($kt, 'return searchRoute(graph, nodesByUuid, startNodeUuid, goalNodeUuid, avoidRooms = false'));

    $dir = dirname($routeKt);
    $prefs = (string) @file_get_contents($dir . '/MapPreferences.kt');
    $fragment = (string) @file_get_contents($dir . '/SettingFragment.kt');
    // 一般ビルドでは手元の重みを読まない(取り込みで入っても効かせない)
    check_bool('手元の重みを読むのは管理ビルドだけ', str_contains($prefs, 'if (adminBuild) routeWeightsFromPreferences(preferences, distributed) else distributed'));
    check_bool('重みの設定は管理者だけに出す', str_contains($fragment, '"route_weights_category",'));
    $pkgKt = (string) @file_get_contents($dir . '/MapPackage.kt');
    $viewModel = (string) @file_get_contents($dir . '/WifiViewModel.kt');
    $client = (string) @file_get_contents($dir . '/RouteWeightsClient.kt');
    check_bool('アプリ: 最新のときの応答からも重みを読む', str_contains($pkgKt, 'routeWeights = parseRouteWeights(obj.get("routeWeights"))'));
    check_bool('アプリ: 取得のたびに覚える(最新でも・本体でも)', substr_count($viewModel, 'rememberDistributedRouteWeights(result.') === 2);
    check_bool('アプリ: 一般ビルドは配られた重みを使う', str_contains($prefs, 'DISTRIBUTED_ROUTE_WEIGHTS_KEY, null)) ?: RouteWeights()'));
    check_bool('アプリ: 配る先は api/route-weights.php', str_contains($client, '"/api/route-weights.php"'));
    check_bool('アプリ: 配るボタンは管理者だけに出す', str_contains($fragment, '"route_weight_publish",') && str_contains($fragment, '"route_weight_unpublish",'));
}

/**
 * GitHub の控えへ出す道(2026-09-25、利用者の指示)。配備のあとに両方の控えへ出す。
 * **出せなくても配備を失敗にしない**こと、**まとめて検査を外す道を作らない**ことを押さえる。
 */
function km_check_github_mirror(): void
{
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('GitHub の控えへ出す道', '手元の作業ツリーで確認する(配備先に PC 側の道具は無い)');
        return;
    }
    $read = static fn (string $path): string => (string) @file_get_contents($repoRoot . '/' . $path);
    $deploy = $read('scripts/deploy-to-host.ps1');
    $all = $read('scripts/push-github-all.ps1');

    km_check_heading('github-mirror: 配備のあとに出す');
    check_bool('push-github-all.ps1 がある', $all !== '');
    check_bool('push-github-all.ps1 は UTF-8 BOM 付き', str_starts_with($all, "\xEF\xBB\xBF"));
    check_bool('配備に -GitHub がある(既定は両方)', preg_match("/\[ValidateSet\('all', 'web', 'android', 'none'\)\]\s*\[string\]\\\$GitHub = 'all'/", $deploy) === 1);
    check_bool('配備は push-github-all.ps1 を呼ぶ', str_contains($deploy, "Join-Path \$PSScriptRoot 'push-github-all.ps1'"));
    // 配備の「あと」= 後片付けの結果を待ってから(終了コードの分岐より前、転送より後)
    $pushAt = strpos($deploy, "\$pushAll = Join-Path");
    check_bool('出すのは転送と後片付けのあと', $pushAt !== false && $pushAt > (int) strpos($deploy, '& $setupScript @setupArgs'));
    // 出せなくても exit の値を変えない。catch の中で exit しないこと
    if (preg_match('/\$pushAll = Join-Path.*?\n\}\n/s', $deploy, $m)) {
        check_bool('出せなくても配備の終了コードは変えない', !str_contains($m[0], 'exit '));
    } else {
        check_bool('出す段を読めた', false);
    }

    km_check_heading('deploy: バックアップは配備の前(2026-10-15、利用者の指摘)');
    // 戻す先は「配備する前の、動いている状態」。取れなければ何も送らずに止める
    $backupAt = strpos($deploy, '$backupResult = Invoke-KmBackupStep -Open:$OpenBackup');
    check_bool('-Backup の控えは tar を作る前', $backupAt !== false && $backupAt < (int) strpos($deploy, "\$tarArgs = @('-czf'"));
    check_bool('控えが取れなければ配備しない(exit 3)', preg_match('/\$backupResult = Invoke-KmBackupStep -Open:\$OpenBackup\s*\} catch \{.*?exit 3\s*\}/s', $deploy) === 1);
    check('-Backup の控えを取るのは 1 か所だけ', 1, substr_count($deploy, '$backupResult = Invoke-KmBackupStep'));

    km_check_heading('update-containers: コンテナの更新を選んで進める(2026-10-16、利用者の指示)');
    $upd = $read('scripts/update-containers.ps1');
    check_bool('update-containers.ps1 がある(UTF-8 BOM 付き)', str_starts_with($upd, "\xEF\xBB\xBF"));
    check_bool('配備は deploy-to-host.ps1 -Backup に任せる(控えは配備の前)', preg_match('/& \$deploy -Action deploy -Backup [^\n]*-Yes/', $upd) === 1);
    check_bool('控えが取れなかった(3)ら先へ進まない', preg_match('/3 \{[^}]*return \$false \}/', $upd) === 1);
    check_bool('書き換えたら check.php と本番での確かめを通し、駄目なら元に戻す', substr_count($upd, 'if (-not (Confirm-ReadyToDeploy $backup)) { return }') === 2
        && preg_match('/function Confirm-ReadyToDeploy.*?Invoke-Check.*?Test-ComposeOnHost.*?Restore-Files \$Backup/s', $upd) === 1);
    // compose の中身が間違っていたら送る前に止める(2026-10-16、利用者の指示「自動で停止か、リトライ」)
    check_bool('本番の .env で compose を読めるか、置き場を汚さずに確かめる', str_contains($upd, 'docker compose --project-directory $RemotePath -f "`$t/compose.yaml" -f "`$t/compose.vps.yaml" config -q')
        && str_contains($upd, "trap 'rm -rf \"`\$t\"' EXIT"));
    check_bool('書いてある像が全部レジストリにあるかを見る', str_contains($upd, 'docker buildx imagetools inspect "`$ref"'));
    check_bool('確かめは配備の yes より前', strpos($upd, 'if (-not (Confirm-ReadyToDeploy $backup)) { return }') < strpos($upd, 'Confirm-Yes "本番で控えを取ってから配備'));
    check_bool('取る・作るは自動でやり直し、駄目なら up -d せずに止める', str_contains($upd, "if (\$pullServices.Count -and -not (Invoke-HostWithRetry \"docker compose pull")
        && str_contains($upd, "if (-not (Invoke-HostWithRetry 'docker compose pull logto')) { & \$rollbackLogto; return }"));
    check_bool('Logto の移行はやり直さない(1 回だけ)', !preg_match('/Invoke-HostWithRetry[^\n]*alteration deploy/', $upd));
    check_bool('入れ替えたら起動を見届け、駄目なら戻し方を選ばせる', str_contains($upd, 'if ($bad.Count -gt 0) { Invoke-Recover $backup -AfterUp -BuildServices $buildServices; return }'));
    check_bool('戻すための配備では控えを取り直さない', preg_match('/if \(\$Rollback\) \{\s*& \$deploy -Action deploy -GitHub none -Yes/', $upd) === 1);
    check_bool('本番を変える前は yes と打たせる(大文字小文字も区別)', str_contains($upd, "-ceq 'yes'"));
    check_bool('Logto の移行は新しい像の使い捨てコンテナで当てる', str_contains($upd, 'docker compose run --rm --entrypoint sh logto -c \'cd /etc/logto && npm run alteration deploy $newText\''));
    check_bool('移行の前に一覧を見せる', strpos($upd, 'alteration list') < strpos($upd, 'alteration deploy'));
    check_bool('1 つだけの up -d を叩かない', preg_match('/docker compose up -d [a-z]/', $upd) === 0);
    check_bool('Logto は 1 つずつ(飛ばすときは skip と打たせる)', str_contains($upd, "-cne 'skip'"));
    check_bool('タグの無い固定は上げない(上げ先が分からない)', str_contains($upd, 'Where-Object { -not $_.Untagged -and -not $_.IsLogto }'));
    check_bool('latest に戻す道が無い(digest を必ず書く)', str_contains($upd, '"$($p.Prefix)$($c.NewRef)@$($c.NewDigest)$($p.Suffix)"'));
    check_bool('ホストの出力は画面へ流す(関数の戻り値に混ぜない)', str_contains($upd, '& ssh @sshArgs | Out-Host'));
    // 手元だけ書き換えて止めた回のあと、本番 1.44.0 なのに「最新です」と出た(2026-10-16、利用者の指摘)
    check_bool('Logto のいまの版は本番で動いているものから読む', str_contains($upd, "\$runningImage = if (\$null -ne \$hostState) { \"\$(\$hostState.Running['logto'])\" }")
        && strpos($upd, '$hostState = Get-HostState') < strpos($upd, '$current = ConvertTo-Version $currentText'));
    check_bool('修正版も本番と比べる(手元だけ新しいものを ☆ で出す)', str_contains($upd, "elseif (\$onHost.State -eq 'no') { 'pending' }"));
    check_bool('やめたときの既定は手元を戻す(Enter = 戻す)', substr_count($upd, '(Enter = 戻す / n = 残す)') === 3 && !str_contains($upd, 'Enter = 残す'));
    /*
     * 止めたコンテナがあると動かなかった(2026-10-22、利用者の指摘。PHP の RCE で nginx と certbot 以外を止めていた):
     *   - 控え(host-backup.sh)が「mariadb が動いていません」で止まり、修正版を当てる道が塞がった
     *   - 止めたサービスは比べられず「変化なし」と出た(check-updates.sh と Get-HostState が ps -q = 動いているものだけ)
     */
    $updCheck = $read('scripts/check-updates.sh');
    $updBackup = $read('scripts/host-backup.sh');
    check_bool('止まっているコンテナも比べる(ps -a)', str_contains($upd, 'for c in $(docker compose ps -a -q 2>/dev/null); do')
        && str_contains($updCheck, 'for _cid in $(docker compose ps -a -q 2>/dev/null); do') && !preg_match('#docker compose ps -q 2>/dev/null\)#', $upd . $updCheck));
    check_bool('控え: 止まっている DB は控えのあいだだけ起こし(start)、終わったら止め直す', str_contains($updBackup, 'if ! docker compose start "$_svc" >/dev/null 2>&1; then')
        && str_contains($updBackup, 'STARTED_DBS="$STARTED_DBS $_svc"') && str_contains($updBackup, 'docker compose stop -t 60 $STARTED_DBS')
        && strpos($updBackup, 'docker compose stop -t 60 $STARTED_DBS') < strpos($updBackup, 'rm -rf "$WORK"')
        && !str_contains($updBackup, 'compose up -d "$_svc"') && !preg_match('/compose start (web|phpmyadmin)/', $updBackup));

    km_check_heading('github-mirror: 検査を外す道を作らない');
    check_bool('まとめて出すときに -Force を渡さない', !preg_match('/pushArgs \+= .*-Force/', $all));
    check_bool('片方が止まってももう片方は出す', str_contains($all, 'catch {') && str_contains($all, 'foreach ($m in $mirrors)'));
    check_bool('2つの push-github.ps1 の食い違いを知らせる', str_contains($all, 'Get-FileHash'));
    check_bool('検査だけの道がある', str_contains($all, "\$pushArgs += '-CheckOnly'"));

    // 控え(PC の F:\Pull)が在るときだけ、2つの push-github.ps1 が同じかを見る
    $androidPush = 'F:/Pull/Ichinoseki_Kosen/scripts/push-github.ps1';
    $webPush = 'F:/Pull/Ichinoseki_Kosen_Web/tools/push-github.ps1';
    if (is_file($androidPush) && is_file($webPush)) {
        check_bool('Android と Website の push-github.ps1 は同じ中身', hash_file('sha256', $androidPush) === hash_file('sha256', $webPush));
        $push = (string) file_get_contents($webPush);
        check_bool('stage してから検査する(新しいファイルも見る)', strpos($push, 'git add -A') < strpos($push, 'git diff --cached --unified=0'));
        check_bool('push したら PR の説明を開く', str_contains($push, 'Start-Process -FilePath $target.FullName'));
        // マージ済みの説明に足すと、前の PR の文が混ざる
        check_bool('マージ済みの説明には足さない', str_contains($push, 'git cat-file -e "origin/main:docs/pull-requests/'));
    } else {
        check_skip('2つの push-github.ps1 の突き合わせ', '控え(F:\Pull)が無い');
    }
}

/**
 * 教職員による地点の編集(docs/15 段 G)。**DB は使わない** —— 差分の作り方と、経路の守りを見る。
 */
function km_check_staff_nodes(): void
{
    require_once __DIR__ . '/../lib/staff-nodes.php';

    km_check_heading('staff-nodes: 地点の ID の受け取り方');
    check('UUID はハイフンを抜いて小文字の 32 字にする', '0123456789abcdef0123456789abcdef', km_staff_node_normalize_id(' 01234567-89AB-CDEF-0123-456789ABCDEF '));
    check('ハイフン無しの UUID もそのまま通す', '0123456789abcdef0123456789abcdef', km_staff_node_normalize_id('0123456789abcdef0123456789abcdef'));
    check('旧 Web の id は大文字小文字を変えずに通す', 'n_New_10', km_staff_node_normalize_id('n_New_10'));
    check('記号の混じった値は断る', null, km_staff_node_normalize_id("x'; DROP TABLE km_map_nodes;--"));
    check('空は断る', null, km_staff_node_normalize_id('  '));

    km_check_heading('staff-nodes: 変えた項目だけを提案にする');
    $current = ['title' => '研究室', 'subtitle' => '管-104', 'occupantName' => '高専 太郎', 'note' => ''];
    check('同じ値なら提案にしない', [], km_staff_node_diff($current, $current));
    check('変えた項目だけを from / to の組で持つ', ['occupantName' => ['from' => '高専 太郎', 'to' => '高専 花子']],
        km_staff_node_diff($current, ['title' => '研究室', 'occupantName' => '高専 花子']));
    check('送っていない項目は触らない(空で上書きしない)', [], km_staff_node_diff($current, ['title' => '研究室']));
    check('知らない項目(位置・種類)は捨てる', [], km_staff_node_diff($current, ['x' => '1', 'type' => 'wall', 'floor' => '2F']));
    check('前後の空白は落とす', [], km_staff_node_diff($current, ['subtitle' => ' 管-104 ']));
    check('メモの列が無い環境ではメモを受け取らない', [], km_staff_node_diff(['title' => 'a'], ['note' => 'x']));
    $threw = static function (callable $fn): bool {
        try {
            $fn();
        } catch (InvalidArgumentException $exception) {
            return true;
        }

        return false;
    };
    check_bool('名前は空にできない', $threw(static fn () => km_staff_node_diff($current, ['title' => '  '])));
    check_bool('長すぎる値は断る', $threw(static fn () => km_staff_node_diff($current, ['subtitle' => str_repeat('あ', 65)])));
    check_bool('氏名は空にしてよい(地点から外す)', km_staff_node_diff($current, ['occupantName' => ''])['occupantName']['to'] === '');

    km_check_heading('staff-nodes: 経路の守り');
    $src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);
    $account = $src('account.php');
    $lib = $src('lib/staff-nodes.php');
    $admin = $src('admin/staff-nodes.php');
    check_bool('提案は CSRF を通った POST の中', strpos($account, 'km_csrf_verify()') < strpos($account, "=== 'staff_node_edit'"));
    check_bool('提案の前に、今のサインインで教職員か確かめる',
        strpos($account, "=== 'staff_node_edit'") < strpos($account, 'if (!$isTeacherNow) {')
        && strpos($account, 'if (!$isTeacherNow) {') < strpos($account, 'km_staff_node_propose('));
    check_bool('提案する本人はトークンの sub(フォームから選ばせない)', str_contains($account, "km_staff_node_propose(\$pdo, \$nodeId, (string) \$KM_USER['sub'], \$fields)"));
    check_bool('割り当てられた地点だけ提案できる', preg_match('/function km_staff_node_propose.*?km_staff_node_is_assigned/s', $lib) === 1);
    check_bool('提案は地図に直接書かない(書くのは承認のときだけ)', preg_match('/function km_staff_node_propose\(.*?\n}\n/s', $lib, $m) === 1 && !str_contains($m[0], 'km_staff_node_apply('));
    check_bool('承認の前に、割り当てと教職員の権限をもう一度確かめる',
        preg_match('/function km_staff_node_edit_decide.*?km_staff_node_is_assigned.*?km_staff_request_latest.*?km_staff_node_apply/s', $lib) === 1);
    check_bool('承認は前の状態を条件にして 1 回だけ通す', str_contains($lib, "WHERE id = ? AND status = 'pending'"));
    // 取引の中で CREATE TABLE を流すと MariaDB は暗黙に確定する
    check_bool('承認の取引より前に表を用意する', preg_match('/function km_staff_node_edit_decide.*?km_staff_requests_ensure_table\(\$pdo\);.*?beginTransaction/s', $lib) === 1);
    check_bool('地図に書くのは lib/map-edit.php の関数(氏名は専用の操作)', str_contains($lib, 'km_map_node_set_occupant($pdo, $nodeId,') && str_contains($lib, 'km_map_node_update('));
    check_bool('種類は今の値のまま(教職員は変えられない)', str_contains($lib, "(string) \$node['type']"));
    check_bool('割り当ては承認済みの教職員にだけ', preg_match('/function km_staff_node_assign.*?km_staff_request_latest/s', $lib) === 1);
    check_bool('割り当てを外すと確認待ちも取り下げる', preg_match("/function km_staff_node_unassign.*?status = 'withdrawn'/s", $lib) === 1);
    check_bool('通知の失敗で提案を失わせない', preg_match('/function km_staff_node_edit_notify.*?catch \(Throwable/s', $lib) === 1);
    check_bool('管理画面は guard と CSRF を通る', str_contains($admin, "require __DIR__ . '/_inc/guard.php';") && str_contains($admin, 'km_csrf_verify()'));
    check_bool('割り当て・外す・承認・却下を記録する', str_contains($admin, "'staffnode.assigned'") && str_contains($admin, "'staffnode.unassigned'")
        && str_contains($admin, "'staffnode.approved'") && str_contains($admin, "'staffnode.rejected'"));
    check_bool('提案を記録する', str_contains($account, "km_admin_log_record('content', 'staffnode.proposed'"));
    check_bool('押す前に確認を出す', str_contains($admin, 'window.confirm('));
    check_bool('サイドバーに教職員の地点がある', str_contains($src('admin/_inc/partials/sidebar.php'), './staff-nodes.php'));
    check_bool('地図編集に地点の ID を出す', str_contains($src('admin/map-editor.php'), 'id="km-node-uid"') && str_contains($src('admin/assets/js/map-editor.js'), "getElementById('km-node-uid')"));
    // 2026-10-23(利用者の指示): 札の ID・座標・先生の名前を切り替える。既定は全部 ON・手元に覚える・app.js の札がそれに従う
    $editorJs = $src('admin/assets/js/map-editor.js');
    $appJsForLabels = $src('Main/app.js');
    check_bool('地図編集: 札の ID・座標・先生の名前を切り替えられる', substr_count($src('admin/map-editor.php'), 'class="form-check-input km-label-pref"') === 3
        && str_contains($editorJs, "const labelPrefs = { id: true, xy: true, names: true };") && str_contains($editorJs, 'window.kmAdminLabelPrefs = labelPrefs;')
        && str_contains($appJsForLabels, 'const prefs = window.kmAdminLabelPrefs || { id: true, xy: true, names: true };')
        && str_contains($appJsForLabels, 'const name = prefs.names ? displayNameFor(node) : (node.alias || node.name);'));
    $legal = $src('lib/legal.php');
    check_bool('プライバシーポリシーに担当の地点の編集がある', str_contains($legal, '**担当の地点の編集:**'));
    check_bool('プライバシーポリシー: 承認するまで反映しないと書いてある', str_contains($legal, '送った内容は**すぐには地図に反映されません。**'));
    check_bool('プライバシーポリシー: 提案の記録は退会で消えると書いてある', str_contains($legal, '担当の地点の割り当てと変更の提案の記録・教職員氏名とメモの表示の承認が同時に削除されます'));
}
/**
 * アカウント削除の後片付けと、その入口(webhook)。
 *
 * **DB は使わない。** 見るのは「消す対象の表を挙げ漏らしていないか」と、
 * **署名の検証が正しく閉じているか**。
 */
function km_check_account_delete(): void
{
    require_once __DIR__ . '/../lib/account-delete.php';
    require_once __DIR__ . '/../lib/logto-webhook.php';

    km_check_heading('account-delete: 消す対象');
    /*
     * **表を足したらここも足す。** 挙げ漏らすと、その表だけ残る ——
     * 残っても画面は何も言わないので気づけない。
     *
     * `user_id` を主キーか列に持つ表を、実際のコードから数えて突き合わせる。
     */
    /*
     * **lib/ を全部見る。** 最初は5本だけ名指しで読んでいたが、それでは
     * 「そのファイルに書かれた表しか見つけられない」——
     * **検査が通るのに漏れている**という一番たちの悪い形になる。
     */
    $sources = [];
    foreach (glob(__DIR__ . '/../lib/*.php') ?: [] as $path) {
        $sources[] = (string) file_get_contents($path);
    }
    $all = implode("\n", $sources);

    // 「CREATE TABLE IF NOT EXISTS <名前>」のうち、その定義に user_id を持つもの
    preg_match_all(
        '/CREATE TABLE IF NOT EXISTS\s+(km_\w+)\s*\((.*?)\)\s*ENGINE/s',
        $all,
        $matches,
        PREG_SET_ORDER
    );
    $withUserId = [];
    foreach ($matches as $match) {
        if (str_contains($match[2], 'user_id')) {
            $withUserId[] = $match[1];
        }
    }
    sort($withUserId);
    $listed = KM_ACCOUNT_DELETE_TABLES;
    sort($listed);

    check_bool('user_id を持つ表を見つけられている', $withUserId !== [], implode(', ', $withUserId));
    $missing = array_values(array_diff($withUserId, $listed));
    check('消し忘れている表がない', [], $missing);

    km_check_heading('account-delete: 監査の記録');
    // 既定は「名前を消して行は残す」。行ごと消すと、都合の悪い操作の証跡まで消える
    check_bool('既定では行ごと消さない', KM_ACCOUNT_DELETE_PURGES_AUDIT === false);

    /*
     * **名前と ID だけでは足りない。**
     * IP を残すと、時刻と併せてその人を指せてしまい、
     * プライバシーポリシー7の「特定することはできません」と食い違う。
     */
    $deleteLib = (string) file_get_contents(__DIR__ . '/../lib/account-delete.php');
    check_bool('匿名化で名前と ID を落とす', str_contains($deleteLib, 'actor_id = NULL, actor_name = NULL'));
    check_bool('匿名化で接続元 IP も落とす', str_contains($deleteLib, 'ip_address = NULL'));
    // 列が無い環境で落とさない。**移行 SQL を流す前でも後片付けは通る**
    check_bool('端末の名乗りは列が在るときだけ', str_contains($deleteLib, "\$withUserAgent"));

    /*
     * `km_admin_log_record()` の匿名指定。**既定は false** ——
     * 既定で匿名にすると、普段の管理操作から実行者が消えて監査にならない。
     */
    require_once __DIR__ . '/../lib/admin-log.php';
    $recordParams = (new ReflectionFunction('km_admin_log_record'))->getParameters();
    check(
        '記録に匿名の指定がある',
        'anonymous',
        isset($recordParams[3]) ? $recordParams[3]->getName() : '(無い)'
    );
    check_bool(
        '匿名は既定にしない',
        isset($recordParams[3]) && $recordParams[3]->isDefaultValueAvailable()
            && $recordParams[3]->getDefaultValue() === false
    );
    $logLib = (string) file_get_contents(__DIR__ . '/../lib/admin-log.php');
    // 匿名のときは $KM_USER を見ない。**見た上で捨てる書き方にしない**(取り違えの元)
    check_bool('匿名なら実行者を読まない', str_contains($logLib, "\$anonymous ? null : (\$GLOBALS['KM_USER'] ?? null)"));
    check_bool('匿名なら接続元も残さない', str_contains($logLib, '$anonymous ? null : km_map_rate_limit_ip()'));
    check_bool('匿名なら端末の名乗りも残さない', str_contains($logLib, '$anonymous ? null : km_admin_log_user_agent()'));

    /*
     * **直す前に消したアカウントの行は、コードを直しても片付かない。**
     * 実際に1件残っている(本番)。流すための SQL を置いてあるか。
     */
    check_bool(
        '既に残った実名を落とす SQL がある',
        is_file(__DIR__ . '/fix-admin-log-deleted-actor.sql')
    );

    km_check_heading('account-delete: 結果の要約');
    $summary = km_account_delete_summary([
        'tables' => ['km_app_settings' => 2, 'km_user_profiles' => 0],
        'auditAnonymized' => 5,
        'avatarRemoved' => true,
    ]);
    // **件数を残す。**「消しました」だけだと、対象が無かったのかが分からない
    check_bool('消した表と件数を出す', str_contains($summary, 'km_app_settings=2'));
    check_bool('0件の表は並べない', !str_contains($summary, 'km_user_profiles'));
    check_bool('監査の扱いを出す', str_contains($summary, '匿名化=5'));
    check_bool('アバターを消したことを出す', str_contains($summary, 'avatar=1'));
    check('何も無ければそう言う', '対象なし', km_account_delete_summary(
        ['tables' => [], 'auditAnonymized' => 0, 'avatarRemoved' => false]
    ));

    /*
     * SDK はトークンの本体を base64_decode(標準)で読み、`-`・`_` が出るトークンだけ読み違えて
     * 管理画面が「認証の設定が未完了」で止まった(2026-09-17、一般アカウント)。子クラスが base64url で読むこと。
     */
    km_check_heading('logto-sdk-client: トークンの中身を base64url で読む');
    if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
        check_skip('トークンの読み方', 'vendor/ が無い(composer install の前)');
    } else {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/../lib/logto-sdk-client.php';
        $readPayload = Closure::bind(static fn (?string $jwt): array => KmLogtoClient::kmJwtPayload($jwt, 'テスト'), null, KmLogtoClient::class);
        $sdkFailed = 0;
        $mineFailed = 0;
        foreach (['がくせい', '山田 太郎', 'テスト利用者', 'user01', 'kosen?map>', 'a~b', '伊藤'] as $name) {
            foreach (['abc123', 'bg6sxyz0', 'odsjq9z', 'x'] as $sub) {
                $claims = ['sub' => $sub, 'name' => $name, 'iss' => 'https://ito4.jp:3001/oidc', 'roles' => []];
                $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
                $jwt = 'eyJhbGciOiJFUzM4NCJ9.' . $payload . '.sig';
                if (json_decode((string) base64_decode($payload), true) === null) {
                    $sdkFailed++;
                }
                try {
                    if ($readPayload($jwt) !== $claims) {
                        $mineFailed++;
                    }
                } catch (Throwable) {
                    $mineFailed++;
                }
            }
        }
        check_bool('このテストの中に、SDK の読み方では読めないトークンが含まれている', $sdkFailed > 0);
        check('base64url で読めば全部読める', 0, $mineFailed);
        $threw = static function (?string $jwt) use ($readPayload): bool {
            try {
                $readPayload($jwt);

                return false;
            } catch (UnexpectedValueException) {
                return true;
            }
        };
        check_bool('トークンが無ければ TypeError ではなく読めないと知らせる', $threw(null) && $threw('abc') && $threw('a.!!!.c'));
        check_bool('logto-client.php はこの子クラスを使う', str_contains((string) file_get_contents(__DIR__ . '/../logto-client.php'), '$client = new KmLogtoClient('));
    }

    km_check_heading('logto-webhook: 署名の検証');
    $body = '{"event":"User.Deleted","data":{"id":"usr_1"}}';
    $key = 'signing-key';
    $good = hash_hmac('sha256', $body, $key);

    check_bool('正しい署名は通る', km_logto_webhook_signature_valid($body, $good, $key));
    check_bool('大文字でも通る', km_logto_webhook_signature_valid($body, strtoupper($good), $key));
    check_bool('違う署名は通さない', !km_logto_webhook_signature_valid($body, str_repeat('a', 64), $key));
    // **本文を1文字でも変えたら通らない**(改ざんの検出)
    check_bool('本文が違えば通さない', !km_logto_webhook_signature_valid($body . ' ', $good, $key));
    check_bool('違う鍵では通らない', !km_logto_webhook_signature_valid($body, $good, 'other'));
    /*
     * **鍵が未設定なら何も受け付けない。**
     * 「鍵が無いから素通し」にすると、設定し忘れがそのまま穴になる。
     */
    check_bool('鍵が空なら通さない', !km_logto_webhook_signature_valid($body, $good, ''));
    check_bool('署名が空なら通さない', !km_logto_webhook_signature_valid($body, '', $key));

    km_check_heading('logto-webhook: 中身の読み取り');
    $parsed = km_logto_webhook_parse($body);
    check('イベント名', KM_LOGTO_WEBHOOK_USER_DELETED, $parsed['event']);
    check('利用者 id', 'usr_1', $parsed['userId']);

    // 別の形(userId で送る版)も読む
    check(
        'userId でも読む',
        'usr_2',
        km_logto_webhook_parse('{"event":"User.Deleted","data":{"userId":"usr_2"}}')['userId']
    );

    // **知らないイベントから id を拾わない。** 別の形に当てはめて誤って消さない
    $other = km_logto_webhook_parse('{"event":"User.Created","data":{"id":"usr_3"}}');
    check('知らないイベントでは id を返さない', null, $other['userId']);
    check_bool('JSON でなければ空', km_logto_webhook_parse('nope')['userId'] === null);
    check_bool(
        'id が無ければ null',
        km_logto_webhook_parse('{"event":"User.Deleted","data":{}}')['userId'] === null
    );
}

// ================================================================ update-notice

/**
 * 更新の通知メール。**当てはしない。知らせるだけ。**
 *
 * ここで見るのは文面の組み立てだけ。Docker もメールも要らない
 * (実際に調べるのは scripts/check-updates.sh、送るのは scripts/notify-update.php)。
 */
function km_check_update_notice(): void
{
    require_once __DIR__ . '/../lib/update-notice.php';

    km_check_heading('update-notice: 受け取った内容の検証');
    $parsed = km_update_notice_parse(
        '{"host":"ito4.jp","items":[{"name":"logto","current":"1.42.0","available":"1.43.0","kind":"release"}]}'
    );
    check('宛先のホスト', 'ito4.jp', $parsed['host']);
    check('項目を読む', 1, count($parsed['items']));
    check_bool('heartbeat の既定は false', $parsed['heartbeat'] === false);

    // **黙って捨てない。** 名前や種別が無い行は、作った側が間違えている
    $rejected = false;
    try {
        km_update_notice_parse('{"items":[{"current":"1.0","kind":"release"}]}');
    } catch (InvalidArgumentException $exception) {
        $rejected = true;
    }
    check_bool('名前の無い項目は拒む', $rejected);

    $badKind = false;
    try {
        km_update_notice_parse('{"items":[{"name":"x","kind":"なにか"}]}');
    } catch (InvalidArgumentException $exception) {
        $badKind = true;
    }
    check_bool('知らない種別は拒む', $badKind);

    $notJson = false;
    try {
        km_update_notice_parse('これは JSON ではない');
    } catch (InvalidArgumentException $exception) {
        $notJson = true;
    }
    check_bool('JSON でなければ拒む', $notJson);

    km_check_heading('update-notice: 件名');
    $release = [['name' => 'logto', 'current' => '1.42.0', 'available' => '1.43.0', 'kind' => 'release']];
    $digest = [['name' => 'nginx:alpine', 'current' => 'sha256:a', 'available' => 'sha256:b', 'kind' => 'digest']];

    // **件名だけで判断できるようにする**(一覧で開かずに済む)
    check_bool('新しい版は名前を出す', str_contains(km_update_notice_subject($release), 'logto'));
    check_bool(
        '中身の入れ替わりは別の言い方',
        km_update_notice_subject($release) !== km_update_notice_subject($digest)
    );
    check_bool(
        '変化なしと定期のお知らせを区別する',
        km_update_notice_subject([], false) !== km_update_notice_subject([], true)
    );

    km_check_heading('update-notice: 本文');
    $body = km_update_notice_body($release, 'ito4.jp');
    check_bool('いまの版と新しい版を並べる', str_contains($body, '1.42.0') && str_contains($body, '1.43.0'));
    // 「更新があります」だけのメールでは手が動かない
    check_bool('先にバックアップと書く', str_contains($body, '-BackupOnly'));
    check_bool('会期中は上げないと書く', str_contains($body, '会期中は上げないこと'));
    // Logto の移行には巻き戻しがある(「戻す手段は復元しかない」は誤りだった)
    check_bool('Logto の巻き戻しを書く', str_contains($body, 'alteration rollback'));
    // **メールに markdown を書かない。** 受け取る側は素のテキストで読む
    check_bool('本文に ** を残さない', !str_contains($body, '**'));

    $quiet = km_update_notice_body([], 'ito4.jp', true);
    check_bool('定期のお知らせは沈黙を説明する', str_contains($quiet, '仕組みが止まっている'));
    check_bool('定期のお知らせにも ** を残さない', !str_contains($quiet, '**'));

    // 上限。**読めないほど長いメールを送らない**
    $many = [];
    for ($i = 0; $i < KM_UPDATE_NOTICE_MAX_ITEMS + 5; $i++) {
        $many[] = ['name' => 'img' . $i, 'current' => 'a', 'available' => 'b', 'kind' => 'digest'];
    }
    check_bool('多すぎる分は件数でまとめる', str_contains(km_update_notice_body($many, 'h'), 'ほか 5 件'));

    /*
     * ## 系列タグとビルド元(2026-09-13 に足した種類)
     *
     * それまで見張っていたのは動くタグ(latest など)だけで、DB 系とビルド元は
     * 修正版が出ても誰も知らなかった。**打つ手が違う**ので、件名も本文も分ける ——
     * 系列タグは pull で当たるが、ビルド元は build --pull し直すまで当たらない。
     */
    km_check_heading('update-notice: 系列タグとビルド元');
    check(
        '系列タグを読む',
        'series',
        km_update_notice_parse('{"items":[{"name":"mariadb:11.4","kind":"series"}]}')['items'][0]['kind']
    );
    check(
        'ビルド元を読む',
        'base',
        km_update_notice_parse('{"items":[{"name":"php:8.4-apache","kind":"base"}]}')['items'][0]['kind']
    );
    $series = [['name' => 'mariadb:11.4', 'current' => 'sha256:a', 'available' => 'sha256:b', 'kind' => 'series']];
    $base = [['name' => 'php:8.4-apache (web のビルド元)', 'current' => 'sha256:c', 'available' => 'sha256:d', 'kind' => 'base']];
    $subjects = [
        km_update_notice_subject($release),
        km_update_notice_subject($digest),
        km_update_notice_subject($series),
        km_update_notice_subject($base),
    ];
    check('4種類の件名が全部違う', 4, count(array_unique($subjects)));
    // 混ざったとき、件名に出なかった分を黙らない
    check_bool(
        '混ざったら件数を添える',
        str_contains(km_update_notice_subject(array_merge($release, $series)), 'ほか 1件')
    );
    $seriesBody = km_update_notice_body($series, 'h');
    $baseBody = km_update_notice_body($base, 'h');
    check_bool('系列タグは pull と書く', str_contains($seriesBody, 'docker compose pull'));
    check_bool('ビルド元は build --pull と書く', str_contains($baseBody, 'build --pull'));
    check_bool('系列タグの本文に ** を残さない', !str_contains($seriesBody, '**'));
    check_bool('ビルド元の本文に ** を残さない', !str_contains($baseBody, '**'));
    // 1 行に詰めると digest が折り返されて、どちらが今か読めない(2026-10-12、利用者の指摘)
    check_bool(
        'いま使っているものと新しく出たものを別の行に書く',
        str_contains($baseBody, "いま使っているもの:\n        sha256:c")
            && str_contains($baseBody, "新しく出たもの:\n        sha256:d")
    );
    check_bool('ビルド元は Dockerfile の digest を書き換えると書く', str_contains($baseBody, 'Dockerfile(docker/php・docker/soketi)の digest'));
    check_bool('件数を先に書く', str_contains($baseBody, '更新が 1 件あります。'));
}

// ========================================================================= self

/**
 * 検査そのものの決まり。**この検査はホストでも走る。**
 *
 * `host-setup.sh` が web コンテナの中から呼ぶので、見えているのは `src/` だけ。
 * `src/` の外(`scripts/` や `Old/`)を素で読むと、配備先では必ず読めず、
 * **配備のたびに FAIL が並ぶ。** 毎回出る FAIL は読まれなくなり、
 * **本物の失敗がその中に埋もれる** —— 2026-09-05 に実際にそうなった
 * (私が足したセキュリティ通知の検査 12 件がそれだった)。
 */
function km_check_self(): void
{
    $source = (string) file_get_contents(__FILE__);

    km_check_heading('self: 配備先でも走らせられるか');
    /*
     * `src/` の外へ出る書き方は `km_check_repo_root()` 1箇所に集める。
     * 2つ以上あったら、増やした側が同じ罠を仕掛けている。
     *
     * **探す文字列は切って組み立てる。** そのまま書くと、この行自身に当たって
     * 数が1つ増える(CSS のコメントや PHP の docblock で何度も踏んだ形)。
     */
    $needle = "__DIR__ . '/" . '../..';
    check('src の外を見る場所は1つだけ', 1, substr_count($source, $needle));
    // 調べられないものは**黙って飛ばさない**。出さないと「通った」と読まれる
    check_bool('飛ばしたら SKIP と書く', str_contains($source, 'SKIP  ('));
    check_bool('飛ばした数を最後に出す', str_contains($source, '調べられず飛ばしたもの'));
}

// ================================================================ backup-notice

/**
 * 2026-09-10 のセキュリティ確認の所見を直したもの(2026-09-14)。**戻っていないかを見張る。**
 * 所見の番号は Old/docs/security-review-2026-09-10.md。
 *
 * DB を使うもの(錠ごとの数え方・ランキング・在席の上限)は、本番と切り離した使い捨ての
 * 試験環境で実際に回して確かめた(status.md)。ここは**書き方が戻っていないか**と、
 * DB 無しで回せる純粋な部分を見る。
 */
function km_check_security_review(): void
{
    $src = __DIR__ . '/..';
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    // 説明の文章に当たらないよう、コメントを外してから探す
    $code = static fn (string $text): string => (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $text);

    km_check_heading('security-review 5: サインアウトで地図の錠を閉める');
    $signOut = $code($read('sign-out.php'));
    check_bool('km_map_unlocked を消す', str_contains($signOut, "\$_SESSION['km_map_unlocked']"));
    check_bool('km_map_admin を消す', str_contains($signOut, "\$_SESSION['km_map_admin']"));

    km_check_heading('security-review 3・7: 解除の試行は錠ごとに、DB が落ちても数える');
    require_once __DIR__ . '/../lib/map-rate-limit.php';
    check('錠は web と app と guest(お試しの閲覧の再入場コード)', ['web', 'app', 'guest'], array_keys(KM_MAP_UNLOCK_SCOPES));
    check_bool('表は別々', KM_MAP_UNLOCK_SCOPES['web'] !== KM_MAP_UNLOCK_SCOPES['app']);
    $unlockApi = $code($read('api/map-unlock.php'));
    $appMapApi = $code($read('api/app-map.php'));
    /*
     * 2026-09-14 から**照合の前に数える**(km_map_unlock_attempt。診断 critic 0 —— 「判定→照合→記録」だと
     * 同時に送って上限を超えられた)。以前の期待(km_map_rate_limited と、app でも成功で消す)は古い。
     * **'app' は成功しても消さない**(android-data 1 —— 配布済みのコードを混ぜれば無制限に試せた)。
     * コメントを外してから探す(api/app-map.php の説明文に、やめた呼び出しの名前が残っている)。
     */
    check_bool('Web 地図は web で数え、成功で消す', str_contains($unlockApi, "km_map_unlock_attempt(\$pdo, 'web')") && str_contains($unlockApi, "km_map_clear_unlock_failures(\$pdo, 'web')"));
    check_bool('アプリは app で数え、成功しても消さない', str_contains($appMapApi, "km_map_unlock_attempt(\$pdo, 'app')") && !str_contains($appMapApi, "km_map_clear_unlock_failures(\$pdo, 'app')"));
    check_bool('DB の例外で「通す」を返さない', preg_match('/catch \(PDOException[^}]*return false;/s', $code($read('lib/map-rate-limit.php'))) === 0);
    $unknownScope = false;
    try {
        km_map_rate_limit_table('other');
    } catch (InvalidArgumentException) {
        $unknownScope = true;
    }
    check_bool('知らない錠は使えない(表の名前が SQL に入るため)', $unknownScope);

    // 予備(ファイル)の数え方を実際に回す。時刻は渡すので、待たずに試せる
    $fallbackDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'km-check-unlock-' . bin2hex(random_bytes(4));
    $previousDir = getenv('KM_RATE_LIMIT_FALLBACK_DIR');
    $previousIp = $_SERVER['HTTP_X_REAL_IP'] ?? null;
    putenv('KM_RATE_LIMIT_FALLBACK_DIR=' . $fallbackDir);
    $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.77';
    $now = 1_800_000_000;
    for ($i = 0; $i < KM_MAP_UNLOCK_FAILURE_LIMIT - 1; $i++) {
        km_map_rate_limit_fallback_record('web', $now);
    }
    check_bool('予備: 上限の1回前ではロックしない', !km_map_rate_limit_fallback_locked('web', $now));
    km_map_rate_limit_fallback_record('web', $now);
    check_bool('予備: 上限でロックする', km_map_rate_limit_fallback_locked('web', $now));
    check_bool('予備: 錠は別', !km_map_rate_limit_fallback_locked('app', $now));
    check_bool('予備: ロックは時間で解ける', !km_map_rate_limit_fallback_locked('web', $now + KM_MAP_UNLOCK_LOCK_SECONDS + 1));
    check_bool('予備: ファイル名に IP を出さない', !str_contains(implode(',', glob($fallbackDir . '/*') ?: []), '203.0.113.77'));
    km_map_rate_limit_fallback_clear('web');
    check_bool('予備: 解けば消える', !km_map_rate_limit_fallback_locked('web', $now));
    foreach (glob($fallbackDir . '/*') ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($fallbackDir);
    putenv($previousDir === false ? 'KM_RATE_LIMIT_FALLBACK_DIR' : 'KM_RATE_LIMIT_FALLBACK_DIR=' . $previousDir);
    if ($previousIp === null) {
        unset($_SERVER['HTTP_X_REAL_IP']);
    } else {
        $_SERVER['HTTP_X_REAL_IP'] = $previousIp;
    }

    km_check_heading('security-review 2・9: ランキングは sub を出さず、語は3つの出どころから');
    require_once __DIR__ . '/../lib/app-ranking.php';
    $secret = str_repeat('k', 32);
    $userKey = km_ranking_public_user_key($secret, 'sub-123', 2026);
    check_bool('利用者キーの形(u + 16進31桁)', preg_match('/^u[0-9a-f]{31}$/', $userKey) === 1, $userKey);
    check_bool('年が違えば別のキー', $userKey !== km_ranking_public_user_key($secret, 'sub-123', 2027));
    check_bool('鍵が違えば別のキー', $userKey !== km_ranking_public_user_key(str_repeat('x', 32), 'sub-123', 2026));
    $rankingCode = $code($read('lib/app-ranking.php'));
    check_bool('応答の userId は鍵つきの印', str_contains($rankingCode, "'userId' => km_ranking_public_user_key(") && !str_contains($rankingCode, "'userId' => (string) \$row['user_id']"));
    check('語を出すのに要る出どころ', 3, KM_RANKING_QUERY_MIN_SOURCES);
    check_bool('語の一覧は出どころの数で絞る', str_contains($rankingCode, 'FROM km_map_ranking_query_sources s') && str_contains($rankingCode, ') >= ?'));
    check_bool('記録に出どころを渡す', str_contains($code($read('api/app-ranking.php')), 'km_map_rate_limit_ip()'));
    check_bool('用途が違えば別の印', km_app_keyed_hash($secret, 'a', 'v') !== km_app_keyed_hash($secret, 'b', 'v'));
    check_bool('同じ入力なら同じ印', km_app_keyed_hash($secret, 'a', 'v') === km_app_keyed_hash($secret, 'a', 'v'));

    km_check_heading('security-review 2・23: アカウント画像は本人だけ、MIME は拡張子から');
    $avatarApi = $code($read('api/app-avatar.php'));
    $getAt = strpos($avatarApi, "if (\$method === 'GET')");
    $requireAt = $getAt === false ? false : strpos($avatarApi, 'logto_require_principal()', $getAt);
    $findAt = $getAt === false ? false : strpos($avatarApi, 'km_profile_find(', $getAt);
    check_bool('GET はログインを確かめてから引く', $requireAt !== false && $findAt !== false && $requireAt < $findAt);
    check_bool('他人の sub は断る', str_contains($avatarApi, 'hash_equals($subject, $requested)'));
    check_bool('引くのはトークンの sub', str_contains($avatarApi, 'km_profile_find($pdo, $subject)'));
    require_once __DIR__ . '/../lib/profile.php';
    check('png は image/png', 'image/png', km_profile_avatar_mime('avatar_' . str_repeat('a', 32) . '.png'));
    check('jpg は image/jpeg', 'image/jpeg', km_profile_avatar_mime('avatar_' . str_repeat('a', 32) . '.jpg'));
    check('知らない拡張子は octet-stream', 'application/octet-stream', km_profile_avatar_mime('x.html'));
    foreach (['api/app-avatar.php', 'admin/api/avatar.php'] as $avatarFile) {
        check_bool("{$avatarFile} は DB の MIME を流さない", !str_contains($code($read($avatarFile)), "\$row['avatarMime']"));
    }

    km_check_heading('security-review 10: 在席は1つの IP から数える端末に上限');
    $statsCode = $code($read('lib/user-stats.php'));
    check_bool('上限がある', str_contains($statsCode, 'KM_USER_STATS_MAX_CLIENTS_PER_SOURCE'));
    check_bool('IP は鍵つきの印にする', str_contains($statsCode, "km_app_keyed_hash(km_app_secret(\$pdo, 'presence')"));
    check_bool('既存の表にも列を足す', str_contains($statsCode, 'ADD COLUMN IF NOT EXISTS source_hash'));
    check_bool('api が IP を渡す', str_contains($code($read('api/app-stats.php')), 'km_map_rate_limit_ip()'));

    km_check_heading('security-review 11・12・13: Logto のキャッシュ・停止判定・DB の証明書');
    $management = $code($read('lib/logto-management.php'));
    check_bool('共有の tmp にキャッシュを置かない', !str_contains($management, 'sys_get_temp_dir()'));
    check_bool('信用できるキャッシュだけ読む(トークンと停止判定の両方)', substr_count($management, 'km_logto_private_cache_read(') >= 3);
    check_bool('利用者の形でない応答では閉じる', str_contains($management, "array_key_exists('isSuspended', \$user)"));
    $dbCode = $code($read('lib/db.php'));
    check_bool('明示しない限り、証明書を検証せずに繋がない', str_contains($dbCode, "getenv('KM_DB_ALLOW_UNVERIFIED_TLS')") && str_contains($dbCode, '証明書を検証できないので接続しません'));

    km_check_heading('security-review 14・15・17・22: ブラウザ側の守り');
    $csp = $code($read('lib/csp.php'));
    check_bool('reCAPTCHA はパスまで絞る', str_contains($csp, "'https://www.google.com/recaptcha/'") && !str_contains($csp, "'https://www.google.com';"));
    check_bool('gstatic もパスまで絞る', !str_contains($csp, "'https://www.gstatic.com';"));
    check_bool('チャットの埋め込みは HEX で逃がす', substr_count($read('admin/chat.php'), 'JSON_HEX_TAG') >= 2);
    check_bool('見取り図は sandbox の CSP で配る', str_contains($code($read('api/floor-image.php')), 'sandbox'));
    // style 属性は CSP の style-src に弾かれて効かない。自前の画面と JS に置かない
    $styled = [];
    $styleTargets = array_merge(
        glob($src . '/*.php') ?: [],
        glob($src . '/admin/*.php') ?: [],
        glob($src . '/admin/_inc/*.php') ?: [],
        glob($src . '/admin/_inc/partials/*.php') ?: [],
        glob($src . '/admin/assets/js/*.js') ?: []
    );
    foreach ($styleTargets as $styleFile) {
        // 説明の文章の「style="…"」(三点リーダ)は数えない。head.php の PHP の1行コメントに書いてある
        // (**ここに PHP の閉じタグを書かないこと** —— // のコメントの中でも PHP の読み取りがそこで終わる)
        if (preg_match('/\sstyle="(?!…)/u', $code((string) file_get_contents($styleFile))) === 1) {
            $styled[] = basename($styleFile);
        }
    }
    check_bool('style="…" 属性を使わない', $styled === [], implode(', ', $styled));

    km_check_heading('security-review 18・19・20: webhook とダウンロード');
    require_once __DIR__ . '/../lib/logto-webhook.php';
    check_bool('新しいイベントは通す', !km_logto_webhook_is_stale($now - 60, $now));
    check_bool('1日より古いものは捨てる', km_logto_webhook_is_stale($now - 86401, $now));
    check_bool('未来すぎるものは捨てる', km_logto_webhook_is_stale($now + 301, $now));
    check_bool('createdAt が無い版は通す', !km_logto_webhook_is_stale(null, $now));
    check('createdAt を読む', strtotime('2026-09-14T00:00:00.000Z'), km_logto_webhook_created_at('{"createdAt":"2026-09-14T00:00:00.000Z"}'));
    $hook = $code($read('api/logto-webhook.php'));
    $staleAt = strpos($hook, 'km_logto_webhook_is_stale(');
    $parseAt = strpos($hook, 'km_logto_webhook_parse(');
    check_bool('古さを見てから消す', $staleAt !== false && $parseAt !== false && $staleAt < $parseAt);
    // 送り方は lib/uploads.php の km_upload_send に移した(2026-10-05。共有リンクと同じ道)
    $download = $code($read('lib/uploads.php'));
    check_bool('管理画面のダウンロードは km_upload_send を使う', str_contains($code($read('admin/api/file-download.php')), 'km_upload_send($file)'));
    check_bool('保存名の形を確かめる', str_contains($download, '[0-9a-f]{32}\.[A-Za-z0-9]{1,16}'));
    $backslashNeedle = <<<'NEEDLE'
'"', '\\']
NEEDLE;
    check_bool('ファイル名から \\ も落とす', str_contains($download, $backslashNeedle));

    km_check_heading('security-review 6・16・21 と持ち越し: nginx・compose・復元');
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('nginx・compose・復元', '手元の作業ツリーで確認する');

        return;
    }
    $nginx = (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($repoRoot . '/nginx/default.conf.template'));
    check_bool('本文の既定は小さい(3m)', preg_match('/http2 on;.*?client_max_body_size 3m;/s', $nginx) === 1);
    check_bool('210m は APK の差し替えだけ', substr_count($nginx, 'client_max_body_size 210m;') === 1 && preg_match('#location = /admin/downloads\.php \{\s*client_max_body_size 210m;#', $nginx) === 1);
    check_bool('vendor を塞ぐ', str_contains($nginx, 'location ~ ^/(lib|config|scripts|uploads|cache|vendor)/'));
    check_bool('ランキングへの記録を絞る', str_contains($nginx, 'zone=km_app:10m') && str_contains($nginx, 'limit_req zone=km_app '));
    check('枠の制限は 8281 と 8025 の2つ(3001 は Logto が自分で送る)', 2, substr_count($nginx, "frame-ancestors 'self'\" always"));
    check_bool('KM_CSP_REPORT_ONLY を web へ渡す', str_contains((string) file_get_contents($repoRoot . '/compose.yaml'), 'KM_CSP_REPORT_ONLY: ${KM_CSP_REPORT_ONLY:-}'));
    // **2回に分けて外す。** 1本の正規表現で s を付けると、`#.*$` が改行をまたいでファイルの終わりまで食う
    $restoreCode = (string) preg_replace(
        ['/<#.*?#>/s', '/^\s*#.*$/m'],
        '',
        (string) file_get_contents($repoRoot . '/Old/restore-data.ps1')
    );
    check_bool('復元はコンテナ名を直に書かない', preg_match('/(?<![\w\/.-])(dev|km)-(mariadb|postgres|php-apache|logto)(?![\w.])/', $restoreCode) === 0);
    check_bool('復元は compose の札で探す', str_contains($restoreCode, 'label=com.docker.compose.service='));
    check_bool('平文のダンプは持ち主だけの一時フォルダに置く', str_contains($restoreCode, 'umask 077 && mktemp -d /tmp/km-restore.XXXXXX'));
    check_bool('一時フォルダは finally で消す', preg_match('/finally\s*\{[^}]*rm -rf/s', $restoreCode) === 1);
    check_bool('行数はすべての INSERT を足す', str_contains($restoreCode, 'foreach ($m in [regex]::Matches($sqlText'));
}

// ==================================================================== hardening

/**
 * 2026-09-14 の診断(セキュリティ・ドメイン・負荷・地図の配信の分割)で直したもの。**戻っていないかを見張る。**
 *
 * 所見の番号は診断の dim#idx(server-ops 6 など)。約束の節 A〜D は
 *   A ドメインは KM_DOMAIN から導く / B 負荷とタイムアウト / C セキュリティ / D 地図の配信を2つに分ける
 *
 * 前半(src だけで回せるもの)は**配備先でも走る**。compose・nginx・scripts は手元の作業ツリーでだけ見る
 * (km_check_repo_root() の説明)。本番で効いているか(nginx -t・up -d・429 が返るか)は、ここでは分からない ——
 * status.md の「本番でまだ実行していないこと」を見ること。
 */
function km_check_hardening(): void
{
    $src = __DIR__ . '/..';
    $read = static fn (string $path): string => (string) @file_get_contents($src . '/' . $path);
    // 説明の文章に当たらないよう、コメントを外してから探す(security-review と同じ)
    $code = static fn (string $text): string => (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $text);

    km_check_heading('hardening C: 例外の文面を画面に出さない(server-ops 6)');
    require_once __DIR__ . '/../lib/user-error.php';
    // error_log は CLI だと標準エラーへ出て、この表の並びを崩す。一時ファイルへ向けて中身も確かめる
    $logFile = (string) tempnam(sys_get_temp_dir(), 'km-check-log');
    $previousLog = ini_set('error_log', $logFile);
    check('KmUserError の文はそのまま出す', 'コードが違います', km_user_error_message(new KmUserError('コードが違います'), '処理できませんでした'));
    $hidden = km_user_error_message(new RuntimeException('SQLSTATE[42S02] km_secret_table'), '処理できませんでした');
    check_bool('それ以外は文面を出さない', !str_contains($hidden, 'SQLSTATE') && !str_contains($hidden, 'km_secret_table'), $hidden);
    $hasId = preg_match('/照合用 ID: ([0-9a-f]{8})/u', $hidden, $idMatch) === 1;
    check_bool('代わりに照合用 ID を付ける', $hasId && str_starts_with($hidden, '処理できませんでした'), $hidden);
    $logged = (string) @file_get_contents($logFile);
    check_bool('詳細は同じ ID でログにだけ残す', $hasId && str_contains($logged, '[' . $idMatch[1] . ']') && str_contains($logged, 'km_secret_table'));
    check_bool('ID は毎回変わる', $hidden !== km_user_error_message(new RuntimeException('x'), '処理できませんでした'));
    check('管理画面: 入力の検証で断った文は見せる', '日付の形が違います', km_admin_error_message(new InvalidArgumentException('日付の形が違います'), '保存できませんでした'));
    check_bool('管理画面: DB の失敗は ID だけ', !str_contains(km_admin_error_message(new PDOException('SQLSTATE[HY000] secret'), '保存できませんでした'), 'SQLSTATE'));
    check_bool('KmUserError は RuntimeException の final な子', is_subclass_of(KmUserError::class, RuntimeException::class) && (new ReflectionClass(KmUserError::class))->isFinal());
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    @unlink($logFile);
    $contact = $code($read('contact.php'));
    check_bool('contact.php は想定外の例外を km_user_error_message で包む', str_contains($contact, 'km_user_error_message('));
    // 入力検証(InvalidArgumentException)の文は出してよい。Throwable の catch の中でだけ getMessage を $errors に入れない
    check_bool(
        'contact.php は Throwable の文面を $errors に入れない',
        preg_match('/catch \(Throwable \$\w+\)\s*\{(?:(?!catch \().)*?\$errors\[\]\s*=\s*\$\w+->getMessage\(\)/s', $contact) === 0
    );

    km_check_heading('hardening C: サインアウト・書き込みの権限・画面の書き方');
    $signOut = $code($read('sign-out.php'));
    check_bool('サインアウトは POST と CSRF が通ったときだけ(server-authz 3)', str_contains($signOut, "!== 'POST' || !km_csrf_verify()"));
    // 匿名のセッションでもトークンは取れるので、記録すると監査ログを埋められる
    $authAt = strpos($signOut, 'isAuthenticated()');
    $logoutAt = strpos($signOut, "km_admin_log_record('auth', 'logout')");
    check_bool('logout はサインイン中の人だけ記録する', $authAt !== false && $logoutAt !== false && $authAt < $logoutAt && str_contains($signOut, 'if ($signingOutUser)'));
    // フォーム送信のあとの転送には CSP の form-action が掛かる。Logto(:3001)は別オリジンなので、302 だと
    // ブラウザが止めて「押しても画面がそのまま」になっていた(2026-09-17)。ページを返して meta refresh で移る
    check_bool('サインアウトは Logto へ 302 で飛ばさず、ページから移る(form-action で止まらない)', !str_contains($signOut, "header('Location: ' . \$location") && str_contains($signOut, '<meta http-equiv="refresh" content="0;url=<?= $locationHtml ?>">'));
    check_bool('管理者ログインの入口は管理画面のオリジンで始める(公開側でサインインし直さない)', str_contains($code($read('index.php')), 'km_site_admin_origin()) ?>/sign-in.php?mode=signIn&return=<?= km_home_e(rawurlencode(\'/admin/\'))'));
    // nginx の設定は src/ の外にある。**web コンテナの中(配備の後片付け)には無いので飛ばす** ——
    // 付け忘れて、配備のたびにここだけ FAIL になった(2026-09-17)
    $hideRepoRoot = km_check_repo_root();
    if ($hideRepoRoot === null) {
        check_skip('管理ポートは許可していない送信元に 403 を見せず接続を切る', '手元の作業ツリーで確認する。配備先に nginx/ は出ない');
    } else {
        $gateLocation = (string) @file_get_contents($hideRepoRoot . '/nginx/km/gate-location.conf');
        $nginxTemplate = (string) @file_get_contents($hideRepoRoot . '/nginx/default.conf.template');
        check_bool('管理ポートは許可していない送信元に 403 を見せず接続を切る', str_contains($gateLocation, "location @km_hide {\n    return 444;") && substr_count($nginxTemplate, 'error_page 403 = @km_hide;') === 3);
    }
    check('書き込みの scope は admin:users:write', 'admin:users:write', (static function () use ($read): ?string {
        return preg_match("/const KM_ADMIN_WRITE_SCOPE = '([^']+)'/", $read('admin/_inc/bootstrap.php'), $m) === 1 ? $m[1] : null;
    })());
    $guard = $code($read('admin/_inc/guard.php'));
    check_bool('guard は状態を変える要求に write を求める(server-authz 2)', str_contains($guard, 'KM_ADMIN_WRITE_SCOPE') && str_contains($guard, "!defined('KM_ADMIN_POST_WITHOUT_WRITE')"));
    check_bool('gate は read と write の両方を求める', str_contains($code($read('admin/api/gate.php')), '!in_array(KM_ADMIN_SCOPE, $scopes, true) || !in_array(KM_ADMIN_WRITE_SCOPE, $scopes, true)'));
    // 例外を増やすと、read だけのロールで書ける口が黙って増える
    $exempt = [];
    foreach (glob($src . '/admin/api/*.php') ?: [] as $apiFile) {
        if (str_contains($code((string) file_get_contents($apiFile)), "define('KM_ADMIN_POST_WITHOUT_WRITE'")) {
            $exempt[] = basename($apiFile);
        }
    }
    check('write を求めない POST は chat-auth.php だけ', ['chat-auth.php'], $exempt);
    /*
     * 管理画面の CSP は script-src 'self' 'nonce-…' だけ。**インラインの on…= と nonce 無しの <script> は
     * 黙って動かない**(map-publish.php の確認ダイアログが本番でそうなっていた)。
     * PHP のコメント(`<?php // … ?>` を含む)に書いた「<script>」は数えない。
     */
    $inline = [];
    $inlineTargets = array_merge(
        glob($src . '/*.php') ?: [],
        glob($src . '/admin/*.php') ?: [],
        glob($src . '/admin/_inc/*.php') ?: [],
        glob($src . '/admin/_inc/partials/*.php') ?: []
    );
    foreach ($inlineTargets as $inlineFile) {
        $text = (string) preg_replace(['#<\?php\s*//[^\n]*?\?>#', '#<\?php\s*/\*.*?\*/\s*\?>#s'], '', (string) file_get_contents($inlineFile));
        $text = $code($text);
        if (preg_match('/\son[a-z]+\s*=\s*["\']/i', $text) === 1) {
            $inline[] = basename($inlineFile) . ' (on…=)';
        }
        // `nonce` の前に \b を置かない —— `km_csp_nonce_attr` は `_` の後ろなので語の境目にならない
        if (preg_match('/<script\b(?![^>]*nonce)(?![^>]*\bsrc=)[^>]*>/i', $text) === 1) {
            $inline[] = basename($inlineFile) . ' (nonce 無しの <script>)';
        }
    }
    check('インラインの on…= と nonce 無しの <script> が無い', [], $inline);
    check_bool('<script> の中の json_encode は HEX で逃がす(server-injection 1)', str_contains($read('admin/_inc/partials/head.php'), 'JSON_HEX_TAG') && str_contains($read('admin/monitor.php'), 'JSON_HEX_TAG'));
    // Leaflet のツールチップは HTML として描く。ノード名を素で渡すと注入になる(server-injection 0)
    check_bool('地図のツールチップに名前を素で渡さない', preg_match('/bindTooltip\((?:node|poi)\.name\b/', $read('Main/app.js')) === 0);
    check_bool('セッションは発行した ID しか受け付けない(critic 3)', str_contains($code($read('lib/session.php')), "ini_set('session.use_strict_mode', '1')"));
    require_once __DIR__ . '/../lib/recaptcha.php';
    check_bool('reCAPTCHA はホスト名を照合する(critic 2)', function_exists('km_recaptcha_hostname_allowed') && !km_recaptcha_hostname_allowed(''));

    km_check_heading('hardening B: 待ち時間と本文の上限(PHP)');
    $db = $code($read('lib/db.php'));
    check_bool('DB の接続待ちは 5 秒', str_contains($db, 'PDO::ATTR_TIMEOUT => 5'));
    // グローバルに掛けると mysqldump のバックアップが止まる。Web の接続にだけ
    check_bool('Web のときだけ文の実行時間を絞る', preg_match("/if \(PHP_SAPI === 'cli'\) \{\s*return;\s*\}\s*try \{\s*\\\$pdo->exec\('SET SESSION max_statement_time = '/", $db) === 1);
    check_bool('公開の JSON API は本文の上限付きで読む', function_exists('km_api_json_body') || str_contains($read('api_bootstrap.php'), 'function km_api_json_body('));
    foreach (['api/app-stats.php', 'api/app-ranking.php', 'api/app-avatar.php', 'api/app-settings.php'] as $jsonApi) {
        check_bool("{$jsonApi} は km_api_json_body で読む", str_contains($code($read($jsonApi)), 'km_api_json_body('));
    }
    // Content-Length の無い chunked の本文は、見出しだけ見ても上限にならない
    check_bool('webhook は php://input を上限+1 バイトまでしか読まない', str_contains($code($read('api/logto-webhook.php')), "file_get_contents('php://input', false, null, 0, KM_WEBHOOK_MAX_BYTES + 1)"));
    check_bool('app-map も本文を上限付きで読む', str_contains($code($read('api/app-map.php')), "file_get_contents('php://input', false, null, 0, KM_APP_MAP_REQUEST_MAX_BYTES + 1)"));

    km_check_heading('hardening D: 配信 ID ごとの版の比較');
    require_once __DIR__ . '/../lib/app-map-publish.php';
    require_once __DIR__ . '/../lib/map-rate-limit.php';
    check('配信 ID は kosen-main と kosen-event', ['kosen-main', 'kosen-event'], KM_APP_MAP_SLUGS);
    check_bool('同じ ID・同じ版は「最新です」', km_app_map_is_up_to_date('kosen-event', 'kosen-event', 3, 3));
    // 版は配信 ID ごとに数える。別の ID の版と比べると、付け替えたのに地図が来ない
    check_bool('違う ID なら版に関係なく送る', !km_app_map_is_up_to_date('kosen-event', 'kosen-main', 99, 3));
    check_bool('ID を送らない古いアプリ・kosen-main は従来どおり版を見る', km_app_map_is_up_to_date('kosen-main', null, 5, 5));
    check_bool('ID を送らない古いアプリ・kosen-event は必ず送る', !km_app_map_is_up_to_date('kosen-event', null, 5, 1));
    check_bool('版を持っていなければ送る', !km_app_map_is_up_to_date('kosen-main', 'kosen-main', null, 1));
    $appMapApi = $code($read('api/app-map.php'));
    check_bool('api は haveMapId を渡して比べる', str_contains($appMapApi, 'km_app_map_is_up_to_date($slug, $haveMapId, $haveRevision, $revision, $haveLevel, $contentLevel)'));

    km_check_heading('hardening D: アクセスコードの照合(lookup と、配信先の無いコード)');
    $secret = str_repeat('s', 32);
    $legacyCode = 'ABCD2345';
    // 本番の形: maps は kosen-main だけ、codes は slug=TEST1 の bcrypt だけ(lookup も平文の控えも無い)
    $prod = [
        'maps' => ['kosen-main' => ['file' => 'app-map-kosen-main.json', 'revision' => 1]],
        'codes' => [['slug' => 'TEST1', 'hash' => password_hash($legacyCode, PASSWORD_BCRYPT, ['cost' => 4])]],
    ];
    $orphans = km_app_map_orphan_codes($prod);
    check('配信先の無いコードを見つける', ['TEST1'], array_column($orphans, 'slug'));
    check_bool('平文の控えが無ければ plain は null、lookup も無い', array_key_exists('plain', $orphans[0] ?? []) && $orphans[0]['plain'] === null && ($orphans[0]['hasLookup'] ?? true) === false);
    check('コードの無い配信は「配信先の無いコード」ではない', [], km_app_map_orphan_codes(['maps' => $prod['maps'], 'codes' => []]));
    $lookup = km_app_map_code_lookup($secret, km_app_map_normalize_code($legacyCode));
    check('古いコードは lookup では当たらない', null, km_app_map_find_code_by_lookup($prod, $lookup));
    $legacy = km_app_map_find_code_by_hash($legacyCode, $prod, $lookup['keyId'], KM_APP_MAP_LEGACY_VERIFY_LIMIT);
    check('古いコードは bcrypt で当たる', 'TEST1', $legacy['slug'] ?? null);
    $ref = (string) ($orphans[0]['ref'] ?? '');
    $moved = km_app_map_reassign_code($prod, 0, KM_APP_MAP_MAIN_SLUG);
    check('付け替えると配信先の無いコードから消える', [], km_app_map_orphan_codes($moved));
    // 印刷した QR を変えずに行き先だけ直す。印は hash から作るので付け替えでも変わらない
    check_bool('付け替えても hash と画面の印は同じ', $moved['codes'][0]['hash'] === $prod['codes'][0]['hash'] && km_app_map_code_at($moved, 0, $ref) !== null);
    check_bool('印が違えば別のコードとして扱う', km_app_map_code_at($moved, 0, str_repeat('0', 16)) === null);
    $rejected = false;
    try {
        km_app_map_reassign_code($prod, 0, 'TEST2');
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    check_bool('付け替え先は kosen-main / kosen-event だけ', $rejected);
    $remembered = km_app_map_remember_lookup($moved, (string) ($legacy['hash'] ?? ''), $lookup);
    check('lookup を書き足すと次から lookup で当たる', 'kosen-main', km_app_map_find_code_by_lookup($remembered, $lookup)['slug'] ?? null);
    check('lookup を持つコードは bcrypt を回さない', null, km_app_map_find_code_by_hash($legacyCode, $remembered, $lookup['keyId']));
    // 鍵が作り直されたとき、黙って誰も入れなくなるのを避ける
    check('鍵が変わったら bcrypt に戻る', 'kosen-main', km_app_map_find_code_by_hash($legacyCode, $remembered, 'otherkey')['slug'] ?? null);
    check('違うコードの lookup は当たらない', null, km_app_map_find_code_by_lookup($remembered, km_app_map_code_lookup($secret, 'ZZZZ9999')));
    $many = ['maps' => [], 'codes' => []];
    for ($i = 0; $i < KM_APP_MAP_LEGACY_VERIFY_LIMIT + 2; $i++) {
        $many['codes'][] = ['slug' => 'kosen-main', 'hash' => password_hash('C' . $i, PASSWORD_BCRYPT, ['cost' => 4])];
    }
    $previousLog = ini_set('error_log', $logFile = (string) tempnam(sys_get_temp_dir(), 'km-check-log'));
    check('1回の要求で回す bcrypt に上限がある(上限の内側は当たる)', KM_APP_MAP_LEGACY_VERIFY_LIMIT - 1, km_app_map_find_code_by_hash('C' . (KM_APP_MAP_LEGACY_VERIFY_LIMIT - 1), $many, 'k', KM_APP_MAP_LEGACY_VERIFY_LIMIT)['index'] ?? null);
    check('上限の外側は照合しない', null, km_app_map_find_code_by_hash('C' . (KM_APP_MAP_LEGACY_VERIFY_LIMIT + 1), $many, 'k', KM_APP_MAP_LEGACY_VERIFY_LIMIT));
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    @unlink($logFile);
    $added = km_app_map_add_code(['maps' => [], 'codes' => []], KM_APP_MAP_EVENT_SLUG, $secret);
    check('作ったコードは lookup で引ける', 'kosen-event', km_app_map_find_code_by_lookup($added['config'], km_app_map_code_lookup($secret, km_app_map_normalize_code($added['code'])))['slug'] ?? null);
    // 安い lookup で当たらなかったときだけ、数えてから bcrypt へ回す
    $lookupAt = strpos($appMapApi, 'km_app_map_find_code_by_lookup(');
    $attemptAt = strpos($appMapApi, "km_map_unlock_attempt(\$pdo, 'app')");
    $hashAt = strpos($appMapApi, 'km_app_map_find_code_by_hash(');
    check_bool('api は lookup → 数える → bcrypt(上限付き)の順', $lookupAt !== false && $attemptAt !== false && $hashAt !== false && $lookupAt < $attemptAt && $attemptAt < $hashAt && str_contains($appMapApi, 'KM_APP_MAP_LEGACY_VERIFY_LIMIT'));

    km_check_heading('hardening D: イベント用の正本の添付');
    $uploadRejects = static function (string $raw): bool {
        try {
            km_app_map_decode_event_upload($raw);
        } catch (KmUserError) {
            return true;
        }

        return false;
    };
    check_bool('空は断る', $uploadRejects(''));
    check_bool('JSON でなければ断る', $uploadRejects('nope'));
    check_bool('地点 0 件は断る', $uploadRejects('{"version":10,"nodes":[],"lines":[]}'));
    // 型の違う項目が混じったファイルを配ると、そのコードの全端末が読み込みに失敗する
    check_bool('地点がオブジェクトでなければ断る', $uploadRejects('{"version":10,"nodes":[1],"lines":[]}'));
    check_bool('封筒付きの書き出しは受ける', !$uploadRejects('{"format":"kosenmap-map","map":{"version":10,"nodes":[{"uuid":"n"}],"lines":[]}}'));

    km_check_heading('hardening D: 失敗ロック(先に数える・app は 30 回)');
    check('web の上限は 8 回のまま', 8, km_map_rate_limit_limit('web'));
    // 学校の共有回線。8 回だと1人の打ち間違いで全来場者が 15 分止まる(android-data 0)
    check('app の上限は 30 回', 30, km_map_rate_limit_limit('app'));
    $now = 1_800_000_000;
    $state = ['count' => 0, 'first' => 0, 'last' => 0, 'lockedUntil' => 0];
    for ($i = 0; $i < 29; $i++) {
        $state = km_map_rate_limit_next_state($state, $now, 30);
    }
    check('app: 29 回目まではロックしない', 0, $state['lockedUntil']);
    $state = km_map_rate_limit_next_state($state, $now, 30);
    check('app: 30 回目でロックが立つ', $now + KM_MAP_UNLOCK_LOCK_SECONDS, $state['lockedUntil']);
    $locked = km_map_rate_limit_next_state($state, $now + 10, 30);
    check_bool('ロック中の試行は数えるが、期限は延ばさない', $locked['count'] === 31 && $locked['lockedUntil'] === $state['lockedUntil']);
    $expired = km_map_rate_limit_next_state($locked, $now + KM_MAP_UNLOCK_LOCK_SECONDS + 1, 30);
    check_bool('ロックが切れたら 1 から数え直す', $expired['count'] === 1 && $expired['lockedUntil'] === 0);
    check('IPv6 は /64 で数える', '2001:db8:1:2::', km_map_rate_limit_key('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
    check('IPv4 射影は IPv4 として数える', '203.0.113.7', km_map_rate_limit_key('::ffff:203.0.113.7'));

    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('compose・nginx・docker・ホスト側スクリプト', '手元の作業ツリーで確認する。配備先に scripts/ は出ない');

        return;
    }
    $file = static fn (string $path): string => (string) @file_get_contents($repoRoot . '/' . $path);
    $yamlCode = static fn (string $text): string => (string) preg_replace('/^\s*#.*$/m', '', $text);
    // services: の直下を、サービス名 → 中身(コメントを除く)に分ける
    $services = static function (string $yaml): array {
        $blocks = [];
        $current = null;
        $inServices = false;
        foreach (preg_split('/\r\n|\r|\n/', $yaml) ?: [] as $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            if (preg_match('/^\S/', $line) === 1) {
                $inServices = rtrim($line) === 'services:';
                $current = null;
                continue;
            }
            if ($inServices && preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $m) === 1) {
                $current = $m[1];
                $blocks[$current] = '';
                continue;
            }
            if ($inServices && $current !== null) {
                $blocks[$current] .= $line . "\n";
            }
        }

        return $blocks;
    };
    $base = $file('compose.yaml');
    $vps = $file('compose.vps.yaml');
    $baseServices = $services($base);
    $vpsServices = $services($vps);
    $vpsCode = $yamlCode($vps);

    km_check_heading('hardening A: VPS の URL 系は KM_DOMAIN から導く');
    // .env の書き忘れで別ドメインのホストが本番の名前を名乗らないよう、KM_DOMAIN だけは既定を持たない
    check_bool('KM_DOMAIN が無ければ config の時点で止まる', str_contains($vpsCode, 'KM_DOMAIN: "${KM_DOMAIN:?'));
    $derived = [
        'KM_APP_URL' => 'KM_APP_URL: ${KM_APP_URL:-https://${KM_DOMAIN}}',
        'KM_APP_PORT' => 'KM_APP_PORT: ${KM_APP_PORT:-443}',
        'KM_CERT' => 'KM_CERT: ${KM_CERT:-/etc/letsencrypt/live/${KM_DOMAIN}/fullchain.pem}',
        'KM_CERT_KEY' => 'KM_CERT_KEY: ${KM_CERT_KEY:-/etc/letsencrypt/live/${KM_DOMAIN}/privkey.pem}',
        'APP_URL' => 'APP_URL: ${APP_URL:-https://${KM_DOMAIN}}',
        // 2026-10-19 から 443 番の auth.<ドメイン>(.env の KM_AUTH_DOMAIN で名前を変えられる。:3001 は外した)
        'LOGTO_ENDPOINT' => 'LOGTO_ENDPOINT: ${LOGTO_ENDPOINT:-https://${KM_AUTH_DOMAIN:-auth.${KM_DOMAIN}}}',
        // Console と phpMyAdmin は管理用のホスト名(無ければ KM_DOMAIN)。ゲートの Cookie がホスト名ごとのため(2026-09-15)
        'LOGTO_ADMIN_ENDPOINT' => 'ADMIN_ENDPOINT: ${LOGTO_ADMIN_ENDPOINT:-https://${KM_ADMIN_DOMAIN:-${KM_DOMAIN}}:3002}',
        'PMA_ABSOLUTE_URI' => 'PMA_ABSOLUTE_URI: ${PMA_ABSOLUTE_URI:-https://${KM_ADMIN_DOMAIN:-${KM_DOMAIN}}:8281/}',
        'MAIL_HOST' => 'MAIL_HOST: ${MAIL_HOST:-mailserver}',
        'MAIL_PORT' => 'MAIL_PORT: ${MAIL_PORT:-587}',
        'MAIL_FROM' => 'MAIL_FROM: ${MAIL_FROM:-noreply@${KM_DOMAIN}}',
        'ALLOWED_SENDER_DOMAINS' => 'ALLOWED_SENDER_DOMAINS: ${KM_DOMAIN}',
        'POSTFIX_myhostname' => 'POSTFIX_myhostname: mail.${KM_DOMAIN}',
    ];
    $notDerived = [];
    foreach ($derived as $name => $needle) {
        if (!str_contains($vpsCode, $needle)) {
            $notDerived[] = $name;
        }
    }
    check('URL 系はすべて KM_DOMAIN から導く(.env で上書き可)', [], $notDerived);
    // **2 行が 1 行につながっていないか**(2026-09-17。行を消したときに改行まで消し、`…}    ports:` になった。
    // この検査は YAML を読まないので素通りし、本番で docker compose が `mapping values are not allowed` で全部止まった)
    $joinedLines = [];
    foreach (['compose.yaml' => $base, 'compose.vps.yaml' => $vps, 'compose.local.yaml' => $file('compose.local.yaml')] as $composeName => $composeText) {
        foreach (preg_split('/\R/u', $composeText) ?: [] as $no => $composeLine) {
            if (preg_match('/^\s*[A-Za-z_][\w.-]*:\s.*\S\s{2,}(#|[A-Za-z_][\w.-]*:(\s|$))/', $composeLine) === 1) {
                $joinedLines[] = $composeName . ':' . ($no + 1);
            }
        }
    }
    check('compose の 1 行に 2 つの項目がつながっていない', [], $joinedLines);
    check_bool('compose.vps.yaml に本番のドメインを直に書かない', !str_contains($vpsCode, 'ito8795.com') && !str_contains($vpsCode, 'ito4.jp'));
    /*
     * API リソース(= トークンの audience)は**ドメインを変えても変えない値。** 変わると管理画面にも
     * Android の管理版にも入れなくなる。PHP は LOGTO_API_RESOURCE と KOSENMAP_LOGTO_AUDIENCE の両方を読む。
     */
    $vpsWeb = $vpsServices['web'] ?? '';
    check_bool('VPS: web に KM_API_RESOURCE を2つの名前で渡す', str_contains($vpsWeb, 'LOGTO_API_RESOURCE: ${KM_API_RESOURCE:-https://${KM_DOMAIN}/api}') && str_contains($vpsWeb, 'KOSENMAP_LOGTO_AUDIENCE: ${KM_API_RESOURCE:-https://${KM_DOMAIN}/api}'));
    $baseWeb = $baseServices['web'] ?? '';
    check_bool('LAN: 既定は 192.168.3.29:9443/api のまま', str_contains($baseWeb, 'LOGTO_API_RESOURCE: ${KM_API_RESOURCE:-https://192.168.3.29:9443/api}') && str_contains($baseWeb, 'KOSENMAP_LOGTO_AUDIENCE: ${KM_API_RESOURCE:-https://192.168.3.29:9443/api}'));
    check_bool('.env.example に KM_API_RESOURCE の説明がある', str_contains($file('.env.example'), 'KM_API_RESOURCE'));

    km_check_heading('hardening B・C: compose(渡す env・マウント・上限)');
    // PHP は Postgres を使わない。しかもその利用者は SUPERUSER(server-ops 0)
    check_bool('web に POSTGRES_* を渡さない', $baseWeb !== '' && !str_contains($baseWeb, 'POSTGRES_'));
    // lib が getenv する名前は、compose に書くまで効かない(server-ops 1)
    check_bool('web に LOGTO_WEBHOOK_SIGNING_KEY を渡す', str_contains($baseWeb, 'LOGTO_WEBHOOK_SIGNING_KEY: ${LOGTO_WEBHOOK_SIGNING_KEY'));
    // VPS の web の volumes は `!override`。基底に足しただけでは本番に届かない
    foreach (['compose.yaml' => $baseWeb, 'compose.vps.yaml' => $vpsWeb] as $composeName => $webBlock) {
        check_bool("{$composeName}: web に 99-limits.ini をマウント", str_contains($webBlock, 'source: ./docker/php/99-limits.ini') && str_contains($webBlock, 'target: /usr/local/etc/php/conf.d/99-limits.ini'));
        check_bool("{$composeName}: web に apache-km.conf を conf-enabled へマウント", preg_match('#source: \./docker/php/apache-km\.conf\s+target: /etc/apache2/conf-enabled/km\.conf#', $webBlock) === 1);
    }
    $apache = $yamlCode($file('docker/php/apache-km.conf'));
    check_bool('Apache は PATH_INFO を受け付けない(server-authz 0)', preg_match('/^AcceptPathInfo Off$/m', $apache) === 1);
    check_bool('Apache の Timeout は 60', preg_match('/^Timeout 60$/m', $apache) === 1);
    $limitsIni = (string) preg_replace('/^\s*;.*$/m', '', $file('docker/php/99-limits.ini'));
    $iniMissing = [];
    foreach (['max_execution_time = 30', 'default_socket_timeout = 15', 'session.use_strict_mode = 1', 'session.use_only_cookies = 1', 'session.use_trans_sid = 0', 'session.upload_progress.enabled = 0'] as $iniLine) {
        if (preg_match('/^' . preg_quote($iniLine, '/') . '$/m', $limitsIni) !== 1) {
            $iniMissing[] = $iniLine;
        }
    }
    check('99-limits.ini の値', [], $iniMissing);
    /*
     * セッションを残す長さ(2026-10-25、利用者「posters.php へ行って ito4.jp へ戻ると、ログインが切れているときがある」)。
     * 組み込み既定の 24 分で掃除され、Logto のサインインが有効なままログインが切れた。ini と lib を同じ値に
     * (掃除は /tmp のセッション全部を見るので、短い側がほかの人のセッションまで消す)
     */
    require_once __DIR__ . '/../lib/session.php';
    /*
     * 戻るボタン(2026-10-25、利用者「posters.php からブラウザの戻るボタンで戻ると、ログインが切れている(ログインボタンですぐ入れる)」)。
     * ブラウザがメモリから戻した画面(bfcache)が、サインイン前に描いたものだった。メモリから戻ったときだけ聞き、違えば読み直す
     */
    $stateApi = (string) $file('src/api/session-state.php');
    $backJs = (string) $file('src/Main/back-refresh.js');
    $homeForBack = (string) $file('src/index.php');
    check_bool('戻るボタン: 描いたときの状態の印・メモリから戻ったときだけ聞いて違えば読み直す', str_contains($homeForBack, '<meta name="km-logged-in" content="<?= $loggedIn ? \'1\' : \'0\' ?>">')
        && str_contains($homeForBack, "km_public_asset('Main/back-refresh.js')")
        && str_contains($backJs, 'if (!event.persisted) return;') && str_contains($backJs, 'data.loggedIn !== shown') && str_contains($backJs, 'window.location.reload();'));
    check_bool('戻るボタン: 状態の口は Logto に問い合わせない・Cookie の無い人にセッションを作らない・真偽だけ返す', !str_contains($stateApi, 'logto-client.php') && !str_contains($stateApi, 'new KmLogtoClient')
        && strpos($stateApi, 'if (!km_session_cookie_present()) {') < strpos($stateApi, 'km_session_start();')
        && str_contains($stateApi, "respond(['success' => true, 'loggedIn' => is_string(\$idToken) && \$idToken !== '']);"));
    check_bool('セッションは 12 時間残す(99-limits.ini と lib/session.php が同じ値・開始より前に決める)', KM_SESSION_LIFETIME_SECONDS === 43200
        && preg_match('/^session\.gc_maxlifetime = ' . KM_SESSION_LIFETIME_SECONDS . '$/m', $limitsIni) === 1
        && strpos((string) $file('src/lib/session.php'), "ini_set('session.gc_maxlifetime', (string) KM_SESSION_LIFETIME_SECONDS);")
            < strpos((string) $file('src/lib/session.php'), "    session_start();"));
    check_bool('99-limits.ini は memory_limit を変えない', !str_contains($limitsIni, 'memory_limit'));
    // 配備でログインを消さない(2026-10-29、振り返りのフェーズ 0)。セッションは /tmp(tmpfs)から名前付きのボリュームへ
    $baseCompose = (string) $file('compose.yaml');
    $vpsCompose = (string) $file('compose.vps.yaml');
    $webStart = (string) $file('docker/php/start-with-local-ca.sh');
    check_bool('セッションの置き場は名前付きのボリューム(lib・compose・本番の上書きの 3 か所が同じ)', KM_SESSION_DIR === '/var/lib/km-sessions'
        && str_contains($baseCompose, '      - web_sessions:/var/lib/km-sessions') && str_contains($vpsCompose, '      - web_sessions:/var/lib/km-sessions')
        && preg_match('/^  web_sessions:\n    name: km_web_sessions$/m', $baseCompose) === 1);
    // 同じ日: ini で決め打ちにすると、ボリュームの無いまま ini だけ届いたとき全員がログインできない。在って書けるときだけ使う
    $sessionTmp = sys_get_temp_dir() . '/km-session-check-' . bin2hex(random_bytes(4));
    @mkdir($sessionTmp);
    check('セッションの置き場: 在って書けるときだけ使い、無ければ既定(/tmp)のまま', [$sessionTmp, null],
        [km_session_save_path($sessionTmp), km_session_save_path($sessionTmp . '/missing')]);
    @rmdir($sessionTmp);
    check_bool('セッションの置き場: ini では決め打ちにしない・開始の前に向ける', !preg_match('/^session\.save_path\s*=/m', $limitsIni)
        && strpos((string) $file('src/lib/session.php'), "ini_set('session.save_path', \$savePath);") < strpos((string) $file('src/lib/session.php'), "    session_start();"));
    check_bool('セッションの置き場は起動のとき www-data・0700 にしてから Apache を起こす', ($own = strpos($webStart, 'chown www-data:www-data "$SESSION_DIR"')) !== false
        && str_contains($webStart, 'chmod 0700 "$SESSION_DIR"') && $own < strpos($webStart, 'exec apache2-foreground'));
    /*
     * 上限はコンテナ全部に。mailserver にだけ no-new-privileges を付けない
     * (postfix の postdrop が setgid で動くので、付けると送信が止まる)。
     */
    $unlimited = [];
    $mayEscalate = [];
    $limitTargets = $baseServices + array_intersect_key($vpsServices, ['certbot' => true, 'mailserver' => true]);
    foreach ($limitTargets as $serviceName => $block) {
        if (preg_match('/^    mem_limit: \d+m$/m', $block) !== 1 || preg_match('/^    pids_limit: \d+$/m', $block) !== 1) {
            $unlimited[] = $serviceName;
        }
        if ($serviceName !== 'mailserver' && !str_contains($block, 'no-new-privileges:true')) {
            $mayEscalate[] = $serviceName;
        }
    }
    check('全サービスに mem_limit と pids_limit がある', [], $unlimited);
    check('mailserver 以外に no-new-privileges がある', [], $mayEscalate);
    check_bool('mailserver には no-new-privileges を付けない', !str_contains($vpsServices['mailserver'] ?? '', 'no-new-privileges'));
    check_bool('MariaDB は LOAD DATA LOCAL を受け付けない', str_contains($baseServices['mariadb'] ?? '', '--local-infile=0'));
    check_bool('MariaDB にグローバルの max_statement_time を掛けない(mysqldump が止まる)', !str_contains($baseServices['mariadb'] ?? '', 'max_statement_time'));
    check_bool('Soketi はクライアントイベントを受けない', str_contains($baseServices['soketi'] ?? '', 'SOKETI_DEFAULT_APP_ENABLE_CLIENT_MESSAGES: "false"') && str_contains($baseServices['soketi'] ?? '', 'SOKETI_DEFAULT_APP_MAX_CONNS:'));
    check_bool('Soketi は root で動かない', preg_match('/^USER node$/m', $file('docker/soketi/Dockerfile')) === 1);
    // 本番の送信は mailserver。無認証の画面を持つ Mailpit を起動しない(server-ops 11)
    check_bool('VPS: Mailpit は profile の内側', str_contains($vpsServices['mailpit'] ?? '', 'profiles: ["mailpit"]'));
    $vpsProxy = $vpsServices['reverse-proxy'] ?? '';
    check_bool('VPS: nginx は Mailpit を待たない', str_contains($vpsProxy, 'depends_on: !override') && !str_contains($vpsProxy, 'mailpit:'));
    // [::] で待つと docker-proxy が送信元を 172.x に化けさせ、allow-admin.conf を素通りする(server-ops 2)
    $adminPorts = [];
    // 8025(Mailpit)は 2026-09-18 に本番から外した。残る 2 つだけを見る
    foreach (['8281', '3002'] as $port) {
        if (!str_contains($vpsProxy, "\"0.0.0.0:{$port}:{$port}\"")) {
            $adminPorts[] = $port;
        }
    }
    check('VPS: 管理系ポートは 0.0.0.0 だけで待つ', [], $adminPorts);
    check_bool('phpMyAdmin は root を既定で断る(server-ops 12)', str_contains($file('docker/phpmyadmin/config.user.inc.php'), "KM_PMA_ALLOW_ROOT") && str_contains($file('docker/phpmyadmin/config.user.inc.php'), "'AllowNoPassword'] = false"));
    // 環境保管領域(2026-10-19)。パスワードがあるときだけ有効。無いまま controluser を書くと空のパスワードで繋ぎに行く
    $pmaConfig = $file('docker/phpmyadmin/config.user.inc.php');
    check_bool('phpMyAdmin の環境保管領域は KM_PMA_CONTROLPASS があるときだけ・表は 19 個', str_contains($pmaConfig, "if (\$kmPmaControlPass !== '' && isset(\$cfg['Servers'])")
        && str_contains($pmaConfig, "['pmadb'] = 'phpmyadmin';") && substr_count($pmaConfig, "=> 'pma__") === 19);
    // 2026-10-19: 「機能」の画面でログイン Cookie の有効期間が空の文字列で保存され、ログイン後の全画面が TypeError になった
    check_bool('phpMyAdmin のログインの有効期間は利用者ごとの設定で変えさせない(UserprefsDisallow)', str_contains($pmaConfig, '$cfg[\'UserprefsDisallow\'] = [\'LoginCookieValidity\'];'));
    check_bool('compose は制御用のパスワードを .env から渡すだけ(既定は空)', str_contains($file('compose.yaml'), 'KM_PMA_CONTROLPASS: ${KM_PMA_CONTROLPASS:-}')
        && preg_match('/^KM_PMA_CONTROLPASS=$/m', $file('.env.example')) === 1);
    $pmaCell = $file('docs/05-containers.ipynb');
    check_bool('制御用の利用者は phpmyadmin.* だけ・TLS 必須・パスワードは引数に載せない(docs/05 §1-2)', str_contains($pmaCell, 'IDENTIFIED BY \'$PASS\' REQUIRE SSL;')
        && str_contains($pmaCell, "GRANT SELECT, INSERT, UPDATE, DELETE ON phpmyadmin.* TO 'pma'@'%';") && !str_contains($pmaCell, 'mariadb -uroot -p')
        && !str_contains($pmaCell, 'echo "$PASS"'));

    km_check_heading('hardening B・C: nginx(同時接続・待ち時間・転送ヘッダー)');
    $nginx = (string) preg_replace('/^\s*#.*$/m', '', $file('nginx/default.conf.template'));
    $chunks = preg_split('/^server\s*\{/m', $nginx) ?: [''];
    $httpPart = (string) array_shift($chunks);
    $server = static function (string $listen) use ($chunks): string {
        foreach ($chunks as $chunk) {
            if (str_contains($chunk, "listen {$listen};")) {
                return $chunk;
            }
        }

        return '';
    };
    $httpMissing = [];
    foreach (['client_header_timeout 15s;', 'client_body_timeout 30s;', 'send_timeout 60s;', 'reset_timedout_connection on;', 'proxy_read_timeout 60s;', 'limit_conn_zone $binary_remote_addr zone=km_conn:10m;', 'limit_conn_status 429;'] as $directive) {
        if (!str_contains($httpPart, $directive)) {
            $httpMissing[] = $directive;
        }
    }
    check('http の待ち時間と同時接続のゾーン', [], $httpMissing);
    // 公式イメージの nginx.conf が http に 65 を書いており、並べると "is duplicate" で起動しない
    check_bool('keepalive_timeout を http に書かない', !str_contains($httpPart, 'keepalive_timeout'));
    $connLimits = [];
    foreach (['443 ssl' => 100, '8281 ssl' => 50, '3002 ssl' => 50, '8025 ssl' => 50] as $listen => $limit) {
        if (!str_contains($server($listen), "limit_conn km_conn {$limit};")) {
            $connLimits[] = $listen;
        }
    }
    // サインインの入口(auth.<ドメイン> の 443。2026-10-19 に 3001 から移した)は 443 の 2 つ目の server
    $authChunk = '';
    foreach ($chunks as $chunk) {
        if (str_contains($chunk, 'server_name ${KM_AUTH_DOMAIN};')) {
            $authChunk = $chunk;
        }
    }
    if (!str_contains($authChunk, 'limit_conn km_conn 100;')) {
        $connLimits[] = 'auth';
    }
    check('各 server の limit_conn(443・auth は 100、他は 50)', [], $connLimits);
    // `${KM_APP_PORT}` の `}` で location の中身を読む `[^}]*` が途切れないよう、置換の印を先に潰す
    $https = (string) preg_replace('/\$\{[A-Z_]+\}/', 'VAR', $server('443 ssl'));
    preg_match('/location\s+~\*?\s+(\S+)/', $https, $firstRegex);
    // 正規表現の location は書いた順に評価される。後ろに置くと完全一致の回数制限や include 専用の 404 を素通りする
    check('443 の最初の正規表現は `.php/` の 404', '\.php/', $firstRegex[1] ?? null);
    check_bool('include 専用の 404 は `\.php(/|$)`', str_contains($https, 'logto-client)\.php(/|$) {'));
    check_bool('proxy-web.conf に proxy_read_timeout を書かない(location で重複すると起動しない)', !str_contains((string) preg_replace('/^\s*#.*$/m', '', $file('nginx/km/proxy-web.conf')), 'proxy_read_timeout'));
    // 2 つ目は Soketi の WebSocket(/ws/app/。2026-10-19 に 6001 番から移した。1 本を長く張る)
    check('443 で 300s を許すのは downloads.php と /ws/app/ だけ', 2, substr_count($https, 'proxy_read_timeout 300s;'));
    check_bool('未認証で重い口を km_api で絞る(メソッドを問わない)', str_contains($https, 'location ~ ^/(api/(app-stats|app-avatar|floor-image|download|map-data|route-weights|map-calibration|app-update|positioning-params|learning|poster-image|poster-event|table|node-extras|display-defaults|user-map-data|map-events|poster-like)|logto_me)\.php$ {') && str_contains($https, 'limit_req zone=km_api burst=120 nodelay;'));
    check_bool('アプリの地図は km_appmap で絞る', preg_match('#location = /api/app-map\.php \{\s*limit_req zone=km_appmap burst=30 nodelay;#', $https) === 1);
    check_bool('callback.php は認可コードをログに残さない', preg_match('#location = /callback\.php \{\s*access_log [^;]+ km_no_query;#', $https) === 1);
    /*
     * PHP で set_time_limit を延ばした管理画面は、nginx の上流待ち(既定 60 秒)にも例外が要る。
     * 無いと nginx だけが先に 504 を返し、PHP は裏で書き込み続ける —— 押し直すと同じ処理が二度走る。
     */
    $shortProxy = [];
    foreach (glob($src . '/admin/*.php') ?: [] as $adminFile) {
        if (preg_match('/set_time_limit\((\d+)\)/', $code((string) file_get_contents($adminFile)), $limitMatch) !== 1) {
            continue;
        }
        $name = basename($adminFile);
        $ok = preg_match('#location = /admin/' . preg_quote($name, '#') . ' \{[^}]*proxy_read_timeout (\d+)s;#', $https, $proxyMatch) === 1
            && (int) $proxyMatch[1] > (int) $limitMatch[1];
        if (!$ok) {
            $shortProxy[] = $name;
        }
    }
    check('set_time_limit を延ばした画面は nginx の待ちもそれより長い', [], $shortProxy);
    // 利用者が付けた X-Forwarded-For を足すと、Logto 側で IP を偽れる(server-ops 7)。HSTS は nginx の1本だけ
    foreach (['auth' => $authChunk, '3002' => $server('3002 ssl')] as $port => $logtoServer) {
        check_bool("{$port}: X-Forwarded-For は送信元だけ", str_contains($logtoServer, 'proxy_set_header X-Forwarded-For $remote_addr;') && !str_contains($logtoServer, '$proxy_add_x_forwarded_for'));
        check_bool("{$port}: Logto の HSTS を隠す", str_contains($logtoServer, 'proxy_hide_header Strict-Transport-Security;'));
    }
    // 3001(Logto)と 6001(Soketi)は 2026-10-19 に外した(学校の回線は 443 番以外を通さない。サインインと WebSocket は 443 番へ)。
    // Origin の見張りと /apps/ を出さないことは、443 の /ws/app/ の検査(「443 番へ寄せる」)が見る
    check_bool('3001 と 6001 は待ち受けない・publish しない', !preg_match('/listen (\[::\]:)?(3001|6001) /', $nginx)
        && !preg_match('/"(\d+\.\d+\.\d+\.\d+:)?(3001|6001):(3001|6001)"/', $file('compose.vps.yaml') . $file('compose.yaml')));
    /*
     * `proxy_pass http://logto:3001;` と直に書くと、nginx は起動時の IP を持ち続ける。
     * 相手を作り直すと IP が変わり、居なくなった IP へ送って 502 になる(2026-09-14 に Logto で発生)。
     * 転送先は upstream + resolve(動いている間も引き直す)を通す。Mailpit だけは変数の proxy_pass。
     */
    $directProxy = [];
    foreach (['nginx/default.conf.template' => $nginx, 'nginx/km/proxy-web.conf' => null, 'nginx/km/gate-location.conf' => null] as $confName => $confBody) {
        $confBody ??= (string) preg_replace('/^\s*#.*$/m', '', $file($confName));
        preg_match_all('#proxy_pass\s+https?://([A-Za-z0-9_.-]+)#', $confBody, $passMatches);
        foreach ($passMatches[1] as $passHost) {
            if (!str_starts_with($passHost, 'km_')) {
                $directProxy[] = "{$confName}: {$passHost}";
            }
        }
    }
    check('proxy_pass はサービス名を直に書かず upstream(km_…)を通す', [], $directProxy);
    preg_match_all('/upstream\s+(km_[a-z_]+)\s*\{([^}]*)\}/', $httpPart, $upstreamMatches, PREG_SET_ORDER);
    $staticUpstreams = [];
    foreach ($upstreamMatches as $upstreamMatch) {
        if (!preg_match('/^\s*zone\s+\S+\s+\d+k;/m', $upstreamMatch[2]) || preg_match_all('/^\s*server\s+[^;]*;/m', $upstreamMatch[2]) !== preg_match_all('/^\s*server\s+[^;]*\sresolve;/m', $upstreamMatch[2])) {
            $staticUpstreams[] = $upstreamMatch[1];
        }
    }
    check_bool('upstream が 5 つある(web・phpmyadmin・logto 2 つ・soketi)', count($upstreamMatches) === 5);
    check('upstream はどれも zone と resolve を持つ', [], $staticUpstreams);
    check_bool('resolver(Docker の内蔵 DNS)は http に 1 本', str_contains($httpPart, 'resolver 127.0.0.11 valid=10s ipv6=off;') && substr_count($nginx, 'resolver ') === 1);

    km_check_heading('hardening 2026-09-15: 網・read_only・特権・digest・src の読み取り専用');
    $composeBaseCode = $yamlCode($file('compose.yaml'));
    $composeVpsCode = $yamlCode($file('compose.vps.yaml'));
    check_bool('devnet を使わない(網を用途ごとに分けた)', !str_contains($composeBaseCode, 'devnet') && !str_contains($composeVpsCode, 'devnet'));
    // DB の網からは外へ出られない。範囲を固定するのは MariaDB の利用者を 172.30.2.% に絞るため
    check_bool('appdb・authdb は internal、範囲は固定', preg_match('/^  appdb:\n(?:    .*\n|      .*\n)*?    internal: true\n(?:    .*\n|      .*\n|        .*\n)*?        - subnet: 172\.30\.2\.0\/24$/m', $composeBaseCode) === 1
        && preg_match('/^  authdb:\n(?:    .*\n|      .*\n)*?    internal: true\n(?:    .*\n|      .*\n|        .*\n)*?        - subnet: 172\.30\.3\.0\/24$/m', $composeBaseCode) === 1);
    $networksOf = static function (string $block): array {
        preg_match('/^    networks:\n((?:      - \S+\n?)+)/m', $block, $listMatch);
        preg_match_all('/- (\S+)/', $listMatch[1] ?? '', $names);

        return $names[1];
    };
    $expectedNetworks = [
        'postgres' => ['authdb'], 'logto' => ['edge', 'authdb', 'mail'], 'mariadb' => ['appdb'],
        'phpmyadmin' => ['edge', 'appdb'], 'web' => ['edge', 'appdb', 'mail', 'monitor'], 'reverse-proxy' => ['edge'],
        'soketi' => ['edge'], 'mailpit' => ['edge', 'mail'],
        // タスクマネージャー(2026-10-05)。Glances は monitor(internal)だけ。web だけが読みに来る
        'glances' => ['monitor'],
    ];
    $wrongNetworks = [];
    foreach ($expectedNetworks as $serviceName => $networkNames) {
        if ($networksOf($baseServices[$serviceName] ?? '') !== $networkNames) {
            $wrongNetworks[] = $serviceName;
        }
    }
    if ($networksOf($vpsServices['mailserver'] ?? '') !== ['mail']) {
        $wrongNetworks[] = 'mailserver';
    }
    check('サービスごとの網(nginx は edge だけ、DB は自分の網だけ)', [], $wrongNetworks);
    check_bool('mailserver が認証なしで中継するのは mail の網だけ', str_contains($vpsServices['mailserver'] ?? '', 'POSTFIX_mynetworks: 127.0.0.0/8,172.30.4.0/24'));
    check_bool('MariaDB を publish しない', preg_match('/^    ports:/m', $baseServices['mariadb'] ?? '') !== 1);
    $notReadOnly = [];
    $notCapDropped = [];
    foreach ($baseServices + array_intersect_key($vpsServices, ['certbot' => true, 'mailserver' => true]) as $serviceName => $block) {
        // phpmyadmin と mailserver は起動スクリプトがイメージの中に設定を書き出すので付けない(compose の注記)
        if (!in_array($serviceName, ['phpmyadmin', 'mailserver'], true) && preg_match('/^    read_only: true$/m', $block) !== 1) {
            $notReadOnly[] = $serviceName;
        }
        if (preg_match('/^    cap_drop:\n      - ALL$/m', $block) !== 1) {
            $notCapDropped[] = $serviceName;
        }
    }
    check('read_only(phpmyadmin と mailserver 以外)', [], $notReadOnly);
    check('cap_drop: ALL(全サービス)', [], $notCapDropped);
    $floatingImages = [];
    foreach ([$composeBaseCode, $composeVpsCode] as $composeCode) {
        preg_match_all('/^\s+image:\s*(\S+)/m', $composeCode, $imageMatches);
        foreach ($imageMatches[1] as $image) {
            if (!str_contains($image, '@sha256:')) {
                $floatingImages[] = $image;
            }
        }
    }
    foreach (['docker/php/Dockerfile', 'docker/soketi/Dockerfile'] as $dockerfile) {
        preg_match_all('/^(?:FROM\s+(\S+)|COPY\s+--from=(\S+))/m', $yamlCode($file($dockerfile)), $fromMatches, PREG_SET_ORDER);
        foreach ($fromMatches as $fromMatch) {
            $image = ($fromMatch[1] ?? '') !== '' ? $fromMatch[1] : ($fromMatch[2] ?? '');
            // `COPY --from=fork` のような段の名前はイメージではない
            if ((str_contains($image, ':') || str_contains($image, '/')) && !str_contains($image, '@sha256:')) {
                $floatingImages[] = "{$dockerfile}: {$image}";
            }
        }
    }
    check('イメージとビルド元はすべて digest で固定', [], $floatingImages);
    // タグも残す: check-updates.sh が Logto の版番号と、固定した digest より新しい修正版を読むため
    $untaggedPins = [];
    foreach (['postgres', 'logto', 'mariadb', 'phpmyadmin'] as $serviceName) {
        if (preg_match('/^    image: [^\s@]+:[^\s@\/]+@sha256:[0-9a-f]{64}$/m', $baseServices[$serviceName] ?? '') !== 1) {
            $untaggedPins[] = $serviceName;
        }
    }
    check('版や系列のあるイメージは「名前:タグ@digest」で固定', [], $untaggedPins);
    $checkUpdatesCode = $file('scripts/check-updates.sh');
    check_bool('check-updates.sh は「タグ@digest」の修正版を知らせ、Logto の版を digest の前から読む', str_contains($checkUpdatesCode, 'compare_pinned "$_image" series ""') && str_contains($checkUpdatesCode, 'compare_pinned "$_base" base') && str_contains($checkUpdatesCode, '_logto_ref="${LOGTO_IMAGE%@*}"'));
    $phpDockerfileCode = $yamlCode($file('docker/php/Dockerfile'));
    check_bool('web のイメージに使わない拡張(pdo_pgsql・libpq)を入れない(§7 の 14)', !str_contains($phpDockerfileCode, 'pdo_pgsql') && !str_contains($phpDockerfileCode, 'libpq'));
    // GD(2026-10-30、利用者の指示。ポスターの縮小版・差し替えた床の WebP)。-dev は残さず、入ったことを作るときに確かめる
    check_bool('web の GD は WebP・JPEG 付きで、-dev を外し、作るときに imagewebp を確かめる', str_contains($phpDockerfileCode, 'docker-php-ext-configure gd --with-jpeg --with-webp;')
        && str_contains($phpDockerfileCode, 'apt-get purge -y --auto-remove') && str_contains($phpDockerfileCode, 'function_exists("imagewebp")'));
    check_bool('縮小版・WebP の写しは cache/ に置く(/tmp は 64MB の tmpfs でアップロードと取り合う)', str_contains($file('src/lib/floor-webp.php'), "return __DIR__ . '/../cache/floor-webp';")
        && str_contains($file('src/lib/image-thumb.php'), "return __DIR__ . '/../cache/image-thumb';"));
    $srcMounts = [
        './src:/var/www/html:ro',
        './src/uploads:/var/www/html/uploads',
        './src/cache:/var/www/html/cache',
        './src/config/app-map.local.php:/var/www/html/config/app-map.local.php',
        './src/config/map-access.local.php:/var/www/html/config/map-access.local.php',
    ];
    foreach (['compose.yaml' => $baseWeb, 'compose.vps.yaml' => $vpsWeb] as $composeName => $webBlock) {
        $missingMounts = array_values(array_filter($srcMounts, static fn (string $mount): bool => preg_match('/^      - ' . preg_quote($mount, '/') . '$/m', $webBlock) !== 1));
        check("{$composeName}: web は src を読み取り専用で渡し、書く 4 か所だけ重ねる", [], $missingMounts);
        check_bool("{$composeName}: src を書ける形で渡していない", preg_match('#^      - \./src:/var/www/html$#m', $webBlock) !== 1);
    }
    check_bool('host-setup.sh が PHP の書く設定 2 つを空で作れる(無いと Docker がディレクトリを作る)', str_contains($file('scripts/host-setup.sh'), 'for name in map-access app-map; do'));
    $soketiDockerfile = $file('docker/soketi/Dockerfile');
    check_bool('Soketi は Node 24 の土台にフォークの /app を載せる(§7 の 4)', preg_match('/^FROM node:24-trixie-slim@sha256:[0-9a-f]{64}$/m', $soketiDockerfile) === 1 && str_contains($soketiDockerfile, 'COPY --from=fork /app /app'));
    check_bool('Soketi の pm2 の置き場は /tmp(read_only の下で起動するため)', str_contains($soketiDockerfile, 'ENV PM2_HOME=/tmp/.pm2'));
    check_bool('allow-admin.conf は Docker の網(172.16.0.0/12)を許さない(§7 の 8)', preg_match('#^\s*allow 172\.16\.0\.0/12;#m', $file('nginx/km/allow-admin.conf')) !== 1);
    check_bool('Logto は LOGTO_DB_USER があればそれで繋ぐ(§7 の 3)', str_contains($baseServices['logto'] ?? '', 'DB_URL: postgres://${LOGTO_DB_USER:-${POSTGRES_USER}}:${LOGTO_DB_PASSWORD:-${POSTGRES_PASSWORD}}@postgres:5432/${POSTGRES_DB}'));
    $roleSql = $file('scripts/logto-db-role.sql');
    $markers = ['-- KM-LOGTO-ROLE-BEGIN', '-- KM-LOGTO-ROLE-END', '-- KM-ADMIN-POLICY-BEGIN', '-- KM-ADMIN-POLICY-END'];
    check_bool('logto-db-role.sql の塊の印は 1 つずつ(docs/12 が sed で切り出す)', array_sum(array_map(static fn (string $marker): int => preg_match_all('/^' . preg_quote($marker, '/') . '$/m', $roleSql), $markers)) === 4);
    check_bool('logto_app は SUPERUSER・CREATEROLE・CREATEDB を持たない', str_contains($roleSql, 'CREATE ROLE logto_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS'));

    km_check_heading('hardening 2026-09-15: 管理画面の別オリジンとセッション Cookie');
    $sessionLib = (string) file_get_contents($src . '/lib/session.php');
    check_bool('セッション Cookie は __Host- 付き(lib と ini で同じ名前)', str_contains($sessionLib, "const KM_SESSION_NAME = '__Host-KMSID';") && str_contains($sessionLib, 'session_name(KM_SESSION_NAME);') && preg_match('/^session\.name = __Host-KMSID$/m', $limitsIni) === 1);
    // __Host- はこの 3 つが揃わないとブラウザが受け取らない
    check_bool('__Host- の条件(Secure・Path=/・Domain なし)', str_contains($sessionLib, "'secure' => true") && str_contains($sessionLib, "'path' => '/'") && str_contains($sessionLib, "'domain' => ''"));
    check_bool('ゲートは開始前でも同じ Cookie 名を見る', str_contains($sessionLib, 'isset($_COOKIE[KM_SESSION_NAME])'));
    check_bool('nginx: 管理用のホストでは管理画面に要るパスだけ返す', str_contains($nginx, 'map "$km_host_role:$uri" $km_leave_admin_host') && str_contains($https, 'if ($km_leave_admin_host)') && str_contains($https, 'if ($km_leave_public_host)'));
    check_bool('nginx: 別オリジンかどうかは KM_ADMIN_URL と KM_APP_URL を比べて決める', str_contains($nginx, 'map "${KM_ADMIN_URL}" $km_admin_split {'));
    check_bool('nginx: 6001 の Origin は管理画面の URL', preg_match('/map \$http_origin \$km_ws_origin_denied \{\s*""\s+0;\s*"\$\{KM_ADMIN_URL\}"\s+0;\s*default\s+1;/', $nginx) === 1);
    check_bool('ゲートのログイン先は管理画面の URL', str_contains($file('nginx/km/gate-location.conf'), 'return 302 $km_admin_url/admin/login.php;'));
    check_bool('compose: 既定は公開側と同じ(別オリジンにしない)', str_contains($baseServices['reverse-proxy'] ?? '', 'KM_ADMIN_URL: ${KM_ADMIN_URL:-${KM_APP_URL:-https://192.168.3.29:9443}}') && str_contains($baseWeb, 'ADMIN_URL: ${ADMIN_URL:-${APP_URL:-https://192.168.3.29:9443}}'));
    check_bool('VPS: 管理用の URL は KM_ADMIN_DOMAIN から導く(無ければ KM_DOMAIN)', str_contains($vpsProxy, 'KM_ADMIN_URL: ${KM_ADMIN_URL:-https://${KM_ADMIN_DOMAIN:-${KM_DOMAIN}}}') && str_contains($vpsWeb, 'ADMIN_URL: ${ADMIN_URL:-https://${KM_ADMIN_DOMAIN:-${KM_DOMAIN}}}'));
    check_bool('logto-client.php の戻り先は要求のホストで選ぶ', str_contains((string) file_get_contents($src . '/logto-client.php'), '$appUrl = km_site_request_origin();'));
    check_bool('host-cert.sh で管理用の名前を証明書に足せる', str_contains($file('scripts/host-cert.sh'), '--also) ALSO="$ALSO $2"; shift 2 ;;'));

    /*
     * ## ローカル環境(KM_ENV=local)
     *
     * 本番の構成を LAN の検証機へ持ち込むと、Let's Encrypt が取れず nginx と MariaDB が再起動を繰り返した(2026-09-17)。
     * 本番の 2 枚はそのままに compose.local.yaml を**重ねる**。置き換え(`!override`)を使うと、
     * 本番で直した読み取り専用のマウントや上限がローカルだけ抜けるので禁じる。
     */
    km_check_heading('local-env: ローカル環境(compose.local.yaml と host-local.sh)');
    $localCompose = $file('compose.local.yaml');
    $localCode = $yamlCode($localCompose);
    check_bool('compose.local.yaml がある', $localCompose !== '');
    check_bool('compose.local.yaml は置き換えずに足すだけ(!override を使わない)', !str_contains($localCode, '!override'));
    check_bool('compose.local.yaml に本番のドメインを書かない', !str_contains($localCompose, 'ito8795.com') && !str_contains($localCompose, 'ito4.jp'));
    check_bool('ローカルでは certbot と mailserver を起動しない', preg_match('/^  certbot:\n    profiles: \["public"\]$/m', $localCode) === 1 && preg_match('/^  mailserver:\n    profiles: \["public"\]$/m', $localCode) === 1);
    check_bool('ローカルでは Mailpit を起動し、web のメールをそこへ落とす', preg_match('/^  mailpit:\n    profiles: !reset \[\]$/m', $localCode) === 1 && str_contains($localCode, 'MAIL_HOST: mailpit') && str_contains($localCode, 'MAIL_PORT: "1025"'));
    check_bool('nginx は自作 CA の証明書を読む', str_contains($localCode, 'KM_CERT: /etc/nginx/certs/local-server.pem') && str_contains($localCode, './certs/local-server-key.pem:/etc/nginx/certs/local-server-key.pem:ro'));
    check_bool('コンテナの中から KM_DOMAIN が nginx に届く(edge の別名)', preg_match('/^    networks:\n      edge:\n(?:\s*#.*\n)*        aliases:\n          - \$\{KM_DOMAIN:\?/m', $localCompose) === 1);
    check_bool('web と Logto に自作 CA を信頼させる', str_contains($localCode, 'target: /etc/ssl/localca/rootCA.pem') && str_contains($localCode, 'target: /usr/local/etc/php/conf.d/99-local-ca.ini') && str_contains($localCode, 'NODE_EXTRA_CA_CERTS: /etc/km-ca/rootCA.pem'));
    check_bool('web に KM_ENV=local を渡し、画面に出す', str_contains($localCode, 'KM_ENV: local') && str_contains((string) file_get_contents($src . '/admin/_inc/partials/footer.php'), 'km_site_is_local()'));
    $hostLocal = $file('scripts/host-local.sh');
    check_bool('host-local.sh がある', $hostLocal !== '');
    check_bool('host-local.sh は本番で止まる(本番のドメイン・Let\'s Encrypt の証明書・公開のアドレス)', str_contains($hostLocal, 'PROD_DOMAINS="ito4.jp ito8795.com"') && str_contains($hostLocal, 'docker volume inspect test_letsencrypt') && str_contains($hostLocal, 'is_private_ipv4 "$_a"'));
    check_bool('host-local.sh は古い証明書を消さずに退避する', str_contains($hostLocal, 'ARCHIVE="$CERTS/Old/') && !preg_match('/^\s*rm\s/m', $hostLocal));
    check_bool('host-local.sh の COMPOSE_FILE は compose.local.yaml を重ねる', str_contains($hostLocal, 'LOCAL_COMPOSE="compose.yaml:compose.vps.yaml:compose.local.yaml"'));
    check_bool('host-cert.sh はローカル環境で Let\'s Encrypt を使わない', str_contains($file('scripts/host-cert.sh'), 'if [ "$(env_value KM_ENV)" = "local" ]; then'));
    $hostSetup = $file('scripts/host-setup.sh');
    check_bool('host-setup.sh は up の前に証明書の有無を見る(無いと再起動を繰り返す)', str_contains($hostSetup, "head_ '証明書(nginx と MariaDB が起動に使うもの)'") && str_contains($hostSetup, 'test -f "/le/live/$KM_DOMAIN_VALUE/fullchain.pem"'));
    check_bool('host-setup.sh が host-local.sh に実行ビットを付ける', preg_match("/^HOST_SCRIPTS='[^']*\\bhost-local\\.sh\\b[^']*'$/m", $hostSetup) === 1);
    $deploy = $file('scripts/deploy-to-host.ps1');
    check_bool('配備物に compose.local.yaml と host-local.sh がある', str_contains($deploy, "'./compose.local.yaml'") && str_contains($deploy, "'./scripts/host-local.sh'"));
    check_bool('配備は相手の .env の KM_ENV を見て、本番に KM_ENV=local があれば止まる', str_contains($deploy, '本番のホスト($HostName)の .env が KM_ENV=local になっています'));

    /*
     * ## 機密なしのコピー(2026-10-05)
     *
     * 検証機へは MariaDB だけを戻し、本番の .env・config・Logto・uploads は持ち込まない。
     * 秘密は host-local.sh init --fresh-secrets が新しく作り、氏名や記録は local-sanitize.sh が抜く。
     */
    km_check_heading('local-copy: 機密なしのコピー(--fresh-secrets と local-sanitize.sh)');
    check_bool('--fresh-secrets は空のキーだけを埋める(入っている値に触らない)', str_contains($hostLocal, '--fresh-secrets) FRESH=1; shift ;;') && str_contains($hostLocal, 'if [ -z "$(env_value "$1")" ]; then') && str_contains($hostLocal, 'od -An -N24 -tx1 /dev/urandom'));
    check_bool('--fresh-secrets は本番の判定(init の 1.)を通ったあとでだけ動く', strpos($hostLocal, 'fresh_secrets' . "\n" . '  fi') > strpos($hostLocal, "ok \"本番の .env でも、Let's Encrypt の証明書のあるホストでもありません\""));
    check_bool('--fresh-secrets は秘密の値を表示しない', str_contains($hostLocal, 'did "$1 を乱数で作りました"') && !str_contains($hostLocal, 'did "$1=$2 '));
    $sanitize = $file('scripts/local-sanitize.sh');
    check_bool('local-sanitize.sh がある', $sanitize !== '');
    check_bool('local-sanitize.sh は KM_ENV=local でなければ・本番の名前なら止まる', str_contains($sanitize, 'if [ "$(env_value KM_ENV)" != "local" ]; then') && str_contains($sanitize, 'PROD_DOMAINS="ito4.jp ito8795.com"'));
    check_bool('local-sanitize.sh は氏名をダミーに・記録と鍵を空にする', str_contains($sanitize, "SET occupant_name = CONCAT('教員'") && str_contains($sanitize, 'km_admin_log') && str_contains($sanitize, 'km_app_secrets') && str_contains($sanitize, 'km_staff_requests'));
    check_bool('local-sanitize.sh は部屋・施設の名前もダミーにする(分類+連番)', str_contains($sanitize, "WHERE type IN ('room', 'facility')") && str_contains($sanitize, "' D', LPAD(@n := @n + 1, 3, '0')"));
    check_bool('local-sanitize.sh はパスワードを引数に載せない(MYSQL_PWD)', str_contains($sanitize, 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD"') && !preg_match('/-p"?\$MARIADB/', $sanitize));
    check_bool('compose は Android のアプリ ID を渡し、空なら本番の既定に落ちる(KM_ANDROID_APP_ID)', str_contains($file('compose.yaml'), 'KOSENMAP_LOGTO_APP_ID: ${KM_ANDROID_APP_ID:-}') && str_contains($file('src/logto_config.php'), "'KOSENMAP_LOGTO_APP_ID',"));
    check_bool('配備物に local-sanitize.sh がある', str_contains($deploy, "'./scripts/local-sanitize.sh'"));

    /*
     * ## 2026-10-05 夜の依頼(スクリプトと管理画面の小さな直し)
     */
    km_check_heading('admin-2026-10-05: PR の説明・お知らせ・次フェーズ・地図配信の字・共有リンク・チャート・お試しのスイッチ・マップへ');
    check_bool('配備は既定でプルリクエストの説明を作らない(-PullRequest のときだけ渡す)', str_contains($deploy, '[switch]$PullRequest') && str_contains($deploy, "if (\$PullRequest) { \$pushAllArgs += '-PullRequest' }"));
    $pushAll = $file('scripts/push-github-all.ps1');
    check_bool('push-github-all も -PullRequest を受けて渡す', str_contains($pushAll, '[switch]$PullRequest') && str_contains($pushAll, "if (\$PullRequest) { \$pushArgs += '-PullRequest' }"));
    $adminScripts = $file('src/admin/_inc/partials/scripts.php');
    $noticeJs = $file('src/admin/assets/js/km-notice.js');
    check_bool('お知らせを閉じる: 情報のお知らせだけ・ブラウザに覚える', str_contains($adminScripts, "./assets/js/km-notice.js") && str_contains($noticeJs, ".alert.alert-info.d-flex.align-items-start") && str_contains($noticeJs, "'kmadmin-dismissed'") && !str_contains($noticeJs, 'fetch('));
    $dashboard = $file('src/admin/index.php');
    check_bool('次フェーズの作業はかんばんの表から出す(決め打ちを残さない)', str_contains($dashboard, 'km_tasks_all(km_db())') && !str_contains($dashboard, 'page.index.next1'));
    // サービス監視の「今後の拡張」もタスクの分類から出す(2026-10-06)
    $monitorPage = $file('src/admin/monitor.php');
    $tasksLib = $file('src/lib/tasks.php');
    check_bool('サービス監視の「今後の拡張」はタスクの分類 monitor から出す(決め打ちを残さない)', str_contains($monitorPage, "km_tasks_by_topic(km_db(), 'monitor')")
        && !str_contains($monitorPage, 'page.monitor.plan1') && !str_contains($file('src/admin/assets/i18n/ja.js'), '"page.monitor.plan1"'));
    check_bool('分類は決めた値だけ(作る・直すの両方で確かめる)', substr_count($tasksLib, 'km_tasks_validate_topic($topic);') === 2
        && str_contains($tasksLib, "if (!array_key_exists(\$topic, KM_TASK_TOPICS))"));
    check_bool('分類の列は古い表にも足し、3 行は列を足したときだけ入れる(消したら戻さない)', str_contains($tasksLib, "ADD COLUMN IF NOT EXISTS topic VARCHAR(32) NOT NULL DEFAULT ''")
        && preg_match('/function km_tasks_ensure_topic_column\(.*?\n\}/s', $tasksLib, $topicFn) === 1
        && strpos($topicFn[0], 'ALTER TABLE') < strpos($topicFn[0], 'km_tasks_seed_monitor($pdo)')
        && str_contains($tasksLib, "WHERE NOT EXISTS (SELECT 1 FROM km_tasks WHERE topic = 'monitor' AND title = ?)"));
    check_bool('かんばんの絞り込みは隠すだけ(並び順の保存でカードを落とさない)', str_contains($file('src/admin/kanban.php'), "card.classList.toggle('d-none'"));
    check_bool('辞書: 分類の訳が日英の両方にある', str_contains($file('src/admin/assets/i18n/ja.js'), '"page.projects.topic.monitor"') && str_contains($file('src/admin/assets/i18n/en.js'), '"page.projects.topic.monitor"')
        && str_contains($file('src/admin/assets/i18n/en.js'), '"page.projects.topic.none"'));
    $publishPage = $file('src/admin/map-publish.php');
    check_bool('地図の配信: 表のセル全体を等幅にしない・見出しは h3.card-title', !str_contains($publishPage, '<td class="font-monospace">') && !str_contains($publishPage, '<h4'));
    $share = $file('src/lib/file-share.php');
    $sharePage = $file('src/share.php');
    check_bool('共有リンク: トークンは sha256 だけを置き、回数は 1 本の UPDATE で数える', str_contains($share, "hash('sha256', 'km-file-share|' . \$token)") && str_contains($share, 'SET uses = uses + 1') && str_contains($share, 'uses < max_uses'));
    check_bool('共有リンク: GET では数えない(POST と CSRF で渡す)', str_contains($sharePage, "if (\$method === 'POST') {") && str_contains($sharePage, 'km_csrf_verify()') && strpos($sharePage, 'km_file_share_use(') > strpos($sharePage, "if (\$method === 'POST') {"));
    check_bool('共有リンク: nginx はクエリを記録せず回数を絞る', str_contains($nginx, "location = /share.php {\n        access_log /var/log/nginx/access.log km_no_query;\n        access_log /var/log/km-visit/visit-\$km_visit_day.jsonl km_visit;\n        limit_req zone=km_public"));
    check_bool('共有リンク: ファイルを消したらリンクも止める', str_contains($file('src/admin/file-manager.php'), 'km_file_share_revoke_for_file($pdo, $id);'));
    $charts = $file('src/admin/charts.php');
    check_bool('チャート: 棒の上の数字の余白を取る(いちばん高い棒の数字が切れない)', str_contains($charts, '$chartTop = 20;') && str_contains($charts, '$y = $chartTop + $chartHeight - $barHeight;'));
    check_bool('チャート: ランキングの語(隠した語は除く)とよく行く場所', str_contains($charts, 'km_map_ranking_hidden_queries') && str_contains($charts, 'km_map_ranking_places'));
    $guestLib = $file('src/lib/map-guest.php');
    foreach (['km_map_guest_create', 'km_map_guest_revoke', 'km_map_guest_create_account', 'km_map_guest_revoke_account', 'km_map_guest_set_names'] as $writer) {
        $body = (string) strstr((string) strstr($guestLib, "function {$writer}("), "\n}\n", true);
        check_bool("お試し: {$writer} は読み取り専用なら断る", str_contains($body, 'km_map_guest_assert_writable($pdo);'));
    }
    check_bool('お試し: 無効のあいだは入れない(印は消さない)', str_contains($guestLib, "\$GLOBALS['km_map_guest_suspended'] = true;") && str_contains($file('src/guest.php'), 'if (!km_map_guest_enabled($pdo)) {'));
    check_bool('マップへ: 公開側の URL へ戻る(相対の / にしない)', str_contains($file('src/admin/_inc/partials/header.php'), "km_site_url(null, '/')"));

    /*
     * ## Docker のログの回転とスワップの規則(2026-10-05)
     * mem_limit を持つサービスには、必ず memswap_limit・oom_score_adj・logging を付ける(付け忘れを見張る)。
     */
    km_check_heading('docker-resources: ログの回転・スワップの規則・OOM の順');
    foreach (['compose.yaml', 'compose.vps.yaml'] as $composeFile) {
        $composeText = $file($composeFile);
        check_bool("{$composeFile}: ログの回転の定義がある(10MB × 3)", str_contains($composeText, "x-km-logging: &km-logging\n  driver: json-file\n  options:\n    max-size: \"10m\"\n    max-file: \"3\""));
        $missing = [];
        if (preg_match_all('/\n  ([a-z0-9-]+):\n((?:(?!\n  [a-z0-9-]+:\n)[\s\S])*)/', $composeText, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as [, $service, $block]) {
                if (!preg_match('/\n    mem_limit: (\d+)m\n/', $block, $mem)) {
                    continue;
                }
                $swapOk = preg_match('/\n    memswap_limit: (\d+)m\n/', $block, $swap) === 1
                    && in_array((int) $swap[1], [(int) $mem[1], (int) $mem[1] * 2], true);
                if (!$swapOk || !str_contains($block, "\n    oom_score_adj: ") || !str_contains($block, "\n    logging: *km-logging\n")) {
                    $missing[] = $service;
                }
            }
        }
        check_bool("{$composeFile}: mem_limit のあるサービスは全部、スワップの上限・OOM の順・ログの回転を持つ", $missing === [], implode(', ', $missing));
    }
    /*
     * ## タスクマネージャー(2026-10-05)
     * Glances はホスト全体だけ(docker.sock を渡さない)・monitor の網だけ。ページとデータの口は IP で絞る。
     * データの口は生の値を束ねるだけ(変換はブラウザ)。
     */
    km_check_heading('taskmgr: Glances・ホストの集計・IP の制限・生の値');
    $glancesBlock = $baseServices['glances'] ?? '';
    check_bool('Glances は monitor(internal)の網だけで、publish しない', $glancesBlock !== '' && preg_match('/^    ports:/m', $glancesBlock) !== 1
        && preg_match('/\n  monitor:\n    driver: bridge\n    internal: true\n/', $file('compose.yaml')) === 1);
    check_bool('Glances に docker.sock を渡さない(ホスト全体だけ)', $glancesBlock !== '' && !str_contains($glancesBlock, 'docker.sock') && str_contains($glancesBlock, '--disable-webui'));
    $taskmgrAllow = "include /etc/nginx/km/taskmgr-allow*.local.conf;\n        deny all;\n        error_page 403 = @km_taskmgr_hide;";
    check_bool('タスクマネージャーのページとデータの口は IP で絞る(許可が無ければ誰も入れない)', str_contains($nginx, "location = /admin/taskmgr.php {\n        {$taskmgrAllow}") && str_contains($nginx, "location = /admin/api/taskmgr-data.php {\n        {$taskmgrAllow}"));
    check_bool('IP の許可はリポジトリに置かない(見本だけ)・配備から除外する', !is_file(km_check_repo_root() . '/nginx/km/taskmgr-allow.local.conf')
        && is_file(km_check_repo_root() . '/nginx/km/taskmgr-allow.local.conf.example')
        && str_contains($deploy, "'./nginx/km/taskmgr-allow.local.conf'"));
    $taskmgrData = $file('src/admin/api/taskmgr-data.php');
    check_bool('データの口は生の値を束ねるだけ(json_decode して組み直さない)', str_contains($taskmgrData, 'json_validate($text)') && !str_contains($taskmgrData, 'json_decode('));
    $taskmgrJs = $file('src/admin/assets/js/taskmgr.js');
    check_bool('ブラウザが変換する(taskmgr.js)・タブが隠れたら止める', str_contains($taskmgrJs, "document.addEventListener('visibilitychange'"));
    // 間隔は 1〜10 秒で選べる(2026-10-06)。前回が終わってから次を予約する(要求を重ねない)
    check_bool('タスクマネージャーの間隔は 1〜10 秒から選び、範囲外は既定に戻す', str_contains($taskmgrJs, 'n >= 1 && n <= 10 ? n : INTERVAL_DEFAULT')
        && str_contains($file('src/admin/taskmgr.php'), 'for ($s = 1; $s <= 10; $s++)')
        && str_contains($taskmgrJs, 'window.setTimeout(() => tick(mine), seconds * 1000)')
        && !str_contains($taskmgrJs, 'setInterval('));
    $hostUpdates = $file('scripts/host-updates-setup.sh');
    check_bool('cron(版 7 以降)に host-stats(1 分)と host-resource-alert(5 分)がある', preg_match('/^CRON_VERSION=([7-9]|[1-9][0-9])$/m', $hostUpdates) === 1
        && str_contains($hostUpdates, '* * * * *    root  $STATS_SCRIPT --path $PATH_ROOT')
        && str_contains($hostUpdates, '*/5 * * * *  root  $RESOURCE_SCRIPT --path $PATH_ROOT'));
    check_bool('配備物に host-stats.sh と host-resource-alert.sh がある', str_contains($deploy, "'./scripts/host-stats.sh'") && str_contains($deploy, "'./scripts/host-resource-alert.sh'"));
    check_bool('web はホストの集計を読み取り専用で受ける(compose と VPS の両方)', str_contains($file('compose.yaml'), '- ./run/hoststats:/var/www/hoststats:ro') && str_contains($file('compose.vps.yaml'), '- ./run/hoststats:/var/www/hoststats:ro'));

    /*
     * ## 訪問者の記録(2026-10-06。lib/visit-log.php)
     * nginx が公開のページだけを JSON で書き(クエリなし)、web は読むだけ。まとめと位置はブラウザ。30 日で消す。
     */
    km_check_heading('visit-log: 訪問者の記録・生の行・位置はブラウザ・30 日');
    check_bool('nginx: 訪問の記録は JSON で、パスはクエリなし・参照元もクエリより前だけ', str_contains($nginx, "log_format km_visit escape=json")
        && preg_match('/log_format km_visit[^;]*"p":"\$uri"/s', $nginx) === 1 && preg_match('/log_format km_visit[^;]*\$km_visit_ref/s', $nginx) === 1
        && preg_match('/log_format km_visit[^;]*\$(request|request_uri|args|query_string|http_referer)\b/s', $nginx) !== 1
        && str_contains($nginx, '"~^(?<km_ref>[^?#]*)" $km_ref;'));
    check_bool('nginx: 書くのは公開のページだけ(管理画面は書かない)', preg_match('/map \$uri \$km_visit_page \{[^}]*"\/"\s+1;[^}]*\}/s', $nginx) === 1
        && preg_match('/map \$uri \$km_visit_page \{[^}]*admin[^}]*\}/s', $nginx) !== 1);
    check_bool('nginx: server に既定のログと訪問の記録の両方を書く・guest と share にも書く', str_contains($nginx, "access_log /var/log/nginx/access.log main;\n    access_log /var/log/km-visit/visit-\$km_visit_day.jsonl km_visit if=\$km_visit_page;")
        && substr_count($nginx, 'access_log /var/log/km-visit/visit-$km_visit_day.jsonl km_visit;') === 2);
    // 変数入りの書き先は root のディレクトリの有無を見てから書く(無いと 1 行も書かれない。2026-10-06 に検証機で踏んだ)
    check_bool('nginx: 443 の server は空の root を持つ(変数入りの書き先のため)', str_contains($nginx, "open_log_file_cache max=4 inactive=60s valid=60s;\n")
        && preg_match('/km_visit if=\$km_visit_page;[\s\S]{0,600}?\n    root \/var\/empty;\n/', $nginx) === 1);
    check_bool('閲覧者の種別は利用者に返さない(proxy_hide_header)', str_contains($file('nginx/km/proxy-web.conf'), 'proxy_hide_header X-KM-Viewer;'));
    $vpsCompose = $file('compose.vps.yaml');
    check_bool('compose: nginx が書き、web は読むだけ(基底と VPS の両方)', str_contains($file('compose.yaml'), '- ./run/visitlog:/var/log/km-visit') && str_contains($vpsCompose, '- ./run/visitlog:/var/log/km-visit')
        && str_contains($file('compose.yaml'), '- ./run/visitlog:/var/www/visitlog:ro') && str_contains($vpsCompose, '- ./run/visitlog:/var/www/visitlog:ro'));
    check_bool('compose: 置き場は起動のたびに nginx(101)の持ち物・グループは www-data(33)・ほかは読めない', str_contains($file('compose.yaml'), 'chown 0:33 /var/log/km-visit && chmod 750 /var/log/km-visit \
          && chown 101:33 /var/log/km-visit')
        && str_contains($file('compose.yaml'), 'exec /docker-entrypoint.sh "$$@"') && str_contains($file('compose.yaml'), 'command: ["nginx", "-g", "daemon off;"]'));
    $visitLib = $file('src/lib/visit-log.php');
    check_bool('サインインした人は鍵つきの印にする(sub をそのまま書かない)', str_contains($visitLib, "km_app_keyed_hash(km_app_secret(km_db(), 'visit'), 'visit-viewer', \$id)")
        && str_contains($file('src/index.php'), "km_visit_mark_viewer('user', (string) \$claims->sub)")
        && str_contains($file('src/api/app-map.php'), "km_visit_mark_viewer(is_array(\$principal) ? 'app' : 'anon'"));
    check_bool('記録の口は生の行をそのまま渡す(PHP で読み解かない)', !str_contains($visitLib, 'json_decode(') && str_contains($visitLib, 'fpassthru($handle)')
        && str_contains($file('src/admin/api/visit-log.php'), "require dirname(__DIR__) . '/_inc/guard.php';"));
    $csp = $file('src/lib/csp.php');
    // 宛先は admin-geo の塊の中にだけ書く(ほかのプロファイルに混ざらない)
    $geoBlock = preg_match("/    if \(\\\$profile === 'admin-geo'\) \{.*?\n    \}\n/s", $csp, $geoMatch) === 1 ? $geoMatch[0] : '';
    check_bool('位置の外部はこのページだけ connect-src に足す', str_contains($file('src/admin/visitors.php'), "km_csp_send('admin-geo');")
        && str_contains($geoBlock, "\$connect[] = 'https://ipwho.is';") && str_contains($geoBlock, "\$connect[] = 'https://api.country.is';")
        && !str_contains(str_replace($geoBlock, '', $csp), "'https://ipwho.is'") && !str_contains(str_replace($geoBlock, '', $csp), "'https://api.country.is'"));
    $visitorsJs = $file('src/admin/assets/js/visitors.js');
    check_bool('位置は既定で引かない(スイッチを入れたときだけ)・文字は textContent', str_contains($visitorsJs, "pref.get(PREF_GEO, '0') === '1'") && !str_contains($visitorsJs, 'innerHTML'));
    // 端末の目安(2026-10-29、振り返り「170 人は IP の数で、学校の回線は 1 人」)。新しい印を作らず、記録の ip・ua・種別の印だけで数える
    check_bool('端末の目安: サインイン等は種別の印、ほかは IP と端末の名乗りの組・日別の列と見出しの数が合う',
        str_contains($visitorsJs, "const deviceKey = (v) => (v.v && v.v.includes(':') ? v.v : `\${v.ip}\\n\${v.ua || ''}`);")
        && str_contains($visitorsJs, 'String(d.ips.size), String(d.devices.size), String(d.foreign)')
        && str_contains($file('src/admin/visitors.php'), "['page.visitors.ips', '送り元(IP)'], ['page.visitors.colDevices', '端末の目安'], ['page.visitors.foreign', '外国から']")
        && str_contains($file('src/admin/assets/i18n/en.js'), '"page.visitors.colDevices"'));
    // 色分け(2026-10-08、利用者の指示「危険は赤・怪しいは黄・危険リスト・外国はチェックボックスで表示・非表示」)
    $threatsJs = $file('src/admin/assets/js/visitors-threats.js');
    $visitorsPage = $file('src/admin/visitors.php');
    check_bool('色分け: 判定の決まりは visitors-threats.js(画面より先に読む)・赤と黄の行', strpos($visitorsPage, 'visitors-threats.js') !== false
        && strpos($visitorsPage, 'visitors-threats.js') < strpos($visitorsPage, "assets/js/visitors.js'")
        && str_contains($visitorsJs, "danger: 'table-danger', suspect: 'table-warning'") && !str_contains($threatsJs, 'innerHTML'));
    check_bool('色分け: RedTail(libredtail-http)と POST を受けないページへの POST は危険', str_contains($threatsJs, '/libredtail|redtail/i')
        && str_contains($threatsJs, "test: (v) => v.m === 'POST' && !POST_PAGES.has(v.p)"));
    // POST を受けるページの一覧が、実際に POST を受けるページと揃っているか(足し忘れると正しい送信を赤にする)
    preg_match("/const POST_PAGES = new Set\(\[([^\]]*)\]\)/", $threatsJs, $postMatch);
    $postPages = array_map(static fn ($s) => trim($s, " '"), explode(',', $postMatch[1] ?? ''));
    sort($postPages);
    check('色分け: POST を受けるページ(お問い合わせ・お試し・共有・アプリの地図)', ['/api/app-map.php', '/contact.php', '/guest.php', '/share.php'], $postPages);
    check_bool('色分け: 外国はチェックボックスで表示・非表示(既定は表示)', str_contains($visitorsPage, 'id="km-visitors-show-foreign" checked')
        && str_contains($visitorsJs, "pref.get(PREF_FOREIGN, '1') === '1'"));
    // 点数(2026-10-08 の 2 回目、利用者の指示「① ISP/ASN 〜 ⑦ 過去の挙動 を計算する・件数は 10/50/100/200・国は ▽ で開く・Excel へ書き出す」)
    check_bool('点数: 60 点以上は危険・25 点以上は怪しい・① 〜 ⑦ の見方がそろう', str_contains($threatsJs, 'const DANGER_SCORE = 60;')
        && str_contains($threatsJs, 'const SUSPECT_SCORE = 25;')
        && count(array_filter(['① ISP/ASN', '② User-Agent', '③ IP 種別', '④ アクセス頻度', '⑤ HTTP ステータス', '⑥ URL・メソッド', '⑦ 過去の挙動'], static fn ($s) => str_contains($threatsJs, "'$s'"))) === 7);
    check_bool('点数: 判定は表示のたびに、位置・信頼・前の危険を渡して行う', str_contains($visitorsJs, 'threats.classify(visits, { geoOf: (ip) => geo.get(ip), isPrivate, trusted, history })'));
    check_bool('① ISP/ASN は ipwho.is の connection で引く(ASN の無い古い覚えは引き直す)', str_contains($visitorsJs, 'fields=success,country_code,country,region,city,connection')
        && str_contains($visitorsJs, "g.src === 'ipwho.is' && !('asn' in g)"));
    check_bool('⑦ 前の危険は IndexedDB(flags)に 30 日だけ覚える', str_contains($visitorsJs, "indexedDB.open('km-visitors', 2)") && str_contains($visitorsJs, "createObjectStore('flags', { keyPath: 'ip' })")
        && str_contains($visitorsJs, "await loadStore('flags', history);"));
    check_bool('信頼する送り元はこのブラウザ(localStorage)だけ・IP をコードに書かない', str_contains($visitorsJs, "const PREF_TRUSTED = 'kmadmin-visitors-trusted';")
        && preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $visitorsJs . $threatsJs) === 0);
    check_bool('最近の訪問は 10・50・100・200 件から選ぶ', str_contains($visitorsJs, "const ROW_CHOICES = ['10', '50', '100', '200'];")
        && preg_match_all('/<option value="(10|50|100|200)"( selected)?>\1<\/option>/', $visitorsPage) === 4);
    check_bool('国・地域別は ▷ / ▽ で地域・都市を開く', str_contains($visitorsJs, "toggle.textContent = open ? '▽' : '▷';") && str_contains($visitorsJs, 'expandedCountries'));
    check_bool('CSV: BOM 付き(Excel で開ける)・式に読まれる値の頭に \'', str_contains($visitorsJs, '`﻿${rows.map((r) => r.map(csvCell).join(\',\')).join(\'\r\n\')}\r\n`')
        && str_contains($visitorsJs, "if (/^[=+\\-@\\t\\r]/.test(s)) {")
        && str_contains($visitorsPage, 'id="km-visitors-export-visits"') && str_contains($visitorsPage, 'id="km-visitors-export-ips"'));
    // 世界地図と BAN・折りたたみ(2026-10-08 の 3 回目、利用者の指示「本書の bg-primary のカードだけを管理画面に・BAN と回数も」)
    $worldJs = $file('src/admin/assets/js/visitors-world.js');
    check_bool('折りたたみ: 日別・国・地域別・ページ別のカードに card-collapse', preg_match_all("/\\\$table\('(byDay|byCountry|byPage)',[^\n]*collapsible: true\);/", $visitorsPage) === 3
        && str_contains($visitorsPage, 'data-lte-toggle="card-collapse"'));
    check_bool('世界地図: 本書の card text-white bg-primary bg-gradient border-primary mb-4 を写す(地図・小さなグラフ 3 つ・折りたたみ)', str_contains($visitorsPage, '<div class="card text-white bg-primary bg-gradient border-primary mb-4" id="km-visitors-world">')
        && str_contains($visitorsPage, '<div id="km-visitors-world-map" class="km-world-map"></div>')
        && substr_count($visitorsPage, 'data-km-vs="spark') === 6
        && str_contains($visitorsPage, "\$collapseButton('btn btn-primary btn-sm');"));
    // 地図は本書と同じ版を置いた(CSP で CDN を読めない)。中身が本書の SRI と同じか
    $jvmSri = [
        'src/admin/vendor/jsvectormap/js/jsvectormap.min.js' => '/t1nN2956BT869E6H4V1dnt0X5pAQHPytli+1nTZm2Y=',
        'src/admin/vendor/jsvectormap/maps/world.js' => 'XPpPaZlU8S/HWf7FZLAncLg2SAkP8ScUTII89x9D3lY=',
        'src/admin/vendor/jsvectormap/css/jsvectormap.min.css' => '+uGLJmmTKOqBr+2E6KDYs/NRsHxSkONXFHUL0fy2O/4=',
    ];
    check_bool('世界地図: jsVectorMap 1.5.3 は本書と同じ中身(SRI の sha256)', count(array_filter($jvmSri, static fn ($sri, $rel) => is_file(km_check_repo_root() . "/$rel")
        && base64_encode(hash_file('sha256', km_check_repo_root() . "/$rel", true)) === $sri, ARRAY_FILTER_USE_BOTH)) === 3);
    check_bool('世界地図: 部品は地図 → 描く側 → 判定 → 画面の順に読む・CSS はこのページだけ', strpos($visitorsPage, 'jsvectormap.min.js') < strpos($visitorsPage, 'maps/world.js')
        && strpos($visitorsPage, 'maps/world.js') < strpos($visitorsPage, "assets/js/visitors-world.js'")
        && strpos($visitorsPage, "assets/js/visitors-world.js'") < strpos($visitorsPage, "assets/js/visitors.js'")
        && str_contains($visitorsPage, "'css' => ['./vendor/jsvectormap/css/jsvectormap.min.css'],")
        && str_contains($file('src/admin/_inc/partials/head.php'), "foreach (\$KM_PAGE['css'] ?? [] as \$href)"));
    // ApexCharts は style 属性を書くので使わない。吹き出しは textContent(tooltip.text に HTML の印を渡さない)
    check_bool('世界地図: 小さなグラフは SVG・文字は textContent・IP をコードに書かない', str_contains($worldJs, "document.createElementNS(SVG_NS, 'polyline')")
        && !str_contains($worldJs, 'innerHTML') && !str_contains($worldJs, 'ApexCharts(') && !str_contains($worldJs, "setAttribute('style'")
        && str_contains($worldJs, 'tooltip.text(tips.get(code) || helpers.countryName(code));')
        && preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $worldJs) === 0);
    check_bool('世界地図: jsVectorMap が描き終えて(onLoaded)から塗る(読み込み中は DOMContentLoaded まで描かない)', str_contains($worldJs, 'onLoaded() {')
        && str_contains($worldJs, 'pending = [byCountry, denySet, bannedCountries, denyInvert];'));
    $bansApi = $file('src/admin/api/visitor-bans.php');
    check_bool('BAN: 口は管理者だけ・ホストの bans.json を組み直さずに渡す', str_contains($bansApi, "require dirname(__DIR__) . '/_inc/guard.php';")
        && str_contains($bansApi, "const KM_VISITOR_BANS_FILE = '/var/www/hoststats/bans.json';")
        && str_contains($bansApi, 'json_validate($read)') && !str_contains($bansApi, 'json_decode(')
        && str_contains($visitorsPage, 'data-bans-src="./api/visitor-bans.php"'));
    $hostStats = $file('scripts/host-stats.sh');
    check_bool('BAN: host-stats.sh が stats.json のあとに bans.json を書く(一時ファイルから mv)', strpos($hostStats, 'mv "$TMP" "$OUT_DIR/stats.json"') !== false
        && strpos($hostStats, 'mv "$TMP" "$OUT_DIR/stats.json"') < strpos($hostStats, 'mv "$BANS_TMP" "$OUT_DIR/bans.json"')
        && str_contains($hostStats, 'nft list chain inet km_geoblock pre') && str_contains($hostStats, 'fail2ban-client status "$_jail"')
        && str_contains($hostStats, "''|*[!A-Za-z0-9_.-]*) continue ;;"));
    check_bool('BAN: 国ごと拒否の色は当てているときだけ・BAN の IP も位置を引く(スイッチが入っているとき)', str_contains($visitorsJs, 'bans?.geoblock?.active ? bans.geoblock.deny || [] : []')
        && str_contains($visitorsJs, 'bannedList().forEach(({ ip }) => {'));
    check_bool('cron(版 8)が 30 日を過ぎた記録を消す', str_contains($hostUpdates, "find \$PATH_ROOT/run/visitlog -maxdepth 1 -type f -name 'visit-*.jsonl' -mtime +30 -delete"));
    // 振る舞い: 上限を超えたら新しい方を残し、古い方は行の頭から送る
    require_once __DIR__ . '/../lib/visit-log.php';
    $visitTmp = sys_get_temp_dir() . '/km-visit-check-' . bin2hex(random_bytes(4));
    @mkdir($visitTmp);
    $dayOld = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
    $dayNew = (new DateTimeImmutable('today'))->format('Y-m-d');
    file_put_contents("{$visitTmp}/visit-{$dayOld}.jsonl", "{\"n\":1}\n{\"n\":2}\n{\"n\":3}\n");
    file_put_contents("{$visitTmp}/visit-{$dayNew}.jsonl", "{\"n\":4}\n");
    file_put_contents("{$visitTmp}/visit-other.txt", "x\n");
    $visitFiles = km_visit_log_files(7, $visitTmp);
    $visitPlan = km_visit_log_plan($visitFiles, 20);
    ob_start();
    km_visit_log_send($visitPlan['plan']);
    $visitOut = (string) ob_get_clean();
    check_bool('記録: 日付の名前のファイルだけを古い順に拾う', count($visitFiles) === 2 && str_ends_with($visitFiles[1], "visit-{$dayNew}.jsonl"));
    check_bool('記録: 上限を超えたら古い方を行の頭から切り、新しい方は全部送る', $visitPlan['truncated'] === true && $visitOut === "{\"n\":3}\n{\"n\":4}\n", json_encode($visitOut));
    check_bool('記録: 上限に収まれば全部・切らない', km_visit_log_plan($visitFiles)['truncated'] === false);
    foreach (glob("{$visitTmp}/*") ?: [] as $visitFile) {
        unlink($visitFile);
    }
    @rmdir($visitTmp);

    /*
     * ## ノードのクラウドバックアップ(2026-10-06。lib/map-backup.php・api/map-backup.php)
     * 管理者だけ。中身は読み解かず形だけ確かめる。保存名は表からだけ。20 件を残す。
     */
    km_check_heading('map-backup: 管理者だけ・形だけ確かめる・保存名は表から・20 件');
    $backupApi = $file('src/api/map-backup.php');
    // 2026-10-23 から学習の協力者も置ける。協力者が見る・戻す・消すのは自分が置いた分だけ
    check_bool('管理者か学習の協力者の権限を、どの方法でも最初に確かめる', str_contains($backupApi, "\$principal = logto_require_principal();\n\$isAdmin = (\$principal['is_admin'] ?? 0) === 1;\nif (!\$isAdmin && (\$principal['is_learner'] ?? false) !== true) {")
        && strpos($backupApi, "(\$principal['is_learner'] ?? false) !== true") < strpos($backupApi, 'km_map_backup_list('));
    check_bool('協力者は自分の分だけ(一覧・中身・消す)', str_contains($backupApi, "km_map_backup_list(\$pdo, \$ownerFilter)")
        && substr_count($backupApi, "\$ownerFilter !== null && !hash_equals(\$ownerFilter, (string) \$row['user_sub'])") === 2);
    check_bool('置いた・戻した・消したをタイムラインに残す', str_contains($backupApi, "'map.backup_create'") && str_contains($backupApi, "'map.backup_restore'") && str_contains($backupApi, "'map.backup_delete'"));
    check_bool('nginx: 専用の口で本文を 16m まで・回数を数える', str_contains($nginx, "location = /api/map-backup.php {\n        client_max_body_size 16m;\n        limit_req zone=km_api burst=120 nodelay;"));
    require_once __DIR__ . '/../lib/map-backup.php';
    $backupOk = static function (string $json): bool {
        try {
            km_map_backup_validate($json);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    };
    check_bool('形: アプリのノードの書き出しは通す', $backupOk('{"format":"kosenmap-map","formatVersion":2,"exportedAt":"2026-10-06T00:00:00+09:00","map":{"nodes":[]}}'));
    check_bool('形: 設定・全体・Wi-Fi 学習・壊れた JSON・空は断る', !$backupOk('{"format":"kosenmap-settings","formatVersion":2}')
        && !$backupOk('{"format":"kosenmap-backup","formatVersion":2,"map":{}}')
        && !$backupOk('{"format":"kosenmap-learning","formatVersion":1}')
        && !$backupOk('{"format":"kosenmap-map","formatVersion":2,"map":{')
        && !$backupOk(''));
    $pathRejected = static function (string $name): bool {
        try {
            km_map_backup_path(['stored_name' => $name]);
            return false;
        } catch (RuntimeException) {
            return true;
        }
    };
    check_bool('保存名は 32 桁の 16 進 + .json.gz だけ(../ などを通さない)', $pathRejected('../../config/db.local.php') && $pathRejected('a.json.gz')
        && !$pathRejected(str_repeat('a', 32) . '.json.gz'));
    check_bool('残すのは置いた人ごとに新しい方から 20 件', KM_MAP_BACKUP_KEEP === 20 && str_contains($file('src/lib/map-backup.php'), "if (\$seen[\$owner] > \$keep) {\n            km_map_backup_remove_row(\$pdo, \$row);"));
    check('パスワード付きの包みも受け、見分ける(中身は読まない)', [true, false, true],
        [km_map_backup_is_locked('{"format":"kosenmap-encrypted","formatVersion":1,"inner":"kosenmap-map","data":"x"}'), km_map_backup_is_locked('{"format":"kosenmap-map","formatVersion":2}'),
         $backupOk('{"format":"kosenmap-encrypted","formatVersion":1,"inner":"kosenmap-map","data":"x"}')]);

    /*
     * ## ストリートビューの写真(2026-10-06。lib/panorama.php・api/panorama.php)
     * 中身で形を確かめる・ハッシュが鍵・配る地図の地点の分だけ・チェックサムの外。
     */
    km_check_heading('panorama: 中身で確かめる・ハッシュが鍵・配る地点の分だけ・チェックサムの外');
    require_once __DIR__ . '/../lib/panorama.php';
    require_once __DIR__ . '/../lib/app-map.php';
    $panoOk = static function (string $bytes): bool {
        try {
            km_panorama_inspect($bytes);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    };
    if (function_exists('imagecreatetruecolor')) {
        $img = imagecreatetruecolor(512, 256);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $tiny = imagecreatetruecolor(64, 32);
        ob_start();
        imagejpeg($tiny);
        $tinyJpeg = (string) ob_get_clean();
        check_bool('形: JPEG(512×256)は通し、PNG・小さすぎ・画像でないものは断る', $panoOk($jpeg) && !$panoOk($png) && !$panoOk($tinyJpeg) && !$panoOk('<?php echo 1;'));
    } else {
        check_bool('形: GD が無いので画像の検査は飛ばす(本番の web には GD がある)', true);
    }
    check_bool('地点の uuid は英数字・ハイフン・下線だけ', km_panorama_valid_node_uuid('3f2a-b_9') && !km_panorama_valid_node_uuid('../x') && !km_panorama_valid_node_uuid(''));
    $panoMap = json_decode('{"version":8,"nodes":[{"uuid":"n1"},{"uuid":"n2"}],"lines":[]}');
    check_bool('配る地図の地点だけを拾う', km_panorama_uuids_of_map($panoMap) === ['n1' => true, 'n2' => true]);
    $panoBody = km_app_map_build_package($panoMap, 'kosen-main', 3, '2026-10-06T00:00:00+09:00', '2026-11-06T00:00:00+09:00', null, true, null, 'visitor', null, ['123' => [['id' => 1, 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'width' => 512, 'height' => 256, 'heading' => null]]]);
    $panoDecoded = json_decode((string) $panoBody, true);
    $panoMapJson = json_encode($panoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check_bool('配信: 一覧は地図の外・数字だけの uuid でも object・チェックサムは地図だけ', is_array($panoDecoded) && isset($panoDecoded['panoramas']['123'][0]['sha256'])
        && str_contains((string) $panoBody, '"panoramas":{"123":') && $panoDecoded['checksum'] === 'sha256:' . hash('sha256', (string) $panoMapJson)
        && !isset($panoDecoded['map']['panoramas']));
    $noPanoBody = km_app_map_build_package($panoMap, 'kosen-main', 3, '2026-10-06T00:00:00+09:00', '2026-11-06T00:00:00+09:00', null, true, null, 'visitor', null, []);
    check_bool('配信: 写真が無ければ載せない(今までと同じ形)', !str_contains((string) $noPanoBody, 'panoramas'));
    // 診断の「情報」(2026-10-05): PHP が書いた設定は OPcache にすぐ読み直させる(錠・パスワード・配信の停止が 2 秒遅れない)
    check_bool('設定を書いたら OPcache に読み直させる(地図の錠・アプリの配信)', substr_count($file('src/lib/map-access.php'), 'km_opcache_forget($path);') === 2
        && substr_count($file('src/lib/app-map.php'), 'km_opcache_forget($path);') === 2 && str_contains($file('src/lib/opcache.php'), '@opcache_invalidate($path, true);'));
    // 診断の「情報」(2026-10-05): 配布物の版番号は APK の中から読み、入力と照らす(lib/apk-version.php)
    require_once __DIR__ . '/../lib/apk-version.php';
    $axml = static function (int $code, bool $deflate) : string {
        // 文字列表(UTF-16。"versionCode" 1 つ)+ 要素の始まり(属性 1 つ: 名前 0・型 0x10・値 $code)
        $str = 'versionCode';
        $u16 = pack('v', strlen($str)) . mb_convert_encoding($str, 'UTF-16LE', 'UTF-8') . "\0\0";
        $pool = pack('vvVVVVVV', 0x0001, 28, 28 + 4 + strlen($u16), 1, 0, 0, 28 + 4, 0) . pack('V', 0) . $u16;
        $attr = pack('VVVvCCV', 0xFFFFFFFF, 0, 0xFFFFFFFF, 8, 0, 0x10, $code);
        $tag = pack('vvVVV', 0x0102, 16, 16 + 20 + strlen($attr), 1, 0xFFFFFFFF) . pack('VVvvvvvv', 0xFFFFFFFF, 0, 20, 20, 1, 0, 0, 0) . $attr;
        $body = $pool . $tag;
        $xml = pack('vvV', 0x0003, 8, 8 + strlen($body)) . $body;
        $data = $deflate ? (string) gzdeflate($xml) : $xml;
        $name = 'AndroidManifest.xml';
        $crc = crc32($xml);
        $method = $deflate ? 8 : 0;
        $local = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, $method, 0, 0, $crc, strlen($data), strlen($xml), strlen($name), 0) . $name . $data;
        $central = "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $method, 0, 0, $crc, strlen($data), strlen($xml), strlen($name), 0, 0, 0, 0, 0, 0) . $name;
        return $local . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($central), strlen($local), 0);
    };
    $apkTmp = tempnam(sys_get_temp_dir(), 'kmapk');
    file_put_contents($apkTmp, $axml(401816, true));
    $apkDeflated = km_apk_version_code($apkTmp);
    file_put_contents($apkTmp, $axml(7, false));
    $apkStored = km_apk_version_code($apkTmp);
    file_put_contents($apkTmp, 'not a zip');
    $apkBroken = km_apk_version_code($apkTmp);
    unlink($apkTmp);
    check_bool('APK の版番号: deflate・格納のどちらでも読め、壊れたものは null', $apkDeflated === 401816 && $apkStored === 7 && $apkBroken === null,
        var_export([$apkDeflated, $apkStored, $apkBroken], true));
    $distLib = $file('src/lib/distributables.php');
    check_bool('配布物: 入力と APK の版番号が違えば断り、空なら APK の値を使う', str_contains($distLib, '$versionCode !== null && $versionCode !== $apkVersionCode')
        && str_contains($distLib, '$versionCode = $apkVersionCode;'));

    /*
     * ## アプリの受け箱(2026-10-09。lib/apk-inbox.php・scripts/host-apk-inbox.sh・scripts/apk-push.ps1)
     * PC → GitHub の非公開リポジトリ → ホストの root の cron が取る → web が検めて受け箱へ → 管理者が押して公開。
     * サーバーに外から APK を受け取る口は作らない。試しの APK(パッケージ名・版番号・署名の塊)を組み立てて検めを試す。
     */
    km_check_heading('apk-inbox: アプリの受け箱(GitHub から・押して公開)');
    require_once __DIR__ . '/../lib/apk-inbox.php';
    $lp = static fn (string $bytes): string => pack('V', strlen($bytes)) . $bytes;
    $makeApk = static function (int $code, string $package, ?string $cert) use ($lp): string {
        // 文字列表: 0 versionCode・1 package・2 パッケージ名(UTF-16)
        $strs = ['versionCode', 'package', $package];
        $data = '';
        $offsets = '';
        foreach ($strs as $s) {
            $offsets .= pack('V', strlen($data));
            $data .= pack('v', strlen($s)) . mb_convert_encoding($s, 'UTF-16LE', 'UTF-8') . "\0\0";
        }
        $start = 28 + 4 * count($strs);
        $pool = pack('vvVVVVVV', 0x0001, 28, $start + strlen($data), count($strs), 0, 0, $start, 0) . $offsets . $data;
        $attrs = pack('VVVvCCV', 0xFFFFFFFF, 0, 0xFFFFFFFF, 8, 0, 0x10, $code) . pack('VVVvCCV', 0xFFFFFFFF, 1, 2, 8, 0, 0x03, 2);
        $tag = pack('vvVVV', 0x0102, 16, 16 + 20 + strlen($attrs), 1, 0xFFFFFFFF) . pack('VVvvvvvv', 0xFFFFFFFF, 0, 20, 20, 2, 0, 0, 0) . $attrs;
        $xml = pack('vvV', 0x0003, 8, 8 + strlen($pool . $tag)) . $pool . $tag;
        $name = 'AndroidManifest.xml';
        $crc = crc32($xml);
        $local = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, strlen($xml), strlen($xml), strlen($name), 0) . $name . $xml;
        $block = '';
        if ($cert !== null) {
            // v2: 署名者の並び → 署名者(署名した中身・署名・公開鍵)→ 署名した中身(要約・証明書の並び・属性)
            $signedData = $lp('') . $lp($lp($cert)) . $lp('');
            $value = $lp($lp($lp($signedData) . $lp('') . $lp('')));
            $pairs = pack('P', 4 + strlen($value)) . pack('V', 0x7109871a) . $value;
            $size = strlen($pairs) + 8 + 16;
            $block = pack('P', $size) . $pairs . pack('P', $size) . 'APK Sig Block 42';
        }
        $central = "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc, strlen($xml), strlen($xml), strlen($name), 0, 0, 0, 0, 0, 0) . $name;
        return $local . $block . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($central), strlen($local . $block), 0);
    };
    $inboxTmp = sys_get_temp_dir() . '/km-apk-inbox-' . bin2hex(random_bytes(4));
    @mkdir($inboxTmp);
    $put = static function (string $name, string $bytes) use ($inboxTmp): string {
        file_put_contents("$inboxTmp/$name", $bytes);
        return "$inboxTmp/$name";
    };
    $cur = $put('current.apk', $makeApk(1000005, 'com.ito.kosenmap', 'real-cert'));
    $new = $put('new.apk', $makeApk(1000006, 'com.ito.kosenmap', 'real-cert'));
    $evil = $put('evil.apk', $makeApk(1000006, 'com.ito.kosenmap', 'other-cert'));
    $admin = $put('admin.apk', $makeApk(1000006, 'com.ito.kosenmap.admin', 'real-cert'));
    $unsigned = $put('unsigned.apk', $makeApk(1000006, 'com.ito.kosenmap', null));
    check_bool('APK の読み取り: パッケージ名・版番号・署名の証明書の SHA-256', km_apk_package_name($new) === 'com.ito.kosenmap'
        && km_apk_version_code($new) === 1000006 && km_apk_signer_sha256($new) === hash('sha256', 'real-cert')
        && km_apk_signer_sha256($unsigned) === null && km_apk_signer_sha256($put('broken.apk', 'not a zip')) === null);
    $current = ['storedName' => 'current.apk', 'versionCode' => 1000005];
    $inspect = static fn (string $path, int $code = 1000006, string $slug = 'apk') => km_apk_inbox_inspect(
        $path, ['slug' => $slug, 'name' => basename($path), 'sha256' => hash_file('sha256', $path)], $code, $current, $inboxTmp
    )['problems'];
    check_bool('受け箱: 同じ鍵・版が上がる・枠のパッケージ名なら通す(初めての配布も通す)', $inspect($new) === []
        && km_apk_inbox_inspect($new, ['slug' => 'apk', 'name' => 'new.apk', 'sha256' => hash_file('sha256', $new)], 1000006, null)['problems'] === []);
    check_bool('受け箱: 違う鍵・署名なし・枠の取り違え・版の下げ・latest.json と違う版を断る', count($inspect($evil)) === 1 && str_contains($inspect($evil)[0], '署名の鍵が今の配布物と違います')
        && $inspect($unsigned) !== [] && str_contains(implode('', $inspect($admin)), 'パッケージ名が違います')
        && str_contains(implode('', $inspect($cur, 1000005)), '版番号が今の配布物') && str_contains(implode('', $inspect($new, 1000009)), 'latest.json'));
    check_bool('受け箱: SHA-256 が latest.json と違えば断る', str_contains(implode('', km_apk_inbox_inspect($new, ['slug' => 'apk', 'name' => 'new.apk', 'sha256' => str_repeat('0', 64)], 1000006, $current, $inboxTmp)['problems']), 'SHA-256'));
    array_map('unlink', glob("$inboxTmp/*") ?: []);
    @rmdir($inboxTmp);
    $manifestProblems = km_apk_inbox_read_manifest('{"format":"kosenmap-apk","versionCode":1000006,"files":[{"slug":"apk","name":"../x.apk"},{"slug":"apk_admin","name":"a/b.apk"}]}')['problems'];
    check_bool('受け箱: latest.json の名前にパスを入れさせない・2 つ揃っていなければ断る', in_array('latest.json の files に知らない・重なった・名前の不正な項目があります', $manifestProblems, true)
        && in_array('一般用の APK がありません', $manifestProblems, true) && km_apk_inbox_read_manifest('[]')['problems'] === ['latest.json の形が違います']);
    check_bool('受け箱: 版の表示は versionName の頭・無ければ 1000000 を引く', km_apk_inbox_label(1000005, '5 (261009.1048)') === '5' && km_apk_inbox_label(1000012, null) === '12');
    $inboxLib = $file('src/lib/apk-inbox.php');
    $inboxPage = $file('src/admin/apk-inbox.php');
    check_bool('受け箱: 開いただけでは公開しない(公開・取り消し・戻すは CSRF つきの POST だけ)', str_contains($inboxPage, "if (!km_csrf_verify()) {")
        && strpos($inboxPage, 'km_apk_inbox_publish(') > strpos($inboxPage, "=== 'POST'") && substr_count($inboxPage, 'km_csrf_field()') === 3
        && str_contains($inboxPage, "require __DIR__ . '/_inc/guard.php';"));
    check_bool('受け箱: 公開の前にもう一度検め、片方が失敗したらもう片方も戻す', strpos($inboxLib, '$recheck = km_apk_inbox_inspect(') < strpos($inboxLib, 'km_dist_store_file(')
        && str_contains($inboxLib, "\$prev === null ? km_dist_delete(\$pdo, \$slug) : km_dist_restore(\$pdo, \$slug, \$prev, \$by);"));
    check_bool('受け箱: URL の印は DB に SHA-256 だけ・nginx はこのページのクエリをログに残さない', str_contains($inboxLib, "hash('sha256', \$token)")
        && str_contains($file('nginx/default.conf.template'), "location = /admin/apk-inbox.php {\n        access_log /var/log/nginx/access.log km_no_query;"));
    check_bool('受け箱: CLI だけ・ホストの置き場の外は読まない', str_contains($file('src/scripts/apk-inbox.php'), "if (PHP_SAPI !== 'cli') {")
        && str_contains($file('src/scripts/apk-inbox.php'), "str_starts_with(\$real, '/var/www/apkinbox/')"));
    check_bool('受け箱: web にはホストの置き場を読み取り専用で渡す(compose と VPS の両方)', str_contains($file('compose.yaml'), '- ./run/apk-inbox:/var/www/apkinbox:ro')
        && str_contains($file('compose.vps.yaml'), '- ./run/apk-inbox:/var/www/apkinbox:ro'));
    $inboxSh = $file('scripts/host-apk-inbox.sh');
    check_bool('受け箱(ホスト): 設定はシェルとして実行しない・鍵は -H @ファイル・www-data で web に渡す', !preg_match('/^\s*(\.|source)\s+"?\$CONF/m', $inboxSh)
        && str_contains($inboxSh, '-H @"$WORK/auth"') && !str_contains($inboxSh, 'Bearer $GITHUB_TOKEN"') && str_contains($inboxSh, 'docker compose exec -T -u www-data web php scripts/apk-inbox.php stage'));
    check_bool('受け箱(ホスト): cron 版 9 で 5 分ごと・失敗だけ送る・配備物と実行ビットの一覧にある', preg_match('/^CRON_VERSION=(9|[1-9][0-9])$/m', $file('scripts/host-updates-setup.sh')) === 1
        && str_contains($file('scripts/host-updates-setup.sh'), '*/5 * * * *  root  $SEND_LOG_SCRIPT --path $PATH_ROOT --label アプリの受け箱 --only-failure --run "$APK_INBOX_SCRIPT --path $PATH_ROOT"')
        && str_contains($file('scripts/deploy-to-host.ps1'), "'./scripts/host-apk-inbox.sh'") && str_contains($file('scripts/host-setup.sh'), 'host-apk-inbox.sh\'')
        && !is_file(km_check_repo_root() . '/apk-inbox.local.conf') && is_file(km_check_repo_root() . '/apk-inbox.local.conf.example'));
    $pushPs = $file('scripts/apk-push.ps1');
    check_bool('受け箱(PC): 2 つが同じ鍵・枠のパッケージ名・同じ版でなければ送らない・3 つのファイルだけ', str_contains($pushPs, "throw '2 つが違う鍵で署名されています'")
        && str_contains($pushPs, "\$allowed = @('KosenMap-admin.apk', 'KosenMap.apk', 'README.md', 'latest.json')") && str_starts_with($pushPs, "\u{FEFF}"));
    /*
     * ## Logto の確認コード欄の自動入力(2026-10-12。nginx/km/logto-otp-autocomplete.js)
     * nginx が Logto Core(auth.<ドメイン> の 443。2026-10-19 までは 3001)の HTML にスクリプトを差し込み、1 つ目の枠に autocomplete="one-time-code" を付ける。
     */
    $otpJs = $file('nginx/km/logto-otp-autocomplete.js');
    $logtoAuth = '';
    if (preg_match('/^server \{\n    listen 443 ssl;\n    listen \[::\]:443 ssl;\n    http2 on;\n    server_name \$\{KM_AUTH_DOMAIN\};.*?\n\}\n/ms', $file('nginx/default.conf.template'), $logtoAuthMatch) === 1) {
        $logtoAuth = (string) preg_replace('/^\s*#.*$/m', '', $logtoAuthMatch[0]);
    }
    check_bool('自動入力: Logto Core(auth の 443)だけで HTML に差し込み、Logto には圧縮させない', $logtoAuth !== ''
        && str_contains($logtoAuth, "sub_filter '</head>' '<script src=\"/km/logto-otp-autocomplete.js\" defer></script></head>';")
        && str_contains($logtoAuth, 'proxy_set_header Accept-Encoding "";')
        && str_contains($logtoAuth, "location = /km/logto-otp-autocomplete.js {\n        alias /etc/nginx/km/logto-otp-autocomplete.js;")
        && substr_count($file('nginx/default.conf.template'), 'sub_filter \'</head>\'') === 1);
    check_bool('自動入力: スクリプトは 1 つ目の枠に one-time-code を付けるだけ(値を読まない・送らない)', $otpJs !== ''
        && str_contains($otpJs, 'input[inputmode="numeric"][data-id="0"]') && str_contains($otpJs, "setAttribute('autocomplete', 'one-time-code')")
        && !str_contains($otpJs, '.value') && !preg_match('/fetch\(|XMLHttpRequest|sendBeacon|localStorage|cookie/', $otpJs)
        && str_contains($file('scripts/deploy-to-host.ps1'), "'./nginx/km/logto-otp-autocomplete.js'"));

    /*
     * ## サインインと通知を 443 番に寄せる(2026-10-19、利用者の指示)
     * 学校の回線は 443 番以外を通さない(利用者が確かめた。解除は難しい)。:3001(Logto)と :6001(Soketi)では、
     * 学内から誰もサインインできず、管理画面のチャットと死活監視もいつも「未接続」だった。
     *   - Logto: auth.<ドメイン> の 443 番(nginx の auth の server)。.env の KM_AUTH_DOMAIN で切り替える(無ければ :3001)
     *   - Soketi: 管理画面の 443 番の /ws/app/ → Soketi の /app/
     */
    km_check_heading('443 番へ寄せる: サインイン(auth.<ドメイン>)と通知(/ws/app/)');
    $vps = $file('compose.vps.yaml');
    $switch = '${LOGTO_ENDPOINT:-https://${KM_AUTH_DOMAIN:-auth.${KM_DOMAIN}}}';
    check_bool('web の LOGTO_ENDPOINT と logto の ENDPOINT は同じ導き方(発行元 iss が食い違わない)',
        str_contains($vps, "LOGTO_ENDPOINT: {$switch}") && str_contains($vps, "ENDPOINT: {$switch}")
        && substr_count($vps, $switch) === 2 && !str_contains($vps, 'https://${KM_DOMAIN}:3001}' . "\n"));
    check_bool('nginx には KM_AUTH_DOMAIN をいつも渡す(空だと server_name が壊れて起動しない)',
        str_contains($vps, 'KM_AUTH_DOMAIN: ${KM_AUTH_DOMAIN:-auth.${KM_DOMAIN}}')
        && str_contains($file('compose.yaml'), 'KM_AUTH_DOMAIN: ${KM_AUTH_DOMAIN:-auth.${KM_DOMAIN:-192.168.3.29}}'));
    check_bool('auth の server: 443 番・Logto Core へ・書き込みだけ数える・送信元は置き換え・Logto の HSTS を消す・100 本', $logtoAuth !== ''
        && str_contains($logtoAuth, 'proxy_pass http://km_logto_core;') && str_contains($logtoAuth, 'proxy_set_header X-Forwarded-Port 443;')
        && str_contains($logtoAuth, 'proxy_set_header X-Forwarded-For $remote_addr;') && str_contains($logtoAuth, 'proxy_hide_header Strict-Transport-Security;')
        && str_contains($logtoAuth, 'limit_req zone=km_auth burst=30 nodelay;') && str_contains($logtoAuth, 'limit_conn km_conn 100;')
        && !str_contains($logtoAuth, 'auth_request'));
    // 443 の本体の server(先頭の 1 つ。auth の server はその後ろ)
    $mainHttps = '';
    if (preg_match('/^server \{\n    listen 443 ssl;.*?\n\}\n/ms', $file('nginx/default.conf.template'), $mainMatch) === 1) {
        $mainHttps = $mainMatch[0];
    }
    $wsLocation = '';
    if (preg_match('/location \^~ \/ws\/app\/ \{.*?\n    \}\n/s', $mainHttps, $wsMatch) === 1) {
        $wsLocation = $wsMatch[0];
    }
    check_bool('/ws/app/: 管理画面の Origin だけ・公開側のホストでは 404・Soketi の /app/ へ', $wsLocation !== ''
        && str_contains($wsLocation, "if (\$km_ws_origin_denied) {\n            return 403;")
        && str_contains($wsLocation, "if (\$km_host_role = public) {\n            return 404;")
        && str_contains($wsLocation, 'proxy_pass http://km_soketi/app/;') && str_contains($wsLocation, 'proxy_set_header Upgrade $http_upgrade;'));
    check_bool('/ws/ のほか(配信の HTTP API /apps/ など)は外に出さない', preg_match('#location \^~ /ws/ \{\s*return 404;#', $mainHttps) === 1);
    check_bool('管理用のホストでは /ws/app/ を返す($km_leave_admin_host)', str_contains($file('nginx/default.conf.template'), '|vendor-web/|ws/app/|'));
    $siteLib = $file('src/lib/site.php');
    check_bool('PHP の Logto の入口は LOGTO_ENDPOINT が正本(km_site_url の logto-core)', str_contains($siteLib, "if (\$service === 'logto-core') {\n        return km_site_logto_endpoint() . \$path;")
        && str_contains($siteLib, "\$endpoint = trim((string) getenv('LOGTO_ENDPOINT'));"));
    $wsPages = [];
    foreach (['src/admin/chat.php', 'src/admin/monitor.php'] as $page) {
        $code = $file($page);
        if (str_contains($code, 'wsPort: 6001') || !str_contains($code, 'wsPath: <?= json_encode(km_site_ws_path()') || !str_contains($code, 'wssPort: <?= (int) km_site_ws_port() ?>')) {
            $wsPages[] = $page;
        }
    }
    check('チャットと死活監視は管理画面の 443 番の /ws で張る', [], $wsPages);
    check_bool('CSP の connect-src は WebSocket の宛先を km_site_ws_origin から', str_contains($file('src/lib/csp.php'), '$connect[] = km_site_ws_origin();'));
    $savedEndpoint = getenv('LOGTO_ENDPOINT');
    putenv('LOGTO_ENDPOINT=https://auth.example.jp/');
    $authEndpoint = km_site_logto_endpoint();
    putenv('LOGTO_ENDPOINT=not a url');
    $brokenEndpoint = km_site_logto_endpoint();
    $savedEndpoint === false ? putenv('LOGTO_ENDPOINT') : putenv('LOGTO_ENDPOINT=' . $savedEndpoint);
    check('LOGTO_ENDPOINT があればそれ(末尾のスラッシュは落とす)', 'https://auth.example.jp', $authEndpoint);
    // 2026-10-19 に :3001 の入口を外したので、落とし先も auth.<本体のホスト>(compose の既定と同じ形)
    check_bool('LOGTO_ENDPOINT が壊れていれば https://auth.<本体のホスト> に落とす', str_starts_with($brokenEndpoint, 'https://auth.') && !str_contains($brokenEndpoint, ':3001'));
    $gradle = (string) @file_get_contents('C:/Users/itota/Documents/Test/app/build.gradle.kts');
    if ($gradle === '') {
        check_skip('管理アプリの Logto の入口', 'Android の作業場が無い');
    } else {
        check_bool('管理アプリの Logto の入口の既定は https://auth.<ドメイン>', str_contains($gradle, '?: "https://auth.$kosenmapDomain").trimEnd(\'/\')'));
    }
    /*
     * ## Logto のメールのテンプレート(2026-10-12。docs/logto-email-templates.json。Console へ貼るのは利用者)
     * Gmail などがコードを拾いやすいよう、コードは本文に 1 回だけ・単独の行に置く。URL(verify.php)は載せない。
     */
    $mailTemplates = json_decode($file('docs/logto-email-templates.json'), true);
    $templateTypes = ['SignIn', 'Register', 'ForgotPassword', 'Generic', 'BindNewIdentifier', 'UserPermissionValidation', 'MfaVerification', 'BindMfa', 'OrganizationInvitation'];
    $templateOk = is_array($mailTemplates) && count($mailTemplates) === 9
        && array_column($mailTemplates, 'usageType') === $templateTypes;
    foreach (is_array($mailTemplates) ? $mailTemplates : [] as $template) {
        $content = (string) ($template['content'] ?? '');
        $templateOk = $templateOk && ($template['contentType'] ?? '') === 'text/plain'
            && substr_count($content, '{{code}}') === 1 && str_contains($content, "\n\n{{code}}\n\n")
            && !str_contains($content, 'verify.php') && !str_contains($content, '{{link}}')
            && str_contains((string) ($template['subject'] ?? ''), '(Verification Code)') && !str_contains((string) ($template['subject'] ?? ''), '{{code}}');
    }
    check_bool('Logto のテンプレート: 9 種類・コードは本文に 1 回だけ単独の行・URL なし・件名にコードを出さない', $templateOk);
    // 確認コードの橋渡し verify.php は Old/src/ へ退避した。healthcheck は DB を触らない healthz.php を見る
    $healthz = $file('src/healthz.php');
    check_bool('verify.php は退避済み・healthcheck は healthz.php(DB を触らず、外には 404)', !is_file(km_check_repo_root() . '/src/verify.php')
        && str_contains($file('compose.yaml'), '"http://127.0.0.1/healthz.php"]') && !str_contains($file('compose.yaml'), '/verify.php"')
        && str_contains($healthz, "!== '127.0.0.1'") && !preg_match('/km_db|require/', $healthz)
        && str_contains($file('nginx/default.conf.template'), "location = /healthz.php {\n        return 404;\n    }")
        && !str_contains($file('nginx/default.conf.template'), 'location = /verify.php'));

    /*
     * ## イベントモード: 管理アプリのイベントの取り込み(2026-10-14。lib/map-event-import.php)
     * アプリの MapEvent → Website の重ね合わせ。変換は DB を使わない関数なので、ここで試す。
     */
    require_once __DIR__ . '/../lib/map-event-import.php';
    $uuidOf = static fn (string $c): string => str_repeat($c, 8) . '-' . str_repeat($c, 4) . '-' . str_repeat($c, 4) . '-' . str_repeat($c, 4) . '-' . str_repeat($c, 12);
    $known = [
        str_repeat('a', 32) => ['floor' => '1', 'type' => 'road', 'x' => 100.0, 'y' => 1100.0],
        str_repeat('b', 32) => ['floor' => '1', 'type' => 'room', 'x' => 500.0, 'y' => 500.0],
        str_repeat('c', 32) => ['floor' => '2', 'type' => 'road', 'x' => 10.0, 'y' => 10.0],
        str_repeat('d', 32) => ['floor' => '1', 'type' => 'wall', 'x' => 101.0, 'y' => 1101.0],
    ];
    $appEvent = json_decode((string) json_encode([
        'uuid' => 'ev-1', 'name' => '高専祭', 'startAtMillis' => 1792540800000, 'endAtMillis' => null,
        'closedLineKeys' => ['1F:' . $uuidOf('b') . ':' . $uuidOf('a'), '1F:' . $uuidOf('a') . ':' . $uuidOf('e'), 'broken'],
        'closedNodeUuids' => [$uuidOf('b')],
        'closedFloors' => ['2F'],
        'places' => [
            ['uuid' => 'p1', 'name' => 'たこ焼き', 'floor' => '1F', 'x' => 102.0, 'y' => 98.0, 'category' => '模擬店', 'note' => '', 'staffOnly' => false],
            ['uuid' => 'p2', 'name' => '本部', 'floor' => '1F', 'x' => 1.0, 'y' => 1.0, 'category' => '', 'note' => '', 'staffOnly' => true],
            ['uuid' => 'p3', 'name' => 'くじ引き', 'floor' => '1F', 'x' => 1.0, 'y' => 1.0, 'category' => 'くじ', 'note' => '2回まで', 'staffOnly' => false],
        ],
        'nameOverrides' => [$uuidOf('b') => 'お化け屋敷', $uuidOf('e') => '無い部屋'],
        'hiddenNodeUuids' => [$uuidOf('a')],
    ]));
    $fromApp = km_map_event_from_app($appEvent, $known);
    check_bool('取り込み: 線の通行止めは DB の id の組・向きを揃える・無い地点と読めないキーは数える', $fromApp['closedEdges'] === [[str_repeat('a', 32), str_repeat('b', 32)]]
        && $fromApp['skipped']['badLineKey'] === 1);
    check_bool('取り込み: 地点の通行止め + 階の通行止めはその階の地点を全部閉じる', $fromApp['closedNodes'] === [str_repeat('b', 32), str_repeat('c', 32)]);
    check_bool('取り込み: 名前の上書きと出さない地点(Website に無い地点は飛ばして数える)', $fromApp['aliases'] === [str_repeat('b', 32) => 'お化け屋敷']
        && $fromApp['hidden'] === [str_repeat('a', 32)] && $fromApp['skipped']['unknownNode'] === 2);
    $firstPoi = $fromApp['pois'][0] ?? [];
    check_bool('取り込み: 一時地点は種別を言葉で寄せ・Y を反転・同じ階の一番近い通れる地点に繋ぐ(壁には繋がない)', count($fromApp['pois']) === 2
        && ($firstPoi['category'] ?? '') === 'food' && ($firstPoi['y'] ?? 0) === 1102 && ($firstPoi['anchorNodeId'] ?? '') === str_repeat('a', 32)
        && ($fromApp['pois'][1]['category'] ?? '') === 'other' && ($fromApp['pois'][1]['note'] ?? '') === 'くじ 2回まで');
    check_bool('取り込み: スタッフ限定の一時地点は入れない・期間はミリ秒から', $fromApp['skipped']['staffOnly'] === 1
        && $fromApp['startsAt'] === date('Y-m-d H:i:s', 1792540800) && $fromApp['endsAt'] === null);
    $eventsLib = $file('src/lib/map-events.php');
    check_bool('取り込み: 新しいイベントは無効のまま・印は別表(ALTER を使わない)・中身は丸ごと入れ替える', str_contains($file('src/lib/map-event-import.php'), 'VALUES (?, ?, ?, 0, 1, NULL, NULL, NOW(), NOW())')
        && str_contains($eventsLib, 'CREATE TABLE IF NOT EXISTS km_map_event_sources') && !preg_match('/ALTER\s+TABLE/i', $eventsLib)
        && str_contains($file('src/lib/map-event-import.php'), 'foreach (KM_MAP_EVENT_CHILD_TABLES as $table) {'));
    // 2026-10-23 から、アプリから取り込んだイベントも Website で直せる(利用者の指示)。直した時刻を残し、取り込み直す前に知らせる
    check_bool('イベントを直す 5 つの操作すべてで、Website で直した時刻を残す・取り込み直すと消える', substr_count($file('src/admin/api/map-edit.php'), 'km_map_event_touch_web_edit($pdo, $eventId);') === 5
        && in_array('km_map_event_web_edits', KM_MAP_EVENT_CHILD_TABLES, true)
        && str_contains($file('src/admin/map-events.php'), "if (\$existing !== null && (\$existing['webEditedAt'] ?? null) !== null) {"));
    check_bool('Website の地図編集で「表示しない」を切り替えられる・記録に文言', str_contains($file('src/admin/api/map-edit.php'), "case 'event.hidden.toggle': {")
        && str_contains($file('src/admin/assets/js/map-editor.js'), "action: 'event.hidden.toggle'") && isset(KM_ADMIN_LOG_ACTION_LABELS['map.event_hidden']));
    require_once __DIR__ . '/../lib/app-map-convert.php';
    $webEventMap = json_decode('{"nodes":[{"uuid":"9d1cebb3-9aa6-4fca-8f08-9ca965237b9e","floor":"1F"},{"uuid":"0f8fad5b-d9cb-469f-a165-70867728950e","floor":"1F"}]}');
    $webEvent = km_app_map_events_from_web([
        'events' => [['id' => 1, 'name' => '祭', 'endsAt' => null]],
        'closedNodes' => [], 'closedEdges' => [],
        'aliases' => ['9d1cebb39aa64fca8f089ca965237b9e' => '受付'],
        'hiddenNodes' => ['0f8fad5bd9cb469fa16570867728950e' => true],
        'pois' => [['id' => 7, 'floor' => '1', 'name' => 'たこ焼き', 'category' => 'food', 'x' => 10, 'y' => 20, 'anchorNodeId' => '9d1cebb39aa64fca8f089ca965237b9e', 'note' => '']],
        'onlyEventNodes' => false,
    ], $webEventMap)['events'][0];
    check('Web → アプリ: 臨時の名前・出さない地点・イベントの地点だけ・一時地点の接続も渡す',
        [['9d1cebb3-9aa6-4fca-8f08-9ca965237b9e' => '受付'], ['0f8fad5b-d9cb-469f-a165-70867728950e'], false, [['placeUuid' => 'web-poi-7', 'nodeUuid' => '9d1cebb3-9aa6-4fca-8f08-9ca965237b9e']]],
        [(array) $webEvent['nameOverrides'], $webEvent['hiddenNodeUuids'], $webEvent['onlyEventNodes'], $webEvent['placeLinks']]);
    $eventsApi = $file('src/api/map-events.php');
    check_bool('イベントを管理アプリへ渡す口は管理者だけ・GET だけ・1 イベントずつ(有効かは問わない)', str_contains($eventsApi, "require_method('GET');")
        && str_contains($eventsApi, 'logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);')
        && str_contains($file('src/lib/map-event-export.php'), 'km_map_event_overlay($pdo, $id, true)')
        && str_contains($eventsLib, '$events = $onlyPreview ? [] : km_map_event_active($pdo);'));
    check_bool('取り込み: アプリで道へつないだ一時地点は、そのつなぎ先を案内先にする', str_contains($file('src/lib/map-event-import.php'), '$linkedTo[$placeUuid] = $toId($nodeUuid);'));
    check_bool('出さない地点の表が無くても重ね合わせ(通行止め)は続ける', (bool) preg_match('/try \{\s*\$stmt = \$pdo->prepare\("SELECT node_id AS nodeId FROM km_map_event_hidden/', $eventsLib));
    $indexPage = $file('src/index.php');
    $appJs = $file('src/Main/app.js');
    // 地図の上の案内の帯は 2026-10-23 に外した(利用者の指示)。案内はダウンロード・設定の中(#km-event-section)
    check_bool('公開ページ: イベントの案内の帯は出さず設定の中に・?room= は字の形だけ確かめて DB には渡さない', !str_contains($indexPage, 'class="km-event-banner"') && str_contains($indexPage, 'id="km-event-section"')
        && str_contains($indexPage, "preg_match('/^[0-9A-Fa-f-]{32,36}\$/', \$roomParam) === 1")
        && str_contains($indexPage, '<meta name="km-focus-room" content="<?= km_home_e($kmFocusRoom) ?>">')
        && !preg_match('/km_db\(\)[^;]*\$room/', $indexPage));
    check_bool('公開ページ: 名前は置き換え・出さない地点は描かず候補にも出さない・QR の部屋を開く', str_contains($appJs, "if (node.alias) {\n            return node.alias;\n        }")
        && str_contains($appJs, 'if (node.hidden && !window.isAdmin) continue;') && str_contains($appJs, "        if (node.hidden) return false;")
        && str_contains($appJs, 'window.setTimeout(focusRoomFromUrl, 0);'));
    // 診断 W-54(2026-10-05): 見取り図の口も、錠を見る前に仮アカウントを表から確かめ直す(セッションを閉じる前に)
    $floorImage = $file('src/api/floor-image.php');
    check_bool('W-54: 見取り図の口は錠を見る前に km_map_guest_verify() を呼ぶ', strpos($floorImage, 'km_map_guest_verify();') !== false
        && strpos($floorImage, 'km_map_guest_verify();') < strpos($floorImage, '$viewUnlocked = km_map_view_unlocked(')
        && strpos($floorImage, 'km_map_guest_verify();') < strpos($floorImage, 'session_write_close();'));

    /*
     * ## アプリへ配る地図の作り置き(2026-10-06。lib/app-map-cache.php。計画 E2)
     * 前 + 作り置き + 後ろ が、その場で組み立てた応答と**同じ文字列**になること。元を差し替えたら作り直すこと。
     */
    require_once __DIR__ . '/../lib/app-map-cache.php';
    $cacheTmp = sys_get_temp_dir() . '/km-appmap-check-' . bin2hex(random_bytes(4));
    @mkdir($cacheTmp);
    $cacheSrc = "{$cacheTmp}/src.json";
    file_put_contents($cacheSrc, '{"version":9,"nodes":[{"uuid":"a","title":"日本語 <x>","occupantName":"架空 太郎"},{"uuid":"b","occupantName":"架空 次郎"},'
        . '{"uuid":"t","title":"教員室","subtitle":"9-999","occupantName":"架空 三郎"}],"lines":[],'
        . '"events":[{"uuid":"e","places":[{"uuid":"p1","staffOnly":true},{"uuid":"p2","staffOnly":false}]}]}');
    $cacheConsent = ['a'];
    $cacheMember = km_app_map_content_level(false, true, false, true, $cacheConsent);
    $cacheLevels = ['public', 'event', 'member', $cacheMember, 'editor', 'editor-names'];
    $cacheOkAll = true;
    $cacheBodies = [];
    foreach ($cacheLevels as $cacheLevel) {
        $cached = km_app_map_cache_get('kosen-main', $cacheSrc, $cacheLevel, "{$cacheTmp}/c", $cacheConsent);
        $direct = km_app_map_build_package(km_app_map_for_level(km_app_map_decode_snapshot((string) file_get_contents($cacheSrc)), $cacheLevel, $cacheConsent), 'kosen-main', 3, 'T', 'E', null, true, null, $cacheLevel, null, ['a' => [['id' => 1]]]);
        $parts = $cached === null ? null : km_app_map_package_envelope($cached['sha256'], 'kosen-main', 3, 'T', 'E', null, null, $cacheLevel, null, ['a' => [['id' => 1]]]);
        $cacheOkAll = $cacheOkAll && $parts !== null && $parts[0] . file_get_contents($cached['body']) . $parts[1] === $direct;
        $cacheBodies[$cacheLevel === $cacheMember ? 'member-x' : $cacheLevel] = $cached === null ? '' : (string) file_get_contents($cached['body']);
    }
    check_bool('作り置き: どの段も、その場で組み立てた応答と同じ文字列', $cacheOkAll);
    check_bool('作り置き: ふつうの人は氏名・スタッフ限定・教員室を落とす', !str_contains($cacheBodies['public'], '架空') && !str_contains($cacheBodies['public'], '"p1"') && !str_contains($cacheBodies['public'], '"t"'));
    check_bool('作り置き: イベント運営はスタッフ限定だけ残し、氏名と教員室は落とす', !str_contains($cacheBodies['event'], '架空') && str_contains($cacheBodies['event'], '"p1"') && !str_contains($cacheBodies['event'], '"t"'));
    check_bool('作り置き: 組織メンバーは教員室を残し、氏名は許可した地点だけ', str_contains($cacheBodies['member'], '"t"') && !str_contains($cacheBodies['member'], '架空')
        && str_contains($cacheBodies['member-x'], '架空 太郎') && !str_contains($cacheBodies['member-x'], '架空 次郎') && !str_contains($cacheBodies['member-x'], '架空 三郎'));
    check_bool('作り置き: 管理者は全部(氏名は錠に従う)', str_contains($cacheBodies['editor'], '"t"') && !str_contains($cacheBodies['editor'], '架空')
        && str_contains($cacheBodies['editor-names'], '架空 三郎') && str_contains($cacheBodies['editor-names'], '"p1"'));
    check_bool('知らない段は一番狭い段と同じに落とす', !str_contains((string) json_encode(km_app_map_for_level(km_app_map_decode_snapshot((string) file_get_contents($cacheSrc)), 'everything')), '"t"'));
    $firstBody = km_app_map_cache_get('kosen-main', $cacheSrc, 'public', "{$cacheTmp}/c")['body'];
    $firstMember = km_app_map_cache_get('kosen-main', $cacheSrc, $cacheMember, "{$cacheTmp}/c", $cacheConsent)['body'];
    km_app_map_cache_get('kosen-main', $cacheSrc, 'member', "{$cacheTmp}/c");
    check_bool('作り置き: 許可の組が変われば、前の組の作り置きを片付ける', !is_file($firstMember));
    file_put_contents($cacheSrc, '{"version":9,"nodes":[{"uuid":"z"}],"lines":[]}');
    touch($cacheSrc, time() + 5);
    $second = km_app_map_cache_get('kosen-main', $cacheSrc, 'public', "{$cacheTmp}/c");
    check_bool('作り置き: 元を差し替えたら作り直し、古いものは片付ける', $second !== null && $second['body'] !== $firstBody && !is_file($firstBody) && isset($second['uuids']['z']));
    check_bool('作り置き: 知らない段・以前の段・おかしな配信 ID は作らない', km_app_map_cache_get('kosen-main', $cacheSrc, 'unknown', "{$cacheTmp}/c") === null
        && km_app_map_cache_get('kosen-main', $cacheSrc, 'visitor', "{$cacheTmp}/c") === null
        && km_app_map_cache_get('../x', $cacheSrc, 'public', "{$cacheTmp}/c") === null);
    foreach (array_merge(glob("{$cacheTmp}/c/*") ?: [], [$cacheSrc]) as $cacheFile) {
        @unlink($cacheFile);
    }
    @rmdir("{$cacheTmp}/c");
    @rmdir($cacheTmp);
    check_bool('配信: 作り置きを readfile で流し、使えなければその場で組み立てる', str_contains($appMapApi, "readfile(\$cached['body']);")
        && strpos($appMapApi, "readfile(\$cached['body']);") < strpos($appMapApi, '$map = $loadDeliveredMap();'));
    check_bool('作り置きの置き場は cache/ の下(nginx が直接は配らない)', str_contains($file('src/lib/app-map-cache.php'), "return __DIR__ . '/../cache/app-map';")
        && str_contains($nginx, 'location ~ ^/(lib|config|scripts|uploads|cache|vendor)/ {'));

    $panoApi = $file('src/api/panorama.php');
    check_bool('写真そのものはハッシュだけで取る・長くキャッシュ・ETag', str_contains($panoApi, "if (\$method === 'GET' && isset(\$_GET['h'])) {") && str_contains($panoApi, "header('Cache-Control: private, max-age=31536000, immutable');")
        && strpos($panoApi, "isset(\$_GET['h'])") < strpos($panoApi, 'logto_require_principal()'));
    check_bool('上げる・一覧・外すは管理者だけ', preg_match('/\$principal = logto_require_principal\(\);\nlogto_assert_permissions\(\$principal, LOGTO_ADMIN_PERMISSIONS\);/', $panoApi) === 1
        && str_contains($panoApi, "'map.panorama_add'") && str_contains($panoApi, "'map.panorama_delete'"));
    check_bool('nginx: クエリ(ハッシュ)をログに書かない・本文 11m・回数を数える', str_contains($nginx, "location = /api/panorama.php {\n        access_log /var/log/nginx/access.log km_no_query;\n        client_max_body_size 11m;\n        limit_req zone=km_api burst=120 nodelay;"));
    check_bool('配信: 来場者版は閲覧不可の地点を落とした地図から一覧を作る', str_contains($appMapApi, 'km_panorama_manifest($pdo, km_panorama_uuids_of_map($map))')
        && preg_match('/\$loadDeliveredMap = static function \(\).*?return km_app_map_for_level\(\$map, \$contentLevel, \$consentedUuids\);/s', $appMapApi) === 1);
    // Android 側の厳格化(2026-10-22): 段は管理者・組織メンバー・イベント運営を分けて決め、組織メンバーには許可した地点を渡す
    check_bool('配信: 段は管理者・組織メンバー・イベント運営を分けて決める(運営は教員室を見ない)', str_contains($appMapApi, '$contentLevel = km_app_map_content_level($isAdmin, $isTeacher || $isLearner, $isEventStaff, $namesAllowed, $consentedUuids);')
        && str_contains($appMapApi, "\$isLearner = is_array(\$principal) && (\$principal['is_learner'] ?? false) === true;")
        && str_contains($appMapApi, '$consentedUuids = km_staff_node_consented_uuids($pdo);')
        && substr_count($appMapApi, 'km_app_map_cache_get($slug, $path, $contentLevel, null, $consentedUuids)') === 2);
    $nodeExtrasApi = $file('src/api/node-extras.php');
    check_bool('写真といいね: 教員室・研究室は出さない', str_contains($nodeExtrasApi, '$likeable = $node !== null && !$restricted;')
        && str_contains($nodeExtrasApi, "\$likes = \$restricted ? ['count' => 0, 'liked' => false] :"));
    $baseCompose = $file('compose.yaml');
    check_bool('入口と地図の正本はスワップを使わず、最後まで守る', preg_match('/\n  mariadb:\n(?:(?!\n  [a-z0-9-]+:\n)[\s\S])*?memswap_limit: 512m\n    oom_score_adj: -500\n/', $baseCompose) === 1
        && preg_match('/\n  reverse-proxy:\n(?:(?!\n  [a-z0-9-]+:\n)[\s\S])*?memswap_limit: 128m\n    oom_score_adj: -500\n/', $baseCompose) === 1);

    /*
     * ## ドメインの統一(2026-09-17。ito8795.com → ito4.jp)
     *
     * 旧ドメインは切り替えと同時に手放す(移行の間だけ生かす仕組みは作ったが、利用者の判断で外した)。
     * audience(API リソース)も https://ito4.jp/api に移す。Logto の識別子は変えられないので、
     * logto-api-resource.php が新しいリソースを作ってスコープとロールを写す。
     */
    km_check_heading('domain-unify: ito4.jp に統一(旧ドメインを残さない・audience も移す)');
    check_bool('nginx: 旧ドメインを別扱いする仕組みが残っていない', !str_contains($nginx, 'km_leave_old_host') && !str_contains($nginx, 'KM_OLD_DOMAIN'));
    check_bool('compose: KM_OLD_DOMAIN を渡さない', !str_contains($yamlCode($base), 'KM_OLD_DOMAIN') && !str_contains($vpsCode, 'KM_OLD_DOMAIN'));
    check_bool('mailserver: 差出人は KM_DOMAIN だけ', str_contains($vpsServices['mailserver'] ?? '', 'ALLOWED_SENDER_DOMAINS: ${KM_DOMAIN}' . "\n"));
    $hostDomain = $file('scripts/host-domain.sh');
    check_bool('host-domain.sh は KM_OLD_DOMAIN を書かない', !str_contains($hostDomain, 'KM_OLD_DOMAIN'));
    check_bool('host-domain.sh apply は --api-resource で audience も移せる(既定は据え置き)', str_contains($hostDomain, '--api-resource) API_NEW="$2"; shift 2 ;;') && str_contains($hostDomain, 'apiforce == "1"'));
    check_bool('host-domain.sh は証明書に管理用の名前が入っているかを見る', str_contains($hostDomain, 'for _want in "$NEW" $_admin; do'));
    check_bool('host-domain.sh は DKIM の鍵を作らせずに旧の鍵を複製する(DNS の公開鍵と合わせる)', str_contains($hostDomain, 'cp -p \'$OLD.private\' \'$NEW.private\'') && str_contains($hostDomain, '[ -e \'$NEW.private\' ] ||'));
    check_bool('host-cert.sh / host-local.sh に旧ドメインの処理が残っていない', !str_contains($file('scripts/host-cert.sh'), 'KM_OLD_DOMAIN') && !str_contains($hostLocal, 'OLD_DOMAIN') && !str_contains($hostLocal, '--old-domain'));
    check_bool('host-local.sh は新旧どちらの本番の名前も拒み、audience は ito4.jp', str_contains($hostLocal, 'PROD_DOMAINS="ito4.jp ito8795.com"') && str_contains($hostLocal, 'PROD_API_RESOURCE="https://ito4.jp/api"'));
    $logtoDomain = (string) @file_get_contents($src . '/scripts/logto-domain.php');
    check_bool('logto-domain.php はメールのコネクタの差出人も書き換える(旧のままだと mailserver が拒む)', str_contains($logtoDomain, "km_logto_management_get('connectors')") && str_contains($logtoDomain, 'function km_logto_domain_rewrite_address('));
    check_bool('logto-domain.php はコネクタの設定をオブジェクトのまま送り返す(空の {} を [] に化けさせない)', str_contains($logtoDomain, "'body' => ['config' => \$objConfig]") && str_contains($logtoDomain, 'json_decode($body, false, 512, JSON_THROW_ON_ERROR)'));
    $apiResource = (string) @file_get_contents($src . '/scripts/logto-api-resource.php');
    check_bool('logto-api-resource.php がある(識別子は変えられないので作り直して写す)', $apiResource !== '');
    check_bool('logto-api-resource.php は root で走らない・既定は見るだけ', str_contains($apiResource, "posix_geteuid() === 0") && str_contains($apiResource, "isset(\$options['apply'])"));
    check_bool('logto-api-resource.php は写しが揃うまで旧いリソースを消さない', str_contains($apiResource, '新しいリソースへの写しが揃っていません'));
    check_bool('logto-api-resource.php は写したあと読み直す', str_contains($apiResource, '$after = km_logto_resource_plan($from, $to);'));
    // 配布物の見張りにも入れる(除外パターンの巻き添えで落ちると、切り替えの日に道具が無い)
    check_bool('配備物の見張りに logto-api-resource.php がある', str_contains($file('scripts/deploy-to-host.ps1'), "'./src/scripts/logto-api-resource.php'"));
    $deployScript = $file('scripts/deploy-to-host.ps1');
    check_bool('配備の既定の繋ぎ先は ito4.jp(旧の名前も本番として扱う)', str_contains($deployScript, "HostName = 'ito4.jp'") && str_contains($deployScript, '$prodHostNames = @(\'ito4.jp\', \'ito8795.com\')'));
    foreach (['scripts/host-setup.ps1', 'scripts/backup-data.ps1', 'scripts/backup-keys.ps1', 'scripts/backup-task-run.ps1', 'scripts/register-backup-task.ps1'] as $psScript) {
        check_bool("{$psScript} の既定の繋ぎ先は ito4.jp", str_contains($file($psScript), "[string]\$HostName = 'ito4.jp'"));
    }
    check_bool('ノートの既定の繋ぎ先は ito4.jp', str_contains($file('docs/km_nb.py'), "os.environ.get('KM_HOST', 'ito4.jp')"));
    // 外部の診断(2026-09-17 の SSL Labs)で WEAK と出た CBC を外す
    check_bool('TLS 1.2 の暗号は ECDHE の AEAD(GCM・ChaCha20)だけ', preg_match('/^ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;$/m', $nginx) === 1 && !preg_match('/^ssl_ciphers .*(CBC|SHA:|CAMELLIA|ARIA|CCM)/m', $nginx));
    check_bool('鍵交換は耐量子を先頭に、ffdhe と secp521r1 を外す', preg_match('/^ssl_ecdh_curve X25519MLKEM768:X25519:prime256v1:secp384r1;$/m', $nginx) === 1);
    check_bool('取扱説明書 14(ito4.jp への統一)がある', is_file($repoRoot . '/docs/14-domain-ito4.ipynb'));

    km_check_heading('hardening A・C: ホスト側スクリプト(証明書・ドメイン・cron 版 6)');
    $scripts = $repoRoot . '/scripts';
    foreach (['host-cert.sh', 'host-domain.sh'] as $hostScript) {
        $body = (string) @file_get_contents($scripts . '/' . $hostScript);
        check_bool("{$hostScript} がある", $body !== '');
        check_bool("{$hostScript} は set -eu で止まる", preg_match('/^set -eu$/m', $body) === 1);
    }
    check_bool('logto-domain.php がある', is_file($src . '/scripts/logto-domain.php'));
    // 実行ビットは host-setup.sh が名指しで付ける。載っていなければ cron が毎日「失敗」を送る
    check_bool('host-setup.sh の HOST_SCRIPTS に2本がある', preg_match("/^HOST_SCRIPTS='[^']*\\bhost-cert\\.sh\\b[^']*'$/m", $file('scripts/host-setup.sh')) === 1 && preg_match("/^HOST_SCRIPTS='[^']*\\bhost-domain\\.sh\\b[^']*'$/m", $file('scripts/host-setup.sh')) === 1);
    $deployCode = (string) preg_replace(['/<#.*?#>/s', '/^\s*#.*$/m'], '', $file('scripts/deploy-to-host.ps1'));
    $deployList = static function (string $name) use ($deployCode): string {
        return preg_match('/^\$' . $name . '\s*=\s*@\((.*?)^\)/ms', $deployCode, $m) === 1 ? $m[1] : '';
    };
    $include = $deployList('include');
    $mustContain = $deployList('mustContain');
    check_bool('配備の $include に host-cert.sh と host-domain.sh', str_contains($include, "'./scripts/host-cert.sh'") && str_contains($include, "'./scripts/host-domain.sh'"));
    check_bool('配備の $mustContain に2本と logto-domain.php', str_contains($mustContain, "'./scripts/host-cert.sh'") && str_contains($mustContain, "'./scripts/host-domain.sh'") && str_contains($mustContain, "'./src/scripts/logto-domain.php'"));
    $setup = $file('scripts/host-updates-setup.sh');
    // 版 6 で証明書を足し、版 7(2026-10-05)でタスクマネージャーの集計と使用率の警告を、版 8(2026-10-06)で訪問者の記録の片付けを、
    // 版 9(2026-10-09)でアプリの受け箱を足した
    check_bool('cron は版 9', preg_match('/^CRON_VERSION=9$/m', $setup) === 1);
    // 国単位のアクセス拒否(2026-10-06)。毎月の取り直しと、再起動のあとの当て直し
    check_bool('cron(版 8)に国の拒否の取り直しと再起動後の当て直しがある', str_contains($setup, '53 4 2 * *  root  sh $GEOBLOCK_SCRIPT --path $PATH_ROOT update')
        && str_contains($setup, '@reboot     root  sleep 30 && sh $GEOBLOCK_SCRIPT --path $PATH_ROOT apply'));
    $geoblock = $file('scripts/host-geoblock.sh');
    check_bool('国の拒否: 設定はシェルとして実行しない(名前=値だけ読む)', !preg_match('/^\s*(\.|source)\s+"?\$CONF/m', $geoblock) && str_contains($geoblock, 'key="${line%%=*}"'));
    check_bool('国の拒否: LAN・ループバックと SSH(既定 22)を先に通してから落とす', strpos($geoblock, '192.168.0.0/16') < strpos($geoblock, 'ip saddr @deny4')
        && strpos($geoblock, 'tcp dport { $exempt } accept') < strpos($geoblock, 'ip saddr @deny4') && str_contains($geoblock, 'EXEMPT_PORTS="22"'));
    check_bool('国の拒否: Docker の nat より前(prerouting -150)・作り直しは 1 回の読み込みで', str_contains($geoblock, 'type filter hook prerouting priority -150; policy accept;')
        && str_contains($geoblock, "echo \"table inet \$TABLE {}\"\n  echo \"delete table inet \$TABLE\""));
    // 反転(2026-10-08、利用者の指示「特定の国以外は拒否」)。戻りの通信は国で落とさない(反転すると外国のサーバーへの通信がすべて戻らなくなる)
    check_bool('国の拒否: 反転(DENY_INVERT=yes)は書いた国の集合に無い送り元を落とす・通す国が空なら止める', str_contains($geoblock, "      DENY_INVERT) DENY_INVERT=\"\$value\" ;;")
        && str_contains($geoblock, "ip saddr != @deny4 counter drop comment")
        && str_contains($geoblock, 'DENY_INVERT=yes なのに DENY_COUNTRIES が空です')
        && str_contains($geoblock, '反転で通す国($DENY_COUNTRIES)の一覧がありません。誰も入れなくなるので当てません'));
    check_bool('国の拒否: こちらからつないだ通信の戻りは国で落とさない(拒否より前)・反転で 80 を通さなければ注意', strpos($geoblock, 'echo "    ct state established,related accept"') !== false
        && strpos($geoblock, 'echo "    ct state established,related accept"') < strpos($geoblock, "ip saddr != @deny4 counter drop")
        && strpos($geoblock, 'echo "    ct state established,related accept"') < strpos($geoblock, "ip saddr @deny4 counter drop")
        && str_contains($geoblock, '反転しているのに EXEMPT_PORTS に 80 がありません'));
    check_bool('国の拒否: 反転は見本・BAN の集計・訪問者の地図にも通る', str_contains($file('geoblock.local.conf.example'), "\nDENY_INVERT=no\n")
        && str_contains($file('scripts/host-stats.sh'), '"invert":%s') && str_contains($file('src/admin/assets/js/visitors-world.js'), 'denyInvert'));
    check_bool('国の拒否: 取り直しに失敗したら前の一覧を使う・管理用の国の一覧が空なら当てない', str_contains($geoblock, '前の一覧を使います') && str_contains($geoblock, '誰も入れなくなるので当てません'));
    check_bool('国の拒否: 設定はリポジトリに置かない(見本だけ)・スクリプトと見本は配備に載る', !is_file(km_check_repo_root() . '/geoblock.local.conf') && is_file(km_check_repo_root() . '/geoblock.local.conf.example')
        && str_contains($deploy, "'./scripts/host-geoblock.sh'") && str_contains($deploy, "'./geoblock.local.conf.example'"));
    check_bool('cron の証明書は host-cert.sh を指す', str_contains($setup, 'CERT_SCRIPT="$PATH_ROOT/scripts/host-cert.sh"'));
    check_bool('毎日 3:47 に renew、失敗したときだけ送る', preg_match('/^47 3 \* \* \*\s+root\s+\$SEND_LOG_SCRIPT [^\n]*--only-failure --run "\$CERT_SCRIPT --path \$PATH_ROOT renew"/m', $setup) === 1);
    check_bool('毎月1日に status を必ず送る', preg_match('/^7 4 1 \* \*\s+root\s+\$SEND_LOG_SCRIPT (?![^\n]*--only-failure)[^\n]*--run "\$CERT_SCRIPT --path \$PATH_ROOT status"/m', $setup) === 1);
    check_bool('実行ビットを直す対象に host-cert.sh と send-log.sh', preg_match('/^for _script in [^\n]*"\$CERT_SCRIPT"[^\n]*"\$SEND_LOG_SCRIPT"/m', $setup) === 1);
    /*
     * 変数を展開するヒアドキュメント(`<<CONF`)の中のバッククォートは**コマンドとして走る。**
     * cron の注記の `1-7 * 0` が実行され、本番の cron から文が消えていた(2026-09-14、ノートの試験で発見)。
     */
    $unquotedHeredocs = preg_match_all('/<<([A-Z_]+)\s*\n(.*?)^\1$/ms', $setup, $heredocs) > 0 ? $heredocs[2] : [];
    check_bool(
        '展開するヒアドキュメントにバッククォートを書かない',
        $unquotedHeredocs !== [] && array_filter($unquotedHeredocs, static fn (string $body): bool => str_contains($body, '`')) === []
    );
    /*
     * logrotate は root 所有でない設定を読まずに飛ばす。本番は km:km 4745 で、実行記録が切り詰められていなかった。
     * cron.d と同じく、在るかどうかだけでなく持ち主と権限を毎回見る。
     */
    check_bool(
        '切り詰めの設定(logrotate)の持ち主と権限も見て、setuid を落として直す',
        str_contains($setup, "stat -c '%U:%G' /etc/logrotate.d/kosenmap")
            && str_contains($setup, 'chown root:root /etc/logrotate.d/kosenmap')
            && str_contains($setup, 'chmod u-s,g-s /etc/logrotate.d/kosenmap')
    );
    /*
     * `trap '…' EXIT INT TERM` の1行だと、Ctrl+C の後も処理が続く。apply まで進むと
     * 写しの無いまま `cat 写し > .env` が走り、**.env が空になる**(scripts の見直しで発見)。
     */
    $domainCode = (string) preg_replace('/^\s*#.*$/m', '', $file('scripts/host-domain.sh'));
    check_bool('host-domain.sh の trap は INT と TERM で抜ける', preg_match('/^\s*trap\s[^\n]*\bEXIT\b[^\n]*\bINT\b/m', $domainCode) === 0 && str_contains($domainCode, "trap 'exit 130' INT"));
    $backupCode = (string) preg_replace('/^\s*#.*$/m', '', $file('scripts/host-backup.sh'));
    // 引数に載せると、コンテナの中の ps で値が見える(server-ops 15)
    check_bool('バックアップの DB パスワードは MYSQL_PWD で渡す', str_contains($backupCode, 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD"') && !str_contains($backupCode, '-p"$MARIADB_ROOT_PASSWORD"'));
    check_bool('緊急調査の出力は持ち主だけが読める(server-ops 14)', preg_match('/^umask 077$/m', $file('scripts/host-emergency.sh')) === 1);
    $securityCode = (string) preg_replace('/^\s*#.*$/m', '', $file('scripts/host-security-check.sh'));
    check_bool('セキュリティ確認は fail2ban が止まっていることを見る', str_contains($securityCode, 'systemctl is-active fail2ban'));
    check_bool('セキュリティ確認は Logto Console の MFA を見る', str_contains($securityCode, "where tenant_id = 'admin'") && str_contains($securityCode, 'add_item logto'));
}

/**
 * 取扱説明書のノートブック(docs/*.ipynb)。**読むだけでなく、そこから本番を動かせる。**
 *
 * だから文書の誤りが、そのまま本番の事故になる。ここで見るのは:
 *
 *   1. **本番を変えるセルに確認が付いている。** `%%ps` / `%%host` / `%%terminal` の1行目の
 *      `--confirm` が、実行の前に yes を求める(docs/km_nb.py)。付け忘れると、上から
 *      Shift+Enter で流しているうちに配備や再起動まで進む
 *   2. **`%%host` で sudo を使わない。** 対話できない場なので、パスワードを待って止まるか落ちる。
 *      sudo が要るものは `%%terminal`(別の窓)にする
 *   3. **印(🟢🟡🔴🔑)がセルの metadata.tags にある。** 読む人はそれで危なさを判断する
 *   4. 2026-09-14 に docs/*.md を Old/docs へ移した。**生きているファイルが古い場所を指していない**
 */
function km_check_notebooks(): void
{
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('取扱説明書のノートブック', '手元の作業ツリーで確認する。配備先に docs/ は出ない');

        return;
    }

    km_check_heading('notebooks: 取扱説明書(docs/*.ipynb)');
    $docs = $repoRoot . '/docs';
    $books = glob($docs . '/*.ipynb') ?: [];
    check_bool('ノートブックがある', count($books) >= 10, count($books) . ' 冊');
    check_bool('セルの道具(docs/km_nb.py)がある', is_file($docs . '/km_nb.py'));

    $tagsAllowed = ['km-setup', 'km-green', 'km-yellow', 'km-red', 'km-terminal'];
    /*
     * これらを含むセルは本番を変える。**確認(--confirm)を必須にする。**
     * 読むだけのものでも、使い捨てのコンテナを立てるものは念のため含める。
     */
    $dangerous = '/(-Yes\b|docker compose (up|down|restart|pull|build|run)\b|docker (restart|run)\b|alteration (deploy|rollback)|--notify\b|send-log\.sh|km_mail_send|Start-ScheduledTask|(?<![\w\/-])reboot(?![\w-])|--fix\b|-Fix\b|backup-data\.ps1|setup-vps\.ps1 (?!.*-DryRun)|restore-data\.ps1 (?!.*-DryRun))/';

    $broken = [];
    $noSetup = [];
    $untagged = [];
    $unconfirmed = [];
    $sudoInHost = [];
    $redWithoutConfirm = [];
    foreach ($books as $book) {
        $name = basename($book);
        $json = json_decode((string) file_get_contents($book), true);
        if (!is_array($json) || ($json['nbformat'] ?? 0) !== 4 || !is_array($json['cells'] ?? null)) {
            $broken[] = $name;
            continue;
        }

        $firstCode = null;
        foreach ($json['cells'] as $index => $cell) {
            if (($cell['cell_type'] ?? '') !== 'code') {
                continue;
            }
            $source = implode('', (array) ($cell['source'] ?? []));
            $firstLine = strtok($source, "\n") ?: '';
            $where = $name . '#' . $index;
            if ($firstCode === null) {
                $firstCode = $source;
            }

            $tags = array_values(array_intersect((array) ($cell['metadata']['tags'] ?? []), $tagsAllowed));
            /*
             * 読み込みのセル(km_nb.load())は印を問わない。**VS Code で保存すると metadata が
             * 空に戻ることがある**(2026-09-14、利用者が実行して保存した 00-start で実際に起きた)。
             * 読み込みは何も変えないので、印が消えても危なさの判断は変わらない。
             */
            if (count($tags) !== 1 && !str_contains($source, 'km_nb.load()')) {
                $untagged[] = $where;
            }
            $hasConfirm = str_contains($firstLine, '--confirm');
            if (in_array('km-red', $tags, true) && !$hasConfirm) {
                $redWithoutConfirm[] = $where;
            }
            // コメント行(# で始まる)は数えない。説明の文章に当たらないように
            $code = (string) preg_replace('/^\s*#.*$/m', '', $source);
            if (preg_match($dangerous, $code) === 1 && !$hasConfirm) {
                $unconfirmed[] = $where;
            }
            if (str_starts_with($firstLine, '%%host') && preg_match('/\bsudo\b/', $code) === 1) {
                $sudoInHost[] = $where;
            }
        }
        if ($firstCode === null || !str_contains($firstCode, 'km_nb.load()')) {
            $noSetup[] = $name;
        }
    }
    check_bool('どれも読める形(nbformat 4)', $broken === [], implode(', ', $broken));
    check_bool('最初のコードセルで km_nb を読み込む', $noSetup === [], implode(', ', $noSetup));
    check_bool('コードセルには印(km-*)が1つだけ付いている', $untagged === [], implode(', ', $untagged));
    check_bool('🔴 のセルは確認を求める', $redWithoutConfirm === [], implode(', ', $redWithoutConfirm));
    check_bool('本番を変えるコマンドを含むセルは確認を求める', $unconfirmed === [], implode(', ', $unconfirmed));
    check_bool('%%host で sudo を使わない(%%terminal にする)', $sudoInHost === [], implode(', ', $sudoInHost));

    $helper = (string) @file_get_contents($docs . '/km_nb.py');
    check_bool('確認は yes だけを受ける', str_contains($helper, "answer.strip().lower() != 'yes'"));
    check_bool('ホストへは標準入力で流す', str_contains($helper, "ssh_args() + ['sh']"));
    check_bool('中断したら子プロセスも止める', str_contains($helper, 'except KeyboardInterrupt') && str_contains($helper, "'taskkill'"));
    check_bool('一時の .ps1 は BOM 付きで書く', str_contains($helper, "encoding='utf-8-sig'"));
    /*
     * セルに構文の誤りがあると、ファイルの1行目も実行されずに止まる。ファイルの中で UTF-8 を決めても
     * 効かず、エラー文が CP932 で出て化けた(2026-09-14)。**外側で先に決めてから呼ぶ。**
     */
    check_bool('構文エラーでも読める形で出す(外側で UTF-8 を決めてから呼ぶ)', str_contains($helper, "'-Command', _ps_launcher(path)"));

    /*
     * ## 移した文書を、生きているファイルが指していない
     *
     * `docs/<名前>.md` の形だけを見る。status.md / plan.md の中の経緯の記述は、
     * 当時の名前のまま残してよい(書き換えると経緯が読めなくなる)。
     */
    $moved = 'containers|data-migration|handover|logto-smtp|mail-server|play-data-safety|security-review-1\.0\.1|security-review-2026-09-10|vendor-assets|vps-checklist|vps-migration|vps-setup';
    $live = array_merge(
        [$repoRoot . '/compose.yaml', $repoRoot . '/compose.vps.yaml', $repoRoot . '/backups/README.txt'],
        glob($repoRoot . '/scripts/*') ?: [],
        glob($repoRoot . '/src/*.php') ?: [],
        glob($repoRoot . '/src/*.svg') ?: [],
        glob($repoRoot . '/src/lib/*.php') ?: [],
        glob($docs . '/*.ipynb') ?: []
    );
    $stale = [];
    foreach ($live as $file) {
        if (is_file($file) && preg_match('#(?<!Old[/\\\\])docs[/\\\\](' . $moved . ')\.md#', (string) file_get_contents($file)) === 1) {
            $stale[] = basename($file);
        }
    }
    check_bool('移した文書の古い場所を指していない', $stale === [], implode(', ', $stale));
    $missing = [];
    foreach (explode('|', str_replace('\\.', '.', $moved)) as $doc) {
        if (!is_file($repoRoot . '/Old/docs/' . $doc . '.md')) {
            $missing[] = $doc . '.md';
        }
    }
    check_bool('移した文書が Old/docs にある', $missing === [], implode(', ', $missing));
    check_bool('docs に残すのは status.md と plan.md だけ', array_map('basename', glob($docs . '/*.md') ?: []) === ['plan.md', 'status.md']);
}

/**
 * 実行記録をメールに載せる口(scripts/send-log.sh → src/scripts/notify-log.php)。
 *
 * **日本語の記録を、文字の途中で割らない。** `/u` の無い `\R` はバイト `\x85` も改行とみなす。
 * `者` は `E8 80 85` なので、2026-09-13 の失敗の知らせに
 * 「指紋を業�」「のコンソールと見比べられる」と2行に割れて届いた。
 * `/u` を付けると、壊れた UTF-8 が混じったときに行が丸ごと消えるので、改行は3通りを明示して割る。
 */
function km_check_log_notice(): void
{
    require_once __DIR__ . '/../lib/log-notice.php';

    // mbstring に頼らない(`//u` は壊れた UTF-8 で 0 以外を返す)
    $validUtf8 = static fn (string $s): bool => preg_match('//u', $s) === 1;

    km_check_heading('log-notice: 日本語の記録を割らない');
    $sample = "B) 手で一度繋いで確認する(指紋を業者のコンソールと見比べられる)\n二行目";
    $trimmed = km_log_notice_trim($sample);
    check('文字の途中で行を割らない', 2, $trimmed['total']);
    check_bool('縮めた後も UTF-8 として正しい', $validUtf8($trimmed['text']));
    check_bool('「業者の」がそのまま残る', str_contains($trimmed['text'], '指紋を業者のコンソール'));
    check('CRLF と CR も改行として数える', 3, km_log_notice_trim("a\r\nb\rc")['total']);

    $redacted = km_log_notice_redact("DB_PASSWORD=秘密者\n指紋を業者の");
    check_bool('伏せた後も UTF-8 として正しい', $validUtf8($redacted) && str_contains($redacted, '指紋を業者の'));
    check_bool('伏せる働きは変わらない', !str_contains($redacted, '秘密者'));

    // 同じ罠を他で踏まない。**`/u` の無い `\R` で行を割るコードを置かない**(このファイルも含む)
    $needle = "'/" . '\\' . "R/'";
    $found = [];
    foreach (array_merge(glob(__DIR__ . '/../lib/*.php') ?: [], glob(__DIR__ . '/*.php') ?: []) as $file) {
        if (str_contains((string) file_get_contents($file), $needle)) {
            $found[] = basename($file);
        }
    }
    check_bool('/u の無い \R で行を割らない', $found === [], implode(', ', $found));
}

/**
 * バックアップの自動取得と通知。**取れているかどうかは、取れなくなって初めて分かる。**
 *
 * ここで見るのは主に2つ:
 *
 *   1. **失敗を成功と読み違えない。** ファイルが在るだけでは取れた証拠にならない
 *      (`Dump completed` が末尾にあるか、まで見ているか)
 *   2. **秘密を素で流さない。** バックアップには `.env` と `config/*.local.php` が
 *      入る —— DB のパスワードと Logto の秘密がそのまま。
 *      **受信箱1つの流出がサーバーの全権**になるので、添付するなら暗号化してから
 */
function km_check_backup_notice(): void
{
    require_once __DIR__ . '/../lib/backup-notice.php';

    km_check_heading('backup-notice: 受け取った内容の検証');
    $parsed = km_backup_notice_parse(
        '{"host":"ubuntu","ok":true,"archive":"/opt/kosenmap/backups/a.tar.gz","bytes":12582912,'
        . '"seconds":42,"kept":14,"parts":[{"name":"MariaDB","bytes":1024,"note":"末尾まで"}],'
        . '"problems":[],"attachNote":""}'
    );
    check('宛先のホスト', 'ubuntu', $parsed['host']);
    check('取れたかどうか', true, $parsed['ok']);
    check('項目を読む', 1, count($parsed['parts']));
    check_bool('heartbeat の既定は false', $parsed['heartbeat'] === false);

    $rejected = false;
    try {
        km_backup_notice_parse('{"parts":[{"bytes":1}]}');
    } catch (InvalidArgumentException $exception) {
        $rejected = true;
    }
    check_bool('名前の無い項目は拒む', $rejected);

    $notJson = false;
    try {
        km_backup_notice_parse('これは JSON ではない');
    } catch (InvalidArgumentException $exception) {
        $notJson = true;
    }
    check_bool('JSON でなければ拒む', $notJson);

    km_check_heading('backup-notice: 件名と本文');
    // **成功と失敗を件名だけで見分けられるようにする**(一覧で開かずに済む)
    $failed = $parsed;
    $failed['ok'] = false;
    check_bool('失敗は件名で分かる', str_contains(km_backup_notice_subject($failed), '失敗'));
    check_bool(
        '成功と失敗で件名が変わる',
        km_backup_notice_subject($parsed) !== km_backup_notice_subject($failed)
    );
    // 気になる点があるなら、成功でもそう言う(「取れました」で流させない)
    $warned = $parsed;
    $warned['problems'] = ['uploads が空です'];
    check_bool('気になる点は件名に出す', str_contains(km_backup_notice_subject($warned), '気になる点'));

    // 桁を数えさせない
    check('大きさは読める形にする', '12.0 MB', km_backup_notice_size(12582912));
    check('0 も出せる', '0 B', km_backup_notice_size(0));

    $body = km_backup_notice_body($parsed);
    check_bool('置き場を出す', str_contains($body, '/opt/kosenmap/backups/a.tar.gz'));
    check_bool('戻し方を書く', str_contains($body, 'restore-data.ps1'));
    // **秘密が入っていることを本文で言う。** 置き場所の扱いが変わる
    check_bool('秘密が入ると書く', str_contains($body, 'config/*.local.php'));
    // **メールに markdown を書かない。** 受け取る側は素のテキストで読む
    check_bool('本文に ** を残さない', !str_contains($body, '**'));
    // 添付が無いときは、その理由まで出す(黙って落とさない)
    $noAttach = $parsed;
    $noAttach['attachNote'] = '合言葉が無いので添付していません。';
    check_bool('添付しない理由を出す', str_contains(km_backup_notice_body($noAttach), '合言葉'));

    /*
     * **添付は実行記録で、控えそのものではない。** 件名と本文で取り違えさせない。
     *
     * 以前は件名が「バックアップ (633.0 KB) を添付しました」、本文が
     * 「バックアップ本体は暗号化してあります … -out backup.tar.gz」だった。
     * 実際の添付は kosenmap-logs-*.tar.gz.enc だけ(2026-09-13 に利用者が受け取って判明)。
     * **受信箱に控えがあると思い込むと、ホストが失われたときに頼る先を間違える。**
     */
    $attached = $parsed;
    $attached['attached'] = ['kosenmap-logs-20260913-024001.tar.gz.enc'];
    $attachedSubject = km_backup_notice_subject($attached);
    $attachedBody = km_backup_notice_body($attached);
    check_bool('添付があるとき件名で「実行記録」と言う', str_contains($attachedSubject, '実行記録'));
    check_bool('控えを添付したとは言わない(件名)', !str_contains($attachedSubject, 'を添付しました'));
    check_bool('開き方の出力名は logs', str_contains($attachedBody, '-out logs.tar.gz'));
    check_bool('控えを添付したとは言わない(本文)', !str_contains($attachedBody, 'backup.tar.gz'));
    check_bool('控えの在りかを本文で言う', str_contains($attachedBody, '添付していません'));
    // 戻し方のパスは実在するものを書く(scripts/Old ではなく server/Old)
    check_bool('復元スクリプトの場所が正しい', !str_contains($attachedBody, 'scripts/Old/'));

    km_check_heading('backup-notice: ホスト側の仕込み');
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('ホスト側の仕込み(この節すべて)', '手元の作業ツリーで確認する。配備先に scripts/ は出ない');

        return;
    }

    $scripts = $repoRoot . '/scripts';
    $backup = (string) @file_get_contents($scripts . '/host-backup.sh');
    $setup = (string) @file_get_contents($scripts . '/host-updates-setup.sh');
    $deploy = (string) @file_get_contents($scripts . '/deploy-to-host.ps1');
    $backupCode = (string) preg_replace('/^\s*#.*$/m', '', $backup);

    check_bool('バックアップのスクリプトがある', $backup !== '');
    check_bool('毎日の実行に入れている', str_contains($setup, 'host-backup.sh'));
    check_bool('配備で送る', str_contains($deploy, "'./scripts/host-backup.sh'"));
    check_bool('通知も対で送る', str_contains($deploy, "'./src/scripts/notify-backup.php'"));

    /*
     * ## 週に一度は、問題が無くても送る
     *
     * 毎日の行は失敗したときだけ送る。それだけだと、**仕掛けそのものが
     * 止まったときに何も起きない** —— 受け取る側から見て「異常なし」と
     * 「そもそも動いていない」が同じ顔になる。
     *
     * `--heartbeat` を曜日指定(`* * 0`)で1本置き、届かなくなった週に
     * 気づけるようにする。月に一度だと、**気づくまで最悪ひと月かかる。**
     *
     * cron は**日付と曜日の両方**を絞ると or で読むので、
     * 「毎月1〜7日の日曜」は書けない。曜日だけを絞ること。
     */
    check_bool(
        '週に一度は必ず送る(日曜の --heartbeat)',
        preg_match('/^\s*\d+\s+\d+\s+\*\s+\*\s+0\s+\S+\s+\$BACKUP_SCRIPT[^\n]*--heartbeat/m', $setup) === 1
    );

    /*
     * ## cron が受け取れる状態にしているか
     *
     * **`/etc/cron.d/` の中で root 所有でないファイルを、cron は実行しない。**
     * 指定した利用者で走らせる仕組みなので、他人が書けるファイルを信じたら
     * 権限の昇格になる。正しい判断だが、**黙って拒む。**
     *
     * 2026-09-09、実際にそうなっていた:
     *
     *     -rw-r--r-- 1 km km  /etc/cron.d/kosenmap-updates
     *
     * ファイルは在り、中身は正しく、末尾に改行もあり、デーモンも動いていて、
     * **版の照合も通る。** 症状は「メールが来ない」だけ。
     * 同じ cron.d の sysstat(root 所有)は同じ時刻に動いていた ——
     * 違いは所有者だけだった。
     *
     * `cat > "$CRON_FILE"` は**既存ファイルの持ち主を変えない**ので、
     * 一度 km で作られると、その後 root で何度書き直しても km のまま残る。
     * 書いた直後の chown と、毎回の確認の**両方**が要る。
     */
    check_bool('cron ファイルを root 所有にする', str_contains($setup, 'chown root:root "$CRON_FILE"'));
    check_bool(
        '所有者と権限を毎回確かめる',
        preg_match('/_owner=.*stat -c .%U. "\$CRON_FILE"/', $setup) === 1
    );
    // 実行記録にはホストの弱点が並ぶ。読めてよいのは root だけ
    check_bool('実行記録の置き場も root だけにする', str_contains($setup, 'chown root:root "$LOG_DIR"'));
    // GNU chmod は数字指定でディレクトリの setuid を残す。4755 に 750 を当てても 4750 のまま
    check_bool('setuid を落としてから 750 にする', str_contains($setup, 'chmod u-s,g-s "$LOG_DIR"'));

    /*
     * ## どの曜日も1回だけ取る
     *
     * 以前は「毎日 2:40」と「日曜 2:50(--heartbeat)」の2行で、**毎日の行は日曜も走る**ため
     * 日曜だけ同じ控えを10分おきに2本作っていた(2026-09-13 実測)。
     * 世代は本数で数えるので、余分な1本が「14日分」の履歴を毎週削る。
     *
     * cron の行を読み、曜日ごとに何本の $BACKUP_SCRIPT が走るかを数える。
     * 日付の欄を絞った行は**曜日と or で読まれる**ので、ここでは「日付は * だけ」も併せて見る。
     */
    $backupPerDay = array_fill(0, 7, 0);
    $backupLineProblems = [];
    preg_match_all(
        '/^\s*\d+\s+\d+\s+(\S+)\s+(\S+)\s+(\S+)\s+\S+\s+\$BACKUP_SCRIPT/m',
        $setup,
        $cronLines,
        PREG_SET_ORDER
    );
    foreach ($cronLines as $cronLine) {
        [, $dom, $month, $dow] = $cronLine;
        if ($dom !== '*' || $month !== '*') {
            $backupLineProblems[] = '日付や月を絞った行があります(曜日と or で読まれる): ' . trim($cronLine[0]);
            continue;
        }
        $days = [];
        foreach (explode(',', $dow === '*' ? '0-6' : $dow) as $part) {
            if (preg_match('/^(\d)-(\d)$/', $part, $range) === 1) {
                $days = array_merge($days, range((int) $range[1], (int) $range[2]));
            } elseif (preg_match('/^\d$/', $part) === 1) {
                $days[] = (int) $part % 7;   // 7 も日曜
            } else {
                $backupLineProblems[] = '読めない曜日の指定: ' . $dow;
            }
        }
        foreach (array_unique($days) as $day) {
            $backupPerDay[$day]++;
        }
    }
    $dayNames = ['日', '月', '火', '水', '木', '金', '土'];
    foreach ($backupPerDay as $day => $count) {
        if ($count !== 1) {
            $backupLineProblems[] = $dayNames[$day] . '曜: ' . $count . ' 回';
        }
    }
    check('どの曜日もバックアップは1回だけ', [], $backupLineProblems);

    /*
     * ## 手元からホストの世代数を指図しない
     *
     * backup-data.ps1 がホストへ `--keep 3` を渡していたため、**手元から1回取るたびに
     * ホストの控えが3本まで削られていた**(2026-09-13、cron が積んだ6本が一度に消えた)。
     * ホストの記録には残らず、cron の設定は正しく見えるので、ホスト側を見ても原因が分からない。
     */
    $pull = (string) @file_get_contents($scripts . '/backup-data.ps1');
    check_bool(
        'backup-data.ps1 はホストに世代数を渡さない',
        $pull !== '' && preg_match('/Invoke-Remote[^\r\n]*--keep/', $pull) !== 1
    );

    /*
     * ## 再起動の知らせを毎日出さない
     *
     * 同じ知らせかどうかを**表示用の文**の指紋で見ていたため、
     * 「(0 日前から)」「(1 日前から)」と日数が増えるだけで別物になり、毎日送っていた
     * (2026-09-12・13)。**鍵**で見る。再起動の鍵は要求された時刻。
     */
    $security = (string) @file_get_contents($scripts . '/host-security-check.sh');
    check_bool('重複の判定は鍵で見る', str_contains($security, 'printf \'%s\' "$KEYS" | md5sum'));
    check_bool('再起動の鍵は要求された時刻', str_contains($security, '"reboot|${_since_epoch}"'));

    /*
     * ## 手元の控えの置き場は1か所で決める
     *
     * backup-data.ps1 / open-backup.ps1 / 世代整理 / 週次タスクの4か所が、それぞれ
     * `server\backups` を直に組み立てていた。利用者が控えを D:\Backups へ移したところ、
     * 移したものは世代整理にも「一番新しいものを開く」にも見えなくなった(2026-09-13)。
     */
    km_check_heading('backup: 手元の置き場と、開いた平文');
    $psHardcoded = [];
    foreach (glob($scripts . '/*.ps1') ?: [] as $psPath) {
        foreach (preg_split('/\r\n|\r|\n/',(string) file_get_contents($psPath)) ?: [] as $i => $psLine) {
            if (preg_match('/^\s*#/', $psLine) === 1) {
                continue;
            }
            if (preg_match('/Join-Path[^\r\n]*[\'"]backups(\\\\|[\'"])/', $psLine) === 1) {
                $psHardcoded[] = basename($psPath) . ':' . ($i + 1);
            }
        }
    }
    check('置き場を server\\backups と決め打ちしていない', [], $psHardcoded);
    $backupLib = (string) @file_get_contents($scripts . '/backup-lib.ps1');
    check_bool('置き場は Get-KmBackupRoot が決める', str_contains($backupLib, 'function Get-KmBackupRoot'));
    // 置き場が無いとき、リポジトリの中へ黙って落ちない
    check_bool('置き場が無ければ止まる', str_contains($backupLib, '控えの置き場がありません'));

    /*
     * ## 開いた平文を、表示で頼まず作りで消す
     *
     * 「用が済んだら消してください」と表示するだけの作りで、2度とも残った
     * (security-review-2026-09-10 の P0。1つは D:\Backups へ移されて平文のまま残っていた)。
     */
    $opener = (string) @file_get_contents($scripts . '/open-backup.ps1');
    check_bool(
        'open-backup は -Keep でなければ平文を消す',
        preg_match('/if \(-not \$Keep\) \{\s*Remove-Item -LiteralPath \$Destination/', $opener) === 1
    );
    check_bool('世代整理が開いた平文の残りも片付ける', str_contains($backupLib, 'Remove-KmOpenedLeftovers -BackupRoot $BackupRoot'));

    /*
     * ## 手元の週次の失敗を黙らない
     *
     * 以前のタスクは記録をファイルに足すだけで、失敗しても誰にも知らせが来なかった。
     * PC の電源が入っていない週はタスク自体が走らないので、ホスト側でも見る。
     */
    $register = (string) @file_get_contents($scripts . '/register-backup-task.ps1');
    $runner = (string) @file_get_contents($scripts . '/backup-task-run.ps1');
    check_bool('週次タスクは backup-task-run.ps1 を呼ぶ', str_contains($register, "'backup-task-run.ps1'"));
    check_bool('失敗したらメールを送る', str_contains($runner, 'send-log.sh'));
    check_bool('失敗したら Windows の通知を出す', str_contains($runner, 'ShowBalloonTip'));
    /*
     * 試験の知らせは件名で分かるようにする(2026-09-13 に試験の1通が本物の失敗と受け取られた)。
     * **`${testMark}` と区切ること** —— 日本語は変数名に使える文字なので、
     * `$testMark手元の…` は未定義の変数になり、見出しの頭が丸ごと消える(実際に一度そう書いた)。
     */
    check_bool('試験の知らせは件名に【試験】を付ける', str_contains($runner, "'【試験】'") && str_contains($runner, '"${testMark}手元の週次バックアップ'));
    check_bool('PC が来ない週をホストで見る', str_contains($backupCode, 'PC_STALE_DAYS'));

    /*
     * ## ビルド元の見張りから漏らさない
     *
     * check-updates.sh は build: のサービスを BUILD_DOCKERFILES の一覧で知る。
     * compose に build: を足して一覧に足し忘れると、そのビルド元は黙って見張られなくなる。
     */
    km_check_heading('update: 系列タグとビルド元の見張り');
    $checkUpdates = (string) @file_get_contents($scripts . '/check-updates.sh');
    $composeText = (string) @file_get_contents($repoRoot . '/compose.yaml') . "\n"
        . (string) @file_get_contents($repoRoot . '/compose.vps.yaml');
    preg_match_all('/^  ([a-z0-9_-]+):[ \t]*\r?\n(?:    [^\n]*\n)*?    build:/m', $composeText, $buildServices);
    $listedBuilds = [];
    if (preg_match('/^BUILD_DOCKERFILES="([^"]*)"/m', $checkUpdates, $buildList) === 1) {
        foreach (preg_split('/\s+/', trim($buildList[1])) ?: [] as $pair) {
            if ($pair !== '') {
                $listedBuilds[] = explode('=', $pair)[0];
            }
        }
    }
    check_bool('build: のサービスを compose から見つけられる', count(array_unique($buildServices[1])) >= 2);
    check(
        'build: のサービスは全部ビルド元を見張る',
        [],
        array_values(array_diff(array_unique($buildServices[1]), $listedBuilds))
    );
    check_bool('系列タグも比べる', str_contains($checkUpdates, 'compare_digest "$_image" series'));
    check_bool('ビルド元を比べる', str_contains($checkUpdates, 'compare_digest "$_base" base'));
    /*
     * FROM は**動いている像の層の先頭**で比べる。手元のビルド元の digest は、
     * BuildKit が像の一覧に残さないので読めないことが多い(2026-09-13 に php も node も読めなかった)。
     * pull してから比べると、古いビルド元で作った像のまま「変化なし」と出てしまう。
     */
    check_bool(
        'FROM は動いている像の層で比べる',
        str_contains($checkUpdates, 'compare_base_layers "$_base" "$_built"')
    );
    check_bool('--pins は貼れない行を出さない', str_contains($checkUpdates, 'ビルドするサービスなので貼らないこと'));
    // 実行記録も一緒に届ける(合言葉つきで暗号化される)
    check_bool(
        '週の便りに実行記録を添える',
        preg_match('/^\s*\d+\s+\d+\s+\*\s+\*\s+0\s+\S+\s+\$BACKUP_SCRIPT[^\n]*--attach-logs/m', $setup) === 1
    );

    /*
     * 配備からバックアップを呼べること。**取り方は1箇所**(backup-data.ps1)で、
     * deploy-to-host.ps1 は呼ぶだけ —— 写すと同じ判断が2つになり、片方だけ直される。
     */
    check_bool('配備からバックアップを呼べる', str_contains($deploy, 'backup-data.ps1'));
    check_bool('解凍は open-backup.ps1 に任せる', str_contains($deploy, 'open-backup.ps1'));

    /*
     * **手元の backup-data.ps1 と同じものを取る。** 片方だけ変えると、
     * 取れているものが食い違い、復元したときに初めて気づく。
     */
    foreach (['mariadb-dump', 'pg_dump', 'uploads', '.env', 'local.php'] as $needle) {
        check_bool('取るもの: ' . $needle, str_contains($backupCode, $needle));
    }
    // 止めずに一貫した断面を取る
    check_bool('MariaDB は単一トランザクションで取る', str_contains($backupCode, '--single-transaction'));
    /*
     * **「ファイルがある」だけでは取れた証拠にならない。**
     * 途中で切れたダンプは、戻すまで壊れていることが分からない。
     */
    check_bool('ダンプの末尾まで確かめる', str_contains($backupCode, 'Dump completed'));
    /*
     * **失敗した回に古いものを消さない。** 消すと手元に1本も残らない。
     */
    check_bool(
        'うまく取れた時だけ片付ける',
        preg_match('/if \[ "\$OK" = "true" \][^\n]*\n[^\n]*\n\s*for _old in/', $backupCode) === 1
    );

    /*
     * **素のまま送らない。** `.env` と `config/*.local.php` が入っているので、
     * 受信箱1つの流出がサーバーの全権になる。
     */
    check_bool('添付は暗号化してから', str_contains($backupCode, 'openssl enc -aes-256-cbc'));
    check_bool('合言葉が無ければ添付しない', str_contains($backupCode, 'BACKUP_PASSPHRASE'));
    // 合言葉をメールに書かない(同じ経路に鍵と錠を流したら意味が無い)
    check_bool('合言葉をメールに載せない', !str_contains($body, 'BACKUP_PASSPHRASE'));
    /*
     * ## .env の書き方で、全部が止まらないように
     *
     * 2026-09-13 18:30、BACKUP_PASSPHRASE に `"` `'` `\` `$` を含む値を入れた直後から、
     * docker compose が .env 全体を拒み、DB の取得もメールも全部落ちた(「mariadb が動いていません」と
     * 別の理由で止まる)。エラー文には値の断片が載る。
     */
    check_bool('先に compose が .env を読めるか確かめる', str_contains($backupCode, 'docker compose config -q >/dev/null 2>&1'));
    check_bool('合言葉の両端の引用符は外して使う', str_contains($backupCode, 'BACKUP_PASSPHRASE="${BACKUP_PASSPHRASE#\"}"'));
    check_bool('引用符・\\・$ を含む合言葉は使わない', str_contains($backupCode, '*\"*|*\\\'*|*\\\\*|*\\$*'));
    check_bool('配備は .env を読めないときに止まり、エラー文を出さない', str_contains($deploy, "-match 'failed to read .*\\.env'"));
    // 添付を作れなかったら添えない(km で手で走らせたとき、mkdir が落ちても先へ進んでいた)
    check_bool('添付を作れなかったら、添えずに理由を書く', str_contains($backupCode, 'if mkdir -p "$ATTACH_DIR" 2>/dev/null && chmod 700 "$ATTACH_DIR"') && str_contains($backupCode, '実行記録を暗号化して添えられませんでした'));
    check_bool('送ったら添付を消す', str_contains($backupCode, 'rm -rf "$ATTACH_DIR"'));
    // 大きすぎる添付は Gmail に捨てられる。判断は PHP 側に置く
    check_bool('大きさの上限がある', defined('KM_BACKUP_NOTICE_ATTACH_LIMIT'));

    /*
     * 実行記録。**捨てずに書き足す** ——
     * `/dev/null` へ流すと、「動いたが何も出なかった」と
     * 「そもそも動いていない」が同じ顔になる。
     *
     * (強調の記号と `/dev/null` を続けて書くと、その並びがコメントを閉じてしまい、
     *  ファイルごと構文エラーになる。実際に踏んだ。)
     */
    check_bool('cron は記録を残す', str_contains($setup, '>>$LOG_DIR/'));
    check_bool('記録を切り詰める', str_contains($setup, 'logrotate.d/kosenmap'));
    check_bool('記録を添えられる', str_contains($backupCode, '--attach-logs'));
}

// ================================================================== powershell

/**
 * 手元スクリプトの文字コード。**中身ではなく、先頭3バイトだけを見る。**
 *
 * ## なぜ検査するのか
 *
 * この罠は**3度踏んでいる**(2026-09-03 / 09-07)。
 *
 * Windows PowerShell 5.1 は、**BOM の無い UTF-8 を CP932 として読む。**
 * 日本語のコメントが化けて、`',' の後に式が存在しません` のような
 * **構文解析の時点での失敗**になる —— 「実行したら失敗」ではなく
 * 「**このファイルは読めない**」なので、何をしようとしたかも出ずに終わる。
 *
 * PowerShell 7 は BOM 無しでも正しく読むため、
 * **`pwsh` で構文解析だけすると 0 件で通ってしまう**(実際そうなった)。
 * 手で `.\xxx.ps1` と叩いた人の側でだけ壊れる。
 *
 * ## sh は逆
 *
 * `.sh` に BOM を付けると、`#!/bin/sh` の前に見えない3バイトが入り、
 * shebang が効かなくなる。**同じ「文字コードを揃える」でも、向きが逆。**
 */
function km_check_powershell(): void
{
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('手元スクリプトの文字コード', '手元の作業ツリーで確認する。配備先に scripts/ は出ない');

        return;
    }

    $bom = "\xEF\xBB\xBF";

    km_check_heading('powershell: BOM 付きの UTF-8 で保存する');
    $missing = [];
    foreach (glob($repoRoot . '/scripts/*.ps1') ?: [] as $path) {
        $head = (string) file_get_contents($path, false, null, 0, 3);
        if ($head !== $bom) {
            $missing[] = basename($path);
        }
    }
    /*
     * **5.1 で読めないファイルを置かない。**
     * 直し方(status.md にも同じものがある):
     *   $t = [System.IO.File]::ReadAllText($p)
     *   [System.IO.File]::WriteAllText($p, $t, [System.Text.UTF8Encoding]::new($true))
     */
    check('BOM の無い .ps1 は無い', [], $missing);

    km_check_heading('powershell: sh には BOM を付けない');
    $withBom = [];
    foreach (glob($repoRoot . '/scripts/*.sh') ?: [] as $path) {
        $head = (string) file_get_contents($path, false, null, 0, 3);
        if ($head === $bom) {
            $withBom[] = basename($path);
        }
    }
    // 見えない3バイトが shebang の前に入ると、`#!/bin/sh` が効かなくなる
    check('BOM の付いた .sh は無い', [], $withBom);

    /*
     * **どの PS1 も -Help でオプションとその意味を出す**(2026-10-16、利用者の指示)。
     * 説明は各スクリプトの先頭のコメント(.SYNOPSIS / .PARAMETER / param() の中のコメント)を km-help.ps1 が読む。
     * 新しい PS1 を足したら、param の最後に [switch]$Help と、param の直後に if ($Help) { … km-help.ps1 … } を足す。
     * 読み込む部品(param を持たない km-ssh.ps1 など)は、直に叩いたときだけ $args を見る(. で読むと何もしない)。
     */
    km_check_heading('powershell: どの PS1 も -Help でオプションとその意味を出す');
    $ps1 = array_merge(glob($repoRoot . '/scripts/*.ps1') ?: [], glob($repoRoot . '/src/scripts/js/*.ps1') ?: []);
    $noHelp = [];
    $lateHelp = [];
    foreach ($ps1 as $path) {
        $code = (string) file_get_contents($path);
        $name = basename($path);
        if ($name === 'km-help.ps1') {
            continue;
        }
        if (str_contains($code, "\$MyInvocation.InvocationName -ne '.' -and \$args -contains '-Help'")) {
            continue;
        }
        if (preg_match('/\[switch\]\$Help\s*\r?\n\)/', $code) !== 1 || !str_contains($code, 'km-help.ps1')) {
            $noHelp[] = $name;
            continue;
        }
        // param の閉じ括弧のすぐあと(空行とコメントだけを挟んで)に if ($Help) が来る。前に処理を置くと -Help でも走る
        if (preg_match('/\[switch\]\$Help\s*\r?\n\)\s*(?:#[^\n]*\n\s*)*if \(\$Help\) \{/', $code) !== 1) {
            $lateHelp[] = $name;
        }
    }
    check('-Help の無い .ps1 は無い', [], $noHelp);
    check('-Help は param の直後で見る(ほかの処理より先)', [], $lateHelp);
    $helper = (string) @file_get_contents($repoRoot . '/scripts/km-help.ps1');
    check_bool('km-help.ps1 がある(BOM 付き)', str_starts_with($helper, $bom));
    check_bool('km-help.ps1 は .PARAMETER と param() の中のコメントの両方から説明を読む',
        str_contains($helper, '$info.Parameters.ContainsKey(') && str_contains($helper, '$described.ContainsKey('));
    // 必須の値があるスクリプトは、-Help を別の組にする(-Help だけで必須の値を聞かれて止まらないように)
    $mandatoryNotSplit = [];
    foreach ($ps1 as $path) {
        // スクリプトの param(行の頭から始まるもの)だけを見る。関数の param(字下げあり)の Mandatory は関係ない
        if (preg_match('/^param\((.*?)^\)/ms', (string) file_get_contents($path), $block) !== 1 || !str_contains($block[1], 'Mandatory')) {
            continue;
        }
        if (preg_match('/Mandatory\s*=\s*\$true\)/', $block[1]) === 1
            || !str_contains($block[1], "[Parameter(ParameterSetName = 'Help')][switch]\$Help")) {
            $mandatoryNotSplit[] = basename($path);
        }
    }
    check('必須の値があるスクリプトは -Help を別の組にする', [], $mandatoryNotSplit);
    // 同じ部品を 4 か所に置く。手元にある写しは同じ中身であること(無ければ見ない)
    $copies = ['C:/Users/itota/Documents/Test/scripts/km-help.ps1', 'F:/Pull/Ichinoseki_Kosen/scripts/km-help.ps1', 'F:/Pull/Ichinoseki_Kosen_Web/tools/km-help.ps1'];
    $differ = [];
    foreach ($copies as $copy) {
        if (is_file($copy) && hash_file('sha256', $copy) !== hash('sha256', $helper)) {
            $differ[] = $copy;
        }
    }
    check('km-help.ps1 の写しはどれも同じ中身', [], $differ);
    $py = (string) @file_get_contents($repoRoot . '/scripts/ar-depth-cloud.py');
    check_bool('手元で動かす Python(ar-depth-cloud.py)にも -Help', str_contains($py, 'parser.add_argument("-Help", "-h", "--help", action="help"'));
    check_bool('check.php 自身も -Help', str_contains((string) file_get_contents(__FILE__), "in_array('-Help', \$arguments, true)"));
}

// ======================================================================= shell

/**
 * ホスト側スクリプトの、**出力そのものが嘘になる**書き方。
 *
 * ## echo にバックスラッシュを渡さない
 *
 * ホストの `/bin/sh` は dash で、その `echo` は**引数のエスケープを解釈する**
 * (bash の echo は解釈しないので、手元で bash 相手に試すと再現しない)。
 *
 *     echo "  .\\backup-data.ps1"   →   ackup-data.ps1
 *
 * `\b` が後退文字(0x08)になり、直前の `.` を消してしまう。
 * **利用者は表示されたとおりに打ち**、`CommandNotFoundException` になった
 * (2026-09-07)。スクリプトは正常終了しているので、**出力を読んでも
 * どこが壊れているか分からない** —— 壊れているのは案内文の方だった。
 *
 * `printf '%s\n' '...'` なら引数側は解釈されないので、書いたものがそのまま出る。
 * (`printf` でも**書式側**の `\b` は解釈されるので、値は必ず引数へ回すこと。)
 */
function km_check_shell(): void
{
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('ホスト側スクリプトの出力', '手元の作業ツリーで確認する。配備先に scripts/ は出ない');

        return;
    }

    km_check_heading('shell: echo にバックスラッシュを渡さない');
    $bad = [];
    foreach (glob($repoRoot . '/scripts/*.sh') ?: [] as $path) {
        $lines = preg_split('/\r\n|\r|\n/',(string) file_get_contents($path)) ?: [];
        foreach ($lines as $i => $line) {
            // 行頭が echo のものだけを見る。コメントや文字列の中の "echo" は対象外
            if (preg_match('/^\s*echo\s+.*\\\\/', $line) === 1) {
                $bad[] = basename($path) . ':' . ($i + 1);
            }
        }
    }
    check('echo の引数にバックスラッシュが無い', [], $bad);

    /*
     * ## 作ったものと消したものを取り違えない
     *
     * host-backup.sh の出力には、同じ形をした名前が二度出る ——
     * いま作ったものと、世代整理でいま消したもの。
     *
     *     /opt/kosenmap/backups/km-backup-ubuntu-20260907-204934.tar.gz.cms
     *     消しました: km-backup-ubuntu-20260907-114924.tar.gz.cms
     *
     * backup-data.ps1 が「それらしい行の最後」を拾っていたため、**消した方を掴んだ**
     * (2026-09-07)。世代整理が何も消さない間は正しく動くので、
     * **置き場が埋まるまで表に出なかった。**
     *
     * 直し方は「文章を上手に読む」ではなく、**機械向けの行を1本立てる**。
     * 両側が同じ合図を使っているかだけを見る。
     */
    km_check_heading('shell: 作ったものと消したものを取り違えない');
    $sh = (string) @file_get_contents($repoRoot . '/scripts/host-backup.sh');
    $ps = (string) @file_get_contents($repoRoot . '/scripts/backup-data.ps1');
    check('host-backup.sh が KM-ARCHIVE を出す', [], $sh === '' || strpos($sh, 'KM-ARCHIVE ') !== false ? [] : ['出していない']);
    check('backup-data.ps1 が KM-ARCHIVE を読む', [], $ps === '' || strpos($ps, 'KM-ARCHIVE') !== false ? [] : ['読んでいない']);
    // 拾い直しの誘惑を断つ。ここが戻ると、また消した方を掴む
    check(
        'backup-data.ps1 は名前を文章から拾わない',
        [],
        preg_match('/km-backup-[^\r\n]*Select-Object\s+-Last/i', $ps) === 1 ? ['文章の最後から拾っている'] : []
    );

    /*
     * ## 空の世代を数えない
     *
     * 世代整理はフォルダの**数**で残す世代を決める。取得が途中で失敗すると
     * 保存先フォルダだけが中身無しで残るので、**空の失敗跡が本物の世代を押し出す。**
     * 「7世代あるはず」が静かに「5世代」になり、**消す方向なので戻せない**
     * (2026-09-07 に2つ残っていた)。
     *
     * 数える前に落としているか、というただ一点を見る。
     */
    /*
     * ## compose: ボリュームの実体名を固定してあるか
     *
     * **ここを外すと、起動は成功したままデータが空になる。**
     *
     * compose はボリュームの実体を `<プロジェクト名>_<宣言名>` で作る。
     * プロジェクト名を `Test` → `kosenmap` に変えた(2026-09-07)が、
     * 本番の中身はすべて `test_*` の実体に入っている。
     * `name:` を書かないと compose は `kosenmap_*` を探し、**無いので黙って新規に作る。**
     *
     *   test_mariadb_data … 消えたように見える(実際は別の実体を見ている)
     *   test_letsencrypt  … 証明書が無い。HSTS のため**誰もサイトへ入れない**
     *   test_mail_dkim    … 鍵が変わり、送信メールが全部迷惑メール行き
     *
     * どれも**起動は成功する**ので、出力からは分からない。
     * だから「宣言したボリュームには必ず name: がある」だけを機械で見る。
     */
    km_check_heading('compose: ボリュームの実体名を固定する');
    $volumeProblems = [];
    $projectProblems = [];
    foreach (['compose.yaml', 'compose.vps.yaml'] as $file) {
        $path = $repoRoot . '/' . $file;
        $text = (string) @file_get_contents($path);
        if ($text === '') {
            $volumeProblems[] = $file . ' が読めない';
            continue;
        }
        $lines = preg_split('/\r\n|\r|\n/',$text) ?: [];

        $inVolumes = false;
        $pending = null;          // name: を待っているボリューム名
        foreach ($lines as $line) {
            if (preg_match('/^\S/', $line) === 1) {
                // 桁 0 の行はトップレベルの鍵。volumes: に入る/出る
                if ($pending !== null) { $volumeProblems[] = $file . ': ' . $pending; }
                $pending = null;
                $inVolumes = (trim($line) === 'volumes:');
                continue;
            }
            if (!$inVolumes) { continue; }
            if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') { continue; }
            if (preg_match('/^  ([A-Za-z0-9_.-]+):\s*$/', $line, $m) === 1) {
                if ($pending !== null) { $volumeProblems[] = $file . ': ' . $pending; }
                $pending = $m[1];
                continue;
            }
            if (preg_match('/^    name:\s*\S/', $line) === 1) { $pending = null; }
        }
        if ($pending !== null) { $volumeProblems[] = $file . ': ' . $pending; }

        // 名前で本番と検証機を見分けられること(同じ顔にしない)
        if (preg_match('/^\s*container_name:\s*dev-/m', $text) === 1) {
            $projectProblems[] = $file . ': container_name が dev- のまま';
        }
        if (preg_match('/^name:\s*[Tt]est\s*$/m', $text) === 1) {
            $projectProblems[] = $file . ': プロジェクト名が Test のまま';
        }
    }
    check('宣言したボリュームに name: がある', [], $volumeProblems);

    /*
     * 本番の compose が `name: Test` / `container_name: dev-*` だったため、
     * 校内 LAN の検証機と `docker ps` の出力が**完全に同じ顔**になっていた。
     * 「テスト環境を止めて」で公開中のサイトを止めかけた(2026-09-07)。
     * 名前は、間違えたときに何が起きるかで選ぶ。
     */
    km_check_heading('compose: 本番と検証機を名前で見分ける');
    check('本番の名前が test/dev のままでない', [], $projectProblems);

    /*
     * ## 動くタグを置かない
     *
     * `nginx:alpine` や `:latest` は**同じ名前のまま中身が入れ替わる。**
     * 2026-09-09 に実測したところ、certbot / mailpit / nginx の3つは
     * **既に中身がずれていた** —— 誰も何も決めていないのに、
     * 次の `up -d` で入れ替わる状態だった。
     *
     * 入れ替わって困るのは中身ではなく**時期**。
     * 更新するつもりで触っていないときに、ついでに変わってしまう。
     * その状態で何かが壊れると、**自分の変更のせいだと思って探す場所を間違える。**
     *
     * digest(`image: nginx@sha256:…`)なら中身そのものを指すので動かない。
     * 上げるときは scripts/check-updates.sh --pins が出す行で置き換える。
     *
     * ### 例外を作っていない
     *
     * `logto` だけは版番号のタグのまま(`1.42.0`)。digest にすると
     * check-updates.sh の版比較(`${IMAGE##*:}` で版番号を読む)が
     * hex を読んでしまい、**毎日「新しい版があります」と言い続ける。**
     * ここは「動くタグ」ではないので、この検査には引っかからない。
     */
    km_check_heading('compose: 動くタグを置かない');
    $floating = [];
    foreach (['compose.yaml', 'compose.vps.yaml'] as $file) {
        $text = (string) @file_get_contents($repoRoot . '/' . $file);
        foreach (preg_split('/\r\n|\r|\n/',$text) ?: [] as $i => $line) {
            if (preg_match('/^\s*#/', $line) === 1) { continue; }
            if (preg_match('/^\s*image:\s*(\S+)\s*$/', $line, $m) !== 1) { continue; }
            $image = $m[1];
            if (strpos($image, '@sha256:') !== false) { continue; }   // digest は動かない
            // タグが無い(= latest と同じ)か、latest / alpine のような動く名前
            $tag = '';
            $slash = strrpos($image, '/');
            $colon = strrpos($image, ':');
            if ($colon !== false && ($slash === false || $colon > $slash)) {
                $tag = substr($image, $colon + 1);
            }
            if ($tag === '' || !preg_match('/\d/', $tag)) {
                $floating[] = $file . ':' . ($i + 1) . ' ' . $image;
            }
        }
    }
    // 数字を含まないタグ(latest / alpine / stable …)は、いつ入れ替わるか決められない
    check('動くタグ(latest 等)を置いていない', [], $floating);

    km_check_heading('shell: 空の世代を数えない');
    $lib = (string) @file_get_contents($repoRoot . '/scripts/backup-lib.ps1');
    check(
        '世代整理が空のフォルダを先に片付ける',
        [],
        $lib === '' || strpos($lib, '空の世代を削除') !== false ? [] : ['片付けていない']
    );
}

// ============================================================== security-notice

/**
 * ホストのセキュリティ通知。**「当たっている」と「効いている」は違う。**
 *
 * `unattended-upgrades` は自動で当てるが、`Automatic-Reboot "false"` にしてある。
 * カーネルを当てたあと、**再起動するまで古いものが動き続ける** ——
 * 促すのは `/var/run/reboot-required` というファイルが1つ置かれるだけで、
 * 誰もログインしなければ何ヶ月でもそのまま。そこを知らせる仕組みの検査。
 *
 * ここは**メールもホストも無しで**文面を確かめられるようにしてある
 * (ホスト側で走らせて初めて分かる、では直す機会が無い)。
 */
function km_check_security_notice(): void
{
    require_once __DIR__ . '/../lib/security-notice.php';

    km_check_heading('security-notice: 受け取った内容の検証');
    $parsed = km_security_notice_parse(
        '{"host":"ito4.jp","os":"Ubuntu 24.04 LTS","items":['
        . '{"kind":"reboot","summary":"再起動しないと更新が効きません","detail":"linux-image"}],'
        . '"deferred":["docker-ce"]}'
    );
    check('宛先のホスト', 'ito4.jp', $parsed['host']);
    check('OS を読む', 'Ubuntu 24.04 LTS', $parsed['os']);
    check('項目を読む', 1, count($parsed['items']));
    check('外しているものを読む', ['docker-ce'], $parsed['deferred']);
    check_bool('heartbeat の既定は false', $parsed['heartbeat'] === false);

    // **黙って捨てない。** 種別も要約も無い行は、作った側が間違えている
    $rejected = false;
    try {
        km_security_notice_parse('{"items":[{"kind":"reboot"}]}');
    } catch (InvalidArgumentException $exception) {
        $rejected = true;
    }
    check_bool('要約の無い項目は拒む', $rejected);

    $badKind = false;
    try {
        km_security_notice_parse('{"items":[{"kind":"なにか","summary":"x"}]}');
    } catch (InvalidArgumentException $exception) {
        $badKind = true;
    }
    check_bool('知らない種別は拒む', $badKind);

    $notJson = false;
    try {
        km_security_notice_parse('これは JSON ではない');
    } catch (InvalidArgumentException $exception) {
        $notJson = true;
    }
    check_bool('JSON でなければ拒む', $notJson);

    km_check_heading('security-notice: 件名');
    $reboot = [['kind' => 'reboot', 'summary' => '再起動が要ります', 'detail' => '']];
    $pending = [['kind' => 'pending', 'summary' => '3件が当たっていません', 'detail' => '']];
    $disk = [['kind' => 'disk', 'summary' => '空きが残り 5%', 'detail' => '']];

    /*
     * **再起動を最優先で出す。** 当たっているのに効いていない、が一番見落とされる。
     * 種別ごとに件名が変わること自体を押さえる —— 同じ件名だと、
     * 受け取る側は開くまで打つ手が分からない。
     */
    check_bool('再起動は件名で分かる', str_contains(km_security_notice_subject($reboot), '再起動'));
    /*
     * **入口が開いていることを最優先で出す。**
     * 総当たりは止められないが、パスワードで入れる状態なら**いつか当たる** ——
     * 再起動の遅れは時間の問題だが、こちらは取り返しがつかない。
     */
    $ssh = [['kind' => 'ssh', 'summary' => 'パスワードで入れます', 'detail' => '']];
    check_bool('SSH は件名で分かる', str_contains(km_security_notice_subject($ssh), 'SSH'));
    check_bool(
        'SSH は再起動より先に出す',
        km_security_notice_subject(array_merge($reboot, $ssh)) === km_security_notice_subject($ssh)
    );
    // **閉めてから入れなくなるのを防ぐ。**手順の順番そのものを押さえる
    $sshBody = km_security_notice_body($ssh, 'h');
    check_bool('先に鍵で入れることを確かめさせる', str_contains($sshBody, '先に確かめる'));
    check_bool('文法確認を挟ませる', str_contains($sshBody, 'sshd -t'));
    check_bool(
        '確かめる手順が閉める手順より前にある',
        strpos($sshBody, '先に確かめる') < strpos($sshBody, 'PasswordAuthentication no')
    );
    check_bool(
        '再起動が混ざれば再起動を優先する',
        km_security_notice_subject(array_merge($pending, $reboot))
            === km_security_notice_subject($reboot)
    );
    check_bool(
        '種別ごとに件名を変える',
        count(array_unique([
            km_security_notice_subject($reboot),
            km_security_notice_subject($pending),
            km_security_notice_subject($disk),
        ])) === 3
    );
    check_bool(
        '問題なしと定期のお知らせを区別する',
        km_security_notice_subject([], false) !== km_security_notice_subject([], true)
    );

    km_check_heading('security-notice: 本文');
    $body = km_security_notice_body($reboot, 'ito4.jp', 'Ubuntu 24.04 LTS');
    check_bool('OS を出す', str_contains($body, 'Ubuntu 24.04 LTS'));
    // 「更新があります」だけのメールでは手が動かない
    check_bool('再起動の手順を出す', str_contains($body, 'sudo reboot'));
    check_bool('会期を避けると書く', str_contains($body, '会期'));
    // **メールに markdown を書かない。** 受け取る側は素のテキストで読む
    check_bool('本文に ** を残さない', !str_contains($body, '**'));
    // 内部の識別子をそのまま出さない
    check_bool('種別は読める言葉で出す', !str_contains($body, '[reboot]'));

    /*
     * **関係の無い手順を並べない。** 全部出すと、どれが自分に当たるのか読み取れない。
     */
    check_bool('出ていない種別の手順は書かない', !str_contains($body, 'docker system df'));
    check_bool(
        '空き容量なら掃除の手順を出す',
        str_contains(km_security_notice_body($disk, 'h'), 'docker system df')
    );

    /*
     * **意図的に外しているものを「問題」と混ぜない。**
     * Docker のパッケージは自動更新の対象外にしてある(デーモンの再起動で
     * コンテナが止まるため)。毎日これで鳴らすと、本物を見落とす。
     */
    $quiet = km_security_notice_body([], 'h', '', true, ['docker-ce', 'containerd.io']);
    check_bool('外しているものは意図と書く', str_contains($quiet, '意図した設定'));
    check_bool('定期のお知らせは沈黙を説明する', str_contains($quiet, '仕組みが止まっている'));
    check_bool('定期のお知らせにも ** を残さない', !str_contains($quiet, '**'));

    // 上限。**読めないほど長いメールを送らない**
    $many = [];
    for ($i = 0; $i < KM_SECURITY_NOTICE_MAX_ITEMS + 3; $i++) {
        $many[] = ['kind' => 'pending', 'summary' => 'pkg' . $i, 'detail' => ''];
    }
    check_bool('多すぎる分は件数でまとめる', str_contains(km_security_notice_body($many, 'h'), 'ほか 3 件'));

    km_check_heading('security-notice: ホスト側の仕込み');
    /*
     * ここから先は `scripts/` の中身を読む。**配備先には出ないので調べられない**
     * (自己検査は web コンテナの中から走り、そこには `src/` しか無い)。
     * 素で見に行くと**配備のたびに 12 件の FAIL が並び、本物が埋もれる。**
     */
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        // **件数を書かない。** 増やすたびに直し忘れて、嘘の数が残る
        check_skip('ホスト側の仕込み(この節すべて)', '手元の作業ツリーで確認する。配備先に scripts/ は出ない');

        return;
    }

    $scripts = $repoRoot . '/scripts';
    $security = (string) @file_get_contents($scripts . '/host-security-check.sh');
    $emergency = (string) @file_get_contents($scripts . '/host-emergency.sh');
    $setup = (string) @file_get_contents($scripts . '/host-updates-setup.sh');

    /*
     * **説明文を外してから中身を見る。**
     *
     * このファイルには「`mariadb-admin` は使わない」「`>(…)` は使わない」と
     * **やらないことの説明**が書いてある。素で探すとその説明に当たって落ちる
     * (`clamp(5px` / `docker compose up` で3度踏んだ形)。
     *
     * 外すのは2つ —— 行頭の `#` のコメントと、人へ見せる手順のヒアドキュメント。
     * 後者は「印字する文字列」であって実行ではない。
     */
    $stripShell = static function (string $source): string {
        $code = (string) preg_replace("/<<'STEPS'.*?\nSTEPS\n/s", '', $source);

        return (string) preg_replace('/^\s*#.*$/m', '', $code);
    };
    $securityCode = $stripShell($security);
    $emergencyCode = $stripShell($emergency);

    check_bool('セキュリティ確認のスクリプトがある', $security !== '');
    check_bool('もしものときのスクリプトがある', $emergency !== '');
    // 仕込む側が知らなければ、置いてあっても一度も走らない
    check_bool('毎日の実行に入れている', str_contains($setup, 'host-security-check.sh'));
    check_bool('月に一度は heartbeat を送る', str_contains($setup, '--notify --heartbeat'));
    /*
     * **在るだけで通さない。版で見る。** 先に仕込んだホストには古い並びが残る。
     * 中身の一部を grep する形だと、**足すたびに見張る文字列を選び直す**ことになり、
     * 選び忘れた変更が黙って素通りする(時刻を変えても書き換わらなかった)。
     */
    check_bool('cron に版がある', str_contains($setup, 'CRON_VERSION='));
    check_bool('版で古さを見分ける', str_contains($setup, 'kosenmap-cron-version: ${CRON_VERSION}'));
    /*
     * セキュリティ確認は **apt-daily-upgrade.timer より後**に置く(既定は 6:00〜7:00)。
     * 前に置くと、**その日当たったぶんを翌日まで知らせない。**
     */
    check_bool('セキュリティ確認は朝の更新より後', str_contains($setup, '23 8 * * *'));
    // 配備は実行ビットを保たない。3本まとめて直す
    check_bool('実行ビットを直す', str_contains($setup, 'chmod +x "$_script"'));
    /*
     * **鶏と卵を断つ。** 手元は Windows なので tar に入る時点で 644 になり、
     * ホストの `.sh` はそのままでは叩けない —— `sudo …/host-security-check.sh` は
     * 「Permission denied」ではなく **`command not found`** と出るので、
     * 「配備されていない」と読み違える(実際にそう読まれた、2026-09-07)。
     *
     * `host-updates-setup.sh --fix` でも直せるが、**それ自体が実行できない。**
     * `host-setup.sh` は標準入力から流し込まれる(実行ビットが要らない)ので、
     * 輪を断てるのはそこだけ。配備のたびに自動で通る。
     */
    $hostSetup = (string) @file_get_contents($scripts . '/host-setup.sh');
    check_bool('配備の後始末で実行ビットを付ける', str_contains($hostSetup, 'chmod +x /p/scripts/'));
    check_bool(
        '4本まとめて面倒を見る',
        str_contains($hostSetup, 'check-updates.sh host-security-check.sh host-updates-setup.sh host-emergency.sh')
    );

    /*
     * **ホストへ送っていなければ、cron は毎晩空振りする。**
     * scripts/ は丸ごと配備しない作りなので、1本ずつ名指しで足す必要がある
     * —— 足し忘れると「仕込んだのに何も来ない」になり、原因が手元から見えない。
     */
    $deploy = (string) @file_get_contents($scripts . '/deploy-to-host.ps1');
    check_bool('確認スクリプトを配備で送る', str_contains($deploy, "'./scripts/host-security-check.sh'"));
    check_bool('通知スクリプトも対で送る', str_contains($deploy, "'./src/scripts/notify-security.php'"));
    // 事故の最中に転送はできない。**先に置いておく**
    check_bool('もしもの1本も送る', str_contains($deploy, "'./scripts/host-emergency.sh'"));

    /*
     * ## Windows のフォルダの「読み取り専用」を、ホストへ持ち込まない
     *
     * Windows のフォルダには読み取り専用の属性が付いていることがあり、bsdtar はそれを
     * **ディレクトリのモード 555** として書く。GNU tar は展開の最後にそのモードを当てるので、
     * **1回目の配備は通り、2回目から `Cannot open: File exists` で全部落ちる**
     * (持ち主の km でも、書けないディレクトリの中のファイルは置き換えられない)。
     * 2026-09-13 に実際に起きた。手元の属性は直しても戻りうるので、展開する側で吸収する。
     *
     * 属性そのもの(`SCHILY.fflags`)は警告の山になって本当のエラーを隠すので、載せない。
     */
    check_bool('配備の tar は Windows のファイル属性を載せない', str_contains($deploy, "'--no-fflags'"));
    check_bool('展開の前後で、書けないディレクトリを書けるように戻す', str_contains($deploy, '[ ! -w "$d" ]'));
    // 二重引用符を含むので、引数ではなく標準入力で渡す(5.1 は引用を崩す)
    check_bool('その展開は標準入力で流す', preg_match('/Invoke-KmSshStdin[^\n]*\$extractScript/', $deploy) === 1);
    /*
     * 前後の chmod だけでは足りない。GNU tar はディレクトリの外へ出た時点でモードを当てるので、
     * 子ディレクトリの項目が先に並ぶ bsdtar のアーカイブでは、**空の場所への初回ですら**落ちる。
     */
    check_bool('展開はディレクトリのモードを最後まで遅らせる', str_contains($deploy, 'tar --delay-directory-restore -xzf km-deploy.tar.gz'));
    check_bool('展開には待つ上限がある', preg_match('/Invoke-KmSshStdin[^\n]*\$extractScript[^\n]*-TimeoutSec\s+\d+/', $deploy) === 1);

    /*
     * ## 標準入力で流したスクリプトの出力を、詰まらせない
     *
     * 標準出力を読み切ってから標準エラーを読むと、**向こうが標準エラーへ大量に書いたとき
     * 双方が相手を待って止まる**(2026-09-13 の配備で、転送のあと無言で止まった)。
     * 回線が生きたまま返ってこない場合に備えて、待つ時間にも上限を置く。
     */
    $kmSsh = (string) @file_get_contents($scripts . '/km-ssh.ps1');
    check_bool('標準出力と標準エラーを同時に読む', str_contains($kmSsh, 'StandardError.ReadToEndAsync()') && !str_contains($kmSsh, 'StandardError.ReadToEnd()'));
    check_bool('標準入力の実行に待つ上限がある', str_contains($kmSsh, '[int]$TimeoutSec') && str_contains($kmSsh, '$process.WaitForExit($TimeoutSec * 1000)'));
    // つながった後に回線が死ぬと、ConnectTimeout では戻ってこない
    check_bool('ssh / scp は死んだ回線を切る', str_contains($kmSsh, "'ServerAliveInterval=15'"));
    /*
     * km-ssh.ps1 は共通の土台。**呼ぶ側にしか無い関数を使わない。**
     * deploy-to-host.ps1 の関数を使っていたため、host-setup.ps1 を単独で走らせると落ちていた(2026-09-14)。
     */
    check_bool('km-ssh.ps1 は deploy-to-host.ps1 の関数に頼らない', !str_contains($kmSsh, 'Get-RemoteShell'));

    /*
     * **当てない。** 当てるのは unattended-upgrades の仕事で、
     * 自動再起動をしないと決めたのにこのスクリプトが再起動したら意味が無い。
     */
    check_bool('セキュリティ確認は再起動しない', !preg_match('/^\s*(sudo\s+)?reboot\b/m', $securityCode));
    check_bool('セキュリティ確認は当てない', !preg_match('/^\s*(sudo\s+)?apt-get\s+(-y\s+)?(install|upgrade|dist-upgrade)\b/m', $securityCode));
    // 試算は変更しない形で呼ぶこと(-s)
    check_bool('未適用は試算で数える', str_contains($securityCode, 'apt-get -s -o Debug::NoLocking=true dist-upgrade'));
    /*
     * 外す一覧を2箇所に持たない。**片方だけ直されると、外したはずのものが鳴き始める。**
     */
    check_bool('外す一覧は設定から読む', str_contains($security, 'Package-Blacklist'));

    /*
     * もしもの時に落ちているのは web かもしれない。**外に頼らない。**
     */
    check_bool('緊急時はメールに頼らない', !str_contains($emergencyCode, 'notify-security.php'));
    // 説明文を外せているか自体を見る(外せていないと、下の検査が自分の文章に当たる)
    check_bool('手順の印字を取り除けた', !str_contains($emergencyCode, 'sudo reboot'));
    check_bool('緊急時は状態を変えない', !preg_match('/docker compose (down|restart|up)\b/', $emergencyCode));
    /*
     * `docker compose exec -T` は標準入力を読む。**`</dev/null` を付けないと
     * スクリプトの残りを食べて**しまい、途中で静かに終わる(既知の罠)。
     */
    /*
     * ---- ホストで実際に走らせて分かったこと(2026-09-07)----
     */
    /*
     * **サービス名を決め打ちしない。** この構成の逆プロキシは `nginx` ではなく
     * `reverse-proxy`。決め打ちしていたので `service "nginx" is not running` としか
     * 出ず、落ちているのか名前が違うのか読み分けられなかった。
     * 名前は `docker compose config --services` に聞く。
     */
    check_bool('サービス名を決め打ちしない', str_contains($emergencyCode, 'docker compose config --services'));
    check_bool(
        '逆プロキシの名前を書かない',
        !preg_match('/(probe|exec -T)\s+["\']?nginx\b/', $emergencyCode)
    );
    /*
     * **資格情報の要る叩き方をしない。** `mariadb-admin ping` は Access denied を返し、
     * サーバーは生きているのに「駄目そう」に見えた。compose の healthcheck を読む。
     */
    check_bool('健康状態は healthcheck を読む', str_contains($emergencyCode, '.State.Health.Status'));
    check_bool('mariadb を直接叩かない', !str_contains($emergencyCode, 'mariadb-admin'));
    /*
     * **SSH の総当たりで本題を埋めない。** 公開ホストでは直近 40 件が
     * すべて sshd の preauth になり、本当のエラーが画面の外へ出た(実測)。
     */
    check_bool('総当たりは件数だけにする', str_contains($emergencyCode, 'SSH への総当たり'));
    // 実際に配っている証明書を見る(ファイルを並べるだけでは、どれが使われているか分からない)
    check_bool('配っている証明書を見る', str_contains($emergencyCode, 's_client -connect 127.0.0.1:443'));

    /*
     * **`sshd -T` に聞く。** `Include /etc/ssh/sshd_config.d/*.conf` があり
     * 後から書かれた方が勝つので、`sshd_config` を読むと**実際と逆の答え**を出す。
     */
    check_bool('SSH の入口を見る', str_contains($securityCode, '-T 2>/dev/null'));
    check_bool('設定ファイルを直接読まない', !str_contains($securityCode, '/etc/ssh/sshd_config"'));

    /*
     * ---- 同じ知らせを繰り返さない ----
     *
     * 見つかったものは**直すまで毎回見つかる。** 再起動が要る状態は
     * 再起動するまで何日でも続くので、毎回送ると**同じメールが毎日届いて
     * 読まれなくなる** —— 本当に新しい知らせが来たときには開かれていない。
     *
     * **2本とも同じ作法にする。** 片方だけだと挙動が食い違い、
     * 「こちらは毎日来るのにあちらは来ない」を故障と読み違える。
     */
    $updates = (string) @file_get_contents($scripts . '/check-updates.sh');
    $updatesCode = $stripShell($updates);
    foreach (['host-security-check.sh' => $securityCode, 'check-updates.sh' => $updatesCode] as $name => $code) {
        check_bool($name . ' は前回の内容を覚える', str_contains($code, 'STATE_FILE'));
        check_bool($name . ' は同じなら送らない', str_contains($code, 'if ! should_send; then'));
        // 念押しが無いと、直さないまま忘れられる
        check_bool($name . ' は日をおいて念押しする', str_contains($code, 'KM_REMIND_DAYS'));
        /*
         * **送れてから覚える。** 先に覚えると、失敗した回で「送った」ことになり、
         * 次からは「前回と同じ」で黙る —— 一度の取りこぼしが**永久の沈黙**になる。
         */
        check_bool(
            $name . ' は送れてから覚える',
            preg_match('/if printf .*\| docker compose exec -T web php scripts\/notify-\w+\.php; then/', $code) === 1
        );
        // 直ったら忘れる。覚えたままだと、再発したときに「変わっていない」で黙る
        check_bool($name . ' は直ったら忘れる', str_contains($code, 'rm -f "$STATE_FILE"'));
        // 月に一度の便りは必ず出す。**ここを抑えると沈黙が正常に見える**
        check_bool(
            $name . ' の月次の便りは抑えない',
            preg_match('/should_send\(\)\s*\{\s*[^}]*HEARTBEAT" -eq 1 \]; then\s*return 0/s', $code) === 1
        );
    }

    /*
     * `docker compose exec -T` は標準入力を読む。**渡す当てが無いと
     * スクリプトの残りを食べて**途中で静かに終わる(既知の罠)。
     *
     * 安全なのは2通り —— `</dev/null` で塞ぐか、**パイプで実際に渡す**か。
     * 後者を落とすと、報告の JSON を流し込んでいる行まで塞げと言うことになり、
     * **通知が空で飛ぶ**(最初そう書いて、この検査に捕まえてもらった)。
     *
     * **数で見る。** 「1箇所でも書いてあれば通る」にすると、
     * 後から足した2本目が無防備でも気づけない。どちらも 0 なら通る。
     */
    foreach (['host-security-check.sh' => $securityCode, 'host-emergency.sh' => $emergencyCode] as $name => $code) {
        $execs = preg_match_all('/docker compose exec/', $code);
        $guarded = preg_match_all('#(\|\s*docker compose exec|docker compose exec[^\n]*</dev/null)#', $code);
        check($name . ' の exec は標準入力の当てがある', $execs, $guarded);
    }
    /*
     * Ubuntu の /bin/sh は dash。**bash だけの書き方は構文解析の時点で落ちる** ——
     * 1行も走らないまま終わるので、出力からは原因が分からない。
     */
    /*
     * **コメントを外してから見る。** 「`>(…)` は使わないこと」と説明している
     * コメント自体に当たって落ちる(styles.css の `clamp(5px` で同じことを踏んだ)。
     * `[[:space:]]` のような文字クラスに当たらないよう、書き方も絞る。
     */
    $bashisms = [
        'プロセス置換' => '/[<>]\s*\(/',
        '[[ ... ]]' => '/(^|\s)\[\[\s/m',
        'function 宣言' => '/^\s*function\s+\w+/m',
        'local' => '/^\s*local\s+\w/m',
    ];
    foreach (['host-security-check.sh' => $security, 'host-emergency.sh' => $emergency] as $name => $source) {
        $code = (string) preg_replace('/^\s*#.*$/m', '', $source);
        $found = [];
        foreach ($bashisms as $label => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $label;
            }
        }
        // Ubuntu の /bin/sh は dash。**構文解析の時点で落ちるので1行も走らない**
        check($name . ' に bash 専用の書き方が無い', [], $found);
    }
}

// ======================================================================== legal

/**
 * 利用規約・プライバシーポリシーと、アカウント自己管理まわり。
 *
 * **文章の中身は検査できない。** ここで見るのは「読める形になっているか」と
 * 「辿り着けるか」——公開されているのに誰からもリンクされていない規約は、無いのと同じ。
 */
function km_check_legal(): void
{
    require_once __DIR__ . '/../lib/legal.php';

    km_check_heading('legal: 文書の形');
    foreach (['利用規約' => km_legal_terms(), 'プライバシーポリシー' => km_legal_privacy()] as $name => $sections) {
        check_bool($name . 'に節がある', $sections !== [], count($sections) . '節');

        $headless = array_values(array_filter(
            $sections,
            static fn (array $section): bool => trim((string) ($section['heading'] ?? '')) === ''
        ));
        check_bool($name . 'の全節に見出しがある', $headless === []);

        // 見出しだけで中身が無い節は、書きかけを公開したときにできる
        $empty = array_values(array_filter(
            $sections,
            static fn (array $s): bool => ($s['paragraphs'] ?? []) === []
                && ($s['list'] ?? []) === []
                && ($s['table'] ?? null) === null
        ));
        check_bool(
            $name . 'に空の節が無い',
            $empty === [],
            $empty === [] ? '' : implode(' / ', array_column($empty, 'heading'))
        );
    }

    km_check_heading('legal: 記法とエスケープ');
    // **エスケープが先。** 逆にすると、本文に紛れたタグがそのまま開く
    check(
        'HTML はエスケープされる',
        '&lt;script&gt;',
        km_legal_inline('<script>')
    );
    check(
        '** は強調になる',
        'これは<strong>大事</strong>です',
        km_legal_inline('これは**大事**です')
    );
    check_bool(
        '強調の中身もエスケープされる',
        km_legal_inline('**<b>x</b>**') === '<strong>&lt;b&gt;x&lt;/b&gt;</strong>'
    );

    km_check_heading('legal: 未記入の検出');
    // 未記入のまま公開されていることを、画面の警告で知らせる仕組みが生きているか
    $placeholders = km_legal_placeholders();
    check_bool(
        '未記入があれば拾える',
        is_array($placeholders),
        $placeholders === [] ? '埋め終わっている' : '未記入: ' . implode(' / ', $placeholders)
    );
    check_bool('未記入なら運営者名がそうと分かる', km_legal_operator_name() !== '');

    /*
     * **どこからも辿れない規約は、無いのと同じ。**
     * 公開ページを足したときに導線を忘れても、ここで気づける。
     */
    km_check_heading('legal: 公開ページからの導線');
    foreach (['index.php', 'faq.php', 'contact.php'] as $page) {
        $source = (string) file_get_contents(__DIR__ . '/../' . $page);
        check_bool(
            $page . ' から両方へ辿れる',
            str_contains($source, '/terms.php') && str_contains($source, '/privacy.php')
        );
    }

    km_check_heading('faq: 答えを空白で挟まない');
    /*
     * `.faq-a` は `white-space: pre-wrap`(答えの改行を残すため)。
     * **タグと出力の間に改行や字下げを入れると、それがそのまま画面に出る。**
     *
     * 実際に出ていた —— 答えの前に空行と空白20個ぶんの字下げが入り、
     * 利用者から「仕様ですか」と聞かれた(2026-09-03)。
     * **見た目では原因が分からない**(ソースの字下げだとは思わない)ので、機械で見る。
     */
    $faqSource = (string) file_get_contents(__DIR__ . '/../faq.php');
    check_bool('pre-wrap を掛けている', str_contains($faqSource, 'white-space: pre-wrap'));

    /*
     * **属性に PHP が入るので、タグを正規表現で切り出さない**(`?>` の `>` に引っかかる)。
     * 見たいのは「答えの出力が、開きタグの直後と閉じタグの直前にあるか」だけ。
     */
    $echo = '<?= km_faq_e((string) $item[\'answer\']) ?>';
    $at = strpos($faqSource, $echo);
    check_bool('答えの出力を見つけられている', $at !== false);
    check_bool(
        '答えの前に空白を入れていない',
        $at !== false && substr($faqSource, $at - 1, 1) === '>'
    );
    check_bool(
        '答えの後ろに空白を入れていない',
        $at !== false && str_starts_with(substr($faqSource, $at + strlen($echo)), '</div>')
    );

    km_check_heading('legal: 管理画面から足した章');
    require_once __DIR__ . '/../lib/legal-db.php';

    /*
     * **本文はコードのまま。** `km_check_legal()` が本文の一文を見て
     * 「実装と文書が食い違っていないか」を確かめているので、
     * DB へ移すとその検査から何も見えなくなる。
     */
    $legalSource = (string) file_get_contents(__DIR__ . '/../lib/legal.php');
    check_bool('本文はコードにある', str_contains($legalSource, 'function km_legal_privacy(): array'));
    $legalAdmin = (string) file_get_contents(__DIR__ . '/../admin/legal.php');
    check_bool('管理画面は本文を書き換えない', !str_contains($legalAdmin, 'km_legal_privacy()['));

    /*
     * 描画は本文と同じ関数を通す。別に描くと **強調** の効き方や見出しがずれる。
     *
     * **実際に出力している所だけ数える** —— 説明の文中にも同じ名前が出るので、
     * ただの文字列として数えると、コメントを1行足しただけで失敗する。
     */
    $legalPage = (string) file_get_contents(__DIR__ . '/../lib/legal-page.php');
    check('本文と足した章を同じ描画で出す', 2, substr_count($legalPage, '<?= km_legal_render_sections('));
    /*
     * **DB が落ちても規約は出す。** 落とすのは追記の方で、土台は落とさない ——
     * 規約とポリシーが読めない状態は、それ自体が問題になる。
     */
    check_bool('DB が読めなくても本文は出す', str_contains($legalPage, 'catch (Throwable $exception)'));

    km_check_heading('legal: 足した章の組み立て');
    $rows = [
        ['heading' => '会場の決まり', 'body' => "一段落目。\n\n二段落目は**大事**。"],
        // 本文が空の章は出さない(見出しだけの空欄が並ぶのを防ぐ)
        ['heading' => '空', 'body' => "   \n\n  "],
    ];
    $sections = km_legal_db_to_sections($rows);
    check('本文の無い章は出さない', 1, count($sections));
    check('見出しはそのまま', '会場の決まり', $sections[0]['heading']);
    // 空行で段落に割る。1つの <p> に長文を入れると本文の章と読み心地が変わる
    check('空行で段落に割る', 2, count($sections[0]['paragraphs']));
    // **強調は本文と同じ関数**。ここで別の書き方をすると効き方が変わる
    check(
        '強調が効く',
        '二段落目は<strong>大事</strong>。',
        km_legal_inline($sections[0]['paragraphs'][1])
    );
    check_bool(
        '足した章もエスケープされる',
        km_legal_inline('<script>') === '&lt;script&gt;'
    );

    km_check_heading('legal: 受け付ける文書');
    // 表の値を推測で通さない
    check('足せるのは2つ', ['terms', 'privacy'], KM_LEGAL_DOCUMENTS);
    $rejected = null;
    try {
        km_legal_db_validate('anything', '見出し', '本文');
    } catch (InvalidArgumentException $exception) {
        $rejected = $exception->getMessage();
    }
    check_bool('知らない文書は受けない', $rejected !== null);

    km_check_heading('legal: アカウント自己管理');
    $client = (string) file_get_contents(__DIR__ . '/../logto-client.php');
    // profile スコープが無いと Account API が 403 を返す
    check_bool('profile スコープを要求している', str_contains($client, 'UserScope::profile->value'));

    $account = (string) file_get_contents(__DIR__ . '/../lib/logto-account.php');
    /*
     * **本人確認を省かせない。** アクセストークンだけでパスワードを変えられると、
     * 端末を借りられた場面やトークンが漏れた場面でそのまま乗っ取られる。
     */
    check_bool(
        'パスワード変更は本人確認を通す',
        str_contains($account, "'/verifications/password'")
            && str_contains($account, 'logto-verification-id: ')
    );
    // 失敗の記録に本文(= パスワード)を混ぜていないか
    check_bool('失敗ログに本文を出さない', !str_contains($account, 'error_log($response'));

    /*
     * 失敗の説明。**推測だけで終わらせない。**
     *
     * 最初の版は 400 をまとめて「入力の内容を確認してください。」にしていたが、
     * 実際に踏んだのは Account center の項目が Off だったときで、入力は何も悪くなかった。
     * この文言のままでは、いくら入力を直しても直らない。
     */
    require_once __DIR__ . '/../lib/logto-account.php';

    $notEditable = km_logto_account_explain(400, ['code' => 'x.y', 'message' => 'Not allowed.']);
    check_bool('400 は Account center を案内する', str_contains($notEditable, 'Account center'));
    // Logto 自身の文言を必ず添える —— こちらの見立てが外れたときの唯一の手がかり
    check_bool('Logto の文言を添える', str_contains($notEditable, 'Not allowed.'));
    check_bool('code も添える', str_contains($notEditable, 'x.y'));

    check_bool(
        '403 はサインインし直しを案内する',
        str_contains(km_logto_account_explain(403, null), 'サインアウト')
    );

    /*
     * **編集できない項目は送らない。**
     *
     * Console 側で ReadOnly / Off にした項目を送ると 400 で断られるが、
     * その応答は「入力が悪い」とも読めるため、利用者は自分の入力を疑って
     * 何度でも直そうとする。送らないと決めてあれば、その失敗自体が起きない。
     */
    /*
     * Logto 自身のアカウント画面への行き先。
     * こちらは表示名とパスワードだけを扱い、メール・電話・MFA・パスキーは向こうに渡す。
     * **リンクを出さないと、案内しているようで案内していない。**
     */
    km_check_heading('legal: Logto の画面への受け渡し');
    check_bool(
        'Account 画面の URL を組み立てられる',
        str_ends_with(km_logto_account_center_url(), '/account')
    );
    check_bool(
        'Account API と同じ Logto を指す',
        str_starts_with(km_logto_account_base(), km_logto_endpoint())
            && str_starts_with(km_logto_account_center_url(), km_logto_endpoint())
    );
    foreach (['account.php', 'admin/profile.php'] as $page) {
        $source = (string) file_get_contents(__DIR__ . '/../' . $page);
        check_bool(
            $page . ' は Logto の画面へリンクする',
            str_contains($source, 'km_logto_account_center_url()')
        );
    }

    km_check_heading('legal: 編集できる項目');
    check_bool('表示名は編集できる', km_logto_account_field_editable('name'));
    // 利用者名はサインインの識別子。Console 側も ReadOnly にしてある
    check_bool('利用者名は編集しない', !km_logto_account_field_editable('username'));
    check_bool('知らない項目は編集しない', !km_logto_account_field_editable('email'));

    foreach (['account.php', 'admin/profile.php'] as $page) {
        $source = (string) file_get_contents(__DIR__ . '/../' . $page);
        check_bool(
            $page . ' は編集可否を見てから入力欄を出す',
            str_contains($source, "km_logto_account_field_editable('username')")
        );
    }

    /*
     * **書いたことと実装を揃える。**
     *
     * 「アカウントを削除すれば紐づく記録も消える」と書いていたが、Logto から
     * 消えたことを知る仕組みが無く、こちらの行は残る。そう書き直してある。
     */
    $privacyText = json_encode(km_legal_privacy(), JSON_UNESCAPED_UNICODE);
    check_bool(
        '自動削除を約束していない',
        !str_contains((string) $privacyText, 'アカウントを削除すると、そのアカウントに紐づく記録は削除されます')
    );
    check_bool(
        'ランキングは自分で消せると書いてある',
        str_contains((string) $privacyText, '参加をやめた時点で削除されます')
    );
    check_bool(
        '404 は Account API の有効化を案内する',
        str_contains(km_logto_account_explain(404, null), 'Account center')
    );
    // 本文が無くても文章として成立すること(空の [Logto: ] を出さない)
    check_bool('本文が無ければ括弧を出さない', !str_contains(km_logto_account_explain(500, null), '[Logto:'));

    /*
     * **アカウント設定は2箇所にある。** 管理画面(admin/profile.php)と
     * 公開ページ(account.php)。前者は管理者しか開けないので、一般ログインの人の
     * 導線は後者しかない —— 導線ごと落ちていないかを見る。
     *
     * どちらも同じ lib を呼ぶので本人確認は共通だが、**CSRF は各ページの責任**なので
     * ここで両方確かめる。
     */
    $accountPages = [
        'admin/profile.php' => (string) file_get_contents(__DIR__ . '/../admin/profile.php'),
        'account.php' => (string) file_get_contents(__DIR__ . '/../account.php'),
    ];
    foreach ($accountPages as $name => $source) {
        check_bool($name . ' は CSRF を確かめる', str_contains($source, 'km_csrf_verify()'));
        check_bool(
            $name . ' はパスワードを記録しない',
            !str_contains($source, "'account.password', ")
        );
        check_bool($name . ' は共有の変更関数を使う', str_contains($source, 'km_logto_account_change_password('));
    }

    // 公開ページから辿れなければ、一般ログインの人には無いのと同じ
    $index = (string) file_get_contents(__DIR__ . '/../index.php');
    check_bool('index.php から アカウント設定へ辿れる', str_contains($index, '/account.php'));
    check_bool('未サインインには出さない', str_contains($index, '<?php if ($loggedIn): ?>'));

    /*
     * アカウントの削除。
     *
     * **Account API には削除が無い**(2026-09-03 に実測)ので、
     * Management API の `DELETE /api/users/{id}` をこちらが代理で呼ぶ。
     * 当初は「Logto の画面で消してもらう」つもりだったが、その道は無かった。
     */
    km_check_heading('legal: アカウントの削除');
    $accountPage = $accountPages['account.php'];
    check_bool('削除について書いてある', str_contains($accountPage, 'アカウントの削除'));
    // **押したら何が消えるのかを、押す前に言う**
    check_bool('何が消えるかを挙げている', str_contains($accountPage, 'ランキングの記録'));
    // 残るものも言う。「全部消える」と読ませない
    check_bool('残るものも言う', str_contains($accountPage, '管理操作の記録だけは残ります'));
    check_bool('元に戻せないと書く', str_contains($accountPage, '元に戻せません'));
    // 端末に残るものも言う。**サーバーだけの話にしない**
    check_bool('端末側は消えないと書く', str_contains($accountPage, '端末側にあります'));

    /*
     * **パスワードを1度確かめてから消す。**
     * アクセストークンだけで消せると、端末を借りられた場面でそのまま消される。
     * 判定はパスワード変更と同じ関数(2箇所に書かない)。
     */
    check_bool(
        '削除の前に本人確認をする',
        str_contains($accountPage, 'km_logto_account_verify_password(')
    );

    /*
     * **こちらのデータが先、Logto が後。**
     * 逆にすると、Logto の削除だけ成功して後片付けが失敗したときに
     * **もう誰のものか分からない行が残る**(user_id しか手がかりが無い)。
     */
    $cleanupAt = strpos($accountPage, 'km_account_delete_data(');
    $logtoAt = strpos($accountPage, 'km_logto_management_delete_user(');
    check_bool('こちらのデータを先に消す', $cleanupAt !== false && $logtoAt !== false && $cleanupAt < $logtoAt);

    // **消えたアカウントのトークンで画面を動かし続けない**
    check_bool('終わったらセッションを捨てる', str_contains($accountPage, 'session_destroy()'));

    /*
     * **削除の記録そのものを匿名で書く。**
     *
     * ここが抜けていた。後片付けが名前と ID を落とした**あと**に
     * 既定のまま記録していたので、最後の1行だけ実名が残り、
     * タイムラインに「Test が削除されたアカウントの情報を片付けました」と
     * 消えた人の名前が出続けた(2026-09-03、利用者の指摘)。
     *
     * **見た目では気づけない。** 消した本人はもう画面を見ていない。
     */
    check_bool(
        '削除の記録に実行者を残さない',
        (bool) preg_match(
            "/km_admin_log_record\(\s*'system',\s*'account\.deleted',[^;]*?,\s*true\s*\)/s",
            $accountPage
        )
    );

    /*
     * 利用者が id を選べる余地を作らない。**渡すのはトークンから来た値だけ。**
     * ここが `$_POST` を見ていたら、他人のアカウントを消せることになる。
     */
    check_bool(
        '消すのはサインインしている本人だけ',
        str_contains($accountPage, "km_logto_management_delete_user(\$subject)")
    );

    km_check_heading('logto-management: 消す口');
    $management = (string) file_get_contents(__DIR__ . '/../lib/logto-management.php');
    // **読むだけの関数に動詞を足さない。** 1文字の間違いが消す呼び出しになる
    check_bool(
        '消す操作は別の関数',
        str_contains($management, 'function km_logto_management_delete_user(')
    );
    check_bool('DELETE を使う', str_contains($management, "CURLOPT_CUSTOMREQUEST => 'DELETE'"));
    // id を URL に埋めるので、必ず符号化する
    check_bool('id を符号化して埋める', str_contains($management, 'rawurlencode($userId)'));
    // 204 が成功。404 は「もう居ない」= 目的は達している
    check_bool('204 と 404 を成功として扱う', str_contains($management, '$status === 204 || $status === 404'));
    // **消せなかったのに「消えた」と誤解させない**
    check_bool(
        '消せなければ例外',
        str_contains($management, "throw new RuntimeException('Logto のアカウントを削除できませんでした")
    );
}

// ======================================================================= layout

/**
 * ボタンの置き場所と、押しやすさ。
 *
 * ## なぜ機械で見るのか
 *
 * ここで壊れるものは、**開いた画面を見ても分からない。**
 *
 *  - 押す面が 30px でも「そこにボタンはある」ので、狭いと気づかない。
 *    気づくのは、指の太い人が現地で隣を押したときだけ
 *  - 折り返し幅(768px)は CSS・app.js・panel.js の3箇所にあり、
 *    ずれると「トップバーからは消えたのに、パネルにも出ていない」幅が生まれる。
 *    その幅にしないと現れない
 *  - 階のレールを右へ移したのに `app.js` が下辺として数えたままだと、
 *    **右が隠れて下が無駄に空く**。地図は出ているので不具合に見えない
 *
 * 見た目の細部まで縛るつもりはない。**「これを外すと静かに壊れる」ものだけ**を置く。
 */
function km_check_layout(): void
{
    $css = (string) file_get_contents(__DIR__ . '/../Main/styles.css');
    $appJs = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    $panelJs = (string) file_get_contents(__DIR__ . '/../Main/panel.js');
    $tuningJs = (string) file_get_contents(__DIR__ . '/../Main/map-tuning.js');
    $index = (string) file_get_contents(__DIR__ . '/../index.php');

    km_check_heading('layout: 押しやすさの下限');
    /*
     * スマホでの下限は **48px**(Android の最小タップ領域と同じ)。
     * PC は指ではなく矢印で狙うので 40px まで詰めてよい。
     */
    $pcTouch = strpos($css, '--km-touch: 40px');
    $mobileBreak = strpos($css, '@media (max-width: 768px)');
    $mobileTouch = strpos($css, '--km-touch: 48px');
    check_bool('PC の下限を決めている', $pcTouch !== false);
    check_bool('スマホの下限を決めている', $mobileTouch !== false);
    check_bool(
        'スマホの下限は折り返しの内側にある',
        $mobileBreak !== false && $mobileTouch !== false && $mobileTouch > $mobileBreak
    );
    check_bool('PC の下限が先に来る(上書きされる側)', $pcTouch !== false && $pcTouch < $mobileTouch);
    // 1〜2箇所だけ直して「揃えた」ことにしない
    check_bool('下限をボタンに効かせている', substr_count($css, 'var(--km-touch)') >= 10);

    /*
     * 地図の上の文字。**下限 5px まで縮む指定が入っていた**(実測: 幅 320px で 9.6px)。
     * 読めない文字を目印に押すことになるので、押し間違えの原因そのものだった。
     *
     * **コメントを外してから見る。** そのままだと「以前は clamp(5px …だった」と
     * 説明しているコメント自体に当たって落ちる(実際に落とした)。
     */
    $cssCode = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    check_bool('地図の上の文字が 5px まで縮まない', !str_contains($cssCode, 'clamp(5px'));

    km_check_heading('layout: 折り返し幅は1つ');
    // 3箇所にある。**同じ値であること**だけを見る(値そのものは CSS が正本)
    check_bool('styles.css が 768px で折り返す', $mobileBreak !== false);
    check_bool('app.js も同じ幅で判断する', str_contains($appJs, 'if (width > 768) {') && str_contains($appJs, 'window.innerWidth <= 768'));
    // panel.js は画面幅で何も入れ替えなくなった(2026-10-23。ログインはいつも引き出しの中)。左の引き出しは CSS の同じ幅で下から出る
    check_bool('左の引き出しも同じ幅で下から出る', preg_match('/@media \(max-width: 768px\) \{[^@]*\.km-sheet \{[^}]*transform: translateY\(105%\)/s', $css) === 1);

    km_check_heading('layout: 階のレールは右');
    check_bool(
        '階の切り替えを右のふちに置いている',
        preg_match('/\.floor-nav\s*\{[^}]*right:\s*var\(--km-edge\)/', $css) === 1
    );
    /*
     * **地図の余白の数え方も一緒に直すこと。**
     * 下辺として数えたままだと、右が隠れたうえに下が無駄に空く。
     */
    check_bool(
        '地図の余白が階のレールを右として数える',
        str_contains($appJs, 'right = Math.max(right, width - floorNav.getBoundingClientRect().left')
    );
    check_bool(
        '地図の余白が操作バーを下として数える',
        str_contains($appJs, "document.querySelector('.km-action-bar')")
    );
    // 片側だけ削ると負の値になりうる。辺ごとに頭を打つ
    check_bool('余白は辺ごとに頭を打つ', str_contains($appJs, 'right = Math.min(right, width * 0.4)'));

    km_check_heading('layout: 地図の上の操作は左下に集める');
    $bar = strpos($index, 'id="km-action-bar"');
    $toggle = strpos($index, 'id="mobile-search-toggle"');
    $headerEnd = strpos($index, '</header>');
    check_bool('操作バーがある', $bar !== false);
    check_bool('検索の開閉は操作バーの中', $bar !== false && $toggle !== false && $toggle > $bar);
    // 上のバーは外した(2026-10-23、利用者の指示)。⚙️ ダウンロード・設定と 🖼️ ポスターも左下の操作バーへ
    check_bool('上のバー(.top-bar)は無い', $headerEnd === false && !str_contains($index, 'class="top-bar"'));
    check_bool('⚙️ と 🖼️ も左下の操作バーの中', ($infoToggle = strpos($index, 'id="info-panel-toggle"')) !== false && $infoToggle > $bar && strpos($index, 'km-poster-btn') > $bar);
    check_bool('表示調整も同じ操作バーへ入る', str_contains($tuningJs, "getElementById('km-action-bar')"));
    // 操作バーの無いページ(admin/map-editor.php)でも出ること。**消えては困る**
    check_bool(
        '操作バーが無いページでも表示調整は出る',
        str_contains($tuningJs, '(bar || document.body).appendChild(button)')
    );
    /*
     * ピルの中身は「絵文字」と「文字」の2要素。**丸ごと書き換えない** ——
     * `textContent` で潰すと、次に開いたとき差し替える先が消えている。
     */
    check_bool('検索ピルを丸ごと書き換えない', !str_contains($appJs, 'mobileSearchToggle.textContent'));
    check_bool('検索ピルは文字だけ差し替える', str_contains($appJs, "querySelector('.km-action-label')"));

    km_check_heading('layout: ログインの2つ');
    /*
     * スマホでは「ダウンロード・設定」パネルへ移す。**同じ要素を動かす。**
     * PHP で2つ書くと、ログイン中と未ログインの出し分けが2箇所になって食い違う。
     */
    check('入口は2つだけ', 2, substr_count($index, 'sign-in.php?mode=signIn'));
    // 2026-10-29 から: 1 回だけ組み立てて(ob_start)、**右上のアカウントの欄の 1 か所だけ**に出す(展示の PC も同じ)。
    // 「ダウンロード・設定」の中だけだと、スマホで引き出しを開いている間 ⚙️ ごと隠れて届かなかった(高専祭の振り返り)
    check_bool('ログインの欄は 1 回だけ組み立てる', substr_count($index, 'id="km-auth-links"') === 1 && str_contains($index, '$kmAuthLinksHtml = (string) ob_get_clean();'));
    check('ログインの欄を出すのは 1 か所だけ', 1, substr_count($index, '<?= $kmAuthLinksHtml ?>'));
    check_bool('出す所は右上のアカウントの欄(テストモードで囲まない)', str_contains($index, '<div class="km-account-menu glass-panel"><?= $kmAuthLinksHtml ?></div>')
        && preg_match('/<\?php if \(\$kmTestMode[^)]*\): \?>\s*<details class="km-account"/', $index) === 0);
    $kmCss = (string) file_get_contents(__DIR__ . '/../Main/styles.css');
    check_bool('アカウントの欄は引き出し・シートより手前で、シートが開いても隠さない', preg_match('/^\.km-account \{[^}]*z-index: 1350;/m', $kmCss) === 1
        && !preg_match('/km-sheet-open[^{]*\.km-account/', $kmCss) && !str_contains($kmCss, '.km-test-auth'));
    check_bool('PC の種類のチップはアカウントの欄の分を空ける', str_contains($kmCss, 'right: calc(var(--km-edge) + var(--km-account-reserve) + 8px);'));
    check_bool('アカウントの欄は外を押す・Esc で閉じる(展示の PC でも。引き出しが無いときの return より前)', ($accountAt = strpos($panelJs, "getElementById('km-account')")) !== false
        && $accountAt < strpos($panelJs, 'if (!toggleBtn'));
    check_bool('panel.js は画面幅で入れ替えない', !str_contains($panelJs, 'placeAuthLinks'));

    // 不具合の報告の入口(2026-10-29、高専祭のアンケートで「不具合の連絡先が分からない」が 5/8)
    require_once __DIR__ . '/../lib/forms.php';
    $contactCode = (string) file_get_contents(__DIR__ . '/../contact.php');
    $postersCode = (string) file_get_contents(__DIR__ . '/../posters.php');
    $guideChatJs = (string) file_get_contents(__DIR__ . '/../Main/guide-chat.js');
    check_bool('不具合の報告: 操作の帯・経路の帯・案内チャット・ポスターの一覧から、別のタブで開く',
        str_contains($index, 'href="/contact.php?subject=bug&amp;where=map" target="_blank" rel="noopener"')
        && str_contains($index, 'href="/contact.php?subject=bug&amp;where=route" target="_blank" rel="noopener"')
        && str_contains($guideChatJs, "bug.href = '/contact.php?subject=bug&where=guide';")
        && str_contains($postersCode, 'href="/contact.php?subject=bug&amp;where=posters"'));
    check_bool('不具合の報告: 操作の帯の入口はテストモードでも出す(⚙️ のように囲まない)', preg_match('/<\?php if \(\$kmTestMode[^)]*\): \?>\s*<a href="\/contact\.php\?subject=bug/', $index) === 0);
    check('不具合の報告: ひな形(どこで・端末・時刻だけ。知らない where は「Web サイト」)',
        "【どこで】地図(経路の案内中)\n【端末】スマホ(Android / Chrome)\n【時刻】2026-10-29 21:05\n【何が起きたか】\n",
        km_contact_bug_template('route', 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36', (int) strtotime('2026-10-29 21:05:00')));
    check('不具合の報告: 端末の見分け', ['PC(Windows / Edge)', 'スマホ(iOS / Safari)', 'タブレット(iOS / Safari)', 'PC(Mac / Firefox)'], [
        km_contact_device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0'),
        km_contact_device('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'),
        km_contact_device('Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'),
        km_contact_device('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:131.0) Gecko/20100101 Firefox/131.0'),
    ]);
    check_bool('不具合の報告: where は決まった値だけ使い、そのまま本文に入れない', str_contains((string) file_get_contents(__DIR__ . '/../lib/forms.php'), "(KM_CONTACT_WHERE_LABELS[\$where] ?? 'Web サイト')")
        && str_contains(km_contact_bug_template('<script>', '', 0), '【どこで】Web サイト') && str_contains($contactCode, "km_contact_bug_template((string) (\$_GET['where'] ?? '')"));

    km_check_heading('layout: ズームボタン');
    // 左下は操作ピルが使う。ぶつからない側へ
    check_bool('ズームは右下', str_contains($appJs, "L.control.zoom({ position: 'bottomright' })"));
    check_bool('zoom.js を読み込んでいない', !str_contains($index, 'Main/zoom.js'));
    check_bool('zoom.js は src/Main に残っていない', !file_exists(__DIR__ . '/../Main/zoom.js'));
    /*
     * 消さずに退避する(戻し方は Old/README.md)。
     * **`Old/` は配備されない**ので、ホストからは調べようがない。
     * `src/Main` に残っていないことの方は、**ホストでこそ意味がある** ——
     * 配備は消さないので、退役したファイルはホストに残り続ける
     * (片付けるのは `host-setup.sh --fix`)。
     */
    $repoRoot = km_check_repo_root();
    if ($repoRoot === null) {
        check_skip('zoom.js は Old/ に退避してある', '手元の作業ツリーで確認する。配備先に Old/ は出ない');
    } else {
        check_bool('zoom.js は Old/ に退避してある', file_exists($repoRoot . '/Old/Main/zoom.js'));
    }

    km_check_heading('layout: 階のレールを畳む');
    /*
     * ふだんは丸1つ(いまの階)。押したときだけ一覧を出す。
     * **取っ手は送り(.floor-scroll)の外に置くこと** —— 中に入れると、
     * 一覧を下まで送ったときに取っ手ごと画面の外へ出て、閉じ方が消える。
     */
    $floorToggle = strpos($index, 'id="floor-toggle"');
    $floorScroll = strpos($index, 'id="floor-scroll"');
    check_bool('畳むための取っ手がある', $floorToggle !== false);
    check_bool('取っ手は一覧の外(送りに巻き込まない)', $floorToggle !== false && $floorScroll !== false && $floorToggle < $floorScroll);
    check_bool('取っ手の中に差し替える先がある', str_contains($index, 'class="floor-toggle-label"'));
    // 検索ピルで一度やらかしている。丸ごと潰すと次に差し替える先が消える
    check_bool('取っ手を丸ごと書き換えない', !str_contains($appJs, 'toggle.textContent'));
    /*
     * **透明にして残さない。** 畳んだつもりの一覧が指の下に居座り、
     * 地図を触ったつもりで階が変わる。
     */
    check_bool(
        '畳んだら一覧は消える(display:none)',
        preg_match('/\.floor-nav\.is-collapsed\s+\.floor-scroll\s*\{[^}]*display:\s*none/', $css) === 1
    );
    check_bool('階を選んだら畳む', str_contains($appJs, 'setFloorNavCollapsed(true)'));
    check_bool('しばらく触らなければ畳む', str_contains($appJs, 'KM_FLOOR_IDLE_MS'));
    /*
     * **fitBounds では畳まない。** Leaflet は zoomstart をプログラムからの
     * 移動でも出すので、そのまま拾うと起動直後の収まり合わせで
     * 一度も見せないまま畳んでしまう。人の操作かどうかは `originalEvent` で見る
     * —— この見分けは `userMovedMap` が既に持っており、**判断を2つ持たない**。
     */
    check_bool('人が動かしたときだけ畳む', str_contains($appJs, '!event.originalEvent) return'));
    check_bool('地図を触ったら畳む', str_contains($appJs, "window.map.on('dragstart'"));
    // 状態は先に宣言する(dragstart の登録がこの節より前にあるため)
    check_bool(
        '畳む状態は使う前に宣言している',
        strpos($appJs, 'let floorIdleTimer') < strpos($appJs, "window.map.on('dragstart'")
    );
    /*
     * 指で1回触ると pointerenter は来るが pointerleave は来ない。
     * 種類を見ないと、スマホでは一度触った時点で二度と畳まなくなる。
     */
    check_bool('乗っているかはマウスのときだけ数える', str_contains($appJs, "pointerType === 'mouse'"));
    /*
     * 畳むと一覧は `display: none` で消える。**キーボードの位置が中にあるまま
     * 消すと、フォーカスが body へ飛ぶ**(タブで辿っている人は現在地を失う)。
     * 窓が裏に回ると `:focus` はどれにも当たらないので、activeElement で見る。
     */
    check_bool(
        '一覧にキーボードの位置があるうちは畳まない',
        str_contains($appJs, 'scroll.contains(document.activeElement)')
    );
    /*
     * 横向き(低い画面)は列を増やして高さを抑える。
     * **取っ手を足したぶんレールが 54px 伸びた** —— 2列のままでは
     * 高さ 400px でズームに 25px かぶった(実測)。3列 2段で元の下端に戻る。
     */
    check_bool(
        '低い画面では3列に畳む',
        preg_match('/@media \(max-height: 600px\).*?grid-template-columns:\s*repeat\(3/s', $css) === 1
    );
    /*
     * **畳んでも地図の収まりは取り直さない。**
     * 取り直すと、何も操作していないのに一定時間後に地図がひとりでに動く。
     */
    $collapseFn = '';
    if (preg_match('/function setFloorNavCollapsed\(collapsed\)\s*\{.*?\n    \}/s', $appJs, $m)) {
        $collapseFn = $m[0];
    }
    check_bool('畳む処理を持っている', $collapseFn !== '');
    check_bool('畳んでも地図を取り直さない', $collapseFn !== '' && !str_contains($collapseFn, 'refit'));

    km_check_heading('layout: 検索の開け閉て');
    /*
     * 的は2つ —— パネルの中の ✕ と、左下のピル。
     * PC ではパネルが左上・ピルが左下で画面の端から端まで離れるので、
     * **パネルを見ている人の目の中にも閉じ方を置く。**
     * ピルは残す(閉じたあとに開き直す的は、パネルの外に要る)。
     */
    $panelPos = strpos($index, 'id="search-panel"');
    $searchClose = strpos($index, 'id="search-close"');
    check_bool('パネルの中に閉じる的がある', $searchClose !== false);
    check_bool(
        '閉じる的はパネルの中',
        $panelPos !== false && $searchClose !== false && $bar !== false
            && $searchClose > $panelPos && $searchClose < $bar
    );
    check_bool('開き直す的は外に残っている', $toggle !== false);
    // 状態を2つ持たない。同じ関数を通すので、閉じるとピルの顔も一緒に戻る
    check_bool('閉じる的も同じ関数を通す', str_contains($appJs, "getElementById('search-close')"));

    km_check_heading('layout: 経路の欄はスマホで上いっぱい・キーボードに隠れない(2026-10-24)');
    /*
     * 利用者の指摘:「経路を調べるを開いた時、キーボードの影響で、検索バーが隠れてしまいます。
     * また、前面と背面の調整がうまくいっていない」。
     *
     *   - 欄は**上いっぱい**に出し、検索の窓とチップの場所に入れ替わる(下から出すと、出発地を押した
     *     ときのキーボードの裏に入力欄が入り、ブラウザがページごと上へ送って窓が画面の外へ出た)
     *   - 階の好みと雨の日は「経路の設定」に畳み、キーボードが出ても入力欄と案内開始が見える高さにする
     *   - 重ね順を決める(決めていなかったので、あとに書いた左下の操作と階の列が欄の上に出ていた)
     */
    check_bool(
        'スマホの経路の欄は上いっぱい',
        preg_match('/@media \(max-width: 768px\).*?\.search-panel \{\s*top: var\(--km-edge\);\s*left: var\(--km-edge\);\s*right: var\(--km-edge\);/s', $css) === 1
    );
    check_bool('下から出すときの置き場所は残っていない', !str_contains($css, 'body.km-route-active .search-panel'));
    check_bool('畳むときは上へ逃がす', preg_match('/\.search-panel\.collapsed \{\s*transform: translateY\(calc\(-100% - 40px\)\);/s', $css) === 1);
    check_bool('欄を開いている間は検索の窓とチップを出さない', preg_match('/body\.km-route-panel-open \.km-searchbox,\s*body\.km-route-panel-open \.km-chips \{ visibility: hidden; \}/', $css) === 1);
    check_bool('候補はキーボードの上に収まる長さで止める', str_contains($css, '.search-panel .km-search-results { max-height: 34vh; }'));
    check_bool('収まりの計算はスマホでは欄の下から数える', preg_match('/\/\/ デスクトップでは左に立つ縦長のカード、モバイルでは上いっぱいの欄.*?top = Math\.max\(top, rect\.bottom \+ PANEL_GAP\);/s', $appJs) === 1);
    // 重ね順: 経路の欄(1150) > 階の列・左下の操作(900)。左の引き出し(1200)と検索の窓(1300)より下
    check_bool('経路の欄の重ね順を決めている', preg_match('/\.search-panel \{ overflow: visible; z-index: 1150; \}/', $css) === 1);
    check_bool('階の列の重ね順を決めている', preg_match('/\.floor-nav \{[^}]*z-index: 900;/s', $css) === 1);
    check_bool('階の好みと雨の日は畳む', str_contains($index, '<details class="km-route-options">') && str_contains($index, 'id="km-route-options-state"'));
    check_bool('畳んでいても効いている設定を見出しに出す', str_contains($appJs, 'function updateRouteOptionsState()') && str_contains($appJs, "parts.push('雨の日')"));
    // display: flex にすると既定の ▶ が消えるブラウザがある。開き閉じの印は自分で出す
    check_bool('開き閉じの印を出す', preg_match("/\.km-route-options > summary::before \{\s*content: '▸';/", $css) === 1
        && str_contains($css, ".km-route-options[open] > summary::before { content: '▾'; }"));
    /*
     * ページごと送られたままにしない。html にも overflow: hidden を掛け(地図のページだけ。文書のページは送れること)、
     * 高さは見えている範囲(dvh)にする。100vh は URL の欄を畳んだときの高さで、見えている範囲より長い
     */
    check_bool('地図のページは送らない', preg_match('/html:has\(#ui-layer\) \{\s*height: 100%;\s*overflow: hidden;\s*\}/', $css) === 1);
    check_bool('高さは見えている範囲で測る', preg_match('/body \{[^}]*height: 100vh;\s*height: 100dvh;/s', $css) === 1);
    check_bool('入力欄から離れたら送りを戻す', str_contains($appJs, 'function resetPageScroll()')
        && str_contains($appJs, "document.addEventListener('focusout', () => window.setTimeout(resetPageScroll, 150));")
        && str_contains($appJs, "window.visualViewport.addEventListener('resize', () => window.setTimeout(resetPageScroll, 150));"));
    // 打っている最中に戻すと、ブラウザが入力欄を見せようとした位置と喧嘩する
    check_bool('打っている最中は戻さない', preg_match("/function resetPageScroll\(\)\s*\{\s*const active = document\.activeElement;\s*if \(active && \(active\.tagName === 'INPUT'/s", $appJs) === 1);

    km_check_heading('layout: 種類のチップはスマホで 1 列・横に送る(2026-10-24)');
    /*
     * 利用者の指示:「トイレや教室などは、１列にし横スクロールが出来るようにしてください」。
     * 前日に折り返す形にしたが、2 行で地図の上を広く覆っていた。帯は画面の端から端まで取り、内側に余白を置く
     * (端で切れて見えるので、まだ右に続くと分かる)
     */
    $chipsMobile = '';
    // 行の先頭から始まる方だけを見る(同じ媒体の問い合わせに body.km-route-panel-open .km-chips もある)
    if (preg_match('/@media \(max-width: 768px\).*?\n    \.km-chips \{(.*?)\}/s', $css, $m)) {
        $chipsMobile = $m[1];
    }
    check_bool('1 列で折り返さない', str_contains($chipsMobile, 'flex-wrap: nowrap;'));
    check_bool('横に送れる', str_contains($chipsMobile, 'overflow-x: auto;') && str_contains($chipsMobile, '-webkit-overflow-scrolling: touch;'));
    check_bool('送りが地図やページへ漏れない', str_contains($chipsMobile, 'overscroll-behavior-x: contain;'));
    check_bool('端まで使い、内側に余白を置く', str_contains($chipsMobile, 'left: 0;') && str_contains($chipsMobile, 'padding: 0 var(--km-edge);'));
    // 下の部品の位置は 1 行ぶん(44px)。2 行ぶん(78px)のままだと、チップの下に空きができる
    check_bool(
        '下の部品の位置は 1 行ぶん',
        preg_match('/@media \(max-width: 768px\).*?--km-top-offset: calc\(var\(--km-edge\) \+ var\(--km-searchbox-height\) \+ 8px \+ 44px \+ 8px\)/s', $css) === 1
    );

    km_check_heading('layout: ルート案内の帯(2026-09-25)');
    /*
     * 下から出るシート(guidance-panel)は、案内を始めた途端に画面の半分以上を埋めていた。
     * **1 行の帯**にして、左から ✕ / 行き先 / 位置の更新 の順に並べる。
     */
    check_bool('シートは残っていない', !str_contains($index, 'id="guidance-panel"') && !str_contains($appJs, "getElementById('guidance-panel')"));
    $routeBarPos = strpos($index, 'id="km-route-bar"');
    $routeClose = strpos($index, 'id="km-route-close"');
    $routeTarget = strpos($index, 'id="km-route-target"');
    $routeNext = strpos($index, 'id="km-route-next"');
    check_bool('帯がある', $routeBarPos !== false);
    check_bool(
        '並びは ✕ → 行き先 → 位置の更新',
        $routeBarPos !== false && $routeClose !== false && $routeTarget !== false && $routeNext !== false
            && $routeBarPos < $routeClose && $routeClose < $routeTarget && $routeTarget < $routeNext
    );
    check_bool('ボタンの名前は「位置の更新」', str_contains($index, '>位置の更新</button>'));
    /*
     * 「位置の更新」は**今いる所を聞いて引き直す**(2026-09-25、利用者の指示)。
     * 以前は「次の区間へ進む」だったが、Web は現在地を測れないので、見えている部屋の名前を選んでもらう。
     */
    check_bool('位置の更新は地点を選んでもらう', str_contains($appJs, '現在見える部屋と合う名前のノードを選択してください'));
    check_bool('選んだ地点から引き直す', preg_match('/function pickPosition\(nodeId\).*?startInput\.value = getLabelForNode\(nodeId, node\).*?searchBtn\.click\(\)/s', $appJs) === 1);
    check_bool('地図の地点を押しても選べる', str_contains($appJs, "window.kmRoutePicking(id)"));
    check_bool('選べない地点は受け取らない', preg_match('/function pickPosition\(nodeId\).*?if \(!isRoutableNode\(node\)\) return false;/s', $appJs) === 1);
    check_bool('次の区間へ進むだけの動きは残っていない', !str_contains($appJs, 'function advanceRouteStep'));
    // display:flex は hidden 属性に勝つ。隠したつもりの帯が地図を塞がないように
    check_bool('隠した帯は消える', preg_match('/\.km-route-bar\[hidden\]\s*\{\s*display:\s*none/', $css) === 1);
    /*
     * **ほかの操作を隠さない。** 右は階のレール(とその下のズーム)を避け、
     * 下は左下の操作ピルの上に置く。帯が出ている間、左下から出る面は帯の上へ逃げる。
     */
    // 置き場所は帯と候補を積む入れ物(.km-route-dock)が決める。帯は問いかけで 2 行に伸びるので、候補と別々に置くと重なる
    check_bool('帯は階のレールを避ける', preg_match('/\.km-route-dock\s*\{[^}]*var\(--km-rail-width\)/s', $css) === 1);
    // 2026-10-24 から、操作の帯の**実際の高さ**の上(スマホでは操作が 2 行に折り返し、1 行ぶんの決め打ちでは重なった)
    check_bool('帯は操作ピルの上', preg_match('/\.km-route-dock\s*\{[^}]*bottom:\s*calc\(var\(--km-bottom\) \+ var\(--km-action-bar-height, var\(--km-touch\)\) \+ 8px\)/s', $css) === 1
        && str_contains($appJs, "document.documentElement.style.setProperty('--km-action-bar-height', `\${actionBarEl.offsetHeight}px`);"));
    check_bool('候補は帯と同じ入れ物に積む', preg_match('/class="km-route-dock">\s*<div id="km-route-pick"/', $index) === 1);
    check_bool('表示調整は帯の上へ逃げる', str_contains($css, '.km-tuning { margin-bottom: var(--km-route-lift); }'));
    check_bool('収まりの計算が帯を数える', str_contains($appJs, "getElementById('km-route-bar')"));
    check_bool('経路は帯とパネルを避けて収める', str_contains($appJs, 'const padding = mapFitPadding();') && str_contains($appJs, 'fitBounds(pathBoundsGroup.getBounds(), padding)'));

    km_check_heading('layout: ダウンロード・設定は横から(2026-09-25)');
    check_bool('引き出しになっている', str_contains($index, 'id="info-panel" class="km-drawer'));
    // 閉じている間に見えない的へフォーカスが入らないように
    check_bool('閉じている間は inert', str_contains($index, 'aria-hidden="true" inert') && str_contains($panelJs, 'panel.inert = !open'));
    check_bool('幕を押しても閉じる', str_contains($panelJs, "scrim.addEventListener('click'"));
    check_bool('Esc でも閉じる', str_contains($panelJs, "event.key === 'Escape'"));
    check_bool('動きを減らす設定では滑らせない', preg_match('/prefers-reduced-motion: reduce\)\s*\{\s*\.km-drawer,/', $css) === 1);

    km_check_heading('layout: 地図が描けないときは操作を下げる(2026-09-25)');
    /*
     * 錠が掛かっていると初期化が途中で止まり、検索・階・表示調整のボタンは
     * **押しても何も起きない**まま残っていた(スマホでは検索シートが解除欄に被さった)。
     */
    $lockAt = strpos($appJs, 'graphData.mapLocked === true) {');
    check_bool('錠のときに印を付ける', $lockAt !== false && strpos($appJs, 'markMapUnavailable();', $lockAt) !== false
        && strpos($appJs, 'markMapUnavailable();', $lockAt) < strpos($appJs, 'kmLoading.finish();', $lockAt));
    check_bool('印が付いたら検索と階を隠す', preg_match('/body\.km-map-unavailable \.search-panel,\s*body\.km-map-unavailable \.km-action-bar,\s*body\.km-map-unavailable \.floor-nav/', $css) === 1);

    km_check_heading('layout: はじめに(2026-09-25)');
    check_bool('はじめにがある', str_contains($index, 'id="km-welcome"'));
    check_bool('規約とプライバシーへ辿れる', preg_match('/id="km-welcome".*href="\/terms\.php".*href="\/privacy\.php"/s', $index) === 1);
    check_bool('迷惑メールのことを書いている', str_contains($index, '迷惑メールフォルダ'));
    check_bool('版で出し直せる', str_contains($index, 'data-version="<?= km_home_e($kmWelcomeVersion) ?>"'));
    check_bool('welcome.js を読む', str_contains($index, "km_public_asset('Main/welcome.js')"));
    $welcomeJs = (string) file_get_contents(__DIR__ . '/../Main/welcome.js');
    // 覚えられない環境(プライベートウィンドウ)で落ちないこと
    check_bool('保存の失敗で止まらない', substr_count($welcomeJs, 'try {') >= 2);
    check_bool('設定から読み直せる', str_contains($index, 'data-km-welcome-open'));
    // 共有のリンク(部屋の QR・場所・経路)から来た人には出さない(2026-10-14 に QR、2026-10-24 に共有全部)。出すと開いた所が幕の下に隠れる
    check_bool('共有のリンクから来た人には出さない', str_contains($welcomeJs, 'meta[name="km-from-share"]') && str_contains($welcomeJs, 'if ((!seen() || unreadNotices().length > 0) && !fromShare) open();'));

    km_check_heading('tutorial: 初回の操作案内(2026-10-15)');
    $tutorialJs = (string) file_get_contents(__DIR__ . '/../Main/tutorial.js');
    // スマホ(2026-10-19、利用者の指摘「スマホでチュートリアルの表示がうまくいっていない」):
    // 閉じた検索の欄の中の欄(カテゴリー検索)に吹き出しを置き、画面の外へ出て見えなかった。キーボードの裏にも隠れた
    check_bool('tutorial: 見える範囲の外にある所は「見えない」とみなす(visual viewport で測る)', str_contains($tutorialJs, 'const vv = window.visualViewport;')
        && str_contains($tutorialJs, 'return r.bottom > box.top + 4 && r.top < box.top + box.height - 4'));
    check_bool('tutorial: 検索の欄の中の所が見えなければ「検索」を指して開いてもらう', str_contains($tutorialJs, "el.closest('#search-panel') && toggle && visible(toggle)"));
    check_bool('tutorial: 吹き出しは必ず見える範囲に収める・キーボードの出し入れで置き直す', str_contains($tutorialJs, 'top = clampTop(top, ch);')
        && str_contains($tutorialJs, "window.visualViewport.addEventListener('resize', place);") && str_contains($tutorialJs, "window.visualViewport.removeEventListener('resize', place);"));
    // 経路の欄が上いっぱいになった(2026-10-24)ので、欄の中を指すときは**下**に固定する。上には何も置けない
    check_bool('tutorial: スマホで経路の欄の中を指すときは下に置く', str_contains($tutorialJs, "const inRoutePanel = narrowScreen() && el.closest && el.closest('#search-panel') !== null;")
        && str_contains($tutorialJs, 'const placeAbove = !inRoutePanel && !fitsBelow && fitsAbove;'));
    check_bool('tutorial.js を welcome.js のあとに読む', ($tw = strpos($index, "Main/welcome.js')")) !== false && $tw < (int) strpos($index, "Main/tutorial.js')"));
    check_bool('「はじめに」を閉じてから始める', str_contains($welcomeJs, "new CustomEvent('km-welcome-closed')") && str_contains($tutorialJs, "'km-welcome-closed'"));
    check_bool('地図が出てから始める', str_contains($appJs, "new CustomEvent('km-map-ready')") && str_contains($tutorialJs, "'km-map-ready'"));
    check_bool('共有のリンクから来た人と錠のときは出さない', str_contains($tutorialJs, 'meta[name="km-from-share"]') && str_contains($tutorialJs, "classList.contains('km-map-unavailable')"));
    check_bool('最初の手順は場所の検索(2026-10-23 から Google マップ風。選ぶと次へ)', str_contains($tutorialJs, "return [byId('km-place-input'), 'km-place-chosen'];") && str_contains($appJs, "placeInput.dispatchEvent(new CustomEvent('km-place-chosen'));"));
    check_bool('幕は見た目だけ(案内の上からも押せる)', preg_match('/\.km-tour \{[^}]*pointer-events: none/', $css) === 1);
    check_bool('テスト用アカウントは来場者ごとに出す', str_contains($tutorialJs, "SEEN_KEY + ':visit:'"));
    check_bool('設定からもう一度見られる', str_contains($index, 'data-km-tutorial-open'));
    check_bool('押す所を追いかける(シートが滑って出る)', str_contains($tutorialJs, 'window.requestAnimationFrame(follow);'));

    km_check_heading('map-access: イベント中だけパスワード無しで地図を出す(2026-10-15)');
    $accessLib = (string) file_get_contents(__DIR__ . '/../lib/map-access.php');
    check_bool('既定は出さない(厳密に true のときだけ)', str_contains($accessLib, "'eventOpensMap' => (\$config['eventOpensMap'] ?? false) === true,"));
    check('設定が無ければイベントを見に行かない', false, km_map_event_opens_view(['mapMode' => 'password']));
    check_bool('地図の錠だけに効く(氏名の錠には足さない)', str_contains($accessLib, "km_map_teacher_like_session(false) || km_map_event_opens_view(\$config)")
        && !preg_match("/'password' => km_map_password_entered\(\\\$config\) \|\| km_map_teacher_like_session\(true\)[^,]*km_map_event_opens_view/", $accessLib));
    check_bool('確かめられなければ開けない', str_contains($accessLib, "error_log('km_map_event_opens_view failed (map stays locked): '") && str_contains($accessLib, '$active = false;'));
    check_bool('保存で設定ファイルに書く', str_contains($accessLib, "\"    'eventOpensMap' => \" . var_export(\$resolvedEventOpensMap, true)"));
    check_bool('管理画面にチェックがある', str_contains((string) file_get_contents(__DIR__ . '/../admin/map-settings.php'), 'name="event_opens_map"'));

    km_check_heading('test-mode: テスト用アカウントは係の人の合言葉でだけログアウトできる(2026-10-19)');
    require_once __DIR__ . '/../lib/test-mode.php';
    $pinHash = password_hash('Kakari2026', PASSWORD_DEFAULT);
    $t0 = 1_760_000_000;
    check('合言葉が決まっていなければ出さない', 'unset', km_test_signout_decide('', [], 'Kakari2026', $t0)['result']);
    check('合言葉が合えば出す・数えを戻す', ['result' => 'ok', 'fail' => ['count' => 0, 'until' => 0]], km_test_signout_decide($pinHash, ['count' => 3], 'Kakari2026', $t0));
    check('違えば数える', ['result' => 'wrong', 'fail' => ['count' => 1, 'until' => 0]], km_test_signout_decide($pinHash, [], 'kakari2026', $t0));
    check('空は違う', 'wrong', km_test_signout_decide($pinHash, [], '', $t0)['result']);
    $fifth = km_test_signout_decide($pinHash, ['count' => 4], 'x', $t0);
    check('5 回続けて違えば 10 分受け付けない', ['result' => 'locked', 'fail' => ['count' => 0, 'until' => $t0 + 600]], $fifth);
    check('受け付けない間は正しい合言葉でも出さない', 'locked', km_test_signout_decide($pinHash, $fifth['fail'], 'Kakari2026', $t0 + 599)['result']);
    check('10 分たてば受け付ける', 'ok', km_test_signout_decide($pinHash, $fifth['fail'], 'Kakari2026', $t0 + 600)['result']);
    check('合言葉の形は英数字 4〜32 字', [true, false, false, false, true], [km_test_signout_pin_valid('1234'), km_test_signout_pin_valid('123'), km_test_signout_pin_valid('ab cd'), km_test_signout_pin_valid('あいうえ'), km_test_signout_pin_valid(str_repeat('a', 32))]);
    $signOutCode = (string) file_get_contents(__DIR__ . '/../sign-out.php');
    check_bool('sign-out.php は Logto から出す前に合言葉を確かめる(ボタンを隠すだけにしない)',
        strpos($signOutCode, 'km_test_signout_decide(') !== false && strpos($signOutCode, 'km_test_signout_decide(') < strpos($signOutCode, '$client->signOut('));
    check_bool('合言葉はハッシュだけを置く', str_contains((string) file_get_contents(__DIR__ . '/../lib/test-mode.php'), 'km_setting_set($pdo, KM_TEST_SIGNOUT_PIN_SETTING, password_hash($pin, PASSWORD_DEFAULT));'));
    $homeCode = (string) file_get_contents(__DIR__ . '/../index.php');
    check_bool('展示の PC の地図の画面には、押せばすぐ出るログアウトを出さない', preg_match('/<\?php if \(\$kmTestMode !== null\): \?>.*?<details class="km-test-signout">.*?name="signout_pin".*?<\?php else: \?>.*?logout-btn/s', $homeCode) === 1);
    // 2026-10-19(同日): 浮かせて開いた欄が「テスト中」の札(#ui-layer の外・1200)の後ろに回り、画面の右からもはみ出した。バーの中で横に開く
    if (preg_match('/^\.km-test-signout > form \{(.*?)^\}/ms', (string) file_get_contents(__DIR__ . '/../Main/styles.css'), $signoutCss) === 1) {
        check_bool('「係の人」の欄は浮かせず、上のバーの中で開く(入りきらなければ折り返す)', !str_contains($signoutCss[1], 'position: absolute') && str_contains($signoutCss[1], 'flex-wrap: wrap;'));
    } else {
        check_bool('「係の人」の欄の CSS がある', false);
    }
    // 2026-10-19(同日、利用者の指示): テスト用アカウントでは「ダウンロード・設定」を開けない。⚙️ も引き出しも書かない
    check_bool('テスト用アカウントでは「ダウンロード・設定」の ⚙️ を出さない', preg_match('/<\?php if \(\$kmTestMode === null\): \?>\s*<button id="info-panel-toggle"/', $homeCode) === 1);
    check_bool('テスト用アカウントでは引き出しごと書かない(APK・アカウント設定への導線をページに残さない)', preg_match('/<\?php if \(\$kmTestMode === null\): \?>\s*<div id="km-drawer-scrim".*?<\/aside>\s*<\?php endif; \?>/s', $homeCode) === 1);
    check_bool('引き出しが無くても(展示の PC)ログインの欄は右上のアカウントの欄に出る', str_contains($homeCode, '<details class="km-account" id="km-account">'));
    if (preg_match('/^\.action-group-left \{(.*?)^\}/ms', (string) file_get_contents(__DIR__ . '/../Main/styles.css'), $leftCss) === 1) {
        check_bool('バーに残ったログインの欄はスマホで折り返す(画面の右へはみ出さない)', str_contains($leftCss[1], 'flex-wrap: wrap;') && str_contains($leftCss[1], 'min-width: 0;'));
    } else {
        check_bool('.action-group-left の CSS がある', false);
    }
    $accountCode = (string) file_get_contents(__DIR__ . '/../account.php');
    check_bool('テスト用アカウントではアカウントの画面を使わせない(表示名・退会・サインアウト)', str_contains($accountCode, "\$blockedReason = 'test';\n            \$signedIn = false;")
        && strpos($accountCode, "\$blockedReason = 'test';") < strpos($accountCode, "if ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {"));

    km_check_heading('posters: ポスター・広告と地点の URL(2026-10-21)');
    require_once __DIR__ . '/../lib/posters.php';
    require_once __DIR__ . '/../lib/admin-log.php';
    require_once __DIR__ . '/../lib/csp.php';
    check_bool('出し方の 3 段階: 表示しない は出さない', !km_poster_mode_shows('off', true) && !km_poster_mode_shows('off', false));
    check_bool('出し方の 3 段階: イベント中のみ はイベント中だけ', km_poster_mode_shows('event', true) && !km_poster_mode_shows('event', false));
    check_bool('出し方の 3 段階: 常に表示 はいつでも', km_poster_mode_shows('always', true) && km_poster_mode_shows('always', false));
    check_bool('知らない出し方は出さない', !km_poster_mode_shows('sometimes', true));
    check('まだ選んでいなければ「表示しない」', 'off', KM_POSTER_MODE_DEFAULT);
    check_bool('リンクの URL は http / https だけ', km_poster_url_valid('https://www.ichinoseki.ac.jp/') && km_poster_url_valid('http://example.com/a?b=1')
        && !km_poster_url_valid('javascript:alert(1)') && !km_poster_url_valid('data:text/html,x') && !km_poster_url_valid('ftp://example.com/')
        && !km_poster_url_valid('//example.com/') && !km_poster_url_valid('/posters.php'));
    check_bool('URL: 利用者名入り・空白・長すぎるものは断る', !km_poster_url_valid('https://user:pass@example.com/')
        && !km_poster_url_valid("https://example.com/a b") && !km_poster_url_valid("https://example.com/\n") && !km_poster_url_valid('https://example.com/' . str_repeat('a', 500)));
    check_bool('取りに行く先: 公開のアドレスは通す', km_poster_ip_allowed('8.8.8.8'));
    $blockedIps = ['10.0.0.1', '172.17.0.1', '192.168.3.29', '127.0.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1', 'localhost'];
    check('取りに行く先: 私的な網・自分・メタデータ・IPv6 は通さない', [], array_values(array_filter($blockedIps, 'km_poster_ip_allowed')));
    $pngHeader = static fn (int $w, int $h): string => "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $w, $h) . "\x08\x02\x00\x00\x00" . pack('N', 0);
    check('画像: 中身が PNG なら png', 'png', km_poster_image_check($pngHeader(600, 800))['ext']);
    $posterImageError = static function (string $bytes): string {
        try {
            km_poster_image_check($bytes);
            return '';
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    };
    check_bool('画像: SVG・PDF・空は断る', $posterImageError('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') !== ''
        && $posterImageError("%PDF-1.7\n") !== '' && $posterImageError('') !== '');
    check_bool('画像: 縦横 ' . KM_POSTER_MAX_DIMENSION . 'px を超えるものは断る(小さなファイルでも展開で端末のメモリが尽きる)', str_contains($posterImageError($pngHeader(5000, 10)), '4096px'));
    check_bool('画像: A4 を 300dpi で取り込んだ大きさは通す', $posterImageError($pngHeader(2480, 3508)) === '');
    $posterValidateError = static function (array $input): string {
        try {
            km_poster_validate($input);
            return '';
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    };
    check_bool('入力: 見出しは要る・URL は形を見る・地点の id にパスを入れさせない', $posterValidateError(['title' => '  ']) !== ''
        && $posterValidateError(['title' => 'a', 'linkUrl' => 'javascript:x']) !== ''
        && $posterValidateError(['title' => 'a', 'nodeId' => '../etc/passwd']) !== ''
        && $posterValidateError(['title' => 'a', 'sortOrder' => '1.5']) !== '');
    $validatedPoster = km_poster_validate(['title' => ' 模擬店 ', 'description' => "a\r\nb", 'linkUrl' => '', 'nodeId' => '', 'sortOrder' => '-2', 'isEnabled' => true]);
    check('入力: 空の URL・地点・企画の番号は null、改行は \n にそろえる', ['模擬店', "a\nb", null, null, null, -2, true], array_values($validatedPoster));
    check('入力: 企画の番号はそろえる・形の違うものは断る', ['A-1', true], [km_poster_validate(['title' => 'a', 'projectNo' => ' a－１ '])['projectNo'], $posterValidateError(['title' => 'a', 'projectNo' => 'A/1']) !== '']);
    check_bool('保存名は自分で採番した形だけ(パスをはみ出させない・SVG を通さない)', km_poster_stored_name_valid('poster_' . str_repeat('a', 32) . '.webp')
        && !km_poster_stored_name_valid('poster_' . str_repeat('a', 32) . '.svg') && !km_poster_stored_name_valid('../poster_' . str_repeat('a', 32) . '.png')
        && !km_poster_stored_name_valid('avatar_' . str_repeat('a', 32) . '.png'));
    if (extension_loaded('pdo_sqlite')) {
        $posterDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        check('表が無ければ「表示しない」・空(公開の経路で表を作らない)', ['off', []], [km_poster_mode($posterDb), km_posters_public($posterDb, true, [])]);
        $posterDb->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($value): int => (int) strtotime((string) $value), 1);
        $posterDb->exec('CREATE TABLE km_settings (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
        $posterDb->exec('CREATE TABLE km_posters (id INTEGER PRIMARY KEY, title TEXT, description TEXT, link_url TEXT, node_id TEXT, image_stored_name TEXT,
            image_source_url TEXT, image_width INT, image_height INT, sort_order INT, is_enabled INT, created_at TEXT, updated_at TEXT)');
        $posterDb->exec("INSERT INTO km_posters VALUES
            (1, '後ろ', NULL, 'https://example.com/', 'n1', 'poster_x.png', NULL, 10, 20, 5, 1, '2026-10-21 10:00:00', '2026-10-21 10:00:00'),
            (2, '前', '説明', NULL, 'hiddenNode', 'poster_y.png', NULL, 10, 20, 1, 1, '2026-10-21 10:00:00', '2026-10-21 10:00:00'),
            (3, '止めた', NULL, NULL, 'n1', 'poster_z.png', NULL, 10, 20, 0, 0, '2026-10-21 10:00:00', '2026-10-21 10:00:00'),
            (4, '消えた地点', NULL, NULL, 'gone', 'poster_w.png', NULL, 10, 20, 9, 1, '2026-10-21 10:00:00', '2026-10-21 10:00:00')");
        $posterNodes = ['n1' => ['name' => '図書館', 'floor' => '1'], 'hiddenNode' => ['name' => '倉庫', 'floor' => '2', 'hidden' => true]];
        check('「表示しない」の間は出さない', [], km_posters_public($posterDb, true, $posterNodes));
        $posterDb->exec("INSERT INTO km_settings VALUES ('poster_display_mode', 'event', '')");
        check('「イベント中のみ」でイベントの外なら出さない', [], km_posters_public($posterDb, false, $posterNodes));
        $publicPosters = km_posters_public($posterDb, true, $posterNodes);
        check('止めたものは出さない・並び順どおり', [2, 1, 4], array_column($publicPosters, 'id'));
        check('イベントで出さない地点・地図に無い地点は指さない(場所を漏らさない)', ['', 'n1', ''], array_column($publicPosters, 'nodeId'));
        check('画像の URL は番号と版だけ', '/api/poster-image.php?id=1&v=' . strtotime('2026-10-21 10:00:00'), $publicPosters[1]['imageUrl'] ?? null);
        check_bool('保存名は公開の地図データに載せない', !array_key_exists('storedName', $publicPosters[0]) && !str_contains((string) json_encode($publicPosters), 'poster_x.png'));
        $posterDb->exec("INSERT INTO km_posters VALUES (5, '広告', NULL, NULL, NULL, 'poster_v.png', NULL, 10, 20, 7, 1, '2026-10-21 10:00:00', '2026-10-21 10:00:00')");
        check('広告は「管理画面で地点に置いていない」ものだけ(隠した地点・消えた地点のポスターは広告にしない)', [2 => false, 1 => false, 5 => true, 4 => false],
            array_column(km_posters_public($posterDb, true, $posterNodes), 'isAd', 'id'));
        $posterDb->exec('CREATE TABLE km_map_node_links (node_id TEXT PRIMARY KEY, url TEXT, label TEXT, updated_at TEXT)');
        $posterDb->exec("INSERT INTO km_map_node_links VALUES ('n1', 'https://example.com/lab', '研究室', ''), ('n2', 'javascript:alert(1)', NULL, '')");
        check('地点の URL: 通らない値は表に入っていても出さない', ['n1'], array_keys(km_node_links_for_map($posterDb)));
    } else {
        check_skip('posters: 公開に出すもの', 'pdo_sqlite が無い');
    }
    $posterLib = (string) file_get_contents(__DIR__ . '/../lib/posters.php');
    check_bool('外から取るとき: リダイレクトを curl に任せない・名前を引いた先を固定する', str_contains($posterLib, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($posterLib, 'CURLOPT_RESOLVE =>')
        && str_contains($posterLib, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS') && preg_match('/foreach \(\$addresses as \$address\) \{\s*if \(!km_poster_ip_allowed\(\$address\)\)/', $posterLib) === 1);
    check_bool('CSP: 公開ページの img-src に外部を足していない(画像は取り込んで自前で配る)', str_contains(km_csp_policy('public'), "img-src 'self' data:;") && str_contains(km_csp_policy('admin'), "img-src 'self' data:;"));
    $posterImageApi = (string) file_get_contents(__DIR__ . '/../api/poster-image.php');
    check_bool('公開の画像の口: いま出しているもので、止めていないものだけ', str_contains($posterImageApi, 'if (km_posters_showing_now($pdo)) {') && str_contains($posterImageApi, 'WHERE id = ? AND is_enabled = 1'));
    check_bool('管理画面の画像・プレビューの口は guard.php の内側', str_starts_with(ltrim(substr((string) file_get_contents(__DIR__ . '/../admin/api/poster-image.php'), 0, 200)), '<?php')
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/api/poster-image.php'), "require dirname(__DIR__) . '/_inc/guard.php';")
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/api/poster-preview.php'), "require dirname(__DIR__) . '/_inc/guard.php';"));
    check_bool('URL のプレビュー: POST と CSRF を見てから取りに行く', preg_match('/!== \'POST\'.*?km_csrf_verify\(\).*?km_poster_fetch_remote/s', (string) file_get_contents(__DIR__ . '/../admin/api/poster-preview.php')) === 1);
    $mapDataLib = (string) file_get_contents(__DIR__ . '/../lib/map-data.php');
    check_bool('地図データ: 錠の内側でだけポスターを載せ、地点に URL を付ける', str_contains($mapDataLib, "'posters' => km_posters_public(\$pdo, \$overlay['events'] !== [], \$nodesById, \$shownProjectNodes, \$projectPois),")
        && strpos($mapDataLib, "'posters' => km_posters_public(") > strpos($mapDataLib, 'if (!$viewUnlocked) {') && str_contains($mapDataLib, "\$node['linkUrl'] = \$nodeLinks[\$id]['url'];"));
    check_bool('公開ページ: ?poster= は数字の形だけ確かめる・🖼️ は出しているポスターがあるときだけ', str_contains($homeCode, "preg_match('/^[1-9]\\d{0,9}\$/', \$posterParam) === 1")
        && str_contains($homeCode, '<?php if ($kmPosterCount > 0): ?>') && str_contains($homeCode, '<meta name="km-focus-poster" content="<?= km_home_e($kmFocusPoster) ?>">'));
    $wallPage = (string) file_get_contents(__DIR__ . '/../posters.php');
    check_bool('一覧のページ: 地図の錠が掛かっていれば地点を出さない・地図へは ?room= で移る', str_contains($wallPage, "km_posters_public(\$pdo, km_poster_event_active(\$pdo), [])")
        && str_contains($wallPage, "'href' => '/?room=' . rawurlencode((string) \$nodeId) . '&poster=' . (int) \$poster['id'],") && str_contains($wallPage, "'href' => '/?poi=' . (int) \$poiId,") && str_contains($wallPage, 'km_map_guest_verify();'));
    // 押したら大きく見る幕 → そこから地図・リンクへ(2026-10-22、利用者の指示「押して飛ばすより、押して拡大してから飛ぶ」)
    $wallJs = (string) file_get_contents(__DIR__ . '/../Main/posters-page.js');
    check_bool('一覧のページ: 画像を押すと大きく見る幕(画像のリンクは数えない・JS が無ければリンクへ)', str_contains($wallPage, 'data-km-poster-open')
        && !preg_match('/data-km-poster-open[^>]*data-km-poster-link/s', $wallPage) && str_contains($wallPage, "km_public_asset('Main/posters-page.js')")
        && str_contains($wallJs, 'event.preventDefault();') && str_contains($wallJs, 'event.ctrlKey || event.metaKey')
        && str_contains($wallJs, "link.cloneNode(true)") && !preg_match('/innerHTML\s*=\s*[^\s\'"]/', $wallJs) && str_contains($wallJs, "if (event.key === 'Escape') close();"));
    $appJsForPosters = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    check_bool('地図: ポスターと地点の URL は escapeHtml を通し、http(s) だけをリンクにする', str_contains($appJsForPosters, '${escapeHtml(poster.title)}')
        && str_contains($appJsForPosters, 'href="${escapeHtml(poster.linkUrl)}" target="_blank" rel="noopener noreferrer"')
        && str_contains($appJsForPosters, "if (node.linkUrl && /^https?:\\/\\//i.test(node.linkUrl)) {"));
    check_bool('監査ログ: ポスターと地点の URL の操作に文言がある', isset(KM_ADMIN_LOG_ACTION_LABELS['poster.mode'], KM_ADMIN_LOG_ACTION_LABELS['poster.create'], KM_ADMIN_LOG_ACTION_LABELS['poster.update'], KM_ADMIN_LOG_ACTION_LABELS['poster.delete'], KM_ADMIN_LOG_ACTION_LABELS['map.node_link']));
    check_bool('管理画面: サイドバーから開ける', str_contains((string) file_get_contents(__DIR__ . '/../admin/_inc/partials/sidebar.php'), '<a href="./posters.php" class="<?= $link(\'posters\') ?>">'));

    km_check_heading('node-extras: アプリの部屋の写真(ポスター)といいね(2026-10-22)');
    require_once __DIR__ . '/../lib/node-extras.php';
    check('地点の uuid の形', [true, true, false, false], [km_node_uuid_valid('9d1cebb3-9aa6-4fca-8f08-9ca965237b9e'), km_node_uuid_valid('n_new_10'), km_node_uuid_valid(''), km_node_uuid_valid("a'b")]);
    check('端末の印は 32 桁の 16 進だけ', [true, false, false], [km_node_like_device_valid(str_repeat('a1', 16)), km_node_like_device_valid(str_repeat('A1', 16)), km_node_like_device_valid('abc')]);
    if (extension_loaded('pdo_sqlite')) {
        $extrasDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $extrasDb->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($value): int => (int) strtotime((string) $value), 1);
        $extrasDb->exec('CREATE TABLE km_settings (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
        $extrasDb->exec('CREATE TABLE km_posters (id INTEGER PRIMARY KEY, title TEXT, description TEXT, link_url TEXT, node_id TEXT, stored_name TEXT, source_url TEXT, image_width INT, image_height INT, sort_order INT, is_enabled INT, created_at TEXT, updated_at TEXT)');
        $extrasDb->exec("INSERT INTO km_posters VALUES (1, '展示', '説明', 'https://example.com/', 'room1', 'p.png', NULL, 10, 20, 0, 1, '', '2026-10-22 10:00:00'),
            (2, '止めた', NULL, NULL, 'room1', 'q.png', NULL, 10, 20, 1, 0, '', '2026-10-22 10:00:00'),
            (3, '教員室', NULL, NULL, 'staff1', 'r.png', NULL, 10, 20, 0, 1, '', '2026-10-22 10:00:00'),
            (4, '広告', NULL, NULL, NULL, 's.png', NULL, 10, 20, 0, 1, '', '2026-10-22 10:00:00')");
        $extrasDb->exec('CREATE TABLE km_map_nodes (id TEXT PRIMARY KEY, uuid TEXT, name TEXT, type2 TEXT)');
        $extrasDb->exec("INSERT INTO km_map_nodes VALUES ('room1', 'uuid-room-1', '機械実習工場', '実習室'), ('staff1', 'uuid-staff-1', '管-115', '教員室')");
        $room = km_node_extras_find_node($extrasDb, 'uuid-room-1');
        check('写真: 地点は uuid で引く・無ければ null', ['room1', null], [$room['id'] ?? null, km_node_extras_find_node($extrasDb, 'nope')]);
        check('写真: 出し方が「表示しない」の間は出さない', [], km_node_extras_posters($extrasDb, $room, true));
        $extrasDb->exec("INSERT INTO km_settings VALUES ('poster_display_mode', 'always', '')");
        $roomPosters = km_node_extras_posters($extrasDb, $room, false);
        check('写真: その地点の出しているものだけ(広告・止めたものは出さない)・nodeId と isAd は返さない', [[1], false, '/api/poster-image.php?id=1&v='],
            [array_column($roomPosters, 'id'), isset($roomPosters[0]['nodeId']) || isset($roomPosters[0]['isAd']), substr((string) ($roomPosters[0]['imageUrl'] ?? ''), 0, 29)]);
        check('写真: 教員室・研究室の地点の写真は出さない(Web の一般向けと同じ)・Web の地図に無い地点も出さない', [[], []],
            [km_node_extras_posters($extrasDb, km_node_extras_find_node($extrasDb, 'uuid-staff-1'), true), km_node_extras_posters($extrasDb, null, true)]);
        // 企画の番号で置いたポスター(2026-10-23。それまでアプリには出なかった)
        $extrasDb->exec("INSERT INTO km_posters VALUES (5, '企画', NULL, NULL, NULL, 't.png', NULL, 10, 20, 2, 1, '', '2026-10-22 10:00:00'), (6, 'ほかの企画', NULL, NULL, NULL, 'u.png', NULL, 10, 20, 3, 1, '', '2026-10-22 10:00:00')");
        $extrasDb->exec('CREATE TABLE km_poster_projects (poster_id INTEGER PRIMARY KEY, project_no TEXT)');
        $extrasDb->exec("INSERT INTO km_poster_projects VALUES (5, '22'), (6, '99')");
        $extrasDb->exec('CREATE TABLE km_map_node_projects (node_uuid TEXT, project_no TEXT, sort_order INT, PRIMARY KEY (node_uuid, project_no))');
        $extrasDb->exec("INSERT INTO km_map_node_projects VALUES ('uuid-room-1', '22', 0)");
        $projectPosters = km_node_extras_posters($extrasDb, $room, false);
        check('写真: 企画の番号で置いたポスターも出す(ほかの番号のものは出さない)・nodeIds と poiIds は返さない', [[1, 5], false],
            [array_column($projectPosters, 'id'), isset($projectPosters[1]['nodeIds']) || isset($projectPosters[1]['poiIds'])]);

        $extrasDb->exec('CREATE TABLE km_node_likes (node_uuid TEXT NOT NULL, voter_hash TEXT NOT NULL, source_hash TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (node_uuid, voter_hash))');
        $voterA = km_app_keyed_hash(str_repeat('k', 32), 'node-like-voter', str_repeat('a', 32));
        $voterB = km_app_keyed_hash(str_repeat('k', 32), 'node-like-voter', str_repeat('b', 32));
        $sourceX = km_app_keyed_hash(str_repeat('k', 32), 'node-like-source', '203.0.113.1');
        $likeNow = strtotime('2026-10-22 12:00:00');
        check('いいね: 端末の印は元の値を含まない', false, str_contains($voterA, str_repeat('a', 32)));
        check('いいね: 付ける → 1・自分は付けている', ['ok', ['count' => 1, 'liked' => true]],
            [km_node_like_set($extrasDb, 'uuid-room-1', $voterA, $sourceX, true, $likeNow), km_node_like_state($extrasDb, 'uuid-room-1', $voterA)]);
        check('いいね: 同じ端末が 2 回付けても 1・ほかの端末からは「付けていない」', [['count' => 1, 'liked' => false]],
            [km_node_like_set($extrasDb, 'uuid-room-1', $voterA, $sourceX, true, $likeNow) === 'ok' ? km_node_like_state($extrasDb, 'uuid-room-1', $voterB) : null]);
        km_node_like_set($extrasDb, 'uuid-room-1', $voterB, $sourceX, true, $likeNow);
        km_node_like_set($extrasDb, 'uuid-room-1', $voterA, $sourceX, false, $likeNow);
        check('いいね: 外すと消える(ほかの端末の分は残る)・端末の印が無ければ liked は false', ['count' => 1, 'liked' => false], km_node_like_state($extrasDb, 'uuid-room-1', null));
        for ($i = 0; $i < KM_NODE_LIKE_SOURCE_LIMIT; $i++) {
            km_node_like_set($extrasDb, 'flood-' . $i, $voterA, $sourceX, true, $likeNow);
        }
        check('いいね: 同じ送り元から 1 時間に ' . KM_NODE_LIKE_SOURCE_LIMIT . ' を超えたら断る・1 時間たてば付けられる', ['limited', 'ok'],
            [km_node_like_set($extrasDb, 'uuid-room-1', $voterA, $sourceX, true, $likeNow + 60), km_node_like_set($extrasDb, 'uuid-room-1', $voterA, $sourceX, true, $likeNow + 3601)]);
        check('いいね: 管理画面の 0 に戻す', [2, ['count' => 0, 'liked' => false]],
            [km_node_likes_reset($extrasDb, 'uuid-room-1'), km_node_like_state($extrasDb, 'uuid-room-1', $voterA)]);
    } else {
        check_bool('node-extras の DB の試し(pdo_sqlite が無いので飛ばした)', true);
    }
    $extrasApi = (string) file_get_contents(__DIR__ . '/../api/node-extras.php');
    check_bool('口: POST だけ・本文は 4KB まで・アクセスコードを確かめてから読む・付けるには端末の印が要る', str_contains($extrasApi, "require_method('POST');")
        && str_contains($extrasApi, 'km_api_json_body(4 * 1024)') && strpos($extrasApi, 'km_learning_code_allowed(') < strpos($extrasApi, 'km_node_extras_find_node(')
        && str_contains($extrasApi, "if (\$like !== null && \$device === '') {") && str_contains($extrasApi, "=== 'limited'"));
    check_bool('いいね: IP・端末の印そのものは持たない(鍵つきの印だけ)', str_contains((string) file_get_contents(__DIR__ . '/../lib/node-extras.php'), "km_app_keyed_hash(km_app_secret(\$pdo, KM_NODE_LIKE_KEY_NAME), 'node-like-voter', \$device)")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/node-extras.php'), "'node-like-source', km_map_rate_limit_key(\$ip)"));
    $privacyForLikes = (string) json_encode(km_legal_privacy(), JSON_UNESCAPED_UNICODE);
    check_bool('利用規約・プライバシーポリシー: いいね(誰が付けたかは記録しない・外すと消える)を書いた', str_contains((string) json_encode(km_legal_terms(), JSON_UNESCAPED_UNICODE), 'いいねは誰が付けたかを記録せず、数だけを表示します')
        && str_contains($privacyForLikes, 'アプリの「いいね」(場所・端末ごとの印・送り元の印・日時') && str_contains($privacyForLikes, 'もう一度押して外した時点で削除されます'));
    check_bool('管理画面: ポスターの画面で地点ごとのいいねを見て 0 に戻せる・記録に文言', str_contains((string) file_get_contents(__DIR__ . '/../admin/posters.php'), "if (\$formAction === 'likes_reset') {")
        && isset(KM_ADMIN_LOG_ACTION_LABELS['node.likes_reset']));

    /*
     * バックアップ(2026-10-23、利用者の指示「サーバーでもバックアップデータを見れるように」)。
     * 地図のバックアップを管理画面で見る・落とす・消す。Wi-Fi の学習データは**消す前に控えを取り、取れなければ消さない**。控えから戻せる
     */
    km_check_heading('backups: 地図のバックアップと Wi-Fi 学習の控え(2026-10-23)');
    require_once __DIR__ . '/../lib/map-backup.php';
    require_once __DIR__ . '/../lib/learning-backup.php';
    check('地図の内訳: 階ごとの地点・線・イベント・氏名の入った地点・学習地点', ['floors' => ['1F' => 2, 'OUTSIDE' => 1], 'nodes' => 3, 'lines' => 1, 'events' => 1, 'occupants' => 1, 'fingerprints' => 0],
        km_map_backup_summary('{"format":"kosenmap-map","formatVersion":2,"map":{"version":10,"nodes":[{"uuid":"a","floor":"1F","occupantName":"架空"},{"uuid":"b","floor":"1F"},{"uuid":"c","floor":"OUTSIDE"}],"lines":[{"startNodeUuid":"a","endNodeUuid":"b"}],"events":[{"uuid":"e"}]}}'));
    check('地図の内訳: 地図でなければ null', null, km_map_backup_summary('{"x":1}'));
    check('学習の内訳: 階ごとの記録・地点の数・測った期間', ['floors' => ['1F' => 2, '2F' => 1], 'nodes' => 2, 'from' => 1760000000000, 'to' => 1760000500000],
        km_learning_backup_summary([['nodeUuid' => 'a', 'floor' => '2F', 'measuredAtMillis' => 1760000500000], ['nodeUuid' => 'a', 'floor' => '1F', 'measuredAtMillis' => 1760000000000], ['nodeUuid' => 'b', 'floor' => '1F']]));
    // 2026-10-23: uploads/map-backups(0750・グループ www-data)を kmops が開けず、配備前の控えが落ちた
    check_bool('uploads の下に作るディレクトリは 0755・host-setup は開けないディレクトリも見つけて直す', str_contains((string) file_get_contents(__DIR__ . '/../lib/map-backup.php'), '!@mkdir($dir, 0755, true)')
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/learning-backup.php'), '!@mkdir($dir, 0755, true)')
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/panorama.php'), '!@mkdir($dir, 0755, true)')
        // host-setup.sh はリポジトリの scripts/ にある(ホストへ配った src だけのときは見ない)
        && (($hostSetupRoot = km_check_repo_root()) === null || str_contains((string) file_get_contents($hostSetupRoot . '/scripts/host-setup.sh'), 'unopenable="$(find "$SRC/uploads" -type d \( ! -readable -o ! -executable \) 2>/dev/null | wc -l)"')));
    $learningLib = (string) file_get_contents(__DIR__ . '/../lib/learning-data.php');
    check_bool('学習データを消す前に控えを書く(書けなければ例外で何も消さない)', strpos($learningLib, 'km_learning_backup_save($pdo, $kind, $scope, $floor,') !== false
        && strpos($learningLib, 'km_learning_backup_save($pdo, $kind, $scope, $floor,') < strpos($learningLib, 'UPDATE {$table} SET deleted = 1'));
    check_bool('控え: 保存名は表からだけ・壊れていれば読まない(SHA-256)', str_contains((string) file_get_contents(__DIR__ . '/../lib/learning-backup.php'), "preg_match('/\\A[0-9a-f]{32}\\.json\\.gz\\z/', \$name)")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/learning-backup.php'), "hash_equals((string) \$row['sha256'], hash('sha256', \$json))"));
    $backupsPage = (string) file_get_contents(__DIR__ . '/../admin/backups.php');
    check_bool('管理画面: バックアップを見る・落とす・消す・控えを戻す(消す・戻すは書き込みの権限と CSRF)', str_contains($backupsPage, "require __DIR__ . '/_inc/guard.php';")
        && str_contains($backupsPage, "if (\$formAction === 'map_delete') {") && str_contains($backupsPage, "if (\$formAction === 'learning_restore') {")
        && str_contains($backupsPage, '!km_csrf_verify()') && substr_count($backupsPage, '<?php if ($KM_ADMIN_CAN_WRITE): ?>') === 2
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/_inc/partials/sidebar.php'), '<a href="./backups.php" class="<?= $link(\'backups\') ?>">'));
    check_bool('監査ログ: 落とす・戻すに文言', isset(KM_ADMIN_LOG_ACTION_LABELS['map.backup_download'], KM_ADMIN_LOG_ACTION_LABELS['learning.backup_download'], KM_ADMIN_LOG_ACTION_LABELS['learning.backup_restore']));

    km_check_heading('map-node-stairs: 階段の上り口・下り口の向き(2026-10-22)');
    require_once __DIR__ . '/../lib/app-map-convert.php';
    check('向きは 0〜359 の整数・数でなければ null', [0, 90, 315, 0, null, null], [km_map_heading(0), km_map_heading('90'), km_map_heading(-45), km_map_heading(360), km_map_heading(null), km_map_heading('右')]);
    $stairMap = json_decode('{"version":10,"nodes":[
        {"uuid":"11111111-1111-1111-1111-111111111111","x":10,"y":20,"useWifi":true,"title":"A階段","subtitle":"","type1":"stairs","type2":"","note":"","floor":"1F","transferGroupId":"A","stairUpHeading":90,"stairDownHeading":270.4},
        {"uuid":"22222222-2222-2222-2222-222222222222","x":30,"y":40,"useWifi":true,"title":"講義室","subtitle":"","type1":"room","type2":"","note":"","floor":"1F","stairUpHeading":45}
    ],"lines":[]}');
    $stairWeb = km_app_map_to_web($stairMap);
    check('アプリ → Web: 階段の向きを持ち回る(列の並びには無いので km_map_nodes には書かない)', [90, 270, false],
        [$stairWeb['nodes'][0]['stair_up_heading'], $stairWeb['nodes'][0]['stair_down_heading'], in_array('stair_up_heading', KM_APP_MAP_COMPARED_COLUMNS, true)]);
    $stairRows = array_map(static fn (array $n): array => $n + ['id' => $n['id']], $stairWeb['nodes']);
    $stairBack = km_app_map_snapshot_from_rows($stairRows, [])['map'];
    check('Web → アプリ: 階段の向きが戻る・階段でない地点には載せない・無ければ項目ごと無い', [90, 270, false, false],
        [$stairBack->nodes[0]->stairUpHeading ?? null, $stairBack->nodes[0]->stairDownHeading ?? null, property_exists($stairBack->nodes[1], 'stairUpHeading'),
         property_exists(km_app_map_snapshot_from_rows([['stair_up_heading' => null] + $stairRows[0]], [])['map']->nodes[0], 'stairUpHeading')]);
    if (extension_loaded('pdo_sqlite')) {
        $stairDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        check('表が無ければ空(配信を止めない)', [], km_map_node_stairs_all($stairDb));
        $stairDb->exec('CREATE TABLE km_map_node_stairs (node_uuid TEXT PRIMARY KEY, up_heading INTEGER NULL, down_heading INTEGER NULL, updated_at TEXT NOT NULL)');
        $stairDb->exec("INSERT INTO km_map_node_stairs VALUES ('22222222-2222-2222-2222-222222222222', 10, NULL, '')");
        check('取り込み: 階段の向きを入れ、階段でない地点の向きは消す', [1, ['11111111-1111-1111-1111-111111111111' => ['up' => 90, 'down' => 270]]],
            [km_map_node_stairs_sync($stairDb, $stairWeb['nodes']), km_map_node_stairs_all($stairDb)]);
        $cleared = $stairWeb['nodes'];
        $cleared[0]['stair_up_heading'] = null;
        $cleared[0]['stair_down_heading'] = null;
        check('取り込み: 向きを外した階段は消える', [0, []], [km_map_node_stairs_sync($stairDb, $cleared), km_map_node_stairs_all($stairDb)]);
    }
    $syncCode = (string) file_get_contents(__DIR__ . '/../lib/app-map-sync.php');
    check_bool('取り込み: 表は取引の前に作り、作れなくても止めない・ノードを入れたあとに向きを入れる', strpos($syncCode, 'km_map_node_stairs_ensure_table($pdo);') < strpos($syncCode, '$pdo->beginTransaction();')
        && strpos($syncCode, 'km_map_node_stairs_sync($pdo, $converted[\'nodes\']);') > strpos($syncCode, '$insertNode->execute($values);'));

    /*
     * 区画の色分けを手で決める(2026-10-23。lib/map-node-area.php)。地点の指定と区画の印は別の表で持ち、配信でアプリへ戻す。
     * 区画の印は km_map_nodes に入れない(Web の地図・一覧に名前の無い地点を出さない)
     */
    /*
     * 地図の履歴とブックマーク(2026-10-23。lib/user-map-data.php・api/user-map-data.php・Main/user-history.js・アプリの UserMapData.kt)。
     * 混ぜ方: 項目ごとに新しい方・消した印と全部消した時刻は新しい方(古いものが生き返らない)・上限で切る。どちらの順に混ぜても同じ
     */
    km_check_heading('user-map-data: 地図の履歴とブックマークの同期(2026-10-23)');
    require_once __DIR__ . '/../lib/user-map-data.php';
    $t0 = 1_760_000_000_000;
    $deviceA = ['bookmarks' => [['uuid' => 'room1', 'at' => $t0 + 1]], 'places' => [['uuid' => 'room1', 'at' => $t0 + 5], ['uuid' => 'room2', 'at' => $t0 + 2]],
        'routes' => [['from' => 'a', 'to' => 'b', 'at' => $t0 + 3]], 'searches' => [['q' => ' 図書館 ', 'at' => $t0 + 4]],
        'settings' => ['android' => ['values' => ['map_color_areas' => true], 'at' => $t0 + 1]]];
    $deviceB = ['bookmarks' => [['uuid' => 'room3', 'at' => $t0 + 2]], 'places' => [['uuid' => 'room1', 'at' => $t0 + 9]],
        'removed' => ['bookmarks' => ['room1' => $t0 + 6]], 'cleared' => ['searches' => $t0 + 10],
        'settings' => ['android' => ['values' => ['map_color_areas' => false], 'at' => $t0 + 8]]];
    $mergedAB = km_user_map_merge(km_user_map_normalize($deviceA), km_user_map_normalize($deviceB));
    $mergedBA = km_user_map_merge(km_user_map_normalize($deviceB), km_user_map_normalize($deviceA));
    check('混ぜる: ブックマークは消した印より古いものを落とす・開いた場所は新しい方を残して新しい順・全部消した検索は残らない・設定は新しい方',
        [['room3'], [['room1', $t0 + 9], ['room2', $t0 + 2]], [], ['a>b'], false],
        [array_column($mergedAB['bookmarks'], 'uuid'), array_map(static fn ($p) => [$p['uuid'], $p['at']], $mergedAB['places']), $mergedAB['searches'],
         array_map(static fn ($r) => $r['from'] . '>' . $r['to'], $mergedAB['routes']), (array) $mergedAB['settings']['android']['values'] === ['map_color_areas' => false] ? false : true]);
    check_bool('混ぜる: どちらの順に混ぜても同じ', json_encode($mergedAB) === json_encode($mergedBA));
    check_bool('混ぜる: 消したあとに付け直したブックマークは残る', array_column(km_user_map_merge($mergedAB, km_user_map_normalize(['bookmarks' => [['uuid' => 'room1', 'at' => $t0 + 20]]]))['bookmarks'], 'uuid') === ['room1', 'room3']);
    $flood = km_user_map_normalize(['places' => array_map(static fn ($i) => ['uuid' => 'p' . $i, 'at' => $t0 + $i], range(1, 80)),
        'routes' => [['from' => 'x', 'to' => 'x', 'at' => $t0], ['from' => "a'b", 'to' => 'c', 'at' => $t0]], 'searches' => [['q' => "a\nb", 'at' => $t0], ['q' => 'ok', 'at' => 5]]]);
    check('形: 開いた場所は 50 件まで(新しい方)・同じ地点の経路・おかしな地点・制御文字・おかしな時刻は捨てる', [50, 'p80', [], []],
        [count($flood['places']), $flood['places'][0]['uuid'], $flood['routes'], $flood['searches']]);
    $userMapApi = (string) file_get_contents(__DIR__ . '/../api/user-map-data.php');
    check_bool('口: 誰のものかはトークンかサインインのセッションの sub だけ・Web の書き込みは CSRF・サインインしていなければ 401', str_contains($userMapApi, '$principal = logto_require_principal();')
        && str_contains($userMapApi, "\$subject = trim((string) (\$client->getIdTokenClaims()->sub ?? ''));") && str_contains($userMapApi, 'if (!km_csrf_verify()) {')
        && !str_contains($userMapApi, "\$_GET['user") && !str_contains($userMapApi, "\$input['user") && str_contains($userMapApi, "respond(['success' => false, 'message' => 'サインインしていません"));
    check_bool('退会で消える', in_array('km_user_map_data', KM_ACCOUNT_DELETE_TABLES, true));
    $termsForHistory = (string) json_encode(km_legal_terms(), JSON_UNESCAPED_UNICODE);
    $privacyForHistory = (string) json_encode(km_legal_privacy(), JSON_UNESCAPED_UNICODE);
    check_bool('利用規約・プライバシーポリシー: 履歴とブックマーク(端末の中だけ・サインインで同期・経路は確かめる・消し方・退会)', str_contains($termsForHistory, 'ふだんは端末の中だけに残ります')
        && str_contains($privacyForHistory, '地図の履歴とブックマーク(ブックマークした場所・開いた場所・検索した言葉・経路の出発地と目的地')
        && str_contains($privacyForHistory, '地図の「履歴」から一覧ごと・全部を消せます') && str_contains($privacyForHistory, 'アプリの設定・地図の履歴とブックマーク・') && KM_LEGAL_UPDATED >= '2026-10-23');
    check_bool('利用規約・プライバシーポリシー: 教員室・研究室の決まりは Android アプリにも効く(2026-10-23 から)', str_contains($termsForHistory, '本サービスの Web の地図と Android アプリでは、**教員室・研究室の場所は')
        && !str_contains($privacyForHistory, 'Android アプリへの配信では'));
    $historyJs = (string) file_get_contents(__DIR__ . '/../Main/user-history.js');
    $homeForHistory = (string) file_get_contents(__DIR__ . '/../index.php');
    $appJsForHistory = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    check_bool('Web: 展示の PC では使わない・app.js より先に読む・サインインしている人だけ印と CSRF を渡す', str_contains($historyJs, "if (meta('km-test-mode') === '1') return;")
        && strpos($homeForHistory, "km_public_asset('Main/user-history.js')") < strpos($homeForHistory, "km_public_asset('Main/app.js')) ?>\"></script>")
        && str_contains($homeForHistory, '$kmUserMapKey = ($loggedIn && $kmTestMode === null'));
    check_bool('Web: 経路の確かめ 3 つ(サインイン後に置くか・前回の経路を出すか・消すとき)とログアウトのとき', str_contains($historyJs, "'この端末だけ'") && str_contains($historyJs, "'前回の経路'")
        && str_contains($historyJs, "'経路は残す'") && str_contains($historyJs, "'この端末から消す'"));
    check_bool('Web: 詳細を開いた・経路を引いたを知らせる・一覧から開ける', str_contains($appJsForHistory, "new CustomEvent('km:node-opened'") && str_contains($appJsForHistory, "new CustomEvent('km:route'")
        && str_contains($appJsForHistory, 'window.kmOpenNode = function (id) {') && str_contains($appJsForHistory, 'data-km-bookmark="${escapeHtml(id)}"'));

    km_check_heading('display-defaults: アプリの表示調整の既定を配る(2026-10-23)');
    require_once __DIR__ . '/../lib/display-defaults.php';
    check('形だけ確かめて並べる(真偽・整数・短い文字列・文字列の並び)', ['map_color_areas' => true, 'map_label_size_percent' => 140, 'map_visible_node_types' => ['road', 'room']],
        km_display_defaults_normalize(['map_visible_node_types' => ['road', 'room'], 'map_label_size_percent' => 140, 'map_color_areas' => true]));
    $displayRejects = 0;
    foreach ([['Bad-Key' => 1], ['k' => 1.5], ['k' => str_repeat('a', 65)], ['k' => ["a\nb"]], ['k' => ['x' => 'y']], ['k' => 2_000_000], array_fill_keys(array_map(static fn ($i) => 'k' . $i, range(1, 201)), true)] as $bad) {
        try {
            km_display_defaults_normalize($bad);
        } catch (InvalidArgumentException) {
            $displayRejects++;
        }
    }
    check('名前の形・小数・長い文字列・制御文字・連想配列・大きすぎる数・201 項目は断る', 7, $displayRejects);
    $displayApi = (string) file_get_contents(__DIR__ . '/../api/display-defaults.php');
    check_bool('口: 読むのは誰でも・配る/やめるは管理者のトークン・本文 32KB・記録に文言', str_contains($displayApi, "logto_assert_permissions(\$principal, LOGTO_ADMIN_PERMISSIONS);")
        && strpos($displayApi, "=== 'GET'") < strpos($displayApi, 'logto_require_principal()') && str_contains($displayApi, 'km_api_json_body(32 * 1024)')
        && isset(KM_ADMIN_LOG_ACTION_LABELS['display.defaults_publish'], KM_ADMIN_LOG_ACTION_LABELS['display.defaults_unpublish']));

    km_check_heading('map-node-area: 区画の色分けの手の指定と区画の印(2026-10-23)');
    check('種類は決まった語だけ・色は #RRGGBB(アプリの ARGB の数も読む)', ['room', null, '#CDE7F7', '#56B4E9', null, null],
        [km_map_area_kind('room'), km_map_area_kind('kitchen'), km_map_area_color(0xFFCDE7F7), km_map_area_color('#56b4e9'), km_map_area_color('blue'), km_map_area_color(-1)]);
    check('#RRGGBB → 不透明の ARGB', [0xFFCDE7F7, null], [km_map_area_color_argb('#CDE7F7'), km_map_area_color_argb(null)]);
    $areaMap = json_decode('{"version":10,"nodes":[
        {"uuid":"r1","x":10,"y":20,"useWifi":true,"title":"講義室","subtitle":"","type1":"room","type2":"","note":"","floor":"1F","areaKind":"corridor","areaColor":4291684343},
        {"uuid":"r2","x":30,"y":40,"useWifi":true,"title":"倉庫","subtitle":"","type1":"room","type2":"","note":"","floor":"1F"},
        {"uuid":"m1","x":55.5,"y":66.25,"useWifi":false,"title":"","subtitle":"","type1":"area","type2":"","note":"","floor":"2F","areaKind":"room"}
    ],"lines":[]}');
    $areaWeb = km_app_map_to_web($areaMap);
    check('アプリ → Web: 区画の印は km_map_nodes に入れない(知らない種類にも数えない)・指定を取り出す', [['r1', 'r2'], [], ['r1' => ['kind' => 'corridor', 'color' => '#CDE7F7']], 1],
        [array_column($areaWeb['nodes'], 'uuid'), $areaWeb['skipped']['unknownType'], $areaWeb['area']['overrides'], count($areaWeb['area']['markers'])]);
    $areaBack = km_app_map_snapshot_from_rows(array_map(static fn (array $n): array => $n, $areaWeb['nodes']), [])['map'];
    km_map_area_apply($areaBack, $areaWeb['area']);
    check('Web → アプリ: 指定と区画の印が戻る・指定の無い地点には項目を足さない', ['corridor', 0xFFCDE7F7, false, 'area', '2F', 55.5, 66.25, 'room'],
        [$areaBack->nodes[0]->areaKind ?? null, $areaBack->nodes[0]->areaColor ?? null, property_exists($areaBack->nodes[1], 'areaKind'),
         $areaBack->nodes[2]->type1 ?? null, $areaBack->nodes[2]->floor ?? null, $areaBack->nodes[2]->x ?? null, $areaBack->nodes[2]->y ?? null, $areaBack->nodes[2]->areaKind ?? null]);
    if (extension_loaded('pdo_sqlite')) {
        $areaDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        check('表が無ければ空(配信を止めない)', ['overrides' => [], 'markers' => []], km_map_node_area_all($areaDb));
        $areaDb->exec('CREATE TABLE km_map_node_area (node_uuid TEXT PRIMARY KEY, area_kind TEXT NULL, area_color TEXT NULL, marker_floor TEXT NULL, marker_x REAL NULL, marker_y REAL NULL, updated_at TEXT NOT NULL)');
        $areaDb->exec("INSERT INTO km_map_node_area VALUES ('old', 'room', NULL, NULL, NULL, NULL, '')");
        check('取り込み: 表を丸ごと入れ替える(前の指定は残らない)・読み戻すと同じ', [2, $areaWeb['area']],
            [km_map_node_area_sync($areaDb, $areaWeb['area']), km_map_node_area_all($areaDb)]);
    }
    check_bool('取り込み: 区画の表は取引の前に作り、ノードを入れたあとに入れ替える・配信で足す・Web の地図に区画の印を出さない', strpos($syncCode, 'km_map_node_area_ensure_table($pdo);') < strpos($syncCode, '$pdo->beginTransaction();')
        && strpos($syncCode, "km_map_node_area_sync(\$pdo, \$converted['area']);") > strpos($syncCode, '$insertNode->execute($values);')
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/app-map-convert.php'), "km_map_area_apply(\$result['map'], km_map_node_area_all(\$pdo));")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/map-data.php'), "if ((string) (\$node['type'] ?? '') === 'area') {"));

    /*
     * 企画の番号・スケジュール・イベント中に出す地点(2026-10-23、利用者の指示)。番号はアプリで振り、Website は番号から
     * ポスターの置き場・スケジュールの場所を決める。1 つの地点に番号がいくつも。イベント中は「イベントの地点」だけを出す
     */
    km_check_heading('map-node-projects・event-schedule: 企画の番号とスケジュール(2026-10-23)');
    require_once __DIR__ . '/../lib/map-node-projects.php';
    require_once __DIR__ . '/../lib/event-schedule.php';
    check('番号はそろえる(全角→半角・大文字・ダッシュ)・形の違うものは null', ['A-1', 'B2', 'C-3', null, null, null],
        [km_map_project_number(' a-1 '), km_map_project_number('Ｂ２'), km_map_project_number('c−3'), km_map_project_number('A/1'), km_map_project_number(str_repeat('A', 17)), km_map_project_number('')]);
    check('一覧は重複を 1 つ・入れた順・10 個まで', [['A-1', 'B-2'], 10], [km_map_project_numbers(['a-1', 'B-2', 'A-1', 'x/y']), count(km_map_project_numbers(array_map(static fn ($n) => 'P' . $n, range(1, 15))))]);
    $projectMap = json_decode('{"version":10,"nodes":[
        {"uuid":"9d1cebb3-9aa6-4fca-8f08-9ca965237b9e","x":10,"y":20,"useWifi":true,"title":"講義室","subtitle":"","type1":"room","type2":"","note":"","floor":"1F","projectNumbers":["a-1","A-2"]},
        {"uuid":"r2","x":30,"y":40,"useWifi":true,"title":"倉庫","subtitle":"","type1":"room","type2":"","note":"","floor":"1F"}
    ],"lines":[]}');
    $projectWeb = km_app_map_to_web($projectMap);
    check('アプリ → Web: 地点ごとの番号を取り出す(番号の無い地点は入れない)', ['9d1cebb3-9aa6-4fca-8f08-9ca965237b9e' => ['A-1', 'A-2']], $projectWeb['projects']);
    $projectBack = km_app_map_snapshot_from_rows(array_map(static fn (array $n): array => $n, $projectWeb['nodes']), [])['map'];
    km_map_projects_apply($projectBack, $projectWeb['projects']);
    check('Web → アプリ: 番号が戻る・番号の無い地点には項目を足さない', [['A-1', 'A-2'], false], [$projectBack->nodes[0]->projectNumbers ?? null, property_exists($projectBack->nodes[1], 'projectNumbers')]);
    check('番号 → 地点: 出している地点だけ・番号の順', ['A-1' => ['n1', 'n2'], 'B-1' => ['n2']],
        km_map_project_nodes(['n1' => ['A-1'], 'n2' => ['B-1', 'A-1'], 'n3' => ['A-1']], ['n1' => true, 'n2' => true]));
    if (extension_loaded('pdo_sqlite')) {
        $projectDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        check('表が無ければ空(配信と公開の地図を止めない)', [[], []], [km_map_node_projects_all($projectDb), km_event_schedule_public($projectDb, [])]);
        $projectDb->exec('CREATE TABLE km_map_node_projects (node_uuid TEXT, project_no TEXT, sort_order INT, PRIMARY KEY (node_uuid, project_no))');
        $projectDb->exec("INSERT INTO km_map_node_projects VALUES ('old', 'Z-9', 0)");
        check('取り込み: 表を丸ごと入れ替える・並びを保つ', [2, $projectWeb['projects']], [km_map_node_projects_sync($projectDb, $projectWeb['projects']), km_map_node_projects_all($projectDb)]);
    }
    $syncForProjects = (string) file_get_contents(__DIR__ . '/../lib/app-map-sync.php');
    check_bool('取り込み: 番号の表は取引の前に作り、ノードを入れたあとに入れ替える・配信で足す', strpos($syncForProjects, 'km_map_node_projects_ensure_table($pdo);') < strpos($syncForProjects, '$pdo->beginTransaction();')
        && strpos($syncForProjects, "km_map_node_projects_sync(\$pdo, \$converted['projects']);") > strpos($syncForProjects, '$insertNode->execute($values);')
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/app-map-convert.php'), "km_map_projects_apply(\$result['map'], km_map_node_projects_all(\$pdo));"));
    $scheduleError = static function (array $input): string {
        try {
            km_event_schedule_validate($input);
            return '';
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    };
    check('スケジュールの入力: 番号と日時をそろえる・空は null', ['A-1', '展示', null, null, '2026-11-03 10:00:00', '2026-11-03 11:30:00', true],
        array_values(km_event_schedule_validate(['projectNo' => 'a-1', 'title' => ' 展示 ', 'startsAt' => '2026-11-03T10:00', 'endsAt' => '2026-11-03 11:30', 'isPublic' => true])));
    check_bool('スケジュールの入力: 名前・始まりは要る・終わりは始まりより後・番号と日付の形を見る', $scheduleError(['title' => '', 'startsAt' => '2026-11-03 10:00']) !== ''
        && $scheduleError(['title' => 'a']) !== '' && $scheduleError(['title' => 'a', 'startsAt' => '2026-11-03 10:00', 'endsAt' => '2026-11-03 09:00']) !== ''
        && $scheduleError(['title' => 'a', 'startsAt' => '2026-02-30 10:00']) !== '' && $scheduleError(['title' => 'a', 'projectNo' => 'A/1', 'startsAt' => '2026-11-03 10:00']) !== '');
    check('公開: 一般に出す行だけ・場所は番号の地点(出している地点だけ)・印は外す', [[1, ['n1', 'n2']], [3, []]],
        array_map(static fn (array $r): array => [$r['id'], $r['nodeIds']], km_event_schedule_public_rows([
            ['id' => 1, 'projectNo' => 'A-1', 'title' => 'a', 'organizer' => '', 'description' => '', 'startsAt' => '2026-11-03 10:00:00', 'endsAt' => null, 'isPublic' => true],
            ['id' => 2, 'projectNo' => 'A-1', 'title' => 'b', 'organizer' => '', 'description' => '', 'startsAt' => '2026-11-03 10:00:00', 'endsAt' => null, 'isPublic' => false],
            ['id' => 3, 'projectNo' => 'Q-9', 'title' => 'c', 'organizer' => '', 'description' => '', 'startsAt' => '2026-11-03 10:00:00', 'endsAt' => null, 'isPublic' => true],
        ], ['A-1' => ['n1', 'n2']])));
    check('公開の行に「一般に出す」の印を残さない', false, array_key_exists('isPublic', km_event_schedule_public_rows([['id' => 1, 'projectNo' => null, 'title' => 'a', 'organizer' => '', 'description' => '', 'startsAt' => 'x', 'endsAt' => null, 'isPublic' => true]], [])[0]));
    $mapDataForEvents = (string) file_get_contents(__DIR__ . '/../lib/map-data.php');
    check('イベント中に出す地点: 番号・イベント中の名前・階段・出入口・トイレ・道は出す、ふつうの部屋は出さない', [true, true, true, true, true, true, false, false],
        [km_map_event_node_visible(['type' => 'room', 'projects' => ['A-1']], false), km_map_event_node_visible(['type' => 'room'], true),
         km_map_event_node_visible(['type' => 'stairs'], false), km_map_event_node_visible(['type' => 'entrance'], false),
         km_map_event_node_visible(['type' => 'facility', 'name' => '男子トイレ'], false), km_map_event_node_visible(['type' => 'road'], false),
         km_map_event_node_visible(['type' => 'room', 'name' => '講義室'], false), km_map_event_node_visible(['type' => 'facility', 'name' => '体育館'], false)]);
    check_bool('地図データ: イベントの地点だけを出すのは有効なイベントがあり ON のとき・隠すのは印だけ(経路は通れる)・スケジュールとポスターは出している地点だけ', str_contains($mapDataForEvents, "if (\$overlay['events'] !== [] && (\$overlay['onlyEventNodes'] ?? false) && empty(\$node['hidden'])")
        && str_contains($mapDataForEvents, "'schedule' => km_event_schedule_public(\$pdo, \$shownProjectNodes, \$projectPois),")
        && str_contains($mapDataForEvents, "km_map_project_nodes(\$projectsById, array_filter(\$nodesById, static fn (array \$n): bool => empty(\$n['hidden'])))")
        && strpos($mapDataForEvents, "'schedule' =>") > strpos($mapDataForEvents, 'if (!$viewUnlocked) {'));
    $eventsLib = (string) file_get_contents(__DIR__ . '/../lib/map-events.php');
    check_bool('イベントの設定は別表・行が無ければ ON・子の表と一緒に消し、取り込みで入れ直す', str_contains($eventsLib, 'CREATE TABLE IF NOT EXISTS km_map_event_options (')
        && in_array('km_map_event_options', KM_MAP_EVENT_CHILD_TABLES, true) && str_contains($eventsLib, "if (\$options[(int) \$eventId] ?? true) {")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/map-event-import.php'), "'onlyEventNodes' => (\$event->onlyEventNodes ?? null) !== false,"));
    $postersForProjects = (string) file_get_contents(__DIR__ . '/../lib/posters.php');
    check_bool('ポスター: 企画の番号は別表・番号の地点全部に出す(地点の指定より先)・番号のあるものは広告にしない', str_contains($postersForProjects, 'CREATE TABLE IF NOT EXISTS km_poster_projects (')
        && str_contains($postersForProjects, "\$nodeIds = \$projectNo !== '' ? (\$projectNodes[\$projectNo] ?? []) : (\$nodeId !== '' ? [\$nodeId] : []);")
        && str_contains($postersForProjects, "'isAd' => (string) (\$row['nodeId'] ?? '') === '' && \$projectNo === '',"));
    $schedulePage = (string) file_get_contents(__DIR__ . '/../admin/schedule.php');
    check_bool('管理画面: スケジュールは guard の内側・CSRF・記録に文言・サイドバー', str_contains($schedulePage, "require __DIR__ . '/_inc/guard.php';") && str_contains($schedulePage, 'km_csrf_verify()')
        && isset(KM_ADMIN_LOG_ACTION_LABELS['schedule.create'], KM_ADMIN_LOG_ACTION_LABELS['schedule.update'], KM_ADMIN_LOG_ACTION_LABELS['schedule.delete'], KM_ADMIN_LOG_ACTION_LABELS['schedule.public'])
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/_inc/partials/sidebar.php'), "./schedule.php"));
    /*
     * 一時地点の経路と番号(2026-10-23、利用者の報告「一時ノードのルート案内がうまく動作しない」。kosenmap_map+event.json を調べた)。
     * 版 22 のアプリは一時地点の接続を a / b の名前で書き出していた(R8)。読めずに一番近い地点へつなぎ、トイレや体育館の出入口が案内先になっていた
     */
    km_check_heading('一時地点: 接続の古い名前・道を先に・名前の番号・表示調整の既定を Web へ(2026-10-23)');
    require_once __DIR__ . '/../lib/map-event-import.php';
    $importNodes = [
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['floor' => '1', 'type' => 'room', 'x' => 10.0, 'y' => 10.0],
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => ['floor' => '1', 'type' => 'road', 'x' => 40.0, 'y' => 10.0],
        'cccccccccccccccccccccccccccccccc' => ['floor' => '1', 'type' => 'road', 'x' => 90.0, 'y' => 10.0],
    ];
    $importEvent = json_decode('{"uuid":"ev","name":"祭","places":[
        {"uuid":"p1","name":"21","floor":"1F","x":12,"y":1190,"category":"模擬店","note":"","staffOnly":false},
        {"uuid":"p2","name":"ゴミ箱","floor":"1F","x":12,"y":1190,"category":"","note":"","staffOnly":false}],
        "placeLinks":[{"a":"p1","b":"cccccccc-cccc-cccc-cccc-cccccccccccc"}]}');
    $importConverted = km_map_event_from_app($importEvent, $importNodes);
    check('取り込み: 版 22 の a / b の接続を読む・つないでいない地点は部屋より道を先に選ぶ', ['cccccccccccccccccccccccccccccccc', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
        array_column($importConverted['pois'], 'anchorNodeId'));
    check_bool('取り込み: Website で作って渡したイベント(web-event-<番号>)は、その番号のイベントを更新する', str_contains((string) file_get_contents(__DIR__ . '/../lib/map-event-import.php'), "if (\$found === false && \$webEventId !== null) {"));
    // イベントの取り込みでも教室の企画の番号を入れる(2026-10-23、利用者の報告「取り込むと地点が 35 で少ない」。それまで番号は捨てていた)
    $importProjectsMap = json_decode('{"nodes":[{"uuid":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","projectNumbers":["22"]},{"uuid":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","projectNumbers":["33","３４"]},{"uuid":"cccccccc-cccc-cccc-cccc-cccccccccccc"},{"uuid":"dddddddd-dddd-dddd-dddd-dddddddddddd","projectNumbers":["22"]}],"events":[]}');
    $importProjects = km_map_event_import_projects_of_map($importProjectsMap);
    check('取り込み: 書き出しの地点の企画の番号を置き場に入れ、読み戻しても同じ・地点の無い書き出しは null(表を触らない)',
        [3, $importProjects, null, [], null],
        [count($importProjects), km_map_event_import_projects_of(json_decode((string) json_encode(['p' => $importProjects]))->p),
            km_map_event_import_projects_of_map(json_decode('{"events":[]}')), km_map_event_import_projects_of(json_decode('[]')), km_map_event_import_projects_of(null)]);
    check('取り込み: 番号の見せ物(地点の数・Website に無い地点・番号の数・いまの数)', ['nodes' => 3, 'unknown' => 1, 'numbers' => 3, 'current' => 0],
        km_map_event_import_projects_summary($importProjects, ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => [], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => []], []));
    $mapEventsAdmin = (string) file_get_contents(__DIR__ . '/../admin/map-events.php');
    check_bool('取り込みの画面: 企画の番号も入れ替える(既定で印)・一時地点の数と分けて出す', str_contains($mapEventsAdmin, "if (\$pending['projects'] !== null && (\$_POST['import_projects'] ?? '') === '1') {")
        && str_contains($mapEventsAdmin, 'name="import_projects" value="1" id="km-import-projects" checked')
        && str_contains($mapEventsAdmin, '一時地点 <?= $event[\'poiCount\'] ?>')
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/map-event-import.php'), "'projects' => km_map_event_import_projects_of_map(\$map)]"));
    check('一時地点の名前が番号なら企画の番号(名前全体のときだけ)', ['21', 'A-3', null, null, null],
        [km_map_project_number_from_name(' 21 '), km_map_project_number_from_name('a-3'), km_map_project_number_from_name('受付'), km_map_project_number_from_name('21 たこ焼き'), km_map_project_number_from_name('')]);
    check('番号ごとの臨時の地点', ['21' => [7, 9], 'A-3' => [8]], km_map_project_pois([['id' => 7, 'name' => '21'], ['id' => 8, 'name' => 'A-3'], ['id' => 9, 'name' => '２１'], ['id' => 10, 'name' => 'ゴミ箱']]));
    check('スケジュール: 番号の一時地点も場所にする', [7], km_event_schedule_public_rows([['id' => 1, 'projectNo' => '21', 'title' => 'a', 'organizer' => '', 'description' => '', 'startsAt' => 'x', 'endsAt' => null, 'isPublic' => true]], [], ['21' => [7]])[0]['poiIds']);
    $appJsForPoi = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    check_bool('公開の地図: 一時地点は部屋と同じ吹き出し・名前はポスターの見出し(7 文字以上は 6 文字 + …)・備考はポスターの説明', str_contains($appJsForPoi, 'marker.bindPopup(poiDetailHtml(poi, shown));')
        && str_contains($appJsForPoi, "return chars.length >= 7 ? chars.slice(0, 6).join('') + '…' : chars.join('');")
        && str_contains($appJsForPoi, "note: poster && poster.description ? poster.description : String(poi.note || ''),"));
    check('教室のイベント中の名前が番号なら、その番号も付ける(二重にしない・番号でない名前は足さない)', ['n1' => ['22', '32'], 'n2' => ['33']],
        km_map_projects_with_aliases(['n1' => ['22'], 'n2' => ['33']], ['n1' => '32', 'n2' => '33', 'n3' => 'たこ焼き']));
    if (extension_loaded('pdo_sqlite')) {
        // 管理画面(ポスター・スケジュール)でも、イベント中の名前が番号の地点を数える(2026-10-23、企画 32 の報告)
        $adminProjectsDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adminProjectsDb->exec('CREATE TABLE km_map_node_projects (node_uuid TEXT, project_no TEXT, sort_order INT)');
        $adminProjectsDb->exec("INSERT INTO km_map_node_projects VALUES ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '22', 0)");
        check('管理画面の番号: イベントの表が無くても表の番号は出る', ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['22']], km_map_projects_for_admin($adminProjectsDb));
        $adminProjectsDb->exec('CREATE TABLE km_map_event_aliases (event_id INT, node_id TEXT, alias TEXT)');
        $adminProjectsDb->exec("INSERT INTO km_map_event_aliases VALUES (7, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '32'), (8, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '33'), (7, 'cccccccccccccccccccccccccccccccc', '救護室')");
        check('管理画面の番号: イベント中の名前「32」の地点が番号 32 に入る(全部のイベント・番号でない名前は入れない)',
            ['22' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], '32' => ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], '33' => ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']],
            km_map_project_nodes(km_map_projects_for_admin($adminProjectsDb)));
    }
    check_bool('管理画面のポスターとスケジュールは、イベント中の名前の番号も数える', str_contains((string) file_get_contents(__DIR__ . '/../admin/posters.php'), '$projectNodes = km_map_project_nodes(km_map_projects_for_admin($pdo));')
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/schedule.php'), '$projectNodes = km_map_project_nodes(km_map_projects_for_admin($pdo));'));
    check_bool('公開の地図: 番号で置いたポスターとスケジュールの場所に、イベント中の名前の番号も入る', str_contains((string) file_get_contents(__DIR__ . '/../lib/map-data.php'), "\$projectsById = km_map_projects_with_aliases(km_map_projects_by_node_id(\$pdo), \$overlay['aliases']);"));
    check_bool('公開の地図: 企画の教室はイベント中、札と吹き出しの名前がポスターの見出し(札は縮める)・備考がポスターの説明・ポスターが無ければ番号を前に付ける',
        // 2026-10-24 から、札はすべて 7 文字以上なら「6 文字 + …」(ポスターの見出しだけでなく)
        str_contains($appJsForPoi, 'escapeHtml(shortPoiName(project ? project.name : displayNameFor(node)));')
        && str_contains($appJsForPoi, 'const title = project ? project.name : (node.alias || node.name);')
        && str_contains($appJsForPoi, "if (!graphData.event || numbers.length === 0) return null;")
        && str_contains($appJsForPoi, "return { name: prefix ? `\${prefix} \${base}` : base, note: '', fromPoster: false };"));
    check_bool('公開の地図: 一時地点は部屋の地点と同じに描く(外も部屋。2026-10-23 に建物から戻した)・同じ種類の束に入れて出し入れと札の間引きも同じ・編集画面は今までの印',
        preg_match("/function poiNodeType\(poi\) \{[^}]*return 'room';\s*\}/", $appJsForPoi) === 1
        && str_contains($appJsForPoi, 'const marker = createPoiMarker(poi, type);')
        // 2026-10-24 から、イベントの地点の印(km-node-event)も付ける
        && str_contains($appJsForPoi, "className: 'km-node km-node-' + type + (hasPoster ? ' km-node-has-poster' : '') + (role ? ' km-node-' + role : ''),")
        && str_contains($appJsForPoi, 'poiMarkers.forEach(fn);')
        && str_contains($appJsForPoi, 'if (!window.isAdmin) return;'));
    require_once __DIR__ . '/../lib/display-defaults.php';
    check('表示調整の既定を Web の形に(対応する項目だけ)', [
        'nodeSize' => 120, 'labelSize' => 110, 'labelMode' => 'text_only', 'roomNames' => true, 'accessPoints' => false, 'roadGraph' => true,
        'types' => ['room' => ['shape' => 'star', 'size' => 90, 'label' => false, 'showScale' => 1.5, 'visible' => true], 'facility' => ['visible' => false],
            'stairs' => ['visible' => true], 'road' => ['visible' => false], 'entrance' => ['visible' => false], 'wall' => ['visible' => false], 'wifi_router' => ['visible' => false]],
    ], km_display_defaults_for_web([
        'map_node_size_percent' => 120, 'map_label_size_percent' => 110, 'map_node_label_mode' => 'text', 'map_display_layers' => ['layer_room_names'],
        'map_show_road_graph' => true, 'map_node_shape_room' => 'star', 'map_node_size_room' => 90, 'map_node_label_room' => false, 'map_node_zoom_room' => '1.5',
        'map_visible_node_types' => ['room', 'stairs'], 'map_color_areas' => true, 'map_node_color_room' => '#FF0000',
    ]));
    check_bool('公開ページ: 配った表示調整の既定を置き、map-style.js がそれを既定にする(「既定に戻す」も)', str_contains((string) file_get_contents(__DIR__ . '/../index.php'), '<script type="application/json" id="km-display-defaults"<?= km_csp_nonce_attr() ?>>')
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/map-style.js'), "document.getElementById('km-display-defaults')")
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/map-tuning.js'), 'style.defaultTypes()'));
    km_check_heading('Google マップ風: 検索 → 場所 → 経路・共有・♡・左の引き出し(2026-10-23)');
    $gmApp = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    $gmIndex = (string) file_get_contents(__DIR__ . '/../index.php');
    $gmCss = (string) file_get_contents(__DIR__ . '/../Main/styles.css');
    check_bool('出発地・目的地は地点の id で持つ・一時地点はつなぎ先(道でも)で断らない', str_contains($gmApp, 'const startNode = routeStartId || resolveNodeByLabel(startText);')
        && str_contains($gmApp, "if (poi ? String(poi.anchorNodeId) !== String(nodeId) : !isRoutableNode(node)) return;")
        && str_contains($gmApp, 'data-km-poi="${Number(poi.id)}"'));
    check_bool('片方だけ選んだら経路の欄を開いて、もう片方を尋ねる', str_contains($gmApp, "openRoutePanel(type === 'start' ? 'end' : 'start');") && str_contains($gmApp, "openRoutePanel(startNode ? 'end' : 'start');"));
    check_bool('出発・到着の印は札より前の層・経路の線は点より前', str_contains($gmApp, "window.map.createPane('kmRoutePins').style.zIndex = '680';")
        && str_contains($gmApp, "window.map.createPane('kmRouteLine').style.zIndex = '610';") && str_contains($gmApp, "pane: 'kmRoutePins',"));
    check_bool('経路の途中で階を移っても拡大率を戻さない(寄せるのは引いた直後の 1 回だけ)', str_contains($gmApp, 'if (currentRoutePath && !routeFitPending) {')
        && str_contains($gmApp, 'if (routeFitPending && pathBoundsGroup.getLayers().length > 0) {') && str_contains($gmApp, 'window.map.setView(firstOnFloor, window.map.getZoom());'));
    check_bool('検索の窓・種類のチップ・左の引き出し・一致率を読む(app.js より前)', str_contains($gmIndex, 'id="km-place-input"') && str_contains($gmIndex, 'id="km-category-chips"')
        && strpos($gmIndex, "Main/km-sheet.js')") < strpos($gmIndex, "<script defer src=\"<?= km_home_e(km_public_asset('Main/app.js'))") && strpos($gmIndex, "Main/search-rank.js')") < strpos($gmIndex, "<script defer src=\"<?= km_home_e(km_public_asset('Main/app.js'))"));
    check_bool('左の引き出しは左から(PC)・地図を覆わない', preg_match('/\.km-sheet \{[^}]*left: 0;[^}]*transform: translateX\(-105%\);/s', $gmCss) === 1 && !str_contains((string) file_get_contents(__DIR__ . '/../Main/km-sheet.js'), 'scrim'));
    check_bool('候補のアイコンはその場所のポスター(無ければ種類の形)', str_contains($gmApp, "box.classList.add('has-poster');") && str_contains($gmApp, "box.innerHTML = nodeIconHtml({ name: item.name }, item.type);"));
    check_bool('共有: 場所・一時地点・経路の URL。navigator.share が無ければ写す', str_contains($gmApp, "shareUrl('/?poi=' + Number(button.dataset.kmPoi)")
        && str_contains($gmApp, "shareUrl('/?' + [fromPart, toPart].concat(optionParts).join('&'), label") && str_contains($gmApp, "'fromPoi=' + routeStartPoi") && str_contains($gmApp, 'await navigator.clipboard.writeText(url);'));
    check_bool('共有のリンクは字の形だけ確かめて meta で渡す(from/to は 32 字の 16 進・poi は数字)', str_contains($gmIndex, "preg_match('/^[0-9A-Fa-f-]{32,36}\$/', \$value) !== 1")
        && str_contains($gmIndex, "preg_match('/^[1-9]\\d{0,9}\$/', \$poiParam) === 1") && str_contains($gmIndex, '<meta name="km-focus-from" content="<?= km_home_e($kmFocusFrom) ?>">') && str_contains($gmIndex, '$kmFocusFromPoi = $kmFocusFrom === null ? $kmPoiParam(\'fromPoi\') : null;'));
    check_bool('表示調整・履歴・予定も左の引き出しに出す', str_contains((string) file_get_contents(__DIR__ . '/../Main/map-tuning.js'), "sheet.open({ key: 'tuning', title: '表示調整'")
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/user-history.js'), "sheet.open({ key: 'history', title: '履歴とブックマーク'")
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/event-schedule.js'), "sheet.open({ key: 'schedule', title: 'スケジュール'"));
    check_bool('♡ の端末の印は押すときだけ作り、URL に載せない', str_contains($gmApp, 'const device = deviceId(true);') && str_contains($gmApp, "headers: device ? { 'X-KM-Device': device } : {}")
        && str_contains((string) file_get_contents(__DIR__ . '/../api/poster-like.php'), "\$_SERVER['HTTP_X_KM_DEVICE']"));
    require_once __DIR__ . '/../lib/poster-likes.php';
    check('♡: 尋ねる番号をそろえる(重複・形の違うものを外す)', [3, 1, 20], km_poster_like_ids('3,1,3,x,0,-2,20'));
    if (extension_loaded('pdo_sqlite')) {
        $likeDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        check('♡: 表が無ければ 0(地図のデータは止めない)', [[], [['id' => 5, 'count' => 0, 'liked' => false]]], [km_poster_like_counts($likeDb), km_poster_like_state($likeDb, [5], 'v')]);
        $likeDb->exec('CREATE TABLE km_poster_likes (poster_id INT NOT NULL, voter_hash TEXT NOT NULL, source_hash TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (poster_id, voter_hash))');
        $likeNow = strtotime('2026-10-23 12:00:00');
        km_poster_like_set($likeDb, 5, 'voterA', 'src', true, $likeNow);
        km_poster_like_set($likeDb, 5, 'voterA', 'src', true, $likeNow);
        km_poster_like_set($likeDb, 5, 'voterB', 'src', true, $likeNow);
        check('♡: 1 端末 1 つ・数と自分が押したか', [['id' => 5, 'count' => 2, 'liked' => true], ['id' => 5, 'count' => 2, 'liked' => false]],
            [km_poster_like_state($likeDb, [5], 'voterA')[0], km_poster_like_state($likeDb, [5], 'voterC')[0]]);
        km_poster_like_set($likeDb, 5, 'voterA', 'src', false, $likeNow);
        check('♡: 外すと消える・地図のデータの数', [5 => 1], km_poster_like_counts($likeDb));
        for ($i = 0; $i < KM_POSTER_LIKE_SOURCE_LIMIT; $i++) {
            km_poster_like_set($likeDb, 100 + $i, 'flood', 'src2', true, $likeNow);
        }
        check('♡: 同じ送り元から 1 時間に ' . KM_POSTER_LIKE_SOURCE_LIMIT . ' を超えたら断る・1 時間たてば付けられる', ['limited', 'ok'],
            [km_poster_like_set($likeDb, 999, 'flood', 'src2', true, $likeNow + 60), km_poster_like_set($likeDb, 999, 'flood', 'src2', true, $likeNow + 3601)]);
        check('♡: 管理画面の 0 に戻す', [1, []], [km_poster_likes_reset($likeDb, 5), km_poster_like_state($likeDb, [5], null) === [['id' => 5, 'count' => 0, 'liked' => false]] ? [] : ['残った']]);
    }
    $likeApi = (string) file_get_contents(__DIR__ . '/../api/poster-like.php');
    check_bool('♡ の口: 付け外しは CSRF を見てから・いま出しているポスターだけ・鍵つきの印だけ持つ', str_contains($likeApi, "if (!km_csrf_verify()) {")
        && strpos($likeApi, 'km_csrf_verify()') < strpos($likeApi, 'km_poster_like_set(') && str_contains($likeApi, "\$shown = array_column(km_posters_public(\$pdo, km_poster_event_active(\$pdo), []), 'id');")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/poster-likes.php'), "km_app_keyed_hash(km_app_secret(\$pdo, KM_POSTER_LIKE_KEY_NAME), 'poster-like-voter', \$device)"));
    check_bool('♡: 地図のデータに数を載せる・管理画面で 0 に戻せる・プライバシーポリシーに書いた', str_contains((string) file_get_contents(__DIR__ . '/../lib/posters.php'), "'likes' => \$likes[(int) \$row['id']] ?? 0,")
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/posters.php'), "if (\$formAction === 'poster_likes_reset') {")
        && str_contains((string) json_encode(km_legal_privacy(), JSON_UNESCAPED_UNICODE), 'ポスターの ♡'));
    check_bool('ポスターから結びつけた場所へ(地図の引き出し・一覧のページ)', str_contains($gmApp, "head.textContent = poster.projectNo ? `企画 \${poster.projectNo} の場所` : '場所';")
        && str_contains((string) file_get_contents(__DIR__ . '/../posters.php'), "foreach (\$poster['poiIds'] ?? [] as \$poiId) {"));

    km_check_heading('QR のリンク集: /qr.php に並べるリンクを管理画面で決める(2026-10-24)');
    require_once __DIR__ . '/../lib/qr-links.php';
    check('URL: https とサイトの中の道だけ(javascript:・よそへの //・空白は断る)', [true, true, true, false, false, false, false],
        [km_qr_link_url_valid('https://example.com/a?b=1'), km_qr_link_url_valid('/posters.php'), km_qr_link_url_valid('/'), km_qr_link_url_valid('javascript:alert(1)'),
            km_qr_link_url_valid('//evil.example/'), km_qr_link_url_valid('/a b'), km_qr_link_url_valid('')]);
    check('入力: 前後の空白を落とす・説明は空なら null・名前と URL は要る', ['title' => 'ポスター', 'url' => '/posters.php', 'description' => null, 'isEnabled' => true],
        km_qr_link_validate(['title' => ' ポスター ', 'url' => ' /posters.php ', 'description' => '  ', 'isEnabled' => true]));
    $qrRefused = [];
    foreach ([['title' => '', 'url' => '/'], ['title' => 'x', 'url' => 'javascript:x'], ['title' => str_repeat('あ', KM_QR_LINK_TITLE_MAX + 1), 'url' => '/']] as $bad) {
        try {
            km_qr_link_validate($bad);
            $qrRefused[] = false;
        } catch (InvalidArgumentException $exception) {
            $qrRefused[] = true;
        }
    }
    check('入力: 名前が空・形の違う URL・長すぎる名前を断る', [true, true, true], $qrRefused);
    if (extension_loaded('pdo_sqlite')) {
        $qrDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        check('公開ページ: 表が無ければ空(作らない)', [], km_qr_links_public($qrDb));
        $qrDb->exec('CREATE TABLE km_qr_links (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, url TEXT, description TEXT, is_enabled INT, sort_order INT, created_at TEXT, updated_at TEXT)');
        $qrDb->exec("INSERT INTO km_qr_links (title, url, description, is_enabled, sort_order) VALUES ('A', '/a', NULL, 1, 20), ('B', 'https://b.example/', 'x', 0, 10), ('C', 'javascript:x', NULL, 1, 5), ('D', '/d', NULL, 1, 30)");
        check('公開ページ: 出すものだけ・並び順・あとで通らなくなった URL は出さない', ['A', 'D'], array_column(km_qr_links_public($qrDb), 'title'));
    }
    $qrPage = (string) file_get_contents(__DIR__ . '/../qr.php');
    check_bool('公開ページ: 先頭はいつも地図・DB が止まっても出す・よそのサイトは新しいタブで行き先を見せる', str_contains($qrPage, '<a class="km-qr-link" href="/">')
        && str_contains($qrPage, "error_log('qr.php (public) failed (fail open): '") && str_contains($qrPage, ' target="_blank" rel="noopener noreferrer"'));
    $qrAdmin = (string) file_get_contents(__DIR__ . '/../admin/qr-links.php');
    check_bool('管理画面: guard の内側・CSRF を見てから書く・記録に残す・左の一覧にある', str_contains($qrAdmin, "require __DIR__ . '/_inc/guard.php';")
        && strpos($qrAdmin, 'km_csrf_verify()') < strpos($qrAdmin, 'km_qr_link_save(') && str_contains($qrAdmin, "'qr_link.create'")
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/_inc/partials/sidebar.php'), 'href="./qr-links.php"'));

    km_check_heading('お知らせ: 管理画面で書く・最初の画面と詳しいページ・プレビュー(2026-10-24)');
    require_once __DIR__ . '/../lib/notices.php';
    check('本文: 「## 」の行が見出し・空行で段落・見出しより前の段落も残す', [
        ['heading' => null, 'paragraphs' => ['はじめの段落']],
        ['heading' => '何があったか', 'paragraphs' => ["一行目\n二行目", '次の段落']],
        ['heading' => '空の節', 'paragraphs' => []],
    ], km_notice_parse_body("はじめの段落\n\n## 何があったか\n一行目\n二行目\n\n\n次の段落\n## 空の節\n"));
    check('入力: 整える・最後の日は空なら null', ['title' => '題', 'summary' => '短い', 'body' => '', 'publishedOn' => '2026-10-24', 'showOnWelcome' => true, 'showUntil' => null, 'isPublished' => false],
        km_notice_validate(['title' => ' 題 ', 'summary' => ' 短い ', 'body' => '', 'publishedOn' => '2026-10-24', 'showOnWelcome' => true, 'showUntil' => '']));
    $noticeRefused = [];
    foreach ([['title' => '', 'summary' => 'x', 'publishedOn' => '2026-10-24'], ['title' => 'x', 'summary' => '', 'publishedOn' => '2026-10-24'],
        ['title' => 'x', 'summary' => 'x', 'publishedOn' => '2026-02-30'], ['title' => 'x', 'summary' => 'x', 'publishedOn' => '2026-10-24', 'showUntil' => '2026-10-01']] as $bad) {
        try {
            km_notice_validate($bad);
            $noticeRefused[] = false;
        } catch (InvalidArgumentException $exception) {
            $noticeRefused[] = true;
        }
    }
    check('入力: 題・短い文が空・無い日付・最後の日が日付より前を断る', [true, true, true, true], $noticeRefused);
    $noticeSample = [
        ['id' => 1, 'date' => '2026-10-01', 'until' => '2026-10-31', 'showOnWelcome' => true],
        ['id' => 2, 'date' => '2026-10-20', 'until' => null, 'showOnWelcome' => true],
        ['id' => 3, 'date' => '2026-10-20', 'until' => null, 'showOnWelcome' => false],
        ['id' => 4, 'date' => '2026-12-01', 'until' => '2026-12-31', 'showOnWelcome' => true],
    ];
    check('最初の画面: 「最初の画面に出す」が付いていて、出す期間の中のものだけ(最後の日が空なら終わりなし)', [[1, 2], [2], []],
        [array_column(km_notices_current('2026-10-24', $noticeSample), 'id'), array_column(km_notices_current('2026-11-05', $noticeSample), 'id'), array_column(km_notices_current('2026-09-01', $noticeSample), 'id')]);
    check('id は数字だけ・日付の書き方', [true, false, false, '2026年10月24日'], [km_notice_id_valid('12'), km_notice_id_valid('../x'), km_notice_id_valid('0'), km_notice_date_label('2026-10-24')]);
    $noticeHtml = km_notice_detail_html(['date' => '2026-10-24', 'summary' => '<b>x</b>', 'sections' => [['heading' => '<script>', 'paragraphs' => ['a & b']]]]);
    check_bool('くわしいページの中身は全部エスケープする(HTML は書かせない)', str_contains($noticeHtml, '&lt;b&gt;x&lt;/b&gt;') && str_contains($noticeHtml, '<h2>&lt;script&gt;</h2>') && str_contains($noticeHtml, 'a &amp; b'));
    if (extension_loaded('pdo_sqlite')) {
        $noticeDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        check('公開: 表が無ければ空(公開ページからは作らない)', [], km_notices_public($noticeDb, '2026-10-24'));
        $noticeDb->exec('CREATE TABLE km_notices (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, summary TEXT, body TEXT, published_on TEXT, show_on_welcome INT, show_until TEXT, is_published INT, created_at TEXT, updated_at TEXT)');
        $noticeDb->exec("INSERT INTO km_notices (title, summary, body, published_on, show_on_welcome, show_until, is_published) VALUES
            ('公開', 's', '## 見出し\n本文', '2026-10-24', 1, NULL, 1), ('下書き', 's', '', '2026-10-24', 1, NULL, 0), ('先の日付', 's', '', '2026-12-01', 1, NULL, 1)");
        $noticePublic = km_notices_public($noticeDb, '2026-10-24');
        check('公開: 公開の印があり日付が来たものだけ(下書き・先の日付は出さない)・本文を節に分ける', [['公開'], '見出し'],
            [array_column($noticePublic, 'title'), $noticePublic[0]['sections'][0]['heading'] ?? null]);
    }
    $noticeLib = (string) file_get_contents(__DIR__ . '/../lib/notices.php');
    check_bool('1 件目は PHP の脆弱性の報告(表を作ったときだけ。CVE・修正版・跡は見つからなかった・操作は要らない)', str_contains($noticeLib, 'if ($created) {')
        && str_contains($noticeLib, 'CVE-2026-109618') && str_contains($noticeLib, '8.4.27') && str_contains($noticeLib, '不正に使われた跡は見つかっていません') && str_contains($noticeLib, '行っていただく操作はありません'));
    $noticeIndex = (string) file_get_contents(__DIR__ . '/../index.php');
    $noticeWelcome = (string) file_get_contents(__DIR__ . '/../Main/welcome.js');
    check_bool('「はじめに」の先頭にお知らせ(読めなければ出さない)・読んでいないお知らせがあれば読んだ人にも出す・設定から一覧へ', str_contains($noticeIndex, '$kmNotices = km_notices_current(km_notice_today(), km_notices_public(km_db(), km_notice_today()));')
        && str_contains($noticeIndex, 'data-notices="<?= km_home_e(implode(\',\', array_column($kmNotices, \'id\'))) ?>"') && str_contains($noticeIndex, '<a href="/notice.php" class="secondary-btn">お知らせ</a>')
        && str_contains($noticeWelcome, 'if ((!seen() || unreadNotices().length > 0) && !fromShare) open();'));
    $noticePage = (string) file_get_contents(__DIR__ . '/../notice.php');
    check_bool('くわしいページ: 公開しているものだけ・知らなければ 404 と一覧・DB が止まっても出す・中身はプレビューと同じ組み立て', str_contains($noticePage, '$all = km_notices_public($pdo, km_notice_today());')
        && str_contains($noticePage, 'http_response_code(404);') && str_contains($noticePage, "error_log('notice.php (public) failed (fail open): '") && str_contains($noticePage, '<?= km_notice_detail_html($notice) ?>'));
    $noticeAdmin = (string) file_get_contents(__DIR__ . '/../admin/notices.php');
    check_bool('管理画面: guard の内側・CSRF を見てから書く・プレビューは保存しない(公開ページと同じ組み立て)・記録に残す・左の一覧にある', str_contains($noticeAdmin, "require __DIR__ . '/_inc/guard.php';")
        && strpos($noticeAdmin, 'km_csrf_verify()') < strpos($noticeAdmin, 'km_notice_save(') && str_contains($noticeAdmin, "if (\$formAction === 'preview') {")
        && str_contains($noticeAdmin, '<?= km_notice_detail_html($preview) ?>') && str_contains($noticeAdmin, "'notice.create'")
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/_inc/partials/sidebar.php'), 'href="./notices.php"'));
    /*
     * ======== 地図の 6 つの直し(2026-10-24、利用者の指示) ========
     *   1. 共有から開いたときは、チュートリアル・前回の経路を出さない(広告は出す)
     *   2. 札はすべて 6 文字 + …
     *   3. 経路の線が地点や札に重なる所は透かす・イベントで関係ない地点は透かす
     *   4. イベントの地点は太い赤い縁
     *   5. チュートリアルとお知らせを機能ごと ON/OFF
     *   6. 経路が使う階をいつも見せる
     */
    km_check_heading('地図: 共有のリンクから開いたとき(2026-10-24)');
    $shareIndex = (string) file_get_contents(__DIR__ . '/../index.php');
    $shareTutorial = (string) file_get_contents(__DIR__ . '/../Main/tutorial.js');
    $shareHistory = (string) file_get_contents(__DIR__ . '/../Main/user-history.js');
    $shareEnd = (string) file_get_contents(__DIR__ . '/../Main/tutorial-end.js');
    $shareApp = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    // 印は 1 つ。部屋の QR・場所・経路(両端そろったときだけ)のどれでも立つ
    check_bool('共有の印は部屋・場所・経路のどれでも立つ', str_contains($shareIndex, '$kmFromShare = $kmFocusRoom !== null')
        && str_contains($shareIndex, '|| $kmFocusPoi !== null')
        && str_contains($shareIndex, '|| (($kmFocusFrom !== null || $kmFocusFromPoi !== null) && ($kmFocusTo !== null || $kmFocusToPoi !== null));')
        && str_contains($shareIndex, '<meta name="km-from-share" content="1">'));
    check_bool('チュートリアルは共有の印を見て出さない', str_contains($shareTutorial, "if (document.querySelector('meta[name=\"km-from-share\"]')) return;"));
    // 前回の経路は聞かない(同期の答えも決めない。決まっている人の同期は続ける)
    check_bool('前回の経路は共有の印を見て聞かない', preg_match("/if \(document\.querySelector\('meta\[name=\"km-from-share\"\]'\)\) \{\s*try \{ window\.sessionStorage\.setItem\('km-route-resume-asked', '1'\);/", $shareHistory) === 1
        && strpos($shareHistory, 'meta[name="km-from-share"]') < strpos($shareHistory, "ask('前回の経路'"));
    // 広告は出す。チュートリアルを通らない入口。セッションで 1 回だけ・選び方と数え方はチュートリアルの終わりと同じ
    check_bool('広告だけを出す入口(セッションで 1 回・同じ選び方と数え方)', str_contains($shareEnd, 'window.kmShowAd = function () {')
        && str_contains($shareEnd, "const AD_SESSION_KEY = 'km-ad-shown';")
        && str_contains($shareEnd, 'showAd(pickAd(list), function () {'));
    check_bool('共有から来たら、開いた所を出したあとで広告', str_contains($shareApp, "if (document.querySelector('meta[name=\"km-from-share\"]')) {")
        && str_contains($shareApp, 'if (typeof window.kmShowAd === \'function\') window.kmShowAd();')
        && str_contains($shareApp, 'const SHARE_AD_DELAY_MS = 1500;'));

    // 2026-10-29(振り返りのフェーズ 0): 経路の選択も共有する。開いた人の保存した設定は書き換えない。共有の経路は履歴に入れない
    $shareIndexCode = (string) file_get_contents(__DIR__ . '/../index.php');
    check_bool('共有: 経路の選択(vp・ff・rain)を URL に載せる', str_contains($shareApp, "verticalPreference === 'elevator' || verticalPreference === 'stairs' ? 'vp=' + verticalPreference : ''")
        && str_contains($shareApp, "routeOptions.fewerFloors ? 'ff=1' : ''") && str_contains($shareApp, "routeOptions.rain ? 'rain=1' : ''"));
    check_bool('共有: 受け取りは決まった値だけ', str_contains($shareIndexCode, "in_array(\$_GET['vp'] ?? '', ['elevator', 'stairs'], true)")
        && str_contains($shareIndexCode, "(\$_GET['ff'] ?? '') === '1'") && str_contains($shareIndexCode, '<meta name="km-focus-route-options"'));
    if (preg_match('/const shared = \(read\(\'km-focus-route-options\'\).*?routeFromShare = false;/s', $shareApp, $sharedBlock) === 1) {
        check_bool('共有: 選択はこのページの間だけ(端末に保存しない)・共有から引いた印を付ける', !str_contains($sharedBlock[0], 'localStorage')
            && str_contains($sharedBlock[0], 'rebuildDijkstra();') && str_contains($sharedBlock[0], 'routeFromShare = true;'));
    } else {
        check_bool('共有: 選択を当てる所がある', false);
    }
    check_bool('共有: 共有から引いた経路は履歴(前回の経路)に入れない', str_contains($shareApp, 'shared: routeFromShare } }));')
        && str_contains($shareHistory, 'if (d.shared) return;'));

    // 2026-10-29(振り返りのフェーズ 1): 地図の周りは温かい色・カーソルは縁取り・暗い配色でも床は明るいまま(沈めるのは表示調整で選んだときだけ)
    $lookCss = (string) file_get_contents(__DIR__ . '/../Main/styles.css');
    check_bool('見やすさ: 周りは温かい色・Leaflet の灰色を使わない・地図の上は縁取りのカーソル', str_contains($lookCss, '--bg-map: #F3EBDD;')
        && str_contains($lookCss, '#map.leaflet-container { background: var(--bg-map); }')
        && str_contains($lookCss, "cursor: url('cursor-map.svg') 16 16, grab;") && is_file(__DIR__ . '/../Main/cursor-map.svg'));
    check_bool('見やすさ: 床の画像を沈めるのは「床の画像も暗く」を選んだ暗い配色だけ', substr_count($lookCss, '--map-image-filter: invert(') === 2
        && substr_count($lookCss, '[data-km-floor-dim="1"] {') === 2
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/map-style.js'), 'dimFloorImage: false,')
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/map-tuning.js'), "if (tuning.dimFloorImage) root.setAttribute('data-km-floor-dim', '1');"));

    // 同じフェーズの 2(2026-10-30): ぼかしをやめて透けない面に・押せるものに濃い縁・いまの階は内側の縁(外側だと並びの箱で切れる)
    check_bool('見やすさ: ぼかし(backdrop-filter の blur)を使わない・面は透けない', !str_contains($lookCss, 'backdrop-filter: blur')
        && str_contains($lookCss, '--glass-heavy: #FFFFFF;') && substr_count($lookCss, '--glass-heavy: #0F172A;') === 2);
    check_bool('見やすさ: 地図の上の操作に 2px の濃い縁・いまの階は内側に 3px', preg_match('/\.km-searchbox,\s*\.km-chip,\s*\.km-action-btn,\s*\.floor-nav,\s*\.km-account-summary,\s*\.km-route-bar \{\s*border: 2px solid var\(--km-control-edge\);/', $lookCss) === 1
        && preg_match('/\.floor-nav \.floor-btn\.active,\s*\.floor-nav\.is-collapsed \.floor-toggle \{\s*border: 3px solid var\(--km-control-edge\);/', $lookCss) === 1);
    // 同じフェーズの 4: 選んだ場所は赤いピン + 脈打つ輪(先と輪の真ん中を iconAnchor の 48px にそろえる)・到着は赤い面で大きく
    $lookApp = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    check_bool('見やすさ: 選んだ場所は赤いピンと輪・到着は大きな赤い吹き出しと輪・動きを減らす設定では輪を止める', str_contains($lookApp, "className: 'km-dest-pin',")
        && str_contains($lookApp, 'iconAnchor: [22, 48],') && str_contains($lookCss, '.km-dest-pin .km-dest-pulse { left: 50%; top: 48px; }')
        && str_contains($lookApp, "\${isEnd ? '<span class=\"km-dest-pulse\" aria-hidden=\"true\"></span>' : ''}")
        && str_contains($lookCss, 'font-size: 17px;') && str_contains($lookCss, '.km-dest-pulse { animation: none;'));

    // 2026-10-30(振り返りのフェーズ 2「ズームがカクつく」): 横へ動かしただけでは札を測り直さない・書くのはコマに分ける・書き終えてから経路の重なり
    check_bool('軽さ: 移動だけなら札を測り直さない(拡大率・階・札の数が同じ)・拡大と外からの呼び出しは必ず測る',
        str_contains($shareApp, "window.map.on('moveend', () => scheduleDeclutter(false));") && str_contains($shareApp, 'scheduleDeclutter(true);')
        && str_contains($shareApp, "if (force !== true && declutterKey() === lastDeclutterKey) return;")
        && str_contains($shareApp, 'window.kmDeclutterLabels = function () { scheduleDeclutter(true); };'));
    check_bool('軽さ: 札の置き方はコマに分けて書く・次の並べ直しが始まったら古い分は書かない・書き終えたら経路の重なりを塗り直す',
        str_contains($shareApp, 'window.requestAnimationFrame(writeChunk);') && str_contains($shareApp, 'if (generation !== declutterGeneration) return;')
        && str_contains($shareApp, 'if (routeLineRuns.length > 0) routeLineRuns.forEach(paintRouteRun);'));

    km_check_heading('地図: 札は 6 文字 + …・イベントの地点の見分け・経路の線(2026-10-24)');
    $viewCss = (string) file_get_contents(__DIR__ . '/../Main/styles.css');
    check_bool('札はすべて縮める(地点・一時地点)', str_contains($shareApp, 'escapeHtml(shortPoiName(project ? project.name : displayNameFor(node)));')
        && str_contains($shareApp, 'escapeHtml(shortPoiName(shown.name))'));
    check_bool('縮め方は 7 文字以上を 6 文字 + …', str_contains($shareApp, "return chars.length >= 7 ? chars.slice(0, 6).join('') + '…' : chars.join('');"));
    // イベントの地点 = 臨時の名前・企画の番号・ポスターのどれか(+ 一時地点)。イベント中・公開の地図だけ
    check_bool('イベントの地点の決め方', str_contains($shareApp, "if (window.isAdmin || !graphData.event) return '';")
        && str_contains($shareApp, "return node.alias || hasProject || hasPoster ? 'event' : 'unrelated';")
        && str_contains($shareApp, "const role = window.isAdmin ? '' : 'event';"));
    check_bool('印と札の両方に付ける', str_contains($shareApp, "(role ? ' km-node-' + role : '')") && str_contains($shareApp, "(role ? ' km-label-' + role : '')"));
    check_bool('イベントの地点は太い赤い縁(印と札)', preg_match('/\.km-node-event \.km-node-shape path \{\s*stroke: #dc2626;\s*stroke-width: 3\.5px;/', $viewCss) === 1
        && str_contains($viewCss, 'border: 3px solid #dc2626 !important;'));
    // 50% 以上透かす。札は Leaflet が opacity を要素に直接書くので !important
    check_bool('関係ない地点は 50% 以上透かす', str_contains($viewCss, '.km-node-unrelated { opacity: 0.45; }')
        && str_contains($viewCss, '.km-label.km-label-unrelated { opacity: 0.45 !important; }'));
    // 60〜80% 透かす(0.3 = 70%)。重なりは画面の座標で、出している印と札の箱
    check_bool('線が重なる所は 70% 透かす', str_contains($shareApp, 'const ROUTE_OVERLAP_OPACITY = 0.3;')
        && str_contains($shareApp, 'view.splitRouteByObstacles(points, routeObstacleRects(), ROUTE_OVERLAP_PAD)')
        && str_contains($shareApp, "container.querySelectorAll('.leaflet-marker-pane .km-node, .leaflet-tooltip-pane .km-label')"));
    // 2026-10-30: 塗り直すのは拡大率が変わったときだけ(横へ動かしただけでは重なる所は変わらない)
    check_bool('拡大し終えたら塗り直す・横へ動かしただけでは塗り直さない・経路を消したら忘れる', str_contains($shareApp, "window.map.on('zoomend', refreshRouteOverlap);")
        && !str_contains($shareApp, "window.map.on('moveend', refreshRouteOverlap);")
        && preg_match('/function clearRoute\(\) \{[^}]*routeLineRuns = \[\];/s', $shareApp) === 1);
    check_bool('route-view.js を app.js より先に読む', ($rv = strpos($shareIndex, "Main/route-view.js')")) !== false
        && $rv < (int) strpos($shareIndex, "<script defer src=\"<?= km_home_e(km_public_asset('Main/app.js'))"));

    km_check_heading('地図: 経路が使う階をいつも見せる(2026-10-24)');
    check_bool('帯の上に階の列', str_contains($shareIndex, '<div id="km-route-floors" class="km-route-floors" role="group" aria-label="この経路が使う階" hidden></div>'));
    check_bool('並びは routeFloorSequence(続けて同じ階は畳む)・押すとその階へ', str_contains($shareApp, 'view.routeFloorSequence(currentRoutePath.map(')
        && str_contains($shareApp, "chip.addEventListener('click', () => changeFloor(String(floor)));"));
    check_bool('帯を書くたびに出し直す・階を移ったら塗り直す', str_contains($shareApp, 'renderRouteFloors(active);')
        && str_contains($shareApp, 'if (window.kmRenderRouteFloors) window.kmRenderRouteFloors();'));
    check_bool('右の階の列にも印', str_contains($shareApp, "btn.classList.toggle('is-on-route', onRoute.has(btn.dataset.floor));")
        && str_contains($viewCss, '.floor-nav .floor-btn.is-on-route { box-shadow: inset 0 0 0 3px #ef4444; }'));

    km_check_heading('地図: 共有のリンクを一般向けアプリで開く(App Links。2026-10-24)');
    $linkNginx = (string) @file_get_contents(km_check_repo_root() . '/nginx/default.conf.template');
    // Android が確かめる。一般向けだけ(管理アプリを載せると、同じリンクで選ぶ画面が出る)。リダイレクトさせず JSON で返す
    check_bool('assetlinks.json を返す(一般向けのパッケージ・リリースの鍵・JSON)', str_contains($linkNginx, 'location = /.well-known/assetlinks.json {')
        && str_contains($linkNginx, '"package_name":"com.ito.kosenmap",')
        && str_contains($linkNginx, '"sha256_cert_fingerprints":["D7:1F:BF:0A:0D:92:FA:EE:D8:67:CD:88:F0:E7:B3:3C:3D:D9:A7:49:66:90:0B:B0:2A:19:F1:E4:3C:2A:93:AF"]')
        && !str_contains($linkNginx, '"package_name":"com.ito.kosenmap.admin"')
        && preg_match('/location = \/\.well-known\/assetlinks\.json \{\s*default_type application\/json;/', $linkNginx) === 1);

    km_check_guide_chat();

    km_check_heading('地図: チュートリアルとお知らせの ON/OFF(2026-10-24)');
    require_once __DIR__ . '/../lib/map-switches.php';
    check('保存の値: \'0\' だけが OFF(空や知らない値は既定の ON)', [false, true, true, true],
        [km_map_switch_value('0'), km_map_switch_value('1'), km_map_switch_value(''), km_map_switch_value('yes')]);
    check_bool('スイッチは読めなければ ON(今までどおり出す)', str_contains($shareIndex, "\$kmSwitches = ['tutorial' => true, 'notices' => true];"));
    check_bool('チュートリアル OFF: tutorial.js を読まない・「操作の案内」を出さない', preg_match("/<\?php if \(\\\$kmSwitches\['tutorial'\]\): \?>\s*<script defer src=\"<\?= km_home_e\(km_public_asset\('Main\/tutorial\.js'\)\) \?>\"><\/script>/", $shareIndex) === 1
        && preg_match("/<\?php if \(\\\$kmSwitches\['tutorial'\]\): \?>\s*<button type=\"button\" class=\"secondary-btn\" data-km-tutorial-open>/", $shareIndex) === 1);
    // 広告はチュートリアルの終わりから出していた。止めたら「はじめに」を閉じたときに出す
    check_bool('チュートリアル OFF でも広告は出す(「はじめに」を閉じたとき)', str_contains($shareIndex, "<?= \$kmSwitches['tutorial'] ? '' : ' data-tutorial=\"off\"' ?>")
        // 2026-10-30 から最初の画面(entry.js)があるときは、そちらの「すぐ地図へ」で出す
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/welcome.js'), "if (dialog.dataset.tutorial === 'off' && !window.kmEntry && typeof window.kmShowAd === 'function') {"));
    check_bool('お知らせ OFF: 最初の画面に出さない・一覧へのボタンを出さない', str_contains($shareIndex, "if (\$kmSwitches['notices']) {")
        && preg_match("/<\?php if \(\\\$kmSwitches\['notices'\]\): \?>\s*<a href=\"\/notice\.php\" class=\"secondary-btn\">お知らせ<\/a>/", $shareIndex) === 1);
    $switchNoticePage = (string) file_get_contents(__DIR__ . '/../notice.php');
    check_bool('お知らせ OFF: 公開ページは 404(書いたものは消さない)', str_contains($switchNoticePage, "\$noticesOff = !km_map_switches(\$pdo)['notices'];")
        && preg_match('/if \(\$noticesOff\) \{\s*http_response_code\(404\);/', $switchNoticePage) === 1);
    $switchAdmin = (string) file_get_contents(__DIR__ . '/../admin/notices.php');
    check_bool('管理画面「お知らせ」で切り替える(CSRF のあと・記録に残す)', str_contains($switchAdmin, "if (\$formAction === 'switches') {")
        && strpos($switchAdmin, 'km_csrf_verify()') < strpos($switchAdmin, 'km_map_switches_save(')
        && str_contains($switchAdmin, "km_admin_log_record('content', 'map.switches',")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/admin-log.php'), "'map.switches' =>"));

    km_check_heading('posters: 広告(重み 10%・必ず 1 件)・数・チュートリアルの終わりの知らせ(2026-10-21)');
    check('広告の重みは 10%(利用者の指示。全部同じ)', 10, KM_POSTER_AD_WEIGHT);
    check('広告が 1 件なら 100%・2 件なら 50%・10 件なら 10%・無ければ 0(表示しないわけではない)', [1.0, 0.5, 0.1, 0.0],
        [km_poster_ad_share(1), km_poster_ad_share(2), km_poster_ad_share(10), km_poster_ad_share(0)]);
    require_once __DIR__ . '/../lib/legal.php';
    $termsText = (string) json_encode(km_legal_terms(), JSON_UNESCAPED_UNICODE);
    check_bool('利用規約: 第 7 条に広告の重み(定数から書く)・1 件なら 100%・数だけ記録すること', str_contains($termsText, '第7条(ポスター・広告の表示)')
        && str_contains($termsText, '**どの広告も同じ重み(10%)で選びます**') && str_contains($termsText, '広告が 1 件なら 100%、2 件ならそれぞれ 50% の確率')
        && str_contains($termsText, '記録するのは数だけで、誰が見た・押したかは記録しません') && str_contains($termsText, '第12条(準拠法)') && KM_LEGAL_UPDATED >= '2026-10-21');
    check_bool('プライバシーポリシー: ポスター・広告の数を取得する情報に書いた', str_contains((string) json_encode(km_legal_privacy(), JSON_UNESCAPED_UNICODE), 'ポスター・広告の数(広告を表示した回数・リンクが押された数'));
    $tutorialJs = (string) file_get_contents(__DIR__ . '/../Main/tutorial.js');
    $tutorialEndJs = (string) file_get_contents(__DIR__ . '/../Main/tutorial-end.js');
    check_bool('チュートリアルが終わったら知らせる(最後まで・スキップ・Esc のどれでも finish を通る)', str_contains($tutorialJs, "new CustomEvent('km-tutorial-finished'")
        && str_contains($tutorialJs, "skip.addEventListener('click', finish);") && str_contains($tutorialJs, "if (event.key === 'Escape') finish();"));
    check_bool('広告: 地点なし(isAd)だけ・あれば必ず 1 件を重みで選ぶ・出したら数える・閉じたら終わりの知らせ', str_contains($tutorialEndJs, 'poster.isAd === true')
        && str_contains($tutorialEndJs, "if (list.length > 0) {\n            showAd(pickAd(list), showEnd);") && str_contains($tutorialEndJs, 'let roll = Math.random() * weight * list.length;')
        && !str_contains($tutorialEndJs, 'Math.random() < ') && str_contains($tutorialEndJs, "window.kmPosterStat(poster.id, 'impression')")
        && str_contains($tutorialEndJs, "link.rel = 'noopener noreferrer';") && !str_contains($tutorialEndJs, 'innerHTML'));
    check_bool('公開ページ: 終わりの知らせ(アンケートは URL があるときだけ・スマホの QR)と重みを置く・数える JS を先に読む', str_contains($homeCode, 'data-ad-weight="<?= (int) KM_POSTER_AD_WEIGHT ?>"')
        && str_contains($homeCode, "<?php if (\$kmTutorialEnd['surveyUrl'] !== ''): ?>") && str_contains($homeCode, 'スマホでもマップを見ることができます')
        && strpos($homeCode, "km_public_asset('Main/poster-stats.js')") < strpos($homeCode, "km_public_asset('Main/tutorial-end.js')")
        && str_contains($homeCode, '$kmTestMode !== null || $kmEventCollect !== null || $kmHasPosters'));
    $statMemory = [];
    check('数: リンクは同じセッションで 1 回だけ・広告の表示は 60 秒に 1 回だけ', [true, false, true, true, false, true], [
        km_poster_stat_allowed($statMemory, 3, 'click', 1000), km_poster_stat_allowed($statMemory, 3, 'click', 5000), km_poster_stat_allowed($statMemory, 4, 'click', 5000),
        km_poster_stat_allowed($statMemory, 3, 'impression', 1000), km_poster_stat_allowed($statMemory, 3, 'impression', 1059), km_poster_stat_allowed($statMemory, 3, 'impression', 1060),
    ]);
    $statsLib = (string) file_get_contents(__DIR__ . '/../lib/posters.php');
    $statsApi = (string) file_get_contents(__DIR__ . '/../api/poster-event.php');
    $statsJs = (string) file_get_contents(__DIR__ . '/../Main/poster-stats.js');
    check_bool('数: 表は ポスター × 日 の数だけ(IP・利用者・端末を置かない)・出していないポスター/地点のポスターの表示は数えない', preg_match('/CREATE TABLE IF NOT EXISTS km_poster_stats \(\s*poster_id INT NOT NULL,\s*day DATE NOT NULL,\s*impressions INT NOT NULL DEFAULT 0,\s*clicks INT NOT NULL DEFAULT 0,\s*PRIMARY KEY \(poster_id, day\)\s*\)/', $statsLib) === 1
        && str_contains($statsLib, "|| !km_posters_showing_now(\$pdo)) {") && str_contains($statsLib, "if (\$kind === 'impression' && (string) (\$nodeId ?? '') !== '') {"));
    check_bool('数える口: POST・CSRF・セッションで絞る・数えたかを外に教えない(204)', str_contains($statsApi, "require_method('POST');") && strpos($statsApi, 'km_csrf_verify()') < strpos($statsApi, 'km_poster_stat_record(')
        && str_contains($statsApi, 'km_poster_stat_allowed($memory, $posterId, $kind, time())') && str_contains($statsApi, 'http_response_code(204);') && !str_contains($statsApi, 'REMOTE_ADDR'));
    check_bool('数える JS: 押したことを端末に覚えて 2 度目を送らない・送るのは番号と種類だけ', str_contains($statsJs, "if (kind === 'click' && clickedBefore(id)) return;")
        && str_contains($statsJs, 'body: JSON.stringify({ poster: posterId, kind: kind }),') && str_contains($statsJs, "a[data-km-poster-link]"));
    check_bool('リンクに数える印(地図の詳細・大きく見る幕・広告・一覧のページ)', str_contains($appJsForPosters = (string) file_get_contents(__DIR__ . '/../Main/app.js'), 'data-km-poster-link="${Number(poster.id)}"')
        && str_contains($appJsForPosters, "link.setAttribute('data-km-poster-link', String(Number(poster.id)));") && str_contains($tutorialEndJs, "link.setAttribute('data-km-poster-link', String(poster.id));")
        && substr_count((string) file_get_contents(__DIR__ . '/../posters.php'), 'data-km-poster-link=') === 1);
    check_bool('ポスターを消したら数も消す・管理画面に数を出す', str_contains($statsLib, "km_poster_stats_delete(\$pdo, \$id);") && str_contains((string) file_get_contents(__DIR__ . '/../admin/posters.php'), '$stats = km_poster_stats_totals($pdo);'));
    $tutorialEndError = static function (string $text, string $url): string {
        try {
            // 書き込みの前に断るものだけを試す(DB は使わない)
            if (mb_strlen(trim($text)) > KM_TUTORIAL_END_TEXT_MAX) {
                throw new InvalidArgumentException('long');
            }
            require_once __DIR__ . '/../lib/test-mode.php';
            if (!km_test_survey_url_valid(trim($url))) {
                throw new InvalidArgumentException('url');
            }
            return '';
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    };
    check('終わりの知らせ: 本文は 200 字まで・アンケートは https だけ', ['', 'long', 'url', 'url'], [
        $tutorialEndError('ok', 'https://forms.gle/x'), $tutorialEndError(str_repeat('あ', 201), ''),
        $tutorialEndError('ok', 'http://example.com/'), $tutorialEndError('ok', 'javascript:alert(1)'),
    ]);

    /*
     * ## まとめて取り込む(2026-10-21、利用者の指示「模擬店のページ 1〜58 から自動で広告を作る」「今後も使うので機能に」)
     * 見本の HTML は高専祭のサイトの形(Next.js が組み立てた article)を真似た**作り物**(本物の文は写さない)。
     */
    km_check_heading('poster-import: 番号のページから広告をまとめて作る(2026-10-21)');
    require_once __DIR__ . '/../lib/poster-import.php';
    $importUrlError = static function (string $template, int $number): string {
        try {
            return km_poster_import_url($template, $number);
        } catch (InvalidArgumentException) {
            return 'error';
        }
    };
    check('URL: %N に番号・0 は使わない・%N はちょうど 1 つ・http(s) だけ',
        ['https://example.test/shops/7/detail', 'error', 'error', 'error', 'error'],
        [$importUrlError('https://example.test/shops/%N/detail', 7), $importUrlError('https://example.test/shops/%N/detail', 0),
            $importUrlError('https://example.test/shops/detail', 1), $importUrlError('https://example.test/%N/%N', 1), $importUrlError('ftp://example.test/%N', 1)]);
    check_bool('既定は高専祭の模擬店の 1〜58', KM_POSTER_IMPORT_TEMPLATE_DEFAULT === 'https://kosensai.ichinoseki.ac.jp/shops/%N/detail'
        && KM_POSTER_IMPORT_FIRST_DEFAULT === 1 && KM_POSTER_IMPORT_LAST_DEFAULT === 58 && KM_POSTER_IMPORT_MIN === 1);
    $importFixture = '<!DOCTYPE html><html><body><main><article><p class="x">No. <!-- -->07</p><h2 class="y">試しの 店</h2>'
        . '<div><img alt="a" src="/_next/image?url=%2Fimg%2Fshops%2F07.webp&amp;w=3840&amp;q=75">'
        . '<img alt="b" src="/_next/image?url=%2Fimg%2Fshops%2F07.webp&amp;w=640&amp;q=75"><img alt="c" src="//evil.test/x.png"></div>'
        . '<section><div><h2>コメント</h2><p>一行目' . "\n" . '  二行目  </p></div></section>'
        . '<section><div><h2>お知らせ</h2></div><p>なし</p></section></article></main></body></html>';
    $importParsed = km_poster_import_parse($importFixture, 'https://example.test/shops/7/detail');
    check('読み方: 番号・名前・コメント(改行は残す)・元の画像(縮小の口を外す・同じものは 1 つ・// は取らない)', [
        'number' => 7, 'title' => '試しの 店', 'description' => "一行目\n二行目", 'images' => ['https://example.test/img/shops/07.webp'],
    ], $importParsed);
    $importNoArticle = 'ok';
    try {
        km_poster_import_parse('<html><body><h2>x</h2></body></html>', 'https://example.test/');
    } catch (InvalidArgumentException) {
        $importNoArticle = 'error';
    }
    check('読み方: 店の欄が無いページは断る', 'error', $importNoArticle);
    check('長い名前は上限で切って … を付ける', [5, '…'], [mb_strlen(km_poster_import_clip('あいうえおかきく', 5)), mb_substr(km_poster_import_clip('あいうえおかきく', 5), -1)]);
    $importLib = (string) file_get_contents(__DIR__ . '/../lib/poster-import.php');
    check_bool('取り込み: 取りに行くのは km_poster_fetch_remote だけ(公開のアドレスだけ・リダイレクトの先も確かめる)', substr_count($importLib, 'km_poster_fetch_remote(') === 3
        && !preg_match('/\bcurl_init\(|file_get_contents\(\s*\$/', $importLib));
    check_bool('取り込み: 同じページは作り直さず直す(出し入れ・並び順・地点は今のまま)・404 は飛ばす', str_contains($importLib, "\$existing = km_poster_find_by_link(\$pdo, \$pageUrl);")
        && str_contains($importLib, "'isEnabled' => \$existing['isEnabled'],") && str_contains($importLib, "if (\$exception->getCode() === 404) {")
        && str_contains($importLib, "'nodeId' => '',"));
    check_bool('取りに行く口は応答の番号を code に載せる(404 を見分ける)', str_contains((string) file_get_contents(__DIR__ . '/../lib/posters.php'), "'画像を取りに行けませんでした(応答 ' . \$status . ')。', \$status);"));
    $importApi = (string) file_get_contents(__DIR__ . '/../admin/api/poster-import.php');
    check_bool('口: 管理者だけ・POST・CSRF・1 回に 1 つの番号・セッションを先に閉じる・操作の記録', str_contains($importApi, "require dirname(__DIR__) . '/_inc/guard.php';")
        && str_contains($importApi, "if (!km_csrf_verify()) {") && str_contains($importApi, "is_int(\$input['number'] ?? null)")
        && str_contains($importApi, 'session_write_close();') && str_contains($importApi, "km_admin_log_record('content', 'poster.import'")
        && isset(KM_ADMIN_LOG_ACTION_LABELS['poster.import']));
    $importJs = (string) file_get_contents(__DIR__ . '/../admin/assets/js/poster-import.js');
    $importPage = (string) file_get_contents(__DIR__ . '/../admin/posters.php');
    check_bool('画面: 番号を 1 つずつ・間を空ける・止められる・番号は 1 から', str_contains($importJs, 'await importOne(template, number, enabled)')
        && str_contains($importJs, 'const WAIT_MS = 400;') && str_contains($importJs, 'stopRequested')
        && str_contains($importPage, 'min="<?= KM_POSTER_IMPORT_MIN ?>"') && str_contains($importPage, "assets/js/poster-import.js"));
    check_bool('利用規約: 広告は収益化していない', str_contains($termsText, '広告は収益化していません。'));

    km_check_heading('table-api: テーブル管理の外部接続(2026-10-21)');
    require_once __DIR__ . '/../lib/table-api.php';
    $apiUser = 'kmx_' . str_repeat('a', 16);
    $apiPass = str_repeat('b', 48);
    check('ユーザー名とキー: Basic(PHP が分けたもの)', [$apiUser, $apiPass], km_table_api_credentials(['PHP_AUTH_USER' => $apiUser, 'PHP_AUTH_PW' => $apiPass]));
    check('ユーザー名とキー: Authorization の Basic・Bearer <ID>:<キー>', [[$apiUser, $apiPass], [$apiUser, $apiPass]], [
        km_table_api_credentials(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($apiUser . ':' . $apiPass)]),
        km_table_api_credentials(['HTTP_AUTHORIZATION' => 'Bearer ' . $apiUser . ':' . $apiPass]),
    ]);
    check('ユーザー名とキー: 形の違うもの・無いものは断る(クエリでは受けない)', [null, null, null, null], [
        km_table_api_credentials([]),
        km_table_api_credentials(['PHP_AUTH_USER' => 'root', 'PHP_AUTH_PW' => $apiPass]),
        km_table_api_credentials(['PHP_AUTH_USER' => $apiUser, 'PHP_AUTH_PW' => 'short']),
        km_table_api_credentials(['HTTP_AUTHORIZATION' => 'Bearer ' . $apiUser, 'QUERY_STRING' => 'key=' . $apiPass]),
    ]);
    $apiValuesError = static function (mixed $input): string {
        try {
            km_table_api_values($input);
            return '';
        } catch (InvalidArgumentException $exception) {
            return 'x';
        }
    };
    check('書き込む値: {列: 値} だけ(id・入れ子・配列の形・変な列名は断る)', ['x', 'x', 'x', 'x', 'x', ''], [
        $apiValuesError([]), $apiValuesError(['a', 'b']), $apiValuesError(['id' => 1]), $apiValuesError(['name' => ['x']]),
        $apiValuesError(['na`me' => 'x']), $apiValuesError(['name' => 'x']),
    ]);
    check('書き込む値: null は空(→ NULL)・真偽は 1/0・数は文字に', ['a' => '', 'b' => '1', 'c' => '0', 'd' => '3.5'], km_table_api_values(['a' => null, 'b' => true, 'c' => false, 'd' => 3.5]));
    $apiLib = (string) file_get_contents(__DIR__ . '/../lib/table-api.php');
    $apiEndpointCode = (string) file_get_contents(__DIR__ . '/../api/table.php');
    check_bool('キーは SHA-256 だけを置く・ユーザー名とキーはこちらで作る(乱数)', str_contains($apiLib, "hash('sha256', \$secret)") && str_contains($apiLib, "\$keyId = 'kmx_' . bin2hex(random_bytes(8));")
        && str_contains($apiLib, '$secret = bin2hex(random_bytes(24));') && !preg_match('/CREATE TABLE IF NOT EXISTS km_table_api_keys \([^;]*\bsecret\s+VARCHAR/s', $apiLib));
    check_bool('最小限の権限: 1 つの表だけ・kmt_ だけ・既定は読む + 足す・取り消し/表の削除で止まる', KM_TABLE_API_DEFAULT_PERMISSIONS === ['can_read', 'can_insert']
        && str_contains($apiLib, 'WHERE key_id = ? AND revoked_at IS NULL') && str_contains($apiLib, '!km_table_is_managed_name($table) || !km_table_exists($pdo, $table) || km_table_is_deleted($pdo, $table)')
        && str_contains($apiEndpointCode, "if (!is_string(\$tableParam) || (\$tableParam !== '' && \$tableParam !== \$connection['table'])) {"));
    check_bool('口: 操作ごとに権限を見てから触る・Cookie を使わない・書き込みは記録に残す', strpos($apiEndpointCode, 'if (!$connection[$need]) {') < strpos($apiEndpointCode, 'km_table_rows(')
        && !str_contains($apiEndpointCode, 'km_session_start') && substr_count($apiEndpointCode, "km_admin_log_record('table', 'table.api_write'") === 3
        && isset(KM_ADMIN_LOG_ACTION_LABELS['table.api_create'], KM_ADMIN_LOG_ACTION_LABELS['table.api_revoke'], KM_ADMIN_LOG_ACTION_LABELS['table.api_write']));
    check_bool('管理画面: キーは作った直後の 1 回だけ出す', str_contains((string) file_get_contents(__DIR__ . '/../admin/tables.php'), "unset(\$_SESSION['km_table_api_new']);"));

    km_check_heading('map-floors: 階と平面図の差し替え・追加・削除・zip(2026-10-21)');
    require_once __DIR__ . '/../lib/map-floors.php';
    require_once __DIR__ . '/../lib/zip-read.php';
    // 試しの zip を組む(格納 / deflate・印・CRC・申告の大きさを変えられる)
    $makeZip = static function (array $entries): string {
        $body = '';
        $central = '';
        foreach ($entries as $entry) {
            $data = (string) $entry['data'];
            $method = (int) ($entry['method'] ?? 8);
            $stored = $method === 8 ? (string) gzdeflate($data) : $data;
            $crc = $entry['crc'] ?? (crc32($data) & 0xFFFFFFFF);
            $uncomp = $entry['uncomp'] ?? strlen($data);
            $flags = (int) ($entry['flags'] ?? 0);
            $name = (string) $entry['name'];
            $offset = strlen($body);
            $body .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, $flags, $method, 0, 0, $crc, strlen($stored), $uncomp, strlen($name), 0) . $name . $stored;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, $flags, $method, 0, 0, $crc, strlen($stored), $uncomp, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $body . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), strlen($body), 0);
    };
    $zipTmp = tempnam(sys_get_temp_dir(), 'kmzip');
    $readZip = static function (string $bytes) use ($zipTmp): array|string {
        file_put_contents($zipTmp, $bytes);
        try {
            return km_zip_read_all($zipTmp, 5, 1024 * 1024, 2 * 1024 * 1024);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    };
    $pngHeaderFloor = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 1600, 1200) . "\x08\x02\x00\x00\x00" . pack('N', 0);
    $zipEntries = $readZip($makeZip([
        ['name' => '1F.png', 'data' => $pngHeaderFloor, 'method' => 0],
        ['name' => 'maps/' . mb_convert_encoding('２階.png', 'CP932', 'UTF-8'), 'data' => $pngHeaderFloor . str_repeat("\0", 2000)],
        ['name' => 'maps/', 'data' => '', 'method' => 0],
        ['name' => '__MACOSX/._1F.png', 'data' => 'x', 'method' => 0],
        ['name' => '.DS_Store', 'data' => 'x', 'method' => 0],
        ['name' => '../../evil.png', 'data' => 'abc'],
    ]));
    check('zip: 格納と deflate を読み、フォルダ・__MACOSX・隠しを飛ばし、名前は最後の部分だけ(CP932 も読む)', ['1F.png', '２階.png', 'evil.png'], is_array($zipEntries) ? array_column($zipEntries, 'name') : $zipEntries);
    check_bool('zip: 中身はそのまま取り出す', is_array($zipEntries) && ($zipEntries[1]['bytes'] ?? '') === $pngHeaderFloor . str_repeat("\0", 2000));
    check_bool('zip: パスワード付き・CRC 違い・数の上限・大きすぎ・膨らむもの(zip 爆弾)は断る',
        is_string($readZip($makeZip([['name' => 'a.png', 'data' => 'x', 'flags' => 1]])))
        && is_string($readZip($makeZip([['name' => 'a.png', 'data' => 'abc', 'crc' => 1]])))
        && is_string($readZip($makeZip(array_map(static fn (int $i): array => ['name' => $i . '.png', 'data' => 'x'], range(1, 6)))))
        && is_string($readZip($makeZip([['name' => 'big.png', 'data' => str_repeat('a', 1024 * 1024 + 1)]])))
        && is_string($readZip($makeZip([['name' => 'bomb.png', 'data' => str_repeat('a', 3 * 1024 * 1024), 'uncomp' => 10]])))
        && is_string($readZip('not a zip')));
    @unlink($zipTmp);
    $floorList = [
        ['id' => 'outside', 'label' => '外'], ['id' => '1', 'label' => '1F'], ['id' => '2', 'label' => '2F'],
        ['id' => 'bldg_library_1f', 'label' => '図書館 1F'], ['id' => 'b1', 'label' => '地下 1F'],
    ];
    $matched = array_map(static fn (string $name): ?string => km_map_floor_match($name, $floorList),
        ['１階.png', '1F.PNG', '2階.jpg', '外全体図.png', '外.webp', 'bldg_library_1f.png', '図書館1F.png', '地下１Ｆ.png', 'b1.png', 'unknown.png']);
    check('zip: 名前から階を当てる(id・名前・元の画像の名前。全角・「階」も)', ['1', '1', '2', 'outside', 'outside', 'bldg_library_1f', 'bldg_library_1f', 'b1', 'b1', null], $matched);
    check_bool('階の id は小文字・数字・_ で 16 字まで', km_map_floor_id_valid('6') && km_map_floor_id_valid('bldg_new_1f') && !km_map_floor_id_valid('../x')
        && !km_map_floor_id_valid('A1') && !km_map_floor_id_valid('') && !km_map_floor_id_valid(str_repeat('a', 17)) && !km_map_floor_id_valid('_x'));
    check_bool('縦横の比: 同じなら知らせない・2% を超えて違えば知らせる', !km_map_floor_aspect_differs(3200, 2400, 1600, 1200)
        && km_map_floor_aspect_differs(1600, 1600, 1600, 1200) && !km_map_floor_aspect_differs(0, 0, 1600, 1200));
    check_bool('差し替えた画像のパス: 自分で採番した形だけ(.. や SVG を通さない)', km_map_floor_upload_name_valid('floor_' . str_repeat('0', 32) . '.png')
        && !km_map_floor_upload_name_valid('floor_' . str_repeat('0', 32) . '.svg') && km_map_floor_image_file('upload:../config/db.local.php') === null
        && km_map_floor_image_file('../config/db.local.php') === null && km_map_floor_image_file('upload:floor_' . str_repeat('0', 32) . '.png') === null);
    check_bool('組み込みの画像は Main/ の下から引ける', km_map_floor_image_file('Picture/１階.png') !== null && km_map_floor_image_file('Picture/bldg/bldg_library_1f.png') !== null);
    check_bool('組み込みの階: 外・1〜5F・建物の階を知っている(元の画像に戻す・消した階を戻す)', count(array_filter(
        array_merge(array_column(KM_MAP_CAMPUS_FLOORS, 'id'), array_column(KM_BUILDING_FLOORS, 'id')),
        static fn (string $id): bool => km_map_floor_builtin($id) !== null && km_map_floor_image_file(km_map_floor_builtin($id)['image']) !== null
    )) === count(KM_MAP_CAMPUS_FLOORS) + count(KM_BUILDING_FLOORS) && km_map_floor_builtin('6') === null);
    $floorValidateError = static function (string $label, string $sort, string $keywords): string {
        try {
            km_map_floor_validate($label, $sort, $keywords);
            return '';
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    };
    check_bool('名前は要る・並び順は整数', $floorValidateError(' ', '1', '') !== '' && $floorValidateError('6F', '1.5', '') !== '' && $floorValidateError(str_repeat('あ', 33), '1', '') !== '' && $floorValidateError('6F', '6', '') === '');
    check('建物の手がかりは「、」・空白・カンマで分ける', ['図書館', 'メディアセンター', 'Lib'], km_map_floor_keywords('図書館、メディアセンター, Lib 図書館'));
    require_once __DIR__ . '/../lib/map-data.php';
    check('「中へ」に出す建物の階: いま在る組み込み(DB の名前)と、足した建物の中', [['bldg_gym1', '体育館(改)'], ['bldg_new', '新棟']], array_map(
        static fn (array $f): array => [$f['id'], $f['label']],
        km_map_data_building_floors(
            [['id' => '1', 'label' => '1F'], ['id' => 'bldg_gym1', 'label' => '体育館(改)'], ['id' => 'bldg_new', 'label' => '新棟'], ['id' => '6', 'label' => '6F']],
            ['bldg_new' => ['kind' => 'building', 'keywords' => ['新棟']], '6' => ['kind' => 'campus', 'keywords' => []]]
        )
    ));
    check('階の種類: 組み込みの建物・足した建物の中は building、それ以外は campus', ['building', 'building', 'campus', 'campus'], [
        km_map_floor_kind('bldg_gym1', []), km_map_floor_kind('bldg_new', ['bldg_new' => ['kind' => 'building']]),
        km_map_floor_kind('6', ['6' => ['kind' => 'campus']]), km_map_floor_kind('1', []),
    ]);
    $floorImageApi = (string) file_get_contents(__DIR__ . '/../api/floor-image.php');
    // 2026-10-30: 色分けした床(?area=1)も同じ口から。塗りのファイルは km_area_paint_file(名前の形を確かめる)で引く
    check_bool('見取り図の口: 差し替えた画像も組み込みも km_map_floor_image_file で引く(錠の確かめの後)', str_contains($floorImageApi, "\$path = str_starts_with((string) \$svgPath, 'area:') ? (\$areaFile ?? null) : km_map_floor_image_file((string) \$svgPath);")
        && strpos($floorImageApi, 'km_map_floor_image_file((string) $svgPath)') > strpos($floorImageApi, 'if (!$viewUnlocked) {')
        && strpos($floorImageApi, "if ((\$_GET['area'] ?? '') === '1') {") > strpos($floorImageApi, 'if (!$viewUnlocked) {'));

    // 場所ごとの色分け(2026-10-30、lib/area-paint.php)
    require_once __DIR__ . '/../lib/area-paint.php';
    $paintRecord = ['file' => 'area_0123456789ab_0123456789abcdef.png', 'source' => '/api/floor-image.php?floor=1&v=5', 'at' => '2026-10-30 10:00:00'];
    check('色分け: 塗ったときと同じ床の画像なら URL・差し替えていれば出さない・名前の形が違えば出さない', [
        '/api/floor-image.php?floor=1&area=1&v=0123456789abcdef', null, null, null,
    ], [
        km_area_paint_url('1', '/api/floor-image.php?floor=1&v=5', $paintRecord),
        km_area_paint_url('1', '/api/floor-image.php?floor=1&v=6', $paintRecord),
        km_area_paint_url('1', '/api/floor-image.php?floor=1&v=5', null),
        km_area_paint_url('1', '/api/floor-image.php?floor=1&v=5', ['file' => '../etc/passwd', 'source' => '/api/floor-image.php?floor=1&v=5', 'at' => '']),
    ]);
    $paintInputs = km_area_paint_inputs([
        'floors' => [['id' => '1', 'label' => '1F', 'svgPath' => '/api/floor-image.php?floor=1&v=5', 'coordWidth' => 1600, 'coordHeight' => 1200],
                     ['id' => 'outside', 'label' => '外', 'svgPath' => '/api/floor-image.php?floor=outside', 'coordWidth' => null, 'coordHeight' => null]],
        'nodes' => [
            'aaaaaaaabbbbccccddddeeeeeeeeeeee' => ['floor' => '1', 'type' => 'room', 'x' => 10.0, 'y' => 20.0],
            'h1' => ['floor' => '1', 'type' => 'room', 'x' => 30.0, 'y' => 40.0, 'hidden' => true],
            'w1' => ['floor' => '1', 'type' => 'wall', 'x' => 0.0, 'y' => 0.0],
            'w2' => ['floor' => '1', 'type' => 'wall', 'x' => 5.0, 'y' => 0.0],
            'p' => ['floor' => '1', 'type' => 'poster', 'x' => 1.0, 'y' => 1.0],
        ],
        'edges' => [['source' => 'w1', 'target' => 'w2', 'wall' => true], ['source' => 'w1', 'target' => 'h1', 'wall' => false]],
    ], [
        'overrides' => ['AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' => ['kind' => 'corridor', 'color' => '#A8E0C8']],
        'markers' => [['uuid' => 'm1', 'floor' => '1F', 'x' => 50.0, 'y' => 1100.0, 'kind' => null, 'color' => '#D0B0E8']],
    ], ['1' => $paintRecord]);
    check('色分けの材料: 種類で絞る・隠した地点は押せない・手の指定は uuid の形をそろえて当てる・印はアプリの座標から直す・壁は両端が壁の線だけ', [
        ['aaaaaaaabbbbccccddddeeeeeeeeeeee', 'room', true, 'corridor', 0xA8E0C8],
        ['h1', 'room', false, null, null],
        ['m1', 'area', false, null, 0xD0B0E8, 100.0],
        [[0.0, 0.0, 5.0, 0.0]],
        [false, true, true],
    ], [
        [$paintInputs[0]['seeds'][0]['uuid'], $paintInputs[0]['seeds'][0]['type'], $paintInputs[0]['seeds'][0]['tappable'], $paintInputs[0]['seeds'][0]['kind'], $paintInputs[0]['seeds'][0]['color']],
        [$paintInputs[0]['seeds'][1]['uuid'], $paintInputs[0]['seeds'][1]['type'], $paintInputs[0]['seeds'][1]['tappable'], $paintInputs[0]['seeds'][1]['kind'], $paintInputs[0]['seeds'][1]['color']],
        [$paintInputs[0]['seeds'][2]['uuid'], $paintInputs[0]['seeds'][2]['type'], $paintInputs[0]['seeds'][2]['tappable'], $paintInputs[0]['seeds'][2]['kind'], $paintInputs[0]['seeds'][2]['color'], $paintInputs[0]['seeds'][2]['y']],
        $paintInputs[0]['walls'],
        [$paintInputs[0]['outdoor'], $paintInputs[1]['outdoor'], $paintInputs[0]['painted']['current']],
    ]);
    $areaApp = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    check_bool('色分け: 公開の地図は塗った床があれば出す(表示調整で切れる・切り替えたら読み直す)・来場者の端末では塗らない',
        str_contains($areaApp, 'return (areaColorsOn && floorAreaImages[floor]) || floorImages[floor];') && str_contains($areaApp, 'const url = floorImageUrl(floor);')
        && str_contains($areaApp, 'if ((tuning.areaColors !== false) !== areaColorsOn) {') && !str_contains($areaApp, 'segmentAreas(')
        && !str_contains((string) file_get_contents(__DIR__ . '/../index.php'), 'area-segmentation.js'));
    check_bool('色分け: 地図データは階ごとに塗りの URL を持つ・置くときは PNG だけ・床が変わっていたら断る', str_contains($mapDataLib, "\$floor['areaPath'] = km_area_paint_url(")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/area-paint.php'), "if (\$checked['ext'] !== 'png') {")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/area-paint.php'), 'if ($source !== km_map_floor_image_endpoint($floorId, $svgPath)) {'));
    check_bool('地図データ: 階の無い地点と、その線を出さない', str_contains($mapDataLib = (string) file_get_contents(__DIR__ . '/../lib/map-data.php'), "if (!isset(\$floorIds[(string) \$node['floor']])) {")
        && str_contains($mapDataLib, "isset(\$nodesById[(string) \$edge['source']], \$nodesById[(string) \$edge['target']])"));
    check_bool('公開ページ: 右の階の並びは地図データの校舎の階から作る(無ければ組み込みの 6 つ)', str_contains($homeCode, "if ((\$railFloor['kind'] ?? 'campus') === 'campus') {")
        && str_contains($homeCode, 'KM_MAP_CAMPUS_FLOORS') && !str_contains($homeCode, '<button class="floor-btn" data-floor="2"'));
    $floorsPage = (string) file_get_contents(__DIR__ . '/../admin/map-floors.php');
    check_bool('管理画面: 1F は消させない・地点のある階は印が無ければ消さない', str_contains((string) file_get_contents(__DIR__ . '/../lib/map-floors.php'), "if (\$floorId === KM_MAP_FLOOR_INITIAL) {")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/map-floors.php'), "if (\$floor['nodeCount'] > 0 && !\$withNodes) {")
        && str_contains($floorsPage, "km_map_floor_delete(\$pdo, \$floorId, isset(\$_POST['with_nodes']))"));
    check_bool('zip の小さな画像の口: 置き場はセッションが決める(URL で選ばせない)', str_contains((string) file_get_contents(__DIR__ . '/../admin/api/floor-staging-image.php'), "\$staged = \$_SESSION['km_floor_zip'] ?? null;")
        && str_contains($floorsPage, "const KM_FLOOR_ZIP_SESSION_KEY = 'km_floor_zip';"));
    check_bool('監査ログ: 階の操作に文言がある', isset(KM_ADMIN_LOG_ACTION_LABELS['map.floor_image'], KM_ADMIN_LOG_ACTION_LABELS['map.floor_update'], KM_ADMIN_LOG_ACTION_LABELS['map.floor_add'], KM_ADMIN_LOG_ACTION_LABELS['map.floor_delete'], KM_ADMIN_LOG_ACTION_LABELS['map.floor_zip']));

    /*
     * PHP の RCE(CVE-2026-109618 / 109619)のつなぎ「multipart を PHP へ渡さない」(2026-10-21〜22)は、
     * PHP 8.4.27 を当てて確かめたので 10-22 に外した(nginx の map と 2 つの if。docs/05-containers.ipynb §10)。
     * 残すのは、そのとき作ったメンテナンス中の画面(下)と、php.ini の session.upload_progress.enabled = 0(上の 99-limits.ini の値)。
     * nginx の雛形はリポジトリにしか無い(配備先の src だけでは読めない)ので、そのときは飛ばす
     */
    $shieldRepoRoot = km_check_repo_root();
    $shieldNginx = $shieldRepoRoot === null ? false : @file_get_contents($shieldRepoRoot . '/nginx/default.conf.template');
    if ($shieldRepoRoot === null) {
        check_skip('メンテナンス中の画面(nginx)', '手元の作業ツリーで確認する。配備先に nginx/ は出ない');
    } elseif (is_string($shieldNginx)) {
        $shieldCode = (string) preg_replace('/^\s*#.*$/m', '', $shieldNginx);
        $shieldServers = [];
        if (preg_match('/^server \{\n    listen 443 ssl;.*?\n\}\n/ms', $shieldCode, $shieldMain) === 1) {
            $shieldServers['443 の Web アプリ'] = $shieldMain[0];
        }

        /*
         * ## メンテナンス中の画面(2026-10-21、利用者の指示。nginx/km/maintenance.conf)
         * web や Logto が居ないとき(nginx が出す 502)だけ、nginx が自分で 503 の画面を返す。
         * 相手が自分で返す 5xx・待ち切れの 504・回数の制限(429)では出さない。
         */
        km_check_heading('maintenance: web・Logto が居ないときはメンテナンス中の画面(2026-10-21)');
        $maintConf = (string) @file_get_contents($shieldRepoRoot . '/nginx/km/maintenance.conf');
        $maintCode = (string) preg_replace('/^\s*#.*$/m', '', $maintConf);
        $maintHtml = (string) @file_get_contents($shieldRepoRoot . '/nginx/km/maintenance.html');
        check_bool('nginx が出す 502 だけを 503 の画面にする(504 は含めない・相手の応答は横取りしない)', str_contains($maintCode, 'error_page 502 =503 /__km_maintenance;')
            && !str_contains($maintCode, '504') && !str_contains($shieldCode . $maintCode, 'proxy_intercept_errors'));
        check_bool('画面の口は内部だけ・503 にも見出しを付ける(always)', str_contains($maintCode, "location = /__km_maintenance {\n    internal;\n    alias /etc/nginx/km/maintenance.html;")
            && str_contains($maintCode, "add_header Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline';") && substr_count($maintCode, ' always;') >= 6);
        $mainInclude = isset($shieldServers['443 の Web アプリ']) && str_contains($shieldServers['443 の Web アプリ'], "    include /etc/nginx/km/maintenance.conf;\n");
        $authInclude = preg_match('/^server \{\n    listen 443 ssl;\n    listen \[::\]:443 ssl;\n    http2 on;\n    server_name \$\{KM_AUTH_DOMAIN\};.*?\n\}\n/ms', $shieldCode, $maintAuth) === 1
            && str_contains($maintAuth[0], "    include /etc/nginx/km/maintenance.conf;\n");
        check_bool('443 の Web アプリと Logto の入口(auth)が読み込む', $mainInclude && $authInclude && substr_count($shieldCode, 'include /etc/nginx/km/maintenance.conf;') === 2);
        check_bool('管理用のホストでも公開側へ飛ばさない($km_leave_admin_host の例外)', str_contains($shieldCode, '|__km_maintenance$)"  0;'));
        check_bool('画面は外のものを読まず、JS を持たない', $maintHtml !== '' && !preg_match('/<script|https?:\/\/|<link|<img|@import/i', $maintHtml)
            && str_contains($maintHtml, 'メンテナンス中'));
        $maintDeploy = (string) @file_get_contents($shieldRepoRoot . '/scripts/deploy-to-host.ps1');
        check_bool('配備の荷に必ず入れる(conf が無いと nginx が起動しない)', str_contains($maintDeploy, "'./nginx/km/maintenance.conf'") && str_contains($maintDeploy, "'./nginx/km/maintenance.html'"));
        check_bool('PHP の RCE のつなぎ(multipart を 403)は外した(PHP 8.4.27。10-22)', !str_contains($shieldCode, 'km_php_shield_multipart'));
    }

    km_check_heading('test-mode: テスト用アカウント(展示の PC。2026-10-15)');
    $testLib = (string) file_get_contents(__DIR__ . '/../lib/test-mode.php');
    $testApi = (string) file_get_contents(__DIR__ . '/../api/test-events.php');
    $testJs = (string) file_get_contents(__DIR__ . '/../Main/test-mode.js');
    $now = 1_760_000_000_000;
    $parsed = km_test_events_parse([
        'visit' => '0123456789abcdef',
        'events' => [
            ['kind' => 'search', 't' => $now - 1000, 'field' => 'goal', 'text' => "  図書館\n ", 'node' => 'abc123'],
            ['kind' => 'route', 't' => $now, 'node' => 'a', 'node2' => 'b', 'text' => 'vertical=any'],
            ['kind' => 'evil', 'text' => 'x'],
            ['kind' => 'detail', 'node' => '../etc/passwd'],
            ['kind' => 'floor', 'floor' => 'outside', 't' => $now + 999_999_999],
        ],
    ], $now);
    check('種類の分からない記録は捨てる', 4, $parsed === null ? -1 : count($parsed['rows']));
    check('語は前後の空白と改行を落とす', '図書館', $parsed['rows'][0]['text'] ?? null);
    check('欄は start / goal だけ', 'goal', $parsed['rows'][0]['field'] ?? null);
    check('地点の id は英数字だけ(パスのような値は捨てる)', null, ($parsed['rows'][2] ?? ['node' => 'x'])['node']);
    check('未来の時刻はサーバーの今にする', gmdate('Y-m-d H:i:s', intdiv($now, 1000)) . '.000', $parsed['rows'][3]['at'] ?? null);
    check('visit の形が違えば丸ごと断る', null, km_test_events_parse(['visit' => 'xyz', 'events' => []], $now));
    $many = km_test_events_parse(['visit' => '0123456789abcdef', 'events' => array_fill(0, 80, ['kind' => 'open'])], $now);
    check('1 回に受けるのは 50 件まで', KM_TEST_EVENTS_PER_REQUEST, count($many['rows'] ?? []));
    check_bool('アンケートは https だけ', km_test_survey_url_valid('https://forms.gle/abc') && km_test_survey_url_valid('')
        && !km_test_survey_url_valid('http://example.com') && !km_test_survey_url_valid('javascript:alert(1)') && !km_test_survey_url_valid('https://a.example/"><x'));
    check('操作方法は空の行を飛ばす', ['一', '二'], km_test_guide_lines("一\n\n  二  \r\n"));
    check_bool('印を確かめられなければ付いていない扱い', str_contains($testLib, "error_log('km_test_account_is_marked failed (treated as not marked): '") && str_contains($testLib, 'return false;'));
    // 公開ページ: 印のあるときだけ JS と札を出す。開くたびに印を立て直す
    check_bool('開くたびに印を外してから確かめ直す', strpos($index, 'unset($_SESSION[KM_TEST_MODE_SESSION_KEY]);') !== false
        && strpos($index, 'unset($_SESSION[KM_TEST_MODE_SESSION_KEY]);') < (int) strpos($index, 'km_test_account_is_marked($testPdo'));
    check_bool('test-mode.js は印のあるときだけ読む', preg_match("/if \(\\\$kmTestMode !== null \|\| \\\$kmEventCollect !== null\): \?>\s*(<\?php[^?]*\?>\s*)?<script defer src=\"<\?= km_home_e\(km_public_asset\('Main\/test-mode\.js'\)\) \?>\"><\/script>/", $index) === 1);
    check_bool('test-mode.js は app.js より先', (int) strpos($index, "Main/test-mode.js')") < (int) strpos($index, '<script defer src="<?= km_home_e(km_public_asset(\'Main/app.js\')) ?>"></script>'));
    check_bool('札と操作方法は小さくできる・始めからがある', substr_count($index, 'data-km-test-min') === 2 && str_contains($index, 'id="km-test-restart"'));
    check_bool('app.js は kmTestLog が在るときだけ送る', str_contains($appJs, "if (typeof window.kmTestLog === 'function') window.kmTestLog(kind, data);") && !str_contains($appJs, "/api/test-events.php"));
    foreach (['search', 'category', 'pick', 'route', 'detail', 'floor'] as $kind) {
        check_bool("app.js が記録する: {$kind}", str_contains($appJs, "testLog('{$kind}'"));
    }
    // API: セッションの印・期限・CSRF の順に見てから書く
    $markAt = strpos($testApi, '$sub = km_test_collect_subject($_SESSION, time());');
    check_bool('API は印と CSRF を見てから書く', $markAt !== false && $markAt < (int) strpos($testApi, 'if (!km_csrf_verify())')
        && (int) strpos($testApi, 'if (!km_csrf_verify())') < (int) strpos($testApi, 'km_test_events_insert('));
    $nowS = 1_760_000_000;
    check('送り主: テスト用の印が先', 'abc', km_test_collect_subject([KM_TEST_MODE_SESSION_KEY => ['sub' => 'abc', 'until' => $nowS + 1], KM_EVENT_COLLECT_SESSION_KEY => ['subject' => 'event:3', 'until' => $nowS + 1]], $nowS));
    check('送り主: イベント中の来場者', 'event:3', km_test_collect_subject([KM_EVENT_COLLECT_SESSION_KEY => ['subject' => 'event:3', 'until' => $nowS + 1]], $nowS));
    check('API は期限切れの印を断る', null, km_test_collect_subject([KM_TEST_MODE_SESSION_KEY => ['sub' => 'abc', 'until' => $nowS - 1], KM_EVENT_COLLECT_SESSION_KEY => ['subject' => 'event:3', 'until' => $nowS - 1]], $nowS));
    check('イベントの印は event: で始まるものだけ', null, km_test_collect_subject([KM_EVENT_COLLECT_SESSION_KEY => ['subject' => 'someone', 'until' => $nowS + 1]], $nowS));
    check('イベントの記録に誰かは書かない(番号だけ・並べ替える)', 'event:2-5', km_event_collect_subject([5, '2', 0]));
    check_bool('Logto の ID と event: は取り違えない', !km_test_valid_sub('event:1'));
    check_bool('絞り込みは決まった文字列だけ', km_test_source_where("x' OR 1=1") === '1 = 1' && km_test_source_where('event') === "sub LIKE 'event:%'");
    check('自動の始めからの既定は 3 分', 3, KM_TEST_IDLE_DEFAULT);
    // イベント中の許可: チェック済みで出す・決めるまで送らない・外したら溜めた分も捨てる
    check_bool('許可はチェック済みで出す', str_contains($index, '<input type="checkbox" id="km-collect-consent" checked>'));
    check_bool('イベントの記録はテスト用アカウントのときは出さない', str_contains($index, 'if ($kmEvent !== null && $kmTestMode === null) {'));
    check_bool('開くたびにイベントの印も立て直す', strpos($index, 'unset($_SESSION[KM_EVENT_COLLECT_SESSION_KEY]);') !== false
        && strpos($index, 'unset($_SESSION[KM_EVENT_COLLECT_SESSION_KEY]);') < (int) strpos($index, 'km_event_collect_enabled(km_db())'));
    check_bool('決めるまでは送らない', str_contains($testJs, "if (consent !== 'yes') return;   // 決めるまでは溜めるだけ"));
    check_bool('許可しないなら記録もしない・溜めた分も捨てる', str_contains($testJs, "if (consent === 'no') return;") && str_contains($testJs, 'queue = [];   // 許可しないなら'));
    check_bool('タブを移っただけでは許可したことにしない', preg_match("/visibilitychange', function \(\) \{\s*if \(document\.visibilityState === 'hidden'\) flush\(true\);/", $testJs) === 1);
    // 自動の始めから: 触られていない画面は戻さない・戻す前に知らせる
    check_bool('触られるまで自動で戻さない', str_contains($testJs, '// 触られるまでは数えない(arm しない)') && str_contains($testJs, 'const IDLE_WARNING_SECONDS = 15;'));
    check_bool('戻す前の知らせに「このまま使う」', str_contains($testJs, "keep.textContent = 'このまま使う';") && str_contains($testJs, "restart('idle');"));
    check_bool('API は 64KB までしか読まない', str_contains($testApi, "file_get_contents('php://input', false, null, 0, 65536)"));
    check_bool('JS はトークンを X-KM-CSRF で送る', str_contains($testJs, "'X-KM-CSRF': token"));
    check_bool('始めからで記録を区切り、来場者の覚えを消す', str_contains($testJs, 'storageSet(window.sessionStorage, VISIT_KEY, newVisitId());')
        && str_contains($testJs, "'km-map-tuning-v1'") && str_contains($testJs, "window.location.replace('/');"));
    $repoRootForTest = km_check_repo_root();
    if ($repoRootForTest !== null) {
        check_bool('nginx: 記録の入口は本文 64KB・回数を絞る', preg_match('/location = \/api\/test-events\.php \{\s*client_max_body_size 64k;\s*limit_req zone=km_api/', (string) @file_get_contents($repoRootForTest . '/nginx/default.conf.template')) === 1);
    } else {
        check_skip('nginx: 記録の入口', '手元の作業ツリーで');
    }
    check_bool('CSV は式として読ませない', str_contains($testLib, "preg_replace('/^([=+\\-@])/', \"'\$1\""));
    check_bool('管理画面に CSRF と記録の消去の確認', str_contains((string) file_get_contents(__DIR__ . '/../admin/test-mode.php'), "!km_csrf_verify()")
        && str_contains((string) file_get_contents(__DIR__ . '/../admin/test-mode.php'), 'data-km-confirm="テスト用アカウントの記録を全部消します。'));

    km_check_heading('layout: ラベルの重なり(引き出し線 2026-10-14 / 建物の中も隠す 2026-10-30)');
    // 2026-10-30、利用者の判断「B」: 建物の中でも置けない札は隠す。選んだ場所の札(keep)だけは隠さない
    check_bool('建物の中も置けない札は隠す', str_contains($appJs, 'hideWhenCrowded: true,') && !str_contains($appJs, 'hideWhenCrowded: outdoors'));
    check_bool('選んだ場所の札は隠さない(keep)', str_contains($appJs, 'keep: !!(selectedAt && marker && marker.getLatLng && marker.getLatLng().equals(selectedAt)),')
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/label-layout.js'), 'if (!hide || item.keep) {'));
    check_bool('選び直したら札を並べ直す', substr_count($appJs, "scheduleDeclutter(true);\n    }") >= 1 && str_contains($appJs, "selectionRing = null;\n        scheduleDeclutter(true);"));
    // 上下のずらしは Leaflet の margin-top(-6px)に足す。dy だけ書くと 6px 下へ落ちて隣と重なっていた(試し場で 1 階の重なり 2〜8 組 → 0)
    km_check_heading('map: 拡大したら地図以外を隠す(2026-10-30、振り返りのフェーズ 2 の 3)');
    $chromeCss = (string) file_get_contents(__DIR__ . '/../Main/styles.css');
    check_bool('全体の 4 倍(2 段)より拡大したら隠す・基準は階の全体が入る倍率', str_contains($appJs, 'const KM_CHROME_HIDE_STEPS = 2;') && str_contains($appJs, 'floorFitZoom = fitZoom;')
        && str_contains($appJs, 'const deep = window.map.getZoom() - floorFitZoom >= KM_CHROME_HIDE_STEPS;'));
    check_bool('隠すのは利用者が自分で拡大したときだけ(検索の候補で飛んだときは隠さない)', str_contains($appJs, 'Date.now() - mapInputAt < KM_CHROME_INPUT_MS && !chromeInUse()'));
    check_bool('縮める・地図をたたく・Tab/Esc で戻す', str_contains($appJs, "if (!deep) {\n            setChromeHidden(false);")
        && str_contains($appJs, "window.map.on('click', function () { setChromeHidden(false); });") && str_contains($appJs, "if (event.key === 'Tab' || event.key === 'Escape') setChromeHidden(false);"));
    check_bool('隠すときは押せなくする(visibility も落とす)。階・経路の帯・ズームは残す', preg_match('/body\.km-chrome-hidden \.km-action-bar \{\s*opacity: 0;\s*visibility: hidden;\s*pointer-events: none;/', $chromeCss) === 1
        && !str_contains($chromeCss, 'body.km-chrome-hidden .floor-nav') && !str_contains($chromeCss, 'body.km-chrome-hidden .km-route-bar'));

    km_check_heading('map: 床の画像を可逆の WebP で配る(2026-10-30、振り返りのフェーズ 2 の 5。lib/floor-webp.php)');
    require_once __DIR__ . '/../lib/floor-webp.php';
    check_bool('WebP: Accept に image/webp があるときだけ・q=0 は断り', km_floor_webp_accepted('image/avif,image/webp,image/apng,*/*;q=0.8')
        && !km_floor_webp_accepted('image/png,*/*') && !km_floor_webp_accepted('image/webp;q=0') && km_floor_webp_accepted('IMAGE/WEBP;q=0.5'));
    $floorPngForWebp = __DIR__ . '/../Main/Picture/１階.png';
    if (function_exists('imagewebp') && is_file($floorPngForWebp)) {
        $webpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'km-check-webp-' . bin2hex(random_bytes(4));
        $webpFile = km_floor_webp_file($floorPngForWebp, $webpDir);
        $pngImage = @imagecreatefrompng($floorPngForWebp);
        $webpImage = $webpFile !== null ? @imagecreatefromwebp($webpFile) : false;
        $sameSize = $pngImage !== false && $webpImage !== false && imagesx($pngImage) === imagesx($webpImage) && imagesy($pngImage) === imagesy($webpImage);
        // 可逆: 何か所か画素を比べる(パレットの PNG は真色に直して比べる)
        $samePixels = $sameSize;
        if ($sameSize) {
            imagepalettetotruecolor($pngImage);
            for ($i = 0; $i < 400 && $samePixels; $i++) {
                $px = ($i * 397) % imagesx($pngImage);
                $py = ($i * 131) % imagesy($pngImage);
                $samePixels = imagecolorsforindex($pngImage, imagecolorat($pngImage, $px, $py)) == imagecolorsforindex($webpImage, imagecolorat($webpImage, $px, $py));
            }
        }
        check_bool('WebP: 1 階の床は小さくなり、画素は同じ(可逆)', $webpFile !== null && filesize($webpFile) < filesize($floorPngForWebp) && $samePixels);
        check_bool('WebP: 2 回目は作った写しを使う', $webpFile !== null && km_floor_webp_file($floorPngForWebp, $webpDir) === $webpFile);
        foreach (glob($webpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($webpDir);
    } else {
        check_skip('WebP: 1 階の床は小さくなり、画素は同じ(可逆)', 'GD に WebP が無い');
    }
    // 本番の PHP には GD が無いので、組み込みの床は手元で作った横の写しを配る(src/scripts/make-floor-webp.php)
    $staleWebp = [];
    foreach (array_merge(glob(__DIR__ . '/../Main/Picture/*.png') ?: [], glob(__DIR__ . '/../Main/Picture/bldg/*.png') ?: []) as $builtinPng) {
        if (km_floor_webp_sibling($builtinPng) === null) {
            $staleWebp[] = basename($builtinPng);
        }
    }
    check_bool('WebP: 組み込みの床すべてに PNG より新しい写し(無ければ php src/scripts/make-floor-webp.php)' . ($staleWebp === [] ? '' : ' 足りない: ' . implode('・', $staleWebp)), $staleWebp === []);
    check_bool('WebP: 横の写しを先に使い、無ければ GD で作る', str_contains((string) file_get_contents(__DIR__ . '/../lib/floor-webp.php'), 'return km_floor_webp_sibling($pngPath) ?? km_floor_webp_file($pngPath);')
        && str_contains((string) file_get_contents(__DIR__ . '/../api/floor-image.php'), '$webp = km_floor_webp_for($path);'));
    check_bool('WebP: PNG ではないもの・無いファイルは null(PNG のまま配る)', km_floor_webp_file(__DIR__ . '/../index.php') === null && km_floor_webp_file(__DIR__ . '/nope.png') === null);
    $floorImageForWebp = (string) file_get_contents(__DIR__ . '/../api/floor-image.php');
    check_bool('WebP: 錠を見た後で・PNG のときだけ写しに替え、Vary: Accept と別の ETag', ($wp = strpos($floorImageForWebp, "if (\$contentType === 'image/png' && km_floor_webp_accepted(")) !== false
        && $wp > (int) strpos($floorImageForWebp, 'if (!$viewUnlocked) {') && str_contains($floorImageForWebp, "header('Vary: Accept');")
        && str_contains($floorImageForWebp, "(string) \$modified . \$variant), 0, 32)"));

    km_check_heading('ポスター: 一覧・候補は縮小版(2026-10-30、振り返りのフェーズ 2 の 5。lib/image-thumb.php)');
    require_once __DIR__ . '/../lib/image-thumb.php';
    check_bool('縮小: 幅は決まった値だけ(好きな幅で縮小を走らせない)', km_image_thumb_width('640') === 640 && km_image_thumb_width('641') === 0
        && km_image_thumb_width(['640']) === 0 && km_image_thumb_width('0640') === 0 && km_image_thumb_width(null) === 0);
    if (function_exists('imagewebp') && function_exists('imagejpeg')) {
        $thumbDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'km-check-thumb-' . bin2hex(random_bytes(4));
        @mkdir($thumbDir, 0700, true);
        $bigJpeg = $thumbDir . DIRECTORY_SEPARATOR . 'big.jpg';
        $canvas = imagecreatetruecolor(1600, 900);
        for ($i = 0; $i < 60; $i++) {
            imagefilledrectangle($canvas, $i * 26, 0, $i * 26 + 13, 900, imagecolorallocate($canvas, ($i * 37) % 256, ($i * 91) % 256, ($i * 53) % 256));
        }
        imagejpeg($canvas, $bigJpeg, 92);
        imagedestroy($canvas);
        $thumbFile = km_image_thumb_file($bigJpeg, 640, $thumbDir);
        $thumbSize = $thumbFile !== null ? getimagesize($thumbFile) : false;
        check_bool('縮小: 1600×900 の JPEG → 幅 640・縦横比そのままの WebP で小さく', $thumbSize !== false && $thumbSize[0] === 640 && $thumbSize[1] === 360
            && $thumbSize['mime'] === 'image/webp' && filesize($thumbFile) < filesize($bigJpeg));
        check_bool('縮小: 元が幅 640 以下なら縮めない(元を配る)', km_image_thumb_file(__DIR__ . '/../Main/Picture/bldg/bldg_gym1.png', 640, $thumbDir) === null || getimagesize(__DIR__ . '/../Main/Picture/bldg/bldg_gym1.png')[0] > 640);
        foreach (glob($thumbDir . DIRECTORY_SEPARATOR . '*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($thumbDir);
    } else {
        check_skip('縮小: 1600×900 の JPEG → 幅 640・縦横比そのままの WebP で小さく', 'GD に WebP / JPEG が無い');
    }
    $posterLibForThumb = (string) file_get_contents(__DIR__ . '/../lib/posters.php');
    check_bool('縮小: 公開の口は ?w= を受け、WebP を読める端末だけ縮小版(Vary: Accept)', str_contains((string) file_get_contents(__DIR__ . '/../api/poster-image.php'), '$thumbWidth = km_image_thumb_width($_GET[\'w\'] ?? null);')
        && str_contains($posterLibForThumb, "header('Vary: Accept');") && str_contains($posterLibForThumb, "\$poster['thumbUrl'] = \$poster['imageUrl'] . '&w=640';"));
    check_bool('縮小: 一覧・検索の候補・地点のポスターは thumbUrl', str_contains((string) file_get_contents(__DIR__ . '/../posters.php'), "\$poster['thumbUrl'] ?? \$poster['imageUrl']")
        && str_contains($appJs, 'img.src = item.poster.thumbUrl || item.poster.imageUrl;') && str_contains($appJs, 'escapeHtml(poster.thumbUrl || poster.imageUrl)'));

    km_check_heading('map: 最初の画面(2026-10-30、振り返りのフェーズ 3 の 1。Main/entry.js)');
    $entryJs = (string) file_get_contents(__DIR__ . '/../Main/entry.js');
    $homeForEntry = (string) file_get_contents(__DIR__ . '/../index.php');
    check_bool('最初の画面: はじめにの後・tutorial.js より先に読む', ($ep = strpos($homeForEntry, "km_public_asset('Main/entry.js')")) !== false
        && $ep > (int) strpos($homeForEntry, "km_public_asset('Main/welcome.js')") && $ep < (int) strpos($homeForEntry, "km_public_asset('Main/tutorial.js')"));
    check_bool('最初の画面: 5 つの探し方(番号・キーワード・部屋・ジャンル・すぐ地図へ)', str_contains($entryJs, "'ポスター・企画の番号から'") && str_contains($entryJs, "'キーワードから'")
        && str_contains($entryJs, "'部屋の名前から'") && str_contains($entryJs, "'ジャンルから'") && str_contains($entryJs, "'すぐ地図へ'"));
    check_bool('最初の画面: 共有から来た人・次から出さない人・地図が開けないときは出さない', str_contains($entryJs, 'if (window.isAdmin || fromShare || skipped()) return;')
        && str_contains($entryJs, "document.body.classList.contains('km-map-unavailable')"));
    check_bool('最初の画面: 展示の PC では「次から出さない」を覚えない', str_contains($entryJs, "if (testMode) return false;") && str_contains($entryJs, 'if (!testMode) {'));
    check_bool('最初の画面: 地図の検索と同じ一致率(app.js の kmEntryApi)・道順は案内できる所だけ', str_contains($appJs, 'window.kmEntryApi = {') && str_contains($entryJs, 'if (item.routable) {'));
    check_bool('チュートリアルは最初の画面があれば自動で始めない・広告は「すぐ地図へ」のときだけ', str_contains((string) file_get_contents(__DIR__ . '/../Main/tutorial.js'), 'if (window.kmEntry) return;')
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/welcome.js'), "!window.kmEntry && typeof window.kmShowAd === 'function'")
        && str_contains($entryJs, "if (toMap && typeof window.kmShowAd === 'function')"));
    check_bool('操作の帯の「🏠 はじめ」から開き直せる', str_contains($homeForEntry, 'data-km-entry-open') && str_contains($entryJs, "document.querySelectorAll('[data-km-entry-open]')"));

    km_check_heading('map: 階の列の上でホイール・縦のスワイプ(2026-10-30、振り返りのフェーズ 3 の 4)');
    check_bool('ホイールの上 = 上の階・地図の拡大へ渡さない・1 段ずつ', str_contains($appJs, "stepFloor(e.deltaY < 0 ? 1 : -1);") && str_contains($appJs, "}, { passive: false });")
        && str_contains($appJs, 'if (now - floorStepAt < 250) return;'));
    check_bool('はらうのは 30px 以上・一覧が送れるときは送る方を優先', str_contains($appJs, 'if (Math.abs(dy) < 30) return;') && str_contains($appJs, 'scroll.scrollHeight > scroll.clientHeight + 1'));

    km_check_heading('ポスター: 地図で見る と 外のサイト を押し間違えない(2026-10-30、振り返りのフェーズ 3 の 5)');
    $posterStatsJs = (string) file_get_contents(__DIR__ . '/../Main/poster-stats.js');
    check_bool('外のサイトへ出る前に 1 度聞く・やめたら数えない', str_contains($posterStatsJs, "if (type === 'click' && !confirmLeave(link)) {")
        && strpos($posterStatsJs, '!confirmLeave(link)') < strpos($posterStatsJs, "window.kmPosterStat(link.getAttribute('data-km-poster-link'), 'click');"));
    $postersPageForLinks = (string) file_get_contents(__DIR__ . '/../posters.php');
    check_bool('一覧: 地図は 🗺 の塗り・外は 🌐 ↗ の点線で右の端', str_contains($postersPageForLinks, '🗺 <?= count($places) > 1') && str_contains($postersPageForLinks, 'km-doc-link km-poster-external')
        && str_contains($postersPageForLinks, '🌐 外のサイトで見る ↗'));
    check_bool('地図の詳細・幕・広告も同じ言葉', substr_count($appJs, '🌐 外のサイトで見る ↗') === 2 && str_contains((string) file_get_contents(__DIR__ . '/../Main/tutorial-end.js'), "'🌐 外のサイトで見る ↗'"));

    km_check_heading('map: 全画面・横画面の上の帯(2026-10-30、振り返りのフェーズ 2 の 4。アプリの TopBarController と同じ)');
    $topBarJs = (string) file_get_contents(__DIR__ . '/../Main/top-bar.js');
    $homeForTopBar = (string) file_get_contents(__DIR__ . '/../index.php');
    check_bool('top-bar.js を app.js の後に読む', ($tb = strpos($homeForTopBar, "km_public_asset('Main/top-bar.js')")) !== false && $tb > (int) strpos($homeForTopBar, "km_public_asset('Main/app.js')"));
    check_bool('全画面か横にしたスマホで帯を隠す', str_contains($topBarJs, "window.matchMedia('(orientation: landscape) and (max-height: 500px)')") && str_contains($topBarJs, 'return isFullscreen() || landscapePhone.matches;'));
    check_bool('アプリと同じ数(4 秒・上の端 40px・24px 引く)', str_contains($topBarJs, 'const AUTO_HIDE_MS = 4000;') && str_contains($topBarJs, 'const EDGE_PX = 40;') && str_contains($topBarJs, 'const PULL_PX = 24;'));
    check_bool('打っている間・欄が開いている間は隠さない', str_contains($topBarJs, 'if (barInUse()) {'));
    check_bool('全画面のボタンは地図ができてから置く(km:map-ready)', str_contains($topBarJs, "window.addEventListener('km:map-ready', addFullscreenControl);") && str_contains($appJs, "window.dispatchEvent(new CustomEvent('km:map-ready'));"));
    check_bool('取っ手は帯を隠しているときだけ出す', str_contains($chromeCss, 'body.km-topbar-auto:not(.km-topbar-peek) .km-topbar-handle {'));

    check_bool('上下のずらしは元の margin-top に足す', str_contains($appJs, "el.style.marginTop = (baseMarginTop + p.dy) + 'px';"));
    check_bool('イベントの地点を一番先・関係ない地点を一番後ろ', str_contains($appJs, "if (el.classList.contains('km-label-event')) return KM_LABEL_PRIORITY_EVENT;")
        && str_contains($appJs, 'const KM_LABEL_PRIORITY_UNRELATED = 10;'));
    $labelLayoutJs = (string) file_get_contents(__DIR__ . '/../Main/label-layout.js');
    // 頭の preload にも app.js があるので、<script> の並びで比べる
    $scriptTag = static fn (string $file): string => '<script defer src="<?= km_home_e(km_public_asset(\'Main/' . $file . '\')) ?>"></script>';
    check_bool('label-layout.js を app.js より先に読む', ($p1 = strpos($index, $scriptTag('label-layout.js'))) !== false && $p1 < (int) strpos($index, $scriptTag('app.js')));
    $repoRootForLabels = km_check_repo_root();
    if ($repoRootForLabels !== null) {
        check_bool('配備の必須ファイルに label-layout.js', str_contains((string) file_get_contents($repoRootForLabels . '/scripts/deploy-to-host.ps1'), "'./src/Main/label-layout.js'"));
    } else {
        check_skip('配備の必須ファイルに label-layout.js', '手元の作業ツリーで');
    }
    check_bool('線は札の後ろに描く', preg_match('/\.km-label\.km-label-led::after\s*\{[^}]*z-index:\s*-1/s', $css) === 1);
    // CSP は style 属性を止める。線の位置は CSSOM(setProperty)で入れる
    check_bool('線の位置は setProperty で入れる', str_contains($appJs, "el.style.setProperty('--km-lead-x'") && !str_contains($appJs, "setAttribute('style'"));
    check_bool('薄い建物名は間引きの数に入れない', str_contains($appJs, "faded && el.classList.contains('km-label-facility')"));
    check_bool('薄い建物名は押せない', preg_match('/#map\.km-facility-faded \.km-label-facility\s*\{[^}]*pointer-events:\s*none/s', $css) === 1);
    // Leaflet はツールチップへ opacity を直接書く
    check_bool('薄さが Leaflet に負けない', preg_match('/#map\.km-facility-faded \.km-label-facility\s*\{[^}]*opacity:\s*0\.45 !important/s', $css) === 1);
    check_bool('表示調整で選べる', str_contains($tuningJs, "'facilityZoomed'"));

    /*
     * アプリ(別のリポジトリ)と**同じ値・同じ意味**であること。在るときだけ突き合わせる
     * (本番のホストにアプリの原本は置いていない)。
     */
    $renderer = 'C:/Users/itota/Documents/Test/app/src/main/java/com/ito/kosenmap/GeneralMapNodeRenderer.kt';
    if (is_file($renderer)) {
        $kotlin = (string) file_get_contents($renderer);
        check_bool(
            'アプリも faded / hidden / shown',
            str_contains($kotlin, 'FADED("faded")') && str_contains($kotlin, 'HIDDEN("hidden")') && str_contains($kotlin, 'SHOWN("shown")')
        );
        // 2026-10-30 から屋内外とも間引く(利用者の判断「B」)。選んだ場所・目的地は隠さない(Website の keep と同じ)
        $mapScreenKt = (string) file_get_contents(dirname($renderer) . '/MapScreen.kt');
        check_bool('アプリも屋内外とも間引き、目的地は隠さない', str_contains($mapScreenKt, 'declutterLabels = true,') && str_contains($mapScreenKt, 'keepLabelUuids = setOfNotNull(destinationNode?.uuid),')
            && str_contains($layoutKt ?? (string) @file_get_contents(dirname($renderer) . '/LabelLayout.kt'), 'if (!hideWhenCrowded || item.keep) {'));
        // 引き出し線の数(ずらし・横の間・線の間)がアプリと同じ
        $layoutKt = (string) @file_get_contents(dirname($renderer) . '/LabelLayout.kt');
        check_bool(
            'アプリと同じ置き方の数',
            str_contains($kotlin, 'LABEL_NUDGE_STEPS = listOf(0f, -14f, 14f, -30f, 30f)') && str_contains($labelLayoutJs, 'const NUDGES = [0, -14, 14, -30, 30];')
            && str_contains($layoutKt, 'LABEL_ADJACENT_GAP = 4f') && str_contains($labelLayoutJs, 'const ADJACENT_GAP = 4;')
            && str_contains($layoutKt, 'LABEL_LEADER_GAPS = listOf(36f, 68f)') && str_contains($labelLayoutJs, 'const LEADER_GAPS = [36, 68];')
        );
    } else {
        check_skip('アプリと突き合わせ', 'アプリの原本が無い(本番のホストなど)');
    }

    km_check_heading('layout: 表示調整のつまみは全部効く');
    /*
     * **出しているのに読んでいないつまみを作らない。**
     * 「文字」(`labelSize`)が実際にそうだった —— つまみは動くし値も残るのに、
     * 読む所がどこにも無く、**何も起きない**(利用者の指摘で判明、2026-09-05)。
     * 画面には出ているので、開いて眺めても気づけない。
     *
     * `DEFAULTS` の鍵を拾って、どこかで `tuning.<鍵>` として読まれているかを見る。
     * つまみを足すときは、読む側を書くまでここが落ちる。
     */
    $styleJs = (string) file_get_contents(__DIR__ . '/../Main/map-style.js');
    $readers = $appJs . $styleJs . $tuningJs;
    $defaults = '';
    if (preg_match('/const DEFAULTS = \{(.*?)\n    \};/s', $styleJs, $m)) {
        $defaults = $m[1];
    }
    preg_match_all('/^\s{8}([A-Za-z][A-Za-z0-9_]*):/m', $defaults, $keys);
    $tuningKeys = $keys[1] ?? [];
    check_bool('既定値の一覧を読めた', count($tuningKeys) >= 8);
    $unread = [];
    foreach ($tuningKeys as $key) {
        if (!str_contains($readers, 'tuning.' . $key)) $unread[] = $key;
    }
    check('読まれていないつまみは無い', [], $unread);

    /*
     * 文字の大きさは**描き直しでは変わらない**(ラベルは Leaflet の tooltip で、
     * 大きさを持っているのは CSS)。CSS 変数へ渡す道が要る。
     */
    check_bool('文字の倍率を CSS へ渡す', str_contains($styleJs, "setProperty('--km-label-scale'"));
    check_bool('CSS に既定の倍率がある', str_contains($css, '--km-label-scale: 1;'));
    // 0 を書かれると地図の字が全部消える。つまみの範囲へ丸めてから使う
    // 上限は 260%(2026-10-30、振り返りのフェーズ 1。アプリと同じ)。つまみ・丸め・配った既定の 3 か所をそろえる
    check_bool('壊れた値はつまみの範囲へ丸める(50〜260%。つまみ・配った既定と同じ)', str_contains($styleJs, 'Math.min(260, Math.max(50, raw))')
        && str_contains((string) file_get_contents(__DIR__ . '/../Main/map-tuning.js'), "slider('文字', 'labelSize', 50, 260);")
        && str_contains((string) file_get_contents(__DIR__ . '/../lib/display-defaults.php'), "\$web['labelSize'] = min(260, \$v);"));
    /*
     * **地図の上の字は全部この係数を通す。** 一部だけ通すと、大きくしたときに
     * 札と長さの釣り合いが崩れて、かえって読みにくくなる。
     */
    $scaled = substr_count($css, 'var(--km-label-scale)');
    check_bool('地図の字がまとめて効く(4箇所以上)', $scaled >= 4);
    // 調整パネルを出さない編集画面でも通る場所で呼ぶこと
    check_bool('読み込み時にも一度渡す', str_contains($appJs, 'mapStyle.applyLabelScale(tuning);'));
}

// ======================================================================== route

/**
 * 経路探索。**線として引かれていない繋がりを、アプリと同じ規則で足しているか。**
 *
 * アプリ(`RouteSearch.kt`)は、同じ接続ID(`transferGroupId`)を持つ階段と出入口を
 * 探索のたびに結んでいる。Website は保存された線だけでグラフを組んでいたため、
 * **屋外と 1F を繋ぐ辺がどこにも無く、外から中への案内が必ず失敗していた**
 * (利用者の指摘、2026-09-05)。
 *
 * ここが壊れても**画面には何も出ない** —— 出入口の点は両方描かれるし、
 * 経路が無いときの文言も普通に出る。地図を眺めても気づけない種類の壊れ方なので、
 * 繋ぎ方そのものを検査で押さえる。
 */
function km_check_route(): void
{
    $appJs = (string) file_get_contents(__DIR__ . '/../Main/app.js');
    $dijkstra = (string) file_get_contents(__DIR__ . '/../Main/dijkstra.js');
    $styleJs = (string) file_get_contents(__DIR__ . '/../Main/map-style.js');
    $tuningJs = (string) file_get_contents(__DIR__ . '/../Main/map-tuning.js');
    $mapData = (string) file_get_contents(__DIR__ . '/../lib/map-data.php');

    km_check_heading('route: 接続IDが画面まで届く');
    /*
     * **列を読んでいるだけでは足りない。** ブラウザへ渡らなければ繋ぎようが無い。
     * 在る列だけ読む形は崩さないこと —— 移行 SQL は配備利用者が別に流すので、
     * 列が無い状態のホストでも地図は出し続ける必要がある。
     */
    check_bool(
        '出入口の接続IDを返している',
        str_contains($mapData, "'transfer_group_id' => 'transferGroupId'")
    );
    check_bool(
        '在る列だけ読む形を崩していない',
        str_contains($mapData, 'if (!in_array($column, $available, true))')
    );

    km_check_heading('route: 線の無い繋がりを足す');
    check_bool('乗り換えの辺を足している', str_contains($dijkstra, 'addTransferEdges(nodes)'));
    // 改行の書き方(CRLF/LF)に依存させない。空白はまとめて読み飛ばす
    check_bool('階段を繋ぐ', preg_match("/pairUp\(\s*'stairs',/", $dijkstra) === 1);
    check_bool('出入口を繋ぐ', preg_match("/pairUp\(\s*'entrance',/", $dijkstra) === 1);
    /*
     * **出入口は接続IDだけで結ぶ**(アプリの `routeEntranceTransferKey` と同じ)。
     * 「玄関」は建物ごとに在りうるので、名前で寄せると**別の建物の中へ出る。**
     * 階段の側だけ名前で寄せてよい(アプリの `routeStairTransferKey` がそうしている)。
     */
    check_bool(
        '出入口は名前で寄せない',
        preg_match("/'entrance',\s*Dijkstra\.transferId,/", $dijkstra) === 1
    );
    check_bool(
        '階段は名前でも寄せる(古い地図のため)',
        preg_match("/'stairs',\s*Dijkstra\.stairKey,/", $dijkstra) === 1
    );
    // 重みはアプリと同じ(20m / 15m を px/m の既定 10 で画素に直した値)。出入りは 2026-09-25 に A 屋内優先で 3m → 15m
    // 値の突き合わせは route-weights の群がアプリの原本と行う
    check_bool('階の移動は 200px', str_contains($dijkstra, '    floorTransferPx: 200,'));
    check_bool('屋内外の出入りは 150px', str_contains($dijkstra, '    entranceTransferPx: 150,'));
    // 0 にすると階の移動がタダになり、少し歩けば済む場面で階段が選ばれる
    check_bool('乗り換えをタダにしない', !str_contains($dijkstra, 'floorTransferPx: 0,') && str_contains($dijkstra, "positive('floorTransferPx', 1)"));
    /*
     * **繋げたかどうかを数える。** 0 のまま外から中を探すと必ず失敗するので、
     * 「経路が見つかりません」ではなく**登録漏れ**として言う材料になる。
     */
    check_bool('繋いだ組を数えている', str_contains($dijkstra, 'this.entranceTransfers'));
    check_bool('数を使って理由を言い分ける', str_contains($appJs, 'dijkstra.entranceTransfers === 0'));

    km_check_heading('route: 壁の線を消しても通れない');
    /*
     * 壁の線は表示調整で消せるようにした(2026-09-05、利用者の要望)。
     * **消せるのは線だけ。** 経路の側でも通さないことを、別々に押さえておく ——
     * 片方だけ直すと「見えないのに通れない」か「見えるのに通り抜ける」になる。
     */
    check_bool('壁の線を切り替えられる', str_contains($tuningJs, "toggle('壁の線', 'wallLines')"));
    check_bool('既定は出す(今の見た目を変えない)', str_contains($styleJs, 'wallLines: true'));
    check_bool('描画側が見ている', str_contains($appJs, 'if (wall && !tuning.wallLines) return;'));
    check_bool('経路は壁を載せない', str_contains($dijkstra, 'if (edge.wall) {'));
}

// ==================================================================== admin-log

/**
 * 監査ログの文言。**記録している操作に、必ず読める文言があること。**
 *
 * ここが抜けると、画面に `map.event_cleanup_mode` のような内部の識別子がそのまま出る。
 * 実際に抜けていた(イベントモードで足した10種すべて)。
 *
 * 記録側は全ファイルに散っているので、**表を眺めて突き合わせるのは無理**。
 * 呼び出しをソースから拾って照合する。
 */
function km_check_admin_log(): void
{
    require_once __DIR__ . '/../lib/admin-log.php';

    km_check_heading('admin-log: カテゴリと見た目');
    check('カテゴリは5つ', ['auth', 'table', 'settings', 'system', 'content'], KM_ADMIN_LOG_CATEGORIES);
    $missingIcons = array_values(array_diff(KM_ADMIN_LOG_CATEGORIES, array_keys(KM_ADMIN_LOG_ICONS)));
    check_bool(
        'どのカテゴリにも見た目がある',
        $missingIcons === [],
        $missingIcons === [] ? '' : implode(' / ', $missingIcons)
    );

    /*
     * `km_admin_log_record('カテゴリ', '操作'` を全 PHP から拾う。
     * 改行を挟んだ書き方(引数を縦に並べたもの)も拾えるよう \s* を入れてある。
     */
    $recorded = [];
    $categoriesUsed = [];
    $directory = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($directory as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'vendor')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        /*
         * 2つ目の引数が操作名。**三項演算子で分ける書き方もある**ので、
         * `$id === null ? 'map.event_create' : 'map.event_update'` の両方を拾う。
         * (拾えていなかったせいで、この2つだけ文言の突き合わせから漏れていた)
         */
        if (preg_match_all(
            "/km_admin_log_record\(\s*'([a-z]+)'\s*,\s*(?:[^,;()]*\?\s*)?'([a-zA-Z0-9._]+)'(?:\s*:\s*'([a-zA-Z0-9._]+)')?/",
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $categoriesUsed[$match[1]] = true;
                $recorded[$match[2]] = true;
                if (($match[3] ?? '') !== '') {
                    $recorded[$match[3]] = true;
                }
            }
        }
    }

    km_check_heading('admin-log: 記録している操作と文言');
    check_bool('呼び出しを拾えている', count($recorded) >= 40, count($recorded) . '種');

    $unknownCategories = array_values(array_diff(array_keys($categoriesUsed), KM_ADMIN_LOG_CATEGORIES));
    // 知らないカテゴリを渡すと km_admin_log_record() が握り潰し、**記録が黙って消える**
    check_bool(
        '知らないカテゴリを渡していない',
        $unknownCategories === [],
        $unknownCategories === [] ? '' : implode(' / ', $unknownCategories)
    );

    $unlabelled = array_values(array_diff(array_keys($recorded), array_keys(KM_ADMIN_LOG_ACTION_LABELS)));
    sort($unlabelled);
    check_bool(
        'すべての操作に文言がある',
        $unlabelled === [],
        $unlabelled === [] ? count($recorded) . '種' : implode(' / ', $unlabelled)
    );

    // 逆向き。使わなくなった操作の文言が残っていても害は無いが、
    // **綴りを間違えた文言**はこちらにだけ現れるので気づける
    $unused = array_values(array_diff(array_keys(KM_ADMIN_LOG_ACTION_LABELS), array_keys($recorded)));
    sort($unused);
    check_bool(
        '記録されない文言が残っていない',
        $unused === [],
        $unused === [] ? '' : implode(' / ', $unused)
    );

    km_check_heading('admin-log: アカウント別の取り出し');
    // **ID で絞ること。** 表示名で絞ると、改名した前後で自分の記録が切れる
    $source = file_get_contents((string) (new ReflectionFunction('km_admin_log_for_actor'))->getFileName());
    check_bool('actor_id で絞っている', str_contains($source, 'WHERE actor_id = ?'));
    // 接続元を返さないと「いつもと違う場所から入られた」に気づけない
    check_bool('IP を返している', str_contains($source, 'INET6_NTOA(ip_address) AS ip'));
}

// =================================================================== map-events

/**
 * 期限切れイベントの掃除まわり。**DB は使わない範囲だけ。**
 *
 * 掃除は「通行止め・臨時の地点・臨時名称ごと消して、戻せない」操作なので、
 * 歯止めが外れていないかをここで固定する。
 */
function km_check_map_events(): void
{
    require_once __DIR__ . '/../lib/map-events.php';

    km_check_heading('map-events: 掃除の歯止め');

    // **既定は手動。** 消す方を既定にすると、設定を知らないままイベントが消える。
    check('掃除モードは manual と auto の2つ', ['manual', 'auto'], KM_EVENT_CLEANUP_MODES);
    check_bool('既定は manual', KM_EVENT_CLEANUP_MODES[0] === 'manual');

    /*
     * 猶予が 0 だと、ends_at を打ち間違えた瞬間にイベントが消え、
     * 重ね合わせごと戻せなくなる。ここは必ず正の日数。
     */
    check_bool(
        '猶予が 0 日になっていない',
        KM_EVENT_CLEANUP_GRACE_DAYS > 0,
        KM_EVENT_CLEANUP_GRACE_DAYS . ' 日'
    );
    check_bool(
        '猶予が短すぎない(1週間以上)',
        KM_EVENT_CLEANUP_GRACE_DAYS >= 7,
        '翌週に気づいて止められる長さであること'
    );

    km_check_heading('map-events: 有効判定の式');
    /*
     * KM_MAP_EVENT_ACTIVE_SQL は「いま有効」の正本。期限切れが地図に反映され続けないのは
     * この式のおかげで、掃除が遅れても実害が出ない根拠でもある。
     */
    check_bool('is_enabled を見ている', str_contains(KM_MAP_EVENT_ACTIVE_SQL, 'is_enabled = 1'));
    check_bool('終了時刻を見ている', str_contains(KM_MAP_EVENT_ACTIVE_SQL, 'ends_at > NOW()'));
    check_bool('開始時刻を見ている', str_contains(KM_MAP_EVENT_ACTIVE_SQL, 'starts_at <= NOW()'));
    check_bool(
        'NULL は「制限なし」として扱う',
        str_contains(KM_MAP_EVENT_ACTIVE_SQL, 'starts_at IS NULL')
            && str_contains(KM_MAP_EVENT_ACTIVE_SQL, 'ends_at IS NULL')
    );

    km_check_heading('map-events: 設定の保存');
    check_bool('設定の名前が決まっている', KM_EVENT_CLEANUP_SETTING !== '');

    /*
     * 知らない値を保存させないこと。画面の POST から来た文字列がそのまま渡る経路なので、
     * **検証は DB へ触る前**に置く必要がある。
     *
     * 実際に呼んで確かめたいところだが、この検査は DB を使わない約束で書いてある
     * (手元の PHP には sqlite ドライバも無い)。**関数の中身を読んで順序を見る。**
     */
    $save = new ReflectionFunction('km_map_event_cleanup_mode_save');
    $body = implode('', array_slice(
        file($save->getFileName()),
        $save->getStartLine() - 1,
        $save->getEndLine() - $save->getStartLine() + 1
    ));
    $guardAt = strpos($body, 'InvalidArgumentException');
    $writeAt = strpos($body, 'km_setting_set');
    check_bool('知らないモードを弾く分岐がある', $guardAt !== false);
    check_bool('保存より前に弾いている', $guardAt !== false && $writeAt !== false && $guardAt < $writeAt);
    check_bool(
        '許すのは KM_EVENT_CLEANUP_MODES のものだけ',
        str_contains($body, 'KM_EVENT_CLEANUP_MODES')
    );
}

// ============================================================ recaptcha-messages

/**
 * `km_recaptcha_explain()` の対応表。**純粋関数なので既定で走る。**
 *
 * この表は「**先に一致したものを返す**」ので、並び順が意味を持つ。
 * 2026-08-29、本番の問い合わせフォームが 403 で止まったとき、Google が返したのは
 *
 *   {"error":{"code":403,"message":"Requests from this referer are blocked.",
 *             "status":"PERMISSION_DENIED"}}
 *
 * だけで `API_KEY_HTTP_REFERRER_BLOCKED` は入っていなかった。そのため末尾の
 * `PERMISSION_DENIED` に先に当たり、「このプロジェクトに対する権限がありません」という
 * **原因から遠い案内**が出ていた(調査がネットワーク遮断の疑いから始まった)。
 *
 * 並べ替えで壊れる種類の間違いなので、ここで固定する。
 */
function km_check_recaptcha_messages(): void
{
    require_once __DIR__ . '/../lib/recaptcha.php';

    km_check_heading('recaptcha-messages: 403 の案内');

    // 本番で実際に返ってきた本文そのもの。
    $referrerBody = '{"error":{"code":403,"message":"Requests from this referer are blocked.",'
        . '"status":"PERMISSION_DENIED"}}';
    $advice = km_recaptcha_explain($referrerBody);
    check_bool(
        'リファラー制限だと分かる案内が出る',
        str_contains($advice, 'アプリケーションの制限'),
        $advice
    );
    check_bool(
        '「権限がありません」に化けていない',
        !str_contains($advice, 'このプロジェクトに対する権限がありません'),
        '並び順が PERMISSION_DENIED より後ろになると化ける'
    );

    km_check_heading('recaptcha-messages: 他の既知パターン');
    check_bool(
        'API の制限',
        str_contains(km_recaptcha_explain('{"status":"API_KEY_SERVICE_BLOCKED"}'), 'API の制限')
    );
    check_bool(
        'IP 制限',
        str_contains(km_recaptcha_explain('{"status":"API_KEY_IP_ADDRESS_BLOCKED"}'), 'IP 制限')
    );
    check_bool(
        'API が未有効',
        str_contains(km_recaptcha_explain('has not been used in project 123'), '有効化')
    );
    check_bool(
        '知らない本文は、そう言う',
        str_contains(km_recaptcha_explain('{"error":"something new"}'), '既知のパターンには当てはまりません')
    );
}

// ===================================================================== recaptcha

/** 値そのものを出さずに形だけを見せる。 */
function km_check_recaptcha_shape(?string $value): string
{
    if ($value === null) {
        return '未設定';
    }

    return sprintf('先頭4文字=%s… 長さ=%d', substr($value, 0, 4), strlen($value));
}

/**
 * reCAPTCHA の設定を確かめる。**環境依存**なので、名前を明示したときだけ走る。
 *
 * **秘密の値は出力しない。** 出すのは「置かれているか / 長さ / 先頭4文字」だけで、
 * これは方式の取り違え(API キーをサイトキーの欄に入れる等)を見つけるのに要る最小限。
 *
 * Enterprise の場合は、**わざと無効なトークンで assessments を1回叩く**。
 * ここで HTTP 200 + `invalidReason` が返れば、プロジェクト ID・API キー・API の有効化・
 * ネットワーク経路がすべて正しいことの証明になる(ブラウザを使わずに設定を確かめられる)。
 *
 * クラシックの siteverify では同じことができない。**秘密鍵が空でも間違っていても
 * `invalid-input-response` しか返らず、鍵の正しさを判別できない**(1.0.0 の確認時に実測)。
 * そのため疎通の可否までしか言わない。
 *
 * @return int 終了コード
 */
function km_check_recaptcha(): int
{
    require_once __DIR__ . '/../lib/recaptcha.php';

    $config = km_recaptcha_config();
    $mode = km_recaptcha_mode();

    km_check_heading('recaptcha: 置かれている値');
    foreach (['siteKey', 'projectId', 'apiKey', 'secretKey'] as $name) {
        printf("  %-10s %s\n", $name, km_check_recaptcha_shape($config[$name]));
    }

    km_check_heading('recaptcha: 判定');
    printf("  方式: %s\n", $mode ?? '未設定(フォームは「準備中」と表示される)');
    if ($mode !== null) {
        printf("  読み込む JS: %s\n", km_recaptcha_script_url());
    }

    /*
     * よくある取り違え。形が明らかに違うものは、叩く前に指摘する。
     * AIza… は Google Cloud の API キー、6L… は reCAPTCHA のサイトキー/秘密鍵。
     */
    km_check_heading('recaptcha: 形の点検');
    $warnings = [];
    if ($config['siteKey'] !== null && str_starts_with($config['siteKey'], 'AIza')) {
        $warnings[] = 'siteKey が AIza… で始まっています。これは Google Cloud の API キーです。'
            . 'サイトキー(6L… で始まる)と入れ替わっていませんか';
    }
    if ($config['apiKey'] !== null && str_starts_with($config['apiKey'], '6L')) {
        $warnings[] = 'apiKey が 6L… で始まっています。これは reCAPTCHA のサイトキーです。'
            . 'API キー(AIza… で始まる)と入れ替わっていませんか';
    }
    if ($config['secretKey'] !== null && str_starts_with($config['secretKey'], 'AIza')) {
        $warnings[] = 'secretKey が AIza… で始まっています。これは Google Cloud の API キーで、'
            . 'クラシックの秘密鍵ではありません。Enterprise 版として apiKey へ移し、'
            . 'projectId も設定してください';
    }
    if ($config['secretKey'] !== null && $config['secretKey'] === $config['siteKey']) {
        $warnings[] = 'secretKey に siteKey と同じ値が入っています。サイトキーは秘密ではなく、'
            . '秘密鍵の代わりにもなりません。Enterprise で使うなら secretKey は空にしてください';
    }
    if ($config['siteKey'] !== null && !str_starts_with($config['siteKey'], '6L')) {
        $warnings[] = 'siteKey が 6L… で始まっていません。サイトキーではない可能性があります';
    }
    if ($warnings === []) {
        echo "  おかしなところはありません。\n";
    } else {
        foreach ($warnings as $warning) {
            echo "  [注意] {$warning}\n";
        }
    }

    if ($mode === null) {
        echo "\n値が揃っていないので疎通は試しません。\n";
        echo "(手元では未設定が正常です。秘密は配備対象外で、ホスト側にしかありません)\n";

        return 1;
    }

    km_check_heading('recaptcha: 実際に叩いてみる(無効なトークンをわざと送る)');
    try {
        km_recaptcha_verify('この文字列は無効なトークンです', '');
        echo "  [異常] 無効なトークンが通ってしまいました。設定を見直してください。\n";

        return 1;
    } catch (Throwable $exception) {
        /*
         * ここへ来るのが正常。ただし「拒否された(= 経路は正しい)」のか
         * 「到達できなかった(= 設定かネットワークの問題)」のかで意味がまるで違う。
         * 判別は利用者向けの文言ではなく、上の error_log に出た内容を見る。
         */
        $message = $exception->getMessage();
        if (str_contains($message, '確認が取れませんでした')) {
            echo "  OK 無効なトークンとして正しく拒否されました。\n";
            if ($mode === 'enterprise') {
                echo "     = プロジェクト ID・API キー・API の有効化・ネットワーク経路のすべてが通っています。\n";
            } else {
                /*
                 * クラシックはここまでしか言えない。**秘密鍵が空でも間違っていても
                 * 同じ `invalid-input-response` が返る**ため、鍵の正しさは判別できない
                 * (でたらめな鍵・空文字と並べて実測して確かめた)。
                 * 最終確認はブラウザで実際にチェックを入れて送るしかない。
                 */
                echo "     ただし**クラシックでは鍵の正しさまでは分かりません**。\n";
                echo "     秘密鍵が空でも誤っていても同じ応答が返るため、確認できたのは経路だけです。\n";
            }

            return 0;
        }

        echo "  [失敗] 検証に到達できませんでした。\n";
        echo "     利用者向けの文言: {$message}\n";
        echo "     Google の応答と、それに対する手当ては上の行(→ の右)に出ています。\n";
        echo "     もう一度読むなら: docker compose logs --tail=30 web\n";

        return 1;
    }
}

/**
 * QR の符号化。**PowerShell 版からの移植が壊れていないか。**
 *
 * QR は目で見ても正しさが分からず、1ビット違うだけで読めなくなる。
 * ここでは**確かめ済みの並びの指紋**と突き合わせる。
 * 指紋は `scripts/check-qr-port.ps1` が PowerShell 版と一致を確認した出力から取った ——
 * つまり**動いている実装と同じ**であることが根拠。
 *
 * 型・誤り訂正・マスクを網羅した突き合わせは `check-qr-port.ps1` の側。
 * こちらは配備のたびに走る側なので、**壊れたことに気づくための1点**を持つ。
 */
function km_check_qr(): void
{
    require_once __DIR__ . '/../lib/qr.php';

    $fingerprint = static function (string $text, string $ec, int $mask): string {
        $rows = [];
        foreach (km_qr_matrix($text, $ec, $mask) as $row) {
            $rows[] = implode('', array_map(static fn ($b) => $b ? '1' : '0', $row));
        }

        return hash('sha256', implode('', $rows));
    };

    km_check_heading('qr: 並びが変わっていない');
    check(
        '型2 / M / マスク自動',
        '9b458264d430aa1d19a1c32733c94164203f2407371c387f688f2e6b312f37e7',
        $fingerprint('KOSENMAP1:KOSEN2026', 'M', -1)
    );
    check(
        '型3 / H / マスク3',
        '60523b1e9b4d51664957ce76eaf5ab3d4d8e997eeeae5eca927d0ed0bc3f63bc',
        $fingerprint('KOSENMAP1:KOSEN2026', 'H', 3)
    );

    km_check_heading('qr: 大きさ');
    // 一辺は 型*4+17。誤り訂正を上げると同じ文字列でも型が上がる
    check('型2 は 25 モジュール', 25, count(km_qr_matrix('KOSENMAP1:KOSEN2026', 'M')));
    check('H にすると型3', 29, count(km_qr_matrix('KOSENMAP1:KOSEN2026', 'H')));

    km_check_heading('qr: 位置検出パターン');
    /*
     * 三隅の 7x7 は必ずこの形。**ここが崩れると読み取り機がコードを見つけられない。**
     * 指紋だけだと「違う」ことしか分からないので、目印だけは別に見る。
     */
    $matrix = km_qr_matrix('KOSENMAP1:KOSEN2026', 'M');
    $size = count($matrix);
    check_bool('左上の角は暗い', $matrix[0][0]);
    check_bool('左上の内側の白枠', !$matrix[1][1]);
    check_bool('左上の中心は暗い', $matrix[3][3]);
    check_bool('右上にもある', $matrix[0][$size - 1]);
    check_bool('左下にもある', $matrix[$size - 1][0]);
    // 分離帯。位置検出パターンの周りは必ず白
    check_bool('分離帯が白い', !$matrix[7][7]);

    km_check_heading('qr: SVG');
    $svg = km_qr_svg($matrix, 4, 4);
    check_bool('SVG になっている', str_starts_with($svg, '<svg '));
    // **静寂域を必ず付ける。**無いと背景と地続きになって端を見つけられない
    check_bool('静寂域ぶん大きい', str_contains($svg, 'width="132"'));
    check_bool('白地を敷く', str_contains($svg, '<rect'));
    // 矩形を数百個並べない(印刷したときに継ぎ目の白線が出る)
    check('矩形は1つだけ', 1, substr_count($svg, '<rect'));
}

// ========================================================================== 入口

$arguments = array_slice($argv, 1);

// -Help は PS1 の -Help と揃えた(2026-10-16、利用者の指示「オプションとその意味を表示できるように」)
if (in_array('-Help', $arguments, true) || in_array('--help', $arguments, true) || in_array('-h', $arguments, true)
    || in_array('--list', $arguments, true)) {
    echo "使い方: php src/scripts/check.php [名前 …] [オプション]\n\n";
    echo "  自己検査。DB も Logto も Web サーバーも要らない範囲で、取り違えたときの被害が大きい判定を確かめる。\n\n";
    echo "オプション:\n";
    echo "  (なし)          純粋な検査をすべて走らせる\n";
    echo "  名前 …          その検査だけ走らせる(下の一覧から。いくつでも)\n";
    echo "  --list          検査の名前の一覧を出す(走らせない)\n";
    echo "  -Help / -h / --help  このオプションとその意味を出して終わる\n\n";
    echo "終わり方: 0 すべて通過 / 1 落ちたものがある / 2 知らない名前を渡した・要る拡張(mbstring)が無い\n\n";
    echo "  純粋(既定で走る):\n";
    foreach (KM_CHECK_PURE as $name) {
        echo "    {$name}\n";
    }
    echo "\n  環境依存(名前を書いたときだけ走る):\n";
    foreach (KM_CHECK_ENVIRONMENT as $name) {
        echo "    {$name}\n";
    }
    exit(0);
}

$known = array_merge(KM_CHECK_PURE, KM_CHECK_ENVIRONMENT);
$unknown = array_values(array_diff($arguments, $known));
if ($unknown !== []) {
    fwrite(STDERR, '知らない名前です: ' . implode(', ', $unknown) . "\n");
    fwrite(STDERR, '選べるのは: ' . implode(', ', $known) . "\n");
    exit(2);
}

$selected = $arguments === [] ? KM_CHECK_PURE : $arguments;

// mbstring が要るのは app-ranking だけ。走らせる前に見て、直し方まで出す。
if (in_array('app-ranking', $selected, true) && !function_exists('mb_strlen')) {
    fwrite(STDERR, "app-ranking には mbstring が要ります(語の長さを文字数で数えるため)。\n");
    fwrite(STDERR, "  php -d extension_dir=<php>\\ext -d extension=mbstring src/scripts/check.php\n");
    fwrite(STDERR, "mbstring 無しで他だけ走らせるなら: php src/scripts/check.php user-stats app-map\n");
    exit(2);
}

$package = null;

/*
 * **組ごとに例外を受け止める**(2026-10-06、診断「1 つの検査が例外を投げると以降が走らない」)。
 * 以前は 1 つの組の途中で例外(読めないファイル・型の誤りなど)が出ると、残りの組が 1 件も走らずに
 * 致命的エラーで終わっていた。ここで受け止めて**その組を失敗として数え**、次の組へ進む。
 * (構文の誤りのように、このファイルそのものが読めないものは受け止められない。それは exit 255 になる)
 */
foreach ($selected as $name) {
    try {
    switch ($name) {
        case 'user-stats':
            km_check_user_stats();
            break;
        case 'app-map':
            $package = km_check_app_map();
            break;
        case 'app-ranking':
            km_check_app_ranking();
            break;
        case 'recaptcha-messages':
            km_check_recaptcha_messages();
            break;
        case 'map-events':
            km_check_map_events();
            break;
        case 'map-access':
            km_check_map_access();
            break;
        case 'admin-log':
            km_check_admin_log();
            break;
        case 'app-map-convert':
            km_check_app_map_convert();
            break;
        case 'legal':
            km_check_legal();
            break;
        case 'app-map-web-events':
            km_check_app_map_web_events();
            break;
        case 'admin-log-user-agent':
            km_check_admin_log_user_agent();
            break;
        case 'seed-once':
            km_check_seed_once();
            break;
        case 'staff-org':
            km_check_staff_org();
            break;
        case 'staff-org-map':
            km_check_staff_org_map();
            break;
        case 'staff-org-app':
            km_check_staff_org_app();
            break;
        case 'staff-nodes':
            km_check_staff_nodes();
            break;
        case 'map-vertical':
            km_check_map_vertical();
            break;
        case 'building-floors':
            km_check_building_floors();
            break;
        case 'ssh-roles':
            km_check_ssh_roles();
            break;
        case 'ssh-notify':
            km_check_ssh_notify();
            break;
        case 'github-mirror':
            km_check_github_mirror();
            break;
        case 'route-weights':
            km_check_route_weights();
            break;
        case 'review-0925':
            km_check_review_0925();
            break;
        case 'guest-links':
            km_check_guest_links();
            break;
        case 'router-bssids':
            km_check_router_bssids();
            break;
        case 'map-calibration':
            km_check_map_calibration();
            break;
        case 'positioning-params':
            km_check_positioning_params();
            break;
        case 'learning-data':
            km_check_learning_data();
            break;
        case 'ar-capture':
            km_check_ar_capture();
            break;
        case 'app-update':
            km_check_app_update();
            break;
        case 'account-delete':
            km_check_account_delete();
            break;
        case 'self':
            km_check_self();
            break;
        case 'update-notice':
            km_check_update_notice();
            break;
        case 'security-notice':
            km_check_security_notice();
            break;
        case 'log-notice':
            km_check_log_notice();
            break;
        case 'backup-notice':
            km_check_backup_notice();
            break;
        case 'powershell':
            km_check_powershell();
            break;
        case 'shell':
            km_check_shell();
            break;
        case 'notebooks':
            km_check_notebooks();
            break;
        case 'security-review':
            km_check_security_review();
            break;
        case 'hardening':
            km_check_hardening();
            break;
        case 'layout':
            km_check_layout();
            break;
        case 'route':
            km_check_route();
            break;
        case 'qr':
            km_check_qr();
            break;
        case 'recaptcha':
            // 環境依存。数えず、終了コードだけを持ち帰る。
            $recaptchaExit = km_check_recaptcha();
            break;
    }
    } catch (Throwable $exception) {
        $failures++;
        $checks++;
        echo "  [$name] 組の途中で例外が出ました(ここから先のこの組の検査は走っていません)    FAIL  "
            . get_class($exception) . ': ' . $exception->getMessage() . ' (' . basename($exception->getFile()) . ':' . $exception->getLine() . ")\n";
    }
}

echo "\n";

// 純粋な検査を1件も走らせていないなら、集計は出さない(0件で「通過」と言わない)。
if ($checks > 0) {
    /*
     * **飛ばした数も出す。** 配備先では `scripts/` や `Old/` を見られないので
     * いくつか飛ぶ。件数だけ見て「手元より少ない」と悩まないように、理由ごと出す。
     */
    $skippedNote = $skipped > 0 ? " (調べられず飛ばしたもの {$skipped} 件)" : '';
    if ($failures === 0) {
        echo "すべて通過 ({$checks} 件){$skippedNote}\n";
    } else {
        echo "{$failures} 件失敗 / {$checks} 件{$skippedNote}\n";
    }
}

if ($package !== null) {
    echo "\nMapPackageTest.serverProducedPackage に貼る値:\n";
    echo $package, PHP_EOL;
    echo "\n(この形を変えたら app/src/test/java/com/ito/kosenmap/MapPackageTest.kt も直すこと)\n";
}

if ($failures > 0) {
    exit(1);
}
exit($recaptchaExit ?? 0);
