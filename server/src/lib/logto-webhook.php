<?php

declare(strict_types=1);

/**
 * Logto の webhook を受ける側の判定。**DB も HTTP も使わない純粋関数。**
 *
 * ## 署名を必ず確かめる
 *
 * この口は**インターネットに開いている。** 確かめずに受けると、
 * 「この利用者が消えました」と誰でも名乗れる ——
 * **他人のランキングと設定を消せる口**になる。
 *
 * Logto は本文の HMAC-SHA256 を `logto-signature-sha-256` ヘッダで送る。
 * 鍵は Logto Console の webhook 設定に表示される「Signing key」。
 *
 * ## 比較は hash_equals で行う
 *
 * `===` で比べると、**先頭から何文字一致したかで処理時間が変わる**。
 * 総当たりの手掛かりになるので、長さに依らず同じ時間で比べる。
 */

/** 署名が入るヘッダ。Logto の仕様。 */
const KM_LOGTO_WEBHOOK_SIGNATURE_HEADER = 'HTTP_LOGTO_SIGNATURE_SHA_256';

/** 受け付けるイベント。**知らないものは黙って捨てる**(200 を返して終わる)。 */
const KM_LOGTO_WEBHOOK_USER_DELETED = 'User.Deleted';

/**
 * これより古いイベントは受けない(秒)。**署名は正しいまま再送(リプレイ)されうる**ので、
 * 本文の `createdAt` で古さを見る(security-review-2026-09-10 の 18)。
 * Logto の再送は数分以内なので、1日あれば取りこぼさない。
 */
const KM_LOGTO_WEBHOOK_MAX_AGE_SECONDS = 86400;

/** 未来の時刻をどこまで許すか(秒)。時計のずれの分。 */
const KM_LOGTO_WEBHOOK_MAX_SKEW_SECONDS = 300;

/**
 * 本文の `createdAt`(ISO 8601)を epoch 秒で返す。無い・読めないなら null。
 */
function km_logto_webhook_created_at(string $rawBody): ?int
{
    $decoded = json_decode($rawBody, true);
    $value = is_array($decoded) ? trim((string) ($decoded['createdAt'] ?? '')) : '';
    if ($value === '') {
        return null;
    }
    $time = strtotime($value);

    return $time === false ? null : $time;
}

/**
 * 古すぎる(または未来すぎる)イベントか。**`createdAt` が無い版は通す**
 * (無いことを理由に正しい削除を捨てると、消えるべき記録が残る)。
 */
function km_logto_webhook_is_stale(?int $createdAt, int $now): bool
{
    if ($createdAt === null) {
        return false;
    }

    return $now - $createdAt > KM_LOGTO_WEBHOOK_MAX_AGE_SECONDS
        || $createdAt - $now > KM_LOGTO_WEBHOOK_MAX_SKEW_SECONDS;
}

/**
 * 署名を確かめる。
 *
 * @param string $rawBody 受け取った本文**そのまま**。json_decode してから作り直さないこと
 *                        —— キーの順序や空白が変わって、署名が合わなくなる
 * @param string $signature 受け取ったヘッダの値
 * @param string $signingKey Logto Console の webhook に表示される鍵
 */
function km_logto_webhook_signature_valid(
    string $rawBody,
    string $signature,
    string $signingKey
): bool {
    $signature = trim($signature);
    if ($signature === '' || trim($signingKey) === '') {
        // **鍵を設定していないなら、何も受け付けない。**
        // 「鍵が無いから素通し」にすると、設定し忘れが穴になる
        return false;
    }

    $expected = hash_hmac('sha256', $rawBody, $signingKey);

    return hash_equals($expected, strtolower($signature));
}

/**
 * 本文から「消えた利用者の id」を取り出す。取れなければ null。
 *
 * Logto の `User.Deleted` は `data` に利用者を入れて送る。
 * **形が違うものを推測で読まない** —— 別のイベントの形に当てはめて
 * 誤って消すより、読めないと言う方がよい。
 *
 * @return array{event:string, userId:?string}
 */
function km_logto_webhook_parse(string $rawBody): array
{
    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        return ['event' => '', 'userId' => null];
    }

    $event = (string) ($decoded['event'] ?? '');
    if ($event !== KM_LOGTO_WEBHOOK_USER_DELETED) {
        return ['event' => $event, 'userId' => null];
    }

    // `data.id` が正。`userId` を送る版もあるので両方見る
    $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $userId = '';
    foreach (['id', 'userId'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '') {
            $userId = $value;
            break;
        }
    }
    if ($userId === '') {
        $userId = trim((string) ($decoded['userId'] ?? ''));
    }

    return ['event' => $event, 'userId' => $userId !== '' ? $userId : null];
}

/**
 * 署名の鍵。`.env` の `LOGTO_WEBHOOK_SIGNING_KEY`。
 *
 * **ソースには置かない。** 置くと、リポジトリを見られた時点で
 * 誰でも正しい署名を作れる。
 */
function km_logto_webhook_signing_key(): string
{
    $value = getenv('LOGTO_WEBHOOK_SIGNING_KEY');

    return is_string($value) ? trim($value) : '';
}
