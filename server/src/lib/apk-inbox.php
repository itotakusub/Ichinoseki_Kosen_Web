<?php

declare(strict_types=1);

/**
 * アプリ(APK)の受け箱(2026-10-09、利用者の指示「APK をいちいちアップロードするのが面倒。
 * 一時ファイルにアップロード → 届いたらメール → その URL を開いてアップロード完了」)。
 *
 * ## 流れ
 *
 *   PC(scripts\apk-push.ps1)が非公開の GitHub リポジトリに APK 2 つと latest.json を置く
 *   → 本番ホストの root の cron(scripts/host-apk-inbox.sh)が 5 分ごとに見に行き、変わっていれば取って run/apk-inbox/ に置く
 *   → この PHP(scripts/apk-inbox.php stage)が検めて受け箱(uploads/apk-inbox/)へ写し、管理者へメール
 *   → 管理者がメールの URL(admin/apk-inbox.php)を開き、「公開する」を押す(取り消す・7 日間は前の版に戻すも)
 *
 * **サーバーに外から APK を受け取る口は作らない**(利用者「セキュリティがガバになるのは嫌」)。
 * GitHub の読むだけの鍵はホストの root だけが持ち、web には渡さない。
 *
 * ## 検めること(届いたときと、公開を押したときの 2 回)
 *
 *   - SHA-256 が latest.json の値と同じ(途中で壊れていない)
 *   - パッケージ名が枠と合う(一般用 com.ito.kosenmap・管理用 com.ito.kosenmap.admin。取り違えない)
 *   - 版番号が latest.json と同じで、今の配布物より大きい(下げない。下げるのは「前の版に戻す」で)
 *   - **署名の証明書が今の配布物と同じ**(違う鍵で作った APK は公開させない。GitHub の鍵が漏れても、偽物を公開させない)
 *
 * 落ちたら「公開できない届け」として記録し、理由をメールに書く(届いたことは知らせる)。
 * **URL を開いただけでは公開しない**(Gmail などがリンクを先に読みに行くので。利用者の決定)。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/distributables.php';
require_once __DIR__ . '/apk-version.php';
require_once __DIR__ . '/uploads.php';

/** 枠ごとのパッケージ名。取り違えを断る */
const KM_APK_INBOX_PACKAGES = [
    'apk' => 'com.ito.kosenmap',
    'apk_admin' => 'com.ito.kosenmap.admin',
];
/** 届けを公開できる期間・公開のあと前の版に戻せる期間(日) */
const KM_APK_INBOX_KEEP_DAYS = 7;
/** latest.json の上限 */
const KM_APK_INBOX_MANIFEST_MAX_BYTES = 64 * 1024;

