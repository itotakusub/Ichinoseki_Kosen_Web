<?php

declare(strict_types=1);

/**
 * 利用者ごとのプロフィール画像(admin/profile.php でアップロードする)。
 *
 * ユーザーの正本は Logto なので、名前もメールも権限もこちらでは持たない。ここで持つのは
 * **Logto に無い付加情報だけ** = 画像1枚。Logto のプロフィール画像は URL しか持てず、
 * その URL をどこかでホストする必要があるため、ここで受け持つ。
 * Logto 側に画像がある場合は、こちらで設定されていないときのフォールバックとして使う。
 *
 * lib/uploads.php とは意図的に別実装にしてある。理由は **SVG**:
 *   ファイル管理の方は SVG を許可しつつ、ダウンロード時に octet-stream で返して
 *   ブラウザに開かせないことで安全を確保している。しかしプロフィール画像は <img> で
 *   その場に描画されるので、その手が使えない。SVG は中に script を書けるため、
 *   ここでは**ラスタ画像だけ**を許可する。
 *
 * 加えて、拡張子だけでなく**中身を見て**画像かどうかを確かめる(getimagesize)。
 * Content-Type はその判定結果から決め、クライアントが送ってきた値は使わない。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';   // km_upload_dir() / km_upload_format_size() を借りる

/** 許可する画像の種類。**SVG は入れない**(<img> で描画されるため)。 */
const KM_AVATAR_TYPES = [
    IMAGETYPE_PNG => ['png', 'image/png'],
    IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
    IMAGETYPE_GIF => ['gif', 'image/gif'],
    IMAGETYPE_WEBP => ['webp', 'image/webp'],
];

/** プロフィール画像の上限。顔写真に 10MB は要らない。 */
const KM_AVATAR_MAX_BYTES = 2 * 1024 * 1024;

/**
 * 縦横それぞれの上限(px)。
 *
 * **バイト数だけでは足りない。** PNG は 2MB 以内でも 20000×20000 のような画像を作れ、
 * それを表示する側(ブラウザや端末)が展開した瞬間にメモリを食い尽くす。
 * 顔写真の用途では 2048px で十分なので、ここで断る。
 */
const KM_AVATAR_MAX_DIMENSION = 2048;

/** 設定されていないときに出す画像(AdminLTE 同梱のもの)。 */
const KM_AVATAR_FALLBACK = './vendor/adminlte/assets/img/user2-160x160.jpg';

/**
 * 配るときの Content-Type。**保存名の拡張子から決める**(DB の avatar_mime は流さない)。
 *
 * 保存名は自分で採番した `avatar_<32桁>.<拡張子>` で、拡張子は保存時に中身を見て決めてある。
 * 以前は DB の avatar_mime をそのまま Content-Type に流していた —— DB が汚れていれば
 * `text/html` として配られうる(security-review-2026-09-10 の 23)。
 */
function km_profile_avatar_mime(string $storedName): string
{
    $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    foreach (KM_AVATAR_TYPES as [$allowed, $mime]) {
        if ($extension === $allowed) {
            return $mime;
        }
    }

    return 'application/octet-stream';
}

function km_profile_ensure_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS km_user_profiles (
            user_id VARCHAR(191) NOT NULL PRIMARY KEY,
            avatar_stored_name VARCHAR(64) NULL,
            avatar_mime VARCHAR(32) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

/** @return array{avatarStoredName:?string, avatarMime:?string, updatedAtEpoch:int}|null */
function km_profile_find(PDO $pdo, string $userId): ?array
{
    km_profile_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT avatar_stored_name AS avatarStoredName, avatar_mime AS avatarMime,
                UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
         FROM km_user_profiles WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * アップロードされた画像を検証して保存し、古い画像を片付ける。
 *
 * @param array $file $_FILES['...'] の形
 */
function km_profile_store_avatar(PDO $pdo, string $userId, array $file): void
{
    km_profile_ensure_table($pdo);

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('画像が選択されていません。');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('画像が大きすぎます。');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('アップロードに失敗しました(コード ' . $error . ')。');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    // ブラウザ以外から直接叩かれた場合に備え、本当にアップロードされたファイルかを確認する
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('アップロードされたファイルを確認できませんでした。');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new InvalidArgumentException('空のファイルは保存できません。');
    }
    if ($size > KM_AVATAR_MAX_BYTES) {
        throw new InvalidArgumentException(
            '画像は ' . km_upload_format_size(KM_AVATAR_MAX_BYTES) . ' 以内にしてください。'
        );
    }

    /*
     * **中身を見て判定する。** 拡張子や Content-Type は送信側の自己申告にすぎない。
     * getimagesize() が読めなければ画像ではないし、読めた場合はその型だけを信用する。
     */
    $info = @getimagesize($tmp);
    $type = is_array($info) ? ($info[2] ?? null) : null;
    if ($type === null || !isset(KM_AVATAR_TYPES[$type])) {
        throw new InvalidArgumentException('PNG / JPEG / GIF / WebP の画像を選んでください。');
    }
    // getimagesize() はヘッダーを読むだけで画素を展開しないので、ここで測っても重くない
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
        throw new InvalidArgumentException('画像の大きさを読み取れませんでした。');
    }
    if ($width > KM_AVATAR_MAX_DIMENSION || $height > KM_AVATAR_MAX_DIMENSION) {
        throw new InvalidArgumentException(
            '画像は縦横それぞれ ' . KM_AVATAR_MAX_DIMENSION . 'px 以内にしてください'
            . '(いまは ' . $width . '×' . $height . 'px)。'
        );
    }
    [$extension, $mime] = KM_AVATAR_TYPES[$type];

    $dir = km_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('保存先ディレクトリを作成できませんでした。');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('保存先ディレクトリに書き込めません。権限を確認してください。');
    }

    // 元の名前はパスに使わない(uploads.php と同じ方針)
    $storedName = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;

    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('画像を保存できませんでした。');
    }

    $previous = km_profile_find($pdo, $userId)['avatarStoredName'] ?? null;

    try {
        $pdo->prepare(
            'INSERT INTO km_user_profiles (user_id, avatar_stored_name, avatar_mime, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE avatar_stored_name = VALUES(avatar_stored_name),
                                     avatar_mime = VALUES(avatar_mime),
                                     updated_at = NOW()'
        )->execute([$userId, $storedName, $mime]);
    } catch (Throwable $exception) {
        // DB に登録できなかったら実体も残さない(参照できない孤児を作らない)
        @unlink($dir . DIRECTORY_SEPARATOR . $storedName);
        throw $exception;
    }

    km_profile_remove_file($previous);
}

