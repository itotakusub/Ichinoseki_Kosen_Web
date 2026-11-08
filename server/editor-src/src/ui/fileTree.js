/*
 * 左の「ファイル」の欄(段 10。計画書 21)。木の形(フォルダが先・名前順。model.js の buildTree)。
 *
 * 行は押すと開く(フォルダは開け閉め)。行の右に操作のボタン(フォルダ: 中に新しいファイル・名前の変更・削除、
 * ファイル: 名前の変更・削除)。入口(プレビューの初めに開く HTML)には印を付ける。
 * ボタンにするだけで、木の役割(role=tree)は付けない(キーの動きを全部作らないなら、付けないほうが正しく読まれる)。
 */
import { el } from './dom.js';

/**
 * @param {HTMLElement} container
 * @param {object} tree buildTree の結果
 * @param {{active: string, entry: string, expanded: Set<string>, t: (key: string, vars?: object) => string,
 *          onOpen: (path: string) => void, onToggle: (folder: string) => void,
 *          onAction: (kind: 'newFile'|'rename'|'delete', path: string, isFolder: boolean) => void}} opts
 */
export function renderFileTree(container, tree, opts) {
  const list = el('ul', { className: 'wce-tree' });
  const actionBtn = (kind, label, symbol, path, isFolder) => el('button', {
    className: `wce-node-act wce-node-act-${kind}`,
    text: symbol,
    attrs: { type: 'button', title: label, 'aria-label': label },
    on: {
      click: (event) => {
        event.stopPropagation();
        opts.onAction(kind, path, isFolder);
      },
    },
  });

  const addFolder = (parent, folder, depth) => {
    const open = opts.expanded.has(folder.path);
    const main = el('button', {
      className: 'wce-node-main',
      attrs: { type: 'button', 'aria-expanded': String(open) },
      on: { click: () => opts.onToggle(folder.path) },
    }, [
      el('span', { className: 'wce-node-icon', text: open ? '▾' : '▸', attrs: { 'aria-hidden': 'true' } }),
      el('span', { className: 'wce-node-name', text: folder.name }),
    ]);
    const row = el('div', { className: 'wce-node wce-node-folder', dataset: { depth } }, [
      main,
      el('span', { className: 'wce-node-acts' }, [
        actionBtn('newFile', opts.t('files.newIn', { name: folder.name }), '+', folder.path, true),
        actionBtn('rename', opts.t('files.renameOf', { name: folder.name }), '✎', folder.path, true),
        actionBtn('delete', opts.t('files.deleteOf', { name: folder.name }), '×', folder.path, true),
      ]),
    ]);
    const li = el('li', {}, [row]);
    if (open) {
      const child = el('ul', { className: 'wce-tree' });
      addChildren(child, folder, depth + 1);
      li.append(child);
    }
    parent.append(li);
  };

  const addFile = (parent, file, depth) => {
    const isActive = file.path === opts.active;
    const isEntry = file.path === opts.entry;
    const main = el('button', {
      className: 'wce-node-main',
      attrs: { type: 'button', 'aria-current': isActive ? 'true' : false, title: file.path },
      on: { click: () => opts.onOpen(file.path) },
    }, [
      el('span', { className: 'wce-node-icon', text: iconFor(file.name), attrs: { 'aria-hidden': 'true' } }),
      el('span', { className: 'wce-node-name', text: file.name }),
      isEntry ? el('span', { className: 'wce-node-entry', text: opts.t('files.entryMark'), attrs: { title: opts.t('files.entryTitle') } }) : null,
    ]);
    const row = el('div', { className: `wce-node wce-node-file${isActive ? ' is-active' : ''}`, dataset: { depth } }, [
      main,
      el('span', { className: 'wce-node-acts' }, [
        actionBtn('rename', opts.t('files.renameOf', { name: file.name }), '✎', file.path, false),
        actionBtn('delete', opts.t('files.deleteOf', { name: file.name }), '×', file.path, false),
      ]),
    ]);
    parent.append(el('li', {}, [row]));
  };

  const addChildren = (parent, node, depth) => {
    for (const folder of node.folders) {
      addFolder(parent, folder, depth);
    }
    for (const file of node.files) {
      addFile(parent, file, depth);
    }
  };

  addChildren(list, tree, 0);
  if (tree.folders.length === 0 && tree.files.length === 0) {
    list.append(el('li', { className: 'wce-tree-empty', text: opts.t('files.empty') }));
  }
  container.replaceChildren(list);
}

function iconFor(name) {
  const ext = (/\.([^.]+)$/.exec(name)?.[1] ?? '').toLowerCase();
  if (ext === 'html' || ext === 'htm') {
    return '<>';
  }
  if (ext === 'css') {
    return '#';
  }
  if (ext === 'js' || ext === 'mjs') {
    return 'JS';
  }
  if (ext === 'json') {
    return '{}';
  }
  if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp'].includes(ext)) {
    return '▣';
  }
  return '·';
}