function km_apk_inbox_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_apk_inbox (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL,
            source_sha256 CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            version_code INT NULL,
            version_label VARCHAR(64) NULL,
            files_json MEDIUMTEXT NOT NULL,
            problems_json TEXT NOT NULL,
            previous_json MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            decided_by VARCHAR(255) NULL,
            decided_at DATETIME NULL,
            UNIQUE KEY uq_km_apk_inbox_token (token_hash),
            UNIQUE KEY uq_km_apk_inbox_source (source_sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** 受け箱の置き場(uploads/apk-inbox)。 */
function km_apk_inbox_dir(): string
{
    return km_upload_dir() . DIRECTORY_SEPARATOR . 'apk-inbox';
}

/** 版番号から画面の版(「6」)を作る。内部の番号は 1000000 + 版(アプリの決まり)。 */
function km_apk_inbox_label(int $versionCode, ?string $versionName): string
{
    if ($versionName !== null && preg_match('/^\s*([0-9]{1,6})\b/', $versionName, $m) === 1) {
        return $m[1];
    }
    return $versionCode > 1000000 ? (string) ($versionCode - 1000000) : (string) $versionCode;
}

/**
 * latest.json を読む。**DB を使わない**(check.php が試す)。
 *
 * @return array{versionCode:int, versionName:?string, declared:array<string, array{slug:string, name:string, sha256:string}>, problems:list<string>}
 */
function km_apk_inbox_read_manifest(string $manifestText): array
{
    $manifest = json_decode($manifestText, true);
    $problems = [];
    $declared = [];
    $versionCode = 0;
    $versionName = null;
    if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'kosenmap-apk' || !is_array($manifest['files'] ?? null)) {
        return ['versionCode' => 0, 'versionName' => null, 'declared' => [], 'problems' => ['latest.json の形が違います']];
    }
    $versionCode = is_int($manifest['versionCode'] ?? null) ? (int) $manifest['versionCode'] : 0;
    $versionName = is_string($manifest['versionName'] ?? null) ? mb_substr((string) $manifest['versionName'], 0, 64) : null;
    if ($versionCode < 1 || $versionCode > KM_DIST_MAX_VERSION_CODE) {
        $problems[] = 'latest.json の版番号が範囲外です';
    }
    foreach ($manifest['files'] as $f) {
        $slug = is_array($f) ? (string) ($f['slug'] ?? '') : '';
        $name = is_array($f) ? (string) ($f['name'] ?? '') : '';
        // 名前は APK のファイル名だけ(パスの区切り・.. を入れさせない)
        if (!isset(KM_APK_INBOX_PACKAGES[$slug]) || preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,99}\.apk$/', $name) !== 1 || isset($declared[$slug])) {
            $problems[] = 'latest.json の files に知らない・重なった・名前の不正な項目があります';
            continue;
        }
        $declared[$slug] = ['slug' => $slug, 'name' => $name, 'sha256' => strtolower((string) ($f['sha256'] ?? ''))];
    }
    foreach (array_keys(KM_APK_INBOX_PACKAGES) as $slug) {
        if (!isset($declared[$slug])) {
            $problems[] = ($slug === 'apk' ? '一般用' : '管理用') . 'の APK がありません';
        }
    }
    return ['versionCode' => $versionCode, 'versionName' => $versionName, 'declared' => $declared, 'problems' => $problems];
}

/**
 * 1 つの APK を検める。戻りは記録する行(problems が空なら公開してよい)。**DB を使わない**(今の配布物の行は呼ぶ側が渡す)。
 *
 * @param array{slug:string, name:string, sha256?:string} $declared latest.json に書かれた値
 * @param array|null $current 今の配布物の行(km_dist_find。初めての配布なら null)
 * @param string|null $currentDir 今の配布物の置き場(既定は uploads)
 * @return array{slug:string, originalName:string, size:int, sha256:string, versionCode:?int, package:?string, signer:?string, currentVersionCode:?int, currentSigner:?string, problems:list<string>}
 */
