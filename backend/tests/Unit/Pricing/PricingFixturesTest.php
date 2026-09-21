<?php

use App\Modules\Catalog\Domain\Pricing\PricingCalculator;
use Brick\Math\BigDecimal;

function pricingFixtures(): array
{
    $dir = dirname(__DIR__, 4).'/shared/fixtures/pricing';
    $files = glob($dir.'/*.json') ?: [];
    sort($files);

    $cases = [];
    foreach ($files as $file) {
        $fixture = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $cases[$fixture['name'].' — '.$fixture['description']] = [$fixture];
    }

    return $cases;
}

it('memiliki fixture bersama', function () {
    expect(count(pricingFixtures()))->toBeGreaterThanOrEqual(20);
});

it('menghasilkan angka yang sama dengan fixture bersama (BR-05)', function (array $fixture) {
    $result = (new PricingCalculator)->calculate($fixture['input']);
    $expected = $fixture['expected'];

    foreach ($expected['totals'] ?? [] as $key => $value) {
        expect($result['totals'][$key])->toBe($value, "totals.{$key}");
    }
    if (isset($expected['lines'])) {
        expect($result['lines'])->toBe($expected['lines']);
    }
    if (isset($expected['discounts'])) {
        expect($result['discounts'])->toBe($expected['discounts']);
    }

    // Invarian: total = dasar + SC + pajak + pembulatan, dan jumlah baris = dasar (bila tidak termasuk pajak).
    $t = $result['totals'];
    $sum = BigDecimal::of($t['service_charge_base'])->plus($t['service_charge'])->plus($t['tax'])->plus($t['rounding']);
    expect((string) $sum)->toBe($t['total']);
    $lineNet = array_reduce($result['lines'], fn (BigDecimal $c, $l) => $c->plus($l['net']), BigDecimal::of('0.00'));
    expect((string) $lineNet)->toBe((string) BigDecimal::of($t['subtotal'])->minus($t['discount']));
})->with(pricingFixtures());

it('menolak input tidak valid', function (array $input, string $message) {
    expect(fn () => (new PricingCalculator)->calculate($input))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'harga float' => [['lines' => [['unit_price' => 18000.5, 'qty' => '1']]], 'unit_price'],
    'qty nol' => [['lines' => [['unit_price' => '1000', 'qty' => '0']]], 'qty'],
    'harga negatif' => [['lines' => [['unit_price' => '-1000', 'qty' => '1']]], 'negatif'],
    'diskon persen > 100' => [['lines' => [['unit_price' => '1000', 'qty' => '1', 'discounts' => [['type' => 'percent', 'value' => '120']]]]], '100'],
    'jenis diskon asing' => [['lines' => [['unit_price' => '1000', 'qty' => '1', 'discounts' => [['type' => 'gratis', 'value' => '1']]]]], 'Jenis diskon'],
    'tarif pajak > 100' => [['config' => ['tax_rate' => '150'], 'lines' => []], 'tax_rate'],
    'mode pembulatan asing' => [['config' => ['rounding_mode' => 'acak'], 'lines' => []], 'pembulatan'],
]);

it('menangani keranjang kosong', function () {
    $result = (new PricingCalculator)->calculate(['config' => ['tax_rate' => '10'], 'lines' => []]);

    expect($result['totals']['total'])->toBe('0.00')->and($result['lines'])->toBe([]);
});
