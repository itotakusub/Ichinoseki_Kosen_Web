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
                'occupantName' => '中嶋 剛 先生'],
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
    check_bool('氏名の文字列がどこにも残らない', !str_contains($strippedJson, '中嶋'));
    // 配信の入口が呼ぶのはこちら。**氏名の除去がここから外れていないこと**
    $viaStaffOnly = km_app_map_decode_snapshot(json_encode([
        'version' => 10,
        'nodes' => [['uuid' => 'n1', 'title' => '教員室', 'subtitle' => '1-106',
            'type1' => 'room', 'floor' => '1F', 'x' => 1, 'y' => 2,
            'occupantName' => '岩渕 晴 先生']],
        'lines' => [],
    ]));
    check_bool(
        'staffOnly の除去からも氏名が落ちる',
        km_app_map_strip_staff_only($viaStaffOnly)->nodes[0]->occupantName === null
    );

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
            ['uuid' => 'n1', 'occupantName' => '中嶋 剛 先生'],
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

    $_SESSION['km_map_unlocked'] = true;
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
    $_SESSION['km_map_unlocked'] = true;
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
    $_SESSION['km_map_unlocked'] = true;
    check_bool('利用者の解除は管理者の印にならない', !km_map_admin_session());
    $_SESSION = [];

    km_check_heading('map-access: 入力欄を出す条件');
    // **どちらか一方でも要求していれば出す。** 片方しか見ないと、
    // 地図だけ錠を掛けたときに入力欄が出ず、利用者に開ける手段が無くなる。
    check('地図だけ password でも出す', true, km_map_password_requested($config('hidden', 'password')));
    check('氏名だけ password でも出す', true, km_map_password_requested($config('password', 'public')));
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
        str_contains($mapDataBody, '$unlocked = $forAdmin || (km_map_names_unlocked($config)')
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
                'occupantName' => '和山 正人'],
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
    check('氏名を持ち越す', '和山 正人', $result['nodes'][0]['occupant_name']);
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
        km_app_map_node_id($uuid) => ['name' => '古い名前', 'occupant_name' => '和山 正人'],
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
    check('氏名が戻る', '和山 正人', $backMap->nodes[0]->occupantName);
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
        'n_new_10' => ['name' => 'トレーナー室 管-104', 'occupant_name' => '和山 正人'],
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
    // **開始は入れない。** Website 側で「いま有効」と判定済みのものだけが来る
    check_bool('開始は入れない', $event['startAtMillis'] === null);

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
    $_SESSION = ['km_map_unlocked' => true];
    check_bool('一般: パスワードを入れても hidden は見えない', !km_map_names_unlocked($cfg('hidden', 'public', true)));
    $_SESSION = $savedSession ?? [];

    $src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);
    $index = $src('index.php');
    check_bool('公開ページ: 開くたびに印を外してから立て直す', strpos($index, "unset(\$_SESSION['km_map_teacher_until']);") < strpos($index, "\$_SESSION['km_map_teacher_until'] = min("));
    check_bool('公開ページ: 止められたアカウントには印を立てない', str_contains($index, 'if (!km_logto_user_is_suspended((string) $claims->sub)) {'));
    check_bool('公開ページ: 印の期限は ID トークンの期限まで(最長 1 時間)', str_contains($index, 'min((int) $claims->exp, time() + 3600)'));
    check_bool('サインアウトで印を外す', str_contains($src('sign-out.php'), "unset(\$_SESSION['km_map_teacher_until']);"));
    $appMap = $src('api/app-map.php');
    check_bool('アプリ: 教職員には閲覧不可の地点も配る', str_contains($appMap, 'if (!$isStaff && !$isTeacher) {'));
    check_bool('アプリ: hidden の切り替えは教職員だけ(イベント運営は対象外)', str_contains($src('lib/app-map.php'), "return \$isTeacher && \$config['teacherSeesHidden'];"));
    check_bool('設定: hidden の切り替えの既定は見せない(厳密に true のときだけ)', str_contains($src('lib/map-access.php'), "'teacherSeesHidden' => (\$config['teacherSeesHidden'] ?? false) === true,"));
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
    check_bool('取り込みで建物の階の座標系を壊さない', str_contains($sync, 'if (km_building_floor_is($floorId)) {'));

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
    check_bool('好みでない方は重くするだけ(外さない)', str_contains($dijkstra, 'const KM_VERTICAL_PENALTY = 4;'));
    check_bool('エレベーターは名前で見分ける(種類は階段と同じ)', str_contains($dijkstra, 'static isElevator(node)'));
    check_bool('英語表記も拾う', str_contains($dijkstra, "indexOf('elevator')"));
    check_bool('知らない値は指定なしに倒す', str_contains($dijkstra, "KM_VERTICAL_MODES.indexOf(vertical) >= 0 ? vertical : 'any'"));
    check_bool('検索パネルに選択がある', str_contains($src('index.php'), 'id="vertical-pref"'));
    check_bool('選んだ値は端末に覚える(サーバーへ送らない)', str_contains($app, "const KM_VERTICAL_KEY = 'km-route-vertical-v1';"));
    check_bool('選び直したら経路を引き直す', str_contains($app, 'rebuildDijkstra();'));

    km_check_heading('map: ラベルの重なりをほどく');
    check_bool('ずらしてから隠す', str_contains($app, 'const KM_LABEL_NUDGES = [0, -14, 14, -30, 30];'));
    check_bool('大事なものから順に置く', str_contains($app, "KM_LABEL_PRIORITY = { facility: 0, entrance: 1, stairs: 2"));
    check_bool('隠すのは文字だけ(点は残す)', str_contains($src('Main/styles.css'), '.km-label.km-label-hidden'));
    check_bool('編集画面では間引かない', str_contains($app, 'if (window.isAdmin) return;'));
    check_bool('測り直す前に前回の結果を戻す', strpos($app, "el.classList.remove('km-label-hidden');") < strpos($app, 'labels.sort('));

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
    $legal = $src('lib/legal.php');
    check_bool('プライバシーポリシーに担当の地点の編集がある', str_contains($legal, '**担当の地点の編集:**'));
    check_bool('プライバシーポリシー: 承認するまで反映しないと書いてある', str_contains($legal, '送った内容は**すぐには地図に反映されません。**'));
    check_bool('プライバシーポリシー: 提案の記録は退会で消えると書いてある', str_contains($legal, '担当の地点の割り当てと変更の提案の記録が同時に削除されます'));
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
    check('錠は web と app', ['web', 'app'], array_keys(KM_MAP_UNLOCK_SCOPES));
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
    $download = $code($read('admin/api/file-download.php'));
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
    check_bool('api は haveMapId を渡して比べる', str_contains($appMapApi, 'km_app_map_is_up_to_date($slug, $haveMapId, $haveRevision, $revision)'));

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
        'LOGTO_ENDPOINT' => 'LOGTO_ENDPOINT: ${LOGTO_ENDPOINT:-https://${KM_DOMAIN}:3001}',
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
    foreach (['max_execution_time = 30', 'default_socket_timeout = 15', 'session.use_strict_mode = 1', 'session.use_only_cookies = 1', 'session.use_trans_sid = 0'] as $iniLine) {
        if (preg_match('/^' . preg_quote($iniLine, '/') . '$/m', $limitsIni) !== 1) {
            $iniMissing[] = $iniLine;
        }
    }
    check('99-limits.ini の値', [], $iniMissing);
    check_bool('99-limits.ini は memory_limit を変えない', !str_contains($limitsIni, 'memory_limit'));
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
    foreach (['443 ssl' => 100, '3001 ssl' => 100, '6001 ssl' => 50, '8281 ssl' => 50, '3002 ssl' => 50, '8025 ssl' => 50] as $listen => $limit) {
        if (!str_contains($server($listen), "limit_conn km_conn {$limit};")) {
            $connLimits[] = $listen;
        }
    }
    check('各 server の limit_conn(443・3001 は 100、他は 50)', [], $connLimits);
    // `${KM_APP_PORT}` の `}` で location の中身を読む `[^}]*` が途切れないよう、置換の印を先に潰す
    $https = (string) preg_replace('/\$\{[A-Z_]+\}/', 'VAR', $server('443 ssl'));
    preg_match('/location\s+~\*?\s+(\S+)/', $https, $firstRegex);
    // 正規表現の location は書いた順に評価される。後ろに置くと完全一致の回数制限や include 専用の 404 を素通りする
    check('443 の最初の正規表現は `.php/` の 404', '\.php/', $firstRegex[1] ?? null);
    check_bool('include 専用の 404 は `\.php(/|$)`', str_contains($https, 'logto-client)\.php(/|$) {'));
    check_bool('proxy-web.conf に proxy_read_timeout を書かない(location で重複すると起動しない)', !str_contains((string) preg_replace('/^\s*#.*$/m', '', $file('nginx/km/proxy-web.conf')), 'proxy_read_timeout'));
    check('443 で 300s を許すのは downloads.php だけ', 1, substr_count($https, 'proxy_read_timeout 300s;'));
    check_bool('未認証で重い口を km_api で絞る(メソッドを問わない)', str_contains($https, 'location ~ ^/(api/(app-stats|app-avatar|floor-image|download|map-data)|logto_me)\.php$ {') && str_contains($https, 'limit_req zone=km_api burst=120 nodelay;'));
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
    foreach (['3001', '3002'] as $port) {
        $logtoServer = $server("{$port} ssl");
        check_bool("{$port}: X-Forwarded-For は送信元だけ", str_contains($logtoServer, 'proxy_set_header X-Forwarded-For $remote_addr;') && !str_contains($logtoServer, '$proxy_add_x_forwarded_for'));
        check_bool("{$port}: Logto の HSTS を隠す", str_contains($logtoServer, 'proxy_hide_header Strict-Transport-Security;'));
    }
    check_bool('6001: 管理画面以外の Origin を断る(server-authz 4)', str_contains($server('6001 ssl'), 'if ($km_ws_origin_denied)'));
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
        'phpmyadmin' => ['edge', 'appdb'], 'web' => ['edge', 'appdb', 'mail'], 'reverse-proxy' => ['edge'],
        'soketi' => ['edge'], 'mailpit' => ['edge', 'mail'],
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
    check_bool('6001: 配信の HTTP API(/apps/)を外に出さない', preg_match('#location \^~ /apps/ \{\s*return 404;#', $server('6001 ssl')) === 1);
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
    check_bool('cron は版 6', preg_match('/^CRON_VERSION=6$/m', $setup) === 1);
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
    check_bool('app.js も同じ幅で判断する', str_contains($appJs, "matchMedia('(max-width: 768px)')"));
    check_bool('panel.js も同じ幅で判断する', str_contains($panelJs, "matchMedia('(max-width: 768px)')"));

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
    check_bool('トップバーには置かない', $headerEnd !== false && $toggle !== false && $toggle > $headerEnd);
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
    check_bool('受け皿は空で置く', str_contains($index, '<div id="km-auth-slot"></div>'));
    check_bool('動かす側がある', str_contains($index, 'id="km-auth-links"'));
    check_bool('panel.js が入れ替える', str_contains($panelJs, "getElementById('km-auth-links')"));

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
    // display:flex は hidden 属性に勝つ。隠したつもりの帯が地図を塞がないように
    check_bool('隠した帯は消える', preg_match('/\.km-route-bar\[hidden\]\s*\{\s*display:\s*none/', $css) === 1);
    /*
     * **ほかの操作を隠さない。** 右は階のレール(とその下のズーム)を避け、
     * 下は左下の操作ピルの上に置く。帯が出ている間、左下から出る面は帯の上へ逃げる。
     */
    check_bool('帯は階のレールを避ける', preg_match('/\.km-route-bar\s*\{[^}]*var\(--km-rail-width\)/s', $css) === 1);
    check_bool('帯は操作ピルの上', preg_match('/\.km-route-bar\s*\{[^}]*bottom:\s*calc\(var\(--km-bottom\) \+ var\(--km-touch\)/s', $css) === 1);
    check_bool('表示調整は帯の上へ逃げる', str_contains($css, '.km-tuning { margin-bottom: var(--km-route-lift); }'));
    check_bool('収まりの計算が帯を数える', str_contains($appJs, "getElementById('km-route-bar')"));
    check_bool('経路は帯とパネルを避けて収める', str_contains($appJs, 'fitBounds(pathBoundsGroup.getBounds(), mapFitPadding())'));

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

    km_check_heading('layout: ラベルの重なりは屋外だけ(2026-09-25)');
    check_bool('屋内では間引かない', str_contains($appJs, "if (String(window.currentFloor) !== 'outside') return;"));
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
        check_bool('アプリも屋外だけ間引く', str_contains((string) file_get_contents(dirname($renderer) . '/MapScreen.kt'), 'declutterLabels = currentFloor == OUTSIDE_FLOOR'));
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
    check_bool('壊れた値はつまみの範囲へ丸める', str_contains($styleJs, 'Math.min(200, Math.max(50, raw))'));
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
    // 重みはアプリと同じ(20m / 3m を px/m の既定 10 で画素に直した値)
    check_bool('階の移動は 200px', str_contains($dijkstra, 'KM_FLOOR_TRANSFER_PX = 200'));
    check_bool('屋内外の出入りは 30px', str_contains($dijkstra, 'KM_ENTRANCE_TRANSFER_PX = 30'));
    // 0 にすると階の移動がタダになり、少し歩けば済む場面で階段が選ばれる
    check_bool('乗り換えをタダにしない', !str_contains($dijkstra, 'KM_FLOOR_TRANSFER_PX = 0'));
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

if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)
    || in_array('--list', $arguments, true)) {
    echo "使い方: php src/scripts/check.php [名前 …]\n\n";
    echo "  引数なし … 純粋な検査をすべて走らせる\n\n";
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

foreach ($selected as $name) {
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
