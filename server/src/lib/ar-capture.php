<?php

declare(strict_types=1);

/**
 * AR 実測の記録と画像の置き場(2026-10-06、利用者の指示「ARCore は画像を保存するようにする」
 * 「学習データは基本的にサーバーに保存し、データをダウンロードしてクライアントだけで処理する」)。
 *
 * - **サーバーは置くだけで、計算しない。** 地図の誤差を直す候補はアプリ(positioning/ArSurvey.kt)が、
 *   3D・疑似ストリートビューは PC(RealityScan / COLMAP)が作る
 * - 記録(軌跡・印・深度の点)は 1 回の実測ごとに gzip のまま表に置く。画像は uploads/ar-captures/<sha256>.<jpg|png>
 * - **読み書きはすべて管理者だけ。** 画像には人が写りうる(利用者の決定: PC でぼかす)。一般のアプリと地図の配信には載せない。
 *   処理が済んだら管理画面(admin/ar-captures.php)から消す運用
 * - PC へは撮影ごとの zip(圧縮しない。JPEG はもう縮まない)。web の像には zip の拡張が無いので、自前で流しながら書く
 *   ([km_zip_stream_stored])。COLMAP の「既知の姿勢」の形も入れる([km_ar_colmap_pose])
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';

/** 記録(gzip)の上限と、ほどいたあとの上限 */
const KM_AR_RECORD_MAX_GZ_BYTES = 3 * 1024 * 1024;
const KM_AR_RECORD_MAX_JSON_BYTES = 12 * 1024 * 1024;
/** 画像 1 枚(JPEG)と深度 1 枚(16bit PNG)の上限。nginx の client_max_body_size と揃える */
const KM_AR_IMAGE_MAX_BYTES = 3 * 1024 * 1024;
const KM_AR_DEPTH_MAX_BYTES = 1024 * 1024;
/** 1 回の撮影の枚数 */
const KM_AR_FRAMES_PER_SESSION = 600;
/** 全体の容量(配備の前に本番の空きを見て決めた。2026-10-06) */
const KM_AR_TOTAL_MAX_BYTES = 5 * 1024 * 1024 * 1024;

const KM_AR_UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';
const KM_AR_FLOOR_PATTERN = '/\A[A-Za-z0-9_]{1,40}\z/';

function km_ar_capture_dir(): string
{
    return km_upload_dir() . '/ar-captures';
}

