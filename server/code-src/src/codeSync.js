/*
 * VS Code 型のエディタ(/code/)のサーバーとの同期(段 11-4。2026-11-09)。
 *
 * 進め方は /editor/ と同じ部品(editor-src/src/project/syncManager.js)。口は api/editor.php、規約は第8条の3。
 * ここは VS Code の画面との受け渡しだけ:
 *   - 下の帯(ステータスバー)に状態。押すと、ぶつかっていれば選ぶ画面、そうでなければ同期のメニュー(クイックピック)
 *   - サインインしていなければ「サインイン」(/sign-in.php。戻り先は /code/)
 *   - 書いたら(projectFs の onPersist)3 秒待って送る・保存(Ctrl+S)で待たずに送る・タブを隠したら送る・戻ったら突き合わせる
 * 文言は /editor/ の i18n.js をそのまま使う(sync.*・badge.*・conflict.*)
 */
import { ExtensionHostKind, registerExtension } from '@codingame/monaco-vscode-api/extensions';
import { t } from '../../editor-src/src/i18n.js';
import { createSyncManager } from '../../editor-src/src/project/syncManager.js';
import { formatBytes } from '../../editor-src/src/ui/dom.js';

const ICONS = {
  idle: 'sync',
  synced: 'cloud',
  pending: 'cloud-upload',
  sending: 'sync~spin',
  failed: 'error',
  offline: 'debug-disconnect',
  conflict: 'warning',
  off: 'circle-slash',
  gone: 'circle-slash',
  blocked: 'circle-slash',
};

const MENU = {
  ja: {
    category: 'プロジェクト',
    menu: 'サーバーとの同期…',
    now: 'いま送る',
    refresh: 'サーバーと突き合わせる',
    pullServer: 'サーバーにだけあるプロジェクトを読み込む…',
    resolve: 'ほかの端末との違いを片付ける…',
    none: 'サーバーにだけあるプロジェクトはありません',
    pickServer: '読み込むプロジェクトを選んでください',
    pickMenu: 'サーバーとの同期',
    usage: (used, max, n, maxN) => `サーバーで使っている量: ${used} / ${max}・${n} / ${maxN} 個`,
    signInTitle: t('ja', 'sync.signInTitle'),
  },
  en: {
    category: 'Project',
    menu: 'Sync with the Server…',
    now: 'Send Now',
    refresh: 'Check the Server',
    pullServer: 'Load a Project from the Server…',
    resolve: 'Resolve Changes from Another Device…',
    none: 'No projects are only on the server',
    pickServer: 'Choose a project to load',
    pickMenu: 'Sync with the server',
    usage: (used, max, n, maxN) => `Server usage: ${used} / ${max}, ${n} / ${maxN} projects`,
    signInTitle: t('en', 'sync.signInTitle'),
  },
};

/**
 * @param {{store: object, fs: object, lang: 'ja'|'en', csrf: string, signedIn: boolean, onProjectName: (name: string) => void}} ctx
 */
