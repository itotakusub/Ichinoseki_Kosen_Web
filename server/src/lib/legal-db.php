<?php

declare(strict_types=1);

/**
 * 利用規約とプライバシーポリシーへ**足す章**。admin/legal.php が編集する。
 *
 * ## 本文は移さない
 *
 * 規約とポリシーの本文は `lib/legal.php` にコードとして置いたまま。
 * **`scripts/check.php` の `km_check_legal()` が、本文の一文を見て
 * 「実装と文書が食い違っていないか」を確かめている**からだ:
 *
 *     check_bool('自動削除を約束していない',
 *         !str_contains($privacyText, 'アカウントを削除すると、…削除されます'));
 *     check_bool('ランキングは自分で消せると書いてある',
 *         str_contains($privacyText, '参加をやめた時点で削除されます'));
 *
 * DB へ移すと、**配備のときに DB を見ない検査からは何も見えなくなる。**
 * 文書だけが実装から離れていくのが、この2つの文書で一番起きやすい壊れ方なので、
 * そこを見張っている足場は崩さない。
 *
 * ## 足せるのは章
 *
 * 会場の決まりごと、その年の注意事項、追加した連絡先 —— **運用で増えるもの**を、
 * コードを触らずに足せるようにする。書けるのは見出しと本文で、
 * `**強調**` は本文と同じ `km_legal_inline()` を通る。
 *
 * ## `lib/faq.php` と同じ形にしてある
 *
 * 表の作り方も、上下の入れ替えも、公開の切り替えも同じ。
 * **2つ目の書き方を持ち込まない** —— 管理画面の作りが揃っていれば、
 * 片方を直したときにもう片方でも同じことをすればよいと分かる。
 */

require_once __DIR__ . '/db.php';

/** 足せる文書。ここに無いものは受け付けない(表の値を推測で通さない)。 */
const KM_LEGAL_DOCUMENTS = ['terms', 'privacy'];

/** 画面に出す名前。 */
const KM_LEGAL_DOCUMENT_LABELS = [
    'terms' => '利用規約',
    'privacy' => 'プライバシーポリシー',
];

function km_legal_db_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_legal_sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document VARCHAR(16) NOT NULL,
            heading VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (document, sort_order),
            INDEX (is_public)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/**
 * @throws InvalidArgumentException 利用者に見せてよい理由を入れる
 */
function km_legal_db_validate(string $document, string $heading, string $body): void
{
    if (!in_array($document, KM_LEGAL_DOCUMENTS, true)) {
        throw new InvalidArgumentException('文書の指定が不正です。');
    }
    if (trim($heading) === '') {
        throw new InvalidArgumentException('見出しを入力してください。');
    }
    if (mb_strlen($heading) > 255) {
        throw new InvalidArgumentException('見出しは255文字以内にしてください。');
    }
    if (trim($body) === '') {
        throw new InvalidArgumentException('本文を入力してください。');
    }
    if (mb_strlen($body) > 20000) {
        throw new InvalidArgumentException('本文は20000文字以内にしてください。');
    }
}

/**
 * @param bool $publicOnly true なら公開扱いの章だけ(公開ページ用)
 * @return array<int, array{id:int, document:string, heading:string, body:string,
 *                          isPublic:int, sortOrder:int, updatedAtEpoch:int}>
 */
