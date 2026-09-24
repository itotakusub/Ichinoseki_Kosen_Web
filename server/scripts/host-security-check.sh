#!/bin/sh
#
# ホスト(Ubuntu)のセキュリティ状態を調べ、問題があれば管理者へメールを送る。
#
#   ./host-security-check.sh                 調べて表示するだけ
#   ./host-security-check.sh --json          送らずに JSON だけ出す(仕込みの確認用)
#   ./host-security-check.sh --notify        問題があればメールも送る
#   ./host-security-check.sh --notify --heartbeat
#                                            問題が無くても送る(月に一度これを回す)
#
# ## なぜ要るのか
#
# `unattended-upgrades` はセキュリティ更新を**自動で当てる**が、
# `host-updates-setup.sh` が **Automatic-Reboot "false"** にしている
# (会期中に勝手に落ちる方が、更新の遅れより痛いため)。つまり:
#
#   - カーネルや libc を当てても、**再起動するまで古いものが動き続ける**
#   - 促すのは /var/run/reboot-required という**ファイルが1つ置かれるだけ**
#   - 誰もホストにログインしなければ、**それは何ヶ月でも置かれたまま**になる
#
# 「自動で当たっているから安心」と「実際に効いている」は違う。その差を知らせる。
#
# ## 当てはしない
#
# 当てるのは unattended-upgrades の仕事。ここは**見て言うだけ**にする。
# 再起動を自動でしないと決めたのに、このスクリプトが再起動したら意味が無い。
#
# POSIX sh。**`[ … ] && cmd` を単独で書かない** —— set -e の下では
# 判定が偽になった時点でスクリプトごと終わる(check-updates.sh で踏んだ)。

set -eu

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
NOTIFY=0
HEARTBEAT=0
JSON_ONLY=0

# 空き容量がこの割合を超えたら知らせる。**apt も DB も、足りないと静かに失敗する**
DISK_WARN_PERCENT=90

# ---------------------------------------------------------------------------
# 同じ知らせを繰り返さない
# ---------------------------------------------------------------------------
# **見つかったものは、直すまで毎回見つかる。**
# 再起動が要る状態は、再起動するまで何日でも続く ——
# そのたびに送ると、**毎日同じメールが届いて読まれなくなる。**
# 本当に新しい知らせが来たときには、もう開かれていない。
#
# 送るのは次のときだけ:
#   1. 前回と**中身が変わった**とき(新しい問題が出た / 直った項目がある)
#   2. 変わっていなくても KM_REMIND_DAYS 日たったとき(**忘れないための念押し**)
#   3. --heartbeat のとき(月に一度。**沈黙を「正常」と解釈させない**)
#
# これがあるので、**確認の頻度を上げてもメールは増えない。**
# 頻度は「どれだけ早く気づきたいか」だけで決められる。
STATE_DIR="/var/lib/kosenmap"
STATE_FILE="$STATE_DIR/security-last"
KM_REMIND_DAYS=7
# 自動更新のログがこの日数より古ければ「動いていない」とみなす
# (APT::Periodic は毎日回る。3日空くのは仕込みか時計の問題)
UNATTENDED_STALE_DAYS=3

# host-setup.sh と同じ差し込み口。PowerShell から標準入力で流すときに使う
if [ -n "$DEFAULT_ARGS" ]; then
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --notify) NOTIFY=1; shift ;;
    --heartbeat) HEARTBEAT=1; shift ;;
    --json) JSON_ONLY=1; shift ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

HOST_LABEL="$(hostname 2>/dev/null || echo unknown)"
OS_LABEL="$(. /etc/os-release 2>/dev/null && printf '%s' "${PRETTY_NAME:-}" || true)"

APT_CONF="/etc/apt/apt.conf.d/52kosenmap-unattended"
UNATTENDED_LOG="/var/log/unattended-upgrades/unattended-upgrades.log"

json_escape() {
  # 改行は \n にする。**生の改行を JSON に入れない**(受け取り側が読めなくなる)
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | awk 'BEGIN{ORS=""} NR>1{print "\\n"} {print}'
}

# **--json のときは人向けの行を出さない。** 混ぜると `| jq` が通らず、
# 仕込みの確認に使えない(そのために用意した口なのに)。
say() {
  if [ "$JSON_ONLY" -eq 0 ]; then
    echo "$1"
  fi
}
section() { say ""; say "== $1 =="; }

