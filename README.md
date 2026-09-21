# FnB Cloud — Platform SaaS F&B Multi-Company

Repository monorepo platform FnB Cloud. Kebutuhan lengkap (SRS) dan pedoman kerja (`CLAUDE.md`) disimpan di
Project Claude "F&B Multi Company"; salin keduanya ke `docs/SRS_Platform_FnB_Multi_Company.md` dan `CLAUDE.md`
bila repository dipakai di luar Claude.

| Folder | Isi | Status |
|---|---|---|
| `backend/` | Laravel 12 API + back-office Filament 3 | Tahap 1–3 selesai |
| `apps/pos`, `apps/kds`, `apps/owner` | Aplikasi Flutter | Belum dimulai |
| `apps/selforder` | PWA self-order (Fase 2) | Belum dimulai |
| `shared/fixtures/` | Fixture bersama PHP–Dart (harga/pajak, promo) | Sisi PHP aktif; sisi Dart di Tahap 6 |
| `docs/` | SRS, ADR, OpenAPI, laporan uji | Aktif |

## Rencana bertahap (MVP)

| Tahap | Cakupan | Status |
|---|---|---|
| 1 | Fondasi: multi-tenant (global scope + RLS), organisasi (company/brand/outlet/perangkat), auth, role & cakupan, PIN, audit log, back-office dasar | **Selesai** — lihat `docs/test-reports/2026-09-15-tahap-1-fondasi.md` |
| 2 | Menu, harga per outlet/channel, modifier, paket, promo, kalkulator harga/pajak + fixture bersama | **Selesai** — lihat `docs/test-reports/2026-09-16-tahap-2-menu-harga-promo.md` |
| 3 | POS server: shift, order, pembayaran tunai/QRIS/EDC manual, sinkronisasi offline (push/pull idempoten) | **Selesai** — lihat `docs/test-reports/2026-09-16-tahap-3-pos-server.md` |
| 4 | Inventory & resep, pengurangan stok otomatis, opname | Berikutnya |
| 5 | Laporan & dashboard, ekspor Excel/PDF | |
| 6 | Aplikasi POS Flutter (offline-first) + integrasi printer | |

## Menjalankan di Windows (PowerShell, PHP 8.5)

`php85` adalah skrip untuk **beralih** ke PHP 8.5. Jalankan sekali di awal sesi PowerShell,
lalu semua perintah memakai `php` seperti biasa.

Prasyarat: PHP 8.5 dengan ekstensi `pdo_pgsql`, `pgsql`, `intl`, `mbstring`, `openssl`, `fileinfo`, `zip`;
PostgreSQL 15+ dengan database `fb_multicompany`.

```powershell
cd D:\DEVELOPMENT\FB_Multi_Company\backend

# 1. Beralih ke PHP 8.5, lalu cek versi & ekstensi
php85
php -v
php -m | Select-String -Pattern "pdo_pgsql|intl|mbstring"

# 2. Siapkan .env (sudah disediakan; sesuaikan bila perlu)
#    DB_DATABASE=fb_multicompany  DB_USERNAME=postgres  DB_PASSWORD=postgres

# 3. Buat tabel + data demo
php artisan migrate:fresh --seed

# 4. Jalankan server
php artisan serve
```

Buka `http://127.0.0.1:8000/admin`. Dengan `FNB_DEMO_LOGIN=true` di `.env`, daftar akun demo tampil di bawah form login
dan cukup diklik untuk masuk (jangan aktifkan di produksi). Akun demo (password semua: `Rahasia123`):

| Peran | Email |
|---|---|
| Super Admin platform | platform@fnbcloud.test |
| Pemilik PT Kopi Nusantara Sejahtera | rina@kopinusantara.test |
| Admin company | bayu@kopinusantara.test |
| Manajer outlet Kemang (PIN 482915) | dewi@kopinusantara.test |
| Kasir Kemang (PIN 7351) | andi@kopinusantara.test |
| Pemilik CV Dapur Bu Ratna | ratna@dapurburatna.test |

Data demo sudah berisi menu (Kopi Tepi Jalan, Roti Bakar 88, Warung Bu Ratna) lengkap dengan varian, modifier,
paket sarapan, harga khusus GoFood/GrabFood, dan promo. Menu back-office: **Menu & Harga** → Daftar Menu, Kategori,
Modifier, Promo, Ketersediaan Menu, Simulasi Harga, Stasiun Dapur, Channel Penjualan.

Outlet Kemang juga berisi transaksi contoh: shift kemarin (sudah ditutup, ada void, refund, QRIS, dan selisih kas)
beserta tutup harinya, serta shift hari ini yang masih berjalan. Menu back-office: **Penjualan** → Transaksi,
Shift Kasir, Tutup Hari; metode pembayaran & MDR diatur di halaman ubah Outlet.

QRIS/e-wallet memakai **gateway sandbox** sampai mitra payment gateway dipilih. Simulasikan pembayaran tagihan dengan
`php artisan fnb:sandbox-pay <id-tagihan>` (hanya lingkungan local/testing).

### Memperbarui dari tahap sebelumnya

```powershell
php85
php artisan migrate:fresh --seed        # data demo dibuat ulang (menghapus data lama)
# atau, bila ingin mempertahankan data: php artisan migrate ; php artisan fnb:provision-catalog
# Tahap 3 menambah partisi transaksi; jadwalkan harian: php artisan fnb:partitions --months=3
php artisan optimize:clear
```

### Menjalankan test

```powershell
# Sekali saja: buat database uji
& "C:\Program Files\PostgreSQL\15\bin\createdb.exe" -U postgres fb_multicompany_test

php artisan test                 # atau: php vendor\bin\pest --parallel
php vendor\bin\pint --test
php -d memory_limit=2G vendor\bin\phpstan analyse
```

E2E back-office (butuh Node.js): `npm ci`, `npx playwright install chromium`,
jalankan `php artisan serve --port=8123`, lalu `npx playwright test`.

### Catatan dependensi

`vendor/` sudah disertakan sehingga `composer install` tidak wajib. `composer.lock` saat ini
berisi sumber git (dibuat tanpa akses Packagist). Bila komputer Anda punya Composer dan akses
internet, jalankan `php composer.phar update --lock` agar lock memakai paket dist biasa,
lalu `php composer.phar audit`.
