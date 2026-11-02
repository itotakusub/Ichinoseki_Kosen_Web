<?php

declare(strict_types=1);

/**
 * いま配っている版の印(2026-11-02。lib/release.php)。
 *
 *   GET → {"success":true,"release":"0123456789ab"}
 *
 * 開いたままのページ(Main/release-watch.js)がときどき聞き、描いたときの印と違えば「ページを更新してください」と出す。
 * 誰でも聞ける(公開ページも見張る)。返すのは印だけで、ファイルの名前や時刻は返さない。セッションも使わない。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/release.php';

require_method('GET');

respond(['success' => true, 'release' => km_release_id()]);
