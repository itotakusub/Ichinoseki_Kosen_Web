<?php

declare(strict_types=1);

/**
 * AdminLTE が**実行時に差し込む `<style>`** の中身から、CSP 用の SHA-256 を求める CLI。
 *
 *   docker compose exec -T web php scripts/csp-style-hash.php
 *
 * ## なぜ要るのか
 *
 * `adminlte.js` の `respectReducedMotion()` は、利用者の OS が
 * 「視差効果を減らす」設定のときだけ `<style id="adminlte-reduce-motion">` を作って
 * head へ差し込む。**JS が作った `<style>` 要素も CSP の style-src で検査される**ので、
 * `'unsafe-inline'` を外すとここが弾かれる。
 *
 * AdminLTE は無編集で使う方針なので nonce は付けられない。**中身が固定文字列**である
 * ことを利用して、その内容のハッシュを style-src に許可する形にした。
 *
 * ## 注意
 *
 * **AdminLTE を更新するとハッシュがずれる。** ずれても普段の画面では何も起きず、
 * 「視差効果を減らす」を有効にしている利用者の環境でだけ CSP 違反になる —
 * 気付きにくいので、更新したらこのスクリプトを回して lib/csp.php の定数を更新すること。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/csp.php';

$path = __DIR__ . '/../admin/vendor/adminlte/js/adminlte.js';
if (!is_file($path)) {
    fwrite(STDERR, "adminlte.js が見つかりません: {$path}\n");
    exit(1);
}

$source = (string) file_get_contents($path);

/*
 * `style.textContent = ` ... `;` のテンプレートリテラルを、**バイトそのまま**取り出す。
 * 改行コードも含めてブラウザが見るものと一致させる必要があるため、正規化しない。
 */
if (preg_match('/style\.textContent\s*=\s*`(.*?)`;/s', $source, $matches) !== 1) {
    fwrite(STDERR, "style.textContent のテンプレートリテラルが見つかりません。\n");
    fwrite(STDERR, "AdminLTE の実装が変わった可能性があります。手で確認してください。\n");
    exit(1);
}

$content = $matches[1];
$hash = 'sha256-' . base64_encode(hash('sha256', $content, true));

echo "対象: {$path}\n";
echo '長さ: ' . strlen($content) . " バイト\n";
echo '改行: ' . (str_contains($content, "\r\n") ? 'CRLF' : 'LF') . "\n\n";
echo "--- 中身 ---\n{$content}\n--- ここまで ---\n\n";
echo "style-src に載せる値:\n  '{$hash}'\n\n";

if (defined('KM_CSP_ADMINLTE_STYLE_HASH')) {
    $current = KM_CSP_ADMINLTE_STYLE_HASH;
    if ($current === $hash) {
        echo "lib/csp.php の定数と一致しています。\n";
        exit(0);
    }

    echo "**lib/csp.php の定数と食い違っています。**\n";
    echo "  いま:   {$current}\n";
    echo "  正しい: {$hash}\n";
    echo "lib/csp.php の KM_CSP_ADMINLTE_STYLE_HASH を更新してください。\n";
    exit(1);
}

exit(0);
