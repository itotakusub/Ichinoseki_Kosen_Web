<#
.SYNOPSIS
  手元の鍵 KM_PROJ_PASSKEY(server/docs/.env)を、本番の /opt/kosenmap/.env へ写す(2026-11-07)。**値は画面に出さない。**

.DESCRIPTION
  利用者の指示「API は docs の .env の KM_PROJ_PASSKEY= のパスワードを使用しないとアクセスできないように。今後もこの ENV を使用して便利化していきたい」。
  正本は手元の server/docs/.env(配備にも GitHub の控えにも載らない)。本番の web は compose.yaml の KM_PROJ_PASSKEY で受け取る(lib/proj-passkey.php)。

  やること:
    1. docs/.env から KM_PROJ_PASSKEY を読む(32 字以上・空白なし・compose の .env で崩れる $ ' " \ ` # を含まない)
    2. kmops で本番へ繋ぎ、/opt/kosenmap/.env の KM_PROJ_PASSKEY= の行だけを差し替える(無ければ足す)。
       **鍵はコマンドの引数に載せない**(相手の ps に出る)。標準入力で渡す。
       書く前の .env は同じ場所に .env.before-proj-passkey-<日時>(600)として残す(消さない)
    3. 写したものを sha256 で突き合わせる(出すのは「同じ / 違う」だけ)
    4. -Restart のときだけ web を入れ直す(docker compose up -d web)。付けなければ、次の配備(-Action up)で効く

  同じ値がもう入っていれば、何も書かない。

.PARAMETER EnvPath
  手元の鍵のファイル(既定 server/docs/.env)。

.PARAMETER Restart
  写したあと web を入れ直して、すぐ効かせる。

.PARAMETER WhatIf
  手元の鍵を確かめ、本番の行が同じかを見るだけ(書かない)。

.EXAMPLE
  pwsh -File scripts\proj-passkey-sync.ps1 -WhatIf
.EXAMPLE
  pwsh -File scripts\proj-passkey-sync.ps1 -Restart
#>
[CmdletBinding()]
param(
    [string]$EnvPath = '',
    [string]$HostName = 'ito4.jp',
    # 繋ぐ利用者(docker あり・sudo なし。.env の持ち主)
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    [string]$RemotePath = '/opt/kosenmap',
    [switch]$Restart,
    [switch]$WhatIf,
    # オプションとその意味を出して終わる
    [switch]$Help
)

# -Help: オプションとその意味を出して終わる(説明はこのファイルの先頭のコメントから km-help.ps1 が読む)
if ($Help) {
    $kmHelp = Join-Path $PSScriptRoot 'km-help.ps1'
    if (Test-Path $kmHelp) { & $kmHelp -Path $PSCommandPath } else { Get-Help $PSCommandPath -Detailed }
    exit 0
}

$ErrorActionPreference = 'Stop'

if ($EnvPath -eq '') { $EnvPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'docs\.env' }
if (-not (Test-Path -LiteralPath $EnvPath)) { throw "鍵のファイルがありません: $EnvPath" }
if ($RemotePath -notmatch '^/[A-Za-z0-9._/-]+$') { throw "RemotePath が不正です: $RemotePath" }

# ---- 1. 手元の鍵を読む(値は変数の中だけ。出さない)
$raw = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $EnvPath).Path)
$found = [regex]::Matches($raw, '(?m)^[ \t]*KM_PROJ_PASSKEY[ \t]*=[ \t]*(\S*)[ \t]*\r?$')
if ($found.Count -ne 1) { throw "$EnvPath に KM_PROJ_PASSKEY= の行が 1 つではありません($($found.Count) 行)。" }
$passkey = $found[0].Groups[1].Value
if ($passkey.Length -lt 32) { throw 'KM_PROJ_PASSKEY が 32 字より短いです(サーバーは短い鍵を受け付けません)。' }
if ($passkey -notmatch '^[\x21-\x7E]+$') { throw 'KM_PROJ_PASSKEY に英数字と記号(ASCII)以外が入っています。' }
if ($passkey -match '[$''"\\`#]') { throw 'KM_PROJ_PASSKEY に $ '' " \ ` # のどれかが入っています(compose の .env で崩れるので、替えてください)。' }
$localHash = [BitConverter]::ToString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::ASCII.GetBytes($passkey))).Replace('-', '').ToLowerInvariant()
Write-Host "手元の鍵: $($passkey.Length) 字(値は出しません)"

