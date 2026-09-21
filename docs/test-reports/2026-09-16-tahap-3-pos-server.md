# Laporan Putaran: Tahap 3 — POS server: shift, transaksi, pembayaran, sinkronisasi offline

- Tanggal: 2026-09-16
- Kebutuhan SRS: FR-POS-01..05, FR-POS-10..24 (sisi server), FR-POS-30/31/33; FR-PAY-01..08, FR-PAY-10, FR-PAY-11;
  FR-DEV-03..06; NFR-OFF-01..05; BR-05, BR-10..16, BR-20; NFR-SEC-01; NFR-PERF (API transaksi & sinkronisasi)
- Keputusan user yang dipakai: QRIS/e-wallet **melalui payment gateway** (mitra belum dipilih → driver sandbox);
  nomor struk format umum `{KODE_OUTLET}-{KODE_PERANGKAT}-{YYMMDD}-{URUT}`; pembulatan Rp100 **juga untuk non-tunai**.
- Desain: `docs/adr/0004-transaksi-pos-sinkronisasi-pembayaran.md` (termasuk pembaruan hasil tinjauan keamanan).
- Status: **LOLOS** (dengan catatan pemeriksaan yang tidak dapat dijalankan di bawah)
- Jumlah siklus uji: 4 (uji → tinjauan keamanan independen → perbaikan → verifikasi ulang ×2 → uji penuh)

## Perubahan
- `database/migrations/2026_09_17_000100_create_sales_tables.php` — 14 tabel baru, semua dengan RLS:
  `shifts`, `cash_movements`, `orders`/`order_items`/`payments` (dipartisi per bulan menurut `business_date`),
  `order_discounts`, `refunds`, `business_days`, `outlet_payment_methods`, `payment_intents`, `webhook_events`,
  `sync_versions`, `sync_batches`, `sync_receipts`. Append-only dijaga di database: hak DELETE/UPDATE dicabut dari role
  aplikasi dan trigger mengunci kolom keuangan (hanya status/void/refund yang boleh berubah; shift tertutup tidak
  dapat diubah). Kolom `devices.master_pulled_at`/`master_version` dan `shifts.master_pulled_at`.
  `fnb:partitions` kini juga membuat partisi transaksi.
- `app/Modules/Sales` (baru)
  - `ShiftService` (buka/tutup dengan hitung buta, kas masuk/keluar, buka laci), `ShiftReport` (laporan X/Z).
  - `OrderRecorder`: hitung ulang total dengan `PricingCalculator` (beda sesen ditolak), cek harga katalog, promo
    (mesin promo + kuota), batas diskon per role, pembayaran (split, kembalian hanya tunai, tagihan gateway sekali
    pakai), pembatalan sebelum bayar, tanda tinjauan (`flags`).
  - `OrderVoider` (void setelah bayar selama shift terbuka), `RefundService` (penuh/sebagian, proporsional termasuk
    pajak, tanpa selisih sen pada refund terakhir, aturan hari yang sudah ditutup), `EndOfDayService` (ringkasan,
    kunci hari, reset status habis), `Authorizations` (pelaku terverifikasi, otorisasi supervisor online/offline
    terikat transaksi & sekali pakai), `BusinessCalendar` (BR-20), `ReceiptNumber`, `SalesAccess`.
  - API POS: `pos/shifts…`, `pos/orders…`; API back-office: `shifts`, `orders`, `outlets/{id}/end-of-day`,
    `outlets/{id}/payment-methods`. Event `OrderCompleted`, `OrderVoided`, `OrderRefunded`, `BusinessDayClosed`.
- `app/Modules/Payment` (baru) — metode bayar per outlet + MDR, antarmuka `PaymentGateway`, `SandboxGateway`
  (webhook HMAC-SHA256), `PaymentIntentService` (kedaluwarsa 15 menit, polling, batal, `paid_late`),
  webhook `POST /webhooks/payment/{provider}` (idempoten), `payments/qris|{id}|{id}/cancel|{id}/simulate`,
  perintah `fnb:sandbox-pay`, `config/payments.php`.
