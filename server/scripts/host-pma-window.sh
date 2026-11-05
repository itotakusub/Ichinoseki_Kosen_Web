#!/bin/sh
#
# phpMyAdmin を決まった時間(既定 18:00〜20:59、日本時間)だけ動かす(2026-11-06、利用者の指示「18〜21 時の間しか使えないように」)。
#
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap          時間の中なら起こし、外なら止める(cron が 5 分ごと)
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap --status 今どうなっているかを出すだけ
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap --open 60  時間の外でも、今から 60 分だけ使う(手で起こす。最大 180 分)
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap --close    手で起こした分を取り消して、時間の外なら止める
#
# ## なぜ
#
# 初回の Trivy(2026-11-06)で phpMyAdmin は CRITICAL 8・HIGH 153(同梱の twig など)。入口は 127.0.0.1:8281 だけで、
# km の鍵の SSH トンネルからしか届かないが、**動いていない時間は攻めようがない。** 使うのは夕方だけなので、それ以外は止める。
#
# 配備や update-containers.ps1 の `up -d` は時間の外でも起こすが、次の見張り(5 分以内)で止まる。
# 止まっていても、更新の確認(check-updates.sh)と片付け(host-docker-prune.sh)はその像を数える(消さない)。
# 使用率の警告(host-resource-alert.sh)はメモリ不足での停止だけを見るので、ここで止めても知らせは来ない。
#
# 変えたいときは FROM_HOUR・UNTIL_HOUR(UNTIL_HOUR の 0 分で止まる)。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
STATUS_ONLY=0
OPEN_MINUTES=""
CLOSE=0
SERVICE="phpmyadmin"
FROM_HOUR=18
UNTIL_HOUR=21
MAX_OPEN_MINUTES=180
# 手で起こしたときの「いつまで」(エポック秒)。root だけが書ける場所
STATE_DIR="/var/lib/kosenmap/pma-window"
OPEN_FILE="$STATE_DIR/open-until"

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --status) STATUS_ONLY=1; shift ;;
    --open) OPEN_MINUTES="${2:-}"; shift 2 ;;
    --close) CLOSE=1; shift ;;
    -h|--help) sed -n '2,21p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ "$(id -u)" -ne 0 ]; then
  echo "root で動かしてください(sudo sh $0 --path $PATH_ROOT)。" >&2
  exit 2
fi
if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

NOW="$(date +%s)"
if [ -n "$OPEN_MINUTES" ]; then
  case "$OPEN_MINUTES" in
    ''|*[!0-9]*) echo "--open には分を数で渡してください(1〜${MAX_OPEN_MINUTES})。" >&2; exit 2 ;;
  esac
  if [ "$OPEN_MINUTES" -lt 1 ] || [ "$OPEN_MINUTES" -gt "$MAX_OPEN_MINUTES" ]; then
    echo "--open は 1〜${MAX_OPEN_MINUTES} 分です。" >&2
    exit 2
  fi
  mkdir -p "$STATE_DIR"
  chmod 700 "$STATE_DIR"
  echo $((NOW + OPEN_MINUTES * 60)) > "$OPEN_FILE.tmp"
  mv -f "$OPEN_FILE.tmp" "$OPEN_FILE"
  echo "$(TZ=Asia/Tokyo date -d "@$((NOW + OPEN_MINUTES * 60))" '+%H:%M' 2>/dev/null || echo "${OPEN_MINUTES} 分後") まで使えるようにします。"
fi
if [ "$CLOSE" -eq 1 ]; then
  rm -f "$OPEN_FILE"
  echo "手で起こした分を取り消しました。"
fi

# 手で起こした「いつまで」(壊れていれば無いものとする)
OPEN_UNTIL=0
if [ -f "$OPEN_FILE" ]; then
  OPEN_UNTIL="$(head -c 20 "$OPEN_FILE" | tr -cd '0-9')"
  OPEN_UNTIL="${OPEN_UNTIL:-0}"
  if [ "$OPEN_UNTIL" -le "$NOW" ]; then
    rm -f "$OPEN_FILE"
    OPEN_UNTIL=0
  fi
fi

# ホストの時計の設定によらず日本時間で決める(先頭の 0 は 8 進と読まれないよう外す)
HOUR="$(TZ=Asia/Tokyo date +%H | sed 's/^0//')"
HOUR="${HOUR:-0}"
if [ "$HOUR" -ge "$FROM_HOUR" ] && [ "$HOUR" -lt "$UNTIL_HOUR" ]; then
  WANT="running"
  WHY="時間の中"
elif [ "$OPEN_UNTIL" -gt "$NOW" ]; then
  WANT="running"
  WHY="手で起こした($(TZ=Asia/Tokyo date -d "@$OPEN_UNTIL" '+%H:%M' 2>/dev/null || echo '?') まで)"
else
  WANT="stopped"
  WHY="時間の外"
fi

# compose の phpmyadmin のコンテナ(無ければ空)
CID="$(docker compose ps -a -q "$SERVICE" 2>/dev/null | head -n 1 || true)"
STATE="none"
if [ -n "$CID" ]; then
  STATE="$(docker inspect -f '{{.State.Status}}' "$CID" 2>/dev/null || echo none)"
fi

if [ "$STATUS_ONLY" -eq 1 ]; then
  echo "いま $(TZ=Asia/Tokyo date '+%H:%M')(日本時間)・使える時間 ${FROM_HOUR}:00〜$((UNTIL_HOUR - 1)):59・phpMyAdmin は ${STATE}(あるべき姿: ${WANT}・${WHY})"
  exit 0
fi

STAMP="$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M')"
if [ "$WANT" = "running" ] && [ "$STATE" != "running" ]; then
  # 1 つだけ起こす。DB(mariadb)は動いている前提(止まっていれば compose が先に起こす)
  docker compose up -d "$SERVICE"
  echo "$STAMP ${WHY}なので phpMyAdmin を起こしました(${STATE} → running)"
elif [ "$WANT" = "stopped" ] && [ "$STATE" = "running" ]; then
  docker compose stop "$SERVICE"
  echo "$STAMP ${WHY}なので phpMyAdmin を止めました"
fi
# 変わらなければ何も書かない(5 分ごとの記録を増やさない)
exit 0
