/*
 * VS Code 型のエディタの自前のコマンド(段 11-1。2026-11-08)。
 *
 * VS Code の拡張機能の形(ローカル。このページの中で動く)で足す:
 *   プロジェクトを開く(切り替えはページを開き直す。VS Code の本体は 1 ページで 1 回しか起こせないため)・
 *   新しいプロジェクト(/editor/ と同じひな形)・ZIP/ファイル/フォルダを読み込む・ZIP で書き出す・
 *   かんたんなエディタ(/editor/)で開く。下の帯(ステータスバー)の左にプロジェクトの名前を出す。
 * プロジェクトは /editor/ と同じ置き場(editor-src/src/project/db.js)。部品も /editor/ のものを使い回す
 */
import { ExtensionHostKind, registerExtension } from '@codingame/monaco-vscode-api/extensions';
import { newProjectRecord, pickEntry } from '../../editor-src/src/project/model.js';
import { prepareEntries, projectZip, readFileList, toStored } from '../../editor-src/src/project/import.js';
import { buildTemplateFiles, TEMPLATE_IDS, templateName, templateNote } from '../../editor-src/src/templates.js';
import { downloadBlob, formatBytes } from '../../editor-src/src/ui/dom.js';
import { t as tr } from '../../editor-src/src/i18n.js';
import { absPath, REVEAL_CHANNEL, revealTarget } from './paths.js';
import { aboutMarkdown } from './about.js';

const TEXT = {
  ja: {
    category: 'プロジェクト',
    open: 'プロジェクトを開く…',
    create: '新しいプロジェクト…',
    importFiles: 'ZIP・ファイル・フォルダを読み込む…',
    exportZip: 'プロジェクトを ZIP で書き出す',
    simple: 'かんたんなエディタ(/editor/)で開く',
    preview: 'プレビューを別の窓で開く',
    about: 'このエディタについて(ライセンス)',
    previewShort: 'プレビュー',
    openPlaceholder: '開くプロジェクトを選んでください',
    current: '開いている',
    files: (n) => `${n} 個のファイル`,
    newItem: '$(add) 新しいプロジェクト…',
    importItem: '$(cloud-upload) ZIP・フォルダから作る…',
    templatePlaceholder: 'ひな形を選んでください',
    namePrompt: 'プロジェクトの名前',
    pickSource: '読み込むものを選んでください',
    zipOrFiles: '$(file-zip) ZIP・ファイルを選ぶ',
    folder: '$(folder) フォルダを選ぶ',
    password: (wrong) => (wrong ? 'パスワードが違います。もう一度入れてください' : 'この ZIP にはパスワードが掛かっています'),
    whereTo: '読み込んだものをどこに入れますか',
    intoCurrent: '$(add) いまのプロジェクトに足す',
    asNew: '$(new-folder) 新しいプロジェクトにする',
    overwrite: (n) => `${n} 個のファイルは同じ名前があります。上書きしますか?`,
    overwriteYes: '上書きする',
    overwriteSkip: '同じ名前は飛ばす',
    nothing: '読み込めるファイルがありませんでした',
    rejected: (n) => `${n} 個は読み込みませんでした(使えない名前・種類)`,
    limit: '1 つのプロジェクトは 20MB・500 ファイルまでです',
    imported: (n) => `${n} 個のファイルを読み込みました`,
    failed: '読み込めませんでした',
  },
  en: {
    category: 'Project',
    open: 'Open Project…',
    create: 'New Project…',
    importFiles: 'Import ZIP, Files or Folder…',
    exportZip: 'Export Project as ZIP',
    simple: 'Open in the Simple Editor (/editor/)',
    preview: 'Open Preview in a New Window',
    about: 'About This Editor (Licenses)',
    previewShort: 'Preview',
    openPlaceholder: 'Choose a project to open',
    current: 'open',
    files: (n) => `${n} files`,
    newItem: '$(add) New Project…',
    importItem: '$(cloud-upload) From ZIP or Folder…',
    templatePlaceholder: 'Choose a template',
    namePrompt: 'Project name',
    pickSource: 'Choose what to import',
    zipOrFiles: '$(file-zip) Choose a ZIP or files',
    folder: '$(folder) Choose a folder',
    password: (wrong) => (wrong ? 'Wrong password. Try again' : 'This ZIP is password-protected'),
    whereTo: 'Where should the files go?',
    intoCurrent: '$(add) Add to this project',
    asNew: '$(new-folder) Make a new project',
    overwrite: (n) => `${n} files already exist. Overwrite?`,
    overwriteYes: 'Overwrite',
    overwriteSkip: 'Skip existing',
    nothing: 'Nothing could be imported',
    rejected: (n) => `${n} files were skipped (name or type not allowed)`,
    limit: 'A project can be up to 20 MB and 500 files',
    imported: (n) => `Imported ${n} files`,
    failed: 'Could not import',
  },
};

