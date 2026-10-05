#!/bin/bash
# 新しいコード: 管理画面「ランキングの語」(admin/ranking.php)。語は**ログイン無しで誰でも**送れる値。
# スクリプトを含む語を送り、管理者がその画面を開いたときにエスケープされているか(保存型 XSS)。
source "$(dirname "$0")/lib.sh"
CUR=N-XSS-RANK; need_servers
RUN=$(date +%s)
PAY="<script>km$RUN()</script><img src=x onerror=k()>"   # 64 文字未満(語の上限)
http POST /api/app-ranking.php -H 'Content-Type: application/json' -H 'X-Real-IP: 203.0.113.99' \
    -d "$(python3 -c "import json,sys;print(json.dumps({'visits':[],'searches':[sys.argv[1]]}))" "$PAY")"
echo "1) 語を送る → $HTTP_CODE $HTTP_BODY"; expect_code 200 "語の送信"
STORED=$(q "SELECT COUNT(*) FROM km_map_ranking_queries WHERE normalized_query LIKE '%km$RUN()%'")
echo "2) DB に入った件数: $STORED"
[ "$STORED" -ge 1 ] || die N-XSS-RANK "前提: 送った語が保存されない(長さ・形の検査で落ちた可能性。中身で弾いたとは言えない)"
A="admin-$RUN"; SA="xssa${RUN}aaaaaaaaaaaaaaaaaaaaaa"; now=$(date +%s)
php mksession.php "$SA" "{\"km_csrf\":\"csrfA\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"$A\",\"aud\":\"webapp\",\"exp\":$((now+3600)),\"iat\":$now,\"name\":\"管理者A\"}},\"_atmap\":{\"$LOGTO_API_RESOURCE\":{\"iss\":\"x\",\"sub\":\"$A\",\"aud\":\"$LOGTO_API_RESOURCE\",\"exp\":$((now+3600)),\"iat\":$now,\"scope\":\"admin:users:read admin:users:write admin:api-keys:read admin:api-keys:write\",\"client_id\":\"webapp\"}}}" >/dev/null || die N-XSS-RANK "管理者のセッションを作れません"
http GET /admin/ranking.php -H "Cookie: __Host-KMSID=$SA"; expect_code 200 "管理者で語の画面を開く"
raw=$(printf '%s' "$HTTP_BODY" | grep -c "<script>km$RUN()" || true)
esc=$(printf '%s' "$HTTP_BODY" | grep -c "&lt;script&gt;km$RUN()" || true)
echo "3) 管理画面の HTML: そのままのスクリプト $raw 箇所 / エスケープ済み $esc 箇所"
[ "$((raw + esc))" -ge 1 ] || die N-XSS-RANK "対照: 送った語が管理画面に出ない(一覧の条件が想定と違う)"
if [ "$raw" -gt 0 ]; then verdict N-XSS-RANK REPRO "誰でも送れる語が、管理画面にエスケープされずに出る(保存型 XSS)"
else verdict N-XSS-RANK FIXED "誰でも送れる語は、管理画面でエスケープされて出る($esc 箇所)"; fi
