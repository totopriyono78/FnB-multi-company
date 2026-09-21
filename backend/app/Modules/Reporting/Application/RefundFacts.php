<?php

namespace App\Modules\Reporting\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Menguraikan refund yang terjadi di periode menjadi porsi per baris transaksi (ADR 0006).
 *
 * Refund dilaporkan pada hari bisnis refund, tetapi atribut lain (channel, kasir, jam, item) mengikuti transaksi asal.
 * Nominal refund dibagi menjadi porsi penjualan bersih, pajak, service charge, dan pembulatan secara proporsional
 * terhadap transaksi asal; porsi baris memakai nominal baris yang tercatat, atau sisa barang bila refund tanpa rincian.
 */
class RefundFacts
{
    /**
     * @return list<array{refund_id: string, business_date: string, outlet_id: string, channel_code: string, cashier_id: string|null, refunded_by: string|null, method: string, hour: int, order_business_date: string, amount: string, net: string, tax: string, service_charge: string, tax_base: string, tax_name: string, tax_rate: string, lines: list<array{order_item_id: string, item_id: string|null, category_id: string|null, qty: string, net: string}>}>
     */
    public function forFilter(ReportFilter $filter): array
    {
        if ($filter->outletIds === []) {
            return [];
        }
        // Satu laporan memakai rincian refund yang sama beberapa kali (ringkasan + rincian): simpan per filter.
        $key = md5((string) json_encode([$filter->outletIds, $filter->fromDate(), $filter->toDate(), $filter->until?->toIso8601String()]));
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        if (count($this->memo) > 20) {
            $this->memo = [];
        }

        return $this->memo[$key] = $this->load($filter);
    }

    /** @var array<string, list<array<string, mixed>>> */
    private array $memo = [];

