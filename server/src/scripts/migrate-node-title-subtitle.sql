-- km_map_nodes を title / subtitle に分ける(2026-09-03)
--
-- ## なぜ
--
-- Web 側は名前を `name` 1本で持っている(`トレーナー室 管-104`)。アプリは
-- `title`(トレーナー室)と `subtitle`(管-104)が**別の欄**で、地図の正本はアプリ側。
-- 同期のたびに `title + " " + subtitle` で組み直しており、**戻せない**。
--
-- そのせいで Web 側では:
--   - 部屋番号だけで並べ替えられない
--   - 番号だけで検索できない
--   - 「同じ部屋名で番号違い」を区別する手がかりが名前の中の空白しか無い
--
-- ## 実行するのは配備利用者
--
-- **ALTER はアプリの DB 利用者に与えていない**(権限を広げると、実行時の利用者が
-- スキーマを変えられる)。ホストで root として流すこと:
--
--   docker compose exec -T mariadb \
--     sh -c 'exec mysql -uroot -p"$MARIADB_ROOT_PASSWORD" kosenmap' \
--     < src/scripts/migrate-node-title-subtitle.sql
--
-- ## 何度流してもよい
--
-- 列が既にあれば何もしない。**`ADD COLUMN IF NOT EXISTS` は MariaDB にはあるが、
-- 分岐を自分で書く**方が、MySQL へ移したときにも同じ形で通る。
--
-- ## `name` は残す
--
-- 消さない。**戻せるようにしておく** —— 同期は当面 name / title / subtitle の
-- 3つとも書き、読む側が title を優先して使う。name だけを見ている画面
-- (公開ページの検索・カテゴリ絞り込み)はそのまま動き続ける。

SET @has_title := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'km_map_nodes' AND COLUMN_NAME = 'title'
);
SET @sql := IF(
    @has_title = 0,
    'ALTER TABLE km_map_nodes ADD COLUMN title VARCHAR(255) NULL AFTER name',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_subtitle := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'km_map_nodes' AND COLUMN_NAME = 'subtitle'
);
SET @sql := IF(
    @has_subtitle = 0,
    'ALTER TABLE km_map_nodes ADD COLUMN subtitle VARCHAR(64) NULL AFTER title',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 既存の行を埋めておく。**最後の空白で切る** ——
-- `トレーナー室 管-104` は `トレーナー室` + `管-104`、
-- `第一体育館` のように空白が無いものは title だけ(subtitle は空)。
--
-- **推測でしかない。** 正しい分かれ目はアプリ側が持っており、
-- 次の同期で上書きされる。ここで埋めるのは、同期までの間に画面が
-- 空欄だらけにならないようにするため。
UPDATE km_map_nodes
SET title = IF(LOCATE(' ', name) > 0,
               SUBSTRING_INDEX(name, ' ', 1),
               name),
    subtitle = IF(LOCATE(' ', name) > 0,
                  TRIM(SUBSTRING(name, LOCATE(' ', name) + 1)),
                  '')
WHERE title IS NULL;
