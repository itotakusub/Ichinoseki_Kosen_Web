#!/bin/sh
#
# 利用者が入れた Ubuntu Server 24.04(検証機の VM)を、本番と同じ形の土台にする。**検証機の VM で、sudo で 1 回流す。**
#
#   sudo sh testhost-bootstrap.sh --lan 192.168.3.0/24            入れる・設定する(何度流してもよい)
#   sh testhost-bootstrap.sh --check                              いまの様子を見るだけ(sudo なしでも見える範囲)
#
# ## なぜ要るのか(2026-11-07 段 5)
#
# 検証機は 192.168.3.29 の Windows の Hyper-V の VM(ブリッジ接続)。OS は利用者が入れる(利用者「UbuntuServer は私が作成します。
# ブリッジ接続で行きます」)。入れたあとの土台をこれ 1 本で本番に揃える:
#
#   本番と同じ … docker.com の Docker(docker-ce・compose プラグイン)・fail2ban・unattended-upgrades・タイムゾーン・
#                スワップ 2GB・SSH は鍵だけ・/opt/kosenmap と空の .env(600)・km は最初の配備だけ docker(あとで host-ops-user.sh demote)
#   わざと違う … ufw は --lan の範囲からだけ 22・80・443・8025 / **外へメールを出さない**(25・465・587 をホストの ufw と
#                コンテナの通り道 DOCKER-USER の両方で断る)/ 印 /etc/kosenmap-test-host(local-sanitize.sh などはこれが無いと止まる)
#
# ## 本番では止まる
#
#   - /etc/letsencrypt か /opt/kosenmap/certs に Let's Encrypt の証明書がある
#   - /opt/kosenmap/.env の KM_DOMAIN が本番の名前(ito4.jp・ito8795.com とその下)
#   - この機械の IPv4 が LAN のアドレス(10・172.16-31・192.168)だけでない
#
# **SSH を鍵だけにするのは、km の authorized_keys に鍵があるときだけ**(無いまま締めると閉め出される)。
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

PROD_DOMAINS="ito4.jp ito8795.com"
MARKER=/etc/kosenmap-test-host
ADMIN_USER=km
LAN=""
MODE=apply
while [ $# -gt 0 ]; do
  case "$1" in
    --lan) LAN="$2"; shift 2 ;;
    --admin-user) ADMIN_USER="$2"; shift 2 ;;
    --check) MODE=check; shift ;;
    -h|--help) sed -n '2,28p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

ok()   { printf '  OK   %s\n' "$*"; }
did()  { printf '  直し %s\n' "$*"; }
bad()  { printf '  ★    %s\n' "$*"; }
head_() { printf '\n== %s\n' "$*"; }

# ---------------------------------------------------------------- 見るだけ
show() {
  head_ '様子'
  . /etc/os-release
  ok "$PRETTY_NAME・$(nproc) コア・メモリ $(free -m | awk '/^Mem:/ {print $2}')MB・スワップ $(free -m | awk '/^Swap:/ {print $2}')MB"
  ok "IPv4: $(hostname -I 2>/dev/null)"
  if [ -f "$MARKER" ]; then ok "印: $(cat "$MARKER")"; else bad "印 $MARKER がありません"; fi
  if command -v docker >/dev/null 2>&1; then ok "docker $(docker --version 2>/dev/null | cut -d' ' -f3 | tr -d ,)"; else bad 'docker がありません'; fi
  if id -nG "$ADMIN_USER" 2>/dev/null | grep -qw docker; then ok "$ADMIN_USER は docker に居る(最初の配備のため。kmops へ渡したら demote)"; fi
  if [ -f /etc/ssh/sshd_config.d/60-kosenmap-test.conf ]; then ok 'SSH は鍵だけ(60-kosenmap-test.conf)'; else bad 'SSH の設定(60-kosenmap-test.conf)がありません'; fi
  if systemctl is-enabled km-block-smtp.service >/dev/null 2>&1; then ok '外へのメールを断る(km-block-smtp.service)'; else bad 'km-block-smtp.service がありません'; fi
  if [ -d /opt/kosenmap ]; then ok "/opt/kosenmap($(stat -c %U /opt/kosenmap))"; else bad '/opt/kosenmap がありません'; fi
  ufw status 2>/dev/null | sed 's/^/       /' || true
}

