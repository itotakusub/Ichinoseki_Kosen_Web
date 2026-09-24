#!/bin/sh
#
# SSH の接続を切る・接続元を BAN する(2026-09-25、利用者の指示)。**root で走らせる。**
#
#   sudo kosenmap-ssh-kick --list                いま繋がっている SSH(利用者・接続元)を見る
#   sudo kosenmap-ssh-kick --ip 203.0.113.5      その接続元からの SSH を切る
#   sudo kosenmap-ssh-kick --ip 203.0.113.5 --ban   切って、その接続元を BAN する
#   sudo kosenmap-ssh-kick --unban 203.0.113.5   BAN を解く
#   sudo kosenmap-ssh-kick --user kmops          その利用者の SSH をすべて切る
#   sudo kosenmap-ssh-kick --lock-user kmops     その利用者を以後ログインできなくする(鍵でも)
#   sudo kosenmap-ssh-kick --unlock-user kmops   上を戻す
#   sudo kosenmap-ssh-kick --trust 203.0.113.5   その接続元を信頼する(知らせが「信頼済み」になり、重要が付かない)
#   sudo kosenmap-ssh-kick --untrust 203.0.113.5 信頼をやめる
#   sudo kosenmap-ssh-kick --list-trusted        信頼している接続元を見る
#
# ログインの知らせ(ssh-login-notify.sh)のメールに、そのときの接続元を入れた形で載る。
# 置き場は /usr/local/sbin/kosenmap-ssh-kick(ssh-login-notify-setup.sh --fix が root:root 755 で写す)。
#
# ## 切り方
#
# systemd-logind のセッションを終わらせる(loginctl terminate-session)。
# logind に載らない接続(強制コマンドだけの鍵など)に備えて、**22 番に繋がっている sshd を
# 接続元で探して止める**方も必ず行う。
#
# ## BAN のしかた
#
# fail2ban が動いていれば、その sshd の牢に入れる(解くのも fail2ban で。期限は fail2ban の設定)。
# 動いていなければ、iptables で 22 番だけ落とす(**再起動で消える**。そのことを言う)。
#
# ## 自分を切らない
#
# いま sudo を叩いている自分の接続元・利用者を指したら止める(--force で押し切れる)。
# 鍵を盗まれた相手と同じ経路(同じ NAT の中など)から入っていると、自分も締め出される。
#
# ## 信頼する接続元(2026-09-25)
#
# 一覧は /etc/kosenmap/ssh-trusted-ips(root:root 644、1 行に 1 つ)。**root だけが書ける** ——
# 配備の利用者が書き換えられると、攻撃側が自分の接続元を「信頼済み」にして目立たなくできる。
# 信頼しても**止めるものは何も無い**(知らせの件名と重要の有無が変わるだけ)。
#
# ## 鍵を盗まれたとき
#
# **接続元を BAN しても、鍵があれば別の場所から入り直せる。** 本当に塞ぐのは、
# その利用者の ~/.ssh/authorized_keys から鍵の行を外すこと(docs/12 の手順)。
# --lock-user はそれまでのつなぎ(アカウントの有効期限を過去にする。PAM が鍵でのログインも止める)。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
LOG="/var/log/kosenmap/ssh-kick.log"
TRUSTED_FILE="/etc/kosenmap/ssh-trusted-ips"

usage() {
  sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'
  exit 2
}

if [ "$(id -u)" -ne 0 ]; then
  echo "root で走らせてください(sudo を付けてください)" >&2
  exit 2
fi

ACTION=""
TARGET=""
BAN=0
FORCE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --list) ACTION=list; shift ;;
    --ip) ACTION=ip; TARGET="${2:-}"; shift 2 ;;
    --user) ACTION=user; TARGET="${2:-}"; shift 2 ;;
    --unban) ACTION=unban; TARGET="${2:-}"; shift 2 ;;
    --lock-user) ACTION=lock; TARGET="${2:-}"; shift 2 ;;
    --unlock-user) ACTION=unlock; TARGET="${2:-}"; shift 2 ;;
    --trust) ACTION=trust; TARGET="${2:-}"; shift 2 ;;
    --untrust) ACTION=untrust; TARGET="${2:-}"; shift 2 ;;
    --list-trusted) ACTION=list-trusted; shift ;;
    --ban) BAN=1; shift ;;
    --force) FORCE=1; shift ;;
    *) usage ;;
  esac
done

if [ -z "$ACTION" ]; then
  usage
fi

log() {
  mkdir -p "$(dirname "$LOG")" 2>/dev/null || true
  echo "$(date '+%F %T') [${SUDO_USER:-root}] $1" >> "$LOG" 2>/dev/null || true
  echo "$1"
}

