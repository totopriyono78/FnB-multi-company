<?php

use App\Modules\Shared\Domain\Money;

it('menjumlahkan tanpa kehilangan presisi', function () {
    $total = Money::of('0.10')->plus(Money::of('0.20'));

    expect($total->toDecimalString())->toBe('0.30')
        ->and($total->equals(Money::of('0.3')))->toBeTrue();
});

it('menghitung persentase dengan presisi penuh lalu membulatkan di akhir', function () {
    // PB1 10% dari Rp 36.000 = Rp 3.600
    expect(Money::of('36000')->percentage('10')->toDecimalString())->toBe('3600.00');
    // 11% dari 18.555 = 2.041,05
    expect(Money::of('18555')->percentage('11')->toDecimalString())->toBe('2041.05');
    // 12,5% dari 0,05 = 0,00625 -> 0,01 (half up)
    expect(Money::of('0.05')->percentage('12.5')->rounded()->toDecimalString())->toBe('0.01');
});

it('membulatkan ke kelipatan rupiah sesuai mode outlet', function (string $amount, int $unit, string $mode, string $expected) {
    expect(Money::of($amount)->roundToUnit($unit, $mode)->toDecimalString())->toBe($expected);
})->with([
    'terdekat ke bawah' => ['39649.99', 100, 'nearest', '39600.00'],
    'terdekat tepat tengah' => ['39650', 100, 'nearest', '39700.00'],
    'ke atas' => ['39601', 100, 'up', '39700.00'],
    'ke bawah' => ['39699', 100, 'down', '39600.00'],
    'kelipatan 500' => ['63800', 500, 'nearest', '64000.00'],
    'tanpa pembulatan' => ['63800.456', 0, 'nearest', '63800.46'],
]);

it('memformat rupiah sesuai kebiasaan lokal', function () {
    expect(Money::of('18000')->format())->toBe('Rp 18.000')
        ->and(Money::of('1250000.50')->format())->toBe('Rp 1.250.001')
        ->and(Money::of('-36200')->format())->toBe('-Rp 36.200')
        ->and(Money::of('0')->format())->toBe('Rp 0');
});

it('menolak nominal tidak valid dan mata uang berbeda', function () {
    expect(fn () => Money::of('1e3'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('12,5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1000')->plus(Money::of('1', 'USD')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1000')->roundToUnit(100, 'acak'))->toThrow(InvalidArgumentException::class);
});

it('membandingkan dan mengurangi nilai', function () {
    $a = Money::of('20000');
    $b = Money::of('36200');

    expect($a->compareTo($b))->toBe(-1)
        ->and($a->minus($b)->isNegative())->toBeTrue()
        ->and($a->minus($a)->isZero())->toBeTrue()
        ->and($a->multipliedBy(2)->toDecimalString())->toBe('40000.00')
        ->and(json_encode(['total' => $b]))->toBe('{"total":"36200.00"}');
});
