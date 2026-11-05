<?php
declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/**
 * ポスター・広告の一括の出す・止める(2026-11-03、利用者の指示)。posters.php と event-setup.php が読む。
 *
 * 4 つのボタン(全て出す・全て止める・✓を付けたものを出す・止める)を 1 つのフォームにまとめ、
 * ✓は表の行に置いて `form="km-poster-bulk"` で結ぶ(admin/ranking.php と同じ形)。受けるのは読む側の
 * `form_action=poster_bulk`(lib/posters.php の km_poster_bulk_plan と km_posters_set_enabled_many)。
 * 「全部に✓」と選んだ数は assets/js/poster-bulk.js。
 *
 * $kmPosterBulkList が true なら、✓の付いた短い一覧もここで描く(セットアップの画面。posters.php は自分の表に✓を置く)。
 *
 * @var array<int, array<string, mixed>> $posters
 */
$kmPosterBulkList ??= false;
$kmPosterBulkCanWrite = (bool) ($KM_ADMIN_CAN_WRITE ?? false);
$kmPosterCount = count($posters);
$kmPosterOn = count(array_filter($posters, static fn (array $p): bool => (bool) $p['isEnabled']));
?>
<div class="km-poster-bulk" data-km-poster-bulk>
  <form method="post" id="km-poster-bulk" class="d-flex flex-wrap align-items-center gap-2">
    <?= km_csrf_field() ?>
    <input type="hidden" name="form_action" value="poster_bulk">
    <span class="fs-7 text-body-secondary me-1">
      出している <?= $kmPosterOn ?> / <?= $kmPosterCount ?> 件・✓ <span data-km-bulk-count>0</span> 件
    </span>
    <?php if ($kmPosterBulkCanWrite): ?>
      <button type="submit" name="bulk" value="show_all" class="btn btn-sm btn-outline-success" <?= $kmPosterCount === 0 ? 'disabled' : '' ?>>
        <i class="bi bi-eye me-1" aria-hidden="true"></i>全て出す
      </button>
      <button type="submit" name="bulk" value="hide_all" class="btn btn-sm btn-outline-secondary" <?= $kmPosterCount === 0 ? 'disabled' : '' ?>
              data-km-confirm="ポスター・広告を全部(<?= $kmPosterCount ?> 件)止めます。よろしいですか?">
        <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>全て止める
      </button>
      <button type="submit" name="bulk" value="show_checked" class="btn btn-sm btn-success" data-km-bulk-needs-check disabled>
        ✓を付けたものを出す
      </button>
      <button type="submit" name="bulk" value="hide_checked" class="btn btn-sm btn-secondary" data-km-bulk-needs-check disabled>
        ✓を付けたものを止める
      </button>
    <?php endif; ?>
  </form>

  <?php if ($kmPosterBulkList): ?>
    <?php if ($posters === []): ?>
      <p class="fs-7 text-body-secondary mt-2 mb-0">ポスター・広告はまだありません(<a href="./posters.php#poster-form">ポスター・広告</a>で足せます)。</p>
    <?php else: ?>
      <div class="table-responsive mt-2 km-poster-bulk-list">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th scope="col" class="km-poster-bulk-check">
                <input class="form-check-input" type="checkbox" data-km-bulk-all aria-label="全部に✓を付ける">
              </th>
              <th scope="col">見出し</th>
              <th scope="col">種類</th>
              <th scope="col">状態</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($posters as $poster): ?>
              <tr>
                <td class="km-poster-bulk-check">
                  <input class="form-check-input" type="checkbox" form="km-poster-bulk" name="ids[]" value="<?= (int) $poster['id'] ?>" data-km-bulk-item aria-label="「<?= km_e((string) $poster['title']) ?>」を選ぶ">
                </td>
                <td><?= km_e((string) $poster['title']) ?></td>
                <td class="fs-7">
                  <?php if ($poster['projectNo'] !== ''): ?>
                    <span class="badge text-bg-info">企画 <?= km_e((string) $poster['projectNo']) ?></span>
                  <?php elseif ($poster['nodeId'] === ''): ?>
                    <span class="badge text-bg-warning">広告</span>
                  <?php else: ?>
                    <span class="badge text-bg-light">地点</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($poster['isEnabled']): ?>
                    <span class="badge text-bg-success">出す</span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary">止めている</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php /* 「全部に✓」・選んだ数・✓が 0 件のときに✓の操作を押せなくする */ ?>
<script src="<?= km_e(km_asset('./assets/js/poster-bulk.js')) ?>"></script>
