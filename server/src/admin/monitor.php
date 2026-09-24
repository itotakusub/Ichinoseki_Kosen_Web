<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';

// リアルタイム更新用。秘密ではない(SOKETI_APP_SECRET はサーバー側にしか出さない)
$soketiAppKey = getenv('SOKETI_APP_KEY') ?: null;

$KM_PAGE = [
    'nav' => 'monitor',
    'titleKey' => 'page.monitor.title',
    'title' => 'サービス監視 | KosenMap 管理',
    'h1Key' => 'page.monitor.h1',
    'h1' => 'サービス監視',
    'crumbs' => [
        ['key' => 'page.monitor.h1', 'text' => 'サービス監視'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Notice-->
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div>
                <strong data-i18n="page.monitor.noticeTitle">サーバー間で疎通確認しています</strong>
                <div data-i18n-html="page.monitor.noticeText">
                  ブラウザから 8281 / 3001 / 3002 / 6001 を直接叩くと自己署名証明書と CORS で
                  必ず失敗するため、9443 の PHP(<code>admin/api/health-check.php</code>)が
                  サーバー間で各ポートへ TCP 接続できるかどうかだけを見ています。ページ読み込み時と
                  「状態を更新」ボタンで再確認します。自動更新を入れると30秒ごとに繰り返しますが、
                  このタブを見ていない間は止まります。
                </div>
              </div>
            </div>
            <!--end::Notice-->

            <!--begin::Toolbar-->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
              <button type="button" class="btn btn-outline-primary btn-sm" id="km-monitor-refresh">
                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
                <span data-i18n="page.monitor.refresh">状態を更新</span>
              </button>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="km-monitor-auto" />
                <label class="form-check-label fs-7" for="km-monitor-auto" data-i18n="page.monitor.auto">
                  30秒ごとに自動更新
                </label>
              </div>
              <span class="text-body-secondary fs-7">
                <span data-i18n="page.monitor.lastCheck">最終確認</span>:
                <strong id="km-monitor-last-checked" data-i18n="page.monitor.never">未実施</strong>
              </span>
              <span class="text-body-secondary fs-7 ms-auto">
                <i class="bi bi-broadcast me-1" aria-hidden="true"></i>
                <span data-i18n="page.monitor.realtime">リアルタイム更新</span>:
                <?php // 接続状態は JS が書き替える。ここは接続前の初期表示 ?>
                <span class="badge text-bg-secondary" id="km-monitor-realtime" data-i18n="page.monitor.realtimeOff"
                  >未接続</span
                >
              </span>
            </div>
            <!--end::Toolbar-->

            <!--begin::Row-->
            <!-- services.js が生成する。言語切り替え時は作り直される -->
            <div class="row" data-km-services="detailed"></div>
            <!--end::Row-->

            <!--begin::Row-->
            <div class="row">
              <div class="col-12">
                <!--begin::Card-->
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title" data-i18n="page.monitor.planTitle">今後の拡張</h3>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item d-flex align-items-start text-body-secondary">
                        <i class="bi bi-check-circle-fill me-2 mt-1 text-success" aria-hidden="true"></i>
                        <span data-i18n="page.monitor.plan1"
                          >9443 の PHP に死活監視用エンドポイントを追加し、各サービスへサーバー間で
                          接続する(実装済み)</span
                        >
                      </li>
                      <li class="list-group-item d-flex align-items-start text-body-secondary">
                        <i class="bi bi-check-circle-fill me-2 mt-1 text-success" aria-hidden="true"></i>
                        <span data-i18n="page.monitor.plan2"
                          >MariaDB は HTTP ではないため、TCP 接続の可否で判定する(実装済み)</span
                        >
                      </li>
                      <li class="list-group-item d-flex align-items-start text-body-secondary">
                        <i class="bi bi-check-circle-fill me-2 mt-1 text-success" aria-hidden="true"></i>
                        <span data-i18n="page.monitor.plan3"
                          >誰かが確認した結果を Soketi で全員へ配る(実装済み)。定期実行の仕組みは
                          作らず、自動更新を入れた人がそのまま配信役になる</span
                        >
                      </li>
                    </ul>
                  </div>
                  <!-- /.card-body -->
                </div>
                <!--end::Card-->
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script src="./vendor/pusher-js/pusher.min.js"></script>
        <script<?= km_csp_nonce_attr() ?>>
          /*
           * 死活監視のリアルタイム更新(フェーズ16)。
           *
           * **自分では調べない。** 誰かが api/health-check.php を叩くと、その結果が
           * Soketi へ流れてくるので、それを取り込むだけ。監視ページを開いている人が
           * 自動更新を入れていれば、その1人が全員ぶんの配信役になる。
           * 定期実行の仕組みを別に用意せずに済み、タブが増えても検査回数は増えない。
           */
          document.addEventListener('DOMContentLoaded', () => {
            <?php // <script> の中へ書くので HEX 系のフラグを付ける(chat.php と揃える) ?>
            const appKey = <?= json_encode($soketiAppKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const badge = document.getElementById('km-monitor-realtime');
            if (!badge) return;

            const setBadge = (key, text, cls) => {
              badge.className = 'badge ' + cls;
              badge.dataset.i18n = key;
              badge.textContent = window.KmI18n ? window.KmI18n.t(key) : text;
            };

            if (typeof Pusher === 'undefined' || !appKey) {
              // pusher-js 未配置 / キー未設定。ポーリングだけで動き続けるので画面は壊れない
              return;
            }

            const pusher = new Pusher(appKey, {
              cluster: 'mt1',
              wsHost: <?= json_encode(km_site_ws_host(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
              wsPort: 6001,
              wssPort: 6001,
              forceTLS: true,
              enabledTransports: ['ws', 'wss'],
              disableStats: true,
              authEndpoint: './api/chat-auth.php',
              // 認可の POST は pusher-js 自身が投げるので、CSRF トークンはここで載せる
              auth: {
                headers: { 'X-KM-CSRF': document.querySelector('meta[name="km-csrf"]')?.content ?? '' },
              },
            });

            pusher.connection.bind('connected', () => setBadge('page.monitor.realtimeOn', '接続済み', 'text-bg-success'));
            pusher.connection.bind('unavailable', () => setBadge('page.monitor.realtimeOff', '未接続', 'text-bg-secondary'));
            pusher.connection.bind('failed', () => setBadge('page.monitor.realtimeOff', '未接続', 'text-bg-secondary'));

            const channel = pusher.subscribe('presence-admin-monitor');
            channel.bind('pusher:subscription_error', () => setBadge('page.monitor.realtimeOff', '未接続', 'text-bg-secondary'));
            channel.bind('health', (data) => {
              window.KmServices?.applyStatuses(data);
            });
          });
        </script>
        <script<?= km_csp_nonce_attr() ?>>
          // services.js は footer.php 側で後から読み込まれるため、window.KmServices の
          // オブジェクト自体はスクリプト実行時点(パース中)には既に代入済みだが、念のため
          // DOMContentLoaded まで待ってから触る(services.js 自身の boot() も同じ流儀)。
          document.addEventListener('DOMContentLoaded', () => {
            const button = document.getElementById('km-monitor-refresh');
            const lastChecked = document.getElementById('km-monitor-last-checked');

            const formatTime = (date) => {
              if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
                return '';
              }
              return date.toLocaleString(window.KmI18n?.current === 'en' ? 'en-US' : 'ja-JP');
            };

            const applyLastChecked = (date) => {
              const formatted = formatTime(date);
              if (!formatted || !lastChecked) {
                return;
              }
              // 一度実際の時刻を入れたら、以後の言語切り替えで「未実施」に戻されないよう外す
              lastChecked.removeAttribute('data-i18n');
              lastChecked.textContent = formatted;
            };

            document.addEventListener(window.KmServices?.event ?? 'refreshed.km.services', (event) => {
              applyLastChecked(event.detail?.at ?? null);
              if (button) {
                button.disabled = false;
              }
            });

            button?.addEventListener('click', () => {
              button.disabled = true;
              window.KmServices?.refresh();
            });

            if (window.KmServices?.lastCheckedAt) {
              applyLastChecked(window.KmServices.lastCheckedAt);
            }

            /*
             * 自動更新。
             *
             * 1回の更新で 8 サービスへサーバー間 TCP 接続を張るので、垂れ流しにはしない。
             * **間隔の管理は services.js の startAuto() / stopAuto() に任せる**(2026-09-14)。
             * 以前はここで setInterval から refresh() を呼んでいたため、失敗が続いても
             * 同じ間隔で叩き続けていた。services.js 側が持つもの:
             *   - 前回が終わってから次を予約する(重ならない。手動ボタンとも二重に飛ばない)
             *   - 失敗が続くと間隔を倍々に延ばす(上限 5 分、±20% のゆらぎ)
             *   - タブが見えていない間は止め、表に戻ったら追いつく
             *     (visibilitychange は services.js が見ているので、ここでは扱わない)
             * ここに残すのは「入れた人にだけ動く」(既定は切、このブラウザに覚える)ことだけ。
             */
            const auto = document.getElementById('km-monitor-auto');
            const STORAGE_KEY = 'kmadmin-monitor-auto';
            const INTERVAL_MS = 30000;

            // localStorage は設定や閲覧モードによって例外を投げる。覚えられなくても画面は動かす
            const load = () => {
              try {
                return localStorage.getItem(STORAGE_KEY) === '1';
              } catch {
                return false;
              }
            };
            const save = (enabled) => {
              try {
                localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0');
              } catch {
                // 覚えられないだけ。次に開いたときは既定の「切」に戻る
              }
            };

            const apply = (enabled) => {
              save(enabled);
              if (enabled) {
                window.KmServices?.startAuto(INTERVAL_MS);
              } else {
                window.KmServices?.stopAuto();
              }
            };

            if (!auto) {
              return;
            }
            auto.checked = load();
            apply(auto.checked);

            auto.addEventListener('change', () => apply(auto.checked));
          });
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>