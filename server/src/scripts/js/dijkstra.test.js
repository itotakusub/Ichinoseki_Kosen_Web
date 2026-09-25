/*
 * 経路探索の検査。**node で走らせる**。
 *
 *   node src/scripts/js/dijkstra.test.js
 *
 * ここで見るのは**通れないものを通らないこと**。
 * 地図の上では線が引かれているので、目で見ても「通れる線」と区別が付かない ——
 * 案内が壁を抜けても、経路としては一応もっともらしく見えてしまう。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const sandbox = {};
vm.createContext(sandbox);
/*
 * **末尾に名前を置いて受け取る。** `class` の宣言は字句束縛で、
 * `sandbox` のプロパティにはならない —— そのままだと
 * 「読み込めたのに undefined」になり、原因が分かりにくい。
 */
const Dijkstra = vm.runInContext(
    fs.readFileSync(path.join(__dirname, '..', '..', 'Main', 'dijkstra.js'), 'utf8') + '\n;Dijkstra;',
    sandbox,
    { filename: 'dijkstra.js' }
);

let failures = 0;
let count = 0;

function check(label, expected, actual) {
    count++;
    if (JSON.stringify(expected) !== JSON.stringify(actual)) {
        failures++;
        console.log(`  ${label.padEnd(40)} FAIL  (期待 ${JSON.stringify(expected)} / 実際 ${JSON.stringify(actual)})`);
        return;
    }
    console.log(`  ${label.padEnd(40)} PASS`);
}

function heading(text) {
    console.log(`\n== ${text} ==`);
}

const nodes = { a: {}, b: {}, c: {} };
/** a→b が近道、a→c→b が回り道。近道に印を付けて試す。 */
function graph(shortcut) {
    return new Dijkstra(nodes, [
        Object.assign({ source: 'a', target: 'b', distance: 1 }, shortcut),
        { source: 'a', target: 'c', distance: 5 },
        { source: 'c', target: 'b', distance: 5 },
    ]);
}

heading('dijkstra: 壁は通れない');
/*
 * 壁のノードを持つようになった(2026-09-03)ので、壁を繋ぐ線もエッジとして届く。
 * **弾かないと案内が壁を通り抜ける。**
 */
check('印が無ければ近道を通る', ['a', 'b'], graph({}).findShortestPath('a', 'b'));
check('壁なら回り込む', ['a', 'c', 'b'], graph({ wall: true }).findShortestPath('a', 'b'));
check(
    '壁しか無ければ経路なし',
    null,
    new Dijkstra(nodes, [{ source: 'a', target: 'b', distance: 1, wall: true }]).findShortestPath('a', 'b')
);

heading('dijkstra: 壁を通行止めと数えない');
/*
 * 数えると「通行止めのせいで経路がありません」と案内してしまう。
 * **壁は会期の都合ではなく地形**で、待っても開かない。
 */
check('壁は通行止めに数えない', 0, graph({ wall: true }).blockedByEvent);
check('通行止めは数える', 1, graph({ closed: true }).blockedByEvent);

heading('dijkstra: イベントの通行止め');
check('閉じた線は避ける', ['a', 'c', 'b'], graph({ closed: true }).findShortestPath('a', 'b'));
// 閉じた地点はそもそも辿らせない
const closedNode = new Dijkstra(
    { a: {}, b: {}, c: { closed: true } },
    [{ source: 'a', target: 'c', distance: 1 }, { source: 'c', target: 'b', distance: 1 }]
);
check('閉じた地点は経由しない', null, closedNode.findShortestPath('a', 'b'));

heading('dijkstra: 建物の階(平面図 1 枚 = 1 つの階)');
/*
 * 2026-09-24。屋外の出入口と、建物の階の中の出入口を**同じ接続ID**で繋ぐ。
 * 繋がらないと、建物の中へ案内できない(中の地図を作った意味が無くなる)。
 */
