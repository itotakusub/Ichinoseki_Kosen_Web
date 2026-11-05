// html-host.js
//
// HTML 展開サイトの道具のページ(/html.php)の動き(2026-11-05、利用者の指示。仕組みは lib/html-host.php)。
//
//   1. 落とす・選ぶ: ZIP・HTML・CSS・JS・画像・フォルダ(webkitGetAsEntry で中をたどり、相対パスを残す)
//   2. 整える: 隠しファイルを除き、全部が 1 つのフォルダの中ならそれを外す(サーバーの km_html_normalize_files と同じ決まり)
//   3. 置く:
//        このブラウザ … 見せる側の bridge.html(見えない iframe)へ渡す。IndexedDB にしまい、Service Worker が /l/<番号>/ で返す
//        一時フォルダ … このブラウザに置けないとき。1 つの zip にまとめて api/html-host.php へ(24 時間。鍵は localStorage に覚える)
//        サーバー     … サインインした人。1 つの zip にまとめて送る(版 10 まで)
//   4. HTML の無い CSS・JS だけを落としたら、「選んでいる」ものに足す(同じ名前は差し替え)
//   5. 開く: ローカルはそのままのリンク。一時とサーバーは、本体で使い捨ての札を作ってから見せる側の /auth へ
//
// 文字は textContent で入れる(上げたファイルの名前を HTML として読まない)。style 属性は使わない(CSP)。

