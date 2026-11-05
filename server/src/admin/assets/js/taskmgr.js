/*
 * タスクマネージャー(2026-10-05、利用者の指示)。
 *
 * admin/api/taskmgr-data.php が渡す**生の値**を、ここ(ブラウザ)で割合・単位・使用率・グラフに直す。
 * サーバーは変換しない(メモリと CPU を使わせない。利用者の指示「変換はクライアント側で」)。
 *
 *   glances … Glances の API の値(ホスト全体)
 *   apache  … /server-status?auto のテキスト
 *   host    … scripts/host-stats.sh の JSON(1 分ごと。コンテナ別の CPU と通信の速さは前回との差から出す)
 *
 * **Windows のタスクマネージャーの形**(2026-11-02、利用者の指示)。左の縦のタブで
 * プロセス / パフォーマンス / コンテナ / サービス / 詳細 を切り替える。パフォーマンスは左に項目と小さなグラフ、
 * 右に選んだ項目の大きなグラフと数値。グラフは素の SVG(CSP で style 属性が使えないので、色は CSS のクラス)。
 *
 * 1〜10 秒(画面で選ぶ。既定 5 秒・このブラウザに覚える)ごとに読む。
 * **タブが隠れている間は読まない**(誰も見ていないのにサーバーを叩かない)。
 * 前の読み込みが終わるまで次を出さない(遅い回線で 1 秒にしても要求が積み上がらない)。
 * 文字はすべて textContent で入れる(コンテナ名・プロセス名を HTML として読まない)。
 */
