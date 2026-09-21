<?php

namespace App\Modules\Payment\Application\Gateways;

use App\Modules\Payment\Domain\Models\PaymentIntent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Gateway simulasi untuk pengembangan & uji. Pembayaran disimulasikan lewat `fnb:sandbox-pay` atau endpoint
 * simulator (hanya non-produksi) yang mengirim webhook bertanda tangan HMAC-SHA256 seperti gateway sungguhan.
 */
class SandboxGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Sandbox-Signature';

    private const CACHE_PREFIX = 'payment-sandbox:';

    public function name(): string
    {
        return 'sandbox';
    }

    public function createCharge(PaymentIntent $intent): GatewayCharge
    {
        $reference = 'SBX-'.Str::upper(Str::random(20));
        Cache::put(self::CACHE_PREFIX.$reference, ['status' => GatewayStatus::PENDING, 'amount' => (string) $intent->amount, 'paid_at' => null], now()->addDay());

        return new GatewayCharge(
            $reference,
            $intent->method === 'qris' ? '00020101021226SANDBOX'.$reference.'5303360540'.(string) $intent->amount.'6304SBOX' : null,
            $intent->method === 'ewallet' ? 'https://sandbox.invalid/pay/'.$reference : null,
            ['sandbox' => true],
        );
    }

    public function status(PaymentIntent $intent): GatewayStatus
    {
        /** @var array{status: string, amount: string, paid_at: ?string}|null $state */
        $state = Cache::get(self::CACHE_PREFIX.$intent->provider_reference);
        if ($state === null) {
            return new GatewayStatus(GatewayStatus::PENDING);
        }

        return new GatewayStatus($state['status'], $state['amount'], $state['paid_at'] ? CarbonImmutable::parse($state['paid_at']) : null);
    }

    public function cancel(PaymentIntent $intent): bool
    {
        $key = self::CACHE_PREFIX.$intent->provider_reference;
        /** @var array{status: string, amount: string, paid_at: ?string}|null $state */
        $state = Cache::get($key);
        if ($state !== null && $state['status'] === GatewayStatus::PAID) {
            return false;
        }
        Cache::put($key, ['status' => GatewayStatus::CANCELLED, 'amount' => (string) $intent->amount, 'paid_at' => null], now()->addDay());

        return true;
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = $this->secret();
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        return $secret !== null && $signature !== ''
            && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(Request $request): GatewayNotification
    {
        $data = $request->json()->all();

        return new GatewayNotification(
            (string) ($data['event_id'] ?? ''),
            (string) ($data['reference'] ?? ''),
            (string) ($data['status'] ?? ''),
            (string) ($data['amount'] ?? '0'),
            isset($data['paid_at']) ? CarbonImmutable::parse((string) $data['paid_at']) : null,
            $data,
        );
    }

    /**
     * Simulasikan hasil pembayaran di sisi "gateway" dan kembalikan webhook yang akan dikirim.
     *
     * @return array{body: string, signature: string}
     */
    public function simulate(string $reference, string $status = GatewayStatus::PAID, ?string $amount = null): array
    {
        $key = self::CACHE_PREFIX.$reference;
        /** @var array{status: string, amount: string, paid_at: ?string}|null $state */
        $state = Cache::get($key) ?? ['status' => GatewayStatus::PENDING, 'amount' => $amount ?? '0', 'paid_at' => null];
        $paidAt = $status === GatewayStatus::PAID ? now()->toIso8601String() : null;
        $state = ['status' => $status, 'amount' => $amount ?? $state['amount'], 'paid_at' => $paidAt];
        Cache::put($key, $state, now()->addDay());

        $body = (string) json_encode([
            'event_id' => (string) Str::uuid7(),
            'reference' => $reference,
            'status' => $status,
            'amount' => $state['amount'],
            'paid_at' => $paidAt,
        ]);

        return ['body' => $body, 'signature' => hash_hmac('sha256', $body, (string) $this->secret())];
    }

    private function secret(): ?string
    {
        $secret = config('payments.gateways.sandbox.webhook_secret');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        // Lokal/uji: turunan APP_KEY agar tidak perlu konfigurasi. Produksi tanpa kunci → webhook selalu ditolak.
        return app()->environment(['local', 'testing']) ? hash('sha256', 'sandbox-webhook|'.config('app.key')) : null;
    }
}
