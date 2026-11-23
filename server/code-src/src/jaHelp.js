/*
 * 自作の日本語の用法を、カーソルの位置から引く(段 11-2。2026-11-08)。**純粋関数だけ**(node で試す)。
 *
 * データは /editor/ と同じ editor-src/src/data/*(段 9。文はすべて自分で書いたもの)。
 * VS Code 付属の HTML・CSS の言語サーバーは標準のデータ(英語)を必ず使い、切る設定が無い。
 * 同じデータを「カスタムデータ」で足すと候補が 2 つずつ並ぶので、/code/ では
 * **指を合わせたときの説明(ホバー)** と **雛形** で日本語を足す(jaExtension.js)
 */
import { lookupJs, jsDocMarkdown } from '../../editor-src/src/data/merge.js';

const WORD = /[A-Za-z0-9_-]/;

/** offset を含む [A-Za-z0-9_-]+ の範囲。無ければ null */
export function wordAt(text, offset) {
  let start = offset;
  let end = offset;
  while (start > 0 && WORD.test(text[start - 1])) {
    start--;
  }
  while (end < text.length && WORD.test(text[end])) {
    end++;
  }
  return start === end ? null : { start, end, word: text.slice(start, end) };
}

function withExample(head, text, example, codeLang) {
  const body = `**${head}**\n\n${text}`;
  return example ? `${body}\n\n\`\`\`${codeLang}\n${example}\n\`\`\`` : body;
}

/**
 * HTML: タグの名前・属性の名前の上なら、その説明(マークダウン)。
 * @returns {{start:number, end:number, markdown:string}|null}
 */
export function htmlHelp(text, offset, data) {
  const w = wordAt(text, offset);
  if (!w) {
    return null;
  }
  const before = text.slice(0, w.start);
  const name = w.word.toLowerCase();
  if (/<\/?$/.test(before)) {
    const entry = data.tags[name];
    // <a> は markdown の中では HTML のタグとして消えるので、コードの形(`<a>`)で書く
    return entry ? { ...w, markdown: withExample(`\`<${name}>\``, entry[0], entry[1], 'html') } : null;
  }
  // タグの中(最後の < が最後の > より後)で、引用符の外にある、空白の後の名前 = 属性
  const lt = before.lastIndexOf('<');
  if (lt < 0 || lt < before.lastIndexOf('>')) {
    return null;
  }
  const inside = before.slice(lt);
  const tag = /^<([A-Za-z][A-Za-z0-9-]*)/.exec(inside);
  if (!tag || !/\s$/.test(inside) || (inside.split('"').length - 1) % 2 === 1 || (inside.split("'").length - 1) % 2 === 1) {
    return null;
  }
  const tagName = tag[1].toLowerCase();
  const textJa = data.attrs[`${tagName}.${name}`] ?? data.global[name] ?? (name.startsWith('data-') ? data.global['data-*'] : undefined);
  return textJa ? { ...w, markdown: `**${name}**(\`<${tagName}>\` の属性)\n\n${textJa}` } : null;
}

/**
 * CSS: プロパティ・値・@ 規則・疑似クラス/要素の上なら、その説明。
 * @returns {{start:number, end:number, markdown:string}|null}
 */
export function cssHelp(text, offset, data) {
  const w = wordAt(text, offset);
  if (!w) {
    return null;
  }
  const before = text.slice(0, w.start);
  const name = w.word.toLowerCase();
  if (before.endsWith('@')) {
    const at = data.at[`@${name}`];
    return at ? { start: w.start - 1, end: w.end, markdown: `**@${name}**\n\n${at}` } : null;
  }
  for (const prefix of ['::', ':']) {
    if (before.endsWith(prefix) && data.pseudo[`${prefix}${name}`]) {
      return { start: w.start - prefix.length, end: w.end, markdown: `**${prefix}${name}**\n\n${data.pseudo[`${prefix}${name}`]}` };
    }
  }
  const inBlock = before.lastIndexOf('{') > before.lastIndexOf('}');
  if (!inBlock) {
    return null;
  }
  const decl = before.slice(Math.max(before.lastIndexOf('{'), before.lastIndexOf(';')) + 1);
  if (/^\s*$/.test(decl) && /^\s*:/.test(text.slice(w.end))) {
    const entry = data.props[name];
    return entry ? { ...w, markdown: withExample(name, entry[0], entry[1], 'css') } : null;
  }
  const prop = /([A-Za-z-]+)\s*:[^:]*$/.exec(decl);
  if (prop) {
    const p = prop[1].toLowerCase();
    const value = data.values[`${p}.${name}`] ?? data.values[`*.${name}`];
    return value ? { ...w, markdown: `**${name}**(${p} の値)\n\n${value}` } : null;
  }
  return null;
}

/**
 * JS: document.querySelector などの上なら、その説明(/editor/ のホバーと同じ引き方)。
 * @param {string} lineBefore  その行の、言葉の前まで
 * @param {{byOwner: Map, owners: Set}} index  merge.js の indexJsApi
 */
export function jsHelp(lineBefore, word, index) {
  const dot = /([A-Za-z_$][\w$]*)?\s*\.\s*$/.exec(lineBefore);
  const owner = dot ? (dot[1] ?? '.') : '';
  const item = lookupJs(index, owner, word);
  return item ? jsDocMarkdown(item) : null;
}
