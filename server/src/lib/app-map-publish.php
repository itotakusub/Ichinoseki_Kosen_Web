<?php

declare(strict_types=1);

/**
 * DB の地図を、アプリへ配る形にして置く。admin/map-publish.php が使う。
 *
 * ## 手元のスクリプトから移した(2026-09-03)
 *
 * それまでは `scripts/new-map-release.ps1` が
 *
 *   1. 書き出し JSON を `uploads/` へ置き
 *   2. アクセスコードを作り
 *   3. `config/app-map.local.php` を書き換え
 *   4. QR を作り
 *   5. **SSH で本番へ直接書き込む**(`docker run --rm alpine` を root で)
 *
 * をやっていた。**配信のたびに手元の PowerShell と SSH が要る**状態で、
 * 「地図を配る」が管理画面から見えなかった。
 *
 * 利用者の指示で**全部 Website 一括**にする。5 の SSH は要らなくなる ——
 * 本番の管理画面で操作するので、置く先が最初からそこにある。
 *
 * ## 配る JSON は「作る」もので、「上げる」ものではない
 *
 * `uploads/app-map-<slug>.json` は**生成物**になった。正本は DB。
 * 上げた JSON をそのまま配ると、**画面で直した内容と配ったものが食い違う。**
 *
 * ## アクセスコードの持ち方
 *
 * 照合に使うのは `password_hash()` の出力(`hash`)だけ。
 * 平文(`code`)も並べて持つが、**それは管理画面で伏せて出すためだけ**
 * (利用者の要望で 2026-09-03 に入れた「アクセスコードを見る(隠しあり)」)。
 *
 * **平文が読めなくても配信は動く。** 古い設定には `code` が無く、
 * そのときは画面が「保存していません」と出す。照合は `hash` しか見ない。
 */

require_once __DIR__ . '/app-map.php';
require_once __DIR__ . '/app-map-convert.php';
require_once __DIR__ . '/db.php';

/**
 * アクセスコードに使う文字。**紛らわしいものを外してある。**
 *
 * `0`/`O`、`1`/`I`/`l` は、紙に印刷したものを手で打つと必ず取り違える。
 * QR が読めない端末のために手入力を残す以上、ここは削る側でよい
 * (`scripts/new-map-release.ps1` と同じ並び)。
 */
const KM_APP_MAP_CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

/** 既定の長さ。32 文字から 8 桁 = 40 bit。総当たりは公開側の回数制限で止める。 */
const KM_APP_MAP_CODE_LENGTH = 8;

/**
 * アクセスコードを作る。**`random_int` を使う**(`rand` は予測できる)。
 */
function km_app_map_generate_code(int $length = KM_APP_MAP_CODE_LENGTH): string
{
    $alphabet = KM_APP_MAP_CODE_ALPHABET;
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }

    return $code;
}

/**
 * いまの版から次の版へ。**必ず増やす。**
 *
 * アプリは `revision` が大きいときだけ受け取る(`shouldApplyPackage`)。
 * 据え置くと、**配信したのに端末が更新されない**——
 * しかも端末側は「最新です」と言うので、配り忘れと区別がつかない。
 */
function km_app_map_next_revision(array $config, string $slug): int
{
    $current = (int) ($config['maps'][$slug]['revision'] ?? 0);

    return $current + 1;
}

/**
 * DB から配信用の JSON を作って置き、設定を書き換える。
 *
 * ## 出せないものがあれば、置く前に止める
 *
 * 知らない種類・知らない階のノードがあると、そのノードは配信から落ちる。
 * **落ちたことは端末側では分からない**(その地点が無いだけ)。
 * 呼ぶ側が `skipped` を見て、続けるかどうかを決められるように返す。
 *
 * ## イベントは配信のときに差し込む
 *
 * 通行止め・臨時の地点は **Website の管理画面が正本**(`admin/map-events.php`)。
 * ここで地図へ折り込んでからファイルにする。
 *
 * **会期のイベントが無ければ空で上書きする。** 前回差し込んだものを残すと、
 * **終わった通行止めが効き続ける** —— 通れる道を「通れません」と言い続ける。
 *
 * @return array{revision:int, bytes:int, nodes:int, lines:int, fingerprints:int,
 *               events:int, activeEventUuid:?string, eventSkipped:array<string, mixed>,
 *               skipped:array<string, mixed>, config:array}
 */
