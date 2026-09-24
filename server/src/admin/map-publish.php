<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require __DIR__ . '/_inc/guard.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/app-map-publish.php';
require_once dirname(__DIR__) . '/lib/app-secret.php';
require_once dirname(__DIR__) . '/lib/qr.php';
require_once dirname(__DIR__) . '/lib/admin-log.php';
require_once dirname(__DIR__) . '/lib/user-error.php';

/**
 * この Website の地図を、アプリへ配る。
 *
 * ## 手元のスクリプトから移した(2026-09-03)
 *
 * それまでは `scripts/new-map-release.ps1` が、手元の PowerShell から
 * **SSH で本番へ直接書き込んで**いた。配信のたびに手元の環境が要り、
 * 「いま何を配っているか」も管理画面からは断片しか見えなかった。
 *
 * 利用者の指示で**全部 Website 一括**にする。
 *
 * ## 配る JSON は「作る」もの
 *
 * `uploads/app-map-<slug>.json` は**生成物**。正本は DB。
 * 上げた JSON をそのまま配ると、この画面で直した内容と配ったものが食い違う。
 *
 * ## 正本を2つに分けた(2026-09-14)
 *
 * 利用者の指示で、**Website の正本(kosen-main)**と**イベント用の正本(kosen-event)**を分ける。
 * 上の「配る JSON は作るもの」は kosen-main の話で、そのまま守る。
 * kosen-event は**管理アプリで作った JSON を添付して配る** —— 会期だけの地図を、
 * Website の地図を書き換えずに配るためのもの。どちらを受け取るかはアクセスコードで決まる。
 *
 * ## 平文のコードは URL に載せない
 *
 * コードを作った直後だけ画面に出す。**そこは POST の応答としてそのまま描く** ——
 * リダイレクトすると平文がクエリに乗り、履歴・プロキシ・アクセスログに残る。
 * 二重送信の心配より、そちらの方が重い。
 *
 * ## インラインの onsubmit を使わない
 *
 * 以前は `<form onsubmit="return confirm(…)">` と nonce 無しの `<script>` だった。
 * 管理画面の CSP は `script-src 'self' 'nonce-…'` だけなので、**確認も伏せ字の切り替えも
 * 本番では動いていなかった**(確認なしで配信され、「見る」を押しても何も出ない)。
 * いまは `data-km-confirm` を付け、nonce 付きの script から登録する。
 */

$mainSlug = KM_APP_MAP_MAIN_SLUG;
$eventSlug = KM_APP_MAP_EVENT_SLUG;
$errors = [];
$notice = null;
$published = null;
$publishedSlug = null;
$newCode = null;
$newCodeSlug = null;

$config = km_app_map_config();

/** 期限の入力を読む。**推測で埋めない**(空のまま配ると、端末に残った地図がいつまでも消えない)。 */
$readExpires = static function (): ?DateTimeImmutable {
    $raw = trim((string) ($_POST['expires_at'] ?? ''));

    return $raw !== '' ? km_app_map_parse_iso($raw) : null;
};

$staleMessage = '画面を開いてから配信設定が変わりました。再読み込みしてからやり直してください。';

/*
 * **post_max_size を超えた POST は、$_POST も $_FILES も空で届く。** そのままだと CSRF の照合で落ち、
 * 「セッションの有効期限が切れています」と**違う理由**が出る(添付が大きすぎただけなのに)。
 * 何も変えずに断るだけなので、CSRF の照合より先に見てよい。
 */
$postTooLarge = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && $_POST === []
    && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

