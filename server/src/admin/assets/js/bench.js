/*
 * ベンチマーク(2026-11-06。タスクマネージャーの「ベンチマーク」タブ。口は ../api/bench.php、中身は lib/bench.php)。
 *
 * 「測る」で回を始め、段を 1 つずつ POST する(1 回の要求を短く保つ。nginx・PHP の時間の上限に掛からない)。
 * **数字はサーバーが測って保存する。** ここは順に押して、返ってきたものを並べるだけ。
 * 比べる相手は「同じ版の、前の回」が既定。履歴の「見る」で選んだ回を、選んだ相手と比べる。
 */
(() => {
  'use strict';

  const root = document.getElementById('km-bench');
  if (!root) {
    return;
  }
  const src = root.dataset.src || './api/bench.php';
  const csrf = document.querySelector('meta[name="km-csrf"]')?.content ?? '';
  const $ = (key) => root.querySelector(`[data-km-bench="${key}"]`);

  /** 辞書の文。無ければ日本語の既定。{name} を差し込む(taskmgr.js と同じ形) */
  const tr = (key, fallback, vars = {}) => {
    const raw = globalThis.KmI18n?.t(key);
    const text = typeof raw === 'string' && raw !== key ? raw : fallback;
    return text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match));
  };
  const english = () => (globalThis.KmI18n?.current ?? document.documentElement.lang ?? '').startsWith('en');

  let info = null; // GET の答え
  let selectedId = null;
  let baseId = null; // 比べる相手(null = 前の回)
  let running = false;
  let progress = null; // 測っている回の段ごとの状態 { id, steps: {step: {state, result}} }

  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) {
      node.className = cls;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  };
  const fmtScore = (v) => (typeof v === 'number' && Number.isFinite(v) ? Math.round(v).toLocaleString() : '—');
  const fmtTime = (sec) => {
    if (typeof sec !== 'number' || !Number.isFinite(sec)) {
      return '—';
    }
    return sec < 1 ? `${(sec * 1000).toFixed(sec < 0.01 ? 2 : 1)} ms` : `${sec.toFixed(2)} s`;
  };
  const fmtPct = (v) => (typeof v === 'number' && Number.isFinite(v) ? `${v > 0 ? '+' : ''}${v.toFixed(1)}%` : '');
  const pctClass = (v) => (typeof v !== 'number' ? '' : v >= 3 ? 'text-success' : v <= -3 ? 'text-danger' : 'text-body-secondary');
  const stepLabel = (step) => (english() ? step.labelEn : step.label);
  const groupLabel = (id) => {
    const g = info?.groups.find((x) => x.id === id);
    return g ? (english() ? g.labelEn : g.label) : id;
  };
  const sourceLabel = (s) => ({
    admin: tr('page.taskmgr.benchSourceAdmin', '管理画面'),
    cli: tr('page.taskmgr.benchSourceCli', 'ホスト(CLI)'),
    pc: tr('page.taskmgr.benchSourcePc', 'この PC から'),
  }[s] ?? s);
  const statusLabel = (s) => ({
    running: tr('page.taskmgr.benchRunning', '測っています'),
    partial: tr('page.taskmgr.benchPartial', '一部失敗'),
    aborted: tr('page.taskmgr.benchAborted', '中断'),
  }[s] ?? '');

  async function api(body) {
    const res = await fetch(src, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: body ? { 'Content-Type': 'application/json', 'X-KM-CSRF': csrf } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
    let data = null;
    try {
      data = await res.json();
    } catch {
      data = null;
    }
    if (!res.ok || !data) {
      throw new Error(data?.error || `HTTP ${res.status}`);
    }
    return data;
  }

  /** 比べる相手: 選んであればそれ、無ければ同じ版・同じ出どころの、1 つ前の終わった回 */
  function baseFor(run) {
    if (!info) {
      return null;
    }
    if (baseId !== null && baseId !== run.id) {
      return info.runs.find((r) => r.id === baseId) ?? null;
    }
    return info.runs.find((r) => r.id < run.id && r.suiteVersion === run.suiteVersion && r.source === run.source
      && (r.status === 'done' || r.status === 'partial')) ?? null;
  }
  const diff = (a, b) => (typeof a === 'number' && typeof b === 'number' && b > 0 ? (a / b - 1) * 100 : null);

  function setStatus(text, isError = false) {
    const node = $('status');
    node.textContent = text;
    node.classList.toggle('text-danger', isError);
    node.classList.toggle('text-body-secondary', !isError);
  }

  function renderEvent() {
    const node = $('event');
    node.hidden = !info?.event;
    node.textContent = info?.event
      ? tr('page.taskmgr.benchEvent', 'イベント「{name}」の開催中です。測ると、その間は地図の応答が遅くなります。', { name: info.event })
      : '';
  }

  function renderHistory() {
    const head = $('historyHead');
    const body = $('history');
    head.replaceChildren();
    body.replaceChildren();
    if (!info) {
      return;
    }
    const groups = info.groups.map((g) => g.id);
    const tr1 = el('tr');
    [tr('page.taskmgr.benchColDate', '日時'), tr('page.taskmgr.benchColName', '名前'), tr('page.taskmgr.benchColSource', '測った所'),
      tr('page.taskmgr.benchColTotal', '合計')].forEach((t, i) => tr1.append(el('th', i === 3 ? 'text-end text-nowrap' : 'text-nowrap', t)));
    groups.forEach((g) => tr1.append(el('th', 'text-end text-nowrap', groupLabel(g))));
    tr1.append(el('th', '', ''));
    tr1.querySelectorAll('th').forEach((th) => th.setAttribute('scope', 'col'));
    head.append(tr1);

    if (info.runs.length === 0) {
      const row = el('tr');
      const cell = el('td', 'text-body-secondary', tr('page.taskmgr.benchEmpty', 'まだ測っていません。'));
      cell.colSpan = 5 + groups.length;
      row.append(cell);
      body.append(row);
      return;
    }
    info.runs.forEach((run) => {
      const row = el('tr', run.id === selectedId ? 'table-active' : '');
      row.append(el('td', 'text-nowrap', run.startedAt.slice(0, 16)));
      const name = el('td', 'text-break', run.label || `#${run.id}`);
      const status = statusLabel(run.status);
      if (status) {
        name.append(' ', el('span', `badge ${run.status === 'running' ? 'text-bg-info' : 'text-bg-secondary'}`, status));
      }
      if (run.environment?.before?.busy) {
        name.append(' ', el('span', 'badge text-bg-warning', tr('page.taskmgr.benchBusy', '混んでいた')));
      }
      if (run.suiteVersion !== info.suiteVersion) {
        name.append(' ', el('span', 'badge text-bg-light', tr('page.taskmgr.benchOldSuite', '版 {v}', { v: run.suiteVersion })));
      }
      row.append(name);
      row.append(el('td', 'text-nowrap', sourceLabel(run.source)));
      const total = el('td', 'text-end text-nowrap fw-bold', fmtScore(run.totalScore));
      const base = baseFor(run);
      const d = base ? diff(run.totalScore, base.totalScore) : null;
      if (d !== null) {
        total.append(' ', el('span', `fs-7 fw-normal ${pctClass(d)}`, fmtPct(d)));
      }
      row.append(total);
      groups.forEach((g) => row.append(el('td', 'text-end text-nowrap', fmtScore(run.results?.groups?.[g]))));
      const actions = el('td', 'text-end text-nowrap');
      const view = el('button', 'btn btn-sm btn-outline-secondary me-1', tr('page.taskmgr.benchView', '見る'));
      view.type = 'button';
      view.addEventListener('click', () => {
        selectedId = run.id;
        progress = null;
        render();
      });
      const del = el('button', 'btn btn-sm btn-outline-danger', tr('page.taskmgr.benchDelete', '消す'));
      del.type = 'button';
      del.disabled = run.status === 'running' || running;
      del.addEventListener('click', () => removeRun(run));
      actions.append(view, del);
      row.append(actions);
      body.append(row);
    });
  }

  /** 選んだ回(か、測っている回)の段ごとの表 */
  function renderDetail() {
    const box = $('detail');
    box.replaceChildren();
    if (!info) {
      return;
    }
    const run = progress ? null : info.runs.find((r) => r.id === selectedId);
    if (!progress && !run) {
      return;
    }
    const steps = progress ? progress.steps : (run.results?.steps ?? {});
    const base = run ? baseFor(run) : null;

    const title = el('h3', 'km-tm-subtitle');
    title.textContent = progress
      ? tr('page.taskmgr.benchNow', 'いま測っている回')
      : `${run.label || `#${run.id}`} — ${run.startedAt.slice(0, 16)}`;
    box.append(title);

    if (run) {
      // 比べる相手を選ぶ(同じ版だけ)
      const line = el('div', 'd-flex flex-wrap align-items-center gap-2 mb-2 fs-7');
      const label = el('label', 'mb-0', tr('page.taskmgr.benchCompare', '比べる相手'));
      label.htmlFor = 'km-bench-base';
      const select = el('select', 'form-select form-select-sm w-auto');
      select.id = 'km-bench-base';
      select.append(new Option(tr('page.taskmgr.benchPrevious', '前の回(同じ所)'), ''));
      info.runs.filter((r) => r.id !== run.id && r.suiteVersion === run.suiteVersion && r.status !== 'running').forEach((r) => {
        select.append(new Option(`${r.startedAt.slice(0, 16)} ${r.label || `#${r.id}`}(${sourceLabel(r.source)})`, String(r.id)));
      });
      select.value = baseId !== null ? String(baseId) : '';
      select.addEventListener('change', () => {
        baseId = select.value === '' ? null : Number(select.value);
        render();
      });
      line.append(label, select);
      if (base) {
        line.append(el('span', 'text-body-secondary', `→ ${base.startedAt.slice(0, 16)} ${base.label || `#${base.id}`}`));
      }
      box.append(line);

      const env = run.environment?.before ?? {};
      const after = run.environment?.after ?? {};
      const facts = [];
      if (env.cores) {
        facts.push(`${tr('page.taskmgr.benchCores', 'コア')} ${env.cores}`);
      }
      if (env.cpuModel) {
        facts.push(env.cpuModel);
      }
      if (typeof env.load1 === 'number') {
        facts.push(`load ${env.load1.toFixed(2)}${typeof after.load1 === 'number' ? ` → ${after.load1.toFixed(2)}` : ''}`);
      }
      if (typeof env.apacheBusy === 'number') {
        facts.push(`Apache ${tr('page.taskmgr.benchBusyWorkers', '忙しいワーカー')} ${env.apacheBusy}`);
      }
      if (env.php) {
        facts.push(`PHP ${env.php}`);
      }
      if (run.actor) {
        facts.push(run.actor);
      }
      if (facts.length > 0) {
        box.append(el('p', 'fs-7 text-body-secondary mb-2', facts.join('・')));
      }
    }

    const wrap = el('div', 'table-responsive');
    const table = el('table', 'table table-sm align-middle mb-0 km-tm-table');
    const thead = el('thead');
    const hr = el('tr');
    [[tr('page.taskmgr.benchColStep', '段'), ''], [tr('page.taskmgr.benchColMedian', '中央値'), 'text-end'],
      [tr('page.taskmgr.benchColReps', '回数'), 'text-end'], [tr('page.taskmgr.benchColCv', 'ばらつき'), 'text-end'],
      [tr('page.taskmgr.benchColScore', 'スコア'), 'text-end'], [tr('page.taskmgr.benchColDiff', '差'), 'text-end'],
      [tr('page.taskmgr.benchColNote', 'メモ'), '']].forEach(([t, c]) => {
      const th = el('th', c ? `${c} text-nowrap` : 'text-nowrap', t);
      th.scope = 'col';
      hr.append(th);
    });
    thead.append(hr);
    const tbody = el('tbody');
    const order = [...info.steps];
    if (steps['web.pc']) {
      order.push({ id: 'web.pc', group: 'pc', label: 'Web: この PC から(TLS)', labelEn: 'Web: from this PC (TLS)', inTotal: false });
    }
    order.forEach((step) => {
      const entry = progress ? steps[step.id] : { state: steps[step.id] ? 'done' : 'none', result: steps[step.id] };
      if (!progress && !steps[step.id]) {
        return;
      }
      const r = entry?.result ?? null;
      const row = el('tr');
      const name = el('td', '', stepLabel(step));
      if (!step.inTotal) {
        name.append(' ', el('span', 'badge text-bg-light', tr('page.taskmgr.benchNotInTotal', '合計に入れない')));
      }
      row.append(name);
      if (entry?.state === 'running') {
        const cell = el('td', 'text-body-secondary', tr('page.taskmgr.benchMeasuring', '測っています…'));
        cell.colSpan = 6;
        row.append(cell);
      } else if (entry?.state === 'waiting' || !r) {
        const cell = el('td', 'text-body-secondary', entry?.state === 'waiting' ? tr('page.taskmgr.benchWaiting', '待っています') : '—');
        cell.colSpan = 6;
        row.append(cell);
      } else if (r.error) {
        const cell = el('td', 'text-danger', r.error);
        cell.colSpan = 6;
        row.append(cell);
      } else {
        row.append(el('td', 'text-end text-nowrap', fmtTime(r.median)));
        row.append(el('td', 'text-end', r.reps ?? ''));
        const cv = el('td', 'text-end text-nowrap', typeof r.cv === 'number' ? `${(r.cv * 100).toFixed(1)}%` : '—');
        if (r.unstable) {
          cv.append(' ', el('span', 'badge text-bg-warning', tr('page.taskmgr.benchUnstable', 'ばらつき大')));
        }
        row.append(cv);
        row.append(el('td', 'text-end text-nowrap fw-bold', fmtScore(r.score)));
        const baseScore = base?.results?.steps?.[step.id]?.score;
        const d = diff(r.score, baseScore);
        row.append(el('td', `text-end text-nowrap ${pctClass(d)}`, fmtPct(d)));
        row.append(el('td', 'fs-7 text-body-secondary', noteFor(step.id, r)));
      }
      tbody.append(row);
    });
    table.append(thead, tbody);
    wrap.append(table);
    box.append(wrap);
  }

  function noteFor(id, r) {
    const x = r.extra ?? {};
    if (id === 'web' || id === 'web.pc') {
      const parts = [`${x.rps ?? '—'} req/s`, `p50 ${x.p50 ?? '—'} ms`, `p95 ${x.p95 ?? '—'} ms`];
      if (x.errors) {
        parts.push(tr('page.taskmgr.benchErrors', '失敗 {n}', { n: x.errors }));
      }
      return parts.join('・');
    }
    if (id === 'cpu.parallel' && x.workers) {
      return tr('page.taskmgr.benchWorkers', '{n} 本同時', { n: x.workers });
    }
    if (id === 'app' && x.nodes !== undefined) {
      return tr('page.taskmgr.benchAppNote', '地点 {n}・{kb} KB', { n: x.nodes, kb: Math.round((x.bytes ?? 0) / 1024) });
    }
    return '';
  }

  function render() {
    renderEvent();
    renderHistory();
    renderDetail();
    $('start').disabled = running;
  }

  async function load() {
    try {
      info = await api(null);
      if (selectedId === null && info.runs.length > 0) {
        selectedId = info.runs[0].id;
      }
      render();
    } catch (error) {
      setStatus(tr('page.taskmgr.benchLoadFailed', '読み込めませんでした({msg})', { msg: error.message }), true);
    }
  }

  async function start(event) {
    event.preventDefault();
    if (running || !info) {
      return;
    }
    if (info.event && !window.confirm(tr('page.taskmgr.benchEventConfirm', 'イベントの開催中です。それでも測りますか?'))) {
      return;
    }
    running = true;
    const label = document.getElementById('km-bench-label').value;
    progress = { steps: {} };
    info.steps.forEach((s) => {
      progress.steps[s.id] = { state: 'waiting', result: null };
    });
    render();
    const startedAt = Date.now();
    try {
      setStatus(tr('page.taskmgr.benchStarting', '始めています…'));
      const begun = await api({ action: 'start', label });
      for (const [index, step] of begun.steps.entries()) {
        progress.steps[step] = { state: 'running', result: null };
        setStatus(tr('page.taskmgr.benchProgress', '{i} / {n} を測っています…', { i: index + 1, n: begun.steps.length }));
        renderDetail();
        try {
          const done = await api({ action: 'step', id: begun.id, step });
          progress.steps[step] = { state: 'done', result: done.result };
        } catch (error) {
          progress.steps[step] = { state: 'done', result: { error: error.message } };
        }
        renderDetail();
      }
      await api({ action: 'finish', id: begun.id });
      selectedId = begun.id;
      setStatus(tr('page.taskmgr.benchDone', '測り終えました({s} 秒)', { s: Math.round((Date.now() - startedAt) / 1000) }));
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      running = false;
      progress = null;
      await load();
    }
  }

  async function removeRun(run) {
    if (running || !window.confirm(tr('page.taskmgr.benchDeleteConfirm', '「{name}」の結果を消しますか?', { name: run.label || `#${run.id}` }))) {
      return;
    }
    try {
      await api({ action: 'delete', id: run.id });
      if (selectedId === run.id) {
        selectedId = null;
      }
      if (baseId === run.id) {
        baseId = null;
      }
      setStatus(tr('page.taskmgr.benchDeleted', '消しました。'));
      await load();
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  $('form').addEventListener('submit', start);

  // タブを開いたときに初めて読む(タスクマネージャーを開くたびに DB を読まない)
  let loaded = false;
  const panel = document.getElementById('km-tm-panel-bench');
  const ensure = () => {
    if (!loaded && panel && !panel.hidden) {
      loaded = true;
      load();
    }
  };
  if (panel) {
    new MutationObserver(ensure).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  }
  // i18n.js はこのファイルの後で読まれる。揃ってから動かす(taskmgr.js と同じ)
  const boot = () => {
    document.addEventListener(globalThis.KmI18n?.event ?? 'changed.km.lang', () => {
      if (info) {
        render();
      }
    });
    ensure();
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
