# Rekomendasi Tahapan Pengembangan Lanjutan — FnB Cloud

Tanggal: 21 September 2026
Acuan: `claude/progres-pengembangan.md`, `claude/rencana-fitur-holding.md`, SRS Platform F&B Multi Company

---

## 1. Rekomendasi singkat

| Urutan | Tahap | Isi | Perkiraan |
|---|---|---|---|
| **Sekarang** | **6 — Kesiapan produksi** | Deployment, backup teruji, scheduler & queue, SMTP, monitoring, pemisahan lingkungan | 1–2 minggu |
| Berikutnya | **7 — Aplikasi POS (Flutter)** | Kasir offline-first + pilot 1 outlet | 6–8 minggu |
| Paralel bila deal holding maju | **8 — Akuntansi inti** | COA, jurnal, buku besar, periode, jurnal otomatis penjualan | 4 minggu |
| | **9 — Dokumen & kas-bank** | SPPK, advis bayar/tagih, lampiran, verifikasi, rekonsiliasi bank, hutang | 4 minggu |
| | **10 — Laporan keuangan & pajak** | Neraca, LR, arus kas, papan kelengkapan, rekap PPN | 2 minggu |
| | **11 — Aset & sewa** | Register aset, penyusutan, sewa, hutang aset | 3 minggu |
| | **12 — Holding & konsolidasi** | Lapisan grup, eliminasi, kertas kerja, LK konsolidasi | 3 minggu |
| Menyusul | **13 — Paritas F&B lanjutan** | KDS, integrasi ojol, meja, loyalty | menyesuaikan pasar |

**Inti rekomendasi:** jangan menambah fitur baru dulu. Satu tahap pendek untuk membuat yang sudah ada
**benar-benar bisa dipakai orang lain** (Tahap 6), baru lanjut ke aplikasi POS, lalu akuntansi.

---

## 2. Tiga prinsip yang saya pakai untuk mengurutkan

1. **Tidak ada nilai sebelum bisa dipasang.** Lima tahap sudah selesai dan lulus uji, tetapi belum ada satu
   pun pelanggan yang bisa memakainya karena belum ada cara memasang, mencadangkan, dan memantaunya.
   Ini pekerjaan 1–2 minggu yang membuka nilai 5 tahap sebelumnya.
2. **Selesaikan tulang punggung sebelum cabang.** POS server (Tahap 3) sudah lengkap tetapi menganggur
   tanpa aplikasi kasir. Selama Tahap 7 belum selesai, produk F&B belum bisa dijual ke siapa pun.
3. **Akuntansi mengikuti kepastian, bukan harapan.** Modul akuntansi adalah pekerjaan ±4 bulan.
   Mulai penuh hanya setelah ada sinyal nyata dari calon klien (lihat §4); sebelum itu, kerjakan
   discovery-nya saja yang murah.

---

## 3. Rincian tiap tahap

### Tahap 6 — Kesiapan produksi & operasional · 1–2 minggu

**Tujuan:** sistem yang sudah jadi dapat dipasang, dipantau, dan dipulihkan.

- Deployment: image container + berkas compose/CI, konfigurasi `.env` produksi, HTTPS, region Jakarta.
- **Scheduler & queue worker** dijalankan sungguhan (`inventory:post-sales`, `reports:send-scheduled`, `fnb:partitions`).
- SMTP produksi untuk laporan terjadwal dan notifikasi.
- **Backup harian + uji restore** (wajib dibuktikan, bukan sekadar dijadwalkan).
- Monitoring dasar: kesehatan aplikasi, error log terpusat, notifikasi bila queue macet.
- **Pemisahan lingkungan**: produksi, staging, demo. Saklar prototipe (`FNB_PROTOTYPE_*`) wajib mati di produksi.
- **Hapus/kunci jembatan POS demo** (`PosBridge`) — jembatan ini menyimpan transaksi tanpa token perangkat;
  aman untuk demo, tidak boleh ikut ke produksi.
