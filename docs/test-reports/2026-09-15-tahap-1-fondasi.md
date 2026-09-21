# Laporan Putaran: Tahap 1 — Fondasi multi-tenant, organisasi, identitas & audit

- Tanggal: 2026-09-15
- Kebutuhan SRS: FR-TEN-01, FR-TEN-02, FR-TEN-03, FR-TEN-04, FR-TEN-05, FR-TEN-06, FR-TEN-08, FR-TEN-09 (tabel),
  FR-TEN-11 (sebagian: registrasi company), FR-AUTH-01, FR-AUTH-03, FR-AUTH-04, FR-AUTH-05, FR-AUTH-06, FR-AUTH-07,
  FR-AUTH-08, FR-AUTH-09, FR-AUTH-10, FR-DEV-01, FR-DEV-02, FR-DEV-06 (data), FR-DEV-07, FR-POS-33 (dasar),
  FR-AUD-01, FR-AUD-02, NFR-SEC-01/03/05/06/08, NFR-OBS-01, NFR-OBS-04, NFR-SCL-04 (audit_logs), NFR-MNT-01/03/06, BR-14, BR-20 (konfigurasi)
- Status: **LOLOS** (dengan catatan pemeriksaan yang tidak dapat dijalankan di bawah)
- Jumlah siklus uji: 3

## Perubahan
- `backend/` — proyek Laravel 12.69 + Filament 3.3 + Sanctum 4 + spatie/laravel-permission 6 (teams = company), Pest 3, Larastan 3.
- `app/Modules/Shared` — `Money` (brick/math, tanpa float), format respons standar SRS §7.4, `AssignRequestId`,
  `TenantBoundary`, helper RLS, `Like::escape`, perintah `fnb:partitions`.
- `app/Modules/Tenancy` — `TenantContext` (mode none/tenant/system), trait `BelongsToCompany` (global scope default-deny,
  route binding + log akses lintas tenant), model Company/Brand/Outlet/Device/Plan/CompanyModule, pairing perangkat
  (kode 8 karakter, HMAC, sekali pakai, 15 menit), penonaktifan jarak jauh, batas paket, mode baca-saja, API Super Admin.
- `app/Modules/Identity` — login email/HP + penguncian, registrasi mandiri, reset password, undangan akun lintas company,
  role bawaan sesuai Lampiran 12.1 + role kustom, cakupan brand/outlet, `GrantGuard` anti-eskalasi, PIN kasir &
  otorisasi supervisor (nama + PIN), pencabutan sesi per company.
- `app/Modules/Audit` — audit log append-only (trigger DB + model), dipartisi bulanan, redaksi data sensitif, pencarian.
- `app/Filament` — back-office: Ringkasan (perangkat offline, antrean sinkron, PIN terkunci), Brand, Outlet (tab pajak &
  operasional), Perangkat (kode pairing, nonaktifkan), Staf (role, cakupan, PIN), Audit Log, Profil Usaha, daftar usaha;
  token desain di `resources/css/filament/admin/theme.css`, avatar inisial lokal.
- `database/` — 7 migrasi (RLS di semua tabel ber-`company_id`), seeder paket & data demo realistis.
- `tests/` — 170 test Pest + 4 skenario Playwright (E2E + axe WCAG 2.1 AA).
- `docs/` — OpenAPI 3 (lolos `redocly lint`), ADR 0001 (tenancy), ADR 0002 (otorisasi supervisor).

## Hasil Quality Gate (siklus terakhir)
| # | Pemeriksaan | Hasil | Catatan |
|---|---|---|---|
| G1 | Format | ✅ Lolos | `pint --test` passed; `redocly lint` valid |
| G2 | Analisis statis | ✅ Lolos | Larastan level 6: 0 error |
| G3 | Unit test | ✅ Lolos | Money (pembulatan, format Rupiah), normalisasi HP |
| G4 | Feature/API test | ✅ Lolos | Semua 54 route API diuji: status, struktur respons standar, validasi |
| G5 | Isolasi tenant | ✅ Lolos | 17 kombinasi endpoint lintas tenant → 404 + audit; uji RLS langsung (baca, sisip, tanpa konteks); cek RLS aktif di semua tabel `company_id` |
| G6 | Hak akses | ✅ Lolos | Matriks role (admin, brand mgr, outlet mgr, kasir, dapur, finance, gudang), cakupan outlet/brand, anti-eskalasi role |
| G7 | Kalkulasi | ➖ Tidak relevan | Kalkulator harga/pajak & fixture PHP–Dart dimulai Tahap 2; `Money` sudah diuji |
| G8 | Offline & sinkronisasi | ➖ Tidak relevan | Endpoint sync dibuat di Tahap 3; heartbeat & token perangkat sudah diuji |
| G9 | Database | ✅ Lolos | `migrate:fresh --seed`, `migrate:rollback --step=3` + migrate ulang, rollback penuh batch; RLS aktif (dicek `pg_class`) |
| G10 | E2E / UI | ✅ Lolos | Playwright (Chromium): login gagal, alur pemilik (brand→outlet→perangkat→staf→audit), cakupan manajer outlet, mode gelap — 4/4 lulus dua kali berturut-turut |
| G11 | Aksesibilitas | ✅ Lolos | axe-core WCAG 2.1 A/AA tanpa pelanggaran serius/kritis di 9 halaman (terang & gelap) |
| G12 | Desain natural | ✅ Lolos | Cek §5.3 semua "tidak"; warna hanya dari token; label tombol spesifik ("Simpan Brand", "Daftarkan & Buat Kode Pairing") |
| G13 | Kinerja | ✅ Lolos | `preventLazyLoading` aktif di test. p95 (server PHP bawaan, data seed): daftar 50–65 ms, heartbeat 34 ms, login PIN 129 ms, otorisasi supervisor 124 ms |
| G14 | Keamanan | ⚠️ Sebagian | `npm audit` 0 kerentanan. Review keamanan independen: 2 High + 2 Medium ditemukan lalu diperbaiki & diverifikasi. **`composer audit` TIDAK DIJALANKAN secara bermakna**: basis data advisori Packagist/GitHub tidak dapat diakses dari lingkungan build |
| G15 | Kebutuhan | ✅ Lolos | Kriteria penerimaan yang relevan (isolasi tenant §10.1, void perlu otorisasi — bagian otorisasi) terpenuhi |
| G16 | Regresi | ✅ Lolos | Seluruh suite |

