<#
.SYNOPSIS
  この PC の JDK の指し先(JAVA_HOME・PATH・Android の gradle.properties)を、入っている新しい JDK 21 に合わせる(2026-11-06)。

.DESCRIPTION
  ## なぜ要るのか

  Eclipse Adoptium の JDK は更新のたびに**フォルダの名前が変わる**(jdk-21.0.12.101-hotspot → jdk-21.0.13.9-hotspot)。
  インストーラーは全体(マシン)の JAVA_HOME と PATH は書き換えるが、次のものは古いまま残る:

    - ユーザーの JAVA_HOME(全体の値より優先されるので、こちらが古いと全体を直しても効かない)
    - 全体の PATH に残った古い jdk の bin
    - Android(Test)の gradle.properties の org.gradle.java.home
      (古いと Gradle が「Java home supplied is invalid」で落ちる。2026-08-11 と 11-05 に起きた)

  apk-push.ps1 の apksigner は JAVA_HOME の java で動くので、ここが古いと署名の確かめで止まる。

  ## 直し方

  - ユーザーの JAVA_HOME: 全体の JAVA_HOME が正しければ**ユーザーの方を消す**(次の更新でもインストーラーの直した値が効く)。
    全体の方も古ければ、ユーザーの方を新しい JDK に向ける
  - 全体の PATH の、もう無い jdk の bin を外す(管理者のときだけ。そうでなければ手順を出す)
  - gradle.properties を新しい JDK に書き換える

  既定は**調べて出すだけ**。直すには -Fix。開いている端末と Android Studio は、直したあと開き直すと新しい値を読む。

.EXAMPLE
  .\java-home.ps1          # 調べるだけ

.EXAMPLE
  .\java-home.ps1 -Fix     # 直す(全体の PATH は管理者の PowerShell で流したときだけ)
