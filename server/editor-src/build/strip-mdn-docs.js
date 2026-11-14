/*
 * JS の候補・カーソルを合わせたときに出る、型の定義(lib.dom.d.ts など)の英語の説明のうち、MDN から来たもの
 * (MDN の要約の文と「MDN Reference」のリンク)を、組み立てのときに外す(2026-11-14、利用者「JS で MDN のコメントが出ています。消してください」)。
 *
 * - /editor/(Monaco): monaco-editor の esm/vs/languages/features/typescript/lib/lib.js(型の定義を文字列で持つ)を書き換える(editor-src/vite.config.js)
 * - /code/(VS Code 型): 付属の TypeScript の型の定義のファイル(lib.*.d.ts-*.txt)を書き換える(code-src/vite.config.js)
 *
 * 外すのは MDN から来た説明のかたまり(/** … *\/): 「[MDN Reference](」を含むもの、と MDN の要約の書き方「**`名前`**」を含むもの
 * (リンクの無い要約もある。Document と DocumentFragment の getElementById など。11-14 に検証機で見つけた。この書き方は lib.dom・lib.webworker にしか無い)。
 * TypeScript が自分で書いた説明(lib.es5 など)と、仕様から来た説明(MutationObserverInit の各項目など)は残す。
 * かたまりの中の @deprecated は残す(使わないほうがよいものに打ち消し線が出るのは役に立つ)。ほかのタグは MDN のかたまりには無い(11-14 に数えた)。
 * 純粋関数(src/scripts/js/editor.test.js が試す)。
 */

/** 行の頭から始まる説明のかたまり 1 つ(中に *\/ を含まない最短のもの)と、その行の終わり */
const DOC_BLOCK = /^([ \t]*)\/\*\*((?:(?!\*\/)[\s\S])*?)\*\/[ \t]*\r?\n/gm;
const MDN_MARK = '[MDN Reference](';
/** MDN の要約の書き方(The **`getElementById()`** method of …) */
const MDN_SUMMARY = /\*\*`[^`\n]+`\*\*/;

/**
 * @param {string} text 型の定義の中身(.d.ts の文)
 * @returns {{ text: string, removed: number, left: number }} removed = 外したかたまりの数、left = 外せずに残った MDN の印の数
 */
export function stripMdnDocs(text) {
  let removed = 0;
  const out = text.replace(DOC_BLOCK, (block, indent, body) => {
    if (!body.includes(MDN_MARK) && !MDN_SUMMARY.test(body)) return block;
    removed++;
    const deprecated = body.split('\n').map((line) => line.replace(/^\s*\*?\s?/, '').trim()).find((line) => line.startsWith('@deprecated'));
    return deprecated ? `${indent}/** ${deprecated.replace(/\*\//g, '')} */\n` : '';
  });
  return { text: out, removed, left: out.split(MDN_MARK).length - 1 };
}

/**
 * monaco の lib.js(型の定義を libFileMap に名前ごとの文字列で持つ ES モジュール)を、MDN の説明を外して書き直す。
 * lib.js は一重・二重・逆引用符の文字列が混ざっていて文で直すのは危ないので、**読み込んだ値**(libFileMap)から作り直す
 * (editor-src/vite.config.js の load で lib.js を import して渡す)。
 *
 * @param {Record<string, string>} libFileMap
 * @returns {{ code: string, removed: number, left: number }}
 */
export function monacoLibModule(libFileMap) {
  let removed = 0;
  let left = 0;
  const lines = ['const libFileMap = {};'];
  for (const [name, text] of Object.entries(libFileMap)) {
    const result = stripMdnDocs(String(text));
    removed += result.removed;
    left += result.left;
    lines.push(`libFileMap[${JSON.stringify(name)}] = ${JSON.stringify(result.text)};`);
  }
  lines.push('export { libFileMap };');
  return { code: lines.join('\n') + '\n', removed, left };
}