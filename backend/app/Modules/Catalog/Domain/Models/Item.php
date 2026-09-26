<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use App\Modules\Tenancy\Domain\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Item menu (FR-MENU-02). Tipe `bundle` adalah paket (FR-MENU-05).
 *
 * @property string $id
 * @property string $company_id
 * @property string $brand_id
 * @property string $category_id
 * @property string $type
 * @property string $sku
 * @property string|null $barcode
 * @property string $name
 * @property string $short_name
 * @property string|null $description
 * @property string|null $image_path
 * @property string $base_price
 * @property string|null $kitchen_station_id
 * @property list<string>|null $channel_codes
 * @property list<array{days?: list<int>|null, start?: string|null, end?: string|null}>|null $schedule
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable|null $updated_at
 * @property-read Brand $brand
 * @property-read MenuCategory $category
 * @property-read KitchenStation|null $station
 * @property-read Collection<int, ItemVariant> $variants
 * @property-read Collection<int, ModifierGroup> $modifierGroups
 * @property-read Collection<int, BundleGroup> $bundleGroups
 * @property-read Collection<int, ItemPrice> $prices
 */
class Item extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;
    use TracksAuthor;

    public const TYPE_SINGLE = 'single';

    public const TYPE_BUNDLE = 'bundle';

    protected $fillable = [
        'brand_id', 'category_id', 'type', 'sku', 'barcode', 'name', 'short_name', 'description', 'image_path',
        'sold_by_weight', 'unit',
        'base_price', 'kitchen_station_id', 'channel_codes', 'schedule', 'sort_order', 'is_active',
    ];

    protected $attributes = [
        'type' => self::TYPE_SINGLE,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'channel_codes' => 'array',
            'schedule' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    /** @return BelongsTo<KitchenStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    /** @return HasMany<ItemVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class)->orderBy('sort_order')->orderBy('name');
    }

    /** @return BelongsToMany<ModifierGroup, $this, ItemModifierGroup, 'pivot'> */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'item_modifier_groups')
            ->using(ItemModifierGroup::class)
            ->withPivot(['id', 'company_id', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /** @return HasMany<BundleGroup, $this> */
    public function bundleGroups(): HasMany
    {
        return $this->hasMany(BundleGroup::class)->orderBy('sort_order');
    }

    /** @return HasMany<ItemPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(ItemPrice::class);
    }

    /** @return HasMany<ItemPriceHistory, $this> */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(ItemPriceHistory::class);
    }

    /** @return HasMany<OutletItemAvailability, $this> */
    public function availability(): HasMany
    {
        return $this->hasMany(OutletItemAvailability::class);
    }

    public function isBundle(): bool
    {
        return $this->type === self::TYPE_BUNDLE;
    }

    public function isSoldForChannel(string $channelCode): bool
    {
        return $this->channel_codes === null || in_array($channelCode, $this->channel_codes, true);
    }
}