function km_apk_inbox_inspect(string $path, array $declared, int $versionCode, ?array $current, ?string $currentDir = null): array
{
    $slug = (string) $declared['slug'];
    $problems = [];
    $size = is_file($path) ? (int) filesize($path) : 0;
    $sha = $size > 0 ? (string) hash_file('sha256', $path) : '';
    if ($size <= 0) {
        $problems[] = 'ファイルがありません';
    } elseif ($size > KM_DISTRIBUTABLES[$slug]['maxBytes']) {
        $problems[] = '大きすぎます(' . km_upload_format_size($size) . ')';
    }
    if ($sha !== '' && isset($declared['sha256']) && !hash_equals(strtolower((string) $declared['sha256']), $sha)) {
        $problems[] = 'SHA-256 が latest.json と違います(途中で壊れた・取り違えた)';
    }
    $apkVersion = $size > 0 ? km_apk_version_code($path) : null;
    $package = $size > 0 ? km_apk_package_name($path) : null;
    $signer = $size > 0 ? km_apk_signer_sha256($path) : null;
    if ($apkVersion !== $versionCode) {
        $problems[] = 'APK の中の版番号(' . ($apkVersion ?? '読めない') . ')が latest.json(' . $versionCode . ')と違います';
    }
    if ($package !== KM_APK_INBOX_PACKAGES[$slug]) {
        $problems[] = 'パッケージ名が違います(' . ($package ?? '読めない') . '。この枠は ' . KM_APK_INBOX_PACKAGES[$slug] . ')';
    }
    if ($signer === null) {
        $problems[] = '署名を読めません(署名していない APK)';
    }
    // 今の配布物と比べる(初めての配布なら比べる相手が無い)
    $currentSigner = null;
    $currentVersion = $current !== null && ($current['versionCode'] ?? null) !== null ? (int) $current['versionCode'] : null;
    if ($current !== null) {
        $currentPath = ($currentDir ?? km_upload_dir()) . DIRECTORY_SEPARATOR . (string) $current['storedName'];
        $currentSigner = is_file($currentPath) ? km_apk_signer_sha256($currentPath) : null;
        if ($signer !== null && $currentSigner !== null && !hash_equals($currentSigner, $signer)) {
            $problems[] = '署名の鍵が今の配布物と違います(今 ' . substr($currentSigner, 0, 12) . '… / 届いた ' . substr($signer, 0, 12) . '…)';
        }
        if ($currentVersion !== null && $apkVersion !== null && $apkVersion <= $currentVersion) {
            $problems[] = '版番号が今の配布物(' . $currentVersion . ')より大きくありません(下げるのは「前の版に戻す」で)';
        }
    }
    return [
        'slug' => $slug,
        'originalName' => (string) $declared['name'],
        'size' => $size,
        'sha256' => $sha,
        'versionCode' => $apkVersion,
        'package' => $package,
        'signer' => $signer,
        'currentVersionCode' => $currentVersion,
        'currentSigner' => $currentSigner,
        'problems' => $problems,
    ];
}

/**
 * 届いたフォルダ(latest.json と APK)を検めて受け箱へ入れ、管理者へ知らせる。
 * 同じ latest.json(SHA-256 が同じ)の届けは 2 度作らない(ホストの cron が何度呼んでもよい)。
 *
 * @return array{id:int, status:string, created:bool, problems:list<string>}
 */
function km_apk_inbox_stage(PDO $pdo, string $dir): array
{
    km_apk_inbox_ensure_table($pdo);
    km_apk_inbox_cleanup($pdo);

    $manifestPath = $dir . DIRECTORY_SEPARATOR . 'latest.json';
    if (!is_file($manifestPath) || filesize($manifestPath) > KM_APK_INBOX_MANIFEST_MAX_BYTES) {
        throw new InvalidArgumentException('latest.json がありません(または大きすぎます)。');
    }
    $manifestText = (string) file_get_contents($manifestPath);
    $sourceSha = hash('sha256', $manifestText);
    $existing = $pdo->prepare('SELECT id, status FROM km_apk_inbox WHERE source_sha256 = ?');
    $existing->execute([$sourceSha]);
    if (($row = $existing->fetch()) !== false) {
        return ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'created' => false, 'problems' => []];
    }

    $read = km_apk_inbox_read_manifest($manifestText);
    $problems = $read['problems'];
    $versionCode = $read['versionCode'];
    $versionName = $read['versionName'];
    $files = [];
    foreach ($read['declared'] as $slug => $declared) {
        $files[$slug] = km_apk_inbox_inspect($dir . DIRECTORY_SEPARATOR . $declared['name'], $declared, $versionCode, km_dist_find($pdo, $slug));
    }
    $fileProblems = array_merge(...array_values(array_map(static fn (array $f): array => $f['problems'], $files ?: [['problems' => []]])));
    $ok = $problems === [] && $fileProblems === [];

    // 受け箱へ写す(公開できるときだけ)。web の持ち物にする(ホストの置き場は読むだけ)
    if ($ok) {
        $inbox = km_apk_inbox_dir();
        if (!is_dir($inbox) && !@mkdir($inbox, 0775, true) && !is_dir($inbox)) {
            throw new RuntimeException('受け箱の置き場を作れません。');
        }
        foreach ($files as $slug => $f) {
            $stored = 'inbox_' . bin2hex(random_bytes(16)) . '.apk';
            if (!copy($dir . DIRECTORY_SEPARATOR . $f['originalName'], $inbox . DIRECTORY_SEPARATOR . $stored)
                || !hash_equals($f['sha256'], (string) hash_file('sha256', $inbox . DIRECTORY_SEPARATOR . $stored))) {
                throw new RuntimeException('受け箱へ写せません。');
            }
            $files[$slug]['storedName'] = $stored;
        }
    }

    $token = bin2hex(random_bytes(32));
    $status = $ok ? 'waiting' : 'rejected';
    $label = $versionCode > 0 ? km_apk_inbox_label($versionCode, $versionName) : null;
    $pdo->prepare(
        'INSERT INTO km_apk_inbox (token_hash, source_sha256, status, version_code, version_label, files_json, problems_json, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . KM_APK_INBOX_KEEP_DAYS . ' DAY)'
    )->execute([
        hash('sha256', $token), $sourceSha, $status, $versionCode > 0 ? $versionCode : null, $label,
        json_encode(array_values($files), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($problems, JSON_UNESCAPED_UNICODE),
    ]);
    $id = (int) $pdo->lastInsertId();
    km_apk_inbox_notify($id, $token, $status, $label, $files, $problems);
    return ['id' => $id, 'status' => $status, 'created' => true, 'problems' => array_merge($problems, $fileProblems)];
}

