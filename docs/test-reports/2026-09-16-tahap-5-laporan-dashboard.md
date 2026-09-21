# Laporan Putaran: Tahap 5 — Laporan & dashboard

- Tanggal: 2026-09-16
- Kebutuhan SRS: FR-RPT-01..08, FR-RPT-10; NFR-PERF-05/07/08; NFR-SCL-05 (persiapan); FR-AUD-01 (ekspor & jadwal)
- Keputusan user yang dipakai: refund mengurangi pajak **di periode refund**; laba kotor = penjualan bersih − **HPP bahan
  terjual** dengan waste & selisih stok di baris terpisah; **Excel & PDF + jadwal email** harian/mingguan/bulanan; PPN
  pembelian ditunda ke modul Akuntansi.
- Desain: `docs/adr/0006-laporan-dashboard.md`
- Status: **LOLOS** (dengan catatan pemeriksaan yang tidak dapat dijalankan di bawah)
- Jumlah siklus uji: 5 (hitung manual → API & isolasi → jadwal → panel & E2E → tinjauan tangkapan layar & volume)

## Perubahan
- `database/migrations/2026_09_19_000100_create_reporting_tables.php` — `report_schedules`, `report_deliveries`
  (append-only, satu kiriman sukses per jadwal-periode), RLS di keduanya; indeks laporan `payments`, `cash_movements`,
  `stock_line_postings` per outlet & hari bisnis.
- `app/Modules/Reporting` (baru, hanya membaca data modul lain)
  - `ReportAccess` & `ReportFilter` — cakupan outlet user (report.sales.* / inventory.view), filter brand/outlet (di luar
    cakupan → 404), periode hari bisnis maks. 366 hari, bawaan awal bulan s.d. hari ini (zona waktu company).
  - `SalesReport` — ringkasan & 10 dimensi (hari, jam, hari dalam minggu, outlet, brand, kategori, item, channel, kasir,
    metode bayar). `RefundFacts` menguraikan refund ke porsi penjualan/pajak/service/DPP dan ke baris item (termasuk
    refund sisa tanpa rincian).
  - `TaxReport` (PB1/PBJT & service charge per outlet dan tarif, koreksi refund), `FraudReport` (per pengguna + rincian
    kejadian), `MenuEngineeringReport` (Kasavana & Smith), `GrossProfitReport`, `InventoryReport` (mutasi, waste, hasil
    opname, posisi stok), `DashboardReport` (hari ini per hari bisnis outlet, minggu lalu sampai jam yang sama, kemarin,
    per jam, menu terlaris, peringkat outlet, pembayaran).
  - `ReportTable` + `ReportCatalog` (19 laporan), `Export/XlsxExporter` (OpenSpout, angka tetap angka),
    `Export/PdfExporter` (Dompdf, font subset), `ReportExporter`.
  - `ReportScheduler` + `reports:send-scheduled` (tiap 5 menit) + `ScheduledReportMail` (HTML & teks).
  - API: `GET dashboard`, `GET reports`, `GET reports/{laporan}`, `GET reports/{laporan}/export`,
    `GET reports/fraud/events`, `report-schedules` (daftar, buat, detail, ubah, aktifkan, nonaktifkan). Pembatas laju
    `reports` 30/menit per user. Ekspor dicatat `report.exported` di audit log.
- `app/Filament` — grup **Laporan**: Penjualan, Menu Terlaris, Anti-Fraud, Pajak & Service, Laba Kotor, Inventory
  (`Support/ReportPage` bersama: filter, ringkasan, tabel, ekspor), Jadwal Email (resource). **Ringkasan**: filter
  brand/outlet, widget penjualan hari ini, grafik per jam, menu terlaris/peringkat outlet/pembayaran.
  Label "Penjualan bersih" di Tutup Hari & laporan shift diganti "Total diterima (setelah refund)" karena angkanya
  termasuk pajak. Perbaikan ARIA tag input & tombol tutup notifikasi di `a11y-labels`.
