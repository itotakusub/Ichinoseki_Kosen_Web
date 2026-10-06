<?php

declare(strict_types=1);

/**
 * Wi-Fi の学習データと精度の評価の置き場(2026-10-06、利用者の指示「学習データは基本的にサーバーに保存し、
 * データをダウンロードしてクライアントだけで処理する」)。
 *
 * - **サーバーは保存するだけで、計算しない。** 学習地点ごとの値にまとめるのも、機械学習も、全部アプリがする
 * - 送る単位は**学習 1 回ごとの生の記録**(利用者の決定)。管理端末が何台あっても上書きで消えない
 * - アプリは「前回から変わった分」だけを落とす。変わるたびに通し番号(seq)を振り、消したものも番号付きで知らせる
 * - 学習データは地図のアクセスコード(または管理者のトークン)で読める。評価は管理者だけ
 *
 * それまで学習データは管理端末の中にしか無く、`km_map_fingerprints` は 0 行のまま(2026-10-06 に本番で実測)。
 * 一般のアプリは学習データを持たず、指紋の方式が働いていなかった。
 */

require_once __DIR__ . '/db.php';

/** 1 回で返す量の目安(学習データの本文の合計バイト)。アプリは 1MB までしか読まない。 */
const KM_LEARNING_PAGE_BYTES = 600 * 1024;
const KM_LEARNING_PAGE_ROWS = 400;
/** 1 回で受け取る記録の数。 */
const KM_LEARNING_PUSH_MAX = 200;

