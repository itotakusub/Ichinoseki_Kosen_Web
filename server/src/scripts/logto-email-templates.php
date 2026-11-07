<?php

declare(strict_types=1);

/**
 * Logto のメール(SMTP のコネクタ)のテンプレートを、正本 `docs/logto-email-templates.json` に合わせる(2026-11-07)。
 *
 *   php scripts/logto-email-templates.php -            正本(標準入力)といまの Logto を比べて出すだけ(既定)
 *   php scripts/logto-email-templates.php - --apply    違うものを正本どおりに書き換える
 *
 * 本番では正本(docs は配備しない)を手元から標準入力で渡す:
 *
 *   Get-Content docs\logto-email-templates.json -Raw | ssh -i ~/.ssh/km_ops kmops@ito4.jp
 *     "cd /opt/kosenmap && docker compose exec -T -u www-data web php scripts/logto-email-templates.php - --apply"
 *
 * ## なぜ要るのか
 *
 * 10-12 に 9 種類を正本にそろえた(件名の `(Verification Code)` が、iPhone・Mac の「確認コードの自動入力」の手がかりになる)が、
 * Console へ貼るのは手作業だったので、**11-07 に本番を読むと SignIn の 1 つしか入っていなかった。** 画面を開かずに合わせ直せる口を残す。
 *
 * ## 書き換えるのはテンプレートだけ
 *
 * SMTP のコネクタの設定は**まるごと**送らないと Logto が受け付けないので、いまの設定を読み、`templates` だけを入れ替えて送り返す。
 * **送信サーバー・利用者・パスワードには触れず、画面にも出さない。** 正本に無い種類(あれば)は残す。
 */

require_once __DIR__ . '/../lib/logto-management.php';

const KM_EMAIL_TEMPLATE_CONNECTOR = 'simple-mail-transfer-protocol';

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$source = null;
foreach ($args as $arg) {
    if ($arg === '--apply') {
        continue;
    }
    if ($source === null && ($arg === '-' || is_file($arg))) {
        $source = $arg;
        continue;
    }
    fwrite(STDERR, "知らない引数です: {$arg}\n");
    exit(2);
}
if ($source === null) {
    fwrite(STDERR, "正本の JSON を渡してください(- で標準入力)。\n");
    exit(2);
}

$raw = $source === '-' ? stream_get_contents(STDIN) : file_get_contents($source);
$wanted = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($wanted) || !array_is_list($wanted) || $wanted === []) {
    fwrite(STDERR, "正本を JSON の配列として読めませんでした。\n");
    exit(2);
}

/**
 * 正本の形を確かめる。種類が重ならない・件名と本文がある・本文に確認コード({{code}})がある。
 *
 * @param array<int, mixed> $list
 * @return array<string, array{usageType:string, contentType:string, subject:string, content:string}>
 */
function km_email_templates_validate(array $list): array
{
    $byType = [];
    foreach ($list as $i => $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException("{$i} 番目がオブジェクトではありません。");
        }
        $type = (string) ($item['usageType'] ?? '');
        $contentType = (string) ($item['contentType'] ?? '');
        $subject = (string) ($item['subject'] ?? '');
        $content = (string) ($item['content'] ?? '');
        if (preg_match('/^[A-Za-z]{2,40}$/', $type) !== 1) {
            throw new InvalidArgumentException("{$i} 番目の usageType が不正です。");
        }
        if (isset($byType[$type])) {
            throw new InvalidArgumentException("usageType {$type} が 2 回あります。");
        }
        if (!in_array($contentType, ['text/plain', 'text/html'], true)) {
            throw new InvalidArgumentException("{$type} の contentType が不正です。");
        }
        if (trim($subject) === '' || mb_strlen($subject) > 200) {
            throw new InvalidArgumentException("{$type} の件名が空か長すぎます。");
        }
        if (!str_contains($content, '{{code}}') || mb_strlen($content) > 5000) {
            throw new InvalidArgumentException("{$type} の本文に {{code}} が無いか、長すぎます。");
        }
        $byType[$type] = ['usageType' => $type, 'contentType' => $contentType, 'subject' => $subject, 'content' => $content];
    }

    return $byType;
}

/**
 * @param array<int, mixed> $templates
 * @return array<string, array<string, mixed>>
 */
function km_email_templates_index(array $templates): array
{
    $out = [];
    foreach ($templates as $item) {
        if (is_array($item) && isset($item['usageType'])) {
            $out[(string) $item['usageType']] = $item;
        }
    }

    return $out;
}

/** @param array<string, mixed>|null $current */
function km_email_template_same(?array $current, array $want): bool
{
    return $current !== null
        && (string) ($current['subject'] ?? '') === $want['subject']
        && (string) ($current['content'] ?? '') === $want['content']
        && (string) ($current['contentType'] ?? '') === $want['contentType'];
}

/** @return array{0: string, 1: array<string, mixed>} コネクタの id と設定 */
function km_email_templates_connector(): array
{
    $list = km_logto_management_get('connectors');
    $found = [];
    foreach ($list as $connector) {
        if (is_array($connector) && ($connector['connectorId'] ?? '') === KM_EMAIL_TEMPLATE_CONNECTOR) {
            $found[] = $connector;
        }
    }
    if (count($found) !== 1) {
        throw new RuntimeException('SMTP のコネクタが 1 つではありません(' . count($found) . ' 個)。Console で確かめてください。');
    }
    $id = (string) ($found[0]['id'] ?? '');
    $config = $found[0]['config'] ?? null;
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || !is_array($config)) {
        throw new RuntimeException('SMTP のコネクタの id か設定を読めませんでした。');
    }

    return [$id, $config];
}

try {
    $want = km_email_templates_validate($wanted);
    [$connectorId, $config] = km_email_templates_connector();
    $current = km_email_templates_index(is_array($config['templates'] ?? null) ? $config['templates'] : []);

    $differ = [];
    foreach ($want as $type => $item) {
        $same = km_email_template_same($current[$type] ?? null, $item);
        printf("  %-26s %s  %s\n", $type, $same ? '同じ  ' : (isset($current[$type]) ? '違う ★' : '無い ★'), $item['subject']);
        if (!$same) {
            $differ[] = $type;
        }
    }
    $extra = array_diff(array_keys($current), array_keys($want));
    foreach ($extra as $type) {
        printf("  %-26s 正本に無い(残す)\n", $type);
    }

    if ($differ === []) {
        echo "\nすべて正本どおりです。\n";
        exit(0);
    }
    if (!$apply) {
        printf("\n%d 種類が正本と違います。合わせるには --apply を付けてください。\n", count($differ));
        exit(0);
    }

    // 正本の順に並べ、正本に無い種類は後ろに残す。ほかの設定(送信サーバーなど)はそのまま送り返す
    $templates = array_values($want);
    foreach ($extra as $type) {
        $templates[] = $current[$type];
    }
    $config['templates'] = $templates;
    km_logto_management_patch('connectors/' . $connectorId, ['config' => $config]);

    // 書けたと思い込まない。読み直して確かめる
    [, $after] = km_email_templates_connector();
    $afterIndex = km_email_templates_index(is_array($after['templates'] ?? null) ? $after['templates'] : []);
    $still = array_values(array_filter(array_keys($want), static fn (string $type): bool => !km_email_template_same($afterIndex[$type] ?? null, $want[$type])));
    if ($still !== []) {
        fwrite(STDERR, "\n書き換えたはずですが、読み直すと違うものがあります: " . implode(', ', $still) . "\n");
        exit(1);
    }
    printf("\n%d 種類を正本どおりに書き換え、読み直して確かめました。\n", count($differ));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
