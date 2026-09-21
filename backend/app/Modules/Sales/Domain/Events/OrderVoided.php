<?php

namespace App\Modules\Sales\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Transaksi di-void setelah bayar (FR-POS-16). Listener berikutnya: pengembalian stok (Tahap 4). */
final class OrderVoided
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $orderId,
        public readonly string $businessDate,
        public readonly string $status,
        public readonly string $stockAction = 'return',
    ) {}
}
