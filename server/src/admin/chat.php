<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';

$soketiAppKey = getenv('SOKETI_APP_KEY') ?: null;

$KM_PAGE = [
    'nav' => 'chat',
    'titleKey' => 'page.chat.title',
    'title' => 'チャット | KosenMap 管理',
    'h1Key' => 'page.chat.h1',
    'h1' => 'チャット',
    'crumbs' => [
        ['key' => 'side.extra', 'text' => 'その他ページ'],
        ['key' => 'page.chat.h1', 'text' => 'チャット'],
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
            <?php if ($soketiAppKey === null): ?>
              <div class="alert alert-warning d-flex align-items-start" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2 mt-1" aria-hidden="true"></i>
                <div data-i18n="page.chat.noticeNoKey">
                  SOKETI_APP_KEY が設定されていません。サーバーの環境変数を確認してください。
                </div>
              </div>
            <?php endif; ?>

            <!--begin::Card-->
            <div class="card">
              <div class="card-header d-flex align-items-center gap-2">
                <h3 class="card-title mb-0" data-i18n="page.chat.cardTitle">管理者チャット</h3>
                <span id="km-chat-status" class="badge text-bg-secondary ms-auto" data-i18n="page.chat.connecting">接続中...</span>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <div id="km-chat-messages" class="direct-chat-messages km-chat-log">
                  <p class="text-body-secondary text-center fs-7 mt-5" data-i18n="page.chat.empty">
                    まだメッセージがありません。
                  </p>
                </div>
                <!-- /.direct-chat-messages -->
              </div>
              <!-- /.card-body -->
              <div class="card-footer">
                <div class="input-group">
                  <input
                    type="text"
                    id="km-chat-input"
                    class="form-control"
                    placeholder="メッセージを入力..."
                    aria-label="メッセージを入力"
                    data-i18n-attr="placeholder:page.chat.placeholder;aria-label:page.chat.placeholder"
                    maxlength="1000"
                  />
                  <button type="button" id="km-chat-send" class="btn btn-primary">
                    <i class="bi bi-send" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
            </div>
            <!--end::Card-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <script src="./vendor/pusher-js/pusher.min.js"></script>
        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            <?php // <script> の中へ書くので、</script> や & を値に持ち込ませない(index.php と揃える) ?>
            const appKey = <?= json_encode($soketiAppKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const currentUserId = <?= json_encode($KM_USER['sub'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const statusBadge = document.getElementById('km-chat-status');
            const messagesEl = document.getElementById('km-chat-messages');
            const input = document.getElementById('km-chat-input');
            const sendBtn = document.getElementById('km-chat-send');

            const setStatus = (key, text, badgeClass) => {
              statusBadge.className = 'badge ' + badgeClass + ' ms-auto';
              statusBadge.textContent = window.KmI18n ? window.KmI18n.t(key) : text;
              statusBadge.dataset.i18n = key;
            };

            if (typeof Pusher === 'undefined') {
              setStatus('page.chat.libMissing', 'ライブラリ未配置', 'text-bg-danger');
              return;
            }
            if (!appKey) {
              setStatus('page.chat.noKey', '設定不備', 'text-bg-danger');
              return;
            }

            const escapeHtml = (value) =>
              String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

            // 履歴(chat-history.php)は sentAtEpoch(秒)、リアルタイム受信は sentAt
            // (PHP の DATE_ATOM = オフセット付き ISO8601)で来る。前者を素の datetime 文字列で
            // 渡していた頃は new Date() がブラウザのローカル時刻として解釈してしまい、
            // 履歴だけ9時間ずれていた。epoch は絶対時刻なのでその曖昧さが無い。
            const resolveSentAt = (data) => {
              if (data.sentAtEpoch !== undefined && data.sentAtEpoch !== null) {
                return new Date(Number(data.sentAtEpoch) * 1000);
              }
              return data.sentAt ? new Date(data.sentAt) : null;
            };

            /*
             * 発言者のプロフィール画像。持っているのは senderId(Logto の sub)だけなので、
             * api/avatar.php にそのまま渡す。**設定していない人でも既定の画像が返る**ので、
             * 「この人は画像を持っているか」を先に調べる必要が無い(壊れた画像も出ない)。
             */
            const avatarFor = (senderId) =>
              './api/avatar.php?user=' + encodeURIComponent(String(senderId ?? ''));

            /*
             * 既読の管理。
             *
             * サーバーは利用者ごとに「最後に読んだメッセージ id」1つだけを持つ。
             * 自分の発言に付ける「既読 N」は、**その id 以上を読んだ人の数**(自分を除く)。
             * reads は userId => lastReadId。
             */
            const reads = new Map();
            let latestId = 0;

            const readCountFor = (messageId) => {
              let n = 0;
              reads.forEach((lastReadId, userId) => {
                if (userId !== currentUserId && lastReadId >= messageId) {
                  n++;
                }
              });
              return n;
            };

            // 訳が無いキーは KmI18n.t() がキーそのものを返すので、そのときは原文に落とす
            const t = (key, fallback) => {
              const value = window.KmI18n?.t(key);
              return value && value !== key ? value : fallback;
            };

            /*
             * ---- 通信の上限と、混み合っているとき(2026-09-14)----
             *
             * **応答が返らないまま待ち続けない。** 本文の読み取りまで含めて 15 秒で打ち切る
             * (ヘッダーだけ返って本文が止まると res.json() が返らず、送信ボタンが
             * 押せないまま残るため。services.js の fetchWithTimeout と同じ作法)。
             * 429(nginx の回数制限)は、すぐ叩き直さず少し待つ。
             */
            const FETCH_TIMEOUT_MS = 15000;
            const csrfToken = () => document.querySelector('meta[name="km-csrf"]')?.content ?? '';

            // Retry-After(秒)があればそれに従う。無ければ数秒。1〜60 秒に収め、ゆらぎを足す
            const waitAfter429 = (res) => {
              const seconds = Number(res.headers.get('Retry-After'));
              const base = Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : 5000;
              return Math.min(Math.max(base, 1000), 60000) + Math.round(Math.random() * 1000);
            };

            /** 時間切れは AbortError で reject する。JSON でない応答は body を null にして返す。 */
            const fetchJson = async (url, options = {}) => {
              const controller = new AbortController();
              const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
              try {
                const res = await fetch(url, { ...options, signal: controller.signal });
                let body = null;
                try {
                  body = await res.json();
                } catch (error) {
                  if (error?.name === 'AbortError') throw error;
                }
                return { res, body };
              } finally {
                clearTimeout(timer);
              }
            };

            // 既読が増えるのは「誰かが読んだとき」だけなので、自分の吹き出しの表示だけ貼り替える
            const refreshReadLabels = () => {
              messagesEl.querySelectorAll('[data-km-mine-id]').forEach((el) => {
                const n = readCountFor(Number(el.dataset.kmMineId));
                el.textContent = n > 0 ? `${t('page.chat.readBy', '既読')} ${n}` : '';
              });
            };

            let firstMessage = true;
            const appendMessage = (data, isMine) => {
              if (firstMessage) {
                messagesEl.innerHTML = '';
                firstMessage = false;
              }
              const id = Number(data.id ?? 0);
              if (id > latestId) {
                latestId = id;
              }
              const sentAt = resolveSentAt(data);
              const time = sentAt && !Number.isNaN(sentAt.getTime()) ? sentAt.toLocaleTimeString() : '';
              const row = document.createElement('div');
              row.className = 'direct-chat-msg' + (isMine ? ' right' : '');
              row.innerHTML = `
                <div class="direct-chat-infos clearfix">
                  <span class="direct-chat-name float-${isMine ? 'end' : 'start'}">${escapeHtml(data.senderName)}</span>
                  <span class="direct-chat-timestamp float-${isMine ? 'start' : 'end'}">${escapeHtml(time)}</span>
                </div>
                <img class="direct-chat-img km-avatar-img" src="${escapeHtml(avatarFor(data.senderId))}" alt="" />
                <div class="direct-chat-text">${escapeHtml(data.message)}</div>
                ${isMine && id > 0 ? `<div class="text-body-secondary fs-7 text-end" data-km-mine-id="${id}"></div>` : ''}
              `;
              messagesEl.appendChild(row);
              messagesEl.scrollTop = messagesEl.scrollHeight;
            };

            /*
             * 「ここまで読んだ」をサーバーへ送る。
             *
             * **画面が見えているときだけ**送る。裏に置いたタブが既読を付けてしまうと、
             * 相手には読まれたように見えるのに実際は誰も見ていない、という嘘になる。
             * 同じ id を何度も送らないよう、送った位置を覚えておく。
             */
            let sentReadId = 0;
            // 送っている最中 / 429 で待っている間は重ねて送らない。待ち明けに1回だけ送り直す
            let readInFlight = false;
            let readRetryTimer = null;
            const markRead = async () => {
              if (readInFlight || readRetryTimer !== null) {
                return;
              }
              if (document.visibilityState !== 'visible' || latestId <= sentReadId) {
                return;
              }
              const target = latestId;
              readInFlight = true;
              try {
                const { res, body } = await fetchJson('./api/chat-read.php', {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-KM-CSRF': csrfToken(),
                  },
                  body: JSON.stringify({ lastReadId: target }),
                });
                if (res.status === 429) {
                  readRetryTimer = setTimeout(() => {
                    readRetryTimer = null;
                    markRead();
                  }, waitAfter429(res));
                  return;
                }
                if (!res.ok || !body?.success) return;
                sentReadId = target;
                reads.set(currentUserId, target);
                // サイドバーの未読バッジをその場で消す(次の画面遷移を待たない)
                document.querySelectorAll('[data-km-chat-unread]').forEach((el) => {
                  const n = Number(body.unread ?? 0);
                  el.textContent = String(n);
                  el.classList.toggle('d-none', n === 0);
                });
              } catch {
                // 既読が付かなくても会話自体は続けられる(時間切れも含む)。次の機会に送り直される
              } finally {
                readInFlight = false;
              }
            };

            document.addEventListener('visibilitychange', markRead);

            // 履歴を先に描画してから購読を始める(この順序が逆だと、購読直後に届いた
            // メッセージと履歴の間で二重表示・抜けが起きうる)。
            const connectRealtime = () => {
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

              pusher.connection.bind('connected', () => setStatus('page.chat.connected', '接続済み', 'text-bg-success'));
              pusher.connection.bind('unavailable', () => setStatus('page.chat.disconnected', '未接続', 'text-bg-danger'));
              pusher.connection.bind('failed', () => setStatus('page.chat.disconnected', '未接続', 'text-bg-danger'));

              const channel = pusher.subscribe('presence-admin-chat');
              channel.bind('pusher:subscription_error', () => setStatus('page.chat.disconnected', '未接続', 'text-bg-danger'));
              channel.bind('message', (data) => {
                appendMessage(data, data.senderId === currentUserId);
                refreshReadLabels();
                markRead();   // 画面を見ている人はその場で既読になる
              });
              // 誰かが読んだら、自分の吹き出しの「既読 N」がその場で増える
              channel.bind('read', (data) => {
                reads.set(String(data.userId), Number(data.lastReadId ?? 0));
                refreshReadLabels();
              });
            };

            /*
             * 履歴を読む。429 のときだけ少し待って読み直す(最大 3 回)。
             * 時間切れ・それ以外の失敗は読み直さない —— **履歴が取れなくても実況は始める**。
             */
            const HISTORY_TRIES = 3;
            const loadHistory = async () => {
              for (let attempt = 1; attempt <= HISTORY_TRIES; attempt++) {
                const { res, body } = await fetchJson('./api/chat-history.php');
                if (res.status === 429 && attempt < HISTORY_TRIES) {
                  await new Promise((resolve) => setTimeout(resolve, waitAfter429(res)));
                  continue;
                }
                if (!res.ok || !body) {
                  return;
                }
                (body.reads || []).forEach((r) => reads.set(String(r.userId), Number(r.lastReadId)));
                (body.messages || []).forEach((data) => appendMessage(data, data.senderId === currentUserId));
                refreshReadLabels();
                markRead();
                return;
              }
            };

            loadHistory()
              .catch(() => {
                // 履歴が取れなくても実況(リアルタイム受信)は始める。
              })
              .finally(connectRealtime);

            const send = async () => {
              const message = input.value.trim();
              if (!message || sendBtn.disabled) return;
              sendBtn.disabled = true;
              // 429 のときは、待ち時間が過ぎるまで送信ボタンを戻さない(連打で制限を延ばさない)
              let reenableAfterMs = 0;
              try {
                const { res, body } = await fetchJson('./api/chat-send.php', {
                  method: 'POST',
                  headers: { 'X-KM-CSRF': csrfToken() },
                  body: JSON.stringify({ message }),
                });
                if (res.status === 429) {
                  reenableAfterMs = waitAfter429(res);
                  // 自動では送り直さない(届いていた場合に二重に投稿されるため)。入力は残す
                  alert(t('page.chat.busy', '送信が混み合っています。少し待ってからもう一度送ってください。'));
                } else if (body?.success) {
                  input.value = '';
                } else {
                  alert(body?.error || t('page.chat.sendError', 'エラーが発生しました。'));
                }
              } catch (error) {
                alert(error?.name === 'AbortError'
                  // 届いている場合もあるので、送り直す前に画面で確かめてもらう
                  ? t('page.chat.timeout', '応答がありませんでした。届いていることもあるので、画面を確かめてから送り直してください。')
                  : t('page.chat.sendError', '通信エラーが発生しました。'));
              } finally {
                setTimeout(() => {
                  sendBtn.disabled = false;
                  input.focus();
                }, reenableAfterMs);
              }
            };

            sendBtn.addEventListener('click', send);
            input.addEventListener('keydown', (event) => {
              if (event.key === 'Enter') send();
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
