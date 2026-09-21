<?php

namespace App\Modules\Payment\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Payment\Application\Gateways\GatewayManager;
use App\Modules\Payment\Application\Gateways\GatewayStatus;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Tenancy\Domain\Models\Device;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Siklus hidup tagihan QRIS/e-wallet (FR-PAY-04, FR-PAY-05, ADR 0004):
 * pending → paid | expired | cancelled | failed; dibayar setelah batal/kedaluwarsa → paid_late.
 */
class PaymentIntentService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentMethods $methods,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{order_ref: string, method: string, amount: string|int|float}  $data */
    public function create(Device $device, User $cashier, array $data): PaymentIntent
    {
        $device->loadMissing('outlet');
        $method = $data['method'];
        $amount = BigDecimal::of((string) $data['amount'])->toScale(2);

        $config = $this->methods->forOutlet($device->outlet)->firstWhere('method', $method);
        if (! in_array($method, PaymentMethods::GATEWAY_METHODS, true) || ! $config instanceof OutletPaymentMethod || ! $config->is_active) {
            throw new SalesException('PAYMENT_METHOD_INACTIVE', 'Metode pembayaran tidak aktif di outlet ini.', 422, field: 'method');
        }

        // Tagihan yang masih berlaku untuk order & nominal yang sama dipakai ulang agar pelanggan tidak memindai dua QR.
        $existing = PaymentIntent::query()
            ->where('device_id', $device->id)
            ->where('order_ref', $data['order_ref'])
            ->where('method', $method)
            ->where('status', PaymentIntent::PENDING)
            ->get();
        foreach ($existing as $intent) {
            $intent = $this->refresh($intent);
            if ($intent->status === PaymentIntent::PENDING && BigDecimal::of((string) $intent->amount)->isEqualTo($amount)) {
                return $intent;
            }
            if ($intent->status === PaymentIntent::PAID && $intent->consumed_by_order_id === null && BigDecimal::of((string) $intent->amount)->isEqualTo($amount)) {
                return $intent;
            }
            if ($intent->status === PaymentIntent::PENDING) {
                $this->cancel($intent, $cashier);
            }
        }

        $gateway = $this->gateways->default();
        $intent = new PaymentIntent;
        $intent->forceFill([
            'id' => (string) Str::uuid7(),
            'company_id' => $device->company_id,
            'outlet_id' => $device->outlet_id,
            'device_id' => $device->id,
            'order_ref' => $data['order_ref'],
            'method' => $method,
            'provider' => $gateway->name(),
            'amount' => (string) $amount,
            'status' => PaymentIntent::PENDING,
            'expires_at' => now()->addMinutes((int) config('payments.intent_ttl_minutes', 15)),
            'created_by' => $cashier->id,
        ])->save();

        try {
            $charge = $gateway->createCharge($intent);
        } catch (Throwable $e) {
            report($e);
            $intent->forceFill(['status' => PaymentIntent::FAILED])->save();
            throw new SalesException('GATEWAY_UNAVAILABLE', 'Payment gateway tidak dapat dihubungi. Coba lagi atau pilih metode lain.', 502, true);
        }

        $intent->forceFill([
            'provider_reference' => $charge->reference,
            'qr_string' => $charge->qrString,
            'checkout_url' => $charge->checkoutUrl,
            'provider_payload' => $charge->payload,
        ])->save();

        $this->audit->log('payment.intent_created', $intent, new: ['method' => $method, 'amount' => (string) $amount, 'order_ref' => $intent->order_ref], userId: $cashier->id);

        return $intent;
    }

    /** Polling cadangan: tanya gateway bila masih pending; tandai kedaluwarsa bila lewat batas. */
    public function refresh(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->status !== PaymentIntent::PENDING || $intent->provider_reference === null) {
            if ($intent->status === PaymentIntent::PENDING && $intent->expires_at->isPast()) {
                return $this->transition($intent, GatewayStatus::EXPIRED, null, null);
            }

            return $intent;
        }

        $gateway = $this->gateways->driver($intent->provider);
        try {
            $status = $gateway->status($intent);
        } catch (Throwable $e) {
            report($e);

            return $intent;
        }

        if ($status->status === GatewayStatus::PENDING && $intent->expires_at->isPast()) {
            // Batalkan di gateway dulu agar pelanggan tidak bisa membayar QR yang sudah kedaluwarsa.
            if (! $this->safeCancel($intent)) {
                $latest = $gateway->status($intent);

                return $this->transition($intent, $latest->status, $latest->amount, $latest->paidAt);
            }

            return $this->transition($intent, GatewayStatus::EXPIRED, null, null);
        }

        return $this->transition($intent, $status->status, $status->amount, $status->paidAt);
    }

    public function cancel(PaymentIntent $intent, ?User $by = null): PaymentIntent
    {
        $intent = $this->refresh($intent);
        if ($intent->status !== PaymentIntent::PENDING) {
            return $intent;
        }

        if (! $this->safeCancel($intent)) {
            // Gateway menolak karena sudah dibayar: ambil status terbaru.
            return $this->refresh($intent);
        }

        $intent = $this->transition($intent, GatewayStatus::CANCELLED, null, null);
        $this->audit->log('payment.intent_cancelled', $intent, userId: $by?->id);

        return $intent;
    }

    /**
     * Terapkan status dari gateway (webhook atau polling). Idempoten & aman terhadap urutan kedatangan.
     */
    public function transition(PaymentIntent $intent, string $status, ?string $amount, ?CarbonImmutable $paidAt): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $status, $amount, $paidAt): PaymentIntent {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $current = $locked->status;

            if ($status === GatewayStatus::PAID) {
                if ($amount !== null && ! BigDecimal::of($amount)->isEqualTo(BigDecimal::of((string) $locked->amount))) {
                    if ($current === PaymentIntent::PENDING) {
                        $locked->forceFill(['status' => PaymentIntent::FAILED])->save();
                    }
                    $this->audit->log('payment.amount_mismatch', $locked, new: ['expected' => (string) $locked->amount, 'received' => $amount]);

                    return $locked;
                }
                if ($current === PaymentIntent::PENDING) {
                    $locked->forceFill(['status' => PaymentIntent::PAID, 'paid_at' => $paidAt ?? now()])->save();
                } elseif (in_array($current, [PaymentIntent::CANCELLED, PaymentIntent::EXPIRED, PaymentIntent::FAILED], true)) {
                    $locked->forceFill(['status' => PaymentIntent::PAID_LATE, 'paid_at' => $paidAt ?? now()])->save();
                    $this->audit->log('payment.paid_late', $locked, old: ['status' => $current], new: ['status' => PaymentIntent::PAID_LATE], reason: 'Dana masuk setelah tagihan dibatalkan/kedaluwarsa; perlu refund manual.');
                }

                return $locked;
            }

            if ($current !== PaymentIntent::PENDING) {
                return $locked;
            }

            $map = [
                GatewayStatus::EXPIRED => PaymentIntent::EXPIRED,
                GatewayStatus::CANCELLED => PaymentIntent::CANCELLED,
                GatewayStatus::FAILED => PaymentIntent::FAILED,
            ];
            if (isset($map[$status])) {
                $locked->forceFill([
                    'status' => $map[$status],
                    'cancelled_at' => $status === GatewayStatus::CANCELLED ? now() : $locked->cancelled_at,
                ])->save();
            }

            return $locked;
        });
    }

    /** Tandai tagihan dipakai sebuah order. False bila sudah dipakai order lain atau belum dibayar. */
    public function consume(string $intentId, string $orderId): bool
    {
        return PaymentIntent::query()
            ->whereKey($intentId)
            ->where('status', PaymentIntent::PAID)
            ->where(fn ($q) => $q->whereNull('consumed_by_order_id')->orWhere('consumed_by_order_id', $orderId))
            ->update(['consumed_by_order_id' => $orderId, 'updated_at' => now()]) === 1;
    }

    private function safeCancel(PaymentIntent $intent): bool
    {
        try {
            return $this->gateways->driver($intent->provider)->cancel($intent);
        } catch (Throwable $e) {
            report($e);

            return true;
        }
    }
}
