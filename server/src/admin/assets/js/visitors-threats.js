/*
 * 訪問者の色分け(2026-10-08、利用者の指示)。admin/visitors.php が visitors.js より先に読む。**判定もブラウザ**。
 *
 * ## 点数で決める(2026-10-08 の 2 回目、利用者の指示「① ISP/ASN → ② User-Agent → ③ IP 種別 → ④ アクセス頻度 →
 *    ⑤ HTTP ステータス → ⑥ URL/パラメータ → ⑦ 過去の挙動 を計算する」)
 * 7 つの見方ごとに点を足し、**60 点以上は危険(赤)・25 点以上は怪しい(黄)**。
 *   行の点 = その行だけで決まる点(② ⑥)+ 送り元(IP)の点(① ③ ④ ⑤ ⑦)
 *   送り元の点 = その送り元の行のうち、いちばん高い行の点
 * 前は「同じ送り元が危険な要求を送ったら、その送り元の行は全部赤」だったので、同じ IP の普通の閲覧まで赤くなった
 * (試験の curl と同じ回線の Chrome)。いまは ⑦ の 20 点だけを、その送り元のほかの行に足す。
 *
 * 記録に残るのは公開のページ(/・/index.php・/guest.php・/share.php・/faq.php・/contact.php・/api/app-map.php)だけで、
 * **URL のクエリ(パラメータ)は残さない**(nginx の km_visit。リンクの中身を残さないため)。⑥ はメソッドとページで見る。
 * ① は「位置を調べる」で引いた ASN・組織名(ipwho.is)。引いていない送り元は ① を 0 点にする。
 *
 * 名乗りは簡単に偽れる。**色が付いていないことは安全の印ではない。**
 */
