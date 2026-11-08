/*
 * Web Code Editor の画面(2026-11-08。https://<ドメイン>/editor/)。殻は editor/index.php(public/index.php)。
 *
 * 段 10(優先度 4。2026-11-08): 複数ファイル・複数プロジェクト・ZIP・インポート・ショートカット・配色テーマ・タブ。
 * すべてこの端末のブラウザの中で動く(利用者の指示「処理はできるだけクライアントで」):
 *   保存 … IndexedDB `km-editor`(project/db.js)。設定は localStorage `km-editor-settings-v2`
 *          段 9 の書きかけ(km-editor-v1)は初めに「はじめのプロジェクト」へ移す(project/migrate.js。古い鍵は残す)
 *   編集 … Monaco。モデルはファイルごと(URI file:///<パス>。JS の import の補完が効く)
 *   プレビュー … 別のオリジンの枠(preview/preview.js)。プロジェクトを 1 つの文書に組む(preview/projectBuilder.js)
 *   ZIP・読み込み … src/Main/html-zip.js(HTML 展開と同じ)。サーバーへは送らない
 * 文字は textContent で入れる。style 属性は書かない(幅などは CSSOM の setProperty。CSP の対象外)。
 */
import './styles/editor.css';
import { monaco, tsService } from './editor/monaco.js';
import { registerLanguageFeatures, setLanguageData } from './editor/languages.js';
import { createPreview } from './preview/preview.js';
import { buildProjectDocument } from './preview/projectBuilder.js';
import { openStore } from './project/db.js';
import { legacySettings, migrateLegacy } from './project/migrate.js';
import { prepareEntries, projectZip, readDataTransfer, readFileList, toStored } from './project/import.js';
import { createSyncClient, needsPush, remoteState, syncable } from './project/sync.js';
import {
  buildTree, byteLength, cleanFolder, cleanName, cleanPath, fileType, isHtml, isImage, movePrefix, newProjectRecord,
  overLimit, pickEntry, uniquePath,
} from './project/model.js';
import {
  clampDelay, clampSplit, createDebouncer, loadSettingsV2, MONACO_THEMES, resolveTheme, saveSettingsV2, THEMES,
} from './settings.js';
import { ACTIONS, actionForKey, assignKey, DEFAULT_KEYS, keyFromEvent, normalizeKeymap } from './keybindings.js';
import { applyI18n, detectLang, rememberLang, t } from './i18n.js';
import { buildTemplateFiles, DEFAULT_TEMPLATE, TEMPLATE_IDS, templateName, templateNote } from './templates.js';
import { $, anyDialogOpen, blobToDataUrl, downloadBlob, el, formatBytes, openDialog } from './ui/dom.js';
import { renderFileTree } from './ui/fileTree.js';
import { renderTabs } from './ui/tabs.js';

const SAVE_DELAY = 400;
const CONSOLE_MAX = 500;

const runUrl = document.querySelector('meta[name="km-editor-run"]')?.content ?? '';
const csrf = document.querySelector('meta[name="km-csrf"]')?.content ?? '';
const signedIn = document.querySelector('meta[name="km-signed-in"]')?.content === '1';
let lang = detectLang();
const tr = (key, vars) => t(lang, key, vars);

const settings = loadSettingsV2(legacySettings());
let keymap = normalizeKeymap(settings.keys);

// ---- いまのプロジェクト ----
let store = null;
let persistent = false;
let project = null; // {id, name, entry, folders, open, active, …}
/** パス → {text?: string, blob?: Blob, dataUrl?: string, size: number} */
const files = new Map();
const models = new Map();
const viewStates = new Map();
const dirty = new Set();
const deleted = new Set();
const expanded = new Set();
let previewEntry = '';
// 同期(10-B)
let lastMetaSig = '';
let changeStamp = 0;

// ---- 見た目(自動 / 明るい / 暗い / ハイコントラスト) ----
const darkQuery = window.matchMedia?.('(prefers-color-scheme: dark)');
const THEME_ICON = { light: '☀', dark: '☾', 'hc-light': '◑', 'hc-dark': '◐' };
function applyTheme() {
  const theme = resolveTheme(settings.theme, darkQuery?.matches);
  document.documentElement.dataset.theme = theme;
  monaco.editor.setTheme(MONACO_THEMES[theme]);
  $('wce-theme').textContent = `${THEME_ICON[theme]} ${t(lang, `theme.${settings.theme}`)}`;
}
darkQuery?.addEventListener?.('change', () => {
  if (settings.theme === 'auto') {
    applyTheme();
  }
});

// ---- エディタ ----
registerLanguageFeatures();
setLanguageData(lang);
// JS の import を、ほかのファイルのモデルから読めるように(補完・定義へ移動)
try {
  tsService.javascriptDefaults?.setEagerModelSync?.(true);
  tsService.javascriptDefaults?.setCompilerOptions?.({
    ...tsService.javascriptDefaults.getCompilerOptions?.(),
    allowJs: true,
    allowNonTsExtensions: true,
    target: 99,
    module: 99,
    moduleResolution: 2,
  });
} catch {
  // 版の違いで無ければ、そのファイルの中だけの補完になる
}

const narrow = window.matchMedia('(max-width: 760px)');
const editor = monaco.editor.create($('wce-monaco'), {
  model: null,
  automaticLayout: true,
  fontSize: 14,
  tabSize: 2,
  insertSpaces: true,
  minimap: { enabled: !narrow.matches },
  wordWrap: 'on',
  scrollBeyondLastLine: false,
  fixedOverflowWidgets: true,
  quickSuggestions: { other: true, comments: false, strings: true },
  suggest: { showStatusBar: true, preview: true },
  'semanticHighlighting.enabled': true,
  renderWhitespace: 'selection',
  ariaLabel: 'Code',
});
narrow.addEventListener?.('change', () => {
  editor.updateOptions({ minimap: { enabled: !narrow.matches } });
  applySidebar();
});

function uriOf(path) {
  return monaco.Uri.from({ scheme: 'file', path: `/${path}` });
}

function ensureModel(path) {
  if (models.has(path)) {
    return models.get(path);
  }
  const file = files.get(path);
  const type = fileType(path);
  if (!file || typeof file.text !== 'string' || !type?.text) {
    return null;
  }
  const model = monaco.editor.createModel(file.text, type.lang ?? 'plaintext', uriOf(path));
  model.onDidChangeContent(() => {
    dirty.add(path);
    scheduleSave();
    if (settings.autoRun) {
      previewTimer.schedule();
    }
    renderTabsUi();
  });
  models.set(path, model);
  return model;
}

function disposeModel(path) {
  models.get(path)?.dispose();
  models.delete(path);
  viewStates.delete(path);
}

function textOf(path) {
  const model = models.get(path);
  if (model) {
    return model.getValue();
  }
  return files.get(path)?.text;
}

// ---- 保存(IndexedDB) ----
const saveEl = $('wce-save');
let saveState = { state: 'saved' };
function setSaveState(state, reason) {
  saveState = { state, reason };
  saveEl.dataset.state = state;
  saveEl.textContent = state === 'failed' ? t(lang, `save.${reason ?? 'quota'}`) : t(lang, `save.${state}`);
}
let saveChain = Promise.resolve();
function saveNow() {
  saveTimer.cancel();
  saveChain = saveChain.then(writeNow, writeNow);
  return saveChain;
}
async function writeNow() {
  if (!project || !store) {
    return;
  }
  const puts = [];
  for (const path of dirty) {
    const file = files.get(path);
    if (!file) {
      continue;
    }
    if (models.has(path)) {
      file.text = models.get(path).getValue();
    }
    if (typeof file.text === 'string') {
      file.size = byteLength(file.text);
    }
    puts.push({ path, text: file.text, blob: file.blob, size: file.size });
  }
  const deletes = [...deleted];
  dirty.clear();
  deleted.clear();
  // 同期(10-B): 中身・名前・入口・フォルダが変わったら「まだ送っていない」にする(タブを替えただけでは送らない)
  const metaSig = JSON.stringify([project.name, project.entry ?? '', project.folders ?? []]);
  if (puts.length > 0 || deletes.length > 0 || metaSig !== lastMetaSig) {
    project.syncDirty = true;
    changeStamp += 1;
    lastMetaSig = metaSig;
  }
  project.updatedAt = Date.now();
  const record = structuredClone(project);
  setSaveState('saving');
  try {
    await store.writeFiles(project.id, puts, deletes, record);
    setSaveState(persistent ? 'saved' : 'memory');
    if (record.syncDirty) {
      scheduleSync();
    }
  } catch {
    for (const p of puts) {
      dirty.add(p.path);
    }
    for (const d of deletes) {
      deleted.add(d);
    }
    setSaveState('failed', 'quota');
  }
  renderTabsUi();
}
const saveTimer = createDebouncer(() => {
  saveNow();
}, () => SAVE_DELAY);
function scheduleSave() {
  setSaveState('unsaved');
  saveTimer.schedule();
}
function saveSettings() {
  settings.keys = keymap;
  saveSettingsV2(settings);
}

