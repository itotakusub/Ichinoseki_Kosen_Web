-- 既に残ってしまった「消えた人の名前」を落とす(2026-09-03)
--
-- ## なぜ
--
-- アカウント削除は、その人の行から名前と ID を落としてから
-- 「削除しました」の1行を書いていた。ところが**その1行を書く側が、
-- サインイン中の本人($KM_USER)から名前と ID を拾っていた。**
--
-- 結果、匿名化した直後に最後の1行だけが実名で残り、タイムラインに
--
--   Test が削除されたアカウントの情報を片付けました
--
-- と、消えたはずの人の名前が出続けた(利用者の指摘で判明)。
--
-- コード側は直した(`km_admin_log_record()` の第4引数で匿名を明示)。
-- **これは、直す前に消したアカウントの行を片付けるためのもの。**
--
-- ## 実行するのは配備利用者
--
--   docker compose exec -T mariadb \
--     sh -c 'exec mysql -uroot -p"$MARIADB_ROOT_PASSWORD" kosenmap' \
--     < src/scripts/fix-admin-log-deleted-actor.sql
--
-- **何度流してもよい。** 対象が無ければ 0 行が変わるだけ。
--
-- ## 何を消すか
--
-- `account.deleted` の行の、名前・ID・接続元 IP・端末の名乗り。
-- **行は消さない** —— 「いつ削除が行われたか」は監査として残す。
-- 消した件数(detail)もそのまま残る。
--
-- IP まで落とすのは、名前だけ消しても**時刻と併せてその人を指せる**ため。
-- プライバシーポリシー7に「特定することはできません」と書いてある。

SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'km_admin_log'
      AND COLUMN_NAME = 'user_agent'
);
SET @sql := IF(
    @has_column = 1,
    'UPDATE km_admin_log
        SET actor_id = NULL, actor_name = NULL, ip_address = NULL, user_agent = NULL
      WHERE action = ''account.deleted''
        AND (actor_id IS NOT NULL OR actor_name IS NOT NULL OR ip_address IS NOT NULL)',
    'UPDATE km_admin_log
        SET actor_id = NULL, actor_name = NULL, ip_address = NULL
      WHERE action = ''account.deleted''
        AND (actor_id IS NOT NULL OR actor_name IS NOT NULL OR ip_address IS NOT NULL)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 残っていないことの確認。**0 になるはず**
SELECT COUNT(*) AS `残っている実名の行`
  FROM km_admin_log
 WHERE action = 'account.deleted'
   AND (actor_id IS NOT NULL OR actor_name IS NOT NULL);
