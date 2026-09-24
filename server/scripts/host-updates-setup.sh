#!/bin/sh
#
# 更新の自動化をホストへ仕込む。**調べるだけが既定。直すには --fix。**
#
#   sudo ./host-updates-setup.sh              いまどうなっているかを見る
#   sudo ./host-updates-setup.sh --fix        仕込む
#
# 入れるものは4つ:
#
#   1. Ubuntu のセキュリティ更新を自動で当てる(unattended-upgrades)
#      **再起動は自動にしない。** 会期中に勝手に落ちる方が、更新の遅れより痛い。
#   2. コンテナの更新を毎日調べ、変化があればメールで知らせる
#      **当てはしない**(理由は src/lib/update-notice.php)
#   3. ホストのセキュリティ状態を毎日調べ、問題があればメールで知らせる
#      1 で**自動再起動を切っている**以上、「当たったが効いていない」状態は
#      誰かが気づかない限り続く。それを知らせる(src/lib/security-notice.php)
#   4. Let's Encrypt の証明書の更新を毎日試し、失敗したときだけメールで知らせる(版 6)
#      certbot コンテナのループは失敗しても黙って眠り続け、更新しても nginx は古い証明書を
#      出し続ける(scripts/host-cert.sh の説明)。HSTS を有効にしてあるので、
#      **切れた時点で誰もサイトに入れなくなる。**
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
FIX=0

if [ -n "$DEFAULT_ARGS" ]; then
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --fix) FIX=1; shift ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

APT_CONF="/etc/apt/apt.conf.d/52kosenmap-unattended"
CRON_FILE="/etc/cron.d/kosenmap-updates"
# **並びを変えたら上げること。** 古いホストの cron を書き直す合図になる
CRON_VERSION=6
LOG_DIR="/var/log/kosenmap"
CHECK_SCRIPT="$PATH_ROOT/scripts/check-updates.sh"
SECURITY_SCRIPT="$PATH_ROOT/scripts/host-security-check.sh"
EMERGENCY_SCRIPT="$PATH_ROOT/scripts/host-emergency.sh"
BACKUP_SCRIPT="$PATH_ROOT/scripts/host-backup.sh"
# 証明書の更新(版 6)。cron は send-log.sh 越しに呼ぶので、**2本とも実行ビットが要る**
CERT_SCRIPT="$PATH_ROOT/scripts/host-cert.sh"
SEND_LOG_SCRIPT="$PATH_ROOT/scripts/send-log.sh"

PROBLEMS=0
note() { echo "  $1"; }
bad() { echo "  ★ $1"; PROBLEMS=$((PROBLEMS + 1)); }

if [ "$FIX" -eq 1 ] && [ "$(id -u)" -ne 0 ]; then
  echo "--fix には root が要ります (sudo を付けてください)" >&2
  exit 2
fi

echo "== Ubuntu のセキュリティ更新 =="
if [ -x /usr/bin/unattended-upgrade ]; then
  note "unattended-upgrades は入っています"
else
  bad "unattended-upgrades が入っていません (apt-get install unattended-upgrades)"
fi

if [ -f "$APT_CONF" ]; then
  note "設定あり: $APT_CONF"
  if grep -q 'Automatic-Reboot "false"' "$APT_CONF"; then
    note "自動再起動は無効(正しい)"
  else
    bad "自動再起動が無効になっていません。会期中に落ちます"
  fi
else
  bad "設定がありません: $APT_CONF"
  if [ "$FIX" -eq 1 ]; then
    cat > "$APT_CONF" <<'CONF'
// KosenMap: セキュリティ更新だけを自動で当てる。
//
// **再起動は自動にしない。** 会期中に勝手に落ちる方が、更新の遅れより痛い。
// カーネルを当てたあとの再起動は、会期を避けて人が実行する。
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
    "${distro_id}ESMApps:${distro_codename}-apps-security";
    "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";

// Docker のパッケージは自動で当てない。**デーモンの再起動でコンテナが止まる。**
Unattended-Upgrade::Package-Blacklist {
    "docker-ce";
    "docker-ce-cli";
    "containerd.io";
};

APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
CONF
    note "書きました: $APT_CONF"
    PROBLEMS=$((PROBLEMS - 1))
  fi
fi

