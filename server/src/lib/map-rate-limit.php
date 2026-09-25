<?php

declare(strict_types=1);

/**
 * 合言葉の解除試行に対するレート制限(総当たり対策)。
 *
 * アカウントという概念が無い(共有の合言葉を当てる形)ので、IP アドレス単位でのみ絞る。
 *
 * ## 錠ごとにバケツを分ける(2026-09-14)
 *
 * 以前は Web 地図のパスワード(api/map-unlock.php)と、Android の配信アクセスコード
 * (api/app-map.php)が **同じ km_map_unlock_attempts を共有**していた。
 * アクセスコードは配布物で、利用者なら誰でも持っている。それを1回入れるたびに
 * 失敗回数が消えるので、**Web 地図のパスワードを無限に試せた**(security-review-2026-09-10 の 3)。
 * いまは錠ごとに表を分け、**片方の成功がもう片方の失敗回数に触れない。**
 *
 * ## DB が使えないときも数える(2026-09-14)
 *
 * 以前は DB の例外で「弾かない」を返していた(可用性優先)。それだと
 * **DB を不安定にできる相手はロックを外せる**(同 7)。いまは DB が使えないときだけ、
 * src/cache/unlock/ のファイルで同じ数え方をする。信用するのは、logto_guard.php の JWKS と同じく
 * **自分の持ち物で、他人が書けないファイルだけ。**
 *
 * ## 先に数えてから照合する(2026-09-14 の2回目)
 *
 * 以前は「ロック中か読む → 照合 → 失敗なら記録」の順だった。読むだけの判定は枠を確保しないので、
 * **ロックが立つ前に同時に数百本を送れば、上限を大きく超えて試せた**(critic#0)。
 * いまは照合の**前に** INSERT … ON DUPLICATE KEY UPDATE で1回分を数え、
 * その値が上限を超えていれば照合しない(km_map_unlock_attempt())。
 * 数える文は1本なので、同時に来ても数え漏れは無い。
 *
 * ## 上限は錠ごと。IPv6 は /64 で数える
 *
 * 'app'(アクセスコード)は**学校と会場の共有回線**で使われる。1つの IP の後ろに来場者が大勢いるので、
 * 8 回だと打ち間違いと偽の QR だけで全員が 15 分止まった(android-data#0)。30 回にする。
 * 'web'(合言葉)は配布物ではないので 8 回のまま。
 *
 * IPv6 は利用者1人が /64 を丸ごと持つのが普通で、1アドレスごとに数えると
 * **アドレスを変えるだけで数え直しになる。** そのため /64 で数える
 * (nginx の km_appmap は $binary_remote_addr のままで、単位は揃っていない。あちらは量の上限、こちらは外れの回数)。
 *
 * ## 権限
 *
 * アプリの DB 利用者(`Main`)は `GRANT ALL PRIVILEGES ON Kosen_map.*`(2026-09-14 にホストで実測)。
 * 以前ここに書いていた `ON *.*` は誤りで、他のスキーマには届かない。
 * 表は自動で作る。`scripts/create-rate-limit-table.sql` は自動作成が使えない環境向けの代替。
 *
 * 解除に成功したら行ごと消す(2026-09-25。成功した人の IP を残さない)。古い行は lib/privacy-retention.php が 1 日で消す。
 */

const KM_MAP_UNLOCK_FAILURE_LIMIT = 8;
const KM_MAP_UNLOCK_WINDOW_SECONDS = 900;
const KM_MAP_UNLOCK_LOCK_SECONDS = 900;

/**
 * 錠 → 表。**表の名前は SQL へ直に入るので、ここにあるものしか使わない。**
 */
const KM_MAP_UNLOCK_SCOPES = [
    // 公開の地図のパスワード(api/map-unlock.php)
    'web' => 'km_map_unlock_attempts',
    // アプリの配信アクセスコード(api/app-map.php)
    'app' => 'km_app_unlock_attempts',
];

