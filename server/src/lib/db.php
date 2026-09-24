<?php

declare(strict_types=1);

/**
 * MariaDB への PDO 接続をここに集約する。
 *
 * 資格情報は次の優先順で決める(logto_config.php と同じ二段構え):
 *   1. 環境変数(compose.yaml が web サービスへ渡す MARIADB_*)
 *   2. config/db.local.php(git 管理外。env が読めない・未整備な環境向けの代替)
 *   3. host/port だけ最終フォールバック値を持つ。database/user/password には
 *      ソースコード上の既定値を持たせていないので、どちらからも取れなければ
 *      例外で止まる(何かの拍子に接続情報が空のまま突き進むことを防ぐ)。
 */

function km_db_local_config(): array
{
    $path = __DIR__ . '/../config/db.local.php';
    return is_file($path) ? (array) require $path : [];
}

function km_db_value(array $local, string $envKey, string $localKey, ?string $default = null): ?string
{
    $envValue = getenv($envKey);
    if (is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }
    $localValue = $local[$localKey] ?? null;
    if (is_string($localValue) && trim($localValue) !== '') {
        return trim($localValue);
    }
    return $default;
}

function km_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $local = km_db_local_config();

    $host = km_db_value($local, 'MARIADB_HOST', 'host', 'mariadb');
    $port = km_db_value($local, 'MARIADB_PORT', 'port', '3306');
    $database = km_db_value($local, 'MARIADB_DATABASE', 'database');
    $user = km_db_value($local, 'MARIADB_USER', 'user');
    $password = km_db_value($local, 'MARIADB_PASSWORD', 'password');

    if ($database === null || $user === null || $password === null) {
        throw new RuntimeException(
            'MARIADB_DATABASE / MARIADB_USER / MARIADB_PASSWORD が設定されていません。'
            . ' compose.yaml の環境変数か、config/db.local.php のどちらかに設定してください。'
        );
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        /*
         * **接続で待ち続けない。** MariaDB が詰まっていると、既定では PHP のワーカーが
         * default_socket_timeout(60秒)まで握られ、Apache のワーカーが先に尽きる。
         * 同じ網の中のコンテナなので、5秒で繋がらなければ繋がらない。
         */
        PDO::ATTR_TIMEOUT => 5,
    ];

    // MariaDB は --ssl=ON で必須。CA が実際にマウントされている場合だけ検証つきで
    // 接続する(compose.yaml の証明書マウントが未反映の環境でも接続自体は失敗させない)。
    $caPath = '/etc/ssl/certs/mariadb-rootCA.pem';
    if (is_file($caPath)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        /*
         * **検証なしへ黙って落ちない。** 以前は CA が無いと検証を外して繋いでいた
         * (security-review-2026-09-10 の 13)。マウントが外れた・名前を変えた、に誰も気づかず、
         * DB への経路の途中で差し替えられても分からない状態が続く。
         * 本番の web コンテナには置いてある(2026-09-14 に実物を確認)。
         * どうしても検証なしで繋ぐ環境だけ、KM_DB_ALLOW_UNVERIFIED_TLS=1 を明示する。
         */
        $allowUnverified = getenv('KM_DB_ALLOW_UNVERIFIED_TLS');
        if (!is_string($allowUnverified) || trim($allowUnverified) !== '1') {
            throw new RuntimeException(
                'km_db(): ' . $caPath . ' が見つかりません。証明書を検証できないので接続しません。'
                . ' compose.yaml の web の volumes(certs/rootCA.pem)を確認してください。'
            );
        }
        error_log('km_db(): KM_DB_ALLOW_UNVERIFIED_TLS=1 のため、証明書検証なしで接続します。');
    }

    $pdo = new PDO($dsn, $user, $password, $options);
    km_db_limit_statement_time($pdo);
    km_db_set_time_zone($pdo);

    return $pdo;
}