/** 設定を消して既定の画像に戻す。 */
function km_profile_clear_avatar(PDO $pdo, string $userId): void
{
    km_profile_ensure_table($pdo);

    $previous = km_profile_find($pdo, $userId)['avatarStoredName'] ?? null;

    $pdo->prepare(
        'INSERT INTO km_user_profiles (user_id, avatar_stored_name, avatar_mime, updated_at)
         VALUES (?, NULL, NULL, NOW())
         ON DUPLICATE KEY UPDATE avatar_stored_name = NULL, avatar_mime = NULL, updated_at = NOW()'
    )->execute([$userId]);

    km_profile_remove_file($previous);
}

/**
 * 保存名で実体を消す。
 *
 * 消せなかったことを黙って捨てない(フェーズ11で file-manager に入れたのと同じ理由。
 * Windows のロック等で残ることが実際にあり、気づけないと孤児が溜まる)。
 */
function km_profile_remove_file(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }
    $path = km_upload_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path)) {
        return;
    }
    if (!@unlink($path) && is_file($path)) {
        error_log('km_profile_remove_file(): 古いプロフィール画像を削除できませんでした: ' . $path);
    }
}

/**
 * 複数ユーザーぶんをまとめて引く(ユーザー管理の一覧用)。
 *
 * 1行ずつ km_profile_find() を呼ぶと人数ぶん問い合わせが飛ぶので、IN でまとめる。
 *
 * @param array<int, string> $userIds
 * @return array<string, int> 画像を持っているユーザーだけ userId => updatedAtEpoch
 */
function km_profile_avatar_map(PDO $pdo, array $userIds): array
{
    $userIds = array_values(array_filter(array_unique(array_map('strval', $userIds)), static fn (string $v): bool => $v !== ''));
    if ($userIds === []) {
        return [];
    }

    km_profile_ensure_table($pdo);

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT user_id, UNIX_TIMESTAMP(updated_at) AS updatedAtEpoch
         FROM km_user_profiles
         WHERE avatar_stored_name IS NOT NULL AND user_id IN ({$placeholders})"
    );
    $stmt->execute($userIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['user_id']] = (int) $row['updatedAtEpoch'];
    }

    return $map;
}

/**
 * 画面に出す <img src>。
 *
 * 優先順は「この画面で設定した画像 → Logto の画像 → 同梱の既定画像」。
 * **DB が落ちていても画面は出したい**ので、失敗したら黙って次の候補へ落とす。
 *
 * 同じリクエスト内で何度も呼ばれる(ヘッダーで2回 + プロフィール画面)ため、
 * 問い合わせ結果は静的に覚えておく。
 *
 * @param array $user $KM_USER
 */
function km_profile_avatar_src(array $user): string
{
    static $cache = [];

    $userId = (string) ($user['sub'] ?? '');
    if ($userId === '') {
        return $user['picture'] ?? KM_AVATAR_FALLBACK;
    }

    if (!array_key_exists($userId, $cache)) {
        $cache[$userId] = null;
        try {
            $row = km_profile_find(km_db(), $userId);
            if (($row['avatarStoredName'] ?? null) !== null) {
                // updated_at を付けて、差し替え後に古い画像がキャッシュから出続けないようにする
                $cache[$userId] = './api/avatar.php?v=' . (int) $row['updatedAtEpoch'];
            }
        } catch (Throwable $exception) {
            error_log('km_profile_avatar_src() failed (fall back to default): ' . $exception->getMessage());
        }
    }

    return $cache[$userId] ?? $user['picture'] ?? KM_AVATAR_FALLBACK;
}
