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
 *   1. ipwho.is   … 国・地域・都市と、ISP・組織名・ASN(鍵なし・CORS あり。ASN は 2026-10-08 から)
 *   2. api.country.is … 1 が断る・落ちているときの落とし先。国だけ
 * 結果はこのブラウザの IndexedDB(km-visitors / geo)に 30 日覚える(記録そのものも 30 日で消えるので、それより長く持たない)。
 * 問い合わせは 1.2 秒に 1 回・1 回の表示で 150 件まで(外部の無料枠を食い潰さない)。LAN の IP は問い合わせない。
 *
 * 文字はすべて textContent で入れる(端末名・パスを HTML として読まない)。
 *
 * ## 色分け(2026-10-08、利用者の指示)
 * 危険は赤・怪しいは黄。① ISP/ASN 〜 ⑦ 過去の挙動の点数で決める(assets/js/visitors-threats.js。このページの下にも一覧で出す)。
 * 「信頼する」にした送り元(自分の回線など)は色を付けない(このブラウザの localStorage だけに覚える。リポジトリには書かない)。
 * 危険だった送り元は IndexedDB(km-visitors / flags)に 30 日覚え、⑦ に使う。
 * 外国からの接続は行の色ではなく場所の欄の札にして、チェックボックスで表示・非表示を切り替える。
 *
 * ## 書き出し(2026-10-08、利用者の指示「Excel などにエクスポート」)
 * いま表示している訪問(外国を隠していれば隠したまま)と、送り元ごとのまとめを CSV にする(UTF-8 の BOM 付き。Excel でそのまま開ける)。
 * = + - @ で始まる値は、Excel が式として読まないよう頭に ' を付ける(CSV インジェクション)。
 *
 * ## 世界地図と BAN(2026-10-08、利用者の指示)
 * 本書の Sales Value のカードを写した世界地図(描くのは assets/js/visitors-world.js)へ、国ごとの数・日ごとの数・BAN を渡す。
 * BAN は admin/api/visitor-bans.php(ホストの cron が書く bans.json)。BAN している IP が訪問の記録に何回出たかをここで数える。
 * 「位置を調べる」が入っていれば、BAN している IP の国も、訪問の IP のあとに引く。
 */
