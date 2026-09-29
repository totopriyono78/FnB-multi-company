<?php

use App\Modules\Catalog\Application\MenuCategorySpreadsheet;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\Factory;

/**
 * Impor/ekspor kategori menu (diminta user 28 Sep 2026).
 *
 * Dua janji yang dijaga uji di sini, keduanya keputusan user dan bukan detail teknis:
 * kategori dicocokkan berdasarkan NAMA, dan yang tidak ada di berkas TIDAK disentuh.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan']);
});

function csvKategori(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'kat').'.csv';
    file_put_contents($path, $content);

    return $path;
}

function imporKategori(object $test, string $csv, bool $dryRun = false): array
{
    return Factory::tenant($test->company, fn () => app(MenuCategorySpreadsheet::class)
        ->import($test->brand, csvKategori($csv), 'csv', $dryRun));
}

function kategori(object $test, string $nama): MenuCategory
{
    return Factory::tenant($test->company, fn () => MenuCategory::query()
        ->where('brand_id', $test->brand->id)
        ->whereRaw('lower(name) = ?', [mb_strtolower($nama)])
        ->firstOrFail());
}

it('membuat kategori dari CSV lengkap dengan warna, ikon, urutan, dan status', function () {
    // BOM di depan: Excel versi Indonesia menulisnya, dan tanpa ditangani nama kolom pertama
    // tidak pernah cocok.
    $laporan = imporKategori($this, "\u{FEFF}nama;warna;ikon;urutan;aktif\n"
        ."Kopi;amber;cup;1;ya\n"
        ."Pastry;pink;;2;ya\n"
        ."Musiman;teal;;9;tidak\n");

    expect($laporan)->toMatchArray(['created' => 3, 'updated' => 0, 'errors' => [], 'dry_run' => false]);

    $kopi = kategori($this, 'Kopi');
    expect($kopi->color)->toBe('amber')
        ->and($kopi->icon)->toBe('cup')
        ->and($kopi->sort_order)->toBe(1)
        ->and($kopi->is_active)->toBeTrue()
        ->and(kategori($this, 'Musiman')->is_active)->toBeFalse();
});

it('mencocokkan kategori yang sudah ada berdasarkan nama tanpa membedakan huruf besar-kecil', function () {
    imporKategori($this, "nama,warna,urutan\nKopi,amber,1\n");

    // Ejaan berbeda, kategori yang sama — persis seperti indeks unik di basis data.
    $laporan = imporKategori($this, "nama,warna,urutan\nKOPI,teal,5\n");

    expect($laporan)->toMatchArray(['created' => 0, 'updated' => 1]);
    expect(Factory::tenant($this->company, fn () => MenuCategory::query()->where('brand_id', $this->brand->id)->count()))->toBe(1);

    $kopi = kategori($this, 'Kopi');
    expect($kopi->color)->toBe('teal')->and($kopi->sort_order)->toBe(5);
});

it('mempertahankan nilai tersimpan untuk kolom yang dikosongkan', function () {
    /*
     * Inilah yang membuat berkas "nama + urutan" saja bisa dipakai untuk menata ulang susunan
     * tombol POS: kolom yang tidak diisi tidak boleh memutihkan warna dan ikon yang sudah diatur.
     */
    imporKategori($this, "nama,warna,ikon,urutan,aktif\nKopi,amber,cup,1,tidak\n");

    imporKategori($this, "nama,urutan\nKopi,7\n");

    $kopi = kategori($this, 'Kopi');
    expect($kopi->sort_order)->toBe(7)
        ->and($kopi->color)->toBe('amber')
        ->and($kopi->icon)->toBe('cup')
        ->and($kopi->is_active)->toBeFalse();
});

it('tidak menyentuh kategori yang tidak ada di berkas', function () {
    // Keputusan user 28 Sep 2026. Kategori diacu menu dan lewat menu diacu riwayat transaksi,
    // jadi berkas yang kebetulan kurang lengkap tidak boleh mengosongkan layar kasir.
    imporKategori($this, "nama,warna,urutan,aktif\nKopi,amber,1,ya\nPastry,pink,2,ya\n");

    $laporan = imporKategori($this, "nama,urutan\nKopi,3\n");

    expect($laporan)->toMatchArray(['created' => 0, 'updated' => 1]);
    $pastry = kategori($this, 'Pastry');
    expect($pastry->is_active)->toBeTrue()
        ->and($pastry->sort_order)->toBe(2)
        ->and($pastry->color)->toBe('pink');
});

