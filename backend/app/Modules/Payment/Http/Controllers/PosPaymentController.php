<?php

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Gateways\GatewayManager;
use App\Modules\Payment\Application\Gateways\GatewayStatus;
use App\Modules\Payment\Application\Gateways\SandboxGateway;
use App\Modules\Payment\Application\PaymentIntentService;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Sales\Http\Resources\SalesResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tagihan QRIS/e-wallet dari POS (FR-PAY-04, FR-PAY-05). */
class PosPaymentController extends Controller
{
    public function __construct(private readonly PaymentIntentService $intents) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ref' => ['required', 'uuid'],
            'method' => ['required', Rule::in(['qris', 'ewallet'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999999', 'decimal:0,2'],
        ]);

        $intent = $this->intents->create($this->device($request), $this->actor($request), $data);

        return ApiResponse::created(SalesResources::intent($intent));
    }

    public function show(Request $request, string $intent): JsonResponse
    {
        return ApiResponse::ok(SalesResources::intent($this->intents->refresh($this->find($request, $intent))));
    }

    public function cancel(Request $request, string $intent): JsonResponse
    {
        return ApiResponse::ok(SalesResources::intent($this->intents->cancel($this->find($request, $intent), $this->actor($request))));
    }

    /**
     * Simulasi pelanggan membayar (hanya gateway sandbox & non-produksi). Webhook dikirim lewat jalur yang sama
     * dengan gateway sungguhan agar alur tanda tangan & idempotensi ikut teruji.
     */
    public function simulate(Request $request, string $intent, GatewayManager $gateways): JsonResponse
    {
        abort_unless(GatewayManager::simulatorEnabled(), 404);
        $data = $request->validate(['status' => ['nullable', Rule::in([GatewayStatus::PAID, GatewayStatus::FAILED, GatewayStatus::EXPIRED])]]);
        $model = $this->find($request, $intent);
        abort_unless($model->provider === 'sandbox' && $model->provider_reference !== null, 404);

        /** @var SandboxGateway $gateway */
        $gateway = $gateways->driver('sandbox');
        $webhook = $gateway->simulate($model->provider_reference, $data['status'] ?? GatewayStatus::PAID, (string) $model->amount);
        $fake = Request::create('/api/v1/webhooks/payment/sandbox', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook['body']);
        $fake->headers->set(SandboxGateway::SIGNATURE_HEADER, $webhook['signature']);
        app(PaymentWebhookController::class)->handle($fake, 'sandbox');

        return ApiResponse::ok(SalesResources::intent($model->refresh()));
    }

    private function find(Request $request, string $id): PaymentIntent
    {
        return PaymentIntent::query()->whereKey($id)->where('device_id', $this->device($request)->id)->firstOrFail();
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        return $device->loadMissing('outlet');
    }
}