(function () {
    'use strict';

    const root = document.getElementById('km-html');
    if (!root) return;

    const HTML_ORIGIN = root.dataset.htmlOrigin || '';
    const API = root.dataset.api || '/api/html-host.php';
    const TYPES = JSON.parse(root.dataset.types || '{}');
    const LIMITS = JSON.parse(root.dataset.limits || '{}');
    const CSRF = (document.querySelector('meta[name="km-csrf"]') || {}).content || '';
    const TEMP_KEY = 'km-html-temps';
    const PLACE_KEY = 'km-html-place';
    const CURRENT_KEY = 'km-html-current';

    const el = {
        drop: document.getElementById('km-html-drop'),
        pickFiles: document.getElementById('km-html-pick-files'),
        pickFolder: document.getElementById('km-html-pick-folder'),
        status: document.getElementById('km-html-status'),
        chooser: document.getElementById('km-html-chooser'),
        chooserList: document.getElementById('km-html-chooser-list'),
        chooserOk: document.getElementById('km-html-chooser-ok'),
        chooserCancel: document.getElementById('km-html-chooser-cancel'),
        placeServer: document.getElementById('km-html-place-server'),
        serverOptions: document.getElementById('km-html-server-options'),
        target: document.getElementById('km-html-target'),
        name: document.getElementById('km-html-name'),
        local: document.getElementById('km-html-local'),
        temp: document.getElementById('km-html-temp'),
        tempSection: document.getElementById('km-html-temp-section'),
        server: document.getElementById('km-html-server'),
        serverSection: document.getElementById('km-html-server-section'),
        serverNote: document.getElementById('km-html-server-note'),
    };

    const state = {
        localReady: false,
        localSites: [],
        server: { signedIn: root.dataset.signedIn === '1', canSave: false, sites: [] },
        // CSS・JS だけを落としたときの足す先 {kind: 'local'|'temp'|'site', id}
        current: readJson(CURRENT_KEY, null),
        busy: false,
    };

    // ------------------------------------------------------------------
    // 小物
    // ------------------------------------------------------------------

    function readJson(key, fallback) {
        try {
            const value = JSON.parse(localStorage.getItem(key) || 'null');
            return value === null ? fallback : value;
        } catch (error) {
            return fallback;
        }
    }

    function writeJson(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
            // 覚えられないだけ(プライベートの窓など)。その場では使える
        }
    }

    function setStatus(text, isError) {
        el.status.textContent = text || '';
        el.status.classList.toggle('is-error', Boolean(isError));
    }

    function sizeLabel(bytes) {
        if (bytes >= 1024 * 1024) return (Math.round(bytes / 1024 / 1024 * 10) / 10) + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    function typeOf(path) {
        const dot = path.lastIndexOf('.');
        const ext = dot >= 0 ? path.slice(dot + 1).toLowerCase() : '';
        return Object.prototype.hasOwnProperty.call(TYPES, ext) ? TYPES[ext] : 'application/octet-stream';
    }

    function isHtml(path) {
        return typeOf(path) === 'text/html';
    }

    function encodePath(path) {
        return path.split('/').map(encodeURIComponent).join('/');
    }

    function fail(message) {
        const error = new Error(message);
        error.userFacing = true;
        return error;
    }

    function button(label, className, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = className || 'km-doc-link';
        b.textContent = label;
        b.addEventListener('click', onClick);
        return b;
    }

    function formatTime(value) {
        const date = typeof value === 'number' ? new Date(value) : new Date(String(value).replace(' ', 'T') + '+09:00');
        if (Number.isNaN(date.getTime())) return '';
        return date.toLocaleString('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    // ------------------------------------------------------------------
    // 本体の口(api/html-host.php)
    // ------------------------------------------------------------------

    function post(action, fields, zip) {
        const body = new FormData();
        body.append('action', action);
        Object.keys(fields || {}).forEach(function (key) { body.append(key, String(fields[key])); });
        if (zip) body.append('zip', zip, 'upload.zip');
        return fetch(API, { method: 'POST', body: body, headers: { 'X-KM-CSRF': CSRF }, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return null; }).then(function (json) {
                    if (json && json.success) return json;
                    if (json && json.message) throw fail(json.message);
                    if (response.status === 413) throw fail('大きすぎます(' + sizeLabel(LIMITS.uploadBytes || 0) + ' まで)。');
                    if (response.status === 429) throw fail('続けて送りすぎました。少し待ってからやり直してください。');
                    throw fail('送れませんでした(' + response.status + ')。');
                });
            });
    }

    // ------------------------------------------------------------------
    // 見せる側の bridge(このブラウザの中の置き場)
    // ------------------------------------------------------------------

    const bridge = (function () {
        let frame = null;
        let seq = 0;
        const pending = new Map();
        let started = null;

        window.addEventListener('message', function (event) {
            if (!frame || event.origin !== HTML_ORIGIN || event.source !== frame.contentWindow) return;
            const message = event.data || {};
            if (!message.kmHtml) return;
            const waiter = pending.get(message.req);
            if (waiter) {
                pending.delete(message.req);
                waiter(message);
            }
        });

        function call(type, data, timeout) {
            return new Promise(function (resolve, reject) {
                if (!frame || !frame.contentWindow) {
                    reject(new Error('no-bridge'));
                    return;
                }
                const req = ++seq;
                const timer = setTimeout(function () {
                    pending.delete(req);
                    reject(new Error('timeout'));
                }, timeout || 60000);
                pending.set(req, function (message) {
                    clearTimeout(timer);
                    if (message.ok) resolve(message);
                    else reject(new Error(message.error || 'failed'));
                });
                frame.contentWindow.postMessage(Object.assign({ type: type, req: req }, data || {}), HTML_ORIGIN);
            });
        }

        function start() {
            if (started) return started;
            started = new Promise(function (resolve) {
                if (!HTML_ORIGIN || !('serviceWorker' in navigator) || !window.indexedDB) {
                    resolve(false);
                    return;
                }
                frame = document.createElement('iframe');
                frame.hidden = true;
                frame.title = 'HTML 展開(このブラウザの置き場)';
                frame.src = HTML_ORIGIN + '/bridge.html';
                const giveUp = setTimeout(function () { resolve(false); }, 10000);
                frame.addEventListener('load', function () {
                    call('hello', {}, 8000).then(function () {
                        clearTimeout(giveUp);
                        resolve(true);
                    }, function () {
                        clearTimeout(giveUp);
                        resolve(false);
                    });
                });
                document.body.appendChild(frame);
            });
            return started;
        }

        return { start: start, call: call };
    })();

    // ------------------------------------------------------------------
    // 落としたものを集める
    // ------------------------------------------------------------------

    function readEntryFile(entry) {
        return new Promise(function (resolve, reject) { entry.file(resolve, reject); });
    }

    function readDirBatch(reader) {
        return new Promise(function (resolve, reject) { reader.readEntries(resolve, reject); });
    }

    async function walk(entry, out) {
        if (out.length > (LIMITS.files || 1000) * 2) throw fail('ファイルが多すぎます(' + (LIMITS.files || 1000) + ' 個まで)。');
        if (entry.isFile) {
            out.push({ path: entry.fullPath.replace(/^\/+/, ''), file: await readEntryFile(entry) });
            return;
        }
        if (entry.isDirectory) {
            const reader = entry.createReader();
            let batch;
            do {
                batch = await readDirBatch(reader);
                for (const child of batch) await walk(child, out);
            } while (batch.length > 0);
        }
    }

    async function fromDataTransfer(dataTransfer) {
        const items = Array.from(dataTransfer.items || []).filter(function (item) { return item.kind === 'file'; });
        const entries = items.map(function (item) { return item.webkitGetAsEntry ? item.webkitGetAsEntry() : null; });
        if (entries.length > 0 && entries.every(Boolean)) {
            const out = [];
            for (const entry of entries) await walk(entry, out);
            return out;
        }
        return Array.from(dataTransfer.files || []).map(function (file) { return { path: file.name, file: file }; });
    }

    function fromFileList(files) {
        return Array.from(files || []).map(function (file) { return { path: file.webkitRelativePath || file.name, file: file }; });
    }

    // ------------------------------------------------------------------
    // ZIP を読む(このブラウザの中に置くとき)・ZIP にまとめる(サーバーへ送るとき)
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
            const error = new Error('no-inflate');
            error.noInflate = true;
            return Promise.reject(error);
        }
        const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
        return new Response(stream).arrayBuffer().then(function (buffer) { return new Uint8Array(buffer); });
    }

    async function unzip(file) {
        if (file.size > (LIMITS.totalBytes || 0)) throw fail('ZIP が大きすぎます(' + sizeLabel(LIMITS.totalBytes) + ' まで)。');
        const buffer = await file.arrayBuffer();
        const view = new DataView(buffer);
        const bytes = new Uint8Array(buffer);
        let eocd = -1;
        for (let i = bytes.length - 22; i >= Math.max(0, bytes.length - 65557); i--) {
            if (view.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
        }
        if (eocd < 0) throw fail('ZIP を読めませんでした(壊れているか、ZIP ではありません)。');
        const count = view.getUint16(eocd + 10, true);
        let pos = view.getUint32(eocd + 16, true);
        if (count === 0xFFFF || pos === 0xFFFFFFFF) throw fail('この ZIP の形式(zip64)は扱えません。');
        const out = [];
        let total = 0;
        for (let n = 0; n < count; n++) {
            if (pos + 46 > bytes.length || view.getUint32(pos, true) !== 0x02014b50) throw fail('ZIP が壊れています。');
            const flags = view.getUint16(pos + 8, true);
            const method = view.getUint16(pos + 10, true);
            const crc = view.getUint32(pos + 16, true);
            const compSize = view.getUint32(pos + 20, true);
            const size = view.getUint32(pos + 24, true);
            const nameLen = view.getUint16(pos + 28, true);
            const extraLen = view.getUint16(pos + 30, true);
            const commentLen = view.getUint16(pos + 32, true);
            const offset = view.getUint32(pos + 42, true);
            const name = decodeName(bytes.subarray(pos + 46, pos + 46 + nameLen), (flags & 0x0800) !== 0).replace(/\\/g, '/');
            pos += 46 + nameLen + extraLen + commentLen;
            if (name === '' || name.endsWith('/') || ignored(name)) continue;
            if (flags & 0x0001) throw fail('パスワード付きの ZIP は扱えません。');
            if (method !== 0 && method !== 8) throw fail('この圧縮方式は扱えません(' + name + ')。');
            total += size;
            if (total > (LIMITS.totalBytes || 0)) throw fail('中身の合計が大きすぎます(' + sizeLabel(LIMITS.totalBytes) + ' まで)。');
            if (view.getUint32(offset, true) !== 0x04034b50) throw fail('ZIP が壊れています(' + name + ')。');
            const start = offset + 30 + view.getUint16(offset + 26, true) + view.getUint16(offset + 28, true);
            const raw = bytes.subarray(start, start + compSize);
            const data = method === 0 ? raw.slice() : await inflateRaw(raw);
            if (data.length !== size || crc32(data) !== crc) throw fail('ZIP の中のファイルが壊れています(' + name + ')。');
            out.push({ path: name, data: data });
        }
        return out;
    }

    function deflateRaw(bytes) {
        if (typeof CompressionStream === 'undefined') return Promise.resolve(null);
        const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate-raw'));
        return new Response(stream).arrayBuffer().then(function (buffer) { return new Uint8Array(buffer); }, function () { return null; });
    }

    // サーバー(lib/zip-read.php)が読める形: 格納か deflate・UTF-8 の名前・zip64 なし
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

    // ------------------------------------------------------------------
    // 整える(サーバーの km_html_normalize_files と同じ決まり)
    // ------------------------------------------------------------------

    function ignored(path) {
        const segments = path.split('/');
        if (segments.some(function (s) { return s === '__MACOSX' || s.charAt(0) === '.'; })) return true;
        const base = segments[segments.length - 1].toLowerCase();
        return base === 'thumbs.db' || base === 'desktop.ini';
    }

    function cleanPath(path) {
        if (path === '' || path.charAt(0) === '/' || /^[A-Za-z]:/.test(path)) throw fail('使えないファイルの場所です(' + path + ')。');
        if (/[\u0000-\u001F\u007F]/.test(path)) throw fail('ファイル名に使えない文字があります(' + path + ')。');
        if (Array.from(path).length > (LIMITS.pathMax || 255)) throw fail('ファイルの場所が長すぎます(' + path.slice(0, 60) + '…)。');
        const segments = path.split('/');
        if (segments.length > (LIMITS.depthMax || 16)) throw fail('フォルダが深すぎます(' + path + ')。');
        if (segments.some(function (s) { return s === '' || s === '.' || s === '..'; })) throw fail('使えないファイルの場所です(' + path + ')。');
        return path;
    }

    function sizeOf(item) {
        return item.data ? item.data.length : item.file.size;
    }

    function normalize(list) {
        const seen = new Set();
        let total = 0;
        let files = [];
        list.forEach(function (item) {
            const path = item.path.replace(/\\/g, '/');
            if (ignored(path)) return;
            cleanPath(path);
            const key = path.toLowerCase();
            if (seen.has(key)) throw fail('大文字と小文字だけが違う同じ名前があります(' + path + ')。');
            seen.add(key);
            total += sizeOf(item);
            files.push(Object.assign({}, item, { path: path }));
        });
        if (files.length === 0) throw fail('使えるファイルがありませんでした。');
        if (files.length > (LIMITS.files || 1000)) throw fail('ファイルは ' + LIMITS.files + ' 個までにしてください。');
        if (total > (LIMITS.totalBytes || 0)) throw fail('中身の合計が大きすぎます(' + sizeLabel(LIMITS.totalBytes) + ' まで)。');
        // 全部が同じ 1 つのフォルダの中なら外す(何段でも)
        for (;;) {
            const tops = new Set(files.map(function (f) { const i = f.path.indexOf('/'); return i < 0 ? null : f.path.slice(0, i); }));
            if (tops.size !== 1 || tops.has(null)) break;
            const top = Array.from(tops)[0];
            files = files.map(function (f) { return Object.assign({}, f, { path: f.path.slice(top.length + 1) }); });
        }
        files.sort(function (a, b) { return a.path < b.path ? -1 : a.path > b.path ? 1 : 0; });
        return files;
    }

    function pickEntry(paths) {
        for (const index of ['index.html', 'index.htm']) {
            const found = paths.find(function (p) { return p.toLowerCase() === index; });
            if (found) return found;
        }
        const htmls = paths.filter(isHtml);
        return htmls.length === 1 ? htmls[0] : null;
    }

    function labelOf(list) {
        if (list.length === 1) return list[0].path.split('/').pop().replace(/\.[^.]+$/, '');
        const tops = new Set(list.map(function (f) { const i = f.path.indexOf('/'); return i < 0 ? null : f.path.slice(0, i); }));
        if (tops.size === 1 && !tops.has(null)) return Array.from(tops)[0];
        const html = list.find(function (f) { return isHtml(f.path); });
        return html ? html.path.split('/').pop().replace(/\.[^.]+$/, '') : 'HTML';
    }

    function chooseEntry(htmls) {
        return new Promise(function (resolve) {
            el.chooserList.textContent = '';
            htmls.forEach(function (path, index) {
                const label = document.createElement('label');
                const input = document.createElement('input');
                input.type = 'radio';
                input.name = 'km-html-entry';
                input.value = path;
                input.checked = index === 0;
                label.append(input, ' ', path);
                el.chooserList.appendChild(label);
            });
            el.chooser.hidden = false;
            function done(value) {
                el.chooser.hidden = true;
                el.chooserOk.removeEventListener('click', ok);
                el.chooserCancel.removeEventListener('click', cancel);
                resolve(value);
            }
            function ok() {
                const checked = el.chooserList.querySelector('input:checked');
                done(checked ? checked.value : null);
            }
            function cancel() { done(null); }
            el.chooserOk.addEventListener('click', ok);
            el.chooserCancel.addEventListener('click', cancel);
            el.chooserOk.focus();
        });
    }

    async function withData(files) {
        const out = [];
        for (const f of files) out.push({ path: f.path, data: f.data || new Uint8Array(await f.file.arrayBuffer()) });
        return out;
    }

    // ------------------------------------------------------------------
    // 置く
    // ------------------------------------------------------------------

    function place() {
        const checked = document.querySelector('input[name="km-html-place"]:checked');
        return checked && checked.value === 'server' && state.server.canSave ? 'server' : 'local';
    }

    function setCurrent(current) {
        state.current = current;
        writeJson(CURRENT_KEY, current);
        renderAll();
    }

    function temps() {
        const now = Date.now();
        return readJson(TEMP_KEY, []).filter(function (t) {
            return t && typeof t.id === 'string' && new Date(String(t.expiresAt).replace(' ', 'T') + '+09:00').getTime() > now;
        });
    }

    function saveTemps(list) {
        writeJson(TEMP_KEY, list);
    }

    async function toLocal(files, entry, name, patchId) {
        const payload = files.map(function (f) { return { path: f.path, type: typeOf(f.path), data: f.data.buffer.slice(f.data.byteOffset, f.data.byteOffset + f.data.byteLength) }; });
        const result = patchId
            ? await bridge.call('patch', { id: patchId, files: payload })
            : await bridge.call('put', { site: { name: name, entry: entry, files: payload } });
        await refreshLocal();
        return result.site;
    }

    async function toTemp(files, entry, name, patchTemp) {
        const zip = await buildZip(files);
        if (zip.size > (LIMITS.uploadBytes || 0)) throw fail('サーバーへ送るには大きすぎます(まとめて ' + sizeLabel(LIMITS.uploadBytes) + ' まで)。');
        const result = patchTemp
            ? await post('temp_patch', { id: patchTemp.id, key: patchTemp.key, entry: '' }, zip)
            : await post('temp', { name: name, entry: entry }, zip);
        const list = temps().filter(function (t) { return t.id !== result.temp.id; });
        list.unshift({ id: result.temp.id, key: patchTemp ? patchTemp.key : result.temp.key, name: result.temp.name, entry: result.temp.entry, files: result.temp.files, bytes: result.temp.bytes, expiresAt: result.temp.expiresAt });
        saveTemps(list);
        return result.temp;
    }

    async function toServer(files, entry, name, siteId, patch) {
        const zip = await buildZip(files);
        if (zip.size > (LIMITS.uploadBytes || 0)) throw fail('サーバーへ送るには大きすぎます(まとめて ' + sizeLabel(LIMITS.uploadBytes) + ' まで)。');
        const result = await post('save', { site: siteId || '', name: name, entry: entry, patch: patch ? '1' : '0' }, zip);
        await refreshServer();
        return result;
    }

    async function handle(rawList) {
        if (state.busy) return;
        state.busy = true;
        setStatus('読み込んでいます…');
        try {
            if (rawList.length === 0) throw fail('ファイルがありませんでした。');
            let label = labelOf(rawList);
            let list = rawList;
            if (rawList.length === 1 && /\.zip$/i.test(rawList[0].path)) {
                list = await unzip(rawList[0].file).catch(function (error) {
                    if (error && error.noInflate) throw fail('このブラウザでは ZIP を開けません。ZIP を展開してから、フォルダごと落としてください。');
                    throw error;
                });
            }
            const files = normalize(list);
            const paths = files.map(function (f) { return f.path; });
            const htmls = paths.filter(isHtml);

            // HTML の無い CSS・JS だけ → 選んでいるものに足す
            if (htmls.length === 0) {
                await patchCurrent(await withData(files));
                return;
            }
            let entry = pickEntry(paths);
            if (!entry) {
                setStatus('最初に開く HTML を選んでください。');
                entry = await chooseEntry(htmls);
                if (!entry) {
                    setStatus('やめました。');
                    return;
                }
            }
            setStatus('置いています…');
            const data = await withData(files);

            if (place() === 'server') {
                const name = el.name.value.trim() || label;
                const result = await toServer(data, entry, name, el.target.value, false);
                setCurrent({ kind: 'site', id: result.site.id });
                setStatus('サーバーに保存しました: ' + result.site.name + '(版 ' + result.version + ')' + (result.removed.length ? '。古い版(' + result.removed.join('・') + ')を消しました。' : '。'));
                return;
            }
            if (await bridge.start()) {
                try {
                    const site = await toLocal(data, entry, label, null);
                    setCurrent({ kind: 'local', id: site.id });
                    setStatus('このブラウザに置きました: ' + site.name + '(「開く」で開けます)');
                    return;
                } catch (error) {
                    // 入りきらない(容量)など。一時フォルダへ
                }
            }
            const temp = await toTemp(data, entry, label, null);
            setCurrent({ kind: 'temp', id: temp.id });
            renderAll();
            setStatus('このブラウザの中に置けなかったので、サーバーの一時フォルダに置きました(24 時間で消えます): ' + temp.name);
        } catch (error) {
            setStatus(error && error.userFacing ? error.message : '置けませんでした。もう一度お試しください。', true);
            if (!(error && error.userFacing)) console.error(error);
        } finally {
            state.busy = false;
        }
    }

    async function patchCurrent(files) {
        const current = state.current;
        if (!current) throw fail('HTML がありません。HTML も一緒に落としてください(CSS・JS だけのときは、足す先を一覧の「選ぶ」で決めてください)。');
        setStatus('選んでいるものに足しています…');
        if (current.kind === 'local') {
            if (!(await bridge.start()) || !state.localSites.some(function (s) { return s.id === current.id; })) throw fail('足す先が見つかりません。一覧の「選ぶ」で選び直してください。');
            const site = await toLocal(files, '', '', current.id);
            setStatus('足しました: ' + site.name + '(' + files.length + ' 個)');
            return;
        }
        if (current.kind === 'temp') {
            const temp = temps().find(function (t) { return t.id === current.id; });
            if (!temp) throw fail('足す先が見つかりません(一時フォルダのものは 24 時間で消えます)。');
            const result = await toTemp(files, '', '', temp);
            renderAll();
            setStatus('足しました: ' + result.name + '(' + files.length + ' 個)');
            return;
        }
        if (!state.server.canSave) throw fail('サインインすると、サーバーのものに足せます。');
        const result = await toServer(files, '', '', current.id, true);
        setStatus('足して、新しい版として保存しました: ' + result.site.name + '(版 ' + result.version + ')' + (result.removed.length ? '。古い版(' + result.removed.join('・') + ')を消しました。' : '。'));
    }

    // ------------------------------------------------------------------
    // 開く(一時とサーバーは使い捨ての札を作ってから)
    // ------------------------------------------------------------------

    function openWithTicket(fields) {
        // 札を待つ前に窓を開く(待ってから開くと、ポップアップとして止められる)
        const win = window.open('about:blank', '_blank');
        if (win) win.opener = null;
        post('ticket', fields).then(function (result) {
            if (win) win.location.href = result.url;
            else location.href = result.url;
        }, function (error) {
            if (win) win.close();
            setStatus(error && error.userFacing ? error.message : '開けませんでした。', true);
        });
    }

    // ------------------------------------------------------------------
    // 一覧
    // ------------------------------------------------------------------

    function item(name, meta, isCurrent) {
        const li = document.createElement('li');
        li.className = 'km-html-item' + (isCurrent ? ' is-current' : '');
        const head = document.createElement('div');
        head.className = 'km-html-item-head';
        const title = document.createElement('span');
        title.className = 'km-html-item-name';
        title.textContent = name;
        head.appendChild(title);
        if (isCurrent) {
            const badge = document.createElement('span');
            badge.className = 'km-html-current-badge';
            badge.textContent = '選んでいる';
            head.appendChild(badge);
        }
        const info = document.createElement('span');
        info.className = 'km-html-item-meta';
        info.textContent = meta;
        head.appendChild(info);
        li.appendChild(head);
        const actions = document.createElement('div');
        actions.className = 'km-html-item-actions';
        li.appendChild(actions);
        return { li: li, actions: actions };
    }

    function empty(list, text) {
        const p = document.createElement('li');
        p.className = 'km-html-empty';
        p.textContent = text;
        list.appendChild(p);
    }

    function isCurrent(kind, id) {
        return Boolean(state.current && state.current.kind === kind && state.current.id === id);
    }

    function renderLocal() {
        el.local.textContent = '';
        if (!state.localReady) {
            empty(el.local, 'このブラウザでは中に置けません(プライベートの窓など)。落とすと、サーバーの一時フォルダに 24 時間置きます。');
            return;
        }
        if (state.localSites.length === 0) {
            empty(el.local, 'まだありません。');
            return;
        }
        state.localSites.forEach(function (site) {
            const row = item(site.name, site.count + ' 個・' + sizeLabel(site.bytes) + '・' + formatTime(site.updated), isCurrent('local', site.id));
            const open = document.createElement('a');
            open.className = 'km-doc-button';
            open.href = HTML_ORIGIN + '/l/' + site.id + '/' + (site.entry && site.entry !== 'index.html' ? encodePath(site.entry) : '');
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.textContent = '開く ↗';
            row.actions.appendChild(open);
            row.actions.appendChild(button('選ぶ', 'km-doc-link', function () { setCurrent({ kind: 'local', id: site.id }); }));
            row.actions.appendChild(button('消す', 'km-doc-link km-html-danger', function () {
                if (!confirm('「' + site.name + '」をこのブラウザから消します。')) return;
                bridge.call('delete', { id: site.id }).then(refreshLocal, function () { setStatus('消せませんでした。', true); });
            }));
            el.local.appendChild(row.li);
        });
    }

    function renderTemp() {
        const list = temps();
        el.temp.textContent = '';
        el.tempSection.hidden = list.length === 0;
        list.forEach(function (temp) {
            const row = item(temp.name, temp.files + ' 個・' + sizeLabel(temp.bytes) + '・' + formatTime(temp.expiresAt) + ' に消えます', isCurrent('temp', temp.id));
            row.actions.appendChild(button('開く ↗', 'km-doc-button', function () { openWithTicket({ id: temp.id, key: temp.key }); }));
            row.actions.appendChild(button('選ぶ', 'km-doc-link', function () { setCurrent({ kind: 'temp', id: temp.id }); }));
            row.actions.appendChild(button('消す', 'km-doc-link km-html-danger', function () {
                if (!confirm('「' + temp.name + '」を一時フォルダから消します。')) return;
                post('temp_delete', { id: temp.id, key: temp.key }).catch(function () { return null; }).then(function () {
                    saveTemps(temps().filter(function (t) { return t.id !== temp.id; }));
                    renderAll();
                });
            }));
            el.temp.appendChild(row.li);
        });
    }

    function renderServer() {
        el.serverSection.hidden = !state.server.signedIn;
        el.server.textContent = '';
        // 保存先の選び方(新しいサイト + 今のサイト)
        const selected = el.target.value;
        el.target.textContent = '';
        const fresh = document.createElement('option');
        fresh.value = '';
        fresh.textContent = '新しいサイト';
        el.target.appendChild(fresh);
        state.server.sites.forEach(function (site) {
            const option = document.createElement('option');
            option.value = site.id;
            option.textContent = site.name + '(新しい版として)';
            el.target.appendChild(option);
        });
        el.target.value = state.server.sites.some(function (s) { return s.id === selected; }) ? selected : '';
        if (!state.server.signedIn) return;
        if (state.server.blocked) {
            el.serverNote.textContent = state.server.blocked;
            return;
        }
        if (state.server.sites.length === 0) {
            empty(el.server, 'まだありません。「置き場所」で「サーバーに保存」を選んでから落としてください。');
            return;
        }
        state.server.sites.forEach(function (site) {
            const latest = site.versions[0];
            const row = item(site.name, '版 ' + site.versions.length + ' つ・' + sizeLabel(site.bytes) + '・' + formatTime(site.updatedAt), isCurrent('site', site.id));
            row.actions.appendChild(button('開く ↗', 'km-doc-button', function () { openWithTicket({ site: site.id }); }));
            row.actions.appendChild(button('選ぶ', 'km-doc-link', function () { setCurrent({ kind: 'site', id: site.id }); }));
            row.actions.appendChild(button('名前を変える', 'km-doc-link', function () {
                const name = prompt('新しい名前', site.name);
                if (name === null || name.trim() === '') return;
                post('rename', { site: site.id, name: name.trim() }).then(refreshServer, function (error) { setStatus(error.message, true); });
            }));
            row.actions.appendChild(button('消す', 'km-doc-link km-html-danger', function () {
                if (!confirm('「' + site.name + '」を版ごとサーバーから消します。元に戻せません。')) return;
                post('delete_site', { site: site.id }).then(refreshServer, function (error) { setStatus(error.message, true); });
            }));
            const details = document.createElement('details');
            details.className = 'km-html-versions';
            const summary = document.createElement('summary');
            summary.textContent = '版の一覧(新しい順。' + (LIMITS.versions || 10) + ' より古い版は自動で消えます)';
            details.appendChild(summary);
            const ol = document.createElement('ol');
            site.versions.forEach(function (version) {
                const li = document.createElement('li');
                const text = document.createElement('span');
                text.textContent = '版 ' + version.version + (version === latest ? '(いちばん新しい)' : '') + '・' + version.files + ' 個・' + sizeLabel(version.bytes) + '・' + formatTime(version.createdAt);
                li.appendChild(text);
                li.appendChild(button('開く ↗', 'km-doc-link', function () { openWithTicket({ site: site.id, version: version.version }); }));
                li.appendChild(button('消す', 'km-doc-link km-html-danger', function () {
                    if (!confirm('版 ' + version.version + ' を消します。' + (site.versions.length === 1 ? '最後の版なので、サイトごと消えます。' : ''))) return;
                    post('delete_version', { site: site.id, version: version.version }).then(refreshServer, function (error) { setStatus(error.message, true); });
                }));
                ol.appendChild(li);
            });
            details.appendChild(ol);
            row.li.appendChild(details);
            el.server.appendChild(row.li);
        });
    }

    function renderAll() {
        renderLocal();
        renderTemp();
        renderServer();
        el.serverOptions.hidden = place() !== 'server';
    }

    function refreshLocal() {
        if (!state.localReady) {
            renderAll();
            return Promise.resolve();
        }
        return bridge.call('list', {}, 15000).then(function (result) {
            state.localSites = result.sites || [];
            renderAll();
        }, function () {
            renderAll();
        });
    }

    function refreshServer() {
        return post('state', {}).then(function (result) {
            state.server = { signedIn: result.signedIn, canSave: result.canSave, blocked: result.blocked, sites: result.sites || [] };
            if (el.placeServer) el.placeServer.disabled = !result.canSave;
            renderAll();
        }, function () {
            renderAll();
        });
    }

    // ------------------------------------------------------------------
    // つなぐ
    // ------------------------------------------------------------------

    ['dragenter', 'dragover'].forEach(function (type) {
        el.drop.addEventListener(type, function (event) {
            event.preventDefault();
            el.drop.classList.add('is-over');
        });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        el.drop.addEventListener(type, function () { el.drop.classList.remove('is-over'); });
    });
    // 落とし損ねて、ブラウザがファイルを開いてしまわないように
    window.addEventListener('dragover', function (event) { event.preventDefault(); });
    window.addEventListener('drop', function (event) { event.preventDefault(); });
    el.drop.addEventListener('drop', function (event) {
        event.preventDefault();
        fromDataTransfer(event.dataTransfer).then(handle, function (error) {
            setStatus(error && error.userFacing ? error.message : '読めませんでした。', true);
        });
    });
    [el.pickFiles, el.pickFolder].forEach(function (input) {
        input.addEventListener('change', function () {
            const list = fromFileList(input.files);
            input.value = '';
            handle(list);
        });
    });
    document.querySelectorAll('input[name="km-html-place"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            writeJson(PLACE_KEY, radio.value);
            renderAll();
        });
    });

    // 前に選んだ置き場所(サーバーはサインインしていて保存できるときだけ)
    const savedPlace = readJson(PLACE_KEY, 'local');
    if (savedPlace === 'server' && el.placeServer && !el.placeServer.disabled) el.placeServer.checked = true;

    renderAll();
    bridge.start().then(function (ready) {
        state.localReady = ready;
        return refreshLocal();
    });
    refreshServer();
})();