(() => {
  const DANGER_SCORE = 60;
  const SUSPECT_SCORE = 25;

  /** POST を受けるページ。ほかへの POST は攻撃の形(トップページは POST を受けない。CVE-2024-4577 などが POST で来る) */
  const POST_PAGES = new Set(['/contact.php', '/guest.php', '/share.php', '/api/app-map.php']);

  /** データセンター・クラウド・ホスティングの組織(人がブラウザで見に来ることは少ない) */
  const HOSTING = /amazon|aws|google cloud|google llc|microsoft|azure|digitalocean|linode|akamai|vultr|choopa|\bovh|hetzner|contabo|leaseweb|alibaba|aliyun|tencent|huawei cloud|oracle|scaleway|m247|hostinger|ionos|godaddy|cloudflare|fastly|datacamp|cdn77|psychz|colocrossing|hostwinds|zenlayer|ucloud|kaopu|baidu|sakura internet|xserver|gmo internet|hosting|datacenter|data center|server|vps|cloud/i;

  /** 1 行で決まる決まり(② User-Agent・⑥ URL/メソッド)。当たったものを全部足す */
  const RULES = [
    {
      id: 'redtail', factor: 2, points: 60, level: 'danger',
      name: 'RedTail(仮想通貨を掘らせるマルウェア)の攻撃',
      what: '名乗りが libredtail-http。PHP や機器の脆弱性を無差別に突いて回る',
      test: (v) => /libredtail|redtail/i.test(v.ua || ''),
    },
    {
      id: 'attack-tool', factor: 2, points: 60, level: 'danger',
      name: '攻撃・脆弱性探しの道具',
      what: '名乗りが sqlmap・nikto・nuclei・ZmEu・WPScan・Acunetix・ffuf・gobuster など',
      test: (v) => /sqlmap|nikto|nuclei|zmeu|morfeus|wpscan|acunetix|netsparker|jaeles|ffuf|fuzz faster|gobuster|dirbuster|feroxbuster|hydra|joomscan|commix|havij|xray\b/i.test(v.ua || ''),
    },
    {
      id: 'ua-payload', factor: 2, points: 60, level: 'danger',
      name: '名乗りに攻撃の文字列',
      what: '${jndi:(Log4Shell)・() {(Shellshock)・<script・union select・../・/etc/passwd など',
      test: (v) => /\$\{|\(\)\s*\{|<script|union\s+select|\.\.\/|\/etc\/passwd|cmd\.exe|powershell|(wget|curl)\s+https?:/i.test(v.ua || ''),
    },
    {
      id: 'tool-ua', factor: 2, points: 20, level: 'suspect',
      name: 'プログラム・調査用スキャナー',
      what: 'curl・wget・python-requests・Go・Java・zgrab・masscan・Nmap・Censys・Expanse・Shodan など',
      test: (v) => /curl\/|wget|python-requests|python-urllib|aiohttp|httpx|go-http-client|^java\/|libwww-perl|axios\/|node-fetch|scrapy|zgrab|masscan|\bnmap\b|censys|expanse|internet-?measurement|shodan|leakix|paloalto|netcraft|l9explore|odin\.io|modat/i.test(v.ua || ''),
    },
    {
      id: 'no-ua', factor: 2, points: 15, level: 'suspect',
      name: '名乗りなし',
      what: '端末の名乗り(User-Agent)が空',
      test: (v) => !v.ua || v.ua === '-',
    },
    {
      id: 'post-to-page', factor: 6, points: 60, level: 'danger',
      name: 'POST を受けないページへの POST',
      what: '/・/index.php・/faq.php への POST(PHP の脆弱性を突く形。CVE-2024-4577 など)',
      test: (v) => v.m === 'POST' && !POST_PAGES.has(v.p),
    },
    {
      id: 'odd-method', factor: 6, points: 20, level: 'suspect',
      name: '普段使わないメソッド',
      what: 'GET・HEAD・POST 以外(PUT・DELETE・PROPFIND・CONNECT など)',
      test: (v) => typeof v.m === 'string' && !['GET', 'HEAD', 'POST'].includes(v.m),
    },
  ];

  /** 送り元(IP)ごとに決まる決まり(① ③ ④ ⑤ ⑦)。表示用の説明(点の付け方は classify の中) */
  const IP_RULES = [
    { id: 'hosting', factor: 1, points: 25, level: 'suspect', name: 'データセンター・クラウドの回線', what: 'ISP・組織名(ASN)が AWS・Google Cloud・Azure・さくら・VPS など(「位置を調べる」で引いたもの)' },
    { id: 'foreign', factor: 3, points: 10, level: 'suspect', name: '国外の IP', what: '国が日本以外(LAN は 0 点)' },
    { id: 'burst', factor: 4, points: 20, level: 'suspect', name: '短い間に多い', what: '1 分に 30 回以上(60 回以上なら 30 点)' },
    { id: 'failures', factor: 5, points: 20, level: 'suspect', name: '失敗が多い', what: '4xx(見つからない・断った)が 10 回以上(5 回以上で半分以上が 4xx なら 10 点)・5xx が 3 回以上で 10 点' },
    { id: 'same-ip-danger', factor: 7, points: 20, level: 'suspect', name: '同じ送り元が危険な要求を送った', what: '期間の中で、その送り元のほかの行が 60 点以上' },
    { id: 'history', factor: 7, points: 20, level: 'suspect', name: '前にも危険だった', what: 'このブラウザが覚えている、期間より前の危険(30 日)' },
  ];
  const FACTOR_LABELS = { 1: '① ISP/ASN', 2: '② User-Agent', 3: '③ IP 種別', 4: '④ アクセス頻度', 5: '⑤ HTTP ステータス', 6: '⑥ URL・メソッド', 7: '⑦ 過去の挙動' };
  const CIRCLED = { 1: '①', 2: '②', 3: '③', 4: '④', 5: '⑤', 6: '⑥', 7: '⑦' };
  const BURST_WINDOW_MS = 60 * 1000;

  const levelOf = (score) => (score >= DANGER_SCORE ? 'danger' : score >= SUSPECT_SCORE ? 'suspect' : null);
  const hit = (factor, points, text) => ({ factor, points, text: `${CIRCLED[factor]} ${text}(+${points})` });

  /** 送り元の種類(③)。geo は visitors.js の位置の覚え({ code, asn, org, isp })。 */
  const ipKind = (ip, g, isPrivate) => {
    if (isPrivate) return 'LAN';
    if (!g) return '不明';
    if (g.org && HOSTING.test(`${g.org} ${g.isp || ''}`)) return 'データセンター';
    return g.code === 'JP' ? '国内' : '国外';
  };

  /**
   * 判定する。**純粋関数**(訪問の配列は書き換えない)。
   * @param visits 訪問の配列(t, ip, m, p, s, ua)
   * @param ctx { geoOf(ip) → {code, asn, org, isp}|undefined, isPrivate(ip) → bool, trusted: Set<ip>, history: Map<ip, {day, score}> }
   * @returns { rows: [{level, score, reasons, trusted}], ips: Map<ip, {level, score, reasons, count, last, kind, org, asn, trusted}> }
   */
  const classify = (visits, ctx = {}) => {
    const geoOf = ctx.geoOf || (() => undefined);
    const isPrivate = ctx.isPrivate || (() => false);
    const trusted = ctx.trusted || new Set();
    const history = ctx.history || new Map();

    // ② ⑥: 行だけで決まる点
    const own = visits.map((v) => {
      const hits = RULES.filter((r) => r.test(v)).map((r) => hit(r.factor, r.points, r.name));
      return { score: hits.reduce((s, h) => s + h.points, 0), hits };
    });

    // 送り元ごとにまとめる
    const byIp = new Map();
    visits.forEach((v, i) => {
      const d = byIp.get(v.ip) || { indexes: [], f4: 0, f5: 0, times: [], days: new Set(), last: '' };
      d.indexes.push(i);
      if (typeof v.s === 'number' && v.s >= 400 && v.s < 500) d.f4 += 1;
      if (typeof v.s === 'number' && v.s >= 500) d.f5 += 1;
      const t = Date.parse(v.t || '');
      if (!Number.isNaN(t)) d.times.push(t);
      d.days.add((v.t || '').slice(0, 10));
      if ((v.t || '') > d.last) d.last = v.t || '';
      byIp.set(v.ip, d);
    });

    const ips = new Map();
    byIp.forEach((d, ip) => {
      const g = geoOf(ip);
      const priv = isPrivate(ip);
      const kind = ipKind(ip, g, priv);
      const context = [];
      // ① ISP/ASN
      if (kind === 'データセンター') context.push(hit(1, 25, `データセンター・クラウド(${g.asn ? `AS${g.asn} ` : ''}${g.org})`));
      // ③ IP 種別
      if (kind === '国外') context.push(hit(3, 10, '国外の IP'));
      // ④ アクセス頻度(1 分の窓で数える)
      const times = d.times.slice().sort((a, b) => a - b);
      let peak = 0;
      for (let s = 0, e = 0; e < times.length; e += 1) {
        while (times[e] - times[s] > BURST_WINDOW_MS) s += 1;
        peak = Math.max(peak, e - s + 1);
      }
      if (peak >= 60) context.push(hit(4, 30, `1 分に ${peak} 回`));
      else if (peak >= 30) context.push(hit(4, 20, `1 分に ${peak} 回`));
      // ⑤ HTTP ステータス
      const total = d.indexes.length;
      if (d.f4 >= 10) context.push(hit(5, 20, `4xx が ${d.f4} 回`));
      else if (d.f4 >= 5 && d.f4 / total >= 0.5) context.push(hit(5, 10, `4xx が ${d.f4} / ${total} 回`));
      if (d.f5 >= 3) context.push(hit(5, 10, `5xx が ${d.f5} 回`));
      // ⑦ 過去の挙動: 期間の中で危険な行を送った・このブラウザが覚えている前の危険
      const dangerRows = d.indexes.filter((i) => own[i].score >= DANGER_SCORE);
      const firstDay = [...d.days].sort()[0] || '';
      const remembered = history.get(ip);
      const past = [];
      if (dangerRows.length > 0) past.push(hit(7, 20, `この期間に危険な要求 ${dangerRows.length} 回`));
      if (remembered && remembered.day && remembered.day < firstDay) past.push(hit(7, 20, `前にも危険(${remembered.day})`));
      const pastPoints = Math.min(30, past.reduce((s, h) => s + h.points, 0));

      const contextScore = context.reduce((s, h) => s + h.points, 0);
      // 送り元の点 = いちばん高い行(その行の点 + 送り元の点)
      let best = 0;
      let bestHits = [];
      d.indexes.forEach((i) => {
        const rowPast = own[i].score >= DANGER_SCORE ? 0 : pastPoints; // 危険な行そのものには ⑦ を足さない(二重に数えない)
        const score = own[i].score + contextScore + rowPast;
        if (score > best || bestHits.length === 0) {
          best = score;
          bestHits = [...own[i].hits, ...context, ...(rowPast > 0 ? past : [])];
        }
      });
      const isTrusted = trusted.has(ip);
      ips.set(ip, {
        level: isTrusted ? 'trusted' : levelOf(best),
        score: isTrusted ? 0 : best,
        reasons: isTrusted ? ['信頼する送り元(このブラウザで指定)'] : bestHits.map((h) => h.text),
        count: total,
        last: d.last,
        kind,
        org: g ? (g.org || g.isp || '') : '',
        asn: g ? (g.asn || null) : null,
        peak,
        contextScore,
        pastPoints,
        context,
        past,
        trusted: isTrusted,
      });
    });

    const rows = visits.map((v, i) => {
      const info = ips.get(v.ip);
      if (info.trusted) return { level: 'trusted', score: 0, reasons: info.reasons, trusted: true };
      const rowPast = own[i].score >= DANGER_SCORE ? 0 : info.pastPoints;
      const score = own[i].score + info.contextScore + rowPast;
      return {
        level: levelOf(score),
        score,
        reasons: [...own[i].hits, ...info.context, ...(rowPast > 0 ? info.past : [])].map((h) => h.text),
        trusted: false,
      };
    });
    return { rows, ips };
  };

  const api = {
    RULES, IP_RULES, POST_PAGES, FACTOR_LABELS, DANGER_SCORE, SUSPECT_SCORE, classify, ipKind,
    LABELS: { danger: '危険', suspect: '怪しい', trusted: '信頼' },
  };
  if (typeof window !== 'undefined') {
    window.KmVisitorThreats = api;
  }
  if (typeof module !== 'undefined') {
    module.exports = api;
  }
})();