it('menolak seluruh impor bila ada warna yang tidak dikenal dan menyebut nomor barisnya', function () {
    $laporan = imporKategori($this, "nama,warna\nKopi,amber\nPastry,merah\n");

    expect($laporan['created'])->toBe(0)
        ->and($laporan['errors'])->toHaveCount(1)
        ->and($laporan['errors'][0]['row'])->toBe(3)
        ->and($laporan['errors'][0]['message'])->toContain('merah');

    // Semua-atau-tidak: baris pertama yang sah pun tidak ikut tersimpan.
    expect(Factory::tenant($this->company, fn () => MenuCategory::query()->count()))->toBe(0);
});

it('menolak nama kategori yang muncul dua kali dalam satu berkas', function () {
    $laporan = imporKategori($this, "nama,urutan\nKopi,1\nkopi,2\n");

    expect($laporan['errors'])->toHaveCount(1)
        ->and($laporan['errors'][0]['row'])->toBe(3)
        ->and($laporan['errors'][0]['message'])->toContain('lebih dari sekali');
});

it('menolak urutan yang bukan bilangan bulat', function () {
    $laporan = imporKategori($this, "nama,urutan\nKopi,satu\n");

    expect($laporan['errors'][0]['message'])->toContain('0–32767');
});

it('tidak menyimpan apa pun dalam mode periksa saja', function () {
    $laporan = imporKategori($this, "nama,warna,urutan\nKopi,amber,1\n", dryRun: true);

    expect($laporan)->toMatchArray(['created' => 1, 'updated' => 0, 'dry_run' => true]);
    expect(Factory::tenant($this->company, fn () => MenuCategory::query()->count()))->toBe(0);
});

it('menolak berkas tanpa kolom nama', function () {
    expect(fn () => imporKategori($this, "warna,urutan\namber,1\n"))
        ->toThrow(ValidationException::class);
});

it('mengekspor kategori ke Excel yang dapat diimpor kembali', function () {
    imporKategori($this, "nama,warna,ikon,urutan,aktif\nKopi,amber,cup,1,ya\nMusiman,teal,,9,tidak\n");

    $path = Factory::tenant($this->company, fn () => app(MenuCategorySpreadsheet::class)->export($this->brand));

    $baris = [];
    $reader = new XlsxReader;
    $reader->open($path);
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $baris[] = array_map(fn ($v) => (string) $v, $row->toArray());
        }
        break;
    }
    $reader->close();

    expect($baris[0])->toBe(MenuCategorySpreadsheet::HEADERS)
        // Diurutkan sesuai urutan tampil, bukan sesuai waktu dibuat.
        ->and($baris[1])->toBe(['Kopi', 'amber', 'cup', '1', 'ya'])
        ->and($baris[2])->toBe(['Musiman', 'teal', '', '9', 'tidak']);

    // Impor balik berkas hasil ekspor: memperbarui, bukan menggandakan.
    $ulang = Factory::tenant($this->company, fn () => app(MenuCategorySpreadsheet::class)->import($this->brand, $path, 'xlsx'));
    expect($ulang)->toMatchArray(['created' => 0, 'updated' => 2, 'errors' => []]);

    @unlink($path);
});

it('tidak mengubah kategori milik company lain', function () {
    // Kunci pencocokannya nama, dan nama yang sama sangat mungkin dipakai dua perusahaan.
    [$lain, $pemilikLain] = Factory::company('Warung Seberang');
    $brandLain = Factory::brand($lain, ['code' => 'WS', 'name' => 'Warung Seberang']);
    Factory::tenant($lain, fn () => MenuCategory::query()->create([
        'brand_id' => $brandLain->id, 'name' => 'Kopi', 'color' => 'pink', 'sort_order' => 4,
    ]));

    imporKategori($this, "nama,warna,urutan\nKopi,amber,1\n");

    $tetangga = Factory::tenant($lain, fn () => MenuCategory::query()->where('brand_id', $brandLain->id)->firstOrFail());
    expect($tetangga->color)->toBe('pink')->and($tetangga->sort_order)->toBe(4)
        ->and($pemilikLain->id)->not->toBe($this->owner->id);
});
