<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Application\ShiftReport;
use App\Modules\Sales\Domain\Models\CashMovement;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Sales\Http\Resources\SalesResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Sync\Application\SyncPushService;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Endpoint online POS (FR-POS-01..24). Memakai jalur yang sama dengan sinkronisasi agar kiriman ulang aman;
 * pelaku diambil dari token kasir, bukan dari isi permintaan.
 */
class PosSalesController extends Controller
{
    /** @var list<string> field waktu yang diisi server pada permintaan ini */
    private array $serverFilled = [];

    public function __construct(private readonly SyncPushService $sync) {}

    /** Waktu dari perangkat, atau jam server bila tidak dikirim (tidak ikut pembanding kiriman ulang). */
    private function serverTime(Request $request, string $field): string
    {
        $value = $request->input($field);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $this->serverFilled[] = $field;

        return now()->toIso8601String();
    }

    public function currentShift(Request $request, ShiftReport $report): JsonResponse
    {
        $shift = Shift::query()->where('device_id', $this->device($request)->id)->where('status', Shift::OPEN)->with('cashier:id,name')->first();

        return ApiResponse::ok($shift === null ? null : SalesResources::shift($shift) + ['report' => $report->build($shift)]);
    }

    public function openShift(Request $request): JsonResponse
    {
        $id = $this->entityId($request);
        $result = $this->run($request, 'shift.open', $id, [
            'cashier_id' => $this->user($request)->id,
            'opening_cash' => $request->input('opening_cash'),
            'opened_at' => $this->serverTime($request, 'opened_at'),
        ]);

        return $this->respond($result, fn () => SalesResources::shift(Shift::query()->findOrFail($result['shift_id'])));
    }

    public function cashMovement(Request $request, string $shiftId): JsonResponse
    {
        $id = $this->entityId($request);
        $result = $this->run($request, 'cash_movement', $id, [
            'shift_id' => $shiftId,
            'type' => $request->input('type'),
            'amount' => $request->input('amount'),
            'reason' => (string) $request->input('reason', ''),
            'created_by' => $this->user($request)->id,
            'created_at' => $this->serverTime($request, 'created_at'),
            'authorization' => $request->input('authorization'),
        ]);

        return $this->respond($result, fn () => SalesResources::cashMovement(CashMovement::query()->findOrFail($result['cash_movement_id'])));
    }

    public function closeShift(Request $request, string $shiftId): JsonResponse
    {
        $id = $this->entityId($request);
        $result = $this->run($request, 'shift.close', $id, [
            'shift_id' => $shiftId,
            'closed_by' => $this->user($request)->id,
            'closed_at' => $this->serverTime($request, 'closed_at'),
            'counted_cash' => $request->input('counted_cash'),
            'denominations' => $request->input('denominations'),
            'variance_note' => $request->input('variance_note'),
        ]);

        return $this->respond($result, fn () => SalesResources::shift(Shift::query()->findOrFail($result['shift_id'])));
    }

