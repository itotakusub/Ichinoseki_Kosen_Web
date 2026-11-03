<?php

declare(strict_types=1);

/**
 * 会期のあとの片付け: ポスター・広告を**控え(zip)へ移してから消す**(2026-11-03、利用者の指示
 * 「会期が終了したら、ポスター(画像も)をバックアップに移して消してください」)。
 *
 * 利用者が決めたこと: 押したときだけ(自動では消さない)・広告も止めているものも全部・控えはダウンロードだけ(戻すボタンは無い)。
 *
 * - 控えは uploads/poster-archives/posters-<日時>-<16 進 32 字>.zip。表は km_poster_archives
 *   (uploads/ の下なので、夜の暗号化した控え scripts/host-backup.sh にも入る。nginx は /uploads/ を直に出さない)
 * - zip の中身: posters.json(全部の列・企画の番号・日ごとの数・♡ の数)・images/<番号>-<保存名>(元の画像そのもの)・README.txt
 * - **書いて、読み戻して確かめてから消す**(lib/learning-backup.php と同じ考え)。書けない・合わないときは何も消さない
 * - 確かめは**少しずつ読む**(km_zip_read_all は全部をメモリに載せる。画像は 1 枚 8MB まであり、web のメモリは 384MB)
 * - web の像には zip の拡張が無いので、書くのは lib/ar-capture.php の km_zip_stream_stored(圧縮しない zip)
 * - 自動では消さない(何回分残すかは決めていない)。管理画面「バックアップ」で手で消す
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/posters.php';
require_once __DIR__ . '/ar-capture.php';   // km_zip_stream_stored

const KM_POSTER_ARCHIVE_FORMAT = 'kosenmap-poster-archive';
const KM_POSTER_ARCHIVE_NAME_PATTERN = '/\Aposters-[0-9]{8}-[0-9]{6}-[0-9a-f]{32}\.zip\z/';

function km_poster_archive_dir(): string
{
    return km_upload_dir() . '/poster-archives';
}

function km_poster_archive_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_poster_archives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            label VARCHAR(255) NOT NULL,
            poster_count INT UNSIGNED NOT NULL,
            image_count INT UNSIGNED NOT NULL,
            bytes BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            stored_name VARCHAR(96) NOT NULL,
            created_by VARCHAR(191) NULL,
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $ready = true;
}

/**
 * zip に入れるもの。**DB を使わない**(scripts/check.php が作業用の場所で試す)。
 * 画像が無い・保存名がおかしいポスターは、posters.json の image を null にして missing に名前を残す(行は控えに入れる)。
 *
 * @param list<array<string, mixed>> $rows km_posters の行(列名のまま)
 * @param array<int, string> $projects poster_id => 企画の番号
 * @param array<int, list<array{day:string, impressions:int, clicks:int}>> $stats
 * @param array<int, int> $likes poster_id => ♡ の数
 * @return array{entries: list<array{name:string, path?:string, data?:string}>, images:int, imageBytes:int, missing:list<string>}
 */
