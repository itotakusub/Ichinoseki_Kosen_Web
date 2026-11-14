/*
 * 自作の拡張機能「Web の検査」(段 11-8。2026-11-09)。利用者の希望: HTMLHint・ESLint・IntelliSense for CSS class names・
 * HTML CSS Support。どれもブラウザの入口が無い(Node 専用)ので、中身の部品をブラウザで動かして同じことをする。
 *
 * - HTMLHint(MIT): .html を検査する。規則は htmlhint の既定に、プロジェクト直下の .htmlhintrc.json を重ねる
 *   (.htmlhintrc は拡張子が無く、プロジェクトの名前の決まりで置けないため)
 * - ESLint の Linter(MIT): .js・.mjs・.cjs を検査する。規則は @eslint/js の推奨のもの + ブラウザの大域変数(globals)。
 *   利用者の eslint.config.js は実行しない(中は JS のプログラムで、ここで動かすと危ない)。
 *   /* eslint-disable *\/・/* global x *\/ の注釈は効く
 * - CSS のクラス名・id: プロジェクトの .css と <style> から集めて、class="…"・id="…" の中で補完する。
 *   どこにも無いクラス名には注意を出す(ほかのサイトの CSS を読んでいる HTML では出さない)
 *
 * 部品は初めて要るときに読む(ESLint は約 1MB)。検査は打つのを止めて 400ms 後。
 * ESLint の new Function を使う所は vite.config.js の km-eslint-browser が組み立てで外す(CSP に 'unsafe-eval' が無い)
 */
import { ExtensionHostKind, registerExtension } from '@codingame/monaco-vscode-api/extensions';
import { attrContext, collectCssNames, hasExternalStylesheet, htmlhintRules, sourceTypeOf, styleBlocks, unknownClasses } from './webChecksCore.js';

const EXTENSION_ID = 'ito4.km-web-checks';
const DELAY_MS = 400;
const MAX_FILES = 300;
const JS_LANGS = new Set(['javascript', 'javascriptreact']);

/** initialize の前に呼ぶ(jaExtension.js と同じ。拡張機能の欄に出してオフにできるように) */
export function registerWebChecks({ lang }) {
  const ja = lang === 'ja';
  const reg = registerExtension({
    name: 'km-web-checks',
    publisher: 'ito4',
    displayName: ja ? 'Web の検査(HTMLHint・ESLint・CSS のクラス名)' : 'Web checks (HTMLHint, ESLint, CSS class names)',
    description: ja
      ? 'HTML を HTMLHint で、JavaScript を ESLint(推奨の規則)で検査し、CSS のクラス名・id を補完します'
      : 'Checks HTML with HTMLHint and JavaScript with ESLint (recommended rules), and completes CSS class names and ids',
    version: '1.0.0',
    engines: { vscode: '*' },
    activationEvents: ['onLanguage:html', 'onLanguage:javascript', 'onLanguage:css'],
    contributes: {
      configuration: {
        title: ja ? 'Web の検査' : 'Web checks',
        properties: {
          'kmWebChecks.htmlhint.enable': { type: 'boolean', default: true, description: ja ? 'HTML を HTMLHint で検査する(規則はプロジェクト直下の .htmlhintrc.json で変えられます)' : 'Check HTML with HTMLHint (rules can be changed in .htmlhintrc.json at the project root)' },
          'kmWebChecks.eslint.enable': { type: 'boolean', default: true, description: ja ? 'JavaScript を ESLint の推奨の規則で検査する' : 'Check JavaScript with the ESLint recommended rules' },
          'kmWebChecks.cssClass.completion': { type: 'boolean', default: true, description: ja ? 'class="…"・id="…" の中で、プロジェクトの CSS にあるクラス名・id を補完する' : 'Complete class names and ids from the project CSS in class="…" and id="…"' },
          'kmWebChecks.cssClass.unknown': { type: 'boolean', default: true, description: ja ? 'どの CSS にも無いクラス名に注意を出す' : 'Warn about class names that are not in any CSS' },
        },
      },
    },
  }, ExtensionHostKind.LocalProcess, { system: true });
  return reg.getApi().then((vscode) => {
    if (!vscode.extensions.getExtension(EXTENSION_ID)) {
      return; // 拡張機能の欄でオフにされている
    }
    start(vscode, ja);
  });
}

