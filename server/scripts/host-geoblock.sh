#!/bin/sh
#
# 国単位のアクセス拒否(2026-10-06。2026-10-05 の計画 C8、利用者の質問「ほぼリソース消費なしで拒否できるか」)。
#
#   sudo sh scripts/host-geoblock.sh --path /opt/kosenmap status      今の設定・一覧・当てているか・落とした数
#   sudo sh scripts/host-geoblock.sh --path /opt/kosenmap update      国別の一覧を取り直す(当てはしない)
#   sudo sh scripts/host-geoblock.sh --path /opt/kosenmap apply       一覧から nftables の表を作って当てる(無ければ先に取る)
#   sudo sh scripts/host-geoblock.sh --path /opt/kosenmap apply --dry-run   当てる中身を出すだけ(nft -c で形も確かめる。**一覧は取らない** —— 初回は先に update)
#   sudo sh scripts/host-geoblock.sh --path /opt/kosenmap remove      表を外す(拒否をやめる)
#
# ## 何をするか
#
# カーネルの nftables に**専用の表 `inet km_geoblock`** を 1 つ作り、国別の IP の範囲を集合(interval set)に載せて
# **prerouting(priority -150)で落とす**。Docker の宛先の書き換え(nat、priority -100)より前なので、
# ホストの SSH にも、Docker が publish したポート(80・443。3001・6001 は 2026-10-19、8281・3002 は 2026-11-02 に閉じた)にも効く。
# 処理はカーネルの中で、集合は木の検索なので CPU はほぼ使わない。メモリは国の数しだいで数 MB。
#
# ## 設定(ホストにだけ置く。リポジトリには置かない)
#
# `$PATH_ROOT/geoblock.local.conf`(`*.local.conf` なので配備でも GitHub の控えでも送らない)。**root の持ち物・600 にすること。**
# 中身は `名前=値` の行だけを読む(**シェルとして実行しない**)。
#
#   DENY_COUNTRIES=cn ru kp          拒否する国(ISO 3166 の 2 文字・空白区切り)。空なら国の拒否はしない
#   DENY_INVERT=yes                  拒否の一覧を反転する: **DENY_COUNTRIES に書いた国だけを通し、ほかの国は全部拒否**
#                                    (2026-10-08、利用者の指示)。既定は no。yes / 1 / true / on で反転
#   ADMIN_COUNTRIES=jp               管理用のポートに入れる国。空なら絞らない
#   ADMIN_PORTS=                     管理用のポート。**既定は空**(2026-11-02 に 8281 の phpMyAdmin を外し、3002 の Console は 127.0.0.1 だけにした。11-03 に戻した phpMyAdmin も 127.0.0.1:8281 だけ)
#   EXEMPT_PORTS=22                  **どの国からでも通すポート(既定 22 = SSH)。締め出されないために残すこと**
#   ALLOW_GOOGLE=yes                 Google の送り元を GOOGLE_PORTS だけ通す(2026-11-07、利用者の決定。既定は no)。下の「Google」
#   GOOGLE_PORTS=443                 ALLOW_GOOGLE で通すポート(既定 443)
#
# ## Google(ALLOW_GOOGLE=yes)
#
# 反転(日本だけ通す)にすると、**Android の App Links の確認が通らない**(2026-11-07 に実機で見つけた)。
# 確認するのは端末ではなく Google のサーバーで、海外から 443 番の /.well-known/assetlinks.json を取りに来る ——
# 落とすと確認が失敗し(端末の pm get-app-links が 1024)、共有のリンクがアプリでなくブラウザで開く。
# どの範囲から来るかは公開されていないので、Google が公開している一覧を 2 通り通す:
#   - 巡回と取得の一覧(common-crawlers・special-crawlers・user-triggered-fetchers・user-triggered-fetchers-google)。そのまま通す
#   - Google 全体(goog.json)から、Google Cloud の利用者の分(cloud.json)を**除いたもの**。他人の GCP の VM は通らない
# cloud.json が取れていなければ、2 つ目は当てない(除けないまま Google 全体を通さない)。
#
# LAN・ループバック・Docker の内側(10/8・172.16/12・192.168/16・100.64/10・127/8・fc00::/7・fe80::/10)は常に通す。
# **こちらから外へつないだ通信の戻り(ct state established,related)も常に通す**(2026-10-08)。
# prerouting は戻りの包みも通るので、これが無いと、拒否した国にあるサーバー(更新・一覧・メールの送り先)への通信が戻ってこない。
# 反転したときは日本の外のほとんどがそうなるので、必ず要る。国で見るのは、外から新しくつないでくる包みだけ。
#
# ## 反転(DENY_INVERT=yes)の注意
#
# - **証明書の更新(Let's Encrypt の HTTP-01)は、海外の確認元から 80 番に来る。** 80 を EXEMPT_PORTS に入れないと更新が失敗する
#   (80 番は確認のファイルと https への転送だけなので、通しても害は小さい)。入っていなければ apply が注意を出す
# - IPv6 の一覧が無い国だけを書くと、外からの IPv6 は全部落ちる(反転なので「書いた国の IPv6」が空 = 通す相手なし)
# - IPv4 の一覧が 1 件も無ければ当てない(SSH 以外の誰も入れなくなる)
#
# ## 一覧
#
# ipdeny.com の国別の集約済み一覧(IPv4・IPv6)。置き場は /var/lib/kosenmap/geoblock/。
# **取り直しに失敗したら前の一覧を使い続ける**(空の一覧で上書きしない)。毎月 1 日に取り直して当て直し、
# 再起動のあとにも当て直す(scripts/host-updates-setup.sh の cron、版 8)。設定が無ければ何もしない。
#
# ## 注意
#
# - 国の判定は IP の割り当てに基づく大まかなもの。VPN・クラウドを使えば国を偽れる(拒否は「ふるい」であって鍵ではない)
# - 管理用のポートを日本に絞ると、海外から管理するときは入れない(そのときは remove するか ADMIN_COUNTRIES を空に)
# - sudo が要る(利用者が当てる)
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
ACTION=""
DRY_RUN=0
while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    status|update|apply|remove) ACTION="$1"; shift ;;
    -h|--help) sed -n '2,54p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done