ITEMS=""
# 「前回と同じ知らせか」を見分けるための鍵。**表示する文とは分けて持つ。**
#
# 以前は表示する文(ITEMS)そのものの指紋で比べていた。ところが再起動の項目は
# 「再起動しないと更新が効きません(0 日前から)」のように**経過日数が文に入る**ので、
# 何も変わっていないのに毎日指紋が変わり、**毎日メールが出ていた**
# (2026-09-12 に「0 日前から」、09-13 に「1 日前から」で2日続けて送信。
#  本来は初回と、7日ごとの念押しだけのはず)。
#
# 4番目の引数で鍵を渡す。省けば「種別|文」—— 件数のように**変われば知らせるべき数**は
# 文に入ったままでよい。**時間が経つだけで変わる数**を含む項目だけ、鍵を別に渡す。
KEYS=""
add_item() {
  _entry="{\"kind\":\"$1\",\"summary\":\"$(json_escape "$2")\",\"detail\":\"$(json_escape "$3")\"}"
  if [ -z "$ITEMS" ]; then
    ITEMS="$_entry"
  else
    ITEMS="$ITEMS,$_entry"
  fi
  KEYS="${KEYS}${4:-$1|$2}
"
  say "  ★ [$1] $2"
}

DEFERRED=""
add_deferred() {
  _entry="\"$(json_escape "$1")\""
  if [ -z "$DEFERRED" ]; then
    DEFERRED="$_entry"
  else
    DEFERRED="$DEFERRED,$_entry"
  fi
}

note() { say "  $1"; }

