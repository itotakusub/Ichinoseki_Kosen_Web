/*
 * 標準のデータ(英語)に、日本語の説明と例を重ねる(2026-11-08)。**純粋関数だけ**(node で試す)。
 *
 * - lang が 'ja' なら、日本語の説明があるものは差し替える。無いものは英語のまま
 * - どちらの言語でも、例があれば説明の下にコードで付ける
 * - 標準のデータは書き換えない(新しいものを返す)
 */
function descText(d) {
  if (typeof d === 'string') {
    return d;
  }
  return d && typeof d.value === 'string' ? d.value : '';
}

function md(value) {
  return { kind: 'markdown', value };
}

function withExample(text, example, codeLang) {
  if (!example) {
    return text;
  }
  return `${text}\n\n\`\`\`${codeLang}\n${example}\n\`\`\``;
}

/**
 * @param {object} std  Monaco の HTML の標準データ(htmlData)
 * @param {object} ja   data/html.js の HTML_JA
 * @param {'ja'|'en'} lang
 */
export function mergeHtmlData(std, ja, lang) {
  const useJa = lang === 'ja';
  const attr = (a, key) => {
    const text = useJa ? ja.attrs[key] : undefined;
    return text ? { ...a, description: md(text) } : a;
  };
  const tags = (std.tags ?? []).map((tag) => {
    const entry = ja.tags[tag.name];
    const out = { ...tag };
    if (entry) {
      out.description = md(withExample(useJa ? entry[0] : descText(tag.description), entry[1], 'html'));
    }
    out.attributes = (tag.attributes ?? []).map((a) => attr(a, `${tag.name}.${a.name}`));
    return out;
  });
  // 標準のデータに無いタグ(search・svg・math など新しいもの)は足す。英語の説明は無いので例だけ
  const known = new Set(tags.map((tag) => tag.name));
  for (const [name, [text, example]] of Object.entries(ja.tags)) {
    if (!known.has(name)) {
      tags.push({ name, description: md(withExample(useJa ? text : `<${name}>`, example, 'html')), attributes: [] });
    }
  }
  // 標準のデータに無い属性(popovertarget・playsinline など)も足す
  const byTag = new Map(tags.map((tag) => [tag.name, tag]));
  for (const [key, text] of Object.entries(ja.attrs)) {
    const [tagName, attrName] = key.split('.');
    const tag = byTag.get(tagName);
    if (tag && !tag.attributes.some((a) => a.name === attrName)) {
      tag.attributes = [...tag.attributes, useJa ? { name: attrName, description: md(text) } : { name: attrName }];
    }
  }
  const globalAttributes = (std.globalAttributes ?? []).map((a) => {
    const text = useJa ? ja.global[a.name] : undefined;
    return text ? { ...a, description: md(text) } : a;
  });
  for (const [name, text] of Object.entries(ja.global)) {
    if (name !== 'data-*' && !globalAttributes.some((a) => a.name === name)) {
      globalAttributes.push(useJa ? { name, description: md(text) } : { name });
    }
  }
  if (useJa && ja.global['data-*'] && !globalAttributes.some((a) => a.name === 'data-*')) {
    globalAttributes.push({ name: 'data-*', description: md(ja.global['data-*']) });
  }
  const valueSets = (std.valueSets ?? []).map((set) => ({
    ...set,
    values: (set.values ?? []).map((v) => {
      const text = useJa ? ja.values[`${set.name}.${v.name}`] : undefined;
      return text ? { ...v, description: md(text) } : v;
    }),
  }));
  return { version: std.version ?? 1.1, tags, globalAttributes, valueSets };
}

/**
 * @param {object} std  Monaco の CSS の標準データ(cssData)
 * @param {object} ja   data/css.js の CSS_JA
 * @param {'ja'|'en'} lang
 */
export function mergeCssData(std, ja, lang) {
  const useJa = lang === 'ja';
  const properties = (std.properties ?? []).map((p) => {
    const entry = ja.props[p.name];
    const out = { ...p };
    if (entry) {
      out.description = md(withExample(useJa ? entry[0] : descText(p.description), entry[1], 'css'));
    }
    if (useJa && Array.isArray(p.values)) {
      out.values = p.values.map((v) => {
        const text = ja.values[`${p.name}.${v.name}`] ?? ja.values[`*.${v.name}`];
        return text ? { ...v, description: md(text) } : v;
      });
    }
    // 標準のデータに値の一覧が無い(または足りない)ものは足す(white-space の nowrap など)
    const prefix = `${p.name}.`;
    for (const [key, text] of Object.entries(ja.values)) {
      if (!key.startsWith(prefix)) {
        continue;
      }
      const name = key.slice(prefix.length);
      const values = out.values ?? [];
      if (!values.some((v) => v.name === name)) {
        out.values = [...values, useJa ? { name, description: md(text) } : { name }];
      }
    }
    return out;
  });
  const byName = (list, table) => (list ?? []).map((item) => {
    const text = useJa ? (table[item.name] ?? table[String(item.name).replace(/\(\)$/, '')]) : undefined;
    return text ? { ...item, description: md(text) } : item;
  });
  const atDirectives = byName(std.atDirectives, ja.at);
  for (const [name, text] of Object.entries(ja.at)) {
    if (!atDirectives.some((a) => a.name === name)) {
      atDirectives.push(useJa ? { name, description: md(text) } : { name });
    }
  }
  return {
    version: std.version ?? 1.1,
    properties,
    atDirectives,
    pseudoClasses: byName(std.pseudoClasses, ja.pseudo),
    pseudoElements: byName(std.pseudoElements, ja.pseudo),
  };
}

/**
 * JS の候補を探す元の表を作る(editor/languages.js が使う)。
 * @returns {{byOwner: Map<string, object[]>, owners: Set<string>}}
 */
export function indexJsApi(api, lang) {
  const byOwner = new Map();
  for (const [owner, name, args, jaText, enText, example] of api) {
    const item = { owner, name, args, doc: lang === 'en' ? enText : jaText, example };
    if (!byOwner.has(owner)) {
      byOwner.set(owner, []);
    }
    byOwner.get(owner).push(item);
  }
  const owners = new Set([...byOwner.keys()].filter((k) => k !== '' && k !== '.'));
  return { byOwner, owners };
}

/** ホバーで引く: 持ち主(無ければ '')と名前から、合う項目を 1 つ */
export function lookupJs(index, owner, name) {
  const tryList = (key) => (index.byOwner.get(key) ?? []).find((item) => item.name === name);
  if (owner && index.owners.has(owner)) {
    return tryList(owner) ?? null;
  }
  if (owner) {
    return tryList('.') ?? null;
  }
  return tryList('') ?? null;
}

/** ホバー・補完の説明(マークダウン) */
export function jsDocMarkdown(item) {
  const head = item.owner && item.owner !== '.' ? `${item.owner}.${item.name}` : (item.owner === '.' ? `.${item.name}` : item.name);
  const call = item.args.startsWith('(') ? '()' : '';
  return withExample(`**${head}${call}**\n\n${item.doc}`, item.example, 'js');
}

/** 雛形の ${1:…} などを外した、見せるための形 */
export function plainSnippet(text) {
  return String(text)
    .replace(/\$\{\d+:([^}]*)\}/g, '$1')
    .replace(/\$\{\d+\}|\$\d+/g, '')
    .replace(/\\\$/g, '$');
}