/** Web のリクエストから流す1文の上限(秒)。 */
const KM_DB_WEB_STATEMENT_SECONDS = 15;

/**
 * Web から流す SQL の1文に時間の上限を付ける。
 *
 * 重い問い合わせを並べて投げられると、MariaDB の接続とワーカーを握ったまま離さない。
 * **1文あたり 15 秒**で MariaDB 側に切らせる(PHP の max_execution_time = 30 より短く)。
 *
 * **サーバー全体(グローバル)には掛けない。** mysqldump のバックアップや
 * CLI の移行スクリプトまで止まるため。ここも CLI(PHP_SAPI === 'cli')では掛けない。
 *
 * 掛けられなかったら記録だけして進む —— 上限は守りの上乗せで、無くても動作は正しい。
 */
function km_db_limit_statement_time(PDO $pdo): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    try {
        $pdo->exec('SET SESSION max_statement_time = ' . KM_DB_WEB_STATEMENT_SECONDS);
    } catch (Throwable $exception) {
        error_log('km_db(): max_statement_time を設定できませんでした: ' . $exception->getMessage());
    }
}

/**
 * このセッションのタイムゾーンを日本時間に固定する。
 *
 * サーバー側の設定(mariadb の --default-time-zone)にも同じ値を入れてあるが、**接続ごとに
 * 明示する方を正本にする**。理由は実際に踏んだ事故があるため:
 *
 *   フェーズ9〜12 の検証はローカルの Windows PHP(Asia/Tokyo)から本番 DB へ繋いで行っており、
 *   「時刻は正しい」と何度も確認できていた。しかし本番のコンテナは PHP も MariaDB も UTC で、
 *   実際の画面は 9 時間ずれていた。**接続元によって解釈が変わる状態そのものが原因**なので、
 *   ここで固定して、どこから繋いでも同じ結果になるようにする。
 *
 * **ただし、移行が済むまでは固定しない。** `SET time_zone` は読み出しだけでなく `NOW()` にも
 * 効くため、保存済みの行がまだ UTC の壁時計のうちに JST を固定すると、そこから先の行だけが
 * JST で書かれて **UTC と JST が混ざった状態**になる。そうなると
 * Old/migrate-timezone-to-jst.php の一律 +9時間 が新しい行を二重にずらしてしまい、
 * どの行がどちらの基準なのか後から判別できない。
 *
 * そこで km_migrations の印を見て、「保存されている値が JST になっている」ことを確認できた
 * ときだけ固定する。移行の前後で常に「保存の基準」と「解釈」が一致する。
 */
function km_db_set_time_zone(PDO $pdo): void
{
    static $migrated = null;

    if ($migrated === null) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM km_migrations WHERE name = 'timezone-utc-to-jst'");
            $migrated = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $exception) {
            // km_migrations がまだ無い = 移行前。サーバーの既定のままにする
            $migrated = false;
        }
    }

    if (!$migrated) {
        return;
    }

    // タイムゾーン名テーブルが無い DB でも動くよう、名前が使えなければ固定オフセットへ落とす
    // (日本には夏時間が無いので +09:00 は恒久的に等価)。
    try {
        $pdo->exec("SET time_zone = 'Asia/Tokyo'");
    } catch (Throwable $exception) {
        $pdo->exec("SET time_zone = '+09:00'");
    }
}

/**
 * テーブル・カラム識別子の検証。km_ / kmt_ どちらの接頭辞にも使う。
 * バッククォート頼みで防ぐのではなく、そもそも許可した形以外を通さない。
 */
function km_is_valid_identifier(string $name): bool
{
    return preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) === 1;
}

/**
 * 今の DB にその表が在るか。「初期データは表を作ったときだけ入れる」の判定に使う
 * (lib/tasks.php・lib/faq.php)。空かどうかで判定すると、全部消した表に初期データが戻る。
 */
function km_db_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table]);

    return $stmt->fetchColumn() !== false;
}