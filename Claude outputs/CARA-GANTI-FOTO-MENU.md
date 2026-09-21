# Foto produk untuk layar POS

Letakkan foto menu di folder ini: **`backend/public/img/pos/`**
(di komputer Anda: `D:\DEVELOPMENT\FB_Multi_Company\backend\public\img\pos\`)

## Aturan

- **Nama berkas harus sama persis** dengan daftar di bawah (huruf kecil semua, tanpa spasi).
- Format yang diterima: `.jpg`, `.jpeg`, `.png`, `.webp` — cukup salah satu. Urutan pencarian: jpg → jpeg → png → webp.
- Ukuran ideal **800 x 600 piksel** (perbandingan 4:3), maksimal ±300 KB per foto agar ringan.
- Foto dipotong otomatis (`object-fit: cover`) — letakkan objek utama di tengah.
- Menu yang belum ada fotonya tampil sebagai kotak abu-abu berisi inisial. Tidak perlu semuanya diisi sekaligus.
- Setelah menyalin foto, cukup **muat ulang halaman** (Ctrl+F5). Tidak perlu restart server dan tidak perlu build apa pun.

## Daftar nama berkas

### Makanan

| Menu | Nama berkas foto |
|---|---|
| Nasi Goreng Kampung | `nasi-goreng-kampung.jpg` |
| Ayam Bakar Taliwang | `ayam-bakar-taliwang.jpg` |
| Iga Bakar Madu | `iga-bakar-madu.jpg` |
| Sop Buntut | `sop-buntut.jpg` |
| Gurame Asam Manis | `gurame-asam-manis.jpg` |
| Mie Goreng Seafood | `mie-goreng-seafood.jpg` |
| Cap Cay Kuah | `cap-cay-kuah.jpg` |
| Sate Ayam (10 tusuk) | `sate-ayam-10-tusuk.jpg` |
| Nasi Putih | `nasi-putih.jpg` |

### Minuman

| Menu | Nama berkas foto |
|---|---|
| Es Kopi Susu Gula Aren | `es-kopi-susu-gula-aren.jpg` |
| Americano | `americano.jpg` |
| Teh Tarik | `teh-tarik.jpg` |
| Jus Alpukat | `jus-alpukat.jpg` |
| Es Jeruk Peras | `es-jeruk-peras.jpg` |
| Air Mineral 600 ml | `air-mineral-600-ml.jpg` |
| Lemon Tea Panas | `lemon-tea-panas.jpg` |

### Paket

| Menu | Nama berkas foto |
|---|---|
| Paket Hemat Nasi + Ayam + Teh | `paket-hemat-nasi-dan-ayam-dan-teh.jpg` |
| Paket Keluarga (4 orang) | `paket-keluarga-4-orang.jpg` |
| Paket Berdua Iga + 2 Minum | `paket-berdua-iga-dan-2-minum.jpg` |
| Paket Sarapan 07-10 | `paket-sarapan-07-10.jpg` |

### Dessert

| Menu | Nama berkas foto |
|---|---|
| Pisang Goreng Keju | `pisang-goreng-keju.jpg` |
| Es Campur | `es-campur.jpg` |
| Puding Cokelat | `puding-cokelat.jpg` |
| Klapertaart | `klapertaart.jpg` |

### Tambahan

| Menu | Nama berkas foto |
|---|---|
| Sambal Matah | `sambal-matah.jpg` |
| Kerupuk Udang | `kerupuk-udang.jpg` |
| Telur Ceplok | `telur-ceplok.jpg` |
| Extra Nasi | `extra-nasi.jpg` |
| Kemasan Bawa Pulang | `kemasan-bawa-pulang.jpg` |

## Menambah atau mengubah menu

Menu contoh diatur di `backend/app/Prototype/DemoPos.php`.
Nama berkas foto dibuat otomatis dari nama menu: huruf kecil, spasi dan tanda baca menjadi tanda hubung.
Contoh: "Es Kopi Susu Gula Aren" → `es-kopi-susu-gula-aren.jpg`.