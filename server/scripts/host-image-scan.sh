#!/bin/sh
#
# コンテナのイメージに既知の脆弱性(HIGH・CRITICAL)が無いかを Trivy で調べる(2026-11-06)。
#
#   sudo sh ./host-image-scan.sh --path /opt/kosenmap            調べて表示し、結果を置く
#   sudo sh ./host-image-scan.sh --path /opt/kosenmap --notify   直る版のある HIGH・CRITICAL が
#                                                                **新しく**出たときだけメールも送る
#
# cron(host-updates-setup.sh の版 10)が毎週日曜 4:47 に --notify で呼ぶ。root で動かす
# (docker を使うのと、/var/lib/kosenmap に書くため)。
#
# ## docker.sock は渡さない
#
# Trivy にイメージを読ませる一番手軽な方法は docker.sock を渡すことだが、それは**ホストの root を渡すのと同じ**
# (タスクマネージャーでも Glances に渡さないと決めた。2026-10-05)。ここでは:
#
#   1. ホストの docker が `docker save` でイメージを tar に書き出す(イメージの ID で。名前の付け方に左右されない)
#   2. Trivy のコンテナには、その tar のある場所を**読み取り専用で**渡すだけ(--input)
#   3. Trivy のコンテナは root の FS も読み取り専用・権限(capability)は全部外す・メモリと CPU に上限
#   4. tar は 1 つ調べるごとに消す
#
# Trivy が外へ出るのは脆弱性の DB を取るときだけ(DB は名前付きボリューム km-trivy-cache に置いて使い回す)。
#
# ## Trivy の版を固定する理由
#
# **Trivy の配布物そのものに供給網の事件があった**(CVE-2026-33634、2026-03)。v0.69.4 の配布物と、
# Docker Hub の v0.69.5・v0.69.6 に不正なものが混じった。タグは書き換えられるので、
# **版と digest の両方で固定する**(Docker Hub と GHCR で同じ digest を確かめた。2026-11-05)。
# 上げるときは、公式の告知(GitHub の Security Advisories)と digest を確かめてから TRIVY_IMAGE を書き換える。
#
# ## 知らせ
#
# 「直る版がある HIGH・CRITICAL」を (イメージ|ID|パッケージ) の組で覚え、**前に知らせていない組が出たときだけ**送る。
# 直す版が無いもの(Debian の will_not_fix など)は数えるだけで知らせない —— 毎週同じものが届くと読まれなくなる。
# 送れなかったときは覚えない(次の週にもう一度送る)。
#
# ## 置き場所
#
#   /var/lib/kosenmap/image-scan/latest.tsv        最新の結果(1 行 1 件。root だけ)
#   /var/lib/kosenmap/image-scan/scan-YYYYMMDD.tsv  日付ごと(8 回分)
#   /var/lib/kosenmap/image-scan/notified.keys      知らせ済みの組
#   $PATH_ROOT/run/hoststats/image-scan.json        要約。管理画面のタスクマネージャー「イメージの検査」が読む
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu
# 並べ替えと突き合わせ(sort・comm)の順を揃える
LC_ALL=C
export LC_ALL

PATH_ROOT="/opt/kosenmap"
NOTIFY=0

# **版と digest で固定する**(上の説明)。v0.69.4〜0.69.6 は使わない
TRIVY_VERSION="0.76.1"
TRIVY_IMAGE="aquasec/trivy:0.76.1@sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c"
TRIVY_CACHE_VOLUME="km-trivy-cache"

STATE_DIR="/var/lib/kosenmap/image-scan"
KEEP_DATED=8
# メールに並べる新しい件数の上限(残りは数だけ)
MAIL_LIST_MAX=20
# 管理画面に出す、イメージごとの直る版のある件数の上限
TOP_MAX=15

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --notify) NOTIFY=1; shift ;;
    -h|--help) sed -n '2,45p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ "$(id -u)" -ne 0 ]; then
  echo "root で動かしてください(sudo sh $0 --path $PATH_ROOT)。" >&2
  exit 2
fi
if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

OUT_DIR="$PATH_ROOT/run/hoststats"
mkdir -p "$STATE_DIR" "$OUT_DIR"
chmod 700 "$STATE_DIR"
chmod 755 "$PATH_ROOT/run" "$OUT_DIR"

# 同時に 2 本走らせない(cron と手動が重なると、tar で一時的にディスクを倍使う)
exec 9>"$STATE_DIR/.lock"
if ! flock -n 9; then
  echo "ほかの検査が動いています。終わってからもう一度。" >&2
  exit 1
fi

