# Laporan Putaran: Tahap 4 — Inventory, resep, dan pembelian

- Tanggal: 2026-09-16
- Kebutuhan SRS: FR-INV-01..11; pembelian (pemasok, PO, persetujuan, penerimaan barang); FR-RPT-06 (food cost,
  sebagian); SRS §7.3 (potong stok setelah sinkronisasi), §9.4, §12.1 (matriks hak akses gudang/purchasing), §12.2
- Keputusan user yang dipakai: pemicu potong stok bawaan **saat pesanan dikirim ke dapur** (tetap bisa diatur per
  outlet); penilaian **Moving Average** (FIFO menyusul); **PO ikut Tahap 4**; stok **boleh minus dengan peringatan**.
- Desain: `docs/adr/0005-inventory-resep-pembelian.md`
- Status: **LOLOS** (dengan catatan pemeriksaan yang tidak dapat dijalankan di bawah)
- Jumlah siklus uji: 4 (uji modul → uji panel & E2E → tinjauan desain dari tangkapan layar → uji penuh & kinerja)

## Perubahan
- `database/migrations/2026_09_18_000100_create_inventory_tables.php` — 22 tabel baru, semua dengan RLS:
  `document_sequences`, `ingredients`, `ingredient_units`, `stock_locations`, `recipes`, `recipe_lines`,
  `stock_balances`, `stock_movements`, `stock_line_postings`, `stock_event_postings`, `kitchen_tickets`,
  `stock_adjustments(+lines)`, `stock_transfers(+lines)`, `stock_counts(+lines)`, `suppliers`,
  `purchase_orders(+lines)`, `goods_receipts(+lines)`. Trigger append-only (mutasi, tiket dapur, penerimaan,
  penyesuaian) dan penjaga status final (transfer, opname, PO). Bawaan `outlets.stock_deduction_trigger` →
  `on_kitchen`; kolom baru `orders.void_stock_action`. Data lama: "Gudang Utama" dibuat untuk setiap outlet, izin baru
  ditambahkan ke role bawaan.
- `app/Modules/Inventory` (baru)
  - `StockLedger` — satu-satunya jalur perubahan saldo: kunci saldo `FOR UPDATE`, moving average (HPP 6 desimal,
    nilai 2 desimal), `source_key` idempoten, tanda `negative_stock`/`opening`, penolakan stok minus untuk dokumen
    back-office bila outlet melarang, event `StockBelowMinimum`.
  - `RecipeExplorer` (varian menggantikan, modifier menambah/mengurangi, paket + pilihan, sub-resep bahan setengah
    jadi maks. 5 tingkat), `RecipeService` (cek siklus, HPP teoritis), `UnitConverter`.
  - `SalesStockPoster` + listener `PostSalesStock` + perintah terjadwal `inventory:post-sales` (tiap 5 menit):
    potong stok penjualan, void (`return`/`waste`), refund, tiket dapur, waste pesanan batal yang sudah dimasak.
  - `StockDocumentService` (penyesuaian/waste, transfer kirim–terima–batal), `StockCountService` (opname hitung buta,
    ajukan, setujui, hitung ulang, batal), `StockLocations`, `FoodCostReport` (teoritis per menu & aktual periode).
  - API: `ingredients`, `stock-locations`, `recipes/{jenis}/{id}`, `stock/balances`, `stock/movements`,
    `stock-adjustments`, `stock-transfers…`, `stock-counts…`, `reports/food-cost[/menu]`.
- `app/Modules/Purchasing` (baru) — `suppliers`, `purchase-orders…` (ajukan/setujui/tolak/batal/tutup),
  `goods-receipts` (dari PO & tanpa PO). Izin baru `purchasing.approve`, `inventory.approve_count`.
- `app/Modules/Sales` — `KitchenTicketRecorder` + entitas sinkron `kitchen.send` / `POST pos/kitchen-tickets`,
  `SalesStockFeed` (data penjualan untuk Inventory tanpa akses model Sales), `stock_action` pada void.
