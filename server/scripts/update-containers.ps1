<#
.SYNOPSIS
  コンテナの更新(Node・PHP・Logto・DB など)を、番号を選んで順に進める。

.DESCRIPTION
  2026-10-16、利用者の指示「Node と、Logto 等のアップロード方法を簡略化してほしい。選択肢を設けて実行するように」。

  それまでは docs/05-containers の手順を、人が 1 つずつ追っていた:
    ホストで更新を調べる → 出た digest を手で compose / Dockerfile へ写す → check.php →
    控え → 配備 → ホストで pull / build / Logto の移行 → 立て直す → 確かめる
  **digest の手写しと、順番の取り違え**が一番危ない。ここでは番号を選ぶだけにして、
  写す・検査する・控えを取る・入れ替える、をこのスクリプトが決まった順で行う。

  選べるもの:
    1. 更新を調べる(読むだけ。ホストの check-updates.sh)
    2. 修正版を当てる —— Node(soketi の土台)・PHP(web の土台)・MariaDB・PostgreSQL など。
       **同じ版(タグ)のまま中身だけ新しくする。** 一覧から番号で選ぶ
    3. Logto を次の版へ上げる(控え → 新しい像 → 移行の一覧 → 移行 → 立て直す)
    4. いまの様子を見る(読むだけ)

  守っている約束(docs/05):
    - **本番を変える前に必ず `yes` を打たせる。** 控えは deploy-to-host.ps1 -Backup が**配備の前に**取る。
      取れなければ何も送らずに止まる
    - 版は必ず `名前:タグ@sha256:…`(latest に戻さない)。書き換えたら check.php を通す。通らなければ元に戻す
    - **メジャー版は上げない**(タグは変えない。postgres 17 → 18 などは手で計画する)
    - Logto は 1 つずつ(飛ばさない)。移行は**新しい像で**当てる。`up -d logto` だけを叩かず全体の `up -d`
    - タグの無い固定(nginx@sha256:… など)は、どの版が新しいか分からないので**ここでは上げない**

  接続は deploy-to-host.ps1 と同じ(kmops@ito4.jp、鍵 ~\.ssh\km_ops。km-ssh.ps1)。

.PARAMETER Choice
  メニューを出さずに 1 つだけ行う: check / refresh / logto / status

.PARAMETER DryRun
  **繋がない・書き換えない・配備しない。** 新しい digest は仮の値にして、流れと書き換えの差分だけを見せる。

.EXAMPLE
  .\update-containers.ps1

.EXAMPLE
  .\update-containers.ps1 -Choice refresh -DryRun
