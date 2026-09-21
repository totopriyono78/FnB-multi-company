<?php

namespace App\Modules\Reporting\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Laporan penjualan (FR-RPT-02, FR-RPT-10) dengan definisi angka ADR 0006.
 */
class SalesReport
{
    /** Dimensi yang tersedia => label. */
    public const DIMENSIONS = [
        'day' => 'Per hari',
        'hour' => 'Per jam',
        'weekday' => 'Per hari dalam minggu',
        'outlet' => 'Per outlet',
        'brand' => 'Per brand',
        'category' => 'Per kategori',
        'item' => 'Per item',
        'channel' => 'Per channel',
        'cashier' => 'Per kasir',
        'payment' => 'Per metode pembayaran',
    ];

    public const WEEKDAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public function __construct(
        private readonly RefundFacts $refunds,
        private readonly ReportLabels $labels,
    ) {}

    /**
     * Ringkasan periode.
     *
     * @return array{order_count: int, gross_sales: string, discount: string, refund: string, refund_count: int, refund_amount: string, net_sales: string, service_charge: string, tax: string, rounding: string, total_collected: string, average_ticket: string, void_count: int, void_amount: string}
     */
    public function summary(ReportFilter $filter): array
    {
        $row = $filter->outletIds === [] ? null : $this->paidOrders($filter)
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(o.item_discount + o.order_discount), 0) AS discount,
                COALESCE(SUM(o.total - o.tax - o.service_charge - o.rounding), 0) AS net,
                COALESCE(SUM(o.service_charge), 0) AS sc, COALESCE(SUM(o.tax), 0) AS tax,
                COALESCE(SUM(o.rounding), 0) AS rounding, COALESCE(SUM(o.total), 0) AS total')
            ->first();

        $voids = $filter->outletIds === [] ? null : DB::table('orders as o')
            ->whereIn('o.outlet_id', $filter->outletIds)
            ->whereBetween('o.business_date', [$filter->fromDate(), $filter->toDate()])
            ->where('o.status', 'voided')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS amount')
            ->first();

        $refundNet = BigDecimal::zero();
        $refundTax = BigDecimal::zero();
        $refundSc = BigDecimal::zero();
        $refundAmount = BigDecimal::zero();
        $facts = $this->refunds->forFilter($filter);
        foreach ($facts as $f) {
            $refundNet = $refundNet->plus($f['net']);
            $refundTax = $refundTax->plus($f['tax']);
            $refundSc = $refundSc->plus($f['service_charge']);
            $refundAmount = $refundAmount->plus($f['amount']);
        }

        $orders = (int) ($row->orders ?? 0);
        $netBefore = BigDecimal::of((string) ($row->net ?? '0'));
        $discount = BigDecimal::of((string) ($row->discount ?? '0'));

