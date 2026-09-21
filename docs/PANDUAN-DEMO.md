# Panduan Demo — FnB Cloud

Diperbarui: 21 September 2026 · seluruh 593 uji otomatis tetap lulus

---

## 1. Menjalankan (Windows)

```powershell
cd D:\DEVELOPMENT\FB_Multi_Company\backend
php85
php artisan optimize:clear      # WAJIB — ada config, route, dan view baru
php artisan serve
```

| Layar | Alamat |
|---|---|
| **Aplikasi kasir (POS web)** | `http://127.0.0.1:8000/pos` |
| Back-office & Akuntansi | `http://127.0.0.1:8000/admin` |

Login back-office: **rina@kopinusantara.test** / **Rahasia123**

> Buka `/pos` di tab terpisah dan tekan **F11**. Siapkan juga satu tab `/admin` agar bisa berpindah cepat.

---

## 2. Yang berubah: POS web sekarang klien sungguhan

Layar kasir **tidak lagi prototipe**. Ia memakai **API POS yang sesungguhnya** — sama persis dengan
yang akan dipakai aplikasi Flutter nanti:

| Langkah | Endpoint yang dipakai |
|---|---|
| Pasang perangkat dengan kode pairing | `POST /api/v1/devices/pair` |
| Kasir login PIN | `GET /pos/staff` → `POST /pos/auth/pin` |
| Buka shift | `GET /pos/shifts/current` → `POST /pos/shifts` |
| Tarik menu | `GET /pos/catalog` |
| Hitung harga, promo, pajak | `POST /pos/quotes` — **dihitung server**, bukan di layar |
| Kirim ke dapur | `POST /pos/kitchen-tickets` |
| Simpan transaksi | `POST /pos/orders` |
| QRIS | `POST /payments/qris` → `POST /payments/{id}/simulate` |
| Void berotorisasi | `POST /pos/authorize` → `POST /pos/orders/{id}/void` |

Tidak ada jalan pintas: tanpa kode pairing dan PIN kasir, tidak ada transaksi yang bisa masuk.
Jembatan demo yang lama (yang menyimpan transaksi tanpa token perangkat) **sudah dihapus**.

---

## 3. Persiapan sekali saja: pasangkan perangkat

1. Buka `/admin` → **Organisasi → Perangkat**.
2. Pilih perangkat kasir (mis. **POS01**) → tombol **Buat Kode Pairing**.
3. Kode 8 huruf muncul di notifikasi (berlaku beberapa menit).
4. Buka `/pos` → masukkan kode → **Pasangkan**.

Perangkat tersimpan di browser, jadi langkah ini tidak perlu diulang. Untuk demo, langkah ini
justru bagus ditunjukkan: memperlihatkan bahwa perangkat kasir harus didaftarkan lebih dulu.

**Akun kasir untuk demo** (PIN):

| Nama | Peran | PIN |
|---|---|---|
| Andi Saputra | Kasir Kemang | **7351** |
| Dewi Lestari | Manajer Outlet (supervisor void/diskon) | **482915** |
| Rina Hartono | Pemilik | 802614 |

---

## 4. Urutan demo (±15 menit)

### A. Kasir — transaksi sungguhan (6 menit)
1. `/pos` → pilih **Andi Saputra** → ketik PIN **7351**.
2. Bila diminta, isi **modal awal** → **Buka shift**. (Ini shift yang nanti terlihat di back-office.)
3. Pilih menu. Untuk item berva­rian/bertopping akan muncul pilihan **varian, tingkat gula, suhu** —
   tunjukkan bahwa pilihan ini ikut tercatat.
4. Perhatikan panel kanan: **subtotal, PB1 10%, pembulatan Rp100, total** — semua **dihitung server**,
   bukan di layar. Ubah tipe pesanan (Makan di Tempat / Bawa Pulang) dan tunjukkan harga bisa berbeda per channel.
5. **Ke dapur** → tiket dapur tercatat; inilah saat stok bahan terpotong sesuai resep.
6. **Bayar** → **Tunai** (pilih nominal, kembalian otomatis) → **Selesaikan**.
   Kotak hijau menyebut nomor struk resmi, mis. `KMG-POS01-260921-0007`.
