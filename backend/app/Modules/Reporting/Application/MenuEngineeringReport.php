<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Inventory\Application\RecipeService;
use App\Modules\Inventory\Domain\Models\Recipe;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Menu terlaris & tidak laku dengan analisis menu engineering (FR-RPT-03), metode Kasavana & Smith (ADR 0006).
 */
class MenuEngineeringReport
{
    public const CLASSES = [
        'star' => 'Star',
        'plowhorse' => 'Plowhorse',
        'puzzle' => 'Puzzle',
        'dog' => 'Dog',
        'no_cost' => 'Belum ada HPP',
    ];

    public const ADVICE = [
        'star' => 'Pertahankan kualitas dan posisi di menu.',
        'plowhorse' => 'Laris tetapi margin tipis: tinjau porsi, resep, atau harga.',
        'puzzle' => 'Margin baik tetapi kurang laku: perbaiki penempatan atau promosi.',
        'dog' => 'Kurang laku dan margin rendah: pertimbangkan diganti.',
        'no_cost' => 'Lengkapi resep dan harga bahan agar margin dapat dihitung.',
    ];

    public function __construct(
        private readonly SalesReport $sales,
        private readonly RecipeService $recipes,
    ) {}

    /** @return list<array<string, mixed>> */
    public function rows(ReportFilter $filter): array
    {
        $items = array_values(array_filter(
            $this->sales->breakdown($filter, 'item'),
            fn (array $r) => $r['key'] !== '',
        ));
        if ($items === []) {
            return [];
        }

        $costs = $this->postedCosts($filter);
        $rows = [];
        foreach ($items as $r) {
            $qty = BigDecimal::of((string) $r['qty'])->minus((string) $r['refund_qty']);
            if (! $qty->isPositive()) {
                continue;
            }
            $net = BigDecimal::of((string) $r['net_sales']);
            $posted = $costs[$r['key']] ?? null;
            $basis = 'posted';
            // Posting tanpa resep bernilai nol: jangan dianggap HPP nol (margin 100% menyesatkan).
            if ($posted !== null && $posted['qty']->isPositive() && $posted['cost']->isPositive()) {
                $unitCost = $posted['cost']->dividedBy($posted['qty'], 2, RoundingMode::HALF_UP);
            } else {
                $theory = $this->recipes->cost(Recipe::ITEM, (string) $r['key'], null);
                $unitCost = BigDecimal::of($theory['total']);
                $basis = $unitCost->isPositive() && ! $theory['missing_cost'] ? 'recipe' : 'none';
            }
            $price = $net->dividedBy($qty, 2, RoundingMode::HALF_UP);
            $rows[] = [
                'key' => $r['key'],
                'label' => $r['label'],
                'category' => $r['category'],
                'qty' => (string) $qty,
                'net_sales' => (string) $net,
                'avg_price' => (string) $price,
                'unit_cost' => $basis === 'none' ? null : (string) $unitCost->toScale(2),
                'unit_margin' => $basis === 'none' ? null : (string) $price->minus($unitCost)->toScale(2),
                'total_margin' => $basis === 'none' ? null : (string) $net->minus($unitCost->multipliedBy($qty))->toScale(2, RoundingMode::HALF_UP),
                'cost_basis' => $basis,
            ];
        }

        $count = count($rows);
        $totalQty = array_reduce($rows, fn (BigDecimal $c, array $r) => $c->plus($r['qty']), BigDecimal::zero());
        $withCost = array_filter($rows, fn ($r) => $r['unit_margin'] !== null);
        $costQty = array_reduce($withCost, fn (BigDecimal $c, array $r) => $c->plus($r['qty']), BigDecimal::zero());
        $avgMargin = $costQty->isZero() ? BigDecimal::zero()
            : array_reduce($withCost, fn (BigDecimal $c, array $r) => $c->plus($r['total_margin']), BigDecimal::zero())
                ->dividedBy($costQty, 2, RoundingMode::HALF_UP);
        // Ambang popularitas: 70% dari porsi rata-rata (1 ÷ jumlah item).
        $threshold = BigDecimal::of('70')->dividedBy($count, 4, RoundingMode::HALF_UP);

        foreach ($rows as &$row) {
            $mix = $totalQty->isZero() ? BigDecimal::zero() : BigDecimal::of($row['qty'])->multipliedBy(100)->dividedBy($totalQty, 2, RoundingMode::HALF_UP);
            $popular = $mix->isGreaterThanOrEqualTo($threshold);
            $row['menu_mix'] = (string) $mix;
            if ($row['unit_margin'] === null) {
                $class = 'no_cost';
            } else {
                $profitable = BigDecimal::of($row['unit_margin'])->isGreaterThanOrEqualTo($avgMargin);
                $class = match (true) {
                    $popular && $profitable => 'star',
                    $popular => 'plowhorse',
                    $profitable => 'puzzle',
                    default => 'dog',
                };
            }
            $row['class'] = $class;
            $row['class_label'] = self::CLASSES[$class];
            $row['advice'] = self::ADVICE[$class];
            $row['popularity'] = $popular ? 'Tinggi' : 'Rendah';
            $row['basis_label'] = match ($row['cost_basis']) {
                'posted' => 'Pemakaian tercatat',
                'recipe' => 'Resep (harga beli terakhir)',
                default => '-',
            };
        }
        unset($row);

        usort($rows, fn ($a, $b) => BigDecimal::of($b['qty'])->compareTo($a['qty']) ?: strcmp((string) $a['label'], (string) $b['label']));
        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return $rows;
    }

