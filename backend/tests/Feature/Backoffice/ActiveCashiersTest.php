<?php

use App\Modules\Reporting\Application\ActiveCashiers;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Kartu "Kasir bertugas" di Ringkasan (permintaan user 30 Sep 2026).
 *
 * Janji yang dijaga di sini: kartu ini menjawab "siapa yang sedang melayani SEKARANG", bukan rekap
 * harian. Karena itu shift yang sudah ditutup tidak boleh muncul, dan shift yang terbuka tetapi
 * perangkatnya sudah lama tidak berdenyut harus tetap muncul **dengan tanda terputus** — bukan
 * disembunyikan. Shift yang lupa ditutup justru persoalan yang perlu dilihat pemilik.
 *
 * Pesanan uji standar Pos::order() bernilai 78.500.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '500000');
});

afterEach(fn () => app(TenantContext::class)->reset());

function denyut(object $test, ?string $when): void
{
    DB::table('devices')->where('id', $test->pos->device->id)->update(['last_seen_at' => $when]);
}

/** @return list<array<string, mixed>> */
function kasirBertugas(object $test): array
{
    return $test->pos->tenant(fn () => app(ActiveCashiers::class)->forOutlets([$test->pos->outlet->id]));
}

function jualLagi(object $test, int $seq): string
{
    return $test->pos->push('order', $test->pos->order($test->shiftId, $test->pos->receipt($test->ymd, $seq)))['order_id'];
}

it('menampilkan kasir dengan shift terbuka berikut transaksi dan penjualannya', function () {
    jualLagi($this, 1);
    jualLagi($this, 2);
    denyut($this, now()->toDateTimeString());

    $rows = kasirBertugas($this);

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'shift_id' => $this->shiftId,
        'order_count' => 2,
        'net_sales' => '157000.00',      // 2 x 78.500
        'online' => true,
        'status_label' => 'Terhubung',
    ]);
    expect($rows[0]['cashier'])->not->toBe('')
        ->and($rows[0]['outlet'])->toContain('KMG');
});

it('tidak menampilkan shift yang sudah ditutup', function () {
    jualLagi($this, 1);
    expect(kasirBertugas($this))->toHaveCount(1);

    $this->pos->push('shift.close', [
        'shift_id' => $this->shiftId,
        'closed_by' => $this->pos->userId('cashier'),
        'counted_cash' => '578500',
        'closed_at' => now()->toIso8601String(),
    ]);

    expect(kasirBertugas($this))->toBe([]);
});

it('tetap menampilkan shift yang terbuka meski perangkatnya sudah lama diam', function () {
    /*
     * Ini alasan kartu ini ada. Menyaring keluar yang perangkatnya mati akan menyembunyikan shift
     * yang lupa ditutup semalam — persis kejadian yang paling sering dan paling mahal.
     */
    denyut($this, now()->subHours(9)->toDateTimeString());

    $rows = kasirBertugas($this);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['online'])->toBeFalse()
        // Statusnya wajib berupa teks, bukan cuma warna (WCAG 2.1 AA), dan menyebut jam terakhirnya.
        ->and($rows[0]['status_label'])->toStartWith('Terputus sejak');
});

it('menandai terputus bila perangkat belum pernah berdenyut sama sekali', function () {
    denyut($this, null);

    expect(kasirBertugas($this)[0]['status_label'])->toBe('Terputus, belum pernah online');
});

it('memakai ambang offline yang sama dengan kartu kesehatan perangkat', function () {
    // Satu detik di dalam ambang masih terhubung; satu detik di luar sudah terputus. Tanpa uji ini,
    // ambang di dua kartu bisa berbeda diam-diam dan layar yang sama berbeda pendapat.
    $ambang = (int) config('fnb.devices.offline_after_seconds');

    denyut($this, now()->subSeconds($ambang - 5)->toDateTimeString());
    expect(kasirBertugas($this)[0]['online'])->toBeTrue();

    denyut($this, now()->subSeconds($ambang + 5)->toDateTimeString());
    expect(kasirBertugas($this)[0]['online'])->toBeFalse();
});

it('tidak menghitung transaksi yang dibatalkan dan mengurangi nilai retur', function () {
    jualLagi($this, 1);
    $batal = jualLagi($this, 2);
    $diretur = jualLagi($this, 3);

    $this->pos->pushAs('manager', 'order.void', [
        'order_id' => $batal, 'voided_by' => $this->pos->userId('manager'),
        'reason' => 'Salah input menu', 'created_at' => now()->toIso8601String(),
    ]);
    $this->pos->pushAs('manager', 'order.refund', [
        'id' => (string) Str::uuid7(), 'order_id' => $diretur, 'shift_id' => $this->shiftId,
        'amount' => '78500', 'method' => 'cash', 'stock_action' => 'return',
        'reason' => 'Pesanan tidak sesuai', 'refunded_by' => $this->pos->userId('manager'),
        'created_at' => now()->toIso8601String(),
    ]);

    $rows = kasirBertugas($this);

    // Tiga transaksi dibuat; yang dibatalkan tidak dihitung, yang diretur tetap dihitung sebagai
    // kejadian tetapi uangnya tidak. Definisi yang sama dengan halaman Transaksi & layar Shift.
    expect($rows[0])->toMatchArray(['order_count' => 2, 'net_sales' => '78500.00']);
    expect($this->pos->tenant(fn () => Order::query()->count()))->toBe(3);
});

it('tidak membocorkan shift milik company lain', function () {
    $tetangga = Pos::setup('Warung Seberang', 'WSB');
    $tetangga->openShift('cashier', '0');

    // Outlet tetangga diminta secara eksplisit: yang menjaga bukan pilihan outlet di layar,
    // melainkan konteks tenant + RLS.
    $rows = $this->pos->tenant(fn () => app(ActiveCashiers::class)->forOutlets([$tetangga->outlet->id]));

    expect($rows)->toBe([]);
    expect(Factory::tenant($tetangga->company, fn () => app(ActiveCashiers::class)->forOutlets([$this->pos->outlet->id])))->toBe([]);
});

it('tidak menyentuh tabel transaksi bila cakupan outletnya kosong', function () {
    /*
     * Pengguna tanpa cakupan outlet tetap membuka Ringkasan. Kartu ini harus pulang dengan tangan
     * kosong tanpa memindai `shifts`/`orders`/`refunds` sama sekali — `whereIn` dengan daftar kosong
     * memang menghasilkan nol baris, tetapi tetap saja satu kueri yang tak ada gunanya.
     * Perintah tenant (SET ROLE / set_config) tidak ikut dihitung: itu milik pembungkusnya.
     */
    DB::enableQueryLog();
    expect($this->pos->tenant(fn () => app(ActiveCashiers::class)->forOutlets([])))->toBe([]);
    $tabel = array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        fn (string $q) => preg_match('/\b(shifts|orders|refunds)\b/i', $q) === 1,
    ));
    DB::disableQueryLog();

    expect($tabel)->toBe([]);
});
