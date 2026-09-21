<?php

namespace App\Modules\Sales\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Tutup hari (FR-POS-05). Listener berikutnya: laporan harian & jurnal (Tahap 5 / Fase 2). */
final class BusinessDayClosed
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $outletId,
        public readonly string $businessDate,
    ) {}
}