(() => {
  const root = document.getElementById('km-taskmgr');
  if (!root) {
    return;
  }
  const INTERVAL_KEY = 'kmadmin-taskmgr-interval';
  const INTERVAL_DEFAULT = 5;
  const HISTORY = 60;
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const SERVICES_INTERVAL_MS = 30000;

  const history = { cpu: [], mem: [], swap: [], disk: [], netRx: [], netTx: [] };
  let lastData = null;
  let previousHost = null;
  let containerCpu = {};
  let netRate = null;
  let previousDisk = null;
  let diskRate = null;
  let timer = null;
  let selectedMetric = 'cpu';
  let activeTab = root.dataset.tab || 'processes';
  const sortState = {
    processes: { key: 'cpu', dir: -1 },
    containers: { key: 'mem', dir: -1 },
  };

  // ---- 文字と数の形 ----

  /** 辞書(ja.js / en.js)の文。無ければ日本語の既定。{name} を差し込む */
  const tr = (key, fallback, vars = {}) => {
    const raw = globalThis.KmI18n?.t(key);
    const text = typeof raw === 'string' && raw !== key ? raw : fallback;
    return text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match));
  };
  const $ = (key) => root.querySelector(`[data-km-tm="${key}"]`);
  const num = (v) => (typeof v === 'number' && Number.isFinite(v) ? v : null);
  const pct = (v, digits = 1) => (v === null ? '—' : `${v.toFixed(digits)}%`);
  const bytes = (v) => {
    if (v === null || v === undefined || !Number.isFinite(Number(v))) {
      return '—';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let n = Number(v);
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
      n /= 1024;
      i += 1;
    }
    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  };
  const perSecond = (v) => (v === null ? '—' : `${bytes(v)}/s`);
  /** 稼働時間は Windows と同じ「日:時:分:秒」(言語によらない) */
  const duration = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    const two = (n) => String(n).padStart(2, '0');
    return `${Math.floor(s / 86400)}:${two(Math.floor((s % 86400) / 3600))}:${two(Math.floor((s % 3600) / 60))}:${two(s % 60)}`;
  };
  const setText = (key, text) => {
    const el = $(key);
    if (el) {
      el.textContent = text;
    }
  };
  /** 値の大きさで表のセルを色付けする(Windows のプロセスの表と同じ。0〜4) */
  const heat = (value, steps) => {
    if (value === null) {
      return 0;
    }
    return steps.filter((step) => value >= step).length;
  };
  const cell = (text, { end = false, heatLevel = 0, mono = false } = {}) => {
    const td = document.createElement('td');
    td.textContent = text;
    const classes = [];
    if (end) {
      classes.push('text-end');
    }
    if (mono) {
      classes.push('font-monospace');
    }
    if (heatLevel > 0) {
      classes.push(`km-tm-heat-${heatLevel}`);
    }
    td.className = classes.join(' ');
    return td;
  };
  const kvRow = (label, value) => {
    const tr2 = document.createElement('tr');
    const th = document.createElement('th');
    th.scope = 'row';
    th.className = 'fw-normal text-body-secondary';
    th.textContent = label;
    tr2.append(th, cell(value));
    return tr2;
  };
  const fill = (key, rows) => {
    const body = $(key);
    if (body) {
      body.replaceChildren(...rows);
    }
  };
  const messageRow = (text, span) => {
    const tr2 = document.createElement('tr');
    const td = cell(text);
    td.colSpan = span;
    td.className = 'text-body-secondary';
    tr2.appendChild(td);
    return tr2;
  };

  // ---- グラフ(素の SVG。色は親の km-tm-c-… が持つ --km-tm-color) ----

  /** 転送量の目盛りの上限(1・2・5 の倍数に切り上げる) */
  const niceMax = (value) => {
    if (!(value > 0)) {
      return 1024;
    }
    const power = 10 ** Math.floor(Math.log10(value));
    return [1, 2, 5, 10].map((m) => m * power).find((candidate) => candidate >= value) ?? value;
  };
  const svgEl = (name, attrs) => {
    const el = document.createElementNS(SVG_NS, name);
    Object.entries(attrs).forEach(([key, value]) => el.setAttribute(key, String(value)));
    return el;
  };
  /**
   * @param {SVGElement} svg
   * @param {{values: (number|null)[], dashed?: boolean}[]} series
   * @param {number} max 縦軸の上限
   * @param {boolean} grid 方眼を描くか(大きなグラフだけ)
   */
  const drawGraph = (svg, series, max, grid) => {
    if (!svg) {
      return;
    }
    const [, , width, height] = (svg.getAttribute('viewBox') || '0 0 600 200').split(' ').map(Number);
    const children = [];
    if (grid) {
      for (let i = 1; i < 10; i += 1) {
        children.push(svgEl('line', { class: 'km-tm-gridline', x1: (width * i) / 10, x2: (width * i) / 10, y1: 0, y2: height, 'vector-effect': 'non-scaling-stroke' }));
      }
      for (let i = 1; i < 5; i += 1) {
        children.push(svgEl('line', { class: 'km-tm-gridline', x1: 0, x2: width, y1: (height * i) / 5, y2: (height * i) / 5, 'vector-effect': 'non-scaling-stroke' }));
      }
    }
    const step = width / (HISTORY - 1);
    series.forEach(({ values, dashed = false }) => {
      const offset = (HISTORY - values.length) * step;
      const points = [];
      values.forEach((v, i) => {
        if (v !== null && v !== undefined) {
          const y = height - (Math.min(max, Math.max(0, v)) / max) * height;
          points.push([offset + i * step, y]);
        }
      });
      if (points.length < 2) {
        return;
      }
      const line = points.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
      if (!dashed) {
        const first = points[0][0].toFixed(1);
        const last = points[points.length - 1][0].toFixed(1);
        children.push(svgEl('path', { class: 'km-tm-area', d: `M${first},${height} L${line.replaceAll(' ', ' L')} L${last},${height} Z` }));
      }
      children.push(svgEl('polyline', { class: `km-tm-line${dashed ? ' km-tm-line-dashed' : ''}`, points: line, 'vector-effect': 'non-scaling-stroke' }));
    });
    svg.replaceChildren(...children);
  };

  const pushHistory = (name, value) => {
    history[name].push(value);
    if (history[name].length > HISTORY) {
      history[name].shift();
    }
  };

  // ---- 受け取った値から、ブラウザで出す値 ----

  /** ディスク全体だけ(vda1 のような区画と、loop・sr・ram は数えない) */
  const wholeDisks = (diskio) => {
    const list = Array.isArray(diskio) ? diskio : [];
    const names = list.map((d) => String(d.disk_name ?? ''));
    return list.filter((d) => {
      const name = String(d.disk_name ?? '');
      return name !== '' && !/^(loop|ram|sr|fd|dm-)/.test(name)
        && !names.some((other) => other !== name && name.startsWith(other));
    });
  };

  const sample = (data) => {
    const g = data.glances || {};
    const host = data.host || null;
    pushHistory('cpu', num(g.cpu?.total));
    pushHistory('mem', num(g.mem?.percent));
    pushHistory('swap', num(g.memswap?.percent));

    // ディスク: 読み書きの合計と「動いていた時間」を、前回との差から(この画面の時計で測る)
    const disks = wholeDisks(g.diskio);
    const now = performance.now();
    if (disks.length > 0) {
      const sum = (field) => disks.reduce((total, d) => total + (num(d[field]) ?? 0), 0);
      const current = { at: now, read: sum('read_bytes_gauge'), write: sum('write_bytes_gauge'), busy: sum('read_time_gauge') + sum('write_time_gauge') };
      if (previousDisk) {
        const ms = current.at - previousDisk.at;
        if (ms > 0) {
          diskRate = {
            read: Math.max(0, ((current.read - previousDisk.read) * 1000) / ms),
            write: Math.max(0, ((current.write - previousDisk.write) * 1000) / ms),
            // 1 台なら 100% まで。複数台は平均にする
            active: Math.min(100, Math.max(0, ((current.busy - previousDisk.busy) / ms / disks.length) * 100)),
          };
        }
      }
      previousDisk = current;
    }
    pushHistory('disk', diskRate ? diskRate.active : null);

    // 通信の速さとコンテナ別の CPU は、集計(1 分ごと)が新しくなったときだけ前回との差から出す
    if (host && host.host) {
      if (previousHost && previousHost.generatedAt !== host.generatedAt) {
        const seconds = host.generatedAt - previousHost.generatedAt;
        if (seconds > 0) {
          netRate = {
            rx: Math.max(0, (host.host.netRx - previousHost.host.netRx) / seconds),
            tx: Math.max(0, (host.host.netTx - previousHost.host.netTx) / seconds),
          };
          const before = Object.fromEntries((previousHost.containers || []).map((c) => [c.name, c.cpuUsageUsec]));
          containerCpu = {};
          (host.containers || []).forEach((c) => {
            if (before[c.name] !== undefined) {
              containerCpu[c.name] = Math.max(0, ((c.cpuUsageUsec - before[c.name]) / 1e6 / seconds) * 100);
            }
          });
        }
      }
      if (!previousHost || previousHost.generatedAt !== host.generatedAt) {
        previousHost = host;
      }
    }
    pushHistory('netRx', netRate ? netRate.rx : null);
    pushHistory('netTx', netRate ? netRate.tx : null);
  };

  // ---- パフォーマンス ----

  /** 項目ごとの見せ方。available が false の項目は左の一覧に出さない */
  const metricView = (id, data) => {
    const g = data.glances || {};
    const q = g.quicklook || {};
    const h = data.host?.host || null;
    const uptime = h ? duration(h.uptime) : (typeof g.uptime === 'string' ? g.uptime : '—');
    switch (id) {
      case 'cpu': {
        const value = num(g.cpu?.total);
        const ghz = num(q.cpu_hz_current) ? `${(q.cpu_hz_current / 1e9).toFixed(2)} GHz` : '';
        return {
          available: value !== null,
          name: tr('page.taskmgr.cpu', 'CPU'),
          model: String(q.cpu_name || ''),
          unit: tr('page.taskmgr.utilPercent', '% 使用率'),
          max: 100,
          maxLabel: '100%',
          series: [{ values: history.cpu }],
          line: `${pct(value, 0)} ${ghz}`.trim(),
          big: [
            [tr('page.taskmgr.util', '使用率'), pct(value, 0)],
            [tr('page.taskmgr.speed', '速度'), ghz || '—'],
            [tr('page.taskmgr.processes', 'プロセス'), String(g.processcount?.total ?? '—')],
            [tr('page.taskmgr.threads', 'スレッド'), String(g.processcount?.thread ?? '—')],
            [tr('page.taskmgr.uptime', '稼働時間'), uptime],
          ],
          kv: [
            [tr('page.taskmgr.cores', 'コア'), String(q.cpu_phys_core ?? g.load?.cpucore ?? '—')],
            [tr('page.taskmgr.logical', '論理プロセッサ'), String(q.cpu_log_core ?? g.load?.cpucore ?? '—')],
            [tr('page.taskmgr.load', '負荷(1・5・15 分)'), g.load ? [g.load.min1, g.load.min5, g.load.min15].map((v) => (num(v) ?? 0).toFixed(2)).join(' / ') : '—'],
            [tr('page.taskmgr.interrupts', '割り込み/秒'), String(g.cpu?.interrupts_rate_per_sec ?? '—')],
            [tr('page.taskmgr.ctxSwitches', 'コンテキスト切り替え/秒'), String(g.cpu?.ctx_switches_rate_per_sec ?? '—')],
          ],
        };
      }
      case 'mem': {
        const m = g.mem || null;
        const value = num(m?.percent);
        return {
          available: value !== null,
          name: tr('page.taskmgr.mem', 'メモリ'),
          model: m ? bytes(m.total) : '',
          unit: tr('page.taskmgr.utilPercent', '% 使用率'),
          max: 100,
          maxLabel: m ? bytes(m.total) : '100%',
          series: [{ values: history.mem }],
          line: m ? `${bytes(m.used)}/${bytes(m.total)} (${pct(value, 0)})` : '—',
          big: [
            [tr('page.taskmgr.inUse', '使用中'), m ? bytes(m.used) : '—'],
            [tr('page.taskmgr.available', '利用可能'), m ? bytes(m.available) : '—'],
            [tr('page.taskmgr.cached', 'キャッシュ'), m ? bytes(m.cached) : '—'],
            [tr('page.taskmgr.util', '使用率'), pct(value, 0)],
          ],
          kv: [
            [tr('page.taskmgr.total', '合計'), m ? bytes(m.total) : '—'],
            [tr('page.taskmgr.free', '空き'), m ? bytes(m.free) : '—'],
            [tr('page.taskmgr.buffers', 'バッファ'), m ? bytes(m.buffers) : '—'],
            [tr('page.taskmgr.shared', '共有'), m ? bytes(m.shared) : '—'],
          ],
        };
      }
      case 'swap': {
        const s = g.memswap || null;
        const value = num(s?.percent);
        return {
          available: value !== null && (num(s?.total) ?? 0) > 0,
          name: tr('page.taskmgr.swap', 'スワップ'),
          model: s ? bytes(s.total) : '',
          unit: tr('page.taskmgr.utilPercent', '% 使用率'),
          max: 100,
          maxLabel: s ? bytes(s.total) : '100%',
          series: [{ values: history.swap }],
          line: s ? `${bytes(s.used)}/${bytes(s.total)} (${pct(value, 0)})` : '—',
          big: [
            [tr('page.taskmgr.inUse', '使用中'), s ? bytes(s.used) : '—'],
            [tr('page.taskmgr.free', '空き'), s ? bytes(s.free) : '—'],
            [tr('page.taskmgr.util', '使用率'), pct(value, 0)],
          ],
          kv: [
            [tr('page.taskmgr.total', '合計'), s ? bytes(s.total) : '—'],
            [tr('page.taskmgr.swapIn', '読み込み(起動から)'), s ? bytes(s.sin) : '—'],
            [tr('page.taskmgr.swapOut', '書き出し(起動から)'), s ? bytes(s.sout) : '—'],
          ],
        };
      }
      case 'disk': {
        const disks = wholeDisks(g.diskio).map((d) => d.disk_name).join(', ');
        const used = h && h.diskTotal > 0 ? (h.diskUsed / h.diskTotal) * 100 : null;
        return {
          available: disks !== '' || h !== null,
          name: disks ? `${tr('page.taskmgr.disk', 'ディスク')} (${disks})` : tr('page.taskmgr.disk', 'ディスク'),
          model: h ? bytes(h.diskTotal) : '',
          unit: tr('page.taskmgr.activeTime', 'アクティブな時間'),
          max: 100,
          maxLabel: '100%',
          series: [{ values: history.disk }],
          line: `${diskRate ? pct(diskRate.active, 0) : '…'}${used !== null ? ` · ${tr('page.taskmgr.capacityUsed', '容量 {pct}', { pct: pct(used, 0) })}` : ''}`,
          big: [
            [tr('page.taskmgr.activeTime', 'アクティブな時間'), diskRate ? pct(diskRate.active, 0) : '…'],
            [tr('page.taskmgr.readSpeed', '読み取り速度'), diskRate ? perSecond(diskRate.read) : '…'],
            [tr('page.taskmgr.writeSpeed', '書き込み速度'), diskRate ? perSecond(diskRate.write) : '…'],
          ],
          kv: [
            [tr('page.taskmgr.capacity', '容量'), h ? bytes(h.diskTotal) : '—'],
            [tr('page.taskmgr.inUse', '使用中'), h ? `${bytes(h.diskUsed)} (${pct(used, 0)})` : '—'],
            [tr('page.taskmgr.free', '空き'), h ? bytes(h.diskAvail) : '—'],
          ],
        };
      }
      case 'net': {
        const top = niceMax(Math.max(0, ...history.netRx.filter((v) => v !== null), ...history.netTx.filter((v) => v !== null)) * 1.1);
        return {
          available: h !== null,
          name: tr('page.taskmgr.net', 'ネットワーク'),
          model: tr('page.taskmgr.netScope', 'lo・Docker の網を除く'),
          unit: tr('page.taskmgr.throughput', '転送量(実線 受信・点線 送信)'),
          max: top,
          maxLabel: perSecond(top),
          series: [{ values: history.netRx }, { values: history.netTx, dashed: true }],
          line: netRate ? `${tr('page.taskmgr.recvShort', '受')} ${perSecond(netRate.rx)} ${tr('page.taskmgr.sendShort', '送')} ${perSecond(netRate.tx)}` : '…',
          big: [
            [tr('page.taskmgr.recv', '受信'), netRate ? perSecond(netRate.rx) : '…'],
            [tr('page.taskmgr.send', '送信'), netRate ? perSecond(netRate.tx) : '…'],
          ],
          kv: [
            [tr('page.taskmgr.recvTotal', '受信の合計'), h ? bytes(h.netRx) : '—'],
            [tr('page.taskmgr.sendTotal', '送信の合計'), h ? bytes(h.netTx) : '—'],
            [tr('page.taskmgr.netNote', '速さの出どころ'), tr('page.taskmgr.perMinute', '1 分ごとの集計の差')],
          ],
        };
      }
      default:
        return { available: false };
    }
  };

  const renderPerformance = (data) => {
    const buttons = [...root.querySelectorAll('[data-km-tm-metric]')];
    const views = {};
    buttons.forEach((button) => {
      const id = button.dataset.kmTmMetric;
      const view = metricView(id, data);
      views[id] = view;
      button.hidden = !view.available;
      if (!view.available) {
        return;
      }
      setText(`line-${id}`, view.line);
      drawGraph($(`mini-${id}`), view.series, view.max, false);
    });
    if (!views[selectedMetric]?.available) {
      selectedMetric = buttons.map((b) => b.dataset.kmTmMetric).find((id) => views[id]?.available) ?? 'cpu';
    }
    buttons.forEach((button) => {
      const on = button.dataset.kmTmMetric === selectedMetric;
      button.classList.toggle('active', on);
      button.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    const view = views[selectedMetric];
    const main = $('main');
    if (!main || !view?.available) {
      return;
    }
    main.className = `km-tm-main km-tm-c-${selectedMetric}`;
    setText('mainName', view.name);
    setText('mainModel', view.model);
    setText('mainUnit', view.unit);
    setText('mainMax', view.maxLabel);
    setText('mainSpan', tr('page.taskmgr.span', '{s} 秒', { s: HISTORY * seconds }));
    drawGraph($('graph'), view.series, view.max, true);
    const big = $('mainBig');
    if (big) {
      big.replaceChildren(...view.big.map(([label, value]) => {
        const item = document.createElement('div');
        item.className = 'km-tm-big-item';
        const l = document.createElement('div');
        l.className = 'km-tm-big-label';
        l.textContent = label;
        const v = document.createElement('div');
        v.className = 'km-tm-big-value';
        v.textContent = value;
        item.append(l, v);
        return item;
      }));
    }
    const kv = $('mainKv');
    if (kv) {
      kv.replaceChildren(...view.kv.flatMap(([label, value]) => {
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.textContent = value;
        return [dt, dd];
      }));
    }
  };

  // ---- プロセス・コンテナ(並べ替えのできる表) ----

  const compare = (a, b, key, dir) => {
    const x = a[key];
    const y = b[key];
    if (typeof x === 'string' || typeof y === 'string') {
      return String(x ?? '').localeCompare(String(y ?? '')) * dir;
    }
    return ((x ?? -1) - (y ?? -1)) * dir;
  };
  const sorted = (table, rows) => {
    const { key, dir } = sortState[table];
    return [...rows].sort((a, b) => compare(a, b, key, dir));
  };
  const markSort = () => {
    root.querySelectorAll('[data-km-tm-sort]').forEach((button) => {
      const [table, key] = button.dataset.kmTmSort.split(':');
      const on = sortState[table]?.key === key;
      const icon = button.querySelector('.km-tm-sort-icon');
      if (icon) {
        icon.classList.toggle('bi-caret-down-fill', on && sortState[table].dir < 0);
        icon.classList.toggle('bi-caret-up-fill', on && sortState[table].dir > 0);
      }
      button.closest('th')?.setAttribute('aria-sort', on ? (sortState[table].dir < 0 ? 'descending' : 'ascending') : 'none');
    });
  };

  const STATUS_TEXT = {
    R: ['page.taskmgr.st.R', '実行中'],
    S: ['page.taskmgr.st.S', '待機'],
    D: ['page.taskmgr.st.D', 'ディスク待ち'],
    I: ['page.taskmgr.st.I', 'アイドル'],
    T: ['page.taskmgr.st.T', '停止'],
    Z: ['page.taskmgr.st.Z', 'ゾンビ'],
  };

  const renderProcesses = (data) => {
    const g = data.glances || {};
    setText('procSum-cpu', pct(num(g.cpu?.total), 0));
    setText('procSum-mem', pct(num(g.mem?.percent), 0));
    const list = Array.isArray(g.processlist) ? g.processlist : [];
    if (list.length === 0) {
      fill('processes', [messageRow(tr('page.taskmgr.noGlances', 'Glances から取れません'), 7)]);
      return;
    }
    const rows = list.map((p) => ({
      name: String(p.name ?? ''),
      pid: num(p.pid),
      user: String(p.username ?? ''),
      status: String(p.status ?? ''),
      cpu: num(p.cpu_percent),
      mem: num(p.memory_info?.rss ?? null),
      memPercent: num(p.memory_percent),
      threads: num(p.num_threads),
    }));
    fill('processes', sorted('processes', rows).map((p) => {
      const tr2 = document.createElement('tr');
      const [statusKey, statusFallback] = STATUS_TEXT[p.status] ?? [null, p.status];
      tr2.append(
        cell(p.name),
        cell(p.pid === null ? '' : String(p.pid), { end: true, mono: true }),
        cell(p.user),
        cell(statusKey ? tr(statusKey, statusFallback) : statusFallback),
        cell(pct(p.cpu), { end: true, heatLevel: heat(p.cpu, [1, 10, 30, 60]) }),
        cell(bytes(p.mem), { end: true, heatLevel: heat(p.memPercent, [2, 5, 10, 20]) }),
        cell(p.threads === null ? '' : String(p.threads), { end: true }),
      );
      return tr2;
    }));
    fitProcesses();
  };

  /*
   * プロセスの行数を画面の高さに合わせる(2026-11-03)。広い画面では表の枠(.km-tm-fill)が
   * 残りの高さを受け取るので、入るだけ出して残りは hidden にする。狭い画面と、低くて入らない
   * ときは 15 行(枠の中でスクロール)。タブを開いたときや窓の大きさが変わったときは下の
   * ResizeObserver が数え直す。
   */
  const PROCESS_MIN_ROWS = 15;
  const wideLayout = window.matchMedia('(min-width: 992px)');
  const fitProcesses = () => {
    const body = $('processes');
    const wrap = body?.closest('.km-tm-fill');
    if (!body || !wrap) {
      return;
    }
    const rows = [...body.rows];
    rows.forEach((row) => { row.hidden = false; });
    let fits = PROCESS_MIN_ROWS;
    const rowHeight = rows[0]?.offsetHeight ?? 0;
    if (wideLayout.matches && wrap.clientHeight > 0 && rowHeight > 0) {
      const head = wrap.querySelector('thead')?.offsetHeight ?? 0;
      fits = Math.max(PROCESS_MIN_ROWS, Math.floor((wrap.clientHeight - head) / rowHeight));
    }
    rows.forEach((row, i) => { row.hidden = i >= fits; });
  };

  const renderContainers = (data) => {
    const host = data.host || null;
    const hostMem = num(data.glances?.mem?.total) ?? num(host?.host?.memTotal);
    if (!host || !Array.isArray(host.containers)) {
      fill('containers', [messageRow(tr('page.taskmgr.noHost', 'ホストの集計がありません(host-stats.sh の cron を確かめてください)'), 7)]);
      globalThis.KmServices?.setContainers?.(null);
      return;
    }
    const rows = host.containers.map((c) => ({
      name: String(c.name ?? ''),
      cpu: containerCpu[c.name] ?? null,
      mem: num(c.memCurrent),
      limit: num(c.memMax) || null,
      swap: num(c.swapCurrent),
      oom: num(c.oomKill) ?? 0,
      log: num(c.logBytes),
    }));
    fill('containers', sorted('containers', rows).map((c) => {
      const tr2 = document.createElement('tr');
      const memShare = c.limit ? (c.mem / c.limit) * 100 : (hostMem ? (c.mem / hostMem) * 100 : null);
      tr2.append(
        cell(c.name),
        cell(c.cpu === null ? '…' : pct(c.cpu), { end: true, heatLevel: heat(c.cpu, [1, 10, 30, 60]) }),
        cell(bytes(c.mem), { end: true, heatLevel: heat(memShare, [25, 50, 75, 90]) }),
        cell(c.limit ? bytes(c.limit) : '—', { end: true }),
        cell(bytes(c.swap), { end: true }),
        cell(String(c.oom), { end: true, heatLevel: c.oom > 0 ? 4 : 0 }),
        cell(bytes(c.log), { end: true }),
      );
      return tr2;
    }));
    // サービスの表の「コンテナ」の欄(services.js が描く)
    globalThis.KmServices?.setContainers?.(Object.fromEntries(rows.map((c) => [
      c.name,
      `${bytes(c.mem)}${c.cpu === null ? '' : ` · CPU ${pct(c.cpu)}`}`,
    ])));
  };

  // ---- 詳細 ----

  const parseApache = (text) => {
    const out = {};
    (text || '').split('\n').forEach((line) => {
      const at = line.indexOf(':');
      if (at > 0) {
        out[line.slice(0, at).trim()] = line.slice(at + 1).trim();
      }
    });
    return out;
  };

  const renderDetails = (data) => {
    const g = data.glances || {};
    const host = data.host || null;
    const rows = [];
    // Glances の OS 名はコンテナ(Alpine)のもの。カーネルだけがホストの値
    if (g.system?.os_version) {
      rows.push(kvRow(tr('page.taskmgr.kernel', 'カーネル'), String(g.system.os_version)));
    }
    if (g.quicklook?.cpu_name) {
      rows.push(kvRow('CPU', String(g.quicklook.cpu_name)));
    }
    if (host && host.host) {
      const h = host.host;
      rows.push(
        kvRow(tr('page.taskmgr.uptime', '稼働時間'), duration(h.uptime)),
        kvRow(tr('page.taskmgr.recvTotal', '受信の合計'), bytes(h.netRx)),
        kvRow(tr('page.taskmgr.sendTotal', '送信の合計'), bytes(h.netTx)),
        kvRow(tr('page.taskmgr.generatedAt', '集計の時刻'), new Date(host.generatedAt * 1000).toLocaleString()),
      );
    }
    fill('hostTable', rows.length ? rows : [messageRow(tr('page.taskmgr.noHost', 'ホストの集計がありません(host-stats.sh の cron を確かめてください)'), 2)]);

    const a = parseApache(data.apache);
    fill('apache', data.apache ? [
      kvRow(tr('page.taskmgr.apacheBusy', '忙しいワーカー'), a.BusyWorkers ?? '—'),
      kvRow(tr('page.taskmgr.apacheIdle', '待っているワーカー'), a.IdleWorkers ?? '—'),
      kvRow(tr('page.taskmgr.apacheTotal', '起動からの要求の数'), a['Total Accesses'] ?? '—'),
      kvRow(tr('page.taskmgr.apacheUptime', '起動からの時間'), a.Uptime ? duration(Number(a.Uptime)) : '—'),
    ] : [messageRow(tr('page.taskmgr.noApache', '取れません(mod_status)'), 2)]);
  };

  // ---- まとめて描く ----

  const render = () => {
    if (!lastData) {
      return;
    }
    const data = lastData;
    renderProcesses(data);
    renderPerformance(data);
    renderContainers(data);
    renderDetails(data);
    markSort();

    const missing = [];
    if (!data.glances || data.glances.mem === null) {
      missing.push('Glances');
    }
    if (!data.apache) {
      missing.push('Apache');
    }
    if (!data.host) {
      missing.push(tr('page.taskmgr.hostStats', 'ホストの集計'));
    }
    const time = new Date(data.fetchedAt * 1000).toLocaleTimeString();
    setText('status', tr('page.taskmgr.updated', '{time} に更新', { time })
      + (missing.length ? ` —— ${tr('page.taskmgr.missing', '取れなかったもの: {list}', { list: missing.join('・') })}` : ''));
  };

  const load = async () => {
    try {
      const response = await fetch(root.dataset.src, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      lastData = await response.json();
      sample(lastData);
      render();
    } catch (error) {
      setText('status', tr('page.taskmgr.failed', '読み込めませんでした({msg})。サインインが切れたか、許可していない場所から開いています。', { msg: error.message }));
    }
  };

  // ---- タブ ----

  const tabs = [...root.querySelectorAll('[data-km-tm-tab]')];
  const showServicesChecked = () => {
    const at = globalThis.KmServices?.lastCheckedAt;
    setText('svcChecked', at ? tr('page.taskmgr.checkedAt', '{time} に確認', { time: at.toLocaleTimeString() }) : '');
  };
  const activate = (id, { focus = false } = {}) => {
    if (!tabs.some((tab) => tab.dataset.kmTmTab === id)) {
      return;
    }
    activeTab = id;
    tabs.forEach((tab) => {
      const on = tab.dataset.kmTmTab === id;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) {
        tab.focus();
      }
    });
    root.querySelectorAll('[data-km-tm-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.kmTmPanel !== id;
    });
    // 開き直しても同じタブにする(履歴は増やさない)
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    window.history.replaceState(null, '', url);
    // サービスの確かめは、このタブを開いている間だけ回す(サーバーから各口へ TCP で繋ぐので)
    const services = globalThis.KmServices;
    if (services) {
      if (id === 'services') {
        services.refresh();
        services.startAuto(SERVICES_INTERVAL_MS);
      } else {
        services.stopAuto();
      }
    }
  };
  tabs.forEach((tab, index) => {
    tab.tabIndex = tab.dataset.kmTmTab === activeTab ? 0 : -1;
    tab.addEventListener('click', () => activate(tab.dataset.kmTmTab));
    // 矢印キーで隣のタブへ(縦でも横でも)
    tab.addEventListener('keydown', (event) => {
      const delta = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[event.key];
      if (delta) {
        event.preventDefault();
        activate(tabs[(index + delta + tabs.length) % tabs.length].dataset.kmTmTab, { focus: true });
      }
    });
  });

  root.querySelectorAll('[data-km-tm-metric]').forEach((button) => {
    button.addEventListener('click', () => {
      selectedMetric = button.dataset.kmTmMetric;
      render();
    });
  });

  root.querySelectorAll('[data-km-tm-sort]').forEach((button) => {
    button.addEventListener('click', () => {
      const [table, key] = button.dataset.kmTmSort.split(':');
      const state = sortState[table];
      if (state.key === key) {
        state.dir = -state.dir;
      } else {
        state.key = key;
        // 名前などの文字は昇順、数は大きい順から
        state.dir = ['name', 'user', 'status'].includes(key) ? 1 : -1;
      }
      render();
      markSort();
    });
  });

  $('svcCheck')?.addEventListener('click', () => globalThis.KmServices?.refresh());

  // ---- 読み直しの間隔 ----

  // 間隔(秒)。1〜10 の整数だけを受け付け、それ以外は既定に戻す。
  // localStorage は設定や閲覧モードによって例外を投げる。覚えられなくても画面は動かす
  const clampSeconds = (value) => {
    const n = Number.parseInt(value, 10);
    return Number.isInteger(n) && n >= 1 && n <= 10 ? n : INTERVAL_DEFAULT;
  };
  const loadSeconds = () => {
    try {
      return clampSeconds(localStorage.getItem(INTERVAL_KEY));
    } catch {
      return INTERVAL_DEFAULT;
    }
  };
  const saveSeconds = (value) => {
    try {
      localStorage.setItem(INTERVAL_KEY, String(value));
    } catch {
      // 覚えられないだけ。次に開いたときは既定の 5 秒に戻る
    }
  };
  let seconds = loadSeconds();
  let running = false;
  // 止めて動かし直したとき、読み込み中だった古い回が次を予約しないよう、回ごとに番号を持たせる
  let generation = 0;

  // 前回が終わってから次を予約する(setInterval だと遅い応答のときに要求が重なる)
  const tick = async (mine) => {
    timer = null;
    await load();
    if (running && mine === generation && !document.hidden) {
      timer = window.setTimeout(() => tick(mine), seconds * 1000);
    }
  };
  const start = () => {
    if (!running) {
      running = true;
      generation += 1;
      tick(generation);
    }
  };
  const stop = () => {
    running = false;
    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }
  };

  const select = document.getElementById('km-taskmgr-interval');
  if (select) {
    select.value = String(seconds);
    select.addEventListener('change', () => {
      seconds = clampSeconds(select.value);
      select.value = String(seconds);
      saveSeconds(seconds);
      // 新しい間隔で数え直す(今すぐ 1 回読む)。グラフの幅(秒)も変わるので履歴は空にする
      Object.values(history).forEach((list) => list.splice(0));
      stop();
      start();
    });
  }

  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));

  // 表の枠の高さが変わったら(窓の大きさ・タブを開いた・狭い画面との行き来)行数を数え直す
  const processWrap = $('processes')?.closest('.km-tm-fill');
  if (processWrap && 'ResizeObserver' in window) {
    new ResizeObserver(() => fitProcesses()).observe(processWrap);
  }
  wideLayout.addEventListener('change', fitProcesses);

  // services.js と i18n.js はこのファイルの後で読まれる。揃ってから動かす
  const boot = () => {
    document.addEventListener(globalThis.KmI18n?.event ?? 'changed.km.lang', render);
    document.addEventListener(globalThis.KmServices?.event ?? 'refreshed.km.services', showServicesChecked);
    activate(activeTab);
    start();
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
