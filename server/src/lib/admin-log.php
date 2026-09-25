<?php

declare(strict_types=1);

/**
 * 管理操作の監査ログ。admin/timeline.php が表示し、admin/index.php が件数を出す。
 *
 * lib/chat.php / lib/map-rate-limit.php と同じ「初回アクセス時に CREATE TABLE IF NOT
 * EXISTS」パターン。DB ユーザー Main の現権限(SELECT/INSERT/UPDATE/CREATE/DELETE)だけで
 * 完結し、権限の追加付与は要らない。
 *
 * 訪問者 IP の判定は lib/map-rate-limit.php の km_map_rate_limit_ip() をそのまま使う。
 * 「X-Forwarded-For ではなく X-Real-IP を信頼する」という判断はフェーズ5で調べて確定した
 * もので、同じ結論を2箇所に書くと片方だけ直されて食い違うため、定義を1つに保つ。
 *
 * このファイルは admin/_inc/guard.php(= 全管理ページの先頭)から読まれる一方、各ページは
 * その後で自前に lib/db.php を読む。そのため lib の読み込みは全て require_once で統一して
 * ある(plain require が混ざると「後から素の require で再度読まれて関数の二重定義で落ちる」
 * という順序依存の事故が起きる)。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/map-rate-limit.php';

/**
 * timeline.php のフィルターボタンの data-km-filter と同じ値。**両方を同時に直すこと。**
 *
 * content は「管理画面で編集できるコンテンツ」(タスク・予定・ファイル・問い合わせ)用。
 * table(DB のテーブル操作)とは別にしてある。
 */
const KM_ADMIN_LOG_CATEGORIES = ['auth', 'table', 'settings', 'system', 'content'];

/** category ごとの見た目。キーは km_admin_log.category と timeline のフィルター値に対応する。 */
const KM_ADMIN_LOG_ICONS = [
    'auth' => ['icon' => 'bi-box-arrow-in-right', 'accent' => 'text-bg-primary'],
    'table' => ['icon' => 'bi-table', 'accent' => 'text-bg-success'],
    'settings' => ['icon' => 'bi-sliders', 'accent' => 'text-bg-warning'],
    'system' => ['icon' => 'bi-hdd-network', 'accent' => 'text-bg-secondary'],
    'content' => ['icon' => 'bi-pencil-square', 'accent' => 'text-bg-info'],
];

/**
 * 操作の文言。日本語を原文として PHP 側に持ち、辞書(log.action.*)は対訳を持つだけ
 * ——他のページと同じ原則。
 *
 * ここを持たずに raw な action 名("form.submit" 等)を原文にしていると、
 * i18n の JS が走る前や辞書にキーが無いときに、利用者へ内部の識別子がそのまま見えてしまう。
 *
 * **記録側と対で維持すること。** 抜けは検査(scripts/check.php の admin-log)が拾う ——
 * km_admin_log_record() に渡している action を全ファイルから拾い、ここに在るかを見ている。
 *
 * 表示は admin/timeline.php(全員ぶん)と admin/profile.php(自分のぶん)の2箇所。
 * **だから lib に置いてある** —— ページ側に持つと、片方だけ新しい操作を知らない状態になる。
 */