function km_app_map_publish(
    PDO $pdo,
    array $config,
    string $slug,
    string $expiresAt,
    bool $withChecksum = true
): array {
    $built = km_app_map_from_web($pdo);
    $map = $built['map'];

    if ($map->nodes === []) {
        /*
         * **空の地図を配らない。** 端末は revision が大きければ受け取るので、
         * 空を配ると全端末の地図が空になり、**元には戻せない**
         * (次の配信まで、来場者の手元には何も無い)。
         */
        throw new KmUserError('地点が1件もありません。配信を中止しました。');
    }

    /*
     * イベントを折り込む。**新しく書かない** ——
     * `scripts/publish-events.php` がやっていたことと同じ2つを呼ぶ。
     *
     * ここを繋ぎ忘れていた(2026-09-03)。配信を Website 一括にしたとき、
     * 差し込みだけが別スクリプトに取り残され、**管理画面から配信すると
     * 通行止めも臨時の地点も端末に届かない**状態になっていた。
     */
    require_once __DIR__ . '/map-events.php';
    $overlay = km_map_event_overlay($pdo);
    $events = km_app_map_events_from_web($overlay, $map);
    km_app_map_replace_events($map, $events['events']);

    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('地図を JSON にできませんでした。');
    }

    $fileName = 'app-map-' . $slug . '.json';
    $dir = km_app_map_storage_dir($config);
    $path = $dir . DIRECTORY_SEPARATOR . $fileName;

    /*
     * **書けたことを確かめてから設定を進める。**
     * 設定だけ新しい版になってファイルが古いままだと、
     * 端末は「新しい版が来た」と受け取って**古い地図**を保存する。
     */
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        // 置き場の絶対パスはログへ。画面には打つ手だけを出す
        error_log('km_app_map_publish: 書き込めませんでした: ' . $path);
        throw new KmUserError(
            '配信用のファイルを書き込めませんでした。ホストで次を実行してください: '
                . 'docker compose exec -u root web chown -R www-data uploads'
        );
    }

    $revision = km_app_map_next_revision($config, $slug);
    $config['maps'][$slug] = [
        'file' => $fileName,
        'revision' => $revision,
        'expiresAt' => $expiresAt,
        /*
         * **差し込んだ結果から決める。人に入力させない。**
         * 手で打たせると、打ち間違えたときに端末が「そのイベントは無い」状態になり、
         * しかも**配信は成功したように見える。**
         */
        'activeEventUuid' => $events['activeEventUuid'],
        'checksum' => $withChecksum,
        // 配信し直したら止まっていた状態は解く(止めたまま配るのは間違いのもと)
        'paused' => false,
    ];

    km_app_map_write_config($config);

    return [
        'revision' => $revision,
        'bytes' => strlen($json),
        'nodes' => count($map->nodes),
        'lines' => count($map->lines),
        'fingerprints' => count($map->fingerprints),
        'events' => count($events['events']),
        'activeEventUuid' => $events['activeEventUuid'],
        // 対応づけられなかった通行止め。**黙って落とさない**
        'eventSkipped' => $events['skipped'],
        'skipped' => $built['skipped'],
        'config' => $config,
    ];
}

/** 添付できるイベント用の地図の大きさ。実データは 0.36MB。**桁が違うものは中身を見る前に断る。** */
const KM_APP_MAP_EVENT_MAX_BYTES = 10 * 1024 * 1024;

/**
 * 配信用のファイルを置く。**書けたことを確かめてから**ファイル名を返す。
 * km_app_map_publish() と同じ守り方(設定だけ新しくてファイルが古い、を作らない)。
 */
function km_app_map_write_release_file(array $config, string $slug, string $json): string
{
    $fileName = 'app-map-' . $slug . '.json';
    $path = km_app_map_storage_dir($config) . DIRECTORY_SEPARATOR . $fileName;
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        error_log('km_app_map_write_release_file: 書き込めませんでした: ' . $path);
        throw new KmUserError(
            '配信用のファイルを書き込めませんでした。ホストで次を実行してください: '
                . 'docker compose exec -u root web chown -R www-data uploads'
        );
    }

    return $fileName;
}

/**
 * 添付された JSON を、配ってよい地図として読む。**置く前に読む。**
 *
 * 断る理由は利用者に見せてよい文なので KmUserError で投げる。
 */
