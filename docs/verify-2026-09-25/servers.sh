#!/bin/bash
# 検証用のサーバー(偽 Logto :3901、Web :3900)を起動し直す
V="$(cd "$(dirname "$0")" && pwd)"; cd "$V"; source env.sh
for p in $(ps -eo pid=,comm=,args= | awk '$2=="php" && /-S 127\.0\.0\.1:390/ {print $1}'); do kill "$p"; done; sleep 1
: > logs/mock-logto.log; : > logs/php-error.log
nohup php -S 127.0.0.1:3901 mock/router.php > logs/mock-server.log 2>&1 &
nohup php -d session.save_path="$V/sessions" -d session.use_strict_mode=1 -d session.name=__Host-KMSID -d error_log="$V/logs/php-error.log" -d log_errors=1 -d display_errors=0 -S 127.0.0.1:3900 -t websrc > logs/web-server.log 2>&1 &
sleep 2
