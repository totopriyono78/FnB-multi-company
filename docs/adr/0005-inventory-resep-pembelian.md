# ADR 0005 — Inventory, resep, dan pembelian

- Status: Diterima (16 Sep 2026)
- Kebutuhan: FR-INV-01..11, FR-PUR (purchase order & penerimaan), FR-RPT-06 (sebagian), SRS §9.4, §12.1, §12.2
- Keputusan user:
  1. Pemicu potong stok bawaan **saat pesanan dikirim ke dapur** (tetap bisa diatur per outlet).
  2. Penilaian persediaan **Moving Average**; FIFO menyusul.
  3. **Purchase order ikut Tahap 4** (pemasok, PO, persetujuan, penerimaan).
  4. Stok **boleh minus dengan peringatan** sebagai bawaan (bisa diubah per outlet).

## Konteks
Penjualan terjadi di POS yang bisa offline, sedangkan stok dihitung di server. Stok harus berkurang otomatis sesuai
resep tanpa pernah menghambat penjualan, dan setiap perubahan saldo harus bisa ditelusuri di kartu stok.

## Keputusan

### Satuan & ketelitian
- Setiap bahan punya **satuan dasar** (g, ml, pcs, lembar, porsi). Resep, saldo, dan kartu stok selalu memakai satuan
  dasar dengan `NUMERIC(18,4)`. Satuan beli (karton, kg, jeriken) disimpan sebagai faktor konversi ke satuan dasar;
  dokumen PO/penerimaan menyalin nama & faktor satuan saat dibuat sehingga perubahan satuan tidak mengubah riwayat.
- Harga pokok per satuan dasar memakai `NUMERIC(18,6)` (Rp per gram/ml tidak cukup 2 desimal). Nilai rupiah setiap
  mutasi dan dokumen tetap `NUMERIC(18,2)` dibulatkan HALF_UP. Ini pengecualian terukur dari aturan uang
  `NUMERIC(18,2)` di CLAUDE.md: yang bertipe 6 desimal hanya harga pokok per satuan, bukan nominal uang.

### Buku besar stok (`StockLedger`)
- Satu-satunya jalur perubahan saldo. Setiap perubahan menulis baris `stock_movements` (append-only, dijaga trigger &
  REVOKE) berisi qty bertanda, harga pokok, nilai, saldo sesudah, dan rujukan dokumen.
- Saldo per (lokasi, bahan) dikunci `FOR UPDATE` dengan urutan tetap untuk mencegah deadlock/penimpaan.
- **Moving average**: HPP berubah hanya saat barang masuk berharga (penerimaan, transfer masuk, pengembalian,
  penyesuaian plus). Bila saldo sebelumnya ≤ 0, HPP = harga barang masuk. Barang keluar dinilai HPP rata-rata saat itu;
  bila lokasi belum pernah menerima bahan tersebut dipakai harga beli terakhir.
- Posting otomatis memakai `source_key` unik sehingga idempoten.
- Stok minus: penjualan **selalu** diposting (penjualan sudah terjadi) dan ditandai `negative_stock`. Dokumen
  back-office (waste, penyesuaian minus, transfer) ditolak `STOCK_INSUFFICIENT` bila outlet melarang stok minus.
- Batas minimum: saldo yang turun melewati batas memicu event `StockBelowMinimum` (untuk notifikasi push Owner App) dan
  tampil di dashboard "Stok kritis".

### Resep
- Resep dapat dimiliki menu, varian, modifier, atau bahan setengah jadi. Aturan penjabaran:
  resep varian menggantikan resep menu; modifier menambah (boleh minus, mis. "Tanpa Gula" atau "Oat Milk" yang
  mengganti susu); paket = resep paket + resep setiap pilihan; total per bahan ≤ 0 diabaikan.
- Bahan setengah jadi yang punya sub-resep **dijabarkan** ke bahan penyusun saat penjualan (qty ÷ hasil produksi,
  maks. 5 tingkat, siklus ditolak saat disimpan) dan tidak disimpan sebagai stok. Bahan setengah jadi tanpa resep
  diperlakukan sebagai barang stok (dibeli jadi). Produksi batch (central kitchen) menyusul di fase berikutnya.
- Perubahan resep tidak mengubah transaksi yang sudah diposting: pemakaian per baris disimpan di
  `stock_line_postings.consumption` beserta HPP saat dipotong.

### Potong stok dari penjualan
- Modul Sales mengirim event setelah commit (`OrderCompleted`, `OrderVoided`, `OrderRefunded`, dan baru
  `KitchenTicketSent`). Inventory membaca data penjualan lewat `SalesStockFeed` (bentuk array), tidak lewat model Sales.
- Entitas sinkron baru `kitchen.send` (juga `POST /pos/kitchen-tickets`) mencatat pesanan yang dikirim ke dapur
  (`kitchen_tickets`, append-only). **Kontrak: ID baris tiket = ID baris pesanan saat dibayar.**
- Pemicu `on_kitchen` (bawaan): stok dipotong saat tiket diterima; baris yang tidak pernah dikirim ke dapur dipotong saat
  pesanan lunas (fallback, agar tidak ada penjualan yang lolos). Pesanan yang dibatalkan sebelum bayar tetapi sudah
  dikirim ke dapur dicatat sebagai **waste**.
- Pemicu `on_payment`: dipotong saat lunas; pembatalan sebelum bayar tidak memotong stok. Tiket yang sudah memotong
  stok (mis. setelah pengaturan outlet diubah) tidak dipotong ulang karena dicek per ID baris.
