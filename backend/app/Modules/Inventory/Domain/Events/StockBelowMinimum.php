<?php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Saldo bahan turun di bawah batas minimum lokasi (FR-INV-08). Listener berikutnya: notifikasi push (Owner App). */
final class StockBelowMinimum
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $ingredientId,
        public readonly string $qty,
        public readonly string $minQty,
    ) {}
}
