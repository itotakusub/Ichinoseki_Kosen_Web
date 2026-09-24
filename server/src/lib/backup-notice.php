<?php

declare(strict_types=1);

/**
 * バックアップの結果通知。**中身ではなく、取れたかどうかを知らせる。**
 *
 * ## なぜ「取れました」を毎日送らないのか
 *
 * 成功の便りが毎日届くと読まれなくなり、**失敗した日の1通も同じ扱いで流される。**
 * 送るのは「失敗したとき」と「添付したとき」、あとは週に一度(日曜)の便りだけ
 * (`update-notice.php` / `security-notice.php` と同じ作法)。
 *
 * ## 中身を素で送らない
 *
 * バックアップには **`.env` と `config/*.local.php`** が入っている ——
 * DB のパスワード、Logto の M2M 秘密、教職員氏名の解除パスワードのハッシュ。
 * さらに Logto の DB には利用者アカウントが、MariaDB には問い合わせの本文が入る。
 *
 * **素のまま送ると、受信箱1つの流出がサーバーの全権になる。**
 * 添付するなら暗号化してから(合言葉は `.env` の `BACKUP_PASSPHRASE`、
 * **メールには書かない**)。合言葉が無ければ添付せず、その理由を本文に書く。
 *
 * ロジックをここに置いてあるのは、メールもホストも無しで文面を検証できるようにするため
 * (`scripts/check.php backup-notice`)。
 */

/** 添付の合計上限。**Gmail の 25MB を超えると黙って捨てられる。** */
const KM_BACKUP_NOTICE_ATTACH_LIMIT = 20 * 1024 * 1024;

/**
 * 受け取った JSON を検証して整える。**壊れた入力で送らない。**
 *
 * @return array{host:string, ok:bool, archive:string, bytes:int, seconds:int, kept:int,
 *               parts:array<int,array{name:string,bytes:int,note:string}>,
 *               problems:array<int,string>, attached:array<int,string>,
 *               attachNote:string, heartbeat:bool}
 * @throws InvalidArgumentException 形が違うとき
 */
function km_backup_notice_parse(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('通知の内容を JSON として読めません');
    }

    $parts = [];
    foreach ($decoded['parts'] ?? [] as $part) {
        if (!is_array($part)) {
            continue;
        }
        $name = trim((string) ($part['name'] ?? ''));
        if ($name === '') {
            // **黙って捨てない。** 名前の無い行は、作った側が間違えている
            throw new InvalidArgumentException('バックアップの項目に名前がありません: ' . json_encode($part));
        }
        $parts[] = [
            'name' => $name,
            'bytes' => (int) ($part['bytes'] ?? 0),
            'note' => trim((string) ($part['note'] ?? '')),
        ];
    }

    $strings = static function ($value): array {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    };

    return [
        'host' => trim((string) ($decoded['host'] ?? '')) ?: 'このホスト',
        'ok' => ($decoded['ok'] ?? false) === true,
        'archive' => trim((string) ($decoded['archive'] ?? '')),
        'bytes' => (int) ($decoded['bytes'] ?? 0),
        'seconds' => (int) ($decoded['seconds'] ?? 0),
        'kept' => (int) ($decoded['kept'] ?? 0),
        'parts' => $parts,
        'problems' => $strings($decoded['problems'] ?? []),
        'attached' => $strings($decoded['attached'] ?? []),
        'attachNote' => trim((string) ($decoded['attachNote'] ?? '')),
        'heartbeat' => ($decoded['heartbeat'] ?? false) === true,
    ];
}

/** 人が読める大きさ。**バイト数をそのまま出さない**(桁を数えることになる)。 */
function km_backup_notice_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }

    return sprintf('%.2f GB', $bytes / 1024 / 1024 / 1024);
}