if [ -z "$ACTION" ]; then
  echo "使い方: host-geoblock.sh --path /opt/kosenmap status|update|apply|remove [--dry-run]" >&2
  exit 2
fi

CONF="$PATH_ROOT/geoblock.local.conf"
DATA_DIR="${KM_GEOBLOCK_DATA_DIR:-/var/lib/kosenmap/geoblock}"
TABLE="km_geoblock"
V4_URL="https://www.ipdeny.com/ipblocks/data/aggregated/%s-aggregated.zone"
V6_URL="https://www.ipdeny.com/ipv6/ipaddresses/aggregated/%s-aggregated.zone"
# Google の一覧(ALLOW_GOOGLE=yes のときだけ取る)。置き場の名前は国(2 文字)と重ならないよう g- で始める
GOOGLE_ALL_URL="https://www.gstatic.com/ipranges/goog.json"
GOOGLE_CLOUD_URL="https://www.gstatic.com/ipranges/cloud.json"
GOOGLE_FETCH_URLS="https://developers.google.com/static/crawling/ipranges/common-crawlers.json
https://developers.google.com/static/crawling/ipranges/special-crawlers.json
https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers.json
https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json"

# ---- 設定を読む(`名前=値` だけ。シェルとして実行しない) ----
DENY_COUNTRIES=""
DENY_INVERT=""
ADMIN_COUNTRIES=""
ADMIN_PORTS=""
EXEMPT_PORTS="22"
ALLOW_GOOGLE=""
GOOGLE_PORTS="443"
HAVE_CONF=0
if [ -f "$CONF" ]; then
  HAVE_CONF=1
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      ''|'#'*) continue ;;
    esac
    key="${line%%=*}"
    value="${line#*=}"
    # 値は英小文字・数字・空白だけを残す(それ以外の字が混じっていたら捨てる)
    value="$(printf '%s' "$value" | tr 'A-Z' 'a-z' | tr -c 'a-z0-9 \n' ' ' | tr -s ' ' | sed 's/^ //; s/ $//')"
    case "$key" in
      DENY_COUNTRIES) DENY_COUNTRIES="$value" ;;
      DENY_INVERT) DENY_INVERT="$value" ;;
      ADMIN_COUNTRIES) ADMIN_COUNTRIES="$value" ;;
      ADMIN_PORTS) ADMIN_PORTS="$value" ;;
      EXEMPT_PORTS) EXEMPT_PORTS="$value" ;;
      ALLOW_GOOGLE) ALLOW_GOOGLE="$value" ;;
      GOOGLE_PORTS) GOOGLE_PORTS="$value" ;;
    esac
  done < "$CONF"
