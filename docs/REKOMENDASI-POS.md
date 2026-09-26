# Rekomendasi Pengembangan POS — FnB Cloud

Tanggal: 22 September 2026
Konteks: POS diputuskan dipakai lebih dulu di tahap awal, mendahului modul akuntansi.
Acuan: kode `resources/views/pos/app.blade.php`, `routes/api.php`, `claude/rekomendasi-tahapan-pengembangan.md`

---

## 0. Keputusan yang sudah diambil (22 September 2026)

| Pertanyaan | Jawaban | Akibatnya pada rencana |
|---|---|---|
| Perangkat kasir | **PC / laptop Windows** | Cetak struk lewat driver Windows — tidak perlu program tambahan di outlet. Flutter makin tidak mendesak. |
| Internet di outlet | **Sering putus-putus** | **POS‑2 (tahan gangguan) naik ke urutan kedua**, tepat setelah cetak struk. Bukan lagi pekerjaan yang boleh menyusul. |
| QRIS | **QRIS statis outlet + konfirmasi kasir** | Tidak perlu payment gateway untuk gelombang pertama. Perlu layar konfirmasi berikut kendali agar uang tetap terpantau — lihat §3.1. |
| Printer | **Kiosk printing Chrome** (`--kiosk-printing`) | Tanpa dialog cetak, langsung ke printer default Windows. Satu printer saja; agen cetak lokal menyusul bila dapur perlu printer sendiri. |

**Sudah dikerjakan (22–23 September):** cetak struk · cetak tiket dapur · cetak ulang · lebar kertas
58/80 mm · uji otomatis Playwright untuk alur kasir · **nomor meja** (tombol pintas 1–N dari pengaturan
outlet, wajib sebelum kirim ke dapur untuk makan di tempat, tercetak di tiket dapur dan struk) ·
perbaikan tiket dapur agar memuat varian dan modifier · 10 galat Larastan lama dibereskan.

---

## 1. Apa yang berubah karena keputusan ini

Sampai kemarin POS web adalah **alat peragaan**. Begitu ia dipakai berjualan, ia menjadi **alat kerja
yang memegang uang**. Tiga hal langsung berubah sifatnya:

- **Struk bukan lagi tampilan.** Sekarang struk hanya ditampilkan sebagai teks di layar. Kasir sungguhan
  harus bisa mencetak, dan pelanggan berhak menerimanya.
- **Internet putus bukan lagi gangguan kecil.** Saat ini bila internet putus, kasir berhenti total —
  tidak ada transaksi yang bisa diselamatkan.
- **Kesalahan tidak bisa dibereskan lewat `migrate:fresh`.** Retur, salah input, dan selisih kas harus
  punya jalan keluar di dalam aplikasi.

Kabar baiknya: **sisi server sudah jauh lebih siap daripada layarnya.** Beberapa hal di bawah ini hanya
perlu layar, bukan mesin baru.

---

## 2. Kondisi POS hari ini — jujur

### Sudah berjalan sungguhan (server + layar)

Pemasangan perangkat dengan kode pairing · login PIN kasir dengan penguncian setelah gagal berulang ·
buka & tutup shift dengan hitung kas · katalog per outlet · varian, modifier, paket · **harga, promo,
service charge, PB1, dan pembulatan dihitung di server** (layar tidak pernah menghitung sendiri) ·
harga berbeda per channel · kirim ke dapur (stok bahan terpotong sesuai resep) · simpan transaksi dengan
nomor struk resmi · bayar tunai dengan kembalian · QRIS sandbox · **void berotorisasi supervisor** ·
daftar transaksi · laporan shift. Semuanya langsung terlihat di back-office dan laporan.

### Mesinnya sudah ada, layarnya belum — pekerjaan ringan

