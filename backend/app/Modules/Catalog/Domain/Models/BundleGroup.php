<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $item_id
 * @property string $name
 * @property int $min_select
 * @property int $max_select
 * @property int $sort_order
 * @property-read Collection<int, BundleGroupOption> $options
 */
class BundleGroup extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'bundle_groups';

    protected $fillable = ['name', 'min_select', 'max_select', 'sort_order'];

    protected function casts(): array
    {
        return ['min_select' => 'integer', 'max_select' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasMany<BundleGroupOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(BundleGroupOption::class)->orderBy('sort_order');
    }
}
