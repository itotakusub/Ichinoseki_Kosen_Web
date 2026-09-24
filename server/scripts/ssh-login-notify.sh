#!/bin/sh
#
# SSH でログインがあったら、管理者(MAIL_ADMIN_TO)へメールで知らせる(2026-09-25、利用者の指示)。
#
# ## 呼ばれ方
#
# **PAM(pam_exec)から、root で呼ばれる。** /etc/pam.d/sshd に次の 1 行を足す:
#
#   session optional pam_exec.so quiet /usr/local/sbin/kosenmap-ssh-login-notify
#
# 仕込みと点検は scripts/ssh-login-notify-setup.sh(調べるだけが既定、--fix で仕込む)。
#
# ## /opt/kosenmap/scripts から直接は呼ばせない
#
# root が走らせるものを、配備の利用者(kmops)が書き換えられる場所に置かない。
# setup が **/usr/local/sbin へ root:root 755 で写す**。中身を変えたら、setup --fix で写し直す
# (setup は写しと原本を突き合わせ、ずれていれば「古い形」と言う)。
#
# ## ログインを邪魔しない
#
#   - PAM は `optional`。この script が落ちても、無くても、ログインは通る
#   - **送信は裏で行い、すぐ 0 で戻る。** メールを待たせると、送信が詰まったときにログインが詰まる
#   - 送れなかったことは /var/log/kosenmap/ssh-login.log に残す
#
# ## 同じ知らせは 10 分まとめる
#
# 配備(deploy-to-host.ps1)は 1 回で何本も SSH を張る。1 本ずつ送ると、配備のたびに
# 何通も届いて**読まれなくなる**。同じ利用者・同じ接続元は 10 分に 1 通にする
# (まとめた回数は次の 1 通に書く)。利用者か接続元が違えば、すぐ送る。
#
# 試すとき:  sudo /usr/local/sbin/kosenmap-ssh-login-notify --test
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -u

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
PATH_ROOT="/opt/kosenmap"
LOG_DIR="/var/log/kosenmap"
STATE_DIR="/run/kosenmap-ssh-notify"
COOLDOWN=600

TEST=0
if [ "${1:-}" = "--test" ]; then
  TEST=1
  PAM_TYPE="open_session"
  PAM_USER="${SUDO_USER:-$(id -un)}"
  PAM_RHOST="(試験)"
  PAM_SERVICE="sshd"
fi

# 開くときだけ。閉じるとき(close_session)は知らせない
if [ "${PAM_TYPE:-}" != "open_session" ]; then
  exit 0
fi

# **PAM から来る値は信じない。** 名前と接続元は見える文字だけに絞ってから使う
safe() {
  printf '%s' "$1" | tr -c 'A-Za-z0-9._:@()-' '_' | cut -c1-64
}
USER_SAFE="$(safe "${PAM_USER:-?}")"
RHOST_SAFE="$(safe "${PAM_RHOST:-?}")"
SERVICE_SAFE="$(safe "${PAM_SERVICE:-?}")"

mkdir -p "$STATE_DIR" 2>/dev/null || true
chmod 700 "$STATE_DIR" 2>/dev/null || true
mkdir -p "$LOG_DIR" 2>/dev/null || true

NOW="$(date +%s)"
STAMP="$STATE_DIR/$(printf '%s_%s' "$USER_SAFE" "$RHOST_SAFE" | tr -c 'A-Za-z0-9._-' '_')"
SKIPPED=0
if [ "$TEST" -eq 0 ] && [ -f "$STAMP" ]; then
  LAST="$(sed -n 1p "$STAMP" 2>/dev/null)"
  SKIPPED="$(sed -n 2p "$STAMP" 2>/dev/null)"
  case "$LAST" in ''|*[!0-9]*) LAST=0 ;; esac
  case "$SKIPPED" in ''|*[!0-9]*) SKIPPED=0 ;; esac
  if [ $((NOW - LAST)) -lt "$COOLDOWN" ]; then
    # まとめる。回数だけ数えて、次の 1 通に書く
    printf '%s\n%s\n' "$LAST" $((SKIPPED + 1)) > "$STAMP" 2>/dev/null || true
    echo "$(date '+%F %T') まとめた: $USER_SAFE from $RHOST_SAFE" >> "$LOG_DIR/ssh-login.log" 2>/dev/null || true
    exit 0
  fi