| Kemampuan | Endpoint yang sudah jadi |
|---|---|
| **Retur / refund** (sebagian maupun penuh) | `POST /pos/orders/{id}/refunds` |
| **Kas masuk & keluar saat shift** (setoran, tambah modal, kasbon) | `POST /pos/shifts/{id}/cash-movements` |
| **Tandai menu habis dari kasir** | `POST /pos/items/{id}/sold-out` |
| **Bayar gabungan** (tunai + QRIS dalam satu struk, sampai 10 pembayaran) | sudah didukung `POST /pos/orders` |
| **Meja, nama pelanggan, catatan, nomor antrean** | kolom `table_label`, `customer_name`, `note`, `queue_no` sudah ada di tabel `orders` |
| **Antrean sinkronisasi offline** | `POST /sync/push`, `GET /sync/pull` |
| **Pemantauan perangkat** | `POST /devices/heartbeat` |

### Belum ada sama sekali *(disegarkan 23 September)*

- **Mode offline.** Tidak ada penyimpanan lokal transaksi, tidak ada service worker, tidak ada PWA.
- **Tahan / parkir bill** dan **gabung bill** — kasir hanya bisa memegang satu pesanan terbuka.
- **Diskon manual dan ubah harga berotorisasi**, serta pemakaian kode promo dari layar kasir.
- **Batal item** sebelum bayar (yang ada baru void seluruh struk sesudah bayar).
- **Laci uang** (cash drawer) dan **layar pelanggan**.
- **KDS** (layar dapur) — tabel `kitchen_tickets` juga belum punya kolom status.
- **Laporan X dan Z** untuk serah terima kas.
- **Cari struk lama**: cetak ulang hanya berlaku untuk transaksi dalam sesi berjalan.

Sudah selesai sejak dokumen ini ditulis pertama kali: cetak struk, cetak tiket dapur, cetak ulang,
nomor meja, dan uji otomatis layar kasir.

### Catatan teknis yang perlu diketahui

- Seluruh layar kasir ada dalam **satu berkas** `pos/app.blade.php` (±1.700 baris, JavaScript polos,
  tanpa build). Ini disengaja agar cepat dan tidak perlu `npm run build` di outlet. Sudah mendekati batas
  wajar: **pecah sebelum memulai pekerjaan offline**, bukan sesudahnya.
- Nomor struk lokal disimpan di `localStorage` dan diselaraskan dari server, dengan 40 kali percobaan
  ulang bila bentrok. Cara ini cukup untuk satu perangkat online, **tidak cukup untuk offline**.
- Payment gateway masih **sandbox**. Tombol "simulasikan pembayaran" tidak boleh ada di produksi.

---

## 3. Rekomendasi bertahap

### POS‑1 — Layak dipakai berjualan · ±2 minggu · **wajib sebelum outlet pertama**

Urutan di dalamnya sudah berdasarkan risiko, bukan kemudahan.

1. ~~**Cetak struk.**~~ **Selesai 22 September.** Dicetak lewat printer yang terpasang di komputer kasir
   (driver Windows), tanpa program tambahan di outlet. Lebar kertas **58 mm dan 80 mm** dapat dipilih di
   menu Atur dan tersimpan per perangkat; ada tombol **Uji cetak**. Jalur ESC/POS lewat agen lokal tetap
   terbuka bila nanti perlu potong kertas otomatis dan buka laci tanpa dialog (±1 minggu).
2. ~~**Cetak tiket dapur.**~~ **Selesai 22 September.** Tercetak otomatis saat menekan "Ke dapur",
   tanpa harga, nama menu huruf besar. Bisa dimatikan di menu Atur.
   *Belum termasuk:* penomoran tiket dan penanda "tambahan pesanan".
3. **Retur / refund** dengan otorisasi supervisor — server sudah siap, tinggal layar (±2 hari).
4. **Kas masuk/keluar shift** dan tutup shift yang menampilkan selisih dengan jelas (±2 hari).
5. **Batal item sebelum bayar** + **tandai habis** dari layar kasir (±1 hari).
6. **Bayar gabungan** tunai + non-tunai dalam satu struk (±2 hari).
7. ~~**Uji otomatis Playwright.**~~ **Selesai 22 September** untuk alur: terbitkan kode pairing di
   back-office → pasangkan → login PIN → pesan → tiket dapur → bayar tunai → struk tercetak → cetak ulang
   di kertas 58 mm. *Masih perlu ditambah:* void dan refund.
8. **QRIS statis + konfirmasi kasir** — lihat §3.1.

#### 3.1 QRIS statis: kendali yang perlu menyertainya