- Git + CI yang menjalankan 593 uji, Larastan, dan Pint pada setiap perubahan.

**Kriteria lolos:** dipasang di server dari nol dengan satu perintah; restore backup berhasil diuji;
laporan terjadwal benar-benar terkirim; prototipe tidak dapat diakses di produksi.

**Mengapa sekarang:** ini satu-satunya tahap yang mengubah "kode yang lulus uji" menjadi "produk yang bisa dipakai".

---

### Tahap 7 — Aplikasi POS (Flutter) · 6–8 minggu + 4 minggu pilot

**Tujuan:** kasir sungguhan di perangkat, menggantikan prototipe web.

Isi inti (FR-POS/DEV, lihat `claude/rencana-fitur-holding.md` POS-01..15): aktivasi perangkat, login PIN,
katalog offline (SQLite), buka/tutup shift, ambil pesanan + modifier, tahan/gabung bill, diskon berotorisasi,
pembayaran & split, cetak struk ESC/POS, void & refund, **mode offline penuh dengan outbox**, kirim ke dapur,
tutup hari.

**Kriteria lolos:** transaksi lengkap tanpa internet lalu tersinkron utuh; uji lapangan 1 outlet dua minggu
berdampingan dengan sistem lama; selisih laporan nol.

**Catatan:** mulai dengan **pilot satu outlet**, jangan langsung banyak. Dua hal yang sering meleset:
kompatibilitas printer dan perilaku baterai/tidur tablet.

---

### Tahap 8 — Akuntansi inti · 4 minggu

COA master (holding → entitas, akun induk terkunci), dimensi (entitas/brand/outlet/departemen), periode &
tutup buku, jurnal manual dengan maker–checker, buku besar & neraca saldo, impor saldo awal, dan
**jurnal otomatis penjualan dari tutup hari POS**.

**Kriteria lolos:** satu bulan data penjualan nyata terjurnal otomatis dan neraca saldo seimbang;
tutup periode mengunci posting; seluruh jurnal dapat ditelusuri ke dokumen asal.

---

### Tahap 9 — Dokumen, kas & bank · 4 minggu

SPPK, advis bayar, advis tagih; **lampiran foto nota/bukti transfer**; antrian verifikasi pusat;
batas wewenang persetujuan; kas & bank, kas kecil outlet, transfer antar entitas; **rekonsiliasi bank**;
hutang supplier & umur hutang.

**Kriteria lolos:** satu siklus penuh dari pengajuan cabang sampai terjurnal tanpa kertas;
mutasi bank satu bulan terekonsiliasi.

**Nilai terbesar bagi klien ada di tahap ini** — ini yang menggantikan pemeriksaan nota manual.

---

### Tahap 10 — Laporan keuangan & pajak · 2 minggu

Neraca, Laba Rugi (per entitas dan per outlet/brand), arus kas, buku besar cetak, umur hutang/piutang,
rekap PPN keluaran, **papan kelengkapan entry harian**, paket LK terjadwal ke email.

Murah karena mesin laporan (Tahap 5) tinggal dipakai ulang: ekspor Excel/PDF dan penjadwalan sudah ada.

---

### Tahap 11 — Aset & sewa · 3 minggu

Register aset, penyusutan otomatis menjadi jurnal, perolehan & pelepasan, kontrak sewa dan akrualnya,
hutang aset dengan jadwal pokok + bunga.

---

### Tahap 12 — Holding & konsolidasi · 3 minggu

Lapisan **Group** di atas Company, peran konsolidator, penarikan saldo ringkas per entitas,
penandaan transaksi antar-entitas, rekonsiliasi dan **eliminasi**, kertas kerja, LK konsolidasi.

**Keputusan arsitektur yang harus diambil di awal tahap ini:** konsolidasi lewat snapshot saldo
(RLS tetap utuh) — bukan dengan melonggarkan isolasi antar entitas.

---

### Tahap 13 — Paritas F&B lanjutan · menyesuaikan

