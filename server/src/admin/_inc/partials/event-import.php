<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/*
 * 管理アプリのイベントを取り込むカード(2026-11-02 にイベントモードの画面から移した。処理は _inc/event-import.php)。
 * 読み込む側が $dbError・$importPending・$importPreview・$importProjects を用意する。
 */
$fmt ??= static fn (?string $value): string => $value === null || $value === ''
    ? '—'
    : str_replace(' ', ' ', substr($value, 0, 16));
?>            <?php if ($dbError === null): ?>
            <!-- ------------------------------------------------ 管理アプリのイベントの取り込み(2026-10-14) -->
            <div class="card mb-4" id="km-event-import">
              <div class="card-header">
                <h3 class="card-title">管理アプリのイベントを取り込む</h3>
              </div>
              <div class="card-body">
                <p class="text-body-secondary fs-7">
                  管理アプリで作ったイベント(部屋の名前・表示しない地点・通行止め・一時地点)を入れます。
                  アプリの「地図の書き出し」で作ったファイルを上げてください。
                  同じイベントを取り込み直すと、中身は丸ごと入れ替わります。<strong>新しいイベントは無効のまま入る</strong>ので、期間と案内の文を確かめてから有効にしてください。
                  取り込んだイベントも「地図編集」で直せます。直した内容は、管理アプリの設定のイベントの「Website から取り込む」でアプリへ戻せます。
                </p>
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                  <?= km_csrf_field() ?>
                  <input type="hidden" name="form_action" value="import_upload">
                  <div class="col-12 col-md">
                    <input class="form-control" type="file" name="event_file" accept=".json,application/json" required>
                  </div>
                  <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-outline-primary">上げて中身を見る</button>
                  </div>
                </form>

                <?php if ($importPending['error'] !== null): ?>
                  <div class="alert alert-warning mt-3 mb-0" role="alert"><?= km_e($importPending['error']) ?></div>
                <?php endif; ?>

                <?php if ($importPreview !== []): ?>
                  <hr>
                  <h4 class="h6">
                    取り込み待ち
                    <span class="text-body-secondary fs-7">(<?= km_e($fmt($importPending['uploadedAt'])) ?> に上げたもの)</span>
                  </h4>
                  <form method="post">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="import_apply">
                    <div class="table-responsive">
                      <table class="table table-sm align-middle">
                        <thead>
                          <tr>
                            <th></th>
                            <th>イベント</th>
                            <th class="text-end">名前</th>
                            <th class="text-end">表示しない</th>
                            <th class="text-end">通行止め</th>
                            <th class="text-end">一時地点</th>
                            <th>注意</th>
                          </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($importPreview as $index => $item): ?>
                          <?php
                            $c = $item['converted'];
                            $existing = $item['existing'];
                            $skip = $c['skipped'];
                            $notes = [];
                            if ($skip['unknownNode'] > 0) {
                                $notes[] = "Website に無い地点 {$skip['unknownNode']} 件は飛ばします(先に「アプリの地図を取り込む」を)";
                            }
                            if ($skip['unknownFloor'] !== []) {
                                $notes[] = '知らない階: ' . implode(', ', array_unique($skip['unknownFloor']));
                            }
                            if ($skip['staffOnly'] > 0) {
                                $notes[] = "スタッフ限定の一時地点 {$skip['staffOnly']} 件は公開ページに出さないので入れません";
                            }
                            if ($skip['badLineKey'] > 0) {
                                $notes[] = "読めない通行止めの線 {$skip['badLineKey']} 件";
                            }
                            // Website で直した内容がある(2026-10-23。取り込むとアプリの中身で置き換わる)
                            if ($existing !== null && ($existing['webEditedAt'] ?? null) !== null) {
                                $notes[] = 'Website で直した内容(' . $fmt($existing['webEditedAt']) . ')は、取り込むとアプリの中身で置き換わります。先に管理アプリの「Website から取り込む」で戻してください';
                            }
                            $closures = count($c['closedEdges']) + count($c['closedNodes']);
                          ?>
                          <tr>
                            <td>
                              <input class="form-check-input" type="checkbox" name="uuids[]" id="km-import-<?= (int) $index ?>"
                                     value="<?= km_e($c['uuid']) ?>" checked>
                            </td>
                            <td>
                              <label for="km-import-<?= (int) $index ?>" class="fw-semibold"><?= km_e($c['name']) ?></label>
                              <div class="fs-7 text-body-secondary">
                                <?php if ($existing === null): ?>
                                  新しく作る(無効のまま)
                                <?php else: ?>
                                  「<?= km_e($existing['name']) ?>」を入れ替える(いま: 名前 <?= (int) $existing['aliasCount'] ?>・表示しない <?= (int) $existing['hiddenCount'] ?>・通行止め <?= (int) $existing['closureCount'] ?>・一時地点 <?= (int) $existing['poiCount'] ?>)
                                <?php endif; ?>
                              </div>
                            </td>
                            <td class="text-end"><?= count($c['aliases']) ?></td>
                            <td class="text-end"><?= count($c['hidden']) ?></td>
                            <td class="text-end"><?= $closures ?></td>
                            <td class="text-end"><?= count($c['pois']) ?></td>
                            <td class="fs-7"><?= $notes === [] ? '—' : km_e(implode(' / ', $notes)) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <?php if ($importProjects !== null): ?>
                      <?php // 教室などに振った企画の番号(2026-10-23。一時地点とは別に、部屋の地点ごとに持つ) ?>
                      <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="import_projects" value="1" id="km-import-projects" checked>
                        <label class="form-check-label" for="km-import-projects">
                          企画の番号も入れ替える(番号のある地点 <strong><?= (int) $importProjects['nodes'] ?></strong>・番号 <?= (int) $importProjects['numbers'] ?> 個。いま Website: <?= (int) $importProjects['current'] ?> 地点)
                        </label>
                        <div class="form-text">
                          教室などの地点に管理アプリで振った番号です。一時地点の数(上の表)とは別に数えます。番号でポスターとスケジュールの場所が決まり、「イベントの地点だけ」のときも出る地点になります。
                          <?php if ($importProjects['unknown'] > 0): ?>
                            <span class="text-danger">Website に無い地点が <?= (int) $importProjects['unknown'] ?> あります(先に上の「書き出したファイルを上げる」で地図を取り込んでください)。</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">選んだイベントを取り込む</button>
                  </form>
                  <form method="post" class="mt-2">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="form_action" value="import_discard">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">取り込まずに捨てる</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

