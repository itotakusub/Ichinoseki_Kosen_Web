#!/bin/sh
#
# スクリプトの実行結果・ログをメールで送る。**何のログでも受ける。**
#
#   ./host-backup.sh 2>&1 | ./send-log.sh --label バックアップ
#   ./send-log.sh --label 週次点検 --file /var/log/kosenmap/weekly.log
#   ./send-log.sh --label 何か --status ng --file /tmp/out.txt
#
# ## 使い方の型
#
# **終了コードごと拾いたいときは `--run` に任せる。**
# 自分でパイプすると、パイプの左の終了コードが取れない(POSIX sh に PIPESTATUS は無い):
#
#   ./send-log.sh --label バックアップ --run "/opt/kosenmap/scripts/host-backup.sh"
#
# 出力を1つのファイルへ溜め、終わってから成否と一緒に送る。
#
# ## 秘密は伏せてから送る
#
# ログには `-p…` の行や .env を写した行が混ざる。**メールは回収できない**ので、
# src/lib/log-notice.php が伏せてから本文にする。
# **伏せ字は完璧ではない**(知っている形しか消せない)。
# ログにそもそも秘密を書かない、が本筋。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
LABEL=""
FILE=""
STATUS=""
RUN=""
EXIT_CODE=""
# 毎日「成功しました」が届くと読まれなくなる。**cron からはこれを付ける**
ONLY_FAILURE=0

if [ -n "$DEFAULT_ARGS" ]; then
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --label) LABEL="$2"; shift 2 ;;
    --file) FILE="$2"; shift 2 ;;
    --status) STATUS="$2"; shift 2 ;;
    --run) RUN="$2"; shift 2 ;;
    --only-failure) ONLY_FAILURE=1; shift ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ -z "$LABEL" ]; then
  echo "--label が要ります(何のログか分からないメールは読まれません)" >&2
  exit 2
fi

WORK="$(mktemp "${TMPDIR:-/tmp}/km-log.XXXXXX")"
trap 'rm -f "$WORK"' EXIT INT TERM

if [ -n "$RUN" ]; then
  # **`set -e` の下でも終了コードを拾う。** そのまま走らせると、
  # 失敗した時点でこのスクリプトごと終わり、**一番送りたい失敗が送られない**
  set +e
  # shellcheck disable=SC2086
  sh -c "$RUN" >"$WORK" 2>&1
  EXIT_CODE=$?
  set -e
  if [ -z "$STATUS" ]; then
    if [ "$EXIT_CODE" -eq 0 ]; then STATUS=ok; else STATUS=ng; fi
  fi
  # 走らせた側の出力は、画面にも出す(手で叩いたときに見えないと不便)
  cat "$WORK"
elif [ -n "$FILE" ]; then
  if [ ! -f "$FILE" ]; then
    echo "ファイルがありません: $FILE" >&2
    exit 2
  fi
  cat "$FILE" > "$WORK"
else
  # 標準入力から。**パイプで受けるときはここ**
  cat > "$WORK"
fi

if [ -z "$STATUS" ]; then
  STATUS=unknown
fi

# **成功したときは黙る(cron 向け)。** 毎日「成功しました」が届くと読まれなくなり、
# 本当に失敗した日のメールも一緒に読み飛ばされる。
# 手で叩いたときは付けないので、ちゃんと届く。
if [ "$ONLY_FAILURE" -eq 1 ] && [ "$STATUS" = "ok" ]; then
  echo "成功したので送りません(--only-failure)。"
  exit 0
fi

HOST_LABEL="$(hostname 2>/dev/null || echo unknown)"

json_escape() {
  # **制御文字を落としてから逃がす。**
  # 端末の色付け(ESC[…m)やタブがそのまま入ると JSON として読めなくなる。
  # 改行だけは残して \n にする(ログは行で読むもの)。
  sed 's/\x1b\[[0-9;]*[A-Za-z]//g' \
    | tr '\t' ' ' \
    | tr -d '\000-\010\013\014\016-\037' \
    | sed 's/\\/\\\\/g; s/"/\\"/g' \
    | awk 'BEGIN{ORS=""} NR>1{print "\\n"} {print}'
}

TEXT="$(json_escape < "$WORK")"
LABEL_JSON="$(printf '%s' "$LABEL" | json_escape)"
HOST_JSON="$(printf '%s' "$HOST_LABEL" | json_escape)"

if [ -n "$EXIT_CODE" ]; then
  EXIT_JSON="$EXIT_CODE"
else
  EXIT_JSON=null
fi

REPORT="{\"label\":\"$LABEL_JSON\",\"host\":\"$HOST_JSON\",\"status\":\"$STATUS\",\"exitCode\":$EXIT_JSON,\"text\":\"$TEXT\"}"

if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

printf '%s' "$REPORT" | docker compose exec -T web php scripts/notify-log.php

# **走らせたものの成否を、こちらの終了コードにも移す。**
# メールは送れたが中身は失敗、という場合に 0 で終わると cron からは成功に見える
if [ -n "$EXIT_CODE" ] && [ "$EXIT_CODE" -ne 0 ]; then
  exit "$EXIT_CODE"
fi