// ---- コンソール ----
const consoleList = $('wce-console-list');
const consoleCount = $('wce-console-count');
let consoleEntries = 0;
function renderConsoleEmpty() {
  consoleList.replaceChildren(el('li', { className: 'wce-console-empty', text: tr('console.empty') }));
}
function clearConsole() {
  consoleEntries = 0;
  consoleCount.textContent = '0';
  consoleCount.dataset.errors = '0';
  renderConsoleEmpty();
}
function addConsole({ level, text, path = '', line = 0, col = 0 }) {
  if (consoleEntries === 0) {
    consoleList.replaceChildren();
  }
  if (consoleEntries >= CONSOLE_MAX) {
    consoleList.firstElementChild?.remove();
  }
  consoleEntries += 1;
  consoleCount.textContent = String(consoleEntries);
  if (level === 'error') {
    consoleCount.dataset.errors = String(Number(consoleCount.dataset.errors || 0) + 1);
  }
  const li = el('li', { className: `wce-console-item wce-level-${level}` }, [el('span', { className: 'wce-console-text', text })]);
  if (path && files.has(path)) {
    const label = line > 0 ? `${path}:${line}` : path;
    li.append(el('button', {
      className: 'wce-console-line',
      text: label,
      attrs: { type: 'button', title: line > 0 ? t(lang, 'console.openAt', { path, line }) : path },
      on: { click: () => openAt(path, line, col) },
    }));
  }
  consoleList.append(li);
  consoleList.scrollTop = consoleList.scrollHeight;
}
$('wce-console-clear').addEventListener('click', clearConsole);
$('wce-console-toggle').addEventListener('click', () => {
  const btn = $('wce-console-toggle');
  const open = btn.getAttribute('aria-expanded') !== 'true';
  btn.setAttribute('aria-expanded', String(open));
  document.querySelector('.wce-console').classList.toggle('is-collapsed', !open);
});

function openAt(path, line, col) {
  selectFile(path, { focus: true });
  if (line > 0 && models.has(path)) {
    editor.revealLineInCenter(line);
    editor.setPosition({ lineNumber: line, column: Math.max(1, col) });
  }
}

// ---- プレビュー ----
const frame = $('wce-frame');
const entrySelect = $('wce-entry');
const preview = createPreview({
  frame,
  runUrl,
  onConsole: addConsole,
  onReady: () => frame.closest('.wce-frame-wrap')?.classList.remove('is-loading'),
  onNav: (path) => {
    if (files.has(path) && isHtml(path)) {
      previewEntry = path;
      renderEntrySelect();
      runPreview();
    }
  },
});
frame.closest('.wce-frame-wrap')?.classList.add('is-loading');

function previewFiles() {
  const map = new Map();
  for (const [path, file] of files) {
    const mime = fileType(path)?.mime ?? 'application/octet-stream';
    const text = textOf(path);
    map.set(path, typeof text === 'string' ? { text, mime } : { dataUrl: file.dataUrl, mime });
  }
  return map;
}

function currentEntry() {
  const paths = [...files.keys()];
  if (previewEntry && files.has(previewEntry) && isHtml(previewEntry)) {
    return previewEntry;
  }
  const active = project?.active && isHtml(project.active) ? project.active : '';
  previewEntry = pickEntry(paths, project?.entry || active) ?? '';
  return previewEntry;
}

function warningText(w, entry) {
  if (w.code === 'missing') {
    return t(lang, 'preview.missing', { path: w.path, from: w.from || entry });
  }
  return t(lang, 'preview.cycle', { path: w.path });
}

function runPreview() {
  clearConsole();
  const entry = currentEntry();
  renderEntrySelect();
  if (!entry) {
    preview.run(() => ({ text: `<!DOCTYPE html><html lang="${lang}"><body><p>${tr('preview.noHtml')}</p></body></html>` }));
    return;
  }
  const result = preview.run((runId) => buildProjectDocument(previewFiles(), entry, { runId, lang, mode: 'preview' }));
  if (!result.ok) {
    addConsole({ level: 'error', text: t(lang, 'preview.tooLarge') });
  }
  for (const w of result.built?.warnings ?? []) {
    addConsole({ level: 'warn', text: warningText(w, entry), path: w.from || entry });
  }
}
const previewTimer = createDebouncer(runPreview, () => settings.delay);

function renderEntrySelect() {
  const html = [...files.keys()].filter(isHtml).sort();
  entrySelect.replaceChildren(...html.map((p) => el('option', { text: p, attrs: { value: p, selected: p === previewEntry } })));
  entrySelect.disabled = html.length === 0;
}
entrySelect.addEventListener('change', () => {
  previewEntry = entrySelect.value;
  if (project) {
    project.entry = previewEntry;
    scheduleSave();
    renderTree();
  }
  runPreview();
});

function updateModeLabel() {
  const node = $('wce-preview-mode');
  if (!settings.autoRun) {
    node.textContent = t(lang, 'preview.manual');
  } else if (settings.delay === 0) {
    node.textContent = t(lang, 'preview.autoNow');
  } else {
    node.textContent = t(lang, 'preview.auto', { ms: settings.delay });
  }
}

$('wce-run').addEventListener('click', () => runAction('run'));
$('wce-reload').addEventListener('click', () => {
  frame.closest('.wce-frame-wrap')?.classList.add('is-loading');
  preview.reload();
  previewEntry = '';
  runPreview();
});

// ---- ファイルの欄 ----
const treeBox = $('wce-tree');
function renderTree() {
  if (!project) {
    treeBox.replaceChildren();
    return;
  }
  renderFileTree(treeBox, buildTree([...files.keys()], project.folders ?? []), {
    active: project.active,
    entry: currentEntryForMark(),
    expanded,
    t: tr,
    onOpen: (path) => {
      selectFile(path, { focus: true });
      if (narrow.matches) {
        setSidebar(false);
      }
    },
    onToggle: (folder) => {
      if (expanded.has(folder)) {
        expanded.delete(folder);
      } else {
        expanded.add(folder);
      }
      renderTree();
    },
    onAction: (kind, path, isFolder) => {
      if (kind === 'newFile') {
        newFileFlow(path);
      } else if (kind === 'rename') {
        renameFlow(path, isFolder);
      } else if (kind === 'delete') {
        deleteFlow(path, isFolder);
      }
    },
  });
}
function currentEntryForMark() {
  return pickEntry([...files.keys()], project?.entry) ?? '';
}
function expandTo(path) {
  const parts = path.split('/');
  for (let i = 1; i < parts.length; i++) {
    expanded.add(parts.slice(0, i).join('/'));
  }
}

const paneEditor = document.querySelector('.wce-pane-editor');
function applySidebar() {
  const open = settings.sidebarOpen;
  paneEditor.classList.toggle('is-files-open', open);
  paneEditor.style.setProperty('--wce-sidebar', `${settings.sidebar}px`);
  $('wce-files-toggle').setAttribute('aria-expanded', String(open));
  $('wce-files').hidden = !open;
}
function setSidebar(open) {
  settings.sidebarOpen = open;
  applySidebar();
  saveSettings();
}
$('wce-files-toggle').addEventListener('click', () => setSidebar(!settings.sidebarOpen));
$('wce-files-new').addEventListener('click', () => newFileFlow(''));
$('wce-files-folder').addEventListener('click', () => newFolderFlow(''));
$('wce-files-upload').addEventListener('click', () => {
  importTarget = 'current';
  $('wce-import-files').click();
});

// ---- タブ ----
const tabsBox = $('wce-tabs');
function renderTabsUi() {
  if (!project) {
    tabsBox.replaceChildren();
    return;
  }
  const dirtyNow = new Set([...dirty].filter((p) => project.open.includes(p)));
  const id = renderTabs(tabsBox, {
    open: project.open,
    active: project.active,
    dirty: dirtyNow,
    t: tr,
    onSelect: (path, focus) => selectFile(path, { focus }),
    onClose: (path) => closeTab(path),
  });
  const panel = $('wce-panel');
  if (id) {
    panel.setAttribute('aria-labelledby', id);
  } else {
    panel.removeAttribute('aria-labelledby');
  }
}

