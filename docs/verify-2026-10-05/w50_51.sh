#!/bin/bash
# W-50: 公開ページで、ID トークンの期限が切れた教職員の特典(氏名)がどうなるか
# W-51: 期限切れの ID トークン(組織から外された後も残る古いもの)で、アカウント画面から教職員として操作できるか
#
# 9/25 版の欠陥:
#   - 「ID トークンは変わったか」が同じ値どうしを比べており、必ず「変わっていない」と出た(w50_51.sh:20)
#   - 地図のデータが取れないときも「氏名が出ない」と同じ見た目になった(= 偽の「成り立つ」)。対照を確かめていなかった
#   - 偽 Logto が更新を必ず断るので、「取り直す」ように直したコードを判定できなかった
source "$(dirname "$0")/lib.sh"
CUR=W-50; need_servers
now=$(date +%s); RUN=$now
ROLE="org123456:Kosen_Member"
mock_state() { printf '%s' "$1" > mock/state.json; }
teacher_session() { # $1=sid $2=sub $3=exp
    php mksession.php "$1" "{\"km_csrf\":\"csrf$2\",\"logto::refresh_token\":\"rt-$2\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"$2\",\"aud\":\"webapp\",\"exp\":$3,\"iat\":$((now-7200)),\"name\":\"教職員$2\",\"email\":\"$2@example.ac.jp\",\"organization_roles\":[\"$ROLE\"]}}}" >/dev/null \
        || die "$CUR" "セッションを作れません"
}
id_exp() { php dumpsession.php "$1" 'logto::id_token' | php -r '$s=json_decode(stream_get_contents(STDIN),true); $t=$s["logto::id_token"] ?? ""; $p=json_decode(base64_decode(strtr(explode(".",$t."..")[1],"-_","+/")),true); echo (int)($p["exp"] ?? 0);'; }
occupant() {
    http GET /api/map-data.php -H "Cookie: __Host-KMSID=$1"; expect_code 200 "地図のデータの取得"
    printf '%s' "$HTTP_BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!is_array($d)||!isset($d["nodes"])){echo "BAD";exit;} $n=array_values($d["nodes"]); echo $n ? json_encode($n[0]["occupantName"] ?? null, JSON_UNESCAPED_UNICODE) : "NONODE";'
}
refreshes() { grep -c 'grant_type=refresh_token' logs/mock-logto.log || true; }
# 氏名は「パスワードが必要」の錠の下に置き、教職員の印でだけ見えるようにする
(cd websrc && php -r 'require "lib/map-access.php"; km_map_access_save("password", "PassW50-'"$RUN"'", "public"); echo "ok";') | grep -q ok || die W-50 "錠を設定できません"
config_changed
q "UPDATE km_map_nodes SET occupant_name='架空 太郎' WHERE id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1'" >/dev/null
mock_state '{}'

echo "== W-50 対照: ID トークンの期限が 1 時間先の教職員"
S1="w50f${RUN}aaaaaaaaaaaaaaaaaaaa"; teacher_session "$S1" "tf-$RUN" $((now+3600))
http GET /index.php -H "Cookie: __Host-KMSID=$S1"; expect_code 200 "公開ページ"
o=$(occupant "$S1"); echo "   occupantName=$o"
[ "$o" = '"架空 太郎"' ] || die W-50 "対照(期限の残る教職員)で氏名が出ない($o)。前提が崩れている"

echo "== W-50 本番: 期限が 1 分前に切れた教職員(リフレッシュトークンあり・偽 Logto は更新に応える)"
S2="w50s${RUN}aaaaaaaaaaaaaaaaaaaa"; teacher_session "$S2" "ts-$RUN" $((now-60))
before_exp=$(id_exp "$S2"); r0=$(refreshes)
http GET /index.php -H "Cookie: __Host-KMSID=$S2"; expect_code 200 "公開ページ"
after_exp=$(id_exp "$S2"); r1=$(refreshes)
o=$(occupant "$S2")
echo "   トークンの更新の要求: $((r1 - r0)) 回 / ID トークンの exp: $before_exp → $after_exp / occupantName=$o"
case "$o" in
    null)          verdict W-50 REPRO "期限が切れると、取り直しが利く状態でも氏名が出なくなる(更新の要求 $((r1 - r0)) 回)" ;;
    '"架空 太郎"') [ "$after_exp" -gt "$now" ] || die W-50 "氏名は出たが ID トークンが新しくなっていない(説明がつかない)"
                   verdict W-50 FIXED "期限切れの ID トークンを取り直し(exp $before_exp → $after_exp)、氏名が出る" ;;
    *)             die W-50 "想定外の応答($o)" ;;
esac