echo ""
echo "== 確認スクリプト =="
# **配備は実行ビットを保たない。** tar 越しに置いた直後は 644 のことがあるので、
# cron が呼ぶものをまとめて見る(1本ずつ手で chmod すると、必ずどれかを忘れる)。
# 版 6 で host-cert.sh と、それを包む send-log.sh を足した。
for _script in "$CHECK_SCRIPT" "$SECURITY_SCRIPT" "$EMERGENCY_SCRIPT" "$BACKUP_SCRIPT" "$CERT_SCRIPT" "$SEND_LOG_SCRIPT"; do
  if [ -x "$_script" ]; then
    note "あり: $_script"
  elif [ -f "$_script" ]; then
    bad "実行できません (chmod +x $_script)"
    if [ "$FIX" -eq 1 ]; then
      chmod +x "$_script"
      note "実行できるようにしました: $_script"
      PROBLEMS=$((PROBLEMS - 1))
    fi
  else
    bad "ありません: $_script"
  fi
done

#
# **中身まで見る。版で見る。** ファイルが在るだけでは足りない ——
# 先に仕込んだホストには古い並びが残る。「在るから通す」にすると、
# **古い形のまま何年も残る**(実際、時刻を変えても書き換わらなかった)。
#
# 中身の一部を grep する形もやめる。**足すたびに見張る文字列を選び直す**ことになり、
# 選び忘れた変更が黙って素通りする。版の番号1つで見る。
if [ -f "$CRON_FILE" ] && grep -q "^# kosenmap-cron-version: ${CRON_VERSION}\$" "$CRON_FILE"; then
  note "定期実行あり(版 $CRON_VERSION): $CRON_FILE"
else
  if [ -f "$CRON_FILE" ]; then
    bad "定期実行が古い形です(版 $CRON_VERSION ではありません): $CRON_FILE"
  else
    bad "定期実行が仕込まれていません: $CRON_FILE"
  fi
  if [ "$FIX" -eq 1 ]; then
    cat > "$CRON_FILE" <<CONF
