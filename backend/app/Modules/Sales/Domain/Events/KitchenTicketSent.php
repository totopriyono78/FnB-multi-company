<?php

namespace App\Modules\Sales\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Pesanan dikirim ke dapur (FR-POS-20). Listener: potong stok bila outlet memakai pemicu "saat kirim dapur". */
final class KitchenTicketSent
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $ticketId,
        public readonly string $orderId,
    ) {}
}
