<#
.SYNOPSIS
GitHub へ出す前に、秘密が混じっていないかを確かめてから commit / push する。

.DESCRIPTION
このリポジトリは**非公開**だが、出したものは取り消せない(履歴に残り、複製もされうる)。
そこで「出す直前に必ず1度見る」場所をここ1本にまとめる(Android 側の控えと同じ考え方)。

  pwsh -File tools\push-github.ps1 -Message "コミットの一言"

見るもの:

  1. **出してはいけない名前のファイル**が入っていないか(.env・*.local.php・鍵・控え・利用者のデータ)
  2. **出す変更の中身**に、直書きの資格情報らしい行・秘密鍵の中身が無いか
  3. 個人のメールアドレスらしいもの(**止めずに知らせるだけ**)

**先に全部を stage してから見る。** stage 前の `git diff` には**新しいファイルが入らない** ——
最初の commit はすべて新しいファイルなので、何も検査されずに素通りする(2026-09-25 に気づいた)。
当たったら stage を戻して止める。誤検知だと分かっているときだけ -Force。

.PARAMETER Message
コミットの一言。省略すると、変更があるときだけ日付入りの既定文になる。

.PARAMETER Branch
出す枝。既定はいまの枝。

.PARAMETER NoCommit
commit はせず、既にある commit を push するだけ。

