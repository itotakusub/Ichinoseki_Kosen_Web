/*
 * VS Code のワークベンチを起こす(段 11-0 の試作。2026-11-08)。
 *
 * 構成は @codingame/monaco-vscode-api の作者の例(demo の setup.common・setup.workbench.classic)に倣い、
 * 使わないもの(ターミナル・デバッガ・SCM・AI・チャット・リモート・拡張機能の取得)は入れない。
 * 拡張機能は下で import したものだけ(利用者の指示「決めたものだけ同梱」)。マーケットプレイスの URL は持たない
 */
import { initialize, LogLevel } from '@codingame/monaco-vscode-api';
import getWorkbenchServiceOverride from '@codingame/monaco-vscode-workbench-service-override';
import getQuickAccessServiceOverride from '@codingame/monaco-vscode-quickaccess-service-override';
import getConfigurationServiceOverride, { initUserConfiguration } from '@codingame/monaco-vscode-configuration-service-override';
import getKeybindingsServiceOverride from '@codingame/monaco-vscode-keybindings-service-override';
import getFilesServiceOverride, { registerFileSystemOverlay } from '@codingame/monaco-vscode-files-service-override';
import getLanguagesServiceOverride from '@codingame/monaco-vscode-languages-service-override';
import getTextmateServiceOverride from '@codingame/monaco-vscode-textmate-service-override';
import getThemeServiceOverride from '@codingame/monaco-vscode-theme-service-override';
import getModelServiceOverride from '@codingame/monaco-vscode-model-service-override';
import getStorageServiceOverride from '@codingame/monaco-vscode-storage-service-override';
import getLifecycleServiceOverride from '@codingame/monaco-vscode-lifecycle-service-override';
import getExplorerServiceOverride from '@codingame/monaco-vscode-explorer-service-override';
import getSearchServiceOverride from '@codingame/monaco-vscode-search-service-override';
import getExtensionServiceOverride from '@codingame/monaco-vscode-extensions-service-override';
// 左の「拡張機能」の欄(段 11-2)。入手先(productConfiguration の gallery の URL)を渡さないので、
// 検索・入れる口は働かず、同梱したものの一覧とオン・オフだけになる
import getExtensionGalleryServiceOverride from '@codingame/monaco-vscode-extension-gallery-service-override';
import getMarkersServiceOverride from '@codingame/monaco-vscode-markers-service-override';
import getOutlineServiceOverride from '@codingame/monaco-vscode-outline-service-override';
import getOutputServiceOverride from '@codingame/monaco-vscode-output-service-override';
import getPreferencesServiceOverride from '@codingame/monaco-vscode-preferences-service-override';
import getLocalizationServiceOverride from '@codingame/monaco-vscode-localization-service-override';
import getSnippetsServiceOverride from '@codingame/monaco-vscode-snippets-service-override';
import getEmmetServiceOverride from '@codingame/monaco-vscode-emmet-service-override';
import getNotebookServiceOverride from '@codingame/monaco-vscode-notebook-service-override';
import getLogServiceOverride from '@codingame/monaco-vscode-log-service-override';
import getNotificationsServiceOverride from '@codingame/monaco-vscode-notifications-service-override';
import getDialogsServiceOverride from '@codingame/monaco-vscode-dialogs-service-override';
import getBulkEditServiceOverride from '@codingame/monaco-vscode-bulk-edit-service-override';
import getWorkingCopyServiceOverride from '@codingame/monaco-vscode-working-copy-service-override';
import getWorkspaceTrustServiceOverride from '@codingame/monaco-vscode-workspace-trust-service-override';
import { getService, INotebookService, IPaneCompositePartService } from '@codingame/monaco-vscode-api/services';
import * as vscode from 'vscode';
import 'vscode/localExtensionHost';