- `app/Modules/Sync` (baru) — `POST /sync/push` (≤ 100 entitas & 1.000 baris, idempoten per entitas, bukti
  penerimaan, selisih jam perangkat), `GET /sync/pull` (versi per company, snapshot penuh: outlet, katalog, metode
  bayar, staf tanpa hash PIN), `SyncVersions` (versi naik saat data master berubah).
- `app/Filament` — grup **Penjualan**: Transaksi (daftar + filter "perlu ditinjau" + rincian), Shift Kasir (rekap &
  pergerakan kas), Tutup Hari; tab **Metode Pembayaran** di halaman ubah Outlet; statistik "Transaksi perlu ditinjau"
  di Ringkasan; perbaikan ARIA infolist Filament di `a11y-labels`.
- `database/seeders/DemoSalesSeeder.php` — transaksi contoh Kemang (shift kemarin + tutup hari, shift hari ini).
- `PosAuthController` — `valid_until` otorisasi mengikuti batas 10 menit; `QuoteController::catalog` mencatat waktu
  tarik data. `bootstrap/app.php` — format galat transaksi (`code`, `field`, `retryable`, `details`).
- `docs/api/openapi.yaml` 1.2.0-tahap3 (25 operasi baru), ADR 0004, README, `scripts/perf-sales.php`.
- Test: `tests/Feature/Sales/*` (6 berkas), `tests/Feature/Backoffice/SalesPanelTest.php`,
  `tests/Unit/Sales/SalesHelpersTest.php`, `tests/Browser/sales.spec.js`, helper `tests/Support/Pos.php`.
  `tests/Support/Factory::pairedDevice` kini mengembalikan guard ke `web` setelah request API di test yang sama
  (sebelumnya perangkat dari request sebelumnya terbaca sebagai pembuat kode pairing).

