/*
 * 公開ページの英語(Main/i18n.js。2026-10-30、振り返りのフェーズ 6 の 1)。**node で走らせる**。
 *
 *   node src/scripts/js/i18n.test.js
 *
 * i18n.js は node では DOM に触らず、純粋関数だけを返す(写しを置かない)。
 */
'use strict';

const path = require('path');
const i18n = require(path.join(__dirname, '..', '..', 'Main', 'i18n.js'));

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

const t = i18n.translate;

console.log('辞書');
check('そのままの語', 'Admin login', t('管理者ログイン'));
check('前後の空白は残す', '  Admin login ', t('  管理者ログイン '));
check('日本語が無ければ触らない', 'Hello 123', t('Hello 123'));
check('辞書に無い名前はそのまま', '電算部の展示', t('電算部の展示'));
check('外全体図', 'Campus overview', t('外全体図'));
check('HTML の改行と字下げは 1 つの空白にして引く', true, /^\n  Login codes .* to be sure\.\n$/.test(t('\n  ログインの確認コードやお問い合わせの返信は、迷惑メールとして振り分けられることがあります。\n          このサイトからのメールを受信できるように設定しておくと確実です。\n')));
check('訳せない文の改行はそのまま返す', '\n  電算部\n    展示\n', t('\n  電算部\n    展示\n'));
check('号棟', 'Building 4', t('4号棟'));

console.log('型');
check('待ち人数', '12 waiting', t('12 人待ち'));
check('分前', '5 min ago', t('5 分前'));
check('件(1)', '1 result', t('1 件'));
check('件(複数)', '3 results', t('3 件'));
check('階', 'Floor 3', t('3階'));
check('地点の大きさ', 'Spots 120%', t('地点 120%'));
check('文字の大きさ', 'Text 80%', t('文字 80%'));
check('数を添えた札は中を訳して数を残す', 'Admin login (2)', t('管理者ログイン(2)'));
check('数を添えた札(辞書に無い)は日本語のまま', '電算部 (2)', t('電算部(2)'));
check('いいね は専用の型が先', 'Like (4)', t('いいね(4)'));
check('企画番号', 'Project A-12', t('企画 A-12'));
check('約 m', 'about 120 m', t('約 120 m'));
check('目印の名前は辞書に無ければそのまま', true, /講義室1/.test(t('講義室1を目印に右へ')));
check('目印の文は英語になる', false, /目印/.test(t('講義室1を目印に右へ')));
check('日付', true, /Nov/.test(t('11月3日(火)')));

console.log('区切り');
check('・ で分けて訳す', 'Admin login · Log in', t('管理者ログイン・一般ログイン'));
check('どれも訳せなければ元のまま', 'たこ焼き・焼きそば', t('たこ焼き・焼きそば'));

console.log('言語を決める');
check('?lang=en が一番', 'en', i18n.pickLanguage('?lang=en', 'ja', ['ja-JP']));
check('?lang=ja が一番', 'ja', i18n.pickLanguage('?x=1&lang=ja', 'en', ['en-US']));
check('覚えたもの', 'en', i18n.pickLanguage('', 'en', ['ja-JP']));
check('ブラウザが日本語', 'ja', i18n.pickLanguage('', null, ['ja-JP', 'en']));
check('ブラウザがほかの言語', 'en', i18n.pickLanguage('', null, ['ko-KR']));
check('何も無ければ日本語', 'ja', i18n.pickLanguage('', null, []));
check('おかしな値は無視', 'ja', i18n.pickLanguage('?lang=fr', 'xx', ['ja']));

console.log('名前の入れ替え');
const data = {
    nodes: {
        a: { name: '図書館', nameEn: 'Library', readings: ['としょかん'] },
        b: { name: '講義室1' },
    },
    eventPois: [{ name: '受付', nameEn: 'Reception' }, { name: '救護' }],
    posters: [{ title: 'ロボット', titleEn: 'Robots' }],
};
i18n.swapNames(data);
check('地点は英語の名前に', 'Library', data.nodes.a.name);
check('日本語の名前は読みに回す(日本語でも探せる)', ['としょかん', '図書館'], data.nodes.a.readings);
check('英語の名前が無い地点はそのまま', '講義室1', data.nodes.b.name);
check('一時地点', ['Reception', '救護'], data.eventPois.map((p) => p.name));
check('ポスター', 'Robots', data.posters[0].title);
check('ポスターの読み', ['ロボット'], data.posters[0].readings);
check('空でも落ちない', null, i18n.swapNames(null));

console.log('');
console.log(count + ' 件中 ' + (count - failures) + ' 件が通りました。');
process.exit(failures === 0 ? 0 : 1);
