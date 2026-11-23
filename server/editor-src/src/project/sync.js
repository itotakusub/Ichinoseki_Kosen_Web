/*
 * サインインした人のプロジェクトをサーバーへ同期する(段 10-B。2026-11-08。口は api/editor.php、規約は第8条の3)。
 *
 * **作業の正本はこの端末(IndexedDB)。**サーバーは預かるだけ。プロジェクトの記録に、前回そろえたときのサーバーの形を覚える:
 *   project.server = { id, revision, account, manifest: {パス: {h, s}}, name, entry, folders } | null
 *   project.syncDirty  … この端末で変えて、まだ送っていない
 *   project.syncOff    … 同期しない(サーバーで消された・ほかのアカウントのもの)
 *
 * 送るとき: 目録(パス → sha256 と大きさ)をまるごと、中身は**サーバーに無いものだけ**(前回の目録に無いハッシュ)を
 * zip(名前はハッシュ)で送る。サーバーが「足りない」(409 missing)と言えば、その分を送り直す。
 * 18MB を超える分は何回かに分けて送る(1 回の本文は 20MB まで)。版が違えば(409 conflict)利用者に選んでもらう。
 *
 * 純粋関数(buildManifest・planUpload・sameContent・needsPush・remoteState)は node で試す。
 */
import { fileType } from './model.js';
import '../../../src/Main/html-zip.js';

export const API_URL = '/api/editor.php';
export const BATCH_BYTES = 18 * 1024 * 1024;
const BLOB_PARALLEL = 6;

const encoder = new TextEncoder();

