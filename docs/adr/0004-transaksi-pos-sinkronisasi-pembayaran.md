# ADR 0004 — Transaksi POS, sinkronisasi offline, dan pembayaran

- Status: Diterima (16 Sep 2026)
- Kebutuhan: FR-POS-01..05, 10..24, 30, 31, 33; FR-PAY-01..08, 10, 11; FR-DEV-03..06; NFR-OFF-01..05; BR-10..16, BR-20
- Keputusan user: QRIS/e-wallet **melalui payment gateway** (mitra belum dipilih); nomor struk memakai format umum;
  pembulatan Rp100 **juga berlaku untuk pembayaran non-tunai**.

## Konteks
POS bersifat offline-first. Transaksi dibuat di perangkat (UUID v7 dari perangkat), disimpan lokal, lalu dikirim ke
server lewat antrian outbox. Server harus menerima kiriman ulang tanpa duplikasi dan tidak pernah kehilangan penjualan
yang sudah terjadi di outlet.

## Keputusan

### Alur data
- **Push** `POST /sync/push`: batch ≤ 100 entitas berurutan (`shift.open`, `cash_movement`, `order`, `order.void`,
  `order.refund`, `shift.close`). Setiap entitas diproses dalam transaksi DB sendiri; hasil per entitas:
  `accepted`, `duplicate` (ID sama & isi sama), atau `rejected` (kode + pesan, `retryable` bila bergantung pada
  entitas yang belum diterima). ID sama dengan isi berbeda → `rejected: CONFLICT`.
  Bukti penerimaan (`sync_receipts`) disimpan tanpa kedaluwarsa (≥ 30 hari sesuai SRS §7.3).
- **Pull** `GET /sync/pull?since=<versi>`: server menyimpan nomor versi per company yang naik setiap ada perubahan
  master (menu, harga, promo, ketersediaan, outlet, staf, metode bayar). Bila versi perangkat tertinggal, server
  mengirim **snapshot penuh** outlet (server wins). Snapshot penuh dipilih karena sederhana dan tetap < 300 ms untuk
  ±300 menu; sinkronisasi per baris dapat ditambahkan tanpa mengubah kontrak (`mode: full|incremental`).
- Endpoint online (`POST /shifts`, `POST /orders`, `POST /orders/{id}/void`, dst.) memakai layanan yang sama dengan push.

### Transaksi append-only
- `orders`, `order_items`, `payments` dipartisi bulanan menurut `business_date`. Baris order tidak dapat diubah
  kecuali kolom status (`status`, `refunded_total`, data void) — dijaga trigger database. Koreksi hanya lewat void
  dan refund (BR-12). `refunds`, `cash_movements`, `order_discounts` append-only.
- Server hanya menerima order yang **sudah selesai** (dibayar) atau **dibatalkan sebelum bayar** (untuk laporan
  anti-fraud). Order tertahan (hold) dan meja terbuka tetap di perangkat sampai Fase 2 (TBL).
- Order menyimpan salinan nama, harga, modifier, pajak, dan konfigurasi harga saat transaksi (SRS §6.3 butir 7).

### Validasi server atas order
1. Shift terbuka milik perangkat & outlet yang sama; waktu order di dalam rentang shift (BR-10).
2. Kasir anggota aktif dengan `pos.transact` dan cakupan outlet.
3. Total dihitung ulang dengan `PricingCalculator` dari baris yang dikirim + konfigurasi snapshot; beda sesen pun ditolak
   (BR-05). Harga yang berbeda dari katalog server saat ini **tidak** ditolak (harga bisa berubah setelah perangkat
   offline) tetapi ditandai `flags.price_mismatch`; ubah harga manual wajib otorisasi `price_override`.
4. Diskon manual wajib izin `pos.discount` dan ≤ batas role kasir (BR-14); di atas itu wajib otorisasi supervisor.
5. Promo dihitung ulang dengan `PromotionEngine`; kode promo diperiksa terhadap kode asli; kuota dikurangi. Bila kuota
   ternyata habis karena transaksi offline lain, penjualan tetap diterima dan ditandai `flags.promo_quota_exceeded`.
6. Pembayaran: jumlah non-tunai ≤ sisa tagihan; total bayar ≥ total; kembalian hanya dari tunai. QRIS/e-wallet wajib
   merujuk `payment_intent` berstatus `paid`, nominal sama, dan belum dipakai order lain (mencegah bayar ganda).
7. Nomor struk `{KODE_OUTLET}-{KODE_PERANGKAT}-{YYMMDD}-{URUT 4 digit}` dibuat perangkat; unik per company & hari bisnis.

### Otorisasi supervisor
- Online: perangkat menyertakan `authorization_id` dari `POST /pos/authorize`; server memeriksa catatan audit tersebut
  (perangkat, aksi, pemberi otorisasi, maksimal 10 menit sebelum aksi).
- Offline (NFR-OFF-02): perangkat mengirim `mode: offline` + ID supervisor. Server memeriksa supervisor punya izin dan
  cakupan outlet, lalu menandai `flags.offline_authorization` untuk ditinjau. Verifikasi PIN lokal dirancang di Tahap 6.