## Hasil Quality Gate (siklus terakhir)
| # | Pemeriksaan | Hasil | Catatan |
|---|---|---|---|
| G1 | Format | ✅ Lolos | `pint --test` passed; `redocly lint` OpenAPI valid tanpa peringatan |
| G2 | Analisis statis | ✅ Lolos | Larastan level 6: `[OK] No errors` |
| G3 | Unit test | ✅ Lolos | Hari bisnis (4 kasus pergantian hari & zona waktu), format nomor struk, MDR |
| G4 | Feature/API test | ✅ Lolos | Semua 25 operasi baru: status, format galat, validasi, idempoten |
| G5 | Isolasi tenant | ✅ Lolos | Perangkat/pengguna company lain: shift, transaksi, void, laporan shift, tagihan QRIS, tutup hari, metode bayar → 404/ditolak; ID transaksi company lain → `CONFLICT` tanpa membocorkan isi; RLS aktif di 14 tabel baru; role aplikasi tidak punya hak langsung ke tabel partisi (hanya lewat induk ber-RLS) |
| G6 | Hak akses | ✅ Lolos | Kasir/dapur/manajer outlet lain/manajer brand/pemilik/admin sesuai matriks; batas diskon role (0/50/100%); void/refund/ubah harga/buka laci wajib otorisasi bila pelaku tidak login PIN sendiri; tutup hari hanya `pos.end_of_day`; metode bayar hanya `outlet.manage` |
| G7 | Kalkulasi | ⚠️ Sebagian | Sisi PHP: total transaksi, diskon manual & promo, split payment, MDR, refund proporsional dihitung manual di test. **Sisi Dart TIDAK DIJALANKAN** (aplikasi Flutter Tahap 6) |
| G8 | Offline & sinkronisasi | ✅ Lolos | Antrian satu shift penuh; batch dikirim ulang (koneksi putus sebelum respons) → 5 duplicate, 0 data ganda; ID sama isi beda → konflik; transaksi sebelum shift diterima → boleh kirim ulang; QRIS belum lunas → boleh kirim ulang; harga/promo/pajak berubah saat offline → diterima dengan tanda, termasuk bila perangkat menarik data sebelum mengirim antrian |
| G9 | Database | ✅ Lolos | `migrate:fresh --seed` (exit 0), `migrate:rollback --step=1` (exit 0) + `migrate` (exit 0) + `db:seed` diulang (exit 0); `relrowsecurity = t` di 14 tabel; trigger append-only diuji (UPDATE/DELETE ditolak) |
| G10 | E2E / UI | ✅ Lolos | Playwright 9 skenario (7 lama + 2 baru: tinjau transaksi/shift/tutup hari/metode bayar; kasir tanpa akses) |
| G11 | Aksesibilitas | ✅ Lolos | axe WCAG 2.1 AA tanpa pelanggaran serius di 6 halaman baru; tabel memakai `<th scope>` dan `aria-label`; status tidak hanya dengan warna (teks badge) |
| G12 | Desain natural | ✅ Lolos | Hanya kelas token yang sudah ada (`fnb-receipt`, `fnb-totals`, `fnb-callout`); tanpa emoji/gradasi; label Bahasa Indonesia (status, tanda tinjauan, metode, channel) |
| G13 | Kinerja | ✅ Lolos (catatan) | Mode strict (lazy loading dilarang) aktif di test. p95 (server PHP bawaan, data seed): push 1 transaksi 91–96 ms; tarik snapshot penuh 81–86 ms; tarik tanpa perubahan 47 ms (n=24, dibatasi pembatas laju 120/menit — sesuai rancangan); daftar transaksi 63–67 ms; pratinjau tutup hari 56 ms. Batch bertumbuh linear ±28 ms/transaksi: 10 transaksi 322 ms, 20 transaksi 552–610 ms (lihat catatan Low) |
| G14 | Keamanan | ✅ Lolos | Tinjauan keamanan independen (2 putaran verifikasi): 4 High, 5 Medium, 6 Low ditemukan → High/Medium diperbaiki & diverifikasi (lihat tabel). `npm audit --omit=dev`: 0. Advisori PHP (basis FriendsOfPHP, 154 paket): 0 |
| G15 | Kebutuhan | ✅ Lolos | Lihat rincian di bawah; bagian yang memang milik tahap lain dicatat |
| G16 | Regresi | ✅ Lolos | Seluruh suite |

Ringkasan test: **465 lulus, 0 gagal, 0 dilewati** (2.929 asersi, 300 dtk berurutan; 92 test baru) + E2E **9 lulus** (1,5 menit).

Pemenuhan kebutuhan (G15):
- FR-POS-01..04 shift, kas masuk/keluar, hitung buta, laporan X/Z; FR-POS-05 tutup hari (semua shift tertutup,
  ringkasan, reset habis, hari terkunci); FR-POS-15 buka laci dengan otorisasi; FR-POS-16/17 void & refund dengan
  alasan dan otorisasi; FR-POS-24 nomor struk unik; FR-POS-33 audit semua aksi sensitif.
- FR-PAY-01..03 metode bayar, split, kembalian tunai; FR-PAY-04..06 QRIS/e-wallet lewat gateway (sandbox),
  webhook + polling, cegah bayar ganda; FR-PAY-10 MDR per metode.
- FR-DEV-03/04 tarik data & antrian offline idempoten; NFR-OFF: tidak ada transaksi hilang/ganda di skenario uji.
- Milik tahap lain: pencetakan struk & layar kasir (Tahap 6), pengurangan stok (Tahap 4 — event sudah tersedia),
  laporan penjualan lengkap (Tahap 5).

