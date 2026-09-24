/*
 * ルート案内の帯(2026-09-25)。**経路を「目指す所」の列にする**処理を確かめる。
 *
 *   node src/scripts/js/route.test.js
 *
 * building.test.js と同じやり方で、`app.js` から**関数の本文だけを取り出して**走らせる。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const appJs = fs.readFileSync(path.join(__dirname, '..', '..', 'Main', 'app.js'), 'utf8');

function extract(name) {
    const match = appJs.match(new RegExp('function ' + name + '\\([\\s\\S]*?\\n    \\}'));
    if (!match) {
        console.log(`  ${name} を app.js から取り出せませんでした。`);
        process.exit(1);
    }
    return match[0];
}

const sandbox = {
    graphData: { nodes: {} },
    // 本物(dijkstra.js)と同じ決め方。ここでは名前だけ見れば足りる
    Dijkstra: { isElevator: (node) => /エレベーター|elevator/i.test(String(node.name || '')) },
};
vm.createContext(sandbox);
vm.runInContext(
    extract('buildRouteSteps') + '\n' + extract('routeNodeLabel') + '\n;0;',
    sandbox,
    { filename: 'app.js (route)' }
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

console.log('\n== route: 目指す所の列 ==');

sandbox.graphData = {
    nodes: {
        a: { floor: '1', type: 'room', name: 'サーバー室' },
        r1: { floor: '1', type: 'road', name: '' },
        s1: { floor: '1', type: 'stairs', name: 'A階段 1F' },
        s2: { floor: '2', type: 'stairs', name: 'A階段 2F' },
        s3: { floor: '3', type: 'stairs', name: 'A階段 3F' },
        r3: { floor: '3', type: 'road', name: '' },
        goal: { floor: '3', type: 'room', name: '専-301' },
        e1: { floor: '1', type: 'entrance', name: '' },
        eo: { floor: 'outside', type: 'entrance', name: '' },
        lib: { floor: 'outside', type: 'facility', name: '図書館' },
        ev: { floor: '1', type: 'stairs', name: 'エレベーター' },
        st: { floor: '1', type: 'stairs', name: '' },
    },
};

const up = sandbox.buildRouteSteps(['a', 'r1', 's1', 's2', 's3', 'r3', 'goal']);
check('続けて上る階段は 1 つにまとめる', 2, up.length);
check('最初は 1F の階段', ['s1', 'transfer', '1', '3'], [up[0].nodeId, up[0].kind, up[0].floor, up[0].nextFloor]);
check('最後は目的地', ['goal', 'goal', '3'], [up[1].nodeId, up[1].kind, up[1].floor]);

const same = sandbox.buildRouteSteps(['a', 'r1', 's1']);
check('同じ階なら目的地だけ', [['s1', 'goal']], same.map(s => [s.nodeId, s.kind]));

const out = sandbox.buildRouteSteps(['a', 'e1', 'eo', 'lib']);
check('出入口で外へ', [['e1', 'transfer', 'outside'], ['lib', 'goal', undefined]],
    out.map(s => [s.nodeId, s.kind, s.nextFloor]));

// 2F で降りて廊下を歩いてから、別の階段で 3F へ —— **まとめてはいけない**(2F で一度着く)
sandbox.graphData.nodes.r2 = { floor: '2', type: 'road', name: '' };
sandbox.graphData.nodes.b2 = { floor: '2', type: 'stairs', name: 'B階段 2F' };
sandbox.graphData.nodes.b3 = { floor: '3', type: 'stairs', name: 'B階段 3F' };
const walk = sandbox.buildRouteSteps(['s1', 's2', 'r2', 'b2', 'b3', 'goal']);
check('途中で歩くなら階ごとに分ける', [['s1', '2'], ['b2', '3'], ['goal', undefined]],
    walk.map(s => [s.nodeId, s.nextFloor]));

check('空の経路は空', [], sandbox.buildRouteSteps([]));

console.log('\n== route: 呼び名 ==');
check('名前があればそのまま', 'サーバー室', sandbox.routeNodeLabel(sandbox.graphData.nodes.a));
check('名前の無い出入口', '出入口', sandbox.routeNodeLabel(sandbox.graphData.nodes.e1));
check('名前の無い階段', '階段', sandbox.routeNodeLabel(sandbox.graphData.nodes.st));
check('エレベーターはエレベーター', 'エレベーター', sandbox.routeNodeLabel(sandbox.graphData.nodes.ev));
check('分からないもの', 'この地点', sandbox.routeNodeLabel({ type: 'road', name: '' }));

console.log('');
if (failures === 0) {
    console.log(`すべて通過 (${count} 件)`);
    process.exit(0);
}
console.log(`${failures} 件 失敗 (${count} 件中)`);
process.exit(1);
