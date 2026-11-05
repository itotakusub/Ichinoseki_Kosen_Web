// html-zip.js
//
// HTML 展開サイトの道具のページ(/html.php)で、ZIP を読む・作る(2026-11-05、利用者の指示)。
// html-host.js より先に読み、window.KmHtmlZip を置く。DOM には触らない(Node でも試せるように)。
//
//   - 読む: 格納(0)と deflate(8)。名前は UTF-8 の印が無ければ UTF-8 → Shift_JIS(Windows の「送る → 圧縮」)
//   - パスワード付き: ZipCrypto(従来の方式)と WinZip AES(方式 99。AE-1/AE-2・128/192/256)
//       パスワードはブラウザの中だけで使う。サーバーへは、解いた中身を暗号化しない ZIP に作り直して送る
//       AES の CTR はカウンターが little-endian で、WebCrypto の AES-CTR(big-endian)では解けないため、
//       AES のブロック暗号化だけをここに書く(鍵の作り方と HMAC は WebCrypto)
//   - ZIP 以外の圧縮ファイル(7z・RAR・tar・gz など)を、拡張子と先頭のバイトで見分ける
//   - 作る: サーバー(lib/zip-read.php)が読める形(格納か deflate・UTF-8 の名前・zip64 なし・暗号化なし)