## Temuan & Perbaikan
| Siklus | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 1 | Medium | Kolom `cash_movements.type` terlalu pendek untuk `drawer_open` (500) | Panjang kolom 20 |
| 1 | Medium | `webhook_events` tanpa RLS (terdeteksi test isolasi) | RLS diaktifkan; tetap ditulis lewat mode sistem |
| 1 | Medium | ID transaksi yang sudah dipakai company lain → galat 500 berulang | `CONFLICT` non-retry tanpa isi |
| 1 | Medium | Infolist Filament melanggar struktur `<dl>` (axe) | Perbaikan ARIA di `a11y-labels` |
| 1 | Medium | Tab metode bayar tidak muncul sebelum digulir (lazy) | Dimuat langsung |
| 1 | Low | Refund per baris menyisakan Rp0,01 | Refund yang menghabiskan semua barang = sisa tagihan |
| 2 | High | Token perangkat dapat mengatasnamakan manajer (void/refund/diskon/ubah harga/buka laci) | Kewenangan sendiri hanya diakui untuk pelaku yang login PIN; selain itu wajib otorisasi |
| 2 | High | Harga di bawah katalog tanpa `price_override` hanya ditandai | Dianggap ubah harga kecuali harga tercatat berubah setelah tarik data |
| 2 | High | Diskon disamarkan sebagai promo (ID acak) melewati batas role | Klaim promo yang tak terjelaskan dihitung sebagai diskon manual; ID asing tidak disimpan |
| 2 | Medium | Pengaturan pajak/service dari perangkat dipercaya | Ditolak `CONFIG_MISMATCH` kecuali outlet/channel berubah setelah tarik data |
| 2 | Medium | Otorisasi buka laci dapat dipakai berulang; otorisasi tidak terikat transaksi/nominal; umur otorisasi memakai jam perangkat | Sekali pakai, `reference_id` wajib cocok, `amount`/`discount_percent` dicocokkan, batas 24 jam jam server |
| 2 | Medium | Batch sinkronisasi besar & banjir audit penolakan | Maks. 1.000 baris/batch; audit penolakan sekali per entitas & 200/jam per perangkat |
| 2 | Low/Medium | Refund tunai untuk transaksi QRIS/kartu tanpa manajer | Hanya metode asal; tunai perlu `pos.end_of_day` + tanda |
| 2 | Low | Sandbox bisa aktif di produksi; tagihan tidak terikat transaksi; `valid_until` 2 menit vs 10; ID tak valid → galat SQL | Ditolak di produksi; `order_ref` wajib sama; diseragamkan; validasi UUID |
| 3 | Medium | Acuan "berubah setelah transaksi" dapat dimundurkan perangkat, dan penjualan offline sah bisa ditolak | Acuan = waktu tarik data terakhir (jam server), dikunci saat shift dibuka |
| 4 | Low | Batch sinkronisasi 10 transaksi sedikit di atas 300 ms (p95 322 ms) | Dicatat: aplikasi POS disarankan mengirim ≤ 8 transaksi per permintaan saat online (antrian panjang tetap aman, hanya lebih lama) |
| 5 | Critical | Di komputer user (PostgreSQL Windows berzona waktu Asia/Jakarta) semua kolom `timestamptz` tersimpan bergeser 7 jam: kode pairing langsung kedaluwarsa → 34 test gagal (Tahap 2) dan semua test yang memakai perangkat gagal (Tahap 3). Tidak terlihat di cloud karena server basis data ber-UTC | Sesi koneksi `pgsql` dipaksa `timezone = UTC` (`config/database.php`); suite penuh diulang dengan zona waktu server PostgreSQL Asia/Jakarta: 465 lulus |
| 6 | High | Di komputer user (PHP 8.5, `memory_limit` 128M) suite penuh berhenti dengan *Allowed memory size exhausted* di `FileinfoMimeTypeGuesser` karena seluruh test berjalan dalam satu proses | `phpunit.xml` menetapkan `memory_limit=1G`; diverifikasi di cloud dengan `php -d memory_limit=128M vendor/bin/pest`: 465 lulus |
| 4 | Medium | Suntingan menu non-harga membenarkan harga rendah; perangkat yang tak pernah tarik data dengan klaim promo ditolak permanen | Hanya riwayat harga yang dipakai; `SYNC_REQUIRED` (boleh kirim ulang) |

