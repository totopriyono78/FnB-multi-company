<?php

namespace App\Modules\Reporting\Application;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Laba kotor per outlet (FR-RPT-07): penjualan bersih − HPP bahan terjual.
 * Waste dan selisih stok ditampilkan terpisah beserta laba setelah keduanya (keputusan user, ADR 0006).
 */
class GrossProfitReport
{
    public function __construct(
        private readonly SalesReport $sales,
        private readonly ReportLabels $labels,
    ) {}

    /** @return array<string, array{cogs: BigDecimal, waste: BigDecimal, variance: BigDecimal}> */
    private function costs(ReportFilter $filter): array
    {
        if ($filter->outletIds === []) {
            return [];
        }
        $rows = DB::table('stock_movements')
            ->whereIn('outlet_id', $filter->outletIds)
            ->whereBetween('business_date', [$filter->fromDate(), $filter->toDate()])
            ->whereIn('type', ['sale', 'sale_return', 'waste', 'adjustment', 'count'])
            ->whereRaw("NOT (flags @> '[\"opening\"]'::jsonb)")
            ->groupBy('outlet_id', 'type')
            ->selectRaw('outlet_id::text AS outlet_id, type, SUM(value) AS total')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $bucket = match ((string) $r->type) {
                'sale', 'sale_return' => 'cogs',
                'waste' => 'waste',
                default => 'variance',
            };
            $out[$r->outlet_id] ??= ['cogs' => BigDecimal::zero(), 'waste' => BigDecimal::zero(), 'variance' => BigDecimal::zero()];
            // Mutasi keluar bernilai negatif; biaya = kebalikannya.
            $out[$r->outlet_id][$bucket] = $out[$r->outlet_id][$bucket]->minus((string) $r->total);
        }

        return $out;
    }

    public function table(ReportFilter $filter): ReportTable
    {
        $sales = [];
        foreach ($this->sales->breakdown($filter, 'outlet') as $row) {
            $sales[(string) $row['key']] = BigDecimal::of((string) $row['net_sales']);
        }
        $summary = $this->sales->summary($filter);
        $costs = $this->costs($filter);
        $ids = array_values(array_unique(array_merge(array_keys($sales), array_keys($costs))));
        $names = $this->labels->names('outlet', $ids);

        $zero = BigDecimal::zero();
        $totals = ['net' => BigDecimal::of($summary['net_sales']), 'cogs' => $zero, 'waste' => $zero, 'variance' => $zero];
        $rows = [];
        foreach ($ids as $id) {
            $c = $costs[$id] ?? ['cogs' => $zero, 'waste' => $zero, 'variance' => $zero];
            $rows[] = ['key' => $id, 'label' => $names[$id] ?? $id] + self::line($sales[$id] ?? $zero, $c['cogs'], $c['waste'], $c['variance']);
            foreach (['cogs', 'waste', 'variance'] as $k) {
                $totals[$k] = $totals[$k]->plus($c[$k]);
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));
        $total = ['label' => 'Total'] + self::line($totals['net'], $totals['cogs'], $totals['waste'], $totals['variance']);

        $m = ReportTable::MONEY;
        $p = ReportTable::PERCENT;
        $columns = [
            'label' => ['label' => 'Outlet', 'type' => ReportTable::TEXT],
            'net_sales' => ['label' => 'Penjualan bersih', 'type' => $m],
            'cogs' => ['label' => 'HPP terjual', 'type' => $m],
            'gross_profit' => ['label' => 'Laba kotor', 'type' => $m],
            'gross_margin' => ['label' => 'Margin kotor', 'type' => $p],
            'waste' => ['label' => 'Waste', 'type' => $m],
            'variance' => ['label' => 'Selisih stok', 'type' => $m],
            'profit_after' => ['label' => 'Laba setelah waste & selisih', 'type' => $m],
            'food_cost' => ['label' => 'Food cost', 'type' => $p],
        ];

        return new ReportTable(
            key: 'gross_profit',
            title: 'Laporan Laba Kotor per Outlet',
            columns: $columns,
            rows: array_map(fn ($r) => array_intersect_key($r, $columns), $rows),
            totals: array_intersect_key($total, $columns),
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: [
                ['label' => 'Laba kotor', 'value' => $total['gross_profit'], 'type' => $m],
                ['label' => 'Margin kotor', 'value' => (string) ($total['gross_margin'] ?? '0'), 'type' => $p],
                ['label' => 'Laba setelah waste & selisih', 'value' => $total['profit_after'], 'type' => $m],
                ['label' => 'Food cost', 'value' => (string) ($total['food_cost'] ?? '0'), 'type' => $p],
            ],
            notes: [
                'HPP terjual = harga pokok bahan saat stok dipotong untuk penjualan, dikurangi bahan yang kembali ke stok karena void/refund (bahan yang dibuang tetap dihitung).',
                'Waste = bahan terbuang dan pesanan batal yang sudah diolah. Selisih stok = hasil opname dan penyesuaian, tanpa saldo awal; nilai minus berarti stok fisik lebih banyak.',
                'Food cost = (HPP terjual + waste + selisih stok) ÷ penjualan bersih.',
                'Biaya lain (gaji, sewa, MDR) belum termasuk; laba bersih tersedia di modul Akuntansi.',
            ],
        );
    }

    /** @return array<string, string|null> */
    private static function line(BigDecimal $net, BigDecimal $cogs, BigDecimal $waste, BigDecimal $variance): array
    {
        $gross = $net->minus($cogs);

        return [
            'net_sales' => SalesReport::money($net),
            'cogs' => SalesReport::money($cogs),
            'gross_profit' => SalesReport::money($gross),
            'gross_margin' => SalesReport::percent($gross, $net),
            'waste' => SalesReport::money($waste),
            'variance' => SalesReport::money($variance),
            'profit_after' => SalesReport::money($gross->minus($waste)->minus($variance)),
            'food_cost' => SalesReport::percent($cogs->plus($waste)->plus($variance), $net),
        ];
    }
}
