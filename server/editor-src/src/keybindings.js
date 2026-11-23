/*
 * ショートカット(段 10。計画書 25「キーボード操作を拡張できる」。2026-11-08)。**純粋関数だけ**(node で試す)。
 *
 * - キーは "Ctrl+Shift+Alt+キー" の形の文字列。キーは event.code から作る(配列の違うキーボードでも同じ所)。
 *   Mac の ⌘ は Ctrl として扱う
 * - 付けられないもの:
 *     browser … ブラウザが先に使うキー(Ctrl+W・T・N・Tab・L など。押してもページに届かない・タブが閉じる)
 *     editor  … 文字を直すときのキー(Ctrl+Z・C・V・F など。取ると編集ができなくなる)
 *     modifier… Ctrl か Alt を含まない(F1〜F12 は単独でよい)
 *     taken   … ほかの操作に付いている
 * - 空("")は「付けない」
 */
export const ACTIONS = ['run', 'save', 'newFile', 'quickOpen', 'nextTab', 'prevTab', 'closeTab', 'projects', 'toggleFiles', 'format'];

export const DEFAULT_KEYS = Object.freeze({
  run: 'Ctrl+Enter',
  save: 'Ctrl+S',
  newFile: 'Alt+N',
  quickOpen: 'Ctrl+P',
  nextTab: 'Alt+PageDown',
  prevTab: 'Alt+PageUp',
  closeTab: 'Alt+W',
  projects: 'Alt+O',
  toggleFiles: 'Ctrl+B',
  format: 'Shift+Alt+F',
});

const BROWSER = new Set([
  'Ctrl+W', 'Ctrl+Shift+W', 'Ctrl+T', 'Ctrl+Shift+T', 'Ctrl+N', 'Ctrl+Shift+N', 'Ctrl+Tab', 'Ctrl+Shift+Tab',
  'Ctrl+PageUp', 'Ctrl+PageDown', 'Ctrl+L', 'Ctrl+Q', 'Ctrl+R', 'Ctrl+Shift+R', 'F5', 'Ctrl+F5', 'F11', 'F12',
  'Ctrl+Shift+I', 'Ctrl+Shift+J', 'Ctrl+Shift+C', 'Ctrl+U', 'Ctrl+H', 'Ctrl+J', 'Ctrl+Shift+Delete', 'Ctrl+Shift+B',
  'Ctrl+D', 'Ctrl+O', 'Ctrl+=', 'Ctrl+-', 'Ctrl+0', 'Ctrl+Shift+=', 'Alt+ArrowLeft', 'Alt+ArrowRight', 'Alt+Home', 'Alt+F4',
  'Alt+F', 'Alt+E', 'Alt+D', 'Ctrl+E', 'Ctrl+K', 'Ctrl+G', 'Ctrl+M', 'Ctrl+Shift+M', 'Ctrl+Shift+O', 'Ctrl+Shift+Q',
  'Ctrl+1', 'Ctrl+2', 'Ctrl+3', 'Ctrl+4', 'Ctrl+5', 'Ctrl+6', 'Ctrl+7', 'Ctrl+8', 'Ctrl+9',
]);

const EDITOR = new Set([
  'Ctrl+Z', 'Ctrl+Y', 'Ctrl+Shift+Z', 'Ctrl+C', 'Ctrl+V', 'Ctrl+X', 'Ctrl+A', 'Ctrl+F', 'Ctrl+/', 'Ctrl+]', 'Ctrl+[',
  'Tab', 'Shift+Tab', 'Ctrl+Space', 'Ctrl+Shift+K', 'Ctrl+Shift+Space', 'Ctrl+I', 'Shift+Alt+ArrowUp', 'Shift+Alt+ArrowDown',
  'Alt+ArrowUp', 'Alt+ArrowDown', 'Ctrl+Backspace', 'Ctrl+Delete', 'Ctrl+ArrowLeft', 'Ctrl+ArrowRight', 'Ctrl+Home', 'Ctrl+End',
]);

const CODE_NAMES = {
  Enter: 'Enter', NumpadEnter: 'Enter', Space: 'Space', Escape: 'Escape', Tab: 'Tab', Backspace: 'Backspace', Delete: 'Delete',
  Insert: 'Insert', Home: 'Home', End: 'End', PageUp: 'PageUp', PageDown: 'PageDown',
  ArrowUp: 'ArrowUp', ArrowDown: 'ArrowDown', ArrowLeft: 'ArrowLeft', ArrowRight: 'ArrowRight',
  Comma: ',', Period: '.', Slash: '/', Backslash: '\\', Semicolon: ';', Quote: "'", Backquote: '`',
  BracketLeft: '[', BracketRight: ']', Minus: '-', Equal: '=', IntlRo: '\\', IntlYen: '¥',
};