        return [
            'order_count' => $orders,
            'gross_sales' => self::money($netBefore->plus($discount)),
            'discount' => self::money($discount),
            'refund' => self::money($refundNet),
            'refund_count' => count($facts),
            'refund_amount' => self::money($refundAmount),
            'net_sales' => self::money($netBefore->minus($refundNet)),
            'service_charge' => self::money(BigDecimal::of((string) ($row->sc ?? '0'))->minus($refundSc)),
            'tax' => self::money(BigDecimal::of((string) ($row->tax ?? '0'))->minus($refundTax)),
            'rounding' => self::money((string) ($row->rounding ?? '0')),
            'total_collected' => self::money(BigDecimal::of((string) ($row->total ?? '0'))->minus($refundAmount)),
            'average_ticket' => $orders === 0 ? '0.00' : self::money($netBefore->dividedBy($orders, 2, RoundingMode::HALF_UP)),
            'void_count' => (int) ($voids->n ?? 0),
            'void_amount' => self::money((string) ($voids->amount ?? '0')),
        ];
    }

    /**
     * Rincian per dimensi.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(ReportFilter $filter, string $dimension): array
    {
        if (! array_key_exists($dimension, self::DIMENSIONS)) {
            throw new InvalidArgumentException("Dimensi laporan tidak dikenal: {$dimension}");
        }
        if ($filter->outletIds === []) {
            return [];
        }

        return match ($dimension) {
            'payment' => $this->byPayment($filter),
            'item', 'category' => $this->byLine($filter, $dimension),
            default => $this->byOrder($filter, $dimension),
        };
    }

    public function table(ReportFilter $filter, string $dimension): ReportTable
    {
        $rows = $this->breakdown($filter, $dimension);
        $summary = $this->summary($filter);
        $m = ReportTable::MONEY;
        $n = ReportTable::NUMBER;
        $p = ReportTable::PERCENT;
        $label = ['label' => ['label' => self::dimensionHeading($dimension), 'type' => ReportTable::TEXT]];

        if ($dimension === 'payment') {
            $columns = $label + [
                'count' => ['label' => 'Pembayaran', 'type' => $n],
                'amount' => ['label' => 'Nominal', 'type' => $m],
                'refund' => ['label' => 'Refund', 'type' => $m],
                'net_received' => ['label' => 'Diterima bersih', 'type' => $m],
                'mdr' => ['label' => 'Biaya MDR', 'type' => $m],
                'share' => ['label' => 'Porsi', 'type' => $p],
            ];
        } elseif (in_array($dimension, ['item', 'category'], true)) {
            $columns = $label + ($dimension === 'item' ? ['category' => ['label' => 'Kategori', 'type' => ReportTable::TEXT]] : []) + [
                'qty' => ['label' => 'Terjual', 'type' => $n],
                'refund_qty' => ['label' => 'Di-refund', 'type' => $n],
                'gross_sales' => ['label' => 'Penjualan kotor', 'type' => $m],
                'discount' => ['label' => 'Diskon', 'type' => $m],
                'refund' => ['label' => 'Refund', 'type' => $m],
                'net_sales' => ['label' => 'Penjualan bersih', 'type' => $m],
                'share' => ['label' => 'Kontribusi', 'type' => $p],
            ];
        } else {
            $columns = $label + [
                'orders' => ['label' => 'Transaksi', 'type' => $n],
                'gross_sales' => ['label' => 'Penjualan kotor', 'type' => $m],
                'discount' => ['label' => 'Diskon', 'type' => $m],
                'refund' => ['label' => 'Refund', 'type' => $m],
                'net_sales' => ['label' => 'Penjualan bersih', 'type' => $m],
                'service_charge' => ['label' => 'Service charge', 'type' => $m],
                'tax' => ['label' => 'Pajak', 'type' => $m],
                'average_ticket' => ['label' => 'Rata-rata/transaksi', 'type' => $m],
                'share' => ['label' => 'Kontribusi', 'type' => $p],
            ];
        }

        $totals = ['label' => 'Total'];
        foreach ($columns as $key => $col) {
            if ($key === 'label' || $col['type'] === ReportTable::TEXT) {
                continue;
            }
            $totals[$key] = match ($key) {
                'share' => $rows === [] ? null : '100.00',
                'average_ticket' => $summary['average_ticket'],
                // Total uang diambil dari ringkasan (tanpa pembulatan per baris) agar sama persis di semua laporan.
                'gross_sales', 'discount', 'refund', 'net_sales', 'service_charge', 'tax' => $dimension === 'payment' && $key === 'refund'
                    ? $summary['refund_amount'] : $summary[$key],
                default => self::sum($rows, $key, $col['type']),
            };
        }
        if (in_array($dimension, ['item', 'category'], true)) {
            $totals['category'] = null;
        }

        $cols = [];
        foreach ($columns as $key => $col) {
            $cols[$key] = $col;
        }

        return new ReportTable(
            key: 'sales.'.$dimension,
            title: 'Laporan Penjualan — '.self::DIMENSIONS[$dimension],
            columns: $cols,
            rows: array_map(fn (array $r) => array_intersect_key($r, $cols), $rows),
            totals: $totals,
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: [
                ['label' => 'Penjualan bersih', 'value' => $summary['net_sales'], 'type' => $m],
                ['label' => 'Transaksi', 'value' => (string) $summary['order_count'], 'type' => $n],
                ['label' => 'Rata-rata/transaksi', 'value' => $summary['average_ticket'], 'type' => $m],
                ['label' => 'Diskon', 'value' => $summary['discount'], 'type' => $m],
                ['label' => 'Refund', 'value' => $summary['refund'], 'type' => $m],
                ['label' => 'Pajak', 'value' => $summary['tax'], 'type' => $m],
                ['label' => 'Service charge', 'value' => $summary['service_charge'], 'type' => $m],
                ['label' => 'Total diterima', 'value' => $summary['total_collected'], 'type' => $m],
            ],
            notes: self::notes($dimension),
        );
    }

    public static function dimensionHeading(string $dimension): string
    {
        return match ($dimension) {
            'day' => 'Tanggal',
            'hour' => 'Jam',
            'weekday' => 'Hari',
            'outlet' => 'Outlet',
            'brand' => 'Brand',
            'category' => 'Kategori',
            'item' => 'Item',
            'channel' => 'Channel',
            'cashier' => 'Kasir',
            'payment' => 'Metode',
            default => $dimension,
        };
    }

    /** @return list<string> */
    public static function notes(string $dimension): array
    {
        $notes = [
            'Penjualan bersih = total transaksi lunas tanpa pajak, service charge, dan pembulatan, dikurangi refund. Penjualan kotor = penjualan bersih sebelum refund + diskon.',
            'Refund dicatat pada hari refund terjadi; transaksi yang di-void tidak dihitung.',
        ];
        if (in_array($dimension, ['item', 'category'], true)) {
            $notes[] = 'Penjualan per item dibagi dari penjualan bersih transaksi sesuai nilai baris, sehingga jumlahnya sama dengan total transaksi.';
        }
        if (in_array($dimension, ['hour', 'channel', 'cashier'], true)) {
            $notes[] = 'Refund mengikuti jam, channel, dan kasir transaksi asal.';
        }
        if ($dimension === 'payment') {
            $notes[] = 'Diterima bersih = nominal pembayaran dikurangi refund dengan metode yang sama; biaya MDR belum dikurangkan.';
        }

        return $notes;
    }

    private function paidOrders(ReportFilter $filter): Builder
    {
        return DB::table('orders as o')
            ->whereIn('o.outlet_id', $filter->outletIds)
            ->whereBetween('o.business_date', [$filter->fromDate(), $filter->toDate()])
            ->where('o.status', '<>', 'voided')
            ->when($filter->until, fn (Builder $q) => $q->whereRaw('COALESCE(o.completed_at, o.device_created_at) <= ?', [$filter->until?->toIso8601String()]));
    }

    /** @return list<array<string, mixed>> */
    private function byOrder(ReportFilter $filter, string $dimension): array
    {
        $expr = match ($dimension) {
            'day' => "to_char(o.business_date, 'YYYY-MM-DD')",
            'hour' => 'EXTRACT(HOUR FROM COALESCE(o.completed_at, o.device_created_at) AT TIME ZONE ot.timezone)::int',
            'weekday' => 'EXTRACT(ISODOW FROM o.business_date)::int',
            'outlet' => 'o.outlet_id::text',
            'brand' => 'ot.brand_id::text',
            'channel' => 'o.channel_code',
            'cashier' => "COALESCE(o.cashier_id::text, '')",
            default => throw new InvalidArgumentException($dimension),
        };

        $rows = $this->paidOrders($filter)
            ->join('outlets as ot', 'ot.id', '=', 'o.outlet_id')
            ->groupByRaw('1')
            ->selectRaw("{$expr} AS k, COUNT(*) AS orders,
                SUM(o.item_discount + o.order_discount) AS discount,
                SUM(o.total - o.tax - o.service_charge - o.rounding) AS net,
                SUM(o.service_charge) AS sc, SUM(o.tax) AS tax")
            ->get();

        /** @var array<string, array{orders: int, discount: BigDecimal, net: BigDecimal, sc: BigDecimal, tax: BigDecimal, refund: BigDecimal, refund_sc: BigDecimal, refund_tax: BigDecimal}> $acc */
        $acc = [];
        $blank = fn () => ['orders' => 0, 'discount' => BigDecimal::zero(), 'net' => BigDecimal::zero(), 'sc' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'refund' => BigDecimal::zero(), 'refund_sc' => BigDecimal::zero(), 'refund_tax' => BigDecimal::zero()];
        foreach ($rows as $r) {
            $k = (string) $r->k;
            $acc[$k] = ['orders' => (int) $r->orders, 'discount' => BigDecimal::of((string) $r->discount), 'net' => BigDecimal::of((string) $r->net), 'sc' => BigDecimal::of((string) $r->sc), 'tax' => BigDecimal::of((string) $r->tax)] + $blank();
        }

        $brandOf = $dimension === 'brand' ? $this->labels->outletBrands($filter->outletIds) : [];
        foreach ($this->refunds->forFilter($filter) as $f) {
            $k = match ($dimension) {
                'day' => $f['business_date'],
                'hour' => (string) $f['hour'],
                'weekday' => (string) CarbonImmutable::parse($f['business_date'])->isoWeekday(),
                'outlet' => $f['outlet_id'],
                'brand' => $brandOf[$f['outlet_id']] ?? '',
                'channel' => $f['channel_code'],
                default => $f['cashier_id'] ?? '',
            };
            $acc[$k] ??= $blank();
            $acc[$k]['refund'] = $acc[$k]['refund']->plus($f['net']);
            $acc[$k]['refund_sc'] = $acc[$k]['refund_sc']->plus($f['service_charge']);
            $acc[$k]['refund_tax'] = $acc[$k]['refund_tax']->plus($f['tax']);
        }

        $names = $this->labels->names($dimension, array_keys($acc));
        $netTotal = array_reduce($acc, fn (BigDecimal $c, array $a) => $c->plus($a['net'])->minus($a['refund']), BigDecimal::zero());
        $out = [];
        foreach ($acc as $k => $a) {
            $net = $a['net']->minus($a['refund']);
            $out[] = [
                'key' => (string) $k,
                'label' => $names[(string) $k] ?? self::fallbackLabel($dimension, (string) $k),
                'orders' => $a['orders'],
                'gross_sales' => self::money($a['net']->plus($a['discount'])),
                'discount' => self::money($a['discount']),
                'refund' => self::money($a['refund']),
                'net_sales' => self::money($net),
                'service_charge' => self::money($a['sc']->minus($a['refund_sc'])),
                'tax' => self::money($a['tax']->minus($a['refund_tax'])),
                'average_ticket' => $a['orders'] === 0 ? '0.00' : self::money($a['net']->dividedBy($a['orders'], 2, RoundingMode::HALF_UP)),
                'share' => self::percent($net, $netTotal),
            ];
        }

        return $this->sort($out, $dimension);
    }

    /** @return list<array<string, mixed>> */
    private function byLine(ReportFilter $filter, string $dimension): array
    {
        $col = $dimension === 'item' ? 'i.item_id' : 'i.category_id';
        $lines = DB::table('order_items as i')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'i.order_id')->on('o.business_date', '=', 'i.business_date');
            })
            ->whereIn('o.outlet_id', $filter->outletIds)
            ->whereBetween('i.business_date', [$filter->fromDate(), $filter->toDate()])
            ->where('o.status', '<>', 'voided')
            ->where('i.status', 'sold')
            ->when($filter->until, fn (Builder $q) => $q->whereRaw('COALESCE(o.completed_at, o.device_created_at) <= ?', [$filter->until?->toIso8601String()]))
            ->selectRaw("{$col}::text AS k, i.item_id::text AS item_id, i.category_id::text AS category_id, i.qty, i.item_discount + i.order_discount AS discount,
                COALESCE((o.total - o.tax - o.service_charge - o.rounding) * i.net / NULLIF(SUM(i.net) OVER (PARTITION BY i.order_id), 0), 0) AS net");

        $rows = DB::query()->fromSub($lines, 'l')
            ->groupBy('k')
            ->selectRaw('k, MAX(category_id) AS category_id, SUM(qty) AS qty, SUM(discount) AS discount, SUM(net) AS net')
            ->get();

        $acc = [];
        $blank = fn () => ['qty' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'net' => BigDecimal::zero(), 'refund' => BigDecimal::zero(), 'refund_qty' => BigDecimal::zero(), 'category_id' => null];
        foreach ($rows as $r) {
            $acc[(string) $r->k] = ['qty' => BigDecimal::of((string) $r->qty), 'discount' => BigDecimal::of((string) $r->discount), 'net' => BigDecimal::of((string) $r->net), 'category_id' => $r->category_id] + $blank();
        }
        foreach ($this->refunds->forFilter($filter) as $f) {
            foreach ($f['lines'] as $line) {
                $k = (string) ($dimension === 'item' ? $line['item_id'] : $line['category_id']);
                $acc[$k] ??= $blank();
                $acc[$k]['refund'] = $acc[$k]['refund']->plus($line['net']);
                $acc[$k]['refund_qty'] = $acc[$k]['refund_qty']->plus($line['qty']);
                $acc[$k]['category_id'] ??= $line['category_id'];
            }
        }

        $names = $this->labels->names($dimension, array_keys($acc));
        $categories = $dimension === 'item'
            ? $this->labels->names('category', array_values(array_filter(array_map(fn ($a) => $a['category_id'], $acc))))
            : [];
        $netTotal = array_reduce($acc, fn (BigDecimal $c, array $a) => $c->plus($a['net'])->minus($a['refund']), BigDecimal::zero());
        $out = [];
        foreach ($acc as $k => $a) {
            $net = $a['net']->minus($a['refund']);
            $out[] = [
                'key' => (string) $k,
                'label' => $names[(string) $k] ?? self::fallbackLabel($dimension, (string) $k),
                'category' => $dimension === 'item' ? ($categories[(string) $a['category_id']] ?? 'Tanpa kategori') : null,
                'qty' => (string) $a['qty']->toScale(3, RoundingMode::HALF_UP),
                'refund_qty' => (string) $a['refund_qty']->toScale(3, RoundingMode::HALF_UP),
                'gross_sales' => self::money($a['net']->plus($a['discount'])),
                'discount' => self::money($a['discount']),
                'refund' => self::money($a['refund']),
                'net_sales' => self::money($net),
                'share' => self::percent($net, $netTotal),
            ];
        }

        usort($out, fn ($a, $b) => BigDecimal::of($b['net_sales'])->compareTo($a['net_sales']) ?: strcmp((string) $a['label'], (string) $b['label']));

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function byPayment(ReportFilter $filter): array
    {
        $rows = DB::table('payments as p')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'p.order_id')->on('o.business_date', '=', 'p.business_date');
            })
            ->whereIn('p.outlet_id', $filter->outletIds)
            ->whereBetween('p.business_date', [$filter->fromDate(), $filter->toDate()])
            ->where('o.status', '<>', 'voided')
            ->when($filter->until, fn (Builder $q) => $q->where('p.device_created_at', '<=', $filter->until))
            ->groupBy('p.method')
            ->selectRaw('p.method AS k, COUNT(*) AS n, SUM(p.amount) AS amount, SUM(p.mdr_amount) AS mdr')
            ->get();

        $acc = [];
        $blank = fn () => ['count' => 0, 'amount' => BigDecimal::zero(), 'mdr' => BigDecimal::zero(), 'refund' => BigDecimal::zero()];
        foreach ($rows as $r) {
            $acc[(string) $r->k] = ['count' => (int) $r->n, 'amount' => BigDecimal::of((string) $r->amount), 'mdr' => BigDecimal::of((string) $r->mdr), 'refund' => BigDecimal::zero()];
        }
        foreach ($this->refunds->forFilter($filter) as $f) {
            $acc[$f['method']] ??= $blank();
            $acc[$f['method']]['refund'] = $acc[$f['method']]['refund']->plus($f['amount']);
        }
        $total = array_reduce($acc, fn (BigDecimal $c, array $a) => $c->plus($a['amount'])->minus($a['refund']), BigDecimal::zero());

        $out = [];
        foreach ($acc as $k => $a) {
            $received = $a['amount']->minus($a['refund']);
            $out[] = [
                'key' => (string) $k,
                'label' => ReportLabels::paymentMethod((string) $k),
                'count' => $a['count'],
                'amount' => self::money($a['amount']),
                'refund' => self::money($a['refund']),
                'net_received' => self::money($received),
                'mdr' => self::money($a['mdr']),
                'share' => self::percent($received, $total),
            ];
        }
        usort($out, fn ($a, $b) => BigDecimal::of($b['net_received'])->compareTo($a['net_received']));

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sort(array $rows, string $dimension): array
    {
        if (in_array($dimension, ['day', 'hour', 'weekday'], true)) {
            if ($dimension === 'hour') {
                // Jam operasional F&B dapat melewati tengah malam; urut tetap 0–23 agar mudah dibaca.
                usort($rows, fn ($a, $b) => (int) $a['key'] <=> (int) $b['key']);
            } else {
                usort($rows, fn ($a, $b) => strcmp((string) $a['key'], (string) $b['key']));
            }

            return $rows;
        }
        usort($rows, fn ($a, $b) => BigDecimal::of((string) $b['net_sales'])->compareTo((string) $a['net_sales']) ?: strcmp((string) $a['label'], (string) $b['label']));

        return $rows;
    }

    private static function fallbackLabel(string $dimension, string $key): string
    {
        return match ($dimension) {
            'day' => CarbonImmutable::parse($key)->locale('id')->translatedFormat('D, j M Y'),
            'hour' => sprintf('%02d.00–%02d.59', (int) $key, (int) $key),
            'weekday' => self::WEEKDAYS[(int) $key] ?? $key,
            'category' => 'Tanpa kategori',
            'cashier' => $key === '' ? 'Tidak diketahui' : 'Pengguna dihapus',
            default => $key === '' ? 'Tidak diketahui' : $key,
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private static function sum(array $rows, string $key, string $type): string
    {
        $total = BigDecimal::zero();
        foreach ($rows as $r) {
            $total = $total->plus((string) ($r[$key] ?? '0'));
        }

        return $type === ReportTable::MONEY ? self::money($total) : (string) $total;
    }

    public static function money(BigDecimal|string $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    public static function percent(BigDecimal|string $part, BigDecimal|string $whole): ?string
    {
        $whole = BigDecimal::of((string) $whole);
        if ($whole->isZero()) {
            return null;
        }

        return (string) BigDecimal::of((string) $part)->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HALF_UP);
    }
}
