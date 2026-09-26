<?php

use App\Modules\Shared\Application\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Factory;
use Tests\Support\Menu;

/**
 * Foto menu sampai ke layar kasir (FR-MENU-02).
 *
 * Pernah jadi masalah: kolom `image_path` sudah ada di basis data dan sudah dirender POS, tetapi
 * tidak lolos validasi permintaan sehingga dibuang diam-diam — sama seperti `sold_by_weight`
 * sebelumnya. Uji ini menjaga jalur datanya utuh dari back-office sampai kartu menu.
 */
beforeEach(function () {
    Storage::fake('media');
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan']);
    $this->kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG']);
    $this->headers = asMember($this->owner, $this->company);
});

function fotoUji(int $w = 1600, int $h = 1200): UploadedFile
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 200, 90, 30));
    $path = tempnam(sys_get_temp_dir(), 'menu').'.jpg';
    imagejpeg($im, $path);
    imagedestroy($im);

    return new UploadedFile($path, 'ayam-bakar.jpg', 'image/jpeg', null, true);
}

it('mengirim URL foto ke katalog POS dan menyimpan jalurnya lewat API', function () {
    $foto = app(MediaStore::class)->put(fotoUji(), 'menu');
    $item = Menu::item($this->company, $this->brand, ['name' => 'Ayam Bakar', 'sku' => 'AYB-01', 'base_price' => '35000']);

    $this->patchJson("/api/v1/items/{$item->id}", ['image_path' => $foto], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.image_path', $foto);

    [, $token] = Factory::pairedDevice($this->company, $this->kemang);
    $katalog = $this->getJson('/api/v1/pos/catalog', bearer($token))->assertOk();
    $kartu = collect($katalog->json('data.items'))->firstWhere('sku', 'AYB-01');

    // Perangkat menerima URL jadi, bukan jalur internal — supaya tidak perlu tahu media
    // disimpan di disk lokal atau object storage. (Bentuk URL-nya ditentukan disk yang dipakai;
    // di sini disknya dipalsukan, jadi yang diperiksa jalurnya ikut terbawa.)
    expect($kartu['image_url'])->toContain($foto)
        ->and($kartu['image_path'])->toBe($foto);
});

it('menolak URL luar dan jalur di luar folder media sebagai foto menu', function () {
    $item = Menu::item($this->company, $this->brand, ['name' => 'Ayam Bakar', 'sku' => 'AYB-01', 'base_price' => '35000']);

    foreach ([
        'https://situs-lain.test/lacak.jpg',   // memanggil server pihak ketiga dari layar kasir
        '../../.env',                          // keluar folder
        'menu/../../.env',
        'menu/skrip.php',
    ] as $jahat) {
        $r = $this->patchJson("/api/v1/items/{$item->id}", ['image_path' => $jahat], $this->headers)
            ->assertUnprocessable();
        expect(errorFields($r))->toContain('image_path');
    }

    expect($item->refresh()->image_path)->toBeNull();
});

it('membuang foto lama setelah foto penggantinya tersimpan', function () {
    $lama = app(MediaStore::class)->put(fotoUji(), 'menu');
    $baru = app(MediaStore::class)->put(fotoUji(), 'menu');
    $item = Menu::item($this->company, $this->brand, [
        'name' => 'Ayam Bakar', 'sku' => 'AYB-01', 'base_price' => '35000', 'image_path' => $lama,
    ]);

    $this->patchJson("/api/v1/items/{$item->id}", ['image_path' => $baru], $this->headers)->assertOk();

    Storage::disk('media')->assertMissing($lama);
    Storage::disk('media')->assertExists($baru);
});

it('mengirim logo brand ke perangkat hanya bila outlet menyalakan cetak logo', function () {
    $logo = app(MediaStore::class)->put(fotoUji(400, 400), 'logo');
    Factory::tenant($this->company, fn () => $this->brand->update(['logo_path' => $logo]));
    [, $token] = Factory::pairedDevice($this->company, $this->kemang);

    // Bawaan outlet: cetak logo mati → URL-nya tidak dikirim sama sekali.
    $mati = $this->getJson('/api/v1/pos/catalog', bearer($token))->assertOk();
    expect($mati->json('data.outlet.receipt.show_logo'))->toBeFalse()
        ->and($mati->json('data.outlet.receipt.logo_url'))->toBeNull();

    Factory::tenant($this->company, fn () => $this->kemang->update([
        'receipt_settings' => ['show_logo' => true, 'footer' => 'Sampai jumpa lagi'],
    ]));

    $nyala = $this->getJson('/api/v1/pos/catalog', bearer($token))->assertOk();
    expect($nyala->json('data.outlet.receipt.show_logo'))->toBeTrue()
        ->and($nyala->json('data.outlet.receipt.logo_url'))->toContain($logo)
        ->and($nyala->json('data.outlet.receipt.footer'))->toBe('Sampai jumpa lagi');
});

it('tidak membocorkan foto menu company lain lewat katalog', function () {
    $foto = app(MediaStore::class)->put(fotoUji(), 'menu');
    [$lain, $pemilikLain] = Factory::company('Warung Bu Ratna');
    $brandLain = Factory::brand($lain, ['code' => 'WBR']);
    $itemLain = Menu::item($lain, $brandLain, [
        'name' => 'Nasi Rawon', 'sku' => 'RWN-01', 'base_price' => '28000', 'image_path' => $foto,
    ]);

    // Pemilik company ini tidak bisa melihat maupun menyunting menu company lain.
    $this->getJson("/api/v1/items/{$itemLain->id}", $this->headers)->assertNotFound();
    $this->patchJson("/api/v1/items/{$itemLain->id}", ['image_path' => null], $this->headers)->assertNotFound();

    expect($itemLain->refresh()->image_path)->toBe($foto);
    unset($pemilikLain);
});
