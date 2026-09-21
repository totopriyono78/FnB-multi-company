<?php

use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Application\ReceiptNumber;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;

function outletWith(string $cutoff, string $tz = 'Asia/Jakarta'): Outlet
{
    $outlet = new Outlet;
    $outlet->setRawAttributes(['code' => 'KMG', 'timezone' => $tz, 'business_day_cutoff' => $cutoff]);

    return $outlet;
}

it('hari bisnis mengikuti jam pergantian outlet (BR-20)', function (string $utc, string $cutoff, string $tz, string $expected) {
    $date = (new BusinessCalendar)->businessDate(outletWith($cutoff, $tz), CarbonImmutable::parse($utc));

    expect($date->format('Y-m-d'))->toBe($expected);
})->with([
    // 16 Sep 01:30 WIB (15 Sep 18:30 UTC), pergantian 04:00 → masih hari 15
    ['2026-09-15T18:30:00Z', '04:00:00', 'Asia/Jakarta', '2026-09-15'],
    // 16 Sep 04:00 WIB tepat → hari 16
    ['2026-09-15T21:00:00Z', '04:00:00', 'Asia/Jakarta', '2026-09-16'],
    // Pergantian 00:00 → tanggal kalender lokal
    ['2026-09-15T17:05:00Z', '00:00', 'Asia/Jakarta', '2026-09-16'],
    // WITA (UTC+8): 16 Sep 02:00 lokal, pergantian 03:00 → 15
    ['2026-09-15T18:00:00Z', '03:00', 'Asia/Makassar', '2026-09-15'],
]);

it('format nomor struk {OUTLET}-{PERANGKAT}-{YYMMDD}-{URUT} (FR-POS-24)', function () {
    $device = new Device;
    $device->setRawAttributes(['code' => 'POS01']);
    $date = CarbonImmutable::parse('2026-09-16');
    $outlet = outletWith('00:00');

    expect(ReceiptNumber::make($outlet, $device, $date, 7))->toBe('KMG-POS01-260916-0007')
        ->and(ReceiptNumber::matches('KMG-POS01-260916-0007', $outlet, $device, $date))->toBeTrue()
        ->and(ReceiptNumber::matches('KMG-POS01-260916-120045', $outlet, $device, $date))->toBeTrue()
        ->and(ReceiptNumber::matches('KMG-POS01-260915-0007', $outlet, $device, $date))->toBeFalse()
        ->and(ReceiptNumber::matches('KMG-POS02-260916-0007', $outlet, $device, $date))->toBeFalse()
        ->and(ReceiptNumber::matches('KMG-POS01-260916-07', $outlet, $device, $date))->toBeFalse()
        ->and(ReceiptNumber::matches('KMG-POS01-260916-00x7', $outlet, $device, $date))->toBeFalse();
});

it('menghitung MDR persen + biaya tetap dibulatkan ke sen (FR-PAY-10)', function () {
    $config = new OutletPaymentMethod;
    $config->setRawAttributes(['mdr_percent' => '0.7', 'mdr_fixed' => '0']);
    // 0,7% × 78.500 = 549,50
    expect(PaymentMethods::mdr($config, '78500'))->toBe('549.50');

    $config->setRawAttributes(['mdr_percent' => '1.5', 'mdr_fixed' => '250']);
    // 1,5% × 33.333 = 499,995 → 500,00 + 250 = 750,00
    expect(PaymentMethods::mdr($config, '33333'))->toBe('750.00')
        ->and(PaymentMethods::mdr(null, '1000'))->toBe('0.00');
});