const viewer = $('wce-viewer');
const monacoBox = $('wce-monaco');
function showFile(path) {
  const file = path ? files.get(path) : null;
  const type = path ? fileType(path) : null;
  if (file && type?.text) {
    const model = ensureModel(path);
    editor.setModel(model);
    const state = viewStates.get(path);
    if (state) {
      editor.restoreViewState(state);
    }
    viewer.hidden = true;
    monacoBox.hidden = false;
    editor.layout();
    return;
  }
  editor.setModel(null);
  monacoBox.hidden = true;
  viewer.hidden = false;
  if (!file) {
    viewer.replaceChildren(el('p', { className: 'wce-viewer-note', text: tr('viewer.none') }));
    return;
  }
  const info = el('p', { className: 'wce-viewer-note', text: `${path} · ${type?.mime ?? ''} · ${formatBytes(file.size, lang)}` });
  if (isImage(path) && file.dataUrl) {
    viewer.replaceChildren(el('img', { className: 'wce-viewer-img', attrs: { src: file.dataUrl, alt: path } }), info);
  } else {
    viewer.replaceChildren(info, el('p', { className: 'wce-viewer-note', text: tr('viewer.binary') }));
  }
}

function rememberView() {
  const current = project?.active;
  if (current && models.has(current) && editor.getModel() === models.get(current)) {
    viewStates.set(current, editor.saveViewState());
  }
}

function selectFile(path, { focus = false } = {}) {
  if (!project || !files.has(path)) {
    return;
  }
  rememberView();
  if (!project.open.includes(path)) {
    project.open.push(path);
  }
  if (project.active !== path) {
    project.active = path;
    scheduleSave();
  }
  expandTo(path);
  showFile(path);
  renderTabsUi();
  renderTree();
  if (focus && models.has(path)) {
    editor.focus();
  }
}

function closeTab(path) {
  if (!project) {
    return;
  }
  const i = project.open.indexOf(path);
  if (i < 0) {
    return;
  }
  rememberView();
  project.open.splice(i, 1);
  if (project.active === path) {
    project.active = project.open[Math.min(i, project.open.length - 1)] ?? '';
    showFile(project.active);
  }
  scheduleSave();
  renderTabsUi();
  renderTree();
}

function cycleTab(step) {
  if (!project || project.open.length === 0) {
    return;
  }
  const i = project.open.indexOf(project.active);
  const next = project.open[(i + step + project.open.length) % project.open.length];
  selectFile(next, { focus: true });
}

// ---- ダイアログ ----
async function confirmDialog(text, okLabel = '') {
  $('wce-confirm-text').textContent = text;
  $('wce-confirm-ok').textContent = okLabel || tr('dialog.ok');
  return (await openDialog($('wce-confirm-dialog'))) === 'ok';
}

async function messageDialog(text) {
  $('wce-message-text').textContent = text;
  await openDialog($('wce-message-dialog'));
}

/** 文字を 1 つ尋ねる。check(value) が文字列(誤り)を返すあいだは閉じない */
function promptDialog({ title, label, value = '', okLabel = '', check = () => null }) {
  const dialog = $('wce-prompt-dialog');
  const input = $('wce-prompt-input');
  const error = $('wce-prompt-error');
  $('wce-prompt-title').textContent = title;
  $('wce-prompt-label').textContent = label;
  $('wce-prompt-ok').textContent = okLabel || tr('dialog.ok');
  input.value = value;
  error.textContent = '';
  return new Promise((resolve) => {
    const onSubmit = (event) => {
      if (event.submitter?.value !== 'ok') {
        return;
      }
      const message = check(input.value);
      if (message) {
        event.preventDefault();
        error.textContent = message;
        input.focus();
      }
    };
    const form = $('wce-prompt-form');
    form.addEventListener('submit', onSubmit);
    dialog.addEventListener('close', () => {
      form.removeEventListener('submit', onSubmit);
      resolve(dialog.returnValue === 'ok' ? input.value : null);
    }, { once: true });
    dialog.returnValue = '';
    dialog.showModal();
    input.focus();
    // 拡張子の前までを選ぶ(名前だけ打ち直しやすく)
    const dot = value.lastIndexOf('.');
    const slash = value.lastIndexOf('/');
    input.setSelectionRange(slash + 1, dot > slash ? dot : value.length);
  });
}

/** 選択肢から 1 つ選ぶ。choices: [{value, label, primary?}]。やめたら null */
function chooseDialog(text, choices) {
  const dialog = $('wce-choose-dialog');
  $('wce-choose-text').textContent = text;
  const box = $('wce-choose-actions');
  box.replaceChildren(
    el('button', { className: 'wce-btn', text: tr('dialog.cancel'), attrs: { type: 'submit', value: 'cancel' } }),
    ...choices.map((c) => el('button', { className: `wce-btn${c.primary ? ' wce-btn-primary' : ''}`, text: c.label, attrs: { type: 'submit', value: c.value } })),
  );
  return openDialog(dialog).then((v) => (choices.some((c) => c.value === v) ? v : null));
}

function pathError(error) {
  return t(lang, `path.${error?.code ?? 'invalid'}`);
}

function totals(extraFiles = 0, extraBytes = 0) {
  let bytes = extraBytes;
  for (const f of files.values()) {
    bytes += f.size ?? 0;
  }
  return overLimit(files.size + extraFiles, bytes);
}

// ---- ファイルの操作 ----
function starterText(path) {
  if (isHtml(path)) {
    const title = path.split('/').pop().replace(/\.[^.]+$/, '');
    return `<!DOCTYPE html>\n<html lang="${lang}">\n<head>\n  <meta charset="utf-8">\n  <meta name="viewport" content="width=device-width, initial-scale=1">\n  <title>${title}</title>\n</head>\n<body>\n\n</body>\n</html>\n`;
  }
  return '';
}

async function newFileFlow(folder) {
  if (!project) {
    return;
  }
  const value = await promptDialog({
    title: tr('files.newFile'),
    label: tr('files.pathLabel'),
    value: folder ? `${folder}/` : '',
    okLabel: tr('files.create'),
    check: (v) => {
      try {
        const path = cleanPath(v);
        if (files.has(path)) {
          return tr('path.exists');
        }
        if (!fileType(path).text) {
          return tr('path.notText');
        }
        if (totals(1, 0)) {
          return tr('limit.files');
        }
      } catch (error) {
        return pathError(error);
      }
      return null;
    },
  });
  if (value === null) {
    return;
  }
  const path = cleanPath(value);
  const text = starterText(path);
  files.set(path, { text, size: byteLength(text) });
  dirty.add(path);
  dropFolderMarks(path);
  selectFile(path, { focus: true });
  await saveNow();
  if (settings.autoRun) {
    runPreview();
  }
}

/** ファイルを作ったフォルダは、空のフォルダの印から外す(中身があれば木に出る) */
function dropFolderMarks(path) {
  if (!project.folders?.length) {
    return;
  }
  project.folders = project.folders.filter((f) => !path.startsWith(`${f}/`));
}

async function newFolderFlow(parent) {
  if (!project) {
    return;
  }
  const value = await promptDialog({
    title: tr('files.newFolder'),
    label: tr('files.folderLabel'),
    value: parent ? `${parent}/` : '',
    okLabel: tr('files.create'),
    check: (v) => {
      try {
        const folder = cleanFolder(v);
        if (files.has(folder) || folderExists(folder)) {
          return tr('path.exists');
        }
      } catch (error) {
        return pathError(error);
      }
      return null;
    },
  });
  if (value === null) {
    return;
  }
  const folder = cleanFolder(value);
  project.folders = [...(project.folders ?? []), folder];
  expandTo(`${folder}/x`);
  renderTree();
  await saveNow();
}

function folderExists(folder) {
  return (project.folders ?? []).includes(folder) || [...files.keys()].some((p) => p.startsWith(`${folder}/`));
}

async function renameFlow(path, isFolder) {
  if (!project) {
    return;
  }
  const value = await promptDialog({
    title: tr(isFolder ? 'files.renameFolder' : 'files.rename'),
    label: tr(isFolder ? 'files.folderLabel' : 'files.pathLabel'),
    value: path,
    okLabel: tr('files.renameOk'),
    check: (v) => {
      try {
        const next = isFolder ? cleanFolder(v) : cleanPath(v);
        if (next === path) {
          return null;
        }
        if (isFolder) {
          if (next.startsWith(`${path}/`)) {
            return tr('path.inside');
          }
          if (files.has(next) || folderExists(next)) {
            return tr('path.exists');
          }
        } else {
          if (files.has(next) || folderExists(next)) {
            return tr('path.exists');
          }
          if (fileType(next).text !== fileType(path).text) {
            return tr('path.kind');
          }
        }
      } catch (error) {
        return pathError(error);
      }
      return null;
    },
  });
  if (value === null) {
    return;
  }
  const next = isFolder ? cleanFolder(value) : cleanPath(value);
  if (next === path) {
    return;
  }
  rememberView();
  const moves = isFolder ? [...files.keys()].filter((p) => p.startsWith(`${path}/`)).map((p) => [p, movePrefix(p, path, next)]) : [[path, next]];
  for (const [from, to] of moves) {
    moveFile(from, to);
  }
  if (isFolder) {
    project.folders = (project.folders ?? []).map((f) => movePrefix(f, path, next));
    for (const f of [...expanded]) {
      if (f === path || f.startsWith(`${path}/`)) {
        expanded.delete(f);
        expanded.add(movePrefix(f, path, next));
      }
    }
  }
  if (project.active) {
    expandTo(project.active);
    showFile(project.active);
  }
  renderTabsUi();
  renderTree();
  await saveNow();
  runPreview();
}

