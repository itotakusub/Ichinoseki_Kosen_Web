/*
 * 屋台のメニューと混み具合の見せ方(Main/stalls.js。2026-10-30、振り返りのフェーズ 5 の 2)。**node で走らせる**。
 *
 *   node src/scripts/js/stalls.test.js
 *
 * stalls.js は node で読むと純粋関数だけを返す(DOM の部分は動かない)。写しを置かない。
 */
'use strict';

const path = require('path');
const stalls = require(path.join(__dirname, '..', '..', 'Main', 'stalls.js'));

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

const now = Date.parse('2026-11-03T12:00:00+09:00');
check('何分前', ['たった今', '3 分前', '2 時間前', ''],
    [stalls.ago('2026-11-03T11:59:40+09:00', now), stalls.ago('2026-11-03T11:57:00+09:00', now), stalls.ago('2026-11-03T09:30:00+09:00', now), stalls.ago(null, now)]);
check('出すものが無ければ null', null, stalls.view({ name: 'a', menu: [], waiting: null, note: '' }, now));
const v = stalls.view({ name: 'たこ焼き屋', menu: [{ name: 'たこ焼き', price: 300, soldOut: false }, { name: 'ラムネ', price: null, soldOut: true }], waiting: 12, note: '焼きたて', waitingAt: '2026-11-03T11:55:00+09:00', menuAt: '2026-11-03T10:00:00+09:00' }, now);
check('待ちの人数・混み具合の色・値段・売り切れ・ひとこと・更新は待ちの時刻', ['12 人待ち', 'mid', [['たこ焼き', '300 円', false], ['ラムネ', '', true]], '焼きたて', '5 分前'],
    [v.wait, v.waitLevel, v.menu.map(m => [m.name, m.price, m.soldOut]), v.note, v.at]);
check('待ちなし・少ない・多い', [['待ちなし', 'low'], ['4 人待ち', 'low'], ['15 人待ち', 'high']],
    [0, 4, 15].map(n => (x => [x.wait, x.waitLevel])(stalls.view({ waiting: n }, now))));
check('待ちが無くメニューだけ(更新はメニューの時刻)', ['', '', '2 時間前'], (x => [x.wait, x.waitLevel, x.at])(stalls.view({ menu: [{ name: 'a' }], menuAt: '2026-11-03T10:00:00+09:00' }, now)));

console.log(failures === 0 ? `\nstalls: ${count} 件すべて通りました` : `\nstalls: ${failures} / ${count} 件が通りませんでした`);
process.exit(failures === 0 ? 0 : 1);
