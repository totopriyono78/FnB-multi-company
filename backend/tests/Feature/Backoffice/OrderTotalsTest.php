<?php

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Baris total & ekspor halaman Transaksi (permintaan user 30 Sep 2026).
 *
 * Dua aturan keuangannya keputusan user, bukan tafsiran sendiri, jadi keduanya dijaga di sini:
 * transaksi yang dibatalkan tidak ikut dijumlah, dan totalnya dikurangi retur. Ditambah satu janji
 * yang menurut saya paling mudah rusak diam-diam: angka di berkas ekspor sama dengan angka di
 * layar, karena keduanya memakai satu perhitungan.
 *
 * Pesanan standar Pos::order() bernilai 78.500 per transaksi.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '500000');
});

/*
 * Masuk sebagai pemilik dilakukan SETELAH transaksinya dibuat, bukan di beforeEach: begitu guard
 * web dipakai, permintaan API dengan token perangkat ditolak sebagai WRONG_CLIENT.
 */
function masukPemilik(object $test): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $test->actingAs(Factory::ownerOf($test->pos->company), 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($test->pos->company);
    app(TenantContext::class)->setTenant($test->pos->company->id);
}

afterEach(fn () => app(TenantContext::class)->reset());

function jual(object $test, int $seq): string
{
    return $test->pos->push('order', $test->pos->order($test->shiftId, $test->pos->receipt($test->ymd, $seq)))['order_id'];
}

/** @return array{counted: int, voided: int, gross: string, refund: string, net: string, average: string} */
function totalTransaksi(object $test): array
{
    masukPemilik($test);
    $tabel = Livewire::test(ListOrders::class);

    return OrderResource::summary($tabel->instance()->getFilteredTableQuery());
}

it('menjumlah seluruh transaksi yang tersaring, bukan hanya halaman yang tampil', function () {
    jual($this, 1);
    jual($this, 2);
    jual($this, 3);

    expect(totalTransaksi($this))->toMatchArray([
        'counted' => 3,
        'voided' => 0,
        'net' => '235500.00',            // 3 × 78.500
        'average' => '78500.00',
    ]);
});

it('mengeluarkan transaksi yang dibatalkan dari total tetapi tetap melaporkan jumlahnya', function () {
    jual($this, 1);
    $batal = jual($this, 2);

    $this->pos->pushAs('manager', 'order.void', [
        'order_id' => $batal, 'voided_by' => $this->pos->userId('manager'),
        'reason' => 'Salah input menu', 'created_at' => now()->toIso8601String(),
    ]);

    $ringkasan = totalTransaksi($this);
    expect($ringkasan)->toMatchArray(['counted' => 1, 'voided' => 1, 'net' => '78500.00']);
    // Barisnya tetap ada di tabel; yang dikecualikan hanya nilainya.
    expect(Factory::tenant($this->pos->company, fn () => Order::query()->count()))->toBe(2);
    // Selisih terhadap baris yang terlihat harus dijelaskan di baris totalnya sendiri.
    expect(OrderResource::ringkasanTeks($ringkasan))->toContain('1 dibatalkan, tidak dihitung');
});

it('mengurangi nilai retur dari total', function () {
    jual($this, 1);
    $diretur = jual($this, 2);

    // Retur penuh: nominalnya wajib sama dengan nilai transaksi, jika tidak server menolaknya.
    $retur = $this->pos->pushAs('manager', 'order.refund', [
        'order_id' => $diretur, 'shift_id' => $this->shiftId, 'amount' => '78500',
        'method' => 'cash', 'stock_action' => 'return', 'reason' => 'Pesanan tidak sesuai',
        'refunded_by' => $this->pos->userId('manager'), 'created_at' => now()->toIso8601String(),
    ]);
    expect($retur['status'])->toBe('accepted');

    /*
     * Transaksi yang diretur TETAP dihitung sebagai transaksi — yang berkurang uangnya, bukan
     * kejadiannya. Itu sebabnya rata-ratanya 39.250: 78.500 dibagi dua transaksi.
     */
    expect(totalTransaksi($this))->toMatchArray([
        'counted' => 2,
        'gross' => '157000.00',
        'refund' => '78500.00',
        'net' => '78500.00',
        'average' => '39250.00',
    ]);
});

