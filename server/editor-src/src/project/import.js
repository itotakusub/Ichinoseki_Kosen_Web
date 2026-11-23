/*
 * プロジェクトへ読み込む(段 10。計画書 24「インポート」。2026-11-08)。すべてブラウザの中(サーバーへは送らない)。
 *
 *   読めるもの … ZIP(HTML 展開と同じ src/Main/html-zip.js。Shift_JIS の名前・パスワード付きも読める)
 *               フォルダ(<input webkitdirectory>)・ファイルの複数選び・ドラッグアンドドロップ(フォルダも)
 *   整え方   … 隠しファイル・__MACOSX は黙って除く → 全部が 1 つのフォルダの下ならそのフォルダを外す
 *               → パスの決まり(model.js の cleanPath)に合わないもの・使えない拡張子は「読まなかったもの」に数える
 *   上限     … 1 プロジェクト 20MB・500 ファイル(model.js)
 *
 * prepareEntries は純粋関数(node で試す)。読む関数(File・ZIP・DataTransfer)はブラウザの物を使う。
 */
import '../../../src/Main/html-zip.js';
import { cleanPath, fileType, ignoredPath, overLimit, PROJECT_MAX_BYTES, stripCommonFolder } from './model.js';

const zip = () => globalThis.KmHtmlZip;

/**
 * 読んだもの [{path, data: Uint8Array}] を、プロジェクトに入れられる形にする。
 * @returns {{files: {path: string, data: Uint8Array}[], rejected: {path: string, code: string}[], limit: null|'files'|'bytes'}}
 */
export function prepareEntries(raw) {
  const kept = raw
    .map((e) => ({ path: String(e.path).replace(/\\/g, '/').replace(/^\/+/, ''), data: e.data }))
    .filter((e) => e.path !== '' && !e.path.endsWith('/') && !ignoredPath(e.path));
  const stripped = stripCommonFolder(kept.map((e) => e.path));
  const files = [];
  const rejected = [];
  const seen = new Set();
  kept.forEach((e, i) => {
    let path;
    try {
      path = cleanPath(stripped[i]);
    } catch (error) {
      rejected.push({ path: e.path, code: error.code ?? 'invalid' });
      return;
    }
    if (seen.has(path)) {
      rejected.push({ path: e.path, code: 'duplicate' });
      return;
    }
    seen.add(path);
    files.push({ path, data: e.data });
  });
  const bytes = files.reduce((n, f) => n + f.data.length, 0);
  return { files, rejected, limit: overLimit(files.length, bytes) };
}

const decoder = () => new TextDecoder('utf-8', { fatal: false });

/** 1 つのファイルを、db.js の writeFiles に渡す形にする(テキストは文字列、ほかは Blob) */
export function toStored(path, data) {
  const type = fileType(path);
  if (type?.text) {
    let text = decoder().decode(data);
    if (text.charCodeAt(0) === 0xfeff) {
      text = text.slice(1);
    }
    return { path, text, size: data.length };
  }
  return { path, blob: new Blob([data], { type: type?.mime ?? 'application/octet-stream' }), size: data.length };
}

export function isZipName(name) {
  return /\.zip$/i.test(String(name));
}

/** ZIP の中身を読む。askPassword(wrong) は文字列か null(やめる)を返す */
export async function readZip(bytes, askPassword) {
  const result = await zip().unzip(bytes, {
    maxTotal: PROJECT_MAX_BYTES * 2,
    keep: (name) => (ignoredPath(name) ? 'ignore' : 'keep'),
    askPassword,
  });
  return result.files.map((f) => ({ path: f.path, data: f.data }));
}

async function fileBytes(file) {
  return new Uint8Array(await file.arrayBuffer());
}

/**
 * <input type=file>(multiple・webkitdirectory)で選んだもの。ZIP が 1 つだけなら中身を読む。
 * @returns {Promise<{path: string, data: Uint8Array}[]>}
 */
export async function readFileList(fileList, askPassword) {
  const list = [...fileList];
  if (list.length === 1 && isZipName(list[0].name)) {
    return readZip(await fileBytes(list[0]), askPassword);
  }
  const out = [];
  for (const file of list) {
    out.push({ path: file.webkitRelativePath || file.name, data: await fileBytes(file) });
  }
  return out;
}

function readAllEntries(reader) {
  return new Promise((resolve, reject) => {
    const all = [];
    const next = () => reader.readEntries((batch) => {
      if (batch.length === 0) {
        resolve(all);
        return;
      }
      all.push(...batch);
      next();
    }, reject);
    next();
  });
}

async function walk(entry, prefix, out, limit) {
  if (out.length > limit) {
    return;
  }
  if (entry.isFile) {
    const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
    out.push({ path: `${prefix}${file.name}`, data: await fileBytes(file) });
    return;
  }
  if (entry.isDirectory) {
    const children = await readAllEntries(entry.createReader());
    for (const child of children) {
      await walk(child, `${prefix}${entry.name}/`, out, limit);
    }
  }
}

/** ドラッグアンドドロップ(フォルダは中まで)。ZIP が 1 つだけなら中身を読む */
export async function readDataTransfer(dataTransfer, askPassword) {
  const items = [...(dataTransfer.items ?? [])].filter((i) => i.kind === 'file');
  const entries = items.map((i) => i.webkitGetAsEntry?.()).filter(Boolean);
  if (entries.length === 0) {
    return readFileList(dataTransfer.files ?? [], askPassword);
  }
  if (entries.length === 1 && entries[0].isFile && isZipName(entries[0].name)) {
    return readFileList(dataTransfer.files, askPassword);
  }
  const out = [];
  for (const entry of entries) {
    await walk(entry, '', out, 2000);
  }
  return out;
}

/** プロジェクトを ZIP に(Blob)。files は {path, text?, blob?} */
export async function projectZip(files) {
  const encoder = new TextEncoder();
  const list = [];
  for (const f of [...files].sort((a, b) => a.path.localeCompare(b.path))) {
    const data = typeof f.text === 'string' ? encoder.encode(f.text) : new Uint8Array(await f.blob.arrayBuffer());
    list.push({ path: f.path, data });
  }
  return zip().buildZip(list);
}