Tanpa payment gateway, sistem tidak punya cara memastikan uang benar-benar masuk — yang memastikan adalah
kasir. Karena itu kemudahan ini harus disertai jejak, bukan sekadar tombol "lunas":

- kasir **wajib mengisi nominal dan waktu** yang terbaca di notifikasi bank, serta 4 digit terakhir
  pengirim bila terlihat;
- transaksi ditandai **"QRIS — menunggu verifikasi"**, bukan langsung lunas bersih, sampai dicocokkan;
- **laporan harian QRIS** di back-office untuk dicocokkan dengan mutasi rekening;
- tombol **"simulasikan pembayaran"** yang sekarang ada harus mati di lingkungan produksi.

Dua hal di atas menyangkut pengakuan uang masuk, jadi saya tidak akan menetapkannya sendiri: **perlu
persetujuan Anda** atas bentuk kendalinya sebelum saya bangun.

---

## 3.2 Usulan lanjutan (23 September) — empat lapis

Ditulis setelah menelusuri ulang kode server dan layar kasir. Temuan utamanya: **server jauh lebih
mampu daripada yang terlihat di layar.** Lapis pertama di bawah bukan membangun mesin baru, melainkan
memunculkan yang sudah ada dan sudah teruji.

### Lapis 1 — Mesinnya sudah jadi, tinggal layar · ±1,5 minggu · dampak terbesar per hari kerja

| Kemampuan | Status | Catatan |
|---|---|---|
| **Retur / refund** | **Selesai 23 Sep** | Pilih item dan jumlah atau retur seluruhnya; alasan wajib; barang kembali ke stok atau dibuang; kasir tanpa izin `pos.void` wajib PIN supervisor. Nilai retur **dihitung server** — layar hanya memperkirakan, dan bila berbeda permintaan diulang sekali memakai angka server. |
| **Kas masuk & keluar shift** | **Selesai 23 Sep** | Tombol di layar Shift; keterangan wajib agar selisih kas bisa ditelusuri; langsung terhitung di kas seharusnya saat tutup shift. |
| **Tandai menu habis** | **Selesai 23 Sep** | Daftar menu dengan pencarian di layar Atur; kartu menu di layar kasir langsung berubah. |
| **Bayar gabungan** | **Selesai 23 Sep** | Sebagian tunai/kartu lalu sisanya dengan metode lain; sisa tagihan terlihat; kembalian hanya dari pembayaran tunai penutup. **QRIS sementara hanya untuk pembayaran penuh** karena nominal kode QR harus sama dengan tagihannya. |
| **Nama tamu, nomor antrean, catatan pesanan** | **Selesai 23 Sep** | Satu isian di panel pesanan; ikut tercetak di tiket dapur dan struk. |
| **Diskon manual berotorisasi** | **Tertahan** | Lihat §3.3 — butuh satu keputusan Anda. |
| **Ubah harga (price override)** | **Tertahan** | Lihat §3.3 — butuh perubahan server. |

Bersamaan dengan itu, alur PIN supervisor dijadikan **satu mekanisme untuk semua aksi** (void, retur,
buka laci): kasir yang memang berwenang tidak ditanyai PIN, yang tidak berwenang selalu ditanyai, dan
tombol kirim terkunci sampai daftar supervisor selesai dimuat.

### 3.3 Dua butir Lapis 1 yang tertahan — perlu keputusan

Keduanya ditemukan saat pengerjaan, bukan saat perencanaan.

**Diskon manual tidak bisa dipakai kasir biasa.** Server sudah lengkap: diskon per baris dan per struk,
batas persen per peran, otorisasi supervisor, jejak audit. Tetapi endpoint **simulasi total**
(`POST /pos/quotes`) menolak diskon manual bila **kasir yang login** tidak punya izin `pos.discount` —
dan peran `cashier` bawaan memang tidak memilikinya. Akibatnya kasir tidak bisa melihat total setelah
diskon, sehingga alur "minta PIN supervisor lalu beri diskon" tidak dapat berjalan sama sekali.

Ini terbaca sebagai **penjagaan yang keliru tempat**, bukan kebijakan: penegakan yang sesungguhnya sudah
ada di titik simpan transaksi, tempat otorisasi dan batas persen diperiksa. Tiga pilihan:

