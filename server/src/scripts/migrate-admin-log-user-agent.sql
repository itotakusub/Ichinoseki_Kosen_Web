-- km_admin_log に user_agent を足す(2026-09-03)
--
-- ## なぜ
--
-- 「自分の覚えの無い操作が残っていないか」を見るための一覧に、
-- **日時・操作・接続元 IP しか無かった。** 同じ回線から2台使っていると、
-- どちらの端末からの操作かが分からない。
--
-- 「端末の情報は Logto 側にある」と画面で言っていたが、**そちらを見に行くには
-- Logto Console へ入る必要があり**、身に覚えのない操作に気づくためだけに
-- そこまで辿る人はいない。
--
-- ## 実行するのは配備利用者
--
-- **`ALTER` はアプリの DB 利用者に与えていない**(権限を広げると、実行時の利用者が
-- スキーマを変えられる)。ホストで root として流すこと:
--
--   docker compose exec -T mariadb \
--     sh -c 'exec mysql -uroot -p"$MARIADB_ROOT_PASSWORD" kosenmap' \
--     < src/scripts/migrate-admin-log-user-agent.sql
--
-- **流さなくても動く。** 列が無ければ記録も表示もしないだけで、他は今までどおり
-- (`km_admin_log_has_user_agent()` が在るかを見ている)。
--
-- ## なぜ別表にしなかったのか
--
-- 1:1 の別表にすれば `ALTER` は要らないが、**結合が1つ増えるだけで、
-- 消すときも面倒**になる。`ALTER` を配備利用者に頼めるなら、素直に列で持つ方がよい。
--
-- ## 長さの上限
--
-- `varchar(255)`。**上限の無い自由文字列をそのまま入れない** ——
-- 長い User-Agent を送られると INSERT ごと落ちる。
-- 書き込む側でも切る(切ったことが分かるよう、末尾に「…」を付ける)。

SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'km_admin_log'
      AND COLUMN_NAME = 'user_agent'
);
SET @sql := IF(
    @has_column = 0,
    'ALTER TABLE km_admin_log ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
