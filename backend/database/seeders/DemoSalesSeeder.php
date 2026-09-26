<?php

namespace Database\Seeders;

use App\Modules\Catalog\Application\QuoteService;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Payment\Application\Gateways\GatewayStatus;
use App\Modules\Payment\Application\PaymentIntentService;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Application\EndOfDayService;
use App\Modules\Sales\Application\OrderRecorder;
use App\Modules\Sales\Application\OrderVoider;
use App\Modules\Sales\Application\ReceiptNumber;
use App\Modules\Sales\Application\RefundService;
use App\Modules\Sales\Application\ShiftReport;
use App\Modules\Sales\Application\ShiftService;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Transaksi contoh untuk Hamzah Coffee Kaliurang (Tahap 3): shift kemarin yang sudah ditutup beserta tutup hari,
 * dan shift hari ini yang masih berjalan. Semua data dibuat melalui layanan yang sama dengan POS.
 */
class DemoSalesSeeder
{
    protected int $sequence = 0;

    public function kaliurang(Outlet $outlet, User $manager, User $cashierA, User $cashierB): void
    {
        // Model dari seeder belum memuat nilai bawaan kolom (zona waktu, pajak).
        $outlet = Outlet::query()->findOrFail($outlet->id);
        /** @var Device $device */
        $device = Device::query()->where('outlet_id', $outlet->id)->where('code', 'POS01')->firstOrFail();
        $device->setRelation('outlet', $outlet);
        if (Shift::query()->where('device_id', $device->id)->exists()) {
            return;
        }

        // Perangkat sudah menarik data master (acuan validasi harga).
        Device::query()->whereKey($device->id)->update(['master_pulled_at' => now()->subDays(2)]);
        $device->refresh()->setRelation('outlet', $outlet);

        $calendar = app(BusinessCalendar::class);
        $today = $calendar->today($outlet);
        $yesterday = $today->subDay();
        $localStart = fn (CarbonImmutable $date, string $time) => CarbonImmutable::parse($date->format('Y-m-d').' '.$time, $outlet->timezone)->utc();

        // Kemarin: shift Andi 07.00–15.30, lengkap dengan void, refund, dan QRIS.
        $shifts = app(ShiftService::class);
        $open = $localStart($yesterday, '07:00');
        $shift = $shifts->open($device, ['id' => (string) Str::uuid7(), 'cashier_id' => $cashierA->id, 'opening_cash' => '500000', 'opened_at' => $open->toIso8601String()]);

        $this->sequence = 0;
        $this->sell($device, $shift, $cashierA, $open->addMinutes(35), [['KSH-01', 'Regular', 2], ['CRS-01', null, 1]], 'cash', '100000');
        $this->sell($device, $shift, $cashierA, $open->addMinutes(80), [['AMR-01', 'Large', 1]], 'debit');
        $this->sell($device, $shift, $cashierA, $open->addHours(2), [['KOA-01', null, 3]], 'qris');
        $this->sell($device, $shift, $cashierA, $open->addHours(3), [['MTL-01', 'Regular', 1], ['PGK-01', null, 2]], 'cash', '100000', 'take_away');
        $voided = $this->sell($device, $shift, $cashierA, $open->addHours(4), [['TTR-01', null, 2]], 'cash', '50000');
        $this->sell($device, $shift, $cashierA, $open->addHours(5), [['NGK-01', null, 2], ['KSH-01', 'Regular', 2]], 'credit');
        $refunded = $this->sell($device, $shift, $cashierA, $open->addHours(6), [['CKL-01', null, 1], ['CRS-01', null, 2]], 'cash', '100000');
        $this->sell($device, $shift, $cashierA, $open->addHours(6)->addMinutes(30), [['KSH-01', 'Large', 2]], 'cash', '60000'); // sebelum Happy Hour 14.00

        $shifts->recordCash($device, [
            'id' => (string) Str::uuid7(), 'shift_id' => $shift->id, 'type' => 'out', 'amount' => '35000',
            'reason' => 'Beli es batu & galon', 'created_by' => $cashierA->id, 'created_at' => $open->addHours(3)->addMinutes(20)->toIso8601String(),
        ]);

        // Void & refund dilakukan manajer yang login PIN di perangkat (pelaku terverifikasi).
        request()->attributes->set('pos_user_id', $manager->id);
        app(OrderVoider::class)->void($device, $voided->id, [
            'voided_by' => $manager->id, 'reason' => 'Salah input menu, pelanggan pesan Teh Tarik panas',
            'created_at' => $open->addHours(4)->addMinutes(3)->toIso8601String(),
        ]);

        $croissantLine = $refunded->items()->where('sku', 'CRS-01')->firstOrFail();
        // Refund 1 dari 2 croissant: porsi baris terhadap total bayar (rumus yang sama dengan RefundService).
        $expected = $this->refundShare($refunded, $croissantLine);
        app(RefundService::class)->refund($device, $refunded->id, [
            'id' => (string) Str::uuid7(), 'shift_id' => $shift->id, 'amount' => $expected, 'method' => 'cash', 'stock_action' => 'waste',
            'reason' => 'Croissant gosong', 'refunded_by' => $manager->id, 'created_at' => $open->addHours(6)->addMinutes(10)->toIso8601String(),
            'lines' => [['order_item_id' => $croissantLine->id, 'qty' => 1]],
        ]);
        request()->attributes->remove('pos_user_id');

        $report = app(ShiftReport::class)->build($shift->refresh());
        $counted = (string) BigDecimal::of($report['cash']['expected'])->minus(2000);
        $shifts->close($device, $shift->id, [
            'closed_by' => $cashierA->id, 'closed_at' => $open->addHours(8)->addMinutes(30)->toIso8601String(),
            'counted_cash' => $counted, 'variance_note' => 'Selisih uang receh Rp2.000',
        ]);

        app(EndOfDayService::class)->close($outlet, $yesterday, $manager, 'Operasional normal');

        // Hari ini: shift Siti masih berjalan.
        $now = CarbonImmutable::now();
        $dayStart = $localStart($today, substr((string) ($outlet->business_day_cutoff ?: '00:00'), 0, 5));
        $todayOpen = max($dayStart, min($localStart($today, '07:00'), $now->subHours(2)));
        $at = fn (int $minutes) => min($todayOpen->addMinutes($minutes), $now);
        $shiftToday = $shifts->open($device, ['id' => (string) Str::uuid7(), 'cashier_id' => $cashierB->id, 'opening_cash' => '500000', 'opened_at' => $todayOpen->toIso8601String()]);
        $this->sequence = 0;
        $this->sell($device, $shiftToday, $cashierB, $at(20), [['KSH-01', 'Regular', 1], ['CRS-01', null, 1]], 'cash', '50000');
        $this->sell($device, $shiftToday, $cashierB, $at(45), [['AMR-01', 'Regular', 2]], 'qris');
        $this->sell($device, $shiftToday, $cashierB, $at(70), [['MTL-01', 'Large', 1]], 'debit');
    }

