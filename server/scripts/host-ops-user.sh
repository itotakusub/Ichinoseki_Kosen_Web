#!/bin/sh
#
# km の降格(docs/12 §7-4 B)。docker を使う作業を、専用の利用者(既定 kmops)へ移す。
#
#   ./host-ops-user.sh status [--ops-user U]
#                          … いまの状態を見る(何も変えない。sudo 無しでも見える範囲だけ出す)
#   sudo ./host-ops-user.sh create --pubkey-line "ssh-ed25519 AAAA… コメント" [--from CIDR[,CIDR…]] [--ops-user U]
#                          … 利用者を作り、docker グループに入れ、鍵を置き、SSH を許す
#   sudo ./host-ops-user.sh handover [--admin-user km] [--ops-user U]
#                          … 置き場の中で km が持つものを U へ移し、控え専用の鍵の行を km から U へ移す
#   sudo ./host-ops-user.sh handback [--admin-user km] [--ops-user U]
#                          … handover の逆(U が持つものを km へ、鍵の行を km へ)
#   sudo ./host-ops-user.sh demote [--admin-user km] [--ops-user U]
#                          … km を docker グループから外す(U が置き場と門番を引き継いでいることを確かめてから)
#   sudo ./host-ops-user.sh undemote [--admin-user km]
#                          … km を docker グループに戻す
#   sudo ./host-ops-user.sh retire [--user ubuntu]
#                          … 使わない利用者を退役させる(docker・sudo などから外す・AllowUsers から外す・ログインを閉じる・鍵を退避)
#   sudo ./host-ops-user.sh unretire --record /var/backups/kosenmap/retire-ubuntu-日時.txt
#                          … retire の逆(控えた記録から戻す)
#
# ## なぜ要るのか(2026-09-18)
#
# km は **docker グループ**に居る。`docker run -v /:/host` でパスワード無しに root になれるので、
# km の鍵(パスフレーズ無し)が盗まれると、ホストがまるごと取られる。km の sudo はパスワードが要るのに、
# docker が裏口になっていて**パスワードの意味が無い。**
#
# そこで役を分ける:
#   km    … 人が入る。sudo(パスワード必須)で host-*.sh を走らせる。**docker には入れない**
#   kmops … 配備・%%host・控え。docker に入る。**sudo には入れない**。鍵はパスフレーズ付き、
#           authorized_keys で転送を切り、`--from` で送信元を絞れる
#
# ## 段階(1 つずつ。段ごとに検証機で配備・%%host・控えが通ることを確かめてから次へ)
#
#   1. create    … kmops を作る。**km はまだ docker に居るので、今までの運用は何も変わらない**(09-18 検証機・本番とも済)
#   2. handover  … /opt/kosenmap の持ち主を km から kmops へ。配備・%%host・控えの鍵を kmops に切り替える(09-18 検証機・本番とも済)
#   3. demote    … km を docker から外す(戻す: undemote)(09-18 検証機・本番とも済)
#   4. retire    … クラウド既定の ubuntu を docker・sudo・AllowUsers から外し、ログインを閉じる(戻す: unretire)   ← この段
#
# 段はこれで全部。
#
# ## 閉め出さないために
#
# - sshd に AllowUsers が**既に在るときだけ**足す(無いところへ 1 行足すと、他の全員が入れなくなる)
# - 書き換える前に控え(.bak-日時)を取り、`sshd -t` が通らなければ控えに戻して止まる
# - km の鍵・sudo・docker には触れない
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

PATH_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CMD=""
OPS_USER="kmops"
PUBKEY_LINE=""
FROM=""
ADMIN_USER="km"
RETIRE_USER="ubuntu"
RECORD=""
SSHD_DROPIN="/etc/ssh/sshd_config.d/99-km.conf"
TS="$(date +%Y%m%d-%H%M%S)"

ok()   { echo "  OK   $*"; }
note() { echo "  メモ $*"; }
warn() { echo "  注意 $*"; }
did()  { echo "  変更 $*"; }
die()  { echo "host-ops-user: $*" >&2; exit 1; }

