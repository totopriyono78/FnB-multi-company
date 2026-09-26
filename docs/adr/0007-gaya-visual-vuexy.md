# ADR 0007 — Gaya visual mengikuti template Vuexy

- Tanggal: 24 Sep 2026
- Status: Diterima (keputusan user)
- Mengubah: CLAUDE.md §5 (Pedoman Desain)

## Konteks

User meminta tampilan back-office, halaman Akuntansi, dan layar kasir mengikuti gaya
template admin Vuexy (Vue 2, demo-1 dashboard eCommerce). Beberapa ciri Vuexy bertentangan
dengan CLAUDE.md §5.1 lama (gradien & pendar di menu aktif, bayangan kartu). User memilih
**setia penuh** pada gaya Vuexy, dengan **aksen tetap hijau daun FnB Cloud** (bukan ungu #7367F0).

## Nilai yang diambil dari Vuexy (diukur dari halaman demo)

| Unsur | Nilai |
|---|---|
| Huruf | Montserrat 14px; teks isi #6e6b7b, judul #5e5873 |
| Latar | #f8f8f8 (gelap: #161d31, kartu #283046) |
| Kartu | tanpa garis, radius 6px, `0 4px 24px 0 rgba(34,41,47,.1)`, padding 21px |
| Sidebar | putih 260px, `0 0 15px 0 rgba(34,41,47,.05)`; label grup huruf kapital 12px |
| Menu aktif | `linear-gradient(118deg, aksen, aksen 70%)` + pendar `0 0 10px 1px aksen 70%`, radius 4px; hover geser 5px |
| Topbar | melayang: radius 6px, bayangan kartu, margin atas ±13px, latar pudar di belakangnya |
| Tabel | kepala #f3f2f7, 12px kapital tebal, spasi huruf .5px; garis #ebe9f1 |
| Tombol | radius 5px, weight 500; hover menyala `0 8px 25px -8px aksen` |
| Tab/pill aktif | latar aksen + `0 4px 18px -4px aksen 65%` |
| Statistik | ikon dalam lingkaran bernuansa 12% warna status |
| Status | success #28c76f, danger #ea5455, warning #ff9f43, info #00cfe8 |

## Penyesuaian yang tetap dipertahankan

- **WCAG 2.1 AA tetap wajib.** Rona status Vuexy terlalu terang untuk teks; tingkat 600 tiap
  palet digelapkan (mis. danger-600 #c42f30, success-600 #168045) agar ≥ 4,5:1. Label grup
  sidebar memakai #6e6b7b, bukan #a6a4b0 Vuexy (2,45:1).
- Semua nilai tetap berasal dari token: `resources/css/filament/admin/theme.css`
  (back-office & Akuntansi) dan blok `:root` di `resources/views/pos/app.blade.php` (POS).
  `app/Filament/DesignTokens.php` menyalin nilai untuk palet Filament & grafik.
- Tidak ada emoji, hero sambutan, maupun data/copy palsu dari demo Vuexy.

## Akibat

- `npm run build` wajib setelah mengubah `theme.css`; hasilnya (`public/build`) ikut dikirim
  karena komputer user tidak menjalankan npm.
- Montserrat dimuat dari fonts.bunny.net; bila offline jatuh ke Helvetica/Arial.

## Pembaruan 26 Sep 2026 — warna brand cokelat tua + emas

Keputusan user: warna utama diganti **cokelat tua (deep brown) + emas (gold)**, menggantikan hijau daun.

| Peran | Token | Nilai | Catatan kontras |
|---|---|---|---|
| Utama (tombol, tautan, fokus, pilihan aktif) | `--primary-600` | #5d3a1f | 10:1 terhadap putih |
| Aksen emas (menu aktif, logo, garis penanda) | `--fnb-gold-500` | #d4af37 | 2,1:1 terhadap putih → **bukan untuk teks di latar terang** |
| Emas untuk ikon/teks di latar terang | `--fnb-gold-700` | #8c6d1c | 4,9:1 |
| Sidebar semi-gelap | `--fnb-sidebar-bg` | #2e1d12 | teks #e9dccb 12:1, label #bfa98a 7:1 |
| Teks di menu aktif emas | `--fnb-sidebar-active-text` | #2e1d12 | 7,7:1 |
| Netral terang (abu hangat) | `--gray-50…500` | #f8f6f3 … #6f6457 | teks isi 5,8:1 |
| Mode gelap | `--gray-950/900` | #1a120c / #261a12 | tautan emas (primary-400 = #ddb94a) 7,7:1 |

- Sidebar memakai varian **"semi dark menu" Vuexy**: latar cokelat tua, menu aktif gradien emas + pendar emas.
- Tombol & tautan cokelat tua; ikon statistik bawaan emas-700; kartu login & layar kasir diberi garis atas emas 4px;
  batang laporan bergradien cokelat (`--fnb-gradient-primary`).
- Bayangan dihangatkan (`rgba(58,36,19,…)`) agar serasi dengan palet cokelat.
- POS: rail kiri cokelat tua dengan menu aktif emas, merek "FNB" emas, KPI bergaris emas.
