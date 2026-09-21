# FnB Cloud — Backend

Laravel 12 (API `/api/v1`) + Filament 3 (back-office `/admin`) + PostgreSQL (RLS).
Panduan menjalankan ada di `../README.md`. Dokumentasi API: `../docs/api/openapi.yaml`.

Struktur modul (`app/Modules`): `Shared`, `Tenancy`, `Identity`, `Audit`, `Catalog` (menu, harga, promo,
kalkulator — lihat `../docs/adr/0003-perhitungan-harga-pajak.md`), `Sales` (shift, transaksi, void, refund, tutup hari),
`Payment` (metode bayar, payment gateway), `Sync` (antrian offline & tarik data master) — lihat
`../docs/adr/0004-transaksi-pos-sinkronisasi-pembayaran.md`, `Inventory` (bahan, resep, stok, opname, food cost) dan
`Purchasing` (pemasok, PO, penerimaan) — lihat `../docs/adr/0005-inventory-resep-pembelian.md`, dan `Reporting`
(dashboard, laporan, ekspor Excel/PDF, jadwal email; hanya membaca) — lihat `../docs/adr/0006-laporan-dashboard.md`.
Back-office ada di `app/Filament`.

Perintah penting:

```powershell
php85   # beralih ke PHP 8.5 (sekali per sesi)
php artisan migrate:fresh --seed
php artisan test
php vendor\bin\pint --test
php -d memory_limit=2G vendor\bin\phpstan analyse
php artisan fnb:partitions --months=3   # jadwalkan harian (partisi audit log & transaksi)
php artisan fnb:provision-catalog       # channel & stasiun bawaan untuk company lama (aman diulang)
php scripts/perf-menu.php               # ukur p95 endpoint menu (server di port 8123, data seed)
php scripts/perf-sales.php              # ukur p95 sinkronisasi & transaksi (server di port 8123, data seed)
php scripts/perf-inventory.php          # ukur p95 endpoint inventory & pembelian (server di port 8123, data seed)
php scripts/perf-reports.php            # ukur p95 dashboard, laporan, & ekspor (server di port 8123, data seed)
php scripts/perf-reports-volume.php     # uji volume 50 outlet × 30 hari (lihat komentar di berkas; DB terpisah)
php artisan fnb:sandbox-pay <id>        # simulasi bayar tagihan QRIS sandbox (local/testing)
php artisan inventory:post-sales        # ulangi potong stok penjualan yang gagal/terlewat (terjadwal tiap 5 menit)
php artisan reports:send-scheduled      # kirim laporan email yang jatuh tempo (terjadwal tiap 5 menit)
php artisan schedule:work               # menjalankan jadwal di komputer lokal (produksi: cron `schedule:run` tiap menit)
```

Konfigurasi pembayaran (`config/payments.php`): `PAYMENT_GATEWAY` (bawaan `sandbox`, ditolak di produksi),
`PAYMENT_SANDBOX_SECRET` (kunci HMAC webhook sandbox), `PAYMENT_INTENT_TTL` (menit, bawaan 15).

Laporan email (`FR-RPT-08`): atur `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_FROM_ADDRESS` di `.env`. Dengan `MAIL_MAILER=log` (bawaan lokal) email hanya ditulis ke
`storage/logs/laravel.log`. Data demo (`migrate:fresh --seed`) berisi riwayat penjualan dua minggu untuk Kemang dan Dago
sehingga proses seed ±1–2 menit.