function km_legal_db_all(PDO $pdo, ?string $document = null, bool $publicOnly = false): array
{
    km_legal_db_ensure_table($pdo);

    $sql = 'SELECT id, document, heading, body, is_public AS isPublic, sort_order AS sortOrder,
                   UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
            FROM km_legal_sections';
    $where = [];
    $params = [];
    if ($document !== null) {
        $where[] = 'document = ?';
        $params[] = $document;
    }
    if ($publicOnly) {
        $where[] = 'is_public = 1';
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY sort_order, id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function km_legal_db_find(PDO $pdo, int $id): ?array
{
    km_legal_db_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, document, heading, body, is_public AS isPublic, sort_order AS sortOrder
         FROM km_legal_sections WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function km_legal_db_create(PDO $pdo, string $document, string $heading, string $body, bool $isPublic): void
{
    km_legal_db_ensure_table($pdo);
    km_legal_db_validate($document, $heading, $body);

    // 新しい章は、その文書の末尾に置く
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM km_legal_sections WHERE document = ?'
    );
    $stmt->execute([$document]);
    $next = (int) $stmt->fetchColumn();

    $pdo->prepare(
        'INSERT INTO km_legal_sections (document, heading, body, is_public, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([$document, trim($heading), trim($body), $isPublic ? 1 : 0, $next]);
}

function km_legal_db_update(PDO $pdo, int $id, string $heading, string $body, bool $isPublic): void
{
    km_legal_db_ensure_table($pdo);

    $row = km_legal_db_find($pdo, $id);
    if ($row === null) {
        throw new InvalidArgumentException('その章はありません。');
    }
    // 文書の付け替えはさせない。**規約の章がポリシーへ移ると、読む人の前提が変わる**
    km_legal_db_validate((string) $row['document'], $heading, $body);

    $pdo->prepare(
        'UPDATE km_legal_sections SET heading = ?, body = ?, is_public = ?, updated_at = NOW() WHERE id = ?'
    )->execute([trim($heading), trim($body), $isPublic ? 1 : 0, $id]);
}

function km_legal_db_delete(PDO $pdo, int $id): void
{
    km_legal_db_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_legal_sections WHERE id = ?')->execute([$id]);
}

/**
 * 並び順を1つ入れ替える。**同じ文書の中だけで動かす。**
 *
 * `km_faq_move()` と同じ手 —— 一覧の順に 10 刻みで振り直してから2つを交換する。
 * 同じ `sort_order` が並んでいても確実に入れ替わる。
 */
function km_legal_db_move(PDO $pdo, int $id, string $direction): void
{
    km_legal_db_ensure_table($pdo);

    $row = km_legal_db_find($pdo, $id);
    if ($row === null) {
        return;
    }

    $rows = km_legal_db_all($pdo, (string) $row['document']);
    $index = null;
    foreach ($rows as $i => $candidate) {
        if ((int) $candidate['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return;
    }

    $target = $direction === 'up' ? $index - 1 : $index + 1;
    if ($target < 0 || $target >= count($rows)) {
        return;
    }

    $ids = array_column($rows, 'id');
    [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

    $stmt = $pdo->prepare('UPDATE km_legal_sections SET sort_order = ? WHERE id = ?');
    $pdo->beginTransaction();
    try {
        foreach ($ids as $i => $rowId) {
            $stmt->execute([$i * 10, $rowId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * DB の章を、`km_legal_render_sections()` が受け取る形へ直す。
 *
 * **描画を2つ持たない。** コードの章と同じ関数を通すので、
 * 見出しの出方も `**強調**` の効き方も、必ず同じになる。
 *
 * 本文は**空行で段落に割る** —— 1つの `<p>` に長文を入れると、
 * コードの章(段落ごとに分けてある)と読み心地が変わる。
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array{heading:string, paragraphs:array<int,string>}>
 */
function km_legal_db_to_sections(array $rows): array
{
    $sections = [];
    foreach ($rows as $row) {
        $body = str_replace("\r\n", "\n", (string) $row['body']);
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', $body) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }
        if ($paragraphs === []) {
            continue;
        }
        $sections[] = [
            'heading' => (string) $row['heading'],
            'paragraphs' => $paragraphs,
        ];
    }

    return $sections;
}

/**
 * 公開されている章の、一番新しい更新時刻。無ければ null。
 *
 * 改定日は `KM_LEGAL_UPDATED` とこれの**新しい方**を出す ——
 * 章を足したのに日付が古いままだと、**読む人は前と同じ文書だと思う。**
 */
function km_legal_db_latest_update(PDO $pdo, string $document): ?int
{
    km_legal_db_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM km_legal_sections
         WHERE document = ? AND is_public = 1'
    );
    $stmt->execute([$document]);
    $value = $stmt->fetchColumn();

    return $value === null || $value === false ? null : (int) $value;
}
