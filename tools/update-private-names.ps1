<#
.SYNOPSIS
push 前の検査に使う「出してはいけない氏名」の一覧を、リポジトリの外に作る。

.DESCRIPTION
**Android と Website の両方の控えで、中身がまったく同じファイル**(2026-09-25)。
  Android: F:\Pull\Ichinoseki_Kosen\scripts\update-private-names.ps1
  Website: F:\Pull\Ichinoseki_Kosen_Web\tools\update-private-names.ps1

教職員の氏名は、Website では地図のパスワードで、アプリでは役割で隠している情報。
それが公開リポジトリのテストやコメントに紛れ込んだ(診断 2026-09-25 の W-53)。
push-github.ps1 がこの一覧と照合して止める。

**一覧はリポジトリに入れない。** 置き場は既定で `~\.kosenmap\private-names.txt`(1 行 1 名)。
画面には件数しか出さない(氏名をログに残さない)。

読むもの:
  - DB のダンプ(既定 C:\Users\itota\Documents\Test\php\Kosen_map.sql)の km_map_nodes.occupant_name
  - -Extra で渡したファイル(1 行 1 名。手で足したい名前)

  pwsh -File tools\update-private-names.ps1

.PARAMETER Dump
地点と氏名の入った DB のダンプ。

.PARAMETER Extra
手で足す名前の一覧(1 行 1 名)。無ければ読まない。

.PARAMETER Output
書き出す先。push-github.ps1 も同じ場所を読む(環境変数 KM_PRIVATE_NAMES_FILE で変えられる)。
#>
[CmdletBinding()]
param(
    [string]$Dump = 'C:\Users\itota\Documents\Test\php\Kosen_map.sql',
    [string]$Extra = (Join-Path $HOME '.kosenmap\private-names.extra.txt'),
    [string]$Output = $(if ($env:KM_PRIVATE_NAMES_FILE) { $env:KM_PRIVATE_NAMES_FILE } else { Join-Path $HOME '.kosenmap\private-names.txt' })
)

$ErrorActionPreference = 'Stop'

$names = New-Object System.Collections.Generic.HashSet[string]

function Add-Name([string]$raw) {
    # 1 つの欄に 2 人入っていることがある(改行・読点で区切る)。ダンプでは改行が \n と書かれている
    $unescaped = $raw -replace '\\n', "`n" -replace '\\(.)', '$1'
    $parts = @($unescaped -split '[\r\n、,・/／]+' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    if ($parts.Count -gt 1) {
        $parts | ForEach-Object { Add-Name $_ }
        return
    }
    # 敬称は外す(「○○ 先生」と「○○」の両方に当たるように)
    $value = ($unescaped -replace '[\s　]*(先生|様|さん|教授|准教授|助教|講師|教諭)$', '').Trim()
    # 1 文字の名前は照合すると当たりすぎる。記号だけのものも捨てる
    if ($value.Length -lt 2 -or $value -notmatch '\p{L}') { return }
    [void]$names.Add($value)
    # 「姓 名」と「姓名」の両方で照合する(空白の有無で書き方が揺れる)
    $compact = $value -replace '[\s\u3000]+', ''
    if ($compact.Length -ge 2) { [void]$names.Add($compact) }
}

if (Test-Path -LiteralPath $Dump) {
    $sql = [IO.File]::ReadAllText($Dump, [Text.Encoding]::UTF8)
    $insert = [regex]::Match($sql, 'INSERT INTO `km_map_nodes` \(([^)]*)\) VALUES(.*?);\s*$', 'Singleline, Multiline')
    if ($insert.Success) {
        $columns = @($insert.Groups[1].Value -split ',' | ForEach-Object { $_.Trim().Trim('`') })
        $index = [array]::IndexOf($columns, 'occupant_name')
        if ($index -lt 0) { throw "ダンプの km_map_nodes に occupant_name の列がありません: $Dump" }
        # 1 行 = ( 値, 値, … )。値は '…'(\' で逃がす)か NULL か数値
        $valuePattern = "'((?:[^'\\]|\\.)*)'|NULL|-?[0-9.]+"
        foreach ($row in [regex]::Matches($insert.Groups[2].Value, "\((?:\s*(?:$valuePattern)\s*,?)+\)")) {
            $values = @([regex]::Matches($row.Value, $valuePattern))
            if ($values.Count -gt $index -and $values[$index].Value -ne 'NULL') {
                Add-Name $values[$index].Groups[1].Value
            }
        }
    } else {
        Write-Host "ダンプに km_map_nodes の INSERT がありません(表定義だけの版かもしれません): $Dump" -ForegroundColor Yellow
    }
} else {
    Write-Host "ダンプが見つかりません: $Dump" -ForegroundColor Yellow
}

if (Test-Path -LiteralPath $Extra) {
    Get-Content -LiteralPath $Extra -Encoding UTF8 | Where-Object { $_.Trim() -ne '' -and -not $_.StartsWith('#') } | ForEach-Object { Add-Name $_ }
}

if ($names.Count -eq 0) {
    throw '氏名を 1 件も読めませんでした。一覧は書き換えていません。'
}

New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Output) | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)
[IO.File]::WriteAllLines($Output, [string[]]($names | Sort-Object), $utf8)
Write-Host "氏名の一覧を書きました: $Output($($names.Count) 件。中身は表示しません)" -ForegroundColor Green
