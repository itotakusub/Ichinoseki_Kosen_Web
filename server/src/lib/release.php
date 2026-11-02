<?php

declare(strict_types=1);

/**
 * いま配っている版の印(2026-11-02、利用者の指示「デプロイ前のページを開いていた人に、更新を促す」)。
 *
 * 配備のあとも、開いたままのタブは古い JS・古い画面で動き続ける(押したボタンが新しい PHP と食い違う)。
 * ページに印を埋め(<meta name="km-release">)、Main/release-watch.js がときどき api/release.php に聞いて、
 * 違えば「ページを更新してください」の帯を出す。
 *
 * ## 印の作り方
 *
 * src の .php・.js・.css・.html の「相対パス・大きさ・更新時刻」から作る(中身は読まない)。
 * 配備は tar で展開するので、変わっていないファイルの更新時刻はそのまま —— **中身が同じなら同じ印**、
 * 1 つでも変われば印が変わる。web は src を bind mount で読むので、コンテナを作り直さない配備でも気付ける。
 *
 * 数えないもの: 書き込み先(uploads・cache)、設定(config。画面から変わる)、外の部品(vendor・vendor-web。
 * composer で変わるときは PHP も変わる)、CLI だけで使う scripts。
 *
 * 数えるのは 300 ほどのファイルの stat だけだが、開くたびには数えない。**30 秒だけ覚えておく**
 * (コンテナの /tmp。配備の直後 30 秒は古い印を返すことがあるが、次に聞いたときに気付く)。
 */

const KM_RELEASE_CACHE_SECONDS = 30;
const KM_RELEASE_EXTENSIONS = ['php', 'js', 'css', 'html'];
const KM_RELEASE_SKIP_DIRS = ['uploads', 'cache', 'config', 'vendor', 'vendor-web', 'scripts', 'node_modules'];

/**
 * 印を数え直す(覚えておいたものは使わない)。ファイルが 1 つも無ければ空の文字列。
 *
 * @param string $root 数える根(既定は src)
 */
function km_release_compute(string $root): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (!is_dir($root)) {
        return '';
    }
    $lines = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file, string $key, RecursiveDirectoryIterator $iterator) use ($root): bool {
                if ($iterator->hasChildren()) {
                    // 根の直下の決まった名前と、どこにあっても vendor・node_modules は数えない
                    $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
                    $name = $file->getFilename();
                    return !($relative === $name && in_array($name, KM_RELEASE_SKIP_DIRS, true))
                        && !in_array($name, ['vendor', 'node_modules'], true);
                }
                return in_array(strtolower($file->getExtension()), KM_RELEASE_EXTENSIONS, true);
            }
        )
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
        $lines[] = $relative . "\t" . $file->getSize() . "\t" . $file->getMTime();
    }
    if ($lines === []) {
        return '';
    }
    // 並び順はファイルシステムによって違う。並べ直してから数える
    sort($lines, SORT_STRING);

    return substr(hash('sha256', implode("\n", $lines)), 0, 12);
}

/**
 * いまの印(12 文字の 16 進)。30 秒は覚えておいたものを返す。数えられなければ空の文字列
 * (そのときは帯を出さない —— release-watch.js は空の印を比べない)。
 */
function km_release_id(): string
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $root = dirname(__DIR__);
    $cacheFile = sys_get_temp_dir() . '/km-release-' . substr(hash('sha256', $root), 0, 8);
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached) && preg_match('/^(\d+) ([0-9a-f]{12})$/', $cached, $m) === 1 && time() - (int) $m[1] < KM_RELEASE_CACHE_SECONDS) {
        return $memo = $m[2];
    }
    $id = km_release_compute($root);
    if ($id !== '') {
        // 書けなくても困らない(次も数えるだけ)。半端なものを読ませないよう、別名で書いてから入れ替える
        $tmp = $cacheFile . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, time() . ' ' . $id) !== false) {
            @rename($tmp, $cacheFile);
        }
    }

    return $memo = $id;
}

/**
 * ページの <head> に入れる 2 つ: 描いたときの印と、見張りのスクリプト。
 *
 * パスは**根からの絶対パス**(/Main/…)。管理画面(/admin/…)からも同じものを読む
 * (管理用のホストでも /Main/ は返す。nginx の $km_leave_admin_host)。
 */
function km_release_tags(): string
{
    require_once __DIR__ . '/assets.php';
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<meta name="km-release" content="' . $e(km_release_id()) . '">'
        . "\n    " . '<script defer src="/' . $e(km_public_asset('Main/release-watch.js')) . '"></script>';
}
