#!/bin/bash
# W-46: 地図のパスワードを替えても、前のパスワードで解除したセッションは氏名を見続けられるか
#
# 9/25 版の欠陥: パスワードの変更が失敗しても止まらず、その場合は「替えたのに見える」と同じ出力になった
# (= 偽の「成り立つ」)。この版は、変更の後に古いパスワードが断られることを**前提として確かめる**。
source "$(dirname "$0")/lib.sh"
CUR=W-46; need_servers
setpw() {
    local out
    out=$(cd websrc && php -r 'require "lib/map-access.php"; km_map_access_save("password", $argv[1], "public"); echo "ok";' "$1" 2>&1)
    [ "$out" = "ok" ] || die W-46 "パスワードを設定できません: ${out:0:300}"
    config_changed
    echo "   [設定] 氏名の錠 = password、パスワード = $1"
}
occupant() { # $1=セッション(空なら Cookie なし)。最初の地点の occupantName を出す
    if [ -n "${1:-}" ]; then http GET /api/map-data.php -H "Cookie: __Host-KMSID=$1"; else http GET /api/map-data.php; fi
    expect_code 200 "地図のデータの取得"
    printf '%s' "$HTTP_BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!is_array($d)||!isset($d["nodes"])){echo "BAD";exit;} $n=array_values($d["nodes"]); echo $n ? json_encode($n[0]["occupantName"] ?? null, JSON_UNESCAPED_UNICODE) : "NONODE";'
}
unlock() { # $1=パスワード $2=送信元 IP → HTTP_CODE、成功なら UNLOCK_SID
    http POST /api/map-unlock.php -H 'Content-Type: application/json' -H "X-Real-IP: $2" -d "{\"password\":\"$1\"}"
    UNLOCK_SID=$(session_cookie)
}
setpw PassA
o=$(occupant ""); echo "1) Cookie なし: occupantName=$o"
[ "$o" = "null" ] || die W-46 "Cookie なしで氏名が見える/読めない($o)。錠が効いていない"
unlock PassA 198.51.100.1; expect_code 200 "パスワード A での解除"; S="$UNLOCK_SID"
[ -n "$S" ] || die W-46 "解除の応答にセッションの Cookie がありません"
o=$(occupant "$S"); echo "2) A で解除したセッション: occupantName=$o"
[ "$o" = '"架空 太郎"' ] || die W-46 "解除した直後に氏名が見えない($o)。前提が崩れている"
setpw PassB
unlock PassA 198.51.100.2; echo "3) 新しいセッションで古いパスワード A: HTTP $HTTP_CODE"
[ "$HTTP_CODE" = "401" ] || die W-46 "パスワードを B に替えたのに A が断られない(HTTP $HTTP_CODE)。変更が効いていない"
o=$(occupant "$S"); echo "4) B に替えた後、A で解除した元のセッション: occupantName=$o"
case "$o" in
    '"架空 太郎"') verdict W-46 REPRO "パスワードを替えた後も、前のパスワードで解除したセッションに氏名が出る" ;;
    null)          verdict W-46 FIXED "パスワードを替えると、前の解除では氏名が出ない" ;;
    *)             die W-46 "想定外の応答($o)" ;;
esac
