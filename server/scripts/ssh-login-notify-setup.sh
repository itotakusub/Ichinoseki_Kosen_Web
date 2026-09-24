#!/bin/sh
#
# SSH ログインの知らせと、切る・BAN する道具をホストへ仕込む(2026-09-25)。
# **調べるだけが既定。直すには --fix。**
#
#   sudo ./ssh-login-notify-setup.sh          いまどうなっているかを見る
#   sudo ./ssh-login-notify-setup.sh --fix    仕込む(写しの更新・PAM の 1 行)
#   sudo /usr/local/sbin/kosenmap-ssh-login-notify --test    試しに 1 通送る
#
# 入れるものは3つ:
#
#   1. /usr/local/sbin/kosenmap-ssh-login-notify   (scripts/ssh-login-notify.sh の写し)
#   2. /usr/local/sbin/kosenmap-ssh-kick           (scripts/ssh-kick.sh の写し)
#   3. /etc/pam.d/sshd に 1 行
#        session optional pam_exec.so quiet /usr/local/sbin/kosenmap-ssh-login-notify
#
# ## 写しにする理由
#
# **root が走らせるものを、配備の利用者が書き換えられる場所に置かない。**
# /opt/kosenmap/scripts は配備(kmops)が書く。PAM はログインのたびに root で走らせるので、
# そこを直接指すと「配備の鍵 → root」の道が 1 本増える。写しは root:root 755。
# 原本を変えたら --fix で写し直す(ここで突き合わせて、ずれていれば言う)。
#
# ## ログインを壊さない
#
# PAM の行は `optional`。写しが無くても、落ちても、ログインは通る。
# それでも **PAM を触るときは、別の SSH を 1 本開いたまま**試すこと。
# 書き換える前の /etc/pam.d/sshd は /etc/pam.d/sshd.kosenmap-bak に残す。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
FIX=0

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --fix) FIX=1; shift ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ "$(id -u)" -ne 0 ]; then
  echo "root で走らせてください(sudo を付けてください)" >&2
  exit 2
fi

PAM_FILE="/etc/pam.d/sshd"
PAM_LINE="session optional pam_exec.so quiet /usr/local/sbin/kosenmap-ssh-login-notify"
PAM_MARK="# kosenmap-ssh-login-notify"

PROBLEMS=0
note() { echo "  $1"; }
bad() { echo "  ★ $1"; PROBLEMS=$((PROBLEMS + 1)); }

install_copy() {
  _src="$1"
  _dst="$2"
  if [ ! -f "$_src" ]; then
    bad "原本がありません: $_src(配備を先に)"
    return 0
  fi
  if [ -f "$_dst" ] && cmp -s "$_src" "$_dst"; then
    _owner="$(stat -c '%U:%G %a' "$_dst" 2>/dev/null || echo '?')"
    if [ "$_owner" = "root:root 755" ]; then
      note "あり(原本と同じ・root:root 755): $_dst"
      return 0
    fi
    bad "持ち主か権限が違います($_owner。root:root 755 が要る): $_dst"
  elif [ -f "$_dst" ]; then
    bad "古い形です(原本と違う): $_dst"
  else
    bad "ありません: $_dst"
  fi
  if [ "$FIX" -eq 1 ]; then
    # 一時ファイルに書いてから置き換える(書きかけのものを PAM に走らせない)
    install -o root -g root -m 755 "$_src" "$_dst.tmp"
    mv -f "$_dst.tmp" "$_dst"
    note "写しました: $_dst"
    PROBLEMS=$((PROBLEMS - 1))
  fi
}

echo "== 写し(/usr/local/sbin) =="
install_copy "$PATH_ROOT/scripts/ssh-login-notify.sh" /usr/local/sbin/kosenmap-ssh-login-notify
install_copy "$PATH_ROOT/scripts/ssh-kick.sh" /usr/local/sbin/kosenmap-ssh-kick

echo ""
echo "== sshd が PAM を通すか =="
_usepam="$(sshd -T 2>/dev/null | awk '$1 == "usepam" {print $2}')"
if [ "$_usepam" = "yes" ]; then
  note "UsePAM yes(PAM の session が走ります)"
else
  bad "UsePAM が yes ではありません(${_usepam:-不明})。この知らせは走りません"
fi

echo ""
echo "== /etc/pam.d/sshd =="
if grep -qF "$PAM_LINE" "$PAM_FILE"; then
  note "あり: $PAM_LINE"
else
  bad "知らせの 1 行がありません"
  if [ "$FIX" -eq 1 ]; then
    cp -p "$PAM_FILE" "$PAM_FILE.kosenmap-bak"
    {
      echo ""
      echo "$PAM_MARK(ssh-login-notify-setup.sh が足した。optional なので落ちてもログインは通る)"
      echo "$PAM_LINE"
    } >> "$PAM_FILE"
    note "足しました(前の形は $PAM_FILE.kosenmap-bak)"
    PROBLEMS=$((PROBLEMS - 1))
  fi
fi

echo ""
echo "== 実行記録 =="
if [ -d /var/log/kosenmap ]; then
  note "記録は /var/log/kosenmap/ssh-login.log と ssh-kick.log に残ります"
else
  note "/var/log/kosenmap がまだありません(host-updates-setup.sh --fix で作られます)"
fi

echo ""
echo "== 通知の宛先 =="
if [ -f "$PATH_ROOT/.env" ] && grep -q '^MAIL_ADMIN_TO=' "$PATH_ROOT/.env"; then
  note "MAIL_ADMIN_TO あり(送り先はここ)"
else
  bad "MAIL_ADMIN_TO が .env にありません(既定の admin@example.test へ飛びます)"
fi

echo ""
if [ "$PROBLEMS" -le 0 ]; then
  echo "すべて問題ありません"
  if [ "$FIX" -eq 1 ]; then
    echo "試すとき: sudo /usr/local/sbin/kosenmap-ssh-login-notify --test"
  fi
  exit 0
fi
if [ "$FIX" -eq 0 ]; then
  echo "気になる点が $PROBLEMS 件あります。直すには --fix を付けてください。"
else
  echo "直しきれなかった点が $PROBLEMS 件あります。上の ★ を確認してください。"
fi
exit 1
