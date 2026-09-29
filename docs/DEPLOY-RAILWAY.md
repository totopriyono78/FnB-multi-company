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

---

## 7. Deploy rilis 27–28 September 2026 (branding, layar pelanggan, QRIS AINO)

Rilis ini menambah satu **tabel baru**, satu **dependensi baru**, satu **rute baru**, dan mengubah
**nilai bawaan konfigurasi**. Urutan di bawah dibuat supaya tidak ada langkah yang bisa dilewati
diam-diam.

### 7.1 Sebelum push — tiga hal yang wajib diperiksa

**a. `RAILPACK_SKIP_MIGRATIONS` harus KOSONG untuk rilis ini.**
Ada migrasi baru `2026_09_27_000100_create_payment_gateway_accounts.php`. Bila variabel itu
bernilai `1` dari deploy sebelumnya, migrasinya dilewati, tabelnya tidak terbentuk, dan layar
**Keamanan → Payment Gateway** akan galat 500 begitu dibuka. Hapus dulu variabelnya, deploy, baru
pasang lagi bila memang tidak ingin migrate tiap rilis.

**b. Dependensi baru sudah terkunci dengan benar.**
`bacon/bacon-qr-code v3.1.1` (perender QR) sudah ada di `composer.lock`, dan
`platform-overrides` masih `php 8.4.1` — jadi build Railpack tidak akan mengulang kegagalan §1.
Tidak ada ekstensi PHP baru yang dibutuhkan. Tidak ada yang perlu dikerjakan; ini hanya
pemeriksaan agar tidak kaget.

**c. Jam deploy.**
`db:seed` ikut berjalan pada tiap rilis, dan `DemoReportSeeder` membuka shift demo pukul 06:45
waktu outlet. Sebelum perbaikan 28 Sep 2026, deploy antara **00:00–06:45 WIB** membuat seeder
menolak dengan `CLOCK_AHEAD` dan **seluruh rilis gagal**. Sekarang jam bukanya dipepet ke waktu
sekarang sehingga aman kapan pun — tetapi bila Anda men-deploy dari repositori lama, hindari
jendela itu.

### 7.2 Push

```bash
cd D:\DEVELOPMENT\FB_Multi_Company
git status                 # pastikan hanya perubahan yang Anda maksud
git add -A
git commit -m "Layar pelanggan, cetak slip QRIS, dan perbaikan pembacaan status AINO"
git push origin main
```

Railway membangun sendiri begitu `main` bergerak. Yang dikerjakannya: `composer install`,
`npm ci` + `npm run build`, `php artisan migrate --force` + `db:seed`, `storage:link`,
`php artisan optimize`. **Tidak ada perintah artisan yang perlu Anda jalankan manual di server**
selama migrasi tidak dilewati.

### 7.3 Variabel lingkungan yang perlu ditambah/diperiksa di Railway

```
PAYMENT_GATEWAY=aino
APP_URL=https://<layanan>.up.railway.app     # harus benar: alamat callback dirakit dari sini
```

Opsional:

```
PAYMENT_INTENT_TTL=5              # bawaannya kini 5 menit; isi hanya bila ingin lain
PAYMENT_AINO_CALLBACK_URL=        # biarkan kosong; dirakit dari APP_URL (66 karakter, muat di batas 100 AINO)
```

### 7.4 Kredensial merchant: WAJIB diisi ulang lewat back-office server

`secret_key` disimpan **terenkripsi dengan `APP_KEY`**, dan `APP_KEY` server berbeda dari mesin
pengembang. Menyalin baris `payment_gateway_accounts` dari basis data lokal ke server akan
menghasilkan kegagalan dekripsi, bukan kredensial yang bekerja.

Jadi setelah deploy: buka **Keamanan → Payment Gateway** di server, isi merchant code & secret
key, pilih lingkungan **Sandbox**. Butuh izin `company.manage`.

### 7.5 Yang berubah perilakunya di server — periksa setelah deploy

1. `/up` → 200.
2. `/pos` → layar kasir; menu **Atur → Layar pelanggan** → tombol membuka `/pos/display`.
3. `/pos/display` → menampilkan "Selamat datang" (tanpa login; halaman ini tidak memanggil
   server sama sekali — lihat catatan di berkasnya).
