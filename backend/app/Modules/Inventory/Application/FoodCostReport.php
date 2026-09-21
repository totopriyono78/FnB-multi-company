<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Application\PriceResolver;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Sales\Application\SalesStockFeed;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Food cost teoritis per menu dan food cost aktual per periode (FR-INV-10, FR-RPT-06).
 *
 * - Teoritis per menu: resep × HPP rata-rata lokasi utama outlet (atau harga beli terakhir) dibanding harga jual
 *   sebelum pajak (harga dine-in outlet).
 * - Aktual per periode: pemakaian penjualan (teoritis) + waste + selisih opname/penyesuaian (tanpa saldo awal),
 *   dibanding penjualan bersih tanpa pajak, service charge & pembulatan.
 */
class FoodCostReport
{
    public function __construct(
        private readonly RecipeExplorer $explorer,
        private readonly StockLocations $locations,
        private readonly PriceResolver $prices,
        private readonly SalesStockFeed $sales,
    ) {}

    /** @return list<array<string, mixed>> */
    public function menu(Outlet $outlet): array
    {
        $location = $this->locations->forSale($outlet, null);
        $channelId = SalesChannel::query()->where('code', 'dine_in')->value('id');
        $items = Item::query()
            ->with(['variants', 'prices'])
            ->where('brand_id', $outlet->brand_id)
            ->where('type', Item::TYPE_SINGLE)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $recipes = Recipe::query()
            ->whereIn('target_type', [Recipe::ITEM, Recipe::VARIANT])
            ->whereIn('target_id', $items->pluck('id')->merge($items->flatMap(fn (Item $i) => $i->variants->pluck('id')))->all())
            ->pluck('target_id')
            ->flip();

        $usages = [];
        foreach ($items as $item) {
            $targets = $item->variants->isEmpty() ? [null] : $item->variants->all();
            foreach ($targets as $variant) {
                $usage = $variant !== null && $recipes->has($variant->id)
                    ? $this->explorer->forTarget(Recipe::VARIANT, $variant->id)
                    : ($recipes->has($item->id) ? $this->explorer->forTarget(Recipe::ITEM, $item->id) : null);
                $usages[] = [$item, $variant, $usage];
            }
        }

        $ingredientIds = [];
        foreach ($usages as [, , $usage]) {
            foreach (array_keys($usage ?? []) as $id) {
                $ingredientIds[$id] = true;
            }
        }
        $costs = $this->costs($location->id, array_keys($ingredientIds));
        $taxRate = BigDecimal::of((string) $outlet->tax_rate);

        $rows = [];
        foreach ($usages as [$item, $variant, $usage]) {
            /** @var Item $item */
            /** @var ItemVariant|null $variant */
            $price = BigDecimal::of($this->prices->resolve($item, $variant, $outlet->id, $channelId));
            $net = $outlet->tax_inclusive && $taxRate->isPositive()
                ? $price->multipliedBy(100)->dividedBy($taxRate->plus(100), 2, RoundingMode::HALF_UP)
                : $price;
            $cost = BigDecimal::zero();
            $missing = false;
            foreach ($usage ?? [] as $ingredientId => $qty) {
                $unit = $costs[$ingredientId] ?? null;
                if ($unit === null || $unit->isZero()) {
                    $missing = true;

                    continue;
                }
                $cost = $cost->plus($qty->multipliedBy($unit));
            }
            $cost = $cost->toScale(2, RoundingMode::HALF_UP);
            $rows[] = [
                'item_id' => $item->id,
                'variant_id' => $variant?->id,
                'name' => $item->name.($variant !== null ? ' · '.$variant->name : ''),
                'sku' => $item->sku,
                'price' => (string) $price->toScale(2),
                'net_price' => (string) $net->toScale(2),
                'has_recipe' => $usage !== null,
                'cost' => $usage === null ? null : (string) $cost,
                'food_cost_percent' => $usage === null || ! $net->isPositive() ? null : (string) $cost->multipliedBy(100)->dividedBy($net, 1, RoundingMode::HALF_UP),
                'gross_margin' => $usage === null ? null : (string) $net->minus($cost)->toScale(2),
                'missing_cost' => $missing,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $outletIds
     * @return array<string, mixed>
     */
    public function actual(array $outletIds, string $from, string $to): array
    {
        $sums = StockMovement::query()
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', [$from, $to])
            ->whereIn('type', StockMovement::CONSUMPTION_TYPES)
            ->whereRaw("NOT (flags @> '[\"opening\"]'::jsonb)")
            ->groupBy('outlet_id', 'type')
            ->selectRaw('outlet_id, type, SUM(value) AS total')
            ->toBase()->get();
        $netSales = $this->sales->netSales($outletIds, $from, $to);
        $outlets = Outlet::withTrashed()->whereIn('id', $outletIds)->orderBy('name')->get(['id', 'name', 'code']);

        $blank = fn () => ['sale' => BigDecimal::zero(), 'waste' => BigDecimal::zero(), 'variance' => BigDecimal::zero()];
        $by = [];
        foreach ($sums as $row) {
            $bucket = match ((string) $row->type) {
                'sale', 'sale_return' => 'sale',
                'waste' => 'waste',
                default => 'variance',
            };
            $by[(string) $row->outlet_id] ??= $blank();
            // Nilai mutasi keluar bertanda negatif; pemakaian = kebalikannya.
            $by[(string) $row->outlet_id][$bucket] = $by[(string) $row->outlet_id][$bucket]->minus((string) $row->total);
        }

        $rows = [];
        $totals = $blank() + ['net_sales' => BigDecimal::zero()];
        foreach ($outlets as $outlet) {
            $v = $by[$outlet->id] ?? $blank();
            $sales = BigDecimal::of($netSales[$outlet->id] ?? '0');
            if ($sales->isZero() && $v['sale']->isZero() && $v['waste']->isZero() && $v['variance']->isZero()) {
                continue;
            }
            $rows[] = $this->row(['outlet_id' => $outlet->id, 'outlet' => $outlet->name], $sales, $v);
            $totals['net_sales'] = $totals['net_sales']->plus($sales);
            foreach (['sale', 'waste', 'variance'] as $k) {
                $totals[$k] = $totals[$k]->plus($v[$k]);
            }
        }

        $top = StockMovement::query()
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', [$from, $to])
            ->whereIn('type', StockMovement::CONSUMPTION_TYPES)
            ->whereRaw("NOT (flags @> '[\"opening\"]'::jsonb)")
            ->groupBy('ingredient_id')
            ->selectRaw('ingredient_id, SUM(qty) AS qty, SUM(value) AS total')
            ->orderByRaw('SUM(value) ASC')
            ->limit(10)
            ->toBase()->get();
        $names = Ingredient::withTrashed()->whereIn('id', $top->pluck('ingredient_id')->all())->get(['id', 'name', 'base_unit'])->keyBy('id');

        return [
            'from' => $from,
            'to' => $to,
            'outlets' => $rows,
            'total' => $this->row([], $totals['net_sales'], $totals),
            'top_ingredients' => $top->map(fn ($r) => [
                'ingredient_id' => (string) $r->ingredient_id,
                'name' => $names->get($r->ingredient_id)?->name,
                'base_unit' => $names->get($r->ingredient_id)?->base_unit,
                'qty' => (string) BigDecimal::of((string) $r->qty)->negated()->toScale(4),
                'value' => (string) BigDecimal::of((string) $r->total)->negated()->toScale(2),
            ])->filter(fn (array $r) => BigDecimal::of($r['value'])->isPositive())->values()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $head
     * @param  array<string, BigDecimal>  $v
     * @return array<string, mixed>
     */
    private function row(array $head, BigDecimal $sales, array $v): array
    {
        $actual = $v['sale']->plus($v['waste'])->plus($v['variance']);
        $pct = fn (BigDecimal $x) => $sales->isPositive() ? (string) $x->multipliedBy(100)->dividedBy($sales, 1, RoundingMode::HALF_UP) : null;

        return $head + [
            'net_sales' => (string) $sales->toScale(2),
            'theoretical_cost' => (string) $v['sale']->toScale(2),
            'waste_cost' => (string) $v['waste']->toScale(2),
            'variance_cost' => (string) $v['variance']->toScale(2),
            'actual_cost' => (string) $actual->toScale(2),
            'theoretical_percent' => $pct($v['sale']),
            'actual_percent' => $pct($actual),
        ];
    }

    /**
     * @param  list<string>  $ingredientIds
     * @return array<string, BigDecimal>
     */
    private function costs(string $locationId, array $ingredientIds): array
    {
        if ($ingredientIds === []) {
            return [];
        }
        $avg = StockBalance::query()->where('location_id', $locationId)->whereIn('ingredient_id', $ingredientIds)->pluck('avg_cost', 'ingredient_id');
        $last = Ingredient::withTrashed()->whereIn('id', $ingredientIds)->pluck('last_cost', 'id');
        $result = [];
        foreach ($ingredientIds as $id) {
            $a = $avg->get($id);
            $result[$id] = $a !== null && BigDecimal::of((string) $a)->isPositive()
                ? BigDecimal::of((string) $a)
                : BigDecimal::of((string) ($last->get($id) ?? '0'));
        }

        return $result;
    }
}
