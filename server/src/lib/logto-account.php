<?php

declare(strict_types=1);

/**
 * Logto Account API —— **利用者が自分のアカウントを直す**ための口。
 *
 * ## Management API(lib/logto-management.php)との違い
 *
 * | | 誰のトークンか | 何ができるか |
 * |---|---|---|
 * | Management API | **サーバーの** m2m 資格情報 | 全ユーザーの操作。停止・一覧など |
 * | Account API(ここ) | **本人の** アクセストークン | 自分自身の情報だけ |
 *
 * **混ぜないこと。** こちらは本人のトークンしか受け取らないので、
 * 取り違えても他人の情報には届かない —— その安全性は経路を分けることで得ている。
 *
 * ## 使う前に
 *
 * Logto Console > Security > Account center で Account API を有効にしておく必要がある
 * (無効だと 404 が返る)。有効化は Console 側の作業で、コードからはできない。
 *
 * ## トークンとスコープ
 *
 * `$client->getAccessToken()` を**リソース指定なし**で呼んだものを使う。
 * リソースを指定すると、その API 向けのトークンになり Account API は受け付けない。
 *
 * プロフィールの読み書きには `profile` スコープが要る。logto-client.php の
 * `scopes:` に足してあるが、**スコープはサインイン時に確定する**ので、
 * 足す前からのセッションでは 403 になる。その場合は「サインインし直してください」と出す
 * ([km_logto_account_explain])——ここを黙って握り潰すと、
 * 「保存できないが理由が分からない」だけが残る。
 *
 * ## 変えられないもの
 *
 * メールアドレスと電話番号の変更は**確認コードの送受信**が要る(別の口を何往復かする)。
 * ここでは扱わない。パスワードの変更は、直前に本人確認(現在のパスワード)を通す
 * 2段構えで実装してある。
 *
 * ## アカウントの削除は、ここには無い
 *
 * **Account API に削除の口は無い**(2026-09-03 に実測)。
 * 本人のトークンで自分を消すことはできず、
 * **Management API の `DELETE /api/users/{id}`** を使うしかない。
 *
 * そのため削除は `account.php` が
 * [km_logto_management_delete_user()] を呼ぶ形で実装してある。
 * 本人確認は [km_logto_account_verify_password()] ——
 * **パスワードの変更と同じ判定を通す**(2箇所に書かない)。
 *
 * こちら側のデータの後片付けは `lib/account-delete.php`。
 * **順番が決まっている: こちらを先に消し、Logto は後。**
 * 逆にすると、Logto の削除だけ成功して後片付けが失敗したときに
 * **もう誰のものか分からない行が残る。**
 */

require_once __DIR__ . '/site.php';

/** 呼び出しに失敗したときに投げる。画面にそのまま出せる文言を持つ。 */
class KmLogtoAccountException extends RuntimeException
{
}

/**
 * 利用者が自分で変えられる項目。**Logto Console の設定と揃えること。**
 *
 * Console > Sign-in & account > Account center では、項目ごとに
 * `Off` / `ReadOnly` / `Edit` を選ぶ(**既定は Off**)。ここに載っているのは
 * `Edit` にしてあるものだけ。
 *
 * ## なぜ一覧を持つのか
 *
 * Account API は「どの項目が編集可能か」を教えてくれない。
 * 画面に入力欄を出しておいて保存時に断られるのが、いちばん質の悪い作りになる ——
 * **利用者は自分の入力を疑い、何度でも直そうとする**(実際にそうなった)。
 *
 * ここを見て、編集できない項目は**そもそも送らない / 入力欄を出さない**。
 *
 * ## 揃え忘れると
 *
 * - Console が Edit なのにここに無い …… 変えられるはずのものが画面に出ない(害は小)
 * - ここに在るのに Console が Off …… 保存が 400 で失敗する(害は大)
 *
 * **迷ったら外しておく。**
 */
const KM_LOGTO_ACCOUNT_EDITABLE = [
    // ランキングに出る名前。利用者が変えたがるのはほぼこれ
    'name',
];

/** その項目を利用者が変えられるか。 */
function km_logto_account_field_editable(string $field): bool
{
    return in_array($field, KM_LOGTO_ACCOUNT_EDITABLE, true);
}

/** Logto 本体の場所。末尾スラッシュ無し。 */
function km_logto_endpoint(): string
{
    $endpoint = getenv('LOGTO_ENDPOINT');
    $endpoint = is_string($endpoint) && trim($endpoint) !== ''
        ? trim($endpoint)
        : km_site_url('logto-core');

    return rtrim($endpoint, '/');
}

/** Account API の基点。`https://…/api` まで。 */
function km_logto_account_base(): string
{
    return km_logto_endpoint() . '/api';
}

/**
 * Logto 自身のアカウント画面。
 *
 * ## こちらの画面と役割を分ける
 *
 * `account.php` が扱うのは表示名とパスワードだけ。**メールアドレス・電話番号・
 * 多要素認証・パスキーは Logto の画面の方が完全**で、確認コードのやり取りも
 * 向こうが持っている。同じものを作り直す理由が無い。
 *
 * **リンクを必ず出すこと。** 「Logto の画面から変更してください」とだけ書いて
 * 行き先を示さないのは、案内しているようで案内していない。
 *
 * 別ポート(既定で :3001)なので、押すと見た目が変わる。それでも、辿り着けない
 * よりはよい。
 */
