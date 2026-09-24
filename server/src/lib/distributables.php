<?php

declare(strict_types=1);

/**
 * 配布物(Android アプリ)の差し替え。
 *
 * APK には置き場所すら無く、画面には「まだ配布用の APK はありません」と出るだけだった。
 * 管理画面から置き換えられるようにしたのがこの仕組み。
 *
 * **役割の決まった1枚だけを持つ。** ファイル管理(km_files)のような自由な置き場とは違い、
 * ここは「アプリの最新版」という決まった役割なので、slug を主キーにして常に上書きする。
 * 履歴は持たない(古い版を配る理由が無い)。
 *
 * **配信は誰でもできる**必要がある。APK は来場者が取りに来るもので、ログインの内側に
 * 置くと取りに来られない。そのため公開の api/download.php から出す。代わりに:
 *   - 出せるのはここに定義した slug だけ(任意のファイルは出せない)
 *   - 拡張子も種類ごとの許可リストで縛る
 *   - 必ず添付として返す(ブラウザ内で開かせない)
 *
 * ## 'rootca' を外した(1.0.5)
 *
 * mkcert のルート CA も同じ仕組みで配っていたが、本番は Let's Encrypt の証明書で出ている
 * ので要らない。**知らない CA を端末へ入れさせる導線が公開ページに残っている方が危ない。**
 *
 * 古い 'rootca' の行が km_distributables に残っていても害は無い ——
 * 下の一覧に無い slug は km_dist_find() が弾くので、配信も差し替えもできない。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';   // km_upload_dir() / km_upload_format_size() を借りる
require_once __DIR__ . '/user-error.php';

/*
 * ## 断り文の投げ方(2026-09-14)
 *
 * admin/downloads.php は例外の文を km_admin_error_message() で出す。
 * そこで画面に出るのは **InvalidArgumentException と KmUserError の文だけ**で、
 * RuntimeException は「保存できませんでした」+ 照合用 ID になる。
 *
 *   - 選び直せば済むもの(拡張子・大きさ・途切れたアップロードなど) … InvalidArgumentException
 *   - 管理者がホストで打つ手があるもの(置き場の権限) … KmUserError(パスは error_log にだけ出す)
 *   - PHP やサーバーの設定の失敗(一時フォルダが無い など) … RuntimeException のまま(ID で照合)
 */

/**
 * 配布する種類。
 *
 * `fallback` は、まだ一度も差し替えていないときに出す静的ファイル。
 */
const KM_DISTRIBUTABLES = [
    'apk' => [
        'extensions' => ['apk'],
        'downloadName' => 'KosenMap.apk',
        'maxBytes' => 200 * 1024 * 1024,
        'fallback' => null,
    ],
];