if [ "$MODE" = check ]; then
  show
  exit 0
fi

if [ "$(id -u)" != 0 ]; then
  echo "sudo で流してください: sudo sh $0 --lan 192.168.3.0/24" >&2
  exit 1
fi
if [ -z "$LAN" ] || ! printf '%s' "$LAN" | grep -Eq '^(10|172\.(1[6-9]|2[0-9]|3[01])|192\.168)\.[0-9.]+/[0-9]{1,2}$'; then
  echo "--lan に LAN の範囲を指定してください(例 192.168.3.0/24)" >&2
  exit 2
fi

# ---------------------------------------------------------------- 本番では止まる
head_ '1. 本番ではないことを確かめる'
. /etc/os-release
if [ "${VERSION_ID:-}" != "24.04" ]; then
  echo "★ Ubuntu 24.04 ではありません(${PRETTY_NAME:-?})。本番と同じ 24.04 で入れてください。" >&2
  exit 1
fi
for _d in /etc/letsencrypt/live /opt/kosenmap/certs/live; do
  if [ -d "$_d" ] && [ -n "$(ls -A "$_d" 2>/dev/null)" ]; then
    echo "★ Let's Encrypt の証明書があります($_d)。本番のようなので止めます。" >&2
    exit 1
  fi
done
if [ -f /opt/kosenmap/.env ]; then
  _dom="$(sed -n 's/^KM_DOMAIN=//p' /opt/kosenmap/.env | tail -n 1 | tr -d '\r"'"'")"
  for _p in $PROD_DOMAINS; do
    case "$_dom" in
      "$_p"|*".$_p") echo "★ /opt/kosenmap/.env の KM_DOMAIN が本番の名前です。止めます。" >&2; exit 1 ;;
    esac
  done
fi
for _ip in $(hostname -I 2>/dev/null); do
  case "$_ip" in
    *:*|10.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[01].*) ;;
    *) echo "★ LAN でないアドレス($_ip)を持っています。本番のようなので止めます。" >&2; exit 1 ;;
  esac
done
if ! id "$ADMIN_USER" >/dev/null 2>&1; then
  echo "★ 利用者 $ADMIN_USER がいません(Ubuntu を入れるときの利用者名を km に)。" >&2
  exit 1
fi
ok "Ubuntu 24.04・LAN だけ・本番の証明書なし"

head_ '2. 印'
if [ ! -f "$MARKER" ]; then
  printf 'KosenMap test host. Set up by testhost-bootstrap.sh at %s. No production secrets here.\n' "$(date '+%F %T')" > "$MARKER"
  chmod 644 "$MARKER"
  did "$MARKER を置きました"
else
  ok "$MARKER があります"
fi

head_ '3. パッケージ・タイムゾーン'
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl openssl fail2ban unattended-upgrades ufw >/dev/null
# Hyper-V の連携(IP を Hyper-V マネージャーに見せる)。無い版の kernel でも止めない
apt-get install -y -qq linux-cloud-tools-virtual >/dev/null 2>&1 || true
timedatectl set-timezone Asia/Tokyo
ok "入れました・タイムゾーン Asia/Tokyo"

head_ '4. Docker(本番と同じ docker.com の apt)'
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu %s stable\n' \
    "$(dpkg --print-architecture)" "$VERSION_CODENAME" > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
  did "docker $(docker --version | cut -d' ' -f3 | tr -d ,) を入れました"
else
  ok "docker $(docker --version | cut -d' ' -f3 | tr -d ,) があります"
fi
# 最初の配備だけ km が docker を使う。kmops を作って渡したら host-ops-user.sh demote で外す(本番 09-18 と同じ順)
if ! id -nG "$ADMIN_USER" | grep -qw docker; then
  usermod -aG docker "$ADMIN_USER"
  did "$ADMIN_USER を docker に入れました(入り直すと効く。kmops へ渡したら demote)"
fi

head_ '5. スワップ 2GB(本番と同じ)'
_swap="$(free -m | awk '/^Swap:/ {print $2}')"
if [ "${_swap:-0}" -ge 1800 ]; then
  ok "スワップ ${_swap}MB"
