<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $bundle_group_id
 * @property string $item_id
 * @property string|null $item_variant_id
 * @property string $extra_price
 * @property bool $is_default
 * @property int $sort_order
 * @property-read Item $item
 * @property-read ItemVariant|null $variant
 */
class BundleGroupOption extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'bundle_group_options';

    protected $fillable = ['item_id', 'item_variant_id', 'extra_price', 'is_default', 'sort_order'];

    protected function casts(): array
    {
        return ['extra_price' => 'decimal:2', 'is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<BundleGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BundleGroup::class, 'bundle_group_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<ItemVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'item_variant_id');
    }
}
