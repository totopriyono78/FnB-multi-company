# ADR 0001 — Multi-tenancy: global scope + PostgreSQL RLS (tanpa stancl/tenancy)

- Status: Diterima (15 Sep 2026, keputusan user untuk Isu Terbuka SRS §12.3 no. 3)
- Kebutuhan: NFR-SEC-01, NFR-SCL-06, CLAUDE.md §4.1

## Konteks
Kebocoran data antar tenant adalah risiko tertinggi. SRS meminta isolasi berlapis di aplikasi dan database.

## Keputusan
1. Satu database, semua tabel milik tenant punya `company_id UUID NOT NULL` dan indeks diawali `company_id`.
2. Lapisan aplikasi: trait `BelongsToCompany` (global scope, isi otomatis `company_id`, larang pindah company,
   route binding yang mencatat percobaan akses lintas tenant).
3. Lapisan database: RLS dengan kebijakan `company_id = current_setting('app.current_company_id')`.
   Aplikasi berpindah ke role `fnb_app` (NOBYPASSRLS) lewat `SET ROLE` saat melayani request tenant.
   User koneksi (mis. `postgres`) tetap dipakai untuk migrasi.
4. `TenantContext` punya tiga mode: *none* (default deny), *tenant*, dan *system* (lintas tenant, hanya untuk
   proses internal eksplisit seperti login dan pairing).
5. Setiap request API diawali mode *none* (`TenantBoundary`) dan diakhiri `RESET ROLE`.

## Konsekuensi
- Kode yang lupa memasang konteks tenant akan melihat data kosong, bukan data tenant lain.
- Cache permission spatie dibangun dalam mode sistem (`TenantAwarePermissionRegistrar`).
- Queue job (Tahap 3) wajib memasang konteks tenant secara eksplisit.
- Tenant enterprise tetap bisa dipindah ke database terpisah karena tidak ada ketergantungan antar tenant.