function buildingMap(idOutside, idInside) {
    return new Dijkstra(
        {
            road: { floor: 'outside', type: 'road' },
            gateOut: { floor: 'outside', type: 'entrance', transferGroupId: idOutside },
            gateIn: { floor: 'bldg_library_1f', type: 'entrance', transferGroupId: idInside },
            counter: { floor: 'bldg_library_1f', type: 'room', name: 'カウンター' },
        },
        [
            { source: 'road', target: 'gateOut', distance: 10 },
            { source: 'gateIn', target: 'counter', distance: 10 },
        ]
    );
}
check('建物の階と分かる', true, Dijkstra.isBuildingFloor('bldg_library_1f'));
check('ふつうの階は建物の階でない', false, Dijkstra.isBuildingFloor('1'));
check('屋外も建物の階でない', false, Dijkstra.isBuildingFloor('outside'));
check(
    '同じ接続IDなら建物の中へ通る',
    ['road', 'gateOut', 'gateIn', 'counter'],
    buildingMap('図書館 出入り口', '図書館 出入り口').findShortestPath('road', 'counter')
);
check(
    '接続IDが違えば通らない',
    null,
    buildingMap('図書館 出入り口', '別の建物 出入り口').findShortestPath('road', 'counter')
);
// 建物の中の階段は、ふつうの階段と同じ扱い(接続IDで繋ぐ)
const buildingStairs = new Dijkstra(
    {
        a: { floor: 'bldg_library_1f', type: 'room' },
        s1: { floor: 'bldg_library_1f', type: 'stairs', transferGroupId: '図書館 階段' },
        s2: { floor: 'bldg_library_2f', type: 'stairs', transferGroupId: '図書館 階段' },
        b: { floor: 'bldg_library_2f', type: 'room' },
    },
    [
        { source: 'a', target: 's1', distance: 10 },
        { source: 's2', target: 'b', distance: 10 },
    ]
);
check('建物の中でも階段で階を移れる', ['a', 's1', 's2', 'b'], buildingStairs.findShortestPath('a', 'b'));

heading('dijkstra: エレベーター優先・階段優先');
/*
 * エレベーターは**種類が階段と同じ `stairs`** で、見分けるものが名前しか無い
 * (本番の実測: 接続IDが「エレベーター 4号棟」・type2 が「エレベーター」)。
 *
 * **好みでない方も残す。** 外すと、片方しか無い建物で経路そのものが消える。
 */
/** 1F→3F。階段は近く(30px)、エレベーターは遠い(90px)ところに置く。 */
function verticalMap(vertical) {
    return new Dijkstra(
        {
            start: { floor: '1', type: 'room' },
            stair1: { floor: '1', type: 'stairs', name: 'A階段', transferGroupId: 'A階段' },
            stair3: { floor: '3', type: 'stairs', name: 'A階段', transferGroupId: 'A階段' },
            ev1: { floor: '1', type: 'stairs', name: 'エレベーター 4号棟', transferGroupId: 'エレベーター 4号棟' },
            ev3: { floor: '3', type: 'stairs', name: 'エレベーター 4号棟', transferGroupId: 'エレベーター 4号棟' },
            goal: { floor: '3', type: 'room' },
        },
        [
            { source: 'start', target: 'stair1', distance: 30 },
            { source: 'start', target: 'ev1', distance: 90 },
            { source: 'stair3', target: 'goal', distance: 30 },
            { source: 'ev3', target: 'goal', distance: 90 },
        ],
        { vertical: vertical }
    );
}
check('見分け: 接続IDで分かる', true, Dijkstra.isElevator({ transferGroupId: 'エレベーター 4号棟' }));
check('見分け: 分類でも分かる', true, Dijkstra.isElevator({ type2: 'エレベーター' }));
check('見分け: 英語表記も拾う', true, Dijkstra.isElevator({ name: 'Elevator' }));
check('見分け: 階段はエレベーターでない', false, Dijkstra.isElevator({ name: 'A階段' }));
check(
    '指定なしなら近い方(階段)を通る',
    ['start', 'stair1', 'stair3', 'goal'],
    verticalMap('any').findShortestPath('start', 'goal')
);
check(
    'エレベーター優先なら遠回りでもエレベーター',
    ['start', 'ev1', 'ev3', 'goal'],
    verticalMap('elevator').findShortestPath('start', 'goal')
);
check(
    '階段優先なら階段',
    ['start', 'stair1', 'stair3', 'goal'],
    verticalMap('stairs').findShortestPath('start', 'goal')
);
// **片方しか無い建物**。好みの方が無くても案内が消えないこと
const stairsOnly = new Dijkstra(
    {
        start: { floor: '1', type: 'room' },
        stair1: { floor: '1', type: 'stairs', transferGroupId: 'B階段' },
        stair2: { floor: '2', type: 'stairs', transferGroupId: 'B階段' },
        goal: { floor: '2', type: 'room' },
    },
    [
        { source: 'start', target: 'stair1', distance: 10 },
        { source: 'stair2', target: 'goal', distance: 10 },
    ],
    { vertical: 'elevator' }
);
check(
    'エレベーターが無ければ階段で案内する',
    ['start', 'stair1', 'stair2', 'goal'],
    stairsOnly.findShortestPath('start', 'goal')
);
check('知らない値は指定なしと同じ', 'any', new Dijkstra({}, [], { vertical: 'ほげ' }).vertical);

heading('dijkstra: 出入口で屋外と中を繋ぐ');
/*
 * **線が引かれていない繋がり。** アプリ(`RouteSearch.kt`)は、同じ接続IDを持つ
 * 1F 側と屋外側の出入口を、探索のたびに自分で繋いでいる。
 * Website はそれを持っておらず、**外から中への案内が必ず失敗していた**
 * (利用者の指摘、2026-09-05)。目で見ると出入口の点は両方出ているので、
 * 地図を眺めても「繋がっていない」ことは分からない。
 */
