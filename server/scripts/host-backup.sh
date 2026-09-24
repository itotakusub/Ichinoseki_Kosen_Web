#!/bin/sh
#
# ホストの上でバックアップを作り、**その場で暗号化する。**
#
#   ./host-backup.sh                      作って暗号化する
#   ./host-backup.sh --notify             結果をメールで知らせる(失敗したときだけ送る)
#   ./host-backup.sh --notify --heartbeat --attach-logs
#                                         週に一度(日曜)。実行記録も添えて必ず送る
#   ./host-backup.sh --list               いま在るものを並べる
#
# **sudo は要らない。** 手元の PC から自動で呼ぶので、パスワードを聞かれる形には
# できない —— 置き場の持ち主(2026-09-18 から `kmops`。それまでは `km`)の権限で通るように作ってある
# (docker グループに入っており、config はその群れが読める。docs/12 §7-4 B)。
#
# ## なぜホストで暗号化するのか
#
# 中身は **DB 全部と .env と config/*.local.php** ——
# 復元に要るものは、そのまま**乗っ取りに要るもの**でもある。
# 平文のまま置くと、ホストに入られた時点で全部持っていかれる。
#
# **鍵はこのホストに無い。** 置いてあるのは証明書(公開鍵)だけで、
# 開ける秘密鍵は手元の PC にしかない ——
# **このホストは、自分で作ったバックアップを自分では開けない。**
# 鍵を作るのは scripts/backup-keys.ps1(手元の PC で1回だけ)。
#
# ## 錠が2種類あるのはなぜか
#
#   置いておくもの(.cms)   … 証明書で閉じる。**合言葉が要らない**ので、
#                              ホストにも cron にも秘密を置かずに済む
#   メールに添える実行記録   … 合言葉(BACKUP_PASSPHRASE)で閉じる。
#                              **受け取った人がその場で開ける**必要があるため
#
# 用途が違うので分けてある。**合言葉はメール本文に書かない** ——
# 同じ経路に鍵と錠を流したら、掛けていないのと同じ。
#
# ## 証明書が無ければ作らない
#
# 「暗号化できないので平文で置く」は**しない。** それを許すと、
# 鍵を入れ忘れた日のぶんだけ平文が残り、しかも**誰も気づかない**
# (ファイルは毎日できているので)。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

# 作業中のファイルは**他人に読ませない。** 平文の DB ダンプが一時的にできる
umask 077

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
CERT=""
KEEP=14
LIST_ONLY=0
NOTIFY=0
HEARTBEAT=0
ATTACH_LOGS=0
LOG_DIR="/var/log/kosenmap"
# 手元の PC が最後に取りに来てから何日で「来ていない」とみなすか(週1 + 余裕1日)。
# 日曜の --heartbeat の回だけ見る。試すときは --pc-stale-days 0
PC_STALE_DAYS=8

if [ -n "$DEFAULT_ARGS" ]; then
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --cert) CERT="$2"; shift 2 ;;
    --keep) KEEP="$2"; shift 2 ;;
    --list) LIST_ONLY=1; shift ;;
    --notify) NOTIFY=1; shift ;;
    --heartbeat) HEARTBEAT=1; shift ;;
    --attach-logs) ATTACH_LOGS=1; shift ;;
    --log-dir) LOG_DIR="$2"; shift 2 ;;
    --pc-stale-days) PC_STALE_DAYS="$2"; shift 2 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ -z "$CERT" ]; then
  CERT="$PATH_ROOT/backup-cert.pem"
fi

BACKUP_DIR="$PATH_ROOT/backups"
HOST_LABEL="$(hostname 2>/dev/null || echo unknown)"
STAMP="$(date +%Y%m%d-%H%M%S)"
ARCHIVE_NAME="km-backup-${HOST_LABEL}-${STAMP}.tar.gz.cms"
OUT="$BACKUP_DIR/$ARCHIVE_NAME"
STARTED="$(date +%s)"

# 添付は web コンテナから見える所に置く。**ホストの backups/ は中から見えない**
ATTACH_DIR="$PATH_ROOT/src/uploads/.km-attach"