/** 管理者へメール。**送れなくても届けは残す**(管理画面「ダウンロード」に出る)。 */
function km_apk_inbox_notify(int $id, string $token, string $status, ?string $label, array $files, array $problems): void
{
    try {
        require_once __DIR__ . '/mailer.php';
        require_once __DIR__ . '/site.php';
        $link = km_site_admin_origin() . '/admin/apk-inbox.php?t=' . $token;
        $lines = [];
        foreach ($files as $f) {
            $lines[] = ($f['slug'] === 'apk' ? '一般用' : '管理用') . ': 版番号 ' . ($f['versionCode'] ?? '?')
                . '(今 ' . ($f['currentVersionCode'] ?? 'なし') . ')・' . km_upload_format_size((int) $f['size'])
                . '・SHA-256 ' . substr((string) $f['sha256'], 0, 12) . '…・署名 '
                . ($f['currentSigner'] === null ? '(比べる相手なし)' : ($f['signer'] === $f['currentSigner'] ? '今と同じ鍵' : '今と違う鍵'));
            foreach ($f['problems'] as $p) {
                $lines[] = '  ✕ ' . $p;
            }
        }
        foreach ($problems as $p) {
            $lines[] = '✕ ' . $p;
        }
        if ($status === 'waiting') {
            $subject = '[KosenMap] 新しいアプリが届きました(版 ' . ($label ?? '?') . ')';
            $body = "GitHub の受け箱に新しいアプリが届きました(#{$id})。\n\n" . implode("\n", $lines) . "\n\n"
                . "公開するには、次を開いて「公開する」を押してください(管理画面のサインインが要ります。開いただけでは公開しません):\n{$link}\n\n"
                . '公開しないときは同じ画面で「取り消す」。' . KM_APK_INBOX_KEEP_DAYS . " 日たつと受け箱から消えます。\n"
                . "覚えのない届けなら、公開せずに取り消してください。\n";
        } else {
            $subject = '[KosenMap] 届いたアプリを公開できません(版 ' . ($label ?? '?') . ')';
            $body = "GitHub の受け箱にアプリが届きましたが、検めに通らなかったので公開できません(#{$id})。\n\n" . implode("\n", $lines) . "\n\n"
                . "詳しくは:\n{$link}\n";
        }
        km_mail_send(km_mail_admin_to(), $subject, $body, $status !== 'waiting');
    } catch (Throwable $exception) {
        error_log('km_apk_inbox_notify failed: ' . $exception->getMessage());
    }
}