function km_poster_archive_entries(array $rows, array $projects, array $stats, array $likes, string $label, string $by, int $now, string $uploadDir): array
{
    $posters = [];
    $images = [];
    $imageBytes = 0;
    $missing = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $stored = (string) ($row['image_stored_name'] ?? '');
        $path = $uploadDir . DIRECTORY_SEPARATOR . $stored;
        $imageName = null;
        if (km_poster_stored_name_valid($stored) && is_file($path)) {
            $imageName = 'images/' . $id . '-' . $stored;
            $images[] = ['name' => $imageName, 'path' => $path];
            $imageBytes += (int) filesize($path);
        } else {
            $missing[] = '#' . $id . ' ' . (string) ($row['title'] ?? '');
        }
        $posters[] = $row + [
            'project_no' => $projects[$id] ?? null,
            'likes' => $likes[$id] ?? 0,
            'stats' => $stats[$id] ?? [],
            'image' => $imageName,
        ];
    }
    $json = json_encode([
        'format' => KM_POSTER_ARCHIVE_FORMAT,
        'formatVersion' => 1,
        'createdAt' => date(DATE_ATOM, $now),
        'label' => $label,
        'createdBy' => $by,
        'posterCount' => count($posters),
        'posters' => $posters,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $readme = implode("\r\n", [
        'KosenMap のポスター・広告の控え(会期の終わりの片付け)',
        '',
        '作った日時: ' . date('Y-m-d H:i:s', $now),
        '会期: ' . ($label === '' ? '(名前なし)' : $label),
        '作った人: ' . ($by === '' ? '(不明)' : $by),
        'ポスター・広告: ' . count($posters) . ' 件 / 画像: ' . count($images) . ' 枚',
        '',
        'posters.json  … 全部の項目(見出し・説明・URL・地点・企画の番号・並び順・出す/止める・日ごとの表示とリンクの数・♡ の数)',
        'images/       … 元の画像そのもの(<番号>-<保存名>)。posters.json の image がこの名前',
        '',
        'この控えから画面で戻す機能はありません。戻すときは、管理画面「ポスター・広告」で画像と項目を入れ直してください。',
        '',
    ]);

    return [
        'entries' => [['name' => 'README.txt', 'data' => $readme], ['name' => 'posters.json', 'data' => $json], ...$images],
        'images' => count($images),
        'imageBytes' => $imageBytes,
        'missing' => $missing,
    ];
}

/**
 * zip を $path に書く(無い名前にだけ書く)。戻り値は大きさと sha256。
 *
 * @param list<array{name:string, path?:string, data?:string}> $entries
 * @return array{bytes:int, sha256:string}
 */
function km_poster_archive_write(array $entries, string $path, ?int $now = null): array
{
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        throw new RuntimeException('控えのファイルを作れませんでした(uploads/poster-archives に書けるかを確かめてください)。');
    }
    try {
        km_zip_stream_stored($entries, static function (string $bytes) use ($handle): void {
            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('控えのファイルを書けませんでした(空きが足りない?)。');
            }
        }, $now);
        if (!fflush($handle)) {
            throw new RuntimeException('控えのファイルを書けませんでした。');
        }
    } finally {
        fclose($handle);
    }
    clearstatcache(true, $path);

    return ['bytes' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
}

/**
 * 書いた zip を読み戻して、入れたものと合うかを確かめる。**少しずつ読む**(画像を丸ごとメモリに載せない)。
 * 名前・順番・大きさ・CRC(元のファイルと zip の中の両方)を見る。合わなければ例外。
 *
 * @param list<array{name:string, path?:string, data?:string}> $entries
 */
function km_poster_archive_verify(string $path, array $entries): void
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('書いた控えを読めませんでした。');
    }
    try {
        $size = (int) fstat($handle)['size'];
        if ($size < 22) {
            throw new RuntimeException('書いた控えが短すぎます。');
        }
        fseek($handle, $size - 22);
        $end = (string) fread($handle, 22);
        $e = unpack('Vsig/vdisk/vcdDisk/vcountDisk/vcount/VcdSize/VcdOffset', $end);
        if (!is_array($e) || $e['sig'] !== 0x06054b50 || $e['count'] !== count($entries) || $e['cdOffset'] + $e['cdSize'] + 22 !== $size) {
            throw new RuntimeException('書いた控えの数が合いません。');
        }
        fseek($handle, $e['cdOffset']);
        $cd = (string) fread($handle, $e['cdSize']);
        $pos = 0;
        foreach ($entries as $entry) {
            $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset', substr($cd, $pos, 46));
            if (!is_array($h) || $h['sig'] !== 0x02014b50) {
                throw new RuntimeException('書いた控えが壊れています。');
            }
            $name = substr($cd, $pos + 46, $h['nameLen']);
            $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
            if ($name !== $entry['name'] || $h['method'] !== 0 || $h['comp'] !== $h['uncomp']) {
                throw new RuntimeException('書いた控えの中身が合いません(' . $entry['name'] . ')。');
            }
            // 元のほう(画像のファイルか、文字のデータ)
            if (isset($entry['path'])) {
                $sourceSize = (int) filesize($entry['path']);
                $sourceCrc = (string) hash_file('crc32b', $entry['path']);
            } else {
                $sourceSize = strlen((string) $entry['data']);
                $sourceCrc = hash('crc32b', (string) $entry['data']);
            }
            if ($h['uncomp'] !== $sourceSize || sprintf('%08x', $h['crc']) !== $sourceCrc) {
                throw new RuntimeException('書いた控えの大きさか CRC が元と合いません(' . $entry['name'] . ')。');
            }
            // zip の中のほう(少しずつ読んで CRC を取る)
            fseek($handle, $h['offset']);
            $local = (string) fread($handle, 30);
            $l = strlen($local) === 30 && substr($local, 0, 4) === "PK\x03\x04" ? unpack('vnameLen/vextraLen', substr($local, 26, 4)) : false;
            if (!is_array($l)) {
                throw new RuntimeException('書いた控えが壊れています(' . $entry['name'] . ')。');
            }
            fseek($handle, $h['offset'] + 30 + $l['nameLen'] + $l['extraLen']);
            $crc = hash_init('crc32b');
            $left = $h['comp'];
            while ($left > 0) {
                $chunk = fread($handle, min($left, 1024 * 1024));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('書いた控えが途中で切れています(' . $entry['name'] . ')。');
                }
                hash_update($crc, $chunk);
                $left -= strlen($chunk);
            }
            if (hash_final($crc) !== $sourceCrc) {
                throw new RuntimeException('書いた控えの中の CRC が合いません(' . $entry['name'] . ')。');
            }
        }
    } finally {
        fclose($handle);
    }
}

