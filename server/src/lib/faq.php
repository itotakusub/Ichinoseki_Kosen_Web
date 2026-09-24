<?php

declare(strict_types=1);

/**
 * よくある質問(admin/faq.php で編集し、公開ページ /faq.php にも出す)。
 *
 * **文言は日本語の自由文として1本で持つ**(ja/en の2列にはしない)。理由:
 *   - 公開ページ(KosenMap 側)にはそもそも言語切り替えが無い(フェーズ4の判断)。
 *     二か国語で持っても出す先が無い
 *   - 利用者が入れた自由文に data-i18n を付けない、というこのプロジェクトの決め事に沿う
 *     (km_tasks の title、km_events の label と同じ扱い)
 *   - フェーズ10で projects.php のハードコード14行を km_tasks へ移したときと同じやり方。
 *     そのとき page.projects.row.* を辞書から消したのと同様、page.faq.q1..a5 も不要になる
 *
 * 公開/非公開を行ごとに持たせてある。管理画面向けの質問(「管理者権限が無いと言われました」等)を
 * 公開ページに出したくないため。
 */

require_once __DIR__ . '/db.php';

function km_faq_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_faq (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question VARCHAR(255) NOT NULL,
            answer TEXT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (sort_order),
            INDEX (is_public)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/**
 * 初期データ。移行前に admin/faq.php へ直接書いてあった5件をそのまま入れる
 * (既存の内容を失わせない)。**表を新しく作ったときだけ**投入する。
 *
 * 以前は「表が空なら入れる」だったので、全部消すと次の表示で5件が復活した
 * (2026-09-18、同じ作りの km_tasks で「消しても生成される」と報告)。
 *
 * この5件はどれも管理画面の使い方の話なので is_public = 0 で入れる。
 * 公開ページ向けの質問は、管理画面から新しく足してもらう想定。
 */
function km_faq_seed_once(PDO $pdo): void
{
    if (km_db_table_exists($pdo, 'km_faq')) {
        return;
    }
    km_faq_ensure_table($pdo);

    $seed = [
        [
            'パスワードはどこで変更しますか?',
            'この管理画面では扱いません。ログイン・パスワード・メールアドレスはすべて Logto が管理しています。'
                . 'ユーザーメニューの「Logto Console で管理」から変更してください。',
        ],
        [
            'なぜ AdminLTE のデザインをそのまま使っているのですか?',
            'AdminLTE 本体(css / js / assets)は無編集で使う方針にしています。デザインが壊れにくく、'
                . '本体のアップデートを取り込みやすくするためです。',
        ],
        [
            '管理者権限が無いと言われました',
            'Logto の role: kosenmap-admin が割り当てられていません。Logto Console でロールを付与してから、'
                . '一度ログアウトして入り直してください。',
        ],
        [
            'なぜ言語設定と配色設定は別々に保存されるのですか?',
            '配色は AdminLTE 本体の機能(localStorage の lte-theme)、言語はこの管理画面が独自に追加した機能'
                . '(localStorage の kmadmin-lang)だからです。互いに干渉しないように、意図的に別の仕組みにしています。',
        ],
        /*
         * 「証明書の警告が出ます」の項目は 1.0.5 で外した。
         * 本番は Let's Encrypt の証明書で出ているので警告は出ず、
         * ローカル CA を配る導線も無くなっている(lib/distributables.php)。
         *
         * **既に seed 済みの環境には残る。** この関数は表を作ったときしか走らないので、
         * 消すなら管理画面の「よくある質問」から削除すること。
         */
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO km_faq (question, answer, is_public, sort_order, created_at, updated_at)
         VALUES (?, ?, 0, ?, NOW(), NOW())'
    );
    foreach ($seed as $i => [$question, $answer]) {
        $stmt->execute([$question, $answer, $i * 10]);
    }
}

function km_faq_validate(string $question, string $answer): void
{
    if (trim($question) === '') {
        throw new InvalidArgumentException('質問を入力してください。');
    }
    if (mb_strlen($question) > 255) {
        throw new InvalidArgumentException('質問は255文字以内にしてください。');
    }
    if (trim($answer) === '') {
        throw new InvalidArgumentException('回答を入力してください。');
    }
    if (mb_strlen($answer) > 5000) {
        throw new InvalidArgumentException('回答は5000文字以内にしてください。');
    }
}

/**
 * @param bool $publicOnly true なら公開扱いの行だけ(公開ページ用)
 * @return array<int, array{id:int, question:string, answer:string, isPublic:int, sortOrder:int, updatedAtEpoch:int}>
 */
function km_faq_all(PDO $pdo, bool $publicOnly = false): array
{
    km_faq_ensure_table($pdo);

    $sql = 'SELECT id, question, answer, is_public AS isPublic, sort_order AS sortOrder,
                   UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
            FROM km_faq';
    if ($publicOnly) {
        $sql .= ' WHERE is_public = 1';
    }
    $sql .= ' ORDER BY sort_order, id';

    return $pdo->query($sql)->fetchAll();
}

function km_faq_find(PDO $pdo, int $id): ?array
{
    km_faq_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, question, answer, is_public AS isPublic, sort_order AS sortOrder FROM km_faq WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function km_faq_create(PDO $pdo, string $question, string $answer, bool $isPublic): void
{
    km_faq_ensure_table($pdo);
    km_faq_validate($question, $answer);

    // 新しい行は末尾に置く
    $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM km_faq')->fetchColumn();

    $pdo->prepare(
        'INSERT INTO km_faq (question, answer, is_public, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())'
    )->execute([trim($question), trim($answer), $isPublic ? 1 : 0, $next]);
}

function km_faq_update(PDO $pdo, int $id, string $question, string $answer, bool $isPublic): void
{
    km_faq_ensure_table($pdo);
    km_faq_validate($question, $answer);

    $pdo->prepare('UPDATE km_faq SET question = ?, answer = ?, is_public = ?, updated_at = NOW() WHERE id = ?')
        ->execute([trim($question), trim($answer), $isPublic ? 1 : 0, $id]);
}

function km_faq_delete(PDO $pdo, int $id): void
{
    km_faq_ensure_table($pdo);
    $pdo->prepare('DELETE FROM km_faq WHERE id = ?')->execute([$id]);
}

/**
 * 並び順を1つ入れ替える。
 *
 * ドラッグ&ドロップは作らない(FAQ は数が少なく、上下ボタンで足りる)。
 * sort_order が同値でも安定するよう、id を第2キーにした並びの中での隣と入れ替える。
 */
function km_faq_move(PDO $pdo, int $id, string $direction): void
{
    km_faq_ensure_table($pdo);

    $rows = km_faq_all($pdo);
    $index = null;
    foreach ($rows as $i => $row) {
        if ((int) $row['id'] === $id) {
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

    // 並びが崩れている(同じ sort_order が並ぶ)場合でも確実に入れ替わるよう、
    // 一覧の順番どおりに 10 刻みで振り直してから2つを交換する
    $ids = array_column($rows, 'id');
    [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

    $stmt = $pdo->prepare('UPDATE km_faq SET sort_order = ? WHERE id = ?');
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
