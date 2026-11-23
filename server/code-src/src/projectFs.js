/*
 * VS Code のファイルの口を、/editor/ と同じプロジェクトの置き場(IndexedDB `km-editor`)の上に作る(段 11。2026-11-08)。
 *
 * - 1 つのプロジェクトを `file:///workspace/` として見せる。それ以外のパス(設定など)は答えず、
 *   FileNotFound / NoPermissions で次の口(標準のメモリ)へ回す(files-service-override の overlay の決まり)
 * - 開いたときに全部をメモリに読み、書いたら同じ取引で IndexedDB へ書き戻す(/editor/ と同じ形:
 *   テキストは文字列、画像などは Blob。空のフォルダは project.folders)
 * - 中身の種類は editor-src/src/project/model.js の fileType(テキストかどうか)で決める。表に無い拡張子は
 *   UTF-8 として読めればテキスト、読めなければ Blob
 */
import {
  FileChangeType,
  FileSystemProviderCapabilities,
  FileSystemProviderError,
  FileSystemProviderErrorCode,
  FileType,
} from '@codingame/monaco-vscode-files-service-override';
import { Emitter, Event } from '@codingame/monaco-vscode-api/vscode/vs/base/common/event';
import { fileType } from '../../editor-src/src/project/model.js';
import { allDirs, limitProblem, listDir, relPath as relOf, textOrNull, writeProblem } from './paths.js';

export { ROOT } from './paths.js';
const encoder = new TextEncoder();

function notFound(what) {
  return FileSystemProviderError.create(`not found: ${what}`, FileSystemProviderErrorCode.FileNotFound);
}

/*
 * 断るときの文(VS Code がそのまま出す)。**NoPermissions・FileNotFound・Unavailable は使わない**:
 * overlay がそれを「次の口へ回せ」と読み、標準のメモリへ黙って書いてしまう(保存されない)
 */
const ja = () => (globalThis.__kmCodeLang ?? 'ja') === 'ja';
const REASONS = {
  type: ['この種類のファイルは置けません(使える拡張子は HTML・CSS・JS・TS・JSON・Markdown・画像・Python・Java など)', 'This file type is not allowed in a project'],
  bytes: ['1 つのプロジェクトは 20MB までです', 'A project can be up to 20 MB'],
  files: ['1 つのプロジェクトのファイルは 500 個までです', 'A project can have up to 500 files'],
};
function refuse(code, path) {
  const [jaText, enText] = REASONS[code] ?? ['使えない名前です(先頭の .・.. ・記号・長すぎる名前など)', 'This name cannot be used'];
  const where = path ? `: ${path}` : '';
  return FileSystemProviderError.create(`${ja() ? jaText : enText}${where}`, code === 'bytes' ? FileSystemProviderErrorCode.FileExceedsStorageQuota : FileSystemProviderErrorCode.Unknown);
}

const relPath = (resource) => relOf(resource.path);

export class ProjectFileSystem {
  /**
   * @param {{getFiles: Function, writeFiles: Function, putProject: Function}} store  editor-src/src/project/db.js の openStore().store
   * @param {object} project  projects の記録
   */
  constructor(store, project, uriOf) {
    this.store = store;
    /** パス('a/b.html')→ VS Code の URI(file:///workspace/a/b.html) */
    this.uriOf = uriOf;
    this.project = project;
    /** @type {Map<string, {text?: string, blob?: Blob, bytes?: Uint8Array, size: number, mtime: number}>} */
    this.files = new Map();
    this.folders = new Set(project.folders ?? []);
    this.capabilities = FileSystemProviderCapabilities.FileReadWrite | FileSystemProviderCapabilities.PathCaseSensitive;
    this.onDidChangeCapabilities = Event.None;
    this._onDidChangeFile = new Emitter();
    this.onDidChangeFile = this._onDidChangeFile.event;
    this.ctime = project.createdAt ?? Date.now();
    /*
     * 同期(段 11-4。editor-src/src/project/syncManager.js)との受け渡し:
     *   stamp     … 書くたびに増える(送っている間に書いたら「まだ送っていない」のままにする)
     *   onPersist … 書いたあとに呼ぶ(少し待って送る)
     *   chain     … 記録の読み書きを 1 列に並べる(書き込みと、同期が server を書き込むのが入れ違うと、古い写しで server を消す)
     */
    this.stamp = 0;
    this.onPersist = null;
    this.chain = Promise.resolve();
  }

