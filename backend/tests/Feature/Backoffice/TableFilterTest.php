<?php

use App\Filament\Resources\StockBalanceResource\Pages\ListStockBalances;
use App\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Stock;

/**
 * Filter tabel back-office benar-benar menyaring (temuan user 30 Sep 2026).
 *
 * Gejalanya: chip "Filter aktif" muncul, judul filternya tertulis, tetapi isi tabel tidak berubah
 * sedikit pun — dan tidak ada error apa pun yang memberi tahu.
 *
 * Sebabnya satu hal kecil yang berlaku untuk SEMUA filter: Filament menyuntikkan argumen closure
 * berdasarkan NAMA parameter. Closure `->query(fn (Builder $q) => ...)` membuat Filament gagal
 * mengenali `$q`, lalu membangun objek Builder baru dari container; closure mengubah objek buangan
 * itu, dan hasilnya dilempar. Karena itu berkas ini berisi dua lapis: uji perilaku untuk filter
 * yang terbukti rusak, dan satu uji statis yang menjaga seluruh filter lain dari kesalahan sama.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $brand = Factory::brand($this->company, ['code' => 'KTJ']);
    $this->kemang = Factory::outlet($this->company, $brand, ['code' => 'KMG']);
    $this->tebet = Factory::outlet($this->company, $brand, ['code' => 'TBT']);

    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->actingAs($this->owner, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->company);
    app(TenantContext::class)->setTenant($this->company->id);
});

afterEach(fn () => app(TenantContext::class)->reset());

/** Satu bahan aman dan satu bahan di bawah minimum, di outlet yang ditentukan. */
function siapkanStok(object $test): array
{
    $lokasiKemang = Stock::location($test->company, $test->kemang);
    $lokasiTebet = Stock::location($test->company, $test->tebet);

    $aman = Stock::ingredient($test->company, 'Gula', 'g', ['min_stock' => '500']);
    $kritis = Stock::ingredient($test->company, 'Bawang Merah', 'g', ['min_stock' => '500']);
    $amanTebet = Stock::ingredient($test->company, 'Kopi Bubuk', 'g', ['min_stock' => '100']);

    Stock::receive($test->company, $lokasiKemang, $aman, '2000', '40');
    Stock::receive($test->company, $lokasiKemang, $kritis, '100', '40');
    Stock::receive($test->company, $lokasiTebet, $amanTebet, '900', '50');

    return compact('aman', 'kritis', 'amanTebet');
}

function saldo(object $test, string $ingredientId): StockBalance
{
    return Factory::tenant($test->company, fn () => StockBalance::query()->where('ingredient_id', $ingredientId)->firstOrFail());
}

it('filter "Hanya stok kritis" menyaring, bukan hanya menyalakan chip', function () {
    ['aman' => $aman, 'kritis' => $kritis] = siapkanStok($this);

    $tabel = Livewire::test(ListStockBalances::class);
    // Tanpa filter: keduanya terlihat, jadi perbedaan di bawah memang karena filternya.
    $tabel->assertCanSeeTableRecords([saldo($this, $aman->id), saldo($this, $kritis->id)]);

    $tabel->set('tableFilters.low.isActive', true)
        ->assertCanSeeTableRecords([saldo($this, $kritis->id)])
        ->assertCanNotSeeTableRecords([saldo($this, $aman->id)]);
});

it('filter outlet di Posisi Stok membatasi ke outlet yang dipilih', function () {
    ['aman' => $aman, 'amanTebet' => $amanTebet] = siapkanStok($this);

    Livewire::test(ListStockBalances::class)
        ->set('tableFilters.outlet.value', $this->kemang->id)
        ->assertCanSeeTableRecords([saldo($this, $aman->id)])
        ->assertCanNotSeeTableRecords([saldo($this, $amanTebet->id)]);
});

it('filter periode di Mutasi Stok membatasi menurut hari bisnis', function () {
    siapkanStok($this);

    $tabel = Livewire::test(ListStockMovements::class);
    $jumlahSemua = Factory::tenant($this->company, fn () => StockMovement::query()->count());
    expect($jumlahSemua)->toBeGreaterThan(0);

    // Rentang yang seluruhnya di masa lalu: tidak ada mutasi yang boleh lolos.
    $tabel->set('tableFilters.period.from', now()->subDays(30)->format('Y-m-d'))
        ->set('tableFilters.period.until', now()->subDays(20)->format('Y-m-d'));

    expect($tabel->instance()->getFilteredTableQuery()->count())
        ->toBe(0, 'filter periode tidak menyaring: seluruh riwayat tetap tampil');
});

it('setiap closure query filter memakai parameter bernama $query', function () {
    /*
     * Penjaga untuk 50-an filter lain di back-office. Kesalahannya tidak menimbulkan error,
     * tidak terlihat di layar, dan hanya ketahuan bila ada yang menghitung barisnya satu per satu
     * — jadi lebih murah dijaga di sini daripada ditemukan pengguna.
     *
     * Hanya closure BERPARAMETER yang diperiksa. `->query(fn () => ...)` pada widget tabel
     * memasok kueri dasarnya sendiri dan tidak menerima suntikan apa pun.
     */
    $berkas = [];
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));
    foreach ($dir as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $berkas[] = $file->getPathname();
        }
    }
    expect($berkas)->not->toBeEmpty();

    $salah = [];
    foreach ($berkas as $path) {
        $isi = (string) file_get_contents($path);
        preg_match_all(
            '/->(?:query|baseQuery)\(\s*(?:static\s+)?(?:fn|function)\s*\(\s*([A-Za-z_\\\\|?]+)\s+\$([A-Za-z_]\w*)/',
            $isi,
            $cocok,
            PREG_SET_ORDER
        );
        foreach ($cocok as $m) {
            if (str_contains($m[1], 'Builder') && $m[2] !== 'query') {
                $salah[] = basename($path).': $'.$m[2];
            }
        }
    }

    expect($salah)->toBe([], 'Filament menyuntikkan berdasarkan nama; parameter Builder wajib $query, bukan: '.implode(', ', $salah));
});
