# ADR 0002 — Otorisasi supervisor memakai pilihan nama + PIN

- Status: Diterima (15 Sep 2026) — **perlu konfirmasi Product Owner** (mengubah alur POS, CLAUDE.md §9)
- Kebutuhan: FR-AUTH-03, FR-AUTH-07, NFR-SEC-03, NFR-PERF-05

## Konteks
Rancangan awal memakai PIN saja (tanpa memilih nama), sehingga PIN harus unik per company. Review keamanan
menemukan dua masalah High: (1) pesan "PIN sudah dipakai" membocorkan PIN staf lain, termasuk pemilik;
(2) percobaan PIN yang tidak cocok dengan siapa pun tidak bisa dikunci.

## Keputusan
- Pop-up otorisasi di POS menampilkan daftar supervisor yang berwenang (`GET /pos/supervisors`);
  supervisor mengetuk namanya lalu memasukkan PIN. PIN tidak lagi wajib unik.
- Salah PIN menambah hitungan kunci milik supervisor tersebut (5 kali → terkunci 15 menit).
- Perangkat ditahan sementara setelah 10 kegagalan otorisasi dalam 15 menit.
- Semua penolakan memakai pesan yang sama.
- PIN di-hash dengan bcrypt cost 10 (konfigurasi `FNB_PIN_HASH_ROUNDS`) agar verifikasi ≤ 150 ms.

## Konsekuensi
- Tambahan satu ketukan hanya untuk aksi sensitif (void/refund/diskon/buka laci/ubah harga);
  transaksi standar kasir tidak berubah.
- Daftar supervisor ikut disinkronkan ke POS untuk mode offline (Tahap 3/6).
