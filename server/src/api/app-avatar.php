<?php

declare(strict_types=1);

/**
 * Android アプリ用のアカウント画像。**一般アプリでも管理アプリでも使える。**
 *
 * admin/api/avatar.php はブラウザのセッション認証なので端末からは使えない。
 * ここは Logto の Bearer トークンで認証し、**保存処理は lib/profile.php を
 * そのまま呼ぶ**（2MB上限・ラスタ画像のみ・実体の置き場は既存のまま）。
 *
 *   GET    [?userId=<自分の sub>]   **自分の**画像を返す。未設定なら404。ログイン必須
 *   POST   multipart(avatar=画像)  自分の画像を差し替える
 *   POST   {"action":"clear"}      自分の画像を消す
 *
 * **読み書きとも、トークンの sub に対してのみ行う。**
 *
 * ## GET も本人だけにした(2026-09-14)
 *
 * 以前の GET は「一覧に他人の顔を出すため」ログインを要求せず、`?userId=<sub>` の画像を
 * 誰にでも返していた。ランキングの応答が sub を出していたので、**未認証のまま
 * 参加者全員の顔写真を集められた**(security-review-2026-09-10 の 2)。
 * 実際にアプリが取っているのは自分の画像だけ(MainActivity)なので、本人に限る。
 * 配布済みの古いアプリはトークンを付けずに取りに来るので、**自分の画像が出なくなる**
 * (アプリを更新すれば戻る)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/profile.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/app-avatar.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

// ---------------------------------------------------------------- 取得

if ($method === 'GET') {
    $principal = logto_require_principal();
    $subject = trim((string) ($principal['subject'] ?? ''));
    if ($subject === '') {
        respond(['success' => false, 'message' => 'Logtoユーザーを特定できません。'], 401);
    }
    // 古いアプリは userId に自分の sub を付けてくる。**他人の sub なら断る**
    $requested = trim((string) ($_GET['userId'] ?? ''));
    if ($requested !== '' && !hash_equals($subject, $requested)) {
        respond(['success' => false, 'message' => '他の利用者の画像は取得できません。'], 403);
    }
    $row = km_profile_find($pdo, $subject);
    $storedName = is_array($row) ? (string) ($row['avatarStoredName'] ?? '') : '';
    // DB が汚れていてもパスをはみ出させない。admin/api/avatar.php と同じ検査。
    if ($storedName === '' || preg_match('/^avatar_[0-9a-f]{32}\.(png|jpg|gif|webp)$/', $storedName) !== 1) {
        respond(['success' => false, 'message' => '画像が設定されていません。'], 404);
    }
    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path)) {
        error_log('api/app-avatar.php: 実体が見つかりません: ' . $path);
        respond(['success' => false, 'message' => '画像が設定されていません。'], 404);
    }
    // api_bootstrap.php が JSON のヘッダーを出しているので上書きする。
    // **DB の MIME をそのまま流さない。** 保存名の拡張子(上で形を確かめた)から決める
    header('Content-Type: ' . km_profile_avatar_mime($storedName));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    // 顔写真なので共有キャッシュには載せない。
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

require_method('POST');

// ---------------------------------------------------------------- 書き込み

$principal = logto_require_principal();
$subject = trim((string) ($principal['subject'] ?? ''));
if ($subject === '') {
    respond(['success' => false, 'message' => 'Logtoユーザーを特定できません。'], 401);
}

// 削除は JSON、差し替えは multipart。Content-Type で見分ける。
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
if (str_contains($contentType, 'application/json')) {
    $input = km_api_json_body();
    if (($input['action'] ?? '') !== 'clear') {
        respond(['success' => false, 'message' => '不明な操作です。'], 400);
    }
    km_profile_clear_avatar($pdo, $subject);
    respond(['success' => true, 'cleared' => true]);
}

$file = $_FILES['avatar'] ?? null;
if (!is_array($file)) {
    respond(['success' => false, 'message' => '画像が選択されていません。'], 400);
}

try {
    km_profile_store_avatar($pdo, $subject, $file);
} catch (InvalidArgumentException $exception) {
    // 利用者の入力が原因。そのまま見せてよい。
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/app-avatar.php store failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '画像を保存できませんでした。'], 500);
}

respond([
    'success' => true,
    // 端末側のキャッシュを捨てさせるための印。
    'updatedAt' => (int) (km_profile_find($pdo, $subject)['updatedAtEpoch'] ?? time()),
]);