fi
printf '%s\n0\n' "$NOW" > "$STAMP" 2>/dev/null || true

HOST_LABEL="$(hostname 2>/dev/null || echo unknown)"
WHEN="$(date '+%Y-%m-%d %H:%M:%S %z')"

LABEL="SSH ログイン"
if [ "$TEST" -eq 1 ]; then
  LABEL="【試験】SSH ログイン"
fi

# 本文。JSON の中に入れるので、**改行は \n、それ以外は見える文字だけ**で組む
TEXT="SSH でログインがありました。\\n\\n利用者: $USER_SAFE\\n接続元: $RHOST_SAFE\\n時刻: $WHEN\\n入口: $SERVICE_SAFE"
if [ "$SKIPPED" -gt 0 ]; then
  TEXT="$TEXT\\n\\n(この前の 10 分間に、同じ利用者・同じ接続元からのログインが $SKIPPED 回ありました。まとめて 1 通にしています)"
fi
#
# 心当たりが無いときに**そのまま貼れる**コマンドを添える(2026-09-25、利用者の指示)。
# メールのリンクから切る形にはしない —— メールの安全確認がリンクを先に開くと、
# 本人が押す前に切れてしまう(ホストへ root の操作を通す入口も増える)。
#
TEXT="$TEXT\\n\\n心当たりが無いときは、ホストに入って次を貼ってください(km で入り、sudo)。\\n"
TEXT="$TEXT\\n  いまの接続を見る:        sudo kosenmap-ssh-kick --list"
TEXT="$TEXT\\n  この接続元を切る:        sudo kosenmap-ssh-kick --ip $RHOST_SAFE"
TEXT="$TEXT\\n  切って BAN する:         sudo kosenmap-ssh-kick --ip $RHOST_SAFE --ban"
TEXT="$TEXT\\n  この利用者をすべて切る:  sudo kosenmap-ssh-kick --user $USER_SAFE"
TEXT="$TEXT\\n  この利用者を止める:      sudo kosenmap-ssh-kick --lock-user $USER_SAFE"
TEXT="$TEXT\\n  (戻すとき: --unban $RHOST_SAFE / --unlock-user $USER_SAFE)"
TEXT="$TEXT\\n\\n接続元を BAN しても、鍵があれば別の場所から入り直せます。\\n鍵を盗まれたなら、その利用者の ~/.ssh/authorized_keys から鍵の行を外してください(docs/12)。"
TEXT="$TEXT\\n\\n配備やバックアップの自動ログインでも届きます(同じ利用者・同じ接続元は 10 分に 1 通)。"

REPORT="{\"label\":\"$LABEL\",\"host\":\"$(safe "$HOST_LABEL")\",\"status\":\"info\",\"exitCode\":null,\"text\":\"$TEXT\"}"

# **裏で送って、すぐ戻る。** PAM(=ログイン)を送信の完了まで待たせない
(
  cd "$PATH_ROOT" 2>/dev/null || { echo "$(date '+%F %T') $PATH_ROOT がありません" >> "$LOG_DIR/ssh-login.log"; exit 1; }
  if printf '%s' "$REPORT" | timeout 60 docker compose exec -T web php scripts/notify-log.php >> "$LOG_DIR/ssh-login.log" 2>&1; then
    echo "$(date '+%F %T') 送った: $USER_SAFE from $RHOST_SAFE" >> "$LOG_DIR/ssh-login.log"
  else
    echo "$(date '+%F %T') 送れなかった: $USER_SAFE from $RHOST_SAFE" >> "$LOG_DIR/ssh-login.log"
  fi
) </dev/null >/dev/null 2>&1 &

if [ "$TEST" -eq 1 ]; then
  # 試すときは結果が見えるよう待つ
  wait
  tail -n 3 "$LOG_DIR/ssh-login.log" 2>/dev/null || true
fi
exit 0