// 言語(VS Code 付属)
import '@codingame/monaco-vscode-html-default-extension';
import '@codingame/monaco-vscode-html-language-features-default-extension';
import '@codingame/monaco-vscode-css-default-extension';
import '@codingame/monaco-vscode-css-language-features-default-extension';
import '@codingame/monaco-vscode-json-default-extension';
import '@codingame/monaco-vscode-json-language-features-default-extension';
import '@codingame/monaco-vscode-javascript-default-extension';
import '@codingame/monaco-vscode-typescript-basics-default-extension';
import '@codingame/monaco-vscode-typescript-language-features-default-extension';
import '@codingame/monaco-vscode-emmet-default-extension';
import '@codingame/monaco-vscode-markdown-basics-default-extension';
import '@codingame/monaco-vscode-markdown-language-features-default-extension';
import '@codingame/monaco-vscode-ipynb-default-extension';
import '@codingame/monaco-vscode-notebook-renderers-default-extension';
import '@codingame/monaco-vscode-python-default-extension';
import '@codingame/monaco-vscode-php-default-extension';
import '@codingame/monaco-vscode-sql-default-extension';
import '@codingame/monaco-vscode-yaml-default-extension';
import '@codingame/monaco-vscode-xml-default-extension';
import '@codingame/monaco-vscode-shellscript-default-extension';
import '@codingame/monaco-vscode-java-default-extension';
import '@codingame/monaco-vscode-powershell-default-extension';
import '@codingame/monaco-vscode-bat-default-extension';
import '@codingame/monaco-vscode-configuration-editing-default-extension';
import '@codingame/monaco-vscode-search-result-default-extension';
// 見た目
import '@codingame/monaco-vscode-theme-defaults-default-extension';
import '@codingame/monaco-vscode-theme-seti-default-extension';
import '@codingame/monaco-vscode-theme-monokai-default-extension';
import '@codingame/monaco-vscode-theme-solarized-dark-default-extension';
import '@codingame/monaco-vscode-theme-solarized-light-default-extension';
import '@codingame/monaco-vscode-theme-abyss-default-extension';
import '@codingame/monaco-vscode-theme-quietlight-default-extension';
import '@codingame/monaco-vscode-theme-kimbie-dark-default-extension';
import '@codingame/monaco-vscode-theme-tomorrow-night-blue-default-extension';
import '@codingame/monaco-vscode-theme-red-default-extension';
// Open VSX(版を固定・ハッシュと中身を検めた。11-08)
import '../extensions/esbenp.prettier-vscode-12.4.0.vsix';
import '../extensions/usernamehw.errorlens-3.30.0.vsix';
import '../extensions/christian-kohler.path-intellisense-2.8.0.vsix';

// worker は同じオリジンのファイル(Vite の ?worker&url)
import editorWorkerUrl from 'monaco-editor/esm/vs/editor/editor.worker.js?worker&url';
import extensionHostWorkerUrl from '@codingame/monaco-vscode-api/workers/extensionHost.worker?worker&url';
import textMateWorkerUrl from '@codingame/monaco-vscode-textmate-service-override/worker?worker&url';
import outputWorkerUrl from '@codingame/monaco-vscode-output-service-override/worker?worker&url';
import notebookWorkerUrl from '@codingame/monaco-vscode-notebook-service-override/worker?worker&url';
import searchWorkerUrl from '@codingame/monaco-vscode-search-service-override/worker?worker&url';

import { onExternalChange, openStore } from '../../editor-src/src/project/db.js';
import { newProjectRecord } from '../../editor-src/src/project/model.js';
import { ProjectFileSystem, ROOT } from './projectFs.js';
import { absPath } from './paths.js';
import { registerProjectCommands } from './commands.js';
import { registerSync } from './codeSync.js';
import { registerJapaneseHelp } from './jaExtension.js';
import { registerWebChecks } from './webChecks.js';

const lang = globalThis.__kmCodeLang ?? 'ja';
const mark = (label) => {
  (globalThis.__kmCodeMarks ??= []).push([label, Math.round(performance.now() - globalThis.__kmCodeT0)]);
};
mark('workbench module loaded');

const workers = {
  editorWorkerService: editorWorkerUrl,
  extensionHostWorkerMain: extensionHostWorkerUrl,
  TextMateWorker: textMateWorkerUrl,
  OutputLinkDetectionWorker: outputWorkerUrl,
  NotebookEditorWorker: notebookWorkerUrl,
  LocalFileSearchWorker: searchWorkerUrl,
};
// worker は VS Code の既定どおり、blob の小さな起動の台本から同じオリジンのファイルを読む(翻訳も渡る)。
// **ファイルから直に起こさない**: 直に起こした worker にはサイト既定の CSP(default-src 'self')が付き、
// TextMate の WASM が止まる(11-1 で確かめた)。blob の worker はこのページの CSP('code')を受け継ぐ
globalThis.MonacoEnvironment = {
  // 完全な URL にする(11-3)。拡張機能ホストの枠は webview.<ドメイン> にあり、/code/assets/… のままだと
  // 枠のオリジンで探して 404 になる(本体の /code/assets/ は webview のオリジンに CORS を許してある)
  getWorkerUrl: (_id, label) => new URL(workers[label], location.href).href,
  getWorkerOptions: () => ({ type: 'module' }),
};

