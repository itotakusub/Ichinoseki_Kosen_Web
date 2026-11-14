/*
 * 雛形(候補に出る「a — リンク」「for」など)の JSON と、サーバーに取り込んだ雛形の読み込み(段 14-4。2026-11-13)。
 *
 * 利用者「予測変換は JSON で管理できませんか」「管理画面の取り込みだけ作る」。
 * - 同梱の雛形は今までどおり data/html.js・data/js.js(正本)。ここはそれを JSON の形にして出す・JSON から戻す
 * - 管理画面「エディタの雛形」で JSON を取り込むと、/api/editor-snippets.php がそれを返す。
 *   /editor/ と /code/ は起動のときに 1 回読み、届けば差し替える。**届かない・形が違うときは同梱のまま**
 * - 形の確かめはサーバー(lib/editor-snippets.php)と同じ決まり。ここでも確かめる(サーバーを信じ切らない)
 *
 * JSON の形(version 1):
 *   { "version": 1,
 *     "html": { "ja": [ { "tag": "a", "label": "リンク", "body": "a href=\"${1:URLを入力}\">${2:リンクの文字}</a>" } ],
 *               "en": [ … ] },
 *     "css":  [ { "label": "display flex", "body": "display: flex;\ngap: ${1:1rem};", "ja": "…", "en": "…" } ],   (11-14 から。無くてもよい)
 *     "js":   [ { "label": "if", "body": "if (${1:condition}) {\n\t$0\n}", "ja": "もし…なら。", "en": "Runs the block only if…" } ] }
 *   html の body は先頭の < を書かなくてよい(**書いてあれば外す**。< を打ったあとに入るので、残すと <<a になる)。
 *   ${1:…} は Tab で順に回る入力欄、$0 は最後のカーソル
 *
 * 11-14(段 14-5): css を受ける・先頭の < を外す・CSS は 1,000 個まで(サーバーと同じ)
 */
import { HTML_JA, HTML_SNIPPETS_EN } from './html.js';
import { JS_SNIPPETS } from './js.js';

export const SNIPPET_VERSION = 1;
/** サーバー(lib/editor-snippets.php の KM_EDITOR_SNIPPET_*)と同じ値にすること */
export const SNIPPET_LIMITS = { items: 300, cssItems: 1000, tag: 40, label: 80, body: 2000, doc: 400 };
export const SNIPPETS_URL = '/api/editor-snippets.php';

/** 同梱の雛形を JSON の形で(管理画面の「同梱の雛形を書き出す」と同じ中身。src/lib/editor-snippets.default.json) */
export function defaultSnippetJson() {
  return {
    version: SNIPPET_VERSION,
    html: {
      ja: HTML_JA.snippets.map(([tag, label, body]) => ({ tag, label, body })),
      en: HTML_SNIPPETS_EN.map(([tag, label, body]) => ({ tag, label, body })),
    },
    // CSS の雛形は同梱していない(管理画面で取り込むもの。プロパティの説明は data/css.js)
    css: [],
    js: JS_SNIPPETS.map(([label, body, ja, en]) => ({ label, body, ja, en })),
  };
}

const isText = (value, max, min = 1) => typeof value === 'string' && value.length >= min && value.length <= max && !value.includes('\u0000');
const oneLine = (value) => !/[\r\n]/.test(value);

/**
 * JSON の形を確かめる。**純粋関数**。
 * @returns {{ok: true, value: object}|{ok: false, error: string}}
 */
export function normalizeSnippetJson(data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    return { ok: false, error: 'object' };
  }
  if (data.version !== SNIPPET_VERSION) {
    return { ok: false, error: 'version' };
  }
  const html = data.html;
  const css = data.css ?? [];
  if (!html || typeof html !== 'object' || !Array.isArray(html.ja) || !Array.isArray(html.en) || !Array.isArray(data.js) || !Array.isArray(css)) {
    return { ok: false, error: 'shape' };
  }
  if (html.ja.length > SNIPPET_LIMITS.items || html.en.length > SNIPPET_LIMITS.items || data.js.length > SNIPPET_LIMITS.items || css.length > SNIPPET_LIMITS.cssItems) {
    return { ok: false, error: 'too-many' };
  }
  const value = { version: SNIPPET_VERSION, html: { ja: [], en: [] }, css: [], js: [] };
  for (const lang of ['ja', 'en']) {
    for (const item of html[lang]) {
      // 先頭の < は外す(サーバーと同じ)
      const body = item && typeof item.body === 'string' && item.body.startsWith('<') ? item.body.slice(1) : item?.body;
      if (!item || typeof item !== 'object' || !isText(item.tag, SNIPPET_LIMITS.tag) || !/^[a-z][a-z0-9-]*$/.test(item.tag)
        || !isText(item.label, SNIPPET_LIMITS.label) || !oneLine(item.label) || !isText(body, SNIPPET_LIMITS.body)) {
        return { ok: false, error: `html.${lang}` };
      }
      value.html[lang].push({ tag: item.tag, label: item.label, body });
    }
  }
  for (const [section, list] of [['css', css], ['js', data.js]]) {
    for (const item of list) {
      if (!item || typeof item !== 'object' || !isText(item.label, SNIPPET_LIMITS.label) || !oneLine(item.label) || !isText(item.body, SNIPPET_LIMITS.body)
        || !isText(item.ja ?? '', SNIPPET_LIMITS.doc, 0) || !isText(item.en ?? '', SNIPPET_LIMITS.doc, 0)) {
        return { ok: false, error: section };
      }
      value[section].push({ label: item.label, body: item.body, ja: item.ja ?? '', en: item.en ?? '' });
    }
  }
  return { ok: true, value };
}

