-- 地図の正本を Website へ移す(2026-09-03)
--
-- ## なぜ
--
-- これまでは**管理アプリが地図の正本**で、Website の `km_map_nodes` はその写しだった。
-- 写しでよかったので、アプリにしかない項目は落としていた:
--
--   uuid(完全形)・z・note・useWifi・type2・bssid・txPowerAtOneMeter・
--   pathLossExponent・transferGroupId、`wall` と `wifi_router` のノード**丸ごと**、
--   fingerprints・overlays
--
-- **向きを逆にする**(利用者の指示)。Website で作って配信するなら、
-- **落とした項目は二度と戻らない。** 器を先に広げる。
--
-- ## 実行するのは配備利用者
--
-- **`ALTER` はアプリの DB 利用者に与えていない**(権限を広げると、実行時の利用者が
-- スキーマを変えられる)。ホストで root として流すこと:
--
--   docker compose exec -T mariadb \
--     sh -c 'exec mysql -uroot -p"$MARIADB_ROOT_PASSWORD" kosenmap' \
--     < src/scripts/migrate-map-app-schema.sql
--
-- **新しいコードを配備する前に流す。** 列が無いまま動かすと地図が読めない。
-- 何度流してもよい(`IF NOT EXISTS` と、値を見てから書き換える UPDATE)。
--
-- ## 座標を int から decimal(10,2) へ
--
-- アプリは Float、Web は int だった。**写しだったときは丸めても害が無い。**
-- Web が正本になると、丸めは往復のたびに積もる位置ずれになる。
--
-- 桁は `km_map_floor_bounds` に合わせた。1600px の図面で 0.01px ——
-- 物理的な意味を持たない桁まで残る。
--
-- **Y は Web の向き(上が正)のまま持つ。** アプリは下向きで、変換は
-- `km_app_map_flip_y()`(引き算1つ)。**正確に逆算できる**ので、
-- 表示・編集・イベントの座標を全部書き換えるより、ここに閉じる方が安全。

-- ---------------------------------------------------------------- km_map_nodes

-- アプリ側の完全な uuid。`id` はハイフンを抜いた 32 字で、
-- **UUID 以外の文字列(旧 Web の `n_new_10` など)は元に戻せない。**
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS uuid varchar(64) NOT NULL DEFAULT '' AFTER id;
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS z float DEFAULT NULL AFTER y;
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS note varchar(500) DEFAULT NULL AFTER occupant_name;
-- 測位でこのノードを信用してよいか。既定は「使う」(アプリの既定と同じ)
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS use_wifi tinyint(1) NOT NULL DEFAULT 1 AFTER note;
-- 自由記述の小分類。アプリ側は空文字を既定にしているので NULL にしない
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS type2 varchar(64) NOT NULL DEFAULT '' AFTER type;
-- ここから下は `wifi_router` ノードと電波の校正値。**いまは 0 件**だが、器だけ先に作る
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS bssid varchar(32) DEFAULT NULL AFTER type2;
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS tx_power_at_one_meter smallint DEFAULT NULL AFTER bssid;
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS path_loss_exponent float DEFAULT NULL AFTER tx_power_at_one_meter;
-- 階をまたぐ階段・出入口の対応付け
ALTER TABLE km_map_nodes ADD COLUMN IF NOT EXISTS transfer_group_id varchar(64) DEFAULT NULL AFTER path_loss_exponent;

-- uuid が空の行(この移行より前からある行)は、id をそのまま入れておく。
-- **取り込みで上書きされる**が、空のままにしておくと配信で uuid 無しのノードが出る。
UPDATE km_map_nodes SET uuid = id WHERE uuid = '';

ALTER TABLE km_map_nodes MODIFY COLUMN x decimal(10,2) NOT NULL;
ALTER TABLE km_map_nodes MODIFY COLUMN y decimal(10,2) NOT NULL;

-- 種別の語彙をアプリに合わせる。
--
--   room / facility / stairs / road / entrance / wall / wifi_router
--
-- Web だけにあった `point` は `road` と `entrance` の**両方**から来ていたので、
-- **逆写しでは復元できない。** 数の多い方(road 233 / entrance 28)へ寄せる。
--
-- **これは繋ぎ。** 正しい値はアプリの書き出しを取り込んだときに入る
-- (管理画面「アプリの地図を取り込む」)。それまでの間、公開側の地図が
-- 種別不明で描けなくなるのを防ぐためだけのもの。
UPDATE km_map_nodes SET type = 'road' WHERE type = 'point';
-- `All`(どのズームでも出す、という表示の都合の分類)は本番に 1 件も無い。
-- 種別ごとのズーム閾値を持つようになったので役目ごと無くなる
UPDATE km_map_nodes SET type = 'facility' WHERE type = 'All';

-- ---------------------------------------------------------------- 新しい表

-- Wi-Fi 指紋。**端末でしか作れない**(その場に立って測る)。
-- いまは 0 件。器だけ先に作り、アプリからのアップロード口は必要になってから。
CREATE TABLE IF NOT EXISTS km_map_fingerprints (
    node_uuid varchar(64) NOT NULL,
    floor_id varchar(16) NOT NULL,
    x decimal(10,2) NOT NULL,
    y decimal(10,2) NOT NULL,
    -- {"aa:bb:cc:dd:ee:ff": -42, ...}。BSSID の数は端末と場所で変わるので列にしない
    rssi_json longtext NOT NULL,
    altitude_meters float DEFAULT NULL,
    sample_count int NOT NULL DEFAULT 1,
    updated_at_millis bigint NOT NULL,
    PRIMARY KEY (node_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 建物平面図の**配置**。画像そのものは APK の中(`MapOverlayCatalog.kt` の固定キー)。
-- **新しい建物を足すには APK を作り直す。** Website から配れるのは配置だけ。
CREATE TABLE IF NOT EXISTS km_map_overlays (
    uuid varchar(64) NOT NULL,
    image_key varchar(64) NOT NULL,
    floor_id varchar(16) NOT NULL,
    x decimal(10,2) NOT NULL,
    y decimal(10,2) NOT NULL,
    scale float NOT NULL DEFAULT 1,
    rotation_degrees float NOT NULL DEFAULT 0,
    opacity float NOT NULL DEFAULT 1,
    visible tinyint(1) NOT NULL DEFAULT 1,
    locked tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- 確認

SELECT
    (SELECT COUNT(*) FROM km_map_nodes)                          AS `ノード`,
    (SELECT COUNT(*) FROM km_map_nodes WHERE uuid = '')          AS `uuid が空(0 のはず)`,
    (SELECT COUNT(*) FROM km_map_nodes
      WHERE type NOT IN ('room','facility','stairs','road','entrance','wall','wifi_router'))
                                                                 AS `種別が不明(0 のはず)`,
    (SELECT COUNT(*) FROM km_map_fingerprints)                   AS `指紋`,
    (SELECT COUNT(*) FROM km_map_overlays)                       AS `平面図の配置`;
