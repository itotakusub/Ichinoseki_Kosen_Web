"""
KosenMap の取扱説明書(docs/*.ipynb)から、手元の PowerShell 7 とホストのシェルを動かす道具。

ノートブックの最初のセルで読み込むと、次の3つのセルの型が使えるようになる。

    %%ps        手元の PowerShell 7 で実行する(作業場所は server/scripts)
    %%host      ホスト(既定 km@ito4.jp)の sh で実行する(作業場所は /opt/kosenmap)
    %%terminal  新しい PowerShell の窓を開いて実行する(sudo のパスワード入力など、対話が要るもの)

どれも1行目に選択肢を書ける:

    --confirm "文"   実行の前に yes の入力を求める。**本番を変えるセルには必ず付ける**
    --timeout 秒     それを過ぎたら止める(%%host の既定は 600 秒。%%ps は既定で無制限)

## なぜ PowerShell を直に呼ばず、ファイルに書いてから -File で呼ぶのか

`pwsh -Command -`(標準入力から読む)は1行ずつ解釈するので、
複数行にまたがる if やヒアドキュメントが途中で切れる。一時ファイルなら書いたとおりに動く。

## なぜ出力を UTF-8 に固定するのか

Windows の既定のコードページ(CP932)のままだと、ssh から戻る UTF-8 の日本語が化ける。
`[Console]::OutputEncoding` を UTF-8 にし、こちらも UTF-8 として読む。

## ホストへは標準入力で流す

引数として渡すと、引用符やリダイレクトを Windows 側と向こうのシェルが奪い合う
(scripts/km-ssh.ps1 の Invoke-KmSshStdin と同じ理由)。CR もここで落とす。

設定は環境変数で変えられる: KM_HOST / KM_USER / KM_KEY / KM_REMOTE_PATH
"""

from __future__ import annotations

import argparse
import datetime
import os
import pathlib
import shlex
import shutil
import subprocess
import sys
import tempfile
import threading
import time

DOCS = pathlib.Path(__file__).resolve().parent
SERVER = DOCS.parent
SCRIPTS = SERVER / 'scripts'

HOST = os.environ.get('KM_HOST', 'ito4.jp')
# %%host は置き場の持ち主(docker あり・sudo なし)で繋ぐ。2026-09-18 に km から kmops へ移した(docs/12 §7-4 B 段 2)。
# sudo が要るセルは %%terminal の中で km と km_vps を直に書く
USER = os.environ.get('KM_USER', 'kmops')
KEY = os.environ.get('KM_KEY', str(pathlib.Path.home() / '.ssh' / 'km_ops'))
REMOTE = os.environ.get('KM_REMOTE_PATH', '/opt/kosenmap')
PWSH = shutil.which('pwsh') or r'C:\Program Files\PowerShell\7\pwsh.exe'

# 一時ファイルの置き場。**秘密は書かない**(セルに書いたコマンドだけ)
WORK = pathlib.Path(tempfile.gettempdir()) / 'kosenmap-notebook'

# 窓を出さずに子プロセスを作る。ノートブックの裏の Python には画面が無いので、
# 付けないと実行のたびに黒い窓が一瞬開く
_NO_WINDOW = getattr(subprocess, 'CREATE_NO_WINDOW', 0)
_NEW_CONSOLE = getattr(subprocess, 'CREATE_NEW_CONSOLE', 0)

_PS_PREFIX = """\
$ErrorActionPreference = 'Continue'
try { [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false) } catch { }
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
try { $PSStyle.OutputRendering = 'PlainText' } catch { }
Set-Location -LiteralPath '__KM_CWD__'
"""


def ssh_args(tty: bool = False) -> list[str]:
    """ssh の共通の引数。scripts/km-ssh.ps1 の Get-KmSshArgs と揃える。"""
    args = ['ssh']
    if tty:
        args.append('-t')
    args += ['-i', KEY, '-o', 'ConnectTimeout=10', '-o', 'IdentitiesOnly=yes',
             '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4']
    if not tty:
        # 対話できない場で聞かれると、黙って止まる。聞かれる状況なら失敗させる
        args += ['-o', 'BatchMode=yes']
    return args + [f'{USER}@{HOST}']


def _kill_tree(proc: subprocess.Popen) -> None:
    if proc.poll() is None:
        subprocess.run(['taskkill', '/PID', str(proc.pid), '/T', '/F'],
                       capture_output=True, creationflags=_NO_WINDOW)


def _run(cmd: list[str], stdin_bytes: bytes | None = None, cwd: str | None = None,
         timeout: float | None = None) -> int | None:
    """実行して、出力を1行ずつそのまま流す。終わったら終了コードを表示して返す。"""
    started = time.monotonic()
    proc = subprocess.Popen(
        cmd,
        stdin=subprocess.PIPE if stdin_bytes is not None else subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        cwd=cwd,
        creationflags=_NO_WINDOW,
    )

    if stdin_bytes is not None:
        def feed() -> None:
            try:
                proc.stdin.write(stdin_bytes)
                proc.stdin.close()
            except OSError:
                pass
        threading.Thread(target=feed, daemon=True).start()

    timed_out = threading.Event()
    timer = None
    if timeout:
        def expire() -> None:
            timed_out.set()
            _kill_tree(proc)
        timer = threading.Timer(timeout, expire)
        timer.start()

    try:
        for raw in iter(proc.stdout.readline, b''):
            sys.stdout.write(raw.decode('utf-8', errors='replace').replace('\r\n', '\n'))
            sys.stdout.flush()
        code = proc.wait()
    except KeyboardInterrupt:
        # ノートブックの「中断」。**止めずに戻ると、裏で走り続ける**
        _kill_tree(proc)
        proc.wait()
        print('\n■ 中断しました(実行中のプロセスを止めました。ホスト側では処理が残っているかもしれません)。')
        return None
    finally:
        if timer:
            timer.cancel()

    elapsed = time.monotonic() - started
    if timed_out.is_set():
        print(f'\n✖ {timeout:g} 秒で終わらなかったので止めました。ホスト側では処理が残っているかもしれません。')
        return None
    mark = '✔' if code == 0 else '✖'
    print(f'\n{mark} 終了コード {code}({elapsed:.1f} 秒)')
    return code


