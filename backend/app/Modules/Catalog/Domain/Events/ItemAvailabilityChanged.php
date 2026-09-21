<?php

namespace App\Modules\Catalog\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Status habis berubah (FR-MENU-08). Listener berikutnya: siaran WebSocket ke POS/self-order
 * dan sinkronisasi ke aggregator (Fase 2).
 */
final class ItemAvailabilityChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $outletId,
        public readonly string $itemId,
        public readonly bool $soldOut,
    ) {}
}
