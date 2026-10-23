/*
 * 企画のスケジュールの一覧(2026-10-23、利用者の指示「スケジュール表を作成。それを元に、どのノードで何が開催されているか表記」)。
 *
 * - 中身は地図のデータ(window.graphData.schedule。lib/event-schedule.php)。一般に出す印の行だけが届く
 * - 一般に出す行が 1 つでもあれば、左下の操作に「予定」を足す。押すと日ごとの一覧を出し、場所のある行は「地図で見る」で地点を開く
 * - 地点の詳細の「この場所の予定」は Main/app.js が出す(日時の形と開催中の判定はあちらの window.kmScheduleTimeLabel / kmScheduleIsNow)
 * - app.js が地図を組み終えた(km-map-ready)あとで動く
 */
(function () {
    'use strict';

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    let panel = null;

    function closePanel() {
        if (panel) panel.remove();
        panel = null;
    }

    function dayLabel(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        if (!m) return '';
        const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        return Number(m[2]) + '月' + Number(m[3]) + '日(' + '日月火水木金土'[date.getDay()] + ')';
    }

    function openPanel(entries) {
        if (panel) { closePanel(); return; }
        panel = el('section', 'km-history-panel km-schedule-panel glass-panel');
        panel.setAttribute('aria-label', 'スケジュール');
        const head = el('div', 'km-history-head');
        head.appendChild(el('h2', 'km-history-title', 'スケジュール'));
        const close = el('button', 'icon-btn', '×');
        close.type = 'button';
        close.setAttribute('aria-label', '閉じる');
        close.addEventListener('click', closePanel);
        head.appendChild(close);
        panel.appendChild(head);

        const now = new Date();
        const timeLabel = window.kmScheduleTimeLabel || function () { return ''; };
        const isNow = window.kmScheduleIsNow || function () { return false; };
        const nodes = (window.graphData && window.graphData.nodes) || {};
        let currentDay = null;
        let list = null;
        entries.forEach(function (entry) {
            const day = dayLabel(entry.startsAt);
            if (day !== currentDay) {
                currentDay = day;
                panel.appendChild(el('h3', 'km-schedule-day', day));
                list = el('ul', 'km-history-list');
                panel.appendChild(list);
            }
            const live = isNow(entry, now);
            const item = el('li', 'km-schedule-item' + (live ? ' is-live' : ''));
            const time = el('span', 'km-schedule-time', timeLabel(entry));
            if (live) time.appendChild(el('em', '', ' 開催中'));
            item.appendChild(time);
            item.appendChild(el('b', 'km-schedule-title', entry.title));
            const place = (entry.nodeIds || []).filter(function (id) { return nodes[id]; });
            const meta = [entry.projectNo ? '企画 ' + entry.projectNo : '', entry.organizer || ''].filter(Boolean).join('・');
            if (meta) item.appendChild(el('span', 'km-schedule-org', meta));
            if (entry.description) item.appendChild(el('span', 'km-schedule-desc', entry.description));
            place.forEach(function (id) {
                const node = nodes[id];
                const go = el('button', 'secondary-btn km-schedule-go', '地図で見る: ' + (node.alias || node.name || ''));
                go.type = 'button';
                go.addEventListener('click', function () {
                    closePanel();
                    if (typeof window.kmOpenNode === 'function') window.kmOpenNode(id);
                });
                item.appendChild(go);
            });
            // 名前が番号の一時地点(臨時の地点。2026-10-23)
            const pois = (window.graphData && window.graphData.eventPois) || [];
            (entry.poiIds || []).forEach(function (poiId) {
                const poi = pois.find(function (p) { return Number(p.id) === Number(poiId); });
                if (!poi) return;
                const go = el('button', 'secondary-btn km-schedule-go', '地図で見る: ' + ((window.kmShortPoiName || String)(poi.name || '')) + (String(poi.floor) === 'outside' ? '(外)' : '(' + poi.floor + 'F)'));
                go.type = 'button';
                go.addEventListener('click', function () {
                    closePanel();
                    if (typeof window.kmOpenPoi === 'function') window.kmOpenPoi(poi.id);
                });
                item.appendChild(go);
            });
            list.appendChild(item);
        });
        document.body.appendChild(panel);
    }

    function start() {
        const entries = (window.graphData && Array.isArray(window.graphData.schedule)) ? window.graphData.schedule : [];
        const bar = document.getElementById('km-action-bar');
        if (entries.length === 0 || !bar) return;
        const button = el('button', 'km-action-btn');
        button.type = 'button';
        button.title = 'スケジュール';
        const icon = el('span', 'km-action-icon', '📅');
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);
        button.appendChild(el('span', 'km-action-label', '予定'));
        button.addEventListener('click', function () { openPanel(entries); });
        bar.appendChild(button);
    }

    if (window.kmMapReady) {
        start();
    } else {
        document.addEventListener('km-map-ready', start, { once: true });
    }
})();
