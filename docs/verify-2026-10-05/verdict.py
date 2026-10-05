# 判定の共通部品(lib.sh の verdict / die と同じ形で results/summary.tsv に書く)
import pathlib, sys
SUMMARY = pathlib.Path(__file__).resolve().parent / 'results' / 'summary.tsv'
LABEL = {'REPRO': '成り立つ', 'FIXED': '成り立たない'}

def _one(text):
    return ' '.join(str(text).split())

def verdict(fid, status, reason):
    if status not in LABEL:
        die(fid, f'判定の値が不正です: {status}')
    with SUMMARY.open('a', encoding='utf-8') as f:
        f.write(f'{fid}\t{status}\t{_one(reason)}\n')
    print(f'[判定] {fid}: {LABEL[status]} —— {reason}')

def die(fid, reason):
    with SUMMARY.open('a', encoding='utf-8') as f:
        f.write(f'{fid}\tERROR\t{_one(reason)}\n')
    print(f'[検証の失敗] {fid}: {reason}', file=sys.stderr)
    sys.exit(2)
