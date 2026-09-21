<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Inventory\Domain\Models\StockAdjustment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laporan inventory (FR-RPT-06): ringkasan mutasi, waste, hasil opname, dan posisi stok.
 * Food cost tersedia di laporan laba kotor dan halaman Food Cost.
 */
class InventoryReport
{
    public const VIEWS = [
        'movements' => 'Ringkasan mutasi',
        'waste' => 'Waste',
        'counts' => 'Hasil opname',
        'stock' => 'Posisi stok saat ini',
    ];

    public function table(ReportFilter $filter, string $view): ReportTable
    {
        return match ($view) {
            'movements' => $this->movements($filter),
            'waste' => $this->waste($filter),
            'counts' => $this->counts($filter),
            'stock' => $this->stock($filter),
            default => throw new \InvalidArgumentException("Tampilan laporan inventory tidak dikenal: {$view}"),
        };
    }

    private function movements(ReportFilter $filter): ReportTable
    {
        $rows = $filter->outletIds === [] ? collect() : DB::table('stock_movements as m')
            ->join('ingredients as g', 'g.id', '=', 'm.ingredient_id')
            ->whereIn('m.outlet_id', $filter->outletIds)
            ->where('m.business_date', '<=', $filter->toDate())
            ->groupBy('m.ingredient_id', 'g.name', 'g.base_unit')
            ->selectRaw("m.ingredient_id, g.name, g.base_unit,
                COALESCE(SUM(m.qty) FILTER (WHERE m.business_date < ?), 0) AS opening_qty,
                COALESCE(SUM(m.value) FILTER (WHERE m.business_date < ?), 0) AS opening_value,
                COALESCE(SUM(m.qty) FILTER (WHERE m.business_date >= ? AND m.type IN ('receipt', 'transfer_in')), 0) AS in_qty,
                COALESCE(SUM(m.qty) FILTER (WHERE m.business_date >= ? AND m.type IN ('sale', 'sale_return')), 0) AS sale_qty,
                COALESCE(SUM(m.qty) FILTER (WHERE m.business_date >= ? AND m.type = 'waste'), 0) AS waste_qty,
                COALESCE(SUM(m.qty) FILTER (WHERE m.business_date >= ? AND m.type = 'transfer_out'), 0) AS transfer_qty,
                COALESCE(SUM(m.qty) FILTER (WHERE m.business_date >= ? AND m.type IN ('adjustment', 'count')), 0) AS adjust_qty,
                SUM(m.qty) AS closing_qty, SUM(m.value) AS closing_value,
                COALESCE(SUM(m.value) FILTER (WHERE m.business_date >= ? AND m.type IN ('sale', 'sale_return')), 0) AS sale_value,
                COALESCE(SUM(m.value) FILTER (WHERE m.business_date >= ? AND m.type = 'waste'), 0) AS waste_value", array_fill(0, 9, $filter->fromDate()))
            ->orderBy('g.name')
            ->get();

        $n = ReportTable::NUMBER;
        $m = ReportTable::MONEY;
        $out = [];
        $totals = ['opening_value' => BigDecimal::zero(), 'closing_value' => BigDecimal::zero(), 'sale_value' => BigDecimal::zero(), 'waste_value' => BigDecimal::zero()];
        foreach ($rows as $r) {
            $q = fn (string $v) => (string) BigDecimal::of($v)->toScale(3, RoundingMode::HALF_UP);
            $out[] = [
                'label' => (string) $r->name,
                'unit' => (string) $r->base_unit,
                'opening_qty' => $q((string) $r->opening_qty),
                'in_qty' => $q((string) $r->in_qty),
                'sale_qty' => $q((string) BigDecimal::of((string) $r->sale_qty)->negated()),
                'waste_qty' => $q((string) BigDecimal::of((string) $r->waste_qty)->negated()),
                'transfer_qty' => $q((string) BigDecimal::of((string) $r->transfer_qty)->negated()),
                'adjust_qty' => $q((string) $r->adjust_qty),
                'closing_qty' => $q((string) $r->closing_qty),
                'opening_value' => SalesReport::money((string) $r->opening_value),
                'usage_value' => SalesReport::money(BigDecimal::of((string) $r->sale_value)->plus((string) $r->waste_value)->negated()),
                'closing_value' => SalesReport::money((string) $r->closing_value),
            ];
            $totals['opening_value'] = $totals['opening_value']->plus((string) $r->opening_value);
            $totals['closing_value'] = $totals['closing_value']->plus((string) $r->closing_value);
            $totals['sale_value'] = $totals['sale_value']->minus((string) $r->sale_value);
            $totals['waste_value'] = $totals['waste_value']->minus((string) $r->waste_value);
        }

        return new ReportTable(
            key: 'inventory.movements',
            title: 'Laporan Inventory — Ringkasan Mutasi',
            columns: [
                'label' => ['label' => 'Bahan', 'type' => ReportTable::TEXT],
                'unit' => ['label' => 'Satuan', 'type' => ReportTable::TEXT],
                'opening_qty' => ['label' => 'Saldo awal', 'type' => $n],
                'in_qty' => ['label' => 'Masuk', 'type' => $n],
                'sale_qty' => ['label' => 'Pemakaian penjualan', 'type' => $n],
                'waste_qty' => ['label' => 'Waste', 'type' => $n],
                'transfer_qty' => ['label' => 'Transfer keluar', 'type' => $n],
                'adjust_qty' => ['label' => 'Penyesuaian & opname', 'type' => $n],
                'closing_qty' => ['label' => 'Saldo akhir', 'type' => $n],
                'opening_value' => ['label' => 'Nilai awal', 'type' => $m],
                'usage_value' => ['label' => 'Nilai pemakaian & waste', 'type' => $m],
                'closing_value' => ['label' => 'Nilai akhir', 'type' => $m],
            ],
            rows: $out,
            totals: [
                'label' => 'Total', 'unit' => null, 'opening_qty' => null, 'in_qty' => null, 'sale_qty' => null, 'waste_qty' => null,
                'transfer_qty' => null, 'adjust_qty' => null, 'closing_qty' => null,
                'opening_value' => SalesReport::money($totals['opening_value']),
                'usage_value' => SalesReport::money($totals['sale_value']->plus($totals['waste_value'])),
                'closing_value' => SalesReport::money($totals['closing_value']),
            ],
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: [
                ['label' => 'Nilai persediaan akhir', 'value' => SalesReport::money($totals['closing_value']), 'type' => $m],
                ['label' => 'Pemakaian penjualan', 'value' => SalesReport::money($totals['sale_value']), 'type' => $m],
                ['label' => 'Waste', 'value' => SalesReport::money($totals['waste_value']), 'type' => $m],
            ],
            notes: [
                'Jumlah dalam satuan dasar bahan. Pemakaian penjualan sudah dikurangi bahan yang kembali karena void/refund.',
                'Penyesuaian & opname termasuk saldo awal yang dicatat pada periode ini; nilai minus berarti stok berkurang.',
            ],
        );
    }

    private function waste(ReportFilter $filter): ReportTable
    {
        $rows = $filter->outletIds === [] ? collect() : DB::table('stock_movements as m')
            ->join('ingredients as g', 'g.id', '=', 'm.ingredient_id')
            ->leftJoin('stock_adjustments as a', function ($join): void {
                $join->on('a.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'stock_adjustment');
            })
            ->whereIn('m.outlet_id', $filter->outletIds)
            ->whereBetween('m.business_date', [$filter->fromDate(), $filter->toDate()])
            ->where('m.type', 'waste')
            ->groupByRaw('1, 2, 3, g.name, g.base_unit')
            ->selectRaw("COALESCE(a.reason_code, 'sales:' || m.reference_type) AS reason, m.ingredient_id, m.outlet_id, g.name, g.base_unit,
                COUNT(*) AS n, SUM(m.qty) AS qty, SUM(m.value) AS value")
            ->get();

        $outlets = app(ReportLabels::class)->names('outlet', array_values(array_unique(array_map(fn ($r) => (string) $r->outlet_id, $rows->all()))));
        $out = [];
        $total = BigDecimal::zero();
        $byReason = [];
        foreach ($rows as $r) {
            $reason = self::wasteReason((string) $r->reason);
            $value = BigDecimal::of((string) $r->value)->negated();
            $out[] = [
                'label' => (string) $r->name,
                'outlet' => $outlets[(string) $r->outlet_id] ?? '',
                'reason' => $reason,
                'count' => (int) $r->n,
                'qty' => (string) BigDecimal::of((string) $r->qty)->negated()->toScale(3, RoundingMode::HALF_UP),
                'unit' => (string) $r->base_unit,
                'value' => SalesReport::money($value),
            ];
            $total = $total->plus($value);
            $byReason[$reason] = ($byReason[$reason] ?? BigDecimal::zero())->plus($value);
        }
        usort($out, fn ($a, $b) => BigDecimal::of($b['value'])->compareTo($a['value']) ?: strcmp($a['label'], $b['label']));
        arsort($byReason);
        $summary = [['label' => 'Total waste', 'value' => SalesReport::money($total), 'type' => ReportTable::MONEY]];
        foreach (array_slice($byReason, 0, 3, true) as $reason => $value) {
            $summary[] = ['label' => $reason, 'value' => SalesReport::money($value), 'type' => ReportTable::MONEY];
        }

        return new ReportTable(
            key: 'inventory.waste',
            title: 'Laporan Inventory — Waste',
            columns: [
                'label' => ['label' => 'Bahan', 'type' => ReportTable::TEXT],
                'outlet' => ['label' => 'Outlet', 'type' => ReportTable::TEXT],
                'reason' => ['label' => 'Alasan', 'type' => ReportTable::TEXT],
                'count' => ['label' => 'Kejadian', 'type' => ReportTable::NUMBER],
                'qty' => ['label' => 'Jumlah', 'type' => ReportTable::NUMBER],
                'unit' => ['label' => 'Satuan', 'type' => ReportTable::TEXT],
                'value' => ['label' => 'Nilai', 'type' => ReportTable::MONEY],
            ],
            rows: $out,
            totals: ['label' => 'Total', 'outlet' => null, 'reason' => null, 'count' => (string) array_sum(array_column($out, 'count')), 'qty' => null, 'unit' => null, 'value' => SalesReport::money($total)],
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: $summary,
            notes: ['Nilai memakai harga pokok saat bahan dibuang. Waste dari penjualan = pesanan batal sebelum bayar yang sudah dikirim ke dapur. Bahan dari void/refund yang dibuang tetap tercatat sebagai pemakaian penjualan.'],
        );
    }

    private function counts(ReportFilter $filter): ReportTable
    {
        $rows = $filter->outletIds === [] ? collect() : DB::table('stock_counts as c')
            ->join('stock_locations as l', 'l.id', '=', 'c.location_id')
            ->join('outlets as o', 'o.id', '=', 'c.outlet_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.decided_by')
            ->whereIn('c.outlet_id', $filter->outletIds)
            ->where('c.status', 'approved')
            ->whereRaw('(c.decided_at AT TIME ZONE o.timezone)::date BETWEEN ? AND ?', [$filter->fromDate(), $filter->toDate()])
            ->orderByDesc('c.decided_at')
            ->selectRaw('c.number, o.name AS outlet, l.name AS location, c.scope, c.decided_at, o.timezone, u.name AS approver, c.variance_value,
                (SELECT COUNT(*) FROM stock_count_lines x WHERE x.stock_count_id = c.id) AS lines,
                (SELECT COUNT(*) FROM stock_count_lines x WHERE x.stock_count_id = c.id AND x.difference <> 0) AS diff_lines,
                (SELECT COALESCE(SUM(x.variance_value), 0) FROM stock_count_lines x WHERE x.stock_count_id = c.id AND x.variance_value < 0) AS short,
                (SELECT COALESCE(SUM(x.variance_value), 0) FROM stock_count_lines x WHERE x.stock_count_id = c.id AND x.variance_value > 0) AS over')
            ->get();

        $out = [];
        $total = BigDecimal::zero();
        foreach ($rows as $r) {
            $out[] = [
                'label' => (string) $r->number,
                'outlet' => $r->outlet.' · '.$r->location,
                'scope' => $r->scope === 'full' ? 'Penuh' : 'Sebagian',
                'decided_at' => CarbonImmutable::parse((string) $r->decided_at)->setTimezone((string) $r->timezone)->format('d/m/Y H.i'),
                'approver' => (string) ($r->approver ?? '-'),
                'lines' => (int) $r->lines,
                'diff_lines' => (int) $r->diff_lines,
                'short' => SalesReport::money((string) $r->short),
                'over' => SalesReport::money((string) $r->over),
                'variance_value' => SalesReport::money((string) ($r->variance_value ?? '0')),
            ];
            $total = $total->plus((string) ($r->variance_value ?? '0'));
        }

        return new ReportTable(
            key: 'inventory.counts',
            title: 'Laporan Inventory — Hasil Stock Opname',
            columns: [
                'label' => ['label' => 'Nomor', 'type' => ReportTable::TEXT],
                'outlet' => ['label' => 'Lokasi', 'type' => ReportTable::TEXT],
                'scope' => ['label' => 'Cakupan', 'type' => ReportTable::TEXT],
                'decided_at' => ['label' => 'Disetujui', 'type' => ReportTable::TEXT],
                'approver' => ['label' => 'Penyetuju', 'type' => ReportTable::TEXT],
                'lines' => ['label' => 'Bahan dihitung', 'type' => ReportTable::NUMBER],
                'diff_lines' => ['label' => 'Bahan berselisih', 'type' => ReportTable::NUMBER],
                'short' => ['label' => 'Nilai kurang', 'type' => ReportTable::MONEY],
                'over' => ['label' => 'Nilai lebih', 'type' => ReportTable::MONEY],
                'variance_value' => ['label' => 'Selisih bersih', 'type' => ReportTable::MONEY],
            ],
            rows: $out,
            totals: [
                'label' => 'Total', 'outlet' => null, 'scope' => null, 'decided_at' => null, 'approver' => null,
                'lines' => (string) array_sum(array_column($out, 'lines')),
                'diff_lines' => (string) array_sum(array_column($out, 'diff_lines')),
                'short' => SalesReport::money(array_reduce($out, fn ($c, $r) => $c->plus($r['short']), BigDecimal::zero())),
                'over' => SalesReport::money(array_reduce($out, fn ($c, $r) => $c->plus($r['over']), BigDecimal::zero())),
                'variance_value' => SalesReport::money($total),
            ],
            filters: ['Periode persetujuan' => $filter->periodLabel()] + $filter->labels,
            summary: [
                ['label' => 'Opname disetujui', 'value' => (string) count($out), 'type' => ReportTable::NUMBER],
                ['label' => 'Selisih bersih', 'value' => SalesReport::money($total), 'type' => ReportTable::MONEY],
            ],
            notes: ['Hanya opname yang sudah disetujui. Selisih = (fisik − sistem) × harga pokok saat opname dimulai.'],
        );
    }

    private function stock(ReportFilter $filter): ReportTable
    {
        $rows = $filter->outletIds === [] ? collect() : DB::table('stock_balances as b')
            ->join('stock_locations as l', 'l.id', '=', 'b.location_id')
            ->join('outlets as o', 'o.id', '=', 'l.outlet_id')
            ->join('ingredients as g', 'g.id', '=', 'b.ingredient_id')
            ->whereIn('l.outlet_id', $filter->outletIds)
            ->whereNull('g.deleted_at')
            ->orderBy('g.name')->orderBy('o.name')->orderBy('l.name')
            ->selectRaw('g.name, g.base_unit, o.name AS outlet, l.name AS location, b.qty, b.avg_cost, b.min_qty, g.min_stock')
            ->get();

        $out = [];
        $total = BigDecimal::zero();
        $low = 0;
        foreach ($rows as $r) {
            $qty = BigDecimal::of((string) $r->qty);
            $min = BigDecimal::of((string) ($r->min_qty ?? $r->min_stock ?? '0'));
            $value = $qty->multipliedBy((string) $r->avg_cost)->toScale(2, RoundingMode::HALF_UP);
            $status = $qty->isNegative() ? 'Minus' : ($min->isPositive() && $qty->isLessThan($min) ? 'Di bawah minimum' : 'Aman');
            $low += $status === 'Aman' ? 0 : 1;
            $out[] = [
                'label' => (string) $r->name,
                'location' => $r->outlet.' · '.$r->location,
                'qty' => (string) $qty->toScale(3, RoundingMode::HALF_UP),
                'unit' => (string) $r->base_unit,
                'min_qty' => $min->isPositive() ? (string) $min->toScale(3) : null,
                'avg_cost' => (string) BigDecimal::of((string) $r->avg_cost)->toScale(2, RoundingMode::HALF_UP),
                'value' => (string) $value,
                'status' => $status,
            ];
            $total = $total->plus($value);
        }

        return new ReportTable(
            key: 'inventory.stock',
            title: 'Laporan Inventory — Posisi Stok',
            columns: [
                'label' => ['label' => 'Bahan', 'type' => ReportTable::TEXT],
                'location' => ['label' => 'Lokasi', 'type' => ReportTable::TEXT],
                'qty' => ['label' => 'Saldo', 'type' => ReportTable::NUMBER],
                'unit' => ['label' => 'Satuan', 'type' => ReportTable::TEXT],
                'min_qty' => ['label' => 'Minimum', 'type' => ReportTable::NUMBER],
                'avg_cost' => ['label' => 'HPP rata-rata', 'type' => ReportTable::MONEY],
                'value' => ['label' => 'Nilai', 'type' => ReportTable::MONEY],
                'status' => ['label' => 'Status', 'type' => ReportTable::TEXT],
            ],
            rows: $out,
            totals: ['label' => 'Total', 'location' => null, 'qty' => null, 'unit' => null, 'min_qty' => null, 'avg_cost' => null, 'value' => SalesReport::money($total), 'status' => null],
            filters: ['Posisi per' => now()->setTimezone(ReportAccess::timezone())->format('d/m/Y H.i')] + $filter->labels,
            summary: [
                ['label' => 'Nilai persediaan', 'value' => SalesReport::money($total), 'type' => ReportTable::MONEY],
                ['label' => 'Perlu perhatian', 'value' => (string) $low, 'type' => ReportTable::NUMBER],
            ],
            notes: ['Posisi stok saat laporan dibuat (tidak mengikuti filter tanggal). HPP rata-rata per lokasi (moving average).'],
        );
    }

    private static function wasteReason(string $code): string
    {
        if (str_starts_with($code, 'sales:')) {
            return substr($code, 6) === 'order' ? 'Pesanan batal setelah diolah' : 'Penjualan lainnya';
        }

        return StockAdjustment::WASTE_REASONS[$code] ?? $code;
    }
}
