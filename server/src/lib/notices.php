<?php

declare(strict_types=1);

/**
 * 来場者へのお知らせ(2026-10-24、利用者の指示)。
 *   - 「最初の画面で PHP 脆弱性についての報告文を作成。この機能を実装」
 *   - 「お知らせは今の管理画面に編集画面(詳細ページも)を足す。プレビュー機能があると尚良し」
 *
 * - 管理画面「お知らせ」(admin/notices.php)で書く。下書き・公開・プレビューができる
 * - 公開したものは公開ページ /notice.php?id=<番号>(くわしいページ)と /notice.php(一覧)に出る
 * - 「最初の画面に出す」を付けたものは、公開の地図の「はじめに」の先頭に短い文(summary)を出す(index.php・Main/welcome.js)。
 *   出す期間の終わり(show_until)を過ぎたら最初の画面には出さない(くわしいページは残る)
 * - 本文は**決まった書き方の文**(HTML は書かせない): 「## 」で始まる行が見出し、空行で段落を分ける。段落の中の改行はそのまま
 * - **別の表**(km_notices)。CREATE だけで足りる。表を作ったときだけ、PHP の脆弱性のお知らせを 1 件目として入れる
 */

require_once __DIR__ . '/db.php';

const KM_NOTICE_TITLE_MAX = 100;
const KM_NOTICE_SUMMARY_MAX = 500;
const KM_NOTICE_BODY_MAX = 20000;

function km_notices_ensure_table(PDO $pdo): void
{
    $created = !km_db_table_exists($pdo, 'km_notices');
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            summary VARCHAR(500) NOT NULL,
            body TEXT NOT NULL,
            published_on DATE NOT NULL,
            show_on_welcome TINYINT(1) NOT NULL DEFAULT 1,
            show_until DATE NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (published_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    if ($created) {
        km_notices_seed($pdo);
    }
}

/**
 * 1 件目: PHP の脆弱性の報告(2026-10-24 に書いた文。docs/status.md の「PHP の RCE」の記録にある事実だけ)。
 * 表を作ったときだけ入れる(消したあとで復活させない。lib/faq.php の km_faq_seed_once と同じ考え)
 */
function km_notices_seed(PDO $pdo): void
{
    $body = <<<'TEXT'
## 何があったか
2026年10月21日、Web サーバーのプログラムである PHP の本体に、ログインなしで外部からプログラムを実行されるおそれのある脆弱性が 2 件公表されました(CVE-2026-109618 / CVE-2026-109619。深刻度 CVSS v4.0 で 9.2)。

このサイトの Web サーバーと、データベースを管理する道具が、対象の版の PHP を使っていました。

## 行った対応
10月21日: 攻撃に使われる種類の通信(ファイルを送る通信)を、サイトの入口で止める一時的な対策を入れました。この間は、画像などを送る機能が使えませんでした。

10月21日〜22日: 修正版が出るまで、入口以外のサーバーを止めて待ちました。この間は「メンテナンス中」の画面を出していました。

10月22日: PHP を修正版(8.4.27)に、データベースを管理する道具も新しい版に更新し、一時的な対策を外して通常どおりに戻しました。

## 影響の確認
サーバーの中で書き込みのできる場所・設定・Web サーバーの記録・外から送られた通信の記録を調べ、不正に使われた跡は見つかりませんでした。

確認した範囲で、個人の情報が外へ出たことを示すものはありません。

## 今後の取り組み
サーバーが止まっているときに「メンテナンス中」の画面を出す仕組みを、これからも残します。

使っていない機能を止め、緊急のときにサーバーを止めたまま更新できるよう、更新の手順を直しました。

これからも、使っている部品の脆弱性の情報を確かめ、早めに更新します。

## 皆さまへ
利用者の皆さまに行っていただく操作はありません。ご心配な場合や、気になることがあった場合は、お問い合わせフォームからお知らせください。

ご不便をおかけし、申し訳ありませんでした。
TEXT;
    $pdo->prepare(
        'INSERT INTO km_notices (title, summary, body, published_on, show_on_welcome, show_until, is_published, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, ?, 1, NOW(), NOW())'
    )->execute([
        'PHP の脆弱性への対応について',
        '2026年10月21日、このサイトの Web サーバーが使っている PHP に、外部から操作されるおそれのある重大な脆弱性が公表されました。'
            . '安全のため一時的にサイトを止め、10月22日に修正版へ更新しました。確認した範囲では、不正に使われた跡は見つかっていません。'
            . '利用者の皆さまに行っていただく操作はありません。',
        $body,
        '2026-10-24',
        '2026-11-30',
    ]);
}

/**
 * 本文を見出しと段落に分ける。**純粋関数**(公開ページ・管理画面のプレビュー・scripts/check.php が同じものを使う)。
 * 「## 」で始まる行が見出し。空行で段落を分ける。見出しより前の段落は見出しなしの節に入れる
 *
 * @return list<array{heading:?string, paragraphs:list<string>}>
 */
function km_notice_parse_body(string $body): array
{
    $sections = [];
    $current = ['heading' => null, 'paragraphs' => []];
    $paragraph = [];
    $flush = static function () use (&$paragraph, &$current): void {
        if ($paragraph !== []) {
            $current['paragraphs'][] = implode("\n", $paragraph);
            $paragraph = [];
        }
    };
    foreach (preg_split('/\R/u', str_replace("\r\n", "\n", $body)) ?: [] as $line) {
        if (preg_match('/^##\s+(.+)$/u', trim($line), $m) === 1) {
            $flush();
            if ($current['heading'] !== null || $current['paragraphs'] !== []) {
                $sections[] = $current;
            }
            $current = ['heading' => trim($m[1]), 'paragraphs' => []];
            continue;
        }
        if (trim($line) === '') {
            $flush();
            continue;
        }
        $paragraph[] = rtrim($line);
    }
    $flush();
    if ($current['heading'] !== null || $current['paragraphs'] !== []) {
        $sections[] = $current;
    }
    return $sections;
}

/** 日付の入力(Y-m-d)を確かめる。空は null。おかしければ例外。**純粋関数** */
function km_notice_date(string $value, string $label): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        throw new InvalidArgumentException($label . 'の日付の形が正しくありません(例: 2026-11-30)。');
    }
    return $value;
}

