/*
 * 場所ごとの色分け(2026-10-30、高専祭の振り返りのフェーズ 1 の 3)。アプリの AreaSegmentation.kt を JS に移したもの。
 *
 * 床の下絵は線画で、部屋は閉じた枠で描かれている。部屋の形のデータは無いので、
 * **線で閉じた区画ごとに番号を振り、中にある地点の種類で区画の種類を決める**(決まりはアプリと同じ):
 *
 *   1. 暗い画素(明るさ BARRIER_LUMINANCE 未満)と、データの壁の線(太さ 3px)を「壁」にする。1px 太らせて切れ目を塞ぐ
 *   2. 壁でない画素を、つながりごとに番号を振る(4 近傍)
 *   3. 画像の端に届いた区画は建物の外、広すぎる区画は漏れとして塗らない
 *   4. 中の地点で種類を決める(classifyArea)。管理アプリで手で決めた種類と色が勝つ(applyManualArea)
 *
 * **来場者の端末では動かさない。** 管理画面「地図の色分け」(admin/map-areas.php)が各階を塗って置き、
 * 公開の地図は塗った画像を出すだけ(1600×1200 で数百 ms かかり、遅い端末のズームを邪魔しないように)。
 *
 * 画素は canvas の ImageData と同じ RGBA の並び(Uint8ClampedArray / 長さ = 幅 × 高さ × 4)。
 * 地点と壁の線は**画像の画素の座標**(y は下向き)。純粋な計算だけで、node でも試験する(scripts/js/area-segmentation.test.js)。
 */
