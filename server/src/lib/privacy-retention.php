<?php

declare(strict_types=1);

/**
 * IP アドレスを含む記録の保存期限(2026-09-25、診断 W-49)。
 *
 * | 表 | 何を | いつ |
 * |---|---|---|
 * | km_admin_log | 接続元 IP と端末の名乗り(User-Agent)を空にする。**行は残す** | 記録から KM_PRIVACY_AUDIT_IP_DAYS 日 |
 * | 解除の試行(km_map_unlock_attempts / km_app_unlock_attempts) | 行ごと消す | 最後の試行から KM_PRIVACY_UNLOCK_ATTEMPT_DAYS 日、ロックも切れていれば |
 *
 * 以前はどちらも消す仕組みが無く、**公開ページで 1 回パスワードを間違えた来場者の IP が、ずっと残った**
 * (解除の試行の表は、1 回で正しく解除した人の IP まで残していた)。
 * プライバシーポリシー(lib/legal.php)に書いた日数と、ここの定数は対。**片方だけ変えないこと。**
 *
 * ## いつ動くか
 *
 * cron を足さずに済むよう、**記録を書いたついでに 1 日 1 回だけ**動かす(km_settings の privacy_purged_on)。
 * 監査ログは管理画面を使えば毎日のように書かれ、解除の試行は解除の API が呼ばれるたびに入口がある。
 * 何も起きない日は掃除も起きないが、そのときは消すべき新しい行も増えていない。
 *
 * **失敗しても呼んだ側の処理は止めない。** 掃除の不調で監査の記録や解除が失敗する方が困る。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/map-rate-limit.php';

/** 監査ログの IP と端末の名乗りを残す日数。 */
const KM_PRIVACY_AUDIT_IP_DAYS = 90;

/** 解除の試行の記録を残す日数。数えるのは 15 分の窓だけなので、1 日で足りる。 */
const KM_PRIVACY_UNLOCK_ATTEMPT_DAYS = 1;

/** 最後に掃除した日(Y-m-d)を置く km_settings の名前。 */
const KM_PRIVACY_PURGED_ON_SETTING = 'privacy_purged_on';

/**
 * 今日まだなら掃除する。**何度呼んでもよい**(2 回目からは日付を見て戻るだけ)。
 *
 * @return array<string, int>|null 掃除した件数。今日は済んでいれば null
 */
function km_privacy_purge_daily(?PDO $pdo = null): ?array
{
    try {
        $pdo ??= km_db();
        $today = date('Y-m-d');
        if (km_setting_get($pdo, KM_PRIVACY_PURGED_ON_SETTING) === $today) {
            return null;
        }
        // 先に日付を書く。同時に来た別の要求が重ねて掃除しても害は無いが、毎回走らせない
        km_setting_set($pdo, KM_PRIVACY_PURGED_ON_SETTING, $today);

        return km_privacy_purge($pdo);
    } catch (Throwable $exception) {
        error_log('km_privacy_purge_daily failed: ' . $exception->getMessage());

        return null;
    }
}

/**
 * 期限を過ぎた IP を消す。**表が無いところは飛ばす**(初めて使う機能の表は、まだ作られていない)。
 *
 * @return array<string, int> 表ごとの件数
 */
function km_privacy_purge(PDO $pdo): array
{
    $counts = [];

    $counts['km_admin_log'] = km_privacy_run($pdo, km_privacy_audit_sql($pdo), [KM_PRIVACY_AUDIT_IP_DAYS]);

    foreach (KM_MAP_UNLOCK_SCOPES as $table) {
        $counts[$table] = km_privacy_run(
            $pdo,
            "DELETE FROM {$table}
              WHERE last_failed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND (locked_until IS NULL OR locked_until < NOW())",
            [KM_PRIVACY_UNLOCK_ATTEMPT_DAYS]
        );
    }

    return $counts;
}

/**
 * 監査ログを空にする文。`user_agent` は**列が在るときだけ**触る(移行 SQL を流す前の環境がある)。
 */
function km_privacy_audit_sql(PDO $pdo): string
{
    require_once __DIR__ . '/admin-log.php';
    $columns = 'ip_address = NULL';
    $still = 'ip_address IS NOT NULL';
    if (km_admin_log_has_user_agent($pdo)) {
        $columns .= ', user_agent = NULL';
        $still .= ' OR user_agent IS NOT NULL';
    }

    return "UPDATE km_admin_log SET {$columns}
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND ({$still})";
}

/** 1 文を流して件数を返す。表が無ければ 0。 */
function km_privacy_run(PDO $pdo, string $sql, array $params): int
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'Base table or view not found')
            || str_contains($exception->getMessage(), '1146')) {
            return 0;
        }
        throw $exception;
    }
}
