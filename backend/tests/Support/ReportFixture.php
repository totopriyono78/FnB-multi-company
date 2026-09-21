<?php

namespace Tests\Support;

use App\Modules\Sales\Domain\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Skenario laporan dua hari bisnis (Asia/Jakarta) dengan angka yang dihitung manual (ADR 0006).
 *
 * Hari 1 (Kamis 10 Sep 2026), shift A kasir:
 *  - A  kasir, standar: subtotal 68.000, SC 3.400, PB1 7.140, pembulatan −40, total 78.500 (bersih 68.000)
 *  - B  manajer, diskon manual 10%: subtotal 68.000, diskon 6.800, SC 3.060, PB1 6.426, +14, total 70.700 (bersih 61.200)
 *  - C  kasir, standar 78.500, lalu di-void manajer setelah bayar
 *  - shift A ditutup dengan kas kurang Rp500
 * Hari 2 (Jumat 11 Sep 2026), shift B kasir:
 *  - D  kasir, standar 78.500 (bersih 68.000)
 *  - refund A: 1 Croissant oleh manajer = 28.860,29 (bersih 24.999,996…; pajak 2.624,999…; SC 1.249,999…)
 *  - manajer membuka laci tanpa transaksi
 * Transaksi hari 1 selesai pukul 18.56 WIB, hari 2 pukul 05.56 WIB (token perangkat berlaku 12 jam).
 */
final class ReportFixture
{
    public const DAY1 = '2026-09-10';

    public const DAY2 = '2026-09-11';

    public Pos $pos;

    public string $orderA;

    public string $orderB;

    public string $orderC;

    public string $orderD;

    public string $shiftA;

    public string $shiftB;

    public static function build(): self
    {
        $f = new self;
        test()->travelTo(CarbonImmutable::parse(self::DAY1.' 19:00', 'Asia/Jakarta'));
        $pos = $f->pos = Pos::setup();

        [$f->shiftA, $ymd] = $pos->openShift('cashier', '500000');
        $f->orderA = self::accepted($pos->push('order', $pos->order($f->shiftA, $pos->receipt($ymd, 1))))['order_id'];

        $f->orderB = self::accepted($pos->pushAs('manager', 'order', $pos->order($f->shiftA, $pos->receipt($ymd, 2), [
            'cashier_id' => $pos->userId('manager'),
            'order_discounts' => [['type' => 'percent', 'value' => '10', 'source' => 'manual', 'reason' => 'Pelanggan tetap']],
            'totals' => ['subtotal' => '68000', 'item_discount' => '0', 'order_discount' => '6800', 'service_charge' => '3060', 'tax' => '6426', 'rounding' => '14', 'total' => '70700'],
            'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '70700', 'created_at' => now()->subMinutes(4)->toIso8601String()]],
        ])))['order_id'];

        $f->orderC = self::accepted($pos->push('order', $pos->order($f->shiftA, $pos->receipt($ymd, 3))))['order_id'];
        self::accepted($pos->pushAs('manager', 'order.void', [
            'order_id' => $f->orderC, 'voided_by' => $pos->userId('manager'), 'reason' => 'Salah input meja',
            'stock_action' => 'return', 'created_at' => now()->subMinutes(2)->toIso8601String(),
        ]));

        // Kas seharusnya 500.000 + 78.500 + 70.700 = 649.200; dihitung 648.700.
        self::accepted($pos->push('shift.close', [
            'shift_id' => $f->shiftA, 'closed_by' => $pos->userId('cashier'), 'closed_at' => now()->toIso8601String(),
            'counted_cash' => '648700', 'variance_note' => 'Kurang Rp500',
        ]));

        test()->travelTo(CarbonImmutable::parse(self::DAY2.' 06:00', 'Asia/Jakarta'));
        [$f->shiftB, $ymd2] = $pos->openShift('cashier', '500000');
        $f->orderD = self::accepted($pos->push('order', $pos->order($f->shiftB, $pos->receipt($ymd2, 1))))['order_id'];

        $croissantLine = $pos->tenant(fn () => OrderItem::query()
            ->where('order_id', $f->orderA)->where('item_id', $pos->croissant->id)->value('id'));
        self::accepted($pos->pushAs('manager', 'order.refund', [
            'order_id' => $f->orderA, 'shift_id' => $f->shiftB, 'amount' => '28860.29', 'method' => 'cash', 'stock_action' => 'return',
            'reason' => 'Croissant gosong', 'refunded_by' => $pos->userId('manager'), 'created_at' => now()->toIso8601String(),
            'lines' => [['order_item_id' => $croissantLine, 'qty' => 1]],
        ]));

        self::accepted($pos->pushAs('manager', 'cash_movement', [
            'shift_id' => $f->shiftB, 'type' => 'drawer_open', 'reason' => 'Tukar uang kecil',
            'created_by' => $pos->userId('manager'), 'created_at' => now()->toIso8601String(),
        ]));

        return $f;
    }

    /** @return array<string, string> header API back-office untuk staf skenario */
    public function headers(string $who): array
    {
        return $who === 'owner'
            ? asMember(Factory::ownerOf($this->pos->company), $this->pos->company)
            : asMember($this->pos->staff[$who]['user'], $this->pos->company);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function accepted(array $result): array
    {
        expect($result['error'] ?? null)->toBeNull()->and($result['status'])->toBe('accepted');

        return $result;
    }
}