if ($postTooLarge) {
    $errors[] = 'ファイルが大きすぎます(上限 ' . (KM_APP_MAP_EVENT_MAX_BYTES / 1024 / 1024) . 'MB)。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
    $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 地図の組み立てと書き出しは時間がかかる。PHP の既定(30 秒)で途中終了させない
    @set_time_limit(120);

    $action = (string) ($_POST['do_publish'] ?? '');
    $postedSlug = (string) ($_POST['slug'] ?? '');
    $knownSlug = in_array($postedSlug, KM_APP_MAP_SLUGS, true) ? $postedSlug : null;

    if ($action === 'publish') {
        $expires = $readExpires();
        if ($expires === null) {
            $errors[] = '有効期限を読み取れませんでした。日時を選び直してください。';
        } else {
            try {
                $published = km_app_map_publish(
                    km_db(),
                    $config,
                    $mainSlug,
                    $expires->format(DateTimeInterface::ATOM),
                    ($_POST['checksum'] ?? '') === '1'
                );
                $publishedSlug = $mainSlug;
                $config = $published['config'];
                km_admin_log_record(
                    'content',
                    'map.publish',
                    "版{$published['revision']} / 地点{$published['nodes']} / 経路{$published['lines']}"
                    . ' / イベント' . $published['events']
                );
                $notice = "Website の正本: 版 {$published['revision']} を配信しました。";
            } catch (Throwable $exception) {
                $errors[] = km_admin_error_message($exception, '配信できませんでした。');
            }
        }
    } elseif ($action === 'publish_event') {
        $expires = $readExpires();
        $file = $_FILES['event_json'] ?? null;
        $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($expires === null) {
            $errors[] = '有効期限を読み取れませんでした。日時を選び直してください。';
        } elseif ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'ファイルが大きすぎます(上限 ' . (KM_APP_MAP_EVENT_MAX_BYTES / 1024 / 1024) . 'MB)。';
        } elseif ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            // **上げられたファイル以外を読ませない**(map-sync.php と同じ)
            $errors[] = 'ファイルを受け取れませんでした。';
        } elseif ((int) ($file['size'] ?? 0) > KM_APP_MAP_EVENT_MAX_BYTES) {
            // 大きさは中身を読む前に見る。巨大なファイルで PHP のメモリを使い切らせない
            $errors[] = 'ファイルが大きすぎます(上限 ' . (KM_APP_MAP_EVENT_MAX_BYTES / 1024 / 1024) . 'MB)。';
        } else {
            try {
                $fold = ($_POST['fold_web_events'] ?? '') === '1';
                $raw = (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, KM_APP_MAP_EVENT_MAX_BYTES + 1);
                $sourceName = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
                $published = km_app_map_publish_event(
                    // 折り込むときだけ DB が要る。折り込まない配信は DB が落ちていてもできる
                    $fold ? km_db() : null,
                    $config,
                    $raw,
                    $expires->format(DateTimeInterface::ATOM),
                    ($_POST['checksum'] ?? '') === '1',
                    $fold,
                    $sourceName
                );
                $publishedSlug = $eventSlug;
                $config = $published['config'];
                // 操作名は Website の正本と同じ map.publish。どちらの正本かは詳細の先頭で分ける
                km_admin_log_record(
                    'content',
                    'map.publish',
                    $eventSlug . " / 版{$published['revision']} / 地点{$published['nodes']} / 経路{$published['lines']}"
                    . ' / イベント' . $published['events'] . ' / 折り込み' . ($fold ? 'あり' : 'なし')
                    . ' / ' . $sourceName
                );
                $notice = "イベント用の正本: 版 {$published['revision']} を配信しました。";
            } catch (Throwable $exception) {
                $errors[] = km_admin_error_message($exception, '配信できませんでした。');
            }
        }
    } elseif ($action === 'add_code') {
        if ($knownSlug === null) {
            $errors[] = '配信先を選び直してください。';
        } else {
            try {
                /*
                 * **鍵つきの印(lookup)を必ず付ける。** 付けないと、そのコードの照合は
                 * bcrypt と外れの回数制限に回る。DB が使えないなら作らない。
                 */
                $secret = km_app_secret(km_db(), KM_APP_MAP_CODE_SECRET);
                $result = km_app_map_add_code($config, $knownSlug, $secret);
                km_app_map_write_config($result['config']);
                $config = $result['config'];
                $newCode = $result['code'];
                $newCodeSlug = $knownSlug;
                km_admin_log_record('content', 'map.code_add', $knownSlug);
            } catch (Throwable $exception) {
                $errors[] = km_admin_error_message($exception, 'アクセスコードを作れませんでした。');
            }
        }
    } elseif ($action === 'remove_code') {
        $index = (int) ($_POST['code_index'] ?? -1);
        $entry = km_app_map_code_at($config, $index, (string) ($_POST['code_ref'] ?? ''));
        if ($entry === null) {
            $errors[] = $staleMessage;
        } else {
            try {
                $config = km_app_map_remove_code($config, $index);
                km_app_map_write_config($config);
                km_admin_log_record('content', 'map.code_remove', (string) ($entry['slug'] ?? ''));
                $notice = 'アクセスコードを1つ止めました。';
            } catch (Throwable $exception) {
                $errors[] = km_admin_error_message($exception, 'アクセスコードを止められませんでした。');
            }
        }
    } elseif ($action === 'reassign_code') {
        $index = (int) ($_POST['code_index'] ?? -1);
        $entry = km_app_map_code_at($config, $index, (string) ($_POST['code_ref'] ?? ''));
        if ($entry === null) {
            $errors[] = $staleMessage;
        } elseif ($knownSlug === null) {
            $errors[] = '付け替え先を選び直してください。';
        } else {
            try {
                $from = (string) ($entry['slug'] ?? '');
                $config = km_app_map_reassign_code($config, $index, $knownSlug);
                km_app_map_write_config($config);
                km_admin_log_record('content', 'map.code_reassign', $from . ' → ' . $knownSlug);
                $notice = 'アクセスコードの配信先を ' . $knownSlug . ' に付け替えました。';
            } catch (Throwable $exception) {
                $errors[] = km_admin_error_message($exception, 'アクセスコードを付け替えられませんでした。');
            }
        }
    }
}