const KM_ADMIN_LOG_ACTION_LABELS = [
    'login' => 'がログインしました',
    /*
     * 拒否とサインアウト(2026-09-14、診断 server-ops#8)。以前は error_log にだけ出ていて、
     * 権限の無い人の試行や停止済みアカウントの再訪がタイムラインに現れなかった。
     * 拒否の2つは「ブラウザセッションにつき1回」に絞って記録している(guard.php / gate.php)。
     */
    'logout' => 'がログアウトしました',
    'admin.denied' => 'が管理画面を開こうとしましたが、管理の権限がありませんでした',
    'admin.write_denied' => 'の変更は、書き込みの権限が無いため断られました',
    'gate.denied' => 'が phpMyAdmin などの管理用ポートへ入ろうとしましたが、権限がありませんでした',
    'account.suspended_blocked' => 'は停止されたアカウントのため、入れませんでした',
    // 実行者は居ない(Logto からの呼び出し)。残る IP は送ってきた相手のもの
    'webhook.signature_failed' => 'が送った Logto の webhook は、署名を確かめられず受け付けませんでした',
    'table.create' => 'がテーブルを作成しました',
    'table.delete' => 'がテーブルを削除しました',
    'row.insert' => 'がレコードを追加しました',
    'row.update' => 'がレコードを更新しました',
    'row.delete' => 'がレコードを削除しました',
    'map.settings' => 'が地図の公開設定を変更しました',
    // 経路の重み(2026-09-25)。管理アプリから api/route-weights.php で配る
    'route.weights_publish' => 'が経路の重みを一般の既定として配りました',
    'route.weights_reset' => 'が経路の重みを配るのをやめました(各端末は既定に戻ります)',
    // 実行者(公開ページの利用者)を頭に置く前提なので「が」から始める
    'map.unlock_failed' => 'が教職員氏名のパスワード解除に失敗しました',
    'task.create' => 'がタスクを追加しました',
    'task.update' => 'がタスクを更新しました',
    'task.delete' => 'がタスクを削除しました',
    'task.move' => 'がタスクを移動しました',
    'event.create' => 'が予定を追加しました',
    'event.update' => 'が予定を更新しました',
    'event.delete' => 'が予定を削除しました',
    'faq.create' => 'がよくある質問を追加しました',
    'faq.update' => 'がよくある質問を編集しました',
    'faq.delete' => 'がよくある質問を削除しました',
    'faq.move' => 'がよくある質問の並び順を変えました',
    // 規約とポリシーへ足した章(本文はコードのまま。admin/legal.php を参照)
    'legal.create' => 'が規約・ポリシーに章を足しました',
    'legal.update' => 'が規約・ポリシーの章を編集しました',
    'legal.delete' => 'が規約・ポリシーの章を削除しました',
    'legal.move' => 'が規約・ポリシーの章の並び順を変えました',
    'form.submit' => 'が問い合わせを送信しました',
    'form.delete' => 'が問い合わせを削除しました',
    'dist.update' => 'が配布ファイルを差し替えました',
    'dist.delete' => 'が配布ファイルの登録を取り消しました',
    'profile.avatar_update' => 'がプロフィール画像を変更しました',
    'profile.avatar_clear' => 'がプロフィール画像を既定に戻しました',
    // **新しいパスワードそのものは記録しない。** 変えたという事実だけ残す
    // アプリへの地図配信。**停止と削除は結果が大きく違う**ので言い分ける
    'map.pause' => 'がアプリへの地図配信を停止しました',
    'map.resume' => 'がアプリへの地図配信を再開しました',
    'map.delete' => 'がアプリへの地図配信を削除しました',
    'account.password' => 'がパスワードを変更しました',
    'account.profile' => 'が利用者名または表示名を変更しました',
    // 教職員の権限(lib/staff-org.php。2026-09-18)。**誰が誰を承認・取り消したか**を残す
    'staff.requested' => 'が教職員の権限を申請しました',
    'staff.approved' => 'が教職員の権限の申請を承認しました',
    'staff.rejected' => 'が教職員の権限の申請を却下しました',
    'staff.revoked' => 'が教職員の権限を取り消しました',
    'staffnode.assigned' => 'が地点を教職員に割り当てました',
    'staffnode.unassigned' => 'が地点の割り当てを外しました',
    'staffnode.proposed' => 'が地点の変更を提案しました',
    'staffnode.approved' => 'が地点の変更の提案を承認しました',
    'staffnode.rejected' => 'が地点の変更の提案を却下しました',
    /*
     * 主語は「あなた」ではない —— 記録するのは webhook を受けたサーバー側で、
     * その時点で**消えた人はもう居ない。**それでも辞書は同じ形にしておく
     * (文言を2つ持つと、片方だけ古くなる)。
     */
    'account.deleted' => 'が削除されたアカウントの情報を片付けました',
    'file.upload' => 'がファイルをアップロードしました',
    'file.delete' => 'がファイルを削除しました',
    'map.node_create' => 'が地図に地点を追加しました',
    'map.node_update' => 'が地図の地点を編集しました',
    'map.node_move' => 'が地図の地点を移動しました',
    'map.node_delete' => 'が地図の地点を削除しました',
    'map.edge_create' => 'が地図の経路をつなぎました',
    'map.edge_delete' => 'が地図の経路を削除しました',
    'map.nodes_align' => 'が地図の地点を整列しました',
    // 氏名は専用の操作でだけ書く(名前や種類と一緒に送れる形にしない)
    'map.node_occupant' => 'が地図の地点の教職員氏名を変えました',
    // アプリの地図からの作り直し(admin/map-sync.php)
    /*
     * 2026-09-03 に向きが変わった。**作り直しではなく取り込み** ——
     * 上げた地図に無いものは既定で残す(両方で編集するため)。
     */
    'map.import_upload' => 'がアプリの地図を読み込みました',
    'map.sync' => 'がアプリの地図を取り込みました',
    // 配信(admin/map-publish.php)。手元の new-map-release.ps1 から移した
    'map.publish' => 'が地図をアプリへ配信しました',
    'map.code_add' => 'が地図のアクセスコードを作りました',
    'map.code_remove' => 'が地図のアクセスコードを止めました',
    // 配信 ID を2つに分けた(2026-09-14)。どこにも配信先の無いコードを付け替える。詳細は「旧 → 新」
    'map.code_reassign' => 'が地図のアクセスコードの配信先を付け替えました',
    // イベントモード(1.0.4)。ここが抜けていたので内部名がそのまま出ていた
    'map.event_create' => 'がイベントを作成しました',
    'map.event_update' => 'がイベントを更新しました',
    'map.event_delete' => 'がイベントを削除しました',
    'map.event_toggle' => 'がイベントの有効/無効を切り替えました',
    'map.event_closure' => 'がイベントの通行止めを変更しました',
    'map.event_poi' => 'がイベントの臨時地点を変更しました',
    'map.event_alias' => 'がイベントの臨時名称を変更しました',
    'map.event_cleanup_mode' => 'が期限切れイベントの掃除方法を変更しました',
    'map.event_cleanup' => 'が期限切れのイベントを削除しました',
    /*
     * 自動削除は**人が指示した操作ではない**(イベント一覧を開いた副作用として走る)。
     * 記録には開いた人が残るので、「が…しました」と書くとその人がやったことになる。
     * 監査ログでそれをやると、後から責任の所在を誤って読むことになる。
     */
    'map.event_cleanup_auto' => 'の操作をきっかけに、期限切れのイベントが自動削除されました',
];

