/*
 * 地図の履歴とブックマーク(2026-10-23、利用者の指示「両方に履歴とブックマーク」「端末の中だけで、ログインしているなら同期」
 * 「アプリ、または Website でルート履歴が残っている場合には確認画面を出す」)。
 *
 * - 残すもの: ブックマーク・開いた場所・検索した言葉(出発地・目的地に入れた言葉)・経路・表示調整
 * - 置き場所: この端末の localStorage(KEY)。**サインインしているときだけ** api/user-map-data.php で同じアカウントの端末と揃える
 * - 形と混ぜ方はサーバー(lib/user-map-data.php)とアプリ(UserMapData.kt)と同じ: 項目ごとに新しい方、消した印・全部消した時刻は新しい方
 * - 経路の確かめ: 開いたとき(前回の経路を出すか)・サインインして揃える前(経路をアカウントに置くか)・消すとき・ログアウトするとき
 * - 展示の PC(テスト用アカウント)では使わない(来場者の間で履歴が見えないように)
 *
 * app.js とのつなぎ: 地点の詳細の ☆(data-km-bookmark)・window の km:node-opened / km:route / km:map-ready、
 * window.kmOpenNode(id)・window.setAsLocation(id, 'start'|'end')
 */
(function () {
    'use strict';

    const KEY = 'km-user-map-data';
    const LISTS = { bookmarks: 200, places: 50, searches: 30, routes: 20 };
    const REMOVED_MAX = 300;
    const TUNING_KEY = 'km-map-tuning-v1';
    const meta = (name) => (document.querySelector('meta[name="' + name + '"]') || {}).content || '';

    if (meta('km-test-mode') === '1') return;
    const signedIn = meta('km-user-key') !== '';
    const userKey = meta('km-user-key');
    const choiceKey = 'km-user-map-route-choice:' + userKey;

    /* ------------------------------------------------ 形と混ぜ方(lib/user-map-data.php と同じ) */

    function itemKey(list, item) {
        if (!item || typeof item !== 'object') return null;
        if (list === 'searches') {
            const q = typeof item.q === 'string' ? item.q.trim() : '';
            return q !== '' && q.length <= 64 && !/[\x00-\x1F\x7F]/.test(q) ? q : null;
        }
        if (list === 'routes') {
            return isId(item.from) && isId(item.to) && item.from !== item.to ? item.from + '>' + item.to : null;
        }
        return isId(item.uuid) ? item.uuid : null;
    }
    function isId(value) { return typeof value === 'string' && /^[0-9A-Za-z_-]{1,64}$/.test(value); }
    function isTime(value) { return Number.isInteger(value) && value >= 1e12 && value <= 4e12; }

    function normalize(input) {
        const src = input && typeof input === 'object' ? input : {};
        const doc = { version: 1, removed: {}, cleared: {}, settings: {} };
        Object.keys(LISTS).forEach(function (list) {
            const byKey = {};
            (Array.isArray(src[list]) ? src[list] : []).forEach(function (item) {
                const key = itemKey(list, item);
                if (key === null || !isTime(item.at)) return;
                const clean = list === 'searches' ? { q: key, at: item.at }
                    : list === 'routes' ? { from: item.from, to: item.to, at: item.at }
                        : { uuid: key, at: item.at };
                if (!byKey[key] || byKey[key].at < item.at) byKey[key] = clean;
            });
            doc[list] = Object.values(byKey).sort(function (a, b) { return b.at - a.at; }).slice(0, LISTS[list]);
            const removed = {};
            const rawRemoved = src.removed && src.removed[list] && typeof src.removed[list] === 'object' ? src.removed[list] : {};
            Object.keys(rawRemoved).sort(function (a, b) { return rawRemoved[b] - rawRemoved[a]; }).slice(0, REMOVED_MAX).forEach(function (key) {
                if (key !== '' && key.length <= 130 && isTime(rawRemoved[key])) removed[key] = rawRemoved[key];
            });
            doc.removed[list] = removed;
            if (src.cleared && isTime(src.cleared[list])) doc.cleared[list] = src.cleared[list];
        });
        ['android', 'web'].forEach(function (platform) {
            const s = src.settings && src.settings[platform];
            if (s && isTime(s.at) && s.values && typeof s.values === 'object' && !Array.isArray(s.values)) {
                doc.settings[platform] = { values: s.values, at: s.at };
            }
        });
        return prune(doc);
    }

    function prune(doc) {
        Object.keys(LISTS).forEach(function (list) {
            const cleared = doc.cleared[list] || 0;
            const removed = doc.removed[list] || {};
            doc[list] = doc[list].filter(function (item) {
                const key = itemKey(list, item);
                return item.at > cleared && !(key in removed && item.at <= removed[key]);
            });
        });
        return doc;
    }

    function merge(a, b) {
        const merged = { version: 1, removed: {}, cleared: {}, settings: {} };
        Object.keys(LISTS).forEach(function (list) {
            merged[list] = (a[list] || []).concat(b[list] || []);
            const removed = Object.assign({}, (a.removed || {})[list] || {});
            Object.entries((b.removed || {})[list] || {}).forEach(function (entry) {
                removed[entry[0]] = Math.max(removed[entry[0]] || 0, entry[1]);
            });
            merged.removed[list] = removed;
            const cleared = Math.max((a.cleared || {})[list] || 0, (b.cleared || {})[list] || 0);
            if (cleared > 0) merged.cleared[list] = cleared;
        });
        ['android', 'web'].forEach(function (platform) {
            const sa = (a.settings || {})[platform];
            const sb = (b.settings || {})[platform];
            const pick = !sa ? sb : (!sb ? sa : (sb.at > sa.at ? sb : sa));
            if (pick) merged.settings[platform] = pick;
        });
        return normalize(merged);
    }

    /* ------------------------------------------------ 端末の中 */

    let doc = load();
    function load() {
        try {
            return normalize(JSON.parse(window.localStorage.getItem(KEY) || '{}'));
        } catch (e) {
            return normalize({});
        }
    }
    function save() {
        try {
            window.localStorage.setItem(KEY, JSON.stringify(doc));
        } catch (e) {
            // 覚えられなくても、このページの間は使える
        }
        scheduleSync();
        renderPanel();
    }
    function add(list, item) {
        doc[list] = [Object.assign({}, item, { at: Date.now() })].concat(doc[list]);
        doc = normalize(doc);
        save();
    }
    function removeItem(list, key) {
        doc.removed[list][key] = Date.now();
        doc = prune(doc);
        save();
    }
    function clearLists(lists) {
        const now = Date.now();
        lists.forEach(function (list) { doc.cleared[list] = now; });
        doc = prune(doc);
        save();
    }
    const isBookmarked = (id) => doc.bookmarks.some(function (b) { return b.uuid === id; });

    /* ------------------------------------------------ 同期(サインインしているときだけ) */

    let syncTimer = null;
    function scheduleSync() {
        if (!signedIn || routeChoice() === null && doc.routes.length > 0) return;
        window.clearTimeout(syncTimer);
        syncTimer = window.setTimeout(sync, 1500);
    }
    function routeChoice() {
        try { return window.localStorage.getItem(choiceKey); } catch (e) { return null; }
    }
    function captureTuning() {
        let raw = null;
        try { raw = window.localStorage.getItem(TUNING_KEY); } catch (e) { /* 読めない */ }
        const values = raw ? (function () { try { return JSON.parse(raw); } catch (e) { return null; } })() : null;
        if (!values || typeof values !== 'object') return;
        const current = doc.settings.web;
        // まだ一度も揃えていなければ先に受け取る(このブラウザの設定で、ほかの端末の設定を上書きしない)。sync の後で置く
        if (current && JSON.stringify(current.values) !== JSON.stringify(values)) {
            doc.settings.web = { values: values, at: Date.now() };
        }
    }
    function currentTuning() {
        try {
            const raw = window.localStorage.getItem(TUNING_KEY);
            const values = raw ? JSON.parse(raw) : null;
            return values && typeof values === 'object' ? values : null;
        } catch (e) {
            return null;
        }
    }
    function sync() {
        if (!signedIn) return;
        captureTuning();
        const localOnlyRoutes = routeChoice() === 'local';
        const outgoing = JSON.parse(JSON.stringify(doc));
        if (localOnlyRoutes) {
            // 経路はこの端末だけ(送らない・アカウントの経路も取り込まない)
            outgoing.routes = [];
            outgoing.removed.routes = {};
            delete outgoing.cleared.routes;
        }
        const before = JSON.stringify(doc.settings.web || null);
        fetch('/api/user-map-data.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': meta('km-csrf') },
            body: JSON.stringify({ data: outgoing }),
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (body) {
            if (!body || !body.data) return;
            const merged = merge(doc, normalize(body.data));
            if (localOnlyRoutes) {
                merged.routes = doc.routes;
                merged.removed.routes = doc.removed.routes;
                if (doc.cleared.routes) merged.cleared.routes = doc.cleared.routes; else delete merged.cleared.routes;
            }
            doc = merged;
            try { window.localStorage.setItem(KEY, JSON.stringify(doc)); } catch (e) { /* 覚えられない */ }
            // ほかの端末で変えた表示調整を引き継ぐ(次に開いたときから効く)
            const web = doc.settings.web;
            const mine = currentTuning();
            if (web && JSON.stringify(web) !== before && JSON.stringify(web.values) !== JSON.stringify(mine)) {
                try { window.localStorage.setItem(TUNING_KEY, JSON.stringify(web.values)); } catch (e) { /* 覚えられない */ }
                toast('ほかの端末の表示調整を引き継ぎました(再読み込みで反映されます)');
            } else if (!web && mine) {
                // アカウントにまだ無い: このブラウザの表示調整を置く(次の同期で送る)
                doc.settings.web = { values: mine, at: Date.now() };
                try { window.localStorage.setItem(KEY, JSON.stringify(doc)); } catch (e) { /* 覚えられない */ }
                scheduleSync();
            }
            renderPanel();
        }).catch(function () {
            // 圏外など。端末の中には残っている
        });
    }

    /* ------------------------------------------------ 画面の部品 */

    const nodes = () => (window.graphData && window.graphData.nodes) || {};
    const nodeName = (id) => { const n = nodes()[id]; return n ? (n.alias || n.name || '') : ''; };
    const floorName = (id) => { const n = nodes()[id]; return !n ? '' : (String(n.floor) === 'outside' ? '外' : n.floor + 'F'); };

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function button(text, className, onClick) {
        const b = el('button', className, text);
        b.type = 'button';
        b.addEventListener('click', onClick);
        return b;
    }

    function toast(text) {
        const box = el('div', 'km-history-toast', text);
        box.setAttribute('role', 'status');
        document.body.appendChild(box);
        window.setTimeout(function () { box.remove(); }, 4000);
    }

    /** 確かめる画面。choices は [{label, value, primary}]。押したものの value を返す(閉じたら null) */
    function ask(title, message, choices) {
        return new Promise(function (resolve) {
            const scrim = el('div', 'km-history-scrim');
            const dialog = el('div', 'km-history-dialog glass-panel');
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.appendChild(el('h3', 'km-history-dialog-title', title));
            dialog.appendChild(el('p', 'km-history-dialog-text', message));
            const row = el('div', 'km-history-dialog-actions');
            const finish = function (value) { scrim.remove(); document.removeEventListener('keydown', onKey); resolve(value); };
            const onKey = function (event) { if (event.key === 'Escape') finish(null); };
            choices.forEach(function (choice) {
                row.appendChild(button(choice.label, choice.primary ? 'primary-btn' : 'secondary-btn', function () { finish(choice.value); }));
            });
            dialog.appendChild(row);
            scrim.appendChild(dialog);
            scrim.addEventListener('click', function (event) { if (event.target === scrim) finish(null); });
            document.addEventListener('keydown', onKey);
            document.body.appendChild(scrim);
            const first = row.querySelector('button');
            if (first) first.focus();
        });
    }

    const routeLabel = (route) => (nodeName(route.from) || '(地図に無い地点)') + ' → ' + (nodeName(route.to) || '(地図に無い地点)');

    function showRoute(route) {
        if (!nodes()[route.from] || !nodes()[route.to] || typeof window.setAsLocation !== 'function') {
            toast('その経路の地点は、いまの地図にありません');
            return;
        }
        closePanel();
        window.setAsLocation(route.from, 'start');
        window.setAsLocation(route.to, 'end');
    }

    /* ------------------------------------------------ 履歴の面 */

    let panel = null;
    let tab = 'bookmarks';
    const TABS = [['bookmarks', 'ブックマーク'], ['places', '開いた場所'], ['routes', '経路'], ['searches', '検索した言葉']];

    /*
     * 面は左の引き出し(Main/km-sheet.js)に出す(2026-10-23、利用者の指示「ダウンロード・設定のように引き出しで」)。
     * 引き出しが無ければ今までどおり左下に浮かせる
     */
    const sheet = window.KmSheet || null;
    function openPanel() {
        if (panel) { closePanel(); return; }
        if (sheet) {
            panel = el('div', 'km-history-content');
            renderPanel();
            sheet.open({ key: 'history', title: '履歴とブックマーク', content: panel, onClose: function () { panel = null; } });
            return;
        }
        panel = el('section', 'km-history-panel glass-panel');
        panel.setAttribute('aria-label', '履歴とブックマーク');
        document.body.appendChild(panel);
        renderPanel();
    }
    function closePanel() {
        if (sheet && sheet.isOpen('history')) { sheet.close('history'); return; }
        if (panel) panel.remove();
        panel = null;
    }

    function renderPanel() {
        if (!panel) return;
        panel.innerHTML = '';
        if (!sheet) {
            const head = el('div', 'km-history-head');
            head.appendChild(el('h2', 'km-history-title', '履歴とブックマーク'));
            head.appendChild(button('×', 'icon-btn', closePanel));
            panel.appendChild(head);
        }
        panel.appendChild(el('p', 'km-history-note', signedIn
            ? (routeChoice() === 'local' ? 'アカウントで揃えています(経路はこの端末だけ)。' : 'アカウントで同じアカウントの端末と揃えています。')
            : 'この端末の中だけに残ります(サインインすると端末どうしで揃えられます)。'));
        const tabs = el('div', 'km-history-tabs');
        TABS.forEach(function (entry) {
            const b = button(entry[1] + '(' + doc[entry[0]].length + ')', 'km-history-tab' + (tab === entry[0] ? ' active' : ''), function () { tab = entry[0]; renderPanel(); });
            b.setAttribute('aria-pressed', String(tab === entry[0]));
            tabs.appendChild(b);
        });
        panel.appendChild(tabs);

        const list = el('ul', 'km-history-list');
        const items = doc[tab];
        items.forEach(function (item) {
            const key = itemKey(tab, item);
            let label;
            let sub = '';
            let onOpen;
            if (tab === 'routes') {
                label = routeLabel(item);
                onOpen = function () { showRoute(item); };
            } else if (tab === 'searches') {
                label = item.q;
                onOpen = function () {
                    // 上の検索の窓に入れて候補を出す(2026-10-23。以前は目的地の欄)
                    const input = document.getElementById('km-place-input') || document.getElementById('end-input');
                    closePanel();
                    if (input) { input.value = item.q; input.focus(); input.dispatchEvent(new Event('input')); }
                };
            } else {
                // 地図に無い地点(消えた・見てよくない地点)は出さない
                if (!nodes()[item.uuid]) return;
                label = nodeName(item.uuid);
                sub = floorName(item.uuid);
                onOpen = function () { closePanel(); if (typeof window.kmOpenNode === 'function') window.kmOpenNode(item.uuid); };
            }
            const li = el('li', 'km-history-item');
            const open = button('', 'km-history-open', onOpen);
            open.appendChild(el('span', 'km-history-label', label));
            if (sub) open.appendChild(el('small', 'km-history-sub', sub));
            li.appendChild(open);
            const remove = button('×', 'km-history-remove', function () { removeItem(tab, key); });
            remove.setAttribute('aria-label', '「' + label + '」を消す');
            li.appendChild(remove);
            list.appendChild(li);
        });
        if (list.children.length === 0) {
            panel.appendChild(el('p', 'km-history-empty', tab === 'bookmarks' ? '地点の詳細の ☆ でブックマークできます。' : 'まだありません。'));
        } else {
            panel.appendChild(list);
        }
        panel.appendChild(button('履歴を全部消す', 'secondary-btn km-history-clear', clearAll));
    }

    async function clearAll() {
        const lists = ['places', 'searches'];
        if (doc.routes.length > 0) {
            const answer = await ask('履歴を消す', '開いた場所と検索した言葉を消します。経路の履歴(' + doc.routes.length + ' 件)はどうしますか？'
                + (signedIn ? '(アカウントからも消えます)' : ''), [
                { label: '経路も消す', value: 'all', primary: true },
                { label: '経路は残す', value: 'keep' },
                { label: 'やめる', value: null },
            ]);
            if (answer === null) return;
            if (answer === 'all') lists.push('routes');
        } else {
            const answer = await ask('履歴を消す', '開いた場所と検索した言葉を消します(ブックマークは残ります)。', [
                { label: '消す', value: true, primary: true },
                { label: 'やめる', value: null },
            ]);
            if (answer === null) return;
        }
        clearLists(lists);
        toast('履歴を消しました');
    }

    /* ------------------------------------------------ つなぎ */

    // 地点の詳細を開いた → 開いた場所に残し、☆ の状態を合わせる
    window.addEventListener('km:node-opened', function (event) {
        const id = event.detail && event.detail.id;
        if (!isId(id)) return;
        add('places', { uuid: id });
        syncBookmarkButtons();
    });
    function syncBookmarkButtons() {
        document.querySelectorAll('[data-km-bookmark]').forEach(function (b) {
            const on = isBookmarked(b.dataset.kmBookmark);
            b.setAttribute('aria-pressed', String(on));
            b.textContent = on ? '★ ブックマーク済み' : '☆ ブックマーク';
        });
    }
    document.addEventListener('click', function (event) {
        const b = event.target.closest('[data-km-bookmark]');
        if (!b) return;
        const id = b.dataset.kmBookmark;
        if (!isId(id)) return;
        if (isBookmarked(id)) removeItem('bookmarks', id); else add('bookmarks', { uuid: id });
        syncBookmarkButtons();
    });

    // 上の検索の窓で場所を選んだ → 検索した言葉に残す(2026-10-23。Main/app.js の km:search)
    window.addEventListener('km:search', function (event) {
        const text = event.detail && event.detail.text;
        if (typeof text !== 'string' || text.trim() === '') return;
        add('searches', { q: text.trim().slice(0, 64) });
    });

    // 経路を引いた → 経路と、入れた言葉を残す
    window.addEventListener('km:route', function (event) {
        const d = event.detail || {};
        if (!isId(d.from) || !isId(d.to) || d.from === d.to) return;
        // 共有のリンクから開いた経路は残さない(2026-10-29)。残すと、次に普通に開いたとき「前回の経路」として聞いていた
        if (d.shared) return;
        [d.fromText, d.toText].forEach(function (text) {
            if (typeof text === 'string' && text.trim() !== '') doc.searches = [{ q: text.trim().slice(0, 64), at: Date.now() }].concat(doc.searches);
        });
        add('routes', { from: d.from, to: d.to });
    });

    // ログアウト: 経路の履歴があれば、この端末から消すかを確かめる
    document.querySelectorAll('form.km-signout-form').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            if (form.dataset.kmAsked === '1' || doc.routes.length === 0) return;
            event.preventDefault();
            const answer = await ask('ログアウト', 'この端末に経路の履歴が ' + doc.routes.length + ' 件あります。この端末から消しますか？', [
                { label: 'この端末から消す', value: 'clear', primary: true },
                { label: '残す', value: 'keep' },
            ]);
            if (answer === null) return;
            if (answer === 'clear') {
                // 端末の中だけ消す(アカウントの分は残す。送らない)
                doc.routes = [];
                try { window.localStorage.setItem(KEY, JSON.stringify(doc)); } catch (e) { /* 覚えられない */ }
            }
            form.dataset.kmAsked = '1';
            form.submit();
        });
    });

    // 左下の操作に「履歴」を足す(map-tuning.js の「表示調整」と同じ形)
    const bar = document.getElementById('km-action-bar');
    if (bar) {
        const historyButton = el('button', 'km-action-btn');
        historyButton.type = 'button';
        historyButton.title = '履歴とブックマーク';
        historyButton.appendChild(el('span', 'km-action-icon', '🕘'));
        historyButton.firstChild.setAttribute('aria-hidden', 'true');
        historyButton.appendChild(el('span', 'km-action-label', '履歴'));
        historyButton.addEventListener('click', openPanel);
        bar.appendChild(historyButton);
    }

    // 地図が組み上がってから: サインイン後の確かめ → 前回の経路を出すかの確かめ → 同期
    async function onReady() {
        // 読み込み画面が消えてから尋ねる(重なって読めない)。app.js が閉じるときに km-map-ready を出す
        if (!window.kmMapReady) {
            await new Promise(function (resolve) { document.addEventListener('km-map-ready', resolve, { once: true }); });
            await new Promise(function (resolve) { window.setTimeout(resolve, 400); });
        }
        /*
         * 共有のリンクから来たときは**前回の経路のことは何も尋ねない**(2026-10-24、利用者の指示「共有から開いた時は、
         * チュートリアル、前回の経路を全て無視」)。開いた場所や経路の上に問いかけが重なるため。
         * 同期の答え(残す/この端末だけ)もここでは決めない —— 次に普通に開いたときに尋ねる。答えが決まっている人の同期は続ける
         */
        if (document.querySelector('meta[name="km-from-share"]')) {
            try { window.sessionStorage.setItem('km-route-resume-asked', '1'); } catch (e) { /* 覚えられない */ }
            if (signedIn && routeChoice() !== null) sync();
            return;
        }
        // 尋ねるのは、サインインしたときにこの端末に経路の履歴があるときだけ。無ければ「残す」にする(尋ねる中身が無い)
        if (signedIn && routeChoice() === null && doc.routes.length === 0) {
            try { window.localStorage.setItem(choiceKey, 'keep'); } catch (e) { /* 覚えられない */ }
        }
        if (signedIn && routeChoice() === null && doc.routes.length > 0) {
            const answer = await ask('経路の履歴', 'この端末に経路の履歴が ' + doc.routes.length + ' 件あります。アカウントに残して、ほかの端末と揃えますか？', [
                { label: '残す', value: 'keep', primary: true },
                { label: 'この端末だけ', value: 'local' },
            ]);
            try { window.localStorage.setItem(choiceKey, answer === 'keep' ? 'keep' : 'local'); } catch (e) { /* 覚えられない */ }
        }
        let asked = false;
        try { asked = window.sessionStorage.getItem('km-route-resume-asked') === '1'; } catch (e) { /* 読めない */ }
        const last = doc.routes[0];
        if (!asked && last && nodes()[last.from] && nodes()[last.to]) {
            try { window.sessionStorage.setItem('km-route-resume-asked', '1'); } catch (e) { /* 覚えられない */ }
            const answer = await ask('前回の経路', '前回の経路(' + routeLabel(last) + ')を表示しますか？', [
                { label: '表示する', value: true, primary: true },
                { label: 'しない', value: null },
            ]);
            if (answer) showRoute(last);
        }
        if (signedIn) sync();
    }
    // app.js が地図データを組み終えたときに出す(地点の名前が引けるようになってから尋ねる)
    if (Object.keys(nodes()).length > 0) onReady(); else window.addEventListener('km:map-ready', onReady, { once: true });

    // 検査(src/scripts/js/)から呼ぶ
    window.KmUserHistory = { enabled: true, normalize: normalize, merge: merge, isBookmarked: isBookmarked };
})();