1. **Longgarkan simulasi saja** — `POST /pos/quotes` boleh menghitung diskon manual untuk siapa pun yang
   sudah login sebagai kasir. Tidak ada uang yang diikat oleh simulasi; penegakan tetap di titik simpan.
   Paling sederhana, ±0,5 hari.
2. **Simulasi menerima bukti otorisasi** — kasir minta PIN supervisor lebih dulu, lalu id otorisasinya
   ikut dikirim saat menghitung. Paling ketat, tetapi menambah pemeriksaan baru di server, ±2 hari.
3. **Beri izin `pos.discount` kepada peran kasir** — tidak saya sarankan: itu menghapus kendali
   supervisor, bukan memindahkannya.

Saya condong ke **pilihan 1**, tetapi ini menyangkut kendali atas potongan harga, jadi **keputusan Anda**.

**Ubah harga belum bisa dijalankan utuh.** Endpoint simulasi tidak menerima harga satuan sama sekali,
jadi total yang dihitung server tidak akan pernah cocok dengan harga yang diubah kasir — transaksinya
akan ditolak dengan selisih total. Perlu penambahan di server (±1 hari) sebelum layarnya berguna.
Dari tujuh butir Lapis 1, ini yang **paling jarang dipakai**, jadi saya sarankan dikerjakan terakhir.

### Lapis 2 — Agar layak dipakai sehari-hari · ±3–4 minggu

1. **Tahan / parkir bill.** Ini yang paling saya tekankan. Sekarang kasir hanya bisa memegang **satu**
   pesanan terbuka. Untuk outlet ber-`order_mode` **dine-in (bayar di akhir)**, itu berarti kasir tidak
   bisa melayani meja kedua sebelum meja pertama membayar — praktis tidak terpakai. Untuk outlet
   quick-service (bayar di depan) tidak masalah. Jadi: **wajib bila outlet pertama memakai mode dine-in**,
   dan boleh menyusul bila tidak. (±3 hari)
2. **Mode offline (POS‑2 di bawah).** Tetap prioritas karena jaringan outlet tidak stabil.
3. **Batal item sebelum bayar**, terpisah dari void seluruh struk. (±1 hari)
4. **Laporan X dan Z.** X = rekap tengah shift tanpa menutup; Z = rekap tutup shift yang dicetak. Ini yang
   dipakai supervisor untuk serah terima kas. (±1,5 hari)
5. **Cari struk lama dan cetak ulang.** `GET /pos/orders?receipt_no=` sudah ada; sekarang cetak ulang hanya
   bisa untuk transaksi dalam sesi berjalan — begitu layar dimuat ulang, riwayatnya hilang. (±1 hari)
6. **Kunci layar & ganti kasir cepat.** Satu perangkat dipakai bergantian; sekarang layar tetap terbuka
   dengan sesi kasir aktif selama 16 jam. (±1 hari)
7. **Pengerasan kiosk.** Cegah tab tertutup atau dimuat ulang saat keranjang berisi, dan tampilkan
   peringatan sebelum keluar. Murah, tapi menyelamatkan transaksi yang sedang diketik. (±0,5 hari)

### Lapis 3 — Yang membuatnya terasa advance · sesuai kebutuhan

- **KDS (layar dapur).** Tiket dapur sudah tersimpan lengkap dengan stasiun per baris, tetapi tabel
  `kitchen_tickets` **belum punya kolom status** — jadi KDS butuh kolom status per tiket/baris, endpoint
  penanda selesai, dan layar dapur. Ini pengganti kertas yang sesungguhnya. (±1,5 minggu)
- **Data induk meja + denah meja.** Naik dari teks bebas menjadi tabel meja per outlet dengan area dan
  kapasitas, lalu denah dengan status kosong / terisi / menunggu bayar. Satu paket dengan tahan bill. (±1,5 minggu)
- **Self-order QR per meja.** Channel `self_order` sudah ada di data dan nomor meja sudah tersimpan —
  tamu memindai QR di meja, pesanan masuk ke antrean kasir untuk dikonfirmasi. Pembeda yang kuat untuk
  outlet ramai, dan memakai fondasi yang sudah ada. (±2 minggu)
