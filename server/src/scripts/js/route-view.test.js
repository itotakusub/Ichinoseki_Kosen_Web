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

console.log(`\n${count - failures} / ${count} 件通過`);
if (failures > 0) process.exit(1);
