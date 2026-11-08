/*
 * 設定の値(2026-11-08)。**純粋関数だけ**(node で試す: src/scripts/js/editor-core.test.js)。
 *
 *   プレビューの更新間隔 … 0〜1000ms・既定 250(計画書 3-5)。範囲の外・数でないものは丸める
 *   自動更新          … 既定 ON。OFF なら手動の「実行」だけ
 *   左右の幅          … 編集の欄の割合 20〜80%(既定 50)
 *   見た目            … 'auto'(OS に合わせる)/ 'light' / 'dark'
 *   言語              … 'ja' / 'en'(既定は選び方の順で決める。i18n.js)
 */
export const DELAY_MIN = 0;
export const DELAY_MAX = 1000;
export const DELAY_DEFAULT = 250;
export const SPLIT_MIN = 20;
export const SPLIT_MAX = 80;
export const SPLIT_DEFAULT = 50;
// 段 10: ハイコントラスト(明・暗)を足した。Monaco の hc-light・hc-black と揃える
export const THEMES = ['auto', 'light', 'dark', 'hc-light', 'hc-dark'];
export const MONACO_THEMES = { light: 'vs', dark: 'vs-dark', 'hc-light': 'hc-light', 'hc-dark': 'hc-black' };
export const SETTINGS_KEY = 'km-editor-settings-v2';
export const SIDEBAR_MIN = 140;
export const SIDEBAR_MAX = 480;
export const SIDEBAR_DEFAULT = 220;

/** 'auto' を OS の明暗で決める */
export function resolveTheme(theme, prefersDark) {
  if (theme === 'auto' || !THEMES.includes(theme)) {
    return prefersDark ? 'dark' : 'light';
  }
  return theme;
}

export function clampSidebar(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) {
    return SIDEBAR_DEFAULT;
  }
  return Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, Math.round(n)));
}

export function clampDelay(value) {
  const n = typeof value === 'number' ? value : Number(String(value ?? '').trim());
  if (!Number.isFinite(n) || String(value ?? '').trim() === '') {
    return DELAY_DEFAULT;
  }
  return Math.min(DELAY_MAX, Math.max(DELAY_MIN, Math.round(n)));
}

export function clampSplit(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) {
    return SPLIT_DEFAULT;
  }
  return Math.min(SPLIT_MAX, Math.max(SPLIT_MIN, Math.round(n * 10) / 10));
}

export function defaultSettings() {
  return { delay: DELAY_DEFAULT, autoRun: true, split: SPLIT_DEFAULT, theme: 'auto', tab: 'html' };
}

/** 保存から読んだもの(形が崩れていてもよい)を、使える設定に直す(段 9 の形。km-editor-v1 の中) */
export function normalizeSettings(raw) {
  const base = defaultSettings();
  if (!raw || typeof raw !== 'object') {
    return base;
  }
  return {
    delay: 'delay' in raw ? clampDelay(raw.delay) : base.delay,
    autoRun: typeof raw.autoRun === 'boolean' ? raw.autoRun : base.autoRun,
    split: 'split' in raw ? clampSplit(raw.split) : base.split,
    theme: THEMES.includes(raw.theme) ? raw.theme : base.theme,
    tab: ['html', 'css', 'js'].includes(raw.tab) ? raw.tab : base.tab,
  };
}

/**
 * 段 10 の設定(localStorage `km-editor-settings-v2`)。プロジェクトの中身は IndexedDB(project/db.js)。
 *   keys        … ショートカット(keybindings.js の normalizeKeymap で直す。ここでは物の形だけ見る)
 *   sidebar     … ファイルの欄の幅(px)・sidebarOpen … 開いているか
 *   lastProject … 最後に開いたプロジェクトの id
 */
export function defaultSettingsV2() {
  return { delay: DELAY_DEFAULT, autoRun: true, split: SPLIT_DEFAULT, theme: 'auto', keys: {}, sidebar: SIDEBAR_DEFAULT, sidebarOpen: true, lastProject: '' };
}

export function normalizeSettingsV2(raw) {
  const base = defaultSettingsV2();
  if (!raw || typeof raw !== 'object') {
    return base;
  }
  return {
    delay: 'delay' in raw ? clampDelay(raw.delay) : base.delay,
    autoRun: typeof raw.autoRun === 'boolean' ? raw.autoRun : base.autoRun,
    split: 'split' in raw ? clampSplit(raw.split) : base.split,
    theme: THEMES.includes(raw.theme) ? raw.theme : base.theme,
    keys: raw.keys && typeof raw.keys === 'object' && !Array.isArray(raw.keys) ? { ...raw.keys } : {},
    sidebar: 'sidebar' in raw ? clampSidebar(raw.sidebar) : base.sidebar,
    sidebarOpen: typeof raw.sidebarOpen === 'boolean' ? raw.sidebarOpen : base.sidebarOpen,
    lastProject: typeof raw.lastProject === 'string' && /^[0-9a-f]{1,64}$/.test(raw.lastProject) ? raw.lastProject : '',
  };
}

function local() {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

/** 段 10 の設定を読む。無ければ段 9 の設定(km-editor-v1 の settings)を引き継ぐ */
export function loadSettingsV2(legacy = null) {
  const s = local();
  try {
    const text = s?.getItem(SETTINGS_KEY);
    if (text) {
      return normalizeSettingsV2(JSON.parse(text));
    }
  } catch {
    // 壊れていれば既定
  }
  return normalizeSettingsV2(legacy ? { ...legacy } : null);
}

export function saveSettingsV2(settings) {
  const s = local();
  if (!s) {
    return false;
  }
  try {
    s.setItem(SETTINGS_KEY, JSON.stringify(normalizeSettingsV2(settings)));
    return true;
  } catch {
    return false;
  }
}

/**
 * デバウンス(計画書 5-2)。0 は待たずにすぐ、1〜1000 は最後の入力からその時間のあと。
 * 時計は差し替えられる(試験で setTimeout を偽物にするため)。
 * **既定は関数で包む。** `{ set: setTimeout }` のまま `timers.set(…)` と呼ぶと、ブラウザでは this が window でないため
 * 「Illegal invocation」で落ちる(node では落ちないので試験では気付けない。2026-11-08 に検証機の Chrome で見つけた)。
 */
const DEFAULT_TIMERS = {
  set: (fn, ms) => globalThis.setTimeout(fn, ms),
  clear: (handle) => globalThis.clearTimeout(handle),
};

export function createDebouncer(run, getDelay, timers = DEFAULT_TIMERS) {
  let handle = null;
  return {
    schedule() {
      if (handle !== null) {
        timers.clear(handle);
        handle = null;
      }
      const delay = clampDelay(getDelay());
      if (delay === 0) {
        run();
        return;
      }
      handle = timers.set(() => {
        handle = null;
        run();
      }, delay);
    },
    cancel() {
      if (handle !== null) {
        timers.clear(handle);
        handle = null;
      }
    },
    pending() {
      return handle !== null;
    },
  };
}
