<?php

namespace App\Modules\Reporting\Application;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Laporan pajak daerah (PB1/PBJT) & service charge per outlet (FR-RPT-05).
 *
 * DPP dan pajak diambil dari rincian yang tersimpan saat transaksi. Refund mengurangi periode refund terjadi
 * (keputusan user, ADR 0006) sehingga laporan periode yang sudah lewat tidak berubah.
 */
class TaxReport
{
    public function __construct(
        private readonly RefundFacts $refunds,
        private readonly ReportLabels $labels,
    ) {}

    /** @return list<array<string, mixed>> */
    public function rows(ReportFilter $filter): array
    {
        if ($filter->outletIds === []) {
            return [];
        }

        $sales = DB::table('orders as o')
            ->whereIn('o.outlet_id', $filter->outletIds)
            ->whereBetween('o.business_date', [$filter->fromDate(), $filter->toDate()])
            ->where('o.status', '<>', 'voided')
            ->groupBy('o.outlet_id', 'o.tax_name')
            ->groupByRaw("COALESCE((o.pricing->>'tax_rate')::numeric, 0)")
            ->selectRaw("o.outlet_id::text AS outlet_id, o.tax_name, COALESCE((o.pricing->>'tax_rate')::numeric, 0) AS tax_rate,
                COUNT(*) AS orders,
                SUM(COALESCE((o.totals->>'tax_base')::numeric, 0)) AS tax_base,
                SUM(o.tax) AS tax, SUM(o.service_charge) AS sc")
            ->get();

        $zero = BigDecimal::zero();
        $acc = [];
        $blank = fn () => ['orders' => 0, 'tax_base' => $zero, 'tax' => $zero, 'sc' => $zero, 'r_base' => $zero, 'r_tax' => $zero, 'r_sc' => $zero];
        foreach ($sales as $s) {
            $rate = (string) BigDecimal::of((string) $s->tax_rate)->toScale(2);
            $key = $s->outlet_id.'|'.$s->tax_name.'|'.$rate;
            $acc[$key] = ['orders' => (int) $s->orders, 'tax_base' => BigDecimal::of((string) $s->tax_base), 'tax' => BigDecimal::of((string) $s->tax), 'sc' => BigDecimal::of((string) $s->sc)] + $blank();
        }
        foreach ($this->refunds->forFilter($filter) as $f) {
            $key = $f['outlet_id'].'|'.$f['tax_name'].'|'.$f['tax_rate'];
            $acc[$key] ??= $blank();
            $acc[$key]['r_base'] = $acc[$key]['r_base']->plus($f['tax_base']);
            $acc[$key]['r_tax'] = $acc[$key]['r_tax']->plus($f['tax']);
            $acc[$key]['r_sc'] = $acc[$key]['r_sc']->plus($f['service_charge']);
        }

        $outletIds = array_values(array_unique(array_map(fn (string $k) => explode('|', $k)[0], array_keys($acc))));
        $names = $this->labels->names('outlet', $outletIds);
        $npwpd = $outletIds === [] ? [] : DB::table('outlets')->whereIn('id', $outletIds)->pluck('npwpd', 'id')->all();

        $rows = [];
        foreach ($acc as $key => $a) {
            [$outletId, $taxName, $rate] = explode('|', $key);
            $rows[] = [
                'key' => $key,
                'label' => $names[$outletId] ?? $outletId,
                'npwpd' => ($npwpd[$outletId] ?? null) ?: '-',
                'tax_name' => ($taxName !== '' ? $taxName : 'Pajak').' '.ReportTable::number($rate, 2).'%',
                'orders' => $a['orders'],
                'tax_base' => SalesReport::money($a['tax_base']),
                'tax' => SalesReport::money($a['tax']),
                'refund_tax_base' => SalesReport::money($a['r_base']->negated()),
                'refund_tax' => SalesReport::money($a['r_tax']->negated()),
                'net_tax_base' => SalesReport::money($a['tax_base']->minus($a['r_base'])),
                'net_tax' => SalesReport::money($a['tax']->minus($a['r_tax'])),
                'service_charge' => SalesReport::money($a['sc']->minus($a['r_sc'])),
                '_raw' => $a,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']) ?: strcmp((string) $a['tax_name'], (string) $b['tax_name']));

        return $rows;
    }

    public function table(ReportFilter $filter): ReportTable
    {
        $rows = $this->rows($filter);
        $m = ReportTable::MONEY;
        $columns = [
            'label' => ['label' => 'Outlet', 'type' => ReportTable::TEXT],
            'npwpd' => ['label' => 'NPWPD', 'type' => ReportTable::TEXT],
            'tax_name' => ['label' => 'Pajak', 'type' => ReportTable::TEXT],
            'orders' => ['label' => 'Transaksi', 'type' => ReportTable::NUMBER],
            'tax_base' => ['label' => 'DPP', 'type' => $m],
            'tax' => ['label' => 'Pajak dipungut', 'type' => $m],
            'refund_tax_base' => ['label' => 'Koreksi DPP (refund)', 'type' => $m],
            'refund_tax' => ['label' => 'Koreksi pajak (refund)', 'type' => $m],
            'net_tax_base' => ['label' => 'DPP bersih', 'type' => $m],
            'net_tax' => ['label' => 'Pajak terutang', 'type' => $m],
            'service_charge' => ['label' => 'Service charge', 'type' => $m],
        ];

        $sum = function (string $field, bool $negate = false) use ($rows): string {
            $t = BigDecimal::zero();
            foreach ($rows as $r) {
                $t = $t->plus($r['_raw'][$field]);
            }

            return SalesReport::money($negate ? $t->negated() : $t);
        };
        $minus = fn (string $a, string $b) => SalesReport::money(BigDecimal::of($sum($a))->minus($sum($b)));
        $totals = [
            'label' => 'Total', 'npwpd' => null, 'tax_name' => null,
            'orders' => (string) array_sum(array_map(fn ($r) => $r['orders'], $rows)),
            'tax_base' => $sum('tax_base'),
            'tax' => $sum('tax'),
            'refund_tax_base' => $sum('r_base', true),
            'refund_tax' => $sum('r_tax', true),
            'net_tax_base' => $minus('tax_base', 'r_base'),
            'net_tax' => $minus('tax', 'r_tax'),
            'service_charge' => $minus('sc', 'r_sc'),
        ];

        return new ReportTable(
            key: 'tax',
            title: 'Laporan Pajak & Service Charge',
            columns: $columns,
            rows: array_map(fn ($r) => array_intersect_key($r, $columns), $rows),
            totals: $totals,
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: [
                ['label' => 'Pajak terutang', 'value' => $totals['net_tax'], 'type' => $m],
                ['label' => 'DPP bersih', 'value' => $totals['net_tax_base'], 'type' => $m],
                ['label' => 'Koreksi pajak (refund)', 'value' => $totals['refund_tax'], 'type' => $m],
                ['label' => 'Service charge', 'value' => $totals['service_charge'], 'type' => $m],
            ],
            notes: [
                'DPP dan pajak mengikuti rincian yang tersimpan pada setiap transaksi (tarif yang berlaku saat transaksi).',
                'Refund mengurangi DPP dan pajak pada periode refund terjadi; laporan periode sebelumnya tidak berubah.',
                'Transaksi yang di-void tidak dihitung. Periksa kembali ketentuan daerah sebelum pelaporan resmi.',
            ],
        );
    }
}
