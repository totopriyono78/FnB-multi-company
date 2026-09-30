<?php

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Widgets\OperationalAlerts;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Angka di kartu Ringkasan harus sama dengan isi daftar yang dibukanya (temuan user 30 Sep 2026).
 *
 * Gejalanya: kartu "Transaksi perlu ditinjau" menulis 3, lalu daftar yang dibuka tautannya berisi 8.
 * Sebabnya kartunya menghitung 7 hari terakhir sementara tautannya membuka daftar tanpa batas
 * tanggal sama sekali. Selisih seperti ini membuat seluruh kartu di halaman itu kehilangan
 * kewibawaannya: pembacanya tidak punya cara menebak angka mana yang benar.
 */
beforeEach(function () {
    // Seluruh data master lahir 10 hari lalu supaya transaksi lama tidak dianggap memakai
    // data yang berubah setelahnya.
    $this->travelTo(now()->subDays(10));
    $this->pos = Pos::setup();
});

/** @return array{0: string, 1: string} id transaksi lama (di luar 7 hari) dan baru */
function duaTransaksiDitandai(object $test): array
{
    [$shiftLama, $ymdLama] = $test->pos->openShift('cashier');
    $lama = $test->pos->push('order', $test->pos->order($shiftLama, $test->pos->receipt($ymdLama, 1)))['order_id'];
    $test->pos->push('shift.close', [
        'shift_id' => $shiftLama, 'closed_by' => $test->pos->userId('cashier'),
        'closed_at' => now()->toIso8601String(), 'counted_cash' => '0', 'variance_note' => 'uji',
    ]);

    $test->travelBack();
    // Token perangkat terbit 10 hari lalu dan ikut kedaluwarsa saat waktunya dikembalikan;
    // perangkat dipasangkan ulang persis seperti outlet yang membuka hari berikutnya.
    [$test->pos->device, $test->pos->deviceToken] = Factory::pairedDevice($test->pos->company, $test->pos->outlet);

    [$shiftBaru, $ymdBaru] = $test->pos->openShift('cashier');
    $baru = $test->pos->push('order', $test->pos->order($shiftBaru, $test->pos->receipt($ymdBaru, 1)))['order_id'];

    Factory::tenant($test->pos->company, fn () => DB::table('orders')
        ->whereIn('id', [$lama, $baru])
        ->update(['flags' => json_encode(['gateway_refund_pending'])]));

    return [$lama, $baru];
}

function kartu(string $label): ?Stat
{
    $widget = new OperationalAlerts;
    $method = new ReflectionMethod($widget, 'getStats');

    foreach ($method->invoke($widget) as $stat) {
        if ($stat->getLabel() === $label) {
            return $stat;
        }
    }

    return null;
}

function masukSebagaiPemilik(object $test): void
{
    $owner = Factory::ownerOf($test->pos->company);
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $test->actingAs($owner, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($test->pos->company);
    app(TenantContext::class)->setTenant($test->pos->company->id);
}

afterEach(fn () => app(TenantContext::class)->reset());

it('menghitung transaksi perlu ditinjau hanya dalam 7 hari terakhir', function () {
    [$lama, $baru] = duaTransaksiDitandai($this);
    masukSebagaiPemilik($this);

    // Keduanya ditandai; hanya satu yang masih di dalam jendela 7 hari.
    expect(Factory::tenant($this->pos->company, fn () => Order::query()->whereRaw("flags <> '[]'::jsonb")->count()))->toBe(2);
    expect(kartu('Transaksi perlu ditinjau')?->getValue())->toBe('1')
        ->and($lama)->not->toBe($baru);
});

it('membuka daftar yang berisi persis sebanyak angka di kartunya', function () {
    [$lama, $baru] = duaTransaksiDitandai($this);
    masukSebagaiPemilik($this);

    $url = (string) kartu('Transaksi perlu ditinjau')?->getUrl();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    /** @var array<string, array<string, mixed>> $filters */
    $filters = $query['tableFilters'] ?? [];

    // Tautannya harus membawa batas tanggal yang sama dengan yang dipakai menghitung.
    expect($filters['flagged']['value'] ?? null)->toBe('1')
        ->and($filters['periode']['from'] ?? null)->toBe(now((string) config('app.display_timezone'))->subDays(6)->format('Y-m-d'));

    $daftar = Livewire::test(ListOrders::class)
        ->set('tableFilters.flagged.value', '1')
        ->set('tableFilters.periode.from', $filters['periode']['from']);

    $records = $daftar->instance()->getFilteredTableQuery()->pluck('id')->all();
    expect($records)->toBe([$baru])
        ->and($records)->not->toContain($lama);
});
