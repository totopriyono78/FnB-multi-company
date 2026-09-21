<?php

namespace App\Modules\Catalog\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Data menu/harga/promo berubah; dipakai untuk sinyal tarik data ke POS (Tahap 3). */
final class MenuChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $entity,
        public readonly string $entityId,
    ) {}
}
