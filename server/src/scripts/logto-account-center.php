<?php

declare(strict_types=1);

/**
 * Logto の「アカウント設定(Account API)」の状態を見て、**退会の案内先だけ**を直す。
 *
 *   php scripts/logto-account-center.php                         いまの設定を出す(何も変えない)
 *   php scripts/logto-account-center.php --fix-delete-url        退会の案内先を今のドメインに合わせる
 *   php scripts/logto-account-center.php --fix-delete-url --url=https://…/account.php
 *
 * ## なぜ要るのか(2026-09-18)
 *
 * ドメインを `ito4.jp` に統一したあとも、Logto の**退会の案内先が `https://ito8795.com/account.php`** のままだった。
 * 旧ドメインは手放すので、**そのままでは退会したい人がどこにも行けない。**
 * Console からも直せるが、**画面を開かずに確かめ直せる口**を残しておく(ドメインを変えるたびに必要になる)。
 *
 * ## 書き換えるのは 1 項目だけ
 *
 * `fields`(どの項目を本人が編集できるか)には**触れない。** 特に `customData` は `Off` でなければならない ——
 * `Edit` になっていると、**利用者が自分で「教職員」の名乗りを書ける**(docs/15 §0)。
 * ここでは状態を出して警告するだけにして、変えるのは人の判断に任せる。
 */

require_once __DIR__ . '/../lib/site.php';
require_once __DIR__ . '/../lib/logto-management.php';

$args = array_slice($argv, 1);
$fix = in_array('--fix-delete-url', $args, true);
$url = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    } elseif ($arg !== '--fix-delete-url') {
        fwrite(STDERR, "知らない引数です: {$arg}\n");
        exit(2);
    }
}

$center = km_logto_management_get('account-center', [], 'readonly');
$fields = (array) ($center['fields'] ?? []);
$current = (string) ($center['deleteAccountUrl'] ?? '');

printf("Account API   : %s\n", ($center['enabled'] ?? false) ? '有効' : '無効');
printf("本人が編集できる項目:\n");
foreach ($fields as $name => $value) {
    printf("  %-12s %s%s\n", (string) $name, (string) $value, $name === 'customData' && $value === 'Edit' ? '  ★ 本人が書ける状態です。Off か ReadOnly に戻すこと(自己申告で教職員になれる)' : '');
}
printf("退会の案内先  : %s\n", $current === '' ? '(無し)' : $current);

$want = $url ?? (km_site_url() . '/account.php');

/*
 * **こちらのサイト以外へは向けない。** 退会の導線は、利用者が「本当にここの運営か」を確かめられる場所に置く。
 * 引数で任意の URL を渡せるが、オリジンが今のサイトと違えば断る(打ち間違いで外部サイトへ送らない)。
 */
$origin = static function (string $value): string {
    $parts = parse_url($value);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return '';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return $parts['scheme'] . '://' . $parts['host'] . $port;
};

if ($origin($want) === '' || $origin($want) !== km_site_origin()) {
    fwrite(STDERR, "この URL は今のサイト({$origin(km_site_origin())})のものではありません: {$want}\n");
    exit(2);
}

if (!$fix) {
    printf("\n直すなら: php scripts/logto-account-center.php --fix-delete-url\n");
    printf("  いま    : %s\n  こうする: %s\n", $current === '' ? '(無し)' : $current, $want);
    exit(0);
}

if ($current === $want) {
    printf("\n既に %s です(変えていません)。\n", $want);
    exit(0);
}

km_logto_management_patch('account-center', ['deleteAccountUrl' => $want]);
$after = km_logto_management_get('account-center', [], 'readonly');
printf("\n直しました: %s → %s\n", $current === '' ? '(無し)' : $current, (string) ($after['deleteAccountUrl'] ?? '(読めません)'));
