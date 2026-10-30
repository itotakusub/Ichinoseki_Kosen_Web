/*
 * 予定の「会場 × 時間」の表の並べ方(2026-10-30、高専祭の振り返りのフェーズ 5 の 1。配布資料と同じ形)。
 *
 * - 列は会場(会場の地点 → 番号の地点 → 番号の一時地点の順で、先頭の 1 つ)。場所の無い行は「その他」の列(右端)
 * - 行は 30 分ずつ。日ごとに分け、その日の一番早い始まりから一番遅い終わりまで
 * - 同じ会場で時間が重なるときは、その会場の列を横に分ける(レーン)
 * - DOM を使わない純粋関数(scripts/js/schedule-grid.test.js)。描くのは Main/event-schedule.js
 */
(function (root) {
    'use strict';

    const SLOT_MINUTES = 30;
    const DAY_MINUTES = 24 * 60;
    const OTHER_KEY = '';

    /** 「2026-11-03 10:30:00」→ { day: '2026-11-03', min: 630 }。形が違えば null */
    function parseTime(value) {
        const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
        if (!m) return null;
        return { day: m[1], min: Number(m[2]) * 60 + Number(m[3]) };
    }

    /** 行の会場(列の鍵と名前) */
    function venueOf(entry, nodes, pois) {
        const ids = [];
        if (entry.venueNodeId) ids.push(String(entry.venueNodeId));
        (entry.nodeIds || []).forEach(function (id) { ids.push(String(id)); });
        for (let i = 0; i < ids.length; i++) {
            const node = nodes[ids[i]];
            if (node) return { key: 'node:' + ids[i], name: String(node.alias || node.name || ''), nodeId: ids[i] };
        }
        const poiId = (entry.poiIds || [])[0];
        const poi = poiId === undefined ? null : (pois || []).find(function (p) { return Number(p.id) === Number(poiId); });
        if (poi) return { key: 'poi:' + Number(poi.id), name: String(poi.name || ''), poiId: Number(poi.id) };
        return { key: OTHER_KEY, name: 'その他' };
    }

    /**
     * @param {Array} entries 地図のデータの schedule(始まりの順)
     * @param {Object} nodes  graphData.nodes(id → 地点)
     * @param {Array} pois    graphData.eventPois
     * @returns {Array<{day:string, startMin:number, rows:number, columns:number, venues:Array, cells:Array}>}
     *   venues: { key, name, nodeId?, poiId?, col(1 始まり), lanes }
     *   cells:  { entry, venueKey, col, rowStart(1 始まり), rowEnd(含まない) }
     */
    function layout(entries, nodes, pois) {
        const byDay = new Map();
        (entries || []).forEach(function (entry) {
            const start = parseTime(entry.startsAt);
            if (!start) return;
            const end = parseTime(entry.endsAt);
            // 終わりが無ければ 30 分・日をまたぐならその日の終わりまで
            let endMin = end === null ? start.min + SLOT_MINUTES : (end.day === start.day ? end.min : DAY_MINUTES);
            if (endMin <= start.min) endMin = start.min + SLOT_MINUTES;
            if (!byDay.has(start.day)) byDay.set(start.day, []);
            byDay.get(start.day).push({ entry: entry, start: start.min, end: Math.min(endMin, DAY_MINUTES), venue: venueOf(entry, nodes || {}, pois) });
        });

        const days = [];
        Array.from(byDay.keys()).sort().forEach(function (day) {
            const items = byDay.get(day).slice().sort(function (a, b) { return a.start - b.start || a.end - b.end; });
            const startMin = Math.floor(Math.min.apply(null, items.map(function (i) { return i.start; })) / SLOT_MINUTES) * SLOT_MINUTES;
            const endMin = Math.ceil(Math.max.apply(null, items.map(function (i) { return i.end; })) / SLOT_MINUTES) * SLOT_MINUTES;

            // 会場ごとにレーンへ(空いている一番左のレーン)
            const venues = new Map();
            items.forEach(function (item) {
                const key = item.venue.key;
                if (!venues.has(key)) venues.set(key, { info: item.venue, laneEnds: [], first: item.start });
                const v = venues.get(key);
                let lane = v.laneEnds.findIndex(function (laneEnd) { return laneEnd <= item.start; });
                if (lane < 0) { lane = v.laneEnds.length; v.laneEnds.push(0); }
                v.laneEnds[lane] = item.end;
                item.lane = lane;
            });
            // 列の順: 早く始まる会場から(名前で揃える)。その他は右端
            const order = Array.from(venues.keys()).sort(function (a, b) {
                if (a === OTHER_KEY || b === OTHER_KEY) return a === OTHER_KEY ? 1 : -1;
                return venues.get(a).first - venues.get(b).first || venues.get(a).info.name.localeCompare(venues.get(b).info.name, 'ja');
            });
            let col = 1;
            const venueList = order.map(function (key) {
                const v = venues.get(key);
                const out = Object.assign({}, v.info, { col: col, lanes: v.laneEnds.length });
                col += v.laneEnds.length;
                return out;
            });
            const colOf = {};
            venueList.forEach(function (v) { colOf[v.key] = v.col; });
            days.push({
                day: day,
                startMin: startMin,
                rows: Math.max(1, (endMin - startMin) / SLOT_MINUTES),
                columns: col - 1,
                venues: venueList,
                cells: items.map(function (item) {
                    const rowStart = Math.floor((item.start - startMin) / SLOT_MINUTES) + 1;
                    return {
                        entry: item.entry,
                        venueKey: item.venue.key,
                        col: colOf[item.venue.key] + item.lane,
                        rowStart: rowStart,
                        rowEnd: Math.max(rowStart + 1, Math.ceil((item.end - startMin) / SLOT_MINUTES) + 1),
                    };
                }),
            });
        });
        return days;
    }

    /** 表の行の時刻(「10:30」) */
    function slotLabel(startMin, row) {
        const min = startMin + (row - 1) * SLOT_MINUTES;
        return Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0');
    }

    const api = { SLOT_MINUTES: SLOT_MINUTES, parseTime: parseTime, venueOf: venueOf, layout: layout, slotLabel: slotLabel };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.KmScheduleGrid = api;
}(typeof window !== 'undefined' ? window : globalThis));
