/*
 * タスクマネージャー(2026-10-05、利用者の指示)。
 *
 * admin/api/taskmgr-data.php が渡す**生の値**を、ここ(ブラウザ)で割合・単位・使用率・グラフに直す。
 * サーバーは変換しない(メモリと CPU を使わせない。利用者の指示「変換はクライアント側で」)。
 *
 *   glances … Glances の API の値(ホスト全体)
 *   apache  … /server-status?auto のテキスト
 *   host    … scripts/host-stats.sh の JSON(1 分ごと。コンテナ別の CPU は前回との差から出す)
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
  const history = { cpu: [], mem: [], swap: [] };
  let previousHost = null;
  let containerCpu = {};
  let timer = null;

  const $ = (key) => root.querySelector(`[data-km-tm="${key}"]`);
  const num = (v) => (typeof v === 'number' && Number.isFinite(v) ? v : null);
  const pct = (v) => (v === null ? '—' : `${v.toFixed(1)}%`);
  const bytes = (v) => {
    if (v === null || v === undefined) {
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
  const duration = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    return d > 0 ? `${d}日 ${h}時間` : `${h}時間 ${m}分`;
  };
  const setText = (key, text) => {
    const el = $(key);
    if (el) {
      el.textContent = text;
    }
  };
  const row = (cells, alignEndFrom = 1) => {
    const tr = document.createElement('tr');
    cells.forEach((text, i) => {
      const td = document.createElement('td');
      td.textContent = text;
      if (i >= alignEndFrom) {
        td.className = 'text-end';
      }
      tr.appendChild(td);
    });
    return tr;
  };
  const fill = (key, rows) => {
    const body = $(key);
    if (!body) {
      return;
    }
    body.replaceChildren(...rows);
  };

  const pushHistory = (name, value) => {
    history[name].push(value);
    if (history[name].length > HISTORY) {
      history[name].shift();
    }
  };

  const drawChart = () => {
    const svg = $('chart');
    if (!svg) {
      return;
    }
    const ns = 'http://www.w3.org/2000/svg';
    const width = 600;
    const height = 160;
    const lines = [
      ['cpu', 'var(--bs-primary)'],
      ['mem', 'var(--bs-success)'],
      ['swap', 'var(--bs-warning)'],
    ];
    const children = [];
    [25, 50, 75].forEach((p) => {
      const grid = document.createElementNS(ns, 'line');
      const y = height - (p / 100) * height;
      grid.setAttribute('x1', '0');
      grid.setAttribute('x2', String(width));
      grid.setAttribute('y1', String(y));
      grid.setAttribute('y2', String(y));
      grid.style.stroke = 'var(--bs-border-color)';
      grid.style.strokeWidth = '1';
      children.push(grid);
    });
    lines.forEach(([name, color]) => {
      const values = history[name];
      if (values.length < 2) {
        return;
      }
      const step = width / (HISTORY - 1);
      const offset = (HISTORY - values.length) * step;
      const points = values
        .map((v, i) => `${(offset + i * step).toFixed(1)},${(height - (Math.min(100, Math.max(0, v ?? 0)) / 100) * height).toFixed(1)}`)
        .join(' ');
      const line = document.createElementNS(ns, 'polyline');
      line.setAttribute('points', points);
      line.style.fill = 'none';
      line.style.stroke = color;
      line.style.strokeWidth = '2';
      children.push(line);
    });
    svg.replaceChildren(...children);
  };

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

  const render = (data) => {
    const g = data.glances || {};
    const host = data.host || null;

    // CPU・メモリ・スワップ(Glances)
    const cpu = num(g.cpu?.total);
    const mem = num(g.mem?.percent);
    const swap = num(g.memswap?.percent);
    setText('cpu', pct(cpu));
    setText('cpuSub', g.load ? `負荷 ${g.load.min1?.toFixed?.(2) ?? '—'} / ${g.load.min5?.toFixed?.(2) ?? '—'} / ${g.load.min15?.toFixed?.(2) ?? '—'}(コア ${g.load.cpucore ?? '—'})` : '');
    setText('mem', pct(mem));
    setText('memSub', g.mem ? `${bytes(g.mem.used)} / ${bytes(g.mem.total)}(空き ${bytes(g.mem.available)})` : '');
    setText('swap', pct(swap));
    setText('swapSub', g.memswap ? `${bytes(g.memswap.used)} / ${bytes(g.memswap.total)}` : '');
    pushHistory('cpu', cpu);
    pushHistory('mem', mem);
    pushHistory('swap', swap);
    drawChart();

    // ディスク・ホスト(host-stats.sh)
    if (host && host.host) {
      const h = host.host;
      const used = h.diskTotal > 0 ? (h.diskUsed / h.diskTotal) * 100 : null;
      setText('disk', pct(used));
      setText('diskSub', `${bytes(h.diskUsed)} / ${bytes(h.diskTotal)}`);
      const rows = [
        row(['稼働時間', duration(h.uptime)]),
        row(['通信(受信の合計)', bytes(h.netRx)]),
        row(['通信(送信の合計)', bytes(h.netTx)]),
        row(['集計の時刻', new Date(host.generatedAt * 1000).toLocaleString()]),
      ];
      if (g.system) {
        rows.unshift(row(['OS', `${g.system.linux_distro || g.system.os_name || ''} ${g.system.os_version || ''}`.trim()]));
      }
      fill('hostTable', rows);

      // コンテナ別の CPU は、集計(1 分ごと)が新しくなったときだけ前回との差から出す
      if (previousHost && previousHost.generatedAt !== host.generatedAt) {
        const seconds = host.generatedAt - previousHost.generatedAt;
        const before = Object.fromEntries((previousHost.containers || []).map((c) => [c.name, c.cpuUsageUsec]));
        containerCpu = {};
        (host.containers || []).forEach((c) => {
          if (before[c.name] !== undefined && seconds > 0) {
            containerCpu[c.name] = ((c.cpuUsageUsec - before[c.name]) / 1e6 / seconds) * 100;
          }
        });
      }
      if (!previousHost || previousHost.generatedAt !== host.generatedAt) {
        previousHost = host;
      }
      const containers = [...(host.containers || [])].sort((a, b) => b.memCurrent - a.memCurrent);
      fill('containers', containers.map((c) => {
        const tr = row([
          c.name,
          containerCpu[c.name] === undefined ? '…' : pct(containerCpu[c.name]),
          c.memMax > 0 ? `${bytes(c.memCurrent)} / ${bytes(c.memMax)}` : bytes(c.memCurrent),
          bytes(c.swapCurrent),
          String(c.oomKill ?? 0),
          bytes(c.logBytes),
        ]);
        if ((c.oomKill ?? 0) > 0 || (c.memMax > 0 && c.memCurrent / c.memMax > 0.9)) {
          tr.className = 'table-warning';
        }
        return tr;
      }));
    } else {
      setText('disk', '—');
      setText('diskSub', 'ホストの集計がありません(host-stats.sh の cron)');
      fill('containers', [row(['ホストの集計がありません(host-stats.sh の cron を確かめてください)'], 99)]);
    }

    // プロセス(Glances の上位)
    const processes = Array.isArray(g.processlist) ? g.processlist : [];
    fill('processes', processes.map((p) => row([
      String(p.pid ?? ''),
      String(p.name ?? ''),
      pct(num(p.cpu_percent)),
      bytes(p.memory_info?.rss ?? null),
    ], 2)));

    // Apache
    const a = parseApache(data.apache);
    fill('apache', data.apache ? [
      row(['忙しいワーカー', a.BusyWorkers ?? '—']),
      row(['待っているワーカー', a.IdleWorkers ?? '—']),
      row(['起動からの要求の数', a['Total Accesses'] ?? '—']),
      row(['起動からの時間', a.Uptime ? duration(Number(a.Uptime)) : '—']),
    ] : [row(['取れません(mod_status)'], 99)]);

    const missing = [];
    if (!data.glances || data.glances.mem === null) {
      missing.push('Glances');
    }
    if (!data.apache) {
      missing.push('Apache');
    }
    if (!host) {
      missing.push('ホストの集計');
    }
    setText('status', `${new Date(data.fetchedAt * 1000).toLocaleTimeString()} に更新${missing.length ? ` —— 取れなかったもの: ${missing.join('・')}` : ''}`);
  };

  const load = async () => {
    try {
      const response = await fetch(root.dataset.src, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      render(await response.json());
    } catch (error) {
      setText('status', `読み込めませんでした(${error.message})。サインインが切れたか、許可していない場所から開いています。`);
    }
  };

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
  const saveSeconds = (seconds) => {
    try {
      localStorage.setItem(INTERVAL_KEY, String(seconds));
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
      // 新しい間隔で数え直す(今すぐ 1 回読む)
      stop();
      start();
    });
  }

  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
  start();
})();