(function (root) {
    'use strict';

    function fail(message, extra) {
        const error = new Error(message);
        error.userFacing = true;
        if (extra) Object.assign(error, extra);
        return error;
    }

    // ------------------------------------------------------------------
    // CRC32
    // ------------------------------------------------------------------

    const CRC_TABLE = (function () {
        const table = new Uint32Array(256);
        for (let n = 0; n < 256; n++) {
            let c = n;
            for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
            table[n] = c >>> 0;
        }
        return table;
    })();

    function crc32(bytes) {
        let c = 0xFFFFFFFF;
        for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
        return (c ^ 0xFFFFFFFF) >>> 0;
    }

    // ------------------------------------------------------------------
    // ZIP 以外の圧縮ファイル
    // ------------------------------------------------------------------

    const ARCHIVE_EXT = /\.(7z|rar|tar|gz|tgz|bz2|tbz|tbz2|xz|txz|zst|tzst|lzh|lha|cab|iso|lz|lzma|z|arj|cpio|zipx|wim|xar|dmg)$/i;

    /** 先頭のバイトから圧縮ファイルの種類を言う(ZIP は 'zip'。当てはまらなければ null) */
    function archiveKind(head) {
        const b = head;
        const at = function (offset, list) { return list.every(function (v, i) { return b[offset + i] === v; }); };
        if (b.length >= 4 && b[0] === 0x50 && b[1] === 0x4B && ((b[2] === 3 && b[3] === 4) || (b[2] === 5 && b[3] === 6) || (b[2] === 7 && b[3] === 8))) return 'zip';
        if (at(0, [0x37, 0x7A, 0xBC, 0xAF, 0x27, 0x1C])) return '7z';
        if (at(0, [0x52, 0x61, 0x72, 0x21, 0x1A, 0x07])) return 'RAR';
        if (at(0, [0x1F, 0x8B])) return 'gzip';
        if (at(0, [0x42, 0x5A, 0x68])) return 'bzip2';
        if (at(0, [0xFD, 0x37, 0x7A, 0x58, 0x5A, 0x00])) return 'xz';
        if (at(0, [0x28, 0xB5, 0x2F, 0xFD])) return 'zstd';
        if (at(0, [0x4D, 0x53, 0x43, 0x46])) return 'cab';
        if (at(0, [0x4C, 0x5A, 0x49, 0x50])) return 'lzip';
        if (at(0, [0x1F, 0x9D]) || at(0, [0x1F, 0xA0])) return 'Z';
        if (at(257, [0x75, 0x73, 0x74, 0x61, 0x72])) return 'tar';
        if (b[2] === 0x2D && b[3] === 0x6C && (b[4] === 0x68 || b[4] === 0x7A) && b[6] === 0x2D) return 'lzh';
        return null;
    }

    function isArchiveName(path) {
        return ARCHIVE_EXT.test(path);
    }

    // ------------------------------------------------------------------
    // AES(ブロック暗号化だけ)。表と鍵の展開は SJCL と同じ作り
    // ------------------------------------------------------------------

    const AES_TABLES = (function () {
        const enc = [new Uint32Array(256), new Uint32Array(256), new Uint32Array(256), new Uint32Array(256)];
        const sbox = new Uint8Array(256);
        const d = new Array(256);
        const th = new Array(256);
        for (let i = 0; i < 256; i++) th[(d[i] = i << 1 ^ (i >> 7) * 283) ^ i] = i;
        for (let x = 0, xInv = 0; !sbox[x]; x ^= d[x] || 1, xInv = th[xInv] || 1) {
            let s = xInv ^ xInv << 1 ^ xInv << 2 ^ xInv << 3 ^ xInv << 4;
            s = s >> 8 ^ s & 255 ^ 99;
            sbox[x] = s;
            let tEnc = d[s] * 0x101 ^ s * 0x1010100;
            for (let i = 0; i < 4; i++) {
                tEnc = tEnc << 24 ^ tEnc >>> 8;
                enc[i][x] = tEnc;
            }
        }
        return { enc: enc, sbox: sbox };
    })();

    /** 鍵(16・24・32 バイト)→ 回す鍵(32 ビットの語の並び) */
    function aesExpandKey(keyBytes) {
        const sbox = AES_TABLES.sbox;
        const keyLen = keyBytes.length / 4;
        if (keyLen !== 4 && keyLen !== 6 && keyLen !== 8) throw new Error('aes key length');
        const words = new Uint32Array(4 * keyLen + 28);
        for (let i = 0; i < keyLen; i++) {
            words[i] = (keyBytes[4 * i] << 24 | keyBytes[4 * i + 1] << 16 | keyBytes[4 * i + 2] << 8 | keyBytes[4 * i + 3]) >>> 0;
        }
        let rcon = 1;
        for (let i = keyLen; i < words.length; i++) {
            let tmp = words[i - 1];
            if (i % keyLen === 0 || (keyLen === 8 && i % keyLen === 4)) {
                tmp = sbox[tmp >>> 24] << 24 ^ sbox[tmp >> 16 & 255] << 16 ^ sbox[tmp >> 8 & 255] << 8 ^ sbox[tmp & 255];
                if (i % keyLen === 0) {
                    tmp = tmp << 8 ^ tmp >>> 24 ^ rcon << 24;
                    rcon = rcon << 1 ^ (rcon >> 7) * 283;
                }
            }
            words[i] = (words[i - keyLen] ^ tmp) >>> 0;
        }
        return words;
    }

    /** 16 バイトを 1 つ暗号化する(in と out は 16 バイトの Uint8Array) */
    function aesEncryptBlock(key, input, out) {
        const t0 = AES_TABLES.enc[0], t1 = AES_TABLES.enc[1], t2 = AES_TABLES.enc[2], t3 = AES_TABLES.enc[3], sbox = AES_TABLES.sbox;
        let a = (input[0] << 24 | input[1] << 16 | input[2] << 8 | input[3]) ^ key[0];
        let b = (input[4] << 24 | input[5] << 16 | input[6] << 8 | input[7]) ^ key[1];
        let c = (input[8] << 24 | input[9] << 16 | input[10] << 8 | input[11]) ^ key[2];
        let d = (input[12] << 24 | input[13] << 16 | input[14] << 8 | input[15]) ^ key[3];
        const inner = key.length / 4 - 2;
        let k = 4;
        for (let i = 0; i < inner; i++) {
            const a2 = t0[a >>> 24] ^ t1[b >> 16 & 255] ^ t2[c >> 8 & 255] ^ t3[d & 255] ^ key[k];
            const b2 = t0[b >>> 24] ^ t1[c >> 16 & 255] ^ t2[d >> 8 & 255] ^ t3[a & 255] ^ key[k + 1];
            const c2 = t0[c >>> 24] ^ t1[d >> 16 & 255] ^ t2[a >> 8 & 255] ^ t3[b & 255] ^ key[k + 2];
            d = t0[d >>> 24] ^ t1[a >> 16 & 255] ^ t2[b >> 8 & 255] ^ t3[c & 255] ^ key[k + 3];
            k += 4;
            a = a2; b = b2; c = c2;
        }
        for (let i = 0; i < 4; i++) {
            const word = (sbox[a >>> 24] << 24 ^ sbox[b >> 16 & 255] << 16 ^ sbox[c >> 8 & 255] << 8 ^ sbox[d & 255] ^ key[k++]) >>> 0;
            out[4 * i] = word >>> 24;
            out[4 * i + 1] = word >>> 16 & 255;
            out[4 * i + 2] = word >>> 8 & 255;
            out[4 * i + 3] = word & 255;
            const t = a; a = b; b = c; c = d; d = t;
        }
    }

    /** WinZip AES の CTR(カウンターは 1 から、little-endian)。暗号化と復号は同じ */
    function winzipAesCtr(keyBytes, data) {
        const key = aesExpandKey(keyBytes);
        const out = new Uint8Array(data.length);
        const counter = new Uint8Array(16);
        const stream = new Uint8Array(16);
        let n = 0;
        for (let pos = 0; pos < data.length; pos += 16) {
            n++;
            counter[0] = n & 255; counter[1] = n >>> 8 & 255; counter[2] = n >>> 16 & 255; counter[3] = n >>> 24 & 255;
            aesEncryptBlock(key, counter, stream);
            const end = Math.min(16, data.length - pos);
            for (let i = 0; i < end; i++) out[pos + i] = data[pos + i] ^ stream[i];
        }
        return out;
    }

    // ------------------------------------------------------------------
    // パスワード付きのファイルを解く
    // ------------------------------------------------------------------

    function subtle() {
        const c = root.crypto || (typeof crypto !== 'undefined' ? crypto : null);
        if (!c || !c.subtle) throw fail('このブラウザでは、パスワード付きの ZIP を開けません(https で開いてください)。');
        return c.subtle;
    }

    /** ZipCrypto(従来の方式)。頭の 12 バイトの最後で照合する。合わなければ null */
    function zipCryptoDecrypt(passwordBytes, raw, checkByte) {
        let k0 = 0x12345678, k1 = 0x23456789, k2 = 0x34567890;
        function update(byte) {
            k0 = (CRC_TABLE[(k0 ^ byte) & 0xFF] ^ (k0 >>> 8)) >>> 0;
            k1 = (k1 + (k0 & 0xFF)) >>> 0;
            k1 = (Math.imul(k1, 134775813) + 1) >>> 0;
            k2 = (CRC_TABLE[(k2 ^ (k1 >>> 24)) & 0xFF] ^ (k2 >>> 8)) >>> 0;
        }
        for (let i = 0; i < passwordBytes.length; i++) update(passwordBytes[i]);
        if (raw.length < 12) return null;
        const out = new Uint8Array(raw.length - 12);
        for (let i = 0; i < raw.length; i++) {
            const temp = (k2 | 2) & 0xFFFF;
            const plain = (raw[i] ^ ((temp * (temp ^ 1)) >>> 8)) & 0xFF;
            update(plain);
            if (i < 12) {
                if (i === 11 && plain !== checkByte) return null;
            } else {
                out[i - 12] = plain;
            }
        }
        return out;
    }

    /** WinZip AES。確かめ値と HMAC が合わなければ null */
    async function winzipAesDecrypt(passwordBytes, raw, strength) {
        const keyLen = [0, 16, 24, 32][strength];
        if (!keyLen) throw fail('この ZIP の暗号化の強さは扱えません。');
        const saltLen = keyLen / 2;
        if (raw.length < saltLen + 2 + 10) return null;
        const salt = raw.subarray(0, saltLen);
        const verifier = raw.subarray(saltLen, saltLen + 2);
        const body = raw.subarray(saltLen + 2, raw.length - 10);
        const mac = raw.subarray(raw.length - 10);
        const s = subtle();
        const base = await s.importKey('raw', passwordBytes, 'PBKDF2', false, ['deriveBits']);
        const bits = new Uint8Array(await s.deriveBits({ name: 'PBKDF2', salt: salt, iterations: 1000, hash: 'SHA-1' }, base, (2 * keyLen + 2) * 8));
        if (bits[2 * keyLen] !== verifier[0] || bits[2 * keyLen + 1] !== verifier[1]) return null;
        const macKey = await s.importKey('raw', bits.subarray(keyLen, 2 * keyLen), { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']);
        const sign = new Uint8Array(await s.sign('HMAC', macKey, body));
        for (let i = 0; i < 10; i++) {
            if (sign[i] !== mac[i]) throw fail('ZIP の中のファイルが壊れています(暗号の検査が合いません)。');
        }
        return winzipAesCtr(bits.subarray(0, keyLen), body);
    }

    // ------------------------------------------------------------------
    // 読む
    // ------------------------------------------------------------------

    function decodeName(bytes, utf8) {
        if (utf8) return new TextDecoder('utf-8').decode(bytes);
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
        } catch (error) {
            // Windows の「送る → 圧縮」は日本語の名前を Shift_JIS で書く
            return new TextDecoder('shift_jis').decode(bytes);
        }
    }

    function inflateRaw(bytes) {
        if (typeof DecompressionStream === 'undefined') {
            return Promise.reject(fail('このブラウザでは ZIP を開けません。ZIP を展開してから、フォルダごと落としてください。', { noInflate: true }));
        }
        const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
        return new Response(stream).arrayBuffer().then(function (buffer) { return new Uint8Array(buffer); });
    }

    /** 中央の目録を読む(中身はまだ解かない) */
    function readDirectory(bytes) {
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        let eocd = -1;
        for (let i = bytes.length - 22; i >= Math.max(0, bytes.length - 65557); i--) {
            if (view.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
        }
        if (eocd < 0) throw fail('ZIP を読めませんでした(壊れているか、ZIP ではありません)。');
        const count = view.getUint16(eocd + 10, true);
        let pos = view.getUint32(eocd + 16, true);
        if (count === 0xFFFF || pos === 0xFFFFFFFF) throw fail('この ZIP の形式(zip64)は扱えません。');
        const entries = [];
        for (let n = 0; n < count; n++) {
            if (pos + 46 > bytes.length || view.getUint32(pos, true) !== 0x02014b50) throw fail('ZIP が壊れています。');
            const nameLen = view.getUint16(pos + 28, true);
            const extraLen = view.getUint16(pos + 30, true);
            const commentLen = view.getUint16(pos + 32, true);
            const flags = view.getUint16(pos + 8, true);
            const entry = {
                flags: flags,
                method: view.getUint16(pos + 10, true),
                time: view.getUint16(pos + 12, true),
                crc: view.getUint32(pos + 16, true),
                compSize: view.getUint32(pos + 20, true),
                size: view.getUint32(pos + 24, true),
                offset: view.getUint32(pos + 42, true),
                name: decodeName(bytes.subarray(pos + 46, pos + 46 + nameLen), (flags & 0x0800) !== 0),
                aes: null,
            };
            // WinZip AES の追加領域(0x9901): 版(1/2)・'AE'・強さ・本当の方式
            let e = pos + 46 + nameLen;
            const extraEnd = e + extraLen;
            while (e + 4 <= extraEnd) {
                const id = view.getUint16(e, true);
                const size = view.getUint16(e + 2, true);
                if (id === 0x9901 && size >= 7) {
                    entry.aes = { version: view.getUint16(e + 4, true), strength: bytes[e + 8], method: view.getUint16(e + 9, true) };
                }
                e += 4 + size;
            }
            entries.push(entry);
            pos += 46 + nameLen + extraLen + commentLen;
        }
        return entries;
    }

    function rawData(bytes, entry) {
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        if (entry.offset + 30 > bytes.length || view.getUint32(entry.offset, true) !== 0x04034b50) throw fail('ZIP が壊れています(' + entry.name + ')。');
        const start = entry.offset + 30 + view.getUint16(entry.offset + 26, true) + view.getUint16(entry.offset + 28, true);
        if (start + entry.compSize > bytes.length) throw fail('ZIP が途中で切れています(' + entry.name + ')。');
        return bytes.subarray(start, start + entry.compSize);
    }

    /**
     * 1 つ取り出す。パスワードが違えば wrongPassword の印の付いた例外。
     * @returns {Promise<Uint8Array>}
     */
    async function extract(bytes, entry, passwordBytes) {
        let raw = rawData(bytes, entry);
        let method = entry.method;
        let checkCrc = true;
        if (entry.flags & 0x0001) {
            if (entry.flags & 0x0040) throw fail('この ZIP の暗号化の方式(強い暗号化)は扱えません。ZipCrypto か AES で作り直してください。');
            if (passwordBytes === null) throw fail('パスワードが要ります。', { wrongPassword: true });
            if (method === 99) {
                if (!entry.aes) throw fail('ZIP が壊れています(' + entry.name + ')。');
                const plain = await winzipAesDecrypt(passwordBytes, raw, entry.aes.strength);
                if (plain === null) throw fail('パスワードが違います。', { wrongPassword: true });
                raw = plain;
                method = entry.aes.method;
                // AE-2 は CRC を 0 にしている(HMAC で確かめ済み)
                checkCrc = entry.aes.version !== 2;
            } else {
                const check = (entry.flags & 0x0008) ? (entry.time >>> 8) & 0xFF : entry.crc >>> 24;
                const plain = zipCryptoDecrypt(passwordBytes, raw, check);
                if (plain === null) throw fail('パスワードが違います。', { wrongPassword: true });
                raw = plain;
            }
        }
        if (method !== 0 && method !== 8) throw fail('この圧縮方式は扱えません(' + entry.name + ')。Windows の「圧縮(zip 形式)フォルダー」か、7-Zip の ZIP(Deflate)で作り直してください。');
        const data = method === 0 ? raw.slice() : await inflateRaw(raw).catch(function (error) {
            if (error && error.userFacing) throw error;
            // ZipCrypto の照合は 1 バイトなので、違うパスワードがすり抜けるとここで壊れる
            throw fail((entry.flags & 0x0001) ? 'パスワードが違います。' : 'ZIP の中のファイルが壊れています(' + entry.name + ')。', { wrongPassword: (entry.flags & 0x0001) !== 0 });
        });
        if (data.length !== entry.size || (checkCrc && crc32(data) !== entry.crc)) {
            throw fail((entry.flags & 0x0001) ? 'パスワードが違います。' : 'ZIP の中のファイルが壊れています(' + entry.name + ')。', { wrongPassword: (entry.flags & 0x0001) !== 0 });
        }
        return data;
    }

    /**
     * ZIP を読む。
     *
     * @param {Uint8Array} bytes
     * @param {{maxTotal:number, keep:(path:string)=>('keep'|'ignore'|'skip'), askPassword:(wrong:boolean)=>Promise<string|null>}} options
     *   keep: 'keep' は使う、'ignore' は黙って飛ばす(__MACOSX など)、'skip' は「使わないファイル」として数える
     * @returns {Promise<{files: Array<{path:string, data:Uint8Array}>, skipped: string[], encrypted: boolean}>}
     */
    async function unzip(bytes, options) {
        const head = bytes.subarray(0, 512);
        const kind = archiveKind(head);
        if (kind !== null && kind !== 'zip') throw fail('ZIP 以外の圧縮ファイル(' + kind + ')は使えません。ZIP にしてください。');
        const entries = readDirectory(bytes);
        const files = [];
        const skipped = [];
        const wanted = [];
        let total = 0;
        for (const entry of entries) {
            const name = entry.name.replace(/\\/g, '/');
            if (name === '' || name.endsWith('/')) continue;
            const verdict = options.keep(name);
            if (verdict === 'ignore') continue;
            if (verdict === 'skip') {
                skipped.push(name);
                continue;
            }
            total += entry.size;
            if (total > options.maxTotal) throw fail('中身の合計が大きすぎます。');
            wanted.push(Object.assign({}, entry, { name: name }));
        }
        const encrypted = wanted.filter(function (e) { return (e.flags & 0x0001) !== 0; });
        let passwordBytes = null;
        if (encrypted.length > 0) {
            // 最初の暗号化されたファイルで、パスワードを確かめてから全部を解く
            let wrong = false;
            for (;;) {
                const password = await options.askPassword(wrong);
                if (password === null) throw fail('やめました。', { cancelled: true });
                const candidate = new TextEncoder().encode(password);
                try {
                    await extract(bytes, encrypted[0], candidate);
                    passwordBytes = candidate;
                    break;
                } catch (error) {
                    if (!(error && error.wrongPassword)) throw error;
                    wrong = true;
                }
            }
        }
        for (const entry of wanted) {
            try {
                files.push({ path: entry.name, data: await extract(bytes, entry, passwordBytes) });
            } catch (error) {
                if (error && error.wrongPassword) throw fail('ファイルごとにパスワードが違う ZIP は扱えません(' + entry.name + ')。');
                throw error;
            }
        }
        return { files: files, skipped: skipped, encrypted: encrypted.length > 0 };
    }

    // ------------------------------------------------------------------
    // 作る(サーバーへ送るとき)
    // ------------------------------------------------------------------

    function deflateRaw(bytes) {
        if (typeof CompressionStream === 'undefined') return Promise.resolve(null);
        const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate-raw'));
        return new Response(stream).arrayBuffer().then(function (buffer) { return new Uint8Array(buffer); }, function () { return null; });
    }

    // サーバー(lib/zip-read.php)が読める形: 格納か deflate・UTF-8 の名前・zip64 なし・暗号化なし
    async function buildZip(files) {
        const encoder = new TextEncoder();
        const parts = [];
        const central = [];
        let offset = 0;
        for (const file of files) {
            const name = encoder.encode(file.path);
            const data = file.data;
            const crc = crc32(data);
            let method = 0;
            let body = data;
            if (data.length > 256) {
                const packed = await deflateRaw(data);
                if (packed && packed.length < data.length) {
                    method = 8;
                    body = packed;
                }
            }
            const local = new DataView(new ArrayBuffer(30));
            local.setUint32(0, 0x04034b50, true);
            local.setUint16(4, 20, true);
            local.setUint16(6, 0x0800, true);
            local.setUint16(8, method, true);
            local.setUint16(12, 33, true);   // 1980-01-01
            local.setUint32(14, crc, true);
            local.setUint32(18, body.length, true);
            local.setUint32(22, data.length, true);
            local.setUint16(26, name.length, true);
            parts.push(local.buffer, name, body);
            const head = new DataView(new ArrayBuffer(46));
            head.setUint32(0, 0x02014b50, true);
            head.setUint16(4, 20, true);
            head.setUint16(6, 20, true);
            head.setUint16(8, 0x0800, true);
            head.setUint16(10, method, true);
            head.setUint16(14, 33, true);
            head.setUint32(16, crc, true);
            head.setUint32(20, body.length, true);
            head.setUint32(24, data.length, true);
            head.setUint16(28, name.length, true);
            head.setUint32(42, offset, true);
            central.push(head.buffer, name);
            offset += 30 + name.length + body.length;
        }
        let centralSize = 0;
        central.forEach(function (part) { centralSize += part.byteLength; });
        const end = new DataView(new ArrayBuffer(22));
        end.setUint32(0, 0x06054b50, true);
        end.setUint16(8, files.length, true);
        end.setUint16(10, files.length, true);
        end.setUint32(12, centralSize, true);
        end.setUint32(16, offset, true);
        return new Blob(parts.concat(central, [end.buffer]), { type: 'application/zip' });
    }

    root.KmHtmlZip = {
        crc32: crc32,
        archiveKind: archiveKind,
        isArchiveName: isArchiveName,
        unzip: unzip,
        buildZip: buildZip,
        // 試験のため(scripts で Node から呼ぶ)
        _aes: { expandKey: aesExpandKey, encryptBlock: aesEncryptBlock, ctr: winzipAesCtr },
    };
})(typeof window !== 'undefined' ? window : globalThis);
