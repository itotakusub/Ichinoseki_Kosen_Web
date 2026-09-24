/*
 * 出発地・目的地に選べる地点の判定。**node で走らせる**。
 *
 *   node src/scripts/js/routable.test.js
 *
 * `app.js` は Leaflet と DOM を要求するので丸ごとは読めない。
 * 判定の関数だけを切り出して読み、**同じ本文**を確かめる ——
 * 写しを置くと、片方だけ直したときに気づけない。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const appJs = fs.readFileSync(
    path.join(__dirname, '..', '..', 'Main', 'app.js'),
    'utf8'
);

/*
 * `function isRoutableNode(node) { … }` をそのまま取り出す。
 * **見つからなければ失敗にする** —— 名前を変えたのに検査だけ通る、を作らない。
 */
const match = appJs.match(/function isRoutableNode\(node\) \{[\s\S]*?\n    \}/);
if (!match) {
    console.log('  isRoutableNode を app.js から取り出せませんでした。');
    process.exit(1);
}

const sandbox = {};
vm.createContext(sandbox);
const isRoutableNode = vm.runInContext(
    match[0] + '\n;isRoutableNode;',
    sandbox,
    { filename: 'app.js (isRoutableNode)' }
);

let failures = 0;
let count = 0;

function check(label, expected, actual) {
    count++;
    if (expected !== actual) {
        failures++;
        console.log(`  ${label.padEnd(44)} FAIL  (期待 ${expected} / 実際 ${actual})`);
        return;
    }
    console.log(`  ${label.padEnd(44)} PASS`);
}

function heading(text) {
    console.log(`\n== ${text} ==`);
}

const node = (over) => Object.assign({ type: 'room', floor: '1', name: '第二実習室' }, over);

heading('routable: 人が行ける場所だけ');
check('部屋は選べる', true, isRoutableNode(node()));
check('階段も選べる', true, isRoutableNode(node({ type: 'stairs', name: '中央階段' })));
check('出入口も選べる', true, isRoutableNode(node({ type: 'entrance', name: '正面玄関' })));
check('通路の点は選べない', false, isRoutableNode(node({ type: 'road', name: '' })));
check('壁は選べない', false, isRoutableNode(node({ type: 'wall', name: '壁' })));
check('ルーターは選べない', false, isRoutableNode(node({ type: 'wifi_router', name: 'AP-1' })));
check('名前が無ければ選べない', false, isRoutableNode(node({ name: '  ' })));
check('null は選べない', false, isRoutableNode(null));

heading('routable: 建物名は屋外だけ');
/*
 * **1〜5F の建物名は行き先にできない**(2026-09-03、利用者の指示)。
 * 「その建物のどこか」でしかなく、屋内の階では**どこへ向かえばよいか決められない**。
 * 屋外図では建物そのものが行き先になりうるので、そちらは残す。
 */
check('1F の建物名は選べない', false, isRoutableNode(node({ type: 'facility', floor: '1', name: '管理棟' })));
check('5F の建物名も選べない', false, isRoutableNode(node({ type: 'facility', floor: '5', name: '管理棟' })));
check('屋外の建物名は選べる', true, isRoutableNode(node({ type: 'facility', floor: 'outside', name: '管理棟' })));
// 階は文字列で来る。数値で来ても取り違えない
check('階が数値でも同じに扱う', false, isRoutableNode(node({ type: 'facility', floor: 1, name: '管理棟' })));
check('屋外の部屋は今までどおり', true, isRoutableNode(node({ floor: 'outside' })));

console.log('');
if (failures === 0) {
    console.log(`すべて通過 (${count} 件)`);
    process.exit(0);
}
console.log(`${failures} 件 失敗 (${count} 件中)`);
process.exit(1);
