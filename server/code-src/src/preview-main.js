/*
 * VS Code 型のエディタのプレビューの窓(/code/preview.php?p=<プロジェクト>。段 11-3。2026-11-09)。
 *
 * - プロジェクトは /editor/・/code/ と同じ置き場(IndexedDB km-editor)から読む。/code/ は打つのを止めて
 *   0.8 秒で保存する(files.autoSave)。保存の知らせ(db.js の onExternalChange)が来たら組み直す
 * - 組み立て(projectBuilder.js)と枠とのやり取り(preview.js)は /editor/ の部品をそのまま使う(写しを作らない)
 * - コンソールの「ファイル:行」を押すと、/code/ にそのファイルを開かせる(同じオリジンの BroadcastChannel
 *   km-code-reveal。受けるのは commands.js。開いているプロジェクトが同じときだけ動く)
 */
import { createPreview } from '../../editor-src/src/preview/preview.js';
import { buildProjectDocument } from '../../editor-src/src/preview/projectBuilder.js';
import { onExternalChange, openStore } from '../../editor-src/src/project/db.js';
import { fileType, isHtml, pickEntry } from '../../editor-src/src/project/model.js';
import { blobToDataUrl } from '../../editor-src/src/ui/dom.js';
import { REVEAL_CHANNEL } from './paths.js';

const LANG_KEY = 'km-code-lang';
const CONSOLE_MAX = 500;

// 開いた /code/ の窓への手綱(window.opener)は使わないので切る(名前で同じ窓を使い回すため、開く側は noopener を付けない)
try {
  window.opener = null;
} catch {
  // 切れなくても動きは同じ
}

function pickLang() {
  const q = new URLSearchParams(location.search).get('lang');
  if (q === 'en' || q === 'ja') {
    return q;
  }
  try {
    const saved = localStorage.getItem(LANG_KEY);
    if (saved === 'en' || saved === 'ja') {
      return saved;
    }
  } catch {
    // 読めなければブラウザの言語
  }
  return (navigator.language || 'ja').toLowerCase().startsWith('en') ? 'en' : 'ja';
}

const lang = pickLang();
document.documentElement.lang = lang;

const TEXT = {
  ja: {
    title: 'プレビュー',
    entry: 'プレビューするページ',
    reload: '再読み込み',
    console: 'コンソール',
    clear: '消す',
    count: (n) => `${n} 件`,
    loading: '読み込み中…',
    updated: (time) => `${time} に組み直しました`,
    noProject: 'プロジェクトが見つかりません。/code/ の「プレビュー」から開いてください。',
    deleted: 'このプロジェクトは消されました。',
    noHtml: 'このプロジェクトには HTML のファイルがありません。',
    tooLarge: '大きすぎてプレビューできません(32,000,000 字まで)',
    missing: (path, from) => `${from} が指す ${path} がプロジェクトにありません`,
    cycle: (path) => `${path} が自分自身を読み込んでいます(読み込みの輪)`,
    reveal: 'エディタでこの場所を開く',
  },
  en: {
    title: 'Preview',
    entry: 'Page to preview',
    reload: 'Reload',
    console: 'Console',
    clear: 'Clear',
    count: (n) => `${n}`,
    loading: 'Loading…',
    updated: (time) => `Rebuilt at ${time}`,
    noProject: 'Project not found. Open this from “Preview” in /code/.',
    deleted: 'This project was deleted.',
    noHtml: 'This project has no HTML file.',
    tooLarge: 'Too large to preview (up to 32,000,000 characters)',
    missing: (path, from) => `${path} (used by ${from}) is not in the project`,
    cycle: (path) => `${path} imports itself (import cycle)`,
    reveal: 'Open this place in the editor',
  },
};
const t = TEXT[lang];

const $ = (id) => document.getElementById(id);
const nameEl = $('kp-name');
const entrySelect = $('kp-entry');
const reloadBtn = $('kp-reload');
const stateEl = $('kp-state');
const consoleBox = $('kp-console');
const consoleToggle = $('kp-console-toggle');
const consoleList = $('kp-console-list');
const consoleCount = $('kp-console-count');
const frame = $('kp-frame');
const runUrl = document.querySelector('meta[name="km-editor-run"]')?.content ?? '';

entrySelect.setAttribute('aria-label', t.entry);
reloadBtn.textContent = t.reload;
consoleToggle.textContent = t.console;
$('kp-console-clear').textContent = t.clear;
consoleBox.setAttribute('aria-label', t.console);
frame.title = t.title;
stateEl.textContent = t.loading;

const projectId = new URLSearchParams(location.search).get('p') ?? '';
/** @type {Map<string, {text?: string, dataUrl?: string, mime: string}>} */
let files = new Map();
let project = null;
let entry = '';
let lines = 0;

let reveal = null;
try {
  reveal = typeof BroadcastChannel === 'function' ? new BroadcastChannel(REVEAL_CHANNEL) : null;
} catch {
  reveal = null;
}

// ---- コンソール ----
function clearConsole() {
  consoleList.replaceChildren();
  lines = 0;
  consoleCount.textContent = t.count(0);
}

