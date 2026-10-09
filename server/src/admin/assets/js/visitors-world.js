/*
 * 訪問者のページの世界地図のカード(2026-10-08、利用者の指示。admin/visitors.php)。
 *
 * 本書(Website/index.html)の「Sales Value」のカード —— jsVectorMap の世界地図と、ApexCharts の小さなグラフ 3 つ —— を写したもの。
 *   - 地図: 国ごとの訪問(多いほど濃い)・国ごと拒否している国(黒)・BAN している IP の国(赤)。吹き出しに数
 *   - 小さなグラフ: 訪問・送り元・危険の、日ごと(期間が「今日」なら 1 時間ごと)の数。
 *     ApexCharts は style 属性を書くので CSP(style-src に unsafe-inline なし)に掛かる。同じ形を SVG で描く
 *   - BAN: 国の拒否・fail2ban・22 番の DROP の数と、BAN している IP が訪問の記録に何回出たか
 *
 * 数える・突き合わせるのは visitors.js。ここは受け取ったものを描くだけ。
 * 文字はすべて textContent(地図の吹き出しも、tooltip.text の 2 つ目の引数 = HTML を渡さない)。
 */
(() => {
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const COLOR_NONE = 'rgba(255, 255, 255, 0.35)';
  const COLOR_DENY = '#212529';
  const COLOR_BANNED = '#dc3545';
  const VISIT_FROM = [255, 243, 205];   // 少ない(#fff3cd)
  const VISIT_TO = [253, 126, 20];      // 多い(#fd7e14)
  const SPARK_COLOR = '#DCE6EC';        // 本書の小さなグラフの色
  const STALE_SEC = 5 * 60;

  const visitColor = (t) => `rgb(${VISIT_FROM.map((a, i) => Math.round(a + (VISIT_TO[i] - a) * t)).join(', ')})`;
  const num = (n) => Number(n || 0).toLocaleString();
  const bytes = (n) => {
    const v = Number(n || 0);
    if (v >= 1024 * 1024) {
      return `${(v / 1024 / 1024).toFixed(1)} MB`;
    }
    return v >= 1024 ? `${(v / 1024).toFixed(1)} KB` : `${v} B`;
  };
  const el = (tag, className = '', text = '') => {
    const e = document.createElement(tag);
    if (className) {
      e.className = className;
    }
    if (text) {
      e.textContent = text;
    }
    return e;
  };

  /** 小さなグラフ(本書の ApexCharts の sparkline と同じ見た目: 面 0.3・直線・下は 0) */
  const sparkline = (host, values) => {
    if (!host) {
      return;
    }
    const w = 120;
    const h = 50;
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'km-sparkline');
    svg.setAttribute('aria-hidden', 'true');
    if (values.length > 0) {
      const max = Math.max(1, ...values);
      const y = (v) => (h - 2 - (v / max) * (h - 4)).toFixed(1);
      const pts = values.length === 1
        ? [[0, y(values[0])], [w, y(values[0])]]
        : values.map((v, i) => [((i * w) / (values.length - 1)).toFixed(1), y(v)]);
      const line = pts.map((p) => p.join(',')).join(' ');
      const area = document.createElementNS(SVG_NS, 'polygon');
      area.setAttribute('points', `0,${h} ${line} ${w},${h}`);
      area.setAttribute('fill', SPARK_COLOR);
      area.setAttribute('fill-opacity', '0.3');
      const stroke = document.createElementNS(SVG_NS, 'polyline');
      stroke.setAttribute('points', line);
      stroke.setAttribute('fill', 'none');
      stroke.setAttribute('stroke', SPARK_COLOR);
      stroke.setAttribute('stroke-width', '2');
      stroke.setAttribute('vector-effect', 'non-scaling-stroke');
      svg.append(area, stroke);
    }
    host.replaceChildren(svg);
  };

  /**
   * @param {HTMLElement} root  #km-visitors
   * @param {{ countryName: (code: string) => string }} helpers
   */
  const create = (root, helpers) => {
    const card = document.getElementById('km-visitors-world');
    const mapEl = document.getElementById('km-visitors-world-map');
    const $ = (key) => root.querySelector(`[data-km-vs="${key}"]`);
    const tips = new Map();
    let map = null;
    let pending = null;   // 地図ができる前に来た色付け(できたら塗る)

    const ensureMap = () => {
      if (map || !mapEl || typeof window.jsVectorMap !== 'function') {
        return map;
      }
      try {
        // 本書と同じ new jsVectorMap({ selector: '#world-map', map: 'world' }) に、色と吹き出しを足したもの。
        // **jsVectorMap は読み込みの途中(readyState が loading)だと DOMContentLoaded まで描かない**ので、
        // 描き終えた知らせ(onLoaded)で、待たせていた色付けをする
        map = new window.jsVectorMap({
          selector: `#${mapEl.id}`,
          map: 'world',
          regionStyle: {
            initial: { fill: COLOR_NONE, stroke: 'rgba(255, 255, 255, 0.6)', strokeWidth: 0.3, fillOpacity: 1 },
            hover: { fillOpacity: 0.75, cursor: 'pointer' },
          },
          onLoaded() {
            map = this;
            if (pending) {
              const args = pending;
              pending = null;
              paint(...args);
            }
          },
          onRegionTooltipShow(event, tooltip, code) {
            tooltip.text(tips.get(code) || helpers.countryName(code));
          },
        });
      } catch {
        map = null;
      }
      return map;
    };

    // 折りたたんだあとに開くと地図の大きさが 0 のままになるので、開き終えたら測り直す
    card?.addEventListener('expanded.lte.card-widget', () => {
      try {
        map?.updateSize();
      } catch {
        // 描き直せないだけ
      }
    });

    /** denyInvert = 反転(denySet は「通す国」。書いていない国が拒否) */
    function paint(byCountry, denySet, bannedCountries, denyInvert = false) {
      const m = ensureMap();
      if (!m) {
        return;
      }
      if (!m.regions || Object.keys(m.regions).length === 0) {
        pending = [byCountry, denySet, bannedCountries, denyInvert];
        return;
      }
      const max = Math.max(1, ...[...byCountry.values()].map((d) => d.n));
      Object.keys(m.regions).forEach((code) => {
        const d = byCountry.get(code);
        const banned = bannedCountries.get(code) || 0;
        const denied = denyInvert ? !denySet.has(code) : denySet.has(code);
        let color = COLOR_NONE;
        if (denied) {
          color = COLOR_DENY;
        } else if (banned > 0) {
          color = COLOR_BANNED;
        } else if (d && d.n > 0) {
          color = visitColor(Math.log(d.n + 1) / Math.log(max + 1));
        }
        const parts = [helpers.countryName(code)];
        parts.push(d ? `訪問 ${num(d.n)} 件・送り元 ${num(d.ips)}` : '訪問なし');
        if (denied) {
          parts.push(denyInvert ? '国ごと拒否(通す国の外)' : '国ごと拒否');
        } else if (denyInvert) {
          parts.push('通す国');
        }
        if (banned > 0) {
          parts.push(`BAN 中の IP ${banned}`);
        }
        tips.set(code, parts.join('・'));
        const region = m.regions[code].element;
        const target = region && typeof region.setStyle === 'function' ? region : region?.shape;
        try {
          target?.setStyle('fill', color);
        } catch {
          // 塗れないだけ
        }
      });
    }

    const legend = () => {
      const host = $('worldLegend');
      if (!host) {
        return;
      }
      const chip = (cls, text) => el('span', `badge ${cls} me-1`, text);
      host.replaceChildren(
        chip('text-bg-warning', '訪問(多いほど濃い)'),
        chip('text-bg-danger', 'BAN 中の IP の国'),
        chip('text-bg-dark', '国ごと拒否'),
        el('span', 'text-white-50', typeof window.jsVectorMap === 'function' ? '' : '(地図の部品を読めませんでした)'),
      );
    };

    /** BAN の様子(ホストの集計と、BAN している IP の訪問の回数) */
    const bansBlock = (data) => {
      const host = $('bans');
      if (!host) {
        return;
      }
      const lines = [];
      const line = (text, cls = '') => lines.push(el('div', cls, text));
      const b = data.bans;
      if (data.bansError) {
        line(`BAN の様子を読めませんでした(${data.bansError})`, 'text-warning');
      } else if (!b) {
        line('BAN の様子はまだ届いていません(ホストの cron が 1 分ごとに書きます。配備の直後は 1 分ほど待って「読み直す」)', 'text-white-50');
      } else {
        const age = Math.round(Date.now() / 1000 - Number(b.generatedAt || 0));
        line(`ホストの集計: ${new Date(Number(b.generatedAt || 0) * 1000).toLocaleString()}${age > STALE_SEC ? '(古い —— cron が止まっているかもしれません)' : ''}`, age > STALE_SEC ? 'text-warning' : 'text-white-50');
        const g = b.geoblock || {};
        const names = (list) => (list || []).map((c) => helpers.countryName(String(c).toUpperCase())).join('・');
        if (!g.configured) {
          line('国の拒否: 設定なし(使っていない)');
        } else {
          // 反転なら deny は「通す国」(その国以外を拒否)
          const which = g.invert ? ` —— ${names(g.deny)} 以外を拒否(反転)` : (g.deny || []).length ? ` —— ${names(g.deny)}` : '';
          line(`国の拒否: ${g.active ? '当てています' : '当てていません'}${which}`
            + `・落とした回数 ${num(g.dropped?.packets)}(${bytes(g.dropped?.bytes)})`);
          if ((g.admin || []).length) {
            line(`管理用のポートは ${names(g.admin)} だけ・ほかから落とした回数 ${num(g.adminDropped?.packets)}`);
          }
        }
        const f = b.fail2ban || {};
        if (!f.running) {
          line('fail2ban: 動いていません');
        } else {
          (f.jails || []).forEach((j) => {
            line(`fail2ban(${j.name}): いま ${num(j.current)} 件 BAN・これまで ${num(j.total)} 件・失敗 ${num(j.failed)} 回`);
          });
        }
        line(`22 番の DROP(kosenmap-ssh-kick): ${num((b.iptables || []).length)} 件`);
      }
      line(`回数制限(429)で断った訪問: ${num(data.limited)} 件(この期間)`);

      host.replaceChildren(...lines);
      if (data.banned.length > 0) {
        const table = el('table', 'table table-sm km-world-ban-table text-nowrap mb-0');
        const head = el('tr');
        ['BAN 中の IP', '場所', '仕組み', '訪問(この期間)', '落とした回数'].forEach((t, i) => head.appendChild(el('th', i >= 3 ? 'text-end' : '', t)));
        const thead = el('thead');
        thead.appendChild(head);
        const tbody = el('tbody');
        data.banned.forEach((r) => {
          const tr = el('tr');
          tr.append(
            el('td', 'text-nowrap', r.ip),
            el('td', '', r.place),
            el('td', 'text-nowrap', r.how),
            el('td', 'text-end', num(r.visits)),
            el('td', 'text-end', r.packets === null ? '—' : num(r.packets)),
          );
          tbody.appendChild(tr);
        });
        table.append(thead, tbody);
        // fail2ban の牢は数百件になることがあるので、表だけを枠の中で縦横に送る
        const scroll = el('div', 'km-world-ban-scroll mt-2');
        scroll.appendChild(table);
        host.appendChild(scroll);
      }
    };

    legend();
    return {
      /**
       * @param {{ byCountry: Map<string, {n:number, ips:number}>, deny: Set<string>, denyInvert: boolean, bannedCountries: Map<string, number>,
       *           series: {visits:number[], ips:number[], danger:number[]}, totals: {visits:number, ips:number, danger:number},
       *           bans: object|null, bansError: string, banned: object[], limited: number }} data
       */
      render(data) {
        paint(data.byCountry, data.deny, data.bannedCountries, data.denyInvert === true);
        sparkline($('spark1'), data.series.visits);
        sparkline($('spark2'), data.series.ips);
        sparkline($('spark3'), data.series.danger);
        const total = (key, n) => {
          const t = $(key);
          if (t) {
            t.textContent = num(n);
          }
        };
        total('spark1Total', data.totals.visits);
        total('spark2Total', data.totals.ips);
        total('spark3Total', data.totals.danger);
        bansBlock(data);
      },
    };
  };

  window.KmVisitorWorld = { create, sparkline };
})();
