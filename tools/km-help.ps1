<#
.SYNOPSIS
  スクリプトのオプションとその意味を出す(各スクリプトの -Help から呼ばれる)。

.DESCRIPTION
  2026-10-16、利用者の指示「全ての PS1 に -Help を付けて、オプションとその意味を表示できるように」。

  説明は**スクリプト自身から読む**(ここに二重に書かない。書き足すのは各スクリプトの先頭のコメント):
    - 概要          … 先頭のコメントの .SYNOPSIS(無ければ先頭のコメントの最初の段落)
    - オプションの意味 … .PARAMETER。無ければ param() の中で、そのオプションの直前(または同じ行の後ろ)に書いたコメント
    - 型・既定値・選べる値(ValidateSet)・必須 … param() の書き方
    - 例            … .EXAMPLE

  **同じ中身で 4 か所に置く**(server/scripts・Android の scripts・GitHub の控え 2 つ)。
  呼ぶ側は同じフォルダの km-help.ps1 を探す。check.php が食い違いを見張る。

.PARAMETER Path
  説明を出すスクリプト(.ps1)。省くとこのファイル自身の説明。

.EXAMPLE
  .\km-help.ps1 -Path .\deploy-to-host.ps1
#>
[CmdletBinding()]
param(
    [string]$Path = '',
    # オプションとその意味を出して終わる
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
if ($Path -eq '' -or $Help) { $Path = $PSCommandPath }
$Path = (Resolve-Path -LiteralPath $Path).Path
$name = Split-Path -Leaf $Path

$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$parseErrors)
$info = $ast.GetHelpContent()

# 説明文を整える: 太字の印を外し、前後の空行と共通の字下げを落とす
function Format-Lines([string]$Text) {
    $lines = @($Text -split "`r?`n" | ForEach-Object { ($_ -replace '\*\*', '').TrimEnd() })
    while ($lines.Count -gt 0 -and $lines[0].Trim() -eq '') { $lines = @($lines | Select-Object -Skip 1) }
    while ($lines.Count -gt 0 -and $lines[-1].Trim() -eq '') { $lines = @($lines | Select-Object -SkipLast 1) }
    $indents = @($lines | Where-Object { $_.Trim() -ne '' } | ForEach-Object { $_.Length - $_.TrimStart().Length })
    $cut = if ($indents.Count) { ($indents | Measure-Object -Minimum).Minimum } else { 0 }
    return @($lines | ForEach-Object { if ($_.Length -ge $cut) { $_.Substring($cut) } else { $_.TrimStart() } })
}

# 最初の段落だけ(空行まで)
function Get-FirstParagraph([string]$Text) {
    $out = @()
    foreach ($line in (Format-Lines $Text)) {
        if ($line.Trim() -eq '') { break }
        $out += $line
    }
    return $out
}

# コメントの記号を外す(# … / <# … #>)
function Get-CommentText($Token) {
    $t = $Token.Text
    if ($t.StartsWith('<#')) { return ($t -replace '^<#', '' -replace '#>$', '') }
    return ($t -replace '^#\s?', '')
}

# ---------------------------------------------------------------- 概要
$synopsis = @()
if ($info -and "$($info.Synopsis)".Trim() -ne '') {
    $synopsis = Get-FirstParagraph $info.Synopsis
} else {
    # 先頭のコメント(param や最初の命令より前)の最初の段落
    $firstCode = if ($ast.ParamBlock) { $ast.ParamBlock.Extent.StartOffset } elseif ($ast.EndBlock.Statements.Count) { $ast.EndBlock.Statements[0].Extent.StartOffset } else { [int]::MaxValue }
    $lead = @($tokens | Where-Object { $_.Kind -eq 'Comment' -and $_.Extent.StartOffset -lt $firstCode -and $_.Text -notmatch '^#Requires' })
    if ($lead.Count) { $synopsis = Get-FirstParagraph (($lead | ForEach-Object { Get-CommentText $_ }) -join "`n") }
}

Write-Host ''
Write-Host $name -ForegroundColor Cyan
foreach ($line in $synopsis) { Write-Host "  $line" }

# ---------------------------------------------------------------- オプション
$params = if ($ast.ParamBlock) { @($ast.ParamBlock.Parameters) } else { @() }

Write-Host ''
Write-Host '使い方:' -ForegroundColor Cyan
Write-Host "  .\$name$(if ($params.Count) { ' [オプション]' })"

