/*
 * 経路の見せ方(Main/route-view.js。2026-10-24、利用者の指示「ルートの線が被っていたら透明度を上げる」
 * 「使用する階を常時表示」)。**node で走らせる**。
 *
 *   node src/scripts/js/route-view.test.js
 *
 * route-view.js は DOM を使わない純粋関数なので、そのまま読む(写しを置かない)。
 */
'use strict';

const path = require('path');
const view = require(path.join(__dirname, '..', '..', 'Main', 'route-view.js'));

let failures = 0;
let count = 0;
function check(label, expected, actual) {
    count++;
    const ok = JSON.stringify(expected) === JSON.stringify(actual);
    if (!ok) failures++;
    console.log(`  ${label.padEnd(48)} ${ok ? 'PASS' : 'FAIL'}`);
    if (!ok) console.log('    期待: ' + JSON.stringify(expected) + '\n    実際: ' + JSON.stringify(actual));
}
const pt = (x, y) => ({ x, y });
const box = (left, top, right, bottom) => ({ left, top, right, bottom });
// 区間を「重なるか + 両端の x」の形にして比べやすくする
const shape = (runs) => runs.map((run) => [run.overlap, run.points.map((p) => Math.round(p.x * 100) / 100)]);

console.log('== route-view: 線分と箱 ==');
check('真ん中を横切る', [0.4, 0.6], view.clipSegment(pt(0, 5), pt(10, 5), box(4, 0, 6, 10)));
check('かからない', null, view.clipSegment(pt(0, 5), pt(10, 5), box(4, 6, 6, 10)));
check('縦の線(dx = 0)でも見る', [0.2, 0.3], view.clipSegment(pt(5, 0), pt(5, 10), box(0, 2, 10, 3)));
check('箱の外で平行ならかからない', null, view.clipSegment(pt(0, 20), pt(10, 20), box(0, 0, 10, 10)));

console.log('== route-view: 重なる区間に分ける ==');
check('箱が無ければ 1 本(重ならない)', [[false, [0, 10]]],
    shape(view.splitRouteByObstacles([pt(0, 5), pt(10, 5)], [], 0)));
check('真ん中だけ重なる → 3 つ', [[false, [0, 4]], [true, [4, 6]], [false, [6, 10]]],
    shape(view.splitRouteByObstacles([pt(0, 5), pt(10, 5)], [box(4, 0, 6, 10)], 0)));
check('pad で箱を広げる', [[false, [0, 3]], [true, [3, 7]], [false, [7, 10]]],
    shape(view.splitRouteByObstacles([pt(0, 5), pt(10, 5)], [box(4, 0, 6, 10)], 1)));
check('始まりから重なる', [[true, [0, 2]], [false, [2, 10]]],
    shape(view.splitRouteByObstacles([pt(0, 5), pt(10, 5)], [box(-1, 0, 2, 10)], 0)));
check('重なる箱が 2 つ重なっていたら 1 つに畳む', [[false, [0, 2]], [true, [2, 6]], [false, [6, 10]]],
    shape(view.splitRouteByObstacles([pt(0, 5), pt(10, 5)], [box(2, 0, 5, 10), box(4, 0, 6, 10)], 0)));
// 曲がり角をまたいで重なりが続くときは 1 本の区間のまま(角で切れない)
const corner = view.splitRouteByObstacles([pt(0, 0), pt(10, 0), pt(10, 10)], [box(8, -1, 11, 2)], 0);
check('角をまたぐ重なりは 1 本', [false, true, false], corner.map((run) => run.overlap));
check('角の点を重なりの区間に入れる', [8, 10, 10], corner[1].points.map((p) => p.x));
// 区間の境目は途切れない(前の終わり = 次の始まり)
const runs = view.splitRouteByObstacles([pt(0, 5), pt(10, 5)], [box(4, 0, 6, 10)], 0);
check('境目は途切れない', true, runs.every((run, i) => i === 0
    || (run.points[0].x === runs[i - 1].points[runs[i - 1].points.length - 1].x)));
check('大きさの無い箱は数えない', [[false, [0, 10]]],
    shape(view.splitRouteByObstacles([pt(0, 5), pt(10, 5)], [box(4, 0, 4, 10)], 0)));
check('点が 1 つなら 1 つ・無ければ空', [1, 0],
    [view.splitRouteByObstacles([pt(1, 1)], [], 0).length, view.splitRouteByObstacles([], [], 0).length]);

console.log('== route-view: 経路が使う階 ==');
check('続けて同じ階は畳む・戻る階はもう一度', ['1', '3', '1'], view.routeFloorSequence(['1', '1', '3', '3', '1']));
check('空・null は飛ばす', ['1', 'outside'], view.routeFloorSequence(['1', null, '', 'outside']));
check('数でも文字にそろえる', ['1', '2'], view.routeFloorSequence([1, 1, 2]));
check('配列でなければ空', [], view.routeFloorSequence(null));