echo "== W-50b 取り直せないとき(偽 Logto が更新を断る)に、理由を画面に出すか"
CUR=W-50b
mock_state '{"refreshFails":true}'
S3="w50x${RUN}aaaaaaaaaaaaaaaaaaaa"; teacher_session "$S3" "tx-$RUN" $((now-60))
http GET /index.php -H "Cookie: __Host-KMSID=$S3"; expect_code 200 "公開ページ"
page="$HTTP_BODY"
o=$(occupant "$S3")
[ "$o" = "null" ] || die W-50b "取り直せないのに氏名が出る($o)"
if printf '%s' "$page" | grep -q 'サインインし直して'; then verdict W-50b FIXED "取り直せないときは「サインインし直して」と理由を出す"
else verdict W-50b REPRO "取り直せないと、理由を出さずに氏名が消える(右上はサインイン中のまま)"; fi
mock_state '{}'

echo "== W-51 準備: 教職員を申請・承認・地点の割り当てまで済ませる"
CUR=W-51
U="t51-$RUN"; SA="w51a${RUN}aaaaaaaaaaaaaaaaaaaa"; ST="w51t${RUN}aaaaaaaaaaaaaaaaaaaa"
php mksession.php "$SA" "{\"km_csrf\":\"csrfA\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"a51-$RUN\",\"aud\":\"webapp\",\"exp\":$((now+3600)),\"iat\":$now,\"name\":\"管理者B\"}},\"_atmap\":{\"$LOGTO_API_RESOURCE\":{\"iss\":\"x\",\"sub\":\"a51-$RUN\",\"aud\":\"$LOGTO_API_RESOURCE\",\"exp\":$((now+3600)),\"iat\":$now,\"scope\":\"admin:users:read admin:users:write admin:api-keys:read admin:api-keys:write\",\"client_id\":\"webapp\"}}}" >/dev/null || die W-51 "管理者のセッションを作れません"
teacher_session "$ST" "$U" $((now+3600))
http POST /account.php -H "Cookie: __Host-KMSID=$ST" --data-urlencode do_account=staff_request --data-urlencode "km_csrf=csrf$U"
expect_redirect "staff=1" "申請"
RID=$(q "SELECT id FROM km_staff_requests WHERE user_id='$U' ORDER BY id DESC LIMIT 1"); [ -n "$RID" ] || die W-51 "申請の行がありません"
http POST /admin/staff-requests.php -H "Cookie: __Host-KMSID=$SA" --data-urlencode action=approve --data-urlencode "id=$RID" --data-urlencode km_csrf=csrfA
expect_redirect "done=approve" "承認"
http POST /admin/staff-nodes.php -H "Cookie: __Host-KMSID=$SA" --data-urlencode action=assign --data-urlencode node=aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1 --data-urlencode "user_id=$U" --data-urlencode km_csrf=csrfA
expect_redirect "done=assign" "割り当て"
echo "== W-51 Logto Console で組織から外した想定(偽 Logto の更新は組織ロール無しを返す)+ セッションには 2 時間前に切れた古い ID トークン"
mock_state "{\"removedFromOrg\":[\"$U\"]}"
teacher_session "$ST" "$U" $((now-7200))
http POST /account.php -H "Cookie: __Host-KMSID=$ST" --data-urlencode do_account=staff_node_edit --data-urlencode node_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1 --data-urlencode "node_occupantName=古いトークンから-$RUN" --data-urlencode "km_csrf=csrf$U"
code=$HTTP_CODE
edits=$(q "SELECT COUNT(*) FROM km_staff_node_edits WHERE user_id='$U' AND status='pending'")
echo "   提案の送信: HTTP $code / 未処理の提案 $edits 件"
if [ "$edits" -ge 1 ]; then verdict W-51 REPRO "組織から外された後の古い ID トークンで、地点の変更の提案が受け付けられた"
elif [ "$code" = "200" ] || [ "$code" = "302" ]; then verdict W-51 FIXED "古い ID トークンからの提案は受け付けない(取り直した中身で判定)"
else die W-51 "想定外の応答(HTTP $code)"; fi
if [ "$edits" -ge 1 ]; then
    CUR=W-51c
    EID=$(q "SELECT id FROM km_staff_node_edits WHERE user_id='$U' AND status='pending' ORDER BY id DESC LIMIT 1")
    http POST /admin/staff-nodes.php -H "Cookie: __Host-KMSID=$SA" --data-urlencode action=approve --data-urlencode "id=$EID" --data-urlencode km_csrf=csrfA
    expect_redirect "done=approve" "提案の承認"
    now_name=$(q "SELECT occupant_name FROM km_map_nodes WHERE id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1'")
    if [ "$now_name" = "古いトークンから-$RUN" ]; then verdict W-51c REPRO "その提案は承認でき、地図に入った(申請の表は approved のまま)"
    else verdict W-51c FIXED "承認しても地図に入らない"; fi
fi
mock_state '{}'
q "UPDATE km_map_nodes SET occupant_name='架空 太郎' WHERE id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1'" >/dev/null