$report = [];
foreach (km_app_map_release_report($config) as $row) {
    $report[$row['slug']] = $row;
}

/** 配信先ごとのコード。並び順(index)と印(ref)は「止める」で使う。 */
$codesBySlug = [$mainSlug => [], $eventSlug => []];
foreach (($config['codes'] ?? []) as $index => $entry) {
    $entrySlug = is_array($entry) ? (string) ($entry['slug'] ?? '') : '';
    if (isset($codesBySlug[$entrySlug])) {
        $codesBySlug[$entrySlug][(int) $index] = [
            'plain' => trim((string) ($entry['code'] ?? '')),
            'ref' => km_app_map_code_ref($entry),
            'legacy' => !is_string($entry['lookup'] ?? null) || $entry['lookup'] === '',
        ];
    }
}

/** **行き先の無いコード。** 本番の TEST1 がこの形(入れた端末は地図を取れない)。 */
$orphans = km_app_map_orphan_codes($config);

/**
 * いま有効なイベントの名前。**配信する前に見せる。**
 *
 * 何が折り込まれるのかを、押す前に読めるようにする ——
 * 「配信したのに通行止めが効かない/解けない」の相談はここで防げる。
 */
$activeEvents = [];
try {
    require_once dirname(__DIR__) . '/lib/map-events.php';
    foreach (km_map_event_overlay(km_db())['events'] as $event) {
        $activeEvents[] = (string) $event['name'];
    }
} catch (Throwable $exception) {
    // 見せるためだけのもの。読めなくても配信そのものは止めない
    error_log('map-publish.php events failed: ' . $exception->getMessage());
}

/** 期限の初期値。**いまの期限を引き継ぐ**(毎回打ち直させない)。 */
$expiresDefault = static function (array $config, string $slug): string {
    $current = $config['maps'][$slug]['expiresAt'] ?? null;
    $parsed = is_string($current) ? km_app_map_parse_iso($current) : null;

    return ($parsed ?? new DateTimeImmutable('+30 days'))->format('Y-m-d\TH:i');
};

$KM_PAGE = [
    'nav' => 'mapPublish',
    'titleKey' => 'page.mapPublish.title',
    'title' => 'アプリへ地図を配信する | KosenMap 管理',
    'h1Key' => 'page.mapPublish.h1',
    'h1' => 'アプリへ地図を配信する',
    'crumbs' => [
        ['key' => 'side.database', 'text' => 'データベース'],
        ['key' => 'page.mapPublish.h1', 'text' => 'アプリへ地図を配信する'],
    ],
];

require __DIR__ . '/_inc/partials/head.php';
require __DIR__ . '/_inc/partials/header.php';
require __DIR__ . '/_inc/partials/sidebar.php';
require __DIR__ . '/_inc/partials/page-header.php';

