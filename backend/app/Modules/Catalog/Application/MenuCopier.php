<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Salin menu antar brand dan pengaturan harga/ketersediaan antar outlet (FR-MENU-10). */
class MenuCopier
{
    public function __construct(
        private readonly ItemWriter $writer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Item dengan SKU yang sudah ada di brand tujuan dilewati.
     *
     * @return array{items_copied: int, items_skipped: list<string>, categories_created: int, modifier_groups_created: int}
     */
    public function copyBrand(Brand $from, Brand $to): array
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_brand_id' => 'Brand tujuan harus berbeda.']);
        }

        return DB::transaction(function () use ($from, $to): array {
            $report = ['items_copied' => 0, 'items_skipped' => [], 'categories_created' => 0, 'modifier_groups_created' => 0];

            $targetCats = MenuCategory::query()->where('brand_id', $to->id)->get()->keyBy(fn ($c) => mb_strtolower($c->name));
            $catMap = [];
            foreach (MenuCategory::query()->where('brand_id', $from->id)->orderBy('sort_order')->get() as $cat) {
                $key = mb_strtolower($cat->name);
                if (! $targetCats->has($key)) {
                    $targetCats->put($key, MenuCategory::query()->create([
                        'brand_id' => $to->id, 'name' => $cat->name, 'color' => $cat->color, 'icon' => $cat->icon,
                        'sort_order' => $cat->sort_order, 'is_active' => $cat->is_active,
                    ]));
                    $report['categories_created']++;
                }
                $catMap[$cat->id] = $targetCats->get($key)->id;
            }

            $targetGroups = ModifierGroup::query()->where('brand_id', $to->id)->get()->keyBy(fn ($g) => mb_strtolower($g->name));
            $groupMap = [];
            foreach (ModifierGroup::query()->with('modifiers')->where('brand_id', $from->id)->get() as $group) {
                $key = mb_strtolower($group->name);
                if (! $targetGroups->has($key)) {
                    $copy = ModifierGroup::query()->create([
                        'brand_id' => $to->id, 'name' => $group->name, 'min_select' => $group->min_select,
                        'max_select' => $group->max_select, 'sort_order' => $group->sort_order, 'is_active' => $group->is_active,
                    ]);
                    foreach ($group->modifiers as $m) {
                        $copy->modifiers()->create($m->only(['name', 'price', 'is_default', 'sort_order', 'is_active']));
                    }
                    $targetGroups->put($key, $copy);
                    $report['modifier_groups_created']++;
                }
                $groupMap[$group->id] = $targetGroups->get($key)->id;
            }

            $existing = Item::query()->where('brand_id', $to->id)->pluck('id', 'sku')->mapWithKeys(fn ($id, $sku) => [mb_strtolower($sku) => $id]);
            $source = Item::query()->with(['variants', 'modifierGroups', 'bundleGroups.options.item'])->where('brand_id', $from->id)
                ->orderByRaw("CASE WHEN type = 'bundle' THEN 1 ELSE 0 END")->get();
            $itemMap = [];

            foreach ($source as $item) {
                $skuKey = mb_strtolower($item->sku);
                if ($existing->has($skuKey)) {
                    $report['items_skipped'][] = $item->sku;
                    $itemMap[$item->id] = $existing->get($skuKey);

                    continue;
                }

                $bundle = [];
                foreach ($item->bundleGroups as $g) {
                    $options = [];
                    foreach ($g->options as $o) {
                        if (isset($itemMap[$o->item_id])) {
                            $options[] = ['item_id' => $itemMap[$o->item_id], 'extra_price' => (string) $o->extra_price, 'is_default' => $o->is_default];
                        }
                    }
                    $bundle[] = ['name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select, 'options' => $options];
                }
                if ($item->isBundle() && collect($bundle)->contains(fn ($g) => $g['options'] === [])) {
                    $report['items_skipped'][] = $item->sku;

                    continue;
                }

                $copy = $this->writer->save(null, [
                    'brand_id' => $to->id,
                    'category_id' => $catMap[$item->category_id],
                    'type' => $item->type,
                    'sku' => $item->sku,
                    'barcode' => $item->barcode,
                    'name' => $item->name,
                    'short_name' => $item->short_name,
                    'description' => $item->description,
                    'image_path' => $item->image_path,
                    'base_price' => (string) $item->base_price,
                    'kitchen_station_id' => $item->kitchen_station_id,
                    'channel_codes' => $item->channel_codes,
                    'schedule' => $item->schedule,
                    'sort_order' => $item->sort_order,
                    'is_active' => $item->is_active,
                    'variants' => $item->isBundle() ? [] : $item->variants->map(fn ($v) => $v->only(['name', 'sku', 'price', 'is_default', 'sort_order', 'is_active']))->all(),
                    'modifier_group_ids' => $item->modifierGroups->pluck('id')->map(fn ($id) => $groupMap[$id])->all(),
                    'bundle_groups' => $item->isBundle() ? $bundle : [],
                ]);
                $itemMap[$item->id] = $copy->id;
                $report['items_copied']++;
            }

            $this->audit->log('menu.brand_copied', $to, metadata: ['from_brand_id' => $from->id] + $report);

            return $report;
        });
    }

    /**
     * Salin harga khusus outlet dan status dijual dari satu outlet ke outlet lain di brand yang sama.
     *
     * @return array{prices_copied: int, availability_copied: int}
     */
    public function copyOutlet(Outlet $from, Outlet $to): array
    {
        if ($from->id === $to->id || $from->brand_id !== $to->brand_id) {
            throw ValidationException::withMessages(['to_outlet_id' => 'Outlet tujuan harus berbeda dan berada di brand yang sama.']);
        }

        return DB::transaction(function () use ($from, $to): array {
            $prices = 0;
            foreach (ItemPrice::query()->where('outlet_id', $from->id)->get() as $price) {
                $row = ItemPrice::query()->firstOrNew([
                    'item_id' => $price->item_id,
                    'item_variant_id' => $price->item_variant_id,
                    'outlet_id' => $to->id,
                    'sales_channel_id' => $price->sales_channel_id,
                ]);
                $row->price = $price->price;
                $row->save();
                $prices++;
            }

            $availability = 0;
            foreach (OutletItemAvailability::query()->where('outlet_id', $from->id)->get() as $avail) {
                $row = OutletItemAvailability::query()->firstOrNew(['outlet_id' => $to->id, 'item_id' => $avail->item_id]);
                $row->is_listed = $avail->is_listed;
                $row->is_sold_out = $row->exists ? $row->is_sold_out : false;
                $row->save();
                $availability++;
            }

            $report = ['prices_copied' => $prices, 'availability_copied' => $availability];
            $this->audit->log('menu.outlet_copied', $to, metadata: ['from_outlet_id' => $from->id] + $report);

            return $report;
        });
    }
}