# kosenmap-cron-version: ${CRON_VERSION}
# ↑ 並びを変えたら番号を上げること。host-updates-setup.sh がこれを見て書き直す。
#
# KosenMap: 毎日の確認。**当てはしない。知らせるだけ。**
#
#   check-updates       … コンテナの更新(新しい版が出たか / タグの中身が変わったか)
#   host-security-check … ホストの更新が**実際に効いているか**
#                         (自動再起動を切ってあるので、当たっても効いていない状態が続く)
#
# ## なぜ1日1回なのか
#
# **どちらも、変わるのは1日に1回まで。**
#   - セキュリティ更新を当てるのは unattended-upgrades で、これが1日1回
#   - 再起動が要るかどうかは、それが当たった直後にしか変わらない
#   - 公開されている版が増えるのは月に数回
# 3時間おきに調べても、**同じ答えを8回見るだけ**。
# 早く気づいて嬉しいのは空き容量くらいだが、それも数時間で埋まる置き方はしていない。
#
# ## 時刻の決め方
#
# セキュリティ確認は **apt-daily-upgrade.timer より後**に置く(既定は 6:00〜7:00)。
# 前に置くと、**その日当たったぶんを翌日まで知らせない。**
# 手元の並びは systemctl list-timers apt-daily-upgrade.timer で確かめられる。
#
# 2本の時刻をずらしてあるのは、同時に docker を叩かないようにするため。
#
# ## 同じ知らせは繰り返さない
#
# 見つかったものは直すまで毎回見つかる。**そのたびに送ると読まれなくなる**ので、
# 中身が変わったときと、7日たったときの念押しだけ送る(スクリプト側で持っている)。
#
# **--heartbeat の行は、問題が無くても必ず送る** —— 沈黙を「正常」と読ませないため。
#   バックアップ … 毎週日曜(取れているかを毎週確かめたいので)
#   更新・安全   … 毎月 1 日(こちらは変化が遅い)
#
# ## 実行記録
#
# **/dev/null に捨てない。** 捨てると「動いたが何も出なかった」と
# 「そもそも動いていない」が同じ顔になる。$LOG_DIR に書き足す。
# 記録はバックアップの便りに添えられる(host-backup.sh --attach-logs)。
#
# ## バックアップ
#
# 毎日 2:40。**他の確認より前**に置く —— 万一ディスクが詰まったときに、
# 先にバックアップが取れている方がよい。
# 世代は 14 本。**うまく取れなかった回は古いものを消さない**(スクリプト側の判断)。
#
# ### 便りは週に一度(日曜 2:40)
#
# 月〜土の行は**失敗したときだけ**送る。日曜の行は --heartbeat なので、
# **問題が無くても必ず送る。**
#
# ### 日曜に2回取らない
#
# 以前は「毎日 2:40」と「日曜 2:50(--heartbeat)」の2行だった。**毎日の行は日曜も走る**ので、
# 日曜だけ同じ控えを10分おきに2本作っていた(2026-09-13 実測: 02:40 と 02:50)。
# 世代は本数で数えるので、余分な1本が「14日分」の履歴を毎週1日ずつ削る。
# **曜日で分けて、どの日も1回だけにする**(月〜土 = 1-6、日曜 = 0)。
#
# 沈黙を「正常」と読ませないため。仕掛けが止まっていても、壊れた側からは何も来ない ——
# **「来ない」と「送るものが無い」は、受け取る側からは同じ顔をしている。**
# 週に一度「取れています」が届いていれば、**届かなくなった週に気づける。**
#
# 月に一度にしていたが、それだと**気づくまで最悪ひと月かかる**ので週に変えた
# (2026-09-07)。実行記録も一緒に送る(合言葉つきで暗号化される)。
#
# 曜日だけを絞る。cron は**日付と曜日の両方**を絞ると **or** で読むので、
# 「毎月1〜7日の日曜」を「1-7 * 0」とは書けない —— 1〜7日**または**日曜、になる。
# (**このヒアドキュメントは変数を展開するので、バッククォートを書かない。** 書くとコマンドとして
#  走り、cron に書かれる文から消える。以前ここに書いてあった分は「1-7」が実行されていた。2026-09-14)
#
# ## 証明書(版 6 で足した。2026-09-14)
#
# 毎日 3:47 に host-cert.sh renew。**失敗したときだけ**送る(send-log.sh --only-failure)。
# 残り 30 日を切るまでは「更新の時期ではない」で終わるので、普段は何も届かない。
# 更新されたら nginx を reload し、**出している証明書の指紋まで**突き合わせる
# (certbot コンテナのループは失敗しても黙って眠り続け、reload もしない)。
#
# 毎月 1 日 4:07 は status を**必ず**送る —— 残り日数と、nginx が出している証明書が一致しているかを
# 沈黙ではなく便りで確かめる。バックアップ(2:40)とも更新の確認(4:17)とも時刻を重ねない。
# certbot コンテナのループと重なったときは、host-cert.sh が待ってやり直す。
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

47 3 * * *  root  $SEND_LOG_SCRIPT --path $PATH_ROOT --label 証明書の更新 --only-failure --run "$CERT_SCRIPT --path $PATH_ROOT renew" >>$LOG_DIR/cert.log 2>&1
7 4 1 * *   root  $SEND_LOG_SCRIPT --path $PATH_ROOT --label 証明書の状態 --run "$CERT_SCRIPT --path $PATH_ROOT status" >>$LOG_DIR/cert.log 2>&1

40 2 * * 1-6  root  $BACKUP_SCRIPT --path $PATH_ROOT --notify >>$LOG_DIR/backup.log 2>&1
40 2 * * 0    root  $BACKUP_SCRIPT --path $PATH_ROOT --notify --heartbeat --attach-logs >>$LOG_DIR/backup.log 2>&1
17 4 * * *  root  $CHECK_SCRIPT --path $PATH_ROOT --notify >>$LOG_DIR/updates.log 2>&1
23 4 1 * *  root  $CHECK_SCRIPT --path $PATH_ROOT --notify --heartbeat >>$LOG_DIR/updates.log 2>&1
23 8 * * *  root  $SECURITY_SCRIPT --path $PATH_ROOT --notify >>$LOG_DIR/security.log 2>&1
29 8 1 * *  root  $SECURITY_SCRIPT --path $PATH_ROOT --notify --heartbeat >>$LOG_DIR/security.log 2>&1
CONF
    chmod 644 "$CRON_FILE"
    chown root:root "$CRON_FILE"
    note "書きました: $CRON_FILE"
    PROBLEMS=$((PROBLEMS - 1))
  fi
fi

