<?php

namespace App\Modules\Payment\Console;

use App\Modules\Payment\Application\Gateways\GatewayManager;
use App\Modules\Payment\Application\Gateways\GatewayStatus;
use App\Modules\Payment\Application\Gateways\SandboxGateway;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Payment\Http\Controllers\PaymentWebhookController;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/** Simulasi pembayaran QRIS/e-wallet di gateway sandbox (pengembangan & uji manual). */
class SandboxPay extends Command
{
    protected $signature = 'fnb:sandbox-pay {intent : ID payment intent} {--status=paid : paid|failed|expired}';

    protected $description = 'Simulasikan hasil pembayaran pada gateway sandbox dan kirim webhook bertanda tangan';

    public function handle(GatewayManager $gateways, TenantContext $context): int
    {
        if (! $this->laravel->environment(['local', 'testing', 'staging'])) {
            $this->error('Perintah ini tidak tersedia di produksi.');

            return self::FAILURE;
        }
        $status = (string) $this->option('status');
        if (! in_array($status, [GatewayStatus::PAID, GatewayStatus::FAILED, GatewayStatus::EXPIRED], true)) {
            $this->error('Status harus paid, failed, atau expired.');

            return self::INVALID;
        }

        /** @var PaymentIntent|null $intent */
        $intent = $context->runAsSystem(fn () => PaymentIntent::query()->find((string) $this->argument('intent')));
        if ($intent === null || $intent->provider !== 'sandbox' || $intent->provider_reference === null) {
            $this->error('Payment intent sandbox tidak ditemukan.');

            return self::FAILURE;
        }

        /** @var SandboxGateway $gateway */
        $gateway = $gateways->driver('sandbox');
        $webhook = $gateway->simulate($intent->provider_reference, $status, (string) $intent->amount);
        $request = Request::create('/api/v1/webhooks/payment/sandbox', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook['body']);
        $request->headers->set(SandboxGateway::SIGNATURE_HEADER, $webhook['signature']);
        $this->laravel->make(PaymentWebhookController::class)->handle($request, 'sandbox');

        $fresh = $context->runAsSystem(fn () => PaymentIntent::query()->find($intent->id));
        $this->info('Status tagihan: '.($fresh instanceof PaymentIntent ? $fresh->status : '-'));

        return self::SUCCESS;
    }
}