  /** 並んでいる書き込みが済むまで待つ */
  idle() {
    return this.chain.then(() => {}, () => {});
  }

  /** 記録を書き換える(同期が server・syncDirty などを書く)。書き込みと同じ列に並ぶ */
  mutateRecord(fn) {
    const run = async () => {
      const latest = (await this.store.getProject(this.project.id)) ?? this.project;
      fn(latest);
      await this.store.putProject(latest);
      this.project = latest;
    };
    this.chain = this.chain.then(run, run);
    return this.chain;
  }

  async load() {
    for (const f of await this.store.getFiles(this.project.id)) {
      this.files.set(f.path, { text: f.text, blob: f.blob, size: f.size ?? 0, mtime: f.updatedAt ?? Date.now() });
    }
  }

  /** パスの親のフォルダをすべて(ファイルから分かるものと空のフォルダ) */
  dirs() {
    return allDirs(this.files.keys(), this.folders);
  }

  watch() {
    return { dispose() {} };
  }

  async stat(resource) {
    const p = relPath(resource);
    if (p === null) {
      throw notFound(resource.path);
    }
    const f = this.files.get(p);
    if (f) {
      return { type: FileType.File, ctime: this.ctime, mtime: f.mtime, size: f.size };
    }
    if (this.dirs().has(p)) {
      return { type: FileType.Directory, ctime: this.ctime, mtime: this.project.updatedAt ?? this.ctime, size: 0 };
    }
    throw notFound(resource.path);
  }

  async readdir(resource) {
    const p = relPath(resource);
    if (p === null || !this.dirs().has(p)) {
      throw notFound(resource.path);
    }
    return listDir(p, this.files.keys(), this.dirs()).map(([name, kind]) => [name, kind === 'dir' ? FileType.Directory : FileType.File]);
  }

  async readFile(resource) {
    const p = relPath(resource);
    const f = p === null ? null : this.files.get(p);
    if (!f) {
      throw notFound(resource.path);
    }
    if (typeof f.text === 'string') {
      return encoder.encode(f.text);
    }
    if (!f.bytes) {
      f.bytes = f.blob ? new Uint8Array(await f.blob.arrayBuffer()) : new Uint8Array(0);
    }
    return f.bytes;
  }

  /**
   * 書いたものを置き場へ。プロジェクトの記録は**書く直前に読み直して**、このファイルの口が持つもの(空のフォルダ)と
   * 「まだ送っていない」の印だけを重ねる(/editor/ の同期が書いた server・名前などを古い写しで上書きしない)
   */
  persist(puts, deletes = []) {
    this.stamp += 1;
    const run = async () => {
      const latest = (await this.store.getProject(this.project.id)) ?? this.project;
      this.project = { ...latest, folders: [...this.folders].sort(), updatedAt: Date.now(), syncDirty: true };
      await this.store.writeFiles(this.project.id, puts, deletes, this.project);
    };
    this.chain = this.chain.then(run, run);
    return this.chain.then(() => {
      this.onPersist?.();
    });
  }

  /**
   * ほかのタブ(/editor/ など)が同じプロジェクトを書いたとき(db.js の onExternalChange)。
   * 置き場から読み直し、変わったパスを VS Code へ知らせる(開いているエディタが読み直す)
   */
  async reloadFromStore(paths) {
    const before = new Map(this.files);
    const record = await this.store.getProject(this.project.id);
    if (record) {
      this.project = record;
      this.folders = new Set(record.folders ?? []);
    }
    this.files.clear();
    await this.load();
    const changes = [];
    const touched = new Set(paths.length > 0 ? paths : [...before.keys(), ...this.files.keys()]);
    for (const p of touched) {
      const had = before.has(p);
      const has = this.files.has(p);
      if (!had && !has) {
        continue;
      }
      const type = !had ? FileChangeType.ADDED : (!has ? FileChangeType.DELETED : FileChangeType.UPDATED);
      changes.push({ type, resource: this.uriOf(p) });
    }
    if (changes.length > 0) {
      this._onDidChangeFile.fire(changes);
    }
  }

