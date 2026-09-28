<?php

namespace App\Modules\Payment\Application\Gateways;

use Carbon\CarbonImmutable;

final class GatewayCharge
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $reference,
        public readonly ?string $qrString,
        public readonly ?string $checkoutUrl,
        public readonly array $payload = [],
        /**
         * Kedaluwarsa menurut gateway. Bila diisi, inilah yang dipakai tagihan kita: menampilkan QR
         * yang kita kira berlaku 15 menit padahal gateway mematikannya 5 menit lagi membuat pelanggan
         * memindai kode mati di depan kasir.
         */
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {}
}