- Void setelah bayar: `stock_action` baru pada `order.void` — `return` (bawaan, bahan kembali dengan HPP saat dipotong)
  atau `waste` (bahan sudah diolah). Refund mengikuti `stock_action` refund (sudah ada sejak Tahap 3); refund penuh
  mengembalikan sisa yang belum di-refund per baris.
- Lokasi yang dipotong: lokasi yang dipetakan ke stasiun dapur menu (mis. Bar), selain itu lokasi utama outlet.
- **Keandalan**: listener berjalan setelah transaksi tersimpan tanpa bergantung pada queue worker; pada permintaan HTTP
  posting ditunda sampai respons terkirim (`defer`, sesuai SRS §7.3) agar sinkronisasi POS tetap cepat, di konsol
  langsung dijalankan. Setiap
  event dicatat di `stock_event_postings` (posted/failed + percobaan). Kegagalan tidak membatalkan penjualan; perintah
  terjadwal `inventory:post-sales` (tiap 5 menit) mengulang yang gagal dan memproses transaksi 48 jam terakhir yang
  belum tercatat. Urutan event yang tertukar ditangani: void/refund memastikan posting penjualan lebih dulu.

### Dokumen stok
- **Penyesuaian & waste** append-only, bernomor `ADJ-/WST-{OUTLET}-{YYMM}-{URUT}` (tabel `document_sequences`,
  UPSERT atomik; nomor dari transaksi yang gagal tidak terpakai). Alasan baku; "Saldo awal" ditandai `opening` dan
  tidak dihitung sebagai pemakaian di food cost. Pencatatan mundur maks. 7 hari.
- **Transfer** kirim–terima: stok asal berkurang saat dikirim (dalam perjalanan), stok tujuan bertambah saat diterima
  dengan HPP asal. Jumlah diterima boleh kurang (selisih tercatat di dokumen & audit), tidak boleh lebih. Pembatalan
  mengembalikan stok asal. Penerima harus berwenang atas outlet tujuan.
- **Stock opname**: saldo sistem dibekukan saat mulai; hitung buta (saldo & selisih tidak ditampilkan selama tahap
  hitung, baik di API maupun back-office); satu opname aktif per lokasi; semua bahan wajib dihitung sebelum diajukan;
  manajer (`inventory.approve_count`) menyetujui → selisih (fisik − beku) diposting sebagai mutasi `count` dengan HPP
  saat mulai, atau meminta hitung ulang. Persetujuan oleh penghitung sendiri diizinkan namun ditandai `self_approved`
  di audit log.

### Pembelian
- Status PO: draf → diajukan → disetujui/ditolak → diterima sebagian/lengkap → (ditutup); draf/diajukan/disetujui dapat
  dibatalkan. Status final dijaga trigger database.
- Hak akses (SRS §12.1): `purchasing.request` (manajer outlet, hanya PO buatannya), `purchasing.manage` (gudang/admin),
  izin baru `purchasing.approve` (pemilik & admin company). Persetujuan oleh pembuat ditandai `self_approved`.
- Penerimaan dari PO tidak boleh melebihi sisa pesanan; harga faktur boleh berbeda (ditandai `price_changed` di audit).
  Penerimaan tanpa PO untuk belanja harian. Penerimaan append-only; koreksi lewat penyesuaian.
- **Pajak pembelian (PPN) belum dihitung otomatis** — harga dicatat per satuan sesuai input. Aturan PPN masukan
  menunggu keputusan user (CLAUDE.md §9).

### Food cost
- Teoritis per menu: resep × HPP rata-rata lokasi utama outlet (atau harga beli terakhir) ÷ harga dine-in sebelum pajak
  (harga termasuk pajak dikonversi dengan tarif outlet).
- Aktual per periode: pemakaian penjualan + waste + selisih opname/penyesuaian (tanpa saldo awal) ÷ penjualan bersih
  (total − pajak − service charge − pembulatan, dikurangi porsi refund).

### Hak akses & isolasi
- Semua tabel baru memiliki `company_id`, global scope, dan RLS. Data master bahan & sub-resep hanya diubah pengguna
  `inventory.manage` tingkat company; resep menu juga boleh diubah pengelola menu brand terkait. Dokumen stok mengikuti
  cakupan outlet user; dokumen di luar cakupan diperlakukan tidak ada (404).
- Izin baru `inventory.approve_count` (pemilik, admin company, manajer outlet) dan `purchasing.approve`. Migrasi
  menambahkan izin ini ke role bawaan yang sudah ada tanpa mengubah izin lain, dan membuat "Gudang Utama" untuk setiap
  outlet yang sudah ada.

## Konsekuensi
- POS (Tahap 6) wajib mengirim `kitchen.send` dengan ID baris yang sama dengan pesanan; tanpa tiket stok tetap terpotong
  saat bayar sehingga data tidak hilang, hanya lebih lambat.
- `stock_movements` belum dipartisi; volume diperkirakan ±50 baris per transaksi. Partisi bulanan menurut
  `business_date` dijadwalkan bila tabel melewati ±50 juta baris.
- Penolakan penjualan karena stok habis (bila outlet melarang stok minus) dilakukan di POS berdasarkan data stok yang
  ditarik (endpoint stok untuk POS menyusul di Tahap 6); server tidak menolak penjualan yang sudah terjadi.
