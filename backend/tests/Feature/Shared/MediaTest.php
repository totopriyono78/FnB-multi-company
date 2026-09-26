<?php

use App\Modules\Shared\Application\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Penyimpanan & penyajian foto menu dan logo brand (FR-MENU-02).
 *
 * Yang dijaga di sini: berkas selalu diperkecil, jalurnya tidak bisa dipakai keluar folder,
 * dan rute penyajiannya tidak menyajikan apa pun di luar folder media.
 */
beforeEach(function () {
    Storage::fake('media');
    $this->media = app(MediaStore::class);
});

/** Membuat berkas gambar sungguhan (bukan UploadedFile::fake yang isinya bukan gambar). */
function gambar(int $w, int $h, string $ext = 'jpg'): UploadedFile
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 10, 120, 200));
    $path = tempnam(sys_get_temp_dir(), 'uji').'.'.$ext;
    $ext === 'png' ? imagepng($im, $path) : imagejpeg($im, $path);
    imagedestroy($im);

    return new UploadedFile($path, 'foto.'.$ext, $ext === 'png' ? 'image/png' : 'image/jpeg', null, true);
}

it('memperkecil foto menu ke batas 800x600 dan menyimpannya dengan nama acak', function () {
    $path = $this->media->put(gambar(2400, 1800), 'menu');

    expect($path)->toStartWith('menu/')->toEndWith('.jpg')
        // Nama berkas tidak boleh membawa nama asli dari komputer pengunggah.
        ->not->toContain('foto');
    Storage::disk('media')->assertExists($path);

    $ukuran = getimagesizefromstring((string) Storage::disk('media')->get($path));
    expect($ukuran[0])->toBe(800)->and($ukuran[1])->toBe(600);
});

it('tidak memperbesar gambar yang sudah kecil', function () {
    $path = $this->media->put(gambar(200, 150), 'menu');
    $ukuran = getimagesizefromstring((string) Storage::disk('media')->get($path));

    expect($ukuran[0])->toBe(200)->and($ukuran[1])->toBe(150);
});

it('mempertahankan PNG untuk logo agar latar transparannya tidak jadi kotak', function () {
    $path = $this->media->put(gambar(900, 900, 'png'), 'logo');

    expect($path)->toStartWith('logo/')->toEndWith('.png');
    $ukuran = getimagesizefromstring((string) Storage::disk('media')->get($path));
    expect($ukuran[0])->toBe(512);
});

it('menolak berkas yang bukan gambar', function () {
    $palsu = UploadedFile::fake()->create('daftar.csv', 4, 'text/csv');

    expect(fn () => $this->media->put($palsu, 'menu'))
        ->toThrow(ValidationException::class);
});

it('hanya menganggap sah jalur di dalam folder media', function () {
    expect($this->media->isValidPath('menu/01j9.jpg'))->toBeTrue()
        ->and($this->media->isValidPath('logo/01j9.png'))->toBeTrue()
        // Keluar folder, folder asing, dan URL luar semuanya ditolak.
        ->and($this->media->isValidPath('menu/../../.env'))->toBeFalse()
        ->and($this->media->isValidPath('../.env'))->toBeFalse()
        ->and($this->media->isValidPath('rahasia/01j9.jpg'))->toBeFalse()
        ->and($this->media->isValidPath('https://situs-lain.test/lacak.jpg'))->toBeFalse()
        ->and($this->media->isValidPath('menu/01j9.php'))->toBeFalse();
});

it('memakai jalur URL /media pada disk lokal', function () {
    // Bentuk URL publiknya penting: inilah yang menggantikan symlink public/storage.
    expect(route('media.show', ['folder' => 'menu', 'file' => '01j9.jpg']))->toContain('/media/menu/01j9.jpg')
        ->and(config('filesystems.disks.media.url'))->toEndWith('/media');
});

it('menyajikan berkas media dan menolak jalur di luar folder', function () {
    $path = $this->media->put(gambar(400, 300), 'menu');

    $ok = $this->get('/media/'.$path);
    $ok->assertOk();
    expect($ok->headers->get('Cache-Control'))->toContain('max-age=31536000');

    $this->get('/media/menu/tidak-ada.jpg')->assertNotFound();
    $this->get('/media/rahasia/berkas.jpg')->assertNotFound();
    $this->get('/media/menu/berkas.php')->assertNotFound();
});
