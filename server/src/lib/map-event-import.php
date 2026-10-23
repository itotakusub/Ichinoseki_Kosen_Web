<?php

declare(strict_types=1);

/**
 * 管理アプリで作ったイベントを Website へ取り込む(2026-10-14、利用者の指示)。admin/map-events.php が使う。
 *
 * ## 向き
 *
 * 2026-09-02 の分担では、イベントは **Website の管理画面が正本**だった(lib/app-map-convert.php の
 * km_app_map_events_from_web が Web → アプリへ写す)。今回の会期は**アプリを配らず**、来場者はブラウザだけ。
 * そのうえで利用者の指示で「ノードの設定は Android で作る」ことになった。
 * そこで**アプリで作ったイベントだけ**この向き(アプリ → Web)で入れる。Website の画面で作ったイベントは今のまま。
 *
 * ## 渡し方はファイル(利用者が決めた)
 *
 * 管理アプリの「地図の書き出し」(kosenmap-map。全部の書き出しも可)をそのまま上げる。中の `map.events` を読む。
 * 地図の取り込み(lib/app-map-sync.php)と同じく、**一度置いて、差分を見せてから**入れる。
 *
 * ## 入れ方
 *
 * - アプリのイベントの uuid で突き合わせる(km_map_event_sources)。同じものがあれば**中身を丸ごと入れ替える**
 * - 新しいイベントは**無効のまま**作る。ON/OFF・期間・案内の文は Website の画面だけで変える(利用者が決めた)
 * - ノードは DB に在るものだけ。無いもの(アプリで足したが、地図をまだ取り込んでいない)は飛ばして数える
 *
 * ## ここは DB を触る関数と触らない関数を分ける
 *
 * 変換([km_map_event_from_app])は純粋関数にして、scripts/check.php が DB 無しで試す。
 */

require_once __DIR__ . '/app-map-convert.php';
require_once __DIR__ . '/map-events.php';

/** 取り込み待ちの置き場。地図の取り込み待ち(app-map-import.json)とは別名にする */
const KM_MAP_EVENT_IMPORT_FILE = 'app-event-import.json';

/** 上げてよい大きさ。地図の取り込みと同じ(実データは地図ごとで 0.4MB 前後) */
const KM_MAP_EVENT_IMPORT_MAX_BYTES = 20 * 1024 * 1024;

/**
 * アプリの一時地点の種別(自由な文字)→ Website の種別。**言葉で寄せる。**
 * Web → アプリの対応(KM_APP_MAP_POI_CATEGORY_LABELS)と同じ言葉を先に並べる。寄せられなければ other。
 */
const KM_MAP_EVENT_IMPORT_CATEGORY_WORDS = [
    'food' => ['模擬店', '屋台', '食', 'カフェ', '喫茶'],
    'exhibit' => ['展示', '発表', 'ポスター'],
    'reception' => ['受付', '案内所', 'インフォメーション'],
    'firstaid' => ['救護', '保健'],
    'toilet' => ['トイレ', 'お手洗い', 'WC'],
    'stage' => ['ステージ', '舞台', 'ライブ'],
];

/** 臨時の地点を経路に繋ぐとき、繋いでよいノードの種類(壁・Wi-Fi ルーターには繋がない) */
const KM_MAP_EVENT_IMPORT_ANCHOR_TYPES = ['road', 'room', 'entrance', 'facility', 'stairs'];

/** 取り込み待ちファイルの置き場所。設定が壊れていれば null。 */
function km_map_event_import_path(): ?string
{
    $config = km_app_map_config();
    $dir = km_app_map_storage_dir($config);

    return $dir === '' ? null : rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . KM_MAP_EVENT_IMPORT_FILE;
}

/**
 * 上げられた書き出しから、イベントだけを置き場へ移す。**中身を確かめてから。**
 *
 * @return ?string 断った理由。受け付けたら null
 */
