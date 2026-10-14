/*
 * 地図の名前の札をどこに置くか。**画面の px だけで決める純粋な計算**(DOM にも Leaflet にも触らない)。
 *
 * ## 引き出し線(2026-10-14、利用者の提案)
 *
 * それまでは「上下に少しずらす → 駄目なら札を出さない」だけだった(2026-09-22)。
 * 建物の中では札を消せないので(部屋が探せなくなる)、重なったまま描いていた。
 * いまは次の順に試す。
 *
 *   1. 元の位置(点の真上)と、上下に少しずらした位置
 *   2. 点の右・左・下にぴったり付けた位置(線は要らない)
 *   3. 点から離した位置(8 方向・2 段の距離)。**点と札を細い線で結ぶ**
 *   4. それでも置けないとき: 屋外は札を出さない(点は残る)/ 建物の中は元の位置のまま重ねる
 *
 * 2 と 3 では、ほかの点も避ける(札で点を隠すと、押せる物が見えなくなる)。
 * 線はほかの札を横切らせない(どの札の線か分からなくなる)。線どうしの交差は許す。
 * 大事な種類から順に置く(施設 → 出入口 → 階段 → 部屋 → その他)。同じ種類なら上から。
 *
 * アプリの `GeneralMapNodeRenderer.kt`(layoutLabelsWithLeaders)と同じ手順・同じ数にしてある。
 * 検査は `scripts/js/label-layout.test.js`。
 */
