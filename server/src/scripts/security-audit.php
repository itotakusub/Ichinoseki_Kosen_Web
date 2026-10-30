<?php

declare(strict_types=1);

/**
 * 脆弱性を調べて 1 行ずつ出す(2026-10-30、振り返りのフェーズ 6 の 2。決まりは lib/security-audit.php)。
 * 呼ぶのは scripts/check-updates.sh(ホスト)。web の中で動く。
 *
 *     docker compose exec -T web php scripts/security-audit.php php        PHP の版(php.net に聞く)
 *     docker compose exec -T web php scripts/security-audit.php composer   composer audit(composer.lock)
 *     … | docker compose exec -T web php scripts/security-audit.php npm    npm audit の JSON を標準入力から
 *     … | docker compose exec -T web php scripts/security-audit.php usn <release>
 *                                                                          dpkg の一覧(名前と版)+ 区切り + USN の JSON を標準入力から
 *
 * 出すのは「鍵<TAB>名前<TAB>いま<TAB>直したもの」の行。聞けなければ「HOLD<TAB>理由」の行(**無いとは言わない**)。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/security-audit.php';

const KM_SECURITY_USN_SEPARATOR = '---KM-USN---';

$what = (string) ($argv[1] ?? '');

/** URL を読む(20 秒まで)。読めなければ null */
$fetch = static function (string $url): ?string {
    $context = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: KosenMap-security-audit\r\nAccept: application/json\r\n"]]);
    $body = @file_get_contents($url, false, $context);
    return is_string($body) && $body !== '' ? $body : null;
};

$emit = static function (array $items): void {
    foreach (array_slice($items, 0, KM_SECURITY_MAX_ITEMS) as $item) {
        echo km_security_line($item), "\n";
    }
};

try {
    switch ($what) {
        case 'php':
            $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $json = $fetch('https://www.php.net/releases/index.php?json&version=' . $branch);
            if ($json === null) {
                echo "HOLD\tphp.net に聞けませんでした\n";
                break;
            }
            $item = km_security_php_item(PHP_VERSION, $json);
            $emit($item === null ? [] : [$item]);
            break;

        case 'composer':
            $lock = dirname(__DIR__) . '/composer.lock';
            if (!is_file($lock) || !is_executable('/usr/local/bin/composer')) {
                echo "HOLD\tcomposer か composer.lock がありません\n";
                break;
            }
            // src は読むだけで載せてあるので、composer の置き場は /tmp へ
            $command = 'COMPOSER_HOME=/tmp/composer-audit COMPOSER_CACHE_DIR=/tmp/composer-audit/cache /usr/local/bin/composer audit --locked --format=json --no-interaction --working-dir='
                . escapeshellarg(dirname(__DIR__)) . ' 2>/dev/null';
            $output = (string) shell_exec($command);
            try {
                $emit(km_security_composer_items($output));
            } catch (InvalidArgumentException $exception) {
                echo "HOLD\tcomposer audit が答えませんでした\n";
            }
            break;

        case 'npm':
            try {
                $emit(km_security_npm_items((string) stream_get_contents(STDIN)));
            } catch (InvalidArgumentException $exception) {
                echo "HOLD\tnpm audit が答えませんでした(Soketi の /app に package-lock.json が無いかもしれません)\n";
            }
            break;

        case 'usn':
            $release = (string) ($argv[2] ?? '');
            if (preg_match('/\A[a-z]+\z/', $release) !== 1) {
                echo "HOLD\tUbuntu のリリース名が分かりません\n";
                break;
            }
            $parts = explode(KM_SECURITY_USN_SEPARATOR, (string) stream_get_contents(STDIN), 2);
            try {
                $emit(km_security_usn_items($parts[1] ?? '', $parts[0], $release));
            } catch (InvalidArgumentException $exception) {
                echo "HOLD\tUbuntu の USN を読めませんでした\n";
            }
            break;

        default:
            fwrite(STDERR, "使い方: php scripts/security-audit.php php|composer|npm|usn <release>\n");
            exit(2);
    }
} catch (Throwable $exception) {
    echo "HOLD\t" . preg_replace('/[\t\r\n]+/', ' ', $exception->getMessage()) . "\n";
}
