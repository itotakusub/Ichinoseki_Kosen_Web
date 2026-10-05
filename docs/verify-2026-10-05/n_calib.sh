#!/bin/bash
# 新しいコード: 北と距離の補正(api/map-calibration.php)と経路の重み(api/route-weights.php)の書き込み。
# 読みは誰でも、書きは管理者のトークンだけか。おかしな値を断るか。
source "$(dirname "$0")/lib.sh"
CUR=N-CALIB; need_servers
ADMIN=$(php mktoken.php admin-0001 "admin:users:read admin:users:write admin:api-keys:read admin:api-keys:write") || die N-CALIB "トークンを作れません"
USER=$(php mktoken.php user-0001 "staff:event:access") || die N-CALIB "トークンを作れません"
H=(-H 'X-Real-IP: 203.0.113.90' -H 'Content-Type: application/json')
post() { # $1=パス $2=トークン(空なら無し) $3=本文 → HTTP_CODE
    if [ -n "$2" ]; then http POST "$1" "${H[@]}" -H "Authorization: Bearer $2" -d "$3"; else http POST "$1" "${H[@]}" -d "$3"; fi
}
GOOD_C='{"calibration":{"mapUpBearingDegrees":12.5,"segments":[]}}'
results=()
for bad in '{"calibration":{"mapUpBearingDegrees":360}}' '{"calibration":{"mapUpBearingDegrees":"x"}}' '{"calibration":{"mapUpBearingDegrees":10,"defaultPixelsPerMeter":0}}' '{"calibration":{"mapUpBearingDegrees":10,"segments":[{"startNodeUuid":"a","endNodeUuid":"a","meters":5}]}}' '{"calibration":{"mapUpBearingDegrees":10,"segments":[{"startNodeUuid":"a","endNodeUuid":"b","meters":5000}]}}' '{}'; do
    post /api/map-calibration.php "$ADMIN" "$bad"; results+=("$HTTP_CODE")
done
echo "1) 補正: 管理者・おかしな値 6 種 → ${results[*]}"
post /api/map-calibration.php "" "$GOOD_C"; c1=$HTTP_CODE
post /api/map-calibration.php "$USER" "$GOOD_C"; c2=$HTTP_CODE
post /api/map-calibration.php "$ADMIN" "$GOOD_C"; c3=$HTTP_CODE; echo "2) 補正: トークン無し $c1 / スタッフ $c2 / 管理者 $c3 ${HTTP_BODY:0:80}"
[ "$c3" = 200 ] || die N-CALIB "対照: 管理者の正しい補正が保存できない(HTTP $c3 ${HTTP_BODY:0:200})"
http GET /api/map-calibration.php -H 'X-Real-IP: 203.0.113.90'; g1=$HTTP_CODE; pub=$(printf '%s' "$HTTP_BODY" | jget calibration mapUpBearingDegrees)
echo "3) 補正の読み(誰でも) → $g1 北=$pub"
post /api/route-weights.php "" '{"weights":{}}'; w1=$HTTP_CODE
post /api/route-weights.php "$USER" '{"weights":{}}'; w2=$HTTP_CODE
echo "4) 重み: トークン無し $w1 / スタッフ $w2"
post /api/map-calibration.php "$ADMIN" '{"action":"reset"}'; echo "5) 後片付け(配るのをやめる) → $HTTP_CODE"
ok=1
for r in "${results[@]}"; do [ "$r" = 400 ] || ok=0; done
if [ "$ok" = 1 ] && [ "$c1" = 401 ] && [ "$c2" = 403 ] && [ "$w1" = 401 ] && [ "$w2" = 403 ] && [ "$g1" = 200 ] && [ "$pub" = "12.5" ]; then
    verdict N-CALIB FIXED "補正・重みの書き込みはトークン無し 401・管理者でない 403。おかしな値 6 種は 400。読みは誰でも 200"
else
    verdict N-CALIB REPRO "認可か入力の検査が想定と違う(値 ${results[*]}・補正 $c1/$c2・重み $w1/$w2・読み $g1/$pub)"
fi