WORK="$(mktemp -d /var/tmp/km-image-scan.XXXXXX)"
chmod 700 "$WORK"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT INT TERM

json_escape() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | awk 'BEGIN{ORS=""} NR>1{print "\\n"} {print}'
}

# 負荷を抑える: 優先度を下げ、Trivy のコンテナにも上限を付ける(本番は 2 コア・2GB)
LOW="nice -n 10"
if command -v ionice >/dev/null 2>&1; then
  LOW="$LOW ionice -c3"
fi

trivy() {
  # 引数はそのまま Trivy へ。tar の置き場所だけを読み取り専用で渡す
  $LOW docker run --rm \
    --read-only --tmpfs /tmp:rw,size=512m \
    --cap-drop ALL --security-opt no-new-privileges \
    --memory 1g --cpus 1 --pids-limit 256 \
    -v "$TRIVY_CACHE_VOLUME:/root/.cache" \
    -v "$WORK:/scan:ro" \
    "$TRIVY_IMAGE" "$@"
}

echo "== Trivy $TRIVY_VERSION =="
# DB は最初に 1 回だけ取る(イメージごとに取りに行かない)
if ! trivy image --download-db-only --quiet --skip-version-check; then
  echo "脆弱性の DB を取れませんでした(ネットワークか、Trivy のイメージを取れていません)。" >&2
  exit 1
