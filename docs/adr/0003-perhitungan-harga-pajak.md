# ADR 0003 — Perhitungan total transaksi (harga, diskon, service charge, pajak, pembulatan)

- Status: Diterima (15 Sep 2026). Urutan SRS §8.1 dan pembulatan default Rp100 terdekat dikonfirmasi user.
- Kebutuhan: FR-POS-20, BR-01 s.d. BR-05, BR-18, NFR-CMP-03
- Implementasi: `backend/app/Modules/Catalog/Domain/Pricing/PricingCalculator.php`
- Kasus uji bersama PHP–Dart: `shared/fixtures/pricing/*.json`

## Aturan
Semua nilai uang diproses sebagai desimal (bukan float). Setiap **komponen** dibulatkan ke 2 desimal
(half-up) sekali saja, lalu komponen berikutnya dihitung dari nilai yang sudah dibulatkan. Dengan begitu
PHP dan Dart menghasilkan angka yang sama persis.

1. `gross` baris = (harga satuan + Σ harga modifier × qty modifier) × qty.
2. Diskon item diterapkan berurutan pada sisa nilai baris (persen dari sisa, atau nominal dibatasi sisa).
   Total diskon item = pembulatan jumlah presisi penuh.
3. Diskon transaksi diterapkan berurutan pada subtotal setelah diskon item (dibatasi sisa, tidak pernah minus).
4. `dasar_sc` = subtotal − diskon item − diskon transaksi.
5. Service charge = dasar × tarif SC (0 bila channel tidak dikenai SC, BR-01).
6. Pajak = (dasar + SC bila `tax_on_service_charge`) × tarif pajak.
7. Pembulatan total ke kelipatan `rounding_unit` (default 100, mode terdekat; 0 = tanpa pembulatan),
   dicatat sebagai komponen terpisah (BR-03). Pengaturan per outlet.
8. TOTAL = dasar + SC + pajak + pembulatan.

### Harga sudah termasuk pajak (BR-02)
Harga menu dianggap sudah memuat pajak **atas harga menu saja**:
- pajak menu = dasar_termasuk_pajak × tarif / (100 + tarif)
- dasar = dasar_termasuk_pajak − pajak menu
- SC = dasar × tarif SC; pajak SC = SC × tarif (bila `tax_on_service_charge`)
- pajak = pajak menu + pajak SC; TOTAL = dasar + SC + pajak + pembulatan

Artinya pelanggan membayar harga menu apa adanya bila outlet tidak memakai service charge.
**Perlu divalidasi konsultan pajak sebelum pilot (SRS §12.3 no. 7).**

### Alokasi ke baris
Diskon transaksi dialokasikan ke baris secara proporsional terhadap nilai baris setelah diskon item,
dengan metode sisa terbesar (selisih sen diberikan ke baris dengan sisa pecahan terbesar, lalu urutan baris).
Dipakai untuk laporan per item dan HPP.

## Promo (BR-18)
Implementasi: `backend/app/Modules/Catalog/Domain/Pricing/PromotionEngine.php`, kasus uji `shared/fixtures/promotions/*.json`.

1. Promo disaring: aktif, periode, outlet, channel, metode bayar, hari & jam (boleh melewati tengah malam,
   memakai zona waktu outlet), minimal belanja, sisa kuota, brand, dan kode (promo non-otomatis hanya
   berlaku bila kodenya dimasukkan).
2. Promo per menu dihitung lebih dulu terhadap baris yang cocok, lalu promo transaksi terhadap sisa subtotal.
3. Dari promo yang **tidak bisa digabung**, dipilih satu yang potongannya terbesar. Semua promo yang
   **bisa digabung** dijumlahkan. Hasil yang dipakai adalah yang lebih menguntungkan pelanggan di antara
   keduanya (seri → promo tunggal, lalu prioritas, lalu urutan).
4. `max_discount` membatasi total potongan satu promo. Potongan tidak pernah melebihi nilai baris/transaksi.
5. Beli X gratis Y: semua unit yang cocok diurutkan dari harga termahal; setiap kelompok `X + Y` unit,
   `Y` unit termurah di kelompok itu gratis. Qty pecahan (menu berat) tidak ikut. Dihitung per kelompok
   harga (bukan per unit) agar qty besar tetap ringan.

## Kode promo di POS offline
Snapshot menu POS (`GET /pos/catalog`) **tidak** memuat kode promo asli. Setiap promo berkode membawa
`code_hash` = `pbkdf2_sha256$100000$<hex>` dengan PBKDF2-SHA256(UPPER(TRIM(kode)), salt = id promo,
100.000 iterasi, 32 byte). POS menghitung hash yang sama dari kode yang diketik kasir.
Keterbatasan: pemegang token perangkat masih bisa menebak kode pendek/umum secara offline (lebih lambat
karena PBKDF2). Karena itu: gunakan kode yang cukup panjang untuk promo bernilai besar, dan saat online
server memvalidasi ulang kode ketika transaksi disinkronkan (Tahap 3).

## Data menu yang dihapus
Menu, kategori, grup modifier, dan promo memakai soft delete. Varian, pilihan modifier, dan isi paket
dihapus permanen saat daftar penggantinya disimpan; transaksi (Tahap 3) wajib menyimpan salinan nama
& harga sehingga tidak bergantung pada baris tersebut. Riwayat harga tetap menyimpan ID varian tanpa
foreign key sehingga tidak ikut terhapus.

## Validasi masukan
Kalkulator dan mesin promo menolak float dan angka tak lazim (`+5`, `.5`). API menolak diskon persen
> 100 dan angka non-desimal biasa dengan 422 sebelum sampai ke kalkulator.
