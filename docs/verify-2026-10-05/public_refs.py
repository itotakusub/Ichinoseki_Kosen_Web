"""公開リポジトリで**誰でも取れる参照**を、検証用の名前空間(refs/km-verify/)へ取り寄せて並べる。

`git log --all` は手元の clone の全ての参照(古いリモート追跡ブランチや、書き換え前の履歴を指す参照)まで見るので、
「公開リポジトリのどこに残っているか」を言えない(2026-10-05 に、main は綺麗なのに「履歴に残る」と出た)。
ここでは GitHub が公開している参照だけを、種類ごとに分けて返す:
  ブランチ(refs/heads/*)… 持ち主が消せる・書き換えられる
  PR の参照(refs/pull/*/head)… **持ち主には消せない**(GitHub Support に頼む)
"""
import subprocess


def public_refs(repo):
    """[(ラベル, 参照名, 種類)] を返す。取り寄せに失敗したら例外"""
    subprocess.run(['git', '-C', str(repo), 'fetch', '-q', 'origin',
                    '+refs/heads/*:refs/km-verify/heads/*', '+refs/pull/*/head:refs/km-verify/pull/*'],
                   check=True, capture_output=True, text=True)
    out = subprocess.run(['git', '-C', str(repo), 'for-each-ref', '--format=%(refname)', 'refs/km-verify/'],
                         check=True, capture_output=True, text=True).stdout.split()
    refs = []
    for ref in out:
        rest = ref[len('refs/km-verify/'):]
        kind = 'pull' if rest.startswith('pull/') else 'branch'
        refs.append((rest, ref, kind))
    key = lambda r: (r[2] != 'branch', int(r[0].split('/')[1]) if r[2] == 'pull' else 0, r[0])
    return sorted(refs, key=key)


def added_lines(repo, ref):
    """その参照から辿れる全コミットで足された行(ファイル名つき)"""
    log = subprocess.run(['git', '-C', str(repo), 'log', ref, '-p', '--no-color', '--format=commit %h'],
                         check=True, capture_output=True, text=True, errors='ignore').stdout
    current = ''
    for line in log.splitlines():
        if line.startswith('+++ '):
            current = line[6:] if line.startswith('+++ b/') else line[4:]
        elif line.startswith('+') and not line.startswith('+++'):
            yield current, line
