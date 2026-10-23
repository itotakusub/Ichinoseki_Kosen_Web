/*
 * 場所の検索の一致率(Main/search-rank.js。2026-10-23、利用者の指示「一致率順に」)。**node で走らせる**。
 *
 *   node src/scripts/js/search-rank.test.js
 *
 * search-rank.js は DOM を使わない純粋関数なので、そのまま読む(写しを置かない)。
 */
'use strict';

const path = require('path');
const rank = require(path.join(__dirname, '..', '..', 'Main', 'search-rank.js'));

let failures = 0;
let count = 0;
function check(label, expected, actual) {
    count++;
    const ok = JSON.stringify(expected) === JSON.stringify(actual);
    if (!ok) failures++;
    console.log(`  ${label.padEnd(48)} ${ok ? 'PASS' : 'FAIL'}`);
    if (!ok) console.log('    期待: ' + JSON.stringify(expected) + '\n    実際: ' + JSON.stringify(actual));
}
const names = (items) => items.map((item) => item.name);

console.log('== search-rank: 文字のそろえ方 ==');
check('全角・半角・大文字・カタカナ・空白を同じに', ['といれ', 'といれ', '2e', '2e', 'a3'],
    [rank.normalize('トイレ'), rank.normalize('ﾄｲﾚ'), rank.normalize('２Ｅ'), rank.normalize('2 e'), rank.normalize('A-3')]);

console.log('== search-rank: 並び ==');
const items = [
    { name: '第二実習室', extras: ['管-105'], floor: '1' },
    { name: '実習室', floor: '2' },
    { name: '機械実習工場', floor: 'outside' },
    { name: '女子トイレ', extras: ['WC'], floor: '1' },
    { name: 'トイレ', floor: '2' },
    { name: 'たこ焼き屋さん本舗', extras: ['21', '模擬店'], floor: 'outside' },
    { name: '2E の展示', extras: ['2E 管-209', '32'], floor: '2' },
];
check('完全一致 → 前方一致 → 途中(同じ点なら短い方)', [['実習室', '第二実習室'], ['実習室', '第二実習室', '機械実習工場']], [names(rank.rank(items, '実習室')), names(rank.rank(items, '実習'))]);
check('同じ点ならいまの階が先(トイレ: 完全一致が先頭)', ['トイレ', '女子トイレ'], names(rank.rank(items, 'といれ', { floor: '1' })));
check('別名(企画の番号・部屋番号)でも当たる・名前より下', ['たこ焼き屋さん本舗'], names(rank.rank(items, '21')));
check('部屋番号の一部(管-209)で当たる', ['2E の展示'], names(rank.rank(items, '209')));
check('空白で区切った言葉は全部当たるものだけ', ['たこ焼き屋さん本舗'], names(rank.rank(items, 'たこ 模擬店')));
check('当たらなければ空・空の言葉も空', [[], []], [names(rank.rank(items, 'ゴミ箱')), names(rank.rank(items, '  '))]);
check('上限で切る', 1, rank.rank(items, '室', { limit: 1 }).length);
check('点数: 完全 1000 > 前方 800 > 語の頭 600 > 途中 400', [1000, 800, 600, 400],
    [rank.fieldScore('トイレ', 'といれ'), rank.fieldScore('トイレ前', 'といれ'), rank.fieldScore('1F トイレ', 'といれ'), rank.fieldScore('女子トイレ', 'といれ')]);

console.log('');
if (failures > 0) {
    console.log(`失敗 ${failures} 件(全 ${count} 件)`);
    process.exit(1);
}
console.log(`すべて通過 (${count} 件)`);