usage() {
  sed -n '3,21p' "$0" | sed 's/^# \{0,1\}//'
  exit 2
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    status|create|handover|handback|demote|undemote|retire|unretire)
      if [ -n "$CMD" ]; then usage; fi
      CMD="$1"; shift ;;
    --ops-user)    [ "$#" -ge 2 ] || usage; OPS_USER="$2"; shift 2 ;;
    --pubkey-line) [ "$#" -ge 2 ] || usage; PUBKEY_LINE="$2"; shift 2 ;;
    --from)        [ "$#" -ge 2 ] || usage; FROM="$2"; shift 2 ;;
    --admin-user)  [ "$#" -ge 2 ] || usage; ADMIN_USER="$2"; shift 2 ;;
    --user)        [ "$#" -ge 2 ] || usage; RETIRE_USER="$2"; shift 2 ;;
    --record)      [ "$#" -ge 2 ] || usage; RECORD="$2"; shift 2 ;;
    -h|--help)     usage ;;
    *) die "知らない引数です: $1" ;;
  esac
done
if [ -z "$CMD" ]; then usage; fi

# 利用者名は小文字で始まる短い名前だけ(sshd の設定と authorized_keys に書くので、空白や記号を通さない)
case "$OPS_USER" in
  ''|*[!a-z0-9_-]*) die "--ops-user は小文字・数字・_・- だけにしてください: $OPS_USER" ;;
  [!a-z]*)          die "--ops-user は小文字で始めてください: $OPS_USER" ;;
esac
if [ "${#OPS_USER}" -gt 32 ]; then die "--ops-user が長すぎます"; fi
if [ "$OPS_USER" = "root" ] || [ "$OPS_USER" = "km" ] || [ "$OPS_USER" = "ubuntu" ]; then
  die "--ops-user に $OPS_USER は使えません(役を分けるための利用者です)"
fi

in_group() { # 利用者 グループ
  id -nG "$1" 2>/dev/null | tr ' ' '\n' | grep -qx "$2"
}

# 効いている AllowUsers(root なら sshd -T、そうでなければ設定ファイルを読む)
allow_users() {
  if [ "$(id -u)" -eq 0 ] && command -v sshd >/dev/null 2>&1; then
    sshd -T 2>/dev/null | awk '$1 == "allowusers" { print $2 }' | sort -u
  else
    cat /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null \
      | awk 'tolower($1) == "allowusers" { for (i = 2; i <= NF; i++) print $i }' | sort -u
  fi
}

# docker のほかにも、入っているだけで root に届くグループがある。2026-09-18、検証機の km が lxd に居た
# (Ubuntu の初期設定で最初の利用者が入る。lxd があれば特権コンテナでホストの / を載せられる)。**知らせるだけで外さない**
root_like_groups() {
  for _g in lxd lxc disk libvirt; do
    if id "$1" >/dev/null 2>&1 && in_group "$1" "$_g"; then
      warn "$1 は $_g に居ます(docker と同じく root に届く。要らなければ sudo gpasswd -d $1 $_g)"
    fi
  done
}

# ---------------------------------------------------------------- status

if [ "$CMD" = "status" ]; then
  echo "== 利用者 =="
  if id "$OPS_USER" >/dev/null 2>&1; then
    ok "$OPS_USER が在ります($(id "$OPS_USER"))"
    if in_group "$OPS_USER" docker; then ok "$OPS_USER は docker に入っています"; else warn "$OPS_USER が docker に入っていません"; fi
    if in_group "$OPS_USER" sudo; then warn "$OPS_USER が sudo に入っています(入れない設計です)"; else ok "$OPS_USER は sudo に入っていません"; fi
    _home="$(getent passwd "$OPS_USER" | cut -d: -f6)"
    if [ -r "$_home/.ssh/authorized_keys" ]; then
      ok "鍵: $(grep -c . "$_home/.ssh/authorized_keys") 行"
      awk '{ if ($1 ~ /^(ssh-|ecdsa-|sk-)/) print "       (縛り無し) " $NF; else print "       " $1 " " $NF }' "$_home/.ssh/authorized_keys"
    else
      note "鍵の行は読めません(sudo で見てください)"
    fi
  else
    note "$OPS_USER はまだ居ません(段 1 の create が未了)"
  fi

  echo "== docker と sudo に居る人 =="
  echo "       docker: $(getent group docker | cut -d: -f4)"
  echo "       sudo  : $(getent group sudo | cut -d: -f4)"
  for _u in km ubuntu; do
    if id "$_u" >/dev/null 2>&1 && in_group "$_u" docker; then
      note "$_u は docker に居ます(パスワード無しで root になれる。段 3・4 で外す)"
    fi
    root_like_groups "$_u"
  done

  echo "== SSH で入れる人(AllowUsers) =="
  _allow="$(allow_users | tr '\n' ' ')"
  if [ -n "$_allow" ]; then
    echo "       $_allow"
    case " $_allow " in
      *" $OPS_USER "*) ok "$OPS_USER は入れます" ;;
      *) note "$OPS_USER はまだ入れません" ;;
    esac
  else
    note "AllowUsers がありません(全員が鍵で入れる設定)"
  fi

  echo "== 置き場の持ち主 =="
  echo "       $(stat -c '%U:%G %a' "$PATH_ROOT") $PATH_ROOT"
  exit 0