#>
[CmdletBinding()]
param(
    [ValidateSet('', 'check', 'refresh', 'logto', 'status')]
    [string]$Choice = '',
    [switch]$DryRun,
    [string]$HostName = 'ito4.jp',
    # deploy-to-host.ps1 と同じ。docker あり・sudo なし(docs/12 §7-4 B)
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    [string]$RemotePath = '/opt/kosenmap',
    [int]$Port = 22
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot   # …\Website\server

. (Join-Path $PSScriptRoot 'km-ssh.ps1')
Initialize-KmSsh -HostName $HostName -User $User -KeyPath $KeyPath -Port $Port

# 固定を探すファイル。build のものは「そのサービスをビルドし直す」、compose のものは「pull する」
$PinFiles = @(
    @{ Path = 'compose.yaml'; Kind = 'compose' }
    @{ Path = 'compose.vps.yaml'; Kind = 'compose' }
    @{ Path = 'docker/php/Dockerfile'; Kind = 'build'; Service = 'web' }
    @{ Path = 'docker/soketi/Dockerfile'; Kind = 'build'; Service = 'soketi' }
)

$LogtoRepo = 'ghcr.io/logto-io/logto'
$FakeDigest = 'sha256:' + ('0' * 63) + '1'

# ---------------------------------------------------------------- 表示

function Write-Step([string]$Text) { Write-Host ''; Write-Host "== $Text ==" -ForegroundColor Cyan }
function Write-Warn([string]$Text) { Write-Host $Text -ForegroundColor Yellow }

<#
  **本番を変える前の確かめ。`yes` と打たせる**(Enter や y では進めない)。
  取扱説明書の %%host --confirm と同じ強さにそろえる。
#>
function Confirm-Yes([string]$Text) {
    Write-Host ''
    Write-Warn "⚠ $Text"
    if ($DryRun) { Write-Host '  (試し: yes とみなして進めます。本番には触れません)'; return $true }
    $answer = Read-Host '  進めるなら yes と打ってください'
    return ($answer.Trim() -ceq 'yes')
}

# ---------------------------------------------------------------- ホスト

$script:Connected = $false
function Connect-Host {
    if ($DryRun -or $script:Connected) { return }
    Write-Host "接続: $User@${HostName}:$RemotePath"
    Assert-KmSshReady
    $script:Connected = $true
}

<#
  ホストで 1 本のコマンドを走らせ、**出力をそのまま画面に流す**(pull や build は数分かかるので、
  終わるまで黙らない)。引用符を含まない単純なコマンドだけを渡す(km-ssh.ps1 の Invoke-KmSshStdin の説明)。
#>
function Invoke-Host([string]$Command, [switch]$AllowFailure) {
    $full = "cd $RemotePath && $Command"
    if ($DryRun) { Write-Host "  (試し) ホストで: $full" -ForegroundColor DarkGray; return 0 }
    Connect-Host
    Write-Host "  ホストで: $Command" -ForegroundColor DarkGray
    $sshArgs = @(Get-KmSshArgs) + @('-p', "$Port", (Get-KmSshTarget), $full)
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $prevEnc = [Console]::OutputEncoding
    try {
        try { [Console]::OutputEncoding = New-Object Text.UTF8Encoding $false } catch { }
        # **Out-Host を外さない。** 外すと出力が関数の戻り値に混ざり、呼ぶ側の [void] で消える
        & ssh @sshArgs | Out-Host
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prev
        try { [Console]::OutputEncoding = $prevEnc } catch { }
    }
    if ($code -ne 0 -and -not $AllowFailure) { throw "ホストで失敗しました (exit $code): $Command" }
    return $code
}

<#
  レジストリにある**いまの** digest をホストで聞く(check-updates.sh の remote_digest と同じ聞き方)。
  imagetools はマニフェストリストの digest を出すので、compose に書く値と同じ種類になる。
  聞けなかったものは空(「変わっていない」と混同しない)。
#>
function Get-RemoteDigests([string[]]$Refs) {
    $result = @{}
    if ($Refs.Count -eq 0) { return $result }
    if ($DryRun) {
        foreach ($r in $Refs) { $result[$r] = $FakeDigest }
        return $result
    }
    Connect-Host
    $lines = foreach ($r in $Refs) {
        "d=`$(docker buildx imagetools inspect '$r' 2>/dev/null | sed -n 's/^Digest:[[:space:]]*\(sha256:[0-9a-f]*\).*/\1/p' | head -n 1)"
        "echo `"KMDIGEST|$r|`$d`""
    }
    Write-Host '  レジストリに新しい中身を聞いています…' -ForegroundColor DarkGray
    $out = Invoke-KmSshStdin -Script ($lines -join "`n") -TimeoutSec 300
    foreach ($line in $out) {
        if ($line -match '^KMDIGEST\|([^|]+)\|(sha256:[0-9a-f]{64})?$') {
            $result[$Matches[1]] = "$($Matches[2])"
        }
    }
    return $result
}

# ---------------------------------------------------------------- 手元の固定

function Get-FriendlyName([string]$Ref) {
    $name = ($Ref -split ':')[0]
    if ($Ref -match '^ghcr\.io/') { $name = ($Ref -replace ':[^:/]+$', '') }
    switch -Regex ($name) {
        '^node$' { return 'Node(soketi の土台)' }
        'aloware/soketi$' { return 'soketi 本体(フォーク)' }
        '^php$' { return 'PHP(web の土台)' }
        '^composer$' { return 'composer(web のビルドで使う)' }
        '^postgres$' { return 'PostgreSQL(Logto の DB)' }
        '^mariadb$' { return 'MariaDB(地図などの DB)' }
        '^phpmyadmin$' { return 'phpMyAdmin' }
        'logto-io/logto$' { return 'Logto(サインイン)' }
        'glances$' { return 'Glances(ホストの様子)' }
        default { return $name }
    }
}

<#
  compose と Dockerfile から `名前:タグ@sha256:…` の固定を拾う。
  タグの無い `名前@sha256:…` は Untagged として返す(上げ先が分からない)。
#>
function Get-Pins {
    $pins = @()
    foreach ($file in $PinFiles) {
        $path = Join-Path $Root $file.Path
        if (-not (Test-Path $path)) { continue }
        $lines = [IO.File]::ReadAllLines($path)
        $service = ''
        for ($i = 0; $i -lt $lines.Count; $i++) {
            $line = $lines[$i]
            if ($file.Kind -eq 'compose') {
                if ($line -match '^  ([A-Za-z0-9_-]+):\s*$') { $service = $Matches[1] }
                $m = [regex]::Match($line, '^(\s+image:\s*)([^\s@]+)@(sha256:[0-9a-f]{64})(.*)$')
                $svc = $service
                $kind = 'image'
            } else {
                $m = [regex]::Match($line, '^((?:FROM\s+)|(?:COPY\s.*--from=))([^\s@]+)@(sha256:[0-9a-f]{64})(.*)$')
                $svc = $file.Service
                $kind = 'build'
            }
            if (-not $m.Success) { continue }
            $ref = $m.Groups[2].Value
            $lastPart = ($ref -split '/')[-1]
            $pins += [pscustomobject]@{
                File     = $file.Path
                Line     = $i
                Prefix   = $m.Groups[1].Value
                Ref      = $ref
                Digest   = $m.Groups[3].Value
                Suffix   = $m.Groups[4].Value
                Service  = $svc
                Kind     = $kind
                Name     = Get-FriendlyName $ref
                Untagged = -not $lastPart.Contains(':')
                IsLogto  = $ref -like "$LogtoRepo*"
            }
        }
    }
    return $pins
}

<#
  固定の digest(と、Logto なら版)を書き換える。**改行と BOM の有無は元のまま。**
  元の中身を返すので、検査に落ちたらそれで戻す。
#>
function Set-PinLines($Changes) {
    $backup = @{}
    foreach ($group in ($Changes | Group-Object { $_.Pin.File })) {
        $path = Join-Path $Root $group.Name
        $bytes = [IO.File]::ReadAllBytes($path)
        $backup[$group.Name] = $bytes
        $hasBom = $bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF
        $text = (New-Object Text.UTF8Encoding $false).GetString($bytes)
        if ($hasBom) { $text = $text.Substring(1) }
        $eol = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
        $lines = $text -split "`r?`n"
        foreach ($c in $group.Group) {
            $p = $c.Pin
            $old = $lines[$p.Line]
            $expected = "$($p.Prefix)$($p.Ref)@$($p.Digest)$($p.Suffix)"
            if ($old -ne $expected) { throw "$($p.File) の $($p.Line + 1) 行目が読んだときと違います。もう一度やり直してください。" }
            $lines[$p.Line] = "$($p.Prefix)$($c.NewRef)@$($c.NewDigest)$($p.Suffix)"
        }
        $newText = $lines -join $eol
        $enc = New-Object Text.UTF8Encoding $hasBom
        if (-not $DryRun) { [IO.File]::WriteAllText($path, $newText, $enc) }
    }
    return $backup
}

function Restore-Files($Backup) {
    if ($DryRun -or $null -eq $Backup) { return }
    foreach ($k in $Backup.Keys) { [IO.File]::WriteAllBytes((Join-Path $Root $k), $Backup[$k]) }
    Write-Host '  手元のファイルを元に戻しました。'
}

function Show-Changes($Changes) {
    foreach ($c in $Changes) {
        $p = $c.Pin
        Write-Host "  $($p.File):$($p.Line + 1)  [$($p.Name)]"
        Write-Host "    - $($p.Ref)@$($p.Digest)" -ForegroundColor Red
        Write-Host "    + $($c.NewRef)@$($c.NewDigest)" -ForegroundColor Green
    }
}

# check.php を通す。**通らなければ false**(呼ぶ側で戻す)
function Invoke-Check {
    Write-Step '自己検査(check.php)'
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) { Write-Warn '  php が見つかりません。検査できないので止めます。'; return $false }
    Push-Location $Root
    try {
        $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $out = & php 'src/scripts/check.php' 2>&1
        $code = $LASTEXITCODE
        $ErrorActionPreference = $prev
    } finally { Pop-Location }
    $fails = @($out | Where-Object { "$_" -cmatch '\sFAIL\b' })
    if ($code -ne 0 -or $fails.Count -gt 0) {
        Write-Warn "  検査に落ちました (exit $code)。"
        $fails | Select-Object -First 20 | ForEach-Object { Write-Host "    $_" -ForegroundColor Red }
        return $false
    }
    $last = @($out | Where-Object { "$_" -match '通過' } | Select-Object -Last 1)
    Write-Host "  $($last -join '')" -ForegroundColor Green
    return $true
}

