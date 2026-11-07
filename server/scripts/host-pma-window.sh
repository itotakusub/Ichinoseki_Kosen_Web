#!/bin/sh
#
# phpMyAdmin は**ふだん止めておき、トンネル(デスクトップの「トンネル開通SSH.cmd」)が開いている間だけ**動かす
# (2026-11-07、利用者の指示「常時 OFF・起動はトンネル開通SSH.cmd・OFF にする条件は Chrome を終了したとき」。11-06 の 18〜21 時の形は外した)。
#
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap            合図を見て、起こすか止める(cron が 1 分ごと)
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap --status   今どうなっているかを出すだけ
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap --open 60  トンネルなしで、今から 60 分だけ使う(最大 180 分)
#   sudo sh ./host-pma-window.sh --path /opt/kosenmap --close    手で起こした分を取り消して止める
#
# ## 合図
#
# トンネルの SSH(km の鍵)は -N の代わりに、**km の家の WANT_NAME を 20 秒ごとに触る**だけの命令を走らせる(sudo は要らない)。
# この見張りは、そのファイルが FRESH_SECONDS 秒より新しければ起こし、古いか無ければ止める。
# Chrome を閉じるとバッチがトンネルを切る → 触られなくなる → **2 分以内に止まる**。PC が落ちても回線が切れても同じ。
# 読むのは**時刻と持ち主だけ**(中身は読まない・リンクはたどらない)。
#
# ## なぜ
#
# 初回の Trivy(2026-11-06)で phpMyAdmin は CRITICAL 8・HIGH 153。入口は 127.0.0.1:8281 だけで km の鍵のトンネルからしか
# 届かないが、**動いていない間は攻めようがない。** 配備の `up -d` は起こすが、次の見張り(1 分以内)で止まる。
# 止まっていても、更新の確認(check-updates.sh)・検査(host-image-scan.sh)・片付け(host-docker-prune.sh)はその像を数える(消さない)。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
STATUS_ONLY=0
OPEN_MINUTES=""
CLOSE=0
SERVICE="phpmyadmin"
# トンネルを張る人と、触る目印(トンネル開通SSH.cmd と同じ名前にすること)
WANT_USER="km"
WANT_NAME=".kosenmap-pma-wanted"
# 触るのは 20 秒ごと。3 回ぶん触られなければ止める
FRESH_SECONDS=60
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
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
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

# トンネルの合図: km の家の目印が、km の持ち物の普通のファイルで、FRESH_SECONDS 秒より新しいか
WANT_AGE=""
WANT_HOME="$(getent passwd "$WANT_USER" | cut -d: -f6 || true)"
WANT_FILE="${WANT_HOME:-/nonexistent}/$WANT_NAME"
if [ -n "$WANT_HOME" ] && [ -f "$WANT_FILE" ] && [ ! -L "$WANT_FILE" ] \
    && [ "$(stat -c %U -- "$WANT_FILE" 2>/dev/null || true)" = "$WANT_USER" ]; then
  _mtime="$(stat -c %Y -- "$WANT_FILE" 2>/dev/null || echo 0)"
  WANT_AGE=$((NOW - _mtime))
fi

if [ -n "$WANT_AGE" ] && [ "$WANT_AGE" -le "$FRESH_SECONDS" ]; then
  WANT="running"
  WHY="トンネルが開いている(${WANT_AGE} 秒前に合図)"
elif [ "$OPEN_UNTIL" -gt "$NOW" ]; then
  WANT="running"
  WHY="手で起こした($(TZ=Asia/Tokyo date -d "@$OPEN_UNTIL" '+%H:%M' 2>/dev/null || echo '?') まで)"
else
  WANT="stopped"
  if [ -n "$WANT_AGE" ]; then
    WHY="トンネルが閉じた(最後の合図は ${WANT_AGE} 秒前)"
  else
    WHY="トンネルの合図がない"
  fi
fi

# compose の phpmyadmin のコンテナ(無ければ空)
CID="$(docker compose ps -a -q "$SERVICE" 2>/dev/null | head -n 1 || true)"
STATE="none"
if [ -n "$CID" ]; then
  STATE="$(docker inspect -f '{{.State.Status}}' "$CID" 2>/dev/null || echo none)"
fi

if [ "$STATUS_ONLY" -eq 1 ]; then
  echo "phpMyAdmin は ${STATE}(あるべき姿: ${WANT}・${WHY})"
  exit 0
fi

STAMP="$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')"
if [ "$WANT" = "running" ] && [ "$STATE" != "running" ]; then
  # 1 つだけ起こす。DB(mariadb)は動いている前提(止まっていれば compose が先に起こす)
  docker compose up -d "$SERVICE"
  echo "$STAMP ${WHY}ので phpMyAdmin を起こしました(${STATE} → running)"
elif [ "$WANT" = "stopped" ] && [ "$STATE" = "running" ]; then
  docker compose stop "$SERVICE"
  echo "$STAMP ${WHY}ので phpMyAdmin を止めました"
fi
# 変わらなければ何も書かない(1 分ごとの記録を増やさない)
exit 0