function km_map_event_import_store(string $tmpPath, int $size): ?string
{
    if ($size <= 0) {
        return 'ファイルが空です。';
    }
    if ($size > KM_MAP_EVENT_IMPORT_MAX_BYTES) {
        return 'ファイルが大きすぎます(上限 ' . (string) (KM_MAP_EVENT_IMPORT_MAX_BYTES / 1024 / 1024) . 'MB)。';
    }
    $raw = @file_get_contents($tmpPath);
    if (!is_string($raw)) {
        return 'ファイルを読めませんでした。';
    }
    $map = km_app_map_decode_snapshot($raw);
    if ($map === null) {
        return '管理アプリの書き出し(kosenmap-map)として読めませんでした。';
    }
    $events = km_map_event_import_events_of($map);
    if ($events === []) {
        return 'このファイルにはイベントが入っていません。管理アプリでイベントを作ってから書き出してください。';
    }

    $path = km_map_event_import_path();
    if ($path === null) {
        return '置き場所の設定を読めませんでした。';
    }
    // 置くのはイベントだけ(地図の本体は要らない。氏名も置き場に残さない)
    $payload = json_encode(
        ['uploadedAt' => date('Y-m-d H:i:s'), 'events' => $events],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($payload === false || @file_put_contents($path, $payload) === false) {
        return '置き場所へ書き込めませんでした。';
    }

    return null;
}

/**
 * 書き出しの中のイベント。uuid と名前の無いものは数えない。
 *
 * @return array<int, stdClass>
 */
function km_map_event_import_events_of(stdClass $map): array
{
    $events = [];
    foreach (is_array($map->events ?? null) ? $map->events : [] as $event) {
        if (!($event instanceof stdClass)) {
            continue;
        }
        if (trim((string) ($event->uuid ?? '')) === '' || trim((string) ($event->name ?? '')) === '') {
            continue;
        }
        $events[] = $event;
    }

    return $events;
}

/**
 * 取り込み待ち。無ければ events が空。
 *
 * @return array{events: array<int, stdClass>, uploadedAt: ?string, error: ?string}
 */
function km_map_event_import_load(): array
{
    $path = km_map_event_import_path();
    if ($path === null || !is_readable($path)) {
        return ['events' => [], 'uploadedAt' => null, 'error' => null];
    }
    $decoded = json_decode((string) file_get_contents($path));
    if (!($decoded instanceof stdClass) || !is_array($decoded->events ?? null)) {
        return ['events' => [], 'uploadedAt' => null, 'error' => '取り込み待ちのファイルを読み取れません。捨ててから上げ直してください。'];
    }

    return [
        'events' => km_map_event_import_events_of($decoded),
        'uploadedAt' => is_string($decoded->uploadedAt ?? null) ? $decoded->uploadedAt : null,
        'error' => null,
    ];
}

/** 取り込み待ちを捨てる。無ければ何もしない。 */
function km_map_event_import_discard(): void
{
    $path = km_map_event_import_path();
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

/** アプリの一時地点の種別 → Website の種別。 */
function km_map_event_import_category(string $appCategory): string
{
    $text = trim($appCategory);
    if ($text === '') {
        return 'other';
    }
    foreach (KM_MAP_EVENT_IMPORT_CATEGORY_WORDS as $category => $words) {
        foreach ($words as $word) {
            if (mb_stripos($text, $word) !== false) {
                return $category;
            }
        }
    }

    return 'other';
}

/** アプリの時刻(ミリ秒)→ DB の DATETIME。無ければ null。 */
function km_map_event_import_datetime(mixed $millis): ?string
{
    if (!is_numeric($millis)) {
        return null;
    }

    return date('Y-m-d H:i:s', intdiv((int) $millis, 1000));
}

/**
 * アプリのイベント 1 件を Website の重ね合わせの形へ直す。**DB は触らない。**
 *
 * @param stdClass $event アプリの MapEvent(書き出しの map.events の 1 件)
 * @param array<string, array{floor:string, type:string, x:float, y:float}> $knownNodes DB に在るノード(id => …)
 * @return array{
 *   uuid: string, name: string, startsAt: ?string, endsAt: ?string,
 *   closedEdges: array<int, array{0:string, 1:string}>,
 *   closedNodes: array<int, string>,
 *   pois: array<int, array{floor:string, name:string, category:string, x:int, y:int, anchorNodeId:?string, note:string}>,
 *   aliases: array<string, string>,
 *   hidden: array<int, string>,
 *   onlyEventNodes: bool,
 *   skipped: array{unknownNode:int, unknownFloor:array<int, string>, staffOnly:int, badLineKey:int}
 * }
 */
function km_map_event_from_app(stdClass $event, array $knownNodes): array
{
    $skipped = ['unknownNode' => 0, 'unknownFloor' => [], 'staffOnly' => 0, 'badLineKey' => 0];
    // uuid → DB の id。**DB に在るものだけ**(在るかどうかで取り込めるかが決まる)
    $toId = static function (string $uuid) use ($knownNodes): ?string {
        $id = km_app_map_node_id($uuid);
        return $id !== '' && isset($knownNodes[$id]) ? $id : null;
    };
    $setOf = static fn (mixed $value): array => is_array($value)
        ? array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $s): bool => trim($s) !== '')))
        : [];

    // --- 通行止め(線)。キーは「階:uuidA:uuidB」(アプリの eventLineKey) ---
    $closedEdges = [];
    foreach ($setOf($event->closedLineKeys ?? null) as $key) {
        $parts = explode(':', $key);
        if (count($parts) !== 3) {
            $skipped['badLineKey']++;
            continue;
        }
        $from = $toId($parts[1]);
        $to = $toId($parts[2]);
        if ($from === null || $to === null) {
            $skipped['unknownNode']++;
            continue;
        }
        $pair = [$from, $to];
        sort($pair);
        $closedEdges[$pair[0] . "\t" . $pair[1]] = $pair;
    }

    // --- 通行止め(ノード)。階ごとの通行止めは、その階のノードを全部閉じる形に広げる ---
    $closedNodes = [];
    foreach ($setOf($event->closedNodeUuids ?? null) as $uuid) {
        $id = $toId($uuid);
        if ($id === null) {
            $skipped['unknownNode']++;
            continue;
        }
        $closedNodes[$id] = true;
    }
    foreach ($setOf($event->closedFloors ?? null) as $appFloor) {
        $webFloor = km_app_map_floor_id($appFloor);
        if ($webFloor === null) {
            $skipped['unknownFloor'][] = $appFloor;
            continue;
        }
        foreach ($knownNodes as $id => $node) {
            if ($node['floor'] === $webFloor) {
                $closedNodes[(string) $id] = true;
            }
        }
    }

    // --- 名前の上書き(イベント中は置き換える)と、出さない地点 ---
    $aliases = [];
    $overrides = $event->nameOverrides ?? null;
    foreach (($overrides instanceof stdClass ? get_object_vars($overrides) : []) as $uuid => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $id = $toId((string) $uuid);
        if ($id === null) {
            $skipped['unknownNode']++;
            continue;
        }
        $aliases[$id] = mb_substr($name, 0, 255);
    }
    $hidden = [];
    foreach ($setOf($event->hiddenNodeUuids ?? null) as $uuid) {
        $id = $toId($uuid);
        if ($id === null) {
            $skipped['unknownNode']++;
            continue;
        }
        $hidden[$id] = true;
    }

    // --- 一時地点 → 臨時の地点 ---
    // アプリで道へつないだ線(2026-10-23。MapEvent.placeLinks)。あれば、それを案内先(つなぎ先)にする
    $linkedTo = [];
    // **版 22 のアプリは項目名を a / b で書き出していた**(R8 に縮められた。2026-10-23 に見つけた)。それも読む
    foreach (is_array($event->placeLinks ?? null) ? $event->placeLinks : [] as $link) {
        if (!($link instanceof stdClass)) {
            continue;
        }
        $placeUuid = $link->placeUuid ?? $link->a ?? null;
        $nodeUuid = $link->nodeUuid ?? $link->b ?? null;
        if (is_string($placeUuid) && is_string($nodeUuid) && !isset($linkedTo[$placeUuid])) {
            $linkedTo[$placeUuid] = $toId($nodeUuid);
        }
    }
    $pois = [];
    foreach (is_array($event->places ?? null) ? $event->places : [] as $place) {
        if (!($place instanceof stdClass)) {
            continue;
        }
        /*
         * **スタッフ限定は入れない。** 公開ページは来場者が見るもので、Website には
         * 「スタッフだけに見せる」口が無い(本部・控室・搬入口を出さない)。
         */
        if (($place->staffOnly ?? false) === true) {
            $skipped['staffOnly']++;
            continue;
        }
        $name = trim((string) ($place->name ?? ''));
        $appFloor = (string) ($place->floor ?? '');
        $webFloor = km_app_map_floor_id($appFloor);
        if ($webFloor === null) {
            $skipped['unknownFloor'][] = $appFloor;
            continue;
        }
        if ($name === '') {
            continue;
        }
        $x = (float) ($place->x ?? 0);
        $y = km_app_map_flip_y((float) ($place->y ?? 0), km_app_map_floor_height($webFloor));
        $appCategory = trim((string) ($place->category ?? ''));
        $category = km_map_event_import_category($appCategory);
        $note = trim((string) ($place->note ?? ''));
        if ($category === 'other' && $appCategory !== '') {
            // 寄せられなかった種別の言葉は捨てずにメモへ
            $note = trim($appCategory . ' ' . $note);
        }
        $pois[] = [
            'floor' => $webFloor,
            'name' => mb_substr($name, 0, 255),
            'category' => $category,
            'x' => (int) round($x),
            'y' => (int) round($y),
            // つないだ先が同じ階にあればそれ、無ければ同じ階の一番近い通れる地点
            'anchorNodeId' => ($linked = $linkedTo[(string) ($place->uuid ?? '')] ?? null) !== null && ($knownNodes[$linked]['floor'] ?? null) === $webFloor
                ? $linked
                : km_map_event_import_anchor($knownNodes, $webFloor, $x, $y),
            'note' => mb_substr($note, 0, 255),
        ];
    }

    return [
        'uuid' => trim((string) $event->uuid),
        'name' => mb_substr(trim((string) $event->name), 0, 100),
        'startsAt' => km_map_event_import_datetime($event->startAtMillis ?? null),
        'endsAt' => km_map_event_import_datetime($event->endAtMillis ?? null),
        'closedEdges' => array_values($closedEdges),
        'closedNodes' => array_map('strval', array_keys($closedNodes)),
        'pois' => $pois,
        'aliases' => $aliases,
        'hidden' => array_map('strval', array_keys($hidden)),
        // イベントの地点だけを出す(2026-10-23。アプリの MapEvent.onlyEventNodes。無い・null は ON)
        'onlyEventNodes' => ($event->onlyEventNodes ?? null) !== false,
        'skipped' => $skipped,
    ];
}