# ---------------------------------------------------------------------------
# 意図的に自動更新から外しているパッケージ
# ---------------------------------------------------------------------------
# **これを「未適用」と数えない。** Docker のパッケージは
# host-updates-setup.sh が Package-Blacklist に入れている(デーモンの再起動で
# コンテナが止まるため)。毎日「未適用が3件あります」と鳴らすと、
# **オオカミ少年になって本物を見落とす。**
#
# 一覧は設定ファイルから読む。**2箇所に同じ表を持たない** ——
# 片方だけ直されると、外したはずのものが未適用として鳴き始める。
BLACKLIST=""
if [ -f "$APT_CONF" ]; then
  BLACKLIST="$(sed -n '/Package-Blacklist/,/}/p' "$APT_CONF" \
    | sed -n 's/^[[:space:]]*"\([^"]*\)".*/\1/p' | tr '\n' ' ')"
fi

is_blacklisted() {
  for _b in $BLACKLIST; do
    if [ "$1" = "$_b" ]; then
      return 0
    fi
  done
  return 1
}

say "== 自動更新の仕組み =="
if [ -x /usr/bin/unattended-upgrade ]; then
  note "unattended-upgrades は入っています"
else
  add_item unattended "unattended-upgrades が入っていません" \
    "apt-get install unattended-upgrades を実行してから host-updates-setup.sh --fix"
fi

if [ -f "$APT_CONF" ]; then
  note "設定あり: $APT_CONF"
else
  add_item unattended "自動更新の設定がありません" "見つからないファイル: $APT_CONF"
fi

# タイマーが止まっていれば、設定が正しくても**一度も走らない**
if command -v systemctl >/dev/null 2>&1; then
  if systemctl is-enabled apt-daily-upgrade.timer >/dev/null 2>&1; then
    note "apt-daily-upgrade.timer は有効です"
  else
    add_item unattended "apt-daily-upgrade.timer が有効ではありません" \
      "sudo systemctl enable --now apt-daily-upgrade.timer apt-daily.timer"
  fi
fi

# 最後に走った時刻。**「設定してある」と「動いている」は違う**
if [ -f "$UNATTENDED_LOG" ]; then
  _log_epoch="$(stat -c %Y "$UNATTENDED_LOG" 2>/dev/null || echo 0)"
  _now="$(date +%s)"
  _age_days=$(( (_now - _log_epoch) / 86400 ))
  note "自動更新のログの更新: ${_age_days} 日前"
  if [ "$_age_days" -gt "$UNATTENDED_STALE_DAYS" ]; then
    # 日数は毎日増えるので鍵に入れない(add_item の注記)。止まっている事実だけで見る
    add_item unattended "自動更新が ${_age_days} 日動いていません" \
      "$UNATTENDED_LOG が更新されていません。タイマーと時計を確かめてください" \
      "unattended|stale"
  fi
  # 直近の失敗。**成功が続いていても、最後が失敗なら当たっていない**
  _last_error="$(grep -i 'ERROR' "$UNATTENDED_LOG" 2>/dev/null | tail -n 1 || true)"
  if [ -n "$_last_error" ]; then
    _error_recent="$(tail -n 200 "$UNATTENDED_LOG" 2>/dev/null | grep -ci 'ERROR' || true)"
    if [ "${_error_recent:-0}" -gt 0 ]; then
      add_item unattended "自動更新がエラーを記録しています" "$_last_error"
    fi
  fi
else
  note "自動更新のログがまだありません($UNATTENDED_LOG)"
fi

# ---------------------------------------------------------------------------
# 当たっていないセキュリティ更新
# ---------------------------------------------------------------------------
# **apt に聞く。** update-notifier の apt-check は入っていないことがある。
# `-s` は変更しない試算。`Debug::NoLocking` を付けるのは、
# apt-daily が動いている最中でもロック待ちで止まらないようにするため。
section "当たっていないセキュリティ更新"
PENDING_LIST=""
PENDING_COUNT=0
DEFERRED_COUNT=0
if command -v apt-get >/dev/null 2>&1; then
  SIM="$(apt-get -s -o Debug::NoLocking=true dist-upgrade 2>/dev/null || true)"
  for _pkg in $(printf '%s\n' "$SIM" | grep '^Inst ' | grep -i 'security' | awk '{print $2}'); do
    if is_blacklisted "$_pkg"; then
      DEFERRED_COUNT=$((DEFERRED_COUNT + 1))
      add_deferred "$_pkg"
      continue
    fi
    PENDING_COUNT=$((PENDING_COUNT + 1))
    if [ -z "$PENDING_LIST" ]; then
      PENDING_LIST="$_pkg"
    else
      PENDING_LIST="$PENDING_LIST $_pkg"
    fi
  done
  note "当てられるのに残っているもの: ${PENDING_COUNT} 件"
  note "意図的に外しているもの: ${DEFERRED_COUNT} 件 (${BLACKLIST:-なし})"
  if [ "$PENDING_COUNT" -gt 0 ]; then
    add_item pending "${PENDING_COUNT} 件のセキュリティ更新が当たっていません" \
      "$PENDING_LIST"
  fi
else
  add_item unattended "apt-get が見つかりません" "Ubuntu 以外のホストでは、この確認は使えません"
fi

# ---------------------------------------------------------------------------
# 再起動が要るか
# ---------------------------------------------------------------------------
# **ここが本題。** 自動再起動を切っている以上、誰かが気づかないと永久に当たらない。
section "再起動"
if [ -f /var/run/reboot-required ]; then
  _since_epoch="$(stat -c %Y /var/run/reboot-required 2>/dev/null || echo 0)"
  _since="$(date -d "@$_since_epoch" '+%Y-%m-%d %H:%M' 2>/dev/null || echo '不明')"
  _days=$(( ($(date +%s) - _since_epoch) / 86400 ))
  _pkgs="$(cat /var/run/reboot-required.pkgs 2>/dev/null | tr '\n' ' ' || true)"
  # 鍵は「要求された時刻」。文の日数は毎日増えるが、要求そのものは同じ ——
  # 再起動して新しい要求が出れば時刻が変わるので、そのときはちゃんと知らせる
  add_item reboot "再起動しないと更新が効きません(${_days} 日前から)" \
    "要求された時刻: ${_since}
対象: ${_pkgs:-不明}" \
    "reboot|${_since_epoch}"
else
  note "再起動は要りません"
fi

# ---------------------------------------------------------------------------
# SSH の入口
# ---------------------------------------------------------------------------
# **総当たりは来ている前提で見る。** インターネットに出ているので、
# 止められるものではない(実測: 1日に数千件、`admin` `oracle` `test` などを順に試す)。
# 意味があるのは**当てられる入口が開いているかどうか**だけ:
#
#   - 合鍵(公開鍵)だけなら、いくら来ても通らない
#   - パスワードで入れるなら、**総当たりはいつか当たる**
#
# 設定は `sshd -T` に聞く。**ファイルを読まない** ——
# `Include /etc/ssh/sshd_config.d/*.conf` があり、後から書かれた方が勝つので、
# `sshd_config` だけ読むと**実際と逆の答え**を出す。
section "SSH の入口"
SSHD_BIN=""
if command -v sshd >/dev/null 2>&1; then
  SSHD_BIN="sshd"
elif [ -x /usr/sbin/sshd ]; then
  SSHD_BIN="/usr/sbin/sshd"
fi

if [ -z "$SSHD_BIN" ]; then
  note "sshd がありません(この確認は飛ばします)"
elif [ "$(id -u)" -ne 0 ]; then
  note "root で走らせると SSH の入口も見ます(sudo を付けてください)"
else
  SSHD_CONF="$("$SSHD_BIN" -T 2>/dev/null || true)"
  if [ -z "$SSHD_CONF" ]; then
    note "sshd の設定を読めませんでした"
  else
    _pw="$(printf '%s\n' "$SSHD_CONF" | sed -n 's/^passwordauthentication //p' | head -n 1)"
    _kbd="$(printf '%s\n' "$SSHD_CONF" | sed -n 's/^kbdinteractiveauthentication //p' | head -n 1)"
    _root="$(printf '%s\n' "$SSHD_CONF" | sed -n 's/^permitrootlogin //p' | head -n 1)"

    # 直近1日の総当たりの数。**「来ている」ことを数で見せる**(判断の材料にする)
    _tries=0
    if command -v journalctl >/dev/null 2>&1; then
      _tries="$(journalctl -u ssh --since '24 hours ago' 2>/dev/null \
        | grep -Ec 'preauth|Invalid user|Failed password' || true)"
    fi
    note "直近24時間の総当たり: ${_tries:-0} 件"

    if [ "$_pw" = "yes" ] || [ "$_kbd" = "yes" ]; then
      add_item ssh "SSH がパスワードで入れる状態です" \
        "直近24時間の総当たり: ${_tries:-0} 件
passwordauthentication=${_pw:-不明} / kbdinteractiveauthentication=${_kbd:-不明}"
    else
      note "パスワードでは入れません(合鍵のみ。正しい)"
    fi

    # `prohibit-password` は鍵だけ許す設定なので問題ない
    if [ "$_root" = "yes" ]; then
      add_item ssh "root で直接ログインできる状態です" \
        "permitrootlogin=yes。prohibit-password(鍵のみ)か no にしてください"
    else
      note "root の直接ログイン: ${_root:-不明}"
    fi
  fi
fi

# ---------------------------------------------------------------------------
# fail2ban
# ---------------------------------------------------------------------------
# **入っているのに動いていない**を拾う。入っていないこと自体は数えない ——
# 合鍵だけで入る設定なら総当たりは当たらないので、fail2ban は記録と負荷を減らす補助にすぎない。
#
# ただ、**入っていれば守られていると思い込む。** 2026-09-14 の実測では、
# 起動直後に exit 255 で落ちたまま(failed)だった。/etc/fail2ban/jail.local は在り、
# 誰もホストを見ないので気づけない。
#
# 鍵は状態だけ。**直近の記録は毎日変わる**ので、鍵に入れると毎日送ってしまう(add_item の注記)。
section "fail2ban"
if ! command -v systemctl >/dev/null 2>&1; then
  note "systemctl がありません(この確認は飛ばします)"
elif ! systemctl cat fail2ban.service >/dev/null 2>&1; then
  note "fail2ban は入っていません"
else
  _f2b_state="$(systemctl is-active fail2ban 2>/dev/null || true)"
  if [ "$_f2b_state" = "active" ]; then
    note "fail2ban は動いています"
  else
    _f2b_enabled="$(systemctl is-enabled fail2ban 2>/dev/null || true)"
    # **タブと制御文字を落としてから載せる。** このファイルの json_escape は `\` と `"` と改行しか
    # 逃がさないので、Python の記録に混じるタブや色付けがそのまま入ると JSON として読めず、
    # notify-security.php が**ほかの項目ごと1通も送らなくなる**(send-log.sh の json_escape と同じ落とし方)
    _f2b_log="$(journalctl -u fail2ban -n 5 --no-pager -o cat 2>/dev/null | tr '\t' ' ' | tr -d '\000-\010\013\014\016-\037\177' || true)"
    add_item fail2ban "fail2ban が入っているのに動いていません(${_f2b_state:-不明})" \
      "is-active=${_f2b_state:-不明} / is-enabled=${_f2b_enabled:-不明}
設定の検査: sudo fail2ban-client -t
直ったら: sudo systemctl enable --now fail2ban
直近の記録:
${_f2b_log:-(読めません)}" \
      "fail2ban|${_f2b_state:-unknown}"
  fi
fi

# ---------------------------------------------------------------------------
# Logto Console のアカウント
# ---------------------------------------------------------------------------
# **Console に入れる人は、利用者も m2m の資格情報も作り替えられる**(このサイトで一番強い鍵)。
#
# Logto はテナントを2つ持つ。default(サイトの利用者)と admin(Console に入るアカウント)で、
# **サインインの設定が別々**。default は MFA 必須にしてあるが、admin は Console の画面から変えられず、
# 2026-09-14 の実測では MFA が NoPrompt(パスワードだけで入れる)のままだった。
#
# 聞くのは postgres コンテナの中の psql。**資格情報を持ち出さない** —— コンテナの中は
# 自分の環境変数とローカルの trust で入る(host-backup.sh の pg_dump と同じ)。
# SQL は -e で渡す(引用符を二重に入れ子にしない)。
#
# 問い合わせられないとき(postgres が止まっている・構成が違う)は項目にしない。
# 止まっていればサイトごと入れないので、別の形で気づく。
section "Logto Console のアカウント"
LOGTO_MFA_SQL="select coalesce(mfa->>'policy', '') from sign_in_experiences where tenant_id = 'admin'"
LOGTO_NOMFA_SQL="select count(*) from users where tenant_id = 'admin' and coalesce(jsonb_array_length(mfa_verifications), 0) = 0"
_admin_mfa=""
_admin_nomfa=""
if [ -f "$PATH_ROOT/compose.yaml" ] && command -v docker >/dev/null 2>&1; then
  # shellcheck disable=SC2016
  _admin_mfa="$(cd "$PATH_ROOT" && timeout 30 docker compose exec -T -e KM_SQL="$LOGTO_MFA_SQL" postgres sh -c 'psql -X -q -t -A -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "$KM_SQL"' </dev/null 2>/dev/null | tr -d '\r' | head -n 1 || true)"
  # shellcheck disable=SC2016
  _admin_nomfa="$(cd "$PATH_ROOT" && timeout 30 docker compose exec -T -e KM_SQL="$LOGTO_NOMFA_SQL" postgres sh -c 'psql -X -q -t -A -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "$KM_SQL"' </dev/null 2>/dev/null | tr -d '\r' | head -n 1 || true)"
fi

case "$_admin_mfa" in
  "")
    note "Logto の DB に問い合わせられませんでした(この確認は飛ばします)"
    ;;
  Mandatory)
    note "Console のアカウントは2段階認証が必須です"
    ;;
  *)
    # 鍵は設定の値だけ。未登録の人数は登録すれば減るので、変わったら知らせてよい数だが、
    # 設定を直さない限り同じ問題なので入れない
    add_item logto "Logto Console のアカウントに2段階認証が要りません(MFA: ${_admin_mfa})" \
      "admin テナント(Logto Console に入るアカウント)の MFA の設定: ${_admin_mfa}