fi

# ---------------------------------------------------------------- handover / handback

if [ "$CMD" = "handover" ] || [ "$CMD" = "handback" ]; then
  case "$ADMIN_USER" in
    ''|*[!a-z0-9_-]*|[!a-z]*) die "--admin-user の形ではありません: $ADMIN_USER" ;;
  esac
  if [ "$ADMIN_USER" = "$OPS_USER" ] || [ "$ADMIN_USER" = "root" ]; then die "--admin-user に $ADMIN_USER は使えません"; fi
  if [ "$(id -u)" -ne 0 ]; then die "$CMD は sudo で実行してください: sudo $0 $CMD"; fi
  id "$ADMIN_USER" >/dev/null 2>&1 || die "$ADMIN_USER が居ません"
  id "$OPS_USER" >/dev/null 2>&1 || die "$OPS_USER が居ません(先に段 1 の create)"
  in_group "$OPS_USER" docker || die "$OPS_USER が docker に居ません(先に段 1 の create)"

  if [ "$CMD" = "handover" ]; then FROM_U="$ADMIN_USER"; TO_U="$OPS_USER"; else FROM_U="$OPS_USER"; TO_U="$ADMIN_USER"; fi
  FROM_G="$(id -gn "$FROM_U")"; TO_G="$(id -gn "$TO_U")"

  echo "== 1. 置き場の持ち主: $FROM_U → $TO_U($PATH_ROOT)=="
  # 戻すときのために、変える前の持ち主を控える(root だけが読める所)
  install -d -m 700 /var/backups/kosenmap
  LIST="/var/backups/kosenmap/owners-$CMD-$TS.tsv"
  find "$PATH_ROOT" -xdev \( -user "$FROM_U" -o -group "$FROM_G" \) -printf '%u:%g\t%m\t%p\n' > "$LIST"
  chmod 600 "$LIST"
  note "控え: $LIST($(grep -c . "$LIST" || true) 件)"
  # 持ち主だけを変える。**モードは変えない**(.env の 600、backups/ の 2770、config の 640 をそのまま残す)
  find "$PATH_ROOT" -xdev -user "$FROM_U" -exec chown -h "$TO_U" {} +
  find "$PATH_ROOT" -xdev -group "$FROM_G" -exec chgrp -h "$TO_G" {} +
  _left="$(find "$PATH_ROOT" -xdev \( -user "$FROM_U" -o -group "$FROM_G" \) | wc -l)"
  if [ "$_left" -ne 0 ]; then warn "$FROM_U / $FROM_G のまま残ったもの: $_left 件"; else ok "$FROM_U / $FROM_G が持つものは残っていません"; fi
  did "持ち主: $(stat -c '%U:%G' "$PATH_ROOT") $PATH_ROOT / $(stat -c '%U:%G %a' "$PATH_ROOT/.env" 2>/dev/null || echo '?') .env / $(stat -c '%U:%G %a' "$PATH_ROOT/backups" 2>/dev/null || echo '?') backups"

  echo "== 2. 控え専用の鍵の行: $FROM_U → $TO_U =="
  # 門番(ssh-backup-gate.sh)は、入った利用者の権限で host-backup.sh を走らせる。docker と backups/ への書き込みが要るので、
  # docker に残る側へ行を移す。**行はそのまま写す**(縛りの restrict,command= を変えない)
  GATE_PREFIX="restrict,command=\"$PATH_ROOT/scripts/ssh-backup-gate.sh\" "
  SRC_AK="$(getent passwd "$FROM_U" | cut -d: -f6)/.ssh/authorized_keys"
  DST_HOME="$(getent passwd "$TO_U" | cut -d: -f6)"
  DST_AK="$DST_HOME/.ssh/authorized_keys"
  if [ ! -f "$SRC_AK" ] || ! grep -qF "$GATE_PREFIX" "$SRC_AK"; then
    if [ -f "$DST_AK" ] && grep -qF "$GATE_PREFIX" "$DST_AK"; then
      ok "既に $TO_U にあります"
    else
      note "$FROM_U にも $TO_U にも門番の行がありません(控え専用の鍵を使っていない)"
    fi
  else
    install -d -m 700 -o "$TO_U" -g "$TO_G" "$DST_HOME/.ssh"
    cp -p "$SRC_AK" "$SRC_AK.bak-$TS"
    if [ -f "$DST_AK" ]; then cp -p "$DST_AK" "$DST_AK.bak-$TS"; fi
    _tmp="$(mktemp "$DST_HOME/.ssh/.ak.XXXXXX")"
    if [ -f "$DST_AK" ]; then cat "$DST_AK" > "$_tmp"; fi
    grep -F "$GATE_PREFIX" "$SRC_AK" | while IFS= read -r _line; do
      if ! grep -qxF "$_line" "$_tmp"; then printf '%s\n' "$_line" >> "$_tmp"; fi
    done
    chown "$TO_U:$TO_G" "$_tmp"; chmod 600 "$_tmp"; mv "$_tmp" "$DST_AK"
    # 写せたのを見てから元を外す。**パイプの中の die は外のスクリプトを止めない**ので、数えてから判断する
    _missing="$(grep -F "$GATE_PREFIX" "$SRC_AK" | while IFS= read -r _line; do grep -qxF "$_line" "$DST_AK" || echo x; done | wc -l)"
    if [ "$_missing" -ne 0 ]; then die "写せていない行が $_missing 本あるので、$FROM_U の行は外しません"; fi
    _src_owner="$(stat -c '%U:%G' "$SRC_AK")"
    _tmp="$(mktemp "$(dirname "$SRC_AK")/.ak.XXXXXX")"
    grep -vF "$GATE_PREFIX" "$SRC_AK" > "$_tmp" || true
    chown "$_src_owner" "$_tmp"; chmod 600 "$_tmp"; mv "$_tmp" "$SRC_AK"
    did "門番の行を $TO_U へ移しました(控え: $SRC_AK.bak-$TS)"
  fi

  echo ""
  if [ "$CMD" = "handover" ]; then
    echo "段 2 のホスト側は終わりです。km はまだ docker に居ます(段 3 で外す)。"
    echo "PC 側は、配備・%%host・控えを $OPS_USER と km_ops の鍵で繋ぐ(docs/12 §7-4 段 2)。"
    echo "戻す: sudo $0 handback"
  else
    echo "戻しました。PC 側の接続先も km に戻してください。"
  fi
  exit 0
