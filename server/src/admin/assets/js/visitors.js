/*
 * 訪問者と大まかな位置(2026-10-06。admin/visitors.php)。
 *
 * admin/api/visit-log.php が渡す**生の行**(1 行 1 つの JSON。nginx の log_format km_visit)を、
 * ここ(ブラウザ)で数え・まとめ・表にする。サーバーは変換しない(利用者の指示「変換はクライアント側で」)。
 *
 *   t  時刻(ISO 8601)  ip 送り元  m メソッド  p パス(クエリなし)  s 状態  b 大きさ
 *   v  閲覧者の種別(anon / guest:<数> / user:<印> / app:<印>)  ua 端末  ref 参照元(クエリなし)
 *
 * ## 位置
 * スイッチ「位置を調べる」を入れたときだけ、IP ごとに 1 回、外部へ問い合わせる(訪問者の IP が外部へ渡るため既定は切)。
 *   1. ipwho.is   … 国・地域・都市(鍵なし・CORS あり)
 *   2. api.country.is … 1 が断る・落ちているときの落とし先。国だけ
 * 結果はこのブラウザの IndexedDB に 30 日覚える(記録そのものも 30 日で消えるので、それより長く持たない)。
 * 問い合わせは 1.2 秒に 1 回・1 回の表示で 150 件まで(外部の無料枠を食い潰さない)。LAN の IP は問い合わせない。
 *
 * 文字はすべて textContent で入れる(端末名・パスを HTML として読まない)。
 *
 * ## 色分け(2026-10-08、利用者の指示)
 * 危険は赤・怪しいは黄。決まり(危険リスト)は assets/js/visitors-threats.js(このページの下にも一覧で出す)。
 * 外国からの接続は行の色ではなく場所の欄の札にして、チェックボックスで表示・非表示を切り替える。
 */
