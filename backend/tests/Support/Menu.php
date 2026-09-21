<?php

namespace Tests\Support;

use App\Modules\Catalog\Application\ItemWriter;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Support\Str;

/** Data menu untuk pengujian, dibuat lewat layanan aplikasi dalam konteks tenant. */
final class Menu
{
    public static function category(Company $company, Brand $brand, string $name = 'Kopi'): MenuCategory
    {
        return Factory::tenant($company, fn () => MenuCategory::query()->create([
            'brand_id' => $brand->id, 'name' => $name.' '.Str::random(3),
        ]));
    }

    /** @param array<string, mixed> $attrs */
    public static function item(Company $company, Brand $brand, array $attrs = []): Item
    {
        $category = isset($attrs['category_id']) ? null : self::category($company, $brand);

        return Factory::tenant($company, fn () => app(ItemWriter::class)->save(null, $attrs + [
            'brand_id' => $brand->id,
            'category_id' => $category?->id,
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'name' => 'Menu '.Str::random(4),
            'base_price' => '10000',
        ]));
    }

    /** @param list<array{name: string, price: string}> $modifiers */
    public static function modifierGroup(Company $company, Brand $brand, array $modifiers, int $min = 0, int $max = 1, string $name = 'Gula'): ModifierGroup
    {
        return Factory::tenant($company, function () use ($brand, $modifiers, $min, $max, $name): ModifierGroup {
            $group = ModifierGroup::query()->create([
                'brand_id' => $brand->id, 'name' => $name, 'min_select' => $min, 'max_select' => $max,
            ]);
            foreach ($modifiers as $i => $m) {
                $group->modifiers()->create($m + ['sort_order' => $i]);
            }

            return $group->load('modifiers');
        });
    }

    public static function channel(Company $company, string $code): SalesChannel
    {
        return Factory::tenant($company, fn () => SalesChannel::query()->where('code', $code)->firstOrFail());
    }

    public static function station(Company $company, string $code = 'BAR'): KitchenStation
    {
        return Factory::tenant($company, fn () => KitchenStation::query()->where('code', $code)->firstOrFail());
    }

    /** @param array<string, mixed> $attrs */
    public static function promotion(Company $company, array $attrs, array $itemIds = [], array $outletIds = []): Promotion
    {
        return Factory::tenant($company, function () use ($attrs, $itemIds, $outletIds): Promotion {
            $promo = Promotion::query()->create($attrs + [
                'name' => 'Promo '.Str::random(4), 'type' => 'percent', 'scope' => $itemIds === [] ? 'order' : 'items',
                'value' => '10', 'starts_at' => now()->subDay(), 'auto_apply' => true, 'is_active' => true,
            ]);
            foreach ($itemIds as $id) {
                $promo->targets()->create(['target_type' => 'item', 'target_id' => $id]);
            }
            if ($outletIds !== []) {
                $promo->outlets()->sync(collect($outletIds)->mapWithKeys(fn ($id) => [$id => ['company_id' => $promo->company_id]])->all());
            }

            return $promo;
        });
    }
}
