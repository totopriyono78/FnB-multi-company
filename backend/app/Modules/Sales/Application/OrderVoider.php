<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Sales\Domain\Events\OrderVoided;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Void transaksi yang sudah dibayar (FR-POS-16, BR-12, BR-13). Hanya selama shift transaksi masih terbuka;
 * setelah itu koreksi memakai refund.
 */
class OrderVoider
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly Authorizations $auth,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  {voided_by, reason, created_at, authorization?, stock_action?}
     */
    public function void(Device $device, string $orderId, array $input): Order
    {
        $validator = Validator::make($input, [
            'voided_by' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'created_at' => ['required', 'date'],
            'authorization' => ['nullable', 'array'],
            // Bahan dikembalikan ke stok (bawaan) atau dianggap terbuang karena sudah diolah (Tahap 4).
            'stock_action' => ['nullable', 'in:return,waste'],
        ]);
        if ($validator->fails()) {
            throw new SalesException('VALIDATION_FAILED', 'Data void tidak valid.', 422, details: ['errors' => $validator->errors()->toArray()]);
        }
        $data = $validator->validated();
        $device->loadMissing('outlet');
        $outlet = $device->outlet;
        $at = $this->shifts->time($data['created_at'], 'created_at');

        return DB::transaction(function () use ($device, $outlet, $orderId, $data, $at): Order {
            /** @var Order|null $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new SalesException('ORDER_NOT_FOUND', 'Transaksi belum diterima server.', 409, true, 'order_id');
            }
            if ($order->outlet_id !== $outlet->id) {
                throw new SalesException('ORDER_NOT_IN_OUTLET', 'Transaksi milik outlet lain.', 422, field: 'order_id');
            }
            if ($order->status !== Order::PAID) {
                throw new SalesException('ORDER_NOT_VOIDABLE', 'Hanya transaksi lunas tanpa refund yang dapat di-void.', 409, field: 'order_id', details: ['status' => $order->status]);
            }
            /** @var Shift $shift */
            $shift = Shift::query()->findOrFail($order->shift_id);
            if ($shift->status !== Shift::OPEN) {
                throw new SalesException('VOID_SHIFT_CLOSED', 'Shift transaksi sudah ditutup. Gunakan refund.', 409, field: 'order_id');
            }
            if ($at->lessThan($order->device_created_at)) {
                throw new SalesException('INVALID_TIME', 'Waktu void lebih awal dari waktu transaksi.', 422, field: 'created_at');
            }

            $voider = $this->auth->staff($data['voided_by'], $outlet, 'pos.transact', 'voided_by');
            $authorizedBy = null;
            $flags = $order->flags;
            if (! $this->auth->selfAuthorized($voider, 'pos.void', $outlet)) {
                [$supervisor, $offline] = $this->auth->verify($data['authorization'] ?? null, 'void', $device, $at, 'authorization', 'void:'.$order->id, [
                    'reference_id' => $order->id,
                    'amount' => (string) $order->total,
                ]);
                $authorizedBy = $supervisor->id;
                if ($offline) {
                    $flags[] = 'offline_authorization';
                }
            }
            if ($order->payments()->whereIn('method', PaymentMethods::GATEWAY_METHODS)->exists()) {
                // Dana QRIS/e-wallet harus dikembalikan lewat gateway/mitra.
                $flags[] = 'gateway_refund_required';
            }

            $order->forceFill([
                'status' => Order::VOIDED,
                'voided_at' => $at,
                'voided_by' => $voider->id,
                'void_authorized_by' => $authorizedBy,
                'void_reason' => trim((string) $data['reason']),
                'void_business_date' => $shift->business_date->format('Y-m-d'),
                'void_stock_action' => $data['stock_action'] ?? 'return',
                'flags' => array_values(array_unique($flags)),
            ])->save();

            OrderItem::query()
                ->where('order_id', $order->id)
                ->where('business_date', $order->business_date->format('Y-m-d'))
                ->where('status', 'sold')
                ->update(['status' => 'voided', 'void_reason' => $order->void_reason, 'voided_by' => $voider->id, 'void_authorized_by' => $authorizedBy]);

            // Kuota promo dikembalikan karena penjualan dibatalkan.
            $promotionIds = $order->discounts()->where('source', 'promo')->whereNotNull('promotion_id')->distinct()->pluck('promotion_id');
            foreach ($promotionIds as $promotionId) {
                DB::update('UPDATE promotions SET used_count = GREATEST(used_count - 1, 0) WHERE id = ? AND company_id = ?', [$promotionId, $order->company_id]);
            }

            $this->audit->log('order.voided', $order, old: ['status' => Order::PAID], new: ['status' => Order::VOIDED, 'total' => (string) $order->total, 'stock_action' => $order->void_stock_action], reason: $order->void_reason, authorizedBy: $authorizedBy, userId: $voider->id);

            DB::afterCommit(fn () => OrderVoided::dispatch($order->company_id, $order->id, $order->business_date->format('Y-m-d'), $order->status, (string) $order->void_stock_action));

            return $order;
        });
    }
}