- `app/Shared/Support/DocumentNumber.php` — nomor `PO/GR/TRF/ADJ/WST/OPN-{OUTLET}-{YYMM}-{URUT}`.
- `app/Filament` — grup **Inventory** (Bahan Baku, Resep, Posisi Stok, Kartu Stok, Penyesuaian & Waste, Transfer Stok,
  Stock Opname, Food Cost, Lokasi Stok) dan **Pembelian** (Purchase Order, Penerimaan Barang, Pemasok); statistik
  "Stok kritis", "Opname menunggu persetujuan", "PO menunggu persetujuan" di Ringkasan; hasil opname sebagai tabel.
- `config/fnb.php` — `inventory.defer_in_console`. `routes/console.php` — jadwal `inventory:post-sales` dan
  `fnb:partitions`.
- `database/seeders/DemoInventorySeeder.php` — 23 bahan, resep 10 menu + modifier, 4 pemasok, saldo awal, PO berbagai
  status, belanja pasar, waste, transfer dalam perjalanan, opname menunggu persetujuan; akun demo gudang
  **Rudi Hartanto** (`rudi@kopinusantara.test`).
- `docs/api/openapi.yaml` 1.3.0-tahap4, ADR 0005, README, `scripts/perf-inventory.php`.
- Test: `tests/Feature/Inventory/*` (4 berkas), `tests/Feature/Backoffice/InventoryPanelTest.php`,
  `tests/Browser/inventory.spec.js`, helper `tests/Support/Stock.php`.

## Hasil Quality Gate (siklus terakhir)
| # | Pemeriksaan | Hasil | Catatan |
|---|---|---|---|
| G1 | Format | ✅ Lolos | `pint --test` passed; OpenAPI lolos `openapi-spec-validator` |
| G2 | Analisis statis | ✅ Lolos | Larastan level 6: `[OK] No errors` |
| G3 | Unit test | ✅ Lolos | Penjabaran resep (varian/modifier/paket/sub-resep), moving average, konversi satuan diuji lewat skenario |
| G4 | Feature/API test | ✅ Lolos | Semua endpoint baru: status, format galat (`STOCK_INSUFFICIENT`, `RECIPE_CYCLE`, `COUNT_INCOMPLETE`, dll.), validasi |
| G5 | Isolasi tenant | ✅ Lolos | Bahan, lokasi, resep, dokumen stok, opname, pemasok, PO, penerimaan company/outlet lain → 404; saldo & mutasi terisolasi RLS; RLS aktif di 22 tabel baru |
| G6 | Hak akses | ✅ Lolos | Pemilik, admin company, manajer outlet (hanya outletnya, PO buatannya), gudang, kasir (ditolak 403, menu tidak tampil), persetujuan PO hanya `purchasing.approve`, persetujuan opname hanya `inventory.approve_count` |
| G7 | Kalkulasi | ⚠️ Sebagian | Moving average, nilai mutasi, HPP resep, food cost aktual (penjualan bersih dikurangi porsi refund), pengembalian refund proporsional dihitung manual di test. **Sisi Dart TIDAK DIJALANKAN** (POS Tahap 6) |
| G8 | Offline & sinkronisasi | ✅ Lolos | Tiket dapur & pesanan dikirim ulang → stok tidak terpotong dua kali; urutan event tertukar (void sebelum posting) tertangani; posting gagal tidak membatalkan penjualan dan diulang perintah terjadwal; posting setelah respons diuji |
| G9 | Database | ✅ Lolos | `migrate:fresh --seed` (exit 0); `migrate:rollback --step=1` + `migrate` pada basis data yang sudah berisi data demo (exit 0); `relrowsecurity = t` di 22 tabel; trigger append-only & status final diuji |
| G10 | E2E / UI | ✅ Lolos | Playwright 12 skenario (9 lama + 3 baru: pemilik memantau stok, menyetujui PO & opname, melihat food cost; gudang mencatat waste & menerima transfer; kasir tanpa akses) — 12 lulus |
| G11 | Aksesibilitas | ✅ Lolos | axe WCAG 2.1 AA tanpa pelanggaran serius di 10 halaman baru; tabel memakai `<th scope>` + `aria-label`; selisih minus ditandai tanda "−" (bukan hanya warna) |
| G12 | Desain natural | ✅ Lolos | Hanya token (`fnb-receipt`, kelas baru `fnb-negative` dan `fnb-table-scroll` memakai variabel warna tema); tanpa emoji/gradasi; label Bahasa Indonesia. Tangkapan layar diperiksa (posisi stok, kartu stok, resep, food cost, PO, opname, waste, transfer) |
| G13 | Kinerja | ✅ Lolos (catatan) | Lazy loading dilarang di test. p95 (server PHP bawaan, data seed): bahan 88 ms; posisi stok 98 ms; kartu stok 100 ms; resep + HPP 117 ms; food cost per menu 148 ms; food cost aktual 114 ms; rincian PO 95 ms; rincian opname 85 ms; catat waste 104 ms. Sinkronisasi: push 1 transaksi 202 ms (lihat temuan Medium) |
| G14 | Keamanan | ✅ Lolos | `composer audit`: tidak ada advisori; `npm audit --omit=dev`: 0. Semua input divalidasi FormRequest; `fillable` eksplisit; dokumen di luar cakupan outlet → 404 (IDOR); hitung buta tidak membocorkan saldo sistem lewat API maupun panel |
| G15 | Kebutuhan | ✅ Lolos | Lihat rincian di bawah |
| G16 | Regresi | ✅ Lolos | Seluruh suite |