// ---- プロジェクト(/editor/ と同じ置き場)----
const { store, persistent } = await openStore();
const wanted = new URLSearchParams(location.search).get('p');
const projects = await store.listProjects();
let project = (wanted && projects.find((p) => p.id === wanted)) || projects[0] || null;
if (!project) {
  const made = newProjectRecord(lang === 'ja' ? 'はじめのプロジェクト' : 'First project', {
    'index.html': '<!doctype html>\n<html lang="ja">\n<head>\n  <meta charset="utf-8">\n  <title>はじめの一歩</title>\n  <link rel="stylesheet" href="style.css">\n</head>\n<body>\n  <h1>こんにちは</h1>\n  <script src="script.js"></script>\n</body>\n</html>\n',
    'style.css': 'h1 { color: teal; }\n',
    'script.js': "console.log('hello');\n",
  });
  project = made.project;
  await store.writeFiles(project.id, made.puts, [], project);
}
const fs = new ProjectFileSystem(store, project, (p) => vscode.Uri.file(absPath(p)));
await fs.load();
registerFileSystemOverlay(1, fs);
mark('project loaded');

await initUserConfiguration(JSON.stringify({
  'workbench.colorTheme': 'Default Dark Modern',
  'workbench.iconTheme': 'vs-seti',
  'workbench.startupEditor': 'none',
  'files.autoSave': 'afterDelay',
  'files.autoSaveDelay': 800,
  'editor.fontSize': 14,
  // 段 11-8(2026-11-09): Auto Rename Tag の代わりに VS Code の「リンクされた編集」(HTML の開きタグを直すと閉じタグも)。
  // Bracket Pair Colorization は VS Code に入っている(拡張機能の方は作者が廃止)。対の縦線も出す
  'editor.linkedEditing': true,
  'editor.bracketPairColorization.enabled': true,
  'editor.guides.bracketPairs': 'active',
  // 2026-11-13(利用者「カーソルを合わせたときの英語の説明は、設定だけで消す」): 付属の HTML・CSS の説明(MDN の英語の文とリンク)を出さない。
  // 日本語の画面だけ(自作の日本語の説明が残る)。英語の画面では、これを消すと説明が 1 つも出なくなるので残す
  ...(lang === 'ja' ? Object.fromEntries(['html', 'css', 'scss', 'less'].flatMap((id) => [[`${id}.hover.documentation`, false], [`${id}.hover.references`, false]])) : {}),
  'window.commandCenter': true,
  'security.workspace.trust.enabled': false,
  'extensions.autoCheckUpdates': false,
  'extensions.autoUpdate': false,
  'telemetry.telemetryLevel': 'off',
}));

const container = document.getElementById('km-code');
// 拡張機能ホストと webview の枠は別のオリジン(段 11-3。殻の meta。https:// の URL だけ受ける)。
// 部品は枠の HTML の URL のホストだけを差し替える(/code/assets/… のまま)
const webviewOrigin = (() => {
  const value = document.querySelector('meta[name="km-webview-origin"]')?.content ?? '';
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && url.origin !== location.origin ? url.origin : undefined;
  } catch {
    return undefined;
  }
})();
// 自作の日本語の用法は起動の前に登録する(拡張機能の欄に出し、オン・オフできるように)
const jaHelpReady = registerJapaneseHelp({ lang }).catch(() => {});
// 自作の Web の検査(段 11-8。HTMLHint・ESLint・CSS のクラス名)も同じく起動の前に
const webChecksReady = registerWebChecks({ lang }).catch((e) => console.warn('km-web-checks', e));

