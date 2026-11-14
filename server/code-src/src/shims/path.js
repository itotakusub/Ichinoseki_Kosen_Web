/*
 * ESLint の Linter(eslint/universal)がブラウザで使う node:path の代わり(段 11-8。2026-11-09)。
 * Linter が使うのは sep・extname・join だけ(ファイル名の切り分け。ファイルは読まない)。
 * vite.config.js の km-eslint-browser が、eslint の中から読むときだけここへ向ける
 */
export const sep = '/';
export const delimiter = ':';

export function extname(p) {
  const base = basename(p);
  const i = base.lastIndexOf('.');
  return i <= 0 ? '' : base.slice(i);
}

export function basename(p, ext) {
  const parts = String(p).split('/').filter(Boolean);
  const base = parts.length ? parts[parts.length - 1] : '';
  return ext && base.endsWith(ext) && base !== ext ? base.slice(0, -ext.length) : base;
}

export function dirname(p) {
  const s = String(p);
  const i = s.lastIndexOf('/');
  return i < 0 ? '.' : i === 0 ? '/' : s.slice(0, i);
}

export function normalize(p) {
  const abs = String(p).startsWith('/');
  const out = [];
  for (const part of String(p).split('/')) {
    if (part === '' || part === '.') {
      continue;
    }
    if (part === '..' && out.length && out[out.length - 1] !== '..') {
      out.pop();
    } else if (part !== '..' || !abs) {
      out.push(part);
    }
  }
  return (abs ? '/' : '') + out.join('/') || (abs ? '/' : '.');
}

export function join(...parts) {
  return normalize(parts.filter((x) => x !== '').join('/'));
}

export function isAbsolute(p) {
  return String(p).startsWith('/');
}

export function resolve(...parts) {
  let out = '';
  for (let i = parts.length - 1; i >= 0 && !out.startsWith('/'); i--) {
    out = parts[i] + (out ? `/${out}` : '');
  }
  return normalize(out.startsWith('/') ? out : `/${out}`);
}

export function relative(from, to) {
  const a = resolve(from).split('/').filter(Boolean);
  const b = resolve(to).split('/').filter(Boolean);
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) {
    i++;
  }
  return [...a.slice(i).map(() => '..'), ...b.slice(i)].join('/');
}

const path = { sep, delimiter, extname, basename, dirname, normalize, join, isAbsolute, resolve, relative };
path.posix = path;
export const posix = path;
export default path;
