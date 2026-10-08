<?php

declare(strict_types=1);

/**
 * APK の中から版番号(versionCode)を読む(2026-10-06、診断の「情報」)。
 *
 * 配布物の版番号は、以前は管理者の手入力だけだった。実際より大きく入れると、端末は毎日「更新あり」と出すが
 * 入れられない(端末の AppUpdater は APK の中の版番号で確かめて断る)。**置くときに APK から読み、入力と照らす。**
 *
 * - web には zip 拡張が無いので、zip の中央ディレクトリから AndroidManifest.xml だけを探して取り出す
 *   (APK は 200MB 近くになりうるので、**全体は読まない**。fseek で要る所だけ読む)
 * - AndroidManifest.xml は Android のバイナリ XML(AXML)。最初の要素(manifest)の属性から versionCode を拾う
 *   (名前の文字列が "versionCode"、または属性の資源 ID が android:versionCode = 0x0101021b)
 * - 読めなければ null(呼ぶ側は手入力に落とす。読めないことを理由に置けなくはしない)
 */

const KM_APK_ATTR_VERSION_CODE = 0x0101021b;
/** 取り出す AndroidManifest.xml の上限(ふつうは数十 KB) */
const KM_APK_MANIFEST_MAX_BYTES = 4 * 1024 * 1024;

function km_apk_version_code(string $path): ?int
{
    $manifest = km_apk_read_entry($path, 'AndroidManifest.xml');
    return $manifest === null ? null : km_axml_version_code($manifest);
}

/** zip の中の 1 つのファイルを取り出す(格納か deflate だけ)。見つからない・壊れていれば null。 */
function km_apk_read_entry(string $path, string $name): ?string
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    try {
        $size = (int) fstat($handle)['size'];
        // 終わりの記録(EOCD)を後ろから探す。コメントは最大 65535 バイト
        $tailLength = min($size, 65535 + 22);
        fseek($handle, $size - $tailLength);
        $tail = (string) fread($handle, $tailLength);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) - $eocd < 22) {
            return null;
        }
        $e = unpack('vdisk/vcdDisk/vcountDisk/vcount/VcdSize/VcdOffset', substr($tail, $eocd + 4, 16));
        if (!is_array($e) || $e['cdSize'] > 64 * 1024 * 1024 || $e['cdOffset'] + $e['cdSize'] > $size) {
            return null;
        }
        fseek($handle, $e['cdOffset']);
        $cd = (string) fread($handle, $e['cdSize']);
        $pos = 0;
        while ($pos + 46 <= strlen($cd) && substr($cd, $pos, 4) === "PK\x01\x02") {
            $h = unpack('vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset', substr($cd, $pos + 10, 36));
            if (!is_array($h)) {
                return null;
            }
            $entryName = substr($cd, $pos + 46, $h['nameLen']);
            if ($entryName === $name) {
                if ($h['uncomp'] > KM_APK_MANIFEST_MAX_BYTES || $h['comp'] > KM_APK_MANIFEST_MAX_BYTES) {
                    return null;
                }
                fseek($handle, $h['offset']);
                $local = (string) fread($handle, 30);
                if (substr($local, 0, 4) !== "PK\x03\x04") {
                    return null;
                }
                $l = unpack('vnameLen/vextraLen', substr($local, 26, 4));
                fseek($handle, $h['offset'] + 30 + $l['nameLen'] + $l['extraLen']);
                $data = $h['comp'] > 0 ? (string) fread($handle, $h['comp']) : '';
                if ($h['method'] === 0) {
                    $out = $data;
                } elseif ($h['method'] === 8) {
                    $out = @gzinflate($data, KM_APK_MANIFEST_MAX_BYTES);
                } else {
                    return null;
                }
                return is_string($out) && strlen($out) === $h['uncomp'] ? $out : null;
            }
            $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }
        return null;
    } finally {
        fclose($handle);
    }
}

