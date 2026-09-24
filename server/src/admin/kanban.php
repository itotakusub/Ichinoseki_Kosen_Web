<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/tasks.php';

$KM_PAGE = [
    'nav' => 'kanban',
    'titleKey' => 'page.kanban.title',
    'title' => 'カンバン | KosenMap 管理',
    'h1Key' => 'page.kanban.h1',
    'h1' => 'カンバン',
    'crumbs' => [
        ['key' => 'side.extra', 'text' => 'その他ページ'],
        ['key' => 'page.kanban.h1', 'text' => 'カンバン'],
    ],
];

/*
 * projects.php と同じ km_tasks を見ている。こちらはレーン(status)に並べる表示。
 * 「要判断」はボードに列を作らない方針なので、todo / progress / done の3レーンだけ出す
 * (decision のタスクは projects.php 側で扱う)。
 */
$tasksByLane = array_fill_keys(KM_TASK_LANES, []);
$dbError = null;
try {
    foreach (km_tasks_all(km_db()) as $task) {
        $lane = (string) $task['status'];
        if (isset($tasksByLane[$lane])) {
            $tasksByLane[$lane][] = $task;
        }
    }
} catch (Throwable $exception) {
    error_log('kanban.php failed: ' . $exception->getMessage());
    $dbError = $exception->getMessage();
}