function moveFile(from, to) {
  const file = files.get(from);
  if (!file) {
    return;
  }
  const model = models.get(from);
  if (model) {
    file.text = model.getValue();
  }
  const state = viewStates.get(from);
  disposeModel(from);
  files.delete(from);
  files.set(to, file);
  if (state) {
    viewStates.set(to, state);
  }
  dirty.delete(from);
  deleted.add(from);
  deleted.delete(to);
  dirty.add(to);
  project.open = project.open.map((p) => (p === from ? to : p));
  if (project.active === from) {
    project.active = to;
  }
  if (project.entry === from) {
    project.entry = to;
  }
  if (previewEntry === from) {
    previewEntry = to;
  }
}

async function deleteFlow(path, isFolder) {
  if (!project) {
    return;
  }
  const targets = isFolder ? [...files.keys()].filter((p) => p.startsWith(`${path}/`)) : [path];
  const text = isFolder ? t(lang, 'confirm.deleteFolder', { name: path, n: targets.length }) : t(lang, 'confirm.deleteFile', { name: path });
  if (!(await confirmDialog(text, tr('files.delete')))) {
    return;
  }
  rememberView();
  for (const p of targets) {
    disposeModel(p);
    files.delete(p);
    dirty.delete(p);
    deleted.add(p);
  }
  if (isFolder) {
    project.folders = (project.folders ?? []).filter((f) => f !== path && !f.startsWith(`${path}/`));
  }
  const gone = new Set(targets);
  const wasActive = gone.has(project.active);
  project.open = project.open.filter((p) => !gone.has(p));
  if (wasActive) {
    project.active = project.open[0] ?? '';
    showFile(project.active);
  }
  if (gone.has(project.entry)) {
    project.entry = '';
  }
  if (gone.has(previewEntry)) {
    previewEntry = '';
  }
  renderTabsUi();
  renderTree();
  await saveNow();
  runPreview();
}

// ---- プロジェクト ----
function disposeAll() {
  for (const path of [...models.keys()]) {
    disposeModel(path);
  }
  files.clear();
  dirty.clear();
  deleted.clear();
  expanded.clear();
  viewStates.clear();
}

async function openProject(id) {
  if (project) {
    rememberView();
    await saveNow();
  }
  const record = await store.getProject(id);
  if (!record) {
    return false;
  }
  const list = await store.getFiles(id);
  disposeAll();
  for (const f of list) {
    files.set(f.path, { text: typeof f.text === 'string' ? f.text : undefined, blob: f.blob, size: f.size ?? 0 });
  }
  await Promise.all([...files.values()].filter((f) => f.blob).map(async (f) => {
    try {
      f.dataUrl = await blobToDataUrl(f.blob);
    } catch {
      f.dataUrl = '';
    }
  }));
  project = {
    folders: [],
    ...record,
    open: (record.open ?? []).filter((p) => files.has(p)),
  };
  if (!files.has(project.active)) {
    project.active = project.open[0] ?? '';
  }
  if (!project.active) {
    project.active = pickEntry([...files.keys()], project.entry) ?? [...files.keys()][0] ?? '';
    if (project.active) {
      project.open = [project.active];
    }
  }
  previewEntry = '';
  lastMetaSig = JSON.stringify([project.name, project.entry ?? '', project.folders ?? []]);
  settings.lastProject = id;
  saveSettings();
  updateSyncChip();
  if (project.active) {
    expandTo(project.active);
  }
  $('wce-project').textContent = project.name;
  showFile(project.active);
  renderTabsUi();
  renderTree();
  setSaveState(persistent ? 'saved' : 'memory');
  runPreview();
  return true;
}

async function createProject(name, texts) {
  const { project: record, puts } = newProjectRecord(name, texts, { fallbackName: tr('project.untitled') });
  await store.writeFiles(record.id, puts, [], record);
  return record.id;
}

async function createFromTemplate(id) {
  const name = await promptDialog({
    title: tr('project.new'),
    label: tr('project.nameLabel'),
    value: templateName(id, lang),
    okLabel: tr('files.create'),
    check: (v) => (v.trim() === '' ? tr('project.nameEmpty') : null),
  });
  if (name === null) {
    return;
  }
  const newId = await createProject(name, buildTemplateFiles(id, lang));
  await openProject(newId);
}

// プロジェクトの一覧
const projectsDialog = $('wce-projects-dialog');
async function renderProjects() {
  const list = $('wce-projects-list');
  const all = await store.listProjects();
  const dateFmt = new Intl.DateTimeFormat(lang === 'en' ? 'en' : 'ja', { dateStyle: 'medium', timeStyle: 'short' });
  const act = (label, fn, danger = false) => el('button', {
    className: `wce-btn wce-btn-small${danger ? ' wce-btn-ghost-danger' : ''}`,
    text: label,
    attrs: { type: 'button' },
    on: { click: fn },
  });
  // サインインしていれば、使っている量と、サーバーにだけあるもの
  const usage = $('wce-projects-usage');
  usage.hidden = !canSync;
  if (canSync && remoteUsage.limits) {
    usage.textContent = t(lang, 'project.usage', {
      used: formatBytes(remoteUsage.used, lang), max: formatBytes(remoteUsage.limits.ownerBytes, lang), n: remoteById.size, maxN: remoteUsage.limits.projects,
    });
  }
  $('wce-projects-help').textContent = tr(canSync ? 'project.helpSync' : 'project.help');
  const linked = new Set(all.filter((p) => p.server && p.server.account === account).map((p) => p.server.id));
  const serverOnly = canSync ? [...remoteById.values()].filter((r) => !linked.has(r.id)) : [];
  list.replaceChildren(...all.map((p) => {
    const current = p.id === project?.id;
    let state = remoteState(p, canSync ? account : '', remoteById);
    if (conflicts.has(p.id)) {
      state = 'conflict';
    }
    const badge = el('span', { className: `wce-badge wce-badge-${state}`, text: t(lang, `badge.${state}`), attrs: { title: syncErrors.get(p.id) ?? '' } });
    const extra = [];
    if (state === 'conflict') {
      extra.push(act(tr('conflict.choose'), () => {
        projectsDialog.close();
        resolveConflict(p.id);
      }));
    } else if (state === 'gone' && canSync) {
      extra.push(act(tr('project.resend'), () => queueSync(async () => {
        await updateRecord(p.id, (r) => {
          r.syncOff = false;
          r.syncDirty = true;
        });
        await pushProject(p.id);
        await renderProjects();
      })));
    }
    return el('li', { className: `wce-project-row${current ? ' is-current' : ''}` }, [
      el('button', {
        className: 'wce-project-open',
        attrs: { type: 'button', 'aria-current': current ? 'true' : false },
        on: {
          click: async () => {
            projectsDialog.close();
            if (!current) {
              await openProject(p.id);
            }
          },
        },
      }, [
        el('strong', { text: p.name }),
        el('span', { text: `${current ? `${tr('project.current')} · ` : ''}${dateFmt.format(new Date(p.updatedAt ?? 0))}` }),
      ]),
      badge,
      el('span', { className: 'wce-project-acts' }, [
        ...extra,
        act(tr('project.rename'), () => renameProject(p)),
        act(tr('project.duplicate'), () => duplicateProject(p)),
        act(tr('project.delete'), () => deleteProject(p), true),
      ]),
    ]);
  }));
  for (const r of serverOnly) {
    list.append(el('li', { className: 'wce-project-row is-server-only' }, [
      el('span', { className: 'wce-project-open' }, [
        el('strong', { text: r.name }),
        el('span', { text: dateFmt.format(new Date(String(r.updatedAt).replace(' ', 'T'))) }),
      ]),
      el('span', { className: 'wce-badge wce-badge-server', text: tr('badge.server') }),
      el('span', { className: 'wce-project-acts' }, [
        act(tr('project.download'), () => queueSync(async () => {
          projectsDialog.close();
          await openProject(await pullNew(r.id));
        })),
        act(tr('project.delete'), async () => {
          projectsDialog.close();
          if (await confirmDialog(t(lang, 'confirm.deleteServerOnly', { name: r.name }), tr('project.delete'))) {
            await queueSync(async () => {
              await syncClient.remove(r.id);
              remoteById.delete(r.id);
            });
          }
          await openProjects();
        }, true),
      ]),
    ]));
  }
  if (all.length === 0 && serverOnly.length === 0) {
    list.append(el('li', { className: 'wce-help', text: tr('project.none') }));
  }
}