- **Pelanggan & member sederhana.** Nomor HP sebagai kunci, riwayat belanja, poin. (±1,5 minggu)
- **Layar pelanggan di monitor kedua** — rincian pesanan dan total yang dilihat tamu. (±3 hari)
- **Integrasi GoFood/GrabFood/ShopeeFood.** Paling mahal dan paling bergantung mitra (±6–8 minggu);
  kerjakan hanya bila ada pelanggan yang mensyaratkannya.

### Lapis 4 — Utang teknis yang akan menggigit bila dibiarkan

- **`pos/app.blade.php` sudah ±1.700 baris.** Masih terkendali, tetapi pekerjaan offline akan menambah
  banyak. Pecah menjadi beberapa berkas (dengan `@include`, tetap tanpa build) **sebelum** memulai POS‑2.
- **Nomor struk masih dari penghitung `localStorage`** dengan 40 kali coba ulang saat bentrok. Cukup untuk
  satu perangkat online; **tidak cukup untuk offline.** Ganti dengan rentang nomor per perangkat.
- **Token kasir disimpan di `localStorage` dan berumur 16 jam.** Bila perangkat dipakai bergantian tanpa
  kunci layar, siapa pun yang duduk di depannya adalah kasir tersebut di mata sistem.
- **Uji e2e POS belum mencakup void dan refund** — dua alur yang justru paling menyentuh uang.
- **`ReportScheduleTest` masih rapuh terhadap jam jalan.** Pernah gagal sekali lalu lulus saat diulang.

---

### POS‑2 — Tahan gangguan internet · ±2–3 minggu · **naik ke urutan kedua**

Internet outlet dinyatakan sering putus-putus, jadi tahap ini **tidak boleh menyusul**: tanpa ini, setiap
gangguan jaringan menghentikan penjualan. Dikerjakan tepat setelah sisa POS‑1.

1. **PWA + service worker**: layar tetap terbuka dan dapat dimuat ulang meski jaringan hilang; bisa
   dipasang sebagai ikon di layar utama tablet.
2. **Katalog & harga tersimpan lokal** (IndexedDB), disegarkan lewat `sync/pull` yang sudah ada.
3. **Outbox transaksi**: transaksi tersimpan lokal lalu dikirim lewat `sync/push` begitu jaringan kembali,
   dengan indikator antrean yang terlihat kasir.
4. **Rentang nomor struk per perangkat** menggantikan penghitung `localStorage` sekarang — tanpa ini,
   transaksi offline berpotensi bentrok nomor.
5. **Otorisasi supervisor saat offline.** Ini menyentuh keamanan; perlu ADR tersendiri dan **keputusan
   Anda** tentang seberapa longgar boleh — saya tidak akan menebaknya.

### POS‑3 — Nyaman untuk operasional harian · ±1,5 minggu

Meja & nomor antrean · nama pelanggan & catatan pesanan · tahan/parkir bill dan gabung bill ·
diskon manual berotorisasi serta kode promo dari kasir · cetak ulang struk · **laporan X** (rekap tengah
shift tanpa menutup) dan **laporan Z** (rekap tutup shift) · pencarian transaksi lebih baik.

Sebagian besar kolomnya sudah ada di basis data, jadi ini pekerjaan layar.

### POS‑4 — Menyusul, sesuai kebutuhan pasar

KDS (layar dapur) · integrasi GoFood/GrabFood/ShopeeFood · member & loyalty · self-order QR ·
layar pelanggan · aplikasi Flutter.

**Tentang Flutter:** dengan POS web dipakai lebih dulu, saya sarankan **menunda Flutter**, bukan
membatalkannya. POS web memakai API yang sama persis, jadi tidak ada pekerjaan yang terbuang. Flutter baru
benar-benar diperlukan bila muncul salah satu dari: printer harus dikendalikan langsung tanpa agen,
perangkat Android murah dengan browser bermasalah, atau kebutuhan offline yang lebih keras daripada yang
bisa dicapai PWA.

---

## 4. Yang tidak bisa dilewati meski fokusnya POS

