# Fixture harga & pajak bersama (BR-05)

Setiap file berisi `input` untuk kalkulator dan `expected` (hanya kunci yang disebut yang dibandingkan).
Dipakai oleh:
- PHP: `backend/tests/Unit/Pricing/PricingFixturesTest.php`
- Dart (POS, Tahap 6): `apps/pos/test/pricing_fixtures_test.dart`

Aturan perhitungan: `docs/adr/0003-perhitungan-harga-pajak.md`. Semua angka ditulis sebagai teks desimal.
Nilai `expected` dihitung manual, bukan disalin dari keluaran program.
Menambah kasus: buat file baru `NN-nama-kasus.json`; kedua implementasi wajib lulus.
