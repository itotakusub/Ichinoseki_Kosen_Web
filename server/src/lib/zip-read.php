<?php

declare(strict_types=1);

/**
 * zip の中身を取り出す(2026-10-21。階の画像をまとめて差し替える admin/map-floors.php が使う)。
 *
 * **本番の PHP には zip の拡張(ZipArchive)が無い**(docker/php/Dockerfile は mysqli と pdo_mysql だけ)。
 * 拡張を足すとイメージの作り直しが要るので、APK を読むのと同じやり方(lib/apk-version.php の km_apk_read_entry)で
 * **中央ディレクトリを自分で読む。** 扱えるのは「格納」と「deflate」だけ(Windows の「送る → 圧縮」・macOS の圧縮はどちらか)。
 *
 * 外から来たファイルなので、**取り出す前に断る**:
 *   - 暗号化・zip64・分割は扱わない
 *   - 数・1 つの大きさ・合計に上限(展開して膨らむもの = zip 爆弾は、gzinflate の上限で止まる)
 *   - CRC を照らす(壊れたものを画像として置かない)
 *   - 名前は**最後の部分だけ**使う(`../` やフォルダは捨てる)。フォルダ・`__MACOSX/`・`.` で始まるものは飛ばす
 *   - 名前の文字コード: UTF-8 の印(bit 11)が無く、UTF-8 として読めなければ CP932(Windows の日本語)として読む
 */

/**
 * @return array<int, array{name:string, bytes:string}>
 */
function km_zip_read_all(string $path, int $maxEntries, int $maxEntryBytes, int $maxTotalBytes): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('zip を開けませんでした。');
    }
    try {
        $size = (int) fstat($handle)['size'];
        $tailLength = min($size, 65535 + 22);
        if ($tailLength < 22) {
            throw new InvalidArgumentException('zip ではありません(短すぎます)。');
        }
        fseek($handle, $size - $tailLength);
        $tail = (string) fread($handle, $tailLength);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) - $eocd < 22) {
            throw new InvalidArgumentException('zip ではありません(終わりの記録が見つかりません)。');
        }
        $e = unpack('vdisk/vcdDisk/vcountDisk/vcount/VcdSize/VcdOffset', substr($tail, $eocd + 4, 16));
        if (!is_array($e) || $e['disk'] !== 0 || $e['cdDisk'] !== 0) {
            throw new InvalidArgumentException('分割された zip は扱えません。1 つの zip にしてください。');
        }
        if ($e['count'] === 0xFFFF || $e['cdOffset'] === 0xFFFFFFFF) {
            throw new InvalidArgumentException('zip64 の形式は扱えません。');
        }
        if ($e['count'] > $maxEntries * 4 || $e['cdSize'] > 4 * 1024 * 1024 || $e['cdOffset'] + $e['cdSize'] > $size) {
            throw new InvalidArgumentException('zip の中身が多すぎるか、壊れています。');
        }

        fseek($handle, $e['cdOffset']);
        $cd = (string) fread($handle, $e['cdSize']);
        $entries = [];
        $total = 0;
        $pos = 0;
        while ($pos + 46 <= strlen($cd) && substr($cd, $pos, 4) === "PK\x01\x02") {
            $h = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset', substr($cd, $pos + 8, 38));
            if (!is_array($h)) {
                throw new InvalidArgumentException('zip が壊れています。');
            }
            $rawName = substr($cd, $pos + 46, $h['nameLen']);
            $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];

            $name = km_zip_entry_name($rawName, ($h['flags'] & 0x0800) !== 0);
            if ($name === null) {
                continue;   // フォルダ・隠しファイル・macOS の付録
            }
            if (($h['flags'] & 0x0001) !== 0) {
                throw new InvalidArgumentException('パスワード付きの zip は扱えません(' . $name . ')。');
            }
            if ($h['comp'] === 0xFFFFFFFF || $h['uncomp'] === 0xFFFFFFFF || $h['offset'] === 0xFFFFFFFF) {
                throw new InvalidArgumentException('zip64 の形式は扱えません。');
            }
            if ($h['method'] !== 0 && $h['method'] !== 8) {
                throw new InvalidArgumentException('この圧縮方式は扱えません(' . $name . ')。Windows の「圧縮(zip 形式)フォルダー」で作り直してください。');
            }
            if (count($entries) >= $maxEntries) {
                throw new InvalidArgumentException('zip の中のファイルは ' . $maxEntries . ' 個までにしてください。');
            }
            if ($h['uncomp'] > $maxEntryBytes || $h['comp'] > $maxEntryBytes) {
                throw new InvalidArgumentException('大きすぎるファイルがあります(' . $name . '。1 つ ' . intdiv($maxEntryBytes, 1024 * 1024) . 'MB まで)。');
            }
            $total += $h['uncomp'];
            if ($total > $maxTotalBytes) {
                throw new InvalidArgumentException('zip の中身の合計が大きすぎます(' . intdiv($maxTotalBytes, 1024 * 1024) . 'MB まで)。');
            }

            fseek($handle, $h['offset']);
            $local = (string) fread($handle, 30);
            if (strlen($local) !== 30 || substr($local, 0, 4) !== "PK\x03\x04") {
                throw new InvalidArgumentException('zip が壊れています(' . $name . ')。');
            }
            $l = unpack('vnameLen/vextraLen', substr($local, 26, 4));
            fseek($handle, $h['offset'] + 30 + $l['nameLen'] + $l['extraLen']);
            $data = $h['comp'] > 0 ? (string) fread($handle, $h['comp']) : '';
            if (strlen($data) !== $h['comp']) {
                throw new InvalidArgumentException('zip が途中で切れています(' . $name . ')。');
            }
            // 上限を渡す: 申告より膨らむもの(zip 爆弾)はここで止まる
            $bytes = $h['method'] === 0 ? $data : @gzinflate($data, $maxEntryBytes);
            if (!is_string($bytes) || strlen($bytes) !== $h['uncomp']) {
                throw new InvalidArgumentException('zip の中のファイルを取り出せませんでした(' . $name . ')。');
            }
            if ((crc32($bytes) & 0xFFFFFFFF) !== ($h['crc'] & 0xFFFFFFFF)) {
                throw new InvalidArgumentException('zip の中のファイルが壊れています(' . $name . '。CRC が合いません)。');
            }
            $entries[] = ['name' => $name, 'bytes' => $bytes];
        }

        return $entries;
    } finally {
        fclose($handle);
    }
}

/**
 * zip の中の名前を、画面に出せる「最後の部分だけ」にする。飛ばすものは null。
 */
function km_zip_entry_name(string $raw, bool $utf8Flag): ?string
{
    if ($raw === '' || str_ends_with($raw, '/') || str_ends_with($raw, '\\')) {
        return null;
    }
    $name = $raw;
    if (!$utf8Flag && !mb_check_encoding($raw, 'UTF-8')) {
        // Windows の「送る → 圧縮」は日本語の名前を CP932 で書く
        $name = (string) mb_convert_encoding($raw, 'UTF-8', 'CP932');
    }
    $normalized = str_replace('\\', '/', $name);
    if (str_starts_with($normalized, '__MACOSX/') || str_contains($normalized, '/__MACOSX/')) {
        return null;
    }
    $base = basename($normalized);
    if ($base === '' || str_starts_with($base, '.') || preg_match('/[\x00-\x1F\x7F]/', $base) === 1) {
        return null;
    }

    return mb_substr($base, 0, 100);
}
