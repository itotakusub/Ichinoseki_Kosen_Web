<?php

declare(strict_types=1);

/**
 * ホストのセキュリティ通知メールを1通送る。**標準入力から JSON を受け取る。**
 *
 *     docker compose exec -T web php scripts/notify-security.php < report.json
 *
 * 呼ぶのは scripts/host-security-check.sh(ホスト側)。
 *
 * ## なぜ PHP から送るのか
 *
 * 送信の設定(宛先・SMTP・認証・TLS)は既に `lib/mailer.php` が env から読んでいる。
 * シェル側で mail コマンドを叩くと**同じ設定が2箇所に散る** ——
 * 片方だけ直されて、通知だけ届かなくなる形になる(notify-update.php と同じ理由)。
 *
 * ## 失敗しても 0 で終わらない
 *
 * 呼び出し側(cron)が失敗に気づけるよう、送れなければ 1 を返す。
 * **「送ったつもり」が一番危ない。**
 *
 * ## ライブラリがまだ知らない種別も落とさない(2026-09-14)
 *
 * host-security-check.sh に `fail2ban`(入っているのに動いていない)と
 * `logto`(Console のアカウントに2段階認証が要らない)を足した。
 * lib/security-notice.php の KM_SECURITY_NOTICE_KINDS に載る前にそのまま渡すと、
 * 検証が例外を投げて **再起動や未適用の知らせまで1通も出なくなる。**
 * 知っている種別だけを検証に回し、残りは本文の末尾に足す。
 * 一覧に載れば、そちらの見出しと手順で出るようになる(ここは空になる)。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/security-notice.php';
require_once __DIR__ . '/../lib/mailer.php';

/**
 * ライブラリが知らない種別の項目を抜き出し、残りの JSON と一緒に返す。
 *
 * **種別の形をしていて要約があるものだけ**を抜く。壊れた項目は従来どおり検証で止める。
 *
 * @return array{0:string, 1:list<array{kind:string, summary:string, detail:string}>}
 */
function km_notify_security_split_unknown(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
        // 形の検査は km_security_notice_parse に任せる(ここで黙って直さない)
        return [$json, []];
    }

    $known = [];
    $unknown = [];
    foreach ($decoded['items'] as $item) {
        $kind = is_array($item) ? (string) ($item['kind'] ?? '') : '';
        $summary = is_array($item) ? trim((string) ($item['summary'] ?? '')) : '';
        if ($summary !== '' && preg_match('/^[a-z][a-z0-9-]{0,31}$/', $kind) === 1
            && !in_array($kind, KM_SECURITY_NOTICE_KINDS, true)) {
            $unknown[] = ['kind' => $kind, 'summary' => $summary, 'detail' => trim((string) ($item['detail'] ?? ''))];
            continue;
        }
        $known[] = $item;
    }
    if ($unknown === []) {
        return [$json, []];
    }

    $decoded['items'] = $known;

    return [(string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $unknown];
}

/**
 * 抜き出した項目を本文の行にする。**内部の識別子をそのまま見出しにしない**(lib と同じ作法)。
 *
 * @param list<array{kind:string, summary:string, detail:string}> $extra
 * @return list<string>
 */
function km_notify_security_extra_lines(array $extra): array
{
    $labels = ['fail2ban' => 'fail2ban', 'logto' => 'Logto Console'];
    $lines = [];
    foreach ($extra as $item) {
        $lines[] = '- [' . ($labels[$item['kind']] ?? $item['kind']) . '] ' . $item['summary'];
        if ($item['detail'] !== '') {
            foreach (explode("\n", $item['detail']) as $detailLine) {
                $lines[] = '    ' . $detailLine;
            }
        }
    }

    return $lines;
}

$json = (string) stream_get_contents(STDIN);
if (trim($json) === '') {
    fwrite(STDERR, "標準入力が空です。JSON を渡してください。\n");
    exit(1);
}

[$json, $extra] = km_notify_security_split_unknown($json);

try {
    $report = km_security_notice_parse($json);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, '通知の内容を読めません: ' . $exception->getMessage() . "\n");
    exit(1);
}

/*
 * **問題が無ければ送らない。** 毎日届くメールは読まれなくなり、
 * 本当に再起動が要るときにも気づかれない。
 * ただし heartbeat のときは送る —— 沈黙を「正常」と解釈させないため。
 */
if ($report['items'] === [] && $extra === [] && !$report['heartbeat']) {
    echo "問題はありません。メールは送りません。\n";
    exit(0);
}

$to = km_mail_admin_to();

if ($report['items'] === [] && $extra !== []) {
    /*
     * **知らない種別だけのときは、lib の本文を使わない。**
     * lib は項目が空だと「問題は見つかりませんでした」と書くので、すぐ下の項目と食い違う。
     */
    $subject = '[KosenMap] ホストの確認: ' . count($extra) . '件' . ($report['heartbeat'] ? '(定期のお知らせ)' : '');
    $lines = [$report['host'] . ' のセキュリティ状態を調べました。'];
    if ($report['os'] !== '') {
        $lines[] = 'OS: ' . $report['os'];
    }
    $lines[] = '確認した時刻: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s P');
    $lines[] = '';
    array_push($lines, ...km_notify_security_extra_lines($extra));
    $lines[] = '';
    $lines[] = '手順は各項目の下に書いてあります。';
    $body = implode("\n", $lines) . "\n";
} else {
    $subject = km_security_notice_subject($report['items'], $report['heartbeat']);
    $body = km_security_notice_body(
        $report['items'],
        $report['host'],
        $report['os'],
        $report['heartbeat'],
        $report['deferred']
    );
    if ($extra !== []) {
        $body .= "\n--- ほかに見つかったもの ---\n" . implode("\n", km_notify_security_extra_lines($extra)) . "\n";
    }
}

try {
    km_mail_send($to, $subject, $body);
} catch (Throwable $exception) {
    // 宛先は出す(設定の取り違えが一番多い)。中身は出さない
    fwrite(STDERR, '送信に失敗しました (宛先 ' . $to . '): ' . $exception->getMessage() . "\n");
    exit(1);
}

echo '送りました: ' . $to . ' / ' . $subject . "\n";