/**
 * 画面の入力を確かめて整える。**純粋関数**(scripts/check.php が試す)
 *
 * @return array{title:string, summary:string, body:string, publishedOn:string, showOnWelcome:bool, showUntil:?string, isPublished:bool}
 */
function km_notice_validate(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $summary = str_replace("\r\n", "\n", trim((string) ($input['summary'] ?? '')));
    $body = str_replace("\r\n", "\n", trim((string) ($input['body'] ?? '')));
    if ($title === '') {
        throw new InvalidArgumentException('題を入れてください。');
    }
    if (mb_strlen($title) > KM_NOTICE_TITLE_MAX) {
        throw new InvalidArgumentException('題は ' . KM_NOTICE_TITLE_MAX . ' 字までにしてください。');
    }
    if ($summary === '') {
        throw new InvalidArgumentException('短い文(最初の画面に出す文)を入れてください。');
    }
    if (mb_strlen($summary) > KM_NOTICE_SUMMARY_MAX) {
        throw new InvalidArgumentException('短い文は ' . KM_NOTICE_SUMMARY_MAX . ' 字までにしてください。');
    }
    if (mb_strlen($body) > KM_NOTICE_BODY_MAX) {
        throw new InvalidArgumentException('本文は ' . KM_NOTICE_BODY_MAX . ' 字までにしてください。');
    }
    $publishedOn = km_notice_date((string) ($input['publishedOn'] ?? ''), '日付') ?? throw new InvalidArgumentException('日付を入れてください。');
    $showUntil = km_notice_date((string) ($input['showUntil'] ?? ''), '最初の画面に出す最後の日');
    if ($showUntil !== null && $showUntil < $publishedOn) {
        throw new InvalidArgumentException('最初の画面に出す最後の日は、日付より後にしてください。');
    }
    return [
        'title' => $title,
        'summary' => $summary,
        'body' => $body,
        'publishedOn' => $publishedOn,
        'showOnWelcome' => (bool) ($input['showOnWelcome'] ?? false),
        'showUntil' => $showUntil,
        'isPublished' => (bool) ($input['isPublished'] ?? false),
    ];
}

/** DB の行 → 画面の形。sections は本文を分けたもの */
function km_notice_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'summary' => (string) $row['summary'],
        'body' => (string) $row['body'],
        'sections' => km_notice_parse_body((string) $row['body']),
        'date' => (string) $row['published_on'],
        'showOnWelcome' => (int) $row['show_on_welcome'] === 1,
        'until' => $row['show_until'] === null ? null : (string) $row['show_until'],
        'isPublished' => (int) $row['is_published'] === 1,
    ];
}

