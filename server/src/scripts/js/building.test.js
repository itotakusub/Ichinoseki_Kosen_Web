/*
 * 施設から中の階へ入る道と、ラベルの重なりほどき。**node で走らせる**。
 *
 *   node src/scripts/js/building.test.js
 *
 * `app.js` は Leaflet と DOM を要求するので丸ごとは読めない。
 * routable.test.js と同じやり方で、**関数の本文だけを取り出して**確かめる ——
 * 写しを置くと、片方だけ直したときに気づけない。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const appJs = fs.readFileSync(path.join(__dirname, '..', '..', 'Main', 'app.js'), 'utf8');

/** `function 名前(…) { … }` を本文ごと取り出す。見つからなければ失敗にする。 */
function extract(name) {
    const match = appJs.match(new RegExp('function ' + name + '\\([\\s\\S]*?\\n    \\}'));
    if (!match) {
        console.log(`  ${name} を app.js から取り出せませんでした。`);
        process.exit(1);
    }
    return match[0];
}

// floorImages は app.js の中の変数(階 → 画像の URL)。**在る階だけ「中へ」を出す**
const sandbox = { graphData: { nodes: {} }, floorImages: {} };
vm.createContext(sandbox);
vm.runInContext(
    extract('normalizeBuildingText') + '\n' + extract('buildingFloorLinksFor')
        + '\n' + extract('facilityFloorLinks') + '\n' + extract('overlaps') + '\n;0;',
    sandbox,
    { filename: 'app.js (building)' }
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

heading('building: 施設から中の階へ');
/*
 * 本番の実測に合わせた並び。建物の階は**接続ID**から分かる ——
 * 「4号棟 出入り口」「4号棟 階段」「エレベーター 4号棟」。
 */
sandbox.graphData = {
    nodes: {
        b4: { floor: 'outside', type: 'facility', name: '4号棟' },
        hall: { floor: 'outside', type: 'facility', name: '萩友会館（食堂・保健室）' },
        gateOut: { floor: 'outside', type: 'entrance', transferGroupId: '4号棟 出入り口' },
        gate1: { floor: '1', type: 'entrance', transferGroupId: '4号棟 出入り口' },
        stair1: { floor: '1', type: 'stairs', transferGroupId: '4号棟 階段' },
        stair2: { floor: '2', type: 'stairs', transferGroupId: '4号棟 階段' },
        ev3: { floor: '3', type: 'stairs', transferGroupId: 'エレベーター 4号棟' },
        room2: { floor: '2', type: 'room', name: '第二実習室' },
        other: { floor: '4', type: 'stairs', transferGroupId: 'A階段' },
        hallOut: { floor: 'outside', type: 'entrance', transferGroupId: '萩友会館（食堂・保健室） 出入り口' },
    },
};
sandbox.floorImages = { '1': 'a.png', '2': 'b.png', '3': 'c.png', '4': 'd.png', 'outside': 'e.png' };
const links = sandbox.facilityFloorLinks(sandbox.graphData.nodes.b4);
check('その建物の階だけを、下から順に出す', ['1', '2', '3'], links.map((l) => l.floor));
// 同じ階に候補が複数あるときは**出入口**を選ぶ(入口として分かりやすい)
check('1F は出入口を入口にする', 'gate1', links[0].nodeId);
check('屋外は出さない(いま居る所)', 0, links.filter((l) => l.floor === 'outside').length);
check('別の建物の階段は混ざらない', 0, links.filter((l) => l.nodeId === 'other').length);
// 中の地図がまだ無い建物では**何も出さない**(押せる先の無いボタンを並べない)
check('中の地点が無ければ空', [], sandbox.facilityFloorLinks(sandbox.graphData.nodes.hall));
check('名前が無ければ空', [], sandbox.facilityFloorLinks({ name: '' }));
check('1文字の名前では当てに行かない', [], sandbox.facilityFloorLinks({ name: '池' }));
check('前後の空白は落とす', '4号棟', sandbox.normalizeBuildingText(' 4号棟 '));
check('全角の空白も落とし、大文字は小文字にする', 'a棟', sandbox.normalizeBuildingText('　A　棟　'));

heading('building: 建物の階(平面図 1 枚 = 1 つの階)');
/*
 * 2026-09-24。**中にまだ地点が無くても「中へ」を出す** —— 中をこれから作る建物でも、
 * 平面図を開けるようにするため。手がかり(keywords)はサーバーから届く
 * (`lib/building-floors.php` が正本)。
 */
sandbox.graphData.buildingFloors = [
    { id: 'bldg_library_1f', label: '図書館 1F', keywords: ['図書館', 'メディアセンター'] },
    { id: 'bldg_library_2f', label: '図書館 2F', keywords: ['図書館', 'メディアセンター'] },
    { id: 'bldg_gym1', label: '第一体育館', keywords: ['第一体育館'] },
];
sandbox.floorImages = {
    '1': 'a.png', '2': 'b.png', '3': 'c.png', 'outside': 'e.png',
    'bldg_library_1f': 'l1.png', 'bldg_library_2f': 'l2.png', 'bldg_gym1': 'g.png',
};
sandbox.graphData.nodes.library = { floor: 'outside', type: 'facility', name: 'メディアセンター（図書館）' };
sandbox.graphData.nodes.gym = { floor: 'outside', type: 'facility', name: '第一体育館' };
sandbox.graphData.nodes.libraryCounter = { floor: 'bldg_library_2f', type: 'room', name: 'カウンター' };

const libraryLinks = sandbox.facilityFloorLinks(sandbox.graphData.nodes.library);
check('別名でも結び付く(メディアセンター=図書館)', ['bldg_library_1f', 'bldg_library_2f'], libraryLinks.map((l) => l.floor));
check('押す文字は階の名前', ['図書館 1F', '図書館 2F'], libraryLinks.map((l) => l.label));
check('地点が無い階は寄せ先なしで開く', '', libraryLinks[0].nodeId);
check('地点があればそこへ寄せる', 'libraryCounter', libraryLinks[1].nodeId);
check('関わりの無い建物は混ざらない', ['bldg_gym1'], sandbox.facilityFloorLinks(sandbox.graphData.nodes.gym).map((l) => l.floor));
// 画像が配備されていない階は出さない(押しても真っ白になる)
sandbox.floorImages = { 'outside': 'e.png' };
check('画像が無ければ出さない', [], sandbox.facilityFloorLinks(sandbox.graphData.nodes.library));

heading('building: ラベルの重なり');
/*
 * ずらす・隠すの判定。**2px の余白**を持たせてある —— ちょうど接している程度は
 * 読めるので隠さない(隠しすぎると、地図から名前が消えたように見える)。
 */
const box = (top, left) => ({ top: top, bottom: top + 20, left: left, right: left + 100 });
check('重なっていれば true', true, sandbox.overlaps(box(0, 0), box(10, 10)));
check('離れていれば false', false, sandbox.overlaps(box(0, 0), box(40, 0)));
check('接しているだけなら重なりと見ない', false, sandbox.overlaps(box(0, 0), box(20, 0)));
check('横にずれていれば重ならない', false, sandbox.overlaps(box(0, 0), box(0, 120)));

console.log('');
if (failures === 0) {
    console.log(`すべて通過 (${count} 件)`);
    process.exit(0);
}
console.log(`${failures} 件失敗 (${count} 件中)`);
process.exit(1);
