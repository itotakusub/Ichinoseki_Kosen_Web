#!/bin/bash
# W-50: 公開ページは ID トークンを更新せず、期限を過ぎると教職員の特典が消えるか
# W-51: 期限を過ぎた ID トークンのままでも、アカウント画面で教職員として扱われるか
cd "$(dirname "$0")"; source env.sh
B=http://127.0.0.1:3900; now=$(date +%s)
q() { docker exec kmdb mariadb -uroot -prootpw Kosen_map -N -e "$1"; }
ROLE="org123456:Kosen_Member"
teacher_session() { # $1=sid $2=sub $3=exp
  php mksession.php "$1" "{\"km_csrf\":\"csrf$2\",\"logto::refresh_token\":\"rt-$2\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"$2\",\"aud\":\"webapp\",\"exp\":$3,\"iat\":$((now-7200)),\"name\":\"教職員$2\",\"email\":\"$2@example.ac.jp\",\"organization_roles\":[\"$ROLE\"]}}}" >/dev/null; }
names() { curl -s --noproxy '*' -H "Cookie: __Host-KMSID=$1" $B/api/map-data.php | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo "namesUnlocked=", json_encode($d["namesUnlocked"]), " occupantName=", json_encode(array_values($d["nodes"])[0]["occupantName"], JSON_UNESCAPED_UNICODE), "\n";'; }
home() { curl -s --noproxy '*' -H "Cookie: __Host-KMSID=$1" $B/index.php | grep -oE 'user-chip">[^<]*' | sed 's/user-chip">/   画面の表示: /'; }
: > logs/mock-logto.log
echo "== W-50 (比較) ID トークンの期限が 1 時間先の教職員"
S1=verifyw50freshaaaaaaaaaaaaaaaaa; teacher_session $S1 teacher-f $((now+3600)); home $S1
echo "   教職員の印の期限: $(php dumpsession.php $S1 km_map_teacher_until)  (今は $now)"; echo -n "   "; names $S1
echo "== W-50 ID トークンの期限が 1 分前に切れた教職員(リフレッシュトークンは持っている)"
S2=verifyw50staleaaaaaaaaaaaaaaaaa; teacher_session $S2 teacher-s $((now-60)); home $S2
echo "   教職員の印の期限: $(php dumpsession.php $S2 km_map_teacher_until)  (今は $now)"; echo -n "   "; names $S2
echo "   偽 Logto が受けた要求のうち、トークンの更新(grant_type=refresh_token)の数: $(grep -c 'refresh_token' logs/mock-logto.log)"
echo "   ID トークンは変わったか: $( [ "$(php dumpsession.php $S2 'logto::id_token')" = "$(php dumpsession.php $S2 'logto::id_token')" ] && php -r '$s=json_decode($argv[1],true); $p=json_decode(base64_decode(strtr(explode(".",$s["logto::id_token"])[1],"-_","+/")),true); echo "exp=", $p["exp"], "(変わっていない)";' "$(php dumpsession.php $S2 'logto::id_token')")"
echo
echo "== W-51 準備: 教職員 teacher-0002 を申請・承認・地点の割り当てまで済ませる"
SA=verifyadmin0001aaaaaaaaaaaaaaaa
php mksession.php $SA "{\"km_csrf\":\"csrfA\",\"_jwt\":{\"logto::id_token\":{\"iss\":\"x\",\"sub\":\"admin-0002\",\"aud\":\"webapp\",\"exp\":$((now+3600)),\"iat\":$now,\"name\":\"管理者B\"}},\"_atmap\":{\"$LOGTO_API_RESOURCE\":{\"iss\":\"x\",\"sub\":\"admin-0002\",\"aud\":\"$LOGTO_API_RESOURCE\",\"exp\":$((now+3600)),\"iat\":$now,\"scope\":\"admin:users:read admin:users:write admin:api-keys:read admin:api-keys:write\",\"client_id\":\"webapp\"}}}" >/dev/null
S3=verifyw51teacheraaaaaaaaaaaaaaa; teacher_session $S3 teacher-0002 $((now+3600))
post() { curl -s --noproxy '*' -o /dev/null -w "%{http_code} %{redirect_url}\n" -H "Cookie: __Host-KMSID=$1" "${@:3}" "$B$2"; }
echo -n "   申請: "; post $S3 /account.php --data-urlencode do_account=staff_request --data-urlencode km_csrf=csrfteacher-0002
RID=$(q "SELECT id FROM km_staff_requests WHERE user_id='teacher-0002' ORDER BY id DESC LIMIT 1")
echo -n "   承認: "; post $SA /admin/staff-requests.php --data-urlencode action=approve --data-urlencode id=$RID --data-urlencode km_csrf=csrfA
echo -n "   割り当て: "; post $SA /admin/staff-nodes.php --data-urlencode action=assign --data-urlencode node=aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1 --data-urlencode user_id=teacher-0002 --data-urlencode km_csrf=csrfA
echo "== W-51 この教職員の ID トークンを「2 時間前に切れた」ものに差し替える(Console で組織から外した後も、セッションに残る古いトークンを想定)"
teacher_session $S3 teacher-0002 $((now-7200))
echo -n "   アカウント画面に担当地点の現在の氏名が出るか: "; curl -s --noproxy '*' -H "Cookie: __Host-KMSID=$S3" $B/account.php | grep -q '架空 太郎' && echo "出る" || echo "出ない"
echo -n "   地点の変更を提案(POST /account.php do_account=staff_node_edit): "; post $S3 /account.php --data-urlencode do_account=staff_node_edit --data-urlencode node_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1 --data-urlencode node_occupantName=期限切れのトークンから --data-urlencode km_csrf=csrfteacher-0002
echo "   提案の表(利用者, 状態, 内容):"; q "SELECT user_id, status, changes_json FROM km_staff_node_edits WHERE user_id='teacher-0002'"