$sshArgs = @('-i', $KeyPath, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', "$User@$HostName")

# ---- 2. 本番の行を見る(ハッシュだけ)。引数の引用で崩れないよう、台本は標準入力で渡す
$probe = @"
f='$RemotePath/.env'
if [ ! -f "`$f" ]; then echo NOENV; exit 0; fi
v=`$(grep '^KM_PROJ_PASSKEY=' "`$f" | tail -n 1 | cut -d= -f2-)
if [ -z "`$v" ]; then echo NONE; else printf '%s' "`$v" | sha256sum | cut -d' ' -f1; fi
# 終わり(PowerShell が末尾に足す CR がこの注釈の行に付くように、最後は注釈にしておく)
"@
$remoteHash = ($probe.Replace("`r`n", "`n") | & ssh @sshArgs 'sh -s' 2>$null | Select-Object -Last 1)
if ($LASTEXITCODE -ne 0) { throw "本番に繋げませんでした(ssh の終了コード $LASTEXITCODE)。鍵 $KeyPath とパスフレーズを確かめてください。" }
if ($remoteHash -eq 'NOENV') { throw "本番に $RemotePath/.env がありません。秘密が入ったファイルなので、こちらからは作りません。" }

if ($remoteHash -eq $localHash) {
    Write-Host '本番の KM_PROJ_PASSKEY は手元と同じです(書きません)。' -ForegroundColor Green
} elseif ($WhatIf) {
    $state = if ($remoteHash -eq 'NONE') { 'まだありません' } else { '手元と違います' }
    Write-Host "本番の KM_PROJ_PASSKEY は$state。-WhatIf なので書きません。" -ForegroundColor Yellow
    exit 0
} else {
    # ---- 3. 差し替える。鍵はヒアドキュメント(引用付きの区切り = 展開しない)で標準入力に乗せる
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $hostScript = @"
set -eu
umask 077
f='$RemotePath/.env'
IFS= read -r NEWKEY <<'KM_PROJ_EOF'
$passkey
KM_PROJ_EOF
cp -p "`$f" "`$f.before-proj-passkey-$stamp"
chmod 600 "`$f.before-proj-passkey-$stamp"
tmp=`$(mktemp "$RemotePath/.env.proj-XXXXXX")
grep -v '^KM_PROJ_PASSKEY=' "`$f" > "`$tmp" || true
printf 'KM_PROJ_PASSKEY=%s\n' "`$NEWKEY" >> "`$tmp"
chmod 600 "`$tmp"
mv "`$tmp" "`$f"
v=`$(grep '^KM_PROJ_PASSKEY=' "`$f" | tail -n 1 | cut -d= -f2-)
printf '%s' "`$v" | sha256sum | cut -d' ' -f1
# 終わり(末尾の CR はこの注釈の行に付く)
"@
    $hostScript = $hostScript.Replace("`r`n", "`n")
    $written = ($hostScript | & ssh @sshArgs 'sh -s' 2>$null | Select-Object -Last 1)
    $passkey = $null
    $hostScript = $null
    if ($LASTEXITCODE -ne 0) { throw "本番の .env を書き換えられませんでした(ssh の終了コード $LASTEXITCODE)。" }
    if ($written -ne $localHash) { throw '書いたはずの鍵が手元と一致しません。本番の .env を確かめてください(前の .env は .env.before-proj-passkey-* に残してあります)。' }
    Write-Host "本番の .env に写し、読み直して手元と同じだと確かめました(前の .env は .env.before-proj-passkey-$stamp)。" -ForegroundColor Green
}

# ---- 4. すぐ効かせる
if ($Restart) {
    & ssh @sshArgs "cd '$RemotePath' && docker compose up -d web" 2>&1 | Where-Object { $_ -notmatch 'KM_PROJ_PASSKEY' } | ForEach-Object { "  $_" }
    if ($LASTEXITCODE -ne 0) { throw "web を入れ直せませんでした(終了コード $LASTEXITCODE)。" }
    Write-Host 'web を入れ直しました。' -ForegroundColor Green
} else {
    Write-Host '効くのは web を入れ直したあと(-Restart か、配備の -Action up)。'
}