fi

# ---------------------------------------------------------------- demote / undemote

if [ "$CMD" = "demote" ] || [ "$CMD" = "undemote" ]; then
  case "$ADMIN_USER" in
    ''|*[!a-z0-9_-]*|[!a-z]*) die "--admin-user の形ではありません: $ADMIN_USER" ;;
  esac
  if [ "$ADMIN_USER" = "$OPS_USER" ] || [ "$ADMIN_USER" = "root" ]; then die "--admin-user に $ADMIN_USER は使えません"; fi
  if [ "$(id -u)" -ne 0 ]; then die "$CMD は sudo で実行してください: sudo $0 $CMD"; fi
  id "$ADMIN_USER" >/dev/null 2>&1 || die "$ADMIN_USER が居ません"

  if [ "$CMD" = "undemote" ]; then
    if in_group "$ADMIN_USER" docker; then ok "$ADMIN_USER は既に docker に居ます"; else usermod -aG docker "$ADMIN_USER"; did "$ADMIN_USER を docker に戻しました(入り直すと効きます)"; fi
    exit 0
  fi

  echo "== 1. 引き継ぎが済んでいるか =="
  # **外したあとで「配備も控えも動かない」にならないよう、先に全部見る。** 1 つでも欠けたら何も変えない
  _ng=0
  if id "$OPS_USER" >/dev/null 2>&1 && in_group "$OPS_USER" docker; then ok "$OPS_USER が docker に居ます"; else warn "$OPS_USER が居ないか docker に居ません(段 1)"; _ng=1; fi
  if [ "$(stat -c '%U' "$PATH_ROOT")" = "$OPS_USER" ]; then ok "置き場の持ち主は $OPS_USER"; else warn "置き場の持ち主が $(stat -c '%U' "$PATH_ROOT") です(段 2 の handover)"; _ng=1; fi
  _left="$(find "$PATH_ROOT" -xdev -user "$ADMIN_USER" 2>/dev/null | wc -l)"
  if [ "$_left" -eq 0 ]; then ok "置き場に $ADMIN_USER の持ち物はありません"; else warn "置き場に $ADMIN_USER の持ち物が $_left 件あります(段 2 の handover)"; _ng=1; fi
  GATE_PREFIX="restrict,command=\"$PATH_ROOT/scripts/ssh-backup-gate.sh\" "
  _admin_ak="$(getent passwd "$ADMIN_USER" | cut -d: -f6)/.ssh/authorized_keys"
  if [ -f "$_admin_ak" ] && grep -qF "$GATE_PREFIX" "$_admin_ak"; then
    warn "門番の鍵の行がまだ $ADMIN_USER にあります(docker を外すと控えが取れなくなる。段 2 の handover)"; _ng=1
  else
    ok "門番の鍵の行は $ADMIN_USER にありません"
  fi
  if in_group "$ADMIN_USER" sudo; then ok "$ADMIN_USER は sudo に居ます(外したあとも host-*.sh は sudo で走らせられる)"; else warn "$ADMIN_USER が sudo に居ません。docker を外すとホストを直す手段が無くなります"; _ng=1; fi
  if [ "$_ng" -ne 0 ]; then die "引き継ぎが済んでいないので、$ADMIN_USER は docker に残したままにします"; fi

  root_like_groups "$ADMIN_USER"

  echo "== 2. $ADMIN_USER を docker から外す =="
  if in_group "$ADMIN_USER" docker; then
    gpasswd -d "$ADMIN_USER" docker >/dev/null
    did "$ADMIN_USER を docker から外しました"
  else
    ok "既に docker に居ません"
  fi
  echo ""
  echo "段 3 は終わりです。**いま開いている $ADMIN_USER のセッションは docker を使えるまま**(グループはログインのときに決まる)。一度切って入り直すと効きます。"
  echo "戻す: sudo $0 undemote"
  exit 0