2段階認証を登録していない Console の利用者: ${_admin_nomfa:-不明} 人
サイトの利用者(default テナント)とは別の設定で、Console の画面からは変えられません。
直し方(先に手元で scripts/backup-data.ps1 を実行して控えを取ること):
  postgres コンテナの psql で(host-backup.sh の pg_dump と同じ入り方)次を実行する
  update sign_in_experiences set mfa = jsonb_build_object('policy', 'Mandatory', 'factors', jsonb_build_array('Totp', 'WebAuthn')) where tenant_id = 'admin';
  次に Console へ入るとき、認証アプリの登録を求められます(スマートフォンを手元に)。
  反映されないときは logto を再起動する" \
      "logto|admin-mfa|${_admin_mfa}"
    ;;
esac

# ---------------------------------------------------------------------------
# サポート期限と空き容量
# ---------------------------------------------------------------------------
section "そのほか"
if command -v hwe-support-status >/dev/null 2>&1; then
  _support="$(hwe-support-status --verbose 2>/dev/null || true)"
  case "$_support" in
    *[Nn]ot\ supported*|*サポート対象外*)
      add_item support "このリリースはサポート対象外です" "$_support" ;;
    *)
      note "サポート期限は問題ありません" ;;
  esac
fi

DISK_USED="$(df -P / 2>/dev/null | awk 'NR==2{gsub(/%/,"",$5); print $5}' || echo 0)"
note "/ の使用率: ${DISK_USED}%"
if [ "${DISK_USED:-0}" -ge "$DISK_WARN_PERCENT" ]; then
  add_item disk "/ の空きが残り $((100 - DISK_USED))% です" \
    "$(df -h / 2>/dev/null | tail -n 1)"
