# Deploy ke Railway

Ditulis 21 September 2026 · builder Railway saat ini: **Railpack** (pengganti Nixpacks)

---

## 1. Mengapa build gagal sebelumnya

Log build menyebut tiga hal, semuanya berakar pada satu penyebab: **Railpack menentukan
versi PHP dan daftar ekstensi dari `backend/composer.json`**, bukan dari mesin Anda.

| Pesan di log | Sebab |
|---|---|
| `symfony/* requires php >=8.4.1 -> your php version (8.3.33)` | `composer.json` menulis `"php": "^8.3"`, sehingga Railpack memasang PHP 8.3 — padahal `composer.lock` dikunci di mesin Anda yang memakai PHP 8.5, jadi ikut menarik paket khusus PHP 8.4+. |
| `filament/support requires ext-intl` | `ext-intl` hanya kebutuhan tidak langsung (dari Filament), tidak tertulis di `composer.json`, jadi Railpack tidak memasangnya. |
| `openspout/openspout requires ext-zip` | sama seperti di atas. |

Jadi masalahnya bukan pada kode, melainkan pada **kontrak antara `composer.json` dan builder**.

---

## 2. Yang sudah diperbaiki di repositori

**`backend/composer.json`**

```jsonc
"require": {
    "php": "^8.4",          // sebelumnya ^8.3 -> Railpack kini memasang PHP 8.4
    "ext-intl": "*",        // dipasang otomatis oleh builder
    "ext-pdo_pgsql": "*",   // driver PostgreSQL, wajib saat runtime
    "ext-zip": "*",
    ...
},
"config": {
    "platform": { "php": "8.4.1" },   // kunci: composer update selalu menyelesaikan
    ...                               // dependensi untuk PHP 8.4, bukan PHP 8.5 lokal
}
```

`config.platform.php` adalah **pencegah kambuhnya masalah**. Mesin Anda memakai PHP 8.5;
tanpa baris ini, `composer update` berikutnya akan mengunci paket yang hanya jalan di PHP 8.5
dan build Railway gagal lagi dengan pesan yang persis sama.

**`backend/composer.lock`** — di-regenerasi (`composer update --lock`). Tidak ada paket yang
berubah versi; hanya `content-hash` dan `platform-overrides` yang diperbarui.

**`backend/bootstrap/app.php`** — `trustProxies(at: '*')`. Railway menghentikan TLS di proxy
tepi; tanpa ini Laravel menganggap request datang sebagai `http://`, sehingga Filament dan
Livewire memuat aset dengan skema salah (mixed content, halaman admin tampak rusak) dan IP
klien tercatat sebagai IP proxy. Kontainer hanya bisa dihubungi lewat proxy Railway, jadi
mempercayai `X-Forwarded-*` aman di sana; di lokal tidak berpengaruh karena header itu tidak ada.

**`backend/package.json`** — `"engines": { "node": ">=20" }` agar builder memilih Node yang
sanggup menjalankan Vite 7.

---

## 3. Pengaturan layanan di Railway

- **Root Directory**: `backend` (repositori ini berisi `backend/`, `docs/`, `shared/`).
- **Builder**: Railpack (default). Tidak perlu Dockerfile maupun `railpack.json`.
- Tambahkan layanan **PostgreSQL** di project yang sama.

Yang dikerjakan Railpack secara otomatis: `composer install`, `npm ci` + `npm run build`,
`php artisan migrate --force` **beserta `db:seed`**, `storage:link`, `php artisan optimize`,
lalu menjalankan FrankenPHP dengan document root `backend/public`.

---

## 4. Variabel lingkungan

Wajib:

```
APP_NAME=FnB Cloud
APP_ENV=staging
APP_KEY=base64:...            # hasil: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://<nama-layanan>.up.railway.app

APP_TIMEZONE=UTC
APP_DISPLAY_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
APP_FALLBACK_LOCALE=en

DB_CONNECTION=pgsql
DB_HOST=${{Postgres.PGHOST}}
DB_PORT=${{Postgres.PGPORT}}
DB_DATABASE=${{Postgres.PGDATABASE}}
DB_USERNAME=${{Postgres.PGUSER}}
DB_PASSWORD=${{Postgres.PGPASSWORD}}
DB_RLS_ROLE=fnb_app

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

Opsional:

```
RAILPACK_PHP_EXTENSIONS=gd     # bila PDF perlu merender gambar/logo
RAILPACK_SKIP_MIGRATIONS=1     # setelah deploy pertama, bila tak ingin migrate tiap deploy
FNB_POS_WEB=true
FNB_PROTOTYPE_ACCOUNTING=true
PAYMENT_GATEWAY=sandbox
```

### `APP_ENV` menentukan ada-tidaknya data demo

`DatabaseSeeder` hanya menjalankan `DemoSeeder` bila `APP_ENV` **bukan** `production`.

- `APP_ENV=staging` → basis data terisi PT Kopi Nusantara Sejahtera, outlet, menu, staf, dan
  perangkat kasir. Cocok untuk memperagakan sistem ke calon klien.
- `APP_ENV=production` → basis data bersih; Anda harus mendaftar perusahaan baru lewat halaman
  registrasi. Tidak ada akun bawaan, jadi siapkan ini sebelum demo.

### Catatan keamanan

`FNB_DEMO_LOGIN=true` menampilkan daftar akun demo **beserta PIN kasir** di halaman login.
URL Railway bersifat publik dan dapat ditemukan, jadi biarkan `false` dan bagikan kredensial
lewat jalur lain, kecuali basis datanya memang hanya berisi data contoh.

Tetap berlaku juga di Railway: `DB_RLS_ROLE` harus terisi agar pemisahan data antar badan usaha
di lapisan database (RLS) aktif. Migrasi `0001_01_01_000000_create_rls_role` membuat sendiri role
`fnb_app` — pengguna `postgres` bawaan Railway punya hak yang cukup untuk itu.

---

## 5. Setelah deploy

1. Buka `https://<layanan>.up.railway.app/up` — health check Laravel, harus `200`.
2. Buka `/admin` lalu masuk; `/pos` untuk layar kasir.
3. Bila halaman admin tampil tanpa gaya, jalankan ulang deploy: artinya `npm run build` tidak
   menghasilkan `public/build/manifest.json`.

Belum termasuk dan perlu layanan terpisah bila nanti dibutuhkan: **queue worker**
(`php artisan queue:work`) dan **scheduler** (`php artisan schedule:work`) untuk email laporan
terjadwal. Untuk peragaan, keduanya belum diperlukan.

---

## 6. Aturan yang menjaga agar tidak kambuh

- Jangan menjalankan `composer update` tanpa memperhatikan `config.platform.php`. Bila kelak
  ingin pindah ke PHP 8.5, ubah **tiga** tempat bersamaan: `require.php`, `config.platform.php`,
  lalu `composer update`, dan pastikan Railpack sudah mendukung versi itu.
- Ekstensi PHP baru yang dipakai paket apa pun harus dituliskan di `composer.json`
  (`"ext-nama": "*"`), bukan diasumsikan ada di image builder.
