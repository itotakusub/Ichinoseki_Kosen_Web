<?php

declare(strict_types=1);

/**
 * ポスターの画像(公開ページ。2026-10-21、lib/posters.php)。**誰でも取れる。**
 *
 *   GET ?id=<ポスターの番号>&v=<版>
 *
 * 出すのは**いま公開ページに出しているものだけ** —— 有効で、出し方の 3 段階(表示しない / イベント中のみ /
 * 常に表示)が「出す」になっているとき。止めたポスターを番号で取れないようにする。
 * 実体は src/uploads/(nginx で直接の配信を塞いである)。管理画面のプレビューは admin/api/poster-image.php。
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/posters.php';

$idParam = $_GET['id'] ?? '';
// ?id[]= で配列にされると (string) が警告になり、ヘッダーより先に出力が混ざる(api/download.php と同じ)
if (!is_string($idParam) || preg_match('/^[1-9]\d{0,9}$/', $idParam) !== 1) {
    http_response_code(404);
    exit('not found');
}

try {
    $pdo = km_db();
    $poster = null;
    if (km_posters_showing_now($pdo)) {
        $statement = $pdo->prepare(
            'SELECT image_stored_name AS storedName FROM km_posters WHERE id = ? AND is_enabled = 1'
        );
        $statement->execute([(int) $idParam]);
        $row = $statement->fetch();
        $poster = $row === false ? null : $row;
    }
} catch (Throwable $exception) {
    error_log('api/poster-image.php failed: ' . $exception->getMessage());
    $poster = null;
}

if ($poster === null || !km_poster_send_image($poster, true)) {
    http_response_code(404);
    exit('not found');
}