function km_learning_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_learning_seq (
            id TINYINT NOT NULL PRIMARY KEY,
            value BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_learning_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sample_uuid CHAR(36) NOT NULL,
            node_uuid VARCHAR(64) NOT NULL,
            floor_id VARCHAR(40) NOT NULL,
            x DOUBLE NOT NULL,
            y DOUBLE NOT NULL,
            rssi_json MEDIUMTEXT NOT NULL,
            altitude_meters DOUBLE NULL,
            sample_count INT NOT NULL DEFAULT 1,
            measured_at_millis BIGINT NOT NULL,
            uploaded_by VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            seq BIGINT UNSIGNED NOT NULL,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_km_learning_samples_uuid (sample_uuid),
            INDEX idx_km_learning_samples_seq (seq),
            INDEX idx_km_learning_samples_node (node_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_learning_evaluations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            eval_uuid CHAR(36) NOT NULL,
            node_uuid VARCHAR(64) NOT NULL,
            floor_id VARCHAR(40) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            uploaded_by VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            seq BIGINT UNSIGNED NOT NULL,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_km_learning_evaluations_uuid (eval_uuid),
            INDEX idx_km_learning_evaluations_seq (seq)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ensured = true;
}

/** 次の通し番号。学習データと評価で共通(どちらが変わっても単調に増える)。 */
function km_learning_next_seq(PDO $pdo): int
{
    $pdo->exec('INSERT INTO km_learning_seq (id, value) VALUES (1, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)');
    return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

const KM_LEARNING_UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
const KM_LEARNING_NODE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
const KM_LEARNING_FLOOR_PATTERN = '/^[A-Za-z0-9_]{1,40}$/';
const KM_LEARNING_BSSID_PATTERN = '/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/';

/**
 * 学習 1 回の記録を検める。**純粋関数。** おかしなものは理由を付けて断る(InvalidArgumentException)。
 *
 * @param array<string,mixed> $input
 * @return array{sampleUuid:string,nodeUuid:string,floor:string,x:float,y:float,rssiByBssid:array<string,int>,altitudeMeters:?float,sampleCount:int,measuredAtMillis:int}
 */
function km_learning_normalize_sample(array $input): array
{
    $uuid = (string) ($input['sampleUuid'] ?? '');
    $node = (string) ($input['nodeUuid'] ?? '');
    $floor = (string) ($input['floor'] ?? '');
    if (preg_match(KM_LEARNING_UUID_PATTERN, $uuid) !== 1) {
        throw new InvalidArgumentException('sampleUuid の形が違います。');
    }
    if (preg_match(KM_LEARNING_NODE_PATTERN, $node) !== 1 || preg_match(KM_LEARNING_FLOOR_PATTERN, $floor) !== 1) {
        throw new InvalidArgumentException('地点か階の形が違います。');
    }
    foreach (['x', 'y'] as $axis) {
        $v = $input[$axis] ?? null;
        if (!(is_int($v) || is_float($v)) || !is_finite((float) $v) || abs((float) $v) > 1e6) {
            throw new InvalidArgumentException($axis . ' は数で入れてください。');
        }
    }
    $rssi = $input['rssiByBssid'] ?? null;
    if (!is_array($rssi) || $rssi === [] || count($rssi) > 600) {
        throw new InvalidArgumentException('電波の値(1〜600 件)が必要です。');
    }
    $cleaned = [];
    foreach ($rssi as $bssid => $value) {
        $key = strtoupper(trim((string) $bssid));
        if (preg_match(KM_LEARNING_BSSID_PATTERN, $key) !== 1 || !is_int($value) || $value < -127 || $value > 0) {
            throw new InvalidArgumentException('電波の値の形が違います。');
        }
        $cleaned[$key] = $value;
    }
    $altitude = $input['altitudeMeters'] ?? null;
    if ($altitude !== null && (!(is_int($altitude) || is_float($altitude)) || !is_finite((float) $altitude) || abs((float) $altitude) > 10000)) {
        throw new InvalidArgumentException('altitudeMeters の形が違います。');
    }
    $count = $input['sampleCount'] ?? 1;
    if (!is_int($count) || $count < 1 || $count > 1000) {
        throw new InvalidArgumentException('sampleCount は 1〜1000 です。');
    }
    $measured = $input['measuredAtMillis'] ?? null;
    if (!is_int($measured) || $measured < 1_000_000_000_000 || $measured > 4_000_000_000_000) {
        throw new InvalidArgumentException('measuredAtMillis の形が違います。');
    }
    return [
        'sampleUuid' => strtolower($uuid),
        'nodeUuid' => $node,
        'floor' => $floor,
        'x' => (float) $input['x'],
        'y' => (float) $input['y'],
        'rssiByBssid' => $cleaned,
        'altitudeMeters' => $altitude === null ? null : (float) $altitude,
        'sampleCount' => $count,
        'measuredAtMillis' => $measured,
    ];
}

/**
 * 評価 1 件を検める。**純粋関数。** 中身はアプリの PositioningEvaluation のまま保存する(サーバーは読まない)。
 *
 * @param array<string,mixed> $input
 * @return array{uuid:string,nodeUuid:string,floor:string,body:string}
 */
function km_learning_normalize_evaluation(array $input): array
{
    $uuid = (string) ($input['uuid'] ?? '');
    $node = (string) ($input['nodeUuid'] ?? '');
    $floor = (string) ($input['floor'] ?? '');
    if (preg_match(KM_LEARNING_UUID_PATTERN, $uuid) !== 1) {
        throw new InvalidArgumentException('評価の uuid の形が違います。');
    }
    if (preg_match(KM_LEARNING_NODE_PATTERN, $node) !== 1 || preg_match(KM_LEARNING_FLOOR_PATTERN, $floor) !== 1) {
        throw new InvalidArgumentException('評価の地点か階の形が違います。');
    }
    $body = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($body) || strlen($body) > 32 * 1024) {
        throw new InvalidArgumentException('評価が大きすぎます。');
    }
    return ['uuid' => strtolower($uuid), 'nodeUuid' => $node, 'floor' => $floor, 'body' => $body];
}

/**
 * 受け取る。**同じ uuid は 2 度入れない**(送り直しても増えない)。入れた数を返す。
 * ただし**消した記録と同じ uuid が来たら生き返らせる**(端末の「リセットを元に戻す」で送り直したとき)。
 * 代入は左から順に評価されるので、deleted を最後に書き換える(前の deleted を見て決めるため)。
 *
 * @param list<array<string,mixed>> $samples km_learning_normalize_sample を通したもの
 * @param list<array<string,mixed>> $evaluations km_learning_normalize_evaluation を通したもの
 * @return array{samples:int, evaluations:int}
 */
function km_learning_store(PDO $pdo, array $samples, array $evaluations, ?string $uploadedBy): array
{
    km_learning_ensure_tables($pdo);
    $insertedSamples = 0;
    $insertedEvaluations = 0;
    $sampleStatement = $pdo->prepare(
        'INSERT INTO km_learning_samples
            (sample_uuid, node_uuid, floor_id, x, y, rssi_json, altitude_meters, sample_count, measured_at_millis, uploaded_by, created_at, seq)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
            rssi_json = IF(deleted = 1, VALUES(rssi_json), rssi_json),
            seq = IF(deleted = 1, VALUES(seq), seq),
            deleted = 0'
    );
    foreach ($samples as $s) {
        $sampleStatement->execute([
            $s['sampleUuid'], $s['nodeUuid'], $s['floor'], $s['x'], $s['y'],
            json_encode($s['rssiByBssid'], JSON_THROW_ON_ERROR), $s['altitudeMeters'], $s['sampleCount'],
            $s['measuredAtMillis'], $uploadedBy, km_learning_next_seq($pdo),
        ]);
        // 入れた = 1、生き返らせた = 2(MariaDB の数え方)、同じものがあった = 0
        $insertedSamples += $sampleStatement->rowCount() > 0 ? 1 : 0;
    }
    $evaluationStatement = $pdo->prepare(
        'INSERT INTO km_learning_evaluations (eval_uuid, node_uuid, floor_id, body, uploaded_by, created_at, seq)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
            body = IF(deleted = 1, VALUES(body), body),
            seq = IF(deleted = 1, VALUES(seq), seq),
            deleted = 0'
    );
    foreach ($evaluations as $e) {
        $evaluationStatement->execute([$e['uuid'], $e['nodeUuid'], $e['floor'], $e['body'], $uploadedBy, km_learning_next_seq($pdo)]);
        $insertedEvaluations += $evaluationStatement->rowCount() > 0 ? 1 : 0;
    }
    return ['samples' => $insertedSamples, 'evaluations' => $insertedEvaluations];
}

/**
 * [since] より後に変わった分。消したものは uuid だけを deleted に入れる。量が多ければ途中で切って more=true。
 *
 * @return array{items:list<array<string,mixed>>, deleted:list<string>, nextSeq:int, more:bool}
 */
function km_learning_changes(PDO $pdo, string $kind, int $since): array
{
    km_learning_ensure_tables($pdo);
    $isSamples = $kind === 'samples';
    $sql = $isSamples
        ? 'SELECT sample_uuid AS uuid, node_uuid, floor_id, x, y, rssi_json AS body, altitude_meters, sample_count, measured_at_millis, seq, deleted
             FROM km_learning_samples WHERE seq > ? ORDER BY seq LIMIT ?'
        : 'SELECT eval_uuid AS uuid, body, seq, deleted FROM km_learning_evaluations WHERE seq > ? ORDER BY seq LIMIT ?';
    $statement = $pdo->prepare($sql);
    $statement->bindValue(1, max(0, $since), PDO::PARAM_INT);
    $statement->bindValue(2, KM_LEARNING_PAGE_ROWS + 1, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $deleted = [];
    $bytes = 0;
    $nextSeq = $since;
    $more = false;
    foreach ($rows as $index => $row) {
        if ($index >= KM_LEARNING_PAGE_ROWS || ($bytes > KM_LEARNING_PAGE_BYTES && $items !== [])) {
            $more = true;
            break;
        }
        $nextSeq = (int) $row['seq'];
        if ((int) $row['deleted'] === 1) {
            $deleted[] = (string) $row['uuid'];
            continue;
        }
        $bytes += strlen((string) $row['body']);
        if ($isSamples) {
            $items[] = [
                'sampleUuid' => (string) $row['uuid'],
                'nodeUuid' => (string) $row['node_uuid'],
                'floor' => (string) $row['floor_id'],
                'x' => (float) $row['x'],
                'y' => (float) $row['y'],
                'rssiByBssid' => json_decode((string) $row['body'], true) ?: (object) [],
                'altitudeMeters' => $row['altitude_meters'] === null ? null : (float) $row['altitude_meters'],
                'sampleCount' => (int) $row['sample_count'],
                'measuredAtMillis' => (int) $row['measured_at_millis'],
            ];
        } else {
            $decoded = json_decode((string) $row['body'], true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
    }
    return ['items' => $items, 'deleted' => $deleted, 'nextSeq' => $nextSeq, 'more' => $more];
}

/**
 * 消す(管理者)。行は残して中身を空にし、通し番号を振り直す(アプリが「消えた」と知るため)。消した数を返す。
 *
 * @param 'all'|'floor'|'nodes' $scope
 * @param list<string> $nodeUuids
 */
function km_learning_delete(PDO $pdo, string $kind, string $scope, ?string $floor, array $nodeUuids): int
{
    km_learning_ensure_tables($pdo);
    $table = $kind === 'samples' ? 'km_learning_samples' : 'km_learning_evaluations';
    $clear = $kind === 'samples' ? ", rssi_json = ''" : ", body = ''";
    $where = 'deleted = 0';
    $params = [];
    if ($scope === 'floor') {
        if ($floor === null || preg_match(KM_LEARNING_FLOOR_PATTERN, $floor) !== 1) {
            throw new InvalidArgumentException('階の形が違います。');
        }
        $where .= ' AND floor_id = ?';
        $params[] = $floor;
    } elseif ($scope === 'nodes') {
        $nodes = array_values(array_filter($nodeUuids, static fn ($n) => is_string($n) && preg_match(KM_LEARNING_NODE_PATTERN, $n) === 1));
        if ($nodes === []) {
            throw new InvalidArgumentException('消す地点を選んでください。');
        }
        $where .= ' AND node_uuid IN (' . implode(',', array_fill(0, count($nodes), '?')) . ')';
        $params = $nodes;
    } elseif ($scope !== 'all') {
        throw new InvalidArgumentException('消す範囲が違います。');
    }
    $ids = $pdo->prepare("SELECT id FROM {$table} WHERE {$where}");
    $ids->execute($params);
    $update = $pdo->prepare("UPDATE {$table} SET deleted = 1, seq = ?{$clear} WHERE id = ?");
    $count = 0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $update->execute([km_learning_next_seq($pdo), (int) $id]);
        $count++;
    }
    return $count;
}

/**
 * 地図のアクセスコードで読んでよいか(一般のアプリ)。**照合は鍵つきの印(lookup)だけ** —— アプリは先に地図を取るので、
 * 古いコードでも api/app-map.php が lookup を書き足している。外れは地図と同じ錠('app')で数える。
 *
 * @return array{ok:bool, status:int, message:string}
 */
function km_learning_code_allowed(PDO $pdo, string $rawCode): array
{
    require_once __DIR__ . '/app-map.php';
    require_once __DIR__ . '/app-secret.php';
    require_once __DIR__ . '/map-rate-limit.php';
    $code = km_app_map_normalize_code($rawCode);
    if ($code === '') {
        return ['ok' => false, 'status' => 400, 'message' => 'アクセスコードが必要です。'];
    }
    try {
        $lookup = km_app_map_code_lookup(km_app_secret($pdo, KM_APP_MAP_CODE_SECRET), $code);
    } catch (Throwable $exception) {
        error_log('km_learning_code_allowed: ' . $exception->getMessage());
        return ['ok' => false, 'status' => 503, 'message' => '現在利用できません。'];
    }
    $config = km_app_map_config();
    $found = km_app_map_find_code_by_lookup($config, $lookup);
    if ($found === null) {
        if (!km_map_unlock_attempt($pdo, 'app')) {
            return ['ok' => false, 'status' => 429, 'message' => '試行回数が多すぎます。しばらく待ってからやり直してください。'];
        }
        return ['ok' => false, 'status' => 401, 'message' => 'アクセスコードが違います(地図を取得し直してください)。'];
    }
    $meta = $config['maps'][$found['slug']] ?? null;
    if (!is_array($meta) || km_app_map_is_paused($meta)) {
        return ['ok' => false, 'status' => 410, 'message' => 'このマップの配信は停止しています。'];
    }
    $expiresAt = km_app_map_parse_iso((string) ($meta['expiresAt'] ?? ''));
    if ($expiresAt === null || $expiresAt->getTimestamp() < time()) {
        return ['ok' => false, 'status' => 410, 'message' => 'このマップの配信は終了しています。'];
    }
    return ['ok' => true, 'status' => 200, 'message' => ''];
}
