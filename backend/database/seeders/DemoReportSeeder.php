<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Purchasing\Application\GoodsReceiptService;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Reporting\Application\ReportScheduler;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Application\EndOfDayService;
use App\Modules\Sales\Application\OrderVoider;
use App\Modules\Sales\Application\RefundService;
use App\Modules\Sales\Application\ShiftReport;
use App\Modules\Sales\Application\ShiftService;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Riwayat penjualan dua minggu untuk laporan & dashboard (Tahap 5): Kemang (kasir drive-thru) dan Dago,
 * termasuk diskon manual, void, refund, selisih kas, dan tutup hari. Pola acak memakai seed tetap agar data demo
 * selalu sama; semua transaksi dibuat melalui layanan POS yang sama dengan aplikasi.
 */
class DemoReportSeeder extends DemoSalesSeeder
{
    /** Menu, varian, dan bobot peluang dipilih. */
    private const MENU = [
        ['KSTJ-01', 'Regular', 30], ['KSTJ-01', 'Large', 14], ['AMR-01', 'Regular', 12], ['AMR-01', 'Large', 5],
        ['LAT-01', 'Regular', 8], ['KOA-01', null, 10], ['MTL-01', 'Regular', 6], ['TTR-01', null, 6],
        ['CKL-01', null, 4], ['CRS-01', null, 12], ['PGK-01', null, 7], ['NGK-01', null, 5],
    ];

    /** Jam ramai (jam lokal) dan bobotnya. */
    private const HOURS = [7 => 5, 8 => 12, 9 => 9, 10 => 6, 11 => 7, 12 => 12, 13 => 9, 14 => 6, 15 => 7, 16 => 9, 17 => 10, 18 => 8, 19 => 6, 20 => 4];

    private const METHODS = ['cash' => 45, 'qris' => 30, 'debit' => 15, 'credit' => 10];

    /**
     * @param  array{kemang: Outlet, dago: Outlet, kemangManager: User, kemangCashiers: list<User>, dagoManager: User, dagoCashier: User, owner: User, warehouse: User}  $p
     */
    public function run(array $p): void
    {
        mt_srand(20260916);
        $this->history($p['kemang'], 'POS02', $p['kemangCashiers'], $p['kemangManager'], 14, [22, 34]);
        $this->history($p['dago'], 'POS01', [$p['dagoCashier']], $p['dagoManager'], 14, [14, 24], today: true);
        $this->restock($p['warehouse'], [$p['kemang'], $p['dago']]);
        $this->schedules($p['owner'], $p['kemangManager'], $p['kemang']);
        mt_srand();
    }

