<?php

namespace App\Modules\Sales\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Refund dicatat (FR-POS-17). Listener berikutnya: stok kembali/waste (Tahap 4). */
final class OrderRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $orderId,
        public readonly string $refundId,
        public readonly string $stockAction,
    ) {}
}
