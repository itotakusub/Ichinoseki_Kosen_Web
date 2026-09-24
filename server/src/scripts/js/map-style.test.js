/*
 * `map-style.js` の検査。**node で走らせる**(ブラウザを開かずに確かめる)。
 *
 *   node src/scripts/js/map-style.test.js
 *
 * ここで見るのは「どの倍率で何が出るか」。**設定を触っていない人の見た目が
 * 変わっていないこと**が一番大事で、それは目で見ても分からない ——
 * 元の Website は「zoom 1 以上で部屋名、それ未満は建物名」だった。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

/*
 * ブラウザの物を最小限だけ用意して読み込む。
 * `localStorage` は**わざと壊す** —— 読めない環境(プライベートウィンドウ)でも
 * 既定のまま動くことを、こちらで確かめておく。
 */
const sandbox = {
    window: {
        localStorage: {
            getItem() { throw new Error('使えない環境'); },
            setItem() { throw new Error('使えない環境'); },
        },
    },
};
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(
    fs.readFileSync(path.join(__dirname, '..', '..', 'Main', 'map-style.js'), 'utf8'),
    sandbox,
    { filename: 'map-style.js' }
);

const style = sandbox.window.KM_MAP_STYLE;

let failures = 0;
let count = 0;

function check(label, expected, actual) {
    count++;
    const ok = JSON.stringify(expected) === JSON.stringify(actual);
    if (!ok) {
        failures++;
        console.log(`  ${label.padEnd(44)} FAIL  (期待 ${JSON.stringify(expected)} / 実際 ${JSON.stringify(actual)})`);
        return;
    }
    console.log(`  ${label.padEnd(44)} PASS`);
}

function heading(text) {
    console.log(`\n== ${text} ==`);
}

heading('map-style: 壊れた localStorage でも動く');
const tuning = style.load();
check('既定に倒れる', 100, tuning.nodeSize);
check('種類の設定は空', {}, tuning.types);

heading('map-style: 倍率の読み替え');
// アプリの scale と Leaflet の zoom を繋ぐ。ここがずれると全部ずれる
check('zoom 0 は等倍', 1, style.scaleForZoom(0));
check('zoom 1 は 2 倍', 2, style.scaleForZoom(1));
check('zoom -1 は半分', 0.5, style.scaleForZoom(-1));

heading('map-style: 何をどの倍率で出すか');
/*
 * **元の Website と同じ見え方になること。**
 * 引いているとき(zoom 0)は建物名、寄ると(zoom 1)部屋名に入れ替わる。
 */
check('引くと建物名は出る', true, style.isVisible(tuning, 'facility', 0));
check('引くと部屋名は出ない', false, style.isVisible(tuning, 'room', 0));
check('寄ると部屋名が出る', true, style.isVisible(tuning, 'room', 1));
check('寄ると建物名は消える', false, style.isVisible(tuning, 'facility', 1));
// 出入口は縮小しても出す(建物の入口が分からないと案内が始められない)
check('出入口は引いても出る', true, style.isVisible(tuning, 'entrance', -1));
// 通路の点と Wi-Fi ルーターは既定で伏せる(従来の Website も管理者だけだった)
check('通路の点は既定で出さない', false, style.isVisible(tuning, 'road', 3));
check('ルーターは既定で出さない', false, style.isVisible(tuning, 'wifi_router', 3));
check('壁は寄れば出る', true, style.isVisible(tuning, 'wall', 1));

heading('map-style: 層の設定は倍率より強い');
// 「部屋名を消す」を選んだら、どれだけ寄っても出さない
const noRooms = style.load();
noRooms.roomNames = false;
check('部屋名を消したら寄っても出ない', false, style.isVisible(noRooms, 'room', 3));
const withRoads = style.load();
withRoads.roadGraph = true;
check('通路の点は選べば出る', true, style.isVisible(withRoads, 'road', 1));

heading('map-style: 種類ごとの設定');
const hidden = style.load();
hidden.types.stairs = { visible: false };
check('外した種類は出ない', false, style.isVisible(hidden, 'stairs', 3));
const early = style.load();
early.types.room = { showScale: 0 };
check('閾値を下げれば早く出る', true, style.isVisible(early, 'room', -1));

heading('map-style: 既定の形');
check('部屋は円', 'circle', style.typeSetting(tuning, 'room').shape);
check('階段は三角', 'triangle', style.typeSetting(tuning, 'stairs').shape);
// 知らない値が入っていても落ちない(手で書き換えられた localStorage)
const broken = style.load();
broken.types.room = { shape: 'banana' };
check('知らない形は既定へ', 'circle', style.typeSetting(broken, 'room').shape);