function km_admin_log_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_admin_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(16) NOT NULL,
            action VARCHAR(64) NOT NULL,
            actor_id VARCHAR(191) NULL,
            actor_name VARCHAR(255) NULL,
            detail VARCHAR(500) NULL,
            ip_address VARBINARY(16) NULL,
            /*
             * 端末の名乗り。**上限の無い自由文字列をそのまま入れない** ——
             * 長い User-Agent で INSERT ごと落ちる。書き込む側でも切る。
             *
             * **既にある表には効かない**(CREATE TABLE IF NOT EXISTS なので)。
             * 既存の DB は scripts/migrate-admin-log-user-agent.sql を流す。
             * 流していなければ記録も表示もしないだけで、他は今までどおり動く。
             */
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            INDEX (created_at),
            INDEX (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/**
 * 操作を1件記録する。
 *
 * **記録に失敗してもアプリを止めない。** 監査ログの不調が原因で「テーブルが作れない」
 * 「設定が保存できない」が起きる方が害が大きいため、DB 接続の取得も含めて丸ごと捕まえて
 * サーバーログへ落とすだけにする(km_map_rate_limited() の fail open と同じ考え方)。
 *
 * 実行者は admin/_inc/guard.php が組み立てる $KM_USER から取る。公開側(api/map-unlock.php)
 * のように $KM_USER が存在しない経路では null のまま IP だけ残す。
 *
 * ## $anonymous —— 実行者を**わざと**残さない
 *
 * アカウント削除だけは、$KM_USER が居るのに残してはいけない。
 * 「その人を消しました」という行にその人の名前と ID が入ると、
 * **消したことの記録が、消したはずの情報になる。**
 *
 * 実際に起きた: 後片付けが `km_admin_log` の名前と ID を落とした**あと**に
 * この関数を呼んでいたため、最後の1行だけが匿名化を免れ、
 * タイムラインに「Test が削除されたアカウントの情報を片付けました」と
 * 消えた人の名前が残り続けた(2026-09-03、利用者の指摘で判明)。
 *
 * **順番で直さない。** 「先に記録すれば UPDATE が拾う」は、
 * 行を足す順を1つ入れ替えただけで静かに戻る。**呼ぶ側が明示する。**
 */
function km_admin_log_record(
    string $category,
    string $action,
    ?string $detail = null,
    bool $anonymous = false
): void {
    try {
        if (!in_array($category, KM_ADMIN_LOG_CATEGORIES, true)) {
            throw new InvalidArgumentException("不正なカテゴリです: {$category}");
        }

        $user = $anonymous ? null : ($GLOBALS['KM_USER'] ?? null);
        $actorId = is_array($user) && isset($user['sub']) ? (string) $user['sub'] : null;
        $actorName = is_array($user) && isset($user['name']) ? (string) $user['name'] : null;

        $pdo = km_db();
        km_admin_log_ensure_table($pdo);

        /*
         * `user_agent` は**列が在るときだけ**書く。
         * 移行 SQL は配備利用者が別に流すもので、コードの配備との順番が決まっていない。
         * 決め打ちにすると、流す前は**記録そのものが全部失敗する** ——
         * 監査の記録が静かに止まるのが一番困る。
         */
        $withUserAgent = km_admin_log_has_user_agent($pdo);

        $columns = 'category, action, actor_id, actor_name, detail, ip_address';
        $placeholders = '?, ?, ?, ?, ?, INET6_ATON(?)';
        $values = [
            $category,
            $action,
            $actorId,
            $actorName,
            $detail !== null ? mb_substr($detail, 0, 500) : null,
            /*
             * **匿名の記録には接続元も端末も入れない。**
             * 名前と ID だけ落として IP を残すと、時刻と併せてその人を指せてしまう ——
             * 「特定できません」と書いてある文書と食い違う。
             */
            $anonymous ? null : km_map_rate_limit_ip(),
        ];
        if ($withUserAgent) {
            $columns .= ', user_agent';
            $placeholders .= ', ?';
            $values[] = $anonymous ? null : km_admin_log_user_agent();
        }

        $stmt = $pdo->prepare(
            "INSERT INTO km_admin_log ({$columns}, created_at) VALUES ({$placeholders}, NOW())"
        );
        $stmt->execute($values);
    } catch (Throwable $exception) {
        error_log('km_admin_log_record failed (' . $category . '/' . $action . '): ' . $exception->getMessage());
    }
}

/**
 * 新しい順に取り出す。
 *
 * 時刻は datetime の文字列ではなく UNIX_TIMESTAMP() の epoch で返す。
 *
 * フェーズ13で DB も PHP も Asia/Tokyo に揃えたので、今は文字列で持ち出しても結果は同じになる。
 * それでも epoch を続けるのは、**設定が食い違ったときに黙って9時間ずれる状態へ戻したくない**から。
 * epoch は絶対時刻なので、どこから繋いでも・どちらの設定が変わっても正しい現地時刻に直せる。
 *
 * @return array<int, array{id:int, category:string, action:string, actorName:?string,
 *                          detail:?string, ip:?string, createdAtEpoch:int}>
 */
/** `user_agent` に入れてよい長さ。列は varchar(255)。 */
const KM_ADMIN_LOG_USER_AGENT_MAX = 255;

/**
 * 記録する端末の名乗り。**長さで切る。**
 *
 * 上限の無い自由文字列をそのまま入れると、長い User-Agent で INSERT ごと落ちる。
 * **切ったことが分かるように末尾へ「…」を付ける** ——
 * 切れた文字列を完全な値だと思って読むと、端末の判別を誤る。
 *
 * 空なら null。「送ってこなかった」と「空文字だった」を分ける意味は無い。
 */
function km_admin_log_user_agent(?string $raw = null): ?string
{
    $value = trim($raw ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) <= KM_ADMIN_LOG_USER_AGENT_MAX) {
        return $value;
    }

    return mb_substr($value, 0, KM_ADMIN_LOG_USER_AGENT_MAX - 1) . '…';
}

