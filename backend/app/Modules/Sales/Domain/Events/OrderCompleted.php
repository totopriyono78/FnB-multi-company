<?php

namespace App\Modules\Sales\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Transaksi diterima server (dibayar atau dibatalkan sebelum bayar). Listener berikutnya: stok (Tahap 4), loyalti & akuntansi (Fase 2). */
final class OrderCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $orderId,
        public readonly string $businessDate,
        public readonly string $status,
    ) {}
}