/**
 * 臨時の地点をどのノードに繋ぐか。**同じ階で一番近い、人が通れるノード。**
 *
 * アプリの一時地点は経路に繋がっていない(アプリは描くときに部屋として扱う)。
 * Website の公開ページは「繋ぎ先のある地点だけ」を行き先の候補に出すので、繋がないと探せない。
 */
function km_map_event_import_anchor(array $knownNodes, string $webFloor, float $x, float $y): ?string
{
    /*
     * **道と階段を先に探す**(2026-10-23)。以前は部屋・出入口・建物も同じ重みで選び、1F のゴミ箱が女子トイレに、
     * 外のゴミ箱が体育館の出入口につながった(経路がその部屋・建物の中へ向かう)。道も階段も無い階でだけ、ほかの種類を使う
     */
    foreach ([['road', 'stairs'], KM_MAP_EVENT_IMPORT_ANCHOR_TYPES] as $types) {
        $best = null;
        $bestDistance = INF;
        foreach ($knownNodes as $id => $node) {
            if ($node['floor'] !== $webFloor || !in_array($node['type'], $types, true)) {
                continue;
            }
            $distance = ($node['x'] - $x) ** 2 + ($node['y'] - $y) ** 2;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string) $id;
            }
        }
        if ($best !== null) {
            return $best;
        }
    }

    return null;
}