/**
 * 錠 → 15 分あたりの上限。
 *
 * 'app' を広げてよいのは、**正しいコードはロック中でも通す**から(api/app-map.php が
 * 先に鍵つきの印で当たりを判定し、外れのときだけここを通る)。上限は外れの回数だけに効く。
 */
const KM_MAP_UNLOCK_LIMITS = [
    'web' => KM_MAP_UNLOCK_FAILURE_LIMIT,
    'app' => 30,
];

function km_map_rate_limit_table(string $scope): string
{
    if (!isset(KM_MAP_UNLOCK_SCOPES[$scope])) {
        throw new InvalidArgumentException('知らない錠です: ' . $scope);
    }

    return KM_MAP_UNLOCK_SCOPES[$scope];
}

function km_map_rate_limit_limit(string $scope): int
{
    km_map_rate_limit_table($scope);

    return KM_MAP_UNLOCK_LIMITS[$scope] ?? KM_MAP_UNLOCK_FAILURE_LIMIT;
}

function km_map_rate_limit_ensure_table(PDO $pdo, string $scope = 'web'): void
{
    $table = km_map_rate_limit_table($scope);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS {$table} (
            ip_address VARBINARY(16) NOT NULL PRIMARY KEY,
            failure_count INT NOT NULL DEFAULT 0,
            first_failed_at DATETIME NOT NULL,
            last_failed_at DATETIME NOT NULL,
            locked_until DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/**
 * このアプリは nginx リバースプロキシ経由でしか到達できない
 * (compose.yaml の web サービスはホストポートを公開していない)。そのため
 * REMOTE_ADDR は常にプロキシ自身の内部 IP になり、実際の訪問者の IP にはならない
 * ——全訪問者が同じレート制限バケツを共有してしまうバグがあった。
 *
 * nginx の proxy-web.conf は `proxy_set_header X-Real-IP $remote_addr;` を
 * 設定済みで、nginx が上書きするため訪問者側からは偽装できない。X-Forwarded-For は
 * $proxy_add_x_forwarded_for によりクライアント由来の値が先頭に残り得るため使わない。
 *
 * **他のファイル(監査ログ・ランキング・在席)もこれを使う。** 丸めた値が欲しいときは
 * km_map_rate_limit_key() を使い、ここは変えないこと。
 */
function km_map_rate_limit_ip(): string
{
    $ip = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * 数える単位。IPv4 はそのまま、**IPv6 は /64 に丸める。**
 * IPv4 射影アドレス(::ffff:a.b.c.d)は IPv4 として数える(同じ相手が2つのバケツに割れない)。
 */
function km_map_rate_limit_key(?string $ip = null): string
{
    $ip ??= km_map_rate_limit_ip();
    if (!str_contains($ip, ':')) {
        return $ip;
    }
    $packed = @inet_pton($ip);
    if (!is_string($packed) || strlen($packed) !== 16) {
        return $ip;
    }
    if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
        return (string) inet_ntop(substr($packed, 12));
    }

    return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
}

/**
 * **この1回を数えてから**、照合してよいかを返す。照合の前に呼ぶこと。
 *
 * true なら照合してよい。false なら上限を超えている(照合せずに 429 を返す)。
 * 失敗したときに km_map_record_unlock_failure() を**重ねて呼ばない**(二重に数える)。
 * 成功したときに回数を消すかは錠ごとに呼ぶ側が決める('web' は消す、'app' は消さない)。
 *
 * DB が使えないときは、ファイルで同じ数え方をする。
 */
function km_map_unlock_attempt(PDO $pdo, string $scope = 'web'): bool
{
    $table = km_map_rate_limit_table($scope);
    $limit = km_map_rate_limit_limit($scope);
    $key = km_map_rate_limit_key();

    try {
        km_map_rate_limit_ensure_table($pdo, $scope);
        /*
         * 1本の文で数える。**読んでから書く形にしない**(同時に来た分が数え漏れる)。
         *
         * - ロック中 … そのまま +1(ロックの期限は延ばさない)
         * - ロックが切れた・最後の試行から窓を過ぎた … 1 から数え直す
         * - 上限に届いたら、その時点からロック
         *
         * 新しい回数はセッション変数に一度だけ入れて、後の式はそれを読む
         * (UPDATE … SET は左から右へ評価され、後の式が「更新後」の値を見てしまうため。
         * km_map_record_unlock_failure() の注記と同じ)。locked_until を読む式はすべて
         * locked_until 自身への代入より前にあるので、古い値を見る。
         */
        $stmt = $pdo->prepare(
            "INSERT INTO {$table}
                (ip_address, failure_count, first_failed_at, last_failed_at, locked_until)
             VALUES
                (INET6_ATON(?), 1, NOW(), NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                failure_count = (@km_new_failure_count := IF(
                    locked_until IS NOT NULL AND locked_until > NOW(),
                    failure_count + 1,
                    IF(
                        locked_until IS NOT NULL OR last_failed_at < DATE_SUB(NOW(), INTERVAL ? SECOND),
                        1,
                        failure_count + 1
                    )
                )),
                first_failed_at = IF(@km_new_failure_count = 1, NOW(), first_failed_at),
                last_failed_at = NOW(),
                locked_until = IF(
                    @km_new_failure_count >= ?,
                    IF(locked_until IS NOT NULL AND locked_until > NOW(), locked_until, DATE_ADD(NOW(), INTERVAL ? SECOND)),
                    NULL
                )"
        );
        $stmt->execute([$key, KM_MAP_UNLOCK_WINDOW_SECONDS, $limit, KM_MAP_UNLOCK_LOCK_SECONDS]);

        // 数えた後の値を読む。同時に来た別の試行が先に足していれば、その分も含めて見る(厳しい側に倒れる)
        $read = $pdo->prepare("SELECT failure_count FROM {$table} WHERE ip_address = INET6_ATON(?)");
        $read->execute([$key]);
        $count = $read->fetchColumn();

        return $count !== false && (int) $count <= $limit;
    } catch (PDOException $exception) {
        error_log("km_map_unlock_attempt({$scope}): DB が使えないためファイルで数えます: " . $exception->getMessage());

        return km_map_rate_limit_fallback_consume($scope) <= $limit;
    }
}

/** ロック中なら true。DB が使えないときは、ファイルで数えた結果を返す。 */
function km_map_rate_limited(PDO $pdo, string $scope = 'web'): bool
{
    $table = km_map_rate_limit_table($scope);
    try {
        km_map_rate_limit_ensure_table($pdo, $scope);
        // locked_until と「今」の比較は DB 側で完結させる(NOW() と比較する)。
        // 以前は locked_until を PHP へ取り出して strtotime() と time() で比較していたが、
        // DB サーバーの時計(UTC 相当)と PHP の既定タイムゾーン(Asia/Tokyo)がずれており、
        // strtotime() が locked_until を JST として誤解釈するため約9時間分「過去」に見え、
        // ロック判定が常に false になる(=総当たり対策が無言で無効化される)バグがあった。
        $stmt = $pdo->prepare(
            "SELECT locked_until IS NOT NULL AND locked_until > NOW() AS is_locked
             FROM {$table} WHERE ip_address = INET6_ATON(?)"
        );
        $stmt->execute([km_map_rate_limit_key()]);
        $isLocked = $stmt->fetchColumn();
        return $isLocked !== false && (bool) $isLocked;
    } catch (PDOException $exception) {
        error_log("km_map_rate_limited({$scope}): DB が使えないためファイルで判定します: " . $exception->getMessage());
        return km_map_rate_limit_fallback_locked($scope);
    }
}

/**
 * 失敗を1回記録する。**照合の後で数える古い形。**
 * いまの api は km_map_unlock_attempt() で先に数えるので、ここは呼ばない(二重に数える)。
 * 他から使われていたときのために残す。
 */
function km_map_record_unlock_failure(PDO $pdo, string $scope = 'web'): void
{
    $table = km_map_rate_limit_table($scope);
    try {
        km_map_rate_limit_ensure_table($pdo, $scope);
        $stmt = $pdo->prepare(
            "INSERT INTO {$table}
                (ip_address, failure_count, first_failed_at, last_failed_at, locked_until)
             VALUES
                (INET6_ATON(?), 1, NOW(), NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                failure_count = (@km_new_failure_count := IF(
                    last_failed_at < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, failure_count + 1
                )),
                first_failed_at = IF(last_failed_at < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), first_failed_at),
                last_failed_at = NOW(),
                locked_until = IF(@km_new_failure_count >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), locked_until)"
        );
        // MariaDB の UPDATE ... SET は左から右へ順に評価され、後続の式は先行する式で
        // 「更新後」の値を参照できてしまう。以前は failure_count + 1 を locked_until 側の
        // 式でもう一度書いていたため二重に +1 され、規定回数の1回前でロックされるバグがあった。
        // 計算結果をセッション変数 @km_new_failure_count に一度だけ入れ、そちらを読む。
        //
        // "?" の出現回数ぶんだけ律儀に値を渡す(ネイティブ prepared statement なので、
        // 使い回すと "SQLSTATE[HY093]: Invalid parameter number" になる)。
        $stmt->execute([
            km_map_rate_limit_key(),
            KM_MAP_UNLOCK_WINDOW_SECONDS,
            KM_MAP_UNLOCK_WINDOW_SECONDS,
            km_map_rate_limit_limit($scope),
            KM_MAP_UNLOCK_LOCK_SECONDS,
        ]);
    } catch (PDOException $exception) {
        error_log("km_map_record_unlock_failure({$scope}): DB が使えないためファイルに記録します: " . $exception->getMessage());
        km_map_rate_limit_fallback_record($scope);
    }
}

/**
 * 失敗回数を消す。**この錠の分だけ。**
 *
 * **行ごと消す**(2026-09-25、診断 W-49)。以前は 0 に戻す UPDATE で、照合の前に必ず 1 回数えるため
 * **1 回で正しく解除した人の IP まで「失敗 0 回」の行として残り続けた。**
 * 冒頭の「DELETE 権限が無い環境」の配慮は、アプリの DB 利用者が ALL を持つと分かった今は要らない
 * (lib/account-delete.php も DELETE している)。
 *
 * **'app' では呼ばない。** アクセスコードは配布物で誰でも持っているので、成功で消すと
 * 「外れ7回ごとに当たり1回」で無制限に試せる(android-data#1)。時間の窓で自然に消えるのを待つ。
 */
function km_map_clear_unlock_failures(PDO $pdo, string $scope = 'web'): void
{
    $table = km_map_rate_limit_table($scope);
    km_map_rate_limit_fallback_clear($scope);
    try {
        km_map_rate_limit_ensure_table($pdo, $scope);
        $stmt = $pdo->prepare(
            "DELETE FROM {$table}
             WHERE ip_address = INET6_ATON(?)"
        );
        $stmt->execute([km_map_rate_limit_key()]);
    } catch (PDOException $exception) {
        error_log("km_map_clear_unlock_failures({$scope}) failed: " . $exception->getMessage());
    }
}

// ---------------------------------------------------------------- DB が使えないときの予備

/** 予備の置き場。試験のときだけ KM_RATE_LIMIT_FALLBACK_DIR で差し替える。 */
function km_map_rate_limit_fallback_dir(): string
{
    $configured = getenv('KM_RATE_LIMIT_FALLBACK_DIR');
    if (is_string($configured) && trim($configured) !== '') {
        return rtrim(trim($configured), '/\\');
    }

    return __DIR__ . '/../cache/unlock';
}

/** 錠と IP ごとのファイル。**IP はファイル名に出さない**(ハッシュにする)。 */
function km_map_rate_limit_fallback_path(string $scope): ?string
{
    km_map_rate_limit_table($scope);
    $directory = km_map_rate_limit_fallback_dir();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('km_map_rate_limit: 予備の置き場を作れません: ' . $directory);
        return null;
    }

    return $directory . DIRECTORY_SEPARATOR . $scope . '-' . hash('sha256', km_map_rate_limit_key()) . '.json';
}

/**
 * 読む。**自分の持ち物で、他人が書けないものだけを信用する**(logto_guard.php の JWKS と同じ)。
 *
 * @return array{count:int, first:int, last:int, lockedUntil:int}
 */
function km_map_rate_limit_fallback_read(?string $path): array
{
    $empty = ['count' => 0, 'first' => 0, 'last' => 0, 'lockedUntil' => 0];
    if ($path === null || !is_file($path)) {
        return $empty;
    }
    $stat = @stat($path);
    if ($stat === false) {
        return $empty;
    }
    if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
        error_log('km_map_rate_limit: 持ち主の違う予備ファイルを無視しました: ' . $path);
        return $empty;
    }
    // Windows(手元の検査)は権限の値が当てにならないので、Linux のときだけ見る
    if (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0022) !== 0) {
        error_log('km_map_rate_limit: 他人が書ける予備ファイルを無視しました: ' . $path);
        return $empty;
    }
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) {
        return $empty;
    }

    return [
        'count' => (int) ($data['count'] ?? 0),
        'first' => (int) ($data['first'] ?? 0),
        'last' => (int) ($data['last'] ?? 0),
        'lockedUntil' => (int) ($data['lockedUntil'] ?? 0),
    ];
}

