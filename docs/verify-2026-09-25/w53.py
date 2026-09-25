# W-53: 非公開リポジトリの DB ダンプにある「部屋 → 教職員氏名」の組が、公開リポジトリに出ているか。氏名は伏せて出す
import re, subprocess, collections
import pathlib
HERE = pathlib.Path(__file__).resolve()
sql = (HERE.parents[3] / 'Ichinoseki_Kosen/php/Kosen_map.sql').read_text(encoding='utf-8')
body = re.search(r"INSERT INTO `km_map_nodes`.*?VALUES\n(.*?);\n", sql, re.S).group(1)
rows = re.findall(r"\('([^']*)', '([^']*)', '([^']*)', (NULL|'[^']*'), '([^']*)'", body)
pairs = [(nid, room, occ.strip("'")) for nid, _, room, occ, _ in rows if occ != 'NULL' and occ.strip("'")]
names = sorted({p[2] for p in pairs})
print(f'ダンプ: 地点 {len(rows)} 件、氏名のある地点 {len(pairs)} 件、異なる氏名 {len(names)} 人')
pub = str(HERE.parents[2])
found = collections.defaultdict(list)
for i, n in enumerate(names):
    for line in subprocess.run(['git', '-C', pub, 'grep', '-nF', n, 'HEAD', '--'], capture_output=True, text=True).stdout.splitlines():
        _, path, no, text = line.split(':', 3)
        rooms = [r for _, r, o in pairs if o == n]
        same_room = any(r.split()[-1] in text for r in rooms)
        found[i].append((path, no, same_room))
print(f'公開リポジトリ(HEAD)に出る氏名: {len(found)} 人')
for i, hits in found.items():
    for path, no, same_room in hits:
        print(f'  氏名#{i}  {path}:{no}  同じ行に、ダンプ上の担当部屋の番号も{"ある" if same_room else "無い"}')
log = subprocess.run(['git', '-C', pub, 'log', '--all', '-p', '--no-color'], capture_output=True, text=True).stdout
print(f'公開リポジトリの全履歴に出る氏名: {sum(1 for n in names if n in log)} 人')
