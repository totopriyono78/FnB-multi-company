<?php

namespace App\Modules\Shared\Application;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Penyimpanan gambar milik tenant: foto menu (FR-MENU-02) dan logo brand.
 *
 * Tiga keputusan yang perlu diingat saat membaca kode ini:
 *
 * 1. **Selalu diperkecil di server.** Foto dari ponsel berukuran 3–5 MB; kartu menu di kasir
 *    hanya ±260 px. Outlet yang koneksinya putus-putus tidak boleh menunggu berkas sebesar itu
 *    setiap kali katalog dimuat. Pengecilan di sisi browser (FilePond) hanya pelengkap — yang
 *    menentukan tetap pengecilan di sini, karena API juga menerima unggahan.
 * 2. **Nama berkas acak.** Berkas disajikan tanpa login supaya bisa dipakai di tag `<img>` layar
 *    kasir. Nama acak (ULID) membuat berkas milik company lain tidak bisa ditebak dari URL,
 *    dan nama asli dari komputer pengunggah tidak ikut bocor.
 * 3. **Jalur tersimpan selalu relatif** (mis. `menu/01J….jpg`). URL-nya dibentuk saat dibaca,
 *    jadi pindah dari disk lokal ke object storage tidak perlu mengubah satu baris pun data.
 */
class MediaStore
{
    public const FOLDERS = ['menu', 'logo'];

    /** Jalur yang sah: `<folder>/<nama>.<ext>` — tanpa `..`, tanpa garis miring lain. */
    public const PATH_PATTERN = '/^(menu|logo)\/[A-Za-z0-9][A-Za-z0-9_-]{0,80}\.(jpg|jpeg|png|webp)$/';

    /**
     * Simpan gambar yang diunggah, kembalikan jalur relatifnya.
     *
     * @param  'menu'|'logo'  $folder
     */
    public function put(UploadedFile $file, string $folder): string
    {
        if (! in_array($folder, self::FOLDERS, true)) {
            throw new \InvalidArgumentException("Folder media tidak dikenal: {$folder}.");
        }

        [$width, $height] = $folder === 'logo'
            ? [(int) config('fnb.media.logo_width'), (int) config('fnb.media.logo_height')]
            : [(int) config('fnb.media.item_width'), (int) config('fnb.media.item_height')];

        // Logo sering punya latar transparan, jadi PNG dipertahankan; foto menu selalu JPEG.
        $asPng = $folder === 'logo' && $this->mime($file) === 'image/png';
        $binary = $this->resize($file, $width, $height, $asPng);
        $path = $folder.'/'.strtolower((string) Str::ulid()).($asPng ? '.png' : '.jpg');

        $this->disk()->put($path, $binary, 'public');

        return $path;
    }

    /**
     * Hapus berkas lama. Diam saja bila jalurnya kosong atau tidak sah — ini bukan alur kritis.
     *
     * Hanya dipanggil **setelah** penyimpanan berhasil. Menghapus lebih dulu berarti, bila
     * penyimpanan gagal, baris yang masih menunjuk berkas itu berubah jadi gambar rusak;
     * berkas yatim jauh lebih ringan akibatnya.
     */
    public function forget(?string $path): void
    {
        if ($path === null || $path === '' || ! $this->isValidPath($path)) {
            return;
        }

        $this->disk()->delete($path);
    }

    public function isValidPath(string $path): bool
    {
        return (bool) preg_match(self::PATH_PATTERN, $path);
    }

    /** URL yang bisa dipasang langsung di `<img src>`; null bila belum ada gambarnya. */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '' || ! $this->isValidPath($path)) {
            return null;
        }

        return $this->disk()->url($path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('fnb.media.disk', 'media'));
    }

    public function diskName(): string
    {
        return (string) config('fnb.media.disk', 'media');
    }

    /**
     * Perkecil agar muat di dalam kotak $maxW x $maxH tanpa mengubah perbandingan sisi, lalu
     * encode ulang. Gambar yang sudah kecil tetap di-encode ulang: itu membuang metadata EXIF —
     * termasuk titik koordinat yang kerap menempel di foto ponsel — dari berkas yang nantinya
     * dapat diakses tanpa login.
     */
    private function resize(UploadedFile $file, int $maxW, int $maxH, bool $asPng): string
    {
        $sumber = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($sumber === false) {
            throw ValidationException::withMessages([
                'image' => 'Berkas ini bukan gambar yang bisa dibaca. Gunakan JPG, PNG, atau WebP.',
            ]);
        }

        $w = imagesx($sumber);
        $h = imagesy($sumber);
        $skala = min($maxW / $w, $maxH / $h, 1);
        $targetW = max(1, (int) round($w * $skala));
        $targetH = max(1, (int) round($h * $skala));

        $hasil = imagecreatetruecolor($targetW, $targetH);
        if ($asPng) {
            imagealphablending($hasil, false);
            imagesavealpha($hasil, true);
        } else {
            // JPEG tidak punya kanal alfa: bagian transparan jadi hitam bila tidak dialasi putih.
            imagefilledrectangle($hasil, 0, 0, $targetW, $targetH, (int) imagecolorallocate($hasil, 255, 255, 255));
        }
        imagecopyresampled($hasil, $sumber, 0, 0, 0, 0, $targetW, $targetH, $w, $h);

        ob_start();
        $asPng ? imagepng($hasil, null, 6) : imagejpeg($hasil, null, 82);
        $binary = (string) ob_get_clean();

        imagedestroy($sumber);
        imagedestroy($hasil);

        return $binary;
    }

    private function mime(UploadedFile $file): string
    {
        return (string) ($file->getMimeType() ?? '');
    }
}
