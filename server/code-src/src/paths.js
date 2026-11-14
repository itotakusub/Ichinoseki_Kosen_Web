/*
 * VS Code 型のエディタのファイルの口の、純粋な部分(段 11。2026-11-08)。node で試す(src/scripts/js/editor.test.js)。
 */
import { cleanPath, fileType, PROJECT_MAX_BYTES, PROJECT_MAX_FILES } from '../../editor-src/src/project/model.js';

/**
 * この名前で書いてよいか(/editor/ とサーバーの同期と同じ決まり。model.js の cleanPath と型の表)。
 * 決まりに合わないものを置くと、サーバーの同期がプロジェクトごと断ってしまうため、書く前に止める。
 * @returns {string|null} 断る理由のコード(PathError の code・'type'・'changed')。よければ null
 */
export function writeProblem(path, { folder = false } = {}) {
  try {
    const cleaned = cleanPath(path, { requireType: !folder });
    return cleaned === path ? null : 'changed';
  } catch (error) {
    return error?.code ?? 'invalid';
  }
}

/** 書いたあとの大きさと数が上限(20MB・500)に収まるか。sizes は いまのパス → 大きさ */
export function limitProblem(sizes, path, newSize) {
  let total = newSize;
  for (const [p, s] of sizes) {
    if (p !== path) {
      total += s;
    }
  }
  if (total > PROJECT_MAX_BYTES) {
    return 'bytes';
  }
  return !sizes.has(path) && sizes.size + 1 > PROJECT_MAX_FILES ? 'files' : null;
}

/** プロジェクトを見せる場所(file:///workspace/) */
export const ROOT = '/workspace';

/** `/workspace/a/b.html` → `a/b.html`(ルートは ''・外は null) */
export function relPath(uriPath) {
  const p = String(uriPath);
  if (p === ROOT || p === `${ROOT}/`) {
    return '';
  }
  return p.startsWith(`${ROOT}/`) ? p.slice(ROOT.length + 1).replace(/\/+$/, '') : null;
}

/** `a/b.html` → `/workspace/a/b.html` */
export function absPath(rel) {
  return rel === '' ? ROOT : `${ROOT}/${rel}`;
}

/**
 * 書かれた中身を、置き場の形(テキストか Blob か)に決める。表(model.js)にある型はその決まりどおり、
 * 表に無い拡張子は UTF-8 として読めればテキスト。@returns テキストなら文字列、そうでなければ null
 */
export function textOrNull(path, bytes) {
  const t = fileType(path);
  if (t) {
    return t.text ? new TextDecoder().decode(bytes) : null;
  }
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return null;
  }
}

/** プレビューの窓(preview-main.js)がエディタにファイルを開かせる知らせの道(同じオリジンの BroadcastChannel) */
export const REVEAL_CHANNEL = 'km-code-reveal';

/**
 * プレビューの窓からの「ここを開いて」(段 11-3)を確かめる。開いているプロジェクトのもので、
 * パスが決まりどおり(cleanPath)のときだけ。@returns {{path: string, line: number, col: number}|null}
 */
export function revealTarget(data, projectId) {
  if (!data || typeof data !== 'object' || data.type !== 'reveal' || data.projectId !== projectId) {
    return null;
  }
  const path = data.path;
  if (typeof path !== 'string' || path === '' || path.length > 300 || writeProblem(path) !== null) {
    return null;
  }
  const int = (v) => (Number.isSafeInteger(v) && v > 0 ? v : 0);
  return { path, line: int(data.line), col: int(data.col) };
}

/** ファイルのパスと空のフォルダから、すべてのフォルダ('' はルート) */
export function allDirs(filePaths, folders) {
  const out = new Set(['']);
  const add = (p, includeSelf) => {
    const parts = p.split('/');
    const end = includeSelf ? parts.length : parts.length - 1;
    for (let i = 1; i <= end; i++) {
      out.add(parts.slice(0, i).join('/'));
    }
  };
  for (const p of filePaths) {
    add(p, false);
  }
  for (const d of folders) {
    add(d, true);
  }
  return out;
}

/** フォルダ dir の直下の名前と種類('file' | 'dir') */
export function listDir(dir, filePaths, dirs) {
  const prefix = dir === '' ? '' : `${dir}/`;
  const out = new Map();
  for (const f of filePaths) {
    if (f.startsWith(prefix)) {
      const rest = f.slice(prefix.length);
      const i = rest.indexOf('/');
      out.set(i < 0 ? rest : rest.slice(0, i), i < 0 ? 'file' : 'dir');
    }
  }
  for (const d of dirs) {
    if (d !== '' && d.startsWith(prefix) && !d.slice(prefix.length).includes('/')) {
      out.set(d.slice(prefix.length), 'dir');
    }
  }
  return [...out.entries()].sort((a, b) => (a[0] < b[0] ? -1 : 1));
}
