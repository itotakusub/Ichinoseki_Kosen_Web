<?php

declare(strict_types=1);

/**
 * 問い合わせフォームの投稿(admin/forms.php が保存し、admin/mailbox.php が読む)。
 *
 * lib/chat.php / lib/tasks.php と同じ「初回アクセス時に CREATE TABLE IF NOT EXISTS」。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/map-rate-limit.php'; // km_map_rate_limit_ip() を使う

/** 件名の選択肢。フォームの <option value> と対で維持する。 */
const KM_FORM_SUBJECTS = ['bug', 'feature', 'other'];

const KM_FORM_BODY_MAX = 4000;

/** 同じ IP からの連投を抑える(秒)。総当たりではなく事故・いたずら対策の緩い制限。 */
const KM_FORM_MIN_INTERVAL_SECONDS = 30;

function km_form_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_form_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            subject VARCHAR(32) NOT NULL,
            body TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARBINARY(16) NULL,
            created_at DATETIME NOT NULL,
            INDEX (created_at),
            INDEX (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 入力の検証。問題があれば InvalidArgumentException。 */
function km_form_validate(string $name, string $email, string $subject, string $body): void
{
    if (trim($name) === '') {
        throw new InvalidArgumentException('お名前を入力してください。');
    }
    if (mb_strlen($name) > 255) {
        throw new InvalidArgumentException('お名前は255文字以内にしてください。');
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('メールアドレスの形式が正しくありません。');
    }
    if (!in_array($subject, KM_FORM_SUBJECTS, true)) {
        throw new InvalidArgumentException('件名の指定が不正です。');
    }
    if (trim($body) === '') {
        throw new InvalidArgumentException('本文を入力してください。');
    }
    if (mb_strlen($body) > KM_FORM_BODY_MAX) {
        throw new InvalidArgumentException('本文は' . KM_FORM_BODY_MAX . '文字以内にしてください。');
    }
}

/**
 * 同じ IP が直前に投稿していないか。
 *
 * 総当たり対策の km_map_rate_limit_* とは別物なので、専用のテーブルは作らず
 * 投稿テーブル自身の最新行を見るだけにしてある(投稿頻度は元々低い前提)。
 * 比較は DB 内で完結させる(PHP 側で時刻を組むとタイムゾーン差で狂う。フェーズ5の教訓)。
 */
function km_form_too_soon(PDO $pdo): bool
{
    km_form_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM km_form_submissions
         WHERE ip_address = INET6_ATON(?) AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    $stmt->bindValue(1, km_map_rate_limit_ip());
    $stmt->bindValue(2, KM_FORM_MIN_INTERVAL_SECONDS, PDO::PARAM_INT);
    $stmt->execute();

    return (int) $stmt->fetchColumn() > 0;
}

function km_form_save(PDO $pdo, string $name, string $email, string $subject, string $body): int
{
    km_form_ensure_table($pdo);
    km_form_validate($name, $email, $subject, $body);

    $stmt = $pdo->prepare(
        'INSERT INTO km_form_submissions (name, email, subject, body, ip_address, created_at)
         VALUES (?, ?, ?, ?, INET6_ATON(?), NOW())'
    );
    $stmt->execute([trim($name), trim($email), $subject, trim($body), km_map_rate_limit_ip()]);

    return (int) $pdo->lastInsertId();
}

/**
 * 時刻は UNIX_TIMESTAMP() の epoch で返す(フェーズ5・9の教訓)。
 *
 * @return array<int, array{id:int, name:string, email:string, subject:string, body:string,
 *                          isRead:int, ip:?string, createdAtEpoch:int}>
 */
function km_form_recent(PDO $pdo, int $limit = 100): array
{
    km_form_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, name, email, subject, body, is_read AS isRead,
                INET6_NTOA(ip_address) AS ip, UNIX_TIMESTAMP(created_at) AS createdAtEpoch
         FROM km_form_submissions ORDER BY id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function km_form_unread_count(PDO $pdo): int
{
    km_form_ensure_table($pdo);

    return (int) $pdo->query('SELECT COUNT(*) FROM km_form_submissions WHERE is_read = 0')->fetchColumn();
}

function km_form_mark_read(PDO $pdo, int $id, bool $read = true): void
{
    km_form_ensure_table($pdo);
    $pdo->prepare('UPDATE km_form_submissions SET is_read = ? WHERE id = ?')
        ->execute([$read ? 1 : 0, $id]);
}

function km_form_delete(PDO $pdo, int $id): void
{
    km_form_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_form_submissions WHERE id = ?')->execute([$id]);
}
