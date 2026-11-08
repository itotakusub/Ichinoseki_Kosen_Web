/*
 * 訪問者の画面の Cloudflare のカード(2026-11-08。admin/visitors.php)。
 *
 * admin/api/cloudflare-data.php が渡す 2 つ(取り込むのはホストの cron。lib/cloudflare-analytics.php):
 *   - WAF で止めた記録 … **サーバーまで届かない**ので訪問者の記録には無い分。同じ IP の訪問(記録)と判定を横に並べる
 *   - 時間ごとの通信量 … 日ごとにまとめ、「サーバーに届いた訪問」(記録)と並べる
 *
 * 描くのは visitors.js の render() の最後(window.KmVisitorCloudflare.render)。部品(cell・badge・fill など)もそこから受け取る。
 * 文字はすべて textContent で入れる(パス・端末名を HTML として読まない)。style 属性は使わない(CSP)。
 */
(() => {
  const WAF_ROWS = 100;
  let data = null;
  let error = '';
  // 書き出しのボタンは 1 度だけ結ぶので、いちばん新しい描画の中身をここに置く
  let latest = { visitsByIp: new Map(), ctx: null };

  const p2 = (n) => String(n).padStart(2, '0');
  const dayOf = (epoch) => {
    const d = new Date(epoch * 1000);
    return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`;
  };
  const bytes = (n) => {
    if (!Number.isFinite(n) || n <= 0) {
      return '0';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1000)));
    return `${(n / 1000 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  };
  const percent = (a, b) => (b > 0 ? `${Math.round((a / b) * 100)}%` : '—');

  const load = async (root, days) => {
    const src = root.dataset.cloudflareSrc;
    if (!src) {
      return;
    }
    try {
      const res = await fetch(`${src}?days=${encodeURIComponent(days)}`, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      data = await res.json();
      error = '';
    } catch (e) {
      data = null;
      error = e.message;
    }
  };

  const render = (ctx) => {
    const { visits, judged, geo, countryName, cell, badge, row, fill, setText, LEVEL_LABEL, LEVEL_BADGE, LEVEL_CLASS } = ctx;

    // 取り込みの様子
    const state = data?.state || {};
    const notes = [];
    if (error) {
      notes.push(`読み込めませんでした(${error})`);
    } else if (!data?.configured) {
      notes.push('まだ取り込んでいません(ホストに cloudflare.local.conf を置き、cron を版 14 にする。docs/17 §6)');
    } else {
      if (state.last_success?._at) {
        notes.push(`最後に取り込んだ: ${new Date(state.last_success._at * 1000).toLocaleString()}`);
      }
      if (state.last_error?._at && (!state.last_success?._at || state.last_error._at > state.last_success._at)) {
        notes.push(`★ 取り込みに失敗: ${state.last_error.message || ''}(${new Date(state.last_error._at * 1000).toLocaleString()})`);
      }
      if (state.settings?.note) {
        notes.push(state.settings.note);
      }
    }
    setText('cfStatus', notes.join(' —— '));

    const waf = Array.isArray(data?.waf) ? data.waf : [];
    const traffic = Array.isArray(data?.traffic) ? data.traffic : [];

    // 同じ IP の訪問(サーバーまで届いた分)
    const visitsByIp = new Map();
    visits.forEach((v) => visitsByIp.set(v.ip, (visitsByIp.get(v.ip) || 0) + 1));
    latest = { visitsByIp, ctx };
    const visitsByDay = new Map();
    visits.forEach((v) => {
      const day = (v.t || '').slice(0, 10);
      visitsByDay.set(day, (visitsByDay.get(day) || 0) + 1);
    });

    // 日別(Cloudflare 全体と、サーバーに届いた訪問)
    const days = new Map();
    traffic.forEach((h) => {
      const day = dayOf(h.hour);
      const d = days.get(day) || { requests: 0, cached: 0, bytes: 0, threats: 0, pageViews: 0, waf: 0 };
      d.requests += h.requests;
      d.cached += h.cachedRequests;
      d.bytes += h.bytes;
      d.threats += h.threats;
      d.pageViews += h.pageViews;
      days.set(day, d);
    });
    waf.forEach((e) => {
      const day = dayOf(e.at);
      const d = days.get(day) || { requests: 0, cached: 0, bytes: 0, threats: 0, pageViews: 0, waf: 0 };
      d.waf += 1;
      days.set(day, d);
    });
    fill('cfByDay', [...days.entries()].sort((a, b) => b[0].localeCompare(a[0])).map(([day, d]) => row([
      day, String(d.requests), percent(d.cached, d.requests), bytes(d.bytes), String(d.threats), String(d.waf), String(visitsByDay.get(day) || 0),
    ])), data?.configured ? '記録がありません' : 'まだ取り込んでいません');

    // 止めた国(上位)。地図の国名と同じ書き方
    const byCountry = new Map();
    waf.forEach((e) => {
      const key = e.country || '?';
      const d = byCountry.get(key) || { n: 0, ips: new Set() };
      d.n += 1;
      d.ips.add(e.ip);
      byCountry.set(key, d);
    });
    fill('cfByCountry', [...byCountry.entries()].sort((a, b) => b[1].n - a[1].n).slice(0, 30)
      .map(([code, d]) => row([code === '?' ? '不明' : countryName(code), String(d.n), String(d.ips.size)])), '止めた記録はありません');

    // 止めた記録(新しい順)。同じ IP の訪問と判定を並べる
    const total = Number(data?.wafTotal) || waf.length;
    setText('cfWaf', String(total));
    setText('cfWafSub', `IP ${new Set(waf.map((e) => e.ip)).size}・国 ${byCountry.size}`);
    setText('cfWafListSub', `${Math.min(WAF_ROWS, waf.length)} / ${total} 件`);
    fill('cfWafList', waf.slice(0, WAF_ROWS).map((e) => {
      const tr = document.createElement('tr');
      const seen = visitsByIp.get(e.ip) || 0;
      const judge = judged.ips.get(e.ip);
      const g = geo.get(e.ip);
      const placeText = [e.country ? countryName(e.country) : '不明', g && g.src !== 'cloudflare' ? [g.region, g.city].filter((x) => x).join(' / ') : '']
        .filter((x) => x).join(' / ');
      tr.append(
        cell(new Date(e.at * 1000).toLocaleString(), [], 'text-nowrap'),
        cell(e.ip),
        cell(placeText),
        cell([e.asn ? `AS${e.asn}` : '', e.asnName].filter((x) => x).join(' ')),
        cell(`${e.method && e.method !== 'GET' ? `${e.method} ` : ''}${(e.host || '').replace(/:443$/, '')}${e.path}`),
        cell(e.description || e.rule || e.source || ''),
        cell(seen ? String(seen) : '—', [], 'text-end'),
        cell(judge?.score ? String(judge.score) : '', judge?.level ? [badge(LEVEL_LABEL[judge.level], LEVEL_BADGE[judge.level])] : [], 'text-nowrap'),
      );
      // サーバーにも届いていた IP(止める前・日本からの別の回線など)は色で見分ける
      if (seen > 0) {
        tr.className = LEVEL_CLASS[judge?.level] || 'table-info';
      }
      tr.title = e.ua || '';
      return tr;
    }), data?.configured ? '止めた記録はありません' : 'まだ取り込んでいません');

    // 書き出し(調べもの用。いま読んでいる期間の全部 = 5000 件まで)
    const button = document.getElementById('km-visitors-export-waf');
    if (button && !button.dataset.kmBound) {
      button.dataset.kmBound = '1';
      button.addEventListener('click', () => {
        const header = ['時刻', 'Ray ID', '動作', 'ルール', '仕組み', 'IP', '国', 'ASN', 'ASN の名前', 'ホスト', 'メソッド', 'パス', '端末(User-Agent)', 'この IP の訪問(記録)'];
        const rows = (Array.isArray(data?.waf) ? data.waf : []).map((e) => [
          new Date(e.at * 1000).toISOString(), e.ray, e.action, e.description || e.rule, e.source, e.ip, e.country,
          e.asn ? `AS${e.asn}` : '', e.asnName, e.host, e.method, e.path, e.ua, latest.visitsByIp.get(e.ip) || 0,
        ]);
        latest.ctx.csvDownload(`cloudflare-waf-${latest.ctx.stamp()}.csv`, [header, ...rows]);
      });
    }
  };

  window.KmVisitorCloudflare = { load, render };
})();
