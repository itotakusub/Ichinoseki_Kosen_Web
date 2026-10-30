/*
 * 予定の「会場 × 時間」の表の並べ方(Main/schedule-grid.js。2026-10-30、振り返りのフェーズ 5 の 1)。**node で走らせる**。
 *
 *   node src/scripts/js/schedule-grid.test.js
 *
 * schedule-grid.js は DOM を使わない純粋関数なので、そのまま読む(写しを置かない)。
 */
'use strict';

const path = require('path');
const grid = require(path.join(__dirname, '..', '..', 'Main', 'schedule-grid.js'));

let failures = 0;
let count = 0;
function check(label, expected, actual) {
    count++;
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        console.log('  ok   ' + label);
    } else {
        failures++;
        console.log('  FAIL ' + label + '\n       expected ' + e + '\n       actual   ' + a);
    }
}

const nodes = {
    stage: { name: '第一体育館' },
    hall: { name: '大講義室', alias: 'ホール' },
    lab: { name: '電算室' },
};
const pois = [{ id: 7, name: '21' }];
const e = (id, startsAt, endsAt, extra) => Object.assign({ id: id, title: 't' + id, startsAt: startsAt, endsAt: endsAt, nodeIds: [] }, extra || {});

check('時刻を読む・形が違えば null', [{ day: '2026-11-03', min: 630 }, null], [grid.parseTime('2026-11-03 10:30:00'), grid.parseTime('11/3 10:30')]);

check('会場: 会場の地点 → 番号の地点 → 一時地点 → その他・別名を先に',
    ['node:stage', 'ホール', 'node:lab', 'poi:7', ''],
    [grid.venueOf(e(1, '', null, { venueNodeId: 'stage', nodeIds: ['hall'] }), nodes, pois).key,
        grid.venueOf(e(2, '', null, { nodeIds: ['gone', 'hall'] }), nodes, pois).name,
        grid.venueOf(e(3, '', null, { venueNodeId: 'gone', nodeIds: ['lab'] }), nodes, pois).key,
        grid.venueOf(e(4, '', null, { poiIds: [7] }), nodes, pois).key,
        grid.venueOf(e(5, '', null), nodes, pois).key]);

const days = grid.layout([
    e(1, '2026-11-03 10:00:00', '2026-11-03 11:00:00', { venueNodeId: 'stage' }),
    e(2, '2026-11-03 10:30:00', '2026-11-03 11:30:00', { venueNodeId: 'stage' }),
    e(3, '2026-11-03 11:00:00', '2026-11-03 12:15:00', { venueNodeId: 'stage' }),
    e(4, '2026-11-03 09:45:00', null, { nodeIds: ['hall'] }),
    e(5, '2026-11-03 13:00:00', '2026-11-03 14:00:00'),
    e(6, '2026-11-04 10:00:00', '2026-11-05 09:00:00', { nodeIds: ['lab'] }),
    e(7, 'まちがい', null),
], nodes, pois);

check('日ごとに分ける(形の違う行は出さない)', ['2026-11-03', '2026-11-04'], days.map(d => d.day));
const d1 = days[0];
check('列: 早く始まる会場から・その他は右端・重なる会場はレーンを分ける',
    [['node:hall', 1, 1], ['node:stage', 2, 2], ['', 4, 1]], d1.venues.map(v => [v.key, v.col, v.lanes]));
check('列の数', 4, d1.columns);
check('行: 9:30 から 14:00 まで 30 分ずつ', [570, 9], [d1.startMin, d1.rows]);
check('マス: 列・始まりの行・終わりの行(含まない)。空いたレーンは使い直す',
    [[1, 1, 3], [2, 2, 4], [3, 3, 5], [2, 4, 7], [4, 8, 10]],
    [1, 2, 3, 4, 5].map(id => d1.cells.find(c => c.entry.id === id)).map(c => [c.col, c.rowStart, c.rowEnd]).sort((a, b) => a[1] - b[1] || a[0] - b[0]));
check('終わりの無い行は 30 分(9:45〜10:15 は 9:30 と 10:00 の 2 マスに掛かる)', [1, 3], (c => [c.rowStart, c.rowEnd])(d1.cells.find(c => c.entry.id === 4)));
check('日をまたぐ行はその日の終わりまで', [1, 29], (c => [c.rowStart, c.rowEnd])(days[1].cells[0]));
check('時刻の札', ['9:30', '10:00', '14:00'], [grid.slotLabel(570, 1), grid.slotLabel(570, 2), grid.slotLabel(570, 10)]);
check('空なら空', [], grid.layout([], nodes, pois));

console.log(failures === 0 ? `\nschedule-grid: ${count} 件すべて通りました` : `\nschedule-grid: ${failures} / ${count} 件が通りませんでした`);
process.exit(failures === 0 ? 0 : 1);
