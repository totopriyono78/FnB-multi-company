# Stress Test POS — k6

Target: `https://selarasposplus.up.railway.app` (Railway, Singapura).

## Persiapan (sekali)

```powershell
winget install k6 --source winget
# tutup lalu buka lagi PowerShell, cek:
k6 version
```

## Urutan menjalankan

```powershell
cd D:\DEVELOPMENT\FB_Multi_Company\stress-test
.\run.ps1 pos smoke      # WAJIB pertama: memastikan alur pairing, PIN, quote, order berhasil
.\run.ps1 web stress     # kapasitas mentah server (tanpa login)
.\run.ps1 pos load       # beban jam sibuk
.\run.ps1 pos stress     # cari titik jenuh
.\run.ps1 pos spike      # lonjakan mendadak
```

Bila PowerShell menolak menjalankan skrip: `Set-ExecutionPolicy -Scope Process Bypass`.

Selama tes berjalan, dasbor langsung ada di <http://127.0.0.1:5665>. Hasil tersimpan di `results\`.

## Apa yang dilakukan `pos-stress.js`

Meniru layar kasir web persis, lewat API resmi:

1. **Setup** (±1,5 menit, sengaja pelan karena rate limit login/pairing 10 per menit per IP):
   owner login → buat perangkat uji `K6S1..K6S3` per outlet (bila belum ada) → kode pairing →
   `/devices/pair` → PIN login 9 staf demo → tutup shift lama perangkat uji → buka shift baru →
   ambil katalog.
2. **Tiap iterasi VU** = satu transaksi: 1–4 item, `/pos/quotes` tiap keranjang berubah,
   `/pos/orders` bayar tunai; sesekali muat ulang katalog, lihat daftar order shift, ringkasan shift.
3. **Teardown**: tutup semua shift uji, logout PIN.

| Profil | Pola | Durasi |
|---|---|---|
| smoke | 2 VU | 1 m |
| load | naik ke 27 VU (3 per sesi kasir) | 9 m |
| stress | 20 → 40 → 60 → 90 → 120 VU | 16 m |
| spike | 10 → 120 VU dalam 20 detik | 5 m |
| soak | 27 VU konstan | 30 m (`-e SOAK_DURATION=60m`) |

Satu VU ≈ satu kasir yang sangat sibuk (transaksi tiap ±3 detik). Kasir nyata ±1 transaksi per
1–2 menit, jadi 27 VU kira-kira setara beban ratusan kasir.

## Hal yang perlu diketahui

- **Menulis data sungguhan** ke database demo: order bertanda `customer_name = "K6 STRESS TEST"`,
  ikut ke jurnal penjualan & laporan. Perangkat uji `K6S*` tetap ada setelah tes (dipakai ulang).
  Untuk menonaktifkannya di akhir: `k6 run -e PROFILE=smoke -e CLEANUP=revoke pos-stress.js`.
- **Rate limiter API 300 permintaan/menit per user.** Semua perangkat yang dipakai satu staf
  berbagi kuota itu. Dengan 9 staf, plafon API POS ≈ 45 permintaan/detik. Di atas itu server
  menjawab **429** — ini perlindungan yang bekerja, bukan server tumbang. 429 dihitung terpisah di
  metrik `throttled_429` dan tidak masuk `http_req_failed`.
- Variabel tambahan: `-e THINK=2` (jeda antar transaksi), `-e SESSIONS=4` (jumlah staf),
  `-e CHANNEL=dine_in`, `-e DEBUG=1` (cetak alasan order gagal), `-e BASE_URL=...`.

## Metrik kunci

| Metrik | Ambang lulus |
|---|---|
| `http_req_failed` (5xx / error nyata) | < 1% |
| `tx_success` (order tersimpan) | > 98% |
| `pos_quote` p95 | < 800 ms |
| `pos_order` p95 | < 1500 ms |
| `pos_catalog` p95 | < 2000 ms |
| `tx_duration` (keranjang → order tersimpan) p95 | < 4 s |