else
  if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
  fi
  swapon /swapfile 2>/dev/null || true
  if ! grep -q '^/swapfile ' /etc/fstab; then
    printf '/swapfile none swap sw 0 0\n' >> /etc/fstab
  fi
  did "/swapfile 2GB を足しました(いま $(free -m | awk '/^Swap:/ {print $2}')MB)"
fi

head_ '6. 置き場'
if [ ! -d /opt/kosenmap ]; then
  install -d -o "$ADMIN_USER" -g "$ADMIN_USER" -m 755 /opt/kosenmap
  did '/opt/kosenmap を作りました'
fi
if [ ! -f /opt/kosenmap/.env ]; then
  install -o "$ADMIN_USER" -g "$ADMIN_USER" -m 600 /dev/null /opt/kosenmap/.env
  did '空の .env を作りました(deploy-to-host.ps1 は .env が無いと送らない)'
fi
ok "/opt/kosenmap($(stat -c %U /opt/kosenmap))"

head_ '7. SSH は鍵だけ'
_home="$(getent passwd "$ADMIN_USER" | cut -d: -f6)"
_keys="$(grep -c '^ssh-' "$_home/.ssh/authorized_keys" 2>/dev/null || true)"
if [ "${_keys:-0}" -lt 1 ]; then
  bad "$ADMIN_USER の authorized_keys に鍵がありません。**締めません**(閉め出されるため)。鍵を置いてから流し直す"
else
  _conf=/etc/ssh/sshd_config.d/60-kosenmap-test.conf
  printf '# testhost-bootstrap.sh(検証機)。本番と同じく鍵だけ\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nPermitRootLogin no\n' > "$_conf.new"
  if [ -f "$_conf" ] && cmp -s "$_conf" "$_conf.new"; then
    rm -f "$_conf.new"
    ok '鍵だけ(変わりなし)'
  else
    mv "$_conf.new" "$_conf"
    if sshd -t; then
      systemctl reload ssh
      did "鍵だけにしました(鍵 $_keys 本)"
    else
      mv "$_conf" "$_conf.bad-$(date +%Y%m%d-%H%M%S)"
      bad 'sshd -t が通らないので戻しました'
    fi
  fi
fi

head_ '8. 外へメールを出さない(コンテナの通り道)'
cat > /etc/systemd/system/km-block-smtp.service <<'UNIT'
[Unit]
Description=KosenMap test host: block outbound SMTP from containers
After=docker.service
Requires=docker.service
PartOf=docker.service
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'for t in iptables ip6tables; do $$t -C DOCKER-USER -p tcp -m multiport --dports 25,465,587 -j REJECT --reject-with tcp-reset 2>/dev/null || $$t -I DOCKER-USER -p tcp -m multiport --dports 25,465,587 -j REJECT --reject-with tcp-reset; done'
[Install]
WantedBy=multi-user.target docker.service
UNIT
systemctl daemon-reload
systemctl enable --now km-block-smtp.service >/dev/null 2>&1
systemctl restart km-block-smtp.service
ok 'DOCKER-USER で 25・465・587 を断る'

head_ "9. ufw($LAN からだけ・外へのメールを断る)"
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
for _p in 22 80 443 8025; do
  ufw allow from "$LAN" to any port "$_p" proto tcp >/dev/null
done
for _p in 25 465 587; do
  ufw deny out "$_p/tcp" >/dev/null
done
ufw --force enable >/dev/null
ok "有効(22・80・443・8025 を $LAN から)"
echo '       ※ Docker が publish したポートは ufw を通らない(本番と同じ)。検証機はルーターで外へ転送しないこと'

show
cat <<EOF

== 次(docs/13 §0)
  PC から最初の配備(km で): deploy-to-host.ps1 -HostName <この VM の IP> -User km -KeyPath ~\\.ssh\\km_test30 -RemotePath /opt/kosenmap -AcceptHostKey -Yes
  そのあと、この VM で: cd /opt/kosenmap && sh scripts/host-local.sh init --domain <IP の点を - にしたもの>.sslip.io --ip <IP> --fresh-secrets
EOF
