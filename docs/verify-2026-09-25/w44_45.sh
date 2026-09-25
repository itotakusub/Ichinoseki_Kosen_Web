#!/bin/bash
# W-44 / W-45: 公開 API(POST /api/app-ranking.php、ログイン不要)へ実際に送る
cd "$(dirname "$0")"; source env.sh
B=http://127.0.0.1:3900/api/app-ranking.php
q() { docker exec kmdb mariadb -uroot -prootpw Kosen_map -N -e "$1"; }
post() { curl -s --noproxy '*' -H 'Content-Type: application/json' -H "X-Real-IP: $1" -d "$2" $B; echo; }
UUID=aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1
dup=$(python3 -c "import json;print(json.dumps({'visits':['$UUID']*50,'searches':[]}))")
echo "== W-44 (1) 同じ地点 ID を 50 個並べて 1 回だけ送る(ログインなし)"
post 203.0.113.10 "$dup"
echo "   その地点の件数:"; q "SELECT node_uuid, visits FROM km_map_ranking_places WHERE node_uuid='$UUID'"
echo "== W-44 (2) 実在しない ID を 50 個ずつ、10 回送る"
before=$(q "SELECT COUNT(*) FROM km_map_ranking_places")
for i in $(seq 1 10); do body=$(python3 -c "import json;print(json.dumps({'visits':['fake-$i-%03d'%k for k in range(50)],'searches':['junk-$i-%03d'%k for k in range(50)]}))"); post 203.0.113.10 "$body" >/dev/null; done
echo "   場所の表: $before 行 → $(q 'SELECT COUNT(*) FROM km_map_ranking_places') 行"
echo "   語の表: $(q 'SELECT COUNT(*) FROM km_map_ranking_queries') 行 / 語の送信元の表: $(q 'SELECT COUNT(*) FROM km_map_ranking_query_sources') 行"
echo "   地図に在る地点の数: $(q 'SELECT COUNT(*) FROM km_map_nodes')"
echo "== W-44 (3) ログインした参加者: API が認証の後に呼ぶ km_ranking_record() を、同じ引数の形で呼ぶ"
(cd websrc && php -r 'require "lib/db.php"; require "lib/app-ranking.php"; km_ranking_record(km_db(), array_fill(0,50,$argv[1]), [], "user-abc", "参加者A", "203.0.113.11"); echo "   1 回の記録の後の利用者ランキング: ", json_encode(km_ranking_users(km_db(), (int) date("Y")), JSON_UNESCAPED_UNICODE), "\n";' "$UUID")
echo "== W-45 任意の語を、送信元 IP を 1 つずつ増やして送る"
for ip in 203.0.113.21 203.0.113.22 203.0.113.23; do
  post $ip '{"visits":[],"searches":["任意の文言を載せる試験"]}' >/dev/null
  shown=$(curl -s --noproxy '*' $B | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo in_array("任意の文言を載せる試験", array_column($d["searches"],"query"), true) ? "載った" : "載らない";')
  echo "   $ip から送った後の公開一覧(GET): $shown"
done