await initialize({
  ...getLogServiceOverride(),
  ...getExtensionServiceOverride({ enableWorkerExtensionHost: true, iframeAlternateDomain: webviewOrigin }),
  ...getExtensionGalleryServiceOverride({ webOnly: true }),
  ...getFilesServiceOverride(),
  ...getConfigurationServiceOverride(),
  ...getModelServiceOverride(),
  ...getNotificationsServiceOverride(),
  ...getDialogsServiceOverride(),
  ...getKeybindingsServiceOverride(),
  ...getTextmateServiceOverride(),
  ...getThemeServiceOverride(),
  ...getLanguagesServiceOverride(),
  ...getPreferencesServiceOverride(),
  ...getOutputServiceOverride(),
  ...getStorageServiceOverride(),
  ...getLifecycleServiceOverride(),
  ...getWorkspaceTrustServiceOverride(),
  ...getWorkingCopyServiceOverride(),
  ...getNotebookServiceOverride(),
  ...getSnippetsServiceOverride(),
  ...getEmmetServiceOverride(),
  ...getBulkEditServiceOverride(),
  ...getExplorerServiceOverride(),
  ...getSearchServiceOverride(),
  ...getMarkersServiceOverride(),
  ...getOutlineServiceOverride(),
  ...getLocalizationServiceOverride({
    async setLocale(id) {
      try {
        localStorage.setItem('km-code-lang', id === 'ja' ? 'ja' : 'en');
      } catch {
        // 保存できなくても次の行で開き直す
      }
      const url = new URL(location.href);
      url.searchParams.set('lang', id === 'ja' ? 'ja' : 'en');
      history.replaceState(null, '', url);
    },
    async clearLocale() {
      const url = new URL(location.href);
      url.searchParams.set('lang', 'en');
      history.replaceState(null, '', url);
    },
    availableLanguages: [{ locale: 'en', languageName: 'English' }, { locale: 'ja', languageName: '日本語' }],
  }),
  ...getWorkbenchServiceOverride(undefined, webviewOrigin),
  ...getQuickAccessServiceOverride({ isKeybindingConfigurationVisible: () => true, shouldUseGlobalPicker: () => true }),
}, container, {
  workspaceProvider: {
    trusted: true,
    async open() {
      return false;
    },
    // id はプロジェクトごと(既定は folderUri の hash で、どのプロジェクトも /workspace なので
    // 開いたタブ・展開した木などの画面の状態が混ざる。11-1 で見つけた)
    workspace: { folderUri: vscode.Uri.file(ROOT), id: `km-${project.id}` },
  },
  developmentOptions: { logLevel: LogLevel.Warning },
  windowIndicator: { label: project.name, tooltip: '', command: '' },
  productConfiguration: {
    nameShort: 'Web Code Editor',
    nameLong: 'Web Code Editor (Workbench)',
  },
}, { userHome: vscode.Uri.file('/') });
mark('initialized');

// ノートブックの部品は遅れて作られ、作られる前に開いた .ipynb はテキストのまま開く(11-2 で見つけた)。
// 起動の直後に一度使って、.ipynb の「Jupyter Notebook」の画面を登録させる
await getService(INotebookService).then((notebooks) => notebooks.getContributedNotebookTypes()).catch(() => {});
// サーバーとの同期(段 11-4)。殻がサインインしているかと CSRF の札を meta で渡す(/editor/ と同じ口 api/editor.php)
let commands = null;
const sync = await registerSync({
  store,
  fs,
  lang,
  csrf: document.querySelector('meta[name="km-csrf"]')?.content ?? '',
  signedIn: document.querySelector('meta[name="km-signed-in"]')?.content === '1',
  onProjectName: (name) => commands?.setProjectName(name),
});
commands = await registerProjectCommands({ store, project, lang, sync });
await jaHelpReady;
await webChecksReady;

// 拡張機能の欄は、空の検索だと「インストール済み」(利用者が入れたもの = いつも 0 個)になる。
// 同梱したものは @builtin で出るので、開いたときに検索が空なら @builtin にする(一覧とオン・オフだけ)
getService(IPaneCompositePartService).then((panes) => {
  panes.onDidPaneCompositeOpen(({ composite }) => {
    if (composite.getId() !== 'workbench.view.extensions') {
      return;
    }
    const container = composite.getViewPaneContainer?.();
    if (container?.searchBox && container.searchBox.getValue() === '') {
      container.search('@builtin ');
    }
  });
}).catch(() => {});
// ほかのタブ(/editor/ など)が同じプロジェクトを書いたら読み直す(db.js の BroadcastChannel)
onExternalChange((msg) => {
  if (msg.projectId !== project.id) {
    return;
  }
  if (msg.kind === 'files') {
    fs.reloadFromStore(msg.paths).catch(() => {});
  } else if (msg.kind === 'project') {
    store.getProject(project.id).then((record) => {
      if (record) {
        fs.project = record;
        commands.setProjectName(record.name);
        sync.queue(() => {}); // 同期の印を描き直す(/editor/ が送った・名前を変えたなど)
      }
    }).catch(() => {});
  }
});
globalThis.__kmCode = { project, persistent, fs, sync };