async function openProjects() {
  if (!store) {
    return;
  }
  await saveNow();
  await renderProjects();
  if (!projectsDialog.open) {
    projectsDialog.showModal();
  }
  // サーバーの一覧を読み直して描き直す(待たずに開く)
  if (signedIn && Date.now() - lastRefresh > 5000) {
    queueSync(refreshRemote).then(() => projectsDialog.open && renderProjects());
  }
}

async function renameProject(p) {
  projectsDialog.close();
  const name = await promptDialog({
    title: tr('project.rename'),
    label: tr('project.nameLabel'),
    value: p.name,
    okLabel: tr('files.renameOk'),
    check: (v) => (v.trim() === '' ? tr('project.nameEmpty') : null),
  });
  if (name !== null) {
    if (p.id === project?.id) {
      project.name = cleanName(name);
      $('wce-project').textContent = project.name;
      await saveNow();
    } else {
      await store.putProject({ ...p, name: cleanName(name), updatedAt: Date.now(), syncDirty: true });
      if (canSync) {
        queueSync(() => pushProject(p.id));
      }
    }
  }
  await openProjects();
}

async function duplicateProject(p) {
  await saveNow();
  const list = await store.getFiles(p.id);
  const { project: record } = newProjectRecord(t(lang, 'project.copyName', { name: p.name }), {});
  Object.assign(record, { entry: p.entry, folders: [...(p.folders ?? [])], open: [...(p.open ?? [])], active: p.active });
  await store.writeFiles(record.id, list.map((f) => ({ path: f.path, text: f.text, blob: f.blob, size: f.size })), [], record);
  if (canSync) {
    queueSync(() => pushProject(record.id));
  }
  await renderProjects();
}

async function deleteProject(p) {
  projectsDialog.close();
  const onServer = canSync && p.server && syncable(p, account);
  if (await confirmDialog(t(lang, onServer ? 'confirm.deleteProjectServer' : 'confirm.deleteProject', { name: p.name }), tr('project.delete'))) {
    if (onServer) {
      // サーバーのものを先に消す(消せなければ、この端末のものも残す)
      try {
        await queueSync(async () => {
          await syncClient.remove(p.server.id);
          remoteById.delete(p.server.id);
        });
      } catch {
        // queueSync が拾う
      }
      if (remoteById.has(p.server.id)) {
        await messageDialog(tr('sync.deleteFailed'));
        await openProjects();
        return;
      }
    }
    conflicts.delete(p.id);
    syncErrors.delete(p.id);
    const wasCurrent = p.id === project?.id;
    if (wasCurrent) {
      saveTimer.cancel();
      dirty.clear();
      deleted.clear();
    }
    await store.deleteProject(p.id);
    if (wasCurrent) {
      project = null;
      const rest = await store.listProjects();
      const next = rest[0]?.id ?? await createProject(templateName(DEFAULT_TEMPLATE, lang), buildTemplateFiles(DEFAULT_TEMPLATE, lang));
      await openProject(next);
    }
  }
  await openProjects();
}

$('wce-project').addEventListener('click', () => openProjects());
$('wce-projects-new').addEventListener('click', () => {
  projectsDialog.close();
  openTemplates();
});
$('wce-projects-import').addEventListener('click', () => {
  projectsDialog.close();
  openImport('new');
});

// テンプレート(新しいプロジェクト)
const templateDialog = $('wce-template-dialog');
function openTemplates() {
  const list = $('wce-template-list');
  list.replaceChildren(...TEMPLATE_IDS.map((id) => el('li', {}, [
    el('button', {
      className: 'wce-template-btn',
      attrs: { type: 'button' },
      on: {
        click: () => {
          templateDialog.close();
          createFromTemplate(id);
        },
      },
    }, [el('strong', { text: templateName(id, lang) }), el('span', { text: templateNote(id, lang) })]),
  ])));
  templateDialog.showModal();
}
$('wce-template').addEventListener('click', openTemplates);

// ---- 読み込み(ZIP・フォルダ・ファイル・ドラッグ) ----
let importTarget = 'new';
let importName = '';
const importDialog = $('wce-import-dialog');
function openImport(target) {
  importTarget = target;
  for (const radio of document.querySelectorAll('input[name="wce-import-target"]')) {
    radio.checked = radio.value === target;
  }
  importDialog.showModal();
}
for (const radio of document.querySelectorAll('input[name="wce-import-target"]')) {
  radio.addEventListener('change', () => {
    if (radio.checked) {
      importTarget = radio.value;
    }
  });
}
$('wce-import').addEventListener('click', () => openImport('new'));
$('wce-import-pick').addEventListener('click', () => $('wce-import-files').click());
$('wce-import-pick-folder').addEventListener('click', () => $('wce-import-folder').click());

async function askPassword(wrong) {
  const dialog = $('wce-password-dialog');
  const input = $('wce-password-input');
  $('wce-password-error').textContent = wrong ? tr('import.wrongPassword') : '';
  input.value = '';
  const result = await openDialog(dialog);
  const value = input.value;
  input.value = '';
  return result === 'ok' ? value : null;
}

function nameFromFiles(fileList) {
  const list = [...fileList];
  if (list.length === 1 && /\.zip$/i.test(list[0].name)) {
    return list[0].name.replace(/\.zip$/i, '');
  }
  const rel = list[0]?.webkitRelativePath ?? '';
  return rel.includes('/') ? rel.split('/')[0] : tr('import.defaultName');
}

async function handlePicked(input) {
  const picked = [...(input.files ?? [])];
  input.value = '';
  if (picked.length === 0) {
    return;
  }
  importDialog.close();
  await runImport(() => readFileList(picked, askPassword), nameFromFiles(picked));
}
$('wce-import-files').addEventListener('change', (e) => handlePicked(e.target));
$('wce-import-folder').addEventListener('change', (e) => handlePicked(e.target));

async function runImport(read, name) {
  let raw;
  try {
    raw = await read();
  } catch (error) {
    if (!error?.cancelled) {
      await messageDialog(error?.userFacing ? `${tr('import.failed')}\n${error.message}` : tr('import.failed'));
    }
    return;
  }
  const prep = prepareEntries(raw);
  if (prep.files.length === 0) {
    await messageDialog(tr('import.none'));
    return;
  }
  if (importTarget === 'new' || !project) {
    if (prep.limit) {
      await messageDialog(tr(`limit.${prep.limit}`));
      return;
    }
    const stored = prep.files.map((f) => toStored(f.path, f.data));
    const { project: record } = newProjectRecord(name, {}, { fallbackName: tr('import.defaultName') });
    const paths = stored.map((s) => s.path);
    record.entry = pickEntry(paths) ?? '';
    const first = record.entry || paths[0] || '';
    record.open = first ? [first] : [];
    record.active = first;
    await store.writeFiles(record.id, stored, [], record);
    await openProject(record.id);
  } else {
    await mergeIntoCurrent(prep.files);
  }
  if (prep.rejected.length > 0) {
    addConsole({ level: 'warn', text: t(lang, 'import.rejected', { n: prep.rejected.length, list: prep.rejected.slice(0, 5).map((r) => r.path).join(', ') }) });
  }
}

async function mergeIntoCurrent(incoming) {
  const conflicts = incoming.filter((f) => files.has(f.path));
  let mode = 'overwrite';
  if (conflicts.length > 0) {
    mode = await chooseDialog(t(lang, 'import.conflict', { n: conflicts.length, list: conflicts.slice(0, 5).map((f) => f.path).join(', ') }), [
      { value: 'rename', label: tr('import.keepBoth') },
      { value: 'overwrite', label: tr('import.overwrite'), primary: true },
    ]);
    if (!mode) {
      return;
    }
  }
  const existing = new Set(files.keys());
  const plan = incoming.map((f) => {
    const path = mode === 'rename' && existing.has(f.path) ? uniquePath(f.path, existing) : f.path;
    existing.add(path);
    return { path, data: f.data };
  });
  const newCount = plan.filter((p) => !files.has(p.path)).length;
  const newBytes = plan.reduce((n, p) => n + p.data.length - (files.get(p.path)?.size ?? 0), 0);
  const limit = totals(newCount, newBytes);
  if (limit) {
    await messageDialog(tr(`limit.${limit}`));
    return;
  }
  for (const p of plan) {
    const stored = toStored(p.path, p.data);
    const entry = { text: stored.text, blob: stored.blob, size: stored.size };
    if (entry.blob) {
      try {
        entry.dataUrl = await blobToDataUrl(entry.blob);
      } catch {
        entry.dataUrl = '';
      }
    }
    const model = models.get(p.path);
    files.set(p.path, entry);
    if (model && typeof entry.text === 'string') {
      // 取り消せるように置き換える
      model.pushEditOperations([], [{ range: model.getFullModelRange(), text: entry.text }], () => null);
      model.pushStackElement();
    } else if (model) {
      disposeModel(p.path);
    }
    dirty.add(p.path);
    deleted.delete(p.path);
    dropFolderMarks(p.path);
  }
  const first = plan.find((p) => isHtml(p.path))?.path ?? plan[0].path;
  selectFile(first);
  if (project.active === first) {
    showFile(first);
  }
  renderTree();
  await saveNow();
  runPreview();
}

