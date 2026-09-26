# Laporan Putaran: Respons back-office lebih ringan & cepat

- Tanggal: 2026-09-26
- Kebutuhan SRS: NFR-PERF (respons API p95 ≤ 300 ms; navigasi terasa instan)
- Status: LOLOS
- Jumlah siklus uji: 3

## Temuan pengukuran awal (cloud, server bawaan PHP + OPcache, data seed)
| Temuan | Dampak |
|---|---|
| Setiap klik menu memuat ulang halaman penuh: 10–12 permintaan (CSS, JS Filament/Livewire/Alpine, huruf). Server bawaan PHP (`php artisan serve`) tidak mengirim header cache, jadi aset diunduh ulang tiap klik — dan di Windows server itu hanya satu proses, semua dilayani bergantian. | "Selesai" rata-rata ±1,1 dtk per klik |
| Menu navigasi memanggil cek akses per halaman; tiap cek menjalankan query outlet yang sama (hingga 14× per halaman; Transaksi 100 query). | ±15–60 query sia-sia per halaman |
| Dasbor memuat 5 widget secara *lazy* → 5 permintaan susulan setelah halaman tampil. | Ringkasan baru lengkap ±1,4 dtk |
| Tanpa OPcache waktu server naik ±3× (463–726 ms vs 132–273 ms). `php artisan serve` di Windows biasanya berjalan **tanpa** OPcache CLI. | Penyebab terbesar rasa lambat di komputer lokal |

## Perubahan
- `AdminPanelProvider` — **mode SPA Filament** (`->spa()`, saklar `FNB_SPA`, bawaan aktif). `/pos` dikecualikan (selalu dimuat penuh).
- `AccessScope` — daftar outlet dalam cakupan dimuat **sekali per request** (`outlets()`, `outletIds()`, `activeOutletOptions()`);
  dipakai `SalesAccess`, `InventoryAccess`, `ReportAccess`, `EndOfDay`, `MenuFields`. Simpanan dibuang otomatis saat
  Outlet/RoleScope disimpan atau dihapus (`ModulesServiceProvider`). Isolasi tenant tetap: kunci simpanan = company + user.
- Widget dasbor (`SalesToday`, `SalesByHourChart`, `SalesBreakdownToday`, `OperationalAlerts`, `DeviceHealth`) — `isLazy = false`:
  satu request, bukan enam.
- `a11y-labels.blade.php` — perbaikan aksesibilitas dijalankan paling banyak sekali per frame (sebelumnya pada setiap mutasi DOM).
- `scripts/serve-cepat.ps1` — menjalankan server lokal dengan OPcache + `php artisan optimize` (cache config/rute/view/komponen Filament).
- `.env.example` — `FNB_SPA=true`.
- Uji: `tests/Feature/Identity/AccessScopeCacheTest.php` (4 test: sekali query, urutan & filter aktif, invalidasi saat outlet
  ditambah/dihapus, cakupan staf & isolasi antar-company). E2E: helper `tests/Browser/support/spa.js` (`klikNavigasi`) menunggu
  navigasi SPA selesai; uji unggah foto menunggu FilePond siap (dipasang saat kolom terlihat).

## Hasil (cloud, OPcache aktif, rata-rata 11 halaman, klik menu sidebar)
| | Sebelum | Sesudah |
|---|---|---|
| Halaman tampil | 456 ms | **328 ms** |
| Halaman selesai (semua permintaan) | 1.135 ms | **379 ms** |
| Permintaan per klik | 10–12 | **1** |
| Query halaman statis (Dashboard Holding) | 51 | **32** |
| Query halaman Transaksi | 100 | **37** |
| Ringkasan lengkap (semua widget) | ±1.450 ms, 17 permintaan | ±330 ms, 1 permintaan |

Di komputer Windows dengan server satu proses, selisihnya lebih besar karena 10+ permintaan aset per klik dilayani bergiliran.

## Hasil Quality Gate
| # | Pemeriksaan | Hasil | Catatan |
|---|---|---|---|
| G1 | Pint | ✅ Lolos | |
| G2 | Larastan level 6 | ✅ 0 galat | |
| G3/G4/G16 | Pest | ✅ 620 lulus, 0 gagal | +4 test baru |
| G5 | Isolasi tenant | ✅ | simpanan per company+user; test lintas company |
| G10 | Playwright | ✅ 19 lulus | dijalankan dengan jam server & browser disamakan (faketime 14.21 WIB) |
| G11 | Aksesibilitas (axe) | ✅ | termasuk setelah navigasi SPA |
| G13 | Kinerja | ✅ | lihat tabel di atas |

## Temuan & Perbaikan
| Siklus | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 1 | High | 14 E2E gagal: dua rangkaian Playwright berjalan bersamaan (proses lama tidak mati saat batas waktu) | ulangi dengan satu rangkaian |
| 2 | Medium | 3 E2E balapan dengan SPA: isian terisi di halaman lama sebelum halaman baru terpasang; FilePond belum terpasang saat berkas dipilih | helper `klikNavigasi` + tunggu FilePond |
| 2 | Low | Uji dasbor "Peringkat outlet" gagal bila dijalankan pagi (baru 1 outlet bertransaksi hari itu) — rapuh jam, bukan akibat putaran ini | dijalankan dengan jam 14.21 WIB |
| 3 | Low | Uji retur POS gagal bila jam browser ≠ jam server (artefak faketime) | jam disamakan |

## Sisa Catatan
- Koneksi persisten PostgreSQL **sengaja tidak dipakai**: RLS memakai `SET ROLE`/`set_config` tingkat sesi; koneksi yang dipakai ulang
  berisiko membawa konteks tenant request sebelumnya.
- Kompresi gzip tidak ditambahkan di aplikasi: untuk localhost tidak berguna; di Railway ditangani server depan.