/**
 * uuid が `web-event-<番号>` で、その番号の Website のイベントが在り、まだアプリの印が無ければ、その番号。ほかは null
 */
function km_map_event_import_web_event_id(PDO $pdo, string $uuid): ?int
{
    if (preg_match('/\Aweb-event-(\d{1,9})\z/', $uuid, $m) !== 1) {
        return null;
    }
    $id = (int) $m[1];
    $stmt = $pdo->prepare('SELECT 1 FROM km_map_events e WHERE e.id = ? AND NOT EXISTS (SELECT 1 FROM km_map_event_sources s WHERE s.event_id = e.id)');
    $stmt->execute([$id]);
    return $stmt->fetchColumn() !== false ? $id : null;
}

/** DB に在るノード(id => 階・種類・座標)。 */
function km_map_event_import_known_nodes(PDO $pdo): array
{
    $nodes = [];
    foreach ($pdo->query('SELECT id, floor_id, type, x, y FROM km_map_nodes')->fetchAll() as $row) {
        $nodes[(string) $row['id']] = [
            'floor' => (string) $row['floor_id'],
            'type' => (string) $row['type'],
            'x' => (float) $row['x'],
            'y' => (float) $row['y'],
        ];
    }

    return $nodes;
}

/**
 * 取り込む前の見せ物。イベントごとに、変換した結果と、いま Website にあるもの。
 *
 * @param array<int, stdClass> $events
 * @return array<int, array{converted: array, existing: ?array}>
 */
