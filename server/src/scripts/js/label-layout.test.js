/*
 * 札の置き方(`Main/label-layout.js`)の検査。**node で走らせる**。
 *
 *   node src/scripts/js/label-layout.test.js
 *
 * 画面を開かずに、「重なったらずらす → 横に付ける → 線で結ぶ → 屋外は出さない/建物の中は重ねる」を確かめる。
 * アプリの `LabelLayoutTest.kt` と同じ場面を使う。
 */
'use strict';

const path = require('path');
const layoutApi = require(path.join(__dirname, '..', '..', 'Main', 'label-layout.js'));

let failures = 0;
let count = 0;
function check(name, expected, actual) {
    count++;
    const ok = JSON.stringify(expected) === JSON.stringify(actual);
    if (!ok) {
        failures++;
        console.log('  NG ' + name + ': 期待 ' + JSON.stringify(expected) + ' / 実際 ' + JSON.stringify(actual));
    }
}

/** 点 (x, y) の真上に置いた幅 100・高さ 20 の札 */
function item(x, y, priority) {
    return {
        box: { left: x - 50, top: y - 6 - 20 - 4, right: x + 50, bottom: y - 6 - 4 },
        anchor: { x: x, y: y },
        radius: 6,
        priority: priority === undefined ? 3 : priority,
    };
}

function overlaps(a, b) {
    return !(a.right <= b.left + 2 || a.left >= b.right - 2 || a.bottom <= b.top + 2 || a.top >= b.bottom - 2);
}

function placedBox(it, p) {
    return { left: it.box.left + p.dx, top: it.box.top + p.dy, right: it.box.right + p.dx, bottom: it.box.bottom + p.dy };
}

// 1 つだけなら元の位置のまま・線なし
{
    const r = layoutApi.layout([item(200, 200)], {});
    check('1 つなら動かさない', { dx: 0, dy: 0, leader: null }, r[0]);
}

// 10px おきに固まった 8 つ(1〜3号棟の出入口のような所)。置いたものどうしは重ならない
{
    const items = Array.from({ length: 8 }, function (_, i) { return item(290 + (i % 4) * 10, 295 + Math.floor(i / 4) * 10); });
    const r = layoutApi.layout(items, { hideWhenCrowded: true, bounds: { left: 0, top: 0, right: 600, bottom: 600 } });
    const boxes = r.map(function (p, i) { return p ? placedBox(items[i], p) : null; }).filter(Boolean);
    let clash = false;
    for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) if (overlaps(boxes[i], boxes[j])) clash = true;
    check('置いた札どうしは重ならない', false, clash);
    check('上下のずらしより多く置ける(線を使う)', true, boxes.length > layoutApi.NUDGES.length);
    check('離して置いたものには線がある', true, r.some(function (p) { return p && p.leader; }));
    // 線は点の縁から札の縁まで。根元は点の中心から半径の距離
    const ledIndex = r.findIndex(function (p) { return p && p.leader; });
    const led = r[ledIndex];
    const d = Math.hypot(led.leader.x1 - items[ledIndex].anchor.x, led.leader.y1 - items[ledIndex].anchor.y);
    check('線の根元は点の縁', true, Math.abs(d - 6) < 0.01);
}

// 画面の外へは逃がさない(端の点)
{
    const items = Array.from({ length: 6 }, function () { return item(60, 40); });
    const bounds = { left: 0, top: 0, right: 400, bottom: 400 };
    const r = layoutApi.layout(items, { hideWhenCrowded: true, bounds: bounds });
    let outside = false;
    r.forEach(function (p, i) {
        if (!p || (p.dx === 0 && layoutApi.NUDGES.indexOf(p.dy) >= 0)) return;
        const b = placedBox(items[i], p);
        if (b.left < 0 || b.top < 0 || b.right > 400 || b.bottom > 400) outside = true;
    });
    check('ずらした先は画面の中', false, outside);
}

