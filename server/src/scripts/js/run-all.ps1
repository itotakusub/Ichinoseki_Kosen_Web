<#
.SYNOPSIS
公開ページの JavaScript の検査をまとめて走らせる。

.DESCRIPTION
`src/scripts/check.php` は PHP だけを見る。**地図の描き方は JavaScript 側にある**ので、
そちらは node で確かめる。

  pwsh -File src\scripts\js\run-all.ps1

node が無い環境では走らせない —— **配備の条件にはしない。**
本番のホストに node は要らない(ブラウザへ配るだけのファイルなので)。
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    Write-Host 'node が見つかりません。JavaScript の検査は飛ばします。' -ForegroundColor Yellow
    Write-Host '(本番のホストには要りません。手元で地図の描画を直したときに走らせるもの)'
    exit 0
}

$failed = 0
Get-ChildItem $PSScriptRoot -Filter '*.test.js' | Sort-Object Name | ForEach-Object {
    Write-Host ""
    Write-Host "---- $($_.Name) ----" -ForegroundColor Cyan
    & node $_.FullName
    if ($LASTEXITCODE -ne 0) { $failed++ }
}

Write-Host ""
if ($failed -eq 0) {
    Write-Host 'JavaScript の検査はすべて通りました。' -ForegroundColor Green
    exit 0
}

Write-Host "$failed 本の検査が失敗しました。" -ForegroundColor Red
exit 1