function km_map_event_import_preview(PDO $pdo, array $events): array
{
    km_map_events_ensure_tables($pdo);
    $known = km_map_event_import_known_nodes($pdo);
    $existingByUuid = [];
    foreach (km_map_events_all($pdo) as $row) {
        if (($row['appUuid'] ?? null) !== null) {
            $existingByUuid[(string) $row['appUuid']] = $row;
        }
    }

    $preview = [];
    foreach ($events as $event) {
        $converted = km_map_event_from_app($event, $known);
        // Website で作って渡したイベント(web-event-<番号>)は、その番号のイベントを入れ替える(km_map_event_import_apply と同じ)
        $existing = $existingByUuid[$converted['uuid']] ?? null;
        $webEventId = $existing === null ? km_map_event_import_web_event_id($pdo, $converted['uuid']) : null;
        if ($webEventId !== null) {
            foreach (km_map_events_all($pdo) as $row) {
                if ((int) $row['id'] === $webEventId) {
                    $existing = $row;
                }
            }
        }
        $preview[] = ['converted' => $converted, 'existing' => $existing];
    }

    return $preview;
}

/**
 * 1 件を取り込む。**中身は丸ごと入れ替える。** 1 回の取引で(途中で失敗したら全部戻す)。
 *
 * @return array{eventId:int, created:bool, converted:array}
 */
