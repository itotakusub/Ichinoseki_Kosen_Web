-- 合言葉の解除試行を記録するテーブル(lib/map-rate-limit.php)。
--
-- **普段は流さなくてよい。** アプリの DB 利用者(Main)は
-- `GRANT ALL PRIVILEGES ON Kosen_map.*` を持っており(2026-09-14 にホストで実測)、
-- lib/map-rate-limit.php が初回に自動で作る。以前ここに書いていた
-- 「SELECT, INSERT, UPDATE ON *.* のみで CREATE 権限を持たない」は古い記述。
--
-- 自動作成が使えない環境だけ、管理者権限で一度流す(Kosen_map を選択した状態で)。
-- 未作成でも地図の表示・解除自体は動くが、そのときは DB ではなく
-- src/cache/unlock/ のファイルで数える(DB が使えないときの予備と同じ経路)。
--
-- 表は錠ごとに分ける(2026-09-14)。アプリのアクセスコードの成功が、
-- Web 地図のパスワードの失敗回数を消さないようにするため。

-- 公開の地図のパスワード(api/map-unlock.php)
CREATE TABLE IF NOT EXISTS km_map_unlock_attempts (
    ip_address VARBINARY(16) NOT NULL PRIMARY KEY,
    failure_count INT NOT NULL DEFAULT 0,
    first_failed_at DATETIME NOT NULL,
    last_failed_at DATETIME NOT NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- アプリの配信アクセスコード(api/app-map.php)
CREATE TABLE IF NOT EXISTS km_app_unlock_attempts (
    ip_address VARBINARY(16) NOT NULL PRIMARY KEY,
    failure_count INT NOT NULL DEFAULT 0,
    first_failed_at DATETIME NOT NULL,
    last_failed_at DATETIME NOT NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
