<?php

declare(strict_types=1);

/**
 * アプリへ配る地図の作り置き(2026-10-06。2026-10-05 の計画 E2「サーバーのメモリを減らす」)。
 *
 * api/app-map.php は以前、**取りに来るたびに**地図のファイル(数百 KB〜数 MB)を読み解き、段に合わせて落とし、
 * 組み直してハッシュを取っていた。検証機の実測(2026-10-06、地点 1037)で 1 回あたり約 16ms・**+5.5MB**。
 * 文化祭のように一斉に取りに来ると、Apache のプロセスごとにこれが積み上がる。
 *
 * そこで、**中身の段(来場者・スタッフ・氏名入り)ごとに配る地図の JSON を 1 度だけ作ってファイルに置き**、
 * 応答では前後の短い部分だけを組み立てて、地図の本体は readfile で流す(実測 約 1ms・+0.5MB)。
 *
 * - 置き場は cache/app-map/(web が書ける。nginx は /cache/ を 404 にしている)
 * - 名前は「元のファイルのパス・更新時刻・大きさ・段・この作りの版」のハッシュ。**元を差し替えれば名前が変わる**ので、
 *   古い作り置きを誤って配ることがない(消し忘れても使われない。同じ配信の古いものは作るときに片付ける)
 * - 本体と一緒に、チェックサム(本体の sha256)と、写真の一覧を絞るための地点の uuid を覚える
 * - 作れないとき(置き場に書けない等)は、今までどおりその場で組み立てる(呼ぶ側の落とし先)
 */

require_once __DIR__ . '/app-map.php';

/** 落とし方(km_app_map_strip_*)や JSON の書き方を変えたら上げる。古い作り置きが使われなくなる(2: 2026-10-22 段を作り直した) */
const KM_APP_MAP_CACHE_FORMAT = 2;

function km_app_map_cache_dir(): string
{
    return __DIR__ . '/../cache/app-map';
}

/**
 * 段に合わせて落とした地図。**引数の $map を書き換える**(呼ぶ側で受け直す)。段の表は lib/app-map.php の KM_APP_MAP_CONTENT_LEVELS。
 * **知らない段は一番狭い public と同じに落とす**(閉じる側に倒す)。
 *
 * @param list<string> $consentedUuids 先生が許可した地点。member-<12 桁> の段で、氏名を残す地点
 */
function km_app_map_for_level(stdClass $map, string $level, array $consentedUuids = []): stdClass
{
    switch (km_app_map_content_level_base($level)) {
        case 'editor':
            if ($level !== 'editor-names') {
                km_app_map_strip_occupant_names($map);
            }
            return $map;
        case 'member':
            if ($level === 'member') {
                km_app_map_strip_occupant_names($map);
            } else {
                km_app_map_keep_occupant_names_for($map, $consentedUuids);
            }
            return $map;
        case 'event':
            km_app_map_strip_occupant_names($map);
            km_app_map_strip_restricted_nodes($map);
            return $map;
        default:
            $map = km_app_map_strip_staff_only($map);
            km_app_map_strip_restricted_nodes($map);
            return $map;
    }
}

/**
 * 作り置きを返す(無ければ作る)。作れなければ null(呼ぶ側はその場で組み立てる)。
 *
 * @param list<string> $consentedUuids km_app_map_for_level に渡す(段の名前に許可の組の印が入っているので、組が変われば別の作り置きになる)
 * @return array{body:string, sha256:string, uuids:array<string,true>}|null body は本体のファイルのパス
 */
function km_app_map_cache_get(string $slug, string $sourcePath, string $level, ?string $dir = null, array $consentedUuids = []): ?array
{
    $levelBase = km_app_map_content_level_base($level);
    if ($levelBase === null || preg_match('/\A[a-z0-9_-]{1,64}\z/', $slug) !== 1) {
        return null;
    }
    clearstatcache(true, $sourcePath);
    $mtime = @filemtime($sourcePath);
    $size = @filesize($sourcePath);
    if ($mtime === false || $size === false) {
        return null;
    }
    $key = substr(hash('sha256', implode("\n", [$sourcePath, $mtime, $size, $level, KM_APP_MAP_CACHE_FORMAT])), 0, 24);
    $dir ??= km_app_map_cache_dir();
    $base = "{$dir}/{$slug}-{$level}-{$key}";

    $meta = km_app_map_cache_read_meta("{$base}.meta.json", "{$base}.json");
    if ($meta !== null) {
        return $meta;
    }

    // 作る。**同時に作りに来ても壊れない**よう、一時ファイルに書いてから名前を変える
    $map = km_app_map_decode_snapshot((string) @file_get_contents($sourcePath));
    if ($map === null) {
        return null;
    }
    $map = km_app_map_for_level($map, $level, $consentedUuids);
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return null;
    }
    $uuids = [];
    foreach ((array) ($map->nodes ?? []) as $node) {
        if (is_object($node) && is_string($node->uuid ?? null)) {
            $uuids[] = $node->uuid;
        }
    }
    unset($map);

    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('km_app_map_cache_get: 置き場を作れません: ' . $dir);
        return null;
    }
    $sha = hash('sha256', $json);
    $tmp = $base . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $metaJson = json_encode(['format' => KM_APP_MAP_CACHE_FORMAT, 'sha256' => $sha, 'bytes' => strlen($json), 'uuids' => $uuids], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, "{$base}.json")
        || !is_string($metaJson) || @file_put_contents("{$tmp}m", $metaJson) !== strlen($metaJson) || !@rename("{$tmp}m", "{$base}.meta.json")) {
        @unlink($tmp);
        @unlink("{$tmp}m");
        error_log('km_app_map_cache_get: 書き込めません: ' . $base);
        return null;
    }

    // 同じ配信・同じ区分の段の古い作り置きを片付ける(元が差し替わった分と、許可の組が変わる前の分)
    foreach (glob("{$dir}/{$slug}-{$levelBase}-*") ?: [] as $old) {
        if (!str_starts_with($old, $base . '.')) {
            @unlink($old);
        }
    }

    return ['body' => "{$base}.json", 'sha256' => $sha, 'uuids' => array_fill_keys($uuids, true)];
}

/** @return array{body:string, sha256:string, uuids:array<string,true>}|null */
function km_app_map_cache_read_meta(string $metaPath, string $bodyPath): ?array
{
    if (!is_file($metaPath) || !is_file($bodyPath)) {
        return null;
    }
    $meta = json_decode((string) @file_get_contents($metaPath), true);
    if (!is_array($meta) || ($meta['format'] ?? null) !== KM_APP_MAP_CACHE_FORMAT
        || !is_string($meta['sha256'] ?? null) || !is_array($meta['uuids'] ?? null)
        || (int) ($meta['bytes'] ?? -1) !== (int) @filesize($bodyPath)) {
        return null;
    }
    return ['body' => $bodyPath, 'sha256' => $meta['sha256'], 'uuids' => array_fill_keys(array_map('strval', $meta['uuids']), true)];
}
