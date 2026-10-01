<?php

namespace App\Modules\Shared\Application;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Penyimpanan **bukti dokumen** milik tenant: foto nota, bukti transfer, kontrak (ACC-05, DOC-01).
 *
 * Sengaja terpisah dari `MediaStore`, yang menyimpan foto menu & logo. Keduanya kelihatan mirip,
 * tetapi tiga hal membuatnya berbeda secara mendasar:
 *
 * 1. **Tidak disajikan publik.** Foto menu memang untuk dilihat siapa saja di layar kasir; foto
 *    nota memuat nama pihak, nominal, kadang NPWP. Berkas di sini hanya boleh diunduh lewat rute
 *    berotentikasi yang memeriksa entitas dan izin.
 * 2. **Tidak diperkecil dan tidak diubah formatnya.** Bukti yang dipotong resolusinya bisa jadi
 *    tidak terbaca angkanya; dan PDF memang bukan gambar.
 * 3. **Disimpan per entitas.** Jalurnya memuat id company (`bukti/<companyId>/<ulid>.<ext>`),
 *    sehingga berkas satu entitas tidak pernah bercampur di folder yang sama dengan entitas lain —
 *    berguna saat suatu hari harus dipindahkan atau dihapus massal.
 *
 * Nama berkasnya tetap acak (ULID) dan nama asli dari komputer pengunggah disimpan di basis data,
 * bukan di nama berkas: nama asli sering memuat informasi yang tidak perlu ikut ke disk.
 */
class AttachmentStore
{
    public const FOLDER = 'bukti';

    /** Jenis yang diterima: gambar hasil foto nota, dan PDF untuk faktur/kontrak. */
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** Jalur sah: `bukti/<uuid company>/<ulid>.<ext>` — tanpa `..`, tanpa garis miring lain. */
    public const PATH_PATTERN = '/^bukti\/[0-9a-f-]{36}\/[0-9a-z]{26}\.(jpg|jpeg|png|webp|pdf)$/';

    public function maxKb(): int
    {
        return (int) config('fnb.attachments.max_upload_kb', 10240);
    }

    /**
     * Simpan berkas bukti, kembalikan jalur relatifnya.
     *
     * @throws \InvalidArgumentException bila jenis berkasnya tidak diterima
     */
    public function put(UploadedFile $file, string $companyId): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) ($file->getMimeType() ?? 'application/octet-stream');
        if (! in_array($ext, self::EXTENSIONS, true) || ! in_array($mime, self::MIMES, true)) {
            throw new \InvalidArgumentException('Hanya gambar (JPG/PNG/WebP) dan PDF yang dapat dilampirkan.');
        }

        $path = self::FOLDER.'/'.$companyId.'/'.strtolower((string) Str::ulid()).'.'.$ext;
        // 'private': bahkan bila disknya kelak S3, berkasnya tidak boleh terbaca tanpa tanda tangan.
        $this->disk()->put($path, $file->get(), 'private');

        return $path;
    }

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

    /** Company pemilik berkas menurut jalurnya — dipakai penjagaan sebelum mengunduh. */
    public function ownerCompanyId(string $path): ?string
    {
        return $this->isValidPath($path) ? explode('/', $path)[1] : null;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('fnb.attachments.disk', 'attachments'));
    }
}
