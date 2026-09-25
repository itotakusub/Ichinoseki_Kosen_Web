// dijkstra.js
// ダイクストラ法を用いた最短経路計算アルゴリズム

/*
 * ======== 経路の重み ========(2026-09-25 に1か所へまとめた)
 *
 * **アプリ(`RouteSearch.kt` の `RouteWeights`)と同じ値・同じ意味。** 片方だけ直すと、
 * アプリと Website で違う道を案内する(check.php の route-weights が突き合わせる)。
 * あちらはメートルで持ち px/m(既定 10)を掛けて使う。こちらは画素で持つので、掛けた後の値を置く。
 *
 *   floorTransferPx       … 階段・エレベーター 1 層ぶん(20m)。0 にしない —— 階の移動がタダになると、
 *                            同じ階を少し歩けば済む場面でも上り下りする経路が選ばれる
 *   entranceTransferPx    … 屋内外の出入り 1 回ぶん。**A 屋内優先**で 3m → 15m に上げた(利用者の指示)。
 *                            3m だと「外へ出て入り直す」がほぼタダで、中に道があっても外回りが選ばれた
 *   outsideMultiplier     … 屋外の道の重み。**A 屋内優先**(1.3 倍)
 *   noRoomPassThrough     … **B 部屋を通り抜けない**。部屋は出発地・目的地のときだけ通る
 *                            (通れる道がそれしか無いときは、黙って通り抜けを許す —— 経路を消さない)
 *   verticalPenalty       … エレベーター優先・階段優先で、好みでない方にかける倍率
 *   fewerFloorsMultiplier … **C 階の移動を減らす**(設定)。1 層ぶんの重みにかける
 *   rainOutsideMultiplier … **D 雨の日モード**(設定)。屋外の道にさらにかける
 *
 * 値は管理アプリで試せる(アプリの設定「経路の重み(管理)」)。**将来は、管理アプリで決めた値を
 * 一般の既定として配る予定**(map-data の routeWeights)。そのときもこの形で受け取る。
 */
const KM_ROUTE_WEIGHTS = Object.freeze({
    floorTransferPx: 200,         // 20m × 10px/m
    entranceTransferPx: 150,      // 15m × 10px/m
    outsideMultiplier: 1.3,
    noRoomPassThrough: true,
    verticalPenalty: 4,
    fewerFloorsMultiplier: 2,
    rainOutsideMultiplier: 3,
});

// 以前の名前。検査や他のファイルが読んでいるので残す(値は上の表から取る)
const KM_FLOOR_TRANSFER_PX = KM_ROUTE_WEIGHTS.floorTransferPx;
const KM_ENTRANCE_TRANSFER_PX = KM_ROUTE_WEIGHTS.entranceTransferPx;

/*
 * エレベーター優先・階段優先(2026-09-22、利用者の要望)。**アプリ(`RouteSearch.kt`)と同じ値。**
 *
 * 選ばれなかった方を**重くするだけで、外さない。** 外すと「その建物にはエレベーターが無い」
 * 「階段が閉じている」場面で経路そのものが消え、**案内が壊れたように見える。**
 * 4 倍だと 1 層ぶんが 20m → 80m 相当。少し遠回りでも好みの方を通り、
 * 好みの方が無ければ黙ってもう一方を使う。
 */
const KM_VERTICAL_PENALTY = KM_ROUTE_WEIGHTS.verticalPenalty;

/** 縦の移動の好み。'any'(指定なし)/ 'elevator' / 'stairs'。 */
const KM_VERTICAL_MODES = ['any', 'elevator', 'stairs'];

/**
 * 重みを読む。**知らない値・壊れた値は既定へ倒す**(将来は配信データから来る。何が入っていても落ちない)。
 * 倍率は 1 未満にさせない —— 屋外を軽くすると「屋内優先」が逆に効く。
 */