    /**
     * @return list<array{refund_id: string, business_date: string, outlet_id: string, channel_code: string, cashier_id: string|null, refunded_by: string|null, method: string, hour: int, order_business_date: string, amount: string, net: string, tax: string, service_charge: string, tax_base: string, tax_name: string, tax_rate: string, lines: list<array{order_item_id: string, item_id: string|null, category_id: string|null, qty: string, net: string}>}>
     */
    private function load(ReportFilter $filter): array
    {

        $refunds = DB::table('refunds as r')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'r.order_id')->on('o.business_date', '=', 'r.order_business_date');
            })
            ->join('outlets as ot', 'ot.id', '=', 'r.outlet_id')
            ->whereIn('r.outlet_id', $filter->outletIds)
            ->whereBetween('r.business_date', [$filter->fromDate(), $filter->toDate()])
            ->when($filter->until, fn ($q) => $q->where('r.device_created_at', '<=', $filter->until))
            ->orderBy('r.created_at')
            ->get([
                'r.id', 'r.order_id', 'r.order_business_date', 'r.business_date', 'r.outlet_id', 'r.amount', 'r.method', 'r.lines', 'r.refunded_by', 'r.created_at',
                'o.total', 'o.tax', 'o.service_charge', 'o.rounding', 'o.channel_code', 'o.cashier_id', 'o.tax_name',
                DB::raw("COALESCE((o.totals->>'tax_base')::numeric, 0) AS tax_base"),
                DB::raw("COALESCE((o.pricing->>'tax_rate')::numeric, 0) AS tax_rate"),
                DB::raw('EXTRACT(HOUR FROM COALESCE(o.completed_at, o.device_created_at) AT TIME ZONE ot.timezone)::int AS hour'),
            ]);

        if ($refunds->isEmpty()) {
            return [];
        }

        $orderKeys = $refunds->map(fn ($r) => $r->order_id)->unique()->values()->all();
        $dates = $refunds->map(fn ($r) => $r->order_business_date)->unique()->values()->all();
        $items = DB::table('order_items')
            ->whereIn('order_id', $orderKeys)
            ->whereIn('business_date', $dates)
            ->where('status', 'sold')
            ->orderBy('line_no')
            ->get(['id', 'order_id', 'item_id', 'category_id', 'qty', 'net'])
            ->groupBy('order_id');

        // Riwayat seluruh refund per transaksi (termasuk di luar periode) untuk menghitung sisa barang.
        $history = DB::table('refunds')
            ->whereIn('order_id', $orderKeys)
            ->orderBy('created_at')
            ->get(['id', 'order_id', 'lines'])
            ->groupBy('order_id');

        $facts = [];
        foreach ($refunds as $r) {
            $total = BigDecimal::of((string) $r->total);
            $amount = BigDecimal::of((string) $r->amount);
            $ratio = fn (string $part) => $total->isZero() ? BigDecimal::zero()
                : $amount->multipliedBy($part)->dividedBy($total, 6, RoundingMode::HALF_UP);
            $net = $ratio((string) BigDecimal::of((string) $r->total)->minus((string) $r->tax)->minus((string) $r->service_charge)->minus((string) $r->rounding));

            $orderItems = $items->get($r->order_id, collect());
            $facts[] = [
                'refund_id' => (string) $r->id,
                'business_date' => (string) $r->business_date,
                'outlet_id' => (string) $r->outlet_id,
                'channel_code' => (string) $r->channel_code,
                'cashier_id' => $r->cashier_id !== null ? (string) $r->cashier_id : null,
                'refunded_by' => $r->refunded_by !== null ? (string) $r->refunded_by : null,
                'method' => (string) $r->method,
                'hour' => (int) $r->hour,
                'order_business_date' => (string) $r->order_business_date,
                'amount' => (string) $amount,
                'net' => (string) $net,
                'tax' => (string) $ratio((string) $r->tax),
                'service_charge' => (string) $ratio((string) $r->service_charge),
                'tax_base' => (string) $ratio((string) $r->tax_base),
                'tax_name' => (string) $r->tax_name,
                'tax_rate' => (string) BigDecimal::of((string) $r->tax_rate)->toScale(2),
                'lines' => $this->lines($r, $orderItems->all(), $history->get($r->order_id, collect())->all(), $net),
            ];
        }

        return $facts;
    }

    /**
     * @param  array<int, object>  $orderItems
     * @param  array<int, object>  $history
     * @return list<array{order_item_id: string, item_id: string|null, category_id: string|null, qty: string, net: string}>
     */
    private function lines(object $refund, array $orderItems, array $history, BigDecimal $net): array
    {
        $recorded = $this->decode($refund->lines);
        $byId = [];
        foreach ($orderItems as $item) {
            $byId[(string) $item->id] = $item;
        }

        /** @var array<string, array{qty: BigDecimal, weight: BigDecimal}> $parts */
        $parts = [];
        if ($recorded !== []) {
            foreach ($recorded as $line) {
                $id = (string) $line['order_item_id'];
                $parts[$id] = [
                    'qty' => BigDecimal::of((string) $line['qty']),
                    'weight' => BigDecimal::of((string) ($line['amount'] ?? '0')),
                ];
            }
        } else {
            // Refund sisa: barang yang belum di-refund oleh refund berincian sebelumnya.
            $refunded = [];
            foreach ($history as $h) {
                if ((string) $h->id === (string) $refund->id) {
                    break;
                }
                foreach ($this->decode($h->lines) as $l) {
                    $key = (string) $l['order_item_id'];
                    $refunded[$key] = ($refunded[$key] ?? BigDecimal::zero())->plus((string) $l['qty']);
                }
            }
            foreach ($orderItems as $item) {
                $itemQty = BigDecimal::of((string) $item->qty);
                $left = $itemQty->minus($refunded[(string) $item->id] ?? BigDecimal::zero());
                if ($left->isPositive() && $itemQty->isPositive()) {
                    $parts[(string) $item->id] = [
                        'qty' => $left,
                        'weight' => BigDecimal::of((string) $item->net)->multipliedBy($left)->dividedBy($itemQty, 6, RoundingMode::HALF_UP),
                    ];
                }
            }
        }

        $weightTotal = array_reduce($parts, fn (BigDecimal $c, array $p) => $c->plus($p['weight']), BigDecimal::zero());
        $lines = [];
        $allocated = BigDecimal::zero();
        $keys = array_keys($parts);
        foreach ($keys as $i => $id) {
            $part = $parts[$id];
            $share = $i === count($keys) - 1
                ? $net->minus($allocated)
                : ($weightTotal->isZero()
                    ? $net->dividedBy(count($keys), 6, RoundingMode::HALF_UP)
                    : $net->multipliedBy($part['weight'])->dividedBy($weightTotal, 6, RoundingMode::HALF_UP));
            $allocated = $allocated->plus($share);
            $item = $byId[$id] ?? null;
            $lines[] = [
                'order_item_id' => $id,
                'item_id' => $item?->item_id !== null ? (string) $item->item_id : null,
                'category_id' => $item?->category_id !== null ? (string) $item->category_id : null,
                'qty' => (string) $part['qty'],
                'net' => (string) $share,
            ];
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> */
    private function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : $json;

        return is_array($value) ? array_values($value) : [];
    }
}
