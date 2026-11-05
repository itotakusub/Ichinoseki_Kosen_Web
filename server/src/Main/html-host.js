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
        gate: document.getElementById('km-html-gate'),
        work: document.getElementById('km-html-work'),
        agree: document.getElementById('km-html-agree'),
        gateOk: document.getElementById('km-html-gate-ok'),
        gateStatus: document.getElementById('km-html-gate-status'),
        password: document.getElementById('km-html-password'),
        passwordInput: document.getElementById('km-html-password-input'),
        passwordMessage: document.getElementById('km-html-password-message'),
        passwordOk: document.getElementById('km-html-password-ok'),
        passwordCancel: document.getElementById('km-html-password-cancel'),
    };

    const state = {
        localReady: false,
        localSites: [],
        server: { signedIn: root.dataset.signedIn === '1', canSave: false, sites: [] },
        // CSS・JS だけを落としたときの足す先 {kind: 'local'|'temp'|'site', id}
        current: readJson(CURRENT_KEY, null),
        busy: false,
        // 使い始める前の関門の期限(秒。0 なら未通過)。サーバーのセッションの印と同じ値(api/html-host.php の gate)
        gateUntil: Number(root.dataset.gateUntil || 0),
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
                    if (json && json.message) {
                        const error = fail(json.message);
                        error.gate = Boolean(json.gate);
                        throw error;
                    }
                    if (response.status === 413) throw fail('大きすぎます(' + sizeLabel(LIMITS.uploadBytes || 0) + ' まで)。');
                    if (response.status === 429) throw fail('続けて送りすぎました。少し待ってからやり直してください。');
                    throw fail('送れませんでした(' + response.status + ')。');
                });
            });
    }

    // ------------------------------------------------------------------
    // 使い始める前の関門(2026-11-05、利用者の指示): 利用規約への同意と「私はロボットではありません」
    // 通るまで落とす場所を出さない。サーバーに置く口も、セッションの印が無ければ断る(error.gate)
    // ------------------------------------------------------------------

    function gatePassed() {
        return state.gateUntil * 1000 > Date.now();
    }

    function setGateStatus(text, isError) {
        el.gateStatus.textContent = text || '';
        el.gateStatus.classList.toggle('is-error', Boolean(isError));
    }

    // クラシック(grecaptcha)と Enterprise(grecaptcha.enterprise)で口が違う
    function recaptchaApi() {
        const g = window.grecaptcha;
        if (!g) return null;
        return g.enterprise || g;
    }

    function recaptchaReset() {
        const api = recaptchaApi();
        if (api && typeof api.reset === 'function') {
            try { api.reset(); } catch (error) { /* まだ描かれていない */ }
        }
    }

    // ページを開いたときに通っていた人は読んでいない。期限が切れたら、ここで初めて読む
    function ensureRecaptcha() {
        const src = root.dataset.recaptchaSrc || '';
        if (!src || window.grecaptcha || document.getElementById('km-html-recaptcha-js')) return;
        const script = document.createElement('script');
        script.id = 'km-html-recaptcha-js';
        script.src = src;
        script.async = true;
        document.head.appendChild(script);
    }

    function showGate(message) {
        state.gateUntil = 0;
        el.work.hidden = true;
        el.gate.hidden = false;
        el.agree.checked = false;
        ensureRecaptcha();
        recaptchaReset();
        setGateStatus(message || '', Boolean(message));
        el.gate.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    function submitGate() {
        if (!el.agree.checked) {
            setGateStatus('利用規約を読んで、「利用規約に同意します」にチェックを入れてください。', true);
            return;
        }
        const field = el.gate.querySelector('[name="g-recaptcha-response"]');
        const token = field ? field.value : '';
        if (!token) {
            setGateStatus('「私はロボットではありません」にチェックを入れてください。', true);
            return;
        }
        el.gateOk.disabled = true;
        setGateStatus('確かめています…');
        post('gate', { agree: '1', 'g-recaptcha-response': token }).then(function (result) {
            state.gateUntil = Number(result.gateUntil || 0);
            setGateStatus('');
            el.gate.hidden = true;
            el.work.hidden = false;
            setStatus('確認できました。ファイルを落としてください。');
        }, function (error) {
            recaptchaReset();
            setGateStatus(error && error.userFacing ? error.message : '確かめられませんでした。もう一度お試しください。', true);
        }).then(function () {
            el.gateOk.disabled = false;
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
    // ZIP を読む・作る・パスワード付きを解く・ZIP 以外の圧縮ファイルを見分けるのは Main/html-zip.js(window.KmHtmlZip)
    // ------------------------------------------------------------------

    const Z = window.KmHtmlZip;

    // ------------------------------------------------------------------
    // 整える(サーバーの km_html_normalize_files と同じ決まり)
    // ------------------------------------------------------------------

    function ignored(path) {
        const segments = path.split('/');
        if (segments.some(function (s) { return s === '__MACOSX' || s.charAt(0) === '.'; })) return true;
        const base = segments[segments.length - 1].toLowerCase();
        return base === 'thumbs.db' || base === 'desktop.ini';
    }

    // 型の表(lib/html-host.php の KM_HTML_TYPES)にあるものだけを使う。exe・docx・中の zip・7z などは除く(2026-11-05)
    function allowedType(path) {
        return typeOf(path) !== 'application/octet-stream';
    }

    /**
     * 先頭を整える(2026-11-05、利用者の試しの ZIP)。ドライブ名(C:/)・先頭の / ・ ./ は断らずに外す
     * (「C:/Users/…/web/index.html」のように、PC の場所ごと入った ZIP がある)。.. は外さない(下で断る)
     */
    function tidyPath(path) {
        let p = path.replace(/\\/g, '/').replace(/^[A-Za-z]:(?=\/|$)/, '').replace(/^\/+/, '');
        while (p.slice(0, 2) === './') p = p.slice(2);
        return p;
    }

    // ZIP の中身用: 使う・黙って飛ばす(__MACOSX など)・使わないファイルとして数える
    function zipVerdict(rawPath) {
        const path = tidyPath(rawPath);
        if (path === '' || ignored(path)) return 'ignore';
        return allowedType(path) ? 'keep' : 'skip';
    }

    function cleanPath(path) {
        if (path === '') throw fail('使えないファイルの場所です。');
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

    /**
     * 同じ中身の木が 2 組以上あれば 1 組にする(2026-11-05。「Users/…/web/」と「web/」の両方が入った ZIP)。
     * index.html を持つフォルダを根の候補にし、全部のファイルがどれかの候補の下にあって、
     * どの候補の下も「相対パス・大きさ・CRC32」が同じなら、パスがいちばん短い候補だけを残す。中身が違えば何もしない
     */
    function dedupeTrees(files) {
        if (!files.every(function (f) { return f.data; })) return files;
        const roots = new Set();
        files.forEach(function (f) {
            const m = f.path.match(/^(.*\/)?index\.html?$/i);
            if (m) roots.add(m[1] || '');
        });
        if (roots.size < 2) return files;
        const list = Array.from(roots).sort(function (a, b) { return a.length - b.length || (a < b ? -1 : 1); });
        // 根の中に別の根がある(入れ子)なら、別のページなので触らない
        if (list.some(function (r, i) { return list.some(function (o, j) { return i !== j && r.indexOf(o) === 0; }); })) return files;
        const groups = list.map(function () { return []; });
        for (const f of files) {
            const i = list.findIndex(function (r) { return f.path.indexOf(r) === 0; });
            if (i < 0) return files;
            groups[i].push(f);
        }
        const signature = function (group, rootPath) {
            return group.map(function (f) { return f.path.slice(rootPath.length) + '\u0000' + f.data.length + '\u0000' + Z.crc32(f.data); }).sort().join('\n');
        };
        const first = signature(groups[0], list[0]);
        return groups.every(function (g, i) { return signature(g, list[i]) === first; }) ? groups[0] : files;
    }

    /** @returns {{files: Array, skipped: string[]}} skipped は使わなかったファイル(型の表に無いもの) */
    function normalize(list, skippedBefore) {
        const skipped = (skippedBefore || []).slice();
        let files = [];
        list.forEach(function (item) {
            const path = tidyPath(item.path);
            if (path === '' || ignored(path)) return;
            if (!allowedType(path)) {
                skipped.push(path);
                return;
            }
            cleanPath(path);
            files.push(Object.assign({}, item, { path: path }));
        });
        if (files.length === 0) throw fail(skipped.length ? '使えるファイルがありませんでした(HTML・CSS・JS・画像などだけを使います)。' : '使えるファイルがありませんでした。');
        files = dedupeTrees(files);
        const seen = new Set();
        let total = 0;
        files.forEach(function (f) {
            const key = f.path.toLowerCase();
            if (seen.has(key)) throw fail('大文字と小文字だけが違う同じ名前があります(' + f.path + ')。');
            seen.add(key);
            total += sizeOf(f);
        });
        if (files.length > (LIMITS.files || 1000)) throw fail('ファイルは ' + LIMITS.files + ' 個までにしてください。');
        if (total > (LIMITS.totalBytes || 0)) throw fail('中身の合計が大きすぎます(' + sizeLabel(LIMITS.totalBytes) + ' まで)。');
        // 全部が同じ 1 つのフォルダの中なら外す(何段でも。「Users/…/Downloads/web/」も消える)
        for (;;) {
            const tops = new Set(files.map(function (f) { const i = f.path.indexOf('/'); return i < 0 ? null : f.path.slice(0, i); }));
            if (tops.size !== 1 || tops.has(null)) break;
            const top = Array.from(tops)[0];
            files = files.map(function (f) { return Object.assign({}, f, { path: f.path.slice(top.length + 1) }); });
        }
        files.sort(function (a, b) { return a.path < b.path ? -1 : a.path > b.path ? 1 : 0; });
        return { files: files, skipped: skipped };
    }

    // 入口: いちばん上の index.html → いちばん上の階に HTML が 1 つだけならそれ → 全体で 1 つならそれ → 選んでもらう
    function pickEntry(paths) {
        for (const index of ['index.html', 'index.htm']) {
            const found = paths.find(function (p) { return p.toLowerCase() === index; });
            if (found) return found;
        }
        const htmls = paths.filter(isHtml);
        const top = htmls.filter(function (p) { return p.indexOf('/') < 0; });
        if (top.length === 1) return top[0];
        return htmls.length === 1 ? htmls[0] : null;
    }

    function skippedNote(skipped) {
        if (!skipped.length) return '';
        const names = skipped.map(function (p) { return p.split('/').pop(); });
        return '(使わないファイルを除きました: ' + names.slice(0, 3).join('・') + (names.length > 3 ? ' ほか ' + (names.length - 3) + ' 個' : '') + ')';
    }

    // ZIP 以外の圧縮ファイルを断る・ZIP は単独で 1 つだけ(2026-11-05)。返すのは ZIP(無ければ null)
    async function screenDrop(rawList) {
        const archiveMessage = 'ZIP 以外の圧縮ファイル(7z・RAR・tar・gz など)は使えません。ZIP にしてください';
        let zip = null;
        let zips = 0;
        for (const item of rawList) {
            const name = item.path.split('/').pop();
            if (ignored(tidyPath(item.path))) continue;
            const head = new Uint8Array(await item.file.slice(0, 512).arrayBuffer());
            const kind = Z.archiveKind(head);
            if (Z.isArchiveName(name) || (kind !== null && kind !== 'zip')) throw fail(archiveMessage + '(' + name + ')。');
            if (/\.zip$/i.test(name)) {
                zips++;
                zip = item;
            }
        }
        if (zips > 0 && (zips > 1 || rawList.length > 1)) throw fail('ZIP は 1 つだけ、ほかのファイルと混ぜずに落としてください。');
        return zip;
    }

    // パスワード付きの ZIP(ZipCrypto・AES)。パスワードはこのブラウザの中だけで使い、サーバーへは送らない
    function askPassword(wrong) {
        return new Promise(function (resolve) {
            el.password.hidden = false;
            el.passwordInput.value = '';
            el.passwordMessage.textContent = wrong ? 'パスワードが違います。もう一度入れてください。' : '';
            el.passwordMessage.classList.toggle('is-error', Boolean(wrong));
            setStatus(wrong ? '' : 'この ZIP にはパスワードが付いています。');
            function done(value) {
                el.password.hidden = true;
                el.passwordInput.value = '';
                el.passwordOk.removeEventListener('click', ok);
                el.passwordCancel.removeEventListener('click', cancel);
                el.passwordInput.removeEventListener('keydown', key);
                resolve(value);
            }
            function ok() {
                if (el.passwordInput.value === '') {
                    el.passwordMessage.textContent = 'パスワードを入れてください。';
                    el.passwordMessage.classList.add('is-error');
                    return;
                }
                setStatus('パスワードを確かめています…');
                done(el.passwordInput.value);
            }
            function cancel() { done(null); }
            function key(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    ok();
                }
            }
            el.passwordOk.addEventListener('click', ok);
            el.passwordCancel.addEventListener('click', cancel);
            el.passwordInput.addEventListener('keydown', key);
            el.passwordInput.focus();
        });
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
        const zip = await Z.buildZip(files);
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
        const zip = await Z.buildZip(files);
        if (zip.size > (LIMITS.uploadBytes || 0)) throw fail('サーバーへ送るには大きすぎます(まとめて ' + sizeLabel(LIMITS.uploadBytes) + ' まで)。');
        const result = await post('save', { site: siteId || '', name: name, entry: entry, patch: patch ? '1' : '0' }, zip);
        await refreshServer();
        return result;
    }

    async function handle(rawList) {
        if (state.busy) return;
        // このブラウザの中に置くときも、関門を通ってから(期限が切れていたら出し直す)
        if (!gatePassed()) {
            showGate('確認の期限が切れました。もう一度、同意と確認をお願いします。');
            return;
        }
        state.busy = true;
        setStatus('読み込んでいます…');
        try {
            if (rawList.length === 0) throw fail('ファイルがありませんでした。');
            let label = labelOf(rawList);
            // ZIP 以外の圧縮ファイルは断る。ZIP は単独で 1 つだけ(パスワード付きなら、ここで聞く)
            const zipItem = await screenDrop(rawList);
            let list = rawList;
            let skippedInZip = [];
            if (zipItem) {
                if (zipItem.file.size > (LIMITS.totalBytes || 0)) throw fail('ZIP が大きすぎます(' + sizeLabel(LIMITS.totalBytes) + ' まで)。');
                const opened = await Z.unzip(new Uint8Array(await zipItem.file.arrayBuffer()), {
                    maxTotal: LIMITS.totalBytes || 0,
                    keep: zipVerdict,
                    askPassword: askPassword,
                });
                list = opened.files;
                skippedInZip = opened.skipped;
            }
            const normalized = normalize(list, skippedInZip);
            const files = normalized.files;
            const note = skippedNote(normalized.skipped);
            const paths = files.map(function (f) { return f.path; });
            const htmls = paths.filter(isHtml);

            // HTML の無い CSS・JS だけ → 選んでいるものに足す
            if (htmls.length === 0) {
                await patchCurrent(await withData(files));
                if (note) setStatus(el.status.textContent + note);
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
                setStatus('サーバーに保存しました: ' + result.site.name + '(版 ' + result.version + ')' + (result.removed.length ? '。古い版(' + result.removed.join('・') + ')を消しました。' : '。') + note);
                return;
            }
            if (await bridge.start()) {
                try {
                    const site = await toLocal(data, entry, label, null);
                    setCurrent({ kind: 'local', id: site.id });
                    setStatus('このブラウザに置きました: ' + site.name + '(「開く」で開けます)' + note);
                    return;
                } catch (error) {
                    // 入りきらない(容量)など。一時フォルダへ
                }
            }
            const temp = await toTemp(data, entry, label, null);
            setCurrent({ kind: 'temp', id: temp.id });
            renderAll();
            setStatus('このブラウザの中に置けなかったので、サーバーの一時フォルダに置きました(24 時間で消えます): ' + temp.name + note);
        } catch (error) {
            if (error && error.cancelled) {
                setStatus('やめました。');
                return;
            }
            if (error && error.gate) {
                // サーバーのセッションの印が無い・切れた(サインインで入れ替わったときなど)
                setStatus('');
                showGate(error.message);
                return;
            }
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
            // サーバーの印が切れていたら、次に落としたときに関門を出す(ここでは画面を変えない)
            if (typeof result.gateUntil === 'number') state.gateUntil = result.gateUntil;
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
    el.gateOk.addEventListener('click', submitGate);
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
