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

    /*
     * ======== 曲がる所と目印 ========(2026-10-30、振り返りのフェーズ 3 の 3「曲がる所の案内」)
     *
     * 角度の決まりはアプリの RouteInstructions.kt と同じ: 向きの変わりが 20° 未満は直進、60° までは斜め、135° を超えると引き返す。
     * Web の地図の座標は**上が +y**(CRS.Simple。アプリは下が +y)。左右は yDown で切り替える。
     * 階が変わる所(階段・出入口)で向きを数え直す。短すぎる区間(minSegment 未満)は向きを決めるのに使わない(点の小さなずれで曲がらない)
     */
    const STRAIGHT_DEGREES = 20;
    const SLIGHT_TURN_DEGREES = 60;
    const TURN_BACK_DEGREES = 135;

    function normalizeTurn(degrees) {
        let value = degrees % 360;
        if (value > 180) value -= 360;
        if (value <= -180) value += 360;
        return value;
    }

    /**
     * @param points [{id, x, y, floor}] 経路の地点の並び
     * @param opts { yDown?: boolean, minSegment?: number }
     * @return [{index, id, turn: 'right'|'left'|'slight-right'|'slight-left'|'back', degrees}] 曲がる地点(index は points の位置)
     */
    function routeTurns(points, opts) {
        const list = Array.isArray(points) ? points : [];
        const o = opts || {};
        const minSegment = o.minSegment === undefined ? 3 : o.minSegment;
        const turns = [];
        let bearing = null;
        for (let i = 0; i < list.length - 1; i++) {
            const a = list[i];
            const b = list[i + 1];
            if (String(a.floor) !== String(b.floor)) {
                bearing = null;
                continue;
            }
            const dx = b.x - a.x;
            const dy = b.y - a.y;
            if (Math.hypot(dx, dy) < minSegment) continue;
            const next = Math.atan2(dy, dx) * 180 / Math.PI;
            if (bearing !== null) {
                const turn = normalizeTurn(next - bearing);
                const magnitude = Math.abs(turn);
                if (magnitude >= STRAIGHT_DEGREES) {
                    // 上が +y の座標では、角度が増える(反時計回り)のが左
                    const right = o.yDown ? turn > 0 : turn < 0;
                    let kind;
                    if (magnitude > TURN_BACK_DEGREES) kind = 'back';
                    else if (magnitude > SLIGHT_TURN_DEGREES) kind = right ? 'right' : 'left';
                    else kind = right ? 'slight-right' : 'slight-left';
                    turns.push({ index: i, id: a.id, turn: kind, degrees: Math.round(turn) });
                }
            }
            bearing = next;
        }
        return turns;
    }

    /**
     * 曲がる所の目印。曲がる地点そのものに名前があればそれ、無ければ同じ階で maxDist 以内のいちばん近い名前のある地点。
     * candidates は [{id, name, x, y, floor}](名前のある、人が見て分かる地点だけを渡す)。無ければ null
     */
    function turnLandmark(point, candidates, maxDist) {
        let best = null;
        let bestDistance = Infinity;
        (Array.isArray(candidates) ? candidates : []).forEach(function (c) {
            if (!c || !String(c.name || '').trim() || String(c.floor) !== String(point.floor)) return;
            if (c.id === point.id) {
                best = c;
                bestDistance = -1;
                return;
            }
            const d = Math.hypot(c.x - point.x, c.y - point.y);
            if (d <= maxDist && d < bestDistance) {
                best = c;
                bestDistance = d;
            }
        });
        return best;
    }

    const TURN_WORDS = {
        right: '右へ曲がる', left: '左へ曲がる', 'slight-right': '右斜め前へ', 'slight-left': '左斜め前へ', back: '引き返す',
    };
    const TURN_SHORT = { right: '右へ', left: '左へ', 'slight-right': '右斜め前へ', 'slight-left': '左斜め前へ', back: '引き返す' };
    const TURN_ARROWS = { right: '↱', left: '↰', 'slight-right': '↗', 'slight-left': '↖', back: '↩' };

    /** 「講義室1を目印に右へ」/ 目印が無ければ「右へ曲がる」 */
    function turnPhrase(turn, landmarkName) {
        const name = String(landmarkName || '').trim();
        if (!name) return TURN_WORDS[turn] || '';
        return turn === 'back' ? name + 'で引き返す' : name + 'を目印に' + (TURN_SHORT[turn] || '');
    }

    function turnArrow(turn) {
        return TURN_ARROWS[turn] || '';
    }

    /*
     * ======== 階段の上り・下り ========(2026-10-30、振り返りのフェーズ 3 の 3。アプリの StairGuide.kt と同じ)
     */

    /** 屋内の階(1F〜)だけ数にする。外・建物の階は null */
    function floorNumber(floor) {
        const text = String(floor === null || floor === undefined ? '' : floor).trim();
        return /^\d+$/.test(text) ? Number(text) : null;
    }

    /** 階の並びを短く(続いていれば「2〜5F」、飛んでいれば「2・4F」) */
    function floorRangeLabel(floors) {
        const sorted = (Array.isArray(floors) ? floors : []).slice().sort(function (a, b) { return a - b; });
        if (sorted.length === 0) return '';
        const contiguous = sorted.every(function (f, i) { return i === 0 || f === sorted[i - 1] + 1; });
        return sorted.length >= 3 && contiguous ? sorted[0] + '〜' + sorted[sorted.length - 1] + 'F' : sorted.join('・') + 'F';
    }

    /**
     * その階段から行ける階。keyOf(node) が同じ階段(接続 ID)を集める。{up: 近い順, down: 近い順, elevator} か null
     * @param isElevator (node) => boolean
     */
    function stairReach(node, allNodes, keyOf, isElevator) {
        if (!node || node.type !== 'stairs') return null;
        const here = floorNumber(node.floor);
        const key = keyOf(node);
        if (here === null || !key) return null;
        const floors = [];
        (Array.isArray(allNodes) ? allNodes : []).forEach(function (other) {
            if (!other || other === node || other.type !== 'stairs' || keyOf(other) !== key) return;
            const f = floorNumber(other.floor);
            if (f !== null && f !== here && floors.indexOf(f) < 0) floors.push(f);
        });
        if (floors.length === 0) return null;
        return {
            up: floors.filter(function (f) { return f > here; }).sort(function (a, b) { return a - b; }),
            down: floors.filter(function (f) { return f < here; }).sort(function (a, b) { return b - a; }),
            elevator: !!(isElevator && isElevator(node)),
        };
    }

    /** 階段の札の行。エレベーターは「⇅ 1〜5F」の 1 行(今の階も入れる) */
    function stairReachLines(reach, hereFloor) {
        if (!reach) return [];
        if (reach.elevator) {
            const all = reach.up.concat(reach.down, [hereFloor]).filter(function (f, i, arr) { return arr.indexOf(f) === i; });
            return [{ kind: 'elevator', text: '⇅ ' + floorRangeLabel(all) }];
        }
        const lines = [];
        if (reach.up.length > 0) lines.push({ kind: 'up', text: '▲上行き ' + floorRangeLabel(reach.up) });
        if (reach.down.length > 0) lines.push({ kind: 'down', text: '▼下行き ' + floorRangeLabel(reach.down) });
        return lines;
    }

    /**
     * いまの階で、経路が通る階段の札。出発側は「▲ 2F へ上る」「▼ 1F へ下りる」「エレベーターで 3F へ」、着いた側は「2F から到着」。
     * pathNodes は経路の地点の並び([{id, type, floor}])。labelOf(floor) は階の呼び名
     * @return [{id, text, goingUp: true|false|null, arriving}]
     */
    function routeStairMarks(pathNodes, currentFloor, labelOf, isElevator) {
        const list = Array.isArray(pathNodes) ? pathNodes : [];
        const here = String(currentFloor);
        const marks = [];
        for (let i = 0; i < list.length - 1; i++) {
            const from = list[i];
            const to = list[i + 1];
            if (!from || !to || String(from.floor) === String(to.floor) || from.type !== 'stairs') continue;
            const a = floorNumber(from.floor);
            const b = floorNumber(to.floor);
            const up = a !== null && b !== null ? b > a : null;
            if (String(from.floor) === here) {
                let text;
                if (isElevator && isElevator(from)) text = 'エレベーターで ' + labelOf(to.floor) + ' へ';
                else if (up === true) text = '▲ ' + labelOf(to.floor) + ' へ上る';
                else if (up === false) text = '▼ ' + labelOf(to.floor) + ' へ下りる';
                else text = labelOf(to.floor) + ' へ';
                marks.push({ id: from.id, text: text, goingUp: up, arriving: false });
            }
            if (String(to.floor) === here) {
                marks.push({ id: to.id, text: labelOf(from.floor) + ' から到着', goingUp: up, arriving: true });
            }
        }
        return marks;
    }

    const api = {
        clipSegment: clipSegment,
        splitRouteByObstacles: splitRouteByObstacles,
        routeFloorSequence: routeFloorSequence,
        routeTurns: routeTurns,
        turnLandmark: turnLandmark,
        turnPhrase: turnPhrase,
        turnArrow: turnArrow,
        floorNumber: floorNumber,
        floorRangeLabel: floorRangeLabel,
        stairReach: stairReach,
        stairReachLines: stairReachLines,
        routeStairMarks: routeStairMarks,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.KmRouteView = api;
}(typeof window !== 'undefined' ? window : globalThis));