  async writeFile(resource, content, opts) {
    const p = relPath(resource);
    if (p === null || p === '') {
      throw FileSystemProviderError.create('outside workspace', FileSystemProviderErrorCode.NoPermissions);
    }
    const exists = this.files.has(p);
    if (!exists && !opts?.create) {
      throw notFound(resource.path);
    }
    if (exists && !opts?.overwrite) {
      throw FileSystemProviderError.create('exists', FileSystemProviderErrorCode.FileExists);
    }
    const bad = writeProblem(p);
    if (bad) {
      throw refuse(bad, p);
    }
    const over = limitProblem(new Map([...this.files].map(([k, f]) => [k, f.size])), p, content.byteLength);
    if (over) {
      throw refuse(over);
    }
    const bytes = new Uint8Array(content);
    const text = textOrNull(p, bytes);
    const entry = text !== null
      ? { text, size: bytes.length, mtime: Date.now() }
      : { blob: new Blob([bytes], { type: fileType(p)?.mime ?? 'application/octet-stream' }), bytes, size: bytes.length, mtime: Date.now() };
    this.files.set(p, entry);
    await this.persist([{ path: p, text: entry.text, blob: entry.blob, size: entry.size }]);
    this._onDidChangeFile.fire([{ type: exists ? FileChangeType.UPDATED : FileChangeType.ADDED, resource }]);
  }

  async mkdir(resource) {
    const p = relPath(resource);
    if (p === null) {
      throw FileSystemProviderError.create('outside workspace', FileSystemProviderErrorCode.NoPermissions);
    }
    if (p === '' || this.dirs().has(p)) {
      return;
    }
    const bad = writeProblem(p, { folder: true });
    if (bad) {
      throw refuse(bad, p);
    }
    this.folders.add(p);
    await this.persist([]);
    this._onDidChangeFile.fire([{ type: FileChangeType.ADDED, resource }]);
  }

  async delete(resource) {
    const p = relPath(resource);
    if (p === null || p === '') {
      throw FileSystemProviderError.create('outside workspace', FileSystemProviderErrorCode.NoPermissions);
    }
    const prefix = `${p}/`;
    const gone = [...this.files.keys()].filter((f) => f === p || f.startsWith(prefix));
    for (const f of gone) {
      this.files.delete(f);
    }
    for (const d of [...this.folders]) {
      if (d === p || d.startsWith(prefix)) {
        this.folders.delete(d);
      }
    }
    await this.persist([], gone);
    this._onDidChangeFile.fire([{ type: FileChangeType.DELETED, resource }]);
  }

  async rename(from, to, opts) {
    const a = relPath(from);
    const b = relPath(to);
    if (a === null || b === null || a === '' || b === '') {
      throw FileSystemProviderError.create('outside workspace', FileSystemProviderErrorCode.NoPermissions);
    }
    if (!opts?.overwrite && (this.files.has(b) || this.dirs().has(b))) {
      throw FileSystemProviderError.create('exists', FileSystemProviderErrorCode.FileExists);
    }
    const moves = [...this.files.keys()].filter((f) => f === a || f.startsWith(`${a}/`));
    if (moves.length === 0 && !this.dirs().has(a)) {
      throw notFound(from.path);
    }
    for (const f of moves) {
      const bad = writeProblem(b + f.slice(a.length));
      if (bad) {
        throw refuse(bad, b + f.slice(a.length));
      }
    }
    if (moves.length === 0 || !this.files.has(a)) {
      const bad = writeProblem(b, { folder: true });
      if (bad) {
        throw refuse(bad, b);
      }
    }
    const puts = [];
    for (const f of moves) {
      const next = b + f.slice(a.length);
      const entry = this.files.get(f);
      this.files.delete(f);
      this.files.set(next, entry);
      puts.push({ path: next, text: entry.text, blob: entry.blob, size: entry.size });
    }
    for (const d of [...this.folders]) {
      if (d === a || d.startsWith(`${a}/`)) {
        this.folders.delete(d);
        this.folders.add(b + d.slice(a.length));
      }
    }
    await this.persist(puts, moves);
    this._onDidChangeFile.fire([{ type: FileChangeType.DELETED, resource: from }, { type: FileChangeType.ADDED, resource: to }]);
  }
}
