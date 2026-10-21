<?php

declare(strict_types=1);

require_once __DIR__ . '/posters.php';

/**
 * ポスター(広告)をまとめて取り込む(2026-10-21、利用者の指示)。
 *
 * 高専祭のサイトの模擬店のページ(`https://kosensai.ichinoseki.ac.jp/shops/<番号>/detail`、1〜58)を 1 つずつ読み、
 * 店の名前・コメント・ポスターの画像から、**地点に置かないポスター(= 広告)**を作る。リンクはそのページ。
 *
 * - 番号 **0 は取り込まない**(利用者が試しに作った店。範囲は 1 から)
 * - 同じページ(リンクの URL)のポスターが既にあれば、作り直さずに**直す**(名前・コメント。画像は画像の URL が変わったときだけ)。
 *   出し入れ・並び順・地点は管理画面で変えたものを残す
 * - 読み方は相手のページの形(Next.js が組み立てた HTML)に合わせてある。形が変わったら km_poster_import_parse を直す
 * - 取りに行くのは km_poster_fetch_remote だけ(公開のアドレスだけ・リダイレクトの先も確かめる・大きさの上限)
 *
 * 管理画面は 1 件ずつ admin/api/poster-import.php を呼ぶ(1 回の要求を短く保つ。58 件を 1 回でやると 30 秒を超える)。
 */

const KM_POSTER_IMPORT_TEMPLATE_DEFAULT = 'https://kosensai.ichinoseki.ac.jp/shops/%N/detail';
const KM_POSTER_IMPORT_FIRST_DEFAULT = 1;
const KM_POSTER_IMPORT_LAST_DEFAULT = 58;
/** 番号の下限。**0 は利用者の試しの店なので使わない** */
const KM_POSTER_IMPORT_MIN = 1;
const KM_POSTER_IMPORT_MAX = 999;
/** 取り込んだ広告の並び順 = これ + 番号(手で足したポスターより後ろに並ぶ) */
const KM_POSTER_IMPORT_SORT_BASE = 1000;

/**
 * 番号から読むページの URL を作る。**DB を使わない**(scripts/check.php が試す)。
 * 型紙は `%N` をちょうど 1 つ持つ http(s) の URL。
 */
function km_poster_import_url(string $template, int $number): string
{
    $template = trim($template);
    if (substr_count($template, '%N') !== 1) {
        throw new InvalidArgumentException('URL の型紙には、番号の入る場所 %N をちょうど 1 つ入れてください。');
    }
    if ($number < KM_POSTER_IMPORT_MIN || $number > KM_POSTER_IMPORT_MAX) {
        throw new InvalidArgumentException('番号は ' . KM_POSTER_IMPORT_MIN . '〜' . KM_POSTER_IMPORT_MAX . ' にしてください(0 は使いません)。');
    }
    $url = str_replace('%N', (string) $number, $template);
    if (!km_poster_url_valid($url)) {
        throw new InvalidArgumentException('URL の型紙は http:// か https:// で始まる URL にしてください。');
    }

    return $url;
}

/** ページの中の参照(/img/… など)を、そのページの origin で絶対の URL にする。読めない形は '' */
function km_poster_import_absolute(string $ref, string $pageUrl): string
{
    $ref = trim($ref);
    if (preg_match('#^https?://#i', $ref) === 1) {
        return $ref;
    }
    if (!str_starts_with($ref, '/') || str_starts_with($ref, '//')) {
        return '';
    }
    $parts = parse_url($pageUrl);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return '';
    }

    return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $ref;
}

/** 空白(改行を除く)をそろえる。前後は落とす */
function km_poster_import_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
    $text = (string) preg_replace('/ *\n */u', "\n", $text);
    $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

    return trim($text);
}

/**
 * 模擬店のページを読む。**DB もネットも使わない**(scripts/check.php が見本の HTML で試す)。
 *
 *   <article>
 *     <p>No. 01</p>
 *     <h2>店の名前</h2>
 *     <img src="/_next/image?url=%2Fimg%2Fshops%2F01.webp&w=3840&q=75">   (ポスター。複数のこともある)
 *     <section><h2>コメント</h2><p>本文</p></section>
 *     <section><h2>お知らせ</h2>…</section>
 *   </article>
 *
 * 画像は Next.js の縮小の口(/_next/image?url=…)ではなく、**元の画像**(url= の中身)を取る。
 *
 * @return array{number:?int, title:string, description:string, images:list<string>}
 */
