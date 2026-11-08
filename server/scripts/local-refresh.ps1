<#
.SYNOPSIS
  検証機の VM(既定 192.168.3.31)を、本番の最新の控えから「機密なしのコピー」に入れ替える。PC で流す。

.DESCRIPTION
  段 5(plan.md 5-2・5-3・5-5)。docs/13 §4-3。

    .\local-refresh.ps1                    控えを開く → MariaDB を検証機へ流し込む(使い捨ての DB で抜いてから)
                                           → ダミーのファイル → web を起こす → 本番の秘密と一致しないかを確かめる
    .\local-refresh.ps1 -SeedLogto         上に加えて、空の Logto を本番の形に組む(初回だけ。Console の m2m が先)
    .\local-refresh.ps1 -CopyPublicImages  上に加えて、本番の公開の画像(色分けの絵・階の画像)を写す(本番は読むだけ)
    .\local-refresh.ps1 -CheckOnly         何も入れ替えず、本番の秘密との突き合わせと抜き残しの数えだけ
    .\local-refresh.ps1 -Opened D:\Backups\_opened\20261107-...   開いた控えを指定する(開かない・消さない)

  ## 守っていること

  - **検証機のほかへは流さない**: 本番の名前・公開のアドレスなら止まる。相手に /etc/kosenmap-test-host と KM_ENV=local が無ければ止まる
  - **生のダンプを検証機の円盤に置かない**: SSH の標準入力で local-import.sh へ流し、使い捨ての MariaDB(tmpfs)で抜いてから入れる
  - **本番の秘密と 1 つも一致しない**ことを、ハッシュどうしで比べる(値は画面に出さない)。比べる相手は、開いた控えの
    env.txt と config/*.local.php、この PC の server/docs/.env
  - この台本が開いた控え(平文)は、終わったら消す(暗号化された控えは D:\Backups に残っている。open-backup.ps1 の既定と同じ考え)

  保存は BOM 付き UTF-8・LF。
#>
[CmdletBinding()]
param(
    # 検証機の VM の IP(192.168.3.30 は別の機械が使っていた。2026-11-07 に空きを確かめた .31 を勧めた)
    [string]$HostName = '192.168.3.31',
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_test30_ops",
    [string]$RemotePath = '/opt/kosenmap',
    [string]$Opened = '',
    [switch]$SeedLogto,
    [switch]$CopyPublicImages,
    [switch]$CheckOnly,
    # 本番から公開の画像を読むとき(読むだけ)
    [string]$ProdHost = 'ito4.jp',
    [string]$ProdUser = 'kmops',
    [string]$ProdKeyPath = "$env:USERPROFILE\.ssh\km_ops",
    # オプションとその意味を出して終わる
    [switch]$Help
)

# -Help: オプションとその意味を出して終わる(2026-10-16。説明はこのファイルの先頭のコメントから km-help.ps1 が読む)
if ($Help) {
    $kmHelp = Join-Path $PSScriptRoot 'km-help.ps1'
    if (Test-Path $kmHelp) { & $kmHelp -Path $PSCommandPath } else { Get-Help $PSCommandPath -Detailed }
    exit 0
}

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0

$ServerDir = Split-Path -Parent $PSScriptRoot
$ProdDomains = @('ito4.jp', 'ito8795.com')

function Say([string]$m) { Write-Host $m }
function Head([string]$m) { Write-Host ''; Write-Host "== $m" }

# ---------------------------------------------------------------- 相手を確かめる
foreach ($p in $ProdDomains) {
    if ($HostName -eq $p -or $HostName.EndsWith(".$p")) { throw "本番の名前($HostName)には流しません。" }
}
$addr = $null
if ([Net.IPAddress]::TryParse($HostName, [ref]$addr)) {
    $b = $addr.GetAddressBytes()
    $private = ($b[0] -eq 10) -or ($b[0] -eq 192 -and $b[1] -eq 168) -or ($b[0] -eq 172 -and $b[1] -ge 16 -and $b[1] -le 31)
    if (-not $private) { throw "$HostName は LAN のアドレスではありません。" }
} elseif ($HostName -notmatch '^192-168-\d{1,3}-\d{1,3}\.sslip\.io$' -and $HostName -notmatch '\.test$') {
    throw "$HostName は検証機の名前の形(LAN の IP・192-168-…sslip.io・….test)ではありません。"
}
if (-not (Test-Path $KeyPath)) { throw "鍵がありません: $KeyPath" }

$SshBase = @('-i', $KeyPath, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', "$User@$HostName")

function Invoke-Vm([string]$Command) {
    & ssh @SshBase "cd $RemotePath && $Command"
    if ($LASTEXITCODE -ne 0) { throw "検証機で失敗しました(終了コード $LASTEXITCODE): $Command" }
}

# ファイルをそのまま(バイトのまま)SSH の標準入力へ流す。PowerShell の文字のパイプを通さない
function Send-FileToVm([string]$Path, [string]$Command) {
    $psi = New-Object Diagnostics.ProcessStartInfo
    $psi.FileName = (Get-Command ssh).Source
    foreach ($a in $SshBase) { $psi.ArgumentList.Add($a) }
    $psi.ArgumentList.Add("cd $RemotePath && $Command")
    $psi.UseShellExecute = $false
    $psi.RedirectStandardInput = $true
    $proc = [Diagnostics.Process]::Start($psi)
    $in = [IO.File]::OpenRead($Path)
    try { $in.CopyTo($proc.StandardInput.BaseStream) } finally { $in.Dispose(); $proc.StandardInput.Close() }
    $proc.WaitForExit()
    if ($proc.ExitCode -ne 0) { throw "検証機で失敗しました(終了コード $($proc.ExitCode)): $Command" }
}

Head "1. 相手は検証機か($User@$HostName)"
$probe = & ssh @SshBase "test -f /etc/kosenmap-test-host && cd $RemotePath && grep -qx 'KM_ENV=local' .env && sed -n 's/^KM_DOMAIN=//p' .env | tail -n 1"
if ($LASTEXITCODE -ne 0 -or -not $probe) { throw '相手に /etc/kosenmap-test-host か KM_ENV=local がありません(検証機ではない・host-local.sh init の前)。' }
$VmDomain = ([string]$probe).Trim()
foreach ($p in $ProdDomains) { if ($VmDomain -eq $p -or $VmDomain.EndsWith(".$p")) { throw "検証機の KM_DOMAIN が本番の名前です($VmDomain)。" } }
Say "  OK 検証機です(KM_DOMAIN=$VmDomain)"

# ---------------------------------------------------------------- 控えを開く
$createdOpened = $false
if (-not $CheckOnly -or -not $Opened) {
    if ($Opened) {
        if (-not (Test-Path (Join-Path $Opened 'env.txt'))) { throw "$Opened に env.txt がありません(開いた控えではない)。" }
    } else {
        Head '2. 一番新しい控えを開く(この台本が終わったら消す)'
        $root = 'D:\Backups'
        $Opened = Join-Path $root ('_opened\refresh-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
        & pwsh -NoProfile -File (Join-Path $PSScriptRoot 'open-backup.ps1') -Keep -Destination $Opened
        if ($LASTEXITCODE -ne 0) { throw '控えを開けませんでした。' }
        $createdOpened = $true
    }
}

try {
    if (-not $CheckOnly) {
        $dump = Join-Path $Opened 'km-mariadb.sql'
        if (-not (Test-Path $dump)) { throw "$dump がありません。" }
        Head "3. MariaDB を流し込む($([math]::Round((Get-Item $dump).Length / 1MB, 1))MB。使い捨ての DB で抜いてから入れる)"
        Send-FileToVm -Path $dump -Command 'sh scripts/local-import.sh'

        Head '4. web を起こし、ダミーのファイルを作る'
        Invoke-Vm 'docker compose up -d web >/dev/null && sleep 5 && docker compose exec -T -u www-data web php scripts/local-dummy-files.php --apply'

        if ($CopyPublicImages) {
            Head "4-2. 本番の公開の画像を写す(本番 $ProdHost は読むだけ)"
            $prodCmd = "cd /opt/kosenmap/src/uploads && tar cf - area_*.png floor* 2>/dev/null"
            # uploads/ は www-data の持ち物なので、web の中で www-data として展開する
            $vmCmd = "cd $RemotePath && docker compose exec -T -u www-data web sh -c 'cd /var/www/html/uploads && tar xf - && ls area_*.png 2>/dev/null | wc -l'"
            # バイトのまま渡すため cmd のパイプを使う(引数は固定の文字だけ)
            $line = "ssh -i `"$ProdKeyPath`" -o IdentitiesOnly=yes -o BatchMode=yes $ProdUser@$ProdHost `"$prodCmd`" | ssh -i `"$KeyPath`" -o IdentitiesOnly=yes -o BatchMode=yes $User@$HostName `"$vmCmd`""
            & cmd.exe /d /c $line
            if ($LASTEXITCODE -ne 0) { Say '  ★ 写せませんでした(本番に入れない・画像が無い)。色分けはアプリから送り直してもよい' }
        }

        if ($SeedLogto) {
            Head '5. Logto を本番の形に組む(初回)'
            $tpl = Join-Path $ServerDir 'docs\logto-email-templates.json'
            Send-FileToVm -Path $tpl -Command 'sh scripts/local-logto-seed.sh --apply'
        }
    }

    Head '6. 抜き残しを数える(検証機の DB)'
    Invoke-Vm 'sh scripts/local-sanitize.sh --check'

    Head '7. 本番の秘密と一致しないか(ハッシュどうし。値は出さない)'
    $sha = [Security.Cryptography.SHA256]::Create()
    $hash = { param([string]$v) [BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($v))) }
    $prod = @{}
    $addValues = {
        param([string]$Text, [string]$Origin)
        foreach ($ln in ($Text -split "`n")) {
            $m = [regex]::Match($ln, '^\s*([A-Z0-9_]+)\s*=\s*"?([^"\r]*)"?\s*$')
            if ($m.Success -and $m.Groups[2].Value.Length -ge 8) { $prod[(& $hash $m.Groups[2].Value)] = "$Origin $($m.Groups[1].Value)" }
            foreach ($q in [regex]::Matches($ln, "'([^']{12,})'")) { $prod[(& $hash $q.Groups[1].Value)] = "$Origin(config の文字列)" }
        }
    }
    $sources = 0
    if ($Opened -and (Test-Path (Join-Path $Opened 'env.txt'))) { & $addValues ([IO.File]::ReadAllText((Join-Path $Opened 'env.txt'))) '本番 .env'; $sources++ }
    $cfgTar = if ($Opened) { Join-Path $Opened 'km-config.tar.gz' } else { '' }
    if ($cfgTar -and (Test-Path $cfgTar)) {
        $tmp = Join-Path ([IO.Path]::GetTempPath()) ('km-cfg-' + [Guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory $tmp | Out-Null
        try {
            & tar.exe -xzf $cfgTar -C $tmp
            Get-ChildItem $tmp -Recurse -File -Filter '*.local.php' | ForEach-Object { & $addValues ([IO.File]::ReadAllText($_.FullName)) "本番 $($_.Name)" }
            $sources++
        } finally { Remove-Item -Recurse -Force $tmp }
    }
    $docsEnv = Join-Path $ServerDir 'docs\.env'
    if (Test-Path $docsEnv) { & $addValues ([IO.File]::ReadAllText($docsEnv)) 'PC の docs/.env'; $sources++ }
    if ($sources -eq 0) { Say '  ★ 比べる相手(開いた控え・docs/.env)がありません。-Opened を指定する' }
    else {
        $vmText = (& ssh @SshBase "cd $RemotePath && cat .env config/*.local.php 2>/dev/null") -join "`n"
        $hits = New-Object Collections.Generic.List[string]
        foreach ($ln in ($vmText -split "`n")) {
            $cands = @()
            $m = [regex]::Match($ln, '^\s*([A-Z0-9_]+)\s*=\s*"?([^"\r]*)"?\s*$')
            if ($m.Success -and $m.Groups[2].Value.Length -ge 8) { $cands += , @($m.Groups[1].Value, $m.Groups[2].Value) }
            foreach ($q in [regex]::Matches($ln, "'([^']{12,})'")) { $cands += , @('config', $q.Groups[1].Value) }
            foreach ($c in $cands) {
                $h = & $hash $c[1]
                if ($prod.ContainsKey($h)) { $hits.Add("検証機の $($c[0]) = $($prod[$h])") }
            }
        }
        # 名前・既定値(kosenmap・logto・local など)は秘密ではないので、8 文字未満は比べていない。
        # 一致したのが秘密でない設定(名前・URL)なら、表示のキーを見て判断する
        $secretHits = @($hits | Where-Object { $_ -match 'PASSWORD|SECRET|KEY|TOKEN|PASSKEY|config の文字列' })
        if ($secretHits.Count -eq 0) {
            Say "  OK 本番の秘密と一致するものは 0(比べた本番の値 $($prod.Count) 個・秘密でない一致 $($hits.Count) 個)"
        } else {
            $secretHits | ForEach-Object { Say "  ★ $_" }
            throw "本番の秘密と一致するものが $($secretHits.Count) 個あります。検証機の .env / config を作り直す(host-local.sh init --fresh-secrets)。"
        }
    }
} finally {
    if ($createdOpened -and (Test-Path $Opened)) {
        Remove-Item -Recurse -Force $Opened
        Say ''
        Say "  開いた控え(平文)を消しました: $Opened"
    }
}

Say ''
Say "済みました。https://$VmDomain/ を開いて確かめる(docs/13 §10)。"