- `barryvdh/laravel-dompdf` 3.1 (dompdf 3.1.6) ditambahkan.
- `database/seeders/DemoReportSeeder.php` — riwayat 2 minggu Kemang (kasir drive-thru) & Dago (±690 transaksi) dengan
  diskon manual, void, refund, selisih kas, tutup hari, belanja rutin, dan 3 jadwal email contoh. `DemoSalesSeeder::sell`
  kini dapat memberi diskon manual; opname demo tidak lagi bisa menghasilkan jumlah fisik minus.
- `docs/api/openapi.yaml` 1.4.0-tahap5 (10 operasi baru), ADR 0006, README, `scripts/perf-reports.php`,
  `scripts/perf-reports-volume.php`.
- Test: `tests/Feature/Reporting/*` (4 berkas, 29 test), `tests/Feature/Backoffice/ReportPanelTest.php` (22),
  `tests/Browser/reports.spec.js` (3), fixture `tests/Support/ReportFixture.php` (angka dihitung manual).

## Hasil Quality Gate (siklus terakhir)
| # | Pemeriksaan | Hasil | Catatan |
|---|---|---|---|
| G1 | Format | ✅ Lolos | `pint --test` passed; OpenAPI lolos `openapi-spec-validator` |
| G2 | Analisis statis | ✅ Lolos | Larastan level 6: `[OK] No errors` |
| G3 | Unit test | ✅ Lolos | Periode & slot jadwal (harian/mingguan/bulanan, zona waktu), format angka Indonesia diuji lewat skenario |
| G4 | Feature/API test | ✅ Lolos | Semua 10 operasi baru: status, amplop respons, validasi (periode terbalik, >1 tahun, format tanggal, format ekspor, laporan tak dikenal), pembatas laju 429 |
| G5 | Isolasi tenant | ✅ Lolos | Outlet/brand company lain → 404 (laporan, ekspor, dashboard, jadwal); pemilik company lain melihat 0 baris di 8 laporan; jadwal company lain → 404; RLS aktif di 2 tabel baru |
| G6 | Hak akses | ✅ Lolos | Kasir/dapur 403 & menu tidak tampil; gudang hanya laporan inventory; manajer outlet hanya outletnya (termasuk jadwal); admin company boleh menonaktifkan jadwal orang lain tetapi tidak mengubah; jadwal dibangun dengan hak akses pembuat saat dikirim (pembuat nonaktif/dipindah → jadwal nonaktif, data tidak terkirim) |
| G7 | Kalkulasi | ⚠️ Sebagian | Fixture dua hari dihitung manual: penjualan bersih, diskon, pajak, service, total diterima, refund lintas hari (porsi 24.999,996…), DPP & koreksi pajak, alokasi item, anti-fraud (rasio 65,10%), laba kotor (margin 62,94%, food cost 52,94%), menu engineering, mutasi stok. **Sisi Dart TIDAK DIJALANKAN** (tidak ada kalkulasi laporan di POS) |
| G8 | Offline & sinkronisasi | ✅ Lolos | Refund offline untuk transaksi hari sebelumnya tercatat di hari refund; laporan periode lama tidak berubah; pengiriman email idempoten per periode, susulan satu per satu (maks. 3 hari tertinggal), gagal diulang 3× |
| G9 | Database | ✅ Lolos | `migrate:fresh --seed` (exit 0); `migrate:rollback --step=1` + `migrate` (exit 0); `relrowsecurity = t` di 2 tabel; hapus riwayat pengiriman ditolak (append-only) |
| G10 | E2E / UI | ✅ Lolos | Playwright 15 skenario (12 lama + 3 baru) — 15 lulus |
| G11 | Aksesibilitas | ✅ Lolos | axe WCAG 2.1 AA tanpa pelanggaran serius di 9 halaman baru (2 temuan vendor diperbaiki); tabel `<th scope>`, baris berlabel, wilayah gulir berlabel & dapat difokus; naik/turun tidak hanya warna (teks "Naik/Turun") |
| G12 | Desain natural | ✅ Lolos | Warna dari token (CSS & salinan `DesignTokens` untuk grafik/PDF); tanpa emoji/gradasi; label Bahasa Indonesia; tangkapan layar ditinjau (lihat temuan) |
| G13 | Kinerja | ✅ Lolos | p95 data demo (server PHP bawaan): dashboard 152 ms; laporan 133–155 ms; ekspor xlsx 156 ms; ekspor PDF 371 ms (bukan operasi transaksi). **Volume 50 outlet × 30 hari (300.000 transaksi, 600.000 item)**: laporan 0,4–2,9 dtk (terlama per item 2,9 dtk), dashboard 0,7 dtk, ekspor 1,1–1,3 dtk — di bawah target 10 dtk (NFR-PERF-08). Lazy loading dilarang di test |
| G14 | Keamanan | ✅ Lolos | `composer audit`: tidak ada advisori; `npm audit --omit=dev`: 0. Input tervalidasi; kunci laporan dibatasi pola rute; berkas ekspor sementara di storage privat dan dihapus setelah dikirim; penerima email dibatasi 10 & diaudit; PDF tanpa akses remote/PHP |
| G15 | Kebutuhan | ✅ Lolos | Lihat rincian di bawah |
| G16 | Regresi | ✅ Lolos | Seluruh suite |