fi

valid_country() { printf '%s' "$1" | grep -Eq '^[a-z]{2}$'; }
valid_port() { printf '%s' "$1" | grep -Eq '^[0-9]{1,5}$' && [ "$1" -ge 1 ] && [ "$1" -le 65535 ]; }

for cc in $DENY_COUNTRIES $ADMIN_COUNTRIES; do
  if ! valid_country "$cc"; then
    echo "国の指定が不正です: $cc(ISO 3166 の 2 文字)" >&2
    exit 2
  fi
done
for port in $ADMIN_PORTS $EXEMPT_PORTS $GOOGLE_PORTS; do
  if ! valid_port "$port"; then
    echo "ポートの指定が不正です: $port" >&2
    exit 2
  fi
done
case " $EXEMPT_PORTS " in
  *" 22 "*) ;;
  *) echo "注意: EXEMPT_PORTS に 22 がありません。拒否した国から SSH できなくなります(締め出しに注意)" >&2 ;;
esac

# 反転(書いた国だけを通す)。値は英小文字・数字だけになっている
INVERT=0
case "$DENY_INVERT" in
  yes|1|true|on) INVERT=1 ;;
  ''|no|0|false|off) INVERT=0 ;;
  *) echo "DENY_INVERT の値が不正です: $DENY_INVERT(yes か no)" >&2; exit 2 ;;
esac
GOOGLE=0
case "$ALLOW_GOOGLE" in
  yes|1|true|on) GOOGLE=1 ;;
  ''|no|0|false|off) GOOGLE=0 ;;
  *) echo "ALLOW_GOOGLE の値が不正です: $ALLOW_GOOGLE(yes か no)" >&2; exit 2 ;;
esac
if [ "$GOOGLE" -eq 1 ] && [ -z "$GOOGLE_PORTS" ]; then
  echo "ALLOW_GOOGLE=yes なのに GOOGLE_PORTS が空です(既定は 443)" >&2
  exit 2
fi
if [ "$INVERT" -eq 1 ] && [ -z "$DENY_COUNTRIES" ]; then
  echo "DENY_INVERT=yes なのに DENY_COUNTRIES が空です。通す国を DENY_COUNTRIES に書いてください(空のままだと誰も入れません)" >&2
  exit 2
fi
if [ "$INVERT" -eq 1 ]; then
  case " $EXEMPT_PORTS " in
    *" 80 "*) ;;
    *) echo "注意: 反転しているのに EXEMPT_PORTS に 80 がありません。証明書の更新(Let's Encrypt の確認は海外からも 80 番に来る)が失敗します" >&2 ;;
  esac
fi

need_root() {
  if [ "$(id -u)" -ne 0 ] && [ "$DRY_RUN" -eq 0 ]; then
    echo "root で実行してください(sudo sh scripts/host-geoblock.sh …)" >&2
    exit 1
  fi
}

