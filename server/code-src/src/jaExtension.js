/*
 * 自作の日本語の用法(段 11-2。2026-11-08)。/editor/ の段 9 のデータを、VS Code の拡張機能の形で足す。
 *
 * - 指を合わせたときの説明(日本語の画面のときだけ): HTML のタグ・属性、CSS のプロパティ・値・@ 規則・疑似クラス、
 *   JS の API(document.querySelector など)。VS Code 付属の説明(英語)の上に並ぶ
 * - 雛形(どちらの言語でも): HTML の < の後に「a — リンク」のような候補、JS の for・fetch などの雛形
 * - 拡張機能の欄からオフにできる(名前「日本語の用法」)
 */
import { ExtensionHostKind, registerExtension } from '@codingame/monaco-vscode-api/extensions';
import { HTML_JA } from '../../editor-src/src/data/html.js';
import { CSS_JA } from '../../editor-src/src/data/css.js';
import { JS_API } from '../../editor-src/src/data/js.js';
// 雛形は snippets から(管理画面で取り込んだ JSON に、起動のあとで差し替わる。段 14-4)
import { cssSnippetTyped, htmlStyleBefore, loadServerSnippets, snippets } from '../../editor-src/src/data/snippets.js';
import { indexJsApi, plainSnippet } from '../../editor-src/src/data/merge.js';
import { cssHelp, htmlHelp, jsHelp } from './jaHelp.js';

/** 説明の初めの一文(候補の右に出す短い説明。/editor/ と同じ) */
function shortDoc(text) {
  const plain = String(text).replace(/\*\*/g, '');
  const cut = plain.search(/[。.](\s|$)/);
  const first = cut >= 0 ? plain.slice(0, cut + 1) : plain;
  return first.length > 36 ? `${first.slice(0, 35)}…` : first;
}

const EXTENSION_ID = 'ito4.km-ja-help';

/**
 * **initialize の前に呼ぶ**(後から足した拡張機能は「拡張機能」の欄に出ず、オフにできない)。
 * 返す Promise は initialize のあとで待つ(getApi は起動が済むまで返らない)
 */
export function registerJapaneseHelp({ lang }) {
  const ja = lang === 'ja';
  const reg = registerExtension({
    name: 'km-ja-help',
    publisher: 'ito4',
    displayName: ja ? '日本語の用法(Web Code Editor)' : 'Japanese help (Web Code Editor)',
    description: ja ? 'HTML・CSS・JS の日本語の説明と雛形' : 'HTML/CSS/JS templates (and Japanese descriptions when the UI is Japanese)',
    version: '1.0.0',
    engines: { vscode: '*' },
    activationEvents: ['onLanguage:html', 'onLanguage:css', 'onLanguage:javascript', 'onLanguage:typescript'],
  }, ExtensionHostKind.LocalProcess, { system: true });
  return reg.getApi().then((vscode) => {
    // 拡張機能の欄でオフにされていれば何も足さない(オフの拡張機能は一覧から引けない)
    if (!vscode.extensions.getExtension(EXTENSION_ID)) {
      return;
    }
    addProviders(vscode, ja, lang);
  });
}

