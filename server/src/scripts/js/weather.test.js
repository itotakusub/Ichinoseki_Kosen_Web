/*
 * 一関の天気と「雨の日」の自動(Main/weather.js。2026-11-05)。**node で走らせる**。
 *
 *   node src/scripts/js/weather.test.js
 *
 * weather.js の純粋な部分(rainState・autoText・dayText・dayOf)は DOM を使わないので、そのまま読む。
 */
'use strict';

const path = require('path');
const weather = require(path.join(__dirname, '..', '..', 'Main', 'weather.js'));

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

const today = '2026-11-05';
const rainy = { rain: true, reason: 'forecast', pop: 60, snow: false, observed: null };
const dry = { rain: false, reason: 'none', pop: 20, snow: false, observed: { precip10m: 0, precip1h: 0 } };

console.log('== 雨の日: 自動と、その日だけの手動 ==');
check('天気が雨なら付ける(自動)', { rain: true, mode: 'auto' }, weather.rainState({}, rainy, today));
check('止めば外す(自動)', { rain: false, mode: 'auto' }, weather.rainState({ rain: true }, dry, today));
check('今日手で外したら、雨でも外したまま', { rain: false, mode: 'manual' }, weather.rainState({ rain: false, rainManualDay: today }, rainy, today));
check('今日手で付けたら、晴れでも付けたまま', { rain: true, mode: 'manual' }, weather.rainState({ rain: true, rainManualDay: today }, dry, today));
check('昨日の手動は効かない(次の日は自動)', { rain: true, mode: 'auto' }, weather.rainState({ rain: false, rainManualDay: '2026-11-04' }, rainy, today));
check('天気が分からなければ付けない', { rain: false, mode: 'unknown' }, weather.rainState({ rain: true }, { rain: false, reason: 'unknown' }, today));
check('天気が取れていなければ付けない', { rain: false, mode: 'unknown' }, weather.rainState({ rain: true }, null, today));
check('端末の今日の形', '2026-01-09', weather.localDay(new Date(2026, 0, 9, 23, 59)));

console.log('== 文 ==');
check('観測の雨(1 時間の量)', '自動: 一関で雨(1 時間 1.5mm)・気象庁', weather.autoText({ reason: 'observed', rain: true, observed: { precip1h: 1.5, precip10m: 0.5 } }, 'ja'));
check('観測の雨(10 分だけ)', '自動: 一関で雨(10 分 0.5mm)・気象庁', weather.autoText({ reason: 'observed', rain: true, observed: { precip1h: 0, precip10m: 0.5 } }, 'ja'));
check('観測の雪', '自動: 一関で雪・気象庁', weather.autoText({ reason: 'observed', rain: true, snow: true, observed: {} }, 'ja'));
check('予報の雨', '自動: 雨の予報(降水 60%)・気象庁', weather.autoText(rainy, 'ja'));
check('雨でない', '自動: 雨の予報なし(降水 20%)・気象庁', weather.autoText(dry, 'ja'));
check('分からない', '自動: 天気が分かりません', weather.autoText(null, 'ja'));
check('英語', 'Auto: rain expected (60%) · JMA', weather.autoText(rainy, 'en'));

const day = { date: '2026-11-06', kind: 'rain', telop: '曇一時雨', telopEn: 'CLOUDY, OCCASIONAL SCATTERED SHOWERS', pop: 50, min: 10, max: 18 };
check('予定の見出しの天気', '☂ 曇一時雨・降水 50%・18℃/10℃', weather.dayText(day, 'ja'));
check('気温の無い日', '☁ 曇・降水 40%', weather.dayText({ kind: 'cloudy', telop: '曇', pop: 40, min: null, max: null }, 'ja'));
check('英語の見出し', '☂ Cloudy, occasional scattered showers · 50% rain · 18°C/10°C', weather.dayText(day, 'en'));
check('日付で当てる(時刻付きでも)', day, weather.dayOf({ days: [day] }, '2026-11-06 10:00:00'));
check('予報の無い日は null', null, weather.dayOf({ days: [day] }, '2026-12-24'));

console.log(failures === 0 ? '\nすべて通過 (' + count + ' 件)' : '\n' + failures + ' 件失敗 / ' + count + ' 件');
process.exit(failures === 0 ? 0 : 1);
