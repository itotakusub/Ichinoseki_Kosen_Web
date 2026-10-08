<#
.SYNOPSIS
  AR の撮影(ぼかしたあとのフォルダ)から、RealityScan に読ませる位置のデータを作る(2026-10-08)。

.DESCRIPTION
  管理画面「AR の撮影」で落とした zip を scripts\ar-blur-faces.ps1 で展開・ぼかしたフォルダに使う。
  画像ごとのカメラの位置を、AR 実測の重ね方(survey.json の alignment。管理アプリが結果を出したときに書き足す)で
  **東・北・上(m)** に直し、RealityScan の Trajectory や地上点として読める CSV にする。計算はこの PC でする
  (サーバーは置くだけ)。

  出すもの(<フォルダ>\realityscan\):
    trajectory_local.csv       name,x,y,z     x = 東・y = 北・z = 上(m)。原点は最初に印を付けた地点
    ground_control_local.csv   name,x,y,z     印を付けた地点(地上点。高さは最初の印のときのカメラの高さを 0)
    trajectory_wgs84.csv       name,lat,lon,alt   -OriginLat / -OriginLon を渡したときだけ
    ground_control_wgs84.csv   name,lat,lon,alt   同上
    excluded.txt               位置を付けなかった画像と理由(印が足りず重ねられない区間など)
  -WriteExif を付けると、exiftool で各画像の EXIF に GPS(緯度・経度・高さ)を書く(-OriginLat / -OriginLon が要る)。
  マップウィザードは「GPS のある画像」として使う。

  RealityScan での読み方は docs/20 の「RealityScan で 3D を作る」。

.PARAMETER Dir
  ar-blur-faces.ps1 で展開したフォルダ(images\・frames.json・survey.json がある所)。

.PARAMETER OriginLat
  原点(最初に印を付けた地点。survey.json の alignment.originTitle)の緯度(度)。地図アプリなどで調べる。

.PARAMETER OriginLon
  同じ地点の経度(度)。

.PARAMETER OriginAlt
  同じ地点のカメラの高さの標高(m)。分からなければ 0。

.PARAMETER WriteExif
  画像の EXIF に GPS を書く(exiftool が要る。元の画像を上書きする)。

.EXAMPLE
  .\ar-realityscan-prep.ps1 -Dir $HOME\Downloads\ar-20261008-1420-1F-f193f607
.EXAMPLE
  .\ar-realityscan-prep.ps1 -Dir $HOME\Downloads\ar-20261008-1420-1F-f193f607 -OriginLat 38.93 -OriginLon 141.12 -WriteExif
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Dir,
    [Nullable[double]]$OriginLat = $null,
    [Nullable[double]]$OriginLon = $null,
    [double]$OriginAlt = 0,
    [switch]$WriteExif
)

$ErrorActionPreference = 'Stop'
$inv = [Globalization.CultureInfo]::InvariantCulture
function F([double]$v, [int]$digits = 3) { $v.ToString("F$digits", $inv) }

$root = (Resolve-Path -LiteralPath $Dir).Path
$surveyPath = Join-Path $root 'survey.json'
$framesPath = Join-Path $root 'frames.json'
foreach ($p in $surveyPath, $framesPath) {
    if (-not (Test-Path -LiteralPath $p)) { Write-Host "見つかりません: $p(ar-blur-faces.ps1 で展開したフォルダを渡してください)" -ForegroundColor Yellow; exit 2 }
}
$survey = Get-Content -LiteralPath $surveyPath -Raw -Encoding UTF8 | ConvertFrom-Json
$alignment = $survey.alignment
if (-not $alignment -or -not $alignment.segments) {
    Write-Host 'この撮影には重ね方(alignment)がありません。' -ForegroundColor Yellow
    Write-Host '管理アプリの地図で「実測」→「前の記録」からこの撮影を開くと、重ね方を付けてサーバーへ送り直します。そのあと zip を落とし直してください。'
    Write-Host '(重ね方は、追跡が続いた区間ごとに 2 つ以上の地点に印があるときに作れます)'
    exit 2
}
$frames = Get-Content -LiteralPath $framesPath -Raw -Encoding UTF8 | ConvertFrom-Json
if ($frames.Count -eq 0) { Write-Host '画像がありません(記録だけの撮影)。' ; exit 0 }