# 形を確かめる。**コマンドに渡す前に。** IPv4 / IPv6 / 利用者名だけを通す
valid_ip() {
  case "$1" in
    ''|*[!0-9A-Fa-f.:]*) return 1 ;;
  esac
  case "$1" in
    *.*.*.*|*:*) return 0 ;;
  esac
  return 1
}
valid_user() {
  case "$1" in
    ''|-*|*[!A-Za-z0-9._-]*) return 1 ;;
  esac
  return 0
}

# いま sudo を叩いている自分の接続元と利用者
SELF_USER="${SUDO_USER:-}"
SELF_IP="$(who -m 2>/dev/null | sed -n 's/.*(\(.*\)).*/\1/p' | head -n 1)"

list_sessions() {
  echo "== いまの SSH の接続(logind) =="
  if command -v loginctl >/dev/null 2>&1; then
    loginctl list-sessions --no-legend 2>/dev/null | while read -r _sid _rest; do
      [ -n "$_sid" ] || continue
      _u="$(loginctl show-session "$_sid" -p Name --value 2>/dev/null || true)"
      _h="$(loginctl show-session "$_sid" -p RemoteHost --value 2>/dev/null || true)"
      _s="$(loginctl show-session "$_sid" -p Service --value 2>/dev/null || true)"
      if [ "$_s" = "sshd" ]; then
        echo "  セッション $_sid  利用者 $_u  接続元 ${_h:-?}"
      fi
    done
  fi
  echo "== 22 番に繋がっているもの =="
  ss -Htnp state established '( sport = :22 )' 2>/dev/null | awk '{print "  " $4 "  " $5}' || true
  if [ -n "$SELF_IP" ]; then
    echo "(あなたの接続元: $SELF_IP)"
  fi
}

kick_ip() {
  _ip="$1"
  _n=0
  if command -v loginctl >/dev/null 2>&1; then
    for _sid in $(loginctl list-sessions --no-legend 2>/dev/null | awk '{print $1}'); do
      _h="$(loginctl show-session "$_sid" -p RemoteHost --value 2>/dev/null || true)"
      if [ "$_h" = "$_ip" ]; then
        loginctl terminate-session "$_sid" 2>/dev/null || true
        _n=$((_n + 1))
      fi
    done
  fi
  # logind に載らない接続も。接続元が一致する sshd を止める
  for _pid in $(ss -Htnp state established '( sport = :22 )' 2>/dev/null \
      | awk -v ip="$_ip" '{ peer=$4; sub(/:[0-9]+$/, "", peer); gsub(/^\[|\]$/, "", peer); sub(/^::ffff:/, "", peer); if (peer == ip) print }' \
      | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u); do
    kill -KILL "$_pid" 2>/dev/null || true
    _n=$((_n + 1))
  done
  log "切った: 接続元 $_ip ($_n 件)"
}

ban_ip() {
  _ip="$1"
  if command -v fail2ban-client >/dev/null 2>&1 && systemctl is-active --quiet fail2ban 2>/dev/null; then
    if fail2ban-client set sshd banip "$_ip" >/dev/null 2>&1; then
      log "BAN した(fail2ban の sshd): $_ip  解くとき: sudo kosenmap-ssh-kick --unban $_ip"
      return 0
    fi
    echo "fail2ban の sshd の牢に入れられませんでした。iptables で落とします。" >&2
  fi
  if printf '%s' "$_ip" | grep -q ':'; then
    _cmd=ip6tables
  else
    _cmd=iptables
  fi
  if ! "$_cmd" -C INPUT -s "$_ip" -p tcp --dport 22 -j DROP 2>/dev/null; then
    "$_cmd" -I INPUT -s "$_ip" -p tcp --dport 22 -j DROP
  fi
  log "BAN した($_cmd。22 番だけ。**再起動で消えます**): $_ip  解くとき: sudo kosenmap-ssh-kick --unban $_ip"
}

unban_ip() {
  _ip="$1"
  _done=0
  if command -v fail2ban-client >/dev/null 2>&1 && systemctl is-active --quiet fail2ban 2>/dev/null; then
    if fail2ban-client set sshd unbanip "$_ip" >/dev/null 2>&1; then _done=1; fi
  fi
  for _cmd in iptables ip6tables; do
    while "$_cmd" -C INPUT -s "$_ip" -p tcp --dport 22 -j DROP 2>/dev/null; do
      "$_cmd" -D INPUT -s "$_ip" -p tcp --dport 22 -j DROP
      _done=1
    done
  done
  if [ "$_done" -eq 1 ]; then
    log "BAN を解いた: $_ip"
  else
    log "BAN は見つかりませんでした: $_ip"
  fi
}