$laneLabels = [
    'todo' => ['key' => 'page.kanban.todo', 'text' => '未着手'],
    'progress' => ['key' => 'page.kanban.inProgress', 'text' => '進行中'],
    'done' => ['key' => 'page.kanban.done', 'text' => '完了'],
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
            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-info-circle-fill me-2 mt-1" aria-hidden="true"></i>
              <div data-i18n="page.kanban.notice">
                外部のドラッグ&ドロップ用ライブラリは使わず、ブラウザ標準の Drag and Drop API だけで
                動いています。移動するとその場で保存されます(プロジェクト状況の画面と同じデータです)。
              </div>
            </div>

            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert" data-i18n="page.kanban.dbError">
                データベースに接続できないため、カードを表示できません。
              </div>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-2 mb-3">
              <a href="./projects.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list-check me-1" aria-hidden="true"></i>
                <span data-i18n="page.kanban.openProjects">一覧で編集する</span>
              </a>
              <span id="km-kanban-status" class="text-body-secondary fs-7"></span>
            </div>

            <div class="row" id="km-kanban">
              <?php foreach (KM_TASK_LANES as $lane): ?>
                <div class="col-md-4">
                  <div class="card">
                    <div class="card-header">
                      <h3 class="card-title" data-i18n="<?= km_e($laneLabels[$lane]['key']) ?>">
                        <?= km_e($laneLabels[$lane]['text']) ?>
                      </h3>
                    </div>
                    <div class="card-body km-kanban-lane" data-km-lane="<?= km_e($lane) ?>" >
                      <?php foreach ($tasksByLane[$lane] as $task): ?>
                        <?php // タイトルは利用者が入れた自由文なので data-i18n は付けない ?>
                        <div class="card mb-2 p-2" draggable="true" data-km-task-id="<?= (int) $task['id'] ?>">
                          <?= km_e((string) $task['title']) ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->

        <!--begin::Kanban Drag and Drop (no external library)-->
        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            const board = document.getElementById('km-kanban');
            if (!board) return;
            const statusEl = document.getElementById('km-kanban-status');
            let dragged = null;

            // 訳が無いキーは KmI18n.t() がキーそのものを返すので、そのときは原文に落とす
            const t = (key, fallback) => {
              const value = window.KmI18n?.t(key);
              return value && value !== key ? value : fallback;
            };

            /*
             * ---- 通信の上限と、混み合っているとき ----
             *
             * **応答が返らないまま「保存中...」で止めない。** 本文の読み取りまで含めて 15 秒で打ち切る
             * (ヘッダーだけ返って本文が止まると res.json() が返らないため。services.js と同じ作法)。
             * 429(nginx の回数制限)は、少し待ってから**そのときの並び**で送り直す。
             * 並びをそのまま送る作りなので、送り直しても結果は同じになる。
             */
            const FETCH_TIMEOUT_MS = 15000;
            const RETRY_MAX = 3;
            const retryTimers = new Map();   // レーン => 送り直しの予約

            // Retry-After(秒)があればそれに従う。無ければ数秒。1〜60 秒に収め、ゆらぎを足す
            const waitAfter429 = (res) => {
              const seconds = Number(res.headers.get('Retry-After'));
              const base = Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : 5000;
              return Math.min(Math.max(base, 1000), 60000) + Math.round(Math.random() * 1000);
            };

            const postWithTimeout = async (url, payload) => {
              const controller = new AbortController();
              const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
              try {
                const res = await fetch(url, {
                  method: 'POST',
                  headers: { 'X-KM-CSRF': document.querySelector('meta[name="km-csrf"]')?.content ?? '' },
                  body: JSON.stringify(payload),
                  signal: controller.signal,
                });
                let body = null;
                try {
                  body = await res.json();
                } catch (error) {
                  if (error?.name === 'AbortError') throw error;
                  // JSON でない応答(502 の HTML など)は body なしで扱う
                }
                return { res, body };
              } finally {
                clearTimeout(timer);
              }
            };

            // 移動したレーンの中身を、表示されている順にそのまま送る。
            // サーバー側は受け取った順を sort_order にするので、画面と保存内容が必ず一致する。
            const persist = async (lane, attempt = 0) => {
              clearTimeout(retryTimers.get(lane));
              retryTimers.delete(lane);

              const ids = Array.from(lane.querySelectorAll('[data-km-task-id]'))
                .map((el) => Number(el.dataset.kmTaskId));

              statusEl.textContent = t('page.kanban.saving', '保存中...');
              try {
                const { res, body } = await postWithTimeout('./api/task-move.php', { status: lane.dataset.kmLane, ids });
                if (res.status === 429) {
                  if (attempt + 1 >= RETRY_MAX) {
                    statusEl.textContent = t('page.kanban.busy', '混み合っていて保存できませんでした。少し待ってからもう一度動かしてください');
                    return;
                  }
                  statusEl.textContent = t('page.kanban.busyRetry', '混み合っています。少し待ってから保存し直します...');
                  retryTimers.set(lane, setTimeout(() => persist(lane, attempt + 1), waitAfter429(res)));
                  return;
                }
                statusEl.textContent = body?.success
                  ? t('page.kanban.saved', '保存しました')
                  : (body?.error || t('page.kanban.saveError', '保存に失敗しました'));
              } catch (error) {
                statusEl.textContent = error?.name === 'AbortError'
                  // 届いている場合もあるので「失敗」とは言い切らない
                  ? t('page.kanban.timeout', '応答がありませんでした。保存されたか分からないので、ページを読み込み直して確かめてください')
                  : t('page.kanban.saveError', '保存に失敗しました');
              }
            };

            // カードは後から増えないので直接束ねてよいが、イベント委譲にしておけば
            // 将来カードを動的に足しても付け直しが要らない。
            board.addEventListener('dragstart', (event) => {
              const card = event.target.closest('[data-km-task-id]');
              if (!card) return;
              dragged = card;
              card.style.opacity = '0.4';
            });

            board.addEventListener('dragend', (event) => {
              const card = event.target.closest('[data-km-task-id]');
              if (card) card.style.opacity = '';
            });

            board.querySelectorAll('.km-kanban-lane').forEach((lane) => {
              lane.addEventListener('dragover', (event) => {
                event.preventDefault();
              });
              lane.addEventListener('drop', (event) => {
                event.preventDefault();
                if (!dragged) return;
                lane.appendChild(dragged);
                persist(lane);
                dragged = null;
              });
            });
          })();
        </script>
        <!--end::Kanban Drag and Drop-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
