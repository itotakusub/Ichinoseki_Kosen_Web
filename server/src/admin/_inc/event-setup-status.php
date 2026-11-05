<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/**
 * イベントのセットアップ(admin/event-setup.php。2026-11-03)の 10 の手順と、それぞれの印。
 *
 * **DB を使わない純粋関数**(scripts/check.php が見本の事実で試す)。事実はページが集めて渡す。
 * 印: ok = 済み(✓)/ warn = 足りない(!)/ info = 任意・見るだけ(―)。理由は画面にそのまま出す。
 *
 * 順番は「有効にする」(8)を「アプリへ配信」(9)の前に置く —— アプリへ配る地図に焼き込むのは
 * **有効なイベントだけ**(lib/map-events.php の km_map_event_overlay_for_app_build。開始前でも有効なら入る)。
 */

const KM_EVENT_SETUP_STEPS = [
    1 => '会期の日付と時間',
    2 => '地図に出すもの',
    3 => 'スケジュール',
    4 => '屋台・ポスター',
    5 => 'お知らせ',
    6 => '来場者の操作の記録',
    7 => '公開の錠',
    8 => '有効にする',
    9 => 'アプリへ配信',
    10 => '控えと最終確認',
];

/**
 * @param array{
 *   event: ?array<string, mixed>,
 *   now: string,
 *   scheduleInWindow: int, schedulePublic: int,
 *   stalls: int, posters: int, postersOn: int, posterMode: string,
 *   tutorial: bool, notices: bool, noticesCurrent: int,
 *   eventCollect: bool,
 *   access: array{mode:string, mapMode:string, hasPassword:bool, eventOpensMap:bool},
 *   releases: array<string, array{revision:int, expiresAt:?string, expired:bool, paused:bool}>,
 *   appWindowIds: list<int>
 * } $f
 * @return array<int, array{title:string, state:string, reason:string}>
 */
function km_event_setup_steps(array $f): array
{
    $event = $f['event'];
    $steps = [];
    $put = static function (int $n, string $state, string $reason) use (&$steps): void {
        $steps[$n] = ['title' => KM_EVENT_SETUP_STEPS[$n], 'state' => $state, 'reason' => $reason];
    };

    // 1. 日付と時間
    if ($event === null) {
        $put(1, 'warn', 'まだイベントを作っていません');
    } elseif (($event['startsAt'] ?? null) === null || ($event['endsAt'] ?? null) === null) {
        $put(1, 'warn', '開始と終了の日時を入れてください(終了が無いと、地図もアプリの配信も自動で終わりません)');
    } else {
        $put(1, 'ok', km_event_setup_period((string) $event['startsAt'], (string) $event['endsAt']));
    }

    // 2. 地図に出すもの(見るだけ)
    if ($event === null) {
        $put(2, 'info', '1 のあとで決めます');
    } else {
        $put(2, 'info', sprintf(
            '通行止め %d・臨時の地点 %d・臨時名称 %d%s%s',
            (int) ($event['closureCount'] ?? 0),
            (int) ($event['poiCount'] ?? 0),
            (int) ($event['aliasCount'] ?? 0),
            !empty($event['onlyEventNodes']) ? '・イベントの地点だけ' : '',
            !empty($event['hideOccupantNames']) ? '・教職員名を隠す' : ''
        ));
    }

    // 3. スケジュール(会期の中に始まる予定)
    if ($event === null || ($event['startsAt'] ?? null) === null || ($event['endsAt'] ?? null) === null) {
        $put(3, 'info', '1 で日時を入れると、会期の中の予定を数えます');
    } elseif ($f['scheduleInWindow'] === 0) {
        $put(3, 'warn', '会期の中に始まる予定がありません');
    } else {
        $put(3, 'ok', sprintf('会期の中に %d 件(うち公開 %d 件)', $f['scheduleInWindow'], $f['schedulePublic']));
    }

    // 4. 屋台・ポスター
    if ($f['posters'] > 0 && $f['posterMode'] === 'off') {
        $put(4, 'warn', 'ポスターの出し方が「表示しない」です(' . $f['posters'] . ' 件あります)');
    } elseif ($f['posters'] > 0 && $f['postersOn'] === 0) {
        $put(4, 'warn', 'ポスター・広告を全部止めています');
    } elseif ($f['posters'] === 0 && $f['stalls'] === 0) {
        $put(4, 'info', '屋台もポスター・広告もありません');
    } else {
        $put(4, 'ok', sprintf('屋台 %d・ポスター・広告 %d(出している %d)', $f['stalls'], $f['posters'], $f['postersOn']));
    }

    // 5. お知らせ(見るだけ)
    $put(5, 'info', sprintf(
        '上の帯: %s・チュートリアル %s・お知らせ %s(いま出るもの %d 件)',
        $event !== null && trim((string) ($event['bannerText'] ?? '')) !== '' ? '文あり' : 'イベント名',
        $f['tutorial'] ? 'ON' : 'OFF',
        $f['notices'] ? 'ON' : 'OFF',
        $f['noticesCurrent']
    ));

    // 6. 記録(見るだけ)
    $put(6, 'info', $f['eventCollect'] ? '会期中は来場者の操作も記録する' : '会期中も来場者の操作は記録しない');

    // 7. 公開の錠
    $access = $f['access'];
    $locked = $access['mapMode'] === 'password' || $access['mode'] === 'password';
    if ($locked && !$access['hasPassword']) {
        $put(7, 'warn', '錠があるのに合言葉がありません(地図の公開設定で決めてください)');
    } else {
        $put(7, 'info', sprintf(
            '地図: %s・教職員名: %s',
            $access['mapMode'] === 'password' ? ($access['eventOpensMap'] ? '錠あり(会期中は開ける)' : '錠あり') : '誰でも見られる',
            match ($access['mode']) {
                'public' => '出す',
                'password' => '合言葉で出す',
                default => '出さない',
            }
        ));
    }

    // 8. 有効にする
    if ($event === null) {
        $put(8, 'warn', '1 のあとで有効にします');
    } elseif (!empty($event['isActive'])) {
        $put(8, 'ok', '有効・いま地図に出ています');
    } elseif (!empty($event['isExpired']) || (($event['endsAt'] ?? null) !== null && (string) $event['endsAt'] <= $f['now'])) {
        $put(8, 'info', '会期は終わっています');
    } elseif (!empty($event['isEnabled'])) {
        $put(8, 'ok', '有効・開始の時刻から地図に出ます');
    } else {
        $put(8, 'warn', 'まだ有効にしていません(アプリへ配る地図にも入りません)');
    }

    // 9. アプリへ配信
    $put(9, ...km_event_setup_release_state($event, $f['releases'], $f['appWindowIds'], $f['now']));

    // 10. 控えと最終確認
    $open = array_keys(array_filter($steps, static fn (array $s): bool => $s['state'] === 'warn'));
    $put(10, $open === [] ? 'ok' : 'warn', $open === [] ? '! はありません。控えは夜 2:40 に自動で取ります' : '! が残っています: ' . implode('・', $open));

    ksort($steps);

    return $steps;
}

