/*
 * プロジェクトの形(段 10。2026-11-08)。**純粋関数だけ**(node で試す: src/scripts/js/editor.test.js)。
 *
 *   パス   … "css/style.css" の形。先頭の / ・.. ・空の段・ドライブ名・制御文字は断る(lib/html-host.php の
 *            km_html_clean_path と同じ決まり。10-B でサーバーへ送るときに同じ物差しで通るように)
 *   型     … 拡張子で決める(KM_HTML_TYPES と同じ表)。テキストは Monaco で直せる、ほかは見るだけ
 *   上限   … 1 プロジェクト 20MB・500 ファイル(10-B のサーバーの上限と同じ)
 */
export const PROJECT_MAX_BYTES = 20 * 1024 * 1024;
export const PROJECT_MAX_FILES = 500;
export const PATH_MAX = 255;
export const DEPTH_MAX = 16;
export const NAME_MAX = 100;

/** 拡張子 → [MIME, テキストか, Monaco の言語] */
const TYPES = {
  html: ['text/html', true, 'html'],
  htm: ['text/html', true, 'html'],
  css: ['text/css', true, 'css'],
  js: ['text/javascript', true, 'javascript'],
  mjs: ['text/javascript', true, 'javascript'],
  json: ['application/json', true, 'json'],
  map: ['application/json', true, 'json'],
  webmanifest: ['application/manifest+json', true, 'json'],
  xml: ['application/xml', true, 'xml'],
  txt: ['text/plain', true, 'plaintext'],
  md: ['text/plain', true, 'markdown'],
  csv: ['text/csv', true, 'plaintext'],
  svg: ['image/svg+xml', true, 'xml'],
  png: ['image/png', false, null],
  jpg: ['image/jpeg', false, null],
  jpeg: ['image/jpeg', false, null],
  gif: ['image/gif', false, null],
  webp: ['image/webp', false, null],
  avif: ['image/avif', false, null],
  bmp: ['image/bmp', false, null],
  ico: ['image/x-icon', false, null],
  woff2: ['font/woff2', false, null],
  woff: ['font/woff', false, null],
  ttf: ['font/ttf', false, null],
  otf: ['font/otf', false, null],
  mp3: ['audio/mpeg', false, null],
  wav: ['audio/wav', false, null],
  ogg: ['audio/ogg', false, null],
  m4a: ['audio/mp4', false, null],
  mp4: ['video/mp4', false, null],
  webm: ['video/webm', false, null],
  wasm: ['application/wasm', false, null],
  pdf: ['application/pdf', false, null],
  glb: ['model/gltf-binary', false, null],
  gltf: ['model/gltf+json', true, 'json'],
  // 段 11(2026-11-08): VS Code 型のエディタ /code/ で書く言語。**保存するだけ**(プレビューでは使わない)。
  // サーバーの表は lib/editor-projects.php の KM_EDITOR_EXTRA_TYPES(同じ並び)。/editor/ では色分けの無いテキスト
  ts: ['text/plain', true, 'typescript'],
  tsx: ['text/plain', true, 'typescript'],
  jsx: ['text/javascript', true, 'javascript'],
  cjs: ['text/javascript', true, 'javascript'],
  mts: ['text/plain', true, 'typescript'],
  cts: ['text/plain', true, 'typescript'],
  py: ['text/plain', true, 'plaintext'],
  ipynb: ['application/json', true, 'json'],
  java: ['text/plain', true, 'plaintext'],
  php: ['text/plain', true, 'plaintext'],
  sql: ['text/plain', true, 'plaintext'],
  ps1: ['text/plain', true, 'plaintext'],
  psm1: ['text/plain', true, 'plaintext'],
  psd1: ['text/plain', true, 'plaintext'],
  bat: ['text/plain', true, 'plaintext'],
  cmd: ['text/plain', true, 'plaintext'],
  sh: ['text/plain', true, 'plaintext'],
  bash: ['text/plain', true, 'plaintext'],
  yaml: ['text/plain', true, 'plaintext'],
  yml: ['text/plain', true, 'plaintext'],
  toml: ['text/plain', true, 'plaintext'],
  ini: ['text/plain', true, 'plaintext'],
  scss: ['text/plain', true, 'plaintext'],
  less: ['text/plain', true, 'plaintext'],
  log: ['text/plain', true, 'plaintext'],
};

