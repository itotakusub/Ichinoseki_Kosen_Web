#!/bin/bash
# 検証の環境をゼロから組み立てる。要るもの: docker・php 8.4(pdo_mysql)・composer・openssl・python3
#   SRC_DIR=<server/src のパス> ./setup.sh   … 検証するコードを選ぶ(既定はこのリポジトリの server/src)
#   例: 2026-09-25 のコード → git worktree add /tmp/km-0925 b0eef20 && SRC_DIR=/tmp/km-0925/server/src ./setup.sh
# 作るもの(どれも .gitignore 済み): websrc/・keys/・mapstore/・sessions/・logs/・mock/jwks.json・mock/state.json、コンテナ kmdb
set -euo pipefail
V="$(cd "$(dirname "$0")" && pwd)"; cd "$V"; source env.sh
REPO="$(cd "$V/../.." && pwd)"
SRC_DIR="${SRC_DIR:-$REPO/server/src}"
[ -f "$SRC_DIR/index.php" ] || { echo "SRC_DIR に server/src がありません: $SRC_DIR" >&2; exit 2; }
for d in websrc keys mapstore sessions logs; do [ -e "$V/$d" ] && rm -r "${V:?}/$d"; done
mkdir -p keys mapstore sessions logs results
: > results/summary.tsv
echo '{}' > mock/state.json
# 1) DB。本番と同じイメージ(compose.yaml の digest)
docker rm -f -v kmdb >/dev/null 2>&1 || true
docker run -d --name kmdb -e MARIADB_ROOT_PASSWORD=rootpw -e MARIADB_DATABASE=Kosen_map -e MARIADB_USER=Main -e MARIADB_PASSWORD=mainpw \
  -p 127.0.0.1:33306:3306 mariadb:11.4@sha256:80494b9810694179889f7281ec44ca928241df577159c0356a1070e2e94616a1 >/dev/null
ok=0; for i in $(seq 1 60); do docker exec kmdb mariadb-admin -uroot -prootpw ping 2>/dev/null | grep -q alive && { ok=1; break; }; sleep 2; done
[ "$ok" = 1 ] || { echo "MariaDB が起動しません" >&2; exit 2; }
db() { docker exec -i kmdb mariadb -uroot -prootpw Kosen_map; }
db < schema.sql
db < "$SRC_DIR/scripts/migrate-map-app-schema.sql" >/dev/null
db < "$SRC_DIR/scripts/migrate-node-title-subtitle.sql" >/dev/null
# 架空のデータだけを入れる(実在の氏名は使わない)
echo "INSERT INTO km_map_floors (id,label,svg_path,sort_order) VALUES ('1','1F','Picture/1F.png',1);
INSERT INTO km_map_nodes (id,uuid,floor_id,name,title,subtitle,occupant_name,type,x,y)
  VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1','aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1','1','教員室 T-101','教員室','T-101','架空 太郎','room',10,10);" | db
[ "$(docker exec kmdb mariadb -uroot -prootpw Kosen_map -N -e 'SELECT COUNT(*) FROM km_map_nodes')" = "1" ] || { echo "架空のデータを入れられません" >&2; exit 2; }
# 2) 検証するコード(写し)と、その composer.lock どおりの依存
cp -a "$SRC_DIR" websrc
(cd websrc && composer install --no-scripts --no-plugins --no-interaction -q)
# 3) 偽 Logto の署名鍵と JWKS
openssl genrsa -out keys/rsa.pem 2048 2>/dev/null
php -r '$d=openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents("keys/rsa.pem")))["rsa"]; $b=fn($x)=>rtrim(strtr(base64_encode($x),"+/","-_"),"=");
  file_put_contents("mock/jwks.json", json_encode(["keys"=>[["kty"=>"RSA","kid"=>"verify1","use"=>"sig","alg"=>"RS256","n"=>$b($d["n"]),"e"=>$b($d["e"])]]]));'
# 4) アプリ向け配信の設定(アクセスコード TESTCODE1)と、氏名入りの架空の地図
php -r '$c=["storageDir"=>$argv[1],"maps"=>["kosen-main"=>["file"=>"app-map-kosen-main.json","revision"=>1,"expiresAt"=>"2027-03-31T18:00:00+09:00","activeEventUuid"=>null,"checksum"=>true]],
  "codes"=>[["slug"=>"kosen-main","hash"=>password_hash("TESTCODE1",PASSWORD_DEFAULT)]],"organizationEmailDomains"=>[]];
  file_put_contents("websrc/config/app-map.local.php","<?php\nreturn ".var_export($c,true).";\n");' "$V/mapstore"
echo '{"version":4,"nodes":[{"uuid":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1","floor":"1F","title":"教員室","subtitle":"T-101","occupantName":"架空 太郎","type1":"room","x":10,"y":10}],"lines":[],"fingerprints":[],"evaluations":[],"overlays":[],"events":[]}' > mapstore/app-map-kosen-main.json
echo "setup done: $SRC_DIR"
