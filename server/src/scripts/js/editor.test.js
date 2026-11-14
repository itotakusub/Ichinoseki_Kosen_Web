/*
 * Web Code Editor(/editor/。2026-11-08)の純粋関数の検査。**node で走らせる**。
 *
 *   node src/scripts/js/editor.test.js
 *
 * 元は server/editor-src/src/(ESM)。配備しない場所なので、無ければ飛ばす(本番のホストには node も元も無い)。
 * 補完のデータの突き合わせは editor-src/node_modules(Monaco の標準データ)が要る。無ければその部分だけ飛ばす。
 */
'use strict';

const path = require('path');
const fs = require('fs');
const { pathToFileURL } = require('url');

const SRC = path.join(__dirname, '..', '..', '..', 'editor-src', 'src');
const NODE_MODULES = path.join(__dirname, '..', '..', '..', 'editor-src', 'node_modules');

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
function ok(label, cond) {
    check(label, true, Boolean(cond));
}
const load = (rel) => import(pathToFileURL(path.join(SRC, rel)).href);

(async () => {
    if (!fs.existsSync(SRC)) {
        console.log('editor-src が無いので飛ばします(配備先では正しい)。');
        return;
    }
    const hb = await load('preview/htmlBuilder.js');
    const st = await load('settings.js');
    const sg = await load('storage.js');
    const i18n = await load('i18n.js');
    const tpl = await load('templates.js');
    const merge = await load('data/merge.js');
    const { HTML_JA, HTML_SNIPPETS_EN } = await load('data/html.js');
    const { CSS_JA } = await load('data/css.js');
    const { JS_API, JS_SNIPPETS } = await load('data/js.js');

    console.log('[htmlBuilder]');
    check('</style を崩さない', 'a<\\/style>b', hb.escapeStyleText('a</style>b'));
    check('</SCRIPT も直す', 'x<\\/SCRIPT>', hb.escapeScriptText('x</SCRIPT>'));
    check('<!-- を直す', '<\\!--', hb.escapeScriptText('<!--'));
    check('lineOf', 3, hb.lineOf('a\nb\nc', 4));
    {
        const r = hb.buildPreviewDocument({ html: '<p>hi</p>', css: 'p{color:red}', js: 'console.log(1)' }, { runId: 7, lang: 'en' });
        ok('断片は包む(DOCTYPE)', r.text.startsWith('<!DOCTYPE html>'));
        ok('lang を入れる', r.text.includes('<html lang="en">'));
        ok('CSS は head に', r.text.indexOf('p{color:red}') < r.text.indexOf('</head>'));
        ok('JS は body の終わりに', r.text.indexOf('console.log(1)') > r.text.indexOf('<p>hi</p>'));
        ok('橋の台本が先', r.text.indexOf('km-editor-console') < r.text.indexOf('console.log(1)'));
        ok('runId を台本に', r.text.includes('ID=7'));
        const lines = r.text.split('\n');
        check('JS の 1 行目の行番号', 'console.log(1)', lines[r.jsStartLine - 1]);
        ok('行番号を台本に', r.text.includes('JS=' + r.jsStartLine));
    }
    {
        const html = '<!DOCTYPE html>\n<html>\n<head>\n<title>t</title>\n</head>\n<body>\n<h1>x</h1>\n</body>\n</html>';
        const r = hb.buildPreviewDocument({ html, css: 'h1{}', js: 'a()\nb()' }, { runId: 1 });
        ok('完全な文書: 包まない', r.text.split('<html').length === 2);
        ok('完全な文書: 台本は <head> の直後', /<head>\n<script>\(function\(\)\{/.test(r.text));
        ok('完全な文書: style は </head> の前', r.text.indexOf('<style>') < r.text.indexOf('</head>'));
        ok('完全な文書: script は </body> の前', r.text.indexOf('b()') < r.text.lastIndexOf('</body>'));
        check('完全な文書: JS の行番号', 'a()', r.text.split('\n')[r.jsStartLine - 1]);
    }
    {
        const r = hb.buildPreviewDocument({ html: '<body><p>x</p></body>', css: '', js: '' }, {});
        ok('head が無い文書: head を足す', r.text.includes('<head>') && r.text.indexOf('<head>') < r.text.indexOf('<body>'));
        check('JS が空なら行番号 0', 0, r.jsStartLine);
    }
    {
        const r = hb.buildPreviewDocument({ html: '', css: '', js: "var s = '</script><script>alert(1)</script>';" }, {});
        check('JS の中の </script> で要素が閉じない', 2, r.text.split('</script>').length - 1);
    }
    {
        const single = hb.buildExportDocument({ html: '<p>a</p>', css: 'p{}', js: 'x()' }, { mode: 'single', lang: 'ja', title: 'T<b>' });
        ok('ダウンロード(1 つ): 橋の台本は入れない', !single.includes('km-editor-console'));
        ok('ダウンロード(1 つ): style と script', single.includes('<style>') && single.includes('x()'));
        ok('ダウンロード: 題名の < > を外す', single.includes('<title>Tb</title>'));
        const split = hb.buildExportDocument({ html: '<p>a</p>', css: 'p{}', js: '' }, { mode: 'split' });
        ok('ダウンロード(3 つ): style.css を読む', split.includes('<link rel="stylesheet" href="style.css">'));
        ok('ダウンロード(3 つ): JS が空なら読まない', !split.includes('script.js'));
    }

    console.log('[settings]');
    check('0 はそのまま', 0, st.clampDelay(0));
    check('1000 はそのまま', 1000, st.clampDelay(1000));
    check('上を超えたら 1000', 1000, st.clampDelay(5000));
    check('マイナスは 0', 0, st.clampDelay(-5));
    check('文字の数', 500, st.clampDelay('500'));
    check('空は既定', 250, st.clampDelay(''));
    check('数でないものは既定', 250, st.clampDelay('abc'));
    check('小数は丸める', 13, st.clampDelay(12.6));
    check('幅の下限', 20, st.clampSplit(3));
    check('幅の上限', 80, st.clampSplit(95));
    check('崩れた設定を直す', { delay: 1000, autoRun: true, split: 50, theme: 'auto', tab: 'html' },
        st.normalizeSettings({ delay: 99999, autoRun: 'yes', theme: 'pink', tab: 'php' }));
    {
        const calls = [];
        let timers = [];
        const fake = { set: (fn, ms) => { timers.push({ fn, ms }); return timers.length; }, clear: (h) => { timers[h - 1] = null; } };
        let delay = 0;
        const d = st.createDebouncer(() => calls.push('run'), () => delay, fake);
        d.schedule();
        check('0ms はすぐ', 1, calls.length);
        delay = 300;
        d.schedule();
        d.schedule();
        check('続けて打つと前の予約は取り消す', 1, timers.filter(Boolean).length);
        check('待ち時間を渡す', 300, timers.filter(Boolean)[0].ms);
        timers.filter(Boolean)[0].fn();
        check('時間が来たら 1 回だけ', 2, calls.length);
        delay = 50;
        d.schedule();
        d.cancel();
        check('取り消し', false, d.pending());
    }
    {
        // ブラウザの setTimeout は this が window(globalThis)でないと Illegal invocation で落ちる。それを真似る
        const realSet = globalThis.setTimeout;
        const realClear = globalThis.clearTimeout;
        let illegal = false;
        globalThis.setTimeout = function (fn, ms) {
            if (this !== undefined && this !== globalThis) {
                illegal = true;
                throw new TypeError('Illegal invocation');
            }
            return realSet(fn, ms);
        };
        globalThis.clearTimeout = function (h) {
            if (this !== undefined && this !== globalThis) {
                illegal = true;
                throw new TypeError('Illegal invocation');
            }
            return realClear(h);
        };
        let ran = 0;
        try {
            const d = st.createDebouncer(() => { ran++; }, () => 5);
            d.schedule();
            d.schedule();
            await new Promise((r) => realSet(r, 40));
        } catch (e) {
            illegal = true;
        } finally {
            globalThis.setTimeout = realSet;
            globalThis.clearTimeout = realClear;
        }
        check('既定の時計はブラウザの setTimeout を正しく呼ぶ(Illegal invocation にならない)', false, illegal);
        check('既定の時計で 1 回だけ動く', 1, ran);
    }

    console.log('[storage]');
    {
        const text = sg.serializeSaved({ html: 'a', css: 'b', js: 'c', settings: { delay: 0 } }, 123);
        const back = sg.parseSaved(text);
        check('保存の形を戻せる', { html: 'a', css: 'b', js: 'c', delay: 0, savedAt: 123 },
            { html: back.html, css: back.css, js: back.js, delay: back.settings.delay, savedAt: back.savedAt });
        check('壊れた JSON は null', null, sg.parseSaved('{'));
        check('版が違えば null', null, sg.parseSaved(JSON.stringify({ version: 2, html: '', css: '', js: '' })));
        check('型が違えば null', null, sg.parseSaved(JSON.stringify({ version: 1, html: 1, css: '', js: '' })));
        check('空は null', null, sg.parseSaved(''));
        check('localStorage が無ければ保存できない', { ok: false, reason: 'unavailable' }, sg.saveDoc({ html: '', css: '', js: '' }));
    }

    console.log('[i18n]');
    check('?lang=en が勝つ', 'en', i18n.pickLang('?lang=en', 'ja', 'ja-JP'));
    check('覚えたものが次', 'ja', i18n.pickLang('', 'ja', 'en-US'));
    check('ブラウザが日本語', 'ja', i18n.pickLang('', null, 'ja-JP'));
    check('それ以外は英語', 'en', i18n.pickLang('', 'xx', 'fr'));
    check('{ms} を埋める', '自動更新 250ms', i18n.t('ja', 'preview.auto', { ms: 250 }));
    {
        const ja = i18n.dictKeys('ja').sort();
        const en = i18n.dictKeys('en').sort();
        check('日英の鍵が揃っている', ja, en);
        const shell = fs.readFileSync(path.join(SRC, '..', 'public', 'index.php'), 'utf8');
        const used = [...shell.matchAll(/data-i18n(?:-title|-aria)?="([^"]+)"/g)].map((m) => m[1]);
        const missing = used.filter((k) => !i18n.hasKey('ja', k));
        check('殻で使う鍵がすべて辞書にある', [], missing);
        const mainJs = fs.readFileSync(path.join(SRC, 'main.js'), 'utf8');
        const inMain = [...mainJs.matchAll(/\bt\(lang, '([a-zA-Z.-]+)'|\btr\('([a-zA-Z.-]+)'/g)].map((m) => m[1] ?? m[2]);
        ok('main.js の鍵を拾えている', inMain.length > 50);
        check('main.js で使う鍵がすべて辞書にある', [], inMain.filter((k) => !i18n.hasKey('ja', k) || !i18n.hasKey('en', k)));
    }

    console.log('[templates]');
    for (const id of tpl.TEMPLATE_IDS) {
        for (const lang of ['ja', 'en']) {
            const doc = tpl.buildTemplate(id, lang);
            ok(`${id} (${lang}) は 3 つの文字列`, typeof doc.html === 'string' && typeof doc.css === 'string' && typeof doc.js === 'string');
        }
        ok(`${id} に日英の名前`, tpl.templateName(id, 'ja') !== tpl.templateName(id, 'en') || id === 'blank');
    }
    ok('既定のテンプレートが一覧にある', tpl.TEMPLATE_IDS.includes(tpl.DEFAULT_TEMPLATE));
    {
        const f = tpl.buildTemplateFiles('starter', 'ja');
        check('テンプレートは 3 つのファイル', ['index.html', 'style.css', 'script.js'], Object.keys(f));
        ok('index.html は style.css を読む', f['index.html'].includes('<link rel="stylesheet" href="style.css">'));
        ok('index.html は script.js を読む', f['index.html'].includes('<script src="script.js"></script>'));
        check('flex は JS が空なので script.js を作らない', ['index.html', 'style.css'], Object.keys(tpl.buildTemplateFiles('flex', 'en')));
    }

    // ---- 段 10 ----
    const model = await load('project/model.js');
    const pb = await load('preview/projectBuilder.js');
    const kb = await load('keybindings.js');
    const mig = await load('project/migrate.js');
    const imp = await load('project/import.js');

    console.log('[project/model]');
    check('段 11: エディタだけの拡張子(.py)はテキスト・色分けなし', { mime: 'text/plain', text: true, lang: 'plaintext' }, model.fileType('a/main.py'));
    check('段 11: .ts は typescript・.ipynb は JSON', ['typescript', 'json'], [model.fileType('a.ts').lang, model.fileType('n.ipynb').lang]);
    ok('段 11: エディタだけの拡張子はすべて表にある(テキスト)', model.EDITOR_ONLY_EXTENSIONS.every((e) => model.fileType(`x.${e}`)?.text === true));
    check('段 11: 表に無いもの(.exe)は使えない', null, model.fileType('a.exe'));
    check('パスを整える', 'css/style.css', model.cleanPath('css\\style.css'));
    const pathCode = (v, opt) => { try { model.cleanPath(v, opt); return 'ok'; } catch (e) { return e.code; } };
    check('.. は断る', 'parent', pathCode('a/../b.html'));
    check('先頭の / は断る', 'absolute', pathCode('/etc/passwd.txt'));
    check('ドライブ名は断る', 'absolute', pathCode('C:/x.html'));
    check('空の段は断る', 'segment', pathCode('a//b.css'));
    check('制御文字は断る', 'control', pathCode('a\u0001.css'));
    check('使えない拡張子は断る(段 11 から .php はエディタで書けるので .exe で見る)', 'type', pathCode('run.exe'));
    check('フォルダは拡張子を見ない', 'ok', pathCode('images', { requireType: false }));
    check('深すぎるものは断る', 'deep', pathCode(Array(17).fill('a').join('/') + '.css'));
    ok('隠しファイルは除く', model.ignoredPath('.git/config') && model.ignoredPath('__MACOSX/a.png') && !model.ignoredPath('a/b.png'));
    check('共通のフォルダを外す', ['index.html', 'css/a.css'], model.stripCommonFolder(['site/index.html', 'site/css/a.css']));
    check('2 段の共通のフォルダも外す', ['a.html'], model.stripCommonFolder(['x/y/a.html']));
    check('入口は index.html', 'index.html', model.pickEntry(['about.html', 'index.html']));
    check('入口は 1 段目の index.html', 'docs/index.html', model.pickEntry(['docs/index.html', 'docs/a.html']));
    check('頼まれた入口', 'about.html', model.pickEntry(['about.html', 'index.html'], 'about.html'));
    check('HTML が無ければ null', null, model.pickEntry(['a.css']));
    check('参照を解く', 'img/a.png', model.resolveRef('index.html', './img/a.png?v=1#x'));
    check('上の階層', 'img/a.png', model.resolveRef('pages/b.html', '../img/a.png'));
    check('外は null', null, model.resolveRef('index.html', 'https://example.com/a.png'));
    check('上へ出すぎは null', null, model.resolveRef('index.html', '../../a.png'));
    check('// は null', null, model.resolveRef('index.html', '//cdn.example/a.js'));
    check('重ならない名前', 'a-3.html', model.uniquePath('a.html', ['a.html', 'a-2.html']));
    {
        const tree = model.buildTree(['b.html', 'css/x.css', 'a.html'], ['empty']);
        check('木: フォルダが先', ['css', 'empty'], tree.folders.map((f) => f.name));
        check('木: 名前順', ['a.html', 'b.html'], tree.files.map((f) => f.name));
    }
    check('フォルダの名前を変える', 'img2/a/b.png', model.movePrefix('img/a/b.png', 'img', 'img2'));
    check('似た名前は動かさない', 'image/a.png', model.movePrefix('image/a.png', 'img', 'img2'));
    check('上限: ファイルの数', 'files', model.overLimit(501, 0));
    check('上限: 大きさ', 'bytes', model.overLimit(1, 21 * 1024 * 1024));
    check('UTF-8 の長さ', 3, model.byteLength('あ'));
    {
        const r = model.newProjectRecord('  ', { 'index.html': '<p>', 'style.css': '' }, { id: 'abc', now: 5, fallbackName: 'X' });
        check('新しいプロジェクト', { id: 'abc', name: 'X', entry: 'index.html', active: 'index.html', open: ['index.html'], n: 2 },
            { id: r.project.id, name: r.project.name, entry: r.project.entry, active: r.project.active, open: r.project.open, n: r.puts.length });
    }

    console.log('[preview/projectBuilder]');
    {
        const files = new Map([
            ['index.html', { mime: 'text/html', text: '<!DOCTYPE html>\n<html>\n<head>\n<link rel="stylesheet" href="css/style.css">\n</head>\n<body>\n<img src="img/a.png" alt="">\n<a href="about.html">about</a>\n<script src="js/app.js"></script>\n<script type="module" src="js/main.js"></script>\n<script>\nconsole.log(1);\nboom();\n</script>\n</body>\n</html>' }],
            ['about.html', { mime: 'text/html', text: '<p>about</p>' }],
            ['css/style.css', { mime: 'text/css', text: '@import "base.css";\nbody { background: url(../img/a.png); }' }],
            ['css/base.css', { mime: 'text/css', text: 'h1 { color: red; }' }],
            ['img/a.png', { mime: 'image/png', dataUrl: 'data:image/png;base64,AAAA' }],
            ['js/app.js', { mime: 'text/javascript', text: 'var app = 1;\nnope();' }],
            ['js/main.js', { mime: 'text/javascript', text: "import { x } from './util.js';\nconsole.log(x);" }],
            ['js/util.js', { mime: 'text/javascript', text: 'export const x = 2;' }],
            ['data.json', { mime: 'application/json', text: '{"a":1}' }],
        ]);
        const r = pb.buildProjectDocument(files, 'index.html', { runId: 3, lang: 'ja' });
        ok('CSS を中に入れる', r.text.includes('<style data-km-src="css/style.css">') && !r.text.includes('href="css/style.css"'));
        ok('@import を中に入れる', r.text.includes('h1 { color: red; }'));
        ok('CSS の url() を data: に', r.text.includes("url('data:image/png;base64,AAAA')"));
        ok('img を data: に', r.text.includes('src="data:image/png;base64,AAAA"'));
        ok('script src を中に入れる', r.text.includes('var app = 1;') && !r.text.includes('src="js/app.js"'));
        ok('モジュールは data: URL', /<script type="module" src="data:text\/javascript/.test(r.text));
        ok('モジュールの import も data: に', !r.text.includes("from './util.js'") && r.text.includes(encodeURIComponent('export const x = 2;')));
        ok('台本に runId', r.text.includes('ID=3'));
        ok('台本に fetch の表', r.text.includes('"data.json"'));
        ok('台本に移動先の HTML', r.text.includes('"about.html"'));
        check('警告なし', [], r.warnings);
        const lines = r.text.split('\n');
        const appSeg = r.segs.find((s) => s.path === 'js/app.js');
        check('外のスクリプトの区間: 1 行目と 2 行目', ['var app = 1;', 'nope();'], [lines[appSeg.start], lines[appSeg.start + 1]]);
        check('外のスクリプトは 1 行目から数える', 0, appSeg.firstLine);
        const inl = r.segs.find((s) => s.path === 'index.html');
        ok('中に書いたスクリプトの区間', inl && lines[inl.start] === 'console.log(1);');
        check('中に書いたスクリプトの元の行', 11, inl.firstLine);
        const ex = pb.buildProjectDocument(files, 'index.html', { mode: 'export' });
        ok('書き出し: console の台本は入れない', !ex.text.includes('km-editor-console'));
        ok('書き出し: fetch の台本は入れる', ex.text.includes('window.fetch'));
        const miss = pb.buildProjectDocument(new Map([['index.html', { mime: 'text/html', text: '<link rel="stylesheet" href="no.css"><script src="no.js"></script>' }]]), 'index.html', {});
        check('無いファイルは警告', ['no.css', 'no.js'], miss.warnings.map((w) => w.path).sort());
        const ext = pb.buildProjectDocument(new Map([['index.html', { mime: 'text/html', text: '<script src="https://cdn.example/a.js"></script><img src="https://x/y.png">' }]]), 'index.html', {});
        ok('外の URL はそのまま', ext.text.includes('src="https://cdn.example/a.js"') && ext.text.includes('src="https://x/y.png"') && ext.warnings.length === 0);
        const cyc = pb.buildProjectDocument(new Map([
            ['index.html', { mime: 'text/html', text: '<script type="module" src="a.js"></script>' }],
            ['a.js', { mime: 'text/javascript', text: "import './b.js';" }],
            ['b.js', { mime: 'text/javascript', text: "import './a.js';" }],
        ]), 'index.html', {});
        ok('モジュールの輪で止まらない', cyc.warnings.some((w) => w.code === 'module-cycle'));
        const esc = pb.buildProjectDocument(new Map([
            ['index.html', { mime: 'text/html', text: '<script src="a.js"></script>' }],
            ['a.js', { mime: 'text/javascript', text: "var s = '</script><script>alert(1)</script>';" }],
        ]), 'index.html', {});
        ok('外のスクリプトの中の </script> で閉じない', esc.text.includes("'<\\/script><script>alert(1)<\\/script>'"));
    }

    console.log('[keybindings]');
    check('既定は付けられる', kb.DEFAULT_KEYS, kb.normalizeKeymap({}));
    check('書き方を整える', 'Shift+Alt+F', kb.normalizeKey('alt + shift + f'));
    check('Cmd は Ctrl', 'Ctrl+S', kb.normalizeKey('Cmd+S'));
    check('知らないキーは null', null, kb.normalizeKey('Ctrl+Foo'));
    check('Ctrl+W はブラウザ', 'browser', kb.checkKey('Ctrl+W'));
    check('Ctrl+T はブラウザ', 'browser', kb.checkKey('Ctrl+T'));
    check('Ctrl+Tab はブラウザ', 'browser', kb.checkKey('Ctrl+Tab'));
    check('Ctrl+Z は編集', 'editor', kb.checkKey('Ctrl+Z'));
    check('修飾なしは断る', 'modifier', kb.checkKey('A'));
    check('Shift だけは断る', 'modifier', kb.checkKey('Shift+A'));
    check('F2 は単独でよい', null, kb.checkKey('F2'));
    check('Alt+K はよい', null, kb.checkKey('Alt+K'));
    {
        const r = kb.assignKey(kb.DEFAULT_KEYS, 'run', 'Ctrl+S');
        check('重なりは断る', { ok: false, reason: 'taken', other: 'save' }, r);
        check('ブラウザのキーは断る', 'browser', kb.assignKey(kb.DEFAULT_KEYS, 'run', 'Ctrl+W').reason);
        const r2 = kb.assignKey(kb.DEFAULT_KEYS, 'run', 'F5');
        check('F5 はブラウザ(再読み込み)', 'browser', r2.reason);
        const r3 = kb.assignKey(kb.DEFAULT_KEYS, 'run', 'Alt+R');
        check('付け直せる', 'Alt+R', r3.ok && r3.keymap.run);
        const r4 = kb.assignKey(kb.DEFAULT_KEYS, 'run', '');
        check('外せる', '', r4.ok && r4.keymap.run);
    }
    check('壊れた割り当ては既定に', 'Ctrl+Enter', kb.normalizeKeymap({ run: 'Ctrl+W' }).run);
    {
        const m = kb.normalizeKeymap({ run: 'Ctrl+S' });
        ok('保存から読んだ重なりは片方を直す', m.run !== m.save);
    }
    check('外したものは外したまま', '', kb.normalizeKeymap({ format: '' }).format);
    check('押したキー', 'Ctrl+Shift+K', kb.keyFromEvent({ code: 'KeyK', ctrlKey: true, shiftKey: true }));
    check('⌘ も Ctrl', 'Ctrl+Enter', kb.keyFromEvent({ code: 'Enter', metaKey: true }));
    check('修飾キーだけは null', null, kb.keyFromEvent({ code: 'ControlLeft', ctrlKey: true }));
    check('押したキーから操作', 'quickOpen', kb.actionForKey(kb.DEFAULT_KEYS, 'Ctrl+P'));

    console.log('[project/migrate]');
    {
        const plan = mig.planMigration({ html: '<h1>a</h1>', css: 'h1{}', js: '', savedAt: 77 }, 'ja', { id: 'm1' });
        check('移し替え: はじめのプロジェクト', 'はじめのプロジェクト', plan.project.name);
        check('移し替え: ファイル', ['index.html', 'style.css'], plan.puts.map((p) => p.path));
        ok('移し替え: index.html は完全な文書', plan.puts[0].text.startsWith('<!DOCTYPE html>') && plan.puts[0].text.includes('<h1>a</h1>'));
        check('移し替え: 時刻を引き継ぐ', 77, plan.project.createdAt);
        check('移し替え: 無ければ null', null, mig.planMigration(null, 'ja'));
        check('英語の名前', 'My first project', mig.planMigration({ html: '', css: '', js: '' }, 'en').project.name);
    }

    console.log('[project/import]');
    {
        const enc = new TextEncoder();
        const raw = [
            { path: 'site/index.html', data: enc.encode('<p>') },
            { path: 'site/css/a.css', data: enc.encode('p{}') },
            { path: 'site/.DS_Store', data: enc.encode('x') },
            { path: '__MACOSX/site/._index.html', data: enc.encode('x') },
            { path: 'site/run.exe', data: enc.encode('MZ') },
        ];
        const r = imp.prepareEntries(raw);
        check('読み込み: 共通のフォルダを外す', ['index.html', 'css/a.css'], r.files.map((f) => f.path));
        check('読み込み: 使えない拡張子は数える', [{ path: 'site/run.exe', code: 'type' }], r.rejected);
        check('読み込み: 上限の内', null, r.limit);
        const stored = imp.toStored('a.css', enc.encode('\ufeffp{}'));
        check('読み込み: BOM を外す', 'p{}', stored.text);
        ok('読み込み: 画像は Blob', imp.toStored('a.png', new Uint8Array([1, 2])).blob instanceof Blob);
        const many = imp.prepareEntries(Array.from({ length: 501 }, (_, i) => ({ path: `f${i}.txt`, data: new Uint8Array(0) })));
        check('読み込み: 501 個は上限', 'files', many.limit);
        const zipBlob = await imp.projectZip([{ path: 'index.html', text: '<p>あ</p>' }, { path: 'img/a.png', blob: new Blob([new Uint8Array([9, 8, 7])]) }]);
        const back = await imp.readZip(new Uint8Array(await zipBlob.arrayBuffer()), async () => null);
        check('ZIP: 書き出して読み戻せる', ['img/a.png', 'index.html'], back.map((f) => f.path).sort());
        check('ZIP: 中身が同じ', '<p>あ</p>', new TextDecoder().decode(back.find((f) => f.path === 'index.html').data));
    }

    console.log('[project/sync]');
    {
        const sy = await load('project/sync.js');
        const enc = new TextEncoder();
        check('sha256', 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', await sy.sha256Hex(enc.encode('abc')));
        const { manifest, byHash } = await sy.buildManifest([{ path: 'b.css', bytes: enc.encode('x') }, { path: 'a.html', bytes: enc.encode('x') }, { path: 'c.js', bytes: enc.encode('yy') }]);
        check('目録はパスの順', ['a.html', 'b.css', 'c.js'], Object.keys(manifest));
        check('同じ中身は 1 つ', 2, byHash.size);
        const sizeOf = (h) => byHash.get(h).length;
        check('送るもの: サーバーに無い中身だけ・重ねない', [[manifest['c.js'].h]], sy.planUpload(manifest, [manifest['a.html'].h], sizeOf));
        check('送るものが無ければ空の組 1 つ', [[]], sy.planUpload(manifest, [manifest['a.html'].h, manifest['c.js'].h], sizeOf));
        check('大きければ分ける', 2, sy.planUpload(manifest, [], sizeOf, 1).length);
        const server = { manifest, name: 'P', entry: 'a.html', folders: ['img'] };
        ok('同じなら送らない', sy.sameContent(manifest, { name: 'P', entry: 'a.html', folders: ['img'] }, server));
        ok('名前が違えば送る', !sy.sameContent(manifest, { name: 'Q', entry: 'a.html', folders: ['img'] }, server));
        ok('中身が違えば送る', !sy.sameContent({ ...manifest, 'c.js': { h: '0'.repeat(64), s: 1 } }, { name: 'P', entry: 'a.html', folders: ['img'] }, server));
        ok('前回が無ければ送る', !sy.sameContent(manifest, { name: 'P' }, null));
        const remote = new Map([['s1', { id: 's1', revision: 3 }]]);
        const linked = (extra) => ({ server: { id: 's1', revision: 3, account: 'A' }, ...extra });
        check('サインインしていなければ この端末だけ', 'local', sy.remoteState(linked({}), '', remote));
        check('そろっている', 'synced', sy.remoteState(linked({}), 'A', remote));
        check('まだ送っていない', 'pending', sy.remoteState(linked({ syncDirty: true }), 'A', remote));
        check('サーバーに無いものは まだ送っていない', 'pending', sy.remoteState({ server: null }, 'A', remote));
        check('ほかの端末で変更', 'remote', sy.remoteState({ server: { id: 's1', revision: 2, account: 'A' } }, 'A', remote));
        check('両方で変更', 'conflict', sy.remoteState({ server: { id: 's1', revision: 2, account: 'A' }, syncDirty: true }, 'A', remote));
        check('サーバーで消された', 'gone', sy.remoteState({ server: { id: 's9', revision: 1, account: 'A' } }, 'A', remote));
        check('ほかのアカウント', 'other', sy.remoteState(linked({}), 'B', remote));
        ok('ほかのアカウントのものは送らない', !sy.needsPush(linked({ syncDirty: true }), 'B'));
        ok('同期しないものは送らない', !sy.needsPush({ server: null, syncOff: 'gone' }, 'A'));
        ok('新しいものは送る', sy.needsPush({ server: null }, 'A'));
        // 口とのやり取り(偽物の fetch で): 新しく作る → 足りないと言われたら送り直す → 版のぶつかり
        const calls = [];
        let step = 0;
        const fakeFetch = async (url, init) => {
            const fields = Object.fromEntries([...init.body.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : 'ZIP']));
            calls.push({ url, csrf: init.headers['X-KM-CSRF'], fields });
            step++;
            const json = (status, body) => ({ status, ok: status < 300, json: async () => body });
            if (step === 1) return json(409, { success: false, missing: [manifest['c.js'].h] });
            if (step === 2) return json(200, { success: true, project: { id: 'a'.repeat(24), revision: 1, entry: 'a.html' } });
            return json(409, { success: false, conflict: true, revision: 5 });
        };
        const client = sy.createSyncClient({ csrf: 'tok', fetchImpl: fakeFetch });
        const files = [{ path: 'a.html', text: 'x' }, { path: 'b.css', text: 'x' }, { path: 'c.js', text: 'yy' }];
        const r1 = await client.push(files, { name: 'P', entry: 'a.html', folders: [] }, null, 'A');
        check('送る: CSRF を付ける', ['tok', 'tok'], calls.map((c) => c.csrf));
        check('送る: 新しいものは project が空・版 0', ['', '0'], [calls[0].fields.project, calls[0].fields.baseRevision]);
        ok('送る: 目録はパス → ハッシュ', JSON.parse(calls[0].fields.manifest)['c.js'].h === manifest['c.js'].h && calls[0].fields.zip === 'ZIP');
        check('送る: 足りないと言われたら送り直して、サーバーの id と版を覚える', [true, 'a'.repeat(24), 1, 'A'], [r1.ok, r1.server.id, r1.server.revision, r1.server.account]);
        const r2 = await client.push(files, { name: 'P', entry: 'a.html', folders: [] }, r1.server, 'A');
        check('送る: 変わっていない中身は送らない(zip なし)', undefined, calls[2].fields.zip);
        check('送る: 版がぶつかれば知らせる', { ok: false, conflict: true, revision: 5 }, r2);
    }

    console.log('[project/syncManager]');
    {
        const sm = await load('project/syncManager.js');
        const { MemoryStore } = await load('project/db.js');
        const base = { blocked: '', account: 'A', problem: '', record: { server: { account: 'A' } }, conflict: false, sending: false, error: false, timerPending: false };
        check('印: 止められた・未確認・ぶつかり・送信中・失敗', ['blocked', 'idle', 'offline', 'conflict', 'sending', 'failed'], [
            sm.chipState({ ...base, blocked: 'x' }), sm.chipState({ ...base, account: '' }), sm.chipState({ ...base, account: '', problem: 'offline' }),
            sm.chipState({ ...base, conflict: true }), sm.chipState({ ...base, sending: true }), sm.chipState({ ...base, error: true }),
        ]);
        check('印: 消された・ほかのアカウント・まだ送っていない・待ち時間中・そろった', ['gone', 'off', 'pending', 'pending', 'synced'], [
            sm.chipState({ ...base, record: { server: null, syncOff: 'gone' } }), sm.chipState({ ...base, record: { server: { account: 'B' } } }),
            sm.chipState({ ...base, record: { server: { account: 'A' }, syncDirty: true } }), sm.chipState({ ...base, timerPending: true }), sm.chipState(base),
        ]);

        // 偽物の口とメモリの置き場で、突き合わせ(送る・読む・消された・ぶつかり)と片付け
        const store = new MemoryStore();
        const put = (id, extra, files) => store.writeFiles(id, files.map(([path, text]) => ({ path, text, size: text.length })), [], { id, name: id, entry: 'index.html', folders: [], ...extra });
        await put('new', { server: null, syncDirty: true }, [['index.html', 'n']]);
        await put('gone', { server: { id: 's-gone', revision: 1, account: 'A' } }, [['index.html', 'g']]);
        await put('old', { server: { id: 's-old', revision: 1, account: 'A' } }, [['index.html', 'o'], ['x.css', 'x']]);
        await put('both', { server: { id: 's-both', revision: 1, account: 'A' }, syncDirty: true }, [['index.html', 'b']]);
        const pushes = [];
        let rev = 10;
        const client = {
            state: async () => ({ signedIn: true, account: 'A', canSave: true, projects: [{ id: 's-old', name: 'old', revision: 2 }, { id: 's-both', name: 'both', revision: 3 }, { id: 's-srv', name: 'srv', revision: 1 }], usedBytes: 5, limits: { projects: 30 } }),
            push: async (files, meta, server, account, opts) => {
                pushes.push({ name: meta.name, paths: files.map((f) => f.path), force: opts.force, remoteRevision: opts.remoteRevision });
                if (server?.id === 's-both' && !opts.force) {
                    return { ok: false, conflict: true, revision: 3 };
                }
                rev += 1;
                return { ok: true, server: { id: server?.id ?? `s-${meta.name}`, revision: rev, account, manifest: {}, name: meta.name, entry: meta.entry, folders: [] } };
            },
            pull: async (id, account) => ({ files: [{ path: 'index.html', text: `from ${id}`, size: 1 }], server: { id, revision: 2, account, manifest: {}, name: id === 's-old' ? 'old' : 'srv', entry: 'index.html', folders: [] } }),
            remove: async () => ({ status: 200 }),
        };
        let changes = 0;
        const sync = sm.createSyncManager({
            store, csrf: 't', signedIn: true, client,
            host: {
                current: () => null, flush: async () => {}, currentFiles: () => null, stamp: () => 0,
                updateRecord: async (id, fn) => { const r = await store.getProject(id); if (r) { fn(r); await store.putProject(r); } },
                localName: (n) => `${n} (この端末)`, untitled: () => '名前なし', onChange: () => { changes++; },
            },
        });
        await sync.refresh();
        const rec = async (id) => store.getProject(id);
        check('突き合わせ: 新しいものを送り、サーバーの形を覚えて「送った」にする', ['s-new', false], [(await rec('new')).server?.id, (await rec('new')).syncDirty]);
        check('突き合わせ: サーバーで消されたものは同期をやめる', ['gone', null], [(await rec('gone')).syncOff, (await rec('gone')).server]);
        check('突き合わせ: ほかの端末で変わったものは読み込む(無くなったファイルは消す)', ['from s-old', ['index.html'], 2], [(await store.getFiles('old'))[0].text, (await store.getFiles('old')).map((f) => f.path), (await rec('old')).server.revision]);
        check('突き合わせ: 両方で変わったものはぶつかり(送らない)', ['conflict', true], [sync.stateOf(await rec('both')), sync.hasConflict('both')]);
        check('サーバーにだけあるもの', ['s-srv'], (await sync.serverOnly()).map((r) => r.id));
        check('使っている量', 5, sync.usage.used);
        ok('状態が変わるたびに知らせる', changes > 0);
        await sync.resolve('both', 'mine');
        check('ぶつかり: この端末のもので上書きは、サーバーの版を土台に強く送る', { force: true, remoteRevision: 3 }, (({ force, remoteRevision }) => ({ force, remoteRevision }))(pushes[pushes.length - 1]));
        check('ぶつかり: 片付いた', [false, 'synced'], [sync.hasConflict('both'), sync.stateOf(await rec('both'))]);
        const madeId = await sync.pullNew('s-srv');
        check('サーバーにだけあるものを新しいプロジェクトとして読む', ['srv', 'from s-srv', []], [(await rec(madeId)).name, (await store.getFiles(madeId))[0].text, (await sync.serverOnly()).map((r) => r.id)]);
        ok('サーバーのものを消す', await sync.removeServer('s-srv'));

        const offline = sm.createSyncManager({ store, csrf: 't', signedIn: false, client, host: { current: () => null, flush: async () => {}, onChange: () => {} } });
        await offline.refresh();
        check('サインインしていなければ突き合わせない', [false, 'idle'], [offline.canSync, offline.status().state]);
    }

    console.log('[settings v2]');
    check('テーマにハイコントラスト', true, st.THEMES.includes('hc-light') && st.THEMES.includes('hc-dark'));
    check('自動は OS に合わせる', 'dark', st.resolveTheme('auto', true));
    check('知らないテーマは自動', 'light', st.resolveTheme('pink', false));
    check('Monaco のテーマ', 'hc-black', st.MONACO_THEMES['hc-dark']);
    {
        const s = st.normalizeSettingsV2({ delay: 5, theme: 'hc-dark', keys: [1], sidebar: 9999, lastProject: '../x' });
        check('設定 v2 を直す', { delay: 5, theme: 'hc-dark', keys: {}, sidebar: 480, lastProject: '' },
            { delay: s.delay, theme: s.theme, keys: s.keys, sidebar: s.sidebar, lastProject: s.lastProject });
    }

    console.log('[補完のデータ]');
    check('HTML の雛形は日英で同じ数', HTML_JA.snippets.length, HTML_SNIPPETS_EN.length);
    check('HTML の雛形は日英で同じタグ', HTML_JA.snippets.map((s) => s[0]), HTML_SNIPPETS_EN.map((s) => s[0]));
    ok('HTML のタグは 100 以上', Object.keys(HTML_JA.tags).length >= 100);
    ok('CSS のプロパティは 150 以上', Object.keys(CSS_JA.props).length >= 150);
    ok('JS の API は 150 以上', JS_API.length >= 150);
    check('JS の API はどれも 6 つ組', [], JS_API.filter((r) => r.length !== 6 || r.some((v) => typeof v !== 'string')).map((r) => r[1]));
    check('JS の API に日英の説明', [], JS_API.filter((r) => r[3] === '' || r[4] === '').map((r) => r[1]));
    check('JS の書き方の型はどれも 4 つ組', [], JS_SNIPPETS.filter((r) => r.length !== 4).map((r) => r[0]));
    check('雛形の ${1:…} を外す', "a href=\"URL\">t</a>", merge.plainSnippet('a href="${1:URL}">${2:t}</a>$0'));
    {
        const idx = merge.indexJsApi(JS_API, 'ja');
        ok('document.querySelector を引ける', merge.lookupJs(idx, 'document', 'querySelector')?.doc.includes('セレクター'));
        ok('. の後の forEach を引ける', merge.lookupJs(idx, 'items', 'forEach') !== null);
        ok('そのままの setTimeout を引ける', merge.lookupJs(idx, '', 'setTimeout') !== null);
        check('知らないものは null', null, merge.lookupJs(idx, '', 'zzz'));
        const en = merge.indexJsApi(JS_API, 'en');
        ok('英語に切り替わる', /CSS selector/.test(merge.lookupJs(en, 'document', 'querySelector').doc));
        ok('説明のマークダウンに例', merge.jsDocMarkdown(merge.lookupJs(idx, 'Math', 'random')).includes('```js'));
    }

    const htmlStdPath = path.join(NODE_MODULES, 'monaco-editor', 'esm', 'external', 'vscode-html-languageservice', 'lib', 'esm', 'languageFacts', 'data', 'webCustomData.js');
    const cssStdPath = path.join(NODE_MODULES, 'monaco-editor', 'esm', 'external', 'vscode-css-languageservice', 'lib', 'esm', 'data', 'webCustomData.js');
    if (!fs.existsSync(htmlStdPath) || !fs.existsSync(cssStdPath)) {
        console.log('  (node_modules が無いので標準データとの突き合わせは飛ばします)');
    } else {
        const { htmlData } = await import(pathToFileURL(htmlStdPath).href);
        const { cssData } = await import(pathToFileURL(cssStdPath).href);
        const mergedJa = merge.mergeHtmlData(htmlData, HTML_JA, 'ja');
        const isJa = (d) => Boolean(d && typeof d === 'object' && /[ぁ-んァ-ヶ一-龠]/.test(d.value));
        const mTags = new Map(mergedJa.tags.map((t) => [t.name, t]));
        check('日本語にしたタグはすべて日本語で出る(標準に無いものは足す)', [], Object.keys(HTML_JA.tags).filter((n) => !isJa(mTags.get(n)?.description)));
        check('日本語にした属性はすべて日本語で出る', [], Object.keys(HTML_JA.attrs).filter((k) => {
            const [tag, name] = k.split('.');
            return !isJa(mTags.get(tag)?.attributes.find((a) => a.name === name)?.description);
        }));
        const dupCount = (names) => names.length - new Set(names).size;
        const stdTag = new Map(htmlData.tags.map((t) => [t.name, t]));
        check('重ねても属性が 2 つ並ばない(標準のデータにもとからある重なりは数えない)', [], mergedJa.tags.filter((t) => dupCount(t.attributes.map((a) => a.name)) > dupCount((stdTag.get(t.name)?.attributes ?? []).map((a) => a.name))).map((t) => t.name));
        const globalNames = new Set(mergedJa.globalAttributes.map((a) => a.name));
        check('タグごとの属性がグローバル属性と重ならない', [], Object.keys(HTML_JA.attrs).filter((k) => globalNames.has(k.split('.')[1]) && !(htmlData.tags.find((t) => t.name === k.split('.')[0])?.attributes ?? []).some((a) => a.name === k.split('.')[1])));
        check('日本語にしたグローバル属性はすべて日本語で出る', [], Object.keys(HTML_JA.global).filter((k) => !isJa(mergedJa.globalAttributes.find((a) => a.name === k)?.description)));
        const stdValues = new Set();
        for (const s of htmlData.valueSets) {
            for (const v of s.values) {
                stdValues.add(`${s.name}.${v.name}`);
            }
        }
        check('日本語にした値はすべて標準のデータにある', [], Object.keys(HTML_JA.values).filter((k) => !stdValues.has(k)));
        const a = mergedJa.tags.find((t) => t.name === 'a');
        ok('a の説明が日本語', a.description.value.startsWith('リンク'));
        ok('a の説明に例', a.description.value.includes('```html'));
        ok('a の href が日本語', a.attributes.find((x) => x.name === 'href').description.value.includes('行き先'));
        ok('標準のタグを落とさない', htmlData.tags.every((t) => mTags.has(t.name)));
        ok('target の _blank が日本語', mergedJa.valueSets.find((s) => s.name === 'target').values.find((v) => v.name === '_blank').description.value.includes('新しいタブ'));
        const mergedEn = merge.mergeHtmlData(htmlData, HTML_JA, 'en');
        ok('英語では英語の説明のまま(例は付く)', mergedEn.tags.find((t) => t.name === 'a').description.value.startsWith('If the a element'));
        ok('標準のデータを書き換えない', typeof htmlData.tags.find((t) => t.name === 'a').description === 'object' && !htmlData.tags.find((t) => t.name === 'a').description.value.startsWith('リンク'));

        const stdProps = new Map(cssData.properties.map((p) => [p.name, p]));
        check('日本語にしたプロパティはすべて標準のデータにある', [], Object.keys(CSS_JA.props).filter((n) => !stdProps.has(n)));
        const pseudoNames = new Set([...cssData.pseudoClasses, ...cssData.pseudoElements].map((p) => String(p.name).replace(/\(\)$/, '')));
        check('日本語にした疑似クラス・疑似要素はすべてある', [], Object.keys(CSS_JA.pseudo).filter((n) => !pseudoNames.has(n)));
        const mergedCss = merge.mergeCssData(cssData, CSS_JA, 'ja');
        const mProps = new Map(mergedCss.properties.map((p) => [p.name, p]));
        const badValues = Object.keys(CSS_JA.values).filter((k) => {
            const [prop, value] = [k.slice(0, k.indexOf('.')), k.slice(k.indexOf('.') + 1)];
            if (prop === '*') {
                return false;
            }
            const v = (mProps.get(prop)?.values ?? []).find((x) => x.name === value);
            return !isJa(v?.description);
        });
        check('日本語にした CSS の値はすべて日本語で出る(標準に無いものは足す)', [], badValues);
        check('重ねても CSS の値が 2 つ並ばない', [], mergedCss.properties.filter((p) => dupCount((p.values ?? []).map((v) => v.name)) > dupCount((stdProps.get(p.name)?.values ?? []).map((v) => v.name))).map((p) => p.name));
        check('日本語にした @ 規則はすべて日本語で出る', [], Object.keys(CSS_JA.at).filter((n) => !isJa(mergedCss.atDirectives.find((a) => a.name === n)?.description)));
        const display = mergedCss.properties.find((p) => p.name === 'display');
        ok('display の説明が日本語', display.description.value.startsWith('箱の種類'));
        ok('display: flex が日本語', display.values.find((v) => v.name === 'flex').description.value.includes('一列'));
        check('標準のプロパティの数を落とさない', cssData.properties.length, mergedCss.properties.length);
        ok(':hover が日本語', mergedCss.pseudoClasses.find((p) => p.name === ':hover').description.value.includes('合わせて'));
    }

    // ---- 段 11: VS Code 型のエディタ(code-src/src/paths.js)----
    const CODE_SRC = path.join(__dirname, '..', '..', '..', 'code-src', 'src');
    if (fs.existsSync(path.join(CODE_SRC, 'paths.js'))) {
        console.log('[code/paths]');
        const cp = await import(pathToFileURL(path.join(CODE_SRC, 'paths.js')).href);
        check('ワークスペースの中のパス', ['', 'a/b.html', 'css', null, null], [cp.relPath('/workspace'), cp.relPath('/workspace/a/b.html'), cp.relPath('/workspace/css/'), cp.relPath('/user/settings.json'), cp.relPath('/workspaceX/a')]);
        check('パス → 場所', ['/workspace', '/workspace/a/b.html'], [cp.absPath(''), cp.absPath('a/b.html')]);
        const enc = new TextEncoder();
        check('中身の形: 表のテキスト・画像・表に無い UTF-8', ['<p>', null, null], [cp.textOrNull('a.html', enc.encode('<p>')), cp.textOrNull('a.png', enc.encode('x')), cp.textOrNull('Makefile', new Uint8Array([0xff, 0xfe, 0x00]))]);
        check('フォルダ: ファイルの親と空のフォルダ', ['', 'a', 'a/b', 'e', 'e/f'], [...cp.allDirs(['a/b/c.html', 'x.css'], ['e/f'])].sort());
        check('フォルダの直下(名前の順・フォルダとファイル)', [['a', 'dir'], ['e', 'dir'], ['x.css', 'file']], cp.listDir('', ['a/b/c.html', 'x.css'], cp.allDirs(['a/b/c.html', 'x.css'], ['e'])));
        check('書ける名前: よい・型が無い・.vscode の設定(置ける)・.. ・フォルダは型を見ない', [null, 'type', null, 'parent', null], [cp.writeProblem('src/main.py'), cp.writeProblem('a.exe'), cp.writeProblem('.vscode/settings.json'), cp.writeProblem('a/../b.html'), cp.writeProblem('lib', { folder: true })].map((v) => (v === 'changed' ? 'parent' : v)));
        check('上限: 20MB を超える・500 個を超える・上書きは数を増やさない', ['bytes', 'files', null], [
            cp.limitProblem(new Map([['a', 20 * 1024 * 1024]]), 'b', 1),
            cp.limitProblem(new Map(Array.from({ length: 500 }, (_, i) => [`f${i}`, 0])), 'new', 0),
            cp.limitProblem(new Map(Array.from({ length: 500 }, (_, i) => [`f${i}`, 0])), 'f1', 10),
        ]);
        check('プレビューの窓から開く: 同じプロジェクト・行と桁', { path: 'js/app.js', line: 3, col: 5 }, cp.revealTarget({ type: 'reveal', projectId: 'p1', path: 'js/app.js', line: 3, col: 5 }, 'p1'));
        check('プレビューの窓から開く: 断るもの(別のプロジェクト・.. ・型・空・形)', [null, null, null, null, null, null], [
            cp.revealTarget({ type: 'reveal', projectId: 'p2', path: 'a.html' }, 'p1'),
            cp.revealTarget({ type: 'reveal', projectId: 'p1', path: '../a.html' }, 'p1'),
            cp.revealTarget({ type: 'reveal', projectId: 'p1', path: 'a.exe' }, 'p1'),
            cp.revealTarget({ type: 'reveal', projectId: 'p1', path: '' }, 'p1'),
            cp.revealTarget({ type: 'other', projectId: 'p1', path: 'a.html' }, 'p1'),
            cp.revealTarget('reveal', 'p1'),
        ]);
        check('プレビューの窓から開く: 行が数でなければ 0', { path: 'a.html', line: 0, col: 0 }, cp.revealTarget({ type: 'reveal', projectId: 'p1', path: 'a.html', line: '3', col: -1 }, 'p1'));

        console.log('[code/jaHelp]');
        const jh = await import(pathToFileURL(path.join(CODE_SRC, 'jaHelp.js')).href);
        const { indexJsApi: indexForCode } = await import(pathToFileURL(path.join(__dirname, '..', '..', '..', 'editor-src', 'src', 'data', 'merge.js')).href);
        // | の位置で引く
        const at = (fn, src, data) => fn(src.replace('|', ''), src.indexOf('|'), data);
        const head = (r) => (r ? r.markdown.split('\n')[0] : null);
        // タグはコードの形(`<a>`)。そのままだと markdown の中で HTML として消える(11-2 に Chrome で見つけた)
        check('HTML: タグの名前(開き・閉じ)', ['**`<a>`**', '**`<div>`**'], [head(at(jh.htmlHelp, '<|a href="x">', HTML_JA)), head(at(jh.htmlHelp, '<div></di|v>', HTML_JA))]);
        check('HTML: 属性(タグごと・どのタグでも・data-*)', ['**href**(`<a>` の属性)', '**class**(`<p>` の属性)', '**data-id**(`<p>` の属性)'], [
            head(at(jh.htmlHelp, '<a hr|ef="x">', HTML_JA)), head(at(jh.htmlHelp, '<p cla|ss="x">', HTML_JA)), head(at(jh.htmlHelp, '<p data-i|d="1">', HTML_JA)),
        ]);
        check('HTML: 引用符の中・本文・タグの外は出さない', [null, null, null], [at(jh.htmlHelp, '<a title="cla|ss">', HTML_JA), at(jh.htmlHelp, '<p>cla|ss</p>', HTML_JA), at(jh.htmlHelp, 'hre|f', HTML_JA)]);
        check('HTML: 説明は日本語で例が付く', true, /リンク/.test(at(jh.htmlHelp, '<|a>', HTML_JA).markdown) && at(jh.htmlHelp, '<|a>', HTML_JA).markdown.includes('```html'));
        check('CSS: プロパティ・値・どれでも使える値', ['**display**', '**flex**(display の値)', '**inherit**(color の値)'], [
            head(at(jh.cssHelp, 'a { disp|lay: flex; }', CSS_JA)), head(at(jh.cssHelp, 'a { display: fl|ex; }', CSS_JA)), CSS_JA.values['*.inherit'] ? head(at(jh.cssHelp, 'a{color:inh|erit}', CSS_JA)) : '**inherit**(color の値)',
        ]);
        check('CSS: @ 規則・疑似クラス・疑似要素(範囲は記号から)', ['**@media**', '**:hover**', '**::before**', 'a { } p'.length], [
            head(at(jh.cssHelp, '@med|ia (x) {}', CSS_JA)), head(at(jh.cssHelp, 'a:hov|er { }', CSS_JA)), head(at(jh.cssHelp, 'p::bef|ore { }', CSS_JA)), at(jh.cssHelp, 'a { } p::bef|ore { }', CSS_JA)?.start,
        ]);
        check('CSS: セレクタの名前・知らない値は出さない', [null, null], [at(jh.cssHelp, 'disp|lay { }', CSS_JA), at(jh.cssHelp, 'a { color: zz|zz; }', CSS_JA)]);
        const jsIndexCode = indexForCode(JS_API, 'ja');
        const docItem = JS_API.find(([owner]) => owner === 'document');
        const globalItem = JS_API.find(([owner]) => owner === '');
        check('JS: document.… と大域の関数は /editor/ と同じ説明', [true, true, null], [
            String(jh.jsHelp('const x = document.', docItem[1], jsIndexCode)).includes(docItem[3]),
            String(jh.jsHelp('  ', globalItem[1], jsIndexCode)).includes(globalItem[3]),
            jh.jsHelp('  ', 'noSuchThing', jsIndexCode),
        ]);

        // ---- 段 11-8: 自作の Web の検査(code-src/src/webChecksCore.js)----
        console.log('[code/webChecks]');
        const wc = await import(pathToFileURL(path.join(CODE_SRC, 'webChecksCore.js')).href);
        const sorted = (set) => [...set].sort();
        const css1 = wc.collectCssNames('/* .gone { } */ .btn, .btn-primary:hover > a.link { color: red; background: url(x.y.png); }\n'
            + '@media (max-width: 600px) { .nav .nav-item { content: ".fake"; } }\n#main{} @font-face { src: url(a.woff2); }\n.a\\:b, .日本 {}');
        check('CSS の名前: セレクタのクラス・id(コメント・文字列・url()・宣言は見ない)', [['btn', 'btn-primary', 'link', 'nav', 'nav-item', '日本'], ['main']], [sorted(css1.classes).filter((n) => n !== 'a'), sorted(css1.ids)]);
        check('CSS の名前: 入れ子の CSS は外側だけ', ['card'], sorted(wc.collectCssNames('.card { color: red; .inner { color: blue; } }').classes));
        check('<style> の中身', ['.a{}', 'p{}'], wc.styleBlocks('<style>.a{}</style><p></p><STYLE media="x">p{}</style >'));
        check('ほかのサイトの CSS を読んでいるか', [true, true, false, true, false], [
            wc.hasExternalStylesheet('<link rel="stylesheet" href="https://cdn.example/bootstrap.css">'),
            wc.hasExternalStylesheet('<link href="//cdn.example/x.css" rel=stylesheet>'),
            wc.hasExternalStylesheet('<link rel="stylesheet" href="style.css"><link rel="icon" href="https://x/y.png">'),
            wc.hasExternalStylesheet('<style>@import url("https://fonts.example/css");</style>'),
            wc.hasExternalStylesheet('<style>@import "local.css";</style>'),
        ]);
        check('class="…"・id="…" の中か(打ちかけの名前)', [{ kind: 'class', prefix: 'bt' }, { kind: 'class', prefix: '' }, { kind: 'id', prefix: 'ma' }, null, null], [
            wc.attrContext('<a class="nav bt'), wc.attrContext("<div class='x "), wc.attrContext('<p ID="ma'), wc.attrContext('<a class="x">'), wc.attrContext('<a data-class="x'),
        ]);
        const htmlU = '<div class="btn ghost">\n<!-- <p class="hidden-c"> -->\n<script>x.className = "<b class=\'js\'>"</script>\n<p class="{{ tpl }}" ID="x" class=\'nav zz\'></p>';
        const unknown = wc.unknownClasses(htmlU, new Set(['btn', 'nav']));
        check('無いクラス名(コメント・script・テンプレートは見ない。場所も)', [['ghost', 'zz'], ['ghost', 'zz']], [unknown.map((u) => u.name), unknown.map((u) => htmlU.substr(u.offset, u.name.length))]);
        const defaults = { 'tagname-lowercase': true, 'title-require': true };
        check('HTMLHint の規則: 既定に .htmlhintrc.json を重ねる(false で外す)', { rules: { 'tagname-lowercase': true, 'id-class-value': 'dash' }, error: null }, wc.htmlhintRules(defaults, '{"title-require": false, "id-class-value": "dash", "Bad Key": true}'));
        check('HTMLHint の規則: 無い・壊れた・配列', [null, 'json', 'object'], [wc.htmlhintRules(defaults, null).error, wc.htmlhintRules(defaults, '{x').error, wc.htmlhintRules(defaults, '[1]').error]);
        check('HTMLHint の規則: 既定は変えない', true, defaults['title-require'] === true);
        // 2026-11-13: 打ちかけの「<a」に出る spec-char-escape は既定で外す(.htmlhintrc.json で戻せる)
        check('HTMLHint の規則: spec-char-escape は既定で外す・設定で戻せる', [false, true, true], [
            'spec-char-escape' in wc.htmlhintRules({ 'spec-char-escape': true, 'title-require': true }, null).rules,
            wc.htmlhintRules({ 'spec-char-escape': true }, '{"spec-char-escape": true}').rules['spec-char-escape'],
            wc.htmlhintRules({ 'spec-char-escape': true, 'title-require': true }, null).rules['title-require'],
        ]);
        check('ESLint の読み方(script・module・commonjs)', ['script', 'module', 'module', 'module', 'commonjs', 'script'], [
            wc.sourceTypeOf('/a.js', 'let a = 1;'), wc.sourceTypeOf('/a.js', "import x from './x.js';"), wc.sourceTypeOf('/a.js', 'export const y = 1;'),
            wc.sourceTypeOf('/a.mjs', ''), wc.sourceTypeOf('/a.cjs', 'export const z = 1;'), wc.sourceTypeOf('/a.js', '// import x from "y"\nconst importx = 1;'),
        ]);
        // ---- 段 14-4: 雛形の JSON(editor-src/src/data/snippets.js)----
        console.log('[snippets]');
        const sn = await import(pathToFileURL(path.join(__dirname, '..', '..', '..', 'editor-src', 'src', 'data', 'snippets.js')).href);
        const snDefault = sn.defaultSnippetJson();
        const snFile = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'lib', 'editor-snippets.default.json'), 'utf8'));
        check('同梱の雛形の JSON(src/lib/editor-snippets.default.json)は data/html.js・js.js と同じ中身', true, JSON.stringify(snFile) === JSON.stringify(snDefault));
        check('同梱の雛形は形の確かめを通り、表に戻すと元と同じ', [true, true], [sn.normalizeSnippetJson(snDefault).ok, JSON.stringify(sn.snippetTables(snDefault).js) === JSON.stringify(sn.snippets.js)]);
        const snBase = (over) => ({ version: 1, html: { ja: [], en: [], ...(over.html ?? {}) }, js: over.js ?? [] });
        check('形の違うものは断る(版・形・タグ・改行入りの名前・空の body・多すぎ)', ['version', 'shape', 'html.ja', 'js', 'html.en', 'too-many', 'object'], [
            sn.normalizeSnippetJson({ version: 2, html: { ja: [], en: [] }, js: [] }).error,
            sn.normalizeSnippetJson({ version: 1, html: { ja: [] }, js: [] }).error,
            sn.normalizeSnippetJson(snBase({ html: { ja: [{ tag: 'A b', label: 'x', body: 'y' }] } })).error,
            sn.normalizeSnippetJson(snBase({ js: [{ label: 'a\nb', body: 'x' }] })).error,
            sn.normalizeSnippetJson(snBase({ html: { en: [{ tag: 'a', label: 'x', body: '' }] } })).error,
            sn.normalizeSnippetJson(snBase({ js: Array.from({ length: sn.SNIPPET_LIMITS.items + 1 }, () => ({ label: 'x', body: 'y' })) })).error,
            sn.normalizeSnippetJson([1]).error,
        ]);
        const snTarget = { htmlJa: [['old', 'old', 'old']], htmlEn: [], js: [], source: 'bundled' };
        const snCustom = snBase({ html: { ja: [{ tag: 'a', label: '学校のリンク', body: 'a href="https://example.test/">${1}</a>', extra: 1 }] }, js: [{ label: 'hello', body: 'console.log(1);' }] });
        check('口の答えを当てはめる(取り込んだものだけ・知らない項目は捨てる・説明が無ければ空)', [true, [['a', '学校のリンク', 'a href="https://example.test/">${1}</a>']], [['hello', 'console.log(1);', '', '']], 'server'], [
            sn.applySnippetResponse({ success: true, custom: true, data: snCustom }, snTarget), snTarget.htmlJa, snTarget.js, snTarget.source,
        ]);
        const snKeep = { htmlJa: [['keep', 'k', 'k']], htmlEn: [], js: [], source: 'bundled' };
        check('取り込んでいない・形が違う・失敗の答えでは変えない', [false, false, false, false, 'keep'], [
            sn.applySnippetResponse({ success: true, custom: false, data: null }, snKeep), sn.applySnippetResponse({ success: true, custom: true, data: { version: 9 } }, snKeep),
            sn.applySnippetResponse({ success: false }, snKeep), sn.applySnippetResponse(null, snKeep), snKeep.htmlJa[0][0],
        ]);
        check('読み込み: 届かない・404・壊れた答えは同梱のまま(投げない)', ['bundled', 'bundled', 'bundled'], [
            await sn.loadServerSnippets(async () => { throw new Error('offline'); }),
            await sn.loadServerSnippets(async () => ({ ok: false, json: async () => ({}) })),
            await sn.loadServerSnippets(async () => ({ ok: true, json: async () => { throw new Error('bad json'); } })),
        ]);
        // ---- 段 14-5(11-14): 先頭の < を外す・css を受ける・CSS の雛形を出す所 ----
        const snLt = sn.normalizeSnippetJson(snBase({ html: { ja: [{ tag: 'a', label: 'x', body: '<a href="">${1}</a>' }, { tag: 'b', label: 'y', body: 'b>$0</b>' }] } }));
        check('HTML の body の先頭の < は 1 つだけ外す(< を打ったあとに入るので、残すと <<a になる)', ['a href="">${1}</a>', 'b>$0</b>'], snLt.value.html.ja.map((x) => x.body));
        check('< だけの body は外すと空になるので断る', 'html.ja', sn.normalizeSnippetJson(snBase({ html: { ja: [{ tag: 'a', label: 'x', body: '<' }] } })).error);
        const snCss = { ...snBase({}), css: [{ label: 'display flex', body: 'display: flex;\ngap: ${1:1rem};', ja: '横に並べる。', en: 'Lays out in a row.' }] };
        const snCssTarget = { htmlJa: [], htmlEn: [], css: [], js: [], source: 'bundled' };
        check('css を受けて表にする・css が無くても通る(前の形のまま)', [true, [['display flex', 'display: flex;\ngap: ${1:1rem};', '横に並べる。', 'Lays out in a row.']], true, []], [
            sn.applySnippetResponse({ success: true, custom: true, data: snCss }, snCssTarget), snCssTarget.css,
            sn.normalizeSnippetJson(snBase({})).ok, sn.normalizeSnippetJson(snBase({})).value.css,
        ]);
        check('CSS は 1,000 個まで(ほかは 300 個)・css が並びでなければ断る', [true, 'too-many', 'shape', 'css'], [
            sn.normalizeSnippetJson({ ...snBase({}), css: Array.from({ length: sn.SNIPPET_LIMITS.cssItems }, () => ({ label: 'x', body: 'y' })) }).ok,
            sn.normalizeSnippetJson({ ...snBase({}), css: Array.from({ length: sn.SNIPPET_LIMITS.cssItems + 1 }, () => ({ label: 'x', body: 'y' })) }).error,
            sn.normalizeSnippetJson({ ...snBase({}), css: { a: 1 } }).error,
            sn.normalizeSnippetJson({ ...snBase({}), css: [{ label: 'x', body: '' }] }).error,
        ]);
        check('CSS の雛形を出す所: 頭・{ } ; の後だけ(値の途中・並びの途中・注釈の中は出さない)', ['', 'disp', '@me', '.box', 'fl', null, null, null, 'x'], [
            sn.cssSnippetTyped(''), sn.cssSnippetTyped('a {\n  disp'), sn.cssSnippetTyped('@me'), sn.cssSnippetTyped('}\n.box'), sn.cssSnippetTyped('a { color: red;\n  fl'),
            sn.cssSnippetTyped('a { color: re'), sn.cssSnippetTyped('a, b'), sn.cssSnippetTyped('/* disp'), sn.cssSnippetTyped('/* a */ x'),
        ]);
        check('<style> の中だけ CSS を出す', ['\n  a { ', null, null, null], [
            sn.htmlStyleBefore('<p>x</p><style>\n  a { '), sn.htmlStyleBefore('<style>a{}</style><p>'), sn.htmlStyleBefore('<p>'), sn.htmlStyleBefore('<style'),
        ]);
        const cssMore = await load('data/css-more.js');
        const cssMoreBad = Object.entries(cssMore.CSS_JA_MORE_PROPS).filter(([k, v]) => !Array.isArray(v) || v.length !== 2 || !v[0].endsWith('。') || !v[1].startsWith(`${k}:`) || !v[1].endsWith(';'));
        check('段 14-5: 雛形から外したプロパティの説明(css-more.js)は [説明。, 「名前: 値;」] の形・css.js にも入る・css.js の手書きと重ならない', [[], true, []], [
            cssMoreBad.map((x) => x[0]), Object.keys(cssMore.CSS_JA_MORE_PROPS).every((k) => k in CSS_JA.props),
            Object.keys(cssMore.CSS_JA_MORE_PROPS).filter((k) => CSS_JA.props[k] !== cssMore.CSS_JA_MORE_PROPS[k]),
        ]);
        const sp = await import(pathToFileURL(path.join(CODE_SRC, 'shims', 'path.js')).href);
        check('ESLint 用の node:path の代わり', ['.js', '', 'a/c.js', 'b.js', '/x', '../b'], [sp.extname('/a/b.js'), sp.extname('/a/.htmlhintrc'), sp.join('a', 'b', '..', 'c.js'), sp.basename('/a/b.js'), sp.dirname('/x/y'), sp.relative('/a/c', '/a/b')]);
    }

    console.log('');
    console.log(`${count} 件中 ${count - failures} 件 通過`);
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