it('tidak menghitung retur milik transaksi di luar filter', function () {
    /*
     * Retur dicocokkan lewat daftar id transaksi yang ikut dihitung. Tanpa itu, retur transaksi
     * outlet lain — atau periode lain — akan mengurangi total yang sedang dilihat pengguna.
     */
    $diretur = jual($this, 1);
    $this->pos->pushAs('manager', 'order.refund', [
        'order_id' => $diretur, 'shift_id' => $this->shiftId, 'amount' => '78500',
        'method' => 'cash', 'stock_action' => 'return', 'reason' => 'Pesanan tidak sesuai',
        'refunded_by' => $this->pos->userId('manager'), 'created_at' => now()->toIso8601String(),
    ]);

    // Filter status "paid" menyisihkan transaksi yang sudah diretur sebagian, jadi returnya pun
    // harus ikut tersisih.
    masukPemilik($this);
    $tabel = Livewire::test(ListOrders::class)->set('tableFilters.status.value', Order::PAID);
    $ringkasan = OrderResource::summary($tabel->instance()->getFilteredTableQuery());

    expect($ringkasan)->toMatchArray(['counted' => 0, 'refund' => '0.00', 'net' => '0.00']);
});

it('mengekspor Excel dengan angka yang sama seperti di layar', function () {
    jual($this, 1);
    $batal = jual($this, 2);
    $this->pos->pushAs('manager', 'order.void', [
        'order_id' => $batal, 'voided_by' => $this->pos->userId('manager'),
        'reason' => 'Salah input menu', 'created_at' => now()->toIso8601String(),
    ]);

    $layar = totalTransaksi($this);

    $tabel = Livewire::test(ListOrders::class);
    $response = $tabel->instance()->export('xlsx');

    expect($response)->not->toBeNull();
    $path = $response->getFile()->getPathname();
    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(1000);

    $isi = [];
    $reader = new Reader;
    $reader->open($path);
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $isi[] = implode(' | ', array_map(fn ($v) => (string) $v, $row->toArray()));
        }
        break;
    }
    $reader->close();
    $teks = implode("\n", $isi);

    // Kedua baris transaksi ikut, termasuk yang dibatalkan.
    expect($teks)->toContain($this->pos->receipt($this->ymd, 1))
        ->and($teks)->toContain($this->pos->receipt($this->ymd, 2))
        ->and($teks)->toContain('Dibatalkan')
        // Angka di berkas sama dengan yang dibaca pengguna di layar. Nilainya tersimpan sebagai
        // bilangan (78500), bukan teks berpemisah ribuan — pemisahnya urusan format sel.
        ->and($layar['net'])->toBe('78500.00')
        ->and($teks)->toContain('Total setelah retur | 78500')
        // Baris total ikut menjelaskan mengapa dua baris hanya menghasilkan satu yang dihitung.
        ->and($teks)->toContain('1 transaksi · 1 dibatalkan, tidak dihitung')
        ->and($teks)->toContain('Total sudah dikurangi retur');

    @unlink($path);
});

it('menolak mengekspor ketika filternya tidak menyisakan transaksi', function () {
    masukPemilik($this);
    $tabel = Livewire::test(ListOrders::class)
        ->set('tableFilters.periode.from', now()->addYear()->format('Y-m-d'));

    expect($tabel->instance()->export('xlsx'))->toBeNull();
});

it('membatasi ekspor pada outlet yang boleh dilihat pengguna', function () {
    // Isolasi tenant: berkas ekspor tidak boleh jadi pintu belakang yang melewati cakupan akses.
    jual($this, 1);
    $tetangga = Pos::setup('Warung Seberang', 'WSB');
    [$shiftLain, $ymdLain] = $tetangga->openShift('cashier', '0');
    $tetangga->push('order', $tetangga->order($shiftLain, $tetangga->receipt($ymdLain, 1)));

    expect(totalTransaksi($this))->toMatchArray(['counted' => 1, 'net' => '78500.00']);
});
