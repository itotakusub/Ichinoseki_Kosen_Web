<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/*
 * アプリへ配信中の地図の詳しい状態と、停止・再開・削除(2026-11-02 に downloads.php から移した。
 * 利用者の指示「無理やり配置した項目を的確な場所へ」—— 配信のことは「地図の配信」の画面 1 か所で済むように)。
 *
 * 読み込む側(admin/map-publish.php)が $mapReleases(km_app_map_release_report)と
 * $mapEventWindow(Website の次に効く会期。無ければ null)を用意する。
 * フォームは action を書かない —— 読み込んだページへ送り、そのページが map_action を受ける。
 */
?>            <?php if ($mapReleases !== []): ?>
              <!--begin::MapRelease-->
              <div class="card mb-4" id="map-releases">
                <div class="card-header">
                  <h3 class="card-title" data-i18n="page.downloads.mapReleaseTitle">アプリへ配信中の地図</h3>
                </div>
                <!-- /.card-header -->
                <div class="card-body">
                  <p class="text-body-secondary fs-7" data-i18n="page.downloads.mapReleaseHint">
                    期限を過ぎると、来場者アプリは地図とアクセスコードを削除します。
                    会期に合わせて短く保つのが正しい運用なので、切れる前に更新してください。
                  </p>
                  <?php foreach ($mapReleases as $release): ?>
                    <?php
                      $status = $release['status'] ?? null;
                      $file = $release['file'];
                      $summary = $release['summary'];
                      $event = $summary['activeEvent'];
                      $fmt = static fn (?string $iso): string =>
                          $iso === null ? '—' : date('Y-m-d H:i', (int) strtotime($iso));
                    ?>
                    <div class="border rounded p-3 mb-3">
                      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <code class="fs-6"><?= km_e($release['slug']) ?></code>
                        <?php
                        /*
                         * **どちらの正本かを並べて出す**(2026-09-14 に2つに分けた)。
                         * 止める・消すのは配信 ID ごとなので、取り違えるとイベント中に Website の地図を止める。
                         */
                        ?>
                        <?php if (($release['role'] ?? '') === 'main'): ?>
                          <span class="badge text-bg-primary" data-i18n="page.downloads.mapReleaseRoleMain">Website の正本</span>
                        <?php elseif (($release['role'] ?? '') === 'event'): ?>
                          <span class="badge text-bg-info" data-i18n="page.downloads.mapReleaseRoleEvent">イベント用の正本</span>
                        <?php endif; ?>
                        <span class="badge text-bg-secondary">
                          <span data-i18n="page.downloads.mapReleaseRevision">版</span>
                          <?= (int) ($status['revision'] ?? 0) ?>
                        </span>
                        <?php if ($release['paused']): ?>
                          <?php
                          /*
                           * **止まっていることを、期限より先に言う。**
                           * 期限が残っていても配られないので、「配信中(あと26日)」と
                           * 出ていたら**止めたつもりの人が騙される。**
                           */
                          ?>
                          <span class="badge text-bg-dark" data-i18n="page.downloads.mapReleasePaused">
                            配信を停止中
                          </span>
                        <?php elseif ($status === null || $status['unknown']): ?>
                          <?php // 読めない値を「大丈夫」と扱わない。配信が止まっていても画面が無言になる ?>
                          <span class="badge text-bg-danger" data-i18n="page.downloads.mapReleaseUnknown">
                            期限を読めません
                          </span>
                        <?php elseif ($status['expired']): ?>
                          <span class="badge text-bg-danger" data-i18n="page.downloads.mapReleaseExpired">
                            期限切れ
                          </span>
                        <?php elseif ($status['warn']): ?>
                          <span class="badge text-bg-warning">
                            <span data-i18n="page.downloads.mapReleaseSoon">まもなく期限</span>
                            (<?= (int) $status['daysLeft'] ?>)
                          </span>
                        <?php else: ?>
                          <span class="badge text-bg-success">
                            <span data-i18n="page.downloads.mapReleaseOk">配信中</span>
                            (<?= (int) $status['daysLeft'] ?>)
                          </span>
                        <?php endif; ?>
                      </div>

                      <div class="row g-3">
                        <?php
                        /*
                         * **設定と実体を並べて出す。** 片方だけ見せると、いま疑っている
                         * 「設定は新しいのに中身は古い」を見逃す。
                         */
                        ?>
                        <div class="col-12 col-lg-4">
                          <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseConfig">配信の設定</p>
                          <dl class="row fs-7 mb-0">
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseExpires">期限</dt>
                            <dd class="col-7 mb-1"><?= km_e($fmt($status['expiresAt'] ?? null)) ?></dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseChecksum">チェックサム</dt>
                            <dd class="col-7 mb-1">
                              <?php if ($release['checksumEnabled']): ?>
                                <span data-i18n="page.downloads.mapReleaseOn">あり</span>
                              <?php else: ?>
                                <span class="text-warning-emphasis" data-i18n="page.downloads.mapReleaseOff">なし</span>
                              <?php endif; ?>
                            </dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseCodes">アクセスコード</dt>
                            <dd class="col-7 mb-0">
                              <?php if ($release['codeCount'] === 0): ?>
                                <?php // コードが無いと、地図はあっても誰も入れられない ?>
                                <span class="text-danger-emphasis">
                                  0 (<span data-i18n="page.downloads.mapReleaseNoCode">誰も入れません</span>)
                                </span>
                              <?php elseif ($release['code'] === null): ?>
                                <?php
                                /*
                                 * 古い設定には平文が入っていない。**「無い」と言い切る。**
                                 * ここを空欄にすると「読めていないのか、設定が古いのか」が
                                 * 分からず、コードを探しに行く先も分からない。
                                 */
                                ?>
                                <?= (int) $release['codeCount'] ?>
                                <span class="text-body-secondary fs-7 d-block" data-i18n="page.downloads.mapReleaseCodeUnsaved">
                                  コードは保存されていません(次回のリリースから表示できます)
                                </span>
                              <?php else: ?>
                                <?php
                                /*
                                 * **既定では伏せる。** この画面は会期中に人に見せながら
                                 * 開くことがある(配信の状態を確かめる場面)。
                                 * コードは来場者へ配る前提の共有情報だが、
                                 * 出しっぱなしにしてよい理由にはならない。
                                 *
                                 * 伏せ字と実物の**両方を最初から置いて、表示を切り替える**。
                                 * 押したときに取りに行く作りにすると、通信が要るうえ
                                 * 「押しても出ない」場面が増える。
                                 */
                                ?>
                                <span class="km-code" data-km-code-mask>
                                  <?= km_e(str_repeat('•', mb_strlen($release['code']))) ?>
                                </span>
                                <code class="km-code" data-km-code-value hidden><?= km_e($release['code']) ?></code>
                                <?php
                                /*
                                 * ボタンの文字は**2つの span を入れ替える**。
                                 * JS で textContent を書き換えると、翻訳が当たったあとに
                                 * 日本語で上書きしてしまう(英語表示で押すと日本語に化ける)。
                                 * span ごとに data-i18n を持たせれば、どちらの言語でも成立する。
                                 */
                                ?>
                                <button
                                  type="button"
                                  class="btn btn-link btn-sm p-0 ms-1 align-baseline"
                                  data-km-code-toggle
                                >
                                  <span data-km-code-label-show data-i18n="page.downloads.mapReleaseCodeShow">表示</span>
                                  <span data-km-code-label-hide data-i18n="page.downloads.mapReleaseCodeHide" hidden>隠す</span>
                                </button>
                                <?php if ($release['codeCount'] > 1): ?>
                                  <span class="text-body-secondary fs-7 d-block">
                                    <span data-i18n="page.downloads.mapReleaseCodeMany">この配信には複数のコードがあります</span>
                                    (<?= (int) $release['codeCount'] ?>)
                                  </span>
                                <?php endif; ?>
                              <?php endif; ?>
                            </dd>
                          </dl>
                        </div>

                        <div class="col-12 col-lg-4">
                          <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseFile">置いてあるファイル</p>
                          <dl class="row fs-7 mb-0">
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileName">ファイル</dt>
                            <dd class="col-7 mb-1 text-break"><?= km_e($file['fileName']) ?></dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileSize">大きさ</dt>
                            <dd class="col-7 mb-1">
                              <?= $file['sizeBytes'] === null ? '—' : km_e(km_upload_format_size((int) $file['sizeBytes'])) ?>
                            </dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileTime">更新</dt>
                            <dd class="col-7 mb-1"><?= km_e($fmt($file['modifiedAt'])) ?></dd>
                            <dt class="col-5 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFileHash">SHA-256</dt>
                            <dd class="col-7 mb-0">
                              <?php if ($file['sha256'] === null): ?>
                                —
                              <?php else: ?>
                                <code class="fs-7"><?= km_e(substr($file['sha256'], 0, 16)) ?></code>
                              <?php endif; ?>
                            </dd>
                          </dl>
                          <?php if ($file['error'] !== null): ?>
                            <p class="text-danger-emphasis fs-7 mb-0 mt-1"><?= km_e($file['error']) ?></p>
                          <?php endif; ?>
                        </div>

                        <div class="col-12 col-lg-4">
                          <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseContent">中身</p>
                          <?php if (!$summary['readable']): ?>
                            <p class="text-danger-emphasis fs-7 mb-0" data-i18n="page.downloads.mapReleaseUnreadable">
                              読めないため、中身を確かめられません。
                            </p>
                          <?php else: ?>
                            <dl class="row fs-7 mb-0">
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseNodes">地点</dt>
                              <dd class="col-5 mb-1"><?= (int) $summary['nodeCount'] ?></dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseLines">経路</dt>
                              <dd class="col-5 mb-1"><?= (int) $summary['lineCount'] ?></dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseOccupants">教職員氏名</dt>
                              <dd class="col-5 mb-1">
                                <?php // 0 は「氏名が失われた」合図。配信では取り除かれるが、原本には入っている ?>
                                <span class="<?= $summary['occupantCount'] === 0 ? 'text-warning-emphasis' : '' ?>">
                                  <?= (int) $summary['occupantCount'] ?>
                                </span>
                              </dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseFingerprints">Wi-Fi 指紋</dt>
                              <dd class="col-5 mb-1"><?= (int) $summary['fingerprintCount'] ?></dd>
                              <dt class="col-7 fw-normal text-body-secondary" data-i18n="page.downloads.mapReleaseMapVersion">地図の版</dt>
                              <dd class="col-5 mb-0"><?= $summary['mapVersion'] === null ? '—' : (int) $summary['mapVersion'] ?></dd>
                            </dl>
                          <?php endif; ?>
                        </div>
                      </div>

                      <?php
                      /*
                       * 載せているイベント。**「終わったイベントが載ったまま」を見つけるためのもの。**
                       * アプリ側は期間外を黙って無効にするので、設定した側からは
                       * 「効いていない」のか「終わっている」のか分からない。
                       */
                      ?>
                      <div class="border-top mt-3 pt-3">
                        <p class="fw-semibold fs-7 mb-1" data-i18n="page.downloads.mapReleaseEvent">配信に載せているイベント</p>
                        <?php if ($event === null): ?>
                          <p class="text-body-secondary fs-7 mb-0">
                            <span data-i18n="page.downloads.mapReleaseNoEvent">指定していません</span>
                            (<span data-i18n="page.downloads.mapReleaseEventCount">地図が持つイベント</span>:
                            <?= (int) $summary['eventCount'] ?>)
                          </p>
                          <?php if ($mapEventWindow !== null): ?>
                            <?php /* Website にはイベントがあるのに、配信には載っていない。一般向けアプリでは何も効かない */ ?>
                            <p class="text-danger-emphasis fs-7 mb-0 mt-1">
                              <span data-i18n="page.downloads.mapReleaseEventStale">
                                Website のイベントが配信に載っていません。一般向けアプリでは効きません。配信し直してください。
                              </span>
                              <span class="text-body-secondary">(<?= km_e($mapEventWindow['names']) ?>)</span>
                            </p>
                          <?php endif; ?>
                        <?php elseif ($event['missing']): ?>
                          <p class="text-danger-emphasis fs-7 mb-0">
                            <span data-i18n="page.downloads.mapReleaseEventMissing">
                              指定された UUID のイベントが、配信中の地図にありません。
                            </span>
                            <code><?= km_e($event['uuid']) ?></code>
                          </p>
                        <?php else: ?>
                          <p class="fs-7 mb-0">
                            <?= km_e((string) $event['name']) ?>
                            <span class="text-body-secondary">
                              / <?= km_e($fmt($event['startAt'])) ?> 〜 <?= km_e($fmt($event['endAt'])) ?>
                            </span>
                            <?php if ($event['ended']): ?>
                              <span class="badge text-bg-danger ms-1" data-i18n="page.downloads.mapReleaseEventEnded">
                                会期は終了しています
                              </span>
                            <?php elseif ($event['notStarted']): ?>
                              <span class="badge text-bg-secondary ms-1" data-i18n="page.downloads.mapReleaseEventPending">
                                会期前
                              </span>
                            <?php else: ?>
                              <span class="badge text-bg-success ms-1" data-i18n="page.downloads.mapReleaseEventActive">
                                会期中
                              </span>
                            <?php endif; ?>
                          </p>
                          <?php
                          /*
                           * 中身の件数(2026-10-24、利用者の指摘「一般向けアプリで臨時の名前になっていない」)。
                           * 「イベントは載っているのに名前が置き換わらない」とき、**載っているのが空のイベント**なのかどうかを
                           * ここで言い切る。臨時の名前が 0 なら、配信し直せば入る
                           */
                          ?>
                          <p class="fs-7 mb-0 text-body-secondary">
                            <span data-i18n="page.downloads.mapReleaseEventNames">臨時の名前</span>
                            <?= (int) $event['nameCount'] ?> /
                            <span data-i18n="page.downloads.mapReleaseEventPlaces">臨時の地点</span>
                            <?= (int) $event['placeCount'] ?> /
                            <span data-i18n="page.downloads.mapReleaseEventClosures">通行止め</span>
                            <?= (int) $event['closureCount'] ?> /
                            <span data-i18n="page.downloads.mapReleaseEventHidden">出さない地点</span>
                            <?= (int) $event['hiddenCount'] ?>
                          </p>
                          <?php if ((int) $event['nameCount'] === 0 && $mapEventWindow !== null): ?>
                            <p class="text-danger-emphasis fs-7 mb-0">
                              <span data-i18n="page.downloads.mapReleaseEventEmpty">
                                配信に載っているイベントに臨時の名前が 1 件も入っていません。配信し直してください。
                              </span>
                            </p>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>

                      <?php
                      /*
                       * 停止 / 再開 / 削除。
                       *
                       * **止めても、既に受け取った端末の地図は消えない。**
                       * あちらは自分が持っている期限で判断しており、
                       * サーバーの 410 は「取得に失敗した」としか扱わない。
                       * ここを書いておかないと「止めたのに使われている」と見えて、
                       * **もう一度止めようとして設定を壊す。**
                       */
                      ?>
                      <div class="border-top mt-3 pt-3 d-flex flex-wrap gap-2 align-items-start">
                        <form method="post" class="d-inline">
                          <?= km_csrf_field() ?>
                          <input type="hidden" name="map_slug" value="<?= km_e($release['slug']) ?>" />
                          <?php if ($release['paused']): ?>
                            <button type="submit" name="map_action" value="resume" class="btn btn-outline-success btn-sm">
                              <i class="bi bi-play-fill me-1" aria-hidden="true"></i>
                              <span data-i18n="page.downloads.mapReleaseResume">配信を再開する</span>
                            </button>
                          <?php else: ?>
                            <button type="submit" name="map_action" value="pause" class="btn btn-outline-secondary btn-sm">
                              <i class="bi bi-pause-fill me-1" aria-hidden="true"></i>
                              <span data-i18n="page.downloads.mapReleasePause">配信を停止する</span>
                            </button>
                          <?php endif; ?>
                        </form>

                        <details class="ms-auto">
                          <summary class="btn btn-outline-danger btn-sm">
                            <span data-i18n="page.downloads.mapReleaseDelete">配信を削除する</span>
                          </summary>
                          <div class="mt-2 p-3 border border-danger-subtle rounded">
                            <p class="fs-7 mb-2" data-i18n="page.downloads.mapReleaseDeleteWarn">
                              設定・地図の実体・この配信のアクセスコードを消します。元に戻せません。
                              既に受け取った端末の地図は、それぞれの期限が切れるまで残ります。
                            </p>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                              <?= km_csrf_field() ?>
                              <input type="hidden" name="map_slug" value="<?= km_e($release['slug']) ?>" />
                              <?php
                              /*
                               * **配信 ID を打たせる。** チェックボックスだと、
                               * 押し慣れた場所を反射で押して消える。打つ手間があれば、
                               * 少なくとも「どれを消すのか」は読むことになる。
                               */
                              ?>
                              <label class="fs-7 mb-0" for="km-del-<?= km_e($release['slug']) ?>">
                                <span data-i18n="page.downloads.mapReleaseDeleteConfirm">確認のため</span>
                                <code><?= km_e($release['slug']) ?></code>
                                <span data-i18n="page.downloads.mapReleaseDeleteType">と入力</span>
                              </label>
                              <input
                                type="text"
                                class="form-control form-control-sm km-w-12rem"
                                id="km-del-<?= km_e($release['slug']) ?>"
                                name="confirm_slug"
                                autocomplete="off"
                                required
                              />
                              <button type="submit" name="map_action" value="delete" class="btn btn-danger btn-sm">
                                <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                <span data-i18n="page.downloads.mapReleaseDeleteButton">削除する</span>
                              </button>
                            </form>
                          </div>
                        </details>
                      </div>

                      <?php if ($release['paused']): ?>
                        <div class="alert alert-dark fs-7 mt-3 mb-0" role="alert" data-i18n="page.downloads.mapReleasePausedHint">
                          停止中は、新しくコードを入れた端末が地図を受け取れません。
                          既に受け取った端末からは消えません(それぞれの期限まで使えます)。
                          取り上げるには、期限を縮めて配信し直してください。
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>

                  <?php
                    // 期限だけでなく、**実体が読めない場合も更新の手順を出す**
                    $needsRenew = false;
                    foreach ($mapReleases as $release) {
                        if (($release['status']['warn'] ?? true) === true
                            || $release['file']['error'] !== null
                            || ($release['summary']['activeEvent']['ended'] ?? false) === true) {
                            $needsRenew = true;
                        }
                    }
                  ?>
                  <?php if ($needsRenew): ?>
                    <?php
                    /*
                     * **更新の手順をここに置く。** この画面から地図は差し替えられない
                     * (地図の実体と config/app-map.local.php は配備対象外で、
                     * ホスト側が正本)。行き先を示さないと、気づいても何もできない。
                     *
                     * 以前は手元の `new-map-release.ps1 -UploadToHost` を案内していた。
                     * 配信は 2026-09-03 から管理画面で行う(2つの正本とも)ので、そちらへ案内する。
                     */
                    ?>
                    <div class="alert alert-warning fs-7 mt-3 mb-0" role="alert">
                      <strong data-i18n="page.downloads.mapReleaseRenew">更新するには</strong>
                      <p class="mb-1 mt-2" data-i18n="page.downloads.mapReleaseRenewHint">
                        「アプリへ地図を配信する」の画面で、期限を選び直して配信し直してください(アクセスコードは据え置き)。
                      </p>
                      <a class="btn btn-sm btn-outline-dark" href="#republish-top" data-i18n="page.downloads.mapReleaseRenewLink">
                        アプリへ地図を配信する
                      </a>
                    </div>
                  <?php endif; ?>
                </div>
                <!-- /.card-body -->
              </div>
              <!--end::MapRelease-->
            <?php endif; ?>
        <?php if ($mapReleases !== []): ?>
          <script<?= km_csp_nonce_attr() ?>>
            (() => {
              /*
               * アクセスコードの表示切り替え。**通信はしない。**
               * 伏せ字と実物は最初から HTML に在り、hidden を入れ替えるだけ。
               * 取りに行く作りにすると、通信が要るうえ「押しても出ない」場面が増える。
               *
               * style.display ではなく hidden 属性を使う —— CSP で style 属性が
               * 使えないので、JS から style を触る形はこの現場では素直でない。
               */
              document.querySelectorAll('[data-km-code-toggle]').forEach((button) => {
                const box = button.closest('dd');
                if (!box) return;
                const mask = box.querySelector('[data-km-code-mask]');
                const value = box.querySelector('[data-km-code-value]');
                const labelShow = button.querySelector('[data-km-code-label-show]');
                const labelHide = button.querySelector('[data-km-code-label-hide]');
                if (!mask || !value || !labelShow || !labelHide) return;

                const hide = () => {
                  value.hidden = true;
                  mask.hidden = false;
                  labelShow.hidden = false;
                  labelHide.hidden = true;
                };

                button.addEventListener('click', () => {
                  if (!value.hidden) {
                    hide();
                    return;
                  }
                  value.hidden = false;
                  mask.hidden = true;
                  labelShow.hidden = true;
                  labelHide.hidden = false;
                  // 出しっぱなしにしない。**押した本人が忘れても戻る**
                  window.setTimeout(hide, 30000);
                });
              });
            })();
          </script>
        <?php endif; ?>
