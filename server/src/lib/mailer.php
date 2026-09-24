<?php

declare(strict_types=1);

/**
 * メール送信。開発中は Mailpit(compose の mailpit サービス)が受け皿になる。
 *
 * PHP の mail() は使えない: docker/php/Dockerfile は php:8.4-apache に sendmail 系を
 * 入れておらず、php.ini にも sendmail_path / SMTP の指定が無い。そのため SMTP を喋る
 * ライブラリ(PHPMailer)経由で mailpit:1025 へ直接投げる。
 *
 * **Mailpit は受け取ったメールを実際には配送しない**開発用の受け皿。本番で外部へ
 * 送るようになったら、MAIL_HOST / MAIL_PORT を実サービスへ向け、必要なら認証と TLS を
 * 足すだけで済むよう、接続情報はすべて環境変数から読む(コードに決め打ちしない)。
 *
 * lib/soketi.php と同じ「第三者サービスのクライアントを1関数に集約する」形。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/** 管理者への通知メールの宛先。 */
function km_mail_admin_to(): string
{
    return getenv('MAIL_ADMIN_TO') ?: 'admin@example.test';
}

/** 空文字と未設定をまとめて「無い」として扱う。 */
function km_mail_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

/**
 * 送信の設定は**すべて環境変数から読む**。
 *
 * ## なぜここまで env に寄せるのか
 *
 * 送信手段は変わる。いまは Mailpit(開発用の受け皿)で、次は自前の送信サーバー、
 * それが届かなければ送信サービス、という順に試す計画になっている。
 *
 * **乗り換えのたびにコードを直す形にしておくと、「駄目だった」と分かった時点で
 * 改修から始めることになる。** そうすると、届かないまま粘る方に傾いてしまう。
 * ここを env だけで切り替えられるようにしておけば、乗り換えは `.env` の数行で済む。
 *
 * ## 何も設定しなければ Mailpit のまま
 *
 * `MAIL_USERNAME` が空なら認証しない。`MAIL_ENCRYPTION` が空なら TLS も張らない。
 * これは Mailpit の要件そのものなので、**検証環境は何も足さずに従来どおり動く。**
 */
function km_mailer(): PHPMailer
{
    $mailer = new PHPMailer(true); // true = 失敗時に例外を投げる

    $mailer->isSMTP();
    $mailer->Host = km_mail_env('MAIL_HOST', 'mailpit');
    $mailer->Port = (int) km_mail_env('MAIL_PORT', '1025');

    /*
     * 認証。**利用者名が入っているときだけ**有効にする。
     * Mailpit は認証を要求しないどころか、AUTH を投げると失敗する。
     */
    $username = km_mail_env('MAIL_USERNAME');
    if ($username !== '') {
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = km_mail_env('MAIL_PASSWORD');
    } else {
        $mailer->SMTPAuth = false;
    }

    /*
     * 暗号化。tls(STARTTLS・587番)/ ssl(SMTPS・465番)/ 空(平文)。
     *
     * **SMTPAutoTLS は明示的に切る。** 既定では「相手が STARTTLS を名乗ったら勝手に張る」
     * 挙動になっており、平文のつもりの接続が黙って変わる。どちらで繋ぐかは
     * 設定した人が決めるべきで、暗黙に切り替わると障害時の切り分けが難しくなる。
     */
    $encryption = strtolower(km_mail_env('MAIL_ENCRYPTION'));
    $mailer->SMTPSecure = match ($encryption) {
        'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
        'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
        default => '',
    };
    $mailer->SMTPAutoTLS = false;

    // 送信で固まらないように(死活監視と同じ感覚の短さにしてある)
    $mailer->Timeout = 10;
    $mailer->SMTPDebug = SMTP::DEBUG_OFF;

    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $from = km_mail_env('MAIL_FROM', 'kosenmap@example.test');
    $mailer->setFrom($from, km_mail_env('MAIL_FROM_NAME', 'KosenMap'));

    /*
     * **差出人のドメインを名乗る。**
     *
     * PHPMailer は Hostname を指定しないと `gethostname()` に落ちる。コンテナの中では
     * これが **`5bbcae4296fb` のような乱数のホスト名**になり、そのまま
     *
     *   Message-ID: <....@5bbcae4296fb>
     *
     * として出ていく(検証環境で実際にこうなった)。EHLO で名乗る名前も同じ。
     * 受け取り側から見ると「差出人は ito4.jp なのに、名乗りは見知らぬ乱数」という
     * 不一致で、**迷惑メール判定で不利に働く**。
     *
     * 差出人アドレスのドメインを使えば、DKIM の d= とも SPF の検査対象とも揃う。
     */
    $domain = substr(strrchr($from, '@') ?: '', 1);
    if ($domain !== '') {
        $mailer->Hostname = $domain;
    }

    return $mailer;
}

/**
 * 1通送る。失敗すると PHPMailer が例外を投げるので、呼び出し側で握るかどうかを決める。
 *
 * 問い合わせフォームのように「メールが飛ばなくても投稿自体は残したい」場面では、
 * 呼び出し側で try/catch して error_log に落とすこと(km_form_* の使い方を参照)。
 */
function km_mail_send(string $to, string $subject, string $body): void
{
    km_mail_send_with_files($to, $subject, $body);
}

/**
 * 添付を付けて1通送る。
 *
 * **読めない添付は黙って落とさない。** 付けられなかったものは例外にする ——
 * 「送れたのに中身が無い」が一番危ない(バックアップを送ったつもりで空、が起こる)。
 *
 * 大きさの見張りは呼び出し側の仕事。ここは**渡されたものを付けるだけ**にする
 * (上限の判断が2箇所に散ると、片方だけ直されて食い違う)。
 *
 * @param array<int,string> $files 添付するファイルの絶対パス
 */
function km_mail_send_with_files(string $to, string $subject, string $body, array $files = []): void
{
    $mailer = km_mailer();
    $mailer->clearAllRecipients();
    $mailer->clearAttachments();
    $mailer->addAddress($to);
    $mailer->Subject = $subject;
    $mailer->Body = $body;
    $mailer->isHTML(false); // 本文はプレーンテキスト。HTML メールは今のところ不要

    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('添付を読めません: ' . $file);
        }
        $mailer->addAttachment($file, basename($file));
    }

    $mailer->send();
}