/** 件名。**成功と失敗を件名だけで見分けられるようにする。** */
function km_backup_notice_subject(array $report): string
{
    if (!$report['ok']) {
        return '[KosenMap] バックアップに失敗しました';
    }
    if ($report['problems'] !== []) {
        return '[KosenMap] バックアップは取れましたが、気になる点があります';
    }

    $size = km_backup_notice_size($report['bytes']);
    /*
     * **添付しているのは実行記録で、控えそのものではない。**
     *
     * 以前は「バックアップ (633.0 KB) を添付しました」だった。633 KB は控えの大きさで、
     * 実際に付いているのは `kosenmap-logs-*.tar.gz.enc`(cron の記録)だけ。
     * **受信箱に控えがあると思い込むと、ホストが失われたときに頼る先を間違える。**
     * 控えを取りに行く先はホストの backups/ と手元の PC。
     */
    if ($report['attached'] !== []) {
        return '[KosenMap] バックアップを取りました (' . $size . ')・実行記録を添付';
    }

    return '[KosenMap] バックアップを取りました (' . $size . ')';
}

/**
 * 本文。**次に何をすればよいかまで書く。**
 *
 * 失敗したときは「何が取れて何が取れなかったか」を先に出す ——
 * 全部駄目なのか1つだけなのかで、打つ手が違う。
 */
function km_backup_notice_body(array $report, ?DateTimeImmutable $ranAt = null): string
{
    $ranAt ??= new DateTimeImmutable('now');

    $lines = [];
    $lines[] = $report['host'] . ' のバックアップ';
    $lines[] = '実行した時刻: ' . $ranAt->format('Y-m-d H:i:s P');
    if ($report['seconds'] > 0) {
        $lines[] = 'かかった時間: ' . $report['seconds'] . ' 秒';
    }
    $lines[] = '';

    if ($report['problems'] !== []) {
        $lines[] = '--- 気になる点 ---';
        foreach ($report['problems'] as $problem) {
            $lines[] = '- ' . $problem;
        }
        $lines[] = '';
    }

    if ($report['parts'] !== []) {
        $lines[] = '--- 取れたもの ---';
        foreach ($report['parts'] as $part) {
            $lines[] = sprintf(
                '- %-12s %10s  %s',
                $part['name'],
                km_backup_notice_size($part['bytes']),
                $part['note']
            );
        }
        $lines[] = '';
    }

    if ($report['archive'] !== '') {
        $lines[] = '置き場: ' . $report['archive'];
        $lines[] = '大きさ: ' . km_backup_notice_size($report['bytes']);
        if ($report['kept'] > 0) {
            $lines[] = '世代: 新しい方から ' . $report['kept'] . ' 本を残しています';
        }
        $lines[] = '';
    }

    $lines[] = '--- 添付 ---';
    if ($report['attached'] !== []) {
        foreach ($report['attached'] as $name) {
            $lines[] = '- ' . $name;
        }
        $lines[] = '';
        $lines[] = '添付は実行記録(cron が /var/log/kosenmap に書いたもの)です。';
        $lines[] = 'バックアップの控えそのものは添付していません。控えはホストの backups/ と手元の PC にあります。';
        $lines[] = '';
        $lines[] = '合言葉はこのメールには書きません。ホストの .env にあります。';
        $lines[] = '開き方: openssl enc -d -aes-256-cbc -pbkdf2 -in <ファイル> -out logs.tar.gz';
        $lines[] = '        tar -xzf logs.tar.gz';
    } else {
        $lines[] = 'ありません。' . ($report['attachNote'] !== '' ? $report['attachNote'] : '');
    }
    $lines[] = '';

    $lines[] = '--- 戻すときは ---';
    $lines[] = '1. 先にいまの状態を1本取る(壊れていても、取っておけば後から調べられます)';
    $lines[] = '2. 展開して、DB を流し込む';
    $lines[] = '3. uploads/ を戻し、config/*.local.php と .env を置き直す';
    $lines[] = '4. docker compose up -d のあと、実際にログインできることを1回確かめる';
    $lines[] = '   手元からの復元は server/Old/restore-data.ps1 です。';
    $lines[] = '';
    $lines[] = 'この中には .env と config/*.local.php が入っています。';
    $lines[] = 'DB のパスワードと Logto の秘密がそのまま含まれるので、置き場所に注意してください。';

    if ($report['heartbeat']) {
        $lines[] = '';
        $lines[] = 'これは定期のお知らせです。このメールが届かなくなったら、';
        $lines[] = 'バックアップが要らなくなったのではなく、仕組みが止まっている可能性があります。';
    }

    return implode("\n", $lines) . "\n";
}