/** 隠した <input type=file> で選ばせる(選ばなければ null) */
function pickFiles({ folder }) {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.hidden = true;
    if (folder) {
      input.webkitdirectory = true;
    }
    input.addEventListener('change', () => {
      resolve(input.files && input.files.length > 0 ? input.files : null);
      input.remove();
    });
    input.addEventListener('cancel', () => {
      resolve(null);
      input.remove();
    });
    document.body.append(input);
    input.click();
  });
}

/**
 * @param {{store: object, project: object, lang: 'ja'|'en', sync?: object}} ctx  sync は codeSync.js(一覧に同期の印とサーバーにだけあるもの)
 */
export async function registerProjectCommands({ store, project, lang, sync = null }) {
  const t = TEXT[lang] ?? TEXT.ja;
  const reg = registerExtension({
    name: 'km-code',
    publisher: 'ito4',
    displayName: 'Web Code Editor',
    version: '1.0.0',
    engines: { vscode: '*' },
    contributes: {
      commands: [
        { command: 'km.openProject', title: t.open, category: t.category },
        { command: 'km.newProject', title: t.create, category: t.category },
        { command: 'km.importFiles', title: t.importFiles, category: t.category },
        { command: 'km.exportZip', title: t.exportZip, category: t.category },
        { command: 'km.openSimple', title: t.simple, category: t.category },
        { command: 'km.preview', title: t.preview, category: t.category, icon: '$(open-preview)' },
        { command: 'km.about', title: t.about, category: t.category },
      ],
      keybindings: [
        { command: 'km.preview', key: 'ctrl+shift+alt+v', mac: 'cmd+shift+alt+v' },
      ],
      menus: {
        'editor/title': [
          { command: 'km.preview', when: 'resourceExtname =~ /^\\.(html?|css|m?js)$/', group: 'navigation@90' },
        ],
        'explorer/context': [
          { command: 'km.importFiles', group: '9_km@1' },
          { command: 'km.exportZip', group: '9_km@2' },
        ],
      },
    },
  }, ExtensionHostKind.LocalProcess, { system: true });
  const vscode = await reg.getApi();

  const go = (id) => {
    const url = new URL(location.href);
    url.searchParams.set('p', id);
    location.assign(url);
  };

  async function chooseTemplateAndCreate() {
    const pick = await vscode.window.showQuickPick(TEMPLATE_IDS.map((id) => ({ id, label: templateName(id, lang), detail: templateNote(id, lang) })), { placeHolder: t.templatePlaceholder });
    if (!pick) {
      return;
    }
    const name = await vscode.window.showInputBox({ prompt: t.namePrompt, value: pick.label });
    if (name === undefined) {
      return;
    }
    const made = newProjectRecord(name, buildTemplateFiles(pick.id, lang), { fallbackName: pick.label });
    await store.writeFiles(made.project.id, made.puts, [], made.project);
    go(made.project.id);
  }

  async function readChosen() {
    const source = await vscode.window.showQuickPick([{ id: 'files', label: t.zipOrFiles }, { id: 'folder', label: t.folder }], { placeHolder: t.pickSource });
    if (!source) {
      return null;
    }
    const chosen = await pickFiles({ folder: source.id === 'folder' });
    if (!chosen) {
      return null;
    }
    const raw = await readFileList(chosen, (wrong) => vscode.window.showInputBox({ prompt: t.password(wrong), password: true }).then((v) => v ?? null));
    return prepareEntries(raw);
  }

  async function importFiles({ forceNew = false } = {}) {
    try {
      const prepared = await readChosen();
      if (!prepared) {
        return;
      }
      if (prepared.files.length === 0) {
        vscode.window.showWarningMessage(t.nothing);
        return;
      }
      if (prepared.rejected.length > 0) {
        vscode.window.showWarningMessage(t.rejected(prepared.rejected.length));
      }
      if (prepared.limit) {
        vscode.window.showErrorMessage(t.limit);
        return;
      }
      const where = forceNew ? { id: 'new' } : await vscode.window.showQuickPick([{ id: 'current', label: t.intoCurrent }, { id: 'new', label: t.asNew }], { placeHolder: t.whereTo });
      if (!where) {
        return;
      }
      if (where.id === 'new') {
        const paths = prepared.files.map((f) => f.path);
        const name = await vscode.window.showInputBox({ prompt: t.namePrompt, value: (paths[0] ?? 'project').split('/')[0].replace(/\.[^.]+$/, '') });
        if (name === undefined) {
          return;
        }
        const made = newProjectRecord(name, {});
        const entry = pickEntry(paths) ?? '';
        made.project.entry = entry;
        made.project.open = entry ? [entry] : [];
        made.project.active = entry;
        await store.writeFiles(made.project.id, prepared.files.map((f) => toStored(f.path, f.data)), [], made.project);
        go(made.project.id);
        return;
      }
      // いまのプロジェクトへ: VS Code のファイルの口を通す(名前・種類・上限の確かめと、画面への知らせが一緒に済む)
      const existing = [];
      for (const f of prepared.files) {
        try {
          await vscode.workspace.fs.stat(vscode.Uri.file(absPath(f.path)));
          existing.push(f.path);
        } catch {
          // 無い = 新しく足す
        }
      }
      let skip = new Set();
      if (existing.length > 0) {
        const answer = await vscode.window.showWarningMessage(t.overwrite(existing.length), { modal: true }, t.overwriteYes, t.overwriteSkip);
        if (!answer) {
          return;
        }
        if (answer === t.overwriteSkip) {
          skip = new Set(existing);
        }
      }
      let count = 0;
      for (const f of prepared.files) {
        if (!skip.has(f.path)) {
          await vscode.workspace.fs.writeFile(vscode.Uri.file(absPath(f.path)), f.data);
          count++;
        }
      }
      vscode.window.showInformationMessage(t.imported(count));
    } catch (error) {
      vscode.window.showErrorMessage(`${t.failed}: ${error?.message ?? error}`);
    }
  }

  vscode.commands.registerCommand('km.openProject', async () => {
    const list = await store.listProjects();
    // サインインしていれば同期の印(/editor/ の一覧と同じ)と、サーバーにだけあるもの(選ぶとこの端末へ読み込む)
    const badge = (p) => (sync?.canSync ? tr(lang, `badge.${sync.stateOf(p)}`) : '');
    const serverOnly = sync ? await sync.serverOnly() : [];
    const items = [
      ...list.map((p) => ({
        id: p.id,
        label: p.name,
        description: [p.id === project.id ? t.current : '', badge(p)].filter(Boolean).join(' · '),
        detail: new Date(p.updatedAt ?? 0).toLocaleString(lang === 'ja' ? 'ja-JP' : 'en-US'),
      })),
      ...serverOnly.map((r) => ({ id: `#srv:${r.id}`, label: `$(cloud-download) ${r.name}`, description: tr(lang, 'badge.server') })),
      { kind: vscode.QuickPickItemKind.Separator, label: '' },
      { id: '#new', label: t.newItem },
      { id: '#import', label: t.importItem },
    ];
    const pick = await vscode.window.showQuickPick(items, { placeHolder: t.openPlaceholder, matchOnDetail: true });
    if (!pick) {
      return;
    }
    if (pick.id === '#new') {
      await chooseTemplateAndCreate();
    } else if (pick.id === '#import') {
      await importFiles({ forceNew: true });
    } else if (pick.id.startsWith('#srv:') && sync) {
      let made = null;
      await sync.queue(async () => {
        made = await sync.pullNew(pick.id.slice(5));
      });
      if (made) {
        go(made);
      }
    } else if (pick.id !== project.id) {
      go(pick.id);
    }
  });
  vscode.commands.registerCommand('km.newProject', chooseTemplateAndCreate);
  vscode.commands.registerCommand('km.importFiles', () => importFiles());
  vscode.commands.registerCommand('km.exportZip', async () => {
    await vscode.workspace.saveAll(false);
    const files = await store.getFiles(project.id);
    const blob = await projectZip(files);
    const safe = String(project.name).replace(/[\\/:*?"<>|\u0000-\u001f]/g, '_').slice(0, 80) || 'project';
    downloadBlob(`${safe}.zip`, blob);
    vscode.window.setStatusBarMessage(`${safe}.zip(${formatBytes(blob.size, lang)})`, 4000);
  });
  vscode.commands.registerCommand('km.openSimple', async () => {
    await vscode.workspace.saveAll(false);
    window.open(`/editor/?p=${encodeURIComponent(project.id)}`, '_blank', 'noopener');
  });

  /*
   * プレビュー(段 11-3)。本体のオリジンの別の窓 /code/preview.php が同じ置き場から読み、保存のたびに組み直す
   * (VS Code の webview の中に枠を重ねると、webview.<ドメイン> の CSP に html.<ドメイン> を足すことになるため)。
   * 窓には名前を付け、2 回目は同じ窓を使う('noopener' を付けると名前が効かず毎回新しいタブになる。11-09 に Chrome で確かめた)。
   * 窓は同じオリジンのこのサイトのページ。opener は窓の側(preview-main.js)が読み込んだ直後に自分で切る
   * (こちらで win.opener = null としても、窓が読み込み直すと戻っていた)
   */
  vscode.commands.registerCommand('km.preview', async () => {
    await vscode.workspace.saveAll(false);
    window.open(`/code/preview.php?p=${encodeURIComponent(project.id)}`, `km-code-preview-${project.id}`)?.focus();
  });
  try {
    const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(REVEAL_CHANNEL) : null;
    channel?.addEventListener('message', (event) => {
      const target = revealTarget(event.data, project.id);
      if (!target) {
        return;
      }
      const at = target.line > 0 ? new vscode.Position(target.line - 1, Math.max(0, target.col - 1)) : null;
      vscode.window.showTextDocument(vscode.Uri.file(absPath(target.path)), at ? { selection: new vscode.Range(at, at) } : {}).then(undefined, () => {});
    });
  } catch {
    // BroadcastChannel が無ければ、コンソールから開く動きだけが無くなる
  }

  // このエディタについて(段 11-6。ライセンスの表示)。読むだけの文書(km-about:)を Markdown のプレビューで見せる
  vscode.workspace.registerTextDocumentContentProvider('km-about', { provideTextDocumentContent: () => aboutMarkdown(lang) });
  vscode.commands.registerCommand('km.about', async () => {
    const uri = vscode.Uri.parse('km-about:/about.md');
    try {
      await vscode.commands.executeCommand('markdown.showPreview', uri);
    } catch {
      await vscode.window.showTextDocument(uri);
    }
  });
  const aboutItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, -1000);
  aboutItem.text = '$(info)';
  aboutItem.tooltip = t.about;
  aboutItem.command = 'km.about';
  aboutItem.show();

  const previewItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 99);
  previewItem.text = `$(open-preview) ${t.previewShort}`;
  previewItem.tooltip = t.preview;
  previewItem.command = 'km.preview';
  previewItem.show();

  const item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 100);
  item.text = `$(repo) ${project.name}`;
  item.tooltip = t.open;
  item.command = 'km.openProject';
  item.show();
  return { setProjectName(name) { item.text = `$(repo) ${name}`; } };
}