Perubahan test karena kebutuhan berubah (bukan untuk meloloskan): skenario yang sebelumnya memakai ID manajer lewat
token perangkat kini memakai token PIN manajer (aturan pelaku terverifikasi); klaim promo tak dikenal kini wajib
otorisasi; transaksi uji panel memakai harga di atas katalog karena harga di bawah katalog kini dianggap ubah harga;
otorisasi uji kini menyebut `reference_id`.

### Konfirmasi di komputer user

Windows, PHP 8.5, PostgreSQL berzona waktu Asia/Jakarta: `php artisan test --log-junit storage\logs\junit.xml` (16-09-2026 16.47 WIB) — 465 test, 2.929 assertion, 0 gagal, 0 error, 0 dilewati (1.028,8 detik).

## Belum Selesai / Risiko
- **Otorisasi supervisor mode offline** belum membawa bukti PIN bertanda tangan (dirancang Tahap 6): diterima bila
  supervisor berwenang dan selalu ditandai `offline_authorization` untuk ditinjau (statistik di Ringkasan).
- **Kas keluar** cukup dengan izin `pos.shift` (sesuai SRS); pertimbangkan batas nominal/otorisasi bila diperlukan.
- **Mitra payment gateway belum dipilih** (SRS §12.3 no. 1): produksi memerlukan driver mitra; sandbox ditolak di produksi.
- **Aplikasi POS wajib mengirim antrian sebelum menarik data**. Bila urutan dilanggar, perubahan harga di antara
  pembukaan shift dan tarik data berikutnya tetap diterima dengan tanda (lebih longgar, bukan ditolak).
- Perubahan harga modifier belum punya riwayat; perubahan apa pun pada modifier setelah tarik data membenarkan
  harga modifier lama (Low).
- **G7 sisi Dart TIDAK DIJALANKAN** (Tahap 6). **Pengujian di PHP 8.5 TIDAK DIJALANKAN** — build & test memakai
  PHP 8.4; mohon jalankan `php artisan test` di komputer Anda.
- **`composer audit` resmi TIDAK DIJALANKAN** (Packagist tidak terjangkau); diganti pencocokan basis FriendsOfPHP.
- Snapshot penuh dikirim setiap versi berubah (termasuk perubahan status habis); sinkronisasi per baris dapat
  ditambahkan tanpa mengubah kontrak (`mode`).
- Jadwalkan `php artisan fnb:partitions --months=3` harian agar partisi transaksi bulan berikutnya selalu tersedia
  (partisi `default` menampung bila terlewat).
- File CSS build lama di komputer Anda (`public/build/assets/theme-*.css` selain yang tercantum di `manifest.json`)
  tidak dipakai lagi dan aman dihapus.

## Cara Verifikasi Manual
1. `php85`, `php artisan migrate:fresh --seed`, `php artisan serve`, buka `http://127.0.0.1:8000/admin`.
2. Masuk sebagai **Rina Hartono** → **Penjualan → Transaksi**: 11 transaksi Kemang (kemarin & hari ini). Buka struk
   berakhiran `-0007` → status "Refund sebagian", refund Croissant gosong Rp24.200.
3. **Shift Kasir** → shift kemarin: kas seharusnya Rp707.000, dihitung Rp705.000, selisih −Rp2.000 dengan keterangan.
4. **Tutup Hari** → pilih Kemang: hari ini masih ada 1 shift terbuka (Siti Nurhaliza), tombol "Tutup hari" nonaktif;
   ganti tanggal ke kemarin → "Hari bisnis sudah ditutup", penjualan bersih Rp452.100.
5. **Outlet → Kopi Tepi Jalan Kemang → Metode Pembayaran**: aktifkan E-Wallet atau isi MDR QRIS 0,7%.
6. Uji QRIS lewat API (token perangkat): `POST /api/v1/payments/qris`, lalu
   `php artisan fnb:sandbox-pay <id>` → status tagihan menjadi `paid`.
