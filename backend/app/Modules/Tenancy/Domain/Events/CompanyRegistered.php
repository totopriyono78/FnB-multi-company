<?php

namespace App\Modules\Tenancy\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Domain event: company baru dibuat. Modul Identity menyiapkan role bawaan. */
final class CompanyRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $ownerUserId,
    ) {}
}
