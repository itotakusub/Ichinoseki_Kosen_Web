/*
 * `Main/area-segmentation.js` の検査(2026-10-30)。アプリの AreaSegmentationTest.kt と同じ場面を同じ答えで確かめる
 * (計算の決まりを 2 か所に持つので、片方だけ変わったら気づけるように)。
 *
 *   node src/scripts/js/area-segmentation.test.js
 */
'use strict';

const area = require('../../Main/area-segmentation.js');
const { AreaKind, AreaPalette } = area;

let failures = 0;
let count = 0;
function check(name, expected, actual) {
    count++;
    const ok = JSON.stringify(expected) === JSON.stringify(actual);
    if (!ok) failures++;
    console.log(`  ${name.padEnd(44)} ${ok ? 'PASS' : 'FAIL'}`);
    if (!ok) console.log(`      期待: ${JSON.stringify(expected)}\n      実際: ${JSON.stringify(actual)}`);
}

const W = 100;
const H = 60;
/** 100×60 の画像: 外枠(10..90, 10..50)の中を x=40 と x=60 の縦線で 3 つに分ける(右の線に 3px の切れ目を開けられる) */
function image(gapInRight = false) {
    const px = new Uint8ClampedArray(W * H * 4).fill(255);
    const dot = (x, y) => { const i = (y * W + x) * 4; px[i] = 0; px[i + 1] = 0; px[i + 2] = 0; px[i + 3] = 255; };
    for (let x = 10; x <= 90; x++) { dot(x, 10); dot(x, 50); }
    for (let y = 10; y <= 50; y++) { dot(10, y); dot(90, y); dot(40, y); }
    for (let y = 10; y <= 50; y++) if (!(gapInRight && y >= 28 && y <= 30)) dot(60, y);
    return px;
}
const seeds = [
    { uuid: 'room-a', type: 'room', x: 25, y: 30 },
    { uuid: 'road-1', type: 'road', x: 50, y: 20 },
    { uuid: 'road-2', type: 'road', x: 50, y: 40 },
    { uuid: 'room-b', type: 'room', x: 75, y: 30 },
];
const rgbAt = (px, x, y) => { const i = (y * W + x) * 4; return (px[i] << 16) | (px[i + 1] << 8) | px[i + 2]; };

console.log('\n== 区画に分ける ==');
{
    const map = area.segmentAreas(image(), W, H, seeds, [], false);
    const a = map.componentAt(25, 30);
    const corridor = map.componentAt(50, 30);
    const b = map.componentAt(75, 30);
    check('部屋 A は部屋・開く地点は room-a', [AreaKind.ROOM, 'room-a'], [a.kind, a.roomUuid]);
    check('真ん中は廊下・開く地点なし', [AreaKind.CORRIDOR, null], [corridor.kind, corridor.roomUuid]);
    check('部屋 B の開く地点', 'room-b', b.roomUuid);
    check('外側は画像の端に届くので建物の外', AreaKind.EXTERIOR, map.componentAt(2, 2).kind);
    check('線の上は区画なし', null, map.componentAt(40, 30));
}
{
    const leaky = area.segmentAreas(image(true), W, H, seeds, [], false);
    check('3px の切れ目は塞がらない(廊下と部屋 B がつながる)', true, leaky.componentAt(50, 30).id === leaky.componentAt(75, 30).id);
    const fixed = area.segmentAreas(image(true), W, H, seeds, [{ x1: 60, y1: 10, x2: 60, y2: 50 }], false);
    check('データの壁の線で塞げば分かれる', true, fixed.componentAt(50, 30).id !== fixed.componentAt(75, 30).id);
    check('塞いだあと部屋 B は room-b', 'room-b', fixed.componentAt(75, 30).roomUuid);
}
{
    const map = area.segmentAreas(image(), W, H, [{ uuid: 'room-a', type: 'room', x: 25, y: 30 }], [], false);
    check('地点の無い広い区画(12%)は塗らない', AreaKind.NONE, map.componentAt(50, 30).kind);
    const onLine = area.segmentAreas(image(), W, H, [{ uuid: 'room-a', type: 'room', x: 39, y: 30 }], [], false);
    check('線の上の地点は近くの区画へ', 'room-a', onLine.componentAt(25, 30).roomUuid);
}

