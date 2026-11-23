/*
 * ストリートビュー(360°)の見る画面の計算(2026-11-07、段 6。Main/panorama-viewer.js の KmPanoramaMath)。**node で走らせる**。
 *
 *   node src/scripts/js/panorama.test.js
 */
'use strict';

const path = require('path');
const math = require(path.join(__dirname, '..', '..', 'Main', 'panorama-viewer.js'));

let failures = 0;
let count = 0;

function check(label, expected, actual) {
    count++;
    if (JSON.stringify(expected) !== JSON.stringify(actual)) {
        failures++;
        console.log(`  ${label.padEnd(44)} FAIL  (期待 ${JSON.stringify(expected)} / 実際 ${JSON.stringify(actual)})`);
        return;
    }
    console.log(`  ${label.padEnd(44)} PASS`);
}

const round = (value) => Math.round(value * 1000) / 1000;

console.log('\n== panorama: 向き・視野 ==');
check('見上げ・見下ろしは ±85° まで', [85, -85, 10], [math.clampPitch(120), math.clampPitch(-90), math.clampPitch(10)]);
check('視野は 30〜100°・数でなければ 75°', [30, 100, 60, 75], [math.clampFov(5), math.clampFov(500), math.clampFov(60), math.clampFov('x')]);
check('向きは 0 以上 360 未満', [350, 10, 0, 0], [math.wrapYaw(-10), math.wrapYaw(370), math.wrapYaw(360), math.wrapYaw(undefined)]);

console.log('\n== panorama: 指の動き ==');
// 写真を掴んで動かす: 右へ動かすと左を向く(yaw が減る)・下へ動かすと上を向く
const drag = math.dragDelta(100, 50, 60, 600);
check('右へ 100px(視野 60°・高さ 600px)→ 左へ 10°', -10, round(drag.yaw));
check('下へ 50px → 上へ 5°', 5, round(drag.pitch));
check('高さ 0 でも割り算で壊れない', true, Number.isFinite(math.dragDelta(10, 10, 60, 0).yaw));
check('2 本の指を広げると拡大(視野が狭く)', 40, math.pinchFov(80, 100, 200));
check('狭めると広く(上限 100°)', 100, math.pinchFov(80, 200, 100));
check('間隔が 0 なら変えない', 80, math.pinchFov(80, 0, 100));
check('ホイールを手前(負)で拡大・奥で縮小', [true, true], [math.wheelFov(75, -100) < 75, math.wheelFov(75, 100) > 75]);

console.log('\n== panorama: 地図の上の針 ==');
// heading = 写真の真ん中が地図のどの向きか(地図の上 = 0°・時計回り)
check('向きが無ければ針は出さない', [null, null], [math.facing(null, 30), math.needleRotation(undefined, 30)]);
check('写真の真ん中が地図の右(90°)を向き、右へ 30° 見回す → 120°', 120, math.facing(90, 30));
check('針はその逆(地図の上を指す)', 240, math.needleRotation(90, 30));
check('真ん中が地図の上で正面を見ていれば針は真上', 0, math.needleRotation(0, 0));

console.log('\n== panorama: 写真 ==');
check('上限より小さければそのまま', { width: 4096, height: 2048, scaled: false }, math.textureSize(4096, 2048, 8192));
check('端末の上限 4096 なら 2:1 のまま縮める', { width: 4096, height: 2048, scaled: true }, math.textureSize(8000, 4000, 4096));
check('端末が大きくても 8192 まで', { width: 8192, height: 4096, scaled: true }, math.textureSize(16384, 8192, 16384));
const hash = 'a'.repeat(64);
check('取り先はハッシュから作る', '/api/panorama.php?h=' + hash, math.photoUrl(hash));
check('ハッシュの形でなければ作らない(大文字・短い・パス)', [null, null, null], [math.photoUrl('A'.repeat(64)), math.photoUrl('abc'), math.photoUrl('../x')]);

console.log('');
if (failures === 0) {
    console.log(`すべて通過 (${count} 件)`);
    process.exit(0);
}
console.log(`${failures} 件失敗 (${count} 件中)`);
process.exit(1);