# 高さの基準: 最初の印のときのカメラの高さ。古い記録で無ければ、最初の画像の高さ
$baseY = if ($null -ne $alignment.originArY) { [double]$alignment.originArY } else { [double]$frames[0].pose.t[1] }

$outDir = Join-Path $root 'realityscan'
New-Item -ItemType Directory -Force $outDir | Out-Null

$rows = New-Object System.Collections.Generic.List[object]
$excluded = New-Object System.Collections.Generic.List[string]
foreach ($f in $frames) {
    $name = [string]$f.name
    $t = [long]$f.timestampMillis
    if (-not (Test-Path -LiteralPath (Join-Path $root "images\$name"))) { $excluded.Add("$name`t画像のファイルがない"); continue }
    $seg = $alignment.segments | Where-Object { [long]$_.fromT -le $t -and $t -le [long]$_.toT } | Select-Object -First 1
    if (-not $seg) { $excluded.Add("$name`t印が足りず重ねられない区間(追跡切れ・位置の飛びのあと)"); continue }
    # survey.json の alignment.frame の式と同じ: a = x, b = -z
    $r = [double]$seg.rotationDegrees * [Math]::PI / 180
    $a = [double]$f.pose.t[0]
    $b = -[double]$f.pose.t[2]
    $east = [Math]::Cos($r) * $a - [Math]::Sin($r) * $b + [double]$seg.east0
    $north = [Math]::Sin($r) * $a + [Math]::Cos($r) * $b + [double]$seg.north0
    $up = [double]$f.pose.t[1] - $baseY
    $rows.Add([pscustomobject]@{ Name = $name; East = $east; North = $north; Up = $up; Path = (Join-Path $root "images\$name") })
}

# 地上点の名前: 地点の名前 + uuid の頭(カンマ・空白は使わない)
$gcps = foreach ($n in $alignment.nodes) {
    $label = (([string]$n.title) -replace '[,\s"]', '_') + '_' + ([string]$n.uuid).Substring(0, [Math]::Min(8, ([string]$n.uuid).Length))
    [pscustomobject]@{ Name = $label; East = [double]$n.east; North = [double]$n.north; Up = 0.0 }
}

$utf8 = New-Object System.Text.UTF8Encoding $false
[IO.File]::WriteAllLines((Join-Path $outDir 'trajectory_local.csv'), [string[]](@('name,x,y,z') + ($rows | ForEach-Object { "$($_.Name),$(F $_.East),$(F $_.North),$(F $_.Up)" })), $utf8)
[IO.File]::WriteAllLines((Join-Path $outDir 'ground_control_local.csv'), [string[]](@('name,x,y,z') + ($gcps | ForEach-Object { "$($_.Name),$(F $_.East),$(F $_.North),$(F $_.Up)" })), $utf8)
[IO.File]::WriteAllLines((Join-Path $outDir 'excluded.txt'), [string[]](@("# 位置を付けなかった画像($($excluded.Count) 枚)") + $excluded), $utf8)