OK=false
PARTS=""
PROBLEMS=""
ARCHIVE_BYTES=0

json_escape() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' \
    | awk 'BEGIN{ORS=""} NR>1{print "\\n"} {print}'
}

add_part() {
  _entry="{\"name\":\"$(json_escape "$1")\",\"bytes\":${2:-0},\"note\":\"$(json_escape "${3:-}")\"}"
  if [ -z "$PARTS" ]; then PARTS="$_entry"; else PARTS="$PARTS,$_entry"; fi
}

add_problem() {
  _entry="\"$(json_escape "$1")\""
  if [ -z "$PROBLEMS" ]; then PROBLEMS="$_entry"; else PROBLEMS="$PROBLEMS,$_entry"; fi
  echo "  ★ $1"
}

# **どこで落ちても、落ちたことを知らせる。**
# 途中で終わると「メールが来ない」だけが残り、
# 問題が無いのか止まったのか読み分けられない。
finish() {
  _code=$?
  rm -rf "$WORK" 2>/dev/null || true
  if [ "$NOTIFY" -eq 1 ]; then
    send_report
  fi
  rm -rf "$ATTACH_DIR" 2>/dev/null || true
  exit "$_code"
}

send_report() {
  _elapsed=$(( $(date +%s) - STARTED ))
  _kept="$(ls -1 "$BACKUP_DIR"/km-backup-*.tar.gz.cms 2>/dev/null | wc -l || echo 0)"
  _hb=false
  if [ "$HEARTBEAT" -eq 1 ]; then _hb=true; fi

  _report="{\"host\":\"$(json_escape "$HOST_LABEL")\",\"ok\":$OK,\"archive\":\"$(json_escape "$ARCHIVE_NAME")\",\"bytes\":$ARCHIVE_BYTES,\"seconds\":$_elapsed,\"kept\":$_kept,\"parts\":[$PARTS],\"problems\":[$PROBLEMS],\"heartbeat\":$_hb,\"attachNote\":\"$(json_escape "$ATTACH_NOTE")\"}"

  # shellcheck disable=SC2086
  printf '%s' "$_report" | docker compose exec -T web php scripts/notify-backup.php $ATTACH_ARGS || true
}

ATTACH_ARGS=""
ATTACH_NOTE=""
WORK=""

if [ "$LIST_ONLY" -eq 1 ]; then
  echo "== $BACKUP_DIR =="
  ls -lh "$BACKUP_DIR" 2>/dev/null || echo "  まだありません"
  exit 0
fi

if [ ! -f "$CERT" ]; then
  cat >&2 <<MSG
証明書がありません: $CERT

**平文では作りません。** 手元の PC で1回だけ次を実行して、証明書を置いてください:

    .\\backup-keys.ps1

秘密鍵は手元の PC にだけ残ります(このホストへは置きません)。
MSG
  exit 2
fi

if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/km-backup.XXXXXX")"
trap finish EXIT INT TERM

<<'WHY' true
**この置き場は root と配備用の利用者(km)の両方が書く。**

  cron              … root で走る(/etc/cron.d/kosenmap-updates)
  backup-data.ps1   … 手元の PC から km で入ってくる

`700 root:root` にしていたため、**後者が一度も通らなかった** ——
openssl が `Permission denied` で落ち、しかも落ちたのは 4 段目なので
ダンプを3つ取り終えたあとだった(2026-09-07 に実測)。

## km から隠しても意味が無い

置き場の持ち主(2026-09-18 から `kmops`)は `docker` グループにいる。`docker run -v /:/host` で root になれるので、
**この置き場をその利用者から隠しても防御にはならない。** 隠せていると思い込む分だけ悪い。

中身を守っているのは**権限ではなく暗号**で、開ける秘密鍵は手元の PC にしかない。
だから「root と km が書ける / 他の誰にも見せない」に揃える。