fi

# ---------------------------------------------------------------- retire / unretire

if [ "$CMD" = "retire" ] || [ "$CMD" = "unretire" ]; then
  if [ "$(id -u)" -ne 0 ]; then die "$CMD は sudo で実行してください: sudo $0 $CMD"; fi
  case "$ADMIN_USER" in
    ''|*[!a-z0-9_-]*|[!a-z]*) die "--admin-user の形ではありません: $ADMIN_USER" ;;
  esac
  RETIRE_GROUPS="docker sudo adm lxd lxc disk libvirt"

  if [ "$CMD" = "unretire" ]; then
    case "$RECORD" in
      /var/backups/kosenmap/retire-*.txt) ;;
      *) die "--record に /var/backups/kosenmap/retire-<利用者>-<日時>.txt を渡してください" ;;
    esac
    [ -f "$RECORD" ] || die "記録がありません: $RECORD"
    _u="$(sed -n 's/^user=//p' "$RECORD")"
    case "$_u" in ''|*[!a-z0-9_-]*|[!a-z]*) die "記録の利用者名が読めません" ;; esac
    id "$_u" >/dev/null 2>&1 || die "$_u が居ません"
    echo "== $_u を戻す($RECORD)=="
    for _g in $(sed -n 's/^groups=//p' "$RECORD"); do
      case "$_g" in *[!a-z0-9_-]*) continue ;; esac
      if in_group "$_u" "$_g"; then ok "$_g に居ます"; else usermod -aG "$_g" "$_u"; did "$_g に戻しました"; fi
    done
    _shell="$(sed -n 's/^shell=//p' "$RECORD")"
    _expire="$(sed -n 's/^expire=//p' "$RECORD")"
    usermod -U "$_u" 2>/dev/null || true
    usermod -e "${_expire:-""}" "$_u"
    if [ -n "$_shell" ]; then usermod -s "$_shell" "$_u"; fi
    did "ログインを開けました(shell=$_shell)"
    _ak="$(sed -n 's/^ak_moved=//p' "$RECORD")"
    if [ -n "$_ak" ] && [ -f "$_ak" ]; then
      _home="$(getent passwd "$_u" | cut -d: -f6)"
      mv "$_ak" "$_home/.ssh/authorized_keys"
      did "鍵を戻しました"
    fi
    if [ "$(sed -n 's/^allowusers=//p' "$RECORD")" = "1" ]; then
      cp -p "$SSHD_DROPIN" "$SSHD_DROPIN.bak-$TS"
      printf 'AllowUsers %s\n' "$_u" >> "$SSHD_DROPIN"
      if ! sshd -t; then cp -p "$SSHD_DROPIN.bak-$TS" "$SSHD_DROPIN"; die "sshd -t が通らないので元に戻しました"; fi
      systemctl reload ssh 2>/dev/null || systemctl reload sshd
      did "AllowUsers に $_u を戻しました"
    fi
    exit 0
  fi

  case "$RETIRE_USER" in
    ''|*[!a-z0-9_-]*|[!a-z]*) die "--user の形ではありません: $RETIRE_USER" ;;
  esac
  if [ "$RETIRE_USER" = "root" ] || [ "$RETIRE_USER" = "$ADMIN_USER" ] || [ "$RETIRE_USER" = "$OPS_USER" ]; then
    die "--user に $RETIRE_USER は使えません(ホストを直す人・配備する人は退役させない)"
  fi
  id "$RETIRE_USER" >/dev/null 2>&1 || die "$RETIRE_USER が居ません"

  echo "== 1. 閉め出されないか =="
  # **いま sudo している本人を退役させない。** 残る人(km)が sudo と SSH を持っていることを先に見る
  _ng=0
  if [ "${SUDO_USER:-}" = "$RETIRE_USER" ]; then warn "いま $RETIRE_USER で sudo しています。$ADMIN_USER で入り直してから流してください"; _ng=1; fi
  if in_group "$ADMIN_USER" sudo; then ok "$ADMIN_USER は sudo に居ます"; else warn "$ADMIN_USER が sudo に居ません(退役させるとホストを直す人が居なくなる)"; _ng=1; fi
  _allow="$(allow_users | tr '\n' ' ')"
  case " $_allow " in
    "  ") ok "AllowUsers がありません(鍵を退避すれば入れなくなる)" ;;
    *" $ADMIN_USER "*) ok "$ADMIN_USER は SSH で入れます" ;;
    *) warn "$ADMIN_USER が AllowUsers に居ません"; _ng=1 ;;
  esac
  if grep -rqsE "^[[:space:]]*$RETIRE_USER[[:space:]]" /etc/sudoers /etc/sudoers.d; then
    warn "sudoers に $RETIRE_USER の行があります(グループを外しても sudo が残る)。先に中身を確かめてください"; _ng=1
  else
    ok "sudoers に $RETIRE_USER の個別の行はありません"
  fi
  if grep -qsE "^[[:space:]]*AllowUsers[[:space:]].*\\b$RETIRE_USER\\b" /etc/ssh/sshd_config; then
    warn "/etc/ssh/sshd_config 本体の AllowUsers に $RETIRE_USER が居ます(ここでは $SSHD_DROPIN しか直さない)"; _ng=1
  fi
  if [ "$_ng" -ne 0 ]; then die "閉め出しや取り残しのおそれがあるので、何も変えません"; fi

  echo "== 2. 控え(戻すための記録)=="
  install -d -m 700 /var/backups/kosenmap
  REC="/var/backups/kosenmap/retire-$RETIRE_USER-$TS.txt"
  _had=""
  for _g in $RETIRE_GROUPS; do
    if in_group "$RETIRE_USER" "$_g"; then _had="$_had $_g"; fi
  done
  _allow_had=0
  case " $_allow " in *" $RETIRE_USER "*) _allow_had=1 ;; esac
  {
    echo "user=$RETIRE_USER"
    echo "groups=${_had# }"
    echo "shell=$(getent passwd "$RETIRE_USER" | cut -d: -f7)"
    echo "expire=$(getent shadow "$RETIRE_USER" | cut -d: -f8)"
    echo "allowusers=$_allow_had"
  } > "$REC"
  chmod 600 "$REC"
  note "記録: $REC"
  _procs="$(pgrep -u "$RETIRE_USER" 2>/dev/null | wc -l)"
  _cron="$(crontab -l -u "$RETIRE_USER" 2>/dev/null | grep -cvE '^[[:space:]]*(#|$)' || true)"
  note "$RETIRE_USER のプロセス: $_procs 個 / crontab の行: ${_cron:-0}(止めはしない。多ければ中身を確かめる)"

  echo "== 3. グループから外す =="
  if [ -z "$_had" ]; then ok "外すグループはありません"; fi
  for _g in $_had; do
    gpasswd -d "$RETIRE_USER" "$_g" >/dev/null
    did "$_g から外しました"
  done

  echo "== 4. SSH とログインを閉じる =="
  _home="$(getent passwd "$RETIRE_USER" | cut -d: -f6)"
  if [ -f "$_home/.ssh/authorized_keys" ]; then
    _moved="$_home/.ssh/authorized_keys.retired-$TS"
    mv "$_home/.ssh/authorized_keys" "$_moved"
    echo "ak_moved=$_moved" >> "$REC"
    did "鍵を退避しました(消していません): $_moved"
  else
    ok "鍵はありません"
  fi
  usermod -L -e 1 -s /usr/sbin/nologin "$RETIRE_USER"
  did "パスワードを錠・期限切れ・nologin にしました"
  if [ "$_allow_had" = "1" ]; then
    [ -f "$SSHD_DROPIN" ] || die "$SSHD_DROPIN がありません"
    cp -p "$SSHD_DROPIN" "$SSHD_DROPIN.bak-$TS"
    note "控え: $SSHD_DROPIN.bak-$TS"
    # AllowUsers の行からその名前だけ抜く。名前が残らない行は消す(空の AllowUsers は書かない)
    awk -v u="$RETIRE_USER" '
      tolower($1) == "allowusers" {
        line = $1; n = 0
        for (i = 2; i <= NF; i++) if ($i != u) { line = line " " $i; n++ }
        if (n > 0) print line
        next
      }
      { print }
    ' "$SSHD_DROPIN.bak-$TS" > "$SSHD_DROPIN"
    if ! sshd -t; then cp -p "$SSHD_DROPIN.bak-$TS" "$SSHD_DROPIN"; die "sshd -t が通らないので元に戻しました(グループと鍵は外したまま。記録: $REC)"; fi
    _after="$(allow_users | tr '\n' ' ')"
    case " $_after " in
      *" $ADMIN_USER "*) ;;
      *) cp -p "$SSHD_DROPIN.bak-$TS" "$SSHD_DROPIN"; die "書き換えたら $ADMIN_USER が AllowUsers から消えたので元に戻しました" ;;
    esac
    systemctl reload ssh 2>/dev/null || systemctl reload sshd
    did "AllowUsers から $RETIRE_USER を外して sshd を読み直しました(いま: $_after)"
  else
    ok "AllowUsers に $RETIRE_USER は居ません"
  fi

  echo ""
  echo "段 4 は終わりです。$RETIRE_USER は消していません(ホームも残っています)。"
  echo "戻す: sudo $0 unretire --record $REC"
  exit 0
