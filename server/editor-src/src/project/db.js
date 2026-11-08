/*
 * プロジェクトをこの端末のブラウザ(IndexedDB `km-editor`)に置く(段 10。2026-11-08)。**サーバーへは送らない**
 * (サインインした人の同期は 10-B の sync.js が、ここに置いたものを読んで送る)。
 *
 *   projects  { id, name, entry, folders: [], open: [], active, createdAt, updatedAt, server: null|{…} }
 *   files     { key: "<id>\u0000<path>", projectId, path, text?: string, blob?: Blob, size, updatedAt }
 *
 * IndexedDB が使えない(プライベートの窓・古いブラウザ・拒まれた)ときは openDb が null を返し、
 * 画面は「この端末に保存できません」と出して、メモリの中だけで動く(memoryDb)。
 */
const DB_NAME = 'km-editor';
const DB_VERSION = 1;
const SEP = '\u0000';

function req(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function done(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onabort = () => reject(tx.error ?? new Error('aborted'));
    tx.onerror = () => reject(tx.error);
  });
}

export const fileKey = (projectId, path) => `${projectId}${SEP}${path}`;

class IdbStore {
  constructor(db) {
    this.db = db;
  }

  async listProjects() {
    const tx = this.db.transaction('projects', 'readonly');
    const all = await req(tx.objectStore('projects').getAll());
    return all.sort((a, b) => (b.updatedAt ?? 0) - (a.updatedAt ?? 0));
  }

  async getProject(id) {
    const tx = this.db.transaction('projects', 'readonly');
    return (await req(tx.objectStore('projects').get(id))) ?? null;
  }

  async putProject(project) {
    const tx = this.db.transaction('projects', 'readwrite');
    tx.objectStore('projects').put(project);
    await done(tx);
  }

  async getFiles(projectId) {
    const tx = this.db.transaction('files', 'readonly');
    const range = IDBKeyRange.bound(`${projectId}${SEP}`, `${projectId}${SEP}￿`);
    return req(tx.objectStore('files').getAll(range));
  }

  /** 書く・消すをまとめて 1 つの取引で(途中で失敗したら全部やめる) */
  async writeFiles(projectId, puts, deletes = [], project = null) {
    const tx = this.db.transaction(['files', 'projects'], 'readwrite');
    const files = tx.objectStore('files');
    for (const path of deletes) {
      files.delete(fileKey(projectId, path));
    }
    for (const f of puts) {
      files.put({ key: fileKey(projectId, f.path), projectId, path: f.path, text: f.text, blob: f.blob, size: f.size, updatedAt: Date.now() });
    }
    if (project) {
      tx.objectStore('projects').put(project);
    }
    await done(tx);
  }

  async deleteProject(projectId) {
    const tx = this.db.transaction(['files', 'projects'], 'readwrite');
    tx.objectStore('files').delete(IDBKeyRange.bound(`${projectId}${SEP}`, `${projectId}${SEP}￿`));
    tx.objectStore('projects').delete(projectId);
    await done(tx);
  }
}

/** IndexedDB が使えないときの代わり(閉じると消える) */
class MemoryStore {
  constructor() {
    this.projects = new Map();
    this.files = new Map();
  }

  async listProjects() {
    return [...this.projects.values()].sort((a, b) => (b.updatedAt ?? 0) - (a.updatedAt ?? 0));
  }

  async getProject(id) {
    return this.projects.get(id) ?? null;
  }

  async putProject(project) {
    this.projects.set(project.id, structuredClone(project));
  }

  async getFiles(projectId) {
    return [...this.files.values()].filter((f) => f.projectId === projectId);
  }

  async writeFiles(projectId, puts, deletes = [], project = null) {
    for (const path of deletes) {
      this.files.delete(fileKey(projectId, path));
    }
    for (const f of puts) {
      this.files.set(fileKey(projectId, f.path), { key: fileKey(projectId, f.path), projectId, path: f.path, text: f.text, blob: f.blob, size: f.size, updatedAt: Date.now() });
    }
    if (project) {
      await this.putProject(project);
    }
  }

  async deleteProject(projectId) {
    for (const [key, f] of this.files) {
      if (f.projectId === projectId) {
        this.files.delete(key);
      }
    }
    this.projects.delete(projectId);
  }
}

/** @returns {Promise<{store: IdbStore|MemoryStore, persistent: boolean}>} */
export async function openStore() {
  try {
    if (!globalThis.indexedDB) {
      throw new Error('no indexedDB');
    }
    const request = globalThis.indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains('projects')) {
        db.createObjectStore('projects', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('files')) {
        db.createObjectStore('files', { keyPath: 'key' });
      }
    };
    const db = await req(request);
    return { store: new IdbStore(db), persistent: true };
  } catch {
    return { store: new MemoryStore(), persistent: false };
  }
}

export { MemoryStore };
