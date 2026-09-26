<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Sales\Domain\Models\CashMovement;
use App\Modules\Sales\Domain\Models\OpenBill;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderDiscount;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\OrderPayment;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\Shift;

/** Bentuk JSON transaksi untuk API POS & back-office. */
final class SalesResources
{
    /** @return array<string, mixed> */
    public static function shift(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'outlet_id' => $shift->outlet_id,
            'device_id' => $shift->device_id,
            'cashier_id' => $shift->cashier_id,
            'cashier_name' => $shift->relationLoaded('cashier') ? $shift->cashier?->name : null,
            'business_date' => $shift->business_date->format('Y-m-d'),
            'status' => $shift->status,
            'opening_cash' => $shift->opening_cash,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'closed_by' => $shift->closed_by,
            'expected_cash' => $shift->expected_cash,
            'counted_cash' => $shift->counted_cash,
            'cash_variance' => $shift->cash_variance,
            'variance_note' => $shift->variance_note,
            'summary' => $shift->summary,
        ];
    }

    /** @return array<string, mixed> */
    public static function cashMovement(CashMovement $m): array
    {
        return [
            'id' => $m->id,
            'shift_id' => $m->shift_id,
            'type' => $m->type,
            'amount' => $m->amount,
            'reason' => $m->reason,
            'created_by' => $m->created_by,
            'authorized_by' => $m->authorized_by,
            'created_at' => $m->device_created_at->toIso8601String(),
        ];
    }

    /**
     * Tagihan terbuka / parkir bill. `totals` hanya cuplikan terakhir untuk ditampilkan di daftar;
     * angka yang mengikat selalu dihitung ulang server saat tagihan dibuka kembali.
     *
     * @return array<string, mixed>
     */
    public static function openBill(OpenBill $bill, bool $detail = true): array
    {
        $data = [
            'id' => $bill->id,
            'outlet_id' => $bill->outlet_id,
            'label' => $bill->label,
            'table_label' => $bill->table_label,
            'customer_name' => $bill->customer_name,
            'queue_no' => $bill->queue_no,
            'channel_code' => $bill->channel_code,
            'business_date' => $bill->business_date->format('Y-m-d'),
            'opened_by' => $bill->opened_by,
            'opened_by_name' => $bill->relationLoaded('openedBy') ? $bill->openedBy?->name : null,
            'opened_at' => $bill->opened_at->toIso8601String(),
            'closed_at' => $bill->closed_at?->toIso8601String(),
            'order_id' => $bill->order_id,
            'line_count' => count($bill->lines),
            'totals' => $bill->totals,
        ];

        return $detail ? $data + ['note' => $bill->note, 'lines' => $bill->lines] : $data;
    }

    /** @return array<string, mixed> */
    public static function order(Order $order, bool $detail = true): array
    {
        $data = [
            'id' => $order->id,
            'receipt_no' => $order->receipt_no,
            'queue_no' => $order->queue_no,
            'business_date' => $order->business_date->format('Y-m-d'),
            'outlet_id' => $order->outlet_id,
            'device_id' => $order->device_id,
            'shift_id' => $order->shift_id,
            'cashier_id' => $order->cashier_id,
            'cashier_name' => $order->relationLoaded('cashier') ? $order->cashier?->name : null,
            'channel_code' => $order->channel_code,
            'table_label' => $order->table_label,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'subtotal' => $order->subtotal,
            'item_discount' => $order->item_discount,
            'order_discount' => $order->order_discount,
            'service_charge' => $order->service_charge,
            'tax_name' => $order->tax_name,
            'tax' => $order->tax,
            'rounding' => $order->rounding,
            'total' => $order->total,
            'paid_total' => $order->paid_total,
            'change_amount' => $order->change_amount,
            'refunded_total' => $order->refunded_total,
            'flags' => $order->flags,
            'created_at' => $order->device_created_at->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'voided_at' => $order->voided_at?->toIso8601String(),
            'void_reason' => $order->void_reason,
        ];
        if (! $detail) {
            return $data;
        }

        return $data + [
            'note' => $order->note,
            'promo_codes' => $order->promo_codes,
            'pricing' => $order->pricing,
            'voided_by' => $order->voided_by,
            'void_authorized_by' => $order->void_authorized_by,
            'items' => $order->items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'line_no' => $i->line_no,
                'item_id' => $i->item_id,
                'item_variant_id' => $i->item_variant_id,
                'name' => $i->name,
                'variant_name' => $i->variant_name,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'catalog_price' => $i->catalog_price,
                'modifiers' => $i->modifiers,
                'bundle' => $i->bundle,
                'gross' => $i->gross,
                'item_discount' => $i->item_discount,
                'order_discount' => $i->order_discount,
                'net' => $i->net,
                'status' => $i->status,
                'note' => $i->note,
            ])->values()->all(),
            'payments' => $order->payments->map(fn (OrderPayment $p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => $p->amount,
                'tendered' => $p->tendered,
                'change_amount' => $p->change_amount,
                'reference' => $p->reference,
                'payment_intent_id' => $p->payment_intent_id,
                'mdr_amount' => $p->mdr_amount,
            ])->values()->all(),
            'discounts' => $order->discounts->map(fn (OrderDiscount $d) => [
                'order_item_id' => $d->order_item_id,
                'source' => $d->source,
                'promotion_id' => $d->promotion_id,
                'type' => $d->type,
                'value' => $d->value,
                'amount' => $d->amount,
                'authorized_by' => $d->authorized_by,
                'reason' => $d->reason,
            ])->values()->all(),
            'refunds' => $order->refunds->map(fn (Refund $r) => self::refund($r))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function refund(Refund $r): array
    {
        return [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'business_date' => $r->business_date->format('Y-m-d'),
            'shift_id' => $r->shift_id,
            'amount' => $r->amount,
            'method' => $r->method,
            'lines' => $r->lines,
            'stock_action' => $r->stock_action,
            'reason' => $r->reason,
            'refunded_by' => $r->refunded_by,
            'authorized_by' => $r->authorized_by,
            'flags' => $r->flags,
            'created_at' => $r->device_created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function intent(PaymentIntent $intent): array
    {
        return [
            'id' => $intent->id,
            'order_ref' => $intent->order_ref,
            'method' => $intent->method,
            'provider' => $intent->provider,
            'amount' => $intent->amount,
            'status' => $intent->status,
            'qr_string' => $intent->status === PaymentIntent::PENDING ? $intent->qr_string : null,
            'checkout_url' => $intent->status === PaymentIntent::PENDING ? $intent->checkout_url : null,
            'expires_at' => $intent->expires_at->toIso8601String(),
            'paid_at' => $intent->paid_at?->toIso8601String(),
            'consumed' => $intent->consumed_by_order_id !== null,
        ];
    }
}
