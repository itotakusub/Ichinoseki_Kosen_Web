#!/bin/bash
# 新しいコード: アプリの更新の確認(api/app-update.php)と APK の取得(api/download.php)の認可。
# 管理用(admin)はトークンと管理者の権限が要るか。一般用(visitor)は誰でも。おかしな flavor は断るか。
source "$(dirname "$0")/lib.sh"
CUR=N-UPDATE; need_servers
ADMIN=$(php mktoken.php admin-0001 "admin:users:read admin:users:write admin:api-keys:read admin:api-keys:write") || die N-UPDATE "トークンを作れません"
USER=$(php mktoken.php user-0001 "") || die N-UPDATE "トークンを作れません"
ip=(-H 'X-Real-IP: 203.0.113.80')
http GET '/api/app-update.php?flavor=visitor' "${ip[@]}"; echo "1) visitor・トークン無し → $HTTP_CODE $HTTP_BODY"; expect_code 200 "一般用の確認"
http GET '/api/app-update.php?flavor=admin' "${ip[@]}"; a1=$HTTP_CODE; echo "2) admin・トークン無し → $a1"
http GET '/api/app-update.php?flavor=admin' "${ip[@]}" -H "Authorization: Bearer $USER"; a2=$HTTP_CODE; echo "3) admin・権限の無いトークン → $a2"
http GET '/api/app-update.php?flavor=admin' "${ip[@]}" -H "Authorization: Bearer $ADMIN"; a3=$HTTP_CODE; echo "4) admin・管理者のトークン → $a3 $HTTP_BODY"
[ "$a3" = 200 ] || die N-UPDATE "対照: 管理者のトークンで管理用の確認が通らない(HTTP $a3)"
http GET '/api/app-update.php?flavor=x' "${ip[@]}"; f1=$HTTP_CODE
http GET '/api/app-update.php?flavor%5B%5D=admin' "${ip[@]}"; f2=$HTTP_CODE
echo "5) flavor=x → $f1 / flavor[]=admin → $f2"
http GET '/api/download.php?slug=apk_admin' "${ip[@]}"; d1=$HTTP_CODE
http GET '/api/download.php?slug=apk_admin' "${ip[@]}" -H "Authorization: Bearer $USER"; d2=$HTTP_CODE
http GET '/api/download.php?slug=..%2Fconfig' "${ip[@]}"; d3=$HTTP_CODE
echo "6) 管理用 APK の取得: トークン無し → $d1 / 権限無し → $d2 / 定義外の slug → $d3"
if [ "$a1" = 401 ] && [ "$a2" = 403 ] && [ "$f1" = 400 ] && [ "$f2" = 400 ] && [ "$d1" = 401 ] && [ "$d2" = 403 ] && [ "$d3" = 404 ]; then
    verdict N-UPDATE FIXED "管理用の更新の確認・APK の取得はトークン無し 401・権限無し 403。おかしな flavor は 400、定義外の slug は 404"
else
    verdict N-UPDATE REPRO "認可か入力の検査が想定と違う(確認 $a1/$a2・flavor $f1/$f2・取得 $d1/$d2/$d3)"
fi