#
# **cron が受け取れる状態かを、中身とは別に見る。**
#
# ## なぜ別に見るか
#
# 上の判定は版の番号しか見ていない。番号が合っていれば「定期実行あり」と言って
# 何も直さない —— **中身が正しくても cron が読まない状態**を素通りする。
#
# 実際にそうなった(2026-09-09 に発覚):
#
#     -rw-r--r-- 1 km km  /etc/cron.d/kosenmap-updates
#
# **`/etc/cron.d/` の中で root 所有でないファイルを、cron は実行しない。**
# 指定した利用者で走らせる仕組みなので、他人が書けるファイルを信じたら
# 権限の昇格になる。だから cron 側が拒む。正しい判断だが、**黙って拒む。**
#
# 症状は「メールが来ない」だけ。ファイルは在り、中身は正しく、構文も通り、
# デーモンも動いている。**版の照合も通る。**
# 同じ cron.d の sysstat(root 所有)は同じ時刻に動いていた ——
# 違いは所有者だけだった。
#
# `cat > "$CRON_FILE"` は**既存ファイルの持ち主を変えない**ので、
# 一度 km で作られると、その後 root で何度書き直しても km のまま残る。
# だから書いた直後の chown だけでは足りず、ここで毎回確かめる。
#
# ## 書き込み権も見る
#
# 群れや他人が書ける状態も cron は拒む(同じ理由)。644 に揃える。
if [ -f "$CRON_FILE" ]; then
  _owner="$(stat -c '%U' "$CRON_FILE" 2>/dev/null || echo '?')"
  _mode="$(stat -c '%a' "$CRON_FILE" 2>/dev/null || echo '?')"
  if [ "$_owner" = "root" ] && [ "$_mode" = "644" ]; then
    note "cron が受け取れます(root:root 644)"
  else
    bad "cron は無視します(所有者 $_owner / 権限 $_mode。root:root 644 が要る): $CRON_FILE"
    if [ "$FIX" -eq 1 ]; then
      chown root:root "$CRON_FILE"
      chmod 644 "$CRON_FILE"
      note "直しました: root:root 644"
      PROBLEMS=$((PROBLEMS - 1))
    fi
  fi
fi

echo ""
echo "== 実行記録の置き場 =="
# **先に作っておく。** 無いと cron のリダイレクトが失敗し、
# 記録が残らないまま「動いていない」ように見える
if [ -d "$LOG_DIR" ]; then
  note "あり: $LOG_DIR"
else
  bad "ありません: $LOG_DIR"
  if [ "$FIX" -eq 1 ]; then
    mkdir -p "$LOG_DIR"
    chown root:root "$LOG_DIR"
    chmod 750 "$LOG_DIR"
    note "作りました: $LOG_DIR"
    PROBLEMS=$((PROBLEMS - 1))
  fi
fi

# **在るだけでは足りない。誰に見えるかまで見る。**
#
# 実際に `4755 km:km` になっていた(2026-09-09)。
#   - 先頭の 4(setuid)は**ディレクトリでは無視される**。付けても何も起きない
#   - 末尾の 5 は **このホストの誰からでも読める**という意味
#
# ここに溜まるのは更新の一覧・セキュリティ点検の結果・バックアップの経過で、
# ホストの弱点と構成がそのまま並ぶ。**読めてよい相手は root だけ。**
# 書くのは cron(root)なので、750 root:root で誰も困らない。
if [ -d "$LOG_DIR" ]; then
  _lowner="$(stat -c '%U' "$LOG_DIR" 2>/dev/null || echo '?')"
  _lmode="$(stat -c '%a' "$LOG_DIR" 2>/dev/null || echo '?')"
  if [ "$_lowner" = "root" ] && [ "$_lmode" = "750" ]; then
    note "実行記録は root だけが読めます(root:root 750)"
  else
    bad "実行記録の置き場が root:root 750 ではありません(所有者 $_lowner / 権限 $_lmode): $LOG_DIR"
    if [ "$FIX" -eq 1 ]; then
      chown root:root "$LOG_DIR"
      # **`chmod 750` だけでは setuid が落ちない。**
      # GNU chmod は、数字で指定されたときディレクトリの setuid / setgid を**残す**。
      # 実際 4755 に `chmod 750` を当てたら 4750 になった(2026-09-13)。
      # すると上の判定(750 か)は毎回外れ、「直しました」と言い続けて一度も直らない。
      # 先に記号で落としてから数字を当てる
      chmod u-s,g-s "$LOG_DIR"
      chmod 750 "$LOG_DIR"
      note "直しました: root:root 750"
      PROBLEMS=$((PROBLEMS - 1))
    fi
  fi
