<?php

namespace App\Modules\Payment\Application\Gateways;

use App\Modules\Payment\Domain\Models\PaymentIntent;
use Illuminate\Http\Request;

/**
 * Kontrak driver payment gateway (ADR 0004). Driver mitra produksi cukup mengimplementasikan antarmuka ini.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Membuat tagihan di gateway. */
    public function createCharge(PaymentIntent $intent): GatewayCharge;

    /** Status terkini dari gateway (polling cadangan). */
    public function status(PaymentIntent $intent): GatewayStatus;

    /** Membatalkan tagihan yang belum dibayar. False bila gateway menolak (mis. sudah dibayar). */
    public function cancel(PaymentIntent $intent): bool;

    /** Tanda tangan webhook valid? */
    public function verifyWebhook(Request $request): bool;

    public function parseWebhook(Request $request): GatewayNotification;
}
