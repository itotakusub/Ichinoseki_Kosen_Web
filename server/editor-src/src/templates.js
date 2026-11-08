/*
 * テンプレート(計画書 7 章の 19。2026-11-08)。日英で文だけ変える。
 * 最初に開いたとき・リセットしたときは 'starter'(はじめの一歩)を使う。
 */
import { buildExportDocument } from './preview/htmlBuilder.js';
const T = {
  blank: {
    name: { ja: '空', en: 'Blank' },
    note: { ja: '何も書いていない状態', en: 'Start from nothing' },
    build: () => ({ html: '', css: '', js: '' }),
  },
  starter: {
    name: { ja: 'はじめの一歩', en: 'First steps' },
    note: { ja: '見出しと段落・色・console.log', en: 'Heading, paragraph, colors and console.log' },
    build: (ja) => ({
      html: ja
        ? '<h1>こんにちは!</h1>\n<p>左で書き換えると、ここがすぐに変わります。</p>\n'
        : '<h1>Hello!</h1>\n<p>Edit the code on the left and this updates right away.</p>\n',
      css: 'body {\n  font-family: system-ui, sans-serif;\n  margin: 2rem;\n  line-height: 1.7;\n}\n\nh1 {\n  color: #2563eb;\n}\n',
      js: ja
        ? "console.log('ようこそ! ここに出たものは下のコンソールに表示されます。');\n"
        : "console.log('Welcome! Anything logged here appears in the console below.');\n",
    }),
  },
  button: {
    name: { ja: 'ボタンとイベント', en: 'Button and events' },
    note: { ja: 'クリックで数を増やす', en: 'Count up on each click' },
    build: (ja) => ({
      html: ja
        ? '<button id="btn" type="button">押してね</button>\n<p>押した回数: <span id="count">0</span></p>\n'
        : '<button id="btn" type="button">Click me</button>\n<p>Clicks: <span id="count">0</span></p>\n',
      css: 'body {\n  font-family: system-ui, sans-serif;\n  margin: 2rem;\n}\n\nbutton {\n  padding: 0.6rem 1.2rem;\n  border: 0;\n  border-radius: 8px;\n  background: #16a34a;\n  color: #fff;\n  font-size: 1rem;\n  cursor: pointer;\n}\n\nbutton:active {\n  transform: scale(0.97);\n}\n',
      js: "const button = document.querySelector('#btn');\nconst count = document.querySelector('#count');\nlet clicks = 0;\n\nbutton.addEventListener('click', () => {\n  clicks += 1;\n  count.textContent = clicks;\n  console.log('clicks:', clicks);\n});\n",
    }),
  },
  flex: {
    name: { ja: 'レイアウト(flex)', en: 'Layout (flex)' },
    note: { ja: 'カードを横に並べ、狭い画面で折り返す', en: 'Cards in a row that wrap on narrow screens' },
    build: (ja) => ({
      html: ja
        ? '<header class="top">サイトの名前</header>\n<main class="cards">\n  <article class="card">カード 1</article>\n  <article class="card">カード 2</article>\n  <article class="card">カード 3</article>\n</main>\n'
        : '<header class="top">Site name</header>\n<main class="cards">\n  <article class="card">Card 1</article>\n  <article class="card">Card 2</article>\n  <article class="card">Card 3</article>\n</main>\n',
      css: '* {\n  box-sizing: border-box;\n}\n\nbody {\n  margin: 0;\n  font-family: system-ui, sans-serif;\n}\n\n.top {\n  padding: 1rem;\n  background: #1e293b;\n  color: #fff;\n}\n\n.cards {\n  display: flex;\n  flex-wrap: wrap;\n  gap: 1rem;\n  padding: 1rem;\n}\n\n.card {\n  flex: 1 1 200px;\n  padding: 1.5rem;\n  border-radius: 12px;\n  background: #f1f5f9;\n}\n',
      js: '',
    }),
  },
  list: {
    name: { ja: 'やることリスト', en: 'To-do list' },
    note: { ja: '入力欄・配列・要素を作って足す', en: 'Input, arrays and creating elements' },
    build: (ja) => ({
      html: ja
        ? '<h1>やること</h1>\n<form id="form">\n  <input id="text" placeholder="やることを入力" required>\n  <button type="submit">追加</button>\n</form>\n<ul id="list"></ul>\n'
        : '<h1>To-do</h1>\n<form id="form">\n  <input id="text" placeholder="What to do" required>\n  <button type="submit">Add</button>\n</form>\n<ul id="list"></ul>\n',
      css: 'body {\n  font-family: system-ui, sans-serif;\n  margin: 2rem;\n  max-width: 480px;\n}\n\nform {\n  display: flex;\n  gap: 0.5rem;\n}\n\ninput {\n  flex: 1;\n  padding: 0.5rem;\n}\n\nli {\n  padding: 0.4rem 0;\n  cursor: pointer;\n}\n\nli.done {\n  text-decoration: line-through;\n  color: #94a3b8;\n}\n',
      js: "const form = document.querySelector('#form');\nconst input = document.querySelector('#text');\nconst list = document.querySelector('#list');\nconst items = [];\n\nform.addEventListener('submit', (event) => {\n  event.preventDefault();\n  const text = input.value.trim();\n  if (text === '') return;\n  items.push(text);\n  const li = document.createElement('li');\n  li.textContent = text;\n  li.addEventListener('click', () => li.classList.toggle('done'));\n  list.append(li);\n  input.value = '';\n  console.log(items);\n});\n",
    }),
  },
};

export const TEMPLATE_IDS = ['blank', 'starter', 'button', 'flex', 'list'];
export const DEFAULT_TEMPLATE = 'starter';

export function templateName(id, lang) {
  return T[id]?.name[lang] ?? T[id]?.name.ja ?? id;
}

export function templateNote(id, lang) {
  return T[id]?.note[lang] ?? T[id]?.note.ja ?? '';
}

/** @returns {{html: string, css: string, js: string}} */
export function buildTemplate(id, lang) {
  const tpl = T[id] ?? T[DEFAULT_TEMPLATE];
  return tpl.build(lang !== 'en');
}

/**
 * 3 つの欄(html・css・js)を、プロジェクトのファイルにする(段 10)。
 * index.html は断片なら <link rel="stylesheet" href="style.css">・<script src="script.js"> 付きの完全な文書に包む。
 * 空の CSS・JS はファイルを作らない(読み込みも書かない)。今の書きかけの移し替え(migrate.js)も同じ形にする。
 * @returns {Record<string, string>} パス → 中身
 */
export function docToFiles(doc, { lang = 'ja', title = 'Web Code Editor' } = {}) {
  const files = { 'index.html': buildExportDocument(doc, { mode: 'split', lang, title }) };
  if (String(doc.css ?? '').trim() !== '') {
    files['style.css'] = String(doc.css);
  }
  if (String(doc.js ?? '').trim() !== '') {
    files['script.js'] = String(doc.js);
  }
  return files;
}

/** テンプレートから新しいプロジェクトのファイル(パス → 中身) */
export function buildTemplateFiles(id, lang) {
  return docToFiles(buildTemplate(id, lang), { lang, title: templateName(id, lang) });
}