export async function registerSync({ store, fs, lang, csrf, signedIn, onProjectName }) {
  const m = MENU[lang] ?? MENU.ja;
  const tr = (key, vars) => t(lang, key, vars);
  const reg = registerExtension({
    name: 'km-sync',
    publisher: 'ito4',
    displayName: 'Web Code Editor Sync',
    version: '1.0.0',
    engines: { vscode: '*' },
    contributes: {
      commands: [
        { command: 'km.sync.menu', title: m.menu, category: m.category },
        { command: 'km.sync.now', title: m.now, category: m.category },
        { command: 'km.sync.refresh', title: m.refresh, category: m.category },
        { command: 'km.sync.pullServer', title: m.pullServer, category: m.category },
        { command: 'km.sync.resolve', title: m.resolve, category: m.category },
      ],
    },
  }, ExtensionHostKind.LocalProcess, { system: true });
  const vscode = await reg.getApi();

  const item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 98);

  const sync = createSyncManager({
    store,
    csrf,
    signedIn,
    host: {
      current: () => fs.project,
      // 開いているエディタの打ちかけを保存し、ファイルの口の書き込みが置き場に届くまで待つ
      flush: async () => {
        await vscode.workspace.saveAll(false);
        await fs.idle();
      },
      currentFiles: () => null,
      stamp: () => fs.stamp,
      updateRecord: async (id, fn) => {
        if (id === fs.project.id) {
          await fs.mutateRecord(fn);
          return;
        }
        const record = await store.getProject(id);
        if (record) {
          fn(record);
          await store.putProject(record);
        }
      },
      // サーバーのもので置き換えたあと: 置き場から読み直して VS Code へ知らせる(開いているエディタも読み直す)
      afterPull: async (_id, paths) => {
        await fs.reloadFromStore(paths);
        onProjectName(fs.project.name);
      },
      afterRename: () => onProjectName(fs.project.name),
      localName: (name) => tr('conflict.localName', { name }),
      untitled: () => tr('project.untitled'),
      onChange: () => render(),
    },
  });

  function render() {
    if (!signedIn) {
      item.text = `$(account) ${tr('sync.signIn')}`;
      item.tooltip = m.signInTitle;
      item.command = 'km.sync.signIn';
      item.show();
      return;
    }
    const { state, title } = sync.status();
    item.text = `$(${ICONS[state] ?? 'cloud'}) ${tr(`sync.${state}`)}`;
    item.tooltip = title ?? tr('sync.title').replace(/[((].*[))]/, '').trim();
    item.command = state === 'conflict' ? 'km.sync.resolve' : 'km.sync.menu';
    item.show();
  }

  async function resolveCurrent() {
    const record = fs.project;
    if (!sync.hasConflict(record.id)) {
      return;
    }
    const pick = await vscode.window.showWarningMessage(tr('conflict.text', { name: record.name }), { modal: true },
      tr('conflict.mine'), tr('conflict.theirs'), tr('conflict.both'));
    const choice = { [tr('conflict.mine')]: 'mine', [tr('conflict.theirs')]: 'theirs', [tr('conflict.both')]: 'both' }[pick ?? ''];
    if (!choice) {
      return;
    }
    const made = await sync.resolve(record.id, choice);
    if (made) {
      // 「両方残す」: サーバーのものは新しいプロジェクトになった。開くかどうかは利用者が選ぶ(プロジェクトを開く…)
      vscode.window.showInformationMessage(tr('conflict.both'));
    }
  }

  async function pullServer() {
    if (!sync.canSync) {
      await sync.refresh();
    }
    const list = await sync.serverOnly();
    if (list.length === 0) {
      vscode.window.showInformationMessage(m.none);
      return;
    }
    const pick = await vscode.window.showQuickPick(list.map((r) => ({ id: r.id, label: r.name, description: tr('badge.server') })), { placeHolder: m.pickServer });
    if (!pick) {
      return;
    }
    let made = null;
    await sync.queue(async () => {
      made = await sync.pullNew(pick.id);
    });
    if (made) {
      const url = new URL(location.href);
      url.searchParams.set('p', made);
      location.assign(url);
    }
  }

  vscode.commands.registerCommand('km.sync.signIn', () => {
    location.assign('/sign-in.php?mode=signIn&return=/code/');
  });
  vscode.commands.registerCommand('km.sync.now', () => sync.pushNow());
  vscode.commands.registerCommand('km.sync.refresh', () => sync.refresh());
  vscode.commands.registerCommand('km.sync.pullServer', pullServer);
  vscode.commands.registerCommand('km.sync.resolve', resolveCurrent);
  vscode.commands.registerCommand('km.sync.menu', async () => {
    const items = [
      { id: 'now', label: `$(cloud-upload) ${m.now}` },
      { id: 'refresh', label: `$(refresh) ${m.refresh}` },
      { id: 'pull', label: `$(cloud-download) ${m.pullServer}` },
    ];
    if (sync.hasConflict(fs.project.id)) {
      items.unshift({ id: 'resolve', label: `$(warning) ${m.resolve}` });
    }
    const usage = sync.usage;
    const pick = await vscode.window.showQuickPick(items, {
      placeHolder: usage.limits ? m.usage(formatBytes(usage.used, lang), formatBytes(usage.limits.ownerBytes ?? 0, lang), sync.remoteById.size, usage.limits.projects ?? 0) : m.pickMenu,
    });
    const run = { now: () => sync.pushNow(), refresh: () => sync.refresh(), pull: pullServer, resolve: resolveCurrent }[pick?.id ?? ''];
    await run?.();
  });

  // 書いたら少し待って送る・保存したら待たずに送る
  fs.onPersist = () => sync.schedule();
  // 自動保存(0.8 秒)では送らず、打つのを止めて 3 秒後(schedule)。手で保存したとき(Ctrl+S)だけ待たずに送る
  let manualSave = false;
  vscode.workspace.onWillSaveTextDocument((event) => {
    if (event.reason === vscode.TextDocumentSaveReason.Manual) {
      manualSave = true;
    }
  });
  vscode.workspace.onDidSaveTextDocument(() => {
    if (manualSave) {
      manualSave = false;
      fs.idle().then(() => sync.pushNow());
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      sync.pushNow();
    } else {
      sync.refreshIfStale();
    }
  });

  render();
  if (signedIn) {
    sync.refresh();
  }
  return sync;
}