function kmRouteWeights(source) {
    const w = Object.assign({}, KM_ROUTE_WEIGHTS);
    if (!source || typeof source !== 'object') return w;
    const positive = function (key, min) {
        const v = Number(source[key]);
        if (Number.isFinite(v) && v >= min) w[key] = v;
    };
    positive('floorTransferPx', 1);
    positive('entranceTransferPx', 0);
    positive('outsideMultiplier', 1);
    positive('verticalPenalty', 1);
    positive('fewerFloorsMultiplier', 1);
    positive('rainOutsideMultiplier', 1);
    if (typeof source.noRoomPassThrough === 'boolean') w.noRoomPassThrough = source.noRoomPassThrough;
    return w;
}
class Dijkstra {
    /**
     * @param {object} nodes
     * @param {Array}  edges
     * @param {{vertical?: string, fewerFloors?: boolean, rain?: boolean, weights?: object}} [options]
     *   vertical: 'any' | 'elevator' | 'stairs'
     *   fewerFloors: C 階の移動を減らす / rain: D 雨の日モード(どちらも閲覧者の設定)
     *   weights: 重みの上書き(既定は KM_ROUTE_WEIGHTS)
     */
    constructor(nodes, edges, options) {
        this.nodes = nodes;
        this.adjacencyList = {};
        const vertical = String((options && options.vertical) || 'any');
        // 知らない値は「指定なし」に倒す(localStorage は閲覧者の手元にあり、何でも入りうる)
        this.vertical = KM_VERTICAL_MODES.indexOf(vertical) >= 0 ? vertical : 'any';
        this.fewerFloors = !!(options && options.fewerFloors === true);
        this.rain = !!(options && options.rain === true);
        this.weights = kmRouteWeights(options && options.weights);

        /*
         * イベントモードで閉じられた経路・地点は、**隣接リストに載せない**。
         *
         * 配信データ側では閉じたエッジも消さずに closed を立てて送っている
         * (地図に破線で残して理由を見せるため)。ここで弾くのは経路計算だけ。
         *
         * 「通行止めのせいで経路が無い」ことを呼び出し側が言い分けられるよう、
         * 弾いた本数を数えておく(0 なら、経路が無い理由は通行止めではない)。
         */
        this.blockedByEvent = 0;

        // 隣接リストの初期化。閉じた地点はそもそも作らない(誰からも辿れなくなる)
        for (const nodeId in nodes) {
            if (nodes[nodeId] && nodes[nodeId].closed) {
                this.blockedByEvent += 1;
                continue;
            }
            this.adjacencyList[nodeId] = [];
        }

        // 無向グラフとしてエッジを登録
        for (const edge of edges) {
            /*
             * **壁は通れない。**
             *
             * 壁のノードを持つようになった(2026-09-03)ので、壁を繋ぐ線も
             * エッジとして届く。地図には描くが、経路には載せない ——
             * 載せると**案内が壁を通り抜ける。**
             *
             * 判定はサーバーが済ませている(両端が `wall` の線だけ)。
             * ここで種類を見に行かないのは、アプリと Web で判定が分かれるのを避けるため。
             *
             * **通行止めと数えない。** これは会期の都合ではなく地形なので、
             * 「通行止めのせいで経路がありません」と言うと嘘になる。
             */
            if (edge.wall) {
                continue;
            }
            if (edge.closed) {
                this.blockedByEvent += 1;
                continue;
            }
            if (this.adjacencyList[edge.source] && this.adjacencyList[edge.target]) {
                const weight = edge.distance * this.outsideFactor(nodes[edge.source], nodes[edge.target]);
                this.adjacencyList[edge.source].push({ node: edge.target, weight: weight });
                // 逆方向の経路も登録
                this.adjacencyList[edge.target].push({ node: edge.source, weight: weight });
            }
        }

        /*
         * ---- 線として引かれていない繋がりを足す(2026-09-05、利用者の指摘)----
         *
         * **屋外から建物の中への案内が必ず失敗していた。**
         *
         * アプリ(`RouteSearch.kt`)は、保存された線に加えて
         * **階段と出入口を「接続ID」(`transferGroupId`)で結ぶ辺**を、
         * 探索のたびに自分で足している。線を引かなくても、同じ接続IDを持つ
         * 1F 側と屋外側の出入口が繋がる、という作りになっている。
         *
         * Website は**保存された線だけ**でグラフを組んでいた。屋外と 1F を繋ぐ線は
         * どこにも無いので、外から中(または中から外)は**常に「経路が見つかりません」**。
         * アプリで通るのに Website で通らないのはこれが理由だった。
         *
         * ここで同じ辺を足す。**判定はアプリに合わせる** ——
         * 別の繋ぎ方をすると、今度は「アプリと違う道を案内する」ことになる。
         */
        this.entranceTransfers = 0;
        this.stairTransfers = 0;
        this.addTransferEdges(nodes);
    }

