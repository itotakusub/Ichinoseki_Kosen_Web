<#
.SYNOPSIS
  AR の撮影の zip(管理画面「AR の撮影」で落としたもの)を展開し、写った人の顔をぼかす(2026-10-06)。

.DESCRIPTION
  利用者の決定「人が写った画像は PC でぼかす」。サーバーには元の画像のまま置いてあるので、
  **3D・疑似ストリートビューに使う前に必ずこれを通す。**

  - ぼかしは deface(Python。顔を見つけてぼかす)。入っていなければ入れ方を出して止まる:
      py -m pip install deface
  - images\ の JPEG だけをぼかす(depth\ の深度は色の無い距離の画像なので、そのまま)
  - **ぼかす前の画像は PC に残さない。** ぼかした画像で元の画像を上書きする。
    途中で失敗したら、展開したフォルダごと消して止まる(ぼかしていない画像を残さない)。zip はそのまま残る
  - 終わったら、-RemoveZip を付けていれば zip も消す(付けなければ、消すよう案内だけする)

  中身(zip の README.txt と同じ):
    images\frame_NNNN.jpg   カメラの画像(ARCore の読み出しの向きのまま)
    depth\frame_NNNN.png    16bit の深度(mm。対応する端末だけ)
    frames.json             1 枚ごとのカメラの位置・向き・内部の値
    sparse\0\*.txt          同じ姿勢を COLMAP のテキストの形で(既知の姿勢)
    survey.json             AR 実測の記録

.PARAMETER Zip
  落とした zip。

.PARAMETER OutDir
  展開先。既定は zip と同じ場所の同じ名前のフォルダ。**もうあれば止まる**(上書きしない)。

.PARAMETER Threshold
  deface の顔の検出の閾値(既定 0.2)。下げるほど多くをぼかす(見落としが減り、顔でない所もぼかす)。

.PARAMETER RemoveZip
  ぼかし終えたら zip を消す。

.EXAMPLE
  .\ar-blur-faces.ps1 -Zip $HOME\Downloads\ar-20261006-1530-3F-0f8fad5b.zip
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Zip,
    [string]$OutDir,
    [ValidateRange(0.01, 0.99)][double]$Threshold = 0.2,
    [switch]$RemoveZip
)

$ErrorActionPreference = 'Stop'

$deface = Get-Command deface -ErrorAction SilentlyContinue
if (-not $deface) {
    Write-Host 'deface が見つかりません。Python を入れてから、次で入れてください:' -ForegroundColor Yellow
    Write-Host '  py -m pip install deface'
    Write-Host '(入れたあと、新しい PowerShell を開き直すと deface が使えるようになります)'
    exit 2
}

$zipPath = (Resolve-Path -LiteralPath $Zip).Path
if (-not $OutDir) {
    $OutDir = Join-Path (Split-Path -Parent $zipPath) ([IO.Path]::GetFileNameWithoutExtension($zipPath))
}
if (Test-Path -LiteralPath $OutDir) {
    Write-Host "展開先がもうあります(上書きしません): $OutDir" -ForegroundColor Yellow
    exit 2
}

Write-Host "展開しています: $OutDir"
Expand-Archive -LiteralPath $zipPath -DestinationPath $OutDir
$imagesDir = Join-Path $OutDir 'images'
$images = @(Get-ChildItem -LiteralPath $imagesDir -Filter 'frame_*.jpg' -File -ErrorAction SilentlyContinue | Sort-Object Name)
if ($images.Count -eq 0) {
    Write-Host '画像がありません(記録だけの撮影)。ぼかすものはありません。'
    exit 0
}

try {
    # deface は 1 回で何枚も受け取れる。コマンドの長さの上限(約 32000 字)を超えないよう 100 枚ずつ
    for ($start = 0; $start -lt $images.Count; $start += 100) {
        $batch = $images[$start..([Math]::Min($start + 99, $images.Count - 1))]
        Write-Host ("顔をぼかしています: {0}〜{1} / {2} 枚" -f ($start + 1), ($start + $batch.Count), $images.Count)
        & $deface.Source @($batch.FullName) --thresh $Threshold --replacewith blur
        if ($LASTEXITCODE -ne 0) {
            throw "deface が失敗しました(終了コード $LASTEXITCODE)"
        }
    }
    # deface は <名前>_anonymized.jpg を隣に書く。それで元の画像を上書きする(ぼかす前の画像を残さない)
    foreach ($image in $images) {
        $blurred = Join-Path $image.DirectoryName ($image.BaseName + '_anonymized' + $image.Extension)
        if (-not (Test-Path -LiteralPath $blurred)) {
            throw "ぼかした画像がありません: $($image.Name)"
        }
        Move-Item -LiteralPath $blurred -Destination $image.FullName -Force
    }
} catch {
    Write-Host "失敗しました: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'ぼかしていない画像を残さないよう、展開したフォルダを消します(zip は残っています)。'
    Remove-Item -LiteralPath $OutDir -Recurse -Force
    exit 1
}

Write-Host ''
Write-Host ("できました: {0} 枚をぼかしました → {1}" -f $images.Count, $OutDir) -ForegroundColor Green
Write-Host 'ぼかしの見落としが無いか、画像を一通り見てください(横顔・小さな顔・後ろ姿は漏れることがあります)。'
if ($RemoveZip) {
    Remove-Item -LiteralPath $zipPath -Force
    Write-Host "zip を消しました: $zipPath"
} else {
    Write-Host "zip(ぼかしていない画像が入っています)は、要らなくなったら消してください: $zipPath" -ForegroundColor Yellow
}
Write-Host ''
Write-Host '次の手順(docs/20 の「PC で 3D を作る」):'
Write-Host '  RealityScan: images フォルダを読み込んで位置合わせ → メッシュ → 書き出し(OBJ / glTF)'
Write-Host '  COLMAP     : sparse\0 に既知の姿勢があります(README.txt)'
Write-Host '  Blender    : 書き出したメッシュを読み込んで整える'
Write-Host '処理が済んだら、管理画面「AR の撮影」でサーバーの撮影を消してください。'