/** 印で届けを引く。形の合わない印・無い印は null。 */
function km_apk_inbox_find_by_token(PDO $pdo, string $token): ?array
{
    if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        return null;
    }
    km_apk_inbox_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM km_apk_inbox WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row === false ? null : km_apk_inbox_decode($row);
}

function km_apk_inbox_find(PDO $pdo, int $id): ?array
{
    km_apk_inbox_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM km_apk_inbox WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : km_apk_inbox_decode($row);
}

/** @return list<array> 新しい順 */
function km_apk_inbox_recent(PDO $pdo, int $limit = 10): array
{
    km_apk_inbox_ensure_table($pdo);
    km_apk_inbox_cleanup($pdo);
    $rows = $pdo->query('SELECT * FROM km_apk_inbox ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)))->fetchAll();
    return array_map('km_apk_inbox_decode', $rows);
}

function km_apk_inbox_waiting_count(PDO $pdo): int
{
    km_apk_inbox_ensure_table($pdo);
    return (int) $pdo->query("SELECT COUNT(*) FROM km_apk_inbox WHERE status = 'waiting' AND expires_at > NOW()")->fetchColumn();
}

function km_apk_inbox_decode(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['files'] = json_decode((string) $row['files_json'], true) ?: [];
    $row['problems'] = json_decode((string) $row['problems_json'], true) ?: [];
    $row['previous'] = $row['previous_json'] !== null ? (json_decode((string) $row['previous_json'], true) ?: []) : null;
    return $row;
}

/**
 * 公開する。**もう一度検めてから**(届いてから公開までの間に、別の版が置かれていることがある)。
 * 2 つ目で失敗したら、1 つ目も前の版に戻す(片方だけ新しい版にしない)。
 */
function km_apk_inbox_publish(PDO $pdo, int $id, ?string $by): void
{
    $row = km_apk_inbox_find($pdo, $id);
    if ($row === null || $row['status'] !== 'waiting' || strtotime((string) $row['expires_at']) <= time()) {
        throw new InvalidArgumentException('公開できる届けではありません(公開済み・取り消し済み・期限切れ)。');
    }
    $inbox = km_apk_inbox_dir();
    foreach ($row['files'] as $f) {
        $recheck = km_apk_inbox_inspect($inbox . DIRECTORY_SEPARATOR . (string) ($f['storedName'] ?? ''), ['slug' => $f['slug'], 'name' => $f['originalName'], 'sha256' => $f['sha256']], (int) $row['version_code'], km_dist_find($pdo, $f['slug']));
        if ($recheck['problems'] !== []) {
            throw new InvalidArgumentException(($f['slug'] === 'apk' ? '一般用' : '管理用') . ': ' . implode(' / ', $recheck['problems']));
        }
    }
    $previous = [];
    try {
        foreach ($row['files'] as $f) {
            $previous[$f['slug']] = km_dist_store_file(
                $pdo, $f['slug'], $inbox . DIRECTORY_SEPARATOR . $f['storedName'], $f['originalName'], (int) $f['size'],
                $row['version_label'], $by, (int) $row['version_code'], false, true
            );
        }
    } catch (Throwable $exception) {
        foreach ($previous as $slug => $prev) {
            try {
                $prev === null ? km_dist_delete($pdo, $slug) : km_dist_restore($pdo, $slug, $prev, $by);
            } catch (Throwable $undo) {
                error_log('km_apk_inbox_publish undo failed: ' . $undo->getMessage());
            }
        }
        throw $exception;
    }
    $pdo->prepare("UPDATE km_apk_inbox SET status = 'published', previous_json = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")
        ->execute([json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $by, $id]);
    km_apk_inbox_remove_files($row['files']);
}

/** 取り消す(受け箱の分を捨てる)。 */
function km_apk_inbox_cancel(PDO $pdo, int $id, ?string $by): void
{
    $row = km_apk_inbox_find($pdo, $id);
    if ($row === null || $row['status'] !== 'waiting') {
        throw new InvalidArgumentException('取り消せる届けではありません。');
    }
    $pdo->prepare("UPDATE km_apk_inbox SET status = 'cancelled', decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$by, $id]);
    km_apk_inbox_remove_files($row['files']);
}

/** 公開の日から戻せる期限を過ぎていないか。 */
function km_apk_inbox_can_rollback(PDO $pdo, array $row): bool
{
    if ($row['status'] !== 'published' || $row['previous'] === null || $row['decided_at'] === null
        || strtotime((string) $row['decided_at']) < time() - KM_APK_INBOX_KEEP_DAYS * 86400) {
        return false;
    }
    // いま配っているのがこの届けのときだけ(あとの届けを公開したあとに、古い届けを戻さない)
    foreach ($row['files'] as $f) {
        $current = km_dist_find($pdo, $f['slug']);
        if ($current === null || ($current['sha256'] ?? null) !== $f['sha256']) {
            return false;
        }
    }
    return true;
}

/** 前の版に戻す(公開から 7 日まで)。端末に入った新しい版は下がらない(Android は版を下げて上書きしない)。 */
function km_apk_inbox_rollback(PDO $pdo, int $id, ?string $by): void
{
    $row = km_apk_inbox_find($pdo, $id);
    if ($row === null || !km_apk_inbox_can_rollback($pdo, $row)) {
        throw new InvalidArgumentException('前の版に戻せる届けではありません(期限切れ・あとの版を公開した・初めから無い)。');
    }
    foreach ($row['previous'] as $slug => $prev) {
        if (!isset(KM_APK_INBOX_PACKAGES[$slug])) {
            continue;
        }
        $prev === null ? km_dist_delete($pdo, $slug) : km_dist_restore($pdo, $slug, $prev, $by);
    }
    $pdo->prepare("UPDATE km_apk_inbox SET status = 'rolledback', previous_json = NULL, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$by, $id]);
}

/** 受け箱の実体を消す。 */
function km_apk_inbox_remove_files(array $files): void
{
    foreach ($files as $f) {
        $name = (string) ($f['storedName'] ?? '');
        if (preg_match('/^inbox_[0-9a-f]{32}\.apk$/', $name) === 1) {
            $path = km_apk_inbox_dir() . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && !@unlink($path)) {
                error_log('km_apk_inbox_remove_files: 消せません: ' . $path);
            }
        }
    }
}

/**
 * 期限を過ぎたものを片付ける:
 *   - 待ちのまま 7 日 → 期限切れ(受け箱の実体を消す)
 *   - 公開から 7 日 → 残していた前の版の実体を消す(もう戻さない)
 */
function km_apk_inbox_cleanup(PDO $pdo): void
{
    foreach ($pdo->query("SELECT * FROM km_apk_inbox WHERE status = 'waiting' AND expires_at <= NOW()")->fetchAll() as $row) {
        $row = km_apk_inbox_decode($row);
        $pdo->prepare("UPDATE km_apk_inbox SET status = 'expired' WHERE id = ?")->execute([$row['id']]);
        km_apk_inbox_remove_files($row['files']);
    }
    $old = $pdo->query("SELECT * FROM km_apk_inbox WHERE status = 'published' AND previous_json IS NOT NULL
                        AND decided_at <= NOW() - INTERVAL " . KM_APK_INBOX_KEEP_DAYS . ' DAY')->fetchAll();
    foreach ($old as $row) {
        $row = km_apk_inbox_decode($row);
        foreach ((array) $row['previous'] as $slug => $prev) {
            $stored = is_array($prev) ? (string) ($prev['storedName'] ?? '') : '';
            $current = isset(KM_APK_INBOX_PACKAGES[$slug]) ? (km_dist_find($pdo, $slug)['storedName'] ?? null) : null;
            if ($stored !== '' && $stored !== $current) {
                km_dist_remove_file($stored);
            }
        }
        $pdo->prepare('UPDATE km_apk_inbox SET previous_json = NULL WHERE id = ?')->execute([$row['id']]);
    }
}