function km_poster_import_parse(string $html, string $pageUrl): array
{
    $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $article = $document->querySelector('main article') ?? $document->querySelector('article');
    if ($article === null) {
        throw new InvalidArgumentException('ページの形が違います(店の欄が見つかりません)。');
    }

    $title = '';
    $description = '';
    foreach ($article->querySelectorAll('h2') as $heading) {
        $text = km_poster_import_text($heading->textContent ?? '');
        if ($text === 'コメント') {
            // 見出しのすぐ後の段落が本文
            $sibling = $heading->nextElementSibling;
            while ($sibling !== null && strtolower($sibling->localName) !== 'p') {
                $sibling = $sibling->nextElementSibling;
            }
            $description = $sibling === null ? '' : km_poster_import_text($sibling->textContent ?? '');
            continue;
        }
        if ($title === '' && $text !== 'お知らせ') {
            $title = $text;
        }
    }

    $number = null;
    foreach ($article->querySelectorAll('p') as $paragraph) {
        if (preg_match('/^No\.\s*(\d{1,4})$/u', km_poster_import_text($paragraph->textContent ?? ''), $match) === 1) {
            $number = (int) $match[1];
            break;
        }
    }

    $images = [];
    foreach ($article->querySelectorAll('img') as $img) {
        $src = (string) $img->getAttribute('src');
        if (str_starts_with($src, '/_next/image?')) {
            parse_str((string) parse_url($src, PHP_URL_QUERY), $query);
            $src = is_string($query['url'] ?? null) ? $query['url'] : '';
        }
        $absolute = km_poster_import_absolute($src, $pageUrl);
        if ($absolute !== '' && km_poster_url_valid($absolute) && !in_array($absolute, $images, true)) {
            $images[] = $absolute;
        }
    }

    return ['number' => $number, 'title' => $title, 'description' => $description, 'images' => $images];
}

/** 長すぎる文字を上限で切る(切ったら … を付ける) */
function km_poster_import_clip(string $text, int $max): string
{
    return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
}

/** リンクの URL が同じポスター(前に取り込んだもの)。無ければ null */
function km_poster_find_by_link(PDO $pdo, string $linkUrl): ?array
{
    km_posters_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT id FROM km_posters WHERE link_url = ? ORDER BY id LIMIT 1');
    $statement->execute([$linkUrl]);
    $id = $statement->fetchColumn();

    return $id === false ? null : km_poster_find($pdo, (int) $id);
}

/**
 * 1 つの番号を取り込む。
 *
 * @return array{status:string, id:?int, title:string, message:string}
 *         status: created(作った)/ updated(直した)/ unchanged(変わりなし)/ missing(その番号のページが無い)
 */
function km_poster_import_one(PDO $pdo, string $template, int $number, bool $enable): array
{
    $pageUrl = km_poster_import_url($template, $number);

    try {
        $html = km_poster_fetch_remote($pageUrl, 'text/html');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getCode() === 404) {
            return ['status' => 'missing', 'id' => null, 'title' => '', 'message' => 'その番号のページがありません(404)。'];
        }
        throw new InvalidArgumentException('ページを読めませんでした: ' . str_replace('画像を', '', $exception->getMessage()));
    }

    $shop = km_poster_import_parse($html, $pageUrl);
    if ($shop['title'] === '') {
        throw new InvalidArgumentException('店の名前が見つかりません(ページの形が変わったかもしれません)。');
    }
    if ($shop['images'] === []) {
        throw new InvalidArgumentException('ポスターの画像が見つかりません。');
    }
    $imageUrl = $shop['images'][0];
    $title = km_poster_import_clip($shop['title'], KM_POSTER_TITLE_MAX);
    $description = km_poster_import_clip($shop['description'], KM_POSTER_DESCRIPTION_MAX);

    $existing = km_poster_find_by_link($pdo, $pageUrl);
    if ($existing === null) {
        $fields = km_poster_validate([
            'title' => $title,
            'description' => $description,
            'linkUrl' => $pageUrl,
            'nodeId' => '',
            'sortOrder' => KM_POSTER_IMPORT_SORT_BASE + $number,
            'isEnabled' => $enable,
        ]);
        $id = km_poster_save($pdo, null, $fields, ['bytes' => km_poster_fetch_remote($imageUrl), 'sourceUrl' => $imageUrl]);

        return ['status' => 'created', 'id' => $id, 'title' => $title, 'message' => '広告を作りました。'];
    }

    // 前に取り込んだもの: 名前・コメントを直す。出し入れ・並び順・地点は管理画面で変えたものを残す
    $sameImage = $existing['sourceUrl'] === $imageUrl;
    if ($sameImage && $existing['title'] === $title && $existing['description'] === $description) {
        return ['status' => 'unchanged', 'id' => $existing['id'], 'title' => $title, 'message' => '変わりありません。'];
    }
    $fields = km_poster_validate([
        'title' => $title,
        'description' => $description,
        'linkUrl' => $pageUrl,
        'nodeId' => $existing['nodeId'],
        'sortOrder' => $existing['sortOrder'],
        'isEnabled' => $existing['isEnabled'],
    ]);
    $image = $sameImage ? null : ['bytes' => km_poster_fetch_remote($imageUrl), 'sourceUrl' => $imageUrl];
    km_poster_save($pdo, $existing['id'], $fields, $image);

    return ['status' => 'updated', 'id' => $existing['id'], 'title' => $title, 'message' => $sameImage ? '名前・コメントを直しました。' : '画像も差し替えました。'];
}
