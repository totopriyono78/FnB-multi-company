# Laporan Putaran: Tahap 2 — Menu, harga, pajak & promo

- Tanggal: 2026-09-16
- Kebutuhan SRS: FR-MENU-01 s.d. FR-MENU-15, FR-POS-20 (simulasi harga), FR-DEV-03 (snapshot menu POS),
  BR-01 s.d. BR-05, BR-18, SRS §8.1 (urutan perhitungan), NFR-CMP-03 (catatan), NFR-PERF (API menu)
- Keputusan user yang dipakai: otorisasi supervisor memilih nama lalu PIN (Ya); urutan perhitungan SRS §8.1 (Ya);
  pembulatan default Rp100 terdekat, dapat diubah per outlet termasuk tanpa pembulatan.
- Status: **LOLOS** (dengan catatan pemeriksaan yang tidak dapat dijalankan di bawah)
- Jumlah siklus uji: 4 (uji → review keamanan independen → perbaikan → verifikasi ulang)

## Perubahan
- `app/Modules/Catalog` (modul baru)
  - `Domain/Pricing`: `PricingCalculator` (urutan SRS §8.1, pajak eksklusif/inklusif, SC per channel, pembulatan
    sebagai komponen terpisah), `PromotionEngine` (BR-18), `Allocation` (sisa terbesar), `ScheduleMatcher`
    (jadwal melewati tengah malam).
  - Model: kategori, menu (biasa/paket), varian, grup & pilihan modifier, isi paket, harga khusus
    outlet/channel, riwayat harga append-only, ketersediaan per outlet, stasiun dapur, channel penjualan, promo.
  - Layanan: `ItemWriter`, `ModifierGroupWriter`, `PromotionWriter`, `PromotionRules`, `CatalogRemover`,
    `AvailabilityService` (audit + event), `PriceResolver`, `PriceHistoryRecorder`, `QuoteService`,
    `PosCatalogBuilder`, `MenuSpreadsheet` (impor/ekspor xlsx/csv), `MenuCopier`, `CatalogProvisioner`.
  - API: 40 operasi baru (lihat `docs/api/openapi.yaml` versi 1.1.0-tahap2), perintah `fnb:provision-catalog`.
- `app/Filament` — grup menu **Menu & Harga**: Daftar Menu (tab informasi/varian & modifier/isi paket/channel &
  jadwal, harga khusus, riwayat harga, alat impor/ekspor/salin), Kategori Menu, Modifier, Promo, Ketersediaan Menu,
  Simulasi Harga, Stasiun Dapur, Channel Penjualan. Kolom sakelar beraksesibel (`LabeledToggleColumn`).
- `database/` — 3 migrasi (16 tabel baru, semua RLS; trigger append-only riwayat harga; default pembulatan outlet
  Rp100), `DemoMenuSeeder` (menu Kopi Tepi Jalan, Roti Bakar 88, Warung Bu Ratna), `DemoSeeder` aman diulang.
- `lang/id` — pesan validasi, autentikasi, reset password, paginasi dalam Bahasa Indonesia (sebelumnya jatuh ke
  bahasa Inggris).
- `shared/fixtures/pricing` (22 kasus) dan `shared/fixtures/promotions` (25 kasus) — dihitung manual.
- `TenantContext` — galat asli tidak lagi tertutup galat pemulihan; konteks diterapkan ulang setelah koneksi dibuat
  ulang atau transaksi dibatalkan; tim permission selalu dipulihkan.
- `docs/` — ADR 0003 (perhitungan, promo, kode promo offline, data terhapus), OpenAPI diperbarui.
- `scripts/perf-menu.php` — pengukur p95 endpoint menu.