<#
  配備は deploy-to-host.ps1 に任せる(**控えは配備の前**。取れなければ何も送らず 3 で終わる)。
  0 と 2(後片付けに気になる点)は先へ進める。
#>
function Invoke-Deploy {
    Write-Step '控えを取ってから配備(deploy-to-host.ps1 -Backup)'
    $deploy = Join-Path $PSScriptRoot 'deploy-to-host.ps1'
    if ($DryRun) {
        Write-Host '  (試し) .\deploy-to-host.ps1 -Action deploy -Backup -Yes' -ForegroundColor DarkGray
        return $true
    }
    try {
        & $deploy -Action deploy -Backup -Yes -HostName $HostName -User $User -KeyPath $KeyPath -RemotePath $RemotePath -Port $Port
        $code = $LASTEXITCODE
    } catch {
        Write-Warn "  配備が止まりました: $($_.Exception.Message)"
        return $false
    }
    switch ($code) {
        0 { return $true }
        2 { Write-Warn '  配備は済みましたが、後片付けに気になる点があります(上の表示)。続けます。'; return $true }
        3 { Write-Warn '  控えが取れなかったので、配備していません(何も送っていません)。'; return $false }
        default { Write-Warn "  配備に失敗しました (exit $code)。"; return $false }
    }
}

