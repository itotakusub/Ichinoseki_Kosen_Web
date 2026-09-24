/*!
 * KosenMap Admin — 言語切り替え (日本語 / English)
 *
 * AdminLTE には一切手を入れず、adminlte.js の ColorMode と同じ流儀で実装する。
 *   localStorage キー : kmadmin-lang         (AdminLTE の lte-theme とは別)
 *   トグルの属性      : data-km-lang-value   (AdminLTE の data-bs-theme-value とは別)
 *   <html> の属性     : lang + data-km-lang  (AdminLTE の data-bs-theme とは別)
 * 名前空間が重ならないので、テーマ切り替えとは互いに干渉しない。
 *
 * HTML には日本語を原文として書く。辞書にキーが無い言語へ切り替えたときは
 * 原文へ戻すので、辞書が未整備でも画面が空にならない。
 */
(() => {
  'use strict';

  const STORAGE_KEY = 'kmadmin-lang';
  const ATTR_HTML_LANG = 'data-km-lang';
  const ATTR_TOGGLE = 'data-km-lang-value';
  const SELECTOR_TOGGLE = `[${ATTR_TOGGLE}]`;
  const SELECTOR_LABEL = '[data-km-lang-label]';
  const EVENT_CHANGED = 'changed.km.lang';
  const SUPPORTED = ['ja', 'en'];
  const FALLBACK = 'ja';
  const NATIVE_LABEL = { ja: '日本語', en: 'English' };

  const root = document.documentElement;

  // 置換前の原文。辞書に無いキーはここへ戻す。
  const originalText = new WeakMap();
  const originalHtml = new WeakMap();
  const originalAttr = new WeakMap();
  let originalTitle = null;

  const isSupported = (lang) => SUPPORTED.includes(lang);
  const dictionaries = () => globalThis.KM_I18N ?? {};

  const readStored = () => {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch {
      // localStorage が使えない場合 (プライベートモード、サンドボックス iframe)
      return null;
    }
  };

  const writeStored = (lang) => {
    try {
      localStorage.setItem(STORAGE_KEY, lang);
    } catch {
      // 保存できなくても切り替え自体は動かす
    }
  };

  // 優先順位: 保存値 > ページが宣言した値 > ブラウザの言語設定 > ja
  // <head> の no-flash スニペットと同じ順序にしてある。
  const resolveLang = () => {
    const stored = readStored();
    if (isSupported(stored)) {
      return stored;
    }
    const authored = root.getAttribute(ATTR_HTML_LANG);
    if (isSupported(authored)) {
      return authored;
    }
    return (navigator.language || '').toLowerCase().startsWith('ja') ? 'ja' : 'en';
  };

  const lookup = (lang, key) => {
    const dict = dictionaries()[lang];
    return dict && Object.hasOwn(dict, key) ? dict[key] : null;
  };

  const findTextNode = (element) => {
    for (const node of element.childNodes) {
      if (node.nodeType === Node.TEXT_NODE && node.nodeValue.trim() !== '') {
        return node;
      }
    }
    return null;
  };

  // 要素の「先頭テキストノードだけ」を置換する。
  // AdminLTE のサイドバーは <p>Dashboard<i class="nav-arrow"></i></p> のように
  // テキストと兄弟要素が混在しているので、textContent で潰すとアイコンや
  // バッジが消える。前後の空白も原文のまま残してレイアウトを保つ。
  const applyText = (element, value) => {
    const node = findTextNode(element);
    if (!node) {
      if (value !== null) {
        element.prepend(document.createTextNode(value));
      }
      return;
    }
    if (!originalText.has(element)) {
      originalText.set(element, node.nodeValue);
    }
    const original = originalText.get(element);
    if (value === null) {
      node.nodeValue = original;
      return;
    }
    const lead = /^\s*/.exec(original)[0];
    const trail = /\s*$/.exec(original)[0];
    node.nodeValue = lead + value + trail;
  };

  const applyHtml = (element, value) => {
    if (!originalHtml.has(element)) {
      originalHtml.set(element, element.innerHTML);
    }
    element.innerHTML = value === null ? originalHtml.get(element) : value;
  };

  const applyAttr = (element, attribute, value) => {
    let store = originalAttr.get(element);
    if (!store) {
      store = new Map();
      originalAttr.set(element, store);
    }
    if (!store.has(attribute)) {
      store.set(attribute, element.getAttribute(attribute));
    }
    if (value !== null) {
      element.setAttribute(attribute, value);
      return;
    }
    const original = store.get(attribute);
    if (original === null) {
      element.removeAttribute(attribute);
    } else {
      element.setAttribute(attribute, original);
    }
  };

  // "placeholder:form.search;aria-label:nav.search" を [attr, key] へ分解する
  const parseAttrSpec = (spec) =>
    spec
      .split(';')
      .map((pair) => pair.trim())
      .filter(Boolean)
      .map((pair) => {
        const separator = pair.indexOf(':');
        if (separator < 0) {
          return null;
        }
        const attribute = pair.slice(0, separator).trim();
        const key = pair.slice(separator + 1).trim();
        return attribute && key ? [attribute, key] : null;
      })
      .filter(Boolean);

  const apply = (lang) => {
    const missing = new Set();
    const translate = (key) => {
      const value = lookup(lang, key);
      if (value === null) {
        missing.add(key);
      }
      return value;
    };

    document.querySelectorAll('[data-i18n]').forEach((element) => {
      applyText(element, translate(element.dataset.i18n));
    });

    document.querySelectorAll('[data-i18n-html]').forEach((element) => {
      applyHtml(element, translate(element.dataset.i18nHtml));
    });

    document.querySelectorAll('[data-i18n-attr]').forEach((element) => {
      parseAttrSpec(element.dataset.i18nAttr).forEach(([attribute, key]) => {
        applyAttr(element, attribute, translate(key));
      });
    });

    const titleKey = root.dataset.i18nTitle;
    if (titleKey) {
      if (originalTitle === null) {
        originalTitle = document.title;
      }
      const value = translate(titleKey);
      document.title = value === null ? originalTitle : value;
    }

    root.setAttribute('lang', lang);
    root.setAttribute(ATTR_HTML_LANG, lang);

    if (missing.size > 0) {
      console.warn(`[km-i18n] "${lang}" に未定義のキーがあります (原文を維持):`, [...missing]);
    }
  };

  // ColorMode._showActiveTheme と同じ形。チェックマークと現在言語ラベルを揃える。
  const syncToggles = (lang) => {
    document.querySelectorAll(SELECTOR_TOGGLE).forEach((toggle) => {
      const isActive = toggle.getAttribute(ATTR_TOGGLE) === lang;
      toggle.classList.toggle('active', isActive);
      toggle.setAttribute('aria-pressed', String(isActive));
      toggle.querySelector('.bi-check-lg')?.classList.toggle('d-none', !isActive);
    });
    document.querySelectorAll(SELECTOR_LABEL).forEach((element) => {
      element.textContent = NATIVE_LABEL[lang];
    });
  };

  const setLang = (lang, { persist = true } = {}) => {
    const next = isSupported(lang) ? lang : FALLBACK;
    if (persist) {
      writeStored(next);
    }
    apply(next);
    syncToggles(next);
    document.dispatchEvent(new CustomEvent(EVENT_CHANGED, { detail: { lang: next } }));
  };

  // ColorMode と同じく document へのイベント委譲。トグルを後から差し込んでも動く。
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const toggle = target.closest(SELECTOR_TOGGLE);
    const lang = toggle?.getAttribute(ATTR_TOGGLE);
    if (lang && isSupported(lang)) {
      event.preventDefault();
      setLang(lang);
    }
  });

  globalThis.KmI18n = {
    supported: [...SUPPORTED],
    get current() {
      const lang = root.getAttribute(ATTR_HTML_LANG);
      return isSupported(lang) ? lang : FALLBACK;
    },
    set: setLang,
    // JS で組み立てる文言用。辞書に無ければキーをそのまま返して欠落に気付けるようにする。
    t(key, lang = this.current) {
      return lookup(lang, key) ?? key;
    },
    event: EVENT_CHANGED,
  };

  const boot = () => {
    // 初回は保存しない。明示的に選んだときだけ localStorage へ残す (ColorMode と同じ)。
    setLang(resolveLang(), { persist: false });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