/**
 * いま消すとどうなるかの見込み(画面に出す件数と画像の大きさ)。表が無ければ 0。
 *
 * @return array{count:int, images:int, bytes:int}
 */
function km_poster_archive_preview(PDO $pdo): array
{
    try {
        $names = $pdo->query('SELECT image_stored_name FROM km_posters')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $exception) {
        return ['count' => 0, 'images' => 0, 'bytes' => 0];
    }
    $images = 0;
    $bytes = 0;
    foreach ($names as $stored) {
        $path = km_upload_dir() . DIRECTORY_SEPARATOR . (string) $stored;
        if (km_poster_stored_name_valid((string) $stored) && is_file($path)) {
            $images++;
            $bytes += (int) filesize($path);
        }
    }

    return ['count' => count($names), 'images' => $images, 'bytes' => $bytes];
}

/**
 * ポスター・広告を全部、控えへ移して消す。ポスターが 0 件なら何もしない(null)。
 * **控えを書いて確かめられたときだけ消す。** 消すのは控えに入れた番号だけ(押している間に足されたものは残る)。
 *
 * @return array{id:int, storedName:string, count:int, images:int, bytes:int, missing:list<string>}|null
 */
function km_poster_archive_and_clear(PDO $pdo, string $label, ?string $by): ?array
{
    km_posters_ensure_tables($pdo);
    km_poster_archive_ensure_table($pdo);
    // 消すときの表は、トランザクションの前に作っておく(CREATE は MariaDB では途中で確定してしまう)
    km_poster_stats_ensure_table($pdo);
    require_once __DIR__ . '/poster-likes.php';
    km_poster_likes_ensure_table($pdo);

    $rows = $pdo->query('SELECT * FROM km_posters ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return null;
    }
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $stats = [];
    foreach ($pdo->query('SELECT poster_id, day, impressions, clicks FROM km_poster_stats ORDER BY poster_id, day')->fetchAll(PDO::FETCH_ASSOC) as $stat) {
        $stats[(int) $stat['poster_id']][] = ['day' => (string) $stat['day'], 'impressions' => (int) $stat['impressions'], 'clicks' => (int) $stat['clicks']];
    }
    $now = time();
    $plan = km_poster_archive_entries($rows, km_poster_projects_map($pdo), $stats, km_poster_like_counts($pdo), $label, (string) $by, $now, km_upload_dir());

    $dir = km_poster_archive_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('控えの置き場(uploads/poster-archives)を作れませんでした。');
    }
    $storedName = 'posters-' . date('Ymd-His', $now) . '-' . bin2hex(random_bytes(16)) . '.zip';
    $temporary = $dir . '/.writing-' . $storedName;
    try {
        $written = km_poster_archive_write($plan['entries'], $temporary, $now);
        km_poster_archive_verify($temporary, $plan['entries']);
        if (!@rename($temporary, $dir . '/' . $storedName)) {
            throw new RuntimeException('控えのファイルの名前を変えられませんでした。');
        }
    } catch (Throwable $exception) {
        @unlink($temporary);
        throw $exception;
    }

    $pdo->prepare(
        'INSERT INTO km_poster_archives (created_at, label, poster_count, image_count, bytes, sha256, stored_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([date('Y-m-d H:i:s', $now), mb_substr($label, 0, 255), count($rows), $plan['images'], $written['bytes'], $written['sha256'], $storedName, $by === null ? null : mb_substr($by, 0, 191)]);
    $archiveId = (int) $pdo->lastInsertId();

    // 控えができたので消す。行は 1 つのトランザクションで(途中で止まっても半端に残さない)、ファイルはそのあと
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        foreach (['km_posters' => 'id', 'km_poster_projects' => 'poster_id', 'km_poster_stats' => 'poster_id', 'km_poster_likes' => 'poster_id'] as $table => $column) {
            $pdo->prepare("DELETE FROM {$table} WHERE {$column} IN ({$marks})")->execute($ids);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    foreach ($rows as $row) {
        $stored = (string) $row['image_stored_name'];
        if (km_poster_stored_name_valid($stored)) {
            km_poster_archive_remove_thumbs(km_upload_dir() . DIRECTORY_SEPARATOR . $stored);
            km_poster_remove_image($stored);
        }
    }

    return ['id' => $archiveId, 'storedName' => $storedName, 'count' => count($rows), 'images' => $plan['images'], 'bytes' => $written['bytes'], 'missing' => $plan['missing']];
}

/** その画像から作った縮小版(cache/image-thumb)を消す。名前は lib/image-thumb.php と同じ作り方 */
function km_poster_archive_remove_thumbs(string $imagePath): void
{
    if (!is_file($imagePath)) {
        return;
    }
    require_once __DIR__ . '/image-thumb.php';
    foreach (KM_IMAGE_THUMB_WIDTHS as $width) {
        $thumb = km_image_thumb_cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $imagePath . '|' . (string) filemtime($imagePath) . '|' . (string) filesize($imagePath) . '|' . $width) . '.webp';
        if (is_file($thumb)) {
            @unlink($thumb);
        }
    }
}

