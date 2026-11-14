/*
 * サーバーとの同期の進め方(段 11-4。2026-11-09)。/editor/(main.js)と /code/(code-src/src/codeSync.js)が使う。
 *
 * 段 10-B で main.js の中に書いた同期(送る・読む・突き合わせる・ぶつかりを選ぶ)を、画面から切り離してここへ移した。
 * 口とのやり取りと純粋関数は sync.js のまま。ここは「いつ・どのプロジェクトを・どう送るか」と、その状態を持つ。
 * 画面ごとに違うところは host で受ける:
 *
 *   host.current()        いま開いているプロジェクトの記録(/editor/ はメモリの写し・/code/ はファイルの口の写し)。無ければ null
 *   host.flush()          打ちかけを置き場へ書く(送る前・突き合わせる前)
 *   host.currentFiles()   開いているプロジェクトの中身 [{path, text?, blob?}]。null なら置き場から読む
 *   host.stamp()          打つたびに増える数(送っている間に打ったら、まだ送っていないままにする)
 *   host.updateRecord(id, fn)  記録を書き換えて置き場へ(開いているものはメモリの方も)
 *   host.beforePull(id)   開いているものをサーバーのもので置き換える前(打ちかけを捨てる)
 *   host.afterPull(id)    置き換えたあと(開き直す)
 *   host.afterRename(id)  ぶつかりで「両方残す」を選び、この端末のものの名前を変えたあと
 *   host.localName(name)  「両方残す」でこの端末のものに付ける名前
 *   host.untitled()       名前の無いもの
 *   host.onChange()       状態が変わった(画面の印を描き直す)
 *
 * 状態の印(chipState)は純粋関数で、node で試す。
 */
import { cleanName, newProjectRecord } from './model.js';
import { createSyncClient, needsPush, remoteState, syncable } from './sync.js';

export const SYNC_DELAY = 3000;
export const REFRESH_EVERY = 30000;

/**
 * 印の状態。'blocked' | 'idle' | 'offline' | 'failed' | 'conflict' | 'sending' | 'gone' | 'off' | 'pending' | 'synced'
 * (サインインしていないときは呼ぶ側が出さない)
 */
export function chipState({ blocked, account, problem, record, conflict, sending, error, timerPending }) {
  if (blocked) {
    return 'blocked';
  }
  if (!account) {
    return problem || 'idle';
  }
  if (record && conflict) {
    return 'conflict';
  }
  if (sending) {
    return 'sending';
  }
  if (record && error) {
    return 'failed';
  }
  if (problem) {
    return problem;
  }
  if (record && !syncable(record, account)) {
    return record.syncOff === 'gone' ? 'gone' : 'off';
  }
  if (record && (needsPush(record, account) || timerPending)) {
    return 'pending';
  }
  return 'synced';
}

/** 打つのを止めてから delay ミリ秒で 1 回呼ぶ */
function debouncer(fn, delay) {
  let timer = 0;
  return {
    schedule() {
      clearTimeout(timer);
      timer = setTimeout(() => {
        timer = 0;
        fn();
      }, delay);
    },
    cancel() {
      clearTimeout(timer);
      timer = 0;
    },
    pending: () => timer !== 0,
  };
}

/**
 * @param {{store: object|(() => object), csrf: string, signedIn: boolean, host: object, client?: object, delay?: number}} options
 */