### Hari bisnis & tutup hari
- `business_date` = tanggal lokal outlet dikurangi 1 hari bila jam < `business_day_cutoff` (BR-20).
- Tutup hari (FR-POS-05) hanya bila semua shift hari itu sudah ditutup; menyimpan ringkasan, mengembalikan status
  "habis" semua menu outlet (sold-out reset), dan mengunci hari tersebut. Void/refund untuk hari yang sudah ditutup
  hanya oleh pemegang `pos.end_of_day` (manajer ke atas) dan dicatat di hari berjalan (BR-13).

### Pembayaran & payment gateway
- `PaymentGateway` (antarmuka) dengan driver **sandbox** untuk pengembangan dan uji. Mitra produksi (SRS §12.3 no. 1)
  cukup menambah driver baru: `createCharge`, `status`, `cancel`, `verifyWebhook`, `parseWebhook`.
- QRIS/e-wallet: `POST /payments/qris` membuat intent (kedaluwarsa 15 menit) → status diperbarui lewat webhook
  `POST /webhooks/payment/{provider}` (tanda tangan wajib valid, event dicatat & diproses idempoten) dan polling
  cadangan `GET /payments/{id}/status`. Pembatalan hanya untuk intent yang belum dibayar; pembayaran yang datang setelah
  dibatalkan/kedaluwarsa ditandai `paid_late` untuk refund manual.
- Pembulatan Rp100 berlaku untuk semua metode (keputusan user); nominal intent = total setelah pembulatan (atau porsi
  split).
- Metode yang aktif, urutan, dan MDR diatur per outlet (FR-PAY-02, FR-PAY-10). `member_balance` dan `city_ledger`
  belum tersedia (Fase 2).

## Konsekuensi
- Perangkat terdaftar dapat mengirim transaksi atas nama kasir mana pun di outletnya (wajar untuk POS offline); semua
  aksi sensitif tercatat dengan perangkat & pemberi otorisasi.
- Snapshot penuh menambah trafik saat master sering berubah; dipantau di Tahap 5/6.

## Pembaruan 16 Sep 2026 — hasil tinjauan keamanan independen
Tinjauan menemukan bahwa aturan di atas dapat disalahgunakan oleh pemegang token perangkat. Keputusan tambahan:

1. **Pelaku terverifikasi.** Kewenangan pelaku sendiri (`pos.void`, `pos.discount`, `pos.price_override`,
   `pos.open_drawer`, `pos.end_of_day`) hanya diakui bila pelaku itulah yang login PIN pada permintaan (token pos).
   Kiriman dengan token perangkat atau oleh kasir lain wajib membawa otorisasi supervisor — termasuk manajer yang
   bertransaksi offline (perangkat menyertakan otorisasi offline atas nama manajer tersebut).
2. **Otorisasi online terikat.** `POST /pos/authorize` wajib menyebut `reference_id` (ID transaksi) untuk void, refund,
   diskon, dan ubah harga; `amount`/`discount_percent` yang dicatat ikut dicocokkan. Satu otorisasi hanya untuk satu
   entitas (termasuk buka laci), paling jauh 10 menit dari waktu aksi dan 24 jam dari jam server.
3. **Acuan perubahan data master = tarik data terakhir perangkat** (`devices.master_pulled_at`, jam server), bukan
   waktu transaksi dari perangkat (dapat dimundurkan). Aturan:
   - harga di bawah katalog tanpa perubahan harga setelah tarik data → diperlakukan sebagai ubah harga (wajib otorisasi);
   - pengaturan pajak/service/pembulatan berbeda tanpa perubahan outlet/channel setelah tarik data → ditolak
     `CONFIG_MISMATCH`;
   - klaim promo yang tidak dihasilkan mesin promo dan promonya tidak berubah setelah tarik data → dihitung sebagai
     diskon manual untuk batas role (BR-14);
   - perangkat yang belum pernah menarik data dan mengirim data berbeda → `SYNC_REQUIRED` (boleh kirim ulang).
   Konsekuensi untuk aplikasi POS: **kirim antrian (`/sync/push`) sebelum menarik data (`/sync/pull`)**.
4. **Refund** harus lewat metode pembayaran asal; refund tunai untuk pembayaran non-tunai hanya dengan persetujuan
   pemegang `pos.end_of_day` (ditandai `refund_method_changed`).
5. **Gateway sandbox** ditolak di luar lingkungan local/testing/staging; simulator bayar hanya local/testing.
   Tagihan QRIS hanya dapat dipakai transaksi yang ID-nya sama dengan `order_ref` tagihan.
6. **Beban**: maksimal 1.000 baris pesanan per batch; audit penolakan sinkronisasi dibatasi 200 per perangkat per jam.

Risiko yang diterima sampai Tahap 6: otorisasi mode offline belum membawa bukti PIN bertanda tangan, sehingga hanya
diperiksa kewenangannya dan ditandai `offline_authorization` untuk ditinjau; kas keluar tidak memerlukan otorisasi.

