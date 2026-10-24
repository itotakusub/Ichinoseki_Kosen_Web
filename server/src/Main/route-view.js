/*
 * 経路の見せ方の計算(2026-10-24、利用者の指示)。**純粋関数だけ**(DOM も地図も触らない)。
 * Main/app.js が画面の座標を渡して使い、src/scripts/js/route-view.test.js が Node で試す(search-rank.js と同じ形)。
 *
 * ## splitRouteByObstacles —— 線が地点や札に重なる所を見つける
 * 「ルート案内をしたときにルートの線が被っていたら、透明度を上げる(60〜80%)」。
 * 経路の線は地点の印より上の層に描く(Main/app.js の pane kmRouteLine)ので、重なると地点が隠れる。
 * 折れ線を、画面の座標で「障害物(地点の印・札の箱)に重なる区間」と「重ならない区間」に分ける。
 * 重なりは線の**中心線**で見る(太さのぶんは pad で箱を広げて足す)。
 *
 * ## routeFloorSequence —— 経路が使う階
 * 「ルートを設定したときに、使用する階を常時表示」。経路の地点の階を順に並べ、続けて同じ階は 1 つに畳む
 * (1F → 3F → 1F のように、戻ってくる階はもう一度出す)。
 */
(function (root) {
    'use strict';

    /**
     * 線分 p0→p1 のうち、箱 rect({left, top, right, bottom})の中にある部分。
     * 線分の割合 [t0, t1](0〜1)で返す。かからなければ null(Liang–Barsky の切り取り)
     */
    function clipSegment(p0, p1, rect) {
        const dx = p1.x - p0.x;
        const dy = p1.y - p0.y;
        let t0 = 0;
        let t1 = 1;
        const edges = [
            [-dx, p0.x - rect.left],
            [dx, rect.right - p0.x],
            [-dy, p0.y - rect.top],
            [dy, rect.bottom - p0.y],
        ];
        for (let i = 0; i < edges.length; i++) {
            const p = edges[i][0];
            const q = edges[i][1];
            if (p === 0) {
                // 辺と平行。外側なら、かからない
                if (q < 0) return null;
                continue;
            }
            const r = q / p;
            if (p < 0) {
                if (r > t1) return null;
                if (r > t0) t0 = r;
            } else {
                if (r < t0) return null;
                if (r < t1) t1 = r;
            }
        }
        return t1 > t0 ? [t0, t1] : null;
    }

    /** 区間 [a, b] の並びを、重なりを畳んで小さい順に */
    function mergeIntervals(intervals) {
        const sorted = intervals.slice().sort(function (a, b) { return a[0] - b[0]; });
        const out = [];
        sorted.forEach(function (range) {
            const last = out[out.length - 1];
            if (last && range[0] <= last[1]) {
                last[1] = Math.max(last[1], range[1]);
            } else {
                out.push([range[0], range[1]]);
            }
        });
        return out;
    }

    function lerp(p0, p1, t) {
        return { x: p0.x + (p1.x - p0.x) * t, y: p0.y + (p1.y - p0.y) * t };
    }

    /**
     * 折れ線 points([{x, y}, …])を、障害物 rects に重なる区間と重ならない区間に分ける。
     * pad は箱を外へ広げる幅(線の太さの半分など)。
     *
     * @return {Array<{points: Array<{x:number,y:number}>, overlap: boolean}>} つながった区間の並び(前の区間の終わり = 次の区間の始まり)
     */
    function splitRouteByObstacles(points, rects, pad) {
        const list = Array.isArray(points) ? points : [];
        if (list.length < 2) return list.length === 1 ? [{ points: [list[0]], overlap: false }] : [];
        const grow = Number(pad) || 0;
        const boxes = (Array.isArray(rects) ? rects : [])
            .filter(function (r) { return r && r.right > r.left && r.bottom > r.top; })
            .map(function (r) { return { left: r.left - grow, top: r.top - grow, right: r.right + grow, bottom: r.bottom + grow }; });

        // 1. 線分ごとに、重なる/重ならない小さな区間へ切る
        const pieces = [];
        for (let i = 0; i < list.length - 1; i++) {
            const p0 = list[i];
            const p1 = list[i + 1];
            const hits = [];
            boxes.forEach(function (box) {
                const range = clipSegment(p0, p1, box);
                if (range) hits.push(range);
            });
            let cursor = 0;
            mergeIntervals(hits).forEach(function (range) {
                if (range[0] > cursor) pieces.push({ a: lerp(p0, p1, cursor), b: lerp(p0, p1, range[0]), overlap: false });
                pieces.push({ a: lerp(p0, p1, range[0]), b: lerp(p0, p1, range[1]), overlap: true });
                cursor = range[1];
            });
            if (cursor < 1) pieces.push({ a: lerp(p0, p1, cursor), b: p1, overlap: false });
        }

        // 2. 同じ側が続く区間をつなぐ。境目の点は前の区間の終わりと次の区間の始まりの両方に置く(線が途切れない)
        const runs = [];
        pieces.forEach(function (piece) {
            if (piece.a.x === piece.b.x && piece.a.y === piece.b.y) return;   // 長さ 0
            const last = runs[runs.length - 1];
            if (last && last.overlap === piece.overlap) {
                last.points.push(piece.b);
            } else {
                runs.push({ points: [piece.a, piece.b], overlap: piece.overlap });
            }
        });
        return runs;
    }

    /**
     * 経路が使う階を順に。floors は経路の地点の階の並び(同じ階が続く)。続けて同じ階は 1 つに畳む。
     * 空・null は飛ばす。例: ['1','1','3','3','1'] → ['1','3','1']
     */
    function routeFloorSequence(floors) {
        const out = [];
        (Array.isArray(floors) ? floors : []).forEach(function (floor) {
            if (floor === null || floor === undefined || String(floor) === '') return;
            const value = String(floor);
            if (out[out.length - 1] !== value) out.push(value);
        });
        return out;
    }

    const api = {
        clipSegment: clipSegment,
        splitRouteByObstacles: splitRouteByObstacles,
        routeFloorSequence: routeFloorSequence,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.KmRouteView = api;
}(typeof window !== 'undefined' ? window : globalThis));