export function createSyncManager({ store, csrf, signedIn, host, client = null, delay = SYNC_DELAY }) {
  // store は置き場か、置き場を返す関数(/editor/ は起動の途中で置き場を開くため)
  const st = () => (typeof store === 'function' ? store() : store);
  const syncClient = client ?? createSyncClient({ csrf });
  const s = {
    account: '',
    canSync: false,
    blockedMessage: '',
    remoteById: new Map(),
    usage: { used: 0, limits: null, count: 0 },
    problem: '', // 'offline' | 'failed' | ''
    problemText: '',
    sending: false,
    lastRefresh: 0,
    conflicts: new Set(), // この端末のプロジェクトの id
    errors: new Map(), // id → 送れなかった理由(上限など)
  };
  let chain = Promise.resolve();
  const changed = () => host.onChange?.();

  function queue(task) {
    chain = chain.then(task).catch((error) => {
      s.problem = error?.status === 0 ? 'offline' : 'failed';
      s.problemText = error?.message ?? '';
    }).finally(changed);
    return chain;
  }

  const timer = debouncer(() => {
    const record = host.current();
    if (record && s.canSync) {
      queue(() => push(record.id));
    }
  }, delay);

  /** 打つのを止めて少ししたら送る */
  function schedule() {
    if (s.canSync) {
      timer.schedule();
      changed();
    }
  }

  /** 待たずに送る(保存・タブを隠したとき)。送るものが無ければ何もしない */
  function pushNow() {
    const record = host.current();
    if (s.canSync && record && (timer.pending() || needsPush(record, s.account))) {
      timer.cancel();
      return queue(() => push(record.id));
    }
    return chain;
  }

  /** 開いているプロジェクトの印({state, title}。title は blocked・failed のときだけ。ほかは null) */
  function status() {
    const record = host.current();
    const state = chipState({
      blocked: s.blockedMessage,
      account: s.account,
      problem: s.problem,
      record,
      conflict: record ? s.conflicts.has(record.id) : false,
      sending: s.sending,
      error: record ? s.errors.has(record.id) : false,
      timerPending: timer.pending(),
    });
    let title = null;
    if (state === 'blocked') {
      title = s.blockedMessage;
    } else if (state === 'failed') {
      title = (record && s.errors.get(record.id)) ?? s.problemText;
    }
    return { state, title };
  }

  /** 一覧の印(remoteState。ぶつかりを覚えていればそちら) */
  function stateOf(record) {
    return s.conflicts.has(record.id) ? 'conflict' : remoteState(record, s.canSync ? s.account : '', s.remoteById);
  }

  async function push(id, { force = false } = {}) {
    if (!s.canSync) {
      return;
    }
    const isCurrent = host.current()?.id === id;
    if (isCurrent) {
      await host.flush();
    }
    const record = isCurrent ? host.current() : await st().getProject(id);
    if (!record || !syncable(record, s.account) || (!force && !needsPush(record, s.account)) || (s.conflicts.has(id) && !force)) {
      return;
    }
    const stamp = host.stamp();
    const live = isCurrent ? host.currentFiles() : null;
    const list = live ?? (await st().getFiles(id)).map((f) => ({ path: f.path, text: f.text, blob: f.blob }));
    const meta = { name: record.name, entry: record.entry ?? '', folders: record.folders ?? [] };
    s.sending = true;
    changed();
    try {
      const remoteRevision = force && record.server ? (s.remoteById.get(record.server.id)?.revision ?? null) : null;
      const result = await syncClient.push(list, meta, record.server, s.account, { force, remoteRevision });
      if (!result.ok) {
        s.conflicts.add(id);
        // 「この端末のもので上書き」は、このとき知ったサーバーの版を土台に送る(覚えていないと古い版で送って、またぶつかる)
        if (record.server && Number.isInteger(result.revision)) {
          s.remoteById.set(record.server.id, { ...(s.remoteById.get(record.server.id) ?? { id: record.server.id, name: record.name }), revision: result.revision });
        }
        return;
      }
      s.conflicts.delete(id);
      s.errors.delete(id);
      s.problem = '';
      s.remoteById.set(result.server.id, { id: result.server.id, name: meta.name, revision: result.server.revision, updatedAt: new Date().toISOString() });
      await host.updateRecord(id, (r) => {
        r.server = result.server;
        r.syncOff = false;
        // 送っている間にまた打っていたら、まだ送っていないまま(次の回で送る)
        if (!(isCurrent && host.current()?.id === id && host.stamp() !== stamp)) {
          r.syncDirty = false;
        }
      });
      if (isCurrent && host.current()?.syncDirty) {
        schedule();
      }
    } catch (error) {
      if (error?.status === 400 || error?.status === 413) {
        s.errors.set(id, error.message);
      } else {
        throw error;
      }
    } finally {
      s.sending = false;
    }
  }

  /** サーバーのものを、この端末のプロジェクト id に読み込む(この端末の中身は置き換わる) */
  async function pullInto(id, serverId) {
    const pulled = await syncClient.pull(serverId, s.account);
    const isCurrent = host.current()?.id === id;
    if (isCurrent) {
      host.beforePull?.(id);
    }
    const before = (await st().getFiles(id)).map((f) => f.path);
    const keep = new Set(pulled.files.map((f) => f.path));
    const current = host.current();
    const record = (isCurrent && current ? structuredClone(current) : await st().getProject(id)) ?? newProjectRecord(pulled.server.name, {}).project;
    Object.assign(record, {
      id,
      name: pulled.server.name,
      entry: pulled.server.entry,
      folders: pulled.server.folders,
      server: pulled.server,
      syncDirty: false,
      syncOff: false,
      updatedAt: Date.now(),
    });
    record.open = (record.open ?? []).filter((p) => keep.has(p));
    await st().writeFiles(id, pulled.files, before.filter((p) => !keep.has(p)), record);
    s.conflicts.delete(id);
    s.remoteById.set(pulled.server.id, { ...(s.remoteById.get(pulled.server.id) ?? {}), revision: pulled.server.revision });
    if (isCurrent) {
      await host.afterPull?.(id, [...new Set([...before, ...keep])]);
    }
  }

  /** サーバーにだけあるものを、この端末の新しいプロジェクトとして読み込む。@returns 新しい id */
  async function pullNew(serverId) {
    const { project: record } = newProjectRecord(s.remoteById.get(serverId)?.name ?? host.untitled(), {});
    await st().putProject(record);
    await pullInto(record.id, serverId);
    return record.id;
  }

  /** サーバーの一覧と突き合わせる(開いたとき・戻ってきたとき・一覧を開いたとき) */
  async function refresh() {
    if (!signedIn || !st()) {
      changed();
      return;
    }
    s.lastRefresh = Date.now();
    await host.flush(); // 打ちかけを「まだ送っていない」に数えてから突き合わせる
    const state = await syncClient.state();
    if (!state.signedIn) {
      s.account = '';
      s.canSync = false;
      changed();
      return;
    }
    s.account = state.account ?? '';
    s.canSync = state.canSave === true && s.account !== '';
    s.blockedMessage = state.blocked ?? '';
    s.remoteById = new Map((state.projects ?? []).map((p) => [p.id, p]));
    s.usage = { used: state.usedBytes ?? 0, limits: state.limits ?? null, count: (state.projects ?? []).length };
    s.problem = '';
    if (!s.canSync) {
      return;
    }
    for (const record of await st().listProjects()) {
      const st = remoteState(record, s.account, s.remoteById);
      if (st === 'gone' && record.server) {
        await host.updateRecord(record.id, (r) => {
          r.server = null;
          r.syncOff = 'gone';
        });
      } else if (st === 'remote') {
        await pullInto(record.id, record.server.id);
      } else if (st === 'conflict') {
        s.conflicts.add(record.id);
      } else if (st === 'pending' && !s.errors.has(record.id)) {
        await push(record.id);
      }
    }
  }

  /** しばらく突き合わせていなければ突き合わせる */
  function refreshIfStale(ms = REFRESH_EVERY) {
    if (signedIn && Date.now() - s.lastRefresh > ms) {
      return queue(refresh);
    }
    return chain;
  }

  /**
   * ぶつかりを片付ける。choice: 'theirs'(サーバーのものを読む)| 'mine'(この端末のもので上書き)|
   * 'both'(この端末のものは別のプロジェクトとして残し、サーバーのものを新しく読む)
   * @returns {Promise<string|null>} 'both' のとき読み込んだ新しいプロジェクトの id
   */
  async function resolve(id, choice) {
    const record = host.current()?.id === id ? host.current() : await st().getProject(id);
    if (!record?.server) {
      s.conflicts.delete(id);
      changed();
      return null;
    }
    const serverId = record.server.id;
    let made = null;
    await queue(async () => {
      if (choice === 'theirs') {
        await pullInto(id, serverId);
      } else if (choice === 'mine') {
        s.conflicts.delete(id);
        await push(id, { force: true });
      } else if (choice === 'both') {
        s.conflicts.delete(id);
        await host.updateRecord(id, (r) => {
          r.server = null;
          r.syncDirty = true;
          r.name = cleanName(host.localName(r.name));
        });
        host.afterRename?.(id);
        await push(id);
        made = await pullNew(serverId);
      }
    });
    return made;
  }

  /** サーバーで消されたものを、もう一度送る */
  function resend(id) {
    return queue(async () => {
      await host.updateRecord(id, (r) => {
        r.syncOff = false;
        r.syncDirty = true;
      });
      await push(id);
    });
  }

  /** サーバーのものを消す。@returns 消せたか */
  async function removeServer(serverId) {
    try {
      await queue(async () => {
        await syncClient.remove(serverId);
        s.remoteById.delete(serverId);
      });
    } catch {
      // queue が拾う
    }
    return !s.remoteById.has(serverId);
  }

  /** この端末のプロジェクトを消したときに覚えていたものを捨てる */
  function forget(id) {
    s.conflicts.delete(id);
    s.errors.delete(id);
  }

  /** サーバーにだけあるもの(この端末のどのプロジェクトにもつながっていない) */
  async function serverOnly() {
    if (!s.canSync) {
      return [];
    }
    const linked = new Set((await st().listProjects()).filter((p) => p.server && p.server.account === s.account).map((p) => p.server.id));
    return [...s.remoteById.values()].filter((r) => !linked.has(r.id));
  }

  return {
    get account() { return s.account; },
    get canSync() { return s.canSync; },
    get remoteById() { return s.remoteById; },
    get usage() { return s.usage; },
    get lastRefresh() { return s.lastRefresh; },
    signedIn,
    hasConflict: (id) => s.conflicts.has(id),
    errorOf: (id) => s.errors.get(id),
    timerPending: () => timer.pending(),
    cancelTimer: () => timer.cancel(),
    queue,
    schedule,
    pushNow,
    push: (id, opts) => queue(() => push(id, opts)),
    status,
    stateOf,
    pullNew,
    refresh: () => queue(refresh),
    refreshIfStale,
    resolve,
    resend,
    removeServer,
    forget,
    serverOnly,
  };
}
