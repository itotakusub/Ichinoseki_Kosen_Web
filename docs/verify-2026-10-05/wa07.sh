#!/bin/bash
# WA-07: 署名の方式(alg)のすり替え・署名なし・鍵の取り違えを、ログイン必須の API(GET /api/app-settings.php)に送る。
# 正しいトークンは通り(対照)、それ以外は全部 401 で断られるかを見る。
# 2026-09-25 の報告書 16 は「alg は鍵の側で決まる」と**実行せずに**「問題なし」と書いていた。ここで実行して確かめる。
source "$(dirname "$0")/lib.sh"
CUR=WA-07; need_servers
send() { # $1=種類 → CODE
    local t; t=$(php wa07_tokens.php "$1") || die WA-07 "トークンを作れません($1)"
    http GET /api/app-settings.php -H "Authorization: Bearer $t" -H 'X-Real-IP: 203.0.113.70'
}
send valid
echo "対照: 正しいトークン → HTTP $HTTP_CODE"
case "$HTTP_CODE" in 200|204) ;; *) die WA-07 "対照: 正しいトークンが通らない(HTTP $HTTP_CODE ${HTTP_BODY:0:200})" ;; esac
accepted=()
for kind in none none-upper hs256-pem hs256-n hs256-nokid rs512 unknown-kid no-kid tampered embedded-jwk expired; do
    send "$kind"
    echo "  $kind → HTTP $HTTP_CODE ${HTTP_BODY:0:80}"
    case "$HTTP_CODE" in
        401) ;;
        200|204) accepted+=("$kind") ;;
        *) die WA-07 "$kind: 想定外の応答 HTTP $HTTP_CODE(401 か 200/204 のはず)" ;;
    esac
done
if [ ${#accepted[@]} -gt 0 ]; then verdict WA-07 REPRO "すり替えたトークンが通る: ${accepted[*]}"
else verdict WA-07 FIXED "alg=none・HS256(公開鍵を HMAC の鍵に)・RS512・未知の kid・kid 無し・中身の改ざん・埋め込み jwk・期限切れの 11 種が全部 401"; fi
# 断った理由が署名の検証(firebase/php-jwt の JWT::decode)であることを、同じ鍵の集合で直接確かめる(出力だけ。判定は上)
echo "断った理由(JWT::decode の例外):"
for kind in none hs256-pem rs512 unknown-kid no-kid tampered embedded-jwk expired; do
    t=$(php wa07_tokens.php "$kind")
    printf '  %-13s ' "$kind"
    php -r 'require "websrc/vendor/autoload.php"; use Firebase\JWT\{JWT,JWK}; try { JWT::decode($argv[1], JWK::parseKeySet(json_decode(file_get_contents("mock/jwks.json"), true))); echo "通る\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }' "$t"
done
