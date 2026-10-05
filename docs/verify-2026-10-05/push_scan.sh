#!/bin/bash
# push 前の検査(tools/push-github.ps1 -CheckOnly)を本物の PowerShell で動かし、公開 IP と氏名で**本当に止まるか**を確かめる。
# 作業ツリーには触れない(このリポジトリを一時ディレクトリへ clone して、そちらで動かす)。
# 要るもの: pwsh(PWSH で場所を指定できる)・git
#
# 使う値はすべて架空: 公開 IP は example.com のアドレス(個人の回線ではない)、氏名は「架空 次郎」。
# IP は**実行時に組み立てる** —— 文字列のまま置くと、このファイル自身が push 前の検査(tools/push-github.ps1)に止められる
source "$(dirname "$0")/lib.sh"
CUR=push-scan
PWSH="${PWSH:-pwsh}"
command -v "$PWSH" >/dev/null || die push-scan "pwsh がありません(PWSH=… で場所を指定)"
REPO="$(cd "$V/../.." && pwd)"
tmp=$(mktemp -d)
trap 'rm -r "${tmp:?}"' EXIT
git clone -q "$REPO" "$tmp/repo" || die push-scan "clone できません"
git -C "$tmp/repo" config user.email verify@example.invalid; git -C "$tmp/repo" config user.name verify
run_scan() { # → SCAN_RC / SCAN_OUT
    SCAN_OUT=$(cd "$tmp/repo" && HOME="$tmp/home" "$PWSH" -NoProfile -File tools/push-github.ps1 -CheckOnly -Message verify 2>&1)
    SCAN_RC=$?
    git -C "$tmp/repo" reset -q; git -C "$tmp/repo" clean -qfd -e home
}
mkdir -p "$tmp/home/.kosenmap"

run_scan
echo "1) 何も足さない: 終了コード $SCAN_RC"
[ "$SCAN_RC" = 0 ] || { echo "$SCAN_OUT" | tail -20; die push-scan "何も足さないのに止まる(終了コード $SCAN_RC)"; }

TEST_IP="93.184.$((200 + 16)).$((30 + 4))"   # example.com
printf '接続元は %s です\n' "$TEST_IP" > "$tmp/repo/verify-ip.md"
run_scan
echo "2) 公開 IP を書いたファイルを足す: 終了コード $SCAN_RC"
if [ "$SCAN_RC" = 1 ] && printf '%s' "$SCAN_OUT" | grep -q '公開の IPv4'; then verdict W-47s FIXED "push 前の検査が公開 IP で止まる(-CheckOnly で終了コード 1)"
elif [ "$SCAN_RC" = 0 ]; then verdict W-47s REPRO "公開 IP を足しても push 前の検査が止まらない"
else echo "$SCAN_OUT" | tail -20; die W-47s "想定外の結果(終了コード $SCAN_RC)"; fi

printf '架空 次郎\n' > "$tmp/home/.kosenmap/private-names.txt"
printf '担当は架空 次郎 先生\n' > "$tmp/repo/verify-name.md"
run_scan
echo "3) 氏名の一覧にある名前を書いたファイルを足す: 終了コード $SCAN_RC"
if [ "$SCAN_RC" = 1 ] && printf '%s' "$SCAN_OUT" | grep -q '氏名'; then verdict W-53s FIXED "push 前の検査が、一覧にある氏名で止まる"
elif [ "$SCAN_RC" = 0 ]; then verdict W-53s REPRO "一覧にある氏名を足しても止まらない"
else echo "$SCAN_OUT" | tail -20; die W-53s "想定外の結果(終了コード $SCAN_RC)"; fi
printf '%s' "$SCAN_OUT" | grep -q '架空 次郎' && verdict W-53s-log REPRO "検査の出力に氏名がそのまま出る" || verdict W-53s-log FIXED "検査の出力に氏名を出さない"

rm "$tmp/home/.kosenmap/private-names.txt"
printf '担当は架空 次郎 先生\n' > "$tmp/repo/verify-name.md"
run_scan
echo "4) 氏名の一覧が無い PC で、同じファイルを足す: 終了コード $SCAN_RC"
if [ "$SCAN_RC" = 0 ]; then verdict W-53s-nolist REPRO "一覧が無い PC では、氏名を足しても止まらない(知らせるだけ)"
else verdict W-53s-nolist FIXED "一覧が無いと止まる"; fi

# 一覧を作る道具を、氏名を持っていた版のダンプで動かす(出力に氏名を出さないか・件数が合うか)
ANDROID="$(cd "$V/../../.." && pwd)/Ichinoseki_Kosen"
if git -C "$ANDROID" show 6e118ec:php/Kosen_map.sql > "$tmp/dump.sql" 2>/dev/null; then
    out=$(cd "$tmp/repo" && HOME="$tmp/home" "$PWSH" -NoProfile -File tools/update-private-names.ps1 -Dump "$tmp/dump.sql" -Output "$tmp/home/names.txt" 2>&1); rc=$?
    n=$( [ -f "$tmp/home/names.txt" ] && grep -c . "$tmp/home/names.txt" || echo 0)
    echo "5) update-private-names.ps1: 終了コード $rc / 一覧の行数 $n"
    [ "$rc" = 0 ] && [ "$n" -ge 50 ] || { echo "$out" | tail -10; die names-tool "一覧を作れない(終了コード $rc・$n 行)"; }
    leaked=$(python3 -c "import sys;names=[l.strip() for l in open(sys.argv[1],encoding='utf-8') if l.strip()];out=sys.stdin.read();print(sum(1 for x in names if x in out))" "$tmp/home/names.txt" <<< "$out")
    [ "$leaked" = 0 ] && verdict names-tool FIXED "一覧を作る道具の出力に氏名を出さない($n 行を作った)" || verdict names-tool REPRO "一覧を作る道具の出力に氏名が $leaked 件出る"
else
    echo "5) 隣に Android のリポジトリが無いので、一覧を作る道具の確認は飛ばした"
fi
