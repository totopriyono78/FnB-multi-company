<?php

namespace App\Modules\Payment\Application\Gateways;

use InvalidArgumentException;

/** Memilih driver payment gateway berdasarkan konfigurasi (ADR 0004). */
class GatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const DRIVERS = [
        'sandbox' => SandboxGateway::class,
    ];

    public function default(): PaymentGateway
    {
        $name = (string) config('payments.default', 'sandbox');
        if ($name === 'sandbox' && ! app()->environment(['local', 'testing', 'staging'])) {
            // Tagihan sandbox tidak pernah menerima dana sungguhan: jangan dipakai di produksi.
            throw new \RuntimeException('Payment gateway sandbox tidak boleh dipakai di lingkungan ini. Atur PAYMENT_GATEWAY.');
        }

        return $this->driver($name);
    }

    /** Simulator bayar hanya untuk pengembangan & uji otomatis. */
    public static function simulatorEnabled(): bool
    {
        return (bool) config('payments.gateways.sandbox.simulator_enabled') && app()->environment(['local', 'testing']);
    }

    public function driver(string $name): PaymentGateway
    {
        $class = self::DRIVERS[$name] ?? throw new InvalidArgumentException("Payment gateway [{$name}] tidak dikenal.");

        return app($class);
    }

    public function has(string $name): bool
    {
        return isset(self::DRIVERS[$name]);
    }
}