function km_map_event_import_apply(PDO $pdo, stdClass $event): array
{
    km_map_events_ensure_tables($pdo);
    $converted = km_map_event_from_app($event, km_map_event_import_known_nodes($pdo));
    if ($converted['uuid'] === '' || $converted['name'] === '') {
        throw new InvalidArgumentException('イベントの印か名前がありません。');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT event_id FROM km_map_event_sources WHERE app_uuid = ?');
        $stmt->execute([$converted['uuid']]);
        $found = $stmt->fetchColumn();
        /*
         * Website で作って管理アプリへ渡したイベント(uuid が web-event-<番号>。lib/map-event-export.php)は、
         * **その Website のイベントを更新する**(2026-10-23。以前は同じ名前のイベントがもう 1 つできた)。印もここで付ける
         */
        $webEventId = km_map_event_import_web_event_id($pdo, $converted['uuid']);
        if ($found === false && $webEventId !== null) {
            $pdo->prepare('INSERT INTO km_map_event_sources (event_id, app_uuid, imported_at) VALUES (?, ?, NOW())')
                ->execute([$webEventId, $converted['uuid']]);
            $found = $webEventId;
        }
        $created = $found === false;

        if ($created) {
            /*
             * **無効のまま作る。** ON にするのは Website の画面で(利用者が決めた)。
             * 期間は初めの 1 回だけアプリから写す。氏名は隠す(イベント中は外部の来場者が見る)。
             */
            $start = $converted['startsAt'];
            $end = $converted['endsAt'];
            if ($start !== null && $end !== null && $start >= $end) {
                $end = null;
            }
            $pdo->prepare(
                'INSERT INTO km_map_events
                    (name, starts_at, ends_at, is_enabled, hide_occupant_names, banner_text, banner_url, created_at, updated_at)
                 VALUES (?, ?, ?, 0, 1, NULL, NULL, NOW(), NOW())'
            )->execute([$converted['name'], $start, $end]);
            $eventId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO km_map_event_sources (event_id, app_uuid, imported_at) VALUES (?, ?, NOW())')
                ->execute([$eventId, $converted['uuid']]);
        } else {
            $eventId = (int) $found;
            // 名前はアプリに合わせる。ON/OFF・期間・案内の文は Website のもの(変えない)
            $pdo->prepare('UPDATE km_map_events SET name = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$converted['name'], $eventId]);
            $pdo->prepare('UPDATE km_map_event_sources SET imported_at = NOW() WHERE event_id = ?')->execute([$eventId]);
        }

        foreach (KM_MAP_EVENT_CHILD_TABLES as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE event_id = ?")->execute([$eventId]);
        }

        $edge = $pdo->prepare(
            "INSERT INTO km_map_event_closures (event_id, target_type, from_node_id, to_node_id, reason) VALUES (?, 'edge', ?, ?, NULL)"
        );
        foreach ($converted['closedEdges'] as [$from, $to]) {
            $edge->execute([$eventId, $from, $to]);
        }
        $node = $pdo->prepare(
            "INSERT INTO km_map_event_closures (event_id, target_type, node_id, reason) VALUES (?, 'node', ?, NULL)"
        );
        foreach ($converted['closedNodes'] as $id) {
            $node->execute([$eventId, $id]);
        }
        $poi = $pdo->prepare(
            'INSERT INTO km_map_event_pois (event_id, floor_id, name, category, x, y, anchor_node_id, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($converted['pois'] as $place) {
            $poi->execute([
                $eventId, $place['floor'], $place['name'], $place['category'], $place['x'], $place['y'],
                $place['anchorNodeId'], $place['note'] !== '' ? $place['note'] : null,
            ]);
        }
        $alias = $pdo->prepare('INSERT INTO km_map_event_aliases (event_id, node_id, alias) VALUES (?, ?, ?)');
        foreach ($converted['aliases'] as $id => $name) {
            $alias->execute([$eventId, (string) $id, $name]);
        }
        $hidden = $pdo->prepare('INSERT INTO km_map_event_hidden (event_id, node_id) VALUES (?, ?)');
        foreach ($converted['hidden'] as $id) {
            $hidden->execute([$eventId, $id]);
        }
        // 子の表と一緒に消したので、アプリの値で入れ直す
        $pdo->prepare('INSERT INTO km_map_event_options (event_id, only_event_nodes) VALUES (?, ?)')
            ->execute([$eventId, $converted['onlyEventNodes'] ? 1 : 0]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return ['eventId' => $eventId, 'created' => $created, 'converted' => $converted];
}
