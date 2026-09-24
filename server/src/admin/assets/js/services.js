/*!
 * KosenMap Admin — 監視対象サービスの定義とカード生成
 *
 * エンドポイントはここ 1 箇所にまとめてある。実疎通を実装するフェーズでは
 * この配列に status を書き戻すだけで画面が追随する。
 *
 * 注意: ブラウザから 8281 / 3001 / 3002 / 6001 を直接 fetch すると、
 * 自己署名証明書と CORS で必ず失敗する。実際の死活監視は 9443 の PHP 側に
 * プロキシ用エンドポイントを立てて、サーバー間通信で行うこと。
 */
(() => {
  'use strict';

  /*
   * ホスト名は **PHP が window.KM_SITE_HOST に入れて渡す**(出所は APP_URL =
   * lib/site.php)。ここに直書きしていると、VPS + 独自ドメインへ移したときに
   * この画面のリンクだけが古い宛先を指し続ける。
   * 未定義のときだけ従来の値へ落として、単体で開いても壊れないようにする。
   */
  const HOST = window.KM_SITE_HOST || '192.168.3.29';

  /*
   * **ポートと URL も PHP から受け取る。**
   * 以前はここで `https://${HOST}:9443` のように組み立てていたが、
   * 8080 / 9443 は校内 LAN 専用の番号で、VPS では 80 / 443 になる。
   * そのままだと **この画面のリンクだけが存在しない宛先を指す**
   * (しかも「開く」を押すまで気付けない)。
   * 受け取れないときだけ、下の直書きの値へ落ちる。
   */
  const FROM_PHP = window.KM_SITE_SERVICES || {};
  const urlOf = (id, fallback) => FROM_PHP[id]?.url ?? fallback;
  const portOf = (id, fallback) => FROM_PHP[id]?.port ?? fallback;

  /**
   * status は 'up' | 'down' | 'unknown'。ここに書いてある 'unknown' は**初期値**で、
   * 読み込み直後に refresh() が ./api/health-check.php の結果で上書きする。
   */
  const SERVICES = [
    {
      id: 'web-http',
      port: portOf('web-http', 8080),
      nameKey: 'svc.webHttp.name',
      roleKey: 'svc.webHttp.role',
      url: urlOf('web-http', `http://${HOST}:8080`),
      icon: 'bi-globe',
      accent: 'text-bg-primary',
      openable: true,
      status: 'unknown',
    },
    {
      id: 'web-https',
      port: portOf('web-https', 9443),
      nameKey: 'svc.webHttps.name',
      roleKey: 'svc.webHttps.role',
      url: urlOf('web-https', `https://${HOST}:9443`),
      icon: 'bi-shield-lock-fill',
      accent: 'text-bg-success',
      openable: true,
      status: 'unknown',
    },
    {
      id: 'phpmyadmin',
      port: portOf('phpmyadmin', 8281),
      nameKey: 'svc.phpmyadmin.name',
      roleKey: 'svc.phpmyadmin.role',
      url: urlOf('phpmyadmin', `https://${HOST}:8281`),
      icon: 'bi-database-gear',
      accent: 'text-bg-info',
      openable: true,
      status: 'unknown',
    },
    {
      id: 'logto-core',
      port: portOf('logto-core', 3001),
      nameKey: 'svc.logtoCore.name',
      roleKey: 'svc.logtoCore.role',
      url: urlOf('logto-core', `https://${HOST}:3001`),
      icon: 'bi-key-fill',
      accent: 'text-bg-warning',
      openable: true,
      status: 'unknown',
    },
    {
      id: 'logto-admin',
      port: portOf('logto-admin', 3002),
      nameKey: 'svc.logtoAdmin.name',
      roleKey: 'svc.logtoAdmin.role',
      url: urlOf('logto-admin', `https://${HOST}:3002`),
      icon: 'bi-sliders',
      accent: 'text-bg-warning',
      openable: true,
      status: 'unknown',
    },
    {
      id: 'soketi',
      port: portOf('soketi', 6001),
      nameKey: 'svc.soketi.name',
      roleKey: 'svc.soketi.role',
      url: urlOf('soketi', `https://${HOST}:6001`),
      icon: 'bi-broadcast',
      accent: 'text-bg-danger',
      openable: true,
      status: 'unknown',
    },
    {
      id: 'mailpit',
      // 監視しているのは SMTP の 1025 (health-check.php 参照)。
      // ここに出す port / url は「人が開く Web 画面」の方なので 8025 で正しい。
      port: portOf('mailpit', 8025),
      /*
       * **本番では Mailpit を起動せず、8025 も publish していない**(2026-09-18)。
       * PHP(km_site_service_map)はそのとき url を null にするので、ここは
       * 「開けない・名前も SMTP の口として出す」に切り替える。押しても繋がらないボタンを残さない。
       */
      nameKey: urlOf('mailpit', null) ? 'svc.mailpit.name' : 'svc.mailSmtp.name',
      roleKey: urlOf('mailpit', null) ? 'svc.mailpit.role' : 'svc.mailSmtp.role',
      url: urlOf('mailpit', null),
      icon: 'bi-envelope-at',
      accent: 'text-bg-primary',
      openable: Boolean(urlOf('mailpit', null)),
      status: 'unknown',
    },
    {
      id: 'mariadb',
      port: portOf('mariadb', 3306),
      nameKey: 'svc.mariadb.name',
      roleKey: 'svc.mariadb.role',
      url: `${HOST}:${portOf('mariadb', 3306)}`,
      icon: 'bi-database-fill',
      accent: 'text-bg-secondary',
      // TCP 直接続なのでブラウザからは開けない
      openable: false,
      status: 'unknown',
    },
  ];

  const STATUS_BADGE = {
    up: 'text-bg-success',
    down: 'text-bg-danger',
    unknown: 'text-bg-secondary',
  };

  const t = (key) => globalThis.KmI18n?.t(key) ?? key;

  const escapeHtml = (value) =>
    String(value).replace(
      /[&<>"']/g,
      (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char],
    );

  const statusBadge = (status) =>
    `<span class="badge ${STATUS_BADGE[status] ?? STATUS_BADGE.unknown}">${escapeHtml(
      t(`svc.status.${status}`),
    )}</span>`;

  // ダッシュボード用: AdminLTE の info-box をそのまま使った一覧
  const compactCard = (service) => `
    <div class="col-12 col-sm-6 col-xl-4">
      <div class="info-box">
        <span class="info-box-icon ${service.accent} shadow-sm">
          <i class="bi ${service.icon}"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">${escapeHtml(t(service.nameKey))}</span>
          <span class="info-box-number">
            ${service.port}
            <small>/ ${escapeHtml(t(service.roleKey))}</small>
          </span>
          <span class="mt-1 d-block">${statusBadge(service.status)}</span>
        </div>
        <!-- /.info-box-content -->
      </div>
      <!-- /.info-box -->
    </div>
  `;

  // 監視ページ用: card で接続先と操作ボタンまで出す
  const detailedCard = (service) => {
    const openButton = service.openable
      ? `<a href="${escapeHtml(service.url)}" class="btn btn-outline-primary btn-sm"
             target="_blank" rel="noopener noreferrer">
           <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>${escapeHtml(t('svc.open'))}
         </a>`
      : `<span class="text-body-secondary fs-7">
           <i class="bi bi-plug me-1" aria-hidden="true"></i>${escapeHtml(t('svc.directConnect'))}
         </span>`;

    return `
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card mb-3 h-100">
          <div class="card-header d-flex align-items-center">
            <span class="info-box-icon km-svc-icon ${service.accent} shadow-sm me-2">
              <i class="bi ${service.icon}"></i>
            </span>
            <h3 class="card-title mb-0">${escapeHtml(t(service.nameKey))}</h3>
            <div class="card-tools ms-auto">${statusBadge(service.status)}</div>
          </div>
          <div class="card-body">
            <dl class="row mb-0 fs-7">
              <dt class="col-4 text-body-secondary">${escapeHtml(t('svc.role'))}</dt>
              <dd class="col-8 mb-2">${escapeHtml(t(service.roleKey))}</dd>
              <dt class="col-4 text-body-secondary">${escapeHtml(t('svc.port'))}</dt>
              <dd class="col-8 mb-2">${service.port}</dd>
              <dt class="col-4 text-body-secondary">${escapeHtml(t('svc.endpoint'))}</dt>
              <dd class="col-8 mb-0"><code class="text-break">${escapeHtml(service.url)}</code></dd>
            </dl>
          </div>
          <div class="card-footer">${openButton}</div>
        </div>
      </div>
    `;
  };

  /*
   * 設定ページ用: 接続先の一覧表(ポートと役割だけ)。
   *
   * 以前は settings.php に7行が直接書いてあり、Mailpit(8025)を足したときに更新し忘れて
   * 実態とずれていた。「定義は services.js にまとまっています」と書いてある footer とも
   * 食い違っていたので、ここから描くようにして二重管理を無くした。
   */
  const endpointRow = (service) => `
    <tr>
      <td><code>${service.port}</code></td>
      <td>${escapeHtml(t(service.roleKey))}</td>
    </tr>
  `;

  // 生成した中身は data-i18n を持たないので、言語切り替え時は作り直す。
  // (i18n.js の apply は既存 DOM しか見ないため)
  const render = () => {
    document.querySelectorAll('[data-km-services="compact"]').forEach((container) => {
      container.innerHTML = SERVICES.map((service) => compactCard(service)).join('');
    });
    document.querySelectorAll('[data-km-services="detailed"]').forEach((container) => {
      container.innerHTML = SERVICES.map((service) => detailedCard(service)).join('');
    });
    document.querySelectorAll('[data-km-endpoints]').forEach((tbody) => {
      tbody.innerHTML = SERVICES.map((service) => endpointRow(service)).join('');
    });
    document.querySelectorAll('input[data-km-services-host]').forEach((input) => {
      input.value = HOST;
    });
    document.querySelectorAll('[data-km-services-count]').forEach((element) => {
      element.textContent = String(SERVICES.length);
    });
  };

  const EVENT_REFRESHED = 'refreshed.km.services';

  let lastCheckedAt = null;
  let refreshing = false;

  /*
   * ---- 通信の上限と、失敗したときの間隔 ----
   *
   * health-check.php は 1 回で 8 サービスへサーバー間の TCP 接続を張る。
   * **応答が返らないまま待ち続けない**(15 秒で打ち切る)し、
   * **落ちているときほど叩く回数を減らす**(失敗が続くと間隔を倍々に延ばす。上限 5 分)。
   * 延ばした間隔には ±20% のゆらぎを入れ、開いている全員が同時に叩き直すのを避ける。
   */
  const FETCH_TIMEOUT_MS = 15000;
  const BACKOFF_MAX_MS = 5 * 60 * 1000;
  let consecutiveFailures = 0;
  // 最後に health-check を出した時刻(端末の時計)。表に戻ったときの叩き直しの判断に使う
  let lastAttemptAt = 0;

  /**
   * タイムアウト付きの fetch(既定 15 秒)。時間切れは AbortError で reject する。
   *
   * **本文の読み取り(read)まで時間に含める。** ヘッダーだけ返って本文が止まると、
   * response.json() が返らず refreshing が true のまま残り、以後の確認がすべて飛ばされるため。
   */
  const fetchWithTimeout = async (url, options = {}, read = (response) => response, timeoutMs = FETCH_TIMEOUT_MS) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(url, { ...options, signal: controller.signal });
      return await read(response);
    } finally {
      clearTimeout(timer);
    }
  };

  /** 次の自動確認までの待ち時間。成功が続いていれば baseMs のまま。 */
  const nextDelay = (baseMs) => {
    if (consecutiveFailures === 0) {
      return baseMs;
    }
    const grown = Math.min(baseMs * 2 ** consecutiveFailures, BACKOFF_MAX_MS);
    return Math.round(grown * (0.8 + Math.random() * 0.4));
  };

  /**
   * ./api/health-check.php を叩いて SERVICES[].status を書き戻す。
   * 9443 の PHP がサーバー間で各ポートへ TCP 接続を試すだけなので、ブラウザの
   * 自己署名証明書・CORS の制約を受けない(services.js 冒頭のコメント参照)。
   * 連打防止のため、実行中の呼び出しは無視する。
   */
  /**
   * 死活確認の結果を取り込んで描き直す。
   *
   * 自分で叩いた結果(refresh)と、他の人が叩いた結果を Soketi 経由で受け取った場合
   * (monitor.php が呼ぶ)の両方でここを通す。取り込み方を1箇所にしておく。
   */
  const applyStatuses = (data) => {
    SERVICES.forEach((service) => {
      const status = data?.[service.id];
      service.status = status === 'up' || status === 'down' ? status : 'unknown';
    });
    lastCheckedAt = data?.checkedAt ? new Date(data.checkedAt) : new Date();
    render();
    document.dispatchEvent(new CustomEvent(EVENT_REFRESHED, { detail: { at: lastCheckedAt } }));
  };

  /** 成功したら true。実行中で飛ばしたときは null(失敗の回数には数えない)。 */
  const refresh = async () => {
    if (refreshing) {
      return null;
    }
    refreshing = true;
    lastAttemptAt = Date.now();
    let ok = false;
    try {
      const data = await fetchWithTimeout('./api/health-check.php', { cache: 'no-store' }, (response) => {
        if (!response.ok) {
          throw new Error(`health-check.php: HTTP ${response.status}`);
        }
        return response.json();
      });
      SERVICES.forEach((service) => {
        const status = data[service.id];
        service.status = status === 'up' || status === 'down' ? status : 'unknown';
      });
      lastCheckedAt = data.checkedAt ? new Date(data.checkedAt) : new Date();
      ok = true;
      consecutiveFailures = 0;
    } catch (error) {
      // 取得できないときは前回の up/down を出し続けず unknown に戻す(古い情報の誤表示防止)
      console.warn('[km-services] health-check failed:', error);
      consecutiveFailures += 1;
      SERVICES.forEach((service) => {
        service.status = 'unknown';
      });
    } finally {
      refreshing = false;
      render();
      document.dispatchEvent(new CustomEvent(EVENT_REFRESHED, { detail: { at: lastCheckedAt } }));
    }
    return ok;
  };

  /*
   * ---- 自動確認 ----
   *
   * 呼ぶ側(monitor.php)が setInterval で refresh() を回すと、失敗が続いても
   * 同じ間隔で叩き続ける。ここで持つ:
   *   - **前回が終わってから**次を予約する(setTimeout の数珠つなぎ。重ならない)
   *   - 失敗が続くと nextDelay() で間隔を延ばす
   *   - **タブが見えていない間は予約しない**。表に戻ったら1回すぐ確認して追いつく
   */
  let autoBaseMs = 0;
  let autoTimer = null;

  const clearAutoTimer = () => {
    if (autoTimer !== null) {
      clearTimeout(autoTimer);
      autoTimer = null;
    }
  };

  const scheduleAuto = () => {
    clearAutoTimer();
    if (!autoBaseMs || document.hidden) {
      return;
    }
    autoTimer = setTimeout(async () => {
      autoTimer = null;
      if (!autoBaseMs || document.hidden) {
        return;
      }
      await refresh();
      scheduleAuto();
    }, nextDelay(autoBaseMs));
  };

  /** 自動確認を始める。間隔は 5 秒未満にしない。 */
  const startAuto = (intervalMs = 30000) => {
    autoBaseMs = Math.max(5000, Number(intervalMs) || 30000);
    scheduleAuto();
  };

  const stopAuto = () => {
    autoBaseMs = 0;
    clearAutoTimer();
  };

  document.addEventListener('visibilitychange', () => {
    if (!autoBaseMs) {
      return;
    }
    if (document.hidden) {
      clearAutoTimer();
      return;
    }
    /*
     * 見ていない間のぶんを取り戻す。ただし、失敗が続いているときは待ち時間を守り、
     * **直前(基本の間隔より前)に確かめたばかりなら叩かない**(タブを行き来するたびに
     * health-check が出るのを防ぐ)。
     */
    if (consecutiveFailures === 0 && Date.now() - lastAttemptAt >= autoBaseMs) {
      refresh().then(scheduleAuto);
    } else {
      scheduleAuto();
    }
  });

  globalThis.KmServices = {
    host: HOST,
    all: SERVICES,
    render,
    refresh,
    // 自動確認(失敗時は間隔を延ばす・非表示の間は止める・重ならない)。monitor.php から使う
    startAuto,
    stopAuto,
    // 他の人が調べた結果を Soketi 経由で受け取ったときに使う(monitor.php)
    applyStatuses,
    event: EVENT_REFRESHED,
    get lastCheckedAt() {
      return lastCheckedAt;
    },
    get refreshing() {
      return refreshing;
    },
  };

  const boot = () => {
    render();
    document.addEventListener(globalThis.KmI18n?.event ?? 'changed.km.lang', render);
    if (document.querySelector('[data-km-services]')) {
      refresh();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