# ---- 一覧を取る ----
# 1 つの国・1 つの種類。形を確かめてから置き換える(失敗したら前のを残す)
fetch_list() {
  cc="$1"; family="$2"; url="$3"
  out="$DATA_DIR/$cc.$family"
  tmp="$out.tmp"
  if ! curl -fsS --max-time 60 -o "$tmp" "$(printf "$url" "$cc")"; then
    rm -f "$tmp"
    echo "  $cc ($family): 取れませんでした(前の一覧を使います)" >&2
    return 0
  fi
  if [ "$family" = "v4" ]; then
    pattern='^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$'
  else
    pattern='^[0-9a-f:]+/[0-9]{1,3}$'
  fi
  total="$(grep -c . "$tmp" || true)"
  good="$(grep -Ec "$pattern" "$tmp" || true)"
  if [ "$total" -eq 0 ] || [ "$good" -ne "$total" ]; then
    rm -f "$tmp"
    echo "  $cc ($family): 形が違います(全 $total 行のうち正しいのは $good 行)。前の一覧を使います" >&2
    return 0
  fi
  mv "$tmp" "$out"
  echo "  $cc ($family): $total 件"
}

# Google の JSON の一覧(1 つ以上の URL)から ipv4Prefix・ipv6Prefix を抜いて、$DATA_DIR/<名前>.v4 / .v6 に置く。
# 1 つでも取れない・形が違えば、その名前は前の一覧を使う(半端な一覧で置き換えない)
fetch_google() {
  name="$1"; urls="$2"
  json="$DATA_DIR/$name.json.tmp"
  : > "$json"
  for url in $urls; do
    if ! curl -fsS --max-time 60 --max-filesize 2000000 "$url" >> "$json"; then
      rm -f "$json"
      echo "  $name: $url が取れませんでした(前の一覧を使います)" >&2
      return 0
    fi
    printf '\n' >> "$json"
  done
  for family in v4 v6; do
    if [ "$family" = "v4" ]; then
      key='ipv4Prefix'; pattern='^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$'
    else
      key='ipv6Prefix'; pattern='^[0-9a-f:]+/[0-9]{1,3}$'
    fi
    tmp="$DATA_DIR/$name.$family.tmp"
    grep -o "\"$key\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" "$json" | sed 's/.*"\([^"]*\)"$/\1/' | tr 'A-F' 'a-f' | sort -u > "$tmp" || true
    total="$(grep -c . "$tmp" || true)"
    good="$(grep -Ec "$pattern" "$tmp" || true)"
    if [ "$total" -eq 0 ] || [ "$good" -ne "$total" ]; then
      rm -f "$tmp"
      echo "  $name ($family): 形が違います(全 $total 行のうち正しいのは $good 行)。前の一覧を使います" >&2
      continue
    fi
    mv "$tmp" "$DATA_DIR/$name.$family"
    echo "  $name ($family): $total 件"
  done
  rm -f "$json"
}

do_update() {
  mkdir -p "$DATA_DIR"
  chmod 700 "$DATA_DIR"
  for cc in $(printf '%s\n' $DENY_COUNTRIES $ADMIN_COUNTRIES | sort -u); do
    fetch_list "$cc" v4 "$V4_URL"
    fetch_list "$cc" v6 "$V6_URL"
  done
  if [ "$GOOGLE" -eq 1 ]; then
    fetch_google g-fetch "$GOOGLE_FETCH_URLS"
    fetch_google g-all "$GOOGLE_ALL_URL"
    fetch_google g-cloud "$GOOGLE_CLOUD_URL"
  fi
}

# 集合の中身(カンマ区切り)。一覧が無ければ空
elements() {
  family="$1"; shift
  for cc in "$@"; do
    if [ -s "$DATA_DIR/$cc.$family" ]; then
      cat "$DATA_DIR/$cc.$family"
    fi
  done | awk 'NF { printf "%s%s", (n++ ? ", " : ""), $1 }'
}

ports_set() { printf '%s' "$1" | awk '{ for (i = 1; i <= NF; i++) printf "%s%s", (i > 1 ? ", " : ""), $i }'; }