function km_app_map_decode_event_upload(string $raw): stdClass
{
    if ($raw === '') {
        throw new KmUserError('ファイルが空です。');
    }
    if (strlen($raw) > KM_APP_MAP_EVENT_MAX_BYTES) {
        throw new KmUserError('ファイルが大きすぎます(上限 ' . (KM_APP_MAP_EVENT_MAX_BYTES / 1024 / 1024) . 'MB)。');
    }
    $map = km_app_map_decode_snapshot($raw);
    if ($map === null) {
        throw new KmUserError('管理アプリの書き出し(kosenmap-map)として読めませんでした。');
    }
    if (!is_array($map->nodes) || !is_array($map->lines)) {
        throw new KmUserError('地点と経路を読めませんでした。別のファイルではありませんか。');
    }
    /*
     * **空の地図を配らない。** 端末は版が上がれば受け取るので、空を配ると
     * そのコードを入れた全端末の地図が空になる(km_app_map_publish() と同じ理由)。
     */
    if ($map->nodes === []) {
        throw new KmUserError('地点が1件もありません。配信を中止しました。');
    }
    /*
     * **項目はどれもオブジェクト。** 数値や文字列が混じったファイルも配れてしまうと、
     * 端末の読み込み(MapDataRepository.decode)で落ち、そのコードの全端末が地図を更新できなくなる。
     */
    foreach ([$map->nodes, $map->lines] as $items) {
        foreach ($items as $item) {
            if (!($item instanceof stdClass)) {
                throw new KmUserError('地点か経路に読めない項目があります。別のファイルではありませんか。');
            }
        }
    }

    return $map;
}

/**
 * 折り込まないときの「会期のイベント」。**人に入力させない**(打ち間違いで効かなくなる)。
 *
 * - イベントが1つだけ … それ
 * - 複数 … 始まりの早い順に見て、まだ終わっていない最初のもの
 * - 無い・全部終わっている … null(通常の地図として扱われる)
 */
function km_app_map_pick_event_uuid(stdClass $map, ?DateTimeImmutable $now = null): ?string
{
    $events = [];
    foreach ((is_array($map->events ?? null) ? $map->events : []) as $event) {
        if ($event instanceof stdClass && trim((string) ($event->uuid ?? '')) !== '') {
            $events[] = $event;
        }
    }
    if (count($events) === 1) {
        return (string) $events[0]->uuid;
    }

    $nowMillis = ($now ?? new DateTimeImmutable('now'))->getTimestamp() * 1000;
    usort($events, static fn ($a, $b) => (int) ($a->startAtMillis ?? 0) <=> (int) ($b->startAtMillis ?? 0));
    foreach ($events as $event) {
        $end = $event->endAtMillis ?? null;
        if (!is_numeric($end) || (int) $end >= $nowMillis) {
            return (string) $event->uuid;
        }
    }

    return null;
}

/**
 * **イベント用の正本**を配る。添付された JSON を検証して置き、kosen-event の版を上げる。
 *
 * ## Website の正本とは別に持つ
 *
 * 会期だけの地図(臨時の地点・模擬店の配置など)を、**Website の地図を書き換えずに**配るためのもの。
 * どちらを受け取るかはアクセスコードで決まる。版は配信 ID ごとに数える。
 *
 * ## Website のイベントを折り込むかは選ばせる(既定はしない)
 *
 * 添付する地図は管理アプリで作ったもので、**イベントもそちらで作ってあることが多い。**
 * 既定で折り込むと、アプリで作った通行止めが Website の内容(多くは空)で消える。
 * 折り込むときは DB が要る。
 *
 * @return array{revision:int, bytes:int, nodes:int, lines:int, fingerprints:int,
 *               events:int, activeEventUuid:?string, folded:bool,
 *               eventSkipped:array<string, mixed>, skipped:array<string, mixed>, config:array}
 */