function addConsole({ level = 'log', text = '', path = '', line = 0, col = 0 }) {
  if (lines >= CONSOLE_MAX) {
    consoleList.firstElementChild?.remove();
  } else {
    lines += 1;
  }
  const li = document.createElement('li');
  li.dataset.level = level;
  const span = document.createElement('span');
  span.className = 'kp-text';
  span.textContent = text;
  li.append(span);
  if (path) {
    const where = document.createElement('button');
    where.type = 'button';
    where.className = 'kp-where';
    where.title = t.reveal;
    where.textContent = line > 0 ? `${path}:${line}` : path;
    where.addEventListener('click', () => {
      try {
        reveal?.postMessage({ type: 'reveal', projectId, path, line, col });
      } catch {
        // 知らせられなくても表示はそのまま
      }
    });
    li.append(where);
  }
  consoleList.append(li);
  li.scrollIntoView({ block: 'nearest' });
  consoleCount.textContent = t.count(lines);
}

consoleToggle.addEventListener('click', () => {
  const open = consoleBox.hidden;
  consoleBox.hidden = !open;
  consoleToggle.setAttribute('aria-expanded', String(open));
});
$('kp-console-clear').addEventListener('click', clearConsole);

// ---- 読み込み ----
async function toEntry(record, old, changed) {
  const mime = fileType(record.path)?.mime ?? 'application/octet-stream';
  if (typeof record.text === 'string') {
    return { text: record.text, mime };
  }
  const before = old.get(record.path);
  if (before && before.dataUrl !== undefined && !changed.has(record.path)) {
    return before;
  }
  let dataUrl = '';
  if (record.blob) {
    try {
      dataUrl = await blobToDataUrl(record.blob);
    } catch {
      dataUrl = '';
    }
  }
  return { dataUrl, mime };
}

/** @param {Set<string>|null} changed 変わったパス(null = 全部読み直す) */
async function load(store, changed = null) {
  const list = await store.getFiles(projectId);
  const old = files;
  const next = new Map();
  const all = changed ?? new Set(list.map((f) => f.path));
  for (const record of list) {
    next.set(record.path, await toEntry(record, old, all));
  }
  files = next;
}

// ---- プレビュー ----
const preview = createPreview({
  frame,
  runUrl,
  onConsole: addConsole,
  onNav: (path) => {
    if (files.has(path) && isHtml(path)) {
      entry = path;
      run();
    }
  },
});

function renderEntrySelect() {
  const html = [...files.keys()].filter(isHtml).sort();
  entrySelect.replaceChildren(...html.map((p) => {
    const option = document.createElement('option');
    option.value = p;
    option.textContent = p;
    option.selected = p === entry;
    return option;
  }));
  entrySelect.disabled = html.length === 0;
}

function currentEntry() {
  if (entry && files.has(entry) && isHtml(entry)) {
    return entry;
  }
  const active = project?.active && isHtml(project.active) ? project.active : '';
  entry = pickEntry([...files.keys()], project?.entry || active) ?? '';
  return entry;
}

function message(text) {
  preview.run(() => ({ text: `<!DOCTYPE html><html lang="${lang}"><body><p>${text}</p></body></html>` }));
}

function run() {
  clearConsole();
  const target = currentEntry();
  renderEntrySelect();
  if (!target) {
    message(t.noHtml);
    return;
  }
  const result = preview.run((runId) => buildProjectDocument(files, target, { runId, lang, mode: 'preview' }));
  if (!result.ok) {
    addConsole({ level: 'error', text: t.tooLarge });
  }
  for (const w of result.built?.warnings ?? []) {
    addConsole({ level: 'warn', text: w.code === 'missing' ? t.missing(w.path, w.from || target) : t.cycle(w.path), path: w.from || target });
  }
  stateEl.textContent = t.updated(new Date().toLocaleTimeString(lang === 'ja' ? 'ja-JP' : 'en-US'));
}

entrySelect.addEventListener('change', () => {
  entry = entrySelect.value;
  run();
});

function showName() {
  const name = project?.name ?? '';
  nameEl.textContent = name;
  document.title = `${name ? `${name} — ` : ''}${t.title} | Web Code Editor (Workbench)`;
}

const { store } = await openStore();
project = projectId ? await store.getProject(projectId) : null;
if (!project) {
  nameEl.textContent = '';
  stateEl.textContent = '';
  reloadBtn.disabled = true;
  entrySelect.disabled = true;
  message(t.noProject);
} else {
  showName();
  await load(store);
  run();

  reloadBtn.addEventListener('click', async () => {
    project = (await store.getProject(projectId)) ?? project;
    await load(store);
    preview.reload();
    run();
  });

  // 保存の知らせ: 続けて来ることが多いので、少し待ってまとめて読む
  let pendingPaths = new Set();
  let pendingProject = false;
  let timer = 0;
  onExternalChange(({ projectId: id, paths, kind }) => {
    if (id !== projectId) {
      return;
    }
    if (kind === 'deleted') {
      clearTimeout(timer);
      project = null;
      files = new Map();
      renderEntrySelect();
      stateEl.textContent = '';
      message(t.deleted);
      return;
    }
    if (!project) {
      return;
    }
    for (const p of paths) {
      pendingPaths.add(p);
    }
    pendingProject ||= kind === 'project';
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const changed = pendingPaths;
      const recordChanged = pendingProject;
      pendingPaths = new Set();
      pendingProject = false;
      if (recordChanged) {
        project = (await store.getProject(projectId)) ?? project;
        showName();
      }
      if (changed.size > 0) {
        await load(store, changed);
        run();
      }
    }, 250);
  });
}