function entranceMap(idOutside, idInside) {
    return new Dijkstra(
        {
            yard: { floor: 'outside', type: 'room' },
            gateOut: { floor: 'outside', type: 'entrance', transferGroupId: idOutside },
            gateIn: { floor: '1', type: 'entrance', transferGroupId: idInside },
            room101: { floor: '1', type: 'room' },
        },
        [
            { source: 'yard', target: 'gateOut', distance: 10 },
            { source: 'gateIn', target: 'room101', distance: 10 },
        ]
    );
}
check(
    '同じ接続IDなら外から中へ通る',
    ['yard', 'gateOut', 'gateIn', 'room101'],
    entranceMap('gate-a', 'gate-a').findShortestPath('yard', 'room101')
);
check('繋いだ組を数える', 1, entranceMap('gate-a', 'gate-a').entranceTransfers);
// 接続IDが違えば繋がない。**別の建物の中へ出てしまう**ため
check('接続IDが違えば繋がない', null, entranceMap('gate-a', 'gate-b').findShortestPath('yard', 'room101'));
// ID が空のものを寄せると、全部の出入口が1つの組になる
check('接続IDが空なら繋がない', null, entranceMap('', '').findShortestPath('yard', 'room101'));
check('繋げなかったことが分かる', 0, entranceMap('', '').entranceTransfers);
/*
 * **名前では繋がない**(アプリの `routeEntranceTransferKey` と同じ)。
 * 「玄関」は建物ごとに在りうるので、名前で寄せると別の建物へ出る。
 */
const namedEntrances = new Dijkstra(
    {
        yard: { floor: 'outside', type: 'room' },
        gateOut: { floor: 'outside', type: 'entrance', name: '玄関' },
        gateIn: { floor: '1', type: 'entrance', name: '玄関' },
        room101: { floor: '1', type: 'room' },
    },
    [
        { source: 'yard', target: 'gateOut', distance: 10 },
        { source: 'gateIn', target: 'room101', distance: 10 },
    ]
);
check('名前が同じでも繋がない', null, namedEntrances.findShortestPath('yard', 'room101'));

heading('dijkstra: 階段で階をまたぐ');
/** 線を1本も引かずに、同じ名前の階段だけで 1F と 3F を繋ぐ。 */
const stairMap = new Dijkstra(
    {
        r1: { floor: '1', type: 'room' },
        s1: { floor: '1', type: 'stairs', name: '中央階段' },
        s3: { floor: '3', type: 'stairs', name: '中央階段' },
        r3: { floor: '3', type: 'room' },
        far: { floor: '3', type: 'stairs', name: '別の階段' },
    },
    [
        { source: 'r1', target: 's1', distance: 10 },
        { source: 's3', target: 'r3', distance: 10 },
    ]
);
check('同じ名前の階段は繋がる', ['r1', 's1', 's3', 'r3'], stairMap.findShortestPath('r1', 'r3'));
check('繋いだ組を数える', 1, stairMap.stairTransfers);
// 屋外は階段では繋がない(外と中は出入口の役目)
const outsideStairs = new Dijkstra(
    {
        yard: { floor: 'outside', type: 'stairs', name: '階段' },
        s1: { floor: '1', type: 'stairs', name: '階段' },
    },
    []
);
check('屋外は階段で繋がない', 0, outsideStairs.stairTransfers);

heading('dijkstra: 経路の条件(2026-09-25)');
/*
 * A 屋内優先: 1F の廊下(中)を 100 歩くか、外へ出て 60 歩いて入り直すか。
 * 以前(出入り 30px・外 1 倍)は 30+60+30=120 < … ではなく、中が 100 なら中。
 * ここでは中を 170 にして、**以前なら外回りだった形**を作る(30+60+30=120 < 170)。
 * 今は 150+78+150=378 > 170 なので中を通る。
 */
const indoor = {
    a: { floor: '1', type: 'road', name: '' },
    b: { floor: '1', type: 'road', name: '' },
    ea: { floor: '1', type: 'entrance', name: '', transferGroupId: 'east' },
    eb: { floor: '1', type: 'entrance', name: '', transferGroupId: 'west' },
    oa: { floor: 'outside', type: 'entrance', name: '', transferGroupId: 'east' },
    ob: { floor: 'outside', type: 'entrance', name: '', transferGroupId: 'west' },
};
const indoorEdges = [
    { source: 'a', target: 'b', distance: 170 },
    { source: 'a', target: 'ea', distance: 0 },
    { source: 'b', target: 'eb', distance: 0 },
    { source: 'oa', target: 'ob', distance: 60 },
];
check('A: 中に道があれば中を通る', ['a', 'b'], new Dijkstra(indoor, indoorEdges).findShortestPath('a', 'b'));
check('A: 以前の重みなら外回りだった', ['a', 'ea', 'oa', 'ob', 'eb', 'b'],
    new Dijkstra(indoor, indoorEdges, { weights: { entranceTransferPx: 30, outsideMultiplier: 1 } }).findShortestPath('a', 'b'));