function km_app_map_publish_event(
    ?PDO $pdo,
    array $config,
    string $raw,
    string $expiresAt,
    bool $withChecksum = true,
    bool $foldWebEvents = false,
    string $sourceName = ''
): array {
    $slug = KM_APP_MAP_EVENT_SLUG;
    $map = km_app_map_decode_event_upload($raw);

    $eventSkipped = ['unknownNode' => 0, 'edgeAcrossFloors' => 0, 'unknownFloor' => []];
    if ($foldWebEvents) {
        if ($pdo === null) {
            throw new KmUserError('データベースに接続できないため、Website のイベントを折り込めません。');
        }
        require_once __DIR__ . '/map-events.php';
        $events = km_app_map_events_from_web(km_map_event_overlay($pdo), $map);
        km_app_map_replace_events($map, $events['events']);
        $activeEventUuid = $events['activeEventUuid'];
        $eventSkipped = $events['skipped'];
    } else {
        $activeEventUuid = km_app_map_pick_event_uuid($map);
    }

    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new KmUserError('地図を JSON にできませんでした。');
    }

    $fileName = km_app_map_write_release_file($config, $slug, $json);

    $revision = km_app_map_next_revision($config, $slug);
    $config['maps'][$slug] = [
        'file' => $fileName,
        'revision' => $revision,
        'expiresAt' => $expiresAt,
        'activeEventUuid' => $activeEventUuid,
        'checksum' => $withChecksum,
        'paused' => false,
        // 画面に出すための控え。**配信には使わない**(api/app-map.php は読まない)
        'sourceName' => mb_substr($sourceName, 0, 120),
        'foldWebEvents' => $foldWebEvents,
    ];

    km_app_map_write_config($config);

    return [
        'revision' => $revision,
        'bytes' => strlen($json),
        'nodes' => count($map->nodes),
        'lines' => count($map->lines),
        'fingerprints' => is_array($map->fingerprints ?? null) ? count($map->fingerprints) : 0,
        'events' => is_array($map->events ?? null) ? count($map->events) : 0,
        'activeEventUuid' => $activeEventUuid,
        'folded' => $foldWebEvents,
        'eventSkipped' => $eventSkipped,
        // 添付した地図は変換しないので、落ちるものは無い
        'skipped' => [],
        'config' => $config,
    ];
}

/**
 * アクセスコードを1つ足す。
 *
 * 同じ地図に複数のコードを持てる(配る相手ごとに分ける・古いものを止める)。
 *
 * `hash` が照合に使うもの、`code` は**管理画面で伏せて出すため**の控え
 * (`km_app_map_release_report()` がこちらを読む)。
 *
 * ## lookup も一緒に置く(2026-09-14)
 *
 * `$secret`(km_app_secret($pdo, KM_APP_MAP_CODE_SECRET))を渡すと、鍵つきの印も保存する。
 * api/app-map.php はまずこれで引くので、**当たりの照合に bcrypt を回さない。**
 * 渡さなければ古い形(hash だけ)になる —— 照合はできるが、外れの回数制限を受ける。
 *
 * @return array{config:array, code:string}
 */
function km_app_map_add_code(array $config, string $slug, ?string $secret = null): array
{
    $code = km_app_map_generate_code();
    $normalized = km_app_map_normalize_code($code);
    $entry = [
        'slug' => $slug,
        'hash' => password_hash($normalized, PASSWORD_DEFAULT),
        'code' => $normalized,
    ];
    if ($secret !== null) {
        $lookup = km_app_map_code_lookup($secret, $normalized);
        $entry['lookup'] = $lookup['lookup'];
        $entry['lookupKey'] = $lookup['keyId'];
    }
    $config['codes'][] = $entry;

    return ['config' => $config, 'code' => $normalized];
}

/**
 * アクセスコードを1つ消す。**残り1つでも消せる** ——
 * 配り終えた会期のコードを止めたい場面があるため。
 *
 * `$index` は `config['codes']` の並び順。画面が出した番号をそのまま受ける。
 */
function km_app_map_remove_code(array $config, int $index): array
{
    if (!isset($config['codes'][$index])) {
        return $config;
    }
    unset($config['codes'][$index]);
    // 並びを詰める。**var_export で連番のまま書き出す**ため
    $config['codes'] = array_values($config['codes']);

    return $config;
}

/**
 * QR に載せる文字列。アプリの読み取りが期待する形。
 *
 * `MapPackage.kt` の `KOSENMAP1:` を前置きにする。**前置きが無いと、
 * ただの文字列として読まれて何も起きない。**
 */
function km_app_map_qr_payload(string $code): string
{
    return 'KOSENMAP1:' . km_app_map_normalize_code($code);
}
