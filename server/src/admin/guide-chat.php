<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/guide-chat.php';
require_once dirname(__DIR__) . '/lib/test-mode.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';

/**
 * 案内チャットの設定(2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」。中身は lib/guide-chat.php)。
 *
 * - ON/OFF(既定 OFF)
 * - 使える人: テスト用アカウント(管理画面「テストモード」で印を付けた Logto のアカウント)から 1 つ(企画書 §8「テストユーザー1のみ」)
 * - 1 日の上限(企画書 §8「LLM の API を無制限に呼び出せないようにする」)
 * - LLM に届くかの確かめ(自宅 PC の Unsloth / Bionic + ngrok。URL と鍵は環境変数で、ここには出さない)
 * - 模型(AI)の選択(2026-10-29、利用者「AI を選択できるように」。自宅 PC の門番の一覧から。変えたら門番が読み込む)
 * - 日ごとの回数
 * - 質問と答えと評価の一覧(2026-10-25、利用者「機能改善のために保存」「評価も付けられるようにし、BAD だったら理由を聞く」。
 *   消さずに残す。誰が聞いたかは残していない)。BAD だけに絞れる。まとめて消せる(記録に残す)
 */

$KM_PAGE = [
    'nav' => 'guideChat',
    'titleKey' => 'page.guideChat.title',
    'title' => '案内チャット | KosenMap 管理',
    'h1Key' => 'page.guideChat.h1',
    'h1' => '案内チャット',
    'crumbs' => [
        ['key' => 'side.content', 'text' => 'コンテンツ'],
        ['key' => 'page.guideChat.h1', 'text' => '案内チャット'],
    ],
];

$errors = [];
$dbError = null;
$settings = ['enabled' => false, 'sub' => '', 'dailyLimit' => KM_GUIDE_DAILY_DEFAULT, 'reasoning' => 'auto', 'model' => ''];
/*
 * 自宅 PC の模型の一覧(2026-10-29、利用者「AI を選択できるように」)。門番の /v1/models から。届かなければ null(欄は今の値だけ出す)
 */
$modelList = null;
$modelError = null;
$modelByModel = [];
$accounts = [];
$usage = [];
$probe = null;
$llmConfig = km_guide_llm_config();
$logs = [];
$logCount = ['total' => 0, 'good' => 0, 'bad' => 0];
$logPerPage = 50;
$logPage = max(1, (int) ($_GET['page'] ?? 1));
$logBadOnly = ($_GET['rating'] ?? '') === 'bad';

if ($llmConfig !== null) {
    try {
        $modelList = km_guide_llm_models($llmConfig);
    } catch (RuntimeException $exception) {
        $modelError = $exception->getMessage();
    }
}