/**
 * 控えの一覧(新しい順)。**表を作らずに読む**(表は最初に片付けたときに作る。無ければ空)。
 *
 * @return list<array{id:int, createdAt:string, label:string, posterCount:int, imageCount:int, bytes:int, storedName:string, createdBy:string}>
 */
function km_poster_archives_list(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT id, created_at, label, poster_count, image_count, bytes, stored_name, created_by FROM km_poster_archives ORDER BY created_at DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        return [];
    }

    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'createdAt' => (string) $row['created_at'],
        'label' => (string) $row['label'],
        'posterCount' => (int) $row['poster_count'],
        'imageCount' => (int) $row['image_count'],
        'bytes' => (int) $row['bytes'],
        'storedName' => (string) $row['stored_name'],
        'createdBy' => (string) ($row['created_by'] ?? ''),
    ], $rows);
}

/** @return array{id:int, createdAt:string, label:string, posterCount:int, imageCount:int, bytes:int, storedName:string, createdBy:string}|null */
function km_poster_archive_find(PDO $pdo, int $id): ?array
{
    foreach (km_poster_archives_list($pdo) as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }

    return null;
}

/** 控えのファイルのパス。**名前の形を確かめる**(表が汚れていても uploads の外へ出さない) */
function km_poster_archive_path(array $row): string
{
    $name = (string) ($row['storedName'] ?? '');
    if (preg_match(KM_POSTER_ARCHIVE_NAME_PATTERN, $name) !== 1) {
        throw new RuntimeException('控えのファイル名が不正です。');
    }

    return km_poster_archive_dir() . '/' . $name;
}

/** 控えを消す(ファイルと行)。消した控えの名前を返す */
function km_poster_archive_delete(PDO $pdo, int $id): string
{
    $row = km_poster_archive_find($pdo, $id);
    if ($row === null) {
        throw new InvalidArgumentException('その控えはありません(もう消されたかもしれません)。');
    }
    $path = km_poster_archive_path($row);
    if (is_file($path) && !@unlink($path)) {
        throw new RuntimeException('控えのファイルを消せませんでした。');
    }
    $pdo->prepare('DELETE FROM km_poster_archives WHERE id = ?')->execute([$id]);

    return $row['storedName'];
}
