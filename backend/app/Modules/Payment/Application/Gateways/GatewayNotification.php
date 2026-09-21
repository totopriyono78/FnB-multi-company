<?php

namespace App\Modules\Payment\Application\Gateways;

use Carbon\CarbonImmutable;

final class GatewayNotification
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $reference,
        public readonly string $status,
        public readonly string $amount,
        public readonly ?CarbonImmutable $paidAt,
        public readonly array $payload,
    ) {}
}