heading('map-style: 文字の大きさ');
/*
 * つまみ(50〜200%)を係数にする。**壊れた値でも倒れないこと** ——
 * localStorage は閲覧者の手元にあり、手で書き換えられる。
 * 0 を通すと**地図の字が全部消える**ので、範囲へ丸めてから返す。
 */
check('既定は等倍', 1, style.labelScale(style.load()));
check('150% は 1.5 倍', 1.5, style.labelScale({ labelSize: 150 }));
check('下限より小さくならない', 0.5, style.labelScale({ labelSize: 0 }));
check('上限より大きくならない', 2, style.labelScale({ labelSize: 1000 }));
check('数でなければ等倍', 1, style.labelScale({ labelSize: 'おおきく' }));
check('鍵が無くても等倍', 1, style.labelScale({}));

heading('map-style: 図形');
style.SHAPES.forEach(function (shape) {
    const path = style.shapePath(shape, 7);
    // **空の d を作らない。** 空だと図形が消え、地点が見えなくなる
    check(shape + ' は描ける', true, typeof path === 'string' && path.length > 5 && path[0] === 'M');
});
// 知らない形が来たら円にする(消さない)
check('知らない形は円になる', true, style.shapePath('banana', 7).indexOf('M0,-7') === 0);

heading('map-style: 色');
/*
 * **建物名と部屋名は違う色**(2026-09-03)。
 * 外を引くと建物名、寄ると部屋名へ入れ替わるが、
 * 同じ色の同じ丸だと**入れ替わったことが分からなかった**(利用者の指摘)。
 */
check('部屋と施設は違う色', true, style.color('facility') !== style.color('room'));
check('施設は深い teal', '#0F766E', style.color('facility'));
check('知らない種類にも色はある', '#94A3B8', style.color('teleporter'));

heading('map-style: アプリと同じ既定');
// **2箇所に値がある。**片方だけ直すと、また別物の地図になる
check('施設は六角', 'hexagon', style.typeSetting(tuning, 'facility').shape);
check('施設は大きめ', 130, style.typeSetting(tuning, 'facility').size);
check('部屋は等倍', 100, style.typeSetting(tuning, 'room').size);
check('道の点は小さめ', 70, style.typeSetting(tuning, 'road').size);

heading('map-style: 屋外で拡大したときの建物名(2026-09-25)');
/*
 * 以前は拡大すると屋外でも建物名が消えた。**既定は「薄く残す」**。
 * 屋内はこれまでどおり部屋名へ入れ替わる。アプリの FacilityZoomMode と同じ。
 */
check('既定は faded', 'faded', style.facilityZoomMode(tuning));
check('知らない値は faded', 'faded', style.facilityZoomMode({ facilityZoomed: '??' }));
check('屋外で拡大しても建物名は残る', true, style.isVisible(tuning, 'facility', 1, 'outside'));
check('屋外で拡大したら薄くする', true, style.facilityFaded(tuning, 1, 'outside'));
check('引いているときは薄くしない', false, style.facilityFaded(tuning, 0, 'outside'));
check('屋内は拡大すると消える', false, style.isVisible(tuning, 'facility', 1, '1'));
check('屋内は薄くしない', false, style.facilityFaded(tuning, 1, '1'));
const hideFacility = Object.assign({}, tuning, { facilityZoomed: 'hidden' });
check('「消す」なら屋外でも消える', false, style.isVisible(hideFacility, 'facility', 1, 'outside'));
check('「消す」なら薄くもしない', false, style.facilityFaded(hideFacility, 1, 'outside'));
const showFacility = Object.assign({}, tuning, { facilityZoomed: 'shown' });
check('「そのまま」なら残る', true, style.isVisible(showFacility, 'facility', 1, 'outside'));
check('「そのまま」なら薄くしない', false, style.facilityFaded(showFacility, 1, 'outside'));
const noNames = Object.assign({}, tuning, { roomNames: false });
check('建物名を消していれば出さない', false, style.isVisible(noNames, 'facility', 1, 'outside'));

console.log('');
if (failures === 0) {
    console.log(`すべて通過 (${count} 件)`);
    process.exit(0);
}
console.log(`${failures} 件 失敗 (${count} 件中)`);
process.exit(1);
