<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Shift;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan shift / laporan X (FR-POS-02, FR-POS-04). Dipakai saat tutup shift dan tampilan back-office.
 * Kas seharusnya = modal + penjualan tunai + kas masuk − kas keluar − refund tunai (void setelah bayar mengembalikan uang).
 */
class ShiftReport
{
    /** @return array<string, mixed> */
    public function build(Shift $shift): array
    {
        $date = $shift->business_date->format('Y-m-d');

        $orders = DB::table('orders')
            ->where('company_id', $shift->company_id)
            ->where('shift_id', $shift->id)
            ->where('business_date', $date)
            ->selectRaw("
                count(*) filter (where status <> 'voided') as order_count,
                count(*) filter (where status = 'voided') as void_count,
                coalesce(sum(total) filter (where status = 'voided' and paid_total > 0), 0) as void_after_payment_total,
                coalesce(sum(total) filter (where status <> 'voided'), 0) as gross_sales,
                coalesce(sum(item_discount + order_discount) filter (where status <> 'voided'), 0) as discount_total,
                coalesce(sum(service_charge) filter (where status <> 'voided'), 0) as service_charge_total,
                coalesce(sum(tax) filter (where status <> 'voided'), 0) as tax_total,
                coalesce(sum(rounding) filter (where status <> 'voided'), 0) as rounding_total
            ")
            ->first();

        $payments = DB::table('payments as p')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'p.order_id')->on('o.business_date', '=', 'p.business_date');
            })
            ->where('p.company_id', $shift->company_id)
            ->where('p.shift_id', $shift->id)
            ->where('p.business_date', $date)
            ->where('o.status', '<>', Order::VOIDED)
            ->groupBy('p.method')
            ->selectRaw('p.method, count(*) as count, sum(p.amount) as amount, sum(p.mdr_amount) as mdr')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->method => ['count' => (int) $r->count, 'amount' => $this->money($r->amount), 'mdr' => $this->money($r->mdr)]])
            ->all();

        $refunds = DB::table('refunds')
            ->where('company_id', $shift->company_id)
            ->where('shift_id', $shift->id)
            ->groupBy('method')
            ->selectRaw('method, count(*) as count, sum(amount) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->method => ['count' => (int) $r->count, 'amount' => $this->money($r->amount)]])
            ->all();

        $movements = DB::table('cash_movements')
            ->where('company_id', $shift->company_id)
            ->where('shift_id', $shift->id)
            ->groupBy('type')
            ->selectRaw('type, count(*) as count, sum(amount) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->type => ['count' => (int) $r->count, 'amount' => $this->money($r->amount)]])
            ->all();

        $cashIn = BigDecimal::of($movements['in']['amount'] ?? '0');
        $cashOut = BigDecimal::of($movements['out']['amount'] ?? '0');
        $cashSales = BigDecimal::of($payments['cash']['amount'] ?? '0');
        $cashRefunds = BigDecimal::of($refunds['cash']['amount'] ?? '0');
        $expected = BigDecimal::of((string) $shift->opening_cash)->plus($cashSales)->plus($cashIn)->minus($cashOut)->minus($cashRefunds);

        $refundTotal = array_reduce($refunds, fn (BigDecimal $c, array $r) => $c->plus($r['amount']), BigDecimal::zero());
        $gross = BigDecimal::of((string) ($orders->gross_sales ?? '0'));

        return [
            'shift_id' => $shift->id,
            'business_date' => $date,
            'order_count' => (int) ($orders->order_count ?? 0),
            'void_count' => (int) ($orders->void_count ?? 0),
            'void_after_payment_total' => $this->money($orders->void_after_payment_total ?? '0'),
            'sales_total' => (string) $gross->toScale(2),
            'refund_total' => (string) $refundTotal->toScale(2),
            'net_sales' => (string) $gross->minus($refundTotal)->toScale(2),
            'discount_total' => $this->money($orders->discount_total ?? '0'),
            'service_charge_total' => $this->money($orders->service_charge_total ?? '0'),
            'tax_total' => $this->money($orders->tax_total ?? '0'),
            'rounding_total' => $this->money($orders->rounding_total ?? '0'),
            'payments' => $payments,
            'refunds' => $refunds,
            'cash' => [
                'opening' => $this->money($shift->opening_cash),
                'sales' => (string) $cashSales->toScale(2),
                'in' => (string) $cashIn->toScale(2),
                'out' => (string) $cashOut->toScale(2),
                'refunds' => (string) $cashRefunds->toScale(2),
                'expected' => (string) $expected->toScale(2),
            ],
            'drawer_open_count' => $movements['drawer_open']['count'] ?? 0,
        ];
    }

    private function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(2);
    }
}
