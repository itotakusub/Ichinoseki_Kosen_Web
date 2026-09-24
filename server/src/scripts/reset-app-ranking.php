<?php

declare(strict_types=1);

/**
 * ランキングの集計を消す。動作確認で入れた値を落とすときと、本番前のリセット用。
 *
 *   # 何が入っているか見るだけ(既定。消さない)
 *   docker compose exec -T web php scripts/reset-app-ranking.php
 *
 *   # 特定のノードUUID・検索語だけ消す
 *   docker compose exec -T web php scripts/reset-app-ranking.php --place=test-node-1 --query=てすと
 *
 *   # 「調べられた語」だけ全部消す(場所と利用者の記録は残す)
 *   docker compose exec -T web php scripts/reset-app-ranking.php --all-queries --yes
 *
 *   # 「利用者」だけ全部消す(場所と調べられた語は残す)
 *   docker compose exec -T web php scripts/reset-app-ranking.php --all-users --yes
 *
 *   # 全部消す(利用者ランキングを含む)
 *   docker compose exec -T web php scripts/reset-app-ranking.php --all --yes
 *
 * **--all は --yes が無いと実行しない。** 集計は貯まるまで時間がかかるので、
 * 打ち間違いで消える経路を作らない。
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/app-ranking.php';

$options = getopt('', ['place::', 'query::', 'all', 'all-queries', 'all-users', 'yes', 'year::']);
$pdo = km_db();
km_ranking_ensure_tables($pdo);

$year = isset($options['year']) ? (int) $options['year'] : (int) date('Y');

function show(PDO $pdo, int $year): void
{
    echo "== {$year}年 ==\n";
    echo "-- よく行かれた場所 --\n";
    foreach (km_ranking_places($pdo, $year, 100) as $row) {
        printf("  %-40s %d\n", $row['nodeUuid'], $row['visits']);
    }
    echo "-- 調べられた語 --\n";
    foreach (km_ranking_queries($pdo, $year, 100) as $row) {
        printf("  %-40s %d\n", $row['query'], $row['searches']);
    }
    echo "-- 利用者 --\n";
    foreach (km_ranking_users($pdo, $year, 100) as $row) {
        printf("  %-40s %d\n", $row['displayName'] ?? '(名前なし)', $row['visits']);
    }
}

/*
 * 検索語だけを全部消す。
 *
 * **「調べられた語」は来場者に見える公開の一覧**で、そこへ出るのは利用者が
 * 打った文字そのもの。不適切な語が入ったときに1語ずつ --query で消すのは現実的でない。
 * 場所と利用者の記録は残したまま、語だけ流せるようにしておく(2026-08-29 追加)。
 *
 * アプリ側は isRecordableSearchQuery() で「地点名に含まれる語」だけを送るように
 * したが、**それ以前に溜まったものはここで消す**。
 */
if (isset($options['all-queries'])) {
    if (!isset($options['yes'])) {
        fwrite(STDERR, "--all-queries には --yes も付けてください(調べられた語が全件消えます)。\n");
        exit(1);
    }
    $removed = $pdo->exec('DELETE FROM km_map_ranking_queries');
    echo "調べられた語を {$removed} 行消しました(場所と利用者の記録は残っています)。\n\n";
    show($pdo, $year);
    exit(0);
}

/*
 * 利用者ランキング(表示名)だけを全部消す。
 *
 * **参加の既定が ON だった 2026-08-29〜09-14 に、同意を取らずに入った表示名**を落とすため
 * (security-review-2026-09-14 §7 の 6)。場所と調べられた語は個人に結び付かないので残す。
 * 端末は次のログインで改めて参加を尋ねる。
 */
if (isset($options['all-users'])) {
    if (!isset($options['yes'])) {
        fwrite(STDERR, "--all-users には --yes も付けてください(利用者ランキングが全件消えます)。\n");
        exit(1);
    }
    $removed = $pdo->exec('DELETE FROM km_map_ranking_users');
    echo "利用者ランキングを {$removed} 行消しました(場所と調べられた語は残っています)。\n\n";
    show($pdo, $year);
    exit(0);
}

if (isset($options['all'])) {
    if (!isset($options['yes'])) {
        fwrite(STDERR, "--all には --yes も付けてください(全件消えます)。\n");
        exit(1);
    }
    foreach (['km_map_ranking_places', 'km_map_ranking_queries', 'km_map_ranking_users'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    echo "全件消しました。\n";
    exit(0);
}

$deleted = 0;

if (isset($options['place']) && $options['place'] !== '') {
    $statement = $pdo->prepare('DELETE FROM km_map_ranking_places WHERE node_uuid = ?');
    $statement->execute([(string) $options['place']]);
    $deleted += $statement->rowCount();
}

if (isset($options['query']) && $options['query'] !== '') {
    $statement = $pdo->prepare('DELETE FROM km_map_ranking_queries WHERE normalized_query = ?');
    $statement->execute([(string) $options['query']]);
    $deleted += $statement->rowCount();
}

if ($deleted > 0) {
    echo "{$deleted} 行消しました。\n\n";
} elseif (!isset($options['place']) && !isset($options['query'])) {
    echo "(消していません。--place / --query / --all を指定してください)\n\n";
} else {
    echo "該当なし。\n\n";
}

show($pdo, $year);
