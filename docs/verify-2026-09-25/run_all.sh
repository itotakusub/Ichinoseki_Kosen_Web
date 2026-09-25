#!/bin/bash
# setup.sh の後に、所見の検証を順に走らせる。結果は results/ に残す(順番に意味がある: W-49 は W-46 の失敗の記録を見る)
V="$(cd "$(dirname "$0")" && pwd)"; cd "$V"; mkdir -p results
./servers.sh
./w44_45.sh 2>&1 | tee results/w44_45.txt
./w46.sh    2>&1 | tee results/w46.txt
./w49.sh    2>&1 | tee results/w49.txt
./w48.sh    2>&1 | tee results/w48.txt
{ ./w50_51.sh; ./w51b.sh; } 2>&1 | tee results/w50_51.txt
./w52.sh    2>&1 | tee results/w52.txt
python3 w47.py 2>&1 | tee results/w47.txt
[ -f "$V/../../../Ichinoseki_Kosen/php/Kosen_map.sql" ] && python3 w53.py 2>&1 | tee results/w53.txt || echo "W-53: 非公開の Android リポジトリが隣に無いので飛ばした"