    /**
     * @param  list<array{0: string, 1: ?string, 2: int}>  $picks  [sku, varian, qty]
     * @param  array{type: string, value: string, reason: string}|null  $manualDiscount
     */
    protected function sell(Device $device, Shift $shift, User $cashier, CarbonImmutable $at, array $picks, string $method, ?string $tendered = null, string $channel = 'dine_in', ?array $manualDiscount = null): Order
    {
        $lines = [];
        foreach ($picks as [$sku, $variant, $qty]) {
            /** @var Item $item */
            $item = Item::query()->with('variants', 'modifierGroups.modifiers')->where('sku', $sku)->where('brand_id', $device->outlet->brand_id)->firstOrFail();
            $modifiers = [];
            foreach ($item->modifierGroups as $group) {
                if ($group->min_select > 0) {
                    $default = $group->modifiers->firstWhere('is_default', true) ?? $group->modifiers->first();
                    if ($default !== null) {
                        $modifiers[] = ['id' => $default->id, 'qty' => 1];
                    }
                }
            }
            $lines[] = [
                'id' => (string) Str::uuid7(),
                'item_id' => $item->id,
                'variant_id' => $variant === null ? null : $item->variants->firstWhere('name', $variant)?->id,
                'qty' => $qty,
                'modifiers' => $modifiers,
            ];
        }

        $quote = app(QuoteService::class)->quote($device->outlet, [
            'channel_code' => $channel, 'lines' => $lines, 'at' => $at->toIso8601String(), 'payment_method' => $method,
            'order_discounts' => $manualDiscount === null ? [] : [['type' => $manualDiscount['type'], 'value' => $manualDiscount['value']]],
        ]);
        $total = (string) $quote['totals']['total'];
        $orderId = (string) Str::uuid7();

        $payment = ['id' => (string) Str::uuid7(), 'method' => $method, 'amount' => $total, 'created_at' => $at->addMinute()->toIso8601String()];
        if ($method === 'cash') {
            $payment['tendered'] = $tendered !== null && (float) $tendered >= (float) $total ? $tendered : $total;
        }
        if ($method === 'qris') {
            $intents = app(PaymentIntentService::class);
            $intent = $intents->create($device, $cashier, ['order_ref' => $orderId, 'method' => 'qris', 'amount' => $total]);
            $intent = $intents->transition($intent, GatewayStatus::PAID, $total, $at->addMinute());
            $payment['payment_intent_id'] = $intent->id;
            $payment['reference'] = $intent->provider_reference;
        }

        $this->sequence++;

        return app(OrderRecorder::class)->record($device, [
            'id' => $orderId,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'receipt_no' => ReceiptNumber::make($device->outlet, $device, $shift->business_date, $this->sequence),
            'queue_no' => $this->sequence,
            'channel_code' => $channel,
            'status' => 'paid',
            'created_at' => $at->toIso8601String(),
            'completed_at' => $at->addMinute()->toIso8601String(),
            'pricing' => [
                'tax_name' => $device->outlet->tax_name,
                'tax_rate' => (string) $device->outlet->tax_rate,
                'tax_inclusive' => $device->outlet->tax_inclusive,
                'tax_on_service_charge' => $device->outlet->tax_on_service_charge,
                'service_charge_rate' => (string) $device->outlet->service_charge_rate,
                'service_charge_applies' => (bool) SalesChannel::query()->where('code', $channel)->value('service_charge_applies'),
                'rounding_unit' => $device->outlet->rounding_unit,
                'rounding_mode' => $device->outlet->rounding_mode,
            ],
            'lines' => array_map(fn (array $l) => [
                'id' => $l['id'],
                'item_id' => $l['item_id'],
                'variant_id' => $l['variant']['id'] ?? null,
                'name' => $l['name'],
                'variant_name' => $l['variant']['name'] ?? null,
                'qty' => $l['qty'],
                'unit_price' => $l['unit_price'],
                'modifiers' => array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'price' => $m['price'], 'qty' => $m['qty']], $l['modifiers']),
                'discounts' => array_map(fn ($d) => ['type' => $d['type'], 'value' => $d['value'], 'source' => $d['source']], $l['discounts']),
            ], $quote['lines']),
            'order_discounts' => $manualDiscount === null ? [] : [$manualDiscount + ['source' => 'manual']],
            'totals' => array_intersect_key($quote['totals'], array_flip(OrderRecorder::TOTAL_KEYS)),
            'payments' => [$payment],
        ]);
    }

    protected function refundShare(Order $order, OrderItem $line): string
    {
        $netTotal = BigDecimal::zero();
        foreach ($order->items as $item) {
            $netTotal = $netTotal->plus((string) $item->net);
        }

        return (string) BigDecimal::of((string) $line->net)->multipliedBy((string) $order->total)
            ->dividedBy(BigDecimal::of((string) $line->qty)->multipliedBy($netTotal), 2, RoundingMode::HALF_UP);
    }
}
