<?php

namespace App\Modules\Catalog\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/** Pembagian nilai 2 desimal secara proporsional dengan metode sisa terbesar (ADR 0003). */
final class Allocation
{
    /**
     * @param  array<int|string, BigDecimal>  $weights
     * @return array<int|string, BigDecimal>
     */
    public static function distribute(array $weights, BigDecimal $total): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = array_reduce($weights, fn (BigDecimal $c, BigDecimal $w) => $c->plus($w), BigDecimal::zero());
        if ($sum->isZero()) {
            return array_map(fn () => BigDecimal::zero()->toScale(2), $weights);
        }

        $cents = $total->toScale(2, RoundingMode::HALF_UP)->multipliedBy(100)->toScale(0)->toBigInteger();
        $alloc = [];
        $fractions = [];
        $used = BigInteger::zero();
        foreach ($weights as $i => $w) {
            $exact = $w->multipliedBy($cents)->dividedBy($sum, 20, RoundingMode::HALF_UP);
            $floor = $exact->toScale(0, RoundingMode::FLOOR);
            $alloc[$i] = $floor->toBigInteger();
            $fractions[$i] = $exact->minus($floor);
            $used = $used->plus($alloc[$i]);
        }

        $keys = array_keys($weights);
        $position = array_flip($keys);
        usort($keys, fn ($a, $b) => $fractions[$b]->compareTo($fractions[$a]) ?: $position[$a] <=> $position[$b]);
        $left = $cents->minus($used)->toInt();
        for ($k = 0; $k < $left; $k++) {
            $i = $keys[$k % count($keys)];
            $alloc[$i] = $alloc[$i]->plus(1);
        }

        return array_map(fn (BigInteger $c) => BigDecimal::ofUnscaledValue($c, 2), $alloc);
    }
}
