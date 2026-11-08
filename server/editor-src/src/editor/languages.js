/*
 * 補完と用法を Monaco に渡す(2026-11-08)。言語(ja / en)を切り替えたら setLanguageData をもう一度呼ぶ。
 *
 * - HTML・CSS: Monaco の言語サービスの「カスタムデータ」(VS Code と同じ形)。標準のデータ(英語)を土台に
 *   日本語の説明と例を重ねた 1 組を渡し、標準のものは切る(同じタグが 2 つ並ばないように)。
 *   標準のデータは大きい(HTML 0.4MB・CSS 1MB)ので、初めに使うときに別のファイルとして読む
 * - HTML の雛形: < の後で「a — リンク」のような候補を出し、href="URLを入力" まで入れる
 * - JS: TypeScript の worker の補完(英語)はそのまま。日本語の説明と例が付いた候補と、指を合わせたときの説明を足す
 * - すべてブラウザの中(worker を含む)で動く。サーバーへは何も聞かない
 */
import { monaco, htmlService, cssService } from './monaco.js';
import { HTML_JA, HTML_SNIPPETS_EN } from '../data/html.js';
import { CSS_JA } from '../data/css.js';
import { JS_API, JS_SNIPPETS } from '../data/js.js';
import { mergeHtmlData, mergeCssData, indexJsApi, lookupJs, jsDocMarkdown, plainSnippet } from '../data/merge.js';

let lang = 'ja';
let jsIndex = indexJsApi(JS_API, lang);
let stdPromise = null;

function loadStandardData() {
  stdPromise ??= Promise.all([
    import('monaco-external/vscode-html-languageservice/lib/esm/languageFacts/data/webCustomData.js'),
    import('monaco-external/vscode-css-languageservice/lib/esm/data/webCustomData.js'),
  ]).then(([html, css]) => ({ html: html.htmlData, css: css.cssData }));
  return stdPromise;
}

/** 説明の初めの一文(候補の一覧の右に出す短い説明) */
function shortDoc(text) {
  const plain = String(text).replace(/\*\*/g, '');
  const cut = plain.search(/[。.](\s|$)/);
  const first = cut >= 0 ? plain.slice(0, cut + 1) : plain;
  return first.length > 36 ? `${first.slice(0, 35)}…` : first;
}

export async function setLanguageData(next) {
  lang = next === 'en' ? 'en' : 'ja';
  jsIndex = indexJsApi(JS_API, lang);
  const want = lang;
  const std = await loadStandardData();
  if (want !== lang) {
    return; // 読み込みの間にまた切り替えた
  }
  htmlService.htmlDefaults.setOptions({
    ...htmlService.htmlDefaults.options,
    data: { useDefaultDataProvider: false, dataProviders: { km: mergeHtmlData(std.html, HTML_JA, lang) } },
  });
  cssService.cssDefaults.setOptions({
    ...cssService.cssDefaults.options,
    data: { useDefaultDataProvider: false, dataProviders: { km: mergeCssData(std.css, CSS_JA, lang) } },
  });
}

function lineBefore(model, position, column = position.column) {
  return model.getValueInRange({
    startLineNumber: position.lineNumber,
    startColumn: 1,
    endLineNumber: position.lineNumber,
    endColumn: column,
  });
}

let registered = false;