function km_logto_account_center_url(): string
{
    return km_logto_endpoint() . '/account';
}

/**
 * Account API を1回叩く。
 *
 * @param string $path `/my-account` のように先頭スラッシュ付き
 * @param array<string, mixed>|null $body null なら本文を送らない
 * @return array{status:int, json:mixed}
 */
function km_logto_account_call(
    string $token,
    string $method,
    string $path,
    ?array $body = null,
    ?string $verificationId = null
): array {
    $curl = curl_init(km_logto_account_base() . $path);
    if ($curl === false) {
        throw new KmLogtoAccountException('アカウント情報の取得を開始できませんでした。');
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($verificationId !== null) {
        // 本人確認の記録。パスワード変更のような操作はこれが無いと 403 になる
        $headers[] = 'logto-verification-id: ' . $verificationId;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($response)) {
        /*
         * **送った本文をログへ出さない。** パスワードを送る経路なので、
         * 失敗の記録に中身が混ざると、そこから漏れる。
         */
        error_log("km_logto_account_call({$method} {$path}) failed: {$error}");
        throw new KmLogtoAccountException('Logto へ接続できませんでした。しばらくしてからお試しください。');
    }

    $json = json_decode($response, true);

    /*
     * **応答の code と message はログに残す。**
     *
     * 送った側(パスワード)は絶対に出さないが、Logto が返した理由は出す。
     * これが無いと「400 が返ったが理由は誰も知らない」で止まる —— 実際に止まった。
     * 応答本文を丸ごとではなく2項目に絞るのは、将来 Logto が本文へ
     * 別の情報を載せたときに巻き込まれないため。
     */
    if ($status >= 400) {
        error_log(sprintf(
            'km_logto_account_call(%s %s) HTTP %d code=%s message=%s',
            $method,
            $path,
            $status,
            is_array($json) ? (string) ($json['code'] ?? '-') : '-',
            is_array($json) ? (string) ($json['message'] ?? '-') : '-'
        ));
    }

    return ['status' => $status, 'json' => $json];
}

/**
 * 失敗の理由を、**次に何をすればよいかが分かる**日本語にする。
 *
 * lib/recaptcha.php の説明表と同じ考え方。生の英語をそのまま出すと、
 * 読んだ人が「自分に直せることなのか」を判断できない。
 *
 * ## 推測だけで終わらせない
 *
 * 最初の版は 400 をまとめて「入力の内容を確認してください。」にしていた。
 * 実際に踏んだのは**項目ごとの許可が Off だった**ときで、入力は何も悪くない ——
 * この文言では、いくら入力を直しても直らない。
 *
 * **Logto 自身の文言を必ず添える。** こちらの見立てが外れていても、
 * 添えてある一文から本当の理由に辿り着ける。
 */
function km_logto_account_explain(int $status, mixed $json): string
{
    $code = is_array($json) ? (string) ($json['code'] ?? '') : '';
    $message = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';

    $hint = match (true) {
        $status === 401 => 'サインインの有効期限が切れています。入り直してからお試しください。',
        /*
         * profile スコープを足す前からのセッションは古いトークンを持っており、
         * Account API に断られる。「権限がありません」とだけ出すと、
         * 直す手立てが無いように読める。
         */
        $status === 403 => 'この操作に必要な許可がトークンにありません。'
            . '一度サインアウトして入り直すと解決します。',
        $status === 404 => 'Logto の Account API が有効になっていません。'
            . 'Logto Console の「Sign-in & account」>「Account center」で有効にしてください。',
        $code === 'user.username_already_in_use' => 'その利用者名は既に使われています。',
        $code !== '' && str_contains($code, 'password_rejected') =>
            'パスワードが条件を満たしていません。Logto の設定で決められた長さと種類を確認してください。',
        /*
         * **これが実際に踏んだもの。**
         *
         * Account API を有効にしただけでは足りない。項目(利用者名・表示名・
         * アバター・パスワード)は**それぞれ Off / ReadOnly / Edit を持ち、既定は Off**。
         * Off のまま変更しに行くと、入力が正しくても断られる。
         */
        $status === 400 || $status === 422 =>
            '変更が許可されていない可能性があります。Logto Console の'
                . '「Sign-in & account」>「Account center」で、変更したい項目を'
                . 'Edit にしてください(既定は Off です)。入力の誤りでも同じ応答になります。',
        $status === 429 => '試行が多すぎます。しばらく待ってからお試しください。',
        default => 'Logto がエラーを返しました(HTTP ' . $status . ')。',
    };

    // **本当の理由を隠さない。** こちらの見立てが外れているときの唯一の手がかり
    $detail = array_filter([$code, $message]);

    return $detail === [] ? $hint : $hint . ' [Logto: ' . implode(' / ', $detail) . ']';
}

/**
 * 自分のアカウント情報。読めなければ null(**画面は出す**)。
 *
 * @return array<string, mixed>|null
 */
function km_logto_account_profile(string $token): ?array
{
    $result = km_logto_account_call($token, 'GET', '/my-account');
    if ($result['status'] !== 200 || !is_array($result['json'])) {
        error_log('km_logto_account_profile: HTTP ' . $result['status']);

        return null;
    }

    return $result['json'];
}

/**
 * 利用者名・表示名・アバターを変える。**本人確認は要らない**(Logto の仕様)。
 *
 * 空文字は「消す」ではなく「変えない」として扱う —— 入力欄を空のまま保存したときに、
 * 表示名が消えるのは意図と違う。消したい場合の口はここでは用意しない。
 *
 * @param array{username?:string, name?:string, avatar?:string} $fields
 */
function km_logto_account_update_profile(string $token, array $fields): void
{
    $payload = [];
    foreach (['username', 'name', 'avatar'] as $key) {
        /*
         * **編集できない項目は送らない。** 送れば Logto が 400 で断るが、
         * その応答は「入力が悪い」とも読めるため、利用者を入力の直しに向かわせる。
         * 送らないと決めておけば、そもそもその失敗が起きない。
         */
        if (!km_logto_account_field_editable($key)) {
            continue;
        }
        $value = trim((string) ($fields[$key] ?? ''));
        if ($value !== '') {
            $payload[$key] = $value;
        }
    }

    if ($payload === []) {
        throw new KmLogtoAccountException('変更する内容がありません。');
    }

    $result = km_logto_account_call($token, 'PATCH', '/my-account', $payload);
    if ($result['status'] !== 200) {
        throw new KmLogtoAccountException(km_logto_account_explain($result['status'], $result['json']));
    }
}

/**
 * パスワードを変える。**2段構え。**
 *
 *   1. `/verifications/password` に現在のパスワードを渡し、本人確認の記録を作る
 *   2. その記録の ID を添えて `/my-account/password` に新しいパスワードを渡す
 *
 * **1段目を省けない。** アクセストークンだけで変えられると、
 * 端末を借りられた・トークンが漏れた場面でそのまま乗っ取られる。
 * 「今のパスワードを知っている」ことをここで1度確かめる。
 */
function km_logto_account_change_password(string $token, string $currentPassword, string $newPassword): void
{
    if ($currentPassword === '' || $newPassword === '') {
        throw new KmLogtoAccountException('現在のパスワードと新しいパスワードを入力してください。');
    }
    if ($currentPassword === $newPassword) {
        throw new KmLogtoAccountException('新しいパスワードが現在のものと同じです。');
    }

    $verificationId = km_logto_account_verify_password($token, $currentPassword);

    $result = km_logto_account_call(
        $token,
        'POST',
        '/my-account/password',
        ['password' => $newPassword],
        $verificationId
    );

    if ($result['status'] !== 204 && $result['status'] !== 200) {
        throw new KmLogtoAccountException(km_logto_account_explain($result['status'], $result['json']));
    }
}

/**
 * 「今のパスワードを知っている」ことを1度確かめ、その記録の ID を返す。
 *
 * ## なぜ切り出したのか
 *
 * パスワードの変更だけでなく、**アカウントの削除でも同じ確認が要る。**
 * どちらも「端末を借りられた・トークンが漏れた」場面で悪用されるもので、
 * アクセストークンだけで通してはいけない。
 *
 * **判定を2箇所に書かない。** 片方だけ緩めても画面上は何も変わらないので、
 * 緩んだことに気づけない。
 *
 * @return string 本人確認の記録 ID
 * @throws KmLogtoAccountException 確かめられなかったとき
 */
function km_logto_account_verify_password(string $token, string $currentPassword): string
{
    if ($currentPassword === '') {
        throw new KmLogtoAccountException('現在のパスワードを入力してください。');
    }

    $verification = km_logto_account_call(
        $token,
        'POST',
        '/verifications/password',
        ['password' => $currentPassword]
    );

    if ($verification['status'] !== 201 && $verification['status'] !== 200) {
        /*
         * **「パスワードが違う」と決めつけない。**
         *
         * 最初の版は 400/422 をまとめてそう言っていたが、Account center で
         * パスワードの項目が Off のときも同じ状態になる。合っているパスワードを
         * 「違う」と言われると、利用者は延々と入力し直すことになる。
         *
         * Logto の code が本当に照合の失敗を指しているときだけ言い切る。
         */
        $code = is_array($verification['json']) ? (string) ($verification['json']['code'] ?? '') : '';
        $wrongPassword = str_contains($code, 'invalid_credentials')
            || str_contains($code, 'password_mismatch')
            || str_contains($code, 'incorrect_password');

        throw new KmLogtoAccountException(
            $wrongPassword
                ? '現在のパスワードが正しくありません。'
                : km_logto_account_explain($verification['status'], $verification['json'])
        );
    }

    $verificationId = is_array($verification['json'])
        ? (string) ($verification['json']['verificationRecordId'] ?? '')
        : '';
    if ($verificationId === '') {
        throw new KmLogtoAccountException('本人確認の記録を作成できませんでした。');
    }

    return $verificationId;
}
