<?php

declare(strict_types=1);

/**
 * 地図データ(階・ノード・経路)を JSON で返す。ログイン不要(誰でも呼べる)。
 * occupant_name は km_map_names_unlocked() が true のときだけ含める。
 *
 * 公開ページ(index.php)は**このエンドポイントを呼ばない**。HTML へ同梱してある
 * (lib/map-data.php の冒頭を参照)。ここが要るのは admin/map-editor.php と、
 * 同梱に失敗したときのフォールバック。
 */

// **Cookie 属性は lib/session.php に一本化する。** ここに同じ内容を書き写していると、
// 片方だけ直したときに「リクエストごとに別の属性で Cookie を出し直す」状態になる
require_once __DIR__ . '/../lib/session.php';
km_session_start();

/*
 * セッションからはこの先「氏名を解除済みか」しか読まない。PHP は**スクリプトが
 * 終わるまでセッションファイルをロックし続ける**ので、開いたままにしておくと
 * 同じ利用者の他のリクエストがこの DB アクセスの後ろで待たされる。
 * 読み終えた時点で閉じる(閉じたあとは $_SESSION の書き込みが効かないが、
 * ここは読むだけなので問題ない)。
 */
session_write_close();

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    echo json_encode(km_map_data(km_db()), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('map-data.php failed: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'map data unavailable']);
}