function km_dist_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_distributables (
            slug VARCHAR(32) NOT NULL PRIMARY KEY,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(64) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            version_label VARCHAR(64) NULL,
            updated_by VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** @return array{slug:string, originalName:string, storedName:string, sizeBytes:int, versionLabel:?string, updatedBy:?string, updatedAtEpoch:int}|null */
function km_dist_find(PDO $pdo, string $slug): ?array
{
    if (!isset(KM_DISTRIBUTABLES[$slug])) {
        return null;
    }
    km_dist_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT slug, original_name AS originalName, stored_name AS storedName, size_bytes AS sizeBytes,
                version_label AS versionLabel, updated_by AS updatedBy,
                UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
         FROM km_distributables WHERE slug = ?'
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** @return array<string, array> slug => 行(未登録の slug は含まない) */
function km_dist_all(PDO $pdo): array
{
    km_dist_ensure_table($pdo);

    $rows = [];
    foreach ($pdo->query(
        'SELECT slug, original_name AS originalName, stored_name AS storedName, size_bytes AS sizeBytes,
                version_label AS versionLabel, updated_by AS updatedBy,
                UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
         FROM km_distributables'
    ) as $row) {
        $rows[(string) $row['slug']] = $row;
    }

    return $rows;
}

/**
 * 差し替える。古い実体は消す(常に最新の1枚だけを持つ)。
 *
 * @param array $file $_FILES['...'] の形
 */
function km_dist_store(PDO $pdo, string $slug, array $file, ?string $versionLabel, ?string $updatedBy): void
{
    if (!isset(KM_DISTRIBUTABLES[$slug])) {
        throw new InvalidArgumentException('配布物の種類が不正です。');
    }
    $spec = KM_DISTRIBUTABLES[$slug];

    km_dist_ensure_table($pdo);

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('ファイルが選択されていません。');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        // php.ini の upload_max_filesize / post_max_size より大きいとここに来る。
        // APK は数十 MB になりうるので、この区別が分かるようにしておく。
        throw new InvalidArgumentException(
            'ファイルがサーバーの受け入れ上限を超えています(php.ini の upload_max_filesize / post_max_size)。'
        );
    }
    if ($error === UPLOAD_ERR_PARTIAL) {
        // 回線が途中で切れたとき。選び直せば通るので、理由を見せる
        throw new InvalidArgumentException('アップロードが途中で途切れました。もう一度ファイルを選んで送ってください。');
    }
    if ($error !== UPLOAD_ERR_OK) {
        // 一時フォルダが無い・書けない・拡張モジュールが止めた、はサーバー側の設定。ID で照合する
        throw new RuntimeException('アップロードに失敗しました(コード ' . $error . ')。');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('アップロードされたファイルを確認できませんでした。もう一度ファイルを選んで送ってください。');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new InvalidArgumentException('空のファイルは保存できません。');
    }
    if ($size > $spec['maxBytes']) {
        throw new InvalidArgumentException(
            'ファイルは ' . km_upload_format_size($spec['maxBytes']) . ' 以内にしてください。'
        );
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $spec['extensions'], true)) {
        throw new InvalidArgumentException(
            'この種類には ' . implode(' / ', $spec['extensions']) . ' のファイルを選んでください。'
        );
    }

    if ($versionLabel !== null && mb_strlen($versionLabel) > 64) {
        throw new InvalidArgumentException('バージョンは64文字以内にしてください。');
    }

    $dir = km_upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        // 画面には打つ手だけ。置き場の絶対パスはログへ(lib/app-map.php と同じ作法)
        error_log('km_dist_store: 保存先ディレクトリを作成できませんでした: ' . $dir);
        throw new KmUserError(
            '保存先ディレクトリを作成できませんでした。ホストで次を実行してください: '
            . 'docker compose exec -u root web install -d -o www-data -g www-data -m 775 uploads'
        );
    }
    if (!is_writable($dir)) {
        error_log('km_dist_store: 保存先ディレクトリに書き込めません: ' . $dir);
        throw new KmUserError(
            '保存先ディレクトリに書き込めません。ホストで次を実行してください: '
            . 'docker compose exec -u root web chown www-data uploads'
        );
    }

    // 元の名前はパスに使わない(uploads.php / profile.php と同じ方針)
    $storedName = 'dist_' . bin2hex(random_bytes(16)) . '.' . $extension;

    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('ファイルを保存できませんでした。');
    }

    $previous = km_dist_find($pdo, $slug)['storedName'] ?? null;

    try {
        $pdo->prepare(
            'INSERT INTO km_distributables (slug, original_name, stored_name, size_bytes, version_label, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE original_name = VALUES(original_name),
                                     stored_name = VALUES(stored_name),
                                     size_bytes = VALUES(size_bytes),
                                     version_label = VALUES(version_label),
                                     updated_by = VALUES(updated_by),
                                     updated_at = NOW()'
        )->execute([
            $slug,
            mb_substr($originalName, 0, 255),
            $storedName,
            $size,
            ($versionLabel === null || trim($versionLabel) === '') ? null : trim($versionLabel),
            $updatedBy,
        ]);
    } catch (Throwable $exception) {
        // DB に登録できなかったら実体も残さない(参照できない孤児を作らない)
        @unlink($dir . DIRECTORY_SEPARATOR . $storedName);
        throw $exception;
    }

    km_dist_remove_file($previous);
}

/** 登録を消して、静的ファイルの既定(あれば)へ戻す。 */
function km_dist_delete(PDO $pdo, string $slug): void
{
    if (!isset(KM_DISTRIBUTABLES[$slug])) {
        throw new InvalidArgumentException('配布物の種類が不正です。');
    }
    km_dist_ensure_table($pdo);

    $previous = km_dist_find($pdo, $slug)['storedName'] ?? null;
    $pdo->prepare('DELETE FROM km_distributables WHERE slug = ?')->execute([$slug]);
    km_dist_remove_file($previous);
}

/**
 * 保存名で実体を消す。消せなかったことは黙って捨てない
 * (フェーズ11で file-manager に入れたのと同じ理由。孤児に気づけなくなる)。
 */
function km_dist_remove_file(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }
    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path)) {
        return;
    }
    if (!@unlink($path) && is_file($path)) {
        error_log('km_dist_remove_file(): 古い配布物を削除できませんでした: ' . $path);
    }
}
