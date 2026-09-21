<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Brand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $company_id
 * @property string $brand_id
 * @property string $name
 * @property int $min_select
 * @property int $max_select
 * @property int $sort_order
 * @property bool $is_active
 * @property-read Collection<int, Modifier> $modifiers
 */
class ModifierGroup extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'modifier_groups';

    protected $fillable = ['brand_id', 'name', 'min_select', 'max_select', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'min_select' => 'integer', 'max_select' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Modifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('name');
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }
}
