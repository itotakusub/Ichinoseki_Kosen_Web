<?php

declare(strict_types=1);

use Logto\Sdk\LogtoClient;
use Logto\Sdk\Models\AccessTokenClaims;
use Logto\Sdk\Models\IdTokenClaims;
use Logto\Sdk\Storage\StorageKey;

/**
 * **トークンの中身を base64url として読む**(2026-09-17)。
 *
 * SDK(logto/sdk 0.3.1)の getIdTokenClaims() / getAccessTokenClaims() は JWT の本体を `base64_decode` で読む。
 * JWT は base64url(`-` と `_`・パディング無し)なので、本体に `-` か `_` が出るトークンだけ読み違え、
 * json_decode が null を返して `new IdTokenClaims(...null)` が TypeError になる。どの文字が出るかは
 * 名前などの中身で変わる —— **一般アカウントで管理画面を開くと「認証の設定が未完了」(fail closed の 503)が出た。**
 * 手元で試すと、よくある形のトークンの 4 分の 1 が読めなかった。
 *
 * vendor は composer で入れ直すと戻るので触らず、ここで読み方だけ差し替える。
 * **署名はここでも SDK でも検証しない**(SDK と同じ)。トークンは Logto からサーバーが直接受け取り、
 * セッションにだけ置いたもので、利用者が書き換えられない。API で受け取るトークンは logto_guard.php が検証する。
 */
final class KmLogtoClient extends LogtoClient
{
    /** @return array<string, mixed> */
    private static function kmJwtPayload(?string $jwt, string $what): array
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) !== 3 || $parts[1] === '') {
            throw new UnexpectedValueException("{$what} がありません(サインインし直すと直ります)。");
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode($b64, true);
        $claims = $json === false ? null : json_decode($json, true);
        if (!is_array($claims)) {
            throw new UnexpectedValueException("{$what} の中身を読めません。");
        }

        return $claims;
    }

    function getIdTokenClaims(): IdTokenClaims
    {
        return new IdTokenClaims(...self::kmJwtPayload($this->getIdToken(), 'ID トークン'));
    }

    function getAccessTokenClaims(string $resource = ''): AccessTokenClaims
    {
        return new AccessTokenClaims(...self::kmJwtPayload($this->getAccessToken($resource), 'アクセストークン'));
    }

    /**
     * **期限の切れていない** ID トークンの中身。近いか過ぎていれば、refresh_token で 1 回だけ取り直す(2026-09-25)。
     *
     * ## なぜ要るのか(診断 W-50・W-51)
     *
     * SDK の isAuthenticated() は ID トークンが**あるか**しか見ず、ID トークンが新しくなるのは
     * getAccessToken() がアクセストークンを更新したときだけ。公開ページはそれを呼ばないので、
     * 有効期間が過ぎると教職員の印(`exp` まで)が立たず、**画面には「○○ さん」と出たまま氏名が消えた。**
     * 逆に account.php は期限を見ずに古い `organization_roles` を信じ、期限切れの ID トークンからの提案が通った。
     *
     * アクセストークンがまだ有効だと getAccessToken() は取り直さないので、ここで直接 refresh_token を使う。
     * 取り直した ID トークンは SDK と同じく検証してから保存する(handleTokenResponse)。
     *
     * @return IdTokenClaims|null 期限内の中身。サインインしていない・取り直せなかったときは null
     *                            (呼ぶ側は「教職員の特典を出さない」に倒す)
     */
    function kmFreshIdTokenClaims(int $marginSeconds = 60): ?IdTokenClaims
    {
        if (!$this->isAuthenticated()) {
            return null;
        }
        $claims = $this->getIdTokenClaims();
        if ((int) $claims->exp > time() + $marginSeconds) {
            return $claims;
        }

        $refreshToken = $this->storage->get(StorageKey::refreshToken);
        if (!is_string($refreshToken) || $refreshToken === '') {
            return null;
        }
        try {
            $response = $this->oidcCore->fetchTokenByRefreshToken(
                clientId: $this->config->appId,
                clientSecret: $this->config->appSecret,
                refreshToken: $refreshToken,
            );
            $this->handleTokenResponse('', $response);
        } catch (Throwable $exception) {
            error_log('KmLogtoClient: ID トークンを取り直せませんでした: ' . $exception->getMessage());

            return null;
        }

        $fresh = $this->getIdTokenClaims();

        return (int) $fresh->exp > time() ? $fresh : null;
    }
}
