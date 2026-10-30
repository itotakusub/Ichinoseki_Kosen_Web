/*
 * 企画のスケジュールの一覧(2026-10-23、利用者の指示「スケジュール表を作成。それを元に、どのノードで何が開催されているか表記」)。
 *
 * - 中身は地図のデータ(window.graphData.schedule。lib/event-schedule.php)。一般に出す印の行だけが届く
 * - 一般に出す行が 1 つでもあれば、左下の操作に「予定」を足す。押すと日ごとの一覧を出し、場所のある行は「地図で見る」で地点を開く
 * - 地点の詳細の「この場所の予定」は Main/app.js が出す(日時の形と開催中の判定はあちらの window.kmScheduleTimeLabel / kmScheduleIsNow)
 * - app.js が地図を組み終えた(km-map-ready)あとで動く
 * - 一覧と「会場 × 時間」の表を切り替える(2026-10-30、振り返りのフェーズ 5 の 1。並べ方は Main/schedule-grid.js)。
 *   スタッフだけの行(教職員の組織のアカウントにだけ届く)には札を付ける
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
    // 左の引き出し(Main/km-sheet.js)に出す(2026-10-23)。無ければ今までどおり左下に浮かせる
    const sheet = window.KmSheet || null;

    function closePanel() {
        if (sheet && sheet.isOpen('schedule')) { sheet.close('schedule'); return; }
        if (panel) panel.remove();
        panel = null;
    }

    function dayLabel(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        if (!m) return '';
        const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        return Number(m[2]) + '月' + Number(m[3]) + '日(' + '日月火水木金土'[date.getDay()] + ')';
    }

    const VIEW_KEY = 'km-schedule-view';
    function savedView() {
        try { return window.localStorage.getItem(VIEW_KEY) === 'grid' ? 'grid' : 'list'; } catch (e) { return 'list'; }
    }
    function saveView(view) {
        try { window.localStorage.setItem(VIEW_KEY, view); } catch (e) { /* 覚えられなくても動く */ }
    }

    function staffTag() {
        const tag = el('span', 'km-schedule-staff', 'スタッフだけ');
        tag.title = '教職員・係のアカウントにだけ見えています';
        return tag;
    }

    /** 会場 × 時間の表(日ごと)。位置は CSSOM で入れる(CSP で style の属性は使えない) */
    function renderGrid(container, entries) {
        const grid = window.KmScheduleGrid;
        const nodes = (window.graphData && window.graphData.nodes) || {};
        const pois = (window.graphData && window.graphData.eventPois) || [];
        const timeLabel = window.kmScheduleTimeLabel || function () { return ''; };
        const isNow = window.kmScheduleIsNow || function () { return false; };
        const now = new Date();
        if (!grid) return;
        grid.layout(entries, nodes, pois).forEach(function (day) {
            container.appendChild(el('h3', 'km-schedule-day', dayLabel(day.day)));
            const scroller = el('div', 'km-schedule-grid-scroll');
            const table = el('div', 'km-schedule-grid');
            table.setAttribute('role', 'table');
            table.setAttribute('aria-label', dayLabel(day.day) + 'の会場と時間');
            table.style.gridTemplateColumns = '3.2em repeat(' + day.columns + ', minmax(7.5em, 1fr))';
            table.style.gridTemplateRows = 'auto repeat(' + day.rows + ', 2.6em)';
            // 見出し(会場)
            const corner = el('div', 'km-schedule-grid-corner');
            corner.style.gridRow = '1';
            corner.style.gridColumn = '1';
            table.appendChild(corner);
            day.venues.forEach(function (venue) {
                const head = el('div', 'km-schedule-grid-venue', venue.name);
                head.setAttribute('role', 'columnheader');
                head.style.gridRow = '1';
                head.style.gridColumn = (venue.col + 1) + ' / span ' + venue.lanes;
                table.appendChild(head);
            });
            // 時刻(1 時間ごとに札、30 分は線だけ)
            for (let row = 1; row <= day.rows; row++) {
                const label = grid.slotLabel(day.startMin, row);
                const cell = el('div', 'km-schedule-grid-time' + (label.endsWith(':00') ? ' is-hour' : ''), label.endsWith(':00') ? label : '');
                cell.style.gridRow = String(row + 1);
                cell.style.gridColumn = '1 / -1';
                table.appendChild(cell);
            }
            day.cells.forEach(function (cellInfo) {
                const entry = cellInfo.entry;
                const venue = day.venues.find(function (v) { return v.key === cellInfo.venueKey; });
                const live = isNow(entry, now);
                const box = el('button', 'km-schedule-grid-item' + (live ? ' is-live' : '') + (entry.staffOnly ? ' is-staff' : ''));
                box.type = 'button';
                box.style.gridRow = (cellInfo.rowStart + 1) + ' / ' + (cellInfo.rowEnd + 1);
                box.style.gridColumn = String(cellInfo.col + 1);
                // 日付は見出しにあるので、マスには時刻だけ
                if (entry.staffOnly) box.appendChild(staffTag());
                box.appendChild(el('b', '', entry.title));
                box.appendChild(el('span', 'km-schedule-grid-at', String(entry.startsAt).slice(11, 16) + '〜' + (entry.endsAt && String(entry.endsAt).slice(0, 10) === String(entry.startsAt).slice(0, 10) ? String(entry.endsAt).slice(11, 16) : '') + (live ? ' 開催中' : '')));
                if (entry.projectNo) box.appendChild(el('span', 'km-schedule-grid-no', '企画 ' + entry.projectNo));
                box.title = [entry.title, timeLabel(entry), venue ? venue.name : '', entry.organizer || ''].filter(Boolean).join('\n');
                if (venue && (venue.nodeId || venue.poiId !== undefined)) {
                    box.addEventListener('click', function () {
                        closePanel();
                        if (venue.nodeId && typeof window.kmOpenNode === 'function') window.kmOpenNode(venue.nodeId);
                        else if (venue.poiId !== undefined && typeof window.kmOpenPoi === 'function') window.kmOpenPoi(venue.poiId);
                    });
                } else {
                    box.disabled = true;
                }
                table.appendChild(box);
            });
            scroller.appendChild(table);
            container.appendChild(scroller);
        });
    }

    function openPanel(entries) {
        if (panel) { closePanel(); return; }
        if (sheet) {
            panel = el('div', 'km-schedule-content');
        } else {
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
        }

        // 一覧 / 会場 × 時間 の切り替え
        const switcher = el('div', 'km-schedule-switch');
        switcher.setAttribute('role', 'group');
        switcher.setAttribute('aria-label', '予定の見せ方');
        const body = el('div', 'km-schedule-body');
        const buttons = {};
        [['list', '一覧'], ['grid', '会場 × 時間']].forEach(function (pair) {
            const button = el('button', 'km-schedule-switch-btn', pair[1]);
            button.type = 'button';
            button.addEventListener('click', function () { saveView(pair[0]); show(pair[0]); });
            buttons[pair[0]] = button;
            switcher.appendChild(button);
        });
        panel.appendChild(switcher);
        panel.appendChild(body);
        function show(view) {
            Object.keys(buttons).forEach(function (key) { buttons[key].setAttribute('aria-pressed', key === view ? 'true' : 'false'); });
            body.textContent = '';
            if (view === 'grid' && window.KmScheduleGrid) renderGrid(body, entries);
            else renderList(body, entries);
        }
        show(savedView());
        if (sheet) {
            sheet.open({ key: 'schedule', title: 'スケジュール', content: panel, onClose: function () { panel = null; } });
        } else {
            document.body.appendChild(panel);
        }
    }

    function renderList(container, entries) {
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
                container.appendChild(el('h3', 'km-schedule-day', day));
                list = el('ul', 'km-history-list');
                container.appendChild(list);
            }
            const live = isNow(entry, now);
            const item = el('li', 'km-schedule-item' + (live ? ' is-live' : ''));
            const time = el('span', 'km-schedule-time', timeLabel(entry));
            if (live) time.appendChild(el('em', '', ' 開催中'));
            item.appendChild(time);
            item.appendChild(el('b', 'km-schedule-title', entry.title));
            if (entry.staffOnly) item.appendChild(staffTag());
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
