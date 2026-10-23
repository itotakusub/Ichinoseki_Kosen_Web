<?php

declare(strict_types=1);

/**
 * QR のリンク集(2026-10-24、利用者の指示「https://ito4.jp/qr.php の QR を作った。このページに URL を並べて表示。管理画面で設定」)。
 *
 * - 公開ページ /qr.php に、管理画面(admin/qr-links.php)で登録したリンクを並べる。止めたものは出さない
 * - 1 行 = 名前・URL・説明(任意)・出すか・並び順
 * - URL は http / https の絶対 URL か、このサイトの中の道(`/posters.php` のように `/` で始まるもの)だけ
 *   (javascript: などを入れさせない。ポスターのリンクと同じ確かめ方 km_poster_url_valid)
 * - **別の表**(km_qr_links)。CREATE だけで足りる
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/posters.php';

const KM_QR_LINK_TITLE_MAX = 100;
const KM_QR_LINK_DESCRIPTION_MAX = 300;
const KM_QR_LINK_URL_MAX = 500;

function km_qr_links_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_qr_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            url VARCHAR(500) NOT NULL,
            description VARCHAR(300) NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** URL として受け付けるか(絶対の http / https か、このサイトの中の道)。**純粋関数** */
function km_qr_link_url_valid(string $url): bool
{
    if ($url === '' || strlen($url) > KM_QR_LINK_URL_MAX) {
        return false;
    }
    // サイトの中の道: `/` で始まり `//`(よそのサイト)ではない。空白・制御文字・バックスラッシュは入れない
    if (str_starts_with($url, '/')) {
        return !str_starts_with($url, '//') && preg_match('#^/[^\s\x00-\x1F\x7F\\\\]*$#', $url) === 1;
    }
    return km_poster_url_valid($url);
}

/**
 * 画面の入力を確かめて整える。**純粋関数**(scripts/check.php が試す)
 *
 * @return array{title:string, url:string, description:?string, isEnabled:bool}
 */
function km_qr_link_validate(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $url = trim((string) ($input['url'] ?? ''));
    $description = str_replace("\r\n", "\n", trim((string) ($input['description'] ?? '')));
    if ($title === '') {
        throw new InvalidArgumentException('名前を入れてください。');
    }
    if (mb_strlen($title) > KM_QR_LINK_TITLE_MAX) {
        throw new InvalidArgumentException('名前は ' . KM_QR_LINK_TITLE_MAX . ' 字までにしてください。');
    }
    if (!km_qr_link_url_valid($url)) {
        throw new InvalidArgumentException('URL は https:// で始まる形か、このサイトの中の道(/ で始まる。例: /posters.php)にしてください。');
    }
    if (mb_strlen($description) > KM_QR_LINK_DESCRIPTION_MAX) {
        throw new InvalidArgumentException('説明は ' . KM_QR_LINK_DESCRIPTION_MAX . ' 字までにしてください。');
    }
    return [
        'title' => $title,
        'url' => $url,
        'description' => $description === '' ? null : $description,
        'isEnabled' => (bool) ($input['isEnabled'] ?? false),
    ];
}

function km_qr_link_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'url' => (string) $row['url'],
        'description' => (string) ($row['description'] ?? ''),
        'isEnabled' => (int) $row['is_enabled'] === 1,
        'sortOrder' => (int) $row['sort_order'],
    ];
}

/** @return list<array<string, mixed>> 全部(管理画面)。並び順 */
function km_qr_links_all(PDO $pdo): array
{
    km_qr_links_ensure_table($pdo);
    $rows = $pdo->query('SELECT * FROM km_qr_links ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    return array_map('km_qr_link_row', $rows);
}

/** @return list<array<string, mixed>> 公開ページに出すもの。表が無ければ空(作らない) */
function km_qr_links_public(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT * FROM km_qr_links WHERE is_enabled = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        // 表がまだ無い(管理画面の「QR のリンク集」をまだ開いていない)
        return [];
    }
    // 入れたあとで確かめ方を変えても、通らなくなった URL は出さない
    return array_values(array_filter(array_map('km_qr_link_row', $rows), static fn (array $link): bool => km_qr_link_url_valid($link['url'])));
}

function km_qr_link_find(PDO $pdo, int $id): ?array
{
    km_qr_links_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_qr_links WHERE id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? km_qr_link_row($row) : null;
}

/** 保存する(id が null なら末尾に足す)。保存した行の番号を返す */
function km_qr_link_save(PDO $pdo, ?int $id, array $fields): int
{
    km_qr_links_ensure_table($pdo);
    if ($id === null) {
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM km_qr_links')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO km_qr_links (title, url, description, is_enabled, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$fields['title'], $fields['url'], $fields['description'], $fields['isEnabled'] ? 1 : 0, $next]);
        return (int) $pdo->lastInsertId();
    }
    if (km_qr_link_find($pdo, $id) === null) {
        throw new InvalidArgumentException('そのリンクは見つかりません(消されたかもしれません)。');
    }
    $pdo->prepare('UPDATE km_qr_links SET title = ?, url = ?, description = ?, is_enabled = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$fields['title'], $fields['url'], $fields['description'], $fields['isEnabled'] ? 1 : 0, $id]);
    return $id;
}

/** 消す。消した名前(無ければ null) */
function km_qr_link_delete(PDO $pdo, int $id): ?string
{
    $row = km_qr_link_find($pdo, $id);
    if ($row === null) {
        return null;
    }
    $pdo->prepare('DELETE FROM km_qr_links WHERE id = ?')->execute([$id]);
    return $row['title'];
}

/** 並びを 1 つ上か下へ(lib/faq.php の km_faq_move と同じ作り: 並びどおりに振り直してから 2 つを入れ替える) */
function km_qr_link_move(PDO $pdo, int $id, string $direction): void
{
    $rows = km_qr_links_all($pdo);
    $ids = array_column($rows, 'id');
    $index = array_search($id, $ids, true);
    if ($index === false) {
        return;
    }
    $target = $direction === 'up' ? $index - 1 : $index + 1;
    if ($target < 0 || $target >= count($ids)) {
        return;
    }
    [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
    $statement = $pdo->prepare('UPDATE km_qr_links SET sort_order = ? WHERE id = ?');
    $pdo->beginTransaction();
    try {
        foreach ($ids as $i => $rowId) {
            $statement->execute([$i * 10, $rowId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