case "$ACTION" in
  list)
    list_sessions
    ;;
  ip)
    if ! valid_ip "$TARGET"; then
      echo "IP の形ではありません: $TARGET" >&2
      exit 2
    fi
    if [ "$TARGET" = "$SELF_IP" ] && [ "$FORCE" -eq 0 ]; then
      echo "それはあなた自身の接続元です($SELF_IP)。自分も切れます。押し切るなら --force" >&2
      exit 2
    fi
    kick_ip "$TARGET"
    if [ "$BAN" -eq 1 ]; then
      ban_ip "$TARGET"
    fi
    ;;
  unban)
    if ! valid_ip "$TARGET"; then
      echo "IP の形ではありません: $TARGET" >&2
      exit 2
    fi
    unban_ip "$TARGET"
    ;;
  user)
    if ! valid_user "$TARGET"; then
      echo "利用者名の形ではありません: $TARGET" >&2
      exit 2
    fi
    if [ "$TARGET" = "$SELF_USER" ] && [ "$FORCE" -eq 0 ]; then
      echo "それはあなた自身です($SELF_USER)。自分も切れます。押し切るなら --force" >&2
      exit 2
    fi
    loginctl terminate-user "$TARGET" 2>/dev/null || true
    # logind に載らない分も。その利用者の sshd を止める
    pkill -KILL -u "$TARGET" -x sshd 2>/dev/null || true
    log "切った: 利用者 $TARGET のすべての SSH"
    ;;
  lock)
    if ! valid_user "$TARGET"; then
      echo "利用者名の形ではありません: $TARGET" >&2
      exit 2
    fi
    if [ "$TARGET" = "$SELF_USER" ] && [ "$FORCE" -eq 0 ]; then
      echo "それはあなた自身です。自分が入れなくなります。押し切るなら --force" >&2
      exit 2
    fi
    # 有効期限を過去にする。PAM の account が止めるので、**鍵でのログインも**通らない
    usermod --expiredate 1 "$TARGET"
    loginctl terminate-user "$TARGET" 2>/dev/null || true
    pkill -KILL -u "$TARGET" -x sshd 2>/dev/null || true
    log "止めた: 利用者 $TARGET(以後ログインできません)。戻すとき: sudo kosenmap-ssh-kick --unlock-user $TARGET"
    echo "鍵を盗まれたなら、~$TARGET/.ssh/authorized_keys から鍵の行も外してください(docs/12)。"
    ;;
  unlock)
    if ! valid_user "$TARGET"; then
      echo "利用者名の形ではありません: $TARGET" >&2
      exit 2
    fi
    usermod --expiredate '' "$TARGET"
    log "戻した: 利用者 $TARGET(ログインできます)"
    ;;
  trust)
    if ! valid_ip "$TARGET"; then
      echo "IP の形ではありません: $TARGET" >&2
      exit 2
    fi
    mkdir -p "$(dirname "$TRUSTED_FILE")"
    chown root:root "$(dirname "$TRUSTED_FILE")"
    chmod 755 "$(dirname "$TRUSTED_FILE")"
    touch "$TRUSTED_FILE"
    chown root:root "$TRUSTED_FILE"
    chmod 644 "$TRUSTED_FILE"
    if grep -qFx -- "$TARGET" "$TRUSTED_FILE"; then
      log "もう信頼しています: $TARGET"
    else
      echo "$TARGET" >> "$TRUSTED_FILE"
      log "信頼しました: $TARGET(次からの知らせは「信頼済み」。やめるとき: sudo kosenmap-ssh-kick --untrust $TARGET)"
    fi
    ;;
  untrust)
    if ! valid_ip "$TARGET"; then
      echo "IP の形ではありません: $TARGET" >&2
      exit 2
    fi
    if [ -f "$TRUSTED_FILE" ] && grep -qFx -- "$TARGET" "$TRUSTED_FILE"; then
      # 一時ファイルに書いてから置き換える(途中で止まっても一覧が空にならない)
      grep -vFx -- "$TARGET" "$TRUSTED_FILE" > "$TRUSTED_FILE.tmp" || true
      chown root:root "$TRUSTED_FILE.tmp"
      chmod 644 "$TRUSTED_FILE.tmp"
      mv -f "$TRUSTED_FILE.tmp" "$TRUSTED_FILE"
      log "信頼をやめました: $TARGET"
    else
      log "信頼の一覧にありません: $TARGET"
    fi
    ;;
  list-trusted)
    echo "== 信頼している接続元($TRUSTED_FILE) =="
    if [ -s "$TRUSTED_FILE" ]; then
      sed 's/^/  /' "$TRUSTED_FILE"
    else
      echo "  (まだありません)"
    fi
    if [ -n "$SELF_IP" ]; then
      echo "(あなたの今の接続元: $SELF_IP)"
    fi
    ;;
esac