/**
 * 端末の名乗りを、**見分けが付く程度まで畳む。**
 *
 * ## なぜ生のまま出さないのか
 *
 * User-Agent は 150 字を超えることがある。そのまま並べると表が読めなくなり、
 * **隣の IP まで読まれなくなる。** ここで知りたいのは
 * 「いつもと違う端末から入られていないか」だけなので、その判別に要る分だけ残す。
 *
 * ## 当てにいかない
 *
 * 細かく言い当てようとすると、**規則が増えるほど外れたときに嘘になる。**
 * 見つからなければ「不明な端末」と言う ——
 * **間違った端末名を出すより、分からないと言う方がよい。**
 *
 * 全文は画面側が `title` に入れてある(畳んだ結果しか残らない形にしない)。
 */
function km_admin_log_device_label(string $userAgent): string
{
    $ua = trim($userAgent);
    if ($ua === '') {
        return '不明な端末';
    }

    // 順番に意味がある。**Edge は Chrome を名乗り、Chrome は Safari を名乗る。**
    // 細かい方から見ないと、全部 Safari になる
    $browsers = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];
    $platforms = [
        'Android' => 'Android',
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Windows NT' => 'Windows',
        'Mac OS X' => 'Mac',
        'Linux' => 'Linux',
    ];

    $browser = null;
    foreach ($browsers as $needle => $label) {
        if (str_contains($ua, $needle)) {
            $browser = $label;
            break;
        }
    }
    $platform = null;
    foreach ($platforms as $needle => $label) {
        if (str_contains($ua, $needle)) {
            $platform = $label;
            break;
        }
    }

    if ($browser === null && $platform === null) {
        // 当てられないものを名乗らせない。**先頭だけ出して、あとは title に任せる**
        return mb_strlen($ua) > 40 ? mb_substr($ua, 0, 39) . '…' : $ua;
    }

    return trim(($platform ?? '') . ' ' . ($browser ?? '')) ?: '不明な端末';
}

