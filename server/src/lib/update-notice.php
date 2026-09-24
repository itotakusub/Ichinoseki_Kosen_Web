<?php

declare(strict_types=1);

/**
 * コンテナの更新通知。**知らせるだけで、当てはしない。**
 *
 * ## なぜ自動で当てないのか
 *
 * Logto は起動時に DB の移行を走らせる。巻き戻し(npm run alteration rollback)はあるが、
 * 列や表を落とす移行は戻しても中身が返らず、**確実なのはバックアップからの復元**。
 * そのうえ Logto は管理画面のゲートそのもの
 * (nginx の auth_request)なので、**落ちると直しに行く手段ごと失われる** ——
 * 2026-08-28 に実際にそうなった。会期中に落ちれば来場者のログインも全部止まる。
 *
 * 「新しい版が出たことを知る」のは自動でよい。**見逃しても壊れない**からだ。
 * 当てるかどうかは人が決める。
 *
 * ## 届かないことに気づける形にする
 *
 * 「何も来ない」は「更新が無い」とも「仕組みが壊れた」とも読める。
 * 変化が無くても月に一度は送る([km_update_notice_body] の heartbeat)。
 * **沈黙を「正常」と解釈させない。**
 *
 * ロジックをここに置いてあるのは、メールも Docker も無しで文面を検証できるようにするため
 * (scripts/check.php update-notice)。
 */

/** 1通に並べる上限。これを超えたら件数だけ出す(メールが読めなくなるより良い)。 */
const KM_UPDATE_NOTICE_MAX_ITEMS = 20;

/**
 * 通知の1件。`kind` は下の4つ。
 *
 * - `release` … 新しい版が公開された(Logto の GitHub リリース)。**人が読める版番号**
 * - `digest`  … 動くタグ(`latest` や `alpine`)の中身が入れ替わった。
 *               **気づかないうちに上がりうる**という警告
 * - `series`  … 系列のタグ(`17-alpine` / `11.4` / `5.2.3-apache`)の中身が入れ替わった。
 *               **修正版が出た**という知らせ。当てるかどうかを決める
 * - `base`    … 自前でビルドするもの(web / soketi)の**ビルド元**が入れ替わった。
 *               pull では変わらないので、ビルドし直すまで当たらない
 *
 * **混ぜない。** 打つ手が違う。
 *
 * series と base は 2026-09-13 に足した。それまで check-updates.sh は
 * `latest|alpine|stable|main|edge` しか比べておらず、DB 系とビルド元は
 * **固定もされず、見張られてもいなかった** —— 修正版が出ても誰も知らなかった。
 */
const KM_UPDATE_NOTICE_KINDS = ['release', 'digest', 'series', 'base'];

/**
 * 受け取った JSON を検証して整える。**壊れた入力で送らない。**
 *
 * @return array{items:array<int,array{name:string,current:string,available:string,kind:string}>,
 *               host:string, heartbeat:bool}
 * @throws InvalidArgumentException 形が違うとき
 */
function km_update_notice_parse(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('通知の内容を JSON として読めません');
    }

    $items = [];
    foreach ($decoded['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? ''));
        $kind = (string) ($item['kind'] ?? 'release');
        if ($name === '' || !in_array($kind, KM_UPDATE_NOTICE_KINDS, true)) {
            // **黙って捨てない。** 名前も種別も無い行は、作った側が間違えている
            throw new InvalidArgumentException('通知の項目が不正です: ' . json_encode($item));
        }
        $items[] = [
            'name' => $name,
            'current' => trim((string) ($item['current'] ?? '')),
            'available' => trim((string) ($item['available'] ?? '')),
            'kind' => $kind,
        ];
    }

    return [
        'items' => $items,
        'host' => trim((string) ($decoded['host'] ?? '')) ?: 'このホスト',
        'heartbeat' => ($decoded['heartbeat'] ?? false) === true,
    ];
}

/** 件名。**何が起きたかを件名だけで分かるようにする**(一覧で開かずに判断できる)。 */
function km_update_notice_subject(array $items, bool $heartbeat = false): string
{
    if ($items === []) {
        return $heartbeat
            ? '[KosenMap] 更新はありません(定期のお知らせ)'
            : '[KosenMap] 更新はありません';
    }

    /*
     * 件名は**一番手が要る種類**で決める。並びは release → base → series → digest。
     * 種類が混ざったときは、件名に出なかった分を「ほか N件」で添える
     * (件名だけ見て「Logto だけ」と思わせない)。
     */
    $pick = static fn (string $kind): array => array_values(
        array_filter($items, static fn (array $i): bool => $i['kind'] === $kind)
    );
    $others = static fn (array $shown): string => count($items) > count($shown)
        ? ' ほか ' . (count($items) - count($shown)) . '件'
        : '';

    $releases = $pick('release');
    if ($releases !== []) {
        return '[KosenMap] 新しい版が出ています: '
            . implode(' / ', array_column($releases, 'name')) . $others($releases);
    }
    $bases = $pick('base');
    if ($bases !== []) {
        return '[KosenMap] ビルド元が更新されています (' . count($bases) . '件)' . $others($bases);
    }
    $series = $pick('series');
    if ($series !== []) {
        return '[KosenMap] 修正版が出ています (' . count($series) . '件)' . $others($series);
    }

    return '[KosenMap] タグの中身が入れ替わりました (' . count($items) . '件)';
}

