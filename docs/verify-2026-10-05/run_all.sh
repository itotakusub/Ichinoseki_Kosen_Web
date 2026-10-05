#!/bin/bash
# setup.sh の後に、所見の検証を順に走らせる。
#   判定の一覧: results/summary.tsv(番号・REPRO|FIXED|ERROR|SKIP・理由)/ 各段の出力: results/<段>.txt
#   終了コード: 0 = 全部判定できた(成り立つ・成り立たないは問わない) / 2 = 検証の失敗(ERROR)が 1 件以上
# 9/25 版は全段を tee に通して終了コードを捨てており、何が失敗しても 0 で終わっていた。
set -uo pipefail
V="$(cd "$(dirname "$0")" && pwd)"; cd "$V"
mkdir -p results; : > results/summary.tsv
./servers.sh || { printf 'servers\tERROR\t検証用サーバーが起動しない\n' >> results/summary.tsv; echo "サーバーが起動しません" >&2; exit 2; }
run() { # $1=段の名前 $2...=コマンド
    local name=$1; shift
    "$@" > "results/$name.txt" 2>&1
    local rc=$?
    cat "results/$name.txt"
    if [ "$rc" -ne 0 ]; then
        echo "→ $name: 終了コード $rc"
        # 2 は die() が理由を書いて抜けた印。それ以外(構文の誤り・例外など)は理由が残っていないので、ここで書く
        [ "$rc" -eq 2 ] || printf '%s\tERROR\t終了コード %s(判定の行を残さずに終わった)\n' "$name" "$rc" >> results/summary.tsv
    fi
}
run w44_45 ./w44_45.sh
run w46    ./w46.sh
run w49    ./w49.sh
run w48    ./w48.sh
run w50_51 ./w50_51.sh
run w52    ./w52.sh
run wa07   ./wa07.sh
run n_update ./n_update.sh
run n_calib  ./n_calib.sh
run n_guest  ./n_guest.sh
run n_suspend ./n_suspend.sh
run n_xss_rank ./n_xss_rank.sh
run w47    python3 w47.py
if [ -d "$V/../../../Ichinoseki_Kosen/.git" ]; then run w53 python3 w53.py
else printf 'W-53\tSKIP\t非公開の Android リポジトリが隣に無い\n' >> results/summary.tsv; fi
if command -v "${PWSH:-pwsh}" >/dev/null; then run push_scan ./push_scan.sh
else printf 'push-scan\tSKIP\tpwsh が無い(PWSH=… で場所を指定)\n' >> results/summary.tsv; fi
echo; echo "===== 判定の一覧(results/summary.tsv)"
awk -F'\t' '{ s = ($2=="REPRO") ? "成り立つ" : ($2=="FIXED") ? "成り立たない" : ($2=="SKIP") ? "飛ばした" : "検証の失敗"; printf "%-14s %-10s %s\n", $1, s, $3 }' results/summary.tsv
if grep -q "	ERROR	" results/summary.tsv; then echo "検証の失敗があります(終了コード 2)"; exit 2; fi