console.log('\n== 種類の決め方 ==');
{
    const s = (type, n) => Array.from({ length: n }, (_, i) => ({ uuid: type + i, type, x: 0, y: 0 }));
    check('部屋 1 + 道 2 は部屋', AreaKind.ROOM, area.classifyArea(s('room', 1).concat(s('road', 2)), false)[0]);
    check('部屋 1 + 道 3 は廊下', AreaKind.CORRIDOR, area.classifyArea(s('room', 1).concat(s('road', 3)), false)[0]);
    check('階段だけは階段', AreaKind.STAIRS, area.classifyArea(s('stairs', 1), false)[0]);
    check('部屋 2 つで道なしはあいまい', AreaKind.AMBIGUOUS, area.classifyArea(s('room', 2), false)[0]);
    check('屋内の施設の札は部屋があれば数えない', [AreaKind.ROOM, 'room0'], area.classifyArea(s('room', 1).concat(s('facility', 1)), false));
    check('部屋が無ければ施設を部屋として', [AreaKind.ROOM, 'facility0'], area.classifyArea(s('facility', 1), false));
    check('地点が無い屋内は名前なし', AreaKind.UNKNOWN, area.classifyArea([], false)[0]);
    check('屋外: 道があれば道', AreaKind.ROAD, area.classifyArea(s('road', 1).concat(s('facility', 1)), true)[0]);
    check('屋外: 施設 1 つは押せる建物', [AreaKind.BUILDING, 'facility0'], area.classifyArea(s('facility', 1), true));
    check('屋外: 地点が無ければ塗らない', AreaKind.NONE, area.classifyArea([], true)[0]);
}

console.log('\n== 塗る ==');
{
    const px = image();
    const map = area.segmentAreas(px, W, H, seeds, [], false);
    const painted = area.paintAreas(px, map, (kind) => area.colorOf(kind, false));
    check('部屋は空色', AreaPalette.ROOM, rgbAt(painted, 25, 30));
    check('廊下は淡い黄', AreaPalette.CORRIDOR, rgbAt(painted, 50, 30));
    check('線はそのまま', 0x000000, rgbAt(painted, 40, 30));
    check('建物の外は塗らない', 0xFFFFFF, rgbAt(painted, 2, 2));
    check('線の脇の 1px も塗る', AreaPalette.ROOM, rgbAt(painted, 39, 30));
}

console.log('\n== イベントで隠した部屋・手で決めた種類と色 ==');
{
    const hidden = seeds.map((s) => (s.uuid === 'room-a' ? Object.assign({}, s, { tappable: false }) : s));
    const map = area.segmentAreas(image(), W, H, hidden, [], false);
    check('隠した部屋は部屋の色だが開かない', [AreaKind.ROOM, null], [map.componentAt(25, 30).kind, map.componentAt(25, 30).roomUuid]);
    check('ほかの部屋はそのまま開く', 'room-b', map.componentAt(75, 30).roomUuid);
}
{
    const green = 0xA8E0C8;
    const manual = seeds.map((s) => (s.uuid === 'room-a' ? Object.assign({}, s, { kind: 'corridor' }) : s))
        .concat([{ uuid: 'marker', type: 'area', x: 75, y: 35, tappable: false, color: green }]);
    const map = area.segmentAreas(image(), W, H, manual, [], false);
    check('部屋を廊下にした → 廊下・開かない', [AreaKind.CORRIDOR, null], [map.componentAt(25, 30).kind, map.componentAt(25, 30).roomUuid]);
    check('印で色だけ決めた → 部屋のまま開く', [AreaKind.ROOM, 'room-b'], [map.componentAt(75, 30).kind, map.componentAt(75, 30).roomUuid]);
    const painted = area.paintAreas(image(), map, (kind) => area.colorOf(kind, false));
    check('その色で塗る', green, rgbAt(painted, 75, 30));
    const courtyard = area.segmentAreas(image(), W, H, [{ uuid: 'm', type: 'area', x: 50, y: 30, tappable: false, color: green }], [], false);
    check('塗らない広い区画も印で色を決めれば塗る', [AreaKind.UNKNOWN, green], [courtyard.componentAt(50, 30).kind, courtyard.componentAt(50, 30).color]);
    check('「塗らない」なら色も付けない', [AreaKind.NONE, null], area.applyManualArea(AreaKind.ROOM, [{ uuid: 'm', type: 'area', kind: 'none', color: green }]));
    check('漏れには当てない', [AreaKind.LEAK, null], area.applyManualArea(AreaKind.LEAK, [{ uuid: 'm', type: 'area', color: green }]));
    check('知らない種類は無視', null, area.manualAreaKind('kitchen'));
}

console.log(failures === 0 ? `\nすべて通過 (${count} 件)` : `\n${failures} 件の失敗 (${count} 件中)`);
process.exit(failures === 0 ? 0 : 1);
