<?php

declare(strict_types=1);

/**
 * 来場者へのお知らせ(2026-10-24、利用者の指示「最初の画面で、PHP 脆弱性についての報告文を作成。この機能を実装」)。
 *
 * - 公開の地図の最初の画面(「はじめに」。index.php の #km-welcome・Main/welcome.js)に、**まだ読んでいないお知らせ**を出す。
 *   読んだかは閲覧者の手元(localStorage の km-notices-read)に覚える。サーバーへは送らない
 * - 「はじめに」を読んだことのある人にも、新しいお知らせがあれば 1 回だけ出す
 * - 載せる期間(until)を過ぎたら最初の画面には出さない。くわしい報告のページ(notice.php?id=…)はその後も読める
 * - 中身はここに書く(DB を使わない)。**事実だけを書く** —— 確かめていないこと(「被害は無い」と言い切るなど)は書かない
 *
 * 1 件の形: id(英数字と - )・date(公表日)・until(最初の画面に出す最後の日)・title・summary(最初の画面の短い文)・
 * sections(くわしいページの見出しと段落)
 */

/**
 * @return list<array{id:string, date:string, until:string, title:string, summary:string, sections:list<array{heading:string, paragraphs:list<string>}>}>
 */
function km_notices(): array
{
    return [
        [
            // 2026-10-21 の PHP の RCE(docs/status.md の「PHP の RCE(CVE-2026-109618 / 109619)のつなぎ」)
            'id' => 'php-rce-2026-10',
            'date' => '2026-10-24',
            'until' => '2026-11-30',
            'title' => 'PHP の脆弱性への対応について',
            'summary' => '2026年10月21日、このサイトの Web サーバーが使っている PHP に、外部から操作されるおそれのある重大な脆弱性が公表されました。'
                . '安全のため一時的にサイトを止め、10月22日に修正版へ更新しました。確認した範囲では、不正に使われた跡は見つかっていません。'
                . '利用者の皆さまに行っていただく操作はありません。',
            'sections' => [
                [
                    'heading' => '何があったか',
                    'paragraphs' => [
                        '2026年10月21日、Web サーバーのプログラムである PHP の本体に、ログインなしで外部からプログラムを実行されるおそれのある脆弱性が 2 件公表されました'
                            . '(CVE-2026-109618 / CVE-2026-109619。深刻度 CVSS v4.0 で 9.2)。',
                        'このサイトの Web サーバーと、データベースを管理する道具が、対象の版の PHP を使っていました。',
                    ],
                ],
                [
                    'heading' => '行った対応',
                    'paragraphs' => [
                        '10月21日: 攻撃に使われる種類の通信(ファイルを送る通信)を、サイトの入口で止める一時的な対策を入れました。'
                            . 'この間は、画像などを送る機能が使えませんでした。',
                        '10月21日〜22日: 修正版が出るまで、入口以外のサーバーを止めて待ちました。この間は「メンテナンス中」の画面を出していました。',
                        '10月22日: PHP を修正版(8.4.27)に、データベースを管理する道具も新しい版に更新し、一時的な対策を外して通常どおりに戻しました。',
                    ],
                ],
                [
                    'heading' => '影響の確認',
                    'paragraphs' => [
                        'サーバーの中で書き込みのできる場所・設定・Web サーバーの記録・外から送られた通信の記録を調べ、不正に使われた跡は見つかりませんでした。',
                        '確認した範囲で、個人の情報が外へ出たことを示すものはありません。',
                    ],
                ],
                [
                    'heading' => '今後の取り組み',
                    'paragraphs' => [
                        'サーバーが止まっているときに「メンテナンス中」の画面を出す仕組みを、これからも残します。',
                        '使っていない機能を止め、緊急のときにサーバーを止めたまま更新できるよう、更新の手順を直しました。',
                        'これからも、使っている部品の脆弱性の情報を確かめ、早めに更新します。',
                    ],
                ],
                [
                    'heading' => '皆さまへ',
                    'paragraphs' => [
                        '利用者の皆さまに行っていただく操作はありません。ご心配な場合や、気になることがあった場合は、お問い合わせフォームからお知らせください。',
                        'ご不便をおかけし、申し訳ありませんでした。',
                    ],
                ],
            ],
        ],
    ];
}

/** id の形(英小文字・数字・-)。**純粋関数** */
function km_notice_id_valid(string $id): bool
{
    return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) === 1;
}

/** その id のお知らせ。無ければ null */
function km_notice_find(string $id): ?array
{
    foreach (km_notices() as $notice) {
        if ($notice['id'] === $id) {
            return $notice;
        }
    }
    return null;
}

/**
 * 最初の画面に出すお知らせ(載せる期間の中のもの。新しい順)。**純粋関数**($today は Y-m-d)
 *
 * @return list<array>
 */
function km_notices_current(string $today, ?array $notices = null): array
{
    $current = array_values(array_filter($notices ?? km_notices(), static fn (array $n): bool => $n['date'] <= $today && $today <= $n['until']));
    usort($current, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
    return $current;
}

/** 日付を「2026年10月24日」に */
function km_notice_date_label(string $date): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
        return $date;
    }
    return (int) $m[1] . '年' . (int) $m[2] . '月' . (int) $m[3] . '日';
}
