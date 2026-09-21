<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\Ingredient;
use Brick\Math\BigDecimal;

/** Konversi satuan beli ke satuan dasar bahan (FR-INV-01). */
class UnitConverter
{
    /**
     * Faktor konversi satuan `$unit` (nama satuan dasar = 1).
     */
    public function factor(Ingredient $ingredient, string $unit, string $field = 'unit_name'): BigDecimal
    {
        if (mb_strtolower($unit) === mb_strtolower($ingredient->base_unit)) {
            return BigDecimal::one();
        }
        $ingredient->loadMissing('units');
        foreach ($ingredient->units as $u) {
            if (mb_strtolower($u->name) === mb_strtolower($unit)) {
                return BigDecimal::of((string) $u->factor);
            }
        }

        throw new InventoryException('UNIT_UNKNOWN', "Satuan \"{$unit}\" belum terdaftar untuk {$ingredient->name}.", 422, $field);
    }

    /** @return array<string, string> nama satuan => faktor */
    public function options(Ingredient $ingredient): array
    {
        $ingredient->loadMissing('units');
        $options = [$ingredient->base_unit => '1'];
        foreach ($ingredient->units as $u) {
            $options[$u->name] = (string) BigDecimal::of((string) $u->factor)->strippedOfTrailingZeros();
        }

        return $options;
    }
}
