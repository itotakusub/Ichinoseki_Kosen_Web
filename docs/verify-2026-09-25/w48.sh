#!/bin/bash
# W-48: アカウントを削除(Logto の User.Deleted webhook)した後に、利用者 ID と名前が残るか
cd "$(dirname "$0")"; source env.sh
B=http://127.0.0.1:3900; now=$(date +%s)
q() { docker exec kmdb mariadb -uroot -prootpw Kosen_map -N -e "$1"; }
ST=verifyteacher0001aaaaaaaaaaaaaa; SA=verifyadmin0001aaaaaaaaaaaaaaaa
php mksession.php $ST "{\"km_csrf\":\"csrfT\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"teacher-0001\",\"aud\":\"webapp\",\"exp\":$((now+3600)),\"iat\":$now,\"name\":\"申請者T\",\"email\":\"t@example.ac.jp\"}}}" >/dev/null
php mksession.php $SA "{\"km_csrf\":\"csrfA\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"admin-0001\",\"aud\":\"webapp\",\"exp\":$((now+3600)),\"iat\":$now,\"name\":\"管理者A\"}},\"_atmap\":{\"$LOGTO_API_RESOURCE\":{\"iss\":\"x\",\"sub\":\"admin-0001\",\"aud\":\"$LOGTO_API_RESOURCE\",\"exp\":$((now+3600)),\"iat\":$now,\"scope\":\"admin:users:read admin:users:write admin:api-keys:read admin:api-keys:write\",\"client_id\":\"webapp\"}}}" >/dev/null
post() { curl -s --noproxy '*' -o /dev/null -w "%{http_code} %{redirect_url}" -H "Cookie: __Host-KMSID=$1" "${@:3}" "$B$2"; echo; }
echo "1) 教職員が申請する(POST /account.php)"; post $ST /account.php --data-urlencode do_account=staff_request --data-urlencode staff_note=検証 --data-urlencode km_csrf=csrfT
RID=$(q "SELECT id FROM km_staff_requests WHERE user_id='teacher-0001' ORDER BY id DESC LIMIT 1"); echo "   申請番号 #$RID"
echo "2) 管理者が承認する(POST /admin/staff-requests.php)"; post $SA /admin/staff-requests.php --data-urlencode action=approve --data-urlencode id=$RID --data-urlencode km_csrf=csrfA
echo "3) 管理者が地点を割り当てる(POST /admin/staff-nodes.php)"; post $SA /admin/staff-nodes.php --data-urlencode action=assign --data-urlencode node=aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1 --data-urlencode user_id=teacher-0001 --data-urlencode km_csrf=csrfA
echo "4) 管理者がチャットで発言する(POST /admin/api/chat-send.php。Soketi が居ないので 503 だが、保存は配信より先)"; post $SA /admin/api/chat-send.php -H 'X-KM-CSRF: csrfA' -H 'Content-Type: application/json' -d '{"message":"検証用の発言"}'
echo "   削除の前: 監査ログで teacher-0001 を含む行(実行者, 操作, detail)"; q "SELECT actor_id, action, detail FROM km_admin_log WHERE actor_id='teacher-0001' OR detail LIKE '%teacher-0001%' ORDER BY id"
hook() { body="{\"event\":\"User.Deleted\",\"createdAt\":\"$(date -u +%Y-%m-%dT%H:%M:%S.000Z)\",\"data\":{\"id\":\"$1\"}}"; sig=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$LOGTO_WEBHOOK_SIGNING_KEY" -r | cut -d' ' -f1); curl -s --noproxy '*' -H 'Content-Type: application/json' -H "logto-signature-sha-256: $sig" -d "$body" $B/api/logto-webhook.php; echo; }
echo "5) Logto から教職員と管理者の User.Deleted が届く(署名付き webhook)"; hook teacher-0001; hook admin-0001
echo "== 削除の後"
echo "   監査ログで teacher-0001 を含む行(実行者, 操作, detail):"; q "SELECT IFNULL(actor_id,'(NULL)'), action, detail FROM km_admin_log WHERE actor_id='teacher-0001' OR detail LIKE '%teacher-0001%' ORDER BY id"
echo "   申請・割り当ての表に残る行: $(q "SELECT (SELECT COUNT(*) FROM km_staff_requests WHERE user_id='teacher-0001') + (SELECT COUNT(*) FROM km_staff_node_assignments WHERE user_id='teacher-0001')")"
echo "   チャットに残る管理者 A の発言(sender_id, sender_name, message):"; q "SELECT sender_id, sender_name, message FROM km_chat_messages WHERE sender_id='admin-0001'"
echo "   監査ログで admin-0001 が実行者の行: $(q "SELECT COUNT(*) FROM km_admin_log WHERE actor_id='admin-0001'")(匿名化されていれば 0)"
