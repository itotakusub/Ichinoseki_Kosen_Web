/*
 * 開いたファイルのタブ(段 10。計画書 4-3「タブの追加と削除」)。× で閉じる・中ボタンでも閉じる。
 * 左右の矢印で選ぶ(WAI-ARIA の tablist。選んだタブだけ tabIndex 0)。
 * 書きかけ(まだ保存していない)のタブには ● を付ける。
 */
import { el } from './dom.js';

/**
 * @param {HTMLElement} container role=tablist の入れ物
 * @param {{open: string[], active: string, dirty: Set<string>, t: Function,
 *          onSelect: (path: string, focus: boolean) => void, onClose: (path: string) => void}} opts
 */
export function renderTabs(container, opts) {
  const nodes = opts.open.map((path, index) => {
    const name = path.split('/').pop();
    const selected = path === opts.active;
    const tab = el('button', {
      className: 'wce-tab',
      attrs: {
        type: 'button',
        role: 'tab',
        id: `wce-tab-${index}`,
        'aria-selected': String(selected),
        tabindex: selected ? '0' : '-1',
        title: path,
      },
      dataset: { path },
      on: {
        click: () => opts.onSelect(path, true),
        auxclick: (event) => {
          if (event.button === 1) {
            event.preventDefault();
            opts.onClose(path);
          }
        },
        keydown: (event) => {
          if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            const step = event.key === 'ArrowRight' ? 1 : opts.open.length - 1;
            const next = opts.open[(index + step) % opts.open.length];
            opts.onSelect(next, false);
            container.querySelector(`.wce-tab[data-path="${CSS.escape(next)}"]`)?.focus();
          } else if (event.key === 'Delete') {
            event.preventDefault();
            opts.onClose(path);
          }
        },
      },
    }, [
      el('span', { className: 'wce-tab-name', text: name }),
      opts.dirty.has(path) ? el('span', { className: 'wce-tab-dirty', text: '●', attrs: { 'aria-label': opts.t('tabs.unsaved') } }) : null,
    ]);
    const close = el('button', {
      className: 'wce-tab-close',
      text: '×',
      attrs: { type: 'button', tabindex: '-1', title: opts.t('tabs.closeOf', { name }), 'aria-label': opts.t('tabs.closeOf', { name }) },
      on: {
        click: (event) => {
          event.stopPropagation();
          opts.onClose(path);
        },
      },
    });
    return el('div', { className: `wce-tab-wrap${selected ? ' is-active' : ''}`, attrs: { role: 'presentation' } }, [tab, close]);
  });
  container.replaceChildren(...nodes);
  const activeIndex = opts.open.indexOf(opts.active);
  return activeIndex >= 0 ? `wce-tab-${activeIndex}` : '';
}