fi

# ---------------------------------------------------------------- create
if [ -z "$PUBKEY_LINE" ]; then
  die "--pubkey-line に公開鍵の 1 行(ssh-ed25519 AAAA… コメント)を渡してください"
fi

# 公開鍵は「種類 本体 [コメント]」の 1 行だけ。**先頭に縛り(command= など)を書かせない** ——
# 付ける縛りはこのスクリプトが決める
set -f
# shellcheck disable=SC2086
set -- $PUBKEY_LINE
set +f
if [ "$#" -lt 2 ]; then die "公開鍵の形ではありません"; fi
KEY_TYPE="$1"; KEY_BLOB="$2"; shift 2
KEY_COMMENT="$*"
case "$KEY_TYPE" in
  ssh-ed25519|sk-ssh-ed25519@openssh.com|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521) ;;
  *) die "鍵の種類 $KEY_TYPE は受け付けません(ed25519 か ecdsa)" ;;
esac
case "$KEY_BLOB" in
  AAAA*) ;;
  *) die "鍵の本体の形ではありません" ;;
esac
case "$KEY_BLOB" in
  *[!A-Za-z0-9+/=]*) die "鍵の本体に使えない文字があります" ;;
esac
case "$KEY_COMMENT" in
  *[!A-Za-z0-9@._-]*) die "鍵のコメントは英数字と @ . _ - だけにしてください" ;;
