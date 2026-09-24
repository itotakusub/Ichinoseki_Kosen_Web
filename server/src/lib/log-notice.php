<?php

declare(strict_types=1);

/**
 * スクリプトの実行結果・ログをメールで送るための文面。
 *
 * ## なぜ要るのか
 *
 * cron から走らせるものは、出力を `>/dev/null` に捨てている。
 * **失敗しても誰も見ない。** バックアップのように「動いていると思っていたら
 * 半年前から止まっていた」が一番起きやすい種類の仕事ほど、結果が要る。
 *
 * ## ログをそのまま送らない
 *
 * ログには**秘密が混ざる。** `mariadb-dump -p…` の行、`.env` を写した行、
 * トークンを含む URL —— メールは転送も保存もされるので、一度出たら回収できない。
 * [km_log_notice_redact] で伏せてから送る。
 *
 * **伏せ字は完璧ではない。** 知っている形しか消せないので、
 * 「ログにはそもそも秘密を書かない」が本筋。ここは最後の網。
 *
 * ## 長すぎるものを送らない
 *
 * 3万行のログを本文に貼っても読まれない。**頭と尻を残して真ん中を落とす** ——
 * 失敗の理由はたいてい末尾に、何をしようとしたかは先頭にある。
 */

/** 本文に残す行数(頭)。 */
const KM_LOG_NOTICE_HEAD_LINES = 40;

/** 本文に残す行数(尻)。**失敗の理由はここに出る。** */
const KM_LOG_NOTICE_TAIL_LINES = 60;

/**
 * 伏せる鍵の名前。**部分一致で見る**(`MARIADB_ROOT_PASSWORD` も `PASSWORD` で当たる)。
 *
 * 増やすときは「その語を含む環境変数名」を思い浮かべること ——
 * `KEY` は広いが、`ACCESS_KEY` `API_KEY` `KEY_PATH` をまとめて拾える。
 */
const KM_LOG_NOTICE_SECRET_HINTS = [
    'PASSWORD', 'PASSWD', 'SECRET', 'TOKEN', 'APIKEY', 'API_KEY',
    'ACCESS_KEY', 'PRIVATE', 'CREDENTIAL', 'DSN', 'SALT', 'HASH',
];

/**
 * 秘密を伏せる。**消すのではなく、伏せたことが分かる形にする** ——
 * 消すと「その行が無かった」のか「伏せた」のか読み分けられない。
 */
function km_log_notice_redact(string $text): string
{
    // 1) KEY=値 / KEY: 値 の形。鍵の名前に手がかりがあるものだけ
    $hints = implode('|', array_map(
        static fn (string $h): string => preg_quote($h, '/'),
        KM_LOG_NOTICE_SECRET_HINTS
    ));
    $text = (string) preg_replace(
        '/^([A-Za-z0-9_]*(?:' . $hints . ')[A-Za-z0-9_]*)\s*([=:])\s*\S.*$/mi',
        '$1$2 ***伏せました***',
        $text
    );

    /*
     * 2) `-p秘密` の形。**mysql 系はここに直接書く。**
     *    `-p` の直後に値が続くときだけ(`-p` 単独は対話入力なので伏せる必要が無い)。
     */
    $text = (string) preg_replace('/(-p)(?!\s)\S+/', '$1***伏せました***', $text);

    // 3) URL に載った資格情報 (scheme://user:pass@host)
    $text = (string) preg_replace('#(://[^/\s:@]+):[^/\s@]+@#', '$1:***伏せました***@', $text);

    // 4) PEM の中身。**行ごと落とす**(部分的に残しても意味が無く、危険なだけ)
    $text = (string) preg_replace(
        '/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/s',
        '***秘密鍵を伏せました***',
        $text
    );

    return $text;
}

/**
 * 長いログを、頭と尻を残して縮める。
 *
 * @return array{text:string, total:int, trimmed:bool}
 */
