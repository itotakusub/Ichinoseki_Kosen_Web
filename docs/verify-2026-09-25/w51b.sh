#!/bin/bash
# W-51 続き: 古い ID トークンから出た提案を、管理者が承認すると地図に入るか(申請の表は approved のまま = Console で組織から直接外した場合)
cd "$(dirname "$0")"; source env.sh
q() { docker exec kmdb mariadb -uroot -prootpw Kosen_map -N -e "$1"; }
EID=$(q "SELECT id FROM km_staff_node_edits WHERE user_id='teacher-0002' AND status='pending' ORDER BY id DESC LIMIT 1")
echo "   申請の表の状態: $(q "SELECT status FROM km_staff_requests WHERE user_id='teacher-0002' ORDER BY id DESC LIMIT 1")"
echo -n "   管理者が提案 #$EID を承認: "; curl -s --noproxy '*' -o /dev/null -w "%{http_code} %{redirect_url}\n" -H "Cookie: __Host-KMSID=verifyadmin0001aaaaaaaaaaaaaaaa" --data-urlencode action=approve --data-urlencode id=$EID --data-urlencode km_csrf=csrfA http://127.0.0.1:3900/admin/staff-nodes.php
echo "   地図の地点の氏名: $(q "SELECT occupant_name FROM km_map_nodes WHERE id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1'")"
