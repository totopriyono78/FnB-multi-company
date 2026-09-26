<#
  Menjalankan FnB Cloud di komputer lokal (Windows) dengan pengaturan yang membuat
  back-office terasa jauh lebih cepat dibanding `php artisan serve` biasa.

  Mengapa:
  - `php artisan serve` menjalankan server bawaan PHP tanpa OPcache untuk CLI, sehingga setiap
    klik membaca & mengompilasi ulang ±2.000 berkas PHP (Laravel + Filament). OPcache menyimpan
    hasil kompilasi di memori — di uji cloud, waktu respons turun ±3x.
  - `php artisan optimize` menyimpan konfigurasi, rute, view, ikon, dan daftar komponen Filament,
    sehingga tidak dipindai ulang dari disk setiap request (disk Windows relatif lambat untuk ini).

  Cara pakai (dari folder backend, setelah `php85`):
      .\scripts\serve-cepat.ps1              # http://127.0.0.1:8000
      .\scripts\serve-cepat.ps1 -Port 8080
      .\scripts\serve-cepat.ps1 -Alamat 0.0.0.0   # agar tablet kasir di jaringan outlet bisa membuka /pos

  Setelah menerima berkas baru dari Claude cukup hentikan (Ctrl+C) lalu jalankan skrip ini lagi:
  simpanan `optimize` dibuat ulang otomatis. Bila mengubah .env, skrip ini juga sudah menanganinya.
  Untuk kembali ke mode biasa: `php artisan optimize:clear` lalu `php artisan serve`.
#>
param(
    [int]$Port = 8000,
    [string]$Alamat = '127.0.0.1'
)

$ErrorActionPreference = 'Stop'
$backend = Split-Path -Parent $PSScriptRoot
Set-Location $backend

Write-Host 'Menyiapkan simpanan konfigurasi, rute, view, dan komponen Filament...'
php artisan optimize:clear | Out-Null
php artisan optimize
if ($LASTEXITCODE -ne 0) { throw 'php artisan optimize gagal. Periksa pesan di atas.' }

$opcache = php -d opcache.enable_cli=1 -r "echo function_exists('opcache_get_status') && opcache_get_status(false) ? 'aktif' : 'tidak tersedia';"
Write-Host "OPcache: $opcache"
if ($opcache -ne 'aktif') {
    Write-Host 'OPcache tidak tersedia pada PHP ini. Aktifkan "zend_extension=opcache" di php.ini agar lebih cepat.' -ForegroundColor Yellow
}

$router = Join-Path $backend 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
Write-Host "FnB Cloud berjalan di http://${Alamat}:${Port}  (Ctrl+C untuk berhenti)"

Push-Location (Join-Path $backend 'public')
try {
    php `
        -d opcache.enable_cli=1 `
        -d opcache.memory_consumption=256 `
        -d opcache.interned_strings_buffer=16 `
        -d opcache.max_accelerated_files=20000 `
        -d opcache.validate_timestamps=1 `
        -d opcache.revalidate_freq=0 `
        -d realpath_cache_size=4096K `
        -d realpath_cache_ttl=600 `
        -S "${Alamat}:${Port}" $router
}
finally {
    Pop-Location
}