try {
    $pdo = km_db();
    km_guide_ensure_tables($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = (string) ($_POST['form_action'] ?? '');
        if ($formAction === 'save') {
            $enabled = isset($_POST['enabled']);
            $sub = trim((string) ($_POST['sub'] ?? ''));
            $limit = (int) ($_POST['daily_limit'] ?? 0);
            $reasoning = (string) ($_POST['reasoning'] ?? 'auto');
            $model = trim((string) ($_POST['model'] ?? ''));
            $currentModel = km_guide_settings($pdo)['model'];
            // 選べる模型は、自宅 PC の一覧にあるものだけ(届かないときは今の値のままなら保存できる)
            $modelOk = $model === '' || $model === $currentModel
                || ($modelList !== null && in_array($model, array_column($modelList['models'], 'id'), true));
            // 選べるのは印の付いたテスト用アカウントだけ
            $known = array_column(km_test_accounts_all($pdo), 'sub');
            if ($sub !== '' && !in_array($sub, $known, true)) {
                $errors[] = 'そのアカウントはテスト用アカウントではありません。先に管理画面「テストモード」で印を付けてください。';
            } elseif ($limit < 1 || $limit > KM_GUIDE_DAILY_MAX) {
                $errors[] = '1 日の上限は 1〜' . KM_GUIDE_DAILY_MAX . ' 回にしてください。';
            } elseif ($enabled && $sub === '') {
                $errors[] = 'ON にするときは、使う人(テスト用アカウント)を選んでください。';
            } elseif (!array_key_exists($reasoning, KM_GUIDE_REASONING_CHOICES)) {
                $errors[] = '「考える」は一覧から選んでください。';
            } elseif (!km_guide_model_key_valid($model) || !$modelOk) {
                $errors[] = $modelList === null
                    ? '自宅 PC に届かないので、模型は変えられません(ほかの設定は、模型をそのままにすれば保存できます)。'
                    : '模型は一覧から選んでください。';
            } else {
                km_setting_set($pdo, KM_GUIDE_ENABLED_SETTING, $enabled ? '1' : '0');
                km_setting_set($pdo, KM_GUIDE_SUB_SETTING, $sub);
                km_setting_set($pdo, KM_GUIDE_DAILY_SETTING, (string) $limit);
                km_setting_set($pdo, KM_GUIDE_REASONING_SETTING, $reasoning);
                km_setting_set($pdo, KM_GUIDE_MODEL_SETTING, $model);
                km_admin_log_record('content', 'guide.settings', ($enabled ? 'ON' : 'OFF') . '・1 日 ' . $limit . ' 回・考える '
                    . KM_GUIDE_REASONING_CHOICES[$reasoning] . '・模型 ' . ($model !== '' ? $model : '自宅 PC の既定'));
                // 模型を変えたら、自宅 PC に先に読み込んでもらう(数十秒〜数分。待たない)
                $loadState = '';
                if ($model !== '' && $model !== $currentModel && $llmConfig !== null) {
                    try {
                        $loadState = km_guide_llm_select($llmConfig, $model);
                    } catch (RuntimeException $exception) {
                        $loadState = 'failed';
                    }
                }
                header('Location: ./guide-chat.php?saved=1' . ($loadState !== '' ? '&model=' . rawurlencode($loadState) : ''), true, 303);
                exit;
            }
        } elseif ($formAction === 'probe') {
            // LLM に届くか。本文は決まった短い文だけ(利用者の質問は使わない)
            if ($llmConfig === null) {
                $probe = ['ok' => false, 'message' => 'KM_GUIDE_LLM_URL と KM_GUIDE_LLM_KEY が入っていません(.env → 配備)。'];
            } else {
                $started = microtime(true);
                // 確かめも、いまの「考える」と「模型」の設定で送る(ON のときにどれだけ待つか・どの模型が答えたかが分かる)
                $probeSettings = km_guide_settings($pdo);
                $llmConfig['reasoning'] = $probeSettings['reasoning'];
                if ($probeSettings['model'] !== '') {
                    $llmConfig['model'] = $probeSettings['model'];
                }
                try {
                    $reply = km_guide_llm_chat($llmConfig, [['role' => 'user', 'content' => '「OK」とだけ答えてください。']], 8, 20, $probeMeta);
                    $probe = ['ok' => true, 'message' => '届きました(' . number_format(microtime(true) - $started, 1) . ' 秒・' . (($probeMeta['model'] ?? '') !== '' ? $probeMeta['model'] : '模型は不明') . ')。返事: ' . mb_substr($reply, 0, 40)];
                } catch (RuntimeException $exception) {
                    $probe = ['ok' => false, 'message' => $exception->getMessage() . '(' . number_format(microtime(true) - $started, 1) . ' 秒)'];
                }
            }
        } elseif ($formAction === 'clear_log') {
            // 残した質問と答えをまとめて消す(確かめの言葉を打ってもらう)
            if (trim((string) ($_POST['confirm'] ?? '')) !== '消す') {
                $errors[] = '消すときは、欄に「消す」と入れてください。';
            } else {
                $removed = km_guide_log_clear($pdo);
                km_admin_log_record('content', 'guide.log_clear', $removed . ' 件');
                header('Location: ./guide-chat.php?cleared=' . $removed, true, 303);
                exit;
            }
        }
    }

    $settings = km_guide_settings($pdo);
    $accounts = km_test_accounts_all($pdo);
    $usage = km_guide_usage_recent($pdo, 7);
    $logCount = km_guide_log_count($pdo);
    $logs = km_guide_log_recent($pdo, $logPerPage, ($logPage - 1) * $logPerPage, $logBadOnly);
    $modelByModel = km_guide_log_by_model($pdo);
} catch (Throwable $exception) {
    error_log('admin/guide-chat.php: ' . $exception->getMessage());
    $dbError = km_admin_error_message($exception, '詳しくはサーバーのログを照合してください。');
}