.PARAMETER Force
秘密の検査に当たっても続ける。**誤検知と確かめたときだけ。**
#>
[CmdletBinding()]
param(
    [string]$Message = '',
    [string]$Branch = '',
    [switch]$NoCommit,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

if (-not (Test-Path (Join-Path $repoRoot '.git'))) {
    throw "$repoRoot は git のリポジトリではありません。"
}

if ($Branch -eq '') {
    $Branch = (git rev-parse --abbrev-ref HEAD).Trim()
}
if ($Branch -eq 'HEAD') {
    throw '枝から外れた状態(detached HEAD)です。先に枝へ戻ってください。'
}

Write-Host "リポジトリ: $repoRoot"
Write-Host "枝        : $Branch"
Write-Host "送り先    : $((git remote get-url origin).Trim())"
Write-Host ''

# ---------------------------------------------------------------- 1. 出す中身

if (-not $NoCommit) {
    git add -A
    if ($LASTEXITCODE -ne 0) { throw 'stage に失敗しました。' }
}
$staged = @(git diff --cached --name-status)
if ($staged.Count -eq 0 -and -not $NoCommit) {
    Write-Host '手元に変更はありません。既にある commit だけを push します。' -ForegroundColor Yellow
    $NoCommit = $true
}
if (-not $NoCommit) {
    Write-Host "--- 出す変更 ($($staged.Count) 件) ---"
    $staged | Select-Object -First 40 | ForEach-Object { Write-Host "  $_" }
    if ($staged.Count -gt 40) { Write-Host "  … ほか $($staged.Count - 40) 件" }
    Write-Host ''
}

# ---------------------------------------------------- 2. 出してはいけない名前

$forbiddenPatterns = @(
    # .env は弾き、.env.example は通す
    '(^|/)\.env(\.(?!example$)[^/]*)?$',
    '(^|/)[^/]*\.local\.php$',
    '(^|/)[^/]*\.(pem|key|jks|keystore|p12|pfx|cms)$',
    '(^|/)id_(rsa|ed25519|ecdsa)$',
    '(^|/)[^/]*\.tar\.gz$',
    '^server/src/uploads/',
    '^server/(backups|Downloaded|Old|out|certs)/'
)

$files = @(git ls-files) + @(git diff --cached --name-only --diff-filter=A)
$forbidden = $files | Sort-Object -Unique | Where-Object {
    $path = $_
    $forbiddenPatterns | Where-Object { $path -match $_ } | Select-Object -First 1
}

$secretHits = @()
if ($forbidden.Count -gt 0) {
    $secretHits += $forbidden | ForEach-Object { "出してはいけないファイル: $_" }
}

# ------------------------------------------------ 3. 出す変更の中に直書きの値

<#
  **値が書かれている行だけを見る。** `getenv('MAIL_PASSWORD')` のような
  「読み込む側の記述」まで止めると、毎回 -Force を付けることになり検査が形骸化する。
#>
$valuePattern = '(?i)(password|passwd|secret|api[_-]?key|access[_-]?token|client[_-]?secret|authorization|passphrase)\s*(=>|=|:)\s*["''][^"''$\s]{6,}["'']'
# 秘密鍵の中身。**印の行だけ**を見る(鍵を見分けるコードの中に印の文字列が出てくるため)
$pemPattern = '^\+\s*-----BEGIN [A-Z ]*PRIVATE KEY-----\s*$'
$tokenPattern = 'gh[pousr]_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{35}'
$emailPattern = '[A-Za-z0-9._%+-]+@(gmail|yahoo|outlook|hotmail|icloud|me)\.(com|co\.jp|jp)'

$diff = if ($NoCommit) {
    git diff "origin/$Branch...HEAD" --unified=0 2>$null
} else {
    git diff --cached --unified=0
}
$added = @($diff | Where-Object { $_ -match '^\+' -and $_ -notmatch '^\+\+\+' })

$suspicious = @($added | Where-Object { $_ -match $valuePattern -or $_ -match $pemPattern -or $_ -match $tokenPattern })
if ($suspicious.Count -gt 0) {
    $secretHits += $suspicious | Select-Object -First 10 | ForEach-Object {
        # **値そのものは出さない。** 検査の画面に秘密を書き写さない
        $line = $_ -replace $valuePattern, '$1$2 ****' -replace $tokenPattern, '****'
        '直書きの資格情報らしい行: ' + $line.Substring(0, [Math]::Min(160, $line.Length))
    }
}

$emails = @($added | ForEach-Object { [regex]::Matches($_, $emailPattern) | ForEach-Object Value } | Sort-Object -Unique)
if ($emails.Count -gt 0) {
    Write-Host "--- 個人のメールアドレスらしいもの ($($emails.Count) 種類。止めはしない) ---" -ForegroundColor Yellow
    $emails | ForEach-Object { Write-Host ('  ' + ($_ -replace '^(.{2})[^@]*', '$1***')) -ForegroundColor Yellow }
    Write-Host ''
}

if ($secretHits.Count -gt 0) {
    Write-Host '--- 秘密の検査に当たりました ---' -ForegroundColor Red
    $secretHits | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    Write-Host ''
    if (-not $Force) {
        if (-not $NoCommit) { git reset -q }   # stage を戻す(手元のファイルはそのまま)
        throw '出すのを止めました。上の行を確かめ、誤検知だと分かっているときだけ -Force を付けて実行してください。'
    }
    Write-Host '-Force が付いているので続けます。' -ForegroundColor Yellow
}
else {
    Write-Host '秘密の検査: 当たりなし' -ForegroundColor Green
}

# ---------------------------------------------------------------- 4. 出す

if (-not $NoCommit) {
    if ($Message -eq '') {
        $Message = "Update ($(Get-Date -Format 'yyyy-MM-dd'))"
    }
    git commit -m $Message
    if ($LASTEXITCODE -ne 0) { throw 'commit に失敗しました。' }
}

git push -u origin $Branch
if ($LASTEXITCODE -ne 0) { throw "push に失敗しました($Branch)。" }

Write-Host ''
Write-Host "push しました: $Branch" -ForegroundColor Green
# SSH の送り先(git@github-kosen-web:owner/repo.git)も、画面で開ける https の形に直す
$remote = (git remote get-url origin).Trim() -replace '\.git$', '' -replace '^git@[^:]+:', 'https://github.com/'
if ($Branch -ne 'main') {
    Write-Host "プルリクエストはこちらから: $remote/compare/main...$Branch"
}
