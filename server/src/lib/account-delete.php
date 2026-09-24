<?php

declare(strict_types=1);

/**
 * アカウントが消されたときの後片付け。
 *
 * ## なぜ要るのか
 *
 * Logto の「アカウントを削除」を開けても、**こちらの DB には行が残る。**
 * Logto は利用者の名簿を持つだけで、ランキングも設定もアバターも
 * この Website 側にあるからだ。残ったままだと:
 *
 *   - ランキングに、もう存在しない人の名前が並び続ける
 *   - 「消したはずの情報が残っている」——プライバシーポリシーと食い違う
 *
 * ## 順番が決まっている
 *
 * **後片付けが動くようになってから、Logto 側を開ける。** 逆にすると、
 * 消えた人の行が**誰のものか分からないまま**残る(`user_id` しか手がかりが無く、
 * Logto から引けなくなる)。
 *
 * ## 監査の記録だけは消さない
 *
 * `km_admin_log` は**誰かを消して行は残す。** これは意図的:
 *
 *   - 管理操作の記録は、**誰が何をしたか**ではなく「何が起きたか」を追うためのもの。
 *     行ごと消すと、**都合の悪い操作をしたあとにアカウントを消せば証跡が消える**
 *   - 名前・ID・接続元 IP・端末の名乗りを落とせば、その人を指し示すものは残らない。
 *     **IP を残すと、時刻と併せて指せてしまう** —— 名前だけ消すのでは足りない
 *
 * 削除そのものの記録(`account.deleted`)も**匿名で書く**。
 * `km_admin_log_record()` の第4引数がそれ。既定のまま呼ぶと、消した本人が
 * サインイン中なので `$KM_USER` から名前が入り、**匿名化した直後に1行だけ蘇る。**
 *
 * **この判断は運用者が決めること。** 行ごと消す運用にするなら
 * [KM_ACCOUNT_DELETE_PURGES_AUDIT] を true にする。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile.php';
// 匿名化で user_agent 列に触ってよいかを聞くため
require_once __DIR__ . '/admin-log.php';

/**
 * 監査の記録を**行ごと消す**か。既定は false(名前だけ消して行は残す)。
 *
 * true にすると、その人の操作の記録が跡形もなく消える。
 * **消えたことも分からなくなる**ので、変えるときは運用者の判断で。
 */
const KM_ACCOUNT_DELETE_PURGES_AUDIT = false;

/**
 * `user_id` を持つ表。**ここに足し忘れると、その表だけ残る。**
 *
 * 表を増やしたらここも足すこと —— `scripts/check.php` の
 * `account-delete` が、`user_id` を持つ表を数えて突き合わせている。
 */
const KM_ACCOUNT_DELETE_TABLES = [
    'km_map_ranking_users',
    'km_app_settings',
    'km_user_profiles',
    'km_chat_reads',
    'km_app_presence',
    // 教職員の申請(lib/staff-org.php。2026-09-18)。メールアドレスと氏名を持つので、退会したら残さない
    'km_staff_requests',
    // 教職員の地点の割り当てと変更の提案(lib/staff-nodes.php。段 G)。提案には氏名が入りうる
    'km_staff_node_assignments',
    'km_staff_node_edits',
];

/**
 * その人のデータを消す。**1回の取引で。**
 *
 * 途中で落ちたときに「ランキングだけ消えて設定は残る」状態を作らない ——
 * どこまで消えたのか分からない中途半端が一番困る。
 *
 * アバターの実体(ファイル)は**取引の外**で消す。ファイルは巻き戻せないので、
 * **DB の commit が済んでから**触る。順番を逆にすると、取引が失敗した後に
 * 画像だけ消えて行が残る。
 *
 * @return array{tables: array<string, int>, auditAnonymized: int, avatarRemoved: bool}
 */