fi

# ---------------------------------------------------------------------------
# 知らせる
# ---------------------------------------------------------------------------
HEARTBEAT_JSON=false
if [ "$HEARTBEAT" -eq 1 ]; then
  HEARTBEAT_JSON=true
fi

REPORT="{\"host\":\"$(json_escape "$HOST_LABEL")\",\"os\":\"$(json_escape "$OS_LABEL")\",\"heartbeat\":$HEARTBEAT_JSON,\"items\":[$ITEMS],\"deferred\":[$DEFERRED]}"

if [ "$JSON_ONLY" -eq 1 ]; then
  # **JSON だけを出す。** 説明の行を混ぜると `| jq` が通らない
  printf '%s\n' "$REPORT"
  exit 0
fi

if [ "$NOTIFY" -eq 0 ]; then
  echo ""
  if [ -z "$ITEMS" ]; then
    echo "問題はありません。メールを送るには --notify を付けてください。"
  else
    echo "上の ★ が見つかりました。メールを送るには --notify を付けてください。"
  fi
  exit 0
fi

if [ -z "$ITEMS" ] && [ "$HEARTBEAT" -eq 0 ]; then
  echo ""
  echo "問題がないので送りません。"
  # **直ったら忘れる。** 覚えたままだと、同じ問題が再発したときに
  # 「変わっていない」と判断して黙ってしまう
  rm -f "$STATE_FILE" 2>/dev/null || true
  exit 0
