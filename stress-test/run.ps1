# Menjalankan stress test k6 dan menyimpan hasilnya di folder results\
#
#   .\run.ps1 pos smoke     # cek alur POS (1 menit)  <= jalankan ini dulu
#   .\run.ps1 pos load      # beban jam sibuk (~9 menit)
#   .\run.ps1 pos stress    # naik bertahap sampai server kewalahan (~16 menit)
#   .\run.ps1 pos spike     # lonjakan mendadak (~5 menit)
#   .\run.ps1 pos soak      # ketahanan 30 menit
#   .\run.ps1 web stress    # kapasitas mentah server web tanpa login (~10 menit)
#
# Hasil: results\<target>-<profil>-<waktu>.json (ringkasan), .html (laporan grafik), .log (console)

param(
    [ValidateSet('pos', 'web')] [string] $Target = 'pos',
    [ValidateSet('smoke', 'load', 'stress', 'spike', 'soak')] [string] $Mode = 'smoke'
)

Set-Location -Path $PSScriptRoot
New-Item -ItemType Directory -Force -Path results | Out-Null

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$base = "results\$Target-$Mode-$stamp"

$env:K6_WEB_DASHBOARD = 'true'
$env:K6_WEB_DASHBOARD_EXPORT = "$base.html"
$env:K6_WEB_DASHBOARD_PERIOD = '5s'

Write-Host "Menjalankan $Target-stress.js profil $Mode - dasbor langsung: http://127.0.0.1:5665" -ForegroundColor Cyan
k6 run -e PROFILE=$Mode --summary-export "$base.json" --console-output "$base.log" "$Target-stress.js"

Remove-Item Env:K6_WEB_DASHBOARD, Env:K6_WEB_DASHBOARD_EXPORT, Env:K6_WEB_DASHBOARD_PERIOD -ErrorAction SilentlyContinue
Write-Host "Selesai. Hasil: $base.json / .html / .log" -ForegroundColor Green
