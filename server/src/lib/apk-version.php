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

/**
 * APK のパッケージ名(AndroidManifest の package)。読めなければ null(2026-10-09)。
 * 受け箱(lib/apk-inbox.php)が、一般用と管理用の取り違えを断るのに使う。
 */
function km_apk_package_name(string $path): ?string
{
    $manifest = km_apk_read_entry($path, 'AndroidManifest.xml');
    return $manifest === null ? null : km_axml_package_name($manifest);
}

/** バイナリ XML(AXML)の最初の要素(manifest)から package を読む。無ければ null。 */
function km_axml_package_name(string $xml): ?string
{
    $found = null;
    km_axml_walk_manifest($xml, static function (string $name, int $dataType, int $data, int $raw, array $strings, ?int $resourceId) use (&$found): bool {
        if ($name === 'package') {
            // 文字列の属性は rawValue(と data)が文字列表の番号
            $value = $strings[$raw] ?? ($dataType === 0x03 ? ($strings[$data] ?? null) : null);
            $found = is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/', $value) === 1 ? $value : null;
            return true;
        }
        return false;
    });
    return $found;
}

/**
 * APK の署名の証明書(最初の署名者の最初の証明書。DER)の SHA-256(小文字の 16 進)。読めなければ null(2026-10-09)。
 *
 * apksigner の「certificate SHA-256 digest」と同じ値。受け箱が「今の配布物と同じ鍵で署名されているか」を確かめる
 * (違う鍵の APK は公開させない。漏れた鍵で作った偽の APK を、うっかり公開しないため)。
 *
 * APK Signing Block(中央ディレクトリのすぐ前。末尾 24 バイトが「大きさ + `APK Sig Block 42`」)から、
 * v2(0x7109871a)か v3(0xf05368c0)の値を読む。中身は「長さ(4 バイト)+ 中身」の入れ子:
 *   署名者の並び → 署名者 → 署名した中身 → (要約の並び, 証明書の並び → 証明書)
 * 全体は読まない(zip の終わりと署名の塊だけ)。
 */
function km_apk_signer_sha256(string $path): ?string
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    try {
        $size = (int) fstat($handle)['size'];
        $tailLength = min($size, 65535 + 22);
        if ($tailLength < 22) {
            return null;
        }
        fseek($handle, $size - $tailLength);
        $tail = (string) fread($handle, $tailLength);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) - $eocd < 22) {
            return null;
        }
        $cdOffset = unpack('V', substr($tail, $eocd + 16, 4))[1];
        if ($cdOffset < 32 || $cdOffset > $size) {
            return null;
        }
        fseek($handle, $cdOffset - 24);
        $footer = (string) fread($handle, 24);
        if (strlen($footer) !== 24 || substr($footer, 8, 16) !== 'APK Sig Block 42') {
            return null;
        }
        $blockSize = unpack('P', substr($footer, 0, 8))[1];
        if ($blockSize < 24 || $blockSize > 32 * 1024 * 1024 || $blockSize + 8 > $cdOffset) {
            return null;
        }
        fseek($handle, $cdOffset - ($blockSize + 8));
        $block = (string) fread($handle, $blockSize + 8);
        if (strlen($block) !== $blockSize + 8 || unpack('P', substr($block, 0, 8))[1] !== $blockSize) {
            return null;
        }
        // 「長さ(8)+ ID(4)+ 値」の並び。末尾の 24 バイトは大きさと印
        $values = [];
        $pos = 8;
        $end = strlen($block) - 24;
        while ($pos + 12 <= $end) {
            $length = unpack('P', substr($block, $pos, 8))[1];
            if ($length < 4 || $pos + 8 + $length > $end) {
                return null;
            }
            $id = unpack('V', substr($block, $pos + 8, 4))[1];
            $values[$id] = substr($block, $pos + 12, $length - 4);
            $pos += 8 + $length;
        }
        $scheme = $values[0x7109871a] ?? $values[0xf05368c0] ?? null;
        if ($scheme === null) {
            return null;
        }
        $signers = km_apk_lp($scheme, 0);
        $signer = $signers === null ? null : km_apk_lp($signers[0], 0);
        $signedData = $signer === null ? null : km_apk_lp($signer[0], 0);
        $digests = $signedData === null ? null : km_apk_lp($signedData[0], 0);
        $certificates = $digests === null ? null : km_apk_lp($signedData[0], $digests[1]);
        $certificate = $certificates === null ? null : km_apk_lp($certificates[0], 0);
        if ($certificate === null || $certificate[0] === '') {
            return null;
        }
        return hash('sha256', $certificate[0]);
    } finally {
        fclose($handle);
    }
}

/**
 * 「長さ(4 バイト・リトルエンディアン)+ 中身」を 1 つ読む。戻りは [中身, 次の位置]。はみ出せば null。
 *
 * @return array{0:string, 1:int}|null
 */
function km_apk_lp(string $bytes, int $offset): ?array
{
    if ($offset + 4 > strlen($bytes)) {
        return null;
    }
    $length = unpack('V', substr($bytes, $offset, 4))[1];
    if ($offset + 4 + $length > strlen($bytes)) {
        return null;
    }
    return [substr($bytes, $offset + 4, $length), $offset + 4 + $length];
}

/** バイナリ XML(AXML)の最初の要素から versionCode を読む。無ければ null。 */
function km_axml_version_code(string $xml): ?int
{
    $found = null;
    km_axml_walk_manifest($xml, static function (string $name, int $dataType, int $data, int $raw, array $strings, ?int $resourceId) use (&$found): bool {
        if ($name === 'versionCode' || $resourceId === KM_APK_ATTR_VERSION_CODE) {
            if ($dataType === 0x10 || $dataType === 0x11) {
                $found = $data >= 1 && $data <= 2100000000 ? $data : null;
            }
            return true;
        }
        return false;
    });
    return $found;
}

/**
 * 最初の要素(manifest)の属性を 1 つずつ [$visit] に渡す。[$visit] が true を返したら止める。
 * 渡すのは(名前, 値の型, 値, 生の値の文字列番号, 文字列表, 属性の資源 ID)。
 */
function km_axml_walk_manifest(string $xml, callable $visit): void
{
    $length = strlen($xml);
    if ($length < 8 || unpack('v', $xml, 0)[1] !== 0x0003) {
        return;
    }
    $strings = [];
    $resourceIds = [];
    $pos = (int) unpack('v', $xml, 2)[1];   // 先頭の見出しの大きさ
    while ($pos + 8 <= $length) {
        $type = unpack('v', $xml, $pos)[1];
        $headerSize = unpack('v', $xml, $pos + 2)[1];
        $chunkSize = unpack('V', $xml, $pos + 4)[1];
        if ($chunkSize < 8 || $pos + $chunkSize > $length) {
            return;
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
                    return;
                }
                // 属性: 名前空間(4)・名前(4)・生の値(4)・型つきの値(大きさ 2・0・型 1・値 4)
                $nameIndex = unpack('V', $xml, $a + 4)[1];
                $raw = unpack('V', $xml, $a + 8)[1];
                $dataType = ord($xml[$a + 15]);
                $data = unpack('V', $xml, $a + 16)[1];
                if ($visit((string) ($strings[$nameIndex] ?? ''), $dataType, $data, $raw, $strings, $resourceIds[$nameIndex] ?? null)) {
                    return;
                }
            }
            return;
        }
        $pos += $chunkSize;
    }
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
