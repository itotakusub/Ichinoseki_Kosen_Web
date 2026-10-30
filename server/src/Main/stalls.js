/*
 * 屋台のメニューと混み具合(2026-10-30、高専祭の振り返りのフェーズ 5 の 2。lib/stalls.php)。
 *
 * - 初めの値は地図のデータ(window.graphData.stalls)。場所(nodeIds・poiIds)もあちらだけが持つ
 * - 画面が見えている間だけ、30 秒ごとに /api/stalls.php で今の値を読み直し、開いている詳細の枠
 *   (Main/app.js の stallSlotHtml が置く data-km-stall)の中を描き直す。隠れている間は読まない
 * - 地点の詳細の中身は window.kmStallHtml(鍵)。鍵は地点の id か 'poi:<一時地点の id>'
 * - 形の決まり(何人待ち・何分前・売り切れ)は view() に寄せた。DOM を使わない(scripts/js/stalls.test.js)
 */
(function (root) {
    'use strict';

    const POLL_MS = 30000;

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    /** 「3 分前」。読めなければ '' */
    function ago(iso, now) {
        const at = Date.parse(String(iso || ''));
        if (!Number.isFinite(at)) return '';
        const minutes = Math.max(0, Math.floor((now - at) / 60000));
        if (minutes < 1) return 'たった今';
        if (minutes < 60) return minutes + ' 分前';
        return Math.floor(minutes / 60) + ' 時間前';
    }

    /**
     * 1 つの屋台の見せ方。**純粋関数**。出すものが無ければ null
     * @returns {{name:string, wait:string, waitLevel:string, menu:Array<{name:string, price:string, soldOut:boolean}>, note:string, at:string}|null}
     */
    function view(stall, now) {
        const menu = Array.isArray(stall.menu) ? stall.menu : [];
        const waiting = typeof stall.waiting === 'number' ? stall.waiting : null;
        const note = String(stall.note || '');
        if (menu.length === 0 && waiting === null && note === '') return null;
        return {
            name: String(stall.name || ''),
            wait: waiting === null ? '' : (waiting === 0 ? '待ちなし' : waiting + ' 人待ち'),
            // 混み具合の色(0〜4 人 / 5〜14 人 / 15 人〜)
            waitLevel: waiting === null ? '' : (waiting < 5 ? 'low' : (waiting < 15 ? 'mid' : 'high')),
            menu: menu.map(function (item) {
                return { name: String(item.name || ''), price: typeof item.price === 'number' ? item.price + ' 円' : '', soldOut: item.soldOut === true };
            }),
            note: note,
            at: ago(stall.waitingAt || stall.menuAt, now),
        };
    }

    const api = { view: view, ago: ago };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
        return;
    }
    root.KmStalls = api;

    // ---- ここから下はブラウザだけ ----
    const byId = new Map();
    const byKey = new Map();

    function index(list) {
        byId.clear();
        byKey.clear();
        (list || []).forEach(function (stall) {
            byId.set(Number(stall.id), stall);
            (stall.nodeIds || []).forEach(function (id) {
                const key = String(id);
                if (!byKey.has(key)) byKey.set(key, []);
                byKey.get(key).push(Number(stall.id));
            });
            (stall.poiIds || []).forEach(function (id) {
                const key = 'poi:' + Number(id);
                if (!byKey.has(key)) byKey.set(key, []);
                byKey.get(key).push(Number(stall.id));
            });
        });
    }

    function html(key) {
        const now = Date.now();
        return (byKey.get(String(key)) || []).map(function (id) {
            const v = view(byId.get(id) || {}, now);
            if (!v) return '';
            const menu = v.menu.length === 0 ? '' : '<ul class="km-stall-menu">' + v.menu.map(function (item) {
                return '<li' + (item.soldOut ? ' class="is-sold-out"' : '') + '><span>' + escapeHtml(item.name) + '</span>'
                    + '<b>' + (item.soldOut ? '売り切れ' : escapeHtml(item.price)) + '</b></li>';
            }).join('') + '</ul>';
            return '<div class="km-stall" aria-label="屋台の今">'
                + '<div class="km-stall-head"><span>🍽 ' + escapeHtml(v.name) + '</span>'
                + (v.wait ? '<b class="km-stall-wait is-' + v.waitLevel + '">' + escapeHtml(v.wait) + '</b>' : '') + '</div>'
                + menu
                + (v.note ? '<p class="km-stall-note">' + escapeHtml(v.note) + '</p>' : '')
                + (v.at ? '<span class="km-stall-at">' + escapeHtml(v.at) + 'に更新</span>' : '')
                + '</div>';
        }).join('');
    }
    root.kmStallHtml = html;

    function redraw() {
        document.querySelectorAll('[data-km-stall]').forEach(function (slot) {
            slot.innerHTML = html(slot.getAttribute('data-km-stall'));
        });
    }

    async function refresh() {
        if (document.visibilityState !== 'visible') return;
        try {
            const response = await fetch('/api/stalls.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const body = await response.json();
            if (!body || !Array.isArray(body.stalls)) return;
            body.stalls.forEach(function (fresh) {
                const stall = byId.get(Number(fresh.id));
                // 地図のデータに無い屋台(場所を出していない)は捨てる
                if (stall) Object.assign(stall, { menu: fresh.menu, waiting: fresh.waiting, note: fresh.note, menuAt: fresh.menuAt, waitingAt: fresh.waitingAt });
            });
            redraw();
        } catch (e) {
            // 読めなくても、前の値のまま出す
        }
    }

    function start() {
        const list = (root.graphData && Array.isArray(root.graphData.stalls)) ? root.graphData.stalls : [];
        index(list);
        if (list.length === 0) return;
        redraw();
        setInterval(refresh, POLL_MS);
        // 戻ってきたらすぐ読む(隠れている間は読まない)
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') refresh(); });
    }

    if (root.kmMapReady) {
        start();
    } else {
        document.addEventListener('km-map-ready', start, { once: true });
    }
}(typeof window !== 'undefined' ? window : globalThis));
