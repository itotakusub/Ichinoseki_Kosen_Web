<?php

declare(strict_types=1);

/**
 * カレンダーの予定(admin/calendar.php)。
 *
 * 移行前のサンプルは「日(1〜31)」だけをキーにしていて月をまたげなかったため、
 * ここでは event_date を DATE として持つ。1日に複数件置ける。
 */

require_once __DIR__ . '/db.php';

/** バッジの色。任意の class を入れさせないため、この4つから選ばせる。 */
const KM_EVENT_BADGES = ['text-bg-info', 'text-bg-warning', 'text-bg-danger', 'text-bg-success'];

function km_events_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_date DATE NOT NULL,
            label VARCHAR(255) NOT NULL,
            badge_class VARCHAR(32) NOT NULL DEFAULT 'text-bg-info',
            created_at DATETIME NOT NULL,
            INDEX (event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

function km_events_validate(string $date, string $label, string $badge): void
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
        throw new InvalidArgumentException('日付の形式が正しくありません。');
    }
    if (trim($label) === '') {
        throw new InvalidArgumentException('予定の内容を入力してください。');
    }
    if (mb_strlen($label) > 255) {
        throw new InvalidArgumentException('予定は255文字以内にしてください。');
    }
    if (!in_array($badge, KM_EVENT_BADGES, true)) {
        throw new InvalidArgumentException('色の指定が不正です。');
    }
}

/**
 * 指定した年月(YYYY-MM)の予定を、日付ごとにまとめて返す。
 *
 * 日付の絞り込みは DB 側で行う(値が住んでいる場所で区切る。以前は DB が UTC 相当・PHP が
 * Asia/Tokyo で、PHP 側で月を出すと境界がずれた。フェーズ5・9の教訓)。
 * event_date は DATE 型で時刻を持たないため、そのまま文字列として扱ってよい。
 *
 * @return array<int, array<int, array{id:int, label:string, badgeClass:string, date:string}>>
 *         キーは日(1〜31)。1日に複数件入る。
 */
function km_events_for_month(PDO $pdo, string $yearMonth): array
{
    km_events_ensure_table($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, event_date AS date, label, badge_class AS badgeClass, DAY(event_date) AS day
         FROM km_events
         WHERE DATE_FORMAT(event_date, '%Y-%m') = ?
         ORDER BY event_date, id"
    );
    $stmt->execute([$yearMonth]);

    $byDay = [];
    foreach ($stmt->fetchAll() as $row) {
        $byDay[(int) $row['day']][] = $row;
    }

    return $byDay;
}

function km_events_create(PDO $pdo, string $date, string $label, string $badge): void
{
    km_events_ensure_table($pdo);
    km_events_validate($date, $label, $badge);

    $pdo->prepare('INSERT INTO km_events (event_date, label, badge_class, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$date, trim($label), $badge]);
}

function km_events_update(PDO $pdo, int $id, string $date, string $label, string $badge): void
{
    km_events_ensure_table($pdo);
    km_events_validate($date, $label, $badge);

    $pdo->prepare('UPDATE km_events SET event_date = ?, label = ?, badge_class = ? WHERE id = ?')
        ->execute([$date, trim($label), $badge, $id]);
}

function km_events_delete(PDO $pdo, int $id): void
{
    km_events_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_events WHERE id = ?')->execute([$id]);
}