setgid(2 の位)を付けて、**root が作ったファイルにも km の群れが付く**ようにする。
これが無いと、cron が作ったものを km が消せず(世代整理は置き場への書き込みなので
消せるが)読めない。
WHY
mkdir -p "$BACKUP_DIR"
# 群れは PATH_ROOT の持ち主に合わせる。"km" と直に書くと、別名で置いたときに外れる
_root_group="$(stat -c '%G' "$PATH_ROOT" 2>/dev/null || echo '')"
if [ -n "$_root_group" ]; then
  chgrp "$_root_group" "$BACKUP_DIR" 2>/dev/null || true
fi
chmod 2770 "$BACKUP_DIR" 2>/dev/null || true

<<'WHY' true
**docker compose が .env を読めなければ、下は全部落ちる。先に確かめる。**

2026-09-13 18:30、BACKUP_PASSPHRASE に `"` `'` `\` `$` を含む値を入れた直後から、
`docker compose` は `failed to read .env: line 52: unexpected character …` で**すべて**拒んだ。
下の `ps -q` は空を返すので「mariadb が動いていません」と**別の理由**で止まり、
メールも `docker compose exec` なので1通も出ない。

**エラー文は出さない。** 読めなかった行の中身(= 秘密の値の断片)がそのまま載る。
WHY
if ! docker compose config -q >/dev/null 2>&1; then
  add_problem "docker compose が .env を読めません。値に引用符・\\・\$ などが入っていないか確かめてください(ホストで docker compose config -q。エラー文には値の断片が出るので、貼り付けないこと)。"
  exit 1
fi

# **止まっていると撃てない。** 先に確かめて、分かる形で止める
for _svc in mariadb postgres; do
  _cid="$(docker compose ps -q "$_svc" 2>/dev/null | head -n 1 || true)"
  if [ -z "$_cid" ]; then
    add_problem "$_svc が動いていません。docker compose up -d してから取ってください。"
    exit 1
  fi
done

echo "== 1. MariaDB =="
# `--single-transaction`: 止めずに一貫した断面を取る(InnoDB)
# `--routines --events`: 手続きとイベントも(いまは無いが、増えても取り漏らさない)
# **`</dev/null` を付ける。** exec -T は標準入力を読むので、付けないと
# このスクリプトの残りを食べて途中で静かに終わる(既知の罠)
#
# **パスワードは引数ではなく環境変数(MYSQL_PWD)で渡す**(2026-09-14)。
# 以前は `-p"$MARIADB_ROOT_PASSWORD"` で、コンテナの中の mariadb-dump の引数に値が載っていた。
# クライアントは起動直後に伏せるが、その短い間は /proc/<pid>/cmdline から誰にでも読める
# (コンテナのプロセスはホストの ps からも見える)。環境変数は本人と root にしか読めない。
docker compose exec -T mariadb sh -c '
  set -e
  DB=$(printf %s "$MARIADB_DATABASE" | tr -d "\r\n ")
  MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  export MYSQL_PWD
  exec mariadb-dump -uroot \
    --single-transaction --routines --events \
    --default-character-set=utf8mb4 "$DB"
' </dev/null > "$WORK/km-mariadb.sql"

# **「ファイルができた」を成功と見なさない。** 途中で切れても大きさは出る
if ! tail -c 200 "$WORK/km-mariadb.sql" | grep -q 'Dump completed'; then
  add_problem "MariaDB のダンプが途中で切れています(末尾に Dump completed がありません)"
  exit 1
fi
_bytes="$(wc -c < "$WORK/km-mariadb.sql")"
add_part "MariaDB" "$_bytes" "テーブル $(grep -c '^CREATE TABLE' "$WORK/km-mariadb.sql" || true) 個"
echo "  ${_bytes} バイト"

echo "== 2. Postgres(Logto)=="
docker compose exec -T postgres sh -c '
  set -e
  U=$(printf %s "$POSTGRES_USER" | tr -d "\r\n ")
  D=$(printf %s "$POSTGRES_DB" | tr -d "\r\n ")
  exec pg_dump -U "$U" -d "$D"
' </dev/null > "$WORK/km-postgres.sql"
_bytes="$(wc -c < "$WORK/km-postgres.sql")"
add_part "Postgres" "$_bytes" "テーブル $(grep -c '^CREATE TABLE' "$WORK/km-postgres.sql" || true) 個"
echo "  ${_bytes} バイト"

echo "== 3. uploads と設定 =="
# **DB の行だけ移して uploads を忘れると、一覧には出るのに落とせない**状態になる
if [ -d "$PATH_ROOT/src/uploads" ]; then
  # 添付用の作業場は入れない(自分自身を巻き込まないため)
  tar czf "$WORK/km-uploads.tar.gz" -C "$PATH_ROOT/src" --exclude='uploads/.km-attach' uploads
  _bytes="$(wc -c < "$WORK/km-uploads.tar.gz")"
  add_part "uploads" "$_bytes" ""
  echo "  uploads ${_bytes} バイト"
else
  add_problem "src/uploads がありません(ファイル管理の実体が入りません)"
fi

# 復元にはこれが要る。**そして、これが一番秘密**
if [ -f "$PATH_ROOT/.env" ]; then
  cp "$PATH_ROOT/.env" "$WORK/env.txt"
  add_part ".env" "$(wc -c < "$WORK/env.txt")" "秘密を含む"
else
  add_problem ".env がありません(復元に要ります)"
fi
if [ -d "$PATH_ROOT/src/config" ]; then
  # config/*.local.php —— DB 接続情報・解除パスワードのハッシュなど
  tar czf "$WORK/km-config.tar.gz" -C "$PATH_ROOT/src" config
  add_part "config" "$(wc -c < "$WORK/km-config.tar.gz")" "local.php を含む"
else
  add_problem "src/config がありません(復元に要ります)"
fi

# 何が入っているかの目録。**開ける前に中身が分かるように**(復元の突き合わせ用)
{
  echo "host: $HOST_LABEL"
  echo "taken: $(date '+%Y-%m-%d %H:%M:%S %z')"
  echo "files:"
  ls -l "$WORK" | sed 's/^/  /'
} > "$WORK/MANIFEST.txt"

echo "== 4. まとめて暗号化 =="
tar czf "$WORK/bundle.tar.gz" -C "$WORK" \
  $(cd "$WORK" && ls | grep -v '^bundle.tar.gz$' | tr '\n' ' ')

# **`-binary` を必ず付ける。** 付けないと CMS が中身を「文字」として扱い、
# 改行を変換して**壊れた tar.gz** になる(実測: 200,000 バイトが 200,734 バイトに)。
# 開くまで気づけないので、ここは削らないこと。
openssl cms -encrypt -binary -aes-256-cbc \
  -in "$WORK/bundle.tar.gz" -outform DER -out "$OUT" "$CERT"
# 群れにも読み書きを許す(置き場と同じ理由 —— root と km の両方が作り、消す)。
# `umask 077` の下では 600 で出来るので、ここで明示的に開ける
chmod 660 "$OUT"

ARCHIVE_BYTES="$(wc -c < "$OUT")"
echo "  平文 $(wc -c < "$WORK/bundle.tar.gz") バイト → 暗号 ${ARCHIVE_BYTES} バイト"
echo "  $OUT"

# **開けないことは確かめない。** 秘密鍵がここに無いのだから開けなくて当然。
# 代わりに「中身が入っているか」だけ見る(空でも cms は作れてしまう)
if [ "${ARCHIVE_BYTES:-0}" -lt 1000 ]; then
  add_problem "暗号化したファイルが小さすぎます(${ARCHIVE_BYTES} バイト)"
  exit 1
fi

OK=true

echo "== 5. 古い世代を片付け =="
# **うまく取れた時だけ片付ける。** 失敗した回に消すと、手元に1本も残らない
if [ "$OK" = "true" ]; then
  _n=0
  for _old in $(ls -1t "$BACKUP_DIR"/km-backup-*.tar.gz.cms 2>/dev/null || true); do
    _n=$((_n + 1))
    if [ "$_n" -gt "$KEEP" ]; then
      rm -f "$_old"
      echo "  消しました: $(basename "$_old")"
    fi
  done
fi
echo "  ${KEEP} 世代まで残します(いま $(ls -1 "$BACKUP_DIR"/km-backup-*.tar.gz.cms 2>/dev/null | wc -l) 個)"

# ---------------------------------------------------------------------------
# 手元の PC が取りに来ているか(日曜の便りの回だけ)
# ---------------------------------------------------------------------------
# 手元の週次タスクは、失敗すれば PC 側でメールと通知を出す(backup-task-run.ps1)。
# **だが PC の電源が入っていなければ、タスクそのものが走らず、誰も何も言わない。**
#
# 手元から取った控えは、SSH で入ってきた利用者(= PATH_ROOT の持ち主)の持ち物になる。
# cron が作ったものは root の持ち物なので混ざらない。その最新の日付を見る。
if [ "$HEARTBEAT" -eq 1 ]; then
  echo "== 手元の PC が取りに来ているか =="
  _pc_owner="$(stat -c '%U' "$PATH_ROOT" 2>/dev/null || echo '')"
  _pc_latest=""
  if [ -n "$_pc_owner" ]; then
    _pc_latest="$(find "$BACKUP_DIR" -maxdepth 1 -name 'km-backup-*.tar.gz.cms' -user "$_pc_owner" -printf '%T@\n' 2>/dev/null \
      | sort -n | tail -n 1 | cut -d. -f1)"
  fi
  if [ -z "$_pc_latest" ]; then
    add_problem "手元の PC が今週取りに来ていません(${_pc_owner:-?} の持ち物の控えが1本もありません)。PC の電源と週次タスクを確かめてください。"
  else
    _pc_days=$(( ($(date +%s) - _pc_latest) / 86400 ))
    if [ "$_pc_days" -ge "$PC_STALE_DAYS" ]; then
      add_problem "手元の PC が今週取りに来ていません(最後は ${_pc_days} 日前)。PC の電源と週次タスクを確かめてください。"
    else
      echo "  最後に取りに来たのは ${_pc_days} 日前です"
    fi
  fi
fi

# ---------------------------------------------------------------------------
# 添付
# ---------------------------------------------------------------------------
# **素のまま送らない。** 実行記録には `-p…` の行や設定の値が混ざりうる。
# メールは転送も保存もされるので、一度出たら回収できない。
#
# 合言葉は `.env` の `BACKUP_PASSPHRASE`。**無ければ添付しない** ——
# 「暗号化できないので平文で送る」を許すと、忘れた日だけ裸で飛ぶ。
if [ "$ATTACH_LOGS" -eq 1 ]; then
  echo "== 6. 実行記録を添える =="
  BACKUP_PASSPHRASE="$(grep -s '^BACKUP_PASSPHRASE=' "$PATH_ROOT/.env" | head -n 1 | cut -d= -f2- | tr -d '\r\n' || true)"
  # 両端の引用符は .env の書き方であって、合言葉の一部ではない。**付けたまま使うと、
  # 人が打つ合言葉と1文字ずつずれて開けない**(docker compose も外して読む)
  case "$BACKUP_PASSPHRASE" in
    \"*\") BACKUP_PASSPHRASE="${BACKUP_PASSPHRASE#\"}"; BACKUP_PASSPHRASE="${BACKUP_PASSPHRASE%\"}" ;;
    \'*\') BACKUP_PASSPHRASE="${BACKUP_PASSPHRASE#\'}"; BACKUP_PASSPHRASE="${BACKUP_PASSPHRASE%\'}" ;;
  esac
  if [ -z "$BACKUP_PASSPHRASE" ]; then
    ATTACH_NOTE=".env に BACKUP_PASSPHRASE がないので、実行記録は添えていません。"
    echo "  $ATTACH_NOTE"
  <<'WHY' true
  **中に引用符・`\`・`$` を含む合言葉は使わない。**
  docker compose は .env を自分の規則で読む。`"…"` の中の `"` で値が終わり、残りを次の変数名として
  読もうとして **.env 全体を拒む** —— すると `docker compose exec` が全部落ち、DB も取れず、
  メールも1通も出ない(2026-09-13 18:30 に合言葉を差し替えた直後に実際に起きた)。
  `$` は展開されて別の値になる。ここで止めて、本文で理由を知らせる。