## Hasil Quality Gate (siklus terakhir)
| # | Pemeriksaan | Hasil | Catatan |
|---|---|---|---|
| G1 | Format | ✅ Lolos | `pint --test` passed; `redocly lint` valid tanpa peringatan |
| G2 | Analisis statis | ✅ Lolos | Larastan level 6: 0 error |
| G3 | Unit test | ✅ Lolos | Fixture harga (22) & promo (25), jadwal, beli-X-gratis-Y dibandingkan perhitungan per unit pada 200 keranjang acak, uji beban 200 baris × 9.999, koneksi dibuat ulang |
| G4 | Feature/API test | ✅ Lolos | Semua route baru: status, format respons, validasi (termasuk angka `+5`/`.5`, persen > 100, SKU/kategori ganda beda huruf), impor/ekspor, salin |
| G5 | Isolasi tenant | ✅ Lolos | 20 kombinasi endpoint lintas company → 404; daftar tidak memuat data asing; referensi ID asing ditolak (API & back-office); RLS baca/tulis diuji di 16 tabel baru |
| G6 | Hak akses | ✅ Lolos | Pemilik, admin, manajer brand (brand sendiri), manajer outlet (lihat, tandai habis di outletnya), kasir (tandai habis saja), dapur; promo seluruh company hanya pengguna tingkat company; mode baca-saja langganan → 402 |
| G7 | Kalkulasi | ⚠️ Sebagian | Sisi PHP lulus semua fixture + 10 skenario end-to-end dihitung manual. **Sisi Dart TIDAK DIJALANKAN**: aplikasi Flutter belum ada (Tahap 6) dan Dart SDK tidak dapat diunduh di lingkungan build |
| G8 | Offline & sinkronisasi | ➖ Tidak relevan | Sinkronisasi transaksi di Tahap 3; snapshot menu POS + versi sudah diuji |
| G9 | Database | ✅ Lolos | `migrate:fresh --seed`, `migrate:rollback --step=3` + migrate ulang, seeder diulang tanpa galat, `relrowsecurity` aktif di 16 tabel baru |
| G10 | E2E / UI | ✅ Lolos | Playwright 7 skenario (5 lama + 2 baru: kelola menu/promo/simulasi, kasir tandai habis) |
| G11 | Aksesibilitas | ✅ Lolos | axe WCAG 2.1 AA tanpa pelanggaran serius (9 pemeriksaan di 7 halaman baru, termasuk kondisi galat & pencarian aktif); sakelar bisa dioperasikan keyboard; label untuk tombol ikon & tab bawaan Filament |
| G12 | Desain natural | ✅ Lolos | Hanya token warna/radius/spasi; tanpa emoji/gradasi; teks Bahasa Indonesia; angka rata kolom |
| G13 | Kinerja | ✅ Lolos | Mode strict (lazy loading dilarang) aktif di test. p95 (server PHP bawaan, data seed): daftar menu 52 ms, detail 54 ms, promo 55 ms, simulasi harga 68 ms, snapshot POS 66 ms (210 ms dengan 310 menu × 2 varian), simulasi POS 56 ms |
| G14 | Keamanan | ✅ Lolos | Review keamanan independen: 4 Medium + 4 Low ditemukan, semuanya diperbaiki dan diverifikasi ulang (lihat tabel). `npm audit --omit=dev`: 0. Pemeriksaan advisori PHP: 154 paket dicocokkan ke basis data FriendsOfPHP/security-advisories (per 11 Sep 2026): 0 temuan |
| G15 | Kebutuhan | ✅ Lolos | FR-MENU-01..15 terpenuhi di API & back-office; FR-MENU-13 (promo otomatis) terpenuhi di simulasi/POS catalog; penerapan pada transaksi di Tahap 3 |
| G16 | Regresi | ✅ Lolos | Seluruh suite |

Ringkasan test: **373 lulus, 0 gagal, 0 dilewati** (1.981 asersi, ±150 dtk berurutan) + E2E **7 lulus** (±72 dtk).

