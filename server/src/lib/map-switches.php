<?php

declare(strict_types=1);

/**
 * 地図を開いたときに出すもののスイッチ(2026-10-24、利用者の指示「イベント時のチュートリアルを削除。お知らせ削除」→
 * 「両方とも機能ごと ON/OFF で」)。
 *
 *   - チュートリアル(Main/tutorial.js): OFF なら読まない。引き出しの「操作の案内を見る」も出さない。
 *     広告はチュートリアルの終わりから出していたので、OFF のときは「はじめに」を閉じたときに出す(Main/welcome.js → kmShowAd)
 *   - お知らせ(lib/notices.php): OFF なら「はじめに」の先頭に出さない・引き出しの「お知らせ」も出さない・notice.php は「ありません」。
 *     管理画面の編集はそのまま使える(書き溜めて、ON にした時点で出す)
 *
 * 値は km_settings(lib/settings.php)に '1' / '0' で置く。**既定は ON**(今までどおり)。
 * 読めないときも ON に倒す(km_setting_get は失敗しても既定値を返す)。切り替えは管理画面「お知らせ」(admin/notices.php)。
 */

require_once __DIR__ . '/settings.php';

const KM_MAP_TUTORIAL_SETTING = 'map_tutorial_enabled';
const KM_MAP_NOTICES_SETTING = 'map_notices_enabled';

/** 保存した値を真偽に読む。'0' だけが OFF(空や知らない値は既定の ON) */
function km_map_switch_value(string $stored): bool
{
    return $stored !== '0';
}

/**
 * いまのスイッチ。
 *
 * @return array{tutorial:bool, notices:bool}
 */
function km_map_switches(PDO $pdo): array
{
    return [
        'tutorial' => km_map_switch_value(km_setting_get($pdo, KM_MAP_TUTORIAL_SETTING, '1')),
        'notices' => km_map_switch_value(km_setting_get($pdo, KM_MAP_NOTICES_SETTING, '1')),
    ];
}

/** スイッチを書く */
function km_map_switches_save(PDO $pdo, bool $tutorial, bool $notices): void
{
    km_setting_set($pdo, KM_MAP_TUTORIAL_SETTING, $tutorial ? '1' : '0');
    km_setting_set($pdo, KM_MAP_NOTICES_SETTING, $notices ? '1' : '0');
}