# 緯度経度(原点のまわりの平面近似。学校の広さなら誤差は無視できる)
$wgs = $null
if ($null -ne $OriginLat -and $null -ne $OriginLon) {
    $R = 6378137.0
    $toLatLon = {
        param([double]$e, [double]$n)
        $lat = $OriginLat + ($n / $R) * 180 / [Math]::PI
        $lon = $OriginLon + ($e / ($R * [Math]::Cos($OriginLat * [Math]::PI / 180))) * 180 / [Math]::PI
        , @($lat, $lon)
    }
    $wgs = foreach ($row in $rows) { $ll = & $toLatLon $row.East $row.North; [pscustomobject]@{ Name = $row.Name; Lat = $ll[0]; Lon = $ll[1]; Alt = $OriginAlt + $row.Up; Path = $row.Path } }
    $gcpWgs = foreach ($g in $gcps) { $ll = & $toLatLon $g.East $g.North; [pscustomobject]@{ Name = $g.Name; Lat = $ll[0]; Lon = $ll[1]; Alt = $OriginAlt + $g.Up } }
    [IO.File]::WriteAllLines((Join-Path $outDir 'trajectory_wgs84.csv'), [string[]](@('name,lat,lon,alt') + ($wgs | ForEach-Object { "$($_.Name),$(F $_.Lat 8),$(F $_.Lon 8),$(F $_.Alt)" })), $utf8)
    [IO.File]::WriteAllLines((Join-Path $outDir 'ground_control_wgs84.csv'), [string[]](@('name,lat,lon,alt') + ($gcpWgs | ForEach-Object { "$($_.Name),$(F $_.Lat 8),$(F $_.Lon 8),$(F $_.Alt)" })), $utf8)
} elseif ($WriteExif) {
    Write-Host '-WriteExif には -OriginLat と -OriginLon が要ります(原点の地点の緯度経度)。' -ForegroundColor Yellow
    exit 2
}

if ($WriteExif) {
    $exiftool = Get-Command exiftool -ErrorAction SilentlyContinue
    if (-not $exiftool) {
        Write-Host 'exiftool が見つかりません。EXIF は書かずに CSV だけ作りました。' -ForegroundColor Yellow
        Write-Host '  入れ方: winget install OliverBetz.ExifTool(入れたあと、新しい PowerShell を開き直す)'
    } else {
        # exiftool の CSV 取り込み(SourceFile ごとに GPS を書く)
        $exifCsv = Join-Path $outDir 'exif-gps.csv'
        $lines = @('SourceFile,GPSLatitude,GPSLatitudeRef,GPSLongitude,GPSLongitudeRef,GPSAltitude,GPSAltitudeRef')
        $lines += $wgs | ForEach-Object {
            '"{0}",{1},{2},{3},{4},{5},{6}' -f $_.Path.Replace('\', '/'), (F ([Math]::Abs($_.Lat)) 8), $(if ($_.Lat -ge 0) { 'N' } else { 'S' }),
                (F ([Math]::Abs($_.Lon)) 8), $(if ($_.Lon -ge 0) { 'E' } else { 'W' }), (F ([Math]::Abs($_.Alt))), $(if ($_.Alt -ge 0) { '0' } else { '1' })
        }
        [IO.File]::WriteAllLines($exifCsv, [string[]]$lines, $utf8)
        # 画像は images フォルダごと渡す(1 枚ずつ並べるとコマンドの長さの上限を超える)。CSV に無い画像は変えない
        & $exiftool.Source "-csv=$exifCsv" -overwrite_original -q -q (Join-Path $root 'images')
        if ($LASTEXITCODE -ne 0) { Write-Host "exiftool が失敗しました(終了コード $LASTEXITCODE)" -ForegroundColor Red; exit 1 }
        Write-Host "EXIF に GPS を書きました($($wgs.Count) 枚)"
    }
}

$segRms = ($alignment.segments | ForEach-Object { "{0} 個の地点で {1} m" -f $_.marks, (F ([double]$_.rmsMeters) 2) }) -join '・'
Write-Host ''
Write-Host ("できました: 位置を付けた画像 {0} 枚・付けなかった画像 {1} 枚 → {2}" -f $rows.Count, $excluded.Count, $outDir) -ForegroundColor Green
Write-Host ("原点: 「{0}」・区間 {1} 個(印の地点との食い違い: {2})" -f $alignment.originTitle, @($alignment.segments).Count, $segRms)
Write-Host ''
Write-Host 'RealityScan で(docs/20 の「RealityScan で 3D を作る」):'
Write-Host '  (A) 座標系を local:1 - Euclidean にして、Import Metadata の Trajectory で trajectory_local.csv'
Write-Host '      (書式 Custom「name x y z」・区切りはカンマ・1 行目を読まない)'
Write-Host '  (B) -OriginLat/-OriginLon と -WriteExif で GPS を書いた画像を入れ、マップウィザードで「GPS のある画像」として使う'
Write-Host '  うまく重ならなければ ground_control_*.csv を地上点として読み、コントロールポイントを数枚の画像で付ける'