(() => {
  const root = document.getElementById('km-visitors');
  if (!root) {
    return;
  }

  const GEO_TTL_MS = 30 * 24 * 3600 * 1000;
  const GEO_GAP_MS = 1200;
  const GEO_MAX_PER_LOAD = 150;
  const PREF_GEO = 'kmadmin-visitors-geo';
  const PREF_DAYS = 'kmadmin-visitors-days';
  const PREF_FOREIGN = 'kmadmin-visitors-show-foreign';
  const PREF_ROWS = 'kmadmin-visitors-rows';
  const PREF_TRUSTED = 'kmadmin-visitors-trusted';
  const ROW_CHOICES = ['10', '50', '100', '200'];
  const THREAT_ROWS = 100;
  const threats = window.KmVisitorThreats || null;
  const LEVEL_CLASS = { danger: 'table-danger', suspect: 'table-warning' };
  const LEVEL_BADGE = { danger: 'text-bg-danger', suspect: 'text-bg-warning', trusted: 'text-bg-info' };
  const LEVEL_LABEL = { danger: '危険', suspect: '怪しい', trusted: '信頼' };

  /** 札(バッジ)。文字は textContent */
  const badge = (text, cls) => {
    const span = document.createElement('span');
    span.className = `badge ${cls} me-1`;
    span.textContent = text;
    return span;
  };
  /** 文字と札の入ったセル */
  const cell = (text, badges = [], className = '') => {
    const td = document.createElement('td');
    badges.forEach((b) => td.appendChild(b));
    td.appendChild(document.createTextNode(text));
    if (className) {
      td.className = className;
    }
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
    cells.forEach((text, i) => tr.appendChild(cell(text, [], numericFrom !== null && i >= numericFrom ? 'text-end' : '')));
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

  // ---- 信頼する送り元(このブラウザだけ)----
  const trusted = new Set((() => {
    try {
      const list = JSON.parse(pref.get(PREF_TRUSTED, '[]'));
      return Array.isArray(list) ? list.filter((x) => typeof x === 'string') : [];
    } catch {
      return [];
    }
  })());
  const saveTrusted = () => pref.set(PREF_TRUSTED, JSON.stringify([...trusted]));

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

  // ---- 位置と、危険だった送り元の覚え(IndexedDB)。開けなければ覚えずに動く ----
  const geo = new Map();       // ip => { code, region, city, asn, org, isp, at, src }
  const history = new Map();   // ip => { ip, day, score, at }
  let db = null;
  const openDb = () => new Promise((resolve) => {
    try {
      const req = indexedDB.open('km-visitors', 2);
      req.onupgradeneeded = () => {
        const names = req.result.objectStoreNames;
        if (!names.contains('geo')) {
          req.result.createObjectStore('geo', { keyPath: 'ip' });
        }
        if (!names.contains('flags')) {
          req.result.createObjectStore('flags', { keyPath: 'ip' });
        }
      };
      req.onsuccess = () => {
        // ほかのタブが版を上げるときは閉じる(そちらを止めない)
        req.result.onversionchange = () => req.result.close();
        resolve(req.result);
      };
      req.onerror = () => resolve(null);
      req.onblocked = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
  const loadStore = (name, into) => new Promise((resolve) => {
    try {
      const req = db.transaction(name, 'readwrite').objectStore(name).openCursor();
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
          into.set(cursor.value.ip, cursor.value);
        }
        cursor.continue();
      };
      req.onerror = () => resolve();
    } catch {
      resolve();
    }
  });
  const loadCaches = async () => {
    db = await openDb();
    if (db) {
      await loadStore('geo', geo);
      await loadStore('flags', history);
    }
  };
  /** IndexedDB が答えないことがある(ほかのタブが開いたままなど)。3 秒待って、覚えなしで先へ進む */
  const loadCachesOrGiveUp = () => Promise.race([loadCaches(), new Promise((resolve) => setTimeout(resolve, 3000))]);
  const put = (name, entry) => {
    if (!db) {
      return;
    }
    try {
      db.transaction(name, 'readwrite').objectStore(name).put(entry);
    } catch {
      // 覚えられないだけ
    }
  };
  const saveGeo = (entry) => {
    geo.set(entry.ip, entry);
    put('geo', entry);
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
        const d = await fetchJson(`https://ipwho.is/${encodeURIComponent(ip)}?fields=success,country_code,country,region,city,connection`);
        if (d && d.success === true && typeof d.country_code === 'string') {
          ipwhoDown = 0;
          const c = d.connection || {};
          return {
            ip, code: d.country_code, region: String(d.region || ''), city: String(d.city || ''),
            asn: Number.isInteger(c.asn) ? c.asn : null, org: String(c.org || ''), isp: String(c.isp || ''),
            at: Date.now(), src: 'ipwho.is',
          };
        }
        ipwhoDown += 1;
      } catch {
        ipwhoDown += 1;
      }
    }
    try {
      const d = await fetchJson(`https://api.country.is/${encodeURIComponent(ip)}`);
      if (d && typeof d.country === 'string') {
        return { ip, code: d.country, region: '', city: '', asn: null, org: '', isp: '', at: Date.now(), src: 'country.is' };
      }
    } catch {
      // どちらも引けない。今回は諦める(覚えない —— 次に開いたときにもう一度試す)
    }
    return null;
  };
  /** ASN を引いていない古い覚え(2026-10-08 より前の ipwho.is)は、引き直す */
  const needsLookup = (ip) => {
    const g = geo.get(ip);
    return !g || (g.src === 'ipwho.is' && !('asn' in g));
  };

  // ---- 読み込みと集計 ----
  let visits = [];
  let bans = null;        // admin/api/visitor-bans.php の bans(まだ無ければ null)
  let bansError = '';
  /** 判定(visits と同じ順の rows と、送り元ごとの ips)。表示のたびに作る(位置・ASN が分かると点が変わる) */
  let judged = { rows: [], ips: new Map() };
  let geoQueueToken = 0;
  const expandedCountries = new Set();
  const geoSwitch = document.getElementById('km-visitors-geo');
  const foreignCheck = document.getElementById('km-visitors-show-foreign');
  const daysSelect = document.getElementById('km-visitors-days');
  const rowsSelect = document.getElementById('km-visitors-rows');
  const world = window.KmVisitorWorld ? window.KmVisitorWorld.create(root, { countryName }) : null;

  /** BAN している IP と、その仕組み(fail2ban の牢・22 番の DROP)。形の合わない値は捨てる */
  const bannedList = () => {
    const out = [];
    const okIp = (ip) => typeof ip === 'string' && /^[0-9A-Fa-f:.]+$/.test(ip) && /[.:]/.test(ip);
    (bans?.fail2ban?.jails || []).forEach((j) => (j.ips || []).filter(okIp).forEach((ip) => out.push({ ip, how: `fail2ban(${j.name})`, packets: null })));
    (bans?.iptables || []).filter((r) => okIp(r?.ip)).forEach((r) => out.push({ ip: r.ip, how: '22 番の DROP', packets: Number(r.packets) || 0 }));
    return out;
  };

  const place = (ip) => {
    if (isPrivate(ip)) {
      return { label: 'LAN', code: 'LAN', foreign: false, region: '', city: '' };
    }
    const g = geo.get(ip);
    if (!g) {
      return { label: '…', code: null, foreign: null, region: '', city: '' };
    }
    const parts = [countryName(g.code), g.region, g.city].filter((x) => x);
    return { label: parts.join(' / '), code: g.code, foreign: g.code !== 'JP', region: g.region || '', city: g.city || '' };
  };
  const ispLabel = (ip) => {
    const g = geo.get(ip);
    if (!g) {
      return isPrivate(ip) ? 'LAN' : '';
    }
    return [g.asn ? `AS${g.asn}` : '', g.org || g.isp || ''].filter((x) => x).join(' ');
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

  /** 判定し直す(位置・ASN・信頼が変わったとき)。危険だった送り元を覚える */
  const classify = () => {
    if (!threats) {
      judged = { rows: visits.map(() => ({ level: null, score: 0, reasons: [] })), ips: new Map() };
      return;
    }
    judged = threats.classify(visits, { geoOf: (ip) => geo.get(ip), isPrivate, trusted, history });
    visits.forEach((v, i) => {
      v._judge = judged.rows[i];
    });
    judged.ips.forEach((d, ip) => {
      const day = (d.last || '').slice(0, 10);
      const before = history.get(ip);
      if (d.level === 'danger' && day && (!before || before.day < day)) {
        const entry = { ip, day, score: d.score, at: Date.now() };
        history.set(ip, entry);
        put('flags', entry);
      }
    });
  };

  /** いま表示している訪問(外国を隠していれば隠したもの) */
  const shownVisits = () => {
    const showForeign = foreignCheck ? foreignCheck.checked : true;
    return showForeign ? visits : visits.filter((v) => place(v.ip).foreign !== true);
  };

  const render = () => {
    classify();
    const list = shownVisits();
    const showForeign = list.length === visits.length || (foreignCheck ? foreignCheck.checked : true);
    const judgeOf = (v) => v._judge || { level: null, score: 0, reasons: [] };

    const ips = new Set(list.map((v) => v.ip));
    const foreignVisits = visits.filter((v) => place(v.ip).foreign === true);
    const known = visits.filter((v) => place(v.ip).foreign !== null).length;
    const signed = list.filter((v) => v.v && v.v !== 'anon').length;
    const ipLevel = (ip) => judged.ips.get(ip)?.level || null;

    setText('visits', String(list.length));
    setText('visitsSub', showForeign ? '' : `外国からの ${foreignVisits.length} 件を隠しています`);
    setText('ips', String(ips.size));
    setText('foreign', String(foreignVisits.length));
    setText('foreignSub', `位置が分かった ${known} / ${visits.length} 件のうち`);
    setText('signed', String(signed));
    setText('danger', String([...ips].filter((ip) => ipLevel(ip) === 'danger').length));
    setText('dangerSub', `訪問 ${list.filter((v) => judgeOf(v).level === 'danger').length} 件`);
    setText('suspect', String([...ips].filter((ip) => ipLevel(ip) === 'suspect').length));
    setText('suspectSub', `訪問 ${list.filter((v) => judgeOf(v).level === 'suspect').length} 件`);

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

    // 国・地域別(▽ で地域・都市に展開する)
    const byCountry = new Map();
    list.forEach((v) => {
      const p = place(v.ip);
      const key = p.code === null ? '…' : p.code;
      const d = byCountry.get(key) || { n: 0, ips: new Set(), places: new Map() };
      d.n += 1;
      d.ips.add(v.ip);
      const sub = [p.region, p.city].filter((x) => x).join(' / ') || '(地域・都市が分からない)';
      const s = d.places.get(sub) || { n: 0, ips: new Set() };
      s.n += 1;
      s.ips.add(v.ip);
      d.places.set(sub, s);
      byCountry.set(key, d);
    });
    const countryRows = [];
    [...byCountry.entries()].sort((a, b) => b[1].n - a[1].n).forEach(([code, d]) => {
      const name = code === '…' ? '(まだ調べていない)' : code === 'LAN' ? 'LAN' : countryName(code);
      const open = expandedCountries.has(code);
      const tr = document.createElement('tr');
      const first = document.createElement('td');
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'btn btn-sm btn-link p-0 me-1 text-decoration-none';
      toggle.textContent = open ? '▽' : '▷';
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.title = open ? '閉じる' : '地域・都市を開く';
      toggle.addEventListener('click', () => {
        if (expandedCountries.has(code)) {
          expandedCountries.delete(code);
        } else {
          expandedCountries.add(code);
        }
        render();
      });
      first.append(toggle, document.createTextNode(name));
      tr.append(first, cell(String(d.n), [], 'text-end'), cell(String(d.ips.size), [], 'text-end'));
      countryRows.push(tr);
      if (open) {
        [...d.places.entries()].sort((a, b) => b[1].n - a[1].n).forEach(([sub, s]) => {
          const child = row([`　└ ${sub}`, String(s.n), String(s.ips.size)]);
          child.className = 'fs-7 text-body-secondary';
          countryRows.push(child);
        });
      }
    });
    fill('byCountry', countryRows, '記録がありません');

    // 世界地図のカード(国ごとの数・日ごとの数・BAN)
    if (world) {
      const days = Number(daysSelect?.value || 7);
      const keys = [];
      const keyOf = days === 1 ? (v) => (v.t || '').slice(11, 13) : (v) => (v.t || '').slice(0, 10);
      if (days === 1) {
        for (let h = 0; h < 24; h += 1) {
          keys.push(String(h).padStart(2, '0'));
        }
      } else {
        const p = (n) => String(n).padStart(2, '0');
        for (let i = days - 1; i >= 0; i -= 1) {
          const d = new Date(Date.now() - i * 86400000);
          keys.push(`${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`);
        }
      }
      const buckets = new Map(keys.map((k) => [k, { n: 0, ips: new Set(), danger: 0 }]));
      list.forEach((v) => {
        const b = buckets.get(keyOf(v));
        if (b) {
          b.n += 1;
          b.ips.add(v.ip);
          if (judgeOf(v).level === 'danger') {
            b.danger += 1;
          }
        }
      });
      const series = [...buckets.values()];
      const mapCounts = new Map();
      byCountry.forEach((d, code) => {
        if (/^[A-Z]{2}$/.test(code)) {
          mapCounts.set(code, { n: d.n, ips: d.ips.size });
        }
      });
      const visitsByIp = new Map();
      visits.forEach((v) => visitsByIp.set(v.ip, (visitsByIp.get(v.ip) || 0) + 1));
      const banned = bannedList().map((r) => ({ ...r, place: place(r.ip).label, visits: visitsByIp.get(r.ip) || 0 }));
      const bannedCountries = new Map();
      new Set(banned.map((r) => r.ip)).forEach((ip) => {
        const code = place(ip).code;
        if (code && /^[A-Z]{2}$/.test(code)) {
          bannedCountries.set(code, (bannedCountries.get(code) || 0) + 1);
        }
      });
      world.render({
        byCountry: mapCounts,
        deny: new Set((bans?.geoblock?.active ? bans.geoblock.deny || [] : []).map((c) => String(c).toUpperCase())),
        // 反転(書いた国だけ通す)なら、deny は「通す国」。地図は書いていない国を黒にする
        denyInvert: bans?.geoblock?.active === true && bans.geoblock.invert === true,
        bannedCountries,
        series: { visits: series.map((b) => b.n), ips: series.map((b) => b.ips.size), danger: series.map((b) => b.danger) },
        totals: { visits: list.length, ips: ips.size, danger: list.filter((v) => judgeOf(v).level === 'danger').length },
        bans,
        bansError,
        banned,
        limited: list.filter((v) => Number(v.s) === 429).length,
      });
    }

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
    const levelCell = (judge) => cell(
      judge.level === 'trusted' || !judge.score ? '' : String(judge.score),
      judge.level ? [badge(LEVEL_LABEL[judge.level], LEVEL_BADGE[judge.level])] : [],
      'text-nowrap',
    );

    // 危険・怪しい送り元(点の高い順)と、信頼する送り元
    const threatIps = [...ips].map((ip) => [ip, judged.ips.get(ip)]).filter(([, d]) => d && d.level)
      .sort((a, b) => (a[1].trusted !== b[1].trusted ? (a[1].trusted ? 1 : -1) : b[1].score - a[1].score || b[1].count - a[1].count))
      .slice(0, THREAT_ROWS);
    fill('threats', threatIps.map(([ip, d]) => {
      const tr = document.createElement('tr');
      tr.className = LEVEL_CLASS[d.level] || '';
      const action = document.createElement('td');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `btn btn-sm ${d.trusted ? 'btn-outline-secondary' : 'btn-outline-info'} text-nowrap`;
      button.textContent = d.trusted ? '信頼を外す' : '信頼する';
      button.title = d.trusted ? 'このブラウザでの信頼を外す' : '自分の回線など。このブラウザでだけ、色を付けなくする';
      button.addEventListener('click', () => {
        if (trusted.has(ip)) {
          trusted.delete(ip);
        } else {
          trusted.add(ip);
        }
        saveTrusted();
        render();
      });
      action.appendChild(button);
      const reasons = cell(d.reasons.join('・'), [], 'text-wrap');
      reasons.style.minWidth = '18rem';   // CSSOM なので CSP(style-src)に掛からない
      tr.append(
        levelCell(d),
        cell(ip),
        placeCell(ip),
        cell(ispLabel(ip)),
        cell(d.kind || ''),
        cell(String(d.count), [], 'text-end'),
        cell(String(d.peak || 0), [], 'text-end'),
        reasons,
        cell(d.last ? new Date(d.last).toLocaleString() : '', [], 'text-nowrap'),
        action,
      );
      return tr;
    }), '危険・怪しい送り元はありません');

    // 最近の訪問(新しい順・件数は選べる)。危険は赤・怪しいは黄。理由は行に重ねると出る
    const limit = Number(rowsSelect?.value || 50);
    const recent = list.slice(-limit).reverse();
    setText('recentSub', `${recent.length} / ${list.length} 件`);
    fill('recent', recent.map((v) => {
      const judge = judgeOf(v);
      const tr = document.createElement('tr');
      tr.append(
        cell(new Date(v.t).toLocaleString(), [], 'text-nowrap'),
        levelCell(judge),
        cell(v.ip),
        placeCell(v.ip),
        cell(ispLabel(v.ip)),
        cell(v.p),
        cell(`${v.m && v.m !== 'GET' ? `${v.m} ` : ''}${v.s}`),
        cell(viewerLabel(v.v)),
        cell(shortUa(v.ua)),
      );
      tr.className = LEVEL_CLASS[judge.level] || '';
      tr.title = [(judge.reasons || []).join('\n'), v.ua || ''].filter((x) => x).join('\n');
      return tr;
    }), '記録がありません');
  };

  // 送り元と最近の訪問は列が多いので、折り返さずに横へ流す(狭い画面では表の中で横に送る)。理由だけ折り返す
  ['threats', 'recent'].forEach((key) => $(key)?.closest('table')?.classList.add('text-nowrap'));

  // 危険リスト(判定の決まり)を表にする。読み込みのたびに変わらないので 1 度だけ
  const renderRules = () => {
    if (!threats) {
      fill('rules', [], '判定の決まり(visitors-threats.js)を読めませんでした');
      return;
    }
    const rules = [...threats.RULES, ...threats.IP_RULES].sort((a, b) => a.factor - b.factor);
    const rows = rules.map((r) => {
      const tr = document.createElement('tr');
      tr.append(
        cell(threats.FACTOR_LABELS[r.factor] || '', [], 'text-nowrap'),
        cell(`+${r.points}`, [], 'text-end'),
        cell('', [badge(LEVEL_LABEL[r.level], LEVEL_BADGE[r.level])]),
        cell(r.name),
        cell(r.what),
      );
      return tr;
    });
    const note = document.createElement('tr');
    note.appendChild(cell(
      `合計 ${threats.DANGER_SCORE} 点以上は危険(赤)・${threats.SUSPECT_SCORE} 点以上は怪しい(黄)。行の点 = その行の ② ⑥ + 送り元の ① ③ ④ ⑤ ⑦。` +
      '⑥ の URL はクエリ(パラメータ)を記録しないので、ページとメソッドで見ます。',
      [], 'text-body-secondary',
    ));
    note.cells[0].colSpan = 5;
    fill('rules', [...rows, note], '');
  };

  // ---- 書き出し(CSV。Excel で開ける)----
  const csvCell = (value) => {
    let s = value === null || value === undefined ? '' : String(value);
    if (/^[=+\-@\t\r]/.test(s)) {
      s = `'${s}`;   // Excel が式として読まないように
    }
    return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const download = (name, rows) => {
    const text = `﻿${rows.map((r) => r.map(csvCell).join(',')).join('\r\n')}\r\n`;
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
  const stamp = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`;
  };
  const exportVisits = () => {
    const header = ['時刻', '判定', '点数', '理由', 'IP', '国', '地域', '都市', 'ASN', 'ISP・組織', 'IP種別', 'ページ', 'メソッド', '状態', '大きさ', '種別', '端末(User-Agent)', '参照元'];
    const rows = shownVisits().map((v) => {
      const j = v._judge || { level: null, score: 0, reasons: [] };
      const g = geo.get(v.ip) || {};
      const info = judged.ips.get(v.ip) || {};
      return [
        v.t, j.level ? LEVEL_LABEL[j.level] : '', j.score ?? 0, (j.reasons || []).join(' / '), v.ip,
        isPrivate(v.ip) ? 'LAN' : (g.code || ''), g.region || '', g.city || '', g.asn ? `AS${g.asn}` : '', g.org || g.isp || '',
        info.kind || '', v.p, v.m || '', v.s, v.b ?? '', viewerLabel(v.v), v.ua || '', v.ref || '',
      ];
    });
    download(`visitors-${stamp()}.csv`, [header, ...rows]);
  };
  const exportIps = () => {
    const header = ['判定', '点数', 'IP', '国', '地域', '都市', 'ASN', 'ISP・組織', 'IP種別', '訪問', '1分の最多', '理由', '最後'];
    const shown = new Set(shownVisits().map((v) => v.ip));
    const rows = [...judged.ips.entries()].filter(([ip]) => shown.has(ip)).sort((a, b) => b[1].score - a[1].score).map(([ip, d]) => {
      const g = geo.get(ip) || {};
      return [
        d.level ? LEVEL_LABEL[d.level] : '', d.score, ip, isPrivate(ip) ? 'LAN' : (g.code || ''), g.region || '', g.city || '',
        g.asn ? `AS${g.asn}` : '', g.org || g.isp || '', d.kind || '', d.count, d.peak || 0, d.reasons.join(' / '), d.last,
      ];
    });
    download(`visitor-sources-${stamp()}.csv`, [header, ...rows]);
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
      if (!seen.has(ip) && needsLookup(ip) && !isPrivate(ip)) {
        queue.push(ip);
      }
      seen.add(ip);
    }
    // BAN している IP の国も(地図の赤)。訪問の IP のあと
    bannedList().forEach(({ ip }) => {
      if (!seen.has(ip) && needsLookup(ip) && !isPrivate(ip)) {
        queue.push(ip);
      }
      seen.add(ip);
    });
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

  /** BAN の様子。読めなくても訪問の表は出す */
  const loadBans = async () => {
    if (!root.dataset.bansSrc) {
      return;
    }
    try {
      const res = await fetch(root.dataset.bansSrc, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const data = await res.json();
      bans = data && typeof data.bans === 'object' ? data.bans : null;
      bansError = '';
    } catch (error) {
      bans = null;
      bansError = error.message;
    }
  };

  const load = async () => {
    geoQueueToken += 1;
    const days = Number(daysSelect?.value || 7);
    setText('status', '読み込み中…');
    const bansDone = loadBans();
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
      await bansDone;
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
  if (rowsSelect) {
    const saved = pref.get(PREF_ROWS, '50');
    rowsSelect.value = ROW_CHOICES.includes(saved) ? saved : '50';
    rowsSelect.addEventListener('change', () => {
      pref.set(PREF_ROWS, rowsSelect.value);
      render();
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
  document.getElementById('km-visitors-export-visits')?.addEventListener('click', exportVisits);
  document.getElementById('km-visitors-export-ips')?.addEventListener('click', exportIps);
  renderRules();
  document.getElementById('km-visitors-reload')?.addEventListener('click', load);

  loadCachesOrGiveUp().then(load);
})();
