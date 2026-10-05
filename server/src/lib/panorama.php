<?php

declare(strict_types=1);

/**
 * ストリートビューの写真(2026-10-06。2026-10-05 の計画 D2、利用者の指示「画像の保存場所は自鯖」)。
 *
 * - 地点(ノードの uuid)ごとに 360° の写真(正距円筒。横:縦 = 2:1 がふつう)を何枚か持つ
 * - 置き場は uploads/panoramas/<sha256>.<jpg|webp>。**名前は中身のハッシュ** —— 同じ写真は 1 つにまとまり、
 *   取りに来る口(api/panorama.php?h=…)は**ハッシュを知っている人だけ**が使える(地図の配信で一覧を受け取った人)
 * - 地図の配信(api/app-map.php)には、配る地図に入っている地点の分だけ一覧を載せる
 *   (閲覧不可の地点を落とした来場者版には、その地点の写真も載らない)。**チェックサムの外**(routeWeights と同じ)
 * - 上げる・消すは管理者だけ(api/panorama.php)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';

/** 1 枚の上限。nginx の client_max_body_size と揃える */
const KM_PANORAMA_MAX_BYTES = 10 * 1024 * 1024;
/** 1 地点に置ける枚数 */
const KM_PANORAMA_MAX_PER_NODE = 8;
/** 受け付ける形(getimagesize の IMAGETYPE)と拡張子 */
const KM_PANORAMA_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

function km_panorama_dir(): string
{
    return km_upload_dir() . '/panoramas';
}

function km_panorama_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_node_panoramas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_uuid VARCHAR(64) NOT NULL,
            seq INT NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL,
            ext VARCHAR(8) NOT NULL,
            width INT UNSIGNED NOT NULL,
            height INT UNSIGNED NOT NULL,
            heading FLOAT NULL,
            bytes INT UNSIGNED NOT NULL,
            created_by VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            INDEX (node_uuid),
            INDEX (sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

/** 地点の uuid の形。地図の uuid は英数字とハイフン(アプリが作る UUID・Web の連番の文字列) */
function km_panorama_valid_node_uuid(string $uuid): bool
{
    return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $uuid) === 1;
}

/**
 * 写真の中身を確かめる。**拡張子や Content-Type は信じず、中身を見る**(getimagesizefromstring)。
 *
 * @return array{type:int, width:int, height:int, ext:string}
 */
function km_panorama_inspect(string $bytes): array
{
    if ($bytes === '' || strlen($bytes) > KM_PANORAMA_MAX_BYTES) {
        throw new InvalidArgumentException('写真の大きさが範囲外です(10MB まで)。');
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || !isset(KM_PANORAMA_TYPES[$info[2]])) {
        throw new InvalidArgumentException('JPEG か WebP の写真を選んでください。');
    }
    [$width, $height] = [(int) $info[0], (int) $info[1]];
    if ($width < 256 || $height < 128 || $width > 16384 || $height > 8192) {
        throw new InvalidArgumentException('写真の縦横の大きさが範囲外です(横 256〜16384・縦 128〜8192)。');
    }
    return ['type' => (int) $info[2], 'width' => $width, 'height' => $height, 'ext' => KM_PANORAMA_TYPES[$info[2]]];
}

/**
 * 1 枚足す。同じ写真(同じハッシュ)が同じ地点にあれば、足さずにそれを返す。
 *
 * @return array{id:int, sha256:string, bytes:int, width:int, height:int, heading:?float}
 */
function km_panorama_add(PDO $pdo, string $nodeUuid, string $bytes, ?float $heading, string $createdBy): array
{
    km_panorama_ensure_table($pdo);
    if (!km_panorama_valid_node_uuid($nodeUuid)) {
        throw new InvalidArgumentException('地点の指定が不正です。');
    }
    if ($heading !== null && (!is_finite($heading) || $heading < 0 || $heading >= 360)) {
        throw new InvalidArgumentException('向きは 0 以上 360 未満で指定してください。');
    }
    $info = km_panorama_inspect($bytes);
    $sha = hash('sha256', $bytes);

    $existing = $pdo->prepare('SELECT id, sha256, bytes, width, height, heading FROM km_node_panoramas WHERE node_uuid = ? AND sha256 = ?');
    $existing->execute([$nodeUuid, $sha]);
    $row = $existing->fetch();
    if (is_array($row)) {
        return km_panorama_public_row($row);
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM km_node_panoramas WHERE node_uuid = ?');
    $count->execute([$nodeUuid]);
    if ((int) $count->fetchColumn() >= KM_PANORAMA_MAX_PER_NODE) {
        throw new InvalidArgumentException('1 つの地点に置ける写真は ' . KM_PANORAMA_MAX_PER_NODE . ' 枚までです。');
    }

    $dir = km_panorama_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('置き場を作れませんでした: ' . $dir);
    }
    $path = $dir . '/' . $sha . '.' . $info['ext'];
    if (!is_file($path) && @file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($path);
        throw new RuntimeException('書き込めませんでした。');
    }

    $next = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM km_node_panoramas WHERE node_uuid = ?');
    $next->execute([$nodeUuid]);
    $pdo->prepare(
        'INSERT INTO km_node_panoramas (node_uuid, seq, sha256, ext, width, height, heading, bytes, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$nodeUuid, (int) $next->fetchColumn(), $sha, $info['ext'], $info['width'], $info['height'], $heading, strlen($bytes), mb_substr($createdBy, 0, 255)]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'sha256' => $sha,
        'bytes' => strlen($bytes),
        'width' => $info['width'],
        'height' => $info['height'],
        'heading' => $heading,
    ];
}