def _confirm(text: str | None) -> bool:
    if not text:
        return True
    print(f'⚠ {text}')
    answer = input('続けるなら yes と入力してください: ')
    if answer.strip().lower() != 'yes':
        print('中断しました(何も実行していません)。')
        return False
    return True


def _parse(line: str) -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog='%%ps / %%host / %%terminal', add_help=False)
    parser.add_argument('--confirm', default=None)
    parser.add_argument('--timeout', type=float, default=None)
    return parser.parse_args(shlex.split(line))


def _write_ps1(body: str) -> pathlib.Path:
    WORK.mkdir(parents=True, exist_ok=True)
    # 1日より古い一時ファイルを片付ける(別の窓で開いたものは、窓が使い終わるまで残す)
    limit = time.time() - 86400
    for old in WORK.glob('cell-*.ps1'):
        try:
            if old.stat().st_mtime < limit:
                old.unlink()
        except OSError:
            pass
    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S-%f')
    path = WORK / f'cell-{stamp}.ps1'
    text = _PS_PREFIX.replace('__KM_CWD__', str(SCRIPTS).replace("'", "''")) + body + '\n'
    # **BOM を付ける。** 付けないと Windows PowerShell 系は日本語を CP932 として読む
    path.write_text(text, encoding='utf-8-sig')
    return path


def _ps_launcher(path: pathlib.Path) -> str:
    """
    **ファイルを読む前に** UTF-8 と色なしを決めてから、そのファイルを呼ぶ。

    `-File` で直に渡すと、セルに構文の誤りがあったとき**ファイルの1行目も実行されずに**
    エラーが出る。ファイルの先頭で決めている UTF-8 が効かないので、エラー文が
    CP932 のまま出て文字化けし、色の制御文字も混ざる(2026-09-14 に実際に起きた)。
    外側で先に決めておけば、構文エラーも読める形で出る。
    """
    quoted = str(path).replace("'", "''")
    return (
        "try { [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false) } catch { }; "
        "$OutputEncoding = [System.Text.UTF8Encoding]::new($false); "
        "try { $PSStyle.OutputRendering = 'PlainText' } catch { }; "
        f"try {{ & '{quoted}' }} catch {{ Write-Host ($_ | Out-String); exit 1 }}; "
        "if ($LASTEXITCODE) { exit $LASTEXITCODE }"
    )


def run_ps(line: str, cell: str) -> int | None:
    opts = _parse(line)
    if not _confirm(opts.confirm):
        return None
    path = _write_ps1(cell)
    try:
        return _run([PWSH, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                     '-Command', _ps_launcher(path)],
                    cwd=str(SCRIPTS), timeout=opts.timeout)
    finally:
        try:
            path.unlink()
        except OSError:
            pass


def run_host(line: str, cell: str) -> int | None:
    opts = _parse(line)
    if not _confirm(opts.confirm):
        return None
    remote = REMOTE.replace("'", "'\\''")
    script = f"cd '{remote}' || exit 1\n" + cell.replace('\r\n', '\n').replace('\r', '\n') + '\n'
    return _run(ssh_args() + ['sh'], stdin_bytes=script.encode('utf-8'),
                timeout=opts.timeout if opts.timeout is not None else 600)


def run_terminal(line: str, cell: str) -> None:
    opts = _parse(line)
    if not _confirm(opts.confirm):
        return
    body = cell + "\nWrite-Host ''\nWrite-Host '終わりました。この窓は閉じて構いません。' -ForegroundColor Green\n"
    path = _write_ps1(body)
    subprocess.Popen([PWSH, '-NoExit', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', str(path)],
                     cwd=str(SCRIPTS), creationflags=_NEW_CONSOLE)
    print('別の PowerShell の窓で開きました。パスワードなどはそちらで入力してください。')


def info() -> None:
    print(f'手元のスクリプト : {SCRIPTS}')
    print(f'PowerShell       : {PWSH}')
    print(f'ホスト           : {USER}@{HOST}:{REMOTE}')
    print(f'SSH の鍵         : {KEY}{"" if pathlib.Path(KEY).exists() else "  ★ 見つかりません"}')


def load() -> None:
    """%%ps / %%host / %%terminal を登録する。ノートブックの最初のセルで呼ぶ。"""
    from IPython import get_ipython

    ip = get_ipython()
    if ip is None:
        raise RuntimeError('ノートブック(IPython)の中から呼んでください。')
    # **値を返さない。** 返すとノートブックが `Out[n]: 0` と重ねて表示する(終了コードは既に出している)
    def ps(line: str, cell: str) -> None:
        run_ps(line, cell)

    def host(line: str, cell: str) -> None:
        run_host(line, cell)

    ip.register_magic_function(ps, magic_kind='cell', magic_name='ps')
    ip.register_magic_function(host, magic_kind='cell', magic_name='host')
    ip.register_magic_function(run_terminal, magic_kind='cell', magic_name='terminal')
    info()
    print('使えるセル: %%ps(手元) / %%host(ホスト) / %%terminal(別の窓)')