function addProviders(vscode, ja, lang) {
  const md = (value) => new vscode.MarkdownString(value);
  const jsIndex = indexJsApi(JS_API, lang);

  if (ja) {
    const hoverFrom = (help) => ({
      provideHover(document, position) {
        const offset = document.offsetAt(position);
        const found = help(document.getText(), offset);
        return found ? new vscode.Hover(md(found.markdown), new vscode.Range(document.positionAt(found.start), document.positionAt(found.end))) : null;
      },
    });
    vscode.languages.registerHoverProvider('html', hoverFrom((text, offset) => htmlHelp(text, offset, HTML_JA)));
    vscode.languages.registerHoverProvider(['css', 'scss', 'less'], hoverFrom((text, offset) => cssHelp(text, offset, CSS_JA)));
    vscode.languages.registerHoverProvider(['javascript', 'typescript', 'javascriptreact', 'typescriptreact'], {
      provideHover(document, position) {
        const range = document.getWordRangeAtPosition(position, /[A-Za-z_$][\w$]*/);
        if (!range) {
          return null;
        }
        const before = document.lineAt(position.line).text.slice(0, range.start.character);
        const text = jsHelp(before, document.getText(range), jsIndex);
        return text ? new vscode.Hover(md(text), range) : null;
      },
    });
  }

  // HTML の雛形(< の後)
  // サーバーに取り込んだ雛形があれば差し替える(待たない。届かなければ同梱のまま)
  loadServerSnippets();
  vscode.languages.registerCompletionItemProvider('html', {
    provideCompletionItems(document, position) {
      const line = document.lineAt(position.line).text.slice(0, position.character);
      const m = /<([A-Za-z][A-Za-z0-9-]*)?$/.exec(line);
      if (!m) {
        return [];
      }
      const typed = m[1] ?? '';
      const range = new vscode.Range(position.line, position.character - typed.length, position.line, position.character);
      return (ja ? snippets.htmlJa : snippets.htmlEn).map(([tag, label, body], i) => {
        const item = new vscode.CompletionItem({ label: tag, description: label }, vscode.CompletionItemKind.Snippet);
        item.insertText = new vscode.SnippetString(body);
        item.filterText = tag;
        item.sortText = `~${tag} ${String(i).padStart(2, '0')}`;
        item.detail = ja ? `雛形: ${label}` : `Template: ${label}`;
        item.documentation = md(`\`\`\`html\n<${plainSnippet(body)}\n\`\`\``);
        item.range = range;
        return item;
      });
    },
  }, '<');

  // CSS の雛形(管理画面で取り込んだもの。段 14-5)。CSS のファイルと、HTML の <style> の中
  const cssItems = (position, cssBefore) => {
    const typed = cssBefore === null ? null : cssSnippetTyped(cssBefore);
    if (typed === null || snippets.css.length === 0) {
      return [];
    }
    const range = new vscode.Range(position.line, position.character - typed.length, position.line, position.character);
    return snippets.css.map(([label, body, jaText, enText], i) => {
      const doc = ja ? jaText : enText;
      const item = new vscode.CompletionItem({ label, description: shortDoc(doc) }, vscode.CompletionItemKind.Snippet);
      item.insertText = new vscode.SnippetString(body);
      item.filterText = label;
      item.sortText = `~${label} ${String(i).padStart(4, '0')}`;
      item.detail = doc;
      item.documentation = md(`${doc}\n\n\`\`\`css\n${plainSnippet(body)}\n\`\`\``);
      item.range = range;
      return item;
    });
  };
  const textBefore = (document, position) => document.getText(new vscode.Range(0, 0, position.line, position.character));
  vscode.languages.registerCompletionItemProvider(['css', 'scss', 'less'], {
    provideCompletionItems: (document, position) => cssItems(position, textBefore(document, position)),
  });
  vscode.languages.registerCompletionItemProvider('html', {
    provideCompletionItems: (document, position) => cssItems(position, htmlStyleBefore(textBefore(document, position))),
  });
  // JS の雛形
  vscode.languages.registerCompletionItemProvider(['javascript', 'typescript'], {
    provideCompletionItems(document, position) {
      const line = document.lineAt(position.line).text.slice(0, position.character);
      if (/\/\//.test(line) || /^\s*\*/.test(line) || /\.\s*[\w$]*$/.test(line)) {
        return []; // 注釈の中・「.」の後(そこは TypeScript の補完に任せる)
      }
      return snippets.js.map(([label, body, jaText, enText]) => {
        const doc = ja ? jaText : enText;
        const item = new vscode.CompletionItem({ label, description: shortDoc(doc) }, vscode.CompletionItemKind.Snippet);
        item.insertText = new vscode.SnippetString(body);
        item.detail = doc;
        item.documentation = md(`${doc}\n\n\`\`\`js\n${plainSnippet(body)}\n\`\`\``);
        return item;
      });
    },
  });
}