export async function sha256Hex(bytes) {
  const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** ファイルの中身(テキストは UTF-8) */
export async function bytesOf(file) {
  if (typeof file.text === 'string') {
    return encoder.encode(file.text);
  }
  if (file.blob) {
    return new Uint8Array(await file.blob.arrayBuffer());
  }
  return new Uint8Array(0);
}

/**
 * @param {{path: string, bytes: Uint8Array}[]} entries
 * @returns {Promise<{manifest: Record<string, {h: string, s: number}>, byHash: Map<string, Uint8Array>}>}
 */
export async function buildManifest(entries) {
  const manifest = {};
  const byHash = new Map();
  for (const { path, bytes } of [...entries].sort((a, b) => (a.path < b.path ? -1 : 1))) {
    const h = await sha256Hex(bytes);
    manifest[path] = { h, s: bytes.length };
    byHash.set(h, bytes);
  }
  return { manifest, byHash };
}

/**
 * 送る中身を決めて、1 回 BATCH_BYTES までに分ける。known はサーバーにあると分かっているハッシュ。
 * @returns {string[][]} ハッシュの組の並び(送るものが無ければ [[]])
 */
export function planUpload(manifest, known, byteOf, batchBytes = BATCH_BYTES) {
  const have = known instanceof Set ? known : new Set(known);
  const seen = new Set();
  const batches = [[]];
  let size = 0;
  for (const item of Object.values(manifest)) {
    if (have.has(item.h) || seen.has(item.h)) {
      continue;
    }
    seen.add(item.h);
    const n = byteOf(item.h);
    if (size > 0 && size + n > batchBytes) {
      batches.push([]);
      size = 0;
    }
    batches[batches.length - 1].push(item.h);
    size += n;
  }
  return batches;
}

function sortedFolders(folders) {
  return [...new Set(folders ?? [])].sort();
}

/** 目録・名前・入口・空のフォルダが同じか(同じなら送らない) */
export function sameContent(manifest, meta, server) {
  if (!server) {
    return false;
  }
  const a = Object.keys(manifest).sort();
  const b = Object.keys(server.manifest ?? {}).sort();
  if (a.length !== b.length || a.some((p, i) => p !== b[i] || manifest[p].h !== server.manifest[p].h)) {
    return false;
  }
  return meta.name === server.name && (meta.entry ?? '') === (server.entry ?? '')
    && JSON.stringify(sortedFolders(meta.folders)) === JSON.stringify(sortedFolders(server.folders));
}

/** このアカウントで送る対象か */
export function syncable(record, account) {
  if (!account || record.syncOff) {
    return false;
  }
  return !record.server || record.server.account === account;
}

export function needsPush(record, account) {
  return syncable(record, account) && (!record.server || record.syncDirty === true);
}

/**
 * サーバーの一覧と突き合わせた、この端末のプロジェクトの状態。
 *   'local'    この端末だけ(サインインしていない・同期しない)
 *   'pending'  送っていない変更がある / まだ送っていない
 *   'synced'   そろっている
 *   'remote'   ほかの端末で変わった(この端末は変えていない → 読み込めばよい)
 *   'conflict' 両方で変わった
 *   'gone'     サーバーで消された
 *   'other'    ほかのアカウントのもの
 */
export function remoteState(record, account, remoteById) {
  if (!account) {
    return 'local';
  }
  if (record.syncOff) {
    return record.syncOff === 'gone' ? 'gone' : 'local';
  }
  if (record.server && record.server.account !== account) {
    return 'other';
  }
  if (!record.server) {
    return 'pending';
  }
  const remote = remoteById.get(record.server.id);
  if (!remote) {
    return 'gone';
  }
  const newer = remote.revision > record.server.revision;
  if (newer && record.syncDirty) {
    return 'conflict';
  }
  if (newer) {
    return 'remote';
  }
  return record.syncDirty ? 'pending' : 'synced';
}

// ---- 口とのやり取り ----

export class SyncError extends Error {
  constructor(message, status = 0, body = null) {
    super(message);
    this.status = status;
    this.body = body;
  }
}

export function createSyncClient({ csrf, fetchImpl = (...a) => globalThis.fetch(...a) }) {
  async function post(fields, zipBlob = null) {
    const form = new FormData();
    for (const [k, v] of Object.entries(fields)) {
      form.append(k, typeof v === 'string' ? v : JSON.stringify(v));
    }
    if (zipBlob) {
      form.append('zip', zipBlob, 'blobs.zip');
    }
    let response;
    try {
      response = await fetchImpl(API_URL, { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-KM-CSRF': csrf } });
    } catch {
      throw new SyncError('offline', 0);
    }
    let body = null;
    try {
      body = await response.json();
    } catch {
      body = null;
    }
    if (response.status === 409) {
      return { status: 409, body: body ?? {} };
    }
    if (!response.ok || !body?.success) {
      throw new SyncError(body?.message ?? `HTTP ${response.status}`, response.status, body);
    }
    return { status: response.status, body };
  }

  async function zipOf(hashes, byHash) {
    return globalThis.KmHtmlZip.buildZip(hashes.map((h) => ({ path: h, data: byHash.get(h) })));
  }

  return {
    state: () => post({ action: 'state' }).then((r) => r.body),

    /**
     * 送る。files: [{path, text?, blob?}]、meta: {name, entry, folders}、server: 前回の形(無ければ新しく作る)。
     * @returns {Promise<{ok: true, server: object} | {ok: false, conflict: true, revision: number}>}
     */
    async push(files, meta, server, account, { force = false, remoteRevision = null } = {}) {
      const entries = [];
      for (const f of files) {
        entries.push({ path: f.path, bytes: await bytesOf(f) });
      }
      const { manifest, byHash } = await buildManifest(entries);
      const known = new Set(Object.values(server?.manifest ?? {}).map((x) => x.h));
      let id = server?.id ?? '';
      let revision = force && remoteRevision !== null ? remoteRevision : (server?.revision ?? 0);
      const fields = () => ({
        action: 'save', project: id, baseRevision: String(revision), name: meta.name, entry: meta.entry ?? '',
        folders: sortedFolders(meta.folders), manifest,
      });
      const batches = planUpload(manifest, known, (h) => byHash.get(h).length);
      // 大きくて何回にも分けるときは、先に空のプロジェクトを作る(新しいものは失敗すると置き場ごと消えるため)
      if (id === '' && batches.length > 1) {
        const created = await post({ action: 'save', project: '', baseRevision: '0', name: meta.name, entry: '', folders: [], manifest: {} });
        if (created.status === 409) {
          throw new SyncError(created.body.message ?? 'conflict', 409, created.body);
        }
        id = created.body.project.id;
        revision = created.body.project.revision;
      }
      let result = null;
      for (let i = 0; i < batches.length; i++) {
        const zip = batches[i].length > 0 ? await zipOf(batches[i], byHash) : null;
        result = await post(fields(), zip);
        if (result.status === 409 && result.body.conflict) {
          return { ok: false, conflict: true, revision: result.body.revision };
        }
        if (result.status !== 409) {
          break; // そろった(サーバーにもとからあった)
        }
        // 409 missing: 分けて送っている途中なら次の組へ
      }
      // サーバーが「足りない」と言ったもの(前回の目録を覚え違えていたなど)を 1 回だけ送り直す
      if (result.status === 409 && Array.isArray(result.body.missing)) {
        const missing = result.body.missing.filter((h) => byHash.has(h));
        for (const batch of planUpload(Object.fromEntries(missing.map((h) => [h, { h }])), new Set(), (h) => byHash.get(h).length)) {
          result = await post(fields(), await zipOf(batch, byHash));
          if (result.status === 409 && result.body.conflict) {
            return { ok: false, conflict: true, revision: result.body.revision };
          }
        }
      }
      if (result.status === 409) {
        throw new SyncError(result.body.message ?? 'missing', 409, result.body);
      }
      const p = result.body.project;
      return {
        ok: true,
        server: { id: p.id, revision: p.revision, account, manifest, name: meta.name, entry: p.entry, folders: sortedFolders(meta.folders) },
      };
    },

    /** サーバーのものを読む。@returns {Promise<{server: object, files: {path, text?, blob?, size}[]}>} */
    async pull(id, account) {
      const { body } = await post({ action: 'get', project: id });
      const entries = Object.entries(body.files ?? {});
      const out = [];
      let next = 0;
      const worker = async () => {
        while (next < entries.length) {
          const [path, item] = entries[next++];
          const url = `${API_URL}?action=blob&project=${encodeURIComponent(id)}&h=${encodeURIComponent(item.h)}`;
          let response;
          try {
            response = await fetchImpl(url, { credentials: 'same-origin', headers: { 'X-KM-CSRF': csrf } });
          } catch {
            throw new SyncError('offline', 0);
          }
          if (!response.ok) {
            throw new SyncError(`HTTP ${response.status}`, response.status);
          }
          const bytes = new Uint8Array(await response.arrayBuffer());
          if (await sha256Hex(bytes) !== item.h) {
            throw new SyncError('hash', 0);
          }
          const type = fileType(path);
          out.push(type?.text
            ? { path, text: new TextDecoder().decode(bytes), size: bytes.length }
            : { path, blob: new Blob([bytes], { type: type?.mime ?? 'application/octet-stream' }), size: bytes.length });
        }
      };
      await Promise.all(Array.from({ length: Math.min(BLOB_PARALLEL, entries.length) }, worker));
      const p = body.project;
      return {
        files: out,
        server: { id: p.id, revision: p.revision, account, manifest: body.files ?? {}, name: p.name, entry: body.entry ?? '', folders: sortedFolders(body.folders) },
      };
    },

    remove: (id) => post({ action: 'delete', project: id }),
  };
}