4. `/admin` → footer memuat "© 2026 PT. Gamatechno Indonesia", judul tab "… - FnB Cloud - Gamatechno".
5. **Callback AINO kini benar-benar bekerja.** Di komputer pengembang, AINO tidak bisa menghubungi
   `127.0.0.1`, sehingga polling adalah satu-satunya jalur status. Di Railway alamatnya publik,
   jadi Finish Notify sampai ke `/api/v1/webhooks/payment/aino` dan menjadi jaring kedua di
   samping polling. Isinya tetap diperlakukan sebagai pemicu, bukan bukti (§3 butir 1 dokumen QRIS).

### 7.6 Peringatan uang

Sandbox AINO memakai rel QRIS sungguhan — **transaksi uji memindahkan uang nyata**, dan di
server callback-nya ikut hidup. Pakai nominal sekecil mungkin. Pembulatan outlet bisa dimatikan
(Outlet → Harga → Pembulatan: "Tanpa pembulatan") untuk menguji nominal kecil, tetapi totalnya
harus tetap rupiah bulat: Rp1 + PB1 10% = Rp1,10 akan ditolak dengan `AMOUNT_NOT_WHOLE_RUPIAH`.
Nominal uji terkecil yang aman: harga item Rp10 → total Rp11.

### 7.7 Build gagal: `composer install` time-out saat `git clone` (28 Sep 2026)

Gejalanya di log build:

```
- Syncing pestphp/pest-plugin (v3.0.0) into cache
- Syncing voku/portable-ascii (2.1.1) into cache
  … 162 baris serupa …
The process "'git' 'clone' '--mirror' '--' 'https://github.com/phpstan/phpstan.git' …"
  exceeded the timeout of 300 seconds.
```

**Kata kuncinya "Syncing … into cache", bukan "Downloading".** Itu artinya composer memasang
tiap paket dari **source** (git clone satu per satu), bukan dari arsip zip. Untuk 162 paket itu
lambat sekali, dan `phpstan` — yang riwayat git-nya besar — menembus batas 300 detik.

Penyebabnya bukan Railway, melainkan **`composer.lock` yang tidak punya satu pun entri `dist`**.
Periksa sendiri:

```bash
php -r "$l=json_decode(file_get_contents('backend/composer.lock'),true);
$a=array_merge($l['packages'],$l['packages-dev']);
$t=array_filter($a,fn($p)=>empty($p['dist']['url']));
echo count($t),' dari ',count($a),\" paket tanpa dist\n\";"
```

Lock yang sehat punya **dist DAN source** untuk tiap paket; composer memakai dist (zip, cepat)
dan hanya jatuh ke source bila dist tidak ada. Lock tanpa dist biasanya lahir dari `composer
update`/`install` yang dijalankan dengan `--prefer-source`, atau di jaringan yang memblokir
`api.github.com` / `codeload.github.com` sehingga composer tidak bisa mencatat alamat zip-nya.

**Penawar cepat (sudah dipasang di repositori):** `config.process-timeout: 1800` di
`backend/composer.json`. Build tetap lambat karena tetap meng-clone, tetapi tidak lagi
digugurkan di detik ke-300. Nilai ini tidak mengubah `content-hash`, jadi `composer.lock`
tidak ikut basi (`composer validate` tetap bersih). Alternatif tanpa menyentuh kode: tambahkan
variabel Railway `COMPOSER_PROCESS_TIMEOUT=1800`.

**Penawar sesungguhnya — kembalikan `dist` ke lock, dan ini harus dikerjakan di mesin yang
internetnya normal** (tidak bisa dari ruang kerja cloud yang egress-nya dibatasi):

```bash
cd D:\DEVELOPMENT\FB_Multi_Company\backend
composer diagnose                      # pastikan api.github.com terjangkau & tidak kena rate limit
composer update --prefer-dist          # tulis ulang lock, kali ini dengan dist
```

Bila `composer diagnose` menyebut rate limit GitHub, pasang token dulu — tanpa itu composer
tidak bisa membaca alamat zip dan akan kembali menulis lock tanpa dist:

```bash
composer config --global github-oauth.github.com <token>
```

Sesudahnya **periksa dua hal sebelum commit**:

1. Perintah pemeriksa di atas harus melaporkan `0 dari 162 paket tanpa dist`.
2. `git diff backend/composer.lock` — bila ada paket yang ikut naik versi, itu keputusan
   tersendiri: jalankan `composer install` lalu gerbang mutu (Pint, Larastan, Pest, Playwright)
   sebelum mendorongnya ke server.

Setelah dist kembali, build Railway turun dari belasan menit menjadi satu–dua menit, dan batas
time-out 1800 detik itu tidak lagi terpakai.