Ringkasan test: **593 lulus, 0 gagal, 0 dilewati** (51 test baru) + E2E **15 lulus** (3,4 menit).

Pemenuhan kebutuhan (G15):
- FR-RPT-01 dashboard real-time per company/brand/outlet dengan pembanding; FR-RPT-02 laporan per periode, jam, hari,
  outlet, brand, kategori, item, channel, kasir, metode bayar; FR-RPT-03 menu terlaris/tidak laku + Star/Plowhorse/
  Puzzle/Dog; FR-RPT-04 void, refund, diskon, selisih kas per kasir (+ ubah harga, buka laci); FR-RPT-05 pajak & service
  charge per outlet per periode; FR-RPT-06 posisi stok, mutasi, waste, hasil opname (food cost di laba kotor & halaman
  Food Cost); FR-RPT-07 laba kotor per outlet; FR-RPT-08 filter, ekspor Excel/PDF, jadwal email; FR-RPT-10 konsolidasi
  lintas brand & outlet.
- Milik tahap lain: FR-RPT-09 notifikasi push Owner App (Owner App); laba bersih & jurnal (Akuntansi, Fase 3).

## Temuan & Perbaikan
| Siklus | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 1 | Medium | Label "Penjualan bersih" di Tutup Hari berisi total termasuk pajak, berbeda dengan definisi laporan | Diganti "Total diterima (setelah refund)"; definisi baku di ADR 0006 |
| 1 | Medium | Format persen memotong, bukan membulatkan (0,05% tampil 0%) | Dibulatkan HALF_UP sebelum diformat |
| 1 | Medium | PDF 880 KB (font utuh) dan tabel anti-fraud 17 kolom terpotong | Subset font (±26 KB); mode padat & judul kolom membungkus |
| 3 | High | Jadwal yang terlewat melompati periode, dan pengulangan setelah gagal mengirim periode yang berbeda | Periode ditentukan dari slot jadwal; susulan satu per satu (maks. 3 hari), pengulangan tetap periode yang sama |
| 4 | High | Menu tanpa resep tercatat HPP 0 sehingga tampil margin 100% sebagai "Star" | Pemakaian bernilai nol tidak dipakai; jatuh ke resep teoritis atau "Belum ada HPP" |
| 4 | High | Seed demo gagal di tengah (opname menghasilkan jumlah minus setelah riwayat penjualan) sehingga password akun staf tidak terpasang | Belanja rutin di seeder riwayat; jumlah fisik opname demo dibatasi ≥ 0 |
| 4 | Medium | Petunjuk periode di form jadwal tidak berubah saat frekuensi diganti | Field frekuensi dibuat reaktif |
| 4 | Medium | Tombol hapus tag email & tombol tutup notifikasi Filament tanpa nama aksesibel (axe `button-name`) | Diberi `aria-label` di `a11y-labels` |
| 5 | Medium | Nama outlet/item terbungkus 4 baris di tabel laporan; angka ringkasan meluber dari kotak | Kolom label min. 12rem, kotak ringkasan min. 14rem dan angka dapat membungkus; kolom Item jadi kolom pertama di menu engineering |
| 5 | Low | Rincian refund dihitung dua kali per laporan | Disimpan per filter selama satu permintaan (laporan penjualan 30% lebih cepat pada uji volume) |
| 5 | Low | Token perangkat berlaku 12 jam sehingga fixture tidak bisa melompat satu hari penuh | Fixture memakai hari 1 pukul 19.00 dan hari 2 pukul 06.00 |
| 6 | High | Di komputer user (dijalankan ±00.30 WIB) 7 test gagal. (a) 2 ekspor PDF: `dompdf.wrapper` tidak terdaftar karena `bootstrap/cache/packages.php` belum diperbarui setelah paket baru disalin | Cache paket diperbarui (setara `php artisan package:discover`) |
| 6 | High | (b) Kiriman ulang `POST /pos/shifts` (dan endpoint online lain tanpa waktu dari perangkat) ditolak `CONFLICT` bila melewati pergantian detik, karena waktu yang diisi server ikut dibandingkan | Waktu yang diisi server tidak ikut pembanding duplikat (`SyncPushService::process`); test kirim ulang kini berjeda 3 detik (gagal sebelum perbaikan, lulus sesudahnya) |
| 6 | Medium | (c) 4 test bergantung jam: pukul 21.00–03.00 WIB shift "1 jam lalu" jatuh ke hari bisnis sebelumnya, sedangkan test memakai tanggal kalender | `TestCase` memindah jam test ke 12.00 WIB hari bisnis berjalan pada rentang itu; suite penuh diulang dengan jam sistem 00.15 WIB dan 14.00 WIB (faketime): 593 lulus keduanya |

