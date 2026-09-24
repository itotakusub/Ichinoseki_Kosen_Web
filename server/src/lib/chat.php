<?php

declare(strict_types=1);

/**
 * admin/chat.php の履歴保存。km_map_rate_limit_ensure_table() と同じ
 * 「初回アクセス時に CREATE TABLE IF NOT EXISTS する」パターンを踏襲する。
 *
 * kmt_ (ユーザーがテーブル管理画面から作るテーブル) とは別系統。km_map_* と同じ
 * 「アプリ自身が使うシステムテーブル」の扱いなので km_ 接頭辞にしてある。
 */

function km_chat_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id VARCHAR(191) NOT NULL,
            sender_name VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            sent_at DATETIME NOT NULL,
            INDEX (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** @return int 保存したメッセージの id。リアルタイム配信にも載せて、既読の基準に使う。 */
function km_chat_save_message(PDO $pdo, string $senderId, string $senderName, string $message): int
{
    km_chat_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO km_chat_messages (sender_id, sender_name, message, sent_at) VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([$senderId, $senderName, $message]);

    return (int) $pdo->lastInsertId();
}

/**
 * 時刻は datetime の文字列ではなく UNIX_TIMESTAMP() の epoch(秒)で返す。
 *
 * 以前は `sent_at` の文字列("2026-08-09 01:22:31")をそのまま返し、chat.php 側が
 * `new Date(sentAt)` に渡していた。JS はこの形式を**ブラウザのローカル時刻**として解釈するが、
 * 当時の本番 DB サーバーの時計は UTC 相当だったので、履歴の表示だけが9時間ずれていた
 * (リアルタイム受信分は PHP の date(DATE_ATOM) 由来でオフセット付きのため正しかった)。
 * epoch は絶対時刻なので、DB と PHP のタイムゾーン設定に関係なく正しい現地時刻に直せる。
 *
 * @return array<int, array{id:int, senderId: string, senderName: string, message: string, sentAtEpoch: int}>
 */
function km_chat_recent_messages(PDO $pdo, int $limit = 100): array
{
    km_chat_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, sender_id AS senderId, sender_name AS senderName, message,
                UNIX_TIMESTAMP(sent_at) AS sentAtEpoch
         FROM km_chat_messages ORDER BY id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    // UNIX_TIMESTAMP() は BIGINT なので PDO が文字列で返すことがある。JSON で数値として
    // 出す(= JS 側で素直に扱える)ようにここで int に寄せておく。
    $rows = array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['sentAtEpoch'] = (int) $row['sentAtEpoch'];
        return $row;
    }, $stmt->fetchAll());

    // DB からは新しい順に取るのが LIMIT の効率上自然だが、画面には古い→新しい順で
    // 出したいので反転する。
    return array_reverse($rows);
}

/*
 * ここから既読の管理。
 *
 * **持つのは「どこまで読んだか」の1点だけ**(利用者ごとに最後に読んだメッセージ id)。
 * メッセージ×利用者の全組み合わせを持つ方式もあるが、チャットは必ず古い順に読むので
 * 「ここまで読んだ」が分かれば未読数も既読数も導ける。行数も利用者数ぶんで済む。
 */

function km_chat_ensure_reads_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_chat_reads (
            user_id VARCHAR(191) NOT NULL PRIMARY KEY,
            user_name VARCHAR(255) NOT NULL,
            last_read_id INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/**
 * 「ここまで読んだ」を進める。
 *
 * **戻すことはしない**(GREATEST で常に大きい方を採る)。画面を複数開いていて、
 * 古い方のタブが後から「ここまで読んだ」を送ってくることがあるため。
 */
function km_chat_mark_read(PDO $pdo, string $userId, string $userName, int $lastReadId): void
{
    km_chat_ensure_reads_table($pdo);

    $pdo->prepare(
        'INSERT INTO km_chat_reads (user_id, user_name, last_read_id, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE user_name = VALUES(user_name),
                                 last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
                                 updated_at = NOW()'
    )->execute([$userId, mb_substr($userName, 0, 255), $lastReadId]);
}

/**
 * その人の未読件数。**自分の発言は数えない**(自分が書いたものを未読とは言わない)。
 *
 * 一度も開いたことがない人は last_read_id が無いので 0 として扱う = 全部未読になる。
 */
function km_chat_unread_count(PDO $pdo, string $userId): int
{
    km_chat_ensure_table($pdo);
    km_chat_ensure_reads_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM km_chat_messages
         WHERE sender_id <> ?
           AND id > COALESCE((SELECT last_read_id FROM km_chat_reads WHERE user_id = ?), 0)'
    );
    $stmt->execute([$userId, $userId]);

    return (int) $stmt->fetchColumn();
}

/**
 * 誰がどこまで読んだか。画面の「既読」表示に使う。
 *
 * @return array<int, array{userId:string, userName:string, lastReadId:int}>
 */
function km_chat_read_receipts(PDO $pdo): array
{
    km_chat_ensure_reads_table($pdo);

    return array_map(
        static fn (array $row): array => [
            'userId' => (string) $row['userId'],
            'userName' => (string) $row['userName'],
            'lastReadId' => (int) $row['lastReadId'],
        ],
        $pdo->query(
            'SELECT user_id AS userId, user_name AS userName, last_read_id AS lastReadId
             FROM km_chat_reads WHERE last_read_id > 0'
        )->fetchAll()
    );
}

/**
 * 画面のバッジ用。ヘッダーとサイドバーの両方から呼ばれるので、1リクエスト内では1回だけ数える。
 *
 * **DB が落ちていてもバッジのために画面を壊さない**ので、失敗したら null を返す
 * (呼び出し側はバッジを出さない)。
 */
function km_chat_unread_badge(string $userId): ?int
{
    static $cache = [];

    if ($userId === '') {
        return null;
    }
    if (!array_key_exists($userId, $cache)) {
        $cache[$userId] = null;
        try {
            require_once __DIR__ . '/db.php';
            $cache[$userId] = km_chat_unread_count(km_db(), $userId);
        } catch (Throwable $exception) {
            error_log('km_chat_unread_badge() failed (badge hidden): ' . $exception->getMessage());
        }
    }

    return $cache[$userId];
}