    /**
     * エレベーターか。**種類は階段と同じ `stairs`** で、見分けるものが名前しか無い
     * (本番の実測: `transfer_group_id` が「エレベーター 4号棟」・`type2` が「エレベーター」)。
     * アプリの `isElevatorNode` と同じ判定にしてある。
     */
    static isElevator(node) {
        if (!node) return false;
        const text = [node.transferGroupId, node.title, node.name, node.subtitle, node.type2]
            .map(function (v) { return String(v || '').toLowerCase(); })
            .join(' ');
        return text.indexOf('エレベーター') >= 0 || text.indexOf('elevator') >= 0;
    }

    /**
     * 縦の移動 1 本にかける倍率。**好みでない方を重くするだけで、外さない。**
     * 外すと、片方しか無い建物で経路が消える。
     */
    verticalMultiplier(node) {
        if (this.vertical === 'any') return 1;
        const wantsElevator = this.vertical === 'elevator';
        return Dijkstra.isElevator(node) === wantsElevator ? 1 : this.weights.verticalPenalty;
    }

    /**
     * 屋外の道にかける倍率(A 屋内優先・D 雨の日)。**両端とも屋外の線だけ**に効かせる。
     */
    outsideFactor(a, b) {
        if (!a || !b || !Dijkstra.isOutside(a.floor) || !Dijkstra.isOutside(b.floor)) return 1;
        return this.weights.outsideMultiplier * (this.rain ? this.weights.rainOutsideMultiplier : 1);
    }

    /** 接続ID。空なら「指定なし」。 */
    static transferId(node) {
        return String((node && node.transferGroupId) || '').trim().toLowerCase();
    }

    /**
     * 階段をまとめる鍵。
     *
     * **接続IDが無ければ名前で寄せる**(アプリの `routeStairTransferKey` と同じ)。
     * 接続IDを付ける前からある地図でも、同じ名前の階段は繋がってほしいため。
     */
    static stairKey(node) {
        const id = Dijkstra.transferId(node);
        if (id) return id;
        const title = String((node && (node.title || node.name)) || '').trim();
        const fallback = title || String((node && node.subtitle) || '').trim();
        return fallback.toLowerCase();
    }

    /**
     * 階の番号。屋外(`outside`)のように数にできない階は null。
     * Website の階は `"1"`〜`"5"` と `"outside"`(アプリは `"1F"` と `"OUTSIDE"`)。
     */
    static floorNumber(floor) {
        const n = Number(String(floor).replace(/F$/i, ''));
        return Number.isFinite(n) ? n : null;
    }

    static isOutside(floor) {
        return String(floor).toLowerCase() === 'outside';
    }

    /**
     * 建物の階か(2026-09-24。`lib/building-floors.php` の id)。**平面図 1 枚が 1 つの階。**
     * アプリ(`BuildingFloorCatalog.kt` の `isBuildingFloor`)と同じ規則。
     */
    static isBuildingFloor(floor) {
        return /^bldg_/i.test(String(floor));
    }

