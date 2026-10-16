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
    # 繋ぐホスト(本番)
    [string]$HostName = 'ito4.jp',
    # deploy-to-host.ps1 と同じ。docker あり・sudo なし(docs/12 §7-4 B)
    [string]$User = 'kmops',
    # SSH の秘密鍵(配備と同じ)
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    # ホスト側の置き場(server/ に相当)
    [string]$RemotePath = '/opt/kosenmap',
    # SSH のポート
    [int]$Port = 22,
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
  **一時的な失敗は自動でやり直す**(2026-10-16、利用者の指示「間違っていたときに自動で停止か、リトライ」)。
  レジストリや回線の揺れ(タイムアウト・429・一瞬の切断)は、待てば通ることが多い。
  10 秒・20 秒と待って計 3 回。それでも駄目なら止める(呼ぶ側が戻し方を出す)。
  **やり直してよいのは、何度やっても結果が同じもの**(聞く・取る・作る)だけ。Logto の移行には使わない。
#>
$RetryTries = 3
function Invoke-WithRetry([string]$Label, [scriptblock]$Action) {
    for ($i = 1; $i -le $RetryTries; $i++) {
        try {
            return (& $Action)
        } catch {
            if ($i -eq $RetryTries) { throw }
            Write-Warn "  $Label に失敗しました: $($_.Exception.Message.Split("`n")[0])"
            Write-Warn "  $($i * 10) 秒待ってやり直します($($i + 1)/$RetryTries)…"
            if (-not $DryRun) { Start-Sleep -Seconds ($i * 10) }
        }
    }
}

# ホストのコマンドを、失敗したら自動でやり直す。最後まで駄目なら $false
function Invoke-HostWithRetry([string]$Command) {
    for ($i = 1; $i -le $RetryTries; $i++) {
        $code = Invoke-Host $Command -AllowFailure
        if ($code -eq 0) { return $true }
        if ($i -lt $RetryTries) {
            Write-Warn "  失敗しました (exit $code)。$($i * 10) 秒待ってやり直します($($i + 1)/$RetryTries)…"
            Start-Sleep -Seconds ($i * 10)
        }
    }
    Write-Warn "  $RetryTries 回とも失敗しました: $Command"
    return $false
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
    $out = Invoke-WithRetry '問い合わせ' { Invoke-KmSshStdin -Script ($lines -join "`n") -TimeoutSec 300 }
    foreach ($line in $out) {
        if ($line -match '^KMDIGEST\|([^|]+)\|(sha256:[0-9a-f]{64})?$') {
            $result[$Matches[1]] = "$($Matches[2])"
        }
    }
    return $result
}

<#
  **本番でいま動いているもの**を読む(2026-10-16、利用者の指摘「1.44.0 なのに最新と出た」)。

  以前は「いまの版」を**手元の** compose.yaml から読んでいた。途中で止めた回(書き換えたが配備・入れ替えまで
  行かなかった)のあとでは、手元は 1.45.0・本番は 1.44.0 になり、**本番が古いのに「最新です」と出た。**
  比べる相手は本番。返すもの:
    Running[サービス] = 動いているコンテナの image(compose に書いてあった `名前:タグ@sha256:…`)
    Built[サービス]   = その像を作った時刻(epoch 秒)。ビルドするサービスで、Dockerfile より古ければ未ビルド
    Files[ファイル]   = ホストに置いてある Dockerfile の FROM / COPY --from= の行
    FileTime[ファイル] = その Dockerfile の更新時刻(epoch 秒)
  試し(-DryRun)では読めないので $null(手元だけで判断する)。
#>
function Get-HostState {
    if ($DryRun) { return $null }
    Connect-Host
    $remoteScript = @'
cd __REMOTE__ || exit 1
for c in $(docker compose ps -q 2>/dev/null); do
  line=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}|{{.Config.Image}}|{{.Image}}' "$c" 2>/dev/null) || continue
  img=${line##*|}
  created=$(docker image inspect -f '{{.Created}}' "$img" 2>/dev/null)
  epoch=$(date -d "$created" +%s 2>/dev/null || echo 0)
  echo "KMRUN|${line%|*}|$epoch"
done
for f in docker/php/Dockerfile docker/soketi/Dockerfile; do
  echo "KMMTIME|$f|$(stat -c %Y "$f" 2>/dev/null || echo 0)"
  grep -E '^(FROM|COPY .*--from=)' "$f" 2>/dev/null | sed "s#^#KMFILE|$f|#"
done
'@
    $out = Invoke-WithRetry '本番の様子の取得' { Invoke-KmSshStdin -Script ($remoteScript -replace '__REMOTE__', $RemotePath) -TimeoutSec 120 }
    $state = @{ Running = @{}; Built = @{}; Files = @{}; FileTime = @{} }
    foreach ($line in $out) {
        if ($line -match '^KMRUN\|([^|]+)\|([^|]+)\|(\d+)$') {
            $state.Running[$Matches[1]] = $Matches[2]
            $state.Built[$Matches[1]] = [long]$Matches[3]
        } elseif ($line -match '^KMMTIME\|([^|]+)\|(\d+)$') {
            $state.FileTime[$Matches[1]] = [long]$Matches[2]
        } elseif ($line -match '^KMFILE\|([^|]+)\|(.*)$') {
            if (-not $state.Files.ContainsKey($Matches[1])) { $state.Files[$Matches[1]] = @() }
            $state.Files[$Matches[1]] += $Matches[2]
        }
    }
    if ($state.Running.Count -eq 0) { throw '本番で動いているコンテナを読めませんでした(docker compose ps が空)。' }
    return $state
}

<#
  その固定が**本番に入っているか**。
    'yes'     本番も同じ digest で動いている
    'no'      手元とは違う(手元だけ書き換えた・配備した が作り直していない)
    'unknown' 読めない(試し・そのサービスが本番で動いていない)
  本番の digest も返す(表示に使う)。
#>
function Get-HostPinState($Pin, $State) {
    if ($null -eq $State) { return @{ State = 'unknown'; Digest = '' } }
    if ($Pin.Kind -eq 'image') {
        $running = "$($State.Running[$Pin.Service])"
        if ($running -eq '') { return @{ State = 'unknown'; Digest = '' } }
        $d = if ($running -match '@(sha256:[0-9a-f]{64})$') { $Matches[1] } else { '' }
        return @{ State = $(if ($d -eq $Pin.Digest) { 'yes' } else { 'no' }); Digest = $d }
    }
    # ビルドするもの: ホストの Dockerfile に同じ固定があり、しかも像がその Dockerfile より新しいこと
    $hostLine = @($State.Files[$Pin.File] | Where-Object { $_ -like "*$($Pin.Ref)@*" }) | Select-Object -First 1
    $d = if ("$hostLine" -match [regex]::Escape($Pin.Ref) + '@(sha256:[0-9a-f]{64})') { $Matches[1] } else { '' }
    if ($d -ne $Pin.Digest) { return @{ State = 'no'; Digest = $d } }
    $built = [long]$State.Built[$Pin.Service]
    $mtime = [long]$State.FileTime[$Pin.File]
    if ($built -gt 0 -and $mtime -gt $built) { return @{ State = 'no'; Digest = $d } }
    return @{ State = 'yes'; Digest = $d }
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
function Invoke-Deploy([switch]$Rollback) {
    $deploy = Join-Path $PSScriptRoot 'deploy-to-host.ps1'
    if ($Rollback) {
        # 戻すための配備。控えはこの回の始めに取ってあるので取り直さない
        Write-Step '元の compose / Dockerfile を配備し直す(deploy-to-host.ps1)'
        if ($DryRun) {
            Write-Host '  (試し) .\deploy-to-host.ps1 -Action deploy -Yes' -ForegroundColor DarkGray
            return $true
        }
    } else {
        Write-Step '控えを取ってから配備(deploy-to-host.ps1 -Backup)'
        if ($DryRun) {
            Write-Host '  (試し) .\deploy-to-host.ps1 -Action deploy -Backup -Yes' -ForegroundColor DarkGray
            return $true
        }
    }
    try {
        if ($Rollback) {
            & $deploy -Action deploy -GitHub none -Yes -HostName $HostName -User $User -KeyPath $KeyPath -RemotePath $RemotePath -Port $Port
        } else {
            & $deploy -Action deploy -Backup -GitHub none -Yes -HostName $HostName -User $User -KeyPath $KeyPath -RemotePath $RemotePath -Port $Port
        }
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

<#
  ---------------------------------------------------------------- 誤りを本番へ送らない

  **compose の中身が間違っていたら、送る前に止める**(2026-10-16、利用者の指示)。
  check.php は形(digest で固定しているか など)しか見ない。本番の docker が読めるか・その像が本当にあるかは
  ホストでしか分からないので、**置き場を汚さずに**確かめる:
    1. 手元: image: / FROM の行が `名前[:タグ]@sha256:64桁` の形か(打ち間違い・digest の欠け)
    2. ホスト: 手元の compose を一時フォルダへ写し、本番の .env で `docker compose config -q`(YAML の誤り・知らないキー)
    3. ホスト: 書いてある像が全部レジストリにあるか(`imagetools inspect 名前:タグ@sha256:…`。無い digest・版の打ち間違い)
  1 つでも駄目なら**何も送らずに止まり**、直してから `r` でやり直せる。
#>
function Test-ComposeOnHost {
    Write-Step 'compose と像を確かめる(本番には置かない)'
    $problems = @()
    $refs = @()
    $refPattern = '^[a-z0-9][a-z0-9./_-]*(:[A-Za-z0-9._-]+)?@sha256:[0-9a-f]{64}$'
    foreach ($file in $PinFiles) {
        $path = Join-Path $Root $file.Path
        if (-not (Test-Path $path)) { continue }
        $lines = [IO.File]::ReadAllLines($path)
        for ($i = 0; $i -lt $lines.Count; $i++) {
            $ref = $null
            if ($file.Kind -eq 'compose' -and $lines[$i] -match '^\s+image:\s*(\S+)\s*$') { $ref = $Matches[1].Trim('"', "'") }
            elseif ($file.Kind -eq 'build' -and $lines[$i] -match '^FROM\s+(\S+)') { $ref = $Matches[1] }
            elseif ($file.Kind -eq 'build' -and $lines[$i] -match '^COPY\s.*--from=(\S+)') {
                # `COPY --from=fork` のような段の名前は像ではない
                if ($Matches[1] -notmatch '[:/@]') { continue }
                $ref = $Matches[1]
            }
            if ($null -eq $ref) { continue }
            if ($ref -cnotmatch $refPattern) {
                $problems += "$($file.Path):$($i + 1)  形が違います(名前[:タグ]@sha256:64 桁): $ref"
            } else {
                $refs += $ref
            }
        }
    }
    if ($problems.Count -gt 0) {
        $problems | ForEach-Object { Write-Host "  ✗ $_" -ForegroundColor Red }
        return $false
    }
    $refs = @($refs | Select-Object -Unique)
    Write-Host "  手元の形: よい(像 $($refs.Count) 個)"
    if ($DryRun) { Write-Host '  (試し) ホストでの config と像の確かめは省きます' -ForegroundColor DarkGray; return $true }

    $composeParts = foreach ($name in 'compose.yaml', 'compose.vps.yaml') {
        $text = [IO.File]::ReadAllText((Join-Path $Root $name))
        if ($text.Contains('KM_COMPOSE_EOF')) { throw "$name に KM_COMPOSE_EOF という文字があるので送れません。" }
        if (-not $text.EndsWith("`n")) { $text += "`n" }
        "cat > `"`$t/$name`" <<'KM_COMPOSE_EOF'`n$($text)KM_COMPOSE_EOF"
    }
    $refLoop = ($refs | ForEach-Object { "'$_'" }) -join ' '
    $remoteScript = @"
t=`$(mktemp -d) || exit 1
trap 'rm -rf "`$t"' EXIT
$($composeParts -join "`n")
cd $RemotePath || exit 1
if docker compose --project-directory $RemotePath -f "`$t/compose.yaml" -f "`$t/compose.vps.yaml" config -q >"`$t/out" 2>&1; then
  echo KMCONFIG_OK
else
  echo KMCONFIG_NG
  head -n 20 "`$t/out" | sed 's/^/KMERR|/'
fi
for ref in $refLoop; do
  if docker buildx imagetools inspect "`$ref" >"`$t/ref" 2>&1; then
    echo "KMREF_OK|`$ref"
  else
    echo "KMREF_NG|`$ref|`$(head -n 1 "`$t/ref")"
  fi
done
"@
    Connect-Host
    $out = Invoke-WithRetry '確かめ' { Invoke-KmSshStdin -Script $remoteScript -TimeoutSec 600 }
    $ok = $true
    if ($out -contains 'KMCONFIG_OK') {
        Write-Host '  docker compose config: 読めました'
    } else {
        $ok = $false
        Write-Host '  ✗ docker compose config: 本番の docker が compose を読めません' -ForegroundColor Red
        $out | Where-Object { $_ -like 'KMERR|*' } | ForEach-Object { Write-Host "      $($_.Substring(6))" -ForegroundColor Red }
    }
    $missing = @($out | Where-Object { $_ -like 'KMREF_NG|*' })
    $found = @($out | Where-Object { $_ -like 'KMREF_OK|*' })
    foreach ($line in $missing) {
        $parts = $line.Split('|')
        Write-Host "  ✗ レジストリにありません: $($parts[1])" -ForegroundColor Red
        if ($parts.Count -gt 2 -and $parts[2]) { Write-Host "      $($parts[2])" -ForegroundColor Red }
    }
    if ($missing.Count -gt 0) { $ok = $false }
    if ($found.Count + $missing.Count -lt $refs.Count) {
        $ok = $false
        Write-Warn "  像の確かめが途中で終わりました($($found.Count + $missing.Count)/$($refs.Count))。"
    }
    if ($ok) { Write-Host "  像: $($found.Count) 個ともレジストリにあります" -ForegroundColor Green }
    return $ok
}

<#
  検査(check.php)と、本番での確かめ(Test-ComposeOnHost)を通す。
  **駄目なら何も送らずに止まる。** 直してから r で確かめ直す。やめるときは手元をこの回の前に戻す。
#>
function Confirm-ReadyToDeploy($Backup) {
    while ($true) {
        $ok = Invoke-Check
        if ($ok) { $ok = Test-ComposeOnHost }
        if ($ok) { return $true }
        Write-Host ''
        Write-Warn '  止めました。本番には何も送っていません。'
        $answer = Read-Host '  ファイルを直してから r = もう一度確かめる / Enter = やめる(手元をこの回の前に戻す)'
        if ($answer.Trim() -ne 'r') { Restore-Files $Backup; return $false }
    }
}

<#
  **入れ替えたあと、全部が起動したかを見届ける。** healthy を待ち、駄目なものがあれば止まる。
  見るもの: 再起動を繰り返している(restarting)/ unhealthy / 0 以外で終わった(exited)。
  starting の間は待つ(既定 180 秒)。駄目なサービスの名前を返す(空なら全部よい)。
#>
function Wait-Healthy([int]$TimeoutSec = 180) {
    Write-Step '起動を見届ける'
    if ($DryRun) { Write-Host '  (試し) 省きます' -ForegroundColor DarkGray; return @() }
    $probe = @"
cd $RemotePath || exit 1
for c in `$(docker compose ps -a -q 2>/dev/null); do
  docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}|{{.State.ExitCode}}' "`$c" 2>/dev/null
done
"@
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ($true) {
        $rows = @(Invoke-WithRetry '様子の取得' { Invoke-KmSshStdin -Script $probe -TimeoutSec 60 }) |
            Where-Object { $_ -match '^[^|]+\|[^|]*\|[^|]*\|-?\d+$' }
        $bad = @(); $waiting = @()
        foreach ($r in $rows) {
            $f = $r.Split('|')
            if ($f[1] -eq 'restarting' -or $f[2] -eq 'unhealthy' -or ($f[1] -eq 'exited' -and $f[3] -ne '0') -or $f[1] -eq 'dead') { $bad += $f[0] }
            elseif ($f[2] -eq 'starting' -or $f[1] -eq 'created') { $waiting += $f[0] }
        }
        if ($bad.Count -eq 0 -and $waiting.Count -eq 0) {
            Write-Host "  全部起動しました($($rows.Count) 個)。" -ForegroundColor Green
            return @()
        }
        if ((Get-Date) -ge $deadline) {
            $bad = @($bad + $waiting | Select-Object -Unique)
            Write-Host "  ✗ 起動しきらないもの: $($bad -join ' ')" -ForegroundColor Red
            foreach ($svc in $bad) { [void](Invoke-Host "docker compose logs --tail 30 $svc" -AllowFailure) }
            return $bad
        }
        $msg = @()
        if ($bad.Count) { $msg += "駄目: $($bad -join ' ')" }
        if ($waiting.Count) { $msg += "待ち: $($waiting -join ' ')" }
        Write-Host "  $($msg -join ' / ')(10 秒後にもう一度見ます)" -ForegroundColor DarkGray
        Start-Sleep -Seconds 10
    }
}

<#
  途中で駄目になったときの戻し方を選ばせる(**自動では戻さない**。戻すのも本番を変える操作なので)。
  b: 手元をこの回の前に戻す → 配備し直す(控えは取り直さない)→ 入れ替え後なら作り直して up -d → 見届ける
#>
function Invoke-Recover($Backup, [switch]$AfterUp, [string[]]$BuildServices = @()) {
    Write-Host ''
    if ($AfterUp) {
        Write-Warn '  止めました。新しい像で立て直しましたが、起動しないものがあります(上のログ)。'
    } else {
        Write-Warn '  止めました。本番の compose / Dockerfile は新しいものに置き換わっていますが、動いているコンテナは古いままです(up -d していません)。'
    }
    $answer = Read-Host '  b = 手元を元に戻して、本番も元に戻す / Enter = このまま止める(あとで直す)'
    if ($answer.Trim() -ne 'b') {
        Write-Warn '  このまま止めます。直してからメニューの 2 をもう一度選べば、本番と比べて続きから当てられます。'
        return
    }
    Restore-Files $Backup
    if (-not (Invoke-Deploy -Rollback)) { Write-Warn '  元に戻す配備に失敗しました。上の表示を見てください。'; return }
    if ($AfterUp) {
        if ($BuildServices.Count) { [void](Invoke-HostWithRetry "docker compose build $($BuildServices -join ' ')") }
        [void](Invoke-Host 'docker compose up -d' -AllowFailure)
        $still = Wait-Healthy
        if ($still.Count -eq 0) { Write-Host '  元に戻りました。' -ForegroundColor Green }
        else { Write-Warn "  戻しても起動しないもの: $($still -join ' ')。docs/06(もしものとき)を見てください。" }
    } else {
        Write-Host '  本番の compose / Dockerfile を元に戻しました(コンテナは触っていません)。' -ForegroundColor Green
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
    # 手元だけでなく**本番**とも比べる(手元を書き換えたまま止めた回のあとでも、未反映を見落とさない)
    $hostState = Get-HostState

    $rows = @()
    $n = 0
    foreach ($p in $pins) {
        $n++
        $new = "$($digests[$p.Ref])"
        $onHost = Get-HostPinState $p $hostState
        $state = if ($new -ne '' -and $new -ne $p.Digest) { 'new' }
            elseif ($onHost.State -eq 'no') { 'pending' }
            elseif ($new -eq '') { 'unknown' }
            else { 'same' }
        $rows += [pscustomobject]@{ No = $n; Pin = $p; NewDigest = $(if ($state -eq 'new') { $new } else { $p.Digest }); State = $state }
    }

    Write-Host ''
    foreach ($r in $rows) {
        $mark = switch ($r.State) {
            'new' { '★ 新しい中身あり' }
            'pending' { '☆ 手元は新しい・本番は古いまま' }
            'same' { '  変化なし(本番も同じ)' }
            default { '  聞けませんでした' }
        }
        $color = switch ($r.State) { 'new' { 'Yellow' } 'pending' { 'Yellow' } 'same' { 'Gray' } default { 'DarkYellow' } }
        Write-Host ("  [{0,2}] {1,-26} {2,-40} {3}" -f $r.No, $r.Pin.Name, $r.Pin.Ref, $mark) -ForegroundColor $color
    }
    if ($null -eq $hostState) {
        Write-Host '  (試しなので、本番に入っているかは見ていません)' -ForegroundColor DarkGray
    }
    if ($untagged.Count -gt 0) {
        Write-Host ''
        Write-Host "  タグの無い固定(ここでは上げません): $(($untagged | ForEach-Object { ($_.Ref) + '(' + $_.File + ')' }) -join ' / ')" -ForegroundColor DarkGray
    }

    $candidates = @($rows | Where-Object { $_.State -in 'new', 'pending' })
    if ($candidates.Count -eq 0) { Write-Host ''; Write-Host '  当てるものはありません。' -ForegroundColor Green; return }

    Write-Host ''
    $answer = Read-Host "  当てる番号をカンマで(Enter = ★☆ の全部 $(($candidates | ForEach-Object { $_.No }) -join ','), q = やめる)"
    if ($answer.Trim() -eq 'q') { return }
    $chosen = @(if ($answer.Trim() -eq '') { $candidates } else {
        $nums = $answer -split '[,\s]+' | Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int]$_ }
        $rows | Where-Object { $_.No -in $nums -and $_.State -in 'new', 'pending' }
    })
    if ($chosen.Count -eq 0) { Write-Warn '  選んだ番号に、当てられるもの(★☆)がありません。'; return }

    if (@($chosen | Where-Object { $_.Pin.Name -match 'DB' }).Count -gt 0) {
        Write-Warn '  DB(MariaDB / PostgreSQL)を含みます。壊れたら控えからの復元になります(docs/03 §8)。'
    }

    # ☆(手元はもう新しい)は書き換えない。配備と入れ替えだけ行う
    $changes = @($chosen | Where-Object { $_.State -eq 'new' } |
        ForEach-Object { [pscustomobject]@{ Pin = $_.Pin; NewRef = $_.Pin.Ref; NewDigest = $_.NewDigest } })
    Write-Step '手元のファイルを書き換える'
    if ($changes.Count -gt 0) { Show-Changes $changes } else { Write-Host '  書き換えるものはありません(手元はもう新しい)。' }
    $backup = Set-PinLines $changes

    if (-not (Confirm-ReadyToDeploy $backup)) { return }

    $pullServices = @($chosen | Where-Object { $_.Pin.Kind -eq 'image' } | ForEach-Object { $_.Pin.Service } | Select-Object -Unique)
    $buildServices = @($chosen | Where-Object { $_.Pin.Kind -eq 'build' } | ForEach-Object { $_.Pin.Service } | Select-Object -Unique)
    $what = @()
    if ($pullServices.Count) { $what += "取り直す: $($pullServices -join ' ')" }
    if ($buildServices.Count) { $what += "作り直す: $($buildServices -join ' ')" }

    if (-not (Confirm-Yes "本番で控えを取ってから配備し、入れ替えます($($what -join ' / '))。数十秒止まるサービスがあります")) {
        $keep = Read-Host '  やめました。手元の書き換えを元に戻しますか? (Enter = 戻す / n = 残す)'
        if ($keep.Trim() -ne 'n') { Restore-Files $backup }
        return
    }
    if (-not (Invoke-Deploy)) {
        Write-Warn '  本番は入れ替えていません。'
        $keep = Read-Host '  手元の書き換えを元に戻しますか? (Enter = 戻す / n = 残す)'
        if ($keep.Trim() -ne 'n') { Restore-Files $backup }
        return
    }

    Write-Step 'ホストで入れ替える'
    # 取る・作るは一時的に失敗しうるので自動でやり直す。最後まで駄目なら up -d せずに止める
    if ($pullServices.Count -and -not (Invoke-HostWithRetry "docker compose pull $($pullServices -join ' ')")) {
        Invoke-Recover $backup; return
    }
    if ($buildServices.Count -and -not (Invoke-HostWithRetry "docker compose build --pull $($buildServices -join ' ')")) {
        Invoke-Recover $backup; return
    }
    # **1 つだけの up -d にしない**(depends_on が評価されない)
    [void](Invoke-Host 'docker compose up -d' -AllowFailure)
    $bad = Wait-Healthy
    if ($bad.Count -gt 0) { Invoke-Recover $backup -AfterUp -BuildServices $buildServices; return }
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
    $localText = ($pin.Ref -split ':')[-1]
    <#
      **いまの版は本番で動いているものから読む**(2026-10-16、利用者の指摘)。
      手元の compose.yaml は、途中で止めた回のあとでは先に進んでいることがある(手元 1.45.0・本番 1.44.0)。
      それを「いまの版」にすると、本番が古いのに「最新です」と出る。
    #>
    $hostState = Get-HostState
    $runningImage = if ($null -ne $hostState) { "$($hostState.Running['logto'])" } else { '' }
    if ($runningImage -ne '') {
        $currentText = (($runningImage -replace '@.*$', '') -split ':')[-1]
        Write-Host "  本番で動いている版: $currentText"
        Write-Host "  手元の compose.yaml: $localText"
        if ($currentText -ne $localText) {
            Write-Warn '  手元と本番が違います(前回、手元を書き換えたあと入れ替えまで行かずに止まったようです)。本番の版から数えます。'
        }
    } else {
        $currentText = $localText
        if ($null -ne $hostState) { Write-Warn '  本番で Logto が動いていません。手元の compose.yaml の版から数えます。' }
        Write-Host "  いまの版(手元の compose.yaml): $currentText"
    }
    $current = ConvertTo-Version $currentText
    if (-not $current) { Write-Warn "  版の番号を読めません: $currentText"; return }

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
    if (-not (Confirm-ReadyToDeploy $backup)) { return }

    Write-Host ''
    Write-Warn '  確かめてください: 会期中(イベントの最中)ではない / リリースノートを読んだ / サインインを試せる人が居る'
    <#
      やめたとき・配備できなかったときは、**手元を本番に合わせて戻す**(既定)。
      「書き換える前」に戻すだけだと、前回の書きかけ(手元だけ新しい)が残ることがある。
    #>
    # 戻したら $true(n で残したら $false)
    $resetToHost = {
        if ((Read-Host '  手元の compose.yaml を本番と同じ版に戻しますか? (Enter = 戻す / n = 残す)').Trim() -eq 'n') {
            Write-Warn '  手元は書き換えたままです。次に配備すると compose.yaml の新しい版が置かれます。'
            return $false
        }
        if ($runningImage -match '^(.+)@(sha256:[0-9a-f]{64})$') {
            # **$Matches は先に取っておく。** Get-Pins の中の -match で上書きされる
            $hostRef = $Matches[1]; $hostDigest = $Matches[2]
            $fresh = @(Get-Pins | Where-Object { $_.IsLogto -and $_.File -eq 'compose.yaml' }) | Select-Object -First 1
            [void](Set-PinLines @([pscustomobject]@{ Pin = $fresh; NewRef = $hostRef; NewDigest = $hostDigest }))
            Write-Host "  手元の compose.yaml を本番と同じ $currentText に戻しました。"
        } else {
            Restore-Files $backup
        }
        return $true
    }
    <#
      配備したあと(移行の前)に止めたとき: 手元を戻し、**本番の compose も戻す**。
      戻さないと、本番は「compose は新しい版・動いているのは古い版」のまま残り、次の up -d で
      移行を当てないまま新しい像が起動する。
    #>
    $rollbackLogto = {
        Write-Warn '  動いている Logto は古い版のままです(移行も立て直しもしていません)。'
        if (& $resetToHost) {
            if (Invoke-Deploy -Rollback) { Write-Host "  本番の compose.yaml も $currentText に戻しました。" -ForegroundColor Green }
            else { Write-Warn '  本番へ戻す配備に失敗しました。直してから .\deploy-to-host.ps1 で配備し直してください(up -d はしないこと)。' }
        } else {
            Write-Warn '  本番の compose.yaml は新しい版のままです。**次に up -d すると移行を当てないまま新しい像が起動します。**'
        }
    }

    if (-not (Confirm-Yes "本番で控えを取ってから compose を置き、Logto $currentText → $newText の新しい像を取ります(まだ入れ替えません)")) {
        Write-Host '  やめました。'
        [void](& $resetToHost)
        return
    }
    if (-not (Invoke-Deploy)) {
        Write-Warn '  本番の Logto はそのままです。'
        [void](& $resetToHost)
        return
    }

    # 取る・一覧を見るは何度やっても同じなので、失敗したら自動でやり直す。最後まで駄目なら止めて戻す
    Write-Step '新しい像を取る'
    if (-not (Invoke-HostWithRetry 'docker compose pull logto')) { & $rollbackLogto; return }

    Write-Step '移行の一覧(使い捨てのコンテナ。DB は書き換えない)'
    if (-not (Invoke-HostWithRetry "docker compose run --rm --entrypoint sh logto -c 'cd /etc/logto && npm run alteration list $newText'")) {
        & $rollbackLogto; return
    }

    if (-not (Confirm-Yes "Logto の DB 移行を当てます($newText)。**戻すのは難しい**(控えは取りました)")) {
        Write-Warn '  移行はしていません。'
        & $rollbackLogto
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
    [void](Invoke-Host 'docker compose up -d' -AllowFailure)
    # Logto は起動のときに移行を確かめるので遅い。長めに待つ
    $bad = Wait-Healthy -TimeoutSec 300
    [void](Invoke-Host 'docker compose logs --tail 60 logto' -AllowFailure)
    if ($bad.Count -gt 0) {
        # **移行は当たっているので、自動では戻さない**(古い像を DB の新しい形で起動すると壊れうる)
        Write-Host ''
        Write-Warn "  止めました。起動しないもの: $($bad -join ' ')"
        Write-Warn '  移行は当たっています。版を戻すだけでは直りません。上のログを見てから、'
        Write-Warn '  docs/05 §6(巻き戻し)か、この回の始めに取った控えからの復元(docs/03 §8)を選んでください。'
        return
    }
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