# ---- 表を組み立てる(作り直しを 1 つの読み込みで行う。途中で守りが外れる時間を作らない) ----
build_ruleset() {
  deny4="$(elements v4 $DENY_COUNTRIES)"
  deny6="$(elements v6 $DENY_COUNTRIES)"
  admin4=""; admin6=""
  if [ -n "$ADMIN_COUNTRIES" ]; then
    admin4="$(elements v4 $ADMIN_COUNTRIES)"
    admin6="$(elements v6 $ADMIN_COUNTRIES)"
  fi
  exempt="$(ports_set "$EXEMPT_PORTS")"
  adminports="$(ports_set "$ADMIN_PORTS")"
  gports=""; gfetch4=""; gfetch6=""; gall4=""; gall6=""; gcloud4=""; gcloud6=""
  if [ "$GOOGLE" -eq 1 ]; then
    gports="$(ports_set "$GOOGLE_PORTS")"
    gfetch4="$(elements v4 g-fetch)"; gfetch6="$(elements v6 g-fetch)"
    gall4="$(elements v4 g-all)"; gall6="$(elements v6 g-all)"
    gcloud4="$(elements v4 g-cloud)"; gcloud6="$(elements v6 g-cloud)"
  fi

  echo "table inet $TABLE {}"
  echo "delete table inet $TABLE"
  echo "table inet $TABLE {"
  if [ -n "$deny4" ]; then echo "  set deny4 { type ipv4_addr; flags interval; auto-merge; elements = { $deny4 } }"; fi
  if [ -n "$deny6" ]; then echo "  set deny6 { type ipv6_addr; flags interval; auto-merge; elements = { $deny6 } }"; fi
  if [ -n "$admin4" ]; then echo "  set admin4 { type ipv4_addr; flags interval; auto-merge; elements = { $admin4 } }"; fi
  if [ -n "$admin6" ]; then echo "  set admin6 { type ipv6_addr; flags interval; auto-merge; elements = { $admin6 } }"; fi
  if [ -n "$gfetch4" ]; then echo "  set gfetch4 { type ipv4_addr; flags interval; auto-merge; elements = { $gfetch4 } }"; fi
  if [ -n "$gfetch6" ]; then echo "  set gfetch6 { type ipv6_addr; flags interval; auto-merge; elements = { $gfetch6 } }"; fi
  # Google 全体は、Google Cloud の利用者の分を除けるときだけ載せる
  if [ -n "$gall4" ] && [ -n "$gcloud4" ]; then
    echo "  set gall4 { type ipv4_addr; flags interval; auto-merge; elements = { $gall4 } }"
    echo "  set gcloud4 { type ipv4_addr; flags interval; auto-merge; elements = { $gcloud4 } }"
  fi
  if [ -n "$gall6" ] && [ -n "$gcloud6" ]; then
    echo "  set gall6 { type ipv6_addr; flags interval; auto-merge; elements = { $gall6 } }"
    echo "  set gcloud6 { type ipv6_addr; flags interval; auto-merge; elements = { $gcloud6 } }"
  fi
  echo "  chain pre {"
  echo "    type filter hook prerouting priority -150; policy accept;"
  printf '%s\n' '    iif "lo" accept'
  # こちらからつないだ通信の戻り(拒否した国のサーバーへの更新・一覧の取得・メールの送信)は通す
  echo "    ct state established,related accept"
  echo "    ip saddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, 127.0.0.0/8 } accept"
  echo "    ip6 saddr { ::1, fc00::/7, fe80::/10 } accept"
  if [ -n "$exempt" ]; then echo "    tcp dport { $exempt } accept"; fi
  # Google(App Links の確認など)は、国で落とす前に GOOGLE_PORTS だけ通す
  if [ -n "$gports" ]; then
    if [ -n "$gfetch4" ]; then printf '    tcp dport { %s } ip saddr @gfetch4 counter accept comment "%s"\n' "$gports" "km: Google の巡回と取得"; fi
    if [ -n "$gfetch6" ]; then printf '    tcp dport { %s } ip6 saddr @gfetch6 counter accept comment "%s"\n' "$gports" "km: Google の巡回と取得"; fi
    if [ -n "$gall4" ] && [ -n "$gcloud4" ]; then printf '    tcp dport { %s } ip saddr @gall4 ip saddr != @gcloud4 counter accept comment "%s"\n' "$gports" "km: Google(Cloud の利用者を除く)"; fi
    if [ -n "$gall6" ] && [ -n "$gcloud6" ]; then printf '    tcp dport { %s } ip6 saddr @gall6 ip6 saddr != @gcloud6 counter accept comment "%s"\n' "$gports" "km: Google(Cloud の利用者を除く)"; fi
  fi
  if [ "$INVERT" -eq 1 ]; then
    # 反転: 書いた国の集合に**入っていない**送り元を落とす。IPv6 の一覧が無ければ、外からの IPv6 は全部落とす
    if [ -n "$deny4" ]; then printf '    ip saddr != @deny4 counter drop comment "%s"\n' "km: 指定の国以外を拒否"; fi
    if [ -n "$deny6" ]; then
      printf '    ip6 saddr != @deny6 counter drop comment "%s"\n' "km: 指定の国以外を拒否"
    else
      printf '    meta nfproto ipv6 counter drop comment "%s"\n' "km: 指定の国以外を拒否(IPv6 の一覧なし)"
    fi
  else
    if [ -n "$deny4" ]; then printf '    ip saddr @deny4 counter drop comment "%s"\n' "km: 拒否する国"; fi
    if [ -n "$deny6" ]; then printf '    ip6 saddr @deny6 counter drop comment "%s"\n' "km: 拒否する国"; fi
  fi
  if [ -n "$adminports" ] && [ -n "$admin4" ]; then printf '    tcp dport { %s } ip saddr != @admin4 counter drop comment "%s"\n' "$adminports" "km: 管理用ポートは指定の国だけ"; fi
  if [ -n "$adminports" ] && [ -n "$admin6" ]; then printf '    tcp dport { %s } ip6 saddr != @admin6 counter drop comment "%s"\n' "$adminports" "km: 管理用ポートは指定の国だけ"; fi
  echo "  }"
  echo "}"
}