    addTransferEdges(nodes) {
        /*
         * **閉じた地点は最初から数えない。**
         * 隣接リストを作っていない地点(イベントで閉じた地点)を繋ぐと、
         * 塞いだはずの階段や出入口を経路が素通りする。
         */
        const usable = [];
        for (const id in nodes) {
            const node = nodes[id];
            if (!node || !this.adjacencyList[id]) continue;
            usable.push({ id: String(id), node: node });
        }

        const link = (entries, weight) => {
            this.adjacencyList[entries[0]].push({ node: entries[1], weight: weight });
            this.adjacencyList[entries[1]].push({ node: entries[0], weight: weight });
        };

        /** 同じ鍵のものを総当たりで繋ぐ(アプリの `addTransferPairs` と同じ)。 */
        const pairUp = (type, keyOf, canConnect, weightOf, onLink) => {
            const groups = new Map();
            usable.forEach((entry) => {
                if (String(entry.node.type) !== type) return;
                const key = keyOf(entry.node);
                if (!key) return;   // 鍵が無いものは繋がない(全部が1つに繋がってしまう)
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(entry);
            });

            groups.forEach((group) => {
                for (let i = 0; i < group.length; i++) {
                    for (let j = i + 1; j < group.length; j++) {
                        const a = group[i];
                        const b = group[j];
                        if (!canConnect(a.node, b.node)) continue;
                        link([a.id, b.id], weightOf(a.node, b.node));
                        onLink();
                    }
                }
            });
        };

        // 階段。屋外は階段で繋がない。またぐ層の数だけ重みを積む
        pairUp(
            'stairs',
            Dijkstra.stairKey,
            (a, b) => String(a.floor) !== String(b.floor)
                && !Dijkstra.isOutside(a.floor) && !Dijkstra.isOutside(b.floor),
            (a, b) => {
                const from = Dijkstra.floorNumber(a.floor);
                const to = Dijkstra.floorNumber(b.floor);
                const layers = (from === null || to === null) ? 1 : Math.abs(to - from);
                // 片側でもエレベーターなら、その乗り換えはエレベーターとして扱う
                // (同じ接続IDで結ぶので、両端は同じ設備になる)
                const multiplier = Math.max(
                    this.verticalMultiplier(a),
                    this.verticalMultiplier(b)
                );
                const fewer = this.fewerFloors ? this.weights.fewerFloorsMultiplier : 1;
                return Math.max(1, layers) * this.weights.floorTransferPx * multiplier * fewer;
            },
            () => { this.stairTransfers += 1; }
        );

        /*
         * 出入口。**接続IDだけで結ぶ**(アプリの `routeEntranceTransferKey` と同じ ——
         * 名前で寄せない)。「玄関」という名前は建物ごとに在りうるので、
         * 名前で繋ぐと**別の建物の中へ出てしまう。**
         *
         * 繋ぐのは 1F と屋外の組だけ。2F と屋外を繋ぐ出入口は今のところ扱わない。
         */
        pairUp(
            'entrance',
            Dijkstra.transferId,
            (a, b) => {
                const floors = [String(a.floor), String(b.floor)];
                if (floors[0] === floors[1]) return false;
                /*
                 * 建物の階(2026-09-24)。平面図 1 枚が 1 つの階になったので、
                 * **その中の出入口は屋外(や 1F)と繋ぐ**。接続IDが同じものだけなので、
                 * 別の建物へ出ることはない。
                 */
                if (floors.some(Dijkstra.isBuildingFloor)) return true;
                return floors.some(Dijkstra.isOutside)
                    && floors.some((f) => Dijkstra.floorNumber(f) === 1);
            },
            () => this.weights.entranceTransferPx,
            () => { this.entranceTransfers += 1; }
        );
    }