function km_ar_capture_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_ar_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_uuid CHAR(36) NOT NULL,
            floor_id VARCHAR(40) NOT NULL,
            started_at_millis BIGINT NOT NULL,
            marks INT NOT NULL DEFAULT 0,
            record_gz MEDIUMBLOB NOT NULL,
            record_bytes INT UNSIGNED NOT NULL,
            created_by VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_km_ar_sessions_uuid (session_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_ar_frames (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_uuid CHAR(36) NOT NULL,
            idx INT NOT NULL,
            image_sha256 CHAR(64) NOT NULL,
            image_bytes INT UNSIGNED NOT NULL,
            width INT UNSIGNED NOT NULL,
            height INT UNSIGNED NOT NULL,
            depth_sha256 CHAR(64) NULL,
            depth_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            meta_json TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_km_ar_frames_idx (session_uuid, idx),
            INDEX idx_km_ar_frames_image (image_sha256),
            INDEX idx_km_ar_frames_depth (depth_sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ensured = true;
}

// ---------------------------------------------------------------- 検め方(純粋関数)

/**
 * 記録(gzip の JSON)を検める。中身はアプリの ArSurveyRecord のまま保存する(サーバーは読まない)。
 * 形だけ見る: uuid・階・始めた時刻・配列。
 *
 * @return array{uuid:string, floor:string, startedAtMillis:int, marks:int, json:string}
 */
function km_ar_normalize_record(string $gz): array
{
    if ($gz === '' || strlen($gz) > KM_AR_RECORD_MAX_GZ_BYTES) {
        throw new InvalidArgumentException('記録の大きさが範囲外です(3MB まで)。');
    }
    $json = @gzdecode($gz, KM_AR_RECORD_MAX_JSON_BYTES);
    if (!is_string($json)) {
        throw new InvalidArgumentException('記録を gzip としてほどけませんでした。');
    }
    $record = json_decode($json, true);
    if (!is_array($record)) {
        throw new InvalidArgumentException('記録が JSON ではありません。');
    }
    $uuid = strtolower((string) ($record['uuid'] ?? ''));
    $floor = (string) ($record['floor'] ?? '');
    $started = $record['startedAtMillis'] ?? null;
    if (preg_match(KM_AR_UUID_PATTERN, $uuid) !== 1) {
        throw new InvalidArgumentException('記録の uuid の形が違います。');
    }
    if (preg_match(KM_AR_FLOOR_PATTERN, $floor) !== 1) {
        throw new InvalidArgumentException('階の形が違います。');
    }
    if (!is_int($started) || $started < 1_000_000_000_000 || $started > 4_000_000_000_000) {
        throw new InvalidArgumentException('startedAtMillis の形が違います。');
    }
    foreach (['track', 'marks', 'depthHits', 'trackingLostAt'] as $key) {
        if (isset($record[$key]) && !is_array($record[$key])) {
            throw new InvalidArgumentException($key . ' は配列で送ってください。');
        }
    }
    return ['uuid' => $uuid, 'floor' => $floor, 'startedAtMillis' => $started, 'marks' => count($record['marks'] ?? []), 'json' => $json];
}

/**
 * 画像 1 枚の付帯情報を検める。**純粋関数。**
 *
 * - pose: カメラの位置 t(AR の世界の m)と向き q(x, y, z, w。ARCore の Camera.getPose =
 *   画像の読み出しの向きに合わせた OpenGL のカメラ: +x 右・+y 上・-z が見ている向き)
 * - intrinsics: fx, fy, cx, cy(px)と、それを測った画像の幅・高さ
 *
 * @param array<string,mixed> $input
 * @return array{timestampMillis:int, pose:array{t:list<float>, q:list<float>}, intrinsics:array{fx:float,fy:float,cx:float,cy:float,width:int,height:int}, map:?array{x:float,y:float}}
 */
function km_ar_normalize_frame_meta(array $input): array
{
    $number = static function ($value, string $name, float $limit): float {
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || abs((float) $value) > $limit) {
            throw new InvalidArgumentException($name . ' は数で入れてください。');
        }
        return (float) $value;
    };
    $time = $input['timestampMillis'] ?? null;
    if (!is_int($time) || $time < 1_000_000_000_000 || $time > 4_000_000_000_000) {
        throw new InvalidArgumentException('timestampMillis の形が違います。');
    }
    $pose = $input['pose'] ?? null;
    if (!is_array($pose) || !is_array($pose['t'] ?? null) || !is_array($pose['q'] ?? null)
        || count($pose['t']) !== 3 || count($pose['q']) !== 4) {
        throw new InvalidArgumentException('pose は t(3 つ)と q(4 つ)で送ってください。');
    }
    $t = array_map(static fn ($v) => $number($v, 'pose.t', 10000.0), array_values($pose['t']));
    $q = array_map(static fn ($v) => $number($v, 'pose.q', 2.0), array_values($pose['q']));
    $norm = sqrt($q[0] ** 2 + $q[1] ** 2 + $q[2] ** 2 + $q[3] ** 2);
    if ($norm < 0.5 || $norm > 1.5) {
        throw new InvalidArgumentException('pose.q が回転になっていません。');
    }
    $q = array_map(static fn ($v) => $v / $norm, $q);
    $k = $input['intrinsics'] ?? null;
    if (!is_array($k)) {
        throw new InvalidArgumentException('intrinsics が必要です。');
    }
    $width = $k['width'] ?? null;
    $height = $k['height'] ?? null;
    if (!is_int($width) || !is_int($height) || $width < 16 || $height < 16 || $width > 8192 || $height > 8192) {
        throw new InvalidArgumentException('intrinsics の幅・高さが範囲外です。');
    }
    $intrinsics = [
        'fx' => $number($k['fx'] ?? null, 'fx', 100000.0),
        'fy' => $number($k['fy'] ?? null, 'fy', 100000.0),
        'cx' => $number($k['cx'] ?? null, 'cx', 100000.0),
        'cy' => $number($k['cy'] ?? null, 'cy', 100000.0),
        'width' => $width,
        'height' => $height,
    ];
    if ($intrinsics['fx'] <= 0 || $intrinsics['fy'] <= 0) {
        throw new InvalidArgumentException('fx・fy は正の数です。');
    }
    $map = null;
    if (isset($input['map'])) {
        if (!is_array($input['map'])) {
            throw new InvalidArgumentException('map の形が違います。');
        }
        $map = ['x' => $number($input['map']['x'] ?? null, 'map.x', 1e6), 'y' => $number($input['map']['y'] ?? null, 'map.y', 1e6)];
    }
    return ['timestampMillis' => $time, 'pose' => ['t' => $t, 'q' => $q], 'intrinsics' => $intrinsics, 'map' => $map];
}

/**
 * 画像の中身を確かめる。**拡張子や Content-Type は信じず、中身を見る。**
 *
 * @return array{width:int, height:int, ext:string}
 */
function km_ar_inspect_image(string $bytes, bool $depth): array
{
    $max = $depth ? KM_AR_DEPTH_MAX_BYTES : KM_AR_IMAGE_MAX_BYTES;
    if ($bytes === '' || strlen($bytes) > $max) {
        throw new InvalidArgumentException(($depth ? '深度' : '画像') . 'の大きさが範囲外です。');
    }
    $info = @getimagesizefromstring($bytes);
    $type = $depth ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
    if (!is_array($info) || $info[2] !== $type) {
        throw new InvalidArgumentException($depth ? '深度は PNG で送ってください。' : '画像は JPEG で送ってください。');
    }
    [$width, $height] = [(int) $info[0], (int) $info[1]];
    if ($width < 16 || $height < 16 || $width > 8192 || $height > 8192) {
        throw new InvalidArgumentException('縦横の大きさが範囲外です。');
    }
    return ['width' => $width, 'height' => $height, 'ext' => $depth ? 'png' : 'jpg'];
}

// ---------------------------------------------------------------- 置く・読む・消す

/** 置いてある全部の大きさ(記録 + 画像 + 深度)。 */
function km_ar_total_bytes(PDO $pdo): int
{
    km_ar_capture_ensure_tables($pdo);
    $records = (int) $pdo->query('SELECT COALESCE(SUM(LENGTH(record_gz)), 0) FROM km_ar_sessions')->fetchColumn();
    $frames = (int) $pdo->query('SELECT COALESCE(SUM(image_bytes + depth_bytes), 0) FROM km_ar_frames')->fetchColumn();
    return $records + $frames;
}

/** 記録を置く(同じ uuid なら置き換える。終えたあとに送り直すことがある)。 */
function km_ar_put_session(PDO $pdo, string $gz, ?string $createdBy): array
{
    km_ar_capture_ensure_tables($pdo);
    $record = km_ar_normalize_record($gz);
    if (km_ar_total_bytes($pdo) + strlen($gz) > KM_AR_TOTAL_MAX_BYTES) {
        throw new InvalidArgumentException('置き場がいっぱいです。管理画面の「AR の撮影」で、処理が済んだものを消してください。');
    }
    $pdo->prepare(
        'INSERT INTO km_ar_sessions (session_uuid, floor_id, started_at_millis, marks, record_gz, record_bytes, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE floor_id = VALUES(floor_id), started_at_millis = VALUES(started_at_millis), marks = VALUES(marks),
            record_gz = VALUES(record_gz), record_bytes = VALUES(record_bytes), updated_at = NOW()'
    )->execute([$record['uuid'], $record['floor'], $record['startedAtMillis'], $record['marks'], $gz, strlen($record['json']), $createdBy]);
    return ['uuid' => $record['uuid'], 'floor' => $record['floor'], 'marks' => $record['marks']];
}

/** ハッシュの名前でファイルを置く(もう同じものがあれば置かない)。 */
function km_ar_store_file(string $bytes, string $ext): string
{
    $sha = hash('sha256', $bytes);
    $dir = km_ar_capture_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('置き場を作れませんでした: ' . $dir);
    }
    $path = $dir . '/' . $sha . '.' . $ext;
    if (!is_file($path) && @file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($path);
        throw new RuntimeException('書き込めませんでした。');
    }
    return $sha;
}