(function (root) {
    'use strict';

    const AreaKind = Object.freeze({
        ROOM: 'room', CORRIDOR: 'corridor', STAIRS: 'stairs', UNKNOWN: 'unknown', BUILDING: 'building',
        ROAD: 'road', AMBIGUOUS: 'ambiguous', EXTERIOR: 'exterior', LEAK: 'leak', NONE: 'none',
    });

    const BARRIER_LUMINANCE = 200;
    const INDOOR_LEAK_FRACTION = 0.2;
    const OUTDOOR_LEAK_FRACTION = 0.5;
    const MIN_AREA_PIXELS = 40;
    const UNKNOWN_MAX_FRACTION = 0.015;
    const SEED_SEARCH_RADIUS = 6;
    const AREA_MARKER_NODE_TYPE = 'area';
    /** 区画を決めるのに使う地点の種類(AreaRendering.kt の AREA_SEED_TYPES) */
    const AREA_SEED_TYPES = ['room', 'facility', 'road', 'stairs', 'entrance', AREA_MARKER_NODE_TYPE];
    /** 手で決める区画の種類(Website の KM_MAP_AREA_KINDS・アプリの MANUAL_AREA_KINDS と同じ語) */
    const MANUAL_AREA_KINDS = ['room', 'corridor', 'stairs', 'building', 'road', 'none'];

    /** 色(0xRRGGBB)。アプリの AreaPalette と同じ値(Okabe-Ito を薄めた色。色覚の型ごとの見え方でも見分けられる) */
    const AreaPalette = Object.freeze({
        ROOM: 0xCDE7F7,
        CORRIDOR: 0xFBF3C4,
        STAIRS: 0xE3B9A6,
        UNKNOWN: 0xE4E7EB,
        BUILDING: 0xC8D2DC,
        ROAD: 0xF4E1BE,
        DEBUG_LEAK: 0xF4A6A6,
        DEBUG_UNKNOWN: 0xF9D29D,
        DEBUG_AMBIGUOUS: 0xC9A7F0,
    });

    function colorOf(kind, debug) {
        switch (kind) {
            case AreaKind.ROOM: return AreaPalette.ROOM;
            case AreaKind.CORRIDOR: return AreaPalette.CORRIDOR;
            case AreaKind.STAIRS: return AreaPalette.STAIRS;
            case AreaKind.UNKNOWN: return debug ? AreaPalette.DEBUG_UNKNOWN : AreaPalette.UNKNOWN;
            case AreaKind.BUILDING: return AreaPalette.BUILDING;
            case AreaKind.ROAD: return AreaPalette.ROAD;
            case AreaKind.AMBIGUOUS: return debug ? AreaPalette.DEBUG_AMBIGUOUS : AreaPalette.CORRIDOR;
            case AreaKind.LEAK: return debug ? AreaPalette.DEBUG_LEAK : null;
            default: return null;
        }
    }

    /** 下絵の線か(不透明で暗い)。透明な所は線ではない */
    function isDrawnLine(rgba, p) {
        const i = p * 4;
        if (rgba[i + 3] < 128) return false;
        return ((rgba[i] * 299 + rgba[i + 1] * 587 + rgba[i + 2] * 114) / 1000 | 0) < BARRIER_LUMINANCE;
    }

    function manualAreaKind(value) {
        return MANUAL_AREA_KINDS.indexOf(value) >= 0 ? value : null;
    }

    /**
     * 中の地点から区画の種類を決める。返すのは [種類, 押したときに開く地点の uuid か null]。
     * - 屋内: 部屋が 1 つで道が 2 つまで・階段が無い → 部屋。部屋が無く階段 → 階段。道・出入口・階段 → 廊下。部屋が 2 つ以上 → あいまい
     * - 屋外: 道 → 道。施設・部屋 → 建物(1 つなら押せる)。地点が無い → 塗らない
     */
    function classifyArea(seeds, outdoor) {
        const roomNodes = seeds.filter((s) => s.type === 'room');
        const facilities = seeds.filter((s) => s.type === 'facility');
        const rooms = outdoor ? roomNodes.concat(facilities) : (roomNodes.length > 0 ? roomNodes : facilities);
        const count = (type) => seeds.filter((s) => s.type === type).length;
        const roads = count('road');
        const stairs = count('stairs');
        const entrances = count('entrance');
        if (outdoor) {
            if (roads > 0) return [AreaKind.ROAD, null];
            if (rooms.length > 0) return [AreaKind.BUILDING, rooms.length === 1 ? rooms[0].uuid : null];
            return [AreaKind.NONE, null];
        }
        if (rooms.length === 1 && roads <= 2 && stairs === 0) return [AreaKind.ROOM, rooms[0].uuid];
        if (rooms.length === 0 && stairs > 0 && roads <= 2) return [AreaKind.STAIRS, null];
        if (roads > 0 || entrances > 0 || stairs > 0) return [AreaKind.CORRIDOR, null];
        if (rooms.length >= 2) return [AreaKind.AMBIGUOUS, null];
        return [AreaKind.UNKNOWN, null];
    }

    /**
     * 手で決めた種類と色を当てる。区画の中で最初に指定のある地点(区画の印を先に)を使う。
     * 建物の外と漏れには当てない。種類を「塗らない」にしたら色も付けない。返すのは [種類, 色(0xRRGGBB)か null]
     */
    function applyManualArea(autoKind, seeds) {
        if (autoKind === AreaKind.EXTERIOR || autoKind === AreaKind.LEAK) return [autoKind, null];
        const ordered = seeds.slice().sort((a, b) => (b.type === AREA_MARKER_NODE_TYPE) - (a.type === AREA_MARKER_NODE_TYPE));
        const manual = ordered.find((s) => manualAreaKind(s.kind) !== null || typeof s.color === 'number');
        if (!manual) return [autoKind, null];
        const kind = manualAreaKind(manual.kind) || (autoKind === AreaKind.NONE ? AreaKind.UNKNOWN : autoKind);
        return [kind, typeof manual.color === 'number' && kind !== AreaKind.NONE ? manual.color : null];
    }

    /** 壁の線を太さ 3px で書き込む */
    function stampSegment(line, width, height, wall) {
        const steps = Math.max(1, Math.round(Math.max(Math.abs(wall.x2 - wall.x1), Math.abs(wall.y2 - wall.y1))));
        for (let i = 0; i <= steps; i++) {
            const t = i / steps;
            const x = Math.round(wall.x1 + (wall.x2 - wall.x1) * t);
            const y = Math.round(wall.y1 + (wall.y2 - wall.y1) * t);
            for (let dy = -1; dy <= 1; dy++) {
                for (let dx = -1; dx <= 1; dx++) {
                    const xx = x + dx;
                    const yy = y + dy;
                    if (xx >= 0 && xx < width && yy >= 0 && yy < height) line[yy * width + xx] = 1;
                }
            }
        }
    }

    /** 地点の画素の区画。壁の上なら SEED_SEARCH_RADIUS の中でいちばん近い区画 */
    function labelNear(labels, width, height, fx, fy) {
        const cx = Math.round(fx);
        const cy = Math.round(fy);
        if (cx < 0 || cx >= width || cy < 0 || cy >= height) return null;
        if (labels[cy * width + cx] > 0) return labels[cy * width + cx];
        let best = null;
        let bestDistance = Infinity;
        for (let dy = -SEED_SEARCH_RADIUS; dy <= SEED_SEARCH_RADIUS; dy++) {
            for (let dx = -SEED_SEARCH_RADIUS; dx <= SEED_SEARCH_RADIUS; dx++) {
                const x = cx + dx;
                const y = cy + dy;
                if (x < 0 || x >= width || y < 0 || y >= height) continue;
                const label = labels[y * width + x];
                const distance = dx * dx + dy * dy;
                if (label > 0 && distance < bestDistance) {
                    best = label;
                    bestDistance = distance;
                }
            }
        }
        return best;
    }

    /**
     * 区画に分ける。
     * @param {Uint8ClampedArray|Uint8Array} rgba 下絵の画素(RGBA)
     * @param {Array<{uuid:string,type:string,x:number,y:number,tappable?:boolean,kind?:string|null,color?:number|null}>} seeds
     * @param {Array<{x1:number,y1:number,x2:number,y2:number}>} walls
     * @param {boolean} outdoor 外の全体図か
     * @returns {{width:number,height:number,labels:Int32Array,components:Array}}
     */
    function segmentAreas(rgba, width, height, seeds, walls, outdoor) {
        const total = width * height;
        const line = new Uint8Array(total);
        for (let p = 0; p < total; p++) if (isDrawnLine(rgba, p)) line[p] = 1;
        walls.forEach((wall) => stampSegment(line, width, height, wall));
        // 1px 太らせる(線の切れ目を塞ぐ)
        const barrier = new Uint8Array(total);
        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                if (!line[y * width + x]) continue;
                for (let dy = -1; dy <= 1; dy++) {
                    const yy = y + dy;
                    if (yy < 0 || yy >= height) continue;
                    for (let dx = -1; dx <= 1; dx++) {
                        const xx = x + dx;
                        if (xx >= 0 && xx < width) barrier[yy * width + xx] = 1;
                    }
                }
            }
        }

        // つながりごとに番号を振る(4 近傍の幅優先)
        const labels = new Int32Array(total);
        const queue = new Int32Array(total);
        const sizes = [0];
        const borders = [false];
        const boxes = [[0, 0, 0, 0]];
        let next = 1;
        for (let start = 0; start < total; start++) {
            if (barrier[start] || labels[start] !== 0) continue;
            const id = next++;
            let head = 0;
            let tail = 0;
            queue[tail++] = start;
            labels[start] = id;
            let size = 0;
            let touches = false;
            let minX = width;
            let minY = height;
            let maxX = 0;
            let maxY = 0;
            while (head < tail) {
                const p = queue[head++];
                size++;
                const x = p % width;
                const y = (p - x) / width;
                if (x === 0 || y === 0 || x === width - 1 || y === height - 1) touches = true;
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
                if (x > 0) { const q = p - 1; if (!barrier[q] && labels[q] === 0) { labels[q] = id; queue[tail++] = q; } }
                if (x < width - 1) { const q = p + 1; if (!barrier[q] && labels[q] === 0) { labels[q] = id; queue[tail++] = q; } }
                if (y > 0) { const q = p - width; if (!barrier[q] && labels[q] === 0) { labels[q] = id; queue[tail++] = q; } }
                if (y < height - 1) { const q = p + width; if (!barrier[q] && labels[q] === 0) { labels[q] = id; queue[tail++] = q; } }
            }
            sizes.push(size);
            borders.push(touches);
            boxes.push([minX, minY, maxX, maxY]);
        }

        // 太らせた分(線の脇の 1px)を、となりの区画へ戻す。塗ったときに線の脇が白く残らないように
        for (let p = 0; p < total; p++) {
            if (labels[p] !== 0 || line[p]) continue;
            const x = p % width;
            let neighbor = 0;
            if (x > 0 && labels[p - 1] > 0) neighbor = labels[p - 1];
            else if (x < width - 1 && labels[p + 1] > 0) neighbor = labels[p + 1];
            else if (p >= width && labels[p - width] > 0) neighbor = labels[p - width];
            else if (p + width < total && labels[p + width] > 0) neighbor = labels[p + width];
            if (neighbor > 0) labels[p] = -neighbor;
        }
        for (let p = 0; p < total; p++) if (labels[p] < 0) labels[p] = -labels[p];

        // 地点を区画へ
        const seedsByLabel = new Map();
        seeds.forEach((seed) => {
            const label = labelNear(labels, width, height, seed.x, seed.y);
            if (label === null) return;
            if (!seedsByLabel.has(label)) seedsByLabel.set(label, []);
            seedsByLabel.get(label).push(seed);
        });

        const leakLimit = Math.floor(total * (outdoor ? OUTDOOR_LEAK_FRACTION : INDOOR_LEAK_FRACTION));
        const components = [null];
        for (let id = 1; id < next; id++) {
            const size = sizes[id];
            const areaSeeds = seedsByLabel.get(id) || [];
            let auto;
            if (borders[id]) auto = [AreaKind.EXTERIOR, null];
            else if (size > leakLimit) auto = [AreaKind.LEAK, null];
            else if (size < MIN_AREA_PIXELS) auto = [AreaKind.NONE, null];
            else {
                auto = classifyArea(areaSeeds, outdoor);
                if (auto[0] === AreaKind.UNKNOWN && size > total * UNKNOWN_MAX_FRACTION) auto = [AreaKind.NONE, null];
            }
            const [kind, color] = applyManualArea(auto[0], areaSeeds);
            // 押して開くのは描いている地点だけ(イベントで隠した部屋は色だけ付けて開かない)
            const roomUuid = auto[1] !== null && areaSeeds.some((s) => s.uuid === auto[1] && s.tappable !== false)
                && (kind === AreaKind.ROOM || kind === AreaKind.BUILDING) ? auto[1] : null;
            const box = boxes[id];
            components.push({ id, size, kind, roomUuid, minX: box[0], minY: box[1], maxX: box[2], maxY: box[3], color });
        }
        return {
            width, height, labels, components,
            componentAt(x, y) {
                const ix = Math.floor(x);
                const iy = Math.floor(y);
                if (ix < 0 || ix >= width || iy < 0 || iy >= height) return null;
                const label = labels[iy * width + ix];
                return label > 0 ? components[label] : null;
            },
        };
    }

    /**
     * 塗った画素を作る(RGBA の写し)。線はそのまま、区画の画素だけを色に替える。色の無い種類は塗らない
     * @param {(kind:string)=>number|null} colorFor
     */
    function paintAreas(rgba, map, colorFor) {
        const out = new Uint8ClampedArray(rgba);
        const colors = map.components.map((c) => (c ? (typeof c.color === 'number' ? c.color : colorFor(c.kind)) : null));
        const total = map.width * map.height;
        for (let p = 0; p < total; p++) {
            const label = map.labels[p];
            if (label <= 0 || isDrawnLine(rgba, p)) continue;
            const color = colors[label];
            if (color === null || color === undefined) continue;
            const i = p * 4;
            out[i] = (color >> 16) & 0xFF;
            out[i + 1] = (color >> 8) & 0xFF;
            out[i + 2] = color & 0xFF;
            out[i + 3] = 255;
        }
        return out;
    }

    /** 種類ごとの区画の数(管理画面の確かめに出す) */
    function countKinds(map) {
        const counts = {};
        map.components.forEach((c) => {
            if (c && c.size >= MIN_AREA_PIXELS) counts[c.kind] = (counts[c.kind] || 0) + 1;
        });
        return counts;
    }

    const api = {
        AreaKind, AreaPalette, AREA_SEED_TYPES, AREA_MARKER_NODE_TYPE, MIN_AREA_PIXELS,
        colorOf, classifyArea, applyManualArea, manualAreaKind, segmentAreas, paintAreas, countKinds,
    };
    root.KM_AREA_SEGMENTATION = api;
    if (typeof module === 'object' && module.exports) module.exports = api;
}(typeof window !== 'undefined' ? window : globalThis));