/** 配信の状態(版・期限・停止・コード数)。 */
$renderCurrent = static function (string $slug) use ($config, $report): void {
    $current = $report[$slug] ?? null;
    $meta = $config['maps'][$slug] ?? [];
    ?>
    <div class="card mb-4">
      <div class="card-header">
        <h4 class="card-title" data-i18n="page.mapPublish.currentTitle">いま配っているもの</h4>
      </div>
      <div class="card-body">
        <?php if ($current === null): ?>
          <p class="mb-0" data-i18n="page.mapPublish.none">まだ配信していません。</p>
        <?php else: ?>
          <dl class="row mb-0">
            <dt class="col-7" data-i18n="page.mapPublish.revision">版</dt>
            <dd class="col-5 text-end"><?= (int) ($meta['revision'] ?? 0) ?></dd>
            <dt class="col-7" data-i18n="page.mapPublish.expiresAt">有効期限</dt>
            <dd class="col-5 text-end fs-7"><?= km_e((string) ($meta['expiresAt'] ?? '—')) ?></dd>
            <dt class="col-7" data-i18n="page.mapPublish.paused">配信の停止</dt>
            <dd class="col-5 text-end">
              <?php if ($current['paused']): ?>
                <span class="badge bg-warning text-dark" data-i18n="page.mapPublish.pausedYes">停止中</span>
              <?php else: ?>
                <span class="badge bg-success" data-i18n="page.mapPublish.pausedNo">配信中</span>
              <?php endif; ?>
            </dd>
            <dt class="col-7" data-i18n="page.mapPublish.codeCount">アクセスコード</dt>
            <dd class="col-5 text-end"><?= (int) $current['codeCount'] ?> 個</dd>
            <?php if (is_string($meta['sourceName'] ?? null) && $meta['sourceName'] !== ''): ?>
              <dt class="col-7" data-i18n="page.mapPublish.eventSource">元のファイル</dt>
              <dd class="col-5 text-end fs-7 text-break"><?= km_e($meta['sourceName']) ?></dd>
            <?php endif; ?>
          </dl>
          <?php if (($current['file']['error'] ?? null) !== null): ?>
            <div class="alert alert-danger mt-3 mb-0 fs-7"><?= km_e((string) $current['file']['error']) ?></div>
          <?php endif; ?>
        <?php endif; ?>
        <p class="form-text mb-0 mt-3" data-i18n="page.mapPublish.pauseElsewhere">
          停止と削除は「配布ファイル」の画面から行えます。
        </p>
      </div>
    </div>
    <?php
};

/** 期限とチェックサムの入力。2つの区画で同じ形。 */
$renderExpiryAndChecksum = static function (string $slug) use ($config, $expiresDefault): void {
    ?>
    <div class="mb-3">
      <label class="form-label" for="expires_at_<?= km_e($slug) ?>"
             data-i18n="page.mapPublish.expiresLabel">有効期限</label>
      <input class="form-control" type="datetime-local" id="expires_at_<?= km_e($slug) ?>" name="expires_at"
             value="<?= km_e($expiresDefault($config, $slug)) ?>" required>
      <div class="form-text" data-i18n="page.mapPublish.expiresHint">
        期限を過ぎると、来場者の端末は地図を自分で消します。
        会期の終わりより少し後にしてください。
      </div>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" value="1" id="checksum_<?= km_e($slug) ?>" name="checksum" checked>
      <label class="form-check-label" for="checksum_<?= km_e($slug) ?>"
             data-i18n="page.mapPublish.checksum">チェックサムを付ける</label>
      <div class="form-text" data-i18n="page.mapPublish.checksumHint">
        途中で切れた地図を端末が弾けます。改ざんの検出ではありません(そちらは TLS)。
      </div>
    </div>
    <?php
};