/** どの行からも使われていないファイルを消す。 */
function km_ar_unlink_if_unused(PDO $pdo, ?string $sha, string $ext): void
{
    if ($sha === null || preg_match('/\A[0-9a-f]{64}\z/', $sha) !== 1) {
        return;
    }
    $column = $ext === 'png' ? 'depth_sha256' : 'image_sha256';
    $statement = $pdo->prepare("SELECT COUNT(*) FROM km_ar_frames WHERE {$column} = ?");
    $statement->execute([$sha]);
    if ((int) $statement->fetchColumn() === 0) {
        @unlink(km_ar_capture_dir() . '/' . $sha . '.' . $ext);
    }
}

/**
 * 画像 1 枚を置く。同じ番号に同じ画像なら何もしない(送り直し)。違う画像なら置き換える。
 *
 * @return array{sessionUuid:string, index:int, sha256:string, depthSha256:?string, stored:bool}
 */
function km_ar_put_frame(PDO $pdo, string $sessionUuid, int $index, array $meta, string $image, ?string $depth): array
{
    km_ar_capture_ensure_tables($pdo);
    $sessionUuid = strtolower($sessionUuid);
    if (preg_match(KM_AR_UUID_PATTERN, $sessionUuid) !== 1) {
        throw new InvalidArgumentException('session の形が違います。');
    }
    if ($index < 0 || $index >= KM_AR_FRAMES_PER_SESSION) {
        throw new InvalidArgumentException('1 回の撮影は ' . KM_AR_FRAMES_PER_SESSION . ' 枚までです。');
    }
    $exists = $pdo->prepare('SELECT 1 FROM km_ar_sessions WHERE session_uuid = ?');
    $exists->execute([$sessionUuid]);
    if (!$exists->fetchColumn()) {
        throw new InvalidArgumentException('先に記録を送ってください(その撮影がまだありません)。');
    }
    $cleanMeta = km_ar_normalize_frame_meta($meta);
    $info = km_ar_inspect_image($image, false);
    if ($depth !== null) {
        km_ar_inspect_image($depth, true);
    }
    $imageSha = hash('sha256', $image);
    $depthSha = $depth === null ? null : hash('sha256', $depth);

    $current = $pdo->prepare('SELECT image_sha256, depth_sha256 FROM km_ar_frames WHERE session_uuid = ? AND idx = ?');
    $current->execute([$sessionUuid, $index]);
    $row = $current->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && $row['image_sha256'] === $imageSha && $row['depth_sha256'] === $depthSha) {
        return ['sessionUuid' => $sessionUuid, 'index' => $index, 'sha256' => $imageSha, 'depthSha256' => $depthSha, 'stored' => false];
    }
    $adding = strlen($image) + strlen($depth ?? '');
    if (km_ar_total_bytes($pdo) + $adding > KM_AR_TOTAL_MAX_BYTES) {
        throw new InvalidArgumentException('置き場がいっぱいです。管理画面の「AR の撮影」で、処理が済んだものを消してください。');
    }
    km_ar_store_file($image, 'jpg');
    if ($depth !== null) {
        km_ar_store_file($depth, 'png');
    }
    $pdo->prepare(
        'INSERT INTO km_ar_frames (session_uuid, idx, image_sha256, image_bytes, width, height, depth_sha256, depth_bytes, meta_json, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE image_sha256 = VALUES(image_sha256), image_bytes = VALUES(image_bytes), width = VALUES(width),
            height = VALUES(height), depth_sha256 = VALUES(depth_sha256), depth_bytes = VALUES(depth_bytes), meta_json = VALUES(meta_json)'
    )->execute([
        $sessionUuid, $index, $imageSha, strlen($image), $info['width'], $info['height'], $depthSha, strlen($depth ?? ''),
        json_encode($cleanMeta, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
    ]);
    if (is_array($row)) {
        // 置き換えた前の画像は、ほかで使っていなければ消す
        km_ar_unlink_if_unused($pdo, $row['image_sha256'] !== $imageSha ? $row['image_sha256'] : null, 'jpg');
        km_ar_unlink_if_unused($pdo, $row['depth_sha256'] !== $depthSha ? $row['depth_sha256'] : null, 'png');
    }
    return ['sessionUuid' => $sessionUuid, 'index' => $index, 'sha256' => $imageSha, 'depthSha256' => $depthSha, 'stored' => true];
}

