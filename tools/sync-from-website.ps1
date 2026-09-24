<#
.SYNOPSIS
手元の Website(C:\Users\itota\Documents\Website\server)を、この控えの server/ へ写す。

.DESCRIPTION
**写すのは「出してよいもの」だけ。** 次は写さない(.gitignore と push-github.ps1 も同じものを弾く):

  - 秘密      … .env / src/config/*.local.php / 鍵・証明書(*.pem *.key *.jks *.p12)
  - 利用者のデータ … src/uploads/(地図の書き出し。教職員氏名を含みうる)・backups/・*.cms・*.tar.gz
  - 生成物・退避 … src/vendor/(composer install で戻る)・Downloaded/(旧サイト)・Old/・out/・*.log

**Notebook(docs/*.ipynb)は実行結果を消して写す。** 本番で走らせた結果が残っており、
メールアドレスや送信記録が入っている(2026-09-25 に確認)。手順の本文とコードは残る。

  pwsh -File tools\sync-from-website.ps1
  pwsh -File tools\push-github.ps1 -Message "…"

.PARAMETER Source
写す元。既定は C:\Users\itota\Documents\Website\server
#>
[CmdletBinding()]
param(
    [string]$Source = 'C:\Users\itota\Documents\Website\server'
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$dest = Join-Path $repoRoot 'server'

if (-not (Test-Path (Join-Path $Source 'src\index.php'))) {
    throw "写す元が Website ではありません(src\index.php が無い): $Source"
}

# 元の側で**丸ごと**写さないフォルダ(元の側の絶対パスで指す —— 名前だけだと src\admin\vendor まで巻き込む)
$excludeDirs = @(
    'Downloaded', 'Old', 'backups', 'out', 'certs', 'node_modules', '.git',
    'src\vendor', 'src\uploads'
) | ForEach-Object { Join-Path $Source $_ }

# 名前で写さないファイル。**.env.example は写す**(.env だけを弾く)
$excludeFiles = @('.env', '*.local.php', '*.pem', '*.key', '*.jks', '*.p12', '*.pfx', '*.cms', '*.tar.gz', '*.log')

Write-Host "写す元: $Source"
Write-Host "写す先: $dest"

# /MIR: 元で消したものは先でも消す(**除外したものは先でも触らない**)
# **$args という名前は使わない**(PowerShell が引数の受け皿として持っている自動変数)
$roboArgs = @($Source, $dest, '/MIR', '/NFL', '/NDL', '/NJH', '/NP', '/R:1', '/W:1', '/XD') + $excludeDirs + @('/XF') + $excludeFiles
& robocopy @roboArgs | Out-Null
# robocopy は 8 未満が成功(1 = 写した, 2 = 余分を消した, …)
if ($LASTEXITCODE -ge 8) { throw "robocopy が失敗しました(終了コード $LASTEXITCODE)。" }

# ---------------------------------------------------- Notebook の実行結果を消す
$strip = Join-Path $PSScriptRoot 'strip-notebook-outputs.js'
$notebooks = @(Get-ChildItem $dest -Recurse -Filter '*.ipynb' | ForEach-Object FullName)
if ($notebooks.Count -gt 0) {
    if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
        throw 'node が見つかりません。Notebook の実行結果を消せないので止めます(消さずに出すと、メールアドレスなどが載る)。'
    }
    & node $strip @notebooks
    if ($LASTEXITCODE -ne 0) { throw 'Notebook の実行結果を消せませんでした。' }
}

# ---------------------------------------------------- 写さなかったはずのものが無いか
$leaked = Get-ChildItem $dest -Recurse -Force -File | Where-Object {
    $_.Name -eq '.env' -or $_.Name -like '*.local.php' -or $_.Extension -in '.pem', '.key', '.jks', '.p12', '.pfx', '.cms'
}
if ($leaked) {
    $leaked | ForEach-Object { Write-Host "  写してはいけないもの: $($_.FullName)" -ForegroundColor Red }
    throw '写してはいけないものが server/ に残っています。消してから、もう一度走らせてください。'
}

Write-Host ''
Write-Host '写しました。次は git status で中身を見てから tools\push-github.ps1 を。' -ForegroundColor Green