7. Ulangi satu transaksi dengan **QRIS**: tekan **Buat kode QR** (kode dari gateway sandbox muncul),
   lalu **Simulasikan pembayaran masuk** → status menjadi *paid* → **Selesaikan**.
   Jelaskan: di produksi, status ini datang dari webhook gateway berizin, bukan dari tombol simulasi.
8. Menu **Pesanan** di kiri → pilih **Void** pada salah satu transaksi → sistem meminta
   **otorisasi supervisor**: pilih Dewi Lestari, PIN **482915**, isi alasan → transaksi menjadi *voided*.

### B. Back-office — angka yang sama (4 menit)
9. `/admin` → **Penjualan → Transaksi**: transaksi tadi ada di urutan teratas, lengkap dengan
   item, pajak, metode bayar, kasir, dan perangkat. Yang di-void tampil dengan statusnya.
10. **Penjualan → Shift**: shift yang Anda buka tadi, beserta penjualan tunai/non-tunai.
11. **Laporan → Penjualan** (hari ini): angkanya sudah termasuk transaksi barusan.
12. **Laporan → Anti-Fraud**: void yang tadi muncul lengkap dengan nama pemberi otorisasi.
13. **Inventory → Mutasi Stok**: bahan terpotong otomatis dari resep menu yang dijual.

Kalimat penghubung: *"tidak ada pengetikan ulang — dari kasir langsung menjadi angka laporan,
dan di modul akuntansi nanti langsung menjadi jurnal penjualan."*

### C. Modul Akuntansi & Konsolidasi — prototipe tampilan (5 menit)
14. Menu **Akuntansi (Prototipe)**: Dashboard Holding → Bagan Akun → Jurnal & Buku Besar →
    SPPK & Advis → Laporan Keuangan → Konsolidasi Holding → Aset & Sewa.
15. Ikon **(i)** di kanan judul menjelaskan status tiap layar bila ditanya.

---

## 5. Batas yang jujur disampaikan

- **Sudah berjalan sungguhan:** seluruh back-office, POS web (pairing, PIN, shift, transaksi, QRIS
  sandbox, void berotorisasi), inventory, pembelian, laporan + ekspor + jadwal email, pemisahan data
  antar badan usaha sampai lapisan database, jejak audit. **593 uji otomatis** lulus.
- **Masih prototipe tampilan:** seluruh layar Akuntansi & Konsolidasi (angka contoh yang konsisten).
- **Belum ada:** aplikasi kasir Flutter (POS web ini memakai API yang sama, jadi bukan pekerjaan sia-sia),
  integrasi pesanan online (GoFood/GrabFood/ShopeeFood), payment gateway produksi, dan KDS.

---

## 6. Bila ada yang bermasalah

| Gejala | Tindakan |
|---|---|
| Halaman `/pos` meminta kode pairing lagi | Data browser terhapus. Buat kode baru di back-office. |
| "Kode pairing tidak berlaku" | Kode hanya sekali pakai dan berumur pendek — buat kode baru. |
| PIN ditolak berkali-kali | Akun terkunci sementara; buka **Pengguna & Akses → Staf → Buka Kunci PIN**. |
| Sesi kasir kedaluwarsa (16 jam) | Login PIN lagi; perangkat tetap terpasang. |
| Menu Akuntansi tidak muncul / layar lama | `php artisan optimize:clear` lalu muat ulang. |
| Ingin mulai dari data bersih | `php artisan migrate:fresh --seed` (±2 menit), lalu pasangkan perangkat lagi. |

**Saklar di `.env`** bila diperlukan:

```
FNB_POS_WEB=false                 # matikan halaman /pos
FNB_PROTOTYPE_ACCOUNTING=false    # sembunyikan layar Akuntansi prototipe
```

Keduanya **wajib dimatikan untuk prototipe akuntansi di lingkungan produksi**; POS web boleh tetap
menyala karena ia memakai jalur API resmi.