/**
 * 本文。**次に何をすればよいかまで書く。**
 *
 * 「更新があります」だけのメールは、受け取っても手が動かない。
 * 手順と、**先にバックアップを取ること**をここに置く。
 */
function km_update_notice_body(
    array $items,
    string $host,
    bool $heartbeat = false,
    ?DateTimeImmutable $checkedAt = null
): string {
    $checkedAt ??= new DateTimeImmutable('now');
    $lines = [];
    $lines[] = $host . ' のコンテナを調べました。';
    $lines[] = '確認した時刻: ' . $checkedAt->format('Y-m-d H:i:s P');
    $lines[] = '';

    if ($items === []) {
        $lines[] = '更新はありませんでした。';
        if ($heartbeat) {
            $lines[] = '';
            // **メール本文に markdown を書かない。** 受け取る側は素のテキストで読む
            $lines[] = 'これは定期のお知らせです。このメールが届かなくなったら、';
            $lines[] = '更新が無いのではなく、確認の仕組みが止まっている可能性があります。';
        }
        return implode("\n", $lines) . "\n";
    }

    // 種類ごとの呼び名。**打つ手が違うので、読み手が一目で分けられるようにする**
    $labels = [
        'release' => ['新しい版', '公開されている版'],
        'digest' => ['タグの中身が変わった', '登録されている中身'],
        'series' => ['修正版', '登録されている中身'],
        'base' => ['ビルド元の修正版', '登録されている中身'],
    ];
    $shown = array_slice($items, 0, KM_UPDATE_NOTICE_MAX_ITEMS);
    foreach ($shown as $item) {
        [$label, $availableLabel] = $labels[$item['kind']] ?? ['更新', '公開されているもの'];
        $lines[] = sprintf(
            '- %s (%s)  いま: %s  →  %s: %s',
            $item['name'],
            $label,
            $item['current'] !== '' ? $item['current'] : '不明',
            $availableLabel,
            $item['available'] !== '' ? $item['available'] : '不明'
        );
    }
    if (count($items) > count($shown)) {
        $lines[] = sprintf('- ほか %d 件', count($items) - count($shown));
    }

    /*
     * 当て方は**種類で変わる。** 系列タグや版番号は pull で当たるが、
     * ビルド元は build --pull し直さない限り、いつまでも古いまま動き続ける。
     */
    $kinds = array_values(array_unique(array_column($items, 'kind')));
    $lines[] = '';
    $lines[] = '--- 当てるときは ---';
    $lines[] = '1. 先にバックアップを取る (.\deploy-to-host.ps1 -BackupOnly)';
    $step = 2;
    if (array_intersect($kinds, ['release', 'digest', 'series']) !== []) {
        $lines[] = $step . '. compose.yaml の image: を書き換える(版番号、または check-updates.sh --pins が出す digest)';
        $step++;
        $lines[] = $step . '. docker compose pull && docker compose up -d';
        $step++;
    }
    if (in_array('base', $kinds, true)) {
        $lines[] = $step . '. ビルド元はビルドし直さないと当たらない: docker compose build --pull web soketi && docker compose up -d';
        $step++;
    }
    $lines[] = $step . '. 最後に、実際にログインできることを1回確かめる';
    $lines[] = '';
    if (in_array('release', $kinds, true)) {
        $lines[] = 'Logto は起動時に DB の移行を走らせます。戻すときは npm run alteration rollback <前の版> がありますが、';
        $lines[] = '列や表を落とす移行は巻き戻しても中身が戻りません。確実なのはバックアップからの復元です。';
        $lines[] = 'Logto は管理画面のゲートでもあるので、落ちると直しに行く手段ごと失われます。';
    }
    if (in_array('series', $kinds, true)) {
        $lines[] = 'MariaDB / PostgreSQL の修正版も、上げる前に必ずバックアップを取ってください。';
    }
    $lines[] = '会期中は上げないこと。';

    return implode("\n", $lines) . "\n";
}