/** @return list<array<string, mixed>> 全部(管理画面)。新しい順 */
function km_notices_all(PDO $pdo): array
{
    km_notices_ensure_table($pdo);
    return array_map('km_notice_row', $pdo->query('SELECT * FROM km_notices ORDER BY published_on DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * 公開しているもの(公開の印があり、日付が来ているもの。新しい順)。表が無ければ空(公開ページからは作らない)。
 *
 * @return list<array<string, mixed>>
 */
function km_notices_public(PDO $pdo, string $today): array
{
    try {
        $statement = $pdo->prepare('SELECT * FROM km_notices WHERE is_published = 1 AND published_on <= ? ORDER BY published_on DESC, id DESC');
        $statement->execute([$today]);
        return array_map('km_notice_row', $statement->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $exception) {
        return [];
    }
}

function km_notice_find(PDO $pdo, int $id): ?array
{
    km_notices_ensure_table($pdo);
    $statement = $pdo->prepare('SELECT * FROM km_notices WHERE id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? km_notice_row($row) : null;
}

/**
 * 最初の画面に出すもの(公開しているもののうち、「最初の画面に出す」が付いていて、出す期間の中のもの。新しい順)。**純粋関数**
 *
 * @param list<array<string, mixed>> $notices km_notices_public の戻り
 * @return list<array<string, mixed>>
 */
function km_notices_current(string $today, array $notices): array
{
    return array_values(array_filter($notices, static fn (array $n): bool => ($n['showOnWelcome'] ?? true)
        && $n['date'] <= $today && ($n['until'] === null || $today <= $n['until'])));
}

/** 保存する(id が null なら足す)。保存した行の番号を返す */
function km_notice_save(PDO $pdo, ?int $id, array $fields): int
{
    km_notices_ensure_table($pdo);
    $values = [$fields['title'], $fields['summary'], $fields['body'], $fields['publishedOn'], $fields['showOnWelcome'] ? 1 : 0, $fields['showUntil'], $fields['isPublished'] ? 1 : 0];
    if ($id === null) {
        $pdo->prepare(
            'INSERT INTO km_notices (title, summary, body, published_on, show_on_welcome, show_until, is_published, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute($values);
        return (int) $pdo->lastInsertId();
    }
    if (km_notice_find($pdo, $id) === null) {
        throw new InvalidArgumentException('そのお知らせは見つかりません(消されたかもしれません)。');
    }
    $values[] = $id;
    $pdo->prepare(
        'UPDATE km_notices SET title = ?, summary = ?, body = ?, published_on = ?, show_on_welcome = ?, show_until = ?, is_published = ?, updated_at = NOW() WHERE id = ?'
    )->execute($values);
    return $id;
}

/** 消す。消した題(無ければ null) */
function km_notice_delete(PDO $pdo, int $id): ?string
{
    $row = km_notice_find($pdo, $id);
    if ($row === null) {
        return null;
    }
    $pdo->prepare('DELETE FROM km_notices WHERE id = ?')->execute([$id]);
    return $row['title'];
}

/** 公開ページの id(1 以上の数字)。**純粋関数** */
function km_notice_id_valid(string $id): bool
{
    return preg_match('/^[1-9]\d{0,9}$/', $id) === 1;
}

/** 日付を「2026年10月24日」に */
function km_notice_date_label(string $date): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
        return $date;
    }
    return (int) $m[1] . '年' . (int) $m[2] . '月' . (int) $m[3] . '日';
}

/** 日本時間の今日(Y-m-d)。サーバーの時刻帯の設定に左右されない */
function km_notice_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

/**
 * くわしいページの中身(題の下の日付・短い文・節)。公開ページと管理画面のプレビューで**同じものを出す**。
 * 文字は全部エスケープする(HTML は書かせない)。段落の中の改行は CSS(white-space: pre-wrap)で見せる
 */
function km_notice_detail_html(array $notice): string
{
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<p class="km-notice-date">' . $e(km_notice_date_label((string) $notice['date'])) . '</p>'
        . '<p class="km-notice-summary">' . $e((string) $notice['summary']) . '</p>';
    foreach ($notice['sections'] as $section) {
        $html .= '<section class="km-notice-section">';
        if ($section['heading'] !== null) {
            $html .= '<h2>' . $e($section['heading']) . '</h2>';
        }
        foreach ($section['paragraphs'] as $paragraph) {
            $html .= '<p>' . $e($paragraph) . '</p>';
        }
        $html .= '</section>';
    }
    return $html;
}

/** くわしいページの中身の見た目(公開ページと管理画面のプレビューで同じ) */
const KM_NOTICE_DETAIL_CSS = <<<'CSS'
.km-notice-date { color: #64748b; margin: 0 0 1rem; }
.km-notice-summary { background: #fff; border-left: 4px solid #2563EB; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); padding: 1rem 1.1rem; line-height: 1.8; white-space: pre-wrap; }
.km-notice-section h2 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; }
.km-notice-section p { line-height: 1.8; margin: 0 0 0.6rem; white-space: pre-wrap; }
CSS;
