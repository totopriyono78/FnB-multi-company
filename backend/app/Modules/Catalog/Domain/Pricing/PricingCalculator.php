<?php

namespace App\Modules\Catalog\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Kalkulator total transaksi sesuai SRS §8.1 dan ADR 0003.
 *
 * Fungsi murni tanpa akses database agar identik dengan implementasi Dart di POS (BR-05).
 * Input/keluaran memakai string desimal; lihat shared/fixtures/pricing untuk contoh.
 */
final class PricingCalculator
{
    private const SCALE = 2;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function calculate(array $input): array
    {
        $config = $this->config($input['config'] ?? []);
        $lines = $input['lines'] ?? [];
        if (! is_array($lines)) {
            throw new InvalidArgumentException('lines harus berupa daftar.');
        }

        // 1–2: gross dan diskon item per baris (presisi penuh).
        $rows = [];
        $grossTotal = BigDecimal::zero();
        $itemDiscountExact = BigDecimal::zero();
        $discountSources = [];

        foreach (array_values($lines) as $index => $line) {
            $row = $this->line($line, $index);
            $remaining = $row['gross'];
            $lineDiscount = BigDecimal::zero();

            foreach ($line['discounts'] ?? [] as $discount) {
                $amount = $this->discountAmount($discount, $remaining);
                $remaining = $remaining->minus($amount);
                $lineDiscount = $lineDiscount->plus($amount);
                $this->addSource($discountSources, $discount, 'item', $amount);
            }

            $row['item_discount_exact'] = $lineDiscount;
            $row['after_item_exact'] = $remaining;
            $rows[] = $row;
            $grossTotal = $grossTotal->plus($row['gross']);
            $itemDiscountExact = $itemDiscountExact->plus($lineDiscount);
        }

        $subtotal = $this->round($grossTotal);
        $itemDiscount = $this->round($itemDiscountExact);
        $afterItem = $subtotal->minus($itemDiscount);

        // 3: diskon transaksi berurutan pada subtotal setelah diskon item.
        $remaining = $afterItem;
        $orderDiscount = BigDecimal::zero();
        foreach ($input['order_discounts'] ?? [] as $discount) {
            $amount = $this->round($this->discountAmount($discount, $remaining));
            $remaining = $remaining->minus($amount);
            $orderDiscount = $orderDiscount->plus($amount);
            $this->addSource($discountSources, $discount, 'order', $amount);
        }

        // 4: dasar service charge.
        $base = $afterItem->minus($orderDiscount);
        $taxRate = $config['tax_rate'];
        $scRate = $config['service_charge_applies'] ? $config['service_charge_rate'] : BigDecimal::zero();

        if ($config['tax_inclusive']) {
            $itemTax = $this->round($base->multipliedBy($taxRate)->dividedBy($taxRate->plus(100), 20, RoundingMode::HALF_UP));
            $dpp = $base->minus($itemTax);
            $serviceCharge = $this->percent($dpp, $scRate);
            $scTax = $config['tax_on_service_charge'] ? $this->percent($serviceCharge, $taxRate) : BigDecimal::zero();
            $tax = $itemTax->plus($scTax);
        } else {
            $dpp = $base;
            $serviceCharge = $this->percent($dpp, $scRate);
            $taxBase = $config['tax_on_service_charge'] ? $dpp->plus($serviceCharge) : $dpp;
            $tax = $this->percent($taxBase, $taxRate);
        }

        $beforeRounding = $dpp->plus($serviceCharge)->plus($tax);
        $rounded = $this->roundToUnit($beforeRounding, $config['rounding_unit'], $config['rounding_mode']);
        $rounding = $rounded->minus($beforeRounding);

        return [
            'lines' => $this->allocate($rows, $itemDiscount, $orderDiscount),
            'totals' => [
                'subtotal' => $this->str($subtotal),
                'item_discount' => $this->str($itemDiscount),
                'order_discount' => $this->str($orderDiscount),
                'discount' => $this->str($itemDiscount->plus($orderDiscount)),
                'service_charge_base' => $this->str($dpp),
                'service_charge' => $this->str($serviceCharge),
                'tax_base' => $this->str($config['tax_on_service_charge'] ? $dpp->plus($serviceCharge) : $dpp),
                'tax' => $this->str($tax),
                'total_before_rounding' => $this->str($beforeRounding),
                'rounding' => $this->str($rounding),
                'total' => $this->str($rounded),
            ],
            'discounts' => array_values(array_map(fn (array $s) => [
                'source' => $s['source'],
                'scope' => $s['scope'],
                'amount' => $this->str($this->round($s['amount'])),
            ], $discountSources)),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{tax_rate: BigDecimal, tax_inclusive: bool, tax_on_service_charge: bool, service_charge_rate: BigDecimal, service_charge_applies: bool, rounding_unit: int, rounding_mode: string}
     */
    private function config(array $config): array
    {
        $taxRate = $this->decimal($config['tax_rate'] ?? '0', 'tax_rate');
        $scRate = $this->decimal($config['service_charge_rate'] ?? '0', 'service_charge_rate');
        foreach (['tax_rate' => $taxRate, 'service_charge_rate' => $scRate] as $name => $rate) {
            if ($rate->isNegative() || $rate->isGreaterThan(100)) {
                throw new InvalidArgumentException("{$name} harus 0–100.");
            }
        }

        $unit = (int) ($config['rounding_unit'] ?? 100);
        $mode = (string) ($config['rounding_mode'] ?? 'nearest');
        if ($unit < 0 || ! in_array($mode, ['nearest', 'up', 'down'], true)) {
            throw new InvalidArgumentException('Aturan pembulatan tidak valid.');
        }

        return [
            'tax_rate' => $taxRate,
            'tax_inclusive' => (bool) ($config['tax_inclusive'] ?? false),
            'tax_on_service_charge' => (bool) ($config['tax_on_service_charge'] ?? true),
            'service_charge_rate' => $scRate,
            'service_charge_applies' => (bool) ($config['service_charge_applies'] ?? true),
            'rounding_unit' => $unit,
            'rounding_mode' => $mode,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{id: string, gross: BigDecimal}
     */
    private function line(array $line, int $index): array
    {
        $qty = $this->decimal($line['qty'] ?? '1', "lines[{$index}].qty");
        if (! $qty->isPositive()) {
            throw new InvalidArgumentException("lines[{$index}].qty harus lebih dari 0.");
        }

        $unit = $this->decimal($line['unit_price'] ?? '0', "lines[{$index}].unit_price");

        /*
         * Barang yang dijual per berat: `qty` adalah BERAT, bukan jumlah butir. Tambahan
         * (mis. bumbu asam manis Rp 15.000) dikenakan SEKALI untuk baris itu, bukan dikali
         * berat — kalau tidak, satu gurame 850 gram akan ditagih 850 x harga bumbu.
         * Untuk barang biasa, tambahan tetap mengikuti jumlah butir seperti sebelumnya.
         */
        $perBerat = (bool) ($line['sold_by_weight'] ?? false);
        $extras = BigDecimal::zero();
        foreach ($line['modifiers'] ?? [] as $m => $modifier) {
            $price = $this->decimal($modifier['price'] ?? '0', "lines[{$index}].modifiers[{$m}].price");
            $modQty = $this->decimal($modifier['qty'] ?? '1', "lines[{$index}].modifiers[{$m}].qty");
            $extras = $extras->plus($price->multipliedBy($modQty));
        }

        if (! $perBerat) {
            $unit = $unit->plus($extras);
        }
        if ($unit->isNegative()) {
            throw new InvalidArgumentException("lines[{$index}] memiliki harga negatif.");
        }

        $gross = $unit->multipliedBy($qty);
        if ($perBerat) {
            $gross = $gross->plus($extras);
        }
        if ($gross->isNegative()) {
            throw new InvalidArgumentException("lines[{$index}] memiliki harga negatif.");
        }

        return [
            'id' => (string) ($line['id'] ?? $index),
            'gross' => $gross,
        ];
    }

    /**
     * @param  array<string, mixed>  $discount
     */
    private function discountAmount(array $discount, BigDecimal $remaining): BigDecimal
    {
        $value = $this->decimal($discount['value'] ?? '0', 'discount.value');
        if ($value->isNegative()) {
            throw new InvalidArgumentException('Nilai diskon tidak boleh negatif.');
        }

        $amount = match ($discount['type'] ?? null) {
            'percent' => $value->isGreaterThan(100)
                ? throw new InvalidArgumentException('Diskon persen maksimal 100.')
                : $remaining->multipliedBy($value)->dividedBy(100, 20, RoundingMode::HALF_UP),
            'amount' => $value,
            default => throw new InvalidArgumentException('Jenis diskon harus percent atau amount.'),
        };

        return $amount->isGreaterThan($remaining) ? $remaining : $amount;
    }

    /**
     * @param  array<string, array{source: string, scope: string, amount: BigDecimal}>  $sources
     * @param  array<string, mixed>  $discount
     */
    private function addSource(array &$sources, array $discount, string $scope, BigDecimal $amount): void
    {
        $source = (string) ($discount['source'] ?? 'manual');
        $key = $scope.'|'.$source;
        $sources[$key] ??= ['source' => $source, 'scope' => $scope, 'amount' => BigDecimal::zero()];
        $sources[$key]['amount'] = $sources[$key]['amount']->plus($amount);
    }

    /**
     * @param  list<array{id: string, gross: BigDecimal, item_discount_exact: BigDecimal, after_item_exact: BigDecimal}>  $rows
     * @return list<array<string, string>>
     */
    private function allocate(array $rows, BigDecimal $itemDiscount, BigDecimal $orderDiscount): array
    {
        $gross = Allocation::distribute(array_map(fn ($r) => $r['gross'], $rows), $this->round(array_reduce($rows, fn ($c, $r) => $c->plus($r['gross']), BigDecimal::zero())));
        $itemAlloc = Allocation::distribute(array_map(fn ($r) => $r['item_discount_exact'], $rows), $itemDiscount);
        $afterItem = [];
        foreach ($rows as $i => $row) {
            $afterItem[$i] = $gross[$i]->minus($itemAlloc[$i]);
        }
        $orderAlloc = Allocation::distribute($afterItem, $orderDiscount);

        $out = [];
        foreach ($rows as $i => $row) {
            $out[] = [
                'id' => $row['id'],
                'gross' => $this->str($gross[$i]),
                'item_discount' => $this->str($itemAlloc[$i]),
                'order_discount' => $this->str($orderAlloc[$i]),
                'net' => $this->str($afterItem[$i]->minus($orderAlloc[$i])),
            ];
        }

        return $out;
    }

    private function percent(BigDecimal $amount, BigDecimal $rate): BigDecimal
    {
        return $this->round($amount->multipliedBy($rate)->dividedBy(100, 20, RoundingMode::HALF_UP));
    }

    private function roundToUnit(BigDecimal $amount, int $unit, string $mode): BigDecimal
    {
        if ($unit === 0) {
            return $amount;
        }

        $rounding = match ($mode) {
            'up' => RoundingMode::CEILING,
            'down' => RoundingMode::FLOOR,
            default => RoundingMode::HALF_UP,
        };

        return $amount->dividedBy($unit, 0, $rounding)->multipliedBy($unit)->toScale(self::SCALE);
    }

    private function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    private function decimal(mixed $value, string $field): BigDecimal
    {
        if (is_int($value)) {
            return BigDecimal::of($value);
        }
        if (! is_string($value) || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("{$field} harus angka desimal dalam bentuk teks.");
        }

        return BigDecimal::of($value);
    }

    private function str(BigDecimal $value): string
    {
        return (string) $value->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