#>
[CmdletBinding()]
param(
    # 直す(既定は調べるだけ)
    [switch]$Fix,
    # Android のプロジェクト(gradle.properties のある所)
    [string]$AndroidRoot = 'C:\Users\itota\Documents\Test',
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

function Test-Jdk([string]$Path) {
    return $Path -and (Test-Path (Join-Path $Path.TrimEnd('\') 'bin\java.exe'))
}

# ---- 入っている JDK 21 のうち、いちばん新しいもの ----
$roots = @('C:\Program Files\Eclipse Adoptium', 'C:\Program Files\Microsoft', 'C:\Program Files\Java')
$jdks = foreach ($root in $roots) {
    if (Test-Path $root) {
        Get-ChildItem $root -Directory -Filter 'jdk-21*' | Where-Object { Test-Jdk $_.FullName } | ForEach-Object {
            $digits = [regex]::Matches($_.Name, '\d+') | ForEach-Object { [int]$_.Value }
            [pscustomobject]@{ Path = $_.FullName; Key = ($digits | ForEach-Object { '{0:D6}' -f $_ }) -join '.' }
        }
    }
}
$newest = $jdks | Sort-Object Key -Descending | Select-Object -First 1
if (-not $newest) {
    Write-Host 'JDK 21 が見つかりません(Eclipse Adoptium・Microsoft・Java の下)。先に入れてください。' -ForegroundColor Red
    exit 1
}
$jdk = $newest.Path
Write-Host "新しい JDK 21: $jdk"

$problems = 0
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

# ---- JAVA_HOME ----
$userHome = [Environment]::GetEnvironmentVariable('JAVA_HOME', 'User')
$machineHome = [Environment]::GetEnvironmentVariable('JAVA_HOME', 'Machine')
Write-Host ''
Write-Host '== JAVA_HOME =='
Write-Host "  全体(マシン): $(if ($machineHome) { $machineHome } else { '(なし)' })$(if ($machineHome -and -not (Test-Jdk $machineHome)) { '  ← もう無い' })"
Write-Host "  ユーザー:     $(if ($userHome) { $userHome } else { '(なし)' })$(if ($userHome -and -not (Test-Jdk $userHome)) { '  ← もう無い' })"
$machineOk = Test-Jdk $machineHome
if ($userHome -and (-not (Test-Jdk $userHome) -or ($machineOk -and $userHome.TrimEnd('\') -ne $machineHome.TrimEnd('\')))) {
    $problems++
    if ($machineOk) {
        Write-Host '  ★ ユーザーの JAVA_HOME が全体の値を上書きしています。ユーザーの方を消せば全体の値が効きます。' -ForegroundColor Yellow
        if ($Fix) {
            [Environment]::SetEnvironmentVariable('JAVA_HOME', $null, 'User')
            Write-Host '  ユーザーの JAVA_HOME を消しました。' -ForegroundColor Green
        }
    } else {
        Write-Host "  ★ ユーザーの JAVA_HOME を $jdk に向けます。" -ForegroundColor Yellow
        if ($Fix) {
            [Environment]::SetEnvironmentVariable('JAVA_HOME', $jdk, 'User')
            Write-Host '  ユーザーの JAVA_HOME を書き換えました。' -ForegroundColor Green
        }
    }
} elseif (-not $userHome -and -not $machineOk) {
    $problems++
    Write-Host "  ★ JAVA_HOME がどこにもありません。ユーザーの JAVA_HOME を $jdk にします。" -ForegroundColor Yellow
    if ($Fix) {
        [Environment]::SetEnvironmentVariable('JAVA_HOME', $jdk, 'User')
        Write-Host '  ユーザーの JAVA_HOME を作りました。' -ForegroundColor Green
    }
} else {
    Write-Host '  正しく効いています。'
}

# ---- PATH の古い jdk ----
Write-Host ''
Write-Host '== PATH の中の、もう無い jdk =='
foreach ($scope in 'User', 'Machine') {
    $raw = [Environment]::GetEnvironmentVariable('Path', $scope)
    if (-not $raw) { continue }
    $parts = $raw -split ';'
    $stale = @($parts | Where-Object { $_ -match '\\(jdk|jre)[^\\]*\\bin\\?$' -and -not (Test-Path $_) })
    if ($stale.Count -eq 0) {
        Write-Host "  $(if ($scope -eq 'User') { 'ユーザー' } else { '全体' }): なし"
        continue
    }
    $problems++
    $stale | ForEach-Object { Write-Host "  ★ $(if ($scope -eq 'User') { 'ユーザー' } else { '全体' }): $_" -ForegroundColor Yellow }
    if (-not $Fix) { continue }
    if ($scope -eq 'Machine' -and -not $isAdmin) {
        Write-Host '  全体の PATH は管理者でないと書けません。管理者の PowerShell で -Fix を流し直してください。' -ForegroundColor Yellow
        continue
    }
    $kept = @($parts | Where-Object { $_ -ne '' -and $stale -notcontains $_ })
    [Environment]::SetEnvironmentVariable('Path', ($kept -join ';'), $scope)
    Write-Host "  外しました($($stale.Count) 件)。" -ForegroundColor Green
}

# ---- gradle.properties ----
Write-Host ''
Write-Host '== Android の gradle.properties =='
$props = Join-Path $AndroidRoot 'gradle.properties'
if (-not (Test-Path $props)) {
    Write-Host "  見つかりません: $props"
} else {
    $text = [IO.File]::ReadAllText($props)
    $m = [regex]::Match($text, '(?m)^org\.gradle\.java\.home=(.*)$')
    if (-not $m.Success) {
        Write-Host '  org.gradle.java.home はありません(JAVA_HOME を使います)。'
    } else {
        $current = $m.Groups[1].Value.Trim() -replace '\\:', ':' -replace '\\\\', '\'
        if ($current.TrimEnd('\') -eq $jdk.TrimEnd('\')) {
            Write-Host "  正しい: $current"
        } else {
            $problems++
            Write-Host "  ★ $current$(if (-not (Test-Jdk $current)) { '(もう無い)' }) → $jdk" -ForegroundColor Yellow
            if ($Fix) {
                $escaped = ($jdk -replace '\\', '\\') -replace ':', '\:'
                $note = "# $(Get-Date -Format 'yyyy-MM-dd'): java-home.ps1 が $(Split-Path $current -Leaf) を $(Split-Path $jdk -Leaf) に更新。"
                $nl = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
                $cr = if ($m.Value.EndsWith("`r")) { "`r" } else { '' }
                $text = $text.Remove($m.Index, $m.Length).Insert($m.Index, "$note${nl}org.gradle.java.home=$escaped$cr")
                [IO.File]::WriteAllText($props, $text, [Text.UTF8Encoding]::new($false))
                Write-Host '  書き換えました。' -ForegroundColor Green
            }
        }
    }
}

Write-Host ''
if ($problems -eq 0) {
    Write-Host 'すべて合っています。'
} elseif ($Fix) {
    Write-Host '直しました。開いている端末・Android Studio は開き直すと新しい値を読みます。'
} else {
    Write-Host "上の ★ が $problems か所あります。直すには -Fix を付けてください(全体の PATH は管理者の PowerShell で)。"
}