/** 「11/03 09:00 〜 11/04 17:00」 */
function km_event_setup_period(string $startsAt, string $endsAt): string
{
    $short = static fn (string $v): string => substr(str_replace('-', '/', substr($v, 5)), 0, 11);

    return $short($startsAt) . ' 〜 ' . $short($endsAt);
}

/**
 * 9 の印。イベント用(kosen-event)を配っていればその期限を会期の終わりと比べ、無ければ Website の正本(kosen-main)を見る。
 * 配る地図に焼き込むのは有効なイベントだけで、**一番早い会期のもの**(km_map_event_overlay_for_app)。
 *
 * @param array<string, array{revision:int, expiresAt:?string, expired:bool, paused:bool}> $releases
 * @param list<int> $appWindowIds いま配り直すと地図に入るイベントの番号
 * @param string $now DB の時刻(終わった会期は配らない)
 * @return array{0:string, 1:string}
 */
function km_event_setup_release_state(?array $event, array $releases, array $appWindowIds, string $now = ''): array
{
    if ($event === null) {
        return ['info', '1 のあとで配ります'];
    }
    if ($now !== '' && ($event['endsAt'] ?? null) !== null && (string) $event['endsAt'] <= $now) {
        return ['info', '会期は終わっています(イベント用の配信は「会期を終える」で止めます)'];
    }
    $main = $releases['kosen-main'] ?? null;
    $eventMap = $releases['kosen-event'] ?? null;
    if ($main === null && $eventMap === null) {
        return ['warn', 'アプリへまだ一度も配信していません(地図の配信の画面で最初の配信をしてください)'];
    }
    if (!in_array((int) $event['id'], $appWindowIds, true)) {
        return ['warn', empty($event['isEnabled'])
            ? '先に 8 で有効にしてください(有効なイベントだけが配る地図に入ります)'
            : 'ほかの会期が先に入ります(配る地図に入るのは一番早い会期だけです)'];
    }
    foreach (['kosen-event' => $eventMap, 'kosen-main' => $main] as $slug => $release) {
        if ($release !== null && $release['paused']) {
            return ['warn', ($slug === 'kosen-event' ? 'イベント用' : 'Website の正本') . 'の配信を止めています'];
        }
    }
    if ($eventMap !== null) {
        $end = ($event['endsAt'] ?? null) === null ? null : strtotime((string) $event['endsAt']);
        $expires = $eventMap['expiresAt'] === null ? null : strtotime($eventMap['expiresAt']);
        if ($eventMap['expired']) {
            return ['warn', 'イベント用の配信の期限が切れています(配り直すと会期の終わりまでにします)'];
        }
        if ($end !== null && $expires !== null && abs($end - $expires) > 60) {
            return ['warn', 'イベント用の配信の期限が会期の終わりと違います(配り直すと合わせます)'];
        }

        return ['ok', 'イベント用 版 ' . $eventMap['revision'] . '・期限は会期の終わり'];
    }
    if ($main['expired']) {
        return ['warn', 'Website の正本の配信の期限が切れています'];
    }

    return ['ok', 'Website の正本 版 ' . $main['revision'] . '(内容を変えたら配り直してください)'];
}