    public function table(ReportFilter $filter): ReportTable
    {
        $rows = $this->rows($filter);
        $m = ReportTable::MONEY;
        $columns = [
            'label' => ['label' => 'Item', 'type' => ReportTable::TEXT],
            'rank' => ['label' => 'Peringkat', 'type' => ReportTable::NUMBER],
            'category' => ['label' => 'Kategori', 'type' => ReportTable::TEXT],
            'qty' => ['label' => 'Terjual bersih', 'type' => ReportTable::NUMBER],
            'menu_mix' => ['label' => 'Porsi terjual', 'type' => ReportTable::PERCENT],
            'avg_price' => ['label' => 'Harga rata-rata', 'type' => $m],
            'unit_cost' => ['label' => 'HPP/porsi', 'type' => $m],
            'unit_margin' => ['label' => 'Margin/porsi', 'type' => $m],
            'total_margin' => ['label' => 'Total margin', 'type' => $m],
            'class_label' => ['label' => 'Kelompok', 'type' => ReportTable::TEXT],
            'basis_label' => ['label' => 'Dasar HPP', 'type' => ReportTable::TEXT],
        ];
        $counts = array_count_values(array_map(fn ($r) => $r['class'], $rows));
        $summary = [];
        foreach (['star', 'plowhorse', 'puzzle', 'dog'] as $c) {
            $summary[] = ['label' => self::CLASSES[$c], 'value' => (string) ($counts[$c] ?? 0), 'type' => ReportTable::NUMBER];
        }
        $threshold = $rows === [] ? '0' : ReportTable::number((string) BigDecimal::of('70')->dividedBy(count($rows), 2, RoundingMode::HALF_UP), 2);

        return new ReportTable(
            key: 'menu_engineering',
            title: 'Menu Terlaris & Menu Engineering',
            columns: $columns,
            rows: array_map(fn ($r) => array_intersect_key($r, $columns), $rows),
            totals: null,
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: $summary,
            notes: [
                'Diurutkan dari yang paling laris. Terjual bersih = terjual dikurangi refund; varian digabung ke item.',
                "Populer bila porsi terjual ≥ {$threshold}% (70% dari porsi rata-rata). Margin tinggi bila margin/porsi ≥ rata-rata tertimbang.",
                'Star: laris & margin tinggi. Plowhorse: laris, margin rendah. Puzzle: margin tinggi, kurang laku. Dog: keduanya rendah.',
                'HPP memakai pemakaian bahan tercatat; bila belum ada, memakai resep dengan harga beli terakhir.',
            ],
        );
    }

    /** @return array<string, array{qty: BigDecimal, cost: BigDecimal}> item_id => biaya bahan tercatat */
    private function postedCosts(ReportFilter $filter): array
    {
        if ($filter->outletIds === []) {
            return [];
        }
        $rows = DB::table('stock_line_postings as p')
            ->join('order_items as i', function ($join): void {
                $join->on('i.id', '=', 'p.line_id')->on('i.business_date', '=', 'p.business_date');
            })
            ->whereIn('p.outlet_id', $filter->outletIds)
            ->whereBetween('p.business_date', [$filter->fromDate(), $filter->toDate()])
            ->where('p.type', 'sale')
            ->groupBy('i.item_id')
            ->selectRaw("i.item_id::text AS item_id, SUM(p.qty) AS qty,
                SUM((SELECT COALESCE(SUM((c->>'qty')::numeric * (c->>'unit_cost')::numeric), 0) FROM jsonb_array_elements(p.consumption) c)) AS cost")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->item_id] = ['qty' => BigDecimal::of((string) $r->qty), 'cost' => BigDecimal::of((string) $r->cost)];
        }

        return $out;
    }
}