function start(vscode, ja) {
  const conf = (key) => vscode.workspace.getConfiguration('kmWebChecks').get(key, true);
  const htmlDiag = vscode.languages.createDiagnosticCollection('htmlhint');
  const jsDiag = vscode.languages.createDiagnosticCollection('eslint');
  const classDiag = vscode.languages.createDiagnosticCollection('km-css-class');
  const decoder = new TextDecoder();
  const root = () => vscode.workspace.workspaceFolders?.[0]?.uri;

  async function readText(uri) {
    const open = vscode.workspace.textDocuments.find((d) => d.uri.toString() === uri.toString());
    if (open) {
      return open.getText();
    }
    return decoder.decode(await vscode.workspace.fs.readFile(uri));
  }

  // プロジェクトのファイルの一覧(findFiles は起動の直後に「Search provider not initialized」で落ちるので、
  // workspace.fs で自分でたどる。11-09 に Chrome で見つけた。node_modules と . で始まるフォルダは見ない)
  async function listFiles(pattern) {
    const out = [];
    const base = root();
    const walk = async (dir) => {
      let entries;
      try {
        entries = await vscode.workspace.fs.readDirectory(dir);
      } catch {
        return;
      }
      for (const [name, type] of entries) {
        if (out.length >= MAX_FILES) {
          return;
        }
        const uri = vscode.Uri.joinPath(dir, name);
        if (type & vscode.FileType.Directory) {
          if (name !== 'node_modules' && !name.startsWith('.')) {
            await walk(uri);
          }
        } else if (pattern.test(name)) {
          out.push(uri);
        }
      }
    };
    if (base) {
      await walk(base);
    }
    return out;
  }
  // 失敗したら覚えない(次にまた集める)
  const remember = (get, set, make) => get() ?? set(make().catch((e) => {
    set(null);
    throw e;
  }));

  // ---- 部品(初めて要るときに読む) ----
  let htmlhintPromise = null;
  const loadHtmlhint = () => (htmlhintPromise ??= import('htmlhint').then((m) => m.HTMLHint ?? m.default?.HTMLHint));
  let eslintPromise = null;
  const loadEslint = () => (eslintPromise ??= Promise.all([import('eslint/universal'), import('@eslint/js'), import('globals')]).then(([l, j, g]) => {
    const Linter = l.Linter ?? l.default?.Linter;
    return { linter: new Linter({ configType: 'flat', cwd: '/' }), recommended: (j.default ?? j).configs.recommended, globals: g.default ?? g };
  }));

  // ---- プロジェクトの CSS の名前(.css が変わったら集め直す) ----
  let cssNamesPromise = null;
  async function collectProjectCss() {
    const classes = new Set();
    const ids = new Set();
    const uris = await listFiles(/\.css$/i);
    for (const uri of uris) {
      try {
        const names = collectCssNames(await readText(uri));
        names.classes.forEach((n) => classes.add(n));
        names.ids.forEach((n) => ids.add(n));
      } catch {
        // 読めないファイルは飛ばす
      }
    }
    return { classes, ids, count: uris.length };
  }
  const projectCss = () => remember(() => cssNamesPromise, (v) => (cssNamesPromise = v), collectProjectCss);

  async function namesFor(document) {
    const project = await projectCss();
    const classes = new Set(project.classes);
    const ids = new Set(project.ids);
    for (const block of styleBlocks(document.getText())) {
      const names = collectCssNames(block);
      names.classes.forEach((n) => classes.add(n));
      names.ids.forEach((n) => ids.add(n));
    }
    return { classes, ids };
  }

  // ---- 別のファイルの script で決めた大域の名前(script の .js どうしで呼び合う形を no-undef にしない) ----
  let scriptGlobalsPromise = null;
  async function collectScriptGlobals() {
    const byFile = new Map();
    const uris = await listFiles(/\.js$/i);
    for (const uri of uris) {
      try {
        const text = await readText(uri);
        if (sourceTypeOf(uri.path, text) !== 'script') {
          continue;
        }
        const names = new Set();
        for (const m of text.matchAll(/^(?:async\s+)?(?:function\s*\*?\s*([A-Za-z_$][\w$]*)|class\s+([A-Za-z_$][\w$]*)|(?:var|let|const)\s+([A-Za-z_$][\w$]*))/gm)) {
          names.add(m[1] ?? m[2] ?? m[3]);
        }
        byFile.set(uri.toString(), names);
      } catch {
        // 読めないファイルは飛ばす
      }
    }
    return byFile;
  }
  const scriptGlobals = () => remember(() => scriptGlobalsPromise, (v) => (scriptGlobalsPromise = v), collectScriptGlobals);

  // ---- 検査 ----
  async function checkHtml(document) {
    const text = document.getText();
    const out = [];
    if (conf('htmlhint.enable')) {
      const HTMLHint = await loadHtmlhint();
      let rcText = null;
      const base = root();
      if (base) {
        try {
          rcText = decoder.decode(await vscode.workspace.fs.readFile(vscode.Uri.joinPath(base, '.htmlhintrc.json')));
        } catch {
          rcText = null; // 無ければ既定の規則
        }
      }
      const { rules, error } = htmlhintRules(HTMLHint.defaultRuleset, rcText);
      if (error) {
        out.push(Object.assign(new vscode.Diagnostic(new vscode.Range(0, 0, 0, 0),
          ja ? '.htmlhintrc.json を読めませんでした(JSON のオブジェクトにしてください)。既定の規則で検査しています' : 'Could not read .htmlhintrc.json (it must be a JSON object). Using the default rules.',
          vscode.DiagnosticSeverity.Warning), { source: 'HTMLHint' }));
      }
      for (const m of HTMLHint.verify(text, rules)) {
        const line = Math.max(0, m.line - 1);
        const col = Math.max(0, m.col - 1);
        const lineText = line < document.lineCount ? document.lineAt(line).text : '';
        const len = Math.max(1, Math.min(String(m.raw ?? '').length, lineText.length - col));
        const severity = m.type === 'error' ? vscode.DiagnosticSeverity.Error : m.type === 'warning' ? vscode.DiagnosticSeverity.Warning : vscode.DiagnosticSeverity.Information;
        const d = new vscode.Diagnostic(new vscode.Range(line, col, line, col + len), m.message, severity);
        d.source = 'HTMLHint';
        if (m.rule?.id) {
          d.code = m.rule.link ? { value: m.rule.id, target: vscode.Uri.parse(m.rule.link) } : m.rule.id;
        }
        out.push(d);
      }
    }
    htmlDiag.set(document.uri, out);

    const classOut = [];
    if (conf('cssClass.unknown') && !hasExternalStylesheet(text)) {
      const names = await namesFor(document);
      // CSS が 1 つも無いプロジェクトでは言わない(class を JS の目印にだけ使うこともある)
      if (names.classes.size > 0) {
        for (const u of unknownClasses(text, names.classes)) {
          const start = document.positionAt(u.offset);
          const d = new vscode.Diagnostic(new vscode.Range(start, document.positionAt(u.offset + u.name.length)),
            ja ? `クラス名「${u.name}」はどの CSS にもありません` : `The class "${u.name}" is not defined in any CSS`,
            vscode.DiagnosticSeverity.Warning);
          d.source = ja ? 'CSS のクラス名' : 'CSS class';
          d.code = 'unknown-class';
          classOut.push(d);
        }
      }
    }
    classDiag.set(document.uri, classOut);
  }

  async function checkJs(document) {
    if (!conf('eslint.enable')) {
      jsDiag.set(document.uri, []);
      return;
    }
    const { linter, recommended, globals } = await loadEslint();
    const code = document.getText();
    const sourceType = sourceTypeOf(document.uri.path, code);
    const extra = {};
    if (sourceType === 'script') {
      for (const [file, names] of await scriptGlobals()) {
        if (file !== document.uri.toString()) {
          names.forEach((n) => (extra[n] = 'readonly'));
        }
      }
    }
    const config = [{
      ...recommended,
      languageOptions: {
        ecmaVersion: 'latest',
        sourceType,
        globals: { ...globals.browser, ...(sourceType === 'commonjs' ? globals.commonjs : {}), ...extra },
      },
      rules: {
        ...recommended.rules,
        // 使っていない変数は注意に留める。script の一番外の関数は HTML の onclick などから呼ぶことがあるので見ない
        'no-unused-vars': ['warn', { vars: sourceType === 'script' ? 'local' : 'all', args: 'after-used', caughtErrors: 'none' }],
      },
    }];
    let messages;
    try {
      messages = linter.verify(code, config, { filename: document.uri.path.split('/').pop() || 'file.js' });
    } catch (e) {
      messages = [{ line: 1, column: 1, severity: 1, message: `ESLint: ${e?.message ?? e}`, ruleId: null }];
    }
    jsDiag.set(document.uri, messages.map((m) => {
      const start = new vscode.Position(Math.max(0, (m.line ?? 1) - 1), Math.max(0, (m.column ?? 1) - 1));
      const end = m.endLine ? new vscode.Position(m.endLine - 1, Math.max(0, m.endColumn - 1)) : start.translate(0, 1);
      const d = new vscode.Diagnostic(new vscode.Range(start, end.isBefore(start) ? start : end), m.message,
        m.severity === 2 ? vscode.DiagnosticSeverity.Error : vscode.DiagnosticSeverity.Warning);
      d.source = 'ESLint';
      if (m.ruleId) {
        d.code = /^[a-z-]+$/.test(m.ruleId) ? { value: m.ruleId, target: vscode.Uri.parse(`https://eslint.org/docs/latest/rules/${m.ruleId}`) } : m.ruleId;
      }
      return d;
    }));
  }

  const timers = new Map();
  function schedule(document, delay = DELAY_MS) {
    const isHtml = document.languageId === 'html';
    const isJs = JS_LANGS.has(document.languageId) && /\.(js|mjs|cjs)$/i.test(document.uri.path);
    if (!isHtml && !isJs) {
      return;
    }
    const key = document.uri.toString();
    clearTimeout(timers.get(key));
    timers.set(key, setTimeout(() => {
      timers.delete(key);
      (isHtml ? checkHtml(document) : checkJs(document)).catch((e) => console.warn('km-web-checks', e));
    }, delay));
  }
  const recheckAll = (delay) => vscode.workspace.textDocuments.forEach((d) => schedule(d, delay));

  vscode.workspace.onDidOpenTextDocument((d) => schedule(d, 0));
  vscode.workspace.onDidChangeTextDocument(({ document }) => {
    if (document.languageId === 'css') {
      cssNamesPromise = null;
      recheckAll(DELAY_MS * 2);
    } else if (JS_LANGS.has(document.languageId)) {
      scriptGlobalsPromise = null;
    }
    schedule(document);
  });
  vscode.workspace.onDidCloseTextDocument((d) => {
    clearTimeout(timers.get(d.uri.toString()));
    htmlDiag.delete(d.uri);
    jsDiag.delete(d.uri);
    classDiag.delete(d.uri);
  });
  vscode.workspace.onDidChangeConfiguration((e) => {
    if (e.affectsConfiguration('kmWebChecks')) {
      recheckAll(0);
    }
  });
  // ファイルが増えた・消えた・外から変わった(/editor/ のタブ・ZIP の読み込みなど)
  const watcher = vscode.workspace.createFileSystemWatcher('**/*.{css,js,json}');
  const onFiles = (uri) => {
    if (/\.css$/i.test(uri.path)) {
      cssNamesPromise = null;
    } else if (/\.js$/i.test(uri.path)) {
      scriptGlobalsPromise = null;
    }
    recheckAll(DELAY_MS * 2);
  };
  watcher.onDidCreate(onFiles);
  watcher.onDidChange(onFiles);
  watcher.onDidDelete(onFiles);
  recheckAll(0);

  // ---- class="…"・id="…" の補完 ----
  vscode.languages.registerCompletionItemProvider('html', {
    async provideCompletionItems(document, position) {
      if (!conf('cssClass.completion')) {
        return [];
      }
      const ctx = attrContext(document.lineAt(position.line).text.slice(0, position.character));
      if (!ctx) {
        return [];
      }
      const names = await namesFor(document);
      const range = new vscode.Range(position.line, position.character - ctx.prefix.length, position.line, position.character);
      const list = [...(ctx.kind === 'class' ? names.classes : names.ids)].sort();
      return list.map((name) => {
        const item = new vscode.CompletionItem(name, vscode.CompletionItemKind.Value);
        item.range = range;
        item.detail = ja ? (ctx.kind === 'class' ? 'CSS のクラス名' : 'CSS の id') : (ctx.kind === 'class' ? 'CSS class' : 'CSS id');
        item.sortText = `!${name}`; // HTML の補完(属性の値の候補)より先に並べる
        return item;
      });
    },
  }, '"', '\'', ' ');
}
