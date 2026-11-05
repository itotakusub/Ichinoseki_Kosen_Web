// bridge.js
//
// HTML 展開サイトのローカルの置き場(2026-11-05。lib/html-host.php の「置き場所は 3 つ」の 1)。
// 本体の道具のページ(html.php・Main/html-host.js)が、見せる側のこのページを見えない iframe で開いて話しかける。
//
//   - 受けるのは km-parent(本体のオリジン)の、親の窓からの postMessage だけ
//   - 中身は見せる側のオリジンの IndexedDB(km-html / sites)にしまう。サーバーには送らない
//   - Service Worker(/sw.js、範囲 /l/)を登録する。/l/<番号>/… を開くと、SW がここにしまったものを返す
//
// 話しかけ方(どれも {kmHtml: true, req, ok, …} で返す):
//   hello                        SW を登録して動くまで待つ(使えなければ ok: false → 道具のページは一時フォルダへ)
//   put    {site: {id?, name, entry, files: [{path, type, data}]}}   置く(id があれば置き換え)
//   patch  {id, files}           足す(同じパスは差し替え)
//   list                         置いたものの一覧(中身は返さない)
//   delete {id}                  消す

(function () {
    'use strict';

    const parentOrigin = (document.querySelector('meta[name="km-parent"]') || {}).content || '';
    const DB_NAME = 'km-html';
    const STORE = 'sites';
    let dbPromise = null;

    function openDb() {
        if (!dbPromise) {
            dbPromise = new Promise(function (resolve, reject) {
                const request = indexedDB.open(DB_NAME, 1);
                request.onupgradeneeded = function () {
                    request.result.createObjectStore(STORE, { keyPath: 'id' });
                };
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error); };
            });
            dbPromise.catch(function () { dbPromise = null; });
        }
        return dbPromise;
    }

    function run(mode, work) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE, mode);
                const request = work(tx.objectStore(STORE));
                tx.oncomplete = function () { resolve(request ? request.result : undefined); };
                tx.onerror = function () { reject(tx.error); };
                tx.onabort = function () { reject(tx.error || new Error('abort')); };
            });
        });
    }

    function newId() {
        const bytes = new Uint8Array(12);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }

    function validId(id) {
        return typeof id === 'string' && /^[0-9a-f]{24}$/.test(id);
    }

    // SW の登録。bridge.html は範囲(/l/)の外なので navigator.serviceWorker.ready は待てない。登録の状態を見て待つ
    function ensureWorker() {
        if (!('serviceWorker' in navigator)) return Promise.reject(new Error('no-service-worker'));
        return navigator.serviceWorker.register('/sw.js', { scope: '/l/' }).then(function (registration) {
            if (registration.active) return;
            const worker = registration.installing || registration.waiting;
            if (!worker) return;
            return new Promise(function (resolve, reject) {
                worker.addEventListener('statechange', function () {
                    if (worker.state === 'activated') resolve();
                    if (worker.state === 'redundant') reject(new Error('service-worker-redundant'));
                });
            });
        });
    }

    function toFiles(list, map) {
        const files = map || new Map();
        (list || []).forEach(function (file) {
            if (typeof file.path !== 'string' || file.path === '' || !(file.data instanceof ArrayBuffer)) {
                throw new Error('bad-file');
            }
            files.set(file.path, { type: String(file.type || 'application/octet-stream'), blob: new Blob([file.data], { type: String(file.type || '') }) });
        });
        return files;
    }

    function summary(site) {
        return { id: site.id, name: site.name, entry: site.entry, count: site.files.size, bytes: site.bytes, updated: site.updated };
    }

    function measure(site) {
        let bytes = 0;
        site.files.forEach(function (file) { bytes += file.blob.size; });
        site.bytes = bytes;
        site.updated = Date.now();
        return site;
    }

    function getSite(id) {
        return run('readonly', function (store) { return store.get(id); });
    }

    function putSite(input) {
        const id = validId(input.id) ? input.id : newId();
        const site = measure({ id: id, name: String(input.name || 'HTML').slice(0, 100), entry: String(input.entry || 'index.html'), files: toFiles(input.files) });
        if (!site.files.has(site.entry)) throw new Error('no-entry');
        return run('readwrite', function (store) { return store.put(site); }).then(function () { return summary(site); });
    }

    function patchSite(id, list) {
        return getSite(id).then(function (site) {
            if (!site) throw new Error('not-found');
            toFiles(list, site.files);
            measure(site);
            return run('readwrite', function (store) { return store.put(site); }).then(function () { return summary(site); });
        });
    }

    function listSites() {
        return run('readonly', function (store) { return store.getAll(); }).then(function (sites) {
            return (sites || []).map(summary).sort(function (a, b) { return b.updated - a.updated; });
        });
    }

    window.addEventListener('message', function (event) {
        if (parentOrigin === '' || event.origin !== parentOrigin || event.source !== window.parent) return;
        const message = event.data || {};
        function reply(data) {
            window.parent.postMessage(Object.assign({ kmHtml: true, req: message.req }, data), parentOrigin);
        }
        let work;
        switch (message.type) {
            case 'hello':
                work = ensureWorker().then(openDb).then(function () { return { ok: true }; });
                break;
            case 'put':
                work = putSite(message.site || {}).then(function (site) { return { ok: true, site: site }; });
                break;
            case 'patch':
                work = patchSite(message.id, message.files).then(function (site) { return { ok: true, site: site }; });
                break;
            case 'list':
                work = listSites().then(function (sites) { return { ok: true, sites: sites }; });
                break;
            case 'delete':
                work = run('readwrite', function (store) { return store.delete(String(message.id)); }).then(function () { return { ok: true }; });
                break;
            default:
                return;
        }
        work.then(reply, function (error) {
            reply({ ok: false, error: String((error && (error.name === 'QuotaExceededError' ? 'quota' : error.message)) || error) });
        });
    });
})();
