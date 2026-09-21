<?php

namespace App\Modules\Payment\Application\Gateways;

final class GatewayCharge
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $reference,
        public readonly ?string $qrString,
        public readonly ?string $checkoutUrl,
        public readonly array $payload = [],
    ) {}
}
