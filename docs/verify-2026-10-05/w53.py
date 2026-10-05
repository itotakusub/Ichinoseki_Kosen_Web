# W-53: 教職員の氏名(非公開の Android リポジトリの、2026-09-24 時点の DB ダンプの occupant_name)が、
# 公開リポジトリの今のファイル・全履歴に出ているか。**氏名は伏せて出す**(番号と場所だけ)
#
# 9/25 版は Android の作業ツリーのダンプを読んでいたが、今の main ではダンプが表定義だけになったので、
# 氏名を持っていた版(コミット 6e118ec)を git show で読む。
import pathlib, re, subprocess, sys
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from verdict import verdict, die

here = pathlib.Path(__file__).resolve()
pub = here.parents[2]
android = here.parents[3] / 'Ichinoseki_Kosen'
r = subprocess.run(['git', '-C', str(android), 'show', '6e118ec:php/Kosen_map.sql'], capture_output=True, text=True)
if r.returncode != 0:
    die('W-53', f'氏名の出どころ(Android の 6e118ec:php/Kosen_map.sql)を読めません: {r.stderr.strip()[:200]}')
m = re.search(r"INSERT INTO `km_map_nodes`.*?VALUES\n(.*?);\n", r.stdout, re.S)
if not m:
    die('W-53', 'ダンプに km_map_nodes の INSERT がありません')
rows = re.findall(r"\('([^']*)', '([^']*)', '([^']*)', (NULL|'[^']*'), '([^']*)'", m.group(1))
names = sorted({occ.strip("'") for _, _, _, occ, _ in rows if occ != 'NULL' and occ.strip("'")})
if len(names) < 10:
    die('W-53', f'氏名が少なすぎます({len(names)} 人)。読み方が壊れている')
print(f'照合に使う氏名: {len(names)} 人(値は出さない)')
head = []
for i, n in enumerate(names):
    out = subprocess.run(['git', '-C', str(pub), 'grep', '-nF', n, 'HEAD', '--'], capture_output=True, text=True).stdout
    for line in out.splitlines():
        _, path, no, _ = line.split(':', 3)
        head.append(f'氏名#{i}  {path}:{no}')
print(f'今のファイル(HEAD): {len(head)} 件')
for h in head[:20]:
    print('  ' + h)
# 履歴: 公開されている参照ごとに数える(public_refs.py。`--all` は手元の古い参照まで見るので使わない)
from public_refs import public_refs, added_lines
try:
    refs = public_refs(pub)
except Exception as exc:
    die('W-53h', f'公開されている参照を取り寄せられません: {exc}')
if not any(kind == 'branch' and label.endswith('/main') for label, _, kind in refs):
    die('W-53h', '対照: 取り寄せた参照に main が無い')
hist = {}
for label, ref, kind in refs:
    added = '\n'.join(line for _, line in added_lines(pub, ref))
    people = sum(1 for n in names if n in added)
    print(f'  {label:<16} 氏名: {people} 人')
    if people:
        hist[(label, kind)] = people
branch_hits = sorted(f'{l}({n} 人)' for (l, k), n in hist.items() if k == 'branch')
pull_hits = sorted((f'{l}({n} 人)' for (l, k), n in hist.items() if k == 'pull'), key=lambda x: int(x.split('/')[1].split('(')[0]))
if head:
    verdict('W-53', 'REPRO', f'公開リポジトリの今のファイルに教職員の氏名が {len(head)} 箇所')
else:
    verdict('W-53', 'FIXED', '公開リポジトリの今のファイルには教職員の氏名が無い')
if branch_hits:
    verdict('W-53h', 'REPRO', f'ブランチの履歴に残っている: {", ".join(branch_hits)}')
else:
    verdict('W-53h', 'FIXED', 'どのブランチの履歴にも無い(書き換え済み)')
if pull_hits:
    verdict('W-53pr', 'REPRO', f'PR の参照(持ち主には消せない)に残っている: {", ".join(pull_hits)}。GitHub Support に消してもらう必要がある')
else:
    verdict('W-53pr', 'FIXED', 'PR の参照にも無い')