case "$ACTION" in
  status)
    echo "設定: $CONF $( [ "$HAVE_CONF" -eq 1 ] && echo '(あり)' || echo '(無し → 何もしていない)')"
    if [ "$INVERT" -eq 1 ]; then
      echo "  反転: この国だけ通し、ほかの国は全部拒否: ${DENY_COUNTRIES}"
    else
      echo "  拒否する国: ${DENY_COUNTRIES:-(なし)}"
    fi
    echo "  管理用ポート(${ADMIN_PORTS:-なし})に入れる国: ${ADMIN_COUNTRIES:-(絞らない)}"
    echo "  どこからでも通すポート: ${EXEMPT_PORTS:-(なし)}"
    if [ "$GOOGLE" -eq 1 ]; then
      echo "  Google を通すポート: $GOOGLE_PORTS(巡回と取得の一覧・Google 全体から Cloud の利用者を除いたもの)"
    else
      echo "  Google: 国と同じに扱う(ALLOW_GOOGLE=no)"
    fi
    echo "一覧: $DATA_DIR"
    for cc in $(printf '%s\n' $DENY_COUNTRIES $ADMIN_COUNTRIES | sort -u); do
      for family in v4 v6; do
        f="$DATA_DIR/$cc.$family"
        if [ -s "$f" ]; then
          echo "  $cc ($family): $(grep -c . "$f") 件($(date -r "$f" '+%Y-%m-%d'))"
        else
          echo "  $cc ($family): まだ取っていません"
        fi
      done
    done
    if [ "$GOOGLE" -eq 1 ]; then
      for name in g-fetch g-all g-cloud; do
        for family in v4 v6; do
          f="$DATA_DIR/$name.$family"
          if [ -s "$f" ]; then
            echo "  $name ($family): $(grep -c . "$f") 件($(date -r "$f" '+%Y-%m-%d'))"
          else
            echo "  $name ($family): まだ取っていません(update で取る)"
          fi
        done
      done
    fi
    if command -v nft >/dev/null 2>&1 && nft list table inet "$TABLE" >/dev/null 2>&1; then
      echo "当てています(nft の表 inet $TABLE)。落とした数:"
      nft list chain inet "$TABLE" pre | grep -E 'counter' | sed 's/^[[:space:]]*/  /'
    else
      echo "当てていません(または root でないので見えません)"
    fi
    ;;
  update)
    need_root
    if [ "$HAVE_CONF" -eq 0 ]; then
      echo "設定がありません($CONF)。何もしません"
      exit 0
    fi
    do_update
    ;;
  apply)
    need_root
    if [ "$HAVE_CONF" -eq 0 ]; then
      echo "設定がありません($CONF)。何もしません"
      exit 0
    fi
    if [ -z "$DENY_COUNTRIES" ] && [ -z "$ADMIN_COUNTRIES" ]; then
      echo "拒否する国も、管理用ポートの国も指定されていません。何もしません(外すなら remove)"
      exit 0
    fi
    missing=0
    for cc in $DENY_COUNTRIES $ADMIN_COUNTRIES; do
      if [ ! -s "$DATA_DIR/$cc.v4" ]; then missing=1; fi
    done
    if [ "$GOOGLE" -eq 1 ] && { [ ! -s "$DATA_DIR/g-fetch.v4" ] || [ ! -s "$DATA_DIR/g-cloud.v4" ]; }; then missing=1; fi
    if [ "$missing" -eq 1 ] && [ "$DRY_RUN" -eq 0 ]; then
      echo "一覧がまだ無い国があるので取ります"
      do_update
    fi
    # --dry-run は何も書き換えないので一覧も取らない。無ければ先に update を案内する(2026-10-06、利用者が詰まった)
    if [ "$missing" -eq 1 ] && [ "$DRY_RUN" -eq 1 ]; then
      echo "一覧がまだ無い国があります。--dry-run は一覧を取らないので、先に次を実行してください:" >&2
      echo "  sudo sh scripts/host-geoblock.sh --path $PATH_ROOT update" >&2
      exit 1
    fi
    # 管理用ポートを国で絞るのに、その国の一覧が空なら当てない(誰も入れなくなる)
    if [ -n "$ADMIN_COUNTRIES" ] && [ -z "$(elements v4 $ADMIN_COUNTRIES)" ]; then
      echo "管理用ポートに入れる国($ADMIN_COUNTRIES)の一覧がありません。誰も入れなくなるので当てません" >&2
      exit 1
    fi
    # 反転なのに通す国の一覧が空なら当てない(SSH 以外の誰も入れなくなる)
    if [ "$INVERT" -eq 1 ] && [ -z "$(elements v4 $DENY_COUNTRIES)" ]; then
      echo "反転で通す国($DENY_COUNTRIES)の一覧がありません。誰も入れなくなるので当てません" >&2
      exit 1
    fi
    if [ "$INVERT" -eq 1 ] && [ -z "$(elements v6 $DENY_COUNTRIES)" ]; then
      echo "注意: 通す国の IPv6 の一覧がありません。外からの IPv6 は全部落とします" >&2
    fi
    if [ "$GOOGLE" -eq 1 ] && [ -z "$(elements v4 g-cloud)" ]; then
      echo "注意: Google Cloud の一覧(cloud.json)がありません。Google 全体は通さず、巡回と取得の一覧だけ通します" >&2
    fi
    RULES="$(mktemp)"
    trap 'rm -f "$RULES"' EXIT
    build_ruleset > "$RULES"
    if [ "$DRY_RUN" -eq 1 ]; then
      awk '{ if (length($0) > 200) print substr($0, 1, 200) " …(" length($0) " 字)"; else print }' "$RULES"
      if command -v nft >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        nft -c -f "$RULES" && echo "(nft -c: 形は正しい)"
      fi
      exit 0
    fi
    nft -f "$RULES"
    echo "当てました(nft の表 inet $TABLE)。外すには: sudo sh scripts/host-geoblock.sh --path $PATH_ROOT remove"
    ;;
  remove)
    need_root
    if nft list table inet "$TABLE" >/dev/null 2>&1; then
      nft delete table inet "$TABLE"
      echo "外しました(国の拒否はしていません)"
    else
      echo "当てていません"
    fi
    ;;
esac