// ドラッグアンドドロップ: ファイルの欄は「いまのプロジェクトへ」、読み込みの窓は選んだほうへ
function dropZone(node, target) {
  node.addEventListener('dragover', (event) => {
    if ([...(event.dataTransfer?.types ?? [])].includes('Files')) {
      event.preventDefault();
      node.classList.add('is-drop');
    }
  });
  node.addEventListener('dragleave', () => node.classList.remove('is-drop'));
  node.addEventListener('drop', async (event) => {
    node.classList.remove('is-drop');
    if (!event.dataTransfer?.files?.length) {
      return;
    }
    event.preventDefault();
    const dt = event.dataTransfer;
    const first = dt.files[0];
    const name = dt.files.length === 1 ? first.name.replace(/\.zip$/i, '') : tr('import.defaultName');
    // DataTransfer はこのイベントの間しか読めないので、先に中を取る
    const reading = readDataTransfer(dt, askPassword);
    importTarget = target();
    importDialog.close();
    await runImport(() => reading, name);
  });
}
dropZone($('wce-files'), () => 'current');
dropZone($('wce-import-drop'), () => importTarget);

// ---- ダウンロード(ZIP・1 つの HTML) ----
function safeFileName(name) {
  const n = String(name).replace(/[\\/:*?"<>|\u0000-\u001f]/g, '_').trim();
  return n === '' ? 'project' : n.slice(0, 80);
}
$('wce-download').addEventListener('click', () => $('wce-download-dialog').showModal());
$('wce-download-zip').addEventListener('click', async () => {
  $('wce-download-dialog').close();
  await saveNow();
  const list = [...files].map(([path, f]) => ({ path, text: textOf(path), blob: f.blob }));
  downloadBlob(`${safeFileName(project.name)}.zip`, await projectZip(list));
});
$('wce-download-single').addEventListener('click', async () => {
  $('wce-download-dialog').close();
  const entry = currentEntry();
  if (!entry) {
    await messageDialog(tr('preview.noHtml'));
    return;
  }
  const built = buildProjectDocument(previewFiles(), entry, { lang, mode: 'export' });
  downloadBlob(`${safeFileName(project.name)}.html`, new Blob([built.text], { type: 'text/html;charset=utf-8' }));
});

// ---- サーバーとの同期(段 10-B。サインインした人だけ。project/sync.js・api/editor.php・規約第8条の3) ----
const SYNC_DELAY = 3000;
const REFRESH_EVERY = 30000;
const syncClient = createSyncClient({ csrf });
let account = '';
let canSync = false;
let blockedMessage = '';
let remoteById = new Map();
let remoteUsage = { used: 0, limits: null };
let syncProblem = ''; // 'offline' | 'failed' | ''
let syncProblemText = '';
let sending = false;
let lastRefresh = 0;
const conflicts = new Set(); // この端末のプロジェクトの id
const syncErrors = new Map(); // id → 送れなかった理由(上限など)
let syncChain = Promise.resolve();

function queueSync(task) {
  syncChain = syncChain.then(task).catch((error) => {
    syncProblem = error?.status === 0 ? 'offline' : 'failed';
    syncProblemText = error?.message ?? '';
  }).finally(updateSyncChip);
  return syncChain;
}

const syncTimer = createDebouncer(() => {
  if (project && canSync) {
    queueSync(() => pushProject(project.id));
  }
}, () => SYNC_DELAY);
function scheduleSync() {
  if (canSync) {
    syncTimer.schedule();
    updateSyncChip();
  }
}

function updateSyncChip() {
  const chip = $('wce-sync');
  const signIn = $('wce-signin');
  if (!signIn || !chip) {
    return;
  }
  signIn.hidden = signedIn;
  chip.hidden = !signedIn;
  if (!signedIn) {
    return;
  }
  let state = 'synced';
  if (blockedMessage) {
    state = 'blocked';
  } else if (!account) {
    state = syncProblem || 'idle';
  } else if (project && conflicts.has(project.id)) {
    state = 'conflict';
  } else if (sending) {
    state = 'sending';
  } else if (project && syncErrors.has(project.id)) {
    state = 'failed';
  } else if (syncProblem) {
    state = syncProblem;
  } else if (project && !syncable(project, account)) {
    state = project.syncOff === 'gone' ? 'gone' : 'off';
  } else if (project && (needsPush(project, account) || syncTimer.pending())) {
    state = 'pending';
  }
  chip.dataset.state = state;
  chip.textContent = t(lang, `sync.${state}`);
  chip.title = state === 'blocked' ? blockedMessage : (state === 'failed' ? (syncErrors.get(project?.id) ?? syncProblemText) : t(lang, 'sync.title'));
}
$('wce-sync').addEventListener('click', () => {
  if (project && conflicts.has(project.id)) {
    resolveConflict(project.id);
  } else {
    openProjects();
  }
});

/** 記録を書き換える(開いているものはメモリの方も) */
async function updateRecord(id, fn) {
  if (project?.id === id) {
    fn(project);
    saveChain = saveChain.then(() => store.putProject(structuredClone(project)));
    await saveChain;
    return;
  }
  const record = await store.getProject(id);
  if (record) {
    fn(record);
    await store.putProject(record);
  }
}

async function pushProject(id, { force = false } = {}) {
  if (!canSync) {
    return;
  }
  const isCurrent = project?.id === id;
  if (isCurrent) {
    await saveNow();
  }
  const record = isCurrent ? project : await store.getProject(id);
  if (!record || !syncable(record, account) || (!force && !needsPush(record, account)) || (conflicts.has(id) && !force)) {
    return;
  }
  const stamp = changeStamp;
  const list = isCurrent
    ? [...files].map(([path, f]) => ({ path, text: textOf(path), blob: f.blob }))
    : (await store.getFiles(id)).map((f) => ({ path: f.path, text: f.text, blob: f.blob }));
  const meta = { name: record.name, entry: record.entry ?? '', folders: record.folders ?? [] };
  sending = true;
  updateSyncChip();
  try {
    const remoteRevision = force && record.server ? (remoteById.get(record.server.id)?.revision ?? null) : null;
    const result = await syncClient.push(list, meta, record.server, account, { force, remoteRevision });
    if (!result.ok) {
      conflicts.add(id);
      // 「この端末のもので上書き」は、このとき知ったサーバーの版を土台に送る(覚えていないと古い版で送って、またぶつかる)
      if (record.server && Number.isInteger(result.revision)) {
        remoteById.set(record.server.id, { ...(remoteById.get(record.server.id) ?? { id: record.server.id, name: record.name }), revision: result.revision });
      }
      return;
    }
    conflicts.delete(id);
    syncErrors.delete(id);
    syncProblem = '';
    remoteById.set(result.server.id, { id: result.server.id, name: meta.name, revision: result.server.revision, updatedAt: new Date().toISOString() });
    await updateRecord(id, (r) => {
      r.server = result.server;
      r.syncOff = false;
      // 送っている間にまた打っていたら、まだ送っていないまま(次の回で送る)
      if (!(isCurrent && project?.id === id && changeStamp !== stamp)) {
        r.syncDirty = false;
      }
    });
    if (isCurrent && project?.syncDirty) {
      scheduleSync();
    }
  } catch (error) {
    if (error?.status === 400 || error?.status === 413) {
      syncErrors.set(id, error.message);
    } else {
      throw error;
    }
  } finally {
    sending = false;
  }
}

/** サーバーのものを、この端末のプロジェクト id に読み込む(この端末の中身は置き換わる) */
async function pullInto(id, serverId) {
  const pulled = await syncClient.pull(serverId, account);
  const isCurrent = project?.id === id;
  if (isCurrent) {
    saveTimer.cancel();
    dirty.clear();
    deleted.clear();
  }
  const before = (await store.getFiles(id)).map((f) => f.path);
  const keep = new Set(pulled.files.map((f) => f.path));
  const record = (isCurrent ? structuredClone(project) : await store.getProject(id)) ?? newProjectRecord(pulled.server.name, {}).project;
  Object.assign(record, {
    id,
    name: pulled.server.name,
    entry: pulled.server.entry,
    folders: pulled.server.folders,
    server: pulled.server,
    syncDirty: false,
    syncOff: false,
    updatedAt: Date.now(),
  });
  record.open = (record.open ?? []).filter((p) => keep.has(p));
  await store.writeFiles(id, pulled.files, before.filter((p) => !keep.has(p)), record);
  conflicts.delete(id);
  remoteById.set(pulled.server.id, { ...(remoteById.get(pulled.server.id) ?? {}), revision: pulled.server.revision });
  if (isCurrent) {
    project = null;
    await openProject(id);
  }
}

/** サーバーにだけあるものを、この端末の新しいプロジェクトとして読み込む */
async function pullNew(serverId) {
  const { project: record } = newProjectRecord(remoteById.get(serverId)?.name ?? tr('project.untitled'), {});
  await store.putProject(record);
  await pullInto(record.id, serverId);
  return record.id;
}

/** サーバーの一覧と突き合わせる(開いたとき・戻ってきたとき・一覧を開いたとき) */
async function refreshRemote() {
  if (!signedIn || !store) {
    updateSyncChip();
    return;
  }
  lastRefresh = Date.now();
  await saveNow(); // 打ちかけを「まだ送っていない」に数えてから突き合わせる
  const state = await syncClient.state();
  if (!state.signedIn) {
    account = '';
    canSync = false;
    updateSyncChip();
    return;
  }
  account = state.account ?? '';
  canSync = state.canSave === true && account !== '';
  blockedMessage = state.blocked ?? '';
  remoteById = new Map((state.projects ?? []).map((p) => [p.id, p]));
  remoteUsage = { used: state.usedBytes ?? 0, limits: state.limits ?? null, count: (state.projects ?? []).length };
  syncProblem = '';
  if (!canSync) {
    return;
  }
  for (const record of await store.listProjects()) {
    const st = remoteState(record, account, remoteById);
    if (st === 'gone' && record.server) {
      await updateRecord(record.id, (r) => {
        r.server = null;
        r.syncOff = 'gone';
      });
    } else if (st === 'remote') {
      await pullInto(record.id, record.server.id);
    } else if (st === 'conflict') {
      conflicts.add(record.id);
    } else if (st === 'pending' && !syncErrors.has(record.id)) {
      await pushProject(record.id);
    }
  }
}

async function resolveConflict(id) {
  const record = project?.id === id ? project : await store.getProject(id);
  if (!record?.server) {
    conflicts.delete(id);
    updateSyncChip();
    return;
  }
  const choice = await chooseDialog(t(lang, 'conflict.text', { name: record.name }), [
    { value: 'both', label: tr('conflict.both') },
    { value: 'theirs', label: tr('conflict.theirs') },
    { value: 'mine', label: tr('conflict.mine'), primary: true },
  ]);
  if (!choice) {
    return;
  }
  const serverId = record.server.id;
  await queueSync(async () => {
    if (choice === 'theirs') {
      await pullInto(id, serverId);
    } else if (choice === 'mine') {
      conflicts.delete(id);
      await pushProject(id, { force: true });
    } else {
      // この端末のものは別のプロジェクトとして残し(新しくサーバーへ送る)、サーバーのものを読み直す
      conflicts.delete(id);
      await updateRecord(id, (r) => {
        r.server = null;
        r.syncDirty = true;
        r.name = cleanName(t(lang, 'conflict.localName', { name: r.name }));
      });
      if (project?.id === id) {
        $('wce-project').textContent = project.name;
        lastMetaSig = JSON.stringify([project.name, project.entry ?? '', project.folders ?? []]);
      }
      await pushProject(id);
      await pullNew(serverId);
    }
  });
}

// ---- ファイルを探して開く(Ctrl+P) ----
const quickDialog = $('wce-quick-dialog');
const quickInput = $('wce-quick-input');
const quickList = $('wce-quick-list');
function fuzzyScore(path, query) {
  const p = path.toLowerCase();
  const q = query.toLowerCase();
  if (q === '') {
    return 1;
  }
  const at = p.indexOf(q);
  if (at >= 0) {
    return 1000 - at - (p.length - q.length) / 100;
  }
  let i = 0;
  for (const ch of p) {
    if (ch === q[i]) {
      i++;
      if (i === q.length) {
        return 100 - p.length / 100;
      }
    }
  }
  return -1;
}
function renderQuick() {
  const q = quickInput.value.trim();
  const hits = [...files.keys()].map((p) => [p, fuzzyScore(p, q)]).filter(([, s]) => s >= 0).sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])).slice(0, 50);
  quickList.replaceChildren(...hits.map(([p]) => el('li', {}, [el('button', {
    className: 'wce-quick-item',
    text: p,
    attrs: { type: 'button' },
    on: {
      click: () => {
        quickDialog.close();
        selectFile(p, { focus: true });
      },
      keydown: (event) => {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          event.target.closest('li')?.nextElementSibling?.querySelector('button')?.focus();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          const prev = event.target.closest('li')?.previousElementSibling?.querySelector('button');
          (prev ?? quickInput).focus();
        }
      },
    },
  })])));
  if (hits.length === 0) {
    quickList.append(el('li', { className: 'wce-help', text: tr('quick.none') }));
  }
}
quickInput.addEventListener('input', renderQuick);
quickInput.addEventListener('keydown', (event) => {
  if (event.key === 'Enter') {
    event.preventDefault();
    quickList.querySelector('button')?.click();
  } else if (event.key === 'ArrowDown') {
    event.preventDefault();
    quickList.querySelector('button')?.focus();
  }
});
function openQuickOpen() {
  quickInput.value = '';
  renderQuick();
  quickDialog.showModal();
  quickInput.focus();
}

