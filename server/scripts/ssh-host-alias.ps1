<#
.SYNOPSIS
  ito4.jp を Cloudflare の中継(橙)にしても、この PC の SSH が本番へ届くようにする(~\.ssh\config に 1 つの段を足す)。

.DESCRIPTION
  2026-11-08、利用者「ito4.jp を Cloudflare に移行します」。docs/17 §1・§3。

    .\ssh-host-alias.ps1                 いまの様子を見るだけ(何も変えない)
    .\ssh-host-alias.ps1 -Apply          ~\.ssh\config に段を足す(前のファイルは ~\.ssh\Old\ へ写す)
    .\ssh-host-alias.ps1 -Undo           足した段を外す(同じく写してから)
    .\ssh-host-alias.ps1 -Test           kmops の鍵で本番へ 1 回繋いで確かめる(読むだけ。echo を返させる)
    .\ssh-host-alias.ps1 -SshName <名前>  灰の名前を変える(既定 ssh.ito4.jp)

  ## なぜ要るのか

  配備・控え(毎週日曜 3:00 のタスク)・コンテナの更新・取扱説明書の %%host・デスクトップの「トンネル開通SSH.cmd」は、
  **どれも `kmops@ito4.jp` / `km@ito4.jp` へ SSH で繋ぐ。** ito4.jp を橙にすると、DNS の答えが Cloudflare の IP になり、
  **Cloudflare は 22 番を中継しない**ので、全部繋がらなくなる。

  スクリプトの既定の名前(十数本)と、登録したタスクの引数を全部書き換える代わりに、**この PC の SSH の設定で 1 回だけ曲げる**:

      Host ito4.jp
          HostName ssh.ito4.jp      ← 灰(DNS だけ)の名前。本番の IP を答える
          HostKeyAlias ito4.jp      ← known_hosts の今の ito4.jp の行をそのまま使う(登録し直さない)

  ssh も scp も ~\.ssh\config を読むので、**スクリプト・タスク・cmd は何も変えずに**灰の名前へ行く。
  ホスト鍵の照合は今までどおり ito4.jp の行で行う(相手が違えば、今までどおり止まる)。

  ## 先に要るもの(利用者が行う)

  - Cloudflare の DNS に **`ssh` の A レコード(本番の IP)を「灰」で**作る。橙にすると 22 番が届かない
  - -Apply は、その名前が引けて、**Cloudflare の範囲ではない**ことを確かめてから書く(範囲は nginx/km/cloudflare-real-ip.conf から読む)

  灰の名前は本番の IP を公に答える(admin.ito4.jp も灰なので、今と変わらない)。IP を隠したいなら、HostName に IP を直に書く手もある
  (VPS を作り直して IP が変わったら、ここも直す)。

  Windows PowerShell 5.1 でも動く書き方。保存は BOM 付き UTF-8・LF。~\.ssh\config は今の改行と BOM の有無を保って書く。