Ringkasan test: **170 lulus, 0 gagal, 0 dilewati** (725 asersi, ±37 dtk paralel 4 proses) + E2E 4 lulus (±36 dtk).

Pemeriksaan lain yang **TIDAK DIJALANKAN**:
- Cakupan kode (NFR-MNT-02): driver pcov/xdebug tidak tersedia di lingkungan build.
- Uji di PHP 8.5 Windows (`php85`) dan PostgreSQL 15 milik user: build & uji memakai PHP 8.4 + PostgreSQL 16 di lingkungan terpisah. Perintah verifikasi ada di README.

## Temuan & Perbaikan
| Siklus | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 1 | High | Cache permission spatie dibangun di bawah RLS tenant lain → izin company lain gagal | `TenantAwarePermissionRegistrar` memuat cache dalam mode sistem |
| 1 | High | Mode tenant tidak dikembalikan ke kondisi semula setelah `runAsTenant` di console | `restore()` mengembalikan status role koneksi sebelumnya |
| 1 | High | Audit log platform ditulis di bawah role RLS → 500 | Log tanpa company selalu ditulis dalam mode sistem |
| 1 | High | Widget dashboard tidak terdaftar → Livewire 419 | `discoverWidgets` di panel |
| 1 | High | Daftar Outlet/Perangkat/Brand di back-office tidak menerapkan cakupan manajer (ditemukan E2E) | `getEloquentQuery` memakai `AccessScope`; test regresi Livewire |
| 1 | Medium | Verifikasi PIN supervisor O(n) bcrypt; otorisasi p95 308 ms | Cost PIN dapat dikonfigurasi (10), pencarian per supervisor |
| 1 | Medium | Kontras tombol/badge/breadcrumb/placeholder < 4,5:1; select Choices.js & tab Filament melanggar ARIA; tombol ikon tanpa nama | Token warna disesuaikan, select native, CheckboxList, id tab, `aria-label` |
| 1 | Medium | Avatar memakai layanan luar (ui-avatars) | Avatar inisial SVG lokal |
| 2 | High | Pesan "PIN sudah dipakai" membocorkan PIN staf lain (termasuk pemilik) | PIN tidak wajib unik; otorisasi memakai `supervisor_id` + PIN (ADR 0002) |
| 2 | High | `pos/authorize` bisa di-brute force tanpa penguncian | Kunci per supervisor, batas 10 kegagalan/15 mnt per perangkat, pesan penolakan seragam |
| 2 | Medium | Company lain bisa mengubah nama/mencabut token akun global | Undangan harus diterima; nama hanya diubah pemilik identitas; `sessions_revoked_at` per company |
| 2 | Medium | `role.manage` tanpa batas → eskalasi hak | `GrantGuard` (izin kewenangan, role bawaan hanya oleh pemilik, batas diskon) |
| 2 | Low | Pencarian dengan `\`, log lintas tenant menyebut company korban, mode baca-saja tidak berlaku di Filament, brand outlet tidak dibatasi | `Like::escape`, log dipisah (tenant/platform), `WritableCompany` di policy, pembatasan brand + cek server |
| 3 | Medium | Aturan anti-eskalasi memblokir admin menambah kasir | Hanya izin kewenangan (`PRIVILEGED`) yang dibatasi; izin tercakup (`IMPLIES`) |
| 3 | Medium | Input form hilang saat validasi `live(onBlur)` (flaky E2E) | Validasi memakai `mutateStateForValidationUsing` tanpa round-trip |

## Sisa Catatan (Low / pertanyaan untuk user)
- **Perlu konfirmasi PO:** otorisasi supervisor kini "pilih nama + PIN" (ADR 0002). Transaksi standar kasir tidak berubah.
- Admin company tidak bisa memberi role **Finance** (butuh izin akuntansi yang tidak dimiliki admin); hanya pemilik.
- Kasir dapat sengaja mengunci PIN supervisor atau menahan perangkat (DoS ringan) — perlu notifikasi ke owner saat modul notifikasi dibuat.
- Pesan 423 saat akun terkunci menandakan akun ada; standar umum, dibiarkan.
- Audit log manajer outlet menampilkan aktivitas staf yang juga ditempatkan di outlet lain.
- `composer.lock` berisi sumber git karena Packagist tidak dapat diakses saat build; jalankan `composer update --lock` & `composer audit` di mesin dengan akses Packagist.
- Belum ada: 2FA (FR-AUTH-02), impersonate (FR-TEN-10), tagihan (FR-TEN-07), verifikasi nomor HP, wizard onboarding lengkap, pengaturan printer (FR-DEV-08), force update (FR-DEV-09) — dijadwalkan pada tahap berikutnya.
- Belum di-commit ke git (menunggu instruksi).
