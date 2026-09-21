<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Sales\Domain\Events\OrderRefunded;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Refund penuh/sebagian (FR-POS-17, BR-12, BR-13). Dicatat pada hari bisnis & shift saat refund dilakukan.
 * Nominal dihitung server secara proporsional terhadap total (termasuk pajak & service charge).
 */
class RefundService
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly Authorizations $auth,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function refund(Device $device, string $orderId, array $input): Refund
    {
        $validator = Validator::make($input, [
            'id' => ['required', 'uuid'],
            'shift_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => ['required', 'string', Rule::in(PaymentMethods::codes())],
            'stock_action' => ['required', Rule::in(['return', 'waste'])],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'refunded_by' => ['required', 'uuid'],
            'created_at' => ['required', 'date'],
            'authorization' => ['nullable', 'array'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.order_item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);
        if ($validator->fails()) {
            throw new SalesException('VALIDATION_FAILED', 'Data refund tidak valid.', 422, details: ['errors' => $validator->errors()->toArray()]);
        }
        $data = $validator->validated();
        $device->loadMissing('outlet');
        $outlet = $device->outlet;
        $at = $this->shifts->time($data['created_at'], 'created_at');

        return DB::transaction(function () use ($device, $outlet, $orderId, $data, $at): Refund {
            /** @var Order|null $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new SalesException('ORDER_NOT_FOUND', 'Transaksi belum diterima server.', 409, true, 'order_id');
            }
            if ($order->outlet_id !== $outlet->id) {
                throw new SalesException('ORDER_NOT_IN_OUTLET', 'Transaksi milik outlet lain.', 422, field: 'order_id');
            }
            if (! in_array($order->status, [Order::PAID, Order::PARTIALLY_REFUNDED], true)) {
                throw new SalesException('ORDER_NOT_REFUNDABLE', 'Transaksi ini tidak dapat di-refund.', 409, field: 'order_id', details: ['status' => $order->status]);
            }

            $shift = $this->shifts->openShift($device, $data['shift_id']);
            $this->shifts->assertWithinShift($shift, $at);

            $actor = $this->auth->staff($data['refunded_by'], $outlet, 'pos.transact', 'refunded_by');
            $flags = [];
            $approver = null;
            if (! $this->auth->selfAuthorized($actor, 'pos.void', $outlet)) {
                [$approver, $offline] = $this->auth->verify($data['authorization'] ?? null, 'refund', $device, $at, 'authorization', 'refund:'.$data['id'], [
                    'reference_id' => $order->id,
                    'amount' => (string) $data['amount'],
                ]);
                if ($offline) {
                    $flags[] = 'offline_authorization';
                }
            }
            // Manajer yang login sendiri, atau supervisor pemberi otorisasi.
            $decider = $approver ?? $actor;

            // Hari transaksi sudah ditutup → hanya manajer ke atas (BR-13).
            if ($this->calendar->isClosed($outlet, $order->business_date)) {
                $ok = $approver !== null
                    ? $this->auth->userCan($approver, 'pos.end_of_day', $outlet)
                    : $this->auth->selfAuthorized($actor, 'pos.end_of_day', $outlet);
                if (! $ok) {
                    throw new SalesException('REFUND_REQUIRES_MANAGER', 'Refund untuk hari yang sudah ditutup memerlukan persetujuan manajer.', 403, field: 'authorization');
                }
                $flags[] = 'closed_day_refund';
            }

            $remaining = BigDecimal::of((string) $order->total)->minus((string) $order->refunded_total);
            [$expected, $lines] = $this->expectedAmount($order, $data['lines'] ?? [], $remaining);
            if (! BigDecimal::of((string) $data['amount'])->isEqualTo($expected)) {
                throw new SalesException('REFUND_AMOUNT_MISMATCH', 'Nominal refund tidak sesuai perhitungan server.', 422, field: 'amount', details: ['expected' => (string) $expected]);
            }

            // Refund lewat metode asal. Tunai untuk transaksi non-tunai hanya dengan persetujuan manajer
            // (mengurangi kas seharusnya di laci).
            $paidMethods = $order->payments()->pluck('method')->all();
            if (! in_array($data['method'], $paidMethods, true)) {
                $managerOk = $data['method'] === 'cash' && (
                    $approver !== null ? $this->auth->userCan($decider, 'pos.end_of_day', $outlet) : $this->auth->selfAuthorized($actor, 'pos.end_of_day', $outlet)
                );
                if (! $managerOk) {
                    throw new SalesException('REFUND_METHOD_INVALID', 'Refund harus melalui metode pembayaran asal; refund tunai untuk transaksi non-tunai memerlukan manajer.', 422, field: 'method');
                }
                $flags[] = 'refund_method_changed';
            }
            if (in_array($data['method'], PaymentMethods::GATEWAY_METHODS, true)) {
                $flags[] = 'gateway_refund_required';
            }

            $refund = new Refund;
            $refund->forceFill([
                'id' => $data['id'],
                'company_id' => $order->company_id,
                'outlet_id' => $outlet->id,
                'order_id' => $order->id,
                'order_business_date' => $order->business_date->format('Y-m-d'),
                'business_date' => $shift->business_date->format('Y-m-d'),
                'shift_id' => $shift->id,
                'amount' => (string) $expected,
                'method' => $data['method'],
                'lines' => $lines,
                'stock_action' => $data['stock_action'],
                'reason' => trim((string) $data['reason']),
                'refunded_by' => $actor->id,
                'authorized_by' => $approver?->id,
                'flags' => array_values(array_unique($flags)),
                'device_created_at' => $at,
                'server_received_at' => now(),
            ])->save();

            $refunded = BigDecimal::of((string) $order->refunded_total)->plus($expected);
            $order->forceFill([
                'refunded_total' => (string) $refunded->toScale(2),
                'status' => $refunded->isEqualTo(BigDecimal::of((string) $order->total)) ? Order::REFUNDED : Order::PARTIALLY_REFUNDED,
            ])->save();

            $this->audit->log('order.refunded', $order, new: ['refund_id' => $refund->id, 'amount' => $refund->amount, 'method' => $refund->method, 'status' => $order->status], reason: $refund->reason, authorizedBy: $refund->authorized_by, userId: $actor->id);

            DB::afterCommit(fn () => OrderRefunded::dispatch($order->company_id, $order->id, $refund->id, $refund->stock_action));

            return $refund;
        });
    }

    /**
     * @param  list<array{order_item_id: string, qty: string|int|float}>  $requested
     * @return array{0: BigDecimal, 1: list<array{order_item_id: string, qty: string, amount: string}>}
     */
    private function expectedAmount(Order $order, array $requested, BigDecimal $remaining): array
    {
        if ($remaining->isLessThanOrEqualTo(0)) {
            throw new SalesException('ORDER_NOT_REFUNDABLE', 'Transaksi sudah di-refund penuh.', 409, field: 'order_id');
        }
        if ($requested === []) {
            return [$remaining->toScale(2), []];
        }

        $items = OrderItem::query()->where('order_id', $order->id)->where('business_date', $order->business_date->format('Y-m-d'))->get()->keyBy('id');
        $netTotal = $items->reduce(fn (BigDecimal $c, OrderItem $i) => $c->plus((string) $i->net), BigDecimal::zero());
        $previous = [];
        foreach ($order->refunds()->get() as $old) {
            foreach ($old->lines as $l) {
                $previous[$l['order_item_id']] = BigDecimal::of($previous[$l['order_item_id']] ?? '0')->plus($l['qty']);
            }
        }

        $amount = BigDecimal::zero();
        $lines = [];
        foreach ($requested as $i => $r) {
            /** @var OrderItem|null $item */
            $item = $items->get($r['order_item_id']);
            if ($item === null || $item->status !== 'sold') {
                throw new SalesException('REFUND_LINE_INVALID', 'Baris refund tidak ditemukan pada transaksi.', 422, field: "lines.{$i}.order_item_id");
            }
            $qty = BigDecimal::of((string) $r['qty']);
            $already = $previous[$item->id] ?? BigDecimal::zero();
            if ($qty->plus($already)->isGreaterThan((string) $item->qty)) {
                throw new SalesException('REFUND_QTY_EXCEEDED', 'Jumlah refund melebihi jumlah yang dibeli.', 422, field: "lines.{$i}.qty");
            }
            // Porsi baris terhadap total bayar (termasuk pajak, service charge, pembulatan).
            $share = $netTotal->isZero()
                ? BigDecimal::zero()
                : BigDecimal::of((string) $item->net)->multipliedBy($qty)->multipliedBy((string) $order->total)
                    ->dividedBy(BigDecimal::of((string) $item->qty)->multipliedBy($netTotal), 2, RoundingMode::HALF_UP);
            $amount = $amount->plus($share);
            $lines[] = ['order_item_id' => $item->id, 'qty' => (string) $qty, 'amount' => (string) $share];
        }

        // Refund yang menghabiskan semua sisa barang = sisa tagihan (hindari selisih pembulatan sen).
        $requestedQty = [];
        foreach ($lines as $line) {
            $requestedQty[$line['order_item_id']] = BigDecimal::of($line['qty']);
        }
        $coversAll = true;
        foreach ($items as $item) {
            if ($item->status !== 'sold') {
                continue;
            }
            $total = ($previous[$item->id] ?? BigDecimal::zero())->plus($requestedQty[$item->id] ?? BigDecimal::zero());
            if (! $total->isEqualTo((string) $item->qty)) {
                $coversAll = false;
                break;
            }
        }
        if ($coversAll || $amount->isGreaterThan($remaining)) {
            $amount = $remaining;
        }
        if (! $amount->isPositive()) {
            throw new SalesException('REFUND_AMOUNT_ZERO', 'Nominal refund nol.', 422, field: 'lines');
        }

        return [$amount->toScale(2), $lines];
    }
}
