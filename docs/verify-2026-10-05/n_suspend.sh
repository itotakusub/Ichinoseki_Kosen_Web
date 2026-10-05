#!/bin/bash
# A-35 の候補(サーバー側): 停止されたアカウントのトークンで、アプリが権限を確かめる logto_me.php は何を返すか。
# アプリは logto_me.php の失敗を「通信の不調」と区別しない(LogtoAuthManager.loadSession の runCatching)。ここではサーバーの応答だけを見る。
# 偽の Logto は停止中でもリフレッシュに応じる。**本物の Logto が停止中の利用者のリフレッシュに応じるかは、ここでは確かめられない**(検証機で)。
source "$(dirname "$0")/lib.sh"
CUR=A-35-pre; need_servers
RUN=$(date +%s)
OK_SUB="active-$RUN"; SUS_SUB="susp-$RUN"
printf '{"suspended":["%s"]}' "$SUS_SUB" > mock/state.json
trap 'echo "{}" > mock/state.json' EXIT
T1=$(php mktoken.php "$OK_SUB" "") || die A-35-pre "トークンを作れません"
T2=$(php mktoken.php "$SUS_SUB" "") || die A-35-pre "トークンを作れません"
http GET /logto_me.php -H 'X-Real-IP: 203.0.113.97' -H "Authorization: Bearer $T1"; c1=$HTTP_CODE; echo "1) 停止されていない利用者 → $c1"
[ "$c1" = 200 ] || die A-35-pre "対照: 停止されていない利用者で logto_me.php が通らない(HTTP $c1 ${HTTP_BODY:0:200})"
http GET /logto_me.php -H 'X-Real-IP: 203.0.113.97' -H "Authorization: Bearer $T2"; c2=$HTTP_CODE; echo "2) 停止中の利用者(署名は正しいトークン) → $c2 ${HTTP_BODY:0:100}"
case "$c2" in
    403) verdict A-35-pre REPRO "A-35 の前提が成り立つ: 停止中なら logto_me.php は 403 で伝える(署名の正しいトークンでも)。アプリ側の扱いは Android の A-35 で判定" ;;
    200) verdict A-35-pre FIXED "停止中でも 200(サーバーが停止を見ていない)" ;;
    *) die A-35-pre "想定外の応答 $c2" ;;
esac
