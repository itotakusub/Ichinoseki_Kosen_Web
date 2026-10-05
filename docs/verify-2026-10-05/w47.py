# W-47: 公開リポジトリに、私用・文書用以外の IPv4 アドレス(運用者の回線など)が出ているか。値は伏せて出す
#
# 9/25 版は、問題の行番号を決め打ちし、push 前の検査の正規表現を Python に写して当てていた
# (PowerShell の -match は大文字小文字を区別しないが、1 つの式にしか反映していなかった)。
# この版は形で IPv4 を探し、今のファイル(HEAD)と全履歴を別々に判定する。push 前の検査そのものは push_scan.sh で本物を動かす。
import ipaddress, pathlib, re, subprocess, sys
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from verdict import verdict, die

repo = pathlib.Path(__file__).resolve().parents[2]
allow = (repo / 'server/nginx/km/allow-admin.conf').read_text(encoding='utf-8')
allowed_hosts = {ipaddress.ip_address(a) for a in re.findall(r'^\s*allow\s+([0-9.]+)\s*;', allow, re.M)}
skip_nets = [ipaddress.ip_network(n) for n in ('0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
             '172.16.0.0/12', '192.168.0.0/16', '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3')]
# push-github.ps1 の Test-KmPublicIpv4 と同じ除外(公開 DNS と説明の例)
skip_ips = {ipaddress.ip_address(a) for a in ('1.1.1.1', '1.0.0.1', '8.8.8.8', '8.8.4.4', '9.9.9.9', '149.112.112.112', '1.2.3.4')}
SKIP_FILE = re.compile(r'(^|/)([^/]*\.lock|package-lock\.json|gradle\.lockfile)$|\.(png|jpe?g|gif|webp|ico|pdf|zip|jar|apk|woff2?|ttf)$')
pat = re.compile(r'(?<![\w./-])(\d{1,3}(?:\.\d{1,3}){3})(?![\w.-]*[A-Za-z])(?![\d.])')

def public_ips(text):
    for m in pat.finditer(text):
        try:
            ip = ipaddress.ip_address(m.group(1))
        except ValueError:
            continue
        if ip in allowed_hosts or ip in skip_ips or any(ip in n for n in skip_nets):
            continue
        yield str(ip)

def mask(ip):
    return ip.split('.')[0] + '.*.*.*'

files = subprocess.run(['git', '-C', str(repo), 'ls-files'], capture_output=True, text=True, check=True).stdout.split('\n')
head_hits = []
for f in filter(None, files):
    if SKIP_FILE.search(f) or 'jdk' in f:
        continue
    p = repo / f
    try:
        text = p.read_text(encoding='utf-8')
    except (UnicodeDecodeError, FileNotFoundError, IsADirectoryError):
        continue
    for no, line in enumerate(text.splitlines(), 1):
        if 'jdk' in line.lower():
            continue
        for ip in public_ips(line):
            head_hits.append(f'{f}:{no}  {mask(ip)}')
print(f'今のファイル(HEAD): {len(head_hits)} 件')
for h in head_hits[:20]:
    print('  ' + h)
# 履歴: 公開されている参照ごとに数える(public_refs.py。`--all` は手元の古い参照まで見るので使わない)
from public_refs import public_refs, added_lines
try:
    refs = public_refs(repo)
except Exception as exc:
    die('W-47h', f'公開されている参照を取り寄せられません: {exc}')
if not any(kind == 'branch' and label.endswith('/main') for label, _, kind in refs):
    die('W-47h', '対照: 取り寄せた参照に main が無い')
hist = {}   # ラベル → 伏せた IP の集合
for label, ref, kind in refs:
    found = set()
    for path, line in added_lines(repo, ref):
        if SKIP_FILE.search(path) or 'jdk' in line.lower():
            continue
        found |= {mask(ip) for ip in public_ips(line)}
    print(f'  {label:<16} 公開の IPv4: {", ".join(sorted(found)) or "無し"}')
    if found:
        hist[(label, kind)] = found
branch_hits = sorted(l for (l, k) in hist if k == 'branch')
pull_hits = sorted(l for (l, k) in hist if k == 'pull')
if head_hits:
    verdict('W-47', 'REPRO', f'今のファイルに公開の IPv4 が {len(head_hits)} 件')
else:
    verdict('W-47', 'FIXED', '今のファイルには公開の IPv4 が無い')
if branch_hits:
    verdict('W-47h', 'REPRO', f'ブランチの履歴に残っている({", ".join(branch_hits)})')
else:
    verdict('W-47h', 'FIXED', 'どのブランチの履歴にも無い(書き換え済み)')
if pull_hits:
    verdict('W-47pr', 'REPRO', f'PR の参照(持ち主には消せない)に残っている: {", ".join(pull_hits)}。GitHub Support に消してもらう必要がある')
else:
    verdict('W-47pr', 'FIXED', 'PR の参照にも無い')
