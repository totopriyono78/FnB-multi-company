<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Models\KitchenTicket;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Refund;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Data penjualan dalam bentuk sederhana untuk modul lain (Inventory) tanpa membuka model Sales.
 *
 * Bentuk baris: {id, item_id, variant_id, qty, modifiers:[{id, qty}], bundle:[{item_id, variant_id}],
 * kitchen_station_id, sent_to_kitchen_at, status}.
 */
class SalesStockFeed
{
    /** @return array<string, mixed>|null */
    public function order(string $orderId): ?array
    {
        /** @var Order|null $order */
        $order = Order::query()->with('items')->find($orderId);
        if ($order === null) {
            return null;
        }

        return [
            'id' => $order->id,
            'outlet_id' => $order->outlet_id,
            'receipt_no' => $order->receipt_no,
            'status' => $order->status,
            'was_paid' => $order->completed_at !== null,
            'business_date' => $order->business_date->format('Y-m-d'),
            'completed_at' => ($order->completed_at ?? $order->voided_at ?? $order->device_created_at)->toIso8601String(),
            'voided_at' => $order->voided_at?->toIso8601String(),
            'void_business_date' => $order->void_business_date?->format('Y-m-d'),
            'void_stock_action' => $order->void_stock_action ?? 'return',
            'cashier_id' => $order->cashier_id,
            'voided_by' => $order->voided_by,
            'lines' => $order->items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'item_id' => $i->item_id,
                'variant_id' => $i->item_variant_id,
                'qty' => (string) $i->qty,
                'modifiers' => array_map(fn (array $m) => ['id' => (string) $m['id'], 'qty' => (int) $m['qty']], $i->modifiers),
                'bundle' => array_values(array_filter(array_map(fn (array $b) => ['item_id' => $b['item_id'] ?? null, 'variant_id' => $b['variant_id'] ?? null], $i->bundle), fn (array $b) => $b['item_id'] !== null)),
                'kitchen_station_id' => $i->kitchen_station_id,
                'sent_to_kitchen_at' => $i->sent_to_kitchen_at?->toIso8601String(),
                'status' => $i->status,
            ])->values()->all(),
        ];
    }

    /**
     * Refund beserta jumlah per baris yang dikembalikan oleh refund ini
     * (refund penuh = sisa yang belum di-refund sebelumnya).
     *
     * @return array<string, mixed>|null
     */
    public function refund(string $refundId): ?array
    {
        /** @var Refund|null $refund */
        $refund = Refund::query()->find($refundId);
        if ($refund === null) {
            return null;
        }

        $lines = [];
        if ($refund->lines !== []) {
            foreach ($refund->lines as $l) {
                $lines[] = ['line_id' => $l['order_item_id'], 'qty' => (string) $l['qty']];
            }
        } else {
            $previous = [];
            $earlier = Refund::query()->where('order_id', $refund->order_id)
                ->where('id', '<>', $refund->id)
                ->where(fn ($q) => $q->where('created_at', '<', $refund->created_at)
                    ->orWhere(fn ($q) => $q->where('created_at', $refund->created_at)->where('id', '<', $refund->id)))
                ->get();
            foreach ($earlier as $old) {
                foreach ($old->lines as $l) {
                    $previous[$l['order_item_id']] = BigDecimal::of($previous[$l['order_item_id']] ?? '0')->plus((string) $l['qty']);
                }
            }
            $items = OrderItem::query()->where('order_id', $refund->order_id)
                ->where('business_date', $refund->order_business_date->format('Y-m-d'))
                ->where('status', 'sold')->get();
            foreach ($items as $item) {
                $rest = BigDecimal::of((string) $item->qty)->minus($previous[$item->id] ?? BigDecimal::zero());
                if ($rest->isPositive()) {
                    $lines[] = ['line_id' => $item->id, 'qty' => (string) $rest];
                }
            }
        }

        return [
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'outlet_id' => $refund->outlet_id,
            'stock_action' => $refund->stock_action,
            'business_date' => $refund->business_date->format('Y-m-d'),
            'occurred_at' => $refund->device_created_at->toIso8601String(),
            'refunded_by' => $refund->refunded_by,
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed>|null */
    public function ticket(string $ticketId): ?array
    {
        /** @var KitchenTicket|null $ticket */
        $ticket = KitchenTicket::query()->find($ticketId);
        if ($ticket === null) {
            return null;
        }

        return [
            'id' => $ticket->id,
            'order_id' => $ticket->order_id,
            'outlet_id' => $ticket->outlet_id,
            'business_date' => $ticket->business_date->format('Y-m-d'),
            'sent_at' => $ticket->sent_at->toIso8601String(),
            'sent_by' => $ticket->sent_by,
            'lines' => array_map(fn (array $l) => $l + ['sent_to_kitchen_at' => $ticket->sent_at->toIso8601String(), 'status' => 'sold'], $ticket->lines),
        ];
    }

    /**
     * Pesanan yang diterima server sejak waktu tertentu (untuk memeriksa posting stok yang terlewat).
     *
     * @return list<array{id: string, voided_after_payment: bool}>
     */
    public function ordersSince(CarbonImmutable $since): array
    {
        return Order::query()
            ->where('business_date', '>=', $since->subDays(2)->format('Y-m-d'))
            ->where(fn ($q) => $q->where('server_received_at', '>=', $since)->orWhere('voided_at', '>=', $since))
            ->get(['id', 'business_date', 'status', 'completed_at', 'voided_at'])
            ->map(fn (Order $o) => ['id' => $o->id, 'voided_after_payment' => $o->status === Order::VOIDED && $o->completed_at !== null])
            ->values()->all();
    }

    /** @return list<array{id: string, order_id: string}> */
    public function refundsSince(CarbonImmutable $since): array
    {
        return Refund::query()->where('server_received_at', '>=', $since)->get(['id', 'order_id'])
            ->map(fn (Refund $r) => ['id' => $r->id, 'order_id' => $r->order_id])->values()->all();
    }

    /** @return list<array{id: string, order_id: string}> */
    public function ticketsSince(CarbonImmutable $since): array
    {
        return KitchenTicket::query()->where('server_received_at', '>=', $since)->get(['id', 'order_id'])
            ->map(fn (KitchenTicket $t) => ['id' => $t->id, 'order_id' => $t->order_id])->values()->all();
    }

    /**
     * Penjualan bersih (setelah diskon, tanpa pajak, service charge & pembulatan) per outlet, dikurangi porsi refund.
     * Rumus total − pajak − service − pembulatan berlaku untuk harga termasuk maupun belum termasuk pajak.
     *
     * @param  list<string>  $outletIds
     * @return array<string, string>
     */
    public function netSales(array $outletIds, string $from, string $to): array
    {
        if ($outletIds === []) {
            return [];
        }
        $result = [];
        $sales = Order::query()
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', [$from, $to])
            ->where('status', '<>', Order::VOIDED)
            ->groupBy('outlet_id')
            ->selectRaw('outlet_id, SUM(total - tax - service_charge - rounding) AS net')
            ->toBase()->get();
        foreach ($sales as $row) {
            $result[(string) $row->outlet_id] = BigDecimal::of((string) $row->net);
        }
        $refunds = Refund::query()
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'refunds.order_id')->on('orders.business_date', '=', 'refunds.order_business_date');
            })
            ->whereIn('refunds.outlet_id', $outletIds)
            ->whereBetween('refunds.business_date', [$from, $to])
            ->groupBy('refunds.outlet_id')
            ->selectRaw('refunds.outlet_id AS outlet_id, SUM(CASE WHEN orders.total > 0 THEN refunds.amount * (orders.total - orders.tax - orders.service_charge - orders.rounding) / orders.total ELSE 0 END) AS net')
            ->toBase()->get();
        foreach ($refunds as $row) {
            $id = (string) $row->outlet_id;
            $result[$id] = ($result[$id] ?? BigDecimal::zero())->minus((string) $row->net);
        }

        return array_map(fn (BigDecimal $v) => (string) $v->toScale(2, RoundingMode::HALF_UP), $result);
    }
}