(function (root) {
    'use strict';

    /** 上下に少しずらす量(px)。0 = 元の位置 */
    const NUDGES = [0, -14, 14, -30, 30];
    /** 点の縁から札の縁までの間(px)。これより離すときは線で結ぶ */
    const ADJACENT_GAP = 4;
    const LEADER_GAPS = [36, 68];
    /** 右・左・下(上は元の位置と同じなので要らない) */
    const ADJACENT_DIRECTIONS = [[1, 0], [-1, 0], [0, 1]];
    /** 線で結ぶ方向。上を先に(札はふだん点の上にあるので、目が探しにいく向き) */
    const LEADER_DIRECTIONS = [[0, -1], [1, -1], [-1, -1], [1, 0], [-1, 0], [1, 1], [-1, 1], [0, 1]];
    /** ちょうど接している程度は「読める」ので重なりと見ない余白(px) */
    const MARGIN = 2;

    function overlaps(a, b, margin) {
        return !(a.right <= b.left + margin || a.left >= b.right - margin
            || a.bottom <= b.top + margin || a.top >= b.bottom - margin);
    }

    function shifted(box, dx, dy) {
        return { left: box.left + dx, top: box.top + dy, right: box.right + dx, bottom: box.bottom + dy };
    }

    function inside(box, bounds) {
        return !bounds || (box.left >= bounds.left && box.right <= bounds.right
            && box.top >= bounds.top && box.bottom <= bounds.bottom);
    }

    /** 点の中心から [dir] の向きに、点の縁から [gap] 離して札を置いたときのずらし量 */
    function besideShift(item, dir, gap) {
        const box = item.box;
        const w = box.right - box.left;
        const h = box.bottom - box.top;
        const r = item.radius || 0;
        const cx = item.anchor.x + dir[0] * (w / 2 + r + gap);
        const cy = item.anchor.y + dir[1] * (h / 2 + r + gap);
        return { dx: cx - (box.left + box.right) / 2, dy: cy - (box.top + box.bottom) / 2 };
    }

    /**
     * 試す位置を順に並べる。leader = 線で結ぶ / avoidNodes = ほかの点も避ける / keepInside = 画面の外へ出さない。
     * 元の位置(0)だけは点を避けない —— そこが本来の置き場所で、ほかの点に掛かっても前と同じ見え方。
     */
    function candidates(item, nudges) {
        const list = nudges.map(function (n) {
            return { dx: 0, dy: n, leader: false, avoidNodes: n !== 0 && !!item.anchor, keepInside: false };
        });
        if (!item.anchor) return list;
        ADJACENT_DIRECTIONS.forEach(function (dir) {
            const s = besideShift(item, dir, ADJACENT_GAP);
            list.push({ dx: s.dx, dy: s.dy, leader: false, avoidNodes: true, keepInside: true });
        });
        LEADER_GAPS.forEach(function (gap) {
            LEADER_DIRECTIONS.forEach(function (dir) {
                const s = besideShift(item, dir, gap);
                list.push({ dx: s.dx, dy: s.dy, leader: true, avoidNodes: true, keepInside: true });
            });
        });
        return list;
    }

    function containsPoint(box, x, y) {
        return x > box.left && x < box.right && y > box.top && y < box.bottom;
    }

    /** 点の縁から札のいちばん近い点までの線。札の縁から外へ出る向き */
    function leaderLine(anchor, radius, box) {
        const px = Math.min(Math.max(anchor.x, box.left), box.right);
        const py = Math.min(Math.max(anchor.y, box.top), box.bottom);
        const vx = px - anchor.x;
        const vy = py - anchor.y;
        const length = Math.sqrt(vx * vx + vy * vy);
        if (length <= radius) return null;
        return {
            x1: anchor.x + (vx / length) * radius,
            y1: anchor.y + (vy / length) * radius,
            x2: px,
            y2: py,
        };
    }

    /**
     * 線分が箱を通るか(Liang–Barsky)。線がほかの札の字を横切ると、どの札の線か分からなくなるので避ける。
     * 箱は [inset] だけ内側に縮めて見る(縁をかすめる程度は許す)。
     */
    function segmentHitsBox(line, box, inset) {
        const left = box.left + inset;
        const right = box.right - inset;
        const top = box.top + inset;
        const bottom = box.bottom - inset;
        if (left >= right || top >= bottom) return false;
        const dx = line.x2 - line.x1;
        const dy = line.y2 - line.y1;
        let t0 = 0;
        let t1 = 1;
        const p = [-dx, dx, -dy, dy];
        const q = [line.x1 - left, right - line.x1, line.y1 - top, bottom - line.y1];
        for (let i = 0; i < 4; i++) {
            if (p[i] === 0) {
                if (q[i] < 0) return false;
                continue;
            }
            const t = q[i] / p[i];
            if (p[i] < 0) {
                if (t > t1) return false;
                if (t > t0) t0 = t;
            } else {
                if (t < t0) return false;
                if (t < t1) t1 = t;
            }
        }
        return t0 < t1;
    }

    /**
     * @param items [{ box:{left,top,right,bottom}, anchor:{x,y}|null, radius, priority }]
     * @param options { hideWhenCrowded, bounds?, nodes?:[{x,y,radius}], nudges?, margin? }
     * @return 入力と同じ並びで { dx, dy, leader:{x1,y1,x2,y2}|null }。**null は「札を出さない」**
     */
    function layout(items, options) {
        const opts = options || {};
        const nudges = opts.nudges || NUDGES;
        const margin = opts.margin === undefined ? MARGIN : opts.margin;
        const hide = opts.hideWhenCrowded !== false;
        const nodeBoxes = (opts.nodes || []).map(function (n) {
            return { x: n.x, y: n.y, box: { left: n.x - n.radius, top: n.y - n.radius, right: n.x + n.radius, bottom: n.y + n.radius } };
        });

        const order = items.map(function (_, i) { return i; }).sort(function (a, b) {
            const byType = (items[a].priority === undefined ? 9 : items[a].priority)
                - (items[b].priority === undefined ? 9 : items[b].priority);
            if (byType !== 0) return byType;
            // 並べ方を決めておかないと、少し動かすたびに隠れるものが入れ替わって**ちらつく**
            return items[a].box.top - items[b].box.top;
        });

        const result = new Array(items.length).fill(null);
        const placed = [];
        const lines = [];
        order.forEach(function (index) {
            const item = items[index];
            const tries = candidates(item, nudges);
            for (let t = 0; t < tries.length; t++) {
                const c = tries[t];
                const box = shifted(item.box, c.dx, c.dy);
                if (c.keepInside && !inside(box, opts.bounds)) continue;
                if (placed.some(function (other) { return overlaps(box, other, margin); })) continue;
                if (c.avoidNodes && nodeBoxes.some(function (n) {
                    // 自分の点は数えない(札は自分の点の隣に置くので、触れることはある)
                    const own = item.anchor && n.x === item.anchor.x && n.y === item.anchor.y;
                    return !own && overlaps(box, n.box, 0);
                })) continue;
                // 先に引いた線の上に札を載せない
                if (c.avoidNodes && lines.some(function (l) { return segmentHitsBox(l, box, margin); })) continue;
                const leader = c.leader && item.anchor ? leaderLine(item.anchor, item.radius || 0, box) : null;
                /*
                 * この線がほかの札を横切らないこと。ただし**自分の点がもう別の札の下にある**ときは、
                 * 線はその札の下から出てくるしかないので、その札は数えない(線は札の後ろに描く)。
                 */
                if (leader && placed.some(function (other) {
                    return !containsPoint(other, leader.x1, leader.y1) && segmentHitsBox(leader, other, margin);
                })) continue;
                placed.push(box);
                if (leader) lines.push(leader);
                result[index] = { dx: c.dx, dy: c.dy, leader: leader };
                return;
            }
            if (!hide) {
                // 建物の中: 消さずに元の位置で重ねる(部屋名が欠けると探せない)
                placed.push(item.box);
                result[index] = { dx: 0, dy: 0, leader: null };
            }
        });
        return result;
    }

    const api = { layout: layout, overlaps: overlaps, NUDGES: NUDGES, LEADER_GAPS: LEADER_GAPS, ADJACENT_GAP: ADJACENT_GAP };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.KM_LABEL_LAYOUT = api;
}(typeof window !== 'undefined' ? window : this));
