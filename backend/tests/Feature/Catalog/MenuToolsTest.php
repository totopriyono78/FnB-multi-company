<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\ItemPriceHistory;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\Factory;
use Tests\Support\Menu;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan']);
    $this->headers = asMember($this->owner, $this->company);
});

function csvUpload(string $content, string $name = 'menu.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

it('mengimpor menu dari CSV dengan varian dan membuat kategori (FR-MENU-09)', function () {
    $csv = "\u{FEFF}kategori;sku;nama;nama_singkat;harga;varian;stasiun;barcode;deskripsi;aktif\n"
        ."Kopi;KSA-01;Kopi Susu Gula Aren;KS Aren;Rp 18.000;\"Regular=18000; Large=22.000\";BAR;;Favorit;ya\n"
        ."Pastry;CRS-01;Croissant Butter;;25000;;Pastry;899100200300;;ya\n"
        ."Kopi;AMR-01;Americano;;15000,50;;bar;;;tidak\n";

    // Pratinjau tidak menyimpan.
    $dry = $this->post('/api/v1/items/import', ['brand_id' => $this->brand->id, 'file' => csvUpload($csv), 'dry_run' => '1'], $this->headers)
        ->assertOk()->json('data');
    expect($dry)->toMatchArray(['created' => 3, 'updated' => 0, 'categories_created' => 2, 'dry_run' => true, 'errors' => []]);
    expect(Factory::tenant($this->company, fn () => Item::query()->count()))->toBe(0);

    $this->post('/api/v1/items/import', ['brand_id' => $this->brand->id, 'file' => csvUpload($csv)], $this->headers)
        ->assertOk()->assertJsonPath('data.created', 3);

    $coffee = Factory::tenant($this->company, fn () => Item::query()->with(['variants', 'station', 'category'])->where('sku', 'KSA-01')->firstOrFail());
    expect((string) $coffee->base_price)->toBe('18000.00')
        ->and($coffee->short_name)->toBe('KS Aren')
        ->and($coffee->station->code)->toBe('BAR')
        ->and($coffee->category->name)->toBe('Kopi')
        ->and($coffee->variants->pluck('price', 'name')->map(fn ($p) => (string) $p)->all())->toBe(['Regular' => '18000.00', 'Large' => '22000.00']);
    $americano = Factory::tenant($this->company, fn () => Item::query()->where('sku', 'AMR-01')->firstOrFail());
    expect((string) $americano->base_price)->toBe('15000.50')->and($americano->is_active)->toBeFalse();

    // Impor ulang memperbarui berdasarkan SKU dan mempertahankan id varian.
    $largeId = $coffee->variants->firstWhere('name', 'Large')->id;
    $csv2 = "kategori,sku,nama,harga,varian\nKopi,ksa-01,Kopi Susu Gula Aren,19000,Regular=19000; Large=23000\n";
    $this->post('/api/v1/items/import', ['brand_id' => $this->brand->id, 'file' => csvUpload($csv2)], $this->headers)
        ->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.created', 0);
    $change = Factory::tenant($this->company, fn () => ItemPriceHistory::query()->where('item_variant_id', $largeId)->whereNotNull('old_price')->first());
    expect((string) $change->old_price)->toBe('22000.00')->and((string) $change->new_price)->toBe('23000.00');
});

it('menolak seluruh impor bila ada baris salah dan melaporkan nomor barisnya', function () {
    Menu::item($this->company, $this->brand, ['sku' => 'LAMA', 'name' => 'Menu Lama']);
    $csv = "kategori,sku,nama,harga,varian,stasiun,aktif\n"
        ."Kopi,OK-1,Kopi Benar,10000,,,ya\n"
        ."Kopi,,Tanpa SKU,10000,,,ya\n"
        ."Kopi,OK-2,Harga Salah,sepuluh ribu,,,ya\n"
        ."Kopi,OK-1,Ganda,10000,,,ya\n"
        ."Kopi,OK-3,Varian Salah,10000,Regular 18000,,ya\n"
        ."Kopi,OK-4,Stasiun Salah,10000,,GRILL,ya\n"
        ."Kopi,OK-5,Aktif Salah,10000,,,mungkin\n"
        ."Kopi,OK 6,SKU Salah,10000,,,ya\n";

    $r = $this->post('/api/v1/items/import', ['brand_id' => $this->brand->id, 'file' => csvUpload($csv)], $this->headers)
        ->assertUnprocessable();

    expect(collect($r->json('errors'))->pluck('field')->all())
        ->toBe(['row.3', 'row.4', 'row.5', 'row.6', 'row.7', 'row.8', 'row.9']);
    expect($r->json('errors.0.message'))->toStartWith('Baris 3:');
    expect(Factory::tenant($this->company, fn () => Item::query()->count()))->toBe(1);

    // Kolom wajib hilang dan format file salah.
    $this->post('/api/v1/items/import', ['brand_id' => $this->brand->id, 'file' => csvUpload("nama,harga\nA,1\n")], $this->headers)
        ->assertUnprocessable();
    $this->post('/api/v1/items/import', ['brand_id' => $this->brand->id, 'file' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')], $this->headers)
        ->assertUnprocessable();
});

it('mengekspor menu ke Excel yang dapat diimpor kembali', function () {
    $category = Menu::category($this->company, $this->brand, 'Kopi');
    Menu::item($this->company, $this->brand, [
        'category_id' => $category->id, 'sku' => 'KSA-01', 'name' => '=HYPERLINK("x")', 'base_price' => '18000',
        'variants' => [['name' => 'Regular', 'price' => '18000'], ['name' => 'Large', 'price' => '22000.50']],
    ]);

    $response = $this->get('/api/v1/items/export?brand_id='.$this->brand->id, $this->headers)->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('menu-kopi-tepi-jalan-');

    $path = $response->baseResponse->getFile()->getPathname();

    // Tidak boleh ada sel rumus (<f>) di dalam sheet — nama menu berawalan "=" harus tetap teks.
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    expect($sheetXml)->not->toContain('<f>')->toContain('HYPERLINK');
    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();

    expect($rows[0])->toBe(['kategori', 'sku', 'nama', 'nama_singkat', 'harga', 'varian', 'stasiun', 'barcode', 'deskripsi', 'aktif'])
        ->and($rows[1][1])->toBe('KSA-01')
        ->and($rows[1][2])->toBe('=HYPERLINK("x")') // disimpan sebagai teks, bukan rumus
        ->and($rows[1][5])->toBe('Regular=18000; Large=22000.50');

    // Brand di luar cakupan ditolak.
    $other = Factory::brand($this->company);
    [$bm] = Factory::staff($this->company, ['brand_manager'], [], null, [$this->brand->id]);
    $this->get('/api/v1/items/export?brand_id='.$other->id, asMember($bm, $this->company))->assertForbidden();
    [$cashier] = Factory::staff($this->company, ['cashier'], [Factory::outlet($this->company, $this->brand)->id]);
    $this->get('/api/v1/items/export?brand_id='.$this->brand->id, asMember($cashier, $this->company))->assertForbidden();
});

it('menyalin menu antar brand (FR-MENU-10)', function () {
    $target = Factory::brand($this->company, ['code' => 'KTJ2', 'name' => 'Kopi Tepi Jalan 2']);
    $sugar = Menu::modifierGroup($this->company, $this->brand, [['name' => 'Normal', 'price' => '0']], 1, 1);
    $category = Menu::category($this->company, $this->brand, 'Kopi');
    $coffee = Menu::item($this->company, $this->brand, [
        'category_id' => $category->id, 'sku' => 'KSA-01', 'name' => 'Kopi Susu', 'base_price' => '18000',
        'variants' => [['name' => 'Regular', 'price' => '18000']], 'modifier_group_ids' => [$sugar->id],
    ]);
    Menu::item($this->company, $target, ['sku' => 'KSA-01', 'name' => 'Sudah Ada']);

    $report = $this->postJson('/api/v1/menu/copy-brand', ['from_brand_id' => $this->brand->id, 'to_brand_id' => $target->id], $this->headers)
        ->assertOk()->json('data');
    expect($report['items_copied'])->toBe(0)
        ->and($report['items_skipped'])->toBe(['KSA-01'])
        ->and($report['modifier_groups_created'])->toBe(1);

    Menu::item($this->company, $this->brand, ['category_id' => $category->id, 'sku' => 'AMR-01', 'name' => 'Americano', 'modifier_group_ids' => [$sugar->id]]);
    $report = $this->postJson('/api/v1/menu/copy-brand', ['from_brand_id' => $this->brand->id, 'to_brand_id' => $target->id], $this->headers)
        ->assertOk()->json('data');
    expect($report['items_copied'])->toBe(1)->and($report['modifier_groups_created'])->toBe(0);

    $copied = Factory::tenant($this->company, fn () => Item::query()->with(['modifierGroups', 'category'])->where('brand_id', $target->id)->where('sku', 'AMR-01')->firstOrFail());
    expect($copied->id)->not->toBe($coffee->id)
        ->and($copied->category->brand_id)->toBe($target->id)
        ->and($copied->modifierGroups->first()->brand_id)->toBe($target->id);

    $this->postJson('/api/v1/menu/copy-brand', ['from_brand_id' => $this->brand->id, 'to_brand_id' => $this->brand->id], $this->headers)
        ->assertUnprocessable();
    expect(Factory::tenant($this->company, fn () => AuditLog::query()->where('action', 'menu.brand_copied')->count()))->toBe(2);
});

it('menyalin harga & ketersediaan antar outlet (FR-MENU-10)', function () {
    $kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG']);
    $dago = Factory::outlet($this->company, $this->brand, ['code' => 'DGO']);
    $otherBrandOutlet = Factory::outlet($this->company);
    $item = Menu::item($this->company, $this->brand);
    Factory::tenant($this->company, function () use ($item, $kemang) {
        ItemPrice::query()->create(['item_id' => $item->id, 'outlet_id' => $kemang->id, 'price' => '21000']);
        OutletItemAvailability::query()->create(['item_id' => $item->id, 'outlet_id' => $kemang->id, 'is_listed' => false, 'is_sold_out' => true]);
    });

    $this->postJson('/api/v1/menu/copy-outlet', ['from_outlet_id' => $kemang->id, 'to_outlet_id' => $dago->id], $this->headers)
        ->assertOk()->assertJsonPath('data.prices_copied', 1)->assertJsonPath('data.availability_copied', 1);

    [$price, $avail] = Factory::tenant($this->company, fn () => [
        ItemPrice::query()->where('outlet_id', $dago->id)->firstOrFail(),
        OutletItemAvailability::query()->where('outlet_id', $dago->id)->firstOrFail(),
    ]);
    expect((string) $price->price)->toBe('21000.00')
        ->and($avail->is_listed)->toBeFalse()
        ->and($avail->is_sold_out)->toBeFalse(); // status habis tidak ikut disalin

    $this->postJson('/api/v1/menu/copy-outlet', ['from_outlet_id' => $kemang->id, 'to_outlet_id' => $otherBrandOutlet->id], $this->headers)
        ->assertUnprocessable();

    [$om] = Factory::staff($this->company, ['outlet_manager'], [$kemang->id]);
    $this->postJson('/api/v1/menu/copy-outlet', ['from_outlet_id' => $kemang->id, 'to_outlet_id' => $dago->id], asMember($om, $this->company))
        ->assertForbidden();
});
