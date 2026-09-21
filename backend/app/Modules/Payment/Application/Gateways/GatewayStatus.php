<?php

namespace App\Modules\Payment\Application\Gateways;

use Carbon\CarbonImmutable;

final class GatewayStatus
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $amount = null,
        public readonly ?CarbonImmutable $paidAt = null,
    ) {}
}
