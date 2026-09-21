<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\BundleGroup;
use App\Modules\Catalog\Domain\Models\BundleGroupOption;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Collection;

/**
 * Snapshot menu lengkap untuk satu outlet (FR-DEV-03). POS menyimpannya lokal agar bisa
 * bertransaksi offline; sinkronisasi inkremental dibuat di Tahap 3 dengan struktur yang sama.
 */
class PosCatalogBuilder
{
    public function __construct(private readonly PriceResolver $prices) {}

    /** @return array<string, mixed> */
    public function build(Outlet $outlet): array
    {
        $channels = SalesChannel::query()->where('is_active', true)->orderBy('sort_order')->get();
        $availability = OutletItemAvailability::query()->where('outlet_id', $outlet->id)->get()->keyBy('item_id');

        $items = Item::query()
            ->with(['variants' => fn ($q) => $q->where('is_active', true), 'modifierGroups', 'bundleGroups.options', 'prices'])
            ->where('brand_id', $outlet->brand_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->reject(fn (Item $item) => ($availability->get($item->id)->is_listed ?? true) === false);

        $groupIds = $items->flatMap(fn (Item $i) => $i->modifierGroups->pluck('id'))->unique();
        $groups = ModifierGroup::query()
            ->with(['modifiers' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('id', $groupIds)->where('is_active', true)->get();

        $promotions = Promotion::query()
            ->with(['targets', 'outlets'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $outlet->brand_id))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get()
            ->filter(fn (Promotion $p) => $p->outlets->isEmpty() || $p->outlets->contains('id', $outlet->id))
            ->values();

        $latest = collect([
            $items->max('updated_at'),
            $groups->max('updated_at'),
            $promotions->max('updated_at'),
            $channels->max('updated_at'),
            $availability->max('updated_at'),
            $outlet->updated_at,
        ])->filter()->max();

        return [
            'generated_at' => now()->toIso8601String(),
            'version' => $latest?->format('Y-m-d\TH:i:s.uP'),
            'outlet' => [
                'id' => $outlet->id,
                'brand_id' => $outlet->brand_id,
                'timezone' => $outlet->timezone,
                'order_mode' => $outlet->order_mode,
                'pricing' => [
                    'tax_name' => $outlet->tax_name,
                    'tax_rate' => (string) $outlet->tax_rate,
                    'tax_inclusive' => $outlet->tax_inclusive,
                    'tax_on_service_charge' => $outlet->tax_on_service_charge,
                    'service_charge_rate' => (string) $outlet->service_charge_rate,
                    'rounding_unit' => $outlet->rounding_unit,
                    'rounding_mode' => $outlet->rounding_mode,
                ],
            ],
            'channels' => $channels->map(fn (SalesChannel $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type,
                'service_charge_applies' => $c->service_charge_applies,
            ])->values(),
            'stations' => KitchenStation::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name']),
            'categories' => MenuCategory::query()->where('brand_id', $outlet->brand_id)->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color', 'icon', 'sort_order']),
            'modifier_groups' => $groups->map(fn (ModifierGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
                'modifiers' => $g->modifiers->map(fn (Modifier $m) => ['id' => $m->id, 'name' => $m->name, 'price' => (string) $m->price, 'is_default' => $m->is_default])->values(),
            ])->values(),
            'items' => $items->map(fn (Item $item) => $this->item($item, $outlet, $channels, $availability->get($item->id)->is_sold_out ?? false))->values(),
            'promotions' => $promotions->map(fn (Promotion $p) => self::forDevice($p))->values(),
        ];
    }

    /**
     * Kode promo tidak dikirim dalam bentuk asli ke perangkat (ADR 0003 §Kode promo):
     * POS mencocokkan hash PBKDF2 (salt = id promo) secara offline.
     *
     * @return array<string, mixed>
     */
    public static function forDevice(Promotion $promo): array
    {
        $data = $promo->toEngineArray();
        $data['code_hash'] = $data['code'] === null ? null : ($promo->code_hash ?? Promotion::hashCode($promo->id, (string) $data['code']));
        unset($data['code']);

        return $data;
    }

    /**
     * @param  Collection<int, SalesChannel>  $channels
     * @return array<string, mixed>
     */
    private function item(Item $item, Outlet $outlet, $channels, bool $soldOut): array
    {
        $priceMap = function ($variant) use ($item, $outlet, $channels): array {
            $map = [];
            foreach ($channels as $channel) {
                if ($item->isSoldForChannel($channel->code)) {
                    $map[$channel->code] = $this->prices->resolve($item, $variant, $outlet->id, $channel->id, $item->prices);
                }
            }

            return $map;
        };

        return [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'type' => $item->type,
            'sku' => $item->sku,
            'barcode' => $item->barcode,
            'name' => $item->name,
            'short_name' => $item->short_name,
            'image_path' => $item->image_path,
            'kitchen_station_id' => $item->kitchen_station_id,
            'channel_codes' => $item->channel_codes,
            'schedule' => $item->schedule,
            'sold_out' => $soldOut,
            'prices' => $item->variants->isEmpty() ? $priceMap(null) : null,
            'variants' => $item->variants->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->name, 'is_default' => $v->is_default, 'prices' => $priceMap($v),
            ])->values(),
            'modifier_group_ids' => $item->modifierGroups->pluck('id')->values(),
            'bundle_groups' => $item->bundleGroups->map(fn (BundleGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
                'options' => $g->options->map(fn (BundleGroupOption $o) => [
                    'id' => $o->id, 'item_id' => $o->item_id, 'item_variant_id' => $o->item_variant_id,
                    'extra_price' => (string) $o->extra_price, 'is_default' => $o->is_default,
                ])->values(),
            ])->values(),
        ];
    }
}