(() => {
  const root = document.getElementById('km-visitors');
  if (!root) {
    return;
  }

  const GEO_TTL_MS = 30 * 24 * 3600 * 1000;
  const GEO_GAP_MS = 1200;
  const GEO_MAX_PER_LOAD = 150;
  const RECENT_ROWS = 200;
  const PREF_GEO = 'kmadmin-visitors-geo';
  const PREF_DAYS = 'kmadmin-visitors-days';
  const PREF_FOREIGN = 'kmadmin-visitors-show-foreign';
  const THREAT_ROWS = 100;
  const threats = window.KmVisitorThreats || null;
  const LEVEL_CLASS = { danger: 'table-danger', suspect: 'table-warning' };
  const LEVEL_BADGE = { danger: 'text-bg-danger', suspect: 'text-bg-warning' };
  const LEVEL_LABEL = { danger: '危険', suspect: '怪しい' };
  /** 札(バッジ)。文字は textContent */
  const badge = (text, cls) => {
    const span = document.createElement('span');
    span.className = `badge ${cls} me-1`;
    span.textContent = text;
    return span;
  };
  /** 文字と札の入ったセル */
  const cell = (text, badges = []) => {
    const td = document.createElement('td');
    badges.forEach((b) => td.appendChild(b));
    td.appendChild(document.createTextNode(text));
    return td;
  };

  const $ = (key) => root.querySelector(`[data-km-vs="${key}"]`);
  const setText = (key, text) => {
    const el = $(key);
    if (el) {
      el.textContent = text;
    }
  };
  const row = (cells, numericFrom = 1) => {
    const tr = document.createElement('tr');
    cells.forEach((text, i) => {
      const td = document.createElement('td');
      td.textContent = text;
      if (numericFrom !== null && i >= numericFrom) {
        td.className = 'text-end';
      }
      tr.appendChild(td);
    });
    return tr;
  };
  const fill = (key, rows, emptyText) => {
    const body = $(key);
    if (!body) {
      return;
    }
    if (rows.length === 0) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 99;
      td.className = 'text-center text-body-secondary';
      td.textContent = emptyText;
      tr.appendChild(td);
      body.replaceChildren(tr);
      return;
    }
    body.replaceChildren(...rows);
  };

  // 設定(このブラウザに覚える)。localStorage は閲覧モードなどで例外を投げるので、覚えられなくても動かす
  const pref = {
    get(key, fallback) {
      try {
        const v = localStorage.getItem(key);
        return v === null ? fallback : v;
      } catch {
        return fallback;
      }
    },
    set(key, value) {
      try {
        localStorage.setItem(key, value);
      } catch {
        // 覚えられないだけ
      }
    },
  };

  // ---- 国名(ブラウザの辞書。無ければコードのまま)----
  let regionNames = null;
  try {
    regionNames = new Intl.DisplayNames([document.documentElement.lang || 'ja'], { type: 'region' });
  } catch {
    regionNames = null;
  }
  const countryName = (code) => {
    if (!code) {
      return '不明';
    }
    try {
      return regionNames ? `${regionNames.of(code)}(${code})` : code;
    } catch {
      return code;
    }
  };

  // ---- LAN・予約済みの IP(外部へ送らない)----
  const isPrivate = (ip) => {
    if (!ip) {
      return true;
    }
    if (ip.includes(':')) {
      const v6 = ip.toLowerCase();
      return v6 === '::1' || v6.startsWith('fc') || v6.startsWith('fd') || v6.startsWith('fe80') || v6.startsWith('::ffff:127.');
    }
    const p = ip.split('.').map(Number);
    if (p.length !== 4 || p.some((n) => !Number.isInteger(n))) {
      return true;
    }
    return p[0] === 10 || p[0] === 127 || p[0] === 0
      || (p[0] === 172 && p[1] >= 16 && p[1] <= 31)
      || (p[0] === 192 && p[1] === 168)
      || (p[0] === 169 && p[1] === 254)
      || (p[0] === 100 && p[1] >= 64 && p[1] <= 127);
  };

  // ---- 位置の覚え(IndexedDB)。開けなければ覚えずに動く ----
  const geo = new Map();   // ip => { code, country, region, city, at, src }
  let db = null;
  const openDb = () => new Promise((resolve) => {
    try {
      const req = indexedDB.open('km-visitors', 1);
      req.onupgradeneeded = () => req.result.createObjectStore('geo', { keyPath: 'ip' });
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
  const loadGeoCache = async () => {
    db = await openDb();
    if (!db) {
      return;
    }
    await new Promise((resolve) => {
      try {
        const store = db.transaction('geo', 'readwrite').objectStore('geo');
        const req = store.openCursor();
        req.onsuccess = () => {
          const cursor = req.result;
          if (!cursor) {
            resolve();
            return;
          }
          // 30 日を過ぎたものは消す(訪問の記録より長く IP を持たない)
          if (Date.now() - (cursor.value.at || 0) > GEO_TTL_MS) {
            cursor.delete();
          } else {
            geo.set(cursor.value.ip, cursor.value);
          }
          cursor.continue();
        };
        req.onerror = () => resolve();
      } catch {
        resolve();
      }
    });
  };
  const saveGeo = (entry) => {
    geo.set(entry.ip, entry);
    if (!db) {
      return;
    }
    try {
      db.transaction('geo', 'readwrite').objectStore('geo').put(entry);
    } catch {
      // 覚えられないだけ
    }
  };

  // ---- 外部への問い合わせ ----
  let ipwhoDown = 0;   // 続けて失敗した回数。3 回で、この表示の間は国だけにする
  const fetchJson = async (url) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    try {
      const res = await fetch(url, { signal: controller.signal, credentials: 'omit', referrerPolicy: 'no-referrer', cache: 'no-store' });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      return await res.json();
    } finally {
      clearTimeout(timer);
    }
  };
  const lookup = async (ip) => {
    if (ipwhoDown < 3) {
      try {
        const d = await fetchJson(`https://ipwho.is/${encodeURIComponent(ip)}?fields=success,country_code,country,region,city`);
        if (d && d.success === true && typeof d.country_code === 'string') {
          ipwhoDown = 0;
          return { ip, code: d.country_code, region: String(d.region || ''), city: String(d.city || ''), at: Date.now(), src: 'ipwho.is' };
        }
        ipwhoDown += 1;
      } catch {
        ipwhoDown += 1;
      }
    }
    try {
      const d = await fetchJson(`https://api.country.is/${encodeURIComponent(ip)}`);
      if (d && typeof d.country === 'string') {
        return { ip, code: d.country, region: '', city: '', at: Date.now(), src: 'country.is' };
      }
    } catch {
      // どちらも引けない。今回は諦める(覚えない —— 次に開いたときにもう一度試す)
    }
    return null;
  };

  // ---- 読み込みと集計 ----
  let visits = [];
  /** 判定(visits と同じ順の rows と、送り元ごとの ips)。読み込んだときに 1 度だけ作る */
  let judged = { rows: [], ips: new Map() };
  let geoQueueToken = 0;
  const geoSwitch = document.getElementById('km-visitors-geo');
  const foreignCheck = document.getElementById('km-visitors-show-foreign');
  const daysSelect = document.getElementById('km-visitors-days');

  const place = (ip) => {
    if (isPrivate(ip)) {
      return { label: 'LAN', code: 'LAN', foreign: false };
    }
    const g = geo.get(ip);
    if (!g) {
      return { label: '…', code: null, foreign: null };
    }
    const parts = [countryName(g.code), g.region, g.city].filter((x) => x);
    return { label: parts.join(' / '), code: g.code, foreign: g.code !== 'JP' };
  };
  const viewerLabel = (v) => {
    if (!v || v === 'anon') {
      return '一般';
    }
    const [kind, id] = v.split(':');
    const names = { guest: 'お試し', user: 'サインイン', app: 'アプリ' };
    return `${names[kind] || kind}${id ? ` #${id}` : ''}`;
  };
  const shortUa = (ua) => {
    if (!ua) {
      return '';
    }
    // 順に見る(1 本の正規表現だと、Android の UA にある先の「Linux」に当たってしまう)
    const os = [/Android [\d.]+/, /iPhone OS [\d_]+/, /iPad/, /Windows NT [\d.]+/, /Mac OS X [\d_]+/, /CrOS/, /Linux/]
      .map((re) => re.exec(ua)?.[0]).find((x) => x) || '';
    const app = /KosenMap[^\s]*|okhttp\/[\d.]+|Edg\/[\d.]+|Chrome\/\d+|Firefox\/\d+|Version\/[\d.]+ Mobile\/\S+ Safari|Safari\/\d+|bot|crawler|spider|curl\/[\d.]+/i.exec(ua)?.[0] || '';
    const text = [os.replace(/_/g, '.'), app].filter((x) => x).join(' · ');
    return text || ua.slice(0, 60);
  };

  let renderTimer = null;
  const scheduleRender = () => {
    if (renderTimer === null) {
      renderTimer = setTimeout(() => {
        renderTimer = null;
        render();
      }, 400);
    }
  };

  const render = () => {
    // 外国からの接続を出すか(チェックボックス。既定は出す)。位置がまだ分からないものは出す
    const showForeign = foreignCheck ? foreignCheck.checked : true;
    const list = showForeign ? visits : visits.filter((v) => place(v.ip).foreign !== true);
    const judgeOf = (v) => v._judge || { level: null, reasons: [] };

    const ips = new Set(list.map((v) => v.ip));
    const foreignVisits = visits.filter((v) => place(v.ip).foreign === true);
    const known = visits.filter((v) => place(v.ip).foreign !== null).length;
    const signed = list.filter((v) => v.v && v.v !== 'anon').length;
    const dangerIps = new Set(list.filter((v) => judgeOf(v).level === 'danger').map((v) => v.ip));
    const suspectIps = new Set(list.filter((v) => judgeOf(v).level === 'suspect').map((v) => v.ip));

    setText('visits', String(list.length));
    setText('visitsSub', showForeign ? '' : `外国からの ${foreignVisits.length} 件を隠しています`);
    setText('ips', String(ips.size));
    setText('foreign', String(foreignVisits.length));
    setText('foreignSub', `位置が分かった ${known} / ${visits.length} 件のうち`);
    setText('signed', String(signed));
    setText('danger', String(dangerIps.size));
    setText('dangerSub', `送り元・訪問 ${list.filter((v) => judgeOf(v).level === 'danger').length} 件`);
    setText('suspect', String(suspectIps.size));
    setText('suspectSub', `送り元・訪問 ${list.filter((v) => judgeOf(v).level === 'suspect').length} 件`);

    // 日別
    const byDay = new Map();
    list.forEach((v) => {
      const day = (v.t || '').slice(0, 10);
      const d = byDay.get(day) || { n: 0, ips: new Set(), foreign: 0, danger: 0 };
      d.n += 1;
      d.ips.add(v.ip);
      if (place(v.ip).foreign === true) {
        d.foreign += 1;
      }
      if (judgeOf(v).level === 'danger') {
        d.danger += 1;
      }
      byDay.set(day, d);
    });
    fill('byDay', [...byDay.entries()].sort((a, b) => b[0].localeCompare(a[0]))
      .map(([day, d]) => {
        const tr = row([day, String(d.n), String(d.ips.size), String(d.foreign), String(d.danger)]);
        if (d.danger > 0) {
          tr.className = 'table-danger';
        }
        return tr;
      }), '記録がありません');

    // 国・地域別
    const byCountry = new Map();
    list.forEach((v) => {
      const p = place(v.ip);
      const key = p.code === null ? '…' : p.code;
      const d = byCountry.get(key) || { n: 0, ips: new Set() };
      d.n += 1;
      d.ips.add(v.ip);
      byCountry.set(key, d);
    });
    fill('byCountry', [...byCountry.entries()].sort((a, b) => b[1].n - a[1].n)
      .map(([code, d]) => row([code === '…' ? '(まだ調べていない)' : code === 'LAN' ? 'LAN' : countryName(code), String(d.n), String(d.ips.size)])), '記録がありません');

    // ページ別
    const byPage = new Map();
    list.forEach((v) => {
      const d = byPage.get(v.p) || { n: 0, ips: new Set() };
      d.n += 1;
      d.ips.add(v.ip);
      byPage.set(v.p, d);
    });
    fill('byPage', [...byPage.entries()].sort((a, b) => b[1].n - a[1].n)
      .map(([page, d]) => row([page, String(d.n), String(d.ips.size)])), '記録がありません');

    // 場所の欄(外国なら札を付ける)
    const placeCell = (ip) => {
      const p = place(ip);
      return cell(p.label, p.foreign === true ? [badge('外国', 'text-bg-secondary')] : []);
    };
    const levelCell = (judge) => cell('', judge.level ? [badge(LEVEL_LABEL[judge.level], LEVEL_BADGE[judge.level])] : []);

    // 危険・怪しい送り元(重い順・多い順)
    const threatIps = [...ips].map((ip) => [ip, judged.ips.get(ip)]).filter(([, d]) => d && d.level)
      .sort((a, b) => (a[1].level === b[1].level ? b[1].count - a[1].count : a[1].level === 'danger' ? -1 : 1))
      .slice(0, THREAT_ROWS);
    fill('threats', threatIps.map(([ip, d]) => {
      const tr = document.createElement('tr');
      tr.className = LEVEL_CLASS[d.level] || '';
      tr.append(
        levelCell(d),
        cell(ip),
        placeCell(ip),
        cell(String(d.count)),
        cell(d.reasons.join('・')),
        cell(d.last ? new Date(d.last).toLocaleString() : ''),
      );
      tr.cells[3].className = 'text-end';
      return tr;
    }), '危険・怪しい送り元はありません');

    // 最近の訪問(新しい順)。危険は赤・怪しいは黄。理由は行に重ねると出る
    const recent = list.slice(-RECENT_ROWS).reverse();
    fill('recent', recent.map((v) => {
      const judge = judgeOf(v);
      const tr = document.createElement('tr');
      tr.append(
        cell(new Date(v.t).toLocaleString()),
        levelCell(judge),
        cell(v.ip),
        placeCell(v.ip),
        cell(v.p),
        cell(`${v.m && v.m !== 'GET' ? `${v.m} ` : ''}${v.s}`),
        cell(viewerLabel(v.v)),
        cell(shortUa(v.ua)),
      );
      tr.className = LEVEL_CLASS[judge.level] || '';
      tr.title = [judge.reasons.join('・'), v.ua || ''].filter((x) => x).join('\n');
      return tr;
    }), '記録がありません');
  };

  // 危険リスト(判定の決まり)を表にする。読み込みのたびに変わらないので 1 度だけ
  const renderRules = () => {
    if (!threats) {
      fill('rules', [], '判定の決まり(visitors-threats.js)を読めませんでした');
      return;
    }
    const rules = [...threats.RULES, ...threats.IP_RULES];
    fill('rules', rules.map((r) => {
      const tr = document.createElement('tr');
      tr.append(cell('', [badge(LEVEL_LABEL[r.level], LEVEL_BADGE[r.level])]), cell(r.name), cell(r.what));
      return tr;
    }), '');
  };
  // まだ位置の分からない IP を、新しい訪問から順に引く。表示を読み直したら前の順番待ちは捨てる
  const runGeoQueue = async () => {
    const token = ++geoQueueToken;
    if (geoSwitch?.checked !== true) {
      return;
    }
    const seen = new Set();
    const queue = [];
    for (let i = visits.length - 1; i >= 0; i -= 1) {
      const ip = visits[i].ip;
      if (!seen.has(ip) && !geo.has(ip) && !isPrivate(ip)) {
        queue.push(ip);
      }
      seen.add(ip);
    }
    let done = 0;
    for (const ip of queue.slice(0, GEO_MAX_PER_LOAD)) {
      if (token !== geoQueueToken || geoSwitch?.checked !== true) {
        return;
      }
      const entry = await lookup(ip);
      if (entry) {
        saveGeo(entry);
        scheduleRender();
      }
      done += 1;
      setText('status', `位置を調べています… ${done} / ${Math.min(queue.length, GEO_MAX_PER_LOAD)}`);
      await new Promise((resolve) => setTimeout(resolve, GEO_GAP_MS));
    }
    if (token === geoQueueToken) {
      const rest = queue.length - Math.min(queue.length, GEO_MAX_PER_LOAD);
      setText('status', rest > 0 ? `位置を ${GEO_MAX_PER_LOAD} 件調べました(残り ${rest} 件は「読み直す」で続き)` : '位置を調べ終えました');
    }
  };

  const load = async () => {
    geoQueueToken += 1;
    const days = Number(daysSelect?.value || 7);
    setText('status', '読み込み中…');
    try {
      const res = await fetch(`${root.dataset.src}?days=${days}`, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const text = await res.text();
      const parsed = [];
      let broken = 0;
      text.split('\n').forEach((line) => {
        if (!line.trim()) {
          return;
        }
        try {
          const v = JSON.parse(line);
          if (v && typeof v.ip === 'string' && typeof v.p === 'string') {
            parsed.push(v);
          } else {
            broken += 1;
          }
        } catch {
          broken += 1;
        }
      });
      visits = parsed;
      // 色分けの判定(危険リスト)。読み込んだときに 1 度だけ
      judged = threats ? threats.classify(visits) : { rows: [], ips: new Map() };
      visits.forEach((v, i) => {
        v._judge = judged.rows[i];
      });
      const notes = [];
      if (res.headers.get('X-KM-Visit-Log') === 'missing') {
        notes.push('記録の置き場がありません(reverse-proxy と web の作り直しが要ります)');
      }
      if (res.headers.get('X-KM-Truncated') === '1') {
        notes.push('多すぎるので古い方を切りました');
      }
      if (broken > 0) {
        notes.push(`読めない行 ${broken}`);
      }
      setText('status', `${new Date().toLocaleTimeString()} に読み込み(${visits.length} 件)${notes.length ? ` —— ${notes.join('・')}` : ''}`);
      render();
      runGeoQueue();
    } catch (error) {
      setText('status', `読み込めませんでした(${error.message})。サインインが切れた可能性があります。`);
    }
  };

  // 設定を戻す
  if (daysSelect) {
    const saved = pref.get(PREF_DAYS, '7');
    if ([...daysSelect.options].some((o) => o.value === saved)) {
      daysSelect.value = saved;
    }
    daysSelect.addEventListener('change', () => {
      pref.set(PREF_DAYS, daysSelect.value);
      load();
    });
  }
  if (geoSwitch) {
    geoSwitch.checked = pref.get(PREF_GEO, '0') === '1';
    geoSwitch.addEventListener('change', () => {
      pref.set(PREF_GEO, geoSwitch.checked ? '1' : '0');
      if (geoSwitch.checked) {
        runGeoQueue();
      } else {
        geoQueueToken += 1;
      }
    });
  }
  if (foreignCheck) {
    foreignCheck.checked = pref.get(PREF_FOREIGN, '1') === '1';
    foreignCheck.addEventListener('change', () => {
      pref.set(PREF_FOREIGN, foreignCheck.checked ? '1' : '0');
      render();
    });
  }
  renderRules();
  document.getElementById('km-visitors-reload')?.addEventListener('click', load);

  loadGeoCache().then(load);
})();
