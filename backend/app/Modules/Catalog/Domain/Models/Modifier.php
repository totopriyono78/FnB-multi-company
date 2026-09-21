<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $modifier_group_id
 * @property string $name
 * @property string $price
 * @property bool $is_default
 * @property int $sort_order
 * @property bool $is_active
 * @property-read ModifierGroup $group
 */
class Modifier extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'modifiers';

    protected $fillable = ['name', 'price', 'is_default', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_default' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<ModifierGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
