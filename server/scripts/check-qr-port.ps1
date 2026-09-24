<#
.SYNOPSIS
PHP の QR 実装(src/lib/qr.php)が、PowerShell 版(new-map-qr.ps1)と一致するか確かめる。

.DESCRIPTION
配信を Website 一括にしたとき、QR の符号化を PowerShell から PHP へ移植した。

**QR は「読めるかどうか」でしか正しさを確かめられない。** 目で見ても分からず、
1ビット違うだけで読めなくなる。そこで、**既に動いている実装と同じ並びになること**を
機械で突き合わせる。

型・誤り訂正レベル・マスクをひととおり回す。特に:

  - **型7以上**は型情報の BCH(18,6) が入る。ここは切り捨てと四捨五入を
    取り違えると型情報だけ別の行へ書かれ、型7以上が丸ごと読めなくなる
  - **マスク0〜7**をすべて固定して比べる。自動選択に任せると、
    たまたま同じマスクが選ばれただけで通ってしまう

.EXAMPLE
pwsh -File scripts\check-qr-port.ps1
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$qrScript = Join-Path $PSScriptRoot 'new-map-qr.ps1'
$dumper = Join-Path $env:TEMP 'km-qr-dump.php'

@"
<?php
declare(strict_types=1);
require '$($root -replace '\\', '/')/src/lib/qr.php';
foreach (km_qr_matrix((string) `$argv[1], (string) `$argv[2], (int) `$argv[3]) as `$row) {
    echo implode('', array_map(static fn (`$b) => `$b ? '1' : '0', `$row)), PHP_EOL;
}
"@ | Set-Content -Path $dumper -Encoding UTF8

function Get-Matrix {
    param([string[]]$Lines)
    return (($Lines | Where-Object { $_ -match '^[01]+$' }) -join "`n")
}

$cases = @()
foreach ($ec in 'L', 'M', 'Q', 'H') {
    $cases += @{ Code = 'KOSEN2026'; Ec = $ec; Mask = -1 }
}
# 型が上がる長さ。'A' * 55 に接頭辞を足して 65 バイト
$cases += @{ Code = ('A' * 55); Ec = 'H'; Mask = -1 }
$cases += @{ Code = ('A' * 55); Ec = 'Q'; Mask = -1 }
$cases += @{ Code = ('A' * 120); Ec = 'L'; Mask = -1 }
foreach ($mask in 0..7) {
    $cases += @{ Code = 'KOSEN2026'; Ec = 'M'; Mask = $mask }
}

$ok = 0
$ng = 0
foreach ($case in $cases) {
    $psArgs = @{ Code = $case.Code; ErrorCorrection = $case.Ec; MatrixOnly = $true }
    if ($case.Mask -ge 0) { $psArgs['Mask'] = $case.Mask }
    $fromPs = Get-Matrix (& $qrScript @psArgs)
    $fromPhp = Get-Matrix (php $dumper ('KOSENMAP1:' + $case.Code) $case.Ec $case.Mask)

    $label = "{0} / {1} / mask={2}" -f $case.Code.Substring(0, [math]::Min(12, $case.Code.Length)), $case.Ec, $case.Mask
    if ($fromPs.Length -eq 0) {
        $ng++
        Write-Host "NG   $label  (PowerShell 側が空)" -ForegroundColor Red
    } elseif ($fromPs -eq $fromPhp) {
        $ok++
        $side = ($fromPs -split "`n").Count
        Write-Host ("OK   {0}  ({1}x{1})" -f $label, $side) -ForegroundColor DarkGray
    } else {
        $ng++
        Write-Host "NG   $label" -ForegroundColor Red
    }
}

Remove-Item $dumper -ErrorAction SilentlyContinue

Write-Host ""
if ($ng -eq 0) {
    Write-Host "一致 $ok 件。PHP の移植は PowerShell 版と同じ並びを出しています。" -ForegroundColor Green
    exit 0
}

Write-Host "不一致 $ng 件。src/lib/qr.php を直してください。" -ForegroundColor Red
exit 1
