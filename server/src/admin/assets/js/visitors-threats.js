/*
 * 訪問者の色分け(2026-10-08、利用者の指示「危険な接続を赤、怪しい接続を黄色。危険リストを作る」)。
 * admin/visitors.php が visitors.js より先に読む。**判定もブラウザ**(記録を読み解くのはクライアント、の決まりのまま)。
 *
 * 記録に残るのは公開のページ(/・/index.php・/guest.php・/share.php・/faq.php・/contact.php・/api/app-map.php)だけで、
 * クエリも残らない(nginx の km_visit)。だから攻撃の URL そのもの(/vendor/phpunit/… や ?%ADd+allow_url_include)は
 * ここには出ない。見られるのは**名乗り(User-Agent)・メソッド・状態・同じ送り元のふるまい**。
 *
 *   危険(赤)  … 攻撃だと分かるもの。2026-10-07 に本番へ来た RedTail(libredtail-http)が例
 *   怪しい(黄) … 攻撃とまでは言えないが、人がブラウザで開いたのではないもの
 *
 * 名乗りは簡単に偽れる。**赤・黄でないことは安全の印ではない**(偽った攻撃は普通の色で出る)。
 */
(() => {
  /** POST を受けるページ。ほかへの POST は攻撃の形(トップページは POST を受けない。CVE-2024-4577 などが POST で来る) */
  const POST_PAGES = new Set(['/contact.php', '/guest.php', '/share.php', '/api/app-map.php']);

  /** 1 行で決まる決まり。上から順に見て、当たったものを全部理由にする */
  const RULES = [
    {
      id: 'redtail',
      level: 'danger',
      name: 'RedTail(仮想通貨を掘らせるマルウェア)の攻撃',
      what: '名乗りが libredtail-http。PHP や機器の脆弱性を無差別に突いて回る',
      test: (v) => /libredtail|redtail/i.test(v.ua || ''),
    },
    {
      id: 'attack-tool',
      level: 'danger',
      name: '攻撃・脆弱性探しの道具',
      what: '名乗りが sqlmap・nikto・nuclei・ZmEu・WPScan・Acunetix・ffuf・gobuster など',
      test: (v) => /sqlmap|nikto|nuclei|zmeu|morfeus|wpscan|acunetix|netsparker|jaeles|ffuf|fuzz faster|gobuster|dirbuster|feroxbuster|hydra|joomscan|commix|havij|xray\b/i.test(v.ua || ''),
    },
    {
      id: 'ua-payload',
      level: 'danger',
      name: '名乗りに攻撃の文字列',
      what: '${jndi:(Log4Shell)・() {(Shellshock)・<script・union select・../・/etc/passwd など',
      test: (v) => /\$\{|\(\)\s*\{|<script|union\s+select|\.\.\/|\/etc\/passwd|cmd\.exe|powershell|(wget|curl)\s+https?:/i.test(v.ua || ''),
    },
    {
      id: 'post-to-page',
      level: 'danger',
      name: 'POST を受けないページへの POST',
      what: '/・/index.php・/faq.php への POST(PHP の脆弱性を突く形。CVE-2024-4577 など)',
      test: (v) => v.m === 'POST' && !POST_PAGES.has(v.p),
    },
    {
      id: 'no-ua',
      level: 'suspect',
      name: '名乗りなし',
      what: '端末の名乗り(User-Agent)が空',
      test: (v) => !v.ua || v.ua === '-',
    },
    {
      id: 'tool-ua',
      level: 'suspect',
      name: 'プログラム・調査用スキャナー',
      what: 'curl・wget・python-requests・Go・Java・zgrab・masscan・Nmap・Censys・Expanse・Shodan など',
      test: (v) => /curl\/|wget|python-requests|python-urllib|aiohttp|httpx|go-http-client|^java\/|libwww-perl|axios\/|node-fetch|scrapy|zgrab|masscan|nmap|censys|expanse|internet-?measurement|shodan|leakix|paloalto|netcraft|l9explore|odin\.io|modat/i.test(v.ua || ''),
    },
    {
      id: 'odd-method',
      level: 'suspect',
      name: '普段使わないメソッド',
      what: 'GET・HEAD・POST 以外(PUT・DELETE・PROPFIND・CONNECT など)',
      test: (v) => typeof v.m === 'string' && !['GET', 'HEAD', 'POST'].includes(v.m),
    },
  ];

  /** 送り元(IP)ごとに決まる決まり(1 行では分からないもの)。表示用の説明 */
  const IP_RULES = [
    { id: 'same-ip-danger', level: 'danger', name: '同じ送り元が危険な要求', what: '同じ IP のほかの行が赤なら、その IP の行はすべて赤' },
    { id: 'many-failures', level: 'suspect', name: '失敗が多い', what: '同じ IP から 4xx(見つからない・断った)が 10 回以上' },
    { id: 'burst', level: 'suspect', name: '短い間に多い', what: '同じ IP から 1 分の間に 30 回以上' },
  ];
  const FAILURES_LIMIT = 10;
  const BURST_COUNT = 30;
  const BURST_WINDOW_MS = 60 * 1000;

  const RANK = { danger: 2, suspect: 1 };
  const higher = (a, b) => ((RANK[a] || 0) >= (RANK[b] || 0) ? a : b);

  /**
   * 判定する。**純粋関数**(訪問の配列は書き換えない)。
   * @returns {{rows: Array<{level: string|null, reasons: string[]}>, ips: Map<string, {level: string|null, reasons: string[], count: number, last: string}>}}
   *   rows は visits と同じ順。level は 'danger' / 'suspect' / null
   */
  const classify = (visits) => {
    const own = visits.map((v) => {
      const hits = RULES.filter((r) => r.test(v));
      return {
        level: hits.reduce((acc, r) => higher(acc, r.level), null),
        reasons: hits.map((r) => r.name),
      };
    });

    // 送り元ごとにまとめる
    const byIp = new Map();
    visits.forEach((v, i) => {
      const d = byIp.get(v.ip) || { indexes: [], failures: 0, times: [], last: '' };
      d.indexes.push(i);
      if (typeof v.s === 'number' && v.s >= 400 && v.s < 500) {
        d.failures += 1;
      }
      const t = Date.parse(v.t || '');
      if (!Number.isNaN(t)) {
        d.times.push(t);
      }
      if ((v.t || '') > d.last) {
        d.last = v.t || '';
      }
      byIp.set(v.ip, d);
    });

    const ips = new Map();
    byIp.forEach((d, ip) => {
      const reasons = new Set();
      let level = null;
      d.indexes.forEach((i) => {
        own[i].reasons.forEach((r) => reasons.add(r));
        level = higher(level, own[i].level);
      });
      if (level === 'danger' && d.indexes.length > 1) {
        reasons.add(IP_RULES[0].name);
      }
      if (d.failures >= FAILURES_LIMIT) {
        reasons.add(`${IP_RULES[1].name}(${d.failures} 回)`);
        level = higher(level, 'suspect');
      }
      const times = d.times.slice().sort((a, b) => a - b);
      for (let s = 0, e = 0; e < times.length; e += 1) {
        while (times[e] - times[s] > BURST_WINDOW_MS) {
          s += 1;
        }
        if (e - s + 1 >= BURST_COUNT) {
          reasons.add(IP_RULES[2].name);
          level = higher(level, 'suspect');
          break;
        }
      }
      ips.set(ip, { level, reasons: [...reasons], count: d.indexes.length, last: d.last });
    });

    // 行の色は、その行と送り元のうち重い方。理由は行のもの + 送り元のもの
    const rows = visits.map((v, i) => {
      const ipInfo = ips.get(v.ip);
      const level = higher(own[i].level, ipInfo ? ipInfo.level : null);
      const reasons = [...new Set([...own[i].reasons, ...(ipInfo ? ipInfo.reasons : [])])];
      return { level, reasons };
    });
    return { rows, ips };
  };

  const api = { RULES, IP_RULES, POST_PAGES, classify, LABELS: { danger: '危険', suspect: '怪しい' } };
  if (typeof window !== 'undefined') {
    window.KmVisitorThreats = api;
  }
  if (typeof module !== 'undefined') {
    module.exports = api;
  }
})();