/** VS Code 型のエディタだけで使う(保存するだけの)拡張子。サーバーの KM_EDITOR_EXTRA_TYPES と同じ並び */
export const EDITOR_ONLY_EXTENSIONS = ['ts', 'tsx', 'jsx', 'cjs', 'mts', 'cts', 'py', 'ipynb', 'java', 'php', 'sql', 'ps1', 'psm1', 'psd1', 'bat', 'cmd', 'sh', 'bash', 'yaml', 'yml', 'toml', 'ini', 'scss', 'less', 'log'];

export function extensionOf(path) {
  const m = /\.([A-Za-z0-9]+)$/.exec(String(path));
  return m ? m[1].toLowerCase() : '';
}

/** @returns {{mime: string, text: boolean, lang: string|null} | null} 使えない拡張子は null */
export function fileType(path) {
  const t = TYPES[extensionOf(path)];
  return t ? { mime: t[0], text: t[1], lang: t[2] } : null;
}

export function isHtml(path) {
  return /\.html?$/i.test(String(path));
}

export function isImage(path) {
  return /^image\//.test(fileType(path)?.mime ?? '');
}

export class PathError extends Error {
  constructor(code, path) {
    super(code);
    this.code = code;
    this.path = path;
  }
}

/** パスを整える。使えなければ PathError(code: 'empty'|'absolute'|'parent'|'segment'|'control'|'long'|'deep'|'type') */
export function cleanPath(input, { requireType = true } = {}) {
  let path = String(input ?? '').replace(/\\/g, '/').normalize('NFC').trim();
  if (path === '') {
    throw new PathError('empty', input);
  }
  if (path.startsWith('/') || /^[A-Za-z]:/.test(path)) {
    throw new PathError('absolute', input);
  }
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f\u007f]/.test(path)) {
    throw new PathError('control', input);
  }
  const parts = path.split('/');
  for (const part of parts) {
    if (part === '..') {
      throw new PathError('parent', input);
    }
    if (part === '' || part === '.' || part.trim() !== part || /[<>:"|?*]/.test(part)) {
      throw new PathError('segment', input);
    }
  }
  if (path.length > PATH_MAX) {
    throw new PathError('long', input);
  }
  if (parts.length > DEPTH_MAX) {
    throw new PathError('deep', input);
  }
  if (requireType && fileType(path) === null) {
    throw new PathError('type', input);
  }
  return path;
}

/** フォルダの名前(拡張子の決まりなし) */
export function cleanFolder(input) {
  return cleanPath(input, { requireType: false });
}

/** 読み込むときに黙って除くもの(隠しファイル・macOS の付録・Windows のサムネイル) */
export function ignoredPath(path) {
  const parts = String(path).split('/');
  if (parts.some((p) => p.startsWith('.') || p === '__MACOSX')) {
    return true;
  }
  const last = parts[parts.length - 1].toLowerCase();
  return last === 'thumbs.db' || last === 'desktop.ini';
}

/** 全部が同じ 1 つのフォルダの下なら、そのフォルダを外す(何段でも) */
export function stripCommonFolder(paths) {
  let list = [...paths];
  for (;;) {
    if (list.length === 0 || list.some((p) => !p.includes('/'))) {
      return list;
    }
    const head = list[0].slice(0, list[0].indexOf('/') + 1);
    if (!list.every((p) => p.startsWith(head))) {
      return list;
    }
    list = list.map((p) => p.slice(head.length));
  }
}

/** 入口の HTML を選ぶ: 頼まれたもの → index.html → 1 階層目の index.html → HTML が 1 つならそれ */
export function pickEntry(paths, requested = '') {
  const list = [...paths];
  if (requested && list.includes(requested) && isHtml(requested)) {
    return requested;
  }
  if (list.includes('index.html')) {
    return 'index.html';
  }
  if (list.includes('index.htm')) {
    return 'index.htm';
  }
  const html = list.filter(isHtml).sort((a, b) => a.split('/').length - b.split('/').length || a.localeCompare(b));
  const index = html.find((p) => /(^|\/)index\.html?$/i.test(p));
  return index ?? html[0] ?? null;
}

/**
 * from(プロジェクトの中のファイル)から見た参照 ref を、プロジェクトのパスにする。
 * 外を指すもの(https:・data:・//… ・# だけ・mailto: など)と、上へ出すぎるものは null。
 */