// ---- ショートカット ----
const ACTION_RUN = {
  run: () => {
    previewTimer.cancel();
    runPreview();
  },
  save: async () => {
    await saveNow();
    if (project && needsPush(project, account)) {
      syncTimer.cancel();
      queueSync(() => pushProject(project.id));
    }
  },
  newFile: () => newFileFlow(project?.active?.includes('/') ? project.active.slice(0, project.active.lastIndexOf('/')) : ''),
  quickOpen: () => openQuickOpen(),
  nextTab: () => cycleTab(1),
  prevTab: () => cycleTab(-1),
  closeTab: () => project?.active && closeTab(project.active),
  projects: () => openProjects(),
  toggleFiles: () => setSidebar(!settings.sidebarOpen),
  format: () => {
    editor.getAction('editor.action.formatDocument')?.run();
    editor.focus();
  },
};
function runAction(action) {
  ACTION_RUN[action]?.();
}
let recording = null;
window.addEventListener('keydown', (event) => {
  if (recording || event.isComposing) {
    return;
  }
  const action = actionForKey(keymap, keyFromEvent(event));
  if (!action) {
    return;
  }
  // ダイアログを開いている間は動かさない。ただしブラウザの動き(Ctrl+P の印刷など)は止める
  event.preventDefault();
  if (anyDialogOpen()) {
    return;
  }
  event.stopPropagation();
  runAction(action);
}, true);

function keyLabel(key) {
  return key === '' ? tr('keys.none') : key;
}
function updateKeyTitles() {
  $('wce-run').title = t(lang, 'action.runTitle', { key: keyLabel(keymap.run) });
  $('wce-project').title = t(lang, 'project.listTitle', { key: keyLabel(keymap.projects) });
  $('wce-files-toggle').title = t(lang, 'files.toggle', { key: keyLabel(keymap.toggleFiles) });
}

let draftKeys = { ...keymap };
function renderKeys(message = '') {
  const list = $('wce-keys-list');
  list.replaceChildren(...ACTIONS.map((action) => {
    const isRec = recording === action;
    return el('li', { className: 'wce-key-row' }, [
      el('span', { className: 'wce-key-name', text: t(lang, `keys.${action}`) }),
      el('button', {
        className: `wce-btn wce-btn-small wce-key-btn${isRec ? ' is-recording' : ''}`,
        text: isRec ? tr('keys.press') : keyLabel(draftKeys[action]),
        attrs: { type: 'button', 'aria-label': `${t(lang, `keys.${action}`)}: ${keyLabel(draftKeys[action])}` },
        on: {
          click: (event) => {
            recording = action;
            renderKeys();
            $('wce-keys-list').querySelector('.is-recording')?.focus();
            event.preventDefault();
          },
        },
      }),
    ]);
  }));
  $('wce-keys-message').textContent = message;
}
// 付け直しの間は、押したキーを先に取る(ダイアログの中でも)
window.addEventListener('keydown', (event) => {
  if (!recording) {
    return;
  }
  event.preventDefault();
  event.stopPropagation();
  const action = recording;
  if (event.key === 'Escape') {
    recording = null;
    renderKeys();
    return;
  }
  if ((event.key === 'Backspace' || event.key === 'Delete') && !event.ctrlKey && !event.altKey && !event.metaKey) {
    recording = null;
    draftKeys = { ...draftKeys, [action]: '' };
    renderKeys();
    return;
  }
  const key = keyFromEvent(event);
  if (!key) {
    return; // Ctrl などだけ。続きを待つ
  }
  recording = null;
  const result = assignKey(draftKeys, action, key);
  if (result.ok) {
    draftKeys = result.keymap;
    renderKeys();
  } else {
    renderKeys(t(lang, `keys.err.${result.reason}`, { key, other: result.other ? t(lang, `keys.${result.other}`) : '' }));
  }
}, true);
$('wce-keys-reset').addEventListener('click', () => {
  draftKeys = { ...DEFAULT_KEYS };
  recording = null;
  renderKeys();
});