fi

# 際限なく太らせない。**古い記録のために新しい記録が書けなくなる**のが一番まずい
if [ -d "$LOG_DIR" ] && [ ! -f /etc/logrotate.d/kosenmap ]; then
  bad "実行記録の切り詰めが仕込まれていません: /etc/logrotate.d/kosenmap"
  if [ "$FIX" -eq 1 ]; then
    cat > /etc/logrotate.d/kosenmap <<CONF
$LOG_DIR/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
CONF
    chmod 644 /etc/logrotate.d/kosenmap
    note "書きました: /etc/logrotate.d/kosenmap"
    PROBLEMS=$((PROBLEMS - 1))
  fi
fi

# **切り詰めの設定も、在るだけでは足りない。持ち主と権限まで見る。**
#
# 実際に `4745 km:km` になっていた(2026-09-14 に発覚。9/7 に km で作られたもの)。
#   - logrotate は root で走り、**root 所有でない設定ファイルは読まずに飛ばす**
#     (他人が書ける設定を信じると、postrotate に書いたコマンドが root で走るため)。
#     つまり実行記録は**一度も切り詰められていない**
#   - 飛ばされなかったとしても、km が書き換えられる = km から root への抜け道になる
#
# cron.d と同じ罠(持ち主が km のまま残る)なので、同じ形で毎回確かめる。
# 数字の chmod では setuid が残ることがあるので、先に記号で落とす(上の実行記録の置き場と同じ)。
if [ -f /etc/logrotate.d/kosenmap ]; then
  _rowner="$(stat -c '%U:%G' /etc/logrotate.d/kosenmap 2>/dev/null || echo '?')"
  _rmode="$(stat -c '%a' /etc/logrotate.d/kosenmap 2>/dev/null || echo '?')"
  if [ "$_rowner" = "root:root" ] && [ "$_rmode" = "644" ]; then
    note "切り詰めの設定は root だけが書けます(root:root 644)"
  else
    bad "切り詰めの設定を logrotate は読みません(所有者 $_rowner / 権限 $_rmode。root:root 644 が要る): /etc/logrotate.d/kosenmap"
    if [ "$FIX" -eq 1 ]; then
      chown root:root /etc/logrotate.d/kosenmap
      chmod u-s,g-s /etc/logrotate.d/kosenmap
      chmod 644 /etc/logrotate.d/kosenmap
      note "直しました: root:root 644"
      PROBLEMS=$((PROBLEMS - 1))
    fi
  fi
fi

echo ""
echo "== バックアップ =="
if [ -d "$PATH_ROOT/backups" ]; then
  note "置き場あり: $PATH_ROOT/backups ($(ls -1 "$PATH_ROOT"/backups/kosenmap-*.tar.gz 2>/dev/null | wc -l) 本)"
else
  note "置き場はまだありません(初回の実行で作られます)"
fi
# **合言葉が無ければ添付しない**作りなので、無いこと自体は失敗ではない
if [ -f "$PATH_ROOT/.env" ] && grep -q '^BACKUP_PASSPHRASE=' "$PATH_ROOT/.env"; then
  note "BACKUP_PASSPHRASE あり(--attach でメールに添付できます)"
else
  note "BACKUP_PASSPHRASE は未設定(メールには添付しません。置き場から取ってください)"
fi

echo ""
echo "== 通知の宛先 =="
# **宛先を .env に置く。** ソースに書くと、担当が替わったときに
# 誰も気づかないまま前任者へ飛び続ける。
if [ -f "$PATH_ROOT/.env" ] && grep -q '^MAIL_ADMIN_TO=' "$PATH_ROOT/.env"; then
  note "MAIL_ADMIN_TO あり: $(grep '^MAIL_ADMIN_TO=' "$PATH_ROOT/.env" | head -n 1)"
else
  bad "MAIL_ADMIN_TO が .env にありません(既定の admin@example.test へ飛びます)"
fi

echo ""
if [ "$PROBLEMS" -le 0 ]; then
  echo "すべて問題ありません"
  exit 0
fi

if [ "$FIX" -eq 0 ]; then
  echo "気になる点が $PROBLEMS 件あります。直すには --fix を付けてください。"
else
  echo "直しきれなかった点が $PROBLEMS 件あります。上の ★ を確認してください。"
fi
exit 1