esac

OPTS="no-agent-forwarding,no-X11-forwarding,no-port-forwarding"
if [ -n "$FROM" ]; then
  case "$FROM" in
    *[!0-9A-Fa-f.:/,]*) die "--from は IP か CIDR をカンマで並べてください: $FROM" ;;
  esac
  OPTS="from=\"$FROM\",$OPTS"
fi

# ここまでの検査は root でなくても走る(sudo のパスワードを打つ前に、形の誤りが分かる)
if [ "$(id -u)" -ne 0 ]; then
  die "create は sudo で実行してください: sudo $0 create --pubkey-line \"…\""
fi

echo "== 1. 利用者 $OPS_USER =="
if id "$OPS_USER" >/dev/null 2>&1; then
  ok "既に在ります"
else
  useradd --create-home --shell /bin/bash --user-group "$OPS_USER"
  did "作りました(パスワードは無し。鍵でだけ入れます)"
fi
passwd -l "$OPS_USER" >/dev/null 2>&1 || true
if in_group "$OPS_USER" sudo; then
  die "$OPS_USER が sudo に入っています。役を分ける意味が無くなるので止めます(gpasswd -d $OPS_USER sudo)"
fi
ok "sudo には入っていません"
if in_group "$OPS_USER" docker; then
  ok "docker に入っています"
