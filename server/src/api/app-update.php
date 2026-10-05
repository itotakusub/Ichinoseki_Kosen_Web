<?php

declare(strict_types=1);

/**
 * アプリの更新の確認(2026-10-05、利用者の指示「自動アップデート」)。
 *
 *   GET ?flavor=visitor   一般用の最新版(誰でも)
 *   GET ?flavor=admin     管理用の最新版(**管理者のトークンが要る**)
 *
 * 返すのは管理画面の「配布物」に置いた APK の版番号・大きさ・SHA-256 と、取りに行く URL。
 * アプリは版番号が自分より大きいときだけ知らせ、取ってきた APK の SHA-256 と**署名が自分と同じか**を確かめてから
 * インストール画面を出す(AppUpdater.kt)。ここは知らせるだけで、何も書き換えない。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/distributables.php';

require_method('GET');

$flavorParam = $_GET['flavor'] ?? 'visitor';
$flavor = is_string($flavorParam) ? $flavorParam : '';
$slug = match ($flavor) {
    'visitor' => 'apk',
    'admin' => 'apk_admin',
    default => null,
};
if ($slug === null) {
    respond(['success' => false, 'message' => 'flavor は visitor か admin です。'], 400);
}

if ((KM_DISTRIBUTABLES[$slug]['requiresAdmin'] ?? false) === true) {
    require_once __DIR__ . '/../logto_config.php';
    require_once __DIR__ . '/../logto_guard.php';
    $principal = logto_require_principal();
    logto_assert_permissions($principal, LOGTO_ADMIN_PERMISSIONS);
}

try {
    $row = km_dist_find(km_db(), $slug);
} catch (Throwable $exception) {
    error_log('api/app-update.php lookup failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

if ($row === null || ($row['versionCode'] ?? null) === null || ($row['sha256'] ?? null) === null) {
    // 置いていない・版番号の無い古い登録は「更新なし」(端末は何もしない)
    respond(['success' => true, 'available' => false]);
}

respond([
    'success' => true,
    'available' => true,
    'versionCode' => (int) $row['versionCode'],
    'versionName' => $row['versionLabel'] !== null ? (string) $row['versionLabel'] : null,
    'sizeBytes' => (int) $row['sizeBytes'],
    'sha256' => (string) $row['sha256'],
    'url' => '/api/download.php?slug=' . $slug,
]);