    /**
     * 最短経路を検索します
     * @param {string} startNodeId 
     * @param {string} endNodeId 
     * @returns {string[] | null} 経路のノードID配列。経路がない場合はnull
     */
    findShortestPath(startNodeId, endNodeId) {
        /*
         * **B 部屋を通り抜けない**(2026-09-25、利用者の指示)。部屋は出発地・目的地のときだけ通る。
         * 部屋どうしが線で繋がっていると、教室の中を突っ切る経路になりうるため。
         *
         * **それで道が無くなるなら、通り抜けを許してもう一度探す。** ホールや吹き抜けを「部屋」として
         * 描いてあり、そこを通らないと行けない場所がある —— 経路を消すより、通り抜けて案内する方がよい
         * (エレベーター優先と同じ考え方)。どちらになったかは roomPassThroughUsed に残す。
         */
        this.roomPassThroughUsed = false;
        if (this.weights.noRoomPassThrough) {
            const strict = this.searchPath(startNodeId, endNodeId, true);
            if (strict) return strict;
            const loose = this.searchPath(startNodeId, endNodeId, false);
            this.roomPassThroughUsed = loose !== null;
            return loose;
        }
        return this.searchPath(startNodeId, endNodeId, false);
    }

    /** 部屋か(B の判定)。 */
    isRoom(nodeId) {
        const node = this.nodes[nodeId];
        return !!node && String(node.type) === 'room';
    }

    /**
     * 探索の本体。
     * @param {boolean} avoidRooms 途中で部屋を通らない(出発地・目的地は除く)
     */
    searchPath(startNodeId, endNodeId, avoidRooms) {
        const distances = {};
        const previous = {};
        const priorityQueue = []; 

        /*
         * 出発地・目的地そのものが閉じているなら、探すまでもない。
         * (「その部屋が立入禁止」を経路なしと同じ扱いにする)
         */
        if (!this.adjacencyList[startNodeId] || !this.adjacencyList[endNodeId]) {
            return null;
        }

        // 初期化処理。**閉じた地点はキューに入れない** ——
        // 入れると取り出したときに隣接リストが無く、for...of が落ちる
        for (const nodeId in this.nodes) {
            if (!this.adjacencyList[nodeId]) continue;
            if (nodeId === startNodeId) {
                distances[nodeId] = 0;
                priorityQueue.push({ id: nodeId, priority: 0 });
            } else {
                distances[nodeId] = Infinity;
                priorityQueue.push({ id: nodeId, priority: Infinity });
            }
            previous[nodeId] = null;
        }

        while (priorityQueue.length) {
            // 最短距離のノードをソートして先頭を取り出す
            priorityQueue.sort((a, b) => a.priority - b.priority);
            const currentNode = priorityQueue.shift();
            const currentId = currentNode.id;

            /*
             * **到達不能の判定を先に行う。**
             *
             * 以前は目的地かどうかを先に見ていたため、目的地が到達不能でも
             * 優先度 Infinity のまま取り出された時点で「経路あり」と判断し、
             * **目的地1点だけの偽の経路**([E] のような配列)を返していた。
             * 全域が繋がっているうちは表に出なかったが、イベントモードで
             * 通行止めを入れると普通に起きる(実測で踏んだ)。
             */
            if (currentNode.priority === Infinity) break;

            // 目的地に到達したか
            if (currentId === endNodeId) {
                const path = [];
                let current = endNodeId;
                while (current) {
                    path.unshift(current);
                    current = previous[current];
                }
                return path;
            }

            // 隣接ノードの距離を更新(閉じた地点は上で除いてあるが、念のため守る)
            for (const neighbor of this.adjacencyList[currentId] || []) {
                if (avoidRooms && neighbor.node !== endNodeId && neighbor.node !== startNodeId
                    && this.isRoom(neighbor.node)) {
                    continue;
                }
                const alt = distances[currentId] + neighbor.weight;
                if (alt < distances[neighbor.node]) {
                    distances[neighbor.node] = alt;
                    previous[neighbor.node] = currentId;

                    // キューの優先度更新
                    const qi = priorityQueue.findIndex(item => item.id === neighbor.node);
                    if (qi !== -1) {
                        priorityQueue[qi].priority = alt;
                    } else {
                        priorityQueue.push({ id: neighbor.node, priority: alt });
                    }
                }
            }
        }

        return null; // 経路が見つからなかった場合
    }
}
