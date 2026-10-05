#!/bin/bash
# W-44 / W-45: ランキングの記録(POST /api/app-ranking.php、ログイン不要)
source "$(dirname "$0")/lib.sh"
CUR=W-44; need_servers
API=/api/app-ranking.php
UUID=aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1
RUN=$(date +%s)
post_json() { # $1=送信元 IP $2=本文。200 と success:true でなければ検証の失敗
    http POST "$API" -H 'Content-Type: application/json' -H "X-Real-IP: $1" -d "$2"
    expect_code 200 "ランキングへの送信"
    [ "$(printf '%s' "$HTTP_BODY" | jget success)" = "true" ] || die "$CUR" "送信が success:true になりません: $HTTP_BODY"
}
# 表は最初の要求で作られる。GET で作らせてから数える
http GET "$API"; expect_code 200 "ランキングの取得(表を作らせる)"
count_visits() { q "SELECT COALESCE((SELECT visits FROM km_map_ranking_places WHERE node_uuid='$UUID' AND year=YEAR(NOW())),0)"; }

echo "== W-44a 同じ実在の地点 ID を 50 個並べて 1 回だけ送る"
before=$(count_visits)
post_json 203.0.113.10 "$(python3 -c "import json;print(json.dumps({'visits':['$UUID']*50,'searches':[]}))")"
delta=$(( $(count_visits) - before ))
echo "   その地点の件数の増え方: +$delta"
case "$delta" in
    50) verdict W-44a REPRO "同じ ID 50 個の 1 回の送信で +50" ;;
    1)  verdict W-44a FIXED "同じ ID 50 個でも +1(重複を数えない)" ;;
    *)  die W-44a "想定外の増え方 +$delta(1 か 50 のはず)" ;;
esac

echo "== W-44b 実在しない地点 ID を 50 個ずつ、10 回送る"
CUR=W-44b
pb=$(q "SELECT COUNT(*) FROM km_map_ranking_places")
for i in $(seq 1 10); do
    post_json 203.0.113.10 "$(python3 -c "import json;print(json.dumps({'visits':['fake-$RUN-$i-%03d'%k for k in range(50)],'searches':[]}))")"
done
pa=$(q "SELECT COUNT(*) FROM km_map_ranking_places")
echo "   場所の表: $pb 行 → $pa 行(地図の地点は $(q 'SELECT COUNT(*) FROM km_map_nodes') 件)"
if [ $((pa - pb)) -ge 500 ]; then verdict W-44b REPRO "実在しない ID で場所の表が $((pa - pb)) 行増えた"
elif [ $((pa - pb)) -eq 0 ]; then verdict W-44b FIXED "実在しない ID では行が増えない"
else die W-44b "想定外の増え方 +$((pa - pb))(0 か 500 のはず)"; fi

echo "== W-44c 新しい語を 50 個ずつ、10 回送る(語の表の増え方と、上限の有無)"
CUR=W-44c
qb=$(q "SELECT COUNT(*) FROM km_map_ranking_queries")
for i in $(seq 1 10); do
    post_json 203.0.113.10 "$(python3 -c "import json;print(json.dumps({'visits':[],'searches':['junk-$RUN-$i-%03d'%k for k in range(50)]}))")"
done
qa=$(q "SELECT COUNT(*) FROM km_map_ranking_queries")
cap=$(grep -hoE "KM_RANKING_MAX_QUERY_ROWS = [0-9]+" websrc/lib/app-ranking.php | grep -oE "[0-9]+$" || true)
echo "   語の表: $qb 行 → $qa 行 / 年ごとの上限: ${cap:-無し}"
if [ $((qa - qb)) -ge 500 ] && [ -z "$cap" ]; then verdict W-44c REPRO "新しい語のたびに行が増え、上限が無い"
elif [ $((qa - qb)) -ge 500 ]; then verdict W-44c REPRO "新しい語のたびに行が増える。ただし年 ${cap} 行で止まる(上限あり)"
elif [ $((qa - qb)) -eq 0 ]; then verdict W-44c FIXED "新しい語で行が増えない"
else die W-44c "想定外の増え方 +$((qa - qb))"; fi

echo "== W-44d ログインした参加者の件数: API が認証の後に呼ぶ km_ranking_record() を、同じ引数の形で 2 回続けて呼ぶ"
CUR=W-44d
out=$(cd websrc && php -r '
    require "lib/db.php"; require "lib/app-ranking.php";
    $u = "user-" . $argv[2];
    km_ranking_record(km_db(), array_fill(0, 50, $argv[1]), [], $u, "参加者A", "203.0.113.11");
    km_ranking_record(km_db(), array_fill(0, 50, $argv[1]), [], $u, "参加者A", "203.0.113.11");
    $s = km_db()->prepare("SELECT visits FROM km_map_ranking_users WHERE user_id = ? AND year = YEAR(NOW())");
    $s->execute([$u]); echo (int) $s->fetchColumn();
' "$UUID" "$RUN" 2>/tmp/w44d.err) || die W-44d "km_ranking_record() を呼べません: $(head -c 300 /tmp/w44d.err)"
[[ "$out" =~ ^[0-9]+$ ]] || die W-44d "件数を読めません: ${out:0:300}"
echo "   同じ地点 50 個 × 2 回の後の参加者の件数: $out"
if [ "$out" -ge 50 ]; then verdict W-44d REPRO "参加者の件数が $out(1 回で +50 以上)"
elif [ "$out" -le 2 ]; then verdict W-44d FIXED "参加者の件数が $out(重複・連投を数えない)"
else die W-44d "想定外の件数 $out"; fi

echo "== W-45 同じ語を、送信元 IP を 1 つずつ増やして送る"
CUR=W-45
WORD="試験の語-$RUN"
shown_after=0
for n in 1 2 3; do
    post_json "203.0.113.2$n" "$(python3 -c "import json,sys;print(json.dumps({'visits':[],'searches':[sys.argv[1]]},ensure_ascii=False))" "$WORD")"
    http GET "$API"; expect_code 200 "ランキングの取得"
    listed=$(printf '%s' "$HTTP_BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!is_array($d)||!isset($d["searches"])||!is_array($d["searches"])){echo "BAD";exit;} echo in_array($argv[1], array_column($d["searches"],"query"), true) ? "yes" : "no";' "$WORD")
    [ "$listed" = "BAD" ] && die W-45 "取得の応答に searches がありません"
    echo "   送信元 $n 個の後の公開一覧: $listed"
    if [ "$listed" = "yes" ] && [ "$shown_after" = 0 ]; then shown_after=$n; fi
done
if [ "$shown_after" -ge 1 ]; then verdict W-45 REPRO "送信元 IP $shown_after 個で、任意の語が公開一覧に載った"
else verdict W-45 FIXED "送信元 IP 3 個でも載らない"; fi