Ringkasan test: **542 lulus, 0 gagal, 0 dilewati** (77 test baru) + E2E **12 lulus** (2,8 menit).

Pemenuhan kebutuhan (G15):
- FR-INV-01/02 bahan baku dengan satuan dasar & satuan beli, bahan setengah jadi; FR-INV-03 resep per menu, varian,
  modifier, paket dengan HPP; FR-INV-04 potong stok otomatis sesuai pemicu outlet; FR-INV-05 multi lokasi (gudang
  utama, bar/dapur per stasiun); FR-INV-06 transfer kirim–terima; FR-INV-07 stock opname hitung buta dengan
  persetujuan; FR-INV-08 waste beralasan; FR-INV-09 batas minimum & peringatan; FR-INV-10 kartu stok; FR-INV-11
  moving average. Pembelian: pemasok, PO dengan persetujuan, penerimaan sebagian/lengkap, belanja tanpa PO.
- Milik tahap lain: endpoint stok & penolakan penjualan stok habis di POS (Tahap 6), notifikasi push stok kritis
  (Owner App — event `StockBelowMinimum` sudah tersedia), FIFO, produksi batch central kitchen, laporan inventory
  lengkap (Tahap 5).

## Temuan & Perbaikan
| Siklus | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 1 | Critical | Migrasi gagal pada basis data yang sudah berisi role (izin ditambahkan lewat `givePermissionTo` → *lazy loading violation*) — komputer Anda tidak akan bisa `migrate` | Izin ditambahkan dengan insert langsung ke `role_has_permissions` (tanpa mengubah izin lain); diverifikasi rollback + migrate pada data demo |
| 1 | High | Galat sintaks `RecipeEditor` (ternary bertingkat) menghentikan suite penuh | Diperbaiki dengan helper `InventoryFields::plain()` |
| 1 | Medium | Operator `?` JSONB bentrok dengan placeholder query | Diganti `flags @> '["opening"]'::jsonb` |
| 2 | High | Halaman rincian (PO, transfer, opname, penerimaan) galat 500 saat tombol aksi dipakai: Livewire memuat ulang model tanpa relasi | Relasi dimuat di `booted()` |
| 2 | Medium | Daftar `RepeatableEntry` Filament melanggar struktur daftar (axe) | Perbaikan ARIA di `a11y-labels` |
| 2 | Medium | Pemilih tanggal Filament menolak tanggal sah karena zona waktu | Batas tanggal diperiksa di service |
| 3 | Medium | Food cost menampilkan "Sebagian bahan belum punya harga" untuk bahan yang hanya punya saldo awal berharga | Saldo awal / penyesuaian plus berharga menjadi harga acuan bila bahan belum pernah dibeli |
| 3 | Medium | Hasil opname tampil sebagai kartu bertumpuk (sulit dibandingkan) | Tabel Bahan / Sistem / Fisik / Selisih / Nilai / Catatan; kolom sistem & selisih tetap disembunyikan saat hitung |
| 4 | Medium | Posting stok berjalan di dalam permintaan sinkronisasi: push 1 transaksi naik dari p95 96 ms (Tahap 3) ke 188 ms, 20 transaksi 1,5 dtk | Sesuai SRS §7.3 posting dipindah ke setelah respons terkirim (`defer`, tanpa queue worker); gagal/terhenti tetap diulang `inventory:post-sales`. Server PHP bawaan menahan koneksi sampai callback selesai sehingga angka terukur (202 ms) adalah batas atas; di php-fpm respons dikirim sebelum posting. **Pengukuran dengan php-fpm TIDAK DIJALANKAN** (tidak tersedia di lingkungan build) |
| 4 | Low | `perf-inventory.php` terkena pembatas laju 300/menit | Skrip menjeda sebelum kuota habis |
| 4 | High | Suite penuh sesekali gagal (1 dari 542) di `SyncTest`: helper test membuat kode perangkat acak `POS10–99` yang bisa kembar di outlet yang sama (sudah ada sejak Tahap 1, bukan dari kode aplikasi) | Kode perangkat di helper dibuat berurutan; suite penuh diulang |