// B 部屋を通り抜けない。部屋 r を抜ければ 2、廊下を回れば 10
const rooms = {
    s: { floor: '1', type: 'road', name: '' },
    r: { floor: '1', type: 'room', name: '教室' },
    g: { floor: '1', type: 'road', name: '' },
    c: { floor: '1', type: 'road', name: '' },
};
const roomEdges = [
    { source: 's', target: 'r', distance: 1 },
    { source: 'r', target: 'g', distance: 1 },
    { source: 's', target: 'c', distance: 5 },
    { source: 'c', target: 'g', distance: 5 },
];
const roomGraph = new Dijkstra(rooms, roomEdges);
check('B: 教室の中を突っ切らない', ['s', 'c', 'g'], roomGraph.findShortestPath('s', 'g'));
check('B: 通り抜けは使っていない', false, roomGraph.roomPassThroughUsed);
check('B: 部屋そのものは目的地にできる', ['s', 'r'], roomGraph.findShortestPath('s', 'r'));
const onlyThroughRoom = new Dijkstra(rooms, roomEdges.slice(0, 2));
check('B: 部屋を通るしか無ければ通す(経路を消さない)', ['s', 'r', 'g'], onlyThroughRoom.findShortestPath('s', 'g'));
check('B: 通り抜けを使ったと分かる', true, onlyThroughRoom.roomPassThroughUsed);
check('B: 切れば突っ切る', ['s', 'r', 'g'], new Dijkstra(rooms, roomEdges, { weights: { noRoomPassThrough: false } }).findShortestPath('s', 'g'));

// C 階の移動を減らす: 階段で 2F を抜ける(1 層 200)か、1F を 300 歩くか
const floors = {
    a: { floor: '1', type: 'road', name: '' },
    s1: { floor: '1', type: 'stairs', name: '階段A' },
    s2: { floor: '2', type: 'stairs', name: '階段A' },
    t2: { floor: '2', type: 'stairs', name: '階段B' },
    t1: { floor: '1', type: 'stairs', name: '階段B' },
    b: { floor: '1', type: 'road', name: '' },
};
const floorEdges = [
    { source: 'a', target: 's1', distance: 0 },
    { source: 's2', target: 't2', distance: 10 },
    { source: 't1', target: 'b', distance: 0 },
    { source: 'a', target: 'b', distance: 500 },
];
check('C: 既定なら 2F を抜ける(400+10 < 500)', ['a', 's1', 's2', 't2', 't1', 'b'], new Dijkstra(floors, floorEdges).findShortestPath('a', 'b'));
check('C: 減らすなら同じ階を歩く(810 > 500)', ['a', 'b'], new Dijkstra(floors, floorEdges, { fewerFloors: true }).findShortestPath('a', 'b'));

// D 雨の日: 外 100(×1.3=130)か、中 250 か。雨なら外は 390
const rainMap = {
    a: { floor: 'outside', type: 'road', name: '' },
    b: { floor: 'outside', type: 'road', name: '' },
    c: { floor: 'outside', type: 'road', name: '' },
};
const rainEdges = [
    { source: 'a', target: 'b', distance: 100 },
    { source: 'a', target: 'c', distance: 1 },
];
const rainGraph = (rain) => new Dijkstra(rainMap, rainEdges, { rain: rain });
check('D: 屋外の道は 1.3 倍', 130, Math.round(rainGraph(false).adjacencyList.a[0].weight));
check('D: 雨の日はさらに 3 倍', 390, Math.round(rainGraph(true).adjacencyList.a[0].weight));

heading('dijkstra: 重みの読み取り');
const weights = sandbox.kmRouteWeights;
check('既定の出入り 1 回', 150, weights(null).entranceTransferPx);
check('壊れた値は既定へ', 1.3, weights({ outsideMultiplier: 'x' }).outsideMultiplier);
// 屋外を軽くすると「屋内優先」が逆に効く
check('倍率は 1 未満にさせない', 1.3, weights({ outsideMultiplier: 0.5 }).outsideMultiplier);
check('上書きは効く', 2, weights({ outsideMultiplier: 2 }).outsideMultiplier);
check('真偽値だけ受ける', true, weights({ noRoomPassThrough: 'false' }).noRoomPassThrough);

console.log('');
if (failures === 0) {
    console.log(`すべて通過 (${count} 件)`);
    process.exit(0);
}
console.log(`${failures} 件 失敗 (${count} 件中)`);
process.exit(1);
