<?php

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Gateways\GatewayManager;
use App\Modules\Payment\Application\PaymentIntentService;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Payment\Domain\Models\WebhookEvent;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notifikasi payment gateway (FR-PAY-04). Tanda tangan wajib valid; setiap event diproses sekali (idempoten).
 * Company belum diketahui saat event datang → pencatatan & pencarian tagihan memakai mode sistem.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentIntentService $intents,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        if (! $this->gateways->has($provider)) {
            return ApiResponse::error(404, [['code' => 'NOT_FOUND', 'message' => 'Gateway tidak dikenal.']]);
        }
        $gateway = $this->gateways->driver($provider);

        if (! $gateway->verifyWebhook($request)) {
            // Tidak disimpan agar tidak bisa dipakai membanjiri basis data.
            Log::warning('Webhook pembayaran dengan tanda tangan tidak valid', ['provider' => $provider, 'ip' => $request->ip()]);

            return ApiResponse::error(401, [['code' => 'INVALID_SIGNATURE', 'message' => 'Tanda tangan webhook tidak valid.']]);
        }

        $notification = $gateway->parseWebhook($request);
        if ($notification->eventId === '' || $notification->reference === '') {
            return ApiResponse::error(422, [['code' => 'INVALID_PAYLOAD', 'message' => 'Data webhook tidak lengkap.']]);
        }

        $eventId = Str::limit($notification->eventId, 120, '');
        /** @var WebhookEvent $event */
        $event = $this->context->runAsSystem(function () use ($provider, $eventId, $notification): WebhookEvent {
            DB::table('webhook_events')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'provider' => $provider,
                'event_id' => $eventId,
                'signature_valid' => true,
                'payload' => json_encode($notification->payload),
                'received_at' => now(),
            ]);

            return WebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->firstOrFail();
        });
        if ($event->processed_at !== null) {
            return ApiResponse::ok(['duplicate' => true]);
        }

        /** @var PaymentIntent|null $intent */
        $intent = $this->context->runAsSystem(fn () => PaymentIntent::query()
            ->where('provider', $provider)
            ->where('provider_reference', $notification->reference)
            ->first());

        if ($intent === null) {
            $this->context->runAsSystem(fn () => $event->forceFill(['result' => 'unknown_reference', 'processed_at' => now()])->save());

            return ApiResponse::ok(['processed' => false]);
        }

        $updated = $this->context->runAsTenant($intent->company_id, fn () => $this->intents->transition(
            $intent,
            $notification->status,
            $notification->amount,
            $notification->paidAt,
        ));

        $this->context->runAsSystem(fn () => $event->forceFill([
            'company_id' => $intent->company_id,
            'result' => $updated->status,
            'processed_at' => now(),
        ])->save());

        return ApiResponse::ok(['processed' => true]);
    }
}