function keyName(code) {
  if (/^Key[A-Z]$/.test(code)) {
    return code.slice(3);
  }
  if (/^Digit[0-9]$/.test(code)) {
    return code.slice(5);
  }
  if (/^Numpad[0-9]$/.test(code)) {
    return code.slice(6);
  }
  if (/^F([1-9]|1[0-2])$/.test(code)) {
    return code;
  }
  return CODE_NAMES[code] ?? null;
}

const MOD_ORDER = ['Ctrl', 'Shift', 'Alt'];

function compose(mods, key) {
  return [...MOD_ORDER.filter((m) => mods.has(m)), key].join('+');
}

/** キーを押したときの文字列。修飾キーだけ・知らないキーは null */
export function keyFromEvent(event) {
  const key = keyName(String(event.code ?? ''));
  if (!key) {
    return null;
  }
  const mods = new Set();
  if (event.ctrlKey || event.metaKey) {
    mods.add('Ctrl');
  }
  if (event.shiftKey) {
    mods.add('Shift');
  }
  if (event.altKey) {
    mods.add('Alt');
  }
  return compose(mods, key);
}

/** 書き方を整える("alt + n" → "Alt+N")。読めなければ null */
export function normalizeKey(text) {
  const raw = String(text ?? '').trim();
  if (raw === '') {
    return '';
  }
  const parts = raw.split('+').map((p) => p.trim()).filter((p, i, all) => p !== '' || i === all.length - 1);
  // "Ctrl++" のように + そのものは使わない
  const mods = new Set();
  let key = null;
  for (const part of parts) {
    const lower = part.toLowerCase();
    if (lower === 'ctrl' || lower === 'control' || lower === 'cmd' || lower === 'meta') {
      mods.add('Ctrl');
    } else if (lower === 'shift') {
      mods.add('Shift');
    } else if (lower === 'alt' || lower === 'option') {
      mods.add('Alt');
    } else if (key === null) {
      key = /^[a-z]$/i.test(part) ? part.toUpperCase() : (/^f([1-9]|1[0-2])$/i.test(part) ? part.toUpperCase() : part);
      const known = new Set([...Object.values(CODE_NAMES), ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'.split('')]);
      if (!known.has(key) && !/^F([1-9]|1[0-2])$/.test(key)) {
        return null;
      }
    } else {
      return null;
    }
  }
  return key === null ? null : compose(mods, key);
}

/** 付けられるか。付けられれば null、だめなら理由 */
export function checkKey(key) {
  if (key === '') {
    return null;
  }
  const k = normalizeKey(key);
  if (k === null) {
    return 'invalid';
  }
  if (BROWSER.has(k)) {
    return 'browser';
  }
  if (EDITOR.has(k)) {
    return 'editor';
  }
  const fKey = /(^|\+)F([1-9]|1[0-2])$/.test(k);
  if (!fKey && !k.startsWith('Ctrl+') && !/(^|\+)Alt\+/.test(k) && !k.startsWith('Alt+')) {
    return 'modifier';
  }
  return null;
}

/**
 * 保存から読んだ割り当てを使える形に直す。読めない・付けられない・重なるものは既定へ戻す
 * (既定も重なるなら付けない)。
 */
export function normalizeKeymap(raw) {
  const out = {};
  const used = new Map();
  const source = raw && typeof raw === 'object' ? raw : {};
  for (const action of ACTIONS) {
    let key = action in source ? normalizeKey(source[action]) : DEFAULT_KEYS[action];
    if (key === null || (key !== '' && checkKey(key) !== null)) {
      key = DEFAULT_KEYS[action];
    }
    if (key !== '' && used.has(key)) {
      key = DEFAULT_KEYS[action] !== key && !used.has(DEFAULT_KEYS[action]) ? DEFAULT_KEYS[action] : '';
    }
    out[action] = key;
    if (key !== '') {
      used.set(key, action);
    }
  }
  return out;
}

/** action に key を付けたらどうなるか。{ok: true, keymap} か {ok: false, reason, other?} */
export function assignKey(keymap, action, key) {
  if (!ACTIONS.includes(action)) {
    return { ok: false, reason: 'invalid' };
  }
  const k = key === '' ? '' : normalizeKey(key);
  if (k === null) {
    return { ok: false, reason: 'invalid' };
  }
  const reason = checkKey(k);
  if (reason) {
    return { ok: false, reason };
  }
  if (k !== '') {
    const other = ACTIONS.find((a) => a !== action && keymap[a] === k);
    if (other) {
      return { ok: false, reason: 'taken', other };
    }
  }
  return { ok: true, keymap: { ...keymap, [action]: k } };
}

export function actionForKey(keymap, key) {
  if (!key) {
    return null;
  }
  return ACTIONS.find((a) => keymap[a] === key) ?? null;
}