    /**
     * @param  list<User>  $cashiers
     * @param  array{0: int, 1: int}  $perDay
     */
    private function history(Outlet $outlet, string $deviceCode, array $cashiers, User $manager, int $days, array $perDay, bool $today = false): void
    {
        $outlet = Outlet::query()->findOrFail($outlet->id);
        /** @var Device $device */
        $device = Device::query()->where('outlet_id', $outlet->id)->where('code', $deviceCode)->firstOrFail();
        if (Shift::query()->where('device_id', $device->id)->exists()) {
            return;
        }
        Device::query()->whereKey($device->id)->update(['master_pulled_at' => now()->subDays($days + 1)]);
        $device->refresh()->setRelation('outlet', $outlet);

        $calendar = app(BusinessCalendar::class);
        $shifts = app(ShiftService::class);
        $todayDate = $calendar->today($outlet);

        for ($back = $days; $back >= ($today ? 0 : 2); $back--) {
            $date = $todayDate->subDays($back);
            $isToday = $back === 0;
            $local = fn (string $time) => CarbonImmutable::parse($date->format('Y-m-d').' '.$time, $outlet->timezone)->utc();
            $cashier = $cashiers[$back % count($cashiers)];
            $open = $local('06:45');
            $shift = $shifts->open($device, ['id' => (string) Str::uuid7(), 'cashier_id' => $cashier->id, 'opening_cash' => '500000', 'opened_at' => $open->toIso8601String()]);
            $this->sequence = 0;

            // Akhir pekan lebih ramai.
            $weekend = in_array($date->dayOfWeekIso, [6, 7], true);
            $count = mt_rand($perDay[0], $perDay[1]) + ($weekend ? 6 : 0);
            $now = CarbonImmutable::now();
            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $at = $local(sprintf('%02d:%02d', self::pick(self::HOURS), mt_rand(0, 59)));
                if ($isToday && $at->greaterThan($now->subMinutes(5))) {
                    continue;
                }
                $times[] = $at;
            }
            sort($times);

            $sold = [];
            foreach ($times as $i => $at) {
                $picks = [];
                foreach (range(1, mt_rand(1, 3)) as $_) {
                    [$sku, $variant] = self::MENU[self::pick(array_map(fn ($m) => $m[2], self::MENU))];
                    $picks[] = [$sku, $variant, mt_rand(1, 10) <= 8 ? 1 : 2];
                }
                $method = self::pick(self::METHODS);
                $channel = mt_rand(1, 100) <= 55 ? 'dine_in' : 'take_away';
                $discount = mt_rand(1, 100) <= 6;
                $actor = $discount ? $manager : $cashier;
                request()->attributes->set('pos_user_id', $actor->id);
                $manual = $discount ? ['type' => 'percent', 'value' => (string) (mt_rand(1, 2) * 10), 'reason' => mt_rand(0, 1) ? 'Pelanggan tetap' : 'Kompensasi pesanan lama'] : null;
                try {
                    $sold[] = $this->sell($device, $shift, $actor, $at, $picks, $method, null, $channel, $manual);
                } catch (ValidationException) {
                    // Menu dengan jam jual/ketersediaan khusus: ganti dengan menu yang selalu tersedia.
                    $sold[] = $this->sell($device, $shift, $actor, $at, [['KSTJ-01', 'Regular', 1]], $method, null, $channel, $manual);
                }
            }
            request()->attributes->set('pos_user_id', $manager->id);

            // Sesekali void & refund oleh manajer.
            if (count($sold) > 6 && mt_rand(1, 100) <= 45) {
                $order = $sold[mt_rand(0, count($sold) - 1)];
                app(OrderVoider::class)->void($device, $order->id, [
                    'voided_by' => $manager->id, 'reason' => mt_rand(0, 1) ? 'Salah input menu' : 'Pelanggan batal sebelum diantar',
                    'stock_action' => 'return', 'created_at' => $order->completed_at?->addMinutes(3)->toIso8601String(),
                ]);
            }
            if (count($sold) > 6 && mt_rand(1, 100) <= 30) {
                $order = $sold[mt_rand(0, count($sold) - 1)]->refresh();
                if ($order->status === 'paid' && $order->payments()->value('method') === 'cash') {
                    $line = $order->items()->where('status', 'sold')->orderBy('line_no')->firstOrFail();
                    app(RefundService::class)->refund($device, $order->id, [
                        'id' => (string) Str::uuid7(), 'shift_id' => $shift->id, 'amount' => $this->refundShare($order->load('items'), $line),
                        'method' => 'cash', 'stock_action' => 'waste', 'reason' => 'Minuman tumpah saat diantar',
                        'refunded_by' => $manager->id, 'created_at' => $order->completed_at?->addMinutes(10)->toIso8601String(),
                        'lines' => [['order_item_id' => $line->id, 'qty' => 1]],
                    ]);
                }
            }
            if ($isToday) {
                request()->attributes->remove('pos_user_id');

                continue;
            }

            $report = app(ShiftReport::class)->build($shift->refresh());
            // Selisih Rp2.000 sengaja tidak dipakai: nilai itu milik shift contoh kemarin (DemoSalesSeeder).
            $variance = mt_rand(1, 100) <= 25 ? [-500, -1000, -1500, -3000, -5000][mt_rand(0, 4)] : 0;
            $shifts->close($device, $shift->id, [
                'closed_by' => $cashier->id, 'closed_at' => $local('21:30')->toIso8601String(),
                'counted_cash' => (string) BigDecimal::of($report['cash']['expected'])->plus($variance),
                'variance_note' => $variance === 0 ? null : 'Uang kembalian kurang',
            ]);
            app(EndOfDayService::class)->close($outlet, $date, $manager, 'Operasional normal');
            request()->attributes->remove('pos_user_id');
        }
    }

    /**
     * Belanja rutin: isi ulang bahan yang terpakai penjualan dua minggu agar saldo demo tidak minus.
     * Matcha & boba sengaja tidak diisi agar tetap muncul di "Stok kritis".
     *
     * @param  list<Outlet>  $outlets
     */
    private function restock(User $warehouse, array $outlets): void
    {
        $supplier = Supplier::query()->orderBy('code')->first();
        foreach ($outlets as $outlet) {
            $location = StockLocation::query()->where('outlet_id', $outlet->id)->where('is_default', true)->first();
            if ($location === null) {
                continue;
            }
            $lines = [];
            $balances = StockBalance::query()->with('ingredient')->where('location_id', $location->id)->get();
            foreach ($balances as $balance) {
                $ingredient = $balance->ingredient;
                if ($ingredient === null || in_array($ingredient->code, ['MATCHA', 'BOBA'], true)) {
                    continue;
                }
                $target = BigDecimal::of((string) $ingredient->min_stock)->multipliedBy(3);
                $qty = BigDecimal::of((string) $balance->qty);
                if ($target->isPositive() && $qty->isLessThan($target)) {
                    $price = $ingredient->last_cost ?? $balance->avg_cost;
                    $lines[] = [
                        'ingredient_id' => $ingredient->id, 'unit_name' => $ingredient->base_unit,
                        'qty' => (string) $target->minus($qty)->toScale(0, RoundingMode::UP),
                        'unit_price' => (string) BigDecimal::of((string) ($price ?? '0'))->toScale(2, RoundingMode::HALF_UP),
                    ];
                }
            }
            if ($lines !== []) {
                app(GoodsReceiptService::class)->manual($warehouse, [
                    'location_id' => $location->id, 'supplier_id' => $supplier?->id, 'supplier_invoice_no' => 'Belanja rutin '.$outlet->code,
                    'received_at' => now()->subHours(3)->toIso8601String(), 'lines' => $lines,
                ]);
            }
        }
    }

    private function schedules(User $owner, User $manager, Outlet $kemang): void
    {
        $scheduler = app(ReportScheduler::class);
        auth()->setUser($owner);
        $scheduler->save($owner, [
            'name' => 'Penjualan harian semua outlet', 'report_key' => 'sales.outlet', 'format' => 'xlsx',
            'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['rina@kopinusantara.test'],
        ]);
        $scheduler->save($owner, [
            'name' => 'Pajak bulanan untuk konsultan', 'report_key' => 'tax', 'format' => 'pdf',
            'frequency' => 'monthly', 'send_time' => '08:00', 'recipients' => ['lina@kopinusantara.test', 'pajak@konsultan-mitra.test'],
        ]);
        auth()->setUser($manager);
        $scheduler->save($manager, [
            'name' => 'Anti-fraud mingguan Kemang', 'report_key' => 'fraud', 'format' => 'pdf', 'outlet_id' => $kemang->id,
            'frequency' => 'weekly', 'send_time' => '07:30', 'recipients' => ['dewi@kopinusantara.test'],
        ]);
        auth()->forgetUser();
    }

    /**
     * @param  array<int|string, int>  $weights
     */
    private static function pick(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