else
  usermod -aG docker "$OPS_USER"
  did "docker に入れました"
fi

echo "== 2. 鍵 =="
HOME_DIR="$(getent passwd "$OPS_USER" | cut -d: -f6)"
AK="$HOME_DIR/.ssh/authorized_keys"
install -d -m 700 -o "$OPS_USER" -g "$OPS_USER" "$HOME_DIR/.ssh"
if [ -f "$AK" ]; then
  cp -p "$AK" "$AK.bak-$TS"
  note "控え: $AK.bak-$TS"
fi
LINE="$OPTS $KEY_TYPE $KEY_BLOB${KEY_COMMENT:+ $KEY_COMMENT}"
if [ -f "$AK" ] && grep -qxF "$LINE" "$AK"; then
  ok "同じ行が既に在ります"
else
  # 同じ鍵が別の縛りで在れば、その行を外してから足す(縛りの無い行を残さない)
  _tmp="$(mktemp "$HOME_DIR/.ssh/.ak.XXXXXX")"
  if [ -f "$AK" ]; then
    grep -vF " $KEY_BLOB" "$AK" > "$_tmp" || true
  fi
  printf '%s\n' "$LINE" >> "$_tmp"
  chown "$OPS_USER:$OPS_USER" "$_tmp"
  chmod 600 "$_tmp"
  mv "$_tmp" "$AK"
  did "鍵を置きました: $OPTS … ${KEY_COMMENT:-(コメント無し)}"
fi
chown "$OPS_USER:$OPS_USER" "$AK"
chmod 600 "$AK"

echo "== 3. SSH で入れるようにする =="
_allow="$(allow_users | tr '\n' ' ')"
if [ -z "$_allow" ]; then
  ok "AllowUsers が無いので足しません(足すと他の全員が入れなくなります)"
else
  case " $_allow " in
    *" $OPS_USER "*) ok "既に入れます($_allow)" ;;
    *)
      if [ ! -f "$SSHD_DROPIN" ]; then
        die "AllowUsers は在るのに $SSHD_DROPIN がありません。どこで設定しているか確かめてから足してください"
      fi
      cp -p "$SSHD_DROPIN" "$SSHD_DROPIN.bak-$TS"
      note "控え: $SSHD_DROPIN.bak-$TS"
      printf 'AllowUsers %s\n' "$OPS_USER" >> "$SSHD_DROPIN"
      if ! sshd -t; then
        cp -p "$SSHD_DROPIN.bak-$TS" "$SSHD_DROPIN"
        die "sshd -t が通らないので元に戻しました"
      fi
      systemctl reload ssh 2>/dev/null || systemctl reload sshd
      did "AllowUsers に $OPS_USER を足して sshd を読み直しました(今つながっている SSH は切れません)"
      ;;
  esac
fi

echo ""
echo "段 1 は終わりです。km には触れていません(まだ docker に居ます)。"
echo "PC から確かめる: ssh -i ~/.ssh/km_ops $OPS_USER@<ホスト> 'id; docker ps --format {{.Names}} | head -3'"
