// sw.js
//
// HTML 展開サイトのローカルの置き場の Service Worker(2026-11-05。範囲は /l/。登録は bridge.js)。
// /l/<番号>/<パス> を、bridge.js が IndexedDB(km-html / sites)にしまったものから返す。サーバーには問い合わせない。
// 返すときのヘッダーはサーバーの見せる側(html-view.php)と同じにする(CSP は lib/html-host.php の KM_HTML_CONTENT_CSP と同じ文。
// scripts/check.php が照らす)

'use strict';

const KM_HTML_CONTENT_CSP = "default-src * data: blob: 'unsafe-inline' 'unsafe-eval'; frame-ancestors 'none'";

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

function openDb() {
    return new Promise(function (resolve, reject) {
        const request = indexedDB.open('km-html', 1);
        request.onupgradeneeded = function () {
            request.result.createObjectStore('sites', { keyPath: 'id' });
        };
        request.onsuccess = function () { resolve(request.result); };
        request.onerror = function () { reject(request.error); };
    });
}

function getSite(id) {
    return openDb().then(function (db) {
        return new Promise(function (resolve, reject) {
            const request = db.transaction('sites', 'readonly').objectStore('sites').get(id);
            request.onsuccess = function () { resolve(request.result || null); };
            request.onerror = function () { reject(request.error); };
        });
    });
}

function notFound() {
    return new Response('<!DOCTYPE html><meta charset="UTF-8"><title>見つかりません</title><p>見つかりません。HTML 展開のページから開き直してください。</p>', {
        status: 404,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': "default-src 'none'", 'Cache-Control': 'no-store' },
    });
}

function encodePath(path) {
    return path.split('/').map(encodeURIComponent).join('/');
}

function serve(url) {
    const match = /^\/l\/([0-9a-f]{24})(\/.*)?$/.exec(url.pathname);
    if (!match) return Promise.resolve(notFound());
    const id = match[1];
    if (!match[2]) return Promise.resolve(Response.redirect(url.origin + '/l/' + id + '/', 308));
    let path;
    try {
        const segments = match[2].slice(1).split('/').map(decodeURIComponent);
        if (segments.some(function (s, i) { return (s === '' && i !== segments.length - 1) || s === '.' || s === '..' || s.indexOf('/') !== -1; })) {
            return Promise.resolve(notFound());
        }
        path = segments.join('/');
    } catch (error) {
        return Promise.resolve(notFound());
    }
    return getSite(id).then(function (site) {
        if (!site) return notFound();
        if (path === '') {
            if (site.entry !== 'index.html' && site.files.has(site.entry)) {
                return Response.redirect(url.origin + '/l/' + id + '/' + encodePath(site.entry), 302);
            }
            path = 'index.html';
        } else if (path.endsWith('/')) {
            path += 'index.html';
        }
        const file = site.files.get(path);
        if (!file) return notFound();
        return new Response(file.blob, {
            status: 200,
            headers: {
                'Content-Type': file.type,
                'X-Content-Type-Options': 'nosniff',
                'Content-Security-Policy': KM_HTML_CONTENT_CSP,
                'Referrer-Policy': 'no-referrer',
                'Cache-Control': 'no-store',
            },
        });
    }, function () {
        return notFound();
    });
}

self.addEventListener('fetch', function (event) {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin || !url.pathname.startsWith('/l/')) return;
    if (event.request.method !== 'GET' && event.request.method !== 'HEAD') return;
    event.respondWith(serve(url));
});