KDS, **integrasi GoFood/GrabFood/ShopeeFood**, meja & reservasi, member/loyalty, self-order QR.
Dikerjakan ketika ada pelanggan F&B yang membutuhkannya; integrasi ojol adalah yang paling sering
menjadi syarat mati dan paling mahal (±6–8 minggu).

---

## 4. Percabangan setelah demo

| Skenario | Yang dilakukan |
|---|---|
| **Deal holding maju** (ada niat lanjut dalam 2–3 minggu) | Tahap 6 selesaikan dulu, lalu jalankan **dua jalur paralel**: jalur akuntansi (8→9→10→11→12) dan jalur POS (7). Butuh dua orang/peran karena beda teknologi (Laravel vs Flutter). |
| **Deal lambat / menunggu** | Tahap 6 → Tahap 7 sampai pilot. Akuntansi cukup **discovery**: kumpulkan COA klien, contoh SPPK/advis, bentuk LK, struktur badan hukum. Murah, dan membuat Tahap 8 langsung tepat sasaran saat dimulai. |
| **Deal batal** | Tahap 6 → 7 → 13. Jual produk F&B lengkap ke pasar yang lebih luas; akuntansi tetap di peta jalan sebagai pembeda jangka menengah (SRS Fase 3). |

Dalam ketiga skenario, **Tahap 6 tetap dikerjakan lebih dulu** — itu sebabnya ia direkomendasikan sekarang.

---

## 5. Utang teknis yang perlu ditutup sambil jalan

Dikumpulkan dari catatan terbuka Tahap 3–5; kecil-kecil, tetapi menumpuk:

- Outlet lama di basis data masih memakai pemicu stok `on_payment` (seharusnya `kitchen.send`).
- Partisi `stock_movements` belum dibuat; `fnb:partitions` belum dijadwalkan di server.
- Pengukuran php-fpm dan rencana read replica untuk laporan belum dilakukan.
- Valuasi FIFO (baru Moving Average), PPN pembelian (ditunda ke modul Akuntansi).
- Endpoint stok untuk POS (dipakai Tahap 7), otorisasi offline tanpa bukti PIN.
- Notifikasi push Owner App (FR-RPT-09) dan mitra payment gateway QRIS belum ditentukan.
- CSS lama di `public/build/assets` boleh dibersihkan.

Saran: sisihkan **10–15% waktu tiap tahap** untuk daftar ini, jangan menunggu "nanti ada waktu khusus".

---

## 6. Cara kerja yang saya sarankan mulai diterapkan

1. **Git + CI sejak Tahap 6.** 593 uji hanya berguna bila dijalankan otomatis setiap perubahan, bukan manual.
2. **Satu tahap = satu Quality Gate.** Pertahankan kebiasaan yang sudah berjalan: laporan uji per tahap,
   ADR untuk keputusan arsitektur, dan catatan jujur untuk hal yang belum diuji.
3. **Tenant demo terpisah** dari data pelanggan, dengan seeder yang dapat disetel ulang sebelum demo.
4. **Keputusan bisnis ditulis sebagai ADR**, bukan hanya di percakapan — terutama perlakuan pajak,
   konsolidasi, dan kebijakan diskon.
5. **Pilot sebelum rollout**, selalu. Satu outlet dua minggu jauh lebih murah daripada memperbaiki 11 cabang.

---

## 7. Yang tidak saya sarankan sekarang

- **Menambah modul baru sebelum Tahap 6.** Menambah fitur ke sistem yang belum bisa dipasang hanya menambah
  jarak ke pengguna pertama.
- **Membangun integrasi ojol sebelum ada pelanggan yang memintanya.** Mahal, bergantung pada mitra, dan
  cepat usang bila tidak dipakai.
- **Payroll penuh.** Cukup jurnal rekap gaji sampai ada permintaan yang jelas.
- **Membawa layar prototipe ke produksi.** Prototipe akuntansi dan jembatan POS demo adalah alat jualan,
  bukan fitur; keduanya harus mati di lingkungan produksi.