Write-Host ''
Write-Host 'オプション:' -ForegroundColor Cyan
if ($params.Count -eq 0) {
    Write-Host '  ありません(-Help だけ)。'
    if (-not $ast.ParamBlock) { Write-Host '  ほかのスクリプトから読み込む部品です(. で読み込む)。' -ForegroundColor DarkGray }
}

# param() の中のコメントを、どのオプションのものかに割り振る
$comments = @($tokens | Where-Object { $_.Kind -eq 'Comment' })
$described = @{}
$trailing = @{}
$prevEnd = if ($ast.ParamBlock) { $ast.ParamBlock.Extent.StartOffset } else { 0 }
$prevLine = -1
$prevName = ''
foreach ($p in $params) {
    $pname = $p.Name.VariablePath.UserPath
    $before = @($comments | Where-Object { $_.Extent.StartOffset -ge $prevEnd -and $_.Extent.EndOffset -le $p.Extent.StartOffset })
    $own = @()
    foreach ($c in $before) {
        # 前のオプションと同じ行の後ろに書いたコメントは、前のオプションの説明
        if ($prevName -ne '' -and $c.Extent.StartLineNumber -eq $prevLine) { $trailing[$prevName] = Get-CommentText $c }
        else { $own += Get-CommentText $c }
    }
    if ($own.Count) { $described[$pname] = ($own -join "`n") }
    $prevEnd = $p.Extent.EndOffset
    $prevLine = $p.Extent.EndLineNumber
    $prevName = $pname
}
# 最後のオプションの同じ行の後ろ
if ($prevName -ne '') {
    $last = @($comments | Where-Object { $_.Extent.StartOffset -ge $prevEnd -and $_.Extent.StartLineNumber -eq $prevLine }) | Select-Object -First 1
    if ($last) { $trailing[$prevName] = Get-CommentText $last }
}

foreach ($p in $params) {
    $pname = $p.Name.VariablePath.UserPath
    $type = ''
    $set = @()
    $mandatory = $false
    $aliases = @()
    foreach ($a in $p.Attributes) {
        if ($a -is [System.Management.Automation.Language.TypeConstraintAst]) { $type = $a.TypeName.Name; continue }
        $an = $a.TypeName.Name
        if ($an -eq 'ValidateSet') { $set = @($a.PositionalArguments | ForEach-Object { $_.SafeGetValue() }) }
        if ($an -eq 'Alias') { $aliases = @($a.PositionalArguments | ForEach-Object { $_.SafeGetValue() }) }
        if ($an -eq 'Parameter') {
            foreach ($na in $a.NamedArguments) {
                if ($na.ArgumentName -eq 'Mandatory' -and ($na.ExpressionOmitted -or $na.Argument.Extent.Text -match '\$true')) { $mandatory = $true }
            }
        }
    }
    $isSwitch = $type -eq 'switch'
    $head = "  -$pname"
    if (-not $isSwitch) {
        if ($set.Count) { $head += " <$($set -join '|')>" }
        elseif ($type) { $head += " <$type>" }
        else { $head += ' <値>' }
    }
    $notes = @()
    if ($mandatory) { $notes += '必須' }
    if ($isSwitch) { $notes += '付けるだけ' }
    if ($p.DefaultValue -and -not $isSwitch) {
        $dv = $p.DefaultValue.Extent.Text
        if ($dv -notin "''", '""', '@()', '0', '$null') { $notes += "既定: $dv" }
    }
    if ($aliases.Count) { $notes += "別名: $(($aliases | ForEach-Object { "-$_" }) -join ' ')" }
    Write-Host ("{0,-34} {1}" -f $head, $(if ($notes.Count) { "($($notes -join '・'))" } else { '' })) -ForegroundColor White

    $text = ''
    if ($info -and $info.Parameters.ContainsKey($pname.ToUpperInvariant())) { $text = $info.Parameters[$pname.ToUpperInvariant()] }
    elseif ($described.ContainsKey($pname)) { $text = $described[$pname] }
    elseif ($trailing.ContainsKey($pname)) { $text = $trailing[$pname] }
    $lines = if ($text) { Get-FirstParagraph $text } else { @() }
    if ($lines.Count -eq 0) { $lines = @('(説明はありません)') }
    foreach ($line in $lines) { Write-Host "      $line" }
}

# ---------------------------------------------------------------- 例
if ($info -and $info.Examples.Count) {
    Write-Host ''
    Write-Host '例:' -ForegroundColor Cyan
    foreach ($ex in $info.Examples) {
        foreach ($line in (Format-Lines $ex)) {
            if ($line.Trim() -ne '') { Write-Host "  $line" }
        }
        Write-Host ''
    }
}
