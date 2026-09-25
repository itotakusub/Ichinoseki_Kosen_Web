<#
.SYNOPSIS
GitHub へ出す前に、秘密が混じっていないかを確かめてから commit / push する。

.DESCRIPTION
**Android と Website の両方の控えで、中身がまったく同じファイル**(2026-09-25 に揃えた)。
  Android: F:\Pull\Ichinoseki_Kosen\scripts\push-github.ps1
  Website: F:\Pull\Ichinoseki_Kosen_Web\tools\push-github.ps1
片方だけ直すと検査がずれるので、直したら両方へ写すこと(push-github-all.ps1 が食い違いを知らせる)。
リポジトリの根は「この script の1つ上」なので、どちらに置いても同じに動く。

  pwsh -File tools\push-github.ps1 -Message "コミットの一言"

見るもの:

  1. **出してはいけない名前のファイル**(署名鍵・.env・*.local.php・鍵と証明書・利用者のデータ)
  2. **出す変更の中身**に、直書きの資格情報らしい行・秘密鍵の中身・トークンの形
  3. 個人のメールアドレスらしいもの(**止めずに知らせるだけ**)
  4. **公開の IPv4 アドレス**(私用・文書用・公開 DNS・ホスト自身の公開アドレスは除く)。
     運用者の回線の IP が文書に載ったまま公開された(診断 2026-09-25 の W-47)
  5. **教職員の氏名**。リポジトリの外の一覧(`~\.kosenmap\private-names.txt`、
     update-private-names.ps1 が作る)と照合する。一覧が無ければ知らせるだけ(同 W-53)

**先に全部を stage してから見る。** stage 前の `git diff` には**新しいファイルが入らない** ——
最初の commit はすべて新しいファイルなので、何も検査されずに素通りする(2026-09-25 に気づいた)。
当たったら stage を戻して止める。誤検知だと分かっているときだけ -Force。

## プルリクエストの説明(Markdown)

`docs/pull-requests/` に**今日の日付の説明が無ければ、下書きを作って一緒に commit する。**
push が済んだら、その説明を開く(-NoOpen で開かない)。PR の画面へ貼る文として使う。

.PARAMETER Message
コミットの一言。省略すると、変更があるときだけ日付入りの既定文になる。

.PARAMETER Branch
出す枝。既定はいまの枝。

.PARAMETER NoCommit
commit はせず、既にある commit を push するだけ。

.PARAMETER Force
秘密の検査に当たっても続ける。**誤検知と確かめたときだけ。**

.PARAMETER NoOpen
push のあとにプルリクエストの説明を開かない(自動実行など)。

.PARAMETER CheckOnly
検査だけして終える。**commit も push もせず、stage も元に戻す。**

