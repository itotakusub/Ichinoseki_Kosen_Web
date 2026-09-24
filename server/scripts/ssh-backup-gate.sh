#!/bin/sh
#
# 控え専用の SSH 鍵の門番。~km/.ssh/authorized_keys で、その鍵にだけ次の形で付ける:
#
#   restrict,command="/opt/kosenmap/scripts/ssh-backup-gate.sh" ssh-ed25519 AAAA… km-backup@<PC>
#
# この鍵で入ったときに通すのは 2 つだけ(それ以外は何もせずに断る):
#
#   backup [--notify] [--heartbeat] [--attach-logs] [--pc-stale-days N]
#                          … host-backup.sh を走らせる(出力の KM-ARCHIVE 行もそのまま返す)
#   fetch <km-backup-….tar.gz.cms>
#                          … backups/ にある控えを 1 本、標準出力へ流す
#   scp -f <PATH>/backups/<km-backup-….tar.gz.cms>
#                          … 上と同じものを scp で(PC 側は `scp -O`。SFTP は通さない)
#   ping                   … 繋がるかだけを見る(km-ok を返す。km-ssh.ps1 の Assert-KmSshReady が使う)
#   report-failure [--test]
#                          … 標準入力の記録(200 KB まで)を、決まった件名で send-log.sh に渡す
#                            (PC の週次の控えが失敗したときの知らせ。backup-task-run.ps1。件名も宛先も選ばせない)
#
# ## なぜ要るのか(2026-09-17、docs/12 §7-4)
#
# 週次の控えは、PC から km の鍵で SSH して取っていた。その鍵は**対話のシェルも sudo も docker も使える**ので、
# PC(か鍵のファイル)が取られると、ホストがまるごと取られる。
# 控えに要るのは「作る」と「取る」だけ。鍵を分けてそこまでに絞れば、盗まれても**暗号化された控えが読めるだけ**
# (開ける秘密鍵は PC にしかない。host-backup.sh の冒頭)。
#
# ## 越えさせないために
#
# - **SSH_ORIGINAL_COMMAND を sh に渡さない。** 語に割って、形を 1 つずつ照らす
# - ファイル名は `km-backup-` で始まり `.tar.gz.cms` で終わる、英数字・`-`・`.`・`_` だけ(`/` と `..` を通さない)
# - `restrict` で転送・pty・X11・agent を切る(authorized_keys 側)
#
# 残るもの: host-backup.sh は入った利用者の権限(docker グループ)で動く。**門番が縛るのは鍵であって利用者ではない。**
# 2026-09-18 にこの行を km から kmops(docker あり・sudo なし)へ移し、km を docker から外す段に進んだ(host-ops-user.sh)。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない。

set -eu

PATH_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="$PATH_ROOT/backups"

deny() {
  echo "ssh-backup-gate: 許可していない操作です: $1" >&2
  logger -t ssh-backup-gate "denied from ${SSH_CLIENT%% *}: $1" 2>/dev/null || true
  exit 1
}

valid_name() {
  case "$1" in
    km-backup-*.tar.gz.cms) ;;
    *) return 1 ;;
  esac
  case "$1" in
    *[!A-Za-z0-9._-]*|*..*) return 1 ;;
  esac
  return 0
}

CMD="${SSH_ORIGINAL_COMMAND:-}"
if [ -z "$CMD" ]; then
  deny "(シェル)"
fi
# 改行やシェルの記号が入っていたら、割る前に断る
case "$CMD" in
  *[\;\&\|\`\$\<\>\(\)\\\"\']*) deny "$CMD" ;;
esac
case "$CMD" in
  *"
"*) deny "(改行を含む)" ;;
esac

# shellcheck disable=SC2086
set -f
set -- $CMD
set +f

logger -t ssh-backup-gate "request from ${SSH_CLIENT%% *}: $CMD" 2>/dev/null || true

case "${1:-}" in
  backup)
    shift
    _args=""
    while [ "$#" -gt 0 ]; do
      case "$1" in
        --notify|--heartbeat|--attach-logs) _args="$_args $1"; shift ;;
        --pc-stale-days)
          case "${2:-}" in
            ''|*[!0-9]*) deny "$CMD" ;;
          esac
          _args="$_args $1 $2"; shift 2 ;;
        *) deny "$CMD" ;;
      esac
    done
    # shellcheck disable=SC2086
    exec sh "$PATH_ROOT/scripts/host-backup.sh" $_args </dev/null
    ;;
  fetch)
    if [ "$#" -ne 2 ] || ! valid_name "$2"; then
      deny "$CMD"
    fi
    if [ ! -f "$BACKUP_DIR/$2" ]; then
      echo "ssh-backup-gate: ありません: $2" >&2
      exit 1
    fi
    exec cat "$BACKUP_DIR/$2"
    ;;
  scp)
    # 旧来の scp プロトコルで「取る」だけ(scp -f)。-r・-t(書き込み)は通さない
    if [ "$#" -ne 3 ] || [ "$2" != "-f" ]; then
      deny "$CMD"
    fi
    _name="${3##*/}"
    if [ "$3" != "$BACKUP_DIR/$_name" ] || ! valid_name "$_name" || [ ! -f "$3" ]; then
      deny "$CMD"
    fi
    exec scp -f "$3"
    ;;
  ping)
    if [ "$#" -ne 1 ]; then
      deny "$CMD"
    fi
    echo km-ok
    ;;
  report-failure)
    # 件名・状態・宛先は**ここで決める**(送る側に選ばせない)。本文は伏せ字を掛けてから送られる(send-log.sh)
    _label="手元の週次バックアップ(PC)"
    if [ "$#" -eq 2 ] && [ "$2" = "--test" ]; then
      _label="【試験】$_label"
    elif [ "$#" -ne 1 ]; then
      deny "$CMD"
    fi
    head -c 200000 | exec sh "$PATH_ROOT/scripts/send-log.sh" --path "$PATH_ROOT" --label "$_label" --status ng
    ;;
  *)
    deny "$CMD"
    ;;
esac