/** 配信先ごとのアクセスコードの一覧と「作る」。 */
$renderCodes = static function (string $slug) use ($codesBySlug, $newCode, $newCodeSlug): void {
    $codes = $codesBySlug[$slug] ?? [];
    ?>
    <?php if ($newCode !== null && $newCodeSlug === $slug): ?>
      <div class="card mb-4 border-primary">
        <div class="card-header">
          <h4 class="card-title" data-i18n="page.mapPublish.newCodeTitle">作ったアクセスコード</h4>
        </div>
        <div class="card-body">
          <p class="fs-2 font-monospace mb-2"><?= km_e($newCode) ?></p>
          <?php
          /*
           * QR はその場で描く。**外部のサービスへコードを送らない** ——
           * 送った先に残ったコードで、誰でも地図を落とせるようになる。
           */
          ?>
          <div class="mb-2 km-qr-box">
            <?= km_qr_svg(km_qr_matrix(km_app_map_qr_payload($newCode), 'Q'), 6, 4) ?>
          </div>
          <p class="form-text mb-0" data-i18n="page.mapPublish.newCodeHint">
            QR は右クリックで保存できます。印刷して会場に貼るなら、
            汚れても読めるよう余白ごと大きめに。
          </p>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mb-4">
      <div class="card-header">
        <h4 class="card-title" data-i18n="page.mapPublish.codesTitle">アクセスコード</h4>
      </div>
      <div class="card-body">
        <?php if ($codes === []): ?>
          <p data-i18n="page.mapPublish.noCodes">まだありません。</p>
        <?php else: ?>
          <table class="table table-sm align-middle">
            <tbody>
              <?php foreach ($codes as $index => $code): ?>
                <tr>
                  <td class="font-monospace">
                    <?php if ($code['plain'] === ''): ?>
                      <span class="text-body-secondary fs-7"
                            data-i18n="page.mapPublish.codeNotStored">控えを保存していません</span>
                    <?php else: ?>
                      <?php
                      /*
                       * **既定では伏せる。** 管理画面を人に見せながら操作する場面で、
                       * 肩越しに読まれるのを防ぐ(利用者の要望)。
                       */
                      ?>
                      <span class="km-code" data-code="<?= km_e($code['plain']) ?>"><?= km_e(str_repeat('•', mb_strlen($code['plain']))) ?></span>
                      <button type="button" class="btn btn-sm btn-link km-code-toggle"
                              data-i18n="page.mapPublish.reveal">見る</button>
                    <?php endif; ?>
                    <?php if ($code['legacy']): ?>
                      <?php // lookup の無い古いコード。一度使われれば書き足される ?>
                      <span class="badge text-bg-secondary ms-1" data-i18n="page.mapPublish.codeLegacy">古い形式</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <form method="post" class="d-inline"
                          data-km-confirm="page.mapPublish.confirmRemoveCode"
                          data-km-confirm-fallback="このアクセスコードを止めます。配った端末は次の取得からつながりません。よろしいですか?">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="do_publish" value="remove_code">
                      <input type="hidden" name="code_index" value="<?= (int) $index ?>">
                      <input type="hidden" name="code_ref" value="<?= km_e($code['ref']) ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"
                              data-i18n="page.mapPublish.removeCode">止める</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <form method="post">
          <?= km_csrf_field() ?>
          <input type="hidden" name="do_publish" value="add_code">
          <input type="hidden" name="slug" value="<?= km_e($slug) ?>">
          <button type="submit" class="btn btn-outline-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
            <span data-i18n="page.mapPublish.addCode">アクセスコードを作る</span>
          </button>
          <span class="form-text ms-2" data-i18n="page.mapPublish.addCodeHint">
            複数持てます。配る相手ごとに分けておくと、片方だけ止められます。
          </span>
        </form>
      </div>
    </div>
    <?php
};
?>
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <?php if ($notice !== null): ?>
              <div class="alert alert-success" role="alert"><?= km_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
              <div class="alert alert-danger" role="alert"><?= km_e($error) ?></div>
            <?php endforeach; ?>

            <div class="card mb-4">
              <div class="card-body">
                <p class="mb-0" data-i18n="page.mapPublish.lead">
                  アプリへ配る地図は2つあります。「Website の正本」はこの Website の地図から作り、
                  「イベント用の正本」は管理アプリで作った JSON を添付して配ります。
                  どちらを受け取るかは、端末に入れたアクセスコードで決まります。
                </p>
              </div>
            </div>

            <?php if ($orphans !== []): ?>
              <?php
              /*
               * **行き先の無いコードを、黙って残さない。**
               * 入れた人には「配信設定が未完了」としか出ず、原因に辿り着けない(TEST1 の形)。
               * 消すだけでなく**付け替え**を用意する —— 印刷して配った QR をそのまま活かせる。
               */
              ?>
              <div class="card mb-4 border-warning">
                <div class="card-header">
                  <h3 class="card-title" data-i18n="page.mapPublish.orphanTitle">配信先の無いアクセスコードがあります</h3>
                </div>
                <div class="card-body">
                  <div class="alert alert-warning fs-7" role="alert" data-i18n="page.mapPublish.orphanHint">
                    次のコードは、どの配信にもつながっていません。入れた端末は地図を受け取れません。
                    付け替えるか、止めてください。
                  </div>
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col" data-i18n="page.mapPublish.orphanSlug">いまの配信先</th>
                        <th scope="col" data-i18n="page.mapPublish.codesTitle">アクセスコード</th>
                        <th scope="col" data-i18n="page.mapPublish.reassignTo">付け替え先</th>
                        <th scope="col"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($orphans as $orphan): ?>
                        <tr>
                          <td><code><?= km_e($orphan['slug'] !== '' ? $orphan['slug'] : '—') ?></code></td>
                          <td class="font-monospace">
                            <?php if ($orphan['plain'] === null): ?>
                              <span class="text-body-secondary fs-7"
                                    data-i18n="page.mapPublish.codeNotStored">控えを保存していません</span>
                            <?php else: ?>
                              <span class="km-code" data-code="<?= km_e($orphan['plain']) ?>"><?= km_e(str_repeat('•', mb_strlen($orphan['plain']))) ?></span>
                              <button type="button" class="btn btn-sm btn-link km-code-toggle"
                                      data-i18n="page.mapPublish.reveal">見る</button>
                            <?php endif; ?>
                          </td>
                          <td>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-center"
                                  data-km-confirm="page.mapPublish.confirmReassign"
                                  data-km-confirm-fallback="このアクセスコードの配信先を付け替えます。配った端末は次の取得から付け替えた先の地図を受け取ります。よろしいですか?">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="do_publish" value="reassign_code">
                              <input type="hidden" name="code_index" value="<?= (int) $orphan['index'] ?>">
                              <input type="hidden" name="code_ref" value="<?= km_e($orphan['ref']) ?>">
                              <label class="visually-hidden" for="km-reassign-<?= (int) $orphan['index'] ?>"
                                     data-i18n="page.mapPublish.reassignTo">付け替え先</label>
                              <select class="form-select form-select-sm km-w-12rem" name="slug"
                                      id="km-reassign-<?= (int) $orphan['index'] ?>">
                                <?php foreach (KM_APP_MAP_SLUGS as $target): ?>
                                  <option value="<?= km_e($target) ?>"><?= km_e($target) ?></option>
                                <?php endforeach; ?>
                              </select>
                              <button type="submit" class="btn btn-sm btn-outline-primary"
                                      data-i18n="page.mapPublish.reassignButton">付け替える</button>
                            </form>
                            <?php foreach (KM_APP_MAP_SLUGS as $target): ?>
                              <?php if (!isset($config['maps'][$target])): ?>
                                <span class="d-block text-body-secondary fs-7">
                                  <code><?= km_e($target) ?></code>
                                  <span data-i18n="page.mapPublish.notPublished">はまだ配信していません</span>
                                </span>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </td>
                          <td class="text-end">
                            <form method="post" class="d-inline"
                                  data-km-confirm="page.mapPublish.confirmRemoveCode"
                                  data-km-confirm-fallback="このアクセスコードを止めます。配った端末は次の取得からつながりません。よろしいですか?">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="do_publish" value="remove_code">
                              <input type="hidden" name="code_index" value="<?= (int) $orphan['index'] ?>">
                              <input type="hidden" name="code_ref" value="<?= km_e($orphan['ref']) ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger"
                                      data-i18n="page.mapPublish.removeCode">止める</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($published !== null): ?>
              <div class="card mb-4 border-success">
                <div class="card-header">
                  <h3 class="card-title">
                    <span data-i18n="page.mapPublish.resultTitle">配信した内容</span>
                    <code class="ms-2"><?= km_e((string) $publishedSlug) ?></code>
                  </h3>
                </div>
                <div class="card-body">
                  <dl class="row mb-0">
                    <dt class="col-7" data-i18n="page.mapPublish.revision">版</dt>
                    <dd class="col-5 text-end"><?= (int) $published['revision'] ?></dd>
                    <dt class="col-7" data-i18n="page.mapPublish.nodes">地点</dt>
                    <dd class="col-5 text-end"><?= (int) $published['nodes'] ?></dd>
                    <dt class="col-7" data-i18n="page.mapPublish.lines">経路</dt>
                    <dd class="col-5 text-end"><?= (int) $published['lines'] ?></dd>
                    <dt class="col-7" data-i18n="page.mapPublish.fingerprints">Wi-Fi 指紋</dt>
                    <dd class="col-5 text-end"><?= (int) $published['fingerprints'] ?></dd>
                    <dt class="col-7" data-i18n="page.mapPublish.events">折り込んだイベント</dt>
                    <dd class="col-5 text-end">
                      <?php if ((int) $published['events'] === 0): ?>
                        <span class="text-body-secondary" data-i18n="page.mapPublish.eventsNone">なし</span>
                      <?php else: ?>
                        <?= (int) $published['events'] ?>
                      <?php endif; ?>
                    </dd>
                    <dt class="col-7" data-i18n="page.mapPublish.bytes">大きさ</dt>
                    <dd class="col-5 text-end"><?= number_format($published['bytes'] / 1024, 1) ?> KB</dd>
                  </dl>

                  <?php
                  /*
                   * **落ちたものは必ず出す。** 端末側では「その地点が無い」としか
                   * 見えないので、ここで言わないと誰も気づけない。
                   */
                  $skipped = $published['skipped'];
                  $eventSkipped = $published['eventSkipped'];
                  $hasSkipped = ($skipped['unknownType'] ?? []) !== []
                      || ($skipped['unknownFloor'] ?? []) !== []
                      || (int) ($skipped['danglingEdge'] ?? 0) > 0
                      || (int) ($skipped['crossFloorEdge'] ?? 0) > 0
                      /*
                       * イベント側で対応づかなかったもの。**別に数えて出す** ——
                       * 地点を作り直すと、Web で指定した通行止めが静かに外れる。
                       */
                      || (int) ($eventSkipped['unknownNode'] ?? 0) > 0
                      || (int) ($eventSkipped['edgeAcrossFloors'] ?? 0) > 0
                      || ($eventSkipped['unknownFloor'] ?? []) !== [];
                  ?>
                  <?php if ($hasSkipped): ?>
                    <div class="alert alert-warning mt-3 mb-0" role="alert">
                      <strong data-i18n="page.mapPublish.skippedTitle">配信に載らなかったものがあります。</strong>
                      <ul class="mb-0 mt-2 fs-7">
                        <?php foreach (($skipped['unknownType'] ?? []) as $type => $count): ?>
                          <li>
                            <span data-i18n="page.mapPublish.skippedType">知らない種類</span>
                            <code><?= km_e((string) $type) ?></code> <?= (int) $count ?> 件
                          </li>
                        <?php endforeach; ?>
                        <?php if (($skipped['unknownFloor'] ?? []) !== []): ?>
                          <li>
                            <span data-i18n="page.mapPublish.skippedFloor">知らない階</span>
                            <?= km_e(implode(' / ', $skipped['unknownFloor'])) ?>
                          </li>
                        <?php endif; ?>
                        <?php if ((int) ($skipped['danglingEdge'] ?? 0) > 0): ?>
                          <li>
                            <span data-i18n="page.mapPublish.skippedDangling">端点を失った経路</span>
                            <?= (int) $skipped['danglingEdge'] ?> 本
                          </li>
                        <?php endif; ?>
                        <?php if ((int) ($skipped['crossFloorEdge'] ?? 0) > 0): ?>
                          <li>
                            <span data-i18n="page.mapPublish.skippedCrossFloor">階をまたぐ経路</span>
                            <?= (int) $skipped['crossFloorEdge'] ?> 本
                          </li>
                        <?php endif; ?>
                        <?php if ((int) ($eventSkipped['unknownNode'] ?? 0) > 0): ?>
                          <li class="text-danger">
                            <span data-i18n="page.mapPublish.skippedEventNode">配信する地図に無い地点の通行止め</span>
                            <?= (int) $eventSkipped['unknownNode'] ?> 件
                          </li>
                        <?php endif; ?>
                        <?php if ((int) ($eventSkipped['edgeAcrossFloors'] ?? 0) > 0): ?>
                          <li>
                            <span data-i18n="page.mapPublish.skippedEventEdge">階をまたぐ通行止め</span>
                            <?= (int) $eventSkipped['edgeAcrossFloors'] ?> 件
                          </li>
                        <?php endif; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>

            <!--begin::MainRelease-->
            <h2 class="h5 mt-2 mb-1">
              <span data-i18n="page.mapPublish.mainTitle">Website の正本</span>
              <code class="ms-2 fs-6"><?= km_e($mainSlug) ?></code>
            </h2>
            <p class="text-body-secondary fs-7" data-i18n="page.mapPublish.mainHint">
              この Website が持っている地図から配信用のファイルを作ります。
              アプリ側で直したものは「アプリの地図を取り込む」で先に戻してください。
            </p>
            <div class="row">
              <div class="col-12 col-xl-6">
                <?php $renderCurrent($mainSlug); ?>
              </div>

              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header">
                    <h4 class="card-title" data-i18n="page.mapPublish.publishTitle">配信する</h4>
                  </div>
                  <div class="card-body">
                    <form method="post"
                          data-km-confirm="page.mapPublish.confirmPublish"
                          data-km-confirm-fallback="この Website の地図を配信します。端末は次の取得で置き換わります。よろしいですか?">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="do_publish" value="publish">

                      <?php
                      /*
                       * **イベントは入力させない。**
                       *
                       * 以前はここに `web-overlay` を手で打たせていたが、
                       * 打ち間違えると端末は「そのイベントは無い」状態になり、
                       * しかも**配信は成功したように見える。**
                       * いまは配信のときに「イベント」画面の内容を折り込み、
                       * その結果から自動で決める。
                       */
                      ?>
                      <div class="mb-3">
                        <span class="form-label d-block" data-i18n="page.mapPublish.eventLabel">
                          会期中のイベント
                        </span>
                        <p class="form-control-plaintext mb-0">
                          <?php if ($activeEvents === []): ?>
                            <span class="text-body-secondary" data-i18n="page.mapPublish.eventNone">
                              いま有効なイベントはありません(通常の地図を配ります)。
                            </span>
                          <?php else: ?>
                            <?= km_e(implode(' / ', $activeEvents)) ?>
                          <?php endif; ?>
                        </p>
                        <div class="form-text" data-i18n="page.mapPublish.eventHint">
                          「イベント」画面の内容を、配信のときに地図へ折り込みます。
                          会期が終わっていれば、前回の通行止めは空で上書きされます。
                        </div>
                      </div>

                      <?php $renderExpiryAndChecksum($mainSlug); ?>

                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-broadcast me-1" aria-hidden="true"></i>
                        <span data-i18n="page.mapPublish.publishButton">この内容で配信する</span>
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <?php $renderCodes($mainSlug); ?>
            <!--end::MainRelease-->

            <!--begin::EventRelease-->
            <h2 class="h5 mt-4 mb-1">
              <span data-i18n="page.mapPublish.eventTitle">イベント用の正本</span>
              <code class="ms-2 fs-6"><?= km_e($eventSlug) ?></code>
            </h2>
            <p class="text-body-secondary fs-7" data-i18n="page.mapPublish.eventIntro">
              管理アプリで書き出した地図(JSON)を添付して配ります。Website の地図は書き換えません。
              会期だけの地図を配りたいときに使います。
            </p>
            <div class="row">
              <div class="col-12 col-xl-6">
                <?php $renderCurrent($eventSlug); ?>
              </div>

              <div class="col-12 col-xl-6">
                <div class="card mb-4">
                  <div class="card-header">
                    <h4 class="card-title" data-i18n="page.mapPublish.publishTitle">配信する</h4>
                  </div>
                  <div class="card-body">
                    <form method="post" enctype="multipart/form-data"
                          data-km-confirm="page.mapPublish.confirmPublishEvent"
                          data-km-confirm-fallback="添付した地図をイベント用として配信します。このコードを入れた端末は次の取得で置き換わります。よろしいですか?">
                      <?= km_csrf_field() ?>
                      <input type="hidden" name="do_publish" value="publish_event">

                      <div class="mb-3">
                        <label class="form-label" for="event_json"
                               data-i18n="page.mapPublish.eventFileLabel">地図ファイル(JSON)</label>
                        <input class="form-control" type="file" id="event_json" name="event_json"
                               accept="application/json,.json" required>
                        <div class="form-text" data-i18n="page.mapPublish.eventFileHint">
                          管理アプリの書き出し(kosenmap-map)。10MB まで。地点が1件も無いものは受け付けません。
                        </div>
                      </div>

                      <div class="form-check mb-3">
                        <?php // 既定はオフ。添付する地図のイベントは、多くはアプリで作ってある ?>
                        <input class="form-check-input" type="checkbox" value="1" id="fold_web_events" name="fold_web_events">
                        <label class="form-check-label" for="fold_web_events"
                               data-i18n="page.mapPublish.eventFold">Website のイベント(通行止め)も折り込む</label>
                        <div class="form-text" data-i18n="page.mapPublish.eventFoldHint">
                          オフのときは、ファイルに入っているイベントをそのまま配ります。
                          オンにすると「イベント」画面の内容で置き換えます。
                        </div>
                      </div>

                      <?php $renderExpiryAndChecksum($eventSlug); ?>

                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1" aria-hidden="true"></i>
                        <span data-i18n="page.mapPublish.eventPublishButton">添付した地図を配信する</span>
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <?php $renderCodes($eventSlug); ?>
            <!--end::EventRelease-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
        <?php /* インラインの onsubmit / onclick は CSP に弾かれる。nonce 付きの script から登録する */ ?>
        <script<?= km_csp_nonce_attr() ?>>
          (() => {
            'use strict';
            const t = (key, fallback) => (window.KmI18n ? window.KmI18n.t(key) : fallback) || fallback;

            // 押す前に確かめる。辞書に無ければ t() はキーを返すので、そのときは日本語の原文を出す
            document.querySelectorAll('form[data-km-confirm]').forEach((form) => {
              form.addEventListener('submit', (event) => {
                const key = form.dataset.kmConfirm || '';
                const fallback = form.dataset.kmConfirmFallback || '';
                const message = t(key, fallback);
                if (!window.confirm(message === key ? fallback : message)) {
                  event.preventDefault();
                }
              });
            });

            // 伏せたアクセスコードを、押したときだけ出す(コードそのものは翻訳しないので textContent でよい)
            document.querySelectorAll('.km-code-toggle').forEach((button) => {
              button.addEventListener('click', () => {
                const target = button.previousElementSibling;
                if (!target || !target.dataset.code) { return; }
                const shown = target.dataset.shown === '1';
                target.textContent = shown ? '•'.repeat(target.dataset.code.length) : target.dataset.code;
                target.dataset.shown = shown ? '0' : '1';
              });
            });
          })();
        </script>
<?php require __DIR__ . '/_inc/partials/footer.php'; ?>