WHY
  elif case "$BACKUP_PASSPHRASE" in *\"*|*\'*|*\\*|*\$*|*\`*) true ;; *) false ;; esac; then
    ATTACH_NOTE="BACKUP_PASSPHRASE に引用符・\\・\$ などが入っています。docker compose が .env を読めなくなるので、英数字だけの値にしてください。実行記録は添えていません。"
    echo "  $ATTACH_NOTE"
  elif [ ! -d "$LOG_DIR" ]; then
    ATTACH_NOTE="実行記録の置き場がありません: $LOG_DIR"
    echo "  $ATTACH_NOTE"
  else
    # **作れなかったら添えない。** 以前は mkdir が落ちても先へ進んでいた
    # (km で手で走らせると src/uploads に書けず、/var/log/kosenmap も読めない。cron は root なので通る)。
    # **合言葉をコマンド行に書かない。** ps に出ると、そこから漏れる(ファイル経由で渡す)
    if mkdir -p "$ATTACH_DIR" 2>/dev/null && chmod 700 "$ATTACH_DIR" \
      && tar czf "$WORK/logs.tar.gz" -C "$(dirname "$LOG_DIR")" "$(basename "$LOG_DIR")" \
      && printf '%s' "$BACKUP_PASSPHRASE" > "$WORK/pass" \
      && openssl enc -aes-256-cbc -pbkdf2 -salt \
        -in "$WORK/logs.tar.gz" -out "$ATTACH_DIR/kosenmap-logs-${STAMP}.tar.gz.enc" \
        -pass file:"$WORK/pass"; then
      ATTACH_ARGS="--attach /var/www/html/uploads/.km-attach/kosenmap-logs-${STAMP}.tar.gz.enc"
      ATTACH_NOTE="実行記録は合言葉つきで暗号化しています。開き方: openssl enc -d -aes-256-cbc -pbkdf2 -in <ファイル> -out logs.tar.gz"
      echo "  添えます: kosenmap-logs-${STAMP}.tar.gz.enc"
    else
      ATTACH_NOTE="実行記録を暗号化して添えられませんでした(書き込みか読み取りの権限。root で走らせてください)。控えそのものは取れています。"
      echo "  $ATTACH_NOTE"
    fi
  fi
