/*
 * 高専マップを自分のサイトに埋め込む(段 15。2026-11-14。https://<ドメイン>/embed/v1.js)。
 *
 *   <div id="campus-map"></div>
 *   <script src="https://<ドメイン>/embed/v1.js"></script>
 *   <script>
 *     const map = KosenMap.embed('#campus-map', { site: '<札>', floor: '<階>', focus: '<地点>', lang: 'ja', height: 480 });
 *     map.on('ready', (info) => console.log(info.floors));      // 地図が出た
 *     map.on('select', (place) => console.log(place.name));     // 地点を押した
 *     map.on('floor', (info) => console.log(info.floor));       // 階が変わった
 *     map.focus('<地点>');                                       // 外のボタンから地点を指す
 *     map.setFloor('<階>');
 *     map.setLang('en');                                        // 言語を替える(読み込み直す)
 *     map.destroy();
 *   </script>
 *
 * 札(site)は管理画面「地図の埋め込み」で作る。**埋め込めるのは、札に登録したオリジンのページだけ**(ほかでは枠が出ない)。
 * 枠との話は postMessage。相手は地図のオリジン(この JS を読んだ所)に決め打ちし、届いたものも同じオリジン・同じ枠からだけ受ける。
 */
(function () {
    'use strict';
    if (window.KosenMap && window.KosenMap.version === 1) return;

    const script = document.currentScript;
    const mapOrigin = script && script.src ? new URL(script.src).origin : '';

    function embed(target, options) {
        const opts = options || {};
        const host = typeof target === 'string' ? document.querySelector(target) : target;
        if (!host || !mapOrigin) throw new Error('KosenMap.embed: 置き場所が見つかりません');
        if (!/^[0-9a-f]{16}$/.test(String(opts.site || ''))) throw new Error('KosenMap.embed: site(管理画面の札)を入れてください');

        const listeners = {};
        const queue = [];
        let ready = false;
        let lang = opts.lang === 'en' ? 'en' : 'ja';
        const iframe = document.createElement('iframe');

        function src(extra) {
            const params = new URLSearchParams({ site: String(opts.site), lang: lang });
            const floor = extra && extra.floor !== undefined ? extra.floor : opts.floor;
            const focus = extra && extra.focus !== undefined ? extra.focus : opts.focus;
            if (floor) params.set('floor', String(floor));
            if (focus) params.set('focus', String(focus));
            return mapOrigin + '/embed/?' + params.toString();
        }

        iframe.src = src();
        iframe.title = opts.title || (lang === 'en' ? 'Campus map' : '校内の地図');
        iframe.loading = opts.lazy === false ? 'eager' : 'lazy';
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.setAttribute('allow', '');
        // 見た目は CSSOM で入れる(埋め込む側の CSP が style 属性を禁じていても効く)
        iframe.style.width = '100%';
        iframe.style.height = (Number(opts.height) > 0 ? Number(opts.height) : 480) + 'px';
        iframe.style.border = '0';
        iframe.style.display = 'block';
        host.appendChild(iframe);

        function emit(name, detail) {
            (listeners[name] || []).slice().forEach(function (fn) {
                try { fn(detail); } catch (e) { setTimeout(function () { throw e; }); }
            });
        }

        function onMessage(event) {
            if (event.source !== iframe.contentWindow || event.origin !== mapOrigin) return;
            const data = event.data;
            if (!data || typeof data !== 'object' || data.kosenmap !== 1 || typeof data.event !== 'string') return;
            if (data.event === 'ready') {
                ready = true;
                queue.splice(0).forEach(send);
            }
            emit(data.event, data.detail);
        }
        window.addEventListener('message', onMessage);

        function send(message) {
            if (!ready) {
                queue.push(message);
                return;
            }
            if (iframe.contentWindow) iframe.contentWindow.postMessage(Object.assign({ kosenmap: 1 }, message), mapOrigin);
        }

        return {
            iframe: iframe,
            on: function (name, fn) { (listeners[name] = listeners[name] || []).push(fn); return this; },
            off: function (name, fn) { listeners[name] = (listeners[name] || []).filter(function (f) { return f !== fn; }); return this; },
            focus: function (id) { send({ cmd: 'focus', id: String(id) }); return this; },
            setFloor: function (floor) { send({ cmd: 'setFloor', floor: String(floor) }); return this; },
            setLang: function (next) {
                lang = next === 'en' ? 'en' : 'ja';
                ready = false;
                iframe.src = src();
                return this;
            },
            destroy: function () {
                window.removeEventListener('message', onMessage);
                iframe.remove();
                queue.length = 0;
            },
        };
    }

    window.KosenMap = { version: 1, embed: embed };
})();