.PARAMETER ScanAll
中身の検査(2〜5)を、出す変更だけでなく**追跡中の全ファイル**に掛ける。
初めて使うときと、履歴を書き換えたあとの確かめに。-CheckOnly と一緒に使う。
#>
[CmdletBinding()]
param(
    [string]$Message = '',
    [string]$Branch = '',
    [switch]$NoCommit,
    [switch]$Force,
    [switch]$NoOpen,
    [switch]$CheckOnly,
    [switch]$ScanAll
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

if (-not (Test-Path (Join-Path $repoRoot '.git'))) {
    throw "$repoRoot は git のリポジトリではありません。"
}

# 日本語のファイル名を \343\201… ではなくそのまま出す(一覧を読めるように)
git config core.quotepath false

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

$prDir = Join-Path $repoRoot 'docs\pull-requests'
$today = Get-Date -Format 'yyyy-MM-dd'

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

<#
  **名前で弾く。** Android と Website の両方の顔ぶれを1つにまとめてある
  (片方にしか無い名前は、もう片方では当たらないだけ)。
#>
$forbiddenPatterns = @(
    # Android の署名まわり
    '(^|/)[^/]*\.(jks|keystore|p12|pfx)$',
    '(^|/)keystore\.properties$',
    '(^|/)local\.properties$',
    '(^|/)google-services\.json$',
    # 秘密(.env.example と keystore.properties.example は通す)
    '(^|/)\.env(\.(?!example$)[^/]*)?$',
    '(^|/)[^/]*\.local\.php$',
    '(^|/)[^/]*\.(pem|key|cms)$',
    '(^|/)id_(rsa|ed25519|ecdsa)$',
    # 控え・利用者のデータ
    '(^|/)[^/]*\.tar\.gz$',
    '^server/src/uploads/',
    '^server/(backups|Downloaded|Old|out|certs)/'
)

$files = @(git ls-files) + @(git diff --cached --name-only --diff-filter=A)
$forbidden = @($files | Sort-Object -Unique | Where-Object {
    $path = $_
    $forbiddenPatterns | Where-Object { $path -match $_ } | Select-Object -First 1
})

$secretHits = @()
if ($forbidden.Count -gt 0) {
    $secretHits += $forbidden | ForEach-Object { "出してはいけないファイル: $_" }
}

# ------------------------------------------------ 3. 出す変更の中に直書きの値

<#
  **値が書かれている行だけを見る。** `getenv('MAIL_PASSWORD')` や `getProperty("storePassword")` のような
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

<#
  **どこに出たか**も持つ(IP と氏名は、値を伏せて「ファイル:行」だけ出すため)。
  差分なら `+++ b/…` と `@@ … +行,数 @@` から数える。-ScanAll なら追跡中の全ファイルを読む。
#>
$located = New-Object System.Collections.Generic.List[object]
if ($ScanAll) {
    $binary = '\.(png|jpe?g|gif|webp|ico|svg|pdf|zip|gz|jar|apk|aab|exe|dll|so|class|dex|ttf|otf|woff2?|jks|keystore|bin)$'
    foreach ($path in @(git ls-files) + @(git diff --cached --name-only --diff-filter=A) | Sort-Object -Unique) {
        if ($path -match $binary) { continue }
        $full = Join-Path $repoRoot $path
        if (-not (Test-Path -LiteralPath $full -PathType Leaf) -or (Get-Item -LiteralPath $full).Length -gt 20MB) { continue }
        $no = 0
        foreach ($line in [IO.File]::ReadLines($full)) {
            $no++
            $located.Add([pscustomobject]@{ Where = "${path}:$no"; Text = $line })
        }
    }
} else {
    $file = ''
    $no = 0
    foreach ($line in $diff) {
        if ($line -match '^\+\+\+ (?:b/)?(.*)$') { $file = $Matches[1]; continue }
        if ($line -match '^@@ -\S+ \+(\d+)') { $no = [int]$Matches[1]; continue }
        if ($line -match '^\+') {
            $located.Add([pscustomobject]@{ Where = "${file}:$no"; Text = $line.Substring(1) })
            $no++
        }
    }
}
if ($ScanAll) {
    # 全体を見るときは、2・3 の検査も全体に掛ける(行頭の + は付いていないので付けて揃える)
    $added = @($located | ForEach-Object { '+' + $_.Text })
}

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

# ------------------------------------------------ 4. 公開の IPv4 アドレス

<#
  **回線の IP は、おおよその所在地と、管理用の口を許している相手を教える**(W-47)。
  除くもの: 私用・予約・文書用(RFC 5737)の範囲、公開 DNS、nginx/km/allow-admin.conf が
  allow しているホスト自身の公開アドレス(DNS で誰でも引ける)。
  版番号(jdk-21.0.12.101・v1.2.3.4 のように前後が英字や - . で続くもの)は形で除く。
#>
function Test-KmPublicIpv4([string]$text) {
    $octets = $text.Split('.') | ForEach-Object { [int]$_ }
    if ($octets | Where-Object { $_ -gt 255 }) { return $false }
    $a, $b, $c, $d = $octets
    if ($a -in 0, 10, 127 -or $a -ge 224) { return $false }
    if ($a -eq 172 -and $b -ge 16 -and $b -le 31) { return $false }
    if ($a -eq 192 -and $b -eq 168) { return $false }
    if ($a -eq 169 -and $b -eq 254) { return $false }
    if ($a -eq 100 -and $b -ge 64 -and $b -le 127) { return $false }   # 事業者の共有(CGNAT)
    if ("$a.$b.$c" -in '192.0.2', '198.51.100', '203.0.113') { return $false }   # 文書用
    if ($text -eq '1.2.3.4') { return $false }   # 説明の例
    if ($text -in '1.1.1.1', '1.0.0.1', '8.8.8.8', '8.8.4.4', '9.9.9.9', '149.112.112.112') { return $false }
    return $true
}
$allowedIps = @()
$allowConf = Join-Path $repoRoot 'server\nginx\km\allow-admin.conf'
if (Test-Path -LiteralPath $allowConf) {
    $allowedIps = @(Select-String -LiteralPath $allowConf -Pattern '^\s*allow\s+([0-9.]+)\s*;' | ForEach-Object { $_.Matches[0].Groups[1].Value })
}
# 前に / が付くものは版(Chrome/120.0.0.0)。lock ファイルは依存の版の一覧なので見ない
$ipPattern = '(?<![\w./-])(\d{1,3}(?:\.\d{1,3}){3})(?![\w.-]*[A-Za-z])(?![\d.])'
$ipSkipFiles = '(^|/)([^/]*\.lock|package-lock\.json|gradle\.lockfile)(:\d+)?$'
# JDK の版(「jdk-21.0.11.10 が 21.0.12.8 に」)は、同じ行に jdk とあれば版として扱う
$ipHits = @($located | Where-Object { $_.Where -notmatch $ipSkipFiles -and $_.Text -notmatch '(?i)jdk' } | ForEach-Object {
    $entry = $_
    [regex]::Matches($entry.Text, $ipPattern) | ForEach-Object {
        $ip = $_.Groups[1].Value
        if ((Test-KmPublicIpv4 $ip) -and $ip -notin $allowedIps) {
            # **値は伏せる。** 先頭の区切りだけ出す
            "公開の IPv4 アドレス: $($entry.Where)  $(($ip -split '\.')[0]).*.*.*"
        }
    }
} | Select-Object -Unique)
if ($ipHits.Count -gt 0) {
    $secretHits += $ipHits | Select-Object -First 20
}

# ------------------------------------------------ 5. 教職員の氏名

<#
  **一覧はリポジトリの外。** 無ければ知らせるだけ(一覧が無い PC でも push は止めない)。
  当たったら値は出さず、「ファイル:行」と一覧の中の番号だけ出す。
#>
$namesFile = if ($env:KM_PRIVATE_NAMES_FILE) { $env:KM_PRIVATE_NAMES_FILE } else { Join-Path $HOME '.kosenmap\private-names.txt' }
if (Test-Path -LiteralPath $namesFile) {
    $privateNames = @(Get-Content -LiteralPath $namesFile -Encoding UTF8 | Where-Object { $_.Trim().Length -ge 2 })
    $nameHits = @($located | ForEach-Object {
        $entry = $_
        for ($i = 0; $i -lt $privateNames.Count; $i++) {
            if ($entry.Text.Contains($privateNames[$i])) {
                "教職員の氏名(一覧の #$i): $($entry.Where)"
            }
        }
    } | Select-Object -Unique)
    if ($nameHits.Count -gt 0) {
        $secretHits += $nameHits | Select-Object -First 20
    }
} else {
    Write-Host "氏名の一覧がありません($namesFile)。氏名の検査は飛ばします。update-private-names.ps1 で作れます。" -ForegroundColor Yellow
    Write-Host ''
}

if ($secretHits.Count -gt 0) {
    Write-Host '--- 秘密の検査に当たりました ---' -ForegroundColor Red
    $secretHits | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    Write-Host ''
    if (-not $Force -and -not $CheckOnly) {
        if (-not $NoCommit) { git reset -q }   # stage を戻す(手元のファイルはそのまま)
        throw '出すのを止めました。上の行を確かめ、誤検知だと分かっているときだけ -Force を付けて実行してください。'
    }
    if ($Force) { Write-Host '-Force が付いているので続けます。' -ForegroundColor Yellow }
}
else {
    Write-Host '秘密の検査: 当たりなし' -ForegroundColor Green
}

if ($CheckOnly) {
    if (-not $NoCommit) { git reset -q }
    Write-Host '検査だけで終えます(-CheckOnly)。commit も push もしていません。'
    if ($secretHits.Count -gt 0) { exit 1 }
    exit 0
}

# ------------------------------------------- 6. プルリクエストの説明(下書き)

<#
  **説明が「まだ main に入っていない今日のもの」ならそこへ足し、無ければ下書きを作る。**

  - 今日の説明が main にまだ無い(= その PR はまだ開いている) … 「このあとの追加」に一言を足す
  - 今日の説明が無い、または**もう main に入っている**(= その PR はマージ済み) … 新しく作る
    (同じ日の2本目は `-2` を付ける。マージ済みの説明を開いて貼り直すと、前の PR の文が混ざる)

  中身は commit の一言と、変わった場所の数だけ —— 何を確かめたかは人にしか書けないので空けておく。
  作ったもの・足したものは同じ commit に入れる(説明と変更が別々に散らない)。
#>
$prFile = $null
if (-not $NoCommit) {
    if ($Message -eq '') {
        $Message = "Update ($today)"
    }
    $lines = @($Message -split "`r?`n")
    $title = $lines[0]
    $body = @($lines | Select-Object -Skip 1 | Where-Object { $_ -notmatch '^Co-Authored-By:' -and $_.Trim() -ne '' })
    $utf8 = New-Object System.Text.UTF8Encoding($false)

    # main の今の姿を知る(マージ済みかどうかの判断に使う)。取れなければ手元の知っている姿で判断する
    git fetch -q origin main 2>$null

    $todays = @(Get-ChildItem $prDir -Filter "$today-*.md" -ErrorAction SilentlyContinue | Sort-Object Name)
    $openPrs = @($todays | Where-Object {
        git cat-file -e "origin/main:docs/pull-requests/$($_.Name)" 2>$null
        $LASTEXITCODE -ne 0
    })

    if ($openPrs.Count -gt 0) {
        $prFile = $openPrs[-1].FullName
        $text = [IO.File]::ReadAllText($prFile, $utf8).TrimEnd()
        if ($text -notmatch '(?m)^## このあとの追加') {
            $text += "`n`n## このあとの追加`n"
        }
        $text += "`n- $title"
        [IO.File]::WriteAllText($prFile, $text + "`n", $utf8)
        Write-Host "プルリクエストの説明に足しました: $prFile" -ForegroundColor Cyan
    } else {
        New-Item -ItemType Directory -Force -Path $prDir | Out-Null
        $base = "$today-" + ($Branch.ToLower() -replace '[^a-z0-9._-]', '-')
        $prFile = Join-Path $prDir "$base.md"
        $n = 2
        while (Test-Path $prFile) { $prFile = Join-Path $prDir "$base-$n.md"; $n++ }

        $areas = @(git diff --cached --name-only | ForEach-Object {
            $parts = $_ -split '/'
            if ($parts.Count -ge 3 -and $parts[0] -in 'server', 'app') { "$($parts[0])/$($parts[1])" } else { $parts[0] }
        } | Group-Object | Sort-Object Name | ForEach-Object { "| ``$($_.Name)`` | $($_.Count) |" })

        $md = @(
            "# $title", '',
            "``$Branch`` → ``main``", '',
            '## 変更点', ''
        ) + $(if ($body.Count -gt 0) { $body } else { @('- (書き足してください)') }) + @(
            '', '## 変わった場所', '',
            '| 場所 | ファイル数 |', '|---|---|'
        ) + $areas + @(
            '', '## 確認したこと', '',
            '- (書き足してください)'
        )
        [IO.File]::WriteAllText($prFile, ($md -join "`n") + "`n", $utf8)
        Write-Host "プルリクエストの説明の下書きを作りました: $prFile" -ForegroundColor Cyan
    }
    git add -- $prFile

    git commit -m $Message
    if ($LASTEXITCODE -ne 0) { throw 'commit に失敗しました。' }
}

# ---------------------------------------------------------------- 7. 出す

<#
  **1 回だけやり直す。** 通信の一時的な切れで止まることがある(2026-09-25 に 1 度あり、やり直したら通った)。
  それ以上は繰り返さない —— 認証や権限の失敗は何度やっても同じで、待たせるだけになる。
#>
git push -u origin $Branch
if ($LASTEXITCODE -ne 0) {
    Write-Host 'push に失敗しました。5 秒待って、もう一度だけ試します。' -ForegroundColor Yellow
    Start-Sleep -Seconds 5
    git push -u origin $Branch
}
if ($LASTEXITCODE -ne 0) { throw "push に失敗しました($Branch)。" }

Write-Host ''
Write-Host "push しました: $Branch" -ForegroundColor Green
# SSH の送り先(git@github-kosen-web:owner/repo.git)も、画面で開ける https の形に直す
$remote = (git remote get-url origin).Trim() -replace '\.git$', '' -replace '^git@[^:]+:', 'https://github.com/'
if ($Branch -ne 'main') {
    Write-Host "プルリクエストはこちらから: $remote/compare/main...$Branch"
}

# ------------------------------------------- 8. プルリクエストの説明を開く

<#
  開くのは**いま作った・足した説明**。今回 commit しなかったとき(既にある commit を出しただけ)は、
  今日の説明、それも無ければ一番新しいもの。既定のアプリで開き、.md に結び付いたアプリが無ければメモ帳で開く。
#>
if (-not $NoOpen) {
    $target = $null
    if ($prFile -and (Test-Path -LiteralPath $prFile)) {
        $target = Get-Item -LiteralPath $prFile
    }
    if (-not $target) {
        $target = Get-ChildItem $prDir -Filter "$today-*.md" -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending | Select-Object -First 1
    }
    if (-not $target) {
        $target = Get-ChildItem $prDir -Filter '*.md' -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending | Select-Object -First 1
    }
    if ($target) {
        Write-Host "プルリクエストの説明を開きます: $($target.FullName)"
        try {
            Start-Process -FilePath $target.FullName
        } catch {
            Start-Process -FilePath 'notepad.exe' -ArgumentList "`"$($target.FullName)`""
        }
    }
}