function Show-AfterChecklist([switch]$Logto, [switch]$Soketi) {
    Write-Step '上げたあとに画面で見るもの(docs/05 §7)'
    $items = @(
        'https://ito4.jp/ が開き、地図が出る'
        '管理画面にサインインできる(= Logto が生きている)'
    )
    if ($Logto) {
        $items += 'サインインのコードの画面で、1 つ目の枠に autocomplete="one-time-code" が付いている'
        $items += 'Android アプリでサインインできる'
    }
    if ($Soketi) { $items += '管理画面のチャットと死活監視が「接続済み」になる' }
    $items += 'ファイル管理から実際にダウンロードできる'
    foreach ($t in $items) { Write-Host "  [ ] $t" }
}

# ---------------------------------------------------------------- 1. 調べる

function Invoke-CheckUpdates {
    Write-Step '更新を調べる(読むだけ)'
    [void](Invoke-Host "./scripts/check-updates.sh --path $RemotePath" -AllowFailure)
}

# ---------------------------------------------------------------- 2. 修正版

function Invoke-Refresh {
    Write-Step '修正版を当てる(同じ版のまま中身を新しくする)'
    $all = @(Get-Pins)
    $untagged = @($all | Where-Object { $_.Untagged })
    $pins = @($all | Where-Object { -not $_.Untagged -and -not $_.IsLogto })
    if ($pins.Count -eq 0) { Write-Warn '  固定が見つかりません。'; return }

    $digests = Get-RemoteDigests @($pins | ForEach-Object { $_.Ref } | Select-Object -Unique)

    $rows = @()
    $n = 0
    foreach ($p in $pins) {
        $n++
        $new = "$($digests[$p.Ref])"
        $state = if ($new -eq '') { 'unknown' } elseif ($new -eq $p.Digest) { 'same' } else { 'new' }
        $rows += [pscustomobject]@{ No = $n; Pin = $p; NewDigest = $new; State = $state }
    }

    Write-Host ''
    foreach ($r in $rows) {
        $mark = switch ($r.State) { 'new' { '★ 新しい中身あり' } 'same' { '  変化なし' } default { '  聞けませんでした' } }
        $color = switch ($r.State) { 'new' { 'Yellow' } 'same' { 'Gray' } default { 'DarkYellow' } }
        Write-Host ("  [{0,2}] {1,-26} {2,-40} {3}" -f $r.No, $r.Pin.Name, $r.Pin.Ref, $mark) -ForegroundColor $color
    }
    if ($untagged.Count -gt 0) {
        Write-Host ''
        Write-Host "  タグの無い固定(ここでは上げません): $(($untagged | ForEach-Object { ($_.Ref) + '(' + $_.File + ')' }) -join ' / ')" -ForegroundColor DarkGray
    }

    $candidates = @($rows | Where-Object { $_.State -eq 'new' })
    if ($candidates.Count -eq 0) { Write-Host ''; Write-Host '  当てるものはありません。' -ForegroundColor Green; return }

    Write-Host ''
    $answer = Read-Host "  当てる番号をカンマで(Enter = ★ の全部 $(($candidates | ForEach-Object { $_.No }) -join ','), q = やめる)"
    if ($answer.Trim() -eq 'q') { return }
    $chosen = @(if ($answer.Trim() -eq '') { $candidates } else {
        $nums = $answer -split '[,\s]+' | Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int]$_ }
        $rows | Where-Object { $_.No -in $nums -and $_.State -eq 'new' }
    })
    if ($chosen.Count -eq 0) { Write-Warn '  選んだ番号に、当てられるもの(★)がありません。'; return }

    if (@($chosen | Where-Object { $_.Pin.Name -match 'DB' }).Count -gt 0) {
        Write-Warn '  DB(MariaDB / PostgreSQL)を含みます。壊れたら控えからの復元になります(docs/03 §8)。'
    }

    $changes = @($chosen | ForEach-Object { [pscustomobject]@{ Pin = $_.Pin; NewRef = $_.Pin.Ref; NewDigest = $_.NewDigest } })
    Write-Step '手元のファイルを書き換える'
    Show-Changes $changes
    $backup = Set-PinLines $changes

    if (-not (Invoke-Check)) { Restore-Files $backup; return }

    $pullServices = @($chosen | Where-Object { $_.Pin.Kind -eq 'image' } | ForEach-Object { $_.Pin.Service } | Select-Object -Unique)
    $buildServices = @($chosen | Where-Object { $_.Pin.Kind -eq 'build' } | ForEach-Object { $_.Pin.Service } | Select-Object -Unique)
    $what = @()
    if ($pullServices.Count) { $what += "取り直す: $($pullServices -join ' ')" }
    if ($buildServices.Count) { $what += "作り直す: $($buildServices -join ' ')" }

    if (-not (Confirm-Yes "本番で控えを取ってから配備し、入れ替えます($($what -join ' / '))。数十秒止まるサービスがあります")) {
        $keep = Read-Host '  やめました。手元の書き換えを元に戻しますか? (y = 戻す / Enter = 残す)'
        if ($keep.Trim() -eq 'y') { Restore-Files $backup }
        return
    }
    if (-not (Invoke-Deploy)) {
        Write-Warn '  本番は入れ替えていません。手元のファイルは書き換えたままです。'
        $keep = Read-Host '  元に戻しますか? (y = 戻す / Enter = 残す)'
        if ($keep.Trim() -eq 'y') { Restore-Files $backup }
        return
    }

    Write-Step 'ホストで入れ替える'
    if ($pullServices.Count) { [void](Invoke-Host "docker compose pull $($pullServices -join ' ')") }
    if ($buildServices.Count) { [void](Invoke-Host "docker compose build --pull $($buildServices -join ' ')") }
    # **1 つだけの up -d にしない**(depends_on が評価されない)
    [void](Invoke-Host 'docker compose up -d')
    [void](Invoke-Host 'docker compose ps' -AllowFailure)
    if ($buildServices -contains 'soketi') {
        [void](Invoke-Host 'docker compose exec -T soketi node -v' -AllowFailure)
    }
    Show-AfterChecklist -Soketi:($buildServices -contains 'soketi')
}

