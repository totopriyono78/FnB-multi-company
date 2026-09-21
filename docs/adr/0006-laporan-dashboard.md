# ADR 0006 — Laporan & dashboard

- Status: Diterima (16 Sep 2026)
- Kebutuhan: FR-RPT-01..08, FR-RPT-10; NFR-PERF-07/08; NFR-SCL-05
- Keputusan user:
  1. Refund mengurangi pajak **di periode saat refund** (laporan periode yang sudah lewat tidak berubah).
  2. Laba kotor = penjualan bersih − **HPP bahan terjual**; waste dan selisih stok ditampilkan di baris terpisah
     beserta laba setelah keduanya.
  3. Semua laporan dapat diekspor **Excel & PDF** dan **dijadwalkan** harian/mingguan/bulanan ke email.
  4. PPN pembelian ditunda ke modul Akuntansi (harga beli dicatat apa adanya).

## Konteks
Data penjualan (Tahap 3) dan persediaan (Tahap 4) sudah lengkap dan append-only. Laporan harus konsisten antar
halaman (angka "penjualan bersih" yang sama di dashboard, laporan penjualan, laba kotor, dan food cost), menghormati
cakupan outlet user, dan tetap cepat untuk 50 outlet × 1 bulan.

## Keputusan

### Modul `Reporting` (baca saja)
- Modul baru `app/Modules/Reporting` hanya **membaca** tabel modul lain lewat query builder dan tidak pernah menulis
  ke tabel tersebut. Ini pengecualian terukur dari aturan "antar modul lewat event": laporan adalah *read model*.
  Semua query berjalan di dalam konteks tenant sehingga RLS tetap berlaku.
- Semua query laporan terkumpul di modul ini agar kelak dapat dialihkan ke read replica (NFR-SCL-05); koneksi replika
  wajib menerima konteks tenant yang sama (RLS) sebelum dipakai.
- Tanpa tabel agregat untuk saat ini: query agregat langsung pada tabel berpartisi `business_date` sudah memenuhi
  NFR-PERF-08 pada uji volume (lihat laporan uji). Tabel agregat ditambahkan bila volume nyata menuntut.

### Definisi angka (berlaku di semua laporan)
Periode selalu berdasarkan **hari bisnis** (`business_date`) outlet.

| Istilah | Rumus |
|---|---|
| Penjualan kotor | Penjualan bersih sebelum refund + diskon (untuk harga tanpa pajak sama dengan Σ subtotal) |
| Diskon | Σ diskon item + diskon transaksi |
| Refund | Porsi penjualan dari refund yang **terjadi** di periode (nominal refund × penjualan transaksi ÷ total transaksi) |
| **Penjualan bersih** | Σ (total − pajak − service charge − pembulatan) transaksi lunas − refund |
| Service charge, pajak | Σ dari transaksi lunas − porsi refund di periode refund |
| Total diterima | Σ total transaksi lunas − Σ nominal refund |

- Transaksi yang di-void tidak dihitung sebagai penjualan; dilaporkan terpisah di laporan anti-fraud.
- Penjualan per item/kategori: penjualan bersih transaksi dibagi ke baris secara proporsional terhadap nilai bersih
  baris (sehingga jumlah per item = total transaksi, termasuk untuk harga termasuk pajak). Refund baris memakai
  nominal baris yang tercatat; refund tanpa rincian baris dibagi proporsional.
- Jam penjualan memakai waktu selesai transaksi di zona waktu outlet.
- Pajak: DPP dan pajak diambil dari rincian yang tersimpan saat transaksi (`totals.tax_base`, `tax`,
  `pricing.tax_rate`); porsi refund dihitung proporsional dan mengurangi periode refund.

### Laba kotor
- HPP terjual = nilai mutasi `sale` − `sale_return` (harga pokok saat stok dipotong). Void/refund yang bahannya
  dibuang tidak mengembalikan stok sehingga biayanya tetap di HPP terjual.
- Baris terpisah: waste (dokumen waste + pesanan batal yang sudah diolah), selisih opname & penyesuaian (tanpa saldo awal).
- Laba kotor = penjualan bersih − HPP terjual; laba setelah waste & selisih = laba kotor − waste − selisih.

### Menu engineering (FR-RPT-03)
- Per item (varian digabung ke item): jumlah terjual bersih, penjualan bersih, HPP (dari pemakaian tercatat; bila
  pemakaian tercatat bernilai nol — menu belum punya resep — memakai HPP resep teoritis), margin per porsi.
- Popularitas tinggi bila porsi jumlah ≥ 70% × (1 ÷ jumlah item) — metode Kasavana & Smith. Margin tinggi bila margin
  per porsi ≥ rata-rata tertimbang. Star (tinggi/tinggi), Plowhorse (populer, margin rendah), Puzzle (kurang populer,
  margin tinggi), Dog (rendah/rendah). Item tanpa HPP diberi kategori "Belum ada HPP".

### Hak akses
- Laporan penjualan, pajak, anti-fraud, menu engineering, laba kotor, dan dashboard: `report.sales.company|brand|outlet`
  dengan cakupan outlet user (`SalesAccess`). Laporan inventory: `inventory.view` (`InventoryAccess`).
- Filter outlet/brand di luar cakupan → 404 di API, tidak tampil di back-office.

### Ekspor & jadwal
- Setiap laporan dibangun sebagai `ReportTable` (judul, filter, kolom bertipe, baris, total) yang dipakai halaman,
  API, Excel (OpenSpout) dan PDF (Dompdf) — angka di tiga bentuk selalu sama.
- Jadwal (`report_schedules`) dimiliki pembuatnya: laporan dibangun **dengan hak akses pembuat saat dikirim**; bila
  pembuat tidak aktif atau kehilangan akses, jadwal dinonaktifkan dan pengiriman dicatat gagal.
- Frekuensi harian (periode kemarin), mingguan (Senin–Minggu lalu), bulanan (bulan lalu) pada jam lokal company.
- Penerima maks. 10 alamat (anggota company atau email luar); setiap perubahan jadwal masuk audit log. Riwayat
  pengiriman (`report_deliveries`) append-only.
- Perintah `reports:send-scheduled` dijalankan scheduler tiap 5 menit dan idempoten per jadwal-periode.

## Konsekuensi
- Angka lama di "Tutup Hari" (total termasuk pajak) diberi label "Total diterima" agar tidak rancu dengan
  "Penjualan bersih".
- Pengiriman email membutuhkan konfigurasi SMTP di produksi; lokal memakai mailer `log`.
- Notifikasi push Owner App (FR-RPT-09) dikerjakan bersama Owner App.