// 置けないとき: 屋外は出さない / 建物の中は元の位置で重ねる
{
    const many = Array.from({ length: 40 }, function () { return item(100, 100); });
    const bounds = { left: 0, top: 0, right: 220, bottom: 220 };
    const outdoor = layoutApi.layout(many, { hideWhenCrowded: true, bounds: bounds });
    const indoor = layoutApi.layout(many, { hideWhenCrowded: false, bounds: bounds });
    check('屋外は置けない札を出さない', true, outdoor.some(function (p) { return p === null; }));
    check('建物の中は札を消さない', false, indoor.some(function (p) { return p === null; }));
}

// ほかの点を札で隠さない(横・線の位置では)
{
    // 右の 30px に別の点。2 つ目の札は右へは行けない
    const a = item(200, 200);
    const b = item(200, 200);
    const nodes = [{ x: 200, y: 200, radius: 6 }, { x: 240, y: 200, radius: 6 }];
    const r = layoutApi.layout([a, b], { nodes: nodes, bounds: { left: 0, top: 0, right: 600, bottom: 600 } });
    const box = placedBox(b, r[1]);
    const coversOther = !(box.right <= 234 || box.left >= 246 || box.bottom <= 194 || box.top >= 206);
    check('ほかの点の上に札を置かない', false, coversOther && r[1].leader !== null);
}

// 線はほかの札を横切らない
{
    const items = Array.from({ length: 10 }, function (_, i) { return item(300 + (i % 3) * 8, 300 + Math.floor(i / 3) * 6); });
    const r = layoutApi.layout(items, { hideWhenCrowded: true, bounds: { left: 0, top: 0, right: 600, bottom: 600 } });
    const boxes = r.map(function (p, i) { return p ? placedBox(items[i], p) : null; });
    let crossed = false;
    r.forEach(function (p, i) {
        if (!p || !p.leader) return;
        const l = p.leader;
        boxes.forEach(function (b, j) {
            if (!b || j === i) return;
            // 点がもう別の札の下にあるときは、その札の下から出るしかない(線は札の後ろに描く)
            if (l.x1 > b.left && l.x1 < b.right && l.y1 > b.top && l.y1 < b.bottom) return;
            // 線の上を 50 等分して、札の内側(2px 縮めた箱)に入る点があるか
            for (let k = 0; k <= 50; k++) {
                const x = l.x1 + (l.x2 - l.x1) * k / 50;
                const y = l.y1 + (l.y2 - l.y1) * k / 50;
                if (x > b.left + 2 && x < b.right - 2 && y > b.top + 2 && y < b.bottom - 2) crossed = true;
            }
        });
    });
    check('線がほかの札の中を通らない', false, crossed);
}

// 大事な種類が元の位置を取る
{
    const room = item(200, 200, 3);
    const facility = item(200, 200, 0);
    const r = layoutApi.layout([room, facility], { nudges: [0], hideWhenCrowded: true });
    check('施設が元の位置', { dx: 0, dy: 0, leader: null }, r[1]);
    check('部屋はずれる', true, r[0] !== null && (r[0].dx !== 0 || r[0].dy !== 0));
}

// 選んだ場所(keep)は隠さない。ほかより先に置き、置けなくても元の位置で重ねる(2026-10-30)
{
    const many = Array.from({ length: 40 }, function () { return item(100, 100, 0); });
    const chosen = item(100, 100, 9);
    chosen.keep = true;
    const r = layoutApi.layout(many.concat([chosen]), { hideWhenCrowded: true, bounds: { left: 0, top: 0, right: 220, bottom: 220 } });
    check('keep の札は大事な種類より先に元の位置', { dx: 0, dy: 0, leader: null }, r[40]);
    check('ほかは混んでいれば隠れる', true, r.slice(0, 40).some(function (p) { return p === null; }));
    const crowded = layoutApi.layout([item(100, 100, 0), Object.assign(item(100, 100, 9), { keep: true })], { nudges: [0], hideWhenCrowded: true });
    check('keep の札は、大事な種類と重なっても出る', true, crowded[1] !== null);
}

console.log('  ' + (count - failures) + ' / ' + count + ' 件通りました');
process.exit(failures === 0 ? 0 : 1);
