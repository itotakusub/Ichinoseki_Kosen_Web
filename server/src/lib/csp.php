<?php

declare(strict_types=1);

/**
 * Content-Security-Policy。**`'unsafe-inline'` を一切使わない**構成。
 *
 * 1.0.0 では `frame-ancestors` だけを nginx で付け、`script-src` は
 * 「AdminLTE がインラインの `<script>` を多用していて無編集運用と両立しない」として
 * 見送っていた。VPS でインターネットに晒す前提になったので今回入れる。
 *
 * ## なぜ nginx ではなく PHP が送るのか
 *
 * nonce はリクエストごとに変える必要があり、**HTML を書き出す側が同じ値を知っている**
 * 必要がある。nginx 単体ではその受け渡しができない。静的ファイル(画像・CSS・JS)には
 * nginx 側の控えめな既定が付く。
 *
 * ## インラインをどう扱っているか
 *
 * - インライン `<script>` … すべて `nonce="<?= km_csp_nonce() ?>"` を付けた
 * - `style="…"` 属性 … **全部やめた**(AdminLTE のユーティリティクラスか自前 CSS へ移した)。
 *   nonce は要素にしか効かず、style 属性には効かないため、残すと `'unsafe-inline'` が要る
 * - AdminLTE が実行時に差し込む `<style>` … **内容が固定**なのでハッシュで許可する
 *   (scripts/csp-style-hash.php で求める)
 *
 * ## 段階的に入れるための逃げ道
 *
 * 環境変数 `KM_CSP_REPORT_ONLY=1` のときは Content-Security-Policy-Report-Only で送る。
 * **本番でいきなり強制して画面を壊さない**ため。違反ゼロを確認してから外す。
 */

require_once __DIR__ . '/site.php';

/**
 * AdminLTE が `prefers-reduced-motion: reduce` のときだけ差し込む `<style>` の中身のハッシュ。
 *
 * **AdminLTE を更新したら `scripts/csp-style-hash.php` で取り直すこと。**
 * ずれても普段の画面では何も起きず、その設定を使っている人の環境でだけ弾かれる。
 */
const KM_CSP_ADMINLTE_STYLE_HASH = 'sha256-DfdLro2xePi/wEA0bTkPBdQ4bwjyQ17jKg1GTq5ANcc=';

/**
 * このリクエストの nonce。**1リクエスト内では同じ値**を返す(複数のインライン
 * script に別々の値を振ると、後から出した方が弾かれる)。
 */
function km_csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * インライン `<script>` に付ける属性。エスケープ済みの ` nonce="…"` を返す。
 *
 * ページごとに `km_e()` / `km_home_e()` などエスケープ関数が違うので、
 * **属性の形にしてここで完結させる**(付け忘れ・書き間違いの余地を減らす)。
 */
function km_csp_nonce_attr(): string
{
    return ' nonce="' . htmlspecialchars(km_csp_nonce(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
}

/**
 * プロファイルごとのディレクティブを組む。
 *
 * @param string $profile 'admin' | 'public' | 'contact'
 */
function km_csp_policy(string $profile): string
{
    $nonce = "'nonce-" . km_csp_nonce() . "'";

    $script = ["'self'", $nonce];

    /*
     * **style にも nonce が要る。** 1.0.1 では script-src にしか入れておらず、
     * nonce を付けた `<style>` が弾かれていた(faq.php / contact.php / map-editor.php の
     * 見た目が崩れたまま気付かなかった。コンソールを見るだけでは拾えず、
     * 実際の描画 —— 要素に sheet が付いているか —— を見て初めて分かった)。
     *
     * nonce を足しても **style 属性は許可されない**(属性には nonce もハッシュも効かない)ので、
     * 「style="…" を全廃した」1.0.1 の方針はそのまま維持される。
     */
    $style = ["'self'", $nonce];
    $connect = ["'self'"];
    $frame = ["'none'"];
    $img = ["'self'", 'data:'];

    if ($profile === 'admin') {
        // AdminLTE が差し込む reduce-motion 用の <style> を通す
        $style[] = "'" . KM_CSP_ADMINLTE_STYLE_HASH . "'";
        // チャットと死活監視がブラウザから直接 WebSocket を張る先
        $connect[] = 'wss://' . km_site_ws_host() . ':' . km_site_ws_port();
        $connect[] = 'https://' . km_site_ws_host() . ':' . km_site_ws_port();
    }

    if ($profile === 'contact') {
        /*
         * reCAPTCHA。**このプロジェクト唯一の意図的な外部依存**で、自前ホストできない。
         * スクリプトは google.com、部品は gstatic.com、チェックボックスの本体は iframe。
         */
        /*
         * **パスまで絞る(`/recaptcha/`)。** 以前は `https://www.google.com` をオリジンごと
         * 許していた(security-review-2026-09-10 の 14)。同じオリジンには JSONP を返す口が
         * 他にもあり、CSP の抜け道として知られている。reCAPTCHA が読むのは
         * `/recaptcha/api.js`・`/recaptcha/enterprise.js` と `www.gstatic.com/recaptcha/releases/…`。
         */
        $script[] = 'https://www.google.com/recaptcha/';
        $script[] = 'https://www.gstatic.com/recaptcha/';
        $frame = ['https://www.google.com/recaptcha/'];
        $style[] = 'https://www.gstatic.com/recaptcha/';
    }

    $directives = [
        "default-src 'self'",
        'script-src ' . implode(' ', $script),
        'style-src ' . implode(' ', $style),
        'img-src ' . implode(' ', $img),
        "font-src 'self'",
        'connect-src ' . implode(' ', $connect),
        'frame-src ' . implode(' ', $frame),
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ];

    return implode('; ', $directives);
}

/** レポートのみで動かすか(env で切り替える。既定は強制)。 */
function km_csp_report_only(): bool
{
    $value = getenv('KM_CSP_REPORT_ONLY');

    return is_string($value) && trim($value) === '1';
}

/**
 * ヘッダーを送る。**出力が始まる前に呼ぶこと**(HTML を書き出したあとでは効かない)。
 */
function km_csp_send(string $profile = 'admin'): void
{
    if (headers_sent()) {
        // ここに来るのは組み込み順の間違い。黙って諦めず気付けるようにする
        error_log('km_csp_send(): 既に出力が始まっているため CSP を送れませんでした。');

        return;
    }

    $header = km_csp_report_only()
        ? 'Content-Security-Policy-Report-Only'
        : 'Content-Security-Policy';

    header($header . ': ' . km_csp_policy($profile));
}
