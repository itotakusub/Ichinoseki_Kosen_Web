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

console.log('');
if (failures === 0) {
    console.log(`すべて通過 (${count} 件)`);
    process.exit(0);
}
console.log(`${failures} 件 失敗 (${count} 件中)`);
process.exit(1);