$saved = isset($_GET['saved']);
$modelLoadState = (string) ($_GET['model'] ?? '');
// 模型の欄の選択肢(頼み先ごと)。一覧に無い今の値も残す(自宅 PC に届かないときに消えないように)
$modelOptions = [];
foreach ($modelList['models'] ?? [] as $row) {
    $modelOptions[$row['backend']][] = $row;
}
$modelStateLabel = static fn (array $row): string => $row['loaded'] ? '読み込み済み' : ($row['loading'] ? '読み込み中' : ($row['failed'] ? '読み込めなかった' : '未読み込み'));
$cleared = isset($_GET['cleared']) ? max(0, (int) $_GET['cleared']) : null;
$logTotalShown = $logBadOnly ? $logCount['bad'] : $logCount['total'];
$logPages = max(1, (int) ceil($logTotalShown / $logPerPage));
$intentLabels = ['shop' => '店', 'route' => '経路', 'place' => '場所', 'other' => 'その他', '' => '—'];
// 印の付いていないアカウントが選ばれたまま(印を外した)なら知らせる
$subMissing = $settings['sub'] !== '' && !in_array($settings['sub'], array_column($accounts, 'sub'), true);

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';
?>
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid">
            <?php if ($dbError !== null): ?>
              <div class="alert alert-warning" role="alert">データベースに接続できません。<?= km_e($dbError) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($message) ?></div>
            <?php endforeach; ?>
            <?php if ($saved): ?>
              <div class="alert alert-success" role="alert">
                保存しました。
                <?php if ($modelLoadState === 'loading'): ?>自宅 PC が模型を読み込んでいます(数十秒〜数分)。その間の質問は、言葉で探した結果だけで答えます。<?php endif; ?>
                <?php if ($modelLoadState === 'failed'): ?>自宅 PC に模型の切り替えを頼めませんでした。最初の質問のときに読み込みます。<?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($cleared !== null): ?>
              <div class="alert alert-success" role="alert">質問と答えを <?= (int) $cleared ?> 件消しました。</div>
            <?php endif; ?>
            <?php if ($subMissing): ?>
              <div class="alert alert-warning" role="alert">選んでいたアカウントのテストモードの印が外れています。いまは誰も使えません。</div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start" role="alert">
              <i class="bi bi-chat-dots me-2 mt-1" aria-hidden="true"></i>
              <div>
                公開の地図に「💬 案内」を出し、模擬店や場所を言葉で聞けるようにします。答えに出す店と場所は、地図に登録されているものだけです(LLM には探した記録だけを渡し、カードは登録から作ります)。
                経路は地図の案内と同じ計算で出します。質問と答えと評価は、案内をよくするために下の一覧に残します(誰が聞いたかは残しません。メールアドレスと電話番号らしい文字は伏せます)。
                使えるのは、下で選んだテスト用アカウントで公開の地図を開いたときだけです。
              </div>
            </div>

            <div class="row">
              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title mb-0">設定</h3></div>
                  <form method="post" action="./guide-chat.php">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="save">
                    <div class="card-body">
                      <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="guide-enabled" name="enabled" value="1"<?= $settings['enabled'] ? ' checked' : '' ?>>
                        <label class="form-check-label" for="guide-enabled">案内チャットを使う</label>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="guide-sub">使う人(テスト用アカウント)</label>
                        <select class="form-select" id="guide-sub" name="sub">
                          <option value="">選ばない(誰も使えない)</option>
                          <?php foreach ($accounts as $account): ?>
                            <option value="<?= km_e($account['sub']) ?>"<?= $account['sub'] === $settings['sub'] ? ' selected' : '' ?>>
                              <?= km_e($account['label'] !== '' ? $account['label'] : $account['sub']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                          並ぶのは、管理画面「テストモード」で印を付けたアカウントです。<a href="./test-mode.php">テストモードを開く</a>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="guide-limit">1 日の上限(回)</label>
                        <input class="form-control" type="number" id="guide-limit" name="daily_limit" min="1" max="<?= KM_GUIDE_DAILY_MAX ?>" value="<?= (int) $settings['dailyLimit'] ?>">
                        <div class="form-text">1 回の質問で自宅 PC の LLM を 2 回(言葉で当たらないときは 3〜6 回)呼びます。ほかに、1 分に <?= KM_GUIDE_PER_MINUTE ?> 回までに絞っています。</div>
                      </div>
                      <div class="mb-3">
                        <?php /* 2026-10-29、利用者「AI を選択できるようにしてください」。自宅 PC の門番の一覧から */ ?>
                        <label class="form-label" for="guide-model">模型(AI)</label>
                        <select class="form-select" id="guide-model" name="model">
                          <option value=""<?= $settings['model'] === '' ? ' selected' : '' ?>>
                            自宅 PC の既定<?= ($modelList['default'] ?? '') !== '' ? '(' . km_e($modelList['default']) . ')' : '' ?>
                          </option>
                          <?php foreach ($modelOptions as $backendName => $rows): ?>
                            <optgroup label="<?= km_e($backendName === 'unsloth' ? 'Unsloth' : ($backendName === 'bionic' ? 'Bionic' : $backendName)) ?>">
                              <?php foreach ($rows as $row): ?>
                                <option value="<?= km_e($row['id']) ?>"<?= $row['id'] === $settings['model'] ? ' selected' : '' ?>>
                                  <?= km_e($row['name']) ?>(<?= km_e($modelStateLabel($row)) ?>)
                                </option>
                              <?php endforeach; ?>
                            </optgroup>
                          <?php endforeach; ?>
                          <?php if ($settings['model'] !== '' && !in_array($settings['model'], array_column($modelList['models'] ?? [], 'id'), true)): ?>
                            <option value="<?= km_e($settings['model']) ?>" selected><?= km_e($settings['model']) ?>(いまの値・一覧に無い)</option>
                          <?php endif; ?>
                        </select>
                        <div class="form-text">
                          <?php if ($llmConfig === null): ?>
                            LLM のつなぎ先が入っていないので、一覧を出せません。
                          <?php elseif ($modelError !== null): ?>
                            自宅 PC に届かないので、一覧を出せません(<?= km_e($modelError) ?>)。自宅 PC で start-guide-llm.ps1 を動かしてください。
                          <?php else: ?>
                            自宅 PC の Unsloth / Bionic にある模型です。変えると、自宅 PC がほかの模型を外してから読み込みます(数十秒〜数分。その間は言葉で探した結果だけで答えます)。
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="mb-3">
                        <?php /* 2026-10-25、利用者「Website 側で、考えるの ON-OFF はできませんか」 */ ?>
                        <label class="form-label" for="guide-reasoning">考える(推論)</label>
                        <select class="form-select" id="guide-reasoning" name="reasoning">
                          <?php foreach (KM_GUIDE_REASONING_CHOICES as $value => $label): ?>
                            <option value="<?= km_e($value) ?>"<?= $value === $settings['reasoning'] ? ' selected' : '' ?>><?= km_e($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                          ON にすると答える前に考えるので、名前の取り違えは減るかもしれませんが、1 回の答えが遅くなります(数秒 → 10〜30 秒)。
                          GPT-OSS のように考えを止められない模型では、OFF でも「少し」考えます。「模型に任せる」は自宅 PC の設定のままです。
                        </div>
                      </div>
                    </div>
                    <div class="card-footer text-end">
                      <button type="submit" class="btn btn-primary btn-sm">保存</button>
                    </div>
                  </form>
                </div>
              </div>

              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title mb-0">LLM(自宅 PC の Unsloth / Bionic + ngrok)</h3></div>
                  <div class="card-body">
                    <dl class="row fs-7 mb-2">
                      <dt class="col-4 fw-normal text-body-secondary">つなぎ先</dt>
                      <dd class="col-8"><?= $llmConfig === null ? '<span class="text-danger-emphasis">入っていません(言葉で探した結果だけを返します)</span>' : '<span class="text-success-emphasis">入っています</span>' ?></dd>
                    </dl>
                    <p class="fs-7 text-body-secondary mb-2">
                      URL と鍵は .env の KM_GUIDE_LLM_URL / KM_GUIDE_LLM_KEY で、ここには出しません。自宅 PC では server/guide-llm/start-guide-llm.ps1 を動かします。
                    </p>
                    <?php if ($probe !== null): ?>
                      <div class="alert <?= $probe['ok'] ? 'alert-success' : 'alert-danger' ?> py-2 fs-7" role="status"><?= km_e($probe['message']) ?></div>
                    <?php endif; ?>
                    <form method="post" action="./guide-chat.php">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="form_action" value="probe">
                      <button type="submit" class="btn btn-outline-primary btn-sm"<?= $llmConfig === null ? ' disabled' : '' ?>>届くか確かめる</button>
                    </form>
                  </div>
                </div>

                <div class="card mb-4">
                  <div class="card-header"><h3 class="card-title mb-0">回数(この 7 日)</h3></div>
                  <div class="card-body p-0">
                    <?php if ($usage === []): ?>
                      <p class="text-body-secondary text-center my-3">まだ使われていません。</p>
                    <?php else: ?>
                      <table class="table table-sm mb-0">
                        <thead><tr><th>日</th><th class="text-end">聞いた</th><th class="text-end">LLM なしで答えた</th><th class="text-end">LLM に届かなかった</th></tr></thead>
                        <tbody>
                          <?php foreach ($usage as $row): ?>
                            <tr>
                              <td><?= km_e($row['day']) ?></td>
                              <td class="text-end"><?= (int) $row['asked'] ?></td>
                              <td class="text-end"><?= (int) $row['fallback'] ?></td>
                              <td class="text-end"><?= (int) $row['failed'] ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <?php if ($modelByModel !== []): ?>
              <?php /* 模型ごとの数(2026-10-29。どの AI が良いかを 👍/👎 と速さで比べる) */ ?>
              <div class="card mb-4">
                <div class="card-header"><h3 class="card-title mb-0">模型ごと</h3></div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm mb-0">
                      <thead><tr><th>模型</th><th class="text-end">質問</th><th class="text-end">👍</th><th class="text-end">👎</th><th class="text-end">平均の時間</th></tr></thead>
                      <tbody>
                        <?php foreach ($modelByModel as $row): ?>
                          <tr>
                            <td class="fs-7"><?= km_e($row['model'] !== '' ? $row['model'] : '(LLM なし・記録前)') ?></td>
                            <td class="text-end"><?= (int) $row['total'] ?></td>
                            <td class="text-end"><?= (int) $row['good'] ?></td>
                            <td class="text-end"><?= (int) $row['bad'] ?></td>
                            <td class="text-end"><?= number_format($row['avgMs'] / 1000, 1) ?> 秒</td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="card mb-4" id="guide-log">
              <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h3 class="card-title mb-0 me-auto">質問と答え</h3>
                <span class="fs-7 text-body-secondary">
                  全 <?= (int) $logCount['total'] ?> 件 · 👍 <?= (int) $logCount['good'] ?> · 👎 <?= (int) $logCount['bad'] ?>
                </span>
                <div class="btn-group btn-group-sm" role="group" aria-label="絞り込み">
                  <a class="btn <?= $logBadOnly ? 'btn-outline-secondary' : 'btn-secondary' ?>" href="./guide-chat.php#guide-log">すべて</a>
                  <a class="btn <?= $logBadOnly ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="./guide-chat.php?rating=bad#guide-log">👎 だけ</a>
                </div>
              </div>
              <div class="card-body p-0">
                <?php if ($logs === []): ?>
                  <p class="text-body-secondary text-center my-3"><?= $logBadOnly ? '👎 の付いた答えはありません。' : 'まだ質問がありません。' ?></p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-top mb-0">
                      <thead>
                        <tr><th class="text-nowrap">日時</th><th>質問</th><th>答え</th><th class="text-nowrap">カード・経路</th><th class="text-nowrap">評価</th></tr>
                      </thead>
                      <tbody>
                        <?php foreach ($logs as $row): ?>
                          <tr>
                            <td class="text-nowrap fs-7">
                              <?= km_e($row['askedAt']) ?><br>
                              <span class="text-body-secondary"><?= km_e($intentLabels[$row['intent']] ?? '—') ?> · <?= number_format($row['elapsedMs'] / 1000, 1) ?> 秒<?= $row['fallback'] ? ' · LLM なし' : '' ?></span>
                              <?php if ($row['model'] !== ''): ?><br><span class="text-body-secondary"><?= km_e($row['model']) ?></span><?php endif; ?>
                            </td>
                            <td class="km-guide-log-q"><?= km_e($row['question']) ?></td>
                            <td class="fs-7 km-guide-log-a"><?= km_e($row['answer']) ?></td>
                            <td class="fs-7 km-guide-log-cards">
                              <?= km_e(implode('、', array_map('strval', $row['cards'])) ?: '—') ?>
                              <?php if ($row['route'] !== ''): ?><br><span class="text-body-secondary">経路: <?= km_e($row['route']) ?></span><?php endif; ?>
                            </td>
                            <td class="fs-7 km-guide-log-rate">
                              <?php if ($row['rating'] === 'good'): ?>
                                👍
                              <?php elseif ($row['rating'] === 'bad'): ?>
                                👎 <?= km_e(KM_GUIDE_BAD_REASONS[$row['badReason']] ?? $row['badReason']) ?>
                                <?php if ($row['badNote'] !== ''): ?><br><span class="text-body-secondary"><?= km_e($row['badNote']) ?></span><?php endif; ?>
                              <?php else: ?>
                                <span class="text-body-secondary">—</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
              <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                <?php if ($logPages > 1): ?>
                  <nav aria-label="質問と答えのページ" class="me-auto">
                    <ul class="pagination pagination-sm mb-0">
                      <?php $logQuery = $logBadOnly ? '&rating=bad' : ''; ?>
                      <li class="page-item<?= $logPage <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="./guide-chat.php?page=<?= max(1, $logPage - 1) . $logQuery ?>#guide-log">新しい方へ</a></li>
                      <li class="page-item disabled"><span class="page-link"><?= $logPage ?> / <?= $logPages ?></span></li>
                      <li class="page-item<?= $logPage >= $logPages ? ' disabled' : '' ?>"><a class="page-link" href="./guide-chat.php?page=<?= min($logPages, $logPage + 1) . $logQuery ?>#guide-log">古い方へ</a></li>
                    </ul>
                  </nav>
                <?php else: ?>
                  <span class="me-auto"></span>
                <?php endif; ?>
                <form method="post" action="./guide-chat.php" class="d-flex align-items-center gap-2">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="form_action" value="clear_log">
                  <label class="visually-hidden" for="guide-log-confirm">確かめの言葉</label>
                  <input class="form-control form-control-sm km-guide-log-confirm" type="text" id="guide-log-confirm" name="confirm" placeholder="「消す」と入力" autocomplete="off">
                  <button type="submit" class="btn btn-outline-danger btn-sm"<?= $logCount['total'] === 0 ? ' disabled' : '' ?>>まとめて消す</button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