## Temuan & Perbaikan
| Siklus | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 1 | High | `ModifierGroupRequest` membaca parameter route yang salah → permintaan ubah grup divalidasi seperti pembuatan baru dan selalu ditolak | Nama parameter diperbaiki |
| 1 | Medium | Kategori & SKU ganda beda huruf lolos validasi lalu gagal di database (500) | Aturan unik tanpa membedakan huruf per brand |
| 1 | Medium | Impor ulang menu 500 (lazy loading varian) | Eager loading |
| 1 | Medium | Mengubah target menu promo menghapus target kategori | Sinkron per jenis target |
| 1 | Medium | Galat asli tertutup galat pemulihan `TenantContext` saat transaksi batal | `guarded()` melempar galat asli |
| 1 | Medium | Pesan validasi berbahasa Inggris | `lang/id/*` + tes |
| 1 | Medium | Ketersediaan menu hanya menampilkan outlet yang pernah diubah | Semua outlet brand dalam cakupan |
| 2 | Medium | Tombol ikon/tab/repeater/choices bawaan Filament melanggar ARIA; sakelar tanpa nama & tidak bisa dengan keyboard | Label & perbaikan ARIA (`a11y-labels`), `LabeledToggleColumn`, select native, CheckboxList |
| 2 | Medium | Dua asersi E2E Tahap 1 lolos secara keliru (menunggu URL yang juga cocok dengan halaman login) | Pola URL diperbaiki; asersi manajer outlet disesuaikan dengan matriks SRS §12.1 (manajer outlet **melihat** brand, tanpa tombol tambah) dan diperketat |
| 3 | Medium | Panel harga khusus menerima ID channel/varian company lain | `->in()` + pemeriksaan ulang di server |
| 3 | Medium | Form promo menerima ID outlet/menu/kategori company lain | `PromotionWriter::assertOwned()` (company + brand) |
| 3 | Medium | Beli-X-gratis-Y bisa menahan worker (9,6 dtk, 307 MB) | Algoritma per kelompok harga (0,07 dtk) |
| 3 | Medium | Ekspor Excel menjadikan nama berawalan `=` sebagai rumus | Semua sel ditulis sebagai teks + tes XML |
| 3 | Low | Angka tidak lazim & diskon > 100% → 500 | Regex desimal, batas persen, galat kalkulator → 422 |
| 3 | Low | Kode promo asli terkirim ke perangkat; diskon manual bisa disimulasikan token perangkat | `code_hash` PBKDF2 (salt id promo); diskon manual butuh kasir dengan `pos.discount` |
| 3 | Low | `TenantContext` tidak memulihkan tim permission/role setelah galat atau koneksi baru | `finally`, `resyncSafely()` pada `ConnectionEstablished` & `TransactionRolledBack` |
| 3 | Low | Pilihan Filament tidak divalidasi (jenis menu, warna, channel, dsb.), `sort_order`/kuota melebihi kolom → 500, file sementara ekspor tertinggal, riwayat harga outlet lain terlihat oleh pengguna per outlet | `->in()`/aturan bersarang, batas 32767/1e9, file dibersihkan, penyaringan per cakupan |

## Belum Selesai / Risiko
- **Validasi konsultan pajak**: rumus harga termasuk pajak + service charge (ADR 0003) perlu dikonfirmasi sebelum pilot.
- **G7 sisi Dart TIDAK DIJALANKAN** — fixture siap dipakai `flutter test` di Tahap 6.
- **Pengujian di PHP 8.5 TIDAK DIJALANKAN** — build & test memakai PHP 8.4; mohon jalankan `php artisan test` di komputer Anda.
- **Pemeriksaan advisori PHP** memakai basis data FriendsOfPHP; advisori yang hanya ada di GitHub Advisory Database tidak tercakup (`composer audit` resmi tidak dapat menjangkau Packagist dari lingkungan build).
- Kode promo pendek tetap dapat ditebak offline oleh pemegang token perangkat (lebih lambat karena PBKDF2); server wajib memvalidasi ulang saat transaksi disinkronkan (Tahap 3).
- Batas diskon per role & otorisasi supervisor untuk diskon manual ditegakkan saat transaksi disimpan (Tahap 3); simulasi hanya memeriksa izin `pos.discount`.
- Status "habis" belum otomatis direset saat tutup hari (Tahap 3) dan belum dikirim real-time ke POS lain (event `ItemAvailabilityChanged` sudah ada; siaran Reverb di Tahap 3/6).
- Snapshot POS 310 menu = p95 210 ms (masih di bawah 300 ms); untuk katalog sangat besar perlu cache per versi.
- Perbaikan ARIA untuk komponen bawaan Filament memakai skrip kecil; hapus bila Filament sudah memperbaikinya.
- File CSS build lama `public/build/assets/theme-DdwvIf2L.css` di komputer Anda tidak dipakai lagi dan aman dihapus.

## Cara Verifikasi Manual
1. `php85` lalu `php artisan migrate:fresh --seed` dan `php artisan serve`.
2. Buka `http://127.0.0.1:8000/admin`, klik akun demo **Rina Hartono** → menu **Menu & Harga → Daftar Menu**: terlihat menu Kopi Tepi Jalan & Roti Bakar 88 lengkap dengan varian.
3. **Simulasi Harga**: pilih outlet Kemang, menu Croissant Butter → Hitung. Total **Rp24.200** (22.000 + PB1 10%).
   Ganti ke Kopi Susu Tepi Jalan varian Large + Normal + Dingin + Extra Shot → total **Rp33.000**.
4. **Ketersediaan Menu**: masuk sebagai **Andi Saputra** (kasir) → tandai Pisang Goreng Keju habis; sakelar "Tampil di POS" tidak bisa diubah.
5. **Promo**: buka "Happy Hour Kopi 20%" — berlaku Senin–Jumat 14.00–17.00 di Kemang & Dago, maksimal potongan Rp10.000.
6. Coba ubah pembulatan outlet (Outlet → tab Pajak & Harga) menjadi "Tanpa pembulatan", lalu ulangi simulasi.