Kasir sungguhan berarti **kehilangan data sama dengan kehilangan uang**. Bagian Tahap 6 berikut tetap
harus jalan berbarengan dengan POS‑1 — tidak perlu seluruhnya, tetapi empat ini tidak bisa ditawar:

1. **Backup harian yang sudah diuji restore.** Bukan sekadar dijadwalkan — dibuktikan sekali.
2. **Scheduler & queue worker berjalan** (`inventory:post-sales`, `reports:send-scheduled`, `fnb:partitions`).
   Tanpa ini stok dan laporan terjadwal diam-diam tidak bergerak.
3. **Saklar prototipe mati di produksi**: `FNB_PROTOTYPE_ACCOUNTING=false`, `FNB_DEMO_LOGIN=false`, dan
   tombol "simulasikan pembayaran" QRIS tidak boleh aktif.
4. **Pemantauan dasar**: notifikasi bila aplikasi mati atau antrean macet, dan error log yang terkumpul
   di satu tempat.

Catatan tentang Railway: penyimpanan kontainer bersifat sementara. Selama berkas yang penting hanya ada di
basis data Postgres, ini aman. Begitu ada unggahan (foto nota, foto menu), perlu penyimpanan objek terpisah.

---

## 5. Keputusan yang saya butuhkan dari Anda

Enam hal berikut mengubah isi dan urutan pekerjaan, dan tidak boleh saya tebak:

1. **Perangkat kasir apa?** Tablet Android, PC Windows, atau iPad. Ini menentukan cara cetak dan apakah PWA cukup.
2. **Printer merek dan model apa,** serta sambungannya (USB, LAN, atau Bluetooth). Kompatibilitas printer
   adalah penyebab kegagalan paling sering di lapangan.
3. **Seberapa andal internet di outlet?** Ini satu-satunya penentu apakah POS‑2 naik ke urutan pertama.
4. **QRIS:** memakai mitra payment gateway (yang mana?), atau untuk sementara **QRIS statis milik outlet**
   dengan konfirmasi manual oleh kasir? Jalur kedua jauh lebih cepat, tetapi perlu disiplin pencocokan
   mutasi bank — dan perlu keputusan Anda, karena menyangkut uang.
5. **Berapa outlet dan berapa kasir** di gelombang pertama. Saya tetap menyarankan **pilot satu outlet
   dua minggu** sebelum yang lain menyusul.
6. **Dapur:** cukup cetak tiket kertas, atau perlu layar KDS sejak awal?

---

## 6. Ringkasan urutan yang saya sarankan

Diperbarui setelah keputusan 22 September:

| Urutan | Isi | Perkiraan |
|---|---|---|
| ~~0~~ | ~~Cetak struk, tiket dapur, cetak ulang, nomor meja, uji otomatis~~ — **selesai** | — |
| ~~1~~ | ~~**Lapis 1**: retur, kas shift, tandai habis, bayar gabungan, tamu/antrean/catatan~~ — **selesai 23 Sep**; diskon & ubah harga tertahan (§3.3) | — |
| **2** | QRIS statis + kendalinya · batal item · tahan bill **bila outlet memakai mode dine-in** | 1 minggu |
| **3** | Pecah `app.blade.php`, lalu **POS‑2 (offline)** | 2,5–3 minggu |
| **4** | Pilot satu outlet, berdampingan dengan cara lama | 2 minggu |
| **5** | Sisa Lapis 2: laporan X/Z, cari struk lama, kunci layar, pengerasan kiosk | 1 minggu |
| **6** | Lapis 3 sesuai kebutuhan (KDS, denah meja, self-order QR), lalu modul akuntansi | — |

Empat butir infrastruktur di §4 (backup teruji, scheduler & queue, saklar prototipe mati, pemantauan)
dikerjakan berbarengan dengan urutan 1–2, bukan sesudahnya.

Modul akuntansi tidak hilang dari peta jalan; ia hanya bergeser ke belakang POS. Yang tetap layak
dikerjakan murah sambil menunggu adalah **discovery akuntansi**: kumpulkan COA klien, contoh SPPK dan
advis, bentuk laporan keuangan yang mereka pakai, dan struktur badan hukumnya.