export function registerLanguageFeatures() {
  if (registered) {
    return;
  }
  registered = true;
  const { CompletionItemKind, CompletionItemInsertTextRule } = monaco.languages;

  // HTML の雛形(< の後)
  monaco.languages.registerCompletionItemProvider('html', {
    triggerCharacters: ['<'],
    provideCompletionItems(model, position) {
      const m = /<([A-Za-z][A-Za-z0-9-]*)?$/.exec(lineBefore(model, position));
      if (!m) {
        return { suggestions: [] };
      }
      const typed = m[1] ?? '';
      const range = {
        startLineNumber: position.lineNumber,
        endLineNumber: position.lineNumber,
        startColumn: position.column - typed.length,
        endColumn: position.column,
      };
      const list = lang === 'en' ? HTML_SNIPPETS_EN : HTML_JA.snippets;
      return {
        suggestions: list.map(([tag, label, body], i) => ({
          label: { label: tag, description: label },
          kind: CompletionItemKind.Snippet,
          insertText: body,
          insertTextRules: CompletionItemInsertTextRule.InsertAsSnippet,
          filterText: tag,
          sortText: `${tag} ${String(i).padStart(2, '0')}`,
          detail: lang === 'en' ? `Template: ${label}` : `雛形: ${label}`,
          documentation: { value: `\`\`\`html\n<${plainSnippet(body)}\n\`\`\`` },
          range,
        })),
      };
    },
  });

  // JS: 日本語の説明が付いた候補
  monaco.languages.registerCompletionItemProvider('javascript', {
    triggerCharacters: ['.'],
    provideCompletionItems(model, position) {
      const word = model.getWordUntilPosition(position);
      const before = lineBefore(model, position, word.startColumn);
      if (/\/\//.test(before) || /^\s*\*/.test(before)) {
        return { suggestions: [] }; // 注釈の中
      }
      const range = {
        startLineNumber: position.lineNumber,
        endLineNumber: position.lineNumber,
        startColumn: word.startColumn,
        endColumn: word.endColumn,
      };
      const make = (item, label, insert, filter) => ({
        label: { label, description: shortDoc(item.doc) },
        kind: item.args.startsWith('(') ? CompletionItemKind.Method : CompletionItemKind.Property,
        insertText: insert,
        insertTextRules: CompletionItemInsertTextRule.InsertAsSnippet,
        filterText: filter,
        sortText: `!${filter}`,
        detail: item.doc,
        documentation: { value: jsDocMarkdown(item) },
        range,
      });
      const dot = /([A-Za-z_$][\w$]*)?\s*\.\s*$/.exec(before);
      if (dot) {
        const owner = dot[1] && jsIndex.owners.has(dot[1]) ? dot[1] : '.';
        const items = jsIndex.byOwner.get(owner) ?? [];
        return {
          suggestions: items.map((item) => make(item, item.name + (item.args.startsWith('(') ? '()' : ''), item.name + item.args, item.name)),
        };
      }
      const suggestions = [];
      for (const item of jsIndex.byOwner.get('') ?? []) {
        suggestions.push(make(item, item.name + (item.args.startsWith('(') ? '()' : ''), item.name + item.args, item.name));
      }
      for (const owner of jsIndex.owners) {
        for (const item of jsIndex.byOwner.get(owner)) {
          const full = `${owner}.${item.name}`;
          suggestions.push(make(item, full + (item.args.startsWith('(') ? '()' : ''), full + item.args, full));
        }
      }
      for (const [label, body, jaText, enText] of JS_SNIPPETS) {
        const doc = lang === 'en' ? enText : jaText;
        suggestions.push({
          label: { label, description: shortDoc(doc) },
          kind: CompletionItemKind.Snippet,
          insertText: body,
          insertTextRules: CompletionItemInsertTextRule.InsertAsSnippet,
          filterText: label,
          sortText: `!${label}`,
          detail: doc,
          documentation: { value: `${doc}\n\n\`\`\`js\n${plainSnippet(body)}\n\`\`\`` },
          range,
        });
      }
      return { suggestions };
    },
  });

  // JS: 指を合わせたときの日本語の説明
  monaco.languages.registerHoverProvider('javascript', {
    provideHover(model, position) {
      const word = model.getWordAtPosition(position);
      if (!word) {
        return null;
      }
      const before = lineBefore(model, position, word.startColumn);
      const dot = /([A-Za-z_$][\w$]*)?\s*\.\s*$/.exec(before);
      const owner = dot ? (dot[1] ?? '.') : '';
      const item = lookupJs(jsIndex, owner, word.word);
      if (!item) {
        return null;
      }
      return {
        range: {
          startLineNumber: position.lineNumber,
          endLineNumber: position.lineNumber,
          startColumn: word.startColumn,
          endColumn: word.endColumn,
        },
        contents: [{ value: jsDocMarkdown(item) }],
      };
    },
  });
}