function km_account_delete_data(PDO $pdo, string $userId): array
{
    $userId = trim($userId);
    if ($userId === '') {
        throw new InvalidArgumentException('user_id が空です。');
    }

    // 取引の前に読む。**消したあとでは、どのファイルだったか分からなくなる**
    $avatarStoredName = null;
    try {
        $profile = km_profile_find($pdo, $userId);
        $avatarStoredName = $profile['avatarStoredName'] ?? null;
    } catch (Throwable $exception) {
        // 表がまだ無い環境もある。**消すものが無いだけ**なので続ける
        error_log('km_account_delete_data: profile lookup skipped: ' . $exception->getMessage());
    }

    $deleted = [];
    $anonymized = 0;

    /*
     * **取引の外で聞く。** 列の有無は information_schema への問い合わせで、
     * 取引の中で失敗させたくない。ここで一度決めて、あとは組み立てるだけにする。
     */
    $withUserAgent = km_admin_log_has_user_agent($pdo);

    $pdo->beginTransaction();
    try {
        foreach (KM_ACCOUNT_DELETE_TABLES as $table) {
            try {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?");
                $stmt->execute([$userId]);
                $deleted[$table] = $stmt->rowCount();
            } catch (PDOException $exception) {
                /*
                 * **表がまだ無いことと、消せなかったことを分ける。**
                 * この構成の表は初回アクセス時に作られるので、一度も使われていない
                 * 機能の表は存在しない。それを失敗として扱うと、
                 * **後片付け全体が止まって何も消えなくなる。**
                 */
                if (str_contains($exception->getMessage(), 'Base table or view not found')
                    || str_contains($exception->getMessage(), '1146')) {
                    $deleted[$table] = 0;
                    continue;
                }
                throw $exception;
            }
        }

        if (KM_ACCOUNT_DELETE_PURGES_AUDIT) {
            $stmt = $pdo->prepare('DELETE FROM km_admin_log WHERE actor_id = ?');
            $stmt->execute([$userId]);
            $anonymized = $stmt->rowCount();
        } else {
            /*
             * 名前と ID を落とす。**行は残す**(何が起きたかの記録は消さない)。
             *
             * **接続元 IP と端末の名乗りも一緒に落とす。** 名前だけ消して IP を残すと、
             * 時刻と併せてその人を指せてしまい、「この記録から個人を特定することは
             * できません」(プライバシーポリシー7)と食い違う。
             * 「いつ何が行われたか」は残るので、監査としての用は足りる。
             *
             * `user_agent` は**列が在るときだけ**触る —— 移行 SQL を流す前の環境では
             * 列ごと存在せず、決め打ちにすると後片付け全体が落ちる。
             */
            $columns = 'actor_id = NULL, actor_name = NULL, ip_address = NULL';
            if ($withUserAgent) {
                $columns .= ', user_agent = NULL';
            }
            $stmt = $pdo->prepare("UPDATE km_admin_log SET {$columns} WHERE actor_id = ?");
            $stmt->execute([$userId]);
            $anonymized = $stmt->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    // **commit のあと。** ファイルは巻き戻せない
    $avatarRemoved = false;
    if ($avatarStoredName !== null && $avatarStoredName !== '') {
        km_profile_remove_file($avatarStoredName);
        $avatarRemoved = true;
    }

    return [
        'tables' => $deleted,
        'auditAnonymized' => $anonymized,
        'avatarRemoved' => $avatarRemoved,
    ];
}

/**
 * 後片付けの結果を1行にまとめる。記録(`km_admin_log`)へ入れる文言。
 *
 * **件数を残す。** 「消しました」だけだと、後から
 * 「本当に消えたのか」「そもそも対象が無かったのか」が分からない。
 */
function km_account_delete_summary(array $result): string
{
    $parts = [];
    foreach ($result['tables'] as $table => $count) {
        if ($count > 0) {
            $parts[] = $table . '=' . $count;
        }
    }
    // 0 件は並べない。**何も無かったことを「対象なし」の一言で言えるようにする**
    if ((int) $result['auditAnonymized'] > 0) {
        $parts[] = 'audit' . (KM_ACCOUNT_DELETE_PURGES_AUDIT ? '削除' : '匿名化') . '='
            . $result['auditAnonymized'];
    }
    if ($result['avatarRemoved']) {
        $parts[] = 'avatar=1';
    }

    return $parts === [] ? '対象なし' : implode(' ', $parts);
}