/**
 * CSS の雛形を出してよい所か。よければ打ちかけの文字(差し替える範囲)を返す。**純粋関数**(段 14-5)。
 * 出すのは「ルールやプロパティを書き始める所」だけ: 文書の頭・`{` `}` `;` の後。
 * 値の途中(`color: ` の後)・セレクタの並びの途中(`a, ` の後)・注釈の中では出さない。
 * @param {string} before カーソルより前の CSS(HTML なら <style> の中だけ。htmlStyleBefore)
 * @returns {string|null}
 */
export function cssSnippetTyped(before) {
  // 閉じた注釈は空白と同じに扱う。閉じていない注釈の中なら出さない
  const text = before.replace(/\/\*[\s\S]*?\*\//g, ' ');
  if (text.includes('/*')) {
    return null;
  }
  const typed = /[A-Za-z0-9@.#:&*_-]*$/.exec(text)[0];
  const head = text.slice(0, text.length - typed.length).replace(/\s+$/, '');
  if (head !== '' && !'{};'.includes(head.slice(-1))) {
    return null;
  }
  return typed;
}

/** HTML のカーソルが <style> の中なら、その中のカーソルより前の文字。外なら null。**純粋関数** */
export function htmlStyleBefore(before) {
  const lower = before.toLowerCase();
  const open = lower.lastIndexOf('<style');
  if (open < 0 || lower.lastIndexOf('</style') > open) {
    return null;
  }
  const gt = before.indexOf('>', open);
  return gt < 0 ? null : before.slice(gt + 1);
}

/** JSON(確かめたもの)を、エディタが使う表の形に */
export function snippetTables(json) {
  return {
    htmlJa: json.html.ja.map((x) => [x.tag, x.label, x.body]),
    htmlEn: json.html.en.map((x) => [x.tag, x.label, x.body]),
    css: (json.css ?? []).map((x) => [x.label, x.body, x.ja, x.en]),
    js: json.js.map((x) => [x.label, x.body, x.ja, x.en]),
  };
}

/**
 * いま使う雛形。**候補を出すたびにここを読む**(起動のあとでサーバーのものに差し替わる)。
 * source: 'bundled'(同梱)/ 'server'(管理画面で取り込んだもの)
 */
export const snippets = { htmlJa: HTML_JA.snippets, htmlEn: HTML_SNIPPETS_EN, css: [], js: JS_SNIPPETS, source: 'bundled' };

/** 口の答え({success, custom, data})を当てはめる。**純粋(snippets を書き換えるだけ)**。差し替えたら true */
export function applySnippetResponse(body, target = snippets) {
  if (!body || body.success !== true || body.custom !== true) {
    return false;
  }
  const checked = normalizeSnippetJson(body.data);
  if (!checked.ok) {
    return false;
  }
  Object.assign(target, snippetTables(checked.value), { source: 'server' });
  return true;
}

/**
 * サーバーに取り込んだ雛形があれば差し替える。失敗しても何も投げない(同梱のまま)。
 * @returns {Promise<'server'|'bundled'>}
 */
export async function loadServerSnippets(fetchImpl = globalThis.fetch, url = SNIPPETS_URL) {
  try {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), 4000) : null;
    const response = await fetchImpl(url, { credentials: 'omit', signal: controller?.signal, headers: { Accept: 'application/json' } });
    if (timer) {
      clearTimeout(timer);
    }
    if (!response.ok) {
      return 'bundled';
    }
    return applySnippetResponse(await response.json()) ? 'server' : 'bundled';
  } catch {
    return 'bundled';
  }
}