#>
[CmdletBinding()]
param(
    [switch]$Apply,
    [switch]$Undo,
    [switch]$Test,
    # 灰(DNS だけ)の名前。本番の IP を答えること
    [string]$SshName = 'ssh.ito4.jp',
    # 曲げる名前(スクリプトが使っている名前)
    [string]$Name = 'ito4.jp',
    # -Test で使う鍵と利用者
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    [string]$User = 'kmops',
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
Set-StrictMode -Version 2.0

if ($Apply -and $Undo) { throw '-Apply と -Undo は同時に渡せません。' }
if ($Name -notmatch '^[a-z0-9.-]+$' -or $SshName -notmatch '^[a-z0-9.-]+$') { throw "名前の形が違います: $Name / $SshName" }
if ($Name -eq $SshName) { throw '曲げる名前と灰の名前が同じです。' }

$SshDir = Join-Path $env:USERPROFILE '.ssh'
$ConfigPath = Join-Path $SshDir 'config'
$Begin = '# >>> KosenMap: ito4.jp の SSH は灰の名前へ(server/scripts/ssh-host-alias.ps1。消すときは -Undo)'
$End = '# <<< KosenMap'
$RealIpConf = Join-Path (Split-Path -Parent $PSScriptRoot) 'nginx\km\cloudflare-real-ip.conf'

# ---------------------------------------------------------------- Cloudflare の範囲(nginx の設定が正本)
function Get-CfRanges {
    if (-not (Test-Path $RealIpConf)) { throw "見つかりません: $RealIpConf" }
    $list = @()
    foreach ($line in [IO.File]::ReadAllLines($RealIpConf)) {
        if ($line -match '^\s*set_real_ip_from\s+([0-9a-fA-F:.]+/\d+)\s*;') { $list += $Matches[1] }
    }
    if ($list.Count -eq 0) { throw "$RealIpConf に set_real_ip_from がありません" }
    return $list
}

function Test-InCidr {
    param([string]$Address, [string]$Cidr)
    $parts = $Cidr -split '/'
    $net = [Net.IPAddress]::Parse($parts[0])
    $ip = [Net.IPAddress]::Parse($Address)
    if ($net.AddressFamily -ne $ip.AddressFamily) { return $false }
    $bits = [int]$parts[1]
    $a = $ip.GetAddressBytes(); $b = $net.GetAddressBytes()
    for ($i = 0; $i -lt $a.Length; $i++) {
        $take = [Math]::Min(8, [Math]::Max(0, $bits - 8 * $i))
        if ($take -eq 0) { break }
        $mask = [byte]((0xFF -shl (8 - $take)) -band 0xFF)
        if (($a[$i] -band $mask) -ne ($b[$i] -band $mask)) { return $false }
    }
    return $true
}

function Test-IsCloudflare {
    param([string]$Address, [string[]]$Ranges)
    foreach ($r in $Ranges) { if (Test-InCidr -Address $Address -Cidr $r) { return $true } }
    return $false
}

# 公開の DNS に聞く(この PC の hosts や古いキャッシュに惑わされない)
function Get-Addresses {
    param([string]$HostName)
    $out = @()
    foreach ($type in 'A', 'AAAA') {
        try {
            $ans = Resolve-DnsName -Name $HostName -Type $type -DnsOnly -NoHostsFile -QuickTimeout -ErrorAction Stop
            foreach ($r in $ans) {
                if ($type -eq 'A' -and $r.PSObject.Properties['IPAddress'] -and $r.Type -eq 'A') { $out += $r.IPAddress }
                if ($type -eq 'AAAA' -and $r.PSObject.Properties['IPAddress'] -and $r.Type -eq 'AAAA') { $out += $r.IPAddress }
            }
        } catch { }
    }
    return @($out | Select-Object -Unique)
}

function Describe-Name {
    param([string]$HostName, [string[]]$Ranges)
    $addrs = @(Get-Addresses -HostName $HostName)
    if ($addrs.Count -eq 0) {
        Write-Host ("  {0,-16} 引けません" -f $HostName)
        return 'none'
    }
    $cf = @($addrs | Where-Object { Test-IsCloudflare -Address $_ -Ranges $Ranges })
    $state = if ($cf.Count -eq $addrs.Count) { 'orange' } elseif ($cf.Count -eq 0) { 'gray' } else { 'mixed' }
    $label = @{ orange = '橙(Cloudflare の中継)'; gray = '灰(本番の IP を直に答える)'; mixed = '★ 混ざっている' }[$state]
    Write-Host ("  {0,-16} {1}  {2}" -f $HostName, $label, ($addrs -join ', '))
    return $state
}

# ---------------------------------------------------------------- ~\.ssh\config
function Read-Config {
    if (-not (Test-Path $ConfigPath)) { return @{ Text = ''; Bom = $false; Nl = "`n" } }
    $bytes = [IO.File]::ReadAllBytes($ConfigPath)
    $bom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
    $text = (New-Object Text.UTF8Encoding $false).GetString($bytes)
    if ($bom) { $text = $text.Substring(1) }
    $nl = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
    return @{ Text = $text; Bom = $bom; Nl = $nl }
}

function Remove-Block {
    param([string]$Text)
    $pattern = '(?ms)^' + [regex]::Escape($Begin) + '.*?^' + [regex]::Escape($End) + '[^\r\n]*(\r?\n)?'
    return [regex]::Replace($Text, $pattern, '')
}

function Save-Config {
    param([hashtable]$Cfg, [string]$Text)
    if (Test-Path $ConfigPath) {
        $old = Join-Path $SshDir 'Old'
        if (-not (Test-Path $old)) { New-Item -ItemType Directory -Path $old | Out-Null }
        $copy = Join-Path $old ('config-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
        Copy-Item -LiteralPath $ConfigPath -Destination $copy
        Write-Host "  前のファイルを写しました: $copy"
    }
    $enc = New-Object Text.UTF8Encoding $Cfg.Bom
    [IO.File]::WriteAllText($ConfigPath, $Text, $enc)
    Write-Host "  書きました: $ConfigPath"
}

function Show-SshResolved {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    # -F で同じファイルを指す(ssh の既定の置き場と同じ。試験で USERPROFILE を替えたときもこのファイルを読む)
    try {
        if (Test-Path $ConfigPath) { $g = & ssh -F $ConfigPath -G $Name 2>$null } else { $g = & ssh -G $Name 2>$null }
    } finally { $ErrorActionPreference = $prev }
    $hn = ($g | Where-Object { $_ -match '^hostname ' } | Select-Object -First 1) -replace '^hostname ', ''
    $ka = ($g | Where-Object { $_ -match '^hostkeyalias ' } | Select-Object -First 1) -replace '^hostkeyalias ', ''
    if (-not $ka) { $ka = '(なし)' }
    Write-Host "  ssh $Name → 繋ぐ先 $hn・ホスト鍵の名前 $ka"
    return $hn
}

function Test-KnownHost {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { & ssh-keygen -F $Name 2>&1 | Out-Null; return ($LASTEXITCODE -eq 0) } finally { $ErrorActionPreference = $prev }
}

# ================================================================ 本体
$ranges = Get-CfRanges
$cfg = Read-Config
$hasBlock = $cfg.Text.Contains($Begin)

Write-Host ''
Write-Host '== DNS(公開の DNS の答え)'
$mainState = Describe-Name -HostName $Name -Ranges $ranges
$sshState = Describe-Name -HostName $SshName -Ranges $ranges

Write-Host ''
Write-Host '== この PC の SSH'
Write-Host ("  ~\.ssh\config の段: {0}" -f $(if ($hasBlock) { 'あり' } else { 'なし' }))
[void](Show-SshResolved)
Write-Host ("  known_hosts の {0} の行: {1}" -f $Name, $(if (Test-KnownHost) { 'あり' } else { '★ なし(先に一度繋いで登録する)' }))

if (-not $Apply -and -not $Undo) {
    Write-Host ''
    if ($mainState -eq 'orange' -and -not $hasBlock) {
        Write-Host "★ $Name が橙なのに段がありません。SSH は繋がりません: .\ssh-host-alias.ps1 -Apply"
    } elseif ($hasBlock -and $sshState -ne 'gray') {
        Write-Host "★ 段はありますが $SshName が灰ではありません($sshState)。Cloudflare の DNS で灰にしてください。"
    } elseif ($hasBlock) {
        Write-Host "SSH は $SshName(灰)へ行きます。"
    } else {
        Write-Host "まだ段はありません。$SshName を灰で作ってから: .\ssh-host-alias.ps1 -Apply"
    }
}

if ($Apply) {
    Write-Host ''
    Write-Host '== 段を足す'
    if ($sshState -ne 'gray') {
        throw "$SshName が灰で引けません($sshState)。Cloudflare の DNS に A レコード(本番の IP・灰)を作ってから流してください。"
    }
    $nl = $cfg.Nl
    $block = @(
        $Begin
        '# Cloudflare は 22 番を中継しないので、この PC から ito4.jp への SSH(配備・控え・%%host・トンネル)を灰の名前へ曲げる(docs/17)'
        "Host $Name"
        "    HostName $SshName"
        "    HostKeyAlias $Name"
        $End
    ) -join $nl
    $text = Remove-Block -Text $cfg.Text
    if ($text.Length -gt 0 -and -not $text.EndsWith("`n")) { $text += $nl }
    # **先頭に置く**(ssh は最初に見つけた値を使う。あとから Host * などが足されても負けない)
    $text = $block + $nl + $nl + $text
    Save-Config -Cfg $cfg -Text $text
    $hn = Show-SshResolved
    if ($hn -ne $SshName) { throw "ssh -G の答えが $SshName になりません($hn)。~\.ssh\config を見てください。" }
}

if ($Undo) {
    Write-Host ''
    Write-Host '== 段を外す'
    if (-not $hasBlock) {
        Write-Host '  段はありません(何もしません)'
    } else {
        $text = Remove-Block -Text $cfg.Text
        $text = $text -replace '^(\r?\n)+', ''
        Save-Config -Cfg $cfg -Text $text
        [void](Show-SshResolved)
        if ($mainState -eq 'orange') { Write-Host "★ $Name は橙のままです。SSH は繋がりません。" }
    }
}

if ($Test) {
    Write-Host ''
    Write-Host "== 繋いでみる($User@$Name・echo だけ)"
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $f = @(); if (Test-Path $ConfigPath) { $f = @('-F', $ConfigPath) }
        $out = & ssh @f -i $KeyPath -o BatchMode=yes -o ConnectTimeout=10 -o IdentitiesOnly=yes "$User@$Name" 'echo km-ok' 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $prev }
    if ($code -eq 0 -and "$out" -match 'km-ok') {
        Write-Host '  OK   繋がりました'
    } else {
        Write-Host "  ★    繋がりません(exit $code): $out"
        exit 1
    }
}
