<?php

namespace App\Modules\Reporting\Application;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Laporan anti-fraud per kasir (FR-RPT-04): void, refund, diskon manual, ubah harga, buka laci, selisih kas.
 *
 * Setiap kejadian dikaitkan dengan orang yang melakukannya (bukan hanya kasir transaksi) beserta pemberi otorisasi.
 */
class FraudReport
{
    public function __construct(
        private readonly SalesReport $sales,
        private readonly ReportLabels $labels,
    ) {}

    /** @return list<array<string, mixed>> */
    public function perCashier(ReportFilter $filter): array
    {
        if ($filter->outletIds === []) {
            return [];
        }
        $zero = fn () => [
            'orders' => 0, 'net_sales' => BigDecimal::zero(),
            'void_before_count' => 0, 'void_before_amount' => BigDecimal::zero(),
            'void_after_count' => 0, 'void_after_amount' => BigDecimal::zero(),
            'line_void_count' => 0, 'line_void_amount' => BigDecimal::zero(),
            'refund_count' => 0, 'refund_amount' => BigDecimal::zero(),
            'discount_count' => 0, 'discount_amount' => BigDecimal::zero(),
            'price_override_count' => 0, 'flagged_count' => 0, 'drawer_open_count' => 0,
            'shift_count' => 0, 'variance_shift_count' => 0, 'cash_short' => BigDecimal::zero(), 'cash_over' => BigDecimal::zero(),
        ];
        $acc = [];
        $add = function (?string $user, string $field, int|string $value) use (&$acc, $zero): void {
            $k = $user ?? '';
            $acc[$k] ??= $zero();
            $acc[$k][$field] = is_int($acc[$k][$field]) ? $acc[$k][$field] + (int) $value : $acc[$k][$field]->plus((string) $value);
        };

        foreach ($this->sales->breakdown($filter, 'cashier') as $row) {
            $add((string) $row['key'] ?: null, 'orders', (int) $row['orders']);
            $add((string) $row['key'] ?: null, 'net_sales', (string) $row['net_sales']);
        }

        $range = [$filter->fromDate(), $filter->toDate()];
        $orders = DB::table('orders as o')->whereIn('o.outlet_id', $filter->outletIds)->whereBetween('o.business_date', $range);

        (clone $orders)->where('o.status', 'voided')
            ->groupByRaw('1, 2')
            ->selectRaw('COALESCE(o.voided_by, o.cashier_id)::text AS u, (o.completed_at IS NOT NULL) AS after_payment, COUNT(*) AS n, SUM(o.total) AS amount')
            ->get()
            ->each(function ($r) use ($add): void {
                $prefix = $r->after_payment ? 'void_after' : 'void_before';
                $add($r->u, $prefix.'_count', (int) $r->n);
                $add($r->u, $prefix.'_amount', (string) $r->amount);
            });

        DB::table('order_items as i')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'i.order_id')->on('o.business_date', '=', 'i.business_date');
            })
            ->whereIn('o.outlet_id', $filter->outletIds)->whereBetween('i.business_date', $range)
            ->where('o.status', '<>', 'voided')->where('i.status', 'voided')
            ->groupByRaw('1')
            ->selectRaw('COALESCE(i.voided_by, o.cashier_id)::text AS u, COUNT(*) AS n, SUM(i.gross) AS amount')
            ->get()
            ->each(function ($r) use ($add): void {
                $add($r->u, 'line_void_count', (int) $r->n);
                $add($r->u, 'line_void_amount', (string) $r->amount);
            });

        DB::table('refunds as r')
            ->whereIn('r.outlet_id', $filter->outletIds)->whereBetween('r.business_date', $range)
            ->groupBy('r.refunded_by')
            ->selectRaw('r.refunded_by::text AS u, COUNT(*) AS n, SUM(r.amount) AS amount')
            ->get()
            ->each(function ($r) use ($add): void {
                $add($r->u, 'refund_count', (int) $r->n);
                $add($r->u, 'refund_amount', (string) $r->amount);
            });

        DB::table('order_discounts as d')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'd.order_id')->on('o.business_date', '=', 'd.business_date');
            })
            ->whereIn('o.outlet_id', $filter->outletIds)->whereBetween('d.business_date', $range)
            ->where('o.status', '<>', 'voided')->where('d.source', 'manual')
            ->groupBy('d.cashier_id')
            ->selectRaw('d.cashier_id::text AS u, COUNT(DISTINCT d.order_id) AS n, SUM(d.amount) AS amount')
            ->get()
            ->each(function ($r) use ($add): void {
                $add($r->u, 'discount_count', (int) $r->n);
                $add($r->u, 'discount_amount', (string) $r->amount);
            });

        (clone $orders)->where('o.status', '<>', 'voided')
            ->groupBy('o.cashier_id')
            ->selectRaw("o.cashier_id::text AS u,
                COUNT(*) FILTER (WHERE o.flags @> '[\"price_override\"]'::jsonb) AS overrides,
                COUNT(*) FILTER (WHERE o.flags <> '[]'::jsonb) AS flagged")
            ->get()
            ->each(function ($r) use ($add): void {
                $add($r->u, 'price_override_count', (int) $r->overrides);
                $add($r->u, 'flagged_count', (int) $r->flagged);
            });

        DB::table('cash_movements as c')
            ->whereIn('c.outlet_id', $filter->outletIds)->whereBetween('c.business_date', $range)
            ->where('c.type', 'drawer_open')
            ->groupBy('c.created_by')
            ->selectRaw('c.created_by::text AS u, COUNT(*) AS n')
            ->get()
            ->each(fn ($r) => $add($r->u, 'drawer_open_count', (int) $r->n));

        DB::table('shifts as s')
            ->whereIn('s.outlet_id', $filter->outletIds)->whereBetween('s.business_date', $range)
            ->where('s.status', 'closed')
            ->groupBy('s.cashier_id')
            ->selectRaw('s.cashier_id::text AS u, COUNT(*) AS n,
                COUNT(*) FILTER (WHERE s.cash_variance <> 0) AS with_variance,
                COALESCE(SUM(s.cash_variance) FILTER (WHERE s.cash_variance < 0), 0) AS short,
                COALESCE(SUM(s.cash_variance) FILTER (WHERE s.cash_variance > 0), 0) AS over')
            ->get()
            ->each(function ($r) use ($add): void {
                $add($r->u, 'shift_count', (int) $r->n);
                $add($r->u, 'variance_shift_count', (int) $r->with_variance);
                $add($r->u, 'cash_short', (string) $r->short);
                $add($r->u, 'cash_over', (string) $r->over);
            });

        $names = $this->labels->names('cashier', array_values(array_filter(array_map('strval', array_keys($acc)))));
        $rows = [];
        foreach ($acc as $user => $a) {
            $voidAmount = $a['void_after_amount']->plus($a['line_void_amount']);
            $exceptions = $voidAmount->plus($a['refund_amount'])->plus($a['discount_amount']);
            $base = $a['net_sales']->plus($exceptions);
            $rows[] = [
                'key' => (string) $user,
                'label' => $names[(string) $user] ?? ($user === '' ? 'Tidak diketahui' : 'Pengguna dihapus'),
                'orders' => $a['orders'],
                'net_sales' => SalesReport::money($a['net_sales']),
                'void_before_count' => $a['void_before_count'],
                'void_after_count' => $a['void_after_count'],
                'void_amount' => SalesReport::money($a['void_after_amount']->plus($a['void_before_amount'])),
                'line_void_count' => $a['line_void_count'],
                'refund_count' => $a['refund_count'],
                'refund_amount' => SalesReport::money($a['refund_amount']),
                'discount_count' => $a['discount_count'],
                'discount_amount' => SalesReport::money($a['discount_amount']),
                'price_override_count' => $a['price_override_count'],
                'drawer_open_count' => $a['drawer_open_count'],
                'variance_shift_count' => $a['variance_shift_count'],
                'cash_short' => SalesReport::money($a['cash_short']),
                'cash_over' => SalesReport::money($a['cash_over']),
                // Rasio hanya bermakna untuk pengguna yang juga bertransaksi (bukan manajer yang hanya menyetujui).
                'exception_rate' => $a['orders'] > 0 ? SalesReport::percent($exceptions, $base) : null,
                'flagged_count' => $a['flagged_count'],
            ];
        }
        usort($rows, fn ($a, $b) => BigDecimal::of((string) ($b['exception_rate'] ?? '0'))->compareTo((string) ($a['exception_rate'] ?? '0')) ?: strcmp((string) $a['label'], (string) $b['label']));

        return $rows;
    }

    public function table(ReportFilter $filter): ReportTable
    {
        $rows = $this->perCashier($filter);
        $n = ReportTable::NUMBER;
        $m = ReportTable::MONEY;
        $columns = [
            'label' => ['label' => 'Pengguna', 'type' => ReportTable::TEXT],
            'orders' => ['label' => 'Transaksi', 'type' => $n],
            'net_sales' => ['label' => 'Penjualan bersih', 'type' => $m],
            'void_before_count' => ['label' => 'Batal sblm bayar', 'type' => $n],
            'void_after_count' => ['label' => 'Void stlh bayar', 'type' => $n],
            'void_amount' => ['label' => 'Nilai void', 'type' => $m],
            'line_void_count' => ['label' => 'Hapus item', 'type' => $n],
            'refund_count' => ['label' => 'Refund', 'type' => $n],
            'refund_amount' => ['label' => 'Nilai refund', 'type' => $m],
            'discount_count' => ['label' => 'Diskon manual', 'type' => $n],
            'discount_amount' => ['label' => 'Nilai diskon', 'type' => $m],
            'price_override_count' => ['label' => 'Ubah harga', 'type' => $n],
            'drawer_open_count' => ['label' => 'Buka laci', 'type' => $n],
            'variance_shift_count' => ['label' => 'Shift berselisih', 'type' => $n],
            'cash_short' => ['label' => 'Kas kurang', 'type' => $m],
            'cash_over' => ['label' => 'Kas lebih', 'type' => $m],
            'exception_rate' => ['label' => 'Rasio', 'type' => ReportTable::PERCENT],
        ];
        $totals = ['label' => 'Total'];
        foreach ($columns as $key => $col) {
            if (in_array($col['type'], [$n, $m], true)) {
                $t = BigDecimal::zero();
                foreach ($rows as $r) {
                    $t = $t->plus((string) $r[$key]);
                }
                $totals[$key] = $col['type'] === $m ? SalesReport::money($t) : (string) $t;
            }
        }
        $totals['exception_rate'] = null;

        return new ReportTable(
            key: 'fraud',
            title: 'Laporan Anti-Fraud per Pengguna',
            columns: $columns,
            rows: array_map(fn ($r) => array_intersect_key($r, $columns), $rows),
            totals: $totals,
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: [
                ['label' => 'Nilai void', 'value' => (string) $totals['void_amount'], 'type' => $m],
                ['label' => 'Nilai refund', 'value' => (string) $totals['refund_amount'], 'type' => $m],
                ['label' => 'Diskon manual', 'value' => (string) $totals['discount_amount'], 'type' => $m],
                ['label' => 'Kas kurang', 'value' => (string) $totals['cash_short'], 'type' => $m],
            ],
            notes: [
                'Kejadian dicatat atas nama pengguna yang melakukannya; transaksi & penjualan atas nama kasir transaksi.',
                'Rasio pengecualian = (void setelah bayar + hapus item + refund + diskon manual) ÷ (penjualan bersih + nilai tersebut); hanya untuk pengguna yang bertransaksi.',
                'Batal sebelum bayar tidak memengaruhi penjualan, tetapi dipantau karena pesanan bisa sudah dibuat.',
            ],
        );
    }

    /**
     * Rincian kejadian terbaru untuk ditinjau (maks. 500 baris).
     *
     * @return list<array<string, mixed>>
     */
    public function events(ReportFilter $filter, ?string $userId = null, int $limit = 500): array
    {
        if ($filter->outletIds === []) {
            return [];
        }
        $range = [$filter->fromDate(), $filter->toDate()];

        $voids = DB::table('orders as o')
            ->whereIn('o.outlet_id', $filter->outletIds)->whereBetween('o.business_date', $range)
            ->where('o.status', 'voided')
            ->when($userId, fn ($q) => $q->whereRaw('COALESCE(o.voided_by, o.cashier_id) = ?', [$userId]))
            ->selectRaw("CASE WHEN o.completed_at IS NULL THEN 'void_before' ELSE 'void_after' END AS type,
                o.voided_at AS at, o.outlet_id, o.receipt_no AS reference, o.total AS amount, o.void_reason AS reason,
                COALESCE(o.voided_by, o.cashier_id) AS user_id, o.void_authorized_by AS authorized_by, o.id AS order_id");

        $refunds = DB::table('refunds as r')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'r.order_id')->on('o.business_date', '=', 'r.order_business_date');
            })
            ->whereIn('r.outlet_id', $filter->outletIds)->whereBetween('r.business_date', $range)
            ->when($userId, fn ($q) => $q->where('r.refunded_by', $userId))
            ->selectRaw("'refund' AS type, r.device_created_at AS at, r.outlet_id, o.receipt_no AS reference, r.amount, r.reason,
                r.refunded_by AS user_id, r.authorized_by, o.id AS order_id");

        $discounts = DB::table('order_discounts as d')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'd.order_id')->on('o.business_date', '=', 'd.business_date');
            })
            ->whereIn('o.outlet_id', $filter->outletIds)->whereBetween('d.business_date', $range)
            ->where('o.status', '<>', 'voided')->where('d.source', 'manual')
            ->when($userId, fn ($q) => $q->where('d.cashier_id', $userId))
            ->selectRaw("'discount' AS type, o.completed_at AS at, o.outlet_id, o.receipt_no AS reference, d.amount, d.reason,
                d.cashier_id AS user_id, d.authorized_by, o.id AS order_id");

        $shifts = DB::table('shifts as s')
            ->whereIn('s.outlet_id', $filter->outletIds)->whereBetween('s.business_date', $range)
            ->where('s.status', 'closed')->where('s.cash_variance', '<>', 0)
            ->when($userId, fn ($q) => $q->where('s.cashier_id', $userId))
            ->selectRaw("'cash_variance' AS type, s.closed_at AS at, s.outlet_id, NULL AS reference, s.cash_variance AS amount, s.variance_note AS reason,
                s.cashier_id AS user_id, s.closed_by AS authorized_by, NULL::uuid AS order_id");

        $drawer = DB::table('cash_movements as c')
            ->whereIn('c.outlet_id', $filter->outletIds)->whereBetween('c.business_date', $range)
            ->where('c.type', 'drawer_open')
            ->when($userId, fn ($q) => $q->where('c.created_by', $userId))
            ->selectRaw("'drawer_open' AS type, c.device_created_at AS at, c.outlet_id, NULL AS reference, NULL::numeric AS amount, c.reason,
                c.created_by AS user_id, c.authorized_by, NULL::uuid AS order_id");

        $rows = DB::query()->fromSub($voids->unionAll($refunds)->unionAll($discounts)->unionAll($shifts)->unionAll($drawer), 'e')
            ->orderByDesc('at')->limit($limit)->get()->all();

        $users = [];
        $outletIds = [];
        foreach ($rows as $r) {
            $outletIds[(string) $r->outlet_id] = true;
            foreach ([$r->user_id, $r->authorized_by] as $u) {
                if ($u !== null) {
                    $users[(string) $u] = true;
                }
            }
        }
        $names = $this->labels->names('cashier', array_keys($users));
        $outlets = $this->labels->names('outlet', array_keys($outletIds));

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'type' => (string) $r->type,
                'type_label' => self::EVENT_TYPES[(string) $r->type] ?? (string) $r->type,
                'at' => $r->at !== null ? (string) $r->at : null,
                'outlet' => $outlets[(string) $r->outlet_id] ?? '',
                'reference' => $r->reference !== null ? (string) $r->reference : null,
                'amount' => $r->amount !== null ? SalesReport::money((string) $r->amount) : null,
                'reason' => $r->reason !== null ? (string) $r->reason : null,
                'user' => $r->user_id !== null ? ($names[(string) $r->user_id] ?? 'Pengguna dihapus') : 'Tidak diketahui',
                'authorized_by' => $r->authorized_by !== null ? ($names[(string) $r->authorized_by] ?? 'Pengguna dihapus') : null,
                'order_id' => $r->order_id !== null ? (string) $r->order_id : null,
            ];
        }

        return $out;
    }

    public const EVENT_TYPES = [
        'void_before' => 'Batal sebelum bayar',
        'void_after' => 'Void setelah bayar',
        'refund' => 'Refund',
        'discount' => 'Diskon manual',
        'cash_variance' => 'Selisih kas',
        'drawer_open' => 'Buka laci',
    ];
}