/** 一覧(新しい順)。 */
function km_ar_list_sessions(PDO $pdo): array
{
    km_ar_capture_ensure_tables($pdo);
    $rows = $pdo->query(
        'SELECT s.session_uuid, s.floor_id, s.started_at_millis, s.marks, s.record_bytes, LENGTH(s.record_gz) AS record_gz_bytes,
                s.created_by, s.created_at, s.updated_at,
                COUNT(f.id) AS frames, COALESCE(SUM(f.image_bytes + f.depth_bytes), 0) AS frame_bytes,
                SUM(f.depth_sha256 IS NOT NULL) AS depth_frames
           FROM km_ar_sessions s LEFT JOIN km_ar_frames f ON f.session_uuid = s.session_uuid
          GROUP BY s.id ORDER BY s.started_at_millis DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn (array $r): array => [
        'uuid' => (string) $r['session_uuid'],
        'floor' => (string) $r['floor_id'],
        'startedAtMillis' => (int) $r['started_at_millis'],
        'marks' => (int) $r['marks'],
        'frames' => (int) $r['frames'],
        'depthFrames' => (int) $r['depth_frames'],
        'bytes' => (int) $r['record_gz_bytes'] + (int) $r['frame_bytes'],
        'createdBy' => (string) ($r['created_by'] ?? ''),
        'updatedAt' => (string) $r['updated_at'],
    ], $rows);
}