console.log('== route-view: 曲がる所(2026-10-30。アプリの RouteInstructions.kt と同じ角度) ==');
const p = (id, x, y, floor) => ({ id, x, y, floor: floor || '1' });
// 上が +y(Web の地図)。東へ進んでから北(上)へ = 左へ曲がる
check('東 → 北は左(上が +y)', [{ index: 1, id: 'b', turn: 'left', degrees: 90 }], view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('c', 10, 10)]));
check('東 → 南は右', 'right', view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('c', 10, -10)])[0].turn);
check('アプリの向き(下が +y)なら同じ動きは左右が逆', 'right', view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('c', 10, 10)], { yDown: true })[0].turn);
check('19° は直進(手順にしない)', [], view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('c', 20, 3.4)]));
check('45° は斜め', 'slight-left', view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('c', 20, 10)])[0].turn);
check('150° は引き返す', 'back', view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('c', 1.3, 5)])[0].turn);
check('階が変わる所で向きを数え直す', [], view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('s2', 10, 0, '2'), p('c', 10, 10, '2')]));
check('短すぎる区間は向きに使わない(点のずれで曲がらない)', [], view.routeTurns([p('a', 0, 0), p('b', 10, 0), p('b2', 10, 1), p('c', 20, 1)]));

const marks = [
    { id: 'b', name: '', x: 10, y: 0, floor: '1' },
    { id: 'r1', name: '講義室1', x: 14, y: 3, floor: '1' },
    { id: 'r2', name: '遠い部屋', x: 200, y: 0, floor: '1' },
    { id: 'r3', name: '上の階', x: 10, y: 1, floor: '2' },
];
check('目印: 近くの名前のある地点', 'r1', (view.turnLandmark(p('b', 10, 0), marks, 40) || {}).id);
check('目印: 曲がる地点に名前があればそれ', 's', (view.turnLandmark(p('s', 10, 0), marks.concat([{ id: 's', name: 'A階段', x: 10, y: 0, floor: '1' }]), 40) || {}).id);
check('目印: 遠い・別の階は使わない', null, view.turnLandmark(p('b', 100, 100), marks, 40));
check('言い方: 目印あり', '講義室1を目印に右へ', view.turnPhrase('right', '講義室1'));
check('言い方: 目印なし', '左へ曲がる', view.turnPhrase('left', ''));
check('言い方: 引き返す', 'A階段で引き返す', view.turnPhrase('back', 'A階段'));

console.log('== route-view: 階段の上り・下り(アプリの StairGuide.kt と同じ) ==');
const key = (n) => n.group;
const isEv = (n) => /エレベーター/.test(n.name || '');
const s = (id, floor, group, name) => ({ id, type: 'stairs', floor, group, name: name || '' });
const stairs = [s('a1', '1', 'A'), s('a2', '2', 'A'), s('a3', '3', 'A'), s('a5', '5', 'A'), s('e1', '1', 'E', 'エレベーター'), s('e3', '3', 'E', 'エレベーター')];
check('行ける階: 上は近い順・下も近い順', { up: [3, 5], down: [1], elevator: false }, view.stairReach(stairs[1], stairs, key, isEv));
check('階の並び: 続いていれば〜', '2〜5F', view.floorRangeLabel([5, 3, 2, 4]));
check('階の並び: 飛んでいれば・', '3・5F', view.floorRangeLabel([5, 3]));
check('札: 上行き・下行き', ['▲上行き 3・5F', '▼下行き 1F'], view.stairReachLines(view.stairReach(stairs[1], stairs, key, isEv), 2).map((l) => l.text));
check('札: エレベーターは ⇅ 1 行(今の階も)', ['⇅ 1・3F'], view.stairReachLines(view.stairReach(stairs[4], stairs, key, isEv), 1).map((l) => l.text));
check('組の無い階段は null', null, view.stairReach(s('z', '1', 'Z'), stairs, key, isEv));
const label = (f) => (f === 'outside' ? '外' : f + 'F');
const routeNodes = [{ id: 'r', type: 'room', floor: '1' }, stairs[0], stairs[1], { id: 'x', type: 'room', floor: '2' }];
check('経路の札: 出発の階は「▲ 2F へ上る」', [{ id: 'a1', text: '▲ 2F へ上る', goingUp: true, arriving: false }], view.routeStairMarks(routeNodes, '1', label, isEv));
check('経路の札: 着いた階は「1F から到着」', [{ id: 'a2', text: '1F から到着', goingUp: true, arriving: true }], view.routeStairMarks(routeNodes, '2', label, isEv));
check('経路の札: 下りる', '▼ 1F へ下りる', view.routeStairMarks(routeNodes.slice().reverse(), '2', label, isEv)[0].text);
check('経路の札: エレベーター', 'エレベーターで 3F へ', view.routeStairMarks([stairs[4], stairs[5]], '1', label, isEv)[0].text);

console.log(`\n${count - failures} / ${count} 件通過`);
if (failures > 0) process.exit(1);