Perubahan test karena kebutuhan berubah (bukan untuk meloloskan): test opname sebagian kini mengharapkan
`system_qty` kosong selama tahap hitung karena hitung buta diterapkan juga pada opname (ADR 0005).

### Konfirmasi di komputer user

Windows, PHP 8.5, PostgreSQL berzona waktu Asia/Jakarta: `php artisan test --log-junit storage\logs\junit.xml` (16-09-2026 20.41 WIB) — 542 test, 3.701 assertion, 0 gagal, 0 error, 0 dilewati (817,1 detik).

## Belum Selesai / Risiko
- **PPN pembelian belum dihitung.** Harga PO/penerimaan dicatat apa adanya. Perlu keputusan: apakah harga pemasok
  termasuk PPN, dan apakah PPN masukan menambah HPP atau dicatat terpisah (untuk akuntansi).
- **Outlet yang sudah ada di komputer Anda tetap memakai pemicu `on_payment`** (migrasi hanya mengubah nilai bawaan
  untuk outlet baru). Ubah per outlet bila ingin potong stok saat kirim ke dapur; data demo baru sudah `on_kitchen`.
- **Jalankan scheduler** (`php artisan schedule:work` saat pengembangan, cron `schedule:run` tiap menit di server)
  agar `inventory:post-sales` mengulang posting yang gagal.
- POS (Tahap 6) wajib mengirim `kitchen.send` dengan ID baris sama dengan pesanan; endpoint stok untuk POS menyusul.
- `stock_movements` belum dipartisi (rencana: bulanan bila > ±50 juta baris).
- Filament Choices menampilkan label "Remove item" berisi UUID untuk pembaca layar pada pilihan bahan (bawaan vendor, Low).
- **G7 sisi Dart TIDAK DIJALANKAN** (Tahap 6). Pengujian PHP 8.5 sudah dikonfirmasi di komputer user (lihat di atas).

## Cara Verifikasi Manual
1. `php85`, `php artisan migrate:fresh --seed`, `php artisan serve`, buka `http://127.0.0.1:8000/admin`.
2. Masuk sebagai **Rina Hartono** → Ringkasan: kartu "Stok kritis", "PO menunggu persetujuan", "Opname menunggu
   persetujuan".
3. **Inventory → Posisi Stok** → filter di bawah minimum: Boba Brown Sugar & Matcha di Gudang Kemang.
4. **Kartu Stok**: mutasi "Pemakaian penjualan" dari transaksi demo dan "Transfer keluar" ke Dago.
5. **Resep** → pilih Kopi Susu Tepi Jalan: rincian HPP per porsi. **Food Cost** → Kemang: food cost aktual & per menu.
6. **Pembelian → Purchase Order** → PO PT Boulangerie Nusantara (menunggu persetujuan) → Setujui.
7. **Stock Opname** → opname Kemang: tabel selisih (nilai −Rp14.750,45) → Setujui & Sesuaikan Stok.
8. Keluar, masuk sebagai **Rudi Hartanto** (gudang) → **Penyesuaian & Waste** → catat waste susu 250 ml;
   **Transfer Stok** → terima transfer ke Dago.