function km_map_rate_limit_fallback_locked(string $scope, ?int $now = null): bool
{
    $now ??= time();

    return km_map_rate_limit_fallback_read(km_map_rate_limit_fallback_path($scope))['lockedUntil'] > $now;
}

/**
 * 1回数えた後の状態。**純粋関数**(DB の文と同じ規則をここに1つだけ書く)。
 *
 * @param array{count:int, first:int, last:int, lockedUntil:int} $state
 * @return array{count:int, first:int, last:int, lockedUntil:int}
 */
function km_map_rate_limit_next_state(array $state, int $now, int $limit): array
{
    $lockActive = $state['lockedUntil'] > $now;
    // ロックが切れた、または最後の試行から窓を過ぎた → 数え直し
    if (!$lockActive && ($state['lockedUntil'] > 0 || $state['last'] < $now - KM_MAP_UNLOCK_WINDOW_SECONDS)) {
        $state['count'] = 0;
        $state['first'] = $now;
        $state['lockedUntil'] = 0;
    }
    $state['count']++;
    $state['last'] = $now;
    if (!$lockActive && $state['count'] >= $limit) {
        $state['lockedUntil'] = $now + KM_MAP_UNLOCK_LOCK_SECONDS;
    }

    return $state;
}

/**
 * ファイルで1回数え、数えた後の回数を返す。
 *
 * 同時に来た分を取りこぼさないよう、隣の .lock を flock してから読み書きする
 * (DB の1文ほど固くはないが、予備としては足りる)。書けなければ数えられなかったとして
 * **上限を超えた値を返す**(DB も書けない状態で「通す」に倒さない)。
 */
function km_map_rate_limit_fallback_consume(string $scope, ?int $now = null): int
{
    $now ??= time();
    $limit = km_map_rate_limit_limit($scope);
    $path = km_map_rate_limit_fallback_path($scope);
    if ($path === null) {
        return $limit + 1;
    }

    $lock = @fopen($path . '.lock', 'c');
    if ($lock !== false) {
        @chmod($path . '.lock', 0600);
        flock($lock, LOCK_EX);
    }
    try {
        $state = km_map_rate_limit_next_state(km_map_rate_limit_fallback_read($path), $now, $limit);

        // ランダム名へ 0600 で書いてから rename する(途中で読まれても壊れた JSON を見せない)
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, (string) json_encode($state), LOCK_EX) === false) {
            return $limit + 1;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return $limit + 1;
        }

        return $state['count'];
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** DB と同じ数え方: 窓を過ぎたら数え直し、上限に届いたらロックする。 */
function km_map_rate_limit_fallback_record(string $scope, ?int $now = null): void
{
    km_map_rate_limit_fallback_consume($scope, $now);
}

function km_map_rate_limit_fallback_clear(string $scope): void
{
    $path = km_map_rate_limit_fallback_path($scope);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}
