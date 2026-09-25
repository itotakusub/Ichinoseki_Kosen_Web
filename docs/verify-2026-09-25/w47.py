# W-47: tools/push-github.ps1 の検査の正規表現を、IP が書かれた行に当てる(.NET と同じ意味になる範囲の書き方だけ使っている)
import re, pathlib
repo = pathlib.Path(__file__).resolve().parents[2]
ps1 = (repo / 'tools/push-github.ps1').read_text(encoding='utf-8')
def pat(name):
    m = re.search(r"^\$" + name + r" = '(.*)'\s*$", ps1, re.M)
    return m.group(1).replace("''", "'")
pats = {n: pat(n) for n in ('valuePattern', 'pemPattern', 'tokenPattern', 'emailPattern')}
pats['valuePattern'] = pats['valuePattern'].replace('(?i)', '')
lines = (repo / 'server/docs/12-hardening-2026-09-15.ipynb').read_text(encoding='utf-8').splitlines()
for no in (168, 1391, 1410, 1853):
    line = '+' + lines[no - 1]   # 検査は git diff の追加行(+ で始まる)に掛かる
    hit = [n for n, p in pats.items() if re.search(p, line, re.I if n == 'valuePattern' else 0)]
    print(f'12-hardening-2026-09-15.ipynb:{no} → 検査に当たったもの: {hit or "なし(push が止まらない)"}')
print('IPv4 を探す検査があるか:', bool(re.search(r'\\d\{1,3\}', ps1)))
