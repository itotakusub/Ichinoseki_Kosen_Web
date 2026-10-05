#!/bin/bash
# W-48: アカウントを削除(Logto の User.Deleted webhook)した後に、利用者 ID と名前が残るか
#
# 9/25 版の欠陥: 申請・承認・割り当てが失敗しても止まらず、その場合は「削除の後に何も残らない」=
# 偽の「直っている」に見えた。この版は、各操作の成功と、削除の前に行があることを前提として確かめる。
source "$(dirname "$0")/lib.sh"
CUR=W-48; need_servers
now=$(date +%s); RUN=$now
T="teacher-$RUN"; A="admin-$RUN"
ST="w48t${RUN}aaaaaaaaaaaaaaaaaaaa"; SA="w48a${RUN}aaaaaaaaaaaaaaaaaaaa"
php mksession.php "$ST" "{\"km_csrf\":\"csrfT\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"$T\",\"aud\":\"webapp\",\"exp\":$((now+3600)),\"iat\":$now,\"name\":\"申請者T\",\"email\":\"t@example.ac.jp\"}}}" >/dev/null \
    || die W-48 "教職員のセッションを作れません"
php mksession.php "$SA" "{\"km_csrf\":\"csrfA\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"$A\",\"aud\":\"webapp\",\"exp\":$((now+3600)),\"iat\":$now,\"name\":\"管理者A\"}},\"_atmap\":{\"$LOGTO_API_RESOURCE\":{\"iss\":\"x\",\"sub\":\"$A\",\"aud\":\"$LOGTO_API_RESOURCE\",\"exp\":$((now+3600)),\"iat\":$now,\"scope\":\"admin:users:read admin:users:write admin:api-keys:read admin:api-keys:write\",\"client_id\":\"webapp\"}}}" >/dev/null \
    || die W-48 "管理者のセッションを作れません"
http POST /account.php -H "Cookie: __Host-KMSID=$ST" --data-urlencode do_account=staff_request --data-urlencode staff_note=検証 --data-urlencode km_csrf=csrfT
expect_redirect "staff=1" "1) 教職員の申請"
RID=$(q "SELECT id FROM km_staff_requests WHERE user_id='$T' ORDER BY id DESC LIMIT 1")
[ -n "$RID" ] || die W-48 "申請の行がありません"
http POST /admin/staff-requests.php -H "Cookie: __Host-KMSID=$SA" --data-urlencode action=approve --data-urlencode "id=$RID" --data-urlencode km_csrf=csrfA
expect_redirect "done=approve" "2) 管理者の承認"
http POST /admin/staff-nodes.php -H "Cookie: __Host-KMSID=$SA" --data-urlencode action=assign --data-urlencode node=aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1 --data-urlencode "user_id=$T" --data-urlencode km_csrf=csrfA
expect_redirect "done=assign" "3) 地点の割り当て"
# チャットは Soketi が居ないので 503 になるが、保存は配信より先(chat-send.php)。保存されたかは表で確かめる
http POST /admin/api/chat-send.php -H "Cookie: __Host-KMSID=$SA" -H 'X-KM-CSRF: csrfA' -H 'Content-Type: application/json' -d '{"message":"検証用の発言"}'
[ "$(q "SELECT COUNT(*) FROM km_chat_messages WHERE sender_id='$A'")" -ge 1 ] || die W-48 "4) チャットの発言が保存されていません(HTTP $HTTP_CODE)"
pre=$(q "SELECT COUNT(*) FROM km_admin_log WHERE actor_id<>'$T' AND detail LIKE '%$T%'")
echo "削除の前: 管理者の操作で detail に教職員の ID を含む行 $pre"
[ "$pre" -ge 1 ] || die W-48 "削除の前に detail の行がありません(承認・割り当ての記録が無い)"
hook() {
    local body sig
    body="{\"event\":\"User.Deleted\",\"createdAt\":\"$(date -u +%Y-%m-%dT%H:%M:%S.000Z)\",\"data\":{\"id\":\"$1\"}}"
    sig=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$LOGTO_WEBHOOK_SIGNING_KEY" -r | cut -d' ' -f1)
    http POST /api/logto-webhook.php -H 'Content-Type: application/json' -H "logto-signature-sha-256: $sig" -d "$body"
    expect_code 200 "User.Deleted($1)"
    [ "$(printf '%s' "$HTTP_BODY" | jget message)" = "deleted" ] || die W-48 "削除の webhook が deleted になりません: $HTTP_BODY"
}
hook "$T"; hook "$A"
post_detail=$(q "SELECT COUNT(*) FROM km_admin_log WHERE detail LIKE '%$T%'")
post_tables=$(q "SELECT (SELECT COUNT(*) FROM km_staff_requests WHERE user_id='$T') + (SELECT COUNT(*) FROM km_staff_node_assignments WHERE user_id='$T')")
post_chat=$(q "SELECT COUNT(*) FROM km_chat_messages WHERE sender_id='$A' OR sender_name='管理者A'")
echo "削除の後: detail に教職員の ID $post_detail 行 / 申請・割り当て $post_tables 行 / チャットに管理者の ID か名前 $post_chat 行"
[ "$post_tables" -eq 0 ] || die W-48 "申請・割り当ての行が消えていません(削除そのものが動いていない)"
if [ "$post_detail" -gt 0 ]; then verdict W-48a REPRO "削除の後も、監査ログの detail に対象者の利用者 ID が残る"
else verdict W-48a FIXED "監査ログの detail から利用者 ID が消えた"; fi
if [ "$post_chat" -gt 0 ]; then verdict W-48b REPRO "削除の後も、チャットに管理者の ID か名前が残る"
else verdict W-48b FIXED "チャットの発言は匿名になった"; fi