# ---------------------------------------------------------------- 3. Logto

function ConvertTo-Version([string]$Text) {
    if ($Text -match '^v?(\d+)\.(\d+)\.(\d+)$') { return [version]"$($Matches[1]).$($Matches[2]).$($Matches[3])" }
    return $null
}

# 公開されている Logto の版(正式版だけ)を古い順に
function Get-LogtoReleases {
    try {
        $list = Invoke-RestMethod -Uri 'https://api.github.com/repos/logto-io/logto/releases?per_page=60' `
            -Headers @{ Accept = 'application/vnd.github+json'; 'User-Agent' = 'kosenmap-update-containers' } -TimeoutSec 20
    } catch {
        return @()
    }
    $out = foreach ($r in $list) {
        if ($r.draft -or $r.prerelease) { continue }
        $v = ConvertTo-Version "$($r.tag_name)"
        if ($v) { [pscustomobject]@{ Version = $v; Url = "$($r.html_url)" } }
    }
    return @($out | Sort-Object Version)
}

function Invoke-LogtoUpgrade {
    Write-Step 'Logto を次の版へ上げる'
    $pin = @(Get-Pins | Where-Object { $_.IsLogto -and $_.File -eq 'compose.yaml' }) | Select-Object -First 1
    if (-not $pin) { Write-Warn '  compose.yaml に Logto の固定が見つかりません。'; return }
    $currentText = ($pin.Ref -split ':')[-1]
    $current = ConvertTo-Version $currentText
    Write-Host "  いまの版: $currentText"

    $releases = Get-LogtoReleases
    $newer = @($releases | Where-Object { $current -and $_.Version -gt $current })
    $suggest = ''
    if ($releases.Count -eq 0) {
        Write-Warn '  GitHub から版の一覧を取れませんでした。上げる先の版を自分で打ってください。'
    } elseif ($newer.Count -eq 0) {
        Write-Host '  最新です。上げるものはありません。' -ForegroundColor Green
        return
    } else {
        $suggest = "$($newer[0].Version)"
        Write-Host "  新しい版: $(($newer | ForEach-Object { "$($_.Version)" }) -join ' → ')"
        Write-Host "  **1 つずつ上げます(飛ばさない)。** 次は $suggest"
        Write-Host "  リリースノート(breaking change を読む): $($newer[0].Url)"
    }

    $typed = Read-Host "  上げる先の版(Enter = $(if ($suggest) { $suggest } else { 'やめる' }), q = やめる)"
    if ($typed.Trim() -eq 'q') { return }
    $newText = if ($typed.Trim() -eq '') { $suggest } else { $typed.Trim().TrimStart('v') }
    $newVersion = ConvertTo-Version $newText
    if (-not $newVersion) { Write-Warn '  版の形が違います(例: 1.45.0)。'; return }
    if ($current -and $newVersion -le $current) { Write-Warn '  いまの版より新しい版を選んでください(戻すのは docs/05 §6)。'; return }
    if ($newer.Count -gt 0 -and $newVersion -ne $newer[0].Version) {
        Write-Warn "  $($newer[0].Version) を飛ばして $newVersion へ上げようとしています。移行を飛ばすと戻せません。"
        if ((Read-Host '  それでも進めるなら skip と打ってください').Trim() -cne 'skip') { return }
    }
    $newText = "$newVersion"

    $newRef = "${LogtoRepo}:$newText"
    $digest = "$((Get-RemoteDigests @($newRef))[$newRef])"
    if ($digest -eq '') { Write-Warn "  $newRef の digest をレジストリから取れませんでした。版の番号を確かめてください。"; return }

    Write-Step '手元の compose.yaml を書き換える'
    $changes = @([pscustomobject]@{ Pin = $pin; NewRef = $newRef; NewDigest = $digest })
    Show-Changes $changes
    $backup = Set-PinLines $changes
    if (-not (Invoke-Check)) { Restore-Files $backup; return }

    Write-Host ''
    Write-Warn '  確かめてください: 会期中(イベントの最中)ではない / リリースノートを読んだ / サインインを試せる人が居る'
    if (-not (Confirm-Yes "本番で控えを取ってから compose を置き、Logto $currentText → $newText の新しい像を取ります(まだ入れ替えません)")) {
        if ((Read-Host '  やめました。手元の書き換えを元に戻しますか? (y = 戻す / Enter = 残す)').Trim() -eq 'y') { Restore-Files $backup }
        return
    }
    if (-not (Invoke-Deploy)) {
        Write-Warn '  本番の Logto はそのままです。手元の compose.yaml は書き換えたままです。'
        if ((Read-Host '  元に戻しますか? (y = 戻す / Enter = 残す)').Trim() -eq 'y') { Restore-Files $backup }
        return
    }

    Write-Step '新しい像を取る'
    [void](Invoke-Host 'docker compose pull logto')

    Write-Step '移行の一覧(使い捨てのコンテナ。DB は書き換えない)'
    [void](Invoke-Host "docker compose run --rm --entrypoint sh logto -c 'cd /etc/logto && npm run alteration list $newText'")

    if (-not (Confirm-Yes "Logto の DB 移行を当てます($newText)。**戻すのは難しい**(控えは取りました)")) {
        Write-Warn '  移行はしていません。動いている Logto は古い版のままです。'
        Write-Warn '  compose.yaml は新しい版になっているので、次に up -d すると新しい像で起動します。'
        Write-Warn "  やめるなら手元の compose.yaml を $currentText に戻して配備し直してください。"
        return
    }

    Write-Step '移行を当てる(新しい像で)'
    $code = Invoke-Host "docker compose run --rm --entrypoint sh logto -c 'cd /etc/logto && npm run alteration deploy $newText'" -AllowFailure
    if ($code -ne 0) {
        Write-Warn '  移行に失敗しました。立て直しはしていません(動いている Logto は古い版のまま)。'
        Write-Warn '  上の出力を見てから、docs/05 §6(巻き戻し)か、取った控えからの復元(docs/03 §8)を選んでください。'
        return
    }

    Write-Step '立て直す(全体の up -d)'
    [void](Invoke-Host 'docker compose up -d')
    Write-Host '  起動を待っています(20 秒)…'
    if (-not $DryRun) { Start-Sleep -Seconds 20 }
    [void](Invoke-Host 'docker compose ps' -AllowFailure)
    [void](Invoke-Host 'docker compose logs --tail 60 logto' -AllowFailure)
    Show-AfterChecklist -Logto
}

# ---------------------------------------------------------------- 4. 様子

function Invoke-Status {
    Write-Step 'いまの様子(読むだけ)'
    [void](Invoke-Host 'docker compose ps' -AllowFailure)
    [void](Invoke-Host 'docker compose exec -T soketi node -v' -AllowFailure)
    [void](Invoke-Host 'docker compose logs --tail 20 logto' -AllowFailure)
}

# ---------------------------------------------------------------- メニュー

function Invoke-Choice([string]$Name) {
    switch ($Name) {
        'check' { Invoke-CheckUpdates }
        'refresh' { Invoke-Refresh }
        'logto' { Invoke-LogtoUpgrade }
        'status' { Invoke-Status }
    }
}

if ($DryRun) { Write-Warn '(試し)繋がない・書き換えない・配備しない。新しい digest は仮の値です。' }

if ($Choice -ne '') {
    Invoke-Choice $Choice
    exit 0
}

while ($true) {
    Write-Host ''
    Write-Host '==== コンテナの更新(本番 ito4.jp)====' -ForegroundColor Cyan
    Write-Host '  1. 更新を調べる(読むだけ)'
    Write-Host '  2. 修正版を当てる(Node・PHP・MariaDB・PostgreSQL など。版はそのまま)'
    Write-Host '  3. Logto を次の版へ上げる'
    Write-Host '  4. いまの様子を見る(読むだけ)'
    Write-Host '  0. 終わる'
    $pick = (Read-Host '番号').Trim()
    try {
        switch ($pick) {
            '1' { Invoke-Choice 'check' }
            '2' { Invoke-Choice 'refresh' }
            '3' { Invoke-Choice 'logto' }
            '4' { Invoke-Choice 'status' }
            '0' { exit 0 }
            '' { exit 0 }
            default { Write-Warn '  0〜4 の番号を選んでください。' }
        }
    } catch {
        Write-Host ''
        Write-Host "止まりました: $($_.Exception.Message)" -ForegroundColor Red
    }
}