fi

<<'WHY' true
**取りに来る側が読む一行。人向けの文章とは別に出す。**

backup-data.ps1 は以前、出力の中から `km-backup-*.cms` に見える行を拾い、
その**最後のもの**を取っていた。ところが世代整理が

    消しました: km-backup-ubuntu-20260907-114924.tar.gz.cms

と出すので、**いま作ったものではなく、いま消したものを掴んだ**
(2026-09-07 に実測。scp が "No such file or directory" で落ちた)。

「作ったもの」と「消したもの」が同じ形をしている以上、**文章から見分けるのは無理**。
機械が読む行を1本立て、そこだけを見るようにする。
人向けの案内文を足しても増やしても、この行は動かない。

`KM-ARCHIVE ` で始まる行は、**成功したときに一度だけ**出る。
WHY
printf '%s\n' "KM-ARCHIVE $ARCHIVE_NAME"

echo ""
echo "できました。手元の PC から取りに来てください:"
# **echo にバックスラッシュを渡さない。** dash の echo は `\b` を後退文字と解釈するので、
# `.\backup-data.ps1` が `ackup-data.ps1` と表示され、**その通り打つと動かない**
# (実際に利用者が打って CommandNotFoundException になった。2026-09-07)。
# printf の %s は引数側のエスケープを解釈しないので、書いたものがそのまま出る。
printf '%s\n' '  .\backup-data.ps1'