Perubahan test karena data demo berubah (bukan untuk meloloskan): `tests/Browser/sales.spec.js` kini mencari struk
`KMG-POS01` lewat kotak pencarian tabel karena riwayat dua minggu membuat struk kemarin tidak lagi di halaman pertama;
selisih kas riwayat demo tidak memakai Rp2.000 agar baris shift contoh tetap unik.

## Belum Selesai / Risiko
- **Seed demo kini ±1–2 menit** (sebelumnya ±13 detik) karena ±690 transaksi dibuat lewat layanan POS asli.
- **Email terjadwal** butuh SMTP di `.env` dan scheduler (`php artisan schedule:work` lokal / cron `schedule:run`).
  Jadwal dengan pembuat yang dinonaktifkan akan nonaktif otomatis (terlihat di halaman Jadwal Email).
- **Pengukuran di php-fpm/Octane dan read replica TIDAK DIJALANKAN**; query laporan sudah terkumpul di modul Reporting.
  Uji volume memakai data SQL sintetis (bukan lewat POS) di basis data terpisah.
- Tabel lebar (anti-fraud 17 kolom, laba kotor) perlu digulir horizontal di layar 1366 px (wilayah gulir berlabel).
- Laporan pajak mengikuti rincian yang tersimpan di transaksi; **periksa ketentuan daerah sebelum pelaporan resmi**.
- Notifikasi push & Owner App (FR-RPT-09) belum. **G7 sisi Dart TIDAK DIJALANKAN**.
- **Pengujian di PHP 8.5 TIDAK DIJALANKAN** di cloud — mohon jalankan `php artisan test` di komputer Anda.
- Dependensi baru: jalankan `composer install` bila folder `vendor` tidak ikut tersalin.

## Cara Verifikasi Manual
1. `php85`, `composer install`, `php artisan migrate:fresh --seed` (±2 menit), `php artisan serve`.
2. Masuk sebagai **Rina Hartono** → **Ringkasan**: penjualan hari ini vs minggu lalu, grafik per jam, peringkat Kemang
   & Dago. Pilih Outlet = Dago → angka berubah.
3. **Laporan → Penjualan** → Kelompokkan "Per outlet", lalu "Per jam" → **Ekspor → Excel**.
4. **Anti-Fraud** → Dewi & Yohanes (void, refund, diskon manual); tampilan "Rincian kejadian".
5. **Menu Terlaris** → kelompok Star/Plowhorse/Puzzle/Dog dan saran. **Laba Kotor** → margin & food cost per outlet.
6. Masuk sebagai **Lina Kusuma** (finance) → **Pajak & Service** → Ekspor PDF; **Jadwal Email → Buat Jadwal**.
7. `php artisan reports:send-scheduled` setelah jam kirim terlewat → lihat `storage/logs/laravel.log` dan riwayat di
   halaman jadwal.
