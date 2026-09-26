<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Application\MediaStore;
use App\Modules\Shared\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menyajikan foto menu & logo brand.
 *
 * Sengaja tanpa autentikasi: layar kasir memasangnya lewat tag `<img>`, yang tidak dapat
 * mengirim token perangkat. Pengamanannya ada pada nama berkas acak (ULID) — tidak bisa ditebak,
 * dan tidak ada endpoint yang mendaftar isi folder. Isinya pun bukan data sensitif: foto menu
 * dan logo memang ditunjukkan ke tamu.
 *
 * Rute ini menggantikan symlink `public/storage`, yang butuh hak administrator di Windows dan
 * hilang setiap kali kontainer PaaS dibangun ulang.
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaStore $media) {}

    public function show(string $folder, string $file): Response|StreamedResponse|RedirectResponse
    {
        $path = $folder.'/'.$file;
        // Pola inilah yang menutup jalan keluar folder (`..`, garis miring tambahan).
        abort_unless($this->media->isValidPath($path), 404);

        $disk = $this->media->disk();
        abort_unless($disk->exists($path), 404);

        // Object storage menyajikan berkasnya sendiri; jangan salurkan lewat PHP.
        if ($this->media->diskName() !== 'media') {
            return redirect()->away((string) $disk->url($path));
        }

        return $disk->response($path, null, [
            // Nama berkas tidak pernah dipakai ulang, jadi aman di-cache lama.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
