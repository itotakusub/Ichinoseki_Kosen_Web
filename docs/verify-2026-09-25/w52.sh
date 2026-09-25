#!/bin/bash
# W-52: 配信 API(POST /api/app-map.php)の「最新です」の判定が、役割(トークンの有無・権限)を見ないか
cd "$(dirname "$0")"; source env.sh
B=http://127.0.0.1:3900/api/app-map.php
call() { # $1=トークン(空なら無し) $2=本文
  curl -s --noproxy '*' -H 'Content-Type: application/json' -H 'X-Real-IP: 203.0.113.50' ${1:+-H "Authorization: Bearer $1"} -d "$2" $B |
  php -r '$d=json_decode(stream_get_contents(STDIN),true); if (!empty($d["upToDate"])) { echo "upToDate=true(本体を送らない) revision=", $d["revision"], "\n"; exit; } if (isset($d["map"])) { echo "本体を受け取った revision=", $d["revision"], " occupantName=", json_encode($d["map"]["nodes"][0]["occupantName"], JSON_UNESCAPED_UNICODE), "\n"; exit; } echo json_encode($d, JSON_UNESCAPED_UNICODE), "\n";'
}
STAFF=$(php mktoken.php staff-0001 "staff:event:access")
echo "1) スタッフのトークンで初めて取得(手元に地図なし):"; echo -n "   "; call "$STAFF" '{"code":"TESTCODE1"}'
echo "2) トークン無し(= ログアウト・失効・停止の後)で、手元の版を送って取得:"; echo -n "   "; call "" '{"code":"TESTCODE1","haveRevision":1,"haveMapId":"kosen-main"}'
echo "3) 権限の無いトークン(= スタッフの権限を外された後)で、手元の版を送って取得:"; echo -n "   "; call "$(php mktoken.php staff-0001 "")" '{"code":"TESTCODE1","haveRevision":1,"haveMapId":"kosen-main"}'
echo "4) 比べるため、トークン無しで手元の版を送らずに取得:"; echo -n "   "; call "" '{"code":"TESTCODE1"}'