/** @return array{id:int, sha256:string, bytes:int, width:int, height:int, heading:?float} */
function km_panorama_public_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'sha256' => (string) $row['sha256'],
        'bytes' => (int) $row['bytes'],
        'width' => (int) $row['width'],
        'height' => (int) $row['height'],
        'heading' => $row['heading'] === null ? null : round((float) $row['heading'], 1),
    ];
}

/**
 * 配信に載せる一覧。`{nodeUuid: [{id, sha256, bytes, width, height, heading}, …]}`。
 *
 * @param array<string, true>|null $allowedUuids 配る地図に入っている地点。null なら絞らない(管理者向け)
 * @return array<string, list<array>>
 */
function km_panorama_manifest(PDO $pdo, ?array $allowedUuids): array
{
    km_panorama_ensure_table($pdo);
    $manifest = [];
    foreach ($pdo->query('SELECT id, node_uuid, sha256, bytes, width, height, heading FROM km_node_panoramas ORDER BY node_uuid, seq, id') as $row) {
        $uuid = (string) $row['node_uuid'];
        if ($allowedUuids !== null && !isset($allowedUuids[$uuid])) {
            continue;
        }
        $manifest[$uuid][] = km_panorama_public_row($row);
    }
    return $manifest;
}

/** 写真が 1 枚でもあるか(無ければ配信の側で地図を読み直さずに済む)。 */
function km_panorama_any(PDO $pdo): bool
{
    km_panorama_ensure_table($pdo);
    return (bool) $pdo->query('SELECT 1 FROM km_node_panoramas LIMIT 1')->fetchColumn();
}

/** 配る地図(stdClass。nodes の配列)に入っている地点の uuid。 */
function km_panorama_uuids_of_map(stdClass $map): array
{
    $uuids = [];
    foreach ((array) ($map->nodes ?? []) as $node) {
        if (is_object($node) && is_string($node->uuid ?? null)) {
            $uuids[$node->uuid] = true;
        }
    }
    return $uuids;
}

/**
 * ハッシュから写真を探す。**中身のファイルのパスは表からだけ作る**(利用者の入力をパスに使わない)。
 *
 * @return array{path:string, ext:string, bytes:int}|null
 */
function km_panorama_find_file(PDO $pdo, string $sha256): ?array
{
    if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
        return null;
    }
    km_panorama_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT sha256, ext, bytes FROM km_node_panoramas WHERE sha256 = ? LIMIT 1');
    $stmt->execute([$sha256]);
    $row = $stmt->fetch();
    if (!is_array($row) || !in_array($row['ext'], KM_PANORAMA_TYPES, true)) {
        return null;
    }
    $path = km_panorama_dir() . '/' . $row['sha256'] . '.' . $row['ext'];
    return is_file($path) ? ['path' => $path, 'ext' => (string) $row['ext'], 'bytes' => (int) $row['bytes']] : null;
}

/** 1 枚外す。ほかの地点が同じ写真を使っていなければ、ファイルも消す。 */
function km_panorama_delete(PDO $pdo, int $id): ?array
{
    km_panorama_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT id, node_uuid, sha256, ext FROM km_node_panoramas WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $pdo->prepare('DELETE FROM km_node_panoramas WHERE id = ?')->execute([$id]);
    $left = $pdo->prepare('SELECT COUNT(*) FROM km_node_panoramas WHERE sha256 = ?');
    $left->execute([$row['sha256']]);
    if ((int) $left->fetchColumn() === 0 && in_array($row['ext'], KM_PANORAMA_TYPES, true)) {
        @unlink(km_panorama_dir() . '/' . $row['sha256'] . '.' . $row['ext']);
    }
    return ['id' => (int) $row['id'], 'nodeUuid' => (string) $row['node_uuid']];
}
