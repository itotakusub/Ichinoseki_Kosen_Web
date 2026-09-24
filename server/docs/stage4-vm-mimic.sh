#!/bin/sh
# docs/12 §7-4 段 4-1 用。**検証機だけで使う。** 本番の ubuntu と同じ形(docker・sudo・adm・AllowUsers)の見本を作り、
# retire → unretire → retire を通す。sudo で 1 回だけ流す(sudo sh /tmp/stage4-vm-mimic.sh)。
set -eu
case "$(hostname)" in
  ubuntu) echo "本番らしいホスト名(ubuntu)なので止めます" >&2; exit 1 ;;
esac
[ -f /opt/kosenmap/.env ] && grep -q '^KM_ENV=local' /opt/kosenmap/.env || { echo "KM_ENV=local ではないので止めます(検証機専用)" >&2; exit 1; }

DROPIN=/etc/ssh/sshd_config.d/99-km.conf
OPS=/opt/kosenmap/scripts/host-ops-user.sh

echo "===== 見本の ubuntu を作る"
id ubuntu >/dev/null 2>&1 || useradd -m -s /bin/bash ubuntu
usermod -aG docker,sudo,adm ubuntu
install -d -m 700 -o ubuntu -g ubuntu /home/ubuntu/.ssh
[ -f /home/ubuntu/.ssh/authorized_keys ] || install -m 600 -o ubuntu -g ubuntu /dev/null /home/ubuntu/.ssh/authorized_keys
if ! grep -qx 'AllowUsers ubuntu' "$DROPIN"; then
  printf 'AllowUsers ubuntu\n' >> "$DROPIN"
  sshd -t
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
fi
id ubuntu

echo "===== retire(1 回目)"
SUDO_USER=km sh "$OPS" retire
REC="$(ls -1t /var/backups/kosenmap/retire-ubuntu-*.txt | head -n 1)"
echo "--- 記録"; cat "$REC"

echo "===== unretire"
sh "$OPS" unretire --record "$REC"
id ubuntu; getent passwd ubuntu | cut -d: -f7; grep -c 'AllowUsers ubuntu' "$DROPIN" || true

echo "===== retire(2 回目。この状態で終える)"
sleep 1
SUDO_USER=km sh "$OPS" retire
id ubuntu; getent passwd ubuntu | cut -d: -f7
echo "===== 終わり"