function km_log_notice_trim(string $text): array
{
    /*
     * **`\R` で割らない。** `/u` の無い `\R` はバイト `\x85`(Latin-1 の NEL)も改行とみなし、
     * UTF-8 の文字の途中で割る —— `者` は `E8 80 85` なので、
     * 「指紋を業者の…」が「指紋を業�」と「の…」の2行になっていた(2026-09-13 のメール)。
     * `/u` を付けると、壊れた UTF-8 が1バイトでも混じったとき preg_split が false を返して
     * 本文が丸ごと消える。**改行の3通りだけを明示して割る**(バイトで見ても安全)。
     */
    $lines = preg_split('/\r\n|\r|\n/', rtrim($text, "\r\n")) ?: [];
    $total = count($lines);
    if ($total <= KM_LOG_NOTICE_HEAD_LINES + KM_LOG_NOTICE_TAIL_LINES) {
        return ['text' => implode("\n", $lines), 'total' => $total, 'trimmed' => false];
    }

    $head = array_slice($lines, 0, KM_LOG_NOTICE_HEAD_LINES);
    $tail = array_slice($lines, -KM_LOG_NOTICE_TAIL_LINES);
    $dropped = $total - count($head) - count($tail);

    return [
        'text' => implode("\n", array_merge(
            $head,
            ['', sprintf('    …… 中略 %d 行(全 %d 行)……', $dropped, $total), ''],
            $tail
        )),
        'total' => $total,
        'trimmed' => true,
    ];
}

/**
 * 受け取った JSON を検証して整える。
 *
 * @return array{label:string, host:string, status:string, text:string, exitCode:?int}
 * @throws InvalidArgumentException 形が違うとき
 */
function km_log_notice_parse(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('通知の内容を JSON として読めません');
    }

    $label = trim((string) ($decoded['label'] ?? ''));
    if ($label === '') {
        // **名無しで送らない。** 受け取った側が、何のログか分からない
        throw new InvalidArgumentException('label がありません');
    }

    $status = (string) ($decoded['status'] ?? 'unknown');
    // info は成否の無い知らせ(SSH ログインの通知など。2026-09-25)
    if (!in_array($status, ['ok', 'ng', 'info', 'unknown'], true)) {
        $status = 'unknown';
    }

    $exit = $decoded['exitCode'] ?? null;

    return [
        'label' => $label,
        'host' => trim((string) ($decoded['host'] ?? '')) ?: 'このホスト',
        'status' => $status,
        'text' => (string) ($decoded['text'] ?? ''),
        'exitCode' => is_int($exit) ? $exit : (is_numeric($exit) ? (int) $exit : null),
    ];
}

/**
 * 件名。**成否を件名だけで分かるようにする**(一覧で開かずに判断できる)。
 */
function km_log_notice_subject(array $report): string
{
    $mark = [
        'ok' => '成功',
        'ng' => '**失敗**',
        'info' => '通知',
        'unknown' => '結果',
    ][$report['status']] ?? '結果';

    // メールの件名に ** は要らない。表記だけ整える
    $mark = str_replace('*', '', $mark);

    return sprintf('[KosenMap] %s: %s (%s)', $report['label'], $mark, $report['host']);
}

/** 本文。伏せてから縮める(**順番が逆だと、落とした部分に秘密が残る**)。 */
function km_log_notice_body(array $report, ?DateTimeImmutable $sentAt = null): string
{
    $sentAt ??= new DateTimeImmutable('now');

    $lines = [];
    $lines[] = $report['label'];
    $lines[] = 'ホスト: ' . $report['host'];
    $lines[] = '時刻: ' . $sentAt->format('Y-m-d H:i:s P');
    if ($report['exitCode'] !== null) {
        $lines[] = '終了コード: ' . $report['exitCode'];
    }
    $lines[] = '結果: ' . ([
        'ok' => '成功',
        'ng' => '失敗',
        'info' => '通知',
        'unknown' => '不明',
    ][$report['status']] ?? '不明');
    $lines[] = '';

    /*
     * **伏せてから縮める。**
     * 逆にすると、中略で落とした行に秘密が残ったまま……ではなく、
     * 残した行の秘密が伏せられずに出る。順番を入れ替えないこと。
     */
    $trimmed = km_log_notice_trim(km_log_notice_redact($report['text']));

    if ($trimmed['text'] === '') {
        $lines[] = '(出力はありませんでした)';
    } else {
        $lines[] = '--- 出力 ---';
        $lines[] = $trimmed['text'];
    }

    if ($trimmed['trimmed']) {
        $lines[] = '';
        $lines[] = '全文はホスト側のログに残っています。';
    }

    $lines[] = '';
    $lines[] = '※ 秘密らしき値は伏せて送っています。伏せ切れないものもあるので、';
    $lines[] = '  このメールは転送しないでください。';

    return implode("\n", $lines) . "\n";
}