fi

# 前回と同じ知らせか。**表示用の文ではなく、鍵で見る**(add_item の注記)。
# 文には「(1 日前から)」のように時間が経つだけで変わる数が入り、毎日別物に見えてしまう
FINGERPRINT="$(printf '%s' "$KEYS" | md5sum 2>/dev/null | awk '{print $1}' || true)"

should_send() {
  # 月に一度の便りは必ず出す。**ここを抑えると沈黙が正常に見える**
  if [ "$HEARTBEAT" -eq 1 ]; then
    return 0
  fi
  if [ ! -f "$STATE_FILE" ] || [ -z "$FINGERPRINT" ]; then
    return 0
  fi
  _prev_fp="$(sed -n '1p' "$STATE_FILE" 2>/dev/null || true)"
  _prev_at="$(sed -n '2p' "$STATE_FILE" 2>/dev/null || true)"
  if [ "$_prev_fp" != "$FINGERPRINT" ]; then
    return 0
  fi
  _age_days=$(( ($(date +%s) - ${_prev_at:-0}) / 86400 ))
  if [ "$_age_days" -ge "$KM_REMIND_DAYS" ]; then
    return 0
  fi
  return 1
}

if ! should_send; then
  echo ""
  echo "前回と同じ内容なので送りません(${KM_REMIND_DAYS} 日たてば念押しを送ります)。"
  echo "いま送るなら --heartbeat を付けてください。"
  exit 0
fi

# **移動できなければ理由を出して終わる。**
# cron から走るので、黙って落ちると「メールが来ない」だけが残り、
# 問題が無いのか仕組みが止まったのか読み分けられなくなる。
if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

echo ""
echo "== メールを送ります =="

# **送れてから覚える。** 先に覚えると、送信に失敗した回で「送った」ことになり、
# 次からは「前回と同じ」で黙る —— 一度きりの取りこぼしが**永久の沈黙**になる。
if printf '%s' "$REPORT" | docker compose exec -T web php scripts/notify-security.php; then
  if mkdir -p "$STATE_DIR" 2>/dev/null; then
    printf '%s\n%s\n' "$FINGERPRINT" "$(date +%s)" > "$STATE_FILE" 2>/dev/null || true
  fi
  exit 0
fi

echo "送れませんでした。次の実行でもう一度試します。" >&2
exit 1
