#!/bin/bash
# W-52: 配信 API(POST /api/app-map.php)の「最新です」の判定が、役割(トークンの有無・権限)を見ないか
source "$(dirname "$0")/lib.sh"
CUR=W-52; need_servers
call() { # $1=トークン(空なら無し) $2=本文 → RESULT=upToDate|names:<値>|other
    if [ -n "$1" ]; then http POST /api/app-map.php -H 'Content-Type: application/json' -H 'X-Real-IP: 203.0.113.50' -H "Authorization: Bearer $1" -d "$2"
    else http POST /api/app-map.php -H 'Content-Type: application/json' -H 'X-Real-IP: 203.0.113.50' -d "$2"; fi
    expect_code 200 "配信 API"
    RESULT=$(printf '%s' "$HTTP_BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
        if(!is_array($d)){echo "other";exit;}
        if(!empty($d["upToDate"])){echo "upToDate";exit;}
        if(isset($d["map"]["nodes"][0])){echo "names:", json_encode($d["map"]["nodes"][0]["occupantName"] ?? null, JSON_UNESCAPED_UNICODE);exit;}
        echo "other";')
    LEVEL=$(printf '%s' "$HTTP_BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d) && isset($d["contentLevel"]) ? (string)$d["contentLevel"] : "";')
}
STAFF=$(php mktoken.php staff-0001 "staff:event:access") || die W-52 "トークンを作れません"
NOPERM=$(php mktoken.php staff-0001 "") || die W-52 "トークンを作れません"
call "$STAFF" '{"code":"TESTCODE1"}'
echo "1) スタッフのトークンで初めて取得: $RESULT(contentLevel=${LEVEL:-無し})"
[ "$RESULT" = 'names:"架空 太郎"' ] || die W-52 "対照: スタッフが氏名入りの地図を受け取れない($RESULT)"
STAFF_LEVEL="$LEVEL"
# 更新したアプリ: 受け取った版と、その中身の段(contentLevel)を送る。古いアプリ: 版だけを送る
NEW_BODY="{\"code\":\"TESTCODE1\",\"haveRevision\":1,\"haveMapId\":\"kosen-main\"${STAFF_LEVEL:+,\"haveLevel\":$(printf '%s' "$STAFF_LEVEL" | php -r 'echo json_encode(stream_get_contents(STDIN));')}}"
OLD_BODY='{"code":"TESTCODE1","haveRevision":1,"haveMapId":"kosen-main"}'
judge() { # $1=番号 $2=説明
    case "$RESULT" in
        upToDate)    verdict "$1" REPRO "$2: 「最新です」で、氏名入りの地図が置き換わらない" ;;
        names:null)  verdict "$1" FIXED "$2: 氏名を落とした地図を送り直す" ;;
        *)           die "$1" "$2: 想定外の応答($RESULT)" ;;
    esac
}
call "" "$NEW_BODY";    echo "2) トークン無し・手元の版(と段)を送る: $RESULT"; judge W-52 "ログアウト・失効・停止の後"
call "$NOPERM" "$NEW_BODY"; echo "3) 権限の無いトークン・手元の版(と段)を送る: $RESULT"; judge W-52b "スタッフの権限を外された後"
if [ -n "$STAFF_LEVEL" ]; then
    call "" "$OLD_BODY"; echo "4) 古いアプリ(段を送らない)・トークン無し: $RESULT"; judge W-52c "段を送らない古いアプリ"
fi
call "" '{"code":"TESTCODE1"}'
echo "5) 対照: トークン無しで手元の版を送らない: $RESULT"
[ "$RESULT" = "names:null" ] || die W-52 "対照: 来場者に氏名が配られる/応答がおかしい($RESULT)"