fi
DB_UPDATED="$(trivy version --format json 2>/dev/null \
  | sed -n 's/.*"VulnerabilityDB":{[^}]*"UpdatedAt":"\([^"]*\)".*/\1/p' | head -n 1 || true)"
echo "DB: ${DB_UPDATED:-不明}"

# 1 行 1 件。クラス(os-pkgs = OS のパッケージ / lang-pkgs = npm・composer・Go など)も残す ——
# distroless・Chainguard に替えて減るのは主に OS のパッケージなので、判断の材料になる
TEMPLATE='{{range .}}{{$c := .Class}}{{if eq .Class "os-pkgs"}}OS{{"\t"}}{{.Target}}{{"\n"}}{{end}}{{range .Vulnerabilities}}V{{"\t"}}{{$c}}{{"\t"}}{{.Severity}}{{"\t"}}{{.VulnerabilityID}}{{"\t"}}{{.PkgName}}{{"\t"}}{{.InstalledVersion}}{{"\t"}}{{.FixedVersion}}{{"\n"}}{{end}}{{end}}'

IMAGES="$(docker compose config --images 2>/dev/null | sort -u || true)"
if [ -z "$IMAGES" ]; then
  echo "compose からイメージの一覧を取れませんでした。" >&2
  exit 1
fi

NOW="$(date +%s)"
LATEST="$WORK/latest.tsv"
: > "$LATEST"
IMAGES_JSON=""
FAILED=0
COUNT=0

for IMG in $IMAGES; do
  # 名前は docker が許す文字だけ(JSON に入れても逃がす文字が無い)
  case "$IMG" in
    *[!A-Za-z0-9_./:@-]*) echo "  飛ばします(名前に使えない文字): $IMG" >&2; continue ;;
  esac
  COUNT=$((COUNT + 1))
  echo ""
  echo "-- $IMG"
  STATUS="ok"
  IMG_ID="$(docker image inspect --format '{{.Id}}' "$IMG" 2>/dev/null || true)"
  CREATED="$(docker image inspect --format '{{.Created}}' "$IMG" 2>/dev/null | cut -c1-19 || true)"
  RAW="$WORK/raw.tsv"
  : > "$RAW"
  if [ -z "$IMG_ID" ]; then
    # 使っていない profile のイメージ(本番の mailpit など)は取ってこない
    STATUS="missing"
    echo "   このホストにありません(取ってきていないイメージ)。"
  elif ! $LOW docker save -o "$WORK/img.tar" "$IMG_ID" 2>"$WORK/err.txt"; then
    STATUS="error"
    echo "   docker save に失敗: $(head -c 300 "$WORK/err.txt")" >&2
  else
    chmod 600 "$WORK/img.tar"
    if ! trivy image --input /scan/img.tar --scanners vuln --severity HIGH,CRITICAL \
        --skip-db-update --skip-version-check --quiet --cache-backend memory \
        --format template --template "$TEMPLATE" >"$RAW" 2>"$WORK/err.txt"; then
      STATUS="error"
      echo "   Trivy に失敗: $(head -c 300 "$WORK/err.txt")" >&2
    fi
    rm -f "$WORK/img.tar"
  fi
  if [ "$STATUS" = "error" ]; then
    FAILED=$((FAILED + 1))
  fi

  # 同じ (ID, パッケージ) は 1 件に数える(同じ CVE が複数の層やファイルで出る)
  OS_TARGET="$(awk -F '\t' '$1 == "OS" { print $2; exit }' "$RAW")"
  awk -F '\t' -v img="$IMG" '$1 == "V" && !seen[$4 "|" $5]++ { print img "\t" $2 "\t" $3 "\t" $4 "\t" $5 "\t" $6 "\t" $7 }' "$RAW" >> "$LATEST"
  # 数える: CRITICAL / HIGH / それぞれの直る版あり / OS のパッケージのもの
  set -- $(awk -F '\t' -v img="$IMG" '$1 == img {
      if ($3 == "CRITICAL") { c++; if ($7 != "") cf++ } else { h++; if ($7 != "") hf++ }
      if ($2 == "os-pkgs") o++
    } END { printf "%d %d %d %d %d", c, h, cf, hf, o }' "$LATEST")
  CRIT="$1"; HIGH="$2"; CRIT_FIX="$3"; HIGH_FIX="$4"; OS_VULNS="$5"
  if [ "$STATUS" = "ok" ]; then
    echo "   CRITICAL $CRIT(直る版あり $CRIT_FIX)・HIGH $HIGH(直る版あり $HIGH_FIX)・うち OS のパッケージ $OS_VULNS"
  fi

  # 直る版のあるものを重い順に TOP_MAX 件(管理画面用)
  TOP=""
  for LINE in $(awk -F '\t' -v img="$IMG" '$1 == img && $7 != "" { print ($3 == "CRITICAL" ? 0 : 1) "\t" $4 }' "$LATEST" \
      | sort -u | head -n "$TOP_MAX" | awk -F '\t' '{ print $2 }'); do
    ROW="$(awk -F '\t' -v img="$IMG" -v id="$LINE" '$1 == img && $4 == id { print; exit }' "$LATEST")"
    _sev="$(printf '%s' "$ROW" | cut -f3)"
    _pkg="$(printf '%s' "$ROW" | cut -f5)"
    _ins="$(printf '%s' "$ROW" | cut -f6)"
    _fix="$(printf '%s' "$ROW" | cut -f7)"
    _entry="{\"id\":\"$(json_escape "$LINE")\",\"severity\":\"$(json_escape "$_sev")\",\"pkg\":\"$(json_escape "$_pkg")\",\"installed\":\"$(json_escape "$_ins")\",\"fixed\":\"$(json_escape "$_fix")\"}"
    if [ -z "$TOP" ]; then TOP="$_entry"; else TOP="$TOP,$_entry"; fi
  done

  _img="{\"image\":\"$IMG\",\"status\":\"$STATUS\",\"imageId\":\"$(json_escape "$IMG_ID")\",\"created\":\"$(json_escape "$CREATED")\",\"os\":\"$(json_escape "$OS_TARGET")\",\"critical\":$CRIT,\"high\":$HIGH,\"criticalFixable\":$CRIT_FIX,\"highFixable\":$HIGH_FIX,\"osVulns\":$OS_VULNS,\"top\":[$TOP]}"
  if [ -z "$IMAGES_JSON" ]; then IMAGES_JSON="$_img"; else IMAGES_JSON="$IMAGES_JSON,$_img"; fi
done

# ---------------------------------------------------------------------------
# 置く(書いてから mv。読みかけの半端なものを渡さない)
# ---------------------------------------------------------------------------
cp "$LATEST" "$STATE_DIR/.latest.tmp"
chmod 600 "$STATE_DIR/.latest.tmp"
mv "$STATE_DIR/.latest.tmp" "$STATE_DIR/latest.tsv"
cp "$STATE_DIR/latest.tsv" "$STATE_DIR/scan-$(date +%Y%m%d).tsv"
# 日付ごとは KEEP_DATED 回分だけ残す
ls -1 "$STATE_DIR"/scan-*.tsv 2>/dev/null | sort -r | tail -n +"$((KEEP_DATED + 1))" | while read -r _old; do
  rm -f "$_old"
done

SUMMARY="{\"generatedAt\":$NOW,\"trivy\":\"$TRIVY_VERSION\",\"dbUpdatedAt\":\"$(json_escape "$DB_UPDATED")\",\"failed\":$FAILED,\"images\":[$IMAGES_JSON]}"
TMP_JSON="$(mktemp "$OUT_DIR/.image-scan.XXXXXX")"
printf '%s\n' "$SUMMARY" > "$TMP_JSON"
chmod 644 "$TMP_JSON"
mv "$TMP_JSON" "$OUT_DIR/image-scan.json"

echo ""
echo "== まとめ =="
echo "  調べたイメージ: $COUNT(失敗 $FAILED)"
echo "  結果: $STATE_DIR/latest.tsv・$OUT_DIR/image-scan.json"

# ---------------------------------------------------------------------------
# 知らせる: 直る版のある組のうち、まだ知らせていないもの
# ---------------------------------------------------------------------------
CURRENT_KEYS="$WORK/current.keys"
awk -F '\t' '$7 != "" { print $1 "|" $4 "|" $5 }' "$STATE_DIR/latest.tsv" | sort -u > "$CURRENT_KEYS"
touch "$STATE_DIR/notified.keys"
NEW_KEYS="$WORK/new.keys"
sort -u "$STATE_DIR/notified.keys" > "$WORK/notified.sorted"
comm -23 "$CURRENT_KEYS" "$WORK/notified.sorted" > "$NEW_KEYS"
NEW_COUNT="$(wc -l < "$NEW_KEYS" | tr -d ' ')"
echo "  直る版のある HIGH・CRITICAL: $(wc -l < "$CURRENT_KEYS" | tr -d ' ') 件(新しく出たもの $NEW_COUNT 件)"

remember() {
  # 今の組を覚える。**直って消えた組は忘れる**(また出たら、もう一度知らせる)
  cp "$CURRENT_KEYS" "$STATE_DIR/notified.keys"
  chmod 600 "$STATE_DIR/notified.keys"
}

if [ "$NEW_COUNT" -eq 0 ]; then
  remember
  echo "  新しく直せるものは無いので送りません。"
  # 失敗したイメージがあれば非 0 で終わる(cron の send-log.sh --only-failure が知らせる)
  if [ "$FAILED" -gt 0 ]; then exit 1; fi
  exit 0
fi
if [ "$NOTIFY" -eq 0 ]; then
  echo "  メールを送るには --notify を付けてください(知らせ済みとして覚えるのも送ったときだけ)。"
  if [ "$FAILED" -gt 0 ]; then exit 1; fi
  exit 0
fi

DETAIL="$(awk -F '\t' '{ c[$1] += ($3 == "CRITICAL"); h[$1] += ($3 == "HIGH"); if ($7 != "") { if ($3 == "CRITICAL") cf[$1]++; else hf[$1]++ } }
  END { for (i in c) printf "%s: CRITICAL %d(直る版あり %d)・HIGH %d(直る版あり %d)\n", i, c[i], cf[i], h[i], hf[i] }' "$STATE_DIR/latest.tsv" | sort)
"
DETAIL="$DETAIL
新しく出たもの(直る版あり):
$(head -n "$MAIL_LIST_MAX" "$NEW_KEYS" | while IFS='|' read -r _i _id _p; do
  awk -F '\t' -v img="$_i" -v id="$_id" -v pkg="$_p" '$1 == img && $4 == id && $5 == pkg { printf "  %s %s %s %s → %s(%s)\n", $3, $4, $5, $6, $7, $1; exit }' "$STATE_DIR/latest.tsv"
done)"
if [ "$NEW_COUNT" -gt "$MAIL_LIST_MAX" ]; then
  DETAIL="$DETAIL
  ほか $((NEW_COUNT - MAIL_LIST_MAX)) 件"
fi
DETAIL="$DETAIL

管理画面のタスクマネージャー →「イメージの検査」に一覧があります。
直すには、イメージを新しい digest に上げる(check-updates の知らせ)か、web なら作り直します(deploy-to-host.ps1 -Action up)。
Trivy $TRIVY_VERSION・DB ${DB_UPDATED:-不明}"

HOST_LABEL="$(hostname 2>/dev/null || echo unknown)"
REPORT="{\"host\":\"$(json_escape "$HOST_LABEL")\",\"os\":\"\",\"heartbeat\":false,\"items\":[{\"kind\":\"images\",\"summary\":\"$(json_escape "コンテナのイメージに、直る版のある HIGH・CRITICAL が新しく $NEW_COUNT 件")\",\"detail\":\"$(json_escape "$DETAIL")\"}],\"deferred\":[]}"

echo ""
echo "== メールを送ります =="
# **送れてから覚える。** 先に覚えると、送れなかった回が永久の沈黙になる(host-security-check.sh と同じ)
if printf '%s' "$REPORT" | docker compose exec -T web php scripts/notify-security.php; then
  remember
  if [ "$FAILED" -gt 0 ]; then exit 1; fi
  exit 0
fi
echo "送れませんでした。次の回にもう一度送ります。" >&2
exit 1