export function resolveRef(fromPath, ref) {
  let r = String(ref ?? '').trim();
  if (r === '' || r.startsWith('#') || r.startsWith('//') || /^[A-Za-z][A-Za-z0-9+.-]*:/.test(r)) {
    return null;
  }
  r = r.replace(/[?#].*$/, '');
  if (r === '') {
    return null;
  }
  try {
    r = decodeURIComponent(r);
  } catch {
    // そのまま
  }
  const base = r.startsWith('/') ? [] : String(fromPath).split('/').slice(0, -1);
  for (const part of r.replace(/^\/+/, '').split('/')) {
    if (part === '' || part === '.') {
      continue;
    }
    if (part === '..') {
      if (base.length === 0) {
        return null;
      }
      base.pop();
      continue;
    }
    base.push(part);
  }
  return base.length === 0 ? null : base.join('/');
}

/** 既にある名前と重ならない名前(a.html → a-2.html) */
export function uniquePath(path, existing) {
  const set = existing instanceof Set ? existing : new Set(existing);
  if (!set.has(path)) {
    return path;
  }
  const m = /^(.*?)(\.[^./]+)?$/.exec(path);
  for (let i = 2; ; i++) {
    const candidate = `${m[1]}-${i}${m[2] ?? ''}`;
    if (!set.has(candidate)) {
      return candidate;
    }
  }
}

export function cleanName(name, fallback = 'Project') {
  const n = String(name ?? '').replace(/[\u0000-\u001f\u007f]/g, '').trim().slice(0, NAME_MAX);
  return n === '' ? fallback : n;
}

/** ファイルの一覧から、フォルダの木を作る(画面の並び: フォルダが先・名前順) */
export function buildTree(paths, folders = []) {
  const root = { name: '', path: '', folders: new Map(), files: [] };
  const ensure = (dirParts) => {
    let node = root;
    let acc = '';
    for (const part of dirParts) {
      acc = acc === '' ? part : `${acc}/${part}`;
      if (!node.folders.has(part)) {
        node.folders.set(part, { name: part, path: acc, folders: new Map(), files: [] });
      }
      node = node.folders.get(part);
    }
    return node;
  };
  for (const folder of folders) {
    ensure(folder.split('/'));
  }
  for (const path of paths) {
    const parts = path.split('/');
    ensure(parts.slice(0, -1)).files.push({ name: parts[parts.length - 1], path });
  }
  const finish = (node) => ({
    name: node.name,
    path: node.path,
    folders: [...node.folders.values()].sort((a, b) => a.name.localeCompare(b.name)).map(finish),
    files: node.files.sort((a, b) => a.name.localeCompare(b.name)),
  });
  return finish(root);
}

/** フォルダの名前を変えたときの、中のファイルの新しいパス */
export function movePrefix(path, from, to) {
  if (path === from) {
    return to;
  }
  return path.startsWith(`${from}/`) ? `${to}${path.slice(from.length)}` : path;
}

/** 合計の大きさとファイルの数の確かめ。超えるなら理由('files'|'bytes')、収まれば null */
export function overLimit(fileCount, totalBytes) {
  if (fileCount > PROJECT_MAX_FILES) {
    return 'files';
  }
  if (totalBytes > PROJECT_MAX_BYTES) {
    return 'bytes';
  }
  return null;
}

export function newId() {
  const bytes = new Uint8Array(12);
  globalThis.crypto.getRandomValues(bytes);
  return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** UTF-8 の長さ(文字列の大きさをバイトで) */
export function byteLength(text) {
  return new TextEncoder().encode(String(text)).length;
}

/**
 * 新しいプロジェクトの記録と、書くファイル(db.js の writeFiles に渡す形)。
 * @param {string} name
 * @param {Record<string, string>} texts パス → テキスト
 * @returns {{project: object, puts: {path: string, text: string, size: number}[]}}
 */
export function newProjectRecord(name, texts, { id = newId(), now = Date.now(), fallbackName = 'Project' } = {}) {
  const puts = Object.entries(texts).map(([path, text]) => ({ path: cleanPath(path), text: String(text), size: byteLength(text) }));
  const paths = puts.map((p) => p.path);
  const entry = pickEntry(paths) ?? '';
  const first = entry || paths[0] || '';
  return {
    project: {
      id,
      name: cleanName(name, fallbackName),
      entry,
      folders: [],
      open: first ? [first] : [],
      active: first,
      createdAt: now,
      updatedAt: now,
      server: null,
    },
    puts,
  };
}