/** バイナリ XML(AXML)の最初の要素から versionCode を読む。無ければ null。 */
function km_axml_version_code(string $xml): ?int
{
    $length = strlen($xml);
    if ($length < 8 || unpack('v', $xml, 0)[1] !== 0x0003) {
        return null;
    }
    $strings = [];
    $resourceIds = [];
    $pos = (int) unpack('v', $xml, 2)[1];   // 先頭の見出しの大きさ
    while ($pos + 8 <= $length) {
        $type = unpack('v', $xml, $pos)[1];
        $headerSize = unpack('v', $xml, $pos + 2)[1];
        $chunkSize = unpack('V', $xml, $pos + 4)[1];
        if ($chunkSize < 8 || $pos + $chunkSize > $length) {
            return null;
        }
        if ($type === 0x0001) {
            $strings = km_axml_string_pool(substr($xml, $pos, $chunkSize));
        } elseif ($type === 0x0180) {
            $resourceIds = array_values(unpack('V*', substr($xml, $pos + 8, $chunkSize - 8)) ?: []);
        } elseif ($type === 0x0102) {
            // 要素の始まり。最初のもの(manifest)だけを見る
            $base = $pos + $headerSize;
            // 拡張部: 名前空間(4)・名前(4)・属性の始まり(2)・属性 1 つの大きさ(2)・属性の数(2)…
            $attrStart = unpack('v', $xml, $base + 8)[1];
            $attrSize = unpack('v', $xml, $base + 10)[1];
            $attrCount = unpack('v', $xml, $base + 12)[1];
            for ($i = 0; $i < $attrCount; $i++) {
                $a = $base + $attrStart + $i * $attrSize;
                if ($a + 20 > $length) {
                    return null;
                }
                $nameIndex = unpack('V', $xml, $a + 4)[1];
                $dataType = ord($xml[$a + 15]);
                $data = unpack('V', $xml, $a + 16)[1];
                $isVersionCode = ($strings[$nameIndex] ?? null) === 'versionCode'
                    || ($resourceIds[$nameIndex] ?? null) === KM_APK_ATTR_VERSION_CODE;
                if ($isVersionCode && ($dataType === 0x10 || $dataType === 0x11)) {
                    return $data >= 1 && $data <= 2100000000 ? $data : null;
                }
            }
            return null;
        }
        $pos += $chunkSize;
    }
    return null;
}

/** @return list<string> */
function km_axml_string_pool(string $chunk): array
{
    $headerSize = unpack('v', $chunk, 2)[1];
    $count = unpack('V', $chunk, 8)[1];
    $flags = unpack('V', $chunk, 16)[1];
    $stringsStart = unpack('V', $chunk, 20)[1];
    $utf8 = ($flags & 0x100) !== 0;
    $strings = [];
    for ($i = 0; $i < $count && $i < 100000; $i++) {
        $offset = $stringsStart + unpack('V', $chunk, $headerSize + $i * 4)[1];
        if ($offset >= strlen($chunk)) {
            $strings[] = '';
            continue;
        }
        if ($utf8) {
            $p = $offset;
            $p += (ord($chunk[$p]) & 0x80) ? 2 : 1;            // 文字数
            $n = ord($chunk[$p]);
            if ($n & 0x80) {
                $n = (($n & 0x7f) << 8) | ord($chunk[$p + 1]);
                $p += 2;
            } else {
                $p += 1;
            }
            $strings[] = substr($chunk, $p, $n);
        } else {
            $n = unpack('v', $chunk, $offset)[1];
            $p = $offset + 2;
            if ($n & 0x8000) {
                $n = (($n & 0x7fff) << 16) | unpack('v', $chunk, $offset + 2)[1];
                $p += 2;
            }
            $strings[] = (string) mb_convert_encoding(substr($chunk, $p, $n * 2), 'UTF-8', 'UTF-16LE');
        }
    }
    return $strings;
}