// ---- 設定 ----
const settingsDialog = $('wce-settings-dialog');
const delayRange = $('wce-delay-range');
const delayNumber = $('wce-delay-number');
const autoBox = $('wce-auto');
const themeSelect = $('wce-theme-select');
function syncDelayInputs(value) {
  const v = clampDelay(value);
  delayRange.value = String(v);
  delayNumber.value = String(v);
}
delayRange.addEventListener('input', () => syncDelayInputs(delayRange.value));
delayNumber.addEventListener('input', () => {
  if (delayNumber.value.trim() !== '') {
    delayRange.value = String(clampDelay(delayNumber.value));
  }
});
delayNumber.addEventListener('change', () => syncDelayInputs(delayNumber.value));
autoBox.addEventListener('change', () => {
  delayRange.disabled = !autoBox.checked;
  delayNumber.disabled = !autoBox.checked;
});
function renderThemeOptions() {
  themeSelect.replaceChildren(...THEMES.map((th) => el('option', { text: t(lang, `theme.${th}`), attrs: { value: th, selected: th === settings.theme } })));
}
async function showStorage() {
  const node = $('wce-storage');
  if (!persistent) {
    node.textContent = tr('save.memory');
    return;
  }
  try {
    const est = await navigator.storage?.estimate?.();
    node.textContent = est ? t(lang, 'settings.storage', { used: formatBytes(est.usage ?? 0, lang), quota: formatBytes(est.quota ?? 0, lang) }) : '';
  } catch {
    node.textContent = '';
  }
}
$('wce-settings').addEventListener('click', () => {
  autoBox.checked = settings.autoRun;
  syncDelayInputs(settings.delay);
  delayRange.disabled = !settings.autoRun;
  delayNumber.disabled = !settings.autoRun;
  renderThemeOptions();
  draftKeys = { ...keymap };
  recording = null;
  renderKeys();
  showStorage();
  settingsDialog.returnValue = '';
  settingsDialog.showModal();
});
settingsDialog.addEventListener('close', () => {
  recording = null;
  if (settingsDialog.returnValue !== 'save') {
    return;
  }
  settings.autoRun = autoBox.checked;
  settings.delay = clampDelay(delayNumber.value);
  settings.theme = THEMES.includes(themeSelect.value) ? themeSelect.value : settings.theme;
  keymap = normalizeKeymap(draftKeys);
  saveSettings();
  updateModeLabel();
  updateKeyTitles();
  applyTheme();
  if (!settings.autoRun) {
    previewTimer.cancel();
  }
});

// 見た目の切り替え(押すたびに次へ)
$('wce-theme').addEventListener('click', () => {
  settings.theme = THEMES[(THEMES.indexOf(settings.theme) + 1) % THEMES.length];
  applyTheme();
  saveSettings();
});

// ---- 左右の幅 ----
const main = $('wce-main');
const resizer = $('wce-resizer');
function applySplit() {
  main.style.setProperty('--wce-split', `${settings.split}%`);
  resizer.setAttribute('aria-valuenow', String(Math.round(settings.split)));
}
resizer.addEventListener('pointerdown', (event) => {
  event.preventDefault();
  resizer.setPointerCapture(event.pointerId);
  main.classList.add('is-resizing');
  const rect = main.getBoundingClientRect();
  const move = (e) => {
    settings.split = clampSplit(((e.clientX - rect.left) / rect.width) * 100);
    applySplit();
  };
  const up = () => {
    main.classList.remove('is-resizing');
    resizer.removeEventListener('pointermove', move);
    resizer.removeEventListener('pointerup', up);
    resizer.removeEventListener('pointercancel', up);
    saveSettings();
  };
  resizer.addEventListener('pointermove', move);
  resizer.addEventListener('pointerup', up);
  resizer.addEventListener('pointercancel', up);
});
resizer.addEventListener('keydown', (event) => {
  const step = event.shiftKey ? 10 : 2;
  if (event.key === 'ArrowLeft') {
    settings.split = clampSplit(settings.split - step);
  } else if (event.key === 'ArrowRight') {
    settings.split = clampSplit(settings.split + step);
  } else if (event.key === 'Home') {
    settings.split = clampSplit(0);
  } else if (event.key === 'End') {
    settings.split = clampSplit(100);
  } else {
    return;
  }
  event.preventDefault();
  applySplit();
  saveSettings();
});

// ---- スマホの「編集 / プレビュー」 ----
for (const btn of document.querySelectorAll('.wce-switch-btn')) {
  btn.addEventListener('click', () => {
    main.dataset.view = btn.dataset.view;
    for (const other of document.querySelectorAll('.wce-switch-btn')) {
      other.setAttribute('aria-pressed', String(other === btn));
    }
    if (btn.dataset.view === 'edit') {
      editor.layout();
    }
  });
}

// ---- 言語の切り替え ----
function applyLang() {
  applyI18n(document, lang);
  const btn = $('wce-lang');
  btn.textContent = t(lang, 'lang.switch');
  btn.lang = lang === 'ja' ? 'en' : 'ja';
  setSaveState(saveState.state, saveState.reason);
  updateModeLabel();
  updateKeyTitles();
  applyTheme();
  updateSyncChip();
  renderTree();
  renderTabsUi();
  if (consoleEntries === 0) {
    renderConsoleEmpty();
  }
  if (project && !project.active) {
    showFile('');
  }
}
$('wce-lang').addEventListener('click', () => {
  lang = lang === 'ja' ? 'en' : 'ja';
  rememberLang(lang);
  const url = new URL(location.href);
  if (url.searchParams.has('lang')) {
    url.searchParams.set('lang', lang);
    history.replaceState(null, '', url);
  }
  applyLang();
  setLanguageData(lang);
});

// 閉じる前・隠れたときに書きかけを保存する
window.addEventListener('pagehide', () => {
  if (dirty.size > 0 || deleted.size > 0 || saveTimer.pending()) {
    saveNow();
  }
});
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') {
    if (dirty.size > 0 || deleted.size > 0 || saveTimer.pending()) {
      saveNow();
    }
    // 隠れたら待たずに送る(閉じられる前に)
    if (canSync && project && (syncTimer.pending() || needsPush(project, account))) {
      syncTimer.cancel();
      queueSync(() => pushProject(project.id));
    }
  } else if (signedIn && Date.now() - lastRefresh > REFRESH_EVERY) {
    // 戻ってきたら、ほかの端末での変更を見る
    queueSync(refreshRemote);
  }
});

// やめるボタン(type=button)。Enter で「作る」などが選ばれるように、やめるは送信のボタンにしない
for (const btn of document.querySelectorAll('[data-close]')) {
  btn.addEventListener('click', () => btn.closest('dialog')?.close('cancel'));
}

// ---- 始める ----
async function start() {
  applyLang();
  applySplit();
  if (narrow.matches) {
    settings.sidebarOpen = false;
  }
  applySidebar();
  clearConsole();
  ({ store, persistent } = await openStore());
  if (!persistent) {
    setSaveState('memory');
  }
  let list = await store.listProjects();
  if (list.length === 0) {
    try {
      await migrateLegacy(store, lang);
    } catch {
      // 移せなければ新しく作る(古い鍵は残っている)
    }
    list = await store.listProjects();
  }
  if (list.length === 0) {
    await createProject(templateName(DEFAULT_TEMPLATE, lang), buildTemplateFiles(DEFAULT_TEMPLATE, lang));
    list = await store.listProjects();
  }
  const target = list.find((p) => p.id === settings.lastProject) ?? list[0];
  await openProject(target.id);
  updateSyncChip();
  if (signedIn) {
    queueSync(refreshRemote);
  }
}
start().catch((error) => {
  addConsole({ level: 'error', text: `${tr('start.failed')} ${error?.message ?? ''}` });
});
