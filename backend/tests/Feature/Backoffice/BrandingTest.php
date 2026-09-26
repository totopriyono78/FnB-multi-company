<?php

use Filament\Facades\Filament;
use Tests\Support\Factory;

/**
 * Identitas produk yang terlihat pengguna: judul tab peramban, ikon, dan baris hak cipta.
 *
 * Permintaan user 26 Sep 2026: footer "© 2026 PT. Gamatechno Indonesia", favicon, dan judul
 * halaman "FnB Cloud - Gamatechno".
 *
 * Diuji lewat permintaan HTTP sungguhan karena ketiganya dirender Filament, bukan oleh kode
 * sendiri: judul disusun tata letak bawaan sebagai "<nama halaman> - <brandName>", dan footer
 * datang dari PanelsRenderHook::FOOTER. Memanggil config() saja tidak membuktikan apa pun
 * sampai halamannya benar-benar dirender.
 *
 * Alamat bertenant ditulis memakai penanda {tenant} lalu diganti di dalam ujinya: closure
 * dataset Pest dijalankan sebelum beforeEach, jadi kode company belum ada di sana.
 */
/** Dicari apa adanya di HTML, jadi lambangnya entity `&copy;` seperti yang ditulis di blade. */
const HAK_CIPTA = '&copy; 2026 PT. Gamatechno Indonesia';

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->alamat = fn (string $path): string => str_replace('{tenant}', $this->company->code, $path);
});

it('menutup halaman panel dengan baris hak cipta', function (string $path) {
    $this->actingAs($this->owner)->get(($this->alamat)($path))
        ->assertOk()
        ->assertSee(HAK_CIPTA, escape: false);
})->with([
    'ringkasan' => ['/admin/{tenant}'],
    'daftar brand' => ['/admin/{tenant}/brands'],
    // Tata letak sederhana memakai hook FOOTER yang sama dengan tata letak penuh — inilah yang
    // dijaga di sini, supaya halaman di luar sidebar tidak ketinggalan.
    'profil pengguna' => ['/admin/profile'],
]);

it('menutup halaman masuk dengan baris hak cipta', function () {
    // Tanpa login: baris ini adalah hal pertama yang dilihat klien saat peragaan.
    $this->get('/admin/login')->assertOk()->assertSee(HAK_CIPTA, escape: false);
});

it('menjaga nama brand tetap utuh walau di sidebar dipenggal dua baris', function () {
    // Nilai brandName berupa Htmlable agar sidebar bisa memenggalnya; judul tab dibersihkan
    // Filament dengan strip_tags. Uji ini menjaga hasil strip_tags itu tetap sama persis dengan
    // nama di config — kalau markupnya diubah dan spasinya bergeser, judul tab ikut salah.
    expect(trim(strip_tags(Filament::getBrandName())))->toBe(config('fnb.brand.name'));
});

it('memberi judul tab peramban berakhiran nama brand', function () {
    $html = $this->actingAs($this->owner)->get(($this->alamat)('/admin/{tenant}'))->assertOk()->getContent();

    expect($html)->toMatch('#<title>\s*[^<]*FnB Cloud - Gamatechno\s*</title>#');
});

it('memberi judul layar kasir dengan pola yang sama', function () {
    $this->get('/pos')->assertOk()->assertSee('<title>Kasir - FnB Cloud - Gamatechno</title>', escape: false);
});

it('menautkan favicon di halaman yang menuntut login', function (string $path) {
    $this->actingAs($this->owner)->get(($this->alamat)($path))
        ->assertOk()
        ->assertSee('/img/favicon.svg', escape: false);
})->with([
    'back-office' => ['/admin/{tenant}'],
    'profil pengguna' => ['/admin/profile'],
]);

it('menautkan favicon di halaman tanpa login', function (string $path) {
    // Tidak boleh memakai actingAs di sini: pengguna yang sudah masuk dialihkan dari /admin/login,
    // dan pengalihan 302 itu akan lolos dari uji yang hanya memeriksa isi halaman.
    $this->get($path)->assertOk()->assertSee('/img/favicon.svg', escape: false);
})->with([
    'halaman masuk' => ['/admin/login'],
    'layar kasir' => ['/pos'],
]);

it('menyediakan berkas ikon yang benar-benar berisi', function (string $berkas, int $minimalByte) {
    // public/favicon.ico bawaan Laravel berukuran 0 byte — peramban tetap memintanya walau tidak
    // ada <link>, dan berkas kosong tampil sebagai ikon rusak. Uji ini menjaga agar tidak kembali 0.
    $path = public_path($berkas);

    expect(file_exists($path))->toBeTrue("Berkas ikon {$berkas} tidak ada.");
    expect(filesize($path))->toBeGreaterThan($minimalByte, "Berkas ikon {$berkas} kosong atau terpotong.");
})->with([
    'favicon.ico' => ['favicon.ico', 1000],
    'favicon.svg' => ['img/favicon.svg', 300],
    'apple-touch-icon' => ['img/apple-touch-icon.png', 500],
]);
