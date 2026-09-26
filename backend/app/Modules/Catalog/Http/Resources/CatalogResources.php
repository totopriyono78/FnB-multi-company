<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Models\BundleGroup;
use App\Modules\Catalog\Domain\Models\BundleGroupOption;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Shared\Application\MediaStore;

/** Pemetaan model menu ke bentuk JSON API. */
final class CatalogResources
{
    /** @return array<string, mixed> */
    public static function station(KitchenStation $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'sort_order' => $s->sort_order, 'is_active' => $s->is_active];
    }

    /** @return array<string, mixed> */
    public static function channel(SalesChannel $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type,
            'service_charge_applies' => $c->service_charge_applies, 'is_system' => $c->is_system,
            'sort_order' => $c->sort_order, 'is_active' => $c->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function category(MenuCategory $c): array
    {
        return [
            'id' => $c->id, 'brand_id' => $c->brand_id, 'name' => $c->name, 'color' => $c->color, 'icon' => $c->icon,
            'sort_order' => $c->sort_order, 'is_active' => $c->is_active,
            'items_count' => $c->getAttributes()['items_count'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function modifierGroup(ModifierGroup $g): array
    {
        return [
            'id' => $g->id, 'brand_id' => $g->brand_id, 'name' => $g->name,
            'min_select' => $g->min_select, 'max_select' => $g->max_select, 'required' => $g->isRequired(),
            'sort_order' => $g->sort_order, 'is_active' => $g->is_active,
            'modifiers' => $g->relationLoaded('modifiers') ? $g->modifiers->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'price' => (string) $m->price,
                'is_default' => $m->is_default, 'sort_order' => $m->sort_order, 'is_active' => $m->is_active,
            ])->values() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function item(Item $i, bool $detail = false): array
    {
        $data = [
            'id' => $i->id, 'brand_id' => $i->brand_id, 'category_id' => $i->category_id,
            'category' => $i->relationLoaded('category') ? ['id' => $i->category->id, 'name' => $i->category->name] : null,
            'type' => $i->type, 'sku' => $i->sku, 'barcode' => $i->barcode, 'name' => $i->name, 'short_name' => $i->short_name,
            'base_price' => (string) $i->base_price, 'kitchen_station_id' => $i->kitchen_station_id,
            'is_active' => $i->is_active, 'sort_order' => $i->sort_order,
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];

        if ($detail) {
            $data += [
                'description' => $i->description,
                'image_path' => $i->image_path,
                'image_url' => app(MediaStore::class)->url($i->image_path),
                'sold_by_weight' => (bool) $i->sold_by_weight,
                'unit' => $i->unit,
                'channel_codes' => $i->channel_codes,
                'schedule' => $i->schedule,
                'variants' => $i->variants->map(fn ($v) => [
                    'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'price' => (string) $v->price,
                    'is_default' => $v->is_default, 'is_active' => $v->is_active,
                ])->values(),
                'modifier_groups' => $i->modifierGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
                'bundle_groups' => $i->bundleGroups->map(fn (BundleGroup $g) => [
                    'id' => $g->id, 'name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
                    'options' => $g->options->map(fn (BundleGroupOption $o) => [
                        'id' => $o->id, 'item_id' => $o->item_id, 'item_variant_id' => $o->item_variant_id,
                        'extra_price' => (string) $o->extra_price, 'is_default' => $o->is_default,
                    ])->values(),
                ])->values(),
            ];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function price(ItemPrice $p): array
    {
        return [
            'id' => $p->id, 'item_variant_id' => $p->item_variant_id, 'outlet_id' => $p->outlet_id,
            'sales_channel_id' => $p->sales_channel_id, 'price' => (string) $p->price,
        ];
    }

    /** @return array<string, mixed> */
    public static function promotion(Promotion $p): array
    {
        return $p->toEngineArray() + [
            'is_active' => $p->is_active,
            'quota' => $p->quota,
            'used_count' => $p->used_count,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