/**
 * `km_admin_log` に `user_agent` の列があるか。
 *
 * 列を足すのは `scripts/migrate-admin-log-user-agent.sql` で、
 * **`ALTER` はアプリの DB 利用者に与えていない** —— つまり配備利用者が別に流すもので、
 * コードの配備との順番が決まっていない。
 *
 * **決め打ちにすると、流す前は記録そのものが全部失敗する。**
 * 監査の記録が静かに止まるのが一番困る。
 *
 * 1リクエストの間は覚えておく(記録のたびに聞かない)。
 */
function km_admin_log_has_user_agent(PDO $pdo): bool
{
    static $known = null;
    if ($known !== null) {
        return $known;
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'km_admin_log'
               AND COLUMN_NAME = 'user_agent'"
        );
        $known = ((int) $stmt->fetchColumn()) === 1;
    } catch (Throwable $exception) {
        // 聞けなければ「無い」側に倒す。**無い前提の SQL は、在っても通る**
        error_log('km_admin_log_has_user_agent failed: ' . $exception->getMessage());
        $known = false;
    }

    return $known;
}

function km_admin_log_recent(PDO $pdo, int $limit = 100): array
{
    km_admin_log_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, category, action, actor_name AS actorName, detail,
                INET6_NTOA(ip_address) AS ip, UNIX_TIMESTAMP(created_at) AS createdAtEpoch
         FROM km_admin_log ORDER BY id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * **1つのアカウントの**記録だけを新しい順に取り出す。admin/profile.php が使う。
 *
 * ## なぜ全体の一覧(timeline.php)と別に要るのか
 *
 * 「自分の覚えの無い操作が残っていないか」を見るためのもの。全員ぶんの中から自分の行を
 * 探すのは、件数が増えるほど現実的でなくなる。**身に覚えのない行に気づける形**にする。
 *
 * IP も返す。日時と操作だけでは「いつもと違う場所から入られた」が分からない。
 *
 * `actor_id` は Logto の `sub`。表示名ではなく ID で絞るのは、
 * **名前は変えられる**ため —— 改名した前後で自分の記録が切れてしまう。
 *
 * @return array<int, array{id:int, category:string, action:string, detail:?string,
 *                          ip:?string, createdAtEpoch:int}>
 */
function km_admin_log_for_actor(PDO $pdo, string $actorId, int $limit = 20): array
{
    if ($actorId === '') {
        return [];
    }

    km_admin_log_ensure_table($pdo);

    // 列が無い環境でも同じ形を返す。**画面側に分岐を持ち込まない**
    $userAgent = km_admin_log_has_user_agent($pdo) ? 'user_agent AS userAgent' : 'NULL AS userAgent';

    $stmt = $pdo->prepare(
        "SELECT id, category, action, detail,
                INET6_NTOA(ip_address) AS ip, {$userAgent},
                UNIX_TIMESTAMP(created_at) AS createdAtEpoch
         FROM km_admin_log WHERE actor_id = ? ORDER BY id DESC LIMIT ?"
    );
    $stmt->bindValue(1, $actorId, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/** 直近 $days 日の記録件数。ダッシュボードのタイル用。 */
function km_admin_log_count_since(PDO $pdo, int $days = 1): int
{
    km_admin_log_ensure_table($pdo);

    // 比較は DB 内で完結させる(PHP 側で時刻を組み立てるとタイムゾーン差で狂う)。
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM km_admin_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->bindValue(1, $days, PDO::PARAM_INT);
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}