    public function shiftReport(Request $request, string $shiftId, ShiftReport $report): JsonResponse
    {
        $shift = Shift::query()->whereKey($shiftId)->where('device_id', $this->device($request)->id)->firstOrFail();

        return ApiResponse::ok(SalesResources::shift($shift) + ['report' => $shift->summary ?? $report->build($shift)]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $payload = $request->except(['id']);
        $payload['cashier_id'] = $this->user($request)->id;
        if (isset($payload['void']) && is_array($payload['void'])) {
            $payload['void']['voided_by'] = $this->user($request)->id;
        }
        $result = $this->run($request, 'order', $this->entityId($request), $payload);

        return $this->respond($result, fn () => SalesResources::order($this->loadOrder($result['order_id'])));
    }

    public function findOrders(Request $request): JsonResponse
    {
        $data = $request->validate(['receipt_no' => ['required', 'string', 'max:40']]);
        $orders = Order::query()
            ->where('outlet_id', $this->device($request)->outlet_id)
            ->where('receipt_no', $data['receipt_no'])
            ->orderByDesc('business_date')
            ->limit(5)
            ->get();

        return ApiResponse::ok($orders->map(fn (Order $o) => SalesResources::order($o, false))->values());
    }

    public function showOrder(Request $request, string $orderId): JsonResponse
    {
        $order = $this->loadOrder($orderId);
        if ($order->outlet_id !== $this->device($request)->outlet_id) {
            throw new SalesException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return ApiResponse::ok(SalesResources::order($order));
    }

    public function voidOrder(Request $request, string $orderId): JsonResponse
    {
        $result = $this->run($request, 'order.void', $this->entityId($request), [
            'order_id' => $orderId,
            'voided_by' => $this->user($request)->id,
            'reason' => $request->input('reason'),
            'created_at' => $this->serverTime($request, 'created_at'),
            'authorization' => $request->input('authorization'),
            'stock_action' => $request->input('stock_action'),
        ]);

        return $this->respond($result, fn () => SalesResources::order($this->loadOrder($result['order_id'])));
    }

    /** Kirim pesanan ke dapur (FR-POS-20); ID baris harus sama dengan baris pesanan saat bayar. */
    public function sendToKitchen(Request $request): JsonResponse
    {
        $result = $this->run($request, 'kitchen.send', $this->entityId($request), [
            'order_id' => $request->input('order_id'),
            'shift_id' => $request->input('shift_id'),
            'sent_by' => $this->user($request)->id,
            'sent_at' => $this->serverTime($request, 'sent_at'),
            'lines' => $request->input('lines'),
        ]);

        return $this->respond($result, fn () => ['ticket_id' => $result['ticket_id'], 'order_id' => $request->input('order_id')]);
    }

    public function refundOrder(Request $request, string $orderId): JsonResponse
    {
        $result = $this->run($request, 'order.refund', $this->entityId($request), [
            'order_id' => $orderId,
            'shift_id' => $request->input('shift_id'),
            'amount' => $request->input('amount'),
            'method' => $request->input('method'),
            'stock_action' => $request->input('stock_action'),
            'reason' => $request->input('reason'),
            'lines' => $request->input('lines'),
            'refunded_by' => $this->user($request)->id,
            'created_at' => $this->serverTime($request, 'created_at'),
            'authorization' => $request->input('authorization'),
        ]);

        return $this->respond($result, fn () => SalesResources::refund(Refund::query()->findOrFail($result['refund_id'])));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function run(Request $request, string $type, string $id, array $payload): array
    {
        $result = $this->sync->process($this->device($request), ['type' => $type, 'id' => $id, 'payload' => array_filter($payload, fn ($v) => $v !== null)], null, $this->serverFilled);
        $this->serverFilled = [];
        if ($result['status'] === 'rejected') {
            $error = $result['error'];
            $status = match (true) {
                $error['code'] === 'CONFLICT' => 409,
                $error['code'] === 'INTERNAL_ERROR' => 500,
                (bool) ($error['retryable'] ?? false) => 409,
                in_array($error['code'], ['STAFF_NOT_ALLOWED', 'AUTHORIZATION_REQUIRED', 'AUTHORIZATION_INVALID', 'AUTHORIZATION_USED', 'DISCOUNT_LIMIT_EXCEEDED', 'REFUND_REQUIRES_MANAGER'], true) => 403,
                in_array($error['code'], ['SHIFT_ALREADY_OPEN', 'SHIFT_CLOSED', 'DUPLICATE_RECEIPT_NO', 'ORDER_NOT_VOIDABLE', 'ORDER_NOT_REFUNDABLE', 'VOID_SHIFT_CLOSED', 'PAYMENT_INTENT_USED', 'OPEN_BILL_CLOSED'], true) => 409,
                $error['code'] === 'OPEN_BILL_NOT_FOUND' => 404,
                default => 422,
            };

            throw new SalesException($error['code'], $error['message'], $status, (bool) ($error['retryable'] ?? false), $error['field'] ?? null, $error['details'] ?? []);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  \Closure(): array<string, mixed>  $data
     */
    private function respond(array $result, \Closure $data): JsonResponse
    {
        $meta = ['sync_status' => $result['status']];

        return $result['status'] === 'accepted'
            ? ApiResponse::created($data(), $meta)
            : ApiResponse::ok($data(), $meta);
    }

    private function entityId(Request $request): string
    {
        $id = $request->input('id');
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new SalesException('VALIDATION_FAILED', 'id wajib berupa UUID yang dibuat perangkat.', 422, field: 'id');
        }

        return $id;
    }

    private function loadOrder(string $id): Order
    {
        if (! Str::isUuid($id)) {
            throw new SalesException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return Order::query()->with(['items', 'payments', 'discounts', 'refunds', 'cashier:id,name'])->findOrFail($id);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        return $device->loadMissing('outlet');
    }

    private function user(Request $request): User
    {
        return $this->actor($request);
    }
}