/** 記録の JSON(ほどいたもの)。無ければ null。 */
function km_ar_session_record(PDO $pdo, string $sessionUuid): ?string
{
    km_ar_capture_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT record_gz FROM km_ar_sessions WHERE session_uuid = ?');
    $statement->execute([strtolower($sessionUuid)]);
    $gz = $statement->fetchColumn();
    if (!is_string($gz)) {
        return null;
    }
    $json = @gzdecode($gz, KM_AR_RECORD_MAX_JSON_BYTES);
    return is_string($json) ? $json : null;
}

/** @return list<array{index:int, imageSha256:string, depthSha256:?string, width:int, height:int, meta:array}> */
function km_ar_session_frames(PDO $pdo, string $sessionUuid): array
{
    km_ar_capture_ensure_tables($pdo);
    $statement = $pdo->prepare('SELECT idx, image_sha256, depth_sha256, width, height, meta_json FROM km_ar_frames WHERE session_uuid = ? ORDER BY idx');
    $statement->execute([strtolower($sessionUuid)]);
    return array_map(static fn (array $r): array => [
        'index' => (int) $r['idx'],
        'imageSha256' => (string) $r['image_sha256'],
        'depthSha256' => $r['depth_sha256'] === null ? null : (string) $r['depth_sha256'],
        'width' => (int) $r['width'],
        'height' => (int) $r['height'],
        'meta' => json_decode((string) $r['meta_json'], true) ?: [],
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

/** 消す(画像のファイルも)。消した枚数を返す。無ければ null。 */
function km_ar_delete_session(PDO $pdo, string $sessionUuid): ?int
{
    km_ar_capture_ensure_tables($pdo);
    $sessionUuid = strtolower($sessionUuid);
    if (preg_match(KM_AR_UUID_PATTERN, $sessionUuid) !== 1) {
        return null;
    }
    $exists = $pdo->prepare('SELECT 1 FROM km_ar_sessions WHERE session_uuid = ?');
    $exists->execute([$sessionUuid]);
    if (!$exists->fetchColumn()) {
        return null;
    }
    $frames = km_ar_session_frames($pdo, $sessionUuid);
    $pdo->prepare('DELETE FROM km_ar_frames WHERE session_uuid = ?')->execute([$sessionUuid]);
    $pdo->prepare('DELETE FROM km_ar_sessions WHERE session_uuid = ?')->execute([$sessionUuid]);
    foreach ($frames as $frame) {
        km_ar_unlink_if_unused($pdo, $frame['imageSha256'], 'jpg');
        km_ar_unlink_if_unused($pdo, $frame['depthSha256'], 'png');
    }
    return count($frames);
}

/** 置いてあるファイルのパス。**パスは表の値(ハッシュ)からだけ作る。** */
function km_ar_file_path(string $sha256, string $ext): ?string
{
    if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1 || !in_array($ext, ['jpg', 'png'], true)) {
        return null;
    }
    $path = km_ar_capture_dir() . '/' . $sha256 . '.' . $ext;
    return is_file($path) ? $path : null;
}

// ---------------------------------------------------------------- COLMAP(PC で 3D を作るとき)

/**
 * ARCore のカメラの姿勢(カメラ → 世界。+x 右・+y 上・-z が前)を、COLMAP の画像の姿勢
 * (世界 → カメラ。+x 右・+y 下・+z が前)へ直す。**純粋関数。** 世界の座標は AR の世界のまま(y が上・m)。
 *
 * @param list<float> $t カメラの位置
 * @param list<float> $q 向き(x, y, z, w)
 * @return array{qvec:list<float>, tvec:list<float>} qvec は COLMAP の順(w, x, y, z)
 */
function km_ar_colmap_pose(array $t, array $q): array
{
    [$x, $y, $z, $w] = $q;
    // カメラ → 世界の回転
    $r = [
        [1 - 2 * ($y * $y + $z * $z), 2 * ($x * $y - $z * $w), 2 * ($x * $z + $y * $w)],
        [2 * ($x * $y + $z * $w), 1 - 2 * ($x * $x + $z * $z), 2 * ($y * $z - $x * $w)],
        [2 * ($x * $z - $y * $w), 2 * ($y * $z + $x * $w), 1 - 2 * ($x * $x + $y * $y)],
    ];
    // カメラの y・z を裏返す(OpenGL → COLMAP)。列 1・2 の符号を変える
    for ($i = 0; $i < 3; $i++) {
        $r[$i][1] = -$r[$i][1];
        $r[$i][2] = -$r[$i][2];
    }
    // 世界 → カメラ = 転置。t = -R·C
    $rw = [[$r[0][0], $r[1][0], $r[2][0]], [$r[0][1], $r[1][1], $r[2][1]], [$r[0][2], $r[1][2], $r[2][2]]];
    $tvec = [];
    for ($i = 0; $i < 3; $i++) {
        $tvec[] = -($rw[$i][0] * $t[0] + $rw[$i][1] * $t[1] + $rw[$i][2] * $t[2]);
    }
    return ['qvec' => km_ar_quaternion_from_matrix($rw), 'tvec' => $tvec];
}

/** 回転行列 → 四元数(w, x, y, z)。w ≥ 0 に揃える。 */
function km_ar_quaternion_from_matrix(array $m): array
{
    $trace = $m[0][0] + $m[1][1] + $m[2][2];
    if ($trace > 0) {
        $s = sqrt($trace + 1.0) * 2;
        $q = [0.25 * $s, ($m[2][1] - $m[1][2]) / $s, ($m[0][2] - $m[2][0]) / $s, ($m[1][0] - $m[0][1]) / $s];
    } elseif ($m[0][0] > $m[1][1] && $m[0][0] > $m[2][2]) {
        $s = sqrt(1.0 + $m[0][0] - $m[1][1] - $m[2][2]) * 2;
        $q = [($m[2][1] - $m[1][2]) / $s, 0.25 * $s, ($m[0][1] + $m[1][0]) / $s, ($m[0][2] + $m[2][0]) / $s];
    } elseif ($m[1][1] > $m[2][2]) {
        $s = sqrt(1.0 + $m[1][1] - $m[0][0] - $m[2][2]) * 2;
        $q = [($m[0][2] - $m[2][0]) / $s, ($m[0][1] + $m[1][0]) / $s, 0.25 * $s, ($m[1][2] + $m[2][1]) / $s];
    } else {
        $s = sqrt(1.0 + $m[2][2] - $m[0][0] - $m[1][1]) * 2;
        $q = [($m[1][0] - $m[0][1]) / $s, ($m[0][2] + $m[2][0]) / $s, ($m[1][2] + $m[2][1]) / $s, 0.25 * $s];
    }
    if ($q[0] < 0) {
        $q = array_map(static fn ($v) => -$v, $q);
    }
    return $q;
}

/**
 * COLMAP の「既知の姿勢」のテキスト(cameras.txt・images.txt・points3D.txt)。内部の値が同じ画像は 1 つのカメラにまとめる。
 *
 * @param list<array{name:string, meta:array}> $frames
 * @return array{cameras:string, images:string, points:string}
 */
function km_ar_colmap_text(array $frames): array
{
    $fmt = static fn (float $v): string => rtrim(rtrim(sprintf('%.9F', $v), '0'), '.') ?: '0';
    $cameraIds = [];
    $cameras = "# Camera list with one line of data per camera:\n#   CAMERA_ID, MODEL, WIDTH, HEIGHT, PARAMS[]\n";
    $images = "# Image list with two lines of data per image:\n#   IMAGE_ID, QW, QX, QY, QZ, TX, TY, TZ, CAMERA_ID, NAME\n#   POINTS2D[] as (X, Y, POINT3D_ID)\n";
    $imageId = 0;
    foreach ($frames as $frame) {
        $k = $frame['meta']['intrinsics'];
        $key = implode(' ', [$k['width'], $k['height'], $fmt($k['fx']), $fmt($k['fy']), $fmt($k['cx']), $fmt($k['cy'])]);
        if (!isset($cameraIds[$key])) {
            $cameraIds[$key] = count($cameraIds) + 1;
            $cameras .= $cameraIds[$key] . ' PINHOLE ' . $key . "\n";
        }
        $pose = km_ar_colmap_pose($frame['meta']['pose']['t'], $frame['meta']['pose']['q']);
        $images .= ++$imageId . ' ' . implode(' ', array_map($fmt, array_merge($pose['qvec'], $pose['tvec'])))
            . ' ' . $cameraIds[$key] . ' ' . $frame['name'] . "\n\n";
    }
    return ['cameras' => $cameras, 'images' => $images, 'points' => "# 3D point list (empty: triangulate with known poses)\n"];
}

// ---------------------------------------------------------------- zip(圧縮しない・流しながら書く)

/**
 * 圧縮しない zip を [$write] へ流しながら書く。web の像には zip の拡張が無い(lib/apk-version.php の注記)。
 * 1 つのファイルは 4GB 未満・全体も 4GB 未満(ZIP64 は使わない)。名前は ASCII の相対パスだけ。
 *
 * @param iterable<array{name:string, path?:string, data?:string}> $entries
 * @param callable(string):void $write
 * @return int 書いたバイト数
 */
function km_zip_stream_stored(iterable $entries, callable $write, ?int $nowTimestamp = null): int
{
    $time = getdate($nowTimestamp ?? time());
    $dosTime = ($time['hours'] << 11) | ($time['minutes'] << 5) | intdiv($time['seconds'], 2);
    $dosDate = ((max(1980, $time['year']) - 1980) << 9) | ($time['mon'] << 5) | $time['mday'];
    $offset = 0;
    $central = '';
    $count = 0;
    foreach ($entries as $entry) {
        $name = (string) $entry['name'];
        if (preg_match('#\A[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*\z#', $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidArgumentException('zip の名前が不正です: ' . $name);
        }
        if (isset($entry['path'])) {
            $size = (int) filesize($entry['path']);
            $crc = (int) hexdec((string) hash_file('crc32b', $entry['path']));
        } else {
            $data = (string) ($entry['data'] ?? '');
            $size = strlen($data);
            $crc = (int) hexdec(hash('crc32b', $data));
        }
        if ($offset + $size > 0xFFFFFFFF - 1024 * 1024) {
            throw new RuntimeException('zip が 4GB を超えます。');
        }
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name;
        $write($local);
        if (isset($entry['path'])) {
            $handle = fopen($entry['path'], 'rb');
            if ($handle === false) {
                throw new RuntimeException('読めませんでした: ' . $name);
            }
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $write($chunk);
            }
            fclose($handle);
        } else {
            $write($data);
        }
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local) + $size;
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    $write($central . $end);
    return $offset + strlen($central) + strlen($end);
}

/**
 * 撮影 1 回ぶんの zip の中身(名前とファイル)。画像の番号順。**ファイルのパスは表のハッシュから作る。**
 *
 * @return list<array{name:string, path?:string, data?:string}>
 */
function km_ar_session_zip_entries(PDO $pdo, string $sessionUuid): array
{
    $record = km_ar_session_record($pdo, $sessionUuid);
    if ($record === null) {
        throw new InvalidArgumentException('その撮影はありません。');
    }
    $frames = km_ar_session_frames($pdo, $sessionUuid);
    $entries = [['name' => 'survey.json', 'data' => $record]];
    $colmapFrames = [];
    $framesJson = [];
    foreach ($frames as $frame) {
        $name = sprintf('frame_%04d', $frame['index']);
        $imagePath = km_ar_file_path($frame['imageSha256'], 'jpg');
        if ($imagePath === null) {
            continue; // ファイルが無い(消えた)ものは飛ばす
        }
        $entries[] = ['name' => 'images/' . $name . '.jpg', 'path' => $imagePath];
        if ($frame['depthSha256'] !== null && ($depthPath = km_ar_file_path($frame['depthSha256'], 'png')) !== null) {
            $entries[] = ['name' => 'depth/' . $name . '.png', 'path' => $depthPath];
        }
        $colmapFrames[] = ['name' => $name . '.jpg', 'meta' => $frame['meta']];
        $framesJson[] = ['name' => $name . '.jpg', 'depth' => $frame['depthSha256'] !== null ? $name . '.png' : null] + $frame['meta'];
    }
    $colmap = km_ar_colmap_text($colmapFrames);
    $entries[] = ['name' => 'frames.json', 'data' => json_encode($framesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
    $entries[] = ['name' => 'sparse/0/cameras.txt', 'data' => $colmap['cameras']];
    $entries[] = ['name' => 'sparse/0/images.txt', 'data' => $colmap['images']];
    $entries[] = ['name' => 'sparse/0/points3D.txt', 'data' => $colmap['points']];
    $entries[] = ['name' => 'README.txt', 'data' => km_ar_zip_readme()];
    return $entries;
}

function km_ar_zip_readme(): string
{
    return <<<'TXT'
        KosenMap AR capture (ARCore)
        ============================

        IMPORTANT: the images may show people. Blur faces BEFORE using or sharing them:
          scripts\pc\ar-blur-faces.ps1 -Zip <this zip>
        Delete the capture on the server (admin > AR captures) once it is processed.

        images/frame_NNNN.jpg   camera images (sensor orientation, as read out by ARCore)
        depth/frame_NNNN.png    16-bit depth in millimetres (only on devices with ARCore Depth)
        frames.json             per image: timestampMillis, pose (ARCore camera-to-world: t = position in m,
                                q = x,y,z,w; camera +x right, +y up, -z forward), intrinsics (fx, fy, cx, cy, width, height)
        sparse/0/*.txt          the same poses as a COLMAP text model (world-to-camera, +z forward).
                                World = ARCore world (y up, metres). points3D.txt is empty:
                                colmap feature_extractor / exhaustive_matcher, then
                                colmap point_triangulator --input_path sparse/0 (known poses), then dense reconstruction.
        survey.json             the AR survey record (track, marks on map nodes, depth hits)

        RealityScan / RealityCapture: import images/ ; the poses in frames.json can be used as priors.
        Blender: import the mesh exported from RealityScan / COLMAP.
        TXT;
}
